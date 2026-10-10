<?php
/**
 * EPF BATCHES LIST PAGE
 * Shows all saved EPF batches with:
 *  - Payroll month, total contribution, EPF amounts
 *  - View modal with employee details
 *  - Before payment slip upload (with date)
 *  - After payment slip upload (with date)
 *  - Payment status tracker
 *
 * DB TABLES (auto-created):
 *   epf_batch_payment_slips — stores before/after slip uploads
 */
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'config.php';

// ── Auto-create payment_slips table ──────────────────────────────────────────
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS epf_batch_payment_slips (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        batch_id    INT NOT NULL,
        slip_type   ENUM('before','after') NOT NULL,
        slip_date   DATE,
        file_name   VARCHAR(255),
        file_path   VARCHAR(500),
        file_size   INT,
        notes       TEXT,
        uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_batch_type (batch_id, slip_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Ensure uploads directory exists
$upload_dir = __DIR__ . '/uploads/epf_slips/';
if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);

$month_names = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

// ── AJAX: Upload slip ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'upload_slip') {
        $batch_id  = intval($_POST['batch_id'] ?? 0);
        $slip_type = in_array($_POST['slip_type'] ?? '', ['before','after']) ? $_POST['slip_type'] : null;
        $slip_date = mysqli_real_escape_string($conn, $_POST['slip_date'] ?? '');
        $notes     = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');

        if (!$batch_id || !$slip_type) {
            echo json_encode(['success'=>false,'msg'=>'Invalid data']); exit;
        }

        $file_name = ''; $file_path = ''; $file_size = 0;

        if (isset($_FILES['slip_file']) && $_FILES['slip_file']['error'] === UPLOAD_ERR_OK) {
            $orig     = basename($_FILES['slip_file']['name']);
            $ext      = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            $allowed  = ['pdf','jpg','jpeg','png','webp'];
            if (!in_array($ext, $allowed)) {
                echo json_encode(['success'=>false,'msg'=>'File type not allowed. Use PDF, JPG, PNG.']); exit;
            }
            $file_name = 'epf_b'.$batch_id.'_'.$slip_type.'_'.time().'.'.$ext;
            $dest      = $upload_dir . $file_name;
            $rel_path  = 'uploads/epf_slips/' . $file_name;
            if (!move_uploaded_file($_FILES['slip_file']['tmp_name'], $dest)) {
                echo json_encode(['success'=>false,'msg'=>'File upload failed']); exit;
            }
            $file_size = $_FILES['slip_file']['size'];
            $file_path = $rel_path;
        }

        // Delete old slip file if replacing
        $old = mysqli_query($conn, "SELECT file_path FROM epf_batch_payment_slips WHERE batch_id=$batch_id AND slip_type='$slip_type' LIMIT 1");
        if ($old && mysqli_num_rows($old) > 0) {
            $ov = mysqli_fetch_assoc($old);
            if ($ov['file_path'] && file_exists(__DIR__.'/'.$ov['file_path'])) @unlink(__DIR__.'/'.$ov['file_path']);
        }

        $slip_date_sql = $slip_date ? "'$slip_date'" : 'NULL';
        mysqli_query($conn, "
            INSERT INTO epf_batch_payment_slips (batch_id, slip_type, slip_date, file_name, file_path, file_size, notes)
            VALUES ($batch_id, '$slip_type', $slip_date_sql, '$file_name', '$file_path', $file_size, '$notes')
            ON DUPLICATE KEY UPDATE
                slip_date=VALUES(slip_date), file_name=VALUES(file_name),
                file_path=VALUES(file_path), file_size=VALUES(file_size),
                notes=VALUES(notes), uploaded_at=NOW()
        ");

        echo json_encode([
            'success'   => true,
            'msg'       => ucfirst($slip_type).' payment slip saved!',
            'file_name' => $file_name,
            'file_path' => $file_path,
        ]);
        exit;
    }

    if ($_POST['action'] === 'delete_slip') {
        $batch_id  = intval($_POST['batch_id'] ?? 0);
        $slip_type = in_array($_POST['slip_type']??'',['before','after'])?$_POST['slip_type']:null;
        if ($batch_id && $slip_type) {
            $old = mysqli_query($conn, "SELECT file_path FROM epf_batch_payment_slips WHERE batch_id=$batch_id AND slip_type='$slip_type' LIMIT 1");
            if ($old && mysqli_num_rows($old) > 0) {
                $ov = mysqli_fetch_assoc($old);
                if ($ov['file_path'] && file_exists(__DIR__.'/'.$ov['file_path'])) @unlink(__DIR__.'/'.$ov['file_path']);
            }
            mysqli_query($conn, "DELETE FROM epf_batch_payment_slips WHERE batch_id=$batch_id AND slip_type='$slip_type'");
            echo json_encode(['success'=>true,'msg'=>'Slip removed.']); exit;
        }
        echo json_encode(['success'=>false,'msg'=>'Invalid']); exit;
    }

    if ($_POST['action'] === 'delete_batch') {
        $bid = intval($_POST['batch_id'] ?? 0);
        if ($bid) {
            // Remove slip files
            $slips = mysqli_query($conn, "SELECT file_path FROM epf_batch_payment_slips WHERE batch_id=$bid");
            if ($slips) while ($s = mysqli_fetch_assoc($slips)) {
                if ($s['file_path'] && file_exists(__DIR__.'/'.$s['file_path'])) @unlink(__DIR__.'/'.$s['file_path']);
            }
            mysqli_query($conn, "DELETE FROM epf_batches WHERE id=$bid");
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
           (SELECT file_path FROM epf_batch_payment_slips WHERE batch_id=b.id AND slip_type='before' LIMIT 1) AS before_slip,
           (SELECT slip_date FROM epf_batch_payment_slips WHERE batch_id=b.id AND slip_type='before' LIMIT 1) AS before_date,
           (SELECT file_path FROM epf_batch_payment_slips WHERE batch_id=b.id AND slip_type='after'  LIMIT 1) AS after_slip,
           (SELECT slip_date FROM epf_batch_payment_slips WHERE batch_id=b.id AND slip_type='after'  LIMIT 1) AS after_date
    FROM epf_batches b
    LEFT JOIN payroll_periods pp ON b.payroll_period_id = pp.id
    ORDER BY b.created_at DESC
");
$batches = [];
while ($row = mysqli_fetch_assoc($batches_res)) $batches[] = $row;

// ── Summary totals ────────────────────────────────────────────────────────────
$grand_epf  = array_sum(array_column($batches,'total_epf'));
$grand_emp  = array_sum(array_column($batches,'total_members'));
$paid_count = count(array_filter($batches, fn($b) => !empty($b['after_slip'])));
$pending    = count($batches) - $paid_count;

include 'header.php';
?>
<style>
:root{--dk:#0c4a6e;--md:#0369a1;--lt:#e0f2fe;--gn:#166534;--gl:#dcfce7;--am:#92400e;--al:#fef3c7;--rd:#991b1b;--rl:#fee2e2;--pu:#7c3aed;--pl:#f3e8ff;--bd:#e2e8f0;--tl:#0d9488;--tt:#f0fdfa;}
/* Page Header */
.bh{background:linear-gradient(135deg,var(--dk),var(--md));color:#fff;padding:22px 28px 18px;border-radius:0 0 14px 14px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;}
.bh-t{font-size:21px;font-weight:800;display:flex;align-items:center;gap:10px;}
.bh-s{font-size:12px;color:#7dd3fc;margin-top:3px;}
.bh-acts{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
/* Buttons */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 15px;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;border:none;text-decoration:none;font-family:inherit;white-space:nowrap;transition:all .18s;}
.btn-back{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);}.btn-back:hover{background:rgba(255,255,255,.25);}
.btn-new{background:#166534;color:#fff;}.btn-new:hover{background:#14532d;}
.btn-view{background:var(--lt);color:var(--dk);border:1px solid #bae6fd;}.btn-view:hover{background:#bae6fd;}
.btn-del{background:var(--rl);color:var(--rd);border:1px solid #fca5a5;}.btn-del:hover{background:#fca5a5;}
.btn-ep{background:var(--gl);color:var(--gn);border:1px solid #86efac;}.btn-ep:hover{background:#86efac;}
.btn-upload{background:var(--pu);color:#fff;}.btn-upload:hover{background:#6d28d9;}
.btn-ghost{background:#f8fafc;color:#374151;border:1px solid var(--bd);}.btn-ghost:hover{background:var(--bd);}
/* Stat strip */
.stats{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;}
.sc{background:#fff;border:1px solid var(--bd);border-radius:11px;padding:13px 16px;flex:1;min-width:140px;position:relative;overflow:hidden;}
.sc::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;}
.sc.s-tot::before{background:var(--dk);}
.sc.s-emp::before{background:var(--tl);}
.sc.s-epf::before{background:var(--pu);}
.sc.s-pd::before{background:var(--gn);}
.sc.s-pe::before{background:var(--am);}
.sc-lb{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;margin-bottom:4px;}
.sc-vl{font-size:22px;font-weight:900;letter-spacing:-1px;}
.sc.s-tot .sc-vl{color:var(--dk);}
.sc.s-emp .sc-vl{color:var(--tl);}
.sc.s-epf .sc-vl{color:var(--pu);font-size:16px;}
.sc.s-pd  .sc-vl{color:var(--gn);}
.sc.s-pe  .sc-vl{color:var(--am);}
.sc-sb{font-size:11px;color:#64748b;margin-top:2px;}
/* Batch cards grid */
.bg{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px;margin-bottom:20px;}
.bcard{background:#fff;border:1px solid var(--bd);border-radius:13px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.05);transition:box-shadow .2s;}
.bcard:hover{box-shadow:0 4px 18px rgba(0,0,0,.1);}
.bcard-head{background:linear-gradient(135deg,var(--dk),var(--md));color:#fff;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;}
.bcard-title{font-size:14px;font-weight:800;}
.bcard-sub{font-size:11px;color:#7dd3fc;margin-top:2px;}
.bcard-badge{background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.3);padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.bcard-body{padding:16px 18px;}
.bcard-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;}
.bcard-row:last-child{margin-bottom:0;}
.bcard-key{font-size:11px;color:#64748b;font-weight:600;}
.bcard-val{font-size:13px;font-weight:800;color:var(--dk);}
.bcard-val.vg{color:var(--gn);}
.bcard-val.vp{color:var(--pu);}
.bcard-val.va{color:var(--am);}
.bcard-divider{border:none;border-top:1px solid var(--bd);margin:10px 0;}
/* Slip status chips */
.slip-row{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:7px;font-size:12px;margin-bottom:6px;}
.slip-row.sl-b{background:#f0fdfa;border:1px solid #99f6e4;}
.slip-row.sl-a{background:var(--gl);border:1px solid #86efac;}
.slip-row.sl-miss{background:#f8fafc;border:1px solid var(--bd);}
.slip-icon{width:28px;height:28px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;}
.slip-icon.si-b{background:var(--tt);color:var(--tl);}
.slip-icon.si-a{background:var(--gl);color:var(--gn);}
.slip-icon.si-miss{background:#f1f5f9;color:#94a3b8;}
.slip-txt{flex:1;}
.slip-lbl{font-weight:700;}
.slip-dt{font-size:10px;color:#64748b;}
.slip-actions{display:flex;gap:5px;}
.bcard-acts{padding:0 18px 16px;display:flex;gap:8px;flex-wrap:wrap;}
/* Empty */
.empty-state{text-align:center;padding:70px 20px;color:#94a3b8;}
.empty-state i{font-size:52px;color:#e2e8f0;margin-bottom:14px;display:block;}
.empty-state h3{font-size:16px;font-weight:700;color:#64748b;margin-bottom:6px;}

/* ── MODALS ── */
.mo{position:fixed;inset:0;background:rgba(0,0,0,.52);z-index:9000;display:none;align-items:center;justify-content:center;padding:20px;}
.mo.open{display:flex;}
/* View modal (wide) */
.mv{background:#fff;border-radius:16px;width:900px;max-width:96vw;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.28);overflow:hidden;}
.mv-head{background:linear-gradient(135deg,var(--dk),var(--md));color:#fff;padding:18px 24px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.mv-head h3{margin:0;font-size:15px;font-weight:800;}
.mv-body{flex:1;overflow-y:auto;padding:0;}
.btn-close{background:rgba(255,255,255,.2);border:none;color:#fff;width:30px;height:30px;border-radius:50%;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;}
.btn-close:hover{background:rgba(255,255,255,.35);}

/* Summary block inside view modal */
.vm-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;padding:16px 22px;background:#f8fafc;border-bottom:1px solid var(--bd);}
.vms-card{background:#fff;border:1px solid var(--bd);border-radius:9px;padding:11px 14px;}
.vms-lb{font-size:10px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:.3px;margin-bottom:3px;}
.vms-vl{font-size:15px;font-weight:900;font-family:'Courier New',monospace;}
.vms-vl.c-gn{color:var(--gn);}
.vms-vl.c-pu{color:var(--pu);}
.vms-vl.c-bl{color:var(--md);}
.vms-vl.c-dk{color:var(--dk);}

/* Payment slips section */
.slip-section{padding:16px 22px;border-bottom:1px solid var(--bd);}
.slip-sec-title{font-size:13px;font-weight:800;color:var(--dk);margin-bottom:12px;display:flex;align-items:center;gap:7px;}
.slip-panels{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.slip-panel{border:1.5px solid var(--bd);border-radius:10px;overflow:hidden;}
.slip-panel-hdr{display:flex;align-items:center;gap:8px;padding:10px 14px;font-size:12px;font-weight:800;}
.slip-panel-hdr.sp-b{background:#f0fdfa;border-bottom:1px solid #99f6e4;color:var(--tl);}
.slip-panel-hdr.sp-a{background:var(--gl);border-bottom:1px solid #86efac;color:var(--gn);}
.slip-panel-body{padding:14px;}
.slip-preview{display:flex;align-items:center;gap:10px;background:#f8fafc;border:1px solid var(--bd);border-radius:7px;padding:10px 12px;margin-bottom:10px;}
.slip-preview-icon{width:34px;height:34px;border-radius:7px;background:var(--lt);color:var(--md);display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;}
.slip-preview-info{flex:1;}
.slip-preview-name{font-size:12px;font-weight:700;color:var(--dk);}
.slip-preview-date{font-size:11px;color:#64748b;}
/* Upload form */
.up-form{display:flex;flex-direction:column;gap:8px;}
.up-row{display:grid;grid-template-columns:1fr 1fr;gap:8px;}
.up-field label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#64748b;display:block;margin-bottom:4px;}
.up-field input,.up-field textarea{width:100%;padding:8px 10px;border:1.5px solid var(--bd);border-radius:7px;font-size:12px;font-family:inherit;outline:none;}
.up-field input:focus,.up-field textarea:focus{border-color:var(--pu);}
.up-field input[type=file]{padding:5px 8px;cursor:pointer;}

/* Entries table inside modal */
.ent-section{padding:0 22px 16px;}
.ent-title{font-size:13px;font-weight:800;color:var(--dk);margin-bottom:10px;padding-top:16px;display:flex;align-items:center;gap:7px;}
.ent-tbl{width:100%;border-collapse:collapse;font-size:11px;}
.ent-tbl thead tr{background:#1e293b;}
.ent-tbl thead th{padding:8px 10px;color:#94a3b8;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;text-align:right;border-right:1px solid #2d3748;white-space:nowrap;}
.ent-tbl thead th.tl{text-align:left;}.ent-tbl thead th.tc{text-align:center;}
.ent-tbl thead .t-id{background:#1e3a5f!important;color:#93c5fd;}
.ent-tbl thead .t-nm{background:#1e293b!important;}
.ent-tbl thead .t-epf{background:#0c4a6e;color:#7dd3fc;}
.ent-tbl thead .t-tot{background:#4c1d95;color:#c4b5fd;}
.ent-tbl thead .t-att{background:#374151;color:#d1d5db;}
.ent-tbl tbody tr{border-bottom:1px solid #f1f5f9;}
.ent-tbl tbody tr:nth-child(even){background:#f8fafc;}
.ent-tbl tbody tr:hover{background:#eff6ff!important;}
.ent-tbl td{padding:8px 10px;vertical-align:middle;text-align:right;}
.ent-tbl td.tl{text-align:left;}.ent-tbl td.tc{text-align:center;}
.ent-tbl .tr-tot td{background:#1e293b;color:#e2e8f0;font-weight:700;padding:9px 10px;border-right:1px solid #2d3748;}
.nc{font-family:'Courier New',monospace;font-size:10px;font-weight:700;color:#1e40af;background:#dbeafe;padding:1px 5px;border-radius:3px;}
.ec-t{font-family:'Courier New',monospace;font-size:10px;font-weight:700;color:var(--md);background:var(--lt);padding:1px 5px;border-radius:3px;}
.mn{font-family:'Courier New',monospace;font-weight:600;color:var(--dk);font-size:11px;}
.mt{font-family:'Courier New',monospace;font-weight:800;color:var(--pu);font-size:12px;}
.ac{display:inline-block;font-family:monospace;font-size:10px;font-weight:700;padding:1px 5px;border-radius:3px;background:#f1f5f9;color:#475569;}
.ac.ok{background:var(--gl);color:var(--gn);}

/* Small slip modal */
.ms{background:#fff;border-radius:13px;width:460px;max-width:94vw;box-shadow:0 20px 50px rgba(0,0,0,.26);overflow:hidden;}
.ms-head{padding:16px 20px;display:flex;align-items:center;gap:10px;}
.ms-head.sp-b{background:#f0fdfa;border-bottom:1.5px solid #99f6e4;}
.ms-head.sp-a{background:var(--gl);border-bottom:1.5px solid #86efac;}
.ms-head h3{margin:0;font-size:14px;font-weight:800;}
.ms-body{padding:18px 20px;}
.ms-ft{padding:13px 20px;background:#f8fafc;border-top:1px solid var(--bd);display:flex;gap:8px;justify-content:flex-end;}
.btn-cn{padding:8px 16px;border-radius:7px;border:1px solid var(--bd);background:#fff;color:#374151;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;}
.btn-ok{padding:8px 20px;border-radius:7px;border:none;color:#fff;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:6px;}
.btn-ok.ok-b{background:var(--tl);}.btn-ok.ok-b:hover{background:#0f766e;}
.btn-ok.ok-a{background:var(--gn);}.btn-ok.ok-a:hover{background:#14532d;}
.btn-ok:disabled{opacity:.6;cursor:not-allowed;}
/* Toast */
.toast{position:fixed;bottom:26px;right:26px;background:#1e293b;color:#fff;padding:13px 20px;border-radius:9px;font-size:13px;font-weight:700;z-index:99999;transform:translateY(70px);opacity:0;transition:all .32s cubic-bezier(.34,1.56,.64,1);display:flex;align-items:center;gap:9px;}
.toast.show{transform:translateY(0);opacity:1;}
.toast.t-s{background:#166534;}.toast.t-e{background:#991b1b;}
.period-chip{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;}
.pc-open{background:#dcfce7;color:#166534;}.pc-lock{background:#f3f4f6;color:#374151;}.pc-close{background:#fee2e2;color:#991b1b;}
@media(max-width:700px){.slip-panels,.vm-summary{grid-template-columns:1fr;}.bg{grid-template-columns:1fr;}}
</style>

<!-- PAGE HEADER -->
<div class="bh">
    <div>
        <div class="bh-t"><i class="fa-solid fa-layer-group"></i> EPF Batches</div>
        <div class="bh-s"><?php echo count($batches); ?> batch<?php echo count($batches)!=1?'es':'';?> saved · Grand Total EPF: LKR <?php echo number_format($grand_epf,2);?></div>
    </div>
    <div class="bh-acts">
        <a href="epf_report.php" class="btn btn-back"><i class="fa-solid fa-arrow-left"></i> Back to Report</a>
        <a href="epf_report.php" class="btn btn-new"><i class="fa-solid fa-plus"></i> New Batch</a>
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
    <div class="sc s-epf">
        <div class="sc-lb"><i class="fa-solid fa-sigma"></i> Grand Total EPF</div>
        <div class="sc-vl">LKR <?php echo number_format($grand_epf,2);?></div>
        <div class="sc-sb">All batches combined</div>
    </div>
    <div class="sc s-pd">
        <div class="sc-lb"><i class="fa-solid fa-circle-check"></i> Paid (After Slip)</div>
        <div class="sc-vl"><?php echo $paid_count;?></div>
        <div class="sc-sb">Batches with after-slip</div>
    </div>
    <div class="sc s-pe">
        <div class="sc-lb"><i class="fa-solid fa-clock"></i> Pending</div>
        <div class="sc-vl"><?php echo $pending;?></div>
        <div class="sc-sb">No after-slip yet</div>
    </div>
</div>

<!-- BATCH CARDS -->
<?php if (empty($batches)): ?>
<div class="empty-state">
    <i class="fa-solid fa-layer-group"></i>
    <h3>No EPF Batches Yet</h3>
    <p>Go to the EPF Report and click <strong>Save Batch</strong> after reviewing contributions for a payroll period.</p>
    <a href="epf_report.php" class="btn btn-new" style="margin-top:16px;"><i class="fa-solid fa-arrow-right"></i> Go to EPF Report</a>
</div>
<?php else: ?>
<div class="bg">
<?php foreach ($batches as $b):
    $period_lbl = $b['year'] ? $month_names[$b['month']].' '.$b['year'] : '—';
    $has_before = !empty($b['before_slip']);
    $has_after  = !empty($b['after_slip']);
    $pay_status = $has_after ? 'Paid' : ($has_before ? 'Processing' : 'Pending');
    $ps_bg      = $has_after ? 'background:#dcfce7;color:#166534;' : ($has_before ? 'background:#fef3c7;color:#92400e;' : 'background:#f1f5f9;color:#64748b;');
?>
<div class="bcard" id="bcard-<?php echo $b['id'];?>">
    <div class="bcard-head">
        <div>
            <div class="bcard-title"><i class="fa-solid fa-calendar-check"></i> <?php echo $period_lbl;?></div>
            <div class="bcard-sub">
              Saved <?php echo date('d M Y',strtotime($b['created_at']));?>
&nbsp;·&nbsp; <?php echo $b['total_members'];?> employees
            </div>
        </div>
        <div>
            <div class="bcard-badge" style="<?php echo $ps_bg;?>"><?php echo $pay_status;?></div>
            <?php if ($b['period_status']): ?>
            <div class="bcard-badge" style="margin-top:4px;background:rgba(255,255,255,.15);"><?php echo $b['period_status'];?></div>
            <?php endif;?>
        </div>
    </div>
    <div class="bcard-body">
        <!-- EPF Amounts -->
        <div class="bcard-row">
           <span class="bcard-key">Total Members</span>
<span class="bcard-val"><?php echo $b['total_members'];?> employees</span>
        </div>
        <div class="bcard-row">
            <span class="bcard-key">EPF Employee</span>
            <span class="bcard-val vg">LKR <?php echo number_format($b['total_epf_emp'],2);?></span>
        </div>
        <div class="bcard-row">
            <span class="bcard-key">EPF Employer</span>
            <span class="bcard-val vg">LKR <?php echo number_format($b['total_epf_er'],2);?></span>
        </div>
        <div class="bcard-row" style="border-top:1.5px solid var(--bd);padding-top:8px;margin-top:8px;">
            <span class="bcard-key" style="font-weight:800;color:#374151;">Total EPF Fund</span>
            <span class="bcard-val vp" style="font-size:15px;">LKR <?php echo number_format($b['total_epf'],2);?></span>
        </div>

        <hr class="bcard-divider">

        <!-- Before Payment Slip -->
        <?php if ($has_before): ?>
        <div class="slip-row sl-b">
            <div class="slip-icon si-b"><i class="fa-solid fa-upload"></i></div>
            <div class="slip-txt">
                <div class="slip-lbl" style="color:var(--tl);">Before Payment Slip</div>
                <div class="slip-dt">
                    <?php echo $b['before_date'] ? date('d M Y',strtotime($b['before_date'])) : 'No date'; ?>
                    &nbsp;·&nbsp; <a href="<?php echo htmlspecialchars($b['before_slip']);?>" target="_blank" style="color:var(--tl);font-weight:700;">View Slip</a>
                </div>
            </div>
            <div class="slip-actions">
                <button class="btn btn-ghost" style="padding:4px 9px;font-size:10px;" onclick="openSlipModal(<?php echo $b['id'];?>,'before',<?php echo $b['year']??0;?>,<?php echo $b['month']??0;?>)"><i class="fa-solid fa-pen"></i></button>
                <button class="btn btn-del" style="padding:4px 9px;font-size:10px;" onclick="delSlip(<?php echo $b['id'];?>,'before')"><i class="fa-solid fa-trash"></i></button>
            </div>
        </div>
        <?php else: ?>
        <div class="slip-row sl-miss">
            <div class="slip-icon si-miss"><i class="fa-solid fa-upload"></i></div>
            <div class="slip-txt">
                <div class="slip-lbl" style="color:#64748b;">Before Payment Slip</div>
                <div class="slip-dt" style="color:#94a3b8;">Not uploaded</div>
            </div>
            <button class="btn btn-upload" style="padding:5px 11px;font-size:11px;" onclick="openSlipModal(<?php echo $b['id'];?>,'before',<?php echo $b['year']??0;?>,<?php echo $b['month']??0;?>)">
                <i class="fa-solid fa-upload"></i> Upload
            </button>
        </div>
        <?php endif;?>

        <!-- After Payment Slip -->
        <?php if ($has_after): ?>
        <div class="slip-row sl-a">
            <div class="slip-icon si-a"><i class="fa-solid fa-circle-check"></i></div>
            <div class="slip-txt">
                <div class="slip-lbl" style="color:var(--gn);">After Payment Slip</div>
                <div class="slip-dt">
                    <?php echo $b['after_date'] ? date('d M Y',strtotime($b['after_date'])) : 'No date'; ?>
                    &nbsp;·&nbsp; <a href="<?php echo htmlspecialchars($b['after_slip']);?>" target="_blank" style="color:var(--gn);font-weight:700;">View Slip</a>
                </div>
            </div>
            <div class="slip-actions">
                <button class="btn btn-ghost" style="padding:4px 9px;font-size:10px;" onclick="openSlipModal(<?php echo $b['id'];?>,'after',<?php echo $b['year']??0;?>,<?php echo $b['month']??0;?>)"><i class="fa-solid fa-pen"></i></button>
                <button class="btn btn-del" style="padding:4px 9px;font-size:10px;" onclick="delSlip(<?php echo $b['id'];?>,'after')"><i class="fa-solid fa-trash"></i></button>
            </div>
        </div>
        <?php else: ?>
        <div class="slip-row sl-miss">
            <div class="slip-icon si-miss"><i class="fa-solid fa-receipt"></i></div>
            <div class="slip-txt">
                <div class="slip-lbl" style="color:#64748b;">After Payment Slip</div>
                <div class="slip-dt" style="color:#94a3b8;">Not uploaded yet</div>
            </div>
            <button class="btn btn-ep" style="padding:5px 11px;font-size:11px;" onclick="openSlipModal(<?php echo $b['id'];?>,'after',<?php echo $b['year']??0;?>,<?php echo $b['month']??0;?>)">
                <i class="fa-solid fa-upload"></i> Upload
            </button>
        </div>
        <?php endif;?>

      <?php if (!empty($b['batch_label'])): ?>
<div style="margin-top:10px;font-size:11px;color:#64748b;background:#f8fafc;border-radius:6px;padding:7px 10px;border:1px solid var(--bd);">
    <i class="fa-solid fa-note-sticky" style="margin-right:4px;"></i><?php echo htmlspecialchars($b['batch_label']);?>
</div>
<?php endif;?>
    </div>
    <div class="bcard-acts">
        <button class="btn btn-view" onclick="viewBatch(<?php echo $b['id'];?>)">
            <i class="fa-solid fa-eye"></i> View Details
        </button>
        <a href="epf_report.php?period_id=<?php echo $b['payroll_period_id'];?>" class="btn btn-ghost" style="font-size:11px;">
            <i class="fa-solid fa-file-alt"></i> EPF Report
        </a>
        <button class="btn btn-del" style="margin-left:auto;" onclick="delBatch(<?php echo $b['id'];?>, '<?php echo htmlspecialchars($period_lbl);?>')">
            <i class="fa-solid fa-trash"></i>
        </button>
    </div>
</div>
<?php endforeach;?>
</div><!-- /.bg -->
<?php endif;?>

<!-- ══════════════════════════════════════════════════════════
     VIEW BATCH MODAL
══════════════════════════════════════════════════════════════ -->
<div class="mo" id="viewModal">
    <div class="mv">
        <div class="mv-head">
            <div>
                <h3 id="vm-title">Batch Details</h3>
                <div id="vm-sub" style="font-size:11px;color:#7dd3fc;margin-top:2px;"></div>
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

<!-- SLIP UPLOAD MODAL -->
<div class="mo" id="slipModal">
    <div class="ms">
        <div class="ms-head" id="sm-head">
            <i class="fa-solid fa-upload" style="font-size:18px;"></i>
            <div>
                <h3 id="sm-title">Upload Payment Slip</h3>
                <div id="sm-sub" style="font-size:11px;opacity:.7;"></div>
            </div>
        </div>
        <div class="ms-body">
            <form id="slipForm" enctype="multipart/form-data">
                <input type="hidden" id="sf-bid" name="batch_id">
                <input type="hidden" id="sf-type" name="slip_type">
                <input type="hidden" name="action" value="upload_slip">
                <div class="up-form">
                    <div class="up-row">
                        <div class="up-field">
                            <label>Slip Date</label>
                            <input type="date" name="slip_date" id="sf-date" required>
                        </div>
                        <div class="up-field">
                            <label>File <span style="color:#94a3b8;">(PDF/JPG/PNG)</span></label>
                            <input type="file" name="slip_file" id="sf-file" accept=".pdf,.jpg,.jpeg,.png,.webp">
                        </div>
                    </div>
                    <div class="up-field">
                        <label>Notes</label>
                        <input type="text" name="notes" id="sf-notes" placeholder="Optional notes about this slip…">
                    </div>
                    <div id="sf-current" style="display:none;background:#f0fdfa;border:1px solid #99f6e4;border-radius:7px;padding:9px 12px;font-size:12px;color:#0f766e;">
                        <i class="fa-solid fa-file"></i> <span id="sf-fname"></span>
                        &nbsp;·&nbsp; <a href="#" id="sf-flink" target="_blank" style="color:#0d9488;font-weight:700;">View Current</a>
                    </div>
                </div>
            </form>
        </div>
        <div class="ms-ft">
            <button class="btn-cn" onclick="closeSlipModal()">Cancel</button>
            <button class="btn-ok" id="sm-btn" onclick="submitSlip()"><i class="fa-solid fa-upload"></i> Upload Slip</button>
        </div>
    </div>
</div>

<!-- DELETE CONFIRM MODAL -->
<div class="mo" id="delModal">
    <div class="ms" style="width:400px;">
        <div class="ms-head" style="background:var(--rl);border-bottom:1.5px solid #fca5a5;">
            <i class="fa-solid fa-triangle-exclamation" style="font-size:18px;color:var(--rd);"></i>
            <div><h3 style="color:var(--rd);" id="del-title">Delete Batch</h3></div>
        </div>
        <div class="ms-body">
            <p style="font-size:13px;color:#374151;margin:0;" id="del-msg">Are you sure you want to delete this batch? This action cannot be undone.</p>
        </div>
        <div class="ms-ft">
            <button class="btn-cn" onclick="closeDelModal()">Cancel</button>
            <button class="btn-ok" style="background:var(--rd);" id="del-confirm"><i class="fa-solid fa-trash"></i> Delete</button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<!-- ══════════════════════════════════════════════════════════
     BATCH DATA (PHP → JS)
══════════════════════════════════════════════════════════════ -->
<script>
const MONTH_NAMES = <?php echo json_encode($month_names); ?>;

// ── View batch modal ──────────────────────────────────────────────────────────
function viewBatch(bid) {
    const modal = document.getElementById('viewModal');
    modal.classList.add('open');
    document.getElementById('vm-body').innerHTML = `<div style="text-align:center;padding:50px;color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin" style="font-size:28px;"></i><div style="margin-top:10px;font-size:13px;">Loading…</div></div>`;

    fetch(`epf_batches_ajax.php?action=get_batch&bid=${bid}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) { document.getElementById('vm-body').innerHTML = `<p style="color:red;padding:20px;">${data.msg}</p>`; return; }
            renderViewModal(data);
        })
        .catch(() => { document.getElementById('vm-body').innerHTML = `<p style="color:red;padding:20px;">Failed to load batch.</p>`; });
}

function renderViewModal(d) {
    const b = d.batch;
    const period_lbl = b.year ? (MONTH_NAMES[parseInt(b.month)] + ' ' + b.year) : '—';
    document.getElementById('vm-title').textContent = 'Batch — ' + period_lbl;
   document.getElementById('vm-sub').textContent = `Saved ${b.created_at ? b.created_at.substring(0,10) : ''} · ${b.total_members||0} employees`;

    // Slip status HTML
    function slipHtml(type, slip_path, slip_date, bid) {
        const label = type === 'before' ? 'Before Payment Slip' : 'After Payment Slip';
        const cls   = type === 'before' ? 'sp-b' : 'sp-a';
        const ic    = type === 'before' ? 'upload' : 'circle-check';
        const col   = type === 'before' ? 'var(--tl)' : 'var(--gn)';
        if (slip_path) return `
            <div class="slip-panel-hdr ${cls}"><i class="fa-solid fa-${ic}"></i> ${label} <span style="margin-left:auto;font-size:10px;font-weight:600;opacity:.7;">${slip_date ? new Date(slip_date).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}) : ''}</span></div>
            <div class="slip-panel-body">
                <div class="slip-preview">
                    <div class="slip-preview-icon"><i class="fa-solid fa-file"></i></div>
                    <div class="slip-preview-info">
                        <div class="slip-preview-name"><a href="${slip_path}" target="_blank" style="color:${col};font-weight:700;">View / Download Slip</a></div>
                        <div class="slip-preview-date">${slip_date||'No date set'}</div>
                    </div>
                    <div style="display:flex;gap:5px;">
                        <button class="btn btn-ghost" style="padding:4px 8px;font-size:10px;" onclick="openSlipModal(${bid},'${type}',${b.year||0},${b.month||0})"><i class="fa-solid fa-pen"></i></button>
                        <button class="btn btn-del" style="padding:4px 8px;font-size:10px;" onclick="delSlip(${bid},'${type}')"><i class="fa-solid fa-trash"></i></button>
                    </div>
                </div>
            </div>`;
        return `
            <div class="slip-panel-hdr ${cls}"><i class="fa-solid fa-upload"></i> ${label} <span style="margin-left:auto;font-size:10px;font-weight:600;opacity:.5;">Not uploaded</span></div>
            <div class="slip-panel-body">
                <p style="font-size:12px;color:#94a3b8;margin:0 0 10px;">No slip uploaded yet.</p>
                <button class="btn btn-upload" style="font-size:11px;" onclick="openSlipModal(${bid},'${type}',${b.year||0},${b.month||0})"><i class="fa-solid fa-upload"></i> Upload ${label}</button>
            </div>`;
    }

    // Entries table
    let etRows = '';
    let tBasic=0,tEe=0,tEr=0,tTot=0;
    (d.entries||[]).forEach((e,i)=>{
        tBasic+=parseFloat(e.basic||0);tEe+=parseFloat(e.epf_emp||0);tEr+=parseFloat(e.epf_er||0);tTot+=parseFloat(e.total_epf||0);
        etRows += `<tr>
            <td class="tc" style="color:#94a3b8;font-size:10px;">${i+1}</td>
            <td class="tc">${e.nic?`<span class="nc">${e.nic}</span>`:'<span style="color:#d1d5db;">—</span>'}</td>
            <td class="tl"><div style="font-weight:700;color:#0c4a6e;font-family:Courier New,monospace;font-size:11px;">${e.surname||''}</div></td>
            <td class="tc"><span class="ec-t">${e.epf_number||'—'}</span></td>
            <td class="tl" style="font-weight:700;font-size:11px;">${e.full_name||''}</td>
            <td style="font-family:Courier New,monospace;font-weight:600;color:#0c4a6e;">LKR ${parseFloat(e.basic).toFixed(2)}</td>
            <td style="font-family:Courier New,monospace;font-weight:600;color:#0369a1;">LKR ${parseFloat(e.epf_emp).toFixed(2)}</td>
            <td style="font-family:Courier New,monospace;font-weight:600;color:#0e7490;">LKR ${parseFloat(e.epf_er).toFixed(2)}</td>
            <td><span class="mt">LKR ${parseFloat(e.total_epf).toFixed(2)}</span></td>
            <td class="tc"><span class="ac ${e.att_days>0?'ok':''}">${e.att_days>0?e.att_days:'—'}</span></td>
        </tr>`;
    });

    const nt = (n) => parseFloat(n).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});

    document.getElementById('vm-body').innerHTML = `
        <div class="vm-summary">
            <div class="vms-card"><div class="vms-lb"><i class="fa-solid fa-users"></i> Employees</div><div class="vms-vl c-dk">${b.total_members||0}</div></div>
            <div class="vms-card"><div class="vms-lb">EPF Employee</div><div class="vms-vl c-gn">LKR ${nt(b.total_epf_emp)}</div></div>
            <div class="vms-card"><div class="vms-lb">EPF Employer</div><div class="vms-vl c-bl">LKR ${nt(b.total_epf_er)}</div></div>
            <div class="vms-card"><div class="vms-lb"><i class="fa-solid fa-sigma"></i> Total EPF</div><div class="vms-vl c-pu">LKR ${nt(b.total_epf)}</div></div>
        </div>
        <div class="slip-section">
            <div class="slip-sec-title"><i class="fa-solid fa-receipt"></i> Payment Slips</div>
            <div class="slip-panels">
                <div class="slip-panel">${slipHtml('before', d.before_slip, d.before_date, b.id)}</div>
                <div class="slip-panel">${slipHtml('after',  d.after_slip,  d.after_date,  b.id)}</div>
            </div>
        </div>
        <div class="ent-section">
            <div class="ent-title"><i class="fa-solid fa-table"></i> Employee Contributions <span style="font-size:11px;font-weight:400;color:#64748b;">(${d.entries?.length||0} employees)</span></div>
            <div style="overflow-x:auto;">
            <table class="ent-tbl">
                <thead><tr>
                    <th class="tc t-id">#</th>
                    <th class="tc t-id">NIC</th>
                    <th class="tl t-id">Surname</th>
                    <th class="tc t-id">EPF No.</th>
                    <th class="tl t-nm">Employee Name</th>
                    <th class="t-epf">Basic</th>
                    <th class="t-epf">EPF Ee</th>
                    <th class="t-epf">EPF Er</th>
                    <th class="t-tot">Total EPF</th>
                    <th class="tc t-att">Working<br>Days</th>
                </tr></thead>
                <tbody>${etRows}</tbody>
                <tbody>
                <tr class="tr-tot">
                    <td colspan="5" style="text-align:right;color:#7dd3fc;font-size:10px;"><i class="fa-solid fa-sigma"></i> TOTAL</td>
                    <td style="color:#a5f3fc;">LKR ${nt(tBasic)}</td>
                    <td style="color:#7dd3fc;">LKR ${nt(tEe)}</td>
                    <td style="color:#7dd3fc;">LKR ${nt(tEr)}</td>
                    <td style="color:#c4b5fd;font-size:12px;">LKR ${nt(tTot)}</td>
                    <td></td>
                </tr>
                </tbody>
            </table>
            </div>
        </div>
    `;
}

function closeViewModal() { document.getElementById('viewModal').classList.remove('open'); }
document.getElementById('viewModal').addEventListener('click', function(e){ if(e.target===this)closeViewModal(); });

// ── Slip Upload Modal ─────────────────────────────────────────────────────────
let _slip_bid=0, _slip_type='';
const _slip_existing = <?php
    // Build a map of all slip data for JS
    $slip_map = [];
    foreach ($batches as $b) {
        $slip_map[$b['id']] = [
            'before_slip' => $b['before_slip'] ?? '',
            'before_date' => $b['before_date'] ?? '',
            'after_slip'  => $b['after_slip']  ?? '',
            'after_date'  => $b['after_date']  ?? '',
        ];
    }
    echo json_encode($slip_map);
?>;

function openSlipModal(bid, type, year, month) {
    _slip_bid = bid; _slip_type = type;
    document.getElementById('sf-bid').value  = bid;
    document.getElementById('sf-type').value = type;
    const lbl = type === 'before' ? 'Before Payment Slip' : 'After Payment Slip';
    const period_lbl = year ? (MONTH_NAMES[month] + ' ' + year) : '';
    document.getElementById('sm-title').textContent = lbl;
    document.getElementById('sm-sub').textContent   = period_lbl;
    const hdr = document.getElementById('sm-head');
    hdr.className = 'ms-head ' + (type === 'before' ? 'sp-b' : 'sp-a');
    const btn = document.getElementById('sm-btn');
    btn.className = 'btn-ok ' + (type === 'before' ? 'ok-b' : 'ok-a');

    // Show existing slip info
    const exist = _slip_existing[bid];
    const cur_path = exist ? (type === 'before' ? exist.before_slip : exist.after_slip) : '';
    const cur_date = exist ? (type === 'before' ? exist.before_date : exist.after_date) : '';
    if (cur_path) {
        document.getElementById('sf-current').style.display = 'flex';
        document.getElementById('sf-fname').textContent = cur_path.split('/').pop();
        document.getElementById('sf-flink').href = cur_path;
        document.getElementById('sf-date').value = cur_date || '';
    } else {
        document.getElementById('sf-current').style.display = 'none';
        document.getElementById('sf-date').value = cur_date || '';
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
    fetch('epf_batches.php', {method:'POST', body: fd})
        .then(r => r.json())
        .then(d => {
            closeSlipModal();
            if (d.success) { showToast('s', '✓ ' + d.msg); setTimeout(() => location.reload(), 1200); }
            else showToast('e', '✗ ' + (d.msg||'Upload failed'));
        }).catch(() => showToast('e','✗ Network error'))
        .finally(() => { btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-upload"></i> Upload Slip'; });
}

document.getElementById('slipModal').addEventListener('click', function(e){ if(e.target===this) closeSlipModal(); });

// ── Delete Slip ───────────────────────────────────────────────────────────────
function delSlip(bid, type) {
    if (!confirm('Remove the ' + type + ' payment slip?')) return;
    const fd = new FormData();
    fd.append('action','delete_slip'); fd.append('batch_id',bid); fd.append('slip_type',type);
    fetch('epf_batches.php',{method:'POST',body:fd})
        .then(r=>r.json()).then(d=>{
            if(d.success){showToast('s','✓ Slip removed.');setTimeout(()=>location.reload(),900);}
            else showToast('e','✗ '+d.msg);
        }).catch(()=>showToast('e','✗ Error'));
}

// ── Delete Batch ──────────────────────────────────────────────────────────────
let _del_bid=0;
function delBatch(bid, lbl) {
    _del_bid = bid;
    document.getElementById('del-title').textContent = 'Delete Batch — ' + lbl;
    document.getElementById('del-msg').textContent   = `Are you sure you want to delete the EPF batch for ${lbl}? All employee entries and uploaded slips will be permanently removed.`;
    document.getElementById('delModal').classList.add('open');
}
document.getElementById('del-confirm').addEventListener('click', function(){
    this.disabled = true;
    this.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Deleting…';
    const fd = new FormData();
    fd.append('action','delete_batch'); fd.append('batch_id',_del_bid);
    fetch('epf_batches.php',{method:'POST',body:fd})
        .then(r=>r.json()).then(d=>{
            closeDelModal();
            if(d.success){showToast('s','✓ '+d.msg);setTimeout(()=>location.reload(),900);}
            else showToast('e','✗ '+d.msg);
        }).catch(()=>showToast('e','✗ Error'))
        .finally(()=>{this.disabled=false;this.innerHTML='<i class="fa-solid fa-trash"></i> Delete';});
});
function closeDelModal(){document.getElementById('delModal').classList.remove('open');}
document.getElementById('delModal').addEventListener('click',function(e){if(e.target===this)closeDelModal();});

function showToast(t,m){const x=document.getElementById('toast');x.textContent=m;x.className='toast t-'+t;setTimeout(()=>x.classList.add('show'),10);setTimeout(()=>x.classList.remove('show'),3400);}
</script>

<?php include 'footer.php'; ?>
