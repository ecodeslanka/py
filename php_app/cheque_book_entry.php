<?php
include 'config.php';

// ══════════════════════════════════════════════════════════════════
//  TABLE SETUP
// ══════════════════════════════════════════════════════════════════

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_books (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    bank_account_id     INT NOT NULL,
    company             ENUM('USLL','ULCL') NOT NULL,
    leaf_no_start       VARCHAR(50) NOT NULL,
    leaf_no_end         VARCHAR(50) NOT NULL,
    leaf_count          INT NOT NULL DEFAULT 0,
    sent_date           DATE NULL,
    remark              TEXT NULL,
    status              ENUM('Active','Used','Cancelled') NOT NULL DEFAULT 'Active',
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS customer_claim_cheque_books (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    bank_account_id     INT NOT NULL,
    customer_name       VARCHAR(255) NOT NULL DEFAULT '',
    leaf_no_start       VARCHAR(50) NOT NULL,
    leaf_no_end         VARCHAR(50) NOT NULL,
    leaf_count          INT NOT NULL DEFAULT 0,
    sent_date           DATE NULL,
    remark              TEXT NULL,
    status              ENUM('Active','Used','Cancelled') NOT NULL DEFAULT 'Active',
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Legacy / shared tables that the Cheque Acknowledgment screen
//    (cheque_acknowledgment_new.php) reads and writes. Created here too
//    (IF NOT EXISTS) so this page's leaf/usage stats are correct and safe
//    to query even if the acknowledgment screen hasn't been opened yet. ──
//
//    NOTE: `book_type` was added so this single shared table can hold
//    cancellations for BOTH the Original cheque_books AND the
//    customer_claim_cheque_books without their auto-increment ids ever
//    colliding. New rows always carry book_type; a migration below
//    backfills the column (defaulting to 'cc', its only prior use) for
//    installs where the table already existed without it.
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS ca_cancelled_cheques (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    cheque_book_id      INT NOT NULL,
    book_type           ENUM('original','cc') NOT NULL DEFAULT 'cc',
    cheque_no           VARCHAR(100) NOT NULL,
    reason              TEXT NULL,
    cancelled_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_book (cheque_book_id)
)");

// Safe migration: add book_type to pre-existing ca_cancelled_cheques tables.
$_bt_col = mysqli_query($conn, "SHOW COLUMNS FROM ca_cancelled_cheques LIKE 'book_type'");
if ($_bt_col && mysqli_num_rows($_bt_col) === 0) {
    mysqli_query($conn, "ALTER TABLE ca_cancelled_cheques ADD COLUMN book_type ENUM('original','cc') NOT NULL DEFAULT 'cc' AFTER cheque_book_id");
}

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS dl_cheque_acknowledgments (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    confirmed_payment_id INT NULL,
    claim_item_id       INT NOT NULL,
    tax_invoice_no      VARCHAR(100) NULL,
    invoice_date        DATE NULL,
    banking_date        DATE NULL,
    entity              VARCHAR(50) NULL,
    claim_description   TEXT NULL,
    actual_amount       DECIMAL(18,4) DEFAULT 0,
    vat_amount          DECIMAL(18,4) DEFAULT 0,
    total_amount        DECIMAL(18,4) DEFAULT 0,
    ledger_type         VARCHAR(100) NULL,
    status              VARCHAR(100) NULL,
    claim_type          VARCHAR(100) NULL,
    customer_code       VARCHAR(50) NULL,
    customer_name       VARCHAR(255) NULL,
    ack_sent_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
    ack_sent_by         VARCHAR(100) DEFAULT 'system',
    INDEX idx_cp (confirmed_payment_id),
    INDEX idx_ci (claim_item_id)
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS ca_customer_claims (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    ack_id              INT NOT NULL,
    customer_id         INT NULL,
    customer_code       VARCHAR(50) NULL,
    customer_name       VARCHAR(255) NULL,
    claim_amount        DECIMAL(18,4) DEFAULT 0,
    cheque_no           VARCHAR(100) NULL,
    cheque_book_id      INT NULL,
    bank_account_id     INT NULL,
    bank_account_no     VARCHAR(100) NULL,
    bank_name           VARCHAR(255) NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ack (ack_id)
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS ca_issue_headers (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    entry_id        INT NOT NULL,
    ack_date        DATE NULL,
    cheque_issue_date DATE NULL,
    common_date     DATE NULL,
    remarks         TEXT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by      VARCHAR(100) DEFAULT 'system',
    INDEX idx_entry (entry_id)
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS ca_issue_customer_lines (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    issue_id        INT NOT NULL,
    line_id         INT NOT NULL,
    ref_id          INT NULL,
    ref_code        VARCHAR(100) NULL,
    ref_name        VARCHAR(255) NULL,
    net_amount      DECIMAL(14,4) DEFAULT 0,
    vat_amount      DECIMAL(14,4) DEFAULT 0,
    total_amount    DECIMAL(14,4) DEFAULT 0,
    bank_account_id INT NULL,
    bank_account_no VARCHAR(100) NULL,
    bank_name       VARCHAR(255) NULL,
    cheque_book_id  INT NULL,
    cheque_no       VARCHAR(100) NULL,
    cancelled       TINYINT(1) DEFAULT 0,
    cancel_reason   TEXT NULL,
    ack_id          INT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_issue (issue_id)
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS ca_issue_employee_batch (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    issue_id        INT NOT NULL,
    total_amount    DECIMAL(14,4) DEFAULT 0,
    bank_account_id INT NULL,
    bank_account_no VARCHAR(100) NULL,
    bank_name       VARCHAR(255) NULL,
    cheque_book_id  INT NULL,
    cheque_no       VARCHAR(100) NULL,
    cancelled       TINYINT(1) DEFAULT 0,
    cancel_reason   TEXT NULL,
    employee_ids    TEXT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_issue (issue_id)
)");

// ══════════════════════════════════════════════════════════════════
//  HELPER: resolve CC book leaf usage from ca_customer_claims,
//          ca_issue_employee_batch (employee batch cheques), and
//          ca_cancelled_cheques
// ══════════════════════════════════════════════════════════════════
function get_cc_book_leaf_stats($conn, $book_id) {
    $book_id = intval($book_id);

    // "Used" leaves come from TWO independent sources:
    //  1) ca_customer_claims — kept in sync with individual customer-line
    //     cheques assigned on the Cheque Acknowledgment screen.
    //  2) ca_issue_employee_batch — the single shared cheque assigned to an
    //     employee batch. This was previously NOT counted here at all, so a
    //     leaf used for an employee batch cheque still looked "available"
    //     on this Cheque Book Management page even though the Acknowledgment
    //     screen itself correctly refused to reuse it.
    // Cheque numbers are combined into one set (not simply added) so a leaf
    // can never be double-counted if it happens to appear in both places.
    $used_nos = [];

    $r1 = mysqli_query($conn,
        "SELECT DISTINCT cheque_no FROM ca_customer_claims
         WHERE cheque_book_id = $book_id AND cheque_no != '' AND cheque_no IS NOT NULL");
    if ($r1) while ($row = mysqli_fetch_assoc($r1)) $used_nos[] = $row['cheque_no'];

    $r2 = mysqli_query($conn,
        "SELECT DISTINCT cheque_no FROM ca_issue_employee_batch
         WHERE cheque_book_id = $book_id AND cheque_no != '' AND cheque_no IS NOT NULL AND cancelled = 0");
    if ($r2) while ($row = mysqli_fetch_assoc($r2)) $used_nos[] = $row['cheque_no'];

    $used_count = count(array_unique($used_nos));

    $canc_res = mysqli_query($conn,
        "SELECT COUNT(*) AS cnt FROM ca_cancelled_cheques WHERE book_type='cc' AND cheque_book_id = $book_id");
    $cancelled_count = intval(mysqli_fetch_assoc($canc_res)['cnt'] ?? 0);

    $claimed_res = mysqli_query($conn,
        "SELECT COALESCE(SUM(claim_amount),0) AS total
         FROM ca_customer_claims WHERE cheque_book_id = $book_id");
    $cust_claimed = floatval(mysqli_fetch_assoc($claimed_res)['total'] ?? 0);

    $emp_claimed_res = mysqli_query($conn,
        "SELECT COALESCE(SUM(total_amount),0) AS total
         FROM ca_issue_employee_batch
         WHERE cheque_book_id = $book_id AND cheque_no != '' AND cheque_no IS NOT NULL AND cancelled = 0");
    $emp_claimed = floatval(mysqli_fetch_assoc($emp_claimed_res)['total'] ?? 0);

    return [
        'used_count'      => $used_count,
        'cancelled_count' => $cancelled_count,
        'total_claimed'   => $cust_claimed + $emp_claimed,
    ];
}

// ══════════════════════════════════════════════════════════════════
//  AJAX
// ══════════════════════════════════════════════════════════════════
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    // ── Save cheque book (original) ──────────────────────────────
    if ($action === 'save_book' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id              = intval($_POST['id'] ?? 0);
        $bank_account_id = intval($_POST['bank_account_id'] ?? 0);
        $company         = in_array($_POST['company'] ?? '', ['USLL','ULCL']) ? $_POST['company'] : '';
        $leaf_start      = mysqli_real_escape_string($conn, trim($_POST['leaf_no_start'] ?? ''));
        $leaf_end        = mysqli_real_escape_string($conn, trim($_POST['leaf_no_end']   ?? ''));
        $sent_date       = mysqli_real_escape_string($conn, trim($_POST['sent_date']     ?? ''));
        $remark          = mysqli_real_escape_string($conn, trim($_POST['remark']        ?? ''));
        $status          = in_array($_POST['status'] ?? '', ['Active','Used','Cancelled']) ? $_POST['status'] : 'Active';

        if (!$bank_account_id || !$company || !$leaf_start || !$leaf_end) {
            echo json_encode(['success'=>false,'message'=>'Bank Account, Company, Leaf Start and Leaf End are required.']);
            exit;
        }
        $start_num  = intval(preg_replace('/\D/', '', $leaf_start));
        $end_num    = intval(preg_replace('/\D/', '', $leaf_end));
        $leaf_count = ($end_num >= $start_num) ? ($end_num - $start_num + 1) : 0;
        $sent_sql   = $sent_date ? "'$sent_date'" : 'NULL';

        if ($id > 0) {
            $ok = mysqli_query($conn, "UPDATE cheque_books SET
                bank_account_id=$bank_account_id, company='$company',
                leaf_no_start='$leaf_start', leaf_no_end='$leaf_end',
                leaf_count=$leaf_count, sent_date=$sent_sql,
                remark='$remark', status='$status'
                WHERE id=$id");
        } else {
            $ok = mysqli_query($conn, "INSERT INTO cheque_books
                (bank_account_id,company,leaf_no_start,leaf_no_end,leaf_count,sent_date,remark,status)
                VALUES ($bank_account_id,'$company','$leaf_start','$leaf_end',$leaf_count,$sent_sql,'$remark','$status')");
            if ($ok) $id = mysqli_insert_id($conn);
        }
        echo json_encode(['success'=>(bool)$ok,'id'=>$id,'leaf_count'=>$leaf_count,'message'=>$ok?'':mysqli_error($conn)]);
        exit;
    }

    // ── Get single cheque book ───────────────────────────────────
    if ($action === 'get_book') {
        $id  = intval($_GET['id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT cb.*,
                CONCAT(COALESCE(NULLIF(b.bank_name,''),cba.bank_code,''),' / ',
                       COALESCE(NULLIF(bb.branch_name,''),cba.branch_code,''),' (',cba.account_no,')') AS bank_label
             FROM cheque_books cb
             LEFT JOIN company_bank_accounts cba ON cba.id=cb.bank_account_id
             LEFT JOIN banks b ON b.bank_code=cba.bank_code
             LEFT JOIN bank_branches bb ON bb.bank_code=cba.bank_code AND bb.branch_code=cba.branch_code
             WHERE cb.id=$id LIMIT 1"));
        echo json_encode(['success'=>(bool)$row,'data'=>$row]);
        exit;
    }

    // ── Delete cheque book ───────────────────────────────────────
    if ($action === 'delete_book' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id  = intval($_POST['id'] ?? 0);
        $cnt = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(DISTINCT cheque_no) as c FROM pi_settlements WHERE cheque_book_id=$id AND cheque_no!=''"));
        if (intval($cnt['c']) > 0) {
            echo json_encode(['success'=>false,'message'=>'Cannot delete: this cheque book has '.$cnt['c'].' settlement entries linked. Remove them first.']);
            exit;
        }
        $ok = mysqli_query($conn, "DELETE FROM cheque_books WHERE id=$id");
        echo json_encode(['success'=>(bool)$ok,'message'=>$ok?'':mysqli_error($conn)]);
        exit;
    }

    // ── Get history (leaves) for original cheque book ────────────
    if ($action === 'get_history') {
        $book_id = intval($_GET['book_id'] ?? 0);
        $book_row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT leaf_no_start, leaf_no_end, leaf_count FROM cheque_books WHERE id=$book_id LIMIT 1"));
        if (!$book_row) { echo json_encode(['success'=>false,'message'=>'Cheque book not found.']); exit; }

        $leaf_start_raw = $book_row['leaf_no_start'];
        $leaf_end_raw   = $book_row['leaf_no_end'];
        preg_match('/^([A-Za-z\-]*)(\d+)$/', $leaf_start_raw, $sm);
        $prefix    = $sm[1] ?? '';
        $start_str = $sm[2] ?? $leaf_start_raw;
        $pad_width = strlen($start_str);
        $start_num = intval(preg_replace('/\D/', '', $leaf_start_raw));
        $end_num   = intval(preg_replace('/\D/', '', $leaf_end_raw));

        if ($end_num < $start_num) { echo json_encode(['success'=>false,'message'=>'Invalid leaf range.']); exit; }

        $total_leaves = min($end_num - $start_num + 1, 5000);
        $res = mysqli_query($conn,
            "SELECT s.id, s.cheque_no, s.paid_date, s.paid_amount, s.cheque_leave_count, s.remarks,
                    p.invoice_no, p.invoice_date, p.company, p.invoice_value,
                    p.credit_note, p.credit_note_value, p.paid AS inv_paid
             FROM pi_settlements s
             INNER JOIN primary_invoices p ON p.id = s.pi_id
             WHERE s.cheque_book_id = $book_id AND s.cheque_no != ''
             ORDER BY s.cheque_no ASC, s.paid_date DESC, s.id DESC");

        $settlements_map = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $numeric_key = ltrim(preg_replace('/\D/', '', $r['cheque_no']), '0') ?: '0';
            if (!isset($settlements_map[$numeric_key])) $settlements_map[$numeric_key] = [];
            $settlements_map[$numeric_key][] = $r;
        }

        // Cancelled leaves for THIS (original) book. book_type='original'
        // keeps these entries separate from Customer Claim cancellations
        // even though both live in the shared ca_cancelled_cheques table.
        $canc_res = mysqli_query($conn,
            "SELECT id, cheque_no, reason, cancelled_at FROM ca_cancelled_cheques
             WHERE book_type='original' AND cheque_book_id = $book_id");
        $cancelled_map = [];
        if ($canc_res) while ($c = mysqli_fetch_assoc($canc_res)) {
            $nk = ltrim(preg_replace('/\D/', '', $c['cheque_no']), '0') ?: '0';
            $cancelled_map[$nk] = $c;
        }

        $all_leaves = [];
        for ($n = $start_num; $n <= $start_num + $total_leaves - 1; $n++) {
            $leaf_no_formatted = $prefix . str_pad($n, $pad_width, '0', STR_PAD_LEFT);
            $numeric_key       = ltrim((string)$n, '0') ?: '0';
            if (isset($cancelled_map[$numeric_key])) {
                $all_leaves[] = [
                    'leaf_no'            => $leaf_no_formatted,
                    'used'               => false,
                    'cancelled'          => true,
                    'cancel_id'          => intval($cancelled_map[$numeric_key]['id']),
                    'reason'             => $cancelled_map[$numeric_key]['reason'],
                    'cancelled_at'       => $cancelled_map[$numeric_key]['cancelled_at'],
                    'cheque_leave_count' => 1,
                    'payment_count'      => 0,
                    'payments'           => [],
                ];
            } elseif (isset($settlements_map[$numeric_key])) {
                $payments = $settlements_map[$numeric_key];
                $first    = $payments[0];
                $total_paid_for_cheque = array_sum(array_column($payments, 'paid_amount'));
                $all_leaves[] = [
                    'leaf_no'            => $leaf_no_formatted,
                    'used'               => true,
                    'cancelled'          => false,
                    'cheque_no'          => $first['cheque_no'],
                    'paid_date'          => $first['paid_date'],
                    'paid_amount'        => $total_paid_for_cheque,
                    'cheque_leave_count' => $first['cheque_leave_count'],
                    'remarks'            => $first['remarks'],
                    'payment_count'      => count($payments),
                    'payments'           => $payments,
                ];
            } else {
                $all_leaves[] = ['leaf_no'=>$leaf_no_formatted,'used'=>false,'cancelled'=>false,'cheque_leave_count'=>1,'payment_count'=>0,'payments'=>[]];
            }
        }
        $used_count      = count(array_filter($all_leaves, fn($l) => $l['used']));
        $cancelled_count = count(array_filter($all_leaves, fn($l) => !empty($l['cancelled'])));
        $empty_count     = count($all_leaves) - $used_count - $cancelled_count;
        echo json_encode(['success'=>true,'data'=>$all_leaves,'total_leaves'=>count($all_leaves),'used_count'=>$used_count,'cancelled_count'=>$cancelled_count,'empty_count'=>$empty_count,'leaf_start'=>$leaf_start_raw,'leaf_end'=>$leaf_end_raw]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════
    //  CUSTOMER CLAIM AJAX
    // ══════════════════════════════════════════════════════════════

    // ── Save customer claim cheque book ──────────────────────────
    if ($action === 'save_cc_book' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id              = intval($_POST['id'] ?? 0);
        $bank_account_id = intval($_POST['bank_account_id'] ?? 0);
        $customer_name   = mysqli_real_escape_string($conn, trim($_POST['customer_name'] ?? ''));
        $leaf_start      = mysqli_real_escape_string($conn, trim($_POST['leaf_no_start'] ?? ''));
        $leaf_end        = mysqli_real_escape_string($conn, trim($_POST['leaf_no_end']   ?? ''));
        $sent_date       = mysqli_real_escape_string($conn, trim($_POST['sent_date']     ?? ''));
        $remark          = mysqli_real_escape_string($conn, trim($_POST['remark']        ?? ''));
        $status          = in_array($_POST['status'] ?? '', ['Active','Used','Cancelled']) ? $_POST['status'] : 'Active';

        if (!$bank_account_id || !$leaf_start || !$leaf_end) {
            echo json_encode(['success'=>false,'message'=>'Bank Account, Leaf Start and Leaf End are required.']);
            exit;
        }
        $start_num  = intval(preg_replace('/\D/', '', $leaf_start));
        $end_num    = intval(preg_replace('/\D/', '', $leaf_end));
        $leaf_count = ($end_num >= $start_num) ? ($end_num - $start_num + 1) : 0;
        $sent_sql   = $sent_date ? "'$sent_date'" : 'NULL';

        if ($id > 0) {
            $ok = mysqli_query($conn, "UPDATE customer_claim_cheque_books SET
                bank_account_id=$bank_account_id,
                customer_name='$customer_name',
                leaf_no_start='$leaf_start', leaf_no_end='$leaf_end',
                leaf_count=$leaf_count, sent_date=$sent_sql,
                remark='$remark', status='$status'
                WHERE id=$id");
        } else {
            $ok = mysqli_query($conn, "INSERT INTO customer_claim_cheque_books
                (bank_account_id,customer_name,leaf_no_start,leaf_no_end,leaf_count,sent_date,remark,status)
                VALUES ($bank_account_id,'$customer_name','$leaf_start','$leaf_end',$leaf_count,$sent_sql,'$remark','$status')");
            if ($ok) $id = mysqli_insert_id($conn);
        }
        echo json_encode(['success'=>(bool)$ok,'id'=>$id,'leaf_count'=>$leaf_count,'message'=>$ok?'':mysqli_error($conn)]);
        exit;
    }

    // ── Get single customer claim cheque book ─────────────────────
    if ($action === 'get_cc_book') {
        $id  = intval($_GET['id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT ccb.*,
                CONCAT(COALESCE(NULLIF(b.bank_name,''),cba.bank_code,''),' / ',
                       COALESCE(NULLIF(bb.branch_name,''),cba.branch_code,''),' (',cba.account_no,')') AS bank_label
             FROM customer_claim_cheque_books ccb
             LEFT JOIN company_bank_accounts cba ON cba.id=ccb.bank_account_id
             LEFT JOIN banks b ON b.bank_code=cba.bank_code
             LEFT JOIN bank_branches bb ON bb.bank_code=cba.bank_code AND bb.branch_code=cba.branch_code
             WHERE ccb.id=$id LIMIT 1"));
        echo json_encode(['success'=>(bool)$row,'data'=>$row]);
        exit;
    }

    // ── Delete customer claim cheque book ─────────────────────────
    if ($action === 'delete_cc_book' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = intval($_POST['id'] ?? 0);
        $ok = mysqli_query($conn, "DELETE FROM customer_claim_cheque_books WHERE id=$id");
        echo json_encode(['success'=>(bool)$ok,'message'=>$ok?'':mysqli_error($conn)]);
        exit;
    }

    // ── Get CC history (leaves) — linked to ca_customer_claims,
    //    ca_issue_employee_batch (employee batch cheques) & ca_cancelled_cheques ──
    if ($action === 'get_cc_history') {
        $book_id  = intval($_GET['book_id'] ?? 0);
        $book_row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT * FROM customer_claim_cheque_books WHERE id=$book_id LIMIT 1"));
        if (!$book_row) { echo json_encode(['success'=>false,'message'=>'Cheque book not found.']); exit; }

        $leaf_start_raw = $book_row['leaf_no_start'];
        $leaf_end_raw   = $book_row['leaf_no_end'];
        preg_match('/^([A-Za-z\-]*)(\d+)$/', $leaf_start_raw, $sm);
        $prefix    = $sm[1] ?? '';
        $start_str = $sm[2] ?? $leaf_start_raw;
        $pad_width = strlen($start_str);
        $start_num = intval(preg_replace('/\D/', '', $leaf_start_raw));
        $end_num   = intval(preg_replace('/\D/', '', $leaf_end_raw));

        if ($end_num < $start_num) { echo json_encode(['success'=>false,'message'=>'Invalid leaf range.']); exit; }
        $total_leaves = min($end_num - $start_num + 1, 5000);

        // Fetch used claims from ca_customer_claims (individual customer-line cheques)
        $claims_res = mysqli_query($conn,
            "SELECT cc.*, da.tax_invoice_no, da.invoice_date, da.claim_description, da.entity
             FROM ca_customer_claims cc
             LEFT JOIN dl_cheque_acknowledgments da ON da.id = cc.ack_id
             WHERE cc.cheque_book_id = $book_id AND cc.cheque_no != '' AND cc.cheque_no IS NOT NULL
             ORDER BY cc.cheque_no ASC, cc.id ASC");

        $claims_map = [];
        while ($r = mysqli_fetch_assoc($claims_res)) {
            $numeric_key = ltrim(preg_replace('/\D/', '', $r['cheque_no']), '0') ?: '0';
            if (!isset($claims_map[$numeric_key])) $claims_map[$numeric_key] = [];
            $claims_map[$numeric_key][] = $r;
        }

        // Fetch used employee-batch cheques from THIS book — previously
        // completely missing from this history view, so an employee batch
        // cheque silently looked "available" here even though it was
        // already assigned/blocked on the Acknowledgment screen.
        $emp_res = mysqli_query($conn,
            "SELECT eb.*, h.entry_id, e.email_date, e.description AS entry_desc
             FROM ca_issue_employee_batch eb
             LEFT JOIN ca_issue_headers h ON h.id = eb.issue_id
             LEFT JOIN sscl_vat_email_entries e ON e.id = h.entry_id
             WHERE eb.cheque_book_id = $book_id AND eb.cheque_no != '' AND eb.cheque_no IS NOT NULL AND eb.cancelled = 0
             ORDER BY eb.cheque_no ASC, eb.id ASC");

        $emp_map = [];
        if ($emp_res) {
            while ($r = mysqli_fetch_assoc($emp_res)) {
                $eids  = json_decode($r['employee_ids'] ?? '[]', true) ?: [];
                $names = [];
                if (!empty($eids)) {
                    $eids_safe = implode(',', array_map('intval', $eids));
                    $elr = mysqli_query($conn, "SELECT ref_code, ref_name FROM sscl_vat_email_lines WHERE id IN ($eids_safe)");
                    if ($elr) while ($el = mysqli_fetch_assoc($elr)) {
                        $names[] = '['.$el['ref_code'].'] '.$el['ref_name'];
                    }
                }
                $r['employee_names'] = $names;
                $numeric_key = ltrim(preg_replace('/\D/', '', $r['cheque_no']), '0') ?: '0';
                $emp_map[$numeric_key] = $r;
            }
        }

        // Fetch cancelled cheques — restricted to book_type='cc' so this
        // never picks up an Original book's cancellation that happens to
        // share the same numeric id.
        $canc_res = mysqli_query($conn,
            "SELECT id, cheque_no, reason, cancelled_at FROM ca_cancelled_cheques
             WHERE book_type='cc' AND cheque_book_id=$book_id");
        $cancelled_map = [];
        while ($c = mysqli_fetch_assoc($canc_res)) {
            $nk = ltrim(preg_replace('/\D/', '', $c['cheque_no']), '0') ?: '0';
            $cancelled_map[$nk] = $c;
        }

        $all_leaves = [];
        for ($n = $start_num; $n <= $start_num + $total_leaves - 1; $n++) {
            $leaf_no  = $prefix . str_pad($n, $pad_width, '0', STR_PAD_LEFT);
            $nk       = ltrim((string)$n, '0') ?: '0';

            if (isset($cancelled_map[$nk])) {
                $all_leaves[] = [
                    'leaf_no'   => $leaf_no,
                    'status'    => 'cancelled',
                    'cancel_id' => intval($cancelled_map[$nk]['id']),
                    'reason'    => $cancelled_map[$nk]['reason'],
                    'cancelled_at' => $cancelled_map[$nk]['cancelled_at'],
                    'payments'  => [],
                    'payment_count' => 0,
                    'paid_amount'   => 0,
                ];
            } elseif (isset($claims_map[$nk])) {
                $payments = $claims_map[$nk];
                $total_amt = array_sum(array_column($payments, 'claim_amount'));
                $all_leaves[] = [
                    'leaf_no'       => $leaf_no,
                    'status'        => 'used',
                    'paid_amount'   => $total_amt,
                    'payment_count' => count($payments),
                    'payments'      => $payments,
                    'cheque_no'     => $payments[0]['cheque_no'],
                ];
            } elseif (isset($emp_map[$nk])) {
                // Employee batch cheque — rendered as a "used" leaf just like
                // a customer claim, using the same payment-row shape so the
                // existing history table needs no special-case UI to show it.
                $eb    = $emp_map[$nk];
                $names = $eb['employee_names'] ?? [];
                $all_leaves[] = [
                    'leaf_no'       => $leaf_no,
                    'status'        => 'used',
                    'paid_amount'   => floatval($eb['total_amount']),
                    'payment_count' => 1,
                    'cheque_no'     => $eb['cheque_no'],
                    'payments'      => [[
                        'cheque_no'          => $eb['cheque_no'],
                        'customer_name'      => 'Employee Batch ('.count($names).' employee'.(count($names)===1?'':'s').')',
                        'customer_code'      => '',
                        'tax_invoice_no'     => $eb['entry_desc'] ?? '',
                        'claim_description'  => !empty($names) ? implode(', ', $names) : '',
                        'entity'             => '',
                        'claim_amount'       => $eb['total_amount'],
                        'bank_account_no'    => $eb['bank_account_no'],
                        'bank_name'          => $eb['bank_name'],
                        'invoice_date'       => $eb['email_date'] ?? null,
                    ]],
                ];
            } else {
                $all_leaves[] = [
                    'leaf_no'       => $leaf_no,
                    'status'        => 'available',
                    'paid_amount'   => 0,
                    'payment_count' => 0,
                    'payments'      => [],
                ];
            }
        }

        $used_count      = count(array_filter($all_leaves, fn($l) => $l['status'] === 'used'));
        $cancelled_count = count(array_filter($all_leaves, fn($l) => $l['status'] === 'cancelled'));
        $avail_count     = count(array_filter($all_leaves, fn($l) => $l['status'] === 'available'));
        $total_claimed   = array_sum(array_column(array_filter($all_leaves, fn($l) => $l['status'] === 'used'), 'paid_amount'));

        echo json_encode([
            'success'         => true,
            'data'            => $all_leaves,
            'total_leaves'    => count($all_leaves),
            'used_count'      => $used_count,
            'cancelled_count' => $cancelled_count,
            'avail_count'     => $avail_count,
            'total_claimed'   => $total_claimed,
            'leaf_start'      => $leaf_start_raw,
            'leaf_end'        => $leaf_end_raw,
            'book'            => $book_row,
        ]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════
    //  REVERSE A CANCELLED LEAF — works for BOTH the Original and
    //  Customer Claim leaf-history modals. Cancellations themselves
    //  are only ever created by the Cheque Acknowledgment screen
    //  (cheque_acknowledgment_new.php); this page can only undo them.
    // ══════════════════════════════════════════════════════════════

    // ── Reverse (undo) a cancelled leaf ────────────────────────────
    if ($action === 'reverse_cancel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = intval($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success'=>false,'message'=>'Missing cancellation id.']); exit; }
        $ok = mysqli_query($conn, "DELETE FROM ca_cancelled_cheques WHERE id=$id");
        echo json_encode(['success'=>(bool)$ok, 'message'=>$ok?'':mysqli_error($conn)]);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']);
    exit;
}

// ══════════════════════════════════════════════════════════════════
//  PAGE LOAD — DATA
// ══════════════════════════════════════════════════════════════════

$f_bank     = intval(trim($_GET['f_bank']   ?? 0));
$f_status   = trim($_GET['f_status'] ?? '');
$active_tab = trim($_GET['tab'] ?? 'usll');

$bank_accounts = [];
$br = mysqli_query($conn,
    "SELECT cba.id,
        CONCAT(COALESCE(NULLIF(b.bank_name,''),cba.bank_code,''),' / ',
               COALESCE(NULLIF(bb.branch_name,''),cba.branch_code,''),' (',cba.account_no,')') AS label
     FROM company_bank_accounts cba
     LEFT JOIN banks b ON b.bank_code=cba.bank_code
     LEFT JOIN bank_branches bb ON bb.bank_code=cba.bank_code AND bb.branch_code=cba.branch_code
     WHERE cba.active=1 ORDER BY cba.account_name ASC");
if ($br) while ($r = mysqli_fetch_assoc($br)) $bank_accounts[] = $r;

// ── Original cheque books ─────────────────────────────────────────
$bk_where = [];
if ($f_bank)   $bk_where[] = "cb.bank_account_id=$f_bank";
if ($f_status) $bk_where[] = "cb.status='".mysqli_real_escape_string($conn,$f_status)."'";
$bk_where_sql = $bk_where ? 'WHERE '.implode(' AND ',$bk_where) : '';

$books_all = [];
$bres = mysqli_query($conn,
    "SELECT cb.*,
        CONCAT(COALESCE(NULLIF(b.bank_name,''),cba.bank_code,''),' / ',
               COALESCE(NULLIF(bb.branch_name,''),cba.branch_code,'')) AS bank_label,
        cba.account_no,
        (SELECT COUNT(DISTINCT s.cheque_no) FROM pi_settlements s WHERE s.cheque_book_id=cb.id AND s.cheque_no!='') AS used_leaves,
        (SELECT COALESCE(SUM(s.paid_amount),0) FROM pi_settlements s WHERE s.cheque_book_id=cb.id AND s.cheque_no!='') AS total_paid,
        (SELECT COUNT(*) FROM ca_cancelled_cheques cc2 WHERE cc2.book_type='original' AND cc2.cheque_book_id=cb.id) AS cancelled_leaves
     FROM cheque_books cb
     LEFT JOIN company_bank_accounts cba ON cba.id=cb.bank_account_id
     LEFT JOIN banks b ON b.bank_code=cba.bank_code
     LEFT JOIN bank_branches bb ON bb.bank_code=cba.bank_code AND bb.branch_code=cba.branch_code
     $bk_where_sql
     ORDER BY cb.id DESC");
if ($bres) while ($r = mysqli_fetch_assoc($bres)) $books_all[] = $r;

$books_usll = array_filter($books_all, fn($b) => $b['company'] === 'USLL');
$books_ulcl = array_filter($books_all, fn($b) => $b['company'] === 'ULCL');

// ── Customer Claim cheque books — with live leaf stats ────────────
$cc_where = [];
if ($f_bank)   $cc_where[] = "ccb.bank_account_id=$f_bank";
if ($f_status) $cc_where[] = "ccb.status='".mysqli_real_escape_string($conn,$f_status)."'";
$cc_where_sql = $cc_where ? 'WHERE '.implode(' AND ',$cc_where) : '';

$cc_books_all = [];
$ccres = mysqli_query($conn,
    "SELECT ccb.*,
        CONCAT(COALESCE(NULLIF(b.bank_name,''),cba.bank_code,''),' / ',
               COALESCE(NULLIF(bb.branch_name,''),cba.branch_code,'')) AS bank_label,
        cba.account_no
     FROM customer_claim_cheque_books ccb
     LEFT JOIN company_bank_accounts cba ON cba.id=ccb.bank_account_id
     LEFT JOIN banks b ON b.bank_code=cba.bank_code
     LEFT JOIN bank_branches bb ON bb.bank_code=cba.bank_code AND bb.branch_code=cba.branch_code
     $cc_where_sql
     ORDER BY ccb.id DESC");
if ($ccres) {
    while ($r = mysqli_fetch_assoc($ccres)) {
        $stats = get_cc_book_leaf_stats($conn, $r['id']);
        $r['used_leaves']      = $stats['used_count'];
        $r['cancelled_leaves'] = $stats['cancelled_count'];
        $r['total_claimed']    = $stats['total_claimed'];
        $cc_books_all[] = $r;
    }
}

// Summaries
$total_books            = count($books_all);
$active_books           = count(array_filter($books_all, fn($b) => $b['status'] === 'Active'));
$total_leaves_remaining = array_sum(array_map(fn($b) => max(0, intval($b['leaf_count']) - intval($b['used_leaves']) - intval($b['cancelled_leaves'])), $books_all));

$usll_active_books = count(array_filter($books_usll, fn($b) => $b['status'] === 'Active'));
$ulcl_active_books = count(array_filter($books_ulcl, fn($b) => $b['status'] === 'Active'));
$usll_remaining = array_sum(array_map(fn($b) => max(0, intval($b['leaf_count']) - intval($b['used_leaves']) - intval($b['cancelled_leaves'])), $books_usll));
$ulcl_remaining = array_sum(array_map(fn($b) => max(0, intval($b['leaf_count']) - intval($b['used_leaves']) - intval($b['cancelled_leaves'])), $books_ulcl));

$cc_total          = count($cc_books_all);
$cc_active         = count(array_filter($cc_books_all, fn($b) => $b['status'] === 'Active'));
$cc_leaves         = array_sum(array_column($cc_books_all, 'leaf_count'));
$cc_used_total     = array_sum(array_column($cc_books_all, 'used_leaves'));
$cc_cancelled_total = array_sum(array_column($cc_books_all, 'cancelled_leaves'));
$cc_remaining      = array_sum(array_map(fn($b) => max(0, intval($b['leaf_count']) - intval($b['used_leaves']) - intval($b['cancelled_leaves'])), $cc_books_all));

include 'header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
*{box-sizing:border-box;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .18s;white-space:nowrap;}
.btn-sm{padding:6px 12px;font-size:12px;}
.btn-xs{padding:4px 9px;font-size:11px;border-radius:5px;}
.btn-primary{background:#0f172a;color:#fff;}.btn-primary:hover{background:#1e293b;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e0e0e0;}.btn-secondary:hover{background:#ececec;}
.btn-danger{background:#dc2626;color:#fff;}.btn-danger:hover{background:#b91c1c;}
.btn-book{background:#7c3aed;color:#fff;}.btn-book:hover{background:#6d28d9;}
.btn-cc{background:#0e7490;color:#fff;}.btn-cc:hover{background:#155e75;}

/* TABS */
.company-tabs{display:flex;gap:0;border-bottom:2px solid #e5e7eb;margin-bottom:22px;}
.ctab-btn{padding:12px 32px;border:none;background:none;font-size:14px;font-weight:700;color:#6b7280;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;transition:all .2s;font-family:inherit;display:flex;align-items:center;gap:9px;letter-spacing:.01em;}
.ctab-btn.active-usll{color:#1d4ed8;border-bottom-color:#1d4ed8;}
.ctab-btn.active-ulcl{color:#9d174d;border-bottom-color:#9d174d;}
.ctab-btn.active-cc{color:#0e7490;border-bottom-color:#0e7490;}
.ctab-btn:hover:not(.active-usll):not(.active-ulcl):not(.active-cc){color:#374151;background:#f9fafb;}
.ctab-pane{display:none;}.ctab-pane.active{display:block;}

/* SUMMARY */
.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:22px;}
.s-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 18px;position:relative;overflow:hidden;}
.s-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;}
.s-card.blue::before{background:linear-gradient(90deg,#3b82f6,#60a5fa);}
.s-card.green::before{background:linear-gradient(90deg,#22c55e,#4ade80);}
.s-card.purple::before{background:linear-gradient(90deg,#8b5cf6,#a78bfa);}
.s-card.amber::before{background:linear-gradient(90deg,#f59e0b,#fbbf24);}
.s-card.usll::before{background:linear-gradient(90deg,#1d4ed8,#60a5fa);}
.s-card.ulcl::before{background:linear-gradient(90deg,#9d174d,#f472b6);}
.s-card.cc::before{background:linear-gradient(90deg,#0e7490,#22d3ee);}
.s-label{font-size:10.5px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.s-value{font-size:22px;font-weight:800;color:#0f172a;line-height:1;}
.s-sub{font-size:11px;color:#9ca3af;margin-top:4px;}

/* FILTER */
.filter-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 18px;margin-bottom:18px;}
.filter-row{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;}
.ffg{display:flex;flex-direction:column;gap:4px;}
.ffg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;}
.fctrl{padding:8px 11px;border:1px solid #e0e0e0;border-radius:7px;font-size:13px;font-family:inherit;color:#111827;background:#fff;outline:none;transition:border-color .2s;}
.fctrl:focus{border-color:#0f172a;}

/* TABLE CARD */
.table-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:13px 18px;border-bottom:1px solid #f0f0f0;gap:10px;flex-wrap:wrap;}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px;}
.dt-wrap{overflow-x:auto;max-height:65vh;overflow-y:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.data-table thead{position:sticky;top:0;z-index:5;}
.data-table thead th{padding:10px 11px;text-align:left;font-weight:700;font-size:11px;color:#f1f5f9;background:#0f172a;white-space:nowrap;}
.data-table thead th.tr{text-align:right;}.data-table thead th.tc{text-align:center;}
.data-table tbody tr{border-bottom:1px solid #f3f4f6;transition:background .1s;}
.data-table tbody tr:hover td{background:#f8fafc;}
.data-table td{padding:9px 11px;color:#374151;vertical-align:middle;background:#fff;}
.data-table td.tr{text-align:right;}.data-table td.tc{text-align:center;}
.data-table tfoot td{padding:10px 11px;font-weight:800;font-size:12px;background:#0f172a;color:#e2e8f0;}
.empty-state{text-align:center;padding:55px 20px;color:#9ca3af;}
.empty-state i{font-size:36px;display:block;margin-bottom:12px;opacity:.25;}
.empty-state p{font-size:14px;font-weight:500;}

/* BADGES */
.badge{display:inline-block;padding:2px 9px;border-radius:10px;font-size:10.5px;font-weight:700;letter-spacing:.02em;}
.badge-usll{background:#dbeafe;color:#1e40af;}
.badge-ulcl{background:#fce7f3;color:#9d174d;}
.badge-active{background:#d1fae5;color:#065f46;}
.badge-used{background:#f3f4f6;color:#6b7280;}
.badge-cancelled{background:#fee2e2;color:#991b1b;}
.badge-paid{background:#dcfce7;color:#166534;}
.badge-unpaid{background:#fef9c3;color:#854d0e;}
.pill{padding:2px 9px;border-radius:12px;font-size:11px;font-weight:700;}
.pill-blue{background:#dbeafe;color:#1e40af;}
.pill-usll{background:#dbeafe;color:#1e40af;}
.pill-ulcl{background:#fce7f3;color:#9d174d;}
.pill-cc{background:#cffafe;color:#155e75;}

/* LEAF BAR */
.leaf-bar{width:90px;height:7px;background:#e5e7eb;border-radius:4px;overflow:hidden;display:inline-block;vertical-align:middle;margin:0 5px;}
.leaf-bar-fill{height:100%;border-radius:4px;}
.leaf-bar-cancelled{height:100%;border-radius:0;}

/* LEAF REMAINING */
.leaf-remaining{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:8px;font-size:15px;font-weight:800;white-space:nowrap;}
.leaf-remaining.green{background:#dcfce7;color:#166534;}
.leaf-remaining.amber{background:#fef9c3;color:#854d0e;}
.leaf-remaining.red{background:#fee2e2;color:#991b1b;}
.leaf-remaining.gray{background:#f3f4f6;color:#6b7280;}
.leaf-sub{font-size:10px;font-weight:500;opacity:.7;margin-top:2px;display:block;}

/* MODAL */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:10000;display:none;align-items:center;justify-content:center;padding:16px;}
.modal-overlay.open{display:flex;}
.modal-box{background:#fff;border-radius:12px;width:96%;max-width:680px;max-height:92vh;display:flex;flex-direction:column;box-shadow:0 24px 70px rgba(0,0,0,.25);overflow:hidden;}
.hist-modal-box{background:#fff;border-radius:12px;width:98%;max-width:1260px;max-height:94vh;display:flex;flex-direction:column;box-shadow:0 24px 70px rgba(0,0,0,.25);overflow:hidden;}
.modal-header{padding:16px 22px;border-bottom:1px solid #e5e7eb;background:#fafafa;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.modal-title{font-size:15px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:9px;}
.modal-close{width:30px;height:30px;border-radius:6px;border:1px solid #e5e7eb;background:#fff;color:#6b7280;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;transition:all .15s;}
.modal-close:hover{background:#f5f5f5;color:#111;}
.modal-body{padding:22px 24px;overflow-y:auto;flex:1;}
.modal-footer{padding:14px 24px;border-top:1px solid #e5e7eb;background:#fafafa;display:flex;justify-content:flex-end;gap:10px;flex-shrink:0;}

/* HISTORY INFO STRIP */
.hist-strip{padding:12px 22px;background:#0f172a;border-bottom:1px solid #1e293b;display:flex;gap:22px;flex-wrap:wrap;flex-shrink:0;align-items:center;}
.hstrip-cell{display:flex;flex-direction:column;gap:1px;}
.hstrip-lbl{font-size:9.5px;color:#94a3b8;text-transform:uppercase;letter-spacing:.4px;}
.hstrip-val{font-size:14px;font-weight:700;color:#f1f5f9;}
.hstrip-val.green{color:#4ade80;}.hstrip-val.amber{color:#fbbf24;}.hstrip-val.red{color:#f87171;}.hstrip-val.cyan{color:#22d3ee;}

/* HISTORY FILTER BAR */
.hist-filter-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:10px 22px;background:#f8fafc;border-bottom:1px solid #e5e7eb;flex-shrink:0;}
.hist-search{padding:6px 12px;border:1px solid #e0e0e0;border-radius:6px;font-size:12.5px;font-family:inherit;color:#111827;width:220px;outline:none;}
.hist-search:focus{border-color:#0f172a;}
.hist-filter-select{padding:6px 10px;border:1px solid #e0e0e0;border-radius:6px;font-size:12.5px;font-family:inherit;color:#111827;background:#fff;outline:none;}
.hist-count-badge{margin-left:auto;font-size:11px;color:#6b7280;font-weight:600;}

/* HISTORY TABLE */
.hist-table{width:100%;border-collapse:collapse;font-size:12px;}
.hist-table th{background:#f9fafb;padding:9px 10px;text-align:left;font-weight:700;color:#6b7280;font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #e5e5e5;white-space:nowrap;position:sticky;top:0;z-index:3;}
.hist-table td{padding:8px 10px;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
.hist-table th.tr,.hist-table td.tr{text-align:right;}
.hist-table th.tc,.hist-table td.tc{text-align:center;}
.hist-table tr.row-cheque-header td{background:#f0fdf4;border-top:2px solid #86efac;border-bottom:1px solid #dcfce7;cursor:pointer;}
.hist-table tr.row-cheque-header:hover td{background:#dcfce7;}
.hist-table tr.row-used td{background:#fff;}
.hist-table tr.row-used:hover td{background:#f0fdf4;}
.hist-table tr.row-sub td{background:#f8fffe;border-bottom:1px solid #e0fdf4;font-size:11.5px;}
.hist-table tr.row-sub:hover td{background:#ecfdf5;}
.hist-table tr.row-sub.last-sub td{border-bottom:2px solid #86efac;}
.sub-row-num{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;background:#dcfce7;color:#166534;border-radius:50%;font-size:10px;font-weight:800;margin-right:5px;flex-shrink:0;}
.hist-table tr.row-empty td{background:#fafafa;color:#b0b7c3;}
.hist-table tr.row-empty:hover td{background:#f3f4f6;}
.hist-table tr.row-cancelled td{background:#fff7ed;}
.hist-table tr.row-cancelled:hover td{background:#ffedd5;}
.hist-table tfoot td{padding:10px 11px;font-weight:800;font-size:12px;background:#0f172a;color:#e2e8f0;}
.hist-table tfoot td.tr{color:#60a5fa;}
.leaf-no-pill{background:#f1f5f9;padding:2px 8px;border-radius:5px;font-family:monospace;font-size:11.5px;font-weight:700;color:#0f172a;display:inline-block;}
.leaf-no-pill.used{background:#dcfce7;color:#166534;}
.leaf-no-pill.cancelled{background:#fee2e2;color:#991b1b;}
.leaf-no-pill.empty{background:#f3f4f6;color:#9ca3af;}
.multi-badge{display:inline-flex;align-items:center;gap:4px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;padding:1px 7px;border-radius:10px;font-size:10.5px;font-weight:700;}
.expand-btn{width:20px;height:20px;border-radius:4px;border:1px solid #86efac;background:#dcfce7;color:#166534;cursor:pointer;font-size:11px;display:inline-flex;align-items:center;justify-content:center;transition:all .15s;flex-shrink:0;}
.expand-btn:hover{background:#bbf7d0;}
.hist-table tr.row-cheque-total td{background:#f0fdf4;font-size:11px;font-weight:700;border-bottom:2px solid #86efac;color:#166534;}
.leaves-left-cell{display:inline-flex;flex-direction:column;align-items:flex-end;gap:1px;}
.ll-num{font-size:13px;font-weight:800;line-height:1;}
.ll-num.green{color:#16a34a;}.ll-num.amber{color:#d97706;}.ll-num.red{color:#dc2626;}.ll-num.gray{color:#9ca3af;}
.ll-sub{font-size:9.5px;color:#9ca3af;font-weight:500;}

/* STACKED LEAF BAR (used + cancelled) */
.stacked-bar-wrap{display:flex;flex-direction:column;align-items:center;gap:2px;}
.stacked-bar{width:90px;height:7px;background:#e5e7eb;border-radius:4px;overflow:hidden;display:flex;}
.stacked-bar-used{height:100%;background:#22c55e;}
.stacked-bar-cancelled{height:100%;background:#f59e0b;}
.stacked-bar-pct{font-size:9.5px;color:#9ca3af;}

#cbToast{position:fixed;bottom:28px;right:28px;padding:12px 22px;border-radius:8px;font-size:14px;font-weight:600;color:#fff;z-index:99999;display:none;box-shadow:0 4px 16px rgba(0,0,0,.18);}
#cbToast.success{background:#15803d;}
#cbToast.error{background:#dc2626;}

/* FORM */
.fg{display:flex;flex-direction:column;gap:5px;margin-bottom:14px;}
.fg label{font-size:12px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px;}
.req{color:#ef4444;margin-left:2px;}
.form-input{padding:9px 12px;border:1px solid #d1d5db;border-radius:7px;font-size:13.5px;font-family:inherit;color:#111827;background:#fff;width:100%;outline:none;transition:border-color .2s,box-shadow .2s;}
.form-input:focus{border-color:#0f172a;box-shadow:0 0 0 3px rgba(15,23,42,.07);}
.fgrid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.sec-divider{font-size:10.5px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid #e5e7eb;padding-bottom:5px;margin:4px 0 14px;}
textarea.form-input{resize:vertical;min-height:62px;}
.lcd{display:flex;align-items:center;gap:12px;background:#0f172a;border-radius:8px;padding:12px 16px;margin-bottom:14px;}
.lcd-val{font-size:24px;font-weight:800;color:#60a5fa;line-height:1;}
.lcd-lbl{font-size:11px;color:#94a3b8;font-weight:600;}
.lcd-sub{font-size:11px;color:#94a3b8;}
.company-picker{display:flex;gap:10px;margin-bottom:14px;}
.company-opt{flex:1;padding:12px;border:2px solid #e5e7eb;border-radius:8px;text-align:center;cursor:pointer;transition:all .2s;background:#fff;}
.company-opt:hover{border-color:#0f172a;background:#f8fafc;}
.company-opt.sel-usll{border-color:#3b82f6;background:#eff6ff;}
.company-opt.sel-ulcl{border-color:#ec4899;background:#fdf2f8;}
.company-opt .co-name{font-size:15px;font-weight:800;margin-bottom:2px;}
.company-opt input{display:none;}
.company-opt.sel-usll .co-name{color:#1d4ed8;}
.company-opt.sel-ulcl .co-name{color:#9d174d;}
.co-sub-text{font-size:10px;color:#9ca3af;}

.select2-container--default .select2-selection--single{height:38px!important;border:1px solid #d1d5db!important;border-radius:7px!important;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:36px!important;padding-left:12px!important;font-size:13.5px!important;color:#111827!important;font-family:inherit!important;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:36px!important;}
.select2-dropdown{border:1px solid #d1d5db!important;border-radius:7px!important;font-size:13px!important;font-family:inherit!important;box-shadow:0 8px 24px rgba(0,0,0,.12)!important;z-index:100020!important;}
.select2-results__option--highlighted{background:#0f172a!important;}

@media(max-width:640px){.fgrid-2{grid-template-columns:1fr;}.filter-row{flex-direction:column;}}
</style>

<!-- PAGE HEADER -->
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:22px;">
    <div>
        <h2 class="page-title"><i class="fa-solid fa-book-bookmark"></i> Cheque Book Management</h2>
        <p class="page-subtitle">Track cheque books, remaining leaves and settlement history per invoice</p>
    </div>
    <button class="btn btn-book" onclick="openBookModal()">
        <i class="fa-solid fa-book-medical"></i> Add Cheque Book
    </button>
</div>

<!-- SUMMARY -->
<div class="summary-grid">
    <div class="s-card blue">
        <div class="s-label">Total Books</div>
        <div class="s-value"><?php echo number_format($total_books); ?></div>
        <div class="s-sub"><?php echo $active_books; ?> active</div>
    </div>
    <div class="s-card usll">
        <div class="s-label">USLL Books</div>
        <div class="s-value" style="color:#1d4ed8;"><?php echo count($books_usll); ?></div>
        <div class="s-sub"><?php echo $usll_active_books; ?> active</div>
    </div>
    <div class="s-card ulcl">
        <div class="s-label">ULCL Books</div>
        <div class="s-value" style="color:#9d174d;"><?php echo count($books_ulcl); ?></div>
        <div class="s-sub"><?php echo $ulcl_active_books; ?> active</div>
    </div>
    <div class="s-card green">
        <div class="s-label">USLL Leaves Left</div>
        <div class="s-value" style="color:#16a34a;"><?php echo number_format($usll_remaining); ?></div>
        <div class="s-sub">Remaining cheque leaves</div>
    </div>
    <div class="s-card amber">
        <div class="s-label">ULCL Leaves Left</div>
        <div class="s-value" style="color:#d97706;"><?php echo number_format($ulcl_remaining); ?></div>
        <div class="s-sub">Remaining cheque leaves</div>
    </div>
    <div class="s-card purple">
        <div class="s-label">Total Leaves Left</div>
        <div class="s-value" style="color:#7c3aed;"><?php echo number_format($total_leaves_remaining); ?></div>
        <div class="s-sub">Across all active books</div>
    </div>
    <div class="s-card cc">
        <div class="s-label">Customer Claim Books</div>
        <div class="s-value" style="color:#0e7490;"><?php echo number_format($cc_total); ?></div>
        <div class="s-sub"><?php echo $cc_active; ?> active · <?php echo number_format($cc_remaining); ?> leaves remaining</div>
    </div>
</div>

<!-- FILTER -->
<div class="filter-card">
    <form method="GET" action="">
        <div class="filter-row">
            <div class="ffg">
                <label>Bank Account</label>
                <select name="f_bank" class="fctrl" id="fFilterBank" style="width:240px;">
                    <option value="">— All Banks —</option>
                    <?php foreach ($bank_accounts as $ba): ?>
                    <option value="<?php echo $ba['id']; ?>" <?php echo $f_bank==$ba['id']?'selected':''; ?>><?php echo htmlspecialchars($ba['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ffg">
                <label>Status</label>
                <select name="f_status" class="fctrl" style="width:140px;">
                    <option value="">— All Status —</option>
                    <option value="Active"    <?php echo $f_status==='Active'   ?'selected':''; ?>>Active</option>
                    <option value="Used"      <?php echo $f_status==='Used'     ?'selected':''; ?>>Used</option>
                    <option value="Cancelled" <?php echo $f_status==='Cancelled'?'selected':''; ?>>Cancelled</option>
                </select>
            </div>
            <input type="hidden" name="tab" id="fTabHidden" value="<?php echo htmlspecialchars($active_tab); ?>">
            <div class="ffg" style="flex-direction:row;gap:6px;align-items:flex-end;">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Filter</button>
                <a href="cheque_book_entry.php" class="btn btn-secondary btn-sm" title="Clear"><i class="fa-solid fa-rotate-left"></i></a>
            </div>
        </div>
    </form>
</div>

<!-- COMPANY TABS -->
<div class="company-tabs">
    <button class="ctab-btn <?php echo $active_tab==='usll'?'active-usll':''; ?>" id="tab-usll" onclick="switchCompanyTab('usll')">
        <i class="fa-solid fa-building" style="color:#1d4ed8;"></i>
        USLL
        <span class="pill pill-usll"><?php echo count($books_usll); ?> books</span>
        <?php if ($usll_remaining > 0): ?>
        <span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;"><?php echo $usll_remaining; ?> leaves left</span>
        <?php endif; ?>
    </button>
    <button class="ctab-btn <?php echo $active_tab==='ulcl'?'active-ulcl':''; ?>" id="tab-ulcl" onclick="switchCompanyTab('ulcl')">
        <i class="fa-solid fa-building" style="color:#9d174d;"></i>
        ULCL
        <span class="pill pill-ulcl"><?php echo count($books_ulcl); ?> books</span>
        <?php if ($ulcl_remaining > 0): ?>
        <span style="background:#fce7f3;color:#9d174d;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;"><?php echo $ulcl_remaining; ?> leaves left</span>
        <?php endif; ?>
    </button>
    <button class="ctab-btn <?php echo $active_tab==='cc'?'active-cc':''; ?>" id="tab-cc" onclick="switchCompanyTab('cc')">
        <i class="fa-solid fa-file-invoice" style="color:#0e7490;"></i>
        Customer Claim
        <span class="pill pill-cc"><?php echo $cc_total; ?> books</span>
        <?php if ($cc_remaining > 0): ?>
        <span style="background:#cffafe;color:#155e75;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;"><?php echo number_format($cc_remaining); ?> leaves left</span>
        <?php endif; ?>
    </button>
</div>

<!-- TAB PANES -->
<div class="ctab-pane <?php echo $active_tab==='usll'?'active':''; ?>" id="pane-usll">
<?php renderBooksTable($books_usll, 'USLL', $bank_accounts); ?>
</div>
<div class="ctab-pane <?php echo $active_tab==='ulcl'?'active':''; ?>" id="pane-ulcl">
<?php renderBooksTable($books_ulcl, 'ULCL', $bank_accounts); ?>
</div>
<div class="ctab-pane <?php echo $active_tab==='cc'?'active':''; ?>" id="pane-cc">
<?php renderCCBooksTable($cc_books_all, $bank_accounts); ?>
</div>

<?php
// ══════════════════════════════════════════════════════════════════
//  RENDER FUNCTIONS
// ══════════════════════════════════════════════════════════════════

function renderBooksTable($books, $company, $bank_accounts) {
    $color = $company === 'USLL' ? '#1d4ed8' : '#9d174d';
    $books = array_values($books);
    $total_paid_all = array_sum(array_column($books, 'total_paid'));
    ?>
    <div class="table-card">
        <div class="table-toolbar">
            <div class="tbl-title">
                <i class="fa-solid fa-book" style="color:<?php echo $color; ?>;"></i>
                <span style="color:<?php echo $color; ?>;"><?php echo $company; ?></span> Cheque Books
                <span class="pill <?php echo strtolower("pill-$company"); ?>"><?php echo count($books); ?></span>
            </div>
            <button class="btn btn-book btn-sm" onclick="openBookModal('<?php echo $company; ?>')">
                <i class="fa-solid fa-book-medical"></i> Add <?php echo $company; ?> Book
            </button>
        </div>
        <div class="dt-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Bank Account</th>
                        <th>Leaf Range</th>
                        <th class="tc">Total Leaves</th>
                        <th class="tc">Used</th>
                        <th class="tc">Cancelled</th>
                        <th class="tc" style="background:#0d2a1a;">Remaining Leaves</th>
                        <th class="tc">Usage</th>
                        <th class="tr">Total Settled</th>
                        <th>Sent Date</th>
                        <th>Status</th>
                        <th>Remark</th>
                        <th class="tc">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($books)): ?>
                    <tr><td colspan="13">
                        <div class="empty-state">
                            <i class="fa-solid fa-book"></i>
                            <p>No <?php echo $company; ?> cheque books found.</p>
                        </div>
                    </td></tr>
                <?php else: $rn = 1; foreach ($books as $bk):
                    $used      = intval($bk['used_leaves']);
                    $cancelled = intval($bk['cancelled_leaves'] ?? 0);
                    $total_lv  = intval($bk['leaf_count']);
                    $remaining = max(0, $total_lv - $used - $cancelled);
                    $consumed  = $used + $cancelled;
                    $pct       = $total_lv > 0 ? min(100, round($consumed / $total_lv * 100)) : 0;
                    $bar_color = $pct < 50 ? '#22c55e' : ($pct < 80 ? '#f59e0b' : '#ef4444');
                    $rem_class = $remaining === 0 ? 'gray' : ($pct < 50 ? 'green' : ($pct < 80 ? 'amber' : 'red'));
                    $sclass    = strtolower($bk['status']);
                ?>
                    <tr id="book-tr-<?php echo $bk['id']; ?>">
                        <td style="color:#9ca3af;font-size:11px;"><?php echo $rn++; ?></td>
                        <td style="font-size:12px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                            title="<?php echo htmlspecialchars(trim(($bk['bank_label']??'').' ('.$bk['account_no'].')','/ ')); ?>">
                            <?php echo htmlspecialchars(trim($bk['bank_label']??'','/ ')); ?>
                            <br><span style="color:#9ca3af;font-size:10.5px;"><?php echo htmlspecialchars($bk['account_no']??''); ?></span>
                        </td>
                        <td>
                            <code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:11.5px;font-weight:700;color:#0f172a;"><?php echo htmlspecialchars($bk['leaf_no_start']); ?></code>
                            <span style="color:#9ca3af;font-size:11px;"> → </span>
                            <code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:11.5px;font-weight:700;color:#0f172a;"><?php echo htmlspecialchars($bk['leaf_no_end']); ?></code>
                        </td>
                        <td class="tc"><strong style="font-size:14px;color:#0f172a;"><?php echo number_format($total_lv); ?></strong></td>
                        <td class="tc" style="color:#6b7280;font-weight:600;"><?php echo number_format($used); ?></td>
                        <td class="tc">
                            <?php if ($cancelled > 0): ?>
                                <span style="background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;padding:2px 7px;border-radius:5px;font-size:11px;font-weight:700;">
                                    <i class="fa-solid fa-ban" style="font-size:9px;"></i> <?php echo number_format($cancelled); ?>
                                </span>
                            <?php else: ?>
                                <span style="color:#d1d5db;">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="tc">
                            <span class="leaf-remaining <?php echo $rem_class; ?>">
                                <i class="fa-solid fa-<?php echo $remaining === 0 ? 'ban' : ($pct < 50 ? 'leaf' : ($pct < 80 ? 'triangle-exclamation' : 'circle-xmark')); ?>" style="font-size:11px;"></i>
                                <?php echo number_format($remaining); ?>
                            </span>
                            <span class="leaf-sub" style="text-align:center;display:block;color:#9ca3af;"><?php echo $remaining > 0 ? 'of '.$total_lv : 'exhausted'; ?></span>
                        </td>
                        <td class="tc">
                            <div class="leaf-bar"><div class="leaf-bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $bar_color; ?>;"></div></div>
                            <span style="font-size:10px;color:#9ca3af;display:block;margin-top:2px;"><?php echo $pct; ?>% used/cancelled</span>
                        </td>
                        <td class="tr" style="font-weight:700;color:#1d4ed8;"><?php echo floatval($bk['total_paid']) > 0 ? number_format(floatval($bk['total_paid']),2) : '<span style="color:#d1d5db;">—</span>'; ?></td>
                        <td><?php echo !empty($bk['sent_date']) && $bk['sent_date'] != '0000-00-00'
                            ? '<span style="background:#fef9c3;color:#713f12;border:1px solid #fde68a;display:inline-block;padding:2px 7px;border-radius:6px;font-size:11px;font-weight:600;">'.date('d M Y',strtotime($bk['sent_date'])).'</span>'
                            : '—'; ?>
                        </td>
                        <td><span class="badge badge-<?php echo $sclass; ?>"><?php echo htmlspecialchars($bk['status']); ?></span></td>
                        <td style="font-size:11.5px;color:#6b7280;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($bk['remark'] ?? '—'); ?></td>
                        <td class="tc" style="white-space:nowrap;">
                            <button class="btn btn-xs btn-secondary" style="margin-bottom:3px;"
                                    onclick="viewHistory(<?php echo $bk['id']; ?>,'<?php echo addslashes(htmlspecialchars($bk['leaf_no_start'])); ?>','<?php echo addslashes(htmlspecialchars($bk['leaf_no_end'])); ?>',<?php echo $used; ?>,<?php echo $remaining; ?>,<?php echo $total_lv; ?>)"
                                    title="View All Leaves">
                                <i class="fa-solid fa-clock-rotate-left"></i> Leaves
                            </button>
                            <br>
                            <button class="btn btn-xs btn-secondary" onclick="editBook(<?php echo $bk['id']; ?>)" title="Edit"><i class="fa-solid fa-pen"></i></button>
                            <button class="btn btn-xs btn-danger" onclick="deleteBook(<?php echo $bk['id']; ?>)" title="Delete" style="margin-left:3px;"><i class="fa-solid fa-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
                <?php if (!empty($books)): ?>
                <tfoot>
                    <tr>
                        <td colspan="8" style="text-align:right;font-size:10.5px;opacity:.7;">TOTALS — <?php echo count($books); ?> books</td>
                        <td class="tr" style="color:#60a5fa;"><?php echo number_format($total_paid_all, 2); ?></td>
                        <td colspan="4"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
    <?php
}

function renderCCBooksTable($books, $bank_accounts) {
    $books = array_values($books);
    $total_leaves_all  = array_sum(array_column($books, 'leaf_count'));
    $total_used_all    = array_sum(array_column($books, 'used_leaves'));
    $total_canc_all    = array_sum(array_column($books, 'cancelled_leaves'));
    $total_claimed_all = array_sum(array_column($books, 'total_claimed'));
    ?>
    <div class="table-card">
        <div class="table-toolbar">
            <div class="tbl-title">
                <i class="fa-solid fa-file-invoice" style="color:#0e7490;"></i>
                <span style="color:#0e7490;">Customer Claim</span> Cheque Books
                <span class="pill pill-cc"><?php echo count($books); ?></span>
            </div>
            <button class="btn btn-cc btn-sm" onclick="openCCModal()">
                <i class="fa-solid fa-book-medical"></i> Add Customer Claim Book
            </button>
        </div>
        <div class="dt-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Bank Account</th>
                        <th>Customer Name</th>
                        <th>Leaf Range</th>
                        <th class="tc">Total Leaves</th>
                        <th class="tc">Used</th>
                        <th class="tc">Cancelled</th>
                        <th class="tc" style="background:#0d2a1a;">Remaining</th>
                        <th class="tc">Usage</th>
                        <th class="tr">Total Claimed</th>
                        <th>Sent Date</th>
                        <th>Status</th>
                        <th>Remark</th>
                        <th class="tc">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($books)): ?>
                    <tr><td colspan="14">
                        <div class="empty-state">
                            <i class="fa-solid fa-file-invoice"></i>
                            <p>No Customer Claim cheque books found. Click <strong>Add Customer Claim Book</strong> to get started.</p>
                        </div>
                    </td></tr>
                <?php else: $rn = 1; foreach ($books as $bk):
                    $total_lv  = intval($bk['leaf_count']);
                    $used      = intval($bk['used_leaves']);
                    $cancelled = intval($bk['cancelled_leaves']);
                    $remaining = max(0, $total_lv - $used - $cancelled);
                    $pct_used  = $total_lv > 0 ? min(100, round($used / $total_lv * 100)) : 0;
                    $pct_canc  = $total_lv > 0 ? min(100 - $pct_used, round($cancelled / $total_lv * 100)) : 0;
                    $pct_total = min(100, $pct_used + $pct_canc);
                    $rem_class = $remaining === 0 ? 'gray' : ($pct_total < 50 ? 'green' : ($pct_total < 80 ? 'amber' : 'red'));
                    $sclass    = strtolower($bk['status']);
                ?>
                    <tr id="cc-book-tr-<?php echo $bk['id']; ?>">
                        <td style="color:#9ca3af;font-size:11px;"><?php echo $rn++; ?></td>
                        <td style="font-size:12px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                            title="<?php echo htmlspecialchars(trim(($bk['bank_label']??'').' ('.$bk['account_no'].')','/ ')); ?>">
                            <?php echo htmlspecialchars(trim($bk['bank_label']??'','/ ')); ?>
                            <br><span style="color:#9ca3af;font-size:10.5px;"><?php echo htmlspecialchars($bk['account_no']??''); ?></span>
                        </td>
                        <td style="font-weight:700;color:#0e7490;"><?php echo htmlspecialchars($bk['customer_name'] ?: '—'); ?></td>
                        <td>
                            <code style="background:#ecfeff;padding:2px 6px;border-radius:4px;font-size:11.5px;font-weight:700;color:#0e7490;"><?php echo htmlspecialchars($bk['leaf_no_start']); ?></code>
                            <span style="color:#9ca3af;font-size:11px;"> → </span>
                            <code style="background:#ecfeff;padding:2px 6px;border-radius:4px;font-size:11.5px;font-weight:700;color:#0e7490;"><?php echo htmlspecialchars($bk['leaf_no_end']); ?></code>
                        </td>
                        <td class="tc"><strong style="font-size:14px;color:#0f172a;"><?php echo number_format($total_lv); ?></strong></td>
                        <td class="tc" style="color:#16a34a;font-weight:700;"><?php echo number_format($used); ?></td>
                        <td class="tc">
                            <?php if ($cancelled > 0): ?>
                                <span style="background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;padding:2px 7px;border-radius:5px;font-size:11px;font-weight:700;">
                                    <i class="fa-solid fa-ban" style="font-size:9px;"></i> <?php echo number_format($cancelled); ?>
                                </span>
                            <?php else: ?>
                                <span style="color:#d1d5db;">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="tc">
                            <span class="leaf-remaining <?php echo $rem_class; ?>">
                                <i class="fa-solid fa-<?php echo $remaining === 0 ? 'ban' : ($pct_total < 50 ? 'leaf' : ($pct_total < 80 ? 'triangle-exclamation' : 'circle-xmark')); ?>" style="font-size:11px;"></i>
                                <?php echo number_format($remaining); ?>
                            </span>
                            <span class="leaf-sub" style="text-align:center;display:block;color:#9ca3af;"><?php echo $remaining > 0 ? 'of '.$total_lv : 'exhausted'; ?></span>
                        </td>
                        <td class="tc">
                            <div class="stacked-bar-wrap">
                                <div class="stacked-bar">
                                    <div class="stacked-bar-used"   style="width:<?php echo $pct_used; ?>%;"></div>
                                    <div class="stacked-bar-cancelled" style="width:<?php echo $pct_canc; ?>%;"></div>
                                </div>
                                <div class="stacked-bar-pct"><?php echo $pct_total; ?>% used/cancelled</div>
                            </div>
                        </td>
                        <td class="tr" style="font-weight:700;color:#0e7490;"><?php echo floatval($bk['total_claimed']) > 0 ? number_format(floatval($bk['total_claimed']),2) : '<span style="color:#d1d5db;">—</span>'; ?></td>
                        <td><?php echo !empty($bk['sent_date']) && $bk['sent_date'] != '0000-00-00'
                            ? '<span style="background:#fef9c3;color:#713f12;border:1px solid #fde68a;display:inline-block;padding:2px 7px;border-radius:6px;font-size:11px;font-weight:600;">'.date('d M Y',strtotime($bk['sent_date'])).'</span>'
                            : '—'; ?>
                        </td>
                        <td><span class="badge badge-<?php echo $sclass; ?>"><?php echo htmlspecialchars($bk['status']); ?></span></td>
                        <td style="font-size:11.5px;color:#6b7280;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($bk['remark'] ?? '—'); ?></td>
                        <td class="tc" style="white-space:nowrap;">
                            <button class="btn btn-xs btn-secondary" style="margin-bottom:3px;"
                                    onclick="viewCCHistory(<?php echo $bk['id']; ?>,'<?php echo addslashes(htmlspecialchars($bk['leaf_no_start'])); ?>','<?php echo addslashes(htmlspecialchars($bk['leaf_no_end'])); ?>',<?php echo $total_lv; ?>,<?php echo $used; ?>,<?php echo $cancelled; ?>,<?php echo $remaining; ?>,'<?php echo addslashes(htmlspecialchars($bk['customer_name'])); ?>')"
                                    title="View Leaves">
                                <i class="fa-solid fa-clock-rotate-left"></i> Leaves
                            </button>
                            <br>
                            <button class="btn btn-xs btn-secondary" onclick="editCCBook(<?php echo $bk['id']; ?>)" title="Edit"><i class="fa-solid fa-pen"></i></button>
                            <button class="btn btn-xs btn-danger" onclick="deleteCCBook(<?php echo $bk['id']; ?>)" title="Delete" style="margin-left:3px;"><i class="fa-solid fa-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
                <?php if (!empty($books)): ?>
                <tfoot>
                    <tr>
                        <td colspan="4" style="text-align:right;font-size:10.5px;opacity:.7;">TOTALS — <?php echo count($books); ?> books</td>
                        <td class="tc" style="color:#60a5fa;"><?php echo number_format($total_leaves_all); ?></td>
                        <td class="tc" style="color:#4ade80;"><?php echo number_format($total_used_all); ?></td>
                        <td class="tc" style="color:#fbbf24;"><?php echo number_format($total_canc_all); ?></td>
                        <td class="tc" style="color:#4ade80;"><?php echo number_format($total_leaves_all - $total_used_all - $total_canc_all); ?></td>
                        <td></td>
                        <td class="tr" style="color:#22d3ee;"><?php echo number_format($total_claimed_all, 2); ?></td>
                        <td colspan="4"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
    <?php
}
?>

<!-- ════════ ADD / EDIT CHEQUE BOOK MODAL (Original) ════════════ -->
<div class="modal-overlay" id="bookModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title"><i class="fa-solid fa-book-bookmark" style="color:#7c3aed;"></i><span id="bookModalTitle">Add Cheque Book</span></div>
            <button class="modal-close" onclick="closeBookModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="bm_id" value="0">
            <div class="sec-divider">Company</div>
            <div class="company-picker">
                <label class="company-opt" id="bm_co_usll" onclick="selectBookCompany('USLL')">
                    <input type="radio" name="bm_company_radio" value="USLL">
                    <div class="co-name">USLL</div>
                </label>
                <label class="company-opt" id="bm_co_ulcl" onclick="selectBookCompany('ULCL')">
                    <input type="radio" name="bm_company_radio" value="ULCL">
                    <div class="co-name">ULCL</div>
                </label>
            </div>
            <input type="hidden" id="bm_company" value="">
            <div class="sec-divider">Bank Account</div>
            <div class="fg">
                <label>Bank Account <span class="req">*</span></label>
                <select id="bm_bank_account" class="form-input" style="width:100%;">
                    <option value="">— select bank account —</option>
                    <?php foreach ($bank_accounts as $ba): ?>
                    <option value="<?php echo $ba['id']; ?>"><?php echo htmlspecialchars($ba['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="sec-divider">Cheque Leaf Range</div>
            <div class="fgrid-2">
                <div class="fg" style="margin-bottom:0;">
                    <label>Leaf No — Start <span class="req">*</span></label>
                    <input type="text" class="form-input" id="bm_leaf_start" placeholder="e.g. 000001" oninput="calcLeafCount()">
                </div>
                <div class="fg" style="margin-bottom:0;">
                    <label>Leaf No — End <span class="req">*</span></label>
                    <input type="text" class="form-input" id="bm_leaf_end" placeholder="e.g. 000050" oninput="calcLeafCount()">
                </div>
            </div>
            <div style="margin:12px 0;">
                <div class="lcd">
                    <i class="fa-solid fa-layer-group" style="font-size:20px;color:#60a5fa;"></i>
                    <div><div class="lcd-lbl">Total Leaf Count</div><div class="lcd-val" id="leafCountVal">—</div></div>
                    <div style="margin-left:auto;text-align:right;"><div class="lcd-sub" id="leafRangeDisplay">Enter start &amp; end numbers</div></div>
                </div>
            </div>
            <div class="sec-divider">Details</div>
            <div class="fgrid-2">
                <div class="fg" style="margin-bottom:0;">
                    <label>Sent Date</label>
                    <input type="date" class="form-input" id="bm_sent_date">
                </div>
                <div class="fg" style="margin-bottom:0;">
                    <label>Status</label>
                    <select class="form-input" id="bm_status">
                        <option value="Active">Active</option>
                        <option value="Used">Used</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>
            </div>
            <div style="margin-bottom:14px;"></div>
            <div class="fg">
                <label>Remark</label>
                <textarea class="form-input" id="bm_remark" rows="2" placeholder="Optional notes…"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeBookModal()">Cancel</button>
            <button class="btn btn-book" id="saveBookBtn" onclick="saveBook()"><i class="fa-solid fa-floppy-disk"></i> Save Cheque Book</button>
        </div>
    </div>
</div>

<!-- ════════ ADD / EDIT CUSTOMER CLAIM CHEQUE BOOK MODAL ════════ -->
<div class="modal-overlay" id="ccModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title">
                <i class="fa-solid fa-file-invoice" style="color:#0e7490;"></i>
                <span id="ccModalTitle">Add Customer Claim Cheque Book</span>
            </div>
            <button class="modal-close" onclick="closeCCModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="cc_id" value="0">
            <div class="sec-divider">Bank Account &amp; Customer</div>
            <div class="fg">
                <label>Bank Account <span class="req">*</span></label>
                <select id="cc_bank_account" class="form-input" style="width:100%;">
                    <option value="">— select bank account —</option>
                    <?php foreach ($bank_accounts as $ba): ?>
                    <option value="<?php echo $ba['id']; ?>"><?php echo htmlspecialchars($ba['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fg">
                <label>Customer Name</label>
                <input type="text" class="form-input" id="cc_customer_name" placeholder="e.g. ABC Traders (Pvt) Ltd">
            </div>
            <div class="sec-divider">Cheque Leaf Range</div>
            <div class="fgrid-2">
                <div class="fg" style="margin-bottom:0;">
                    <label>Leaf No — Start <span class="req">*</span></label>
                    <input type="text" class="form-input" id="cc_leaf_start" placeholder="e.g. 000001" oninput="calcCCLeafCount()">
                </div>
                <div class="fg" style="margin-bottom:0;">
                    <label>Leaf No — End <span class="req">*</span></label>
                    <input type="text" class="form-input" id="cc_leaf_end" placeholder="e.g. 000050" oninput="calcCCLeafCount()">
                </div>
            </div>
            <div style="margin:12px 0;">
                <div class="lcd">
                    <i class="fa-solid fa-layer-group" style="font-size:20px;color:#22d3ee;"></i>
                    <div><div class="lcd-lbl">Total Leaf Count</div><div class="lcd-val" id="ccLeafCountVal" style="color:#22d3ee;">—</div></div>
                    <div style="margin-left:auto;text-align:right;"><div class="lcd-sub" id="ccLeafRangeDisplay">Enter start &amp; end numbers</div></div>
                </div>
            </div>
            <div class="sec-divider">Details</div>
            <div class="fgrid-2">
                <div class="fg" style="margin-bottom:0;">
                    <label>Sent Date</label>
                    <input type="date" class="form-input" id="cc_sent_date">
                </div>
                <div class="fg" style="margin-bottom:0;">
                    <label>Status</label>
                    <select class="form-input" id="cc_status">
                        <option value="Active">Active</option>
                        <option value="Used">Used</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>
            </div>
            <div style="margin-bottom:14px;"></div>
            <div class="fg">
                <label>Remark</label>
                <textarea class="form-input" id="cc_remark" rows="2" placeholder="Optional notes…"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeCCModal()">Cancel</button>
            <button class="btn btn-cc" id="saveCCBtn" onclick="saveCCBook()">
                <i class="fa-solid fa-floppy-disk"></i> Save Customer Claim Book
            </button>
        </div>
    </div>
</div>

<!-- ════════ ORIGINAL HISTORY / ALL LEAVES MODAL ════════════════ -->
<div class="modal-overlay" id="histModal">
    <div class="hist-modal-box">
        <div class="modal-header">
            <div class="modal-title"><i class="fa-solid fa-list-ol" style="color:#1d4ed8;"></i><span id="histModalTitle">Cheque Leaves</span></div>
            <button class="modal-close" onclick="closeHistModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="hist-strip">
            <div class="hstrip-cell"><div class="hstrip-lbl">Leaf Range</div><div class="hstrip-val" id="h_range">—</div></div>
            <div class="hstrip-cell"><div class="hstrip-lbl">Total Leaves</div><div class="hstrip-val" id="h_total">—</div></div>
            <div class="hstrip-cell"><div class="hstrip-lbl">Used</div><div class="hstrip-val amber" id="h_used">—</div></div>
            <div class="hstrip-cell"><div class="hstrip-lbl">Cancelled</div><div class="hstrip-val red" id="h_cancelled">—</div></div>
            <div class="hstrip-cell"><div class="hstrip-lbl">Remaining</div><div class="hstrip-val green" id="h_remaining">—</div></div>
            <div class="hstrip-cell"><div class="hstrip-lbl">Total Settled</div><div class="hstrip-val" id="h_total_paid">—</div></div>
            <div class="hstrip-cell" style="margin-left:auto;"><div class="hstrip-lbl">Multi-Payment Cheques</div><div class="hstrip-val amber" id="h_multi_count">—</div></div>
        </div>
        <div class="hist-filter-bar">
            <input type="text" class="hist-search" id="histSearch" placeholder="Search cheque no / invoice no / remark…" oninput="renderHistTable()">
            <select class="hist-filter-select" id="histFilterStatus" onchange="renderHistTable()">
                <option value="">All leaves</option>
                <option value="used">Used only</option>
                <option value="multi">Multi-payment cheques</option>
                <option value="cancelled">Cancelled only</option>
                <option value="empty">Empty only</option>
            </select>
            <button class="btn btn-xs btn-secondary" onclick="expandAllGroups()"><i class="fa-solid fa-expand"></i> Expand All</button>
            <button class="btn btn-xs btn-secondary" onclick="collapseAllGroups()"><i class="fa-solid fa-compress"></i> Collapse All</button>
            <span class="hist-count-badge" id="histCountBadge"></span>
        </div>
        <div style="overflow-y:auto;flex:1;">
            <table class="hist-table">
                <thead>
                    <tr>
                        <th style="width:36px;">#</th>
                        <th style="width:32px;"></th>
                        <th>Cheque No</th>
                        <th class="tc" style="width:80px;">Status</th>
                        <th>Paid Date</th>
                        <th class="tr">Paid Amount</th>
                        <th>Invoice No</th>
                        <th>Company</th>
                        <th class="tr">Invoice Value</th>
                        <th class="tc">Inv. Status</th>
                        <th class="tr" style="width:90px;">Leaves Left</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody id="histTableBody">
                    <tr><td colspan="12" style="text-align:center;padding:40px;color:#9ca3af;">
                        <i class="fa-solid fa-spinner fa-spin" style="font-size:22px;"></i>
                        <p style="margin-top:10px;">Loading leaves…</p>
                    </td></tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="5" style="text-align:right;font-size:10.5px;opacity:.7;" id="histFooterLabel">—</td>
                        <td class="tr" id="histFooterTotal">—</td>
                        <td colspan="6"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- ════════ CUSTOMER CLAIM LEAVES MODAL (Full — linked data) ════ -->
<div class="modal-overlay" id="ccHistModal">
    <div class="hist-modal-box">
        <div class="modal-header">
            <div class="modal-title">
                <i class="fa-solid fa-list-ol" style="color:#0e7490;"></i>
                <span id="ccHistModalTitle">Customer Claim Leaves</span>
            </div>
            <button class="modal-close" onclick="closeCCHistModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <!-- Info strip -->
        <div class="hist-strip" style="background:#0c4a6e;">
            <div class="hstrip-cell"><div class="hstrip-lbl">Leaf Range</div><div class="hstrip-val" id="cc_h_range">—</div></div>
            <div class="hstrip-cell"><div class="hstrip-lbl">Total Leaves</div><div class="hstrip-val" id="cc_h_total">—</div></div>
            <div class="hstrip-cell"><div class="hstrip-lbl">Used</div><div class="hstrip-val amber" id="cc_h_used">—</div></div>
            <div class="hstrip-cell"><div class="hstrip-lbl">Cancelled</div><div class="hstrip-val red" id="cc_h_cancelled">—</div></div>
            <div class="hstrip-cell"><div class="hstrip-lbl">Available</div><div class="hstrip-val green" id="cc_h_avail">—</div></div>
            <div class="hstrip-cell"><div class="hstrip-lbl">Total Claimed</div><div class="hstrip-val cyan" id="cc_h_claimed">—</div></div>
            <div class="hstrip-cell" style="margin-left:auto;"><div class="hstrip-lbl">Customer</div><div class="hstrip-val" id="cc_h_customer" style="color:#22d3ee;">—</div></div>
        </div>
        <!-- Filter bar -->
        <div class="hist-filter-bar">
            <input type="text" class="hist-search" id="ccHistSearch" placeholder="Search cheque no / customer / invoice…" oninput="renderCCHistTable()">
            <select class="hist-filter-select" id="ccHistFilterStatus" onchange="renderCCHistTable()">
                <option value="">All leaves</option>
                <option value="used">Used only</option>
                <option value="cancelled">Cancelled only</option>
                <option value="available">Available only</option>
            </select>
            <span class="hist-count-badge" id="ccHistCountBadge"></span>
        </div>
        <!-- Table -->
        <div style="overflow-y:auto;flex:1;">
            <table class="hist-table">
                <thead>
                    <tr>
                        <th style="width:36px;">#</th>
                        <th style="width:32px;"></th>
                        <th>Cheque No</th>
                        <th class="tc">Status</th>
                        <th>Customer</th>
                        <th class="tr">Claim Amount</th>
                        <th>Acknowledgment</th>
                        <th>Entity</th>
                        <th>Invoice Date</th>
                        <th>Bank Account</th>
                        <th class="tr">Leaves Left</th>
                        <th>Remarks / Reason</th>
                    </tr>
                </thead>
                <tbody id="ccHistTableBody">
                    <tr><td colspan="12" style="text-align:center;padding:40px;color:#9ca3af;">
                        <i class="fa-solid fa-spinner fa-spin" style="font-size:22px;"></i>
                        <p style="margin-top:10px;">Loading leaves…</p>
                    </td></tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="5" style="text-align:right;font-size:10.5px;opacity:.7;" id="ccHistFooterLabel">—</td>
                        <td class="tr" id="ccHistFooterTotal">—</td>
                        <td colspan="6"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<div id="cbToast"></div>

<script>
$(function(){
    document.body.appendChild(document.getElementById('bookModal'));
    document.body.appendChild(document.getElementById('ccModal'));
    document.body.appendChild(document.getElementById('histModal'));
    document.body.appendChild(document.getElementById('ccHistModal'));
    $('#bm_bank_account').select2({placeholder:'— select bank account —', allowClear:true, dropdownParent:$('#bookModal')});
    $('#cc_bank_account').select2({placeholder:'— select bank account —', allowClear:true, dropdownParent:$('#ccModal')});
    $('#fFilterBank').select2({placeholder:'— All Banks —', allowClear:true, width:'240px'});
});

// ── COMPANY TABS ──────────────────────────────────────────────────
function switchCompanyTab(co) {
    document.querySelectorAll('.ctab-btn').forEach(b => { b.className = 'ctab-btn'; });
    document.querySelectorAll('.ctab-pane').forEach(p => p.classList.remove('active'));
    document.getElementById('pane-' + co).classList.add('active');
    document.getElementById('tab-' + co).classList.add('active-' + co);
    document.getElementById('fTabHidden').value = co;
}

// ── LEAF COUNT helpers ────────────────────────────────────────────
function calcLeafCount() {
    const s = document.getElementById('bm_leaf_start').value.trim();
    const e = document.getElementById('bm_leaf_end').value.trim();
    const sn = parseInt(s.replace(/\D/g,'')), en = parseInt(e.replace(/\D/g,''));
    const v = document.getElementById('leafCountVal'), r = document.getElementById('leafRangeDisplay');
    if (!isNaN(sn) && !isNaN(en) && en >= sn) { v.textContent=(en-sn+1).toLocaleString(); r.textContent=s+' → '+e; }
    else { v.textContent='—'; r.textContent='Enter start & end numbers'; }
}
function calcCCLeafCount() {
    const s = document.getElementById('cc_leaf_start').value.trim();
    const e = document.getElementById('cc_leaf_end').value.trim();
    const sn = parseInt(s.replace(/\D/g,'')), en = parseInt(e.replace(/\D/g,''));
    const v = document.getElementById('ccLeafCountVal'), r = document.getElementById('ccLeafRangeDisplay');
    if (!isNaN(sn) && !isNaN(en) && en >= sn) { v.textContent=(en-sn+1).toLocaleString(); r.textContent=s+' → '+e; }
    else { v.textContent='—'; r.textContent='Enter start & end numbers'; }
}

// ── COMPANY PICKER ────────────────────────────────────────────────
function selectBookCompany(co) {
    document.getElementById('bm_company').value = co;
    document.getElementById('bm_co_usll').className = 'company-opt' + (co==='USLL'?' sel-usll':'');
    document.getElementById('bm_co_ulcl').className = 'company-opt' + (co==='ULCL'?' sel-ulcl':'');
}

// ── ORIGINAL CHEQUE BOOK MODAL ────────────────────────────────────
function openBookModal(presetCompany) {
    document.getElementById('bookModalTitle').textContent = 'Add Cheque Book';
    document.getElementById('bm_id').value = '0';
    document.getElementById('bm_company').value = '';
    document.getElementById('bm_co_usll').className = 'company-opt';
    document.getElementById('bm_co_ulcl').className = 'company-opt';
    $('#bm_bank_account').val('').trigger('change');
    ['bm_leaf_start','bm_leaf_end','bm_sent_date','bm_remark'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('bm_status').value = 'Active';
    document.getElementById('leafCountVal').textContent = '—';
    document.getElementById('leafRangeDisplay').textContent = 'Enter start & end numbers';
    if (presetCompany) selectBookCompany(presetCompany);
    document.getElementById('bookModal').classList.add('open');
}
function closeBookModal() { document.getElementById('bookModal').classList.remove('open'); }

function editBook(id) {
    fetch('cheque_book_entry.php?action=get_book&id=' + id)
    .then(r => r.json()).then(res => {
        if (!res.success || !res.data) { showToast('Could not load record.', 'error'); return; }
        const d = res.data;
        document.getElementById('bookModalTitle').textContent = 'Edit Cheque Book';
        document.getElementById('bm_id').value = d.id;
        selectBookCompany(d.company);
        $('#bm_bank_account').val(d.bank_account_id).trigger('change');
        document.getElementById('bm_leaf_start').value = d.leaf_no_start || '';
        document.getElementById('bm_leaf_end').value   = d.leaf_no_end   || '';
        document.getElementById('bm_sent_date').value  = d.sent_date && d.sent_date !== '0000-00-00' ? d.sent_date : '';
        document.getElementById('bm_remark').value     = d.remark  || '';
        document.getElementById('bm_status').value     = d.status  || 'Active';
        calcLeafCount();
        document.getElementById('bookModal').classList.add('open');
    }).catch(() => showToast('Failed to load record.', 'error'));
}

function saveBook() {
    const company = document.getElementById('bm_company').value;
    const bankId  = $('#bm_bank_account').val();
    const ls = document.getElementById('bm_leaf_start').value.trim();
    const le = document.getElementById('bm_leaf_end').value.trim();
    if (!company) { showToast('Please select a company.', 'error'); return; }
    if (!bankId)  { showToast('Please select a bank account.', 'error'); return; }
    if (!ls)      { showToast('Please enter Leaf No Start.', 'error'); return; }
    if (!le)      { showToast('Please enter Leaf No End.', 'error'); return; }
    if (parseInt(le.replace(/\D/g,'')) < parseInt(ls.replace(/\D/g,''))) { showToast('Leaf End must be ≥ Leaf Start.', 'error'); return; }
    const fd = new FormData();
    fd.append('id', document.getElementById('bm_id').value);
    fd.append('bank_account_id', bankId); fd.append('company', company);
    fd.append('leaf_no_start', ls); fd.append('leaf_no_end', le);
    fd.append('sent_date', document.getElementById('bm_sent_date').value);
    fd.append('remark', document.getElementById('bm_remark').value);
    fd.append('status', document.getElementById('bm_status').value);
    const btn = document.getElementById('saveBookBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    fetch('cheque_book_entry.php?action=save_book', { method:'POST', body:fd })
    .then(r => r.json()).then(res => {
        btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Cheque Book';
        if (res.success) { showToast('Cheque book saved!', 'success'); closeBookModal(); setTimeout(() => location.reload(), 700); }
        else showToast('Error: ' + (res.message || 'Unknown'), 'error');
    }).catch(() => { btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Cheque Book'; showToast('Network error.','error'); });
}

function deleteBook(id) {
    if (!confirm('Delete this cheque book? This action cannot be undone.')) return;
    const fd = new FormData(); fd.append('id', id);
    fetch('cheque_book_entry.php?action=delete_book', { method:'POST', body:fd })
    .then(r => r.json()).then(res => {
        if (res.success) { document.getElementById('book-tr-'+id)?.remove(); showToast('Cheque book deleted.', 'success'); }
        else showToast('Error: ' + (res.message || 'Delete failed'), 'error');
    }).catch(() => showToast('Network error.', 'error'));
}

// ── CUSTOMER CLAIM MODAL ──────────────────────────────────────────
function openCCModal() {
    document.getElementById('ccModalTitle').textContent = 'Add Customer Claim Cheque Book';
    document.getElementById('cc_id').value = '0';
    $('#cc_bank_account').val('').trigger('change');
    ['cc_customer_name','cc_leaf_start','cc_leaf_end','cc_sent_date','cc_remark'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('cc_status').value = 'Active';
    document.getElementById('ccLeafCountVal').textContent = '—';
    document.getElementById('ccLeafRangeDisplay').textContent = 'Enter start & end numbers';
    document.getElementById('ccModal').classList.add('open');
}
function closeCCModal() { document.getElementById('ccModal').classList.remove('open'); }

function editCCBook(id) {
    fetch('cheque_book_entry.php?action=get_cc_book&id=' + id)
    .then(r => r.json()).then(res => {
        if (!res.success || !res.data) { showToast('Could not load record.', 'error'); return; }
        const d = res.data;
        document.getElementById('ccModalTitle').textContent = 'Edit Customer Claim Cheque Book';
        document.getElementById('cc_id').value = d.id;
        $('#cc_bank_account').val(d.bank_account_id).trigger('change');
        document.getElementById('cc_customer_name').value = d.customer_name || '';
        document.getElementById('cc_leaf_start').value    = d.leaf_no_start || '';
        document.getElementById('cc_leaf_end').value      = d.leaf_no_end   || '';
        document.getElementById('cc_sent_date').value     = d.sent_date && d.sent_date !== '0000-00-00' ? d.sent_date : '';
        document.getElementById('cc_remark').value        = d.remark  || '';
        document.getElementById('cc_status').value        = d.status  || 'Active';
        calcCCLeafCount();
        document.getElementById('ccModal').classList.add('open');
    }).catch(() => showToast('Failed to load record.', 'error'));
}

function saveCCBook() {
    const bankId = $('#cc_bank_account').val();
    const ls = document.getElementById('cc_leaf_start').value.trim();
    const le = document.getElementById('cc_leaf_end').value.trim();
    if (!bankId) { showToast('Please select a bank account.', 'error'); return; }
    if (!ls)     { showToast('Please enter Leaf No Start.', 'error'); return; }
    if (!le)     { showToast('Please enter Leaf No End.', 'error'); return; }
    if (parseInt(le.replace(/\D/g,'')) < parseInt(ls.replace(/\D/g,''))) { showToast('Leaf End must be ≥ Leaf Start.', 'error'); return; }
    const fd = new FormData();
    fd.append('id', document.getElementById('cc_id').value);
    fd.append('bank_account_id', bankId);
    fd.append('customer_name', document.getElementById('cc_customer_name').value);
    fd.append('leaf_no_start', ls); fd.append('leaf_no_end', le);
    fd.append('sent_date', document.getElementById('cc_sent_date').value);
    fd.append('remark', document.getElementById('cc_remark').value);
    fd.append('status', document.getElementById('cc_status').value);
    const btn = document.getElementById('saveCCBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    fetch('cheque_book_entry.php?action=save_cc_book', { method:'POST', body:fd })
    .then(r => r.json()).then(res => {
        btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Customer Claim Book';
        if (res.success) { showToast('Customer Claim book saved!', 'success'); closeCCModal(); setTimeout(() => location.reload(), 700); }
        else showToast('Error: ' + (res.message || 'Unknown'), 'error');
    }).catch(() => { btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Customer Claim Book'; showToast('Network error.','error'); });
}

function deleteCCBook(id) {
    if (!confirm('Delete this Customer Claim cheque book?')) return;
    const fd = new FormData(); fd.append('id', id);
    fetch('cheque_book_entry.php?action=delete_cc_book', { method:'POST', body:fd })
    .then(r => r.json()).then(res => {
        if (res.success) { document.getElementById('cc-book-tr-'+id)?.remove(); showToast('Deleted.', 'success'); }
        else showToast('Error: ' + (res.message || 'Delete failed'), 'error');
    }).catch(() => showToast('Network error.', 'error'));
}

// ══════════════════════════════════════════════════════════════════
//  SHARED: Reverse a cancelled leaf
//  Works for BOTH the Original and Customer Claim leaves modals.
//  Cancellations themselves are only ever created on the Cheque
//  Acknowledgment screen (cheque_acknowledgment_new.php); this page
//  can only undo (reverse) them. reloadFn re-fetches & re-renders
//  whichever modal is open without resetting its title/range.
// ══════════════════════════════════════════════════════════════════
function reverseCancelledLeaf(cancelId, reloadFn) {
    if (!cancelId) { showToast('Missing cancellation reference.', 'error'); return; }
    if (!confirm('Reverse this cancellation and make the leaf available again?')) return;
    const fd = new FormData(); fd.append('id', cancelId);
    fetch('cheque_book_entry.php?action=reverse_cancel', { method:'POST', body:fd })
    .then(r => r.json()).then(res => {
        if (res.success) { showToast('Cancellation reversed.', 'success'); reloadFn(); }
        else showToast('Error: ' + (res.message || 'Unknown'), 'error');
    }).catch(() => showToast('Network error.', 'error'));
}

// ══════════════════════════════════════════════════════════════════
//  CC HISTORY MODAL — full linked leaf table
// ══════════════════════════════════════════════════════════════════
let _ccHistAllLeaves   = [];
let _ccExpandedCheques = new Set();
let _ccHistBookId      = 0;

function viewCCHistory(bookId, leafStart, leafEnd, total, used, cancelled, avail, customerName) {
    _ccHistAllLeaves   = [];
    _ccExpandedCheques = new Set();
    _ccHistBookId      = bookId;
    document.getElementById('ccHistModalTitle').textContent  = 'Customer Claim Leaves: ' + leafStart + ' – ' + leafEnd;
    document.getElementById('cc_h_range').textContent     = leafStart + ' – ' + leafEnd;
    document.getElementById('cc_h_total').textContent     = total;
    document.getElementById('cc_h_used').textContent      = used;
    document.getElementById('cc_h_cancelled').textContent = cancelled;
    document.getElementById('cc_h_avail').textContent     = avail;
    document.getElementById('cc_h_claimed').textContent   = '…';
    document.getElementById('cc_h_customer').textContent  = customerName || '—';
    document.getElementById('ccHistSearch').value         = '';
    document.getElementById('ccHistFilterStatus').value   = '';
    document.getElementById('ccHistTableBody').innerHTML  =
        '<tr><td colspan="12" style="text-align:center;padding:40px;color:#9ca3af;"><i class="fa-solid fa-spinner fa-spin" style="font-size:22px;"></i><p style="margin-top:10px;">Loading leaves…</p></td></tr>';
    document.getElementById('ccHistModal').classList.add('open');
    reloadCCHist();
}

function reloadCCHist() {
    if (!_ccHistBookId) return;
    fetch('cheque_book_entry.php?action=get_cc_history&book_id=' + _ccHistBookId)
    .then(r => r.json()).then(res => {
        if (!res.success) {
            document.getElementById('ccHistTableBody').innerHTML =
                '<tr><td colspan="12" style="color:#dc2626;padding:20px;">Failed to load leaves: ' + escH(res.message||'Unknown error') + '</td></tr>';
            return;
        }
        _ccHistAllLeaves = res.data;
        document.getElementById('cc_h_used').textContent      = res.used_count;
        document.getElementById('cc_h_cancelled').textContent = res.cancelled_count;
        document.getElementById('cc_h_avail').textContent     = res.avail_count;
        document.getElementById('cc_h_total').textContent     = res.total_leaves;
        document.getElementById('cc_h_claimed').textContent   = res.total_claimed > 0
            ? parseFloat(res.total_claimed).toLocaleString('en',{minimumFractionDigits:2}) : '—';
        renderCCHistTable();
    }).catch(() => {
        document.getElementById('ccHistTableBody').innerHTML =
            '<tr><td colspan="12" style="color:#dc2626;padding:20px;">Network error.</td></tr>';
    });
}

function buildCCLeavesLeftMap(allLeaves) {
    const total = allLeaves.length;
    let usedOrCancelled = 0;
    const map = {};
    allLeaves.forEach(l => {
        if (l.status === 'used' || l.status === 'cancelled') usedOrCancelled++;
        map[l.leaf_no] = total - usedOrCancelled;
    });
    return map;
}

function renderCCHistTable() {
    const search   = (document.getElementById('ccHistSearch').value || '').toLowerCase().trim();
    const filterSt = document.getElementById('ccHistFilterStatus').value;
    const leavesLeftMap = buildCCLeavesLeftMap(_ccHistAllLeaves);

    let filtered = _ccHistAllLeaves.filter(l => {
        if (filterSt && l.status !== filterSt) return false;
        if (search) {
            const base = [l.leaf_no, l.status].join(' ').toLowerCase();
            const subs = (l.payments || []).map(p =>
                [p.cheque_no||'', p.customer_name||'', p.customer_code||'', p.tax_invoice_no||'', p.entity||'', p.reason||''].join(' ').toLowerCase()
            ).join(' ');
            if (!(base + ' ' + subs + ' ' + (l.reason||'').toLowerCase()).includes(search)) return false;
        }
        return true;
    });

    const usedInView     = filtered.filter(l => l.status === 'used');
    const cancelledInView= filtered.filter(l => l.status === 'cancelled');
    const totalClaimedView = usedInView.reduce((s,l) => s + parseFloat(l.paid_amount||0), 0);

    document.getElementById('ccHistCountBadge').textContent =
        filtered.length + ' leaves' +
        (usedInView.length ? ' · ' + usedInView.length + ' used' : '') +
        (cancelledInView.length ? ' · ' + cancelledInView.length + ' cancelled' : '') +
        ((filtered.length - usedInView.length - cancelledInView.length) ? ' · ' + (filtered.length - usedInView.length - cancelledInView.length) + ' available' : '');

    document.getElementById('ccHistFooterLabel').textContent = usedInView.length + ' used in view';
    document.getElementById('ccHistFooterTotal').textContent = totalClaimedView > 0
        ? totalClaimedView.toLocaleString('en',{minimumFractionDigits:2}) : '—';

    if (filtered.length === 0) {
        document.getElementById('ccHistTableBody').innerHTML =
            '<tr><td colspan="12" style="text-align:center;padding:40px;color:#9ca3af;"><i class="fa-solid fa-magnifying-glass" style="font-size:22px;opacity:.3;"></i><p style="margin-top:10px;">No leaves match your filter.</p></td></tr>';
        return;
    }

    let rows = '';
    let rowNum = 1;

    filtered.forEach(l => {
        const leavesLeft = leavesLeftMap[l.leaf_no] ?? 0;
        const llClass    = leavesLeft <= 0 ? 'red' : leavesLeft <= 5 ? 'amber' : 'green';

        if (l.status === 'available') {
            rows += `<tr class="row-empty">
                <td style="font-size:11px;color:#9ca3af;">${rowNum++}</td>
                <td></td>
                <td><span class="leaf-no-pill empty">${escH(l.leaf_no)}</span></td>
                <td class="tc"><span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;">Available</span></td>
                <td colspan="6"><span style="color:#d1d5db;">—</span></td>
                <td class="tr"><div class="leaves-left-cell"><span class="ll-num ${llClass}">${leavesLeft}</span><span class="ll-sub">remaining</span></div></td>
                <td><span style="color:#d1d5db;">—</span></td>
            </tr>`;
            return;
        }

        if (l.status === 'cancelled') {
            rows += `<tr class="row-cancelled">
                <td style="font-size:11px;color:#9ca3af;">${rowNum++}</td>
                <td></td>
                <td><span class="leaf-no-pill cancelled">${escH(l.leaf_no)}</span></td>
                <td class="tc"><span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;"><i class="fa-solid fa-ban" style="font-size:9px;"></i> Cancelled</span></td>
                <td colspan="6" style="color:#9ca3af;font-size:11.5px;">
                    <span style="font-size:10px;color:#9ca3af;">${l.cancelled_at ? l.cancelled_at.slice(0,10) : ''}</span>
                </td>
                <td class="tr"><div class="leaves-left-cell"><span class="ll-num gray">${leavesLeft}</span><span class="ll-sub">remaining</span></div></td>
                <td style="font-size:11.5px;color:#c2410c;">${escH(l.reason || '—')}
                    <button class="btn btn-xs btn-secondary" style="margin-left:6px;" onclick="reverseCancelledLeaf(${l.cancel_id}, reloadCCHist)"><i class="fa-solid fa-rotate-left"></i> Reverse</button>
                </td>
            </tr>`;
            return;
        }

        // Used — single or multi (includes customer-line claims AND employee
        // batch cheques — the latter are pre-shaped server-side into the same
        // payments[] structure so no extra branching is needed here).
        const isMulti    = l.payment_count > 1;
        const isExpanded = _ccExpandedCheques.has(l.leaf_no);
        const totalAmt   = parseFloat(l.paid_amount || 0);

        if (!isMulti) {
            const p = l.payments[0];
            rows += `<tr class="row-used">
                <td style="color:#9ca3af;font-size:11px;">${rowNum++}</td>
                <td></td>
                <td><span class="leaf-no-pill used">${escH(l.leaf_no)}</span></td>
                <td class="tc"><span style="color:#16a34a;font-size:12px;font-weight:700;">&#10003; Used</span></td>
                <td style="font-weight:600;color:#0e7490;">${escH(p.customer_name||'—')}
                    ${p.customer_code ? '<br><span style="font-size:10px;color:#9ca3af;">['+escH(p.customer_code)+']</span>' : ''}
                </td>
                <td class="tr"><strong style="color:#0e7490;">${fmtAmt(p.claim_amount)}</strong></td>
                <td style="font-size:12px;">
                    ${p.tax_invoice_no ? '<strong>'+escH(p.tax_invoice_no)+'</strong>' : '—'}
                    ${p.claim_description ? '<br><span style="font-size:10px;color:#9ca3af;">'+escH(p.claim_description.substring(0,40))+'…</span>' : ''}
                </td>
                <td>${escH(p.entity||'—')}</td>
                <td style="font-size:11.5px;">${p.invoice_date && p.invoice_date!=='0000-00-00' ? fmtDateStr(p.invoice_date) : '—'}</td>
                <td style="font-size:11.5px;color:#6b7280;">${escH(p.bank_account_no||'—')}<br><span style="font-size:10px;">${escH(p.bank_name||'')}</span></td>
                <td class="tr"><div class="leaves-left-cell"><span class="ll-num ${llClass}">${leavesLeft}</span><span class="ll-sub">remaining</span></div></td>
                <td style="font-size:11.5px;color:#6b7280;">—</td>
            </tr>`;
        } else {
            const expandIcon = isExpanded
                ? '<i class="fa-solid fa-chevron-down" style="font-size:10px;"></i>'
                : '<i class="fa-solid fa-chevron-right" style="font-size:10px;"></i>';
            rows += `<tr class="row-cheque-header" onclick="toggleCCGroup('${escH(l.leaf_no)}')">
                <td style="color:#9ca3af;font-size:11px;">${rowNum++}</td>
                <td class="tc"><button class="expand-btn">${expandIcon}</button></td>
                <td><span class="leaf-no-pill used">${escH(l.leaf_no)}</span>
                    <span class="multi-badge" style="margin-left:6px;background:#cffafe;color:#155e75;border-color:#a5f3fc;">
                        <i class="fa-solid fa-layer-group" style="font-size:9px;"></i> ${l.payment_count} claims
                    </span>
                </td>
                <td class="tc"><span style="color:#16a34a;font-size:12px;font-weight:700;">&#10003; Used</span></td>
                <td colspan="4" style="color:#64748b;font-size:11.5px;">
                    <i class="fa-solid fa-users" style="color:#0e7490;margin-right:4px;"></i>
                    ${l.payments.map(p=>escH(p.customer_name||'')).join(', ')}
                    <span style="color:#94a3b8;margin-left:6px;">(click to ${isExpanded?'hide':'show'} detail)</span>
                </td>
                <td class="tr"><strong style="color:#0e7490;font-size:13px;">${fmtAmt(totalAmt)}</strong>
                    <span style="display:block;font-size:10px;color:#6b7280;">total of ${l.payment_count}</span></td>
                <td class="tr"><div class="leaves-left-cell"><span class="ll-num ${llClass}">${leavesLeft}</span><span class="ll-sub">remaining</span></div></td>
                <td></td>
            </tr>`;

            if (isExpanded) {
                l.payments.forEach((p, pi) => {
                    const isLast = (pi === l.payments.length - 1);
                    rows += `<tr class="row-sub${isLast?' last-sub':''}">
                        <td style="color:#94a3b8;font-size:10px;">${pi+1}</td>
                        <td></td>
                        <td class="sub-indent" style="padding-left:28px;">
                            <span class="sub-row-num">${pi+1}</span>
                            <span style="font-family:monospace;font-size:11px;color:#475569;">${escH(p.cheque_no||l.leaf_no)}</span>
                        </td>
                        <td class="tc"><span style="color:#16a34a;font-size:11px;">&#10003;</span></td>
                        <td style="font-weight:600;color:#0e7490;">${escH(p.customer_name||'—')}
                            ${p.customer_code ? '<br><span style="font-size:10px;color:#9ca3af;">['+escH(p.customer_code)+']</span>' : ''}
                        </td>
                        <td class="tr"><strong style="color:#0e7490;">${fmtAmt(p.claim_amount)}</strong></td>
                        <td style="font-size:12px;">${p.tax_invoice_no ? '<strong>'+escH(p.tax_invoice_no)+'</strong>' : '—'}</td>
                        <td>${escH(p.entity||'—')}</td>
                        <td style="font-size:11.5px;">${p.invoice_date && p.invoice_date!=='0000-00-00' ? fmtDateStr(p.invoice_date) : '—'}</td>
                        <td style="font-size:11.5px;color:#6b7280;">${escH(p.bank_account_no||'—')}</td>
                        <td class="tr"><span style="color:#b0b7c3;font-size:10px;">↑ same</span></td>
                        <td>—</td>
                    </tr>`;
                });
                rows += `<tr class="row-cheque-total">
                    <td colspan="5" style="text-align:right;font-size:10.5px;color:#0e7490;padding-right:10px;">
                        Cheque ${escH(l.leaf_no)} total across ${l.payment_count} claims →
                    </td>
                    <td class="tr" style="color:#0e7490;font-size:13px;">${fmtAmt(totalAmt)}</td>
                    <td colspan="6"></td>
                </tr>`;
            }
        }
    });

    document.getElementById('ccHistTableBody').innerHTML = rows;
}

function toggleCCGroup(leafNo) {
    if (_ccExpandedCheques.has(leafNo)) _ccExpandedCheques.delete(leafNo);
    else _ccExpandedCheques.add(leafNo);
    renderCCHistTable();
}
function closeCCHistModal() { document.getElementById('ccHistModal').classList.remove('open'); _ccExpandedCheques.clear(); }

// ══════════════════════════════════════════════════════════════════
//  ORIGINAL HISTORY MODAL
// ══════════════════════════════════════════════════════════════════
let _histAllLeaves   = [];
let _expandedCheques = new Set();
let _histBookId      = 0;

function viewHistory(bookId, leafStart, leafEnd, used, remaining, total) {
    _histAllLeaves   = [];
    _expandedCheques = new Set();
    _histBookId      = bookId;
    document.getElementById('histModalTitle').textContent = 'Leaves: ' + leafStart + ' – ' + leafEnd;
    document.getElementById('h_range').textContent     = leafStart + ' – ' + leafEnd;
    document.getElementById('h_total').textContent     = total;
    document.getElementById('h_used').textContent      = used;
    document.getElementById('h_cancelled').textContent = '…';
    document.getElementById('h_remaining').textContent = remaining;
    document.getElementById('h_total_paid').textContent = '…';
    document.getElementById('h_multi_count').textContent = '…';
    document.getElementById('histCountBadge').textContent = '';
    document.getElementById('histSearch').value = '';
    document.getElementById('histFilterStatus').value = '';
    document.getElementById('histTableBody').innerHTML =
        '<tr><td colspan="12" style="text-align:center;padding:40px;color:#9ca3af;"><i class="fa-solid fa-spinner fa-spin" style="font-size:22px;"></i><p style="margin-top:10px;">Loading leaves…</p></td></tr>';
    document.getElementById('histModal').classList.add('open');
    reloadHist();
}

function reloadHist() {
    if (!_histBookId) return;
    fetch('cheque_book_entry.php?action=get_history&book_id=' + _histBookId)
    .then(r => r.json()).then(res => {
        if (!res.success) {
            document.getElementById('histTableBody').innerHTML =
                '<tr><td colspan="12" style="color:#dc2626;padding:20px;">Failed to load leaves.</td></tr>';
            return;
        }
        _histAllLeaves = res.data;
        const usedLeaves = res.data.filter(l => l.used);
        const totalPaid  = usedLeaves.reduce((s,l) => s + parseFloat(l.paid_amount || 0), 0);
        const multiCount = usedLeaves.filter(l => l.payment_count > 1).length;
        document.getElementById('h_used').textContent        = res.used_count;
        document.getElementById('h_cancelled').textContent   = res.cancelled_count;
        document.getElementById('h_remaining').textContent   = res.empty_count;
        document.getElementById('h_total').textContent       = res.total_leaves;
        document.getElementById('h_total_paid').textContent  = totalPaid > 0
            ? totalPaid.toLocaleString('en', {minimumFractionDigits:2}) : '—';
        document.getElementById('h_multi_count').textContent = multiCount > 0 ? multiCount : '0';
        renderHistTable();
    }).catch(() => {
        document.getElementById('histTableBody').innerHTML =
            '<tr><td colspan="12" style="color:#dc2626;padding:20px;">Network error.</td></tr>';
    });
}

function buildLeavesLeftMap(allLeaves) {
    const total = allLeaves.length;
    let usedPassedSoFar = 0;
    const map = {};
    allLeaves.forEach(l => {
        if (l.used || l.cancelled) usedPassedSoFar++;
        map[l.leaf_no] = total - usedPassedSoFar;
    });
    return map;
}

function leavesLeftCell(leavesLeft, isUsed) {
    const c = leavesLeft <= 0 ? 'red' : leavesLeft <= 5 ? 'red' : leavesLeft <= 15 ? 'amber' : 'green';
    if (!isUsed) return `<div class="leaves-left-cell"><span class="ll-num gray">${leavesLeft}</span><span class="ll-sub">remaining</span></div>`;
    const icon = leavesLeft <= 0 ? '✗' : leavesLeft <= 5 ? '!' : '';
    return `<div class="leaves-left-cell"><span class="ll-num ${c}">${icon ? icon+' ' : ''}${leavesLeft}</span><span class="ll-sub">remaining</span></div>`;
}

function renderHistTable() {
    const search   = (document.getElementById('histSearch').value || '').toLowerCase().trim();
    const filterSt = document.getElementById('histFilterStatus').value;
    const leavesLeftMap = buildLeavesLeftMap(_histAllLeaves);

    let filtered = _histAllLeaves.filter(l => {
        if (filterSt === 'used'      && !l.used)                           return false;
        if (filterSt === 'empty'     && (l.used || l.cancelled))           return false;
        if (filterSt === 'cancelled' && !l.cancelled)                      return false;
        if (filterSt === 'multi'     && (!l.used || l.payment_count <= 1)) return false;
        if (search) {
            const base = [l.leaf_no, l.cheque_no||'', l.reason||''].join(' ').toLowerCase();
            const subs = (l.payments||[]).map(p =>
                [p.cheque_no||'', p.invoice_no||'', p.remarks||'', p.company||''].join(' ').toLowerCase()
            ).join(' ');
            if (!(base + ' ' + subs).includes(search)) return false;
        }
        return true;
    });

    const usedInView    = filtered.filter(l => l.used);
    const cancelledInView = filtered.filter(l => l.cancelled);
    const totalPaidView = usedInView.reduce((s,l) => s + parseFloat(l.paid_amount||0), 0);
    const multiInView   = usedInView.filter(l => l.payment_count > 1).length;

    document.getElementById('histCountBadge').textContent =
        filtered.length + ' leaves' +
        (usedInView.length ? ' · ' + usedInView.length + ' used' : '') +
        (cancelledInView.length ? ' · ' + cancelledInView.length + ' cancelled' : '') +
        (multiInView       ? ' · ' + multiInView + ' multi-payment' : '') +
        ((filtered.length - usedInView.length - cancelledInView.length) ? ' · ' + (filtered.length - usedInView.length - cancelledInView.length) + ' empty' : '');
    document.getElementById('histFooterLabel').textContent = usedInView.length + ' used leaves in view';
    document.getElementById('histFooterTotal').textContent = totalPaidView > 0
        ? totalPaidView.toLocaleString('en',{minimumFractionDigits:2}) : '—';

    if (filtered.length === 0) {
        document.getElementById('histTableBody').innerHTML =
            '<tr><td colspan="12" style="text-align:center;padding:40px;color:#9ca3af;"><i class="fa-solid fa-magnifying-glass" style="font-size:22px;opacity:.3;"></i><p style="margin-top:10px;">No leaves match your filter.</p></td></tr>';
        return;
    }

    let rows = ''; let rowNum = 1;
    filtered.forEach(l => {
        const leavesLeft   = leavesLeftMap[l.leaf_no] ?? 0;
        const llCellHeader = leavesLeftCell(leavesLeft, l.used);
        const llCellEmpty  = leavesLeftCell(leavesLeft, false);

        if (l.cancelled) {
            rows += `<tr class="row-cancelled">
                <td style="font-size:11px;color:#9ca3af;">${rowNum++}</td><td></td>
                <td><span class="leaf-no-pill cancelled">${escH(l.leaf_no)}</span></td>
                <td class="tc"><span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;"><i class="fa-solid fa-ban" style="font-size:9px;"></i> Cancelled</span></td>
                <td style="font-size:10px;color:#9ca3af;">${l.cancelled_at ? l.cancelled_at.slice(0,10) : ''}</td>
                <td colspan="6" style="color:#9ca3af;"><span style="color:#d1d5db;">—</span></td>
                <td class="tr">${llCellEmpty}</td>
                <td style="font-size:11.5px;color:#c2410c;">${escH(l.reason || '—')}
                    <button class="btn btn-xs btn-secondary" style="margin-left:6px;" onclick="reverseCancelledLeaf(${l.cancel_id}, reloadHist)"><i class="fa-solid fa-rotate-left"></i> Reverse</button>
                </td>
            </tr>`;
            return;
        }

        if (!l.used) {
            rows += `<tr class="row-empty">
                <td style="font-size:11px;color:#9ca3af;">${rowNum++}</td><td></td>
                <td><span class="leaf-no-pill empty">${escH(l.leaf_no)}</span></td>
                <td class="tc"><span style="color:#d1d5db;">—</span></td>
                <td><span style="color:#d1d5db;">—</span></td>
                <td class="tr"><span style="color:#d1d5db;">—</span></td>
                <td><span style="color:#d1d5db;">—</span></td>
                <td><span style="color:#d1d5db;">—</span></td>
                <td class="tr"><span style="color:#d1d5db;">—</span></td>
                <td class="tc"><span style="color:#d1d5db;">—</span></td>
                <td class="tr">${llCellEmpty}</td>
                <td><span style="color:#d1d5db;">—</span></td>
            </tr>`;
            return;
        }

        const isMulti    = l.payment_count > 1;
        const isExpanded = _expandedCheques.has(l.leaf_no);
        const paidDate   = fmtDateBadge(l.payments[0].paid_date);
        const totalAmt   = parseFloat(l.paid_amount || 0);

        if (!isMulti) {
            const p = l.payments[0];
            rows += `<tr class="row-used">
                <td style="color:#9ca3af;font-size:11px;">${rowNum++}</td><td></td>
                <td><span class="leaf-no-pill">${escH(l.leaf_no)}</span></td>
                <td class="tc"><span style="color:#22c55e;font-size:12px;font-weight:700;">&#10003; Used</span></td>
                <td>${paidDate}</td>
                <td class="tr"><strong style="color:#1d4ed8;">${fmtAmt(p.paid_amount)}</strong></td>
                <td><strong style="font-size:12.5px;">${escH(p.invoice_no||'')}</strong>
                    ${p.invoice_date?'<br><span style="font-size:10.5px;color:#6b7280;">'+fmtDate(p.invoice_date)+'</span>':''}
                </td>
                <td>${coBadge(p.company)}</td>
                <td class="tr" style="font-size:11.5px;">${p.invoice_value?parseFloat(p.invoice_value).toLocaleString('en',{minimumFractionDigits:2}):'—'}</td>
                <td class="tc">${paidBadge(p.inv_paid)}</td>
                <td class="tr">${llCellHeader}</td>
                <td style="font-size:11.5px;color:#6b7280;">${escH(p.remarks||'—')}</td>
            </tr>`;
        } else {
            const expandIcon = isExpanded
                ? '<i class="fa-solid fa-chevron-down" style="font-size:10px;"></i>'
                : '<i class="fa-solid fa-chevron-right" style="font-size:10px;"></i>';
            rows += `<tr class="row-cheque-header" onclick="toggleGroup('${escH(l.leaf_no)}')">
                <td style="color:#9ca3af;font-size:11px;">${rowNum++}</td>
                <td class="tc"><button class="expand-btn">${expandIcon}</button></td>
                <td><span class="leaf-no-pill">${escH(l.leaf_no)}</span>
                    <span class="multi-badge" style="margin-left:6px;"><i class="fa-solid fa-layer-group" style="font-size:9px;"></i> ${l.payment_count} payments</span>
                </td>
                <td class="tc"><span style="color:#22c55e;font-size:12px;font-weight:700;">&#10003; Used</span></td>
                <td>${paidDate}</td>
                <td class="tr"><strong style="color:#1d4ed8;font-size:13px;">${fmtAmt(totalAmt)}</strong>
                    <span style="display:block;font-size:10px;color:#6b7280;">total of ${l.payment_count}</span></td>
                <td colspan="3" style="color:#64748b;font-size:11.5px;">
                    <i class="fa-solid fa-file-invoice" style="color:#93c5fd;margin-right:4px;"></i>
                    ${l.payments.map(p=>escH(p.invoice_no||'')).join(', ')}
                    <span style="color:#94a3b8;margin-left:6px;">(click to ${isExpanded?'hide':'show'} detail)</span>
                </td>
                <td class="tc">${paidBadge(l.payments[0].inv_paid)}</td>
                <td class="tr">${llCellHeader}</td>
                <td style="font-size:11.5px;color:#6b7280;">${escH(l.payments[0].remarks||'—')}</td>
            </tr>`;

            if (isExpanded) {
                l.payments.forEach((p, pi) => {
                    const isLast = (pi === l.payments.length - 1);
                    rows += `<tr class="row-sub${isLast?' last-sub':''}">
                        <td style="color:#94a3b8;font-size:10px;padding-left:28px;">${pi+1}</td><td></td>
                        <td class="sub-indent" style="padding-left:28px;">
                            <span class="sub-row-num">${pi+1}</span>
                            <span style="font-family:monospace;font-size:11px;color:#475569;">${escH(p.cheque_no||l.leaf_no)}</span>
                        </td>
                        <td class="tc"><span style="color:#22c55e;font-size:11px;">&#10003;</span></td>
                        <td>${fmtDateBadge(p.paid_date)}</td>
                        <td class="tr"><strong style="color:#1d4ed8;">${fmtAmt(p.paid_amount)}</strong></td>
                        <td><strong style="font-size:12px;color:#0f172a;">${escH(p.invoice_no||'')}</strong>
                            ${p.invoice_date?'<br><span style="font-size:10px;color:#6b7280;">'+fmtDate(p.invoice_date)+'</span>':''}
                        </td>
                        <td>${coBadge(p.company)}</td>
                        <td class="tr" style="font-size:11.5px;">${p.invoice_value?parseFloat(p.invoice_value).toLocaleString('en',{minimumFractionDigits:2}):'—'}</td>
                        <td class="tc">${paidBadge(p.inv_paid)}</td>
                        <td class="tr"><span style="color:#b0b7c3;font-size:10px;">↑ same</span></td>
                        <td style="font-size:11px;color:#6b7280;">${escH(p.remarks||'—')}</td>
                    </tr>`;
                });
                rows += `<tr class="row-cheque-total">
                    <td colspan="5" style="text-align:right;font-size:10.5px;color:#64748b;padding-right:10px;">
                        Cheque ${escH(l.leaf_no)} total across ${l.payment_count} invoices →
                    </td>
                    <td class="tr" style="color:#1d4ed8;font-size:13px;">${fmtAmt(totalAmt)}</td>
                    <td colspan="6"></td>
                </tr>`;
            }
        }
    });
    document.getElementById('histTableBody').innerHTML = rows;
}

function toggleGroup(leafNo) {
    if (_expandedCheques.has(leafNo)) _expandedCheques.delete(leafNo);
    else _expandedCheques.add(leafNo);
    renderHistTable();
}
function expandAllGroups() {
    _histAllLeaves.filter(l => l.used && l.payment_count > 1).forEach(l => _expandedCheques.add(l.leaf_no));
    renderHistTable();
}
function collapseAllGroups() { _expandedCheques.clear(); renderHistTable(); }
function closeHistModal() { document.getElementById('histModal').classList.remove('open'); _expandedCheques.clear(); }

// ── SHARED UTILS ──────────────────────────────────────────────────
function fmtDate(d) {
    if (!d || d === '0000-00-00') return '—';
    return new Date(d).toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'});
}
function fmtDateStr(d) {
    if (!d || d === '0000-00-00') return '—';
    return '<span style="background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;display:inline-block;padding:2px 7px;border-radius:5px;font-size:11px;font-weight:600;">'+fmtDate(d)+'</span>';
}
function fmtDateBadge(d) {
    if (!d || d === '0000-00-00') return '—';
    return '<span style="background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;display:inline-block;padding:2px 7px;border-radius:5px;font-size:11px;font-weight:600;">'+fmtDate(d)+'</span>';
}
function fmtAmt(v) { const n = parseFloat(v||0); return n > 0 ? n.toLocaleString('en',{minimumFractionDigits:2}) : '—'; }
function coBadge(co) { if (!co) return '—'; return '<span class="badge badge-'+escH(co.toLowerCase())+'">'+escH(co)+'</span>'; }
function paidBadge(v) { return v==1?'<span class="badge badge-paid">Paid</span>':'<span class="badge badge-unpaid">Pending</span>'; }
function escH(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function showToast(msg, type) {
    const t = document.getElementById('cbToast');
    t.textContent = msg; t.className = type; t.style.display = 'block';
    clearTimeout(t._t); t._t = setTimeout(() => t.style.display = 'none', 3500);
}

document.getElementById('bookModal').addEventListener('click', function(e){ if(e.target===this) closeBookModal(); });
document.getElementById('ccModal').addEventListener('click', function(e){ if(e.target===this) closeCCModal(); });
document.getElementById('histModal').addEventListener('click', function(e){ if(e.target===this) closeHistModal(); });
document.getElementById('ccHistModal').addEventListener('click', function(e){ if(e.target===this) closeCCHistModal(); });
document.addEventListener('keydown', e => {
    if(e.key==='Escape'){ closeBookModal(); closeCCModal(); closeHistModal(); closeCCHistModal(); }
});
</script>

<?php include 'footer.php'; ?>