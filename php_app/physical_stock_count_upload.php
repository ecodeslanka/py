<?php
// ============================================================
//  physical_stock_count_upload.php
//  PHYSICAL STOCK COUNT — UPLOAD
//  Upload the physical stock count Excel (Sr, Division, SKU, Product Name,
//  No, UPC, Unit, Phy Cases, Phy Pieces), tag it with a Stock Count Date,
//  then view the uploaded details with Total Units computed as:
//      Total Units = (Phy Cases x UPC) + Phy Pieces
// ============================================================
if (function_exists('mysqli_report')) { mysqli_report(MYSQLI_REPORT_OFF); }
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();

include 'config.php';
require_once __DIR__ . '/SimpleXLSX.php';
if (session_status() === PHP_SESSION_NONE) session_start();

/* ── Ensure tables exist ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `physical_stock_count_batches` (
    `id`            INT(11)       NOT NULL AUTO_INCREMENT,
    `count_date`    DATE          NOT NULL,
    `filename`      VARCHAR(255)  NOT NULL,
    `total_rows`    INT(11)       NOT NULL DEFAULT 0,
    `total_cases`   DECIMAL(15,2) NOT NULL DEFAULT 0,
    `total_pieces`  DECIMAL(15,2) NOT NULL DEFAULT 0,
    `total_units`   DECIMAL(15,2) NOT NULL DEFAULT 0,
    `skipped_rows`  INT(11)       NOT NULL DEFAULT 0,
    `uploaded_by`   VARCHAR(100)  DEFAULT NULL,
    `uploaded_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `note`          TEXT          DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_count_date` (`count_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `physical_stock_count_items` (
    `id`             INT(11)       NOT NULL AUTO_INCREMENT,
    `batch_id`       INT(11)       NOT NULL,
    `count_date`     DATE          NOT NULL,
    `sr_no`          INT(11)       DEFAULT NULL,
    `division`       VARCHAR(100)  DEFAULT NULL,
    `sku`            VARCHAR(50)   DEFAULT NULL,
    `product_name`   VARCHAR(500)  DEFAULT NULL,
    `no_per_case`    DECIMAL(15,2) DEFAULT NULL,
    `upc`            DECIMAL(15,2) DEFAULT NULL,
    `book_unit`      DECIMAL(15,2) DEFAULT NULL,
    `phy_cases`      DECIMAL(15,2) NOT NULL DEFAULT 0,
    `phy_pieces`     DECIMAL(15,2) NOT NULL DEFAULT 0,
    `total_units`    DECIMAL(15,2) NOT NULL DEFAULT 0,
    `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_batch` (`batch_id`),
    KEY `idx_count_date` (`count_date`),
    KEY `idx_sku` (`sku`),
    CONSTRAINT `fk_pscb_batch` FOREIGN KEY (`batch_id`)
        REFERENCES `physical_stock_count_batches`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$session_user = $_SESSION['username'] ?? 'System';

function pscNum($v) {
    if ($v === null || $v === '') return 0.0;
    $v = str_replace(',', '', trim((string)$v));
    return is_numeric($v) ? (float)$v : 0.0;
}
function pscStr($v, $max = 500) {
    if ($v === null) return null;
    $s = trim((string)$v);
    return $s === '' ? null : mb_substr($s, 0, $max);
}

/* ── AJAX: upload & parse ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    include 'auth.php';
    requireLogin();
    header('Content-Type: application/json');

    $count_date = trim($_POST['count_date'] ?? '');
    $note       = trim($_POST['note'] ?? '');
    if (!$count_date || !strtotime($count_date)) {
        echo json_encode(['ok' => false, 'msg' => 'Please select a valid Stock Count Date.']);
        exit;
    }
    if (!isset($_FILES['xlsx_file']) || $_FILES['xlsx_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'msg' => 'File upload failed. Please try again.']);
        exit;
    }
    $file = $_FILES['xlsx_file'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls'])) {
        echo json_encode(['ok' => false, 'msg' => 'Only .xlsx or .xls files are allowed.']);
        exit;
    }

    $xlsx = SimpleXLSX::parse($file['tmp_name']);
    if (!$xlsx) {
        echo json_encode(['ok' => false, 'msg' => 'Could not read the uploaded file — please check it is a valid Excel export.']);
        exit;
    }
    $rows = $xlsx->rows(0);
    if (!$rows) {
        echo json_encode(['ok' => false, 'msg' => 'The file appears to be empty.']);
        exit;
    }

    // Find header row — needs "sku" and "product name" together
    $header_row_idx = null;
    foreach ($rows as $ri => $row) {
        $found_sku = false; $found_name = false;
        foreach ($row as $cell) {
            $c = strtolower(trim((string)$cell));
            if ($c === 'sku') $found_sku = true;
            if ($c === 'product name') $found_name = true;
        }
        if ($found_sku && $found_name) { $header_row_idx = $ri; break; }
    }
    if ($header_row_idx === null) {
        echo json_encode(['ok' => false, 'msg' => 'Could not find the header row. Expected columns "SKU" and "Product Name" — please upload the Physical Stock Count sheet.']);
        exit;
    }

    $header = $rows[$header_row_idx];
    $col = [];
    foreach ($header as $ci => $h) { $col[strtolower(trim((string)$h))] = $ci; }
    $g = function ($row, $key) use ($col) {
        if (!isset($col[$key]) || !isset($row[$col[$key]])) return null;
        $v = $row[$col[$key]];
        return $v === '' ? null : $v;
    };

    $count_date_esc = mysqli_real_escape_string($conn, $count_date);
    $fname_esc      = mysqli_real_escape_string($conn, $file['name']);
    $note_esc       = mysqli_real_escape_string($conn, $note);
    $user_esc       = mysqli_real_escape_string($conn, $session_user);

    mysqli_query($conn, "INSERT INTO physical_stock_count_batches
        (count_date, filename, uploaded_by, note) VALUES ('$count_date_esc', '$fname_esc', '$user_esc', " . ($note_esc !== '' ? "'$note_esc'" : 'NULL') . ")");
    $batch_id = mysqli_insert_id($conn);
    if (!$batch_id) {
        echo json_encode(['ok' => false, 'msg' => 'Failed to create batch record.']);
        exit;
    }

    $inserted = 0; $skipped = 0;
    $sum_cases = 0.0; $sum_pieces = 0.0; $sum_units = 0.0;
    $vals = [];
    $flush = function () use (&$vals, $conn) {
        if (!$vals) return;
        mysqli_query($conn, "INSERT INTO physical_stock_count_items
            (batch_id, count_date, sr_no, division, sku, product_name, no_per_case, upc, book_unit, phy_cases, phy_pieces, total_units)
            VALUES " . implode(',', $vals));
        $vals = [];
    };

    for ($ri = $header_row_idx + 1; $ri < count($rows); $ri++) {
        $row = $rows[$ri];
        $sku  = pscStr($g($row, 'sku'), 50);
        $name = pscStr($g($row, 'product name'), 500);

        $rowIsBlank = true;
        foreach ($row as $cv) { if ($cv !== null && trim((string)$cv) !== '') { $rowIsBlank = false; break; } }
        // Every real stock-count line has an SKU — this also catches the trailing
        // "Grand Total" / "Printed Grand Total" / "Difference" summary rows some
        // sheets have at the bottom, which carry a number but no SKU.
        if ($rowIsBlank || $sku === null
            || ($name && stripos($name, 'grand total') !== false)
            || ($sku && stripos($sku, 'grand total') !== false)
        ) {
            $skipped++; continue;
        }

        $sr        = $g($row, 'sr');
        $division  = pscStr($g($row, 'division'), 100);
        $no_pc     = pscNum($g($row, 'no'));
        $upc       = pscNum($g($row, 'upc'));
        $book_unit = $g($row, 'unit');
        $phy_cases = pscNum($g($row, 'phy cases'));
        $phy_pieces = pscNum($g($row, 'phy pieces'));
        $total_units = ($phy_cases * $upc) + $phy_pieces;

        $sum_cases  += $phy_cases;
        $sum_pieces += $phy_pieces;
        $sum_units  += $total_units;

        $vals[] = '(' . $batch_id . ',' .
            "'$count_date_esc'," .
            (is_numeric($sr) ? (int)$sr : 'NULL') . ',' .
            ($division !== null ? "'" . mysqli_real_escape_string($conn, $division) . "'" : 'NULL') . ',' .
            ($sku !== null ? "'" . mysqli_real_escape_string($conn, $sku) . "'" : 'NULL') . ',' .
            ($name !== null ? "'" . mysqli_real_escape_string($conn, $name) . "'" : 'NULL') . ',' .
            $no_pc . ',' . $upc . ',' .
            (is_numeric($book_unit) ? (float)$book_unit : 'NULL') . ',' .
            $phy_cases . ',' . $phy_pieces . ',' . $total_units .
            ')';
        $inserted++;
        if (count($vals) >= 200) $flush();
    }
    $flush();

    if ($inserted === 0) {
        mysqli_query($conn, "DELETE FROM physical_stock_count_batches WHERE id=$batch_id");
        echo json_encode(['ok' => false, 'msg' => "No valid item rows found (skipped: $skipped). Please check the file format."]);
        exit;
    }

    mysqli_query($conn, "UPDATE physical_stock_count_batches SET
        total_rows=$inserted, total_cases=$sum_cases, total_pieces=$sum_pieces, total_units=$sum_units, skipped_rows=$skipped
        WHERE id=$batch_id");

    echo json_encode(['ok' => true, 'msg' => "Import complete — $inserted item(s) imported" . ($skipped ? " ($skipped row(s) skipped)" : '') . ".", 'batch_id' => $batch_id]);
    exit;
}

/* ── AJAX: delete batch ── */
if (isset($_GET['delete'])) {
    include 'auth.php';
    requireLogin();
    $bid = (int)$_GET['delete'];
    if ($bid > 0) mysqli_query($conn, "DELETE FROM physical_stock_count_batches WHERE id=$bid");
    header('Location: physical_stock_count_upload.php?deleted=1');
    exit;
}

