<?php
// ============================================================
//  sampath_current_stock_report.php
//  SAMPATH CURRENT STOCK REPORT (AS AT DATE)
//  Item-wise running stock balance as at a given date, built the
//  same way as smf.xlsx:
//
//    Item Code / Item Name / UPC   -> unilever_purchase_items (Unilever Purchase Import)
//    Primary Qty                   -> unilever_purchase_items.purchase_qty_cases (x UPC = units)
//                                     cumulative up to invoice_date, falling back to the purchase
//                                     batch's received_date only when invoice_date is blank
//    Primary Returns               -> unilever_item_return_items.qty (Unilever Items Return) (x UPC = units)
//                                     cumulative up to return_date
//    Pre Secondary out             -> primary_invoice_wise_sales_data.units (Daily Pre Secondary Stock)
//                                     cumulative up to delivery_date — already in units
//    Secondary Out                 -> invoice_wise_sales_data.units (Invoice Wise Sales)
//                                     cumulative up to delivery_date — already in units
//                                     Reference only — NOT included in the Actual Qty Units balance.
//    Unloading qty base on
//      Pre secondary - Secondary out -> calculated = Pre Secondary out - Secondary Out
//                                     Reference only — NOT included in the Actual Qty Units balance.
//    Unloding Qty from unloading    -> unloading_data.adj_qty_good_units + adj_qty_damage (Shortage / Unloading)
//                                     cumulative up to delivery_date — already in units, actual scanned figure
//    Vehicle Good Short/Excess      -> calculated = Unloading Qty from unloading
//                                       - (Unloading qty base on Pre secondary - Secondary out)
//                                       Minus means short, plus means excess.
//                                       Shown for reference only — NOT included in the Actual Qty Units
//                                       balance (it is derived from Secondary Out, which is also kept
//                                       out of the current-stock balance).
//    Sampath Rejected Qty          -> TWO sources, added together:
//                                     (a) grn_items.qty (GRN Report / Goods Return Notes) for every
//                                         GRN whose header status is 'saved', 'cc_checked' or
//                                         'st_checked' (i.e. saved onward — draft and cancelled GRNs
//                                         are excluded), cumulative up to the GRN's delivery_date
//                                         (falling back to the header's created_at date when
//                                         delivery_date is blank) — this is the automatic feed now
//                                         that Sampath goods-rejected quantities come through the
//                                         GRN Report import.
//                                     (b) stock_manual_adjustments.rejected_qty (manual entry — kept
//                                         for one-off corrections or anything outside the GRN flow)
//    Adjustment Qty                -> stock_manual_adjustments.adjustment_qty (manual entry)
//    Stock Damage (-)              -> sampath_damage_grn_items.qty (Sampath Goods Rejected / Damage
//                                     Return import), cumulative up to grn_date
//
//    Actual Qty Units = Primary Qty - Primary Returns - Pre Secondary out
//                        + Unloding Qty from unloading
//                        + Sampath Rejected Qty (GRN Report auto feed + manual entries combined)
//                        + Adjustment Qty - Stock Damage
//                        (Vehicle Good Short/Excess and Secondary Out are NOT part of this balance —
//                         they remain visible in the table/detail tabs for reference only.)
//    Cases from Actual Qty Units   = FLOOR(Actual Qty Units / UPC)
//    Units Balance from cases      = Actual Qty Units - (Cases * UPC)
//
//  This is a running "as at date" balance (everything on record up to and
//  including the selected date), not a from/to period window. It does not
//  include the physical-stock-count reconciliation option that
//  sampath_stock_reconciliation.php has — that stays a separate report.
// ============================================================
if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();

include 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$session_user = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'System';

/* ── Ensure the manual adjustment table exists (Sampath Rejected Qty / Adjustment Qty
       have no import anywhere in the system yet, so they're captured here manually,
       the same way physical_stock_count_upload.php captures its own manual source). ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS stock_manual_adjustments (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(100) NOT NULL,
    entry_date DATE NOT NULL,
    rejected_qty DECIMAL(14,2) NOT NULL DEFAULT 0,
    adjustment_qty DECIMAL(14,2) NOT NULL DEFAULT 0,
    note VARCHAR(255) DEFAULT NULL,
    created_by VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_item_code (item_code),
    INDEX idx_entry_date (entry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$flash_ok = '';
$flash_err = '';

/* ── POST: add a manual adjustment / rejected qty entry ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_adjustment') {
    $adj_item_code = trim($_POST['item_code'] ?? '');
    $adj_date      = trim($_POST['entry_date'] ?? '');
    $adj_rejected  = (float)($_POST['rejected_qty'] ?? 0);
    $adj_qty       = (float)($_POST['adjustment_qty'] ?? 0);
    $adj_note      = trim($_POST['note'] ?? '');

    if ($adj_item_code === '' || $adj_date === '') {
        $flash_err = 'Item code and date are required.';
    } elseif ($adj_rejected == 0 && $adj_qty == 0) {
        $flash_err = 'Enter a Sampath Rejected Qty or an Adjustment Qty (or both) — both were zero.';
    } else {
        $ic = mysqli_real_escape_string($conn, $adj_item_code);
        $dt2 = mysqli_real_escape_string($conn, $adj_date);
        $nt = mysqli_real_escape_string($conn, $adj_note);
        $cb = mysqli_real_escape_string($conn, $session_user);
        $ok = mysqli_query($conn, "INSERT INTO stock_manual_adjustments
                (item_code, entry_date, rejected_qty, adjustment_qty, note, created_by)
                VALUES ('$ic','$dt2',$adj_rejected,$adj_qty,'$nt','$cb')");
        if ($ok) { $flash_ok = 'Entry saved for ' . htmlspecialchars($adj_item_code) . '.'; }
        else { $flash_err = 'Could not save: ' . mysqli_error($conn); }
    }
}

/* ── POST: delete a manual adjustment entry ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_adjustment') {
    $del_id = (int)($_POST['id'] ?? 0);
    if ($del_id > 0) {
        mysqli_query($conn, "DELETE FROM stock_manual_adjustments WHERE id=$del_id");
        $flash_ok = 'Entry removed.';
    }
}

include 'header.php';

$debug = isset($_GET['debug']) && $_GET['debug'] === '1';

/* ── FILTERS ── */
$as_at_date    = isset($_GET['as_at_date']) && $_GET['as_at_date'] !== '' ? $_GET['as_at_date'] : date('Y-m-d');
$filter_search = isset($_GET['search']) ? trim($_GET['search']) : '';

$dt = mysqli_real_escape_string($conn, $as_at_date);

$search_where_purchase = '';
$search_where_ri       = '';
$search_where_gen      = '';
if ($filter_search !== '') {
    $s = mysqli_real_escape_string($conn, $filter_search);
    $search_where_purchase = " AND (p.item_code LIKE '%$s%' OR p.item_name LIKE '%$s%') ";
    $search_where_ri       = " AND (ri.item_code LIKE '%$s%' OR ri.item_name LIKE '%$s%') ";
}

/* ── CHECK REQUIRED TABLES EXIST ── */
$required_tables = [
    'unilever_purchase_items',
    'unilever_item_return_items',
    'unilever_item_returns',
    'primary_invoice_wise_sales_data',
    'invoice_wise_sales_data',
    'unloading_data',
];
$missing_tables = [];
foreach ($required_tables as $t) {
    $chk = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $t) . "'");
    if (!$chk || mysqli_num_rows($chk) === 0) $missing_tables[] = $t;
}
$has_damage_tables = false;
$chk = mysqli_query($conn, "SHOW TABLES LIKE 'sampath_damage_grn_items'");
if ($chk && mysqli_num_rows($chk) > 0) {
    $chk2 = mysqli_query($conn, "SHOW TABLES LIKE 'sampath_damage_grn_headers'");
    $has_damage_tables = ($chk2 && mysqli_num_rows($chk2) > 0);
}

/* ── GRN Report (Goods Return Notes) tables — the automatic feed for Sampath
       Rejected Qty. Optional: older databases may not have these yet, same
       pattern as the damage tables check above. ── */
