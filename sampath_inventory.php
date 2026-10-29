<?php
// ============================================================
//  sampath_inventory.php
//  SAMPATH INVENTORY — Pre Secondary + Secondary inventory in one page
//
//  One filter ("Inventory Type": All / Pre Secondary / Secondary) over both
//  item-wise sales imports that used to be separate pages:
//    Pre Secondary -> primary_invoice_wise_sales_uploads / primary_invoice_wise_sales_data
//    Secondary     -> invoice_wise_sales_uploads         / invoice_wise_sales_data
//  Views:
//    Uploads       -> every imported file with its type, delivery date and row count
//    Item Summary  -> item-wise units / free qty / gross sales per type for the date range
// ============================================================
include 'config.php';

$TYPES = [
    'pre' => ['label' => 'Pre Secondary', 'up' => 'primary_invoice_wise_sales_uploads', 'data' => 'primary_invoice_wise_sales_data',
              'view' => 'primary_invoice_wise_sales_view.php', 'upload' => 'primary_invoice_wise_sales_upload.php', 'badge' => '#ede9fe;color:#6d28d9'],
    'sec' => ['label' => 'Secondary',     'up' => 'invoice_wise_sales_uploads',         'data' => 'invoice_wise_sales_data',
              'view' => 'invoice_wise_sales_view.php',         'upload' => 'invoice_wise_sales_upload.php',         'badge' => '#dbeafe;color:#1e40af'],
];
function si_table($conn, $t) {
    $q = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $t) . "'");
    return $q && mysqli_num_rows($q) > 0;
}
foreach ($TYPES as $k => $t) $TYPES[$k]['ok'] = si_table($conn, $t['up']) && si_table($conn, $t['data']);

/* ── delete an upload (and its rows) ── */
if (isset($_GET['delete'], $_GET['type']) && is_numeric($_GET['delete']) && isset($TYPES[$_GET['type']]) && $TYPES[$_GET['type']]['ok']) {
    $t = $TYPES[$_GET['type']];
    $id = (int)$_GET['delete'];
    mysqli_query($conn, "DELETE FROM {$t['data']} WHERE upload_id=$id");
    mysqli_query($conn, "DELETE FROM {$t['up']} WHERE id=$id");
    header('Location: sampath_inventory.php?deleted=1');
    exit;
}

/* ── filters ── */
$f_type   = $_GET['type'] ?? 'all';
if ($f_type !== 'all' && !isset($TYPES[$f_type])) $f_type = 'all';
$f_view   = ($_GET['view'] ?? 'uploads') === 'items' ? 'items' : 'uploads';
$f_from   = trim($_GET['date_from'] ?? '');
$f_to     = trim($_GET['date_to'] ?? '');
$f_search = trim($_GET['search'] ?? '');
$f_items  = [];   // Items filter (Item Summary): product codes
foreach ((array)($_GET['items'] ?? []) as $v) { $v = trim((string)$v); if ($v !== '') $f_items[] = $v; }
$e = function ($v) use ($conn) { return mysqli_real_escape_string($conn, $v); };

$active = $f_type === 'all' ? array_keys($TYPES) : [$f_type];
$active = array_values(array_filter($active, function ($k) use ($TYPES) { return $TYPES[$k]['ok']; }));

$rows = [];
$stats = ['uploads' => 0, 'rows' => 0, 'dates' => []];
$errors = [];

