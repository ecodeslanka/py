<?php
include 'config.php';
include 'header.php';

// ─── DB Setup ────────────────────────────────────────────────────────────────
mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS monthly_invoice_imports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    import_month INT NOT NULL,
    import_year INT NOT NULL,
    import_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    company_name VARCHAR(255),
    company_address VARCHAR(255),
    total_rows INT DEFAULT 0,
    total_cash DECIMAL(15,2) DEFAULT 0,
    total_credit DECIMAL(15,2) DEFAULT 0,
    total_visa DECIMAL(15,2) DEFAULT 0,
    grand_total DECIMAL(15,2) DEFAULT 0
)");

mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS monthly_invoice_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    import_id INT NOT NULL,
    sale_date DATE,
    location_name VARCHAR(255),
    cash DECIMAL(15,2) DEFAULT 0,
    credit DECIMAL(15,2) DEFAULT 0,
    visa_card DECIMAL(15,2) DEFAULT 0,
    total DECIMAL(15,2) DEFAULT 0,
    FOREIGN KEY (import_id) REFERENCES monthly_invoice_imports(id) ON DELETE CASCADE
)");

// ─── Pure-PHP XLSX reader (ZipArchive + SimpleXML, no shell_exec) ─────────────
function readXlsx($path) {
    if (!class_exists('ZipArchive')) {
        return ['error' => 'ZipArchive extension is not enabled on this server.'];
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ['error' => 'Cannot open uploaded file as a ZIP/XLSX archive.'];
    }

    // 1. Shared strings
    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false) {
        $xml = simplexml_load_string($ss, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($xml) {
            foreach ($xml->si as $si) {
                // si may have a plain <t> or many <r><t> runs
                if (isset($si->t)) {
                    $shared[] = (string)$si->t;
                } else {
                    $text = '';
                    foreach ($si->r as $r) {
                        $text .= (string)$r->t;
                    }
                    $shared[] = $text;
                }
            }
        }
    }

    // 2. Sheet data — different export tools name/case this differently
    // (Sheet1.xml, sheet1.xml, sheet2.xml, etc.), so look it up tolerantly.
    $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml', 0, ZipArchive::FL_NOCASE);
    if ($sheet_xml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry_name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/.+\.xml$#i', $entry_name)) {
                $sheet_xml = $zip->getFromName($entry_name);
                break;
            }
        }
    }
    $zip->close();
    if ($sheet_xml === false) {
        return ['error' => 'Could not find a worksheet inside the uploaded file.'];
    }

    $xml = simplexml_load_string($sheet_xml, 'SimpleXMLElement', LIBXML_NOCDATA);
    if (!$xml) {
        return ['error' => 'Could not parse worksheet XML.'];
    }

    // Helper: col letter(s) → 0-based index
    $col_index = function($col_str) {
        $col_str = strtoupper(preg_replace('/[0-9]/', '', $col_str));
        $idx = 0;
        foreach (str_split($col_str) as $c) {
            $idx = $idx * 26 + (ord($c) - ord('A') + 1);
        }
        return $idx - 1;
    };

    $rows = [];
    $ns = $xml->getNamespaces(true);
    $sheetData = $xml->sheetData ?? $xml->children($ns[''] ?? '')->sheetData;

    foreach ($sheetData->row as $row) {
        $row_num = (int)$row['r'] - 1; // 0-based
        foreach ($row->c as $cell) {
            $ref  = (string)$cell['r'];           // e.g. A1
            $type = (string)$cell['t'];           // s=shared string, n/blank=number
            $val_node = $cell->v ?? null;
            $raw_val  = $val_node !== null ? (string)$val_node : null;

            if ($raw_val === null) {
                $value = null;
            } elseif ($type === 's') {
                $value = $shared[(int)$raw_val] ?? '';
            } else {
                $value = is_numeric($raw_val) ? $raw_val + 0 : $raw_val;
            }

            $col = $col_index($ref);
            $rows[$row_num][$col] = $value;
        }
    }

    // Sort rows by row index
    ksort($rows);
    return ['rows' => $rows, 'shared' => $shared];
}

// Excel serial date → Y-m-d string
function excelDateToYmd($serial) {
    if (!is_numeric($serial)) return null;
    // Excel epoch: Jan 1 1900 (with leap year bug, serial 1 = Jan 1 1900)
    $unix = ($serial - 25569) * 86400;
    return date('Y-m-d', (int)$unix);
}