$has_grn_tables = false;
$chk = mysqli_query($conn, "SHOW TABLES LIKE 'grn_headers'");
if ($chk && mysqli_num_rows($chk) > 0) {
    $chk2 = mysqli_query($conn, "SHOW TABLES LIKE 'grn_items'");
    $has_grn_tables = ($chk2 && mysqli_num_rows($chk2) > 0);
}

$query_error = '';
$purchase_detail = $return_detail = $presec_detail = $secout_detail = $unload_detail = $damage_detail = $adjustment_detail = [];
$grn_rejected_detail = [];
$item_master = []; // item_code => ['item_name'=>.., 'upc'=>.., 'pack_size'=>..] — full history, not date-limited

if (empty($missing_tables)) {

    /* ── 0) ITEM MASTER — item code / name / UPC, taken from the LATEST row per item
           in unilever_purchase_items across its full history, so every item always
           shows its correct name/UPC even when it wasn't purchased again right before
           the as-at date. Same "latest row per item_code" pattern used by
           get_unilever_items.php elsewhere in this system. ── */
    $sql = "SELECT p.item_code, p.item_name, p.upc, p.pack_size
            FROM unilever_purchase_items p
            INNER JOIN (
                SELECT item_code, MAX(id) AS max_id
                FROM unilever_purchase_items
                WHERE item_code IS NOT NULL AND item_code <> ''
                GROUP BY item_code
            ) latest ON latest.max_id = p.id";
    $res = mysqli_query($conn, $sql);
    if (!$res) { $query_error = mysqli_error($conn); }
    else {
        while ($r = mysqli_fetch_assoc($res)) {
            $item_master[trim($r['item_code'])] = [
                'item_name' => $r['item_name'], 'upc' => $r['upc'], 'pack_size' => $r['pack_size'],
            ];
        }
    }

    /* ── 1) PRIMARY QTY — cumulative to date, filtered by invoice_date, falling back to
           the purchase batch's received_date only on rows where invoice_date is blank. ── */
    $sql = "SELECT p.item_code, p.item_name, p.upc, p.batch_code, p.pack_size, p.price_per_case,
                   p.invoice_number, p.invoice_date, p.purchase_qty_cases, b.received_date
            FROM unilever_purchase_items p
            JOIN unilever_purchase_batches b ON b.id = p.batch_id
            WHERE p.item_code IS NOT NULL AND p.item_code <> ''
              AND COALESCE(p.invoice_date, b.received_date) <= '$dt'
              $search_where_purchase
            ORDER BY COALESCE(p.invoice_date, b.received_date) ASC, p.item_code ASC";
    $res = mysqli_query($conn, $sql);
    if (!$res) { $query_error = mysqli_error($conn); }
    else { while ($r = mysqli_fetch_assoc($res)) { $purchase_detail[] = $r; } }

    /* ── 2) PRIMARY RETURNS — cumulative to date ── */
    $sql = "SELECT ri.item_code, ri.item_name, ri.qty, ri.batch_code, ri.pack_size,
                   r.return_no, r.return_date
            FROM unilever_item_return_items ri
            JOIN unilever_item_returns r ON r.id = ri.return_id
            WHERE ri.item_code IS NOT NULL AND ri.item_code <> ''
              AND r.return_date <= '$dt'
              $search_where_ri
            ORDER BY r.return_date ASC, ri.item_code ASC";
    $res = mysqli_query($conn, $sql);
    if ($res) { while ($r = mysqli_fetch_assoc($res)) { $return_detail[] = $r; } }

    /* ── 3) PRE SECONDARY OUT — cumulative to date (already in units) ── */
    $sql = "SELECT product_code AS item_code, product_description AS item_name,
                   units, delivery_date, bill_number, salesperson_name, outlet_name
            FROM primary_invoice_wise_sales_data
            WHERE product_code IS NOT NULL AND product_code <> ''
              AND delivery_date <= '$dt'
              " . ($filter_search !== '' ? " AND (product_code LIKE '%$s%' OR product_description LIKE '%$s%') " : "") . "
            ORDER BY delivery_date ASC, product_code ASC";
    $res = mysqli_query($conn, $sql);
    if ($res) { while ($r = mysqli_fetch_assoc($res)) { $presec_detail[] = $r; } }

    /* ── 4) SECONDARY OUT — cumulative to date (already in units), the actual sale
           to the outlet, from Invoice Wise Sales (invoice_wise_sales_data).
           Kept for reference / the "Unloading qty base on Pre secondary - Secondary out"
           and "Vehicle Good Short/Excess" columns only — it does NOT feed into the
           Actual Qty Units balance. ── */
    $sql = "SELECT product_code AS item_code, product_description AS item_name,
                   units, delivery_date, bill_number, salesperson_name, outlet_name
            FROM invoice_wise_sales_data
            WHERE product_code IS NOT NULL AND product_code <> ''
              AND delivery_date <= '$dt'
              " . ($filter_search !== '' ? " AND (product_code LIKE '%$s%' OR product_description LIKE '%$s%') " : "") . "
            ORDER BY delivery_date ASC, product_code ASC";
    $res = mysqli_query($conn, $sql);
    if ($res) { while ($r = mysqli_fetch_assoc($res)) { $secout_detail[] = $r; } }

    /* ── 5) UNLOADING QTY (actual, from unloading) — cumulative to date (already in units) ── */
    $sql = "SELECT sku_code AS item_code, sku_desc AS item_name,
                   adj_qty_good_units, adj_qty_damage, delivery_date,
                   delivery_person_name, vehicle
            FROM unloading_data
            WHERE sku_code IS NOT NULL AND sku_code <> ''
              AND delivery_date <= '$dt'
              " . ($filter_search !== '' ? " AND (sku_code LIKE '%$s%' OR sku_desc LIKE '%$s%') " : "") . "
            ORDER BY delivery_date ASC, sku_code ASC";
    $res = mysqli_query($conn, $sql);
    if ($res) { while ($r = mysqli_fetch_assoc($res)) { $unload_detail[] = $r; } }

    /* ── 6) STOCK DAMAGE — cumulative to date, from Sampath Goods Rejected / Damage
           Return import (sampath_damage_grn_items x sampath_damage_grn_headers). ── */
    if ($has_damage_tables) {
        $sql = "SELECT gi.product_code AS item_code, gi.product_name AS item_name,
                       gi.qty, gh.grn_no, gh.grn_date, gh.supplier
                FROM sampath_damage_grn_items gi
                JOIN sampath_damage_grn_headers gh ON gh.id = gi.grn_header_id
                WHERE gi.product_code IS NOT NULL AND gi.product_code <> ''
                  AND gh.grn_date <= '$dt'
                  " . ($filter_search !== '' ? " AND (gi.product_code LIKE '%$s%' OR gi.product_name LIKE '%$s%') " : "") . "
                ORDER BY gh.grn_date ASC, gi.product_code ASC";
        $res = mysqli_query($conn, $sql);
        if ($res) { while ($r = mysqli_fetch_assoc($res)) { $damage_detail[] = $r; } }
    }

    /* ── 7) MANUAL ADJUSTMENTS — Sampath Rejected Qty + Adjustment Qty, cumulative to date ── */
    $sql = "SELECT id, item_code, entry_date, rejected_qty, adjustment_qty, note, created_by, created_at
            FROM stock_manual_adjustments
            WHERE entry_date <= '$dt'
              " . ($filter_search !== '' ? " AND item_code LIKE '%$s%' " : "") . "
            ORDER BY entry_date DESC, id DESC";
    $res = mysqli_query($conn, $sql);
    if ($res) { while ($r = mysqli_fetch_assoc($res)) { $adjustment_detail[] = $r; } }

    /* ── 8) SAMPATH REJECTED QTY — automatic feed from the GRN Report (Goods Return
           Notes). Only GRNs that have at least been saved count (status 'saved',
           'cc_checked' or 'st_checked') — 'draft' and 'cancelled' GRNs are excluded.
           Cumulative up to the GRN's delivery_date, falling back to the header's
           created_at date only when delivery_date is blank. ── */
    if ($has_grn_tables) {
        $sql = "SELECT gi.product_code AS item_code, gi.product_description AS item_name,
                       gi.qty, gh.grn_ref, gh.outlet_name, gh.status,
                       gh.delivery_date, gh.created_at
                FROM grn_items gi
                JOIN grn_headers gh ON gh.id = gi.grn_id
                WHERE gi.product_code IS NOT NULL AND gi.product_code <> ''
                  AND gh.status IN ('saved','cc_checked','st_checked')
                  AND COALESCE(gh.delivery_date, DATE(gh.created_at)) <= '$dt'
                  " . ($filter_search !== '' ? " AND (gi.product_code LIKE '%$s%' OR gi.product_description LIKE '%$s%') " : "") . "
                ORDER BY COALESCE(gh.delivery_date, DATE(gh.created_at)) ASC, gi.product_code ASC";
        $res = mysqli_query($conn, $sql);
        if ($res) { while ($r = mysqli_fetch_assoc($res)) { $grn_rejected_detail[] = $r; } }
    }

} else {
    $query_error = 'Missing table(s) on this database: ' . implode(', ', $missing_tables)
        . '. These are populated by other pages already in this system — import at least one batch on '
        . 'Unilever Purchase Import, Unilever Items Return, Daily Pre Secondary Stock, Invoice Wise Sales '
        . 'and Shortage/Unloading first.';
}

