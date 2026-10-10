<?php
/**
 * customer_claim_report.php
 * "CC Report" — Customer Claim Certificate Report
 * Shows ALL imported claim_cert_items with date filters, search, customer/entity/status filters,
 * KPI summary, and Excel export.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
include_once 'config.php';

function ensure_claim_tables_r($conn) {
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS claim_cert_uploads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        file_name VARCHAR(255) NOT NULL,
        customer_code VARCHAR(50) DEFAULT NULL,
        customer_name VARCHAR(255) DEFAULT NULL,
        total_rows INT DEFAULT 0,
        imported INT DEFAULT 0,
        skipped_dup INT DEFAULT 0,
        upload_date DATE DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(100) DEFAULT 'system',
        INDEX idx_customer(customer_code),
        INDEX idx_created(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS claim_cert_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        upload_id INT NOT NULL,
        row_no INT DEFAULT NULL,
        customer_code VARCHAR(50) DEFAULT NULL,
        customer_name VARCHAR(255) DEFAULT NULL,
        ledger_type VARCHAR(100) DEFAULT NULL,
        status VARCHAR(100) DEFAULT NULL,
        claim_type VARCHAR(100) DEFAULT NULL,
        tax_invoice_no VARCHAR(100) DEFAULT NULL,
        invoice_date DATE DEFAULT NULL,
        banking_date DATE DEFAULT NULL,
        entity VARCHAR(50) DEFAULT NULL,
        claim_description TEXT DEFAULT NULL,
        actual_amount DECIMAL(18,4) DEFAULT 0,
        vat_amount DECIMAL(18,4) DEFAULT 0,
        total_amount DECIMAL(18,4) DEFAULT 0,
        imported_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        imported_by VARCHAR(100) DEFAULT 'system',
        INDEX idx_upload(upload_id),
        INDEX idx_tax_inv(tax_invoice_no),
        INDEX idx_customer(customer_code),
        UNIQUE KEY uniq_tax_inv_entity(tax_invoice_no, entity)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* Build WHERE clause shared by report + export */
function cc_build_where($conn) {
    $w = [];

    $dateField = $_REQUEST['date_field'] ?? 'imported_at';
    $allowedFields = ['invoice_date', 'banking_date', 'imported_at', 'upload_date'];
    if (!in_array($dateField, $allowedFields, true)) $dateField = 'imported_at';
    $col = $dateField === 'upload_date' ? 'cu.upload_date' : 'ci.' . $dateField;

    $from = trim($_REQUEST['date_from'] ?? '');
    $to   = trim($_REQUEST['date_to']   ?? '');
    if ($from !== '') {
        $from_e = mysqli_real_escape_string($conn, $from);
        $w[] = "$col >= '$from_e" . ($dateField === 'imported_at' ? ' 00:00:00' : '') . "'";
    }
    if ($to !== '') {
        $to_e = mysqli_real_escape_string($conn, $to);
        $w[] = "$col <= '$to_e" . ($dateField === 'imported_at' ? ' 23:59:59' : '') . "'";
    }

    $search = trim($_REQUEST['search'] ?? '');
    if ($search !== '') {
        $s_e = mysqli_real_escape_string($conn, $search);
        $w[] = "(ci.tax_invoice_no LIKE '%$s_e%' OR ci.claim_description LIKE '%$s_e%' " .
               "OR ci.customer_name LIKE '%$s_e%' OR ci.customer_code LIKE '%$s_e%' " .
               "OR cu.file_name LIKE '%$s_e%')";
    }

    $cust = trim($_REQUEST['customer_code'] ?? '');
    if ($cust !== '') { $c_e = mysqli_real_escape_string($conn, $cust); $w[] = "ci.customer_code='$c_e'"; }

    $entity = trim($_REQUEST['entity'] ?? '');
    if ($entity !== '') { $e_e = mysqli_real_escape_string($conn, $entity); $w[] = "ci.entity='$e_e'"; }

    $status = trim($_REQUEST['status'] ?? '');
    if ($status !== '') { $st_e = mysqli_real_escape_string($conn, $status); $w[] = "ci.status='$st_e'"; }

    $claimType = trim($_REQUEST['claim_type'] ?? '');
    if ($claimType !== '') { $ct_e = mysqli_real_escape_string($conn, $claimType); $w[] = "ci.claim_type='$ct_e'"; }

    $uploadId = trim($_REQUEST['upload_id'] ?? '');
    if ($uploadId !== '' && ctype_digit($uploadId)) { $w[] = "ci.upload_id=" . intval($uploadId); }

    return $w ? ('WHERE ' . implode(' AND ', $w)) : '';
}