// ─── Handle Delete ────────────────────────────────────────────────────────────
if (isset($_GET['delete_import'])) {
    $del_id = intval($_GET['delete_import']);
    if (mysqli_query($conn, "DELETE FROM monthly_invoice_imports WHERE id = $del_id")) {
        $success_message = "Import deleted successfully!";
    } else {
        $error_message = "Error deleting import: " . mysqli_error($conn);
    }
}

// ─── Handle Upload ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    $sel_month = intval($_POST['filter_month'] ?? date('n'));
    $sel_year  = intval($_POST['filter_year']  ?? date('Y'));
    $file      = $_FILES['excel_file'];
    $ext       = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, ['xlsx', 'xls'])) {
        $error_message = "Invalid file type. Please upload an .xlsx file.";
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $error_message = "File upload error (code " . $file['error'] . "). Please try again.";
    } else {
        // Duplicate check
        $dup = mysqli_query($conn, "SELECT id FROM monthly_invoice_imports WHERE import_month=$sel_month AND import_year=$sel_year");
        if (mysqli_num_rows($dup) > 0) {
            $months_arr = ['January','February','March','April','May','June','July','August','September','October','November','December'];
            $error_message = "An import for " . $months_arr[$sel_month-1] . " $sel_year already exists. Delete it first to re-import.";
        } else {
            $result = readXlsx($file['tmp_name']);
            if (isset($result['error'])) {
                $error_message = "Parse error: " . $result['error'];
            } else {
                $xlsx_rows = $result['rows'];

                // Extract header info
                // Row 0: company name (col 0), address (col 1)
                // Header row (CASH/CREDIT/[VISA CARD]/Total) is located dynamically below,
                // since some files don't have a VISA CARD column at all.
                $company_name = isset($xlsx_rows[0][0]) ? trim((string)$xlsx_rows[0][0]) : '';
                $company_addr = isset($xlsx_rows[0][1]) ? trim((string)$xlsx_rows[0][1]) : '';

                $sorted_row_keys = array_keys($xlsx_rows);
                sort($sorted_row_keys);

                // ─── Locate header row + map columns by label text (handles files with/without VISA CARD) ───
                $header_row_idx = null;
                $col_cash = $col_credit = $col_visa = $col_total = null;
                foreach ($sorted_row_keys as $ri) {
                    $lc = $lcr = $lv = $lt = null;
                    foreach ($xlsx_rows[$ri] as $cidx => $cval) {
                        if (!is_string($cval)) continue;
                        $label = strtoupper(trim($cval));
                        if ($label === '') continue;
                        if (strpos($label, 'VISA')   !== false) $lv  = $cidx;
                        elseif (strpos($label, 'CASH')   !== false) $lc  = $cidx;
                        elseif (strpos($label, 'CREDIT') !== false) $lcr = $cidx;
                        elseif (strpos($label, 'TOTAL')  !== false) $lt  = $cidx;
                    }
                    if ($lc !== null || $lcr !== null || $lv !== null) {
                        $header_row_idx = $ri;
                        $col_cash = $lc; $col_credit = $lcr; $col_visa = $lv; $col_total = $lt;
                        break;
                    }
                }

                $data_rows = [];
                if ($header_row_idx === null) {
                    $error_message = "Could not find a CASH/CREDIT/VISA CARD header row in this file. Please check the format.";
                } else {
                    foreach ($sorted_row_keys as $ri) {
                        if ($ri <= $header_row_idx) continue; // skip everything up to and including the header row
                        $r   = $xlsx_rows[$ri];
                        $col0 = $r[0] ?? null;
                        if ($col0 === null || $col0 === '') continue;

                        // Date: could be Excel serial or string
                        if (is_numeric($col0)) {
                            $sale_date = excelDateToYmd((float)$col0);
                        } else {
                            $raw = trim((string)$col0);
                            // Try d/m/Y then m/d/Y
                            $dt = DateTime::createFromFormat('d/m/Y', $raw)
                               ?: DateTime::createFromFormat('m/d/Y', $raw)
                               ?: DateTime::createFromFormat('Y-m-d', $raw)
                               ?: null;
                            $sale_date = $dt ? $dt->format('Y-m-d') : null;
                        }

                        if (!$sale_date) continue; // skip totals row (no valid date)

                        $cash_val   = ($col_cash   !== null && isset($r[$col_cash]))   ? floatval($r[$col_cash])   : 0;
                        $credit_val = ($col_credit !== null && isset($r[$col_credit])) ? floatval($r[$col_credit]) : 0;
                        $visa_val   = ($col_visa   !== null && isset($r[$col_visa]))   ? floatval($r[$col_visa])   : 0;
                        // If there's no explicit Total column in the file, derive it from the parts
                        $total_val  = ($col_total !== null && isset($r[$col_total]))
                            ? floatval($r[$col_total])
                            : ($cash_val + $credit_val + $visa_val);

                        $data_rows[] = [
                            'date'     => $sale_date,
                            'location' => isset($r[1]) ? trim((string)$r[1]) : '',
                            'cash'     => $cash_val,
                            'credit'   => $credit_val,
                            'visa'     => $visa_val + $cash_val, // cash summed into visa card
                            'total'    => $total_val,
                        ];
                    }
                }

                if (empty($data_rows)) {
                    if (!isset($error_message)) {
                        $error_message = "No valid data rows found. Ensure the file matches the Daily Sales Summary format.";
                    }
                } else {
                    $t_cash  = array_sum(array_column($data_rows, 'cash'));
                    $t_cred  = array_sum(array_column($data_rows, 'credit'));
                    $t_visa  = array_sum(array_column($data_rows, 'visa'));
                    $t_total = array_sum(array_column($data_rows, 'total'));
                    $t_rows  = count($data_rows);

                    $cn = mysqli_real_escape_string($conn, $company_name);
                    $ca = mysqli_real_escape_string($conn, $company_addr);

                    $ins = "INSERT INTO monthly_invoice_imports
                        (import_month,import_year,company_name,company_address,total_rows,total_cash,total_credit,total_visa,grand_total)
                        VALUES ($sel_month,$sel_year,'$cn','$ca',$t_rows,$t_cash,$t_cred,$t_visa,$t_total)";

                    if (!mysqli_query($conn, $ins)) {
                        $error_message = "DB error: " . mysqli_error($conn);
                    } else {
                        $imp_id = mysqli_insert_id($conn);
                        foreach ($data_rows as $dr) {
                            $loc  = mysqli_real_escape_string($conn, $dr['location']);
                            $sd   = $dr['date'];
                            $cash = $dr['cash']; $cred = $dr['credit']; $visa = $dr['visa']; $tot = $dr['total'];
                            mysqli_query($conn, "INSERT INTO monthly_invoice_details
                                (import_id,sale_date,location_name,cash,credit,visa_card,total)
                                VALUES ($imp_id,'$sd','$loc',$cash,$cred,$visa,$tot)");
                        }
                        $months_arr = ['January','February','March','April','May','June','July','August','September','October','November','December'];
                        $success_message = "Imported $t_rows records for " . $months_arr[$sel_month-1] . " $sel_year successfully!";
                    }
                }
            }
        }
    }
}

