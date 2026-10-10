<?php
ob_start();
include 'config.php';

// ══════════════════════════════════════════════════════════════════
//  CREATE TABLES IF NOT EXISTS
// ══════════════════════════════════════════════════════════════════
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS oa_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    invoice_no VARCHAR(100),
    ledger_row_id INT,
    ledger_ref VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    notes TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS oa_transaction_lines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    oa_txn_id INT NOT NULL,
    description VARCHAR(500),
    amount DECIMAL(15,2),
    line_date DATE,
    FOREIGN KEY (oa_txn_id) REFERENCES oa_transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ══════════════════════════════════════════════════════════════════
//  AJAX — SAVE TRANSACTION
// ══════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_oa_txn') {
    ob_end_clean();
    header('Content-Type: application/json');
    $invoice_id  = intval($_POST['invoice_id']  ?? 0);
    $invoice_no  = mysqli_real_escape_string($conn, trim($_POST['invoice_no']  ?? ''));
    $ledger_id   = intval($_POST['ledger_row_id'] ?? 0);
    $ledger_ref  = mysqli_real_escape_string($conn, trim($_POST['ledger_ref']  ?? ''));
    $notes       = mysqli_real_escape_string($conn, trim($_POST['notes']       ?? ''));
    $lines       = $_POST['lines'] ?? [];

    if (!$invoice_id || empty($lines)) {
        echo json_encode(['ok' => false, 'msg' => 'Invoice ID and at least one line required.']);
        exit;
    }

    mysqli_query($conn, "INSERT INTO oa_transactions (invoice_id, invoice_no, ledger_row_id, ledger_ref, notes)
        VALUES ($invoice_id, '$invoice_no', ".($ledger_id ?: 'NULL').", '$ledger_ref', '$notes')");
    $txn_id = (int)mysqli_insert_id($conn);

    if (!$txn_id) {
        echo json_encode(['ok' => false, 'msg' => 'DB insert failed.']);
        exit;
    }

    $inserted = 0;
    foreach ($lines as $line) {
        $desc   = mysqli_real_escape_string($conn, trim($line['description'] ?? ''));
        $amt    = floatval($line['amount'] ?? 0);
        $ldate  = mysqli_real_escape_string($conn, trim($line['date'] ?? ''));
        if ($amt == 0) continue;
        $ldate_sql = $ldate ? "'$ldate'" : 'NULL';
        mysqli_query($conn, "INSERT INTO oa_transaction_lines (oa_txn_id, description, amount, line_date)
            VALUES ($txn_id, '$desc', $amt, $ldate_sql)");
        $inserted++;
    }

    echo json_encode(['ok' => true, 'msg' => "Transaction saved with $inserted line(s).", 'txn_id' => $txn_id]);
    exit;
}

// ══════════════════════════════════════════════════════════════════
//  AJAX — DELETE TRANSACTION
// ══════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_oa_txn') {
    ob_end_clean();
    header('Content-Type: application/json');
    $txn_id = intval($_POST['txn_id'] ?? 0);
    if (!$txn_id) { echo json_encode(['ok' => false, 'msg' => 'Invalid ID.']); exit; }
    // Lines deleted by CASCADE
    $ok = mysqli_query($conn, "DELETE FROM oa_transactions WHERE id=$txn_id");
    echo json_encode(['ok' => (bool)$ok && mysqli_affected_rows($conn) > 0, 'msg' => $ok ? 'Transaction deleted.' : 'Delete failed.']);
    exit;
}

// ══════════════════════════════════════════════════════════════════
//  AJAX — FETCH TRANSACTIONS FOR INVOICE
// ══════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_oa_txns') {
    ob_end_clean();
    header('Content-Type: application/json');
    $inv_id = intval($_GET['invoice_id'] ?? 0);
    if (!$inv_id) { echo json_encode(['ok' => false, 'rows' => []]); exit; }
    $res = mysqli_query($conn, "SELECT t.id, t.invoice_no, t.ledger_row_id, t.ledger_ref, t.notes, t.created_at,
        (SELECT GROUP_CONCAT(CONCAT(l.id,'~~',COALESCE(l.description,''),'~~',l.amount,'~~',COALESCE(l.line_date,''))
            ORDER BY l.id SEPARATOR '||') FROM oa_transaction_lines l WHERE l.oa_txn_id=t.id) AS lines
        FROM oa_transactions t WHERE t.invoice_id=$inv_id ORDER BY t.created_at DESC");
    $rows = [];
    if ($res) while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
    echo json_encode(['ok' => true, 'rows' => $rows]);
    exit;
}

// ══════════════════════════════════════════════════════════════════
//  ALLOWED TRANSACTION TYPES
// ══════════════════════════════════════════════════════════════════
define('OA_ALLOWED_TXN_TYPES', [
    'claims credit',
    'sl gt rtn billing',
    'sl new manual bill',
    'sl gt std billing',
]);

function oa_isAllowedTxnType($type) {
    return in_array(strtolower(trim($type ?? '')), OA_ALLOWED_TXN_TYPES, true);
}

// ══════════════════════════════════════════════════════════════════
//  HELPERS
// ══════════════════════════════════════════════════════════════════
function oa_buildQuery($where_sql) {
    return "SELECT p.*,
            COALESCE((SELECT SUM(s.paid_amount) FROM pi_settlements s WHERE s.pi_id = p.id), 0) AS total_paid_amt,
            (SELECT MAX(s.paid_date) FROM pi_settlements s WHERE s.pi_id = p.id) AS last_paid_date,
            (SELECT GROUP_CONCAT(
                CONCAT(COALESCE(s.cheque_no,''),'~~',COALESCE(s.paid_amount,''),'~~',COALESCE(s.paid_date,''))
                ORDER BY s.cheque_no, s.id SEPARATOR '||')
             FROM pi_settlements s WHERE s.pi_id = p.id) AS settlement_rows
     FROM primary_invoices p $where_sql
     ORDER BY (SELECT MIN(s.cheque_no) FROM pi_settlements s WHERE s.pi_id = p.id) IS NULL ASC,
              (SELECT MIN(s.cheque_no) FROM pi_settlements s WHERE s.pi_id = p.id) ASC,
              p.invoice_date DESC, p.id DESC";
}

function oa_findBestMatch($debit_rows, $invoice_value) {
    $inv = floatval($invoice_value);
    if ($inv <= 0 || empty($debit_rows)) return null;
    $near_threshold = max($inv * 0.10, 1000);
    $best_allowed = null; $best_any = null;
    foreach ($debit_rows as $row) {
        $debit = floatval($row['debit']);
        if ($debit <= 0) continue;
        $diff = abs($inv - $debit);
        $allowed = oa_isAllowedTxnType($row['transaction_type'] ?? '');
        if ($allowed && ($best_allowed === null || $diff < $best_allowed['diff']))
            $best_allowed = array_merge($row, ['diff' => $diff]);
        if ($best_any === null || $diff < $best_any['diff'])
            $best_any = array_merge($row, ['diff' => $diff]);
    }
    if ($best_allowed !== null) {
        $d = $best_allowed['diff'];
        if ($d <= 1.00 || $d <= $near_threshold)
            return array_merge($best_allowed, ['is_exact' => ($d <= 1.00), 'is_near' => ($d > 1.00 && $d <= $near_threshold), 'is_other_adj' => false]);
    }
    if ($best_any === null) return null;
    $d = $best_any['diff'];
    $allowed = oa_isAllowedTxnType($best_any['transaction_type'] ?? '');
    return array_merge($best_any, ['is_exact' => false, 'is_near' => false, 'is_other_adj' => (!$allowed)]);
}

function oa_findBestCreditMatch($all_ledger_credits_flat, $diff_val) {
    $diff_abs = abs($diff_val);
    if ($diff_abs <= 0) return null;
    $near_threshold = max($diff_abs * 0.10, 1000);
    $best_allowed = null; $best_any = null;
    foreach ($all_ledger_credits_flat as $row) {
        $credit = floatval($row['credit']);
        if ($credit <= 0) continue;
        $d = abs($diff_abs - $credit);
        $allowed = oa_isAllowedTxnType($row['transaction_type'] ?? '');
        if ($allowed && ($best_allowed === null || $d < $best_allowed['diff']))
            $best_allowed = array_merge($row, ['diff' => $d]);
        if ($best_any === null || $d < $best_any['diff'])
            $best_any = array_merge($row, ['diff' => $d]);
    }
    if ($best_allowed !== null) {
        $d = $best_allowed['diff'];
        if ($d <= 1.00 || $d <= $near_threshold)
            return array_merge($best_allowed, ['is_exact' => ($d <= 1.00), 'is_near' => ($d > 1.00 && $d <= $near_threshold), 'is_other_adj' => false]);
    }
    if ($best_any === null) return null;
    $d = $best_any['diff'];
    $allowed = oa_isAllowedTxnType($best_any['transaction_type'] ?? '');
    return array_merge($best_any, ['is_exact' => false, 'is_near' => false, 'is_other_adj' => (!$allowed)]);
}

