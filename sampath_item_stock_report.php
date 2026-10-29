<?php
// ============================================================
//  sampath_item_stock_report.php
//  SAMPATH ITEM STOCK REPORT (as-at-date running balance, no opening entry) with item-wise ledger
//
//  One row per item for the chosen period:
//    Basepack Code / SKU7 (item code) / Product Name / UPC / Item Value
//        -> unilever_purchase_items (latest row per item code)
//    Location                -> sampath_damage_grn_items.location (latest, if recorded; else blank)
//    Primary Purchase        -> unilever_purchase_items.purchase_qty_cases x UPC  (units)
//    Primary Returned        -> unilever_item_return_items.qty x UPC              (units)
//    Secondary Inv           -> invoice_wise_sales_data.units                     (units)
//    Good Rejected           -> stock_manual_adjustments.rejected_qty             (units, Sampath Goods Rejected)
//    Adjustment              -> stock_manual_adjustments.adjustment_qty           (units)
//    Closing Balance         = Primary Purchase - Primary Returned - Secondary Inv - Good Rejected + Adjustment
//    Closing Stock Value     = Closing Balance x Item Value (price per case / UPC)
//    Damaged Stock          -> column after Closing Balance (option); source = confirmed Sampath Damage Entry lines ONLY
//                              (NOT the secondary Dmg/Exp qty). Stock Location 'Damaged Stock items' lists them as <code>-D rows.
//  Click an item to open its ledger (every movement + running balance).
// ============================================================
include 'config.php';

function isr_table_exists($conn, $t) {
    $q = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $t) . "'");
    return $q && mysqli_num_rows($q) > 0;
}
function isr_upc($v) { return (float)preg_replace('/[^0-9.]/', '', (string)$v); }

/* Running balance as at a date (same basis as the Sampath Current Stock Report):
   everything on record up to and including the As At date. */
/* Two options (radio): 
     As At Date  -> the normal report: all transactions up to the date (Opening Units not used).
     Date Range  -> the From date is taken as the as-at date: Opening Units = closing balance as at the From date;
                    Primary Purchase / Returned / Secondary / Rejected / Adjustment show the transactions AFTER it up to
                    the To date, and Closing = Opening + those transactions. */
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
if ($has_open) {
    $open_to = $f_from_in;                                          // opening = closing balance as at the From date
    $f_from  = date('Y-m-d', strtotime($f_from_in . ' +1 day'));    // transactions after it
    $f_to    = $f_to_in;                                            // ...up to the To date
} else {
    $open_to = null;
    $f_from  = '1970-01-01';                                        // normal report: everything up to the As At Date
    $f_to    = $f_asat;
}
$f_items = [];   // Item filter (multi-select): item codes; a "-D" row follows its base item
foreach ((array)($_GET['items'] ?? []) as $v) { $v = trim((string)$v); if ($v !== '') $f_items[] = $v; }
$show_dcol = ($_GET['dcol'] ?? '1') !== '0';   // option: show the Damaged Stock column after Closing Balance
$f_loc_pre = $_GET['loc'] ?? 'all';
if ($f_loc_pre === 'damaged') $show_dcol = true;   // the damaged items view is all about that column
$f_loc  = in_array($_GET['loc'] ?? 'all', ['good','damaged']) ? $_GET['loc'] : 'all';   // Stock Location filter
$from   = mysqli_real_escape_string($conn, $f_from);
$to     = mysqli_real_escape_string($conn, $f_to);
/* Customer-wise filter (outlet codes). Only secondary invoices belong to a customer, so when it is set the
   report shows that customer's Secondary Inv and Dmg/Exp only (purchases, returns, rejections and adjustments are not customer-wise). */
$f_cust = [];
foreach ((array)($_GET['cust'] ?? []) as $v) { $v = trim((string)$v); if ($v !== '') $f_cust[] = $v; }
$cust_sql = $f_cust ? " AND outlet_code IN ('" . implode("','", array_map(function ($v) use ($conn) { return mysqli_real_escape_string($conn, $v); }, $f_cust)) . "')" : '';

$have = [];
foreach (['unilever_purchase_items','unilever_purchase_batches','unilever_item_return_items','unilever_item_returns',
          'invoice_wise_sales_data','stock_manual_adjustments','sampath_damage_grn_items','sampath_damage_grn_headers','sampath_damage_item_map'] as $t)
    $have[$t] = isr_table_exists($conn, $t);