include 'header.php';

/* ── VIEW ONE BATCH ── */
$view_id = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$view_batch = null; $view_items = [];
if ($view_id > 0) {
    $r = mysqli_query($conn, "SELECT * FROM physical_stock_count_batches WHERE id=$view_id");
    $view_batch = $r ? mysqli_fetch_assoc($r) : null;
    if ($view_batch) {
        $ir = mysqli_query($conn, "SELECT * FROM physical_stock_count_items WHERE batch_id=$view_id ORDER BY sr_no ASC, id ASC");
        if ($ir) while ($row = mysqli_fetch_assoc($ir)) { $view_items[] = $row; }
    }
}

/* ── LIST BATCHES (default view) ── */
$batches = [];
if (!$view_batch) {
    $r = mysqli_query($conn, "SELECT * FROM physical_stock_count_batches ORDER BY count_date DESC, id DESC LIMIT 100");
    if ($r) while ($row = mysqli_fetch_assoc($r)) { $batches[] = $row; }
}

$view_items_json = json_encode($view_items, JSON_NUMERIC_CHECK);
?>
<style>
*{box-sizing:border-box}
:root{--ink:#0f172a;--muted:#64748b;--border:#e2e8f0;--bg:#f8fafc;--accent:#0ea5e9;--accent-dark:#0284c7;--success:#10b981;--warn:#f59e0b;--danger:#ef4444;--white:#fff;--radius:10px;--shadow:0 2px 12px rgba(15,23,42,.07)}
.psc-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;flex-wrap:wrap;gap:12px}
.psc-title{font-size:22px;font-weight:800;color:var(--ink);margin:0 0 3px}
.psc-sub{font-size:13px;color:var(--muted);margin:0}
.psc-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;margin-bottom:20px}
.psc-card-h{display:flex;align-items:center;gap:10px;padding:14px 20px;border-bottom:1px solid var(--border);background:#f1f5f9;font-size:14px;font-weight:700;color:var(--ink);flex-wrap:wrap}
.psc-card-b{padding:22px}
.psc-alert{display:flex;align-items:flex-start;gap:10px;padding:12px 16px;border-radius:8px;font-size:13px;margin-bottom:16px}
.psc-alert-danger{background:#fee2e2;border:1px solid #fecaca;color:#991b1b}
.psc-alert-success{background:#dcfce7;border:1px solid #bbf7d0;color:#166534}
.psc-alert-info{background:#e0f2fe;border:1px solid #bae6fd;color:#075985}
.psc-label{display:block;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px}
.psc-input{width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;color:var(--ink);background:var(--white)}
.psc-input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(14,165,233,.1)}
.form-row{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:14px}
.form-group{flex:1;min-width:200px}
.upload-zone{border:2px dashed #cbd5e1;border-radius:12px;padding:36px 24px;text-align:center;background:#f8fafc;cursor:pointer;transition:all .2s;position:relative}
.upload-zone:hover,.upload-zone.drag{border-color:var(--accent);background:#f0f9ff}
.upload-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.upload-icon{font-size:34px;margin-bottom:8px;display:block}
.upload-title{font-size:15px;font-weight:700;color:var(--ink);margin-bottom:5px}
.upload-hint{font-size:12.5px;color:var(--muted)}
#fileLoading{display:none;align-items:center;gap:8px;padding:10px 0;font-size:13px;color:var(--muted)}
#fileLoading .spin{display:inline-block;width:16px;height:16px;border:2px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:all .18s;text-decoration:none;font-family:inherit;white-space:nowrap}
.btn-primary{background:#000;color:#fff}.btn-primary:hover{background:#333}
.btn-success{background:var(--success);color:#fff}.btn-success:hover{background:#059669}
.btn-ghost{background:#f1f5f9;color:var(--ink);border:1px solid var(--border)}.btn-ghost:hover{background:#e2e8f0}
.btn-danger{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}.btn-danger:hover{background:#fecaca}
.btn-sm{padding:7px 14px;font-size:12px}
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:20px}
.kpi-card{background:#fff;border:1px solid var(--border);border-radius:8px;padding:16px;position:relative;overflow:hidden}
.kpi-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px}
.kpi-blue::before{background:#3b82f6}.kpi-purple::before{background:#8b5cf6}.kpi-teal::before{background:#14b8a6}.kpi-orange::before{background:#f97316}.kpi-green::before{background:#22c55e}
.kpi-label{font-size:11px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
.kpi-value{font-size:20px;font-weight:700;color:var(--ink)}
.kpi-sub{font-size:11px;color:#9ca3af;margin-top:3px}
.gtable{width:100%;border-collapse:collapse;font-size:12.5px}
.gtable th{padding:10px 12px;background:#000;color:#fff;text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.3px;font-weight:700}
.gtable td{padding:9px 12px;border-top:1px solid #f1f5f9;color:var(--ink)}
.gtable tr:hover td{background:#f8fafc}
.gtable td.num,.gtable th.num{text-align:right}
.gtable td.center,.gtable th.center{text-align:center}
.table-wrap{overflow-x:auto}
.empty-box{text-align:center;padding:40px 20px;color:var(--muted)}
.empty-box i{font-size:32px;color:#cbd5e1;margin-bottom:8px;display:block}
.code-pill{font-family:monospace;font-weight:700;background:#eef2ff;color:#3730a3;border-radius:5px;padding:2px 7px;font-size:11px}
.mismatch{color:#dc2626;font-weight:700}
.match-ok{color:#16a34a}
@media(max-width:700px){.kpi-grid{grid-template-columns:1fr 1fr}}
@media print{.btn,.form-row,.upload-zone{display:none}}
</style>

<div class="psc-header">
    <div>
        <h2 class="psc-title"><i class="fa-solid fa-clipboard-check" style="color:var(--accent)"></i> Physical Stock Count</h2>
        <p class="psc-sub">Upload the physical stock count sheet, tagged with a Stock Count Date. Total Units = (Phy Cases × UPC) + Phy Pieces.</p>
    </div>
    <?php if ($view_batch): ?>
    <div style="display:flex;gap:8px">
        <button onclick="window.print()" class="btn btn-ghost btn-sm"><i class="fa-solid fa-print"></i> Print</button>
        <button onclick="exportExcel()" class="btn btn-success btn-sm"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
        <a href="physical_stock_count_upload.php" class="btn btn-ghost btn-sm"><i class="fa-solid fa-arrow-left"></i> Back to Upload</a>
    </div>
    <?php endif; ?>
</div>

<?php if (isset($_GET['deleted'])): ?>
<div class="psc-alert psc-alert-success"><i class="fa-solid fa-circle-check"></i><div>Stock count batch deleted.</div></div>
<?php endif; ?>

<?php if ($view_batch): ?>
<!-- ══════════════════ VIEW BATCH DETAIL ══════════════════ -->
<?php
    $mismatch_count = 0;
    foreach ($view_items as $it) {
        if ($it['book_unit'] !== null && abs((float)$it['book_unit'] - (float)$it['total_units']) > 0.01) $mismatch_count++;
    }
?>
<div class="kpi-grid">
    <div class="kpi-card kpi-blue"><div class="kpi-label">Stock Count Date</div><div class="kpi-value" style="font-size:16px"><?php echo date('M d, Y', strtotime($view_batch['count_date'])); ?></div><div class="kpi-sub"><?php echo htmlspecialchars($view_batch['filename']); ?></div></div>
    <div class="kpi-card kpi-purple"><div class="kpi-label">Items</div><div class="kpi-value"><?php echo number_format($view_batch['total_rows']); ?></div></div>
    <div class="kpi-card kpi-teal"><div class="kpi-label">Total Cases</div><div class="kpi-value"><?php echo number_format($view_batch['total_cases'], 2); ?></div></div>
    <div class="kpi-card kpi-orange"><div class="kpi-label">Total Pieces</div><div class="kpi-value"><?php echo number_format($view_batch['total_pieces'], 2); ?></div></div>
    <div class="kpi-card kpi-green"><div class="kpi-label">Total Units</div><div class="kpi-value"><?php echo number_format($view_batch['total_units']); ?></div><div class="kpi-sub">Cases × UPC + Pieces</div></div>
</div>

<?php if ($mismatch_count > 0): ?>
<div class="psc-alert psc-alert-info"><i class="fa-solid fa-circle-info"></i><div><?php echo $mismatch_count; ?> row(s) where the file's own "Unit" figure doesn't match the computed Cases×UPC+Pieces — highlighted in red below, worth a quick check against the source sheet.</div></div>
<?php endif; ?>

<div class="psc-card">
    <div class="psc-card-h"><i class="fa-solid fa-table-list" style="color:var(--accent)"></i> Stock Count Items (<?php echo count($view_items); ?>)</div>
    <div class="psc-card-b" style="padding:0">
        <?php if (empty($view_items)): ?>
        <div class="empty-box"><i class="fa-solid fa-inbox"></i><p>No items in this batch.</p></div>
        <?php else: ?>
        <div class="table-wrap"><table class="gtable">
            <thead><tr>
                <th class="center">Sr</th><th>Division</th><th>SKU</th><th style="min-width:220px">Product Name</th>
                <th class="num">No/Case</th><th class="num">UPC</th><th class="num">Phy Cases</th><th class="num">Phy Pieces</th>
                <th class="num">Total Units</th><th class="num">File's Unit</th>
            </tr></thead>
            <tbody>
            <?php foreach ($view_items as $it):
                $mismatch = $it['book_unit'] !== null && abs((float)$it['book_unit'] - (float)$it['total_units']) > 0.01;
            ?>
            <tr>
                <td class="center"><?php echo $it['sr_no'] !== null ? (int)$it['sr_no'] : '—'; ?></td>
                <td><?php echo htmlspecialchars($it['division'] ?? '—'); ?></td>
                <td><span class="code-pill"><?php echo htmlspecialchars($it['sku'] ?? '—'); ?></span></td>
                <td><?php echo htmlspecialchars($it['product_name'] ?? ''); ?></td>
                <td class="num"><?php echo $it['no_per_case'] !== null ? number_format($it['no_per_case'], 0) : '—'; ?></td>
                <td class="num"><?php echo $it['upc'] !== null ? number_format($it['upc'], 0) : '—'; ?></td>
                <td class="num"><?php echo number_format($it['phy_cases'], 2); ?></td>
                <td class="num"><?php echo number_format($it['phy_pieces'], 2); ?></td>
                <td class="num" style="font-weight:700"><?php echo number_format($it['total_units'], 2); ?></td>
                <td class="num <?php echo $mismatch ? 'mismatch' : 'match-ok'; ?>"><?php echo $it['book_unit'] !== null ? number_format($it['book_unit'], 2) : '—'; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" defer></script>
<script>
const VIEW_ITEMS = <?php echo $view_items_json; ?>;
const COUNT_DATE = <?php echo json_encode($view_batch['count_date']); ?>;
function exportExcel() {
    if (typeof XLSX === 'undefined') { alert('Excel library still loading, please wait a moment.'); return; }
    const headers = ['Sr','Division','SKU','Product Name','No/Case','UPC','Phy Cases','Phy Pieces','Total Units','File Unit'];
    const data = [['PHYSICAL STOCK COUNT — ' + COUNT_DATE], [], headers];
    VIEW_ITEMS.forEach(r => data.push([
        r.sr_no, r.division, r.sku, r.product_name, +r.no_per_case, +r.upc,
        +r.phy_cases, +r.phy_pieces, +r.total_units, r.book_unit !== null ? +r.book_unit : ''
    ]));
    const ws = XLSX.utils.aoa_to_sheet(data);
    ws['!cols'] = headers.map((_, i) => ({ wch: i === 3 ? 30 : 14 }));
    ws['!merges'] = [{ s:{r:0,c:0}, e:{r:0,c:headers.length-1} }];
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Stock Count');
    XLSX.writeFile(wb, 'Physical_Stock_Count_' + COUNT_DATE + '.xlsx');
}
</script>

<?php else: ?>
<!-- ══════════════════ UPLOAD + LIST ══════════════════ -->
<div class="psc-card">
    <div class="psc-card-h"><i class="fa-solid fa-cloud-arrow-up" style="color:var(--accent)"></i> Upload Physical Stock Count</div>
    <div class="psc-card-b">
        <form id="uploadForm">
            <div class="form-row">
                <div class="form-group">
                    <label class="psc-label"><i class="fa-solid fa-calendar-day"></i> Stock Count Date</label>
                    <input type="date" name="count_date" id="countDate" class="psc-input" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="form-group" style="flex:2">
                    <label class="psc-label"><i class="fa-solid fa-note-sticky"></i> Note (optional)</label>
                    <input type="text" name="note" id="noteField" class="psc-input" placeholder="e.g. August warehouse audit">
                </div>
            </div>
            <div class="upload-zone" id="uploadZone">
                <input type="file" name="xlsx_file" id="xlsxFile" accept=".xlsx,.xls" onchange="handleFileSelect(this)">
                <span class="upload-icon">📋</span>
                <div class="upload-title" id="uploadTitle">Drop the Physical Stock Count .xlsx here or click to browse</div>
                <div class="upload-hint">Expected columns: Sr, Division, SKU, Product Name, No, UPC, Unit, Phy Cases, Phy Pieces</div>
            </div>
            <div id="fileLoading"><span class="spin"></span> Uploading and parsing, please wait…</div>
        </form>
    </div>
</div>

<div id="uploadResult"></div>

<div class="psc-card">
    <div class="psc-card-h"><i class="fa-solid fa-layer-group" style="color:var(--accent)"></i> Stock Count History</div>
    <div class="psc-card-b" style="padding:0">
        <?php if (empty($batches)): ?>
        <div class="empty-box"><i class="fa-solid fa-inbox"></i><p style="margin:8px 0 0">No physical stock counts uploaded yet.</p></div>
        <?php else: ?>
        <div class="table-wrap"><table class="gtable">
            <thead><tr>
                <th>Count Date</th><th>File</th><th class="num">Items</th><th class="num">Cases</th><th class="num">Pieces</th><th class="num">Total Units</th><th>Uploaded By</th><th>Uploaded At</th><th class="center">Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($batches as $b): ?>
            <tr>
                <td style="font-weight:700"><?php echo date('M d, Y', strtotime($b['count_date'])); ?></td>
                <td><?php echo htmlspecialchars($b['filename']); ?><?php if (!empty($b['note'])): ?><br><span style="font-size:11px;color:var(--muted)"><?php echo htmlspecialchars($b['note']); ?></span><?php endif; ?></td>
                <td class="num"><?php echo number_format($b['total_rows']); ?></td>
                <td class="num"><?php echo number_format($b['total_cases'], 2); ?></td>
                <td class="num"><?php echo number_format($b['total_pieces'], 2); ?></td>
                <td class="num" style="font-weight:700"><?php echo number_format($b['total_units']); ?></td>
                <td><?php echo htmlspecialchars($b['uploaded_by'] ?? '—'); ?></td>
                <td><?php echo date('M d, Y H:i', strtotime($b['uploaded_at'])); ?></td>
                <td class="center">
                    <a href="physical_stock_count_upload.php?view=<?php echo (int)$b['id']; ?>" class="btn btn-ghost btn-sm"><i class="fa-solid fa-eye"></i> View</a>
                    <a href="physical_stock_count_upload.php?delete=<?php echo (int)$b['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this stock count batch and all its items? This cannot be undone.');"><i class="fa-solid fa-trash"></i></a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>

<script>
function handleFileSelect(input) {
    if (!input.files.length) return;
    const countDate = document.getElementById('countDate').value;
    if (!countDate) { alert('Please select a Stock Count Date first.'); input.value = ''; return; }

    document.getElementById('uploadTitle').textContent = '📄 ' + input.files[0].name + ' — uploading…';
    document.getElementById('fileLoading').style.display = 'flex';
    document.getElementById('uploadZone').style.pointerEvents = 'none';

    const fd = new FormData();
    fd.append('action', 'upload');
    fd.append('count_date', countDate);
    fd.append('note', document.getElementById('noteField').value);
    fd.append('xlsx_file', input.files[0]);

    fetch('physical_stock_count_upload.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(function (res) {
            if (res.ok) {
                window.location.href = 'physical_stock_count_upload.php?view=' + res.batch_id;
            } else {
                document.getElementById('uploadResult').innerHTML =
                    '<div class="psc-alert psc-alert-danger"><i class="fa-solid fa-circle-xmark"></i><div>' + (res.msg || 'Upload failed.') + '</div></div>';
                document.getElementById('fileLoading').style.display = 'none';
                document.getElementById('uploadZone').style.pointerEvents = 'auto';
                document.getElementById('uploadTitle').textContent = 'Drop the Physical Stock Count .xlsx here or click to browse';
            }
        })
        .catch(function () {
            document.getElementById('uploadResult').innerHTML =
                '<div class="psc-alert psc-alert-danger"><i class="fa-solid fa-circle-xmark"></i><div>Network error while uploading.</div></div>';
            document.getElementById('fileLoading').style.display = 'none';
            document.getElementById('uploadZone').style.pointerEvents = 'auto';
        });
}
const zone = document.getElementById('uploadZone');
if (zone) {
    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('drag'));
    zone.addEventListener('drop', e => { e.preventDefault(); zone.classList.remove('drag'); });
}
</script>
<?php endif; ?>

<?php
ob_end_flush();
include 'footer.php';
