<?php
include 'config.php';
include 'header.php';
?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet"/>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

<style>
@import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600;700&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap');

:root {
  --bg: #f4f4f0;
  --surface: #ffffff;
  --border: #d8d8d0;
  --border-strong: #999990;
  --text: #111111;
  --text-muted: #666660;
  --accent: #1a1a2e;
  --accent-light: #e8e8f0;
  --green: #1a6b3a;
  --green-bg: #e8f5ee;
  --green-border: #a8d8bc;
  --red: #8b1a1a;
  --red-bg: #fdf0f0;
  --red-border: #e8b8b8;
  --amber: #7a4f00;
  --amber-bg: #fff8e8;
  --amber-border: #e8d08a;
  --mono: 'IBM Plex Mono', monospace;
  --sans: 'IBM Plex Sans', sans-serif;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body { font-family: var(--sans); background: var(--bg); color: var(--text); }

/* ── Page header ── */
.bulk-header {
  background: var(--accent);
  color: #fff;
  padding: 20px 24px 18px;
  margin-bottom: 20px;
  border-radius: 0 0 8px 8px;
  display: flex;
  align-items: center;
  gap: 12px;
}
.bulk-header-icon {
  width: 40px; height: 40px;
  background: rgba(255,255,255,0.12);
  border-radius: 6px;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px;
}
.bulk-header h2 { font-size: 16px; font-weight: 700; font-family: var(--sans); }
.bulk-header p  { font-size: 12px; color: rgba(255,255,255,0.6); margin-top: 2px; }

/* ── Cards ── */
.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 6px;
  margin-bottom: 14px;
}
.card-head {
  padding: 11px 14px;
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; gap: 8px;
  font-size: 12px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .5px;
  color: var(--text-muted);
}
.card-body { padding: 14px; }

