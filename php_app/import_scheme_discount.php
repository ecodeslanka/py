<?php
include 'config.php';
include 'header.php';

// Create imports tracking table
$createImportsTable = "CREATE TABLE IF NOT EXISTS scheme_discount_imports (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    total_records INT(11) DEFAULT 0,
    imported_records INT(11) DEFAULT 0,
    failed_records INT(11) DEFAULT 0,
    status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
    imported_by INT(11) NULL,
    imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bill_date_filter (bill_date_filter),
    INDEX idx_status (status)
)";
mysqli_query($conn, $createImportsTable);

// Create import details table
$createDetailsTable = "CREATE TABLE IF NOT EXISTS scheme_discount_import_details (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    import_id INT(11) NOT NULL,
    scheme_no VARCHAR(100) NULL,              -- Col 1:  Scheme No
    scheme_desc VARCHAR(255) NULL,            -- Col 2:  Desc
    scheme_type VARCHAR(50) NULL,             -- Col 3:  Scheme Type
    bill_no VARCHAR(100) NULL,                -- Col 4:  Bill No
    bill_date DATE NULL,                      -- Col 5:  Bill Date
    beat_name VARCHAR(255) NULL,              -- Col 7:  Beat Name
    hul_code VARCHAR(100) NULL,               -- Col 9:  HUL Code (T-Code)
    party_name VARCHAR(255) NULL,             -- Col 10: Party Name
    sku7_code VARCHAR(100) NULL,              -- Col 13: SKU7 Code
    product_name VARCHAR(255) NULL,           -- Col 14: Product Name
    sold_qty DECIMAL(12,4) DEFAULT 0,         -- Col 15: Sold Qty in Units
    free_qty DECIMAL(12,4) DEFAULT 0,         -- Col 17: Free Qty
    free_value DECIMAL(12,2) DEFAULT 0,       -- Col 19: Free Value
    sch_disc DECIMAL(12,2) DEFAULT 0,         -- Col 20: Sch Disc
    gross_sales DECIMAL(12,2) DEFAULT 0,      -- Col 21: Gross Sales
    salesman_code VARCHAR(100) NULL,          -- Col 22: Salesman Code
    t_code_valid TINYINT(1) DEFAULT 0,
    route_valid TINYINT(1) DEFAULT 0,
    customer_name VARCHAR(255) NULL,
    route_name VARCHAR(255) NULL,
    status ENUM('pending','imported','failed') DEFAULT 'pending',
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (import_id) REFERENCES scheme_discount_imports(id) ON DELETE CASCADE,
    INDEX idx_import_id (import_id),
    INDEX idx_status (status),
    INDEX idx_hul_code (hul_code),
    INDEX idx_bill_no (bill_no)
)";
mysqli_query($conn, $createDetailsTable);

// Handle delete
if (isset($_GET['delete_import'])) {
    $import_id = intval($_GET['delete_import']);
    mysqli_query($conn, "DELETE FROM scheme_discount_import_details WHERE import_id = $import_id");
    if (mysqli_query($conn, "DELETE FROM scheme_discount_imports WHERE id = $import_id")) {
        $success_message = "Import deleted successfully!";
    }
}
?>

<div class="page-header">
    <h2 class="page-title">
        <i class="fa-solid fa-file-excel"></i> Import Scheme Discount
    </h2>
    <p class="page-subtitle">Upload Bill Wise Scheme Analysis Excel to import scheme discount data</p>
</div>

<?php if (isset($success_message)): ?>
<div class="alert alert-success">
    <i class="fa-solid fa-circle-check"></i>
    <?php echo $success_message; ?>
</div>
<?php endif; ?>

<!-- Info box -->
<div class="info-box">
    <div class="info-icon"><i class="fa-solid fa-info-circle"></i></div>
    <div class="info-content">
        <strong>Expected Format: LeverEDGE Bill Wise Scheme Analysis</strong>
        Data is read from row 14 onward. Columns used:
        1·Scheme No, 2·Desc, 3·Type, 4·Bill No, 5·Bill Date,
        7·Beat, 9·HUL Code, 10·Party Name, 13·SKU7, 14·Product,
        15·Sold Qty, 17·Free Qty, 19·Free Value, 20·Sch Disc, 21·Gross Sales, 22·Salesman
    </div>
</div>

<!-- Upload form -->
<div class="content-card">
    <h3 class="card-title">
        <i class="fa-solid fa-upload"></i> Step 1: Upload Excel File
    </h3>
    <form id="uploadForm" enctype="multipart/form-data">
        <div class="form-row">
            <div class="form-group required-field">
                <label class="form-label">Bill Date <span class="required">*</span></label>
                <input type="date" id="bill_date_filter" name="bill_date_filter" class="form-input" required>
                <small class="form-hint">Select the bill date for this import batch</small>
            </div>
            <div class="form-group required-field">
                <label class="form-label">Excel File <span class="required">*</span></label>
                <input type="file" id="excel_file" name="excel_file" class="form-input" accept=".xlsx,.xls" required>
                <small class="form-hint">LeverEDGE → Bill Wise Scheme Analysis report (.xlsx)</small>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary" id="uploadBtn">
                <i class="fa-solid fa-upload"></i> Upload & Preview
            </button>
        </div>
    </form>
