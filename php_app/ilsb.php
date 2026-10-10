<?php
// ============================================================
//  ALL-IN-ONE: Import Pre-Secondary Invoices
//  Handles AJAX sub-actions via ?action=validate|import|delete
// ============================================================
error_reporting(0);
ini_set('display_errors', 0);
ini_set('max_execution_time', 300);
ini_set('memory_limit', '256M');

include 'config.php';

// ---------- helpers ----------
function normalizeDate($v) {
    if (empty($v) || $v === 0 || $v === '0') return null;
    if (is_numeric($v)) {
        $unix = (intval($v) - 25569) * 86400;
        return gmdate('Y-m-d', $unix);
    }
    $ts = strtotime($v);
    return $ts ? date('Y-m-d', $ts) : null;
}

function jsonOut($data) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ---------- ensure tables ----------
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS loading_summary_imports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    delivery_date DATE NOT NULL,
    filename VARCHAR(255) NOT NULL,
    total_records INT DEFAULT 0,
    imported_records INT DEFAULT 0,
    failed_records INT DEFAULT 0,
    status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
    imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dd (delivery_date)
)");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS loading_summary_import_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    import_id INT NOT NULL,
    sales_person_code VARCHAR(100),
    t_code VARCHAR(50),
    route_code VARCHAR(50),
    bill_no VARCHAR(100),
    bill_date DATE NULL,
    outlet_code VARCHAR(100),
    party_name VARCHAR(255),
    free_qty DECIMAL(12,2) DEFAULT 0,
    gross_sales DECIMAL(12,2) DEFAULT 0,
    scheme_disc DECIMAL(12,2) DEFAULT 0,
    rs_discount DECIMAL(12,2) DEFAULT 0,
    tot_disc DECIMAL(12,2) DEFAULT 0,
    total_discount DECIMAL(12,2) DEFAULT 0,
    good_returns_value DECIMAL(12,2) DEFAULT 0,
    damage_expiry_shortage_value DECIMAL(12,2) DEFAULT 0,
    final_bill_amount DECIMAL(12,2) DEFAULT 0,
    delivery_person VARCHAR(255),
    delivery_date DATE NULL,
    t_code_valid TINYINT(1) DEFAULT 0,
    route_valid TINYINT(1) DEFAULT 0,
    customer_name VARCHAR(255),
    route_name VARCHAR(255),
    status ENUM('pending','imported','failed') DEFAULT 'pending',
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_iid (import_id),
    INDEX idx_bn (bill_no)
)");

