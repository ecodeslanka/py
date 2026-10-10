<?php
/**
 * cheque_recon_history.php
 * ──────────────────────────────────────────────────────────────────
 *  Upload History for Cheque Reconciliation
 *  • List all past uploads with summary stats (sorted by Statement Date)
 *  • Click Statement Date header to toggle newest / oldest first
 *  • Click a row to view full details (cleared, returned, not-found, already done)
 *  • Reverse Update button to undo a reconciliation batch
 *  • Not-found cheques from cheque_recon_not_found table
 * ──────────────────────────────────────────────────────────────────
 */
if (session_status() === PHP_SESSION_NONE) session_start();
function get_current_user_label_hist() {
    return $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['name'] ??
           $_SESSION['full_name'] ?? $_SESSION['email'] ??
           (isset($_SESSION['user_id']) ? 'User #' . $_SESSION['user_id'] : 'system');
}
include_once 'config.php';

function ensure_recon_tables_hist($conn) {
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_recon_uploads(id INT AUTO_INCREMENT PRIMARY KEY,file_name VARCHAR(255) NOT NULL,statement_date DATE DEFAULT NULL,total_rows INT DEFAULT 0,matched INT DEFAULT 0,cleared INT DEFAULT 0,returned INT DEFAULT 0,not_found INT DEFAULT 0,already_done INT DEFAULT 0,skipped INT DEFAULT 0,status VARCHAR(20) DEFAULT 'applied',reversed_at DATETIME DEFAULT NULL,reversed_by VARCHAR(100) DEFAULT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,created_by VARCHAR(100) DEFAULT 'system',INDEX idx_stmt_date(statement_date),INDEX idx_status(status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_recon_upload_items(id INT AUTO_INCREMENT PRIMARY KEY,upload_id INT NOT NULL,cheque_id INT DEFAULT NULL,stmt_cheque_no VARCHAR(100),db_cheque_no VARCHAR(100) DEFAULT NULL,stmt_amount DECIMAL(15,2) DEFAULT 0,db_amount DECIMAL(15,2) DEFAULT NULL,type VARCHAR(20) DEFAULT 'cleared',matched TINYINT(1) DEFAULT 0,already_updated TINYINT(1) DEFAULT 0,applied TINYINT(1) DEFAULT 0,old_status VARCHAR(50) DEFAULT NULL,new_status VARCHAR(50) DEFAULT NULL,bank_ref VARCHAR(100) DEFAULT NULL,tx_date VARCHAR(50) DEFAULT NULL,return_reason TEXT DEFAULT NULL,customer_name VARCHAR(255) DEFAULT NULL,t_code VARCHAR(100) DEFAULT NULL,amt_match TINYINT(1) DEFAULT 0,description TEXT DEFAULT NULL,deposit_date DATE DEFAULT NULL,account_name VARCHAR(255) DEFAULT NULL,INDEX idx_upload(upload_id),INDEX idx_cheque(cheque_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_recon_not_found(id INT AUTO_INCREMENT PRIMARY KEY,upload_id INT NOT NULL,stmt_cheque_no VARCHAR(100) NOT NULL,stmt_amount DECIMAL(15,2) DEFAULT 0,type VARCHAR(20) DEFAULT 'cleared',bank_ref VARCHAR(100) DEFAULT NULL,tx_date VARCHAR(50) DEFAULT NULL,description TEXT DEFAULT NULL,statement_date DATE DEFAULT NULL,file_name VARCHAR(255) DEFAULT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,created_by VARCHAR(100) DEFAULT 'system',INDEX idx_upload(upload_id),INDEX idx_chqno(stmt_cheque_no),INDEX idx_stmt_date(statement_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * SQL expression that turns statement_date into a real DATE,
 * whether the column is DATE or text (Y-m-d, d/m/Y, d-m-Y, d.m.Y).
 * Empty / zero / unparseable dates become NULL (sorted last).
 */
function stmt_date_sql_hist() {
    return "(CASE
              WHEN statement_date IS NULL OR statement_date = '' OR statement_date LIKE '0000%' THEN NULL
              WHEN statement_date REGEXP '^[0-9]{4}-[0-9]{1,2}-[0-9]{1,2}'
                   THEN STR_TO_DATE(SUBSTRING_INDEX(statement_date,' ',1),'%Y-%m-%d')
              WHEN statement_date REGEXP '^[0-9]{1,2}[-/.][0-9]{1,2}[-/.][0-9]{4}'
                   THEN STR_TO_DATE(REPLACE(REPLACE(SUBSTRING_INDEX(statement_date,' ',1),'.','/'),'-','/'),'%d/%m/%Y')
              ELSE NULL END)";
}

/** Parse '29 Sep 2026', '29 Sept 2026', '2026-09-29', '29/09/2026' → 'Y-m-d' or '' */
function parse_any_date_hist($s) {
    $s = trim((string)$s);
    if ($s === '' || strpos($s, '0000') === 0) return '';
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m))
        return checkdate($m[2], $m[3], $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : '';
    if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})/', $s, $m))
        return checkdate($m[2], $m[1], $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : '';
    if (preg_match('/^(\d{1,2})\s+([A-Za-z]{3,9})\.?,?\s+(\d{4})/', $s, $m)) {
        $mons = ['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12];
        $mo = $mons[strtolower(substr($m[2], 0, 3))] ?? 0;
        return ($mo && checkdate($mo, $m[1], $m[3])) ? sprintf('%04d-%02d-%02d', $m[3], $mo, $m[1]) : '';
    }
    return '';
}

/** All dates written as 'dd Mon yyyy' inside a file name, in order */
function dates_in_name_hist($name) {
    $out = [];
    if (preg_match_all('/\b\d{1,2}\s+[A-Za-z]{3,9}\.?\s+\d{4}\b/', (string)$name, $mm)) {
        foreach ($mm[0] as $d) { $iso = parse_any_date_hist($d); if ($iso) $out[] = $iso; }
    }
    return $out;
}

/**
 * The real statement date of an upload.
 *  • stored statement_date if it is valid and not in the future
 *  • otherwise the latest date in the file name that is not in the future
 *    (e.g. "29 Sep 2026 – 29 Oct 2026" uploaded on 05 Oct → 29 Sep 2026)
 *  • otherwise the upload date
 */
function effective_stmt_date_hist($row) {
    $today   = date('Y-m-d');
    $created = parse_any_date_hist($row['created_at'] ?? '');
    $limit   = $created ?: $today;   // a statement can't be dated after the day it was uploaded

    $stored = parse_any_date_hist($row['statement_date'] ?? '');
    if ($stored && $stored <= $limit) return [$stored, false];

    $cands = array_filter(dates_in_name_hist($row['file_name'] ?? ''), fn($d) => $d <= $limit);
    if ($cands) return [max($cands), true];

    return [$created ?: '', (bool)$stored];
}

function add_effective_date_hist(array $row) {
    [$iso, $fixed] = effective_stmt_date_hist($row);
    $row['stmt_date_iso']   = $iso;
    $row['stmt_date_fixed'] = $fixed ? 1 : 0;
    $row['stmt_date_raw']   = parse_any_date_hist($row['statement_date'] ?? '');
    return $row;
}

/* ── AJAX: get_upload_history ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_upload_history') {
    ob_start(); header('Content-Type: application/json');
    ensure_recon_tables_hist($conn);

    $r = mysqli_query($conn, "SELECT * FROM cheque_recon_uploads ORDER BY created_at DESC, id DESC LIMIT 300");
    $rows = [];
    if ($r) { while ($row = mysqli_fetch_assoc($r)) $rows[] = add_effective_date_hist($row); }

    // newest statement date first, no date last, same date → latest upload first
    usort($rows, function ($a, $b) {
        $ka = $a['stmt_date_iso']; $kb = $b['stmt_date_iso'];
        if ($ka === '' && $kb !== '') return 1;
        if ($ka !== '' && $kb === '') return -1;
        if ($ka !== $kb) return strcmp($kb, $ka);
        $c = strcmp((string)$b['created_at'], (string)$a['created_at']);
        return $c ?: ((int)$b['id'] - (int)$a['id']);
    });
    $rows = array_slice($rows, 0, 100);

    ob_end_clean();
    echo json_encode(['success' => true, 'rows' => $rows]);
    exit;
}

/* ── AJAX: get_upload_details ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_upload_details') {
    ob_start(); header('Content-Type: application/json');
    ensure_recon_tables_hist($conn);
    $uid = intval($_POST['upload_id'] ?? 0);
    if (!$uid) { ob_end_clean(); echo json_encode(['success'=>false,'error'=>'No upload_id']); exit; }

    $hr = mysqli_query($conn, "SELECT * FROM cheque_recon_uploads WHERE id=$uid LIMIT 1");
    $header = $hr ? mysqli_fetch_assoc($hr) : null;
    if (!$header) { ob_end_clean(); echo json_encode(['success'=>false,'error'=>'Upload not found']); exit; }
    $header = add_effective_date_hist($header);

    $r = mysqli_query($conn, "SELECT * FROM cheque_recon_upload_items WHERE upload_id=$uid ORDER BY id ASC");
    $items = [];
    if ($r) { while ($row = mysqli_fetch_assoc($r)) $items[] = $row; }

    // Get not-found from dedicated table
    $nfr = mysqli_query($conn, "SELECT * FROM cheque_recon_not_found WHERE upload_id=$uid ORDER BY id ASC");
    $not_found = [];
    if ($nfr) { while ($row = mysqli_fetch_assoc($nfr)) $not_found[] = $row; }

    ob_end_clean();
    echo json_encode(['success' => true, 'header' => $header, 'items' => $items, 'not_found' => $not_found]);
    exit;
}

/* ── AJAX: reverse_upload ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'reverse_upload') {
    ob_start(); header('Content-Type: application/json');
    ensure_recon_tables_hist($conn);

    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_logs(id INT AUTO_INCREMENT PRIMARY KEY,cheque_id INT NOT NULL,action VARCHAR(100),old_value TEXT,new_value TEXT,note TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,created_by VARCHAR(100) DEFAULT 'system',INDEX idx_cid(cheque_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $uid = intval($_POST['upload_id'] ?? 0);
    if (!$uid) { ob_end_clean(); echo json_encode(['success'=>false,'error'=>'No upload_id']); exit; }

    $cu = mysqli_real_escape_string($conn, get_current_user_label_hist());

    $hr = mysqli_query($conn, "SELECT * FROM cheque_recon_uploads WHERE id=$uid LIMIT 1");
    $upload = $hr ? mysqli_fetch_assoc($hr) : null;
    if (!$upload) { ob_end_clean(); echo json_encode(['success'=>false,'error'=>'Upload not found']); exit; }
    if ($upload['status'] === 'reversed') { ob_end_clean(); echo json_encode(['success'=>false,'error'=>'Already reversed']); exit; }

    $ir = mysqli_query($conn, "SELECT * FROM cheque_recon_upload_items WHERE upload_id=$uid AND applied=1");
    $reversed = 0; $failed = 0;

    while ($ir && ($item = mysqli_fetch_assoc($ir))) {
        $cid = intval($item['cheque_id']);
        $old_st = $item['old_status'];
        $new_st = $item['new_status'];
        if (!$cid || !$old_st) { $failed++; continue; }

        $old_e = mysqli_real_escape_string($conn, $old_st);
        $new_e = mysqli_real_escape_string($conn, $new_st);
        $ok = mysqli_query($conn, "UPDATE cheques SET status='$old_e' WHERE id=$cid AND status='$new_e'");

        if ($ok && mysqli_affected_rows($conn) > 0) {
            $note = mysqli_real_escape_string($conn, "Recon Reversal | Upload #$uid reversed");
            mysqli_query($conn, "INSERT INTO cheque_logs(cheque_id,action,old_value,new_value,note,created_by) VALUES($cid,'status_change','$new_e','$old_e','$note','$cu')");

            if ($new_st === 'returned') {
                mysqli_query($conn, "DELETE FROM cheque_return_charges WHERE cheque_id=$cid LIMIT 1");
            }
            $reversed++;
        } else { $failed++; }
    }

    /* bank statement lines reconciled by this batch are no longer reconciled */
    $stmt_cleared = 0;
    try {
        mysqli_query($conn, "UPDATE bank_statement_transactions
                                SET recon_status=NULL, recon_source=NULL, recon_category=NULL, recon_ref_id=NULL,
                                    recon_remark=NULL, recon_by=NULL, recon_at=NULL
                              WHERE recon_source='cheque_recon' AND recon_ref_id=$uid");
        $stmt_cleared = mysqli_affected_rows($conn);
        mysqli_query($conn, "UPDATE cheque_recon_uploads SET stmt_reconciled=0 WHERE id=$uid");
    } catch (Throwable $e) { /* statement columns not created yet → nothing to clear */ }

    $now = date('Y-m-d H:i:s');
    mysqli_query($conn, "UPDATE cheque_recon_uploads SET status='reversed', reversed_at='$now', reversed_by='$cu' WHERE id=$uid");

    ob_end_clean();
    echo json_encode(['success'=>true, 'reversed'=>$reversed, 'failed'=>$failed, 'stmt_cleared'=>$stmt_cleared]);
    exit;
}

include 'header.php';
?>

<style>
:root{--ink:#0f172a;--ink3:#334155;--muted:#64748b;--lite:#f8fafc;--card:#ffffff;--bdr:#e2e8f0;--pri:#1e3a5f;--pri2:#2563eb;--teal:#0d9488;--green:#16a34a;--red:#dc2626;--amber:#d97706;--violet:#7c3aed;--shadow:0 1px 3px rgba(0,0,0,.06),0 4px 16px rgba(0,0,0,.05);--shadow-lg:0 8px 32px rgba(0,0,0,.12);}
.rch-wrap{max-width:1380px;margin:0 auto;padding:20px 16px 80px;font-family:'Segoe UI',system-ui,sans-serif;}

/* Hero */
.rch-hero{background:linear-gradient(135deg,#1e293b 0%,#334155 50%,#1e293b 100%);border-radius:16px;padding:20px 26px;display:flex;align-items:center;gap:18px;flex-wrap:wrap;margin-bottom:22px;position:relative;overflow:hidden;}
.rch-hero::before{content:'';position:absolute;top:-40px;right:-60px;width:240px;height:240px;border-radius:50%;background:rgba(124,58,237,.1);pointer-events:none;}
.rch-hero .hero-icon{width:52px;height:52px;border-radius:14px;background:rgba(255,255,255,.1);display:flex;align-items:center;justify-content:center;font-size:24px;color:#fff;flex-shrink:0;border:1px solid rgba(255,255,255,.15);}
.rch-hero .hero-title{color:#fff;font-size:20px;font-weight:800;}.rch-hero .hero-sub{color:rgba(255,255,255,.6);font-size:12px;margin-top:3px;}
.hero-right{margin-left:auto;display:flex;align-items:center;gap:10px;flex-wrap:wrap;position:relative;z-index:1;}
.btn-hero{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.1);color:#fff;border:1px solid rgba(255,255,255,.2);border-radius:9px;padding:8px 16px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;transition:background .2s;}
.btn-hero:hover{background:rgba(255,255,255,.2);color:#fff;}

/* Cards */
.rch-card{background:var(--card);border:1.5px solid var(--bdr);border-radius:14px;box-shadow:var(--shadow);overflow:hidden;}
.rch-card-hdr{padding:14px 20px;border-bottom:1.5px solid var(--bdr);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;background:linear-gradient(to right,#fafbff,#fff);}
.rch-card-title{font-size:14px;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:8px;}

/* Filter bar */
.filter-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:14px 16px;}
.fb-btn{background:#fff;border:1.5px solid var(--bdr);border-radius:8px;padding:7px 14px;font-size:11.5px;font-weight:700;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:5px;color:var(--ink3);transition:all .15s;}
.fb-btn:hover{background:#f1f5f9;}.fb-btn.active{background:var(--pri2);color:#fff !important;border-color:var(--pri2);}
.fb-sep{flex:1;}
.fb-search{border:1.5px solid var(--bdr);border-radius:8px;padding:7px 12px;font-size:12px;outline:none;width:220px;font-family:inherit;}
.sort-note{font-size:11px;color:var(--muted);font-weight:600;}

/* Tables */
.ht-outer{border:1.5px solid var(--bdr);border-radius:10px;overflow:hidden;}
.ht-scroll{overflow-x:auto;max-height:600px;overflow-y:auto;}
.ht{width:100%;border-collapse:collapse;font-size:12px;min-width:1100px;}
.ht thead th{padding:10px 12px;background:var(--ink);color:#e2e8f0;font-size:10.5px;font-weight:700;text-align:left;white-space:nowrap;border-right:1px solid rgba(255,255,255,.08);position:sticky;top:0;z-index:5;}
.ht thead th.tr{text-align:right;}.ht thead th.tc{text-align:center;}
.ht thead th.th-sort{cursor:pointer;user-select:none;background:#1e3a5f;}
.ht thead th.th-sort:hover{background:#2563eb;}
.ht tbody tr{border-bottom:1px solid #f1f5f9;transition:background .1s;cursor:pointer;}
.ht tbody tr:hover td{background:#f0f9ff !important;}
.ht td{padding:10px 12px;vertical-align:middle;background:#fff;}
.ht tr.row-rev td{background:#fef2f2;}
.tr{text-align:right;}.tc{text-align:center;}

/* Pills & badges */
.pill{display:inline-flex;align-items:center;gap:3px;padding:2px 9px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap;}
.p-green{background:#dcfce7;color:#166534;border:1px solid #86efac;}.p-red{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}
.p-blue{background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;}.p-amber{background:#fef3c7;color:#92400e;border:1px solid #fde68a;}
.p-gray{background:#f3f4f6;color:#374151;border:1px solid #e5e5e5;}.p-violet{background:#f5f3ff;color:#6d28d9;border:1px solid #ddd6fe;}
.mb{display:inline-flex;align-items:center;gap:3px;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;white-space:nowrap;}
.mb-ok{background:#dcfce7;color:#166534;}.mb-no{background:#fee2e2;color:#991b1b;}.mb-clr{background:#dcfce7;color:#16a34a;}.mb-ret{background:#fee2e2;color:#dc2626;}
.mb-amtok{background:#dcfce7;color:#166534;}.mb-amtd{background:#fef3c7;color:#92400e;}.mb-skip{background:#e5e7eb;color:#6b7280;}

/* Buttons */
.btn-danger{display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#991b1b,#dc2626);color:#fff;border:none;border-radius:9px;padding:7px 14px;font-size:11px;font-weight:800;cursor:pointer;font-family:inherit;box-shadow:0 3px 10px rgba(220,38,38,.2);}
.btn-danger:hover{filter:brightness(1.08);}.btn-danger:disabled{opacity:.5;cursor:not-allowed;}
.btn-teal{background:#f0fdfa;border:1.5px solid #5eead4;color:#0f766e;border-radius:8px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:5px;}
.btn-teal:hover{background:#ccfbf1;}

/* Modal */
.mdl-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.45);z-index:9990;display:none;align-items:center;justify-content:center;}
.mdl-overlay.show{display:flex;}
.mdl{background:var(--card);border-radius:16px;width:95%;max-width:1200px;max-height:90vh;box-shadow:var(--shadow-lg);display:flex;flex-direction:column;overflow:hidden;}
.mdl-hdr{padding:18px 24px;border-bottom:1.5px solid var(--bdr);display:flex;align-items:center;justify-content:space-between;background:linear-gradient(to right,#fafbff,#fff);flex-shrink:0;}
.mdl-hdr h3{font-size:16px;font-weight:800;color:var(--ink);margin:0;display:flex;align-items:center;gap:10px;}
.mdl-body{padding:20px 24px;overflow-y:auto;flex:1;}
.mdl-close{width:34px;height:34px;border-radius:10px;border:1.5px solid var(--bdr);background:#fff;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--muted);}
.mdl-close:hover{background:#fee2e2;color:#dc2626;border-color:#fecaca;}

/* KPI */
.kpi-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px;margin-bottom:18px;}
.kpi-box{background:var(--card);border:1.5px solid var(--bdr);border-radius:12px;padding:12px 14px;box-shadow:var(--shadow);}
.kpi-box .kl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:4px;}
.kpi-box .kv{font-size:20px;font-weight:900;color:var(--ink);}
.kv-green{color:var(--green)!important;}.kv-red{color:var(--red)!important;}.kv-amber{color:var(--amber)!important;}.kv-sky{color:#0284c7!important;}.kv-teal{color:var(--teal)!important;}

/* Detail tabs */
.dt-tabs{display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap;}
.dt-tab{padding:6px 14px;border-radius:8px;font-size:11.5px;font-weight:700;cursor:pointer;border:1.5px solid var(--bdr);background:#fff;color:var(--ink3);display:flex;align-items:center;gap:5px;transition:all .15s;}
.dt-tab:hover{background:#f1f5f9;}.dt-tab.active{background:var(--pri2);color:#fff !important;border-color:var(--pri2);}
.dt-tab .dc{font-size:10px;opacity:.75;}

/* Not found section (after items table) */
.nf-card{background:#fffbeb;border:1.5px solid #fde68a;border-radius:12px;padding:16px 20px;margin-top:22px;}
.nf-title{font-size:13px;font-weight:800;color:#92400e;margin-bottom:12px;display:flex;align-items:center;gap:8px;}

/* Toast */
#toast2{position:fixed;bottom:30px;right:26px;background:#166534;color:#fff;padding:12px 22px;border-radius:12px;font-size:13px;font-weight:700;z-index:9999;opacity:0;pointer-events:none;transition:opacity .3s;max-width:340px;box-shadow:var(--shadow-lg);}
#toast2.show{opacity:1;}#toast2.err{background:var(--red);}
</style>

<div class="rch-wrap">

  <!-- Hero -->
  <div class="rch-hero">
    <div class="hero-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
    <div style="position:relative;z-index:1;">
      <div class="hero-title">Reconciliation Upload History</div>
      <div class="hero-sub">View past uploads · Cleared / Not-Found details · Reverse updates</div>
    </div>
    <div class="hero-right">
      <a class="btn-hero" href="cheque_reconciliation.php"><i class="fa-solid fa-scale-balanced"></i> Reconcile</a>
      <a class="btn-hero" href="cheques.php"><i class="fa-solid fa-money-check"></i> Cheques</a>
    </div>
  </div>

  <!-- History Table -->
  <div class="rch-card">
    <div class="rch-card-hdr">
      <div class="rch-card-title"><i class="fa-solid fa-list"></i> Upload History</div>
      <button class="btn-teal" onclick="loadHistory()"><i class="fa-solid fa-rotate-right"></i> Refresh</button>
    </div>
    <div class="filter-bar">
      <button class="fb-btn active" data-filter="all" onclick="filterHist('all',this)"><i class="fa-solid fa-list"></i> All</button>
      <button class="fb-btn" data-filter="applied" onclick="filterHist('applied',this)" style="color:#166534;"><i class="fa-solid fa-circle-check"></i> Applied</button>
      <button class="fb-btn" data-filter="reversed" onclick="filterHist('reversed',this)" style="color:#991b1b;"><i class="fa-solid fa-rotate-left"></i> Reversed</button>
      <div class="fb-sep"></div>
      <span class="sort-note" id="sortNote">Statement Date: newest first</span>
      <input type="text" class="fb-search" id="histSearch" placeholder="Search file name…" oninput="searchHist(this.value)">
    </div>
    <div class="ht-outer"><div class="ht-scroll">
      <table class="ht">
        <thead><tr>
          <th>#</th><th>File Name</th>
          <th class="th-sort" onclick="toggleDateSort()" title="Click to change order">Statement Date <i class="fa-solid fa-arrow-down-wide-short" id="sdIcon"></i></th>
          <th class="tc">Total</th><th class="tc">Matched</th>
          <th class="tc" style="color:#86efac;">Cleared</th><th class="tc" style="color:#fecaca;">Returned</th>
          <th class="tc" style="color:#fde68a;">Not Found</th><th class="tc">Already</th><th class="tc">Skipped</th>
          <th>Status</th><th>Uploaded At</th><th>By</th><th>Actions</th>
        </tr></thead>
        <tbody id="histBody">
          <tr><td colspan="14" style="padding:40px;text-align:center;color:var(--muted);"><i class="fa-solid fa-spinner fa-spin" style="font-size:20px;display:block;margin-bottom:8px;"></i>Loading…</td></tr>
        </tbody>
      </table>
    </div></div>
  </div>

</div>

<!-- Detail Modal -->
<div class="mdl-overlay" id="mdlOverlay" onclick="if(event.target===this)closeMdl()">
  <div class="mdl">
    <div class="mdl-hdr">
      <h3><i class="fa-solid fa-file-lines"></i> <span id="mdlTitle">Upload Details</span></h3>
      <button class="mdl-close" onclick="closeMdl()">&times;</button>
    </div>
    <div class="mdl-body" id="mdlBody">
      <div style="text-align:center;padding:40px;color:var(--muted);"><i class="fa-solid fa-spinner fa-spin" style="font-size:28px;display:block;margin-bottom:10px;"></i>Loading…</div>
    </div>
  </div>
</div>

<div id="toast2"></div>

<script>
let _histRows   = [];
let _sdDir      = 'desc';   // 'desc' = newest statement date first
let _histFilter = 'all';
let _histQuery  = '';

/* ═══ Date helpers ═══ */
/* Returns 'YYYY-MM-DD' or '' — accepts Y-m-d, d/m/Y, d-m-Y, d.m.Y */
function toIsoDate(v) {
    if (!v) return '';
    const s = String(v).trim();
    let m = s.match(/^(\d{4})-(\d{1,2})-(\d{1,2})/);
    if (m) {
        if (m[1] === '0000') return '';
        return m[1] + '-' + m[2].padStart(2,'0') + '-' + m[3].padStart(2,'0');
    }
    m = s.match(/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})/);
    if (m) return m[3] + '-' + m[2].padStart(2,'0') + '-' + m[1].padStart(2,'0');
    return '';
}
function rowDateKey(r) { return r.stmt_date_iso || toIsoDate(r.statement_date); }

/* Bank statement uploads → short name "NDB · 02 Oct 2026"
   (full name e.g. "Bank statement · National Development Bank PLC / Kegalle (111000194014) · NDB · 02 Oct 2026"
    stays in the tooltip and in search). Other uploads keep their file name. */
function displayName(r) {
    const full  = String(r.file_name || '');
    const parts = full.split('·').map(p => p.trim()).filter(Boolean);
    const isStmt = r.source === 'statement' || /^bank statement$/i.test(parts[0] || '');
    if (!isStmt || parts.length < 3) return full;
    const bank = parts[parts.length - 2];               // e.g. "NDB"
    const key  = rowDateKey(r);
    const date = key ? fmtDate(key) : parts[parts.length - 1];
    return bank + ' · ' + date;
}

/* ═══ Sorting ═══ */
function sortRows(rows) {
    return rows.slice().sort((a, b) => {
        const ka = rowDateKey(a), kb = rowDateKey(b);
        if (!ka && kb) return 1;            // no date → always last
        if (ka && !kb) return -1;
        if (ka !== kb) {
            return _sdDir === 'desc' ? (ka < kb ? 1 : -1) : (ka < kb ? -1 : 1);
        }
        // same statement date → latest upload first
        const ca = a.created_at || '', cb = b.created_at || '';
        if (ca !== cb) return ca < cb ? 1 : -1;
        return (+b.id) - (+a.id);
    });
}

function toggleDateSort() {
    _sdDir = _sdDir === 'desc' ? 'asc' : 'desc';
    document.getElementById('sdIcon').className =
        'fa-solid ' + (_sdDir === 'desc' ? 'fa-arrow-down-wide-short' : 'fa-arrow-up-short-wide');
    document.getElementById('sortNote').textContent =
        'Statement Date: ' + (_sdDir === 'desc' ? 'newest first' : 'oldest first');
    renderHistory(sortRows(_histRows));
}

/* ═══ Load History ═══ */
async function loadHistory() {
    const fd = new FormData();
    fd.append('ajax_action', 'get_upload_history');
    try {
        const res = await fetch('cheque_recon_history.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed');
        _histRows = data.rows || [];
        renderHistory(sortRows(_histRows));
    } catch(e) {
        document.getElementById('histBody').innerHTML = '<tr><td colspan="14" style="padding:30px;text-align:center;color:#dc2626;">Error: '+esc(e.message)+'</td></tr>';
    }
}

function renderHistory(rows) {
    if (!rows.length) {
        document.getElementById('histBody').innerHTML = '<tr><td colspan="14" style="padding:40px;text-align:center;color:var(--muted);"><i class="fa-solid fa-inbox" style="font-size:28px;display:block;margin-bottom:10px;"></i>No upload history found.</td></tr>';
        return;
    }
    let h = '';
    rows.forEach(r => {
        const key = rowDateKey(r);

        const isRev = r.status === 'reversed';
        let stDate = key ? fmtDate(key) : '<span style="color:#d1d5db;">—</span>';
        if (r.stmt_date_fixed == 1) {
            stDate += '<div style="font-size:9.5px;color:#b45309;font-weight:700;margin-top:2px;" title="Saved statement date was after the upload date, so the date from the file name is used">'
                    + '<i class="fa-solid fa-wand-magic-sparkles"></i> saved as '+(r.stmt_date_raw ? fmtDate(r.stmt_date_raw) : '—')+'</div>';
        }
        const statusP = isRev
            ? '<span class="pill p-red"><i class="fa-solid fa-rotate-left"></i> Reversed</span>'
            : '<span class="pill p-green"><i class="fa-solid fa-circle-check"></i> Applied</span>';
        const revInfo = isRev ? '<div style="font-size:9px;color:#991b1b;margin-top:2px;">'+fmtDT(r.reversed_at)+' by '+esc(r.reversed_by||'')+'</div>' : '';
        const isStmt = r.source === 'statement';
        let reverseBtn = !isRev
            ? '<button class="btn-danger" onclick="event.stopPropagation();reverseUpload('+r.id+')" title="Reverse all changes"><i class="fa-solid fa-rotate-left"></i> Reverse</button>'
            : '<span style="font-size:10px;color:#991b1b;font-weight:700;">Reversed</span>';
        if (isStmt) reverseBtn += ' <button class="btn-danger" style="margin-top:4px;" onclick="event.stopPropagation();deleteStmtBatch('+r.id+','+(isRev?'true':'false')+')" title="Delete batch"><i class="fa-solid fa-trash"></i> Delete</button>';
        const srcBadge = isStmt
            ? '<div style="margin-top:3px;"><span class="pill p-green" style="background:#ccfbf1;color:#0f766e;"><i class="fa-solid fa-building-columns"></i> Bank statement · '+(r.stmt_reconciled||0)+' line(s) reconciled</span></div>'
            : '';

        h += '<tr class="hist-row '+(isRev?'row-rev':'')+'" data-grp="'+esc(key)+'" data-status="'+esc(r.status)+'" data-search="'+esc((r.file_name||'').toLowerCase())+'" onclick="openDetail('+r.id+')">'
            +'<td style="font-weight:800;color:var(--muted);">#'+r.id+'</td>'
            +'<td><div style="font-weight:700;color:var(--ink);font-size:12.5px;" title="'+esc(r.file_name)+'">'+esc(displayName(r))+'</div>'+srcBadge+'</td>'
            +'<td style="white-space:nowrap;font-weight:700;">'+stDate+'</td>'
            +'<td class="tc" style="font-weight:800;color:#0284c7;">'+num(r.total_rows)+'</td>'
            +'<td class="tc" style="font-weight:700;">'+num(r.matched)+'</td>'
            +'<td class="tc" style="font-weight:800;color:#16a34a;">'+num(r.cleared)+'</td>'
            +'<td class="tc" style="font-weight:800;color:#dc2626;">'+num(r.returned)+'</td>'
            +'<td class="tc" style="color:#d97706;font-weight:700;">'+num(r.not_found)+'</td>'
            +'<td class="tc" style="color:#6b7280;">'+num(r.already_done)+'</td>'
            +'<td class="tc" style="color:#6b7280;">'+num(r.skipped)+'</td>'
            +'<td>'+statusP+revInfo+'</td>'
            +'<td style="font-size:11px;white-space:nowrap;">'+fmtDT(r.created_at)+'</td>'
            +'<td style="font-size:11px;">'+esc(r.created_by)+'</td>'
            +'<td onclick="event.stopPropagation();">'+reverseBtn+'</td>'
            +'</tr>';
    });
    document.getElementById('histBody').innerHTML = h;
    applyHistFilters();
}

/* ═══ Filter + search (work together) ═══ */
function applyHistFilters() {
    document.querySelectorAll('#histBody tr.hist-row').forEach(tr => {
        const okStatus = _histFilter === 'all' || tr.dataset.status === _histFilter;
        const okSearch = !_histQuery || (tr.dataset.search || '').includes(_histQuery);
        tr.style.display = (okStatus && okSearch) ? '' : 'none';
    });
}

function filterHist(type, btn) {
    document.querySelectorAll('.fb-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    _histFilter = type;
    applyHistFilters();
}

function searchHist(q) {
    _histQuery = (q || '').toLowerCase().trim();
    applyHistFilters();
}

/* ═══ Reverse ═══ */
async function reverseUpload(uid) {
    if (!confirm('Reverse Upload #'+uid+'?\n\nAll cheque status changes will revert to previous status.\nReturn charges will be removed.')) return;
    const fd = new FormData();
    fd.append('ajax_action', 'reverse_upload');
    fd.append('upload_id', uid);
    try {
        const res = await fetch('cheque_recon_history.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed');
        showToast2('Reversed: '+data.reversed+' cheque(s) reverted'+(data.failed?', '+data.failed+' failed':'')+(data.stmt_cleared?' · '+data.stmt_cleared+' bank statement line(s) cleared':''), 'ok');
        loadHistory();
    } catch(e) { showToast2('Error: '+e.message, 'err'); }
}

/* ═══ Delete bank statement batch (handled by cheque_reconciliation.php) ═══ */
async function deleteStmtBatch(uid, reversed) {
    const msg = 'Delete bank statement batch #'+uid+'?\n\n'
              + '• Its bank statement lines lose the Cheque Reconcile status, category and remark.\n'
              + (reversed ? '• This batch is already reversed, so cheque statuses are not touched.\n'
                          : '• Cheques go back to their old status and the return charges added by this batch are removed\n   (cheques changed again after this batch are left as they are).\n')
              + '• The batch is removed from history.';
    if (!confirm(msg)) return;
    const fd = new FormData();
    fd.append('ajax_action', 'stmt_batch_delete');
    fd.append('upload_id', uid);
    if (!reversed) fd.append('revert', '1');
    try {
        const res = await fetch('cheque_reconciliation.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed');
        showToast2('Batch #'+uid+' deleted · '+data.lines+' statement line(s) cleared'+(data.reverted?' · '+data.reverted+' cheque(s) reverted':''), 'ok');
        loadHistory();
    } catch(e) { showToast2('Error: '+e.message, 'err'); }
}

/* ═══ Detail Modal ═══ */
async function openDetail(uid) {
    document.getElementById('mdlOverlay').classList.add('show');
    document.getElementById('mdlTitle').textContent = 'Upload #'+uid+' — Details';
    document.getElementById('mdlBody').innerHTML = '<div style="text-align:center;padding:40px;color:var(--muted);"><i class="fa-solid fa-spinner fa-spin" style="font-size:28px;display:block;margin-bottom:10px;"></i>Loading…</div>';

    const fd = new FormData();
    fd.append('ajax_action', 'get_upload_details');
    fd.append('upload_id', uid);
    try {
        const res = await fetch('cheque_recon_history.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed');
        renderDetail(data.header, data.items || [], data.not_found || []);
    } catch(e) {
        document.getElementById('mdlBody').innerHTML = '<div style="text-align:center;padding:30px;color:#dc2626;">Error: '+esc(e.message)+'</div>';
    }
}

function closeMdl() { document.getElementById('mdlOverlay').classList.remove('show'); }

function renderDetail(header, items, notFoundList) {
    const isRev = header.status === 'reversed';
    const sdKey = header.stmt_date_iso || toIsoDate(header.statement_date);
    const stDate = sdKey ? fmtDate(sdKey) : '—';

    const applied = items.filter(i => i.applied == 1);
    const cleared = applied.filter(i => i.new_status === 'cleared');
    const returned = applied.filter(i => i.new_status === 'returned');
    const alreadyDone = items.filter(i => i.already_updated == 1);

    /* ── KPI row ── */
    let html = '<div class="kpi-row">'
        +'<div class="kpi-box"><div class="kl">File</div><div style="font-size:12px;font-weight:700;word-break:break-all;" title="'+esc(header.file_name)+'">'+esc(displayName(header))+'</div></div>'
        +'<div class="kpi-box"><div class="kl">Statement Date</div><div class="kv kv-teal" style="font-size:16px;">'+stDate+'</div></div>'
        +'<div class="kpi-box"><div class="kl">Total Rows</div><div class="kv kv-sky">'+num(header.total_rows)+'</div></div>'
        +'<div class="kpi-box"><div class="kl">Applied</div><div class="kv kv-green">'+applied.length+'</div></div>'
        +'<div class="kpi-box"><div class="kl">Cleared</div><div class="kv kv-green">'+cleared.length+'</div></div>'
        +'<div class="kpi-box"><div class="kl">Returned</div><div class="kv kv-red">'+returned.length+'</div></div>'
        +'<div class="kpi-box"><div class="kl">Not Found</div><div class="kv kv-amber">'+notFoundList.length+'</div></div>'
        +'<div class="kpi-box"><div class="kl">Already Done</div><div class="kv" style="color:#6b7280;">'+alreadyDone.length+'</div></div>'
        +'<div class="kpi-box"><div class="kl">Status</div><div>'+(isRev?'<span class="pill p-red">Reversed</span>':'<span class="pill p-green">Applied</span>')+'</div></div>'
        +'</div>';

    /* ── Tabs ── */
    html += '<div class="dt-tabs">'
        +'<div class="dt-tab active" onclick="filterDet(\'all\',this)"><i class="fa-solid fa-list"></i> All <span class="dc">('+items.length+')</span></div>'
        +'<div class="dt-tab" onclick="filterDet(\'applied\',this)" style="color:#166534;"><i class="fa-solid fa-circle-check"></i> Applied <span class="dc">('+applied.length+')</span></div>'
        +'<div class="dt-tab" onclick="filterDet(\'cleared\',this)" style="color:#16a34a;"><i class="fa-solid fa-check"></i> Cleared <span class="dc">('+cleared.length+')</span></div>'
        +'<div class="dt-tab" onclick="filterDet(\'returned\',this)" style="color:#dc2626;"><i class="fa-solid fa-xmark"></i> Returned <span class="dc">('+returned.length+')</span></div>'
        +'<div class="dt-tab" onclick="filterDet(\'already\',this)" style="color:#6b7280;"><i class="fa-solid fa-ban"></i> Already <span class="dc">('+alreadyDone.length+')</span></div>'
        +'</div>';

    /* ── Items table (cleared / returned / already done) ── */
    html += '<div class="ht-outer"><div class="ht-scroll" style="max-height:380px;"><table class="ht" style="min-width:950px;">'
        +'<thead><tr><th>#</th><th>Stmt Cheque No</th><th>DB Cheque No</th><th>Customer</th><th>T-Code</th><th>Action</th><th>Old Status</th><th>New Status</th><th class="tr">Stmt Amt</th><th class="tr">DB Amt</th><th class="tc">Amt</th><th>Bank Ref</th><th>Tx Date</th><th>Return Reason</th></tr></thead><tbody>';

    items.forEach((item, idx) => {
        const isMatched = item.matched == 1;
        const cat = !isMatched ? 'notfound'
                  : item.already_updated == 1 ? 'already'
                  : item.applied == 1 ? (item.new_status === 'returned' ? 'returned' : 'cleared')
                  : 'notapplied';
        const appliedCat = item.applied == 1 ? 'applied' : '';
        const rowCls = item.applied == 1 ? (item.new_status==='cleared'?'row-cleared':'row-returned')
                     : !isMatched ? 'row-notfound' : item.already_updated == 1 ? 'row-already' : '';

        const actionB = item.applied == 1
            ? (item.new_status==='cleared'?'<span class="mb mb-clr"><i class="fa-solid fa-circle-check"></i> Cleared</span>':'<span class="mb mb-ret"><i class="fa-solid fa-circle-xmark"></i> Returned</span>')
            : !isMatched ? '<span class="mb mb-no"><i class="fa-solid fa-xmark"></i> Not Found</span>'
            : item.already_updated == 1 ? '<span class="mb mb-skip"><i class="fa-solid fa-ban"></i> Already</span>'
            : '<span class="mb" style="background:#e5e7eb;color:#6b7280;">Not Selected</span>';

        const amtB = item.amt_match == 1 ? '<span class="mb mb-amtok"><i class="fa-solid fa-check"></i></span>'
                   : isMatched ? '<span class="mb mb-amtd"><i class="fa-solid fa-exclamation"></i></span>' : '—';

        html += '<tr class="'+rowCls+'" data-cat="'+cat+'" data-cat2="'+appliedCat+'" style="cursor:default;">'
            +'<td style="color:var(--muted);font-size:10px;">'+(idx+1)+'</td>'
            +'<td><span style="font-family:monospace;font-size:11px;color:#9ca3af;">'+esc(item.stmt_cheque_no)+'</span></td>'
            +'<td>'+(item.db_cheque_no?'<span style="font-family:monospace;font-size:12px;font-weight:800;color:#312e81;background:#ede9fe;padding:2px 7px;border-radius:5px;">'+esc(item.db_cheque_no)+'</span>':'—')+'</td>'
            +'<td style="font-size:12px;font-weight:600;">'+esc(item.customer_name||'—')+'</td>'
            +'<td style="font-family:monospace;font-size:10px;color:var(--muted);">'+esc(item.t_code||'')+'</td>'
            +'<td>'+actionB+'</td>'
            +'<td>'+(item.old_status?'<span class="pill p-gray">'+esc(item.old_status)+'</span>':'—')+'</td>'
            +'<td>'+(item.new_status?(item.new_status==='cleared'?'<span class="pill p-green">cleared</span>':'<span class="pill p-red">returned</span>'):'—')+'</td>'
            +'<td class="tr" style="font-weight:700;">Rs. '+fmtN(item.stmt_amount)+'</td>'
            +'<td class="tr" style="color:var(--muted);">'+(item.db_amount!=null?'Rs. '+fmtN(item.db_amount):'—')+'</td>'
            +'<td class="tc">'+amtB+'</td>'
            +'<td style="font-family:monospace;font-size:10px;color:#0284c7;">'+esc(item.bank_ref||'')+'</td>'
            +'<td style="font-size:11px;">'+esc(item.tx_date||'')+'</td>'
            +'<td style="font-size:11px;color:#991b1b;">'+esc(item.return_reason||'')+'</td>'
            +'</tr>';
    });

    html += '</tbody></table></div></div>';

    /* ── Not-Found Card — shown AFTER the items table ── */
    if (notFoundList.length > 0) {
        html += '<div class="nf-card">'
            +'<div class="nf-title"><i class="fa-solid fa-triangle-exclamation"></i> Not Found in Database &nbsp;<span style="font-size:11px;font-weight:600;color:#a16207;">('+notFoundList.length+' cheque'+(notFoundList.length>1?'s':'')+' in statement not matched to any DB record)</span></div>'
            +'<div style="overflow-x:auto;border:1.5px solid #fde68a;border-radius:8px;">'
            +'<table style="width:100%;border-collapse:collapse;font-size:12px;min-width:700px;">'
            +'<thead><tr style="background:#92400e;color:#fff;">'
            +'<th style="padding:8px 10px;text-align:left;">#</th>'
            +'<th style="padding:8px 10px;text-align:left;">Statement Cheque No</th>'
            +'<th style="padding:8px 10px;text-align:right;">Amount</th>'
            +'<th style="padding:8px 10px;text-align:center;">Type</th>'
            +'<th style="padding:8px 10px;text-align:left;">Bank Ref</th>'
            +'<th style="padding:8px 10px;text-align:left;">Tx Date</th>'
            +'<th style="padding:8px 10px;text-align:left;">Description</th>'
            +'</tr></thead><tbody>';

        notFoundList.forEach((nf, idx) => {
            const rowBg = idx % 2 === 0 ? '#fffbeb' : '#fef9c3';
            html += '<tr style="border-bottom:1px solid #fde68a;background:'+rowBg+';">'
                +'<td style="padding:7px 10px;color:#92400e;font-weight:700;">'+(idx+1)+'</td>'
                +'<td style="padding:7px 10px;font-family:monospace;font-weight:800;color:#92400e;font-size:13px;">'+esc(nf.stmt_cheque_no)+'</td>'
                +'<td style="padding:7px 10px;text-align:right;font-weight:700;color:#92400e;">Rs. '+fmtN(nf.stmt_amount)+'</td>'
                +'<td style="padding:7px 10px;text-align:center;">'+(nf.type==='cleared'?'<span class="mb mb-clr">Clear</span>':'<span class="mb mb-ret">Return</span>')+'</td>'
                +'<td style="padding:7px 10px;font-family:monospace;font-size:10px;color:#0284c7;">'+esc(nf.bank_ref||'—')+'</td>'
                +'<td style="padding:7px 10px;font-size:11px;">'+esc(nf.tx_date||'—')+'</td>'
                +'<td style="padding:7px 10px;font-size:11px;color:var(--muted);max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+esc(nf.description||'')+'">'+esc((nf.description||'').substring(0,70)||'—')+'</td>'
                +'</tr>';
        });

        html += '</tbody></table></div></div>';
    } else {
        html += '<div style="margin-top:18px;background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;padding:12px 18px;display:flex;align-items:center;gap:10px;font-size:12px;font-weight:700;color:#166534;">'
            +'<i class="fa-solid fa-circle-check"></i> All cheques in this statement were matched — no unmatched records.'
            +'</div>';
    }

    document.getElementById('mdlBody').innerHTML = html;
}

function filterDet(cat, btn) {
    document.querySelectorAll('.dt-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('#mdlBody .ht tbody tr').forEach(tr => {
        if (cat === 'all') { tr.style.display = ''; return; }
        if (cat === 'applied') { tr.style.display = tr.dataset.cat2 === 'applied' ? '' : 'none'; }
        else { tr.style.display = tr.dataset.cat === cat ? '' : 'none'; }
    });
}

/* ═══ Utils ═══ */
function esc(s){if(s==null)return '';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
function num(v){return esc(v==null?0:v);}
function fmtN(v){return parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});}
/* Parses the date as a local calendar date (no timezone shift) */
function fmtDate(d){
    const iso = toIsoDate(d);
    if (!iso) return d ? esc(d) : '—';
    const [y,m,day] = iso.split('-').map(Number);
    const MON = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    if (!MON[m-1]) return esc(d);
    return String(day).padStart(2,'0') + ' ' + MON[m-1] + ' ' + y;
}
function fmtDT(d){
    if(!d || String(d).startsWith('0000')) return '—';
    const m = String(d).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
    const dt = m ? new Date(+m[1], +m[2]-1, +m[3], +m[4], +m[5]) : new Date(d);
    if (isNaN(dt)) return esc(d);
    return dt.toLocaleString('en-GB',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});
}
function showToast2(msg,type){const t=document.getElementById('toast2');t.className=type==='err'?'err':'';t.textContent=msg;t.classList.add('show');clearTimeout(t._t);t._t=setTimeout(()=>t.classList.remove('show'),3800);}

// Load on page load
loadHistory();
</script>

<?php include 'footer.php'; ?>