function oa_extractChequeNo($ref) {
    if (preg_match('/cheque\s+no\.?\s*(\d+)/i', $ref, $m)) return $m[1];
    if (preg_match('/\b(chq|cheque|chque)\W*(\d{5,10})\b/i', $ref, $m)) return $m[2];
    return null;
}

function oa_getChequesPresentedMap($conn) {
    $res = mysqli_query($conn, "SELECT id, txn_date, transaction_type, customer_reference, debit, credit, balance
        FROM ulcl_ledger
        WHERE (LOWER(transaction_type) LIKE '%cheque%presented%'
            OR LOWER(transaction_type) LIKE '%presented%'
            OR LOWER(transaction_type) LIKE '%cheque%')
        ORDER BY txn_date DESC");
    $map = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $cn = oa_extractChequeNo($row['customer_reference'] ?? '');
            if ($cn) $map[$cn][] = $row;
        }
    }
    return $map;
}

// ══════════════════════════════════════════════════════════════════
//  PAGE LOAD — Filters
// ══════════════════════════════════════════════════════════════════
$filter_company = isset($_GET['filter_company']) ? mysqli_real_escape_string($conn, $_GET['filter_company']) : '';
$filter_from    = isset($_GET['filter_from'])    ? mysqli_real_escape_string($conn, $_GET['filter_from'])    : '';
$filter_to      = isset($_GET['filter_to'])      ? mysqli_real_escape_string($conn, $_GET['filter_to'])      : '';

$where = [];
if ($filter_company) $where[] = "p.company LIKE '%$filter_company%'";
if ($filter_from)    $where[] = "p.invoice_date >= '$filter_from'";
if ($filter_to)      $where[] = "p.invoice_date <= '$filter_to'";
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$rows_result = mysqli_query($conn, oa_buildQuery($where_sql));

// Pre-load ALL ledger debit rows (keyed by company)
$all_debit_rows = []; $all_debit_rows_flat = [];
$debit_res = mysqli_query($conn, "SELECT id, txn_date, transaction_type, customer_reference, debit, company FROM ulcl_ledger WHERE debit > 0 ORDER BY txn_date DESC");
if ($debit_res) {
    while ($br = mysqli_fetch_assoc($debit_res)) {
        $all_debit_rows_flat[] = $br;
        $co_key = strtoupper(trim($br['company'] ?? '')); if ($co_key === '') $co_key = 'UNKNOWN';
        $all_debit_rows[$co_key][] = $br;
    }
}

// Pre-load ALL ledger credit rows
$all_ledger_credits_flat = [];
$cr_res_all = mysqli_query($conn, "SELECT id, txn_date, transaction_type, customer_reference, credit FROM ulcl_ledger WHERE credit > 0 ORDER BY txn_date DESC");
if ($cr_res_all) { while ($cr = mysqli_fetch_assoc($cr_res_all)) $all_ledger_credits_flat[] = $cr; }

// Pre-load cheques presented map
$cheques_presented_map  = oa_getChequesPresentedMap($conn);
$cheque_ledger_cr_value = [];
foreach ($cheques_presented_map as $chq_no => $lrows) {
    $total_cr = 0; foreach ($lrows as $lrow) $total_cr += floatval($lrow['credit'] ?? 0);
    $cheque_ledger_cr_value[$chq_no] = $total_cr;
}

// Load existing OA transactions count per invoice
$existing_txns = [];
$etq = mysqli_query($conn, "SELECT invoice_id, COUNT(*) cnt FROM oa_transactions GROUP BY invoice_id");
if ($etq) while ($et = mysqli_fetch_assoc($etq)) $existing_txns[$et['invoice_id']] = $et['cnt'];

// ── Collect rows ──
$oa_rows = []; $total_inv = 0; $total_paid = 0;

if ($rows_result) {
    while ($r = mysqli_fetch_assoc($rows_result)) {
        $paid_amt = floatval($r['total_paid_amt'] ?? 0);
        if ($paid_amt <= 0 || empty($r['settlement_rows'])) continue;

        $has_cheque = false;
        foreach (explode('||', $r['settlement_rows']) as $item) {
            $parts = explode('~~', $item, 3);
            if (trim($parts[0] ?? '') !== '' && floatval($parts[1] ?? 0) > 0) { $has_cheque = true; break; }
        }
        if (!$has_cheque) continue;

        $inv_val = floatval($r['invoice_value'] ?? 0);
        $co      = strtoupper(trim($r['company'] ?? ''));
        $co_rows = !empty($all_debit_rows[$co]) ? $all_debit_rows[$co] : $all_debit_rows_flat;

        $best_dr   = oa_findBestMatch($co_rows, $inv_val);
        $dr_is_adj = ($best_dr && !empty($best_dr['is_other_adj']));

        $inv_chq_diff = $inv_val - $paid_amt;
        $diff_abs     = abs($inv_chq_diff);
        $cr_is_adj    = false;
        $best_cr      = null;
        if ($diff_abs > 1.00) {
            $best_cr   = oa_findBestCreditMatch($all_ledger_credits_flat, $diff_abs);
            $cr_is_adj = ($best_cr && !empty($best_cr['is_other_adj']));
        }

        if (!$dr_is_adj && !$cr_is_adj) continue;

        $r['_best_dr']      = $best_dr;
        $r['_best_cr']      = $best_cr;
        $r['_dr_is_adj']    = $dr_is_adj;
        $r['_cr_is_adj']    = $cr_is_adj;
        $r['_inv_chq_diff'] = $inv_chq_diff;
        $oa_rows[]   = $r;
        $total_inv  += $inv_val;
        $total_paid += $paid_amt;
    }
}

include 'header.php';
?>

