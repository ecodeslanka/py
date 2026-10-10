<?php
/**
 * ETF BATCHES LIST PAGE
 * Shows all saved ETF batches with:
 *  - Payroll month, total ETF contribution
 *  - View modal with employee details
 *  - Single payment slip upload (with date)
 *  - Payment status tracker
 *
 * DB TABLE (auto-created):
 *   etf_batch_payment_slips — one slip per batch
 */
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'config.php';

// ── Auto-create tables ────────────────────────────────────────────────────────
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS etf_batches (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        payroll_period_id INT NOT NULL,
        batch_label       VARCHAR(20),
        submission_no     INT DEFAULT 1,
        total_members     INT           NOT NULL DEFAULT 0,
        total_etf         DECIMAL(14,2) NOT NULL DEFAULT 0,
        status            VARCHAR(20)   DEFAULT 'Pending',
        notes             TEXT,
        created_by        INT,
        created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_period (payroll_period_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS etf_batch_entries (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        batch_id    INT NOT NULL,
        employee_id INT NOT NULL,
        epf_number  VARCHAR(20),
        nic         VARCHAR(20),
        full_name   VARCHAR(200),
        surname     VARCHAR(100),
        initials    VARCHAR(50),
        basic       DECIMAL(12,2) DEFAULT 0,
        etf_er      DECIMAL(12,2) DEFAULT 0,
        att_days    INT           DEFAULT 0,
        source      VARCHAR(10)   DEFAULT 'live',
        FOREIGN KEY (batch_id) REFERENCES etf_batches(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS etf_batch_payment_slips (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        batch_id    INT NOT NULL,
        slip_date   DATE,
        file_name   VARCHAR(255),
        file_path   VARCHAR(500),
        file_size   INT,
        notes       TEXT,
        uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_batch (batch_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Ensure uploads dir
$upload_dir = __DIR__ . '/uploads/etf_slips/';
if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);

$month_names = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

// ── AJAX: POST handlers ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    // Upload slip
    if ($_POST['action'] === 'upload_slip') {
        $batch_id  = intval($_POST['batch_id'] ?? 0);
        $slip_date = mysqli_real_escape_string($conn, $_POST['slip_date'] ?? '');
        $notes     = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');

        if (!$batch_id) { echo json_encode(['success'=>false,'msg'=>'Invalid batch ID']); exit; }

        $file_name = ''; $file_path = ''; $file_size = 0;

        if (isset($_FILES['slip_file']) && $_FILES['slip_file']['error'] === UPLOAD_ERR_OK) {
            $orig    = basename($_FILES['slip_file']['name']);
            $ext     = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            $allowed = ['pdf','jpg','jpeg','png','webp'];
            if (!in_array($ext, $allowed)) {
                echo json_encode(['success'=>false,'msg'=>'File type not allowed. Use PDF, JPG, PNG.']); exit;
            }
            $file_name = 'etf_b'.$batch_id.'_slip_'.time().'.'.$ext;
            $dest      = $upload_dir . $file_name;
            $rel_path  = 'uploads/etf_slips/' . $file_name;
            if (!move_uploaded_file($_FILES['slip_file']['tmp_name'], $dest)) {
                echo json_encode(['success'=>false,'msg'=>'File upload failed']); exit;
            }
            $file_size = $_FILES['slip_file']['size'];
            $file_path = $rel_path;
        }

        // Delete old file if replacing
        $old = mysqli_query($conn, "SELECT file_path FROM etf_batch_payment_slips WHERE batch_id=$batch_id LIMIT 1");
        if ($old && mysqli_num_rows($old) > 0) {
            $ov = mysqli_fetch_assoc($old);
            if ($ov['file_path'] && file_exists(__DIR__.'/'.$ov['file_path'])) @unlink(__DIR__.'/'.$ov['file_path']);
        }

        $slip_date_sql = $slip_date ? "'$slip_date'" : 'NULL';
        mysqli_query($conn, "
            INSERT INTO etf_batch_payment_slips (batch_id, slip_date, file_name, file_path, file_size, notes)
            VALUES ($batch_id, $slip_date_sql, '$file_name', '$file_path', $file_size, '$notes')
            ON DUPLICATE KEY UPDATE
                slip_date=VALUES(slip_date), file_name=VALUES(file_name),
                file_path=VALUES(file_path), file_size=VALUES(file_size),
                notes=VALUES(notes), uploaded_at=NOW()
        ");

        // Update batch status
        $has_slip = !empty($file_path);
        if ($has_slip) {
            mysqli_query($conn, "UPDATE etf_batches SET status='Paid' WHERE id=$batch_id");
        }

        echo json_encode(['success'=>true,'msg'=>'Payment slip saved!','file_path'=>$file_path]);
        exit;
    }

    // Delete slip
    if ($_POST['action'] === 'delete_slip') {
        $batch_id = intval($_POST['batch_id'] ?? 0);
        if ($batch_id) {
            $old = mysqli_query($conn, "SELECT file_path FROM etf_batch_payment_slips WHERE batch_id=$batch_id LIMIT 1");
            if ($old && mysqli_num_rows($old) > 0) {
                $ov = mysqli_fetch_assoc($old);
                if ($ov['file_path'] && file_exists(__DIR__.'/'.$ov['file_path'])) @unlink(__DIR__.'/'.$ov['file_path']);
            }
            mysqli_query($conn, "DELETE FROM etf_batch_payment_slips WHERE batch_id=$batch_id");
            mysqli_query($conn, "UPDATE etf_batches SET status='Pending' WHERE id=$batch_id");
            echo json_encode(['success'=>true,'msg'=>'Slip removed.']); exit;
        }
        echo json_encode(['success'=>false,'msg'=>'Invalid']); exit;
    }

    // Delete batch
    if ($_POST['action'] === 'delete_batch') {
        $bid = intval($_POST['batch_id'] ?? 0);
        if ($bid) {
            $slips = mysqli_query($conn, "SELECT file_path FROM etf_batch_payment_slips WHERE batch_id=$bid");
            if ($slips) while ($s = mysqli_fetch_assoc($slips)) {
                if ($s['file_path'] && file_exists(__DIR__.'/'.$s['file_path'])) @unlink(__DIR__.'/'.$s['file_path']);
            }
            mysqli_query($conn, "DELETE FROM etf_batches WHERE id=$bid");
            echo json_encode(['success'=>true,'msg'=>'Batch deleted.']); exit;
        }
        echo json_encode(['success'=>false,'msg'=>'Invalid']); exit;
    }

    echo json_encode(['success'=>false,'msg'=>'Unknown action']); exit;
}

// ── Fetch all batches ─────────────────────────────────────────────────────────
$batches_res = mysqli_query($conn, "
    SELECT b.*,
           pp.year, pp.month, pp.status AS period_status,
           (SELECT file_path FROM etf_batch_payment_slips WHERE batch_id=b.id LIMIT 1) AS slip_path,
           (SELECT slip_date FROM etf_batch_payment_slips WHERE batch_id=b.id LIMIT 1) AS slip_date,
           (SELECT file_name FROM etf_batch_payment_slips WHERE batch_id=b.id LIMIT 1) AS slip_name
    FROM etf_batches b
    LEFT JOIN payroll_periods pp ON b.payroll_period_id = pp.id
    ORDER BY b.created_at DESC
");
$batches = [];
while ($row = mysqli_fetch_assoc($batches_res)) $batches[] = $row;

// ── Summary totals ────────────────────────────────────────────────────────────
$grand_etf  = array_sum(array_column($batches, 'total_etf'));
$grand_emp  = array_sum(array_column($batches, 'total_members'));
$paid_count = count(array_filter($batches, fn($b) => !empty($b['slip_path'])));
$pending    = count($batches) - $paid_count;

// ── Build slip map for JS ─────────────────────────────────────────────────────
$slip_map = [];
foreach ($batches as $b) {
    $slip_map[$b['id']] = [
        'slip_path' => $b['slip_path'] ?? '',
        'slip_date' => $b['slip_date'] ?? '',
        'slip_name' => $b['slip_name'] ?? '',
    ];
}

include 'header.php';
?>
<style>
:root{--tk:#0d9488;--tm:#14b8a6;--tl:#f0fdfa;--tt:#ccfbf1;--dk:#0c4a6e;--md:#0369a1;--lt:#e0f2fe;--gn:#166534;--gl:#dcfce7;--am:#92400e;--al:#fef3c7;--rd:#991b1b;--rl:#fee2e2;--pu:#7c3aed;--pl:#f3e8ff;--bd:#e2e8f0;}
/* Header */
.bh{background:linear-gradient(135deg,var(--tk),var(--tm));color:#fff;padding:22px 28px 18px;border-radius:0 0 14px 14px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;}
.bh-t{font-size:21px;font-weight:800;display:flex;align-items:center;gap:10px;}
.bh-s{font-size:12px;color:#99f6e4;margin-top:3px;}
.bh-acts{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
/* Buttons */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 15px;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;border:none;text-decoration:none;font-family:inherit;white-space:nowrap;transition:all .18s;}
.btn-back{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);}.btn-back:hover{background:rgba(255,255,255,.25);}
.btn-new{background:var(--gn);color:#fff;}.btn-new:hover{background:#14532d;}
.btn-view{background:var(--tl);color:var(--tk);border:1px solid var(--tt);}.btn-view:hover{background:var(--tt);}
.btn-del{background:var(--rl);color:var(--rd);border:1px solid #fca5a5;}.btn-del:hover{background:#fca5a5;}
.btn-upload{background:var(--tk);color:#fff;}.btn-upload:hover{background:#0f766e;}
.btn-ghost{background:#f8fafc;color:#374151;border:1px solid var(--bd);}.btn-ghost:hover{background:var(--bd);}
.btn-report{background:var(--lt);color:var(--dk);border:1px solid #bae6fd;}.btn-report:hover{background:#bae6fd;}
/* Stat strip */
.stats{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;}
.sc{background:#fff;border:1px solid var(--bd);border-radius:11px;padding:13px 16px;flex:1;min-width:140px;position:relative;overflow:hidden;}
.sc::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;}
.sc.s-tot::before{background:var(--tk);}
.sc.s-emp::before{background:var(--md);}
.sc.s-etf::before{background:var(--pu);}
.sc.s-pd::before{background:var(--gn);}
.sc.s-pe::before{background:var(--am);}
.sc-lb{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:4px;}
.sc-vl{font-size:22px;font-weight:900;letter-spacing:-1px;}
.sc.s-tot .sc-vl{color:var(--tk);}
.sc.s-emp .sc-vl{color:var(--md);}
.sc.s-etf .sc-vl{color:var(--pu);font-size:16px;}
.sc.s-pd  .sc-vl{color:var(--gn);}
.sc.s-pe  .sc-vl{color:var(--am);}
.sc-sb{font-size:11px;color:#64748b;margin-top:2px;}
/* Batch cards grid */
.bg{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px;margin-bottom:20px;}
.bcard{background:#fff;border:1px solid var(--bd);border-radius:13px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.05);transition:box-shadow .2s;}
.bcard:hover{box-shadow:0 4px 18px rgba(0,0,0,.1);}
.bcard-head{background:linear-gradient(135deg,var(--tk),var(--tm));color:#fff;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;}
.bcard-title{font-size:14px;font-weight:800;}
.bcard-sub{font-size:11px;color:#99f6e4;margin-top:2px;}
.bcard-badge{padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.bcard-body{padding:16px 18px;}
.bcard-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;}
.bcard-row:last-child{margin-bottom:0;}
.bcard-key{font-size:11px;color:#64748b;font-weight:600;}
.bcard-val{font-size:13px;font-weight:800;color:var(--dk);}
.bcard-val.vg{color:var(--gn);}
.bcard-val.vt{color:var(--tk);}
.bcard-val.vp{color:var(--pu);}
.bcard-divider{border:none;border-top:1px solid var(--bd);margin:10px 0;}
/* Slip row */
.slip-row{display:flex;align-items:center;gap:8px;padding:9px 11px;border-radius:8px;font-size:12px;}
.slip-row.sl-paid{background:#f0fdfa;border:1px solid #5eead4;}
.slip-row.sl-miss{background:#f8fafc;border:1px solid var(--bd);}
.slip-icon{width:30px;height:30px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;}
.slip-icon.si-paid{background:var(--tt);color:var(--tk);}
.slip-icon.si-miss{background:#f1f5f9;color:#94a3b8;}
.slip-txt{flex:1;}
.slip-lbl{font-weight:700;}
.slip-dt{font-size:10px;color:#64748b;}
.slip-actions{display:flex;gap:5px;}
.bcard-acts{padding:0 18px 16px;display:flex;gap:8px;flex-wrap:wrap;}
/* Empty state */
.empty-state{text-align:center;padding:70px 20px;color:#94a3b8;}
.empty-state i{font-size:52px;color:#e2e8f0;margin-bottom:14px;display:block;}
.empty-state h3{font-size:16px;font-weight:700;color:#64748b;margin-bottom:6px;}
/* Modals */
.mo{position:fixed;inset:0;background:rgba(0,0,0,.52);z-index:9000;display:none;align-items:center;justify-content:center;padding:20px;}
.mo.open{display:flex;}
/* View modal */
.mv{background:#fff;border-radius:16px;width:820px;max-width:96vw;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.28);overflow:hidden;}
.mv-head{background:linear-gradient(135deg,var(--tk),var(--tm));color:#fff;padding:18px 24px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.mv-head h3{margin:0;font-size:15px;font-weight:800;}
.mv-body{flex:1;overflow-y:auto;}
.btn-close{background:rgba(255,255,255,.2);border:none;color:#fff;width:30px;height:30px;border-radius:50%;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;}
.btn-close:hover{background:rgba(255,255,255,.35);}
/* Summary block in view modal */
.vm-summary{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;padding:16px 22px;background:#f8fafc;border-bottom:1px solid var(--bd);}
.vms-card{background:#fff;border:1px solid var(--bd);border-radius:9px;padding:11px 14px;}
.vms-lb{font-size:10px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:.3px;margin-bottom:3px;}
.vms-vl{font-size:15px;font-weight:900;font-family:'Courier New',monospace;}
.vms-vl.c-tk{color:var(--tk);}
.vms-vl.c-pu{color:var(--pu);}
.vms-vl.c-dk{color:var(--dk);}
/* Slip section in view modal */
.slip-section{padding:16px 22px;border-bottom:1px solid var(--bd);}
.slip-sec-title{font-size:13px;font-weight:800;color:var(--tk);margin-bottom:12px;display:flex;align-items:center;gap:7px;}
.slip-panel{border:1.5px solid #5eead4;border-radius:10px;overflow:hidden;max-width:420px;}
.slip-panel-hdr{display:flex;align-items:center;gap:8px;padding:10px 14px;font-size:12px;font-weight:800;background:#f0fdfa;border-bottom:1px solid #99f6e4;color:var(--tk);}
.slip-panel-body{padding:14px;}
.slip-preview{display:flex;align-items:center;gap:10px;background:#f8fafc;border:1px solid var(--bd);border-radius:7px;padding:10px 12px;margin-bottom:10px;}
.slip-preview-icon{width:36px;height:36px;border-radius:7px;background:var(--tl);color:var(--tk);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.slip-preview-info{flex:1;}
.slip-preview-name{font-size:12px;font-weight:700;color:var(--dk);}
.slip-preview-date{font-size:11px;color:#64748b;}
/* Upload form */
.up-form{display:flex;flex-direction:column;gap:8px;}
.up-row{display:grid;grid-template-columns:1fr 1fr;gap:8px;}
.up-field label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#64748b;display:block;margin-bottom:4px;}
.up-field input{width:100%;padding:8px 10px;border:1.5px solid var(--bd);border-radius:7px;font-size:12px;font-family:inherit;outline:none;box-sizing:border-box;}
.up-field input:focus{border-color:var(--tk);}
.up-field input[type=file]{padding:5px 8px;cursor:pointer;}
/* Entries table */
.ent-section{padding:0 22px 16px;}
.ent-title{font-size:13px;font-weight:800;color:var(--tk);margin-bottom:10px;padding-top:16px;display:flex;align-items:center;gap:7px;}
.ent-tbl{width:100%;border-collapse:collapse;font-size:11px;}
.ent-tbl thead tr{background:#1e293b;}
.ent-tbl thead th{padding:8px 10px;color:#94a3b8;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;text-align:right;border-right:1px solid #2d3748;white-space:nowrap;}
.ent-tbl thead th.tl{text-align:left;}.ent-tbl thead th.tc{text-align:center;}
.ent-tbl thead .t-id{background:#134e4a!important;color:#5eead4;}
.ent-tbl thead .t-nm{background:#1e293b!important;}
.ent-tbl thead .t-etf{background:#134e4a!important;color:#5eead4;}
.ent-tbl thead .t-tot{background:#4c1d95!important;color:#c4b5fd;}
.ent-tbl tbody tr{border-bottom:1px solid #f1f5f9;}
.ent-tbl tbody tr:nth-child(even){background:#f0fdfa;}
.ent-tbl tbody tr:hover{background:#ccfbf1!important;}
.ent-tbl td{padding:8px 10px;vertical-align:middle;text-align:right;}
.ent-tbl td.tl{text-align:left;}.ent-tbl td.tc{text-align:center;}
.ent-tbl .tr-tot td{background:#1e293b;color:#e2e8f0;font-weight:700;padding:9px 10px;border-right:1px solid #2d3748;}
.nc{font-family:'Courier New',monospace;font-size:10px;font-weight:700;color:#0f766e;background:#f0fdfa;padding:1px 5px;border-radius:3px;}
.ec-t{font-family:'Courier New',monospace;font-size:10px;font-weight:700;color:var(--tk);background:var(--tl);padding:1px 5px;border-radius:3px;}
.mt{font-family:'Courier New',monospace;font-weight:800;color:var(--tk);font-size:12px;}
.ac{display:inline-block;font-family:monospace;font-size:10px;font-weight:700;padding:1px 5px;border-radius:3px;background:#f1f5f9;color:#475569;}
.ac.ok{background:var(--gl);color:var(--gn);}
/* Small modal (slip upload) */
.ms{background:#fff;border-radius:13px;width:460px;max-width:94vw;box-shadow:0 20px 50px rgba(0,0,0,.26);overflow:hidden;}
.ms-head{padding:16px 20px;display:flex;align-items:center;gap:10px;background:var(--tl);border-bottom:1.5px solid #5eead4;}
.ms-head h3{margin:0;font-size:14px;font-weight:800;color:var(--tk);}
.ms-body{padding:18px 20px;}
.ms-ft{padding:13px 20px;background:#f8fafc;border-top:1px solid var(--bd);display:flex;gap:8px;justify-content:flex-end;}
.btn-cn{padding:8px 16px;border-radius:7px;border:1px solid var(--bd);background:#fff;color:#374151;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;}
.btn-ok{padding:8px 20px;border-radius:7px;border:none;color:#fff;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:6px;background:var(--tk);}
.btn-ok:hover{background:#0f766e;}.btn-ok:disabled{opacity:.6;cursor:not-allowed;}
/* Toast */
.toast{position:fixed;bottom:26px;right:26px;background:#1e293b;color:#fff;padding:13px 20px;border-radius:9px;font-size:13px;font-weight:700;z-index:99999;transform:translateY(70px);opacity:0;transition:all .32s cubic-bezier(.34,1.56,.64,1);display:flex;align-items:center;gap:9px;}
.toast.show{transform:translateY(0);opacity:1;}
.toast.t-s{background:#0f766e;}.toast.t-e{background:#991b1b;}
@media(max-width:700px){.vm-summary{grid-template-columns:1fr 1fr;}.bg{grid-template-columns:1fr;}}
</style>

<!-- PAGE HEADER -->
<div class="bh">
    <div>
        <div class="bh-t"><i class="fa-solid fa-building-columns"></i> ETF Batches</div>
        <div class="bh-s"><?php echo count($batches); ?> batch<?php echo count($batches)!=1?'es':'';?> saved · Grand Total ETF: LKR <?php echo number_format($grand_etf,2);?></div>
    </div>
    <div class="bh-acts">
        <a href="etf_report.php" class="btn btn-back"><i class="fa-solid fa-arrow-left"></i> Back to Report</a>
        <a href="etf_report.php" class="btn btn-new"><i class="fa-solid fa-plus"></i> New Batch</a>
    </div>
</div>

<!-- STAT STRIP -->
<div class="stats">
    <div class="sc s-tot">
        <div class="sc-lb"><i class="fa-solid fa-layer-group"></i> Total Batches</div>
        <div class="sc-vl"><?php echo count($batches);?></div>
        <div class="sc-sb">Payroll periods</div>
    </div>
    <div class="sc s-emp">
        <div class="sc-lb"><i class="fa-solid fa-users"></i> Total Employees</div>
        <div class="sc-vl"><?php echo $grand_emp;?></div>
        <div class="sc-sb">Across all batches</div>
    </div>
    <div class="sc s-etf">
        <div class="sc-lb"><i class="fa-solid fa-sigma"></i> Grand Total ETF</div>
        <div class="sc-vl">LKR <?php echo number_format($grand_etf,2);?></div>
        <div class="sc-sb">All batches combined</div>
    </div>
    <div class="sc s-pd">
        <div class="sc-lb"><i class="fa-solid fa-circle-check"></i> Paid (Slip Uploaded)</div>
        <div class="sc-vl"><?php echo $paid_count;?></div>
        <div class="sc-sb">Batches with payment slip</div>
    </div>
    <div class="sc s-pe">
        <div class="sc-lb"><i class="fa-solid fa-clock"></i> Pending</div>
        <div class="sc-vl"><?php echo $pending;?></div>
        <div class="sc-sb">No slip yet</div>
    </div>
</div>

<!-- BATCH CARDS -->
<?php if (empty($batches)): ?>
<div class="empty-state">
    <i class="fa-solid fa-building-columns"></i>
    <h3>No ETF Batches Yet</h3>
    <p>Go to the ETF Report and click <strong>Save Batch</strong> after reviewing contributions for a payroll period.</p>
    <a href="etf_report.php" class="btn btn-new" style="margin-top:16px;display:inline-flex;"><i class="fa-solid fa-arrow-right"></i> Go to ETF Report</a>
</div>
<?php else: ?>
<div class="bg">
<?php foreach ($batches as $b):
    $period_lbl = $b['year'] ? $month_names[$b['month']].' '.$b['year'] : '—';
    $has_slip   = !empty($b['slip_path']);
    $pay_status = $has_slip ? 'Paid' : 'Pending';
    $ps_style   = $has_slip
        ? 'background:rgba(240,253,250,.9);color:#0f766e;border:1px solid #5eead4;'
        : 'background:rgba(255,255,255,.18);color:#fff;border:1px solid rgba(255,255,255,.3);';
?>
<div class="bcard" id="bcard-<?php echo $b['id'];?>">
    <div class="bcard-head">
        <div>
            <div class="bcard-title"><i class="fa-solid fa-calendar-check"></i> <?php echo $period_lbl;?></div>
            <div class="bcard-sub">
                Saved <?php echo date('d M Y', strtotime($b['created_at']));?>
                &nbsp;·&nbsp; <?php echo $b['total_members'];?> employees
            </div>
        </div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;">
            <div class="bcard-badge" style="<?php echo $ps_style;?>"><?php echo $pay_status;?></div>
            <?php if ($b['period_status']): ?>
            <div class="bcard-badge" style="background:rgba(255,255,255,.15);color:#fff;"><?php echo htmlspecialchars($b['period_status']);?></div>
            <?php endif;?>
        </div>
    </div>

    <div class="bcard-body">
        <!-- ETF Amounts -->
        <div class="bcard-row">
            <span class="bcard-key">Total Members</span>
            <span class="bcard-val"><?php echo $b['total_members'];?> employees</span>
        </div>
        <div class="bcard-row" style="border-top:1.5px solid var(--bd);padding-top:8px;margin-top:4px;">
            <span class="bcard-key" style="font-weight:800;color:#374151;">Total ETF Contribution</span>
            <span class="bcard-val vt" style="font-size:16px;font-family:'Courier New',monospace;">LKR <?php echo number_format($b['total_etf'],2);?></span>
        </div>

        <hr class="bcard-divider">

        <!-- Payment Slip -->
        <?php if ($has_slip): ?>
        <div class="slip-row sl-paid">
            <div class="slip-icon si-paid"><i class="fa-solid fa-file-invoice"></i></div>
            <div class="slip-txt">
                <div class="slip-lbl" style="color:var(--tk);">Payment Slip</div>
                <div class="slip-dt">
                    <?php echo $b['slip_date'] ? date('d M Y', strtotime($b['slip_date'])) : 'No date set'; ?>
                    &nbsp;·&nbsp;
                    <a href="<?php echo htmlspecialchars($b['slip_path']);?>" target="_blank" style="color:var(--tk);font-weight:700;">View Slip</a>
                </div>
            </div>
            <div class="slip-actions">
                <button class="btn btn-ghost" style="padding:4px 9px;font-size:10px;" title="Replace slip" onclick="openSlipModal(<?php echo $b['id'];?>,<?php echo $b['year']??0;?>,<?php echo $b['month']??0;?>)">
                    <i class="fa-solid fa-pen"></i>
                </button>
                <button class="btn btn-del" style="padding:4px 9px;font-size:10px;" title="Remove slip" onclick="delSlip(<?php echo $b['id'];?>)">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </div>
        </div>
        <?php else: ?>
        <div class="slip-row sl-miss">
            <div class="slip-icon si-miss"><i class="fa-solid fa-file-invoice"></i></div>
            <div class="slip-txt">
                <div class="slip-lbl" style="color:#64748b;">Payment Slip</div>
                <div class="slip-dt" style="color:#94a3b8;">Not uploaded yet</div>
            </div>
            <button class="btn btn-upload" style="padding:5px 12px;font-size:11px;" onclick="openSlipModal(<?php echo $b['id'];?>,<?php echo $b['year']??0;?>,<?php echo $b['month']??0;?>)">
                <i class="fa-solid fa-upload"></i> Upload
            </button>
        </div>
        <?php endif;?>

        <?php if (!empty($b['notes'])): ?>
        <div style="margin-top:10px;font-size:11px;color:#64748b;background:#f8fafc;border-radius:6px;padding:7px 10px;border:1px solid var(--bd);">
            <i class="fa-solid fa-note-sticky" style="margin-right:4px;color:var(--tk);"></i><?php echo htmlspecialchars($b['notes']);?>
        </div>
        <?php endif;?>
    </div>

    <div class="bcard-acts">
        <button class="btn btn-view" onclick="viewBatch(<?php echo $b['id'];?>)">
            <i class="fa-solid fa-eye"></i> View Details
        </button>
        <a href="etf_report.php?period_id=<?php echo $b['payroll_period_id'];?>" class="btn btn-report" style="font-size:11px;">
            <i class="fa-solid fa-file-lines"></i> ETF Report
        </a>
        <button class="btn btn-del" style="margin-left:auto;" onclick="delBatch(<?php echo $b['id'];?>,'<?php echo htmlspecialchars($period_lbl);?>')">
            <i class="fa-solid fa-trash"></i>
        </button>
    </div>
</div>
<?php endforeach;?>
</div><!-- /.bg -->
<?php endif;?>

<!-- ══════════════════════════════════════════════════════
     VIEW BATCH MODAL
══════════════════════════════════════════════════════════ -->
<div class="mo" id="viewModal">
    <div class="mv">
        <div class="mv-head">
            <div>
                <h3 id="vm-title">ETF Batch Details</h3>
                <div id="vm-sub" style="font-size:11px;color:#99f6e4;margin-top:2px;"></div>
            </div>
            <button class="btn-close" onclick="closeViewModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="mv-body" id="vm-body">
            <div style="text-align:center;padding:50px;color:#94a3b8;">
                <i class="fa-solid fa-spinner fa-spin" style="font-size:28px;"></i>
                <div style="margin-top:10px;font-size:13px;">Loading…</div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════
     SLIP UPLOAD MODAL
══════════════════════════════════════════════════════════ -->
<div class="mo" id="slipModal">
    <div class="ms">
        <div class="ms-head">
            <i class="fa-solid fa-file-invoice" style="font-size:18px;color:var(--tk);"></i>
            <div>
                <h3>Upload ETF Payment Slip</h3>
                <div id="sm-sub" style="font-size:11px;color:#0f766e;opacity:.8;"></div>
            </div>
        </div>
        <div class="ms-body">
            <form id="slipForm" enctype="multipart/form-data">
                <input type="hidden" id="sf-bid" name="batch_id">
                <input type="hidden" name="action" value="upload_slip">
                <div class="up-form">
                    <div class="up-row">
                        <div class="up-field">
                            <label>Payment Date</label>
                            <input type="date" name="slip_date" id="sf-date" required>
                        </div>
                        <div class="up-field">
                            <label>File <span style="color:#94a3b8;">(PDF / JPG / PNG)</span></label>
                            <input type="file" name="slip_file" id="sf-file" accept=".pdf,.jpg,.jpeg,.png,.webp">
                        </div>
                    </div>
                    <div class="up-field">
                        <label>Notes</label>
                        <input type="text" name="notes" id="sf-notes" placeholder="Optional notes…">
                    </div>
                    <div id="sf-current" style="display:none;background:var(--tl);border:1px solid #5eead4;border-radius:7px;padding:9px 12px;font-size:12px;color:var(--tk);">
                        <i class="fa-solid fa-file"></i>
                        <span id="sf-fname"></span>
                        &nbsp;·&nbsp;
                        <a href="#" id="sf-flink" target="_blank" style="color:var(--tk);font-weight:700;">View Current Slip</a>
                    </div>
                </div>
            </form>
        </div>
        <div class="ms-ft">
            <button class="btn-cn" onclick="closeSlipModal()">Cancel</button>
            <button class="btn-ok" id="sm-btn" onclick="submitSlip()">
                <i class="fa-solid fa-upload"></i> Upload Slip
            </button>
        </div>
    </div>
</div>

<!-- DELETE CONFIRM MODAL -->
<div class="mo" id="delModal">
    <div class="ms" style="width:400px;">
        <div class="ms-head" style="background:var(--rl);border-bottom:1.5px solid #fca5a5;">
            <i class="fa-solid fa-triangle-exclamation" style="font-size:18px;color:var(--rd);"></i>
            <div>
                <h3 style="color:var(--rd);" id="del-title">Delete Batch</h3>
            </div>
        </div>
        <div class="ms-body">
            <p style="font-size:13px;color:#374151;margin:0;" id="del-msg">Are you sure? This will permanently remove this ETF batch and its payment slip.</p>
        </div>
        <div class="ms-ft">
            <button class="btn-cn" onclick="closeDelModal()">Cancel</button>
            <button class="btn-ok" style="background:var(--rd);" id="del-confirm">
                <i class="fa-solid fa-trash"></i> Delete
            </button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
const MONTH_NAMES = <?php echo json_encode($month_names); ?>;
const SLIP_MAP    = <?php echo json_encode($slip_map); ?>;

// ── View Batch Modal ──────────────────────────────────────────────────────────
function viewBatch(bid) {
    document.getElementById('viewModal').classList.add('open');
    document.getElementById('vm-body').innerHTML = `
        <div style="text-align:center;padding:50px;color:#94a3b8;">
            <i class="fa-solid fa-spinner fa-spin" style="font-size:28px;"></i>
            <div style="margin-top:10px;font-size:13px;">Loading…</div>
        </div>`;

    fetch(`etf_batches_ajax.php?action=get_batch&bid=${bid}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                document.getElementById('vm-body').innerHTML = `<p style="color:red;padding:20px;">${data.msg}</p>`;
                return;
            }
            renderViewModal(data);
        })
        .catch(() => {
            document.getElementById('vm-body').innerHTML = `<p style="color:red;padding:20px;">Failed to load batch.</p>`;
        });
}

function renderViewModal(d) {
    const b = d.batch;
    const period_lbl = b.year ? (MONTH_NAMES[parseInt(b.month)] + ' ' + b.year) : '—';
    document.getElementById('vm-title').textContent = 'ETF Batch — ' + period_lbl;
    document.getElementById('vm-sub').textContent   = `Saved ${b.created_at ? b.created_at.substring(0,10) : ''} · ${b.total_members||0} employees`;

    const nt = n => parseFloat(n||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});

    // Payment slip section
    const slip_path = d.slip_path || '';
    const slip_date = d.slip_date || '';
    let slipHtml = '';
    if (slip_path) {
        const fd = slip_date ? new Date(slip_date).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}) : '';
        slipHtml = `
            <div class="slip-panel">
                <div class="slip-panel-hdr"><i class="fa-solid fa-file-invoice"></i> Payment Slip <span style="margin-left:auto;font-size:10px;font-weight:600;opacity:.7;">${fd}</span></div>
                <div class="slip-panel-body">
                    <div class="slip-preview">
                        <div class="slip-preview-icon"><i class="fa-solid fa-file"></i></div>
                        <div class="slip-preview-info">
                            <div class="slip-preview-name">
                                <a href="${slip_path}" target="_blank" style="color:var(--tk);font-weight:700;">View / Download Slip</a>
                            </div>
                            <div class="slip-preview-date">${slip_date||'No date set'}</div>
                        </div>
                        <div style="display:flex;gap:5px;">
                            <button class="btn btn-ghost" style="padding:4px 8px;font-size:10px;" onclick="openSlipModal(${b.id},${b.year||0},${b.month||0})"><i class="fa-solid fa-pen"></i></button>
                            <button class="btn btn-del"   style="padding:4px 8px;font-size:10px;" onclick="delSlip(${b.id})"><i class="fa-solid fa-trash"></i></button>
                        </div>
                    </div>
                </div>
            </div>`;
    } else {
        slipHtml = `
            <div class="slip-panel">
                <div class="slip-panel-hdr" style="background:#f8fafc;border-bottom-color:var(--bd);color:#64748b;">
                    <i class="fa-solid fa-file-invoice"></i> Payment Slip
                    <span style="margin-left:auto;font-size:10px;font-weight:600;opacity:.5;">Not uploaded</span>
                </div>
                <div class="slip-panel-body">
                    <p style="font-size:12px;color:#94a3b8;margin:0 0 10px;">No payment slip uploaded yet.</p>
                    <button class="btn btn-upload" style="font-size:11px;" onclick="openSlipModal(${b.id},${b.year||0},${b.month||0})">
                        <i class="fa-solid fa-upload"></i> Upload Payment Slip
                    </button>
                </div>
            </div>`;
    }

    // Entries rows
    let etRows = '', tEtf = 0, tBasic = 0;
    (d.entries || []).forEach((e, i) => {
        tBasic += parseFloat(e.basic||0);
        tEtf   += parseFloat(e.etf_er||0);
        etRows += `<tr>
            <td class="tc" style="color:#94a3b8;font-size:10px;">${i+1}</td>
            <td class="tc">${e.nic ? `<span class="nc">${e.nic}</span>` : '<span style="color:#d1d5db;">—</span>'}</td>
            <td class="tl"><div style="font-weight:700;color:#0f766e;font-family:'Courier New',monospace;font-size:11px;">${e.surname||''}</div></td>
            <td class="tc"><span class="ec-t">${e.epf_number||'—'}</span></td>
            <td class="tl" style="font-weight:700;font-size:11px;">${e.full_name||''}</td>
            <td style="font-family:'Courier New',monospace;font-weight:600;color:#374151;">LKR ${parseFloat(e.basic||0).toFixed(2)}</td>
            <td><span class="mt">LKR ${parseFloat(e.etf_er||0).toFixed(2)}</span></td>
            <td class="tc"><span class="ac ${e.att_days>0?'ok':''}">${e.att_days>0?e.att_days:'—'}</span></td>
        </tr>`;
    });

    document.getElementById('vm-body').innerHTML = `
        <div class="vm-summary">
            <div class="vms-card"><div class="vms-lb"><i class="fa-solid fa-users"></i> Employees</div><div class="vms-vl c-dk">${b.total_members||0}</div></div>
            <div class="vms-card"><div class="vms-lb">Basic Salary Total</div><div class="vms-vl c-dk">LKR ${nt(tBasic)}</div></div>
            <div class="vms-card"><div class="vms-lb"><i class="fa-solid fa-sigma"></i> Total ETF</div><div class="vms-vl c-tk">LKR ${nt(b.total_etf)}</div></div>
        </div>
        <div class="slip-section">
            <div class="slip-sec-title"><i class="fa-solid fa-receipt"></i> Payment Slip</div>
            ${slipHtml}
        </div>
        <div class="ent-section">
            <div class="ent-title"><i class="fa-solid fa-table"></i> Employee ETF Contributions <span style="font-size:11px;font-weight:400;color:#64748b;">(${d.entries?.length||0} employees)</span></div>
            <div style="overflow-x:auto;">
            <table class="ent-tbl">
                <thead><tr>
                    <th class="tc t-id">#</th>
                    <th class="tc t-id">NIC</th>
                    <th class="tl t-id">Surname</th>
                    <th class="tc t-id">EPF No.</th>
                    <th class="tl t-nm">Employee Name</th>
                    <th class="t-etf">Basic</th>
                    <th class="t-tot">ETF (3%)</th>
                    <th class="tc">Att. Days</th>
                </tr></thead>
                <tbody>${etRows}</tbody>
                <tbody>
                <tr class="tr-tot">
                    <td colspan="5" style="text-align:right;color:#5eead4;font-size:10px;"><i class="fa-solid fa-sigma"></i> TOTAL</td>
                    <td style="color:#a5f3fc;font-family:'Courier New',monospace;">LKR ${nt(tBasic)}</td>
                    <td style="color:#5eead4;font-family:'Courier New',monospace;font-size:12px;">LKR ${nt(tEtf)}</td>
                    <td></td>
                </tr>
                </tbody>
            </table>
            </div>
        </div>
    `;
}

function closeViewModal() { document.getElementById('viewModal').classList.remove('open'); }
document.getElementById('viewModal').addEventListener('click', function(e){ if(e.target===this) closeViewModal(); });

// ── Slip Upload Modal ─────────────────────────────────────────────────────────
let _slip_bid = 0;

function openSlipModal(bid, year, month) {
    _slip_bid = bid;
    document.getElementById('sf-bid').value = bid;
    const period_lbl = year ? (MONTH_NAMES[month] + ' ' + year) : '';
    document.getElementById('sm-sub').textContent = period_lbl;

    // Show existing slip info if any
    const exist    = SLIP_MAP[bid];
    const cur_path = exist ? exist.slip_path : '';
    const cur_date = exist ? exist.slip_date : '';
    if (cur_path) {
        document.getElementById('sf-current').style.display = 'block';
        document.getElementById('sf-fname').textContent     = exist.slip_name || cur_path.split('/').pop();
        document.getElementById('sf-flink').href            = cur_path;
        document.getElementById('sf-date').value            = cur_date || '';
    } else {
        document.getElementById('sf-current').style.display = 'none';
        document.getElementById('sf-date').value            = '';
    }
    document.getElementById('sf-notes').value = '';
    document.getElementById('slipModal').classList.add('open');
}

function closeSlipModal() { document.getElementById('slipModal').classList.remove('open'); }

function submitSlip() {
    const btn = document.getElementById('sm-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Uploading…';
    const fd = new FormData(document.getElementById('slipForm'));
    fetch('etf_batches.php', {method:'POST', body:fd})
        .then(r => r.json())
        .then(d => {
            closeSlipModal();
            if (d.success) { showToast('s','✓ ' + d.msg); setTimeout(() => location.reload(), 1200); }
            else showToast('e','✗ ' + (d.msg||'Upload failed'));
        })
        .catch(() => showToast('e','✗ Network error'))
        .finally(() => { btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-upload"></i> Upload Slip'; });
}

document.getElementById('slipModal').addEventListener('click', function(e){ if(e.target===this) closeSlipModal(); });

// ── Delete Slip ───────────────────────────────────────────────────────────────
function delSlip(bid) {
    if (!confirm('Remove the payment slip for this batch?')) return;
    const fd = new FormData();
    fd.append('action','delete_slip');
    fd.append('batch_id', bid);
    fetch('etf_batches.php',{method:'POST',body:fd})
        .then(r=>r.json())
        .then(d=>{
            if(d.success){showToast('s','✓ Slip removed.');setTimeout(()=>location.reload(),900);}
            else showToast('e','✗ '+d.msg);
        })
        .catch(()=>showToast('e','✗ Error'));
}

// ── Delete Batch ──────────────────────────────────────────────────────────────
let _del_bid = 0;
function delBatch(bid, lbl) {
    _del_bid = bid;
    document.getElementById('del-title').textContent = 'Delete ETF Batch — ' + lbl;
    document.getElementById('del-msg').textContent   = `Delete the ETF batch for ${lbl}? All employee entries and the payment slip will be permanently removed.`;
    document.getElementById('delModal').classList.add('open');
}
document.getElementById('del-confirm').addEventListener('click', function(){
    this.disabled = true;
    this.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Deleting…';
    const fd = new FormData();
    fd.append('action','delete_batch'); fd.append('batch_id',_del_bid);
    fetch('etf_batches.php',{method:'POST',body:fd})
        .then(r=>r.json())
        .then(d=>{
            closeDelModal();
            if(d.success){showToast('s','✓ '+d.msg);setTimeout(()=>location.reload(),900);}
            else showToast('e','✗ '+d.msg);
        })
        .catch(()=>showToast('e','✗ Error'))
        .finally(()=>{this.disabled=false;this.innerHTML='<i class="fa-solid fa-trash"></i> Delete';});
});
function closeDelModal(){ document.getElementById('delModal').classList.remove('open'); }
document.getElementById('delModal').addEventListener('click',function(e){if(e.target===this)closeDelModal();});

function showToast(t,m){
    const x=document.getElementById('toast');x.textContent=m;x.className='toast t-'+t;
    setTimeout(()=>x.classList.add('show'),10);setTimeout(()=>x.classList.remove('show'),3400);
}
</script>

<?php include 'footer.php'; ?>
