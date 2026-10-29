<?php
// ============================================================
//  inventory_reconciliation_report.php
//  INVENTORY RECONCILIATION REPORT — item-wise stock in VALUE (units x TUR), good stock and damaged stock side by side
//
//  Same options as the Sampath Inventory Report:
//    As At Date   -> the normal report: everything up to that date (Opening not used)
//    Date Range   -> Opening = closing balance as at the From date; the other columns show the transactions after it up to To
//
//  Per item (qty in units; value = qty x TUR):
//    TUR                    -> latest TUR per item: invoice_wise_sales_data.tur, else primary_invoice_wise_sales_data.tur,
//                              else unit price from the purchase import (price per case / UPC)
//    Opening Inventory      -> closing good stock as at the From date (range mode)
//    Primary Purchase       -> unilever_purchase_items.purchase_qty_cases x UPC (PLUS qty rows only)
//    Primary Return         -> unilever_item_return_items.qty x UPC + the MINUS qty rows of the purchase import
//    Pre Secondary          -> primary_invoice_wise_sales_data.units (Daily Pre Secondary Stock)
//    Secondary              -> invoice_wise_sales_data.units (Daily Secondary Stock)
//    Diff                   = Pre Secondary - Secondary
//    Rejected / Adjustment  -> stock_manual_adjustments (rejected qty is deducted, adjustment qty added)
//    Closing - Good Stock   = Opening + Primary Purchase - Primary Return - Secondary - Rejected + Adjustment   (same as the Inventory Report)
//    Closing - Damaged Stock= confirmed Sampath Damage Entry lines (Damage Entry AI Tally & Confirm); its own log
// ============================================================
include 'config.php';
include_once 'auth.php';
requireLogin();
require_once 'item_match_lib.php';     // im_table(), im_load_tur()

function irr_upc($v) { return (float)preg_replace('/[^0-9.]/', '', (string)$v); }

/* ── dates: As At (normal) or Date Range (opening = closing as at From) ── */
$ok_date = function ($v) { $v = trim((string)$v); return $v !== '' && strtotime($v) ? date('Y-m-d', strtotime($v)) : ''; };
$f_mode    = ($_GET['mode'] ?? '') === 'range' ? 'range' : 'asat';
$f_asat    = $ok_date($_GET['as_at_date'] ?? '') ?: date('Y-m-d');
$f_from_in = $ok_date($_GET['date_from'] ?? '');
$f_to_in   = $ok_date($_GET['date_to'] ?? '');
$range_err = '';
if ($f_mode === 'range' && ($f_from_in === '' || $f_to_in === '' || $f_from_in > $f_to_in)) {
    $range_err = 'Select a From Date and a To Date (From must not be after To) to run the date range. Showing the As At report meanwhile.';
    $f_mode = 'asat';
}
$has_open = $f_mode === 'range';
if ($has_open) { $open_to = $f_from_in; $f_from = date('Y-m-d', strtotime($f_from_in . ' +1 day')); $f_to = $f_to_in; }
else           { $open_to = null;       $f_from = '1970-01-01';                                     $f_to = $f_asat; }

$f_items = [];
foreach ((array)($_GET['items'] ?? []) as $v) { $v = trim((string)$v); if ($v !== '') $f_items[] = $v; }

$have = [];
foreach (['unilever_purchase_items','unilever_purchase_batches','unilever_item_return_items','unilever_item_returns','invoice_wise_sales_data',
          'primary_invoice_wise_sales_data','stock_manual_adjustments','sampath_damage_grn_items','sampath_damage_grn_headers','sampath_damage_item_map'] as $t)
    $have[$t] = im_table($conn, $t);

/* ── item master (latest purchase row per item) + TUR ── */
$master = [];
if ($have['unilever_purchase_items']) {
    $q = mysqli_query($conn, "SELECT p.item_code, p.basepack_code, p.item_name, p.upc, p.price_per_case FROM unilever_purchase_items p
        INNER JOIN (SELECT item_code, MAX(id) AS max_id FROM unilever_purchase_items WHERE item_code IS NOT NULL AND item_code <> '' GROUP BY item_code) l ON l.max_id = p.id");
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $upc = irr_upc($r['upc']);
        $master[trim($r['item_code'])] = ['basepack' => $r['basepack_code'], 'name' => $r['item_name'], 'upc' => $upc,
                                          'unit' => $upc > 0 ? (float)$r['price_per_case'] / $upc : 0.0];
    }
}
$tur = im_load_tur($conn);
$price = function ($code) use ($master, $tur) { return (float)($tur[$code] ?? ($master[$code]['unit'] ?? 0)); };