<!-- ═══════════════════════ STYLES ═══════════════════════ -->
<style>
/* ── Layout ── */
.page-subtitle{color:#6b7280;font-size:13px;margin-top:2px}
.sum-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:12px;margin-bottom:20px}
.sum-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:14px 16px;position:relative;overflow:hidden}
.sum-card::after{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;border-radius:3px 0 0 3px}
.sum-card.purple::after{background:#7c3aed}.sum-card.red::after{background:#dc2626}
.sum-card.amber::after{background:#d97706}.sum-card.blue::after{background:#3b82f6}
.sum-lbl{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
.sum-val{font-size:18px;font-weight:800;color:#111827;line-height:1.1}
.sum-sub{font-size:10.5px;color:#9ca3af;margin-top:3px}
.content-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:18px 20px;margin-bottom:18px}
/* ── Filter ── */
.filter-row{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end}
.fg{display:flex;flex-direction:column;gap:3px}
.fg label{font-size:10.5px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px}
.fi{height:32px;padding:0 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12.5px;font-family:inherit;outline:none;background:#fff;color:#111827}
.fi:focus{border-color:#111827}
/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:5px;padding:0 14px;height:32px;border:none;border-radius:6px;font-size:12.5px;font-weight:700;cursor:pointer;font-family:inherit;text-decoration:none;white-space:nowrap;transition:all .15s}
.btn-dark{background:#111827;color:#fff}.btn-dark:hover{background:#374151}
.btn-light{background:#f9fafb;color:#374151;border:1px solid #d1d5db}.btn-light:hover{background:#f3f4f6}
.btn-green{background:#16a34a;color:#fff}.btn-green:hover{background:#15803d}
.btn-purple{background:#7c3aed;color:#fff;height:28px;padding:0 10px;font-size:11px}.btn-purple:hover{background:#6d28d9}
.btn-xs-green{background:#dcfce7;color:#166534;border:1px solid #86efac;height:24px;padding:0 8px;font-size:10.5px}.btn-xs-green:hover{background:#bbf7d0}
/* ── Toolbar ── */
.toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px}
.search-wrap{position:relative;min-width:220px;flex:1;max-width:320px}
.search-wrap i{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:11px;pointer-events:none}
.search-input{width:100%;height:32px;padding:0 10px 0 28px;border:1px solid #d1d5db;border-radius:6px;font-size:12.5px;font-family:inherit;outline:none;background:#fff;box-sizing:border-box}
.search-input:focus{border-color:#7c3aed}
#rowCount{font-size:11.5px;color:#9ca3af;margin-left:4px}
/* ── Table ── */
.tbl-scroll{overflow-x:auto;border:1px solid #e5e7eb;border-radius:8px;max-height:75vh;overflow-y:auto}
.oa-table{width:100%;border-collapse:collapse;font-size:11.5px;white-space:nowrap}
.oa-table thead{position:sticky;top:0;z-index:10}
.oa-table thead th{background:#3b0764;color:#e9d5ff;padding:7px 10px;text-align:left;font-size:10px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;border-bottom:2px solid #6d28d9}
.oa-table th.r,.oa-table td.r{text-align:right}
.oa-table th.c,.oa-table td.c{text-align:center}
.oa-table tbody tr:nth-child(odd) td{background:#faf5ff}
.oa-table tbody tr:nth-child(even) td{background:#f5f3ff}
.oa-table tbody tr:hover td{filter:brightness(.97)}
.oa-table td{padding:6px 10px;color:#1f2937;vertical-align:middle;border-bottom:1px solid #ede9fe}
.oa-table tfoot td{background:#3b0764;color:#e9d5ff;padding:7px 10px;font-weight:700;font-size:11.5px;border-top:2px solid #6d28d9;position:sticky;bottom:0;z-index:9}
/* ── Section headers ── */
.sect-inv{background:#1e3a5f !important;color:#93c5fd !important;text-align:center !important;font-size:10.5px !important;font-weight:800 !important;letter-spacing:.8px !important;border-bottom:2px solid #3b82f6 !important}
.sect-chq{background:#052e16 !important;color:#6ee7b7 !important;text-align:center !important;font-size:10.5px !important;font-weight:800 !important;letter-spacing:.8px !important;border-bottom:2px solid #22c55e !important}
.sect-diff{background:#3b1a6e !important;color:#d8b4fe !important;text-align:center !important;font-size:10.5px !important;font-weight:800 !important;letter-spacing:.8px !important;border-bottom:2px solid #a855f7 !important}
.sect-act{background:#1c1917 !important;color:#fef3c7 !important;text-align:center !important;font-size:10.5px !important;font-weight:800 !important;letter-spacing:.8px !important;border-bottom:2px solid #f59e0b !important}
.sub-inv{background:#172554 !important;color:#93c5fd !important;font-size:9.5px !important;padding:4px 10px !important}
.sub-chq{background:#052e16 !important;color:#6ee7b7 !important;font-size:9.5px !important;padding:4px 10px !important}
.sub-diff{background:#2e1065 !important;color:#d8b4fe !important;font-size:9.5px !important;padding:4px 10px !important}
.sub-act{background:#1c1917 !important;color:#fef3c7 !important;font-size:9.5px !important;padding:4px 10px !important}
/* ── Separators ── */
.oa-table td.sep-inv,.oa-table th.sep-inv{border-left:2px solid #3b82f6 !important}
.oa-table td.sep-chq,.oa-table th.sep-chq{border-left:2px solid #22c55e !important}
.oa-table td.sep-diff,.oa-table th.sep-diff{border-left:2px solid #a855f7 !important}
.oa-table td.sep-act,.oa-table th.sep-act{border-left:2px solid #f59e0b !important}
/* ── Chips / badges ── */
.inv-no{font-family:'Courier New',monospace;font-size:11.5px;font-weight:700;color:#1e3a5f;background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;padding:1px 7px;display:inline-block}
.co-usll{background:#1d4ed8;color:#fff;font-size:9.5px;font-weight:800;padding:2px 8px;border-radius:20px;display:inline-block}
.co-ulcl{background:#16a34a;color:#fff;font-size:9.5px;font-weight:800;padding:2px 8px;border-radius:20px;display:inline-block}
.co-other{background:#6b7280;color:#fff;font-size:9.5px;font-weight:800;padding:2px 8px;border-radius:20px;display:inline-block}
.cheque-chip{display:inline-flex;align-items:center;gap:3px;background:#ede9fe;color:#5b21b6;border:1px solid #ddd6fe;padding:1px 7px;border-radius:5px;font-size:10.5px;font-weight:700;margin:1px 0}
.cell-date{display:inline-block;padding:1px 7px;border-radius:4px;font-size:10.5px;font-weight:700;border:1px solid #e2e8f0;background:#f1f5f9;color:#334155}
.badge-adj{display:inline-flex;align-items:center;gap:4px;font-size:9px;font-weight:800;color:#fff;background:#7c3aed;padding:2px 8px;border-radius:10px}
.badge-ok{display:inline-flex;align-items:center;gap:4px;font-size:9px;font-weight:800;color:#fff;background:#16a34a;padding:2px 8px;border-radius:10px}
.badge-near{display:inline-flex;align-items:center;gap:4px;font-size:9px;font-weight:800;color:#fff;background:#d97706;padding:2px 8px;border-radius:10px}
.badge-none{display:inline-flex;align-items:center;gap:4px;font-size:9px;font-weight:800;color:#fff;background:#dc2626;padding:2px 8px;border-radius:10px}
.type-tag{display:inline-block;font-size:10px;font-weight:700;padding:2px 7px;border-radius:4px;background:#ede9fe;color:#5b21b6;border:1px solid #c4b5fd;margin-top:3px}
.type-tag-ok{display:inline-block;font-size:10px;font-weight:700;padding:2px 7px;border-radius:4px;background:#dcfce7;color:#166534;border:1px solid #86efac;margin-top:3px}
.dr-pill{font-size:8px;font-weight:700;background:#dbeafe;color:#1e40af;padding:0 4px;border-radius:3px;margin-left:3px}
.cr-pill{font-size:8px;font-weight:700;background:#dcfce7;color:#166534;padding:0 4px;border-radius:3px;margin-left:3px}
/* ── Ledger detail box ── */
.ledger-detail-box{background:#1e3a5f;border:1px solid #3b82f6;border-radius:6px;padding:6px 10px;min-width:200px;white-space:normal}
.ldb-row{display:flex;align-items:center;gap:5px;flex-wrap:wrap;margin-bottom:3px}
.ldb-label{font-size:9px;font-weight:700;color:#93c5fd;text-transform:uppercase;letter-spacing:.4px;min-width:55px}
.ldb-val{font-size:10.5px;color:#e0f2fe;font-weight:600}
.ldb-debit{font-size:13px;font-weight:800;color:#fca5a5}
.ldb-type{display:inline-block;font-size:10px;font-weight:700;padding:2px 7px;border-radius:4px;background:#7c3aed;color:#fff;margin-top:2px}
.ldb-ref{font-family:'Courier New',monospace;font-size:10px;color:#bfdbfe;word-break:break-all}
/* ── Diff cell ── */
.diff-cell{min-width:190px;max-width:240px;white-space:normal;vertical-align:top}
.diff-val-pos{color:#7c3aed;font-weight:800;font-size:12.5px}
.diff-val-neg{color:#dc2626;font-weight:800;font-size:12.5px}
.diff-val-zero{color:#16a34a;font-weight:800;font-size:12.5px}
.diff-cr-match{margin-top:4px;padding:3px 6px;border-radius:5px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px}
.diff-cr-exact{background:#dcfce7;color:#166534;border:1px solid #86efac}
.diff-cr-near{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.diff-cr-none{background:#fef2f2;color:#991b1b;border:1px solid #fca5a5}
.diff-cr-zero{background:#dcfce7;color:#166634;border:1px solid #86efac}
.diff-cr-adj{background:#ede9fe;color:#5b21b6;border:1px solid #c4b5fd}
/* ── Existing txn badge ── */
.txn-exists-badge{display:inline-flex;align-items:center;gap:3px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;padding:2px 7px;border-radius:5px;font-size:9.5px;font-weight:700;margin-bottom:4px}
/* ── Alert banner ── */
.alert-banner{background:#faf5ff;border:1.5px solid #c4b5fd;border-radius:8px;padding:12px 16px;margin-bottom:18px;display:flex;align-items:flex-start;gap:12px}
.alert-banner i{color:#7c3aed;font-size:18px;margin-top:1px;flex-shrink:0}
.alert-banner h4{font-size:13px;font-weight:800;color:#3b0764;margin:0 0 3px}
.alert-banner p{font-size:11.5px;color:#6b7280;margin:0}
.empty-state{text-align:center;padding:60px 20px;color:#9ca3af}
.empty-state i{font-size:42px;display:block;margin-bottom:12px;color:#c4b5fd}
/* ── Toast ── */
#toast{position:fixed;bottom:22px;right:22px;padding:10px 18px;border-radius:8px;font-size:13px;font-weight:600;color:#fff;z-index:9999;display:none;box-shadow:0 4px 16px rgba(0,0,0,.2)}
#toast.success{background:#16a34a}#toast.error{background:#dc2626}

/* ════════════════════════════════
   MODAL STYLES
════════════════════════════════ */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000;align-items:center;justify-content:center;padding:16px}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:12px;width:100%;max-width:660px;max-height:92vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);animation:slideUp .2s ease}
@keyframes slideUp{from{transform:translateY(24px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e5e7eb;background:linear-gradient(135deg,#3b0764,#1e3a5f);border-radius:12px 12px 0 0}
.modal-title{font-size:15px;font-weight:800;color:#fff;display:flex;align-items:center;gap:8px}
.modal-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:28px;height:28px;border-radius:6px;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:14px;transition:background .15s}
.modal-close:hover{background:rgba(255,255,255,.3)}
.modal-body{padding:20px}
/* Info row in modal */
.modal-info-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:18px}
.mig-item{background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;padding:10px 12px}
.mig-label{font-size:9.5px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px}
.mig-val{font-size:13px;font-weight:700;color:#1f2937}
/* Ledger info box in modal */
.modal-ledger-box{background:#1e3a5f;border:1px solid #3b82f6;border-radius:8px;padding:12px 14px;margin-bottom:18px}
.mlb-title{font-size:10px;font-weight:800;color:#93c5fd;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;display:flex;align-items:center;gap:6px}
.mlb-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.mlb-item .mlb-lbl{font-size:9px;font-weight:700;color:#7dd3fc;text-transform:uppercase;letter-spacing:.3px;margin-bottom:2px}
.mlb-item .mlb-v{font-size:11.5px;font-weight:700;color:#e0f2fe}
.mlb-item .mlb-v.debit{color:#fca5a5;font-size:14px}
/* Payment lines */
.lines-section{margin-bottom:16px}
.lines-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.lines-title{font-size:12px;font-weight:800;color:#374151;display:flex;align-items:center;gap:6px}
.line-row{display:grid;grid-template-columns:1fr auto auto auto;gap:8px;align-items:center;margin-bottom:8px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:7px;padding:8px 10px;position:relative}
.line-row .line-desc{grid-column:1/2}
.line-row .line-amt{width:120px}
.line-row .line-date{width:130px}
.line-row .line-del{width:26px;height:26px;background:#fef2f2;border:1px solid #fca5a5;border-radius:5px;color:#dc2626;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0}
.line-row .line-del:hover{background:#fee2e2}
.line-num{font-size:9px;font-weight:800;color:#9ca3af;margin-bottom:3px}
.mf-input{width:100%;height:30px;padding:0 8px;border:1px solid #d1d5db;border-radius:5px;font-size:12px;font-family:inherit;box-sizing:border-box;outline:none}
.mf-input:focus{border-color:#7c3aed}
/* Line total */
.line-total-bar{background:#ede9fe;border:1px solid #c4b5fd;border-radius:7px;padding:8px 14px;display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.lt-label{font-size:11px;font-weight:700;color:#5b21b6}
.lt-val{font-size:16px;font-weight:800;color:#3b0764}
/* Notes */
.modal-notes-row{margin-bottom:18px}
.modal-notes-row label{font-size:10.5px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:4px}
.modal-notes-row textarea{width:100%;min-height:60px;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12.5px;font-family:inherit;resize:vertical;outline:none;box-sizing:border-box}
.modal-notes-row textarea:focus{border-color:#7c3aed}
/* Footer */
.modal-footer{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 20px;border-top:1px solid #e5e7eb;background:#f9fafb;border-radius:0 0 12px 12px}
.mf-hint{font-size:11px;color:#9ca3af}
.btn-save{background:#7c3aed;color:#fff;height:36px;padding:0 20px;font-size:13px;font-weight:700;border:none;border-radius:7px;cursor:pointer;display:flex;align-items:center;gap:6px}
.btn-save:hover{background:#6d28d9}
.btn-save:disabled{background:#c4b5fd;cursor:not-allowed}
.btn-cancel-modal{background:#f9fafb;color:#374151;border:1px solid #d1d5db;height:36px;padding:0 16px;font-size:13px;font-weight:700;border-radius:7px;cursor:pointer}
.btn-cancel-modal:hover{background:#f3f4f6}
/* View Transactions button */
.btn-view-txn{display:inline-flex;align-items:center;gap:5px;padding:0 10px;height:26px;border:1px solid #d1d5db;border-radius:5px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;background:#f9fafb;color:#6b7280;transition:all .15s}
.btn-view-txn:hover{background:#f3f4f6;border-color:#9ca3af}
.btn-view-txn.has-txns{background:#fef3c7;color:#92400e;border-color:#fde68a}
.btn-view-txn.has-txns:hover{background:#fde68a}
.vbtn-count{display:inline-flex;align-items:center;justify-content:center;min-width:16px;height:16px;background:#7c3aed;color:#fff;font-size:9px;font-weight:800;border-radius:8px;padding:0 4px}
.btn-view-txn.has-txns .vbtn-count{background:#d97706}
/* View modal specific */
.view-modal-box{background:#fff;border-radius:12px;width:100%;max-width:780px;max-height:92vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);animation:slideUp .2s ease}
.vm-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e5e7eb;background:linear-gradient(135deg,#052e16,#1e3a5f);border-radius:12px 12px 0 0}
.vm-title{font-size:15px;font-weight:800;color:#fff;display:flex;align-items:center;gap:8px}
.txn-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:12px;overflow:hidden}
.txn-card-header{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#f8fafc;border-bottom:1px solid #e5e7eb}
.txn-card-id{font-size:10px;font-weight:800;color:#9ca3af;letter-spacing:.5px}
.txn-card-date{font-size:10.5px;color:#6b7280}
.txn-lines-table{width:100%;border-collapse:collapse;font-size:12px}
.txn-lines-table th{background:#f1f5f9;padding:6px 12px;text-align:left;font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #e2e8f0}
.txn-lines-table th.r{text-align:right}
.txn-lines-table td{padding:8px 12px;border-bottom:1px solid #f0f0f0;color:#1f2937;vertical-align:middle}
.txn-lines-table td.r{text-align:right;font-weight:700;color:#7c3aed;font-variant-numeric:tabular-nums}
.txn-lines-table tr:last-child td{border-bottom:none}
.txn-card-footer{display:flex;align-items:center;justify-content:space-between;padding:8px 14px;background:#faf5ff;border-top:1px solid #ede9fe}
.txn-total-lbl{font-size:10.5px;font-weight:700;color:#7c3aed}
.txn-total-val{font-size:14px;font-weight:800;color:#3b0764}
.btn-del-txn{display:inline-flex;align-items:center;gap:4px;padding:0 10px;height:26px;background:#fef2f2;border:1px solid #fca5a5;border-radius:5px;color:#dc2626;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit}
.btn-del-txn:hover{background:#fee2e2}
.vm-empty{text-align:center;padding:40px 20px;color:#9ca3af;font-size:13px}
.vm-loading{text-align:center;padding:40px 20px;color:#7c3aed;font-size:13px}
.vm-notes{font-size:11px;color:#6b7280;padding:4px 14px 10px;font-style:italic}
</style>

<!-- ═══════════════════════ PAGE HEADER ═══════════════════════ -->
<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
        <div>
            <h2 class="page-title"><i class="fa-solid fa-triangle-exclamation" style="color:#7c3aed"></i> Other Adjustments</h2>
            <p class="page-subtitle">Invoices with cheque linked · DR match OR balance CR match is a non-standard type · Allowed: Claims Credit, SL GT Rtn Billing, SL New Manual Bill, SL GT Std Billing</p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a href="pi_reconciliation.php" class="btn btn-light"><i class="fa-solid fa-scale-balanced"></i> Full Reconciliation</a>
            <a href="primary_invoices.php"  class="btn btn-light"><i class="fa-solid fa-file-invoice-dollar"></i> Primary Invoices</a>
            <button class="btn btn-green" onclick="exportCSV()"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
        </div>
    </div>
</div>

<div class="alert-banner">
    <i class="fa-solid fa-circle-info"></i>
    <div>
        <h4>What is shown here?</h4>
        <p>Invoices that have a cheque/payment linked AND whose <strong>ledger DR match</strong> OR <strong>balance difference CR match</strong> is a non-standard transaction type. Use <strong>Add Transaction</strong> to record separate payments against the matched ledger row.</p>
    </div>
</div>

<?php
$unique_txn_types = []; $unique_cheques = [];
foreach ($oa_rows as $r) {
    if (!empty($r['_best_dr']['transaction_type']) && $r['_dr_is_adj'])
        $unique_txn_types[$r['_best_dr']['transaction_type']] = ($unique_txn_types[$r['_best_dr']['transaction_type']] ?? 0) + 1;
    if (!empty($r['_best_cr']['transaction_type']) && $r['_cr_is_adj'])
        $unique_txn_types[$r['_best_cr']['transaction_type']] = ($unique_txn_types[$r['_best_cr']['transaction_type']] ?? 0) + 1;
    if (!empty($r['settlement_rows'])) {
        foreach (explode('||', $r['settlement_rows']) as $item) {
            $parts = explode('~~', $item, 3); $cn = trim($parts[0] ?? ''); $amt = floatval($parts[1] ?? 0);
            if ($cn && $amt > 0) $unique_cheques[$cn] = true;
        }
    }
}
arsort($unique_txn_types);
?>

<!-- ═══════════════════════ SUMMARY CARDS ═══════════════════════ -->
<div class="sum-grid">
    <div class="sum-card purple"><div class="sum-lbl">Other Adj. Invoices</div><div class="sum-val"><?php echo number_format(count($oa_rows)); ?></div><div class="sum-sub">DR or CR side flagged</div></div>
    <div class="sum-card red"><div class="sum-lbl">Invoice Value</div><div class="sum-val" style="font-size:15px;color:#7c3aed"><?php echo $total_inv > 0 ? number_format($total_inv,2) : '—'; ?></div><div class="sum-sub">Total gross</div></div>
    <div class="sum-card amber"><div class="sum-lbl">Cheque Payments</div><div class="sum-val" style="font-size:15px;color:#16a34a"><?php echo number_format($total_paid,2); ?></div><div class="sum-sub">Total settled</div></div>
    <div class="sum-card blue"><div class="sum-lbl">Distinct Cheques</div><div class="sum-val"><?php echo number_format(count($unique_cheques)); ?></div><div class="sum-sub">Unique cheque nos.</div></div>
    <div class="sum-card purple"><div class="sum-lbl">Non-Std Txn Types</div><div class="sum-val"><?php echo number_format(count($unique_txn_types)); ?></div><div class="sum-sub">Distinct types flagged</div></div>
</div>

<?php if (!empty($unique_txn_types)): ?>
<div class="content-card" style="padding:14px 18px">
    <div style="font-size:11px;font-weight:800;color:#7c3aed;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px"><i class="fa-solid fa-tags"></i> Non-Standard Transaction Types Found</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php foreach ($unique_txn_types as $type => $cnt): ?>
        <span style="display:inline-flex;align-items:center;gap:5px;background:#ede9fe;border:1px solid #c4b5fd;border-radius:6px;padding:4px 10px;font-size:11px">
            <span style="font-weight:800;color:#5b21b6"><?php echo htmlspecialchars($type); ?></span>
            <span style="background:#7c3aed;color:#fff;font-size:9px;font-weight:800;padding:1px 5px;border-radius:8px"><?php echo $cnt; ?></span>
        </span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════ FILTERS ═══════════════════════ -->
<div class="content-card">
    <form method="GET" action="" class="filter-row">
        <div class="fg"><label>Company</label>
            <select name="filter_company" class="fi" style="width:110px">
                <option value="">All</option>
                <option value="USLL" <?php echo $filter_company==='USLL'?'selected':''; ?>>USLL</option>
                <option value="ULCL" <?php echo $filter_company==='ULCL'?'selected':''; ?>>ULCL</option>
            </select>
        </div>
        <div class="fg"><label>From date</label><input type="date" name="filter_from" class="fi" value="<?php echo htmlspecialchars($filter_from); ?>"></div>
        <div class="fg"><label>To date</label><input type="date" name="filter_to" class="fi" value="<?php echo htmlspecialchars($filter_to); ?>"></div>
        <button type="submit" class="btn btn-dark"><i class="fa-solid fa-filter"></i> Filter</button>
        <a href="pi_other_adjustments.php" class="btn btn-light"><i class="fa-solid fa-xmark"></i> Clear</a>
    </form>
</div>

<!-- ═══════════════════════ TABLE ═══════════════════════ -->
<div class="content-card" style="padding:16px 20px">
    <div class="toolbar">
        <div class="search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" class="search-input" id="searchBox" placeholder="Invoice no, cheque no, txn type…" oninput="doSearch()">
        </div>
        <span id="rowCount"></span>
    </div>
    <div class="tbl-scroll">
        <table class="oa-table">
           <thead>
    <tr>
        <th colspan="10" class="sect-inv sep-inv"><i class="fa-solid fa-file-invoice-dollar" style="margin-right:5px"></i>INVOICE RECONCILATION</th>
        <th colspan="3" class="sect-chq sep-chq"><i class="fa-solid fa-money-check-dollar" style="margin-right:5px"></i>CHEQUE RECONCILATION</th>
        <th colspan="1" class="sect-diff sep-diff"><i class="fa-solid fa-calculator" style="margin-right:5px"></i>BALANCE DIFFERENCE · CR MATCH</th>
        <th colspan="1" class="sect-act sep-act"><i class="fa-solid fa-plus-circle" style="margin-right:5px"></i>ACTIONS</th>
    </tr>
    <tr>
        <th class="sub-inv sep-inv">#</th>
        <th class="sub-inv">Invoice Number</th>
        <th class="sub-inv c">CO</th>
        <th class="sub-inv">Invoice Date</th>
        <th class="r sub-inv">Invoice Amount</th>
        <th class="r sub-inv">Ledger Amt</th>
        <th class="sub-inv">Ledger Date</th>
        <th class="sub-inv">Txn Type</th>
        <th class="sub-inv">Customer Ref</th>
        <th class="r sub-inv">Diff</th>
        <th class="sub-chq sep-chq">Cheque Number</th>
        <th class="sub-chq">Cheque Date</th>
        <th class="r sub-chq">Cheque Amount</th>
        <th class="sub-diff sep-diff" style="min-width:200px">Inv − Cheque · CR Match</th>
        <th class="sub-act sep-act">Add / View</th>
    </tr>
</thead>
            <tbody id="tableBody">
            <?php if (empty($oa_rows)): ?>
                <tr><td colspan="15"><div class="empty-state"><i class="fa-solid fa-circle-check" style="color:#86efac"></i><p style="font-weight:700;color:#374151">No other adjustments found</p><p style="font-size:12px">All invoices with payments match a standard billing type on both DR and CR sides.</p></div></td></tr>
            <?php else: ?>
                <?php
                $rn = 1; $tf_inv = 0; $tf_paid = 0;
                foreach ($oa_rows as $r):
                    $inv_val    = floatval($r['invoice_value']  ?? 0);
                    $inv_paid   = floatval($r['total_paid_amt'] ?? 0);
                    $tf_inv += $inv_val; $tf_paid += $inv_paid;

                    $co = strtoupper(trim($r['company'] ?? ''));
                    if ($co === 'USLL')     $co_html = '<span class="co-usll">USLL</span>';
                    elseif ($co === 'ULCL') $co_html = '<span class="co-ulcl">ULCL</span>';
                    else                    $co_html = '<span class="co-other">'.htmlspecialchars($r['company']).'</span>';

                    $best_dr      = $r['_best_dr'];
                    $dr_is_adj    = $r['_dr_is_adj'];
                    $best_cr      = $r['_best_cr'];
                    $cr_is_adj    = $r['_cr_is_adj'];
                    $inv_chq_diff = floatval($r['_inv_chq_diff']);

                    // ── Ledger DR detail box (actual row data) ──
                    if (!$best_dr) {
                        $dr_amt_html   = '<span style="color:#d1d5db">—</span>';
                        $dr_date_html  = '<span style="color:#d1d5db">—</span>';
                        $dr_type_html  = '<span style="color:#d1d5db">—</span>';
                        $dr_ref_html   = '<span style="color:#d1d5db">—</span>';
                        $dr_diff_html  = '<span style="color:#d1d5db">—</span>';
                    } else {
                        $debit    = floatval($best_dr['debit'] ?? 0);
                        $diff     = floatval($best_dr['diff']  ?? 0);
                        $type_lbl = htmlspecialchars($best_dr['transaction_type'] ?? '—');
                        $ref_lbl  = htmlspecialchars($best_dr['customer_reference'] ?? '—');
                        $pct      = $inv_val > 0 ? round(($diff / $inv_val) * 100, 2) : 0;

                        // Date
                        $dr_date_html = !empty($best_dr['txn_date'])
                            ? '<span class="cell-date">'.date('d M Y', strtotime($best_dr['txn_date'])).'</span>'
                            : '<span style="color:#d1d5db">—</span>';

                        // Amount coloured by status
                        if ($dr_is_adj) {
                            $dr_amt_html  = '<span style="color:#7c3aed;font-weight:800;font-size:13px">'.number_format($debit,2).'</span>';
                            $dr_diff_html = '<span style="color:#7c3aed;font-weight:800">'.number_format($diff,2).'</span>'.($pct>0?'<span style="font-size:9px;color:#9ca3af;margin-left:3px">'.$pct.'%</span>':'');
                            $dr_type_html = '<span class="badge-adj" style="font-size:8.5px"><i class="fa-solid fa-triangle-exclamation" style="font-size:8px"></i> ADJ</span><br><span class="type-tag">'.$type_lbl.'</span>';
                        } elseif (!empty($best_dr['is_exact'])) {
                            $dr_amt_html  = '<span style="color:#15803d;font-weight:800;font-size:13px">'.number_format($debit,2).'</span>';
                            $dr_diff_html = '<span style="color:#16a34a;font-weight:800">'.number_format($diff,2).'</span> <span style="font-size:9px;color:#86efac">✓</span>';
                            $dr_type_html = '<span class="badge-ok" style="font-size:8.5px"><i class="fa-solid fa-circle-check" style="font-size:8px"></i> EXACT</span><br><span class="type-tag-ok">'.$type_lbl.'</span>';
                        } elseif (!empty($best_dr['is_near'])) {
                            $dr_amt_html  = '<span style="color:#b45309;font-weight:800;font-size:13px">'.number_format($debit,2).'</span>';
                            $dr_diff_html = '<span style="color:#d97706;font-weight:800">'.number_format($diff,2).'</span>'.($pct>0?'<span style="font-size:9px;color:#9ca3af;margin-left:3px">'.$pct.'%</span>':'');
                            $dr_type_html = '<span class="badge-near" style="font-size:8.5px"><i class="fa-solid fa-circle-half-stroke" style="font-size:8px"></i> NEAR</span><br><span class="type-tag-ok">'.$type_lbl.'</span>';
                        } else {
                            $dr_amt_html  = '<span style="color:#9ca3af;font-weight:700">'.number_format($debit,2).'</span>';
                            $dr_diff_html = '<span style="color:#dc2626;font-weight:800">'.number_format($diff,2).'</span>'.($pct>0?'<span style="font-size:9px;color:#9ca3af;margin-left:3px">'.$pct.'%</span>':'');
                            $dr_type_html = '<span class="badge-none" style="font-size:8.5px"><i class="fa-solid fa-circle-xmark" style="font-size:8px"></i> NO MATCH</span><br><span class="type-tag">'.$type_lbl.'</span>';
                        }
                        $dr_ref_html = '<span class="ldb-ref">'.$ref_lbl.'</span>';
                    }

                    // ── Cheques ──
                    $settlement_cheques = []; $all_cheque_q = [];
                    if (!empty($r['settlement_rows'])) {
                        foreach (explode('||', $r['settlement_rows']) as $item) {
                            $parts = explode('~~', $item, 3);
                            $cn_key = trim($parts[0] ?? ''); $amt = floatval($parts[1] ?? 0); $dt = trim($parts[2] ?? '');
                            if ($amt <= 0) continue;
                            $settlement_cheques[] = ['no' => $cn_key, 'amt' => $amt, 'date' => $dt];
                            if ($cn_key) $all_cheque_q[] = $cn_key;
                        }
                    }
                    $all_cheque_q = array_unique($all_cheque_q);

                    $cheque_no_html = '<span style="color:#d1d5db">—</span>';
                    if (!empty($settlement_cheques)) {
                        $chips = ''; $seen = [];
                        foreach ($settlement_cheques as $sc) {
                            $ck = $sc['no'] ?: '__none__';
                            if (isset($seen[$ck])) continue; $seen[$ck] = true;
                            $label = $ck === '__none__' ? '<em style="color:#9ca3af;font-size:10px">no cheque no</em>' : '<i class="fa-solid fa-money-check"></i> '.htmlspecialchars($ck);
                            $chips .= '<div style="margin:1px 0"><span class="cheque-chip">'.$label.'</span></div>';
                        }
                        $cheque_no_html = $chips;
                    }
                    $cheque_date_html = '<span style="color:#d1d5db">—</span>';
                    if (!empty($settlement_cheques[0]['date']))
                        $cheque_date_html = '<span class="cell-date">'.date('d M Y', strtotime($settlement_cheques[0]['date'])).'</span>';

                    // ── Balance Difference ──
                    $diff_abs = abs($inv_chq_diff);
                    if ($inv_val == 0 && $inv_paid == 0) {
                        $diff_cell_html = '<span style="color:#d1d5db">—</span>';
                    } elseif ($diff_abs <= 1.00) {
                        $diff_cell_html = '<span class="diff-val-zero">0.00 ✓</span>'
                                        . '<br><span class="diff-cr-match diff-cr-zero"><i class="fa-solid fa-circle-check" style="font-size:8px"></i> FULLY SETTLED</span>';
                    } else {
                        $diff_cell_html = ($inv_chq_diff > 0)
                            ? '<span class="diff-val-pos">'.number_format($inv_chq_diff,2).'</span><span style="font-size:9px;color:#9ca3af;margin-left:4px">outstanding</span>'
                            : '<span class="diff-val-neg">'.number_format($inv_chq_diff,2).'</span><span style="font-size:9px;color:#9ca3af;margin-left:4px">overpaid</span>';

                        if (!$best_cr) {
                            $diff_cell_html .= '<br><span class="diff-cr-match diff-cr-none"><i class="fa-solid fa-circle-xmark" style="font-size:8px"></i> NOT IN LEDGER CR</span>';
                        } elseif ($cr_is_adj) {
                            $cr_type = htmlspecialchars($best_cr['transaction_type'] ?? '');
                            $diff_cell_html .= '<br><span class="diff-cr-match diff-cr-adj"><i class="fa-solid fa-triangle-exclamation" style="font-size:8px"></i> OTHER ADJUSTMENT</span>'
                                            . '<br><span style="font-size:9px;color:#5b21b6;font-weight:700">'.$cr_type.' <span class="cr-pill">CR</span></span>'
                                            . '<br><span style="font-size:9px;color:#9ca3af">'.number_format(floatval($best_cr['credit']),2).'</span>';
                        } elseif (!empty($best_cr['is_exact'])) {
                            $cr_type = htmlspecialchars($best_cr['transaction_type'] ?? '');
                            $diff_cell_html .= '<br><span class="diff-cr-match diff-cr-exact"><i class="fa-solid fa-circle-check" style="font-size:8px"></i> EXACT CR MATCH</span>'
                                            . '<br><span style="font-size:9px;color:#166534;font-weight:700">'.$cr_type.' <span class="cr-pill">CR</span></span>'
                                            . '<br><span style="font-size:9px;color:#9ca3af">'.number_format(floatval($best_cr['credit']),2).'</span>';
                        } elseif (!empty($best_cr['is_near'])) {
                            $cr_type = htmlspecialchars($best_cr['transaction_type'] ?? '');
                            $cr_pct  = $diff_abs > 0 ? round(($best_cr['diff'] / $diff_abs) * 100, 2) : 0;
                            $diff_cell_html .= '<br><span class="diff-cr-match diff-cr-near"><i class="fa-solid fa-circle-half-stroke" style="font-size:8px"></i> NEAR CR MATCH</span>'
                                            . '<br><span style="font-size:9px;color:#92400e;font-weight:700">'.$cr_type.' <span class="cr-pill">CR</span></span>'
                                            . '<br><span style="font-size:9px;color:#9ca3af">'.number_format(floatval($best_cr['credit']),2).' ('.$cr_pct.'%)</span>';
                        } else {
                            $diff_cell_html .= '<br><span class="diff-cr-match diff-cr-none"><i class="fa-solid fa-circle-xmark" style="font-size:8px"></i> NO CR MATCH</span>';
                        }
                    }

                    // Modal data attributes
                    $ledger_id  = $best_dr ? intval($best_dr['id'] ?? 0) : 0;
                    $ledger_ref = $best_dr ? htmlspecialchars($best_dr['customer_reference'] ?? '') : '';
                    $ledger_type= $best_dr ? htmlspecialchars($best_dr['transaction_type'] ?? '') : '';
                    $ledger_date= $best_dr && !empty($best_dr['txn_date']) ? date('d M Y', strtotime($best_dr['txn_date'])) : '—';
                    $ledger_amt = $best_dr ? number_format(floatval($best_dr['debit'] ?? 0),2) : '0.00';
                    $existing_cnt = $existing_txns[$r['id']] ?? 0;

                    $search_data = strtolower(
                        ($r['invoice_no'] ?? '').' '.($r['company'] ?? '').' '.
                        implode(' ', $all_cheque_q).' '.
                        ($best_dr['transaction_type'] ?? '').' '.($best_cr['transaction_type'] ?? '')
                    );
                ?>
                <tr id="tr-<?php echo $r['id']; ?>" data-search="<?php echo htmlspecialchars($search_data); ?>">
                    <td style="color:#9ca3af;font-size:10px"><?php echo $rn++; ?></td>
                    <td><span class="inv-no"><?php echo htmlspecialchars($r['invoice_no']); ?></span></td>
                    <td class="c"><?php echo $co_html; ?></td>
                    <td><?php echo !empty($r['invoice_date']) ? '<span class="cell-date">'.date('d M Y', strtotime($r['invoice_date'])).'</span>' : '<span style="color:#d1d5db">—</span>'; ?></td>
                    <td class="r" style="font-weight:800;font-size:12.5px"><?php echo number_format($inv_val,2); ?></td>
                    <!-- DR ledger columns -->
                    <td class="r sep-inv"><?php echo $dr_amt_html; ?></td>
                    <td><?php echo $dr_date_html; ?></td>
                    <td style="white-space:normal;min-width:160px"><?php echo $dr_type_html; ?></td>
                    <td style="white-space:normal;min-width:140px"><?php echo $dr_ref_html; ?></td>
                    <td class="r"><?php echo $dr_diff_html; ?></td>
                    <!-- Cheque columns -->
                    <td class="sep-chq" style="white-space:normal;min-width:130px"><?php echo $cheque_no_html; ?></td>
                    <td><?php echo $cheque_date_html; ?></td>
                    <td class="r"><span style="color:#16a34a;font-weight:800;font-size:12.5px"><?php echo number_format($inv_paid,2); ?></span></td>
                    <!-- Diff column -->
                    <td class="diff-cell sep-diff"><?php echo $diff_cell_html; ?></td>
                    <!-- Actions column -->
                    <td class="sep-act" style="white-space:normal;min-width:150px;text-align:center;vertical-align:middle">
                        <div style="display:flex;flex-direction:column;gap:5px;align-items:center">
                            <button class="btn btn-purple"
                                onclick="openModal(
                                    <?php echo $r['id']; ?>,
                                    '<?php echo addslashes($r['invoice_no']); ?>',
                                    '<?php echo addslashes(number_format($inv_val,2)); ?>',
                                    <?php echo $ledger_id; ?>,
                                    '<?php echo addslashes($ledger_ref); ?>',
                                    '<?php echo addslashes($ledger_type); ?>',
                                    '<?php echo addslashes($ledger_date); ?>',
                                    '<?php echo addslashes($ledger_amt); ?>'
                                )">
                                <i class="fa-solid fa-plus"></i> Add Transaction
                            </button>
                            <button class="btn-view-txn <?php echo $existing_cnt > 0 ? 'has-txns' : ''; ?>"
                                id="viewbtn-<?php echo $r['id']; ?>"
                                onclick="openViewModal(<?php echo $r['id']; ?>, '<?php echo addslashes($r['invoice_no']); ?>')">
                                <i class="fa-solid fa-eye"></i>
                                View Transactions
                                <span class="vbtn-count" id="vcount-<?php echo $r['id']; ?>"><?php echo $existing_cnt; ?></span>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
            <?php if (!empty($oa_rows)): ?>
            <tfoot>
                <tr>
                    <td colspan="4" style="text-align:right;font-size:10px;opacity:.5">TOTALS</td>
                    <td class="r"><?php echo number_format($tf_inv,2); ?></td>
                    <td colspan="7"></td>
                    <td class="r" style="color:#4ade80"><?php echo number_format($tf_paid,2); ?></td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
    <?php if (!empty($oa_rows)): ?>
    <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:14px;padding-top:12px;border-top:1px solid #f0f0f0;font-size:11.5px;color:#6b7280">
        <span><span style="font-weight:800;color:#7c3aed"><?php echo count($oa_rows); ?></span> other adjustment rows</span>
        <span><span style="font-weight:800;color:#16a34a"><?php echo count($unique_cheques); ?></span> distinct cheques</span>
        <span><span style="font-weight:800;color:#dc2626"><?php echo count($unique_txn_types); ?></span> non-standard transaction types</span>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════════════════════ ADD TRANSACTION MODAL ═══════════════════════ -->
<div class="modal-overlay" id="txnModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title"><i class="fa-solid fa-plus-circle"></i> Add Transaction</div>
            <button class="modal-close" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <!-- Invoice + Ledger info -->
            <div class="modal-info-grid">
                <div class="mig-item">
                    <div class="mig-label">Invoice Number</div>
                    <div class="mig-val" id="m-inv-no" style="font-family:'Courier New',monospace;color:#1e3a5f">—</div>
                </div>
                <div class="mig-item">
                    <div class="mig-label">Invoice Amount</div>
                    <div class="mig-val" id="m-inv-amt" style="color:#7c3aed">—</div>
                </div>
            </div>

            <!-- Ledger row detail -->
            <div class="modal-ledger-box" id="m-ledger-box">
                <div class="mlb-title"><i class="fa-solid fa-landmark"></i> Matched Ledger Row (Auto-filled)</div>
                <div class="mlb-grid">
                    <div class="mlb-item"><div class="mlb-lbl">Ledger Row ID</div><div class="mlb-v" id="m-led-id">—</div></div>
                    <div class="mlb-item"><div class="mlb-lbl">Txn Date</div><div class="mlb-v" id="m-led-date">—</div></div>
                    <div class="mlb-item"><div class="mlb-lbl">Debit Amount</div><div class="mlb-v debit" id="m-led-amt">—</div></div>
                    <div class="mlb-item" style="grid-column:1/3"><div class="mlb-lbl">Transaction Type</div><div class="mlb-v" id="m-led-type">—</div></div>
                    <div class="mlb-item" style="grid-column:1/4"><div class="mlb-lbl">Customer Reference</div><div class="mlb-v" id="m-led-ref" style="font-family:'Courier New',monospace;word-break:break-all">—</div></div>
                </div>
            </div>

            <!-- Payment lines -->
            <div class="lines-section">
                <div class="lines-header">
                    <div class="lines-title"><i class="fa-solid fa-list"></i> Payment Lines</div>
                    <button type="button" class="btn btn-xs-green" onclick="addLine()"><i class="fa-solid fa-plus"></i> Add Line</button>
                </div>
                <div id="linesContainer"></div>
            </div>

            <!-- Line total -->
            <div class="line-total-bar">
                <span class="lt-label"><i class="fa-solid fa-sigma"></i> Total Entered</span>
                <span class="lt-val" id="lineTotal">0.00</span>
            </div>

            <!-- Notes -->
            <div class="modal-notes-row">
                <label>Notes (optional)</label>
                <textarea id="m-notes" placeholder="Any additional notes for this transaction…"></textarea>
            </div>

            <!-- Hidden inputs -->
            <input type="hidden" id="m-invoice-id">
            <input type="hidden" id="m-ledger-row-id">
            <input type="hidden" id="m-ledger-ref-val">
        </div>
        <div class="modal-footer">
            <span class="mf-hint"><i class="fa-solid fa-info-circle"></i> All lines saved as separate payment entries</span>
            <div style="display:flex;gap:8px">
                <button class="btn-cancel-modal" onclick="closeModal()">Cancel</button>
                <button class="btn-save" id="saveBtn" onclick="saveTxn()">
                    <i class="fa-solid fa-floppy-disk"></i> Save Transaction
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════ VIEW TRANSACTIONS MODAL ═══════════════════════ -->
<div class="modal-overlay" id="viewTxnModal">
    <div class="view-modal-box">
        <div class="vm-header">
            <div class="vm-title"><i class="fa-solid fa-receipt"></i> Transactions — <span id="vm-inv-no" style="color:#6ee7b7;margin-left:4px"></span></div>
            <button class="modal-close" onclick="closeViewModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div style="padding:18px 20px" id="vmBody">
            <div class="vm-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>
        </div>
        <div style="padding:12px 20px;border-top:1px solid #e5e7eb;background:#f9fafb;border-radius:0 0 12px 12px;display:flex;justify-content:flex-end">
            <button class="btn-cancel-modal" onclick="closeViewModal()">Close</button>
        </div>
    </div>
</div>

<div id="toast"></div>

<!-- ═══════════════════════ SCRIPTS ═══════════════════════ -->



<script>
// ── Search ──
function doSearch() {
    const q = document.getElementById('searchBox').value.toLowerCase().trim();
    const rows = document.querySelectorAll('#tableBody tr[id^="tr-"]');
    let vis = 0;
    rows.forEach(r => {
        const show = !q || (r.dataset.search || '').includes(q);
        r.style.display = show ? '' : 'none';
        if (show) vis++;
    });
    document.getElementById('rowCount').textContent = `(${vis} of ${rows.length})`;
}
document.addEventListener('DOMContentLoaded', () => {
    const rows = document.querySelectorAll('#tableBody tr[id^="tr-"]');
    if (rows.length) document.getElementById('rowCount').textContent = `(${rows.length})`;
});

// ── Export CSV ──
function exportCSV() {
    const params = new URLSearchParams(window.location.search);
    params.set('action', 'export_csv');
    window.location = 'pi_other_adjustments.php?' + params.toString();
}

// ── Toast ──
function showToast(msg, type) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = type;
    t.style.display = 'block';
    clearTimeout(t._t);
    t._t = setTimeout(() => t.style.display = 'none', 3500);
}

// ══════════════════════════════════════════
//  VIEW TRANSACTIONS MODAL
// ══════════════════════════════════════════
let _viewInvId = null;

function openViewModal(invId, invNo) {
    _viewInvId = invId;
    document.getElementById('vm-inv-no').textContent = invNo;
    document.getElementById('vmBody').innerHTML =
        '<div class="vm-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
    document.getElementById('viewTxnModal').classList.add('open');
    loadTransactions(invId);
}

function closeViewModal() {
    document.getElementById('viewTxnModal').classList.remove('open');
    _viewInvId = null;
}

document.getElementById('viewTxnModal').addEventListener('click', function (e) {
    if (e.target === this) closeViewModal();
});

function loadTransactions(invId) {
    const url = 'oa_ajax.php?action=get_oa_txns&invoice_id=' + encodeURIComponent(invId);
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.text())
        .then(raw => {
            const body = document.getElementById('vmBody');
            let data;
            // Strip any accidental whitespace / BOM before the JSON
            const cleaned = raw.trim().replace(/^\uFEFF/, '');
            try {
                data = JSON.parse(cleaned);
            } catch (e) {
                // Show the raw server output so you can diagnose it
                body.innerHTML = `<div style="color:var(--color-text-danger);padding:16px;font-size:12px">
                    <strong>Server returned invalid JSON:</strong><br>
                    <pre style="white-space:pre-wrap;margin-top:8px;background:var(--color-background-danger);
                         padding:10px;border-radius:6px;font-size:11px;max-height:300px;overflow:auto">${escHtml(raw)}</pre>
                </div>`;
                return;
            }

            if (!data.ok) {
                body.innerHTML = `<div class="vm-empty" style="color:var(--color-text-danger)">
                    <i class="fa-solid fa-triangle-exclamation" style="font-size:28px;display:block;margin-bottom:8px"></i>
                    ${escHtml(data.msg || 'Unknown error')}
                </div>`;
                return;
            }

            if (!data.rows || data.rows.length === 0) {
                body.innerHTML = `<div class="vm-empty">
                    <i class="fa-solid fa-inbox" style="font-size:32px;display:block;margin-bottom:10px;color:#ddd"></i>
                    No transactions recorded yet.
                </div>`;
                return;
            }

            let html = '';
            data.rows.forEach(txn => {
                const createdDate = txn.created_at
                    ? new Date(txn.created_at).toLocaleString() : '—';

                let lineRows = '';
                let total = 0;

                if (txn.lines) {
                    txn.lines.split('||').forEach(item => {
                        const parts    = item.split('~~');
                        // parts: [line_id, description, amount, line_date]
                        const desc     = parts[1] || '—';
                        const amt      = parseFloat(parts[2] || 0);
                        const lineDate = parts[3] || '';
                        const dispDate = lineDate
                            ? new Date(lineDate + 'T00:00:00').toLocaleDateString('en-GB',
                                { day: '2-digit', month: 'short', year: 'numeric' })
                            : '—';
                        total += amt;
                        lineRows += `<tr>
                            <td>${escHtml(desc)}</td>
                            <td>${dispDate}</td>
                            <td class="r">${amt.toLocaleString('en-US',
                                { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                        </tr>`;
                    });
                } else {
                    lineRows = '<tr><td colspan="3" style="color:#9ca3af;text-align:center;font-size:11px">No lines</td></tr>';
                }

                const notesHtml = txn.notes
                    ? `<div class="vm-notes"><i class="fa-solid fa-note-sticky"></i> ${escHtml(txn.notes)}</div>`
                    : '';

                html += `<div class="txn-card" id="txncard-${txn.id}">
                    <div class="txn-card-header">
                        <div>
                            <span class="txn-card-id">TXN #${txn.id}</span>
                            ${txn.ledger_ref
                                ? `<span style="font-size:10px;color:#5b21b6;margin-left:8px;font-family:monospace">${escHtml(txn.ledger_ref)}</span>`
                                : ''}
                        </div>
                        <div style="display:flex;align-items:center;gap:10px">
                            <span class="txn-card-date">
                                <i class="fa-solid fa-clock" style="font-size:9px"></i> ${createdDate}
                            </span>
                            <button class="btn-del-txn" onclick="deleteTxn(${txn.id}, ${invId})">
                                <i class="fa-solid fa-trash"></i> Delete
                            </button>
                        </div>
                    </div>
                    <table class="txn-lines-table">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th>Date</th>
                                <th class="r">Amount</th>
                            </tr>
                        </thead>
                        <tbody>${lineRows}</tbody>
                    </table>
                    ${notesHtml}
                    <div class="txn-card-footer">
                        <span class="txn-total-lbl"><i class="fa-solid fa-sigma"></i> Total</span>
                        <span class="txn-total-val">${total.toLocaleString('en-US',
                            { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                    </div>
                </div>`;
            });
            body.innerHTML = html;
        })
        .catch(err => {
            document.getElementById('vmBody').innerHTML = `<div class="vm-empty" style="color:#dc2626">
                <i class="fa-solid fa-triangle-exclamation" style="font-size:28px;display:block;margin-bottom:8px"></i>
                Network error: ${escHtml(String(err))}
            </div>`;
        });
}

function deleteTxn(txnId, invId) {
    if (!confirm('Delete this transaction and all its lines? This cannot be undone.')) return;
    const fd = new FormData();
    fd.append('action', 'delete_oa_txn');
    fd.append('txn_id', txnId);
    fetch('oa_ajax.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                const card = document.getElementById('txncard-' + txnId);
                if (card) card.remove();
                const remaining = document.querySelectorAll('#vmBody .txn-card').length;
                if (remaining === 0) {
                    document.getElementById('vmBody').innerHTML =
                        '<div class="vm-empty"><i class="fa-solid fa-inbox" style="font-size:32px;display:block;margin-bottom:10px;color:#ddd"></i>No transactions recorded yet.</div>';
                }
                // Update count badge
                const countEl = document.getElementById('vcount-' + invId);
                const viewBtn = document.getElementById('viewbtn-' + invId);
                if (countEl) {
                    const nw = Math.max(0, (parseInt(countEl.textContent) || 0) - 1);
                    countEl.textContent = nw;
                    if (viewBtn && nw === 0) viewBtn.classList.remove('has-txns');
                }
                showToast('Transaction deleted.', 'success');
            } else {
                showToast(data.msg || 'Delete failed.', 'error');
            }
        })
        .catch(() => showToast('Network error. Please try again.', 'error'));
}

function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// ══════════════════════════════════════════
//  ADD TRANSACTION MODAL
// ══════════════════════════════════════════
let lineCount = 0;

function openModal(invId, invNo, invAmt, ledgerId, ledgerRef, ledgerType, ledgerDate, ledgerAmt) {
    document.getElementById('m-inv-no').textContent   = invNo;
    document.getElementById('m-inv-amt').textContent  = invAmt;
    document.getElementById('m-led-id').textContent   = ledgerId   || '—';
    document.getElementById('m-led-date').textContent = ledgerDate || '—';
    document.getElementById('m-led-amt').textContent  = ledgerAmt  || '—';
    document.getElementById('m-led-type').textContent = ledgerType || '—';
    document.getElementById('m-led-ref').textContent  = ledgerRef  || '—';
    // Hidden fields
    document.getElementById('m-invoice-id').value     = invId;
    document.getElementById('m-ledger-row-id').value  = ledgerId;
    document.getElementById('m-ledger-ref-val').value = ledgerRef;
    // Reset
    lineCount = 0;
    document.getElementById('linesContainer').innerHTML = '';
    document.getElementById('m-notes').value = '';
    updateTotal();
    addLine();   // start with one blank line
    document.getElementById('txnModal').classList.add('open');
}

function closeModal() {
    document.getElementById('txnModal').classList.remove('open');
}

document.getElementById('txnModal').addEventListener('click', function (e) {
    if (e.target === this) closeModal();
});

function addLine() {
    lineCount++;
    const n = lineCount;
    const today = new Date().toISOString().split('T')[0];
    const div = document.createElement('div');
    div.className = 'line-row';
    div.id = 'line-' + n;
    div.innerHTML = `
        <div class="line-desc">
            <div class="line-num">Line ${n}</div>
            <input type="text" class="mf-input line-desc-input" placeholder="Description…" id="desc-${n}">
        </div>
        <div>
            <div class="line-num">Amount</div>
            <input type="number" class="mf-input line-amt-input" placeholder="0.00"
                   step="0.01" min="0" id="amt-${n}" oninput="updateTotal()">
        </div>
        <div>
            <div class="line-num">Date</div>
            <input type="date" class="mf-input line-date-input" id="date-${n}" value="${today}">
        </div>
        <button class="line-del" title="Remove line" onclick="removeLine(${n})">
            <i class="fa-solid fa-trash"></i>
        </button>`;
    document.getElementById('linesContainer').appendChild(div);
    document.getElementById('desc-' + n).focus();
}

function removeLine(n) {
    const el = document.getElementById('line-' + n);
    if (el) { el.remove(); updateTotal(); }
    if (document.querySelectorAll('.line-row').length === 0) addLine();
}

function updateTotal() {
    let total = 0;
    document.querySelectorAll('.line-amt-input').forEach(inp => {
        const v = parseFloat(inp.value);
        if (!isNaN(v) && v > 0) total += v;
    });
    document.getElementById('lineTotal').textContent = total.toFixed(2);
}

function saveTxn() {
    // ── Read invoice ID FIRST, before any modal state changes ──
    const invId     = document.getElementById('m-invoice-id').value;
    const invNo     = document.getElementById('m-inv-no').textContent;
    const ledgerId  = document.getElementById('m-ledger-row-id').value;
    const ledgerRef = document.getElementById('m-ledger-ref-val').value;
    const notes     = document.getElementById('m-notes').value;

    // Collect lines
    const lines = [];
    document.querySelectorAll('.line-row').forEach(row => {
        const id   = row.id.replace('line-', '');
        const desc = (document.getElementById('desc-' + id)?.value || '').trim();
        const amt  = parseFloat(document.getElementById('amt-'  + id)?.value || '0');
        const dt   = document.getElementById('date-' + id)?.value || '';
        if (amt > 0) lines.push({ description: desc, amount: amt, date: dt });
    });

    if (!invId) {
        showToast('No invoice selected. Please close and reopen the modal.', 'error');
        return;
    }
    if (lines.length === 0) {
        showToast('Please enter at least one payment line with an amount greater than 0.', 'error');
        return;
    }

    const btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData();
    fd.append('action',        'save_oa_txn');
    fd.append('invoice_id',    invId);
    fd.append('invoice_no',    invNo);
    fd.append('ledger_row_id', ledgerId);
    fd.append('ledger_ref',    ledgerRef);
    fd.append('notes',         notes);
    lines.forEach((ln, i) => {
        fd.append(`lines[${i}][description]`, ln.description);
        fd.append(`lines[${i}][amount]`,      ln.amount);
        fd.append(`lines[${i}][date]`,        ln.date);
    });

    fetch('oa_ajax.php', { method: 'POST', body: fd })
        .then(r => r.text())
        .then(raw => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Transaction';

            let data;
            try {
                data = JSON.parse(raw.trim().replace(/^\uFEFF/, ''));
            } catch (e) {
                showToast('Server returned invalid response — check console.', 'error');
                console.error('[OA] save response was not JSON:', raw);
                return;
            }

            if (data.ok) {
                showToast(data.msg, 'success');
                closeModal();
                // Update view-button count badge without a page reload
                const countEl = document.getElementById('vcount-' + invId);
                const viewBtn = document.getElementById('viewbtn-' + invId);
                if (countEl) {
                    countEl.textContent = (parseInt(countEl.textContent) || 0) + 1;
                }
                if (viewBtn) viewBtn.classList.add('has-txns');
            } else {
                showToast(data.msg || 'Save failed.', 'error');
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Transaction';
            showToast('Network error. Please try again.', 'error');
        });
}
</script>

<?php include 'footer.php'; ?>