<?php
include 'config.php';
include 'header.php';

// Create secondary invoice imports tracking table
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS secondary_invoice_imports (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    delivery_date DATE NOT NULL,
    filename VARCHAR(255) NOT NULL,
    total_records INT(11) DEFAULT 0,
    imported_records INT(11) DEFAULT 0,
    failed_records INT(11) DEFAULT 0,
    status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
    imported_by INT(11) NULL,
    imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_delivery_date (delivery_date),
    INDEX idx_status (status)
)");

// Create secondary invoice import details table
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS secondary_invoice_import_details (
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
    bill_value DECIMAL(12,2) DEFAULT 0,
    good_returns_value DECIMAL(12,2) DEFAULT 0,
    damage_expiry_shortage_value DECIMAL(12,2) DEFAULT 0,
    final_bill_amount DECIMAL(12,2) DEFAULT 0,
    delivery_person VARCHAR(255) NULL,
    delivery_date DATE NULL,
    t_code_valid TINYINT(1) DEFAULT 0,
    route_valid TINYINT(1) DEFAULT 0,
    customer_name VARCHAR(255) NULL,
    route_name VARCHAR(255) NULL,
    status ENUM('pending','imported','failed') DEFAULT 'pending',
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_import_id (import_id),
    INDEX idx_status (status)
)");

