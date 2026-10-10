<?php
/**
 * view_saved_cash_reports.php
 * ─────────────────────────────────────────────────────────────────
 * Dedicated page to browse, view detail, export and manage all
 * saved Daily Cash Shortage Report snapshots.
 *
 * Powered by ECODES IT SOLUTIONS
 * ─────────────────────────────────────────────────────────────────
 */
session_start();
include 'config.php';
include 'header.php';

/* ensure table exists */
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `csr_saved_reports` (
        `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `report_name`   VARCHAR(255)  NOT NULL,
        `date_from`     DATE          NOT NULL,
        `date_to`       DATE          NOT NULL,
        `view_mode`     ENUM('repcode_wise','rep_wise') NOT NULL DEFAULT 'repcode_wise',
        `sr_code`       VARCHAR(60)   DEFAULT NULL,
        `total_coll`    DECIMAL(15,2) NOT NULL DEFAULT 0,
        `bank_deposit`  DECIMAL(15,2) NOT NULL DEFAULT 0,
        `bo_handover`   DECIMAL(15,2) NOT NULL DEFAULT 0,
        `excess_short`  DECIMAL(15,2) NOT NULL DEFAULT 0,
        `final_se`      DECIMAL(15,2) NOT NULL DEFAULT 0,
        `record_count`  INT UNSIGNED  NOT NULL DEFAULT 0,
        `report_data`   LONGTEXT      NOT NULL,
        `saved_by`      VARCHAR(100)  DEFAULT NULL,
        `notes`         VARCHAR(500)  DEFAULT NULL,
        `saved_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_saved_at`   (`saved_at`),
        INDEX `idx_date_range` (`date_from`,`date_to`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

/* summary counts */
$cnt_all  = 0; $cnt_rw = 0; $cnt_rc = 0;
$r = mysqli_query($conn,"SELECT COUNT(*) c FROM csr_saved_reports"); if($r) $cnt_all = mysqli_fetch_assoc($r)['c'];
$r = mysqli_query($conn,"SELECT COUNT(*) c FROM csr_saved_reports WHERE view_mode='rep_wise'"); if($r) $cnt_rw = mysqli_fetch_assoc($r)['c'];
$r = mysqli_query($conn,"SELECT COUNT(*) c FROM csr_saved_reports WHERE view_mode='repcode_wise'"); if($r) $cnt_rc = mysqli_fetch_assoc($r)['c'];
$r = mysqli_query($conn,"SELECT MAX(saved_at) mx FROM csr_saved_reports"); $last_saved = ($r && $row=mysqli_fetch_assoc($r)) ? $row['mx'] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Saved Cash Shortage Reports – SK Distributors</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap');

:root{
  --bg:#f0f2f5;
  --surface:#fff;
  --surface2:#f8fafc;
  --bdr:#e2e8f0;
  --bdr2:#cbd5e1;
  --tx:#0f172a;
  --txm:#475569;
  --txs:#94a3b8;
  --fn:'Inter',sans-serif;
  --mono:'JetBrains Mono',monospace;
  --r:10px;
  --sh:0 1px 3px rgba(0,0,0,.06),0 4px 16px rgba(0,0,0,.04);
  --sh2:0 8px 32px rgba(0,0,0,.12);

  /* brand palette */
  --navy:#1e3a5f;
  --navy2:#162d4a;
  --green:#15803d;
  --red:#dc2626;
  --blue:#1d4ed8;
  --purple:#7c3aed;
  --teal:#0369a1;
  --amber:#d97706;

  /* semantic */
  --excess:#16a34a;
  --short:#dc2626;
}

*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--fn);background:var(--bg);color:var(--tx);font-size:13px;min-height:100vh;}

/* ── Page shell ── */
.pg{padding:16px 16px 60px;max-width:1600px;margin:0 auto;}

/* ── Topbar ── */
.topbar{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:16px;}
.pg-head{display:flex;align-items:center;gap:12px;}
.pg-icon{width:44px;height:44px;background:linear-gradient(135deg,var(--navy),#2d5a92);border-radius:12px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px;flex-shrink:0;box-shadow:0 4px 12px rgba(30,58,95,.3);}
.pg-h1{font-size:20px;font-weight:800;letter-spacing:-.025em;color:var(--tx);}
.pg-h1 span{color:var(--red);}
.pg-sub{font-size:11px;color:var(--txs);margin-top:2px;}
.topbar-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}

/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:7px;font-size:12px;font-weight:700;font-family:var(--fn);cursor:pointer;border:none;transition:all .15s;white-space:nowrap;}
.btn-navy{background:var(--navy);color:#fff;box-shadow:0 2px 6px rgba(30,58,95,.25);}
.btn-navy:hover{background:var(--navy2);}
.btn-green{background:linear-gradient(135deg,#166534,#15803d);color:#fff;box-shadow:0 2px 6px rgba(22,101,52,.25);}
.btn-green:hover{background:linear-gradient(135deg,#14532d,#166534);}
.btn-ghost{background:#f1f5f9;color:var(--txm);border:1px solid var(--bdr2);}
.btn-ghost:hover{background:#e2e8f0;}
.btn-red{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.btn-red:hover{background:#fecaca;}
.btn-sm{padding:5px 10px;font-size:11px;}
.btn-xs{padding:3px 8px;font-size:10px;border-radius:5px;}
.btn-teal{background:linear-gradient(135deg,#075985,#0369a1);color:#fff;}
.btn-teal:hover{background:linear-gradient(135deg,#0c4a6e,#075985);}
.btn-purple{background:linear-gradient(135deg,#6d28d9,#7c3aed);color:#fff;}
.btn-purple:hover{background:linear-gradient(135deg,#5b21b6,#6d28d9);}

/* ── Stat Cards ── */
.stat-row{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px;}
.stat{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);padding:12px 14px;box-shadow:var(--sh);display:flex;align-items:center;gap:12px;cursor:pointer;transition:all .15s;}
.stat:hover{border-color:var(--bdr2);box-shadow:var(--sh2);transform:translateY(-1px);}
.stat-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;}
.si-navy{background:#eff6ff;color:var(--navy);}
.si-blue{background:#dbeafe;color:var(--blue);}
.si-green{background:#dcfce7;color:var(--green);}
.si-amber{background:#fef3c7;color:var(--amber);}
.stat-body{}
.stat-val{font-size:22px;font-weight:900;line-height:1;letter-spacing:-.02em;}
.stat-lbl{font-size:10px;font-weight:600;color:var(--txs);text-transform:uppercase;letter-spacing:.05em;margin-top:2px;}

/* ── Filter bar ── */
.fbar{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);padding:10px 14px;margin-bottom:12px;display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;box-shadow:var(--sh);}
.fg{display:flex;flex-direction:column;gap:3px;}
.fg label{font-size:10px;font-weight:700;color:var(--txs);text-transform:uppercase;letter-spacing:.06em;}
.fg input,.fg select{padding:7px 10px;border:1.5px solid var(--bdr);border-radius:6px;font-size:12px;font-family:var(--fn);color:var(--tx);background:#fff;min-width:140px;}
.fg input:focus,.fg select:focus{outline:none;border-color:var(--navy);}
.fg.grow{flex:1;min-width:200px;}
.fg.grow input{width:100%;}

/* ── Table card ── */
.tcard{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);overflow:hidden;box-shadow:var(--sh);}
.tcard-head{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid var(--bdr);background:linear-gradient(to right,#f8fafc,#fff);flex-wrap:wrap;gap:8px;}
.tcard-ttl{font-size:13px;font-weight:800;display:flex;align-items:center;gap:7px;}
.tcard-actions{display:flex;gap:6px;align-items:center;}
.tscroll{overflow-x:auto;}

/* ── Main list table ── */
table.srt{width:100%;border-collapse:collapse;font-size:12px;}
.srt thead tr{background:var(--navy);}
.srt thead th{padding:8px 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:rgba(255,255,255,.9);white-space:nowrap;text-align:left;border-right:1px solid rgba(255,255,255,.1);}
.srt thead th:last-child{border-right:none;}
.srt thead th.num{text-align:right;}
.srt tbody tr{border-bottom:1px solid var(--bdr);transition:background .1s;}
.srt tbody tr:hover{background:#f0f7ff;}
.srt tbody tr.stripe{background:#fafcfe;}
.srt tbody tr.stripe:hover{background:#f0f7ff;}
.srt tbody td{padding:8px 10px;vertical-align:middle;white-space:nowrap;}
.srt tbody td.num{text-align:right;font-family:var(--mono);font-size:11.5px;}
.srt tfoot tr{background:#0f172a;}
.srt tfoot td{padding:7px 10px;color:#e2e8f0;font-weight:800;font-size:11.5px;white-space:nowrap;font-family:var(--mono);}
.srt tfoot td.lbl{font-family:var(--fn);color:#94a3b8;font-size:11px;}

/* ── Cells ── */
.rep-name{font-weight:700;font-size:12.5px;color:var(--tx);max-width:240px;overflow:hidden;text-overflow:ellipsis;}
.rep-notes{font-size:10px;color:var(--txs);margin-top:2px;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.badge{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap;}
.bg-blue{background:#dbeafe;color:#1e40af;}
.bg-slate{background:#f1f5f9;color:#475569;}
.bg-green{background:#dcfce7;color:#166534;}
.bg-red{background:#fee2e2;color:#991b1b;}
.bg-purple{background:#ede9fe;color:#5b21b6;}
.bg-amber{background:#fef3c7;color:#92400e;}
.mono{font-family:var(--mono);font-size:11.5px;}
.se-ok{color:#16a34a;font-weight:700;}
.se-short{color:#dc2626;font-weight:700;}
.se-excess{color:#16a34a;font-weight:700;}

/* ── Empty ── */
.empty-state{text-align:center;padding:60px 20px;color:var(--txs);}
.empty-state .es-icon{font-size:48px;opacity:.18;display:block;margin-bottom:14px;}
.empty-state h3{font-size:16px;font-weight:700;color:var(--txm);margin-bottom:6px;}
.empty-state p{font-size:12px;}

/* ══════════════════════════════════════
   MODAL
══════════════════════════════════════ */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:8000;display:none;align-items:center;justify-content:center;backdrop-filter:blur(4px);padding:16px;}
.modal-overlay.open{display:flex;}
.modal-box{background:#fff;border-radius:14px;box-shadow:0 24px 80px rgba(0,0,0,.25);width:100%;overflow:hidden;animation:mIn .22s cubic-bezier(.34,1.4,.64,1);}
.modal-sm{max-width:480px;}
.modal-lg{max-width:1100px;max-height:90vh;display:flex;flex-direction:column;}
@keyframes mIn{from{transform:scale(.93) translateY(-8px);opacity:0;}to{transform:scale(1) translateY(0);opacity:1;}}
.mhead{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--bdr);flex-shrink:0;}
.mhead h3{font-size:14px;font-weight:800;display:flex;align-items:center;gap:8px;}
.mclose{background:none;border:none;font-size:18px;cursor:pointer;color:var(--txm);width:30px;height:30px;border-radius:6px;display:flex;align-items:center;justify-content:center;}
.mclose:hover{background:#f1f5f9;}
.mbody{padding:18px;overflow-y:auto;}
.mfoot{padding:12px 18px;border-top:1px solid var(--bdr);display:flex;justify-content:flex-end;gap:8px;flex-shrink:0;flex-wrap:wrap;}
.mfoot.split{justify-content:space-between;}

/* ── Detail modal specific ── */
.dm-meta{display:flex;flex-wrap:wrap;gap:8px;padding:10px 13px;background:#f0f4ff;border-radius:9px;border:1px solid #c7d7f5;margin-bottom:14px;}
.dm-meta span{font-size:11px;font-weight:600;color:#1e40af;display:inline-flex;align-items:center;gap:5px;}
.dm-summary{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-bottom:14px;}
.dms-card{background:var(--surface2);border:1px solid var(--bdr);border-radius:8px;padding:8px 10px;}
.dms-lbl{font-size:9px;font-weight:700;color:var(--txs);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;}
.dms-val{font-size:13px;font-weight:800;font-family:var(--mono);}
.dm-tscroll{overflow-x:auto;max-height:380px;overflow-y:auto;border-radius:8px;border:1px solid var(--bdr);}
table.dmt{width:100%;border-collapse:collapse;font-size:11px;}
.dmt thead{position:sticky;top:0;z-index:3;}
.dmt thead tr.g1 th{background:#1e3a5f;color:#fff;padding:5px 8px;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;text-align:center;white-space:nowrap;border-right:2px solid rgba(255,255,255,.15);}
.dmt thead tr.g1 th.tl{text-align:left;}
.dmt thead tr.g2 th{padding:3px 8px;font-size:9px;font-weight:700;text-transform:uppercase;color:rgba(255,255,255,.9);text-align:center;white-space:nowrap;border-right:1px solid rgba(255,255,255,.1);border-bottom:2px solid var(--bdr);}
.dmt thead tr.g2 th.tl{text-align:left;}
.h-coll{background:#14532d;}.h-dep{background:#1e4d8c;}.h-sht{background:#7f1d1d;}.h-tr{background:#0c4a6e;}.h-new{background:#312e81;}.h-pay{background:#5b21b6;}
.s-coll{background:#0f3d20;}.s-dep{background:#163a6e;}.s-sht{background:#6b1616;}.s-tr{background:#063b58;}.s-new{background:#26235a;}.s-pay{background:#4c1d95;}
.dmt tbody tr{border-bottom:1px solid #f1f5f9;}
.dmt tbody tr:nth-child(even){background:#fafbfc;}
.dmt tbody tr:hover{background:#eff6ff!important;}
.dmt tbody td{padding:4px 8px;white-space:nowrap;text-align:right;font-family:var(--mono);font-size:11px;}
.dmt tbody td.tl{text-align:left;font-family:var(--fn);font-weight:600;}
.dmt tfoot td{background:#0f172a;color:#e2e8f0;padding:5px 8px;font-weight:800;font-size:11px;text-align:right;border-top:2px solid #334155;font-family:var(--mono);}
.dmt tfoot td.tl{text-align:left;font-family:var(--fn);color:#94a3b8;}

/* ── Delete confirm modal ── */
.del-confirm-icon{text-align:center;font-size:40px;color:#dc2626;margin-bottom:12px;}
.del-confirm-title{text-align:center;font-size:15px;font-weight:800;margin-bottom:6px;}
.del-confirm-desc{text-align:center;font-size:12px;color:var(--txm);margin-bottom:4px;}
.del-rep-name{text-align:center;font-size:12px;font-weight:700;color:var(--navy);padding:8px 14px;background:#f0f4ff;border-radius:8px;border:1px solid #c7d7f5;margin-bottom:6px;}

/* ── Toast ── */
#toast{position:fixed;bottom:24px;right:24px;padding:10px 18px;border-radius:9px;font-size:12px;font-weight:700;z-index:9999;display:none;opacity:0;transition:opacity .3s;max-width:340px;}
.t-ok{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.t-err{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.t-info{background:#dbeafe;color:#1e40af;border:1px solid #93c5fd;}

/* ── Spinner ── */
#spinner{position:fixed;inset:0;background:rgba(255,255,255,.65);z-index:9500;display:none;align-items:center;justify-content:center;backdrop-filter:blur(2px);}
#spinner.on{display:flex;}
.spin-box{background:#fff;border-radius:12px;padding:18px 28px;box-shadow:0 8px 32px rgba(0,0,0,.18);display:flex;align-items:center;gap:12px;font-size:13px;font-weight:700;}

/* ── Loading skeleton ── */
.skeleton{background:linear-gradient(90deg,#f1f5f9 25%,#e2e8f0 50%,#f1f5f9 75%);background-size:200% 100%;animation:shimmer 1.4s infinite;border-radius:5px;height:13px;}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

/* ── Responsive ── */
@media(max-width:900px){.stat-row{grid-template-columns:repeat(2,1fr);}.dm-summary{grid-template-columns:repeat(2,1fr);}}
@media(max-width:600px){.stat-row{grid-template-columns:1fr;}.dm-summary{grid-template-columns:1fr;}}

/* ── Print ── */
@media print{
  body{background:#fff;}
  .no-print{display:none!important;}
  .tcard{box-shadow:none;border:1px solid #ddd;}
  table.srt thead tr{background:var(--navy)!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
}
</style>

<div class="pg">

<!-- TOPBAR -->
<div class="topbar">
  <div class="pg-head">
    <div class="pg-icon"><i class="fa-solid fa-folder-open"></i></div>
    <div>
      <div class="pg-h1">Saved <span>Cash Shortage</span> Reports</div>
      <div class="pg-sub">Browse, view, export and manage all saved Daily Cash Shortage snapshots &nbsp;·&nbsp; <i class="fa-solid fa-database" style="color:var(--navy);"></i> csr_saved_reports</div>
    </div>
  </div>
  <div class="topbar-actions no-print">
    <a href="daily_cash_shortage.php" class="btn btn-navy"><i class="fa-solid fa-arrow-left"></i> Back to Report</a>
    <button onclick="window.print()" class="btn btn-ghost"><i class="fa-solid fa-print"></i> Print</button>
    <button onclick="exportListExcel()" class="btn btn-green"><i class="fa-solid fa-file-excel"></i> Export List</button>
  </div>
</div>

<!-- STAT CARDS -->
<div class="stat-row no-print">
  <div class="stat" onclick="filterVM('')" title="Show all">
    <div class="stat-icon si-navy"><i class="fa-solid fa-folder-open"></i></div>
    <div class="stat-body">
      <div class="stat-val"><?php echo $cnt_all; ?></div>
      <div class="stat-lbl">Total Saved Reports</div>
    </div>
  </div>
  <div class="stat" onclick="filterVM('rep_wise')" title="Filter Rep-Wise">
    <div class="stat-icon si-blue"><i class="fa-solid fa-users"></i></div>
    <div class="stat-body">
      <div class="stat-val"><?php echo $cnt_rw; ?></div>
      <div class="stat-lbl">Rep-Wise Reports</div>
    </div>
  </div>
  <div class="stat" onclick="filterVM('repcode_wise')" title="Filter Rep Code Wise">
    <div class="stat-icon si-green"><i class="fa-solid fa-id-badge"></i></div>
    <div class="stat-body">
      <div class="stat-val"><?php echo $cnt_rc; ?></div>
      <div class="stat-lbl">Rep Code Wise Reports</div>
    </div>
  </div>
  <div class="stat">
    <div class="stat-icon si-amber"><i class="fa-regular fa-clock"></i></div>
    <div class="stat-body">
      <div class="stat-val" style="font-size:13px;font-weight:800;"><?php echo $last_saved ? date('d M Y', strtotime($last_saved)) : '—'; ?></div>
      <div class="stat-lbl">Last Saved</div>
    </div>
  </div>
</div>

<!-- FILTER BAR -->
<div class="fbar no-print">
  <div class="fg grow">
    <label><i class="fa-solid fa-magnifying-glass"></i> Search</label>
    <input type="text" id="fSearch" placeholder="Report name, rep code, saved by…" oninput="applyFilters()">
  </div>
  <div class="fg">
    <label><i class="fa-solid fa-calendar-from-portal"></i> Date From</label>
    <input type="date" id="fDateFrom" onchange="applyFilters()">
  </div>
  <div class="fg">
    <label><i class="fa-solid fa-calendar-to-portal"></i> Date To</label>
    <input type="date" id="fDateTo" onchange="applyFilters()">
  </div>
  <div class="fg">
    <label><i class="fa-solid fa-layer-group"></i> View Mode</label>
    <select id="fVM" onchange="applyFilters()">
      <option value="">— All Modes —</option>
      <option value="rep_wise">Rep-Wise</option>
      <option value="repcode_wise">Rep Code Wise</option>
    </select>
  </div>
  <div class="fg">
    <label><i class="fa-solid fa-sort"></i> Sort By</label>
    <select id="fSort" onchange="applyFilters()">
      <option value="saved_at_desc">Saved Date ↓</option>
      <option value="saved_at_asc">Saved Date ↑</option>
      <option value="name_asc">Name A→Z</option>
      <option value="name_desc">Name Z→A</option>
      <option value="total_coll_desc">Total Coll ↓</option>
    </select>
  </div>
  <button onclick="resetFilters()" class="btn btn-ghost"><i class="fa-solid fa-rotate-left"></i> Reset</button>
</div>

<!-- TABLE CARD -->
<div class="tcard">
  <div class="tcard-head">
    <div class="tcard-ttl">
      <i class="fa-solid fa-table" style="color:var(--navy);"></i>
      Saved Reports
      <span class="badge bg-blue" id="listCount">Loading…</span>
    </div>
    <div class="tcard-actions no-print">
      <button onclick="loadReports()" class="btn btn-ghost btn-sm"><i class="fa-solid fa-arrows-rotate"></i> Refresh</button>
    </div>
  </div>
  <div class="tscroll">
    <table class="srt" id="mainTbl">
      <thead>
        <tr>
          <th style="width:38px;">#</th>
          <th style="min-width:220px;">Report Name</th>
          <th style="min-width:110px;">Date Range</th>
          <th>View Mode</th>
          <th>Rep Filter</th>
          <th class="num" style="min-width:105px;">Total Coll</th>
          <th class="num" style="min-width:100px;">Bank Dep.</th>
          <th class="num" style="min-width:100px;">BO Handover</th>
          <th class="num" style="min-width:100px;">Ex/Short</th>
          <th class="num" style="min-width:100px;">Final</th>
          <th class="num" style="min-width:60px;">Recs</th>
          <th style="min-width:90px;">Saved By</th>
          <th style="min-width:110px;">Saved At</th>
          <th style="min-width:110px;">Actions</th>
        </tr>
      </thead>
      <tbody id="tblBody">
        <tr><td colspan="14" style="padding:40px;text-align:center;color:var(--txs);">
          <i class="fa-solid fa-spinner fa-spin" style="font-size:20px;opacity:.4;display:block;margin-bottom:8px;"></i>
          Loading saved reports…
        </td></tr>
      </tbody>
      <tfoot>
        <tr id="tblFoot" style="display:none;">
          <td class="lbl" colspan="5">GRAND TOTAL</td>
          <td id="ft_tc"></td><td id="ft_bd"></td><td id="ft_boh"></td>
          <td id="ft_es"></td><td id="ft_fse"></td>
          <td id="ft_rc"></td><td colspan="3" class="lbl"></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

</div><!-- /pg -->

<!-- ══════════════════════════════════════
     MODAL: VIEW REPORT DETAIL
══════════════════════════════════════ -->
<div class="modal-overlay" id="detailModal">
  <div class="modal-box modal-lg">
    <div class="mhead">
      <h3 id="dm_title"><i class="fa-solid fa-chart-line" style="color:var(--navy);"></i> Report Detail</h3>
      <button class="mclose" onclick="closeModal('detailModal')">✕</button>
    </div>
    <div class="mbody" id="dm_body">
      <div style="text-align:center;padding:40px;color:var(--txs);"><i class="fa-solid fa-spinner fa-spin" style="font-size:24px;"></i></div>
    </div>
    <div class="mfoot split">
      <div style="display:flex;gap:7px;">
        <button onclick="exportDetailExcel()" class="btn btn-green btn-sm"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
        <button onclick="printDetail()" class="btn btn-ghost btn-sm"><i class="fa-solid fa-print"></i> Print</button>
      </div>
      <button onclick="closeModal('detailModal')" class="btn btn-ghost btn-sm">Close</button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════
     MODAL: DELETE CONFIRM
══════════════════════════════════════ -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal-box modal-sm">
    <div class="mhead">
      <h3><i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;"></i> Confirm Delete</h3>
      <button class="mclose" onclick="closeModal('deleteModal')">✕</button>
    </div>
    <div class="mbody">
      <div class="del-confirm-icon"><i class="fa-solid fa-trash-can"></i></div>
      <div class="del-confirm-title">Delete This Report?</div>
      <div class="del-confirm-desc">This action cannot be undone. The snapshot will be permanently removed.</div>
      <br>
      <div class="del-rep-name" id="del_rep_name">—</div>
    </div>
    <div class="mfoot">
      <button onclick="closeModal('deleteModal')" class="btn btn-ghost">Cancel</button>
      <button onclick="confirmDelete()" class="btn btn-red" id="delConfirmBtn"><i class="fa-solid fa-trash"></i> Delete</button>
    </div>
  </div>
</div>

<!-- Spinner -->
<div id="spinner"><div class="spin-box"><i class="fa-solid fa-spinner fa-spin" style="font-size:18px;color:var(--purple);"></i> Processing…</div></div>

<!-- Toast -->
<div id="toast"></div>

<script>
/* ══════════════════════════════════════
   DATA STORE
══════════════════════════════════════ */
var _allReports   = [];      // raw from server
var _filtered     = [];      // after filter/sort
var _deleteId     = null;
var _deleteRow    = null;
var _currentData  = null;    // active detail report data
var _currentMeta  = null;

const API = 'save_cash_shortage_report.php';

/* ══════════════════════════════════════
   LOAD
══════════════════════════════════════ */
function loadReports(){
    document.getElementById('tblBody').innerHTML =
        '<tr><td colspan="14" style="padding:40px;text-align:center;color:var(--txs);">'+
        '<i class="fa-solid fa-spinner fa-spin" style="font-size:20px;opacity:.4;display:block;margin-bottom:8px;"></i>Loading…</td></tr>';

    fetch(API + '?action=list_reports&limit=200')
    .then(r => r.json())
    .then(res => {
        if(!res.success){ showToast('Failed to load: '+res.message,'err'); return; }
        _allReports = res.data || [];
        applyFilters();
    })
    .catch(e => showToast('Network error: '+e.message,'err'));
}

/* ══════════════════════════════════════
   FILTER + SORT
══════════════════════════════════════ */
function applyFilters(){
    var q    = document.getElementById('fSearch').value.toLowerCase().trim();
    var df   = document.getElementById('fDateFrom').value;
    var dt   = document.getElementById('fDateTo').value;
    var vm   = document.getElementById('fVM').value;
    var sort = document.getElementById('fSort').value;

    _filtered = _allReports.filter(function(r){
        if(q && !(r.report_name+r.sr_code+r.saved_by+r.date_from+r.date_to+r.notes).toLowerCase().includes(q)) return false;
        if(vm && r.view_mode !== vm) return false;
        if(df && r.date_from < df) return false;
        if(dt && r.date_to   > dt) return false;
        return true;
    });

    _filtered.sort(function(a,b){
        if(sort==='saved_at_asc')    return a.saved_at.localeCompare(b.saved_at);
        if(sort==='name_asc')        return a.report_name.localeCompare(b.report_name);
        if(sort==='name_desc')       return b.report_name.localeCompare(a.report_name);
        if(sort==='total_coll_desc') return parseFloat(b.total_coll)-parseFloat(a.total_coll);
        return b.saved_at.localeCompare(a.saved_at); // default: saved_at_desc
    });

    renderTable(_filtered);
}

function filterVM(vm){
    document.getElementById('fVM').value = vm;
    applyFilters();
}

function resetFilters(){
    document.getElementById('fSearch').value='';
    document.getElementById('fDateFrom').value='';
    document.getElementById('fDateTo').value='';
    document.getElementById('fVM').value='';
    document.getElementById('fSort').value='saved_at_desc';
    applyFilters();
}

/* ══════════════════════════════════════
   RENDER TABLE
══════════════════════════════════════ */
function renderTable(list){
    document.getElementById('listCount').textContent = list.length + ' report'+(list.length!==1?'s':'');
    if(!list.length){
        document.getElementById('tblBody').innerHTML =
            '<tr><td colspan="14"><div class="empty-state">'+
            '<i class="fa-solid fa-inbox es-icon"></i>'+
            '<h3>No Saved Reports Found</h3>'+
            '<p>Adjust your filters, or go to the Daily Cash Shortage page and save a report.</p>'+
            '</div></td></tr>';
        document.getElementById('tblFoot').style.display='none';
        return;
    }

    // Grand totals
    var gt={tc:0,bd:0,boh:0,es:0,fse:0,rc:0};
    list.forEach(function(r){
        gt.tc +=parseFloat(r.total_coll  ||0);
        gt.bd +=parseFloat(r.bank_deposit||0);
        gt.boh+=parseFloat(r.bo_handover ||0);
        gt.es +=parseFloat(r.excess_short||0);
        gt.fse+=parseFloat(r.final_se    ||0);
        gt.rc +=parseInt  (r.record_count||0);
    });

    var html='';
    list.forEach(function(r,i){
        var vm   = r.view_mode==='rep_wise'?'<span class="badge bg-blue"><i class="fa-solid fa-users"></i> Rep-Wise</span>':'<span class="badge bg-slate"><i class="fa-solid fa-id-badge"></i> Rep Code</span>';
        var sr   = r.sr_code  ? '<span class="badge bg-purple">'+escH(r.sr_code)+'</span>' : '<span class="badge bg-slate">All Reps</span>';
        var es   = parseFloat(r.excess_short||0);
        var fse  = parseFloat(r.final_se    ||0);
        var esCl = Math.abs(es)<0.005 ?'se-ok':( es<0?'se-excess':'se-short');
        var fseCl= Math.abs(fse)<0.005?'se-ok':(fse<0?'se-excess':'se-short');
        var esTxt= Math.abs(es)<0.005?'0.00 ✓':(es<0?'▲ '+f2(Math.abs(es)):'▼ '+f2(es));
        var fseTxt=Math.abs(fse)<0.005?'0.00 ✓':(fse<0?'▲ '+f2(Math.abs(fse)):'▼ '+f2(fse));

        html+='<tr class="'+(i%2?'stripe':'')+'" id="row_'+r.id+'">'
            +'<td style="color:var(--txs);font-size:10px;font-family:var(--mono);">'+r.id+'</td>'
            +'<td>'
            +  '<div class="rep-name">'+escH(r.report_name)+'</div>'
            +  (r.notes?'<div class="rep-notes"><i class="fa-solid fa-note-sticky" style="opacity:.5;"></i> '+escH(r.notes)+'</div>':'')
            +'</td>'
            +'<td><span class="mono" style="font-size:11px;">'+fmtDate(r.date_from)+' – '+fmtDate(r.date_to)+'</span></td>'
            +'<td>'+vm+'</td>'
            +'<td>'+sr+'</td>'
            +'<td class="num" style="color:var(--navy);font-weight:700;">'+f2(r.total_coll)+'</td>'
            +'<td class="num">'+f2(r.bank_deposit)+'</td>'
            +'<td class="num">'+f2(r.bo_handover)+'</td>'
            +'<td class="num"><span class="'+esCl+'">'+esTxt+'</span></td>'
            +'<td class="num"><span class="'+fseCl+'">'+fseTxt+'</span></td>'
            +'<td class="num" style="color:var(--txm);">'+r.record_count+'</td>'
            +'<td><span class="badge bg-slate"><i class="fa-solid fa-user"></i> '+escH(r.saved_by||'—')+'</span></td>'
            +'<td><span class="mono" style="font-size:10.5px;">'+r.saved_at.substring(0,16)+'</span></td>'
            +'<td>'
            +  '<div style="display:flex;gap:4px;">'
            +  '<button class="btn btn-navy btn-xs" onclick="viewReport('+r.id+')" title="View Detail"><i class="fa-solid fa-eye"></i></button>'
            +  '<button class="btn btn-green btn-xs" onclick="exportOneExcel('+r.id+')" title="Export Excel"><i class="fa-solid fa-file-excel"></i></button>'
            +  '<button class="btn btn-red btn-xs" onclick="askDelete('+r.id+',\''+escAttr(r.report_name)+'\')" title="Delete"><i class="fa-solid fa-trash"></i></button>'
            +  '</div>'
            +'</td>'
            +'</tr>';
    });
    document.getElementById('tblBody').innerHTML=html;

    // Footer totals
    document.getElementById('ft_tc').textContent  = f2(gt.tc);
    document.getElementById('ft_bd').textContent  = f2(gt.bd);
    document.getElementById('ft_boh').textContent = f2(gt.boh);
    document.getElementById('ft_es').textContent  = f2(Math.abs(gt.es));
    document.getElementById('ft_fse').textContent = f2(Math.abs(gt.fse));
    document.getElementById('ft_rc').textContent  = gt.rc;
    document.getElementById('tblFoot').style.display='';
}

/* ══════════════════════════════════════
   VIEW DETAIL MODAL
══════════════════════════════════════ */
function viewReport(id){
    document.getElementById('dm_title').innerHTML='<i class="fa-solid fa-chart-line" style="color:var(--navy);"></i> Loading…';
    document.getElementById('dm_body').innerHTML='<div style="text-align:center;padding:40px;color:var(--txs);"><i class="fa-solid fa-spinner fa-spin" style="font-size:24px;"></i><p style="margin-top:8px;font-size:12px;">Fetching report data…</p></div>';
    document.getElementById('detailModal').classList.add('open');

    fetch(API + '?action=get_report&id='+id)
    .then(r=>r.json())
    .then(res=>{
        if(!res.success){ document.getElementById('dm_body').innerHTML='<p style="color:var(--red);padding:20px;">Error: '+escH(res.message)+'</p>'; return; }
        var rep = res.data;
        _currentMeta = rep;
        try{
            var pd = JSON.parse(rep.report_data);
            _currentData = pd;
            renderDetailModal(rep, pd);
        } catch(e){
            document.getElementById('dm_body').innerHTML='<p style="color:var(--red);padding:20px;">Invalid report data.</p>';
        }
    })
    .catch(e=>{ document.getElementById('dm_body').innerHTML='<p style="color:var(--red);padding:20px;">Network error: '+e.message+'</p>'; });
}

function renderDetailModal(meta, pd){
    var rows  = pd.rows  || [];
    var grand = pd.grand || {};
    var vm    = meta.view_mode==='rep_wise'?'Rep-Wise (Grouped)':'Rep Code Wise';
    var sr    = meta.sr_code||'All Reps';

    document.getElementById('dm_title').innerHTML =
        '<i class="fa-solid fa-chart-line" style="color:var(--navy);"></i> '+escH(meta.report_name);

    // Summary cards
    var es  = parseFloat(grand.excess_short||0);
    var fse = parseFloat(grand.final_se||0);
    var esc = Math.abs(es)<0.005?'color:#16a34a':(es<0?'color:#16a34a':'color:#dc2626');
    var fsec= Math.abs(fse)<0.005?'color:#16a34a':(fse<0?'color:#16a34a':'color:#dc2626');

    var metaHtml =
        '<div class="dm-meta">'
        +'<span><i class="fa-solid fa-calendar-days"></i> '+fmtDate(meta.date_from)+' – '+fmtDate(meta.date_to)+'</span>'
        +'<span><i class="fa-solid fa-layer-group"></i> '+vm+'</span>'
        +'<span><i class="fa-solid fa-id-badge"></i> '+escH(sr)+'</span>'
        +'<span><i class="fa-solid fa-database"></i> '+rows.length+' records</span>'
        +'<span><i class="fa-regular fa-clock"></i> Saved: '+(meta.saved_at||'').substring(0,16)+'</span>'
        +(meta.saved_by?'<span><i class="fa-solid fa-user"></i> '+escH(meta.saved_by)+'</span>':'')
        +(meta.notes?'<span><i class="fa-solid fa-note-sticky"></i> '+escH(meta.notes)+'</span>':'')
        +'</div>';

    var sumHtml =
        '<div class="dm-summary">'
        +'<div class="dms-card"><div class="dms-lbl">Total Collections</div><div class="dms-val" style="color:var(--navy);">'+f2(grand.total_coll||0)+'</div></div>'
        +'<div class="dms-card"><div class="dms-lbl">Bank Deposit</div><div class="dms-val" style="color:var(--blue);">'+f2(grand.bank_deposit||0)+'</div></div>'
        +'<div class="dms-card"><div class="dms-lbl">BO Handover</div><div class="dms-val" style="color:var(--green);">'+f2(grand.bo_handover||0)+'</div></div>'
        +'<div class="dms-card"><div class="dms-lbl">Excess / Short</div><div class="dms-val" style="'+esc+';">'+(Math.abs(es)<0.005?'0.00 ✓':(es<0?'▲ ':' ▼ ')+f2(Math.abs(es)))+'</div></div>'
        +'<div class="dms-card"><div class="dms-lbl">Final (Ex/Short)</div><div class="dms-val" style="'+fsec+';">'+(Math.abs(fse)<0.005?'0.00 ✓':(fse<0?'▲ ':' ▼ ')+f2(Math.abs(fse)))+'</div></div>'
        +'</div>';

    // Data table
    var thead =
        '<tr class="g1">'
        +'<th class="tl" rowspan="2" style="position:sticky;left:0;z-index:5;background:#1e3a5f;min-width:85px;">Rep Code</th>'
        +'<th class="tl" rowspan="2" style="min-width:95px;">Del. Date</th>'
        +'<th class="h-coll" colspan="6">Cash Collections</th>'
        +'<th class="h-dep"  colspan="2">Deposits</th>'
        +'<th class="h-sht"  colspan="1">Short/Excess</th>'
        +'<th class="h-tr"   colspan="2">Cross Charge</th>'
        +'<th class="h-new"  colspan="1">Final</th>'
        +'<th class="h-pay"  colspan="2">Pay Alloc</th>'
        +'</tr>'
        +'<tr class="g2">'
        +'<th class="s-coll" style="min-width:88px;">Daily Sale</th>'
        +'<th class="s-coll" style="min-width:80px;">Rcvd Credit</th>'
        +'<th class="s-coll" style="min-width:75px;">RTN Chqs</th>'
        +'<th class="s-coll" style="min-width:75px;">RTN Chgs</th>'
        +'<th class="s-coll" style="min-width:85px;">Sent Back</th>'
        +'<th class="s-coll" style="min-width:90px;font-weight:800;">Total Coll</th>'
        +'<th class="s-dep"  style="min-width:88px;">Bank Dep</th>'
        +'<th class="s-dep"  style="min-width:88px;">BO Handover</th>'
        +'<th class="s-sht"  style="min-width:88px;">Ex/Short</th>'
        +'<th class="s-tr"   style="min-width:80px;">Out</th>'
        +'<th class="s-tr"   style="min-width:80px;">In</th>'
        +'<th class="s-new"  style="min-width:88px;">Final</th>'
        +'<th class="s-pay"  style="min-width:80px;">Emply Chg</th>'
        +'<th class="s-pay"  style="min-width:80px;">Chg to Com</th>'
        +'</tr>';

    var tbody='';
    rows.forEach(function(r,i){
        var exs=parseFloat(r.excess_short||0);
        var fse=parseFloat(r.final_se||0);
        tbody+='<tr>'
            +'<td class="tl" style="position:sticky;left:0;background:'+(i%2?'#fafbfc':'#fff')+';font-weight:700;">'+escH(r.sr_code||'')+'</td>'
            +'<td class="tl" style="font-size:10.5px;color:var(--txm);font-family:var(--mono);">'+fmtDate(r.del_date)+'</td>'
            +'<td>'+nv(r.daily_sale)+'</td>'
            +'<td>'+nv(r.rcvd_credit)+'</td>'
            +'<td>'+nv(r.rtn_chq)+'</td>'
            +'<td>'+nv(r.rtn_chgs)+'</td>'
            +'<td>'+nv(r.sent_back_chq)+'</td>'
            +'<td style="font-weight:800;color:var(--green);">'+nv(r.total_coll)+'</td>'
            +'<td>'+nv(r.bank_deposit)+'</td>'
            +'<td>'+nv(r.bo_handover)+'</td>'
            +'<td><span class="'+(Math.abs(exs)<0.005?'se-ok':(exs<0?'se-excess':'se-short'))+'">'+(Math.abs(exs)<0.005?'0.00 ✓':(exs<0?'▲ ':' ▼ ')+f2(Math.abs(exs)))+'</span></td>'
            +'<td><span style="color:#ef4444;font-weight:700;">'+nv(r.cross_out)+'</span></td>'
            +'<td><span style="color:#22c55e;font-weight:700;">'+nv(r.cross_in)+'</span></td>'
            +'<td><span class="'+(Math.abs(fse)<0.005?'se-ok':(fse<0?'se-excess':'se-short'))+'">'+(Math.abs(fse)<0.005?'0.00 ✓':(fse<0?'▲ ':' ▼ ')+f2(Math.abs(fse)))+'</span></td>'
            +'<td style="color:#dc2626;">'+nv(r.emply_chg)+'</td>'
            +'<td style="color:#0369a1;">'+nv(r.chg_to_com)+'</td>'
            +'</tr>';
    });

    var tfoot =
        '<tr>'
        +'<td class="tl" colspan="2">TOTAL — '+rows.length+' records</td>'
        +'<td>'+f2(grand.daily_sale||0)+'</td>'
        +'<td>'+f2(grand.rcvd_credit||0)+'</td>'
        +'<td>'+f2(grand.rtn_chq||0)+'</td>'
        +'<td>'+f2(grand.rtn_chgs||0)+'</td>'
        +'<td>'+f2(grand.sent_back_chq||0)+'</td>'
        +'<td>'+f2(grand.total_coll||0)+'</td>'
        +'<td>'+f2(grand.bank_deposit||0)+'</td>'
        +'<td>'+f2(grand.bo_handover||0)+'</td>'
        +'<td>'+f2(Math.abs(grand.excess_short||0))+'</td>'
        +'<td>'+f2(grand.cross_charge_out||0)+'</td>'
        +'<td>'+f2(grand.cross_charge_in||0)+'</td>'
        +'<td>'+f2(Math.abs(grand.final_se||0))+'</td>'
        +'<td>'+f2(grand.emply_chg||0)+'</td>'
        +'<td>'+f2(grand.chg_to_com||0)+'</td>'
        +'</tr>';

    document.getElementById('dm_body').innerHTML =
        metaHtml + sumHtml +
        '<div class="dm-tscroll">'
        +'<table class="dmt"><thead>'+thead+'</thead><tbody>'+tbody+'</tbody><tfoot>'+tfoot+'</tfoot></table>'
        +'</div>';
}

/* ══════════════════════════════════════
   DELETE
══════════════════════════════════════ */
function askDelete(id, name){
    _deleteId  = id;
    document.getElementById('del_rep_name').textContent = name;
    document.getElementById('deleteModal').classList.add('open');
}
function confirmDelete(){
    if(!_deleteId) return;
    document.getElementById('deleteModal').classList.remove('open');
    document.getElementById('spinner').classList.add('on');
    var fd=new FormData(); fd.append('action','delete_report'); fd.append('id',_deleteId);
    fetch(API, {method:'POST', body:fd})
    .then(r=>r.json())
    .then(res=>{
        document.getElementById('spinner').classList.remove('on');
        if(res.success){
            showToast('Report deleted.','info');
            _allReports = _allReports.filter(r=>r.id!=_deleteId);
            applyFilters();
        } else {
            showToast('Failed: '+res.message,'err');
        }
        _deleteId=null;
    })
    .catch(e=>{document.getElementById('spinner').classList.remove('on'); showToast('Network error.','err');});
}

/* ══════════════════════════════════════
   EXCEL EXPORTS
══════════════════════════════════════ */
function exportListExcel(){
    if(!_filtered.length){ showToast('No data to export.','err'); return; }
    var hdrs=['ID','Report Name','Date From','Date To','View Mode','Rep Filter','Total Coll','Bank Deposit','BO Handover','Excess/Short','Final','Records','Saved By','Saved At','Notes'];
    var data=_filtered.map(r=>[r.id,r.report_name,r.date_from,r.date_to,r.view_mode,r.sr_code||'All Reps',r.total_coll,r.bank_deposit,r.bo_handover,r.excess_short,r.final_se,r.record_count,r.saved_by||'',r.saved_at,r.notes||'']);
    var ws=XLSX.utils.aoa_to_sheet([hdrs].concat(data));
    var wb=XLSX.utils.book_new(); XLSX.utils.book_append_sheet(wb,ws,'Saved Reports');
    XLSX.writeFile(wb,'Saved_Cash_Shortage_Reports_'+today()+'.xlsx');
    showToast('List exported!','ok');
}

function exportDetailExcel(){
    if(!_currentData||!_currentMeta){ showToast('No detail data loaded.','err'); return; }
    var rows=_currentData.rows||[]; var grand=_currentData.grand||{};
    var hdrs=['Rep Code','Date','Daily Sale','Rcvd Credit','RTN Chqs','RTN Chgs','Sent Back','Total Coll','Bank Dep','BO Handover','Excess/Short','Cross Out','Cross In','Final','Emply Chg','Chg to Com'];
    var data=rows.map(r=>[r.sr_code,r.del_date,r.daily_sale,r.rcvd_credit,r.rtn_chq,r.rtn_chgs,r.sent_back_chq,r.total_coll,r.bank_deposit,r.bo_handover,r.excess_short,r.cross_out||0,r.cross_in||0,r.final_se,r.emply_chg||0,r.chg_to_com||0]);
    var totals=['TOTAL','',grand.daily_sale,grand.rcvd_credit,grand.rtn_chq,grand.rtn_chgs,grand.sent_back_chq,grand.total_coll,grand.bank_deposit,grand.bo_handover,grand.excess_short,grand.cross_charge_out||0,grand.cross_charge_in||0,grand.final_se,grand.emply_chg||0,grand.chg_to_com||0];
    var ws=XLSX.utils.aoa_to_sheet([hdrs].concat(data).concat([totals]));
    var wb=XLSX.utils.book_new(); XLSX.utils.book_append_sheet(wb,ws,'Report');
    XLSX.writeFile(wb,'Cash_Shortage_Report_'+_currentMeta.id+'_'+_currentMeta.date_from+'_'+_currentMeta.date_to+'.xlsx');
    showToast('Excel exported!','ok');
}

function exportOneExcel(id){
    showToast('Fetching report for export…','info');
    fetch(API+'?action=get_report&id='+id)
    .then(r=>r.json())
    .then(res=>{
        if(!res.success){ showToast('Failed: '+res.message,'err'); return; }
        _currentMeta = res.data;
        _currentData = JSON.parse(res.data.report_data);
        exportDetailExcel();
    })
    .catch(e=>showToast('Network error.','err'));
}

function printDetail(){
    var dm=document.getElementById('dm_body');
    var w=window.open('','_blank','width=1100,height=800');
    w.document.write('<html><head><title>'+escH(_currentMeta?.report_name||'Report')+'</title>');
    w.document.write('<style>body{font-family:Inter,sans-serif;font-size:10px;padding:12px;}table{border-collapse:collapse;width:100%;}th,td{border:1px solid #ddd;padding:3px 5px;font-size:9px;}th{background:#1e3a5f;color:#fff;}tfoot td{background:#0f172a;color:#e2e8f0;font-weight:700;}</style>');
    w.document.write('</head><body>');
    w.document.write('<h2 style="font-size:13px;margin-bottom:8px;">'+escH(_currentMeta?.report_name||'Cash Shortage Report')+'</h2>');
    w.document.write(dm.innerHTML);
    w.document.write('</body></html>');
    w.document.close();
    setTimeout(function(){ w.print(); },600);
}

/* ══════════════════════════════════════
   HELPERS
══════════════════════════════════════ */
function closeModal(id){ document.getElementById(id).classList.remove('open'); }
function f2(v){ return parseFloat(v||0).toFixed(2); }
function nv(v){ var n=parseFloat(v||0); return n==0?'<span style="color:#d1d5db;">—</span>':n.toFixed(2); }
function fmtDate(d){
    if(!d||d==='0000-00-00') return '—';
    var p=d.split('-'); if(p.length<3) return d;
    var m=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return p[2]+' '+m[parseInt(p[1],10)-1]+' '+p[0];
}
function today(){ var d=new Date(); return d.getFullYear()+'-'+(d.getMonth()+1).toString().padStart(2,'0')+'-'+d.getDate().toString().padStart(2,'0'); }
function escH(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function escAttr(s){ return escH(s).replace(/'/g,'&#39;'); }

function showToast(msg,type){
    var t=document.getElementById('toast');
    t.className=type==='ok'?'t-ok':(type==='info'?'t-info':'t-err');
    t.textContent=msg; t.style.display='block'; t.style.opacity='1';
    clearTimeout(t._timer);
    t._timer=setTimeout(()=>{ t.style.opacity='0'; setTimeout(()=>{ t.style.display='none'; },300); },2800);
}

/* Close modals on overlay click */
['detailModal','deleteModal'].forEach(id=>{
    document.getElementById(id).addEventListener('click',function(e){
        if(e.target===this) this.classList.remove('open');
    });
});

/* Boot */
loadReports();
</script>

<?php include 'footer.php'; ?>
