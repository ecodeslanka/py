<?php
include_once 'auth.php';
requireLogin();
$current_user = getCurrentUser();
include_once 'config.php';

// Helper: run a prepared statement and return all rows as assoc array
function db_query($conn, $sql, $params = []) {
    $stmt = mysqli_prepare($conn, $sql);
    if ($params) {
        $types = str_repeat('s', count($params));
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) $rows[] = $row;
    mysqli_stmt_close($stmt);
    return $rows;
}

// Helper: return single scalar value
function db_scalar($conn, $sql, $params = []) {
    $stmt = mysqli_prepare($conn, $sql);
    if ($params) {
        $types = str_repeat('s', count($params));
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_row($result);
    mysqli_stmt_close($stmt);
    return $row ? $row[0] : null;
}

$success_msg = '';
$error_msg = '';
$upload_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['stock_file'])) {
    $delivery_date = $_POST['delivery_date'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    $file = $_FILES['stock_file'];

    if (empty($delivery_date)) {
        $error_msg = 'Please select a delivery date.';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $error_msg = 'File upload error. Please try again.';
    } elseif (!in_array(pathinfo($file['name'], PATHINFO_EXTENSION), ['xlsx', 'xls'])) {
        $error_msg = 'Only Excel files (.xlsx, .xls) are allowed.';
    } else {
        // Check if delivery date already uploaded
        $existing = db_scalar($conn, "SELECT id FROM daily_stock_uploads WHERE delivery_date = ?", [$delivery_date]);
        if ($existing) {
            $error_msg = "Stock data for <strong>" . date('d M Y', strtotime($delivery_date)) . "</strong> has already been uploaded. Please delete the existing upload first.";
        } else {
            $tmp_path = $file['tmp_name'];
            $original_name = basename($file['name']);

            // Use Python to parse the Excel
            $py_script = sys_get_temp_dir() . '/parse_stock_' . time() . '.py';
            $out_file   = sys_get_temp_dir() . '/stock_data_' . time() . '.json';

            $py_code = <<<PYTHON
import pandas as pd, json, sys
try:
    df = pd.read_excel('$tmp_path', header=17)
    df.columns = [str(c).strip() for c in df.columns]
    expected = ['Sr No','Division','Basepack Code','SKU7','Product Name','Location','PKM',
                'Batch Code','Expiry Month','Expiry Date','No of Days to Expire','UPC',
                'Units','Stocks in Days','Pur.Rate','Pur.Rate + Tax','TUR','MRP',
                'Cur.Stk Value','Tonnage']
    # Drop rows where Sr No is not numeric
    df = df[pd.to_numeric(df['Sr No'], errors='coerce').notna()]
    df['Sr No'] = df['Sr No'].astype(int)
    # Convert expiry date
    df['Expiry Date'] = pd.to_datetime(df['Expiry Date'], errors='coerce')
    records = []
    for _, row in df.iterrows():
        def v(x):
            import math
            if x is None: return None
            try:
                if isinstance(x, float) and math.isnan(x): return None
            except: pass
            return x
        expiry_date_str = row['Expiry Date'].strftime('%Y-%m-%d') if pd.notna(row['Expiry Date']) else None
        records.append({
            'sr_no': int(row['Sr No']),
            'division': str(v(row['Division']) or ''),
            'basepack_code': str(v(row['Basepack Code']) or ''),
            'sku7': str(v(row['SKU7']) or ''),
            'product_name': str(v(row['Product Name']) or ''),
            'location': str(v(row['Location']) or ''),
            'pkm': str(v(row['PKM']) or ''),
            'batch_code': str(v(row['Batch Code']) or ''),
            'expiry_month': str(v(row['Expiry Month']) or ''),
            'expiry_date': expiry_date_str,
            'no_of_days_to_expire': int(row['No of Days to Expire']) if pd.notna(row['No of Days to Expire']) else None,
            'upc': str(v(row['UPC']) or ''),
            'units': int(row['Units']) if pd.notna(row['Units']) else None,
            'stocks_in_days': str(v(row['Stocks in Days']) or ''),
            'pur_rate': float(row['Pur.Rate']) if pd.notna(row['Pur.Rate']) else None,
            'pur_rate_tax': float(row['Pur.Rate + Tax']) if pd.notna(row['Pur.Rate + Tax']) else None,
            'tur': float(row['TUR']) if pd.notna(row['TUR']) else None,
            'mrp': float(row['MRP']) if pd.notna(row['MRP']) else None,
            'cur_stk_value': float(row['Cur.Stk Value']) if pd.notna(row['Cur.Stk Value']) else None,
            'tonnage': float(row['Tonnage']) if pd.notna(row['Tonnage']) else None,
        })
    with open('$out_file', 'w') as f:
        json.dump({'status':'ok','count':len(records),'records':records}, f)
except Exception as e:
    with open('$out_file', 'w') as f:
        json.dump({'status':'error','message':str(e)}, f)
PYTHON;

            file_put_contents($py_script, $py_code);
            shell_exec("python3 $py_script 2>/dev/null");

            if (!file_exists($out_file)) {
                $error_msg = 'Failed to process the Excel file. Python not available.';
            } else {
                $result = json_decode(file_get_contents($out_file), true);
                unlink($py_script);
                unlink($out_file);

                if ($result['status'] !== 'ok') {
                    $error_msg = 'Excel parsing error: ' . htmlspecialchars($result['message']);
                } elseif (count($result['records']) === 0) {
                    $error_msg = 'No valid data rows found in the file.';
                } else {
                    mysqli_begin_transaction($conn);
                    try {
                        $upload_ref = 'STK-' . strtoupper(date('Ymd')) . '-' . substr(uniqid(), -5);
                        $uploaded_by = $current_user['id'] ?? null;
                        $total_rows  = count($result['records']);
                        $notes_val   = $notes ?: null;

                        $ins_upload = mysqli_prepare($conn,
                            "INSERT INTO daily_stock_uploads
                             (upload_ref, delivery_date, original_filename, total_rows, uploaded_by, notes)
                             VALUES (?,?,?,?,?,?)"
                        );
                        mysqli_stmt_bind_param($ins_upload, 'sssiis',
                            $upload_ref, $delivery_date, $original_name,
                            $total_rows, $uploaded_by, $notes_val
                        );
                        mysqli_stmt_execute($ins_upload);
                        $upload_id = mysqli_insert_id($conn);
                        mysqli_stmt_close($ins_upload);

                        $ins_item = mysqli_prepare($conn,
                            "INSERT INTO daily_stock_items
                             (upload_id, delivery_date, sr_no, division, basepack_code, sku7, product_name,
                              location, pkm, batch_code, expiry_month, expiry_date, no_of_days_to_expire,
                              upc, units, stocks_in_days, pur_rate, pur_rate_tax, tur, mrp, cur_stk_value, tonnage)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                        );

                        foreach ($result['records'] as $row) {
                            $sr_no              = $row['sr_no'];
                            $division           = $row['division'];
                            $basepack_code      = $row['basepack_code'];
                            $sku7               = $row['sku7'];
                            $product_name       = $row['product_name'];
                            $location           = $row['location'];
                            $pkm                = $row['pkm'];
                            $batch_code         = $row['batch_code'];
                            $expiry_month       = $row['expiry_month'];
                            $expiry_date        = $row['expiry_date'];
                            $no_of_days         = $row['no_of_days_to_expire'];
                            $upc                = $row['upc'];
                            $units              = $row['units'];
                            $stocks_in_days     = $row['stocks_in_days'];
                            $pur_rate           = $row['pur_rate'];
                            $pur_rate_tax       = $row['pur_rate_tax'];
                            $tur                = $row['tur'];
                            $mrp                = $row['mrp'];
                            $cur_stk_value      = $row['cur_stk_value'];
                            $tonnage            = $row['tonnage'];

                            mysqli_stmt_bind_param($ins_item, 'ississsssssiissddddddd',
                                $upload_id, $delivery_date, $sr_no,
                                $division, $basepack_code, $sku7, $product_name,
                                $location, $pkm, $batch_code, $expiry_month,
                                $expiry_date, $no_of_days,
                                $upc, $units, $stocks_in_days,
                                $pur_rate, $pur_rate_tax, $tur,
                                $mrp, $cur_stk_value, $tonnage
                            );
                            mysqli_stmt_execute($ins_item);
                        }
                        mysqli_stmt_close($ins_item);

                        mysqli_commit($conn);
                        $success_msg = "Successfully uploaded <strong>" . number_format(count($result['records'])) . " rows</strong> for delivery date <strong>" . date('d M Y', strtotime($delivery_date)) . "</strong>. Reference: <strong>$upload_ref</strong>";
                        $upload_result = ['ref' => $upload_ref, 'rows' => count($result['records']), 'id' => $upload_id];

                    } catch (Exception $e) {
                        mysqli_rollback($conn);
                        $error_msg = 'Database error: ' . htmlspecialchars($e->getMessage());
                    }
                }
            }
        }
    }
}

// Recent uploads for quick reference
$recent_uploads = db_query($conn,
    "SELECT u.*, usr.username as uploader_name
     FROM daily_stock_uploads u
     LEFT JOIN users usr ON u.uploaded_by = usr.id
     ORDER BY u.delivery_date DESC, u.uploaded_at DESC
     LIMIT 10"
);
?>
<?php include 'header.php'; ?>

<style>
.upload-card {
    background: #fff; border-radius: 12px; border: 1px solid #e5e7eb;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06); padding: 28px 32px;
}
.upload-card h2 {
    font-size: 15px; font-weight: 700; color: #111;
    margin: 0 0 20px; display: flex; align-items: center; gap: 8px;
}
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-group.full { grid-column: 1 / -1; }
.form-label { font-size: 13px; font-weight: 600; color: #374151; }
.form-label .req { color: #dc2626; }
.form-control {
    padding: 9px 12px; border: 1.5px solid #d1d5db; border-radius: 8px;
    font-size: 13px; font-family: 'Inter', sans-serif; color: #111;
    outline: none; transition: border-color 0.2s; background: #fff;
}
.form-control:focus { border-color: #000; }

/* Drop zone */
.drop-zone {
    border: 2px dashed #d1d5db; border-radius: 10px; padding: 32px;
    text-align: center; cursor: pointer; transition: all 0.2s; position: relative;
    background: #fafafa;
}
.drop-zone:hover, .drop-zone.dragover { border-color: #000; background: #f3f4f6; }
.drop-zone input[type="file"] {
    position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
}
.drop-zone-icon { font-size: 36px; color: #16a34a; margin-bottom: 10px; }
.drop-zone-text { font-size: 14px; font-weight: 600; color: #374151; }
.drop-zone-sub  { font-size: 12px; color: #9ca3af; margin-top: 4px; }
.file-selected  { display: none; }
.file-badge {
    display: inline-flex; align-items: center; gap: 8px;
    background: #f0fdf4; border: 1px solid #bbf7d0;
    border-radius: 8px; padding: 10px 16px; font-size: 13px;
    color: #166534; font-weight: 500;
}

/* Format guide */
.format-guide {
    background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;
    padding: 14px 16px; margin-top: 16px; font-size: 12px; color: #6b7280;
}
.format-guide p { margin: 0 0 8px; font-weight: 600; color: #374151; }
.format-guide ul { margin: 0; padding-left: 16px; line-height: 1.8; }

.btn {
    padding: 10px 20px; border-radius: 8px; font-size: 13px; font-weight: 600;
    font-family: 'Inter', sans-serif; cursor: pointer; border: none;
    display: inline-flex; align-items: center; gap: 8px; transition: all 0.15s;
    text-decoration: none;
}
.btn-primary { background: #000; color: #fff; }
.btn-primary:hover { background: #1a1a1a; }
.btn-outline { background: #fff; color: #374151; border: 1.5px solid #d1d5db; }
.btn-outline:hover { border-color: #000; color: #000; }
.btn-sm { padding: 6px 14px; font-size: 12px; }

/* Alert */
.alert { padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-size: 13px; line-height: 1.5; }
.alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
.alert-danger  { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }

/* Mini table */
.mini-table { width: 100%; border-collapse: collapse; }
.mini-table th {
    font-size: 11px; font-weight: 600; color: #9ca3af; text-transform: uppercase;
    letter-spacing: 0.5px; padding: 8px 0; border-bottom: 1px solid #e5e7eb;
    text-align: left;
}
.mini-table td { padding: 9px 0; border-bottom: 1px solid #f3f4f6; font-size: 12px; vertical-align: middle; }
.mini-table tr:last-child td { border-bottom: none; }

.badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 500; }
.badge-blue { background: #eff6ff; color: #1d4ed8; }

@media(max-width: 700px) {
    .form-row { grid-template-columns: 1fr; }
}
</style>

<div style="padding:24px;">
    <!-- Page Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;">
        <div>
            <h1 style="font-size:20px;font-weight:700;color:#111;margin:0;">
                <i class="fa-solid fa-cloud-arrow-up"></i> Upload Daily Stock
            </h1>
            <p style="font-size:13px;color:#9ca3af;margin:4px 0 0;">Import LeverEDGE current stock report</p>
        </div>
        <a href="daily_stock_list.php" class="btn btn-outline btn-sm">
            <i class="fa-solid fa-list"></i> View All Uploads
        </a>
    </div>

    <?php if ($error_msg): ?>
        <div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> <?php echo $error_msg; ?></div>
    <?php endif; ?>

    <?php if ($success_msg && $upload_result): ?>
        <div class="alert alert-success">
            <div>
                <?php echo $success_msg; ?><br>
                <div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;">
                    <a href="daily_stock_view.php?id=<?php echo $upload_result['id']; ?>" class="btn btn-sm btn-primary">
                        <i class="fa-solid fa-eye"></i> View Records
                    </a>
                    <a href="daily_stock_list.php" class="btn btn-sm btn-outline">
                        <i class="fa-solid fa-list"></i> All Uploads
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div style="display:grid; grid-template-columns: 1fr 380px; gap:24px; align-items:start;">
        <!-- Upload Form -->
        <div class="upload-card">
            <h2><i class="fa-solid fa-file-excel"></i> Upload Stock File</h2>
            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Delivery Date <span class="req">*</span></label>
                        <input type="date" name="delivery_date" class="form-control"
                               value="<?php echo htmlspecialchars($_POST['delivery_date'] ?? date('Y-m-d')); ?>"
                               max="<?php echo date('Y-m-d'); ?>" required>
                        <span style="font-size:12px;color:#9ca3af;">Select the date the stock was delivered</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Notes <span style="color:#9ca3af;font-weight:400;">(optional)</span></label>
                        <input type="text" name="notes" class="form-control"
                               placeholder="e.g. Month-end stock count"
                               value="<?php echo htmlspecialchars($_POST['notes'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-group full">
                    <label class="form-label">Excel File <span class="req">*</span></label>
                    <div class="drop-zone" id="dropZone">
                        <input type="file" name="stock_file" id="stockFile" accept=".xlsx,.xls" required>
                        <div id="dropContent">
                            <div class="drop-zone-icon"><i class="fa-solid fa-file-excel"></i></div>
                            <div class="drop-zone-text">Drop your LeverEDGE stock file here</div>
                            <div class="drop-zone-sub">or click to browse &nbsp;·&nbsp; .xlsx / .xls only</div>
                        </div>
                        <div class="file-selected" id="fileSelected">
                            <div class="file-badge">
                                <i class="fa-solid fa-file-excel"></i>
                                <span id="fileName">No file selected</span>
                                <i class="fa-solid fa-circle-check" style="color:#16a34a;"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="format-guide">
                    <p><i class="fa-solid fa-circle-info"></i> &nbsp;Expected File Format</p>
                    <ul>
                        <li>LeverEDGE <strong>Current Stock Report</strong> export (standard format)</li>
                        <li>Data starts at row 18 — do <strong>not</strong> modify the file before uploading</li>
                        <li>Columns: Sr No, Division, Basepack Code, SKU7, Product Name, Location, PKM, Batch Code, Expiry Month, Expiry Date, No of Days to Expire, UPC, Units, Stocks in Days, Pur.Rate, Pur.Rate + Tax, TUR, MRP, Cur.Stk Value, Tonnage</li>
                    </ul>
                </div>

                <div style="margin-top:24px; display:flex; gap:12px; flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="fa-solid fa-cloud-arrow-up"></i> Upload Stock Data
                    </button>
                    <button type="reset" class="btn btn-outline" onclick="resetForm()">
                        <i class="fa-solid fa-rotate-left"></i> Reset
                    </button>
                </div>
            </form>
        </div>

        <!-- Recent Uploads -->
        <div class="upload-card" style="padding:20px;">
            <h2><i class="fa-solid fa-clock-rotate-left"></i> Recent Uploads</h2>
            <?php if (count($recent_uploads) > 0): ?>
                <table class="mini-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Rows</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_uploads as $u): ?>
                        <tr>
                            <td>
                                <div style="font-weight:600;color:#111;"><?php echo date('d M Y', strtotime($u['delivery_date'])); ?></div>
                                <div style="font-size:11px;color:#9ca3af;"><?php echo htmlspecialchars($u['upload_ref']); ?></div>
                            </td>
                            <td><span class="badge badge-blue"><?php echo number_format($u['total_rows']); ?></span></td>
                            <td>
                                <a href="daily_stock_view.php?id=<?php echo $u['id']; ?>"
                                   title="View" style="color:#2563eb;font-size:14px;">
                                    <i class="fa-solid fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div style="margin-top:14px;">
                    <a href="daily_stock_list.php" style="font-size:13px;color:#2563eb;font-weight:500;">
                        View all uploads <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>
            <?php else: ?>
                <div style="text-align:center;padding:30px 0;color:#9ca3af;font-size:13px;">
                    <i class="fa-solid fa-inbox" style="font-size:28px;display:block;margin-bottom:8px;"></i>
                    No uploads yet
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Drag & drop
const dropZone = document.getElementById('dropZone');
const stockFile = document.getElementById('stockFile');

['dragenter','dragover'].forEach(e => {
    dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.add('dragover'); });
});
['dragleave','drop'].forEach(e => {
    dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.remove('dragover'); });
});
dropZone.addEventListener('drop', e => {
    const files = e.dataTransfer.files;
    if (files.length) { stockFile.files = files; updateFileName(files[0].name); }
});
stockFile.addEventListener('change', function() {
    if (this.files.length) updateFileName(this.files[0].name);
});
function updateFileName(name) {
    document.getElementById('fileName').textContent = name;
    document.getElementById('fileSelected').style.display = 'block';
    document.getElementById('dropContent').style.display = 'none';
}
function resetForm() {
    document.getElementById('fileSelected').style.display = 'none';
    document.getElementById('dropContent').style.display = 'block';
}
// Show loader on submit
document.getElementById('uploadForm').addEventListener('submit', function() {
    const btn = document.getElementById('submitBtn');
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Uploading & Processing...';
    btn.disabled = true;
    if (typeof showLoader === 'function') showLoader();
});
</script>

<?php include 'footer.php'; ?>