/* ── AGGREGATE PER ITEM ──
   The item list is the union of every item code that has ANY activity on record up
   to the as-at date (purchase, return, pre-secondary, secondary, unloading, damage,
   or a manual entry) — this is a running balance, not just a purchase-period list.
   Item Name / UPC come from $item_master (unilever_purchase_items' full history) so
   they're always correct even when a particular row's own name/upc is blank. */
$items = [];
$ensure_item = function ($code, $name = null) use (&$items, $item_master) {
    $code = trim((string)$code);
    if ($code === '') return null;
    if (!isset($items[$code])) {
        $master = $item_master[$code] ?? null;
        $items[$code] = [
            'item_code' => $code,
            'item_name' => $master['item_name'] ?? $name,
            'upc'       => $master['upc'] ?? null,
            'purchase_cases' => 0.0, 'return_cases' => 0.0,
            'presec_units' => 0.0, 'secout_units' => 0.0,
            'unload_actual_units' => 0.0,
            'manual_rejected_units' => 0.0, 'grn_rejected_units' => 0.0,
            'adjustment_units' => 0.0, 'damage_units' => 0.0,
        ];
    } elseif (($items[$code]['item_name'] ?? null) === null && $name !== null) {
        $items[$code]['item_name'] = $name;
    }
    return $code;
};

foreach ($purchase_detail as $r) {
    $code = $ensure_item($r['item_code'], $r['item_name']);
    if ($code === null) continue;
    // a MINUS qty row in the purchase import is a purchase return (not a negative purchase)
    if ((float)$r['purchase_qty_cases'] < 0) $items[$code]['return_cases'] += -(float)$r['purchase_qty_cases'];
    else                                     $items[$code]['purchase_cases'] += (float)$r['purchase_qty_cases'];
}
foreach ($return_detail as $r) {
    $code = $ensure_item($r['item_code'], $r['item_name']);
    if ($code === null) continue;
    $items[$code]['return_cases'] += (float)$r['qty'];
}
foreach ($presec_detail as $r) {
    $code = $ensure_item($r['item_code'], $r['item_name']);
    if ($code === null) continue;
    $items[$code]['presec_units'] += (float)$r['units'];
}
foreach ($secout_detail as $r) {
    $code = $ensure_item($r['item_code'], $r['item_name']);
    if ($code === null) continue;
    $items[$code]['secout_units'] += (float)$r['units'];
}
foreach ($unload_detail as $r) {
    $code = $ensure_item($r['item_code'], $r['item_name']);
    if ($code === null) continue;
    $items[$code]['unload_actual_units'] += (float)$r['adj_qty_good_units'] + (float)$r['adj_qty_damage'];
}
foreach ($damage_detail as $r) {
    $code = $ensure_item($r['item_code'], $r['item_name']);
    if ($code === null) continue;
    $items[$code]['damage_units'] += (float)$r['qty'];
}
foreach ($adjustment_detail as $r) {
    $code = $ensure_item($r['item_code']);
    if ($code === null) continue;
    $items[$code]['manual_rejected_units'] += (float)$r['rejected_qty'];
    $items[$code]['adjustment_units']      += (float)$r['adjustment_qty'];
}
foreach ($grn_rejected_detail as $r) {
    $code = $ensure_item($r['item_code'], $r['item_name']);
    if ($code === null) continue;
    $items[$code]['grn_rejected_units'] += (float)$r['qty'];
}

/* ── FINAL CALC PER ITEM ── */
$rows = [];
$grand = [
    'purchase_units' => 0, 'return_units' => 0, 'presec_units' => 0, 'secout_units' => 0,
    'unload_calc_units' => 0, 'unload_actual_units' => 0, 'vehicle_short_excess' => 0,
    'rejected_units' => 0, 'grn_rejected_units' => 0, 'manual_rejected_units' => 0,
    'adjustment_units' => 0, 'damage_units' => 0, 'actual_units' => 0,
];
foreach ($items as $code => $it) {
    $upc = (float)preg_replace('/[^0-9.]/', '', (string)($it['upc'] ?? 0));
    $purchase_units = $upc > 0 ? $it['purchase_cases'] * $upc : 0.0;
    $return_units   = $upc > 0 ? $it['return_cases']   * $upc : 0.0;

    // "Unloading qty base on Pre secondary - Secondary out": what SHOULD have come back
    // unsold, worked out from the sales side. Shown for reference only.
    $unload_calc_units = $it['presec_units'] - $it['secout_units'];

    // Vehicle Good Short/Excess = actual scanned unloading qty - the above calculated
    // figure. Minus means short (less physically came back than expected), plus means excess.
    // Shown for reference only — see note below, this is NOT part of Actual Qty Units.
    $vehicle_short_excess = $it['unload_actual_units'] - $unload_calc_units;

    // Sampath Rejected Qty = GRN Report auto feed (status saved/cc_checked/st_checked)
    // + any manual entries keyed in via "Add Rejected / Adjustment Qty".
    $rejected_units = $it['grn_rejected_units'] + $it['manual_rejected_units'];

    // Actual Qty Units = Primary Qty - Primary Returns - Pre Secondary out
    //                    + Unloding Qty from unloading
    //                    + Sampath Rejected Qty + Adjustment Qty - Stock Damage
    // NOTE: Vehicle Good Short/Excess (and therefore Secondary Out, which it's derived
    // from) is intentionally NOT added into or subtracted from this balance — it stays
    // a reference-only column in the table.
    $actual_units = $purchase_units - $return_units - $it['presec_units']
                    + $it['unload_actual_units']
                    + $rejected_units + $it['adjustment_units'] - $it['damage_units'];

    $actual_cases = $upc > 0 ? (int)floor($actual_units / $upc) : 0;
    $actual_bal_units = $upc > 0 ? $actual_units - ($actual_cases * $upc) : $actual_units;

    $grand['purchase_units']       += $purchase_units;
    $grand['return_units']         += $return_units;
    $grand['presec_units']         += $it['presec_units'];
    $grand['secout_units']         += $it['secout_units'];
    $grand['unload_calc_units']    += $unload_calc_units;
    $grand['unload_actual_units']  += $it['unload_actual_units'];
    $grand['vehicle_short_excess']  += $vehicle_short_excess;
    $grand['rejected_units']        += $rejected_units;
    $grand['grn_rejected_units']    += $it['grn_rejected_units'];
    $grand['manual_rejected_units'] += $it['manual_rejected_units'];
    $grand['adjustment_units']     += $it['adjustment_units'];
    $grand['damage_units']         += $it['damage_units'];
    $grand['actual_units']         += $actual_units;

    $rows[] = [
        'item_code'            => $code,
        'item_name'            => $it['item_name'],
        'upc'                  => $upc,
        'purchase_units'       => $purchase_units,
        'return_units'         => $return_units,
        'presec_units'         => $it['presec_units'],
        'secout_units'         => $it['secout_units'],
        'unload_calc_units'    => $unload_calc_units,
        'unload_actual_units'  => $it['unload_actual_units'],
        'vehicle_short_excess' => $vehicle_short_excess,
        'rejected_units'       => $rejected_units,
        'grn_rejected_units'   => $it['grn_rejected_units'],
        'manual_rejected_units'=> $it['manual_rejected_units'],
        'adjustment_units'     => $it['adjustment_units'],
        'damage_units'         => $it['damage_units'],
        'actual_units'         => $actual_units,
        'actual_cases'         => $actual_cases,
        'actual_bal_units'     => $actual_bal_units,
    ];
}
usort($rows, fn($a, $b) => strcmp($a['item_code'], $b['item_code']));

