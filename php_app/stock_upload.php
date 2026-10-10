<?php
include 'config.php';

// ══════════════════════════════════════════════════════════════════
//  TABLE SETUP
// ══════════════════════════════════════════════════════════════════
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS stock_uploads (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    batch_ref       VARCHAR(60) NOT NULL,
    deliver_date    DATE NOT NULL,
    rs_name         VARCHAR(120) NULL,
    report_date     DATE NULL,
    file_name       VARCHAR(255) NOT NULL,
    total_rows      INT NOT NULL DEFAULT 0,
    total_units     BIGINT NOT NULL DEFAULT 0,
    total_value     DECIMAL(18,2) NOT NULL DEFAULT 0,
    uploaded_by     VARCHAR(100) NULL,
    status          ENUM('Active','Archived') NOT NULL DEFAULT 'Active',
    notes           TEXT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS stock_upload_items (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    upload_id           INT NOT NULL,
    sr_no               INT NULL,
    division            VARCHAR(50) NULL,
    basepack_code       VARCHAR(50) NULL,
    sku7                VARCHAR(50) NULL,
    product_name        VARCHAR(255) NULL,
    location            VARCHAR(100) NULL,
    pkm                 VARCHAR(20) NULL,
    batch_code          VARCHAR(50) NULL,
    expiry_month        VARCHAR(20) NULL,
    expiry_date         DATE NULL,
    days_to_expire      INT NULL,
    upc                 INT NULL,
    units               INT NULL,
    stocks_in_days      VARCHAR(20) NULL,
    pur_rate            DECIMAL(14,4) NULL,
    pur_rate_tax        DECIMAL(14,4) NULL,
    tur                 DECIMAL(14,4) NULL,
    mrp                 DECIMAL(14,4) NULL,
    cur_stk_value       DECIMAL(14,2) NULL,
    tonnage             DECIMAL(14,4) NULL,
    INDEX idx_upload (upload_id),
    INDEX idx_sku (sku7),
    FOREIGN KEY (upload_id) REFERENCES stock_uploads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ══════════════════════════════════════════════════════════════════
//  AJAX
// ══════════════════════════════════════════════════════════════════
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    if ($_GET['action'] === 'process_upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {

        if (empty($_FILES['stock_file']['tmp_name'])) {
            echo json_encode(['success'=>false,'message'=>'No file received.']); exit;
        }

        $deliver_date = trim($_POST['deliver_date'] ?? '');
        $notes        = trim($_POST['notes']        ?? '');
        $uploaded_by  = trim($_POST['uploaded_by']  ?? '');

        if (!$deliver_date) {
            echo json_encode(['success'=>false,'message'=>'Delivery date is required.']); exit;
        }

        $ext = strtolower(pathinfo($_FILES['stock_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx','xls'])) {
            echo json_encode(['success'=>false,'message'=>'Only .xlsx / .xls files accepted.']); exit;
        }

        // Save file
        $uploads_dir = __DIR__ . '/stock_uploads/';
        if (!is_dir($uploads_dir)) mkdir($uploads_dir, 0755, true);
        $safe_name  = 'stock_' . date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($_FILES['stock_file']['name']));
        $saved_path = $uploads_dir . $safe_name;
        if (!move_uploaded_file($_FILES['stock_file']['tmp_name'], $saved_path)) {
            echo json_encode(['success'=>false,'message'=>'Failed to save uploaded file. Check directory permissions.']); exit;
        }

        // ── Parse XLSX using pure PHP (ZipArchive + SimpleXML) ────
        require_once __DIR__ . 'SimpleXLSX.php';
        $xlsx = SimpleXLSX::parse($saved_path);
        if (!$xlsx) {
            @unlink($saved_path);
            echo json_encode(['success'=>false,'message'=>'Cannot parse Excel file. Ensure it is a valid .xlsx (not .xls or .csv).']); exit;
        }

        $all_rows = $xlsx->rows(0);

        // ── Meta extraction & header detection ────────────────────
        $header_row_idx = -1;
        $rs_name        = '';
        $report_date    = '';

        foreach ($all_rows as $ri => $row) {
            $flat = implode('|', array_map('trim', $row));

            if ($rs_name === '' && strpos($flat, 'RS Name:') !== false) {
                foreach ($row as $ci => $cell) {
                    if (trim($cell) === 'RS Name:' && !empty(trim($row[$ci+1] ?? ''))) {
                        $rs_name = trim($row[$ci+1]);
                        break;
                    }
                }
            }

            if ($report_date === '' && strpos($flat, 'Date:') !== false) {
                foreach ($row as $ci => $cell) {
                    if (trim($cell) === 'Date:' && !empty(trim($row[$ci+1] ?? ''))) {
                        $dv = trim($row[$ci+1]);
                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dv)) {
                            $report_date = $dv;
                        } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $dv, $m)) {
                            $report_date = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
                        }
                        break;
                    }
                }
            }

            if (in_array('Sr No', $row) && in_array('Product Name', $row)) {
                $header_row_idx = $ri;
                break;
            }
        }

        if ($header_row_idx < 0) {
            @unlink($saved_path);
            echo json_encode(['success'=>false,'message'=>'Header row not found. Expected columns: "Sr No", "Product Name". Is this a LeverEDGE Current Stock report?']); exit;
        }

        // Build column index map
        $header  = $all_rows[$header_row_idx];
        $col_map = [];
        foreach ($header as $ci => $cell) {
            $col_map[trim($cell)] = $ci;
        }

        $g = function(array $row, string $key) use ($col_map): string {
            if (!isset($col_map[$key])) return '';
            return isset($row[$col_map[$key]]) ? trim((string)$row[$col_map[$key]]) : '';
        };

        // ── Parse data rows ───────────────────────────────────────
        $items       = [];
        $total_units = 0;
        $total_value = 0.0;

        for ($ri = $header_row_idx + 1; $ri < count($all_rows); $ri++) {
            $row = $all_rows[$ri];
            $sr  = $g($row, 'Sr No');
            if ($sr === '' || !is_numeric(preg_replace('/[^0-9]/', '', $sr))) continue;
            if ($g($row, 'Product Name') === '') continue;

            $exp_raw = $g($row, 'Expiry Date');
            $exp_sql = '';
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp_raw)) {
                $exp_sql = $exp_raw;
            } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $exp_raw, $m)) {
                $exp_sql = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            }

            $units        = (int)$g($row, 'Units');
            $value        = (float)$g($row, 'Cur.Stk Value');
            $total_units += $units;
            $total_value += $value;

            $items[] = [
                'sr_no'          => (int)preg_replace('/[^0-9]/', '', $sr),
                'division'       => $g($row, 'Division'),
                'basepack_code'  => $g($row, 'Basepack Code'),
                'sku7'           => preg_replace('/\.0+$/', '', $g($row, 'SKU7')),
                'product_name'   => $g($row, 'Product Name'),
                'location'       => $g($row, 'Location'),
                'pkm'            => preg_replace('/\.0+$/', '', $g($row, 'PKM')),
                'batch_code'     => $g($row, 'Batch Code'),
                'expiry_month'   => preg_replace('/\.0+$/', '', $g($row, 'Expiry Month')),
                'expiry_date'    => $exp_sql,
                'days_to_expire' => (int)$g($row, 'No of Days to Expire'),
                'upc'            => (int)$g($row, 'UPC'),
                'units'          => $units,
                'stocks_in_days' => $g($row, 'Stocks in Days'),
                'pur_rate'       => (float)$g($row, 'Pur.Rate'),
                'pur_rate_tax'   => (float)$g($row, 'Pur.Rate + Tax'),
                'tur'            => (float)$g($row, 'TUR'),
                'mrp'            => (float)$g($row, 'MRP'),
                'cur_stk_value'  => $value,
                'tonnage'        => (float)$g($row, 'Tonnage'),
            ];
        }

        if (empty($items)) {
            @unlink($saved_path);
            echo json_encode(['success'=>false,'message'=>'No valid data rows found after parsing.']); exit;
        }

        // ── Insert header record ──────────────────────────────────
        $batch_ref       = 'STK-' . strtoupper(substr(md5(uniqid()), 0, 8));
        $report_date_sql = $report_date ? "'".mysqli_real_escape_string($conn,$report_date)."'" : 'NULL';
        $total_rows      = count($items);

        mysqli_query($conn, "INSERT INTO stock_uploads
            (batch_ref,deliver_date,rs_name,report_date,file_name,total_rows,total_units,total_value,uploaded_by,notes)
            VALUES (
                '".mysqli_real_escape_string($conn,$batch_ref)."',
                '".mysqli_real_escape_string($conn,$deliver_date)."',
                '".mysqli_real_escape_string($conn,$rs_name)."',
                $report_date_sql,
                '".mysqli_real_escape_string($conn,$safe_name)."',
                $total_rows, $total_units, $total_value,
                '".mysqli_real_escape_string($conn,$uploaded_by)."',
                '".mysqli_real_escape_string($conn,$notes)."'
            )");
        $upload_id = mysqli_insert_id($conn);

        if (!$upload_id) {
            echo json_encode(['success'=>false,'message'=>'DB header insert failed: '.mysqli_error($conn)]); exit;
        }

        // ── Bulk insert items in chunks of 200 ───────────────────
        $chunk = [];
        $ins_sql_prefix = "INSERT INTO stock_upload_items
            (upload_id,sr_no,division,basepack_code,sku7,product_name,location,pkm,batch_code,
             expiry_month,expiry_date,days_to_expire,upc,units,stocks_in_days,
             pur_rate,pur_rate_tax,tur,mrp,cur_stk_value,tonnage) VALUES ";

        foreach ($items as $r) {
            $ed      = $r['expiry_date'] ? "'".mysqli_real_escape_string($conn,$r['expiry_date'])."'" : 'NULL';
            $chunk[] = "($upload_id,{$r['sr_no']},
                '".mysqli_real_escape_string($conn,$r['division'])."',
                '".mysqli_real_escape_string($conn,$r['basepack_code'])."',
                '".mysqli_real_escape_string($conn,$r['sku7'])."',
                '".mysqli_real_escape_string($conn,$r['product_name'])."',
                '".mysqli_real_escape_string($conn,$r['location'])."',
                '".mysqli_real_escape_string($conn,$r['pkm'])."',
                '".mysqli_real_escape_string($conn,$r['batch_code'])."',
                '".mysqli_real_escape_string($conn,$r['expiry_month'])."',
                $ed,
                {$r['days_to_expire']},{$r['upc']},{$r['units']},
                '".mysqli_real_escape_string($conn,$r['stocks_in_days'])."',
                {$r['pur_rate']},{$r['pur_rate_tax']},{$r['tur']},
                {$r['mrp']},{$r['cur_stk_value']},{$r['tonnage']})";

            if (count($chunk) >= 200) {
                mysqli_query($conn, $ins_sql_prefix . implode(',', $chunk));
                $chunk = [];
            }
        }
        if ($chunk) mysqli_query($conn, $ins_sql_prefix . implode(',', $chunk));

        echo json_encode([
            'success'     => true,
            'upload_id'   => $upload_id,
            'batch_ref'   => $batch_ref,
            'total_rows'  => $total_rows,
            'total_units' => $total_units,
            'total_value' => number_format($total_value, 2),
            'rs_name'     => $rs_name,
            'report_date' => $report_date,
        ]);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']); exit;
}

