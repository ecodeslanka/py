<?php
// ── AJAX must be handled BEFORE any include/output ───────────────────────────
// header.php outputs HTML which corrupts JSON — so intercept POST first
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    include 'config.php';
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $action = $_POST['action'];

    if ($action === 'save_recon') {
        $mk         = mysqli_real_escape_string($conn, trim($_POST['month_key']   ?? ''));
        $txn_date   = mysqli_real_escape_string($conn, trim($_POST['txn_date']    ?? ''));
        $total      = (float)($_POST['total_claimed'] ?? 0);
        $claim_date = mysqli_real_escape_string($conn, trim($_POST['claim_date']  ?? ''));
        if (!$mk)       { echo json_encode(['ok'=>false,'msg'=>'Month key missing.']);        exit; }
        if (!$txn_date) { echo json_encode(['ok'=>false,'msg'=>'Transaction date missing.']); exit; }
        $sql = "INSERT INTO claim_reconciliation (month_key,txn_date,total_claimed,claim_date)
                VALUES ('$mk','$txn_date',$total,'$claim_date')
                ON DUPLICATE KEY UPDATE txn_date='$txn_date',total_claimed=$total,claim_date='$claim_date',updated_at=NOW()";
        if (!mysqli_query($conn,$sql)) { echo json_encode(['ok'=>false,'msg'=>'DB error: '.mysqli_error($conn)]); exit; }
        echo json_encode(['ok'=>true,'msg'=>'Saved.']);
        exit;
    }

    if ($action === 'reverse_recon') {
        $mk = mysqli_real_escape_string($conn, trim($_POST['month_key'] ?? ''));
        if (!$mk) { echo json_encode(['ok'=>false,'msg'=>'Month key missing.']); exit; }
        $sql = "UPDATE claim_reconciliation SET txn_date=NULL,total_claimed=0.00,claim_date=NULL,updated_at=NOW() WHERE month_key='$mk'";
        if (!mysqli_query($conn,$sql)) { echo json_encode(['ok'=>false,'msg'=>'DB error: '.mysqli_error($conn)]); exit; }
        echo json_encode(['ok'=>true,'msg'=>'Reversed.']);
        exit;
    }

    if ($action === 'save_handed') {
        $mk   = mysqli_real_escape_string($conn, trim($_POST['month_key']        ?? ''));
        $date = mysqli_real_escape_string($conn, trim($_POST['handed_over_date'] ?? ''));
        if (!$mk) { echo json_encode(['ok'=>false,'msg'=>'Month key missing.']); exit; }
        $sql = "INSERT INTO claim_reconciliation (month_key,handed_over_date) VALUES ('$mk','$date')
                ON DUPLICATE KEY UPDATE handed_over_date='$date',updated_at=NOW()";
        if (!mysqli_query($conn,$sql)) { echo json_encode(['ok'=>false,'msg'=>'DB error: '.mysqli_error($conn)]); exit; }
        echo json_encode(['ok'=>true,'msg'=>'Saved.']);
        exit;
    }

    // ── Clear handed over date only ──────────────────────────────────────────
    if ($action === 'clear_handed') {
        $mk = mysqli_real_escape_string($conn, trim($_POST['month_key'] ?? ''));
        if (!$mk) { echo json_encode(['ok'=>false,'msg'=>'Month key missing.']); exit; }
        $sql = "UPDATE claim_reconciliation SET handed_over_date=NULL,updated_at=NOW() WHERE month_key='$mk'";
        if (!mysqli_query($conn,$sql)) { echo json_encode(['ok'=>false,'msg'=>'DB error: '.mysqli_error($conn)]); exit; }
        echo json_encode(['ok'=>true,'msg'=>'Handed over date cleared.']);
        exit;
    }

    // ── Clear total claimed + claim date only ────────────────────────────────
    if ($action === 'clear_claimed') {
        $mk = mysqli_real_escape_string($conn, trim($_POST['month_key'] ?? ''));
        if (!$mk) { echo json_encode(['ok'=>false,'msg'=>'Month key missing.']); exit; }
        $sql = "UPDATE claim_reconciliation SET txn_date=NULL,total_claimed=0.00,claim_date=NULL,updated_at=NOW() WHERE month_key='$mk'";
        if (!mysqli_query($conn,$sql)) { echo json_encode(['ok'=>false,'msg'=>'DB error: '.mysqli_error($conn)]); exit; }
        echo json_encode(['ok'=>true,'msg'=>'Claim data cleared.']);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Unknown action.']);
    exit;
}

// ── Normal page load ──────────────────────────────────────────────────────────
include 'config.php';
include 'header.php';

$month_names = ['','January','February','March','April','May','June',
                'July','August','September','October','November','December'];