/* ── per-item quantities between two dates ── */
function irr_aggregate($conn, $have, $master, $from, $to, &$errors) {
    $from = mysqli_real_escape_string($conn, $from); $to = mysqli_real_escape_string($conn, $to);
    $acc = []; $names = [];
    $t = function ($c) use (&$acc) { if (!isset($acc[$c])) $acc[$c] = ['pc'=>0.0,'rc'=>0.0,'pre'=>0.0,'sec'=>0.0,'rej'=>0.0,'adj'=>0.0,'dmg'=>0.0,'name'=>null,'bp'=>null]; };
    if ($have['unilever_purchase_items'] && $have['unilever_purchase_batches']) {
        /* purchase import rows with a MINUS qty are purchase returns: purchase = plus rows, return = minus rows */
        $q = mysqli_query($conn, "SELECT p.item_code, SUM(CASE WHEN p.purchase_qty_cases > 0 THEN p.purchase_qty_cases ELSE 0 END) AS q, SUM(CASE WHEN p.purchase_qty_cases < 0 THEN -p.purchase_qty_cases ELSE 0 END) AS rq FROM unilever_purchase_items p JOIN unilever_purchase_batches b ON b.id = p.batch_id
            WHERE p.item_code <> '' AND COALESCE(p.invoice_date, b.received_date) BETWEEN '$from' AND '$to' GROUP BY p.item_code");
        if (!$q) $errors[] = mysqli_error($conn);
        while ($q && $r = mysqli_fetch_assoc($q)) { $c = trim($r['item_code']); $t($c); $acc[$c]['pc'] = (float)$r['q'] * ($master[$c]['upc'] ?? 0); $acc[$c]['rc'] += (float)$r['rq'] * ($master[$c]['upc'] ?? 0); }
    }
    if ($have['unilever_item_return_items'] && $have['unilever_item_returns']) {
        $q = mysqli_query($conn, "SELECT ri.item_code, SUM(ri.qty) AS q FROM unilever_item_return_items ri JOIN unilever_item_returns r ON r.id = ri.return_id
            WHERE ri.item_code <> '' AND r.return_date BETWEEN '$from' AND '$to' GROUP BY ri.item_code");
        if (!$q) $errors[] = mysqli_error($conn);
        while ($q && $r = mysqli_fetch_assoc($q)) { $c = trim($r['item_code']); $t($c); $acc[$c]['rc'] += (float)$r['q'] * ($master[$c]['upc'] ?? 0); }
    }
    foreach (['pre' => 'primary_invoice_wise_sales_data', 'sec' => 'invoice_wise_sales_data'] as $k => $tbl) {
        if (!$have[$tbl]) continue;
        $q = mysqli_query($conn, "SELECT product_code, MAX(basepack_code) AS bp, MAX(product_description) AS nm, SUM(units) AS q FROM $tbl
            WHERE product_code <> '' AND delivery_date BETWEEN '$from' AND '$to' GROUP BY product_code");
        if (!$q) $errors[] = mysqli_error($conn);
        while ($q && $r = mysqli_fetch_assoc($q)) { $c = trim($r['product_code']); $t($c); $acc[$c][$k] = (float)$r['q']; if ($acc[$c]['name'] === null) { $acc[$c]['name'] = $r['nm']; $acc[$c]['bp'] = $r['bp']; } }
    }
    if ($have['stock_manual_adjustments']) {
        $q = mysqli_query($conn, "SELECT item_code, SUM(rejected_qty) AS rq, SUM(adjustment_qty) AS aq FROM stock_manual_adjustments
            WHERE item_code <> '' AND entry_date BETWEEN '$from' AND '$to' GROUP BY item_code");
        if (!$q) $errors[] = mysqli_error($conn);
        while ($q && $r = mysqli_fetch_assoc($q)) { $c = trim($r['item_code']); $t($c); $acc[$c]['rej'] = (float)$r['rq']; $acc[$c]['adj'] = (float)$r['aq']; }
    }
    if ($have['sampath_damage_item_map'] && $have['sampath_damage_grn_items'] && $have['sampath_damage_grn_headers']) {
        $q = mysqli_query($conn, "SELECT m.item_code, MAX(m.item_name) AS nm, SUM(i.qty) AS q FROM sampath_damage_item_map m
            JOIN sampath_damage_grn_items i ON i.id = m.line_id JOIN sampath_damage_grn_headers h ON h.id = i.grn_header_id
            WHERE h.grn_date BETWEEN '$from' AND '$to' GROUP BY m.item_code");
        if (!$q) $errors[] = mysqli_error($conn);
        while ($q && $r = mysqli_fetch_assoc($q)) { $c = trim($r['item_code']); $t($c); $acc[$c]['dmg'] = (float)$r['q']; if ($acc[$c]['name'] === null) $acc[$c]['name'] = $r['nm']; }
    }
    return $acc;
}
$errors = [];
$acc  = irr_aggregate($conn, $have, $master, $f_from, $f_to, $errors);
$acc0 = $has_open ? irr_aggregate($conn, $have, $master, '1970-01-01', $open_to, $errors) : [];

/* item filter options */
$item_opts = [];
foreach ($master as $c => $m) $item_opts[$c] = $m['name'] ?? '';
foreach ([$acc, $acc0] as $set) foreach ($set as $c => $a) if (!isset($item_opts[$c]) || $item_opts[$c] === '') $item_opts[$c] = $a['name'] ?? '';
ksort($item_opts);

/* ── rows ── */
$good = function ($a) { return $a['pc'] - $a['rc'] - $a['sec'] - $a['rej'] + $a['adj']; };
$rows = [];
$tot = ['open_q'=>0,'open_v'=>0,'pc_q'=>0,'pc_v'=>0,'rc_q'=>0,'rc_v'=>0,'pre_q'=>0,'pre_v'=>0,'sec_q'=>0,'sec_v'=>0,'diff_q'=>0,'diff_v'=>0,'oth_q'=>0,'oth_v'=>0,'g_q'=>0,'g_v'=>0,'d_q'=>0,'d_v'=>0,'dopen_q'=>0,'dopen_v'=>0,'dmov_q'=>0,'dmov_v'=>0];
foreach (array_unique(array_merge(array_keys($acc), array_keys($acc0))) as $code) {
    $a  = $acc[$code]  ?? ['pc'=>0.0,'rc'=>0.0,'pre'=>0.0,'sec'=>0.0,'rej'=>0.0,'adj'=>0.0,'dmg'=>0.0,'name'=>null,'bp'=>null];
    $a0 = $acc0[$code] ?? null;
    if ($f_items && !in_array((string)$code, $f_items, true)) continue;
    $open  = $a0 ? $good($a0) : 0.0;
    $dopen = $a0 ? $a0['dmg'] : 0.0;
    $oth   = $a['adj'] - $a['rej'];
    $gq    = $open + $good($a);
    $dq    = $dopen + $a['dmg'];
    if (round($open,2) == 0 && round($good($a),2) == 0 && round($a['pre'],2) == 0 && round($dq,2) == 0 && round($oth,2) == 0 && round($a['pc'],2) == 0) continue;
    $m = $master[$code] ?? []; $p = $price($code);
    $r = ['sku7'=>(string)$code, 'basepack'=>$m['basepack'] ?? $a['bp'] ?? ($a0['bp'] ?? ''), 'name'=>$m['name'] ?? $a['name'] ?? ($a0['name'] ?? ''), 'upc'=>(float)($m['upc'] ?? 0), 'tur'=>$p,
          'open_q'=>$open, 'pc_q'=>$a['pc'], 'rc_q'=>-$a['rc'], 'pre_q'=>$a['pre'], 'sec_q'=>$a['sec'], 'diff_q'=>$a['pre'] - $a['sec'], 'oth_q'=>$oth, 'g_q'=>$gq, 'd_q'=>$dq];
    foreach (['open','pc','rc','pre','sec','diff','oth','g','d'] as $k) { $r[$k . '_v'] = round($r[$k . '_q'] * $p, 2); $r[$k . '_q'] = round($r[$k . '_q'], 2); $tot[$k . '_q'] += $r[$k . '_q']; $tot[$k . '_v'] += $r[$k . '_v']; }
    $tot['dopen_q'] += $dopen;     $tot['dopen_v'] += $dopen * $p;
    $tot['dmov_q']  += $a['dmg'];  $tot['dmov_v']  += $a['dmg'] * $p;
    $rows[] = $r;
}
usort($rows, function ($a, $b) { return strcmp($a['sku7'], $b['sku7']); });

include 'header.php';
function irr_n($v) { return number_format(round($v), 0); }
function irr_v($v) { return number_format($v, 2); }
/* a value cell: value in bold, qty (units) underneath */
function irr_cell($q, $v, $bg = '') {
    if (round($q, 2) == 0 && round($v, 2) == 0) return '<td class="n"' . ($bg ? ' style="background:' . $bg . '"' : '') . '>—</td>';
    $neg = $v < 0 ? ' neg' : '';
    return '<td class="n' . $neg . '"' . ($bg ? ' style="background:' . $bg . '"' : '') . '><strong>' . irr_v($v) . '</strong><div class="qq">' . ($q < 0 ? '-' : '') . irr_n(abs($q)) . ' u</div></td>';
}
$period = $has_open ? ('opening as at ' . $open_to . ' · transactions ' . $f_from . ' to ' . $f_to) : ('as at ' . $f_to);
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.4.0/exceljs.min.js"></script>
<style>
.select2-container .select2-selection--multiple{min-height:37px;border:1.5px solid #e2e6f0;border-radius:8px;}
.select2-container{min-width:320px;max-width:520px;}
.irr{padding:0 4px;}
.irr h2{font-size:22px;font-weight:800;margin:0 0 4px;}
.irr .sub{font-size:12px;color:#8892a4;margin-bottom:16px;}
.irr-card{background:#fff;border:1.5px solid #e2e6f0;border-radius:14px;padding:16px 20px;margin-bottom:18px;}
.irr-f{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;}
.irr-f label{display:block;font-size:10px;font-weight:700;color:#475569;text-transform:uppercase;margin-bottom:4px;}
.irr-f input[type=date]{border:1.5px solid #e2e6f0;border-radius:8px;padding:8px 12px;font-size:13px;}
.irr-b{padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;background:#0a0c14;color:#fff;text-decoration:none;}
.irr-b.g{background:#14532d;} .irr-b.l{background:#f7f8fc;color:#475569;border:1.5px solid #e2e6f0;}
.irr-wrap{overflow:auto;border:1.5px solid #e2e6f0;border-radius:12px;background:#fff;max-height:78vh;}
table.irr-t{width:100%;border-collapse:collapse;font-size:12px;}
table.irr-t th{background:#fbe5d6;color:#111;padding:8px 9px;text-align:center;font-size:11px;border-bottom:2px solid #111;white-space:nowrap;position:sticky;top:0;z-index:2;}
table.irr-t th.top{background:#0a0c14;color:#fff;border-bottom:none;top:0;}
table.irr-t td{padding:6px 9px;border-bottom:1px solid #eef0f6;white-space:nowrap;vertical-align:top;}
table.irr-t td.n{text-align:right;font-family:monospace;}
table.irr-t .qq{font-size:10px;color:#8892a4;font-weight:400;}
table.irr-t tfoot td{background:#fbe5d6;font-weight:800;border-top:2px solid #111;position:sticky;bottom:0;}
.neg strong{color:#dc2626;}
.gd{background:#f0fdf4;} .dg{background:#fff7ed;}
#irrSummary table.irr-t th{position:static;} #irrSummary table.irr-t td.n{text-align:right;}
@media print{#irrDetails{display:block !important;} #irrToggle{display:none;}}
</style>

<div class="irr">
    <h2><i class="fa-solid fa-scale-balanced"></i> Inventory Reconciliation Report</h2>
    <div class="sub">A <strong>summary</strong> of the stock position — click <strong>View Details</strong> for the item-wise table. <strong>As At Date:</strong> the normal report — all transactions up to that date. <strong>Date Range:</strong> Opening Inventory = closing good stock as at the From date, then the transactions after it up to the To date. Every value = units × <strong>TUR</strong> (latest TUR of the item). <strong>Diff</strong> = Pre Secondary − Secondary. Closing is shown on two sides: <strong>Good Stock</strong> (Opening + Primary Purchase − Primary Return − Secondary − Rejected + Adjustment) and <strong>Damaged Stock</strong> (confirmed Sampath damage entries).</div>

    <?php if ($range_err): ?><div class="irr-card" style="background:#fffbeb;border-color:#fcd34d;color:#92400e;"><?php echo htmlspecialchars($range_err); ?></div><?php endif; ?>
    <?php foreach ($errors as $er): ?><div class="irr-card" style="background:#fef2f2;border-color:#fecaca;color:#991b1b;"><?php echo htmlspecialchars($er); ?></div><?php endforeach; ?>

    <form method="GET" class="irr-card">
        <div class="irr-f">
            <div style="display:flex;gap:18px;align-items:flex-end;flex-wrap:wrap;border:1.5px solid #e2e6f0;border-radius:10px;padding:8px 12px;">
                <div>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;text-transform:none;font-size:12px;color:#111;">
                        <input type="radio" name="mode" value="asat" <?php echo $f_mode === 'asat' ? 'checked' : ''; ?> onchange="irrMode()"> <strong>As At Date</strong></label>
                    <input type="date" name="as_at_date" id="irrAsAt" value="<?php echo htmlspecialchars($f_asat); ?>">
                </div>
                <div>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;text-transform:none;font-size:12px;color:#111;">
                        <input type="radio" name="mode" value="range" <?php echo $f_mode === 'range' ? 'checked' : ''; ?> onchange="irrMode()"> <strong>Date Range</strong></label>
                    <div style="display:flex;gap:8px;">
                        <input type="date" name="date_from" id="irrFrom" value="<?php echo htmlspecialchars($f_from_in); ?>" title="From date = opening as at date">
                        <input type="date" name="date_to" id="irrTo" value="<?php echo htmlspecialchars($f_to_in); ?>" title="To date">
                    </div>
                </div>
            </div>
            <div><label>Items</label>
                <select name="items[]" id="itemSelect" multiple style="min-width:320px">
                    <?php foreach ($item_opts as $c => $nm): ?>
                    <option value="<?php echo htmlspecialchars($c); ?>" <?php echo in_array((string)$c, $f_items, true) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c . ' - ' . $nm); ?></option>
                    <?php endforeach; ?>
                </select></div>
            <button class="irr-b" type="submit"><i class="fa-solid fa-filter"></i> Apply</button>
            <a class="irr-b l" href="inventory_reconciliation_report.php">Reset</a>
            <button class="irr-b g" type="button" onclick="irrExport()"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
            <button class="irr-b l" type="button" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        </div>
    </form>

    <!-- SUMMARY -->
    <div class="irr-card" id="irrSummary">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
            <div>
                <div style="font-size:15px;font-weight:800;">Reconciliation Summary</div>
                <div style="font-size:12px;color:#8892a4;"><?php echo htmlspecialchars($period); ?> · <?php echo count($rows); ?> items · values at TUR</div>
            </div>
            <button class="irr-b" type="button" id="irrToggle" onclick="irrDetails()"><i class="fa-solid fa-table-list"></i> View Details</button>
        </div>
        <div style="overflow-x:auto;">
        <table class="irr-t" style="max-width:900px;" id="irrSumTable">
            <thead>
                <tr><th rowspan="2" style="text-align:left">Particulars</th>
                    <th colspan="2" style="background:#bbf7d0">Good Stock</th>
                    <th colspan="2" style="background:#fed7aa">Damaged Stock</th></tr>
                <tr><th style="background:#dcfce7">Units</th><th style="background:#dcfce7">Value (Rs.)</th>
                    <th style="background:#ffedd5">Units</th><th style="background:#ffedd5">Value (Rs.)</th></tr>
            </thead>
            <tbody>
            <?php
            $dash = '<td class="n" style="color:#c4c9d4">—</td><td class="n" style="color:#c4c9d4">—</td>';
            $gcells = function ($k) use ($tot) {
                return '<td class="n">' . ($tot[$k . '_q'] < 0 ? '-' : '') . irr_n(abs($tot[$k . '_q'])) . '</td><td class="n"><strong>' . irr_v($tot[$k . '_v']) . '</strong></td>';
            };
            $dcells = function ($q, $v) {
                return '<td class="n">' . ($q < 0 ? '-' : '') . irr_n(abs($q)) . '</td><td class="n"><strong>' . irr_v($v) . '</strong></td>';
            };
            ?>
                <tr><td>Opening Inventory</td><?php echo $gcells('open'); ?><?php echo $dcells($tot['dopen_q'], $tot['dopen_v']); ?></tr>
                <tr><td>+ Primary Purchase</td><?php echo $gcells('pc'); ?><?php echo $dash; ?></tr>
                <tr><td>− Primary Return</td><?php echo $gcells('rc'); ?><?php echo $dash; ?></tr>
                <tr><td>Pre Secondary (loaded out)</td><?php echo $gcells('pre'); ?><?php echo $dash; ?></tr>
                <tr><td>− Secondary (sold)</td><?php echo $gcells('sec'); ?><?php echo $dash; ?></tr>
                <tr style="background:#fefce8"><td>Diff (Pre Secondary − Secondary)</td><?php echo $gcells('diff'); ?><?php echo $dash; ?></tr>
                <tr><td>± Rejected / Adjustment</td><?php echo $gcells('oth'); ?><?php echo $dash; ?></tr>
                <tr><td>+ Damage entries (confirmed)</td><?php echo $dash; ?><?php echo $dcells($tot['dmov_q'], $tot['dmov_v']); ?></tr>
                <tr style="background:#fbe5d6"><td><strong>Closing Inventory</strong></td>
                    <td class="n" style="background:#f0fdf4"><strong><?php echo irr_n($tot['g_q']); ?></strong></td><td class="n" style="background:#f0fdf4"><strong><?php echo irr_v($tot['g_v']); ?></strong></td>
                    <td class="n" style="background:#fff7ed"><strong><?php echo irr_n($tot['d_q']); ?></strong></td><td class="n" style="background:#fff7ed"><strong><?php echo irr_v($tot['d_v']); ?></strong></td></tr>
                <tr style="background:#e2e8f0"><td><strong>Total Closing Inventory (Good + Damaged)</strong></td>
                    <td class="n" colspan="2" style="text-align:right"><strong><?php echo irr_n($tot['g_q'] + $tot['d_q']); ?> units</strong></td>
                    <td class="n" colspan="2" style="text-align:right"><strong>Rs. <?php echo irr_v($tot['g_v'] + $tot['d_v']); ?></strong></td></tr>
            </tbody>
        </table>
        </div>
    </div>

    <!-- ITEM-WISE DETAILS (hidden until View Details) -->
    <div id="irrDetails" style="display:none;">
    <div class="irr-wrap">
    <table class="irr-t" id="irrTable">
        <thead>
            <tr>
                <th class="top" colspan="5">Item</th>
                <th class="top" colspan="9">Value (units × TUR)</th>
                <th class="top" colspan="2" style="background:#14532d">Closing Inventory</th>
            </tr>
            <tr>
                <th>Basepack</th><th>SKU7</th><th style="text-align:left">Product Name</th><th>UPC</th><th>TUR</th>
                <th>Opening Inventory</th><th>Primary Purchase</th><th>Primary Return</th><th>Pre Secondary</th><th>Secondary</th><th>Diff<br><small>Pre − Sec</small></th><th>Rejected / Adj.</th>
                <th class="gd" style="background:#bbf7d0">Good Stock</th><th style="background:#fed7aa">Damaged Stock</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?php echo htmlspecialchars($r['basepack'] ?? ''); ?></td>
                <td><strong><?php echo htmlspecialchars($r['sku7']); ?></strong></td>
                <td><?php echo htmlspecialchars($r['name']); ?></td>
                <td class="n"><?php echo $r['upc'] ? irr_n($r['upc']) : '—'; ?></td>
                <td class="n"><?php echo $r['tur'] ? irr_v($r['tur']) : '<span style="color:#dc2626" title="No TUR / price found">—</span>'; ?></td>
                <?php echo irr_cell($r['open_q'], $r['open_v']); ?>
                <?php echo irr_cell($r['pc_q'], $r['pc_v']); ?>
                <?php echo irr_cell($r['rc_q'], $r['rc_v']); ?>
                <?php echo irr_cell($r['pre_q'], $r['pre_v']); ?>
                <?php echo irr_cell($r['sec_q'], $r['sec_v']); ?>
                <?php echo irr_cell($r['diff_q'], $r['diff_v']); ?>
                <?php echo irr_cell($r['oth_q'], $r['oth_v']); ?>
                <?php echo irr_cell($r['g_q'], $r['g_v'], '#f0fdf4'); ?>
                <?php echo irr_cell($r['d_q'], $r['d_v'], '#fff7ed'); ?>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="14" style="text-align:center;padding:30px;color:#8892a4;">No stock records found.</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr>
            <td colspan="5" style="text-align:right;">TOTAL — <?php echo count($rows); ?> items</td>
            <?php foreach (['open','pc','rc','pre','sec','diff','oth','g','d'] as $k): ?>
                <td class="n"><?php echo irr_v($tot[$k . '_v']); ?><div class="qq"><?php echo irr_n($tot[$k . '_q']); ?> u</div></td>
            <?php endforeach; ?>
        </tr></tfoot>
    </table>
    </div>
    </div>
</div>

<script>
$(function(){ $('#itemSelect').select2({placeholder:'— All items (code - name) —', allowClear:true, width:'100%'}); });
function irrDetails(){
    const box = document.getElementById('irrDetails'), btn = document.getElementById('irrToggle');
    const open = box.style.display === 'none';
    box.style.display = open ? '' : 'none';
    btn.innerHTML = open ? '<i class="fa-solid fa-eye-slash"></i> Hide Details' : '<i class="fa-solid fa-table-list"></i> View Details';
    if (open) box.scrollIntoView({behavior:'smooth', block:'start'});
}
function irrMode(){
    const range = document.querySelector('input[name=mode]:checked').value === 'range';
    document.getElementById('irrAsAt').disabled = range;
    document.getElementById('irrFrom').disabled = !range;
    document.getElementById('irrTo').disabled   = !range;
}
document.addEventListener('DOMContentLoaded', irrMode);
const IRR_ROWS = <?php echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
const IRR_TOT  = <?php echo json_encode($tot); ?>;
const IRR_PERIOD = <?php echo json_encode($period); ?>;
async function irrExport(){
    const wb = new ExcelJS.Workbook(), ws = wb.addWorksheet('Inventory Reconciliation');
    ws.addRow(['Inventory Reconciliation Report — ' + IRR_PERIOD + ' (value = units x TUR)']).font = {bold:true, size:14};
    const groups = [['open','Opening Inventory'],['pc','Primary Purchase'],['rc','Primary Return'],['pre','Pre Secondary'],['sec','Secondary'],['diff','Diff (Pre - Sec)'],['oth','Rejected / Adj.'],['g','Closing - Good Stock'],['d','Closing - Damaged Stock']];
    const head = ['Basepack','SKU7','Product Name','UPC','TUR'];
    groups.forEach(g => head.push(g[1] + ' Qty', g[1] + ' Value'));
    const h = ws.addRow(head); h.eachCell(c => { c.font = {bold:true}; c.fill = {type:'pattern', pattern:'solid', fgColor:{argb:'FFFBE5D6'}}; });
    IRR_ROWS.forEach(r => { const row = [r.basepack, r.sku7, r.name, r.upc, r.tur]; groups.forEach(g => row.push(r[g[0] + '_q'], r[g[0] + '_v'])); ws.addRow(row); });
    const t = ['TOTAL','','','','']; groups.forEach(g => t.push(IRR_TOT[g[0] + '_q'], IRR_TOT[g[0] + '_v'])); ws.addRow(t).font = {bold:true};
    for (let c = 6; c <= head.length; c++) ws.getColumn(c).numFmt = c % 2 === 1 ? '#,##0.00' : '#,##0';   // value columns = odd, qty = even
    ws.getColumn(5).numFmt = '#,##0.00';
    ws.getColumn(3).width = 40; ws.getColumn(2).width = 12;
    ws.views = [{state:'frozen', xSplit:3, ySplit:2}];
    const buf = await wb.xlsx.writeBuffer();
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([buf], {type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}));
    a.download = 'inventory_reconciliation_' + new Date().toISOString().slice(0,10) + '.xlsx'; a.click(); URL.revokeObjectURL(a.href);
}
</script>
<?php include 'footer.php'; ?>
