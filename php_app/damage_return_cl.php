<?php
include 'config.php';

// ══════════════════════════════════════════════════════════════════
//  AJAX — BEFORE header.php to avoid HTML output corruption
// ══════════════════════════════════════════════════════════════════
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS damage_returns_cl (
        id                      INT AUTO_INCREMENT PRIMARY KEY,
        handed_over_date        DATE NULL,
        year                    INT NULL,
        month                   TINYINT NULL,
        claim_description       VARCHAR(500) NULL,
        amount_ikea             DECIMAL(14,2) NULL,
        usl_collection_value    DECIMAL(14,2) NULL,
        damage_shortages        DECIMAL(14,2) NULL,
        document_path           VARCHAR(500) NULL,
        document_name           VARCHAR(255) NULL,
        created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS drcl_settlements (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        drcl_id             INT NOT NULL,
        claim_date          DATE NULL,
        claim_reference     VARCHAR(255) NULL,
        entity              VARCHAR(255) NULL,
        deduct_invoice_no   VARCHAR(255) NULL,
        claimed_amount      DECIMAL(14,2) NULL,
        remarks             TEXT NULL,
        created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_drcl (drcl_id)
    )");

    $action = $_GET['action'];

    // ── Save main record ─────────────────────────────────────────
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id                   = intval($_POST['id'] ?? 0);
        $handed_over_date     = mysqli_real_escape_string($conn, trim($_POST['handed_over_date']     ?? ''));
        $year                 = is_numeric($_POST['year']  ?? '') ? intval($_POST['year'])  : null;
        $month                = is_numeric($_POST['month'] ?? '') ? intval($_POST['month']) : null;
        $claim_description    = mysqli_real_escape_string($conn, trim($_POST['claim_description']    ?? ''));
        $amount_ikea          = is_numeric($_POST['amount_ikea']          ?? '') ? floatval($_POST['amount_ikea'])          : null;
        $usl_collection_value = is_numeric($_POST['usl_collection_value'] ?? '') ? floatval($_POST['usl_collection_value']) : null;
        $damage_shortages     = is_numeric($_POST['damage_shortages']     ?? '') ? floatval($_POST['damage_shortages'])     : null;

        // File upload
        $doc_path = null; $doc_name = null;
        if (!empty($_FILES['document']['name']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/damage_returns/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext     = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png'];
            if (!in_array($ext, $allowed)) {
                echo json_encode(['success'=>false,'message'=>'Invalid file type.']);
                exit;
            }
            $safe = uniqid('drcl_',true).'.'.$ext;
            if (move_uploaded_file($_FILES['document']['tmp_name'], $upload_dir.$safe)) {
                $doc_path = mysqli_real_escape_string($conn, $upload_dir.$safe);
                $doc_name = mysqli_real_escape_string($conn, $_FILES['document']['name']);
            }
        }

        $hod_sql  = $handed_over_date ? "'$handed_over_date'" : 'NULL';
        $yr_sql   = $year   !== null ? $year   : 'NULL';
        $mo_sql   = $month  !== null ? $month  : 'NULL';
        $ai_sql   = $amount_ikea          !== null ? $amount_ikea          : 'NULL';
        $uc_sql   = $usl_collection_value !== null ? $usl_collection_value : 'NULL';
        $ds_sql   = $damage_shortages     !== null ? $damage_shortages     : 'NULL';

        if ($id > 0) {
            $doc_part = $doc_path !== null ? ", document_path='$doc_path', document_name='$doc_name'" : '';
            $sql = "UPDATE damage_returns_cl SET
                        handed_over_date=$hod_sql, year=$yr_sql, month=$mo_sql,
                        claim_description='$claim_description',
                        amount_ikea=$ai_sql, usl_collection_value=$uc_sql, damage_shortages=$ds_sql
                        $doc_part
                    WHERE id=$id";
        } else {
            $dp = $doc_path !== null ? "'$doc_path'" : 'NULL';
            $dn = $doc_name !== null ? "'$doc_name'" : 'NULL';
            $sql = "INSERT INTO damage_returns_cl
                        (handed_over_date,year,month,claim_description,amount_ikea,usl_collection_value,damage_shortages,document_path,document_name)
                    VALUES ($hod_sql,$yr_sql,$mo_sql,'$claim_description',$ai_sql,$uc_sql,$ds_sql,$dp,$dn)";
        }
        $ok = mysqli_query($conn, $sql);
        echo json_encode(['success'=>(bool)$ok,'id'=>($ok&&!$id)?mysqli_insert_id($conn):$id,'message'=>$ok?'':mysqli_error($conn)]);
        exit;
    }

    // ── Get single record ─────────────────────────────────────────
    if ($action === 'get') {
        $id  = intval($_GET['id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn,"SELECT * FROM damage_returns_cl WHERE id=$id"));
        echo json_encode(['success'=>(bool)$row,'data'=>$row]);
        exit;
    }

    // ── Delete main record ────────────────────────────────────────
    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id  = intval($_POST['id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn,"SELECT document_path FROM damage_returns_cl WHERE id=$id"));
        if ($row && $row['document_path'] && file_exists($row['document_path'])) @unlink($row['document_path']);
        mysqli_query($conn,"DELETE FROM drcl_settlements WHERE drcl_id=$id");
        $ok = mysqli_query($conn,"DELETE FROM damage_returns_cl WHERE id=$id");
        echo json_encode(['success'=>(bool)$ok]);
        exit;
    }

    // ── Save settlements ──────────────────────────────────────────
    if ($action === 'save_settlements' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $drcl_id  = intval($_POST['drcl_id'] ?? 0);
        if (!$drcl_id) { echo json_encode(['success'=>false,'message'=>'Invalid record.']); exit; }

        $rows_data = json_decode($_POST['rows'] ?? '[]', true);
        if (!is_array($rows_data)) { echo json_encode(['success'=>false,'message'=>'Invalid data.']); exit; }

        mysqli_query($conn,"DELETE FROM drcl_settlements WHERE drcl_id=$drcl_id");
        foreach ($rows_data as $r) {
            $claim_date   = mysqli_real_escape_string($conn, trim($r['claim_date']        ?? ''));
            $claim_ref    = mysqli_real_escape_string($conn, trim($r['claim_reference']   ?? ''));
            $s_entity     = mysqli_real_escape_string($conn, trim($r['entity']            ?? ''));
            $deduct_inv   = mysqli_real_escape_string($conn, trim($r['deduct_invoice_no'] ?? ''));
            $claimed_amt  = is_numeric($r['claimed_amount'] ?? '') ? floatval($r['claimed_amount']) : 'NULL';
            $remarks      = mysqli_real_escape_string($conn, trim($r['remarks']           ?? ''));
            $cd = $claim_date ? "'$claim_date'" : 'NULL';
            mysqli_query($conn,"INSERT INTO drcl_settlements
                (drcl_id,claim_date,claim_reference,entity,deduct_invoice_no,claimed_amount,remarks)
                VALUES ($drcl_id,$cd,'$claim_ref','$s_entity','$deduct_inv',$claimed_amt,'$remarks')");
        }
        echo json_encode(['success'=>true]);
        exit;
    }

    // ── Get settlements ───────────────────────────────────────────
    if ($action === 'get_settlements') {
        $drcl_id = intval($_GET['drcl_id'] ?? 0);
        $res  = mysqli_query($conn,"SELECT * FROM drcl_settlements WHERE drcl_id=$drcl_id ORDER BY id");
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
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS damage_returns_cl (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    handed_over_date        DATE NULL,
    year                    INT NULL,
    month                   TINYINT NULL,
    claim_description       VARCHAR(500) NULL,
    amount_ikea             DECIMAL(14,2) NULL,
    usl_collection_value    DECIMAL(14,2) NULL,
    damage_shortages        DECIMAL(14,2) NULL,
    document_path           VARCHAR(500) NULL,
    document_name           VARCHAR(255) NULL,
    created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS drcl_settlements (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    drcl_id             INT NOT NULL,
    claim_date          DATE NULL,
    claim_reference     VARCHAR(255) NULL,
    entity              VARCHAR(255) NULL,
    deduct_invoice_no   VARCHAR(255) NULL,
    claimed_amount      DECIMAL(14,2) NULL,
    remarks             TEXT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_drcl (drcl_id)
)");

$months_list = ['January','February','March','April','May','June','July','August','September','October','November','December'];

// Filters
$filter_year  = isset($_GET['filter_year'])  ? intval($_GET['filter_year'])  : '';
$filter_month = isset($_GET['filter_month']) ? intval($_GET['filter_month']) : '';
$where = [];
if ($filter_year)  $where[] = "d.year  = $filter_year";
if ($filter_month) $where[] = "d.month = $filter_month";
$where_sql = $where ? 'WHERE '.implode(' AND ',$where) : '';

$rows_result = mysqli_query($conn,
    "SELECT d.*,
            COALESCE((SELECT SUM(s.claimed_amount) FROM drcl_settlements s WHERE s.drcl_id=d.id),0) AS total_settled,
            (SELECT MAX(s.claim_date)  FROM drcl_settlements s WHERE s.drcl_id=d.id) AS last_claim_date,
            (SELECT GROUP_CONCAT(s.claim_reference ORDER BY s.id SEPARATOR ', ') FROM drcl_settlements s WHERE s.drcl_id=d.id) AS claim_refs
     FROM damage_returns_cl d $where_sql
     ORDER BY d.handed_over_date DESC, d.id DESC"
);

$totals = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as cnt,
            SUM(d.amount_ikea)          as total_ikea,
            SUM(d.usl_collection_value) as total_usl,
            SUM(d.damage_shortages)     as total_shortage,
            COALESCE((SELECT SUM(s.claimed_amount) FROM drcl_settlements s
                      INNER JOIN damage_returns_cl d2 ON s.drcl_id=d2.id
                      ".($where ? str_replace('d.','d2.',$where_sql) : '')."),0) as total_claimed
     FROM damage_returns_cl d $where_sql"
));

include 'header.php';
?>
<style>
/* ── Base ──────────────────────────────────────────────────────── */
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:20px}
.card-title{font-size:16px;font-weight:600;margin-bottom:0;color:#1f2937;display:flex;align-items:center;gap:8px}
.card-header-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px}

/* ── Buttons ───────────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;transition:all .2s;font-family:'Inter',sans-serif;text-decoration:none;white-space:nowrap}
.btn-sm{padding:7px 14px;font-size:13px}
.btn-xs{padding:5px 10px;font-size:12px}
.btn-primary{background:#000;color:#fff}.btn-primary:hover{background:#1f2937}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}.btn-secondary:hover{background:#e5e5e5}
.btn-dark{background:#000;color:#fff}.btn-dark:hover{background:#1f2937}
.btn-light{background:#f9fafb;color:#374151;border:1px solid #d1d5db}.btn-light:hover{background:#f3f4f6}
.btn-settle{background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe}.btn-settle:hover{background:#7c3aed;color:#fff}
.btn-settle.settled{background:#f0fdf4;color:#16a34a;border-color:#86efac}

/* ── Summary boxes ─────────────────────────────────────────────── */
.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:14px;margin-bottom:20px}
.summary-box{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:16px}
.summary-label{font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px}
.summary-value{font-size:19px;font-weight:700;color:#1f2937}
.summary-sub{font-size:11px;color:#9ca3af;margin-top:2px}

/* ── Filters ───────────────────────────────────────────────────── */
.fbar-group{display:flex;flex-direction:column;gap:4px}
.fbar-label{font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.4px}
.fbar-input,.fbar-select{padding:8px 12px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;outline:none;transition:border-color .2s;height:36px;box-sizing:border-box;background:#fff}
.fbar-input:focus,.fbar-select:focus{border-color:#000}

/* ── Toolbar ───────────────────────────────────────────────────── */
.toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.search-wrap{position:relative;flex:1;min-width:200px;max-width:340px}
.search-wrap i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:13px;pointer-events:none}
.search-input{width:100%;padding:8px 12px 8px 32px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;outline:none;transition:border-color .2s;box-sizing:border-box}
.search-input:focus{border-color:#000}

/* ── Table ─────────────────────────────────────────────────────── */
.table-wrap{max-height:65vh;overflow:auto;border:1px solid #e5e5e5;border-radius:8px}
.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table thead tr{position:sticky;top:0;z-index:10}
.data-table thead th{background:#f0f0f0;padding:10px 12px;text-align:left;font-weight:600;color:#222;font-size:12px;white-space:nowrap;border-bottom:2px solid #d5d5d5;box-shadow:0 2px 0 #d5d5d5}
.data-table th.num,.data-table td.num{text-align:right}
.data-table tbody tr{border-bottom:1px solid #f0f0f0;transition:background .1s}
.data-table tbody tr:hover{background:#fafafa}
.data-table td{padding:9px 12px;color:#333;white-space:nowrap;vertical-align:middle}
.data-table tfoot td{padding:10px 12px;font-weight:700;color:#1f2937;background:#f9fafb;border-top:2px solid #e5e5e5;font-size:13px}

/* ── Action buttons ────────────────────────────────────────────── */
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

/* ── Entry Form ────────────────────────────────────────────────── */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-grid .span2{grid-column:span 2}
.form-grid .span3{grid-column:span 2}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-label{font-size:12px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:.4px}
.form-label span.req{color:#ef4444}
.form-input,.form-select{padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;font-family:'Inter',sans-serif;color:#1f2937;outline:none;transition:border-color .2s,box-shadow .2s;background:#fff;width:100%;box-sizing:border-box}
.form-input:focus,.form-select:focus{border-color:#000;box-shadow:0 0 0 3px rgba(0,0,0,.06)}
.form-input.error,.form-select.error{border-color:#ef4444;box-shadow:0 0 0 3px rgba(239,68,68,.08)}
.form-divider{grid-column:span 2;border:none;border-top:1px solid #f0f0f0;margin:4px 0}

/* number input — no arrows */
input[type=number]::-webkit-inner-spin-button,
input[type=number]::-webkit-outer-spin-button{-webkit-appearance:none;margin:0}
input[type=number]{-moz-appearance:textfield;appearance:textfield}

/* ── File upload ───────────────────────────────────────────────── */
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

/* ═══ SETTLEMENT MODAL ════════════════════════════════════════════ */
.settle-info-strip{padding:12px 26px;background:#f9fafb;border-bottom:1px solid #f0f0f0;display:flex;gap:24px;flex-wrap:wrap;flex-shrink:0}
.sinfo-cell{display:flex;flex-direction:column;gap:2px}
.sinfo-lbl{font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px}
.sinfo-val{font-size:14px;font-weight:700;color:#1f2937}
.sinfo-val.green{color:#16a34a}

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

/* ── Toast ─────────────────────────────────────────────────────── */
#toast{position:fixed;bottom:28px;right:28px;padding:12px 22px;border-radius:8px;font-size:14px;font-weight:600;color:#fff;z-index:9999;display:none;box-shadow:0 4px 16px rgba(0,0,0,.18)}
#toast.success{background:#16a34a}
#toast.error{background:#dc2626}

.empty-state{text-align:center;padding:60px 20px;color:#9ca3af}
.empty-state i{font-size:40px;margin-bottom:12px;display:block}
.empty-state p{font-size:15px;font-weight:500}

/* month badge */
.month-badge{display:inline-flex;align-items:center;padding:3px 9px;border-radius:12px;font-size:11px;font-weight:700;background:#ede9fe;color:#6d28d9}

@media(max-width:640px){
    .form-grid{grid-template-columns:1fr}
    .form-grid .span2,.form-grid .span3,.form-divider{grid-column:span 1}
    .summary-grid{grid-template-columns:1fr 1fr}
}
</style>

<!-- Page Header -->
<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h2 class="page-title"><i class="fa-solid fa-box-open"></i> Damage Claim (CL)</h2>
            <p class="page-subtitle">Manage CL damage returns, IKEA amounts and settlements</p>
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
        <div class="summary-label">Amount – IKEA</div>
        <div class="summary-value"><?php echo $totals['total_ikea']!==null?number_format($totals['total_ikea'],2):'—'; ?></div>
        <div class="summary-sub">Total IKEA amount</div>
    </div>
    <div class="summary-box">
        <div class="summary-label">USL Collection</div>
        <div class="summary-value" style="color:#0369a1;"><?php echo $totals['total_usl']!==null?number_format($totals['total_usl'],2):'—'; ?></div>
        <div class="summary-sub">Total USL value</div>
    </div>
    <div class="summary-box">
        <div class="summary-label">Damage Shortages</div>
        <div class="summary-value" style="color:#dc2626;"><?php echo $totals['total_shortage']!==null?number_format($totals['total_shortage'],2):'—'; ?></div>
        <div class="summary-sub">Total shortage value</div>
    </div>
    <div class="summary-box">
        <div class="summary-label">Total Claimed</div>
        <div class="summary-value" style="color:#16a34a;"><?php echo number_format($totals['total_claimed']??0,2); ?></div>
        <div class="summary-sub">Sum of settlements</div>
    </div>
</div>

<!-- Records Table -->
<div class="content-card">
    <div class="card-header-row">
        <h3 class="card-title"><i class="fa-solid fa-table"></i> Damage Return Records</h3>
        <form method="GET" action="" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
            <div class="fbar-group">
                <label class="fbar-label">Year</label>
                <input type="number" name="filter_year" class="fbar-input" placeholder="e.g. 2024" value="<?php echo htmlspecialchars($filter_year); ?>" style="width:110px;">
            </div>
            <div class="fbar-group">
                <label class="fbar-label">Month</label>
                <select name="filter_month" class="fbar-select" style="width:130px;">
                    <option value="">All Months</option>
                    <?php foreach($months_list as $mi=>$mn): ?>
                        <option value="<?php echo $mi+1; ?>" <?php echo $filter_month==$mi+1?'selected':''; ?>><?php echo $mn; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-dark btn-sm"><i class="fa-solid fa-filter"></i> Filter</button>
            <a href="damage_return_cl.php" class="btn btn-light btn-sm"><i class="fa-solid fa-xmark"></i> Clear</a>
        </form>
    </div>

    <div class="toolbar">
        <div class="search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" class="search-input" id="searchBox" placeholder="Search description…" oninput="doSearch()">
        </div>
        <span id="rowCount" style="font-size:12px;color:#6b7280;"></span>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Handed Over Date</th>
                    <th>Year</th>
                    <th>Month</th>
                    <th>Claim Description</th>
                    <th class="num">Amount – IKEA</th>
                    <th class="num">USL Collection Value</th>
                    <th class="num">Damage Shortages</th>
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
                <tr><td colspan="14">
                    <div class="empty-state"><i class="fa-solid fa-box-open"></i><p>No records found. Click <strong>Add New Entry</strong> to get started.</p></div>
                </td></tr>
            <?php else: $rn=1; while ($r=mysqli_fetch_assoc($rows_result)):
                $ikea_amt = floatval($r['amount_ikea']??0);
                $settled  = floatval($r['total_settled']??0);
                $balance  = $ikea_amt - $settled;
                $balCl    = $balance>0?'bal-pos':($balance<0?'bal-neg':'bal-zero');
                $mon_name = $r['month'] ? $months_list[intval($r['month'])-1] : '—';
            ?>
                <tr id="tr-<?php echo $r['id']; ?>"
                    data-search="<?php echo strtolower(htmlspecialchars($r['claim_description']??'')); ?>">
                    <td><?php echo $rn++; ?></td>
                    <td><?php echo !empty($r['handed_over_date'])?date('d M Y',strtotime($r['handed_over_date'])):'—'; ?></td>
                    <td><?php echo $r['year']??'—'; ?></td>
                    <td><?php if($r['month']): ?><span class="month-badge"><?php echo $mon_name; ?></span><?php else: ?>—<?php endif; ?></td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($r['claim_description']??''); ?>"><?php echo htmlspecialchars($r['claim_description']??'—'); ?></td>
                    <td class="num"><?php echo $r['amount_ikea']!==null?number_format($ikea_amt,2):'—'; ?></td>
                    <td class="num" style="color:#0369a1;font-weight:600;"><?php echo $r['usl_collection_value']!==null?number_format($r['usl_collection_value'],2):'—'; ?></td>
                    <td class="num" style="color:#dc2626;font-weight:600;"><?php echo $r['damage_shortages']!==null?number_format($r['damage_shortages'],2):'—'; ?></td>
                    <td class="num" id="td-claimed-<?php echo $r['id']; ?>" style="color:#16a34a;font-weight:700;"><?php echo $settled>0?number_format($settled,2):'—'; ?></td>
                    <td class="num" id="td-balance-<?php echo $r['id']; ?>"><span class="<?php echo $balCl; ?>"><?php echo number_format($balance,2); ?></span></td>
                    <td id="td-claimdate-<?php echo $r['id']; ?>" style="font-size:12px;color:#6b7280;"><?php echo !empty($r['last_claim_date'])?date('d M Y',strtotime($r['last_claim_date'])):'—'; ?></td>
                    <td id="td-claimrefs-<?php echo $r['id']; ?>" style="font-size:12px;color:#6b7280;max-width:140px;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($r['claim_refs']??''); ?>"><?php echo htmlspecialchars($r['claim_refs']??'—'); ?></td>
                    <td style="text-align:center;">
                        <?php if ($r['document_path']&&file_exists($r['document_path'])): ?>
                            <a href="<?php echo htmlspecialchars($r['document_path']); ?>" target="_blank" class="doc-link"><i class="fa-solid fa-paperclip"></i><?php echo htmlspecialchars(substr($r['document_name']??'File',0,14)); ?></a>
                        <?php else: ?><span class="no-doc">—</span><?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <div class="action-buttons">
                            <button class="btn btn-settle btn-xs<?php echo $settled>0?' settled':''; ?>"
                                    id="settlebtn-<?php echo $r['id']; ?>"
                                    onclick="openSettleModal(<?php echo $r['id']; ?>,'<?php echo addslashes(htmlspecialchars($r['claim_description']??'#'.$r['id'])); ?>',<?php echo $ikea_amt; ?>)">
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
                    <td colspan="5" style="text-align:right;">Totals</td>
                    <td class="num"><?php echo $totals['total_ikea']!==null?number_format($totals['total_ikea'],2):'—'; ?></td>
                    <td class="num" style="color:#0369a1;"><?php echo $totals['total_usl']!==null?number_format($totals['total_usl'],2):'—'; ?></td>
                    <td class="num" style="color:#dc2626;"><?php echo $totals['total_shortage']!==null?number_format($totals['total_shortage'],2):'—'; ?></td>
                    <td class="num" style="color:#16a34a;"><?php echo number_format($totals['total_claimed']??0,2); ?></td>
                    <td class="num" style="color:#d97706;"><?php echo number_format(($totals['total_ikea']??0)-($totals['total_claimed']??0),2); ?></td>
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
            <div class="modal-title"><i class="fa-solid fa-box-open"></i><span id="modalTitleText">Add New Entry</span></div>
            <button class="modal-close" onclick="closeAddModal()">×</button>
        </div>
        <div class="modal-body">
            <form id="entryForm" enctype="multipart/form-data" onsubmit="return false;">
                <input type="hidden" id="fid" name="id" value="0">
                <div class="form-grid">

                    <!-- Handed Over Date -->
                    <div class="form-group">
                        <label class="form-label">Damage Handed Over Date</label>
                        <input type="date" class="form-input" id="f_handed_over_date" name="handed_over_date">
                    </div>

                    <!-- Claim Description -->
                    <div class="form-group">
                        <label class="form-label">Claim Description</label>
                        <input type="text" class="form-input" id="f_claim_description" name="claim_description" placeholder="e.g. Damaged goods batch A">
                    </div>

                    <!-- Year -->
                    <div class="form-group">
                        <label class="form-label">Year</label>
                        <input type="number" class="form-input" id="f_year" name="year" placeholder="<?php echo date('Y'); ?>" min="2000" max="2100">
                    </div>

                    <!-- Month -->
                    <div class="form-group">
                        <label class="form-label">Month</label>
                        <select class="form-select" id="f_month" name="month">
                            <option value="">— Select Month —</option>
                            <?php foreach($months_list as $mi=>$mn): ?>
                                <option value="<?php echo $mi+1; ?>"><?php echo $mn; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <hr class="form-divider">

                    <!-- Amount IKEA -->
                    <div class="form-group">
                        <label class="form-label">Amount – IKEA</label>
                        <input type="number" step="0.01" min="0" class="form-input" id="f_amount_ikea" name="amount_ikea" placeholder="0.00">
                    </div>

                    <!-- USL Collection Value -->
                    <div class="form-group">
                        <label class="form-label">USL Collection Value</label>
                        <input type="number" step="0.01" min="0" class="form-input" id="f_usl_collection_value" name="usl_collection_value" placeholder="0.00">
                    </div>

                    <!-- Damage Shortages -->
                    <div class="form-group span2">
                        <label class="form-label">Damage Shortages</label>
                        <input type="number" step="0.01" min="0" class="form-input" id="f_damage_shortages" name="damage_shortages" placeholder="0.00">
                    </div>

                    <hr class="form-divider">

                    <!-- Document -->
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
            <div class="sinfo-cell"><div class="sinfo-lbl">Description</div><div class="sinfo-val" id="si_desc" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;">—</div></div>
            <div class="sinfo-cell"><div class="sinfo-lbl">IKEA Amount</div><div class="sinfo-val" id="si_amount">—</div></div>
            <div class="sinfo-cell"><div class="sinfo-lbl">Total Claimed</div><div class="sinfo-val green" id="si_claimed">0.00</div></div>
            <div class="sinfo-cell"><div class="sinfo-lbl">Balance</div><div class="sinfo-val" id="si_balance">—</div></div>
        </div>
        <div class="modal-body">
            <input type="hidden" id="s_drcl_id" value="0">
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
                <div class="stotal-cell"><div class="stotal-lbl">IKEA Amount</div><div class="stotal-val" id="st_amount">0.00</div></div>
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
    ['f_handed_over_date','f_year','f_claim_description',
     'f_amount_ikea','f_usl_collection_value','f_damage_shortages'].forEach(id=>{
        document.getElementById(id).value='';
    });
    document.getElementById('f_month').value='';
    document.getElementById('existingDocWrap').style.display='none';
    clearFile();
    document.querySelectorAll('.form-input.error,.form-select.error').forEach(e=>e.classList.remove('error','error'));
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

    fetch('damage_return_cl.php?action=save',{method:'POST',body:new FormData(document.getElementById('entryForm'))})
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
    fetch('damage_return_cl.php?action=get&id='+id)
    .then(r=>r.json())
    .then(res=>{
        if(!res.success||!res.data){showToast('Could not load record.','error');return;}
        const d=res.data;
        document.getElementById('fid').value                        =''+d.id;
        document.getElementById('f_handed_over_date').value         =d.handed_over_date       ||'';
        document.getElementById('f_year').value                     =d.year                   ||'';
        document.getElementById('f_month').value                    =d.month                  ||'';
        document.getElementById('f_claim_description').value        =d.claim_description      ||'';
        document.getElementById('f_amount_ikea').value              =d.amount_ikea            ||'';
        document.getElementById('f_usl_collection_value').value     =d.usl_collection_value   ||'';
        document.getElementById('f_damage_shortages').value         =d.damage_shortages       ||'';
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
    fetch('damage_return_cl.php?action=delete',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(res=>{
        if(res.success){document.getElementById('tr-'+id)?.remove();showToast('Entry deleted.','success');}
        else showToast('Delete failed.','error');
    })
    .catch(()=>showToast('Network error.','error'));
}

/* ════ SETTLEMENT MODAL ═════════════════════════════════════════ */
let sRowCount=0;

function openSettleModal(drclId,desc,amount){
    sRowCount=0;
    document.getElementById('s_drcl_id').value=drclId;
    document.getElementById('s_inv_amount').value=amount;
    document.getElementById('settleTitleText').textContent='Settlement';
    document.getElementById('si_desc').textContent=desc;
    document.getElementById('si_amount').textContent=parseFloat(amount).toFixed(2);
    document.getElementById('st_amount').textContent=parseFloat(amount).toFixed(2);
    document.getElementById('settleRows').innerHTML='';

    fetch('damage_return_cl.php?action=get_settlements&drcl_id='+drclId)
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
    const tr=document.createElement('tr');
    tr.id='sr'+sRowCount;
    const cd=data?.claim_date||'';
    const cr=ea(data?.claim_reference);
    const en=ea(data?.entity);
    const di=ea(data?.deduct_invoice_no);
    const am=data?.claimed_amount||'';
    const rm=ea(data?.remarks);
    tr.innerHTML=`
        <td><input type="date" class="settle-input" value="${cd}"></td>
        <td><input type="text" class="settle-input" placeholder="REF-001" value="${cr}"></td>
        <td><input type="text" class="settle-input" placeholder="Entity"  value="${en}"></td>
        <td><input type="text" class="settle-input" placeholder="INV-001" value="${di}"></td>
        <td><input type="number" step="0.01" min="0" class="settle-input" placeholder="0.00" value="${am}" oninput="recalcSettle()"></td>
        <td><input type="text" class="settle-input" placeholder="Remarks" value="${rm}"></td>
        <td><button class="settle-rm" onclick="this.closest('tr').remove();recalcSettle();" title="Remove"><i class="fa-solid fa-xmark"></i></button></td>`;
    document.getElementById('settleRows').appendChild(tr);
}
function ea(v){return (v||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');}

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
    const drclId=document.getElementById('s_drcl_id').value;
    const trs=document.querySelectorAll('#settleRows tr');
    if(!trs.length){showToast('Add at least one settlement row.','error');return;}

    const rows=[];let valid=true;
    trs.forEach(tr=>{
        const inputs=tr.querySelectorAll('input');
        const amt=parseFloat(inputs[4]?.value||0);
        if(!(amt>0)) valid=false;
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
    fd.append('drcl_id',drclId);
    fd.append('rows',JSON.stringify(rows));

    fetch('damage_return_cl.php?action=save_settlements',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(res=>{
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Settlements';
        if(res.success){
            showToast('Settlements saved!','success');
            const total=rows.reduce((s,r)=>s+(r.claimed_amount||0),0);
            const amount=parseFloat(document.getElementById('s_inv_amount').value||0);
            const balance=amount-total;
            const balCl=balance>0?'bal-pos':(balance<0?'bal-neg':'bal-zero');
            const tc=document.getElementById('td-claimed-'+drclId);
            const tb=document.getElementById('td-balance-'+drclId);
            if(tc) tc.textContent=total>0?total.toFixed(2):'—';
            if(tb) tb.innerHTML=`<span class="${balCl}">${balance.toFixed(2)}</span>`;
            const sb=document.getElementById('settlebtn-'+drclId);
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