/* ── AJAX: get_report_data (paginated) ── */
if (isset($_REQUEST['ajax_action']) && $_REQUEST['ajax_action'] === 'get_report_data') {
    ob_start(); header('Content-Type: application/json');
    ensure_claim_tables_r($conn);

    $where = cc_build_where($conn);
    $page  = max(1, intval($_REQUEST['page'] ?? 1));
    $per   = max(1, min(2000, intval($_REQUEST['per_page'] ?? 100)));
    $off   = ($page - 1) * $per;

    $sortField = $_REQUEST['sort'] ?? 'imported_at';
    $allowedSort = ['imported_at','invoice_date','banking_date','tax_invoice_no','actual_amount','vat_amount','total_amount','customer_code'];
    if (!in_array($sortField, $allowedSort, true)) $sortField = 'imported_at';
    $sortDir = (strtoupper($_REQUEST['dir'] ?? 'DESC') === 'ASC') ? 'ASC' : 'DESC';

    $baseSql = "FROM claim_cert_items ci LEFT JOIN claim_cert_uploads cu ON ci.upload_id = cu.id $where";

    $cntRes = mysqli_query($conn, "SELECT COUNT(*) c, COALESCE(SUM(ci.actual_amount),0) sa,
                                            COALESCE(SUM(ci.vat_amount),0) sv, COALESCE(SUM(ci.total_amount),0) st,
                                            COUNT(DISTINCT ci.customer_code) cust_n,
                                            COUNT(DISTINCT ci.upload_id) upload_n
                                     $baseSql");
    $summary = $cntRes ? mysqli_fetch_assoc($cntRes) : ['c'=>0,'sa'=>0,'sv'=>0,'st'=>0,'cust_n'=>0,'upload_n'=>0];

    $dataRes = mysqli_query($conn, "SELECT ci.*, cu.file_name, cu.upload_date
                                     $baseSql
                                     ORDER BY ci.$sortField $sortDir
                                     LIMIT $per OFFSET $off");
    $rows = [];
    if ($dataRes) while ($r = mysqli_fetch_assoc($dataRes)) $rows[] = $r;

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'rows'    => $rows,
        'summary' => $summary,
        'page'    => $page,
        'per_page'=> $per,
        'total'   => intval($summary['c']),
        'pages'   => max(1, ceil(intval($summary['c']) / $per)),
    ]);
    exit;
}

