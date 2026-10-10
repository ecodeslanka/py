<?php
/* =========================================================================
   secondary_import_multi.php
   ----------------------------------------------------------------------
   ONE-PAGE, multi-delivery-date Secondary Invoice importer.

   - Reads delivery date directly from the Excel "Delivery Date" column
     (column 26 in the "Summary Report" tab, 0-indexed = 25).
   - Groups rows by delivery date automatically in the browser.
   - Validates every group (T-Code / Route) via AJAX to this SAME file
     (?action=validate).
   - Imports every group (or only the ones you choose) via AJAX to this
     SAME file (?action=import) — looping internally, one date at a time,
     each wrapped in its own DB transaction with the same archive/replace
     behaviour as the original single-date importer.
   - "Quick Add Customer" also posts to this same file (?action=quick_add).

   No other PHP files are required. Adjust the CUSTOMERS / ROUTES lookup
   table + column names in the CONFIG block below to match your schema.
   ========================================================================= */

include 'config.php';
include 'header.php';

/* ====================== CONFIG — adjust to your schema ================= */
// Table + columns used to validate a T-Code and fetch the customer name.
define('CUSTOMERS_TABLE',        'customers');
define('CUSTOMERS_CODE_COL',     't_code');        // column holding the T-Code
define('CUSTOMERS_NAME_COL',     'shop_name');      // column holding display name

// Table + columns used to validate a Route/Beat code and fetch its name.
define('ROUTES_TABLE',           'routes');
define('ROUTES_CODE_COL',        'route_code');
define('ROUTES_NAME_COL',        'route_name');
/* ========================================================================= */

/* ---------------------------------------------------------------------
   Ensure tables exist (same structure as the original single-date tool)
   --------------------------------------------------------------------- */
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

