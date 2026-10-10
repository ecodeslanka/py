<?php
/**
 * ushop_invoice_reconciliation_visa.php
 * UShop Invoice Reconciliation - Visa Card vs BOC Bank Statement Matching
 */

ob_start();
include 'config.php';

/* Visa merchant discount rate (bank charge) deducted from invoice amount
   before comparing/tallying against the bank statement credit. */
define('VISA_MDR_RATE', 0.025); // 2.5%

/* ══════════════════════════════════════════════════════════
   AUTO-CREATE RECONCILIATION TABLE
══════════════════════════════════════════════════════════ */
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `ushop_invoice_reconciliations` (
        `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `invoice_id`            INT UNSIGNED NOT NULL,
        `bst_id`                INT UNSIGNED NOT NULL,
        `recon_date`            DATE NOT NULL,
        `invoice_amount`        DECIMAL(15,2) NOT NULL,
        `bank_credit_amount`    DECIMAL(15,2) NOT NULL,
        `bank_description`      TEXT NULL,
        `bank_transaction_date` DATE NULL,
        `reconciled_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_invoice` (`invoice_id`),
        KEY `idx_bst` (`bst_id`),
        KEY `idx_recon_date` (`recon_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

/* ══════════════════════════════════════════════════════════
   HELPER — Clean JSON output with proper headers
══════════════════════════════════════════════════════════ */
function jsonDie($payload) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ══════════════════════════════════════════════════════════
   AJAX — SAVE RECONCILIATION  (POST action=save_invoice_recon)
══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_invoice_recon') {
    $invoice_id = intval($_POST['invoice_id'] ?? 0);
    $bst_id     = intval($_POST['bst_id'] ?? 0);
    $inv_amount = floatval($_POST['inv_amount'] ?? 0); // actual amount (post 2.5% charge)

    /* Validation */
    if (!$invoice_id || !$bst_id || $inv_amount <= 0) {
        jsonDie(['ok' => false, 'msg' => 'Invalid invoice ID, bank transaction ID, or amount.']);
    }

    /* Verify invoice exists */
    $inv_check = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM ushop_invoices WHERE id = $invoice_id LIMIT 1"));
    if (!$inv_check) {
        jsonDie(['ok' => false, 'msg' => "Invoice #$invoice_id not found."]);
    }

    /* Get bank transaction details */
    $btr = mysqli_query($conn, "
        SELECT bst.id, bst.credit, bst.transaction_date, bst.description
        FROM bank_statement_transactions bst
        WHERE bst.id = $bst_id LIMIT 1
    ");
    $bt = $btr ? mysqli_fetch_assoc($btr) : null;
    
    if (!$bt) {
        jsonDie(['ok' => false, 'msg' => "Bank transaction #$bst_id not found."]);
    }

    $bank_credit = floatval($bt['credit']);
    $bank_date   = $bt['transaction_date'] ? mysqli_real_escape_string($conn, $bt['transaction_date']) : null;
    $bank_desc   = mysqli_real_escape_string($conn, $bt['description'] ?? '');
    $recon_date  = date('Y-m-d');
    $bank_date_sql = $bank_date ? "'$bank_date'" : 'NULL';

    /* Insert or update reconciliation. invoice_amount stores the ACTUAL
       amount (after the 2.5% Visa charge), since that is what is being
       tallied against the bank credit. */
    $result = mysqli_query($conn, "
        INSERT INTO ushop_invoice_reconciliations
            (invoice_id, bst_id, recon_date, invoice_amount, bank_credit_amount, bank_description, bank_transaction_date)
        VALUES
            ($invoice_id, $bst_id, '$recon_date', $inv_amount, $bank_credit, '$bank_desc', $bank_date_sql)
        ON DUPLICATE KEY UPDATE
            bst_id                = $bst_id,
            recon_date            = '$recon_date',
            invoice_amount        = $inv_amount,
            bank_credit_amount    = $bank_credit,
            bank_description      = '$bank_desc',
            bank_transaction_date = $bank_date_sql,
            reconciled_at         = NOW()
    ");

    if ($result) {
        jsonDie([
            'ok'         => true,
            'msg'        => 'Invoice matched & reconciled successfully.',
            'invoice_id' => $invoice_id,
            'bst_id'     => $bst_id,
        ]);
    } else {
        jsonDie(['ok' => false, 'msg' => 'Database error: ' . mysqli_error($conn)]);
    }
}

/* ══════════════════════════════════════════════════════════
   AJAX — FETCH MATCHES  (GET action=fetch_matches)
══════════════════════════════════════════════════════════ */
if (($_GET['action'] ?? '') === 'fetch_matches') {
    
    /* ── Visa Card invoices (with VISA payment type) ── */
    $inv_res = mysqli_query($conn, "
        SELECT DISTINCT inv.id, inv.doc_no, inv.unique_inv_no, inv.invoice_date, 
               inv.customer_name, inv.customer_code, inv.total_amount
        FROM ushop_invoices inv
        INNER JOIN ushop_invoice_payments pay ON pay.invoice_id = inv.id
        WHERE LOWER(TRIM(pay.pay_type)) LIKE '%visa%'
        ORDER BY inv.invoice_date ASC
    ");

    if (!$inv_res) {
        jsonDie(['ok' => false, 'msg' => 'Database error (invoices): ' . mysqli_error($conn)]);
    }

    $invoices = [];
    $total_inv_amount    = 0;
    $total_actual_amount = 0;
    while ($row = mysqli_fetch_assoc($inv_res)) {
        $amount        = floatval($row['total_amount']);
        $actual_amount = round($amount * (1 - VISA_MDR_RATE), 2);
        $mdr_charge    = round($amount - $actual_amount, 2);
        $invoices[] = [
            'id'            => intval($row['id']),
            'doc_no'        => htmlspecialchars($row['doc_no']),
            'unique_inv_no' => htmlspecialchars($row['unique_inv_no'] ?? ''),
            'date'          => $row['invoice_date'],
            'customer'      => htmlspecialchars($row['customer_name'] ?: 'Walk-in'),
            'code'          => htmlspecialchars($row['customer_code'] ?? ''),
            'amount'        => $amount,         // gross invoice amount
            'mdr_charge'    => $mdr_charge,      // 2.5% bank charge
            'actual_amount' => $actual_amount,   // amount actually tallied vs bank
        ];
        $total_inv_amount    += $amount;
        $total_actual_amount += $actual_amount;
    }

    /* ── BOC bank statement credits ── */
    $bst_res = mysqli_query($conn, "
        SELECT bst.id, bst.transaction_date, bst.value_date,
               bst.description, bst.credit, bst.debit, bst.balance,
               bsu.statement_date, bsu.account_number, bsu.bank_type
        FROM bank_statement_transactions bst
        INNER JOIN bank_statement_uploads bsu ON bst.upload_id = bsu.id
        WHERE bst.credit > 0 AND bsu.bank_type = 'BOC'
        ORDER BY bst.transaction_date ASC
    ");

    if (!$bst_res) {
        jsonDie(['ok' => false, 'msg' => 'Database error (bank txns): ' . mysqli_error($conn)]);
    }

    $bank_txns = [];
    $total_bank_credit = 0;
    while ($b = mysqli_fetch_assoc($bst_res)) {
        $credit = floatval($b['credit']);
        $bank_txns[] = [
            'id'                => intval($b['id']),
            'transaction_date'  => $b['transaction_date'],
            'value_date'        => $b['value_date'],
            'description'       => htmlspecialchars($b['description'] ?? ''),
            'credit'            => $credit,
            'debit'             => floatval($b['debit']),
            'balance'           => floatval($b['balance']),
            'statement_date'    => $b['statement_date'],
            'account_number'    => htmlspecialchars($b['account_number'] ?? ''),
            'bank_type'         => htmlspecialchars($b['bank_type']),
        ];
        $total_bank_credit += $credit;
    }

    /* ── Already-reconciled invoices ── */
    $ar_res = mysqli_query($conn, "
        SELECT invoice_id, bst_id, bank_credit_amount, bank_description, 
               bank_transaction_date, invoice_amount, reconciled_at
        FROM ushop_invoice_reconciliations
    ");
    $already = [];
    $total_already = 0;
    while ($ar = mysqli_fetch_assoc($ar_res)) {
        $amt = floatval($ar['bank_credit_amount']);
        $already[intval($ar['invoice_id'])] = [
            'invoice_id'            => intval($ar['invoice_id']),
            'bst_id'                => intval($ar['bst_id']),
            'bank_credit_amount'    => $amt,
            'bank_description'      => htmlspecialchars($ar['bank_description'] ?? ''),
            'bank_transaction_date' => $ar['bank_transaction_date'],
            'invoice_amount'        => floatval($ar['invoice_amount']), // actual amount at time of save
            'reconciled_at'         => $ar['reconciled_at'],
        ];
        $total_already += $amt;
    }

    /* ── Server-side auto-match (scoring algorithm) — matched on ACTUAL amount ── */
    $usedBst = [];
    foreach ($already as $a) {
        $usedBst[intval($a['bst_id'])] = true;
    }

    $suggestions = [];
    foreach ($invoices as $inv) {
        if (isset($already[$inv['id']])) {
            continue;
        }

        $best = null;
        $bestScore = PHP_INT_MIN;

        foreach ($bank_txns as $b) {
            if (isset($usedBst[intval($b['id'])])) {
                continue;
            }

            /* Scoring: prioritize exact/near matches against the ACTUAL
               (post 2.5% charge) amount, then closer dates */
            $amtDiff = abs(floatval($b['credit']) - floatval($inv['actual_amount']));
            $dateDays = 999;
            
            if ($inv['date'] && $b['transaction_date']) {
                $invTime = strtotime($inv['date']);
                $bstTime = strtotime($b['transaction_date']);
                if ($invTime && $bstTime) {
                    $dateDays = abs(($bstTime - $invTime) / 86400);
                }
            }

            /* Score: penalize amount difference heavily, then date difference */
            $score = -($amtDiff * 200) - ($dateDays * 1);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $b;
            }
        }

        if ($best) {
            $diff = floatval($best['credit']) - floatval($inv['actual_amount']);
            $dateDays = 999;
            
            if ($inv['date'] && $best['transaction_date']) {
                $invTime = strtotime($inv['date']);
                $bstTime = strtotime($best['transaction_date']);
                if ($invTime && $bstTime) {
                    $dateDays = abs(($bstTime - $invTime) / 86400);
                }
            }

            /* Quality: exact < 0.02, near < 50, else miss */
            $quality = abs($diff) < 0.02 ? 'exact'
                     : (abs($diff) <= 50 ? 'near' : 'miss');

            $suggestions[intval($inv['id'])] = [
                'bst_id'         => intval($best['id']),
                'quality'        => $quality,
                'diff'           => round($diff, 2),
                'date_diff_days' => round($dateDays, 1),
            ];
            $usedBst[intval($best['id'])] = true;
        }
    }

    jsonDie([
        'ok'          => true,
        'invoices'    => $invoices,
        'bank_txns'   => $bank_txns,
        'already'     => $already,
        'suggestions' => $suggestions,
        'summary'     => [
            'invoice_count'     => count($invoices),
            'bank_txn_count'    => count($bank_txns),
            'already_count'     => count($already),
            'suggest_count'     => count($suggestions),
            'total_invoice_amt' => round($total_inv_amount, 2),
            'total_actual_amt'  => round($total_actual_amount, 2),
            'total_bank_credit' => round($total_bank_credit, 2),
            'total_already_amt' => round($total_already, 2),
            'overall_diff'      => round($total_bank_credit - $total_actual_amount, 2),
            'mdr_rate'          => VISA_MDR_RATE,
        ],
    ]);
}

/* ══════════════════════════════════════════════════════════
   PAGE RENDER
══════════════════════════════════════════════════════════ */
ob_end_flush();

/* Get Visa card invoices with reconciliation status - case insensitive match */
$vis_res = mysqli_query($conn, "
    SELECT DISTINCT inv.id, inv.doc_no, inv.unique_inv_no, inv.invoice_date,
           inv.customer_name, inv.customer_code, inv.total_amount,
           recon.id as recon_id, recon.bank_credit_amount, recon.bank_description,
           recon.bank_transaction_date, recon.reconciled_at
    FROM ushop_invoices inv
    INNER JOIN ushop_invoice_payments pay ON pay.invoice_id = inv.id
    LEFT JOIN ushop_invoice_reconciliations recon ON recon.invoice_id = inv.id
    WHERE LOWER(TRIM(pay.pay_type)) LIKE '%visa%'
    ORDER BY inv.invoice_date DESC, inv.id DESC
");

/* Debug: Check if query failed */
if (!$vis_res) {
    error_log('Visa Query Error: ' . mysqli_error($conn));
}

$visa_invoices = [];
$total_visa_amt = 0;     // gross invoice total
$total_actual_amt = 0;   // total after 2.5% Visa charge — this is what's tallied vs the bank
$total_reconciled_amt = 0;
$total_pending_amt = 0;
$recon_count = 0;
$pending_count = 0;

while ($v = mysqli_fetch_assoc($vis_res)) {
    $amt           = floatval($v['total_amount']);
    $actual_amt    = round($amt * (1 - VISA_MDR_RATE), 2);
    $mdr_charge    = round($amt - $actual_amt, 2);
    $v['actual_amount'] = $actual_amt;
    $v['mdr_charge']    = $mdr_charge;
    $visa_invoices[] = $v;
    $total_visa_amt   += $amt;
    $total_actual_amt += $actual_amt;
    
    if ($v['recon_id']) {
        $total_reconciled_amt += floatval($v['bank_credit_amount']);
        $recon_count++;
    } else {
        $total_pending_amt += $actual_amt;
        $pending_count++;
    }
}

/* Get BOC bank statement stats */
$boc_res = mysqli_query($conn, "
    SELECT COUNT(*) cnt, COALESCE(SUM(bst.credit), 0) total
    FROM bank_statement_transactions bst
    INNER JOIN bank_statement_uploads bsu ON bst.upload_id = bsu.id
    WHERE bst.credit > 0 AND bsu.bank_type = 'BOC'
");
$boc_stats = mysqli_fetch_assoc($boc_res) ?: ['cnt' => 0, 'total' => 0];

/* Calculate variance — BOC bank credits vs ACTUAL (post 2.5% charge) invoice total */
$variance = floatval($boc_stats['total']) - $total_actual_amt;

include 'header.php';
?>

<style>
* { box-sizing: border-box; }
.page-wrap { max-width: 1600px; margin: 0 auto; padding: 0 8px 48px; }

.breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 11.5px; color: #9ca3af; margin-bottom: 14px; flex-wrap: wrap; }
.breadcrumb a { color: #1e40af; text-decoration: none; font-weight: 600; }
.breadcrumb a:hover { text-decoration: underline; }
.breadcrumb .sep { color: #d1d5db; }

.ph-row { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px; margin-bottom: 18px; }
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; font-family: inherit; transition: all .18s; white-space: nowrap; text-decoration: none; }
.btn:disabled { opacity: 0.45; cursor: not-allowed; }
.btn-recon { background: #1e40af; color: #fff; }
.btn-recon:hover:not(:disabled) { background: #1e3a8a; }

.tally-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 20px; }
.tally-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px 18px; border-top: 3px solid #1e40af; box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04); }
.tally-label { font-size: 11px; color: #6b7280; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
.tally-val { font-size: 22px; font-weight: 800; color: #1e40af; }
.tally-sub { font-size: 11px; color: #9ca3af; margin-top: 3px; }

.table-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; box-shadow: 0 1px 6px rgba(0, 0, 0, 0.05); }
.table-toolbar { display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; border-bottom: 1px solid #f3f4f6; }
.tbl-title { font-size: 14px; font-weight: 700; color: #111827; }
.dt-wrap { overflow-x: auto; }
.data-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.data-table th { padding: 10px 12px; text-align: left; background: #f9fafb; border-bottom: 2px solid #e5e7eb; color: #374151; font-size: 11px; text-transform: uppercase; white-space: nowrap; }
.data-table td { padding: 9px 12px; border-bottom: 1px solid #f3f4f6; color: #111827; }
.data-table tbody tr:hover { background: #f9fafb; }
.tr { text-align: right !important; }
.tc { text-align: center !important; }
.badge { display: inline-block; padding: 2px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.badge-blue { background: #dbeafe; color: #1e40af; }
.badge-green { background: #dcfce7; color: #15803d; }
.badge-gray { background: #f3f4f6; color: #6b7280; }
.badge-amber { background: #fef3c7; color: #92400e; }

/* Reconciliation status rows */
.data-table tbody tr {
  transition: background-color 0.2s;
}
.data-table tbody tr[style*="background: #f0fdf4"] {
  border-left: 4px solid #15803d;
}
.data-table tbody tr[style*="background: #fffbeb"] {
  border-left: 4px solid #f59e0b;
}
.data-table tbody tr:hover {
  opacity: 0.95;
}

/* Reconciliation Drawer */
.recon-overlay { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.5); z-index: 1000; display: none; opacity: 0; transition: opacity 0.3s; }
.recon-overlay.open { display: block; opacity: 1; }
.recon-drawer { position: fixed; top: 0; right: 0; height: 100vh; width: min(98vw, 1160px); background: #fff; z-index: 1001; box-shadow: -4px 0 40px rgba(0, 0, 0, 0.2); display: flex; flex-direction: column; transform: translateX(100%); transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1); }
.recon-drawer.open { transform: translateX(0); }

.rd-head { display: flex; align-items: flex-start; justify-content: space-between; padding: 18px 22px; border-bottom: 1px solid #e5e7eb; flex-shrink: 0; background: #f9fafb; }
.rd-title { font-size: 15px; font-weight: 800; color: #111827; }
.rd-sub { font-size: 11px; color: #6b7280; margin-top: 3px; }
.rd-close { background: none; border: none; font-size: 18px; cursor: pointer; color: #6b7280; padding: 4px 8px; border-radius: 6px; transition: all 0.15s; }
.rd-close:hover { background: #f3f4f6; color: #111; }

.tally-section { flex-shrink: 0; padding: 14px 22px; border-bottom: 1px solid #f0f0f0; background: #f9fafb; }
.tally-heading { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280; margin-bottom: 10px; }
.tally-pills { display: flex; flex-wrap: wrap; gap: 8px; }
.tally-pill { display: flex; flex-direction: column; align-items: center; min-width: 110px; background: #fff; border: 1px solid #e5e7eb; border-radius: 9px; padding: 8px 14px; text-align: center; }
.tp-lbl { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; margin-bottom: 4px; }
.tp-val { font-size: 16px; font-weight: 800; color: #111827; }
.tp-sub { font-size: 10px; color: #9ca3af; margin-top: 2px; }
.tp-green { border-color: #bbf7d0; background: #f0fdf4; }
.tp-green .tp-val { color: #15803d; }
.tp-blue { border-color: #bfdbfe; background: #eff6ff; }
.tp-blue .tp-val { color: #1e40af; }
.tp-red { border-color: #fecaca; background: #fef2f2; }
.tp-red .tp-val { color: #dc2626; }
.tp-amber { border-color: #fde68a; background: #fffbeb; }
.tp-amber .tp-val { color: #92400e; }
.tp-purple { border-color: #ddd6fe; background: #f5f3ff; }
.tp-purple .tp-val { color: #6d28d9; }

.recon-toolbar { display: flex; align-items: center; justify-content: space-between; padding: 12px 22px; border-bottom: 1px solid #f0f0f0; flex-shrink: 0; gap: 10px; background: #fff; flex-wrap: wrap; }
.recon-content { display: flex; flex-direction: column; flex: 1; overflow: hidden; }
.match-wrap { flex: 1; overflow-y: auto; padding: 0 22px 16px; }
.match-table { width: 100%; border-collapse: collapse; font-size: 12px; min-width: 1100px; }
.match-table thead { position: sticky; top: 0; z-index: 5; background: #1e293b; }
.match-table thead th { padding: 9px 10px; color: #e2e8f0; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; white-space: nowrap; }
.match-table tbody tr { border-bottom: 1px solid #f1f5f9; transition: background 0.12s; }
.match-table tbody tr:hover { background: #f8fafc; }
.match-table td { padding: 8px 10px; vertical-align: middle; color: #1e293b; }
.match-table tr.q-exact { background: #f0fdf4 !important; }
.match-table tr.q-near { background: #fffbeb !important; }
.match-table tr.q-miss { background: #fef2f2 !important; }
.match-table tr.q-done { background: #f5f3ff !important; opacity: 0.85; }
.match-table tr.q-selected td { box-shadow: inset 0 0 0 2px #1e40af; }
.match-table tr.q-selected { background: #eff6ff !important; }

.mbadge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 10px; font-weight: 700; white-space: nowrap; }
.mb-exact { background: #dcfce7; color: #15803d; }
.mb-near { background: #fef3c7; color: #92400e; }
.mb-miss { background: #fee2e2; color: #dc2626; }
.mb-done { background: #ede9fe; color: #6d28d9; }

.bst-sel { width: 100%; min-width: 240px; padding: 5px 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 11px; font-family: inherit; background: #fff; cursor: pointer; color: #111827; }
.bst-sel:focus { border-color: #1e40af; outline: none; box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1); }

.recon-alert { margin-top: 10px; padding: 10px 14px; border-radius: 7px; font-size: 12px; font-weight: 600; margin-left: 22px; margin-right: 22px; }
.ra-info { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
.ra-ok { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.ra-err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

.save-result { flex-shrink: 0; margin: 10px 22px; padding: 11px 15px; border-radius: 8px; font-size: 13px; font-weight: 600; }
.sr-ok { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.sr-err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

.match-table tfoot td { padding: 9px 10px; border-top: 2px solid #334155; background: #1e293b; color: #e2e8f0; font-weight: 700; font-size: 12px; }
.match-table tfoot td.ar { text-align: right; }
.match-table tfoot td.ac { text-align: center; }

@media (max-width: 768px) {
    .recon-drawer { width: 100%; }
    .tally-grid { grid-template-columns: repeat(2, 1fr); }
    .match-table { min-width: 900px; font-size: 11px; }
}
</style>

<div class="page-wrap">

<div class="breadcrumb">
  <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
  <span class="sep">›</span>
  <span style="color: #1e40af; font-weight: 700;"><i class="fa-solid fa-link"></i> Visa Reconciliation</span>
</div>

<div class="ph-row">
  <div>
    <h2 style="margin: 0; font-size: 19px; font-weight: 700; color: #111827;">
      <i class="fa-solid fa-link" style="color: #1e40af; margin-right: 8px;"></i>Visa Card Invoice Reconciliation
    </h2>
    <p style="margin: 4px 0 0; font-size: 12px; color: #6b7280;">Match Visa invoices (after <?= number_format(VISA_MDR_RATE * 100, 1) ?>% bank charge) against BOC bank statement credits</p>
  </div>
  <button class="btn btn-recon" onclick="openReconPanel()">
    <i class="fa-solid fa-link"></i> Bank Reconciliation
  </button>
</div>

<!-- Tally Summary -->
<div class="tally-grid">
  <div class="tally-card">
    <div class="tally-label">Total Invoices</div>
    <div class="tally-val"><?= number_format(count($visa_invoices)) ?></div>
    <div class="tally-sub">Visa card invoices</div>
  </div>
  <div class="tally-card" style="border-top-color: #15803d;">
    <div class="tally-label">Invoice Amount (Gross)</div>
    <div class="tally-val" style="color: #15803d;"><?= number_format($total_visa_amt, 2) ?></div>
    <div class="tally-sub">before bank charge</div>
  </div>
  <div class="tally-card" style="border-top-color: #0e7490;">
    <div class="tally-label">Actual Amount (-<?= number_format(VISA_MDR_RATE * 100, 1) ?>%)</div>
    <div class="tally-val" style="color: #0e7490;"><?= number_format($total_actual_amt, 2) ?></div>
    <div class="tally-sub">tallied vs bank statement</div>
  </div>
  <div class="tally-card" style="border-top-color: #7c3aed;">
    <div class="tally-label">Reconciled</div>
    <div class="tally-val" style="color: #7c3aed;"><?= number_format($recon_count) ?></div>
    <div class="tally-sub"><?= number_format($total_reconciled_amt, 2) ?> matched</div>
  </div>
  <div class="tally-card" style="border-top-color: #f59e0b;">
    <div class="tally-label">Pending</div>
    <div class="tally-val" style="color: #f59e0b;"><?= number_format($pending_count) ?></div>
    <div class="tally-sub"><?= number_format($total_pending_amt, 2) ?> actual to match</div>
  </div>
  <div class="tally-card" style="border-top-color: #0891b2;">
    <div class="tally-label">BOC Credits</div>
    <div class="tally-val" style="color: #0891b2;"><?= number_format(floatval($boc_stats['total']), 2) ?></div>
    <div class="tally-sub"><?= intval($boc_stats['cnt']) ?> transactions</div>
  </div>
  <div class="tally-card" style="border-top-color: <?= abs($variance) < 0.01 ? '#15803d' : ($variance > 0 ? '#f59e0b' : '#dc2626') ?>;">
    <div class="tally-label">Variance</div>
    <div class="tally-val" style="color: <?= abs($variance) < 0.01 ? '#15803d' : ($variance > 0 ? '#f59e0b' : '#dc2626') ?>;"><?= number_format($variance, 2) ?></div>
    <div class="tally-sub">BOC − Actual Amount</div>
  </div>
</div>

<!-- Visa Invoice Table -->
<div class="table-card">
  <div class="table-toolbar">
    <div class="tbl-title">
      <i class="fa-solid fa-credit-card" style="margin-right: 6px; color: #1e40af;"></i>Visa Card Invoices Status
      <span style="font-size: 11px; color: #9ca3af; font-weight: 400; margin-left: 8px;"><?= count($visa_invoices) ?> record<?= count($visa_invoices) != 1 ? 's' : '' ?> · <?= $recon_count ?> reconciled · <?= $pending_count ?> pending</span>
    </div>
  </div>
  <div class="dt-wrap">
  <table class="data-table">
    <thead>
      <tr>
        <th style="width: 30px;">#</th>
        <th>Invoice Date</th>
        <th>Doc No</th>
        <th>Invoice No</th>
        <th>Customer</th>
        <th class="tr">Invoice Amt (Gross)</th>
        <th class="tr">Actual Amt (-<?= number_format(VISA_MDR_RATE * 100, 1) ?>%)</th>
        <th>Status</th>
        <th>Bank Txn Details</th>
        <th class="tr">Bank Credit</th>
        <th style="width: 80px;">Matched Date</th>
      </tr>
    </thead>
    <tbody>
    <?php 
    if (count($visa_invoices) > 0) {
        $i = 1;
        foreach ($visa_invoices as $inv):
            $is_recon = !empty($inv['recon_id']);
            $diff = $is_recon ? (floatval($inv['bank_credit_amount']) - floatval($inv['actual_amount'])) : 0;
    ?>
    <tr style="<?= $is_recon ? 'background: #f0fdf4;' : 'background: #fffbeb;' ?>">
      <td style="color: #9ca3af; font-size: 11px;"><?= $i++ ?></td>
      <td><span class="badge badge-blue"><?= date('d M Y', strtotime($inv['invoice_date'])) ?></span></td>
      <td style="font-weight: 700; color: #1e40af;"><?= htmlspecialchars($inv['doc_no']) ?></td>
      <td style="font-size: 11px; font-family: monospace; color: #6b7280;"><?= htmlspecialchars($inv['unique_inv_no'] ?? '—') ?></td>
      <td style="max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($inv['customer_name'] ?? '') ?>">
        <?= $inv['customer_name'] ? htmlspecialchars($inv['customer_name']) : '<span style="color: #9ca3af; font-size: 11px;">Walk-in</span>' ?>
      </td>
      <td class="tr" style="font-weight: 700; color: #15803d;"><?= number_format(floatval($inv['total_amount']), 2) ?></td>
      <td class="tr" style="font-weight: 700; color: #0e7490;">
        <?= number_format($inv['actual_amount'], 2) ?>
        <div style="font-size: 10px; color: #9ca3af; font-weight: 500;">−<?= number_format($inv['mdr_charge'], 2) ?> chg</div>
      </td>
      <td>
        <?php if ($is_recon): ?>
          <span class="badge" style="background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0;">
            <i class="fa-solid fa-circle-check"></i> Matched
          </span>
        <?php else: ?>
          <span class="badge" style="background: #fef3c7; color: #92400e; border: 1px solid #fde68a;">
            <i class="fa-solid fa-hourglass-end"></i> Pending
          </span>
        <?php endif; ?>
      </td>
      <td style="font-size: 11px; max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($inv['bank_description'] ?? '') ?>">
        <?php if ($is_recon): ?>
          <span style="color: #6b7280;"><?= htmlspecialchars(substr($inv['bank_description'] ?? '—', 0, 50)) ?></span>
        <?php else: ?>
          <span style="color: #d1d5db;">—</span>
        <?php endif; ?>
      </td>
      <td class="tr" style="font-size: 12px;">
        <?php if ($is_recon): ?>
          <strong style="color: #15803d;"><?= number_format(floatval($inv['bank_credit_amount']), 2) ?></strong>
          <div style="font-size: 10px; color: <?= abs($diff) < 0.01 ? '#6b7280' : ($diff > 0 ? '#15803d' : '#dc2626') ?>; font-weight: 600;">
            <?= abs($diff) < 0.01 ? '✓ Match' : ($diff > 0 ? '+' . number_format($diff, 2) : number_format($diff, 2)) ?>
          </div>
        <?php else: ?>
          <span style="color: #d1d5db;">—</span>
        <?php endif; ?>
      </td>
      <td style="font-size: 10px; color: #9ca3af; white-space: nowrap;">
        <?php if ($is_recon): ?>
          <?= date('d M Y', strtotime($inv['reconciled_at'])) ?>
        <?php else: ?>
          <span style="color: #d1d5db;">—</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach;
    } else { ?>
    <tr>
      <td colspan="11" style="text-align: center; padding: 44px; color: #9ca3af; font-size: 13px;">
        <i class="fa-solid fa-inbox" style="font-size: 24px; display: block; margin-bottom: 8px; opacity: 0.4;"></i>
        No Visa card invoices found.
      </td>
    </tr>
    <?php } ?>
    </tbody>
    <?php if (count($visa_invoices) > 0): ?>
    <tfoot>
      <tr style="background: #f9fafb; border-top: 2px solid #e5e7eb;">
        <td colspan="5" style="padding: 10px 12px; font-weight: 700; color: #374151; text-align: right;">TOTALS:</td>
        <td class="tr" style="padding: 10px 12px; font-weight: 700; color: #15803d; background: #f0fdf4;"><?= number_format($total_visa_amt, 2) ?></td>
        <td class="tr" style="padding: 10px 12px; font-weight: 700; color: #0e7490; background: #ecfeff;"><?= number_format($total_actual_amt, 2) ?></td>
        <td colspan="2" style="padding: 10px 12px; text-align: center; color: #6b7280; font-size: 11px;">
          <span class="badge" style="background: #dcfce7; color: #15803d;">Matched: <?= $recon_count ?></span>
          <span class="badge" style="background: #fef3c7; color: #92400e; margin-left: 4px;">Pending: <?= $pending_count ?></span>
        </td>
        <td class="tr" style="padding: 10px 12px; font-weight: 700; color: #15803d; background: #f0fdf4;"><?= number_format($total_reconciled_amt, 2) ?></td>
        <td style="padding: 10px 12px; text-align: center; font-weight: 700; color: #6b7280;">—</td>
      </tr>
    </tfoot>
    <?php endif; ?>
  </table>
  </div>
</div>

</div><!-- /page-wrap -->

<!-- ══════════════════════════════════════════════════════
     RECONCILIATION DRAWER PANEL
══════════════════════════════════════════════════════ -->
<div id="reconOverlay" class="recon-overlay" onclick="closeReconPanel()"></div>
<div id="reconDrawer" class="recon-drawer">

  <div class="rd-head">
    <div>
      <div class="rd-title"><i class="fa-solid fa-link" style="margin-right: 8px; color: #1e40af;"></i>Bank Statement Reconciliation</div>
      <div class="rd-sub">Match Visa invoices (after <?= number_format(VISA_MDR_RATE * 100, 1) ?>% bank charge) against BOC bank credits</div>
    </div>
    <button class="rd-close" onclick="closeReconPanel()" title="Close panel">
      <i class="fa-solid fa-xmark"></i>
    </button>
  </div>

  <div id="reconAlert" class="recon-alert" style="display: none;"></div>

  <!-- Tally Section -->
  <div class="tally-section" id="tallySection">
    <div style="color: #9ca3af; font-size: 12px; text-align: center; padding: 20px;">
      <i class="fa-solid fa-spinner fa-spin" style="margin-right: 6px;"></i> Loading data…
    </div>
  </div>

  <!-- Toolbar -->
  <div class="recon-toolbar">
    <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: #374151; cursor: pointer; margin: 0;">
      <input type="checkbox" id="chkSelectAll" onchange="toggleSelectAll(this.checked)">
      <span>Select All</span>
    </label>
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
      <button class="btn" style="background: #fff; color: #374151; border: 1px solid #d1d5db; padding: 6px 14px; font-size: 12px;" onclick="applyAutoMatch()">
        <i class="fa-solid fa-wand-magic-sparkles"></i> Auto-Match
      </button>
      <button id="btnSaveRecon" class="btn" style="background: #1e40af; color: #fff; padding: 6px 14px; font-size: 12px;" onclick="saveReconciliation()" disabled>
        <i class="fa-solid fa-floppy-disk"></i> Save Selected
      </button>
    </div>
  </div>

  <!-- Loading Spinner -->
  <div id="reconLoading" style="flex: 1; display: flex; align-items: center; justify-content: center; flex-direction: column; color: #6b7280;">
    <i class="fa-solid fa-spinner fa-spin fa-2x" style="color: #1e40af; margin-bottom: 12px;"></i>
    <div style="font-size: 13px; font-weight: 600;">Loading matches…</div>
  </div>

  <!-- Content -->
  <div id="reconContent" class="recon-content" style="display: none;">
    <!-- Match Table -->
    <div class="match-wrap">
      <table class="match-table">
        <thead>
          <tr>
            <th style="width: 36px; text-align: center;"><input type="checkbox" id="chkHead" onchange="toggleSelectAll(this.checked)"></th>
            <th>Invoice Date</th>
            <th>Doc No</th>
            <th>Customer</th>
            <th style="text-align: right;">Invoice Amt (Gross)</th>
            <th style="text-align: right;">Actual Amt (-<?= number_format(VISA_MDR_RATE * 100, 1) ?>%)</th>
            <th style="text-align: center;">↔</th>
            <th>Bank Txn (select)</th>
            <th>Bank Date</th>
            <th style="text-align: right;">Bank Credit</th>
            <th style="text-align: right;">Difference</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody id="matchBody"></tbody>
        <tfoot id="matchFoot"></tfoot>
      </table>
    </div>

    <!-- Save Result -->
    <div id="saveResult" class="save-result" style="display: none;"></div>
  </div>

</div><!-- /reconDrawer -->

<script>
const PAGE_URL = window.location.pathname;
const VISA_MDR_RATE = <?= json_encode(VISA_MDR_RATE) ?>;
let allInvoices = [];
let allBankTxns = [];
let alreadyMap = {};
let suggestions = {};
let matchState = {};

function openReconPanel() {
    document.getElementById('reconOverlay').classList.add('open');
    document.getElementById('reconDrawer').classList.add('open');
    document.body.style.overflow = 'hidden';
    loadMatches();
}

function closeReconPanel() {
    document.getElementById('reconOverlay').classList.remove('open');
    document.getElementById('reconDrawer').classList.remove('open');
    document.body.style.overflow = '';
}

function showAlert(msg, type) {
    const el = document.getElementById('reconAlert');
    if (!msg) {
        el.style.display = 'none';
        return;
    }
    const iconMap = { ok: 'circle-check', err: 'circle-xmark', info: 'circle-info' };
    el.className = 'recon-alert ra-' + type;
    el.innerHTML = '<i class="fa-solid fa-' + iconMap[type] + '" style="margin-right: 6px;"></i>' + msg;
    el.style.display = 'block';
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function loadMatches() {
    showAlert('', '');
    document.getElementById('reconLoading').style.display = 'flex';
    document.getElementById('reconContent').style.display = 'none';
    document.getElementById('saveResult').style.display = 'none';

    const url = PAGE_URL + '?action=fetch_matches';

    fetch(url, { headers: { 'Accept': 'application/json' } })
        .then(res => res.text().then(txt => {
            try {
                return JSON.parse(txt);
            } catch (e) {
                throw new Error('Invalid server response');
            }
        }))
        .then(data => {
            document.getElementById('reconLoading').style.display = 'none';
            if (!data.ok) {
                showAlert(data.msg || 'Failed to load data', 'err');
                return;
            }

            allInvoices = data.invoices || [];
            allBankTxns = data.bank_txns || [];
            alreadyMap = {};
            Object.entries(data.already || {}).forEach(([k, v]) => {
                alreadyMap[parseInt(k)] = v;
            });
            suggestions = {};
            Object.entries(data.suggestions || {}).forEach(([k, v]) => {
                suggestions[parseInt(k)] = v;
            });

            matchState = {};
            Object.entries(suggestions).forEach(([invId, sg]) => {
                const invIdInt = parseInt(invId);
                const inv = allInvoices.find(i => i.id === invIdInt);
                matchState[invIdInt] = {
                    bst_id: sg.bst_id,
                    amount: inv ? inv.actual_amount : 0, // ACTUAL amount (post 2.5% charge)
                    selected: sg.quality === 'exact' || sg.quality === 'near',
                };
            });

            renderTallySection(data.summary);
            renderMatchTable();

            document.getElementById('reconContent').style.display = 'flex';
            const summary = data.summary || {};
            const msg = `${allBankTxns.length} BOC credits · ${allInvoices.length} Visa invoices · ${Object.keys(alreadyMap).length} already matched`;
            showAlert(msg, 'info');
        })
        .catch(err => {
            document.getElementById('reconLoading').style.display = 'none';
            showAlert('Error: ' + (err.message || 'Unknown error'), 'err');
        });
}

function renderTallySection(summary) {
    const s = summary || {};
    const diff = parseFloat(s.overall_diff || 0);
    const matchRate = s.invoice_count > 0 ? Math.round(((s.suggest_count + s.already_count) / s.invoice_count) * 100) : 0;
    const diffColor = diff < -0.01 ? 'tp-red' : diff > 0.01 ? 'tp-amber' : 'tp-green';
    const mdrPct = ((s.mdr_rate !== undefined ? s.mdr_rate : VISA_MDR_RATE) * 100).toFixed(1);

    document.getElementById('tallySection').innerHTML = `
        <div class="tally-heading">Reconciliation Tally Summary</div>
        <div class="tally-pills">
            <div class="tally-pill tp-blue">
                <div class="tp-lbl">Invoices</div>
                <div class="tp-val">${s.invoice_count || 0}</div>
                <div class="tp-sub">to reconcile</div>
            </div>
            <div class="tally-pill tp-green">
                <div class="tp-lbl">Invoice Total (Gross)</div>
                <div class="tp-val">${fmt(s.total_invoice_amt || 0)}</div>
                <div class="tp-sub">before bank charge</div>
            </div>
            <div class="tally-pill tp-purple">
                <div class="tp-lbl">Actual Amount (-${mdrPct}%)</div>
                <div class="tp-val">${fmt(s.total_actual_amt || 0)}</div>
                <div class="tp-sub">tallied vs bank</div>
            </div>
            <div class="tally-pill tp-blue">
                <div class="tp-lbl">Bank Credits</div>
                <div class="tp-val">${s.bank_txn_count || 0}</div>
                <div class="tp-sub">transactions</div>
            </div>
            <div class="tally-pill tp-green">
                <div class="tp-lbl">Bank Total</div>
                <div class="tp-val">${fmt(s.total_bank_credit || 0)}</div>
                <div class="tp-sub">BOC amount</div>
            </div>
            <div class="tally-pill ${diffColor}">
                <div class="tp-lbl">Difference</div>
                <div class="tp-val">${diff >= 0 ? '+' : ''}${fmt(diff)}</div>
                <div class="tp-sub">bank − actual</div>
            </div>
            <div class="tally-pill tp-blue">
                <div class="tp-lbl">Match Rate</div>
                <div class="tp-val">${matchRate}%</div>
                <div class="tp-sub">${s.suggest_count + s.already_count} matched</div>
            </div>
        </div>
    `;
}

function renderMatchTable() {
    const tbody = document.getElementById('matchBody');
    tbody.innerHTML = '';
    const tfoot = document.getElementById('matchFoot');
    tfoot.innerHTML = '';

    let footInvAmt = 0, footActualAmt = 0, footBankCredit = 0;
    let selCount = 0;

    allInvoices.forEach(inv => {
        const isAlready = !!alreadyMap[inv.id];
        const state = matchState[inv.id];
        const bstId = state ? state.bst_id : (isAlready ? parseInt(alreadyMap[inv.id].bst_id) : null);
        const selected = !isAlready && state && state.selected;

        const bst = bstId ? allBankTxns.find(b => b.id === bstId) : null;
        const bankCredit = bst ? bst.credit : (isAlready ? parseFloat(alreadyMap[inv.id].bank_credit_amount) : null);
        const bankDate = bst ? bst.transaction_date : (isAlready ? alreadyMap[inv.id].bank_transaction_date : null);

        let quality = 'miss';
        let diff = null;
        if (isAlready) {
            quality = 'done';
            diff = bankCredit - inv.actual_amount;
        } else if (bst) {
            const sg = suggestions[inv.id];
            diff = bst.credit - inv.actual_amount;
            quality = sg ? sg.quality : (Math.abs(diff) < 0.02 ? 'exact' : Math.abs(diff) <= 50 ? 'near' : 'miss');
        }

        const rowCls = isAlready ? 'q-done' : quality === 'exact' ? 'q-exact' : quality === 'near' ? 'q-near' : 'q-miss';
        const badgeCls = isAlready ? 'mb-done' : quality === 'exact' ? 'mb-exact' : quality === 'near' ? 'mb-near' : 'mb-miss';
        const badgeTxt = isAlready ? '✓ Done' : quality === 'exact' ? '✓ Exact' : quality === 'near' ? '~ Near' : '✗ Miss';

        let chkHtml;
        if (isAlready) {
            chkHtml = `<i class="fa-solid fa-circle-check" style="color: #7c3aed; font-size: 15px;"></i>`;
        } else {
            const disabled = !bstId ? ' disabled' : '';
            chkHtml = `<input type="checkbox" class="row-chk" data-id="${inv.id}" ${selected ? 'checked' : ''} ${disabled} onchange="onRowCheck(${inv.id}, this.checked)">`;
            if (selected) selCount++;
        }

        let bankCellHtml;
        if (isAlready) {
            const desc = alreadyMap[inv.id].bank_description || '';
            bankCellHtml = `<span style="font-size: 11px; color: #7c3aed; font-weight: 600;" title="${esc(desc)}">${esc(desc).substring(0, 45)}</span>`;
        } else {
            const opts = allBankTxns.map(b => {
                const d = Math.abs(b.credit - inv.actual_amount);
                const style = d < 0.02 ? 'style="color: #15803d; font-weight: 700;"' : d <= 50 ? 'style="color: #92400e;"' : '';
                const sel = b.id === bstId ? 'selected' : '';
                const lbl = `${b.transaction_date || '?'} | ${fmt(b.credit)} | ${esc(b.description || '').substring(0, 40)}`;
                return `<option value="${b.id}" ${style} ${sel}>${lbl}</option>`;
            }).join('');
            bankCellHtml = `<select class="bst-sel" onchange="onSelectBst(${inv.id}, this.value, ${inv.actual_amount})"><option value="">-- select --</option>${opts}</select>`;
        }

        const diffHtml = diff !== null
            ? `<span style="font-weight: 700; color: ${Math.abs(diff) < 0.02 ? '#94a3b8' : diff > 0 ? '#15803d' : '#dc2626'}">${diff >= 0 ? '+' : ''}${fmt(diff)}</span>`
            : '<span style="color: #d1d5db;">—</span>';

        if (!isAlready && bankCredit !== null) {
            footInvAmt += inv.amount;
            footActualAmt += inv.actual_amount;
            footBankCredit += bankCredit;
        }

        const tr = document.createElement('tr');
        tr.className = rowCls + (selected ? ' q-selected' : '');
        tr.id = `mrow-${inv.id}`;
        tr.innerHTML = `
            <td style="text-align: center;">${chkHtml}</td>
            <td style="white-space: nowrap; font-size: 11px;">${fmtDate(inv.date)}</td>
            <td style="font-weight: 700; color: #1e40af;">${esc(inv.doc_no)}</td>
            <td style="font-size: 11px;">${esc(inv.customer)}</td>
            <td style="text-align: right; font-weight: 700; color: #15803d;">${fmt(inv.amount)}</td>
            <td style="text-align: right; font-weight: 700; color: #0e7490;">
                ${fmt(inv.actual_amount)}
                <div style="font-size: 9px; color: #9ca3af; font-weight: 500;">−${fmt(inv.mdr_charge)} chg</div>
            </td>
            <td style="text-align: center; color: #9ca3af; font-size: 14px;">→</td>
            <td>${bankCellHtml}</td>
            <td style="white-space: nowrap; font-size: 11px;">${bankDate ? fmtDate(bankDate) : '<span style="color: #d1d5db;">—</span>'}</td>
            <td style="text-align: right; font-size: 12px;">${bankCredit !== null ? `<strong>${fmt(bankCredit)}</strong>` : '<span style="color: #d1d5db;">—</span>'}</td>
            <td style="text-align: right; font-size: 11px;">${diffHtml}</td>
            <td><span class="mbadge ${badgeCls}">${badgeTxt}</span></td>
        `;
        tbody.appendChild(tr);
    });

    const tfoot_diff = footBankCredit - footActualAmt;
    const tfRow = document.createElement('tr');
    tfRow.innerHTML = `
        <td colspan="4" style="color: #94a3b8; font-size: 10px; text-transform: uppercase; font-weight: 700;">Totals (excl. done)</td>
        <td style="text-align: right; font-weight: 700; color: #374151;">${fmt(footInvAmt)}</td>
        <td style="text-align: right; font-weight: 700; color: #67e8f9;">${fmt(footActualAmt)}</td>
        <td></td>
        <td></td>
        <td></td>
        <td style="text-align: right; font-weight: 700; color: #374151;">${fmt(footBankCredit)}</td>
        <td style="text-align: right; font-weight: 700; color: ${tfoot_diff < -0.01 ? '#fca5a5' : tfoot_diff > 0.01 ? '#fde68a' : '#86efac'}">${tfoot_diff >= 0 ? '+' : ''}${fmt(tfoot_diff)}</td>
        <td></td>
    `;
    tfoot.appendChild(tfRow);

    updateSaveBtn();
    updateSelectAllState();
}

function onSelectBst(invId, bstId, actualAmount) {
    if (!bstId) {
        delete matchState[invId];
    } else {
        const existing = matchState[invId];
        matchState[invId] = {
            bst_id: parseInt(bstId),
            amount: actualAmount, // ACTUAL amount (post 2.5% charge)
            selected: existing ? existing.selected : false,
        };
    }
    renderMatchTable();
}

function onRowCheck(invId, checked) {
    if (matchState[invId]) {
        matchState[invId].selected = checked;
    }
    const row = document.getElementById(`mrow-${invId}`);
    if (row) {
        row.classList.toggle('q-selected', checked);
    }
    updateSelectAllState();
    updateSaveBtn();
}

function toggleSelectAll(checked) {
    ['chkSelectAll', 'chkHead'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.checked = checked;
    });
    allInvoices.forEach(inv => {
        if (!alreadyMap[inv.id] && matchState[inv.id] && matchState[inv.id].bst_id) {
            matchState[inv.id].selected = checked;
        }
    });
    renderMatchTable();
}

function updateSelectAllState() {
    const eligible = allInvoices.filter(inv =>
        !alreadyMap[inv.id] && matchState[inv.id] && matchState[inv.id].bst_id
    );
    const allChecked = eligible.length > 0 && eligible.every(inv => matchState[inv.id].selected);
    ['chkSelectAll', 'chkHead'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.checked = allChecked;
    });
}

function applyAutoMatch() {
    const usedBst = new Set();
    Object.values(alreadyMap).forEach(a => {
        usedBst.add(parseInt(a.bst_id));
    });

    allInvoices.forEach(inv => {
        if (alreadyMap[inv.id]) return;
        let best = null, bestScore = -Infinity;
        allBankTxns.forEach(b => {
            if (usedBst.has(b.id)) return;
            const amtDiff = Math.abs(b.credit - inv.actual_amount);
            const daysDiff = (inv.date && b.transaction_date)
                ? Math.abs((new Date(b.transaction_date) - new Date(inv.date)) / 86400000)
                : 999;
            const score = -(amtDiff * 200) - (daysDiff * 1);
            if (score > bestScore) {
                bestScore = score;
                best = b;
            }
        });
        if (best) {
            matchState[inv.id] = { bst_id: best.id, amount: inv.actual_amount, selected: true };
            usedBst.add(best.id);
        }
    });
    renderMatchTable();
}

function updateSaveBtn() {
    const has = allInvoices.some(inv =>
        !alreadyMap[inv.id] && matchState[inv.id] && matchState[inv.id].selected && matchState[inv.id].bst_id
    );
    document.getElementById('btnSaveRecon').disabled = !has;
}

function saveReconciliation() {
    const pairs = [];
    allInvoices.forEach(inv => {
        const s = matchState[inv.id];
        if (!alreadyMap[inv.id] && s && s.selected && s.bst_id) {
            pairs.push({ invoice_id: inv.id, bst_id: s.bst_id, inv_amount: s.amount });
        }
    });

    if (!pairs.length) {
        showAlert('No rows selected.', 'err');
        return;
    }

    const btn = document.getElementById('btnSaveRecon');
    btn.disabled = true;
    const origHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    let saved = 0, failed = 0;
    const startTime = Date.now();

    Promise.all(pairs.map(p => {
        const fd = new FormData();
        fd.append('action', 'save_invoice_recon');
        fd.append('invoice_id', p.invoice_id);
        fd.append('bst_id', p.bst_id);
        fd.append('inv_amount', p.inv_amount);

        return fetch(PAGE_URL, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
            .then(res => res.json())
            .then(data => {
                if (data.ok) saved++;
                else failed++;
            })
            .catch(() => {
                failed++;
            });
    })).then(() => {
        btn.disabled = false;
        btn.innerHTML = origHtml;

        const box = document.getElementById('saveResult');
        box.className = 'save-result ' + (saved > 0 ? 'sr-ok' : 'sr-err');
        const icon = saved > 0 ? 'circle-check' : 'circle-xmark';
        const msg = `<i class="fa-solid fa-${icon}"></i> ${saved} saved${failed ? ', ' + failed + ' failed' : ''}`;
        box.innerHTML = msg;
        box.style.display = 'block';

        if (saved > 0) {
            setTimeout(() => {
                loadMatches();
            }, 1200);
        }
    });
}

/* Formatting helpers */
function fmt(v) {
    return parseFloat(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function fmtDate(d) {
    if (!d) return '—';
    return new Date(d + 'T00:00:00').toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

function esc(s) {
    const d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}

/* Close drawer on backdrop click */
document.getElementById('reconOverlay').addEventListener('click', e => {
    if (e.target === e.currentTarget) {
        closeReconPanel();
    }
});
</script>

<?php include 'footer.php'; ?>