// ─── View detail ─────────────────────────────────────────────────────────────
$view_import = isset($_GET['view']) ? intval($_GET['view']) : 0;
$detail_rows = [];
$view_info   = null;
if ($view_import > 0) {
    $vi = mysqli_query($conn, "SELECT * FROM monthly_invoice_imports WHERE id = $view_import");
    $view_info = mysqli_fetch_assoc($vi);
    $vd = mysqli_query($conn, "SELECT * FROM monthly_invoice_details WHERE import_id = $view_import ORDER BY sale_date ASC");
    while ($row = mysqli_fetch_assoc($vd)) $detail_rows[] = $row;
}

$months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
$current_year = (int)date('Y');
?>

<!-- ─── Page Header ─────────────────────────────────────────────────────────── -->
<div class="page-header">
    <h2 class="page-title">Monthly Invoice Summary</h2>
    <p class="page-subtitle">Import, view, and manage monthly sales summaries from Excel</p>
</div>

<?php if (isset($success_message)): ?>
<div class="alert alert-success">
    <i class="fa-solid fa-circle-check"></i>
    <?php echo htmlspecialchars($success_message); ?>
</div>
<?php endif; ?>
<?php if (isset($error_message)): ?>
<div class="alert alert-error">
    <i class="fa-solid fa-circle-exclamation"></i>
    <?php echo htmlspecialchars($error_message); ?>
</div>
<?php endif; ?>

