<?php
// ============================================================
//  sampath_stock_reconciliation.php
//  SAMPATH STOCK COUNT RECONCILIATION
//
//  Flow:
//    1. Select the Stock Count Date.
//    2. Select the reconciliation BASE:
//         a) CURRENT STOCK      -> system stock = running balance of every record on file up to
//                                  and including the date (exactly the Current Stock Report).
//                                  Last Stock Count Units = 0, movements from the very beginning.
//         b) LAST STOCK COUNT   -> system stock = the LAST UPLOADED physical count before the date (item-wise qty of that
//                                  one count only; an item with no qty in it starts at 0 - it is not taken from an
//                                  earlier count) + movements dated AFTER that count up to and including the date.
//    3. Upload the stock count Excel (Sr No | SKU7 | Product Name | Stock Count Unit).
//       SKU7 = our item code (same code used in the Current Stock Report / Tally).
//    4. The page shows the reconciliation (short / excess) against system stock.
//    5. "Save to DB" stores the count + the reconciliation snapshot (and which base was used).
//    6. "Saved Stock Counts" lists every saved count; View re-opens its reconciliation.
//
//  Reconciliation layout (same as srtock_count_reconc.xlsx):
//    Item Code / Item Name / UPC
//    Last Stock Count Units   -> (Last Stock Count base only) the item's qty in the most recent SAVED stock count
//                                dated BEFORE the selected date; 0 if it has no qty in that count (older counts are not used).
//                                Always 0 on the Current Stock base.
//    Primary Qty              -> Unilever Purchase Import  (cases x UPC = units)
//    Primary Returns          -> Unilever Items Return      (cases x UPC = units)
//    Secondary Out            -> Invoice Wise Sales         (units)
//    Adjustment Qty           -> stock_manual_adjustments   (units)
//    Actual Qty Units         = Last Stock Count + Primary Qty - Primary Returns
//                               - Secondary Out + Adjustment Qty
//    New Stock count base on selected date -> physical qty from the uploaded file
//    Dif Units                = New Stock count - Actual Qty Units
//                               minus = SHORT, plus = EXCESS
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
$SELF = 'sampath_stock_reconciliation.php';

$BASE_LABELS = [
    'current'    => 'Current Stock',
    'last_count' => 'Last Stock Count',
];
function sc_base($b) { return $b === 'current' ? 'current' : 'last_count'; }

/* ── Tables ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sampath_stock_count_headers (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    count_date DATE NOT NULL,
    base_type VARCHAR(20) NOT NULL DEFAULT 'last_count',
    file_name VARCHAR(255) DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_by VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_count_date (count_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Upgrade older installs: add base_type if the table was created before this column existed.
$colchk = mysqli_query($conn, "SHOW COLUMNS FROM sampath_stock_count_headers LIKE 'base_type'");
if ($colchk && mysqli_num_rows($colchk) === 0) {
    mysqli_query($conn, "ALTER TABLE sampath_stock_count_headers
        ADD COLUMN base_type VARCHAR(20) NOT NULL DEFAULT 'last_count' AFTER count_date");
}

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sampath_stock_count_items (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    header_id INT(11) NOT NULL,
    sr_no INT(11) DEFAULT NULL,
    item_code VARCHAR(100) NOT NULL,
    item_name VARCHAR(255) DEFAULT NULL,
    upc DECIMAL(12,2) NOT NULL DEFAULT 0,
    last_count_date DATE DEFAULT NULL,
    last_count_units DECIMAL(14,2) NOT NULL DEFAULT 0,
    primary_units DECIMAL(14,2) NOT NULL DEFAULT 0,
    return_units DECIMAL(14,2) NOT NULL DEFAULT 0,
    secout_units DECIMAL(14,2) NOT NULL DEFAULT 0,
    adjustment_units DECIMAL(14,2) NOT NULL DEFAULT 0,
    actual_units DECIMAL(14,2) NOT NULL DEFAULT 0,
    counted_units DECIMAL(14,2) DEFAULT NULL,
    diff_units DECIMAL(14,2) DEFAULT NULL,
    in_system TINYINT(1) NOT NULL DEFAULT 1,
    INDEX idx_header (header_id),
    INDEX idx_item (item_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Same definition the Current Stock Report uses (Adjustment Qty is keyed in there).
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

$JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

function sc_cut($s, $n) {
    $s = (string)$s;
    return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n);
}

function sc_valid_date($d) {
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false
        && date('Y-m-d', strtotime($d)) === $d;
}

/* ============================================================
   SYSTEM STOCK AS AT A COUNT DATE
   $base = 'current'    -> running balance from the very beginning (Current Stock Report)
   $base = 'last_count' -> last saved physical count before the date + movements after it
   Returns ['error'=>'', 'rows'=>[...], 'master'=>[code=>[name,upc]]]
   ============================================================ */
