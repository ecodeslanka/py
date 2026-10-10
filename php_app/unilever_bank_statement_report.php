<?php
/**
 * unilever_bank_statement_report.php
 *
 * Full report of ALL bank statement transactions whose description matches
 * the known Unilever description sets (kept in sync with unilever_reconcile.php),
 * tallied against reconciled vs. not-reconciled vs. partially-reconciled,
 * with filters. Uses GET so the filtered/paginated view survives a page
 * refresh or being bookmarked/shared (the whole state lives in the URL).
 */
if (session_status() === PHP_SESSION_NONE) session_start();
include_once 'config.php';

/* ══════════════════════════════════════════════════════════
   SHARED: Known Unilever bank-statement description sets
   (keep this list identical to $UNILEVER_DESC_SETS in
   unilever_reconcile.php)
══════════════════════════════════════════════════════════ */
$UNILEVER_DESC_SETS = [
    'UNILEVER LANKA CONSUMER LIMITED',
    'UNILEVER LANKA CONSUMER LIMITED ULCL',
    'UNILEVER SRI LANKA LTD',
    'UNILEVER LANKA CONSUMER LTD',
    'UNILEVER LIPTON CEYLON LIMITED',
];
$UNILEVER_DESC_SETS = array_values(array_unique($UNILEVER_DESC_SETS));

function unilever_desc_where($conn, $sets, $col = 't.description') {
    $conditions = [];
    foreach ($sets as $pat) {
        $pat_esc = mysqli_real_escape_string($conn, $pat);
        $conditions[] = "UPPER($col) LIKE '%$pat_esc%'";
    }
    return $conditions ? implode(' OR ', $conditions) : '1=0';
}

/* ══════════════════════════════════════════════════════════
   AJAX: accounts list (same shape as main reconcile page)
══════════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'accounts') {
    header('Content-Type: application/json');
    ob_start();
    $q = mysqli_query($conn,
        "SELECT cba.id, cba.account_no, cba.account_name, cba.account_type,
                b.bank_name, c.company_name, c.company_code
         FROM company_bank_accounts cba
         LEFT JOIN banks b ON cba.bank_code = b.bank_code
         LEFT JOIN companies c ON cba.company_id = c.id
         WHERE cba.active = 1
         ORDER BY b.bank_name, cba.account_name");
    $rows = [];
    while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;
    ob_end_clean();
    echo json_encode(['ok'=>true,'accounts'=>$rows]);
    exit;
}

/* ══════════════════════════════════════════════════════════
   Read filters from $_GET (GET-based so state survives refresh)
══════════════════════════════════════════════════════════ */
$f_account = intval($_GET['account_id'] ?? 0);
$f_status  = in_array($_GET['status'] ?? 'all', ['all','reconciled','unreconciled','partial'], true) ? $_GET['status'] : 'all';
$f_search  = trim($_GET['search'] ?? '');
$f_from    = trim($_GET['date_from'] ?? '');
$f_to      = trim($_GET['date_to'] ?? '');
$f_page    = max(1, intval($_GET['page'] ?? 1));
$per_page  = 50;
$offset    = ($f_page - 1) * $per_page;

function build_report_where($conn, $UNILEVER_DESC_SETS, $f_account, $f_status, $f_search, $f_from, $f_to) {
    $desc_where = unilever_desc_where($conn, $UNILEVER_DESC_SETS, 't.description');
    $where = "($desc_where)";

    if ($f_account > 0) {
        $where .= " AND u.account_id = " . intval($f_account);
    }
    if ($f_status === 'reconciled') {
        $where .= " AND t.reconciled = 1";
    } elseif ($f_status === 'unreconciled') {
        $where .= " AND t.reconciled = 0 AND COALESCE(t.reconciled_amount,0) = 0";
    } elseif ($f_status === 'partial') {
        $where .= " AND t.reconciled = 0 AND COALESCE(t.reconciled_amount,0) > 0";
    }
    if ($f_search !== '') {
        $s = mysqli_real_escape_string($conn, $f_search);
        $where .= " AND (t.description LIKE '%$s%' OR t.reference LIKE '%$s%' OR t.cheque_no LIKE '%$s%')";
    }
    if ($f_from !== '') {
        $d = mysqli_real_escape_string($conn, $f_from);
        $where .= " AND t.transaction_date >= '$d'";
    }
    if ($f_to !== '') {
        $d = mysqli_real_escape_string($conn, $f_to);
        $where .= " AND t.transaction_date <= '$d'";
    }
    return $where;
}