</div>

<!-- Validating section -->
<div id="validatingSection" style="display:none;">
  <div class="vld-card">
    <div class="vld-header">
      <div class="vld-icon-wrap">
        <i class="fa-solid fa-database vld-db-icon"></i>
        <div class="vld-pulse"></div>
      </div>
      <div>
        <h3 class="vld-title">Validating Scheme Data</h3>
        <p class="vld-subtitle" id="vldSubtitle">Preparing chunks…</p>
      </div>
    </div>
    <div class="vld-strip-wrapper">
      <div class="vld-strip-fade vld-strip-fade--top"></div>
      <div class="vld-strip" id="vldStrip"></div>
      <div class="vld-strip-fade vld-strip-fade--bottom"></div>
    </div>
    <div class="vld-stats">
      <div class="vld-stat"><span class="vld-stat-val" id="vldDone">0</span><span class="vld-stat-lbl">Checked</span></div>
      <div class="vld-stat vld-stat--mid"><span class="vld-stat-val" id="vldTotal">0</span><span class="vld-stat-lbl">Total</span></div>
      <div class="vld-stat"><span class="vld-stat-val vld-green" id="vldValid">0</span><span class="vld-stat-lbl">Valid</span></div>
      <div class="vld-stat"><span class="vld-stat-val vld-red" id="vldInvalid">0</span><span class="vld-stat-lbl">Invalid</span></div>
      <div class="vld-stat"><span class="vld-stat-val vld-gray" id="vldElapsed">0s</span><span class="vld-stat-lbl">Elapsed</span></div>
    </div>
    <div class="vld-bar-track">
      <div class="vld-bar-fill" id="vldBarFill"><div class="vld-bar-shine"></div></div>
    </div>
    <div class="vld-bar-labels">
      <span id="vldPct">0%</span>
      <span id="vldEta">Estimating…</span>
    </div>
  </div>
</div>

<!-- Preview section -->
<div id="previewSection" class="content-card" style="display:none;">
    <h3 class="card-title">
        <i class="fa-solid fa-eye"></i> Step 2: Preview & Validate Data
    </h3>
    <div class="preview-summary">
        <div class="summary-item">
            <span class="summary-label">Total Records:</span>
            <span class="summary-value" id="totalRecords">0</span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Valid Records:</span>
            <span class="summary-value valid" id="validRecords">0</span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Invalid Records:</span>
            <span class="summary-value invalid" id="invalidRecords">0</span>
        </div>
    </div>

    <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap;">
        <button type="button" id="filterInvalidBtn" class="btn btn-secondary" onclick="toggleInvalidFilter()" style="border-color:#ef4444;color:#ef4444;">
            <i class="fa-solid fa-filter"></i> Show Invalid Only
        </button>
        <button type="button" id="filterAllBtn" class="btn btn-secondary" onclick="toggleInvalidFilter()" style="display:none;">
            <i class="fa-solid fa-list"></i> Show All Records
        </button>
        <span id="filterNote" style="font-size:12px;color:#6b7280;"></span>
    </div>

    <div class="table-responsive">
        <table class="data-table" id="previewTable">
            <thead>
                <tr>
                    <th>Col 1: Scheme No</th>
                    <th>Col 2: Description</th>
                    <th>Col 3: Type</th>
                    <th>Col 4: Bill No</th>
                    <th>Col 5: Bill Date</th>
                    <th>Col 7: Beat</th>
                    <th>Col 9: HUL Code</th>
                    <th>Col 10: Party Name</th>
                    <th>Col 13: SKU7 Code</th>
                    <th>Col 14: Product Name</th>
                    <th>Col 15: Sold Qty</th>
                    <th>Col 17: Free Qty</th>
                    <th>Col 19: Free Value</th>
                    <th>Col 20: Sch Disc</th>
                    <th>Col 21: Gross Sales</th>
                    <th>Col 22: Salesman</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="previewTableBody"></tbody>
        </table>
    </div>

    <div class="form-actions">
        <button type="button" class="btn btn-secondary" onclick="cancelImport()">
            <i class="fa-solid fa-xmark"></i> Cancel
        </button>
        <button type="button" class="btn btn-primary" id="importBtn" onclick="startImport()">
            <i class="fa-solid fa-file-import"></i> Start Import
        </button>
    </div>
</div>

<!-- Progress section -->
<div id="progressSection" class="content-card" style="display:none;">
    <h3 class="card-title">
        <i class="fa-solid fa-spinner fa-spin"></i> Importing Data…
    </h3>
    <div class="progress-container">
        <div class="progress-bar">
            <div class="progress-fill" id="progressFill">0%</div>
        </div>
        <div class="progress-text">
            <span id="progressPercent">0%</span>
            <span id="progressStatus">Preparing…</span>
        </div>
    </div>
</div>