mysqli_query($conn,"CREATE TABLE IF NOT EXISTS claim_reconciliation (
    id INT AUTO_INCREMENT PRIMARY KEY,
    month_key VARCHAR(7) NOT NULL COMMENT 'YYYY-MM',
    txn_date DATE DEFAULT NULL,
    total_claimed DECIMAL(15,2) DEFAULT 0.00,
    claim_date DATE DEFAULT NULL,
    handed_over_date DATE DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_month (month_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Step 1: Invoice Wise Sales
$inv_by_month=[];
$r=mysqli_query($conn,"SELECT YEAR(i.delivery_date) AS yr,MONTH(i.delivery_date) AS mo,
    COALESCE(SUM(d.damage_expiry_shortage_value),0) AS inv_dmg
    FROM secondary_invoice_imports i INNER JOIN secondary_invoice_import_details d ON d.import_id=i.id
    GROUP BY YEAR(i.delivery_date),MONTH(i.delivery_date) ORDER BY yr,mo");
if($r) while($row=mysqli_fetch_assoc($r)){
    $k=$row['yr'].'-'.str_pad($row['mo'],2,'0',STR_PAD_LEFT);
    $inv_by_month[$k]=['yr'=>(int)$row['yr'],'mo'=>(int)$row['mo'],'inv_dmg'=>(float)$row['inv_dmg']];
}

// Step 2: DSP Report
$dsp_by_month=[];
$r2=mysqli_query($conn,"SELECT i.proposal_year AS yr,i.proposal_month AS mo,
    COALESCE(SUM(CASE WHEN d.transaction_type='MKT - DMG' THEN d.total_tur_value ELSE 0 END),0) AS mkt_tur,
    COALESCE(SUM(CASE WHEN d.transaction_type='MKT - DMG' THEN d.total_aip ELSE 0 END),0) AS mkt_aip,
    COALESCE(SUM(CASE WHEN d.transaction_type='RS LOC - DMG' THEN d.total_aip ELSE 0 END),0) AS rsloc_aip
    FROM damage_proposal_imports i INNER JOIN damage_proposal_transaction_details d ON d.import_id=i.id
    GROUP BY i.proposal_year,i.proposal_month ORDER BY yr,mo");
if($r2) while($row=mysqli_fetch_assoc($r2)){
    $k=$row['yr'].'-'.str_pad($row['mo'],2,'0',STR_PAD_LEFT);
    $dsp_by_month[$k]=['yr'=>(int)$row['yr'],'mo'=>(int)$row['mo'],
        'mkt_tur'=>(float)$row['mkt_tur'],'mkt_aip'=>(float)$row['mkt_aip'],'rsloc_aip'=>(float)$row['rsloc_aip']];
}

// Step 3: Merge
$all_keys=array_unique(array_merge(array_keys($inv_by_month),array_keys($dsp_by_month)));
sort($all_keys);
$report_rows=[];
$totals=['inv_dmg'=>0,'dsp_mkt_tur'=>0,'diff'=>0,'dsp_mkt_aip'=>0,'dsp_rsloc_aip'=>0,'total_dis'=>0];
foreach($all_keys as $k){
    $yr=$inv_by_month[$k]['yr']??$dsp_by_month[$k]['yr'];
    $mo=$inv_by_month[$k]['mo']??$dsp_by_month[$k]['mo'];
    $inv_dmg=$inv_by_month[$k]['inv_dmg']??0.0;
    $mkt_tur=$dsp_by_month[$k]['mkt_tur']??0.0;
    $mkt_aip=$dsp_by_month[$k]['mkt_aip']??0.0;
    $rsloc_aip=$dsp_by_month[$k]['rsloc_aip']??0.0;
    $diff=$mkt_tur-$inv_dmg; $total_dis=$mkt_aip+$rsloc_aip;
    $report_rows[]=compact('yr','mo','inv_dmg','mkt_tur','diff','mkt_aip','rsloc_aip','total_dis');
    $totals['inv_dmg']+=$inv_dmg;$totals['dsp_mkt_tur']+=$mkt_tur;
    $totals['diff']+=$diff;$totals['dsp_mkt_aip']+=$mkt_aip;
    $totals['dsp_rsloc_aip']+=$rsloc_aip;$totals['total_dis']+=$total_dis;
}

// Step 4: Saved data
$saved=[];
$rs=mysqli_query($conn,"SELECT * FROM claim_reconciliation");
if($rs) while($row=mysqli_fetch_assoc($rs)) $saved[$row['month_key']]=$row;

// Step 5: Claims credit by exact txn_date
// company is directly on ulcl_ledger — no join needed
// LIKE '%claim%' catches: 'Claims credit','Claim','2024-Feb Damage Claim', etc.
$recon_by_date=[];
$rr=mysqli_query($conn,"SELECT DATE(l.txn_date) AS txn_date, l.company, l.import_id,
    COALESCE(SUM(l.credit),0) AS claims_credit
    FROM ulcl_ledger l
    WHERE LOWER(l.transaction_type) LIKE '%claim%'
    GROUP BY DATE(l.txn_date),l.company,l.import_id
    ORDER BY txn_date ASC, l.company ASC");
if($rr) while($row=mysqli_fetch_assoc($rr)){
    $d=$row['txn_date']; $co=strtoupper(trim($row['company']));
    if(!isset($recon_by_date[$d]))
        $recon_by_date[$d]=['txn_date'=>$d,'month_key'=>substr($d,0,7),
            'ULCL'=>0.0,'USLL'=>0.0,'ULCL_import'=>null,'USLL_import'=>null];
    if($co==='ULCL'){$recon_by_date[$d]['ULCL']+=(float)$row['claims_credit'];$recon_by_date[$d]['ULCL_import']=(int)$row['import_id'];}
    elseif($co==='USLL'){$recon_by_date[$d]['USLL']+=(float)$row['claims_credit'];$recon_by_date[$d]['USLL_import']=(int)$row['import_id'];}
}

$month_options=[];
foreach($report_rows as $rrow){
    $mk=$rrow['yr'].'-'.str_pad($rrow['mo'],2,'0',STR_PAD_LEFT);
    $month_options[$mk]=$month_names[(int)$rrow['mo']].' '.$rrow['yr'];
}
?>
<style>
.dc-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.dc-title{font-size:22px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:10px;margin:0 0 4px}
.dc-subtitle{font-size:13px;color:#6b7280;margin:0}
.dc-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.dc-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;margin-bottom:24px}
.dc-wrap{overflow-x:auto}
.dc-table{width:100%;border-collapse:collapse;font-size:12px;white-space:nowrap}
.dc-table .hrow-group th{padding:8px 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #e5e5e5;border-right:1px solid #e5e5e5;text-align:center;vertical-align:middle}
.dc-table .hrow-sub th{padding:7px 10px;font-size:10px;font-weight:600;border-bottom:2px solid #d1d5db;border-right:1px solid #e5e5e5;text-align:center;vertical-align:middle}
.th-month{background:#f3f4f6!important;color:#1f2937!important;text-align:left!important}

.th-invoice{background:#dbeafe!important;color:#1e3a8a!important}
.th-dsp-list{background:#ede9fe!important;color:#5b21b6!important}
.th-dsp-dis{background:#f3e8ff!important;color:#7c3aed!important}
.th-total{background:#e9d5ff!important;color:#6d28d9!important}
.th-handed{background:#fef3c7!important;color:#92400e!important}
.th-claimed{background:#d1fae5!important;color:#065f46!important}
.th-claimdate{background:#ffedd5!important;color:#c2410c!important}
.th-tobeclaim{background:#fee2e2!important;color:#991b1b!important}
.dc-table tbody tr{border-bottom:1px solid #f0f0f0;transition:background .4s}
.dc-table tbody tr:hover{background:#f9fafb}
.dc-table tbody tr.row-linked{background:#fef9c3!important;border-left:4px solid #f59e0b!important}
.dc-table td{padding:10px 12px;border-right:1px solid #f0f0f0;vertical-align:middle}
.td-month{font-weight:600;color:#1f2937;min-width:150px}
.td-num{text-align:right;font-family:monospace;color:#374151}
.td-diff-pos{text-align:right;font-family:monospace;color:#166534;font-weight:700}
.td-diff-neg{text-align:right;font-family:monospace;color:#991b1b;font-weight:700}
.td-diff-zero{text-align:right;font-family:monospace;color:#6b7280}
.td-total{text-align:right;font-family:monospace;font-weight:700;color:#6d28d9}
.td-claimed{text-align:right;font-family:monospace;font-weight:700;color:#065f46;min-width:120px}
.td-tbc-pos{text-align:right;font-family:monospace;font-weight:700;color:#991b1b}
.td-tbc-zero{text-align:right;font-family:monospace;color:#6b7280}
.td-tbc-neg{text-align:right;font-family:monospace;font-weight:700;color:#166534}
.td-date-cell{text-align:center;min-width:140px}
.td-claimdate{text-align:center;font-size:12px;color:#c2410c;font-weight:600;min-width:120px}
.date-input{padding:5px 8px;border:1px solid #e5e5e5;border-radius:6px;font-size:12px;font-family:inherit;color:#1f2937;width:126px;cursor:pointer;transition:border-color .2s}
.date-input:focus{outline:none;border-color:#f59e0b;box-shadow:0 0 0 2px #fde68a}
.dc-table tfoot tr{background:#f3f4f6;border-top:2px solid #d1d5db}
.dc-table tfoot td{padding:10px 12px;font-weight:700;font-family:monospace;font-size:12px;text-align:right;border-right:1px solid #e5e5e5;color:#1f2937}
.tf-label{font-family:inherit!important;text-align:right;color:#374151}
.dc-err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:12px 16px;border-radius:8px;font-size:13px;margin-bottom:16px}
.dc-empty-state{text-align:center;padding:60px 20px;color:#9ca3af}
.dc-empty-state i{font-size:40px;display:block;margin-bottom:12px;color:#d1d5db}
.dc-empty-state p{font-size:13px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .2s}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}.btn-secondary:hover{background:#e5e5e5}
.btn-print{background:#1f2937;color:#fff}.btn-print:hover{background:#374151}
.btn-reconcile{background:#065f46;color:#fff}.btn-reconcile:hover{background:#064e3b}
.toast{position:fixed;bottom:24px;right:24px;padding:11px 18px;border-radius:8px;font-size:13px;font-weight:600;z-index:9999;opacity:0;transform:translateY(8px);transition:all .3s;pointer-events:none;display:flex;align-items:center;gap:8px;max-width:380px;box-shadow:0 4px 20px rgba(0,0,0,.18)}
.toast.show{opacity:1;transform:translateY(0)}.toast-ok{background:#065f46;color:#fff}.toast-err{background:#991b1b;color:#fff}
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:900;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .2s}
.modal-backdrop.open{opacity:1;pointer-events:auto}
.modal-box{background:#fff;border-radius:14px;width:100%;max-width:980px;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.2);transform:translateY(12px);transition:transform .25s}
.modal-backdrop.open .modal-box{transform:translateY(0)}
.modal-head{display:flex;justify-content:space-between;align-items:center;padding:20px 24px 16px;border-bottom:1px solid #e5e5e5;flex-shrink:0}
.modal-title{font-size:17px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:10px;margin:0}
.modal-close{background:none;border:none;cursor:pointer;color:#6b7280;font-size:20px;padding:4px;display:flex;align-items:center;border-radius:6px}
.modal-close:hover{background:#f5f5f5;color:#111}
.modal-body{overflow-y:auto;padding:20px 24px 8px;flex:1}
.modal-foot{padding:14px 24px;border-top:1px solid #f0f0f0;flex-shrink:0;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.modal-foot-info{font-size:12px;color:#6b7280}
.recon-table{width:100%;border-collapse:collapse;font-size:13px}
.recon-table thead th{background:#f8fafc;padding:10px 12px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#374151;border-bottom:2px solid #e5e5e5;white-space:nowrap}
.recon-table thead th.th-l{text-align:left}.recon-table thead th.th-r{text-align:right}.recon-table thead th.th-c{text-align:center}
.recon-table tbody tr{border-bottom:1px solid #f0f0f0;transition:background .2s}
.recon-table tbody tr:hover{background:#f9fafb}
.recon-table tbody tr.row-saved{background:#f0fdf4}
.recon-table tbody tr.row-reversed{background:#fef2f2}
.recon-table tbody tr.row-error{background:#fff1f2}
.recon-table td{padding:10px 12px;vertical-align:middle;white-space:nowrap}
.rt-date{font-weight:600;color:#1f2937;font-size:13px;min-width:130px}
.rt-link{text-align:right;font-family:monospace}
.rt-link a{color:#166534;font-weight:700;text-decoration:none;border-bottom:1px dashed #86efac;font-size:13px}
.rt-link a:hover{color:#14532d}
.rt-none{color:#d1d5db;font-style:italic;font-size:12px}
.rt-total{text-align:right;font-family:monospace;font-weight:700;color:#6d28d9;font-size:13px}
.recon-foot td{background:#f3f4f6;border-top:2px solid #d1d5db;padding:10px 12px;font-weight:700;font-family:monospace;font-size:13px;text-align:right;color:#1f2937}
.recon-foot td.tf-lbl{font-family:inherit;text-align:left;font-size:12px;color:#374151}
.co-badge{font-size:10px;font-weight:700;padding:2px 7px;border-radius:5px;white-space:nowrap;vertical-align:middle}
.co-ulcl{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
.co-usll{background:#fdf4ff;color:#7e22ce;border:1px solid #e9d5ff}
.recon-empty{text-align:center;padding:40px 20px;color:#9ca3af;font-size:13px}
.month-select{padding:6px 10px;border:1px solid #e5e5e5;border-radius:7px;font-size:12px;font-family:inherit;color:#1f2937;background:#fff;min-width:155px;cursor:pointer}
.month-select:focus{outline:none;border-color:#3b82f6}
.month-select.sel-active{border-color:#3b82f6;background:#eff6ff;color:#1e40af;font-weight:600}
.btn-row-update{display:inline-flex;align-items:center;gap:5px;padding:6px 11px;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;background:#1e40af;color:#fff}
.btn-row-update:hover:not(:disabled){background:#1d4ed8}
.btn-row-update:disabled{background:#93c5fd;cursor:not-allowed;opacity:.7}
.btn-row-update.st-saving{background:#6b7280;cursor:not-allowed}
.btn-row-update.st-saved{background:#065f46}
.btn-row-update.st-error{background:#991b1b}
.btn-row-reverse{display:inline-flex;align-items:center;gap:5px;padding:6px 11px;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;background:#7c3aed;color:#fff}
.btn-row-reverse:hover:not(:disabled){background:#6d28d9}
.btn-row-reverse:disabled{background:#c4b5fd;cursor:not-allowed;opacity:.7}
.btn-row-reverse.st-saving{background:#6b7280;cursor:not-allowed}
.row-status{font-size:11px;font-weight:600;display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:12px;white-space:nowrap}
.rs-saved{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.rs-reversed{background:#f5f3ff;color:#6d28d9;border:1px solid #ddd6fe}
.rs-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.rs-pending{background:#fef9c3;color:#92400e;border:1px solid #fde68a}
.td-actions{text-align:center;min-width:200px}
.action-btns{display:flex;gap:6px;justify-content:center;align-items:center}
/* small inline clear button inside main table cells */
.btn-cell-clear{display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border:none;border-radius:50%;background:#fca5a5;color:#7f1d1d;font-size:9px;cursor:pointer;padding:0;margin-left:5px;line-height:1;vertical-align:middle;transition:background .15s;flex-shrink:0}
.btn-cell-clear:hover{background:#f87171;color:#450a0a}
.cell-with-clear{display:inline-flex;align-items:center;justify-content:flex-end;gap:4px;width:100%}
.date-cell-wrap{display:flex;align-items:center;justify-content:center;gap:4px}
@media print{.dc-actions,.modal-backdrop,.toast,.btn-cell-clear{display:none!important}.dc-card{border:none}}
</style>

<div class="dc-header">
    <div>
        <h2 class="dc-title"><i class="fa-solid fa-file-invoice-dollar" style="color:#dc2626;"></i> Damaged Claim Report</h2>
        <p class="dc-subtitle">Grouped by upload month</p>
    </div>
    <div class="dc-actions">
        <button class="btn btn-reconcile" onclick="openRecon()"><i class="fa-solid fa-scale-balanced"></i> Reconcile with Customer Ledger</button>
        <button class="btn btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        <a href="javascript:history.back()" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
    </div>
</div>

<?php $err1=mysqli_error($conn); if($err1): ?>
<div class="dc-err"><i class="fa-solid fa-circle-xmark"></i> DB Error: <?= htmlspecialchars($err1) ?></div>
<?php endif; ?>

<div class="dc-card"><div class="dc-wrap">
<table class="dc-table" id="mainTable">
    <thead>
        <tr class="hrow-group">
            <th rowspan="2" class="th-month" style="text-align:left;min-width:150px;">Month</th>
            <th colspan="1" class="th-invoice">Invoice Wise Sales</th>
            <th colspan="2" class="th-dsp-list">DSP Status Report</th>
            <th colspan="2" class="th-dsp-dis">DSP Dis Price</th>
            <th colspan="1" class="th-total">Total</th>
            <th colspan="1" class="th-handed">Handed Over Date</th>
            <th colspan="1" class="th-claimed">Total Claimed</th>
            <th colspan="1" class="th-claimdate">Claim Date</th>
            <th colspan="1" class="th-tobeclaim">To Be Claimed</th>
        </tr>
        <tr class="hrow-sub">
            <th class="th-invoice" style="min-width:120px;">MKT – DMG<br><span style="font-weight:400;font-size:9px;opacity:.8;">List Price</span></th>
            <th class="th-dsp-list" style="min-width:120px;">MKT – DMG<br><span style="font-weight:400;font-size:9px;opacity:.8;">List Price</span></th>
            <th class="th-dsp-list" style="min-width:100px;">Difference<br><span style="font-weight:400;font-size:9px;opacity:.8;">List Price</span></th>
            <th class="th-dsp-dis" style="min-width:120px;">MKT – DMG<br><span style="font-weight:400;font-size:9px;opacity:.8;">Dis Price</span></th>
            <th class="th-dsp-dis" style="min-width:130px;">RS LOC – DMG<br><span style="font-weight:400;font-size:9px;opacity:.8;">Dis Price</span></th>
            <th class="th-total" style="min-width:110px;">Total<br><span style="font-weight:400;font-size:9px;opacity:.8;">Dis Price</span></th>
            <th class="th-handed" style="min-width:140px;">Date</th>
            <th class="th-claimed" style="min-width:130px;">Amount</th>
            <th class="th-claimdate" style="min-width:120px;">Date</th>
            <th class="th-tobeclaim" style="min-width:130px;">Amount</th>
        </tr>
    </thead>
    <tbody>
    <?php if(empty($report_rows)): ?>
        <tr><td colspan="11" class="dc-empty-state"><i class="fa-solid fa-inbox"></i><p>No data found.</p></td></tr>
    <?php else: ?>
    <?php foreach($report_rows as $row):
        $mk=$row['yr'].'-'.str_pad($row['mo'],2,'0',STR_PAD_LEFT);
        $sv=$saved[$mk]??null;
        $total_claimed=$sv?(float)$sv['total_claimed']:0;
        $claim_date=($sv&&!empty($sv['claim_date'])&&$sv['claim_date']!=='0000-00-00')?$sv['claim_date']:null;
        $handed_date=($sv&&!empty($sv['handed_over_date'])&&$sv['handed_over_date']!=='0000-00-00')?$sv['handed_over_date']:null;
        $total_dis=(float)$row['total_dis'];
        $to_be_claimed=$total_dis-$total_claimed;
        if($row['diff']>0.005)$dc='td-diff-pos';
        elseif($row['diff']<-0.005)$dc='td-diff-neg';
        else $dc='td-diff-zero';
        $diff_str=($row['diff']>0?'+':'').number_format($row['diff'],2);
        if($total_claimed==0)$tbc_cls='td-tbc-zero';
        elseif($to_be_claimed>0)$tbc_cls='td-tbc-pos';
        else $tbc_cls='td-tbc-neg';
    ?>
    <tr id="row-<?= $mk ?>" data-month="<?= $mk ?>" data-total-dis="<?= $total_dis ?>" class="<?= $total_claimed>0?'row-linked':'' ?>">
        <td class="td-month"><?= $month_names[(int)$row['mo']].' '.$row['yr'] ?></td>
        <td class="td-num"><?= number_format($row['inv_dmg'],2) ?></td>
        <td class="td-num"><?= number_format($row['mkt_tur'],2) ?></td>
        <td class="<?= $dc ?>"><?= $diff_str ?></td>
        <td class="td-num"><?= number_format($row['mkt_aip'],2) ?></td>
        <td class="td-num"><?= number_format($row['rsloc_aip'],2) ?></td>
        <td class="td-total"><?= number_format($total_dis,2) ?></td>
        <td class="td-date-cell">
            <div class="date-cell-wrap">
                <input type="date" class="date-input" id="handed-<?= $mk ?>"
                       value="<?= htmlspecialchars($handed_date??'') ?>"
                       onchange="saveHandedDate('<?= $mk ?>',this)">
                <?php if($handed_date): ?>
                <button class="btn-cell-clear" id="clr-handed-<?= $mk ?>"
                        onclick="clearHandedDate('<?= $mk ?>')"
                        title="Remove handed over date">
                    <i class="fa-solid fa-xmark"></i>
                </button>
                <?php endif; ?>
            </div>
        </td>
        <td class="td-claimed" id="claimed-<?= $mk ?>">
            <?php if($total_claimed>0): ?>
                <span class="cell-with-clear">
                    <?= number_format($total_claimed,2) ?>
                    <button class="btn-cell-clear" id="clr-claimed-<?= $mk ?>"
                            onclick="clearClaimedData('<?= $mk ?>')"
                            title="Remove claim data">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </span>
            <?php else: ?><span style="color:#d1d5db">—</span><?php endif; ?>
        </td>
        <td class="td-claimdate" id="claimdate-<?= $mk ?>">
            <?php if($claim_date): ?>
                <span class="cell-with-clear" style="justify-content:center;">
                    <span><i class="fa-regular fa-calendar" style="font-size:10px;margin-right:3px;"></i><?= date('d M Y',strtotime($claim_date)) ?></span>
                    <button class="btn-cell-clear" id="clr-claimdate-<?= $mk ?>"
                            onclick="clearClaimedData('<?= $mk ?>')"
                            title="Remove claim data">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </span>
            <?php else: ?><span style="color:#d1d5db">—</span><?php endif; ?>
        </td>
        <td class="<?= $tbc_cls ?>" id="tbc-<?= $mk ?>">
            <?= $total_claimed>0?(($to_be_claimed>0?'+':'').number_format($to_be_claimed,2)):'<span style="color:#d1d5db">—</span>' ?>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
    <?php if(!empty($report_rows)):
        $gt_claimed=array_sum(array_column($saved,'total_claimed'));
        $gt_tbc=$totals['total_dis']-$gt_claimed;
    ?>
    <tfoot><tr>
        <td class="tf-label">Grand Total</td>
        <td><?= number_format($totals['inv_dmg'],2) ?></td>
        <td><?= number_format($totals['dsp_mkt_tur'],2) ?></td>
        <td style="color:<?= $totals['diff']>=0?'#166534':'#991b1b' ?>"><?= ($totals['diff']>0?'+':'').number_format($totals['diff'],2) ?></td>
        <td><?= number_format($totals['dsp_mkt_aip'],2) ?></td>
        <td><?= number_format($totals['dsp_rsloc_aip'],2) ?></td>
        <td style="color:#6d28d9;"><?= number_format($totals['total_dis'],2) ?></td>
        <td></td>
        <td style="color:#065f46;"><?= $gt_claimed>0?number_format($gt_claimed,2):'—' ?></td>
        <td></td>
        <td style="color:<?= $gt_tbc>0?'#991b1b':'#166534' ?>;"><?= $gt_claimed>0?(($gt_tbc>0?'+':'').number_format($gt_tbc,2)):'—' ?></td>
    </tr></tfoot>
    <?php endif; ?>
</table>
</div></div>

<!-- Modal -->
<div class="modal-backdrop" id="reconModal" role="dialog" aria-modal="true">
    <div class="modal-box">
        <div class="modal-head">
            <h3 class="modal-title"><i class="fa-solid fa-scale-balanced" style="color:#065f46;font-size:16px;"></i> Customer Ledger — Claims Credit Reconciliation</h3>
            <button class="modal-close" onclick="closeRecon()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <p style="font-size:13px;color:#6b7280;margin:0 0 16px;line-height:1.6;">
                Select a report month for each date row, then click <strong>Update</strong> to save to the DB.
                Click <strong>Reverse</strong> to clear a previously saved entry.
            </p>
            <?php if(empty($recon_by_date)): ?>
                <div class="recon-empty"><i class="fa-solid fa-inbox" style="font-size:32px;display:block;margin-bottom:10px;color:#d1d5db;"></i>No claims credit entries found.</div>
            <?php else: ?>
            <table class="recon-table" id="reconTable">
                <thead><tr>
                    <th class="th-l">Transaction Date</th>
                    <th class="th-r"><span class="co-badge co-ulcl">ULCL</span>&nbsp;Credit</th>
                    <th class="th-r"><span class="co-badge co-usll">USLL</span>&nbsp;Credit</th>
                    <th class="th-r">Total</th>
                    <th class="th-c" style="min-width:158px;">Link to Month</th>
                    <th class="th-c" style="min-width:210px;">Actions</th>
                    <th class="th-c" style="min-width:88px;">Status</th>
                </tr></thead>
                <tbody>
                <?php
                $gt_r_ulcl=0; $gt_r_usll=0;
                foreach($recon_by_date as $d=>$rec):
                    $ulcl_v=$rec['ULCL']; $usll_v=$rec['USLL']; $row_tot=$ulcl_v+$usll_v;
                    $u_imp=$rec['ULCL_import']; $s_imp=$rec['USLL_import'];
                    $txn_mk=$rec['month_key']; $disp_d=date('d M Y',strtotime($d));
                    $gt_r_ulcl+=$ulcl_v; $gt_r_usll+=$usll_v;

                    // Find which report month this txn_date is saved against (search saved table)
                    $saved_mk='';
                    foreach($saved as $smk=>$sv){
                        if(!empty($sv['txn_date']) && $sv['txn_date']===$d && (float)$sv['total_claimed']>0){
                            $saved_mk=$smk; break;
                        }
                    }
                    // default dropdown: saved month first, then matching txn month if in report, else empty
                    if($saved_mk!==''){
                        $default_mk=$saved_mk;
                    } elseif(isset($month_options[$txn_mk])){
                        $default_mk=$txn_mk;
                    } else {
                        $default_mk='';
                    }
                    $already = $saved_mk!=='';
                ?>
                <tr id="mrow-<?= $d ?>" data-date="<?= $d ?>" data-total="<?= $row_tot ?>" data-saved-mk="<?= htmlspecialchars($saved_mk) ?>" class="<?= $already?'row-saved':'' ?>">
                    <td class="rt-date"><i class="fa-regular fa-calendar" style="font-size:11px;color:#9ca3af;margin-right:5px;"></i><?= htmlspecialchars($disp_d) ?></td>
                    <td class="rt-link">
                        <?php if($ulcl_v>0&&$u_imp): ?>
                            <a href="ulcl_view.php?id=<?= $u_imp ?>&txn_type=Claims+Credit" target="_blank"><?= number_format($ulcl_v,2) ?> <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:10px;"></i></a>
                        <?php else: ?><span class="rt-none">—</span><?php endif; ?>
                    </td>
                    <td class="rt-link">
                        <?php if($usll_v>0&&$s_imp): ?>
                            <a href="ulcl_view.php?id=<?= $s_imp ?>&txn_type=Claims+Credit" target="_blank"><?= number_format($usll_v,2) ?> <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:10px;"></i></a>
                        <?php else: ?><span class="rt-none">—</span><?php endif; ?>
                    </td>
                    <td class="rt-total"><?= number_format($row_tot,2) ?></td>
                    <td style="text-align:center;">
                        <select class="month-select <?= $already?'sel-active':'' ?>" id="sel-<?= $d ?>" onchange="onSelChange(this,'<?= $d ?>')">
                            <option value="">— Select month —</option>
                            <?php foreach($month_options as $opt_mk=>$opt_label): ?>
                            <option value="<?= $opt_mk ?>" <?= $opt_mk===$default_mk?'selected':'' ?>><?= htmlspecialchars($opt_label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td class="td-actions"><div class="action-btns">
                        <button class="btn-row-update <?= $already?'st-saved':'' ?>" id="updbtn-<?= $d ?>"
                                onclick="updateRow('<?= $d ?>')" <?= ($already||$default_mk!=='')?'':'disabled' ?>>
                            <i class="fa-solid fa-floppy-disk"></i> <?= $already?'Re-update':'Update' ?>
                        </button>
                        <button class="btn-row-reverse" id="revbtn-<?= $d ?>"
                                onclick="reverseRow('<?= $d ?>')" <?= $already?'':'disabled' ?>
                                title="Clear this entry from DB">
                            <i class="fa-solid fa-rotate-left"></i> Reverse
                        </button>
                    </div></td>
                    <td id="status-<?= $d ?>" style="text-align:center;">
                        <?php if($already): ?>
                            <span class="row-status rs-saved"><i class="fa-solid fa-check" style="font-size:10px;"></i> Saved</span>
                        <?php else: ?>
                            <span class="row-status rs-pending"><i class="fa-solid fa-clock" style="font-size:10px;"></i> Pending</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="recon-foot"><tr>
                    <td class="tf-lbl">Grand Total</td>
                    <td><?= number_format($gt_r_ulcl,2) ?></td>
                    <td><?= number_format($gt_r_usll,2) ?></td>
                    <td style="color:#6d28d9;"><?= number_format($gt_r_ulcl+$gt_r_usll,2) ?></td>
                    <td colspan="3"></td>
                </tr></tfoot>
            </table>
            <?php endif; ?>
        </div>
        <div class="modal-foot">
            <span class="modal-foot-info" id="reconInfo"></span>
            <button class="btn btn-secondary" onclick="closeRecon()"><i class="fa-solid fa-xmark"></i> Close</button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
var PAGE_URL='<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>';

function openRecon(){document.getElementById('reconModal').classList.add('open');document.body.style.overflow='hidden';updateFooterInfo();}
function closeRecon(){
    document.getElementById('reconModal').classList.remove('open');
    document.body.style.overflow='';
    // clear all row outlines from main table
    document.querySelectorAll('#mainTable tbody tr').forEach(function(r){r.style.outline='';});
}
document.getElementById('reconModal').addEventListener('click',function(e){if(e.target===this)closeRecon();});
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeRecon();});

function onSelChange(sel,date){
    sel.classList.toggle('sel-active',!!sel.value);
    var upd=document.getElementById('updbtn-'+date);
    if(upd){
        upd.disabled=!sel.value;
        if(sel.value){upd.classList.remove('st-saved','st-error');upd.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Update';}
    }
    // highlight matching main table row so user can see the link
    if(sel.value){
        document.querySelectorAll('#mainTable tbody tr').forEach(function(r){r.style.outline='';});
        var mrow=document.getElementById('row-'+sel.value);
        if(mrow) mrow.style.outline='2px solid #3b82f6';
    } else {
        document.querySelectorAll('#mainTable tbody tr').forEach(function(r){r.style.outline='';});
    }
    updateFooterInfo();
}
function updateFooterInfo(){
    var ready=0;document.querySelectorAll('.month-select').forEach(function(s){if(s.value)ready++;});
    var info=document.getElementById('reconInfo');if(!info)return;
    info.innerHTML=ready
        ?'<i class="fa-solid fa-circle-check" style="color:#22c55e;margin-right:4px;"></i><strong>'+ready+'</strong> row'+(ready>1?'s':'')+' ready.'
        :'<i class="fa-solid fa-circle-info" style="color:#93c5fd;margin-right:4px;"></i>Select a month then click Update.';
}

function updateRow(date){
    var sel=document.getElementById('sel-'+date);
    var upd=document.getElementById('updbtn-'+date);
    var rev=document.getElementById('revbtn-'+date);
    var srow=document.getElementById('mrow-'+date);
    var stat=document.getElementById('status-'+date);
    if(!sel||!sel.value){showToast('Select a month first.','err');return;}
    var mk=sel.value;
    var total=parseFloat(srow.dataset.total)||0;
    setBtnState(upd,'saving','<i class="fa-solid fa-spinner fa-spin"></i> Saving…');
    postAction({action:'save_recon',month_key:mk,txn_date:date,total_claimed:total,claim_date:date})
    .then(function(data){
        if(!data.ok)throw new Error(data.msg||'DB error.');
        setBtnState(upd,'saved','<i class="fa-solid fa-rotate-right"></i> Re-update');
        upd.disabled=false;
        if(rev){rev.disabled=false;}
        // store the saved month key on the row so reverse knows which month to clear
        if(srow) srow.dataset.savedMk=mk;
        srow.classList.remove('row-error','row-reversed');srow.classList.add('row-saved');
        stat.innerHTML=badge('saved','fa-check','Saved');
        updateMainRow(mk,total,date);
        showToast('Saved successfully.','ok');
        updateFooterInfo();
    })
    .catch(function(err){
        setBtnState(upd,'error','<i class="fa-solid fa-triangle-exclamation"></i> Retry');
        upd.disabled=false;
        srow.classList.remove('row-saved');srow.classList.add('row-error');
        stat.innerHTML=badge('error','fa-xmark','Error');
        showToast('Failed: '+err.message,'err');
    });
}

function reverseRow(date){
    var srow=document.getElementById('mrow-'+date);
    var mk=srow?srow.dataset.savedMk:'';
    // also check current dropdown value as fallback
    if(!mk){var sel=document.getElementById('sel-'+date);if(sel)mk=sel.value;}
    var upd=document.getElementById('updbtn-'+date);
    var rev=document.getElementById('revbtn-'+date);
    var stat=document.getElementById('status-'+date);
    if(!mk){showToast('No saved month found for this row.','err');return;}
    if(!confirm('Clear the reconciliation for this row from the database?'))return;
    setBtnState(rev,'saving','<i class="fa-solid fa-spinner fa-spin"></i> Reversing…');
    postAction({action:'reverse_recon',month_key:mk})
    .then(function(data){
        if(!data.ok)throw new Error(data.msg||'DB error.');
        setBtnState(rev,'','<i class="fa-solid fa-rotate-left"></i> Reverse');
        rev.disabled=true;
        if(upd){upd.classList.remove('st-saved','st-error');upd.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Update';}
        if(srow){srow.classList.remove('row-saved','row-error');srow.classList.add('row-reversed');srow.dataset.savedMk='';}
        stat.innerHTML=badge('reversed','fa-rotate-left','Reversed');
        clearMainRow(mk);
        showToast('Reconciliation cleared.','ok');
    })
    .catch(function(err){
        setBtnState(rev,'','<i class="fa-solid fa-rotate-left"></i> Reverse');
        rev.disabled=false;
        stat.innerHTML=badge('error','fa-xmark','Error');
        showToast('Reverse failed: '+err.message,'err');
    });
}

function updateMainRow(mk,totalClaimed,claimDate){
    var row=document.getElementById('row-'+mk);if(!row)return;
    var totalDis=parseFloat(row.dataset.totalDis)||0;
    var tbc=totalDis-totalClaimed;
    row.classList.add('row-linked');
    // claimed cell
    var cc=document.getElementById('claimed-'+mk);
    if(cc){
        var fmt=totalClaimed>0?totalClaimed.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}):'—';
        if(totalClaimed>0){
            cc.innerHTML='<span class="cell-with-clear">'+fmt
                +'<button class="btn-cell-clear" onclick="clearClaimedData(\''+mk+'\')" title="Remove claim data"><i class="fa-solid fa-xmark"></i></button></span>';
        } else {
            cc.innerHTML='<span style="color:#d1d5db">—</span>';
        }
    }
    // claim date cell
    var dc=document.getElementById('claimdate-'+mk);
    if(dc&&claimDate){
        var d=new Date(claimDate),mn=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        var fmtd=String(d.getUTCDate()).padStart(2,'0')+' '+mn[d.getUTCMonth()]+' '+d.getUTCFullYear();
        dc.innerHTML='<span class="cell-with-clear" style="justify-content:center;">'
            +'<span><i class="fa-regular fa-calendar" style="font-size:10px;margin-right:3px;"></i>'+fmtd+'</span>'
            +'<button class="btn-cell-clear" onclick="clearClaimedData(\''+mk+'\')" title="Remove claim data"><i class="fa-solid fa-xmark"></i></button></span>';
    }
    // to be claimed
    var tc=document.getElementById('tbc-'+mk);
    if(tc){
        if(totalClaimed>0){
            tc.className=tbc>0?'td-tbc-pos':(tbc<0?'td-tbc-neg':'td-tbc-zero');
            tc.textContent=(tbc>0?'+':'')+tbc.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
        } else {
            tc.className='td-tbc-zero';tc.innerHTML='<span style="color:#d1d5db">—</span>';
        }
    }
}
function clearMainRow(mk){
    var row=document.getElementById('row-'+mk);if(row)row.classList.remove('row-linked');
    var cc=document.getElementById('claimed-'+mk);if(cc)cc.innerHTML='<span style="color:#d1d5db">—</span>';
    var dc=document.getElementById('claimdate-'+mk);if(dc)dc.innerHTML='<span style="color:#d1d5db">—</span>';
    var tc=document.getElementById('tbc-'+mk);if(tc){tc.className='td-tbc-zero';tc.innerHTML='<span style="color:#d1d5db">—</span>';}
}
// Clear handed over date from main table
function clearHandedDate(mk){
    if(!confirm('Remove the handed over date for this row?'))return;
    var inp=document.getElementById('handed-'+mk);
    var clrBtn=document.getElementById('clr-handed-'+mk);
    postAction({action:'clear_handed',month_key:mk})
    .then(function(data){
        if(!data.ok)throw new Error(data.msg);
        if(inp)inp.value='';
        if(clrBtn)clrBtn.remove();
        showToast('Handed over date removed.','ok');
    })
    .catch(function(err){ showToast('Failed: '+err.message,'err'); });
}
// Clear total claimed + claim date from main table
function clearClaimedData(mk){
    if(!confirm('Remove the claim data (Total Claimed, Claim Date) for this month?'))return;
    postAction({action:'clear_claimed',month_key:mk})
    .then(function(data){
        if(!data.ok)throw new Error(data.msg);
        clearMainRow(mk);
        showToast('Claim data removed.','ok');
    })
    .catch(function(err){ showToast('Failed: '+err.message,'err'); });
}
// Also update saveHandedDate to show/hide clear button after save
function saveHandedDate(mk,input){
    var orig=input.style.borderColor;input.style.borderColor='#fde68a';
    postAction({action:'save_handed',month_key:mk,handed_over_date:input.value})
    .then(function(data){
        if(!data.ok)throw new Error(data.msg);
        input.style.borderColor='#86efac';
        showToast('Handed over date saved.','ok');
        // show clear button if not already there
        if(input.value){
            var wrap=input.parentElement;
            if(wrap&&!document.getElementById('clr-handed-'+mk)){
                var btn=document.createElement('button');
                btn.className='btn-cell-clear';btn.id='clr-handed-'+mk;
                btn.title='Remove handed over date';
                btn.innerHTML='<i class="fa-solid fa-xmark"></i>';
                btn.onclick=function(){clearHandedDate(mk);};
                wrap.appendChild(btn);
            }
        } else {
            // date cleared via input — remove clear button
            var clrBtn=document.getElementById('clr-handed-'+mk);
            if(clrBtn)clrBtn.remove();
        }
        setTimeout(function(){input.style.borderColor=orig;},2000);
    })
    .catch(function(err){
        input.style.borderColor='#fca5a5';
        showToast('Save failed: '+err.message,'err');
        setTimeout(function(){input.style.borderColor=orig;},3000);
    });
}

// Shared fetch — strips whitespace/BOM, catches all error types
function postAction(params){
    var body=Object.keys(params).map(function(k){return encodeURIComponent(k)+'='+encodeURIComponent(params[k]);}).join('&');
    return fetch(PAGE_URL,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
    .then(function(res){if(!res.ok)throw new Error('HTTP '+res.status+' — '+res.statusText);return res.text();})
    .then(function(text){
        var clean=text.replace(/^[\s\uFEFF\xA0]+/,'');
        if(!clean.startsWith('{'))throw new Error('Non-JSON from server: '+clean.substring(0,120));
        try{return JSON.parse(clean);}catch(e){throw new Error('JSON parse error: '+clean.substring(0,120));}
    });
}
function setBtnState(btn,cls,html){if(!btn)return;btn.className=btn.className.replace(/\bst-\w+/g,'').trim();if(cls)btn.classList.add('st-'+cls);btn.innerHTML=html;btn.disabled=true;}
function badge(type,icon,label){var m={saved:'rs-saved',reversed:'rs-reversed',error:'rs-error',pending:'rs-pending'};return '<span class="row-status '+(m[type]||'rs-pending')+'"><i class="fa-solid '+icon+'" style="font-size:10px;"></i> '+label+'</span>';}
function showToast(msg,type){var t=document.getElementById('toast');t.className='toast toast-'+(type||'ok');t.innerHTML=(type==='err'?'<i class="fa-solid fa-triangle-exclamation"></i> ':'<i class="fa-solid fa-circle-check"></i> ')+msg;t.classList.add('show');clearTimeout(t._t);t._t=setTimeout(function(){t.classList.remove('show');},4000);}
updateFooterInfo();
</script>

<?php include 'footer.php'; ?>