if ($f_view === 'uploads') {
    $parts = [];
    foreach ($active as $k) {
        $t = $TYPES[$k];
        $w = ['1=1'];
        if ($f_from)   $w[] = "u.delivery_date >= '" . $e($f_from) . "'";
        if ($f_to)     $w[] = "u.delivery_date <= '" . $e($f_to) . "'";
        if ($f_search) $w[] = "(u.filename LIKE '%" . $e($f_search) . "%' OR u.note LIKE '%" . $e($f_search) . "%')";
        $parts[] = "SELECT '$k' AS type, u.id, u.delivery_date, u.filename, u.total_rows, u.note, u.uploaded_at FROM {$t['up']} u WHERE " . implode(' AND ', $w);
    }
    if ($parts) {
        $q = mysqli_query($conn, implode(' UNION ALL ', $parts) . ' ORDER BY delivery_date DESC, uploaded_at DESC LIMIT 500');
        if (!$q) $errors[] = mysqli_error($conn);
        while ($q && $r = mysqli_fetch_assoc($q)) {
            $rows[] = $r;
            $stats['uploads']++; $stats['rows'] += (int)$r['total_rows']; $stats['dates'][$r['delivery_date']] = 1;
        }
    }
} else {
    /* item-wise summary, one column group per active type */
    $items = [];
    foreach ($active as $k) {
        $t = $TYPES[$k];
        $w = ['product_code IS NOT NULL', "product_code <> ''"];
        if ($f_from) $w[] = "delivery_date >= '" . $e($f_from) . "'";
        if ($f_to)   $w[] = "delivery_date <= '" . $e($f_to) . "'";
        if ($f_search) $w[] = "(product_code LIKE '%" . $e($f_search) . "%' OR product_description LIKE '%" . $e($f_search) . "%' OR basepack_code LIKE '%" . $e($f_search) . "%')";
        if ($f_items) $w[] = "product_code IN ('" . implode("','", array_map($e, $f_items)) . "')";
        $q = mysqli_query($conn, "SELECT product_code, MAX(basepack_code) AS bp, MAX(product_description) AS nm,
                SUM(units) AS units, SUM(free_qty) AS free_qty, SUM(gross_sales) AS gross, COUNT(DISTINCT bill_number) AS bills
            FROM {$t['data']} WHERE " . implode(' AND ', $w) . " GROUP BY product_code");
        if (!$q) { $errors[] = mysqli_error($conn); continue; }
        while ($r = mysqli_fetch_assoc($q)) {
            $c = trim($r['product_code']);
            if (!isset($items[$c])) $items[$c] = ['code' => $c, 'bp' => $r['bp'], 'nm' => $r['nm']];
            $items[$c][$k] = ['units' => (float)$r['units'], 'free' => (float)$r['free_qty'], 'gross' => (float)$r['gross'], 'bills' => (int)$r['bills']];
        }
    }
    ksort($items);
    $rows = array_values($items);
}

/* Items filter options: every product in the active inventory tables, as "code - name" */
$item_opts = [];
if ($f_view === 'items') {
    foreach ($active as $k) {
        $q = mysqli_query($conn, "SELECT product_code, MAX(product_description) AS nm FROM {$TYPES[$k]['data']} WHERE product_code <> '' GROUP BY product_code");
        while ($q && $r = mysqli_fetch_assoc($q)) { $c = trim($r['product_code']); if (!isset($item_opts[$c]) || $item_opts[$c] === '') $item_opts[$c] = $r['nm'] ?? ''; }
    }
    ksort($item_opts);
}
$qs_base = ['type' => $f_type, 'date_from' => $f_from, 'date_to' => $f_to, 'search' => $f_search, 'items' => $f_items];
include 'header.php';
function si_n($v) { return number_format(round($v), 0); }
function si_m($v) { return number_format($v, 2); }
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<style>
.select2-container .select2-selection--multiple{min-height:37px;border:1.5px solid #e2e6f0;border-radius:8px;}
.select2-container{min-width:320px;max-width:520px;}
.si{padding:0 4px;}
.si h2{font-size:22px;font-weight:800;margin:0 0 4px;}
.si .sub{font-size:12px;color:#8892a4;margin-bottom:16px;}
.si-card{background:#fff;border:1.5px solid #e2e6f0;border-radius:14px;padding:16px 20px;margin-bottom:18px;}
.si-f{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;}
.si-f label{display:block;font-size:10px;font-weight:700;color:#475569;text-transform:uppercase;margin-bottom:4px;}
.si-f input,.si-f select{border:1.5px solid #e2e6f0;border-radius:8px;padding:8px 12px;font-size:13px;background:#fff;}
.si-b{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;background:#0a0c14;color:#fff;text-decoration:none;}
.si-b.l{background:#f7f8fc;color:#475569;border:1.5px solid #e2e6f0;} .si-b.g{background:#14532d;} .si-b.p{background:#6d28d9;} .si-b.bl{background:#1e40af;} .si-b.r{background:#dc2626;}
.si-b.sm{padding:5px 10px;font-size:12px;}
.si-tabs{display:inline-flex;border:1.5px solid #e2e6f0;border-radius:9px;overflow:hidden;}
.si-tabs a{padding:8px 16px;font-size:13px;font-weight:700;text-decoration:none;color:#475569;background:#fff;}
.si-tabs a.on{background:#0a0c14;color:#fff;}
.si-stats{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px;}
.si-stat{background:#fff;border:1.5px solid #e2e6f0;border-radius:12px;padding:12px 18px;min-width:150px;}
.si-stat .l{font-size:10px;font-weight:700;color:#8892a4;text-transform:uppercase;} .si-stat .v{font-size:20px;font-weight:800;margin-top:2px;}
.si-wrap{overflow:auto;border:1.5px solid #e2e6f0;border-radius:12px;background:#fff;}
table.si-t{width:100%;border-collapse:collapse;font-size:12px;}
table.si-t th{background:#0a0c14;color:#fff;padding:10px;text-align:left;font-size:10.5px;text-transform:uppercase;white-space:nowrap;}
table.si-t th.n,table.si-t td.n{text-align:right;} table.si-t th.c,table.si-t td.c{text-align:center;}
table.si-t td{padding:8px 10px;border-bottom:1px solid #eef0f6;white-space:nowrap;}
table.si-t td.n{font-family:monospace;}
.si-pill{display:inline-block;padding:2px 10px;border-radius:12px;font-size:11px;font-weight:700;}
.si-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;border-radius:8px;padding:10px 16px;margin-bottom:14px;font-size:13px;}
.si-err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:8px;padding:10px 16px;margin-bottom:14px;font-size:13px;}
</style>

<div class="si">
    <h2><i class="fa-solid fa-warehouse"></i> Sampath Inventory</h2>
    <div class="sub">Pre Secondary and Secondary inventory in one place — use <strong>Inventory Type</strong> to switch between them or see both together.</div>

    <?php if (isset($_GET['deleted'])): ?><div class="si-ok">Upload and its rows deleted.</div><?php endif; ?>
    <?php foreach ($TYPES as $k => $t) if (!$t['ok']): ?><div class="si-err"><?php echo $t['label']; ?> tables not found yet — import a file first.</div><?php endif; ?>
    <?php foreach ($errors as $er): ?><div class="si-err"><?php echo htmlspecialchars($er); ?></div><?php endforeach; ?>

    <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
        <div class="si-tabs">
            <a class="<?php echo $f_view === 'uploads' ? 'on' : ''; ?>" href="?<?php echo http_build_query($qs_base + ['view' => 'uploads']); ?>">Uploads</a>
            <a class="<?php echo $f_view === 'items' ? 'on' : ''; ?>" href="?<?php echo http_build_query($qs_base + ['view' => 'items']); ?>">Item Summary</a>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a class="si-b p" href="primary_invoice_wise_sales_upload.php"><i class="fa-solid fa-plus"></i> Upload Pre Secondary</a>
            <a class="si-b bl" href="invoice_wise_sales_upload.php"><i class="fa-solid fa-plus"></i> Upload Secondary</a>
        </div>
    </div>

    <form method="GET" class="si-card">
        <input type="hidden" name="view" value="<?php echo $f_view; ?>">
        <div class="si-f">
            <div><label>Inventory Type</label>
                <select name="type">
                    <option value="all" <?php echo $f_type === 'all' ? 'selected' : ''; ?>>All (Pre Secondary + Secondary)</option>
                    <?php foreach ($TYPES as $k => $t): ?><option value="<?php echo $k; ?>" <?php echo $f_type === $k ? 'selected' : ''; ?>><?php echo $t['label']; ?></option><?php endforeach; ?>
                </select></div>
            <div><label>Delivery Date From</label><input type="date" name="date_from" value="<?php echo htmlspecialchars($f_from); ?>"></div>
            <div><label>Delivery Date To</label><input type="date" name="date_to" value="<?php echo htmlspecialchars($f_to); ?>"></div>
            <?php if ($f_view === 'items'): ?>
            <div><label>Items</label>
                <select name="items[]" id="itemSelect" multiple style="min-width:320px">
                    <?php foreach ($item_opts as $c => $nm): ?>
                    <option value="<?php echo htmlspecialchars($c); ?>" <?php echo in_array((string)$c, $f_items, true) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c . ' - ' . $nm); ?></option>
                    <?php endforeach; ?>
                </select></div>
            <?php else: ?>
            <div><label>Filename / note</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($f_search); ?>" style="min-width:220px" placeholder="search…"></div>
            <?php endif; ?>
            <button class="si-b" type="submit"><i class="fa-solid fa-filter"></i> Apply</button>
            <a class="si-b l" href="sampath_inventory.php?view=<?php echo $f_view; ?>">Reset</a>
            <?php if ($f_view === 'items'): ?><button class="si-b g" type="button" onclick="siExport()"><i class="fa-solid fa-file-excel"></i> Export Excel</button><?php endif; ?>
        </div>
    </form>

<?php if ($f_view === 'uploads'): ?>
    <div class="si-stats">
        <div class="si-stat"><div class="l">Uploads</div><div class="v"><?php echo si_n($stats['uploads']); ?></div></div>
        <div class="si-stat"><div class="l">Data Rows</div><div class="v"><?php echo si_n($stats['rows']); ?></div></div>
        <div class="si-stat"><div class="l">Delivery Dates</div><div class="v"><?php echo count($stats['dates']); ?></div></div>
    </div>
    <div class="si-wrap"><table class="si-t">
        <thead><tr><th>#</th><th>Type</th><th>Delivery Date</th><th>Filename</th><th class="n">Rows</th><th>Note</th><th>Uploaded At</th><th class="c">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $i => $r): $t = $TYPES[$r['type']]; ?>
            <tr>
                <td style="color:#9ca3af"><?php echo $i + 1; ?></td>
                <td><span class="si-pill" style="background:<?php echo $t['badge']; ?>"><?php echo $t['label']; ?></span></td>
                <td><?php echo date('d M Y', strtotime($r['delivery_date'])); ?></td>
                <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($r['filename']); ?>"><?php echo htmlspecialchars($r['filename']); ?></td>
                <td class="n"><?php echo si_n($r['total_rows']); ?></td>
                <td style="color:#6b7280"><?php echo htmlspecialchars($r['note'] ?: '—'); ?></td>
                <td><?php echo date('d M Y H:i', strtotime($r['uploaded_at'])); ?></td>
                <td class="c">
                    <a class="si-b bl sm" href="<?php echo $t['view']; ?>?upload_id=<?php echo $r['id']; ?>" title="View data"><i class="fa-solid fa-eye"></i></a>
                    <a class="si-b r sm" href="?<?php echo http_build_query($qs_base + ['view' => 'uploads', 'type' => $r['type'], 'delete' => $r['id']]); ?>"
                       onclick="return confirm('Delete this <?php echo $t['label']; ?> upload and ALL its data rows? This cannot be undone.')" title="Delete"><i class="fa-solid fa-trash"></i></a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="8" style="text-align:center;padding:40px;color:#9ca3af;">No uploads found.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
<?php else:
    $show = $active;
    $tot = [];
    foreach ($show as $k) $tot[$k] = ['units' => 0, 'free' => 0, 'gross' => 0];
?>
    <div class="si-wrap"><table class="si-t" id="siTable">
        <thead>
            <tr><th rowspan="2">Basepack</th><th rowspan="2">Item Code</th><th rowspan="2">Product Name</th>
            <?php foreach ($show as $k): ?><th class="c" colspan="3" style="background:<?php echo $k === 'pre' ? '#6d28d9' : '#1e40af'; ?>"><?php echo $TYPES[$k]['label']; ?></th><?php endforeach; ?>
            <?php if (count($show) > 1): ?><th class="n" rowspan="2">Total Units</th><?php endif; ?></tr>
            <tr><?php foreach ($show as $k): ?><th class="n">Units</th><th class="n">Free Qty</th><th class="n">Gross Sales</th><?php endforeach; ?></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): $sum = 0; ?>
            <tr>
                <td><?php echo htmlspecialchars($r['bp'] ?? ''); ?></td>
                <td><strong><?php echo htmlspecialchars($r['code']); ?></strong></td>
                <td><?php echo htmlspecialchars($r['nm'] ?? ''); ?></td>
                <?php foreach ($show as $k): $d = $r[$k] ?? null; if ($d) { $tot[$k]['units'] += $d['units']; $tot[$k]['free'] += $d['free']; $tot[$k]['gross'] += $d['gross']; $sum += $d['units']; } ?>
                    <td class="n"><?php echo $d && round($d['units']) ? si_n($d['units']) : '—'; ?></td>
                    <td class="n"><?php echo $d && round($d['free']) ? si_n($d['free']) : '—'; ?></td>
                    <td class="n"><?php echo $d && $d['gross'] ? si_m($d['gross']) : '—'; ?></td>
                <?php endforeach; ?>
                <?php if (count($show) > 1): ?><td class="n"><strong><?php echo si_n($sum); ?></strong></td><?php endif; ?>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="<?php echo 3 + 3 * count($show) + (count($show) > 1 ? 1 : 0); ?>" style="text-align:center;padding:40px;color:#9ca3af;">No inventory found for this filter.</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr style="background:#f1f5f9;font-weight:800;">
            <td colspan="3" style="text-align:right;">TOTAL — <?php echo count($rows); ?> items</td>
            <?php foreach ($show as $k): ?>
                <td class="n"><?php echo si_n($tot[$k]['units']); ?></td><td class="n"><?php echo si_n($tot[$k]['free']); ?></td><td class="n"><?php echo si_m($tot[$k]['gross']); ?></td>
            <?php endforeach; ?>
            <?php if (count($show) > 1): ?><td class="n"><?php echo si_n(array_sum(array_column($tot, 'units'))); ?></td><?php endif; ?>
        </tr></tfoot>
    </table></div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.4.0/exceljs.min.js"></script>
    <script>
    $(function(){ $('#itemSelect').select2({placeholder:'— All items (code - name) —', allowClear:true, width:'100%'}); });
    const SI_ROWS = <?php echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    const SI_SHOW = <?php echo json_encode($show); ?>;
    const SI_LABEL = <?php echo json_encode(array_map(function ($t) { return $t['label']; }, $TYPES)); ?>;
    async function siExport(){
        const wb = new ExcelJS.Workbook(), ws = wb.addWorksheet('Sampath Inventory');
        const head = ['Basepack','Item Code','Product Name'];
        SI_SHOW.forEach(k => head.push(SI_LABEL[k] + ' Units', SI_LABEL[k] + ' Free Qty', SI_LABEL[k] + ' Gross Sales'));
        if (SI_SHOW.length > 1) head.push('Total Units');
        const h = ws.addRow(head); h.eachCell(c => { c.font = {bold:true}; c.fill = {type:'pattern', pattern:'solid', fgColor:{argb:'FFFBE5D6'}}; });
        SI_ROWS.forEach(r => {
            const row = [r.bp || '', r.code, r.nm || '']; let sum = 0;
            SI_SHOW.forEach(k => { const d = r[k] || {units:0, free:0, gross:0}; row.push(d.units, d.free, d.gross); sum += d.units; });
            if (SI_SHOW.length > 1) row.push(sum);
            ws.addRow(row);
        });
        for (let c = 4; c <= head.length; c++) ws.getColumn(c).numFmt = '#,##0';
        SI_SHOW.forEach((k, i) => ws.getColumn(6 + i * 3).numFmt = '#,##0.00');
        ws.getColumn(3).width = 40; ws.getColumn(2).width = 14;
        const buf = await wb.xlsx.writeBuffer();
        const a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([buf], {type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}));
        a.download = 'sampath_inventory_' + new Date().toISOString().slice(0,10) + '.xlsx'; a.click(); URL.revokeObjectURL(a.href);
    }
    </script>
<?php endif; ?>
</div>
<?php include 'footer.php'; ?>