$_bv_check = mysqli_query($conn, "SHOW COLUMNS FROM secondary_invoice_import_details LIKE 'bill_value'");
if (!$_bv_check || mysqli_num_rows($_bv_check) === 0) {
    mysqli_query($conn, "ALTER TABLE secondary_invoice_import_details
                         ADD COLUMN bill_value DECIMAL(12,2) DEFAULT 0
                         AFTER total_discount");
}

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS secondary_invoice_import_history (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    original_import_id INT(11) NOT NULL,
    delivery_date DATE NOT NULL,
    filename VARCHAR(255) NOT NULL,
    total_records INT(11) DEFAULT 0,
    imported_records INT(11) DEFAULT 0,
    failed_records INT(11) DEFAULT 0,
    replaced_by_import_id INT(11) NULL,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_delivery_date (delivery_date),
    INDEX idx_original_import_id (original_import_id)
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS secondary_invoice_import_history_details (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    history_id INT(11) NOT NULL,
    original_import_id INT(11) NOT NULL,
    delivery_date DATE NULL,
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
    customer_name VARCHAR(255) NULL,
    route_name VARCHAR(255) NULL,
    status VARCHAR(20) NULL,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_history_id (history_id),
    INDEX idx_original_import_id (original_import_id),
    INDEX idx_delivery_date (delivery_date)
)");

$_bv_check2 = mysqli_query($conn, "SHOW COLUMNS FROM secondary_invoice_import_history_details LIKE 'bill_value'");
if (!$_bv_check2 || mysqli_num_rows($_bv_check2) === 0) {
    mysqli_query($conn, "ALTER TABLE secondary_invoice_import_history_details
                         ADD COLUMN bill_value DECIMAL(12,2) DEFAULT 0
                         AFTER total_discount");
}

/* =========================================================================
   AJAX ROUTER — must run before any HTML output.
   action=validate   -> validate one date-group's rows, return preview JSON
   action=import     -> import one or many date-groups in one call
   action=quick_add  -> save a new customer T-Code on the fly
   ========================================================================= */
if (isset($_POST['action']) && in_array($_POST['action'], ['validate', 'import', 'quick_add'])) {
    header('Content-Type: application/json; charset=utf-8');
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('max_execution_time', 300);
    ini_set('memory_limit', '256M');

    try {
        if (!$conn) {
            echo json_encode(['success' => false, 'message' => 'Database connection failed']);
            exit;
        }

        /* ----------------------- action=quick_add ------------------------ */
        if ($_POST['action'] === 'quick_add') {
            $t_code    = mysqli_real_escape_string($conn, trim($_POST['t_code']    ?? ''));
            $shop_name = mysqli_real_escape_string($conn, trim($_POST['shop_name'] ?? ''));

            if ($t_code === '' || $shop_name === '') {
                echo json_encode(['success' => false, 'message' => 'T-Code and Shop Name are required']);
                exit;
            }

            $tbl      = CUSTOMERS_TABLE;
            $codeCol  = CUSTOMERS_CODE_COL;
            $nameCol  = CUSTOMERS_NAME_COL;

            $exists = mysqli_query($conn, "SELECT 1 FROM `$tbl` WHERE `$codeCol` = '$t_code' LIMIT 1");
            if ($exists && mysqli_num_rows($exists) > 0) {
                mysqli_query($conn, "UPDATE `$tbl` SET `$nameCol` = '$shop_name' WHERE `$codeCol` = '$t_code'");
            } else {
                mysqli_query($conn, "INSERT INTO `$tbl` (`$codeCol`, `$nameCol`) VALUES ('$t_code', '$shop_name')");
            }

            if (mysqli_errno($conn)) {
                echo json_encode(['success' => false, 'message' => 'DB error: ' . mysqli_error($conn)]);
                exit;
            }
            echo json_encode(['success' => true]);
            exit;
        }

        /* ----------------------- action=validate -------------------------- */
        if ($_POST['action'] === 'validate') {
            $raw = $_POST['data'] ?? '';
            if (empty($raw)) { echo json_encode(['success' => false, 'message' => 'No data field in POST']); exit; }

            $rows = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($rows)) {
                echo json_encode(['success' => false, 'message' => 'JSON error: ' . json_last_error_msg()]);
                exit;
            }

            // Collect distinct codes so we can validate with as few queries as possible
            $tCodes  = [];
            $routes  = [];
            foreach ($rows as $r) {
                if (!empty($r['t_code']))     $tCodes[$r['t_code']] = true;
                if (!empty($r['route_code'])) $routes[$r['route_code']] = true;
            }

            $custLookup  = [];
            $routeLookup = [];

            if (!empty($tCodes)) {
                $tbl = CUSTOMERS_TABLE; $codeCol = CUSTOMERS_CODE_COL; $nameCol = CUSTOMERS_NAME_COL;
                $escaped = array_map(fn($c) => "'" . mysqli_real_escape_string($conn, $c) . "'", array_keys($tCodes));
                $res = mysqli_query($conn, "SELECT `$codeCol` AS code, `$nameCol` AS name FROM `$tbl` WHERE `$codeCol` IN (" . implode(',', $escaped) . ")");
                if ($res) { while ($row = mysqli_fetch_assoc($res)) { $custLookup[$row['code']] = $row['name']; } }
            }

            if (!empty($routes)) {
                $tbl = ROUTES_TABLE; $codeCol = ROUTES_CODE_COL; $nameCol = ROUTES_NAME_COL;
                $escaped = array_map(fn($c) => "'" . mysqli_real_escape_string($conn, $c) . "'", array_keys($routes));
                $res = mysqli_query($conn, "SELECT `$codeCol` AS code, `$nameCol` AS name FROM `$tbl` WHERE `$codeCol` IN (" . implode(',', $escaped) . ")");
                if ($res) { while ($row = mysqli_fetch_assoc($res)) { $routeLookup[$row['code']] = $row['name']; } }
            }

            // Distinct delivery dates already imported (status completed) — used for replace-warning
            $existingDates = [];
            $resD = mysqli_query($conn, "SELECT DISTINCT delivery_date FROM secondary_invoice_imports WHERE status = 'completed'");
            if ($resD) { while ($row = mysqli_fetch_assoc($resD)) { $existingDates[$row['delivery_date']] = true; } }

            $out = [];
            foreach ($rows as $r) {
                $tCode  = $r['t_code']     ?? '';
                $route  = $r['route_code'] ?? '';

                $tValid = $tCode !== '' && isset($custLookup[$tCode]);
                $rValid = $route !== '' && isset($routeLookup[$route]);

                $r['t_code_valid']  = $tValid;
                $r['route_valid']   = $rValid;
                $r['customer_name'] = $tValid ? $custLookup[$tCode] : null;
                $r['route_name']    = $rValid ? $routeLookup[$route] : null;

                $out[] = $r;
            }

            echo json_encode([
                'success'         => true,
                'data'            => $out,
                'existing_dates'  => array_keys($existingDates),
            ]);
            exit;
        }

        /* ------------------------ action=import ---------------------------- */
        if ($_POST['action'] === 'import') {
            // groups = [ { delivery_date: 'YYYY-MM-DD', filename: '...', rows: [...] }, ... ]
            $raw = $_POST['groups'] ?? '';
            if (empty($raw)) { echo json_encode(['success' => false, 'message' => 'No groups field in POST']); exit; }

            $groups = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($groups) || count($groups) === 0) {
                echo json_encode(['success' => false, 'message' => 'JSON error: ' . json_last_error_msg()]);
                exit;
            }

            $results = [];

            foreach ($groups as $group) {
                $delivery_date = $group['delivery_date'] ?? '';
                $filename      = $group['filename']      ?? 'unknown.xlsx';
                $rowsRaw       = $group['rows']           ?? [];

                if (empty($delivery_date) || !is_array($rowsRaw) || count($rowsRaw) === 0) {
                    $results[] = ['delivery_date' => $delivery_date, 'success' => false, 'message' => 'Missing date or rows'];
                    continue;
                }

                $valid_data = array_values(array_filter($rowsRaw, function ($r) {
                    if (is_object($r)) $r = (array) $r;
                    return !empty($r['t_code_valid']) && !empty($r['route_valid']);
                }));

                $total_records = count($valid_data);
                if ($total_records === 0) {
                    $results[] = ['delivery_date' => $delivery_date, 'success' => false, 'message' => 'No valid records for this date'];
                    continue;
                }

                $dd = mysqli_real_escape_string($conn, $delivery_date);
                $fn = mysqli_real_escape_string($conn, $filename);

                mysqli_begin_transaction($conn);

                // Step 1: archive existing completed import(s) for this date
                $archived_count = 0;
                $existing_res = mysqli_query($conn,
                    "SELECT * FROM secondary_invoice_imports WHERE delivery_date = '$dd' AND status = 'completed' ORDER BY id ASC");
                $existing_imports = [];
                if ($existing_res) { while ($erow = mysqli_fetch_assoc($existing_res)) { $existing_imports[] = $erow; } }

                $abort = false;
                foreach ($existing_imports as $old_import) {
                    $old_id = intval($old_import['id']);

                    $arch_ins = mysqli_query($conn,
                        "INSERT INTO secondary_invoice_import_history
                            (original_import_id, delivery_date, filename, total_records, imported_records, failed_records, replaced_by_import_id)
                         VALUES
                            ($old_id,
                             '" . mysqli_real_escape_string($conn, $old_import['delivery_date']) . "',
                             '" . mysqli_real_escape_string($conn, $old_import['filename']) . "',
                             " . intval($old_import['total_records']) . ",
                             " . intval($old_import['imported_records']) . ",
                             " . intval($old_import['failed_records']) . ",
                             NULL)");

                    if (!$arch_ins) {
                        mysqli_rollback($conn);
                        $results[] = ['delivery_date' => $delivery_date, 'success' => false, 'message' => 'Failed to archive import header: ' . mysqli_error($conn)];
                        $abort = true;
                        break;
                    }
                    $history_id = mysqli_insert_id($conn);

                    $det_res = mysqli_query($conn, "SELECT * FROM secondary_invoice_import_details WHERE import_id = $old_id");
                    if ($det_res) {
                        while ($det = mysqli_fetch_assoc($det_res)) {
                            $bill_date_arch = !empty($det['bill_date']) ? "'" . mysqli_real_escape_string($conn, $det['bill_date']) . "'" : 'NULL';
                            $del_date_arch  = !empty($det['delivery_date']) ? "'" . mysqli_real_escape_string($conn, $det['delivery_date']) . "'" : 'NULL';

                            mysqli_query($conn,
                                "INSERT INTO secondary_invoice_import_history_details
                                    (history_id, original_import_id, delivery_date,
                                     sales_person_code, t_code, route_code, bill_no,
                                     bill_date, outlet_code, party_name,
                                     free_qty, gross_sales, scheme_disc, rs_discount,
                                     tot_disc, total_discount, bill_value,
                                     good_returns_value, damage_expiry_shortage_value, final_bill_amount,
                                     delivery_person, customer_name, route_name, status)
                                 VALUES
                                    ($history_id, $old_id, $del_date_arch,
                                     '" . mysqli_real_escape_string($conn, $det['sales_person_code'] ?? '') . "',
                                     '" . mysqli_real_escape_string($conn, $det['t_code']            ?? '') . "',
                                     '" . mysqli_real_escape_string($conn, $det['route_code']        ?? '') . "',
                                     '" . mysqli_real_escape_string($conn, $det['bill_no']           ?? '') . "',
                                     $bill_date_arch,
                                     '" . mysqli_real_escape_string($conn, $det['outlet_code']       ?? '') . "',
                                     '" . mysqli_real_escape_string($conn, $det['party_name']        ?? '') . "',
                                     " . floatval($det['free_qty']                     ?? 0) . ",
                                     " . floatval($det['gross_sales']                  ?? 0) . ",
                                     " . floatval($det['scheme_disc']                  ?? 0) . ",
                                     " . floatval($det['rs_discount']                  ?? 0) . ",
                                     " . floatval($det['tot_disc']                     ?? 0) . ",
                                     " . floatval($det['total_discount']               ?? 0) . ",
                                     " . floatval($det['bill_value']                   ?? 0) . ",
                                     " . floatval($det['good_returns_value']           ?? 0) . ",
                                     " . floatval($det['damage_expiry_shortage_value'] ?? 0) . ",
                                     " . floatval($det['final_bill_amount']            ?? 0) . ",
                                     '" . mysqli_real_escape_string($conn, $det['delivery_person'] ?? '') . "',
                                     '" . mysqli_real_escape_string($conn, $det['customer_name']   ?? '') . "',
                                     '" . mysqli_real_escape_string($conn, $det['route_name']      ?? '') . "',
                                     '" . mysqli_real_escape_string($conn, $det['status']          ?? '') . "')");
                            $archived_count++;
                        }
                    }

                    mysqli_query($conn, "DELETE FROM secondary_invoice_import_details WHERE import_id = $old_id");
                    mysqli_query($conn, "DELETE FROM secondary_invoice_imports WHERE id = $old_id");
                }
                if ($abort) continue;

                // Step 2: create new import header
                $new_ins = mysqli_query($conn,
                    "INSERT INTO secondary_invoice_imports (delivery_date, filename, total_records, imported_records, failed_records, status)
                     VALUES ('$dd', '$fn', $total_records, 0, 0, 'processing')");

                if (!$new_ins) {
                    mysqli_rollback($conn);
                    $results[] = ['delivery_date' => $delivery_date, 'success' => false, 'message' => 'DB error creating import: ' . mysqli_error($conn)];
                    continue;
                }
                $import_id = mysqli_insert_id($conn);

                if (!empty($existing_imports)) {
                    mysqli_query($conn,
                        "UPDATE secondary_invoice_import_history SET replaced_by_import_id = $import_id
                         WHERE delivery_date = '$dd' AND replaced_by_import_id IS NULL");
                }

                // Step 3: insert new detail rows
                $imported = 0;
                $failed   = 0;

                foreach ($valid_data as $rec) {
                    if (is_object($rec)) $rec = (array) $rec;

                    $sales_code      = mysqli_real_escape_string($conn, $rec['sales_person_code'] ?? '');
                    $t_code          = mysqli_real_escape_string($conn, $rec['t_code'] ?? '');
                    $route_code      = mysqli_real_escape_string($conn, $rec['route_code'] ?? '');
                    $bill_no         = mysqli_real_escape_string($conn, $rec['bill_no'] ?? '');
                    $bill_date       = mysqli_real_escape_string($conn, $rec['bill_date'] ?? '');
                    $outlet_code     = mysqli_real_escape_string($conn, $rec['outlet_code'] ?? '');
                    $party_name      = mysqli_real_escape_string($conn, $rec['party_name'] ?? '');
                    $free_qty        = floatval($rec['free_qty'] ?? 0);
                    $gross_sales     = floatval($rec['gross_sales'] ?? 0);
                    $scheme_disc     = floatval($rec['scheme_disc'] ?? 0);
                    $rs_discount     = floatval($rec['rs_discount'] ?? 0);
                    $tot_disc        = floatval($rec['tot_disc'] ?? 0);
                    $total_discount  = floatval($rec['total_discount'] ?? 0);
                    $bill_value      = floatval($rec['bill_value'] ?? 0);
                    $good_returns    = floatval($rec['good_returns_value'] ?? 0);
                    $dmg_expiry      = floatval($rec['damage_expiry_shortage_value'] ?? 0);
                    $amount          = floatval($rec['final_bill_amount'] ?? 0);
                    $delivery_person = mysqli_real_escape_string($conn, $rec['delivery_person'] ?? '');
                    $cust_name       = mysqli_real_escape_string($conn, $rec['customer_name'] ?? '');
                    $route_name      = mysqli_real_escape_string($conn, $rec['route_name'] ?? '');
                    $bill_date_val   = !empty($bill_date) ? "'$bill_date'" : 'NULL';

                    $ins = mysqli_query($conn,
                        "INSERT INTO secondary_invoice_import_details
                            (import_id, sales_person_code, t_code, route_code, bill_no, bill_date,
                             outlet_code, party_name, free_qty, gross_sales, scheme_disc, rs_discount,
                             tot_disc, total_discount, bill_value, good_returns_value, damage_expiry_shortage_value,
                             final_bill_amount, delivery_person, delivery_date, t_code_valid, route_valid,
                             customer_name, route_name, status)
                         VALUES
                            ($import_id, '$sales_code', '$t_code', '$route_code', '$bill_no', $bill_date_val,
                             '$outlet_code', '$party_name', $free_qty, $gross_sales, $scheme_disc, $rs_discount,
                             $tot_disc, $total_discount, $bill_value, $good_returns, $dmg_expiry,
                             $amount, '$delivery_person', '$dd', 1, 1,
                             '$cust_name', '$route_name', 'imported')");

                    if ($ins) { $imported++; } else { $failed++; }
                }

                mysqli_query($conn,
                    "UPDATE secondary_invoice_imports SET imported_records = $imported, failed_records = $failed, status = 'completed'
                     WHERE id = $import_id");

                mysqli_commit($conn);

                $results[] = [
                    'delivery_date'  => $delivery_date,
                    'success'        => true,
                    'import_id'      => $import_id,
                    'total_records'  => $total_records,
                    'imported'       => $imported,
                    'failed'         => $failed,
                    'replaced_count' => count($existing_imports),
                    'archived_rows'  => $archived_count,
                ];
            }

            $overallSuccess = !empty(array_filter($results, fn($r) => $r['success']));
            echo json_encode(['success' => $overallSuccess, 'results' => $results]);
            exit;
        }

    } catch (Exception $e) {
        if (isset($conn)) mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
        exit;
    } catch (Error $e) {
        if (isset($conn)) mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => 'PHP Error: ' . $e->getMessage()]);
        exit;
    }
}
/* ======================= END AJAX ROUTER ======================= */
?>

<div class="page-header">
    <h2 class="page-title">
        <i class="fa-solid fa-receipt" style="color:#7c3aed;"></i> Import Secondary Invoices — Multi Date
    </h2>
    <p class="page-subtitle">Upload one Excel file covering any number of delivery dates — they're detected and imported automatically</p>
</div>

<div class="info-box">
    <div class="info-icon"><i class="fa-solid fa-info-circle"></i></div>
    <div class="info-content">
        <strong>Column Order (Invoice Wise Sales — Summary Report tab)</strong>
        2: Salesperson Code, 4: T-Code (HUL), 5: Beat/Route, 6: Bill No, 8: Bill Date,
        9: Outlet Code, 10: Party Name, 12: Free Qty, 13: Gross Sales,
        14: Scheme Disc, 15: RS Discount, 16: TOT Disc, 17: Total Discount,
        18: Taxable Amount, <strong style="color:#7c3aed;">19: Bill Value</strong>,
        20: Good Returns Value, 21: Dmg/Expiry Value,
        <strong style="color:#7c3aed;">22: Final Bill Amount</strong>, 25: Delivery Person,
        <strong style="color:#7c3aed;">26: Delivery Date</strong>
        <br><span style="font-weight:600;">Delivery Date is read straight from the file — every distinct date found becomes its own import, automatically.</span>
    </div>
</div>

<!-- Step 1: Upload -->
<div class="content-card">
    <h3 class="card-title"><i class="fa-solid fa-upload"></i> Step 1: Upload Excel File</h3>
    <form id="uploadForm" enctype="multipart/form-data">
        <div class="form-group required-field">
            <label class="form-label">Excel File <span class="required">*</span></label>
            <input type="file" id="excel_file" name="excel_file" class="form-input" accept=".xlsx,.xls" required>
            <small class="form-hint">
                No need to pick a delivery date — every row's own Delivery Date (column 26) is used automatically,
                and rows are grouped into one import per date.
            </small>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary" id="uploadBtn" style="background:#7c3aed;">
                <i class="fa-solid fa-upload"></i> Upload &amp; Detect Dates
            </button>
        </div>
    </form>
</div>

<!-- Step 2: Date groups overview -->
<div id="groupsSection" class="content-card" style="display:none;">
    <h3 class="card-title"><i class="fa-solid fa-calendar-days"></i> Step 2: Delivery Dates Found</h3>
    <p style="font-size:13px; color:#6b7280; margin:-6px 0 16px;">
        Select which dates to import (all selected by default). Click a date to expand and review its records.
    </p>

    <div class="preview-summary" style="grid-template-columns:repeat(4,1fr);">
        <div class="summary-item">
            <span class="summary-label">Dates Found</span>
            <span class="summary-value" id="totalDates">0</span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Total Records</span>
            <span class="summary-value" id="totalRecordsAll">0</span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Valid Records</span>
            <span class="summary-value valid" id="validRecordsAll">0</span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Invalid Records</span>
            <span class="summary-value invalid" id="invalidRecordsAll">0</span>
        </div>
    </div>

    <div style="display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap;">
        <button type="button" class="btn btn-secondary" onclick="selectAllDates(true)">
            <i class="fa-solid fa-square-check"></i> Select All
        </button>
        <button type="button" class="btn btn-secondary" onclick="selectAllDates(false)">
            <i class="fa-solid fa-square"></i> Deselect All
        </button>
    </div>

    <div id="dateGroupsList"></div>

    <div class="form-actions">
        <button type="button" class="btn btn-secondary" onclick="cancelImport()">
            <i class="fa-solid fa-xmark"></i> Cancel
        </button>
        <button type="button" class="btn btn-primary" id="importBtn" onclick="startImportAll()" style="background:#7c3aed;">
            <i class="fa-solid fa-file-import"></i> <span id="importBtnLabel">Import Selected Dates</span>
        </button>
    </div>
</div>

<!-- Progress -->
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
    <div id="resultsList" style="margin-top:16px;"></div>
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
    <span id="loadingMsg">Processing Excel file...</span>
</div>

<style>
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:20px}
.card-title{font-size:16px;font-weight:600;margin-bottom:16px;color:#1f2937;display:flex;align-items:center;gap:8px}
.info-box{display:flex;align-items:center;gap:16px;padding:16px 20px;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:8px;margin-bottom:24px}
.info-icon{font-size:24px;color:#7c3aed}
.info-content{flex:1;font-size:14px;color:#4c1d95}
.info-content strong{display:block;margin-bottom:4px;color:#7c3aed}
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
.preview-summary{display:grid;gap:16px;margin-bottom:24px;padding:16px;background:#f9fafb;border-radius:8px}
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
.data-table th{padding:10px;text-align:left;font-weight:600;color:#333;font-size:11px;white-space:nowrap}
.data-table tbody tr{border-bottom:1px solid #f0f0f0}
.data-table tbody tr:hover{background:#fafafa}
.data-table tbody tr.row-invalid{background:#fef2f2}
.data-table td{padding:10px;color:#333;font-size:12px}
.badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:600}
.badge-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.badge-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.badge-warning{background:#fffbeb;color:#92400e;border:1px solid #fde68a}

/* Date group cards */
.date-group{border:1px solid #e5e5e5;border-radius:8px;margin-bottom:12px;overflow:hidden}
.date-group-header{display:flex;align-items:center;gap:12px;padding:14px 16px;background:#fafafa;cursor:pointer;user-select:none}
.date-group-header:hover{background:#f3f3f3}
.date-group-header .chk{flex-shrink:0}
.date-group-header .dg-date{font-weight:700;font-size:14px;color:#1f2937;min-width:110px}
.date-group-header .dg-stats{display:flex;gap:14px;font-size:12px;color:#6b7280;flex:1}
.date-group-header .dg-stats b{color:#1f2937}
.date-group-header .chevron{transition:transform .2s;color:#9ca3af}
.date-group.expanded .chevron{transform:rotate(90deg)}
.date-group-body{display:none;padding:0 16px 16px;}
.date-group.expanded .date-group-body{display:block;}
.date-group.replace-mode .date-group-header{background:#fffbeb;border-bottom:1px solid #fde68a}
.replace-tag{font-size:10px;font-weight:700;color:#92400e;background:#fef3c7;border:1px solid #fde68a;padding:2px 8px;border-radius:10px;text-transform:uppercase;letter-spacing:.03em}
.result-row{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:6px;margin-bottom:8px;font-size:13px}
.result-row.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.result-row.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
@media(max-width:768px){.preview-summary{grid-template-columns:1fr 1fr !important}.date-group-header{flex-wrap:wrap}}
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
let dateGroups   = {};   // { 'YYYY-MM-DD': { rows: [...], selected: true, expanded: false } }
let currentFile  = null;
let qacRef       = null; // { date, rowIndex }

function showLoader(msg) { document.getElementById('loadingMsg').textContent = msg || 'Processing Excel file...'; document.getElementById('loadingOverlay').style.display = 'flex'; }
function hideLoader() { document.getElementById('loadingOverlay').style.display = 'none'; }

function excelDateToISO(val) {
    if (val === null || val === undefined || val === '') return '';
    if (val instanceof Date) {
        return val.toISOString().slice(0, 10);
    }
    if (typeof val === 'number') {
        // Excel serial date -> JS date
        const d = XLSX.SSF.parse_date_code(val);
        if (!d) return '';
        const mm = String(d.m).padStart(2, '0');
        const dd = String(d.d).padStart(2, '0');
        return `${d.y}-${mm}-${dd}`;
    }
    // Try parsing common string formats (dd/mm/yyyy or yyyy-mm-dd)
    const s = String(val).trim();
    let m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (m) return `${m[1]}-${m[2]}-${m[3]}`;
    m = s.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/);
    if (m) return `${m[3]}-${String(m[1]).padStart(2,'0')}-${String(m[2]).padStart(2,'0')}`;
    return '';
}

function billDateToISO(val) { return excelDateToISO(val); }

document.getElementById('uploadForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const file = document.getElementById('excel_file').files[0];
    if (!file) { alert('Please select an Excel file'); return; }
    currentFile = file;

    showLoader('Reading Excel file...');

    const reader = new FileReader();
    reader.onload = function (e) {
        try {
            const workbook = XLSX.read(new Uint8Array(e.target.result), { type: 'array' });
            const sheet    = workbook.Sheets[workbook.SheetNames[0]];
            const jsonData = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: '', raw: true });

            if (jsonData.length < 1) { hideLoader(); alert('Excel file is empty'); return; }

            const rows = jsonData.slice(19); // skip header/title rows, same as original

            const dataObjects = rows.map(row => ({
                sales_person_code:            row[1]  || '',
                t_code:                       row[3]  || '',
                route_code:                   row[4]  || '',
                bill_no:                      row[5]  || '',
                bill_date:                    billDateToISO(row[7]),
                outlet_code:                  row[8]  || '',
                party_name:                   row[9]  || '',
                free_qty:                     row[11] || 0,
                gross_sales:                  row[12] || 0,
                scheme_disc:                  row[13] || 0,
                rs_discount:                  row[14] || 0,
                tot_disc:                     row[15] || 0,
                total_discount:               row[16] || 0,
                bill_value:                   row[18] || 0,
                good_returns_value:           row[19] || 0,
                damage_expiry_shortage_value: row[20] || 0,
                final_bill_amount:            row[21] || 0,
                delivery_person:              row[24] || '',
                delivery_date_raw:            row[25],
            }));

            const validData = dataObjects.filter(row =>
                row.bill_no || row.sales_person_code || row.outlet_code || row.party_name
            );

            if (validData.length === 0) { hideLoader(); alert('No valid data found in Excel file'); return; }

            // Group by delivery date (column 26)
            const groups = {};
            const noDate = [];
            validData.forEach(row => {
                const iso = excelDateToISO(row.delivery_date_raw);
                if (!iso) { noDate.push(row); return; }
                if (!groups[iso]) groups[iso] = [];
                groups[iso].push(row);
            });

            if (Object.keys(groups).length === 0) {
                hideLoader();
                alert('No rows had a readable Delivery Date (column 26). Cannot group by date.');
                return;
            }

            if (noDate.length > 0) {
                console.warn(noDate.length + ' row(s) had no readable Delivery Date and were skipped.');
            }

            showLoader('Validating records...');
            validateAllGroups(groups);
        } catch (err) {
            hideLoader();
            alert('Error reading Excel file: ' + err.message);
        }
    };
    reader.onerror = () => { hideLoader(); alert('Error reading file'); };
    reader.readAsArrayBuffer(file);
});

function validateAllGroups(groups) {
    const dates = Object.keys(groups);
    let allRows = [];
    dates.forEach(d => { groups[d].forEach(r => { r._date = d; allRows.push(r); }); });

    const formData = new FormData();
    formData.append('action', 'validate');
    formData.append('data', JSON.stringify(allRows));

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(result => {
            hideLoader();
            if (!result.success) { alert('Validation error: ' + (result.message || 'Unknown error')); return; }

            dateGroups = {};
            const existingDates = new Set(result.existing_dates || []);
            result.data.forEach(row => {
                const d = row._date;
                if (!dateGroups[d]) dateGroups[d] = { rows: [], selected: true, expanded: false, isReplace: existingDates.has(d) };
                dateGroups[d].rows.push(row);
            });

            renderDateGroups();
            document.getElementById('groupsSection').style.display = 'block';
            document.getElementById('groupsSection').scrollIntoView({ behavior: 'smooth' });
        })
        .catch(err => { hideLoader(); alert('Network error: ' + err.message); });
}

function renderDateGroups() {
    const dates = Object.keys(dateGroups).sort();
    const container = document.getElementById('dateGroupsList');
    container.innerHTML = '';

    let totalAll = 0, validAll = 0;

    dates.forEach(date => {
        const g = dateGroups[date];
        const valid = g.rows.filter(r => r.t_code_valid && r.route_valid).length;
        const invalid = g.rows.length - valid;
        totalAll += g.rows.length;
        validAll += valid;

        const div = document.createElement('div');
        div.className = 'date-group' + (g.expanded ? ' expanded' : '') + (g.isReplace ? ' replace-mode' : '');
        div.id = 'group-' + date;

        div.innerHTML = `
            <div class="date-group-header" onclick="toggleGroupExpand('${date}', event)">
                <input type="checkbox" class="chk" ${g.selected ? 'checked' : ''} onclick="event.stopPropagation(); toggleGroupSelect('${date}', this.checked)">
                <span class="dg-date"><i class="fa-solid fa-calendar"></i> ${formatDateDisplay(date)}</span>
                <span class="dg-stats">
                    <span><b>${g.rows.length}</b> records</span>
                    <span style="color:#22c55e;"><b>${valid}</b> valid</span>
                    <span style="color:#ef4444;"><b>${invalid}</b> invalid</span>
                </span>
                ${g.isReplace ? '<span class="replace-tag"><i class="fa-solid fa-rotate"></i> Will Replace Existing</span>' : ''}
                <i class="fa-solid fa-chevron-right chevron"></i>
            </div>
            <div class="date-group-body">
                ${g.isReplace ? `<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:10px 14px;margin:12px 0;font-size:12px;color:#92400e;">
                    <i class="fa-solid fa-triangle-exclamation"></i> A completed import already exists for this date. Importing will archive the old data to history and replace it.
                </div>` : ''}
                <div class="table-responsive">
                    <table class="data-table">
                        <thead><tr>
                            <th>Sales Code</th><th>T-Code</th><th>Route</th><th>Bill No</th><th>Bill Date</th>
                            <th>Outlet</th><th>Party Name</th><th>Free Qty</th><th>Gross Sales</th>
                            <th>Bill Value</th><th>Final Amt</th><th>Delivery Person</th><th>Status</th><th>Action</th>
                        </tr></thead>
                        <tbody>${renderGroupRows(date)}</tbody>
                    </table>
                </div>
            </div>
        `;
        container.appendChild(div);
    });

    document.getElementById('totalDates').textContent = dates.length;
    document.getElementById('totalRecordsAll').textContent = totalAll;
    document.getElementById('validRecordsAll').textContent = validAll;
    document.getElementById('invalidRecordsAll').textContent = totalAll - validAll;
}

function renderGroupRows(date) {
    const g = dateGroups[date];
    return g.rows.map((r, idx) => {
        const isValid = r.t_code_valid && r.route_valid;
        return `
        <tr class="${isValid ? '' : 'row-invalid'}">
            <td>${r.sales_person_code || '-'}</td>
            <td>${r.t_code || '-'} ${r.t_code_valid
                ? `<span class="validation-badge valid"><i class="fa-solid fa-check"></i> ${escapeHtml(r.customer_name || 'Valid')}</span>`
                : '<span class="validation-badge invalid"><i class="fa-solid fa-xmark"></i> Invalid</span>'}</td>
            <td>${r.route_code || '-'} ${r.route_valid
                ? `<span class="validation-badge valid"><i class="fa-solid fa-check"></i> ${escapeHtml(r.route_name || 'Valid')}</span>`
                : '<span class="validation-badge invalid"><i class="fa-solid fa-xmark"></i> Invalid</span>'}</td>
            <td>${r.bill_no || '-'}</td>
            <td>${r.bill_date || '-'}</td>
            <td>${r.outlet_code || '-'}</td>
            <td>${escapeHtml(r.party_name || '-')}</td>
            <td style="text-align:right;">${r.free_qty || 0}</td>
            <td style="text-align:right;">${parseFloat(r.gross_sales || 0).toFixed(2)}</td>
            <td style="text-align:right;">${parseFloat(r.bill_value || 0).toFixed(2)}</td>
            <td style="text-align:right; font-weight:600;">${parseFloat(r.final_bill_amount || 0).toFixed(2)}</td>
            <td>${r.delivery_person || '-'}</td>
            <td>${isValid ? '<span class="badge badge-success">Ready</span>' : '<span class="badge badge-error">Invalid</span>'}</td>
            <td>${!r.t_code_valid && r.t_code
                ? `<button type="button" class="btn btn-secondary" onclick="event.stopPropagation(); openQuickAddModal('${date}', ${idx})" style="font-size:11px; padding:5px 10px; border-color:#22c55e; color:#166634; white-space:nowrap;"><i class="fa-solid fa-user-plus"></i> Add</button>`
                : '-'}</td>
        </tr>`;
    }).join('');
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function formatDateDisplay(iso) {
    const [y, m, d] = iso.split('-');
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return `${d} ${months[parseInt(m, 10) - 1]} ${y}`;
}

function toggleGroupExpand(date, evt) {
    if (evt && evt.target.tagName === 'INPUT') return;
    dateGroups[date].expanded = !dateGroups[date].expanded;
    document.getElementById('group-' + date).classList.toggle('expanded');
}

function toggleGroupSelect(date, checked) {
    dateGroups[date].selected = checked;
}

function selectAllDates(state) {
    Object.keys(dateGroups).forEach(date => { dateGroups[date].selected = state; });
    renderDateGroups();
}

// ── Quick Add Customer ────────────────────────────────────────────────────
function openQuickAddModal(date, rowIndex) {
    qacRef = { date, rowIndex };
    const record = dateGroups[date].rows[rowIndex];
    document.getElementById('qacTCode').value     = record.t_code     || '';
    document.getElementById('qacPartyName').value = record.party_name || '';
    document.getElementById('qacShopName').value  = record.party_name || '';
    document.getElementById('qacError').style.display = 'none';
    document.getElementById('quickAddCustomerModal').style.display = 'flex';
    setTimeout(() => document.getElementById('qacShopName').focus(), 100);
}

function closeQuickAddModal() {
    document.getElementById('quickAddCustomerModal').style.display = 'none';
    qacRef = null;
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
    formData.append('action',    'quick_add');
    formData.append('t_code',    tCode);
    formData.append('shop_name', shopName);

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(result => {
            btn.disabled  = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save &amp; Validate';
            if (result.success) {
                const { date, rowIndex } = qacRef;
                dateGroups[date].rows[rowIndex].t_code_valid  = true;
                dateGroups[date].rows[rowIndex].customer_name = shopName;
                closeQuickAddModal();
                renderDateGroups();
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

// ── Import all selected date groups in one call ─────────────────────────────
function startImportAll() {
    const selectedDates = Object.keys(dateGroups).filter(d => dateGroups[d].selected);
    if (selectedDates.length === 0) { alert('Select at least one date to import'); return; }

    let totalValid = 0;
    const groupsPayload = [];
    selectedDates.forEach(date => {
        const validRows = dateGroups[date].rows.filter(r => r.t_code_valid && r.route_valid);
        totalValid += validRows.length;
        groupsPayload.push({
            delivery_date: date,
            filename: currentFile ? currentFile.name : 'unknown.xlsx',
            rows: validRows.map(r => ({
                sales_person_code: r.sales_person_code || '',
                t_code: r.t_code || '',
                route_code: r.route_code || '',
                bill_no: r.bill_no || '',
                bill_date: r.bill_date || '',
                outlet_code: r.outlet_code || '',
                party_name: r.party_name || '',
                free_qty: r.free_qty || 0,
                gross_sales: r.gross_sales || 0,
                scheme_disc: r.scheme_disc || 0,
                rs_discount: r.rs_discount || 0,
                tot_disc: r.tot_disc || 0,
                total_discount: r.total_discount || 0,
                bill_value: r.bill_value || 0,
                good_returns_value: r.good_returns_value || 0,
                damage_expiry_shortage_value: r.damage_expiry_shortage_value || 0,
                final_bill_amount: r.final_bill_amount || 0,
                delivery_person: r.delivery_person || '',
                customer_name: r.customer_name || '',
                route_name: r.route_name || '',
                t_code_valid: true,
                route_valid: true
            }))
        });
    });

    if (totalValid === 0) { alert('No valid records to import across the selected dates'); return; }

    const replaceCount = selectedDates.filter(d => dateGroups[d].isReplace).length;
    let confirmMsg = `Import ${totalValid} valid record(s) across ${selectedDates.length} date(s)?`;
    if (replaceCount > 0) confirmMsg += `\n\n${replaceCount} date(s) already have an import — old data will be archived to history and replaced.`;
    if (!confirm(confirmMsg)) return;

    document.getElementById('groupsSection').style.display   = 'none';
    document.getElementById('progressSection').style.display = 'block';
    document.getElementById('resultsList').innerHTML = '';
    document.getElementById('progressFill').style.width = '10%';
    document.getElementById('progressStatus').textContent = `Importing ${selectedDates.length} date(s)...`;

    const formData = new FormData();
    formData.append('action', 'import');
    formData.append('groups', JSON.stringify(groupsPayload));

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(r => r.text())
        .then(text => {
            let jsonText = text.trim();
            const s = jsonText.indexOf('{');
            if (s > 0) jsonText = jsonText.substring(s);
            let result;
            try { result = JSON.parse(jsonText); } catch (e) {
                alert('Server error:\n' + text.substring(0, 300));
                document.getElementById('progressSection').style.display = 'none';
                document.getElementById('groupsSection').style.display   = 'block';
                return;
            }

            document.getElementById('progressFill').style.width = '100%';
            document.getElementById('progressFill').textContent = '100%';
            document.getElementById('progressPercent').textContent = '100%';

            const list = document.getElementById('resultsList');
            list.innerHTML = '';
            let okCount = 0, errCount = 0, importedTotal = 0, archivedTotal = 0;

            (result.results || []).forEach(r => {
                const row = document.createElement('div');
                if (r.success) {
                    okCount++;
                    importedTotal += r.imported;
                    archivedTotal += (r.archived_rows || 0);
                    row.className = 'result-row ok';
                    row.innerHTML = `<i class="fa-solid fa-check-circle"></i>
                        <span><b>${formatDateDisplay(r.delivery_date)}</b> — ${r.imported} imported${r.failed > 0 ? `, ${r.failed} failed` : ''}${r.replaced_count > 0 ? ` · ${r.replaced_count} previous import archived` : ''}</span>`;
                } else {
                    errCount++;
                    row.className = 'result-row err';
                    row.innerHTML = `<i class="fa-solid fa-circle-xmark"></i>
                        <span><b>${formatDateDisplay(r.delivery_date)}</b> — ${escapeHtml(r.message || 'Failed')}</span>`;
                }
                list.appendChild(row);
            });

            document.getElementById('progressStatus').textContent =
                `Done — ${okCount} date(s) imported (${importedTotal} records)${errCount > 0 ? `, ${errCount} date(s) failed` : ''}${archivedTotal > 0 ? `, ${archivedTotal} old rows archived` : ''}`;
        })
        .catch(err => {
            alert('Network error: ' + err.message);
            document.getElementById('progressSection').style.display = 'none';
            document.getElementById('groupsSection').style.display   = 'block';
        });
}

function cancelImport() {
    if (confirm('Cancel import?')) {
        document.getElementById('groupsSection').style.display = 'none';
        document.getElementById('uploadForm').reset();
        dateGroups  = {};
        currentFile = null;
    }
}

document.getElementById('quickAddCustomerModal').addEventListener('click', function (e) {
    if (e.target === this) closeQuickAddModal();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeQuickAddModal(); });
</script>

<?php include 'footer.php'; ?>