<!-- ─── Import Card ──────────────────────────────────────────────────────────── -->
<div class="content-card">
    <h3 class="card-title">Import Excel File</h3>
    <form method="POST" action="" enctype="multipart/form-data" class="form import-form">
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Period <span class="required">*</span></label>
                <div class="period-select-wrap">
                    <select name="filter_month" class="form-select period-select" required>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m === (int)date('n') ? 'selected' : ''; ?>>
                                <?php echo $months[$m-1]; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    <select name="filter_year" class="form-select period-select" required>
                        <?php for ($y = $current_year - 3; $y <= $current_year + 1; $y++): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y === $current_year ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <small class="form-hint">Select the month and year this file covers</small>
            </div>

            <div class="form-group">
                <label for="excel_file" class="form-label">Excel File <span class="required">*</span></label>
                <div class="file-drop-area" id="fileDropArea">
                    <input type="file" name="excel_file" id="excel_file" accept=".xlsx,.xls" required class="file-input">
                    <div class="file-drop-inner">
                        <i class="fa-solid fa-file-excel file-icon"></i>
                        <span class="file-drop-text">Click or drag &amp; drop your Excel file here</span>
                        <span class="file-drop-hint">.xlsx — Daily Sales Summary format</span>
                        <span class="file-name-display" id="fileNameDisplay"></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-file-import"></i>
                Import File
            </button>
        </div>
    </form>
</div>