$all_rows_json = json_encode($rows, JSON_NUMERIC_CHECK);
$adj_json      = json_encode($adjustment_detail, JSON_NUMERIC_CHECK);
$item_codes_json = json_encode(array_values(array_map(fn($c) => ['code' => $c, 'name' => $items[$c]['item_name']], array_keys($items))));
?>
<style>
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:20px}
.card-title{font-size:16px;font-weight:600;margin-bottom:16px;color:#1f2937;display:flex;align-items:center;gap:8px}
.filter-bar{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin-bottom:0}
.filter-group{display:flex;flex-direction:column;gap:4px}
.filter-group label{font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px}
.filter-input{padding:8px 12px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;color:#333;background:#fff;min-width:150px}
.filter-input:focus{outline:none;border-color:#000}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none}
.btn-primary{background:#000;color:#fff}.btn-primary:hover{background:#333}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}.btn-secondary:hover{background:#e5e5e5}
.btn-success{background:#16a34a;color:#fff}.btn-success:hover{background:#15803d}
.btn-purple{background:#8b5cf6;color:#fff}.btn-purple:hover{background:#7c3aed}
.btn-danger{background:#ef4444;color:#fff}.btn-danger:hover{background:#dc2626}
.btn-sm{padding:6px 14px;font-size:12px}
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:20px}
.kpi-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:16px;position:relative;overflow:hidden}
.kpi-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px}
.kpi-blue::before{background:#3b82f6}.kpi-purple::before{background:#8b5cf6}
.kpi-red::before{background:#ef4444}.kpi-green::before{background:#22c55e}
.kpi-orange::before{background:#f97316}.kpi-teal::before{background:#14b8a6}
.kpi-gray::before{background:#6b7280}.kpi-indigo::before{background:#6366f1}
.kpi-label{font-size:10.5px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px}
.kpi-value{font-size:18px;font-weight:700;color:#1f2937}
.kpi-sub{font-size:11px;color:#9ca3af;margin-top:3px}
.alert-error{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;border-radius:8px;padding:16px 20px;margin-bottom:20px;font-size:13px}
.alert-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;border-radius:8px;padding:14px 20px;margin-bottom:20px;font-size:13px}
.table-responsive{overflow-x:auto;-webkit-overflow-scrolling:touch}
.report-table{width:100%;border-collapse:collapse;font-size:12px;min-width:1350px}
.report-table thead th{background:#000;color:#fff;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;padding:11px 8px;text-align:center;border:1px solid #333}
.report-table tbody tr{border-bottom:1px solid #f0f0f0}
.report-table tbody tr:hover{background:#f8fafc}
.report-table tbody tr.neg-row{background:#fef2f2}
.report-table td{padding:8px 8px;color:#374151;text-align:center;border:1px solid #f0f0f0;white-space:nowrap}
.report-table td.left{text-align:left}
.report-table tfoot tr{background:#f1f5f9;font-weight:700}
.report-table tfoot td{padding:11px 9px;border:1px solid #e2e8f0;font-size:12px;color:#1f2937}
.code-pill{font-family:monospace;font-weight:700;background:#eef2ff;color:#3730a3;border-radius:5px;padding:2px 7px;font-size:11px}
.sub-txt{display:block;font-size:10.5px;color:#9ca3af;font-weight:400;margin-top:1px}
.note-box{font-size:11.5px;color:#6b7280;background:#f9fafb;border:1px dashed #e5e7eb;border-radius:6px;padding:10px 14px;margin-top:14px}
#page-loader{position:fixed;inset:0;background:rgba(255,255,255,.75);z-index:9999;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(2px)}
#page-loader .ld-box{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:24px 36px;text-align:center;box-shadow:0 8px 24px rgba(0,0,0,.1)}
#page-loader .ld-spinner{width:36px;height:36px;border:3px solid #e5e5e5;border-top-color:#3b82f6;border-radius:50%;animation:spin .7s linear infinite;margin:0 auto 12px}
@keyframes spin{to{transform:rotate(360deg)}}
#page-loader p{font-size:13px;color:#6b7280;margin:0}

.scr-tabs{display:flex;gap:6px;margin-bottom:18px;flex-wrap:wrap;border-bottom:2px solid #f0f0f0;padding-bottom:0}
.scr-tab-btn{display:inline-flex;align-items:center;gap:7px;padding:10px 16px;border:none;background:none;font-size:13px;font-weight:600;color:#6b7280;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;transition:all .15s}
.scr-tab-btn:hover{color:#111}
.scr-tab-btn.active{color:#000;border-bottom-color:#000}
.scr-tab-count{background:#f1f5f9;color:#475569;font-size:10.5px;font-weight:700;border-radius:10px;padding:1px 8px}
.scr-tab-btn.active .scr-tab-count{background:#000;color:#fff}
.scr-panel{display:none}
.scr-panel.active{display:block}

.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:2000;display:none;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:10px;padding:22px;width:100%;max-width:420px;max-height:90vh;overflow-y:auto}
.modal-box h4{margin:0 0 14px;font-size:15px;color:#1f2937}
.modal-box label{display:block;font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin:10px 0 4px}
.modal-box input, .modal-box textarea{width:100%;padding:8px 10px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px}
.modal-actions{display:flex;gap:8px;margin-top:18px;justify-content:flex-end}
datalist{}

@media print{.filter-bar,.btn,#page-loader,.scr-tabs{display:none}.content-card{border:none;padding:0}.report-table{font-size:10px}}
</style>

<div id="page-loader"><div class="ld-box"><div class="ld-spinner"></div><p>Loading report…</p></div></div>

<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
        <div>
            <h2 class="page-title">
                <i class="fa-solid fa-warehouse" style="color:#000"></i>
                SAMPATH CURRENT STOCK REPORT
            </h2>
            <p class="page-subtitle">Actual Qty Units = Primary Qty − Primary Returns − Pre Secondary out + Unloding Qty from unloading + Sampath Rejected Qty + Adjustment Qty − Stock Damage. (Vehicle Good Short/Excess and Secondary Out are reference-only columns and are not part of this balance.)</p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button onclick="scrOpenAdjModal()" class="btn btn-purple btn-sm"><i class="fa-solid fa-pen-to-square"></i> Add Rejected / Adjustment Qty</button>
            <button onclick="window.print()" class="btn btn-secondary btn-sm"><i class="fa-solid fa-print"></i> Print</button>
            <button onclick="exportExcel()" class="btn btn-success btn-sm"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
        </div>
    </div>
</div>

<?php if ($flash_ok): ?><div class="alert-ok"><?php echo htmlspecialchars($flash_ok); ?></div><?php endif; ?>
<?php if ($flash_err): ?><div class="alert-error"><?php echo htmlspecialchars($flash_err); ?></div><?php endif; ?>
<?php if ($query_error): ?>
<div class="alert-error"><strong>Database error:</strong> <?php echo htmlspecialchars($query_error); ?></div>
<?php endif; ?>

<?php if ($debug): ?>
<div class="alert-error" style="background:#eff6ff;border-color:#bfdbfe;color:#1e3a8a">
    <strong>Debug mode</strong><br>
    As at date: <?php echo htmlspecialchars($as_at_date); ?><br>
    Missing tables: <?php echo $missing_tables ? htmlspecialchars(implode(', ', $missing_tables)) : 'none'; ?><br>
    Damage tables present: <?php echo $has_damage_tables ? 'yes' : 'no'; ?><br>
    Items found: <?php echo count($rows); ?>
</div>
<?php endif; ?>

<div class="content-card">
    <form method="GET" action="">
        <div class="filter-bar">
            <div class="filter-group">
                <label>As At Date</label>
                <input type="date" name="as_at_date" class="filter-input" value="<?php echo htmlspecialchars($as_at_date); ?>">
            </div>
            <div class="filter-group" style="min-width:220px">
                <label>Item Code / Item Name</label>
                <input type="text" name="search" class="filter-input" placeholder="Search item…" value="<?php echo htmlspecialchars($filter_search); ?>">
            </div>
            <div class="filter-group">
                <label>&nbsp;</label>
                <div style="display:flex;gap:8px">
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Apply</button>
                    <a href="sampath_current_stock_report.php" class="btn btn-secondary"><i class="fa-solid fa-rotate-left"></i> Reset</a>
                </div>
            </div>
            <div class="filter-group" style="flex:1;min-width:220px">
                <label>&nbsp;</label>
                <span style="font-size:11.5px;color:#9ca3af">Shows the running stock balance built from every record on file up to and including this date — not a from/to period window.</span>
            </div>
        </div>
    </form>
</div>

<!-- KPI Cards -->
<div class="kpi-grid">
    <div class="kpi-card kpi-blue"><div class="kpi-label">Primary Qty</div><div class="kpi-value"><?php echo number_format($grand['purchase_units']); ?></div><div class="kpi-sub"><?php echo count($rows); ?> item(s)</div></div>
    <div class="kpi-card kpi-purple"><div class="kpi-label">Primary Returns</div><div class="kpi-value"><?php echo number_format($grand['return_units']); ?></div></div>
    <div class="kpi-card kpi-orange"><div class="kpi-label">Pre Secondary out</div><div class="kpi-value"><?php echo number_format($grand['presec_units']); ?></div></div>
    <div class="kpi-card kpi-orange"><div class="kpi-label">Secondary Out <span style="font-weight:400;text-transform:none;font-size:9.5px">(ref only)</span></div><div class="kpi-value"><?php echo number_format($grand['secout_units']); ?></div></div>
    <div class="kpi-card kpi-teal"><div class="kpi-label">Unloding Qty from unloading</div><div class="kpi-value"><?php echo number_format($grand['unload_actual_units']); ?></div></div>
    <div class="kpi-card <?php echo $grand['vehicle_short_excess'] < 0 ? 'kpi-red' : 'kpi-green'; ?>"><div class="kpi-label">Vehicle Good Short/Excess <span style="font-weight:400;text-transform:none;font-size:9.5px">(ref only)</span></div><div class="kpi-value"><?php echo number_format($grand['vehicle_short_excess']); ?></div></div>
    <div class="kpi-card kpi-indigo"><div class="kpi-label">Sampath Rejected Qty</div><div class="kpi-value"><?php echo number_format($grand['rejected_units']); ?></div><div class="kpi-sub">GRN <?php echo number_format($grand['grn_rejected_units']); ?> + Manual <?php echo number_format($grand['manual_rejected_units']); ?></div></div>
    <div class="kpi-card kpi-gray"><div class="kpi-label">Adjustment Qty</div><div class="kpi-value"><?php echo number_format($grand['adjustment_units']); ?></div></div>
    <div class="kpi-card kpi-red"><div class="kpi-label">Stock Damage (-)</div><div class="kpi-value"><?php echo number_format($grand['damage_units']); ?></div></div>
    <div class="kpi-card <?php echo $grand['actual_units'] >= 0 ? 'kpi-green' : 'kpi-red'; ?>"><div class="kpi-label">Actual Qty Units</div><div class="kpi-value"><?php echo number_format($grand['actual_units']); ?></div><div class="kpi-sub">as at <?php echo htmlspecialchars(date('M d, Y', strtotime($as_at_date))); ?></div></div>
</div>

<!-- TABS -->
<div class="scr-tabs">
    <button class="scr-tab-btn active" onclick="scrShowTab('summary', this)"><i class="fa-solid fa-table-list"></i> Summary <span class="scr-tab-count"><?php echo count($rows); ?></span></button>
    <button class="scr-tab-btn" onclick="scrShowTab('purchase', this)"><i class="fa-solid fa-truck-ramp-box"></i> Primary Purchase <span class="scr-tab-count"><?php echo count($purchase_detail); ?></span></button>
    <button class="scr-tab-btn" onclick="scrShowTab('return', this)"><i class="fa-solid fa-rotate-left"></i> Primary Returns <span class="scr-tab-count"><?php echo count($return_detail); ?></span></button>
    <button class="scr-tab-btn" onclick="scrShowTab('presec', this)"><i class="fa-solid fa-dolly"></i> Pre Secondary Out <span class="scr-tab-count"><?php echo count($presec_detail); ?></span></button>
    <button class="scr-tab-btn" onclick="scrShowTab('secout', this)"><i class="fa-solid fa-cart-flatbed"></i> Secondary Out <span class="scr-tab-count"><?php echo count($secout_detail); ?></span></button>
    <button class="scr-tab-btn" onclick="scrShowTab('unload', this)"><i class="fa-solid fa-truck-loading"></i> Unloading <span class="scr-tab-count"><?php echo count($unload_detail); ?></span></button>
    <button class="scr-tab-btn" onclick="scrShowTab('damage', this)"><i class="fa-solid fa-ban"></i> Stock Damage <span class="scr-tab-count"><?php echo count($damage_detail); ?></span></button>
    <button class="scr-tab-btn" onclick="scrShowTab('grnreject', this)"><i class="fa-solid fa-truck-arrow-right"></i> Sampath Rejected (GRN) <span class="scr-tab-count"><?php echo count($grn_rejected_detail); ?></span></button>
    <button class="scr-tab-btn" onclick="scrShowTab('adjustments', this)"><i class="fa-solid fa-pen-to-square"></i> Rejected / Adjustments Log <span class="scr-tab-count"><?php echo count($adjustment_detail); ?></span></button>
</div>

<!-- ══ SUMMARY PANEL ══ -->
<div id="scr-panel-summary" class="scr-panel active content-card">
    <h3 class="card-title"><i class="fa-solid fa-warehouse" style="color:#3b82f6"></i> Item-wise Current Stock</h3>
    <?php if ($query_error): ?>
        <div class="alert-error">Query failed — see error above.</div>
    <?php elseif (empty($rows)): ?>
        <div style="text-align:center;padding:40px;color:#9ca3af">
            <i class="fa-solid fa-inbox" style="font-size:32px;margin-bottom:12px;display:block"></i>
            No items found as at <strong><?php echo htmlspecialchars($as_at_date); ?></strong>.
        </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="report-table">
            <thead>
                <tr>
                    <th style="width:100px">Item Code</th>
                    <th style="width:200px">Item Name</th>
                    <th style="width:60px">UPC</th>
                    <th>Primary Qty</th>
                    <th>Primary Returns</th>
                    <th>Pre Secondary out</th>
                    <th>Secondary Out<br><span style="font-weight:400;text-transform:none">(ref only)</span></th>
                    <th style="min-width:150px">Unloading qty base on<br>Pre secondary - Secondary out<br><span style="font-weight:400;text-transform:none">(ref only)</span></th>
                    <th style="min-width:130px">Unloding Qty<br>from unloading</th>
                    <th style="min-width:130px">Vehicle Good<br>Short/Excess<br><span style="font-weight:400;text-transform:none">(ref only)</span></th>
                    <th style="min-width:120px">Sampath<br>Rejected Qty</th>
                    <th style="min-width:100px">Adjustment Qty</th>
                    <th style="min-width:100px">Stock Damage (-)</th>
                    <th>Actual Qty Units</th>
                    <th>Cases from<br>Actual Qty Units</th>
                    <th style="min-width:120px">Units Balance<br>from cases</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
            <tr class="<?php echo $row['actual_units'] < 0 ? 'neg-row' : ''; ?>">
                <td class="left"><span class="code-pill"><?php echo htmlspecialchars($row['item_code']); ?></span></td>
                <td class="left"><?php echo htmlspecialchars($row['item_name'] ?: '—'); ?></td>
                <td><?php echo $row['upc'] ? number_format($row['upc'], 0) : '—'; ?></td>
                <td><?php echo number_format($row['purchase_units']); ?></td>
                <td><?php echo number_format($row['return_units']); ?></td>
                <td><?php echo number_format($row['presec_units']); ?></td>
                <td><?php echo number_format($row['secout_units']); ?></td>
                <td><?php echo number_format($row['unload_calc_units']); ?></td>
                <td><?php echo number_format($row['unload_actual_units']); ?></td>
                <td style="font-weight:600;color:<?php echo $row['vehicle_short_excess'] < 0 ? '#dc2626' : ($row['vehicle_short_excess'] > 0 ? '#16a34a' : '#374151'); ?>"><?php echo number_format($row['vehicle_short_excess']); ?></td>
                <td title="GRN <?php echo number_format($row['grn_rejected_units']); ?> + Manual <?php echo number_format($row['manual_rejected_units']); ?>"><?php echo number_format($row['rejected_units']); ?></td>
                <td><?php echo number_format($row['adjustment_units']); ?></td>
                <td><?php echo $row['damage_units'] ? '−' . number_format($row['damage_units']) : '0'; ?></td>
                <td style="font-weight:700;color:<?php echo $row['actual_units'] < 0 ? '#dc2626' : '#111827'; ?>"><?php echo number_format($row['actual_units']); ?></td>
                <td style="font-weight:600"><?php echo number_format($row['actual_cases']); ?></td>
                <td><?php echo number_format($row['actual_bal_units']); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" class="left"><strong>GRAND TOTAL</strong></td>
                    <td><strong><?php echo number_format($grand['purchase_units']); ?></strong></td>
                    <td><strong><?php echo number_format($grand['return_units']); ?></strong></td>
                    <td><strong><?php echo number_format($grand['presec_units']); ?></strong></td>
                    <td><strong><?php echo number_format($grand['secout_units']); ?></strong></td>
                    <td><strong><?php echo number_format($grand['unload_calc_units']); ?></strong></td>
                    <td><strong><?php echo number_format($grand['unload_actual_units']); ?></strong></td>
                    <td><strong><?php echo number_format($grand['vehicle_short_excess']); ?></strong></td>
                    <td><strong><?php echo number_format($grand['rejected_units']); ?></strong></td>
                    <td><strong><?php echo number_format($grand['adjustment_units']); ?></strong></td>
                    <td><strong>−<?php echo number_format($grand['damage_units']); ?></strong></td>
                    <td style="color:<?php echo $grand['actual_units'] < 0 ? '#dc2626' : '#111827'; ?>"><strong><?php echo number_format($grand['actual_units']); ?></strong></td>
                    <td colspan="2">—</td>
                </tr>
            </tfoot>
        </table>
    </div>
    <div class="note-box">
        <strong>Secondary Out</strong>, <strong>Unloading qty base on Pre secondary − Secondary out</strong>, and <strong>Vehicle Good Short/Excess</strong> are shown for reference only — none of them are added into or subtracted from <strong>Actual Qty Units</strong>.<br>
        <strong>Vehicle Good Short/Excess</strong> — minus means short, plus means excess. Calculated as <em>Unloding Qty from unloading − (Unloading qty base on Pre secondary − Secondary out)</em>, kept for information purposes only.<br>
        <strong>Sampath Rejected Qty</strong> = GRN Report auto feed (GRNs with status <em>saved</em>, <em>cc_checked</em> or <em>st_checked</em> — see the "Sampath Rejected (GRN)" tab) + any manual entries. Hover a row's Sampath Rejected Qty cell for the GRN / manual split.<br>
        <strong>Adjustment Qty</strong> is still keyed in manually via "Add Rejected / Adjustment Qty" above — Sampath does not provide this through any import.
    </div>
    <?php endif; ?>
</div>

<!-- ══ PRIMARY PURCHASE ══ -->
<div id="scr-panel-purchase" class="scr-panel content-card">
    <h3 class="card-title"><i class="fa-solid fa-truck-ramp-box" style="color:#3b82f6"></i> Primary Purchase <span style="font-size:12px;font-weight:400;color:#9ca3af">from Unilever Purchase Import, up to <?php echo htmlspecialchars($as_at_date); ?></span></h3>
    <?php if (empty($purchase_detail)): ?>
        <div style="text-align:center;padding:30px;color:#9ca3af">No purchase records up to this date.</div>
    <?php else: ?>
    <div class="table-responsive"><table class="report-table">
        <thead><tr><th>Invoice Date</th><th>Invoice No</th><th>Item Code</th><th style="min-width:200px">Item Name</th><th>Batch Code</th><th>Pack Size</th><th>UPC</th><th>Qty (Cases)</th><th>Price/Case</th></tr></thead>
        <tbody>
        <?php foreach ($purchase_detail as $r): ?>
        <tr>
            <td>
                <?php if (!empty($r['invoice_date'])): ?>
                    <?php echo date('M d, Y', strtotime($r['invoice_date'])); ?>
                <?php else: ?>
                    <span title="Invoice date was blank on this row — using the batch's received date instead">
                        <?php echo $r['received_date'] ? date('M d, Y', strtotime($r['received_date'])) : '—'; ?>
                        <span class="sub-txt">(received date)</span>
                    </span>
                <?php endif; ?>
            </td>
            <td class="left"><?php echo htmlspecialchars($r['invoice_number'] ?: '—'); ?></td>
            <td class="left"><span class="code-pill"><?php echo htmlspecialchars($r['item_code']); ?></span></td>
            <td class="left"><?php echo htmlspecialchars($r['item_name'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($r['batch_code'] ?? '—'); ?></td>
            <td><?php echo htmlspecialchars($r['pack_size'] ?? '—'); ?></td>
            <td><?php echo htmlspecialchars($r['upc'] ?? '—'); ?></td>
            <td><?php echo number_format((float)$r['purchase_qty_cases'], 2); ?></td>
            <td><?php echo $r['price_per_case'] !== null ? number_format((float)$r['price_per_case'], 2) : '—'; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<!-- ══ PRIMARY RETURNS ══ -->
<div id="scr-panel-return" class="scr-panel content-card">
    <h3 class="card-title"><i class="fa-solid fa-rotate-left" style="color:#8b5cf6"></i> Primary Returns <span style="font-size:12px;font-weight:400;color:#9ca3af">from Unilever Items Return, up to <?php echo htmlspecialchars($as_at_date); ?></span></h3>
    <?php if (empty($return_detail)): ?>
        <div style="text-align:center;padding:30px;color:#9ca3af">No purchase returns up to this date.</div>
    <?php else: ?>
    <div class="table-responsive"><table class="report-table">
        <thead><tr><th>Return Date</th><th>Return No</th><th>Item Code</th><th style="min-width:200px">Item Name</th><th>Batch Code</th><th>Pack Size</th><th>Qty (Cases)</th></tr></thead>
        <tbody>
        <?php foreach ($return_detail as $r): ?>
        <tr>
            <td><?php echo $r['return_date'] ? date('M d, Y', strtotime($r['return_date'])) : '—'; ?></td>
            <td class="left"><?php echo htmlspecialchars($r['return_no'] ?? '—'); ?></td>
            <td class="left"><span class="code-pill"><?php echo htmlspecialchars($r['item_code']); ?></span></td>
            <td class="left"><?php echo htmlspecialchars($r['item_name'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($r['batch_code'] ?? '—'); ?></td>
            <td><?php echo htmlspecialchars($r['pack_size'] ?? '—'); ?></td>
            <td><?php echo number_format((float)$r['qty'], 2); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<!-- ══ PRE SECONDARY OUT ══ -->
<div id="scr-panel-presec" class="scr-panel content-card">
    <h3 class="card-title"><i class="fa-solid fa-dolly" style="color:#f97316"></i> Pre Secondary Out <span style="font-size:12px;font-weight:400;color:#9ca3af">from Daily Pre Secondary Stock, up to <?php echo htmlspecialchars($as_at_date); ?></span></h3>
    <?php if (empty($presec_detail)): ?>
        <div style="text-align:center;padding:30px;color:#9ca3af">No pre secondary out records up to this date.</div>
    <?php else: ?>
    <div class="table-responsive"><table class="report-table">
        <thead><tr><th>Delivery Date</th><th>Bill No</th><th>Salesperson</th><th>Outlet</th><th>Item Code</th><th style="min-width:200px">Item Name</th><th>Units</th></tr></thead>
        <tbody>
        <?php foreach ($presec_detail as $r): ?>
        <tr>
            <td><?php echo $r['delivery_date'] ? date('M d, Y', strtotime($r['delivery_date'])) : '—'; ?></td>
            <td class="left"><?php echo htmlspecialchars($r['bill_number'] ?? '—'); ?></td>
            <td class="left"><?php echo htmlspecialchars($r['salesperson_name'] ?? '—'); ?></td>
            <td class="left"><?php echo htmlspecialchars($r['outlet_name'] ?? '—'); ?></td>
            <td class="left"><span class="code-pill"><?php echo htmlspecialchars($r['item_code']); ?></span></td>
            <td class="left"><?php echo htmlspecialchars($r['item_name'] ?? ''); ?></td>
            <td><?php echo number_format((float)$r['units']); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<!-- ══ SECONDARY OUT ══ -->
<div id="scr-panel-secout" class="scr-panel content-card">
    <h3 class="card-title"><i class="fa-solid fa-cart-flatbed" style="color:#f97316"></i> Secondary Out <span style="font-size:12px;font-weight:400;color:#9ca3af">from Invoice Wise Sales, up to <?php echo htmlspecialchars($as_at_date); ?> — reference only, not part of Actual Qty Units</span></h3>
    <?php if (empty($secout_detail)): ?>
        <div style="text-align:center;padding:30px;color:#9ca3af">No secondary out records up to this date.</div>
    <?php else: ?>
    <div class="table-responsive"><table class="report-table">
        <thead><tr><th>Delivery Date</th><th>Bill No</th><th>Salesperson</th><th>Outlet</th><th>Item Code</th><th style="min-width:200px">Item Name</th><th>Units</th></tr></thead>
        <tbody>
        <?php foreach ($secout_detail as $r): ?>
        <tr>
            <td><?php echo $r['delivery_date'] ? date('M d, Y', strtotime($r['delivery_date'])) : '—'; ?></td>
            <td class="left"><?php echo htmlspecialchars($r['bill_number'] ?? '—'); ?></td>
            <td class="left"><?php echo htmlspecialchars($r['salesperson_name'] ?? '—'); ?></td>
            <td class="left"><?php echo htmlspecialchars($r['outlet_name'] ?? '—'); ?></td>
            <td class="left"><span class="code-pill"><?php echo htmlspecialchars($r['item_code']); ?></span></td>
            <td class="left"><?php echo htmlspecialchars($r['item_name'] ?? ''); ?></td>
            <td><?php echo number_format((float)$r['units']); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<!-- ══ UNLOADING ══ -->
<div id="scr-panel-unload" class="scr-panel content-card">
    <h3 class="card-title"><i class="fa-solid fa-truck-loading" style="color:#14b8a6"></i> Unloading <span style="font-size:12px;font-weight:400;color:#9ca3af">from Shortage / Unloading, up to <?php echo htmlspecialchars($as_at_date); ?></span></h3>
    <?php if (empty($unload_detail)): ?>
        <div style="text-align:center;padding:30px;color:#9ca3af">No unloading records up to this date.</div>
    <?php else: ?>
    <div class="table-responsive"><table class="report-table">
        <thead><tr><th>Delivery Date</th><th>Delivery Person</th><th>Vehicle</th><th>Item Code</th><th style="min-width:200px">Item Name</th><th>Good Units</th><th>Damage Units</th><th>Total</th></tr></thead>
        <tbody>
        <?php foreach ($unload_detail as $r): $tot = (float)$r['adj_qty_good_units'] + (float)$r['adj_qty_damage']; ?>
        <tr>
            <td><?php echo $r['delivery_date'] ? date('M d, Y', strtotime($r['delivery_date'])) : '—'; ?></td>
            <td class="left"><?php echo htmlspecialchars($r['delivery_person_name'] ?? '—'); ?></td>
            <td class="left"><?php echo htmlspecialchars($r['vehicle'] ?? '—'); ?></td>
            <td class="left"><span class="code-pill"><?php echo htmlspecialchars($r['item_code']); ?></span></td>
            <td class="left"><?php echo htmlspecialchars($r['item_name'] ?? ''); ?></td>
            <td><?php echo number_format((float)$r['adj_qty_good_units']); ?></td>
            <td><?php echo number_format((float)$r['adj_qty_damage']); ?></td>
            <td style="font-weight:700"><?php echo number_format($tot); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<!-- ══ STOCK DAMAGE ══ -->
<div id="scr-panel-damage" class="scr-panel content-card">
    <h3 class="card-title"><i class="fa-solid fa-ban" style="color:#ef4444"></i> Stock Damage <span style="font-size:12px;font-weight:400;color:#9ca3af">from Sampath Goods Rejected / Damage Return import, up to <?php echo htmlspecialchars($as_at_date); ?></span></h3>
    <?php if (!$has_damage_tables): ?>
        <div style="text-align:center;padding:30px;color:#9ca3af">
            <i class="fa-solid fa-circle-info" style="font-size:26px;margin-bottom:10px;display:block"></i>
            Damage import tables aren't set up on this database yet — nothing to show. Stock Damage stays 0 in the Summary tab until a Damage Return batch is imported.
        </div>
    <?php elseif (empty($damage_detail)): ?>
        <div style="text-align:center;padding:30px;color:#9ca3af">No damage records up to this date.</div>
    <?php else: ?>
    <div class="table-responsive"><table class="report-table">
        <thead><tr><th>GRN Date</th><th>GRN No</th><th>Supplier</th><th>Item Code</th><th style="min-width:200px">Item Name</th><th>Qty</th></tr></thead>
        <tbody>
        <?php foreach ($damage_detail as $r): ?>
        <tr>
            <td><?php echo $r['grn_date'] ? date('M d, Y', strtotime($r['grn_date'])) : '—'; ?></td>
            <td class="left"><?php echo htmlspecialchars($r['grn_no'] ?? '—'); ?></td>
            <td class="left"><?php echo htmlspecialchars($r['supplier'] ?? '—'); ?></td>
            <td class="left"><span class="code-pill"><?php echo htmlspecialchars($r['item_code']); ?></span></td>
            <td class="left"><?php echo htmlspecialchars($r['item_name'] ?? ''); ?></td>
            <td><?php echo number_format((float)$r['qty'], 2); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<!-- ══ SAMPATH REJECTED (GRN) ══ -->
<div id="scr-panel-grnreject" class="scr-panel content-card">
    <h3 class="card-title"><i class="fa-solid fa-truck-arrow-right" style="color:#6366f1"></i> Sampath Rejected Qty — GRN Report <span style="font-size:12px;font-weight:400;color:#9ca3af">GRNs with status saved / cc_checked / st_checked, up to <?php echo htmlspecialchars($as_at_date); ?></span></h3>
    <?php if (!$has_grn_tables): ?>
        <div style="text-align:center;padding:30px;color:#9ca3af">
            <i class="fa-solid fa-circle-info" style="font-size:26px;margin-bottom:10px;display:block"></i>
            GRN Report tables aren't set up on this database yet — nothing to show. Sampath Rejected Qty falls back to manual entries only until GRNs are recorded via the GRN Report page.
        </div>
    <?php elseif (empty($grn_rejected_detail)): ?>
        <div style="text-align:center;padding:30px;color:#9ca3af">No saved GRNs up to this date.</div>
    <?php else: ?>
    <div class="table-responsive"><table class="report-table">
        <thead><tr><th>Delivery Date</th><th>GRN Ref</th><th>Outlet</th><th>Status</th><th>Item Code</th><th style="min-width:200px">Item Name</th><th>Qty</th></tr></thead>
        <tbody>
        <?php foreach ($grn_rejected_detail as $r): ?>
        <tr>
            <td><?php echo !empty($r['delivery_date']) ? date('M d, Y', strtotime($r['delivery_date'])) : ($r['created_at'] ? date('M d, Y', strtotime($r['created_at'])) . ' (created)' : '—'); ?></td>
            <td class="left"><span class="code-pill"><?php echo htmlspecialchars($r['grn_ref']); ?></span></td>
            <td class="left"><?php echo htmlspecialchars($r['outlet_name'] ?? '—'); ?></td>
            <td><?php echo htmlspecialchars($r['status']); ?></td>
            <td class="left"><span class="code-pill"><?php echo htmlspecialchars($r['item_code']); ?></span></td>
            <td class="left"><?php echo htmlspecialchars($r['item_name'] ?? ''); ?></td>
            <td><?php echo number_format((float)$r['qty'], 2); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<!-- ══ REJECTED / ADJUSTMENTS LOG ══ -->
<div id="scr-panel-adjustments" class="scr-panel content-card">
    <h3 class="card-title"><i class="fa-solid fa-pen-to-square" style="color:#6366f1"></i> Sampath Rejected Qty / Adjustment Qty Log <span style="font-size:12px;font-weight:400;color:#9ca3af">manual entries, up to <?php echo htmlspecialchars($as_at_date); ?></span></h3>
    <?php if (empty($adjustment_detail)): ?>
        <div style="text-align:center;padding:30px;color:#9ca3af">
            <i class="fa-solid fa-circle-info" style="font-size:26px;margin-bottom:10px;display:block"></i>
            No manual entries yet. Use "Add Rejected / Adjustment Qty" above once Sampath informs actual rejected quantities, or to key in a manual stock adjustment.
        </div>
    <?php else: ?>
    <div class="table-responsive"><table class="report-table">
        <thead><tr><th>Date</th><th>Item Code</th><th>Rejected Qty</th><th>Adjustment Qty</th><th style="min-width:200px">Note</th><th>Entered By</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($adjustment_detail as $r): ?>
        <tr>
            <td><?php echo $r['entry_date'] ? date('M d, Y', strtotime($r['entry_date'])) : '—'; ?></td>
            <td class="left"><span class="code-pill"><?php echo htmlspecialchars($r['item_code']); ?></span></td>
            <td><?php echo number_format((float)$r['rejected_qty'], 2); ?></td>
            <td><?php echo number_format((float)$r['adjustment_qty'], 2); ?></td>
            <td class="left"><?php echo htmlspecialchars($r['note'] ?: '—'); ?></td>
            <td class="left"><?php echo htmlspecialchars($r['created_by'] ?? '—'); ?></td>
            <td>
                <form method="POST" onsubmit="return confirm('Remove this entry?');" style="display:inline">
                    <input type="hidden" name="action" value="delete_adjustment">
                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                    <button type="submit" class="btn btn-danger btn-sm" style="padding:4px 10px"><i class="fa-solid fa-trash"></i></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<!-- ══ ADD ADJUSTMENT MODAL ══ -->
<div class="modal-overlay" id="scr-adj-modal">
    <div class="modal-box">
        <h4><i class="fa-solid fa-pen-to-square"></i> Add Sampath Rejected / Adjustment Qty</h4>
        <form method="POST">
            <input type="hidden" name="action" value="add_adjustment">
            <label>Item Code</label>
            <input type="text" name="item_code" list="scr-item-list" required placeholder="e.g. 3420311">
            <datalist id="scr-item-list">
                <?php foreach ($items as $code => $it): ?>
                <option value="<?php echo htmlspecialchars($code); ?>"><?php echo htmlspecialchars($it['item_name'] ?? ''); ?></option>
                <?php endforeach; ?>
            </datalist>

            <label>Date</label>
            <input type="date" name="entry_date" value="<?php echo htmlspecialchars($as_at_date); ?>" required>

            <label>Sampath Rejected Qty</label>
            <input type="number" step="0.01" name="rejected_qty" value="0">

            <label>Adjustment Qty</label>
            <input type="number" step="0.01" name="adjustment_qty" value="0">

            <label>Note (optional)</label>
            <textarea name="note" rows="2" placeholder="e.g. per Sampath email dated ..."></textarea>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="scrCloseAdjModal()">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Save</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var loader = document.getElementById('page-loader');
    if (loader) loader.style.display = 'none';
});

function scrShowTab(name, btn) {
    document.querySelectorAll('.scr-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.scr-tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('scr-panel-' + name).classList.add('active');
    btn.classList.add('active');
}

function scrOpenAdjModal() { document.getElementById('scr-adj-modal').classList.add('open'); }
function scrCloseAdjModal() { document.getElementById('scr-adj-modal').classList.remove('open'); }

/* ── Excel export, laid out the same way as the reference sheet ── */
const SUMMARY_ROWS = <?php echo $all_rows_json; ?>;
const AS_AT_DATE    = <?php echo json_encode($as_at_date); ?>;

function exportExcel() {
    if (typeof XLSX === 'undefined') { alert('Excel library still loading, please wait a moment.'); return; }
    const wb = XLSX.utils.book_new();

    const headers = ['Item Code','Item Name','UPC','Primary Qty','Primary Returns','Pre Secondary out',
        'Secondary Out (ref only)','Unloading qty base on Pre secondary - Secondary out (ref only)','Unloding Qty from unloading',
        'Vehicle Good Short/Excess (ref only)','Sampath Rejected Qty','GRN Rejected Qty (auto)','Manual Rejected Qty',
        'Adjustment Qty','Stock Damage (-)','Actual Qty Units','Cases from Actual Qty Units','Units Balance from cases'];

    const data = [['SAMPATH CURRENT STOCK REPORT — AS AT ' + AS_AT_DATE], [], headers];
    SUMMARY_ROWS.forEach(r => {
        data.push([
            r.item_code, r.item_name, +r.upc, +r.purchase_units, +r.return_units, +r.presec_units,
            +r.secout_units, +r.unload_calc_units, +r.unload_actual_units, +r.vehicle_short_excess,
            +r.rejected_units, +r.grn_rejected_units, +r.manual_rejected_units,
            +r.adjustment_units, -Math.abs(+r.damage_units),
            +r.actual_units, +r.actual_cases, +r.actual_bal_units
        ]);
    });
    data.push([]);
    // Notes, same column (Vehicle Good Short/Excess, index 9) as the reference sheet
    const noteRow1 = new Array(headers.length).fill(null); noteRow1[9] = 'Minus meens short plus means exces (reference only, not included in Actual Qty Units)';
    const noteRow2 = new Array(headers.length).fill(null); noteRow2[9] = 'Unloading - (Unloading qty base on Pre secondary - Secondary out)';
    data.push(noteRow1, noteRow2);

    const ws = XLSX.utils.aoa_to_sheet(data);
    ws['!cols'] = headers.map((_, i) => ({ wch: i < 2 ? 22 : 14 }));
    ws['!merges'] = [{ s:{r:0,c:0}, e:{r:0,c:headers.length-1} }];
    XLSX.utils.book_append_sheet(wb, ws, 'Current Stock');

    XLSX.writeFile(wb, 'Sampath_Current_Stock_Report_' + AS_AT_DATE + '.xlsx');
}
</script>

<?php
ob_end_flush();
include 'footer.php';