/* ══════════════════════════════════════════════════════════
   AJAX: report — returns summary + one page of rows as JSON
══════════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'report') {
    header('Content-Type: application/json');
    ob_start();

    $where = build_report_where($conn, $UNILEVER_DESC_SETS, $f_account, $f_status, $f_search, $f_from, $f_to);

    /* --- Summary (over the FULL filtered set, not just this page) --- */
    $sumq = mysqli_query($conn,
        "SELECT
            COUNT(*) AS total_count,
            SUM(COALESCE(t.credit,0)) AS total_credit,
            SUM(COALESCE(t.debit,0))  AS total_debit,
            SUM(CASE WHEN t.reconciled=1 THEN 1 ELSE 0 END) AS reconciled_count,
            SUM(CASE WHEN t.reconciled=1 THEN COALESCE(t.reconciled_amount,0) ELSE 0 END) AS reconciled_amt,
            SUM(CASE WHEN t.reconciled=0 AND COALESCE(t.reconciled_amount,0)=0 THEN 1 ELSE 0 END) AS unreconciled_count,
            SUM(CASE WHEN t.reconciled=0 AND COALESCE(t.reconciled_amount,0)=0
                     THEN (CASE WHEN t.credit>0 THEN t.credit ELSE t.debit END) ELSE 0 END) AS unreconciled_amt,
            SUM(CASE WHEN t.reconciled=0 AND COALESCE(t.reconciled_amount,0)>0 THEN 1 ELSE 0 END) AS partial_count,
            SUM(CASE WHEN t.reconciled=0 AND COALESCE(t.reconciled_amount,0)>0 THEN COALESCE(t.reconciled_amount,0) ELSE 0 END) AS partial_reconciled_amt
         FROM bank_statement_transactions t
         JOIN bank_statement_uploads u ON t.upload_id = u.id
         WHERE $where");
    $summary = mysqli_fetch_assoc($sumq) ?: [];

    /* --- Total row count for pagination --- */
    $total_rows = (int)($summary['total_count'] ?? 0);
    $total_pages = max(1, (int)ceil($total_rows / $per_page));

    /* --- Page of rows, with matched claim info from the reconcile log --- */
    $q = mysqli_query($conn,
        "SELECT t.id, t.transaction_date, t.description, t.reference, t.cheque_no,
                t.debit, t.credit, t.balance,
                t.reconciled, t.reconciled_at, t.reconciled_amount, t.reconcile_balance,
                t.reconciled_description,
                cba.account_name, cba.account_no, b.bank_name,
                GROUP_CONCAT(DISTINCT CONCAT(cl.entity, ' (', DATE_FORMAT(cl.banking_date,'%d-%b-%Y'), ')') SEPARATOR '; ') AS matched_claims,
                COUNT(DISTINCT l.id) AS recon_log_count,
                MAX(l.is_ai_combo) AS has_ai_combo
         FROM bank_statement_transactions t
         JOIN bank_statement_uploads u ON t.upload_id = u.id
         LEFT JOIN company_bank_accounts cba ON u.account_id = cba.id
         LEFT JOIN banks b ON cba.bank_code = b.bank_code
         LEFT JOIN unilever_reconcile_log l ON l.bank_txn_id = t.id
         LEFT JOIN unilever_reconcile_claim_lines cl ON cl.log_id = l.id
         WHERE $where
         GROUP BY t.id
         ORDER BY t.transaction_date DESC, t.id DESC
         LIMIT $per_page OFFSET $offset");
    $rows = [];
    while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;

    ob_end_clean();
    echo json_encode([
        'ok'          => true,
        'summary'     => $summary,
        'rows'        => $rows,
        'page'        => $f_page,
        'per_page'    => $per_page,
        'total_rows'  => $total_rows,
        'total_pages' => $total_pages,
    ]);
    exit;
}

include 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Sora:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#f0f2f5;--surface:#ffffff;--surface2:#f7f8fb;--border:#e4e8ef;--border2:#d0d6e2;
  --ink:#0d1117;--ink3:#4a5568;--muted:#8492a6;--blue:#1a56db;--green:#0d7a4e;
  --amber:#b45309;--red:#b91c1c;--violet:#6d28d9;
  --font:'Sora',system-ui,sans-serif;--mono:'DM Mono','Fira Mono',monospace;
  --radius:12px;--shadow:0 1px 4px rgba(0,0,0,.06),0 4px 20px rgba(0,0,0,.06);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--font);background:var(--bg);color:var(--ink);}