// Migrate: add bill_value column to existing table if missing
$_bv_check = mysqli_query($conn, "SHOW COLUMNS FROM secondary_invoice_import_details LIKE 'bill_value'");
if (!$_bv_check || mysqli_num_rows($_bv_check) === 0) {
    mysqli_query($conn, "ALTER TABLE secondary_invoice_import_details
                         ADD COLUMN bill_value DECIMAL(12,2) DEFAULT 0
                         AFTER total_discount");
}
?>

<div class="page-header">
    <h2 class="page-title">
        <i class="fa-solid fa-receipt" style="color:#7c3aed;"></i> Import Secondary Invoices
    </h2>
    <p class="page-subtitle">Upload Excel file to import secondary invoice data</p>
</div>

<!-- Info Box -->
<div class="info-box">
    <div class="info-icon"><i class="fa-solid fa-info-circle"></i></div>
    <div class="info-content">
        <strong>Column Order (Invoice Wise Sales — Summary Report tab)</strong>
        2: Salesperson Code, 4: T-Code (HUL), 5: Beat/Route, 6: Bill No, 8: Bill Date,
        9: Outlet Code, 10: Party Name, 12: Free Qty, 13: Gross Sales,
        14: Scheme Disc, 15: RS Discount, 16: TOT Disc, 17: Total Discount,
        18: Taxable Amount, <strong style="color:#7c3aed;">19: Bill Value</strong>,
        20: Good Returns Value, 21: Dmg/Expiry Value,
        <strong style="color:#7c3aed;">22: Final Bill Amount</strong>, 25: Delivery Person
    </div>
</div>

<!-- Same-date warning banner (hidden until JS detects existing import) -->
<div id="sameDateWarning" class="warning-banner" style="display:none;">
    <div class="wb-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
    <div class="wb-body">
        <strong>Existing Import Found for This Date</strong>
        <span id="sameDateMsg">
            A completed secondary import already exists for this delivery date.
            Proceeding will <u>delete the old import</u> and replace it with the new data.
            The old data will be automatically <strong>saved to history</strong> before replacement.
        </span>
    </div>
    <a href="secondary_import_history.php" target="_blank" class="btn btn-secondary btn-sm" style="white-space:nowrap;">
        <i class="fa-solid fa-clock-rotate-left"></i> View History
    </a>
</div>

<!-- Step 1: Upload -->
<div class="content-card">
    <h3 class="card-title"><i class="fa-solid fa-upload"></i> Step 1: Upload Excel File</h3>
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
                    <strong>Column Order (Summary Report tab):</strong>
                    2: Salesperson Code, 4: T-Code (HUL), 5: Beat/Route, 6: Bill No, 8: Bill Date,
                    9: Outlet Code, 10: Party Name, 12: Free Qty, 13: Gross Sales,
                    14: Scheme Disc, 15: RS Discount, 16: TOT Disc, 17: Total Discount,
                    <strong>19: Bill Value</strong>, 20: Good Returns Value, 21: Dmg/Expiry Value,
                    <strong>22: Final Bill Amount</strong>, 25: Delivery Person
                </small>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary" id="uploadBtn" style="background:#7c3aed;">
                <i class="fa-solid fa-upload"></i> Upload &amp; Preview
            </button>
        </div>
    </form>
</div>

<!-- Step 2: Preview -->
<div id="previewSection" class="content-card" style="display:none;">
    <h3 class="card-title"><i class="fa-solid fa-eye"></i> Step 2: Preview &amp; Validate Data</h3>

    <!-- Same-date replace notice inside preview -->
    <div id="replaceNotice" style="display:none; background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:12px 16px; margin-bottom:16px; font-size:13px; color:#92400e;">
        <i class="fa-solid fa-rotate"></i>
        <strong>Replace Mode:</strong> This delivery date already has an import.
        Old data will be archived to history and replaced with records below.
    </div>

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

    <!-- Filter toolbar -->
    <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px; flex-wrap:wrap;">
        <button type="button" id="filterInvalidBtn" class="btn btn-secondary" onclick="toggleInvalidFilter()" style="border-color:#ef4444; color:#ef4444;">
            <i class="fa-solid fa-filter"></i> Show Invalid Only
        </button>
        <button type="button" id="filterAllBtn" class="btn btn-secondary" onclick="toggleInvalidFilter()" style="display:none;">
            <i class="fa-solid fa-list"></i> Show All Records
        </button>
        <span id="filterNote" style="font-size:12px; color:#6b7280;"></span>
    </div>

    <div class="table-responsive">
        <table class="data-table" id="previewTable">
            <thead>
                <tr>
                    <th>Col 2: Sales Code</th>
                    <th>Col 4: T-Code</th>
                    <th>Col 5: Route/Beat</th>
                    <th>Col 6: Bill No</th>
                    <th>Col 8: Bill Date</th>
                    <th>Col 9: Outlet Code</th>
                    <th>Col 10: Party Name</th>
                    <th>Col 12: Free Qty</th>
                    <th>Col 13: Gross Sales</th>
                    <th>Col 14: Scheme Disc</th>
                    <th>Col 15: RS Discount</th>
                    <th>Col 16: TOT Disc</th>
                    <th>Col 17: Total Discount</th>
                    <th>Col 19: Bill Value</th>
                    <th>Col 20: Good Returns</th>
                    <th>Col 21: Dmg/Expiry</th>
                    <th>Col 22: Final Bill Amt</th>
                    <th>Col 25: Delivery Person</th>
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
        <button type="button" class="btn btn-primary" id="importBtn" onclick="startImport()" style="background:#7c3aed;">
            <i class="fa-solid fa-file-import"></i> <span id="importBtnLabel">Start Import</span>
        </button>
    </div>
</div>

<!-- Progress Section -->
<div id="progressSection" class="content-card" style="display:none;">
    <h3 class="card-title"><i class="fa-solid fa-spinner fa-spin"></i> Importing Data...</h3>
    <div class="progress-container">
        <div class="progress-bar">
            <div class="progress-fill" id="progressFill" style="background:linear-gradient(90deg,#7c3aed,#a855f7);">0%</div>
        </div>
        <div class="progress-text">
            <span id="progressPercent">0%</span>
            <span id="progressStatus">Preparing...</span>
        </div>
    </div>
</div>

<!-- Quick Link to History -->
<div class="content-card" style="text-align:center; padding:30px;">
    <p style="color:#666; margin-bottom:16px;">
        <i class="fa-solid fa-info-circle"></i> View previously imported files, replacements and archived history
    </p>
    <a href="secondary_import_history.php" class="btn btn-secondary">
        <i class="fa-solid fa-clock-rotate-left"></i> View Import History
    </a>
</div>

<!-- Quick Add Customer Modal -->
<div id="quickAddCustomerModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:10px; padding:28px; width:420px; max-width:95vw; box-shadow:0 10px 40px rgba(0,0,0,0.2);">
        <h3 style="margin:0 0 6px; font-size:16px; font-weight:700; color:#1f2937;">
            <i class="fa-solid fa-user-plus" style="color:#22c55e;"></i> Quick Add Customer
        </h3>
        <p style="font-size:12px; color:#6b7280; margin:0 0 20px;">Save this T-Code to customers table and re-validate.</p>
        <div style="margin-bottom:14px;">
            <label style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:5px;">T-Code</label>
            <input type="text" id="qacTCode" class="form-input" readonly style="background:#f9fafb; font-weight:700;">
        </div>
        <div style="margin-bottom:14px;">
            <label style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:5px;">Party Name (from Excel)</label>
            <input type="text" id="qacPartyName" class="form-input" readonly style="background:#f9fafb; color:#6b7280;">
        </div>
        <div style="margin-bottom:20px;">
            <label style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:5px;">Shop Name <span style="color:#ef4444;">*</span></label>
            <input type="text" id="qacShopName" class="form-input" placeholder="Enter shop name to save">
        </div>
        <div id="qacError" style="display:none; background:#fef2f2; color:#991b1b; border:1px solid #fecaca; border-radius:6px; padding:8px 12px; font-size:12px; margin-bottom:14px;"></div>
        <div style="display:flex; gap:10px; justify-content:flex-end;">
            <button type="button" class="btn btn-secondary" onclick="closeQuickAddModal()">Cancel</button>
            <button type="button" class="btn btn-primary" id="qacSaveBtn" onclick="saveQuickCustomer()" style="background:#22c55e;">
                <i class="fa-solid fa-floppy-disk"></i> Save &amp; Validate
            </button>
        </div>
    </div>
</div>

<!-- Loading Overlay -->
<div id="loadingOverlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9998; align-items:center; justify-content:center; flex-direction:column; gap:16px; color:#fff; font-size:16px; font-weight:600;">
    <i class="fa-solid fa-spinner fa-spin" style="font-size:40px;"></i>
    <span>Processing Excel file...</span>
</div>

<style>
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:20px}
.card-title{font-size:16px;font-weight:600;margin-bottom:16px;color:#1f2937;display:flex;align-items:center;gap:8px}
.info-box{display:flex;align-items:center;gap:16px;padding:16px 20px;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:8px;margin-bottom:24px}
.info-icon{font-size:24px;color:#7c3aed}
.info-content{flex:1;font-size:14px;color:#4c1d95}
.info-content strong{display:block;margin-bottom:4px;color:#7c3aed}
.warning-banner{display:flex;align-items:flex-start;gap:14px;padding:14px 18px;background:#fffbeb;border:1px solid #f59e0b;border-radius:8px;margin-bottom:20px}
.wb-icon{font-size:22px;color:#d97706;padding-top:2px}
.wb-body{flex:1;font-size:13px;color:#92400e}
.wb-body strong{display:block;margin-bottom:4px;font-size:14px;color:#78350f}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
.form-group{margin-bottom:16px}
.form-label{display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:#374151}
.form-input{width:100%;padding:10px 14px;border:1px solid #e5e5e5;border-radius:6px;font-size:14px;font-family:'Inter',sans-serif;box-sizing:border-box}
.form-input:focus{outline:none;border-color:#7c3aed;box-shadow:0 0 0 2px rgba(124,58,237,.1)}
.required-field .form-input{background:#faf5ff}
.required{color:#ef4444}
.form-hint{display:block;font-size:11px;color:#666;margin-top:4px}
.form-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:16px;padding-top:16px;border-top:1px solid #e5e5e5}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;transition:all .3s;font-family:'Inter',sans-serif;text-decoration:none}
.btn-sm{padding:5px 12px;font-size:12px}
.btn-primary{background:#000;color:#fff}
.btn-primary:hover{filter:brightness(1.2)}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}
.btn-secondary:hover{background:#e5e5e5}
.preview-summary{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px;padding:16px;background:#f9fafb;border-radius:8px}
.summary-item{text-align:center}
.summary-label{display:block;font-size:12px;color:#6b7280;margin-bottom:4px}
.summary-value{display:block;font-size:28px;font-weight:700;color:#1f2937}
.summary-value.valid{color:#22c55e}
.summary-value.invalid{color:#ef4444}
.progress-container{margin:24px 0}
.progress-bar{width:100%;height:50px;background:#f0f0f0;border-radius:25px;overflow:hidden;margin-bottom:16px;box-shadow:inset 0 2px 4px rgba(0,0,0,.1)}
.progress-fill{height:100%;width:0%;transition:width .3s ease;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:16px}
.progress-text{display:flex;justify-content:space-between;align-items:center;font-size:14px;color:#666}
#progressPercent{font-weight:700;color:#7c3aed;font-size:20px}
.validation-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:12px;font-size:10px;font-weight:600;margin-left:6px}
.validation-badge.valid{background:#f0fdf4;color:#166534}
.validation-badge.invalid{background:#fef2f2;color:#991b1b}
.table-responsive{overflow-x:auto}
.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table thead{background:#fafafa;border-bottom:2px solid #e5e5e5}
.data-table th{padding:12px;text-align:left;font-weight:600;color:#333;font-size:12px;white-space:nowrap}
.data-table tbody tr{border-bottom:1px solid #f0f0f0}
.data-table tbody tr:hover{background:#fafafa}
.data-table tbody tr.row-invalid{background:#fef2f2}
.data-table td{padding:12px;color:#333}
.badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:600}
.badge-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.badge-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
@media(max-width:768px){.form-row{grid-template-columns:1fr}.preview-summary{grid-template-columns:1fr}}
</style>

<!-- SheetJS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
let previewData     = [];
let importId        = null;
let qacRowIndex     = null;
let hasSameDateImport = false;

function showLoader() { document.getElementById('loadingOverlay').style.display = 'flex'; }
function hideLoader() { document.getElementById('loadingOverlay').style.display = 'none'; }

// ── Handle file upload ────────────────────────────────────────────────────────
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
            const workbook = XLSX.read(new Uint8Array(e.target.result), { type: 'array' });
            const sheet    = workbook.Sheets[workbook.SheetNames[0]];
            const jsonData = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: '' });

            if (jsonData.length < 1) { hideLoader(); alert('Excel file is empty'); return; }

            // Skip first 19 rows — same as loading summary
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
                'Bill Value':                   row[18] || 0,
                'Good Returns Value':           row[19] || 0,
                'Damage-Expiry Shortage Value': row[20] || 0,
                'Final Bill Amount':            row[21] || 0,
                'Delivery Person':              row[24] || '',
            }));

            const validData = dataObjects.filter(row =>
                row['Bill No'] || row['Sales Person Code'] || row['Outlet Code'] || row['Party Name']
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

// ── Validate via validate_import_data.php ────────────────────────────────────
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
            hasSameDateImport = result.has_same_date_import || false;
            displayPreview(result);
        } else {
            alert('Validation error: ' + result.message);
        }
    })
    .catch(err => { hideLoader(); alert('Error: ' + err.message); });
}

// ── Preview / filter ──────────────────────────────────────────────────────────
let showInvalidOnly = false;

function toggleInvalidFilter() {
    showInvalidOnly = !showInvalidOnly;
    document.getElementById('filterInvalidBtn').style.display = showInvalidOnly ? 'none' : '';
    document.getElementById('filterAllBtn').style.display     = showInvalidOnly ? '' : 'none';
    const cnt = previewData.filter(r => !r.t_code_valid || !r.route_valid).length;
    document.getElementById('filterNote').textContent = showInvalidOnly
        ? `Showing ${cnt} invalid record(s) only` : '';
    renderPreviewTable();
}

function displayPreview(result) {
    renderPreviewTable();
    const validCount   = previewData.filter(r => r.t_code_valid && r.route_valid).length;
    const invalidCount = previewData.length - validCount;
    document.getElementById('totalRecords').textContent   = previewData.length;
    document.getElementById('validRecords').textContent   = validCount;
    document.getElementById('invalidRecords').textContent = invalidCount;

    // Show/hide same-date warning banner and replace notice
    if (hasSameDateImport) {
        document.getElementById('sameDateWarning').style.display  = 'flex';
        document.getElementById('replaceNotice').style.display    = 'block';
        document.getElementById('importBtnLabel').textContent     = 'Replace & Import';
    } else {
        document.getElementById('sameDateWarning').style.display  = 'none';
        document.getElementById('replaceNotice').style.display    = 'none';
        document.getElementById('importBtnLabel').textContent     = 'Start Import';
    }

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
        const globalIdx = previewData.indexOf(record);
        const isValid   = record.t_code_valid && record.route_valid;
        const tr        = document.createElement('tr');
        if (!isValid) tr.classList.add('row-invalid');

        tr.innerHTML = `
            <td>${record.sales_person_code || '-'}</td>
            <td>
                ${record.t_code || '-'}
                ${record.t_code_valid
                    ? `<span class="validation-badge valid"><i class="fa-solid fa-check"></i> ${record.customer_name || 'Valid'}</span>`
                    : '<span class="validation-badge invalid"><i class="fa-solid fa-xmark"></i> Invalid</span>'}
            </td>
            <td>
                ${record.route_code || '-'}
                ${record.route_valid
                    ? `<span class="validation-badge valid"><i class="fa-solid fa-check"></i> ${record.route_name || 'Valid'}</span>`
                    : '<span class="validation-badge invalid"><i class="fa-solid fa-xmark"></i> Invalid</span>'}
            </td>
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
            <td style="text-align:right;">${parseFloat(record.bill_value || 0).toFixed(2)}</td>
            <td style="text-align:right;">${parseFloat(record.good_returns_value || 0).toFixed(2)}</td>
            <td style="text-align:right;">${parseFloat(record.damage_expiry_shortage_value || 0).toFixed(2)}</td>
            <td style="text-align:right; font-weight:600;">${parseFloat(record.final_bill_amount || 0).toFixed(2)}</td>
            <td>${record.delivery_person || '-'}</td>
            <td>
                ${isValid
                    ? '<span class="badge badge-success">Ready</span>'
                    : '<span class="badge badge-error">Invalid</span>'}
            </td>
            <td>
                ${!record.t_code_valid && record.t_code
                    ? `<button type="button" class="btn btn-secondary" onclick="openQuickAddModal(${globalIdx})"
                         style="font-size:11px; padding:5px 10px; border-color:#22c55e; color:#166634; white-space:nowrap;">
                           <i class="fa-solid fa-user-plus"></i> Add Customer
                       </button>` : '-'}
            </td>
        `;
        tbody.appendChild(tr);
    });
}

// ── Quick Add Customer ────────────────────────────────────────────────────────
function openQuickAddModal(rowIndex) {
    qacRowIndex = rowIndex;
    const record = previewData[rowIndex];
    document.getElementById('qacTCode').value     = record.t_code     || '';
    document.getElementById('qacPartyName').value = record.party_name || '';
    document.getElementById('qacShopName').value  = record.party_name || '';
    document.getElementById('qacError').style.display = 'none';
    document.getElementById('quickAddCustomerModal').style.display = 'flex';
    setTimeout(() => document.getElementById('qacShopName').focus(), 100);
}

function closeQuickAddModal() {
    document.getElementById('quickAddCustomerModal').style.display = 'none';
    qacRowIndex = null;
}

function saveQuickCustomer() {
    const tCode    = document.getElementById('qacTCode').value.trim();
    const shopName = document.getElementById('qacShopName').value.trim();
    const errEl    = document.getElementById('qacError');
    if (!shopName) { errEl.textContent = 'Shop name is required.'; errEl.style.display = 'block'; return; }

    const btn = document.getElementById('qacSaveBtn');
    btn.disabled  = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
    errEl.style.display = 'none';

    const formData = new FormData();
    formData.append('t_code',    tCode);
    formData.append('shop_name', shopName);

    fetch('quick_add_customer.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(result => {
        btn.disabled  = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save &amp; Validate';
        if (result.success) {
            previewData[qacRowIndex].t_code_valid  = true;
            previewData[qacRowIndex].customer_name = shopName;
            closeQuickAddModal();
            const validCount = previewData.filter(r => r.t_code_valid && r.route_valid).length;
            document.getElementById('validRecords').textContent   = validCount;
            document.getElementById('invalidRecords').textContent = previewData.length - validCount;
            renderPreviewTable();
        } else {
            errEl.textContent   = result.message || 'Failed to save customer.';
            errEl.style.display = 'block';
        }
    })
    .catch(err => {
        btn.disabled  = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save &amp; Validate';
        errEl.textContent   = 'Network error: ' + err.message;
        errEl.style.display = 'block';
    });
}

// ── Start Import → process_secondary_import.php ───────────────────────────────
function startImport() {
    const validData = previewData.filter(r => r.t_code_valid && r.route_valid);
    if (validData.length === 0) { alert('No valid records to import'); return; }

    const confirmMsg = hasSameDateImport
        ? `Replace existing import? ${validData.length} valid records will be imported and the old data archived to history.`
        : `Import ${validData.length} valid records?`;
    if (!confirm(confirmMsg)) return;

    document.getElementById('previewSection').style.display  = 'none';
    document.getElementById('progressSection').style.display = 'block';
    document.getElementById('sameDateWarning').style.display = 'none';

    const slimData = validData.map(r => ({
        sales_person_code:              r.sales_person_code             || '',
        t_code:                         r.t_code                        || '',
        route_code:                     r.route_code                    || '',
        bill_no:                        r.bill_no                       || '',
        bill_date:                      r.bill_date                     || '',
        outlet_code:                    r.outlet_code                   || '',
        party_name:                     r.party_name                    || '',
        free_qty:                       r.free_qty                      || 0,
        gross_sales:                    r.gross_sales                   || 0,
        scheme_disc:                    r.scheme_disc                   || 0,
        rs_discount:                    r.rs_discount                   || 0,
        tot_disc:                       r.tot_disc                      || 0,
        total_discount:                 r.total_discount                || 0,
        bill_value:                     r.bill_value                    || 0,
        good_returns_value:             r.good_returns_value            || 0,
        damage_expiry_shortage_value:   r.damage_expiry_shortage_value  || 0,
        final_bill_amount:              r.final_bill_amount             || 0,
        delivery_person:                r.delivery_person               || '',
        customer_name:                  r.customer_name                 || '',
        route_name:                     r.route_name                    || '',
        t_code_valid:                   true,
        route_valid:                    true
    }));

    const formData = new FormData();
    formData.append('data',          JSON.stringify(slimData));
    formData.append('delivery_date', document.getElementById('delivery_date').value);
    formData.append('filename',      document.getElementById('excel_file').files[0].name);

    fetch('process_secondary_import.php', { method: 'POST', body: formData })
    .then(r => r.text())
    .then(text => {
        let jsonText = text.trim();
        const s = jsonText.indexOf('{');
        if (s > 0) jsonText = jsonText.substring(s);
        let result;
        try { result = JSON.parse(jsonText); } catch(e) {
            alert('Server error:\n' + text.substring(0, 300));
            document.getElementById('progressSection').style.display = 'none';
            document.getElementById('previewSection').style.display  = 'block';
            return;
        }
        if (result.success) {
            document.getElementById('progressFill').style.width    = '100%';
            document.getElementById('progressFill').textContent    = '100%';
            document.getElementById('progressPercent').textContent = '100%';

            let statusMsg = 'Completed! ' + result.imported + ' imported';
            if (result.failed > 0) statusMsg += ', ' + result.failed + ' failed';
            if (result.replaced_count > 0) statusMsg += ' · ' + result.replaced_count + ' previous import(s) archived to history';
            document.getElementById('progressStatus').textContent = statusMsg;

            setTimeout(() => window.location.href = 'secondary_import_history.php', 2200);
        } else {
            alert('Import failed: ' + result.message);
            document.getElementById('progressSection').style.display = 'none';
            document.getElementById('previewSection').style.display  = 'block';
        }
    })
    .catch(err => {
        alert('Network error: ' + err.message);
        document.getElementById('progressSection').style.display = 'none';
        document.getElementById('previewSection').style.display  = 'block';
    });
}

function cancelImport() {
    if (confirm('Cancel import?')) {
        document.getElementById('previewSection').style.display  = 'none';
        document.getElementById('sameDateWarning').style.display = 'none';
        document.getElementById('uploadForm').reset();
        previewData       = [];
        hasSameDateImport = false;
    }
}

// Close modal on backdrop click / Escape
document.getElementById('quickAddCustomerModal').addEventListener('click', function(e) {
    if (e.target === this) closeQuickAddModal();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeQuickAddModal(); });
</script>

<?php include 'footer.php'; ?>