<!-- History link -->
<div class="content-card" style="text-align:center;padding:30px;">
    <p style="color:#666;margin-bottom:16px;">
        <i class="fa-solid fa-info-circle"></i> View previously imported scheme discount files
    </p>
    <a href="scheme_import_history.php" class="btn btn-secondary">
        <i class="fa-solid fa-history"></i> View Import History
    </a>
</div>

<!-- Quick Add Customer Modal -->
<div id="quickAddCustomerModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:10px;padding:28px;width:420px;max-width:95vw;box-shadow:0 10px 40px rgba(0,0,0,0.2);">
        <h3 style="margin:0 0 6px;font-size:16px;font-weight:700;color:#1f2937;">
            <i class="fa-solid fa-user-plus" style="color:#22c55e;"></i> Quick Add Customer
        </h3>
        <p style="font-size:12px;color:#6b7280;margin:0 0 20px;">Save this HUL Code to customers table and re-validate.</p>
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">HUL Code (T-Code)</label>
            <input type="text" id="qacTCode" class="form-input" readonly style="background:#f9fafb;font-weight:700;">
        </div>
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">Party Name (from Excel)</label>
            <input type="text" id="qacPartyName" class="form-input" readonly style="background:#f9fafb;color:#6b7280;">
        </div>
        <div style="margin-bottom:20px;">
            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">Shop Name <span style="color:#ef4444;">*</span></label>
            <input type="text" id="qacShopName" class="form-input" placeholder="Enter shop name to save">
        </div>
        <div id="qacError" style="display:none;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;border-radius:6px;padding:8px 12px;font-size:12px;margin-bottom:14px;"></div>
        <div style="display:flex;gap:10px;justify-content:flex-end;">
            <button type="button" class="btn btn-secondary" onclick="closeQuickAddModal()">Cancel</button>
            <button type="button" class="btn btn-primary" id="qacSaveBtn" onclick="saveQuickCustomer()" style="background:#22c55e;">
                <i class="fa-solid fa-floppy-disk"></i> Save & Validate
            </button>
        </div>
    </div>
</div>

