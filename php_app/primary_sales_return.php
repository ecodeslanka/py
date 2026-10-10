<?php
include 'config.php';

// ══════════════════════════════════════════════════════════════════
//  AJAX — must be BEFORE header.php to avoid HTML output corruption
// ══════════════════════════════════════════════════════════════════
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS primary_sales_returns (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        invoice_date     DATE NOT NULL,
        invoice_no       VARCHAR(100) NOT NULL,
        entity           VARCHAR(255) NOT NULL,
        ikea_reference   VARCHAR(255) NULL,
        cbu_code         VARCHAR(100) NULL,
        cbu_description  VARCHAR(500) NULL,
        qnt_cs           DECIMAL(12,4) NULL,
        amount           DECIMAL(14,2) NULL,
        document_path    VARCHAR(500) NULL,
        document_name    VARCHAR(255) NULL,
        created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS psr_settlements (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        psr_id              INT NOT NULL,
        claim_date          DATE NULL,
        claim_reference     VARCHAR(255) NULL,
        entity              VARCHAR(255) NULL,
        deduct_invoice_no   VARCHAR(255) NULL,
        claimed_amount      DECIMAL(14,2) NULL,
        remarks             TEXT NULL,
        created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_psr (psr_id)
    )");

    $action = $_GET['action'];

    // ── Save main record ─────────────────────────────────────────
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id              = intval($_POST['id'] ?? 0);
        $invoice_date    = mysqli_real_escape_string($conn, trim($_POST['invoice_date']    ?? ''));
        $invoice_no      = mysqli_real_escape_string($conn, trim($_POST['invoice_no']      ?? ''));
        $entity          = mysqli_real_escape_string($conn, trim($_POST['entity']          ?? ''));
        $ikea_reference  = mysqli_real_escape_string($conn, trim($_POST['ikea_reference']  ?? ''));
        $cbu_code        = mysqli_real_escape_string($conn, trim($_POST['cbu_code']        ?? ''));
        $cbu_description = mysqli_real_escape_string($conn, trim($_POST['cbu_description'] ?? ''));
        $qnt_cs          = is_numeric($_POST['qnt_cs'] ?? '') ? floatval($_POST['qnt_cs']) : null;
        $amount          = is_numeric($_POST['amount']  ?? '') ? floatval($_POST['amount'])  : null;

        if (!$invoice_date || !$invoice_no || !$entity) {
            echo json_encode(['success'=>false,'message'=>'Invoice Date, Invoice No and Entity are required.']);
            exit;
        }

        // File upload
        $doc_path = null; $doc_name = null;
        if (!empty($_FILES['document']['name']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/sales_returns/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext     = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png'];
            if (!in_array($ext, $allowed)) {
                echo json_encode(['success'=>false,'message'=>'Invalid file type.']);
                exit;
            }
            $safe = uniqid('psr_',true).'.'.$ext;
            if (move_uploaded_file($_FILES['document']['tmp_name'], $upload_dir.$safe)) {
                $doc_path = mysqli_real_escape_string($conn, $upload_dir.$safe);
                $doc_name = mysqli_real_escape_string($conn, $_FILES['document']['name']);
            }
        }

        $qnt_sql    = $qnt_cs  !== null ? $qnt_cs  : 'NULL';
        $amount_sql = $amount  !== null ? $amount  : 'NULL';

        if ($id > 0) {
            $doc_part = $doc_path !== null ? ", document_path='$doc_path', document_name='$doc_name'" : '';
            $sql = "UPDATE primary_sales_returns SET
                        invoice_date='$invoice_date', invoice_no='$invoice_no', entity='$entity',
                        ikea_reference='$ikea_reference', cbu_code='$cbu_code',
                        cbu_description='$cbu_description', qnt_cs=$qnt_sql, amount=$amount_sql
                        $doc_part
                    WHERE id=$id";
        } else {
            $dp = $doc_path !== null ? "'$doc_path'" : 'NULL';
            $dn = $doc_name !== null ? "'$doc_name'" : 'NULL';
            $sql = "INSERT INTO primary_sales_returns
                        (invoice_date,invoice_no,entity,ikea_reference,cbu_code,cbu_description,qnt_cs,amount,document_path,document_name)
                    VALUES
                        ('$invoice_date','$invoice_no','$entity','$ikea_reference','$cbu_code','$cbu_description',$qnt_sql,$amount_sql,$dp,$dn)";
        }
        $ok = mysqli_query($conn, $sql);
        echo json_encode(['success'=>(bool)$ok,'id'=>($ok&&!$id)?mysqli_insert_id($conn):$id,'message'=>$ok?'':mysqli_error($conn)]);
        exit;
    }

    // ── Get single record ────────────────────────────────────────
    if ($action === 'get') {
        $id  = intval($_GET['id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn,"SELECT * FROM primary_sales_returns WHERE id=$id"));
        echo json_encode(['success'=>(bool)$row,'data'=>$row]);
        exit;
    }

    // ── Delete main record ───────────────────────────────────────
    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id  = intval($_POST['id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn,"SELECT document_path FROM primary_sales_returns WHERE id=$id"));
        if ($row && $row['document_path'] && file_exists($row['document_path'])) @unlink($row['document_path']);
        mysqli_query($conn,"DELETE FROM psr_settlements WHERE psr_id=$id");
        $ok = mysqli_query($conn,"DELETE FROM primary_sales_returns WHERE id=$id");
        echo json_encode(['success'=>(bool)$ok]);
        exit;
    }

    // ── Save settlements ─────────────────────────────────────────
    if ($action === 'save_settlements' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $psr_id = intval($_POST['psr_id'] ?? 0);
        if (!$psr_id) { echo json_encode(['success'=>false,'message'=>'Invalid record.']); exit; }

        $rows_data = json_decode($_POST['rows'] ?? '[]', true);
        if (!is_array($rows_data)) { echo json_encode(['success'=>false,'message'=>'Invalid data.']); exit; }

        mysqli_query($conn,"DELETE FROM psr_settlements WHERE psr_id=$psr_id");
        foreach ($rows_data as $r) {
            $claim_date   = mysqli_real_escape_string($conn, trim($r['claim_date']        ?? ''));
            $claim_ref    = mysqli_real_escape_string($conn, trim($r['claim_reference']   ?? ''));
            $s_entity     = mysqli_real_escape_string($conn, trim($r['entity']            ?? ''));
            $deduct_inv   = mysqli_real_escape_string($conn, trim($r['deduct_invoice_no'] ?? ''));
            $claimed_amt  = is_numeric($r['claimed_amount'] ?? '') ? floatval($r['claimed_amount']) : 'NULL';
            $remarks      = mysqli_real_escape_string($conn, trim($r['remarks']           ?? ''));
            $cd = $claim_date ? "'$claim_date'" : 'NULL';
            mysqli_query($conn,"INSERT INTO psr_settlements
                (psr_id,claim_date,claim_reference,entity,deduct_invoice_no,claimed_amount,remarks)
                VALUES ($psr_id,$cd,'$claim_ref','$s_entity','$deduct_inv',$claimed_amt,'$remarks')");
        }
        echo json_encode(['success'=>true]);
        exit;
    }

    // ── Get settlements ──────────────────────────────────────────
    if ($action === 'get_settlements') {
        $psr_id = intval($_GET['psr_id'] ?? 0);
        $res  = mysqli_query($conn,"SELECT * FROM psr_settlements WHERE psr_id=$psr_id ORDER BY id");
        $data = [];
        while ($r = mysqli_fetch_assoc($res)) $data[] = $r;
        echo json_encode(['success'=>true,'data'=>$data]);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']);
    exit;
}

// ══════════════════════════════════════════════════════════════════
//  PAGE LOAD
// ══════════════════════════════════════════════════════════════════
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS primary_sales_returns (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    invoice_date     DATE NOT NULL,
    invoice_no       VARCHAR(100) NOT NULL,
    entity           VARCHAR(255) NOT NULL,
    ikea_reference   VARCHAR(255) NULL,
    cbu_code         VARCHAR(100) NULL,
    cbu_description  VARCHAR(500) NULL,
    qnt_cs           DECIMAL(12,4) NULL,
    amount           DECIMAL(14,2) NULL,
    document_path    VARCHAR(500) NULL,
    document_name    VARCHAR(255) NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS psr_settlements (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    psr_id              INT NOT NULL,
    claim_date          DATE NULL,
    claim_reference     VARCHAR(255) NULL,
    entity              VARCHAR(255) NULL,
    deduct_invoice_no   VARCHAR(255) NULL,
    claimed_amount      DECIMAL(14,2) NULL,
    remarks             TEXT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_psr (psr_id)
)");

// Filters
$filter_entity = isset($_GET['filter_entity']) ? mysqli_real_escape_string($conn,$_GET['filter_entity']) : '';
$filter_from   = isset($_GET['filter_from'])   ? mysqli_real_escape_string($conn,$_GET['filter_from'])   : '';
$filter_to     = isset($_GET['filter_to'])     ? mysqli_real_escape_string($conn,$_GET['filter_to'])     : '';
$where = [];
if ($filter_entity) $where[] = "p.entity LIKE '%$filter_entity%'";
if ($filter_from)   $where[] = "p.invoice_date >= '$filter_from'";
if ($filter_to)     $where[] = "p.invoice_date <= '$filter_to'";
$where_sql = $where ? 'WHERE '.implode(' AND ',$where) : '';

$rows_result = mysqli_query($conn,
    "SELECT p.*,
            COALESCE((SELECT SUM(s.claimed_amount) FROM psr_settlements s WHERE s.psr_id=p.id),0) AS total_settled,
            (SELECT MAX(s.claim_date) FROM psr_settlements s WHERE s.psr_id=p.id) AS last_claim_date,
            (SELECT GROUP_CONCAT(s.claim_reference ORDER BY s.id SEPARATOR ', ') FROM psr_settlements s WHERE s.psr_id=p.id) AS claim_refs
     FROM primary_sales_returns p $where_sql
     ORDER BY p.invoice_date DESC, p.id DESC"
);
$totals = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as cnt, SUM(p.amount) as total_amt,
            COALESCE((SELECT SUM(s.claimed_amount) FROM psr_settlements s
                      INNER JOIN primary_sales_returns p2 ON s.psr_id=p2.id
                      ".($where ? str_replace('p.','p2.',$where_sql) : '')."),0) as total_claimed
     FROM primary_sales_returns p $where_sql"
));

include 'header.php';
?>
<style>
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:20px}
.card-title{font-size:16px;font-weight:600;margin-bottom:0;color:#1f2937;display:flex;align-items:center;gap:8px}
.card-header-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px}

.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;transition:all .2s;font-family:'Inter',sans-serif;text-decoration:none;white-space:nowrap}
.btn-sm{padding:7px 14px;font-size:13px}
.btn-xs{padding:5px 10px;font-size:12px}
.btn-primary{background:#000;color:#fff}.btn-primary:hover{background:#1f2937}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}.btn-secondary:hover{background:#e5e5e5}
.btn-dark{background:#000;color:#fff}.btn-dark:hover{background:#1f2937}
.btn-light{background:#f9fafb;color:#374151;border:1px solid #d1d5db}.btn-light:hover{background:#f3f4f6}
.btn-danger{background:#ef4444;color:#fff}.btn-danger:hover{background:#dc2626}
.btn-settle{background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe}.btn-settle:hover{background:#7c3aed;color:#fff}
.btn-settle.settled{background:#f0fdf4;color:#16a34a;border-color:#86efac}

.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:20px}
.summary-box{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:16px}
.summary-label{font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px}
.summary-value{font-size:20px;font-weight:700;color:#1f2937}
.summary-sub{font-size:11px;color:#9ca3af;margin-top:2px}

.fbar-group{display:flex;flex-direction:column;gap:4px}
.fbar-label{font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.4px}
.fbar-input{padding:8px 12px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;outline:none;transition:border-color .2s;height:36px;box-sizing:border-box}
.fbar-input:focus{border-color:#000}

.toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.search-wrap{position:relative;flex:1;min-width:200px;max-width:340px}
.search-wrap i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:13px;pointer-events:none}
.search-input{width:100%;padding:8px 12px 8px 32px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;outline:none;transition:border-color .2s;box-sizing:border-box}
.search-input:focus{border-color:#000}

.table-wrap{max-height:65vh;overflow:auto;border:1px solid #e5e5e5;border-radius:8px}
.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table thead tr{position:sticky;top:0;z-index:10}
.data-table thead th{background:#f0f0f0;padding:10px 12px;text-align:left;font-weight:600;color:#222;font-size:12px;white-space:nowrap;border-bottom:2px solid #d5d5d5;box-shadow:0 2px 0 #d5d5d5}
.data-table th.num,.data-table td.num{text-align:right}
.data-table tbody tr{border-bottom:1px solid #f0f0f0;transition:background .1s}
.data-table tbody tr:hover{background:#fafafa}
.data-table td{padding:9px 12px;color:#333;white-space:nowrap;vertical-align:middle}
.data-table tfoot td{padding:10px 12px;font-weight:700;color:#1f2937;background:#f9fafb;border-top:2px solid #e5e5e5;font-size:13px}

.action-buttons{display:flex;gap:5px;justify-content:center;align-items:center}
.btn-action{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:6px;border:1px solid #e5e7eb;background:#fff;color:#6b7280;cursor:pointer;transition:all .2s;font-size:12px}
.btn-action:hover{transform:translateY(-1px);box-shadow:0 2px 4px rgba(0,0,0,.1)}
.btn-edit-r:hover{background:#000;color:#fff;border-color:#000}
.btn-del-r:hover{background:#ef4444;color:#fff;border-color:#ef4444}
.doc-link{display:inline-flex;align-items:center;gap:4px;font-size:12px;color:#0369a1;text-decoration:none;font-weight:600}
.doc-link:hover{text-decoration:underline}
.no-doc{color:#d1d5db;font-size:12px}
.bal-pos{color:#16a34a;font-weight:700}
.bal-neg{color:#dc2626;font-weight:700}
.bal-zero{color:#6b7280;font-weight:600}

/* ═══ MODALS ══════════════════════════════════════════════════════ */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000;display:none;align-items:center;justify-content:center;padding:16px}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:12px;width:96%;max-width:720px;max-height:93vh;display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,.22);overflow:hidden}
.settle-modal-box{background:#fff;border-radius:12px;width:96%;max-width:960px;max-height:93vh;display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,.22);overflow:hidden}
.modal-header{padding:20px 26px 16px;border-bottom:1px solid #f0f0f0;display:flex;justify-content:space-between;align-items:center;flex-shrink:0}
.modal-title{font-size:18px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px}
.modal-close{background:none;border:none;cursor:pointer;color:#9ca3af;font-size:26px;line-height:1;padding:0;transition:color .2s}
.modal-close:hover{color:#1f2937}
.modal-body{padding:24px 26px;overflow-y:auto;flex:1}
.modal-footer{padding:16px 26px;border-top:1px solid #f0f0f0;display:flex;justify-content:flex-end;gap:10px;flex-shrink:0}

/* settlement info strip */
.settle-info-strip{padding:12px 26px;background:#f9fafb;border-bottom:1px solid #f0f0f0;display:flex;gap:24px;flex-wrap:wrap;flex-shrink:0}
.sinfo-cell{display:flex;flex-direction:column;gap:2px}
.sinfo-lbl{font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px}
.sinfo-val{font-size:14px;font-weight:700;color:#1f2937}
.sinfo-val.green{color:#16a34a}

/* settlement table */
.settle-table{width:100%;border-collapse:collapse;font-size:13px;min-width:800px}
.settle-table th{background:#f9fafb;padding:8px 8px;text-align:left;font-weight:600;color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #e5e5e5;white-space:nowrap}
.settle-table td{padding:5px 5px;vertical-align:middle;border-bottom:1px solid #f5f5f5}
.settle-input{width:100%;padding:6px 9px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;font-family:'Inter',sans-serif;outline:none;transition:border-color .2s;box-sizing:border-box;background:#fff}
.settle-input:focus{border-color:#7c3aed;box-shadow:0 0 0 2px rgba(124,58,237,.08)}
.settle-input[type=number]{text-align:right}
.settle-rm{background:none;border:none;cursor:pointer;color:#dc2626;font-size:15px;padding:4px 7px;border-radius:4px;transition:background .15s;line-height:1}
.settle-rm:hover{background:#fef2f2}
.add-settle-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border:1px dashed #c4b5fd;border-radius:6px;background:#fff;color:#7c3aed;font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;margin-top:10px;transition:all .2s}
.add-settle-btn:hover{background:#f5f3ff;border-color:#7c3aed}

.settle-totals{display:flex;gap:24px;padding:12px 0;margin-top:12px;border-top:2px solid #e5e5e5;flex-wrap:wrap}
.stotal-cell{display:flex;flex-direction:column;gap:2px}
.stotal-lbl{font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px}
.stotal-val{font-size:17px;font-weight:800;color:#1f2937}
.stotal-val.green{color:#16a34a}.stotal-val.red{color:#dc2626}.stotal-val.orange{color:#d97706}.stotal-val.purple{color:#7c3aed}

/* entry form */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-grid .span2{grid-column:span 2}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-label{font-size:12px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:.4px}
.form-label span.req{color:#ef4444}
.form-input{padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;font-family:'Inter',sans-serif;color:#1f2937;outline:none;transition:border-color .2s,box-shadow .2s;background:#fff;width:100%;box-sizing:border-box}
.form-input:focus{border-color:#000;box-shadow:0 0 0 3px rgba(0,0,0,.06)}
.form-input.error{border-color:#ef4444;box-shadow:0 0 0 3px rgba(239,68,68,.08)}
.form-divider{grid-column:span 2;border:none;border-top:1px solid #f0f0f0;margin:4px 0}

.file-drop{border:2px dashed #d1d5db;border-radius:8px;padding:20px;text-align:center;cursor:pointer;transition:all .2s;background:#fafafa;position:relative}
.file-drop:hover,.file-drop.drag{border-color:#000;background:#f5f5f5}
.file-drop input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.file-drop-icon{font-size:24px;color:#9ca3af;margin-bottom:6px}
.file-drop-text{font-size:13px;color:#6b7280;font-weight:500}
.file-drop-sub{font-size:11px;color:#9ca3af;margin-top:2px}
.file-preview{display:none;align-items:center;gap:10px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:10px 14px;margin-top:8px}
.file-preview.show{display:flex}
.file-preview-name{font-size:13px;color:#166534;font-weight:600;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.file-preview-rm{background:none;border:none;cursor:pointer;color:#dc2626;font-size:15px;padding:2px 5px;border-radius:4px}
.existing-doc{display:flex;align-items:center;gap:8px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:8px 12px;margin-bottom:8px;font-size:13px}
.existing-doc a{color:#1e40af;font-weight:600;text-decoration:none;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.existing-doc a:hover{text-decoration:underline}

/* Remove arrows from number inputs */
input[type=number]::-webkit-inner-spin-button,
input[type=number]::-webkit-outer-spin-button{-webkit-appearance:none;margin:0}
input[type=number]{-moz-appearance:textfield;appearance:textfield}

.empty-state{text-align:center;padding:60px 20px;color:#9ca3af}
.empty-state i{font-size:40px;margin-bottom:12px;display:block}
.empty-state p{font-size:15px;font-weight:500}

#toast{position:fixed;bottom:28px;right:28px;padding:12px 22px;border-radius:8px;font-size:14px;font-weight:600;color:#fff;z-index:9999;display:none;box-shadow:0 4px 16px rgba(0,0,0,.18)}
#toast.success{background:#16a34a}
#toast.error{background:#dc2626}

@media(max-width:640px){
    .form-grid{grid-template-columns:1fr}
    .form-grid .span2,.form-divider{grid-column:span 1}
    .summary-grid{grid-template-columns:1fr 1fr}
}
</style>

<!-- Page Header -->
<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h2 class="page-title"><i class="fa-solid fa-rotate-left"></i> Primary Sales Return</h2>
            <p class="page-subtitle">Manage CL invoice returns and settlements</p>
        </div>
        <button class="btn btn-primary" onclick="openAddModal()">
            <i class="fa-solid fa-plus"></i> Add New Entry
        </button>
    </div>
</div>

<!-- Summary -->
<div class="summary-grid">
    <div class="summary-box">
        <div class="summary-label">Total Records</div>
        <div class="summary-value"><?php echo number_format($totals['cnt']); ?></div>
        <div class="summary-sub">Filtered results</div>
    </div>
    <div class="summary-box">
        <div class="summary-label">Total Amount</div>
        <div class="summary-value"><?php echo $totals['total_amt']!==null?number_format($totals['total_amt'],2):'—'; ?></div>
        <div class="summary-sub">Sum of invoice amounts</div>
    </div>
    <div class="summary-box">
        <div class="summary-label">Total Claimed</div>
        <div class="summary-value" style="color:#16a34a;"><?php echo number_format($totals['total_claimed']??0,2); ?></div>
        <div class="summary-sub">Sum of settled amounts</div>
    </div>
    <div class="summary-box">
        <?php $diff=($totals['total_amt']??0)-($totals['total_claimed']??0); ?>
        <div class="summary-label">Balance Remaining</div>
        <div class="summary-value" style="color:<?php echo $diff>0?'#d97706':($diff<0?'#dc2626':'#16a34a'); ?>">
            <?php echo number_format(abs($diff),2); ?>
        </div>
        <div class="summary-sub">Amount vs Settled</div>
    </div>
</div>

<!-- Records Table -->
<div class="content-card">
    <div class="card-header-row">
        <h3 class="card-title"><i class="fa-solid fa-table"></i> CL Invoice Returns</h3>
        <form method="GET" action="" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
            <div class="fbar-group">
                <label class="fbar-label">Entity</label>
                <input type="text" name="filter_entity" class="fbar-input" placeholder="Filter entity…" value="<?php echo htmlspecialchars($filter_entity); ?>" style="width:150px;">
            </div>
            <div class="fbar-group">
                <label class="fbar-label">From</label>
                <input type="date" name="filter_from" class="fbar-input" value="<?php echo htmlspecialchars($filter_from); ?>">
            </div>
            <div class="fbar-group">
                <label class="fbar-label">To</label>
                <input type="date" name="filter_to" class="fbar-input" value="<?php echo htmlspecialchars($filter_to); ?>">
            </div>
            <button type="submit" class="btn btn-dark btn-sm"><i class="fa-solid fa-filter"></i> Filter</button>
            <a href="primary_sales_return.php" class="btn btn-light btn-sm"><i class="fa-solid fa-xmark"></i> Clear</a>
        </form>
    </div>

    <div class="toolbar">
        <div class="search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" class="search-input" id="searchBox" placeholder="Search invoice, entity, SKU…" oninput="doSearch()">
        </div>
        <span id="rowCount" style="font-size:12px;color:#6b7280;"></span>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Invoice Date</th>
                    <th>Invoice No</th>
                    <th>Entity</th>
                    <th>IKEA Reference</th>
                    <th>CBU Code</th>
                    <th>CBU Description</th>
                    <th class="num">QNT CS</th>
                    <th class="num">Amount</th>
                    <th class="num">Claimed</th>
                    <th class="num">Balance to Claimed</th>
                    <th>Claim Date</th>
                    <th>Claim References</th>
                    <th style="text-align:center;">Document</th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody id="tableBody">
            <?php if (!$rows_result || mysqli_num_rows($rows_result) === 0): ?>
                <tr><td colspan="15"><div class="empty-state"><i class="fa-solid fa-file-invoice"></i><p>No records found. Click <strong>Add New Entry</strong> to get started.</p></div></td></tr>
            <?php else: $rn=1; while ($r=mysqli_fetch_assoc($rows_result)):
                $amt     = floatval($r['amount']??0);
                $settled = floatval($r['total_settled']??0);
                $balance = $amt - $settled;
                $balCl   = $balance>0?'bal-pos':($balance<0?'bal-neg':'bal-zero');
            ?>
                <tr id="tr-<?php echo $r['id']; ?>"
                    data-search="<?php echo strtolower(htmlspecialchars(($r['invoice_no']??'').' '.($r['entity']??'').' '.($r['ikea_reference']??'').' '.($r['cbu_code']??'').' '.($r['cbu_description']??''))); ?>">
                    <td><?php echo $rn++; ?></td>
                    <td><?php echo !empty($r['invoice_date'])?date('d M Y',strtotime($r['invoice_date'])):'—'; ?></td>
                    <td><strong><?php echo htmlspecialchars($r['invoice_no']); ?></strong></td>
                    <td><?php echo htmlspecialchars($r['entity']); ?></td>
                    <td><?php echo htmlspecialchars($r['ikea_reference']??'—'); ?></td>
                    <td><?php echo htmlspecialchars($r['cbu_code']??'—'); ?></td>
                    <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($r['cbu_description']??''); ?>"><?php echo htmlspecialchars($r['cbu_description']??'—'); ?></td>
                    <td class="num"><?php echo $r['qnt_cs']!==null?number_format($r['qnt_cs'],2):'—'; ?></td>
                    <td class="num"><?php echo $r['amount']!==null?number_format($amt,2):'—'; ?></td>
                    <td class="num" id="td-claimed-<?php echo $r['id']; ?>" style="color:#16a34a;font-weight:700;"><?php echo $settled>0?number_format($settled,2):'—'; ?></td>
                    <td class="num" id="td-balance-<?php echo $r['id']; ?>"><span class="<?php echo $balCl; ?>"><?php echo number_format($balance,2); ?></span></td>
                    <td id="td-claimdate-<?php echo $r['id']; ?>" style="font-size:12px;color:#6b7280;"><?php echo !empty($r['last_claim_date'])?date('d M Y',strtotime($r['last_claim_date'])):'—'; ?></td>
                    <td id="td-claimrefs-<?php echo $r['id']; ?>" style="font-size:12px;color:#6b7280;max-width:150px;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($r['claim_refs']??''); ?>"><?php echo htmlspecialchars($r['claim_refs']??'—'); ?></td>
                    <td style="text-align:center;">
                        <?php if ($r['document_path']&&file_exists($r['document_path'])): ?>
                            <a href="<?php echo htmlspecialchars($r['document_path']); ?>" target="_blank" class="doc-link"><i class="fa-solid fa-paperclip"></i><?php echo htmlspecialchars(substr($r['document_name']??'File',0,14)); ?></a>
                        <?php else: ?><span class="no-doc">—</span><?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <div class="action-buttons">
                            <button class="btn btn-settle btn-xs<?php echo $settled>0?' settled':''; ?>"
                                    id="settlebtn-<?php echo $r['id']; ?>"
                                    onclick="openSettleModal(<?php echo $r['id']; ?>,'<?php echo addslashes(htmlspecialchars($r['invoice_no'])); ?>',<?php echo $amt; ?>)">
                                <i class="fa-solid fa-<?php echo $settled>0?'check-circle':'hand-holding-dollar'; ?>"></i>
                                <?php echo $settled>0?'Settled':'Settle'; ?>
                            </button>
                            <button class="btn-action btn-edit-r" onclick="editRow(<?php echo $r['id']; ?>)" title="Edit"><i class="fa-solid fa-pen"></i></button>
                            <button class="btn-action btn-del-r" onclick="deleteRow(<?php echo $r['id']; ?>)" title="Delete"><i class="fa-solid fa-trash"></i></button>
                        </div>
                    </td>
                </tr>
            <?php endwhile; endif; ?>
            </tbody>
            <?php if ($rows_result && mysqli_num_rows($rows_result) > 0): ?>
            <tfoot>
                <tr>
                    <td colspan="8" style="text-align:right;">Totals</td>
                    <td class="num"><?php echo $totals['total_amt']!==null?number_format($totals['total_amt'],2):'—'; ?></td>
                    <td class="num" style="color:#16a34a;"><?php echo number_format($totals['total_claimed']??0,2); ?></td>
                    <td class="num" style="color:#d97706;"><?php echo number_format(($totals['total_amt']??0)-($totals['total_claimed']??0),2); ?></td>
                    <td colspan="4"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- ═══ ADD / EDIT MODAL ════════════════════════════════════════ -->
<div class="modal-overlay" id="entryModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title"><i class="fa-solid fa-file-invoice"></i><span id="modalTitleText">Add New Entry</span></div>
            <button class="modal-close" onclick="closeAddModal()">×</button>
        </div>
        <div class="modal-body">
            <form id="entryForm" enctype="multipart/form-data" onsubmit="return false;">
                <input type="hidden" id="fid" name="id" value="0">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Invoice Date <span class="req">*</span></label>
                        <input type="date" class="form-input" id="f_invoice_date" name="invoice_date">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Invoice No <span class="req">*</span></label>
                        <input type="text" class="form-input" id="f_invoice_no" name="invoice_no" placeholder="e.g. INV-2024-001">
                    </div>
                    <div class="form-group span2">
                        <label class="form-label">Entity <span class="req">*</span></label>
                        <input type="text" class="form-input" id="f_entity" name="entity" placeholder="e.g. IKEA Sri Lanka">
                    </div>
                    <div class="form-group span2">
                        <label class="form-label">IKEA Reference</label>
                        <input type="text" class="form-input" id="f_ikea_reference" name="ikea_reference" placeholder="e.g. PO-123456">
                    </div>
                    <hr class="form-divider">
                    <div class="form-group">
                        <label class="form-label">CBU Code</label>
                        <input type="text" class="form-input" id="f_cbu_code" name="cbu_code" placeholder="e.g. CBU-001">
                    </div>
                    <div class="form-group">
                        <label class="form-label">CBU Description</label>
                        <input type="text" class="form-input" id="f_cbu_description" name="cbu_description" placeholder="e.g. Flat Pack Furniture">
                    </div>
                    <div class="form-group">
                        <label class="form-label">QNT CS</label>
                        <input type="number" step="0.0001" min="0" class="form-input" id="f_qnt_cs" name="qnt_cs" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Amount</label>
                        <input type="number" step="0.01" min="0" class="form-input" id="f_amount" name="amount" placeholder="0.00">
                    </div>
                    <hr class="form-divider">
                    <div class="form-group span2">
                        <label class="form-label">Supporting Document <span style="color:#9ca3af;font-weight:400;text-transform:none;">(Optional)</span></label>
                        <div id="existingDocWrap" style="display:none;" class="existing-doc">
                            <i class="fa-solid fa-paperclip" style="color:#1e40af;"></i>
                            <a id="existingDocLink" href="#" target="_blank">Current file</a>
                            <span style="font-size:11px;color:#9ca3af;">Upload new to replace</span>
                        </div>
                        <div class="file-drop" id="fileDrop"
                             ondragover="event.preventDefault();this.classList.add('drag')"
                             ondragleave="this.classList.remove('drag')"
                             ondrop="handleDrop(event)">
                            <input type="file" name="document" id="f_document" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" onchange="handleFileSelect(this)">
                            <div class="file-drop-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                            <div class="file-drop-text">Click or drag & drop to upload</div>
                            <div class="file-drop-sub">PDF, DOC, DOCX, XLS, XLSX, JPG, PNG · Max 10 MB</div>
                        </div>
                        <div class="file-preview" id="filePreview">
                            <i class="fa-solid fa-file" style="color:#16a34a;"></i>
                            <span class="file-preview-name" id="filePreviewName"></span>
                            <button type="button" class="file-preview-rm" onclick="clearFile()"><i class="fa-solid fa-xmark"></i></button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-light" onclick="closeAddModal()">Cancel</button>
            <button class="btn btn-primary" id="saveBtn" onclick="saveEntry()"><i class="fa-solid fa-floppy-disk"></i> Save Entry</button>
        </div>
    </div>
</div>

<!-- ═══ SETTLEMENT MODAL ════════════════════════════════════════ -->
<div class="modal-overlay" id="settleModal">
    <div class="settle-modal-box">
        <div class="modal-header">
            <div class="modal-title"><i class="fa-solid fa-hand-holding-dollar" style="color:#7c3aed;"></i><span id="settleTitleText">Settlement</span></div>
            <button class="modal-close" onclick="closeSettleModal()">×</button>
        </div>
        <div class="settle-info-strip">
            <div class="sinfo-cell"><div class="sinfo-lbl">Invoice No</div><div class="sinfo-val" id="si_invoice">—</div></div>
            <div class="sinfo-cell"><div class="sinfo-lbl">Invoice Amount</div><div class="sinfo-val" id="si_amount">—</div></div>
            <div class="sinfo-cell"><div class="sinfo-lbl">Total Claimed</div><div class="sinfo-val green" id="si_claimed">0.00</div></div>
            <div class="sinfo-cell"><div class="sinfo-lbl">Balance</div><div class="sinfo-val" id="si_balance">—</div></div>
        </div>
        <div class="modal-body">
            <input type="hidden" id="s_psr_id" value="0">
            <input type="hidden" id="s_inv_amount" value="0">
            <div style="overflow-x:auto;">
                <table class="settle-table">
                    <thead>
                        <tr>
                            <th style="min-width:130px;">Claim Date</th>
                            <th style="min-width:130px;">Claim Reference</th>
                            <th style="min-width:130px;">Entity</th>
                            <th style="min-width:140px;">Deduct Invoice No</th>
                            <th style="min-width:120px;" class="num">Claimed Amount</th>
                            <th style="min-width:150px;">Remarks</th>
                            <th style="width:36px;"></th>
                        </tr>
                    </thead>
                    <tbody id="settleRows"></tbody>
                </table>
            </div>
            <button class="add-settle-btn" onclick="addSettleRow()"><i class="fa-solid fa-plus"></i> Add Row</button>
            <div class="settle-totals">
                <div class="stotal-cell"><div class="stotal-lbl">Invoice Amount</div><div class="stotal-val" id="st_amount">0.00</div></div>
                <div class="stotal-cell"><div class="stotal-lbl">Total Claimed</div><div class="stotal-val purple" id="st_claimed">0.00</div></div>
                <div class="stotal-cell"><div class="stotal-lbl">Balance</div><div class="stotal-val orange" id="st_balance">0.00</div></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-light" onclick="closeSettleModal()">Cancel</button>
            <button class="btn btn-primary" id="saveSettleBtn" onclick="saveSettlements()"><i class="fa-solid fa-floppy-disk"></i> Save Settlements</button>
        </div>
    </div>
</div>

<div id="toast"></div>

<script>
/* ════ SEARCH ═══════════════════════════════════════════════════ */
function doSearch() {
    const q = document.getElementById('searchBox').value.toLowerCase().trim();
    const rows = document.querySelectorAll('#tableBody tr[id^="tr-"]');
    let vis=0;
    rows.forEach(r => {
        const show = !q||(r.dataset.search||'').includes(q);
        r.style.display=show?'':'none';
        if(show) vis++;
    });
    document.getElementById('rowCount').textContent=`(${vis} of ${rows.length})`;
}
document.addEventListener('DOMContentLoaded',()=>{
    const rows=document.querySelectorAll('#tableBody tr[id^="tr-"]');
    if(rows.length) document.getElementById('rowCount').textContent=`(${rows.length})`;
});

/* ════ ADD/EDIT MODAL ═══════════════════════════════════════════ */
function openAddModal(title='Add New Entry'){
    document.getElementById('modalTitleText').textContent=title;
    document.getElementById('entryModal').classList.add('open');
}
function closeAddModal(){
    document.getElementById('entryModal').classList.remove('open');
    resetForm();
}
function resetForm(){
    document.getElementById('fid').value='0';
    ['f_invoice_date','f_invoice_no','f_entity','f_ikea_reference',
     'f_cbu_code','f_cbu_description','f_qnt_cs','f_amount'].forEach(id=>{
        document.getElementById(id).value='';
    });
    document.getElementById('existingDocWrap').style.display='none';
    clearFile();
    document.querySelectorAll('.form-input.error').forEach(e=>e.classList.remove('error'));
}

/* ════ FILE ═════════════════════════════════════════════════════ */
function handleFileSelect(inp){if(inp.files[0])showFilePreview(inp.files[0].name);}
function handleDrop(e){
    e.preventDefault();
    document.getElementById('fileDrop').classList.remove('drag');
    const f=e.dataTransfer.files[0];if(!f)return;
    const dt=new DataTransfer();dt.items.add(f);
    document.getElementById('f_document').files=dt.files;
    showFilePreview(f.name);
}
function showFilePreview(name){
    document.getElementById('filePreviewName').textContent=name;
    document.getElementById('filePreview').classList.add('show');
    document.getElementById('fileDrop').style.display='none';
}
function clearFile(){
    document.getElementById('f_document').value='';
    document.getElementById('filePreview').classList.remove('show');
    document.getElementById('fileDrop').style.display='';
}

/* ════ SAVE ENTRY ═══════════════════════════════════════════════ */
function saveEntry(){
    let valid=true;
    ['f_invoice_date','f_invoice_no','f_entity'].forEach(id=>{
        const el=document.getElementById(id);
        if(!el.value.trim()){el.classList.add('error');valid=false;}
        else el.classList.remove('error');
    });
    if(!valid){showToast('Please fill in all required fields.','error');return;}

    const id=document.getElementById('fid').value;
    const btn=document.getElementById('saveBtn');
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    fetch('primary_sales_return.php?action=save',{method:'POST',body:new FormData(document.getElementById('entryForm'))})
    .then(r=>r.json())
    .then(res=>{
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Entry';
        if(res.success){
            showToast(parseInt(id)>0?'Entry updated!':'Entry added!','success');
            closeAddModal();
            setTimeout(()=>location.reload(),800);
        } else {
            showToast('Error: '+(res.message||'Unknown error'),'error');
        }
    })
    .catch(err=>{
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Entry';
        showToast('Network error: '+err.message,'error');
    });
}

/* ════ EDIT ROW ═════════════════════════════════════════════════ */
function editRow(id){
    fetch('primary_sales_return.php?action=get&id='+id)
    .then(r=>r.json())
    .then(res=>{
        if(!res.success||!res.data){showToast('Could not load record.','error');return;}
        const d=res.data;
        document.getElementById('fid').value=''+d.id;
        document.getElementById('f_invoice_date').value   =d.invoice_date    ||'';
        document.getElementById('f_invoice_no').value     =d.invoice_no      ||'';
        document.getElementById('f_entity').value          =d.entity          ||'';
        document.getElementById('f_ikea_reference').value  =d.ikea_reference  ||'';
        document.getElementById('f_cbu_code').value        =d.cbu_code        ||'';
        document.getElementById('f_cbu_description').value =d.cbu_description ||'';
        document.getElementById('f_qnt_cs').value          =d.qnt_cs          ||'';
        document.getElementById('f_amount').value          =d.amount          ||'';
        if(d.document_path&&d.document_name){
            document.getElementById('existingDocWrap').style.display='flex';
            document.getElementById('existingDocLink').href=d.document_path;
            document.getElementById('existingDocLink').textContent=d.document_name;
        }
        openAddModal('Edit Entry');
    })
    .catch(()=>showToast('Failed to load record.','error'));
}

/* ════ DELETE ═══════════════════════════════════════════════════ */
function deleteRow(id){
    if(!confirm('Delete this entry and all its settlements? This cannot be undone.'))return;
    const fd=new FormData();fd.append('id',id);
    fetch('primary_sales_return.php?action=delete',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(res=>{
        if(res.success){document.getElementById('tr-'+id)?.remove();showToast('Entry deleted.','success');}
        else showToast('Delete failed.','error');
    })
    .catch(()=>showToast('Network error.','error'));
}

/* ════ SETTLEMENT MODAL ═════════════════════════════════════════ */
let sRowCount=0;

function openSettleModal(psrId,invoiceNo,amount){
    sRowCount=0;
    document.getElementById('s_psr_id').value=psrId;
    document.getElementById('s_inv_amount').value=amount;
    document.getElementById('settleTitleText').textContent='Settlement — '+invoiceNo;
    document.getElementById('si_invoice').textContent=invoiceNo;
    document.getElementById('si_amount').textContent=parseFloat(amount).toFixed(2);
    document.getElementById('st_amount').textContent=parseFloat(amount).toFixed(2);
    document.getElementById('settleRows').innerHTML='';

    fetch('primary_sales_return.php?action=get_settlements&psr_id='+psrId)
    .then(r=>r.json())
    .then(res=>{
        if(res.success&&res.data.length){res.data.forEach(s=>addSettleRow(s));}
        else addSettleRow();
        recalcSettle();
    })
    .catch(()=>{addSettleRow();recalcSettle();});

    document.getElementById('settleModal').classList.add('open');
}
function closeSettleModal(){document.getElementById('settleModal').classList.remove('open');}

function addSettleRow(data){
    sRowCount++;
    const rid='sr'+sRowCount;
    const tr=document.createElement('tr');
    tr.id=rid;
    const cd=data?.claim_date||'';
    const cr=escAttr(data?.claim_reference);
    const en=escAttr(data?.entity);
    const di=escAttr(data?.deduct_invoice_no);
    const am=data?.claimed_amount||'';
    const rm=escAttr(data?.remarks);
    tr.innerHTML=`
        <td><input type="date" class="settle-input" value="${cd}"></td>
        <td><input type="text" class="settle-input" placeholder="REF-001" value="${cr}"></td>
        <td><input type="text" class="settle-input" placeholder="Entity" value="${en}"></td>
        <td><input type="text" class="settle-input" placeholder="INV-001" value="${di}"></td>
        <td><input type="number" step="0.01" min="0" class="settle-input" placeholder="0.00" value="${am}" oninput="recalcSettle()"></td>
        <td><input type="text" class="settle-input" placeholder="Remarks" value="${rm}"></td>
        <td><button class="settle-rm" onclick="this.closest('tr').remove();recalcSettle();" title="Remove"><i class="fa-solid fa-xmark"></i></button></td>`;
    document.getElementById('settleRows').appendChild(tr);
}
function escAttr(v){return (v||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');}

function recalcSettle(){
    let total=0;
    document.querySelectorAll('#settleRows tr').forEach(tr=>{
        const v=parseFloat(tr.querySelector('input[type=number]')?.value||0);
        if(v>0) total+=v;
    });
    const amount=parseFloat(document.getElementById('s_inv_amount').value||0);
    const balance=amount-total;
    document.getElementById('si_claimed').textContent=total.toFixed(2);
    document.getElementById('si_balance').textContent=balance.toFixed(2);
    document.getElementById('si_balance').style.color=balance>0?'#d97706':(balance<0?'#dc2626':'#16a34a');
    document.getElementById('st_claimed').textContent=total.toFixed(2);
    document.getElementById('st_balance').textContent=balance.toFixed(2);
    document.getElementById('st_balance').className='stotal-val '+(balance>0?'orange':(balance<0?'red':'green'));
}

function saveSettlements(){
    const psrId=document.getElementById('s_psr_id').value;
    const trs=document.querySelectorAll('#settleRows tr');
    if(!trs.length){showToast('Add at least one settlement row.','error');return;}

    const rows=[];
    let valid=true;
    trs.forEach(tr=>{
        const inputs=tr.querySelectorAll('input');
        const amt=parseFloat(inputs[4]?.value||0);
        if(!(amt>0)){valid=false;}
        rows.push({
            claim_date:       inputs[0]?.value||'',
            claim_reference:  inputs[1]?.value||'',
            entity:           inputs[2]?.value||'',
            deduct_invoice_no:inputs[3]?.value||'',
            claimed_amount:   amt,
            remarks:          inputs[5]?.value||''
        });
    });
    if(!valid){showToast('Each row must have a claimed amount > 0.','error');return;}

    const btn=document.getElementById('saveSettleBtn');
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const fd=new FormData();
    fd.append('psr_id',psrId);
    fd.append('rows',JSON.stringify(rows));

    fetch('primary_sales_return.php?action=save_settlements',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(res=>{
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Settlements';
        if(res.success){
            showToast('Settlements saved!','success');
            // Update row cells instantly
            const total=rows.reduce((s,r)=>s+(r.claimed_amount||0),0);
            const amount=parseFloat(document.getElementById('s_inv_amount').value||0);
            const balance=amount-total;
            const balCl=balance>0?'bal-pos':(balance<0?'bal-neg':'bal-zero');
            const tc=document.getElementById('td-claimed-'+psrId);
            const tb=document.getElementById('td-balance-'+psrId);
            if(tc) tc.textContent=total>0?total.toFixed(2):'—';
            if(tb) tb.innerHTML=`<span class="${balCl}">${balance.toFixed(2)}</span>`;
            const sb=document.getElementById('settlebtn-'+psrId);
            if(sb){sb.className='btn btn-settle btn-xs settled';sb.innerHTML='<i class="fa-solid fa-check-circle"></i> Settled';}
            closeSettleModal();
        } else {
            showToast('Error: '+(res.message||'Failed'),'error');
        }
    })
    .catch(err=>{
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Settlements';
        showToast('Network error: '+err.message,'error');
    });
}

/* ════ TOAST ════════════════════════════════════════════════════ */
function showToast(msg,type){
    const t=document.getElementById('toast');
    t.textContent=msg;t.className=type;t.style.display='block';
    clearTimeout(t._t);t._t=setTimeout(()=>t.style.display='none',3500);
}
</script>
<?php include 'footer.php'; ?>