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
//    Stock Count (optional)  -> pick a saved physical stock count (sampath_stock_count_*): the count's item qty (and value at TUR) is shown
//                              against the Good Stock closing as at the count date, with the Difference (Physical - System).
//                              In As At mode the report date follows the count date. Items the count has no qty for are not compared.
//  Save Report -> stores a snapshot (filters, summary, every item row) in inventory_recon_saved; the Saved Reports list at the bottom
//                 re-opens it (read-only) or deletes it.
//  Print / PDF -> a creative black & white print layout (A4 landscape) of the live report or a saved one; use the browser's
//                 "Save as PDF" destination to get the PDF.
// ============================================================
include 'config.php';
include_once 'auth.php';
requireLogin();
require_once 'item_match_lib.php';     // im_table(), im_load_tur()

function irr_upc($v) { return (float)preg_replace('/[^0-9.]/', '', (string)$v); }

/* ── optional stock count to compare the good stock with ── */
$have_sc = im_table($conn, 'sampath_stock_count_headers') && im_table($conn, 'sampath_stock_count_items');
$counts = []; $sc = null; $phys = []; $count_warn = '';
$f_count = (int)($_GET['stock_count'] ?? 0);
if ($have_sc) {
    $q = mysqli_query($conn, "SELECT id, count_date, file_name, note FROM sampath_stock_count_headers ORDER BY count_date DESC, id DESC");
    while ($q && $r = mysqli_fetch_assoc($q)) { $counts[] = $r; if ((int)$r['id'] === $f_count) $sc = $r; }
    if ($sc) {
        $q = mysqli_query($conn, "SELECT item_code, SUM(counted_units) AS u FROM sampath_stock_count_items
            WHERE header_id = " . (int)$sc['id'] . " AND counted_units IS NOT NULL AND item_code <> '' GROUP BY item_code");
        while ($q && $r = mysqli_fetch_assoc($q)) $phys[trim($r['item_code'])] = (float)$r['u'];
    }
}

/* ── dates: As At (normal) or Date Range (opening = closing as at From) ── */
$ok_date = function ($v) { $v = trim((string)$v); return $v !== '' && strtotime($v) ? date('Y-m-d', strtotime($v)) : ''; };
$f_mode    = ($_GET['mode'] ?? '') === 'range' ? 'range' : 'asat';
$f_asat    = $ok_date($_GET['as_at_date'] ?? '') ?: date('Y-m-d');
if ($sc && $f_mode === 'asat') $f_asat = substr((string)$sc['count_date'], 0, 10);   // system stock is compared as at the count date
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
if ($sc && $f_to !== substr((string)$sc['count_date'], 0, 10)) $count_warn = 'The stock count is dated ' . substr((string)$sc['count_date'], 0, 10) . ' but the report closes on ' . $f_to . ' — the difference compares counts taken on different dates. Use the As At Date option to compare on the count date.';

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
$tot = ['open_q'=>0,'open_v'=>0,'pc_q'=>0,'pc_v'=>0,'rc_q'=>0,'rc_v'=>0,'pre_q'=>0,'pre_v'=>0,'sec_q'=>0,'sec_v'=>0,'diff_q'=>0,'diff_v'=>0,'oth_q'=>0,'oth_v'=>0,'g_q'=>0,'g_v'=>0,'d_q'=>0,'d_v'=>0,'dopen_q'=>0,'dopen_v'=>0,'dmov_q'=>0,'dmov_v'=>0,
        'ph_q'=>0,'ph_v'=>0,'pd_q'=>0,'pd_v'=>0,'scsys_q'=>0,'scsys_v'=>0,'short_q'=>0,'short_v'=>0,'excess_q'=>0,'excess_v'=>0,'counted'=>0,'uncounted'=>0];
foreach (array_unique(array_merge(array_keys($acc), array_keys($acc0), array_keys($phys))) as $code) {
    $a  = $acc[$code]  ?? ['pc'=>0.0,'rc'=>0.0,'pre'=>0.0,'sec'=>0.0,'rej'=>0.0,'adj'=>0.0,'dmg'=>0.0,'name'=>null,'bp'=>null];
    $a0 = $acc0[$code] ?? null;
    if ($f_items && !in_array((string)$code, $f_items, true)) continue;
    $open  = $a0 ? $good($a0) : 0.0;
    $dopen = $a0 ? $a0['dmg'] : 0.0;
    $oth   = $a['adj'] - $a['rej'];
    $gq    = $open + $good($a);
    $dq    = $dopen + $a['dmg'];
    $counted = isset($phys[$code]);
    if (!$counted && round($open,2) == 0 && round($good($a),2) == 0 && round($a['pre'],2) == 0 && round($dq,2) == 0 && round($oth,2) == 0 && round($a['pc'],2) == 0) continue;
    $m = $master[$code] ?? []; $p = $price($code);
    $r = ['sku7'=>(string)$code, 'basepack'=>$m['basepack'] ?? $a['bp'] ?? ($a0['bp'] ?? ''), 'name'=>$m['name'] ?? $a['name'] ?? ($a0['name'] ?? ''), 'upc'=>(float)($m['upc'] ?? 0), 'tur'=>$p,
          'open_q'=>$open, 'pc_q'=>$a['pc'], 'rc_q'=>-$a['rc'], 'pre_q'=>$a['pre'], 'sec_q'=>$a['sec'], 'diff_q'=>$a['pre'] - $a['sec'], 'oth_q'=>$oth, 'g_q'=>$gq, 'd_q'=>$dq];
    foreach (['open','pc','rc','pre','sec','diff','oth','g','d'] as $k) { $r[$k . '_v'] = round($r[$k . '_q'] * $p, 2); $r[$k . '_q'] = round($r[$k . '_q'], 2); $tot[$k . '_q'] += $r[$k . '_q']; $tot[$k . '_v'] += $r[$k . '_v']; }
    if ($sc) {      // physical count vs the system good stock (counted items only)
        if ($counted) {
            $r['ph_q'] = round($phys[$code], 2);        $r['ph_v'] = round($phys[$code] * $p, 2);
            $r['pd_q'] = round($phys[$code] - $gq, 2);  $r['pd_v'] = round(($phys[$code] - $gq) * $p, 2);
            $tot['ph_q'] += $r['ph_q']; $tot['ph_v'] += $r['ph_v']; $tot['pd_q'] += $r['pd_q']; $tot['pd_v'] += $r['pd_v'];
            $tot['scsys_q'] += $r['g_q']; $tot['scsys_v'] += $r['g_v']; $tot['counted']++;
            if ($r['pd_q'] < 0) { $tot['short_q'] += $r['pd_q'];  $tot['short_v'] += $r['pd_v']; }
            if ($r['pd_q'] > 0) { $tot['excess_q'] += $r['pd_q']; $tot['excess_v'] += $r['pd_v']; }
        } else { $r['ph_q'] = $r['ph_v'] = $r['pd_q'] = $r['pd_v'] = null; $tot['uncounted']++; }
    }
    $tot['dopen_q'] += $dopen;     $tot['dopen_v'] += $dopen * $p;
    $tot['dmov_q']  += $a['dmg'];  $tot['dmov_v']  += $a['dmg'] * $p;
    $rows[] = $r;
}
usort($rows, function ($a, $b) { return strcmp($a['sku7'], $b['sku7']); });

/* ── saved reports + PDF print view ── */
if (session_status() === PHP_SESSION_NONE) session_start();
$who = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'System';
$period = $has_open ? ('opening as at ' . $open_to . ' · transactions ' . $f_from . ' to ' . $f_to) : ('as at ' . $f_to);
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS inventory_recon_saved (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    note VARCHAR(500) NULL,
    period VARCHAR(200) NULL,
    sc_date DATE NULL,
    item_count INT NOT NULL DEFAULT 0,
    good_value DECIMAL(18,2) NOT NULL DEFAULT 0,
    damaged_value DECIMAL(18,2) NOT NULL DEFAULT 0,
    data_json LONGTEXT NOT NULL,
    created_by VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$irr_sc_meta = $sc ? ['count_date' => substr((string)$sc['count_date'], 0, 10), 'file' => (string)($sc['file_name'] ?? '')] : null;
$snap_row = null;
$saved_id = (int)($_GET['saved'] ?? 0);
if ($saved_id) {
    $q = mysqli_query($conn, "SELECT * FROM inventory_recon_saved WHERE id = $saved_id LIMIT 1");
    $snap_row = $q ? mysqli_fetch_assoc($q) : null;
    if ($snap_row && ($data = json_decode($snap_row['data_json'], true))) {       // show the saved snapshot instead of the live figures
        $rows = $data['rows']; $tot = $data['tot']; $period = $data['period']; $has_open = !empty($data['has_open']);
        $sc = $data['sc'] ? ['id' => 0, 'count_date' => $data['sc']['count_date'], 'file_name' => $data['sc']['file']] : null;
        $irr_sc_meta = $data['sc']; $count_warn = ''; $range_err = '';
    } else { $snap_row = null; }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    $base_url = 'inventory_reconciliation_report.php';
    if ($act === 'save' && !$snap_row) {
        $title = trim((string)($_POST['title'] ?? ''));
        if ($title === '') $title = 'Inventory Reconciliation ' . $period;
        $data = ['rows' => $rows, 'tot' => $tot, 'period' => $period, 'has_open' => $has_open, 'sc' => $irr_sc_meta, 'filters' => ['items' => $f_items]];
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $ok = mysqli_query($conn, "INSERT INTO inventory_recon_saved (title, note, period, sc_date, item_count, good_value, damaged_value, data_json, created_by) VALUES ('"
            . mysqli_real_escape_string($conn, mb_substr($title, 0, 200)) . "', '" . mysqli_real_escape_string($conn, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500)) . "', '"
            . mysqli_real_escape_string($conn, $period) . "', " . ($irr_sc_meta ? "'" . $irr_sc_meta['count_date'] . "'" : 'NULL') . ", " . count($rows) . ", "
            . (float)$tot['g_v'] . ", " . (float)$tot['d_v'] . ", '" . mysqli_real_escape_string($conn, $json) . "', '" . mysqli_real_escape_string($conn, $who) . "')");
        $newid = $ok ? mysqli_insert_id($conn) : 0;
        header('Location: ' . $base_url . '?' . http_build_query($ok ? ['saved' => $newid, 'msg' => 'Report saved.'] : ['err' => 'Could not save the report: ' . mysqli_error($conn)]));
        exit;
    }
    if ($act === 'delete_saved') {
        $did = (int)($_POST['id'] ?? 0);
        if ($did > 0) mysqli_query($conn, "DELETE FROM inventory_recon_saved WHERE id = $did");
        header('Location: ' . $base_url . '?' . http_build_query(['msg' => 'Saved report deleted.']));
        exit;
    }
}

/* ── creative black & white print view (live or saved) — use the browser's "Save as PDF" for the PDF ── */
function irr_pu($q) { return $q < 0 ? '(' . number_format(abs($q), 0) . ')' : number_format($q, 0); }
function irr_pf($v, $d = 2) { $t = number_format(abs($v), $d); return $v < -0.004 ? '(' . $t . ')' : $t; }
function irr_pcell($q, $v) {
    if (round($q, 2) == 0 && round($v, 2) == 0) return '<td class="n z">–</td>';
    return '<td class="n"><b>' . irr_pf($v) . '</b><i>' . ($q < 0 ? '(' : '') . number_format(abs($q), 0) . ($q < 0 ? ')' : '') . ' u</i></td>';
}
if (isset($_GET['print'])) {
    $title_p = $snap_row ? $snap_row['title'] : 'Inventory Reconciliation Report';
    $scd = $sc ? substr((string)$sc['count_date'], 0, 10) : null;
    $grp = [['open','Opening Inventory'],['pc','Primary Purchase'],['rc','Primary Return'],['pre','Pre Secondary'],['sec','Secondary'],['diff','Diff (Pre − Sec)'],['oth','Rejected / Adj.'],['g','Good Stock'],['d','Damaged Stock']];
    if ($sc) { $grp[] = ['ph','Physical Count']; $grp[] = ['pd','Difference']; }
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><title><?php echo htmlspecialchars($title_p); ?></title>
<style>
@page{size:A4 landscape;margin:10mm 9mm 14mm;@bottom-right{content:"Page " counter(page) " of " counter(pages);font:7pt Georgia,serif;color:#000}@bottom-left{content:"Inventory Reconciliation Report";font:7pt Georgia,serif;color:#000}}
*{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
body{margin:0;font-family:"Helvetica Neue",Arial,sans-serif;color:#000;background:#fff;font-size:9pt;}
.bar{position:sticky;top:0;background:#000;color:#fff;padding:8px 14px;display:flex;gap:10px;align-items:center;font-size:12px;z-index:5}
.bar button,.bar a{background:#fff;color:#000;border:0;padding:6px 14px;font-weight:700;cursor:pointer;text-decoration:none;font-size:12px}
@media print{.bar{display:none}}
.wrap{padding:6mm 2mm 0;}
.mast{display:flex;border:2.5pt solid #000;}
.mast .l{background:#000;color:#fff;padding:9mm 8mm;flex:1;position:relative;}
.mast .l:after{content:"";position:absolute;right:0;top:0;bottom:0;width:22mm;background:repeating-linear-gradient(135deg,#fff 0 1.2pt,#000 1.2pt 4pt);}
.mast h1{margin:0;font:900 25pt/1 "Arial Black",Impact,Arial,sans-serif;letter-spacing:2pt;text-transform:uppercase;}
.mast .tag{margin-top:3mm;font-size:8.5pt;letter-spacing:3pt;text-transform:uppercase;}
.mast .r{width:78mm;padding:4mm 5mm;font-size:8pt;line-height:1.6}
.mast .r b{display:inline-block;min-width:23mm;text-transform:uppercase;font-size:6.8pt;letter-spacing:1pt;}
.glance{display:grid;grid-template-columns:repeat(4,1fr);gap:0;margin:5mm 0 4mm;border:1.2pt solid #000;}
.glance div{padding:3.5mm 4mm;border-right:1.2pt solid #000;}
.glance div:last-child{border-right:0}
.glance small{display:block;font-size:6.8pt;letter-spacing:1.2pt;text-transform:uppercase;border-bottom:1.2pt solid #000;padding-bottom:1.2mm;margin-bottom:1.5mm}
.glance strong{font:900 14pt/1.1 "Arial Black",Arial,sans-serif;font-variant-numeric:tabular-nums}
.glance span{font-size:7pt;display:block;margin-top:1mm}
h2{font:900 10pt Arial,sans-serif;letter-spacing:2pt;text-transform:uppercase;margin:5mm 0 2mm;display:flex;align-items:center;gap:3mm}
h2:after{content:"";flex:1;border-top:1.2pt solid #000;}
table{border-collapse:collapse;width:100%;}
.sum{width:62%}
.sum th{background:#000;color:#fff;padding:2mm 3mm;font-size:7.5pt;letter-spacing:1pt;text-transform:uppercase;text-align:right}
.sum th:first-child{text-align:left}
.sum td{padding:1.6mm 3mm;border-bottom:.5pt solid #000;font-size:8.5pt}
.sum td.n{text-align:right;font-variant-numeric:tabular-nums}
.sum tr.sub td{background:#e6e6e6;font-weight:700}
.sum tr.tot td{border-top:2.2pt double #000;border-bottom:0;font-weight:900;background:repeating-linear-gradient(135deg,#fff 0 1.5pt,#e0e0e0 1.5pt 3pt)}
.det{font-size:6.6pt;margin-top:1mm}
.det thead{display:table-header-group}
.det th{background:#000;color:#fff;padding:1.5mm 1.2mm;text-align:center;font-weight:700;font-size:6.2pt;letter-spacing:.4pt;text-transform:uppercase;border-right:.5pt solid #fff}
.det td{padding:1mm 1.2mm;border-bottom:.4pt solid #888;vertical-align:top}
.det tr{page-break-inside:avoid}
.det tbody tr:nth-child(even) td{background:#f1f1f1}
.det td.n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.det td.n b{display:block;font-weight:700}
.det td.n i{display:block;font-style:normal;font-size:5.6pt;color:#444}
.det td.z{color:#999}
.det td.sku{font-weight:700;white-space:nowrap}
.det tfoot td{border-top:2.2pt double #000;font-weight:900;background:repeating-linear-gradient(135deg,#fff 0 1.5pt,#dcdcdc 1.5pt 3pt)}
.cols2{display:flex;gap:6mm;align-items:flex-start}
.note{font-size:7.5pt;border:1pt solid #000;padding:3mm;flex:1;line-height:1.5}
.note b{text-transform:uppercase;letter-spacing:1pt;font-size:6.8pt}
.sign{display:flex;gap:10mm;margin-top:12mm;page-break-inside:avoid}
.sign div{flex:1;border-top:1.2pt solid #000;padding-top:1.5mm;font-size:7pt;letter-spacing:1.5pt;text-transform:uppercase;text-align:center}
</style></head><body>
<div class="bar"><b>Inventory Reconciliation Report — print / PDF preview</b>
  <button onclick="window.print()">Print / Save as PDF</button><button onclick="window.close()">Close</button>
  <span style="opacity:.7">Choose “Save as PDF” as the printer destination to get the PDF. Landscape A4.</span></div>
<div class="wrap">
<div class="mast">
  <div class="l"><h1>Inventory<br>Reconciliation</h1><div class="tag">Good stock · Damaged stock · valued at TUR</div></div>
  <div class="r">
    <b>Report</b> <?php echo htmlspecialchars($title_p); ?><br>
    <b>Period</b> <?php echo htmlspecialchars($period); ?><br>
    <b>Stock count</b> <?php echo $scd ? htmlspecialchars($scd) : '—'; ?><br>
    <b>Items</b> <?php echo count($rows); ?><br>
    <b><?php echo $snap_row ? 'Saved' : 'Generated'; ?></b> <?php echo htmlspecialchars($snap_row ? substr((string)$snap_row['created_at'], 0, 16) . ' · ' . $snap_row['created_by'] : date('Y-m-d H:i') . ' · ' . $who); ?>
  </div>
</div>

<div class="glance">
  <div><small>Closing — Good Stock</small><strong>Rs. <?php echo irr_pf($tot['g_v']); ?></strong><span><?php echo irr_pu($tot['g_q']); ?> units</span></div>
  <div><small>Closing — Damaged Stock</small><strong>Rs. <?php echo irr_pf($tot['d_v']); ?></strong><span><?php echo irr_pu($tot['d_q']); ?> units</span></div>
  <div><small>Total Closing Inventory</small><strong>Rs. <?php echo irr_pf($tot['g_v'] + $tot['d_v']); ?></strong><span><?php echo irr_pu($tot['g_q'] + $tot['d_q']); ?> units</span></div>
  <?php if ($sc): ?><div><small>Stock count difference</small><strong>Rs. <?php echo irr_pf($tot['pd_v']); ?></strong><span><?php echo ($tot['pd_q'] < 0 ? '(' : '') . number_format(abs($tot['pd_q']), 0) . ($tot['pd_q'] < 0 ? ')' : ''); ?> units · physical − system</span></div>
  <?php else: ?><div><small>Diff · Pre − Secondary</small><strong>Rs. <?php echo irr_pf($tot['diff_v']); ?></strong><span><?php echo irr_pu($tot['diff_q']); ?> units</span></div><?php endif; ?>
</div>

<h2>Summary</h2>
<div class="cols2">
<table class="sum">
  <thead><tr><th>Particulars</th><th>Good · Units</th><th>Good · Value</th><th>Damaged · Units</th><th>Damaged · Value</th></tr></thead>
  <tbody>
  <?php
  $sr = [['Opening Inventory','open',true],['+ Primary Purchase','pc',false],['− Primary Return','rc',false],['Pre Secondary (loaded out)','pre',false],['− Secondary (sold)','sec',false],['Diff (Pre Secondary − Secondary)','diff',false],['± Rejected / Adjustment','oth',false]];
  foreach ($sr as $x): ?>
    <tr><td><?php echo $x[0]; ?></td><td class="n"><?php echo ($tot[$x[1].'_q'] < 0 ? '(' : '') . number_format(abs($tot[$x[1].'_q']), 0) . ($tot[$x[1].'_q'] < 0 ? ')' : ''); ?></td><td class="n"><b><?php echo irr_pf($tot[$x[1].'_v']); ?></b></td>
        <?php if ($x[2]): ?><td class="n"><?php echo irr_pu($tot['dopen_q']); ?></td><td class="n"><b><?php echo irr_pf($tot['dopen_v']); ?></b></td><?php else: ?><td class="n">–</td><td class="n">–</td><?php endif; ?></tr>
  <?php endforeach; ?>
    <tr><td>+ Damage entries (confirmed)</td><td class="n">–</td><td class="n">–</td><td class="n"><?php echo irr_pu($tot['dmov_q']); ?></td><td class="n"><b><?php echo irr_pf($tot['dmov_v']); ?></b></td></tr>
    <tr class="sub"><td>Closing Inventory</td><td class="n"><?php echo irr_pu($tot['g_q']); ?></td><td class="n"><?php echo irr_pf($tot['g_v']); ?></td><td class="n"><?php echo irr_pu($tot['d_q']); ?></td><td class="n"><?php echo irr_pf($tot['d_v']); ?></td></tr>
  <?php if ($sc): ?>
    <tr><td>System good stock (counted items)</td><td class="n"><?php echo irr_pu($tot['scsys_q']); ?></td><td class="n"><b><?php echo irr_pf($tot['scsys_v']); ?></b></td><td class="n">–</td><td class="n">–</td></tr>
    <tr><td>Physical stock count — <?php echo htmlspecialchars($scd); ?></td><td class="n"><?php echo irr_pu($tot['ph_q']); ?></td><td class="n"><b><?php echo irr_pf($tot['ph_v']); ?></b></td><td class="n">–</td><td class="n">–</td></tr>
    <tr><td>Short (physical below system)</td><td class="n">(<?php echo number_format(abs($tot['short_q']), 0); ?>)</td><td class="n"><b><?php echo irr_pf($tot['short_v']); ?></b></td><td class="n">–</td><td class="n">–</td></tr>
    <tr><td>Excess (physical above system)</td><td class="n"><?php echo irr_pu($tot['excess_q']); ?></td><td class="n"><b><?php echo irr_pf($tot['excess_v']); ?></b></td><td class="n">–</td><td class="n">–</td></tr>
    <tr class="sub"><td>Difference (Physical − System)</td><td class="n"><?php echo ($tot['pd_q'] < 0 ? '(' : '') . number_format(abs($tot['pd_q']), 0) . ($tot['pd_q'] < 0 ? ')' : ''); ?></td><td class="n"><?php echo irr_pf($tot['pd_v']); ?></td><td class="n">–</td><td class="n">–</td></tr>
  <?php endif; ?>
    <tr class="tot"><td>Total Closing Inventory (Good + Damaged)</td><td class="n" colspan="2"><?php echo irr_pu($tot['g_q'] + $tot['d_q']); ?> units</td><td class="n" colspan="2">Rs. <?php echo irr_pf($tot['g_v'] + $tot['d_v']); ?></td></tr>
  </tbody>
</table>
<div class="note"><b>How to read this report</b><br>
  Every value = units × the item's latest TUR. Good Stock = Opening + Primary Purchase − Primary Return − Secondary − Rejected + Adjustment. Damaged Stock = confirmed Sampath damage entries, kept as its own stock.
  Diff = Pre Secondary − Secondary. Figures in ( ) are negative.<?php if ($sc): ?> Physical count is the saved stock count of <?php echo htmlspecialchars($scd); ?>; items it has no qty for are not compared (<?php echo (int)$tot['uncounted']; ?> in this report).<?php endif; ?></div>
</div>

<h2>Item detail</h2>
<table class="det">
  <thead><tr><th>SKU7</th><th style="text-align:left">Product</th><th>UPC</th><th>TUR</th><?php foreach ($grp as $g): ?><th><?php echo $g[1]; ?></th><?php endforeach; ?></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td class="sku"><?php echo htmlspecialchars($r['sku7']); ?></td><td><?php echo htmlspecialchars($r['name']); ?></td>
        <td class="n"><?php echo $r['upc'] ? number_format($r['upc'], 0) : '–'; ?></td><td class="n"><?php echo $r['tur'] ? number_format($r['tur'], 2) : '–'; ?></td>
        <?php foreach ($grp as $g): $q = $r[$g[0].'_q'] ?? null; if ($q === null) echo '<td class="n z">–</td>'; else echo irr_pcell($q, $r[$g[0].'_v']); endforeach; ?></tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot><tr><td colspan="4" style="text-align:right">TOTAL — <?php echo count($rows); ?> items</td>
    <?php foreach ($grp as $g): echo irr_pcell($tot[$g[0].'_q'], $tot[$g[0].'_v']); endforeach; ?></tr></tfoot>
</table>
<div class="sign"><div>Prepared by</div><div>Checked by</div><div>Approved by</div></div>
</div>
<script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 500); });</script>
</body></html><?php
    exit;
}

include 'header.php';
function irr_n($v) { return number_format(round($v), 0); }
function irr_v($v) { return number_format($v, 2); }
/* a value cell: value in bold, qty (units) underneath */
function irr_cell($q, $v, $bg = '') {
    if (round($q, 2) == 0 && round($v, 2) == 0) return '<td class="n"' . ($bg ? ' style="background:' . $bg . '"' : '') . '>—</td>';
    $neg = $v < 0 ? ' neg' : '';
    return '<td class="n' . $neg . '"' . ($bg ? ' style="background:' . $bg . '"' : '') . '><strong>' . irr_v($v) . '</strong><div class="qq">' . ($q < 0 ? '-' : '') . irr_n(abs($q)) . ' u</div></td>';
}
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
.vi{font-size:10px;font-weight:700;color:#6d28d9;text-decoration:underline;margin-left:6px;white-space:nowrap;} tr.clk:hover td{background:#ede9fe !important;}
.dmod{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;} .dmod.open{display:flex;}
.dbox{background:#fff;border-radius:14px;width:min(1000px,96vw);max-height:90vh;display:flex;flex-direction:column;}
.dbox header{padding:14px 18px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;}
.dbox .body{padding:0 16px 16px;overflow:auto;}
.dx{border:none;background:#f3f4f6;border-radius:8px;width:32px;height:32px;cursor:pointer;font-size:16px;}
.dchip{border:1.5px solid #e2e6f0;background:#fff;border-radius:20px;padding:4px 12px;font-size:12px;font-weight:700;cursor:pointer;} .dchip.on{background:#0a0c14;color:#fff;border-color:#0a0c14;}
.dsh{color:#dc2626;font-weight:800;} .dex{color:#15803d;font-weight:800;}
#irrSummary table.irr-t th{position:static;} #irrSummary table.irr-t td.n{text-align:right;}
@media print{#irrDetails{display:block !important;} #irrToggle{display:none;}}
</style>

<div class="irr">
    <h2><i class="fa-solid fa-scale-balanced"></i> Inventory Reconciliation Report</h2>
    <div class="sub">A <strong>summary</strong> of the stock position — click <strong>View Details</strong> for the item-wise table. <strong>As At Date:</strong> the normal report — all transactions up to that date. <strong>Date Range:</strong> Opening Inventory = closing good stock as at the From date, then the transactions after it up to the To date. Every value = units × <strong>TUR</strong> (latest TUR of the item). <strong>Diff</strong> = Pre Secondary − Secondary. Closing is shown on two sides: <strong>Good Stock</strong> (Opening + Primary Purchase − Primary Return − Secondary − Rejected + Adjustment) and <strong>Damaged Stock</strong> (confirmed Sampath damage entries).</div>

    <?php if (!empty($_GET['msg'])): ?><div class="irr-card" style="background:#dcfce7;border-color:#86efac;color:#166534;"><?php echo htmlspecialchars($_GET['msg']); ?></div><?php endif; ?>
    <?php if (!empty($_GET['err'])): ?><div class="irr-card" style="background:#fef2f2;border-color:#fecaca;color:#991b1b;"><?php echo htmlspecialchars($_GET['err']); ?></div><?php endif; ?>
    <?php if ($snap_row): ?>
    <div class="irr-card" style="background:#0a0c14;color:#fff;border-color:#0a0c14;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <div><div style="font-size:11px;letter-spacing:1px;text-transform:uppercase;opacity:.7">Saved report (read-only snapshot)</div>
            <div style="font-size:17px;font-weight:800"><?php echo htmlspecialchars($snap_row['title']); ?></div>
            <div style="font-size:12px;opacity:.75"><?php echo htmlspecialchars($period); ?> · saved <?php echo htmlspecialchars(substr((string)$snap_row['created_at'], 0, 16) . ' by ' . $snap_row['created_by']); ?><?php echo $snap_row['note'] ? ' · ' . htmlspecialchars($snap_row['note']) : ''; ?></div></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a class="irr-b" style="background:#fff;color:#000" href="javascript:void(0)" onclick="irrPrint()"><i class="fa-solid fa-file-pdf"></i> PDF / Print</a>
            <a class="irr-b" style="background:#334155" href="inventory_reconciliation_report.php">Back to live report</a>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($count_warn): ?><div class="irr-card" style="background:#fffbeb;border-color:#fcd34d;color:#92400e;"><?php echo htmlspecialchars($count_warn); ?></div><?php endif; ?>
    <?php if ($sc && $f_mode === 'asat' && !$snap_row): ?><div class="irr-card" style="background:#eff6ff;border-color:#bfdbfe;color:#1e40af;">Stock count of <strong><?php echo htmlspecialchars(substr((string)$sc['count_date'], 0, 10)); ?></strong> selected — the report is as at that date so the system good stock can be compared with the physical count.</div><?php endif; ?>
    <?php if ($range_err): ?><div class="irr-card" style="background:#fffbeb;border-color:#fcd34d;color:#92400e;"><?php echo htmlspecialchars($range_err); ?></div><?php endif; ?>
    <?php foreach ($errors as $er): ?><div class="irr-card" style="background:#fef2f2;border-color:#fecaca;color:#991b1b;"><?php echo htmlspecialchars($er); ?></div><?php endforeach; ?>

    <?php if (!$snap_row): ?>
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
            <?php if ($have_sc): ?>
            <div><label>Stock Count (physical)</label>
                <select name="stock_count" style="border:1.5px solid #e2e6f0;border-radius:8px;padding:8px 12px;font-size:13px;min-width:230px;max-width:100%;">
                    <option value="">— none —</option>
                    <?php foreach ($counts as $c): ?><option value="<?php echo (int)$c['id']; ?>" <?php echo $sc && (int)$sc['id'] === (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars(substr($c['count_date'], 0, 10) . ($c['file_name'] ? ' · ' . $c['file_name'] : '') . ($c['note'] ? ' · ' . $c['note'] : '')); ?></option><?php endforeach; ?>
                </select></div>
            <?php endif; ?>
            <div><label>Items</label>
                <select name="items[]" id="itemSelect" multiple style="min-width:320px">
                    <?php foreach ($item_opts as $c => $nm): ?>
                    <option value="<?php echo htmlspecialchars($c); ?>" <?php echo in_array((string)$c, $f_items, true) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c . ' - ' . $nm); ?></option>
                    <?php endforeach; ?>
                </select></div>
            <button class="irr-b" type="submit"><i class="fa-solid fa-filter"></i> Apply</button>
            <a class="irr-b l" href="inventory_reconciliation_report.php">Reset</a>
            <button class="irr-b g" type="button" onclick="irrExport()"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
            <button class="irr-b l" type="button" onclick="irrPrint()"><i class="fa-solid fa-file-pdf"></i> PDF / Print</button>
            <button class="irr-b" type="button" style="background:#6d28d9" onclick="irrSave()"><i class="fa-solid fa-floppy-disk"></i> Save Report</button>
        </div>
    </form>
    <form method="POST" id="irrSaveForm" style="display:none"><input type="hidden" name="action" value="save"><input type="hidden" name="title" id="irrSaveTitle"><input type="hidden" name="note" id="irrSaveNote"></form>
    <?php endif; ?>

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
                <tr style="background:#fefce8;cursor:pointer" onclick="irrDiff('presec')" class="clk" title="Click to see the items with details"><td>Diff (Pre Secondary − Secondary) <span class="vi">view items ▸</span></td><?php echo $gcells('diff'); ?><?php echo $dash; ?></tr>
                <tr><td>± Rejected / Adjustment</td><?php echo $gcells('oth'); ?><?php echo $dash; ?></tr>
                <tr><td>+ Damage entries (confirmed)</td><?php echo $dash; ?><?php echo $dcells($tot['dmov_q'], $tot['dmov_v']); ?></tr>
                <tr style="background:#fbe5d6"><td><strong>Closing Inventory</strong></td>
                    <td class="n" style="background:#f0fdf4"><strong><?php echo irr_n($tot['g_q']); ?></strong></td><td class="n" style="background:#f0fdf4"><strong><?php echo irr_v($tot['g_v']); ?></strong></td>
                    <td class="n" style="background:#fff7ed"><strong><?php echo irr_n($tot['d_q']); ?></strong></td><td class="n" style="background:#fff7ed"><strong><?php echo irr_v($tot['d_v']); ?></strong></td></tr>
                <?php if ($sc): ?>
                <tr style="background:#f5f3ff"><td colspan="5" style="font-weight:800;color:#5b21b6">Stock Count — <?php echo htmlspecialchars(substr((string)$sc['count_date'], 0, 10)); ?> (good stock, <?php echo (int)$tot['counted']; ?> item(s) counted<?php echo $tot['uncounted'] ? ', ' . (int)$tot['uncounted'] . ' in the report not in the count' : ''; ?>)</td></tr>
                <tr><td>System good stock (counted items)</td><?php echo $dcells($tot['scsys_q'], $tot['scsys_v']); ?><?php echo $dash; ?></tr>
                <tr style="background:#f5f3ff"><td><strong>Physical Stock Count</strong></td><?php echo $dcells($tot['ph_q'], $tot['ph_v']); ?><?php echo $dash; ?></tr>
                <tr style="cursor:pointer" onclick="irrDiff('short')" class="clk" title="Click to see the items with details"><td style="color:#dc2626">Short (physical below system) <span class="vi">view items ▸</span></td><?php echo $dcells($tot['short_q'], $tot['short_v']); ?><?php echo $dash; ?></tr>
                <tr style="cursor:pointer" onclick="irrDiff('excess')" class="clk" title="Click to see the items with details"><td style="color:#15803d">Excess (physical above system) <span class="vi">view items ▸</span></td><?php echo $dcells($tot['excess_q'], $tot['excess_v']); ?><?php echo $dash; ?></tr>
                <tr style="background:#ede9fe;cursor:pointer" onclick="irrDiff('count')" class="clk" title="Click to see the items with details"><td><strong>Difference (Physical − System)</strong> <span class="vi">view items ▸</span></td><?php echo $dcells($tot['pd_q'], $tot['pd_v']); ?><?php echo $dash; ?></tr>
                <?php endif; ?>
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
                <?php if ($sc): ?><th class="top" colspan="2" style="background:#6d28d9">Stock Count <?php echo htmlspecialchars(substr((string)$sc['count_date'], 0, 10)); ?> (good stock)</th><?php endif; ?>
            </tr>
            <tr>
                <th>Basepack</th><th>SKU7</th><th style="text-align:left">Product Name</th><th>UPC</th><th>TUR</th>
                <th>Opening Inventory</th><th>Primary Purchase</th><th>Primary Return</th><th>Pre Secondary</th><th>Secondary</th><th>Diff<br><small>Pre − Sec</small></th><th>Rejected / Adj.</th>
                <th class="gd" style="background:#bbf7d0">Good Stock</th><th style="background:#fed7aa">Damaged Stock</th>
                <?php if ($sc): ?><th style="background:#ddd6fe">Physical Count</th><th style="background:#ede9fe">Difference<br><small>Physical − System</small></th><?php endif; ?>
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
                <?php if ($sc): ?><?php echo $r['ph_q'] === null ? '<td class="n" style="color:#c4c9d4" title="not in the stock count">—</td><td class="n" style="color:#c4c9d4">—</td>' : irr_cell($r['ph_q'], $r['ph_v'], '#f5f3ff') . irr_cell($r['pd_q'], $r['pd_v'], '#ede9fe'); ?><?php endif; ?>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="<?php echo 14 + ($sc ? 2 : 0); ?>" style="text-align:center;padding:30px;color:#8892a4;">No stock records found.</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr>
            <td colspan="5" style="text-align:right;">TOTAL — <?php echo count($rows); ?> items</td>
            <?php foreach (['open','pc','rc','pre','sec','diff','oth','g','d'] as $k): ?>
                <td class="n"><?php echo irr_v($tot[$k . '_v']); ?><div class="qq"><?php echo irr_n($tot[$k . '_q']); ?> u</div></td>
            <?php endforeach; ?>
            <?php if ($sc): foreach (['ph','pd'] as $k): ?>
                <td class="n"><?php echo irr_v($tot[$k . '_v']); ?><div class="qq"><?php echo irr_n($tot[$k . '_q']); ?> u</div></td>
            <?php endforeach; endif; ?>
        </tr></tfoot>
    </table>
    </div>
    </div>

    <!-- SAVED REPORTS -->
    <?php
    $saved_list = [];
    $q = mysqli_query($conn, "SELECT id, title, note, period, sc_date, item_count, good_value, damaged_value, created_by, created_at FROM inventory_recon_saved ORDER BY id DESC LIMIT 100");
    while ($q && $r = mysqli_fetch_assoc($q)) $saved_list[] = $r;
    ?>
    <div class="irr-card" style="margin-top:18px">
        <div style="font-size:15px;font-weight:800;margin-bottom:10px;"><i class="fa-solid fa-box-archive"></i> Saved Reports <span style="font-size:12px;font-weight:500;color:#8892a4">(<?php echo count($saved_list); ?>)</span></div>
        <div style="overflow-x:auto;">
        <table class="irr-t" style="min-width:760px">
            <thead><tr><th style="text-align:left">Title</th><th>Period</th><th>Stock count</th><th>Items</th><th>Good Stock (Rs.)</th><th>Damaged (Rs.)</th><th>Saved</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($saved_list as $sv): ?>
                <tr style="<?php echo $snap_row && (int)$snap_row['id'] === (int)$sv['id'] ? 'background:#f1f5f9' : ''; ?>">
                    <td><a href="?saved=<?php echo (int)$sv['id']; ?>" style="font-weight:700;color:#0a0c14"><?php echo htmlspecialchars($sv['title']); ?></a><?php echo $sv['note'] ? '<div style="font-size:10.5px;color:#8892a4">' . htmlspecialchars($sv['note']) . '</div>' : ''; ?></td>
                    <td style="white-space:nowrap;font-size:11px"><?php echo htmlspecialchars($sv['period']); ?></td>
                    <td><?php echo $sv['sc_date'] ? htmlspecialchars($sv['sc_date']) : '—'; ?></td>
                    <td class="n"><?php echo (int)$sv['item_count']; ?></td>
                    <td class="n"><?php echo irr_v($sv['good_value']); ?></td>
                    <td class="n"><?php echo irr_v($sv['damaged_value']); ?></td>
                    <td style="white-space:nowrap;font-size:11px"><?php echo htmlspecialchars(substr((string)$sv['created_at'], 0, 16) . ' · ' . $sv['created_by']); ?></td>
                    <td style="white-space:nowrap">
                        <a class="irr-b" style="padding:4px 10px;font-size:11px" href="?saved=<?php echo (int)$sv['id']; ?>">View</a>
                        <a class="irr-b l" style="padding:4px 10px;font-size:11px" target="_blank" href="?saved=<?php echo (int)$sv['id']; ?>&print=1"><i class="fa-solid fa-file-pdf"></i> PDF</a>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this saved report?')"><input type="hidden" name="action" value="delete_saved"><input type="hidden" name="id" value="<?php echo (int)$sv['id']; ?>">
                            <button class="irr-b" style="padding:4px 10px;font-size:11px;background:#dc2626" type="submit"><i class="fa-solid fa-trash"></i></button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$saved_list): ?><tr><td colspan="8" style="text-align:center;padding:18px;color:#8892a4">No saved reports yet — set the report you want and press <strong>Save Report</strong>.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<div class="dmod" id="irrDMod" onclick="if(event.target===this)irrDClose()">
  <div class="dbox">
    <header>
      <div><div style="font-size:16px;font-weight:800" id="irrDTitle">Difference items</div><div style="font-size:12px;color:#6b7280" id="irrDSub"></div></div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <span id="irrDChips"></span>
        <button class="irr-b g" type="button" style="padding:6px 12px;font-size:12px" onclick="irrDExport()"><i class="fa-solid fa-file-excel"></i> Excel</button>
        <button class="dx" type="button" onclick="irrDClose()">&times;</button>
      </div>
    </header>
    <div class="body"><table class="irr-t" style="margin-top:10px"><thead id="irrDHead"></thead><tbody id="irrDBody"></tbody><tfoot id="irrDFoot"></tfoot></table></div>
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
/* ── click a difference line -> the items behind it, with details ── */
let irrDCur = null;
const irrFm = (v, d) => (v === null || v === undefined || v === '') ? '—' : Number(v).toLocaleString('en-US', {minimumFractionDigits: d || 0, maximumFractionDigits: d || 0});
const irrSg = v => (v < 0 ? '-' : (v > 0 ? '+' : '')) + irrFm(Math.abs(v), 0);
function irrDiff(type, filter){
    let rows, title, sub, cols;
    if (type === 'presec') {
        rows = IRR_ROWS.filter(r => Math.round(r.diff_q * 100) !== 0)
            .map(r => ({sku7: r.sku7, name: r.name, tur: r.tur, a: r.pre_q, b: r.sec_q, dq: r.diff_q, dv: r.diff_v}));
        title = 'Diff — Pre Secondary − Secondary'; sub = 'Items where the pre-secondary (loaded) qty differs from the secondary (sold) qty';
        cols = ['Pre Secondary (units)', 'Secondary (units)'];
    } else {
        rows = IRR_ROWS.filter(r => r.pd_q !== null && r.pd_q !== undefined && Math.round(r.pd_q * 100) !== 0)
            .map(r => ({sku7: r.sku7, name: r.name, tur: r.tur, a: r.g_q, b: r.ph_q, dq: r.pd_q, dv: r.pd_v}));
        title = 'Stock count difference — Physical − System (good stock)'; sub = 'Items where the physical count differs from the system good stock';
        cols = ['System good stock (units)', 'Physical count (units)'];
        if (type === 'short') filter = 'short'; if (type === 'excess') filter = 'excess';
    }
    irrDCur = {type, rows, title, sub, cols, filter: filter || 'all'};
    document.getElementById('irrDMod').classList.add('open');
    irrDRender();
}
function irrDRender(){
    const c = irrDCur; let rows = c.rows;
    if (c.type !== 'presec') {
        if (c.filter === 'short') rows = rows.filter(r => r.dq < 0); else if (c.filter === 'excess') rows = rows.filter(r => r.dq > 0);
        document.getElementById('irrDChips').innerHTML = ['all','short','excess'].map(k => `<button type="button" class="dchip ${c.filter === k ? 'on' : ''}" onclick="irrDCur.filter='${k}';irrDRender()">${k === 'all' ? 'All' : k === 'short' ? 'Short' : 'Excess'}</button>`).join(' ');
    } else document.getElementById('irrDChips').innerHTML = '';
    rows = rows.slice().sort((x, y) => Math.abs(y.dv) - Math.abs(x.dv));
    c.shown = rows;
    document.getElementById('irrDTitle').textContent = c.title;
    document.getElementById('irrDSub').textContent = c.sub + ' · ' + rows.length + ' item(s) · ' + IRR_PERIOD;
    document.getElementById('irrDHead').innerHTML = '<tr><th>SKU7</th><th style="text-align:left">Product</th><th>TUR</th><th>' + c.cols[0] + '</th><th>' + c.cols[1] + '</th><th>Difference (units)</th><th>Difference (value)</th><th></th></tr>';
    let tq = 0, tv = 0;
    document.getElementById('irrDBody').innerHTML = rows.length ? rows.map(r => { tq += r.dq; tv += r.dv; const k = r.dq < 0 ? 'dsh' : 'dex';
        return `<tr><td><strong>${r.sku7}</strong></td><td>${(r.name || '').replace(/</g,'&lt;')}</td><td class="n">${irrFm(r.tur, 2)}</td><td class="n">${irrFm(r.a, 0)}</td><td class="n">${irrFm(r.b, 0)}</td>
            <td class="n ${k}">${irrSg(r.dq)}</td><td class="n ${k}">${r.dv < 0 ? '-' : '+'}${irrFm(Math.abs(r.dv), 2)}</td><td class="${k}">${r.dq < 0 ? (c.type === 'presec' ? 'less loaded than sold' : 'Short') : (c.type === 'presec' ? 'more loaded than sold' : 'Excess')}</td></tr>`; }).join('')
        : '<tr><td colspan="8" style="text-align:center;padding:24px;color:#8892a4">No items with a difference.</td></tr>';
    document.getElementById('irrDFoot').innerHTML = rows.length ? `<tr><td colspan="5" style="text-align:right">TOTAL — ${rows.length} items</td><td class="n">${irrSg(tq)}</td><td class="n">${tv < 0 ? '-' : '+'}${irrFm(Math.abs(tv), 2)}</td><td></td></tr>` : '';
}
function irrDClose(){ document.getElementById('irrDMod').classList.remove('open'); }
document.addEventListener('keydown', e => { if (e.key === 'Escape') irrDClose(); });
async function irrDExport(){
    const c = irrDCur; if (!c) return;
    const wb = new ExcelJS.Workbook(), ws = wb.addWorksheet('Difference items');
    ws.addRow([c.title + ' — ' + IRR_PERIOD]).font = {bold:true, size:13};
    const h = ws.addRow(['SKU7','Product','TUR', c.cols[0], c.cols[1], 'Difference (units)', 'Difference (value)']);
    h.eachCell(x => { x.font = {bold:true}; x.fill = {type:'pattern', pattern:'solid', fgColor:{argb:'FFFBE5D6'}}; });
    c.shown.forEach(r => ws.addRow([r.sku7, r.name, r.tur, r.a, r.b, r.dq, r.dv]));
    [3,7].forEach(n => ws.getColumn(n).numFmt = '#,##0.00'); ws.getColumn(2).width = 40; ws.getColumn(4).width = 20; ws.getColumn(5).width = 20; ws.getColumn(6).width = 16; ws.getColumn(7).width = 16;
    const buf = await wb.xlsx.writeBuffer(); const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([buf], {type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}));
    a.download = 'inventory_difference_items_' + new Date().toISOString().slice(0,10) + '.xlsx'; a.click(); URL.revokeObjectURL(a.href);
}
function irrPrint(){ window.open(location.pathname + (location.search ? location.search + '&' : '?') + 'print=1', '_blank'); }
function irrSave(){
    const t = prompt('Name for this saved report:', 'Inventory Reconciliation ' + IRR_PERIOD);
    if (t === null) return;
    const n = prompt('Note (optional):', '') || '';
    document.getElementById('irrSaveTitle').value = t; document.getElementById('irrSaveNote').value = n;
    document.getElementById('irrSaveForm').submit();
}
function irrMode(){
    if (!document.getElementById('irrAsAt')) return;      // saved-report view has no filter form
    const range = document.querySelector('input[name=mode]:checked').value === 'range';
    document.getElementById('irrAsAt').disabled = range;
    document.getElementById('irrFrom').disabled = !range;
    document.getElementById('irrTo').disabled   = !range;
}
document.addEventListener('DOMContentLoaded', irrMode);
const IRR_ROWS = <?php echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
const IRR_TOT  = <?php echo json_encode($tot); ?>;
const IRR_SC   = <?php echo $sc ? 'true' : 'false'; ?>;
const IRR_PERIOD = <?php echo json_encode($period); ?>;
async function irrExport(){
    const wb = new ExcelJS.Workbook(), ws = wb.addWorksheet('Inventory Reconciliation');
    ws.addRow(['Inventory Reconciliation Report — ' + IRR_PERIOD + ' (value = units x TUR)']).font = {bold:true, size:14};
    const groups = [['open','Opening Inventory'],['pc','Primary Purchase'],['rc','Primary Return'],['pre','Pre Secondary'],['sec','Secondary'],['diff','Diff (Pre - Sec)'],['oth','Rejected / Adj.'],['g','Closing - Good Stock'],['d','Closing - Damaged Stock']];
    if (IRR_SC) groups.push(['ph','Physical Count (Good)'], ['pd','Difference (Physical - System)']);
    const head = ['Basepack','SKU7','Product Name','UPC','TUR'];
    groups.forEach(g => head.push(g[1] + ' Qty', g[1] + ' Value'));
    const h = ws.addRow(head); h.eachCell(c => { c.font = {bold:true}; c.fill = {type:'pattern', pattern:'solid', fgColor:{argb:'FFFBE5D6'}}; });
    IRR_ROWS.forEach(r => { const row = [r.basepack, r.sku7, r.name, r.upc, r.tur]; groups.forEach(g => row.push(r[g[0] + '_q'] ?? '', r[g[0] + '_v'] ?? '')); ws.addRow(row); });
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
