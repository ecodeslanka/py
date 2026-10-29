<?php
// ============================================================
//  physical_stock_count_reconciliation.php
//  PHYSICAL STOCK COUNT RECONCILIATION
//  Compares the SYSTEM's cumulative Actual Qty-Units (same formula as
//  sampath_stock_reconciliation.php, but running from the beginning of
//  all data up to a chosen "As At Date") against a chosen Physical
//  Stock Count batch's File's Unit figure, item by item:
//
//    System Actual Qty-Units = Primary Qty(units) - Primary Return(units) - Pre Secondary Out
//                               + Unloading Qty + Sampath Rejected Qty   (all cumulative, <= As At Date)
//    Physical File's Unit    = physical_stock_count_items.book_unit for the selected batch (matched by SKU = item_code)
//    Difference               = System Actual Qty-Units - Physical File's Unit
//                               (positive = system thinks there's more than what was physically counted -> shortage;
//                                negative = more was physically counted than the system expects -> excess)
//    Difference Rate           = Difference / System Actual Qty-Units * 100  (as % of the system's expected qty)
// ============================================================
if (function_exists('mysqli_report')) { mysqli_report(MYSQLI_REPORT_OFF); }
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();

include 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
include 'header.php';

$debug = isset($_GET['debug']) && $_GET['debug'] === '1';

/* ── FILTERS ── */
$as_at_date  = isset($_GET['as_at_date']) && $_GET['as_at_date'] !== '' ? $_GET['as_at_date'] : date('Y-m-d');
$batch_id    = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;
$filter_search = isset($_GET['search']) ? trim($_GET['search']) : '';
$has_run     = isset($_GET['run']) && $_GET['run'] === '1';

$aad = mysqli_real_escape_string($conn, $as_at_date);

/* ── CHECK REQUIRED TABLES EXIST ── */
$required_tables = [
    'unilever_purchase_items', 'unilever_purchase_batches',
    'unilever_item_return_items', 'unilever_item_returns',
    'primary_invoice_wise_sales_data', 'unloading_data',
    'physical_stock_count_batches', 'physical_stock_count_items',
];
$missing_tables = [];
foreach ($required_tables as $t) {
    $chk = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $t) . "'");
    if (!$chk || mysqli_num_rows($chk) === 0) $missing_tables[] = $t;
}

/* ── Stock count batches for the dropdown ── */
$stock_batches = [];
if (!in_array('physical_stock_count_batches', $missing_tables)) {
    $r = mysqli_query($conn, "SELECT id, count_date, filename, total_rows FROM physical_stock_count_batches ORDER BY count_date DESC, id DESC");
    if ($r) while ($row = mysqli_fetch_assoc($r)) { $stock_batches[] = $row; }
}
// Default to the most recent batch at/before the As At Date, else the most recent overall
if ($batch_id === 0 && $stock_batches) {
    foreach ($stock_batches as $b) {
        if ($b['count_date'] <= $as_at_date) { $batch_id = (int)$b['id']; break; }
    }
    if ($batch_id === 0) $batch_id = (int)$stock_batches[0]['id'];
}

$query_error = '';
$rows = [];
$grand = ['system_units' => 0, 'physical_units' => 0, 'difference' => 0, 'abs_difference' => 0];
$variance_count = 0; $matched_count = 0; $items_compared = 0;
$selected_batch = null;