/* ── Controls strip ── */
.ctrl-strip { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
.ctrl-field { display: flex; flex-direction: column; gap: 4px; }
.ctrl-label {
  font-size: 10px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .4px; color: var(--text-muted);
}
.ctrl-input, .ctrl-select {
  height: 36px; padding: 0 10px;
  border: 1px solid var(--border-strong);
  border-radius: 4px; font-size: 13px;
  font-family: var(--sans); background: #fff; color: var(--text);
}
.ctrl-input:focus, .ctrl-select:focus {
  outline: none; border-color: var(--accent);
  box-shadow: 0 0 0 2px rgba(26,26,46,.08);
}

/* ── Employee Select2 ── */
.select2-container { width: 100% !important; }
.select2-container--default .select2-selection--single {
  height: 36px; border: 1px solid var(--border-strong); border-radius: 4px;
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
  line-height: 36px; padding-left: 10px; font-size: 13px;
}
.select2-container--default .select2-selection--single .select2-selection__arrow { height: 36px; }
.select2-container--default.select2-container--focus .select2-selection--single {
  border-color: var(--accent); box-shadow: 0 0 0 2px rgba(26,26,46,.08);
}
.select2-dropdown { border: 1px solid var(--border-strong); border-radius: 4px; font-size: 13px; font-family: var(--sans); }
.select2-container--default .select2-results__option--highlighted[aria-selected] { background: var(--accent); }

/* ── Load button ── */
.btn-load {
  height: 36px; padding: 0 16px;
  background: var(--accent); color: #fff;
  border: none; border-radius: 4px;
  font-size: 12px; font-weight: 700;
  font-family: var(--sans); cursor: pointer;
  display: inline-flex; align-items: center; gap: 6px;
  transition: background .15s;
  white-space: nowrap;
}
.btn-load:hover { background: #2d2d50; }
.btn-load:disabled { opacity: .4; cursor: not-allowed; }

/* ── Summary stats bar ── */
.stats-bar {
  display: none; gap: 0;
  border: 1px solid var(--border);
  border-radius: 5px; overflow: hidden;
  margin-bottom: 12px;
}
.stats-bar.show { display: flex; }
.stat-cell {
  flex: 1; padding: 8px 12px;
  border-right: 1px solid var(--border);
  text-align: center;
}
.stat-cell:last-child { border-right: none; }
.stat-val { font-size: 18px; font-weight: 700; font-family: var(--mono); color: var(--accent); }
.stat-lbl { font-size: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .4px; margin-top: 1px; }

/* ── Table ── */
.sr-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.sr-table thead th {
  background: var(--accent); color: #fff;
  padding: 8px 10px; text-align: left;
  font-weight: 600; font-size: 11px;
  text-transform: uppercase; letter-spacing: .4px;
}
.sr-table thead th:first-child { width: 36px; text-align: center; }
.sr-table tbody tr { border-bottom: 1px solid var(--border); transition: background .1s; }
.sr-table tbody tr:hover { background: #fafaf8; }
.sr-table tbody td { padding: 8px 10px; vertical-align: middle; }
.sr-table tbody td:first-child { text-align: center; }

.sr-code-badge {
  font-family: var(--mono); font-size: 11px; font-weight: 700;
  background: var(--accent-light); border: 1px solid #c8c8e0;
  border-radius: 3px; padding: 2px 7px; color: var(--accent);
}
.fs-code-cell {
  font-family: var(--mono); font-size: 11px; color: #555;
  background: #f8f8f4; border: 1px solid var(--border);
  border-radius: 3px; padding: 2px 7px; display: inline-block;
}

/* row status badges */
.row-status {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 10px; font-weight: 700; padding: 2px 7px;
  border-radius: 10px; white-space: nowrap;
}
.rs-pending   { background: var(--amber-bg); color: var(--amber); border: 1px solid var(--amber-border); }
.rs-ok        { background: var(--green-bg); color: var(--green); border: 1px solid var(--green-border); }
.rs-err       { background: var(--red-bg);   color: var(--red);   border: 1px solid var(--red-border);   }
.rs-skip      { background: #f0f0f0; color: #999; border: 1px solid #ddd; }
.rs-running   { background: var(--accent-light); color: var(--accent); border: 1px solid #c0c0e0; }

/* ── Select-all bar ── */
.sel-bar {
  display: none; align-items: center; gap: 10px;
  padding: 8px 10px;
  background: var(--accent-light);
  border: 1px solid #c0c0e0; border-radius: 4px;
  margin-bottom: 10px; font-size: 12px;
}
.sel-bar.show { display: flex; }
.sel-count { font-weight: 700; font-family: var(--mono); color: var(--accent); }

/* ── Action footer ── */
.action-footer {
  display: none; gap: 8px; align-items: center;
  padding-top: 12px; border-top: 1px solid var(--border);
  margin-top: 4px; flex-wrap: wrap;
}
.action-footer.show { display: flex; }

.btn-create {
  background: var(--green); color: #fff;
  border: none; border-radius: 4px;
  font-size: 12px; font-weight: 700;
  font-family: var(--sans); cursor: pointer;
  padding: 8px 16px;
  display: inline-flex; align-items: center; gap: 6px;
  transition: background .15s;
}
.btn-create:hover:not(:disabled) { background: #14552e; }
.btn-create:disabled { opacity: .4; cursor: not-allowed; }

.btn-ghost {
  background: transparent; color: var(--text-muted);
  border: 1px solid var(--border); border-radius: 4px;
  font-size: 12px; font-weight: 600;
  font-family: var(--sans); cursor: pointer;
  padding: 8px 14px;
  display: inline-flex; align-items: center; gap: 5px;
  transition: all .15s;
}
.btn-ghost:hover { background: #f0f0ec; border-color: #aaa; color: #333; }

/* ── Progress bar ── */
.prog-wrap { display: none; margin-top: 10px; }
.prog-wrap.show { display: block; }
.prog-track { background: #e8e8e0; border-radius: 3px; height: 6px; overflow: hidden; }
.prog-fill  { height: 100%; background: var(--green); border-radius: 3px; transition: width .3s; width: 0%; }
.prog-label { font-size: 11px; color: var(--text-muted); margin-top: 4px; font-family: var(--mono); }

/* ── Alert ── */
.alert { display: flex; align-items: flex-start; gap: 8px; padding: 10px 12px; border-radius: 4px; font-size: 12px; margin-bottom: 12px; }
.alert-ok  { background: var(--green-bg); color: var(--green); border: 1px solid var(--green-border); }
.alert-err { background: var(--red-bg);   color: var(--red);   border: 1px solid var(--red-border); }
.alert-inf { background: var(--amber-bg); color: var(--amber); border: 1px solid var(--amber-border); }

/* ── Spinner ── */
.spin { display:inline-block;width:11px;height:11px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:sp 1s linear infinite; }
.spin-dark { border-color: #ddd; border-top-color: var(--accent); }
@keyframes sp { to { transform:rotate(360deg) } }

/* ── Empty state ── */
.empty-state { text-align:center; padding: 30px 20px; color: var(--text-muted); }
.empty-state i { font-size: 28px; margin-bottom: 8px; display:block; opacity:.3; }
.empty-state p { font-size: 12px; }

/* ── Employee picker row ── */
.emp-pick-row { display:flex; gap:10px; align-items:flex-end; margin-top:12px; padding-top:12px; border-top:1px solid var(--border); }
.emp-pick-field { flex:1; min-width:200px; }
.emp-opt { display:flex;align-items:center;gap:6px; }
.emp-code-badge { font-size:10px;font-weight:700;background:#f0f0f0;border:1px solid #e0e0e0;border-radius:3px;padding:1px 5px;color:#444;white-space:nowrap;font-family:monospace; }
.emp-name-text { font-size:13px;color:#111; }
.emp-desig-text { font-size:11px;color:#999; }

/* ── Checkbox styling ── */
input[type=checkbox] {
  width: 15px; height: 15px;
  accent-color: var(--accent);
  cursor: pointer;
}
</style>

<div class="bulk-header">
  <div class="bulk-header-icon"><i class="fa-solid fa-layer-group"></i></div>
  <div>
    <h2>Bulk Create Field Summaries</h2>
    <p>Generate field summaries for all available SR codes in one operation</p>
  </div>
</div>

<div id="alertBox"></div>

<!-- Step 1: Date + Employee -->
<div class="card">
  <div class="card-head"><i class="fa-solid fa-sliders"></i> Configuration</div>
  <div class="card-body">

    <div class="ctrl-strip">
      <div class="ctrl-field">
        <span class="ctrl-label">Delivery Date <span style="color:#e53935">*</span></span>
        <input type="date" id="bulk_date" class="ctrl-input" style="width:160px;">
      </div>
      <div class="ctrl-field">
        <span class="ctrl-label">&nbsp;</span>
        <button class="btn-load" id="loadBtn" onclick="loadSRCodes()">
          <i class="fa-solid fa-magnifying-glass"></i> Load SR Codes
        </button>
      </div>
    </div>

    <div class="emp-pick-row">
      <div class="ctrl-field emp-pick-field">
        <span class="ctrl-label">Assign Employee (applied to all) <span style="color:#e53935">*</span></span>
        <select id="bulkEmpSelect">
          <option value="">-- Select Employee --</option>
        </select>
      </div>
      <div class="ctrl-field" style="font-size:11px;color:var(--text-muted);padding-bottom:8px;max-width:280px;line-height:1.4;">
        <i class="fa-solid fa-circle-info"></i>
        One employee will be assigned as the "Employee" field for all summaries created.
      </div>
    </div>

  </div>
</div>

<!-- Step 2: SR Code list -->
<div class="card" id="srCard" style="display:none;">
  <div class="card-head"><i class="fa-solid fa-route"></i> Available SR Codes <span id="srCardCount" style="margin-left:auto;font-size:11px;font-weight:400;font-family:var(--mono);color:var(--text-muted);"></span></div>
  <div class="card-body">

    <div id="statsBar" class="stats-bar">
      <div class="stat-cell"><div class="stat-val" id="statTotal">0</div><div class="stat-lbl">Total SR Codes</div></div>
      <div class="stat-cell"><div class="stat-val" id="statAvail">0</div><div class="stat-lbl">Available</div></div>
      <div class="stat-cell"><div class="stat-val" id="statCreated">0</div><div class="stat-lbl">Already Created</div></div>
      <div class="stat-cell"><div class="stat-val" id="statSelected">0</div><div class="stat-lbl">Selected</div></div>
    </div>

    <div class="sel-bar" id="selBar">
      <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this.checked)">
      <label for="selectAll" style="font-size:12px;cursor:pointer;">Select All Available</label>
      <span style="margin-left:auto;font-size:11px;color:var(--text-muted);">
        <span class="sel-count" id="selCount">0</span> selected
      </span>
    </div>

    <div id="srTableWrap">
      <div class="empty-state"><i class="fa-solid fa-calendar-days"></i><p>Select a date and click Load SR Codes</p></div>
    </div>

    <!-- Progress -->
    <div class="prog-wrap" id="progWrap">
      <div class="prog-track"><div class="prog-fill" id="progFill"></div></div>
      <div class="prog-label" id="progLabel">0 / 0 processed</div>
    </div>

    <!-- Actions -->
    <div class="action-footer" id="actionFooter">
      <button class="btn-create" id="createBtn" onclick="bulkCreate()">
        <i class="fa-solid fa-bolt"></i> Create Selected Field Summaries
      </button>
      <button class="btn-ghost" onclick="window.location.href='field_summary_list.php'">
        <i class="fa-solid fa-list"></i> View All Summaries
      </button>
      <span id="actionStatus" style="font-size:11px;color:var(--text-muted);margin-left:4px;"></span>
    </div>

  </div>
</div>

<script>
document.getElementById('bulk_date').valueAsDate = new Date();

/* ── Employee data ── */
let allEmployees = [];

/* ── Select2 formatters ── */
function fmtResult(opt) {
  if (!opt.id) return opt.text;
  const $el   = $(opt.element);
  const code  = $el.data('code')  || '';
  const desig = $el.data('desig') || '';
  return $('<span class="emp-opt">'
    + (code ? '<span class="emp-code-badge">' + code + '</span>' : '')
    + '<span class="emp-name-text">' + opt.text + '</span>'
    + (desig ? ' <span class="emp-desig-text">· ' + desig + '</span>' : '')
    + '</span>');
}
function fmtSelection(opt) {
  if (!opt.id) return opt.text;
  const code = $(opt.element).data('code') || '';
  return code ? '[' + code + '] ' + opt.text : opt.text;
}
$('#bulkEmpSelect').select2({ placeholder:'-- Select Employee --', allowClear:true, width:'100%', templateResult:fmtResult, templateSelection:fmtSelection });

/* ── Load employees ── */
fetch('get_all_employees.php')
  .then(r => r.json())
  .then(data => {
    if (data.success && data.employees) {
      allEmployees = data.employees;
      data.employees.forEach(emp => {
        const opt = new Option(emp.employee_name, emp.id);
        $(opt).attr('data-code', emp.employee_code || '').attr('data-desig', emp.designation || '');
        $('#bulkEmpSelect').append(opt);
      });
      $('#bulkEmpSelect').trigger('change');
    }
  });

/* ── SR Code table data ── */
let srRows = []; // [{sr_code, label, record_count, already_created, fs_code}]

function genCode(srCode, date) {
  // Format: credit-YYYYMMDD-SRCODE
  const d = date.replace(/-/g, '');
  return 'credit-' + d + '-' + srCode;
}

function loadSRCodes() {
  const date = document.getElementById('bulk_date').value;
  if (!date) { showAlert('Please select a delivery date.', 'err'); return; }

  const btn = document.getElementById('loadBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spin"></span> Loading…';
  document.getElementById('srCard').style.display = 'none';
  document.getElementById('alertBox').innerHTML = '';

  fetch('get_sr_codes.php?delivery_date=' + encodeURIComponent(date))
    .then(r => r.json())
    .then(data => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-magnifying-glass"></i> Load SR Codes';

      if (!data.success || !data.sr_codes || !data.sr_codes.length) {
        showAlert('No SR codes found for this date.', 'err');
        return;
      }

      srRows = data.sr_codes.map(s => ({
        sr_code:         s.code,
        label:           s.label || s.code,
        record_count:    s.record_count || '—',
        already_created: !!s.already_created,
        existing_id:     s.field_summary_id || null,
        fs_code:         genCode(s.code, date),
        status:          s.already_created ? 'skip' : 'pending',
        result_msg:      s.already_created ? 'Already created' : '',
      }));

      renderTable();
      updateStats();
      document.getElementById('srCard').style.display = 'block';
      document.getElementById('statsBar').classList.add('show');
      document.getElementById('selBar').classList.add('show');
      document.getElementById('actionFooter').classList.add('show');

      const avail = srRows.filter(r => !r.already_created).length;
      document.getElementById('srCardCount').textContent = srRows.length + ' total · ' + avail + ' available';
    })
    .catch(() => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-magnifying-glass"></i> Load SR Codes';
      showAlert('Error loading SR codes. Check your connection.', 'err');
    });
}

function renderTable() {
  const wrap = document.getElementById('srTableWrap');
  if (!srRows.length) {
    wrap.innerHTML = '<div class="empty-state"><i class="fa-solid fa-inbox"></i><p>No SR codes found</p></div>';
    return;
  }

  let html = `<table class="sr-table">
    <thead>
      <tr>
        <th><i class="fa-solid fa-check"></i></th>
        <th>SR Code</th>
        <th>Field Summary Code</th>
        <th>Records</th>
        <th>Status</th>
        <th>Result</th>
      </tr>
    </thead>
    <tbody id="srTbody">`;

  srRows.forEach((row, i) => {
    const disabled  = row.already_created ? 'disabled' : '';
    const checked   = (!row.already_created) ? 'checked' : '';
    const statusBadge = getBadge(row.status, row.result_msg);

    html += `<tr id="row_${i}">
      <td><input type="checkbox" class="sr-chk" data-idx="${i}" ${checked} ${disabled}
          onchange="onCheckChange()"></td>
      <td><span class="sr-code-badge">${escHtml(row.sr_code)}</span></td>
      <td><span class="fs-code-cell" id="fscode_${i}">${escHtml(row.fs_code)}</span></td>
      <td style="font-family:var(--mono);font-size:11px;color:var(--text-muted);">${row.record_count}</td>
      <td id="status_${i}">${statusBadge}</td>
      <td id="result_${i}" style="font-size:11px;color:var(--text-muted);">${escHtml(row.result_msg)}</td>
    </tr>`;
  });

  html += '</tbody></table>';
  wrap.innerHTML = html;
  onCheckChange();
}

function getBadge(status, msg) {
  const icons = { pending:'clock', ok:'circle-check', err:'circle-xmark', skip:'ban', running:'spinner fa-spin' };
  const cls   = { pending:'rs-pending', ok:'rs-ok', err:'rs-err', skip:'rs-skip', running:'rs-running' };
  const lbl   = { pending:'Pending', ok:'Created', err:'Failed', skip:'Already Exists', running:'Creating…' };
  const icon  = icons[status] || 'clock';
  const c     = cls[status]   || 'rs-pending';
  const l     = lbl[status]   || status;
  return `<span class="row-status ${c}"><i class="fa-solid fa-${icon}"></i> ${l}</span>`;
}

function onCheckChange() {
  const checks = document.querySelectorAll('.sr-chk:not(:disabled)');
  const checked = document.querySelectorAll('.sr-chk:not(:disabled):checked');
  document.getElementById('selCount').textContent = checked.length;
  updateStats();

  // Sync selectAll
  const allChk = document.getElementById('selectAll');
  allChk.indeterminate = checked.length > 0 && checked.length < checks.length;
  allChk.checked = checks.length > 0 && checked.length === checks.length;

  const createBtn = document.getElementById('createBtn');
  createBtn.disabled = checked.length === 0 || !$('#bulkEmpSelect').val();
}

function toggleSelectAll(checked) {
  document.querySelectorAll('.sr-chk:not(:disabled)').forEach(chk => chk.checked = checked);
  onCheckChange();
}

function updateStats() {
  const total   = srRows.length;
  const avail   = srRows.filter(r => !r.already_created).length;
  const created = srRows.filter(r =>  r.already_created).length;
  const sel     = document.querySelectorAll('.sr-chk:not(:disabled):checked').length;
  document.getElementById('statTotal').textContent   = total;
  document.getElementById('statAvail').textContent   = avail;
  document.getElementById('statCreated').textContent = created;
  document.getElementById('statSelected').textContent = sel;
}

/* ── Bulk Create ── */
async function bulkCreate() {
  const empId  = $('#bulkEmpSelect').val();
  const date   = document.getElementById('bulk_date').value;

  if (!empId) { showAlert('Please select an employee first.', 'inf'); return; }

  const toCreate = [];
  document.querySelectorAll('.sr-chk:not(:disabled):checked').forEach(chk => {
    toCreate.push(parseInt(chk.dataset.idx));
  });

  if (!toCreate.length) { showAlert('No SR codes selected.', 'inf'); return; }

  const btn = document.getElementById('createBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spin"></span> Creating…';
  document.getElementById('alertBox').innerHTML = '';

  const progWrap  = document.getElementById('progWrap');
  const progFill  = document.getElementById('progFill');
  const progLabel = document.getElementById('progLabel');
  progWrap.classList.add('show');

  let done = 0, success = 0, failed = 0;
  const total = toCreate.length;

  for (const idx of toCreate) {
    const row = srRows[idx];

    // Update row status → running
    setRowStatus(idx, 'running', '');

    const fd = new FormData();
    fd.append('delivery_date',               date);
    fd.append('field_summary_code',          row.fs_code);
    fd.append('sr_code',                     row.sr_code);
    fd.append('delivery_person_employee_id', empId);
    fd.append('employee_id',                 empId);

    try {
      const resp = await fetch('process_field_summary.php', { method:'POST', body:fd });
      const data = await resp.json();

      if (data.success) {
        success++;
        srRows[idx].status     = 'ok';
        srRows[idx].result_msg = data.total_invoices + ' invoice(s)';
        setRowStatus(idx, 'ok', data.total_invoices + ' invoice(s) imported');
      } else {
        failed++;
        srRows[idx].status     = 'err';
        srRows[idx].result_msg = data.message || 'Failed';
        setRowStatus(idx, 'err', data.message || 'Failed');
      }
    } catch (e) {
      failed++;
      srRows[idx].status     = 'err';
      srRows[idx].result_msg = 'Network error';
      setRowStatus(idx, 'err', 'Network error');
    }

    done++;
    const pct = Math.round((done / total) * 100);
    progFill.style.width = pct + '%';
    progLabel.textContent = done + ' / ' + total + ' processed (' + pct + '%)';
  }

  btn.innerHTML = '<i class="fa-solid fa-bolt"></i> Create Selected Field Summaries';
  updateStats();

  if (failed === 0) {
    showAlert('<i class="fa-solid fa-circle-check"></i> All ' + success + ' field summar' + (success===1?'y':'ies') + ' created successfully!', 'ok');
  } else if (success === 0) {
    showAlert('<i class="fa-solid fa-circle-xmark"></i> All ' + failed + ' creation(s) failed. Check individual rows for details.', 'err');
  } else {
    showAlert('<i class="fa-solid fa-triangle-exclamation"></i> ' + success + ' created, ' + failed + ' failed. Check rows for details.', 'inf');
  }

  // Reload to update availability
  setTimeout(() => loadSRCodes(), 2000);
}

function setRowStatus(idx, status, msg) {
  const statusEl = document.getElementById('status_' + idx);
  const resultEl = document.getElementById('result_' + idx);
  if (statusEl) statusEl.innerHTML = getBadge(status, msg);
  if (resultEl) resultEl.textContent = msg;
}

/* ── Alert ── */
function showAlert(msg, type) {
  const cls = type === 'ok' ? 'alert-ok' : type === 'err' ? 'alert-err' : 'alert-inf';
  document.getElementById('alertBox').innerHTML =
    '<div class="alert ' + cls + '">' + msg + '</div>';
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── Watch employee to re-check button state ── */
$('#bulkEmpSelect').on('change', onCheckChange);

/* ── Enter key on date ── */
document.getElementById('bulk_date').addEventListener('keydown', e => { if (e.key === 'Enter') loadSRCodes(); });
</script>

<?php include 'footer.php'; ?>