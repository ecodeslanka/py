<?php
include 'config.php';

// ══════════════════════════════════════════════════════════════════
//  AJAX — must be BEFORE header.php
// ══════════════════════════════════════════════════════════════════
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    // Ensure tables & columns
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS primary_invoices (
        id                      INT AUTO_INCREMENT PRIMARY KEY,
        invoice_date            DATE NOT NULL,
        invoice_no              VARCHAR(100) NOT NULL,
        note                    TEXT NULL,
        invoice_capture_date    DATE NULL,
        invoice_capture_time    TIME NULL,
        grn_date                DATE NULL,
        dp_days                 INT NULL,
        company                 VARCHAR(255) NOT NULL,
        invoice_value           DECIMAL(14,2) NULL,
        credit_note             VARCHAR(255) NULL,
        credit_note_value       DECIMAL(14,2) NULL,
        paid                    TINYINT(1) NOT NULL DEFAULT 0,
        scheduled_due_date      DATE NULL,
        scheduled_banking_date  DATE NULL,
        created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    mysqli_query($conn, "ALTER TABLE primary_invoices ADD COLUMN IF NOT EXISTS note TEXT NULL AFTER invoice_no");
    mysqli_query($conn, "ALTER TABLE primary_invoices ADD COLUMN IF NOT EXISTS credit_note_value DECIMAL(14,2) NULL AFTER credit_note");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS pi_settlements (
        id                 INT AUTO_INCREMENT PRIMARY KEY,
        pi_id              INT NOT NULL,
        paid_date          DATE NULL,
        paid_amount        DECIMAL(14,2) NULL,
        cheque_no          VARCHAR(255) NULL,
        cheque_book_id     INT NULL,
        cheque_leave_count INT NULL,
        remarks            TEXT NULL,
        created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_pi (pi_id)
    )");
    mysqli_query($conn, "ALTER TABLE pi_settlements ADD COLUMN IF NOT EXISTS cheque_book_id INT NULL AFTER cheque_no");

    $action = $_GET['action'];

    // ── Save main record ─────────────────────────────────────────
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id                     = intval($_POST['id'] ?? 0);
        $invoice_date           = mysqli_real_escape_string($conn, trim($_POST['invoice_date']           ?? ''));
        $invoice_no             = mysqli_real_escape_string($conn, trim($_POST['invoice_no']             ?? ''));
        $note                   = mysqli_real_escape_string($conn, trim($_POST['note']                   ?? ''));
        $invoice_capture_date   = mysqli_real_escape_string($conn, trim($_POST['invoice_capture_date']   ?? ''));
        $invoice_capture_time   = mysqli_real_escape_string($conn, trim($_POST['invoice_capture_time']   ?? ''));
        $grn_date               = mysqli_real_escape_string($conn, trim($_POST['grn_date']               ?? ''));
        $dp_days                = is_numeric($_POST['dp_days'] ?? '') ? intval($_POST['dp_days']) : null;
        $company                = mysqli_real_escape_string($conn, trim($_POST['company']                ?? ''));
        $invoice_value          = is_numeric($_POST['invoice_value'] ?? '')       ? floatval($_POST['invoice_value'])       : null;
        $credit_note            = mysqli_real_escape_string($conn, trim($_POST['credit_note']            ?? ''));
        $credit_note_value      = is_numeric($_POST['credit_note_value'] ?? '')   ? floatval($_POST['credit_note_value'])   : null;
        $paid                   = isset($_POST['paid']) && $_POST['paid'] == '1' ? 1 : 0;
        $scheduled_banking_date = mysqli_real_escape_string($conn, trim($_POST['scheduled_banking_date'] ?? ''));

        $scheduled_due_date = '';
        if ($invoice_date && $dp_days !== null) {
            $scheduled_due_date = date('Y-m-d', strtotime($invoice_date . ' + ' . $dp_days . ' days'));
        }

        if (!$invoice_date || !$invoice_no || !$company) {
            echo json_encode(['success' => false, 'message' => 'Invoice Date, Invoice No and Company are required.']);
            exit;
        }

        $dp_sql   = $dp_days          !== null ? $dp_days          : 'NULL';
        $iv_sql   = $invoice_value     !== null ? $invoice_value    : 'NULL';
        $cnv_sql  = $credit_note_value !== null ? $credit_note_value: 'NULL';
        $icd_sql  = $invoice_capture_date ? "'$invoice_capture_date'" : 'NULL';
        $ict_sql  = $invoice_capture_time ? "'$invoice_capture_time'" : 'NULL';
        $gd_sql   = $grn_date          ? "'$grn_date'"              : 'NULL';
        $sdd_sql  = $scheduled_due_date? "'$scheduled_due_date'"    : 'NULL';
        $sbd_sql  = $scheduled_banking_date ? "'$scheduled_banking_date'" : 'NULL';
        $cn_sql   = $credit_note       ? "'$credit_note'"           : 'NULL';
        $note_sql = $note              ? "'$note'"                  : 'NULL';

        if ($id > 0) {
            $sql = "UPDATE primary_invoices SET
                invoice_date='$invoice_date', invoice_no='$invoice_no', note=$note_sql,
                invoice_capture_date=$icd_sql, invoice_capture_time=$ict_sql,
                grn_date=$gd_sql, dp_days=$dp_sql, company='$company',
                invoice_value=$iv_sql, credit_note=$cn_sql, credit_note_value=$cnv_sql,
                paid=$paid, scheduled_due_date=$sdd_sql, scheduled_banking_date=$sbd_sql
                WHERE id=$id";
        } else {
            $sql = "INSERT INTO primary_invoices
                (invoice_date,invoice_no,note,invoice_capture_date,invoice_capture_time,
                 grn_date,dp_days,company,invoice_value,credit_note,credit_note_value,
                 paid,scheduled_due_date,scheduled_banking_date)
                VALUES
                ('$invoice_date','$invoice_no',$note_sql,$icd_sql,$ict_sql,
                 $gd_sql,$dp_sql,'$company',$iv_sql,$cn_sql,$cnv_sql,
                 $paid,$sdd_sql,$sbd_sql)";
        }
        $ok = mysqli_query($conn, $sql);
        echo json_encode([
            'success' => (bool)$ok,
            'id'      => ($ok && !$id) ? mysqli_insert_id($conn) : $id,
            'sdd'     => $scheduled_due_date,
            'message' => $ok ? '' : mysqli_error($conn)
        ]);
        exit;
    }

    // ── Get single record ────────────────────────────────────────
    if ($action === 'get') {
        $id  = intval($_GET['id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM primary_invoices WHERE id=$id"));
        echo json_encode(['success' => (bool)$row, 'data' => $row]);
        exit;
    }

    // ── Delete main record ───────────────────────────────────────
    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = intval($_POST['id'] ?? 0);
        mysqli_query($conn, "DELETE FROM pi_settlements WHERE pi_id=$id");
        $ok = mysqli_query($conn, "DELETE FROM primary_invoices WHERE id=$id");
        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    // ── Save settlements ─────────────────────────────────────────
    if ($action === 'save_settlements' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $pi_id = intval($_POST['pi_id'] ?? 0);
        if (!$pi_id) { echo json_encode(['success' => false, 'message' => 'Invalid record.']); exit; }
        $rows_data = json_decode($_POST['rows'] ?? '[]', true);
        if (!is_array($rows_data)) { echo json_encode(['success' => false, 'message' => 'Invalid data.']); exit; }

        // Delete this invoice's old settlement rows first
        mysqli_query($conn, "DELETE FROM pi_settlements WHERE pi_id=$pi_id");

        foreach ($rows_data as $r) {
            $paid_date   = mysqli_real_escape_string($conn, trim($r['paid_date']  ?? ''));
            $paid_amount = is_numeric($r['paid_amount'] ?? '') ? floatval($r['paid_amount']) : 'NULL';
            $cheque_no   = mysqli_real_escape_string($conn, trim($r['cheque_no']  ?? ''));
            $remarks     = mysqli_real_escape_string($conn, trim($r['remarks']    ?? ''));
            $pd_sql      = $paid_date ? "'$paid_date'" : 'NULL';

            $cb_id_sql          = 'NULL';
            $cheque_leave_count = 'NULL';

            if ($cheque_no !== '') {
                $cn_esc = mysqli_real_escape_string($conn, $cheque_no);

                // Find the cheque book this cheque belongs to
                $cb_row = mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT id, leaf_count
                     FROM cheque_books
                     WHERE '$cn_esc' BETWEEN leaf_no_start AND leaf_no_end
                     AND status='Active' LIMIT 1"));

                if ($cb_row) {
                    $cb_id_int   = intval($cb_row['id']);
                    $cb_id_sql   = $cb_id_int;
                    $total_leaves = intval($cb_row['leaf_count']);

                    // Count DISTINCT cheque numbers already issued from this book
                    // across ALL invoices (the ones saved before this delete+reinsert).
                    // Since we just deleted this invoice's rows, this count only
                    // includes OTHER invoices — giving us the correct baseline.
                    $already = mysqli_fetch_assoc(mysqli_query($conn,
                        "SELECT COUNT(DISTINCT cheque_no) AS cnt
                         FROM pi_settlements
                         WHERE cheque_book_id = $cb_id_int
                           AND cheque_no != ''
                           AND cheque_no IS NOT NULL"));
                    $issued_so_far = intval($already['cnt'] ?? 0);

                    // Check if this exact cheque_no was already saved in an earlier
                    // row of THIS same save batch (same invoice, multiple rows with
                    // the same cheque number — count it only once).
                    // We track this with a temporary query after inserting below,
                    // but for the leaf count we need to know: is this cheque_no
                    // new to the book, or already counted in issued_so_far?
                    // Because we deleted this invoice's rows above, we check if
                    // this cheque_no exists anywhere else in the DB for this book.
                    $exists_elsewhere = mysqli_fetch_assoc(mysqli_query($conn,
                        "SELECT COUNT(*) AS cnt
                         FROM pi_settlements
                         WHERE cheque_book_id = $cb_id_int
                           AND cheque_no = '$cn_esc'"));

                    if (intval($exists_elsewhere['cnt']) > 0) {
                        // This cheque_no is already used by another invoice.
                        // It was already counted in issued_so_far.
                        // Remaining = total - issued_so_far (no +1 since already counted).
                        $cheque_leave_count = max(0, $total_leaves - $issued_so_far);
                    } else {
                        // Brand new cheque_no for this book — it adds 1 to issued count.
                        $cheque_leave_count = max(0, $total_leaves - $issued_so_far - 1);
                    }
                }
            }

            mysqli_query($conn, "INSERT INTO pi_settlements
                (pi_id, paid_date, paid_amount, cheque_no, cheque_book_id, cheque_leave_count, remarks)
                VALUES ($pi_id, $pd_sql, $paid_amount, '$cheque_no', $cb_id_sql, $cheque_leave_count, '$remarks')");
        }

        // Auto-update paid flag
        $tp_res  = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COALESCE(SUM(paid_amount),0) as tp FROM pi_settlements WHERE pi_id=$pi_id"));
        $inv_res = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT invoice_value, credit_note_value FROM primary_invoices WHERE id=$pi_id"));
        $tp  = floatval($tp_res['tp']              ?? 0);
        $inv = floatval($inv_res['invoice_value']   ?? 0);
        $cnv = floatval($inv_res['credit_note_value'] ?? 0);
        $net = $inv - $cnv;
        if ($tp > 0 && $net > 0 && $tp >= $net) {
            mysqli_query($conn, "UPDATE primary_invoices SET paid=1 WHERE id=$pi_id");
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // ── Get settlements ──────────────────────────────────────────
    if ($action === 'get_settlements') {
        $pi_id = intval($_GET['pi_id'] ?? 0);
        $res   = mysqli_query($conn,
            "SELECT s.*, cb.leaf_no_start, cb.leaf_no_end, cb.leaf_count,
                    (SELECT COUNT(DISTINCT s2.cheque_no)
                     FROM pi_settlements s2
                     WHERE s2.cheque_book_id = s.cheque_book_id
                       AND s2.cheque_no != '') AS book_used_total
             FROM pi_settlements s
             LEFT JOIN cheque_books cb ON cb.id = s.cheque_book_id
             WHERE s.pi_id = $pi_id ORDER BY s.id");
        $data = [];
        while ($r = mysqli_fetch_assoc($res)) $data[] = $r;
        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }

    // ── Look up cheque book by cheque no ─────────────────────────
    // Returns:
    //   live_left        = total_leaves - DISTINCT cheques already issued
    //                      (before this new cheque is added)
    //   leaves_after_issue = live_left - 1  (what will remain after saving)
    //
    // KEY FIX: use COUNT(DISTINCT cheque_no) so a cheque with 3 invoice
    // rows is counted as ONE used leaf, not three.
    if ($action === 'lookup_cheque_book') {
        $cheque_no = mysqli_real_escape_string($conn, trim($_GET['cheque_no'] ?? ''));
        if (!$cheque_no) { echo json_encode(['success' => false]); exit; }

        $row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT cb.*,
                    (SELECT COUNT(DISTINCT s.cheque_no)
                     FROM pi_settlements s
                     WHERE s.cheque_book_id = cb.id
                       AND s.cheque_no != '') AS used_leaves,
                    CONCAT(COALESCE(NULLIF(b.bank_name,''),cba.bank_code,''),' / ',
                           COALESCE(NULLIF(bb.branch_name,''),cba.branch_code,''),' (',cba.account_no,')') AS bank_label
             FROM cheque_books cb
             LEFT JOIN company_bank_accounts cba ON cba.id = cb.bank_account_id
             LEFT JOIN banks b ON b.bank_code = cba.bank_code
             LEFT JOIN bank_branches bb ON bb.bank_code = cba.bank_code
                                       AND bb.branch_code = cba.branch_code
             WHERE '$cheque_no' BETWEEN cb.leaf_no_start AND cb.leaf_no_end
             LIMIT 1"));

        if ($row) {
            $total_leaves   = intval($row['leaf_count']);
            $already_issued = intval($row['used_leaves']);

            // If this exact cheque_no is already in the DB (re-opening an
            // existing settlement), it was already counted in already_issued
            // so live_left = total - already_issued (no extra -1).
            // If it's brand new, live_left is also total - already_issued,
            // and after_issue = live_left - 1.
            $already_this_cheque = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT COUNT(*) AS cnt
                 FROM pi_settlements
                 WHERE cheque_book_id = {$row['id']}
                   AND cheque_no = '$cheque_no'"));
            $this_cheque_exists = intval($already_this_cheque['cnt']) > 0;

            $live_left = max(0, $total_leaves - $already_issued);

            if ($this_cheque_exists) {
                // Already issued — remaining stays the same (not a new use)
                $leaves_after_issue = $live_left;
            } else {
                // New cheque — will consume 1 more leaf
                $leaves_after_issue = max(0, $live_left - 1);
            }

            $row['live_left']          = $live_left;
            $row['leaves_after_issue'] = $leaves_after_issue;
        }

        echo json_encode(['success' => (bool)$row, 'data' => $row ?: null]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

// ══════════════════════════════════════════════════════════════════
//  PAGE LOAD
// ══════════════════════════════════════════════════════════════════
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS primary_invoices (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    invoice_date            DATE NOT NULL,
    invoice_no              VARCHAR(100) NOT NULL,
    note                    TEXT NULL,
    invoice_capture_date    DATE NULL,
    invoice_capture_time    TIME NULL,
    grn_date                DATE NULL,
    dp_days                 INT NULL,
    company                 VARCHAR(255) NOT NULL,
    invoice_value           DECIMAL(14,2) NULL,
    credit_note             VARCHAR(255) NULL,
    credit_note_value       DECIMAL(14,2) NULL,
    paid                    TINYINT(1) NOT NULL DEFAULT 0,
    scheduled_due_date      DATE NULL,
    scheduled_banking_date  DATE NULL,
    created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
mysqli_query($conn, "ALTER TABLE primary_invoices ADD COLUMN IF NOT EXISTS note TEXT NULL AFTER invoice_no");
mysqli_query($conn, "ALTER TABLE primary_invoices ADD COLUMN IF NOT EXISTS credit_note_value DECIMAL(14,2) NULL AFTER credit_note");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS pi_settlements (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    pi_id              INT NOT NULL,
    paid_date          DATE NULL,
    paid_amount        DECIMAL(14,2) NULL,
    cheque_no          VARCHAR(255) NULL,
    cheque_book_id     INT NULL,
    cheque_leave_count INT NULL,
    remarks            TEXT NULL,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pi (pi_id)
)");
mysqli_query($conn, "ALTER TABLE pi_settlements ADD COLUMN IF NOT EXISTS cheque_book_id INT NULL AFTER cheque_no");

// Filters
$filter_company  = isset($_GET['filter_company'])  ? mysqli_real_escape_string($conn, $_GET['filter_company'])  : '';
$filter_from     = isset($_GET['filter_from'])     ? mysqli_real_escape_string($conn, $_GET['filter_from'])     : '';
$filter_to       = isset($_GET['filter_to'])       ? mysqli_real_escape_string($conn, $_GET['filter_to'])       : '';
$filter_paid     = isset($_GET['filter_paid'])     ? mysqli_real_escape_string($conn, $_GET['filter_paid'])     : '';
$filter_dp_min   = isset($_GET['filter_dp_min'])   && is_numeric($_GET['filter_dp_min']) ? intval($_GET['filter_dp_min'])   : '';
$filter_dp_max   = isset($_GET['filter_dp_max'])   && is_numeric($_GET['filter_dp_max']) ? intval($_GET['filter_dp_max'])   : '';

$where = [];
if ($filter_company)    $where[] = "p.company LIKE '%$filter_company%'";
if ($filter_from)       $where[] = "p.invoice_date >= '$filter_from'";
if ($filter_to)         $where[] = "p.invoice_date <= '$filter_to'";
if ($filter_paid !== '') $where[] = "p.paid = $filter_paid";
if ($filter_dp_min !== '') $where[] = "p.dp_days >= $filter_dp_min";
if ($filter_dp_max !== '') $where[] = "p.dp_days <= $filter_dp_max";
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── cheque_book_info subquery ────────────────────────────────────
//
//  For each settlement row tied to a cheque book, we build a pipe-
//  delimited string:
//    cb_id :: leaf_range :: total_leaves :: leaves_remaining :: cb_company
//
//  leaves_remaining = total_leaves
//                   - COUNT(DISTINCT cheque_no issued from this book
//                           up to and including this settlement row,
//                           ordered by s.id ASC)
//
//  Using COUNT(DISTINCT ...) means: one cheque number with 3 invoice
//  rows counts as ONE used leaf, not three.
//
//  We de-duplicate by cheque_no inside the GROUP_CONCAT so that the
//  same cheque_no used across multiple invoices always shows the same
//  remaining value.
//
$rows_result = mysqli_query($conn,
    "SELECT p.*,
            COALESCE((SELECT SUM(s.paid_amount) FROM pi_settlements s WHERE s.pi_id=p.id),0) AS total_paid_amt,
            (SELECT MAX(s.paid_date) FROM pi_settlements s WHERE s.pi_id=p.id) AS last_paid_date,
            (SELECT GROUP_CONCAT(s.cheque_no ORDER BY s.id SEPARATOR ', ')
             FROM pi_settlements s WHERE s.pi_id=p.id AND s.cheque_no!='') AS cheque_nos,
            (SELECT GROUP_CONCAT(
                CONCAT(
                    cb.id,'::',
                    cb.leaf_no_start,'-',cb.leaf_no_end,'::',
                    cb.leaf_count,'::',
                    (cb.leaf_count - (
                        SELECT COUNT(DISTINCT sx.cheque_no)
                        FROM pi_settlements sx
                        WHERE sx.cheque_book_id = cb.id
                          AND sx.cheque_no != ''
                          AND sx.cheque_no IS NOT NULL
                          AND (
                              SELECT MIN(sy.id)
                              FROM pi_settlements sy
                              WHERE sy.cheque_book_id = cb.id
                                AND sy.cheque_no = sx.cheque_no
                          ) <= (
                              SELECT MIN(sz.id)
                              FROM pi_settlements sz
                              WHERE sz.cheque_book_id = cb.id
                                AND sz.cheque_no = s.cheque_no
                          )
                    )),'::',
                    COALESCE(cb.company,'')
                )
                ORDER BY s.id ASC SEPARATOR '|')
             FROM pi_settlements s
             LEFT JOIN cheque_books cb ON cb.id = s.cheque_book_id
             WHERE s.pi_id = p.id
               AND s.cheque_book_id IS NOT NULL
               AND s.cheque_no != ''
               AND s.cheque_no IS NOT NULL) AS cheque_book_info
     FROM primary_invoices p $where_sql
     ORDER BY p.invoice_date DESC, p.id DESC"
);

$totals = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as cnt,
            SUM(p.invoice_value) as total_value,
            SUM(COALESCE(p.credit_note_value,0)) as total_cn_value,
            SUM(CASE WHEN p.paid=1 THEN 1 ELSE 0 END) as paid_count,
            COALESCE((SELECT SUM(s.paid_amount) FROM pi_settlements s
                      INNER JOIN primary_invoices p2 ON s.pi_id=p2.id
                      " . ($where ? str_replace('p.', 'p2.', $where_sql) : '') . "),0) as total_paid_sum
     FROM primary_invoices p $where_sql"
));

// ── Pre-load ledger credits for CN matching ──────────────────────
$ledger_credits_for_cn = [];
$lcr = mysqli_query($conn,
    "SELECT id, txn_date, transaction_type, customer_reference, credit, company
     FROM ulcl_ledger WHERE credit > 0 ORDER BY txn_date DESC");
if ($lcr) {
    while ($lc = mysqli_fetch_assoc($lcr)) {
        $ledger_credits_for_cn[] = $lc;
    }
}

// ── Bank reconcile status of payment cheques (Payment Cheque ↔ Bank page) ──
//    cheque number without leading zeros → [txn_date, status]
$pi_bank_cleared = [];
try {
    $bcr = mysqli_query($conn, "SELECT cheque_key, txn_date, status FROM pi_chq_recon");
    while ($bcr && ($bc = mysqli_fetch_assoc($bcr))) {
        $num = explode('|', $bc['cheque_key'])[0];
        $pi_bank_cleared[$num] = $bc;
    }
} catch (Throwable $e) { /* reconcile page not used yet */ }

include 'header.php';

// ══════════════════════════════════════════════════════════════════
//  HELPERS
// ══════════════════════════════════════════════════════════════════

function findLedgerCrMatch(array $ledger_credits, float $amount): ?array {
    if ($amount <= 0 || empty($ledger_credits)) return null;
    $near_threshold = max($amount * 0.10, 1000);
    $best = null;
    foreach ($ledger_credits as $lc) {
        $cr = floatval($lc['credit']);
        if ($cr <= 0) continue;
        $diff = abs($amount - $cr);
        if ($best === null || $diff < $best['_diff']) {
            $best = array_merge($lc, [
                '_diff'     => $diff,
                '_is_exact' => ($diff <= 1.00),
                '_is_near'  => ($diff > 1.00 && $diff <= $near_threshold),
            ]);
        }
    }
    return $best;
}

function isValidLedgerMatch(?array $match): bool {
    if (!$match) return false;
    return $match['_is_exact'] || $match['_is_near'];
}

function renderLedgerCrBadge(?array $match): string {
    if (!$match) {
        return '<span style="display:inline-flex;align-items:center;gap:3px;background:#f3f4f6;color:#6b7280;'
             . 'border:1px solid #e5e7eb;padding:1px 6px;border-radius:8px;font-size:9.5px;font-weight:600;">'
             . '<i class="fa-solid fa-circle-minus" style="font-size:8px;"></i> No Ledger CR</span>';
    }
    $txn  = htmlspecialchars($match['transaction_type'] ?? '');
    $cr_pill = '<span style="background:%s;color:#fff;padding:0 4px;border-radius:3px;font-size:8px;margin-left:2px;">CR</span>';
    if ($match['_is_exact']) {
        return '<span style="display:inline-flex;align-items:center;gap:3px;background:#dcfce7;color:#166534;'
             . 'border:1px solid #86efac;padding:1px 6px;border-radius:8px;font-size:9.5px;font-weight:700;">'
             . '<i class="fa-solid fa-circle-check" style="font-size:8px;"></i> ' . $txn
             . sprintf($cr_pill, '#166534') . '</span>';
    }
    if ($match['_is_near']) {
        return '<span style="display:inline-flex;align-items:center;gap:3px;background:#fef3c7;color:#92400e;'
             . 'border:1px solid #fde68a;padding:1px 6px;border-radius:8px;font-size:9.5px;font-weight:700;">'
             . '<i class="fa-solid fa-circle-half-stroke" style="font-size:8px;"></i> ' . $txn
             . sprintf($cr_pill, '#92400e') . '</span>';
    }
    return '<span style="display:inline-flex;align-items:center;gap:3px;background:#fef2f2;color:#991b1b;'
         . 'border:1px solid #fca5a5;padding:1px 6px;border-radius:8px;font-size:9.5px;font-weight:700;">'
         . '<i class="fa-solid fa-circle-xmark" style="font-size:8px;"></i> No CR Match</span>';
}
?>
<style>
/* ═══ BASE ══════════════════════════════════════════════════════ */
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:20px}
.card-title{font-size:16px;font-weight:600;margin-bottom:0;color:#1f2937;display:flex;align-items:center;gap:8px}
.card-header-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px}

/* ═══ BUTTONS ═══════════════════════════════════════════════════ */
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;transition:all .2s;font-family:'Inter',sans-serif;text-decoration:none;white-space:nowrap}
.btn-sm{padding:7px 14px;font-size:13px}
.btn-xs{padding:4px 9px;font-size:11.5px}
.btn-primary{background:#000;color:#fff}.btn-primary:hover{background:#1f2937}
.btn-dark{background:#000;color:#fff}.btn-dark:hover{background:#1f2937}
.btn-light{background:#f9fafb;color:#374151;border:1px solid #d1d5db}.btn-light:hover{background:#f3f4f6}
.btn-danger{background:#ef4444;color:#fff}.btn-danger:hover{background:#dc2626}
.btn-settle{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}.btn-settle:hover{background:#1d4ed8;color:#fff}
.btn-settle.paid{background:#f0fdf4;color:#16a34a;border-color:#86efac}

/* ═══ SUMMARY ═══════════════════════════════════════════════════ */
.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:14px;margin-bottom:20px}
.summary-box{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:16px;position:relative;overflow:hidden}
.summary-box::before{content:'';position:absolute;top:0;left:0;right:0;height:3px}
.summary-box.blue::before{background:linear-gradient(90deg,#3b82f6,#60a5fa)}
.summary-box.green::before{background:linear-gradient(90deg,#22c55e,#4ade80)}
.summary-box.amber::before{background:linear-gradient(90deg,#f59e0b,#fbbf24)}
.summary-box.red::before{background:linear-gradient(90deg,#ef4444,#f87171)}
.summary-box.purple::before{background:linear-gradient(90deg,#8b5cf6,#a78bfa)}
.summary-label{font-size:10.5px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px;font-weight:700}
.summary-value{font-size:19px;font-weight:800;color:#1f2937}
.summary-sub{font-size:10.5px;color:#9ca3af;margin-top:2px}

/* ═══ FILTER BAR ════════════════════════════════════════════════ */
.fbar-group{display:flex;flex-direction:column;gap:4px}
.fbar-label{font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.4px}
.fbar-input{padding:7px 11px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;outline:none;transition:border-color .2s;height:34px;box-sizing:border-box}
.fbar-input:focus{border-color:#000}
.dp-range{display:flex;align-items:center;gap:4px}
.dp-range input{width:62px}

/* ═══ TOOLBAR ═══════════════════════════════════════════════════ */
.toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px}
.search-wrap{position:relative;flex:1;min-width:200px;max-width:320px}
.search-wrap i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:12px;pointer-events:none}
.search-input{width:100%;padding:7px 11px 7px 30px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;outline:none;box-sizing:border-box}
.search-input:focus{border-color:#000}

/* ═══ COMPANY LEGEND ════════════════════════════════════════════ */
.company-legend{display:flex;align-items:center;gap:12px;font-size:11.5px;color:#6b7280;}
.legend-dot{display:inline-flex;align-items:center;gap:5px;font-weight:600;}
.legend-dot span{display:inline-block;width:12px;height:12px;border-radius:3px;border:1px solid rgba(0,0,0,.08);}
.legend-usll span{background:#dbeafe;}
.legend-ulcl span{background:#dcfce7;}

/* ═══ TABLE ═════════════════════════════════════════════════════ */
.table-wrap{max-height:68vh;overflow:auto;border:1px solid #e5e5e5;border-radius:8px}
.data-table{width:100%;border-collapse:collapse;font-size:11.5px}
.data-table thead tr{position:sticky;top:0;z-index:10}
.data-table thead th{background:#0f172a;color:#e2e8f0;padding:8px 9px;text-align:left;font-weight:600;font-size:10.5px;white-space:nowrap;border-bottom:2px solid #1e293b}
.data-table th.num,.data-table td.num{text-align:right}
.data-table th.tc,.data-table td.tc{text-align:center}
.data-table tbody tr{border-bottom:1px solid rgba(0,0,0,.06);transition:background .12s}

.data-table tbody tr.row-usll td{background:#a3eaff;}
.data-table tbody tr.row-ulcl td{background:#ffc8ab;}
.data-table tbody tr.row-usll:hover td{background:#dbeafe !important;}
.data-table tbody tr.row-ulcl:hover td{background:#dcfce7 !important;}
.data-table tbody tr.row-auto-cn-paid td{background:#f0fdf4 !important;}
.data-table tbody tr.row-auto-cn-paid:hover td{background:#dcfce7 !important;}

.data-table td{padding:6px 9px;color:#374151;white-space:nowrap;vertical-align:middle;}
.data-table tfoot td{padding:8px 9px;font-weight:700;color:#e2e8f0;background:#0f172a;font-size:11.5px}

.td-cn{white-space:normal !important;min-width:200px;max-width:270px;vertical-align:top !important;}

.cell-sm{font-size:10.5px;color:#6b7280}
.cell-date{display:inline-block;background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;padding:1px 6px;border-radius:4px;font-size:10.5px;font-weight:600}
.cell-time{display:inline-block;background:#fef3c7;color:#92400e;border:1px solid #fde68a;padding:1px 6px;border-radius:4px;font-size:10.5px;font-weight:600}

.note-tooltip{position:relative;display:inline-block}
.note-pill{display:inline-flex;align-items:center;gap:3px;background:#fef9c3;color:#713f12;border:1px solid #fde68a;padding:1px 6px;border-radius:10px;font-size:10px;font-weight:600;cursor:help;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.note-tooltip .note-tt{visibility:hidden;opacity:0;background:#1f2937;color:#fff;font-size:11px;padding:8px 12px;border-radius:6px;position:absolute;z-index:500;left:0;top:calc(100% + 4px);min-width:180px;max-width:260px;white-space:normal;line-height:1.5;box-shadow:0 4px 12px rgba(0,0,0,.2);transition:opacity .18s;pointer-events:none;font-style:normal;font-weight:400}
.note-tooltip:hover .note-tt{visibility:visible;opacity:1}

.aging-badge{display:inline-flex;align-items:center;gap:3px;padding:2px 6px;border-radius:9px;font-size:10px;font-weight:700}
.aging-ok{background:#dcfce7;color:#166534}
.aging-warn{background:#fef9c3;color:#854d0e}
.aging-high{background:#fee2e2;color:#991b1b}
.aging-none{background:#f3f4f6;color:#9ca3af}

.badge{display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap}
.badge-paid{background:#dcfce7;color:#166534}
.badge-unpaid{background:#fef9c3;color:#854d0e}
.badge-overdue{background:#fee2e2;color:#991b1b}
.badge-auto-paid{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7}

.bal-pos{color:#16a34a;font-weight:700}
.bal-neg{color:#dc2626;font-weight:700}
.bal-zero{color:#6b7280;font-weight:600}
.bal1-pos{color:#d97706;font-weight:700}
.bal1-zero{color:#6b7280;font-weight:600}

.cb-chip{display:inline-flex;align-items:center;gap:3px;background:#ede9fe;color:#5b21b6;border:1px solid #ddd6fe;padding:1px 6px;border-radius:7px;font-size:10px;font-weight:700;white-space:nowrap;margin:1px}

.action-buttons{display:flex;gap:3px;justify-content:center;align-items:center}
.btn-action{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:5px;border:1px solid #e5e7eb;background:#fff;color:#6b7280;cursor:pointer;transition:all .2s;font-size:11px}
.btn-action:hover{transform:translateY(-1px);box-shadow:0 2px 4px rgba(0,0,0,.1)}
.btn-edit-r:hover{background:#000;color:#fff;border-color:#000}
.btn-del-r:hover{background:#ef4444;color:#fff;border-color:#ef4444}

/* ═══ MODALS ════════════════════════════════════════════════════ */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000;display:none;align-items:center;justify-content:center;padding:16px}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:12px;width:96%;max-width:800px;max-height:93vh;display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,.22);overflow:hidden}
.settle-modal-box{background:#fff;border-radius:12px;width:96%;max-width:900px;max-height:93vh;display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,.22);overflow:hidden}
.modal-header{padding:16px 22px 12px;border-bottom:1px solid #f0f0f0;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;background:#fafafa}
.modal-title{font-size:16px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px}
.modal-close{background:none;border:none;cursor:pointer;color:#9ca3af;font-size:22px;line-height:1;padding:0;transition:color .2s}
.modal-close:hover{color:#1f2937}
.modal-body{padding:20px 22px;overflow-y:auto;flex:1}
.modal-footer{padding:12px 22px;border-top:1px solid #f0f0f0;display:flex;justify-content:flex-end;gap:10px;flex-shrink:0;background:#fafafa}

.settle-info-strip{padding:11px 22px;background:#0f172a;border-bottom:1px solid #1e293b;display:flex;gap:20px;flex-wrap:wrap;flex-shrink:0}
.sinfo-cell{display:flex;flex-direction:column;gap:1px}
.sinfo-lbl{font-size:9.5px;color:#94a3b8;text-transform:uppercase;letter-spacing:.4px}
.sinfo-val{font-size:14px;font-weight:700;color:#f1f5f9}
.sinfo-val.green{color:#4ade80}
.sinfo-val.amber{color:#fbbf24}
.sinfo-val.red{color:#f87171}

.settle-table{width:100%;border-collapse:collapse;font-size:12.5px;min-width:680px}
.settle-table th{background:#f9fafb;padding:7px 8px;text-align:left;font-weight:700;color:#6b7280;font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #e5e5e5;white-space:nowrap}
.settle-table td{padding:5px 5px;vertical-align:middle;border-bottom:1px solid #f5f5f5}
.settle-input{width:100%;padding:5px 8px;border:1px solid #d1d5db;border-radius:5px;font-size:12.5px;font-family:'Inter',sans-serif;outline:none;transition:border-color .2s;box-sizing:border-box;background:#fff}
.settle-input:focus{border-color:#1d4ed8;box-shadow:0 0 0 2px rgba(29,78,216,.08)}
.settle-input[type=number]{text-align:right}
.settle-rm{background:none;border:none;cursor:pointer;color:#dc2626;font-size:13px;padding:4px 6px;border-radius:4px;transition:background .15s;line-height:1}
.settle-rm:hover{background:#fef2f2}
.add-settle-btn{display:inline-flex;align-items:center;gap:6px;padding:6px 13px;border:1px dashed #93c5fd;border-radius:6px;background:#fff;color:#1d4ed8;font-size:12.5px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;margin-top:10px;transition:all .2s}
.add-settle-btn:hover{background:#eff6ff;border-color:#1d4ed8}

.cb-hint{display:inline-flex;align-items:center;gap:4px;margin-top:3px;font-size:10.5px;color:#6b7280;white-space:nowrap}
.cb-hint.found{color:#5b21b6;font-weight:600}
.cb-hint .bar-mini{width:50px;height:4px;background:#e5e7eb;border-radius:2px;overflow:hidden;display:inline-block;vertical-align:middle;margin:0 2px}
.cb-hint .bar-fill{height:100%;border-radius:2px}

.settle-totals{display:flex;gap:20px;padding:11px 0;margin-top:10px;border-top:2px solid #e5e5e5;flex-wrap:wrap}
.stotal-cell{display:flex;flex-direction:column;gap:2px}
.stotal-lbl{font-size:9.5px;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px}
.stotal-val{font-size:16px;font-weight:800;color:#1f2937}
.stotal-val.green{color:#16a34a}.stotal-val.red{color:#dc2626}.stotal-val.orange{color:#d97706}.stotal-val.blue{color:#1d4ed8}.stotal-val.purple{color:#7c3aed}

.form-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}
.form-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-label{font-size:11.5px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:.4px}
.form-label .req{color:#ef4444}
.form-input{padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13.5px;font-family:'Inter',sans-serif;color:#1f2937;outline:none;transition:border-color .2s,box-shadow .2s;background:#fff;width:100%;box-sizing:border-box}
.form-input:focus{border-color:#000;box-shadow:0 0 0 3px rgba(0,0,0,.06)}
.form-input.error{border-color:#ef4444}
.form-input[readonly]{background:#f9fafb;color:#6b7280;cursor:default}
textarea.form-input{resize:vertical;min-height:56px}
.sdd-calc-note{font-size:10.5px;color:#6b7280;margin-top:2px}
.sec-head{font-size:10.5px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #f0f0f0;padding-bottom:5px;margin:4px 0 14px}

.toggle-wrap{display:flex;align-items:center;gap:10px;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;background:#fff;cursor:pointer}
.toggle-wrap input[type=checkbox]{width:17px;height:17px;cursor:pointer;accent-color:#16a34a}
.toggle-label{font-size:13.5px;color:#374151;font-weight:500}

input[type=number]::-webkit-inner-spin-button,
input[type=number]::-webkit-outer-spin-button{-webkit-appearance:none;margin:0}
input[type=number]{-moz-appearance:textfield;appearance:textfield}

.empty-state{text-align:center;padding:55px 20px;color:#9ca3af}
.empty-state i{font-size:38px;margin-bottom:12px;display:block}
.empty-state p{font-size:14px;font-weight:500}

#toast{position:fixed;bottom:24px;right:24px;padding:11px 20px;border-radius:8px;font-size:13.5px;font-weight:600;color:#fff;z-index:9999;display:none;box-shadow:0 4px 16px rgba(0,0,0,.18)}
#toast.success{background:#16a34a}
#toast.error{background:#dc2626}

.co-badge-usll{display:inline-block;background:#1d4ed8;color:#fff;font-size:10px;font-weight:700;padding:1px 7px;border-radius:10px;letter-spacing:.3px;}
.co-badge-ulcl{display:inline-block;background:#16a34a;color:#fff;font-size:10px;font-weight:700;padding:1px 7px;border-radius:10px;letter-spacing:.3px;}

@media(max-width:640px){.form-grid-3,.form-grid-2{grid-template-columns:1fr}}
</style>

<!-- Page Header -->
<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h2 class="page-title"><i class="fa-solid fa-file-invoice-dollar"></i> Primary Invoices</h2>
            <p class="page-subtitle">Manage invoices, credit notes, DP days and payment settlements</p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a class="btn btn-light" href="pi_cheque_bank_recon.php">
                <i class="fa-solid fa-money-check-dollar"></i> Cheque Bank Reconcile
            </a>
            <button class="btn btn-primary" onclick="openAddModal()">
                <i class="fa-solid fa-plus"></i> Add New Invoice
            </button>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<?php
$total_cn_val  = floatval($totals['total_cn_value']  ?? 0);
$total_inv_val = floatval($totals['total_value']      ?? 0);
$total_paid_s  = floatval($totals['total_paid_sum']   ?? 0);
$bal1_total    = $total_inv_val - $total_cn_val;
$bal2_total    = $bal1_total - $total_paid_s;
?>
<div class="summary-grid">
    <div class="summary-box blue">
        <div class="summary-label">Total Invoices</div>
        <div class="summary-value"><?php echo number_format($totals['cnt']); ?></div>
        <div class="summary-sub">Filtered results</div>
    </div>
    <div class="summary-box purple">
        <div class="summary-label">Total Invoice Value</div>
        <div class="summary-value" style="font-size:16px;"><?php echo $total_inv_val > 0 ? number_format($total_inv_val, 2) : '—'; ?></div>
        <div class="summary-sub">Sum of invoice values</div>
    </div>
    <div class="summary-box amber">
        <div class="summary-label">After Credit Notes</div>
        <div class="summary-value" style="font-size:16px;color:#d97706;"><?php echo number_format($bal1_total, 2); ?></div>
        <div class="summary-sub">Inv − Credit Note Values</div>
    </div>
    <div class="summary-box green">
        <div class="summary-label">Total Paid</div>
        <div class="summary-value" style="font-size:16px;color:#16a34a;"><?php echo number_format($total_paid_s, 2); ?></div>
        <div class="summary-sub">Sum of settlements</div>
    </div>
    <div class="summary-box red">
        <div class="summary-label">Net Outstanding</div>
        <div class="summary-value" style="font-size:16px;color:<?php echo $bal2_total > 0 ? '#d97706' : ($bal2_total < 0 ? '#dc2626' : '#16a34a'); ?>;">
            <?php echo number_format(abs($bal2_total), 2); ?>
        </div>
        <div class="summary-sub">Inv − CN − Paid</div>
    </div>
    <div class="summary-box blue">
        <div class="summary-label">Fully Paid</div>
        <div class="summary-value" style="color:#16a34a;"><?php echo number_format($totals['paid_count'] ?? 0); ?></div>
        <div class="summary-sub">Invoices marked paid</div>
    </div>
</div>

<!-- Records Table -->
<div class="content-card">
    <div class="card-header-row">
        <h3 class="card-title"><i class="fa-solid fa-table"></i> Invoice Records</h3>
        <form method="GET" action="" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
            <div class="fbar-group">
                <label class="fbar-label">Company</label>
                <select name="filter_company" class="fbar-input" style="width:120px;">
                    <option value="">All Companies</option>
                    <option value="USLL" <?php echo $filter_company==='USLL'?'selected':''; ?>>USLL</option>
                    <option value="ULCL" <?php echo $filter_company==='ULCL'?'selected':''; ?>>ULCL</option>
                </select>
            </div>
            <div class="fbar-group">
                <label class="fbar-label">From</label>
                <input type="date" name="filter_from" class="fbar-input" value="<?php echo htmlspecialchars($filter_from); ?>">
            </div>
            <div class="fbar-group">
                <label class="fbar-label">To</label>
                <input type="date" name="filter_to" class="fbar-input" value="<?php echo htmlspecialchars($filter_to); ?>">
            </div>
            <div class="fbar-group">
                <label class="fbar-label">Status</label>
                <select name="filter_paid" class="fbar-input" style="width:110px;">
                    <option value="">All</option>
                    <option value="1" <?php echo $filter_paid==='1'?'selected':''; ?>>Paid</option>
                    <option value="0" <?php echo $filter_paid==='0'?'selected':''; ?>>Unpaid</option>
                </select>
            </div>
            <div class="fbar-group">
                <label class="fbar-label">DP Days</label>
                <div class="dp-range">
                    <input type="number" name="filter_dp_min" class="fbar-input" placeholder="Min" value="<?php echo $filter_dp_min !== '' ? $filter_dp_min : ''; ?>">
                    <span style="font-size:11px;color:#9ca3af;">–</span>
                    <input type="number" name="filter_dp_max" class="fbar-input" placeholder="Max" value="<?php echo $filter_dp_max !== '' ? $filter_dp_max : ''; ?>">
                </div>
            </div>
            <button type="submit" class="btn btn-dark btn-sm"><i class="fa-solid fa-filter"></i> Filter</button>
            <a href="primary_invoices.php" class="btn btn-light btn-sm"><i class="fa-solid fa-xmark"></i> Clear</a>
        </form>
    </div>

    <div class="toolbar">
        <div class="search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" class="search-input" id="searchBox" placeholder="Search invoice no, company, note…" oninput="doSearch()">
        </div>
        <div class="company-legend">
            <span class="legend-dot legend-usll"><span></span> USLL</span>
            <span class="legend-dot legend-ulcl"><span></span> ULCL</span>
        </div>
        <span id="rowCount" style="font-size:12px;color:#6b7280;"></span>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Invoice Date</th>
                    <th>Invoice No</th>
                    <th>Note</th>
                    <th class="tc">DP</th>
                    <th>Company</th>
                    <th class="num">Invoice Value</th>
                    <th class="tc">Status</th>
                    <th style="min-width:200px;">Credit Note / Auto CN</th>
                    <th class="num" style="background:#1e293b;color:#fbbf24;">Bal (Inv−CN)</th>
                    <th>Cap. Date</th>
                    <th>Cap. Time</th>
                    <th>GRN Date</th>
                    <th class="tc">Aging</th>
                    <th>Sched. Due</th>
                    <th>Bank. Date</th>
                    <th class="num">Total Paid</th>
                    <th class="num" style="background:#1e293b;color:#4ade80;">Net Balance</th>
                    <th>Last Paid</th>
                    <th class="tc">Leaf Balance</th>
                    <th class="tc">Actions</th>
                </tr>
            </thead>
            <tbody id="tableBody">
            <?php if (!$rows_result || mysqli_num_rows($rows_result) === 0): ?>
                <tr><td colspan="21"><div class="empty-state"><i class="fa-solid fa-file-invoice-dollar"></i><p>No records found. Click <strong>Add New Invoice</strong> to get started.</p></div></td></tr>
            <?php else:
                $rn    = 1;
                $today = date('Y-m-d');
                while ($r = mysqli_fetch_assoc($rows_result)):
                    $inv_val    = floatval($r['invoice_value']     ?? 0);
                    $cn_val     = floatval($r['credit_note_value'] ?? 0);
                    $total_paid = floatval($r['total_paid_amt']    ?? 0);

                    $company_upper = strtoupper($r['company'] ?? '');
                    $row_class = '';
                    if ($company_upper === 'USLL')     $row_class = 'row-usll';
                    elseif ($company_upper === 'ULCL') $row_class = 'row-ulcl';

                    $balance1 = $inv_val - $cn_val;
                    $bal1Cl   = $balance1 > 0 ? 'bal1-pos' : 'bal1-zero';

                    $balance2_raw   = $balance1 - $total_paid;
                    $auto_cn_amount = 0.0;
                    $auto_cn_match  = null;
                    $auto_cn_valid  = false;

                    if ($total_paid > 0 && $balance2_raw > 0.005) {
                        $candidate_match = findLedgerCrMatch($ledger_credits_for_cn, $balance2_raw);
                        if (isValidLedgerMatch($candidate_match)) {
                            $auto_cn_amount = $balance2_raw;
                            $auto_cn_match  = $candidate_match;
                            $auto_cn_valid  = true;
                        }
                    }

                    $effective_net = $auto_cn_valid ? 0.0 : $balance2_raw;
                    $bal2Cl = $effective_net > 0.005  ? 'bal-pos'
                            : ($effective_net < -0.005 ? 'bal-neg' : 'bal-zero');

                    $net_inv_for_paid = $balance1;
                    $is_fully_settled = ($total_paid > 0 && $net_inv_for_paid > 0 && $total_paid >= ($net_inv_for_paid - 0.01));
                    $is_paid    = ($r['paid'] == 1) || $auto_cn_valid || $is_fully_settled;
                    $is_overdue = !$is_paid && !empty($r['scheduled_due_date']) && $r['scheduled_due_date'] < $today;

                    if ($auto_cn_valid || $is_fully_settled) $row_class = 'row-auto-cn-paid';

                    $aging_html = '<span class="aging-badge aging-none">—</span>';
                    if (!empty($r['invoice_date'])) {
                        $end_d      = !empty($r['grn_date']) ? $r['grn_date'] : $today;
                        $aging_days = (int)((strtotime($end_d) - strtotime($r['invoice_date'])) / 86400);
                        $no_grn     = empty($r['grn_date']);
                        $acl  = $aging_days <= 15 ? 'aging-ok'   : ($aging_days <= 30 ? 'aging-warn' : 'aging-high');
                        $aico = $aging_days <= 15 ? 'fa-circle-check' : ($aging_days <= 30 ? 'fa-triangle-exclamation' : 'fa-circle-xmark');
                        $albl = ($no_grn ? '~' : '') . $aging_days . 'd';
                        $atip = $no_grn ? "Inv→Today (no GRN): {$aging_days}d" : "Inv→GRN: {$aging_days}d";
                        $aging_html = "<span class='aging-badge $acl' title='$atip'><i class='fa-solid $aico'></i>$albl</span>";
                    }

                    if ($r['paid'] == 1) {
                        $badge = '<span class="badge badge-paid"><i class="fa-solid fa-check"></i> Paid</span>';
                    } elseif ($auto_cn_valid) {
                        $badge = '<span class="badge badge-auto-paid"><i class="fa-solid fa-rotate-left"></i> Auto-Paid</span>';
                    } elseif ($is_fully_settled) {
                        $badge = '<span class="badge badge-paid"><i class="fa-solid fa-check"></i> Paid</span>';
                    } elseif ($total_paid > 0 && $balance2_raw > 0.005 && !$auto_cn_valid) {
                        if ($is_overdue) {
                            $badge = '<span class="badge badge-overdue"><i class="fa-solid fa-exclamation-triangle"></i> Overdue</span>';
                        } else {
                            $badge = '<span class="badge badge-unpaid" style="background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;">'
                                   . '<i class="fa-solid fa-triangle-exclamation"></i> Part-Paid</span>';
                        }
                    } elseif ($is_overdue) {
                        $badge = '<span class="badge badge-overdue"><i class="fa-solid fa-exclamation-triangle"></i> Overdue</span>';
                    } else {
                        $badge = '<span class="badge badge-unpaid"><i class="fa-solid fa-clock"></i> Pending</span>';
                    }

                    $note_html = '<span style="color:#d1d5db;">—</span>';
                    if (!empty($r['note'])) {
                        $short     = mb_strimwidth($r['note'], 0, 26, '…');
                        $note_html = '<div class="note-tooltip"><span class="note-pill"><i class="fa-solid fa-note-sticky"></i> '.htmlspecialchars($short).'</span><div class="note-tt">'.htmlspecialchars($r['note']).'</div></div>';
                    }

                    $cn_display = '';
                    if (!empty($r['credit_note']) || $cn_val > 0) {
                        if (!empty($r['credit_note'])) {
                            $cn_display .= '<span style="font-weight:700;font-size:12px;color:#374151;">'
                                         . htmlspecialchars($r['credit_note']) . '</span>';
                        }
                        if ($cn_val > 0) {
                            $cn_display .= ($cn_display ? ' ' : '')
                                         . '<span style="color:#dc2626;font-size:13px;font-weight:800;">'
                                         . '−' . number_format($cn_val, 2) . '</span>';
                            if ($total_paid > 0) {
                                $cn_display .= '<br>' . renderLedgerCrBadge(findLedgerCrMatch($ledger_credits_for_cn, $cn_val));
                            }
                        }
                    }

                    if ($total_paid > 0 && $balance2_raw > 0.005) {
                        if ($cn_display) $cn_display .= '<hr style="border:none;border-top:1px solid #f0f0f0;margin:4px 0;">';
                        if ($auto_cn_valid) {
                            $cn_display .= '<span style="display:inline-flex;align-items:center;gap:3px;'
                                         . 'background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;'
                                         . 'padding:1px 7px;border-radius:8px;font-size:10px;font-weight:700;">'
                                         . '<i class="fa-solid fa-rotate-left" style="font-size:9px;"></i>'
                                         . ' Auto CN −' . number_format($auto_cn_amount, 2) . '</span>';
                            $cn_display .= '<br>' . renderLedgerCrBadge($auto_cn_match);
                        } else {
                            $cn_display .= '<span style="display:inline-flex;align-items:center;gap:3px;'
                                         . 'background:#fef3c7;color:#92400e;border:1px dashed #fde68a;'
                                         . 'padding:1px 7px;border-radius:8px;font-size:10px;font-weight:700;">'
                                         . '<i class="fa-solid fa-triangle-exclamation" style="font-size:9px;"></i>'
                                         . ' Rem. −' . number_format($balance2_raw, 2) . '</span>';
                            $cn_display .= '<br>'
                                         . '<span style="display:inline-flex;align-items:center;gap:3px;background:#fef2f2;color:#991b1b;'
                                         . 'border:1px solid #fca5a5;padding:1px 6px;border-radius:8px;font-size:9.5px;font-weight:700;">'
                                         . '<i class="fa-solid fa-circle-xmark" style="font-size:8px;"></i> No Ledger CR — not auto-credited</span>';
                        }
                    } elseif ($total_paid > 0 && !$cn_display) {
                        $cn_display .= '<span style="display:inline-flex;align-items:center;gap:3px;'
                                     . 'background:#dcfce7;color:#166534;border:1px solid #86efac;'
                                     . 'padding:1px 6px;border-radius:8px;font-size:9.5px;font-weight:700;"> -</span>';
                    }
                    if (!$cn_display) $cn_display = '<span style="color:#d1d5db;">—</span>';

                    if ($company_upper === 'USLL')
                        $co_badge = '<span class="co-badge-usll">USLL</span>';
                    elseif ($company_upper === 'ULCL')
                        $co_badge = '<span class="co-badge-ulcl">ULCL</span>';
                    else
                        $co_badge = htmlspecialchars($r['company']);

                    // ── Cheque leaf balance column ──────────────────────────────
                    //
                    //  cheque_book_info format (pipe-separated entries):
                    //    cb_id :: leaf_range :: total_leaves :: leaves_remaining :: cb_company
                    //
                    //  leaves_remaining uses COUNT(DISTINCT cheque_no) up to s.id,
                    //  so one cheque with 3 invoice rows = 1 used leaf, same
                    //  remaining shown on every invoice that shares that cheque_no.
                    //
                    //  De-duplicate by cheque_no so the same cheque showing
                    //  across multiple rows only appears once per card.
                    //
                    $leaf_html = '<span style="color:#d1d5db;">—</span>';
                    if (!empty($r['cheque_book_info'])) {
                        $entries   = explode('|', $r['cheque_book_info']);
                        $book_html = [];
                        $seen_cheque_books = []; // track cb_id → leaf_remaining already shown

                        foreach ($entries as $entry) {
                            $parts = explode('::', $entry);
                            if (count($parts) < 4) continue;

                            $cb_id          = intval($parts[0]);
                            $range          = $parts[1];
                            $total_lv       = intval($parts[2]);
                            $row_leaf_count = intval($parts[3]);
                            $cb_company     = strtoupper(trim($parts[4] ?? ''));

                            // De-duplicate: if we already showed this cb_id with
                            // this same remaining count, skip. Different counts
                            // (different cheques from same book) each get a card.
                            $dedup_key = $cb_id . '_' . $row_leaf_count;
                            if (isset($seen_cheque_books[$dedup_key])) continue;
                            $seen_cheque_books[$dedup_key] = true;

                            $pct_left = $total_lv > 0 ? round($row_leaf_count / $total_lv * 100) : 0;

                            if ($cb_company === 'USLL') {
                                $bg = '#dbeafe'; $border = '#93c5fd'; $text = '#1d4ed8';
                                $badge_bg = '#1d4ed8';
                            } elseif ($cb_company === 'ULCL') {
                                $bg = '#dcfce7'; $border = '#86efac'; $text = '#16a34a';
                                $badge_bg = '#16a34a';
                            } else {
                                $bg = '#f3f4f6'; $border = '#d1d5db'; $text = '#374151';
                                $badge_bg = '#6b7280';
                            }

                            if ($pct_left <= 20)     { $bar_fill = '#ef4444'; $icon = 'fa-circle-xmark'; }
                            elseif ($pct_left <= 50) { $bar_fill = '#eab308'; $icon = 'fa-triangle-exclamation'; }
                            else                     { $bar_fill = '#22c55e'; $icon = 'fa-leaf'; }

                            $bar_pct = max(0, min(100, $pct_left));

                            $book_html[$dedup_key] =
                                '<div style="background:'.$bg.';border:1.5px solid '.$border.';border-radius:7px;'
                               .'padding:5px 8px;min-width:115px;margin:1px 0;">'
                               .'<div style="display:flex;align-items:center;gap:5px;margin-bottom:3px;">'
                               .'<i class="fa-solid '.$icon.'" style="font-size:10px;color:'.$text.';flex-shrink:0;"></i>'
                               .'<span style="font-size:15px;font-weight:800;color:'.$text.';line-height:1;">'.$row_leaf_count.'</span>'
                               .'<span style="font-size:9.5px;color:'.$text.';opacity:.7;font-weight:600;">left</span>'
                               .'</div>'
                               .'<div style="height:4px;background:rgba(0,0,0,.1);border-radius:2px;overflow:hidden;margin-bottom:3px;">'
                               .'<div style="height:100%;width:'.$bar_pct.'%;background:'.$bar_fill.';border-radius:2px;"></div>'
                               .'</div>'
                               .'<div style="display:flex;align-items:center;justify-content:space-between;gap:4px;">'
                               .'<span style="font-size:9px;color:'.$text.';opacity:.7;font-weight:600;">'.$range.'</span>'
                               .'<span style="font-size:9px;font-weight:800;padding:1px 5px;border-radius:8px;'
                               .'background:'.$badge_bg.';color:#fff;white-space:nowrap;">'
                               .($cb_company ?: '—').'</span>'
                               .'</div>'
                               .'</div>';
                        }

                        if (!empty($book_html)) {
                            $leaf_html = '<div style="display:flex;flex-direction:column;gap:3px;">'
                                       . implode('', $book_html) . '</div>';
                        }
                    }

                    $sdd_html = '<span style="color:#d1d5db;">—</span>';
                    if (!empty($r['scheduled_due_date'])) {
                        $sty = $is_overdue ? 'background:#fee2e2;color:#991b1b;border-color:#fca5a5;' : '';
                        $sdd_html = '<span class="cell-date" style="'.$sty.'">'.date('d M Y', strtotime($r['scheduled_due_date'])).'</span>';
                    }

                    $pay_btn_class = 'btn btn-settle btn-xs' . ($is_paid ? ' paid' : '');
                    $pay_btn_icon  = $is_paid ? 'check-circle' : 'money-bill-wave';
                    $pay_btn_label = $is_paid ? 'Paid' : 'Pay';
            ?>
                <tr id="tr-<?php echo $r['id']; ?>"
                    class="<?php echo $row_class; ?>"
                    data-search="<?php echo strtolower(htmlspecialchars(($r['invoice_no']??'').' '.($r['company']??'').' '.($r['credit_note']??'').' '.($r['note']??''))); ?>">
                    <td style="color:#9ca3af;font-size:10.5px;"><?php echo $rn++; ?></td>
                    <td><?php echo !empty($r['invoice_date']) ? '<span class="cell-date">'.date('d M Y', strtotime($r['invoice_date'])).'</span>' : '—'; ?></td>
                    <td><strong style="font-size:11.5px;"><?php echo htmlspecialchars($r['invoice_no']); ?></strong></td>
                    <td><?php echo $note_html; ?></td>
                    <td class="tc" style="font-weight:700;"><?php echo $r['dp_days'] !== null ? $r['dp_days'] : '<span style="color:#d1d5db;">—</span>'; ?></td>
                    <td><?php echo $co_badge; ?></td>
                    <td class="num" style="font-weight:700;"><?php echo $inv_val > 0 ? number_format($inv_val, 2) : '—'; ?></td>
                    <td class="tc" id="td-status-<?php echo $r['id']; ?>"><?php echo $badge; ?></td>
                    <td class="td-cn" id="td-cn-<?php echo $r['id']; ?>"><?php echo $cn_display; ?></td>
                    <td class="num" id="td-bal1-<?php echo $r['id']; ?>">
                        <span class="<?php echo $bal1Cl; ?>"><?php echo number_format($balance1, 2); ?></span>
                    </td>
                    <td><?php echo !empty($r['invoice_capture_date']) ? '<span class="cell-date" style="background:#f0fdf4;color:#166534;border-color:#86efac;">'.date('d M Y', strtotime($r['invoice_capture_date'])).'</span>' : '<span style="color:#d1d5db;">—</span>'; ?></td>
                    <td><?php echo !empty($r['invoice_capture_time']) ? '<span class="cell-time">'.date('H:i', strtotime($r['invoice_capture_time'])).'</span>' : '<span style="color:#d1d5db;">—</span>'; ?></td>
                    <td><?php echo !empty($r['grn_date']) ? '<span class="cell-date" style="background:#fdf4ff;color:#7e22ce;border-color:#e9d5ff;">'.date('d M Y', strtotime($r['grn_date'])).'</span>' : '<span style="color:#d1d5db;">—</span>'; ?></td>
                    <td class="tc"><?php echo $aging_html; ?></td>
                    <td><?php echo $sdd_html; ?></td>
                    <td><?php echo !empty($r['scheduled_banking_date']) ? '<span class="cell-date" style="background:#fff7ed;color:#9a3412;border-color:#fdba74;">'.date('d M Y', strtotime($r['scheduled_banking_date'])).'</span>' : '<span style="color:#d1d5db;">—</span>'; ?></td>
                    <td class="num" id="td-paid-<?php echo $r['id']; ?>" style="color:#16a34a;font-weight:700;"><?php echo $total_paid > 0 ? number_format($total_paid, 2) : '<span style="color:#d1d5db;">—</span>'; ?></td>
                    <td class="num" id="td-balance-<?php echo $r['id']; ?>">
                        <span class="<?php echo $bal2Cl; ?>"><?php echo number_format($effective_net, 2); ?></span>
                    </td>
                    <td class="cell-sm" id="td-lastpaid-<?php echo $r['id']; ?>"><?php echo !empty($r['last_paid_date']) ? date('d M Y', strtotime($r['last_paid_date'])) : '—'; ?>
                        <?php
                        // bank status of each cheque on this invoice
                        if (!empty($r['cheque_nos'])) {
                            foreach (array_unique(array_map('trim', explode(',', $r['cheque_nos']))) as $cq) {
                                $cqn = ltrim(preg_replace('/\D/', '', $cq), '0');
                                if ($cqn === '') continue;
                                if (isset($pi_bank_cleared[$cqn])) {
                                    $bc = $pi_bank_cleared[$cqn];
                                    $ok = $bc['status'] === 'reconciled';
                                    echo '<div style="margin-top:2px;"><span title="Bank reconciled" style="display:inline-flex;align-items:center;gap:3px;padding:1px 6px;border-radius:8px;font-size:9.5px;font-weight:700;'
                                       . ($ok ? 'background:#dcfce7;color:#166534;border:1px solid #86efac;' : 'background:#fef3c7;color:#92400e;border:1px solid #fde68a;') . '">'
                                       . '<i class="fa-solid fa-building-columns" style="font-size:8px;"></i> ' . htmlspecialchars($cq) . ' '
                                       . ($bc['txn_date'] ? date('d M', strtotime($bc['txn_date'])) : '') . ($ok ? '' : ' (diff)') . '</span></div>';
                                } else {
                                    echo '<div style="margin-top:2px;"><span title="Not reconciled with the bank statement yet" style="display:inline-flex;align-items:center;gap:3px;padding:1px 6px;border-radius:8px;font-size:9.5px;font-weight:600;background:#f3f4f6;color:#6b7280;border:1px solid #e5e7eb;">'
                                       . '<i class="fa-solid fa-hourglass-half" style="font-size:8px;"></i> ' . htmlspecialchars($cq) . '</span></div>';
                                }
                            }
                        }
                        ?></td>
                    <td class="tc" id="td-cheques-<?php echo $r['id']; ?>"><?php echo $leaf_html; ?></td>
                    <td class="tc">
                        <div class="action-buttons">
                            <button class="<?php echo $pay_btn_class; ?>"
                                    id="paybtn-<?php echo $r['id']; ?>"
                                    onclick="openSettleModal(<?php echo $r['id']; ?>,'<?php echo addslashes(htmlspecialchars($r['invoice_no'])); ?>',<?php echo $inv_val; ?>,<?php echo $cn_val; ?>)">
                                <i class="fa-solid fa-<?php echo $pay_btn_icon; ?>"></i>
                                <?php echo $pay_btn_label; ?>
                            </button>
                            <button class="btn-action btn-edit-r" onclick="editRow(<?php echo $r['id']; ?>)" title="Edit"><i class="fa-solid fa-pen"></i></button>
                            <button class="btn-action btn-del-r" onclick="deleteRow(<?php echo $r['id']; ?>)" title="Delete"><i class="fa-solid fa-trash"></i></button>
                        </div>
                    </td>
                </tr>
            <?php endwhile; endif; ?>
            </tbody>
            <?php if ($rows_result && mysqli_num_rows($rows_result) > 0):
                $tf_bal1 = $total_inv_val - $total_cn_val;
                $tf_bal2 = $tf_bal1 - $total_paid_s;
            ?>
            <tfoot>
                <tr>
                    <td colspan="6" style="text-align:right;font-size:10px;opacity:.6;letter-spacing:.04em;">TOTALS</td>
                    <td class="num"><?php echo number_format($total_inv_val, 2); ?></td>
                    <td colspan="2"></td>
                    <td class="num" style="color:#fbbf24;"><?php echo number_format($tf_bal1, 2); ?></td>
                    <td colspan="6"></td>
                    <td class="num" style="color:#4ade80;"><?php echo number_format($total_paid_s, 2); ?></td>
                    <td class="num" style="color:<?php echo $tf_bal2 > 0 ? '#fbbf24' : ($tf_bal2 < 0 ? '#f87171' : '#4ade80'); ?>;"><?php echo number_format($tf_bal2, 2); ?></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- ═══ ADD / EDIT MODAL ════════════════════════════════════════ -->
<div class="modal-overlay" id="entryModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title"><i class="fa-solid fa-file-invoice-dollar"></i><span id="modalTitleText">Add New Invoice</span></div>
            <button class="modal-close" onclick="closeAddModal()">×</button>
        </div>
        <div class="modal-body">
            <form id="entryForm" onsubmit="return false;">
                <input type="hidden" id="fid" name="id" value="0">
                <div class="form-grid-3" style="margin-bottom:14px;">
                    <div class="form-group">
                        <label class="form-label">Invoice Date <span class="req">*</span></label>
                        <input type="date" class="form-input" id="f_invoice_date" name="invoice_date" onchange="calcScheduledDue()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Invoice No <span class="req">*</span></label>
                        <input type="text" class="form-input" id="f_invoice_no" name="invoice_no" placeholder="e.g. INV-2024-001">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Company <span class="req">*</span></label>
                        <select class="form-input" id="f_company" name="company">
                            <option value="">— Select —</option>
                            <option value="USLL">USLL</option>
                            <option value="ULCL">ULCL</option>
                        </select>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:14px;">
                    <label class="form-label"><i class="fa-solid fa-note-sticky" style="color:#d97706;"></i> Note</label>
                    <textarea class="form-input" id="f_note" name="note" rows="2" placeholder="Internal note or memo…"></textarea>
                </div>
                <hr style="border:none;border-top:1px solid #f0f0f0;margin:4px 0 14px;">
                <div class="sec-head">Capture &amp; GRN</div>
                <div class="form-grid-3" style="margin-bottom:14px;">
                    <div class="form-group">
                        <label class="form-label">Capture Date <small style="font-weight:400;color:#9ca3af;">(IKEA)</small></label>
                        <input type="date" class="form-input" id="f_invoice_capture_date" name="invoice_capture_date">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Capture Time <small style="font-weight:400;color:#9ca3af;">(IKEA)</small></label>
                        <input type="time" class="form-input" id="f_invoice_capture_time" name="invoice_capture_time">
                    </div>
                    <div class="form-group">
                        <label class="form-label">GRN Date</label>
                        <input type="date" class="form-input" id="f_grn_date" name="grn_date">
                    </div>
                </div>
                <hr style="border:none;border-top:1px solid #f0f0f0;margin:4px 0 14px;">
                <div class="sec-head">Financials</div>
                <div class="form-grid-3" style="margin-bottom:14px;">
                    <div class="form-group">
                        <label class="form-label">DP Days</label>
                        <input type="number" step="1" min="0" class="form-input" id="f_dp_days" name="dp_days" placeholder="e.g. 30" oninput="calcScheduledDue()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Invoice Value</label>
                        <input type="number" step="0.01" min="0" class="form-input" id="f_invoice_value" name="invoice_value" placeholder="0.00" oninput="calcFormBalance()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Credit Note Ref</label>
                        <input type="text" class="form-input" id="f_credit_note" name="credit_note" placeholder="e.g. CN-001">
                    </div>
                </div>
                <div class="form-grid-3" style="margin-bottom:14px;">
                    <div class="form-group">
                        <label class="form-label">Credit Note Value</label>
                        <input type="number" step="0.01" min="0" class="form-input" id="f_credit_note_value" name="credit_note_value" placeholder="0.00" oninput="calcFormBalance()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Balance after CN <small style="color:#9ca3af;font-weight:400;">(auto)</small></label>
                        <input type="text" class="form-input" id="f_bal1_display" readonly placeholder="—" style="background:#fffbeb;color:#d97706;font-weight:700;">
                    </div>
                    <div class="form-group" style="justify-content:flex-end;">
                        <label class="form-label">Payment Status</label>
                        <label class="toggle-wrap">
                            <input type="checkbox" id="f_paid" name="paid" value="1">
                            <span class="toggle-label">Mark as Paid</span>
                        </label>
                    </div>
                </div>
                <hr style="border:none;border-top:1px solid #f0f0f0;margin:4px 0 14px;">
                <div class="sec-head">Scheduling</div>
                <div class="form-grid-2" style="margin-bottom:14px;">
                    <div class="form-group">
                        <label class="form-label">Scheduled Due Date <small style="color:#9ca3af;font-weight:400;">(auto)</small></label>
                        <input type="date" class="form-input" id="f_scheduled_due_date" name="scheduled_due_date" readonly>
                        <span class="sdd-calc-note" id="sdd_note">= Invoice Date + DP Days</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Scheduled Banking Date</label>
                        <input type="date" class="form-input" id="f_scheduled_banking_date" name="scheduled_banking_date">
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-light" onclick="closeAddModal()">Cancel</button>
            <button class="btn btn-primary" id="saveBtn" onclick="saveEntry()"><i class="fa-solid fa-floppy-disk"></i> Save Invoice</button>
        </div>
    </div>
</div>

<!-- ═══ SETTLEMENT / PAYMENT MODAL ═════════════════════════════ -->
<div class="modal-overlay" id="settleModal">
    <div class="settle-modal-box">
        <div class="modal-header">
            <div class="modal-title"><i class="fa-solid fa-money-bill-wave" style="color:#1d4ed8;"></i><span id="settleTitleText">Payment Settlement</span></div>
            <button class="modal-close" onclick="closeSettleModal()">×</button>
        </div>
        <div class="settle-info-strip">
            <div class="sinfo-cell"><div class="sinfo-lbl">Invoice No</div><div class="sinfo-val" id="si_invoice">—</div></div>
            <div class="sinfo-cell"><div class="sinfo-lbl">Invoice Value</div><div class="sinfo-val" id="si_inv_amount">—</div></div>
            <div class="sinfo-cell"><div class="sinfo-lbl">Credit Note</div><div class="sinfo-val" id="si_cn_val" style="color:#f87171;">—</div></div>
            <div class="sinfo-cell"><div class="sinfo-lbl">Inv−CN</div><div class="sinfo-val amber" id="si_net_inv">—</div></div>
            <div class="sinfo-cell"><div class="sinfo-lbl">Total Paid</div><div class="sinfo-val green" id="si_claimed">0.00</div></div>
            <div class="sinfo-cell"><div class="sinfo-lbl">Balance</div><div class="sinfo-val amber" id="si_balance">—</div></div>
        </div>
        <div class="modal-body">
            <input type="hidden" id="s_pi_id"      value="0">
            <input type="hidden" id="s_inv_amount"  value="0">
            <input type="hidden" id="s_cn_val"      value="0">
            <div style="overflow-x:auto;">
                <table class="settle-table">
                    <thead>
                        <tr>
                            <th style="min-width:120px;">Paid Date</th>
                            <th style="min-width:105px;" class="num">Paid Amount</th>
                            <th style="min-width:150px;">Cheque No</th>
                            <th style="min-width:200px;">Cheque Book Match</th>
                            <th style="min-width:75px;" class="num">Leaves Left</th>
                            <th style="min-width:140px;">Remarks</th>
                            <th style="width:30px;"></th>
                        </tr>
                    </thead>
                    <tbody id="settleRows"></tbody>
                </table>
            </div>
            <button class="add-settle-btn" onclick="addSettleRow()"><i class="fa-solid fa-plus"></i> Add Row</button>
            <div class="settle-totals">
                <div class="stotal-cell"><div class="stotal-lbl">Invoice Value</div><div class="stotal-val" id="st_amount">0.00</div></div>
                <div class="stotal-cell"><div class="stotal-lbl">Credit Note</div><div class="stotal-val red" id="st_cn">0.00</div></div>
                <div class="stotal-cell"><div class="stotal-lbl">Inv−CN</div><div class="stotal-val purple" id="st_net">0.00</div></div>
                <div class="stotal-cell"><div class="stotal-lbl">Total Paid</div><div class="stotal-val blue" id="st_paid">0.00</div></div>
                <div class="stotal-cell"><div class="stotal-lbl">Net Balance</div><div class="stotal-val orange" id="st_balance">0.00</div></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-light" onclick="closeSettleModal()">Cancel</button>
            <button class="btn btn-primary" id="saveSettleBtn" onclick="saveSettlements()"><i class="fa-solid fa-floppy-disk"></i> Save Payments</button>
        </div>
    </div>
</div>

<div id="toast"></div>

<script>
let sRowCount    = 0;
let lookupTimers = {};

function doSearch() {
    const q    = document.getElementById('searchBox').value.toLowerCase().trim();
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

function calcScheduledDue() {
    const invDate = document.getElementById('f_invoice_date').value;
    const dpDays  = document.getElementById('f_dp_days').value;
    const sddEl   = document.getElementById('f_scheduled_due_date');
    const note    = document.getElementById('sdd_note');
    if (invDate && dpDays !== '' && !isNaN(parseInt(dpDays))) {
        const d = new Date(invDate);
        d.setDate(d.getDate() + parseInt(dpDays));
        sddEl.value = d.toISOString().split('T')[0];
        note.textContent = `= ${invDate} + ${dpDays} days`;
        note.style.color = '#16a34a';
    } else {
        sddEl.value = '';
        note.textContent = '= Invoice Date + DP Days';
        note.style.color = '#6b7280';
    }
}
function calcFormBalance() {
    const iv  = parseFloat(document.getElementById('f_invoice_value').value || 0);
    const cnv = parseFloat(document.getElementById('f_credit_note_value').value || 0);
    const bal = iv - cnv;
    const el  = document.getElementById('f_bal1_display');
    el.value  = (iv > 0 || cnv > 0) ? bal.toFixed(2) : '';
    el.style.color = bal > 0 ? '#d97706' : (bal < 0 ? '#dc2626' : '#6b7280');
}

function openAddModal(title) {
    document.getElementById('modalTitleText').textContent = title || 'Add New Invoice';
    document.getElementById('entryModal').classList.add('open');
}
function closeAddModal() { document.getElementById('entryModal').classList.remove('open'); resetForm(); }
function resetForm() {
    document.getElementById('fid').value = '0';
    ['f_invoice_date','f_invoice_no','f_note','f_invoice_capture_date','f_invoice_capture_time',
     'f_grn_date','f_dp_days','f_invoice_value','f_credit_note','f_credit_note_value',
     'f_bal1_display','f_scheduled_due_date','f_scheduled_banking_date'].forEach(id => {
        const el = document.getElementById(id); if (el) el.value = '';
    });
    document.getElementById('f_company').value = '';
    document.getElementById('f_paid').checked  = false;
    document.getElementById('sdd_note').textContent = '= Invoice Date + DP Days';
    document.getElementById('sdd_note').style.color = '#6b7280';
    document.querySelectorAll('.form-input.error').forEach(e => e.classList.remove('error'));
}

function saveEntry() {
    let valid = true;
    ['f_invoice_date','f_invoice_no','f_company'].forEach(id => {
        const el = document.getElementById(id);
        if (!el.value.trim()) { el.classList.add('error'); valid = false; }
        else el.classList.remove('error');
    });
    if (!valid) { showToast('Please fill in all required fields.', 'error'); return; }
    const id  = document.getElementById('fid').value;
    const btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    const fd = new FormData(document.getElementById('entryForm'));
    fd.set('paid', document.getElementById('f_paid').checked ? '1' : '0');
    fetch('primary_invoices.php?action=save', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Invoice';
        if (res.success) { showToast(parseInt(id) > 0 ? 'Invoice updated!' : 'Invoice added!', 'success'); closeAddModal(); setTimeout(() => location.reload(), 800); }
        else showToast('Error: ' + (res.message || 'Unknown error'), 'error');
    })
    .catch(err => { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Invoice'; showToast('Network error: ' + err.message, 'error'); });
}

function editRow(id) {
    fetch('primary_invoices.php?action=get&id=' + id)
    .then(r => r.json())
    .then(res => {
        if (!res.success || !res.data) { showToast('Could not load record.', 'error'); return; }
        const d = res.data;
        document.getElementById('fid').value                       = '' + d.id;
        document.getElementById('f_invoice_date').value            = d.invoice_date           || '';
        document.getElementById('f_invoice_no').value              = d.invoice_no             || '';
        document.getElementById('f_note').value                    = d.note                   || '';
        document.getElementById('f_invoice_capture_date').value    = d.invoice_capture_date   || '';
        document.getElementById('f_invoice_capture_time').value    = d.invoice_capture_time ? d.invoice_capture_time.substring(0,5) : '';
        document.getElementById('f_grn_date').value                = d.grn_date               || '';
        document.getElementById('f_dp_days').value                 = d.dp_days                || '';
        document.getElementById('f_company').value                 = d.company                || '';
        document.getElementById('f_invoice_value').value           = d.invoice_value          || '';
        document.getElementById('f_credit_note').value             = d.credit_note            || '';
        document.getElementById('f_credit_note_value').value       = d.credit_note_value      || '';
        document.getElementById('f_paid').checked                  = d.paid == 1;
        document.getElementById('f_scheduled_due_date').value      = d.scheduled_due_date     || '';
        document.getElementById('f_scheduled_banking_date').value  = d.scheduled_banking_date || '';
        calcFormBalance();
        if (d.invoice_date && d.dp_days) {
            document.getElementById('sdd_note').textContent = `= ${d.invoice_date} + ${d.dp_days} days`;
            document.getElementById('sdd_note').style.color = '#16a34a';
        }
        openAddModal('Edit Invoice');
    })
    .catch(() => showToast('Failed to load record.', 'error'));
}

function deleteRow(id) {
    if (!confirm('Delete this invoice and all payment records? This cannot be undone.')) return;
    const fd = new FormData(); fd.append('id', id);
    fetch('primary_invoices.php?action=delete', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (res.success) { document.getElementById('tr-' + id)?.remove(); showToast('Invoice deleted.', 'success'); }
        else showToast('Delete failed.', 'error');
    })
    .catch(() => showToast('Network error.', 'error'));
}

/* ════ SETTLEMENT MODAL ═════════════════════════════════════════ */
function openSettleModal(piId, invoiceNo, amount, cnVal) {
    sRowCount = 0;
    document.getElementById('s_pi_id').value      = piId;
    document.getElementById('s_inv_amount').value = amount;
    document.getElementById('s_cn_val').value      = cnVal || 0;
    document.getElementById('settleTitleText').textContent = 'Payments — ' + invoiceNo;
    document.getElementById('si_invoice').textContent     = invoiceNo;
    document.getElementById('si_inv_amount').textContent  = parseFloat(amount).toFixed(2);
    document.getElementById('si_cn_val').textContent      = parseFloat(cnVal || 0).toFixed(2);
    const netInv = parseFloat(amount) - parseFloat(cnVal || 0);
    document.getElementById('si_net_inv').textContent = netInv.toFixed(2);
    document.getElementById('st_amount').textContent  = parseFloat(amount).toFixed(2);
    document.getElementById('st_cn').textContent      = parseFloat(cnVal || 0).toFixed(2);
    document.getElementById('st_net').textContent     = netInv.toFixed(2);
    document.getElementById('settleRows').innerHTML   = '';

    fetch('primary_invoices.php?action=get_settlements&pi_id=' + piId)
    .then(r => r.json())
    .then(res => {
        if (res.success && res.data.length) res.data.forEach(s => addSettleRow(s));
        else addSettleRow();
        recalcSettle();
    })
    .catch(() => { addSettleRow(); recalcSettle(); });

    document.getElementById('settleModal').classList.add('open');
}
function closeSettleModal() { document.getElementById('settleModal').classList.remove('open'); }

function addSettleRow(data) {
    sRowCount++;
    const rowId = sRowCount;
    const pd    = data?.paid_date         || '';
    const pa    = data?.paid_amount       || '';
    const cn    = escAttr(data?.cheque_no  || '');
    // cheque_leave_count stored in DB = correct locked value (COUNT DISTINCT based)
    const cl    = data?.cheque_leave_count !== null && data?.cheque_leave_count !== undefined
                  ? data.cheque_leave_count : '';
    const rm    = escAttr(data?.remarks    || '');

    const tr = document.createElement('tr');
    tr.id = 'sr' + rowId;
    tr.dataset.rowid = rowId;
    tr.innerHTML = `
        <td><input type="date"   class="settle-input" value="${pd}"></td>
        <td><input type="number" class="settle-input" step="0.01" min="0" placeholder="0.00" value="${pa}" oninput="recalcSettle()"></td>
        <td>
            <input type="text" class="settle-input" id="cn_${rowId}" placeholder="e.g. 000015" value="${cn}"
                   oninput="debounceLookup(${rowId})" autocomplete="off">
        </td>
        <td>
            <div id="cb_hint_${rowId}" class="cb-hint">
                <i class="fa-solid fa-circle-question" style="color:#d1d5db;"></i>
                <span id="cb_hint_text_${rowId}">type cheque no…</span>
            </div>
        </td>
        <td><input type="number" class="settle-input" id="cl_${rowId}" step="1" min="0" placeholder="—" value="${cl}" readonly
                   style="background:#f9fafb;color:#5b21b6;font-weight:700;cursor:default;text-align:right;"
                   title="Leaves remaining after this cheque is issued"></td>
        <td><input type="text"   class="settle-input" placeholder="Remarks" value="${rm}"></td>
        <td><button class="settle-rm" onclick="this.closest('tr').remove();recalcSettle();" title="Remove"><i class="fa-solid fa-xmark"></i></button></td>`;
    document.getElementById('settleRows').appendChild(tr);

    // Re-trigger lookup for existing rows to refresh the hint bar
    if (cn) setTimeout(() => lookupChequeBook(rowId, cn), 100);
}

function debounceLookup(rowId) {
    clearTimeout(lookupTimers[rowId]);
    lookupTimers[rowId] = setTimeout(() => {
        const val = document.getElementById('cn_' + rowId)?.value?.trim();
        lookupChequeBook(rowId, val);
    }, 450);
}

function lookupChequeBook(rowId, chequeNo) {
    const hintWrap = document.getElementById('cb_hint_' + rowId);
    if (!hintWrap) return;

    if (!chequeNo) {
        hintWrap.className = 'cb-hint';
        hintWrap.innerHTML = '<i class="fa-solid fa-circle-question" style="color:#d1d5db;"></i><span id="cb_hint_text_'+rowId+'">type cheque no…</span>';
        const clInput = document.getElementById('cl_' + rowId);
        if (clInput) clInput.value = '';
        return;
    }

    hintWrap.className = 'cb-hint';
    hintWrap.innerHTML = '<i class="fa-solid fa-spinner fa-spin" style="color:#9ca3af;"></i><span>looking up…</span>';

    fetch('primary_invoices.php?action=lookup_cheque_book&cheque_no=' + encodeURIComponent(chequeNo))
    .then(r => r.json())
    .then(res => {
        if (!res.success || !res.data) {
            hintWrap.className = 'cb-hint';
            hintWrap.innerHTML = '<i class="fa-solid fa-circle-xmark" style="color:#fca5a5;"></i><span>not in any book</span>';
            return;
        }
        const b           = res.data;
        const total       = parseInt(b.leaf_count      || 0);
        const liveLeft    = parseInt(b.live_left        || 0);
        const afterIssue  = parseInt(b.leaves_after_issue ?? Math.max(0, liveLeft - 1));

        // Update the leaf count field with the server-computed value
        const clInput = document.getElementById('cl_' + rowId);
        if (clInput) clInput.value = afterIssue;

        // Bar shows depletion BEFORE this cheque
        const usedSoFar = total - liveLeft;
        const barPct    = total > 0 ? Math.min(100, Math.round(usedSoFar / total * 100)) : 0;
        const color     = barPct < 50 ? '#22c55e' : (barPct < 80 ? '#f59e0b' : '#ef4444');
        const label     = escH(b.leaf_no_start) + '–' + escH(b.leaf_no_end);
        const bank      = escH((b.bank_label || '').replace(/^\s*\/\s*/,'').replace(/\s*\/\s*$/,''));

        hintWrap.className = 'cb-hint found';
        hintWrap.innerHTML = `
            <i class="fa-solid fa-book-bookmark" style="color:#7c3aed;"></i>
            <span>[${escH(b.company)}] ${label}</span>
            <span class="bar-mini"><span class="bar-fill" style="width:${100-barPct}%;background:${color};display:block;height:100%;border-radius:2px;"></span></span>
            <span style="font-weight:700;color:${color};">${afterIssue} left / ${total}</span>
            <span style="color:#9ca3af;font-size:9.5px;">${bank}</span>`;
    })
    .catch(() => {
        hintWrap.className = 'cb-hint';
        hintWrap.innerHTML = '<i class="fa-solid fa-circle-exclamation" style="color:#fca5a5;"></i><span>lookup failed</span>';
    });
}

function recalcSettle() {
    let total = 0;
    document.querySelectorAll('#settleRows tr').forEach(tr => {
        const v = parseFloat(tr.querySelectorAll('input[type=number]')[0]?.value || 0);
        if (v > 0) total += v;
    });
    const amount  = parseFloat(document.getElementById('s_inv_amount').value || 0);
    const cnVal   = parseFloat(document.getElementById('s_cn_val').value     || 0);
    const netInv  = amount - cnVal;
    const balance = netInv - total;
    document.getElementById('si_claimed').textContent = total.toFixed(2);
    document.getElementById('si_balance').textContent = balance.toFixed(2);
    const balEl = document.getElementById('si_balance');
    balEl.className = 'sinfo-val ' + (balance > 0 ? 'amber' : (balance < 0 ? 'red' : 'green'));
    document.getElementById('st_paid').textContent    = total.toFixed(2);
    document.getElementById('st_balance').textContent = balance.toFixed(2);
    document.getElementById('st_balance').className   = 'stotal-val ' + (balance > 0 ? 'orange' : (balance < 0 ? 'red' : 'green'));
}

function saveSettlements() {
    const piId = document.getElementById('s_pi_id').value;
    const trs  = document.querySelectorAll('#settleRows tr');
    if (!trs.length) { showToast('Add at least one payment row.', 'error'); return; }

    const rows = [];
    let valid  = true;
    trs.forEach(tr => {
        const inputs    = tr.querySelectorAll('input');
        const dateVal   = inputs[0]?.value || '';
        const amt       = parseFloat(inputs[1]?.value || 0);
        if (!(amt > 0)) valid = false;
        const chequeNo  = inputs[2]?.value || '';
        // inputs[3] = leaves left (read-only, set by server via lookup)
        const leafCount = inputs[3]?.value || '';
        const remark    = inputs[4]?.value || '';
        rows.push({ paid_date: dateVal, paid_amount: amt, cheque_no: chequeNo,
                    cheque_leave_count: leafCount, remarks: remark });
    });
    if (!valid) { showToast('Each row must have a paid amount > 0.', 'error'); return; }

    const btn = document.getElementById('saveSettleBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData();
    fd.append('pi_id', piId);
    fd.append('rows', JSON.stringify(rows));

    fetch('primary_invoices.php?action=save_settlements', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Payments';
        if (res.success) {
            showToast('Payments saved!', 'success');
            closeSettleModal();
            setTimeout(() => location.reload(), 800);
        } else {
            showToast('Error: ' + (res.message || 'Failed'), 'error');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Payments';
        showToast('Network error: ' + err.message, 'error');
    });
}

function escAttr(v){ return (v||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }
function escH(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function showToast(msg, type){
    const t = document.getElementById('toast');
    t.textContent = msg; t.className = type; t.style.display = 'block';
    clearTimeout(t._t); t._t = setTimeout(() => t.style.display = 'none', 3500);
}
document.getElementById('entryModal').addEventListener('click',  e => { if(e.target===e.currentTarget) closeAddModal(); });
document.getElementById('settleModal').addEventListener('click', e => { if(e.target===e.currentTarget) closeSettleModal(); });
document.addEventListener('keydown', e => { if(e.key==='Escape'){ closeAddModal(); closeSettleModal(); } });
</script>
<?php include 'footer.php'; ?>