if (empty($missing_tables) && $has_run && $batch_id > 0) {

    foreach ($stock_batches as $b) { if ((int)$b['id'] === $batch_id) { $selected_batch = $b; break; } }

    /* ── ITEM MASTER (full history, for name/upc) ── */
    $item_master = [];
    $sql = "SELECT p.item_code, p.item_name, p.upc
            FROM unilever_purchase_items p
            INNER JOIN (
                SELECT item_code, MAX(id) AS max_id FROM unilever_purchase_items
                WHERE item_code IS NOT NULL AND item_code <> '' GROUP BY item_code
            ) latest ON latest.max_id = p.id";
    $res = mysqli_query($conn, $sql);
    if (!$res) { $query_error = mysqli_error($conn); }
    else { while ($r = mysqli_fetch_assoc($res)) { $item_master[trim($r['item_code'])] = ['item_name' => $r['item_name'], 'upc' => $r['upc']]; } }

    /* ── CUMULATIVE PRIMARY PURCHASE, up to As At Date (invoice_date, falling back to received_date) ── */
    $purchase_by_item = [];
    $imp_return_by_item = [];   // MINUS qty rows of the purchase import = purchase returns
    $sql = "SELECT p.item_code, SUM(CASE WHEN p.purchase_qty_cases > 0 THEN p.purchase_qty_cases ELSE 0 END) AS cases, SUM(CASE WHEN p.purchase_qty_cases < 0 THEN -p.purchase_qty_cases ELSE 0 END) AS rcases
            FROM unilever_purchase_items p
            JOIN unilever_purchase_batches b ON b.id = p.batch_id
            WHERE p.item_code IS NOT NULL AND p.item_code <> ''
              AND COALESCE(p.invoice_date, b.received_date) <= '$aad'
            GROUP BY p.item_code";
    $res = mysqli_query($conn, $sql);
    if (!$res) { $query_error = $query_error ?: mysqli_error($conn); }
    else { while ($r = mysqli_fetch_assoc($res)) { $purchase_by_item[trim($r['item_code'])] = (float)$r['cases']; $imp_return_by_item[trim($r['item_code'])] = (float)$r['rcases']; } }

    /* ── CUMULATIVE PRIMARY RETURN ── */
    $return_by_item = [];
    $sql = "SELECT ri.item_code, SUM(ri.qty) AS cases
            FROM unilever_item_return_items ri
            JOIN unilever_item_returns r ON r.id = ri.return_id
            WHERE ri.item_code IS NOT NULL AND ri.item_code <> '' AND r.return_date <= '$aad'
            GROUP BY ri.item_code";
    $res = mysqli_query($conn, $sql);
    if ($res) { while ($r = mysqli_fetch_assoc($res)) { $return_by_item[trim($r['item_code'])] = (float)$r['cases']; } }
    foreach ($imp_return_by_item as $ic => $rq) $return_by_item[$ic] = ($return_by_item[$ic] ?? 0) + $rq;

    /* ── CUMULATIVE PRE SECONDARY OUT (already units) ── */
    $presec_by_item = [];
    $sql = "SELECT product_code AS item_code, SUM(units) AS u
            FROM primary_invoice_wise_sales_data
            WHERE product_code IS NOT NULL AND product_code <> '' AND delivery_date <= '$aad'
            GROUP BY product_code";
    $res = mysqli_query($conn, $sql);
    if ($res) { while ($r = mysqli_fetch_assoc($res)) { $presec_by_item[trim($r['item_code'])] = (float)$r['u']; } }

    /* ── CUMULATIVE UNLOADING (already units) ── */
    $unload_by_item = [];
    $sql = "SELECT sku_code AS item_code, SUM(COALESCE(adj_qty_good_units,0)+COALESCE(adj_qty_damage,0)) AS u
            FROM unloading_data
            WHERE sku_code IS NOT NULL AND sku_code <> '' AND delivery_date <= '$aad'
            GROUP BY sku_code";
    $res = mysqli_query($conn, $sql);
    if ($res) { while ($r = mysqli_fetch_assoc($res)) { $unload_by_item[trim($r['item_code'])] = (float)$r['u']; } }

    /* ── PHYSICAL COUNT for the selected batch, keyed by SKU (= item_code) ── */
    $physical_by_item = []; // item_code => ['file_unit'=>.., 'total_units'=>.., 'product_name'=>.., 'phy_cases'=>.., 'phy_pieces'=>..]
    $sql = "SELECT sku, product_name, upc, book_unit, total_units, phy_cases, phy_pieces
            FROM physical_stock_count_items WHERE batch_id=$batch_id";
    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $code = trim($r['sku']);
            if ($code === '') continue;
            // Same SKU can legitimately appear more than once in a physical count
            // sheet (recounted / split rows) — sum the quantities instead of the
            // last row silently overwriting the earlier one.
            if (isset($physical_by_item[$code])) {
                $physical_by_item[$code]['book_unit']    += (float)$r['book_unit'];
                $physical_by_item[$code]['total_units']  += (float)$r['total_units'];
                $physical_by_item[$code]['phy_cases']    += (float)$r['phy_cases'];
                $physical_by_item[$code]['phy_pieces']   += (float)$r['phy_pieces'];
            } else {
                $physical_by_item[$code] = $r;
            }
        }
    }

    if ($filter_search !== '') {
        $sTerm = mb_strtolower($filter_search);
    }

    /* ── COMBINE: union of items with system purchase history OR physical count ── */
    $all_codes = array_unique(array_merge(array_keys($purchase_by_item), array_keys($physical_by_item)));
    sort($all_codes);

    foreach ($all_codes as $code) {
        $master = $item_master[$code] ?? null;
        $phy    = $physical_by_item[$code] ?? null;

        $item_name = $master['item_name'] ?? ($phy['product_name'] ?? null);
        $upc       = (float)preg_replace('/[^0-9.]/', '', (string)($master['upc'] ?? ($phy['upc'] ?? 0)));

        if ($filter_search !== '') {
            $hay = mb_strtolower($code . ' ' . ($item_name ?? ''));
            if (mb_strpos($hay, $sTerm) === false) continue;
        }

        $purchase_cases = $purchase_by_item[$code] ?? 0.0;
        $return_cases   = $return_by_item[$code] ?? 0.0;
        $purchase_units = $upc > 0 ? $purchase_cases * $upc : 0.0;
        $return_units   = $upc > 0 ? $return_cases * $upc : 0.0;
        $presec_units   = $presec_by_item[$code] ?? 0.0;
        $unload_units   = $unload_by_item[$code] ?? 0.0;
        $rejected_units = 0.0; // no source yet, same as the period reconciliation report

        $system_units = $purchase_units - $return_units - $presec_units + $unload_units + $rejected_units;
        $physical_units = $phy !== null ? (float)$phy['book_unit'] : null; // File's Unit — null = not counted in this batch

        $difference = $physical_units !== null ? ($system_units - $physical_units) : null;
        $diff_rate  = null;
        if ($difference !== null) {
            if (abs($system_units) > 0.0001) {
                $diff_rate = ($difference / $system_units) * 100;
            } elseif (abs($physical_units) > 0.0001) {
                $diff_rate = -100.0; // system expects 0 but something was physically counted
            } else {
                $diff_rate = 0.0;
            }
        }

        $items_compared++;
        if ($difference === null) {
            // not counted in this batch at all — still listed, but excluded from variance stats
        } elseif (abs($difference) < 0.01) {
            $matched_count++;
        } else {
            $variance_count++;
        }

        $grand['system_units']   += $system_units;
        if ($physical_units !== null) $grand['physical_units'] += $physical_units;
        if ($difference !== null) { $grand['difference'] += $difference; $grand['abs_difference'] += abs($difference); }

        $rows[] = [
            'item_code' => $code, 'item_name' => $item_name, 'upc' => $upc,
            'purchase_cases' => $purchase_cases, 'return_cases' => $return_cases,
            'presec_units' => $presec_units, 'unload_units' => $unload_units,
            'system_units' => $system_units,
            'physical_units' => $physical_units,
            'phy_cases' => $phy['phy_cases'] ?? null, 'phy_pieces' => $phy['phy_pieces'] ?? null,
            'physical_total_units' => $phy !== null ? (float)$phy['total_units'] : null,
            'difference' => $difference, 'diff_rate' => $diff_rate,
            'counted' => $phy !== null,
        ];
    }
    usort($rows, function ($a, $b) {
        // biggest absolute variance first, uncounted items last
        $av = $a['difference'] === null ? -1 : abs($a['difference']);
        $bv = $b['difference'] === null ? -1 : abs($b['difference']);
        return $bv <=> $av;
    });

} elseif (!empty($missing_tables)) {
    $query_error = 'Missing table(s) on this database: ' . implode(', ', $missing_tables)
        . '. Import at least one Unilever Purchase batch and one Physical Stock Count batch first.';
}