// ── PAGE STATS ────────────────────────────────────────────────────
$stats = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as total, COALESCE(SUM(total_rows),0) as rows, COALESCE(SUM(total_value),0) as value
     FROM stock_uploads WHERE status='Active'"));

include 'header.php';
?>
<style>
*{box-sizing:border-box;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .18s;white-space:nowrap;}
.btn-sm{padding:6px 12px;font-size:12px;}
.btn-primary{background:#0f172a;color:#fff;}.btn-primary:hover{background:#1e293b;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e0e0e0;}.btn-secondary:hover{background:#ececec;}
.btn-teal{background:#0d9488;color:#fff;}.btn-teal:hover{background:#0f766e;}

.upload-wrapper{max-width:780px;margin:0 auto;}

.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:22px;}
.s-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 18px;position:relative;overflow:hidden;}
.s-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;}
.s-card.blue::before{background:linear-gradient(90deg,#3b82f6,#60a5fa);}
.s-card.teal::before{background:linear-gradient(90deg,#0d9488,#2dd4bf);}
.s-card.green::before{background:linear-gradient(90deg,#22c55e,#4ade80);}
.s-label{font-size:10.5px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.s-value{font-size:21px;font-weight:800;color:#0f172a;line-height:1;}
.s-sub{font-size:11px;color:#9ca3af;margin-top:4px;}

.step-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:28px 32px;margin-bottom:22px;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.step-header{display:flex;align-items:center;gap:12px;margin-bottom:22px;}
.step-num{width:34px;height:34px;border-radius:50%;background:#0f172a;color:#fff;font-size:14px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.step-title{font-size:16px;font-weight:800;color:#0f172a;}
.step-sub{font-size:12px;color:#9ca3af;font-weight:500;margin-top:2px;}

.fg{display:flex;flex-direction:column;gap:5px;margin-bottom:16px;}
.fg label{font-size:12px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px;}
.req{color:#ef4444;margin-left:2px;}
.form-input{padding:10px 13px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13.5px;font-family:inherit;color:#111827;background:#fff;width:100%;outline:none;transition:border-color .2s,box-shadow .2s;}
.form-input:focus{border-color:#0f172a;box-shadow:0 0 0 3px rgba(15,23,42,.07);}
.fgrid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
textarea.form-input{resize:vertical;min-height:70px;}
.date-picker-wrap{position:relative;}
.date-picker-wrap .form-input{padding-left:42px;}
.date-picker-wrap .dp-icon{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#6b7280;font-size:14px;pointer-events:none;}

.drop-zone{border:2px dashed #d1d5db;border-radius:12px;padding:48px 24px;text-align:center;cursor:pointer;transition:all .2s;background:#fafafa;position:relative;}
.drop-zone:hover,.drop-zone.drag-over{border-color:#0f172a;background:#f8fafc;}
.drop-zone.file-selected{border-color:#15803d;background:#f0fdf4;}
.drop-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}
.dz-icon{font-size:40px;color:#d1d5db;margin-bottom:12px;display:block;}
.drop-zone.file-selected .dz-icon{color:#15803d;}
.dz-title{font-size:15px;font-weight:700;color:#374151;margin-bottom:4px;}
.dz-sub{font-size:12px;color:#9ca3af;}
.dz-file-name{font-size:13px;font-weight:700;color:#15803d;margin-top:8px;display:none;}
.drop-zone.file-selected .dz-file-name{display:block;}
.drop-zone.file-selected .dz-sub{display:none;}

.progress-wrap{background:#1e293b;border-radius:8px;height:10px;overflow:hidden;margin:14px 0 6px;}
.progress-bar{height:100%;background:linear-gradient(90deg,#3b82f6,#60a5fa);border-radius:8px;transition:width .4s ease;width:0%;}

.result-panel{display:none;background:#fff;border:1.5px solid #15803d;border-radius:12px;padding:22px 24px;margin-bottom:22px;}
.result-panel.visible{display:block;}
.error-panel{display:none;background:#fff;border:1.5px solid #dc2626;border-radius:12px;padding:18px 22px;margin-bottom:22px;}
.error-panel.visible{display:block;}
.result-meta{display:flex;flex-wrap:wrap;gap:16px;font-size:12.5px;color:#374151;}
.rmeta{display:flex;flex-direction:column;gap:2px;}
.rmeta-lbl{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px;}
.rmeta-val{font-size:14px;font-weight:800;color:#0f172a;}

.breadcrumb{display:flex;align-items:center;gap:7px;font-size:12.5px;color:#9ca3af;margin-bottom:18px;}
.breadcrumb a{color:#6b7280;text-decoration:none;font-weight:600;}.breadcrumb a:hover{color:#0f172a;}
.breadcrumb .sep{color:#d1d5db;}

#upToast{position:fixed;bottom:28px;right:28px;padding:12px 22px;border-radius:8px;font-size:14px;font-weight:600;color:#fff;z-index:99999;display:none;box-shadow:0 4px 16px rgba(0,0,0,.18);}
#upToast.success{background:#15803d;}
#upToast.error{background:#dc2626;}

@media(max-width:640px){.fgrid-2{grid-template-columns:1fr;}}
</style>

<div class="breadcrumb">
    <a href="stock_list.php"><i class="fa-solid fa-layer-group"></i> Stock Uploads</a>
    <span class="sep">/</span><span>New Upload</span>
</div>

<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:22px;">
    <div>
        <h2 class="page-title"><i class="fa-solid fa-file-arrow-up"></i> Daily Stock Upload</h2>
        <p class="page-subtitle">Upload LeverEDGE Current Stock report and link it to a delivery date</p>
    </div>
    <a href="stock_list.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-list"></i> View All Uploads</a>
</div>

<div class="summary-grid">
    <div class="s-card blue"><div class="s-label">Total Batches</div><div class="s-value"><?php echo number_format($stats['total']); ?></div><div class="s-sub">All time uploads</div></div>
    <div class="s-card teal"><div class="s-label">Total SKU Rows</div><div class="s-value"><?php echo number_format($stats['rows']); ?></div><div class="s-sub">Across all batches</div></div>
    <div class="s-card green"><div class="s-label">Total Stock Value</div><div class="s-value" style="font-size:15px;"><?php echo number_format($stats['value'],2); ?></div><div class="s-sub">Active batches</div></div>
</div>

<div class="upload-wrapper">

    <div class="result-panel" id="resultPanel">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
            <i class="fa-solid fa-circle-check" style="font-size:22px;color:#15803d;"></i>
            <div style="font-size:15px;font-weight:800;color:#14532d;">Upload Successful!</div>
        </div>
        <div class="result-meta" id="resultMeta"></div>
        <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;">
            <a href="stock_list.php" class="btn btn-primary btn-sm"><i class="fa-solid fa-list"></i> View All Uploads</a>
            <button class="btn btn-secondary btn-sm" onclick="resetForm()"><i class="fa-solid fa-rotate-left"></i> Upload Another</button>
            <a href="#" id="viewUploadBtn" class="btn btn-teal btn-sm"><i class="fa-solid fa-eye"></i> View This Upload</a>
        </div>
    </div>

    <div class="error-panel" id="errorPanel">
        <div style="display:flex;align-items:center;gap:9px;color:#dc2626;font-weight:700;font-size:14px;">
            <i class="fa-solid fa-circle-xmark" style="font-size:18px;"></i>
            <span id="errorText">Upload failed.</span>
        </div>
    </div>

    <!-- STEP 1 -->
    <div class="step-card">
        <div class="step-header">
            <div class="step-num">1</div>
            <div>
                <div class="step-title">Select Delivery Date</div>
                <div class="step-sub">The date the stock was delivered — this upload will be linked to it</div>
            </div>
        </div>
        <div class="fgrid-2">
            <div class="fg" style="margin-bottom:0;">
                <label>Delivery Date <span class="req">*</span></label>
                <div class="date-picker-wrap">
                    <i class="fa-solid fa-calendar-days dp-icon"></i>
                    <input type="date" class="form-input" id="deliverDate" value="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>
            <div class="fg" style="margin-bottom:0;">
                <label>Uploaded By</label>
                <input type="text" class="form-input" id="uploadedBy" placeholder="Your name (optional)">
            </div>
        </div>
        <div class="fg" style="margin-top:14px;margin-bottom:0;">
            <label>Notes / Remarks</label>
            <textarea class="form-input" id="uploadNotes" rows="2" placeholder="Optional notes about this upload…"></textarea>
        </div>
    </div>

    <!-- STEP 2 -->
    <div class="step-card">
        <div class="step-header">
            <div class="step-num">2</div>
            <div>
                <div class="step-title">Select Stock File</div>
                <div class="step-sub">LeverEDGE Current Stock Report (.xlsx)</div>
            </div>
        </div>
        <div class="drop-zone" id="dropZone">
            <input type="file" id="stockFile" accept=".xlsx,.xls" onchange="onFileSelected(this)">
            <span class="dz-icon"><i class="fa-solid fa-file-excel"></i></span>
            <div class="dz-title">Drag &amp; drop your stock file here</div>
            <div class="dz-sub">or click to browse — .xlsx only</div>
            <div class="dz-file-name" id="dzFileName">—</div>
        </div>
        <div style="margin-top:10px;font-size:11.5px;color:#9ca3af;">
            <i class="fa-solid fa-circle-info"></i>
            Expected columns: Sr No · Division · Basepack Code · SKU7 · Product Name · Location · PKM · Batch Code · Expiry Month · Expiry Date · No of Days to Expire · UPC · Units · Stocks in Days · Pur.Rate · Pur.Rate+Tax · TUR · MRP · Cur.Stk Value · Tonnage
        </div>
    </div>

    <!-- STEP 3 -->
    <div class="step-card" style="background:#0f172a;border-color:#0f172a;">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
            <div>
                <div style="font-size:15px;font-weight:800;color:#f1f5f9;display:flex;align-items:center;gap:9px;">
                    <i class="fa-solid fa-cloud-arrow-up" style="color:#60a5fa;"></i> Upload &amp; Process
                </div>
                <div style="font-size:12px;color:#94a3b8;margin-top:3px;">Parsed entirely in PHP — no external dependencies</div>
            </div>
            <button class="btn" id="uploadBtn" onclick="doUpload()"
                style="background:#3b82f6;color:#fff;font-size:14px;padding:12px 28px;">
                <i class="fa-solid fa-file-arrow-up"></i> Upload Stock File
            </button>
        </div>
        <div id="progressWrap" style="display:none;margin-top:16px;">
            <div style="display:flex;justify-content:space-between;font-size:11.5px;color:#94a3b8;margin-bottom:4px;">
                <span id="progressLabel">Uploading…</span>
                <span id="progressPct">0%</span>
            </div>
            <div class="progress-wrap"><div class="progress-bar" id="progressBar"></div></div>
        </div>
    </div>

</div>
<div id="upToast"></div>

<script>
const dz = document.getElementById('dropZone');
dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('drag-over'); });
dz.addEventListener('dragleave', () => dz.classList.remove('drag-over'));
dz.addEventListener('drop', e => {
    e.preventDefault(); dz.classList.remove('drag-over');
    const f = e.dataTransfer.files[0];
    if (f) { try { const dt = new DataTransfer(); dt.items.add(f); document.getElementById('stockFile').files = dt.files; } catch(e){} onFileSelected(null, f); }
});

function onFileSelected(input, file) {
    const f = file || (input && input.files[0]);
    if (!f) return;
    dz.classList.add('file-selected');
    document.getElementById('dzFileName').textContent = f.name + ' (' + (f.size/1024).toFixed(1) + ' KB)';
    document.querySelector('.dz-title').textContent = 'File selected!';
}

function setProgress(pct, label) {
    document.getElementById('progressBar').style.width = pct + '%';
    document.getElementById('progressPct').textContent = pct + '%';
    document.getElementById('progressLabel').textContent = label;
}

function doUpload() {
    const deliverDate = document.getElementById('deliverDate').value;
    const fileInput   = document.getElementById('stockFile');
    if (!deliverDate) { showToast('Please select a delivery date.', 'error'); return; }
    if (!fileInput.files.length) { showToast('Please select a stock file.', 'error'); return; }

    document.getElementById('resultPanel').classList.remove('visible');
    document.getElementById('errorPanel').classList.remove('visible');

    const fd = new FormData();
    fd.append('deliver_date', deliverDate);
    fd.append('notes',        document.getElementById('uploadNotes').value);
    fd.append('uploaded_by',  document.getElementById('uploadedBy').value);
    fd.append('stock_file',   fileInput.files[0]);

    const btn = document.getElementById('uploadBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing…';
    document.getElementById('progressWrap').style.display = 'block';
    setProgress(10, 'Uploading file…');

    const xhr = new XMLHttpRequest();
    xhr.upload.onprogress = e => {
        if (e.lengthComputable) setProgress(Math.round(e.loaded / e.total * 55), 'Uploading…');
    };
    xhr.onload = () => {
        setProgress(95, 'Saving to database…');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-file-arrow-up"></i> Upload Stock File';
        try {
            const res = JSON.parse(xhr.responseText);
            setProgress(100, 'Done!');
            if (res.success) {
                document.getElementById('resultMeta').innerHTML =
                    meta('Batch Ref',   res.batch_ref,   '#15803d') +
                    meta('Delivery',    deliverDate) +
                    meta('RS Name',     res.rs_name || '—') +
                    meta('Report Date', res.report_date || '—') +
                    meta('SKU Rows',    (+res.total_rows).toLocaleString()) +
                    meta('Units',       (+res.total_units).toLocaleString()) +
                    meta('Stock Value', res.total_value);
                document.getElementById('viewUploadBtn').href = 'stock_view.php?id=' + res.upload_id;
                document.getElementById('resultPanel').classList.add('visible');
                showToast('Upload successful! ' + res.total_rows + ' rows imported.', 'success');
            } else {
                document.getElementById('errorText').textContent = res.message || 'Upload failed.';
                document.getElementById('errorPanel').classList.add('visible');
                document.getElementById('progressWrap').style.display = 'none';
                showToast('Error: ' + res.message, 'error');
            }
        } catch(e) {
            document.getElementById('errorText').textContent = 'Invalid server response. Check server error logs.';
            document.getElementById('errorPanel').classList.add('visible');
            document.getElementById('progressWrap').style.display = 'none';
            showToast('Server error.', 'error');
        }
    };
    xhr.onerror = () => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-file-arrow-up"></i> Upload Stock File';
        document.getElementById('progressWrap').style.display = 'none';
        showToast('Network error.', 'error');
    };
    xhr.open('POST', 'stock_upload.php?action=process_upload');
    xhr.send(fd);
}

function meta(label, val, color) {
    return '<div class="rmeta"><div class="rmeta-lbl">'+label+'</div><div class="rmeta-val"'+(color?' style="color:'+color+';"':'')+'>'+escH(val)+'</div></div>';
}
function escH(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function resetForm() {
    document.getElementById('resultPanel').classList.remove('visible');
    document.getElementById('errorPanel').classList.remove('visible');
    document.getElementById('progressWrap').style.display = 'none';
    document.getElementById('stockFile').value = '';
    document.getElementById('uploadNotes').value = '';
    dz.classList.remove('file-selected');
    document.getElementById('dzFileName').textContent = '—';
    document.querySelector('.dz-title').textContent = 'Drag & drop your stock file here';
    setProgress(0, '');
}
function showToast(msg, type) {
    const t = document.getElementById('upToast');
    t.textContent = msg; t.className = type; t.style.display = 'block';
    clearTimeout(t._t); t._t = setTimeout(() => t.style.display = 'none', 4000);
}
</script>
<?php include 'footer.php'; ?>
