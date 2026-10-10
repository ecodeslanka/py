<?php
include 'config.php';

// ══════════════════════════════════════════════════════════════════
//  AJAX — BEFORE header.php
// ══════════════════════════════════════════════════════════════════
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS others_cc (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        paid_date           DATE NULL,
        remarks             VARCHAR(500) NULL,
        description         VARCHAR(500) NULL,
        paid_amount         DECIMAL(14,2) NULL,
        vat                 DECIMAL(14,2) NULL,
        total_amount        DECIMAL(14,2) NULL,
        document_path       VARCHAR(500) NULL,
        document_name       VARCHAR(255) NULL,
        created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS others_cc_settlements (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        others_cc_id        INT NOT NULL,
        received_amount     DECIMAL(14,2) NULL,
        tax_invoice_no      VARCHAR(255) NULL,
        claim_reference     VARCHAR(255) NULL,
        banking_date        DATE NULL,
        created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_occ (others_cc_id)
    )");

    $action = $_GET['action'];

    // ── Save main record ─────────────────────────────────────────
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id          = intval($_POST['id'] ?? 0);
        $paid_date   = mysqli_real_escape_string($conn, trim($_POST['paid_date']   ?? ''));
        $remarks     = mysqli_real_escape_string($conn, trim($_POST['remarks']     ?? ''));
        $description = mysqli_real_escape_string($conn, trim($_POST['description'] ?? ''));
        $paid_amount = is_numeric($_POST['paid_amount'] ?? '') ? floatval($_POST['paid_amount']) : null;
        $vat         = is_numeric($_POST['vat']         ?? '') ? floatval($_POST['vat'])         : null;
        $total_amount= is_numeric($_POST['total_amount']?? '') ? floatval($_POST['total_amount']): null;

        // File upload
        $doc_path = null; $doc_name = null;
        if (!empty($_FILES['document']['name']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/others_cc/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext     = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png'];
            if (!in_array($ext, $allowed)) { echo json_encode(['success'=>false,'message'=>'Invalid file type.']); exit; }
            $safe = uniqid('occ_',true).'.'.$ext;
            if (move_uploaded_file($_FILES['document']['tmp_name'], $upload_dir.$safe)) {
                $doc_path = mysqli_real_escape_string($conn, $upload_dir.$safe);
                $doc_name = mysqli_real_escape_string($conn, $_FILES['document']['name']);
            }
        }

        $pd_sql  = $paid_date    ? "'$paid_date'"    : 'NULL';
        $pa_sql  = $paid_amount  !== null ? $paid_amount  : 'NULL';
        $vt_sql  = $vat          !== null ? $vat          : 'NULL';
        $ta_sql  = $total_amount !== null ? $total_amount : 'NULL';

        if ($id > 0) {
            $doc_part = $doc_path !== null ? ", document_path='$doc_path', document_name='$doc_name'" : '';
            $sql = "UPDATE others_cc SET
                        paid_date=$pd_sql, remarks='$remarks', description='$description',
                        paid_amount=$pa_sql, vat=$vt_sql, total_amount=$ta_sql $doc_part
                    WHERE id=$id";
        } else {
            $dp = $doc_path !== null ? "'$doc_path'" : 'NULL';
            $dn = $doc_name !== null ? "'$doc_name'" : 'NULL';
            $sql = "INSERT INTO others_cc
                        (paid_date,remarks,description,paid_amount,vat,total_amount,document_path,document_name)
                    VALUES ($pd_sql,'$remarks','$description',$pa_sql,$vt_sql,$ta_sql,$dp,$dn)";
        }
        $ok = mysqli_query($conn, $sql);
        echo json_encode(['success'=>(bool)$ok,'id'=>($ok&&!$id)?mysqli_insert_id($conn):$id,'message'=>$ok?'':mysqli_error($conn)]);
        exit;
    }

    // ── Get single record ─────────────────────────────────────────
    if ($action === 'get') {
        $id  = intval($_GET['id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn,"SELECT * FROM others_cc WHERE id=$id"));
        echo json_encode(['success'=>(bool)$row,'data'=>$row]);
        exit;
    }

    // ── Delete ────────────────────────────────────────────────────
    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id  = intval($_POST['id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn,"SELECT document_path FROM others_cc WHERE id=$id"));
        if ($row && $row['document_path'] && file_exists($row['document_path'])) @unlink($row['document_path']);
        mysqli_query($conn,"DELETE FROM others_cc_settlements WHERE others_cc_id=$id");
        $ok = mysqli_query($conn,"DELETE FROM others_cc WHERE id=$id");
        echo json_encode(['success'=>(bool)$ok]);
        exit;
    }

    // ── Save settlements ──────────────────────────────────────────
    if ($action === 'save_settlements' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $occ_id    = intval($_POST['occ_id'] ?? 0);
        if (!$occ_id) { echo json_encode(['success'=>false,'message'=>'Invalid record.']); exit; }
        $rows_data = json_decode($_POST['rows'] ?? '[]', true);
        if (!is_array($rows_data)) { echo json_encode(['success'=>false,'message'=>'Invalid data.']); exit; }

        mysqli_query($conn,"DELETE FROM others_cc_settlements WHERE others_cc_id=$occ_id");
        foreach ($rows_data as $r) {
            $recv    = is_numeric($r['received_amount'] ?? '') ? floatval($r['received_amount']) : 'NULL';
            $tinv    = mysqli_real_escape_string($conn, trim($r['tax_invoice_no']  ?? ''));
            $cref    = mysqli_real_escape_string($conn, trim($r['claim_reference'] ?? ''));
            $bdate   = mysqli_real_escape_string($conn, trim($r['banking_date']    ?? ''));
            $bd_sql  = $bdate ? "'$bdate'" : 'NULL';
            mysqli_query($conn,"INSERT INTO others_cc_settlements (others_cc_id,received_amount,tax_invoice_no,claim_reference,banking_date)
                VALUES ($occ_id,$recv,'$tinv','$cref',$bd_sql)");
        }
        echo json_encode(['success'=>true]);
        exit;
    }

    // ── Get settlements ───────────────────────────────────────────
    if ($action === 'get_settlements') {
        $occ_id = intval($_GET['occ_id'] ?? 0);
        $res    = mysqli_query($conn,"SELECT * FROM others_cc_settlements WHERE others_cc_id=$occ_id ORDER BY id");
        $data   = [];
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
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS others_cc (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    paid_date           DATE NULL,
    remarks             VARCHAR(500) NULL,
    description         VARCHAR(500) NULL,
    paid_amount         DECIMAL(14,2) NULL,
    vat                 DECIMAL(14,2) NULL,
    total_amount        DECIMAL(14,2) NULL,
    document_path       VARCHAR(500) NULL,
    document_name       VARCHAR(255) NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS others_cc_settlements (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    others_cc_id        INT NOT NULL,
    received_amount     DECIMAL(14,2) NULL,
    tax_invoice_no      VARCHAR(255) NULL,
    claim_reference     VARCHAR(255) NULL,
    banking_date        DATE NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_occ (others_cc_id)
)");

// Filters
$filter_date_from = isset($_GET['filter_date_from']) ? mysqli_real_escape_string($conn,$_GET['filter_date_from']) : '';
$filter_date_to   = isset($_GET['filter_date_to'])   ? mysqli_real_escape_string($conn,$_GET['filter_date_to'])   : '';
$filter_remarks   = isset($_GET['filter_remarks'])   ? mysqli_real_escape_string($conn,$_GET['filter_remarks'])   : '';

$where = [];
if ($filter_date_from) $where[] = "p.paid_date >= '$filter_date_from'";
if ($filter_date_to)   $where[] = "p.paid_date <= '$filter_date_to'";
if ($filter_remarks)   $where[] = "p.remarks LIKE '%$filter_remarks%'";
$where_sql = $where ? 'WHERE '.implode(' AND ',$where) : '';

$rows_result = mysqli_query($conn,
    "SELECT p.*,
            COALESCE((SELECT SUM(s.received_amount) FROM others_cc_settlements s WHERE s.others_cc_id=p.id),0) AS total_received,
            (SELECT MAX(s.banking_date)   FROM others_cc_settlements s WHERE s.others_cc_id=p.id) AS last_banking_date,
            (SELECT GROUP_CONCAT(s.tax_invoice_no ORDER BY s.id SEPARATOR ', ') FROM others_cc_settlements s WHERE s.others_cc_id=p.id) AS tax_inv_refs,
            (SELECT s.claim_reference FROM others_cc_settlements s WHERE s.others_cc_id=p.id ORDER BY s.id DESC LIMIT 1) AS last_claim_ref
     FROM others_cc p $where_sql
     ORDER BY p.paid_date DESC, p.id DESC"
);

$totals = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as cnt,
            SUM(p.paid_amount)  as total_paid,
            SUM(p.vat)          as total_vat,
            SUM(p.total_amount) as total_with_vat,
            COALESCE((SELECT SUM(s.received_amount) FROM others_cc_settlements s
                      INNER JOIN others_cc p2 ON s.others_cc_id=p2.id
                      ".($where ? str_replace('p.','p2.',$where_sql) : '')."),0) as total_received
     FROM others_cc p $where_sql"
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
.btn-dark{background:#000;color:#fff}.btn-dark:hover{background:#1f2937}
.btn-light{background:#f9fafb;color:#374151;border:1px solid #d1d5db}.btn-light:hover{background:#f3f4f6}
.btn-settle{background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe}.btn-settle:hover{background:#7c3aed;color:#fff}
.btn-settle.settled{background:#f0fdf4;color:#16a34a;border-color:#86efac}

.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:20px}
.summary-box{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:16px}
.summary-label{font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px}
.summary-value{font-size:19px;font-weight:700;color:#1f2937}
.summary-sub{font-size:11px;color:#9ca3af;margin-top:2px}

.fbar-group{display:flex;flex-direction:column;gap:4px}
.fbar-label{font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.4px}
.fbar-input,.fbar-select{padding:8px 12px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;outline:none;transition:border-color .2s;height:36px;box-sizing:border-box;background:#fff}
.fbar-input:focus,.fbar-select:focus{border-color:#000}

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
.doc-icon-link{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:6px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;font-size:13px;transition:all .2s;text-decoration:none}
.doc-icon-link:hover{background:#1e40af;color:#fff;border-color:#1e40af}
.no-doc{color:#d1d5db;font-size:16px}
.bal-pos{color:#16a34a;font-weight:700}
.bal-neg{color:#dc2626;font-weight:700}
.bal-zero{color:#6b7280;font-weight:600}
.vat-pill{font-size:11px;font-weight:700;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;border-radius:10px;padding:2px 8px}
.claim-pill{font-size:11px;font-family:monospace;background:#fdf4ff;color:#7e22ce;border:1px solid #e9d5ff;border-radius:5px;padding:2px 7px;white-space:nowrap}
.taxref-pill{font-size:11px;font-family:monospace;background:#f8fafc;color:#475569;border:1px solid #e2e8f0;border-radius:5px;padding:2px 7px}

/* ── Modals ────────────────────────────────────────────────────── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000;display:none;align-items:center;justify-content:center;padding:16px}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:12px;width:96%;max-width:700px;max-height:93vh;display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,.22);overflow:hidden}
.settle-modal-box{background:#fff;border-radius:12px;width:96%;max-width:940px;max-height:93vh;display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,.22);overflow:hidden}
.modal-header{padding:20px 26px 16px;border-bottom:1px solid #f0f0f0;display:flex;justify-content:space-between;align-items:center;flex-shrink:0}
.modal-title{font-size:18px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px}
.modal-close{background:none;border:none;cursor:pointer;color:#9ca3af;font-size:26px;line-height:1;padding:0;transition:color .2s}
.modal-close:hover{color:#1f2937}
.modal-body{padding:24px 26px;overflow-y:auto;flex:1}
.modal-footer{padding:16px 26px;border-top:1px solid #f0f0f0;display:flex;justify-content:flex-end;gap:10px;flex-shrink:0}

/* ── Form ──────────────────────────────────────────────────────── */
.form-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px}
.form-grid .span2{grid-column:span 2}
.form-grid .span3{grid-column:span 3}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-label{font-size:12px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:.4px}
.form-label span.auto{font-size:10px;background:#dbeafe;color:#1e40af;border-radius:4px;padding:1px 6px;font-weight:700;text-transform:none;letter-spacing:0;margin-left:4px}
.form-input,.form-select{padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;font-family:'Inter',sans-serif;color:#1f2937;outline:none;transition:border-color .2s,box-shadow .2s;background:#fff;width:100%;box-sizing:border-box}
.form-input:focus,.form-select:focus{border-color:#000;box-shadow:0 0 0 3px rgba(0,0,0,.06)}
.form-input.auto-fill{background:#f0fdf4;border-color:#bbf7d0;color:#166534;font-weight:700}
.form-divider{grid-column:span 3;border:none;border-top:1px solid #f0f0f0;margin:4px 0}
.vat-hint{font-size:11px;color:#6b7280;margin-top:3px;display:flex;align-items:center;gap:4px}
.vat-hint b{color:#7c3aed}

input[type=number]::-webkit-inner-spin-button,
input[type=number]::-webkit-outer-spin-button{-webkit-appearance:none;margin:0}
input[type=number]{-moz-appearance:textfield;appearance:textfield}

/* ── File upload ───────────────────────────────────────────────── */
.file-drop{border:2px dashed #d1d5db;border-radius:8px;padding:18px;text-align:center;cursor:pointer;transition:all .2s;background:#fafafa;position:relative}
.file-drop:hover,.file-drop.drag{border-color:#000;background:#f5f5f5}
.file-drop input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.file-drop-icon{font-size:22px;color:#9ca3af;margin-bottom:5px}
.file-drop-text{font-size:13px;color:#6b7280;font-weight:500}
.file-drop-sub{font-size:11px;color:#9ca3af;margin-top:2px}
.file-preview{display:none;align-items:center;gap:10px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:10px 14px;margin-top:8px}
.file-preview.show{display:flex}
.file-preview-name{font-size:13px;color:#166534;font-weight:600;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.file-preview-rm{background:none;border:none;cursor:pointer;color:#dc2626;font-size:15px;padding:2px 5px;border-radius:4px}
.existing-doc{display:flex;align-items:center;gap:8px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:8px 12px;margin-bottom:8px;font-size:13px}
.existing-doc a{color:#1e40af;font-weight:600;text-decoration:none;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.existing-doc a:hover{text-decoration:underline}

/* ── Settlement modal ──────────────────────────────────────────── */
.settle-info-strip{padding:12px 26px;background:#f9fafb;border-bottom:1px solid #f0f0f0;display:flex;gap:20px;flex-wrap:wrap;flex-shrink:0}
.sinfo-cell{display:flex;flex-direction:column;gap:2px}
.sinfo-lbl{font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px}
.sinfo-val{font-size:14px;font-weight:700;color:#1f2937}
.sinfo-val.green{color:#16a34a}

.settle-table{width:100%;border-collapse:collapse;font-size:13px;min-width:680px}
.settle-table th{background:#f9fafb;padding:8px 10px;text-align:left;font-weight:600;color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #e5e5e5;white-space:nowrap}
.settle-table th.num{text-align:right}
.settle-table td{padding:5px 6px;vertical-align:middle;border-bottom:1px solid #f5f5f5}
.settle-input{width:100%;padding:6px 9px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;font-family:'Inter',sans-serif;outline:none;transition:border-color .2s;box-sizing:border-box;background:#fff}
.settle-input:focus{border-color:#7c3aed;box-shadow:0 0 0 2px rgba(124,58,237,.08)}
.settle-input[type=number]{text-align:right}
.settle-input.running-bal{background:#fdf4ff;color:#7e22ce;font-weight:700;border-color:#e9d5ff;cursor:default}
.settle-rm{background:none;border:none;cursor:pointer;color:#dc2626;font-size:15px;padding:4px 7px;border-radius:4px;transition:background .15s;line-height:1}
.settle-rm:hover{background:#fef2f2}
.add-settle-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border:1px dashed #c4b5fd;border-radius:6px;background:#fff;color:#7c3aed;font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;margin-top:10px;transition:all .2s}
.add-settle-btn:hover{background:#f5f3ff;border-color:#7c3aed}
.settle-note{font-size:11px;color:#9ca3af;margin-top:6px;display:flex;align-items:center;gap:5px}

.settle-totals{display:flex;gap:24px;padding:12px 0;margin-top:12px;border-top:2px solid #e5e5e5;flex-wrap:wrap}
.stotal-cell{display:flex;flex-direction:column;gap:2px}
.stotal-lbl{font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px}
.stotal-val{font-size:17px;font-weight:800;color:#1f2937}
.stotal-val.green{color:#16a34a}.stotal-val.red{color:#dc2626}.stotal-val.orange{color:#d97706}.stotal-val.purple{color:#7c3aed}

#toast{position:fixed;bottom:28px;right:28px;padding:12px 22px;border-radius:8px;font-size:14px;font-weight:600;color:#fff;z-index:9999;display:none;box-shadow:0 4px 16px rgba(0,0,0,.18)}
#toast.success{background:#16a34a}
#toast.error{background:#dc2626}

.empty-state{text-align:center;padding:60px 20px;color:#9ca3af}
.empty-state i{font-size:40px;margin-bottom:12px;display:block}
.empty-state p{font-size:15px;font-weight:500}

@media(max-width:700px){
    .form-grid{grid-template-columns:1fr 1fr}
    .form-grid .span3,.form-divider{grid-column:span 2}
    .summary-grid{grid-template-columns:1fr 1fr}
}
@media(max-width:480px){
    .form-grid{grid-template-columns:1fr}
    .form-grid .span2,.form-grid .span3,.form-divider{grid-column:span 1}
}
</style>

<!-- Page Header -->
<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h2 class="page-title"><i class="fa-solid fa-circle-dot"></i> Others (CC)</h2>
            <p class="page-subtitle">Manage other CC claim entries and settlements</p>
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
        <div class="summary-label">Total Paid Amount</div>
        <div class="summary-value"><?php echo $totals['total_paid']!==null?number_format($totals['total_paid'],2):'—'; ?></div>
        <div class="summary-sub">Before VAT</div>
    </div>
    <div class="summary-box">
        <div class="summary-label">Total VAT</div>
        <div class="summary-value" style="color:#7c3aed;"><?php echo $totals['total_vat']!==null?number_format($totals['total_vat'],2):'—'; ?></div>
        <div class="summary-sub">18% VAT sum</div>
    </div>
    <div class="summary-box">
        <div class="summary-label">Total to Receive</div>
        <div class="summary-value" style="color:#0369a1;"><?php echo $totals['total_with_vat']!==null?number_format($totals['total_with_vat'],2):'—'; ?></div>
        <div class="summary-sub">Amount + VAT</div>
    </div>
    <div class="summary-box">
        <div class="summary-label">Total Received</div>
        <div class="summary-value" style="color:#16a34a;"><?php echo number_format($totals['total_received']??0,2); ?></div>
        <div class="summary-sub">Settlement receipts</div>
    </div>
    <div class="summary-box">
        <?php $diff=($totals['total_with_vat']??0)-($totals['total_received']??0); ?>
        <div class="summary-label">Balance to Receive</div>
        <div class="summary-value" style="color:<?php echo $diff>0?'#d97706':($diff<0?'#dc2626':'#16a34a'); ?>">
            <?php echo number_format(abs($diff),2); ?>
        </div>
        <div class="summary-sub">To Receive vs Received</div>
    </div>
</div>

<!-- Records Table -->
<div class="content-card">
    <div class="card-header-row">
        <h3 class="card-title"><i class="fa-solid fa-table"></i> Entry Records</h3>
        <form method="GET" action="" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
            <div class="fbar-group">
                <label class="fbar-label">Date From</label>
                <input type="date" name="filter_date_from" class="fbar-input" value="<?php echo htmlspecialchars($filter_date_from); ?>" style="width:130px;">
            </div>
            <div class="fbar-group">
                <label class="fbar-label">Date To</label>
                <input type="date" name="filter_date_to" class="fbar-input" value="<?php echo htmlspecialchars($filter_date_to); ?>" style="width:130px;">
            </div>
            <div class="fbar-group">
                <label class="fbar-label">Remarks</label>
                <input type="text" name="filter_remarks" class="fbar-input" placeholder="Filter remarks…" value="<?php echo htmlspecialchars($filter_remarks); ?>" style="width:140px;">
            </div>
            <button type="submit" class="btn btn-dark btn-sm"><i class="fa-solid fa-filter"></i> Filter</button>
            <a href="others_cc.php" class="btn btn-light btn-sm"><i class="fa-solid fa-xmark"></i> Clear</a>
        </form>
    </div>

    <div class="toolbar">
        <div class="search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" class="search-input" id="searchBox" placeholder="Search remarks, description, claim ref…" oninput="doSearch()">
        </div>
        <span id="rowCount" style="font-size:12px;color:#6b7280;"></span>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="text-align:center;width:36px;" title="Document"><i class="fa-solid fa-paperclip"></i></th>
                    <th>#</th>
                    <th>Paid Date by Yelo</th>
                    <th>Remarks</th>
                    <th>Description</th>
                    <th class="num">Paid Amount</th>
                    <th class="num">VAT 18%</th>
                    <th class="num">Total to be Received</th>
                    <th class="num">Received</th>
                    <th class="num">Balance</th>
                    <th>Last Banking Date</th>
                    <th>Claim Reference</th>
                    <th>Tax Invoice Refs</th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody id="tableBody">
            <?php if (!$rows_result || mysqli_num_rows($rows_result) === 0): ?>
                <tr><td colspan="14"><div class="empty-state"><i class="fa-solid fa-circle-dot"></i><p>No records found. Click <strong>Add New Entry</strong> to get started.</p></div></td></tr>
            <?php else: $rn=1; while ($r=mysqli_fetch_assoc($rows_result)):
                $total   = floatval($r['total_amount']??0);
                $received= floatval($r['total_received']??0);
                $balance = $total - $received;
                $balCl   = $balance>0?'bal-pos':($balance<0?'bal-neg':'bal-zero');
            ?>
                <tr id="tr-<?php echo $r['id']; ?>"
                    data-search="<?php echo strtolower(htmlspecialchars(($r['remarks']??'').' '.($r['description']??'').' '.($r['last_claim_ref']??'').' '.($r['tax_inv_refs']??''))); ?>">
                    <td style="text-align:center;">
                        <?php if ($r['document_path']&&file_exists($r['document_path'])): ?>
                            <a href="<?php echo htmlspecialchars($r['document_path']); ?>" target="_blank" class="doc-icon-link" title="<?php echo htmlspecialchars($r['document_name']??'Document'); ?>"><i class="fa-solid fa-paperclip"></i></a>
                        <?php else: ?><span class="no-doc"><i class="fa-solid fa-minus"></i></span><?php endif; ?>
                    </td>
                    <td><?php echo $rn++; ?></td>
                    <td><?php echo !empty($r['paid_date'])?date('d M Y',strtotime($r['paid_date'])):'—'; ?></td>
                    <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($r['remarks']??''); ?>"><?php echo htmlspecialchars($r['remarks']??'—'); ?></td>
                    <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($r['description']??''); ?>"><?php echo htmlspecialchars($r['description']??'—'); ?></td>
                    <td class="num"><?php echo $r['paid_amount']!==null?number_format($r['paid_amount'],2):'—'; ?></td>
                    <td class="num"><span class="vat-pill"><?php echo $r['vat']!==null?number_format($r['vat'],2):'—'; ?></span></td>
                    <td class="num" style="font-weight:700;color:#0369a1;"><?php echo $r['total_amount']!==null?number_format($total,2):'—'; ?></td>
                    <td class="num" id="td-received-<?php echo $r['id']; ?>" style="color:#16a34a;font-weight:700;"><?php echo $received>0?number_format($received,2):'—'; ?></td>
                    <td class="num" id="td-balance-<?php echo $r['id']; ?>"><span class="<?php echo $balCl; ?>"><?php echo number_format($balance,2); ?></span></td>
                    <td id="td-lastbd-<?php echo $r['id']; ?>" style="font-size:12px;color:#6b7280;"><?php echo !empty($r['last_banking_date'])?date('d M Y',strtotime($r['last_banking_date'])):'—'; ?></td>
                    <td id="td-claimref-<?php echo $r['id']; ?>">
                        <?php if(!empty($r['last_claim_ref'])): ?>
                            <span class="claim-pill"><?php echo htmlspecialchars($r['last_claim_ref']); ?></span>
                        <?php else: ?><span style="color:#d1d5db;">—</span><?php endif; ?>
                    </td>
                    <td id="td-taxrefs-<?php echo $r['id']; ?>" style="font-size:12px;color:#6b7280;max-width:130px;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($r['tax_inv_refs']??''); ?>">
                        <?php if(!empty($r['tax_inv_refs'])): ?>
                            <span class="taxref-pill"><?php echo htmlspecialchars($r['tax_inv_refs']); ?></span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <div class="action-buttons">
                            <button class="btn btn-settle btn-xs<?php echo $received>0?' settled':''; ?>"
                                    id="settlebtn-<?php echo $r['id']; ?>"
                                    onclick="openSettleModal(<?php echo $r['id']; ?>,'<?php echo addslashes(htmlspecialchars($r['remarks']??'')); ?>',<?php echo $total; ?>)">
                                <i class="fa-solid fa-<?php echo $received>0?'check-circle':'hand-holding-dollar'; ?>"></i>
                                <?php echo $received>0?'Settled':'Settle'; ?>
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
                    <td colspan="5" style="text-align:right;">Totals</td>
                    <td class="num"><?php echo $totals['total_paid']!==null?number_format($totals['total_paid'],2):'—'; ?></td>
                    <td class="num" style="color:#7c3aed;"><?php echo $totals['total_vat']!==null?number_format($totals['total_vat'],2):'—'; ?></td>
                    <td class="num" style="color:#0369a1;font-weight:700;"><?php echo $totals['total_with_vat']!==null?number_format($totals['total_with_vat'],2):'—'; ?></td>
                    <td class="num" style="color:#16a34a;"><?php echo number_format($totals['total_received']??0,2); ?></td>
                    <td class="num" style="color:#d97706;"><?php echo number_format(($totals['total_with_vat']??0)-($totals['total_received']??0),2); ?></td>
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
            <div class="modal-title"><i class="fa-solid fa-circle-dot"></i><span id="modalTitleText">Add New Entry</span></div>
            <button class="modal-close" onclick="closeAddModal()">×</button>
        </div>
        <div class="modal-body">
            <form id="entryForm" enctype="multipart/form-data" onsubmit="return false;">
                <input type="hidden" id="fid" name="id" value="0">
                <div class="form-grid">

                    <!-- Row 1: Paid Date -->
                    <div class="form-group">
                        <label class="form-label">Paid Date by Yelo</label>
                        <input type="date" class="form-input" id="f_paid_date" name="paid_date">
                    </div>
                    <div></div><div></div>

                    <!-- Row 2: Remarks -->
                    <div class="form-group span3">
                        <label class="form-label">Remarks</label>
                        <input type="text" class="form-input" id="f_remarks" name="remarks" placeholder="e.g. Monthly incentive claim — IKEA">
                    </div>

                    <!-- Row 3: Description -->
                    <div class="form-group span3">
                        <label class="form-label">Description</label>
                        <input type="text" class="form-input" id="f_description" name="description" placeholder="e.g. CC incentive payment for March 2024">
                    </div>

                    <hr class="form-divider">

                    <!-- Row 4: Paid Amount | VAT | Total -->
                    <div class="form-group">
                        <label class="form-label">Paid Amount</label>
                        <input type="number" step="0.01" min="0" class="form-input" id="f_paid_amount" name="paid_amount" placeholder="0.00" oninput="calcVAT()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">VAT 18% <span class="auto">AUTO</span></label>
                        <input type="number" step="0.01" class="form-input auto-fill" id="f_vat" name="vat" placeholder="0.00" readonly>
                        <div class="vat-hint"><i class="fa-solid fa-bolt" style="color:#7c3aed;font-size:10px;"></i> Paid Amount × <b>18%</b></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Total Amount to be Received <span class="auto">AUTO</span></label>
                        <input type="number" step="0.01" class="form-input auto-fill" id="f_total_amount" name="total_amount" placeholder="0.00" readonly>
                        <div class="vat-hint"><i class="fa-solid fa-bolt" style="color:#7c3aed;font-size:10px;"></i> Paid Amount + VAT</div>
                    </div>

                    <hr class="form-divider">

                    <!-- Document -->
                    <div class="form-group span3">
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

                </div><!-- /form-grid -->
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
            <div class="sinfo-cell"><div class="sinfo-lbl">Remarks</div><div class="sinfo-val" id="si_remarks">—</div></div>
            <div class="sinfo-cell"><div class="sinfo-lbl">Total to be Received</div><div class="sinfo-val" id="si_total">—</div></div>
            <div class="sinfo-cell"><div class="sinfo-lbl">Total Received</div><div class="sinfo-val green" id="si_received">0.00</div></div>
            <div class="sinfo-cell"><div class="sinfo-lbl">Balance to be Received</div><div class="sinfo-val" id="si_balance">—</div></div>
        </div>
        <div class="modal-body">
            <input type="hidden" id="s_occ_id" value="0">
            <input type="hidden" id="s_total_amount" value="0">
            <div style="overflow-x:auto;">
                <table class="settle-table">
                    <thead>
                        <tr>
                            <th style="min-width:120px;" class="num">Received Amount</th>
                            <th style="min-width:130px;" class="num">Balance to be Received</th>
                            <th style="min-width:150px;">Tax Invoice No</th>
                            <th style="min-width:160px;">Claim Reference</th>
                            <th style="min-width:130px;">Banking Date</th>
                            <th style="width:36px;"></th>
                        </tr>
                    </thead>
                    <tbody id="settleRows"></tbody>
                </table>
            </div>
            <button class="add-settle-btn" onclick="addSettleRow()"><i class="fa-solid fa-plus"></i> Add Row</button>
            <div class="settle-note"><i class="fa-solid fa-info-circle"></i> Balance to be Received is a running balance — auto-calculated per row.</div>
            <div class="settle-totals">
                <div class="stotal-cell"><div class="stotal-lbl">Total to be Received</div><div class="stotal-val" id="st_total">0.00</div></div>
                <div class="stotal-cell"><div class="stotal-lbl">Total Received</div><div class="stotal-val purple" id="st_received">0.00</div></div>
                <div class="stotal-cell"><div class="stotal-lbl">Balance to be Received</div><div class="stotal-val orange" id="st_balance">0.00</div></div>
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
/* ════ VAT AUTO CALC ════════════════════════════════════════════ */
function calcVAT(){
    const amt = parseFloat(document.getElementById('f_paid_amount').value||0)||0;
    const vat = Math.round(amt * 0.18 * 100) / 100;
    const total = Math.round((amt + vat) * 100) / 100;
    document.getElementById('f_vat').value          = amt > 0 ? vat.toFixed(2)   : '';
    document.getElementById('f_total_amount').value = amt > 0 ? total.toFixed(2) : '';
}

/* ════ SEARCH ═══════════════════════════════════════════════════ */
function doSearch(){
    const q=document.getElementById('searchBox').value.toLowerCase().trim();
    const rows=document.querySelectorAll('#tableBody tr[id^="tr-"]');
    let vis=0;
    rows.forEach(r=>{
        const show=!q||(r.dataset.search||'').includes(q);
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
    ['f_paid_date','f_remarks','f_description',
     'f_paid_amount','f_vat','f_total_amount'].forEach(id=>{
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
    const id=document.getElementById('fid').value;
    const btn=document.getElementById('saveBtn');
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    fetch('others_cc.php?action=save',{method:'POST',body:new FormData(document.getElementById('entryForm'))})
    .then(r=>r.json())
    .then(res=>{
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Entry';
        if(res.success){
            showToast(parseInt(id)>0?'Entry updated!':'Entry added!','success');
            closeAddModal();
            setTimeout(()=>location.reload(),800);
        } else showToast('Error: '+(res.message||'Unknown error'),'error');
    })
    .catch(err=>{
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Entry';
        showToast('Network error: '+err.message,'error');
    });
}

/* ════ EDIT ROW ═════════════════════════════════════════════════ */
function editRow(id){
    fetch('others_cc.php?action=get&id='+id)
    .then(r=>r.json())
    .then(res=>{
        if(!res.success||!res.data){showToast('Could not load record.','error');return;}
        const d=res.data;
        document.getElementById('fid').value               =''+d.id;
        document.getElementById('f_paid_date').value       =d.paid_date    ||'';
        document.getElementById('f_remarks').value         =d.remarks      ||'';
        document.getElementById('f_description').value     =d.description  ||'';
        document.getElementById('f_paid_amount').value     =d.paid_amount  ||'';
        document.getElementById('f_vat').value             =d.vat          ||'';
        document.getElementById('f_total_amount').value    =d.total_amount ||'';
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
    fetch('others_cc.php?action=delete',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(res=>{
        if(res.success){document.getElementById('tr-'+id)?.remove();showToast('Entry deleted.','success');}
        else showToast('Delete failed.','error');
    })
    .catch(()=>showToast('Network error.','error'));
}

/* ════ SETTLEMENT MODAL ═════════════════════════════════════════ */
let sRowCount=0;

function openSettleModal(occId, remarks, totalAmount){
    sRowCount=0;
    document.getElementById('s_occ_id').value=occId;
    document.getElementById('s_total_amount').value=totalAmount;
    document.getElementById('si_remarks').textContent=remarks||'—';
    document.getElementById('si_total').textContent=parseFloat(totalAmount).toFixed(2);
    document.getElementById('st_total').textContent=parseFloat(totalAmount).toFixed(2);
    document.getElementById('settleRows').innerHTML='';

    fetch('others_cc.php?action=get_settlements&occ_id='+occId)
    .then(r=>r.json())
    .then(res=>{
        if(res.success&&res.data.length) res.data.forEach(s=>addSettleRow(s));
        else addSettleRow();
        recalcSettle();
    })
    .catch(()=>{addSettleRow();recalcSettle();});

    document.getElementById('settleModal').classList.add('open');
}
function closeSettleModal(){document.getElementById('settleModal').classList.remove('open');}

function addSettleRow(data){
    sRowCount++;
    const tr=document.createElement('tr');
    tr.id='sr'+sRowCount;
    const ra=data?.received_amount||'';
    const ti=ea(data?.tax_invoice_no);
    const cr=ea(data?.claim_reference);
    const bd=data?.banking_date||'';
    tr.innerHTML=`
        <td><input type="number" step="0.01" min="0" class="settle-input" style="text-align:right;" placeholder="0.00" value="${ra}" oninput="recalcSettle()"></td>
        <td><input type="number" step="0.01" class="settle-input running-bal" readonly placeholder="0.00" tabindex="-1"></td>
        <td><input type="text" class="settle-input" placeholder="TAX-INV-001" value="${ti}"></td>
        <td><input type="text" class="settle-input" placeholder="e.g. SRICC-2024-001" value="${cr}"></td>
        <td><input type="date" class="settle-input" value="${bd}"></td>
        <td><button class="settle-rm" onclick="this.closest('tr').remove();recalcSettle();" title="Remove"><i class="fa-solid fa-xmark"></i></button></td>`;
    document.getElementById('settleRows').appendChild(tr);
}
function ea(v){return (v||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');}

function recalcSettle(){
    const totalAmount=parseFloat(document.getElementById('s_total_amount').value||0);
    let runningBalance=totalAmount;
    let totalReceived=0;

    document.querySelectorAll('#settleRows tr').forEach(tr=>{
        const inputs=tr.querySelectorAll('input');
        const recv=parseFloat(inputs[0]?.value||0)||0;
        totalReceived+=recv;
        runningBalance-=recv;
        // Set running balance field
        if(inputs[1]) inputs[1].value=runningBalance.toFixed(2);
    });

    const finalBalance=totalAmount-totalReceived;
    document.getElementById('si_received').textContent=totalReceived.toFixed(2);
    document.getElementById('si_balance').textContent=finalBalance.toFixed(2);
    document.getElementById('si_balance').style.color=finalBalance>0?'#d97706':(finalBalance<0?'#dc2626':'#16a34a');
    document.getElementById('st_received').textContent=totalReceived.toFixed(2);
    document.getElementById('st_balance').textContent=finalBalance.toFixed(2);
    document.getElementById('st_balance').className='stotal-val '+(finalBalance>0?'orange':(finalBalance<0?'red':'green'));
}

function saveSettlements(){
    const occId=document.getElementById('s_occ_id').value;
    const trs=document.querySelectorAll('#settleRows tr');
    if(!trs.length){showToast('Add at least one settlement row.','error');return;}

    const rows=[];let valid=true;
    trs.forEach(tr=>{
        const inputs=tr.querySelectorAll('input');
        const amt=parseFloat(inputs[0]?.value||0);
        if(!(amt>0)) valid=false;
        rows.push({
            received_amount:  amt,
            tax_invoice_no:   inputs[2]?.value||'',
            claim_reference:  inputs[3]?.value||'',
            banking_date:     inputs[4]?.value||''
        });
    });
    if(!valid){showToast('Each row must have a received amount > 0.','error');return;}

    const btn=document.getElementById('saveSettleBtn');
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const fd=new FormData();
    fd.append('occ_id',occId);
    fd.append('rows',JSON.stringify(rows));

    fetch('others_cc.php?action=save_settlements',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(res=>{
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Settlements';
        if(res.success){
            showToast('Settlements saved!','success');
            const totalReceived=rows.reduce((s,r)=>s+(r.received_amount||0),0);
            const totalAmount=parseFloat(document.getElementById('s_total_amount').value||0);
            const balance=totalAmount-totalReceived;
            const balCl=balance>0?'bal-pos':(balance<0?'bal-neg':'bal-zero');
            const tr=document.getElementById('td-received-'+occId);
            const tb=document.getElementById('td-balance-'+occId);
            if(tr) tr.textContent=totalReceived>0?totalReceived.toFixed(2):'—';
            if(tb) tb.innerHTML=`<span class="${balCl}">${balance.toFixed(2)}</span>`;
            const sb=document.getElementById('settlebtn-'+occId);
            if(sb){sb.className='btn btn-settle btn-xs settled';sb.innerHTML='<i class="fa-solid fa-check-circle"></i> Settled';}
            // Update claim ref cell
            const lastRow=rows[rows.length-1];
            const tcr=document.getElementById('td-claimref-'+occId);
            if(tcr) tcr.innerHTML=lastRow?.claim_reference
                ?`<span class="claim-pill">${lastRow.claim_reference.replace(/&/g,'&amp;').replace(/</g,'&lt;')}</span>`
                :`<span style="color:#d1d5db;">—</span>`;
            // Update tax inv refs cell
            const taxRefs=rows.map(r=>r.tax_invoice_no).filter(Boolean).join(', ');
            const ttr=document.getElementById('td-taxrefs-'+occId);
            if(ttr) ttr.innerHTML=taxRefs
                ?`<span class="taxref-pill">${taxRefs.replace(/&/g,'&amp;').replace(/</g,'&lt;')}</span>`
                :'—';
            closeSettleModal();
        } else showToast('Error: '+(res.message||'Failed'),'error');
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