<!-- ─── Detail View ──────────────────────────────────────────────────────────── -->
<?php if ($view_import > 0 && $view_info): ?>
<div class="content-card">
    <div class="detail-header">
        <div>
            <h3 class="card-title">
                <?php echo htmlspecialchars($view_info['company_name']); ?>
                &mdash; <?php echo $months[$view_info['import_month']-1] . ' ' . $view_info['import_year']; ?>
            </h3>
            <p class="detail-address"><?php echo htmlspecialchars($view_info['company_address']); ?></p>
        </div>
        <a href="monthly_invoice_summary.php" class="btn btn-secondary btn-sm">
            <i class="fa-solid fa-xmark"></i> Close
        </a>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <span class="summary-label">Cash</span>
            <span class="summary-value"><?php echo number_format($view_info['total_cash'], 2); ?></span>
        </div>
        <div class="summary-card">
            <span class="summary-label">Credit</span>
            <span class="summary-value"><?php echo number_format($view_info['total_credit'], 2); ?></span>
        </div>
        <div class="summary-card">
            <span class="summary-label">Visa Card</span>
            <span class="summary-value"><?php echo number_format($view_info['total_visa'], 2); ?></span>
        </div>
        <div class="summary-card summary-card-total">
            <span class="summary-label">Grand Total</span>
            <span class="summary-value"><?php echo number_format($view_info['grand_total'], 2); ?></span>
        </div>
    </div>

    <?php if (!empty($detail_rows)): ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Location</th>
                    <th class="text-right">Cash</th>
                    <th class="text-right">Credit</th>
                    <th class="text-right">Visa Card</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($detail_rows as $idx => $dr): ?>
                <tr>
                    <td><?php echo $idx + 1; ?></td>
                    <td><?php echo $dr['sale_date'] ? date('d M Y', strtotime($dr['sale_date'])) : '—'; ?></td>
                    <td><?php echo htmlspecialchars($dr['location_name']); ?></td>
                    <td class="text-right"><?php echo $dr['cash'] > 0 ? number_format($dr['cash'], 2) : '<span class="zero">—</span>'; ?></td>
                    <td class="text-right"><?php echo $dr['credit'] > 0 ? number_format($dr['credit'], 2) : '<span class="zero">—</span>'; ?></td>
                    <td class="text-right"><?php echo $dr['visa_card'] > 0 ? number_format($dr['visa_card'], 2) : '<span class="zero">—</span>'; ?></td>
                    <td class="text-right amount-bold"><?php echo number_format($dr['total'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="totals-row">
                    <td colspan="3"><strong>Total (<?php echo count($detail_rows); ?> days)</strong></td>
                    <td class="text-right"><strong><?php echo number_format($view_info['total_cash'], 2); ?></strong></td>
                    <td class="text-right"><strong><?php echo number_format($view_info['total_credit'], 2); ?></strong></td>
                    <td class="text-right"><strong><?php echo number_format($view_info['total_visa'], 2); ?></strong></td>
                    <td class="text-right amount-bold"><strong><?php echo number_format($view_info['grand_total'], 2); ?></strong></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php else: ?>
    <p class="empty-state">No detail records found for this import.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ─── Imports List ─────────────────────────────────────────────────────────── -->
<div class="content-card">
    <div class="list-header">
        <h3 class="card-title">All Imports</h3>
        <form method="GET" action="" class="filter-form">
            <?php if ($view_import): ?>
                <input type="hidden" name="view" value="<?php echo $view_import; ?>">
            <?php endif; ?>
            <select name="month" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Months</option>
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?php echo $m; ?>" <?php echo (isset($_GET['month']) && (int)$_GET['month'] === $m) ? 'selected' : ''; ?>>
                        <?php echo $months[$m-1]; ?>
                    </option>
                <?php endfor; ?>
            </select>
            <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Years</option>
                <?php for ($y = $current_year - 3; $y <= $current_year + 1; $y++): ?>
                    <option value="<?php echo $y; ?>" <?php echo (isset($_GET['year']) && (int)$_GET['year'] === $y) ? 'selected' : ''; ?>>
                        <?php echo $y; ?>
                    </option>
                <?php endfor; ?>
            </select>
        </form>
    </div>

    <?php
    $where_parts = [];
    if (!empty($_GET['month'])) $where_parts[] = "import_month=" . intval($_GET['month']);
    if (!empty($_GET['year']))  $where_parts[] = "import_year="  . intval($_GET['year']);
    $where = $where_parts ? "WHERE " . implode(' AND ', $where_parts) : '';
    $list_res = mysqli_query($conn, "SELECT * FROM monthly_invoice_imports $where ORDER BY import_year DESC, import_month DESC");
    ?>

    <?php if (mysqli_num_rows($list_res) > 0): ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Period</th>
                    <th>Company</th>
                    <th class="text-right">Records</th>
                    <th class="text-right">Cash</th>
                    <th class="text-right">Credit</th>
                    <th class="text-right">Visa Card</th>
                    <th class="text-right">Grand Total</th>
                    <th>Imported On</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($imp = mysqli_fetch_assoc($list_res)): ?>
                <tr class="<?php echo $view_import == $imp['id'] ? 'row-active' : ''; ?>">
                    <td><strong><?php echo $months[$imp['import_month']-1] . ' ' . $imp['import_year']; ?></strong></td>
                    <td><?php echo htmlspecialchars($imp['company_name']); ?></td>
                    <td class="text-right"><span class="badge badge-neutral"><?php echo $imp['total_rows']; ?></span></td>
                    <td class="text-right"><?php echo number_format($imp['total_cash'], 2); ?></td>
                    <td class="text-right"><?php echo number_format($imp['total_credit'], 2); ?></td>
                    <td class="text-right"><?php echo number_format($imp['total_visa'], 2); ?></td>
                    <td class="text-right amount-bold"><?php echo number_format($imp['grand_total'], 2); ?></td>
                    <td><?php echo date('d M Y', strtotime($imp['import_date'])); ?></td>
                    <td>
                        <div class="action-buttons">
                            <a href="?view=<?php echo $imp['id']; ?>" class="btn-action btn-view" title="View Details">
                                <i class="fa-solid fa-eye"></i>
                            </a>
                            <a href="monthly_invoice_reconcile.php?import_id=<?php echo $imp['id']; ?>" class="btn-action btn-reconcile" title="Reconcile">
                                <i class="fa-solid fa-balance-scale"></i>
                            </a>
                            <a href="?delete_import=<?php echo $imp['id']; ?>"
                               class="btn-action btn-delete" title="Delete Import"
                               onclick="return confirm('Delete this import and all its records? This cannot be undone.')">
                                <i class="fa-solid fa-trash"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p class="empty-state">No imports found. Use the form above to import your first file.</p>
    <?php endif; ?>
</div>

<style>
.alert{padding:16px 20px;border-radius:8px;margin-bottom:24px;display:flex;align-items:center;gap:12px;font-size:13px;font-weight:500}
.alert i{font-size:18px}
.alert-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.alert-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}

.import-form{max-width:100%}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:24px}
@media(max-width:768px){.form-row{grid-template-columns:1fr}}
.form-group{margin-bottom:24px}
.form-label{display:block;font-size:13px;font-weight:600;margin-bottom:8px;color:#333}
.required{color:#ef4444}
.form-hint{display:block;font-size:11px;color:#666;margin-top:6px}

.period-select-wrap{display:flex;gap:10px}
.form-select{padding:12px 36px 12px 16px;border:1px solid #e5e5e5;border-radius:8px;font-size:14px;font-family:'Inter',sans-serif;background:#fff;color:#333;cursor:pointer;transition:all .3s;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23666' d='M6 8L1 3h10z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;width:100%}
.form-select:focus{outline:none;border-color:#000;box-shadow:0 0 0 3px rgba(0,0,0,.05)}
.period-select{flex:1}
.form-select-sm{padding:8px 32px 8px 12px;font-size:13px;width:auto}

.file-drop-area{position:relative;border:2px dashed #e5e5e5;border-radius:10px;padding:28px 20px;text-align:center;cursor:pointer;transition:all .3s;background:#fafafa}
.file-drop-area:hover,.file-drop-area.drag-over{border-color:#000;background:#f5f5f5}
.file-input{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer}
.file-drop-inner{display:flex;flex-direction:column;align-items:center;gap:6px;pointer-events:none}
.file-icon{font-size:32px;color:#22863a;margin-bottom:4px}
.file-drop-text{font-size:14px;font-weight:600;color:#333}
.file-drop-hint{font-size:12px;color:#888}
.file-name-display{font-size:12px;color:#000;font-weight:600;margin-top:4px}

.form-actions{display:flex;gap:12px;margin-top:8px}
.btn{display:inline-flex;align-items:center;gap:8px;padding:12px 24px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;transition:all .3s;text-decoration:none;font-family:'Inter',sans-serif}
.btn i{font-size:16px}
.btn-primary{background:#000;color:#fff}
.btn-primary:hover{background:#333;transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}
.btn-secondary:hover{background:#e5e5e5}
.btn-sm{padding:8px 16px;font-size:13px}

.list-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;flex-wrap:wrap;gap:12px}
.filter-form{display:flex;gap:8px}

.table-responsive{overflow-x:auto;margin-top:20px}
.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table thead{background:#fafafa;border-bottom:2px solid #e5e5e5}
.data-table th{padding:12px 16px;text-align:left;font-weight:600;color:#333;font-size:12px;text-transform:uppercase;letter-spacing:.5px}
.data-table th.text-right{text-align:right}
.data-table tbody tr{border-bottom:1px solid #f0f0f0;transition:background .2s}
.data-table tbody tr:hover{background:#fafafa}
.data-table tbody tr.row-active{background:#f0fdf4}
.data-table td{padding:14px 16px;color:#333}
.data-table td.text-right{text-align:right}
.data-table tfoot td{padding:14px 16px;border-top:2px solid #e5e5e5;background:#fafafa}
.data-table tfoot td.text-right{text-align:right}
.amount-bold{font-weight:700;color:#000}
.zero{color:#bbb}
.totals-row{background:#f5f5f5!important}

.badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600}
.badge-neutral{background:#f0f0f0;color:#555;border:1px solid #e5e5e5}

.action-buttons{display:flex;gap:8px}
.btn-action{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;border:1px solid #e5e5e5;background:#fff;color:#666;cursor:pointer;transition:all .2s;text-decoration:none}
.btn-action:hover{transform:translateY(-2px);box-shadow:0 2px 8px rgba(0,0,0,.1)}
.btn-view:hover{background:#000;color:#fff;border-color:#000}
.btn-reconcile:hover{background:#0066cc;color:#fff;border-color:#0066cc}
.btn-delete:hover{background:#ef4444;color:#fff;border-color:#ef4444}

.detail-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;gap:12px}
.detail-address{font-size:12px;color:#666;margin-top:2px}
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px}
@media(max-width:768px){.summary-grid{grid-template-columns:1fr 1fr}}
.summary-card{background:#fafafa;border:1px solid #e5e5e5;border-radius:10px;padding:16px 20px;display:flex;flex-direction:column;gap:6px}
.summary-card-total{background:#000;border-color:#000}
.summary-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#666}
.summary-card-total .summary-label{color:#aaa}
.summary-value{font-size:18px;font-weight:700;color:#000}
.summary-card-total .summary-value{color:#fff}
.empty-state{color:#666;font-size:13px;text-align:center;padding:32px 20px}
</style>

<script>
const fileInput=document.getElementById('excel_file');
const dropArea=document.getElementById('fileDropArea');
const nameDisplay=document.getElementById('fileNameDisplay');
if(fileInput){
    fileInput.addEventListener('change',()=>{
        nameDisplay.textContent=fileInput.files[0]?.name??'';
    });
}
['dragover','dragenter'].forEach(e=>dropArea?.addEventListener(e,ev=>{ev.preventDefault();dropArea.classList.add('drag-over')}));
['dragleave','drop'].forEach(e=>dropArea?.addEventListener(e,()=>dropArea.classList.remove('drag-over')));
</script>

<?php include 'footer.php'; ?>