$all_rows_json = json_encode($rows, JSON_NUMERIC_CHECK);
?>
<style>
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:20px}
.card-title{font-size:16px;font-weight:600;margin-bottom:16px;color:#1f2937;display:flex;align-items:center;gap:8px}
.filter-bar{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end}
.filter-group{display:flex;flex-direction:column;gap:4px}
.filter-group label{font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px}
.filter-input,.filter-select{padding:8px 12px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;color:#333;background:#fff;min-width:170px}
.filter-input:focus,.filter-select:focus{outline:none;border-color:#000}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none}
.btn-primary{background:#000;color:#fff}.btn-primary:hover{background:#333}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}.btn-secondary:hover{background:#e5e5e5}
.btn-success{background:#16a34a;color:#fff}.btn-success:hover{background:#15803d}
.btn-sm{padding:6px 14px;font-size:12px}
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:20px}
.kpi-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:16px;position:relative;overflow:hidden}
.kpi-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px}
.kpi-blue::before{background:#3b82f6}.kpi-purple::before{background:#8b5cf6}.kpi-red::before{background:#ef4444}.kpi-green::before{background:#22c55e}.kpi-orange::before{background:#f97316}.kpi-gray::before{background:#6b7280}
.kpi-label{font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
.kpi-value{font-size:19px;font-weight:700;color:#1f2937}
.kpi-sub{font-size:11px;color:#9ca3af;margin-top:3px}
.alert-error{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;border-radius:8px;padding:16px 20px;margin-bottom:20px;font-size:13px}
.alert-info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;border-radius:8px;padding:16px 20px;margin-bottom:20px;font-size:13px}
.table-responsive{overflow-x:auto}
.report-table{width:100%;border-collapse:collapse;font-size:12px;min-width:1150px}
.report-table thead th{background:#000;color:#fff;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:11px 9px;text-align:center;border:1px solid #333}
.report-table tbody tr{border-bottom:1px solid #f0f0f0}
.report-table tbody tr:hover{background:#f8fafc}
.report-table tbody tr.variance-row{background:#fef2f2}
.report-table tbody tr.uncounted-row{background:#fffbeb}
.report-table td{padding:8px 9px;color:#374151;text-align:center;border:1px solid #f0f0f0;white-space:nowrap}
.report-table td.left{text-align:left}
.report-table tfoot tr{background:#f1f5f9;font-weight:700}
.report-table tfoot td{padding:11px 9px;border:1px solid #e2e8f0;font-size:12px}
.code-pill{font-family:monospace;font-weight:700;background:#eef2ff;color:#3730a3;border-radius:5px;padding:2px 7px;font-size:11px}
.badge-ok{display:inline-block;padding:2px 10px;border-radius:10px;font-size:11px;font-weight:700;background:#dcfce7;color:#166534}
.badge-variance{display:inline-block;padding:2px 10px;border-radius:10px;font-size:11px;font-weight:700;background:#fee2e2;color:#991b1b}
.badge-uncounted{display:inline-block;padding:2px 10px;border-radius:10px;font-size:11px;font-weight:700;background:#fef9c3;color:#854d0e}
@media print{.filter-bar,.btn{display:none}.content-card{border:none;padding:0}.report-table{font-size:10px}}
</style>

<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
        <div>
            <h2 class="page-title">
                <i class="fa-solid fa-scale-unbalanced" style="color:#000"></i>
                PHYSICAL STOCK COUNT RECONCILIATION
            </h2>
            <p class="page-subtitle">System Actual Qty-Units (as at date, cumulative) vs the physical count's File's Unit — Difference = System − Physical</p>
        </div>
        <?php if ($has_run): ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button onclick="window.print()" class="btn btn-secondary btn-sm"><i class="fa-solid fa-print"></i> Print</button>
            <button onclick="exportExcel()" class="btn btn-success btn-sm"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($query_error): ?>
<div class="alert-error"><strong>Database error:</strong> <?php echo htmlspecialchars($query_error); ?></div>
<?php endif; ?>

<div class="content-card">
    <form method="GET" action="">
        <input type="hidden" name="run" value="1">
        <div class="filter-bar">
            <div class="filter-group">
                <label>As At Date</label>
                <input type="date" name="as_at_date" class="filter-input" value="<?php echo htmlspecialchars($as_at_date); ?>">
            </div>
            <div class="filter-group" style="min-width:280px">
                <label>Stock Count</label>
                <select name="batch_id" class="filter-select" style="min-width:280px">
                    <?php if (empty($stock_batches)): ?>
                        <option value="0">No physical stock counts uploaded yet</option>
                    <?php else: foreach ($stock_batches as $b): ?>
                        <option value="<?php echo (int)$b['id']; ?>" <?php echo $batch_id === (int)$b['id'] ? 'selected' : ''; ?>>
                            <?php echo date('M d, Y', strtotime($b['count_date'])); ?> — <?php echo htmlspecialchars($b['filename']); ?> (<?php echo (int)$b['total_rows']; ?> items)
                        </option>
                    <?php endforeach; endif; ?>
                </select>
            </div>
            <div class="filter-group" style="min-width:200px">
                <label>Item Code / Item Name</label>
                <input type="text" name="search" class="filter-input" placeholder="Search item…" value="<?php echo htmlspecialchars($filter_search); ?>">
            </div>
            <div class="filter-group">
                <label>&nbsp;</label>
                <div style="display:flex;gap:8px">
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-play"></i> Run</button>
                    <a href="physical_stock_count_reconciliation.php" class="btn btn-secondary"><i class="fa-solid fa-rotate-left"></i> Reset</a>
                </div>
            </div>
        </div>
    </form>
</div>

<?php if (!$has_run): ?>
<div class="alert-info"><i class="fa-solid fa-circle-info"></i> Pick an As At Date and a Stock Count batch, then click <strong>Run</strong> to compare.</div>

<?php elseif ($batch_id === 0 || empty($stock_batches)): ?>
<div class="alert-error">No Physical Stock Count has been uploaded yet — upload one from <a href="physical_stock_count_upload.php">Import Physical Stock</a> first.</div>

<?php else: ?>

<div class="kpi-grid">
    <div class="kpi-card kpi-blue"><div class="kpi-label">System Actual Qty-Units</div><div class="kpi-value"><?php echo number_format($grand['system_units']); ?></div><div class="kpi-sub">as at <?php echo date('M d, Y', strtotime($as_at_date)); ?></div></div>
    <div class="kpi-card kpi-purple"><div class="kpi-label">Physical File's Unit</div><div class="kpi-value"><?php echo number_format($grand['physical_units']); ?></div><div class="kpi-sub"><?php echo $selected_batch ? date('M d, Y', strtotime($selected_batch['count_date'])) : ''; ?></div></div>
    <div class="kpi-card <?php echo abs($grand['difference']) < 0.01 ? 'kpi-green' : 'kpi-red'; ?>"><div class="kpi-label">Total Difference</div><div class="kpi-value"><?php echo number_format($grand['difference']); ?></div><div class="kpi-sub">System − Physical</div></div>
    <div class="kpi-card kpi-orange"><div class="kpi-label">Total Abs. Difference</div><div class="kpi-value"><?php echo number_format($grand['abs_difference']); ?></div></div>
    <div class="kpi-card kpi-gray"><div class="kpi-label">Items Compared</div><div class="kpi-value"><?php echo number_format($items_compared); ?></div><div class="kpi-sub"><?php echo $matched_count; ?> matched / <?php echo $variance_count; ?> variance</div></div>
</div>

<div class="content-card">
    <h3 class="card-title">
        <i class="fa-solid fa-table" style="color:#3b82f6"></i>
        Item-wise Difference
        <span style="font-size:12px;font-weight:400;color:#9ca3af;margin-left:8px"><?php echo count($rows); ?> row(s), largest variance first</span>
    </h3>
    <?php if (empty($rows)): ?>
        <div style="text-align:center;padding:40px;color:#9ca3af">
            <i class="fa-solid fa-inbox" style="font-size:32px;margin-bottom:12px;display:block"></i>
            No items to compare — no purchase history up to this date and nothing in the selected stock count.
        </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="report-table">
            <thead>
                <tr>
                    <th style="width:100px">Item Code</th>
                    <th style="width:200px">Item Name</th>
                    <th>UPC</th>
                    <th>System Actual<br>Qty-Units</th>
                    <th>Physical<br>File's Unit</th>
                    <th>Difference<br><small style="font-weight:400;font-size:9px">(System − Physical)</small></th>
                    <th>Difference<br>Rate</th>
                    <th style="width:90px">Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row):
                $rowClass = !$row['counted'] ? 'uncounted-row' : (abs($row['difference']) >= 0.01 ? 'variance-row' : '');
            ?>
            <tr class="<?php echo $rowClass; ?>">
                <td class="left"><span class="code-pill"><?php echo htmlspecialchars($row['item_code']); ?></span></td>
                <td class="left"><?php echo htmlspecialchars($row['item_name'] ?: '—'); ?></td>
                <td><?php echo $row['upc'] ? number_format($row['upc'], 0) : '—'; ?></td>
                <td style="font-weight:600"><?php echo number_format($row['system_units']); ?></td>
                <td><?php echo $row['counted'] ? number_format($row['physical_units'], 2) : '—'; ?></td>
                <td style="font-weight:700;color:<?php
                    echo !$row['counted'] ? '#92400e' : (abs($row['difference']) < 0.01 ? '#16a34a' : '#dc2626'); ?>">
                    <?php echo $row['counted'] ? number_format($row['difference'], 2) : '—'; ?>
                </td>
                <td style="color:<?php echo !$row['counted'] ? '#92400e' : (abs($row['difference']) < 0.01 ? '#16a34a' : '#dc2626'); ?>">
                    <?php echo $row['counted'] ? number_format($row['diff_rate'], 1) . '%' : '—'; ?>
                </td>
                <td>
                    <?php if (!$row['counted']): ?>
                        <span class="badge-uncounted">Not Counted</span>
                    <?php elseif (abs($row['difference']) < 0.01): ?>
                        <span class="badge-ok">Matched</span>
                    <?php else: ?>
                        <span class="badge-variance">Variance</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" class="left"><strong>GRAND TOTAL</strong></td>
                    <td><strong><?php echo number_format($grand['system_units']); ?></strong></td>
                    <td><strong><?php echo number_format($grand['physical_units'], 2); ?></strong></td>
                    <td style="color:<?php echo abs($grand['difference']) < 0.01 ? '#16a34a' : '#dc2626'; ?>"><strong><?php echo number_format($grand['difference'], 2); ?></strong></td>
                    <td colspan="2">—</td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" defer></script>
<script>
const ALL_ROWS = <?php echo $all_rows_json; ?>;
const AS_AT = <?php echo json_encode($as_at_date); ?>;

function exportExcel() {
    if (!ALL_ROWS || ALL_ROWS.length === 0) { alert('No data.'); return; }
    if (typeof XLSX === 'undefined') { alert('Excel library still loading, please wait a moment.'); return; }
    const headers = ['Item Code','Item Name','UPC','System Actual Qty-Units','Physical File\'s Unit','Difference','Difference Rate %','Status'];
    const data = [['PHYSICAL STOCK COUNT RECONCILIATION'], ['As At Date: ' + AS_AT], [], headers];
    ALL_ROWS.forEach(r => data.push([
        r.item_code, r.item_name, +r.upc, +r.system_units,
        r.counted ? +r.physical_units : '', r.counted ? +r.difference : '', r.counted ? +r.diff_rate : '',
        !r.counted ? 'Not Counted' : (Math.abs(r.difference) < 0.01 ? 'Matched' : 'Variance')
    ]));
    const ws = XLSX.utils.aoa_to_sheet(data);
    ws['!cols'] = headers.map((_, i) => (i === 1 ? { wch: 30 } : { wch: 16 }));
    ws['!merges'] = [{ s:{r:0,c:0}, e:{r:0,c:headers.length-1} }, { s:{r:1,c:0}, e:{r:1,c:headers.length-1} }];
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Reconciliation');
    XLSX.writeFile(wb, 'Physical_Stock_Reconciliation_' + AS_AT + '.xlsx');
}
</script>
<?php endif; ?>

<?php
ob_end_flush();
include 'footer.php';
