<?php
// ============================================================
//  sampath_super_payment.php  –  Sampath Super Payments (Cheque Only)
//
//  Full invoice-list report (like GRN Report), each row independently
//  payable/settleable:
//    Secondary Amount = raw secondary-invoice final_bill_amount
//    GRN Rejected     = SUM of matching grn_headers.total_amount (any
//                        status — not filtered) for that invoice_no
//    Final Amount     = Secondary Amount − GRN Rejected
//    Credit Note Applied = SUM of credit notes applied directly to this
//                        invoice (independent of any cheque payment;
//                        sampath_invoice_credit_notes)
//    Balance           = Final Amount − Credit Note Applied
//    Settle Amount      = SUM of everything ever paid via cheque against
//                        this invoice (sampath_super_payment_invoices) —
//                        can build up over MULTIPLE partial payments
//    Diff               = Balance − Settle Amount (what's still owed)
//  "Pay" opens a modal to record one more cheque payment (full or
//  partial) against that single invoice. Cheques are deduped/merged by
//  bank+branch+cheque_no (existing `cheques` UNIQUE KEY), mirrored into
//  ../yderp/config.php's database exactly as before.
//
//  EDIT PAYMENT: every payment in the per-invoice payment history can be
//  edited (amount, cheque no., bank, branch, dates, reference, remarks).
//  The cheque register in BOTH databases is re-synced:
//    • same cheque  → its total_amount is adjusted by (new − old)
//    • cheque moved → old cheque is deducted (or deleted if no other
//                     payment uses it) and the new cheque is merged/added
// ============================================================
ob_start();
include 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

/* PHP 8.1+ defaults mysqli to throw on any SQL error instead of
   returning false — every handler here expects the classic `if (!$res)`
   pattern, so turn that off for the whole request. */
mysqli_report(MYSQLI_REPORT_OFF);

/* ── Second-database connection (../yderp/config.php) ─────────── */
function sampath_get_yderp_conn() {
    static $yconn = null;
    static $tried = false;
    if ($tried) return $yconn;
    $tried = true;

    $path = __DIR__ . '/../yderp/config.php';
    if (!is_file($path)) return null;
    $src = @file_get_contents($path);
    if ($src === false) return null;

    $creds = [];
    foreach (['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME'] as $const) {
        if (preg_match("/define\\(\\s*['\"]" . $const . "['\"]\\s*,\\s*['\"](.*?)['\"]\\s*\\)/s", $src, $m)) {
            $creds[$const] = $m[1];
        }
    }
    if (count($creds) < 4) return null;

    $prevReportMode = mysqli_report(MYSQLI_REPORT_OFF);
    try {
        $link = mysqli_init();
        if ($link) {
            @mysqli_options($link, MYSQLI_OPT_CONNECT_TIMEOUT, 3);
            $ok = @mysqli_real_connect(
                $link, $creds['DB_HOST'], $creds['DB_USER'], $creds['DB_PASS'], $creds['DB_NAME']
            );
            $yconn = $ok ? $link : null;
        }
    } catch (\Throwable $e) {
        $yconn = null;
    } finally {
        mysqli_report($prevReportMode);
    }

    if ($yconn) @mysqli_set_charset($yconn, 'utf8mb4');
    return $yconn;
}

function sampath_ensure_cheques_table($c) {
    if (!$c) return;
    mysqli_query($c, "CREATE TABLE IF NOT EXISTS cheques (
        id                   INT           AUTO_INCREMENT PRIMARY KEY,
        invoice_payment_id   INT           NULL,
        field_summary_id     INT           NULL,
        t_code               VARCHAR(50)   NULL,
        cheque_no            VARCHAR(100)  NOT NULL,
        cheque_date          DATE          NULL,
        amount               DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        total_amount         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        bank_code            VARCHAR(50)   NULL,
        bank_name            VARCHAR(150)  NULL,
        branch_code          VARCHAR(50)   NULL,
        branch_name          VARCHAR(150)  NULL,
        status               VARCHAR(30)   NOT NULL DEFAULT 'pending',
        created_at           TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
        updated_at           TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        received_date        DATE          NULL,
        due_date             DATE          NULL,
        bulk_deposit         VARCHAR(200)  NULL,
        acc_holder_name      VARCHAR(200)  NULL,
        bulk_flag            INT           NULL,
        sampath              VARCHAR(50)   NULL,
        sampath_description  TEXT          NULL,
        sampath_payment_id   INT           NULL,
        UNIQUE KEY uq_cheque_bank_branch (cheque_no, bank_code, branch_code),
        INDEX idx_pid  (invoice_payment_id),
        INDEX idx_cno  (cheque_no),
        INDEX idx_bank (bank_code, branch_code),
        INDEX idx_sampath_pid (sampath_payment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach ([
        'sampath'             => "VARCHAR(50)  NULL",
        'sampath_description' => "TEXT         NULL",
        'received_date'       => "DATE         NULL",
        'sampath_payment_id'  => "INT          NULL",
    ] as $col => $def) {
        $chk = mysqli_query($c, "SHOW COLUMNS FROM cheques LIKE '$col'");
        if (!$chk || mysqli_num_rows($chk) === 0) {
            mysqli_query($c, "ALTER TABLE cheques ADD COLUMN $col $def");
        }
    }

    static $relaxed = [];
    $connKey = spl_object_id($c);
    if (empty($relaxed[$connKey])) {
        $relaxed[$connKey] = true;
        $dbRes = mysqli_query($c, "SELECT DATABASE() AS db");
        $dbRow = $dbRes ? mysqli_fetch_assoc($dbRes) : null;
        $dbName = $dbRow['db'] ?? '';
        if ($dbName !== '') {
            $dbNameEsc = mysqli_real_escape_string($c, $dbName);
            $fkRes = mysqli_query($c, "
                SELECT CONSTRAINT_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = '$dbNameEsc'
                  AND TABLE_NAME = 'cheques'
                  AND COLUMN_NAME = 'invoice_payment_id'
                  AND REFERENCED_TABLE_NAME IS NOT NULL");
            if ($fkRes) {
                while ($fk = mysqli_fetch_assoc($fkRes)) {
                    $fkName = $fk['CONSTRAINT_NAME'] ?? '';
                    if ($fkName !== '') {
                        @mysqli_query($c, "ALTER TABLE cheques DROP FOREIGN KEY `$fkName`");
                    }
                }
            }
        }
        @mysqli_query($c, "ALTER TABLE cheques MODIFY COLUMN invoice_payment_id INT NULL");
    }
}

/* GRN-reject subquery fragment, reused wherever we need it.
   COLLATE utf8mb4_general_ci: grn_headers.invoice_no and
   secondary_invoice_import_details.bill_no can live under different
   default collations depending on when each table was created — without
   forcing a common collation MySQL throws "Illegal mix of collations".
   No status filter at all: any GRN row for this invoice_no counts. */
function grn_reject_subquery($alias = 'd') {
    return "COALESCE((SELECT SUM(ABS(g.total_amount)) FROM grn_headers g
             WHERE TRIM(UPPER(g.invoice_no)) COLLATE utf8mb4_general_ci
                 = TRIM(UPPER($alias.bill_no)) COLLATE utf8mb4_general_ci), 0)";
}

/* Re-sync one connection's `cheques` table after a payment is EDITED.
   $old / $new = ['cheque_no','bank_code','branch_code','amount', ...]
   (raw, unescaped values — escaped here per connection).
   $remainOld = how many OTHER payments still use the old cheque.
     • Same cheque identity → total_amount adjusted by (new − old)
     • Different cheque     → old one deducted (or deleted if nobody else
                              uses it), new one merged/inserted */
function sampath_cheque_apply_edit($c, array $old, array $new, $remainOld, $desc, $payment_id) {
    if (!$c) return false;
    sampath_ensure_cheques_table($c);
    $e = function ($v) use ($c) { return mysqli_real_escape_string($c, (string) $v); };

    $oNo     = $e($old['cheque_no']);
    $oBank   = $e($old['bank_code']);
    $oBranch = $e($old['branch_code']);
    $nNo         = $e($new['cheque_no']);
    $nBank       = $e($new['bank_code']);
    $nBranch     = $e($new['branch_code']);
    $nBankName   = $e($new['bank_name']);
    $nBranchName = $e($new['branch_name']);
    $nChqDate    = $e($new['cheque_date']);
    $nRecDate    = $e($new['received_date']);
    $descEsc     = $e($desc);
    $oldAmt      = round((float) $old['amount'], 2);
    $newAmt      = round((float) $new['amount'], 2);
    $payment_id  = (int) $payment_id;

    $norm = function ($v) { return strtoupper(trim((string) $v)); };
    $sameCheque = $norm($old['cheque_no'])   === $norm($new['cheque_no'])
               && $norm($old['bank_code'])   === $norm($new['bank_code'])
               && $norm($old['branch_code']) === $norm($new['branch_code']);

    $oldWhere = "cheque_no='$oNo' AND bank_code='$oBank' AND branch_code='$oBranch' AND sampath='Sampath Cheque'";
    $ok = true;

    if ($sameCheque) {
        $chk = mysqli_query($c, "SELECT id FROM cheques WHERE $oldWhere LIMIT 1");
        if ($chk && mysqli_num_rows($chk) > 0) {
            /* Only overwrite the single-payment `amount` column when no
               other payment shares this cheque. */
            $amountSet = ((int) $remainOld > 0) ? '' : "amount = $newAmt,";
            return (bool) mysqli_query($c, "UPDATE cheques SET
                    $amountSet
                    total_amount        = GREATEST(total_amount - $oldAmt + $newAmt, 0),
                    bank_name           = '$nBankName',
                    branch_name         = '$nBranchName',
                    cheque_date         = '$nChqDate',
                    received_date       = '$nRecDate',
                    sampath_description = CONCAT(COALESCE(sampath_description,''), ' || ', '$descEsc'),
                    updated_at          = NOW()
                WHERE $oldWhere");
        }
        // Cheque row missing in this DB → fall through and (re)create it.
    } else {
        // Cheque identity changed → take this payment off the old cheque first.
        if ((int) $remainOld > 0) {
            $ok = (bool) mysqli_query($c, "UPDATE cheques
                SET total_amount = GREATEST(total_amount - $oldAmt, 0), updated_at = NOW()
                WHERE $oldWhere");
        } else {
            $ok = (bool) mysqli_query($c, "DELETE FROM cheques WHERE $oldWhere");
        }
    }

    $ins = mysqli_query($c, "INSERT INTO cheques
        (invoice_payment_id, cheque_no, cheque_date, amount, total_amount,
         bank_code, bank_name, branch_code, branch_name, status, received_date,
         sampath, sampath_description, sampath_payment_id)
        VALUES
        (NULL, '$nNo', '$nChqDate', $newAmt, $newAmt,
         '$nBank', '$nBankName', '$nBranch', '$nBranchName', 'pending', '$nRecDate',
         'Sampath Cheque', '$descEsc', $payment_id)
        ON DUPLICATE KEY UPDATE
         total_amount         = total_amount + VALUES(amount),
         cheque_date          = VALUES(cheque_date),
         sampath_description  = CONCAT(COALESCE(sampath_description,''), ' || ', VALUES(sampath_description)),
         updated_at           = NOW()");
    return $ok && (bool) $ins;
}

// ── Ensure tables exist ───────────────────────────────────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sampath_super_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_date DATE NOT NULL,
    reference VARCHAR(100) NULL,
    remarks TEXT NULL,
    total_invoice_amount DECIMAL(14,2) DEFAULT 0,
    total_credit_note_amount DECIMAL(14,2) DEFAULT 0,
    net_payable DECIMAL(14,2) DEFAULT 0,
    payment_method VARCHAR(20) NOT NULL DEFAULT 'cheque',
    bank_code VARCHAR(50) NULL,
    bank_name VARCHAR(150) NULL,
    branch_code VARCHAR(50) NULL,
    branch_name VARCHAR(150) NULL,
    cheque_no VARCHAR(100) NULL,
    cheque_date DATE NULL,
    received_date DATE NULL,
    created_by VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payment_date (payment_date),
    INDEX idx_cheque_no (cheque_no)
)");
/* Audit columns for the Edit Payment feature */
foreach (['updated_by' => "VARCHAR(100) NULL", 'updated_at' => "DATETIME NULL"] as $col => $def) {
    $chk = mysqli_query($conn, "SHOW COLUMNS FROM sampath_super_payments LIKE '$col'");
    if ($chk && mysqli_num_rows($chk) === 0) {
        mysqli_query($conn, "ALTER TABLE sampath_super_payments ADD COLUMN $col $def");
    }
}
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sampath_super_payment_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    detail_id INT NULL,
    bill_no VARCHAR(100) NULL,
    party_name VARCHAR(255) NULL,
    amount DECIMAL(12,2) DEFAULT 0,
    grn_reject_amount DECIMAL(12,2) DEFAULT 0,
    adjusted_amount DECIMAL(12,2) DEFAULT 0,
    cn_applied DECIMAL(12,2) DEFAULT 0,
    INDEX idx_payment_id (payment_id),
    INDEX idx_bill_no (bill_no)
)");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sampath_invoice_credit_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bill_no VARCHAR(100) NOT NULL,
    cn_id INT NULL,
    grn_no VARCHAR(100) NULL,
    branch_name VARCHAR(150) NULL,
    amount DECIMAL(12,2) DEFAULT 0,
    applied_by VARCHAR(100) NULL,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bill_no (bill_no),
    INDEX idx_cn_id (cn_id)
)");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sampath_invoice_manual_adjustments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bill_no VARCHAR(100) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    adjustment_date DATE NULL,
    note VARCHAR(255) NULL,
    created_by VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bill_no (bill_no)
)");
sampath_ensure_cheques_table($conn);

/* Bank list for the cheque dropdown (this page is cheque-only) */
$banks_list = [];
$br = mysqli_query($conn, "SELECT id, bank_code, bank_name FROM banks WHERE active=1 ORDER BY bank_name ASC");
if ($br) while ($b = mysqli_fetch_assoc($br)) $banks_list[] = $b;

