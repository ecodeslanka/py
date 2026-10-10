
<?php
// ============================================================
//  sampath_damage_import.php  –  Import Damage Return Excel
//  Pure-PHP xlsx parser – no shell_exec / no external tools
// ============================================================
include 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$session_user = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'System';

// ── Ensure tables exist ──────────────────────────────────────

// ── Ensure tables exist ──────────────────────────────────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sampath_damage_batches (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    batch_ref VARCHAR(60) NOT NULL UNIQUE,
    import_date DATE NOT NULL,
    return_date DATE NULL,
    imported_by VARCHAR(100) NOT NULL,
    total_grns INT(11) DEFAULT 0,
    total_items INT(11) DEFAULT 0,
    total_value DECIMAL(14,2) DEFAULT 0.00,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_batch_ref (batch_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Add return_date column if it doesn't exist (for upgrades)
try {
    mysqli_query($conn, "ALTER TABLE sampath_damage_batches ADD COLUMN return_date DATE NULL AFTER import_date");
} catch (mysqli_sql_exception $e) {
    // Column already exists — safe to ignore
}

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sampath_damage_grn_headers (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    batch_id INT(11) NOT NULL,
    grn_no VARCHAR(60) NOT NULL,
    grn_branch_code VARCHAR(20) NOT NULL,
    branch_id INT(11) NULL,
    supplier VARCHAR(255) NOT NULL,
    grn_date DATE NOT NULL,
    total_qty INT(11) DEFAULT 0,
    total_value DECIMAL(14,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (batch_id) REFERENCES sampath_damage_batches(id) ON DELETE CASCADE,
    INDEX idx_grn_no (grn_no),
    INDEX idx_branch_code (grn_branch_code),
    INDEX idx_branch_id (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sampath_damage_grn_items (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    grn_header_id INT(11) NOT NULL,
    line_no INT(11) NOT NULL,
    product_code VARCHAR(50) NOT NULL,
    product_name VARCHAR(500) NOT NULL,
    location VARCHAR(255) NULL,
    unit_price DECIMAL(12,4) NOT NULL DEFAULT 0,
    qty INT(11) NOT NULL DEFAULT 0,
    line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (grn_header_id) REFERENCES sampath_damage_grn_headers(id) ON DELETE CASCADE,
    INDEX idx_product_code (product_code),
    INDEX idx_grn_header (grn_header_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");



// ============================================================
//  Pure-PHP xlsx parser (ZipArchive + SimpleXML)
//  Works on shared hosting — no shell_exec needed
// ============================================================
function parseXlsxGRNs(string $filepath): array {
    if (!class_exists('ZipArchive')) {
        return ['__error' => 'ZipArchive PHP extension is not available on this server.'];
    }
    $zip = new ZipArchive();
    if ($zip->open($filepath) !== true) {
        return ['__error' => 'Cannot open the uploaded xlsx file.'];
    }

    // 1. Shared strings table
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        // Strip namespaces so SimpleXML can walk freely
        $ssXml = preg_replace('/xmlns[^=]*="[^"]*"/i', '', $ssXml);
        $ssXml = preg_replace('/<\?xml[^>]*\?>/i', '', $ssXml);
        libxml_use_internal_errors(true);
        $ss = simplexml_load_string($ssXml);
        if ($ss) {
            foreach ($ss->si as $si) {
                // Rich text: concatenate all <r><t> children; plain: just <t>
                $text = '';
                if (isset($si->r)) {
                    foreach ($si->r as $r) {
                        if (isset($r->t)) $text .= (string)$r->t;
                    }
                }
                if ($text === '' && isset($si->t)) $text = (string)$si->t;
                $sharedStrings[] = $text;
            }
        }
    }

    // 2. Sheet data
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if (!$sheetXml) return ['__error' => 'sheet1.xml not found inside the xlsx file.'];

    $sheetXml = preg_replace('/xmlns[^=]*="[^"]*"/i', '', $sheetXml);
    $sheetXml = preg_replace('/<\?xml[^>]*\?>/i', '', $sheetXml);
    libxml_use_internal_errors(true);
    $sheet = simplexml_load_string($sheetXml);
    if (!$sheet) return ['__error' => 'Cannot parse sheet XML.'];

    // 3. Build row → col array
    $rows_raw = [];
    foreach ($sheet->sheetData->row as $row) {
        $rowIdx = (int)$row['r'];
        $cells  = [];
        foreach ($row->c as $cell) {
            $ref   = (string)$cell['r'];
            $type  = (string)$cell['t'];
            $v     = isset($cell->v) ? (string)$cell->v : '';

            // Column letter(s) → 0-based index
            preg_match('/^([A-Z]+)/', strtoupper($ref), $m);
            $colIdx = 0;
            foreach (str_split($m[1]) as $ch) $colIdx = $colIdx * 26 + (ord($ch) - 64);
            $colIdx--; // 0-based

            if ($type === 's') {
                $val = $sharedStrings[(int)$v] ?? '';
            } elseif ($v !== '') {
                $val = is_numeric($v) ? $v + 0 : $v;
            } else {
                $val = null;
            }
            $cells[$colIdx] = $val;
        }
        $rows_raw[$rowIdx] = $cells;
    }

    // 4. Parse GRN blocks
    $grns   = [];
    $curIdx = -1;
    foreach ($rows_raw as $cells) {
        $col0 = $cells[0] ?? null;

        if (is_string($col0) && stripos($col0, 'GRN No:') === 0) {
            $grn_no   = trim(str_ireplace('GRN No:', '', $col0));
            $supplier = trim(str_ireplace('Supplier:', '', (string)($cells[1] ?? '')));
            $date_str = trim(str_ireplace('Date:', '', (string)($cells[2] ?? '')));
            $branch   = (strpos($grn_no, '/') !== false) ? explode('/', $grn_no)[0] : $grn_no;
            $grns[]   = [
                'grn_no'          => $grn_no,
                'supplier'        => $supplier,
                'grn_date'        => $date_str,
                'grn_branch_code' => $branch,
                'items'           => [],
            ];
            $curIdx = count($grns) - 1;

        } elseif ($curIdx >= 0 && is_int($col0) && $col0 > 0) {
            $grns[$curIdx]['items'][] = [
                'line_no'      => (int)$col0,
                'product_code' => trim((string)($cells[1] ?? '')),
                'product_name' => trim((string)($cells[3] ?? '')),
                'location'     => trim((string)($cells[4] ?? '')),
                'unit_price'   => (float)($cells[5] ?? 0),
                'qty'          => (int)($cells[6] ?? 0),
                'line_total'   => (float)($cells[7] ?? 0),
            ];
        }
    }
    return $grns;
}

// ── Resolve branch_id ────────────────────────────────────────
function resolveBranchId($conn, string $branch_code): ?int {
    $bc = mysqli_real_escape_string($conn, $branch_code);
    // Try sampath_outlet_code first, fallback to t_code prefix match
    $r = mysqli_query($conn, "SELECT id FROM customers WHERE sampath_outlet_code='$bc' LIMIT 1");
    if ($r && $row = mysqli_fetch_assoc($r)) return (int)$row['id'];
    $r = mysqli_query($conn, "SELECT id FROM customers WHERE t_code='$bc' LIMIT 1");
    if ($r && $row = mysqli_fetch_assoc($r)) return (int)$row['id'];
    return null;
}

// ── Generate batch reference ─────────────────────────────────
function genBatchRef($conn): string {
    $date = date('Ymd');
    $r    = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM sampath_damage_batches WHERE DATE(created_at) = CURDATE()");
    $n    = ($r ? (int)mysqli_fetch_assoc($r)['cnt'] : 0) + 1;
    return 'SDMG-' . $date . '-' . str_pad($n, 3, '0', STR_PAD_LEFT);
}

$msg = ''; $msg_type = ''; $preview = null; $preview_file = '';
$post_notes = ''; $post_return_date = date('Y-m-d');

// ── Step 2: Confirm & Save ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $tmp_path          = $_POST['tmp_path']    ?? '';
    $notes             = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');
    $return_date_input = mysqli_real_escape_string($conn, $_POST['return_date'] ?? date('Y-m-d'));
    $batch_ref         = genBatchRef($conn);

    if (!file_exists($tmp_path)) {
        $msg = 'Session expired — please re-upload the file.'; $msg_type = 'danger';
    } else {
        $grns = parseXlsxGRNs($tmp_path);
        if (isset($grns['__error'])) {
            $msg = 'Parse error: ' . htmlspecialchars($grns['__error']); $msg_type = 'danger';
        } else {
            $total_grns  = count($grns);
            $total_items = array_sum(array_map(fn($g) => count($g['items']), $grns));
            $total_value = array_sum(array_map(fn($g) => array_sum(array_column($g['items'], 'line_total')), $grns));
            $import_date = date('Y-m-d');

            mysqli_begin_transaction($conn);
            try {
                $user_esc = mysqli_real_escape_string($conn, $session_user);
                mysqli_query($conn, "INSERT INTO sampath_damage_batches
                    (batch_ref, import_date, return_date, imported_by, total_grns, total_items, total_value, notes)
                    VALUES ('$batch_ref', '$import_date', '$return_date_input', '$user_esc',
                    $total_grns, $total_items, " . round($total_value, 2) . ", '$notes')");
                $batch_id = (int)mysqli_insert_id($conn);

                foreach ($grns as $g) {
                    $grn_no    = mysqli_real_escape_string($conn, $g['grn_no']);
                    $bc        = mysqli_real_escape_string($conn, $g['grn_branch_code']);
                    $supplier  = mysqli_real_escape_string($conn, $g['supplier']);
                    $gdate     = mysqli_real_escape_string($conn, $g['grn_date']);
                    $branch_id = resolveBranchId($conn, $g['grn_branch_code']);
                    $bid_sql   = $branch_id ? $branch_id : 'NULL';
                    $g_qty     = array_sum(array_column($g['items'], 'qty'));
                    $g_val     = round(array_sum(array_column($g['items'], 'line_total')), 2);

                    mysqli_query($conn, "INSERT INTO sampath_damage_grn_headers
                        (batch_id, grn_no, grn_branch_code, branch_id, supplier, grn_date, total_qty, total_value)
                        VALUES ($batch_id, '$grn_no', '$bc', $bid_sql, '$supplier', '$gdate', $g_qty, $g_val)");
                    $header_id = (int)mysqli_insert_id($conn);

                    foreach ($g['items'] as $item) {
                        $ln  = (int)$item['line_no'];
                        $pc  = mysqli_real_escape_string($conn, $item['product_code']);
                        $pn  = mysqli_real_escape_string($conn, $item['product_name']);
                        $loc = mysqli_real_escape_string($conn, $item['location']);
                        $up  = (float)$item['unit_price'];
                        $qty = (int)$item['qty'];
                        $lt  = round((float)$item['line_total'], 2);
                        mysqli_query($conn, "INSERT INTO sampath_damage_grn_items
                            (grn_header_id, line_no, product_code, product_name, location, unit_price, qty, line_total)
                            VALUES ($header_id, $ln, '$pc', '$pn', '$loc', $up, $qty, $lt)");
                    }
                }
                mysqli_commit($conn);
                @unlink($tmp_path);
                header("Location: sampath_damage_batches.php?imported=1&batch=" . urlencode($batch_ref));
                exit;
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $msg = 'Database error: ' . htmlspecialchars($e->getMessage()); $msg_type = 'danger';
            }
        }
    }
}

// ── Step 1: Upload & Preview ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
    $ext = strtolower(pathinfo($_FILES['excel_file']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx'])) {
        $msg = 'Only .xlsx files are supported (shared hosting; .xls is not supported).'; $msg_type = 'danger';
    } else {
        $tmp_dest = sys_get_temp_dir() . '/sdmg_' . uniqid() . '.xlsx';
        if (move_uploaded_file($_FILES['excel_file']['tmp_name'], $tmp_dest)) {
            $grns = parseXlsxGRNs($tmp_dest);
            if (isset($grns['__error'])) {
                $msg = 'Parse error: ' . htmlspecialchars($grns['__error']); $msg_type = 'danger';
                @unlink($tmp_dest);
            } else {
                $preview      = $grns;
                $preview_file = $tmp_dest;
                $post_return_date = $_POST['return_date'] ?? date('Y-m-d');
                $post_notes       = $_POST['notes'] ?? '';
            }
        } else {
            $msg = 'File upload failed. Check server tmp directory permissions.'; $msg_type = 'danger';
        }
    }
}

include 'header.php';
?>
<style>
*{box-sizing:border-box;}
:root{
    --ink:#0f172a;--muted:#64748b;--border:#e2e8f0;--bg:#f8fafc;
    --accent:#0ea5e9;--accent-dark:#0284c7;--success:#10b981;
    --warn:#f59e0b;--danger:#ef4444;--white:#fff;
    --radius:10px;--shadow:0 2px 12px rgba(15,23,42,.07);
}
.sdmg-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;flex-wrap:wrap;gap:12px;}
.sdmg-title{font-size:22px;font-weight:800;color:var(--ink);margin:0 0 3px;}
.sdmg-sub{font-size:13px;color:var(--muted);margin:0;}
.sdmg-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;margin-bottom:20px;}
.sdmg-card-h{display:flex;align-items:center;gap:10px;padding:14px 20px;border-bottom:1px solid var(--border);background:#f1f5f9;font-size:14px;font-weight:700;color:var(--ink);}
.sdmg-card-b{padding:22px;}

/* Alert */
.sdmg-alert{display:flex;align-items:flex-start;gap:10px;padding:12px 16px;border-radius:8px;font-size:13px;margin-bottom:16px;}
.sdmg-alert-danger {background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}
.sdmg-alert-success{background:#dcfce7;border:1px solid #bbf7d0;color:#166534;}
.sdmg-alert-info   {background:#e0f2fe;border:1px solid #bae6fd;color:#075985;}

/* Upload zone */
.upload-zone{border:2px dashed #cbd5e1;border-radius:12px;padding:48px 24px;text-align:center;background:#f8fafc;cursor:pointer;transition:all .2s;position:relative;}
.upload-zone:hover,.upload-zone.drag{border-color:var(--accent);background:#f0f9ff;}
.upload-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}
.upload-icon{font-size:40px;margin-bottom:12px;display:block;}
.upload-title{font-size:16px;font-weight:700;color:var(--ink);margin-bottom:6px;}
.upload-hint{font-size:13px;color:var(--muted);}
#fileLoading{display:none;align-items:center;gap:8px;padding:10px 0;font-size:13px;color:var(--muted);}
#fileLoading .spin{display:inline-block;width:16px;height:16px;border:2px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:spin .7s linear infinite;}
@keyframes spin{to{transform:rotate(360deg)}}

/* Form fields */
.sdmg-label{display:block;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;}
.sdmg-input{width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;color:var(--ink);background:var(--white);transition:border-color .15s;}
.sdmg-input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(14,165,233,.1);}
.sdmg-textarea{width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;resize:vertical;min-height:68px;}
.sdmg-textarea:focus{outline:none;border-color:var(--accent);}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.form-group{margin-bottom:14px;}

/* Stats */
.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;}
.stat-card{background:#f8fafc;border:1px solid var(--border);border-radius:8px;padding:14px 16px;text-align:center;}
.stat-label{font-size:10px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);font-weight:700;margin-bottom:6px;}
.stat-val{font-size:20px;font-weight:800;color:var(--ink);}
.stat-val.blue{color:var(--accent);}
.stat-val.green{color:var(--success);}
.stat-val.amber{color:var(--warn);}

/* GRN preview */
.grn-list{display:flex;flex-direction:column;gap:8px;}
.grn-row{border:1px solid var(--border);border-radius:8px;overflow:hidden;}
.grn-row-h{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#fafafa;cursor:pointer;gap:12px;flex-wrap:wrap;}
.grn-row-h:hover{background:#f0f9ff;}
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-branch{background:#dbeafe;color:#1e40af;}
.badge-items{background:#dcfce7;color:#166534;}
.badge-val{background:#fef3c7;color:#92400e;}
.badge-no-match{background:#fee2e2;color:#991b1b;}
.badge-matched{background:#dcfce7;color:#166534;}
.grn-expand{display:none;border-top:1px solid var(--border);}
.grn-expand.open{display:block;}
.items-table{width:100%;border-collapse:collapse;font-size:12px;}
.items-table th{padding:7px 10px;background:#f1f5f9;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.3px;color:var(--muted);font-weight:700;}
.items-table td{padding:7px 10px;border-top:1px solid #f1f5f9;color:var(--ink);}
.items-table tr:hover td{background:#f8fafc;}

/* Buttons */
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:all .18s;text-decoration:none;font-family:inherit;white-space:nowrap;}
.btn-primary{background:var(--accent);color:#fff;}.btn-primary:hover{background:var(--accent-dark);}
.btn-success{background:var(--success);color:#fff;}.btn-success:hover{background:#059669;}
.btn-ghost{background:#f1f5f9;color:var(--ink);border:1px solid var(--border);}.btn-ghost:hover{background:#e2e8f0;}

/* Date highlight in meta bar */
.meta-bar{display:flex;align-items:center;flex-wrap:wrap;gap:16px;padding:12px 18px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;margin-bottom:16px;font-size:13px;}
.meta-bar strong{color:var(--ink);}

@media(max-width:700px){
    .stat-grid{grid-template-columns:1fr 1fr;}
    .form-row{grid-template-columns:1fr;}
    .grn-row-h{flex-direction:column;align-items:flex-start;}
}
</style>

<div class="sdmg-header">
    <div>
        <h2 class="sdmg-title"><i class="fa-solid fa-file-import" style="color:var(--accent);"></i> Sampath Damage Return Import</h2>
        <p class="sdmg-sub">Import damage return GRN data from YELO Distributors Excel (.xlsx)</p>
    </div>
    <a href="sampath_damage_batches.php" class="btn btn-ghost"><i class="fa-solid fa-list"></i> View Batches</a>
</div>

<?php if ($msg): ?>
<div class="sdmg-alert sdmg-alert-<?php echo $msg_type; ?>">
    <i class="fa-solid fa-<?php echo $msg_type==='danger'?'circle-xmark':'circle-check'; ?>" style="font-size:16px;flex-shrink:0;margin-top:1px;"></i>
    <div><?php echo $msg; ?></div>
</div>
<?php endif; ?>

<?php if (!$preview): ?>
<!-- ══ STEP 1: UPLOAD ═══════════════════════════════════════ -->
<div class="sdmg-card">
    <div class="sdmg-card-h"><i class="fa-solid fa-cloud-arrow-up" style="color:var(--accent);"></i> Upload & Configure</div>
    <div class="sdmg-card-b">
        <form method="POST" enctype="multipart/form-data" id="uploadForm">

            <div class="form-row" style="margin-bottom:18px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="sdmg-label"><i class="fa-solid fa-calendar-day"></i> Return / Batch Date <span style="color:#ef4444;">*</span></label>
                    <input type="date" name="return_date" class="sdmg-input" value="<?php echo htmlspecialchars($post_return_date); ?>" required>
                    <small style="font-size:11px;color:var(--muted);display:block;margin-top:4px;">The physical return date for this batch</small>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="sdmg-label"><i class="fa-solid fa-note-sticky"></i> Notes (Optional)</label>
                    <input type="text" name="notes" class="sdmg-input" placeholder="e.g. May return run 1" value="">
                </div>
            </div>

            <div class="upload-zone" id="uploadZone">
                <input type="file" name="excel_file" id="excelFile" accept=".xlsx" onchange="handleFileSelect(this)">
                <span class="upload-icon">📊</span>
                <div class="upload-title" id="uploadTitle">Drop .xlsx file here or click to browse</div>
                <div class="upload-hint">Sampath damage return format — YELO Distributors. <strong>Only .xlsx</strong></div>
            </div>
            <div id="fileLoading" style="display:none;align-items:center;gap:8px;padding:10px 0;font-size:13px;color:var(--muted);">
                <span class="spin"></span> Parsing Excel file, please wait…
            </div>
            <div style="margin-top:14px;">
                <div class="sdmg-alert sdmg-alert-info" style="margin-bottom:0;">
                    <i class="fa-solid fa-circle-info" style="font-size:15px;flex-shrink:0;"></i>
                    <div>GRN No, Supplier, Date, and all line items are extracted automatically. Branch code (<code>DS01</code> from <code>DS01/VDSR/000113</code>) is matched to Sampath outlet customers. No Python or shell commands required.</div>
                </div>
            </div>
        </form>
    </div>
</div>

<?php else: ?>
<!-- ══ STEP 2: PREVIEW & CONFIRM ════════════════════════════ -->
<?php
$total_grns  = count($preview);
$total_items = array_sum(array_map(fn($g) => count($g['items']), $preview));
$total_value = array_sum(array_map(fn($g) => array_sum(array_column($g['items'], 'line_total')), $preview));
?>

<div class="sdmg-alert sdmg-alert-success">
    <i class="fa-solid fa-circle-check" style="font-size:16px;flex-shrink:0;"></i>
    <div>Excel parsed successfully — <strong><?php echo $total_grns; ?> GRNs</strong> with <strong><?php echo $total_items; ?> line items</strong> ready to import. Review and confirm below.</div>
</div>

<!-- Summary stats -->
<div class="stat-grid">
    <div class="stat-card"><div class="stat-label">Total GRNs</div><div class="stat-val blue"><?php echo $total_grns; ?></div></div>
    <div class="stat-card"><div class="stat-label">Line Items</div><div class="stat-val"><?php echo $total_items; ?></div></div>
    <div class="stat-card"><div class="stat-label">Total Value</div><div class="stat-val amber">LKR <?php echo number_format($total_value, 2); ?></div></div>
    <div class="stat-card"><div class="stat-label">Branches</div><div class="stat-val green"><?php echo count(array_unique(array_column($preview, 'grn_branch_code'))); ?></div></div>
</div>

<form method="POST" id="saveForm">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="tmp_path" value="<?php echo htmlspecialchars($preview_file); ?>">

    <!-- Batch config -->
    <div class="sdmg-card" style="margin-bottom:14px;">
        <div class="sdmg-card-h"><i class="fa-solid fa-sliders" style="color:var(--warn);"></i> Batch Configuration</div>
        <div class="sdmg-card-b">
            <div class="form-row">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="sdmg-label"><i class="fa-solid fa-calendar-day"></i> Return / Batch Date <span style="color:#ef4444;">*</span></label>
                    <input type="date" name="return_date" class="sdmg-input" value="<?php echo htmlspecialchars($post_return_date); ?>" required>
                    <small style="font-size:11px;color:var(--muted);display:block;margin-top:4px;">Saved against this batch for reference</small>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="sdmg-label"><i class="fa-solid fa-note-sticky"></i> Batch Notes (Optional)</label>
                    <textarea name="notes" class="sdmg-textarea" placeholder="Any notes about this import batch…"><?php echo htmlspecialchars($post_notes); ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <!-- GRN Preview -->
    <div class="sdmg-card">
        <div class="sdmg-card-h">
            <i class="fa-solid fa-receipt" style="color:var(--success);"></i>
            <span>GRN Preview</span>
            <span style="font-size:12px;font-weight:500;color:var(--muted);margin-left:auto;">Click any row to expand items</span>
        </div>
        <div class="sdmg-card-b">
            <div class="grn-list">
                <?php foreach ($preview as $gi => $g):
                    $g_qty = array_sum(array_column($g['items'], 'qty'));
                    $g_val = array_sum(array_column($g['items'], 'line_total'));
                    $bc    = mysqli_real_escape_string($conn, $g['grn_branch_code']);
                    $br    = mysqli_query($conn, "SELECT id, shop_name FROM customers WHERE sampath_outlet_code='$bc' LIMIT 1");
                    if (!$br || !($branch_match = mysqli_fetch_assoc($br))) {
                        $br2 = mysqli_query($conn, "SELECT id, shop_name FROM customers WHERE t_code='$bc' LIMIT 1");
                        $branch_match = $br2 ? mysqli_fetch_assoc($br2) : null;
                    }
                ?>
                <div class="grn-row">
                    <div class="grn-row-h" onclick="toggleGRN(<?php echo $gi; ?>)">
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <span style="font-weight:700;font-size:13px;"><?php echo htmlspecialchars($g['grn_no']); ?></span>
                            <span class="badge badge-branch"><?php echo htmlspecialchars($g['grn_branch_code']); ?></span>
                            <?php if ($branch_match): ?>
                            <span class="badge badge-matched"><i class="fa-solid fa-link"></i> <?php echo htmlspecialchars($branch_match['shop_name']); ?></span>
                            <?php else: ?>
                            <span class="badge badge-no-match"><i class="fa-solid fa-unlink"></i> No customer match</span>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <span style="font-size:12px;color:var(--muted);"><?php echo htmlspecialchars($g['grn_date']); ?></span>
                            <span class="badge badge-items"><?php echo count($g['items']); ?> items</span>
                            <span class="badge badge-val">LKR <?php echo number_format($g_val, 2); ?></span>
                            <i class="fa-solid fa-chevron-down" id="chevron<?php echo $gi; ?>" style="color:var(--muted);font-size:11px;transition:transform .2s;"></i>
                        </div>
                    </div>
                    <div class="grn-expand" id="grn-expand-<?php echo $gi; ?>">
                        <table class="items-table">
                            <thead><tr>
                                <th>#</th><th>Code</th><th>Product Name</th>
                                <th>Location</th>
                                <th style="text-align:right;">Unit Price</th>
                                <th style="text-align:right;">Qty</th>
                                <th style="text-align:right;">Total</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($g['items'] as $item): ?>
                            <tr>
                                <td><?php echo $item['line_no']; ?></td>
                                <td><code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:11px;"><?php echo htmlspecialchars($item['product_code']); ?></code></td>
                                <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                <td style="font-size:11px;color:var(--muted);"><?php echo htmlspecialchars($item['location']); ?></td>
                                <td style="text-align:right;"><?php echo number_format($item['unit_price'], 2); ?></td>
                                <td style="text-align:right;font-weight:700;"><?php echo $item['qty']; ?></td>
                                <td style="text-align:right;font-weight:700;color:var(--accent-dark);"><?php echo number_format($item['line_total'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                            <tr style="background:#f1f5f9;font-weight:700;">
                                <td colspan="5" style="padding:7px 10px;font-size:12px;color:var(--muted);">Subtotal</td>
                                <td style="padding:7px 10px;text-align:right;"><?php echo $g_qty; ?></td>
                                <td style="padding:7px 10px;text-align:right;color:var(--accent-dark);">LKR <?php echo number_format($g_val, 2); ?></td>
                            </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:4px;">
        <button type="submit" class="btn btn-success" id="saveBtn">
            <i class="fa-solid fa-database"></i> Confirm & Save (<?php echo $total_grns; ?> GRNs · <?php echo $total_items; ?> Items)
        </button>
        <a href="sampath_damage_import.php" class="btn btn-ghost"><i class="fa-solid fa-rotate-left"></i> Start Over</a>
        <span style="font-size:12px;color:var(--muted);margin-left:auto;">
            <i class="fa-solid fa-shield-halved"></i> Batch ref: <strong>SDMG-<?php echo date('Ymd'); ?>-###</strong>
        </span>
    </div>
</form>
<?php endif; ?>

<script>
function handleFileSelect(input) {
    if (!input.files.length) return;
    document.getElementById('uploadTitle').textContent = '📄 ' + input.files[0].name + ' — uploading…';
    document.getElementById('fileLoading').style.display = 'flex';
    document.getElementById('uploadZone').style.pointerEvents = 'none';
    document.getElementById('uploadForm').submit();
}
function toggleGRN(idx) {
    const el  = document.getElementById('grn-expand-' + idx);
    const ch  = document.getElementById('chevron' + idx);
    const open = el.classList.toggle('open');
    ch.style.transform = open ? 'rotate(180deg)' : '';
}
const zone = document.getElementById('uploadZone');
if (zone) {
    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('drag'));
    zone.addEventListener('drop', e => { e.preventDefault(); zone.classList.remove('drag'); });
}
const saveBtn = document.getElementById('saveBtn');
if (saveBtn) {
    saveBtn.addEventListener('click', function(e) {
        e.preventDefault();
        if (confirm('Save this import batch to the database?\n\nThis cannot be undone.')) {
            this.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
            this.disabled = true;
            document.getElementById('saveForm').submit();
        }
    });
}
</script>
<?php include 'footer.php'; ?>
