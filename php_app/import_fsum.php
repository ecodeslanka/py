<?php
include 'config.php';
include 'header.php';

// Create imports tracking table
$createImportsTable = "CREATE TABLE IF NOT EXISTS loading_summary_imports (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    delivery_date DATE NOT NULL,
    filename VARCHAR(255) NOT NULL,
    total_records INT(11) DEFAULT 0,
    imported_records INT(11) DEFAULT 0,
    failed_records INT(11) DEFAULT 0,
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    imported_by INT(11) NULL,
    imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_delivery_date (delivery_date),
    INDEX idx_status (status)
)";
mysqli_query($conn, $createImportsTable);

$createImportDetailsTable = "CREATE TABLE IF NOT EXISTS loading_summary_import_details (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    import_id INT(11) NOT NULL,
    sales_person_code VARCHAR(100) NULL,
    t_code VARCHAR(50) NULL,
    route_code VARCHAR(50) NULL,
    bill_no VARCHAR(100) NULL,
    bill_date DATE NULL,
    outlet_code VARCHAR(100) NULL,
    party_name VARCHAR(255) NULL,
    free_qty DECIMAL(12,2) DEFAULT 0,
    gross_sales DECIMAL(12,2) DEFAULT 0,
    scheme_disc DECIMAL(12,2) DEFAULT 0,
    rs_discount DECIMAL(12,2) DEFAULT 0,
    tot_disc DECIMAL(12,2) DEFAULT 0,
    total_discount DECIMAL(12,2) DEFAULT 0,
    good_returns_value DECIMAL(12,2) DEFAULT 0,
    damage_expiry_shortage_value DECIMAL(12,2) DEFAULT 0,
    final_bill_amount DECIMAL(12,2) DEFAULT 0,
    delivery_person VARCHAR(255) NULL,
    delivery_date DATE NULL,
    t_code_valid TINYINT(1) DEFAULT 0,
    route_valid TINYINT(1) DEFAULT 0,
    customer_name VARCHAR(255) NULL,
    route_name VARCHAR(255) NULL,
    status ENUM('pending', 'imported', 'failed') DEFAULT 'pending',
    error_message TEXT NULL,
    loading_summary_id INT(11) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (import_id) REFERENCES loading_summary_imports(id) ON DELETE CASCADE,
    INDEX idx_import_id (import_id),
    INDEX idx_status (status)
)";
mysqli_query($conn, $createImportDetailsTable);

if (isset($_GET['delete_import'])) {
    $import_id = intval($_GET['delete_import']);
    mysqli_query($conn, "DELETE FROM loading_summary_import_details WHERE import_id = $import_id");
    if (mysqli_query($conn, "DELETE FROM loading_summary_imports WHERE id = $import_id")) {
        $success_message = "Import deleted successfully!";
    }
}
?>

<div class="page-header">
    <h2 class="page-title">
        <i class="fa-solid fa-file-excel"></i> Import Pre - Secondary Invoices
    </h2>
    <p class="page-subtitle">Upload Excel file to import loading summary data</p>
</div>

<?php if (isset($success_message)): ?>
<div class="alert alert-success">
    <i class="fa-solid fa-circle-check"></i>
    <?php echo $success_message; ?>
</div>
<?php endif; ?>

<div class="info-box">
    <div class="info-icon"><i class="fa-solid fa-info-circle"></i></div>
    <div class="info-content">
        <strong>Need a template?</strong>
        Download the Excel template with correct column order
        <a href="download_template.html" target="_blank" class="template-link">
            <i class="fa-solid fa-download"></i> Download Template
        </a>
    </div>
</div>

<!-- Import Form -->
<div class="content-card">
    <h3 class="card-title">
        <i class="fa-solid fa-upload"></i> Step 1: Upload Excel File
    </h3>
    <form id="uploadForm" enctype="multipart/form-data">
        <div class="form-row">
            <div class="form-group required-field">
                <label class="form-label">Delivery Date <span class="required">*</span></label>
                <input type="date" id="delivery_date" name="delivery_date" class="form-input" required>
            </div>
            <div class="form-group required-field">
                <label class="form-label">Excel File <span class="required">*</span></label>
                <input type="file" id="excel_file" name="excel_file" class="form-input" accept=".xlsx,.xls" required>
                <small class="form-hint">
                    <strong>Column Order:</strong>
                    2: Salesperson Code, 4: T-Code, 5: Route, 6: Bill No, 8: Bill Date,
                    9: Outlet Code, 10: Party Name, 12: Free Qty, 13: Gross Sales,
                    14: Scheme Disc, 15: RS Discount, 16: TOT Disc, 17: Total Discount,
                    18: Taxable Amount, <strong style="color:#7c3aed;">19: Tax Amount</strong>, 20: Bill Value,
                    21: Good Returns, 22: Dmg/Expiry, 23: Final Bill Amount, 26: Delivery Person
                </small>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary" id="uploadBtn">
                <i class="fa-solid fa-upload"></i> Upload & Preview
            </button>
        </div>

        <!-- Blacklist hold — ALWAYS ON (not optional) -->
        <div class="blacklist-check-option" id="blacklistCheckOption" style="display:none;">
            <div class="bl-check-label" style="cursor:default;">
                <span class="bl-check-text">
                    <strong><i class="fa-solid fa-shield-halved" style="color:#4338ca;margin-right:6px;"></i>Blacklist Protection — Automatic</strong>
                    <span>Blacklisted customers' invoices are imported into the <strong>same list</strong> as everything else, but are automatically marked <strong>Cancelled</strong>. Every other row imports normally.</span>
                </span>
                <span class="bl-check-badge"><i class="fa-solid fa-ban"></i> Always On</span>
            </div>
        </div>
    </form>
</div>