// ── AJAX: GET handlers ──────────────────────────────────────────
if (isset($_GET['ajax'])) {
    ob_end_clean();
    header('Content-Type: application/json');

    if ($_GET['ajax'] === 'list_invoices') {
        $page   = max(1, intval($_GET['page'] ?? 1));
        /* per = rows per page; 0 = View All (no paging) */
        $perRaw = intval($_GET['per'] ?? 25);
        $limit  = in_array($perRaw, [0, 25, 50, 100, 250], true) ? $perRaw : 25;
        $search = mysqli_real_escape_string($conn, trim($_GET['search'] ?? ''));

        /* Status filter:
             outstanding = not paid + partly paid (default — old behaviour)
             unpaid      = nothing paid yet, diff > 0
             partial     = some cheque paid, diff still > 0
             settled     = diff = 0 (fully paid, or fully covered by GRN/CN/adj.)
             all         = everything */
        $status = $_GET['status'] ?? 'outstanding';
        if (!in_array($status, ['outstanding', 'unpaid', 'partial', 'settled', 'all'], true)) $status = 'outstanding';

        $whereSql = '1=1';
        if ($search !== '') {
            $whereSql = "(d.bill_no LIKE '%$search%' OR d.party_name LIKE '%$search%' OR d.customer_name LIKE '%$search%')";
        }

        $grnFrag = grn_reject_subquery('d');

        /* Raw components per invoice */
        $baseSql = "SELECT d.id, d.bill_no, d.party_name, d.customer_name, d.route_name,
                       d.final_bill_amount, d.bill_date, i.delivery_date,
                       $grnFrag AS grn_reject_amount,
                       COALESCE((SELECT SUM(c.amount) FROM sampath_invoice_credit_notes c
                                 WHERE c.bill_no COLLATE utf8mb4_general_ci = d.bill_no COLLATE utf8mb4_general_ci), 0) AS cn_applied,
                       COALESCE((SELECT SUM(m.amount) FROM sampath_invoice_manual_adjustments m
                                 WHERE m.bill_no COLLATE utf8mb4_general_ci = d.bill_no COLLATE utf8mb4_general_ci), 0) AS manual_adjustment,
                       COALESCE((SELECT SUM(spi.amount) FROM sampath_super_payment_invoices spi
                                 WHERE spi.bill_no COLLATE utf8mb4_general_ci = d.bill_no COLLATE utf8mb4_general_ci), 0) AS settle_amount
                FROM secondary_invoice_import_details d
                JOIN secondary_invoice_imports i ON i.id = d.import_id
                WHERE $whereSql";

        /* Balance / Diff / Status — same formulas as computeRowAmounts() in JS */
        $balExpr = "GREATEST(GREATEST(t.final_bill_amount - ABS(t.grn_reject_amount), 0) - t.cn_applied - t.manual_adjustment, 0)";
        $calcSql = "SELECT u.*,
                           CASE WHEN u.diff_amount <= 0.004 THEN 'settled'
                                WHEN u.settle_amount > 0.004 THEN 'partial'
                                ELSE 'unpaid' END AS pay_status
                    FROM (
                        SELECT t.*, $balExpr AS balance,
                               GREATEST($balExpr - t.settle_amount, 0) AS diff_amount
                        FROM ($baseSql) t
                    ) u";

        /* Materialise once into a temp table — the correlated subqueries are
           expensive, and we need counts, totals and rows from the same set. */
        $sqlError = null;
        mysqli_query($conn, "DROP TEMPORARY TABLE IF EXISTS tmp_ssp_list");
        $src = 'tmp_ssp_list';
        if (!mysqli_query($conn, "CREATE TEMPORARY TABLE tmp_ssp_list AS $calcSql")) {
            /* No CREATE TEMPORARY TABLES privilege → fall back to a derived table */
            $src = "($calcSql) x";
        }

        /* Counts for every tab (respecting the search box) */
        $cRes = mysqli_query($conn,
            "SELECT COUNT(*) AS all_c,
                    COALESCE(SUM(pay_status='unpaid'),0)  AS unpaid_c,
                    COALESCE(SUM(pay_status='partial'),0) AS partial_c,
                    COALESCE(SUM(pay_status='settled'),0) AS settled_c
             FROM $src");
        if (!$cRes) $sqlError = mysqli_error($conn);
        $cRow = $cRes ? mysqli_fetch_assoc($cRes) : ['all_c'=>0,'unpaid_c'=>0,'partial_c'=>0,'settled_c'=>0];
        $counts = [
            'unpaid'  => (int) $cRow['unpaid_c'],
            'partial' => (int) $cRow['partial_c'],
            'settled' => (int) $cRow['settled_c'],
            'all'     => (int) $cRow['all_c'],
        ];
        $counts['outstanding'] = $counts['unpaid'] + $counts['partial'];

        switch ($status) {
            case 'unpaid':      $statusWhere = "pay_status = 'unpaid'"; break;
            case 'partial':     $statusWhere = "pay_status = 'partial'"; break;
            case 'settled':     $statusWhere = "pay_status = 'settled'"; break;
            case 'all':         $statusWhere = "1=1"; break;
            default:            $statusWhere = "pay_status IN ('unpaid','partial')";
        }

        $cnt = $counts[$status];
        if ($limit > 0) {
            $pages  = $cnt > 0 ? (int) ceil($cnt / $limit) : 1;
            if ($page > $pages) $page = $pages;
            $offset = ($page - 1) * $limit;
            $limitSql = "LIMIT $limit OFFSET $offset";
        } else {
            $pages = 1; $page = 1; $limitSql = '';
        }

        $res  = mysqli_query($conn, "SELECT * FROM $src WHERE $statusWhere
                                     ORDER BY delivery_date DESC, bill_no ASC $limitSql");
        $rows = [];
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
        } else {
            $sqlError = mysqli_error($conn);
            error_log('sampath_super_payment list_invoices failed: ' . $sqlError);
        }

        /* Totals for the whole filtered set (not just the visible page) */
        $sRes = mysqli_query($conn,
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(final_bill_amount),0) AS total_secondary,
                    COALESCE(SUM(settle_amount),0)     AS total_settled,
                    COALESCE(SUM(diff_amount),0)       AS total_diff
             FROM $src WHERE $statusWhere");
        $stats = $sRes ? mysqli_fetch_assoc($sRes) : null;

        mysqli_query($conn, "DROP TEMPORARY TABLE IF EXISTS tmp_ssp_list");

        echo json_encode(['rows'=>$rows,'total'=>$cnt,'pages'=>$pages,'page'=>$page,'per'=>$limit,
                          'status'=>$status,'counts'=>$counts,'stats'=>$stats,'sql_error'=>$sqlError]);
        exit;
    }

    if ($_GET['ajax'] === 'get_credit_notes') {
        $sql = "SELECT id, grn_no, amount, branch_name, customer_id, status
                FROM sampath_damage_credit_notes
                WHERE status = 'unused'
                ORDER BY created_at DESC";
        $res = mysqli_query($conn, $sql);
        if (!$res) { echo json_encode(['error' => mysqli_error($conn)]); exit; }
        $rows = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $r['customer_name'] = $r['branch_name'];
            $rows[] = $r;
        }
        echo json_encode($rows);
        exit;
    }

    if ($_GET['ajax'] === 'invoice_cns') {
        $billNo = mysqli_real_escape_string($conn, trim($_GET['bill_no'] ?? ''));
        $rows = [];
        if ($billNo !== '') {
            $res = mysqli_query($conn, "SELECT id, cn_id, grn_no, branch_name, amount, applied_at
                                         FROM sampath_invoice_credit_notes
                                         WHERE bill_no = '$billNo'
                                         ORDER BY applied_at ASC");
            if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
        }
        echo json_encode($rows);
        exit;
    }

    if ($_GET['ajax'] === 'invoice_manual_adjustments') {
        $billNo = mysqli_real_escape_string($conn, trim($_GET['bill_no'] ?? ''));
        $rows = [];
        if ($billNo !== '') {
            $res = mysqli_query($conn, "SELECT id, amount, adjustment_date, note, created_by, created_at
                                         FROM sampath_invoice_manual_adjustments
                                         WHERE bill_no = '$billNo'
                                         ORDER BY adjustment_date ASC, id ASC");
            if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
        }
        echo json_encode($rows);
        exit;
    }

    if ($_GET['ajax'] === 'invoice_payments') {
        $billNo = mysqli_real_escape_string($conn, trim($_GET['bill_no'] ?? ''));
        $rows = [];
        if ($billNo !== '') {
            $res = mysqli_query($conn, "SELECT sp.id, sp.payment_date, sp.cheque_date, sp.received_date, sp.cheque_no,
                                                sp.bank_name, sp.bank_code, sp.branch_name, sp.branch_code,
                                                sp.reference, sp.remarks, sp.created_by, sp.created_at,
                                                sp.updated_by, sp.updated_at,
                                                spi.amount AS settle_amount
                                         FROM sampath_super_payment_invoices spi
                                         JOIN sampath_super_payments sp ON sp.id = spi.payment_id
                                         WHERE spi.bill_no = '$billNo'
                                         ORDER BY sp.created_at DESC");
            if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
        }
        echo json_encode($rows);
        exit;
    }

    if ($_GET['ajax'] === 'list_payments') {
        $sql = "SELECT sp.id, sp.payment_date, sp.reference, sp.remarks,
                       sp.net_payable, sp.bank_code, sp.bank_name, sp.branch_code, sp.branch_name,
                       sp.cheque_no, sp.cheque_date, sp.received_date,
                       sp.created_by, sp.created_at,
                       spi.bill_no, spi.party_name, spi.amount AS settle_amount
                FROM sampath_super_payments sp
                JOIN sampath_super_payment_invoices spi ON spi.payment_id = sp.id
                ORDER BY sp.created_at DESC
                LIMIT 200";
        $res = mysqli_query($conn, $sql);
        if (!$res) { echo json_encode(['error' => mysqli_error($conn)]); exit; }
        $rows = [];
        while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
        echo json_encode($rows);
        exit;
    }

    if ($_GET['ajax'] === 'grn_debug') {
        $billNo = trim($_GET['bill_no'] ?? '');
        $out = ['bill_no_searched' => $billNo];
        $dbRes = mysqli_query($conn, "SELECT DATABASE() AS db");
        $out['connected_database'] = $dbRes ? (mysqli_fetch_assoc($dbRes)['db'] ?? null) : null;
        $cntRes = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM grn_headers");
        if ($cntRes) {
            $out['grn_headers_table_reachable'] = true;
            $out['grn_headers_total_rows'] = (int) mysqli_fetch_assoc($cntRes)['cnt'];
        } else {
            $out['grn_headers_table_reachable'] = false;
            $out['grn_headers_error'] = mysqli_error($conn);
        }
        if ($billNo !== '' && !empty($out['grn_headers_table_reachable'])) {
            $billNoEsc = mysqli_real_escape_string($conn, $billNo);
            $trimRes = mysqli_query($conn, "SELECT id, grn_ref, invoice_no, total_amount, status
                                             FROM grn_headers
                                             WHERE TRIM(UPPER(invoice_no)) COLLATE utf8mb4_general_ci
                                                 = TRIM(UPPER('$billNoEsc')) COLLATE utf8mb4_general_ci");
            $out['trim_upper_match_rows'] = [];
            if ($trimRes) while ($r = mysqli_fetch_assoc($trimRes)) $out['trim_upper_match_rows'][] = $r;
        }
        echo json_encode($out, JSON_PRETTY_PRINT);
        exit;
    }

    echo json_encode(['error' => 'Unknown ajax action']);
    exit;
}

// ── AJAX: POST handlers ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];

    // Apply an existing unused credit note directly to an invoice
    if ($action === 'apply_invoice_cn') {
        $cn_id  = (int) ($_POST['cn_id'] ?? 0);
        $bill_no = mysqli_real_escape_string($conn, trim($_POST['bill_no'] ?? ''));
        if (!$cn_id || $bill_no === '') { echo json_encode(['ok'=>false,'msg'=>'Missing credit note or invoice.']); exit; }

        $cnRes = mysqli_query($conn, "SELECT id, grn_no, amount, branch_name, status FROM sampath_damage_credit_notes WHERE id=$cn_id LIMIT 1");
        $cn = $cnRes ? mysqli_fetch_assoc($cnRes) : null;
        if (!$cn) { echo json_encode(['ok'=>false,'msg'=>'Credit note not found.']); exit; }
        if ($cn['status'] !== 'unused') { echo json_encode(['ok'=>false,'msg'=>'This credit note is already used.']); exit; }

        $grn_no      = mysqli_real_escape_string($conn, $cn['grn_no']);
        $branch_name = mysqli_real_escape_string($conn, $cn['branch_name']);
        $amount      = round((float)$cn['amount'], 2);
        $applied_by  = mysqli_real_escape_string($conn, $_SESSION['username'] ?? 'system');

        $ok = mysqli_query($conn, "INSERT INTO sampath_invoice_credit_notes
            (bill_no, cn_id, grn_no, branch_name, amount, applied_by, applied_at)
            VALUES ('$bill_no', $cn_id, '$grn_no', '$branch_name', $amount, '$applied_by', NOW())");
        if (!$ok) { echo json_encode(['ok'=>false,'msg'=>'DB error: '.mysqli_error($conn)]); exit; }

        mysqli_query($conn, "UPDATE sampath_damage_credit_notes SET status='used' WHERE id=$cn_id");
        echo json_encode(['ok'=>true]);
        exit;
    }

    // BULK: apply several unused credit notes to one invoice in one go
    if ($action === 'apply_invoice_cns') {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) json_decode($_POST['cn_ids'] ?? '[]', true)))));
        $bill_no = mysqli_real_escape_string($conn, trim($_POST['bill_no'] ?? ''));
        if (!$ids || $bill_no === '') { echo json_encode(['ok'=>false,'msg'=>'Select at least one credit note.']); exit; }
        $applied_by = mysqli_real_escape_string($conn, $_SESSION['username'] ?? 'system');
        $done = 0; $skipped = 0; $total = 0.0;
        foreach ($ids as $cn_id) {
            $cnRes = mysqli_query($conn, "SELECT id, grn_no, amount, branch_name, status FROM sampath_damage_credit_notes WHERE id=$cn_id LIMIT 1");
            $cn = $cnRes ? mysqli_fetch_assoc($cnRes) : null;
            if (!$cn || $cn['status'] !== 'unused') { $skipped++; continue; }
            $grn_no      = mysqli_real_escape_string($conn, $cn['grn_no']);
            $branch_name = mysqli_real_escape_string($conn, $cn['branch_name']);
            $amount      = round((float)$cn['amount'], 2);
            $ok = mysqli_query($conn, "INSERT INTO sampath_invoice_credit_notes
                (bill_no, cn_id, grn_no, branch_name, amount, applied_by, applied_at)
                VALUES ('$bill_no', $cn_id, '$grn_no', '$branch_name', $amount, '$applied_by', NOW())");
            if (!$ok) { $skipped++; continue; }
            mysqli_query($conn, "UPDATE sampath_damage_credit_notes SET status='used' WHERE id=$cn_id");
            $done++; $total += $amount;
        }
        echo json_encode(['ok'=>$done > 0, 'applied'=>$done, 'skipped'=>$skipped, 'total'=>round($total, 2),
                          'msg'=>$done > 0 ? '' : 'None of the selected credit notes could be applied (already used?).']);
        exit;
    }

    // Remove a credit note that was applied to an invoice — frees it back to unused
    if ($action === 'remove_invoice_cn') {
        $id = (int) ($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'msg'=>'Invalid id.']); exit; }

        $r = mysqli_query($conn, "SELECT cn_id FROM sampath_invoice_credit_notes WHERE id=$id LIMIT 1");
        $row = $r ? mysqli_fetch_assoc($r) : null;
        if (!$row) { echo json_encode(['ok'=>false,'msg'=>'Not found.']); exit; }

        mysqli_query($conn, "DELETE FROM sampath_invoice_credit_notes WHERE id=$id");
        $cn_id = (int) $row['cn_id'];
        if ($cn_id) mysqli_query($conn, "UPDATE sampath_damage_credit_notes SET status='unused' WHERE id=$cn_id");
        echo json_encode(['ok'=>true]);
        exit;
    }

    // Add a manual (freehand) adjustment to an invoice — amount can be
    // positive (a credit, reduces balance) or negative (a debit/charge,
    // increases balance). Independent of the picked-credit-note list.
    if ($action === 'apply_manual_adjustment') {
        $bill_no = mysqli_real_escape_string($conn, trim($_POST['bill_no'] ?? ''));
        $amount  = round((float) ($_POST['amount'] ?? 0), 2);
        $dateRaw = trim($_POST['adjustment_date'] ?? '');
        $date    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw) ? $dateRaw : date('Y-m-d');
        $note    = mysqli_real_escape_string($conn, trim($_POST['note'] ?? ''));
        $created_by = mysqli_real_escape_string($conn, $_SESSION['username'] ?? 'system');

        if ($bill_no === '' || $amount == 0) {
            echo json_encode(['ok'=>false,'msg'=>'Invoice and a non-zero amount are required.']); exit;
        }

        $ok = mysqli_query($conn, "INSERT INTO sampath_invoice_manual_adjustments
            (bill_no, amount, adjustment_date, note, created_by, created_at)
            VALUES ('$bill_no', $amount, '$date', '$note', '$created_by', NOW())");
        if (!$ok) { echo json_encode(['ok'=>false,'msg'=>'DB error: '.mysqli_error($conn)]); exit; }
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'remove_manual_adjustment') {
        $id = (int) ($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'msg'=>'Invalid id.']); exit; }
        $ok = mysqli_query($conn, "DELETE FROM sampath_invoice_manual_adjustments WHERE id=$id");
        echo json_encode(['ok'=>(bool)$ok, 'msg'=>$ok?null:mysqli_error($conn)]);
        exit;
    }

    // Record a new cheque payment (full or partial) against ONE invoice
    if ($action === 'add_payment') {
        $detail_id  = (int) ($_POST['detail_id'] ?? 0);
        $bill_no    = mysqli_real_escape_string($conn, trim($_POST['bill_no']    ?? ''));
        $party_name = mysqli_real_escape_string($conn, trim($_POST['party_name'] ?? ''));
        $settle     = round((float) ($_POST['settle_amount'] ?? 0), 2);
        $grn_reject = round(abs((float) ($_POST['grn_reject_amount'] ?? 0)), 2);
        $adjusted   = round((float) ($_POST['adjusted_amount'] ?? 0), 2);
        $cn_applied = round((float) ($_POST['cn_applied'] ?? 0), 2);

        $reference  = mysqli_real_escape_string($conn, trim($_POST['reference'] ?? ''));
        $remarks    = mysqli_real_escape_string($conn, trim($_POST['remarks']   ?? ''));
        $created_by = mysqli_real_escape_string($conn, $_SESSION['username'] ?? 'system');

        $bank_code   = mysqli_real_escape_string($conn, trim($_POST['bank_code']   ?? ''));
        $bank_name   = mysqli_real_escape_string($conn, trim($_POST['bank_name']   ?? ''));
        $branch_code = mysqli_real_escape_string($conn, trim($_POST['branch_code'] ?? ''));
        $branch_name = mysqli_real_escape_string($conn, trim($_POST['branch_name'] ?? ''));
        $cheque_no   = mysqli_real_escape_string($conn, trim($_POST['cheque_no']   ?? ''));
        $cheque_date_raw = trim($_POST['cheque_date'] ?? '');
        $cheque_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $cheque_date_raw) ? $cheque_date_raw : date('Y-m-d');
        $received_raw = trim($_POST['cheque_received_date'] ?? '');
        $received_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $received_raw) ? $received_raw : date('Y-m-d');

        if ($bill_no === '' || $settle <= 0) {
            echo json_encode(['ok'=>false,'msg'=>'Invoice and a settle amount greater than 0 are required.']); exit;
        }
        if ($cheque_no === '' || $bank_code === '' || $branch_code === '' || $cheque_date_raw === '' || $received_raw === '') {
            echo json_encode(['ok'=>false,'msg'=>'Bank, Branch, Cheque No., Cheque Date and Cheque Received Date are required.']); exit;
        }

        $ok = mysqli_query($conn, "INSERT INTO sampath_super_payments
            (payment_date, reference, remarks, total_invoice_amount, total_credit_note_amount, net_payable,
             payment_method, bank_code, bank_name, branch_code, branch_name, cheque_no, cheque_date, received_date,
             created_by, created_at)
            VALUES ('$received_date','$reference','$remarks',$settle,0,$settle,
             'cheque','$bank_code','$bank_name','$branch_code','$branch_name','$cheque_no','$cheque_date','$received_date',
             '$created_by',NOW())");
        if (!$ok) { echo json_encode(['ok'=>false,'msg'=>'DB error: '.mysqli_error($conn)]); exit; }
        $payment_id = mysqli_insert_id($conn);

        mysqli_query($conn, "INSERT INTO sampath_super_payment_invoices
            (payment_id, detail_id, bill_no, party_name, amount, grn_reject_amount, adjusted_amount, cn_applied)
            VALUES ($payment_id, $detail_id, '$bill_no', '$party_name', $settle, $grn_reject, $adjusted, $cn_applied)");

        $desc = "Sampath Super Payment #$payment_id | Invoice: $bill_no ($party_name) LKR " . number_format($settle,2);
        if ($reference !== '') $desc .= " | Ref: $reference";
        if ($remarks   !== '') $desc .= " | Remarks: $remarks";
        $descEsc = mysqli_real_escape_string($conn, $desc);

        /* Merge into the cheques table: same bank+branch+cheque_no gets
           its total_amount incremented (ON DUPLICATE KEY UPDATE) instead
           of creating a duplicate cheque row — this is how multiple
           invoices/payments settled under the same physical cheque add up
           to that cheque's real total value. */
        $cheque_saved = false;
        try {
            sampath_ensure_cheques_table($conn);
            mysqli_query($conn, "INSERT INTO cheques
                (invoice_payment_id, cheque_no, cheque_date, amount, total_amount,
                 bank_code, bank_name, branch_code, branch_name, status, received_date,
                 sampath, sampath_description, sampath_payment_id)
                VALUES
                (NULL, '$cheque_no', '$cheque_date', $settle, $settle,
                 '$bank_code', '$bank_name', '$branch_code', '$branch_name', 'pending', '$received_date',
                 'Sampath Cheque', '$descEsc', $payment_id)
                ON DUPLICATE KEY UPDATE
                 total_amount        = total_amount + VALUES(amount),
                 cheque_date          = VALUES(cheque_date),
                 sampath_description  = CONCAT(sampath_description, ' || ', VALUES(sampath_description)),
                 updated_at           = NOW()");
            $cheque_saved = (mysqli_error($conn) === '');
        } catch (\Throwable $e) { $cheque_saved = false; }

        $yderp_saved = false;
        try {
            $yconn = sampath_get_yderp_conn();
            if ($yconn) {
                $prevReportMode = mysqli_report(MYSQLI_REPORT_OFF);
                sampath_ensure_cheques_table($yconn);
                $y_cheque_no   = mysqli_real_escape_string($yconn, $cheque_no);
                $y_bank_code   = mysqli_real_escape_string($yconn, $bank_code);
                $y_bank_name   = mysqli_real_escape_string($yconn, $bank_name);
                $y_branch_code = mysqli_real_escape_string($yconn, $branch_code);
                $y_branch_name = mysqli_real_escape_string($yconn, $branch_name);
                $y_desc        = mysqli_real_escape_string($yconn, $desc);
                $y_ok = mysqli_query($yconn, "INSERT INTO cheques
                    (invoice_payment_id, cheque_no, cheque_date, amount, total_amount,
                     bank_code, bank_name, branch_code, branch_name, status, received_date,
                     sampath, sampath_description, sampath_payment_id)
                    VALUES
                    (NULL, '$y_cheque_no', '$cheque_date', $settle, $settle,
                     '$y_bank_code', '$y_bank_name', '$y_branch_code', '$y_branch_name', 'pending', '$received_date',
                     'Sampath Cheque', '$y_desc', $payment_id)
                    ON DUPLICATE KEY UPDATE
                     total_amount        = total_amount + VALUES(amount),
                     cheque_date          = VALUES(cheque_date),
                     sampath_description  = CONCAT(sampath_description, ' || ', VALUES(sampath_description)),
                     updated_at           = NOW()");
                $yderp_saved = (bool) $y_ok;
                mysqli_report($prevReportMode);
                mysqli_close($yconn);
            }
        } catch (\Throwable $e) { $yderp_saved = false; }

        echo json_encode(['ok'=>true,'payment_id'=>$payment_id,'settle_amount'=>$settle,'cheque_saved'=>$cheque_saved,'yderp_saved'=>$yderp_saved]);
        exit;
    }

    // ── EDIT an existing payment (+ re-sync its cheque in BOTH databases) ──
    if ($action === 'update_payment') {
        $pay_id     = (int) ($_POST['payment_id'] ?? 0);
        $settle     = round((float) ($_POST['settle_amount'] ?? 0), 2);
        $grn_reject = round(abs((float) ($_POST['grn_reject_amount'] ?? 0)), 2);
        $adjusted   = round((float) ($_POST['adjusted_amount'] ?? 0), 2);
        $cn_applied = round((float) ($_POST['cn_applied'] ?? 0), 2);
        $reference_raw = trim($_POST['reference'] ?? '');
        $remarks_raw   = trim($_POST['remarks']   ?? '');

        $cheque_date_raw = trim($_POST['cheque_date'] ?? '');
        $received_raw    = trim($_POST['cheque_received_date'] ?? '');

        $new = [
            'cheque_no'     => trim($_POST['cheque_no']   ?? ''),
            'bank_code'     => trim($_POST['bank_code']   ?? ''),
            'bank_name'     => trim($_POST['bank_name']   ?? ''),
            'branch_code'   => trim($_POST['branch_code'] ?? ''),
            'branch_name'   => trim($_POST['branch_name'] ?? ''),
            'cheque_date'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', $cheque_date_raw) ? $cheque_date_raw : date('Y-m-d'),
            'received_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $received_raw)    ? $received_raw    : date('Y-m-d'),
            'amount'        => $settle,
        ];

        if ($pay_id <= 0) { echo json_encode(['ok'=>false,'msg'=>'Invalid payment id.']); exit; }
        if ($settle <= 0) { echo json_encode(['ok'=>false,'msg'=>'Settle amount must be greater than 0.']); exit; }
        if ($new['cheque_no'] === '' || $new['bank_code'] === '' || $new['branch_code'] === '' || $cheque_date_raw === '' || $received_raw === '') {
            echo json_encode(['ok'=>false,'msg'=>'Bank, Branch, Cheque No., Cheque Date and Cheque Received Date are required.']); exit;
        }

        // Current (old) state of this payment — needed to fix up the cheque register
        $pr = mysqli_query($conn, "SELECT sp.id, sp.cheque_no, sp.bank_code, sp.branch_code,
                                          spi.amount AS settle_amount, spi.bill_no, spi.party_name
                                   FROM sampath_super_payments sp
                                   JOIN sampath_super_payment_invoices spi ON spi.payment_id = sp.id
                                   WHERE sp.id=$pay_id LIMIT 1");
        $prow = $pr ? mysqli_fetch_assoc($pr) : null;
        if (!$prow) { echo json_encode(['ok'=>false,'msg'=>'Payment not found (deleted?).']); exit; }

        $old = [
            'cheque_no'   => (string) $prow['cheque_no'],
            'bank_code'   => (string) $prow['bank_code'],
            'branch_code' => (string) $prow['branch_code'],
            'amount'      => round((float) $prow['settle_amount'], 2),
        ];

        // How many OTHER payments still share the old cheque?
        $e = function ($v) use ($conn) { return mysqli_real_escape_string($conn, (string) $v); };
        $remainRes = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM sampath_super_payments
                                           WHERE cheque_no='{$e($old['cheque_no'])}'
                                             AND bank_code='{$e($old['bank_code'])}'
                                             AND branch_code='{$e($old['branch_code'])}'
                                             AND id <> $pay_id");
        $remainOld = $remainRes ? (int) mysqli_fetch_assoc($remainRes)['cnt'] : 0;

        $updated_by = $e($_SESSION['username'] ?? 'system');

        mysqli_begin_transaction($conn);
        $ok1 = mysqli_query($conn, "UPDATE sampath_super_payments SET
                payment_date         = '{$new['received_date']}',
                reference            = '{$e($reference_raw)}',
                remarks              = '{$e($remarks_raw)}',
                total_invoice_amount = $settle,
                net_payable          = $settle,
                bank_code            = '{$e($new['bank_code'])}',
                bank_name            = '{$e($new['bank_name'])}',
                branch_code          = '{$e($new['branch_code'])}',
                branch_name          = '{$e($new['branch_name'])}',
                cheque_no            = '{$e($new['cheque_no'])}',
                cheque_date          = '{$new['cheque_date']}',
                received_date        = '{$new['received_date']}',
                updated_by           = '$updated_by',
                updated_at           = NOW()
            WHERE id=$pay_id");
        $ok2 = $ok1 ? mysqli_query($conn, "UPDATE sampath_super_payment_invoices SET
                amount            = $settle,
                grn_reject_amount = $grn_reject,
                adjusted_amount   = $adjusted,
                cn_applied        = $cn_applied
            WHERE payment_id=$pay_id") : false;
        if (!$ok1 || !$ok2) {
            $err = mysqli_error($conn);
            mysqli_rollback($conn);
            echo json_encode(['ok'=>false,'msg'=>'DB error: '.$err]); exit;
        }
        mysqli_commit($conn);

        $desc = "Sampath Super Payment #$pay_id EDITED | Invoice: {$prow['bill_no']} ({$prow['party_name']}) LKR "
              . number_format($old['amount'], 2) . " -> " . number_format($settle, 2);
        if ($reference_raw !== '') $desc .= " | Ref: $reference_raw";
        if ($remarks_raw   !== '') $desc .= " | Remarks: $remarks_raw";

        $cheque_ok = false;
        try {
            $cheque_ok = sampath_cheque_apply_edit($conn, $old, $new, $remainOld, $desc, $pay_id);
        } catch (\Throwable $ex) { $cheque_ok = false; }

        $yderp_ok = false;
        try {
            $yconn = sampath_get_yderp_conn();
            if ($yconn) {
                $prevReportMode = mysqli_report(MYSQLI_REPORT_OFF);
                $yderp_ok = sampath_cheque_apply_edit($yconn, $old, $new, $remainOld, $desc, $pay_id);
                mysqli_report($prevReportMode);
                mysqli_close($yconn);
            }
        } catch (\Throwable $ex) { $yderp_ok = false; }

        echo json_encode(['ok'=>true,'payment_id'=>$pay_id,'settle_amount'=>$settle,
                          'cheque_ok'=>$cheque_ok,'yderp_ok'=>$yderp_ok]);
        exit;
    }

    // Delete a single payment (+ its cheque row in BOTH databases)
    if ($action === 'delete_payment') {
        $del_id = (int) ($_POST['payment_id'] ?? 0);
        if ($del_id <= 0) { echo json_encode(['ok'=>false,'msg'=>'Invalid payment id.']); exit; }

        /* Need the cheque identity + settle amount BEFORE deleting, since
           cheques are merged/looked up by cheque_no+bank_code+branch_code
           (not by sampath_payment_id — that column only ever holds the
           MOST RECENT payment that touched a shared cheque, so matching on
           it would silently miss older payments sharing the same cheque). */
        $pr = mysqli_query($conn, "SELECT sp.id, sp.cheque_no, sp.bank_code, sp.branch_code, spi.amount AS settle_amount
                                    FROM sampath_super_payments sp
                                    JOIN sampath_super_payment_invoices spi ON spi.payment_id = sp.id
                                    WHERE sp.id=$del_id LIMIT 1");
        $prow = $pr ? mysqli_fetch_assoc($pr) : null;
        if (!$prow) { echo json_encode(['ok'=>false,'msg'=>'Payment not found (already deleted?).']); exit; }

        $chequeNo   = $prow['cheque_no'];
        $bankCode   = $prow['bank_code'];
        $branchCode = $prow['branch_code'];
        $settleAmt  = round((float) $prow['settle_amount'], 2);

        mysqli_query($conn, "DELETE FROM sampath_super_payment_invoices WHERE payment_id=$del_id");
        $del_ok = mysqli_query($conn, "DELETE FROM sampath_super_payments WHERE id=$del_id");
        if (!$del_ok) { echo json_encode(['ok'=>false,'msg'=>'DB error: '.mysqli_error($conn)]); exit; }

        // Does any OTHER payment still reference this same physical cheque?
        $chequeNoEsc   = mysqli_real_escape_string($conn, $chequeNo);
        $bankCodeEsc   = mysqli_real_escape_string($conn, $bankCode);
        $branchCodeEsc = mysqli_real_escape_string($conn, $branchCode);
        $remainRes = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM sampath_super_payments
                                           WHERE cheque_no='$chequeNoEsc' AND bank_code='$bankCodeEsc' AND branch_code='$branchCodeEsc'");
        $remainCount = $remainRes ? (int) mysqli_fetch_assoc($remainRes)['cnt'] : 0;
        $chequeAction = $remainCount > 0 ? 'deducted' : 'deleted';

        $cheque_ok = false;
        try {
            sampath_ensure_cheques_table($conn);
            if ($remainCount > 0) {
                // Other payments still share this cheque — deduct this
                // payment's amount from the cheque's running total instead
                // of touching the cheque row's existence.
                mysqli_query($conn, "UPDATE cheques
                    SET total_amount = GREATEST(total_amount - $settleAmt, 0), updated_at = NOW()
                    WHERE cheque_no='$chequeNoEsc' AND bank_code='$bankCodeEsc' AND branch_code='$branchCodeEsc'
                      AND sampath='Sampath Cheque'");
            } else {
                // This was the last payment linked to this cheque — remove it.
                mysqli_query($conn, "DELETE FROM cheques
                    WHERE cheque_no='$chequeNoEsc' AND bank_code='$bankCodeEsc' AND branch_code='$branchCodeEsc'
                      AND sampath='Sampath Cheque'");
            }
            $cheque_ok = (mysqli_error($conn) === '');
        } catch (\Throwable $e) { $cheque_ok = false; }

        $yderp_ok = false;
        try {
            $yconn = sampath_get_yderp_conn();
            if ($yconn) {
                $prevReportMode = mysqli_report(MYSQLI_REPORT_OFF);
                sampath_ensure_cheques_table($yconn);
                $y_chequeNoEsc   = mysqli_real_escape_string($yconn, $chequeNo);
                $y_bankCodeEsc   = mysqli_real_escape_string($yconn, $bankCode);
                $y_branchCodeEsc = mysqli_real_escape_string($yconn, $branchCode);
                if ($remainCount > 0) {
                    mysqli_query($yconn, "UPDATE cheques
                        SET total_amount = GREATEST(total_amount - $settleAmt, 0), updated_at = NOW()
                        WHERE cheque_no='$y_chequeNoEsc' AND bank_code='$y_bankCodeEsc' AND branch_code='$y_branchCodeEsc'
                          AND sampath='Sampath Cheque'");
                } else {
                    mysqli_query($yconn, "DELETE FROM cheques
                        WHERE cheque_no='$y_chequeNoEsc' AND bank_code='$y_bankCodeEsc' AND branch_code='$y_branchCodeEsc'
                          AND sampath='Sampath Cheque'");
                }
                $yderp_ok = (mysqli_error($yconn) === '');
                mysqli_report($prevReportMode);
                mysqli_close($yconn);
            }
        } catch (\Throwable $e) { $yderp_ok = false; }

        echo json_encode(['ok'=>true,'cheque_action'=>$chequeAction,'cheque_ok'=>$cheque_ok,'yderp_ok'=>$yderp_ok]);
        exit;
    }

    // Delete EVERY payment ever made from this page, and every Sampath
    // cheque row from BOTH databases. Destructive — frontend must confirm.
    if ($action === 'delete_all_payments') {
        mysqli_query($conn, "DELETE FROM sampath_super_payment_invoices WHERE 1=1");
        $del_ok = mysqli_query($conn, "DELETE FROM sampath_super_payments WHERE 1=1");
        if (!$del_ok) { echo json_encode(['ok'=>false,'msg'=>'DB error: '.mysqli_error($conn)]); exit; }

        $cheque_deleted = false;
        try {
            sampath_ensure_cheques_table($conn);
            mysqli_query($conn, "DELETE FROM cheques WHERE sampath='Sampath Cheque'");
            $cheque_deleted = (mysqli_error($conn) === '');
        } catch (\Throwable $e) { $cheque_deleted = false; }

        $yderp_deleted = false;
        try {
            $yconn = sampath_get_yderp_conn();
            if ($yconn) {
                $prevReportMode = mysqli_report(MYSQLI_REPORT_OFF);
                sampath_ensure_cheques_table($yconn);
                mysqli_query($yconn, "DELETE FROM cheques WHERE sampath='Sampath Cheque'");
                $yderp_deleted = (mysqli_error($yconn) === '');
                mysqli_report($prevReportMode);
                mysqli_close($yconn);
            }
        } catch (\Throwable $e) { $yderp_deleted = false; }

        echo json_encode(['ok'=>true,'cheque_deleted'=>$cheque_deleted,'yderp_deleted'=>$yderp_deleted]);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Unknown action']);
    exit;
}

include 'header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>
<style>
*{box-sizing:border-box;}
:root{
    --ink:#0d1117;--muted:#5b6675;--border:#dde3ec;--bg:#f4f6fa;--white:#fff;
    --sky:#0ea5e9;--sky-dark:#0284c7;--sky-light:#e0f2fe;
    --emerald:#10b981;--emerald-light:#dcfce7;
    --amber:#f59e0b;--amber-light:#fef3c7;
    --rose:#ef4444;--rose-light:#fee2e2;
    --violet:#7c3aed;--violet-light:#ede9fe;
    --radius:10px;--shadow:0 2px 16px rgba(13,17,23,.08);--shadow-lg:0 8px 40px rgba(13,17,23,.13);
}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--ink);font-size:14px;}

.ssp-page-hd{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:12px;}
.ssp-title{font-size:21px;font-weight:800;margin:0 0 3px;display:flex;align-items:center;gap:10px;}
.ssp-sub{font-size:13px;color:var(--muted);margin:0;}

.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px;}
.stat-card{background:#fff;border-radius:10px;box-shadow:var(--shadow);padding:14px 16px;display:flex;align-items:center;gap:14px;}
.stat-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;}
.stat-val{font-size:20px;font-weight:800;color:#111827;line-height:1;}
.stat-lbl{font-size:11px;color:#6b7280;margin-top:3px;font-weight:500;}

.ssp-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;margin-bottom:18px;}
.ssp-card-h{display:flex;align-items:center;gap:9px;padding:13px 18px;border-bottom:1px solid var(--border);background:#f8fafc;font-size:13px;font-weight:700;color:var(--ink);flex-wrap:wrap;}
.ssp-card-b{padding:18px;}
.ssp-card-h .badge-count{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;border-radius:10px;background:var(--sky-light);color:var(--sky-dark);font-size:11px;font-weight:800;padding:0 6px;}

.search-input{width:100%;padding:10px 16px 10px 40px;border:2px solid var(--border);border-radius:8px;font-size:14px;font-family:inherit;color:var(--ink);transition:border-color .18s;outline:none;background:var(--white);}
.search-input:focus{border-color:var(--sky);}
.search-wrap{position:relative;margin-bottom:14px;max-width:420px;}
.search-icon{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:14px;}
.search-spinner{position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--muted);display:none;}

.inv-tbl{width:100%;border-collapse:collapse;font-size:13px;}
.inv-tbl thead{background:#f8fafc;}
.inv-tbl th{padding:9px 11px;text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);font-weight:700;border-bottom:2px solid var(--border);white-space:nowrap;}
.inv-tbl td{padding:9px 11px;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
.inv-tbl tbody tr:hover td{background:#fafafa;}
.inv-tbl tfoot td{background:#f0f9ff;font-weight:700;padding:9px 11px;border-top:2px solid var(--border);}
.inv-empty-row td{text-align:center;padding:32px 12px;color:var(--muted);font-size:13px;}
.inv-del-btn{background:none;border:none;color:#d1d5db;cursor:pointer;font-size:14px;padding:4px 6px;border-radius:5px;transition:all .15s;}
.inv-del-btn:hover{color:var(--rose);background:var(--rose-light);}
.inv-edit-btn{background:none;border:none;color:#93c5fd;cursor:pointer;font-size:13px;padding:4px 6px;border-radius:5px;transition:all .15s;}
.inv-edit-btn:hover{color:#fff;background:var(--sky);}
.inv-cn-btn{background:none;border:none;color:#c4b5fd;cursor:pointer;font-size:14px;padding:4px 6px;border-radius:5px;transition:all .15s;}
.inv-cn-btn:hover{color:#fff;background:var(--violet);}
.inv-pay-btn{background:var(--emerald);border:none;color:#fff;cursor:pointer;font-size:11.5px;font-weight:700;padding:6px 12px;border-radius:6px;transition:all .15s;display:inline-flex;align-items:center;gap:5px;white-space:nowrap;}
.inv-pay-btn:hover{background:#059669;}
.inv-pay-btn:disabled{background:#e5e7eb;color:#9ca3af;cursor:not-allowed;}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-sky{background:var(--sky-light);color:var(--sky-dark);}
.badge-violet{background:var(--violet-light);color:var(--violet);}
.badge-emerald{background:var(--emerald-light);color:#065f46;}
.badge-amber{background:var(--amber-light);color:#92400e;}

.page-btn{display:inline-flex;align-items:center;justify-content:center;min-width:30px;height:30px;padding:0 7px;border:1px solid #e5e7eb;border-radius:6px;background:#fff;font-size:12px;font-weight:500;color:#374151;cursor:pointer;transition:all .18s;font-family:inherit;}
.page-btn:hover{background:#f3f4f6;}
.page-btn.active{background:#111827;color:#fff;border-color:#111827;}
.page-btn:disabled{opacity:.4;cursor:not-allowed;}

/* ── Status tabs + list toolbar ─────────────────────────── */
.list-toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin-bottom:12px;}
.list-toolbar .search-wrap{margin-bottom:0;flex:1;min-width:220px;}
.st-tabs{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px;}
.st-tab{display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border-radius:20px;border:1.5px solid var(--border);background:#fff;font-size:12px;font-weight:700;color:var(--muted);cursor:pointer;font-family:inherit;transition:all .15s;}
.st-tab .n{background:#f1f5f9;border-radius:10px;padding:1px 7px;font-size:11px;color:var(--ink);}
.st-tab:hover{border-color:var(--sky);color:var(--ink);}
.st-tab.active{color:#fff;}
.st-tab.active .n{background:rgba(255,255,255,.25);color:#fff;}
.st-tab[data-s="outstanding"].active{background:var(--sky);border-color:var(--sky);}
.st-tab[data-s="unpaid"].active{background:var(--rose);border-color:var(--rose);}
.st-tab[data-s="partial"].active{background:var(--amber);border-color:var(--amber);}
.st-tab[data-s="settled"].active{background:var(--emerald);border-color:var(--emerald);}
.st-tab[data-s="all"].active{background:#111827;border-color:#111827;}
.per-select{padding:9px 12px;border:2px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;background:#fff;color:var(--ink);cursor:pointer;outline:none;}
.per-select:focus{border-color:var(--sky);}
.badge-rose{background:var(--rose-light);color:#991b1b;}
.list-scroll{overflow:auto;}
.list-scroll.all-mode{max-height:75vh;}
.list-scroll.all-mode .inv-tbl thead th{position:sticky;top:0;background:#f8fafc;z-index:2;}

.meta-group{margin-bottom:14px;}
.meta-label{font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;}
.meta-input{width:100%;padding:10px 13px;border:2px solid var(--border);border-radius:7px;font-size:13px;font-family:inherit;color:var(--ink);transition:border-color .18s;outline:none;background:var(--white);}
.meta-input:focus{border-color:var(--sky);}
textarea.meta-input{resize:vertical;min-height:56px;}

.select2-container{width:100%!important;}
.select2-container--default .select2-selection--single{height:40px!important;border:2px solid var(--border)!important;border-radius:7px!important;background:var(--white)!important;display:flex;align-items:center;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:normal!important;padding-left:13px!important;padding-right:26px!important;font-size:13px!important;color:var(--ink)!important;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:38px!important;}
.select2-container--default.select2-container--focus .select2-selection--single,
.select2-container--default.select2-container--open .select2-selection--single{border-color:var(--sky)!important;}
.select2-dropdown{z-index:99999!important;border-radius:7px!important;border-color:var(--sky)!important;}
.select2-results__option--highlighted[aria-selected]{background:var(--sky)!important;}

.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;transition:all .18s;text-decoration:none;font-family:inherit;white-space:nowrap;}
.btn-sm{padding:6px 12px;font-size:12px;}
.btn-sky{background:var(--sky);color:#fff;}.btn-sky:hover{background:var(--sky-dark);}
.btn-emerald{background:var(--emerald);color:#fff;box-shadow:0 4px 14px rgba(16,185,129,.28);}.btn-emerald:hover{background:#059669;}
.btn-ghost{background:#f1f5f9;color:var(--ink);border:1px solid var(--border);}.btn-ghost:hover{background:#e2e8f0;}
.btn-violet{background:var(--violet);color:#fff;}.btn-violet:hover{background:#6d28d9;}
.btn-danger{background:var(--rose-light);color:#991b1b;border:1px solid #fecaca;}.btn-danger:hover{background:var(--rose);color:#fff;}
.btn:disabled{opacity:.5;cursor:not-allowed;}

.toast-wrap{position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:8px;}
.toast{padding:12px 18px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,.15);display:flex;align-items:center;gap:8px;animation:toastIn .25s ease;max-width:380px;}
.toast-success{background:#065f46;color:#fff;}
.toast-error{background:#991b1b;color:#fff;}
@keyframes toastIn{from{opacity:0;transform:translateY(10px);}to{opacity:1;transform:translateY(0);}}

.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;display:none;align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(3px);}
.modal-overlay.open{display:flex;animation:fadeIn .2s;}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.modal-box{background:#fff;border-radius:14px;width:100%;max-width:520px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);animation:slideUp .22s;}
@keyframes slideUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
.modal-header{display:flex;align-items:flex-start;justify-content:space-between;padding:16px 18px 12px;border-bottom:1px solid #f3f4f6;position:sticky;top:0;background:#fff;z-index:2;}
.modal-title{font-size:15px;font-weight:700;color:#111827;}
.modal-sub{font-size:12px;color:#6b7280;margin-top:2px;}
.modal-close{width:30px;height:30px;border-radius:8px;border:none;background:#f3f4f6;color:#6b7280;cursor:pointer;font-size:13px;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all .18s;}
.modal-close:hover{background:#e5e7eb;color:#111827;}
.modal-body{padding:16px 18px;}
.modal-footer-bar{display:flex;align-items:center;justify-content:flex-end;gap:8px;padding:14px 18px;border-top:1px solid #f3f4f6;position:sticky;bottom:0;background:#fff;}

.cn-picker-item{padding:10px 14px;cursor:pointer;border-bottom:1px solid #f5f3ff;transition:background .13s;border-radius:7px;}
.cn-picker-item:hover{background:#f5f3ff;}
.cn-pi-top{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:3px;}
.cn-pi-grn{font-weight:700;font-size:13px;color:#6d28d9;}
.cn-pi-amt{font-weight:800;font-size:13px;color:var(--emerald);white-space:nowrap;}
.cn-pi-branch{font-size:12px;color:var(--muted);}

.pay-diff-row{display:flex;justify-content:space-between;align-items:center;padding:9px 13px;background:#f8fafc;border-radius:8px;font-size:13px;margin-bottom:14px;}
.pay-diff-row .lbl{color:var(--muted);}
.pay-diff-row .val{font-weight:800;}
.pay-edit-banner{display:none;background:var(--sky-light);border:1px solid #bae6fd;color:#075985;border-radius:8px;padding:9px 12px;font-size:12px;margin-bottom:14px;}

@media(max-width:900px){.stat-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:700px){
    .inv-tbl{font-size:12px;}
    .ssp-page-hd{flex-direction:column;align-items:stretch;}
    .ssp-title{font-size:18px;}
    .ssp-card-b{padding:14px;}
    .meta-input,.select2-container--default .select2-selection--single .select2-selection__rendered{font-size:16px!important;}
}
</style>

<div class="ssp-page-hd">
    <div>
        <h2 class="ssp-title"><i class="fa-solid fa-building-columns" style="color:var(--sky);"></i> Sampath Super Payments</h2>
        <p class="ssp-sub">Full invoice list — pay, apply credit notes, and track settlement per invoice (cheque only)</p>
    </div>
    <a href="sampath_damage_batches.php" class="btn btn-ghost btn-sm"><i class="fa-solid fa-arrow-left"></i> Back to Batches</a>
</div>

<div class="stat-grid" id="statGrid">
    <div class="stat-card">
        <div class="stat-icon" style="background:#f0f9ff;color:#0369a1;"><i class="fa-solid fa-file-invoice"></i></div>
        <div><div class="stat-val" id="s-total">—</div><div class="stat-lbl">Total Invoices</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fdf2f8;color:#9333ea;"><i class="fa-solid fa-sterling-sign"></i></div>
        <div><div class="stat-val" id="s-secondary">—</div><div class="stat-lbl">Total Secondary Amount</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fa-solid fa-money-check-dollar"></i></div>
        <div><div class="stat-val" id="s-settled">—</div><div class="stat-lbl">Total Settled (current filter)</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fef2f2;color:#dc2626;"><i class="fa-solid fa-scale-unbalanced"></i></div>
        <div><div class="stat-val" id="s-diff">—</div><div class="stat-lbl">Total Diff (current filter)</div></div>
    </div>
</div>

<div class="ssp-card">
    <div class="ssp-card-h">
        <i class="fa-solid fa-file-invoice" style="color:var(--sky);"></i> Invoices
        <span class="badge-count" id="inv-count">0</span>
        <button class="btn btn-danger btn-sm" style="margin-left:auto;" onclick="confirmDeleteAllPayments()">
            <i class="fa-solid fa-trash"></i> Delete All Payments
        </button>
    </div>
    <div class="ssp-card-b">
        <div class="st-tabs" id="statusTabs">
            <button class="st-tab active" data-s="outstanding" onclick="setStatusFilter('outstanding')"><i class="fa-solid fa-hourglass-half"></i> Outstanding <span class="n" id="cnt-outstanding">0</span></button>
            <button class="st-tab" data-s="unpaid"  onclick="setStatusFilter('unpaid')"><i class="fa-solid fa-circle-xmark"></i> Not Paid <span class="n" id="cnt-unpaid">0</span></button>
            <button class="st-tab" data-s="partial" onclick="setStatusFilter('partial')"><i class="fa-solid fa-circle-half-stroke"></i> Partly Paid <span class="n" id="cnt-partial">0</span></button>
            <button class="st-tab" data-s="settled" onclick="setStatusFilter('settled')"><i class="fa-solid fa-circle-check"></i> Settled <span class="n" id="cnt-settled">0</span></button>
            <button class="st-tab" data-s="all"     onclick="setStatusFilter('all')"><i class="fa-solid fa-list"></i> All <span class="n" id="cnt-all">0</span></button>
        </div>

        <div class="list-toolbar">
            <div class="search-wrap">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <input type="text" id="searchInput" class="search-input" placeholder="Search invoice no. or party name…" autocomplete="off">
                <span class="search-spinner" id="searchSpinner"><i class="fa-solid fa-spinner fa-spin"></i></span>
            </div>
            <select class="per-select" id="perSelect" onchange="setPerPage(this.value)" title="Rows per page">
                <option value="0" selected>View All (no paging)</option>
                <option value="25">25 per page</option>
                <option value="50">50 per page</option>
                <option value="100">100 per page</option>
                <option value="250">250 per page</option>
            </select>
        </div>

        <div class="list-scroll" id="listScroll">
        <table class="inv-tbl" id="inv-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Invoice No</th>
                    <th>Party Name</th>
                    <th>Route</th>
                    <th>Bill Date</th>
                    <th style="text-align:right;">Secondary Amount</th>
                    <th style="text-align:right;">GRN Rejected</th>
                    <th style="text-align:right;">Final Amount</th>
                    <th style="text-align:right;">Credit Note Applied</th>
                    <th style="text-align:right;">Manual CN / Adj.</th>
                    <th style="text-align:right;">Balance</th>
                    <th style="text-align:right;">Settle Amount</th>
                    <th style="text-align:right;">Diff</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="inv-tbody">
                <tr class="inv-empty-row"><td colspan="15"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</td></tr>
            </tbody>
        </table>
        </div>

        <div id="paginationWrapper" style="display:none;margin-top:14px;">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <span id="pageInfo" style="font-size:12px;color:#6b7280;"></span>
                <div id="paginationBtns" style="display:flex;gap:4px;flex-wrap:wrap;"></div>
            </div>
        </div>

        <p style="font-size:11.5px;color:var(--muted);margin:10px 2px 0;">
            <i class="fa-solid fa-circle-info"></i>
            <strong>Final Amount</strong> = Secondary Amount − GRN Rejected. <strong>Balance</strong> = Final Amount − Credit Note Applied − Manual CN / Adj.
            <strong>Manual CN / Adj.</strong> (manual credit note / adjustment) is a freehand +/− entry — a positive amount reduces the balance like a credit, a negative amount increases it.
            <strong>Settle Amount</strong> is everything ever paid via cheque for that invoice (can build up over multiple partial payments) — click it to
            view, <i class="fa-solid fa-pen" style="color:var(--sky);"></i> edit or delete individual payments.
            <strong>Diff</strong> = Balance − Settle Amount. Use <i class="fa-solid fa-file-invoice-dollar" style="color:var(--violet);"></i> to add one or more credit notes (bulk),
            <i class="fa-solid fa-pen-to-square" style="color:#d97706;"></i> to add a manual credit note / adjustment, and <strong>Pay</strong> to record a cheque settlement.
        </p>
    </div>
</div>

<div class="toast-wrap" id="toast-wrap"></div>

<!-- ══════════════════ PAY / EDIT PAYMENT MODAL ══════════════════ -->
<div id="payModal" class="modal-overlay" onclick="closePayModalBg(event)">
    <div class="modal-box" onclick="event.stopPropagation()">
        <div class="modal-header">
            <div>
                <div class="modal-title"><i class="fa-solid fa-money-check-dollar" id="pay-modal-icon" style="color:var(--amber);margin-right:6px;"></i> <span id="pay-modal-title-text">Add Payment</span></div>
                <div class="modal-sub" id="pay-modal-sub">—</div>
            </div>
            <button class="modal-close" onclick="closePayModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="pay-edit-banner" id="pay-edit-banner"></div>
            <div class="pay-diff-row">
                <span class="lbl">Balance</span>
                <span class="val" id="pay-balance-val" style="color:var(--sky-dark);">LKR 0.00</span>
            </div>
            <div class="meta-group">
                <div class="meta-label">Settle Amount <span style="color:var(--rose);">*</span></div>
                <input type="number" step="0.01" min="0.01" class="meta-input" id="pay-settle-amount" oninput="updatePayDiffPreview()">
            </div>
            <div class="pay-diff-row">
                <span class="lbl">Diff (Balance − Settle Amount)</span>
                <span class="val" id="pay-diff-val" style="color:var(--rose);">LKR 0.00</span>
            </div>
            <div class="meta-group">
                <div class="meta-label">Cheque Date <span style="color:var(--rose);">*</span></div>
                <input type="date" class="meta-input" id="pay-cheque-date">
            </div>
            <div class="meta-group">
                <div class="meta-label">Cheque Received Date <span style="color:var(--rose);">*</span></div>
                <input type="date" class="meta-input" id="pay-received-date">
            </div>
            <div class="meta-group">
                <div class="meta-label">Bank <span style="color:var(--rose);">*</span></div>
                <select class="meta-input" id="pay-bank">
                    <option value="">— Select Bank —</option>
                    <?php foreach ($banks_list as $b): ?>
                    <option value="<?= htmlspecialchars($b['bank_code']) ?>" data-name="<?= htmlspecialchars($b['bank_name']) ?>">
                        <?= htmlspecialchars($b['bank_code']) ?> – <?= htmlspecialchars($b['bank_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="meta-group">
                <div class="meta-label">Branch <span style="color:var(--rose);">*</span></div>
                <select class="meta-input" id="pay-branch"><option value="">— Select Branch —</option></select>
            </div>
            <div class="meta-group">
                <div class="meta-label">Cheque No. <span style="color:var(--rose);">*</span></div>
                <input type="text" class="meta-input" id="pay-cheque-no" placeholder="e.g. 001234" oninput="checkPayChequeDuplicate()">
                <div id="pay-chq-dup-warn" style="font-size:11.5px;color:#b45309;margin-top:4px;display:none;"></div>
            </div>
            <div class="meta-group">
                <div class="meta-label">Reference (optional)</div>
                <input type="text" class="meta-input" id="pay-reference" placeholder="e.g. deposit slip no.">
            </div>
            <div class="meta-group">
                <div class="meta-label">Remarks</div>
                <textarea class="meta-input" id="pay-remarks" placeholder="Optional notes…"></textarea>
            </div>
        </div>
        <div class="modal-footer-bar">
            <button class="btn btn-ghost" onclick="closePayModal()">Cancel</button>
            <button class="btn btn-emerald" id="pay-save-btn" onclick="savePayment()"><i class="fa-solid fa-floppy-disk"></i> Save Payment</button>
        </div>
    </div>
</div>

<!-- ══════════════════ ADD CREDIT NOTE MODAL ══════════════════ -->
<div id="cnModal" class="modal-overlay" onclick="closeCNModalBg(event)">
    <div class="modal-box" onclick="event.stopPropagation()">
        <div class="modal-header">
            <div>
                <div class="modal-title"><i class="fa-solid fa-file-invoice-dollar" style="color:var(--violet);margin-right:6px;"></i> Add Credit Notes <span style="font-size:11px;font-weight:600;color:var(--muted);">(tick one or more — bulk apply)</span></div>
                <div class="modal-sub" id="cn-modal-sub">—</div>
            </div>
            <button class="modal-close" onclick="closeCNModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div style="padding:14px 18px 4px;">
            <div style="position:relative;">
                <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px;"></i>
                <input type="text" id="cn-modal-search" class="meta-input" style="padding-left:34px;" placeholder="Search GRN No or outlet…" oninput="renderCNModalList()">
            </div>
        </div>
        <div id="cn-modal-list" style="max-height:320px;overflow-y:auto;padding:6px 12px 16px;">
            <div style="text-align:center;padding:20px;color:var(--muted);font-size:13px;"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>
        </div>
        <div class="modal-footer-bar" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
            <div style="font-size:12px;color:#6b7280;">
                <label style="cursor:pointer;"><input type="checkbox" id="cn-sel-all" onchange="toggleAllCNs(this.checked)"> Select all shown</label>
                &nbsp;·&nbsp; <span id="cn-sel-info">0 selected</span>
            </div>
            <div style="display:flex;gap:8px;">
                <button class="btn btn-ghost" onclick="closeCNModal()">Cancel</button>
                <button class="btn btn-violet" id="cn-apply-btn" onclick="applySelectedCNs()" disabled><i class="fa-solid fa-check"></i> Apply Selected</button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════ APPLIED CREDIT NOTES MODAL (view / remove) ══════════════════ -->
<div id="cnAppliedModal" class="modal-overlay" onclick="closeAppliedCNModalBg(event)">
    <div class="modal-box" onclick="event.stopPropagation()">
        <div class="modal-header">
            <div>
                <div class="modal-title"><i class="fa-solid fa-file-invoice-dollar" style="color:var(--violet);margin-right:6px;"></i> Credit Notes Applied</div>
                <div class="modal-sub" id="cn-applied-modal-sub">—</div>
            </div>
            <button class="modal-close" onclick="closeAppliedCNModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div id="cn-applied-modal-list" style="max-height:340px;overflow-y:auto;padding:14px 18px;"></div>
        <div class="modal-footer-bar" style="justify-content:space-between;">
            <button class="btn btn-violet btn-sm" onclick="switchToAddCNModal()"><i class="fa-solid fa-plus"></i> Add Another</button>
            <button class="btn btn-ghost btn-sm" onclick="closeAppliedCNModal()">Close</button>
        </div>
    </div>
</div>

<!-- ══════════════════ ADD MANUAL CREDIT NOTE / ADJUSTMENT MODAL ══════════════════ -->
<div id="manualAdjModal" class="modal-overlay" onclick="closeManualAdjModalBg(event)">
    <div class="modal-box" onclick="event.stopPropagation()">
        <div class="modal-header">
            <div>
                <div class="modal-title"><i class="fa-solid fa-pen-to-square" style="color:#d97706;margin-right:6px;"></i> Add Manual Credit Note / Adjustment</div>
                <div class="modal-sub" id="manual-adj-modal-sub">—</div>
            </div>
            <button class="modal-close" onclick="closeManualAdjModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="meta-group" style="background:#fef3c7;border:1px solid #fde68a;border-radius:7px;padding:8px 11px;font-size:12px;color:#92400e;">
                <i class="fa-solid fa-circle-info"></i> Enter a positive amount to reduce the balance (like a credit), or a negative amount to increase it (a charge).
            </div>
            <div class="meta-group">
                <div class="meta-label">Amount (+ reduces balance / − increases balance) <span style="color:var(--rose);">*</span></div>
                <input type="number" step="0.01" class="meta-input" id="manual-adj-amount" placeholder="e.g. 500 or -500">
            </div>
            <div class="meta-group">
                <div class="meta-label">Date <span style="color:var(--rose);">*</span></div>
                <input type="date" class="meta-input" id="manual-adj-date">
            </div>
            <div class="meta-group">
                <div class="meta-label">Note (optional)</div>
                <input type="text" class="meta-input" id="manual-adj-note" placeholder="Reason for this credit note / adjustment…">
            </div>
        </div>
        <div class="modal-footer-bar">
            <button class="btn btn-ghost" onclick="closeManualAdjModal()">Cancel</button>
            <button class="btn btn-emerald" id="manual-adj-save-btn" onclick="saveManualAdjustment()"><i class="fa-solid fa-floppy-disk"></i> Save</button>
        </div>
    </div>
</div>

<!-- ══════════════════ MANUAL CREDIT NOTES / ADJUSTMENTS APPLIED MODAL (view / remove) ══════════════════ -->
<div id="manualAdjAppliedModal" class="modal-overlay" onclick="closeAppliedManualAdjModalBg(event)">
    <div class="modal-box" onclick="event.stopPropagation()">
        <div class="modal-header">
            <div>
                <div class="modal-title"><i class="fa-solid fa-pen-to-square" style="color:#d97706;margin-right:6px;"></i> Manual Credit Notes / Adjustments</div>
                <div class="modal-sub" id="manual-adj-applied-modal-sub">—</div>
            </div>
            <button class="modal-close" onclick="closeAppliedManualAdjModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div id="manual-adj-applied-modal-list" style="max-height:340px;overflow-y:auto;padding:14px 18px;"></div>
        <div class="modal-footer-bar" style="justify-content:space-between;">
            <button class="btn btn-violet btn-sm" onclick="switchToAddManualAdjModal()" style="background:#d97706;"><i class="fa-solid fa-plus"></i> Add Another</button>
            <button class="btn btn-ghost btn-sm" onclick="closeAppliedManualAdjModal()">Close</button>
        </div>
    </div>
</div>

<!-- ══════════════════ PAYMENT HISTORY (per invoice) MODAL ══════════════════ -->
<div id="invPaymentsModal" class="modal-overlay" onclick="closeInvPaymentsModalBg(event)">
    <div class="modal-box" onclick="event.stopPropagation()">
        <div class="modal-header">
            <div>
                <div class="modal-title"><i class="fa-solid fa-clock-rotate-left" style="color:var(--emerald);margin-right:6px;"></i> Payments For This Invoice</div>
                <div class="modal-sub" id="inv-payments-modal-sub">—</div>
            </div>
            <button class="modal-close" onclick="closeInvPaymentsModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div id="inv-payments-modal-list" style="max-height:340px;overflow-y:auto;padding:14px 18px;"></div>
        <div class="modal-footer-bar" style="justify-content:flex-end;">
            <button class="btn btn-ghost btn-sm" onclick="closeInvPaymentsModal()">Close</button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
let currentPage   = 1;
let allRows       = [];
let allCNs        = [];
let currentPayRow    = null; // invoice row currently open in the Pay modal
let editingPayment   = null; // payment being edited (null = adding a new one)
let currentCNRow     = null; // invoice row currently open in the Add-CN modal
let currentAppliedRow = null; // invoice row currently open in the Applied-CN modal
let currentManualAdjRow = null; // invoice row currently open in the Add-Manual-Adjustment modal
let currentAppliedManualAdjRow = null; // invoice row currently open in the Applied-Manual-Adjustments modal
let currentInvPaymentsRow = null; // invoice row whose payment history is open
let invPaymentsCache = {};        // payment id → payment row (from invoice_payments)
let searchTimer   = null;
let statusFilter  = 'outstanding';   // outstanding | unpaid | partial | settled | all
let perPage       = 0;               // 0 = View All (default: show everything, no paging)
let listReqId     = 0;               // ignore stale responses
let branchReqId   = 0;               // ignore stale branch loads

/* Any filter change (status tab or search) shows ALL matching rows — no paging */
function showAllRows() {
    perPage = 0;
    document.getElementById('perSelect').value = '0';
}

const STATUS_EMPTY_MSG = {
    outstanding: 'No outstanding invoices — everything matching this search is settled or has no balance owed.',
    unpaid:      'No unpaid invoices match this search.',
    partial:     'No partly paid invoices match this search.',
    settled:     'No settled invoices match this search.',
    all:         'No invoices match this search.'
};

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('perSelect').value = String(perPage);
    document.getElementById('searchInput').addEventListener('input', () => {
        clearTimeout(searchTimer);
        document.getElementById('searchSpinner').style.display = 'inline';
        showAllRows();
        searchTimer = setTimeout(() => loadInvoices(1), 320);
    });
    loadInvoices(1);
});

function setStatusFilter(s) {
    statusFilter = s;
    document.querySelectorAll('.st-tab').forEach(t => t.classList.toggle('active', t.dataset.s === s));
    showAllRows();
    loadInvoices(1);
}

function setPerPage(v) {
    perPage = parseInt(v, 10) || 0;
    loadInvoices(1);
}

// ═══════════════════════════════════════════════════════════
//  INVOICE LIST
// ═══════════════════════════════════════════════════════════
function loadInvoices(page) {
    currentPage = page;
    const reqId  = ++listReqId;
    const search = document.getElementById('searchInput').value.trim();
    const params = new URLSearchParams({ajax:'list_invoices', page, search, status: statusFilter, per: perPage});

    if (perPage === 0) {
        document.getElementById('inv-tbody').innerHTML =
            '<tr class="inv-empty-row"><td colspan="15"><i class="fa-solid fa-spinner fa-spin"></i> Loading all invoices…</td></tr>';
    }

    return fetch('?' + params)
        .then(r => r.text())
        .then(text => {
            if (reqId !== listReqId) return;   // a newer request is on its way
            document.getElementById('searchSpinner').style.display = 'none';
            let data;
            try { data = JSON.parse(text); }
            catch(e) {
                console.error('list_invoices returned non-JSON:', text);
                document.getElementById('inv-tbody').innerHTML =
                    '<tr class="inv-empty-row"><td colspan="15">Server error loading invoices. Check console.</td></tr>';
                return;
            }
            if (data.sql_error) {
                showToast('Invoice list DB error: ' + data.sql_error, 'error');
            }
            currentPage = data.page || 1;
            allRows = data.rows || [];
            renderInvoiceTable(allRows);
            renderPagination(data.page, data.pages, data.total);
            document.getElementById('inv-count').textContent = Number(data.total || 0).toLocaleString();
            if (data.counts) {
                ['outstanding','unpaid','partial','settled','all'].forEach(k =>
                    document.getElementById('cnt-' + k).textContent = Number(data.counts[k] || 0).toLocaleString());
            }
            const st = data.stats || {};
            document.getElementById('s-total').textContent     = Number(st.total || 0).toLocaleString();
            document.getElementById('s-secondary').textContent = 'Rs. ' + numFmt(st.total_secondary || 0);
            document.getElementById('s-settled').textContent   = 'Rs. ' + numFmt(st.total_settled || 0);
            document.getElementById('s-diff').textContent      = 'Rs. ' + numFmt(st.total_diff || 0);
        })
        .catch(() => {
            if (reqId !== listReqId) return;
            document.getElementById('searchSpinner').style.display = 'none';
            document.getElementById('inv-tbody').innerHTML =
                '<tr class="inv-empty-row"><td colspan="15">Network error loading invoices.</td></tr>';
        });
}

function statusBadge(s) {
    if (s === 'settled') return '<span class="badge badge-emerald"><i class="fa-solid fa-circle-check"></i> Settled</span>';
    if (s === 'partial') return '<span class="badge badge-amber"><i class="fa-solid fa-circle-half-stroke"></i> Partly Paid</span>';
    return '<span class="badge badge-rose"><i class="fa-solid fa-circle-xmark"></i> Not Paid</span>';
}

function renderInvoiceTable(rows) {
    const tbody = document.getElementById('inv-tbody');
    if (!rows.length) {
        tbody.innerHTML = `<tr class="inv-empty-row"><td colspan="15">${STATUS_EMPTY_MSG[statusFilter] || STATUS_EMPTY_MSG.all}</td></tr>`;
        return;
    }

    const rowOffset = perPage > 0 ? (currentPage - 1) * perPage : 0;
    tbody.innerHTML = rows.map((r, i) => {
        const secondary  = parseFloat(r.final_bill_amount   || 0);
        const grnReject  = Math.abs(parseFloat(r.grn_reject_amount || 0));
        const finalAmt   = Math.max(secondary - grnReject, 0);
        const cnApplied  = parseFloat(r.cn_applied    || 0);
        const manualAdj  = parseFloat(r.manual_adjustment || 0);
        const balance    = Math.max(finalAmt - cnApplied - manualAdj, 0);
        const settle     = parseFloat(r.settle_amount || 0);
        const diff       = Math.max(balance - settle, 0);

        const isSettled = diff <= 0.004;
        const rowStatus = r.pay_status || (isSettled ? 'settled' : (settle > 0.004 ? 'partial' : 'unpaid'));

        return `<tr>
            <td style="color:var(--muted);font-size:12px;">${rowOffset + i + 1}</td>
            <td><span class="badge badge-sky">${esc(r.bill_no)}</span></td>
            <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(r.party_name || r.customer_name || '—')}</td>
            <td style="font-size:12px;color:var(--muted);">${esc(r.route_name || '—')}</td>
            <td style="font-size:12px;color:var(--muted);">${r.bill_date ? fmtDate(r.bill_date) : '—'}</td>
            <td style="text-align:right;">${fmt(secondary)}</td>
            <td style="text-align:right;color:${grnReject>0?'var(--rose)':'var(--muted)'};">${grnReject>0 ? '('+fmt(grnReject)+')' : '—'}</td>
            <td style="text-align:right;font-weight:700;color:var(--sky-dark);">${fmt(finalAmt)}</td>
            <td style="text-align:right;${cnApplied>0?'cursor:pointer;':''}" ${cnApplied>0?`onclick='openAppliedCNModal(${JSON.stringify(r)})' title="View / remove credit notes applied to this invoice"`:''}>
                <span style="color:${cnApplied>0?'var(--violet)':'var(--muted)'};${cnApplied>0?'text-decoration:underline dotted;':''}">${cnApplied>0 ? '('+fmt(cnApplied)+')' : '—'}</span>
                ${cnApplied>0 ? `<i class="fa-solid fa-eye" style="font-size:10px;color:var(--violet);margin-left:5px;"></i>` : ''}
            </td>
            <td style="text-align:right;${manualAdj!==0?'cursor:pointer;':''}" ${manualAdj!==0?`onclick='openAppliedManualAdjModal(${JSON.stringify(r)})' title="View / remove manual credit notes / adjustments on this invoice"`:''}>
                <span style="color:${manualAdj===0?'var(--muted)':(manualAdj>0?'var(--amber)':'var(--rose)')};${manualAdj!==0?'text-decoration:underline dotted;':''}">${manualAdj!==0 ? (manualAdj>0 ? '('+fmt(manualAdj)+')' : '+'+fmt(Math.abs(manualAdj))) : '—'}</span>
                ${manualAdj!==0 ? `<i class="fa-solid fa-eye" style="font-size:10px;color:${manualAdj>0?'var(--amber)':'var(--rose)'};margin-left:5px;"></i>` : ''}
            </td>
            <td style="text-align:right;font-weight:800;color:var(--emerald);">${fmt(balance)}</td>
            <td style="text-align:right;${settle>0?'cursor:pointer;':''}" ${settle>0?`onclick='openInvPaymentsModal(${JSON.stringify(r)})' title="View / edit / delete payments for this invoice"`:''}>
                <span style="color:${settle>0?'#0e7490':'var(--muted)'};${settle>0?'text-decoration:underline dotted;':''}">${settle>0 ? fmt(settle) : '—'}</span>
                ${settle>0 ? `<i class="fa-solid fa-eye" style="font-size:10px;color:#0e7490;margin-left:5px;"></i>` : ''}
            </td>
            <td style="text-align:right;font-weight:800;color:${isSettled?'var(--emerald)':'var(--rose)'};">${isSettled ? '0.00' : fmt(diff)}</td>
            <td>${statusBadge(rowStatus)}</td>
            <td style="white-space:nowrap;">
                <button class="inv-cn-btn" onclick='openCNModal(${JSON.stringify(r)})' title="Add credit note to this invoice">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                </button>
                <button class="inv-cn-btn" onclick='openManualAdjModal(${JSON.stringify(r)})' title="Add a manual credit note / adjustment (+/-) to this invoice" style="color:#fbbf24;">
                    <i class="fa-solid fa-pen-to-square"></i>
                </button>
                <button class="inv-pay-btn" onclick='openPayModal(${JSON.stringify(r)})' ${isSettled?'disabled':''} title="${isSettled?'Fully settled':'Record a cheque payment'}">
                    <i class="fa-solid fa-money-check-dollar"></i> Pay
                </button>
            </td>
        </tr>`;
    }).join('');
}

function computeRowAmounts(r) {
    const secondary = parseFloat(r.final_bill_amount || 0);
    const grnReject = Math.abs(parseFloat(r.grn_reject_amount || 0));
    const finalAmt  = Math.max(secondary - grnReject, 0);
    const cnApplied = parseFloat(r.cn_applied || 0);
    const manualAdj = parseFloat(r.manual_adjustment || 0);
    const balance   = Math.max(finalAmt - cnApplied - manualAdj, 0);
    const settle    = parseFloat(r.settle_amount || 0);
    const diff      = Math.max(balance - settle, 0);
    return { secondary, grnReject, finalAmt, cnApplied, manualAdj, balance, settle, diff };
}

function renderPagination(page, pages, total) {
    const wrapper = document.getElementById('paginationWrapper');
    const btns    = document.getElementById('paginationBtns');
    const limit   = perPage;
    document.getElementById('listScroll').classList.toggle('all-mode', limit === 0);

    if (!total) { wrapper.style.display = 'none'; return; }
    wrapper.style.display = 'block';

    if (limit === 0) {
        document.getElementById('pageInfo').textContent = `Showing all ${Number(total).toLocaleString()} invoices`;
        btns.innerHTML = '';
        return;
    }
    document.getElementById('pageInfo').textContent =
        `Showing ${(page-1)*limit+1}–${Math.min(page*limit,total)} of ${Number(total).toLocaleString()} invoices`;
    if (pages <= 1) { btns.innerHTML = ''; return; }
    let html = `<button class="page-btn" onclick="loadInvoices(${page-1})" ${page===1?'disabled':''}>‹</button>`;
    const range = [1];
    if (page > 3) range.push('...');
    for (let i = Math.max(2,page-1); i <= Math.min(pages-1,page+1); i++) range.push(i);
    if (page < pages-2) range.push('...');
    if (pages > 1) range.push(pages);
    range.forEach(p => {
        if (p === '...') html += `<span class="page-btn" style="cursor:default;">…</span>`;
        else html += `<button class="page-btn ${p===page?'active':''}" onclick="loadInvoices(${p})">${p}</button>`;
    });
    html += `<button class="page-btn" onclick="loadInvoices(${page+1})" ${page===pages?'disabled':''}>›</button>`;
    btns.innerHTML = html;
}

function refreshCurrentPage() { return loadInvoices(currentPage); }

// ═══════════════════════════════════════════════════════════
//  PAY / EDIT PAYMENT MODAL
// ═══════════════════════════════════════════════════════════
function payBtnHtml() {
    return editingPayment
        ? '<i class="fa-solid fa-floppy-disk"></i> Update Payment'
        : '<i class="fa-solid fa-floppy-disk"></i> Save Payment';
}

/* Amount still payable on this invoice, counting the payment being edited
   as "not yet paid" so it can be changed freely up to that limit. */
function payMaxAllowed() {
    if (!currentPayRow) return 0;
    const a = computeRowAmounts(currentPayRow);
    const origAmt = editingPayment ? parseFloat(editingPayment.settle_amount || 0) : 0;
    return Math.max(a.balance - (a.settle - origAmt), 0);
}

function openPayModal(row) {
    currentPayRow = row;
    editingPayment = null;
    const a = computeRowAmounts(row);
    document.getElementById('pay-modal-title-text').textContent = 'Add Payment';
    document.getElementById('pay-modal-icon').style.color = 'var(--amber)';
    document.getElementById('pay-edit-banner').style.display = 'none';
    document.getElementById('pay-modal-sub').textContent = `Invoice ${row.bill_no} — ${row.party_name || row.customer_name || ''}`;
    document.getElementById('pay-balance-val').textContent = 'LKR ' + fmt(a.balance);
    document.getElementById('pay-settle-amount').value = a.diff.toFixed(2);
    document.getElementById('pay-cheque-date').value = new Date().toISOString().slice(0,10);
    document.getElementById('pay-received-date').value = new Date().toISOString().slice(0,10);
    document.getElementById('pay-cheque-no').value = '';
    document.getElementById('pay-reference').value = '';
    document.getElementById('pay-remarks').value = '';
    document.getElementById('pay-chq-dup-warn').style.display = 'none';
    $('#pay-bank').val(null).trigger('change.select2');
    branchReqId++;   // cancel any branch load still in flight
    setBranchOptions('<option value="">— Select Branch —</option>');
    const btn = document.getElementById('pay-save-btn');
    btn.disabled = false;
    btn.innerHTML = payBtnHtml();
    updatePayDiffPreview();
    document.getElementById('payModal').classList.add('open');
}

/* Open the Pay modal pre-filled with an existing payment for editing */
function openEditPaymentModal(paymentId) {
    const p   = invPaymentsCache[paymentId];
    const row = currentInvPaymentsRow;
    if (!p || !row) { showToast('Could not load this payment. Reopen the payment list and try again.', 'error'); return; }

    closeInvPaymentsModal();
    openPayModal(row);          // resets all fields
    editingPayment = p;         // …then switch into edit mode

    document.getElementById('pay-modal-title-text').textContent = 'Edit Payment';
    document.getElementById('pay-modal-icon').style.color = 'var(--sky)';
    document.getElementById('pay-modal-sub').textContent =
        `Payment #${p.id} — Invoice ${row.bill_no} — ${row.party_name || row.customer_name || ''}`;
    const banner = document.getElementById('pay-edit-banner');
    banner.innerHTML = `<i class="fa-solid fa-pen"></i> Editing payment #${esc(String(p.id))} — original
        <strong>LKR ${fmt(p.settle_amount)}</strong> on cheque <strong>${esc(p.cheque_no || '—')}</strong>.
        The cheque register in both databases will be updated to match.`;
    banner.style.display = 'block';

    document.getElementById('pay-settle-amount').value = parseFloat(p.settle_amount || 0).toFixed(2);
    document.getElementById('pay-cheque-date').value   = (p.cheque_date   || '').slice(0,10);
    document.getElementById('pay-received-date').value = (p.received_date || '').slice(0,10);
    document.getElementById('pay-cheque-no').value     = p.cheque_no || '';
    document.getElementById('pay-reference').value     = p.reference || '';
    document.getElementById('pay-remarks').value       = p.remarks || '';

    // Bank — add it to the list if it's no longer active
    const bankSel = document.getElementById('pay-bank');
    if (p.bank_code && ![...bankSel.options].some(o => o.value === p.bank_code)) {
        const o = new Option(`${p.bank_code} – ${p.bank_name || ''}`, p.bank_code);
        o.dataset.name = p.bank_name || '';
        bankSel.add(o);
    }
    $('#pay-bank').val(p.bank_code || null).trigger('change.select2');
    if (p.bank_code) loadPayBranches(p.branch_code || '', p.branch_name || '');

    document.getElementById('pay-save-btn').innerHTML = payBtnHtml();
    updatePayDiffPreview();
}

function closePayModal() {
    document.getElementById('payModal').classList.remove('open');
    currentPayRow = null;
    editingPayment = null;
}
function closePayModalBg(e) { if (e.target === document.getElementById('payModal')) closePayModal(); }

function updatePayDiffPreview() {
    if (!currentPayRow) return;
    const entered = parseFloat(document.getElementById('pay-settle-amount').value || 0);
    const remaining = Math.max(payMaxAllowed() - entered, 0);
    document.getElementById('pay-diff-val').textContent = 'LKR ' + fmt(remaining);
    document.getElementById('pay-diff-val').style.color = remaining <= 0.004 ? 'var(--emerald)' : 'var(--rose)';
}

/* Select2 throws if you call 'destroy' on an element that isn't currently
   a Select2 — only destroy when it really is one. */
function safeDestroySelect2(el) {
    const $el = $(el);
    if ($el.hasClass('select2-hidden-accessible') || $el.data('select2')) {
        try { $el.select2('destroy'); } catch (e) {}
    }
}

/* Rebuild the branch dropdown with new <option> HTML and re-init Select2 */
function setBranchOptions(html, selectValue) {
    const branchSel = document.getElementById('pay-branch');
    safeDestroySelect2(branchSel);
    branchSel.innerHTML = html;
    if (selectValue) branchSel.value = selectValue;
    $(branchSel).select2({width:'100%', placeholder:'— Select Branch —'});
}

/* preBranch / preBranchName: optional branch to pre-select (edit mode).
   When bound as a change handler the first arg is an Event — ignored. */
function loadPayBranches(preBranch, preBranchName) {
    const pre     = typeof preBranch === 'string' ? preBranch.trim() : '';
    const preName = typeof preBranchName === 'string' ? preBranchName : '';
    const bankCode = document.getElementById('pay-bank').value;
    const reqId    = ++branchReqId;

    if (!bankCode) {
        setBranchOptions('<option value="">— Select Branch —</option>');
        return;
    }
    setBranchOptions('<option value="">Loading…</option>');

    fetch('get_bank_branches.php?bank_code=' + encodeURIComponent(bankCode))
        .then(r => r.json())
        .then(data => {
            if (reqId !== branchReqId) return;   // user picked another bank meanwhile
            let opts = '<option value="">— Select Branch —</option>';
            let found = false;
            (Array.isArray(data) ? data : []).forEach(b => {
                if (pre && String(b.branch_code).trim() === pre) found = true;
                opts += `<option value="${esc(b.branch_code)}" data-name="${esc(b.branch_name)}">${esc(b.branch_code)} – ${esc(b.branch_name)}</option>`;
            });
            if (pre && !found) {
                opts += `<option value="${esc(pre)}" data-name="${esc(preName)}">${esc(pre)} – ${esc(preName || '(saved branch)')}</option>`;
            }
            setBranchOptions(opts, pre);
        })
        .catch(() => {
            if (reqId !== branchReqId) return;
            setBranchOptions(pre
                ? `<option value="${esc(pre)}" data-name="${esc(preName)}">${esc(pre)} – ${esc(preName)}</option>`
                : '<option value="">Error loading branches</option>', pre);
        });
}

let _payChqDupTimer = null;
function checkPayChequeDuplicate() {
    clearTimeout(_payChqDupTimer);
    const no   = document.getElementById('pay-cheque-no').value.trim();
    const warn = document.getElementById('pay-chq-dup-warn');
    if (!no) { warn.style.display = 'none'; return; }
    // Editing and cheque no. unchanged → it's this payment's own cheque
    if (editingPayment && no === String(editingPayment.cheque_no || '').trim()) { warn.style.display = 'none'; return; }
    _payChqDupTimer = setTimeout(() => {
        fetch('get_cheque_info.php?cheque_no=' + encodeURIComponent(no))
            .then(r => r.json())
            .then(data => {
                if (data.exists) {
                    warn.style.display = 'block';
                    warn.innerHTML = `<i class="fa-solid fa-circle-info"></i> Cheque #${esc(no)} already has LKR ${fmt(data.total_amount)} recorded — this payment will be added on top of it.`;
                } else warn.style.display = 'none';
            }).catch(() => {});
    }, 320);
}

async function savePayment() {
    if (!currentPayRow) return;
    const a = computeRowAmounts(currentPayRow);
    const settle = parseFloat(document.getElementById('pay-settle-amount').value || 0);
    const isEdit = !!editingPayment;

    const bankSel    = document.getElementById('pay-bank');
    const bankCode   = bankSel.value;
    const bankName   = bankSel.selectedOptions[0] ? (bankSel.selectedOptions[0].dataset.name || '') : '';
    const branchSel   = document.getElementById('pay-branch');
    const branchCode  = branchSel.value;
    const branchName  = branchSel.selectedOptions[0] ? (branchSel.selectedOptions[0].dataset.name || '') : '';
    const chequeNo    = document.getElementById('pay-cheque-no').value.trim();
    const chequeDate  = document.getElementById('pay-cheque-date').value;
    const receivedDate= document.getElementById('pay-received-date').value;

    if (settle <= 0) { showToast('Settle amount must be greater than 0.', 'error'); return; }
    if (!bankCode || !branchCode || !chequeNo || !chequeDate || !receivedDate) {
        showToast('Bank, Branch, Cheque No., Cheque Date and Cheque Received Date are required.', 'error'); return;
    }
    const maxAllowed = payMaxAllowed();
    if (settle > maxAllowed + 0.004 &&
        !confirm(`LKR ${fmt(settle)} is more than the LKR ${fmt(maxAllowed)} still owed on this invoice. Save anyway?`)) return;

    const btn = document.getElementById('pay-save-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData();
    fd.append('ajax_action', isEdit ? 'update_payment' : 'add_payment');
    if (isEdit) fd.append('payment_id', editingPayment.id);
    fd.append('detail_id', currentPayRow.id);
    fd.append('bill_no', currentPayRow.bill_no);
    fd.append('party_name', currentPayRow.party_name || currentPayRow.customer_name || '');
    fd.append('settle_amount', settle);
    fd.append('grn_reject_amount', a.grnReject);
    fd.append('adjusted_amount', a.finalAmt);
    fd.append('cn_applied', a.cnApplied);
    fd.append('bank_code', bankCode);
    fd.append('bank_name', bankName);
    fd.append('branch_code', branchCode);
    fd.append('branch_name', branchName);
    fd.append('cheque_no', chequeNo);
    fd.append('cheque_date', chequeDate);
    fd.append('cheque_received_date', receivedDate);
    fd.append('reference', document.getElementById('pay-reference').value);
    fd.append('remarks', document.getElementById('pay-remarks').value);

    const rowRef = currentPayRow;
    try {
        const res  = await fetch(location.pathname, {method:'POST', body:fd});
        const data = await res.json();
        btn.disabled = false;
        btn.innerHTML = payBtnHtml();
        if (data.ok) {
            if (isEdit) {
                showToast(`Payment #${data.payment_id} updated — now LKR ${fmt(data.settle_amount)} for ${rowRef.bill_no}.`, 'success');
                if (data.cheque_ok === false) showToast('Payment updated, but the cheque register entry here may need review.', 'error');
                if (data.yderp_ok === false)  showToast('Payment updated. (Could not update the mirrored cheque in the secondary ERP database.)', 'error');
            } else {
                showToast(`Payment of LKR ${fmt(data.settle_amount)} recorded for ${rowRef.bill_no}.`, 'success');
                if (data.cheque_saved === false) showToast('Payment saved, but the cheque register entry may need review.', 'error');
                else if (data.yderp_saved === false) showToast('Payment saved. (Could not reach the secondary ERP database to mirror the cheque.)', 'error');
            }
            closePayModal();
            await refreshCurrentPage();
            if (isEdit) {
                // Reopen the history list with fresh figures
                const fresh = allRows.find(r => String(r.id) === String(rowRef.id)) || rowRef;
                openInvPaymentsModal(fresh);
            }
        } else {
            showToast('Error: ' + (data.msg || 'Unknown error'), 'error');
        }
    } catch(e) {
        btn.disabled = false;
        btn.innerHTML = payBtnHtml();
        showToast('Network error. Try again.', 'error');
    }
}

// ═══════════════════════════════════════════════════════════
//  ADD CREDIT NOTE MODAL
// ═══════════════════════════════════════════════════════════
async function loadCreditNotes() {
    try {
        const res  = await fetch(location.pathname + '?ajax=get_credit_notes');
        const text = await res.text();
        let data;
        try { data = JSON.parse(text); } catch(e) { allCNs = []; return; }
        if (data && data.error) { allCNs = []; showToast('Failed to load credit notes: ' + data.error, 'error'); return; }
        allCNs = Array.isArray(data) ? data : [];
    } catch(e) { allCNs = []; showToast('Could not load credit notes.', 'error'); }
}

let selCNs = new Set();   // credit note ids ticked in the Add Credit Notes modal (bulk apply)
async function openCNModal(row) {
    currentCNRow = row;
    const a = computeRowAmounts(row);
    document.getElementById('cn-modal-sub').textContent = `For invoice ${row.bill_no} — Balance: LKR ${fmt(a.balance)}`;
    document.getElementById('cn-modal-search').value = '';
    selCNs = new Set();
    document.getElementById('cnModal').classList.add('open');
    const listEl = document.getElementById('cn-modal-list');
    listEl.innerHTML = '<div style="text-align:center;padding:20px;color:var(--muted);font-size:13px;"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
    await loadCreditNotes();
    renderCNModalList();
    setTimeout(() => document.getElementById('cn-modal-search').focus(), 50);
}

function closeCNModal() { document.getElementById('cnModal').classList.remove('open'); currentCNRow = null; }
function closeCNModalBg(e) { if (e.target === document.getElementById('cnModal')) closeCNModal(); }

function renderCNModalList() {
    const listEl = document.getElementById('cn-modal-list');
    const term = (document.getElementById('cn-modal-search').value || '').toLowerCase().trim();
    const filtered = allCNs.filter(cn => {
        if (!term) return true;
        return (cn.grn_no || '').toLowerCase().includes(term) || (cn.branch_name || '').toLowerCase().includes(term);
    });
    if (!filtered.length) {
        listEl.innerHTML = allCNs.length === 0
            ? '<div style="text-align:center;padding:20px;color:var(--muted);font-size:13px;"><i class="fa-solid fa-circle-info"></i> No unused credit notes available.</div>'
            : '<div style="text-align:center;padding:20px;color:var(--muted);font-size:13px;">No results match your search.</div>';
        return;
    }
    listEl.innerHTML = filtered.map(cn => `
        <div class="cn-picker-item" onclick="toggleCN(${cn.id})" style="${selCNs.has(cn.id) ? 'background:#f5f3ff;' : ''}">
            <div class="cn-pi-top">
                <span class="cn-pi-grn"><input type="checkbox" ${selCNs.has(cn.id) ? 'checked' : ''} style="margin-right:8px;pointer-events:none;">${esc(cn.grn_no)}</span>
                <span class="cn-pi-amt">LKR ${fmt(cn.amount)}</span>
            </div>
            <div class="cn-pi-branch">${esc(cn.branch_name || cn.customer_name || '—')}${cn.customer_id ? ' &nbsp;<span style="color:#9ca3af;font-size:11px;">#'+cn.customer_id+'</span>' : ''}</div>
        </div>`).join('');
    updateCNSelInfo();
}

function toggleCN(id) { selCNs.has(id) ? selCNs.delete(id) : selCNs.add(id); renderCNModalList(); }
function shownCNs() {
    const term = (document.getElementById('cn-modal-search').value || '').toLowerCase().trim();
    return allCNs.filter(cn => !term || (cn.grn_no || '').toLowerCase().includes(term) || (cn.branch_name || '').toLowerCase().includes(term));
}
function toggleAllCNs(on) { shownCNs().forEach(cn => on ? selCNs.add(cn.id) : selCNs.delete(cn.id)); renderCNModalList(); }
function updateCNSelInfo() {
    const picked = allCNs.filter(cn => selCNs.has(cn.id));
    const total = picked.reduce((t, cn) => t + parseFloat(cn.amount || 0), 0);
    const bal = currentCNRow ? computeRowAmounts(currentCNRow).balance : null;
    const over = bal !== null && total > bal + 0.005;
    const info = document.getElementById('cn-sel-info');
    info.innerHTML = picked.length + ' selected · LKR ' + fmt(total) + (over ? ' <span style="color:var(--rose);font-weight:700;">(more than the invoice balance LKR ' + fmt(bal) + ')</span>' : '');
    document.getElementById('cn-apply-btn').disabled = picked.length === 0;
    const shown = shownCNs();
    document.getElementById('cn-sel-all').checked = shown.length > 0 && shown.every(cn => selCNs.has(cn.id));
}

async function applySelectedCNs() {
    if (!currentCNRow || !selCNs.size) return;
    const btn = document.getElementById('cn-apply-btn'); btn.disabled = true;
    const fd = new FormData();
    fd.append('ajax_action', 'apply_invoice_cns');
    fd.append('cn_ids', JSON.stringify([...selCNs]));
    fd.append('bill_no', currentCNRow.bill_no);
    try {
        const res  = await fetch(location.pathname, {method:'POST', body:fd});
        const data = await res.json();
        if (data.ok) {
            showToast(data.applied + ' credit note(s) applied (LKR ' + fmt(data.total) + ')' + (data.skipped ? ' · ' + data.skipped + ' skipped' : '') + '.', 'success');
            closeCNModal();
            refreshCurrentPage();
        } else { showToast('Error: ' + (data.msg || 'Unknown error'), 'error'); btn.disabled = false; }
    } catch(e) { showToast('Network error. Try again.', 'error'); btn.disabled = false; }
}

async function selectModalCN(id) {
    if (!currentCNRow) return;
    const fd = new FormData();
    fd.append('ajax_action', 'apply_invoice_cn');
    fd.append('cn_id', id);
    fd.append('bill_no', currentCNRow.bill_no);
    try {
        const res  = await fetch(location.pathname, {method:'POST', body:fd});
        const data = await res.json();
        if (data.ok) {
            showToast('Credit note applied.', 'success');
            closeCNModal();
            refreshCurrentPage();
        } else showToast('Error: ' + (data.msg || 'Unknown error'), 'error');
    } catch(e) { showToast('Network error. Try again.', 'error'); }
}

// ═══════════════════════════════════════════════════════════
//  APPLIED CREDIT NOTES MODAL (view / remove)
// ═══════════════════════════════════════════════════════════
async function openAppliedCNModal(row) {
    currentAppliedRow = row;
    document.getElementById('cn-applied-modal-sub').textContent = `For invoice ${row.bill_no}`;
    document.getElementById('cnAppliedModal').classList.add('open');
    await renderAppliedCNModalList();
}

function closeAppliedCNModal() { document.getElementById('cnAppliedModal').classList.remove('open'); currentAppliedRow = null; }
function closeAppliedCNModalBg(e) { if (e.target === document.getElementById('cnAppliedModal')) closeAppliedCNModal(); }

async function renderAppliedCNModalList() {
    if (!currentAppliedRow) return;
    const listEl = document.getElementById('cn-applied-modal-list');
    listEl.innerHTML = '<div style="text-align:center;padding:20px;color:var(--muted);font-size:13px;"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
    try {
        const res  = await fetch('?ajax=invoice_cns&bill_no=' + encodeURIComponent(currentAppliedRow.bill_no));
        const rows = await res.json();
        if (!Array.isArray(rows) || !rows.length) {
            listEl.innerHTML = '<div style="text-align:center;padding:20px;color:var(--muted);font-size:13px;"><i class="fa-solid fa-file-circle-xmark"></i> No credit notes applied to this invoice.</div>';
            return;
        }
        listEl.innerHTML = rows.map(cn => `
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 4px;border-bottom:1px solid #f5f3ff;">
                <div style="min-width:0;">
                    <div style="font-weight:700;font-size:13px;color:#6d28d9;">${esc(cn.grn_no)}</div>
                    <div style="font-size:12px;color:var(--muted);margin-top:2px;">${esc(cn.branch_name || '—')}</div>
                </div>
                <div style="display:flex;align-items:center;gap:10px;flex-shrink:0;">
                    <span style="font-weight:800;color:var(--emerald);font-size:13px;">LKR ${fmt(cn.amount)}</span>
                    <button class="inv-del-btn" onclick="removeInvoiceCN(${cn.id})" title="Remove this credit note">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>`).join('');
    } catch(e) {
        listEl.innerHTML = '<div style="text-align:center;padding:20px;color:var(--rose);font-size:13px;">Could not load credit notes.</div>';
    }
}

async function removeInvoiceCN(id) {
    const fd = new FormData();
    fd.append('ajax_action', 'remove_invoice_cn');
    fd.append('id', id);
    try {
        const res  = await fetch(location.pathname, {method:'POST', body:fd});
        const data = await res.json();
        if (data.ok) {
            showToast('Credit note removed.', 'success');
            refreshCurrentPage();
            await renderAppliedCNModalList();
        } else showToast('Error: ' + (data.msg || 'Unknown error'), 'error');
    } catch(e) { showToast('Network error. Try again.', 'error'); }
}

function switchToAddCNModal() {
    if (!currentAppliedRow) return;
    const row = currentAppliedRow;
    closeAppliedCNModal();
    openCNModal(row);
}

// ═══════════════════════════════════════════════════════════
//  ADD MANUAL ADJUSTMENT MODAL (freehand +/- amount)
// ═══════════════════════════════════════════════════════════
function openManualAdjModal(row) {
    currentManualAdjRow = row;
    const a = computeRowAmounts(row);
    document.getElementById('manual-adj-modal-sub').textContent = `For invoice ${row.bill_no} — Balance: LKR ${fmt(a.balance)}`;
    document.getElementById('manual-adj-amount').value = '';
    document.getElementById('manual-adj-date').value = new Date().toISOString().slice(0,10);
    document.getElementById('manual-adj-note').value = '';
    document.getElementById('manualAdjModal').classList.add('open');
    setTimeout(() => document.getElementById('manual-adj-amount').focus(), 50);
}

function closeManualAdjModal() {
    document.getElementById('manualAdjModal').classList.remove('open');
    currentManualAdjRow = null;
}
function closeManualAdjModalBg(e) { if (e.target === document.getElementById('manualAdjModal')) closeManualAdjModal(); }

async function saveManualAdjustment() {
    if (!currentManualAdjRow) return;
    const amount = parseFloat(document.getElementById('manual-adj-amount').value);
    const date   = document.getElementById('manual-adj-date').value;
    const note   = document.getElementById('manual-adj-note').value;

    if (!amount || isNaN(amount)) { showToast('Enter a non-zero amount (positive or negative).', 'error'); return; }
    if (!date) { showToast('Date is required.', 'error'); return; }

    const btn = document.getElementById('manual-adj-save-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData();
    fd.append('ajax_action', 'apply_manual_adjustment');
    fd.append('bill_no', currentManualAdjRow.bill_no);
    fd.append('amount', amount);
    fd.append('adjustment_date', date);
    fd.append('note', note);

    try {
        const res  = await fetch(location.pathname, {method:'POST', body:fd});
        const data = await res.json();
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save';
        if (data.ok) {
            showToast('Manual credit note / adjustment saved.', 'success');
            closeManualAdjModal();
            refreshCurrentPage();
        } else showToast('Error: ' + (data.msg || 'Unknown error'), 'error');
    } catch(e) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save';
        showToast('Network error. Try again.', 'error');
    }
}

// ═══════════════════════════════════════════════════════════
//  MANUAL ADJUSTMENTS APPLIED MODAL (view / remove)
// ═══════════════════════════════════════════════════════════
async function openAppliedManualAdjModal(row) {
    currentAppliedManualAdjRow = row;
    document.getElementById('manual-adj-applied-modal-sub').textContent = `For invoice ${row.bill_no}`;
    document.getElementById('manualAdjAppliedModal').classList.add('open');
    await renderAppliedManualAdjModalList();
}

function closeAppliedManualAdjModal() {
    document.getElementById('manualAdjAppliedModal').classList.remove('open');
    currentAppliedManualAdjRow = null;
}
function closeAppliedManualAdjModalBg(e) { if (e.target === document.getElementById('manualAdjAppliedModal')) closeAppliedManualAdjModal(); }

async function renderAppliedManualAdjModalList() {
    if (!currentAppliedManualAdjRow) return;
    const listEl = document.getElementById('manual-adj-applied-modal-list');
    listEl.innerHTML = '<div style="text-align:center;padding:20px;color:var(--muted);font-size:13px;"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
    try {
        const res  = await fetch('?ajax=invoice_manual_adjustments&bill_no=' + encodeURIComponent(currentAppliedManualAdjRow.bill_no));
        const rows = await res.json();
        if (!Array.isArray(rows) || !rows.length) {
            listEl.innerHTML = '<div style="text-align:center;padding:20px;color:var(--muted);font-size:13px;"><i class="fa-solid fa-file-circle-xmark"></i> No manual credit notes / adjustments on this invoice.</div>';
            return;
        }
        listEl.innerHTML = rows.map(m => {
            const amt = parseFloat(m.amount || 0);
            return `<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 4px;border-bottom:1px solid #fef3c7;">
                <div style="min-width:0;">
                    <div style="font-weight:700;font-size:13px;color:#92400e;">${m.adjustment_date ? fmtDate(m.adjustment_date) : '—'}</div>
                    <div style="font-size:12px;color:var(--muted);margin-top:2px;">${esc(m.note || '—')}</div>
                </div>
                <div style="display:flex;align-items:center;gap:10px;flex-shrink:0;">
                    <span style="font-weight:800;font-size:13px;color:${amt>0?'var(--amber)':'var(--rose)'};">${amt>0 ? '('+fmt(amt)+')' : '+'+fmt(Math.abs(amt))}</span>
                    <button class="inv-del-btn" onclick="removeManualAdjustment(${m.id})" title="Remove this entry">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>`;
        }).join('');
    } catch(e) {
        listEl.innerHTML = '<div style="text-align:center;padding:20px;color:var(--rose);font-size:13px;">Could not load manual credit notes / adjustments.</div>';
    }
}

async function removeManualAdjustment(id) {
    const fd = new FormData();
    fd.append('ajax_action', 'remove_manual_adjustment');
    fd.append('id', id);
    try {
        const res  = await fetch(location.pathname, {method:'POST', body:fd});
        const data = await res.json();
        if (data.ok) {
            showToast('Manual credit note / adjustment removed.', 'success');
            refreshCurrentPage();
            await renderAppliedManualAdjModalList();
        } else showToast('Error: ' + (data.msg || 'Unknown error'), 'error');
    } catch(e) { showToast('Network error. Try again.', 'error'); }
}

function switchToAddManualAdjModal() {
    if (!currentAppliedManualAdjRow) return;
    const row = currentAppliedManualAdjRow;
    closeAppliedManualAdjModal();
    openManualAdjModal(row);
}

// ═══════════════════════════════════════════════════════════
//  PER-INVOICE PAYMENT HISTORY MODAL (view / edit / delete)
// ═══════════════════════════════════════════════════════════
async function openInvPaymentsModal(row) {
    currentInvPaymentsRow = row;
    invPaymentsCache = {};
    document.getElementById('inv-payments-modal-sub').textContent = `For invoice ${row.bill_no}`;
    document.getElementById('invPaymentsModal').classList.add('open');
    const listEl = document.getElementById('inv-payments-modal-list');
    listEl.innerHTML = '<div style="text-align:center;padding:20px;color:var(--muted);font-size:13px;"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
    try {
        const res  = await fetch('?ajax=invoice_payments&bill_no=' + encodeURIComponent(row.bill_no));
        const rows = await res.json();
        if (!Array.isArray(rows) || !rows.length) {
            listEl.innerHTML = '<div style="text-align:center;padding:20px;color:var(--muted);font-size:13px;">No payments recorded yet for this invoice.</div>';
            return;
        }
        rows.forEach(p => { invPaymentsCache[p.id] = p; });
        listEl.innerHTML = rows.map(p => `
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 4px;border-bottom:1px solid #f1f5f9;">
                <div style="min-width:0;">
                    <div style="font-weight:700;font-size:13px;">${esc(p.cheque_no || '—')} <span style="font-weight:500;color:var(--muted);font-size:11.5px;">${esc(p.bank_name||p.bank_code||'')} / ${esc(p.branch_name||p.branch_code||'')}</span></div>
                    <div style="font-size:11.5px;color:var(--muted);margin-top:2px;">
                        Cheque Date: ${p.cheque_date ? fmtDate(p.cheque_date) : '—'} &nbsp;·&nbsp; Received: ${p.received_date ? fmtDate(p.received_date) : '—'}
                        ${p.reference ? ' &nbsp;·&nbsp; Ref: ' + esc(p.reference) : ''}
                    </div>
                    ${p.remarks ? `<div style="font-size:11.5px;color:var(--muted);margin-top:2px;"><i class="fa-regular fa-comment"></i> ${esc(p.remarks)}</div>` : ''}
                    ${p.updated_at ? `<div style="font-size:11px;color:#0369a1;margin-top:2px;"><i class="fa-solid fa-pen"></i> Edited ${fmtDate(p.updated_at)}${p.updated_by ? ' by ' + esc(p.updated_by) : ''}</div>` : ''}
                </div>
                <div style="display:flex;align-items:center;gap:6px;flex-shrink:0;">
                    <span style="font-weight:800;color:var(--emerald);font-size:13px;margin-right:4px;">LKR ${fmt(p.settle_amount)}</span>
                    <button class="inv-edit-btn" onclick="openEditPaymentModal(${p.id})" title="Edit this payment">
                        <i class="fa-solid fa-pen"></i>
                    </button>
                    <button class="inv-del-btn" onclick="deletePaymentFromModal(${p.id}, ${JSON.stringify(row).replace(/"/g,'&quot;')})" title="Delete this payment">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
            </div>`).join('');
    } catch(e) {
        listEl.innerHTML = '<div style="text-align:center;padding:20px;color:var(--rose);font-size:13px;">Could not load payment history.</div>';
    }
}

function closeInvPaymentsModal() { document.getElementById('invPaymentsModal').classList.remove('open'); }
function closeInvPaymentsModalBg(e) { if (e.target === document.getElementById('invPaymentsModal')) closeInvPaymentsModal(); }

async function deletePaymentFromModal(paymentId, row) {
    if (!confirm('Delete this payment? This also removes its cheque entry from both databases and cannot be undone.')) return;
    await deletePayment(paymentId, false);
    openInvPaymentsModal(row);
}

async function deletePayment(paymentId, askConfirm) {
    if (askConfirm && !confirm('Delete this payment? If the cheque is shared with other payments, its total will be reduced instead of removed. This cannot be undone.')) return;
    const fd = new FormData();
    fd.append('ajax_action', 'delete_payment');
    fd.append('payment_id', paymentId);
    try {
        const res  = await fetch(location.pathname, {method:'POST', body:fd});
        const data = await res.json();
        if (data.ok) {
            const chequeVerb = data.cheque_action === 'deducted' ? 'reduced (still shared with other payments)' : 'removed';
            showToast(`Payment deleted. Cheque ${chequeVerb}.`, 'success');
            if (data.cheque_ok === false) showToast('Payment deleted, but the cheque register update here may need manual review.', 'error');
            if (data.yderp_ok === false) showToast('Could not reach the secondary ERP database to update the mirrored cheque.', 'error');
            refreshCurrentPage();
        } else showToast('Error: ' + (data.msg || 'Unknown error'), 'error');
    } catch(e) { showToast('Network error. Try again.', 'error'); }
}

function confirmDeleteAllPayments() {
    if (!confirm('Delete ALL payments and ALL matching cheque entries from BOTH databases? This cannot be undone.')) return;
    if (!confirm('Are you absolutely sure? This will wipe every Sampath Super Payment ever recorded.')) return;
    deleteAllPayments();
}

async function deleteAllPayments() {
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'delete_all_payments');
        const res  = await fetch(location.pathname, {method:'POST', body:fd});
        const data = await res.json();
        if (data.ok) {
            showToast('All payments deleted.', 'success');
            if (data.cheque_deleted === false) showToast('Payments deleted, but cheque cleanup here may need manual review.', 'error');
            if (data.yderp_deleted === false) showToast('Could not reach the secondary ERP database to clear its cheques.', 'error');
            refreshCurrentPage();
        } else showToast('Error: ' + (data.msg || 'Unknown error'), 'error');
    } catch(e) { showToast('Network error. Try again.', 'error'); }
}

// ═══════════════════════════════════════════════════════════
//  UTILS
// ═══════════════════════════════════════════════════════════
function fmt(n) { return parseFloat(n || 0).toLocaleString('en-LK', {minimumFractionDigits:2, maximumFractionDigits:2}); }
function numFmt(n) { return parseFloat(n || 0).toLocaleString('en-LK', {minimumFractionDigits:2, maximumFractionDigits:2}); }
function fmtDate(d) {
    if (!d) return '—';
    const dt = new Date(d);
    if (isNaN(dt)) return d;
    return dt.toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'});
}
function esc(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function showToast(msg, type='success') {
    const wrap = document.getElementById('toast-wrap');
    const t = document.createElement('div');
    t.className = `toast toast-${type}`;
    t.innerHTML = `<i class="fa-solid fa-${type==='success'?'circle-check':'circle-exclamation'}"></i> ${msg}`;
    wrap.appendChild(t);
    setTimeout(() => t.remove(), 4200);
}

$('#pay-bank').select2({width:'100%', placeholder:'— Select Bank —', allowClear:true});
$('#pay-branch').select2({width:'100%', placeholder:'— Select Branch —'});
/* Bound via jQuery (not inline onchange) so programmatic
   .trigger('change.select2') in edit mode doesn't wipe the branch list. */
$('#pay-bank').on('change', () => loadPayBranches());
</script>

<?php include 'footer.php'; ?>