function sc_compute($conn, $dt_raw, $base = 'last_count') {
    $out = ['error' => '', 'rows' => [], 'master' => []];
    $base = sc_base($base);

    $required = ['unilever_purchase_items', 'unilever_purchase_batches', 'unilever_item_return_items',
                 'unilever_item_returns', 'invoice_wise_sales_data'];
    $missing = [];
    foreach ($required as $t) {
        $chk = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $t) . "'");
        if (!$chk || mysqli_num_rows($chk) === 0) $missing[] = $t;
    }
    if ($missing) {
        $out['error'] = 'Missing table(s) on this database: ' . implode(', ', $missing)
            . '. Import Unilever Purchase, Unilever Items Return and Invoice Wise Sales data first.';
        return $out;
    }

    $dt = mysqli_real_escape_string($conn, $dt_raw);

    /* Item master: latest unilever_purchase_items row per item code (name + UPC) */
    $master = [];
    $res = mysqli_query($conn, "SELECT p.item_code, p.item_name, p.upc
            FROM unilever_purchase_items p
            INNER JOIN (
                SELECT item_code, MAX(id) AS max_id
                FROM unilever_purchase_items
                WHERE item_code IS NOT NULL AND item_code <> ''
                GROUP BY item_code
            ) latest ON latest.max_id = p.id");
    if (!$res) { $out['error'] = mysqli_error($conn); return $out; }
    while ($r = mysqli_fetch_assoc($res)) {
        $code = trim((string)$r['item_code']);
        if ($code === '') continue;
        $master[$code] = [
            'name' => $r['item_name'],
            'upc'  => (float)preg_replace('/[^0-9.]/', '', (string)$r['upc']),
        ];
    }

    /* Last stock count (Last Stock Count base only): the base is the LAST UPLOADED count only, item by item.
       An item with no qty in that last count gets no base qty (0) - it is NOT taken from an earlier count -
       and every item's movements are counted only after the last count's date. */
    $last = [];
    $cut_date = null;       // date of the last uploaded count (movements after it are added)
    if ($base === 'last_count') {
        $hq = mysqli_query($conn, "SELECT id, count_date FROM sampath_stock_count_headers WHERE count_date < '$dt' ORDER BY count_date DESC, id DESC LIMIT 1");
        $hrow = $hq ? mysqli_fetch_assoc($hq) : null;
        if ($hrow) {
            $cut_date = substr((string)$hrow['count_date'], 0, 10);
            $res = mysqli_query($conn, "SELECT i.item_code, i.item_name, i.counted_units
                    FROM sampath_stock_count_items i
                    WHERE i.header_id = " . (int)$hrow['id'] . " AND i.counted_units IS NOT NULL");
            if (!$res) { $out['error'] = mysqli_error($conn); return $out; }
            while ($r = mysqli_fetch_assoc($res)) {
                $code = trim((string)$r['item_code']);
                if ($code === '') continue;
                $last[$code] = ['date' => $cut_date, 'units' => (float)$r['counted_units'], 'name' => $r['item_name']];
            }
        }
    }

    $acc = [];
    $seen = [];   // item codes that exist in any purchase / return / sales / adjustment record
    $touch = function ($code, $name = null) use (&$acc) {
        if (!isset($acc[$code])) $acc[$code] = ['pc' => 0.0, 'rc' => 0.0, 'so' => 0.0, 'adj' => 0.0, 'name' => null];
        if ($acc[$code]['name'] === null && $name !== null && $name !== '') $acc[$code]['name'] = $name;
    };
    foreach ($last as $code => $l) $touch($code, $l['name']);

    // Current Stock base: every record counts. Last Stock Count base: only records after the last uploaded count's date.
    $in_window = function ($code, $d) use ($cut_date) {
        $d = substr((string)$d, 0, 10);
        if ($d === '') return false;
        return $cut_date === null || $d > $cut_date;
    };

    /* Primary Qty (cases) */
    $res = mysqli_query($conn, "SELECT p.item_code, COALESCE(p.invoice_date, b.received_date) AS d,
                   SUM(CASE WHEN p.purchase_qty_cases > 0 THEN p.purchase_qty_cases ELSE 0 END) AS q, SUM(CASE WHEN p.purchase_qty_cases < 0 THEN -p.purchase_qty_cases ELSE 0 END) AS rq, MAX(p.item_name) AS nm
            FROM unilever_purchase_items p
            JOIN unilever_purchase_batches b ON b.id = p.batch_id
            WHERE p.item_code IS NOT NULL AND p.item_code <> ''
              AND COALESCE(p.invoice_date, b.received_date) <= '$dt'
            GROUP BY p.item_code, COALESCE(p.invoice_date, b.received_date)");
    if (!$res) { $out['error'] = mysqli_error($conn); return $out; }
    while ($r = mysqli_fetch_assoc($res)) {
        $code = trim((string)$r['item_code']);
        if ($code === '') continue;
        $seen[$code] = true;
        if (!$in_window($code, $r['d'])) continue;
        $touch($code, $r['nm']);
        $acc[$code]['pc'] += (float)$r['q'];
        $acc[$code]['rc'] += (float)$r['rq'];   // MINUS qty rows of the purchase import = purchase returns
    }

    /* Primary Returns (cases) */
    $res = mysqli_query($conn, "SELECT ri.item_code, r.return_date AS d, SUM(ri.qty) AS q, MAX(ri.item_name) AS nm
            FROM unilever_item_return_items ri
            JOIN unilever_item_returns r ON r.id = ri.return_id
            WHERE ri.item_code IS NOT NULL AND ri.item_code <> '' AND r.return_date <= '$dt'
            GROUP BY ri.item_code, r.return_date");
    if (!$res) { $out['error'] = mysqli_error($conn); return $out; }
    while ($r = mysqli_fetch_assoc($res)) {
        $code = trim((string)$r['item_code']);
        if ($code === '') continue;
        $seen[$code] = true;
        if (!$in_window($code, $r['d'])) continue;
        $touch($code, $r['nm']);
        $acc[$code]['rc'] += (float)$r['q'];
    }

    /* Secondary Out (units) */
    $res = mysqli_query($conn, "SELECT product_code AS item_code, delivery_date AS d,
                   SUM(units) AS q, MAX(product_description) AS nm
            FROM invoice_wise_sales_data
            WHERE product_code IS NOT NULL AND product_code <> '' AND delivery_date <= '$dt'
            GROUP BY product_code, delivery_date");
    if (!$res) { $out['error'] = mysqli_error($conn); return $out; }
    while ($r = mysqli_fetch_assoc($res)) {
        $code = trim((string)$r['item_code']);
        if ($code === '') continue;
        $seen[$code] = true;
        if (!$in_window($code, $r['d'])) continue;
        $touch($code, $r['nm']);
        $acc[$code]['so'] += (float)$r['q'];
    }

    /* Adjustment Qty (units) */
    $res = mysqli_query($conn, "SELECT item_code, entry_date AS d, SUM(adjustment_qty) AS q
            FROM stock_manual_adjustments
            WHERE entry_date <= '$dt'
            GROUP BY item_code, entry_date");
    if (!$res) { $out['error'] = mysqli_error($conn); return $out; }
    while ($r = mysqli_fetch_assoc($res)) {
        $code = trim((string)$r['item_code']);
        if ($code === '') continue;
        $seen[$code] = true;
        if (!$in_window($code, $r['d'])) continue;
        $touch($code);
        $acc[$code]['adj'] += (float)$r['q'];
    }

    /* Final rows */
    $rows = [];
    foreach ($acc as $code => $a) {
        $m   = $master[$code] ?? null;
        $upc = $m['upc'] ?? 0.0;
        $last_units = $last[$code]['units'] ?? 0.0;
        $primary = $upc > 0 ? $a['pc'] * $upc : 0.0;
        $return  = $upc > 0 ? $a['rc'] * $upc : 0.0;
        $actual  = $last_units + $primary - $return - $a['so'] + $a['adj'];
        $rows[] = [
            'item_code'        => (string)$code,
            'in_system'        => (isset($master[$code]) || isset($seen[$code])) ? 1 : 0,
            'item_name'        => $m['name'] ?? $a['name'],
            'upc'              => round($upc, 2),
            'last_count_date'  => $last[$code]['date'] ?? null,
            'last_count_units' => round($last_units, 2),
            'primary_units'    => round($primary, 2),
            'return_units'     => round($return, 2),
            'secout_units'     => round($a['so'], 2),
            'adjustment_units' => round($a['adj'], 2),
            'actual_units'     => round($actual, 2),
        ];
    }
    usort($rows, fn($a, $b) => strcmp($a['item_code'], $b['item_code']));

    $out['rows'] = $rows;
    $out['master'] = $master;
    return $out;
}

/* ============================================================
   AJAX: system stock for a chosen count date + base
   (?ajax=system&count_date=YYYY-MM-DD&base=current|last_count)
   ============================================================ */
if (($_GET['ajax'] ?? '') === 'system') {
    $d    = trim($_GET['count_date'] ?? '');
    $base = sc_base($_GET['base'] ?? 'last_count');
    $resp = ['ok' => false, 'error' => '', 'base' => $base, 'rows' => [], 'master' => new stdClass(),
             'existing' => null, 'prev_count_date' => null];
    if (!sc_valid_date($d)) {
        $resp['error'] = 'Please select a valid stock count date.';
    } else {
        $calc = sc_compute($conn, $d, $base);
        if ($calc['error'] !== '') {
            $resp['error'] = $calc['error'];
        } else {
            $resp['ok'] = true;
            $resp['rows'] = $calc['rows'];
            $resp['master'] = $calc['master'] ? $calc['master'] : new stdClass();
            $de = mysqli_real_escape_string($conn, $d);
            $q = mysqli_query($conn, "SELECT id, base_type, created_by, created_at FROM sampath_stock_count_headers WHERE count_date = '$de' LIMIT 1");
            if ($q && ($ex = mysqli_fetch_assoc($q))) $resp['existing'] = $ex;
            $q = mysqli_query($conn, "SELECT MAX(count_date) AS pd FROM sampath_stock_count_headers WHERE count_date < '$de'");
            if ($q && ($pr = mysqli_fetch_assoc($q))) $resp['prev_count_date'] = $pr['pd'];
        }
    }
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($resp, $JSON_FLAGS);
    exit;
}

$flash_ok = '';
$flash_err = '';

/* ============================================================
   POST: save a stock count (recalculated on the server, not trusted from the browser)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_count') {
    $count_date = trim($_POST['count_date'] ?? '');
    $base       = sc_base($_POST['base'] ?? 'last_count');
    $file_name  = sc_cut(trim($_POST['file_name'] ?? ''), 255);
    $note       = sc_cut(trim($_POST['note'] ?? ''), 255);
    $lines      = json_decode($_POST['lines_json'] ?? '[]', true);

    if (!sc_valid_date($count_date)) {
        $flash_err = 'Please select a valid stock count date.';
    } elseif (!is_array($lines) || count($lines) === 0) {
        $flash_err = 'No stock count lines were received — upload the stock count Excel first.';
    } else {
        $calc = sc_compute($conn, $count_date, $base);
        if ($calc['error'] !== '') {
            $flash_err = $calc['error'];
        } else {
            // counted qty per SKU7 (duplicate SKUs in the file are added together)
            $counted = [];
            foreach ($lines as $ln) {
                $code = trim((string)($ln['code'] ?? ''));
                if ($code === '' || !is_numeric($ln['qty'] ?? null)) continue;
                if (!isset($counted[$code])) {
                    $counted[$code] = ['qty' => 0.0, 'sr' => $ln['sr'] ?? null, 'name' => trim((string)($ln['name'] ?? ''))];
                }
                $counted[$code]['qty'] += (float)$ln['qty'];
            }

            $final = [];
            foreach ($calc['rows'] as $r) {
                $r['sr_no'] = null; $r['counted_units'] = null; $r['diff_units'] = null;
                $final[$r['item_code']] = $r;
            }
            foreach ($counted as $code => $c) {
                if (!isset($final[$code])) {
                    $m = $calc['master'][$code] ?? null;
                    $final[$code] = [
                        'item_code' => (string)$code, 'item_name' => $m['name'] ?? $c['name'], 'upc' => $m['upc'] ?? 0,
                        'last_count_date' => null, 'last_count_units' => 0, 'primary_units' => 0, 'return_units' => 0,
                        'secout_units' => 0, 'adjustment_units' => 0, 'actual_units' => 0,
                        'in_system' => $m ? 1 : 0, 'sr_no' => null, 'counted_units' => null, 'diff_units' => null,
                    ];
                }
                $final[$code]['counted_units'] = round($c['qty'], 2);
                $final[$code]['diff_units']    = round($c['qty'] - $final[$code]['actual_units'], 2);
                $final[$code]['sr_no']         = is_numeric($c['sr']) ? (int)$c['sr'] : null;
                if (empty($final[$code]['item_name'])) $final[$code]['item_name'] = $c['name'];
            }
            ksort($final, SORT_STRING);

            if (count($counted) === 0) {
                $flash_err = 'None of the uploaded lines had a valid SKU7 and Stock Count Unit.';
            } else {
                $esc = fn($s) => mysqli_real_escape_string($conn, (string)$s);
                $qs  = fn($s) => "'" . mysqli_real_escape_string($conn, (string)$s) . "'";
                $n   = fn($v) => $v === null ? 'NULL' : sprintf('%.2F', $v);

                mysqli_begin_transaction($conn);
                $ok = true;
                $dt_e = $esc($count_date);

                $q = mysqli_query($conn, "SELECT id FROM sampath_stock_count_headers WHERE count_date = '$dt_e'");
                if ($q) {
                    while ($old = mysqli_fetch_assoc($q)) {                  // replace any earlier count for this date
                        $oid = (int)$old['id'];
                        $ok = $ok && mysqli_query($conn, "DELETE FROM sampath_stock_count_items WHERE header_id = $oid");
                        $ok = $ok && mysqli_query($conn, "DELETE FROM sampath_stock_count_headers WHERE id = $oid");
                    }
                } else { $ok = false; }

                $new_id = 0;
                if ($ok) {
                    $ok = mysqli_query($conn, "INSERT INTO sampath_stock_count_headers (count_date, base_type, file_name, note, created_by)
                            VALUES ('$dt_e', " . $qs($base) . ", " . $qs($file_name) . ", " . $qs($note) . ", " . $qs($session_user) . ")");
                    $new_id = $ok ? (int)mysqli_insert_id($conn) : 0;
                }

                if ($ok && $new_id > 0) {
                    $cols = "(header_id, sr_no, item_code, item_name, upc, last_count_date, last_count_units, primary_units,
                              return_units, secout_units, adjustment_units, actual_units, counted_units, diff_units, in_system)";
                    foreach (array_chunk(array_values($final), 200) as $chunk) {
                        $vals = [];
                        foreach ($chunk as $f) {
                            $vals[] = '(' . $new_id . ','
                                . ($f['sr_no'] === null ? 'NULL' : (int)$f['sr_no']) . ','
                                . $qs($f['item_code']) . ','
                                . $qs(sc_cut($f['item_name'] ?? '', 255)) . ','
                                . $n($f['upc']) . ','
                                . ($f['last_count_date'] ? $qs($f['last_count_date']) : 'NULL') . ','
                                . $n($f['last_count_units']) . ',' . $n($f['primary_units']) . ','
                                . $n($f['return_units']) . ',' . $n($f['secout_units']) . ','
                                . $n($f['adjustment_units']) . ',' . $n($f['actual_units']) . ','
                                . $n($f['counted_units']) . ',' . $n($f['diff_units']) . ','
                                . (int)$f['in_system'] . ')';
                        }
                        if (!mysqli_query($conn, "INSERT INTO sampath_stock_count_items $cols VALUES " . implode(',', $vals))) {
                            $ok = false; break;
                        }
                    }
                } else { $ok = false; }

                if ($ok) {
                    mysqli_commit($conn);
                    if (!headers_sent()) {
                        while (ob_get_level() > 0) ob_end_clean();
                        header('Location: ' . $SELF . '?view=' . $new_id . '&saved=1');
                        exit;
                    }
                    $flash_ok = 'Stock count saved.';
                } else {
                    $err = mysqli_error($conn);
                    mysqli_rollback($conn);
                    $flash_err = 'Could not save the stock count: ' . $err;
                }
            }
        }
    }
}

/* ── POST: delete a saved stock count ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_count') {
    $del_id = (int)($_POST['id'] ?? 0);
    if ($del_id > 0) {
        mysqli_begin_transaction($conn);
        $ok = mysqli_query($conn, "DELETE FROM sampath_stock_count_items WHERE header_id = $del_id")
           && mysqli_query($conn, "DELETE FROM sampath_stock_count_headers WHERE id = $del_id");
        if ($ok) {
            mysqli_commit($conn);
            if (!headers_sent()) {
                while (ob_get_level() > 0) ob_end_clean();
                header('Location: ' . $SELF . '?tab=list&deleted=1');
                exit;
            }
            $flash_ok = 'Stock count deleted.';
        } else {
            mysqli_rollback($conn);
            $flash_err = 'Could not delete: ' . mysqli_error($conn);
        }
    }
}

if (isset($_GET['saved']))   $flash_ok = 'Stock count saved to the database.';
if (isset($_GET['deleted'])) $flash_ok = 'Stock count deleted.';

/* ============================================================
   PAGE MODE:  new (default) | list | view
   ============================================================ */
$page_mode  = (($_GET['tab'] ?? '') === 'list') ? 'list' : 'new';
$view_head  = null;
$view_rows  = [];
$saved_list = [];

$view_id = (int)($_GET['view'] ?? 0);
if ($view_id > 0) {
    $q = mysqli_query($conn, "SELECT * FROM sampath_stock_count_headers WHERE id = $view_id");
    $view_head = $q ? mysqli_fetch_assoc($q) : null;
    if ($view_head) {
        $page_mode = 'view';
        $view_head['base_type'] = sc_base($view_head['base_type'] ?? 'last_count');
        $q = mysqli_query($conn, "SELECT sr_no, item_code, item_name, upc, last_count_date, last_count_units, primary_units,
                    return_units, secout_units, adjustment_units, actual_units, counted_units, diff_units, in_system
                FROM sampath_stock_count_items WHERE header_id = $view_id ORDER BY item_code ASC");
        if ($q) {
            while ($r = mysqli_fetch_assoc($q)) {
                foreach (['upc','last_count_units','primary_units','return_units','secout_units','adjustment_units','actual_units'] as $k) {
                    $r[$k] = (float)$r[$k];
                }
                $r['counted_units'] = $r['counted_units'] === null ? null : (float)$r['counted_units'];
                $r['diff_units']    = $r['diff_units'] === null ? null : (float)$r['diff_units'];
                $r['in_system']     = (int)$r['in_system'];
                $view_rows[] = $r;
            }
        }
    } else {
        $flash_err = 'That stock count was not found.';
    }
}

if ($page_mode === 'list') {
    $q = mysqli_query($conn, "SELECT h.id, h.count_date, h.base_type, h.file_name, h.note, h.created_by, h.created_at,
                COUNT(i.id) AS n_items,
                SUM(CASE WHEN i.counted_units IS NOT NULL THEN 1 ELSE 0 END) AS n_counted,
                SUM(CASE WHEN i.diff_units < 0 THEN 1 ELSE 0 END) AS n_short,
                SUM(CASE WHEN i.diff_units > 0 THEN 1 ELSE 0 END) AS n_excess,
                COALESCE(SUM(CASE WHEN i.diff_units < 0 THEN i.diff_units ELSE 0 END), 0) AS short_units,
                COALESCE(SUM(CASE WHEN i.diff_units > 0 THEN i.diff_units ELSE 0 END), 0) AS excess_units
            FROM sampath_stock_count_headers h
            LEFT JOIN sampath_stock_count_items i ON i.header_id = h.id
            GROUP BY h.id, h.count_date, h.base_type, h.file_name, h.note, h.created_by, h.created_at
            ORDER BY h.count_date DESC");
    if ($q) { while ($r = mysqli_fetch_assoc($q)) $saved_list[] = $r; }
}

$init_date = trim($_GET['count_date'] ?? '');
if (!sc_valid_date($init_date)) $init_date = date('Y-m-d');
$init_base = sc_base($_GET['base'] ?? 'last_count');

include 'header.php';
?>
<style>
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:20px}
.card-title{font-size:16px;font-weight:600;margin-bottom:16px;color:#1f2937;display:flex;align-items:center;gap:8px}
.filter-bar{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end}
.filter-group{display:flex;flex-direction:column;gap:4px}
.filter-group label{font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px}
.filter-input{padding:8px 12px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;color:#333;background:#fff;min-width:150px}
.filter-input:focus{outline:none;border-color:#000}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none}
.btn:disabled{opacity:.45;cursor:not-allowed}
.btn-primary{background:#000;color:#fff}.btn-primary:hover:not(:disabled){background:#333}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}.btn-secondary:hover:not(:disabled){background:#e5e5e5}
.btn-success{background:#16a34a;color:#fff}.btn-success:hover:not(:disabled){background:#15803d}
.btn-purple{background:#8b5cf6;color:#fff}.btn-purple:hover:not(:disabled){background:#7c3aed}
.btn-danger{background:#ef4444;color:#fff}.btn-danger:hover:not(:disabled){background:#dc2626}
.btn-sm{padding:6px 14px;font-size:12px}
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:20px}
.kpi-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:16px;position:relative;overflow:hidden}
.kpi-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px}
.kpi-blue::before{background:#3b82f6}.kpi-green::before{background:#22c55e}.kpi-red::before{background:#ef4444}
.kpi-orange::before{background:#f97316}.kpi-gray::before{background:#6b7280}.kpi-indigo::before{background:#6366f1}
.kpi-label{font-size:10.5px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px}
.kpi-value{font-size:18px;font-weight:700;color:#1f2937}
.kpi-sub{font-size:11px;color:#9ca3af;margin-top:3px}
.alert-error{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;border-radius:8px;padding:14px 20px;margin-bottom:20px;font-size:13px}
.alert-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;border-radius:8px;padding:14px 20px;margin-bottom:20px;font-size:13px}
.alert-warn{background:#fffbeb;border:1px solid #fcd34d;color:#92400e;border-radius:8px;padding:12px 16px;margin-top:12px;font-size:12.5px}
.alert-info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;border-radius:8px;padding:12px 16px;margin-top:12px;font-size:12.5px}
.table-responsive{overflow:auto;-webkit-overflow-scrolling:touch;max-height:72vh}
.report-table{width:100%;border-collapse:collapse;font-size:12px;min-width:1250px}
.report-table thead th{background:#000;color:#fff;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;padding:11px 8px;text-align:center;border:1px solid #333;position:sticky;top:0;z-index:2}
.report-table tbody tr{border-bottom:1px solid #f0f0f0}
.report-table tbody tr:hover{background:#f8fafc}
.report-table tbody tr.row-short{background:#fef2f2}
.report-table tbody tr.row-excess{background:#f0fdf4}
.report-table td{padding:8px 8px;color:#374151;text-align:center;border:1px solid #f0f0f0;white-space:nowrap}
.report-table td.left{text-align:left}
.report-table tfoot tr{background:#f1f5f9;font-weight:700}
.report-table tfoot td{padding:11px 9px;border:1px solid #e2e8f0;font-size:12px;color:#1f2937;position:sticky;bottom:0;background:#f1f5f9}
.code-pill{font-family:monospace;font-weight:700;background:#eef2ff;color:#3730a3;border-radius:5px;padding:2px 7px;font-size:11px}
.sub-txt{display:block;font-size:10.5px;color:#9ca3af;font-weight:400;margin-top:1px}
.pill{display:inline-block;border-radius:10px;padding:2px 9px;font-size:10.5px;font-weight:700}
.pill-short{background:#fee2e2;color:#b91c1c}.pill-excess{background:#dcfce7;color:#15803d}
.pill-match{background:#e0f2fe;color:#0369a1}.pill-notc{background:#f3f4f6;color:#6b7280}
.pill-nosys{background:#fef3c7;color:#92400e;margin-left:4px}
.pill-base-current{background:#ede9fe;color:#5b21b6}.pill-base-last{background:#e0f2fe;color:#0369a1}
.txt-red{color:#dc2626;font-weight:700}.txt-green{color:#16a34a;font-weight:700}
.note-box{font-size:11.5px;color:#6b7280;background:#f9fafb;border:1px dashed #e5e7eb;border-radius:6px;padding:10px 14px;margin-top:14px;line-height:1.6}
.mode-tabs{display:flex;gap:6px;margin-bottom:18px;border-bottom:2px solid #f0f0f0}
.mode-tab{display:inline-flex;align-items:center;gap:7px;padding:10px 16px;font-size:13px;font-weight:600;color:#6b7280;text-decoration:none;border-bottom:3px solid transparent;margin-bottom:-2px}
.mode-tab:hover{color:#111}.mode-tab.active{color:#000;border-bottom-color:#000}
.step-num{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:#000;color:#fff;font-size:11px;font-weight:700;margin-right:4px}
.meta-line{font-size:12.5px;color:#6b7280;margin-top:4px}
.base-choice{display:flex;gap:8px;flex-wrap:wrap}
.base-opt{display:flex;align-items:flex-start;gap:8px;border:1.5px solid #e5e5e5;border-radius:8px;padding:9px 12px;cursor:pointer;min-width:230px;max-width:320px;background:#fff;text-transform:none!important;letter-spacing:0!important}
.base-opt input{margin-top:3px}
.base-opt.active{border-color:#000;background:#fafafa}
.base-opt .bo-title{font-size:13px;font-weight:700;color:#111}
.base-opt .bo-desc{font-size:11px;color:#6b7280;font-weight:400;line-height:1.4;margin-top:2px}
@media print{.filter-bar,.btn,.mode-tabs,.no-print,.page-header .btn{display:none!important}.content-card{border:none;padding:0}.table-responsive{max-height:none;overflow:visible}.report-table{font-size:9px;min-width:0}.report-table thead th,.report-table tfoot td{position:static}}
</style>

<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
        <div>
            <h2 class="page-title">
                <i class="fa-solid fa-clipboard-check" style="color:#000"></i>
                SAMPATH STOCK COUNT RECONCILIATION
            </h2>
            <p class="page-subtitle">Select the stock count date, choose the base (Current Stock or Last Stock Count), upload the stock count Excel, and see short / excess per item.</p>
        </div>
    </div>
</div>

<div class="mode-tabs no-print">
    <a class="mode-tab <?php echo $page_mode === 'new' ? 'active' : ''; ?>" href="<?php echo $SELF; ?>"><i class="fa-solid fa-file-arrow-up"></i> New Stock Count</a>
    <a class="mode-tab <?php echo ($page_mode === 'list' || $page_mode === 'view') ? 'active' : ''; ?>" href="<?php echo $SELF; ?>?tab=list"><i class="fa-solid fa-folder-open"></i> Saved Stock Counts</a>
</div>

<?php if ($flash_ok): ?><div class="alert-ok"><?php echo htmlspecialchars($flash_ok); ?></div><?php endif; ?>
<?php if ($flash_err): ?><div class="alert-error"><?php echo htmlspecialchars($flash_err); ?></div><?php endif; ?>

<?php if ($page_mode === 'list'): ?>
<!-- ══ SAVED STOCK COUNTS ══ -->
<div class="content-card">
    <h3 class="card-title"><i class="fa-solid fa-folder-open" style="color:#3b82f6"></i> Saved Stock Counts</h3>
    <?php if (empty($saved_list)): ?>
        <div style="text-align:center;padding:36px;color:#9ca3af">
            <i class="fa-solid fa-inbox" style="font-size:30px;margin-bottom:10px;display:block"></i>
            No stock counts saved yet. Use <strong>New Stock Count</strong> to upload one.
        </div>
    <?php else: ?>
    <div class="table-responsive"><table class="report-table" style="min-width:1100px">
        <thead><tr>
            <th>Stock Count Date</th><th>Base</th><th>Items</th><th>Counted</th><th>Short Items</th><th>Short Units</th>
            <th>Excess Items</th><th>Excess Units</th><th>Net Dif Units</th><th>File</th><th>Saved By</th><th>Saved At</th><th>Action</th>
        </tr></thead>
        <tbody>
        <?php foreach ($saved_list as $s): $net = (float)$s['short_units'] + (float)$s['excess_units']; $sb = sc_base($s['base_type'] ?? ''); ?>
        <tr>
            <td style="font-weight:700"><?php echo htmlspecialchars(date('M d, Y', strtotime($s['count_date']))); ?></td>
            <td><span class="pill <?php echo $sb === 'current' ? 'pill-base-current' : 'pill-base-last'; ?>"><?php echo htmlspecialchars($BASE_LABELS[$sb]); ?></span></td>
            <td><?php echo (int)$s['n_items']; ?></td>
            <td><?php echo (int)$s['n_counted']; ?></td>
            <td><?php echo (int)$s['n_short']; ?></td>
            <td class="<?php echo (float)$s['short_units'] < 0 ? 'txt-red' : ''; ?>"><?php echo number_format((float)$s['short_units'], 0); ?></td>
            <td><?php echo (int)$s['n_excess']; ?></td>
            <td class="<?php echo (float)$s['excess_units'] > 0 ? 'txt-green' : ''; ?>"><?php echo (float)$s['excess_units'] > 0 ? '+' : ''; ?><?php echo number_format((float)$s['excess_units'], 0); ?></td>
            <td style="font-weight:700" class="<?php echo $net < 0 ? 'txt-red' : ($net > 0 ? 'txt-green' : ''); ?>"><?php echo ($net > 0 ? '+' : '') . number_format($net, 0); ?></td>
            <td class="left"><?php echo htmlspecialchars($s['file_name'] ?: '—'); ?></td>
            <td class="left"><?php echo htmlspecialchars($s['created_by'] ?: '—'); ?></td>
            <td><?php echo htmlspecialchars(date('M d, Y H:i', strtotime($s['created_at']))); ?></td>
            <td style="white-space:nowrap">
                <a href="<?php echo $SELF; ?>?view=<?php echo (int)$s['id']; ?>" class="btn btn-primary btn-sm"><i class="fa-solid fa-eye"></i> View</a>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete the stock count for <?php echo htmlspecialchars($s['count_date']); ?>? This cannot be undone.');">
                    <input type="hidden" name="action" value="delete_count">
                    <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                    <button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-trash"></i></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<?php elseif ($page_mode === 'view'): ?>
<!-- ══ VIEW A SAVED COUNT ══ -->
<div class="content-card">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
        <div>
            <h3 class="card-title" style="margin-bottom:4px"><i class="fa-solid fa-clipboard-check" style="color:#16a34a"></i>
                Stock Count — <?php echo htmlspecialchars(date('M d, Y', strtotime($view_head['count_date']))); ?>
                <span class="pill <?php echo $view_head['base_type'] === 'current' ? 'pill-base-current' : 'pill-base-last'; ?>">Base: <?php echo htmlspecialchars($BASE_LABELS[$view_head['base_type']]); ?></span>
            </h3>
            <div class="meta-line">
                Saved by <strong><?php echo htmlspecialchars($view_head['created_by'] ?: '—'); ?></strong>
                on <?php echo htmlspecialchars(date('M d, Y H:i', strtotime($view_head['created_at']))); ?>
                <?php if ($view_head['file_name']): ?> · File: <?php echo htmlspecialchars($view_head['file_name']); ?><?php endif; ?>
                <?php if ($view_head['note']): ?> · Note: <?php echo htmlspecialchars($view_head['note']); ?><?php endif; ?>
            </div>
        </div>
        <div class="no-print" style="display:flex;gap:8px;flex-wrap:wrap">
            <a href="<?php echo $SELF; ?>?tab=list" class="btn btn-secondary btn-sm"><i class="fa-solid fa-arrow-left"></i> Back</a>
            <button onclick="window.print()" class="btn btn-secondary btn-sm"><i class="fa-solid fa-print"></i> Print</button>
            <button onclick="exportExcel()" class="btn btn-success btn-sm"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
        </div>
    </div>
    <div class="note-box" style="margin-top:12px">This is the reconciliation exactly as it was saved on the day — later imports or a newer stock count do not change it.</div>
</div>

<?php else: ?>
<!-- ══ NEW STOCK COUNT ══ -->
<div class="content-card no-print">
    <div class="filter-bar">
        <div class="filter-group">
            <label><span class="step-num">1</span> Stock Count Date</label>
            <input type="date" id="sc-date" class="filter-input" value="<?php echo htmlspecialchars($init_date); ?>">
        </div>
        <div class="filter-group">
            <label><span class="step-num">2</span> Reconcile Against</label>
            <div class="base-choice">
                <label class="base-opt <?php echo $init_base === 'current' ? 'active' : ''; ?>">
                    <input type="radio" name="sc-base" value="current" <?php echo $init_base === 'current' ? 'checked' : ''; ?>>
                    <span><span class="bo-title">Current Stock</span>
                    <span class="bo-desc" style="display:block">Running balance of every record up to the date — same as the Current Stock Report.</span></span>
                </label>
                <label class="base-opt <?php echo $init_base === 'last_count' ? 'active' : ''; ?>">
                    <input type="radio" name="sc-base" value="last_count" <?php echo $init_base === 'last_count' ? 'checked' : ''; ?>>
                    <span><span class="bo-title">Last Stock Count</span>
                    <span class="bo-desc" style="display:block">Last uploaded stock count only (item qty; items with no qty in it start at 0) + movements after its date, up to the date.</span></span>
                </label>
            </div>
        </div>
    </div>
    <div class="filter-bar" style="margin-top:14px">
        <div class="filter-group" style="min-width:280px">
            <label><span class="step-num">3</span> Stock Count Excel (Sr No · SKU7 · Product Name · Stock Count Unit)</label>
            <input type="file" id="sc-file" class="filter-input" accept=".xlsx,.xls,.csv">
        </div>
        <div class="filter-group" style="min-width:220px;flex:1">
            <label>Note (optional)</label>
            <input type="text" id="sc-note" class="filter-input" maxlength="255" placeholder="e.g. Month-end full count">
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px">
        <button id="btn-save" class="btn btn-primary" onclick="saveCount()" disabled><i class="fa-solid fa-floppy-disk"></i> <span class="step-num" style="background:#fff;color:#000">4</span> Save to DB</button>
        <button id="btn-export" class="btn btn-success btn-sm" onclick="exportExcel()" disabled><i class="fa-solid fa-file-excel"></i> Export Excel</button>
        <button class="btn btn-secondary btn-sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        <button class="btn btn-secondary btn-sm" onclick="downloadTemplate()"><i class="fa-solid fa-download"></i> Download Import Template</button>
    </div>
    <div id="sc-status" class="alert-info" style="display:none"></div>
    <div id="sc-warn" class="alert-warn" style="display:none"></div>
</div>

<form id="save-form" method="POST" style="display:none">
    <input type="hidden" name="action" value="save_count">
    <input type="hidden" name="count_date" id="f-date">
    <input type="hidden" name="base" id="f-base">
    <input type="hidden" name="file_name" id="f-file">
    <input type="hidden" name="note" id="f-note">
    <input type="hidden" name="lines_json" id="f-lines">
</form>
<?php endif; ?>

<?php if ($page_mode === 'new' || $page_mode === 'view'): ?>
<!-- ══ RECONCILIATION (shared by New + View) ══ -->
<div id="rec-section" style="display:none">
    <div class="kpi-grid" id="rec-kpis"></div>

    <div class="content-card">
        <div class="filter-bar no-print" style="margin-bottom:14px">
            <div class="filter-group">
                <label>Show</label>
                <select id="rec-filter" class="filter-input" onchange="renderReport()">
                    <option value="all">All items</option>
                    <option value="short">Short only</option>
                    <option value="excess">Excess only</option>
                    <option value="match">Matched only</option>
                    <option value="notcounted">Not counted</option>
                    <option value="nosys">Not in system</option>
                </select>
            </div>
            <div class="filter-group" style="min-width:220px">
                <label>Item Code / Item Name</label>
                <input type="text" id="rec-search" class="filter-input" placeholder="Search item…" oninput="renderReport()">
            </div>
        </div>
        <div id="rec-table"></div>
        <div class="note-box" id="rec-note"></div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" defer></script>
<script>
const PAGE_MODE = <?php echo json_encode($page_mode); ?>;
const SELF_URL  = <?php echo json_encode($SELF); ?>;
const VIEW_ROWS = <?php echo json_encode($view_rows, $JSON_FLAGS); ?>;
const VIEW_DATE = <?php echo json_encode($view_head['count_date'] ?? null); ?>;
const VIEW_BASE = <?php echo json_encode($view_head['base_type'] ?? 'last_count'); ?>;
const BASE_LABELS = <?php echo json_encode($BASE_LABELS); ?>;

let SYS = null;        // {rows, master, existing, prev_count_date, base} for the chosen date + base
let UPLOAD = null;     // {fileName, map: Map(code -> {qty, sr, name}), lineCount, dupMerged, skipped[]}
let CUR_ROWS = [];     // rows currently on screen
let CUR_DATE = '';
let CUR_BASE = 'last_count';
let LOAD_TOKEN = 0;
let BASE_MSG = '';     // status text about the loaded system stock

const $id = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const round2 = n => Math.round((+n + Number.EPSILON) * 100) / 100;
function fmt(n) { if (n === null || n === undefined || n === '') return '—'; return Number(n).toLocaleString('en-US', {maximumFractionDigits: 2}); }
function fmtDiff(n) { n = Number(n); return n > 0 ? '+' + fmt(n) : (n < 0 ? '−' + fmt(Math.abs(n)) : '0'); }
function baseLabel(b) { return BASE_LABELS[b] || BASE_LABELS.last_count; }

function selectedBase() {
    const r = document.querySelector('input[name="sc-base"]:checked');
    return r ? r.value : 'last_count';
}

function rowStatus(r) {
    if (r.counted_units === null || r.counted_units === undefined) return 'notcounted';
    const d = Number(r.diff_units);
    return d < 0 ? 'short' : (d > 0 ? 'excess' : 'match');
}

/* ───────── New-count mode: load system stock, parse upload, merge ───────── */
function showStatus(html) {
    const box = $id('sc-status'); if (!box) return;
    box.style.display = html ? 'block' : 'none'; box.innerHTML = html || '';
}
function showWarn(html) {
    const box = $id('sc-warn'); if (!box) return;
    box.style.display = html ? 'block' : 'none'; box.innerHTML = html || '';
}

async function loadSystem() {
    const d = $id('sc-date').value;
    const base = selectedBase();
    document.querySelectorAll('.base-opt').forEach(el => el.classList.toggle('active', el.querySelector('input').checked));
    const token = ++LOAD_TOKEN;
    SYS = null; BASE_MSG = ''; rebuild();
    if (!d) { showStatus('Select the stock count date.'); return; }
    showStatus('<i class="fa-solid fa-spinner fa-spin"></i> Loading system stock (' + esc(baseLabel(base)) + ' base) as at ' + esc(d) + '…');
    try {
        const res = await fetch(SELF_URL + '?ajax=system&count_date=' + encodeURIComponent(d) + '&base=' + encodeURIComponent(base), {credentials: 'same-origin'});
        const j = await res.json();
        if (token !== LOAD_TOKEN) return;                 // a newer date / base was picked meanwhile
        if (!j.ok) throw new Error(j.error || 'Could not load system stock.');
        SYS = j;
    } catch (e) {
        if (token !== LOAD_TOKEN) return;
        showStatus('');
        showWarn('<strong>Could not load system stock:</strong> ' + esc(e.message));
        rebuild(); return;
    }
    showWarn('');
    let msg = 'System stock loaded as at <strong>' + esc(d) + '</strong> — base: <strong>' + esc(baseLabel(SYS.base)) + '</strong> (' + SYS.rows.length + ' items). ';
    if (SYS.base === 'current') {
        msg += 'Running balance of every purchase, return, sale and adjustment up to and including this date (same as the Current Stock Report).';
    } else {
        msg += SYS.prev_count_date
            ? 'Last uploaded stock count: <strong>' + esc(SYS.prev_count_date) + '</strong> — its item qty is the base (items with no qty in it start at 0, nothing is taken from older counts) and movements after it are added on top.'
            : 'No earlier saved stock count — Last Stock Count Units start at 0 and movements are taken from the beginning (same result as Current Stock).';
    }
    if (SYS.existing) {
        msg += '<br><strong>A stock count for this date is already saved</strong> (' + esc(baseLabel(SYS.existing.base_type)) + ' base, by ' + esc(SYS.existing.created_by || '—') +
               ' on ' + esc(SYS.existing.created_at) + ') — saving will replace it. ' +
               '<a href="' + SELF_URL + '?view=' + encodeURIComponent(SYS.existing.id) + '">View it</a>';
    }
    BASE_MSG = msg;
    rebuild();
}

function normCode(v) {
    if (typeof v === 'number') return String(v);
    return String(v ?? '').trim().replace(/\.0+$/, '');
}

function parseUpload(file) {
    const reader = new FileReader();
    reader.onload = e => {
        try {
            const wb = XLSX.read(new Uint8Array(e.target.result), {type: 'array'});
            const ws = wb.Sheets[wb.SheetNames[0]];
            const aoa = XLSX.utils.sheet_to_json(ws, {header: 1, defval: '', raw: true});

            let hr = -1; const col = {sr: -1, sku: -1, name: -1, qty: -1};
            for (let i = 0; i < Math.min(aoa.length, 25); i++) {
                const row = aoa[i].map(v => String(v).trim().toLowerCase());
                const sku = row.findIndex(v => /sku/.test(v) || v === 'item code' || v === 'itemcode');
                if (sku === -1) continue;
                hr = i; col.sku = sku;
                col.sr   = row.findIndex(v => /^sr/.test(v) || /^s\.?\s*no/.test(v) || v === '#');
                col.name = row.findIndex((v, ix) => ix !== sku && /product|item name|description|^name/.test(v));
                col.qty  = row.findIndex((v, ix) => ix !== sku && /count|qty|quantity|unit/.test(v));
                break;
            }
            if (hr === -1 || col.qty === -1) {
                throw new Error('Could not find the "SKU7" and "Stock Count Unit" column headings. Use the import format: Sr No | SKU7 | Product Name | Stock Count Unit (see Download Import Template).');
            }

            const map = new Map(); const skipped = []; let lines = 0, dup = 0;
            for (let i = hr + 1; i < aoa.length; i++) {
                const row = aoa[i];
                if (row.every(v => String(v).trim() === '')) continue;
                const code = normCode(row[col.sku]);
                const rawQty = row[col.qty];
                if (code === '') { skipped.push('Row ' + (i + 1) + ': blank SKU7'); continue; }
                const qty = typeof rawQty === 'number' ? rawQty : parseFloat(String(rawQty).replace(/,/g, ''));
                if (String(rawQty).trim() === '' || !isFinite(qty) || qty < 0) { skipped.push('Row ' + (i + 1) + ' (' + code + '): invalid Stock Count Unit "' + rawQty + '"'); continue; }
                lines++;
                const name = col.name > -1 ? String(row[col.name] ?? '').trim() : '';
                const sr = col.sr > -1 ? row[col.sr] : '';
                if (map.has(code)) { map.get(code).qty += qty; dup++; }
                else map.set(code, {qty: qty, sr: (sr === '' ? null : sr), name: name});
            }
            if (map.size === 0) throw new Error('No valid stock count lines were found in the file.');
            UPLOAD = {fileName: file.name, map: map, lineCount: lines, dupMerged: dup, skipped: skipped};
            rebuild();
        } catch (err) {
            UPLOAD = null;
            showWarn('<strong>Upload problem:</strong> ' + esc(err.message));
            rebuild();
        }
    };
    reader.readAsArrayBuffer(file);
}

function buildRows() {
    if (!SYS) return [];
    const map = new Map();
    SYS.rows.forEach(r => map.set(r.item_code, Object.assign({}, r, {counted_units: null, diff_units: null, sr_no: null})));
    if (UPLOAD) {
        UPLOAD.map.forEach((c, code) => {
            let r = map.get(code);
            if (!r) {
                const m = SYS.master[code];
                r = {item_code: code, item_name: m ? m.name : c.name, upc: m ? m.upc : 0, last_count_date: null,
                     last_count_units: 0, primary_units: 0, return_units: 0, secout_units: 0, adjustment_units: 0,
                     actual_units: 0, counted_units: null, diff_units: null, sr_no: null, in_system: m ? 1 : 0};
                map.set(code, r);
            }
            r.counted_units = round2(c.qty);
            r.diff_units = round2(c.qty - r.actual_units);
            r.sr_no = c.sr;
            if (!r.item_name) r.item_name = c.name;
        });
    }
    return [...map.values()].sort((a, b) => a.item_code < b.item_code ? -1 : (a.item_code > b.item_code ? 1 : 0));
}

function rebuild() {
    if (PAGE_MODE === 'view') { CUR_ROWS = VIEW_ROWS; CUR_DATE = VIEW_DATE; CUR_BASE = VIEW_BASE; renderReport(); return; }
    CUR_DATE = $id('sc-date').value;
    CUR_BASE = SYS ? SYS.base : selectedBase();
    CUR_ROWS = buildRows();

    // upload summary / warnings
    let warn = '';
    if (UPLOAD && SYS) {
        let notInSys = [];
        UPLOAD.map.forEach((c, code) => { const r = CUR_ROWS.find(x => x.item_code === code); if (r && +r.in_system === 0) notInSys.push(code); });
        let info = '<strong>' + esc(UPLOAD.fileName) + '</strong>: ' + UPLOAD.map.size + ' SKUs read';
        if (UPLOAD.dupMerged) info += ', ' + UPLOAD.dupMerged + ' duplicate SKU line(s) added together';
        const uncounted = CUR_ROWS.filter(r => rowStatus(r) === 'notcounted').length;
        if (uncounted) info += ', ' + uncounted + ' system item(s) not in the file';
        showStatus(BASE_MSG + '<br>' + info + '.');
        if (notInSys.length) warn += 'SKU7 not found in the system (no purchase / sales history): <strong>' + notInSys.slice(0, 15).map(esc).join(', ') + '</strong>' + (notInSys.length > 15 ? ' … +' + (notInSys.length - 15) + ' more' : '') + '. They are still saved, shown as excess against 0 system stock.<br>';
        if (UPLOAD.skipped.length) warn += 'Skipped ' + UPLOAD.skipped.length + ' line(s): ' + UPLOAD.skipped.slice(0, 5).map(esc).join('; ') + (UPLOAD.skipped.length > 5 ? ' …' : '');
        showWarn(warn);
    } else if (SYS && !UPLOAD) {
        showStatus(BASE_MSG + '<br>Now upload the stock count Excel.');
    }
    $id('btn-save').disabled = !(SYS && UPLOAD && CUR_DATE);
    $id('btn-export').disabled = !(SYS && CUR_ROWS.length);
    renderReport();
}

/* ───────── Shared: KPI cards + table ───────── */
function renderReport() {
    const sec = $id('rec-section'); if (!sec) return;
    const rows = CUR_ROWS;
    if (!rows.length) { sec.style.display = 'none'; return; }
    sec.style.display = 'block';
    const isCurrent = CUR_BASE === 'current';

    let counted = 0, match = 0, shortN = 0, excessN = 0, shortU = 0, excessU = 0, notC = 0, noSys = 0;
    rows.forEach(r => {
        const st = rowStatus(r);
        if (st === 'notcounted') notC++;
        else { counted++; if (st === 'short') { shortN++; shortU += Number(r.diff_units); } else if (st === 'excess') { excessN++; excessU += Number(r.diff_units); } else match++; }
        if (+r.in_system === 0) noSys++;
    });
    const net = shortU + excessU;
    let k = '';
    k += '<div class="kpi-card kpi-gray"><div class="kpi-label">Base</div><div class="kpi-value" style="font-size:15px">' + esc(baseLabel(CUR_BASE)) + '</div><div class="kpi-sub">as at ' + esc(CUR_DATE) + '</div></div>';
    k += '<div class="kpi-card kpi-blue"><div class="kpi-label">Items Counted</div><div class="kpi-value">' + fmt(counted) + '</div><div class="kpi-sub">of ' + fmt(rows.length) + ' items</div></div>';
    k += '<div class="kpi-card kpi-indigo"><div class="kpi-label">Matched</div><div class="kpi-value">' + fmt(match) + '</div></div>';
    k += '<div class="kpi-card kpi-red"><div class="kpi-label">Short</div><div class="kpi-value">' + fmt(shortN) + ' item(s)</div><div class="kpi-sub">' + fmtDiff(shortU) + ' units</div></div>';
    k += '<div class="kpi-card kpi-green"><div class="kpi-label">Excess</div><div class="kpi-value">' + fmt(excessN) + ' item(s)</div><div class="kpi-sub">' + fmtDiff(excessU) + ' units</div></div>';
    k += '<div class="kpi-card ' + (net < 0 ? 'kpi-red' : (net > 0 ? 'kpi-green' : 'kpi-gray')) + '"><div class="kpi-label">Net Dif Units</div><div class="kpi-value">' + fmtDiff(net) + '</div><div class="kpi-sub">stock count ' + esc(CUR_DATE) + '</div></div>';
    k += '<div class="kpi-card kpi-orange"><div class="kpi-label">Not Counted</div><div class="kpi-value">' + fmt(notC) + '</div><div class="kpi-sub">system items not in the count</div></div>';
    if (noSys) k += '<div class="kpi-card kpi-gray"><div class="kpi-label">Not in System</div><div class="kpi-value">' + fmt(noSys) + '</div><div class="kpi-sub">SKU7 unknown</div></div>';
    $id('rec-kpis').innerHTML = k;

    const f = $id('rec-filter').value, q = $id('rec-search').value.trim().toLowerCase();
    const shown = rows.filter(r => {
        const st = rowStatus(r);
        if (f === 'short' && st !== 'short') return false;
        if (f === 'excess' && st !== 'excess') return false;
        if (f === 'match' && st !== 'match') return false;
        if (f === 'notcounted' && st !== 'notcounted') return false;
        if (f === 'nosys' && +r.in_system !== 0) return false;
        if (q && !(String(r.item_code).toLowerCase().includes(q) || String(r.item_name || '').toLowerCase().includes(q))) return false;
        return true;
    });

    const T = {last: 0, pri: 0, ret: 0, sec: 0, adj: 0, act: 0, cnt: 0, dif: 0};
    let body = '';
    shown.forEach(r => {
        const st = rowStatus(r);
        T.last += +r.last_count_units; T.pri += +r.primary_units; T.ret += +r.return_units; T.sec += +r.secout_units;
        T.adj += +r.adjustment_units; T.act += +r.actual_units;
        if (st !== 'notcounted') { T.cnt += +r.counted_units; T.dif += +r.diff_units; }
        const cls = st === 'short' ? 'row-short' : (st === 'excess' ? 'row-excess' : '');
        const pill = st === 'short' ? '<span class="pill pill-short">Short</span>' : st === 'excess' ? '<span class="pill pill-excess">Excess</span>'
                   : st === 'match' ? '<span class="pill pill-match">Matched</span>' : '<span class="pill pill-notc">Not counted</span>';
        const flag = +r.in_system === 0 ? '<span class="pill pill-nosys">Not in system</span>' : '';
        const lastSub = isCurrent ? 'current stock base' : (r.last_count_date ? esc(r.last_count_date) : 'no earlier count');
        body += '<tr class="' + cls + '">'
            + '<td class="left"><span class="code-pill">' + esc(r.item_code) + '</span></td>'
            + '<td class="left">' + esc(r.item_name || '—') + '</td>'
            + '<td>' + (+r.upc ? fmt(r.upc) : '—') + '</td>'
            + '<td>' + (isCurrent ? '—' : fmt(r.last_count_units)) + '<span class="sub-txt">' + lastSub + '</span></td>'
            + '<td>' + fmt(r.primary_units) + '</td><td>' + fmt(r.return_units) + '</td><td>' + fmt(r.secout_units) + '</td><td>' + fmt(r.adjustment_units) + '</td>'
            + '<td style="font-weight:700" class="' + (+r.actual_units < 0 ? 'txt-red' : '') + '">' + fmt(r.actual_units) + '</td>'
            + '<td style="font-weight:700">' + (st === 'notcounted' ? '—' : fmt(r.counted_units)) + '</td>'
            + '<td class="' + (st === 'short' ? 'txt-red' : (st === 'excess' ? 'txt-green' : '')) + '" style="font-weight:700">' + (st === 'notcounted' ? '—' : fmtDiff(r.diff_units)) + '</td>'
            + '<td>' + pill + flag + '</td></tr>';
    });

    const filtered = shown.length !== rows.length;
    const actualHead = isCurrent ? 'Actual Qty Units<br><span style="font-weight:400;text-transform:none">(current stock)</span>' : 'Actual Qty Units';
    $id('rec-table').innerHTML = shown.length ? (
        '<div class="table-responsive"><table class="report-table"><thead><tr>'
        + '<th style="width:100px">Item Code</th><th style="min-width:230px">Item Name</th><th style="width:60px">UPC</th>'
        + '<th style="min-width:130px">Last Stock<br>Count Units</th><th>Primary Qty</th><th>Primary Returns</th><th>Secondary Out</th><th>Adjustment Qty</th>'
        + '<th>' + actualHead + '</th><th style="min-width:140px">New Stock count base<br>on selected date</th>'
        + '<th style="min-width:110px">Dif Units<br><span style="font-weight:400;text-transform:none">(− short / + excess)</span></th><th>Status</th>'
        + '</tr></thead><tbody>' + body + '</tbody><tfoot><tr>'
        + '<td colspan="3" class="left">TOTAL' + (filtered ? ' (filtered view)' : '') + ' — ' + fmt(shown.length) + ' item(s)</td>'
        + '<td>' + (isCurrent ? '—' : fmt(T.last)) + '</td><td>' + fmt(T.pri) + '</td><td>' + fmt(T.ret) + '</td><td>' + fmt(T.sec) + '</td><td>' + fmt(T.adj) + '</td>'
        + '<td>' + fmt(T.act) + '</td><td>' + fmt(T.cnt) + '</td>'
        + '<td class="' + (T.dif < 0 ? 'txt-red' : (T.dif > 0 ? 'txt-green' : '')) + '">' + fmtDiff(T.dif) + '</td><td>—</td></tr></tfoot></table></div>'
    ) : '<div style="text-align:center;padding:30px;color:#9ca3af">No items match this filter.</div>';

    const note = $id('rec-note');
    if (note) {
        note.innerHTML = (isCurrent
            ? '<strong>Base: Current Stock</strong> — system stock is the running balance of every record on file up to and including the stock count date, exactly like the Current Stock Report. Last Stock Count is not used (0).<br>'
              + '<strong>Primary Qty, Primary Returns, Secondary Out, Adjustment Qty</strong> — all records from the beginning up to and including the stock count date.<br>'
            : '<strong>Base: Last Stock Count</strong> — <strong>Last Stock Count Units</strong> is the item\'s physical count from its most recent saved stock count before this date. Items with no earlier count start at 0 and take all movements from the beginning.<br>'
              + '<strong>Primary Qty, Primary Returns, Secondary Out, Adjustment Qty</strong> — records dated after that last count, up to and including the stock count date.<br>')
            + 'Primary Qty / Returns are converted from cases to units using UPC.<br>'
            + '<strong>Actual Qty Units</strong> = Last Stock Count + Primary Qty − Primary Returns − Secondary Out + Adjustment Qty.<br>'
            + '<strong>Dif Units</strong> = New Stock count − Actual Qty Units. <span class="txt-red">Minus = short</span>, <span class="txt-green">plus = excess</span>. Items missing from the count file show as "Not counted" and are left out of the short / excess totals.';
    }
}

/* ───────── Save ───────── */
function saveCount() {
    if (!SYS || !UPLOAD) return;
    const d = $id('sc-date').value;
    if (!d) { alert('Select the stock count date first.'); return; }
    let msg = 'Save the stock count for ' + d + ' (' + UPLOAD.map.size + ' SKUs) — base: ' + baseLabel(SYS.base) + '?';
    if (SYS.existing) msg = 'A stock count for ' + d + ' is already saved.\n\nSaving will REPLACE it with this upload (base: ' + baseLabel(SYS.base) + '). Continue?';
    if (!confirm(msg)) return;
    const lines = [];
    UPLOAD.map.forEach((c, code) => lines.push({sr: c.sr, code: code, name: c.name, qty: c.qty}));
    $id('f-date').value = d;
    $id('f-base').value = SYS.base;
    $id('f-file').value = UPLOAD.fileName;
    $id('f-note').value = $id('sc-note').value;
    $id('f-lines').value = JSON.stringify(lines);
    $id('btn-save').disabled = true;
    $id('save-form').submit();
}

/* ───────── Excel export — same layout as srtock_count_reconc.xlsx ───────── */
function exportExcel() {
    if (typeof XLSX === 'undefined') { alert('Excel library still loading, please wait a moment.'); return; }
    if (!CUR_ROWS.length) return;
    const headers = ['Item Code','Item Name','UPC','Last Stock Count Units','Primary Qty','Primary Returns','Secondary Out',
                     'Adjustment Qty','Actual Qty Units','New Stock count base on selected date','Dif Units'];
    const data = [['Stock Count Date', CUR_DATE, 'Base', baseLabel(CUR_BASE)], [], headers];
    CUR_ROWS.forEach(r => {
        data.push([r.item_code, r.item_name || '', +r.upc, +r.last_count_units, +r.primary_units, +r.return_units,
                   +r.secout_units, +r.adjustment_units, +r.actual_units,
                   rowStatus(r) === 'notcounted' ? null : +r.counted_units, null]);
    });
    const first = 4, last = 3 + CUR_ROWS.length, tot = last + 1;
    const ws = XLSX.utils.aoa_to_sheet(data);

    CUR_ROWS.forEach((r, i) => {
        const x = first + i, counted = rowStatus(r) !== 'notcounted';
        ws['I' + x] = {t: 'n', f: `D${x}+E${x}-F${x}-G${x}+H${x}`, v: +r.actual_units};
        ws['K' + x] = counted ? {t: 'n', f: `IF(ISNUMBER(J${x}),J${x}-I${x},"")`, v: +r.diff_units}
                              : {t: 's', f: `IF(ISNUMBER(J${x}),J${x}-I${x},"")`, v: ''};
    });

    // Totals + short / excess summary
    const sumCell = (c, v) => ({t: 'n', f: `SUM(${c}${first}:${c}${last})`, v: v});
    let sLast = 0, sPri = 0, sRet = 0, sSec = 0, sAdj = 0, sAct = 0, sCnt = 0, sShort = 0, sExcess = 0;
    CUR_ROWS.forEach(r => {
        sLast += +r.last_count_units; sPri += +r.primary_units; sRet += +r.return_units; sSec += +r.secout_units;
        sAdj += +r.adjustment_units; sAct += +r.actual_units;
        if (rowStatus(r) !== 'notcounted') { sCnt += +r.counted_units; if (+r.diff_units < 0) sShort += +r.diff_units; else sExcess += +r.diff_units; }
    });
    ws['A' + tot] = {t: 's', v: 'TOTAL'};
    ws['D' + tot] = sumCell('D', sLast); ws['E' + tot] = sumCell('E', sPri); ws['F' + tot] = sumCell('F', sRet);
    ws['G' + tot] = sumCell('G', sSec);  ws['H' + tot] = sumCell('H', sAdj); ws['I' + tot] = sumCell('I', sAct);
    ws['J' + tot] = sumCell('J', sCnt);  ws['K' + tot] = sumCell('K', sShort + sExcess);
    ws['J' + (tot + 1)] = {t: 's', v: 'Total Short'};
    ws['K' + (tot + 1)] = {t: 'n', f: `SUMIF(K${first}:K${last},"<0")`, v: sShort};
    ws['J' + (tot + 2)] = {t: 's', v: 'Total Excess'};
    ws['K' + (tot + 2)] = {t: 'n', f: `SUMIF(K${first}:K${last},">0")`, v: sExcess};
    ws['!ref'] = `A1:K${tot + 2}`;

    ws['!cols'] = [{wch: 19.5}, {wch: 48}, {wch: 8}, {wch: 24}, {wch: 14}, {wch: 16}, {wch: 14}, {wch: 16}, {wch: 18}, {wch: 36}, {wch: 14}];
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Stock Count Reconciliation');
    const tag = CUR_BASE === 'current' ? 'CurrentStock' : 'LastCount';
    XLSX.writeFile(wb, 'Sampath_Stock_Count_Reconciliation_' + CUR_DATE + '_' + tag + '.xlsx');
}

/* ───────── Import template ───────── */
function downloadTemplate() {
    if (typeof XLSX === 'undefined') { alert('Excel library still loading, please wait a moment.'); return; }
    const ws = XLSX.utils.aoa_to_sheet([
        ['Sr No', 'SKU7', 'Product Name', 'Stock Count Unit'],
        [1, '3420311', 'SURFE MATICFRNTL 1.0LX12', 14],
        [2, '3443305', 'AXE GOLDTEMPTRK135MLX12', 18],
    ]);
    ws['!cols'] = [{wch: 8}, {wch: 14}, {wch: 44}, {wch: 18}];
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Stock Count');
    XLSX.writeFile(wb, 'Stock_Count_Import_Template.xlsx');
}

/* ───────── Init ───────── */
document.addEventListener('DOMContentLoaded', function () {
    if (PAGE_MODE === 'view') { rebuild(); return; }
    if (PAGE_MODE !== 'new') return;
    $id('sc-date').addEventListener('change', loadSystem);
    document.querySelectorAll('input[name="sc-base"]').forEach(r => r.addEventListener('change', loadSystem));
    $id('sc-file').addEventListener('change', function () {
        const f = this.files && this.files[0];
        if (!f) { UPLOAD = null; rebuild(); return; }
        showWarn('');
        if (typeof XLSX === 'undefined') { showWarn('Excel library still loading — please pick the file again in a moment.'); this.value = ''; return; }
        parseUpload(f);
    });
    loadSystem();
});
</script>

<?php
ob_end_flush();
include 'footer.php';