<!-- Preview Section -->
<div id="previewSection" class="content-card" style="display: none;">
    <h3 class="card-title">
        <i class="fa-solid fa-eye"></i> Step 2: Preview & Validate Data
    </h3>

    <!-- Auto-created customers banner -->
    <div id="autoCreatedBanner" style="display:none; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:14px 18px; margin-bottom:16px; align-items:flex-start; gap:12px;">
        <i class="fa-solid fa-user-plus" style="color:#16a34a; font-size:20px; margin-top:2px; flex-shrink:0;"></i>
        <div>
            <div style="font-weight:700; color:#166534; margin-bottom:4px;">Customers Auto-Created</div>
            <div id="autoCreatedText" style="font-size:13px; color:#15803d; line-height:1.5;"></div>
        </div>
    </div>

    <!-- Same-date update banner -->
    <div id="sameDateBanner" style="display:none; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:14px 18px; margin-bottom:16px; align-items:flex-start; gap:12px;">
        <i class="fa-solid fa-rotate" style="color:#2563eb; font-size:20px; margin-top:2px; flex-shrink:0;"></i>
        <div>
            <div style="font-weight:700; color:#1e40af; margin-bottom:4px;">Same Date — Only New Invoices Shown</div>
            <div id="sameDateText" style="font-size:13px; color:#1d4ed8; line-height:1.5;"></div>
        </div>
    </div>

    <!-- Invalid routes error banner — blocks import -->
    <div id="invalidErrorBanner" style="display:none; background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:14px 18px; margin-bottom:16px; align-items:flex-start; gap:12px;">
        <i class="fa-solid fa-circle-xmark" style="color:#dc2626; font-size:20px; margin-top:2px; flex-shrink:0;"></i>
        <div>
            <div style="font-weight:700; color:#991b1b; margin-bottom:4px;">Import Blocked — Invalid Routes Found</div>
            <div id="invalidErrorText" style="font-size:13px; color:#b91c1c; line-height:1.5;"></div>
            <div style="font-size:12px; color:#991b1b; margin-top:6px;">
                <strong>Fix invalid routes</strong> in the Routes master before importing, or remove those rows.
            </div>
        </div>
    </div>

    <div class="preview-summary">
        <div class="summary-item">
            <span class="summary-label">Total Records</span>
            <span class="summary-value" id="totalRecords">0</span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Valid (Ready)</span>
            <span class="summary-value valid" id="validRecords">0</span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Invalid Routes</span>
            <span class="summary-value invalid" id="invalidRecords">0</span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Skipped (Duplicate)</span>
            <span class="summary-value duplicate" id="duplicateRecords">0</span>
        </div>
        <div class="summary-item" id="blacklistSummaryItem" style="display:none;">
            <span class="summary-label">Blacklisted</span>
            <span class="summary-value blacklisted" id="blacklistedRecords">0</span>
        </div>
    </div>

    <!-- Blacklisted customers banner (shown when blacklist check is ON and blacklisted records exist) -->
    <div id="blacklistedBanner" style="display:none; background:#1e1b4b; border:1px solid #4338ca; border-radius:8px; padding:16px 18px; margin-bottom:16px; flex-direction:column; gap:10px;">
        <div style="display:flex; align-items:flex-start; gap:12px;">
            <i class="fa-solid fa-ban" style="color:#818cf8; font-size:20px; margin-top:2px; flex-shrink:0;"></i>
            <div style="flex:1;">
                <div style="font-weight:700; color:#c7d2fe; margin-bottom:4px; font-size:14px;">Blacklisted Customers Detected</div>
                <div id="blacklistedBannerText" style="font-size:13px; color:#a5b4fc; line-height:1.5;"></div>
                <div style="font-size:12px; color:#818cf8; margin-top:6px;">
                    They import into <strong style="color:#c7d2fe;">the same list, under this same import</strong> — they are just marked <strong style="color:#c7d2fe;">Cancelled</strong> instead of Imported. All other rows import normally.
                </div>
            </div>
            <button type="button" onclick="setFilter('blacklisted')" style="flex-shrink:0; padding:5px 12px; border:1px solid #6366f1; border-radius:6px; background:transparent; color:#a5b4fc; font-size:12px; font-weight:600; cursor:pointer; font-family:inherit;">
                <i class="fa-solid fa-eye"></i> View Blacklisted
            </button>
        </div>
        <!-- Blacklisted customers detail list -->
        <div id="blacklistedDetailList" style="border:1px solid #4338ca; border-radius:6px; overflow:hidden; max-height:200px; overflow-y:auto;"></div>
    </div>

    <!-- Filter toolbar -->
    <div style="display:flex; align-items:center; gap:8px; margin-bottom:12px; flex-wrap:wrap;">
        <button type="button" id="filterAllBtn" class="filter-btn active" onclick="setFilter('all')">
            <i class="fa-solid fa-list"></i> All
        </button>
        <button type="button" id="filterValidBtn" class="filter-btn" onclick="setFilter('valid')">
            <i class="fa-solid fa-check-circle"></i> Valid Only
        </button>
        <button type="button" id="filterInvalidBtn" class="filter-btn" onclick="setFilter('invalid')" style="border-color:#ef4444; color:#ef4444;">
            <i class="fa-solid fa-xmark-circle"></i> Invalid Only
        </button>
        <button type="button" id="filterBlacklistedBtn" class="filter-btn" onclick="setFilter('blacklisted')" style="display:none; border-color:#4338ca; color:#4338ca;">
            <i class="fa-solid fa-ban"></i> Blacklisted
        </button>
        <span id="filterNote" style="font-size:12px; color:#6b7280; margin-left:4px;"></span>
    </div>

    <div class="table-responsive">
        <table class="data-table" id="previewTable">
            <thead>
                <tr>
                    <th style="width:36px;">#</th>
                    <th>Sales Code</th>
                    <th>T-Code</th>
                    <th>Route</th>
                    <th>Bill No</th>
                    <th>Bill Date</th>
                    <th>Outlet Code</th>
                    <th>Party Name</th>
                    <th>Free Qty</th>
                    <th>Gross Sales</th>
                    <th>Scheme Disc</th>
                    <th>RS Discount</th>
                    <th>TOT Disc</th>
                    <th>Total Disc</th>
                    <th>Tax Amt</th>
                    <th>Bill Value</th>
                    <th>Good Returns</th>
                    <th>Dmg/Expiry</th>
                    <th>Final Bill Amt</th>
                    <th>Del. Person</th>
                    <th>Status</th>
                    <th class="bl-col-header" style="display:none;">Blacklist</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="previewTableBody"></tbody>
        </table>
    </div>

    <div class="form-actions" style="margin-top:20px;">
        <button type="button" class="btn btn-secondary" onclick="cancelImport()">
            <i class="fa-solid fa-xmark"></i> Cancel
        </button>
        <button type="button" class="btn btn-primary" id="importBtn" onclick="startImport()" disabled>
            <i class="fa-solid fa-file-import"></i> Start Import
        </button>
    </div>