// ============================================================
//  AJAX: ?action=validate
// ============================================================
if (isset($_GET['action']) && $_GET['action'] === 'validate') {
    $rows = json_decode($_POST['data'] ?? '[]', true);
    if (!$rows) jsonOut(['success'=>false,'message'=>'No data']);

    // Pre-load all known bill numbers
    $known = [];
    $res = mysqli_query($conn,
        "SELECT d.bill_no, i.delivery_date, i.imported_at
         FROM loading_summary_import_details d
         JOIN loading_summary_imports i ON d.import_id=i.id
         WHERE d.bill_no!='' AND d.bill_no IS NOT NULL");
    while ($r = mysqli_fetch_assoc($res))
        $known[trim($r['bill_no'])] = $r;

    $out = [];
    foreach ($rows as $row) {
        $bn = trim($row['bill_no'] ?? '');
        $dd = normalizeDate($row['delivery_date'] ?? '');

        $rec = [
            'sales_person_code'            => $row['sales_person_code'] ?? '',
            't_code'                       => $row['t_code'] ?? '',
            'route_code'                   => $row['route_code'] ?? '',
            'bill_no'                      => $bn,
            'bill_date'                    => normalizeDate($row['bill_date'] ?? ''),
            'outlet_code'                  => $row['outlet_code'] ?? '',
            'party_name'                   => $row['party_name'] ?? '',
            'free_qty'                     => floatval($row['free_qty'] ?? 0),
            'gross_sales'                  => floatval($row['gross_sales'] ?? 0),
            'scheme_disc'                  => floatval($row['scheme_disc'] ?? 0),
            'rs_discount'                  => floatval($row['rs_discount'] ?? 0),
            'tot_disc'                     => floatval($row['tot_disc'] ?? 0),
            'total_discount'               => floatval($row['total_discount'] ?? 0),
            'good_returns_value'           => floatval($row['good_returns_value'] ?? 0),
            'damage_expiry_shortage_value' => floatval($row['damage_expiry_shortage_value'] ?? 0),
            'final_bill_amount'            => floatval($row['final_bill_amount'] ?? 0),
            'delivery_person'              => $row['delivery_person'] ?? '',
            'delivery_date'                => $dd,
            't_code_valid'  => false, 'route_valid'  => false,
            'customer_name' => '',    'route_name'   => '',
            'is_duplicate'  => false,
            'dup_date'      => null,  'dup_at'       => null,
        ];

        // Duplicate check
        if ($bn !== '' && isset($known[$bn])) {
            $rec['is_duplicate'] = true;
            $rec['dup_date']     = $known[$bn]['delivery_date'];
            $rec['dup_at']       = substr($known[$bn]['imported_at'],0,16);
        }

        // T-Code
        if ($rec['t_code']) {
            $e = mysqli_real_escape_string($conn, $rec['t_code']);
            $r = mysqli_query($conn,"SELECT shop_name FROM customers WHERE t_code='$e' LIMIT 1");
            if ($r && mysqli_num_rows($r)) {
                $c = mysqli_fetch_assoc($r);
                $rec['t_code_valid']  = true;
                $rec['customer_name'] = $c['shop_name'];
            }
        }

        // Route
        if ($rec['route_code']) {
            $e = mysqli_real_escape_string($conn, $rec['route_code']);
            $r = mysqli_query($conn,"SELECT route_name FROM routes WHERE route_name='$e' LIMIT 1");
            if ($r && mysqli_num_rows($r)) {
                $rt = mysqli_fetch_assoc($r);
                $rec['route_valid'] = true;
                $rec['route_name']  = $rt['route_name'];
            }
        }

        $out[] = $rec;
    }

    // Which delivery dates in this upload already have a completed import?
    $existing_dates = [];
    $all_dates = array_unique(array_filter(array_column($out, 'delivery_date')));
    foreach ($all_dates as $dd) {
        $de = mysqli_real_escape_string($conn, $dd);
        $ex = mysqli_query($conn,
            "SELECT id, imported_at FROM loading_summary_imports
             WHERE delivery_date='$de' AND status='completed'
             ORDER BY id DESC LIMIT 1");
        if ($ex && mysqli_num_rows($ex)) {
            $row = mysqli_fetch_assoc($ex);
            $existing_dates[$dd] = [
                'id'          => (int)$row['id'],
                'imported_at' => substr($row['imported_at'], 0, 16),
            ];
        }
    }

    jsonOut(['success'=>true,'data'=>$out,
        'dup_count'      => count(array_filter($out, fn($r)=>$r['is_duplicate'])),
        'nodate_count'   => count(array_filter($out, fn($r)=>empty($r['delivery_date']))),
        'existing_dates' => $existing_dates,
    ]);
}

// ============================================================
//  AJAX: ?action=import
// ============================================================
if (isset($_GET['action']) && $_GET['action'] === 'import') {
    $chunk    = json_decode($_POST['data'] ?? '[]', true);
    $filename = $_POST['filename'] ?? 'import.xlsx';
    $is_first = ($_POST['is_first'] ?? 'true') === 'true';
    $is_last  = ($_POST['is_last']  ?? 'true') === 'true';
    $map      = json_decode($_POST['date_map'] ?? '{}', true) ?: [];

    if (!$chunk) jsonOut(['success'=>false,'message'=>'Empty chunk']);

    $fn = mysqli_real_escape_string($conn, $filename);

    // ── Resolve / create import-header row per delivery date ─────────────
    foreach (array_unique(array_column($chunk,'delivery_date')) as $dd) {
        if (!$dd || isset($map[$dd])) continue;
        $de = mysqli_real_escape_string($conn, $dd);

        // 1. Is there already a COMPLETED import for this delivery date?
        $ex = mysqli_query($conn,
            "SELECT id FROM loading_summary_imports
             WHERE delivery_date='$de' AND status='completed'
             ORDER BY id DESC LIMIT 1");

        if ($ex && mysqli_num_rows($ex)) {
            $iid = (int)mysqli_fetch_assoc($ex)['id'];

            if ($is_first) {
                mysqli_query($conn,
                    "DELETE FROM loading_summary_import_details
                     WHERE import_id = $iid");
            }

            mysqli_query($conn,
                "UPDATE loading_summary_imports
                 SET status='processing', filename='$fn',
                     imported_records=0, failed_records=0,
                     imported_at=CURRENT_TIMESTAMP
                 WHERE id=$iid");

            $map[$dd] = $iid;

        } else {
            // 2. Re-use an in-progress record from THIS session?
            $ex2 = mysqli_query($conn,
                "SELECT id FROM loading_summary_imports
                 WHERE delivery_date='$de' AND filename='$fn'
                   AND status='processing'
                 ORDER BY id DESC LIMIT 1");

            if ($ex2 && mysqli_num_rows($ex2)) {
                $map[$dd] = (int)mysqli_fetch_assoc($ex2)['id'];
            } else {
                // 3. Brand-new import record
                mysqli_query($conn,
                    "INSERT INTO loading_summary_imports
                     (delivery_date, filename, status)
                     VALUES('$de','$fn','processing')");
                $map[$dd] = mysqli_insert_id($conn);
            }
        }
    }

    // ── Insert detail rows ────────────────────────────────────────────────
    $imported = 0;
    $failed   = 0;

    foreach ($chunk as $rec) {
        if (is_object($rec)) $rec = (array)$rec;
        $dd  = $rec['delivery_date'] ?? '';
        $iid = $map[$dd] ?? reset($map);
        if (!$iid) { $failed++; continue; }

        $f  = fn($k) => mysqli_real_escape_string($conn, $rec[$k] ?? '');
        $n  = fn($k) => floatval($rec[$k] ?? 0);
        $bd = !empty($rec['bill_date']) ? "'".$f('bill_date')."'" : 'NULL';

        $sql = "INSERT INTO loading_summary_import_details
            (import_id, sales_person_code, t_code, route_code, bill_no, bill_date,
             outlet_code, party_name, free_qty, gross_sales, scheme_disc, rs_discount,
             tot_disc, total_discount, good_returns_value, damage_expiry_shortage_value,
             final_bill_amount, delivery_person, delivery_date,
             t_code_valid, route_valid, customer_name, route_name, status)
            VALUES
            ($iid, '".$f('sales_person_code')."', '".$f('t_code')."', '".$f('route_code')."',
             '".$f('bill_no')."', $bd, '".$f('outlet_code')."', '".$f('party_name')."',
             ".$n('free_qty').", ".$n('gross_sales').", ".$n('scheme_disc').", ".$n('rs_discount').",
             ".$n('tot_disc').", ".$n('total_discount').", ".$n('good_returns_value').",
             ".$n('damage_expiry_shortage_value').", ".$n('final_bill_amount').",
             '".$f('delivery_person')."', '".mysqli_real_escape_string($conn,$dd)."',
             1, 1, '".$f('customer_name')."', '".$f('route_name')."', 'imported')";

        mysqli_query($conn, $sql) ? $imported++ : $failed++;
    }

    // ── Update header totals when last chunk arrives ───────────────────
    $st = $is_last ? "'completed'" : "'processing'";
    foreach (array_unique($map) as $iid) {
        mysqli_query($conn,
            "UPDATE loading_summary_imports SET
                total_records    = (SELECT COUNT(*) FROM loading_summary_import_details WHERE import_id=$iid),
                imported_records = (SELECT COUNT(*) FROM loading_summary_import_details WHERE import_id=$iid AND status='imported'),
                failed_records   = (SELECT COUNT(*) FROM loading_summary_import_details WHERE import_id=$iid AND status='failed'),
                status           = $st
             WHERE id = $iid");
    }

    jsonOut(['success'=>true,'imported'=>$imported,'failed'=>$failed,
             'date_map'=>$map,'is_last'=>$is_last]);
}

// ============================================================
//  AJAX: ?action=quick_add
// ============================================================
if (isset($_GET['action']) && $_GET['action'] === 'quick_add') {
    $tc   = trim($_POST['t_code']    ?? '');
    $shop = trim($_POST['shop_name'] ?? '');
    if (!$tc || !$shop) jsonOut(['success'=>false,'message'=>'Missing fields']);
    $te = mysqli_real_escape_string($conn,$tc);
    $se = mysqli_real_escape_string($conn,$shop);
    $check = mysqli_query($conn,"SELECT id FROM customers WHERE t_code='$te' LIMIT 1");
    if ($check && mysqli_num_rows($check))
        mysqli_query($conn,"UPDATE customers SET shop_name='$se' WHERE t_code='$te'");
    else
        mysqli_query($conn,"INSERT INTO customers (t_code,shop_name) VALUES('$te','$se')");
    jsonOut(['success'=>true]);
}

// ============================================================
//  DELETE import
// ============================================================
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    mysqli_query($conn,"DELETE FROM loading_summary_import_details WHERE import_id=$id");
    mysqli_query($conn,"DELETE FROM loading_summary_imports WHERE id=$id");
    header('Location: '.$_SERVER['PHP_SELF'].'?deleted=1');
    exit;
}

// ============================================================
//  RENDER PAGE
// ============================================================
include 'header.php';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
/* ── Reset & Base ── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;font-size:14px;color:#1f2937;background:#f8fafc;}

/* ── Page Header ── */
.pg-header{padding:24px 0 16px;border-bottom:1px solid #e5e7eb;margin-bottom:24px;}
.pg-header h2{font-size:22px;font-weight:700;display:flex;align-items:center;gap:10px;color:#111827;}
.pg-header p{color:#6b7280;font-size:13px;margin-top:4px;}

/* ── Cards ── */
.card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:22px;margin-bottom:20px;}
.card-title{font-size:15px;font-weight:700;color:#111827;display:flex;align-items:center;gap:8px;margin-bottom:18px;}

/* ── Info banner ── */
.info-banner{display:flex;align-items:flex-start;gap:14px;padding:14px 18px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;margin-bottom:22px;}
.info-banner .ico{font-size:22px;color:#1d4ed8;flex-shrink:0;}
.info-banner strong{color:#1e40af;display:block;margin-bottom:3px;}
.info-banner span{font-size:13px;color:#1e3a8a;}

/* ── Form ── */
.file-drop{border:2px dashed #d1d5db;border-radius:10px;padding:36px;text-align:center;cursor:pointer;transition:.2s;background:#fafafa;position:relative;}
.file-drop:hover,.file-drop.drag{border-color:#3b82f6;background:#eff6ff;}
.file-drop input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}
.file-drop .drop-icon{font-size:38px;color:#9ca3af;margin-bottom:10px;}
.file-drop .drop-text{font-size:14px;color:#6b7280;}
.file-drop .drop-text strong{color:#1d4ed8;}
.file-drop .file-name{margin-top:8px;font-size:13px;font-weight:600;color:#111827;background:#dbeafe;padding:4px 12px;border-radius:20px;display:inline-block;}

/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 22px;border:none;border-radius:7px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;transition:.2s;text-decoration:none;}
.btn-primary{background:#111827;color:#fff;}
.btn-primary:hover{background:#374151;}
.btn-primary:disabled{background:#9ca3af;cursor:not-allowed;}
.btn-secondary{background:#f9fafb;color:#374151;border:1px solid #d1d5db;}
.btn-secondary:hover{background:#f3f4f6;}
.btn-danger{background:#fff;color:#dc2626;border:1px solid #fca5a5;}
.btn-danger:hover{background:#dc2626;color:#fff;}
.btn-green{background:#16a34a;color:#fff;}
.btn-green:hover{background:#15803d;}
.btn-sm{padding:5px 12px;font-size:12px;}

/* ── Stats grid ── */
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:12px;padding:16px;background:#f9fafb;border-radius:8px;margin-bottom:18px;}
.stat{text-align:center;}
.stat-label{font-size:11px;color:#9ca3af;font-weight:600;text-transform:uppercase;letter-spacing:.5px;}
.stat-val{font-size:28px;font-weight:800;color:#111827;line-height:1.1;margin-top:2px;}
.stat-val.green{color:#16a34a;}
.stat-val.red{color:#dc2626;}
.stat-val.orange{color:#ea580c;}
.stat-val.blue{color:#2563eb;}
.stat-val.gray{color:#9ca3af;}

/* ── Date pills ── */
.date-pills{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px;}
.date-pill{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:20px;font-size:12px;font-weight:700;color:#1e40af;cursor:pointer;transition:.2s;}
.date-pill:hover,.date-pill.active{background:#1e40af;color:#fff;border-color:#1e40af;}
.date-pill .cnt{background:rgba(255,255,255,.3);border-radius:10px;padding:1px 7px;font-size:11px;}
.date-pill.active .cnt{background:rgba(255,255,255,.2);}

/* ── Filter bar ── */
.filter-bar{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-bottom:12px;}
.fbtn{display:inline-flex;align-items:center;gap:5px;padding:5px 13px;border:1px solid #d1d5db;border-radius:20px;background:#f9fafb;color:#6b7280;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;transition:.2s;}
.fbtn:hover{background:#e5e7eb;}
.fbtn.act{background:#111827;color:#fff;border-color:#111827;}
.fbtn.act-green{background:#16a34a;color:#fff;border-color:#16a34a;}
.fbtn.act-red{background:#dc2626;color:#fff;border-color:#dc2626;}
.fbtn.act-orange{background:#ea580c;color:#fff;border-color:#ea580c;}

/* ── Warnings ── */
.warn{display:none;align-items:flex-start;gap:12px;padding:13px 16px;border-radius:8px;margin-bottom:14px;font-size:13px;}
.warn.show{display:flex;}
.warn-orange{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;}
.warn-yellow{background:#fefce8;border:1px solid #fde047;color:#713f12;}
.warn strong{display:block;font-weight:700;margin-bottom:3px;}

/* ── Table ── */
.tbl-wrap{overflow-x:auto;border-radius:8px;border:1px solid #e5e7eb;}
table{width:100%;border-collapse:collapse;font-size:12px;}
thead{background:#f9fafb;}
th{padding:10px 12px;text-align:left;font-weight:700;color:#374151;font-size:11px;white-space:nowrap;border-bottom:2px solid #e5e7eb;}
td{padding:9px 12px;color:#374151;vertical-align:middle;border-bottom:1px solid #f3f4f6;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:#f9fafb;}
tr.row-dup td{background:#fff7ed;}
tr.row-inv td{background:#fef2f2;}
tr.row-nodate td{opacity:.6;}
.th-date{background:#eff6ff;color:#1e40af;}
.td-date{font-weight:700;color:#1e40af;white-space:nowrap;}

/* ── Badges ── */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:10px;font-size:11px;font-weight:700;}
.b-ok{background:#dcfce7;color:#15803d;}
.b-inv{background:#fee2e2;color:#b91c1c;}
.b-dup{background:#ffedd5;color:#c2410c;}
.b-nd{background:#f3f4f6;color:#9ca3af;}
.b-update{background:#dbeafe;color:#1e40af;}
.vtag{display:inline-flex;align-items:center;gap:3px;padding:1px 6px;border-radius:8px;font-size:10px;font-weight:700;margin-left:4px;}
.vtag-ok{background:#dcfce7;color:#166534;}
.vtag-no{background:#fee2e2;color:#991b1b;}

/* ── Progress ── */
.progress-wrap{margin:20px 0;}
.prog-bar{height:48px;background:#f3f4f6;border-radius:24px;overflow:hidden;box-shadow:inset 0 2px 4px rgba(0,0,0,.08);}
.prog-fill{height:100%;background:linear-gradient(90deg,#111827,#374151);width:0%;transition:width .4s;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:15px;}
.prog-meta{display:flex;justify-content:space-between;margin-top:8px;font-size:13px;color:#6b7280;}
.prog-meta span:first-child{font-size:20px;font-weight:800;color:#111827;}
.prog-log{margin-top:12px;font-size:12px;color:#6b7280;max-height:100px;overflow-y:auto;}

/* ── Modal ── */
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;}
.overlay.open{display:flex;}
.modal{background:#fff;border-radius:12px;padding:28px;width:420px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.2);}
.modal h3{font-size:16px;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.form-group{margin-bottom:14px;}
.form-group label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px;}
.form-group input{width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;font-family:inherit;}
.form-group input:focus{outline:none;border-color:#111827;}
.form-group input[readonly]{background:#f9fafb;color:#9ca3af;}
.err-box{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;border-radius:6px;padding:8px 12px;font-size:12px;margin-bottom:12px;display:none;}
.modal-btns{display:flex;gap:8px;justify-content:flex-end;}

/* ── History table ── */
.history-table td,.history-table th{padding:10px 14px;}

/* ── Alert ── */
.alert{padding:12px 16px;border-radius:6px;margin-bottom:18px;display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;}
.alert-success{background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;}
.alert-info{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;}

@media(max-width:600px){.stats{grid-template-columns:repeat(3,1fr)}.stat-val{font-size:20px}}
</style>
</head>
<body>

<div class="pg-header">
    <h2><i class="fa-solid fa-file-excel" style="color:#16a34a;"></i> Import Pre-Secondary Invoices</h2>
    <p>Upload once — delivery dates are read automatically from each row (Column 25)</p>
</div>

<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-success"><i class="fa-solid fa-check-circle"></i> Import record deleted successfully.</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════
     STEP 1 — Upload
═══════════════════════════════════════════════════════ -->
<div class="card" id="stepUpload">
    <div class="card-title"><i class="fa-solid fa-upload"></i> Step 1 — Upload Excel File</div>

    <div class="info-banner">
        <div class="ico"><i class="fa-solid fa-calendar-days"></i></div>
        <div>
            <strong>Multi-Date Upload — No date input needed!</strong>
            <span>Each row's <strong>Delivery Date</strong> is read from <strong>Column 25</strong> automatically.
            One upload handles all dates in the file. If a date already exists, its records will be <strong>replaced</strong> with the new data.</span>
        </div>
    </div>

    <div class="file-drop" id="dropZone">
        <input type="file" id="xlFile" accept=".xlsx,.xls">
        <div class="drop-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
        <div class="drop-text">
            <strong>Click to choose</strong> or drag & drop your Excel file here<br>
            <small style="color:#9ca3af;">Columns: 2-SalesCode · 4-TCode · 5-Route · 6-BillNo · 8-BillDate · 9-OutletCode · 10-Party · 12-FreeQty · 13-GrossSales · 14-SchDisc · 15-RSDisc · 16-TOTDisc · 17-TotalDisc · 20-GoodReturns · 21-DmgExpiry · 22-FinalAmt · 24-DelPerson · <strong>25-DeliveryDate</strong></small>
        </div>
        <div class="file-name" id="fileName" style="display:none;"></div>
    </div>

    <div style="display:flex;justify-content:flex-end;margin-top:16px;padding-top:16px;border-top:1px solid #f3f4f6;">
        <button class="btn btn-primary" id="btnUpload" disabled onclick="doUpload()">
            <i class="fa-solid fa-magnifying-glass"></i> Parse & Validate
        </button>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     STEP 2 — Preview
═══════════════════════════════════════════════════════ -->
<div class="card" id="stepPreview" style="display:none;">
    <div class="card-title"><i class="fa-solid fa-table-list"></i> Step 2 — Preview & Validate</div>

    <!-- Stats -->
    <div class="stats" id="statsGrid"></div>

    <!-- Date pills -->
    <div class="date-pills" id="datePills"></div>

    <!-- Warnings -->
    <div class="warn warn-orange" id="warnDup">
        <i class="fa-solid fa-triangle-exclamation" style="font-size:18px;flex-shrink:0;"></i>
        <div><strong id="warnDupTitle"></strong><span id="warnDupText"></span></div>
    </div>
    <div class="warn warn-yellow" id="warnND">
        <i class="fa-solid fa-calendar-xmark" style="font-size:18px;flex-shrink:0;"></i>
        <div><strong id="warnNDTitle"></strong><span id="warnNDText"></span></div>
    </div>

    <!-- Filter bar -->
    <div class="filter-bar">
        <button class="fbtn act" id="fAll"   onclick="setF('all')"><i class="fa-solid fa-list"></i> All</button>
        <button class="fbtn"     id="fValid" onclick="setF('valid')"><i class="fa-solid fa-check"></i> Valid</button>
        <button class="fbtn"     id="fInv"   onclick="setF('invalid')"><i class="fa-solid fa-xmark"></i> Invalid</button>
        <button class="fbtn"     id="fDup"   onclick="setF('dup')"><i class="fa-solid fa-copy"></i> Duplicates</button>
        <button class="fbtn"     id="fND"    onclick="setF('nodate')"><i class="fa-solid fa-calendar-xmark"></i> No Date</button>
        <span id="filterInfo" style="font-size:12px;color:#9ca3af;margin-left:4px;"></span>
    </div>

    <!-- Table -->
    <div class="tbl-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Sales Code</th>
                    <th>T-Code</th>
                    <th>Route</th>
                    <th>Bill No</th>
                    <th>Bill Date</th>
                    <th>Party Name</th>
                    <th>Final Amt</th>
                    <th>Del. Person</th>
                    <th class="th-date">Delivery Date</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="tblBody"></tbody>
        </table>
    </div>

    <div style="display:flex;justify-content:space-between;margin-top:18px;padding-top:16px;border-top:1px solid #f3f4f6;flex-wrap:wrap;gap:10px;">
        <button class="btn btn-secondary" onclick="resetPage()"><i class="fa-solid fa-arrow-left"></i> Back</button>
        <button class="btn btn-primary" id="btnImport" onclick="doImport()"><i class="fa-solid fa-file-import"></i> Start Import</button>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     STEP 3 — Progress
═══════════════════════════════════════════════════════ -->
<div class="card" id="stepProgress" style="display:none;">
    <div class="card-title"><i class="fa-solid fa-spinner fa-spin"></i> Importing…</div>
    <div class="progress-wrap">
        <div class="prog-bar"><div class="prog-fill" id="progFill">0%</div></div>
        <div class="prog-meta"><span id="progPct">0%</span><span id="progStatus">Preparing…</span></div>
    </div>
    <div class="prog-log" id="progLog"></div>
</div>

<!-- ═══════════════════════════════════════════════════════
     Import History
═══════════════════════════════════════════════════════ -->
<div class="card">
    <div class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Import History</div>
    <?php
    $hist = mysqli_query($conn,"SELECT * FROM loading_summary_imports ORDER BY id DESC LIMIT 30");
    if ($hist && mysqli_num_rows($hist)): ?>
    <div class="tbl-wrap">
        <table class="history-table">
            <thead>
                <tr>
                    <th>#</th><th>Delivery Date</th><th>Filename</th>
                    <th>Total</th><th>Imported</th><th>Failed</th>
                    <th>Status</th><th>Imported At</th><th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php while($h=mysqli_fetch_assoc($hist)): ?>
            <tr>
                <td><?=$h['id']?></td>
                <td><strong style="color:#1e40af;"><?=$h['delivery_date']?></strong></td>
                <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?=htmlspecialchars($h['filename'])?>"><?=htmlspecialchars($h['filename'])?></td>
                <td><?=$h['total_records']?></td>
                <td style="color:#16a34a;font-weight:700;"><?=$h['imported_records']?></td>
                <td style="color:<?=$h['failed_records']>0?'#dc2626':'#9ca3af'?>;"><?=$h['failed_records']?></td>
                <td>
                    <?php $sc=['completed'=>'b-ok','processing'=>'','failed'=>'b-inv','pending'=>'b-nd'];?>
                    <span class="badge <?=$sc[$h['status']]??''?>"><?=ucfirst($h['status'])?></span>
                </td>
                <td style="white-space:nowrap;font-size:11px;"><?=substr($h['imported_at'],0,16)?></td>
                <td><a href="?delete=<?=$h['id']?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this import record and all its detail rows?')"><i class="fa-solid fa-trash"></i></a></td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p style="color:#9ca3af;text-align:center;padding:24px;">No import history yet.</p>
    <?php endif; ?>
</div>

<!-- Quick-Add Customer Modal -->
<div class="overlay" id="qacModal">
    <div class="modal">
        <h3><i class="fa-solid fa-user-plus" style="color:#16a34a;"></i> Quick Add Customer</h3>
        <div class="form-group">
            <label>T-Code</label>
            <input type="text" id="qacTC" readonly>
        </div>
        <div class="form-group">
            <label>Party Name (from Excel)</label>
            <input type="text" id="qacPN" readonly>
        </div>
        <div class="form-group">
            <label>Shop Name *</label>
            <input type="text" id="qacSN" placeholder="Enter shop name">
        </div>
        <div class="err-box" id="qacErr"></div>
        <div class="modal-btns">
            <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button class="btn btn-green" id="qacBtn" onclick="saveCustomer()"><i class="fa-solid fa-floppy-disk"></i> Save & Re-validate</button>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
// ── State ──────────────────────────────────────────────────────────────────
let rows       = [];
let fStatus    = 'all';
let fDate      = 'all';
let qacIdx     = null;
let existingDates = {};

// ── File drop & pick ───────────────────────────────────────────────────────
const dropZone  = document.getElementById('dropZone');
const xlFile    = document.getElementById('xlFile');
const btnUpload = document.getElementById('btnUpload');

xlFile.addEventListener('change', () => {
    if (xlFile.files[0]) {
        document.getElementById('fileName').textContent = xlFile.files[0].name;
        document.getElementById('fileName').style.display = 'inline-block';
        btnUpload.disabled = false;
    }
});
['dragover','dragenter'].forEach(e => dropZone.addEventListener(e, ev => {
    ev.preventDefault(); dropZone.classList.add('drag');
}));
['dragleave','drop'].forEach(e => dropZone.addEventListener(e, ev => {
    ev.preventDefault(); dropZone.classList.remove('drag');
    if (e === 'drop' && ev.dataTransfer.files[0]) {
        const dt = new DataTransfer();
        dt.items.add(ev.dataTransfer.files[0]);
        xlFile.files = dt.files;
        xlFile.dispatchEvent(new Event('change'));
    }
}));

// ── Parse Excel & send to server for validation ───────────────────────────
function doUpload() {
    const file = xlFile.files[0];
    if (!file) return;
    btnUpload.disabled = true;
    btnUpload.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Parsing…';

    const reader = new FileReader();
    reader.onload = e => {
        try {
            const wb = XLSX.read(new Uint8Array(e.target.result), {type:'array', cellDates:true});
            const ws = wb.Sheets[wb.SheetNames[0]];
            const raw = XLSX.utils.sheet_to_json(ws, {header:1, defval:''});

            const data = raw.slice(19).map(r => {
                let dd = '';
                const rdv = r[25];
                if (rdv instanceof Date)               dd = fmtDate(rdv);
                else if (typeof rdv==='number'&&rdv>0) dd = excelSerial(rdv);
                else if (typeof rdv==='string'&&rdv)   dd = fmtDate(new Date(rdv));

                return {
                    sales_person_code:            String(r[1]  || ''),
                    t_code:                       String(r[3]  || ''),
                    route_code:                   String(r[4]  || ''),
                    bill_no:                      String(r[5]  || ''),
                    bill_date:                    fmtRaw(r[7]),
                    outlet_code:                  String(r[8]  || ''),
                    party_name:                   String(r[9]  || ''),
                    free_qty:                     +r[11] || 0,
                    gross_sales:                  +r[12] || 0,
                    scheme_disc:                  +r[13] || 0,
                    rs_discount:                  +r[14] || 0,
                    tot_disc:                     +r[15] || 0,
                    total_discount:               +r[16] || 0,
                    good_returns_value:           +r[19] || 0,
                    damage_expiry_shortage_value: +r[20] || 0,
                    final_bill_amount:            +r[21] || 0,
                    delivery_person:              String(r[23] || ''),
                    delivery_date:                dd,
                };
            }).filter(r => r.bill_no || r.t_code || r.party_name);

            if (!data.length) { alert('No data found in file.'); resetBtn(); return; }

            const fd = new FormData();
            fd.append('data', JSON.stringify(data));
            fetch('?action=validate', {method:'POST', body:fd})
            .then(r => r.json())
            .then(res => {
                resetBtn();
                if (!res.success) { alert('Server error: '+res.message); return; }
                rows          = res.data;
                existingDates = res.existing_dates || {};
                renderPreview(res.dup_count, res.nodate_count);
            })
            .catch(err => { resetBtn(); alert('Error: '+err.message); });
        } catch(err) { resetBtn(); alert('Parse error: '+err.message); }
    };
    reader.readAsArrayBuffer(file);
}

// ── FIXED: Use UTC to avoid timezone date shift ───────────────────────────
function fmtDate(d) {
    if (!d || isNaN(d)) return '';
    const y   = d.getUTCFullYear();
    const m   = String(d.getUTCMonth() + 1).padStart(2, '0');
    const day = String(d.getUTCDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}
function excelSerial(n) {
    const ms = Math.round((n - 25569) * 86400) * 1000;
    return fmtDate(new Date(ms));
}

function fmtRaw(v) {
    if (!v||v===0) return '';
    if (v instanceof Date) return fmtDate(v);
    if (typeof v==='number') return excelSerial(v);
    return String(v);
}
function resetBtn() {
    btnUpload.disabled = false;
    btnUpload.innerHTML = '<i class="fa-solid fa-magnifying-glass"></i> Parse & Validate';
}

// ── Render preview ─────────────────────────────────────────────────────────
function renderPreview(dupCount, ndCount) {
    fStatus = 'all'; fDate = 'all';
    buildStats();
    buildDatePills();
    updateWarnings(dupCount, ndCount);
    updateFilterBtns();
    renderTable();
    document.getElementById('stepUpload').style.display  = 'none';
    document.getElementById('stepPreview').style.display = 'block';
    document.getElementById('stepPreview').scrollIntoView({behavior:'smooth'});
}

function buildStats() {
    const total  = rows.length;
    const dups   = rows.filter(r=>r.is_duplicate).length;
    const nd     = rows.filter(r=>!r.delivery_date).length;
    const valid  = rows.filter(r=>r.t_code_valid&&r.route_valid&&!r.is_duplicate&&r.delivery_date).length;
    const inv    = rows.filter(r=>(!r.t_code_valid||!r.route_valid)&&!r.is_duplicate&&r.delivery_date).length;
    const dates  = new Set(rows.map(r=>r.delivery_date).filter(Boolean)).size;

    document.getElementById('statsGrid').innerHTML = `
        <div class="stat"><div class="stat-label">Total</div><div class="stat-val">${total}</div></div>
        <div class="stat"><div class="stat-label">Dates</div><div class="stat-val blue">${dates}</div></div>
        <div class="stat"><div class="stat-label">Ready</div><div class="stat-val green">${valid}</div></div>
        <div class="stat"><div class="stat-label">Invalid</div><div class="stat-val red">${inv}</div></div>
        <div class="stat"><div class="stat-label">Duplicate</div><div class="stat-val orange">${dups}</div></div>
        <div class="stat"><div class="stat-label">No Date</div><div class="stat-val gray">${nd}</div></div>
    `;
}

function buildDatePills() {
    const counts = {};
    rows.forEach(r => { if(r.delivery_date) counts[r.delivery_date]=(counts[r.delivery_date]||0)+1; });
    const sorted = Object.keys(counts).sort();
    const pills  = document.getElementById('datePills');
    pills.innerHTML = `<button class="date-pill active" onclick="setDate('all')" id="dpAll">📅 All Dates <span class="cnt">${rows.length}</span></button>`;
    sorted.forEach(d => {
        const isExisting = existingDates && existingDates[d];
        const tag = isExisting
            ? `<span style="font-size:10px;background:rgba(255,255,255,.35);border-radius:8px;padding:1px 5px;margin-left:2px;">🔄 Update</span>`
            : '';
        pills.innerHTML += `<button class="date-pill" onclick="setDate('${d}')" id="dp_${d.replace(/-/g,'_')}">${d} <span class="cnt">${counts[d]}</span>${tag}</button>`;
    });
}

function setDate(d) {
    fDate = d;
    document.querySelectorAll('.date-pill').forEach(p=>p.classList.remove('active'));
    const id = d==='all' ? 'dpAll' : 'dp_'+d.replace(/-/g,'_');
    const el = document.getElementById(id);
    if (el) el.classList.add('active');
    renderTable();
}

function updateWarnings(dupCount, ndCount) {
    const wd = document.getElementById('warnDup');
    const wn = document.getElementById('warnND');
    if (dupCount>0) {
        document.getElementById('warnDupTitle').textContent = `${dupCount} duplicate invoice(s) detected`;
        document.getElementById('warnDupText').textContent  = ' — These bill numbers already exist in the database and will be skipped. Remove them with the trash button if needed.';
        wd.classList.add('show');
    } else wd.classList.remove('show');

    if (ndCount>0) {
        document.getElementById('warnNDTitle').textContent = `${ndCount} row(s) missing Delivery Date`;
        document.getElementById('warnNDText').textContent  = ' — Column 25 is empty for these rows. They will be skipped during import.';
        wn.classList.add('show');
    } else wn.classList.remove('show');
}

// ── Filter ─────────────────────────────────────────────────────────────────
function setF(f) {
    fStatus = f;
    updateFilterBtns();
    renderTable();
}
function updateFilterBtns() {
    const map = {all:'fAll',valid:'fValid',invalid:'fInv',dup:'fDup',nodate:'fND'};
    const cls = {all:'act',valid:'act-green',invalid:'act-red',dup:'act-orange',nodate:'act'};
    Object.keys(map).forEach(k => {
        const btn = document.getElementById(map[k]);
        btn.className = 'fbtn' + (fStatus===k ? ' '+cls[k] : '');
    });
}

function getFiltered() {
    let data = rows;
    if (fDate !== 'all') data = data.filter(r=>r.delivery_date===fDate);
    switch(fStatus) {
        case 'valid':   return data.filter(r=>r.t_code_valid&&r.route_valid&&!r.is_duplicate&&r.delivery_date);
        case 'invalid': return data.filter(r=>(!r.t_code_valid||!r.route_valid)&&!r.is_duplicate&&r.delivery_date);
        case 'dup':     return data.filter(r=>r.is_duplicate);
        case 'nodate':  return data.filter(r=>!r.delivery_date);
        default:        return data;
    }
}

// ── Render table ───────────────────────────────────────────────────────────
function renderTable() {
    const filtered = getFiltered();
    const info = document.getElementById('filterInfo');
    info.textContent = fStatus!=='all'||fDate!=='all' ? `Showing ${filtered.length} of ${rows.length}` : '';

    const tb = document.getElementById('tblBody');
    tb.innerHTML = '';
    filtered.forEach(rec => {
        const gi      = rows.indexOf(rec);
        const dup     = rec.is_duplicate;
        const nd      = !rec.delivery_date;
        const ok      = rec.t_code_valid && rec.route_valid && !dup && !nd;
        const inv     = (!rec.t_code_valid||!rec.route_valid) && !dup && !nd;
        const isUpd   = rec.delivery_date && existingDates && existingDates[rec.delivery_date];

        let trCls = dup?'row-dup':inv?'row-inv':nd?'row-nodate':'';
        let badge = dup
            ? `<span class="badge b-dup"><i class="fa-solid fa-copy"></i> Duplicate</span>`
            : nd
            ? `<span class="badge b-nd"><i class="fa-solid fa-calendar-xmark"></i> No Date</span>`
            : ok
            ? (isUpd
                ? `<span class="badge b-update"><i class="fa-solid fa-rotate"></i> Update</span>`
                : `<span class="badge b-ok"><i class="fa-solid fa-check"></i> Ready</span>`)
            : `<span class="badge b-inv"><i class="fa-solid fa-xmark"></i> Invalid</span>`;

        let billCell = dup
            ? `<strong style="color:#c2410c">${rec.bill_no}</strong><div style="font-size:10px;color:#9a3412;margin-top:2px;"><i class="fa-solid fa-circle-exclamation"></i> Imported ${rec.dup_date||''} · ${rec.dup_at||''}</div>`
            : (rec.bill_no||'—');

        let tcCell = `${rec.t_code||'—'}
            <span class="vtag ${rec.t_code_valid?'vtag-ok':'vtag-no'}">
                ${rec.t_code_valid?'<i class="fa-solid fa-check"></i> '+rec.customer_name:'<i class="fa-solid fa-xmark"></i> Invalid'}
            </span>`;
        let rtCell = `${rec.route_code||'—'}
            <span class="vtag ${rec.route_valid?'vtag-ok':'vtag-no'}">
                ${rec.route_valid?'<i class="fa-solid fa-check"></i> '+rec.route_name:'<i class="fa-solid fa-xmark"></i> Invalid'}
            </span>`;

        let actions = '';
        if (!dup && !rec.t_code_valid && rec.t_code)
            actions += `<button class="btn btn-secondary btn-sm" onclick="openQAC(${gi})" style="border-color:#16a34a;color:#15803d;margin-bottom:4px;display:block;white-space:nowrap;"><i class="fa-solid fa-user-plus"></i> Add Customer</button>`;
        actions += `<button class="btn btn-danger btn-sm" onclick="removeRow(${gi})"><i class="fa-solid fa-trash"></i></button>`;

        tb.insertAdjacentHTML('beforeend',`
            <tr class="${trCls}">
                <td style="color:#9ca3af;font-size:11px;">${gi+1}</td>
                <td>${rec.sales_person_code||'—'}</td>
                <td>${tcCell}</td>
                <td>${rtCell}</td>
                <td>${billCell}</td>
                <td>${rec.bill_date||'—'}</td>
                <td>${rec.party_name||'—'}</td>
                <td style="text-align:right;font-weight:700;">${(+rec.final_bill_amount||0).toFixed(2)}</td>
                <td>${rec.delivery_person||'—'}</td>
                <td class="td-date">${rec.delivery_date||'<span style="color:#9ca3af;font-weight:400;">—</span>'}</td>
                <td>${badge}</td>
                <td style="white-space:nowrap;">${actions}</td>
            </tr>`);
    });
}

function removeRow(gi) {
    if (!confirm('Remove this row?')) return;
    rows.splice(gi, 1);
    buildStats();
    buildDatePills();
    updateWarnings(
        rows.filter(r=>r.is_duplicate).length,
        rows.filter(r=>!r.delivery_date).length
    );
    renderTable();
}

// ── Import ─────────────────────────────────────────────────────────────────
const CHUNK = 100;

function doImport() {
    const importable = rows.filter(r=>r.t_code_valid&&r.route_valid&&!r.is_duplicate&&r.delivery_date);
    if (!importable.length) { alert('No importable records found.'); return; }

    const byDate={};
    importable.forEach(r=>{byDate[r.delivery_date]=(byDate[r.delivery_date]||0)+1;});
    const lines = Object.entries(byDate).sort().map(([d,c])=>{
        const tag = existingDates && existingDates[d] ? ' [UPDATE — existing data will be replaced]' : ' [NEW]';
        return `  • ${d} — ${c} records${tag}`;
    }).join('\n');
    const skips = rows.length - importable.length;
    let msg = `Import ${importable.length} records across ${Object.keys(byDate).length} date(s):\n\n${lines}`;
    if (skips>0) msg += `\n\n${skips} row(s) will be skipped (invalid / duplicate / no date).`;
    if (!confirm(msg)) return;

    document.getElementById('stepPreview').style.display  = 'none';
    document.getElementById('stepProgress').style.display = 'block';

    const slim = importable.map(r=>({
        sales_person_code:r.sales_person_code||'',
        t_code:r.t_code||'', route_code:r.route_code||'',
        bill_no:r.bill_no||'', bill_date:r.bill_date||'',
        outlet_code:r.outlet_code||'', party_name:r.party_name||'',
        free_qty:r.free_qty||0, gross_sales:r.gross_sales||0,
        scheme_disc:r.scheme_disc||0, rs_discount:r.rs_discount||0,
        tot_disc:r.tot_disc||0, total_discount:r.total_discount||0,
        good_returns_value:r.good_returns_value||0,
        damage_expiry_shortage_value:r.damage_expiry_shortage_value||0,
        final_bill_amount:r.final_bill_amount||0,
        delivery_person:r.delivery_person||'',
        customer_name:r.customer_name||'', route_name:r.route_name||'',
        delivery_date:r.delivery_date, t_code_valid:true, route_valid:true,
    }));

    const chunks=[]; for(let i=0;i<slim.length;i+=CHUNK) chunks.push(slim.slice(i,i+CHUNK));
    const fn = xlFile.files[0]?.name || 'import.xlsx';
    let chunkIdx=0, totalImp=0, totalFail=0, dateMap={};

    document.getElementById('progStatus').textContent = `Importing ${slim.length} records in ${chunks.length} batch(es)…`;

    function sendChunk(i) {
        const c      = chunks[i];
        const isFirst= i===0;
        const isLast = i===chunks.length-1;
        const fd     = new FormData();
        fd.append('data',     JSON.stringify(c));
        fd.append('filename', fn);
        fd.append('is_first', isFirst?'true':'false');
        fd.append('is_last',  isLast ?'true':'false');
        fd.append('date_map', JSON.stringify(dateMap));

        fetch('?action=import', {method:'POST',body:fd})
        .then(r=>r.text())
        .then(txt=>{
            let res;
            try { const t=txt.trim(); res=JSON.parse(t.substring(t.indexOf('{'))); }
            catch(e) { alert('Server error:\n'+txt.substring(0,300)); resetAfterErr(); return; }
            if (!res.success) { alert('Import error: '+res.message); resetAfterErr(); return; }

            dateMap    = res.date_map || dateMap;
            totalImp  += res.imported;
            totalFail += res.failed;
            chunkIdx++;

            const pct = Math.round(chunkIdx/chunks.length*100);
            document.getElementById('progFill').style.width = pct+'%';
            document.getElementById('progFill').textContent = pct+'%';
            document.getElementById('progPct').textContent  = pct+'%';
            document.getElementById('progStatus').textContent = `Batch ${chunkIdx}/${chunks.length} · ${totalImp} imported`;

            const bDates=[...new Set(c.map(r=>r.delivery_date))].sort().join(', ');
            const updTag = c.some(r=>existingDates&&existingDates[r.delivery_date]) ? ' 🔄' : '';
            document.getElementById('progLog').insertAdjacentHTML('beforeend',
                `<div>✓ Batch ${chunkIdx} [${bDates}]${updTag} — ${res.imported} imported</div>`);

            if (isLast) {
                document.getElementById('progStatus').textContent =
                    `✅ Done! ${totalImp} imported across ${Object.keys(byDate).length} date(s). ${totalFail} failed.`;
                setTimeout(()=>location.reload(), 2200);
            } else {
                setTimeout(()=>sendChunk(i+1), 150);
            }
        })
        .catch(err=>{ alert('Network error: '+err.message); resetAfterErr(); });
    }

    function resetAfterErr() {
        document.getElementById('stepProgress').style.display = 'none';
        document.getElementById('stepPreview').style.display  = 'block';
    }

    sendChunk(0);
}

// ── Quick Add Customer ─────────────────────────────────────────────────────
function openQAC(gi) {
    qacIdx = gi;
    const r = rows[gi];
    document.getElementById('qacTC').value = r.t_code||'';
    document.getElementById('qacPN').value = r.party_name||'';
    document.getElementById('qacSN').value = r.party_name||'';
    document.getElementById('qacErr').style.display='none';
    document.getElementById('qacModal').classList.add('open');
    setTimeout(()=>document.getElementById('qacSN').focus(),80);
}
function closeModal() { document.getElementById('qacModal').classList.remove('open'); qacIdx=null; }

function saveCustomer() {
    const sn = document.getElementById('qacSN').value.trim();
    if (!sn) { showErr('Shop name is required.'); return; }
    const btn=document.getElementById('qacBtn');
    btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    document.getElementById('qacErr').style.display='none';

    const fd=new FormData();
    fd.append('t_code',    document.getElementById('qacTC').value);
    fd.append('shop_name', sn);

    fetch('?action=quick_add',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(res=>{
        btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save & Re-validate';
        if (res.success) {
            rows[qacIdx].t_code_valid  = true;
            rows[qacIdx].customer_name = sn;
            closeModal();
            buildStats();
            updateWarnings(
                rows.filter(r=>r.is_duplicate).length,
                rows.filter(r=>!r.delivery_date).length
            );
            renderTable();
        } else showErr(res.message||'Failed.');
    })
    .catch(err=>{ btn.disabled=false; showErr('Network: '+err.message); });
}
function showErr(m){ const e=document.getElementById('qacErr'); e.textContent=m; e.style.display='block'; }

// ── Utils ──────────────────────────────────────────────────────────────────
function resetPage() {
    if (!confirm('Go back and discard current data?')) return;
    rows=[];
    document.getElementById('stepPreview').style.display = 'none';
    document.getElementById('stepUpload').style.display  = 'block';
    document.getElementById('uploadForm')?.reset();
    document.getElementById('fileName').style.display='none';
    btnUpload.disabled=true;
}

document.getElementById('qacModal').addEventListener('click',e=>{if(e.target===e.currentTarget)closeModal();});
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeModal();});
</script>

<?php include 'footer.php'; ?>