/* ── item master: latest purchase row per item code ── */
function isr_master($conn, $have) {
    $m = [];
    if (!$have['unilever_purchase_items']) return $m;
    $res = mysqli_query($conn, "SELECT p.item_code, p.basepack_code, p.item_name, p.upc, p.price_per_case
        FROM unilever_purchase_items p
        INNER JOIN (SELECT item_code, MAX(id) AS max_id FROM unilever_purchase_items
                    WHERE item_code IS NOT NULL AND item_code <> '' GROUP BY item_code) l ON l.max_id = p.id");
    while ($res && $r = mysqli_fetch_assoc($res)) {
        $upc = isr_upc($r['upc']);
        $m[trim($r['item_code'])] = [
            'basepack' => $r['basepack_code'], 'name' => $r['item_name'], 'upc' => $upc,
            'value'    => $upc > 0 ? round((float)$r['price_per_case'] / $upc, 4) : 0.0,
        ];
    }
    return $m;
}

/* ── ledger events for one item / location between two dates (unsorted) ── */
function isr_ledger_events($conn, $have, $ce, $upc, $loc, $f_cust, $cust_sql, $d1, $d2) {
    $ev = [];
    $d1 = mysqli_real_escape_string($conn, $d1); $d2 = mysqli_real_escape_string($conn, $d2);
    if ($loc === 'damaged') {
        /* Damaged Stock ledger: confirmed Sampath Damage Entry lines only (not linked to secondary invoices) */
        /* confirmed Sampath Damage Entry lines (Sampath Damage Match & Confirm) -> Damaged Stock */
        if (!$f_cust && $have['sampath_damage_item_map'] && $have['sampath_damage_grn_items'] && $have['sampath_damage_grn_headers']) {
            $q = mysqli_query($conn, "SELECT h.grn_date AS d, h.grn_no, h.grn_branch_code, i.product_name, i.qty AS q
                FROM sampath_damage_item_map m
                JOIN sampath_damage_grn_items i ON i.id = m.line_id
                JOIN sampath_damage_grn_headers h ON h.id = i.grn_header_id
                WHERE m.item_code = '$ce' AND h.grn_date BETWEEN '$d1' AND '$d2'");
            while ($q && $r = mysqli_fetch_assoc($q))
                $ev[] = ['d'=>$r['d'], 't'=>'Sampath Damage Entry', 'ref'=>trim('GRN ' . $r['grn_no'] . ' · ' . $r['grn_branch_code'] . ' · ' . ($r['product_name'] ?? '')),
                         'in'=>(float)$r['q'], 'out'=>0, 'qty'=>(float)$r['q'], 'uom'=>'units'];
        }
        return $ev;
    }
    if (!$f_cust && $have['unilever_purchase_items'] && $have['unilever_purchase_batches']) {
        $q = mysqli_query($conn, "SELECT COALESCE(p.invoice_date, b.received_date) AS d, p.invoice_number, p.supplier_invoice_no, p.purchase_qty_cases AS q
            FROM unilever_purchase_items p JOIN unilever_purchase_batches b ON b.id = p.batch_id
            WHERE p.item_code = '$ce' AND COALESCE(p.invoice_date, b.received_date) BETWEEN '$d1' AND '$d2'");
        while ($q && $r = mysqli_fetch_assoc($q))
            $ev[] = ['d'=>$r['d'], 't'=>'Primary Purchase', 'ref'=>trim(($r['invoice_number'] ?: '') . ' ' . ($r['supplier_invoice_no'] ? '('.$r['supplier_invoice_no'].')' : '')),
                     'in'=>(float)$r['q'] * $upc, 'out'=>0, 'qty'=>(float)$r['q'], 'uom'=>'cases'];
    }
    if (!$f_cust && $have['unilever_item_return_items'] && $have['unilever_item_returns']) {
        $q = mysqli_query($conn, "SELECT r.return_date AS d, r.return_no, r.reason, ri.qty AS q
            FROM unilever_item_return_items ri JOIN unilever_item_returns r ON r.id = ri.return_id
            WHERE ri.item_code = '$ce' AND r.return_date BETWEEN '$d1' AND '$d2'");
        while ($q && $r = mysqli_fetch_assoc($q))
            $ev[] = ['d'=>$r['d'], 't'=>'Primary Returned', 'ref'=>trim($r['return_no'] . ' ' . ($r['reason'] ?? '')),
                     'in'=>0, 'out'=>(float)$r['q'] * $upc, 'qty'=>(float)$r['q'], 'uom'=>'cases'];
    }
    if ($have['invoice_wise_sales_data']) {
        $q = mysqli_query($conn, "SELECT delivery_date AS d, bill_number, outlet_name, units AS q
            FROM invoice_wise_sales_data
            WHERE product_code = '$ce' AND delivery_date BETWEEN '$d1' AND '$d2'$cust_sql");
        while ($q && $r = mysqli_fetch_assoc($q))
            $ev[] = ['d'=>$r['d'], 't'=>'Secondary Inv', 'ref'=>trim($r['bill_number'] . ' ' . ($r['outlet_name'] ?? '')),
                     'in'=>0, 'out'=>(float)$r['q'], 'qty'=>(float)$r['q'], 'uom'=>'units'];
    }
    if (!$f_cust && $have['stock_manual_adjustments']) {
        $q = mysqli_query($conn, "SELECT entry_date AS d, rejected_qty, adjustment_qty, note
            FROM stock_manual_adjustments
            WHERE item_code = '$ce' AND entry_date BETWEEN '$d1' AND '$d2'");
        while ($q && $r = mysqli_fetch_assoc($q)) {
            if ((float)$r['rejected_qty'] != 0)
                $ev[] = ['d'=>$r['d'], 't'=>'Good Rejected', 'ref'=>$r['note'] ?? '', 'in'=>0, 'out'=>(float)$r['rejected_qty'], 'qty'=>(float)$r['rejected_qty'], 'uom'=>'units'];
            if ((float)$r['adjustment_qty'] != 0) {
                $a = (float)$r['adjustment_qty'];
                $ev[] = ['d'=>$r['d'], 't'=>'Adjustment', 'ref'=>$r['note'] ?? '', 'in'=>$a > 0 ? $a : 0, 'out'=>$a < 0 ? -$a : 0, 'qty'=>abs($a), 'uom'=>'units'];
            }
        }
    }
    return $ev;
}

/* ── ledger (AJAX) ── */
if (($_GET['ajax'] ?? '') === 'ledger') {
    header('Content-Type: application/json; charset=utf-8');
    $code = trim($_GET['item_code'] ?? '');
    $ce   = mysqli_real_escape_string($conn, $code);
    $master = isr_master($conn, $have);
    $upc = $master[$code]['upc'] ?? 0;
    $ledger_loc = ($_GET['loc'] ?? '') === 'damaged' ? 'damaged' : 'good';
    $ev = isr_ledger_events($conn, $have, $ce, $upc, $ledger_loc, $f_cust, $cust_sql, $from, $to);
    if ($has_open) {
        /* brought-forward balance = everything before the From date */
        $op = 0;
        foreach (isr_ledger_events($conn, $have, $ce, $upc, $ledger_loc, $f_cust, $cust_sql, '1970-01-01', $open_to) as $o) $op += $o['in'] - $o['out'];
        $ev[] = ['d'=>$open_to, 't'=>'Opening Balance', 'ref'=>'Balance brought forward (closing of ' . $open_to . ')', 'in'=>$op > 0 ? $op : 0, 'out'=>$op < 0 ? -$op : 0, 'qty'=>abs($op), 'uom'=>'units', 'is_open'=>1];
    }
    usort($ev, function ($a, $b) {
        $c = strcmp((string)$a['d'], (string)$b['d']);
        return $c ?: (empty($b['is_open']) ? 0 : 1) - (empty($a['is_open']) ? 0 : 1);   // opening line first on its date
    });
    $bal = 0;
    foreach ($ev as &$e) { $bal += $e['in'] - $e['out']; $e['bal'] = round($bal, 2); $e['in'] = round($e['in'], 2); $e['out'] = round($e['out'], 2); }
    unset($e);
    $ev = array_reverse($ev);   // latest transaction first (running balance was built oldest to newest)
    echo json_encode(['item'=>$code . ($ledger_loc === 'damaged' ? '-D' : ''), 'loc'=>$ledger_loc === 'damaged' ? 'Damaged Stock' : 'Good Stock', 'name'=>$master[$code]['name'] ?? '', 'upc'=>$upc, 'rows'=>$ev, 'closing'=>round($bal, 2)], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ── per-item movements between two dates: [$acc (good stock), $dmg (damaged "-D" stock)] ── */
function isr_aggregate($conn, $have, $master, $from, $to, $cust_sql, $f_cust, &$errors) {
    $from = mysqli_real_escape_string($conn, $from); $to = mysqli_real_escape_string($conn, $to);
    $acc = [];
    $touch = function ($code) use (&$acc) {
        if (!isset($acc[$code])) $acc[$code] = ['pc'=>0.0,'rc'=>0.0,'so'=>0.0,'rej'=>0.0,'adj'=>0.0,'name'=>null,'basepack'=>null];
    };
    if (!$f_cust && $have['unilever_purchase_items'] && $have['unilever_purchase_batches']) {
        $q = mysqli_query($conn, "SELECT p.item_code, SUM(p.purchase_qty_cases) AS q
            FROM unilever_purchase_items p JOIN unilever_purchase_batches b ON b.id = p.batch_id
            WHERE p.item_code <> '' AND COALESCE(p.invoice_date, b.received_date) BETWEEN '$from' AND '$to' GROUP BY p.item_code");
        if (!$q) $errors[] = mysqli_error($conn);
        while ($q && $r = mysqli_fetch_assoc($q)) { $c = trim($r['item_code']); $touch($c); $acc[$c]['pc'] = (float)$r['q'] * ($master[$c]['upc'] ?? 0); }
    }
    if (!$f_cust && $have['unilever_item_return_items'] && $have['unilever_item_returns']) {
        $q = mysqli_query($conn, "SELECT ri.item_code, SUM(ri.qty) AS q
            FROM unilever_item_return_items ri JOIN unilever_item_returns r ON r.id = ri.return_id
            WHERE ri.item_code <> '' AND r.return_date BETWEEN '$from' AND '$to' GROUP BY ri.item_code");
        if (!$q) $errors[] = mysqli_error($conn);
        while ($q && $r = mysqli_fetch_assoc($q)) { $c = trim($r['item_code']); $touch($c); $acc[$c]['rc'] = (float)$r['q'] * ($master[$c]['upc'] ?? 0); }
    }
    if ($have['invoice_wise_sales_data']) {
        $q = mysqli_query($conn, "SELECT product_code AS item_code, MAX(basepack_code) AS bp, MAX(product_description) AS nm, SUM(units) AS q
            FROM invoice_wise_sales_data
            WHERE product_code <> '' AND delivery_date BETWEEN '$from' AND '$to'$cust_sql GROUP BY product_code");
        if (!$q) $errors[] = mysqli_error($conn);
        while ($q && $r = mysqli_fetch_assoc($q)) {
            $c = trim($r['item_code']); $touch($c);
            $acc[$c]['so'] = (float)$r['q']; $acc[$c]['name'] = $r['nm']; $acc[$c]['basepack'] = $r['bp'];
        }
    }
    if (!$f_cust && $have['stock_manual_adjustments']) {
        $q = mysqli_query($conn, "SELECT item_code, SUM(rejected_qty) AS rq, SUM(adjustment_qty) AS aq
            FROM stock_manual_adjustments WHERE item_code <> '' AND entry_date BETWEEN '$from' AND '$to' GROUP BY item_code");
        if (!$q) $errors[] = mysqli_error($conn);
        while ($q && $r = mysqli_fetch_assoc($q)) { $c = trim($r['item_code']); $touch($c); $acc[$c]['rej'] = (float)$r['rq']; $acc[$c]['adj'] = (float)$r['aq']; }
    }
    /* Damaged Stock: confirmed Sampath Damage Entry lines only (NOT linked to secondary invoices / Dmg-Exp qty) */
    $dmg = [];
    /* confirmed Sampath Damage Entry lines (Sampath Damage Match & Confirm), by GRN date -> added to Damaged Stock */
    if (!$f_cust && $have['sampath_damage_item_map'] && $have['sampath_damage_grn_items'] && $have['sampath_damage_grn_headers']) {
        $q = mysqli_query($conn, "SELECT m.item_code, MAX(m.item_name) AS nm, SUM(i.qty) AS q
            FROM sampath_damage_item_map m
            JOIN sampath_damage_grn_items i ON i.id = m.line_id
            JOIN sampath_damage_grn_headers h ON h.id = i.grn_header_id
            WHERE h.grn_date BETWEEN '$from' AND '$to' GROUP BY m.item_code");
        if (!$q) $errors[] = mysqli_error($conn);
        while ($q && $r = mysqli_fetch_assoc($q)) {
            $c = trim($r['item_code']);
            if (!isset($dmg[$c])) $dmg[$c] = ['q'=>0.0, 'bp'=>null, 'nm'=>$r['nm']];
            $dmg[$c]['q'] += (float)$r['q'];
        }
    }
    return [$acc, $dmg];
}

/* ── summary (cumulative up to the As At date, like sampath_current_stock_report.php) ── */
$master = isr_master($conn, $have);
$errors = [];
list($acc, $dmg) = isr_aggregate($conn, $have, $master, $from, $to, $cust_sql, $f_cust, $errors);
$acc0 = $dmg0 = [];
if ($has_open) list($acc0, $dmg0) = isr_aggregate($conn, $have, $master, '1970-01-01', $open_to, $cust_sql, $f_cust, $errors);

/* Item filter options: every item known to the report, shown as "code - name" */
$item_opts = [];
foreach ($master as $c => $m) $item_opts[$c] = $m['name'] ?? '';
foreach ($acc as $c => $a) if (!isset($item_opts[$c]) || $item_opts[$c] === '') $item_opts[$c] = $a['name'] ?? '';
foreach ($dmg as $c => $d) if (!isset($item_opts[$c]) || $item_opts[$c] === '') $item_opts[$c] = $d['nm'] ?? '';
foreach ($acc0 as $c => $x) if (!isset($item_opts[$c]) || $item_opts[$c] === '') $item_opts[$c] = $x['name'] ?? '';
foreach ($dmg0 as $c => $x) if (!isset($item_opts[$c]) || $item_opts[$c] === '') $item_opts[$c] = $x['nm'] ?? '';
ksort($item_opts);
$cust_opts = [];
if ($have['invoice_wise_sales_data']) {
    $q = mysqli_query($conn, "SELECT outlet_code, MAX(outlet_name) AS nm FROM invoice_wise_sales_data WHERE outlet_code IS NOT NULL AND outlet_code <> '' GROUP BY outlet_code ORDER BY nm");
    while ($q && $r = mysqli_fetch_assoc($q)) $cust_opts[trim($r['outlet_code'])] = $r['nm'] ?? '';
}

$rows = [];
$tot = ['dmg'=>0,'open'=>0,'pc'=>0,'rc'=>0,'so'=>0,'rej'=>0,'adj'=>0,'close'=>0,'val'=>0];
$add_row = function ($code, $base, $name, $bp, $loc_label, $is_dmg, $a, $m, $open = 0.0, $dmg_q = 0.0) use (&$rows, &$tot) {
    $upc_v = (float)($m['upc'] ?? 0);
    $value = (float)($m['value'] ?? 0);
    if ($is_dmg) {
        /* Damaged Stock item (<code>-D): its own log from the confirmed Sampath damage entries. It never shows in Opening Units,
           Secondary Inv or Closing Balance - the qty is only in the Damaged Stock column. */
        $dm = $open + $a['so'];            // ($a['so'] carries the damage movement in the period; $open = damage before it)
        $rows[] = ['basepack'=>$bp, 'open'=>0, 'sku7'=>$code, 'base'=>$base, 'name'=>$name, 'location'=>$loc_label, 'is_dmg'=>1, 'upc'=>$upc_v,
            'pc'=>0, 'rc'=>0, 'so'=>0, 'so_s'=>0, 'rej'=>0, 'adj'=>0, 'close'=>0, 'dmg'=>round($dm,2), 'value'=>$value, 'cval'=>0];
        $tot['dmg'] += $dm;
        return;
    }
    // Good Stock: Opening + Primary Purchase - Primary Returned - Secondary Inv - Good Rejected + Adjustment
    // (Damaged Stock is a separate log and is not part of Secondary Inv or Closing Balance)
    $so_s  = -$a['so'];
    $close = $open + $a['pc'] - $a['rc'] - $a['so'] - $a['rej'] + $a['adj'];
    $rows[] = ['basepack'=>$bp, 'open'=>round($open,2), 'sku7'=>$code, 'base'=>$base, 'name'=>$name, 'location'=>$loc_label, 'is_dmg'=>0, 'upc'=>$upc_v,
        'pc'=>round($a['pc'],2), 'rc'=>round($a['rc'],2), 'so'=>round($a['so'],2), 'so_s'=>round($so_s,2), 'rej'=>round($a['rej'],2), 'adj'=>round($a['adj'],2),
        'close'=>round($close,2), 'dmg'=>round($dmg_q,2), 'value'=>$value, 'cval'=>round($close * $value, 2)];
    $tot['dmg'] += $dmg_q;
    $tot['open'] += $open; $tot['pc'] += $a['pc']; $tot['rc'] += $a['rc']; $tot['so'] += $so_s; $tot['rej'] += $a['rej']; $tot['adj'] += $a['adj'];
    $tot['close'] += $close; $tot['val'] += $close * $value;
};
$zero = ['pc'=>0.0,'rc'=>0.0,'so'=>0.0,'rej'=>0.0,'adj'=>0.0];
$net = function ($a) { return $a['pc'] - $a['rc'] - $a['so'] - $a['rej'] + $a['adj']; };
if ($f_loc !== 'damaged') {
    foreach (array_unique(array_merge(array_keys($acc), array_keys($acc0), array_keys($dmg), array_keys($dmg0))) as $code) {
        $a  = $acc[$code] ?? ($zero + ['name'=>null,'basepack'=>null]);
        $a0 = $acc0[$code] ?? null;
        $open = $a0 ? $net($a0) : 0.0;
        $dq = (float)($dmg0[$code]['q'] ?? 0) + (float)($dmg[$code]['q'] ?? 0);      // Damaged Stock (opening + movement)
        if (round($open, 2) == 0 && round($net($a), 2) == 0 && round($dq, 2) == 0 && !array_filter($zero, function ($k) use ($a) { return round($a[$k], 2) != 0; }, ARRAY_FILTER_USE_KEY)) continue;
        $m = $master[$code] ?? [];
        $name = $m['name'] ?? $a['name'] ?? ($a0['name'] ?? ($dmg[$code]['nm'] ?? ($dmg0[$code]['nm'] ?? '')));
        $bp   = $m['basepack'] ?? $a['basepack'] ?? ($a0['basepack'] ?? '');
        if ($f_items && !in_array((string)$code, $f_items, true)) continue;
        $add_row($code, $code, $name, $bp, 'Good Stock', false, $a, $m, $open, $dq);
    }
}
if ($f_loc === 'damaged') {      // Damaged Stock items (<code>-D) as their own rows
    foreach (array_unique(array_merge(array_keys($dmg), array_keys($dmg0))) as $code) {
        $d  = $dmg[$code]  ?? ['q'=>0.0, 'bp'=>null, 'nm'=>null];
        $d0 = $dmg0[$code] ?? null;
        $m = $master[$code] ?? [];
        $name = $m['name'] ?? $d['nm'] ?? ($d0['nm'] ?? '');
        $bp   = $m['basepack'] ?? $d['bp'] ?? ($d0['bp'] ?? '');
        if ($f_items && !in_array((string)$code, $f_items, true)) continue;
        $add_row($code . '-D', $code, $name, $bp, 'Damaged Stock', true, ['so'=>$d['q']] + $zero, $m, $d0 ? $d0['q'] : 0.0);
    }
}
usort($rows, function ($a, $b) { return strcmp($a['base'], $b['base']) ?: $a['is_dmg'] <=> $b['is_dmg']; });

include 'header.php';
function nf($v) { return $v == 0 ? '—' : number_format($v, 2); }
/* whole-number quantity with thousands commas */
function qn($v) { return number_format(round($v), 0); }
function sgn($v) { return round($v) == 0 ? '—' : ($v < 0 ? '-' : '') . number_format(abs(round($v)), 0); }
function qcell($units, $upc, $neg = false) {
    if (round($units) == 0) return '—';
    return ($neg ? '-' : '') . qn($units);
}
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.4.0/exceljs.min.js"></script>
<style>
.select2-container .select2-selection--multiple{min-height:37px;border:1.5px solid #e2e6f0;border-radius:8px;}
.select2-container{min-width:320px;max-width:520px;}
.isr{padding:0 4px;}
.isr h2{font-size:22px;font-weight:800;margin:0 0 4px;}
.isr .sub{font-size:12px;color:#8892a4;margin-bottom:16px;}
.isr-card{background:#fff;border:1.5px solid #e2e6f0;border-radius:14px;padding:16px 20px;margin-bottom:18px;}
.isr-f{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;}
.isr-f label{display:block;font-size:10px;font-weight:700;color:#475569;text-transform:uppercase;margin-bottom:4px;}
.isr-f input{border:1.5px solid #e2e6f0;border-radius:8px;padding:8px 12px;font-size:13px;}
.isr-b{padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;background:#0a0c14;color:#fff;text-decoration:none;}
.isr-b.g{background:#14532d;} .isr-b.l{background:#f7f8fc;color:#475569;border:1.5px solid #e2e6f0;}
.isr-wrap{overflow:auto;border:1.5px solid #e2e6f0;border-radius:12px;background:#fff;}
table.isr-t{width:100%;border-collapse:collapse;font-size:12px;}
table.isr-t th{background:#fbe5d6;color:#111;padding:9px 10px;text-align:center;font-size:11px;border-bottom:2px solid #111;white-space:nowrap;}
table.isr-t td{padding:7px 10px;border-bottom:1px solid #eef0f6;white-space:nowrap;}
table.isr-t td.n{text-align:right;font-family:monospace;}
table.isr-t tr.item{cursor:pointer;} table.isr-t tr.item:hover{background:#f5f8ff;} table.isr-t tr.dmg{background:#fff7ed;} .isr-loc{display:inline-block;padding:2px 9px;border-radius:12px;font-size:11px;font-weight:700;background:#dcfce7;color:#166534;} .isr-loc.d{background:#ffedd5;color:#9a3412;}
table.isr-t tfoot td{background:#fbe5d6;font-weight:800;border-top:2px solid #111;}
.neg{color:#dc2626;}
.isr-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;}
.isr-modal.open{display:flex;}
.isr-box{background:#fff;border-radius:14px;width:min(960px,94vw);max-height:88vh;display:flex;flex-direction:column;}
.isr-box header{padding:16px 20px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;gap:10px;}
.isr-box .body{padding:0 20px 18px;overflow:auto;}
.isr-x{border:none;background:#f3f4f6;border-radius:8px;width:32px;height:32px;cursor:pointer;font-size:16px;}
</style>

<div class="isr">
    <h2><i class="fa-solid fa-boxes-stacked"></i> Sampath Item Stock Report</h2>
    <div class="sub"><strong>As At Date:</strong> the normal report — all transactions up to that date. <strong>Date Range:</strong> <strong>Opening Units</strong> = closing balance as at the From date; Primary Purchase, Primary Returned, Secondary Inv, Good Rejected and Adjustment show the transactions after it up to the To date; Closing = Opening + Primary Purchase − Primary Returned − Secondary Inv − Good Rejected + Adjustment (Primary Qty = purchase qty × UPC, same data as the Sampath Current Stock Report). <strong>Damaged Stock</strong> is a separate log — it never shows in Opening Units, Secondary Inv or Closing Balance — and is the column after Closing Balance (untick the option to hide it) = opening + confirmed Sampath Damage Entry lines (Damage Entry AI Tally &amp; Confirm); it is <strong>not linked to the secondary invoices</strong>. Choose Stock Location → Damaged Stock items to list them as separate <em>&lt;code&gt;-D</em> rows. Click an item to see its ledger. <strong>Customers filter:</strong> shows only those customers' Secondary Inv and Dmg/Exp (primary purchase, returns, rejections and adjustments are not customer-wise, so they are left out).</div>

    <?php if ($range_err): ?><div class="isr-card" style="background:#fffbeb;border-color:#fcd34d;color:#92400e;"><?php echo htmlspecialchars($range_err); ?></div><?php endif; ?>
    <form method="GET" class="isr-card">
        <div class="isr-f">
            <div style="display:flex;gap:18px;align-items:flex-end;flex-wrap:wrap;border:1.5px solid #e2e6f0;border-radius:10px;padding:8px 12px;">
                <div>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;text-transform:none;font-size:12px;color:#111;">
                        <input type="radio" name="mode" value="asat" <?php echo $f_mode === 'asat' ? 'checked' : ''; ?> onchange="isrMode()"> <strong>As At Date</strong></label>
                    <input type="date" name="as_at_date" id="isrAsAt" value="<?php echo htmlspecialchars($f_asat); ?>">
                </div>
                <div>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;text-transform:none;font-size:12px;color:#111;">
                        <input type="radio" name="mode" value="range" <?php echo $f_mode === 'range' ? 'checked' : ''; ?> onchange="isrMode()"> <strong>Date Range</strong></label>
                    <div style="display:flex;gap:8px;">
                        <input type="date" name="date_from" id="isrFrom" value="<?php echo htmlspecialchars($f_from_in); ?>" title="From date = opening as at date">
                        <input type="date" name="date_to" id="isrTo" value="<?php echo htmlspecialchars($f_to_in); ?>" title="To date">
                    </div>
                </div>
            </div>
            <div><label>Customers</label>
                <select name="cust[]" id="custSelect" multiple style="min-width:320px">
                    <?php foreach ($cust_opts as $c => $nm): ?>
                    <option value="<?php echo htmlspecialchars($c); ?>" <?php echo in_array((string)$c, $f_cust, true) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c . ' - ' . $nm); ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div><label>Stock Location</label>
                <select name="loc" style="border:1.5px solid #e2e6f0;border-radius:8px;padding:8px 12px;font-size:13px;">
                    <option value="all" <?php echo $f_loc === 'all' ? 'selected' : ''; ?>>All items (Good Stock)</option>
                    <option value="good" <?php echo $f_loc === 'good' ? 'selected' : ''; ?>>Good Stock</option>
                    <option value="damaged" <?php echo $f_loc === 'damaged' ? 'selected' : ''; ?>>Damaged Stock items (-D)</option>
                </select></div>
            <div><label>Option</label>
                <label style="display:flex;align-items:center;gap:6px;text-transform:none;font-size:13px;font-weight:600;color:#111;height:36px;cursor:pointer;">
                    <input type="hidden" name="dcol" value="0"><input type="checkbox" name="dcol" value="1" <?php echo $show_dcol ? 'checked' : ''; ?>> Damaged Stock column</label></div>
            <div><label>Items</label>
                <select name="items[]" id="itemSelect" multiple style="min-width:320px">
                    <?php foreach ($item_opts as $c => $nm): ?>
                    <option value="<?php echo htmlspecialchars($c); ?>" <?php echo in_array((string)$c, $f_items, true) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c . ' - ' . $nm); ?></option>
                    <?php endforeach; ?>
                </select></div>
            <button class="isr-b" type="submit"><i class="fa-solid fa-filter"></i> Apply</button>
            <a class="isr-b l" href="sampath_item_stock_report.php">Reset</a>
            <button class="isr-b g" type="button" onclick="isrExport()"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
            <button class="isr-b l" type="button" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        </div>
    </form>
    <?php foreach ($errors as $e): ?><div class="isr-card" style="background:#fef2f2;border-color:#fecaca;color:#991b1b;"><?php echo htmlspecialchars($e); ?></div><?php endforeach; ?>

    <div class="isr-wrap">
    <table class="isr-t" id="isrTable">
        <thead><tr>
            <th>Basepack Code</th><th>SKU7</th><th style="text-align:left">Product Name</th><th>Location</th><th>UPC</th>
            <th>Opening Units<br><small>units</small></th><th>Primary Purchase<br><small>units</small></th><th>Primary Returned<br><small>units</small></th><th>Secondary Inv<br><small>units</small></th><th>Good Rejected<br><small>units</small></th><th>Adjustment<br><small>units</small></th>
            <th>Closing Balance<br><small>units</small></th><?php if ($show_dcol): ?><th style="background:#fed7aa">Damaged Stock<br><small>units</small></th><?php endif; ?><th>Item Value</th><th>Closing Stock Value</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $i => $r): ?>
        <tr class="item <?php echo $r['is_dmg'] ? 'dmg' : ''; ?>" onclick="isrLedger(<?php echo htmlspecialchars(json_encode($r['base']), ENT_QUOTES); ?>, '<?php echo $r['is_dmg'] ? 'damaged' : 'good'; ?>')" title="Click to view item ledger">
            <td><?php echo htmlspecialchars($r['basepack'] ?? ''); ?></td>
            <td><strong><?php echo htmlspecialchars($r['sku7']); ?></strong></td>
            <td><?php echo htmlspecialchars($r['name']); ?></td>
            <td><span class="isr-loc <?php echo $r['is_dmg'] ? 'd' : ''; ?>"><?php echo htmlspecialchars($r['location']); ?></span></td>
            <td class="n"><?php echo $r['upc'] ? qn($r['upc']) : '—'; ?></td>
            <td class="n"><?php echo sgn($r['open']); ?></td>
            <td class="n"><?php echo qcell($r['pc'], $r['upc']); ?></td>
            <td class="n"><?php echo qcell($r['rc'], $r['upc'], true); ?></td>
            <td class="n"><?php echo sgn($r['so_s']); ?></td>
            <td class="n"><?php echo qcell($r['rej'], $r['upc'], true); ?></td>
            <td class="n"><?php echo qcell($r['adj'], 0); ?></td>
            <td class="n <?php echo $r['close'] < 0 ? 'neg' : ''; ?>"><strong><?php echo $r['is_dmg'] ? '—' : qn($r['close']); ?></strong></td>
            <?php if ($show_dcol): ?><td class="n" style="background:#fff7ed;color:#9a3412;font-weight:700;<?php echo round($r['dmg']) != 0 ? 'cursor:pointer;text-decoration:underline dotted;' : ''; ?>"
                <?php if (round($r['dmg']) != 0 && !$r['is_dmg']): ?>onclick="event.stopPropagation();isrLedger(<?php echo htmlspecialchars(json_encode($r['base']), ENT_QUOTES); ?>, 'damaged')" title="Damaged Stock ledger"<?php endif; ?>><?php echo sgn($r['dmg']); ?></td><?php endif; ?>
            <td class="n"><?php echo $r['value'] ? number_format($r['value'], 2) : '—'; ?></td>
            <td class="n <?php echo $r['cval'] < 0 ? 'neg' : ''; ?>"><?php echo $r['is_dmg'] ? '—' : number_format($r['cval'], 2); ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="<?php echo 14 + ($show_dcol ? 1 : 0); ?>" style="text-align:center;padding:24px;color:#8892a4;">No stock records found up to this date.</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr>
            <td colspan="5" style="text-align:right;">TOTAL — <?php echo count($rows); ?> items</td>
            <td class="n"><?php echo sgn($tot['open']); ?></td>
            <td class="n"><?php echo qn($tot['pc']); ?></td>
            <td class="n"><?php echo qn(-$tot['rc']); ?></td>
            <td class="n"><?php echo sgn($tot['so']); ?></td>
            <td class="n"><?php echo qn(-$tot['rej']); ?></td>
            <td class="n"><?php echo qn($tot['adj']); ?></td>
            <td class="n"><?php echo qn($tot['close']); ?></td>
            <?php if ($show_dcol): ?><td class="n" style="background:#ffedd5;color:#9a3412"><?php echo sgn($tot['dmg']); ?></td><?php endif; ?>
            <td></td>
            <td class="n"><?php echo number_format($tot['val'], 2); ?></td>
        </tr></tfoot>
    </table>
    </div>
</div>

<div class="isr-modal" id="isrModal" onclick="if(event.target===this)isrClose()">
    <div class="isr-box">
        <header>
            <div><div style="font-size:16px;font-weight:800" id="isrMTitle">Item Ledger</div><div style="font-size:12px;color:#6b7280" id="isrMSub"></div></div>
            <div style="display:flex;gap:8px;align-items:center">
                <button class="isr-b g" type="button" onclick="isrLedgerExport()"><i class="fa-solid fa-file-excel"></i> Excel</button>
                <button class="isr-x" type="button" onclick="isrClose()">&times;</button>
            </div>
        </header>
        <div class="body">
            <table class="isr-t" style="margin-top:12px">
                <thead><tr><th>Date</th><th style="text-align:left">Type</th><th style="text-align:left">Reference</th><th>UPC</th><th>In (units)</th><th>Out (units)</th><th>Balance (units)</th></tr></thead>
                <tbody id="isrMBody"></tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(function(){
    $('#itemSelect').select2({placeholder:'— All items (code - name) —', allowClear:true, width:'100%'});
    $('#custSelect').select2({placeholder:'— All customers (code - name) —', allowClear:true, width:'100%'});
});
function isrMode(){
    const range = document.querySelector('input[name=mode]:checked').value === 'range';
    document.getElementById('isrAsAt').disabled = range;
    document.getElementById('isrFrom').disabled = !range;
    document.getElementById('isrTo').disabled   = !range;
}
document.addEventListener('DOMContentLoaded', isrMode);
const ISR_DCOL = <?php echo $show_dcol ? 'true' : 'false'; ?>;
const ISR_ROWS = <?php echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
const ISR_PERIOD = <?php echo json_encode($has_open ? ('opening as at ' . $open_to . ' · transactions ' . $f_from . ' to ' . $f_to) : ('as at ' . $f_to)); ?>;
const ISR_QS = <?php echo json_encode(($has_open ? 'mode=range&date_from=' . urlencode($f_from_in) . '&date_to=' . urlencode($f_to_in) : 'mode=asat&as_at_date=' . urlencode($f_asat)) . ($f_cust ? '&' . http_build_query(['cust' => $f_cust]) : '')); ?>;
let isrLedgerData = null;
const fm = v => Number(v).toLocaleString('en-US', {maximumFractionDigits:0});
const fm0 = fm;
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

function isrLedger(code, loc){
    loc = loc || 'good';
    document.getElementById('isrMTitle').textContent = 'Item Ledger — ' + code + (loc === 'damaged' ? '-D (Damaged Stock)' : ' (Good Stock)');
    document.getElementById('isrMSub').textContent = 'Loading…';
    document.getElementById('isrMBody').innerHTML = '';
    document.getElementById('isrModal').classList.add('open');
    fetch('sampath_item_stock_report.php?ajax=ledger&' + ISR_QS + '&loc=' + loc + '&item_code=' + encodeURIComponent(code))
        .then(r => r.json()).then(d => {
            isrLedgerData = d;
            document.getElementById('isrMSub').textContent = (d.name || '') + ' · ' + ISR_PERIOD + ' · UPC ' + fm(d.upc) + ' · Closing ' + fm(d.closing);
            document.getElementById('isrMBody').innerHTML = d.rows.length ? d.rows.map(r =>
                `<tr><td style="text-align:center">${esc(r.d)}</td><td>${esc(r.t)}</td><td>${esc(r.ref)}</td>
                 <td class="n">${d.upc ? fm0(d.upc) : '—'}</td>
                 <td class="n">${r.in ? fm(r.in) : '—'}</td><td class="n">${r.out ? fm(r.out) : '—'}</td>
                 <td class="n ${r.bal < 0 ? 'neg' : ''}"><strong>${fm(r.bal)}</strong></td></tr>`).join('')
                : '<tr><td colspan="7" style="text-align:center;padding:20px;color:#8892a4">No movements up to this date.</td></tr>';
        }).catch(e => { document.getElementById('isrMSub').textContent = 'Failed to load: ' + e.message; });
}
function isrClose(){ document.getElementById('isrModal').classList.remove('open'); }
document.addEventListener('keydown', e => { if (e.key === 'Escape') isrClose(); });

async function isrSave(wb, name){
    const buf = await wb.xlsx.writeBuffer();
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([buf], {type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}));
    a.download = name; a.click(); URL.revokeObjectURL(a.href);
}
function isrHead(row){ row.eachCell(c => { c.font = {bold:true}; c.fill = {type:'pattern', pattern:'solid', fgColor:{argb:'FFFBE5D6'}}; }); }

async function isrExport(){
    const wb = new ExcelJS.Workbook(), ws = wb.addWorksheet('Item Stock');
    ws.addRow(['Sampath Item Stock Report — ' + ISR_PERIOD]).font = {bold:true, size:14};
    isrHead(ws.addRow(['Basepack Code','SKU7','Product Name','Location','UPC','Opening Units','Primary Purchase (units)','Primary Returned (units)','Secondary Inv (units)','Good Rejected (units)','Adjustment (units)','Closing Balance (units)'].concat(ISR_DCOL ? ['Damaged Stock (units)'] : [], ['Item Value','Closing Stock Value'])));
    ISR_ROWS.forEach(r => ws.addRow([r.basepack, r.sku7, r.name, r.location, r.upc, r.open, r.pc, -r.rc, r.so_s, -r.rej, r.adj, r.close].concat(ISR_DCOL ? [r.dmg] : [], [r.value, r.cval])));
    const nq = ISR_DCOL ? 13 : 12;
    for (let c = 5; c <= nq; c++) ws.getColumn(c).numFmt = '#,##0';
    [nq + 1, nq + 2].forEach(c => ws.getColumn(c).numFmt = '#,##0.00');
    [14,12,40,14,8,12,14,14,14,14,12,14,14,12,18].forEach((w,i) => ws.getColumn(i+1).width = w);
    await isrSave(wb, 'sampath_item_stock_' + new Date().toISOString().slice(0,10) + '.xlsx');
}
async function isrLedgerExport(){
    if (!isrLedgerData) return;
    const wb = new ExcelJS.Workbook(), ws = wb.addWorksheet('Ledger');
    ws.addRow(['Item Ledger — ' + isrLedgerData.item + ' ' + (isrLedgerData.name||'') + ' (' + ISR_PERIOD + ')']).font = {bold:true, size:13};
    isrHead(ws.addRow(['Date','Type','Reference','UPC','In (units)','Out (units)','Balance (units)']));
    isrLedgerData.rows.forEach(r => ws.addRow([r.d, r.t, r.ref, isrLedgerData.upc, r.in, r.out, r.bal]));
    [4,5,6,7].forEach(c => ws.getColumn(c).numFmt = '#,##0');
    [12,18,40,8,12,12,14].forEach((w,i) => ws.getColumn(i+1).width = w);
    await isrSave(wb, 'item_ledger_' + isrLedgerData.item + '.xlsx');
}
</script>
<?php include 'footer.php'; ?>