</div>

<!-- Progress Section -->
<div id="progressSection" class="content-card" style="display: none;">
    <h3 class="card-title">
        <i class="fa-solid fa-spinner fa-spin"></i> Importing Data...
    </h3>
    <div class="progress-container">
        <div class="progress-bar">
            <div class="progress-fill" id="progressFill">0%</div>
        </div>
        <div class="progress-text">
            <span id="progressPercent">0%</span>
            <span id="progressStatus">Preparing...</span>
        </div>
    </div>
</div>

<div class="content-card" style="text-align: center; padding: 30px;">
    <p style="color: #666; margin-bottom: 16px;">
        <i class="fa-solid fa-info-circle"></i>
        View previously imported files and their status
    </p>
    <a href="import_history.php" class="btn btn-secondary">
        <i class="fa-solid fa-history"></i> View Import History
    </a>
</div>

<style>
.content-card { background:#fff; border:1px solid #e5e5e5; border-radius:8px; padding:20px; margin-bottom:20px; }
.card-title { font-size:16px; font-weight:600; margin-bottom:16px; color:#1f2937; display:flex; align-items:center; gap:8px; }

.alert { padding:12px 16px; border-radius:6px; margin-bottom:20px; display:flex; align-items:center; gap:8px; font-size:13px; }
.alert-success { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }

.info-box { display:flex; align-items:center; gap:16px; padding:16px 20px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; margin-bottom:24px; }
.info-icon { font-size:24px; color:#1e40af; }
.info-content { flex:1; font-size:14px; color:#1e3a8a; }
.info-content strong { display:block; margin-bottom:4px; color:#1e40af; }
.template-link { display:inline-flex; align-items:center; gap:6px; margin-left:12px; padding:6px 14px; background:#1e40af; color:#fff; text-decoration:none; border-radius:6px; font-weight:600; font-size:13px; }
.template-link:hover { background:#1e3a8a; }

.form-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px; }
.form-group { margin-bottom:16px; }
.form-label { display:block; font-size:13px; font-weight:600; margin-bottom:6px; color:#374151; }
.form-input { width:100%; padding:10px 14px; border:1px solid #e5e5e5; border-radius:6px; font-size:14px; font-family:'Inter',sans-serif; }
.form-input:focus { outline:none; border-color:#000; box-shadow:0 0 0 2px rgba(0,0,0,.05); }
.required-field .form-input { background:#fffbeb; }
.required { color:#ef4444; }
.form-hint { display:block; font-size:11px; color:#666; margin-top:4px; }
.form-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:16px; padding-top:16px; border-top:1px solid #e5e5e5; }

.btn { display:inline-flex; align-items:center; gap:6px; padding:10px 20px; border:none; border-radius:6px; font-size:14px; font-weight:600; cursor:pointer; transition:all .3s; font-family:'Inter',sans-serif; }
.btn-primary { background:#000; color:#fff; }
.btn-primary:hover { background:#333; }
.btn-primary:disabled { background:#9ca3af; cursor:not-allowed; }
.btn-secondary { background:#f5f5f5; color:#333; border:1px solid #e5e5e5; }
.btn-secondary:hover { background:#e5e5e5; }
.btn-danger { background:#fff; color:#dc2626; border:1px solid #fca5a5; }
.btn-danger:hover { background:#dc2626; color:#fff; border-color:#dc2626; }

.preview-summary { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:20px; padding:16px; background:#f9fafb; border-radius:8px; }
.summary-item { text-align:center; }
.summary-label { display:block; font-size:12px; color:#6b7280; margin-bottom:4px; }
.summary-value { display:block; font-size:28px; font-weight:700; color:#1f2937; }
.summary-value.valid { color:#22c55e; }
.summary-value.invalid { color:#ef4444; }
.summary-value.duplicate { color:#ea580c; }

.filter-btn { display:inline-flex; align-items:center; gap:5px; padding:6px 14px; border:1px solid #e5e5e5; border-radius:20px; background:#f5f5f5; color:#555; font-size:12px; font-weight:600; cursor:pointer; transition:all .2s; font-family:'Inter',sans-serif; }
.filter-btn.active { background:#1f2937; color:#fff; border-color:#1f2937; }
.filter-btn:hover:not(.active) { background:#e5e5e5; }

.progress-container { margin:24px 0; }
.progress-bar { width:100%; height:50px; background:#f0f0f0; border-radius:25px; overflow:hidden; margin-bottom:16px; box-shadow:inset 0 2px 4px rgba(0,0,0,.1); }
.progress-fill { height:100%; background:linear-gradient(90deg,#000 0%,#333 100%); width:0%; transition:width .3s ease; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:16px; }
.progress-text { display:flex; justify-content:space-between; align-items:center; font-size:14px; color:#666; }
#progressPercent { font-weight:700; color:#000; font-size:20px; }

.validation-badge { display:inline-flex; align-items:center; gap:4px; padding:2px 7px; border-radius:10px; font-size:10px; font-weight:600; margin-left:5px; }
.validation-badge.valid { background:#f0fdf4; color:#166534; }
.validation-badge.invalid { background:#fef2f2; color:#991b1b; }
.validation-badge.auto { background:#ecfdf5; color:#065f46; border:1px dashed #6ee7b7; }

.badge { display:inline-flex; align-items:center; gap:4px; padding:4px 10px; border-radius:12px; font-size:11px; font-weight:600; }
.badge-success { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
.badge-warning { background:#fef3c7; color:#92400e; border:1px solid #fde68a; }
.badge-error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }

.table-responsive { overflow-x:auto; }
.data-table { width:100%; border-collapse:collapse; font-size:12px; }
.data-table thead { background:#fafafa; border-bottom:2px solid #e5e5e5; }
.data-table th { padding:10px 12px; text-align:left; font-weight:600; color:#333; font-size:11px; white-space:nowrap; }
.data-table tbody tr { border-bottom:1px solid #f0f0f0; }
.data-table tbody tr:hover { background:#fafafa; }
.data-table tbody tr.row-invalid { background:#fef2f2; }
.data-table td { padding:10px 12px; color:#333; vertical-align:middle; }

.btn-remove-row { display:inline-flex; align-items:center; justify-content:center; gap:4px; padding:5px 10px; border:1px solid #fca5a5; border-radius:6px; background:#fff; color:#dc2626; font-size:11px; font-weight:600; cursor:pointer; transition:all .2s; font-family:'Inter',sans-serif; white-space:nowrap; }
.btn-remove-row:hover { background:#dc2626; color:#fff; border-color:#dc2626; }

.summary-value.blacklisted { color:#4338ca; }

/* ── Blacklist Check Option ── */
.blacklist-check-option { margin-top:14px; padding:14px 16px; background:#f5f3ff; border:1px solid #c4b5fd; border-radius:8px; }
.bl-check-label { display:flex; align-items:flex-start; gap:12px; cursor:pointer; }
.bl-check-label input[type=checkbox] { display:none; }
.bl-check-box-visual { flex-shrink:0; width:18px; height:18px; border:2px solid #6d28d9; border-radius:4px; margin-top:2px; transition:all .2s; display:flex; align-items:center; justify-content:center; background:#fff; }
.bl-check-label input[type=checkbox]:checked ~ .bl-check-box-visual { background:#6d28d9; border-color:#6d28d9; }
.bl-check-label input[type=checkbox]:checked ~ .bl-check-box-visual::after { content:'✓'; color:#fff; font-size:12px; font-weight:700; }
.bl-check-text { flex:1; font-size:13px; color:#4c1d95; line-height:1.5; }
.bl-check-text strong { display:block; color:#3b0764; font-size:14px; margin-bottom:2px; }
.bl-check-badge { flex-shrink:0; display:inline-flex; align-items:center; gap:5px; padding:4px 10px; background:#4338ca; color:#fff; border-radius:12px; font-size:11px; font-weight:700; white-space:nowrap; }

/* ── Blacklisted row & badge ── */
.data-table tbody tr.row-blacklisted { background:#f5f3ff; border-left:3px solid #4338ca; }
.badge-blacklisted { background:#1e1b4b; color:#a5b4fc; border:1px solid #4338ca; }

/* ── Blacklist reason tooltip cell ── */
.bl-reason-cell { font-size:11px; color:#6d28d9; line-height:1.4; max-width:180px; }
.bl-reason-date { color:#a5b4fc; font-size:10px; margin-top:2px; }

@media(max-width:768px){
    .form-row { grid-template-columns:1fr; }
    .preview-summary { grid-template-columns:1fr 1fr; }
}
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
let previewData = [];
let importId    = null;
let activeFilter = 'all';
let existingImportId = null; // set by validation if same-date import exists
let skippedDuplicateCount = 0;
/* Blacklist hold is ALWAYS ON — blocked customers' invoices are held
   automatically on every import; there is no opt-out. */
const blacklistCheckEnabled = true;

// Show the always-on blacklist notice once a file is chosen
document.getElementById('excel_file').addEventListener('change', function() {
    document.getElementById('blacklistCheckOption').style.display = this.files.length ? 'block' : 'none';
});

// ── Upload & parse ──────────────────────────────────────────────────────────
document.getElementById('uploadForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const deliveryDate = document.getElementById('delivery_date').value;
    const file = document.getElementById('excel_file').files[0];

    if (!file)         { alert('Please select an Excel file'); return; }
    if (!deliveryDate) { alert('Please select delivery date'); return; }

    showLoader();

    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const data     = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, { type: 'array' });
            const sheet    = workbook.Sheets[workbook.SheetNames[0]];
            const jsonData = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: '' });

            if (jsonData.length < 1) { hideLoader(); alert('Excel file is empty'); return; }

            const rows = jsonData.slice(19);

            const dataObjects = rows.map(row => ({
                'Sales Person Code':            row[1]  || '',
                'T-Code':                       row[3]  || '',
                'Route':                        row[4]  || '',
                'Bill No':                      row[5]  || '',
                'Bill Date':                    row[7]  || '',
                'Outlet Code':                  row[8]  || '',
                'Party Name':                   row[9]  || '',
                'Free Qty':                     row[11] || 0,
                'Gross Sales':                  row[12] || 0,
                'Scheme Disc':                  row[13] || 0,
                'RS Discount':                  row[14] || 0,
                'TOT Disc':                     row[15] || 0,
                'Total Discount':               row[16] || 0,
                'Taxable Amount':                row[17] || 0,
                'Tax Amount':                    row[18] || 0,
                'Bill Value':                    row[19] || 0,
                'Good Returns Value':           row[20] || 0,
                'Damage-Expiry Shortage Value': row[21] || 0,
                'Final Bill Amount':            row[22] || 0,
                'Delivery Person':              row[25] || '',
            }));

            const validData = dataObjects.filter(r =>
                r['Bill No'] || r['T-Code'] || r['Route'] || r['Party Name']
            );

            if (validData.length === 0) { hideLoader(); alert('No valid data found in Excel file'); return; }

            validateAndPreview(validData, deliveryDate);
        } catch (err) {
            hideLoader();
            alert('Error reading Excel file: ' + err.message);
        }
    };
    reader.onerror = () => { hideLoader(); alert('Error reading file'); };
    reader.readAsArrayBuffer(file);
});

// ── Validate via PHP ────────────────────────────────────────────────────────
function validateAndPreview(data, deliveryDate) {
    const formData = new FormData();
    formData.append('data', JSON.stringify(data));
    formData.append('delivery_date', deliveryDate);

    fetch('validate_import_data.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(result => {
        hideLoader();
        if (result.success) {
            previewData = result.data;
            existingImportId = result.existing_import_id || null;
            skippedDuplicateCount = result.skipped_duplicates || 0;
            displayPreview(result);
        } else {
            alert('Validation error: ' + result.message);
        }
    })
    .catch(err => { hideLoader(); alert('Error: ' + err.message); });
}

// ── Display preview ─────────────────────────────────────────────────────────
function displayPreview(result) {
    activeFilter = 'all';
    updateFilterButtons();
    renderPreviewTable();
    updateCounters();

    // Auto-created customers banner
    const acBanner = document.getElementById('autoCreatedBanner');
    if (result.auto_created_count > 0) {
        document.getElementById('autoCreatedText').textContent =
            `${result.auto_created_count} new customer(s) were automatically added to the database: ${result.auto_created_tcodes.join(', ')}`;
        acBanner.style.display = 'flex';
    } else {
        acBanner.style.display = 'none';
    }

    // Same-date banner
    const sdBanner = document.getElementById('sameDateBanner');
    if (skippedDuplicateCount > 0) {
        document.getElementById('sameDateText').textContent =
            `${skippedDuplicateCount} invoice(s) already exist for this date and were removed. Only new invoices are shown below.`;
        sdBanner.style.display = 'flex';
    } else {
        sdBanner.style.display = 'none';
    }

    // Check for invalid routes — block import
    const invalidCount = previewData.filter(r => !r.route_valid && !r.is_duplicate).length;
    const invBanner = document.getElementById('invalidErrorBanner');
    const importBtn = document.getElementById('importBtn');

    if (invalidCount > 0) {
        const badRoutes = [...new Set(previewData.filter(r => !r.route_valid && r.route_code).map(r => r.route_code))];
        document.getElementById('invalidErrorText').textContent =
            `${invalidCount} record(s) have invalid routes: ${badRoutes.join(', ')}. Import is disabled until all routes are valid.`;
        invBanner.style.display = 'flex';
        importBtn.disabled = true;
    } else {
        invBanner.style.display = 'none';
        const validCount = previewData.filter(r => r.t_code_valid && r.route_valid && !r.is_duplicate).length;
        importBtn.disabled = (validCount === 0);
    }

    // Blacklisted customers banner (only shown when checkbox was ticked)
    const blBanner     = document.getElementById('blacklistedBanner');
    const blBtnFilter  = document.getElementById('filterBlacklistedBtn');
    const blSummaryItem = document.getElementById('blacklistSummaryItem');
    const blColHeaders  = document.querySelectorAll('.bl-col-header');

    if (blacklistCheckEnabled) {
        const blacklistedRows = previewData.filter(r => r.is_blacklisted);
        blSummaryItem.style.display = '';
        blBtnFilter.style.display   = '';
        blColHeaders.forEach(h => h.style.display = '');

        if (blacklistedRows.length > 0) {
            document.getElementById('blacklistedBannerText').textContent =
                `${blacklistedRows.length} invoice(s) belong to blacklisted customers and will be imported with a Cancelled status.`;

            // Build detail list grouped by t_code
            const byTcode = {};
            blacklistedRows.forEach(r => {
                if (!byTcode[r.t_code]) byTcode[r.t_code] = { name: r.customer_name, reason: r.blacklist_reason, date: r.blacklist_date, count: 0, amount: 0 };
                byTcode[r.t_code].count++;
                byTcode[r.t_code].amount += parseFloat(r.final_bill_amount || 0);
            });

            let listHtml = '';
            Object.entries(byTcode).forEach(([tc, info]) => {
                listHtml += `<div style="padding:10px 14px; border-bottom:1px solid #3730a3; display:flex; align-items:flex-start; gap:10px; background:#1e1b4b;">
                    <i class="fa-solid fa-ban" style="color:#818cf8; margin-top:2px; flex-shrink:0;"></i>
                    <div style="flex:1;">
                        <span style="font-family:monospace; color:#c7d2fe; font-weight:700; font-size:12px;">${escH(tc)}</span>
                        <span style="color:#a5b4fc; font-size:12px; margin-left:8px;">${escH(info.name)}</span>
                        <span style="color:#818cf8; font-size:11px; margin-left:8px;">(${info.count} invoice${info.count !== 1 ? 's' : ''}, Rs. ${info.amount.toFixed(2)})</span>
                        ${info.reason ? `<div class="bl-reason-cell" style="color:#a5b4fc; margin-top:4px;"><i class="fa-solid fa-comment-dots"></i> ${escH(info.reason)}</div>` : ''}
                        ${info.date ? `<div class="bl-reason-date" style="color:#6366f1;">${new Date(info.date).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})}</div>` : ''}
                    </div>
                </div>`;
            });
            document.getElementById('blacklistedDetailList').innerHTML = listHtml;
            blBanner.style.display = 'flex';
        } else {
            blBanner.style.display = 'none';
        }
    } else {
        blSummaryItem.style.display = 'none';
        blBtnFilter.style.display   = 'none';
        blBanner.style.display      = 'none';
        blColHeaders.forEach(h => h.style.display = 'none');
    }

    document.getElementById('previewSection').style.display = 'block';
    document.getElementById('previewSection').scrollIntoView({ behavior: 'smooth' });
}

// ── Counters ────────────────────────────────────────────────────────────────
function updateCounters() {
    const validCount      = previewData.filter(r => r.t_code_valid && r.route_valid && !r.is_duplicate && !(blacklistCheckEnabled && r.is_blacklisted)).length;
    const invalidCount    = previewData.filter(r => (!r.t_code_valid || !r.route_valid) && !r.is_duplicate).length;
    const dupCount        = previewData.filter(r => r.is_duplicate).length;
    const blacklistedCount = blacklistCheckEnabled ? previewData.filter(r => r.is_blacklisted).length : 0;

    document.getElementById('totalRecords').textContent       = previewData.length;
    document.getElementById('validRecords').textContent       = validCount;
    document.getElementById('invalidRecords').textContent     = invalidCount;
    document.getElementById('duplicateRecords').textContent   = skippedDuplicateCount;
    document.getElementById('blacklistedRecords').textContent = blacklistedCount;

    // Re-evaluate import button
    const importBtn = document.getElementById('importBtn');
    if (invalidCount > 0) {
        importBtn.disabled = true;
    } else {
        importBtn.disabled = (validCount === 0 && blacklistedCount === 0);
    }
}

// ── Filter ──────────────────────────────────────────────────────────────────
function setFilter(f) {
    activeFilter = f;
    updateFilterButtons();
    renderPreviewTable();

    const counts = {
        all:         previewData.length,
        valid:       previewData.filter(r => r.t_code_valid && r.route_valid && !r.is_duplicate && !(blacklistCheckEnabled && r.is_blacklisted)).length,
        invalid:     previewData.filter(r => (!r.t_code_valid || !r.route_valid) && !r.is_duplicate).length,
        blacklisted: previewData.filter(r => r.is_blacklisted).length,
    };
    document.getElementById('filterNote').textContent =
        f !== 'all' ? `Showing ${counts[f] ?? 0} record(s)` : '';
}

function updateFilterButtons() {
    ['all','valid','invalid','blacklisted'].forEach(f => {
        const key = f.charAt(0).toUpperCase() + f.slice(1);
        const btn = document.getElementById('filter' + key + 'Btn');
        if (btn) btn.classList.toggle('active', activeFilter === f);
    });
}

// ── Render table ────────────────────────────────────────────────────────────
function renderPreviewTable() {
    const tbody = document.getElementById('previewTableBody');
    tbody.innerHTML = '';

    let rows;
    switch (activeFilter) {
        case 'valid':       rows = previewData.filter(r => r.t_code_valid && r.route_valid && !r.is_duplicate && !(blacklistCheckEnabled && r.is_blacklisted)); break;
        case 'invalid':     rows = previewData.filter(r => (!r.t_code_valid || !r.route_valid) && !r.is_duplicate); break;
        case 'blacklisted': rows = previewData.filter(r => r.is_blacklisted); break;
        default:            rows = previewData;
    }

    rows.forEach((record) => {
        const globalIdx   = previewData.indexOf(record);
        const isDup       = record.is_duplicate;
        const isBl        = blacklistCheckEnabled && record.is_blacklisted;
        const isValid     = record.t_code_valid && record.route_valid && !isDup && !isBl;

        const tr = document.createElement('tr');
        if (!isValid && !isDup && !isBl) tr.classList.add('row-invalid');
        if (isBl) tr.classList.add('row-blacklisted');

        // Status badge
        let statusBadge;
        if (isDup) {
            statusBadge = `<span class="badge badge-warning"><i class="fa-solid fa-copy"></i> Duplicate</span>`;
        } else if (isBl) {
            statusBadge = `<span class="badge badge-blacklisted"><i class="fa-solid fa-ban"></i> Blacklisted → Cancelled</span>`;
        } else if (isValid) {
            statusBadge = `<span class="badge badge-success"><i class="fa-solid fa-check"></i> Ready</span>`;
        } else {
            statusBadge = `<span class="badge badge-error"><i class="fa-solid fa-xmark"></i> Invalid Route</span>`;
        }

        // T-Code badge
        let tcodeBadge = '';
        if (record.t_code_valid) {
            const cls = record.auto_created ? 'auto' : 'valid';
            const label = record.auto_created
                ? `<i class="fa-solid fa-plus"></i> ${record.customer_name || 'Auto-created'}`
                : `<i class="fa-solid fa-check"></i> ${record.customer_name || 'Valid'}`;
            tcodeBadge = `<span class="validation-badge ${cls}">${label}</span>`;
        } else {
            tcodeBadge = '<span class="validation-badge invalid"><i class="fa-solid fa-xmark"></i> Invalid</span>';
        }

        // Route badge
        let routeBadge = '';
        if (record.route_valid) {
            routeBadge = `<span class="validation-badge valid"><i class="fa-solid fa-check"></i> ${record.route_name || 'Valid'}</span>`;
        } else {
            routeBadge = '<span class="validation-badge invalid"><i class="fa-solid fa-xmark"></i> Invalid</span>';
        }

        // Blacklist reason cell
        let blReasonCell = '';
        if (blacklistCheckEnabled) {
            if (isBl) {
                const blDate = record.blacklist_date
                    ? new Date(record.blacklist_date).toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'})
                    : '';
                blReasonCell = `<td class="bl-reason-cell" style="display:'';">
                    ${record.blacklist_reason ? `<div><i class="fa-solid fa-comment-dots" style="color:#818cf8;"></i> ${escH(record.blacklist_reason)}</div>` : '<span style="color:#9ca3af;">—</span>'}
                    ${blDate ? `<div class="bl-reason-date">${blDate}</div>` : ''}
                </td>`;
            } else {
                blReasonCell = '<td style="color:#9ca3af; font-size:11px;">—</td>';
            }
        } else {
            blReasonCell = '<td style="display:none;"></td>';
        }

        // Action cell
        let actionCell = `<div class="action-cell">
            <button type="button" class="btn-remove-row" onclick="removeRow(${globalIdx})" title="Remove this row">
                <i class="fa-solid fa-trash"></i> Remove
            </button>
        </div>`;

        tr.innerHTML = `
            <td style="color:#9ca3af; font-size:11px;">${globalIdx + 1}</td>
            <td>${record.sales_person_code || '-'}</td>
            <td>${record.t_code || '-'} ${tcodeBadge}</td>
            <td>${record.route_code || '-'} ${routeBadge}</td>
            <td>${record.bill_no || '-'}</td>
            <td>${record.bill_date || '-'}</td>
            <td>${record.outlet_code || '-'}</td>
            <td>${record.party_name || '-'}</td>
            <td style="text-align:right;">${record.free_qty || 0}</td>
            <td style="text-align:right;">${parseFloat(record.gross_sales || 0).toFixed(2)}</td>
            <td style="text-align:right;">${parseFloat(record.scheme_disc || 0).toFixed(2)}</td>
            <td style="text-align:right;">${parseFloat(record.rs_discount || 0).toFixed(2)}</td>
            <td style="text-align:right;">${parseFloat(record.tot_disc || 0).toFixed(2)}</td>
            <td style="text-align:right;">${parseFloat(record.total_discount || 0).toFixed(2)}</td>
            <td style="text-align:right; color:#7c3aed;">${parseFloat(record.tax_amount || 0).toFixed(2)}</td>
            <td style="text-align:right;">${parseFloat(record.bill_value || 0).toFixed(2)}</td>
            <td style="text-align:right;">${parseFloat(record.good_returns_value || 0).toFixed(2)}</td>
            <td style="text-align:right;">${parseFloat(record.damage_expiry_shortage_value || 0).toFixed(2)}</td>
            <td style="text-align:right; font-weight:600;">${parseFloat(record.final_bill_amount || 0).toFixed(2)}</td>
            <td>${record.delivery_person || '-'}</td>
            <td>${statusBadge}</td>
            ${blReasonCell}
            <td>${actionCell}</td>
        `;
        tbody.appendChild(tr);
    });
}

// ── Remove row ──────────────────────────────────────────────────────────────
function removeRow(globalIdx) {
    if (!confirm('Remove this row from the import list?')) return;
    previewData.splice(globalIdx, 1);
    updateCounters();
    renderPreviewTable();

    // Re-evaluate invalid banner
    const invalidCount = previewData.filter(r => !r.route_valid && !r.is_duplicate).length;
    const invBanner = document.getElementById('invalidErrorBanner');
    if (invalidCount === 0) {
        invBanner.style.display = 'none';
    }
}

// ── Import ──────────────────────────────────────────────────────────────────
const CHUNK_SIZE = 100;

function startImport() {
    // Double-check no invalid routes remain
    const invalidCount = previewData.filter(r => (!r.t_code_valid || !r.route_valid) && !r.is_duplicate).length;
    if (invalidCount > 0) {
        alert('Cannot import — ' + invalidCount + ' record(s) have invalid routes.\nRemove them or fix the routes first.');
        return;
    }

    // All importable rows go into ONE list now — blacklisted customers are
    // no longer split off to a separate destination/table. They import into
    // the same list, just flagged so the backend marks them 'cancelled'.
    const importableRecords = previewData.filter(r =>
        r.t_code_valid && r.route_valid && !r.is_duplicate
    );
    const blacklistedCount = importableRecords.filter(r => blacklistCheckEnabled && r.is_blacklisted).length;
    const normalCount      = importableRecords.length - blacklistedCount;

    const totalImportable = importableRecords.length;
    if (totalImportable === 0) { alert('No importable records found.'); return; }

    let confirmMsg = `Import ${normalCount} normal record(s)`;
    if (blacklistedCount > 0) {
        confirmMsg += ` and ${blacklistedCount} blacklisted customer invoice(s) (these will import into the SAME list but be marked as Cancelled)`;
    }
    confirmMsg += '?';
    if (existingImportId) confirmMsg += `\n\nThis will ADD to the existing import for this delivery date.`;
    if (!confirm(confirmMsg)) return;

    document.getElementById('previewSection').style.display  = 'none';
    document.getElementById('progressSection').style.display = 'block';

    const deliveryDate = document.getElementById('delivery_date').value;
    const filename     = document.getElementById('excel_file').files[0].name;

    // Slim a data array for sending
    function slimRecords(arr) {
        return arr.map(r => ({
            sales_person_code:            r.sales_person_code            || '',
            t_code:                       r.t_code                       || '',
            route_code:                   r.route_code                   || '',
            bill_no:                      r.bill_no                      || '',
            bill_date:                    r.bill_date                    || '',
            outlet_code:                  r.outlet_code                  || '',
            party_name:                   r.party_name                   || '',
            free_qty:                     r.free_qty                     || 0,
            gross_sales:                  r.gross_sales                  || 0,
            scheme_disc:                  r.scheme_disc                  || 0,
            rs_discount:                  r.rs_discount                  || 0,
            tot_disc:                     r.tot_disc                     || 0,
            total_discount:               r.total_discount               || 0,
            taxable_amount:               r.taxable_amount               || 0,
            tax_amount:                   r.tax_amount                   || 0,
            bill_value:                   r.bill_value                   || 0,
            good_returns_value:           r.good_returns_value           || 0,
            damage_expiry_shortage_value: r.damage_expiry_shortage_value || 0,
            final_bill_amount:            r.final_bill_amount            || 0,
            delivery_person:              r.delivery_person              || '',
            customer_name:                r.customer_name                || '',
            route_name:                   r.route_name                   || '',
            blacklist_reason:             r.blacklist_reason             || '',
            blacklist_date:               r.blacklist_date               || '',
            is_blacklisted:               !!(blacklistCheckEnabled && r.is_blacklisted),
            t_code_valid: true,
            route_valid:  true,
        }));
    }

    // Generic chunked sender
    function runChunkedImport(records, totalAllRecords, label, onDone) {
        if (records.length === 0) { onDone(0, 0); return; }

        const slimData = slimRecords(records);
        const chunks   = [];
        for (let i = 0; i < slimData.length; i += CHUNK_SIZE) chunks.push(slimData.slice(i, i + CHUNK_SIZE));

        const totalChunks  = chunks.length;
        let currentChunk   = 0;
        let currentImportId = null;
        let totalImported  = 0;
        let totalFailed    = 0;
        let totalCancelled = 0;

        function sendChunk(idx) {
            const chunk   = chunks[idx];
            const isFirst = idx === 0;
            const isLast  = idx === totalChunks - 1;
            const fd      = new FormData();
            fd.append('data',           JSON.stringify(chunk));
            fd.append('delivery_date',  deliveryDate);
            fd.append('filename',       filename);
            fd.append('chunk_total',    slimData.length);
            fd.append('is_first_chunk', isFirst ? 'true' : 'false');
            fd.append('is_last_chunk',  isLast  ? 'true' : 'false');
            if (currentImportId) fd.append('import_id', currentImportId);
            if (existingImportId && isFirst) fd.append('update_existing_import_id', existingImportId);

            fetch('process_import.php', { method: 'POST', body: fd })
            .then(r => r.text())
            .then(text => {
                let result;
                try {
                    const jt = text.trim();
                    const js = jt.indexOf('{');
                    result = JSON.parse(js > 0 ? jt.substring(js) : jt);
                } catch(e) {
                    showImportError('Server returned invalid response:\n\n' + text.substring(0, 400));
                    return;
                }
                if (!result.success) {
                    showImportError('Import failed on batch ' + (idx + 1) + ' (' + label + '): ' + result.message);
                    return;
                }
                if (isFirst) { currentImportId = result.import_id; importId = result.import_id; }
                totalImported += result.imported;
                totalFailed   += result.failed;
                totalCancelled += (result.cancelled || 0);
                currentChunk++;

                const pct = Math.min(100, Math.round((totalImported / totalAllRecords) * 100));
                document.getElementById('progressFill').style.width    = pct + '%';
                document.getElementById('progressFill').textContent    = pct + '%';
                document.getElementById('progressPercent').textContent = pct + '%';
                document.getElementById('progressStatus').textContent  =
                    `[${label}] Batch ${currentChunk}/${totalChunks} · ${totalImported} sent`;

                if (isLast) {
                    onDone(totalImported, totalFailed, totalCancelled);
                } else {
                    setTimeout(() => sendChunk(idx + 1), 200);
                }
            })
            .catch(err => showImportError('Network error on batch ' + (idx + 1) + ': ' + err.message));
        }

        sendChunk(0);
    }

    const grandTotal = totalImportable;

    document.getElementById('progressStatus').textContent =
        `Preparing to import ${grandTotal} total record(s)…`;

    // Single phase: everything (normal + blacklisted) imports into the same
    // list. Blacklisted rows come back with status='cancelled' server-side.
    runChunkedImport(importableRecords, grandTotal, 'Import', (imp, fail, cancelled) => {
        let doneMsg = `✅ Completed! ${imp} record(s) imported`;
        if (cancelled > 0) doneMsg += ` (${cancelled} marked Cancelled — blacklisted)`;
        if (fail > 0) doneMsg += `, ${fail} failed`;
        document.getElementById('progressStatus').textContent = doneMsg;
        document.getElementById('progressFill').style.width    = '100%';
        document.getElementById('progressFill').textContent    = '100%';
        document.getElementById('progressPercent').textContent = '100%';
        setTimeout(() => window.location.reload(), 2500);
    });
}

function showImportError(msg) {
    alert(msg);
    document.getElementById('progressSection').style.display = 'none';
    document.getElementById('previewSection').style.display  = 'block';
}

function cancelImport() {
    if (confirm('Cancel import?')) {
        document.getElementById('previewSection').style.display = 'none';
        document.getElementById('uploadForm').reset();
        previewData = [];
        existingImportId = null;
        skippedDuplicateCount = 0;
        document.getElementById('blacklistCheckOption').style.display = 'none';
    }
}

function escH(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function showLoader() {
    document.getElementById('uploadBtn').disabled = true;
    document.getElementById('uploadBtn').innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing…';
}
function hideLoader() {
    document.getElementById('uploadBtn').disabled = false;
    document.getElementById('uploadBtn').innerHTML = '<i class="fa-solid fa-upload"></i> Upload & Preview';
}
</script>

<?php include 'footer.php'; ?>