<?php
include 'config.php';
include 'header.php';
?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet"/>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

<style>
:root {
  --bg:#f4f4f0;--surface:#fff;--border:#ddd;--text:#111;--muted:#666;
  --accent:#1a1a2e;--accent-lite:#e8e8f4;
  --green:#155e2e;--green-bg:#edfaf3;--green-bd:#9ad4b8;
  --red:#7f1d1d;--red-bg:#fef2f2;--red-bd:#fca5a5;
  --amber:#78350f;--amber-bg:#fffbeb;--amber-bd:#fcd34d;
  --mono:'IBM Plex Mono',monospace;--sans:'IBM Plex Sans',sans-serif;
}
@import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600;700&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap');
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--sans);background:var(--bg);color:var(--text);}

.ph{background:var(--accent);color:#fff;padding:18px 22px;margin-bottom:18px;
    border-radius:0 0 8px 8px;display:flex;align-items:center;gap:12px;}
.ph-icon{width:38px;height:38px;background:rgba(255,255,255,.12);border-radius:6px;
         display:flex;align-items:center;justify-content:center;font-size:17px;}
.ph h2{font-size:15px;font-weight:700;}
.ph p{font-size:11px;color:rgba(255,255,255,.6);margin-top:2px;}
.ph-badge{margin-left:auto;background:rgba(255,255,255,.15);border-radius:20px;
          padding:3px 10px;font-size:11px;font-family:var(--mono);white-space:nowrap;}

.card{background:var(--surface);border:1px solid var(--border);border-radius:6px;margin-bottom:14px;}
.card-head{padding:10px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;
           gap:7px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);}
.card-body{padding:14px;}

.stats{display:flex;border:1px solid var(--border);border-radius:5px;overflow:hidden;margin-bottom:14px;}
.sc{flex:1;padding:10px 12px;border-right:1px solid var(--border);text-align:center;}
.sc:last-child{border-right:none;}
.sv{font-size:20px;font-weight:700;font-family:var(--mono);color:var(--accent);}
.sl{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-top:1px;}