<style>
/* ── Content cards ─────────────────────────────────────────────────────────── */
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:20px;}
.card-title{font-size:16px;font-weight:600;margin-bottom:16px;color:#1f2937;display:flex;align-items:center;gap:8px;}
.alert{padding:12px 16px;border-radius:6px;margin-bottom:20px;display:flex;align-items:center;gap:8px;font-size:13px;}
.alert-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
.info-box{display:flex;align-items:flex-start;gap:16px;padding:16px 20px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;margin-bottom:24px;}
.info-icon{font-size:20px;color:#1e40af;margin-top:2px;}
.info-content{flex:1;font-size:13px;color:#1e3a8a;line-height:1.6;}
.info-content strong{display:block;margin-bottom:4px;color:#1e40af;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;}
.form-group{margin-bottom:16px;}
.form-label{display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:#374151;}
.form-input{width:100%;padding:10px 14px;border:1px solid #e5e5e5;border-radius:6px;font-size:14px;font-family:'Inter',sans-serif;box-sizing:border-box;}
.form-input:focus{outline:none;border-color:#000;box-shadow:0 0 0 2px rgba(0,0,0,.05);}
.required-field .form-input{background-color:#fffbeb;}
.required{color:#ef4444;}
.form-hint{display:block;font-size:11px;color:#666;margin-top:4px;}
.form-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:16px;padding-top:16px;border-top:1px solid #e5e5e5;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;transition:all .3s;font-family:'Inter',sans-serif;}
.btn-primary{background:#000;color:#fff;}
.btn-primary:hover{background:#333;}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;}
.btn-secondary:hover{background:#e5e5e5;}
.preview-summary{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px;padding:16px;background:#f9fafb;border-radius:8px;}
.summary-item{text-align:center;}
.summary-label{display:block;font-size:12px;color:#6b7280;margin-bottom:4px;}
.summary-value{display:block;font-size:28px;font-weight:700;color:#1f2937;}
.summary-value.valid{color:#22c55e;}
.summary-value.invalid{color:#ef4444;}
.progress-container{margin:24px 0;}
.progress-bar{width:100%;height:50px;background:#f0f0f0;border-radius:25px;overflow:hidden;margin-bottom:16px;box-shadow:inset 0 2px 4px rgba(0,0,0,.1);}
.progress-fill{height:100%;background:linear-gradient(90deg,#000 0%,#333 100%);width:0%;transition:width .3s ease;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:16px;}
.progress-text{display:flex;justify-content:space-between;align-items:center;font-size:14px;color:#666;}
#progressPercent{font-weight:700;color:#000;font-size:20px;}
.table-responsive{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12px;}
.data-table thead{background:#fafafa;border-bottom:2px solid #e5e5e5;}
.data-table th{padding:10px 8px;text-align:left;font-weight:600;color:#333;font-size:11px;white-space:nowrap;}
.data-table tbody tr{border-bottom:1px solid #f0f0f0;}
.data-table tbody tr:hover{background:#fafafa;}
.data-table tbody tr.row-invalid{background:#fef2f2;}
.data-table td{padding:8px;color:#333;white-space:nowrap;max-width:160px;overflow:hidden;text-overflow:ellipsis;}
.validation-badge{display:inline-flex;align-items:center;gap:3px;padding:2px 6px;border-radius:10px;font-size:10px;font-weight:600;margin-left:4px;}
.validation-badge.valid{background:#f0fdf4;color:#166534;}
.validation-badge.invalid{background:#fef2f2;color:#991b1b;}
.badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:600;}
.badge-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
.badge-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}

/* ── Validating card ─────────────────────────────────────────────────────── */
.vld-card{background:#0f172a;border:1px solid #1e293b;border-radius:12px;padding:28px 28px 22px;margin-bottom:20px;color:#e2e8f0;font-family:'JetBrains Mono','Courier New',monospace;}
.vld-header{display:flex;align-items:center;gap:18px;margin-bottom:20px;}
.vld-icon-wrap{position:relative;width:44px;height:44px;flex-shrink:0;}
.vld-db-icon{font-size:26px;color:#38bdf8;position:relative;z-index:2;animation:vldPulseIcon 1.6s ease-in-out infinite;}
@keyframes vldPulseIcon{0%,100%{opacity:1;transform:scale(1);}50%{opacity:.7;transform:scale(.9);}}
.vld-pulse{position:absolute;inset:-6px;border-radius:50%;border:2px solid #38bdf8;animation:vldRipple 1.6s ease-out infinite;}
@keyframes vldRipple{0%{transform:scale(.6);opacity:.9;}100%{transform:scale(1.8);opacity:0;}}
.vld-title{margin:0 0 2px;font-size:17px;font-weight:700;letter-spacing:.03em;color:#f1f5f9;}
.vld-subtitle{margin:0;font-size:12px;color:#64748b;}
.vld-strip-wrapper{position:relative;height:140px;overflow:hidden;border:1px solid #1e293b;border-radius:8px;background:#080f1a;margin-bottom:20px;}
.vld-strip{display:flex;flex-direction:column;gap:0;animation:vldScroll 4s linear infinite;}
@keyframes vldScroll{0%{transform:translateY(0);}100%{transform:translateY(-50%);}}
.vld-strip-fade{position:absolute;left:0;right:0;height:36px;z-index:2;pointer-events:none;}
.vld-strip-fade--top{top:0;background:linear-gradient(to bottom,#080f1a,transparent);}
.vld-strip-fade--bottom{bottom:0;background:linear-gradient(to top,#080f1a,transparent);}
.vld-row{display:grid;grid-template-columns:80px 90px 1fr 80px 90px;gap:0 10px;padding:5px 14px;font-size:11px;border-bottom:1px solid #0d1a2d;}
.vld-row--valid{color:#4ade80;}
.vld-row--invalid{color:#f87171;}
.vld-row--neutral{color:#475569;}
.vld-row span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.vld-row .vld-badge{font-size:10px;padding:1px 6px;border-radius:10px;font-weight:700;text-align:center;}
.vld-row--valid .vld-badge{background:#14532d;color:#4ade80;}
.vld-row--invalid .vld-badge{background:#450a0a;color:#f87171;}
.vld-row--neutral .vld-badge{background:#1e293b;color:#64748b;}
.vld-stats{display:flex;gap:0;margin-bottom:16px;border:1px solid #1e293b;border-radius:8px;overflow:hidden;}
.vld-stat{flex:1;text-align:center;padding:10px 4px;border-right:1px solid #1e293b;}
.vld-stat:last-child{border-right:none;}
.vld-stat-val{display:block;font-size:22px;font-weight:700;color:#e2e8f0;line-height:1;margin-bottom:4px;font-variant-numeric:tabular-nums;}
.vld-stat-lbl{display:block;font-size:10px;color:#475569;text-transform:uppercase;letter-spacing:.06em;}
.vld-green{color:#4ade80!important;}
.vld-red{color:#f87171!important;}
.vld-gray{color:#94a3b8!important;}
.vld-bar-track{height:10px;background:#1e293b;border-radius:99px;overflow:hidden;margin-bottom:6px;}
.vld-bar-fill{height:100%;width:0%;background:linear-gradient(90deg,#0ea5e9,#38bdf8,#7dd3fc);border-radius:99px;transition:width .4s ease;position:relative;overflow:hidden;}
.vld-bar-shine{position:absolute;top:0;bottom:0;width:60px;background:linear-gradient(90deg,transparent,rgba(255,255,255,.35),transparent);animation:vldShine 1.2s linear infinite;}
@keyframes vldShine{0%{left:-60px;}100%{left:100%;}}
.vld-bar-labels{display:flex;justify-content:space-between;font-size:11px;color:#64748b;}
@media(max-width:768px){.form-row{grid-template-columns:1fr;}.preview-summary{grid-template-columns:1fr;}}
</style>

<!-- SheetJS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<script>
let previewData = [];
const CHUNK_SIZE = 50;

/* ── Scrolling strip sample data ── */
const STRIP_SAMPLES = [
    ['26002788','T10170080','Jahinsa Stores','AYUSH TOC 110G','✓ Valid'],
    ['26006408','T101700901','PRASATH GROCERY','AYUSH TOC 70G','✓ Valid'],
    ['26009901','X9991MISS','Unknown Outlet','SURF EXCEL 1KG','✗ Invalid'],
    ['26011234','T10170045','City Pharma','CLINIC PLUS 80ML','✓ Valid'],
    ['26014567','T10170023','Green Mart','DOVE SOAP 75G','✓ Valid'],
    ['26017890','NOTFOUND','Route Missing','LIFEBUOY 150G','✗ Invalid'],
    ['26020001','T10170099','Sunrise Stores','PEPSODENT 200G','✓ Valid'],
    ['26023456','T10170012','Royal Traders','WHEEL 1KG','✓ Valid'],
    ['26026789','T10170078','Kandy Dist.','LUX SOAP 100G','✓ Valid'],
    ['26030000','T10170055','Amal Medical','FAIR & LOVELY 50G','✓ Valid'],
];

function buildStrip() {
    const strip = document.getElementById('vldStrip');
    const makeRows = () => STRIP_SAMPLES.map(s => {
        const isValid = s[4].startsWith('✓');
        const cls = isValid ? 'vld-row--valid' : 'vld-row--invalid';
        return `<div class="vld-row ${cls}">
            <span>${s[0]}</span><span>${s[1]}</span>
            <span>${s[2]}</span><span>${s[3]}</span>
            <span class="vld-badge">${s[4]}</span>
        </div>`;
    }).join('');
    strip.innerHTML = makeRows() + makeRows();
}

let _timerStart = 0, _timerRef = null;
function startTimer() {
    _timerStart = Date.now();
    _timerRef = setInterval(() => {
        document.getElementById('vldElapsed').textContent =
            Math.floor((Date.now() - _timerStart) / 1000) + 's';
    }, 1000);
}
function stopTimer() { clearInterval(_timerRef); }

function showValidating(total) {
    buildStrip();
    document.getElementById('vldTotal').textContent   = total;
    document.getElementById('vldDone').textContent    = '0';
    document.getElementById('vldValid').textContent   = '0';
    document.getElementById('vldInvalid').textContent = '0';
    document.getElementById('vldElapsed').textContent = '0s';
    document.getElementById('vldBarFill').style.width = '0%';
    document.getElementById('vldPct').textContent     = '0%';
    document.getElementById('vldEta').textContent     = 'Estimating…';
    document.getElementById('vldSubtitle').textContent = 'Starting validation…';
    document.getElementById('validatingSection').style.display = 'block';
    document.getElementById('validatingSection').scrollIntoView({ behavior: 'smooth' });
    startTimer();
}

function updateValidating(done, total, validCount, invalidCount, ci, totalChunks) {
    const pct = Math.round((done / total) * 100);
    document.getElementById('vldDone').textContent    = done;
    document.getElementById('vldValid').textContent   = validCount;
    document.getElementById('vldInvalid').textContent = invalidCount;
    document.getElementById('vldBarFill').style.width = pct + '%';
    document.getElementById('vldPct').textContent     = pct + '%';
    document.getElementById('vldSubtitle').textContent =
        `Chunk ${ci + 1} of ${totalChunks} — ${done} rows checked`;
    const elapsed = (Date.now() - _timerStart) / 1000;
    if (done > 0 && elapsed > 0) {
        const eta = Math.ceil((total - done) / (done / elapsed));
        document.getElementById('vldEta').textContent = eta > 0 ? `~${eta}s remaining` : 'Almost done…';
    }
}

function hideValidating() {
    stopTimer();
    document.getElementById('validatingSection').style.display = 'none';
}

/* ── File upload handler ── */
document.getElementById('uploadForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const billDate = document.getElementById('bill_date_filter').value;
    const file     = document.getElementById('excel_file').files[0];
    if (!file)     { alert('Please select an Excel file'); return; }
    if (!billDate) { alert('Please select a Bill Date'); return; }

    const uploadBtn = document.getElementById('uploadBtn');
    uploadBtn.disabled = true;
    uploadBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Reading file…';

    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const workbook   = XLSX.read(new Uint8Array(e.target.result), { type: 'array', cellDates: true });
            const firstSheet = workbook.Sheets[workbook.SheetNames[0]];

            // raw:true keeps date cells as JS Date objects.
            // raw:false would use the cell's own number format (mm-dd-yy) and
            // produce "02-19-26" which is unparseable — that was the original bug.
            const jsonData = XLSX.utils.sheet_to_json(firstSheet, {
                header: 1,
                defval: '',
                raw: true
            });

            /**
             * Convert any cell value to a yyyy-mm-dd string safely.
             *
             * Cases handled:
             *  1. JS Date object  (cellDates:true + raw:true)
             *     → Use UTC methods to avoid timezone shift.
             *       Sri Lanka is UTC+5:30: a UTC-midnight date read with
             *       local getDate() would show the PREVIOUS day.
             *
             *  2. Excel serial number (date stored as raw number, no cellDates)
             *     → Convert via UTC epoch math.
             *
             *  3. String already in any common format
             *     → Pass through; PHP normDate() handles parsing.
             *       Formats supported by PHP side: yyyy-mm-dd, d/m/Y,
             *       d-m-Y (01-02-2025 = day-month-year), m/d/Y, etc.
             */
            function toDateStr(v) {
                if (v === null || v === undefined || v === '') return '';

                // ── Case 1: JS Date object ───────────────────────────────
                if (v instanceof Date) {
                    if (isNaN(v.getTime())) return '';
                    // Always use UTC getters — avoids UTC+5:30 day-shift bug
                 // SheetJS creates dates as LOCAL midnight, so use LOCAL getters.
// getUTCDate() in UTC+5:30 returns the PREVIOUS day (18:30 UTC = midnight local).
const y  = v.getFullYear();
const m  = String(v.getMonth() + 1).padStart(2, '0');
const d  = String(v.getDate()).padStart(2, '0');
                    return `${y}-${m}-${d}`;
                }

                // ── Case 2: Excel serial number ──────────────────────────
                if (typeof v === 'number') {
                    // Excel epoch is 1899-12-30 UTC
                    const utcMs = (v - 25569) * 86400000;
                    const dt    = new Date(utcMs);
                    if (isNaN(dt.getTime())) return '';
                    const y  = dt.getUTCFullYear();
                    const m  = String(dt.getUTCMonth() + 1).padStart(2, '0');
                    const d  = String(dt.getUTCDate()).padStart(2, '0');
                    return `${y}-${m}-${d}`;
                }

                // ── Case 3: String — pass to PHP for parsing ─────────────
                return String(v).trim();
            }

            // Data starts at row 14 in Excel = index 13 (0-based)
            // Row 12 (index 11) = col numbers, Row 13 (index 12) = headers, Row 14 (index 13) = first data
            const rows = jsonData.slice(13);

            /*  Column index → Excel column (1-based shown in header row):
                [1]  Scheme No       [2]  Desc            [3]  Scheme Type
                [4]  Bill No         [5]  Bill Date        [7]  Beat Name
                [9]  HUL Code        [10] Party Name       [13] SKU7 Code
                [14] Product Name    [15] Sold Qty         [17] Free Qty
                [19] Free Value      [20] Sch Disc         [21] Gross Sales
                [22] Salesman Code
            */
            const dataObjects = rows.map(row => ({
                'Scheme No':    row[1]  || '',
                'Desc':         row[2]  || '',
                'Scheme Type':  row[3]  || '',
                'Bill No':      row[4]  || '',
                'Bill Date':    toDateStr(row[5]),
                'Beat Name':    row[7]  || '',
                'HUL Code':     row[9]  || '',
                'Party Name':   row[10] || '',
                'SKU7 Code':    row[13] || '',
                'Product Name': row[14] || '',
                'Sold Qty':     row[15] || 0,
                'Free Qty':     row[17] || 0,
                'Free Value':   row[19] || 0,
                'Sch Disc':     row[20] || 0,
                'Gross Sales':  row[21] || 0,
                'Salesman Code':row[22] || '',
            }));

            // Filter out empty / totals rows
            const validData = dataObjects.filter(row =>
                (row['Bill No'] && String(row['Bill No']).trim() !== '') ||
                (row['Scheme No'] && String(row['Scheme No']).trim() !== '')
            );

            if (validData.length === 0) {
                resetUploadBtn(uploadBtn);
                alert('No valid data found in Excel file. Make sure this is the Bill Wise Scheme Analysis report.');
                return;
            }

            console.log('Total rows:', jsonData.length, '| Data rows:', validData.length, '| Sample:', validData[0]);

            resetUploadBtn(uploadBtn);
            validateAndPreview(validData, billDate);

        } catch (err) {
            resetUploadBtn(uploadBtn);
            alert('Error reading Excel file: ' + err.message);
        }
    };
    reader.onerror = () => { resetUploadBtn(uploadBtn); alert('File read error'); };
    reader.readAsArrayBuffer(file);
});

function resetUploadBtn(btn) {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-upload"></i> Upload & Preview';
}

/* ── Chunked validation ── */
async function validateAndPreview(data, billDate) {
    const chunks = [];
    for (let i = 0; i < data.length; i += CHUNK_SIZE) chunks.push(data.slice(i, i + CHUNK_SIZE));

    showValidating(data.length);

    let allValidated = [], validSoFar = 0, invalidSoFar = 0;

    for (let ci = 0; ci < chunks.length; ci++) {
        const fd = new FormData();
        fd.append('data',      JSON.stringify(chunks[ci]));
        fd.append('bill_date', billDate);

        let result;
        try {
            const res  = await fetch('validate_scheme_import.php', { method: 'POST', body: fd });
            const text = await res.text();
            result = JSON.parse(text.substring(text.indexOf('{')));
        } catch (err) {
            hideValidating();
            alert('Validation network error (chunk ' + (ci+1) + '): ' + err.message);
            return;
        }
        if (!result.success) { hideValidating(); alert('Validation error: ' + result.message); return; }

        allValidated  = allValidated.concat(result.data);
        validSoFar   += (result.valid   || 0);
        invalidSoFar += (result.total   || 0) - (result.valid || 0);
        updateValidating(allValidated.length, data.length, validSoFar, invalidSoFar, ci, chunks.length);
    }

    hideValidating();
    previewData = allValidated;
    displayPreview();
}

/* ── Preview table ── */
let showInvalidOnly = false;

function toggleInvalidFilter() {
    showInvalidOnly = !showInvalidOnly;
    document.getElementById('filterInvalidBtn').style.display = showInvalidOnly ? 'none' : '';
    document.getElementById('filterAllBtn').style.display     = showInvalidOnly ? '' : 'none';
    const inv = previewData.filter(r => !r.t_code_valid || !r.route_valid).length;
    document.getElementById('filterNote').textContent = showInvalidOnly ? `Showing ${inv} invalid record(s) only` : '';
    renderPreviewTable();
}

function displayPreview() {
    renderPreviewTable();
    const valid = previewData.filter(r => r.t_code_valid && r.route_valid).length;
    document.getElementById('totalRecords').textContent   = previewData.length;
    document.getElementById('validRecords').textContent   = valid;
    document.getElementById('invalidRecords').textContent = previewData.length - valid;
    document.getElementById('previewSection').style.display = 'block';
    document.getElementById('previewSection').scrollIntoView({ behavior: 'smooth' });
}

function renderPreviewTable() {
    const tbody = document.getElementById('previewTableBody');
    tbody.innerHTML = '';
    const rows = showInvalidOnly
        ? previewData.filter(r => !r.t_code_valid || !r.route_valid)
        : previewData;

    rows.forEach(record => {
        const gi      = previewData.indexOf(record);
        const isValid = record.t_code_valid && record.route_valid;
        const row     = document.createElement('tr');
        if (!isValid) row.classList.add('row-invalid');
        const errTip  = record.error_message
            ? ` title="${String(record.error_message).replace(/"/g,'&quot;')}"` : '';

        row.innerHTML = `
            <td>${record.scheme_no   || '-'}</td>
            <td title="${record.scheme_desc || ''}">${(record.scheme_desc || '-').substring(0,30)}${(record.scheme_desc||'').length>30?'…':''}</td>
            <td>${record.scheme_type || '-'}</td>
            <td>${record.bill_no     || '-'}</td>
            <td>${record.bill_date   || '-'}</td>
            <td>${record.beat_name   || '-'}</td>
            <td>
                ${record.hul_code || '-'}
                ${record.t_code_valid
                    ? `<span class="validation-badge valid"><i class="fa-solid fa-check"></i> ${record.customer_name||'Valid'}</span>`
                    : '<span class="validation-badge invalid"><i class="fa-solid fa-xmark"></i> Invalid</span>'}
            </td>
            <td>${record.party_name  || '-'}</td>
            <td>${record.sku7_code   || '-'}</td>
            <td title="${record.product_name||''}">${(record.product_name||'-').substring(0,25)}${(record.product_name||'').length>25?'…':''}</td>
            <td style="text-align:right;">${parseFloat(record.sold_qty   ||0).toFixed(2)}</td>
            <td style="text-align:right;">${parseFloat(record.free_qty   ||0).toFixed(2)}</td>
            <td style="text-align:right;">${parseFloat(record.free_value ||0).toFixed(2)}</td>
            <td style="text-align:right;font-weight:600;">${parseFloat(record.sch_disc   ||0).toFixed(2)}</td>
            <td style="text-align:right;">${parseFloat(record.gross_sales||0).toFixed(2)}</td>
            <td>${record.salesman_code||'-'}</td>
            <td${errTip}>${isValid
                ? '<span class="badge badge-success">Ready</span>'
                : `<span class="badge badge-error"${errTip}>Invalid</span>`}</td>
            <td>${!record.t_code_valid && record.hul_code
                ? `<button type="button" class="btn btn-secondary" onclick="openQuickAddModal(${gi})"
                      style="font-size:11px;padding:5px 10px;border-color:#22c55e;color:#166534;white-space:nowrap;">
                      <i class="fa-solid fa-user-plus"></i> Add Customer</button>` : '-'}</td>`;
        tbody.appendChild(row);
    });
}

/* ── Quick add modal ── */
let qacRowIndex = null;
function openQuickAddModal(idx) {
    qacRowIndex = idx;
    const r = previewData[idx];
    document.getElementById('qacTCode').value    = r.hul_code   || '';
    document.getElementById('qacPartyName').value = r.party_name || '';
    document.getElementById('qacShopName').value  = r.party_name || '';
    document.getElementById('qacError').style.display = 'none';
    document.getElementById('quickAddCustomerModal').style.display = 'flex';
    setTimeout(() => document.getElementById('qacShopName').focus(), 100);
}
function closeQuickAddModal() {
    document.getElementById('quickAddCustomerModal').style.display = 'none';
    qacRowIndex = null;
}
function saveQuickCustomer() {
    const tCode = document.getElementById('qacTCode').value.trim();
    const shop  = document.getElementById('qacShopName').value.trim();
    const errEl = document.getElementById('qacError');
    if (!shop) { errEl.textContent='Shop name is required.'; errEl.style.display='block'; return; }
    const btn = document.getElementById('qacSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    errEl.style.display = 'none';
    const fd = new FormData();
    fd.append('t_code', tCode); fd.append('shop_name', shop);
    fetch('quick_add_customer.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save & Validate';
            if (res.success) {
                previewData[qacRowIndex].t_code_valid  = true;
                previewData[qacRowIndex].customer_name = shop;
                closeQuickAddModal();
                const valid = previewData.filter(r => r.t_code_valid && r.route_valid).length;
                document.getElementById('validRecords').textContent   = valid;
                document.getElementById('invalidRecords').textContent = previewData.length - valid;
                renderPreviewTable();
            } else { errEl.textContent = res.message||'Failed.'; errEl.style.display='block'; }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save & Validate';
            errEl.textContent = 'Network error: ' + err.message; errEl.style.display = 'block';
        });
}

/* ── Chunked import ── */
async function startImport() {
    if (!previewData.length) { alert('No data to import'); return; }
    const validData = previewData.filter(r => r.t_code_valid && r.route_valid);
    if (!validData.length) { alert('No valid records to import'); return; }
    if (!confirm(`Import ${validData.length} valid records?`)) return;

    document.getElementById('previewSection').style.display  = 'none';
    document.getElementById('progressSection').style.display = 'block';

    const filename = document.getElementById('excel_file').files[0].name;
    const billDate = document.getElementById('bill_date_filter').value;

    const slimData = validData.map(r => ({
        scheme_no:    r.scheme_no    || '',
        scheme_desc:  r.scheme_desc  || '',
        scheme_type:  r.scheme_type  || '',
        bill_no:      r.bill_no      || '',
        bill_date:    r.bill_date    || '',
        beat_name:    r.beat_name    || '',
        hul_code:     r.hul_code     || '',
        party_name:   r.party_name   || '',
        sku7_code:    r.sku7_code    || '',
        product_name: r.product_name || '',
        sold_qty:     r.sold_qty     || 0,
        free_qty:     r.free_qty     || 0,
        free_value:   r.free_value   || 0,
        sch_disc:     r.sch_disc     || 0,
        gross_sales:  r.gross_sales  || 0,
        salesman_code:r.salesman_code|| '',
        customer_name:r.customer_name|| '',
        route_name:   r.route_name   || '',
        t_code_valid: true,
        route_valid:  true
    }));

    const chunks = [];
    for (let i = 0; i < slimData.length; i += CHUNK_SIZE) chunks.push(slimData.slice(i, i + CHUNK_SIZE));

    let importId = 0, totalImported = 0, totalFailed = 0;

    for (let ci = 0; ci < chunks.length; ci++) {
        const fd = new FormData();
        fd.append('data',          JSON.stringify(chunks[ci]));
        fd.append('bill_date',     billDate);
        fd.append('filename',      filename);
        fd.append('chunk_index',   ci);
        fd.append('total_chunks',  chunks.length);
        fd.append('total_records', slimData.length);
        fd.append('import_id',     importId);

        let result;
        try {
            const res  = await fetch('process_scheme_import.php', { method: 'POST', body: fd });
            const text = await res.text();
            result = JSON.parse(text.substring(text.indexOf('{')));
        } catch (err) {
            alert('Import error (chunk ' + (ci+1) + '): ' + err.message);
            document.getElementById('progressSection').style.display = 'none';
            document.getElementById('previewSection').style.display  = 'block';
            return;
        }
        if (!result.success) {
            alert('Import failed: ' + result.message);
            document.getElementById('progressSection').style.display = 'none';
            document.getElementById('previewSection').style.display  = 'block';
            return;
        }
        if (ci === 0) importId = result.import_id;
        totalImported += result.chunk_imported;
        totalFailed   += result.chunk_failed;
        updateProgress({ imported: totalImported, total: slimData.length });
        document.getElementById('progressStatus').textContent =
            `Chunk ${ci+1}/${chunks.length} — ${totalImported} saved, ${totalFailed} failed`;
    }

    document.getElementById('progressStatus').textContent =
        `✅ Done! ${totalImported} imported, ${totalFailed} failed.`;
    setTimeout(() => window.location.reload(), 2000);
}

function updateProgress(p) {
    const pct = Math.round((p.imported / p.total) * 100);
    document.getElementById('progressFill').style.width    = pct + '%';
    document.getElementById('progressFill').textContent    = pct + '%';
    document.getElementById('progressPercent').textContent = pct + '%';
}

function cancelImport() {
    if (confirm('Cancel import?')) {
        document.getElementById('previewSection').style.display = 'none';
        document.getElementById('uploadForm').reset();
        previewData = [];
    }
}

document.getElementById('quickAddCustomerModal').addEventListener('click', function(e) {
    if (e.target === this) closeQuickAddModal();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeQuickAddModal(); });
</script>

<?php include 'footer.php'; ?>