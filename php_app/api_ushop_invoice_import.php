<?php
/* ═══════════════════════════════════════════════════
   API: UShop Invoice Import — Save Parsed Data
   Receives JSON from ushop_invoice_import.php (doImport())
   action = 'import_invoices'

   NEW: import_stock_out flag —
   When 1, every invoice item is written to ushop_stock_history
   as txn_type = 'STOCK_OUT' (deducting available stock, since
   availability = IN − OUT), with full details:
     product_code, unilever_code, product_name,
     qty, mrp (invoice price), cost_price (from items master),
     total_mrp (line amount), total_cost (qty × cost),
     txn_date (invoice date), reference (invoice doc no),
     invoice_id + invoice_import_id links.
═══════════════════════════════════════════════════ */

ob_start();

error_reporting(E_ALL);
ini_set('display_errors', '0');

include 'config.php';

function send_json($data) {
    if (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Server error: ' . $error['message'] . ' (line ' . $error['line'] . ')'
        ]);
    }
});

/* ─────────────────────────────────────────────────
   MIGRATE ushop_stock_history for invoice STOCK_OUT
   The table was created by the items import with:
     import_id INT NOT NULL + FK → ushop_items_imports
   Invoice imports live in a DIFFERENT table
   (ushop_invoice_imports), so we:
     1. Make import_id nullable (FK still valid — NULLs allowed)
     2. Add invoice_import_id + invoice_id link columns
        with FK → ushop_invoice_imports / ushop_invoices
        ON DELETE CASCADE (deleting an invoice import
        removes its STOCK_OUT rows → stock is restored)
   All migrations are idempotent (safe to run every call).
───────────────────────────────────────────────── */
function migrate_stock_history($conn) {
    /* Does the table exist at all? (items import page creates it) */
    $t = mysqli_query($conn, "SHOW TABLES LIKE 'ushop_stock_history'");
    if (!$t || mysqli_num_rows($t) === 0) {
        throw new Exception('Table ushop_stock_history does not exist. Run the Items Import page once first.');
    }

    /* 1. import_id → nullable */
    $c = mysqli_fetch_assoc(mysqli_query($conn, "SHOW COLUMNS FROM ushop_stock_history LIKE 'import_id'"));
    if ($c && strtoupper($c['Null']) === 'NO') {
        mysqli_query($conn, "ALTER TABLE ushop_stock_history MODIFY import_id INT(11) NULL");
    }

    /* 2. invoice_import_id column */
    $c = mysqli_query($conn, "SHOW COLUMNS FROM ushop_stock_history LIKE 'invoice_import_id'");
    if ($c && mysqli_num_rows($c) === 0) {
        mysqli_query($conn, "ALTER TABLE ushop_stock_history
            ADD COLUMN invoice_import_id INT(11) NULL AFTER import_id,
            ADD KEY idx_invoice_import_id (invoice_import_id)");
        /* FK so deleting an invoice import auto-removes its stock rows */
        mysqli_query($conn, "ALTER TABLE ushop_stock_history
            ADD CONSTRAINT fk_ssh_inv_import FOREIGN KEY (invoice_import_id)
            REFERENCES ushop_invoice_imports(id) ON DELETE CASCADE");
    }

    /* 3. invoice_id column */
    $c = mysqli_query($conn, "SHOW COLUMNS FROM ushop_stock_history LIKE 'invoice_id'");
    if ($c && mysqli_num_rows($c) === 0) {
        mysqli_query($conn, "ALTER TABLE ushop_stock_history
            ADD COLUMN invoice_id INT(11) NULL AFTER invoice_import_id,
            ADD KEY idx_invoice_id (invoice_id)");
        mysqli_query($conn, "ALTER TABLE ushop_stock_history
            ADD CONSTRAINT fk_ssh_invoice FOREIGN KEY (invoice_id)
            REFERENCES ushop_invoices(id) ON DELETE CASCADE");
    }

    /* 4. Make sure the ENUM contains STOCK_OUT (it does in the original
          schema, but guard against older variants) */
    $c = mysqli_fetch_assoc(mysqli_query($conn, "SHOW COLUMNS FROM ushop_stock_history LIKE 'txn_type'"));
    if ($c && stripos($c['Type'], 'STOCK_OUT') === false) {
        mysqli_query($conn, "ALTER TABLE ushop_stock_history
            MODIFY txn_type ENUM('OPENING','STOCK_IN','STOCK_OUT','ADJUSTMENT') NOT NULL DEFAULT 'OPENING'");
    }

    /* 5. stock_updated flag on ushop_invoice_imports */
    $c = mysqli_query($conn, "SHOW COLUMNS FROM ushop_invoice_imports LIKE 'stock_updated'");
    if ($c && mysqli_num_rows($c) === 0) {
        mysqli_query($conn, "ALTER TABLE ushop_invoice_imports
            ADD COLUMN stock_updated TINYINT(1) DEFAULT 0 AFTER total_amount");
    }
}

/* ─────────────────────────────────────────────────
   Read & validate JSON input
───────────────────────────────────────────────── */
$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input)) {
    send_json(['success' => false, 'message' => 'Invalid JSON received by server.']);
}

$action = isset($input['action']) ? $input['action'] : '';
if ($action !== 'import_invoices') {
    send_json(['success' => false, 'message' => 'Unknown or missing action.']);
}

$filename       = isset($input['filename'])         ? trim((string)$input['filename'])    : '';
$note           = isset($input['note'])             ? trim((string)$input['note'])        : '';
$importDate     = isset($input['import_date'])      ? trim((string)$input['import_date']) : '';
$dateFrom       = isset($input['date_from'])        ? trim((string)$input['date_from'])   : '';
$dateTo         = isset($input['date_to'])          ? trim((string)$input['date_to'])     : '';
$importStockOut = !empty($input['import_stock_out']);   /* ← NEW flag from checkbox */
$invoices       = (isset($input['invoices']) && is_array($input['invoices'])) ? $input['invoices'] : [];

if ($importDate === '') $importDate = date('Y-m-d');
if ($dateFrom === '')   $dateFrom   = null;
if ($dateTo === '')     $dateTo     = null;
if ($filename === '')   $filename   = 'unknown.xlsx';

if (empty($invoices)) {
    send_json(['success' => false, 'message' => 'No invoices found in the uploaded data.']);
}

/* ─────────────────────────────────────────────────
   Pre-compute import-level totals
───────────────────────────────────────────────── */
$totalInvoices = count($invoices);
$totalItems    = 0;
$totalAmount   = 0;
foreach ($invoices as $inv) {
    if (isset($inv['items']) && is_array($inv['items'])) {
        $totalItems += count($inv['items']);
    }
    $totalAmount += isset($inv['total_amount']) ? (float)$inv['total_amount'] : 0;
}

/* ─────────────────────────────────────────────────
   Preload items master details for all product codes
   (cost_price + unilever_code fallback) in ONE query
───────────────────────────────────────────────── */
$masterInfo = [];   /* product_code => ['cost' => float|null, 'ucode' => string|null] */
if ($importStockOut) {
    $allCodes = [];
    foreach ($invoices as $inv) {
        if (!empty($inv['items']) && is_array($inv['items'])) {
            foreach ($inv['items'] as $it) {
                $pc = isset($it['product_code']) ? trim((string)$it['product_code']) : '';
                if ($pc !== '') $allCodes[$pc] = true;
            }
        }
    }
    if ($allCodes) {
        $esc = array_map(fn($c) => "'" . mysqli_real_escape_string($conn, $c) . "'", array_keys($allCodes));
        $res = mysqli_query($conn, "SELECT product_code, cost_price, unilever_code
                                    FROM ushop_items
                                    WHERE product_code IN (" . implode(',', $esc) . ")");
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $masterInfo[$r['product_code']] = [
                    'cost'  => ($r['cost_price'] !== null ? (float)$r['cost_price'] : null),
                    'ucode' => ($r['unilever_code'] !== null && $r['unilever_code'] !== '' ? $r['unilever_code'] : null),
                ];
            }
        }
    }
}

/* ─────────────────────────────────────────────────
   0. Ensure stock history table supports invoice
      STOCK_OUT rows — must run BEFORE the transaction
      (ALTER TABLE causes an implicit commit in MySQL)
───────────────────────────────────────────────── */
if ($importStockOut) {
    try {
        migrate_stock_history($conn);
    } catch (Throwable $e) {
        send_json(['success' => false, 'message' => $e->getMessage()]);
    }
}

/* ─────────────────────────────────────────────────
   Save everything inside a transaction
───────────────────────────────────────────────── */
mysqli_begin_transaction($conn);

try {

    /* 1. Import header row */
    $stockUpdatedFlag = $importStockOut ? 1 : 0;
    $stmtImport = mysqli_prepare($conn, "
        INSERT INTO ushop_invoice_imports
          (import_date, filename, note, date_from, date_to, total_invoices, total_items, total_amount, stock_updated)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmtImport) throw new Exception('Prepare failed (imports): ' . mysqli_error($conn));
    mysqli_stmt_bind_param(
        $stmtImport, 'sssssiidi',
        $importDate, $filename, $note, $dateFrom, $dateTo, $totalInvoices, $totalItems, $totalAmount, $stockUpdatedFlag
    );
    if (!mysqli_stmt_execute($stmtImport)) throw new Exception('Insert failed (imports): ' . mysqli_stmt_error($stmtImport));
    $importId = mysqli_insert_id($conn);
    mysqli_stmt_close($stmtImport);

    /* Prepared statements reused for every invoice / item / payment */
    $stmtInv = mysqli_prepare($conn, "
        INSERT INTO ushop_invoices
          (import_id, doc_no, unique_inv_no, invoice_date, customer_name, customer_code,
           cashier, total_qty, total_discount, total_amount)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmtInv) throw new Exception('Prepare failed (invoices): ' . mysqli_error($conn));

    $stmtItem = mysqli_prepare($conn, "
        INSERT INTO ushop_invoice_items
          (invoice_id, import_id, product_code, barcode, ref_code, product_name,
           unilever_code, price, qty, discount, amount)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmtItem) throw new Exception('Prepare failed (items): ' . mysqli_error($conn));

    $stmtPay = mysqli_prepare($conn, "
        INSERT INTO ushop_invoice_payments
          (invoice_id, import_id, pay_type, amount)
        VALUES (?, ?, ?, ?)
    ");
    if (!$stmtPay) throw new Exception('Prepare failed (payments): ' . mysqli_error($conn));

    /* STOCK_OUT insert — import_id stays NULL (that FK belongs to the
       ITEMS import table); linkage is via invoice_import_id + invoice_id.
       Details saved: unilever_code, product_name, qty, mrp (=invoice
       selling price), cost_price (items master), total_mrp (=line amount),
       total_cost (qty × cost), txn_date (=invoice date), reference, note. */
    $stmtStock = null;
    if ($importStockOut) {
        $stmtStock = mysqli_prepare($conn, "
            INSERT INTO ushop_stock_history
              (import_id, invoice_import_id, invoice_id, product_code, unilever_code, product_name,
               txn_type, qty, mrp, cost_price, total_mrp, total_cost, avg_cost,
               txn_date, reference, note)
            VALUES (NULL, ?, ?, ?, ?, ?, 'STOCK_OUT', ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmtStock) throw new Exception('Prepare failed (stock history): ' . mysqli_error($conn) .
            ' — check that ushop_stock_history has invoice_import_id / invoice_id columns.');
    }

    $itemCount = 0;
    $stockRows = 0;

    foreach ($invoices as $inv) {

        $docNo     = isset($inv['doc_no'])          ? (string)$inv['doc_no']         : '';
        $uniqInvNo = isset($inv['unique_inv_no'])   ? (string)$inv['unique_inv_no']  : '';
        $invDate   = (!empty($inv['invoice_date'])) ? (string)$inv['invoice_date']   : null;
        $custName  = isset($inv['customer_name'])   ? (string)$inv['customer_name']  : '';
        $custCode  = isset($inv['customer_code'])   ? (string)$inv['customer_code']  : '';
        $cashier   = isset($inv['cashier'])         ? (string)$inv['cashier']        : '';
        $totQty    = isset($inv['total_qty'])       ? (float)$inv['total_qty']       : 0;
        $totDisc   = isset($inv['total_discount'])  ? (float)$inv['total_discount']  : 0;
        $totAmt    = isset($inv['total_amount'])    ? (float)$inv['total_amount']    : 0;

        mysqli_stmt_bind_param(
            $stmtInv, 'issssssddd',
            $importId, $docNo, $uniqInvNo, $invDate, $custName, $custCode, $cashier, $totQty, $totDisc, $totAmt
        );
        if (!mysqli_stmt_execute($stmtInv)) throw new Exception('Insert failed (invoices): ' . mysqli_stmt_error($stmtInv));
        $invoiceId = mysqli_insert_id($conn);

        /* Reference text used on stock history rows for this invoice */
        $stockRef  = 'Invoice: ' . ($docNo !== '' ? $docNo : ('#' . $invoiceId))
                   . ($uniqInvNo !== '' ? ' / ' . $uniqInvNo : '');
        $stockNote = 'POS Sales STOCK OUT'
                   . ($custName !== '' ? ' — ' . $custName : '')
                   . ($note !== '' ? ' — ' . $note : '');
        /* txn_date: invoice date, fallback import date */
        $txnDate = $invDate !== null ? $invDate : $importDate;

        /* ── Items ── */
        $items = (isset($inv['items']) && is_array($inv['items'])) ? $inv['items'] : [];
        foreach ($items as $it) {
            $prodCode = isset($it['product_code'])  ? (string)$it['product_code']  : '';
            $barcode  = isset($it['barcode'])       ? (string)$it['barcode']       : '';
            $refCode  = isset($it['ref_code'])      ? (string)$it['ref_code']      : '';
            $prodName = isset($it['product_name'])  ? (string)$it['product_name']  : '';
            $ulvrCode = isset($it['unilever_code']) ? (string)$it['unilever_code'] : '';
            $price    = isset($it['price'])         ? (float)$it['price']          : 0;
            $qty      = isset($it['qty'])           ? (float)$it['qty']            : 0;
            $disc     = isset($it['discount'])      ? (float)$it['discount']       : 0;
            $amount   = isset($it['amount'])        ? (float)$it['amount']         : 0;

            mysqli_stmt_bind_param(
                $stmtItem, 'iisssssdddd',
                $invoiceId, $importId, $prodCode, $barcode, $refCode, $prodName, $ulvrCode, $price, $qty, $disc, $amount
            );
            if (!mysqli_stmt_execute($stmtItem)) throw new Exception('Insert failed (items): ' . mysqli_stmt_error($stmtItem));
            $itemCount++;

            /* ── STOCK_OUT entry for this item ── */
            if ($importStockOut && $stmtStock && $prodCode !== '' && $qty > 0) {

                /* unilever code: prefer parsed from invoice, fallback to items master */
                $shUcode = $ulvrCode !== '' ? $ulvrCode
                         : ($masterInfo[$prodCode]['ucode'] ?? null);

                /* cost price from items master (null if unknown) */
                $shCost = $masterInfo[$prodCode]['cost'] ?? null;

                $shMrp       = $price > 0 ? $price : null;          /* invoice selling price   */
                $shTotalMrp  = $amount != 0 ? $amount : null;       /* line net amount         */
                $shTotalCost = ($shCost !== null) ? round($qty * $shCost, 4) : null;
                $shAvgCost   = $shCost;                              /* avg cost = master cost  */

                mysqli_stmt_bind_param(
                    $stmtStock, 'iisssddddddsss',
                    $importId, $invoiceId, $prodCode, $shUcode, $prodName,
                    $qty, $shMrp, $shCost, $shTotalMrp, $shTotalCost, $shAvgCost,
                    $txnDate, $stockRef, $stockNote
                );
                if (!mysqli_stmt_execute($stmtStock)) {
                    throw new Exception('Insert failed (stock history): ' . mysqli_stmt_error($stmtStock));
                }
                $stockRows++;
            }
        }

        /* ── Payments ── */
        $payments = (isset($inv['payments']) && is_array($inv['payments'])) ? $inv['payments'] : [];
        foreach ($payments as $p) {
            $payType = isset($p['pay_type']) ? (string)$p['pay_type'] : '';
            $payAmt  = isset($p['amount'])   ? (float)$p['amount']    : 0;

            mysqli_stmt_bind_param($stmtPay, 'iisd', $invoiceId, $importId, $payType, $payAmt);
            if (!mysqli_stmt_execute($stmtPay)) throw new Exception('Insert failed (payments): ' . mysqli_stmt_error($stmtPay));
        }
    }

    mysqli_stmt_close($stmtInv);
    mysqli_stmt_close($stmtItem);
    mysqli_stmt_close($stmtPay);
    if ($stmtStock) mysqli_stmt_close($stmtStock);

    mysqli_commit($conn);

    send_json([
        'success'    => true,
        'import_id'  => $importId,
        'invoices'   => $totalInvoices,
        'items'      => $itemCount,
        'stock_rows' => $stockRows,
        'stock_out'  => $importStockOut,
    ]);

} catch (Throwable $e) {
    mysqli_rollback($conn);
    send_json(['success' => false, 'message' => $e->getMessage()]);
}