.rpt-wrap{max-width:1600px;margin:0 auto;padding:24px 20px 80px;}

.page-head{background:linear-gradient(120deg,#0b1d3a 0%,#132d5e 55%,#1a3f80 100%);border-radius:16px;padding:22px 28px;display:flex;align-items:center;gap:18px;margin-bottom:22px;position:relative;overflow:hidden;}
.ph-icon{width:54px;height:54px;border-radius:14px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:24px;color:#fff;flex-shrink:0;}
.ph-title{color:#fff;font-size:21px;font-weight:900;line-height:1.2;}
.ph-sub{color:rgba(255,255,255,.58);font-size:12px;margin-top:4px;}
.ph-link{margin-left:auto;color:#fff;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.25);border-radius:9px;padding:9px 16px;font-size:12px;font-weight:700;text-decoration:none;white-space:nowrap;}
.ph-link:hover{background:rgba(255,255,255,.24);}

.filter-card{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius);padding:18px 22px;margin-bottom:18px;box-shadow:var(--shadow);}
.filter-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;align-items:end;}
@media(max-width:1200px){.filter-grid{grid-template-columns:repeat(3,1fr);}}
@media(max-width:640px){.filter-grid{grid-template-columns:repeat(2,1fr);}}
.f-field{display:flex;flex-direction:column;gap:5px;}
.f-label{font-size:10.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;}
.f-input,.f-select{padding:9px 12px;border:1.5px solid var(--border2);border-radius:8px;font-family:var(--font);font-size:12.5px;color:var(--ink);background:#fff;outline:none;}
.f-input:focus,.f-select:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(26,86,219,.12);}
.f-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:9px 18px;border-radius:8px;border:none;background:linear-gradient(135deg,#1a56db,#2563eb);color:#fff;font-family:var(--font);font-size:12.5px;font-weight:800;cursor:pointer;white-space:nowrap;}
.f-btn:hover{filter:brightness(1.08);}
.f-btn-reset{background:#fff;color:var(--ink3);border:1.5px solid var(--border2);}
.f-btn-reset:hover{background:var(--surface2);}
.status-chips{display:flex;gap:6px;flex-wrap:wrap;}
.status-chip{display:inline-flex;align-items:center;gap:5px;padding:7px 13px;border-radius:8px;border:1.5px solid var(--border2);background:#fff;font-size:11.5px;font-weight:700;color:var(--ink3);text-decoration:none;white-space:nowrap;}
.status-chip:hover{border-color:var(--blue);color:var(--blue);}
.status-chip.active{background:var(--blue);border-color:var(--blue);color:#fff;}
.status-chip.active.sc-reconciled{background:#059669;border-color:#059669;}
.status-chip.active.sc-unreconciled{background:var(--red);border-color:var(--red);}
.status-chip.active.sc-partial{background:#d97706;border-color:#d97706;}

.kpi-row{display:grid;grid-template-columns:repeat(7,1fr);gap:12px;margin-bottom:18px;}
@media(max-width:1200px){.kpi-row{grid-template-columns:repeat(4,1fr);}}
@media(max-width:560px){.kpi-row{grid-template-columns:repeat(2,1fr);}}
.kpi-box{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius);padding:14px 16px;box-shadow:var(--shadow);position:relative;overflow:hidden;}
.kpi-box::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;}
.kpi-box.k-blue::before{background:var(--blue);}
.kpi-box.k-green::before{background:var(--green);}
.kpi-box.k-red::before{background:var(--red);}
.kpi-box.k-amber::before{background:var(--amber);}
.kpi-box.k-violet::before{background:var(--violet);}
.kpi-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:6px;}
.kpi-val{font-size:22px;font-weight:900;color:var(--ink);line-height:1;}
.kpi-val.green{color:var(--green);}
.kpi-val.red{color:var(--red);}
.kpi-val.amber{color:var(--amber);}
.kpi-val.blue{color:var(--blue);}
.kpi-val.violet{color:var(--violet);}
.kpi-sub{font-size:10.5px;color:var(--muted);margin-top:4px;font-weight:600;}

.rpt-table-wrap{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;}
.rtw-header{padding:14px 18px;border-bottom:1.5px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;}
.rtw-title{font-size:14px;font-weight:800;color:var(--ink);}
.rtw-scroll{overflow-x:auto;max-height:70vh;overflow-y:auto;}
.rpt-tbl{width:100%;border-collapse:collapse;font-size:12px;min-width:1300px;}
.rpt-tbl thead th{padding:10px 12px;text-align:left;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;background:#0b2045;color:#a5c8ff;position:sticky;top:0;z-index:5;}
.rpt-tbl thead th.tr{text-align:right;}
.rpt-tbl thead th.tc{text-align:center;}
.rpt-tbl tbody tr{border-bottom:1px solid #f1f5f9;}
.rpt-tbl tbody td{padding:9px 12px;vertical-align:middle;}
.row-reconciled td{background:#f0fdf6;}
.row-partial td{background:#fffbeb;}
.row-unreconciled td{background:#fef2f2;}

.status-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:800;white-space:nowrap;border:1.5px solid;}
.sb-reconciled{background:#d1fae5;color:#065f46;border-color:#6ee7b7;}
.sb-partial{background:#fef3c7;color:#92400e;border-color:#fcd34d;}
.sb-unreconciled{background:#fee2e2;color:#991b1b;border-color:#fca5a5;}

.amt{font-family:var(--mono);font-weight:700;white-space:nowrap;}
.amt-pos{color:#065f46;}
.amt-neg{color:var(--red);}
.chip{display:inline-flex;align-items:center;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:700;white-space:nowrap;}
.chip-ref{background:#f0f9ff;color:#075985;font-family:var(--mono);}
.chip-ai{background:#ede9fe;color:#4c1d95;}
.claims-cell{font-size:11px;color:var(--ink3);max-width:260px;}
.no-rows{padding:60px 30px;text-align:center;color:var(--muted);font-size:13px;font-style:italic;}

.pagination{display:flex;align-items:center;justify-content:center;gap:8px;padding:16px;border-top:1.5px solid var(--border);flex-wrap:wrap;}
.pg-link{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;padding:0 10px;border-radius:8px;border:1.5px solid var(--border2);background:#fff;color:var(--ink3);font-size:12px;font-weight:700;text-decoration:none;}
.pg-link:hover{border-color:var(--blue);color:var(--blue);}
.pg-link.active{background:var(--blue);border-color:var(--blue);color:#fff;}
.pg-link.disabled{opacity:.4;pointer-events:none;}
.pg-info{font-size:11.5px;color:var(--muted);font-weight:600;margin:0 8px;}
</style>

<div class="rpt-wrap">
  <div class="page-head">
    <div class="ph-icon"><i class="fa-solid fa-file-invoice-dollar"></i></div>
    <div>
      <div class="ph-title">Unilever Bank Statement Report</div>
      <div class="ph-sub">All bank statement rows matching the Unilever description sets — tallied against Reconciled / Partial / Not Reconciled</div>
    </div>
    <a class="ph-link" href="unilever_reconcile.php"><i class="fa-solid fa-scale-balanced"></i> Go to Reconcile</a>
  </div>

  <form class="filter-card" method="GET" id="filterForm">
    <div class="filter-grid">
      <div class="f-field">
        <span class="f-label"><i class="fa-solid fa-building-columns"></i> Bank Account</span>
        <select name="account_id" class="f-select" id="accountSelect">
          <option value="0">— All Accounts —</option>
        </select>
      </div>
      <div class="f-field">
        <span class="f-label"><i class="fa-solid fa-calendar"></i> From Date</span>
        <input type="date" class="f-input" name="date_from" value="<?= htmlspecialchars($f_from) ?>">
      </div>
      <div class="f-field">
        <span class="f-label"><i class="fa-solid fa-calendar"></i> To Date</span>
        <input type="date" class="f-input" name="date_to" value="<?= htmlspecialchars($f_to) ?>">
      </div>
      <div class="f-field" style="grid-column:span 2;">
        <span class="f-label"><i class="fa-solid fa-magnifying-glass"></i> Search (description / reference)</span>
        <input type="text" class="f-input" name="search" value="<?= htmlspecialchars($f_search) ?>" placeholder="e.g. cheque no, reference…">
      </div>
      <div class="f-field">
        <span class="f-label">&nbsp;</span>
        <div style="display:flex;gap:8px;">
          <button type="submit" class="f-btn"><i class="fa-solid fa-filter"></i> Apply</button>
          <a class="f-btn f-btn-reset" href="unilever_bank_statement_report.php" style="text-decoration:none;"><i class="fa-solid fa-rotate-left"></i></a>
        </div>
      </div>
    </div>
    <input type="hidden" name="status" id="statusField" value="<?= htmlspecialchars($f_status) ?>">
    <div style="margin-top:14px;" class="status-chips">
      <a href="#" class="status-chip <?= $f_status==='all'?'active':'' ?>" onclick="return setStatus('all')"><i class="fa-solid fa-list"></i> All</a>
      <a href="#" class="status-chip sc-reconciled <?= $f_status==='reconciled'?'active':'' ?>" onclick="return setStatus('reconciled')"><i class="fa-solid fa-circle-check"></i> Reconciled</a>
      <a href="#" class="status-chip sc-partial <?= $f_status==='partial'?'active':'' ?>" onclick="return setStatus('partial')"><i class="fa-solid fa-circle-half-stroke"></i> Partially Reconciled</a>
      <a href="#" class="status-chip sc-unreconciled <?= $f_status==='unreconciled'?'active':'' ?>" onclick="return setStatus('unreconciled')"><i class="fa-solid fa-circle-xmark"></i> Not Reconciled</a>
    </div>
  </form>

  <div class="kpi-row" id="kpiRow">
    <div class="kpi-box k-blue"><div class="kpi-lbl">Total Transactions</div><div class="kpi-val blue" id="kTotalCount">—</div></div>
    <div class="kpi-box k-green"><div class="kpi-lbl">Reconciled</div><div class="kpi-val green" id="kReconCount">—</div><div class="kpi-sub" id="kReconAmt">—</div></div>
    <div class="kpi-box k-amber"><div class="kpi-lbl">Partially Reconciled</div><div class="kpi-val amber" id="kPartialCount">—</div><div class="kpi-sub" id="kPartialAmt">—</div></div>
    <div class="kpi-box k-red"><div class="kpi-lbl">Not Reconciled</div><div class="kpi-val red" id="kUnreconCount">—</div><div class="kpi-sub" id="kUnreconAmt">—</div></div>
    <div class="kpi-box k-violet"><div class="kpi-lbl">Total Credit</div><div class="kpi-val violet" id="kTotalCredit" style="font-size:15px;">—</div></div>
    <div class="kpi-box k-violet"><div class="kpi-lbl">Total Debit</div><div class="kpi-val violet" id="kTotalDebit" style="font-size:15px;">—</div></div>
    <div class="kpi-box k-blue"><div class="kpi-lbl">Reconciled %</div><div class="kpi-val blue" id="kReconPct">—</div></div>
  </div>

  <div class="rpt-table-wrap">
    <div class="rtw-header">
      <div class="rtw-title"><i class="fa-solid fa-table-list"></i> Bank Statement Transactions <span id="rowCountLabel" style="font-size:11px;font-weight:600;color:var(--muted);"></span></div>
    </div>
    <div class="rtw-scroll">
      <table class="rpt-tbl">
        <thead>
          <tr>
            <th>Date</th>
            <th>Account</th>
            <th>Description</th>
            <th>Reference</th>
            <th class="tr">Debit</th>
            <th class="tr">Credit</th>
            <th class="tr">Balance</th>
            <th class="tc">Status</th>
            <th class="tr">Reconciled Amt</th>
            <th class="tr">Remaining</th>
            <th>Matched Claim(s)</th>
          </tr>
        </thead>
        <tbody id="reportBody">
          <tr><td colspan="11"><div class="no-rows"><span class="spinner-placeholder"></span> Loading…</div></td></tr>
        </tbody>
      </table>
    </div>
    <div class="pagination" id="pagination"></div>
  </div>
</div>

<script>
const _page = 'unilever_bank_statement_report.php';
const _params = new URLSearchParams(window.location.search);

function esc(s){if(s==null)return '';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
function fmtN(v,dp){return parseFloat(v||0).toLocaleString('en-LK',{minimumFractionDigits:dp??2,maximumFractionDigits:dp??2});}
function fmtDate(d){if(!d)return '—';try{const dt=new Date(d);if(isNaN(dt))return d;return dt.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});}catch(e){return d;}}
function amtCell(v,cls){const f=parseFloat(v||0);if(!f)return '<span style="color:#9ca3af;">—</span>';return '<span class="amt '+cls+'">LKR '+fmtN(f)+'</span>';}

function setStatus(s){
  document.getElementById('statusField').value=s;
  document.getElementById('filterForm').submit();
  return false;
}

async function loadAccounts(){
  try{
    const res=await fetch(_page+'?ajax=accounts');
    const data=await res.json();
    const sel=document.getElementById('accountSelect');
    const selectedId=_params.get('account_id')||'0';
    (data.accounts||[]).forEach(a=>{
      const o=document.createElement('option');
      o.value=a.id;
      o.textContent='['+(a.company_code||'')+'] '+(a.bank_name||'')+' — '+(a.account_name||'')+' ('+(a.account_no||'')+')';
      if(String(a.id)===selectedId) o.selected=true;
      sel.appendChild(o);
    });
  }catch(e){/* silent */}
}

function buildQuery(page){
  const p=new URLSearchParams(window.location.search);
  p.set('ajax','report');
  if(page) p.set('page',page); else p.set('page','1');
  return p.toString();
}

async function loadReport(page){
  const tbody=document.getElementById('reportBody');
  tbody.innerHTML='<tr><td colspan="11"><div class="no-rows">Loading…</div></td></tr>';
  try{
    const res=await fetch(_page+'?'+buildQuery(page));
    const data=await res.json();
    if(!data.ok) throw new Error(data.msg||'Failed to load report');
    renderSummary(data.summary, data.total_rows);
    renderRows(data.rows);
    renderPagination(data.page, data.total_pages, data.total_rows);
  }catch(e){
    tbody.innerHTML='<tr><td colspan="11"><div class="no-rows">Error: '+esc(e.message)+'</div></td></tr>';
  }
}

function renderSummary(s, totalRows){
  s=s||{};
  const total=parseInt(s.total_count||0);
  const reconCount=parseInt(s.reconciled_count||0);
  const partialCount=parseInt(s.partial_count||0);
  const unreconCount=parseInt(s.unreconciled_count||0);
  const reconAmt=parseFloat(s.reconciled_amt||0);
  const partialAmt=parseFloat(s.partial_reconciled_amt||0);
  const unreconAmt=parseFloat(s.unreconciled_amt||0);
  const totalCredit=parseFloat(s.total_credit||0);
  const totalDebit=parseFloat(s.total_debit||0);
  const pct=total>0?((reconCount/total)*100).toFixed(1):'0.0';

  document.getElementById('kTotalCount').textContent=total;
  document.getElementById('kReconCount').textContent=reconCount;
  document.getElementById('kReconAmt').textContent='LKR '+fmtN(reconAmt);
  document.getElementById('kPartialCount').textContent=partialCount;
  document.getElementById('kPartialAmt').textContent='LKR '+fmtN(partialAmt);
  document.getElementById('kUnreconCount').textContent=unreconCount;
  document.getElementById('kUnreconAmt').textContent='LKR '+fmtN(unreconAmt);
  document.getElementById('kTotalCredit').textContent='LKR '+fmtN(totalCredit);
  document.getElementById('kTotalDebit').textContent='LKR '+fmtN(totalDebit);
  document.getElementById('kReconPct').textContent=pct+'%';
  document.getElementById('rowCountLabel').textContent='('+totalRows+' matching rows)';
}

function renderRows(rows){
  const tbody=document.getElementById('reportBody');
  if(!rows || !rows.length){
    tbody.innerHTML='<tr><td colspan="11"><div class="no-rows">No bank statement rows match the current filters.</div></td></tr>';
    return;
  }
  let h='';
  rows.forEach(r=>{
    const isReconciled = parseInt(r.reconciled)===1;
    const reconAmt = parseFloat(r.reconciled_amount||0);
    const isPartial = !isReconciled && reconAmt>0;
    let rowClass='row-unreconciled', badge='<span class="status-badge sb-unreconciled"><i class="fa-solid fa-circle-xmark"></i> Not Reconciled</span>';
    if(isReconciled){ rowClass='row-reconciled'; badge='<span class="status-badge sb-reconciled"><i class="fa-solid fa-circle-check"></i> Reconciled</span>'; }
    else if(isPartial){ rowClass='row-partial'; badge='<span class="status-badge sb-partial"><i class="fa-solid fa-circle-half-stroke"></i> Partial</span>'; }

    const remaining = r.reconcile_balance!==null && r.reconcile_balance!==undefined
      ? parseFloat(r.reconcile_balance)
      : Math.max(0, (parseFloat(r.credit||0)>0?parseFloat(r.credit):parseFloat(r.debit||0)) - reconAmt);

    const acct = (r.account_name?esc(r.account_name):'—') + (r.account_no?' ('+esc(r.account_no)+')':'');
    const ref = r.reference || r.cheque_no || '—';
    const claims = r.matched_claims ? esc(r.matched_claims) : '<span style="color:#9ca3af;">—</span>';
    const aiTag = (parseInt(r.has_ai_combo||0)===1) ? ' <span class="chip chip-ai">AI Combo</span>' : '';

    h+='<tr class="'+rowClass+'">'+
      '<td style="font-family:var(--mono);font-size:11.5px;font-weight:700;white-space:nowrap;">'+esc(fmtDate(r.transaction_date))+'</td>'+
      '<td style="font-size:11px;max-width:160px;">'+acct+'</td>'+
      '<td style="font-size:11px;max-width:260px;">'+esc((r.description||'').slice(0,70))+'</td>'+
      '<td><span class="chip chip-ref">'+esc(String(ref).slice(0,24))+'</span></td>'+
      '<td style="text-align:right;">'+amtCell(r.debit,'amt-neg')+'</td>'+
      '<td style="text-align:right;">'+amtCell(r.credit,'amt-pos')+'</td>'+
      '<td style="text-align:right;">'+amtCell(r.balance,'')+'</td>'+
      '<td style="text-align:center;">'+badge+'</td>'+
      '<td style="text-align:right;">'+amtCell(reconAmt,'amt-pos')+'</td>'+
      '<td style="text-align:right;">'+(remaining>0?'<span class="amt" style="color:var(--amber);">LKR '+fmtN(remaining)+'</span>':'<span style="color:#9ca3af;">—</span>')+'</td>'+
      '<td class="claims-cell">'+claims+aiTag+'</td>'+
      '</tr>';
  });
  tbody.innerHTML=h;
}

function renderPagination(page, totalPages, totalRows){
  const el=document.getElementById('pagination');
  if(totalPages<=1){ el.innerHTML=''; return; }
  const p=new URLSearchParams(window.location.search);
  function link(n, label, disabled, active){
    p.set('page', n);
    return '<a class="pg-link'+(active?' active':'')+(disabled?' disabled':'')+'" href="?'+p.toString()+'">'+label+'</a>';
  }
  let h='';
  h+=link(Math.max(1,page-1), '&laquo;', page<=1, false);
  const start=Math.max(1,page-2), end=Math.min(totalPages,page+2);
  if(start>1) h+=link(1,'1',false,page===1)+ (start>2?'<span class="pg-info">…</span>':'');
  for(let i=start;i<=end;i++) h+=link(i,String(i),false,i===page);
  if(end<totalPages) h+=(end<totalPages-1?'<span class="pg-info">…</span>':'')+link(totalPages,String(totalPages),false,page===totalPages);
  h+=link(Math.min(totalPages,page+1), '&raquo;', page>=totalPages, false);
  h+='<span class="pg-info">Page '+page+' of '+totalPages+' · '+totalRows+' rows</span>';
  el.innerHTML=h;

  /* Make pagination links use AJAX instead of a full reload */
  el.querySelectorAll('a.pg-link:not(.disabled)').forEach(a=>{
    a.addEventListener('click', function(ev){
      ev.preventDefault();
      const u=new URL(this.href);
      const newPage=u.searchParams.get('page');
      history.replaceState(null,'', window.location.pathname+'?'+ (function(){const q=new URLSearchParams(window.location.search); q.set('page',newPage); return q.toString();})());
      loadReport(newPage);
    });
  });
}

loadAccounts();
loadReport(<?= (int)$f_page ?>);
</script>

<?php include 'footer.php'; ?>