/* ── AJAX: export_report (no pagination — full filtered set) ── */
if (isset($_REQUEST['ajax_action']) && $_REQUEST['ajax_action'] === 'export_report') {
    ob_start(); header('Content-Type: application/json');
    ensure_claim_tables_r($conn);
    $where = cc_build_where($conn);
    $res = mysqli_query($conn, "SELECT ci.*, cu.file_name, cu.upload_date
                                 FROM claim_cert_items ci LEFT JOIN claim_cert_uploads cu ON ci.upload_id = cu.id
                                 $where ORDER BY ci.imported_at DESC LIMIT 50000");
    $rows = [];
    if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    ob_end_clean();
    echo json_encode(['success'=>true,'rows'=>$rows]);
    exit;
}

/* ── AJAX: get_filter_options ── */
if (isset($_REQUEST['ajax_action']) && $_REQUEST['ajax_action'] === 'get_filter_options') {
    ob_start(); header('Content-Type: application/json');
    ensure_claim_tables_r($conn);
    $custRes   = mysqli_query($conn, "SELECT DISTINCT customer_code, customer_name FROM claim_cert_items WHERE customer_code<>'' ORDER BY customer_name ASC");
    $entRes    = mysqli_query($conn, "SELECT DISTINCT entity FROM claim_cert_items WHERE entity<>'' ORDER BY entity ASC");
    $statRes   = mysqli_query($conn, "SELECT DISTINCT status FROM claim_cert_items WHERE status<>'' ORDER BY status ASC");
    $typeRes   = mysqli_query($conn, "SELECT DISTINCT claim_type FROM claim_cert_items WHERE claim_type<>'' ORDER BY claim_type ASC");
    $customers = []; while ($r = mysqli_fetch_assoc($custRes)) $customers[] = $r;
    $entities  = []; while ($r = mysqli_fetch_assoc($entRes))  $entities[]  = $r['entity'];
    $statuses  = []; while ($r = mysqli_fetch_assoc($statRes)) $statuses[]  = $r['status'];
    $types     = []; while ($r = mysqli_fetch_assoc($typeRes)) $types[]     = $r['claim_type'];
    ob_end_clean();
    echo json_encode(['success'=>true,'customers'=>$customers,'entities'=>$entities,'statuses'=>$statuses,'claim_types'=>$types]);
    exit;
}

include 'header.php';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<style>
:root{
  --ink:#0f172a;--ink2:#1e293b;--ink3:#334155;--muted:#64748b;
  --lite:#f8fafc;--card:#ffffff;--bdr:#e2e8f0;
  --pri:#1e3a5f;--pri2:#2563eb;--teal:#0d9488;
  --green:#16a34a;--red:#dc2626;--amber:#d97706;--violet:#7c3aed;
  --shadow:0 1px 3px rgba(0,0,0,.06),0 4px 16px rgba(0,0,0,.05);
}
.ccr-wrap{width:100%;margin:0;padding:20px 12px 80px;font-family:'Segoe UI',system-ui,sans-serif;}

.ccr-hero{background:linear-gradient(135deg,#0f3460 0%,#16213e 50%,#0a1628 100%);border-radius:16px;padding:20px 26px;display:flex;align-items:center;gap:18px;flex-wrap:wrap;margin-bottom:20px;position:relative;overflow:hidden;}
.ccr-hero::before{content:'';position:absolute;top:-40px;right:-60px;width:240px;height:240px;border-radius:50%;background:rgba(99,102,241,.15);pointer-events:none;}
.hero-icon{width:52px;height:52px;border-radius:14px;background:rgba(255,255,255,.12);display:flex;align-items:center;justify-content:center;font-size:24px;color:#fff;flex-shrink:0;border:1px solid rgba(255,255,255,.18);}
.hero-title{color:#fff;font-size:20px;font-weight:800;line-height:1.2;}
.hero-sub{color:rgba(255,255,255,.65);font-size:12px;margin-top:3px;}
.hero-right{margin-left:auto;display:flex;align-items:center;gap:10px;flex-wrap:wrap;position:relative;z-index:1;}

.kpi-strip{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:16px;}
@media(max-width:980px){.kpi-strip{grid-template-columns:repeat(3,1fr);}}
@media(max-width:520px){.kpi-strip{grid-template-columns:repeat(2,1fr);}}
.kpi-mini{background:var(--card);border:1.5px solid var(--bdr);border-radius:12px;padding:12px 14px;box-shadow:var(--shadow);}
.kpi-mini .km-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:4px;}
.kpi-mini .km-val{font-size:19px;font-weight:900;color:var(--ink);}
.kpi-mini .km-val.green{color:var(--green);}
.kpi-mini .km-val.amber{color:var(--amber);}
.kpi-mini .km-val.violet{color:var(--violet);}
.kpi-mini .km-val.sky{color:#0284c7;}

.rc-card{background:var(--card);border:1.5px solid var(--bdr);border-radius:14px;box-shadow:var(--shadow);overflow:hidden;}
.rc-card-hdr{padding:14px 20px;border-bottom:1.5px solid var(--bdr);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;background:linear-gradient(to right,#fafbff,#fff);}
.rc-card-title{font-size:14px;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:8px;}
.rc-card-body{padding:16px 18px;}

.filter-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:12px;}
.f-field{display:flex;flex-direction:column;gap:4px;}
.f-field label{font-size:10.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;}
.f-field input,.f-field select{border:1.5px solid var(--bdr);border-radius:8px;padding:8px 10px;font-size:12.5px;font-family:inherit;outline:none;color:var(--ink);background:#fff;}
.f-field input:focus,.f-field select:focus{border-color:var(--pri2);}
.filter-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}

.btn-primary{display:inline-flex;align-items:center;gap:7px;background:linear-gradient(135deg,#4f46e5,#6366f1);color:#fff;border:none;border-radius:9px;padding:9px 18px;font-size:12.5px;font-weight:800;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px rgba(99,102,241,.3);}
.btn-primary:hover{filter:brightness(1.08);}
.btn-secondary{background:var(--lite);border:1.5px solid var(--bdr);border-radius:9px;padding:8px 16px;font-size:12.5px;font-weight:700;cursor:pointer;font-family:inherit;color:var(--ink3);}
.btn-secondary:hover{background:#e2e8f0;}
.btn-success{display:inline-flex;align-items:center;gap:7px;background:linear-gradient(135deg,#15803d,var(--green));color:#fff;border:none;border-radius:9px;padding:9px 18px;font-size:12.5px;font-weight:800;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px rgba(22,163,74,.3);}
.btn-success:hover{filter:brightness(1.08);}

.prev-outer{border:1.5px solid var(--bdr);border-radius:10px;overflow:hidden;}
.prev-scroll{overflow-x:auto;max-height:600px;overflow-y:auto;}
.prev-table{width:100%;border-collapse:collapse;font-size:12px;min-width:1300px;}
.prev-table thead th{padding:9px 11px;background:#0f172a;color:#e2e8f0;font-size:10.5px;font-weight:700;text-align:left;white-space:nowrap;border-right:1px solid rgba(255,255,255,.08);position:sticky;top:0;z-index:5;cursor:pointer;user-select:none;}
.prev-table thead th.tr{text-align:right;}
.prev-table thead th:hover{background:#1e293b;}
.prev-table thead th .sort-ic{opacity:.5;font-size:9px;margin-left:3px;}
.prev-table tbody tr{border-bottom:1px solid #f1f5f9;}
.prev-table tbody tr:hover td{background:#f8faff !important;}
.prev-table td{padding:8px 11px;vertical-align:middle;background:#fff;}
.tr{text-align:right;}
.tc{text-align:center;}

.pill{display:inline-flex;align-items:center;gap:3px;padding:2px 9px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap;}
.p-blue{background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;}
.p-green{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.p-red{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}
.p-violet{background:#f5f3ff;color:#6d28d9;border:1px solid #ddd6fe;}
.p-gray{background:#f3f4f6;color:#374151;border:1px solid #e5e5e5;}
.p-teal{background:#ccfbf1;color:#065f46;border:1px solid #5eead4;}
.p-amber{background:#fef3c7;color:#92400e;border:1px solid #fde68a;}

.pager{display:flex;align-items:center;gap:10px;justify-content:flex-end;padding:12px 16px;flex-wrap:wrap;}
.pager select{border:1.5px solid var(--bdr);border-radius:7px;padding:6px 10px;font-size:12px;font-family:inherit;}
.pager button{background:#fff;border:1.5px solid var(--bdr);border-radius:7px;padding:6px 12px;font-size:12px;font-weight:700;cursor:pointer;color:var(--ink3);}
.pager button:disabled{opacity:.4;cursor:not-allowed;}
.pager button:hover:not(:disabled){background:#f1f5f9;}
.pager .pg-info{font-size:12px;color:var(--muted);font-weight:600;}

#toast{position:fixed;bottom:30px;right:26px;background:#166534;color:#fff;padding:12px 22px;border-radius:12px;font-size:13px;font-weight:700;z-index:9999;opacity:0;pointer-events:none;transition:opacity .3s;max-width:360px;}
#toast.show{opacity:1;}
#toast.err{background:var(--red);}
</style>

<div class="ccr-wrap">
  <div class="ccr-hero">
    <div class="hero-icon"><i class="fa-solid fa-chart-line"></i></div>
    <div style="position:relative;z-index:1;">
      <div class="hero-title">Customer Claim Report <span style="font-weight:500;opacity:.7;">(CC Report)</span></div>
      <div class="hero-sub">All imported claim certificate data · Date filters · Search · Export to Excel</div>
    </div>
    <div class="hero-right">
      <button class="btn-success" onclick="exportReport()"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
    </div>
  </div>

  <div class="kpi-strip">
    <div class="kpi-mini"><div class="km-lbl">Records</div><div class="km-val sky" id="kpiCount">—</div></div>
    <div class="kpi-mini"><div class="km-lbl">Customers</div><div class="km-val violet" id="kpiCust">—</div></div>
    <div class="kpi-mini"><div class="km-lbl">Uploads</div><div class="km-val" id="kpiUploads">—</div></div>
    <div class="kpi-mini"><div class="km-lbl">Actual Amt</div><div class="km-val green" id="kpiActual">—</div></div>
    <div class="kpi-mini"><div class="km-lbl">VAT Amt</div><div class="km-val amber" id="kpiVat">—</div></div>
    <div class="kpi-mini"><div class="km-lbl">Total Amt</div><div class="km-val" id="kpiTotal">—</div></div>
  </div>

  <div class="rc-card" style="margin-bottom:16px;">
    <div class="rc-card-hdr">
      <div class="rc-card-title"><i class="fa-solid fa-filter"></i> Filters</div>
      <div class="filter-actions">
        <button class="btn-secondary" onclick="clearFilters()"><i class="fa-solid fa-rotate-left"></i> Clear</button>
        <button class="btn-primary" onclick="loadReport(1)"><i class="fa-solid fa-magnifying-glass"></i> Apply</button>
      </div>
    </div>
    <div class="rc-card-body">
      <div class="filter-grid">
        <div class="f-field">
          <label>Date Field</label>
          <select id="fDateField">
            <option value="imported_at">Import Date</option>
            <option value="invoice_date">Invoice Date</option>
            <option value="banking_date">Banking Date</option>
            <option value="upload_date">Upload Date</option>
          </select>
        </div>
        <div class="f-field"><label>From</label><input type="date" id="fFrom"></div>
        <div class="f-field"><label>To</label><input type="date" id="fTo"></div>
        <div class="f-field" style="grid-column:span 2;">
          <label>Search</label>
          <input type="text" id="fSearch" placeholder="Tax invoice, description, customer, file name…">
        </div>
        <div class="f-field">
          <label>Customer</label>
          <select id="fCustomer"><option value="">All Customers</option></select>
        </div>
        <div class="f-field">
          <label>Entity</label>
          <select id="fEntity"><option value="">All Entities</option></select>
        </div>
        <div class="f-field">
          <label>Status</label>
          <select id="fStatus"><option value="">All Statuses</option></select>
        </div>
        <div class="f-field">
          <label>Claim Type</label>
          <select id="fClaimType"><option value="">All Types</option></select>
        </div>
      </div>
    </div>
  </div>

  <div class="rc-card">
    <div class="rc-card-hdr">
      <div class="rc-card-title"><i class="fa-solid fa-table-list"></i> Claim Records</div>
      <div style="font-size:11.5px;color:var(--muted);" id="resultInfo"></div>
    </div>
    <div class="prev-outer">
      <div class="prev-scroll">
        <table class="prev-table">
          <thead>
            <tr>
              <th>#</th>
              <th onclick="setSort('customer_code')">Customer <span class="sort-ic" id="si-customer_code"></span></th>
              <th>File / Upload</th>
              <th>Ledger Type</th><th>Status</th><th>Claim Type</th>
              <th onclick="setSort('tax_invoice_no')">Tax Invoice No. <span class="sort-ic" id="si-tax_invoice_no"></span></th>
              <th onclick="setSort('invoice_date')">Invoice Date <span class="sort-ic" id="si-invoice_date"></span></th>
              <th onclick="setSort('banking_date')">Banking Date <span class="sort-ic" id="si-banking_date"></span></th>
              <th>Entity</th>
              <th>Claim Description</th>
              <th class="tr" onclick="setSort('actual_amount')">Actual Amt <span class="sort-ic" id="si-actual_amount"></span></th>
              <th class="tr" onclick="setSort('vat_amount')">VAT Amt <span class="sort-ic" id="si-vat_amount"></span></th>
              <th class="tr" onclick="setSort('total_amount')">Total <span class="sort-ic" id="si-total_amount"></span></th>
              <th onclick="setSort('imported_at')">Imported At <span class="sort-ic" id="si-imported_at"></span></th>
            </tr>
          </thead>
          <tbody id="repBody">
            <tr><td colspan="15" style="padding:40px;text-align:center;color:var(--muted);"><i class="fa-solid fa-spinner fa-spin" style="font-size:24px;display:block;margin-bottom:8px;"></i>Loading…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <div class="pager">
      <span class="pg-info" id="pgInfo"></span>
      <select id="perPageSel" onchange="loadReport(1)">
        <option value="50">50 / page</option>
        <option value="100" selected>100 / page</option>
        <option value="250">250 / page</option>
        <option value="500">500 / page</option>
      </select>
      <button id="pgFirst" onclick="loadReport(1)"><i class="fa-solid fa-angles-left"></i></button>
      <button id="pgPrev" onclick="loadReport(_curPage-1)"><i class="fa-solid fa-angle-left"></i></button>
      <span class="pg-info" id="pgPageLbl">Page 1</span>
      <button id="pgNext" onclick="loadReport(_curPage+1)"><i class="fa-solid fa-angle-right"></i></button>
      <button id="pgLast" onclick="loadReport(_totalPages)"><i class="fa-solid fa-angles-right"></i></button>
    </div>
  </div>
</div>

<div id="toast"></div>

<script>
let _curPage = 1, _totalPages = 1, _sortField = 'imported_at', _sortDir = 'DESC';
let _searchDebounce = null;

function esc(s){ if(s==null) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }
function fmtN(v){ return parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function fmtMoney(v){ return 'Rs. ' + fmtN(v); }
function showToast(msg,type){
  const t=document.getElementById('toast');
  t.className = type==='err' ? 'err' : '';
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(t._t);
  t._t=setTimeout(()=>t.classList.remove('show'),3500);
}

/* ── Load filter dropdown options ── */
async function loadFilterOptions(){
  try{
    const res = await fetch('customer_claim_report.php?ajax_action=get_filter_options');
    const d = await res.json();
    if(!d.success) return;
    const custSel = document.getElementById('fCustomer');
    d.customers.forEach(c=>{
      const opt = document.createElement('option');
      opt.value = c.customer_code;
      opt.textContent = c.customer_code + (c.customer_name ? ' — '+c.customer_name : '');
      custSel.appendChild(opt);
    });
    const entSel = document.getElementById('fEntity');
    d.entities.forEach(e=>{
      const opt = document.createElement('option'); opt.value = e; opt.textContent = e; entSel.appendChild(opt);
    });
    const stSel = document.getElementById('fStatus');
    d.statuses.forEach(s=>{
      const opt = document.createElement('option'); opt.value = s; opt.textContent = s; stSel.appendChild(opt);
    });
    const ctSel = document.getElementById('fClaimType');
    d.claim_types.forEach(t=>{
      const opt = document.createElement('option'); opt.value = t; opt.textContent = t; ctSel.appendChild(opt);
    });
  }catch(e){ /* silent */ }
}

function getFilterParams(){
  return {
    date_field: document.getElementById('fDateField').value,
    date_from:  document.getElementById('fFrom').value,
    date_to:    document.getElementById('fTo').value,
    search:     document.getElementById('fSearch').value.trim(),
    customer_code: document.getElementById('fCustomer').value,
    entity:     document.getElementById('fEntity').value,
    status:     document.getElementById('fStatus').value,
    claim_type: document.getElementById('fClaimType').value,
  };
}

function clearFilters(){
  document.getElementById('fDateField').value = 'imported_at';
  document.getElementById('fFrom').value = '';
  document.getElementById('fTo').value = '';
  document.getElementById('fSearch').value = '';
  document.getElementById('fCustomer').value = '';
  document.getElementById('fEntity').value = '';
  document.getElementById('fStatus').value = '';
  document.getElementById('fClaimType').value = '';
  loadReport(1);
}

function setSort(field){
  if(_sortField === field){ _sortDir = _sortDir === 'ASC' ? 'DESC' : 'ASC'; }
  else { _sortField = field; _sortDir = 'DESC'; }
  document.querySelectorAll('.sort-ic').forEach(el=>el.innerHTML='');
  const ic = document.getElementById('si-'+field);
  if(ic) ic.innerHTML = _sortDir === 'ASC' ? '<i class="fa-solid fa-arrow-up"></i>' : '<i class="fa-solid fa-arrow-down"></i>';
  loadReport(1);
}

const statusBadge = s => {
  if (!s) return '<span style="color:var(--muted)">—</span>';
  if (s === 'Completed')  return '<span class="pill p-green">' + esc(s) + '</span>';
  if (s === 'Pending' || s === 'In Progress') return '<span class="pill p-amber">' + esc(s) + '</span>';
  return '<span class="pill p-gray">' + esc(s) + '</span>';
};
const typePill = t => {
  if (!t) return '—';
  if (t.includes('Damages')) return '<span class="pill p-red">' + esc(t) + '</span>';
  if (t.includes('Drive') || t.includes('Loyalty')) return '<span class="pill p-violet">' + esc(t) + '</span>';
  return '<span class="pill p-teal">' + esc(t) + '</span>';
};

async function loadReport(page){
  page = Math.max(1, page || 1);
  _curPage = page;
  document.getElementById('repBody').innerHTML =
    '<tr><td colspan="15" style="padding:40px;text-align:center;color:var(--muted);"><i class="fa-solid fa-spinner fa-spin" style="font-size:24px;display:block;margin-bottom:8px;"></i>Loading…</td></tr>';

  const params = getFilterParams();
  const per = document.getElementById('perPageSel').value;
  const qs = new URLSearchParams({
    ajax_action: 'get_report_data',
    page: page, per_page: per, sort: _sortField, dir: _sortDir,
    ...params
  });

  try{
    const res = await fetch('customer_claim_report.php?' + qs.toString());
    const d = await res.json();
    if(!d.success) throw new Error('Load failed');

    _totalPages = d.pages;
    document.getElementById('kpiCount').textContent   = d.summary.c;
    document.getElementById('kpiCust').textContent    = d.summary.cust_n;
    document.getElementById('kpiUploads').textContent = d.summary.upload_n;
    document.getElementById('kpiActual').textContent  = fmtMoney(d.summary.sa);
    document.getElementById('kpiVat').textContent      = fmtMoney(d.summary.sv);
    document.getElementById('kpiTotal').textContent    = fmtMoney(d.summary.st);

    document.getElementById('resultInfo').textContent = d.total + ' record(s) found';
    document.getElementById('pgInfo').textContent = 'Showing ' + ((page-1)*per + (d.rows.length?1:0)) + '–' + ((page-1)*per + d.rows.length) + ' of ' + d.total;
    document.getElementById('pgPageLbl').textContent = 'Page ' + page + ' of ' + _totalPages;
    document.getElementById('pgFirst').disabled = page <= 1;
    document.getElementById('pgPrev').disabled  = page <= 1;
    document.getElementById('pgNext').disabled  = page >= _totalPages;
    document.getElementById('pgLast').disabled  = page >= _totalPages;

    if(!d.rows.length){
      document.getElementById('repBody').innerHTML =
        '<tr><td colspan="15" style="padding:34px;text-align:center;color:var(--muted);">No records match the current filters.</td></tr>';
      return;
    }

    let h = '';
    d.rows.forEach((r, i) => {
      h += '<tr>' +
        '<td style="font-family:monospace;font-size:11px;color:var(--muted);">' + ((( _curPage-1)*per)+i+1) + '</td>' +
        '<td><span class="pill p-violet">' + esc(r.customer_code||'—') + '</span> <span style="font-size:11px;color:var(--muted);">' + esc(r.customer_name||'') + '</span></td>' +
        '<td style="font-size:11px;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc(r.file_name||'') + '">' + esc(r.file_name||'—') + '<br><span style="color:var(--muted);">' + esc(r.upload_date||'') + '</span></td>' +
        '<td><span class="pill p-blue">' + esc(r.ledger_type||'—') + '</span></td>' +
        '<td>' + statusBadge(r.status) + '</td>' +
        '<td>' + typePill(r.claim_type) + '</td>' +
        '<td style="font-family:monospace;font-size:12px;font-weight:700;color:#312e81;">' + esc(r.tax_invoice_no) + '</td>' +
        '<td style="font-size:11.5px;">' + esc(r.invoice_date||'—') + '</td>' +
        '<td style="font-size:11.5px;">' + esc(r.banking_date||'—') + '</td>' +
        '<td><span class="pill p-gray">' + esc(r.entity||'—') + '</span></td>' +
        '<td style="font-size:11px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc(r.claim_description||'') + '">' + esc((r.claim_description||'').slice(0,50)) + '</td>' +
        '<td class="tr" style="font-weight:700;">' + fmtN(r.actual_amount) + '</td>' +
        '<td class="tr" style="color:var(--muted);">' + fmtN(r.vat_amount) + '</td>' +
        '<td class="tr" style="font-weight:800;color:#312e81;">' + fmtN(r.total_amount) + '</td>' +
        '<td style="font-size:10.5px;color:var(--muted);">' + esc(r.imported_at||'—') + '</td>' +
        '</tr>';
    });
    document.getElementById('repBody').innerHTML = h;
  }catch(e){
    document.getElementById('repBody').innerHTML =
      '<tr><td colspan="15" style="padding:34px;text-align:center;color:#dc2626;">Failed to load report data.</td></tr>';
  }
}

/* debounce search */
document.getElementById('fSearch').addEventListener('input', function(){
  clearTimeout(_searchDebounce);
  _searchDebounce = setTimeout(()=>loadReport(1), 450);
});
['fCustomer','fEntity','fStatus','fClaimType','fDateField'].forEach(id=>{
  document.getElementById(id).addEventListener('change', ()=>loadReport(1));
});

/* ── Export to Excel (full filtered set, no pagination) ── */
async function exportReport(){
  showToast('Preparing export…');
  const params = getFilterParams();
  const qs = new URLSearchParams({ ajax_action: 'export_report', ...params });
  try{
    const res = await fetch('customer_claim_report.php?' + qs.toString());
    const d = await res.json();
    if(!d.success || !d.rows.length){ showToast('No data to export', 'err'); return; }

    const data = d.rows.map(r => ({
      'Customer Code': r.customer_code,
      'Customer Name': r.customer_name,
      'Upload File': r.file_name,
      'Upload Date': r.upload_date,
      'Ledger Type': r.ledger_type,
      'Status': r.status,
      'Claim Type': r.claim_type,
      'Tax Invoice No': r.tax_invoice_no,
      'Invoice Date': r.invoice_date,
      'Banking Date': r.banking_date,
      'Entity': r.entity,
      'Claim Description': r.claim_description,
      'Actual Amount': parseFloat(r.actual_amount || 0),
      'VAT Amount': parseFloat(r.vat_amount || 0),
      'Total Amount': parseFloat(r.total_amount || 0),
      'Imported At': r.imported_at,
      'Imported By': r.imported_by,
    }));
    const ws = XLSX.utils.json_to_sheet(data);
    ws['!cols'] = [
      {wch:14},{wch:22},{wch:24},{wch:12},{wch:14},{wch:12},{wch:16},
      {wch:18},{wch:12},{wch:12},{wch:10},{wch:34},{wch:13},{wch:11},{wch:13},{wch:18},{wch:14}
    ];
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'CC Report');
    const stamp = new Date().toISOString().slice(0,10);
    XLSX.writeFile(wb, 'CC_Report_' + stamp + '.xlsx');
    showToast(d.rows.length + ' record(s) exported');
  }catch(e){
    showToast('Export failed: ' + e.message, 'err');
  }
}

/* init */
loadFilterOptions();
loadReport(1);
</script>
<?php include 'footer.php'; ?>