.toolbar{display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap;}
.tl-left{display:flex;align-items:center;gap:8px;}
.tl-right{margin-left:auto;display:flex;gap:6px;}
.filter-input{height:32px;padding:0 10px;border:1px solid var(--border);border-radius:4px;
              font-size:12px;font-family:var(--sans);background:#fff;width:180px;}
.filter-input:focus{outline:none;border-color:var(--accent);}

.s2-wrap{width:280px;}
.select2-container{width:100%!important;}
.select2-container--default .select2-selection--single{height:34px;border:1px solid var(--border);border-radius:4px;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:34px;padding-left:9px;font-size:12px;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:34px;}
.select2-container--default.select2-container--focus .select2-selection--single{border-color:var(--accent);}
.select2-dropdown{border:1px solid var(--border);border-radius:4px;font-size:12px;font-family:var(--sans);}
.select2-container--default .select2-results__option--highlighted[aria-selected]{background:var(--accent);}
.emp-opt{display:flex;align-items:center;gap:5px;}
.emp-code{font-size:10px;font-weight:700;background:#f0f0f0;border:1px solid #e0e0e0;border-radius:3px;
          padding:1px 5px;color:#444;white-space:nowrap;font-family:monospace;}

.btn{display:inline-flex;align-items:center;gap:5px;padding:0 13px;height:32px;border:none;
     border-radius:4px;font-size:12px;font-weight:600;font-family:var(--sans);cursor:pointer;transition:background .15s;}
.btn-primary{background:var(--green);color:#fff;}
.btn-primary:hover:not(:disabled){background:#0f4520;}
.btn-primary:disabled{opacity:.4;cursor:not-allowed;}
.btn-ghost{background:#f5f5f0;color:var(--muted);border:1px solid var(--border);}
.btn-ghost:hover{background:#ebebeb;color:var(--text);}
.btn-reload{background:var(--accent);color:#fff;}
.btn-reload:hover{background:#2d2d50;}

.sr-table{width:100%;border-collapse:collapse;font-size:12px;}
.sr-table thead th{background:var(--accent);color:#fff;padding:8px 10px;text-align:left;
                   font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;}
.sr-table thead th:first-child{width:34px;text-align:center;}
.sr-table tbody tr{border-bottom:1px solid #eee;transition:background .1s;}
.sr-table tbody tr:hover{background:#fafaf8;}
.sr-table tbody td{padding:7px 10px;vertical-align:middle;}
.sr-table tbody td:first-child{text-align:center;}
.group-row td{background:#f4f4f0;font-size:11px;font-weight:700;color:var(--muted);
              padding:5px 10px;letter-spacing:.3px;border-bottom:1px solid var(--border);}

.badge-sr{font-family:var(--mono);font-size:11px;font-weight:700;background:var(--accent-lite);
          border:1px solid #c0c0e0;border-radius:3px;padding:2px 7px;color:var(--accent);}
.badge-code{font-family:var(--mono);font-size:10px;color:#555;background:#f8f8f4;
            border:1px solid #e0e0e0;border-radius:3px;padding:2px 7px;display:inline-block;}
.badge-date{font-size:11px;font-family:var(--mono);color:var(--muted);}

.rs{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;
    padding:2px 7px;border-radius:10px;white-space:nowrap;}
.rs-pending{background:var(--amber-bg);color:var(--amber);border:1px solid var(--amber-bd);}
.rs-ok     {background:var(--green-bg);color:var(--green);border:1px solid var(--green-bd);}
.rs-err    {background:var(--red-bg);  color:var(--red);  border:1px solid var(--red-bd);}
.rs-run    {background:var(--accent-lite);color:var(--accent);border:1px solid #c0c0e0;}

.prog-wrap{display:none;margin:10px 0 0;}
.prog-wrap.show{display:block;}
.prog-track{background:#e8e8e0;border-radius:3px;height:7px;overflow:hidden;}
.prog-fill{height:100%;background:var(--green);border-radius:3px;transition:width .3s;width:0%;}
.prog-lbl{font-size:11px;color:var(--muted);margin-top:4px;font-family:var(--mono);}

.alert{display:flex;align-items:flex-start;gap:8px;padding:10px 12px;border-radius:4px;font-size:12px;margin-bottom:12px;}
.al-ok {background:var(--green-bg);color:var(--green);border:1px solid var(--green-bd);}
.al-err{background:var(--red-bg);  color:var(--red);  border:1px solid var(--red-bd);}
.al-inf{background:var(--amber-bg);color:var(--amber); border:1px solid var(--amber-bd);}

.spin{display:inline-block;width:11px;height:11px;border:2px solid rgba(255,255,255,.3);
      border-top-color:#fff;border-radius:50%;animation:sp 1s linear infinite;}
.spin-d{border-color:#ddd;border-top-color:var(--accent);}
@keyframes sp{to{transform:rotate(360deg)}}

.empty{text-align:center;padding:36px 20px;color:var(--muted);}
.empty i{font-size:30px;opacity:.25;display:block;margin-bottom:8px;}
.empty p{font-size:12px;}
input[type=checkbox]{width:15px;height:15px;accent-color:var(--accent);cursor:pointer;}
</style>

<div class="ph">
  <div class="ph-icon"><i class="fa-solid fa-layer-group"></i></div>
  <div>
    <h2>Bulk Create Field Summaries</h2>
    <p>All pending SR codes across all dates — create field summaries in one go</p>
  </div>
  <div class="ph-badge" id="headerBadge"><span class="spin"></span> Loading…</div>
</div>

<div id="alertBox"></div>

<!-- Employee -->
<div class="card">
  <div class="card-head"><i class="fa-solid fa-user-tie"></i> Assign Employee</div>
  <div class="card-body" style="display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap;">
    <div>
      <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);margin-bottom:4px;">
        Employee <span style="color:#e53935">*</span>
      </div>
      <div class="s2-wrap">
        <select id="empSelect"><option value="">-- Select Employee --</option></select>
      </div>
    </div>
    <div style="font-size:11px;color:var(--muted);padding-bottom:5px;max-width:320px;line-height:1.5;">
      <i class="fa-solid fa-circle-info" style="margin-right:3px;"></i>
      This employee will be assigned to <strong>all</strong> field summaries created in this batch.
    </div>
  </div>
</div>

<!-- Table -->
<div class="card">
  <div class="card-head">
    <i class="fa-solid fa-table-list"></i> Pending SR Codes
    <span id="cardCount" style="margin-left:auto;font-weight:400;font-family:var(--mono);font-size:11px;"></span>
  </div>
  <div class="card-body">

    <div class="stats">
      <div class="sc"><div class="sv" id="stTotal">—</div><div class="sl">Total Pending</div></div>
      <div class="sc"><div class="sv" id="stDates">—</div><div class="sl">Dates</div></div>
      <div class="sc"><div class="sv" id="stSelected">0</div><div class="sl">Selected</div></div>
      <div class="sc"><div class="sv" id="stDone">0</div><div class="sl">Created</div></div>
    </div>

    <div class="toolbar">
      <div class="tl-left">
        <input type="checkbox" id="selectAll" onchange="toggleAll(this.checked)" title="Select / deselect all">
        <label for="selectAll" style="font-size:12px;cursor:pointer;">Select All</label>
        <input type="text" class="filter-input" id="filterInput" placeholder="Filter by SR code or date…" oninput="applyFilter()">
      </div>
      <div class="tl-right">
        <button class="btn btn-reload" onclick="loadAll()"><i class="fa-solid fa-arrows-rotate"></i> Refresh</button>
        <button class="btn btn-primary" id="createBtn" disabled onclick="bulkCreate()">
          <i class="fa-solid fa-bolt"></i> Create Selected
        </button>
        <button class="btn btn-ghost" onclick="window.location.href='field_summary_list.php'">
          <i class="fa-solid fa-list"></i> View All
        </button>
      </div>
    </div>

    <div id="tableWrap">
      <div style="text-align:center;padding:30px;color:var(--muted);font-size:12px;">
        <span class="spin spin-d"></span>&nbsp; Loading pending SR codes…
      </div>
    </div>

    <div class="prog-wrap" id="progWrap">
      <div class="prog-track"><div class="prog-fill" id="progFill"></div></div>
      <div class="prog-lbl" id="progLbl">0 / 0</div>
    </div>

  </div>
</div>

<script>
let allRows   = [];
let rowStates = {};
let doneCount = 0;

/* ── Select2 ── */
function fmtResult(opt) {
  if (!opt.id) return opt.text;
  const code  = $(opt.element).data('code')  || '';
  const desig = $(opt.element).data('desig') || '';
  return $('<span class="emp-opt">'
    + (code ? '<span class="emp-code">' + code + '</span>' : '')
    + '<span>' + opt.text + (desig ? ' <span style="font-size:11px;color:#999;">· ' + desig + '</span>' : '') + '</span>'
    + '</span>');
}
function fmtSel(opt) {
  if (!opt.id) return opt.text;
  const code = $(opt.element).data('code') || '';
  return code ? '[' + code + '] ' + opt.text : opt.text;
}
$('#empSelect').select2({ placeholder:'-- Select Employee --', allowClear:true, width:'100%', templateResult:fmtResult, templateSelection:fmtSel });
$('#empSelect').on('change', syncBtn);

/* ── Load employees ── */
fetch('get_all_employees.php').then(r => r.json()).then(data => {
  if (data.success && data.employees) {
    data.employees.forEach(emp => {
      const opt = new Option(emp.employee_name, emp.id);
      $(opt).attr('data-code', emp.employee_code || '').attr('data-desig', emp.designation || '');
      $('#empSelect').append(opt);
    });
    $('#empSelect').trigger('change');
  }
});

/* ── Load all pending SR codes ── */
function loadAll() {
  document.getElementById('tableWrap').innerHTML =
    '<div style="text-align:center;padding:30px;color:var(--muted);font-size:12px;"><span class="spin spin-d"></span>&nbsp; Loading…</div>';
  document.getElementById('headerBadge').innerHTML = '<span class="spin"></span> Loading…';
  document.getElementById('alertBox').innerHTML = '';
  doneCount = 0;
  document.getElementById('stDone').textContent = '0';

  fetch('get_all_pending_sr_codes.php')
    .then(r => r.json())
    .then(data => {
      if (!data.success) { showAlert(data.message || 'Error loading data', 'err'); return; }

      allRows   = data.rows || [];
      rowStates = {};
      allRows.forEach((_, i) => { rowStates[i] = { status:'pending', msg:'' }; });

      renderTable(allRows);
      updateStats();

      const total = allRows.length;
      document.getElementById('headerBadge').textContent = total + ' pending';
      document.getElementById('cardCount').textContent   = total + ' SR code' + (total===1?'':'s') + ' pending';

      if (total === 0) showAlert('<i class="fa-solid fa-circle-check"></i>&nbsp; All field summaries are up to date!', 'ok');
    })
    .catch(() => {
      showAlert('Error fetching data.', 'err');
      document.getElementById('headerBadge').textContent = 'Error';
    });
}

loadAll();

/* ── Render ── */
function renderTable(rows) {
  const wrap = document.getElementById('tableWrap');
  if (!rows.length) {
    wrap.innerHTML = '<div class="empty"><i class="fa-solid fa-circle-check"></i><p>No pending SR codes — everything is up to date!</p></div>';
    syncBtn(); return;
  }

  // Group by date descending
  const groups = {};
  rows.forEach(row => {
    if (!groups[row.delivery_date]) groups[row.delivery_date] = [];
    groups[row.delivery_date].push(row);
  });
  const dates = Object.keys(groups).sort().reverse();

  let html = `<table class="sr-table">
    <thead><tr>
      <th><i class="fa-solid fa-check"></i></th>
      <th>Date</th>
      <th>SR Code</th>
      <th>Field Summary Code</th>
      <th style="text-align:right;">Records</th>
      <th>Status</th>
      <th>Result</th>
    </tr></thead><tbody id="srTbody">`;

  dates.forEach(date => {
    const grp = groups[date];
    html += `<tr class="group-row"><td colspan="7">
      <i class="fa-solid fa-calendar-day" style="margin-right:5px;opacity:.6;"></i>
      ${formatDate(date)} &nbsp;·&nbsp; ${grp.length} SR code${grp.length===1?'':'s'}
    </td></tr>`;

    grp.forEach(row => {
      const idx = allRows.indexOf(row);
      const st  = rowStates[idx] || { status:'pending', msg:'' };
      const dis = st.status === 'ok' ? 'disabled' : '';
      const chk = st.status !== 'ok' ? 'checked' : '';
      html += `<tr id="row_${idx}">
        <td><input type="checkbox" class="sr-chk" data-idx="${idx}" ${chk} ${dis} onchange="syncBtn()"></td>
        <td><span class="badge-date">${escH(row.delivery_date)}</span></td>
        <td><span class="badge-sr">${escH(row.sr_code)}</span></td>
        <td><span class="badge-code">${escH(row.fs_code)}</span></td>
        <td style="text-align:right;font-family:var(--mono);font-size:11px;color:var(--muted);">${row.record_count}</td>
        <td id="st_${idx}">${badge(st.status)}</td>
        <td id="rs_${idx}" style="font-size:11px;color:var(--muted);">${escH(st.msg)}</td>
      </tr>`;
    });
  });

  html += '</tbody></table>';
  wrap.innerHTML = html;
  syncBtn();
}

/* ── Filter ── */
function applyFilter() {
  const q = document.getElementById('filterInput').value.toLowerCase().trim();
  const filtered = q
    ? allRows.filter(r => r.sr_code.toLowerCase().includes(q) || r.delivery_date.includes(q) || r.fs_code.toLowerCase().includes(q))
    : allRows;
  renderTable(filtered);
}

/* ── Select all ── */
function toggleAll(checked) {
  document.querySelectorAll('.sr-chk:not(:disabled)').forEach(c => c.checked = checked);
  syncBtn();
}

/* ── Sync ── */
function syncBtn() {
  const sel = document.querySelectorAll('.sr-chk:not(:disabled):checked').length;
  const all = document.querySelectorAll('.sr-chk:not(:disabled)').length;
  document.getElementById('stSelected').textContent = sel;
  document.getElementById('createBtn').disabled = (sel === 0 || !$('#empSelect').val());
  const allChk = document.getElementById('selectAll');
  allChk.indeterminate = sel > 0 && sel < all;
  allChk.checked = all > 0 && sel === all;
}

function updateStats() {
  const dates = new Set(allRows.map(r => r.delivery_date));
  document.getElementById('stTotal').textContent = allRows.length;
  document.getElementById('stDates').textContent = dates.size;
  syncBtn();
}

/* ── Bulk Create ── */
async function bulkCreate() {
  const empId = $('#empSelect').val();
  if (!empId) { showAlert('Please select an employee first.', 'inf'); return; }

  const toCreate = [];
  document.querySelectorAll('.sr-chk:not(:disabled):checked').forEach(chk => {
    toCreate.push(parseInt(chk.dataset.idx));
  });
  if (!toCreate.length) return;

  const btn = document.getElementById('createBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spin"></span> Creating…';
  document.getElementById('alertBox').innerHTML = '';

  const progWrap = document.getElementById('progWrap');
  const fill     = document.getElementById('progFill');
  const lbl      = document.getElementById('progLbl');
  progWrap.classList.add('show');
  fill.style.width = '0%';

  let done = 0, ok = 0, fail = 0;
  const total = toCreate.length;

  for (const idx of toCreate) {
    const row = allRows[idx];
    rowStates[idx] = { status:'run', msg:'' };
    setRowUI(idx, 'run', '');

    const fd = new FormData();
    fd.append('delivery_date',               row.delivery_date);
    fd.append('field_summary_code',          row.fs_code);
    fd.append('sr_code',                     row.sr_code);
    fd.append('delivery_person_employee_id', empId);
    fd.append('employee_id',                 empId);

    try {
      const res  = await fetch('process_field_summary.php', { method:'POST', body:fd });
      const data = await res.json();
      if (data.success) {
        ok++; doneCount++;
        rowStates[idx] = { status:'ok', msg: data.total_invoices + ' inv.' };
        setRowUI(idx, 'ok', data.total_invoices + ' invoice(s) imported');
        const chk = document.querySelector('.sr-chk[data-idx="' + idx + '"]');
        if (chk) chk.disabled = true;
      } else {
        fail++;
        rowStates[idx] = { status:'err', msg: data.message || 'Failed' };
        setRowUI(idx, 'err', data.message || 'Failed');
      }
    } catch (e) {
      fail++;
      rowStates[idx] = { status:'err', msg:'Network error' };
      setRowUI(idx, 'err', 'Network error');
    }

    done++;
    const pct = Math.round((done / total) * 100);
    fill.style.width = pct + '%';
    lbl.textContent  = done + ' / ' + total + ' processed (' + pct + '%)';
    document.getElementById('stDone').textContent = doneCount;
    syncBtn();
  }

  btn.innerHTML = '<i class="fa-solid fa-bolt"></i> Create Selected';
  syncBtn();

  if (fail === 0)      showAlert('<i class="fa-solid fa-circle-check"></i>&nbsp; ' + ok + ' field summar' + (ok===1?'y':'ies') + ' created successfully!', 'ok');
  else if (ok === 0)   showAlert('<i class="fa-solid fa-circle-xmark"></i>&nbsp; All ' + fail + ' creation(s) failed. See rows for details.', 'err');
  else                 showAlert('<i class="fa-solid fa-triangle-exclamation"></i>&nbsp; ' + ok + ' created, ' + fail + ' failed.', 'inf');

  const remaining = allRows.length - doneCount;
  document.getElementById('headerBadge').textContent = remaining + ' pending';
  document.getElementById('cardCount').textContent   = remaining + ' SR code' + (remaining===1?'':'s') + ' pending';
  document.getElementById('stTotal').textContent     = remaining;
}

function setRowUI(idx, status, msg) {
  const stEl = document.getElementById('st_' + idx);
  const rsEl = document.getElementById('rs_' + idx);
  if (stEl) stEl.innerHTML = badge(status);
  if (rsEl) rsEl.textContent = msg;
}
function badge(s) {
  const m = {
    pending:['rs-pending','clock','Pending'],
    ok:     ['rs-ok','circle-check','Created'],
    err:    ['rs-err','circle-xmark','Failed'],
    run:    ['rs-run','spinner fa-spin','Creating…'],
  };
  const [c,i,l] = m[s] || m.pending;
  return `<span class="rs ${c}"><i class="fa-solid fa-${i}"></i> ${l}</span>`;
}
function showAlert(msg, type) {
  const cls = type==='ok'?'al-ok':type==='err'?'al-err':'al-inf';
  document.getElementById('alertBox').innerHTML = '<div class="alert ' + cls + '">' + msg + '</div>';
  window.scrollTo({ top:0, behavior:'smooth' });
}
function formatDate(d) {
  const [y,m,day] = (d||'').split('-');
  const n = ['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  return day + ' ' + (n[parseInt(m)]||'') + ' ' + y;
}
function escH(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>

<?php include 'footer.php'; ?>