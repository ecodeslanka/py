<?php
include 'config.php';
include 'header.php';

// Create imports tracking table
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS unloading_summary_imports (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    total_records INT(11) DEFAULT 0,
    imported_records INT(11) DEFAULT 0,
    failed_records INT(11) DEFAULT 0,
    status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
    delivery_date DATE NULL,
    imported_by INT(11) NULL,
    imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_delivery_date (delivery_date)
)");

// Create items master table
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS items (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    sku_code VARCHAR(100) NOT NULL UNIQUE,
    sku_desc VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sku_code (sku_code)
)");

// Handle delete import
if (isset($_GET['delete_import'])) {
    $import_id = intval($_GET['delete_import']);
    mysqli_query($conn, "DELETE FROM unloading_summary_import_details WHERE import_id = $import_id");
    if (mysqli_query($conn, "DELETE FROM unloading_summary_imports WHERE id = $import_id")) {
        $success_message = "Import deleted successfully!";
    }
}

// Fetch existing SKU codes from items table for JS preview check
$existing_skus = [];
$sku_result = mysqli_query($conn, "SELECT sku_code FROM items");
if ($sku_result) {
    while ($row = mysqli_fetch_assoc($sku_result)) {
        $existing_skus[] = $row['sku_code'];
    }
}

// Check if any existing import exists for a given date (for overwrite warning)
$existing_import_dates = [];
$dates_res = mysqli_query($conn,
    "SELECT DISTINCT DATE_FORMAT(delivery_date,'%Y-%m-%d') AS dd FROM unloading_summary_imports
     WHERE delivery_date IS NOT NULL AND status='completed'");
if ($dates_res) {
    while ($dr = mysqli_fetch_assoc($dates_res)) {
        $existing_import_dates[] = $dr['dd'];
    }
}
?>

<div class="page-header">
    <h2 class="page-title">
        <i class="fa-solid fa-truck-ramp-box"></i> Import Unloading Summary
    </h2>
    <p class="page-subtitle">Upload Excel file to import unloading summary data (all 37 columns)</p>
</div>

<?php if (isset($success_message)): ?>
<div class="alert alert-success">
    <i class="fa-solid fa-circle-check"></i> <?php echo $success_message; ?>
</div>
<?php endif; ?>

<!-- Info Box -->
<div class="info-box">
    <div class="info-icon"><i class="fa-solid fa-info-circle"></i></div>
    <div class="info-content">
        <strong>Excel Column Mapping (DeliveryPersonReconciliation sheet — all 37 columns captured)</strong>
        Col A: Sr No &nbsp;|&nbsp; Col B: Date &nbsp;|&nbsp; Col C: Del. Person Code &nbsp;|&nbsp;
        Col D: Del. Person Name &nbsp;|&nbsp; Col E: Vehicle &nbsp;|&nbsp; Col F: SKU Code &nbsp;|&nbsp;
        Col G: SKU Desc &nbsp;|&nbsp; Col H: TUR &nbsp;|&nbsp; Col I: MRP &nbsp;|&nbsp; Col J: UPC &nbsp;|&nbsp;
        Col K: Physical Qty Good &nbsp;|&nbsp; Col L: Physical Qty Damage &nbsp;|&nbsp;
        Col M-P: Bill Counts &nbsp;|&nbsp; Col Q-S: Billed Qty Before Del &nbsp;|&nbsp;
        Col T-V: Final Bill Mod &nbsp;|&nbsp; Col W-Z: Good/Damage Return &nbsp;|&nbsp;
        Col AA-AC: Adj Qty Good &nbsp;|&nbsp; Col AD: Adj Qty Damage &nbsp;|&nbsp;
        Col AE-AF: Difference &nbsp;|&nbsp; Col AK: Net Amount
        <br>
        <span style="color:#1e40af;">
            <i class="fa-solid fa-arrows-rotate"></i>
            <strong>Re-uploading the same delivery date will overwrite all previous records for that date.</strong>
        </span>
    </div>
</div>

<!-- Stats Row -->
<div class="stats-row">
    <div class="stat-card">
        <div class="stat-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
        <div class="stat-info">
            <span class="stat-value"><?php echo count($existing_skus); ?></span>
            <span class="stat-label">SKUs in Items DB</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fef3c7;color:#d97706;">
            <i class="fa-solid fa-calendar-days"></i>
        </div>
        <div class="stat-info">
            <span class="stat-value"><?php echo count($existing_import_dates); ?></span>
            <span class="stat-label">Imported Date(s)</span>
        </div>
    </div>
    <div class="stat-card" style="cursor:pointer;" onclick="window.location.href='items.php'">
        <div class="stat-icon" style="background:#f0fdf4;color:#166534;">
            <i class="fa-solid fa-arrow-up-right-from-square"></i>
        </div>
        <div class="stat-info">
            <span class="stat-value" style="font-size:14px;font-weight:600;">View Items</span>
            <span class="stat-label">Manage Items Master</span>
        </div>
    </div>
</div>

<!-- Overwrite Warning Banner (if date already imported) -->
<div id="overwriteWarning" class="overwrite-warning" style="display:none;">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <span id="overwriteMsg">This delivery date already has imported data. Uploading will <strong>overwrite</strong> all records for that date.</span>
</div>

<!-- Upload Form -->
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
                <input type="file" id="excel_file" name="excel_file" class="form-input"
                       accept=".xlsx,.xls" required>
                <small class="form-hint">
                    Upload the Unloading Sheet Excel file (DeliveryPersonReconciliation sheet)
                </small>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary" id="uploadBtn">
                <i class="fa-solid fa-upload"></i> Upload & Preview
            </button>
        </div>
    </form>
</div>

<!-- Preview Section -->
<div id="previewSection" class="content-card" style="display:none;">
    <h3 class="card-title">
        <i class="fa-solid fa-eye"></i> Step 2: Preview Data
    </h3>

    <div class="preview-summary">
        <div class="summary-item">
            <span class="summary-label">Total Records</span>
            <span class="summary-value" id="totalRecords">0</span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Valid Records</span>
            <span class="summary-value valid" id="validRecords">0</span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Skipped</span>
            <span class="summary-value invalid" id="invalidRecords">0</span>
        </div>
        <div class="summary-item">
            <span class="summary-label">New SKUs</span>
            <span class="summary-value" style="color:#2563eb;" id="newSkuCount">0</span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Existing SKUs</span>
            <span class="summary-value" style="color:#d97706;" id="existingSkuCount">0</span>
        </div>
    </div>

    <!-- Legend -->
    <div style="display:flex;gap:16px;margin-bottom:16px;flex-wrap:wrap;">
        <span class="badge badge-new"><i class="fa-solid fa-plus"></i> New SKU — will be added to Items DB</span>
        <span class="badge badge-existing"><i class="fa-solid fa-database"></i> Already in Items DB</span>
        <span class="badge badge-success">Ready to Import</span>
    </div>

    <div class="table-responsive">
        <table class="data-table" id="previewTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Sr No</th>
                    <th>Date</th>
                    <th>Del. Person Code</th>
                    <th>Del. Person Name</th>
                    <th>Vehicle</th>
                    <th>SKU Code</th>
                    <th>SKU Desc</th>
                    <th>TUR</th>
                    <th>MRP</th>
                    <th>UPC</th>
                    <th>Phys.Qty Good</th>
                    <th>Phys.Qty Damage</th>
                    <th>Bill Cnt</th>
                    <th>Posted Bill</th>
                    <th>Return Ref</th>
                    <th>Conf. Return Ref</th>
                    <th>Billed Cases</th>
                    <th>Billed Units</th>
                    <th>Billed Value</th>
                    <th>FB Mod Cases</th>
                    <th>FB Mod Units</th>
                    <th>FB Mod Value</th>
                    <th>GR Entry Cases</th>
                    <th>GR Entry Units</th>
                    <th>GR Conf Cases</th>
                    <th>GR Conf Units</th>
                    <th>DR Entry Cases</th>
                    <th>DR Entry Units</th>
                    <th>DR Conf Cases</th>
                    <th>DR Conf Units</th>
                    <th>Adj Good Cases</th>
                    <th>Adj Good Units</th>
                    <th>Adj Good Values</th>
                    <th>Adj Damage</th>
                    <th>Diff Units</th>
                    <th>Diff Value</th>
                    <th>Net Amount</th>
                    <th>Item DB</th>
                    <th>Status</th>
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

<!-- Progress Section -->
<div id="progressSection" class="content-card" style="display:none;">
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

<!-- Quick Link to History -->
<div class="content-card" style="text-align:center;padding:30px;">
    <p style="color:#666;margin-bottom:16px;">
        <i class="fa-solid fa-info-circle"></i>
        View previously imported files and their status
    </p>
    <a href="unloading_import_history.php" class="btn btn-secondary">
        <i class="fa-solid fa-history"></i> View Import History
    </a>
</div>

<style>
.content-card { background:#ffffff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:20px; }
.card-title { font-size:16px;font-weight:600;margin-bottom:16px;color:#1f2937;display:flex;align-items:center;gap:8px; }
.alert { padding:12px 16px;border-radius:6px;margin-bottom:20px;display:flex;align-items:center;gap:8px;font-size:13px; }
.alert-success { background:#f0fdf4;color:#166534;border:1px solid #bbf7d0; }
.overwrite-warning {
    display:flex;align-items:center;gap:10px;
    padding:14px 18px;background:#fff7ed;border:1px solid #fdba74;
    border-radius:8px;margin-bottom:16px;font-size:13px;color:#c2410c;
}
.info-box { display:flex;align-items:flex-start;gap:16px;padding:16px 20px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;margin-bottom:16px; }
.info-icon { font-size:24px;color:#1e40af;margin-top:2px; }
.info-content { flex:1;font-size:12px;color:#1e3a8a;line-height:1.8; }
.info-content strong { display:block;margin-bottom:4px;color:#1e40af; }
.stats-row { display:flex;gap:16px;margin-bottom:20px;flex-wrap:wrap; }
.stat-card { display:flex;align-items:center;gap:14px;background:#ffffff;border:1px solid #e5e5e5;border-radius:8px;padding:16px 24px;min-width:180px; }
.stat-icon { width:44px;height:44px;background:#eff6ff;color:#1e40af;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px; }
.stat-value { display:block;font-size:26px;font-weight:700;color:#1f2937;line-height:1; }
.stat-label { display:block;font-size:12px;color:#6b7280;margin-top:2px; }
.form-row { display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px; }
.form-group { margin-bottom:16px; }
.form-label { display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:#374151; }
.form-input { width:100%;padding:10px 14px;border:1px solid #e5e5e5;border-radius:6px;font-size:14px;font-family:'Inter',sans-serif;box-sizing:border-box; }
.form-input:focus { outline:none;border-color:#000000;box-shadow:0 0 0 2px rgba(0,0,0,0.05); }
.required-field .form-input { background-color:#fffbeb; }
.required { color:#ef4444; }
.form-hint { display:block;font-size:11px;color:#666666;margin-top:4px; }
.form-actions { display:flex;gap:10px;justify-content:flex-end;margin-top:16px;padding-top:16px;border-top:1px solid #e5e5e5; }
.btn { display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;transition:all 0.3s;font-family:'Inter',sans-serif;text-decoration:none; }
.btn-primary { background:#000000;color:#ffffff; }
.btn-primary:hover { background:#333333; }
.btn-secondary { background:#f5f5f5;color:#333333;border:1px solid #e5e5e5; }
.btn-secondary:hover { background:#e5e5e5; }
.preview-summary { display:grid;grid-template-columns:repeat(5,1fr);gap:16px;margin-bottom:20px;padding:16px;background:#f9fafb;border-radius:8px; }
.summary-item { text-align:center; }
.summary-label { display:block;font-size:12px;color:#6b7280;margin-bottom:4px; }
.summary-value { display:block;font-size:28px;font-weight:700;color:#1f2937; }
.summary-value.valid { color:#22c55e; }
.summary-value.invalid { color:#ef4444; }
.progress-container { margin:24px 0; }
.progress-bar { width:100%;height:50px;background:#f0f0f0;border-radius:25px;overflow:hidden;margin-bottom:16px;box-shadow:inset 0 2px 4px rgba(0,0,0,0.1); }
.progress-fill { height:100%;background:linear-gradient(90deg,#000000 0%,#333333 100%);width:0%;transition:width 0.3s ease;display:flex;align-items:center;justify-content:center;color:#ffffff;font-weight:700;font-size:16px; }
.progress-text { display:flex;justify-content:space-between;align-items:center;font-size:14px;color:#666666; }
#progressPercent { font-weight:700;color:#000000;font-size:20px; }
.table-responsive { overflow-x:auto; }
.data-table { width:100%;border-collapse:collapse;font-size:12px; }
.data-table thead { background:#fafafa;border-bottom:2px solid #e5e5e5; }
.data-table th { padding:10px 8px;text-align:left;font-weight:600;color:#333333;font-size:11px;white-space:nowrap; }
.data-table tbody tr { border-bottom:1px solid #f0f0f0; }
.data-table tbody tr:hover { background:#fafafa; }
.data-table tbody tr.row-existing-sku { background:#fffbeb; }
.data-table tbody tr.row-new-sku { background:#f0fdf4; }
.data-table td { padding:8px;color:#333333;white-space:nowrap; }
.badge { display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:600; }
.badge-success { background:#f0fdf4;color:#166534;border:1px solid #bbf7d0; }
.badge-error   { background:#fef2f2;color:#991b1b;border:1px solid #fecaca; }
.badge-new     { background:#f0fdf4;color:#166534;border:1px solid #bbf7d0; }
.badge-existing{ background:#fffbeb;color:#92400e;border:1px solid #fde68a; }
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
let previewData = [];

const existingSkusInDB     = <?php echo json_encode($existing_skus); ?>;
const existingImportDates  = <?php echo json_encode($existing_import_dates); ?>;

// Show overwrite warning when delivery date is already imported
document.getElementById('delivery_date').addEventListener('change', function() {
    const val = this.value; // YYYY-MM-DD
    const warn = document.getElementById('overwriteWarning');
    if (existingImportDates.includes(val)) {
        document.getElementById('overwriteMsg').innerHTML =
            '⚠️ Delivery date <strong>' + val + '</strong> already has imported data. ' +
            'Uploading will <strong>overwrite</strong> all records for that date.';
        warn.style.display = 'flex';
    } else {
        warn.style.display = 'none';
    }
});

// ── Excel date helper ──────────────────────────────────────────────────────
function excelDateToStr(val) {
    if (!val) return '';
    if (val instanceof Date) {
        const y = val.getFullYear();
        const m = String(val.getMonth() + 1).padStart(2, '0');
        const d = String(val.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }
    if (typeof val === 'string' && val.trim()) return val.trim();
    return '';
}

function fv(v) { return isNaN(parseFloat(v)) ? 0 : parseFloat(v); }
function iv(v) { return isNaN(parseInt(v))   ? 0 : parseInt(v);   }

// ── Upload & Parse ─────────────────────────────────────────────────────────
document.getElementById('uploadForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const deliveryDate = document.getElementById('delivery_date').value;
    const file         = document.getElementById('excel_file').files[0];
    if (!deliveryDate) { alert('Please select a delivery date'); return; }
    if (!file)          { alert('Please select an Excel file');  return; }

    showLoader && showLoader();

    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const data     = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, { type:'array', cellDates:true });
            const sheet    = workbook.Sheets[workbook.SheetNames[0]];
            const jsonData = XLSX.utils.sheet_to_json(sheet, { header:1, defval:'', raw:true });

            if (jsonData.length < 2) {
                hideLoader && hideLoader();
                alert('Excel file is empty or has no data rows');
                return;
            }

            // Row 0 = header; data from row 1 onwards
            // Columns (0-indexed, matching Excel columns A-AK):
            // 0:Sr No  1:Date  2:Del Person Code  3:Del Person Name  4:Vehicle
            // 5:SKU Code  6:SKU Desc  7:TUR  8:MRP  9:UPC
            // 10:Phys Qty Good  11:Phys Qty Damage
            // 12:Bill Count  13:Posted Bill Count  14:Return Ref Count  15:Confirmed Return Ref
            // 16:Billed Cases  17:Billed Units  18:Billed Value
            // 19:FB Mod Cases  20:FB Mod Units  21:FB Mod Value
            // 22:GR Entry Cases  23:GR Entry Units
            // 24:GR Conf Cases   25:GR Conf Units
            // 26:DR Entry Cases  27:DR Entry Units
            // 28:DR Conf Cases   29:DR Conf Units
            // 30:Adj Good Cases  31:Adj Good Units  32:Adj Good Values
            // 33:Adj Damage
            // 34:Diff Units  35:Diff Value  36:Net Amount

            const rows = jsonData.slice(1);
            const dataObjects = rows.map(row => ({
                sr_no:                        String(row[0]  || '').trim(),
                record_date:                  excelDateToStr(row[1]),
                delivery_person_code:         String(row[2]  || '').trim(),
                delivery_person_name:         String(row[3]  || '').trim(),
                vehicle:                      String(row[4]  || '').trim(),
                sku_code:                     String(row[5]  || '').trim(),
                sku_desc:                     String(row[6]  || '').trim(),
                tur:                          fv(row[7]),
                mrp:                          fv(row[8]),
                upc:                          fv(row[9]),
                physical_qty_good:            fv(row[10]),
                physical_qty_damage:          fv(row[11]),
                bill_count:                   iv(row[12]),
                posted_bill_count:            iv(row[13]),
                return_ref_count:             iv(row[14]),
                confirmed_return_ref_count:   iv(row[15]),
                billed_qty_before_del_cases:  fv(row[16]),
                billed_qty_before_del_units:  fv(row[17]),
                billed_qty_before_del_value:  fv(row[18]),
                final_bill_mod_cases:         fv(row[19]),
                final_bill_mod_units:         fv(row[20]),
                final_bill_mod_value:         fv(row[21]),
                good_return_entry_cases:      fv(row[22]),
                good_return_entry_units:      fv(row[23]),
                good_return_confirmed_cases:  fv(row[24]),
                good_return_confirmed_units:  fv(row[25]),
                damage_return_entry_cases:    fv(row[26]),
                damage_return_entry_units:    fv(row[27]),
                damage_return_confirmed_cases:fv(row[28]),
                damage_return_confirmed_units:fv(row[29]),
                adj_qty_good_cases:           fv(row[30]),
                adj_qty_good_units:           fv(row[31]),
                adj_qty_good_values:          fv(row[32]),
                adj_qty_damage:               fv(row[33]),
                difference_units:             fv(row[34]),
                difference_value:             fv(row[35]),
                net_amount:                   fv(row[36]),
            }));

            // Filter: skip completely empty rows
            const validData = dataObjects.filter(r =>
                r.sku_code || r.delivery_person_code || r.delivery_person_name
            );

            if (validData.length === 0) {
                hideLoader && hideLoader();
                alert('No valid data found in Excel file');
                return;
            }

            hideLoader && hideLoader();
            previewData = validData;
            displayPreview();

        } catch (err) {
            hideLoader && hideLoader();
            alert('Error reading Excel file: ' + err.message);
        }
    };
    reader.onerror = () => { hideLoader && hideLoader(); alert('Error reading file'); };
    reader.readAsArrayBuffer(file);
});

// ── Preview ────────────────────────────────────────────────────────────────
function displayPreview() {
    const tbody = document.getElementById('previewTableBody');
    tbody.innerHTML = '';

    let validCount = 0, skippedCount = 0, newSkuCount = 0, existingCount = 0;
    const seenNewSkus = new Set();

    previewData.forEach((r, idx) => {
        const isValid = !!(r.sku_code || r.delivery_person_code);
        if (isValid) validCount++; else skippedCount++;

        const inDB = r.sku_code && existingSkusInDB.includes(r.sku_code);
        if (r.sku_code && !inDB && !seenNewSkus.has(r.sku_code)) {
            newSkuCount++;
            seenNewSkus.add(r.sku_code);
        } else if (r.sku_code && inDB) {
            existingCount++;
        }

        let itemBadge = '', rowClass = '';
        if (!r.sku_code) {
            itemBadge = '<span class="badge" style="background:#f3f4f6;color:#6b7280;border:1px solid #e5e5e5;">No SKU</span>';
        } else if (inDB) {
            itemBadge = '<span class="badge badge-existing"><i class="fa-solid fa-database"></i> In DB</span>';
            rowClass  = 'row-existing-sku';
        } else {
            itemBadge = '<span class="badge badge-new"><i class="fa-solid fa-plus"></i> New</span>';
            rowClass  = 'row-new-sku';
        }

        const tr = document.createElement('tr');
        if (rowClass) tr.className = rowClass;

        const n = (v, d=2) => isNaN(parseFloat(v)) ? '-' : parseFloat(v).toFixed(d);

        tr.innerHTML = `
            <td style="color:#9ca3af;font-size:11px;">${idx+1}</td>
            <td>${r.sr_no||'-'}</td>
            <td>${r.record_date||'-'}</td>
            <td>${r.delivery_person_code||'-'}</td>
            <td>${r.delivery_person_name||'-'}</td>
            <td>${r.vehicle||'-'}</td>
            <td><strong>${r.sku_code||'-'}</strong></td>
            <td>${r.sku_desc||'-'}</td>
            <td style="text-align:right;">${n(r.tur)}</td>
            <td style="text-align:right;">${n(r.mrp)}</td>
            <td style="text-align:right;">${n(r.upc,0)}</td>
            <td style="text-align:right;">${n(r.physical_qty_good)}</td>
            <td style="text-align:right;">${n(r.physical_qty_damage)}</td>
            <td style="text-align:right;">${r.bill_count}</td>
            <td style="text-align:right;">${r.posted_bill_count}</td>
            <td style="text-align:right;">${r.return_ref_count}</td>
            <td style="text-align:right;">${r.confirmed_return_ref_count}</td>
            <td style="text-align:right;">${n(r.billed_qty_before_del_cases)}</td>
            <td style="text-align:right;">${n(r.billed_qty_before_del_units)}</td>
            <td style="text-align:right;">${n(r.billed_qty_before_del_value)}</td>
            <td style="text-align:right;">${n(r.final_bill_mod_cases)}</td>
            <td style="text-align:right;">${n(r.final_bill_mod_units)}</td>
            <td style="text-align:right;">${n(r.final_bill_mod_value)}</td>
            <td style="text-align:right;">${n(r.good_return_entry_cases)}</td>
            <td style="text-align:right;">${n(r.good_return_entry_units)}</td>
            <td style="text-align:right;">${n(r.good_return_confirmed_cases)}</td>
            <td style="text-align:right;">${n(r.good_return_confirmed_units)}</td>
            <td style="text-align:right;">${n(r.damage_return_entry_cases)}</td>
            <td style="text-align:right;">${n(r.damage_return_entry_units)}</td>
            <td style="text-align:right;">${n(r.damage_return_confirmed_cases)}</td>
            <td style="text-align:right;">${n(r.damage_return_confirmed_units)}</td>
            <td style="text-align:right;">${n(r.adj_qty_good_cases)}</td>
            <td style="text-align:right;">${n(r.adj_qty_good_units)}</td>
            <td style="text-align:right;">${n(r.adj_qty_good_values)}</td>
            <td style="text-align:right;">${n(r.adj_qty_damage)}</td>
            <td style="text-align:right;">${n(r.difference_units)}</td>
            <td style="text-align:right;">${n(r.difference_value)}</td>
            <td style="text-align:right;">${n(r.net_amount)}</td>
            <td>${itemBadge}</td>
            <td><span class="badge badge-success">Ready</span></td>
        `;
        tbody.appendChild(tr);
    });

    document.getElementById('totalRecords').textContent    = previewData.length;
    document.getElementById('validRecords').textContent    = validCount;
    document.getElementById('invalidRecords').textContent  = skippedCount;
    document.getElementById('newSkuCount').textContent     = newSkuCount;
    document.getElementById('existingSkuCount').textContent= existingCount;

    document.getElementById('previewSection').style.display = 'block';
    document.getElementById('previewSection').scrollIntoView({ behavior:'smooth' });
}

// ── Import ─────────────────────────────────────────────────────────────────
function startImport() {
    if (previewData.length === 0) { alert('No data to import'); return; }

    const dd = document.getElementById('delivery_date').value;
    const willOverwrite = existingImportDates.includes(dd);
    const msg = willOverwrite
        ? `⚠️ Delivery date ${dd} already has imported data.\n\nAll existing records for this date will be REPLACED.\n\nImport ${previewData.length} records?`
        : `Import ${previewData.length} records for ${dd}?\n\nNew SKUs will be saved to the Items master table automatically.`;

    if (!confirm(msg)) return;

    document.getElementById('previewSection').style.display  = 'none';
    document.getElementById('progressSection').style.display = 'block';

    const formData = new FormData();
    formData.append('data',          JSON.stringify(previewData));
    formData.append('filename',      document.getElementById('excel_file').files[0].name);
    formData.append('delivery_date', dd);

    fetch('process_unloading_import.php', { method:'POST', body:formData })
    .then(r => r.text())
    .then(text => {
        let result;
        let jsonText = text.trim();
        const start = jsonText.indexOf('{');
        if (start > 0) jsonText = jsonText.substring(start);
        try { result = JSON.parse(jsonText); }
        catch (e) {
            alert('Server returned invalid response:\n\n' + text.substring(0, 300));
            document.getElementById('progressSection').style.display = 'none';
            document.getElementById('previewSection').style.display  = 'block';
            return;
        }

        if (result.success) {
            document.getElementById('progressFill').style.width   = '100%';
            document.getElementById('progressFill').textContent   = '100%';
            document.getElementById('progressPercent').textContent = '100%';
            const owNote = result.overwritten > 0 ? ` (overwrote ${result.overwritten} previous import(s))` : '';
            document.getElementById('progressStatus').textContent  =
                `Completed! ${result.imported} imported, ${result.failed} failed. ` +
                `${result.new_items} new SKU(s) added.${owNote}`;
            setTimeout(() => window.location.href = 'unloading_import_history.php', 2500);
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
        document.getElementById('previewSection').style.display = 'none';
        document.getElementById('uploadForm').reset();
        document.getElementById('overwriteWarning').style.display = 'none';
        previewData = [];
    }
}
</script>

<?php include 'footer.php'; ?>