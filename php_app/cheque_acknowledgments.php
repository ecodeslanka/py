<?php
/**
 * cheque_acknowledgment_new.php  (revised)
 * ─────────────────────────────────────────────────────────────────────────────
 * KEY CHANGES vs previous version:
 *  • Common Bank Account + Cheque Book selector sits beside the date fields.
 *  • "Auto-Assign All" button sequences cheques from that book to every
 *    customer row automatically (one cheque per customer, in order).
 *  • Each customer row shows its assigned cheque_no + a ✕ Remove button +
 *    a Cancel Cheque button.
 *  • Employee tab: ONE shared cheque for ALL employee lines combined —
 *    no per-employee selection needed.
 */

include 'config.php';

// ══════════════════════════════════════════════════════════════════
//  TABLE SETUP
// ══════════════════════════════════════════════════════════════════
function ensure_issue_tables($conn) {
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

    // ── Link column: ties a customer line to the printable acknowledgment ──
    mysqli_query($conn, "ALTER TABLE ca_issue_customer_lines ADD COLUMN IF NOT EXISTS ack_id INT NULL AFTER cheque_no");

    // ── Shared tables used by the original (legacy) cheque-ack screen.
    //    Created here too (IF NOT EXISTS) so this page can write into them
    //    and the existing print_cheque_ack.php can render customer cheques
    //    that were assigned from THIS screen. ──
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

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS ca_cancelled_cheques (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        cheque_book_id      INT NOT NULL,
        cheque_no           VARCHAR(100) NOT NULL,
        reason              TEXT NULL,
        cancelled_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_book (cheque_book_id)
    )");
}

/**
 * Create or refresh the legacy dl_cheque_acknowledgments + ca_customer_claims
 * rows for ONE assigned customer line, so the existing print_cheque_ack.php
 * screen can print it. Returns the ack_id used.
 *
 * claim_item_id on dl_cheque_acknowledgments is set to the
 * ca_issue_customer_lines.id (cl_id) — this keeps a stable 1:1 link between
 * "this row in this issue" and "this printable acknowledgment", independent
 * of however many times the cheque gets reassigned/cancelled.
 */
function sync_customer_claim_to_legacy_ack($conn, $cl_id, $entry, $line, $assign) {
    $cl_id = intval($cl_id);

    $existing_ack_id = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT ack_id FROM ca_issue_customer_lines WHERE id=$cl_id LIMIT 1"));
    $ack_id = intval($existing_ack_id['ack_id'] ?? 0);

    $tax_invoice  = mysqli_real_escape_string($conn, $entry['tax_invoice_no'] ?? ($entry['description'] ?? ''));
    $inv_date     = !empty($entry['email_date']) ? "'" . mysqli_real_escape_string($conn, $entry['email_date']) . "'" : 'NULL';
    $bank_date    = !empty($assign['cheque_issue_date']) ? "'" . mysqli_real_escape_string($conn, $assign['cheque_issue_date']) . "'" : 'NULL';
    $description  = mysqli_real_escape_string($conn, $entry['description'] ?? '');
    $cust_code    = mysqli_real_escape_string($conn, $line['ref_code'] ?? '');
    $cust_name    = mysqli_real_escape_string($conn, $line['ref_name'] ?? '');
    $net          = floatval($line['net_amount']   ?? 0);
    $vat          = floatval($line['vat_amount']   ?? 0);
    $tot          = floatval($line['total_amount'] ?? 0);

    if ($ack_id > 0) {
        mysqli_query($conn, "UPDATE dl_cheque_acknowledgments SET
            tax_invoice_no='$tax_invoice', invoice_date=$inv_date, banking_date=$bank_date,
            claim_description='$description', actual_amount=$net, vat_amount=$vat, total_amount=$tot,
            status='Cheque Issued', claim_type='customer', customer_code='$cust_code', customer_name='$cust_name'
            WHERE id=$ack_id");
    } else {
        mysqli_query($conn, "INSERT INTO dl_cheque_acknowledgments
            (claim_item_id, tax_invoice_no, invoice_date, banking_date, claim_description,
             actual_amount, vat_amount, total_amount, status, claim_type, customer_code, customer_name)
            VALUES ($cl_id, '$tax_invoice', $inv_date, $bank_date, '$description',
                    $net, $vat, $tot, 'Cheque Issued', 'customer', '$cust_code', '$cust_name')");
        $ack_id = mysqli_insert_id($conn);
        mysqli_query($conn, "UPDATE ca_issue_customer_lines SET ack_id=$ack_id WHERE id=$cl_id");
    }

    $cheque_no = mysqli_real_escape_string($conn, $assign['cheque_no'] ?? '');
    $cbid      = intval($assign['cheque_book_id']  ?? 0);
    $baid      = intval($assign['bank_account_id'] ?? 0);
    $bano      = mysqli_real_escape_string($conn, $assign['bank_account_no'] ?? '');
    $bname     = mysqli_real_escape_string($conn, $assign['bank_name']       ?? '');

    $ex = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id FROM ca_customer_claims WHERE ack_id=$ack_id LIMIT 1"));
    if ($ex) {
        mysqli_query($conn, "UPDATE ca_customer_claims SET
            customer_code='$cust_code', customer_name='$cust_name', claim_amount=$tot,
            cheque_no='$cheque_no', cheque_book_id=" . ($cbid ?: 'NULL') . ",
            bank_account_id=" . ($baid ?: 'NULL') . ", bank_account_no='$bano', bank_name='$bname'
            WHERE id=" . intval($ex['id']));
    } else {
        mysqli_query($conn, "INSERT INTO ca_customer_claims
            (ack_id, customer_code, customer_name, claim_amount, cheque_no, cheque_book_id,
             bank_account_id, bank_account_no, bank_name)
            VALUES ($ack_id, '$cust_code', '$cust_name', $tot, '$cheque_no', " . ($cbid ?: 'NULL') . ",
                    " . ($baid ?: 'NULL') . ", '$bano', '$bname')");
    }

    return $ack_id;
}

/**
 * Remove the legacy link for a customer line (e.g. when a cheque assignment
 * is removed before saving, or the line is cancelled outright). Keeps the
 * dl_cheque_acknowledgments row (so history/print stays intact for anything
 * already printed) but blanks the cheque on the claim record.
 */
function clear_customer_claim_cheque($conn, $ack_id) {
    $ack_id = intval($ack_id);
    if (!$ack_id) return;
    mysqli_query($conn, "UPDATE ca_customer_claims SET cheque_no='', cheque_book_id=NULL WHERE ack_id=$ack_id");
}

function get_next_cheque($conn, $book_id, $exclude = []) {
    $book_id = intval($book_id);
    $book = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM customer_claim_cheque_books WHERE id=$book_id LIMIT 1"));
    if (!$book) return null;

    preg_match('/^([A-Za-z\-]*)(\d+)$/', $book['leaf_no_start'], $sm);
    $prefix    = $sm[1] ?? '';
    $start_str = $sm[2] ?? $book['leaf_no_start'];
    $pad       = strlen($start_str);
    $start_num = intval(preg_replace('/\D/', '', $book['leaf_no_start']));
    $end_num   = intval(preg_replace('/\D/', '', $book['leaf_no_end']));

    $used = [];
    $r1 = mysqli_query($conn, "SELECT cheque_no FROM ca_customer_claims WHERE cheque_book_id=$book_id AND cheque_no IS NOT NULL AND cheque_no!=''");
    while ($u = mysqli_fetch_assoc($r1)) $used[] = $u['cheque_no'];
    $r2 = mysqli_query($conn, "SELECT cheque_no FROM ca_issue_customer_lines WHERE cheque_book_id=$book_id AND cheque_no IS NOT NULL AND cheque_no!='' AND cancelled=0");
    while ($u = mysqli_fetch_assoc($r2)) $used[] = $u['cheque_no'];
    $r3 = mysqli_query($conn, "SELECT cheque_no FROM ca_issue_employee_batch WHERE cheque_book_id=$book_id AND cheque_no IS NOT NULL AND cheque_no!='' AND cancelled=0");
    while ($u = mysqli_fetch_assoc($r3)) $used[] = $u['cheque_no'];

    $cancelled = [];
    $rc = mysqli_query($conn, "SELECT cheque_no FROM ca_cancelled_cheques WHERE cheque_book_id=$book_id");
    while ($c = mysqli_fetch_assoc($rc)) $cancelled[] = $c['cheque_no'];

    $blocked = array_unique(array_merge($used, $cancelled, $exclude));
    $total   = $end_num - $start_num + 1;

    for ($n = $start_num; $n <= $end_num; $n++) {
        $leaf_no = $prefix . str_pad($n, $pad, '0', STR_PAD_LEFT);
        if (!in_array($leaf_no, $blocked)) {
            return [
                'cheque_no'        => $leaf_no,
                'total_leaves'     => $total,
                'used_count'       => count(array_unique($used)),
                'cancelled_count'  => count($cancelled),
                'leaves_remaining' => max(0, $total - count(array_unique($used)) - count($cancelled) - 1),
                'book'             => $book,
            ];
        }
    }
    return null;
}

// ══════════════════════════════════════════════════════════════════
//  AJAX
// ══════════════════════════════════════════════════════════════════
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    ensure_issue_tables($conn);
    $action = $_GET['action'];

    // ── Entry lines ───────────────────────────────────────────────
    if ($action === 'get_entry_lines') {
        $eid   = intval($_GET['entry_id'] ?? 0);
        $entry = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT * FROM sscl_vat_email_entries WHERE id=$eid"));
        if (!$entry) { echo json_encode(['success'=>false,'message'=>'Entry not found']); exit; }

        $lines = [];
        $lr = mysqli_query($conn,
            "SELECT * FROM sscl_vat_email_lines WHERE entry_id=$eid ORDER BY sort_order ASC, id ASC");
        while ($l = mysqli_fetch_assoc($lr)) $lines[] = $l;

        $issues = [];
        $ir = mysqli_query($conn,
            "SELECT h.*,
                    (SELECT COUNT(*) FROM ca_issue_customer_lines cl WHERE cl.issue_id=h.id AND cl.cancelled=0) AS cust_assigned,
                    (SELECT COUNT(*) FROM ca_issue_employee_batch eb WHERE eb.issue_id=h.id AND eb.cancelled=0) AS emp_assigned
             FROM ca_issue_headers h WHERE h.entry_id=$eid ORDER BY h.created_at DESC");
        while ($i = mysqli_fetch_assoc($ir)) $issues[] = $i;

        // Load existing cheque assignments if issue exists
        $existing_cust = [];
        $existing_emp  = null;
        $print_ack_ids = [];
        if (!empty($issues)) {
            $iid = intval($issues[0]['id']);
            $cr  = mysqli_query($conn, "SELECT * FROM ca_issue_customer_lines WHERE issue_id=$iid ORDER BY id");
            while ($c = mysqli_fetch_assoc($cr)) {
                $existing_cust[$c['line_id']] = $c;
                if (!intval($c['cancelled']) && !empty($c['cheque_no']) && !empty($c['ack_id'])) {
                    $print_ack_ids[] = intval($c['ack_id']);
                }
            }
            $existing_emp = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT * FROM ca_issue_employee_batch WHERE issue_id=$iid LIMIT 1"));
        }

        echo json_encode([
            'success'       => true,
            'entry'         => $entry,
            'lines'         => $lines,
            'issues'        => $issues,
            'existing_cust' => $existing_cust,
            'existing_emp'  => $existing_emp,
            'print_ack_ids' => $print_ack_ids,
        ]);
        exit;
    }

    // ── Bank accounts ─────────────────────────────────────────────
    if ($action === 'get_bank_accounts') {
        $res  = mysqli_query($conn,
            "SELECT cba.id, cba.account_no, cba.account_name, cba.account_type, b.bank_name
             FROM company_bank_accounts cba
             LEFT JOIN banks b ON cba.bank_code = b.bank_code
             WHERE cba.active = 1 ORDER BY b.bank_name ASC");
        $rows = [];
        while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
        echo json_encode(['success'=>true,'data'=>$rows]);
        exit;
    }

    // ── Cheque books for bank ─────────────────────────────────────
    if ($action === 'get_books') {
        $bank_id = intval($_GET['bank_account_id'] ?? 0);
        $where   = $bank_id
            ? "WHERE ccb.bank_account_id=$bank_id AND ccb.status='Active'"
            : "WHERE ccb.status='Active'";
        $res = mysqli_query($conn,
            "SELECT ccb.*, COALESCE(NULLIF(b.bank_name,''),cba.bank_code,'') AS bank_name_label, cba.account_no
             FROM customer_claim_cheque_books ccb
             LEFT JOIN company_bank_accounts cba ON cba.id=ccb.bank_account_id
             LEFT JOIN banks b ON b.bank_code=cba.bank_code
             $where ORDER BY ccb.id ASC");
        $books = [];
        while ($bk = mysqli_fetch_assoc($res)) {
            $next = get_next_cheque($conn, $bk['id']);
            $bk['next_cheque_no']   = $next ? $next['cheque_no']       : null;
            $bk['leaves_remaining'] = $next ? $next['leaves_remaining'] : 0;
            $bk['used_count']       = $next ? $next['used_count']       : intval($bk['leaf_count']);
            $bk['cancelled_count']  = $next ? $next['cancelled_count']  : 0;
            $bk['total_leaves']     = intval($bk['leaf_count']);
            $books[] = $bk;
        }
        echo json_encode(['success'=>true,'data'=>$books]);
        exit;
    }

    // ── Get N sequential cheques from book (bulk auto-assign) ─────
    if ($action === 'get_bulk_cheques') {
        $book_id = intval($_GET['book_id'] ?? 0);
        $count   = intval($_GET['count']   ?? 1);
        if (!$book_id || $count < 1) {
            echo json_encode(['success'=>false,'message'=>'Invalid parameters']); exit;
        }
        $book = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT * FROM customer_claim_cheque_books WHERE id=$book_id LIMIT 1"));
        if (!$book) { echo json_encode(['success'=>false,'message'=>'Book not found']); exit; }

        preg_match('/^([A-Za-z\-]*)(\d+)$/', $book['leaf_no_start'], $sm);
        $prefix    = $sm[1] ?? '';
        $start_str = $sm[2] ?? $book['leaf_no_start'];
        $pad       = strlen($start_str);
        $start_num = intval(preg_replace('/\D/', '', $book['leaf_no_start']));
        $end_num   = intval(preg_replace('/\D/', '', $book['leaf_no_end']));

        $blocked = [];
        $q1 = mysqli_query($conn, "SELECT cheque_no FROM ca_customer_claims WHERE cheque_book_id=$book_id AND cheque_no IS NOT NULL AND cheque_no!=''");
        while ($r = mysqli_fetch_assoc($q1)) $blocked[] = $r['cheque_no'];
        $q2 = mysqli_query($conn, "SELECT cheque_no FROM ca_issue_customer_lines WHERE cheque_book_id=$book_id AND cheque_no IS NOT NULL AND cheque_no!='' AND cancelled=0");
        while ($r = mysqli_fetch_assoc($q2)) $blocked[] = $r['cheque_no'];
        $q3 = mysqli_query($conn, "SELECT cheque_no FROM ca_issue_employee_batch WHERE cheque_book_id=$book_id AND cheque_no IS NOT NULL AND cheque_no!='' AND cancelled=0");
        while ($r = mysqli_fetch_assoc($q3)) $blocked[] = $r['cheque_no'];
        $q4 = mysqli_query($conn, "SELECT cheque_no FROM ca_cancelled_cheques WHERE cheque_book_id=$book_id");
        while ($r = mysqli_fetch_assoc($q4)) $blocked[] = $r['cheque_no'];
        $blocked = array_unique($blocked);

        $available = [];
        for ($n = $start_num; $n <= $end_num && count($available) < $count; $n++) {
            $leaf_no = $prefix . str_pad($n, $pad, '0', STR_PAD_LEFT);
            if (!in_array($leaf_no, $blocked)) $available[] = $leaf_no;
        }

        $total      = $end_num - $start_num + 1;
        $used_count = count(array_unique(array_filter(array_merge(
            array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT cheque_no FROM ca_customer_claims WHERE cheque_book_id=$book_id AND cheque_no!=''")?:false)?:[], 0),
            array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT cheque_no FROM ca_issue_customer_lines WHERE cheque_book_id=$book_id AND cheque_no!='' AND cancelled=0")?:false)?:[], 0),
            array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT cheque_no FROM ca_issue_employee_batch WHERE cheque_book_id=$book_id AND cheque_no!='' AND cancelled=0")?:false)?:[], 0)
        ))));
        $canc_count = intval(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM ca_cancelled_cheques WHERE cheque_book_id=$book_id"))['c']);

        echo json_encode([
            'success'          => true,
            'cheques'          => $available,
            'found'            => count($available),
            'requested'        => $count,
            'total_leaves'     => $total,
            'used_count'       => $used_count,
            'cancelled_count'  => $canc_count,
            'leaves_remaining' => max(0, $total - $used_count - $canc_count),
            'book'             => $book,
        ]);
        exit;
    }

    // ── Save issue ────────────────────────────────────────────────
    if ($action === 'save_issue' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $entry_id  = intval($_POST['entry_id']   ?? 0);
        $issue_id  = intval($_POST['issue_id']   ?? 0);
        $ack_date  = mysqli_real_escape_string($conn, trim($_POST['ack_date']          ?? ''));
        $cheq_date = mysqli_real_escape_string($conn, trim($_POST['cheque_issue_date'] ?? ''));
        $com_date  = mysqli_real_escape_string($conn, trim($_POST['common_date']       ?? ''));
        $remarks   = mysqli_real_escape_string($conn, trim($_POST['remarks']           ?? ''));
        $customers = json_decode($_POST['customers'] ?? '[]', true);
        $employees = json_decode($_POST['employees'] ?? '{}', true);
        $by        = mysqli_real_escape_string($conn, $_SESSION['username'] ?? 'system');

        if (!$entry_id) { echo json_encode(['success'=>false,'message'=>'Invalid entry.']); exit; }

        $entry = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM sscl_vat_email_entries WHERE id=$entry_id"));

        $ad  = $ack_date  ? "'$ack_date'"  : 'NULL';
        $cd  = $cheq_date ? "'$cheq_date'" : 'NULL';
        $cmd = $com_date  ? "'$com_date'"  : 'NULL';

        if ($issue_id > 0) {
            mysqli_query($conn, "UPDATE ca_issue_headers SET
                ack_date=$ad, cheque_issue_date=$cd, common_date=$cmd, remarks='$remarks'
                WHERE id=$issue_id AND entry_id=$entry_id");
        } else {
            mysqli_query($conn, "INSERT INTO ca_issue_headers
                (entry_id,ack_date,cheque_issue_date,common_date,remarks,created_by)
                VALUES ($entry_id,$ad,$cd,$cmd,'$remarks','$by')");
            $issue_id = mysqli_insert_id($conn);
        }

        $print_ack_ids = [];

        // Save customer lines
        if (!empty($customers) && is_array($customers)) {
            foreach ($customers as $cl) {
                $lid   = intval($cl['line_id']        ?? 0);
                $baid  = intval($cl['bank_account_id'] ?? 0);
                $bano  = mysqli_real_escape_string($conn, $cl['bank_account_no'] ?? '');
                $bname = mysqli_real_escape_string($conn, $cl['bank_name']       ?? '');
                $cbid  = intval($cl['cheque_book_id']  ?? 0);
                $cno   = mysqli_real_escape_string($conn, $cl['cheque_no']       ?? '');
                $rid   = intval($cl['ref_id']           ?? 0);
                $rcode = mysqli_real_escape_string($conn, $cl['ref_code']        ?? '');
                $rname = mysqli_real_escape_string($conn, $cl['ref_name']        ?? '');
                $net   = floatval($cl['net_amount']    ?? 0);
                $vat   = floatval($cl['vat_amount']    ?? 0);
                $tot   = floatval($cl['total_amount']  ?? 0);
                // ── cancellation state must be persisted too, otherwise a
                //    removed/cancelled cheque silently "sticks" in the DB ──
                $cancelled      = intval($cl['cancelled'] ?? 0);
                $cancel_reason  = mysqli_real_escape_string($conn, $cl['cancel_reason'] ?? '');

                $ex = mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT id FROM ca_issue_customer_lines WHERE issue_id=$issue_id AND line_id=$lid LIMIT 1"));
                if ($ex) {
                    $cl_id = intval($ex['id']);
                    mysqli_query($conn, "UPDATE ca_issue_customer_lines SET
                        bank_account_id=$baid, bank_account_no='$bano', bank_name='$bname',
                        cheque_book_id=" . ($cbid ?: 'NULL') . ", cheque_no='$cno',
                        cancelled=$cancelled, cancel_reason='$cancel_reason'
                        WHERE id=$cl_id");
                } else {
                    mysqli_query($conn, "INSERT INTO ca_issue_customer_lines
                        (issue_id,line_id,ref_id,ref_code,ref_name,net_amount,vat_amount,total_amount,
                         bank_account_id,bank_account_no,bank_name,cheque_book_id,cheque_no,cancelled,cancel_reason)
                        VALUES ($issue_id,$lid,$rid,'$rcode','$rname',$net,$vat,$tot,
                                $baid,'$bano','$bname'," . ($cbid ?: 'NULL') . ",'$cno',$cancelled,'$cancel_reason')");
                    $cl_id = mysqli_insert_id($conn);
                }

                // ── Cheque book usage: keep the legacy ack + customer-claim
                //    record in sync so the cheque book's "used" count and the
                //    printable acknowledgment both reflect this assignment.
                //    A cancelled line must NOT count as an active printable
                //    cheque even if a (now-void) cheque_no is still stored
                //    on it for audit purposes. ──
                if ($cno !== '' && !$cancelled) {
                    $ack_id = sync_customer_claim_to_legacy_ack(
                        $conn, $cl_id, $entry,
                        ['ref_code'=>$rcode,'ref_name'=>$rname,'net_amount'=>$net,'vat_amount'=>$vat,'total_amount'=>$tot],
                        ['cheque_no'=>$cno,'cheque_book_id'=>$cbid,'bank_account_id'=>$baid,
                         'bank_account_no'=>$bano,'bank_name'=>$bname,'cheque_issue_date'=>$_POST['cheque_issue_date'] ?? '']
                    );
                    $print_ack_ids[] = $ack_id;
                } else {
                    // No cheque on this line (removed before save), or the
                    // line was cancelled — blank out any previously linked
                    // claim's cheque so it no longer counts as "used" or
                    // shows up as a printable acknowledgment.
                    $existing_ack = mysqli_fetch_assoc(mysqli_query($conn,
                        "SELECT ack_id FROM ca_issue_customer_lines WHERE id=$cl_id LIMIT 1"));
                    if (!empty($existing_ack['ack_id'])) {
                        clear_customer_claim_cheque($conn, intval($existing_ack['ack_id']));
                    }
                }
            }
        }

        // Save employee batch (one cheque for all employees)
        // NOTE: this must run whenever the employee payload was sent at all
        // (i.e. the entry has employee lines), NOT only when a cheque_no is
        // present — otherwise removing/cancelling the batch cheque never
        // reaches the DB and the old cheque_no "sticks" on reload.
        $employee_issue_id = null;
        if (!empty($employees) && array_key_exists('cheque_no', $employees)) {
            $ebaid  = intval($employees['bank_account_id'] ?? 0);
            $ebano  = mysqli_real_escape_string($conn, $employees['bank_account_no'] ?? '');
            $ebname = mysqli_real_escape_string($conn, $employees['bank_name']       ?? '');
            $ecbid  = intval($employees['cheque_book_id']  ?? 0);
            $ecno   = mysqli_real_escape_string($conn, $employees['cheque_no']       ?? '');
            $etot   = floatval($employees['total_amount']  ?? 0);
            $eids   = mysqli_real_escape_string($conn, json_encode($employees['line_ids'] ?? []));
            $ecancelled     = intval($employees['cancelled'] ?? 0);
            $ecancelreason  = mysqli_real_escape_string($conn, $employees['cancel_reason'] ?? '');

            $ex = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT id FROM ca_issue_employee_batch WHERE issue_id=$issue_id LIMIT 1"));
            if ($ex) {
                mysqli_query($conn, "UPDATE ca_issue_employee_batch SET
                    total_amount=$etot, bank_account_id=$ebaid, bank_account_no='$ebano', bank_name='$ebname',
                    cheque_book_id=" . ($ecbid ?: 'NULL') . ", cheque_no='$ecno', employee_ids='$eids',
                    cancelled=$ecancelled, cancel_reason='$ecancelreason'
                    WHERE id=" . intval($ex['id']));
            } else {
                mysqli_query($conn, "INSERT INTO ca_issue_employee_batch
                    (issue_id,total_amount,bank_account_id,bank_account_no,bank_name,cheque_book_id,cheque_no,employee_ids,cancelled,cancel_reason)
                    VALUES ($issue_id,$etot,$ebaid,'$ebano','$ebname'," . ($ecbid ?: 'NULL') . ",'$ecno','$eids',$ecancelled,'$ecancelreason')");
            }
            // One shared cheque acknowledgment for the whole employee batch —
            // printable via print_cheque_ack_employee.php?issue_id=... —
            // only while an active (non-cancelled) cheque is actually assigned.
            $employee_issue_id = (!$ecancelled && $ecno !== '') ? $issue_id : null;
        }

        echo json_encode(['success'=>true,'issue_id'=>$issue_id,'print_ack_ids'=>array_values(array_unique($print_ack_ids)),'employee_issue_id'=>$employee_issue_id]);
        exit;
    }

    // ── Cancel a cheque (book-level, adds to ca_cancelled_cheques) ─
    if ($action === 'cancel_cheque_leaf' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $book_id   = intval($_POST['book_id']   ?? 0);
        $cheque_no = mysqli_real_escape_string($conn, trim($_POST['cheque_no'] ?? ''));
        $reason    = mysqli_real_escape_string($conn, trim($_POST['reason']    ?? ''));
        if (!$book_id || !$cheque_no) {
            echo json_encode(['success'=>false,'message'=>'Missing parameters']); exit;
        }
        $ex = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id FROM ca_cancelled_cheques WHERE cheque_book_id=$book_id AND cheque_no='$cheque_no' LIMIT 1"));
        if (!$ex) mysqli_query($conn,
            "INSERT INTO ca_cancelled_cheques (cheque_book_id,cheque_no,reason) VALUES ($book_id,'$cheque_no','$reason')");
        $next = get_next_cheque($conn, $book_id);
        echo json_encode(['success'=>true,'next'=>$next]);
        exit;
    }

    // ── Get issue detail (print / view) ───────────────────────────
    if ($action === 'get_issue') {
        $issue_id = intval($_GET['issue_id'] ?? 0);
        $h = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT h.*, e.email_date, e.description AS entry_desc, e.total_amount AS entry_total
             FROM ca_issue_headers h
             JOIN sscl_vat_email_entries e ON e.id=h.entry_id
             WHERE h.id=$issue_id"));
        if (!$h) { echo json_encode(['success'=>false]); exit; }

        $custs = [];
        $cr = mysqli_query($conn, "SELECT * FROM ca_issue_customer_lines WHERE issue_id=$issue_id ORDER BY id");
        while ($c = mysqli_fetch_assoc($cr)) $custs[] = $c;

        $emp = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT * FROM ca_issue_employee_batch WHERE issue_id=$issue_id LIMIT 1"));

        $emp_lines = [];
        if ($emp) {
            $eids_arr = json_decode($emp['employee_ids'] ?? '[]', true);
            if (!empty($eids_arr)) {
                $eids_safe = implode(',', array_map('intval', $eids_arr));
                $elr = mysqli_query($conn, "SELECT * FROM sscl_vat_email_lines WHERE id IN ($eids_safe) ORDER BY sort_order");
                while ($el = mysqli_fetch_assoc($elr)) $emp_lines[] = $el;
            }
        }

        $print_ack_ids = [];
        foreach ($custs as $c) {
            if (!intval($c['cancelled']) && !empty($c['cheque_no']) && !empty($c['ack_id'])) {
                $print_ack_ids[] = intval($c['ack_id']);
            }
        }

        echo json_encode(['success'=>true,'header'=>$h,'customers'=>$custs,'employee_batch'=>$emp,'employee_lines'=>$emp_lines,'print_ack_ids'=>$print_ack_ids]);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']);
    exit;
}

// ══════════════════════════════════════════════════════════════════
//  PAGE LOAD
// ══════════════════════════════════════════════════════════════════
ensure_issue_tables($conn);

$filter_from = mysqli_real_escape_string($conn, $_GET['filter_from'] ?? '');
$filter_to   = mysqli_real_escape_string($conn, $_GET['filter_to']   ?? '');

$where = ['1=1'];
if ($filter_from) $where[] = "e.email_date >= '$filter_from'";
if ($filter_to)   $where[] = "e.email_date <= '$filter_to'";
$where_sql = 'WHERE ' . implode(' AND ', $where);

$entries = [];
$res = mysqli_query($conn,
    "SELECT e.*,
            (SELECT COUNT(*) FROM sscl_vat_email_lines l WHERE l.entry_id=e.id) AS line_count,
            (SELECT COUNT(*) FROM sscl_vat_email_lines l WHERE l.entry_id=e.id AND l.line_type='customer') AS cust_count,
            (SELECT COUNT(*) FROM sscl_vat_email_lines l WHERE l.entry_id=e.id AND l.line_type='employee') AS emp_count,
            (SELECT COUNT(*) FROM ca_issue_headers h WHERE h.entry_id=e.id) AS issue_count
     FROM sscl_vat_email_entries e $where_sql
     ORDER BY e.email_date DESC, e.id DESC");
if ($res) while ($r = mysqli_fetch_assoc($res)) $entries[] = $r;

$sum = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as tot FROM sscl_vat_email_entries e $where_sql"));

include 'header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
*,*::before,*::after{box-sizing:border-box}
body{font-family:'Inter',system-ui,sans-serif}

/* ── Page header ── */
.ph{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;margin-bottom:22px}
.ph-title{font-size:23px;font-weight:800;color:#0f172a;margin:0;display:flex;align-items:center;gap:9px}
.ph-title i{color:#1d4ed8}
.ph-sub{font-size:13px;color:#64748b;margin:3px 0 0}

/* ── Summary cards ── */
.sum-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:20px}
.sum-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px 18px}
.sum-lbl{font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.6px;margin-bottom:5px}
.sum-val{font-size:21px;font-weight:800;color:#0f172a}

/* ── Filter ── */
.fbar{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:13px 16px;margin-bottom:16px}
.fg{display:flex;flex-direction:column;gap:3px}
.fl{font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px}
.fi{padding:7px 11px;border:1px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:inherit;outline:none;height:34px}
.fi:focus{border-color:#1d4ed8}

/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;white-space:nowrap;transition:all .17s;text-decoration:none}
.btn-sm{padding:6px 13px;font-size:12px}
.btn-xs{padding:4px 10px;font-size:11px;border-radius:5px}
.btn-primary{background:#1d4ed8;color:#fff}.btn-primary:hover{background:#1e40af}
.btn-light{background:#f8fafc;color:#374151;border:1px solid #e2e8f0}.btn-light:hover{background:#f1f5f9}
.btn-danger{background:#fef2f2;color:#dc2626;border:1px solid #fca5a5}.btn-danger:hover{background:#dc2626;color:#fff}
.btn-green{background:#f0fdf4;color:#16a34a;border:1px solid #86efac}.btn-green:hover{background:#16a34a;color:#fff}
.btn-amber{background:#fffbeb;color:#d97706;border:1px solid #fde68a}.btn-amber:hover{background:#d97706;color:#fff}
.btn-print{background:#0f172a;color:#fff}.btn-print:hover{background:#1e293b}

/* ── Main table ── */
.tcard{background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;margin-bottom:20px}
.tcard-hdr{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #f1f5f9;flex-wrap:wrap;gap:8px}
.tcard-title{font-size:14px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:7px}
.cnt-badge{background:#f1f5f9;color:#475569;font-size:11px;font-weight:700;padding:2px 8px;border-radius:9px}
.swrap{position:relative}
.swrap i{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:12px;pointer-events:none}
.sinp{width:210px;padding:6px 11px 6px 28px;border:1px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:inherit;outline:none}
.sinp:focus{border-color:#1d4ed8}

.dt{width:100%;border-collapse:collapse;font-size:13px}
.dt thead th{background:#f8fafc;padding:10px 13px;text-align:left;font-weight:700;font-size:11px;color:#475569;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;border-bottom:2px solid #e2e8f0}
.dt th.r,.dt td.r{text-align:right}
.dt tbody tr{border-bottom:1px solid #f8fafc;transition:background .1s}
.dt tbody tr:hover{background:#f8fafc}
.dt td{padding:10px 13px;color:#1e293b;vertical-align:middle;white-space:nowrap}
.dt tfoot td{padding:10px 13px;font-weight:800;background:#eff6ff;border-top:2px solid #bfdbfe}
.empty{text-align:center;padding:55px 20px;color:#94a3b8}
.empty i{font-size:38px;display:block;margin-bottom:12px;color:#cbd5e1}

/* ── Tags ── */
.tag{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700}
.tag-blue{background:#dbeafe;color:#1e40af}
.tag-green{background:#dcfce7;color:#166534}
.tag-amber{background:#fef3c7;color:#92400e}
.tag-red{background:#fef2f2;color:#991b1b}
.tag-violet{background:#f5f3ff;color:#7c3aed}
.pill-cust{display:inline-flex;align-items:center;gap:3px;background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;border-radius:5px;padding:2px 8px;font-size:10px;font-weight:700}
.pill-emp{display:inline-flex;align-items:center;gap:3px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:5px;padding:2px 8px;font-size:10px;font-weight:700}
.pill-mix{display:inline-flex;align-items:center;gap:3px;background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe;border-radius:5px;padding:2px 8px;font-size:10px;font-weight:700}
.iss-badge-yes{display:inline-flex;align-items:center;gap:4px;background:#dcfce7;color:#166534;border-radius:6px;padding:2px 9px;font-size:10px;font-weight:700}
.iss-badge-no{display:inline-flex;align-items:center;gap:4px;background:#f1f5f9;color:#94a3b8;border-radius:6px;padding:2px 9px;font-size:10px;font-weight:700}
.amt{font-weight:800;color:#312e81}

/* ── Row action buttons ── */
.abtns{display:flex;gap:4px;align-items:center}
.abtn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:6px;border:1px solid #e2e8f0;background:#fff;color:#6b7280;cursor:pointer;font-size:12px;transition:all .16s}
.abtn:hover{transform:translateY(-1px);box-shadow:0 2px 5px rgba(0,0,0,.08)}
.abtn-issue:hover{background:#1d4ed8;color:#fff;border-color:#1d4ed8}
.abtn-print:hover{background:#0f172a;color:#fff;border-color:#0f172a}

/* ══════════════════════════════════════════════════════════════════
   MODALS
══════════════════════════════════════════════════════════════════ */
.mo{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:8000;display:none;align-items:flex-start;justify-content:center;padding:16px;overflow-y:auto}
.mo.open{display:flex;animation:mofade .2s ease}
@keyframes mofade{from{opacity:0}to{opacity:1}}
.mo-box{background:#fff;border-radius:14px;width:100%;max-width:1000px;display:flex;flex-direction:column;box-shadow:0 24px 80px rgba(0,0,0,.22);overflow:hidden;animation:moslide .22s ease;margin:auto}
.mo-box-sm{max-width:500px}
@keyframes moslide{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.mo-hdr{background:linear-gradient(135deg,#1e3a5f,#0f172a);padding:18px 24px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.mo-hdr-title{color:#fff;font-size:15px;font-weight:800;display:flex;align-items:center;gap:9px}
.mo-hdr-sub{color:rgba(255,255,255,.55);font-size:11px;margin-top:3px}
.mo-close{background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.27);color:#fff;border-radius:7px;padding:6px 14px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .18s}
.mo-close:hover{background:rgba(255,255,255,.25)}
.mo-body{padding:22px 26px;overflow-y:auto;flex:1}

/* ── Common setup strip (dates + bank + book) ── */
.setup-strip{background:#f0f9ff;border:1px solid #bae6fd;border-radius:12px;padding:16px 18px;margin-bottom:18px}
.setup-strip-title{font-size:11px;font-weight:800;color:#1e40af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;display:flex;align-items:center;gap:6px}
.setup-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px}
.setup-row-bank{display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:end;margin-top:12px}
.fgrp{display:flex;flex-direction:column;gap:5px}
.flbl{font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px}
.finp{padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;font-family:inherit;outline:none}
.finp:focus{border-color:#1d4ed8;box-shadow:0 0 0 3px rgba(29,78,216,.08)}

/* ── Book selector pills ── */
.book-pills{display:flex;flex-wrap:wrap;gap:8px;margin-top:6px}
.book-pill{background:#fff;border:2px solid #e2e8f0;border-radius:8px;padding:8px 14px;cursor:pointer;transition:all .15s;font-size:12px}
.book-pill:hover{border-color:#1d4ed8;background:#eff6ff}
.book-pill.selected{border-color:#1d4ed8;background:#eff6ff;color:#1d4ed8;font-weight:700}
.book-pill .bp-remain{font-size:10px;color:#16a34a;font-weight:700;margin-left:5px}
.book-pill .bp-name{font-weight:700;color:#1e293b}
.book-pill.selected .bp-name{color:#1d4ed8}

/* ── Leaf bar ── */
.leaf-bar-wrap{margin-top:8px}
.leaf-bar-label{display:flex;justify-content:space-between;font-size:10px;color:#64748b;margin-bottom:3px}
.leaf-bar-track{height:5px;background:#e2e8f0;border-radius:3px;overflow:hidden}
.leaf-bar-fill{height:100%;background:linear-gradient(90deg,#22c55e,#16a34a);border-radius:3px;transition:width .3s}

/* ── Auto-assign bar ── */
.aa-bar{display:flex;align-items:center;gap:10px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:9px;padding:10px 16px;margin-bottom:14px;flex-wrap:wrap}
.aa-bar-info{font-size:12px;color:#1e40af;flex:1}
.aa-bar-info strong{color:#1d4ed8}

/* ── Tabs ── */
.tabs{display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:16px}
.tab-btn{padding:10px 20px;font-size:13px;font-weight:700;color:#64748b;background:none;border:none;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;font-family:inherit;display:flex;align-items:center;gap:6px;transition:color .15s}
.tab-btn.active{color:#1d4ed8;border-bottom-color:#1d4ed8}
.tab-btn:hover{color:#1d4ed8}
.tab-pane{display:none}
.tab-pane.active{display:block}

/* ── Customer lines table ── */
.lt{width:100%;border-collapse:collapse;font-size:13px}
.lt thead th{background:#f0f9ff;padding:9px 12px;text-align:left;font-size:11px;font-weight:700;color:#1e40af;text-transform:uppercase;letter-spacing:.4px;border-bottom:2px solid #bae6fd;white-space:nowrap}
.lt th.r,.lt td.r{text-align:right}
.lt tbody tr{border-bottom:1px solid #f1f5f9;transition:background .1s}
.lt td{padding:9px 12px;vertical-align:middle}
.lt tbody tr.assigned{background:#f0fdf4}
.lt tbody tr.cancelled{background:#fef2f2}

/* ── Cheque cell inside table ── */
.ch-cell{display:flex;align-items:center;gap:6px;white-space:nowrap}
.ch-no{font-family:monospace;font-size:12px;font-weight:800;color:#1d4ed8;background:#dbeafe;border:1px solid #bfdbfe;border-radius:5px;padding:2px 8px}
.ch-no.cancelled{color:#dc2626;background:#fef2f2;border-color:#fca5a5;text-decoration:line-through}
.ch-none{font-size:11px;color:#94a3b8;font-style:italic}

/* ── Employee batch panel ── */
.emp-panel{background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:18px 20px}
.emp-panel-hdr{font-size:14px;font-weight:800;color:#92400e;margin-bottom:6px;display:flex;align-items:center;gap:8px}
.emp-panel-sub{font-size:12px;color:#b45309;margin-bottom:14px}
.emp-list-tbl{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:14px}
.emp-list-tbl th{background:#fef3c7;padding:8px 11px;text-align:left;font-size:11px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #fde68a}
.emp-list-tbl td{padding:8px 11px;border-bottom:1px solid #fef9c3;vertical-align:middle}
.emp-list-tbl td.r{text-align:right;font-weight:700}
.emp-total-row td{background:#fef3c7;font-weight:800;border-top:2px solid #fde68a}
.emp-cheque-box{background:#fff;border:1px solid #fde68a;border-radius:9px;padding:14px 16px}
.emp-cheque-box-title{font-size:11px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:.4px;margin-bottom:10px}
.emp-cheque-assigned{display:flex;align-items:center;gap:10px;flex-wrap:wrap}

/* ── Cancel inline box ── */
.cancel-inline{background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:12px;margin-top:8px;display:none}
.cancel-inline textarea{width:100%;border:1px solid #fca5a5;border-radius:6px;padding:7px 10px;font-size:12px;font-family:inherit;outline:none;resize:vertical;min-height:52px}

/* ── Toast ── */
#toast{position:fixed;bottom:22px;right:22px;padding:12px 20px;border-radius:9px;font-size:13px;font-weight:600;color:#fff;z-index:99999;display:none;box-shadow:0 4px 18px rgba(0,0,0,.18)}
#toast.success{background:#16a34a}#toast.error{background:#dc2626}#toast.warn{background:#d97706}#toast.info{background:#1d4ed8}

/* ── Print ── */
.print-modal-box{max-width:700px}
#printArea{padding:10px 0}
.print-company{text-align:center;margin-bottom:16px}
.print-company h2{font-size:18px;font-weight:800;margin:0}
.print-company p{font-size:12px;color:#64748b;margin:3px 0 0}
.print-title{text-align:center;font-size:15px;font-weight:800;text-decoration:underline;margin-bottom:14px}
.print-meta{display:grid;grid-template-columns:1fr 1fr;gap:8px 24px;font-size:12px;margin-bottom:14px;border:1px solid #e2e8f0;padding:12px 14px;border-radius:8px;background:#f8fafc}
.pmr{display:flex;gap:6px}.pmr-lbl{color:#64748b;font-weight:600;white-space:nowrap}.pmr-val{font-weight:700;color:#0f172a}
.print-section{margin-bottom:14px}
.print-section-title{font-size:11px;font-weight:800;color:#1e40af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;padding-bottom:4px;border-bottom:2px solid #bfdbfe}
.print-tbl{width:100%;border-collapse:collapse;font-size:12px}
.print-tbl th{background:#f0f9ff;padding:7px 10px;text-align:left;font-weight:700;border:1px solid #bae6fd}
.print-tbl td{padding:7px 10px;border:1px solid #e2e8f0}
.print-tbl td.r{text-align:right;font-weight:700}
.print-tbl tfoot td{background:#f0fdf4;font-weight:800;border:1px solid #86efac}
.print-sig{display:grid;grid-template-columns:1fr 1fr 1fr;gap:30px;margin-top:28px}
.print-sig-line{border-top:1px solid #0f172a;padding-top:5px;text-align:center;font-size:10px;color:#64748b}
@media print{
    body *{visibility:hidden}
    #printArea,#printArea *{visibility:visible}
    #printArea{position:fixed;left:0;top:0;width:100%;background:#fff;padding:20px}
}
</style>

<!-- ══ PAGE ══════════════════════════════════════════════════════ -->
<div class="ph">
    <div>
        <h2 class="ph-title"><i class="fa-solid fa-money-check-dollar"></i> Cheque Acknowledgments</h2>
        <p class="ph-sub">Issue cheques against VAT email entry lines — customers get individual sequential cheques, employees share one batch cheque</p>
    </div>
    <a href="ca_issued_cheque_bank_recon.php" class="btn btn-light btn-sm" style="align-self:center;margin-left:auto;">
        <i class="fa-solid fa-scale-balanced"></i> Issued Cheque ↔ Bank Recon
    </a>
</div>

<div class="sum-grid">
    <div class="sum-card">
        <div class="sum-lbl">Total Entries</div>
        <div class="sum-val"><?php echo number_format($sum['cnt'] ?? 0); ?></div>
    </div>
    <div class="sum-card">
        <div class="sum-lbl">Total Amount</div>
        <div class="sum-val" style="color:#1d4ed8"><?php echo number_format($sum['tot'] ?? 0, 2); ?></div>
    </div>
    <div class="sum-card">
        <div class="sum-lbl">Total Issues</div>
        <div class="sum-val" style="color:#16a34a"><?php
            $ti = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM ca_issue_headers"));
            echo number_format($ti['c'] ?? 0);
        ?></div>
    </div>
</div>

<form method="GET" class="fbar">
    <div class="fg">
        <label class="fl">Email Date From</label>
        <input type="date" name="filter_from" class="fi" value="<?php echo htmlspecialchars($filter_from); ?>" style="width:140px">
    </div>
    <div class="fg">
        <label class="fl">To</label>
        <input type="date" name="filter_to" class="fi" value="<?php echo htmlspecialchars($filter_to); ?>" style="width:140px">
    </div>
    <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Filter</button>
    <a href="cheque_acknowledgment_new.php" class="btn btn-light btn-sm"><i class="fa-solid fa-xmark"></i> Clear</a>
</form>

<div class="tcard">
    <div class="tcard-hdr">
        <div class="tcard-title">
            <i class="fa-solid fa-table-list"></i> VAT Email Entries
            <span class="cnt-badge" id="rowCountBadge"><?php echo count($entries); ?></span>
        </div>
        <div class="swrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" class="sinp" id="tblSearch" placeholder="Search…" oninput="doSearch()">
        </div>
    </div>

    <?php if (empty($entries)): ?>
    <div class="empty">
        <i class="fa-solid fa-money-check-dollar"></i>
        <p>No VAT email entries found.</p>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="dt" id="mainTable">
        <thead>
            <tr>
                <th>#</th>
                <th>Email Date</th>
                <th>Type</th>
                <th>Description</th>
                <th class="r">Net</th>
                <th class="r">VAT 18%</th>
                <th class="r">Total</th>
                <th style="text-align:center">Lines</th>
                <th style="text-align:center">Issues</th>
                <th style="text-align:center">Actions</th>
            </tr>
        </thead>
        <tbody id="mainTbody">
        <?php foreach ($entries as $idx => $e):
            $custC = intval($e['cust_count']);
            $empC  = intval($e['emp_count']);
            $issC  = intval($e['issue_count']);
        ?>
        <tr id="etr-<?php echo $e['id']; ?>"
            data-search="<?php echo strtolower(htmlspecialchars(($e['email_date']??'').' '.($e['description']??''))); ?>">
            <td style="color:#94a3b8;font-size:11px"><?php echo $idx+1; ?></td>
            <td style="font-weight:600;color:#1e293b">
                <i class="fa-regular fa-calendar" style="color:#1d4ed8;font-size:11px"></i>
                <?php echo $e['email_date'] ? date('d M Y', strtotime($e['email_date'])) : '—'; ?>
            </td>
            <td>
                <?php if ($custC > 0 && $empC > 0): ?>
                    <span class="pill-mix"><i class="fa-solid fa-shuffle"></i> Mixed</span>
                <?php elseif ($custC > 0): ?>
                    <span class="pill-cust"><i class="fa-solid fa-user"></i> Customer</span>
                <?php elseif ($empC > 0): ?>
                    <span class="pill-emp"><i class="fa-solid fa-id-badge"></i> Employee</span>
                <?php else: ?>
                    <span style="color:#d1d5db;font-size:11px">—</span>
                <?php endif; ?>
            </td>
            <td style="max-width:190px;overflow:hidden;text-overflow:ellipsis;font-size:12px;color:#475569"
                title="<?php echo htmlspecialchars($e['description']??''); ?>">
                <?php echo htmlspecialchars(mb_substr($e['description']??'—',0,50));
                      if (mb_strlen($e['description']??'')>50) echo '…'; ?>
            </td>
            <td class="r" style="color:#1d4ed8;font-weight:700"><?php echo ($e['total_net']??0)>0 ? number_format($e['total_net'],2) : '—'; ?></td>
            <td class="r" style="color:#7c3aed;font-weight:700"><?php echo ($e['total_vat']??0)>0 ? number_format($e['total_vat'],2) : '—'; ?></td>
            <td class="r amt"><?php echo floatval($e['total_amount'])>0 ? number_format($e['total_amount'],2) : '—'; ?></td>
            <td style="text-align:center">
                <?php $lc = intval($e['line_count']); ?>
                <?php if ($lc): ?><span class="tag tag-blue"><i class="fa-solid fa-list"></i> <?php echo $lc; ?></span>
                <?php else: ?><span style="color:#d1d5db">—</span><?php endif; ?>
            </td>
            <td style="text-align:center">
                <?php if ($issC): ?>
                    <span class="iss-badge-yes"><i class="fa-solid fa-check-circle"></i> <?php echo $issC; ?> issued</span>
                <?php else: ?>
                    <span class="iss-badge-no">Not issued</span>
                <?php endif; ?>
            </td>
            <td style="text-align:center">
                <div class="abtns" style="justify-content:center">
                    <button class="abtn abtn-issue" title="Issue Cheque Acknowledgment"
                        onclick="openIssueModal(<?php echo $e['id']; ?>)">
                        <i class="fa-solid fa-money-check"></i>
                    </button>
                    <?php if ($issC): ?>
                    <button class="abtn abtn-print" title="Print Customer Cheque Acknowledgment(s)" id="printBtn-<?php echo $e['id']; ?>"
                        onclick="printCustomerAcks(<?php echo $e['id']; ?>)" style="display:none">
                        <i class="fa-solid fa-print"></i>
                    </button>
                    <button class="abtn abtn-print" title="Print Employee Batch Cheque Acknowledgment" id="printEmpBtn-<?php echo $e['id']; ?>"
                        onclick="printEmployeeAck(<?php echo $e['id']; ?>)" style="display:none">
                        <i class="fa-solid fa-users"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4" style="text-align:right;font-size:11px;color:#64748b;font-weight:600">Page Totals</td>
                <td class="r" style="color:#1d4ed8"><?php echo number_format(array_sum(array_column($entries,'total_net')),2); ?></td>
                <td class="r" style="color:#7c3aed"><?php echo number_format(array_sum(array_column($entries,'total_vat')),2); ?></td>
                <td class="r amt"><?php echo number_format(array_sum(array_column($entries,'total_amount')),2); ?></td>
                <td colspan="3"></td>
            </tr>
        </tfoot>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════════
     ISSUE MODAL
══════════════════════════════════════════════════════════════════ -->
<div class="mo" id="issueModal">
<div class="mo-box">
    <div class="mo-hdr">
        <div>
            <div class="mo-hdr-title"><i class="fa-solid fa-money-check-dollar"></i> Issue Cheque Acknowledgment</div>
            <div class="mo-hdr-sub" id="issMeta">Entry #—</div>
        </div>
        <button class="mo-close" onclick="closeIssueModal()"><i class="fa-solid fa-xmark"></i> Close</button>
    </div>
    <div class="mo-body">
        <input type="hidden" id="iss_entry_id" value="0">
        <input type="hidden" id="iss_issue_id"  value="0">

        <!-- ── Setup Strip: dates + bank + book ── -->
        <div class="setup-strip">
            <div class="setup-strip-title"><i class="fa-solid fa-sliders"></i> Common Settings</div>

            <!-- Row 1: dates + remarks -->
            <div class="setup-row">
                <div class="fgrp">
                    <label class="flbl">Acknowledgment Date</label>
                    <input type="date" class="finp" id="iss_ack_date">
                </div>
                <div class="fgrp">
                    <label class="flbl">Cheque Issue Date</label>
                    <input type="date" class="finp" id="iss_cheque_date">
                </div>
                <div class="fgrp">
                    <label class="flbl">Common Date</label>
                    <input type="date" class="finp" id="iss_common_date">
                </div>
                <div class="fgrp" style="grid-column:span 2">
                    <label class="flbl">Remarks</label>
                    <input type="text" class="finp" id="iss_remarks" placeholder="Optional remarks…">
                </div>
            </div>

            <!-- Row 2: bank account + cheque book -->
            <div class="setup-row-bank" style="margin-top:14px">
                <div class="fgrp">
                    <label class="flbl"><i class="fa-solid fa-building-columns" style="color:#1d4ed8"></i> Bank Account</label>
                    <select class="finp" id="iss_bank_account" onchange="onIssBankChange()">
                        <option value="">-- Select Bank Account --</option>
                    </select>
                </div>
                <div class="fgrp">
                    <label class="flbl"><i class="fa-solid fa-book" style="color:#1d4ed8"></i> Cheque Book</label>
                    <select class="finp" id="iss_cheque_book" onchange="onIssBookChange()" disabled>
                        <option value="">-- Select after Bank --</option>
                    </select>
                </div>
                <div>
                    <button class="btn btn-primary btn-sm" id="autoAssignBtn" onclick="autoAssignAllCheques()" disabled
                        title="Auto-assign sequential cheques to all customer rows">
                        <i class="fa-solid fa-bolt"></i> Auto-Assign All
                    </button>
                </div>
            </div>

            <!-- Leaf bar for selected book -->
            <div id="iss_leaf_bar_wrap" style="display:none;margin-top:10px">
                <div class="leaf-bar-label">
                    <span id="iss_leaf_used" style="color:#64748b">0 used</span>
                    <span id="iss_leaf_remain" style="color:#16a34a;font-weight:700">0 remaining</span>
                </div>
                <div class="leaf-bar-track"><div class="leaf-bar-fill" id="iss_leaf_fill" style="width:0%"></div></div>
            </div>
        </div>

        <!-- ── Tabs ── -->
        <div class="tabs">
            <button class="tab-btn active" id="tab-cust-btn" onclick="switchTab('cust')">
                <i class="fa-solid fa-user"></i> Customers
                <span class="cnt-badge" id="tab-cust-count">0</span>
            </button>
            <button class="tab-btn" id="tab-emp-btn" onclick="switchTab('emp')">
                <i class="fa-solid fa-id-badge"></i> Employees
                <span class="cnt-badge" id="tab-emp-count">0</span>
            </button>
        </div>

        <!-- ── Customer tab ── -->
        <div class="tab-pane active" id="tab-cust">
            <div id="custLinesWrap">
                <div style="color:#94a3b8;text-align:center;padding:28px"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>
            </div>
        </div>

        <!-- ── Employee tab ── -->
        <div class="tab-pane" id="tab-emp">
            <div id="empPanelWrap">
                <div style="color:#94a3b8;text-align:center;padding:28px"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>
            </div>
        </div>

        <!-- Footer -->
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:18px;padding-top:14px;border-top:1px solid #e2e8f0">
            <button class="btn btn-light" onclick="closeIssueModal()">Cancel</button>
            <button class="btn btn-light" id="printIssueBtn" onclick="printCustomerAcks(_issEntryId)" style="display:none">
                <i class="fa-solid fa-print"></i> Print Customer Ack(s)
            </button>
            <button class="btn btn-light" id="printEmpIssueBtn" onclick="printEmployeeAck(_issEntryId)" style="display:none">
                <i class="fa-solid fa-users"></i> Print Employee Ack
            </button>
            <button class="btn btn-print" id="printChequesBtn" onclick="printChequesModal()">
    <i class="fa-solid fa-print"></i> Print Cheques
</button>
            <button class="btn btn-primary" id="saveIssueBtn" onclick="saveIssue()">
                <i class="fa-solid fa-floppy-disk"></i> Save Acknowledgment
            </button>
        </div>
    </div>
</div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     CANCEL CHEQUE MODAL (shared)
══════════════════════════════════════════════════════════════════ -->
<div class="mo" id="cancelModal">
<div class="mo-box mo-box-sm">
    <div class="mo-hdr">
        <div>
            <div class="mo-hdr-title"><i class="fa-solid fa-ban"></i> Cancel Cheque</div>
            <div class="mo-hdr-sub" id="cm_chequeLabel">—</div>
        </div>
        <button class="mo-close" onclick="closeCancelModal()"><i class="fa-solid fa-xmark"></i> Close</button>
    </div>
    <div class="mo-body">
        <p style="font-size:13px;color:#475569;margin:0 0 12px">
            This will mark the cheque as cancelled and load the next available leaf from the book.
        </p>
        <div class="fgrp">
            <label class="flbl">Cancel Reason <span style="color:#dc2626">*</span></label>
            <textarea class="finp" id="cm_reason" rows="3" placeholder="Enter reason for cancelling this cheque…" style="resize:vertical;min-height:70px"></textarea>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px">
            <button class="btn btn-light" onclick="closeCancelModal()">Dismiss</button>
            <button class="btn btn-danger" id="cm_confirmBtn" onclick="confirmCancelCheque()">
                <i class="fa-solid fa-ban"></i> Cancel &amp; Load Next
            </button>
        </div>
    </div>
</div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     PRINT MODAL
══════════════════════════════════════════════════════════════════ -->
<div class="mo" id="printModal">
<div class="mo-box print-modal-box">
    <div class="mo-hdr">
        <div class="mo-hdr-title"><i class="fa-solid fa-print"></i> Print Acknowledgment</div>
        <div style="display:flex;gap:8px;align-items:center">
            <button class="btn btn-print btn-sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
            <button class="mo-close" onclick="closePrintModal()"><i class="fa-solid fa-xmark"></i> Close</button>
        </div>
    </div>
    <div class="mo-body" style="padding:16px">
        <div id="printArea"></div>
    </div>
</div>
</div>

<div id="toast"></div>

<!-- ══════════════════════════════════════════════════════════════
     JAVASCRIPT
══════════════════════════════════════════════════════════════════ -->
<script>
/* ── Helpers ───────────────────────────────────────────────── */
const fN = n => new Intl.NumberFormat('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}).format(parseFloat(n)||0);
function eh(s){ const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
let _toastTimer;
function toast(msg,type='info'){
    const t=document.getElementById('toast');
    t.className=type; t.textContent=msg; t.style.display='block';
    clearTimeout(_toastTimer); _toastTimer=setTimeout(()=>t.style.display='none',3500);
}
function formatDate(d){
    if(!d) return '—';
    const dt=new Date(d+'T00:00:00');
    return dt.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});
}

/* ── Table search ─────────────────────────────────────────── */
function doSearch(){
    const q=document.getElementById('tblSearch').value.toLowerCase();
    let vis=0;
    document.querySelectorAll('#mainTbody tr').forEach(tr=>{
        const show=!q||tr.dataset.search.includes(q);
        tr.style.display=show?'':'none'; if(show) vis++;
    });
    document.getElementById('rowCountBadge').textContent=vis;
}

/* ═══════════════════════════════════════════════════════════════
   STATE
═══════════════════════════════════════════════════════════════ */
let _issEntryId   = 0;
let _custLines    = [];   // raw lines (line_type=customer)
let _empLines     = [];   // raw lines (line_type=employee)
let _custAssigned = {};   // { line_id : {cheque_no, cheque_book_id, bank_account_id, bank_account_no, bank_name, cancelled, cancel_reason} }
let _empBatch     = null; // { cheque_no, cheque_book_id, bank_account_id, bank_account_no, bank_name, total_amount, line_ids[], cancelled, cancel_reason }
let _bankAccounts = [];
let _currentBooks = [];   // books for currently selected bank
let _selectedBookId = 0;
let _selectedBookData = null;
let _printAckIdsByEntry = {}; // { entry_id : [ack_id, ack_id, ...] }  -- customer-type cheque acks available to print
let _empIssueIdByEntry = {};  // { entry_id : issue_id|null }        -- employee batch cheque (one shared ack) available to print

// Cancel modal state
let _cancelCtx = null; // { type:'cust'|'emp', lineId, bookId, chequeNo, onSuccess }

/* ═══════════════════════════════════════════════════════════════
   OPEN / CLOSE ISSUE MODAL
═══════════════════════════════════════════════════════════════ */
async function openIssueModal(entryId){
    _issEntryId=entryId;
    document.getElementById('iss_entry_id').value=entryId;
    document.getElementById('iss_issue_id').value=0;
    document.getElementById('iss_ack_date').value='';
    document.getElementById('iss_cheque_date').value='';
    document.getElementById('iss_common_date').value='';
    document.getElementById('iss_remarks').value='';
    document.getElementById('iss_cheque_book').innerHTML='<option value="">-- Select after Bank --</option>';
    document.getElementById('iss_cheque_book').disabled=true;
    document.getElementById('autoAssignBtn').disabled=true;
    document.getElementById('iss_leaf_bar_wrap').style.display='none';
    document.getElementById('printIssueBtn').style.display='none';
    _custAssigned={}; _empBatch=null; _selectedBookId=0; _selectedBookData=null;
    switchTab('cust');
    document.getElementById('issueModal').classList.add('open');
    document.getElementById('issMeta').textContent='Entry #'+entryId+' — Loading…';
    document.getElementById('custLinesWrap').innerHTML='<div style="color:#94a3b8;text-align:center;padding:28px"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
    document.getElementById('empPanelWrap').innerHTML='<div style="color:#94a3b8;text-align:center;padding:28px"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';

    // Load bank accounts once
    if(!_bankAccounts.length){
        const r=await fetch('cheque_acknowledgment_new.php?action=get_bank_accounts');
        const d=await r.json();
        if(d.success) _bankAccounts=d.data;
    }

    // Populate bank selector
    const bankSel=document.getElementById('iss_bank_account');
    bankSel.innerHTML='<option value="">-- Select Bank Account --</option>';
    _bankAccounts.forEach(b=>{
        const o=document.createElement('option');
        o.value=b.id; o.textContent=b.bank_name+' — '+b.account_no;
        o.dataset.accountNo=b.account_no; o.dataset.bankName=b.bank_name;
        bankSel.appendChild(o);
    });

    // Load entry lines + existing issues
    const r2=await fetch('cheque_acknowledgment_new.php?action=get_entry_lines&entry_id='+entryId);
    const d2=await r2.json();
    if(!d2.success){ toast('Failed to load entry','error'); return; }

    const entry=d2.entry;
    _custLines=d2.lines.filter(l=>l.line_type==='customer');
    _empLines =d2.lines.filter(l=>l.line_type==='employee');

    // Pre-load existing assignments
    if(d2.existing_cust){
        Object.values(d2.existing_cust).forEach(c=>{
            _custAssigned[c.line_id]={
                line_id:        c.line_id,
                bank_account_id:c.bank_account_id,
                bank_account_no:c.bank_account_no,
                bank_name:      c.bank_name,
                cheque_book_id: c.cheque_book_id,
                cheque_no:      c.cheque_no,
                cancelled:      !!parseInt(c.cancelled),
                cancel_reason:  c.cancel_reason || '',
                ack_id:         c.ack_id || null,
                ref_id:         c.ref_id,
                ref_code:       c.ref_code,
                ref_name:       c.ref_name,
                net_amount:     c.net_amount,
                vat_amount:     c.vat_amount,
                total_amount:   c.total_amount,
            };
        });
    }
    if(d2.existing_emp){
        const eb=d2.existing_emp;
        const eids=JSON.parse(eb.employee_ids||'[]');
        _empBatch={
            bank_account_id: eb.bank_account_id,
            bank_account_no: eb.bank_account_no,
            bank_name:       eb.bank_name,
            cheque_book_id:  eb.cheque_book_id,
            cheque_no:       eb.cheque_no,
            total_amount:    eb.total_amount,
            line_ids:        eids,
            cancelled:       !!parseInt(eb.cancelled),
            cancel_reason:   eb.cancel_reason || '',
        };
    }

    // Track printable acks (customer-type cheques only) for this entry
    _printAckIdsByEntry[entryId] = d2.print_ack_ids || [];
    // Track the employee batch's issue id — printable once a shared cheque
    // has been assigned and it isn't cancelled.
    _empIssueIdByEntry[entryId] = (d2.existing_emp && d2.existing_emp.cheque_no && !parseInt(d2.existing_emp.cancelled) && d2.issues && d2.issues.length)
        ? d2.issues[0].id : null;
    updatePrintButtons(entryId);

    // If existing issue, pre-load dates
    if(d2.issues && d2.issues.length){
        const latest=d2.issues[0];
        document.getElementById('iss_issue_id').value=latest.id;
        document.getElementById('iss_ack_date').value=latest.ack_date||'';
        document.getElementById('iss_cheque_date').value=latest.cheque_issue_date||'';
        document.getElementById('iss_common_date').value=latest.common_date||'';
        document.getElementById('iss_remarks').value=latest.remarks||'';

        // Pre-select bank/book from first existing customer assignment
        const firstAssigned=Object.values(_custAssigned).find(a=>a.bank_account_id && !a.cancelled);
        if(firstAssigned){
            bankSel.value=firstAssigned.bank_account_id;
            await onIssBankChange(firstAssigned.cheque_book_id);
        } else if(_empBatch && _empBatch.bank_account_id){
            bankSel.value=_empBatch.bank_account_id;
            await onIssBankChange(_empBatch.cheque_book_id);
        }
    }

    document.getElementById('issMeta').textContent=
        'Entry #'+entryId+' — '+formatDate(entry.email_date)+' — Total: '+fN(entry.total_amount);
    document.getElementById('tab-cust-count').textContent=_custLines.length;
    document.getElementById('tab-emp-count').textContent=_empLines.length;

    renderCustLines();
    renderEmpPanel();
}

function closeIssueModal(){ document.getElementById('issueModal').classList.remove('open'); }

function switchTab(tab){
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p=>p.classList.remove('active'));
    document.getElementById('tab-'+tab+'-btn').classList.add('active');
    document.getElementById('tab-'+tab).classList.add('active');
}

/* ── Show/hide the "Print Customer Ack(s)" buttons based on whether
   this entry's customer lines have at least one cheque-backed ack ── */
function updatePrintButtons(entryId){
    const ids=_printAckIdsByEntry[entryId]||[];
    const rowBtn=document.getElementById('printBtn-'+entryId);
    if(rowBtn) rowBtn.style.display = ids.length ? 'inline-flex' : 'none';

    const empIssueId=_empIssueIdByEntry[entryId]||null;
    const empRowBtn=document.getElementById('printEmpBtn-'+entryId);
    if(empRowBtn) empRowBtn.style.display = empIssueId ? 'inline-flex' : 'none';

    if(entryId===_issEntryId){
        const modalBtn=document.getElementById('printIssueBtn');
        if(modalBtn) modalBtn.style.display = ids.length ? 'inline-flex' : 'none';
        const empModalBtn=document.getElementById('printEmpIssueBtn');
        if(empModalBtn) empModalBtn.style.display = empIssueId ? 'inline-flex' : 'none';
    }
}

/* ── Open the legacy print screen for every customer cheque acknowledgment
   tied to this entry (one A4 page per customer claim) ── */
function printCustomerAcks(entryId){
    const ids=_printAckIdsByEntry[entryId]||[];
    if(!ids.length){ toast('No customer cheques have been assigned yet for this entry.','error'); return; }
    window.open('print_cheque_ack.php?ack_ids='+ids.join(','), '_blank');
}

/* ── Open the employee batch acknowledgment print screen — ONE shared
   acknowledgment page listing every employee on the batch cheque ── */
function printEmployeeAck(entryId){
    const issueId=_empIssueIdByEntry[entryId]||null;
    if(!issueId){ toast('No employee batch cheque has been assigned yet for this entry.','error'); return; }
    window.open('print_cheque_ack_employee.php?issue_id='+issueId, '_blank');
}

function printChequesModal(){
    const issueId = parseInt(document.getElementById('iss_issue_id').value) || 0;
    if(!issueId){
        toast('Please save the acknowledgment first.','error'); 
        return; 
    }
    window.open('print_cheque.php?issue_id=' + issueId, '_blank');
}

/* ═══════════════════════════════════════════════════════════════
   BANK / BOOK SELECTION (common, in setup strip)
═══════════════════════════════════════════════════════════════ */
async function onIssBankChange(preBookId=null){
    const bankId=document.getElementById('iss_bank_account').value;
    const bookSel=document.getElementById('iss_cheque_book');
    bookSel.innerHTML='<option value="">-- Loading… --</option>';
    bookSel.disabled=true;
    document.getElementById('autoAssignBtn').disabled=true;
    document.getElementById('iss_leaf_bar_wrap').style.display='none';
    _selectedBookId=0; _selectedBookData=null; _currentBooks=[];
    if(!bankId) return;

    const res=await fetch('cheque_acknowledgment_new.php?action=get_books&bank_account_id='+bankId);
    const d=await res.json();
    _currentBooks=d.data||[];

    bookSel.innerHTML='<option value="">-- Select Cheque Book --</option>';
    _currentBooks.forEach(bk=>{
        const o=document.createElement('option');
        o.value=bk.id;
        o.textContent=(bk.book_name||bk.bank_name_label||'Book #'+bk.id)+
                      ' ('+bk.leaf_no_start+'→'+bk.leaf_no_end+') · '+bk.leaves_remaining+' left';
        bookSel.appendChild(o);
    });
    bookSel.disabled=false;

    if(preBookId){
        bookSel.value=preBookId;
        onIssBookChange();
    }
}

function onIssBookChange(){
    const bookId=parseInt(document.getElementById('iss_cheque_book').value)||0;
    _selectedBookId=bookId;
    _selectedBookData=_currentBooks.find(b=>b.id==bookId)||null;
    if(!_selectedBookData){
        document.getElementById('autoAssignBtn').disabled=true;
        document.getElementById('iss_leaf_bar_wrap').style.display='none';
        return;
    }
    document.getElementById('autoAssignBtn').disabled=false;
    updateIssLeafBar(_selectedBookData);
}

function updateIssLeafBar(bk){
    const used      = parseInt(bk.used_count||0);
    const cancelled = parseInt(bk.cancelled_count||0);
    const total     = parseInt(bk.total_leaves||bk.leaf_count||0);
    const pct       = total>0 ? Math.min(100,Math.round(((used+cancelled)/total)*100)) : 0;
    document.getElementById('iss_leaf_bar_wrap').style.display='block';
    document.getElementById('iss_leaf_fill').style.width=pct+'%';
    document.getElementById('iss_leaf_used').textContent=used+' used, '+cancelled+' cancelled';
    document.getElementById('iss_leaf_remain').textContent=(bk.leaves_remaining||0)+' remaining';
}

/* ═══════════════════════════════════════════════════════════════
   AUTO-PERSIST + LEAF-BAR REFRESH
   Cheque assign/remove/cancel must be reflected in the DB immediately
   (not only after clicking "Save Acknowledgment"), otherwise:
     - the book's used/remaining counters stay stale on screen, and
     - get_bulk_cheques() still sees the old (unfreed) cheque as "used",
       so the NEXT assignment skips straight to the next sequential leaf
       instead of reusing the one you just removed/cancelled.
   This silently saves the current in-progress state (same payload as
   the manual Save button) then re-pulls live book stats.
═══════════════════════════════════════════════════════════════ */
async function saveIssueSilent(){
    const entryId=parseInt(document.getElementById('iss_entry_id').value)||0;
    if(!entryId) return null;
    const issueId=parseInt(document.getElementById('iss_issue_id').value)||0;
    const custPayload=Object.values(_custAssigned);
    const empPayload = _empBatch ? _empBatch : {};

    const fd=new FormData();
    fd.append('entry_id',entryId);
    fd.append('issue_id',issueId);
    fd.append('ack_date',document.getElementById('iss_ack_date').value);
    fd.append('cheque_issue_date',document.getElementById('iss_cheque_date').value);
    fd.append('common_date',document.getElementById('iss_common_date').value);
    fd.append('remarks',document.getElementById('iss_remarks').value);
    fd.append('customers',JSON.stringify(custPayload));
    fd.append('employees',JSON.stringify(empPayload));

    try{
        const res=await fetch('cheque_acknowledgment_new.php?action=save_issue',{method:'POST',body:fd});
        const d=await res.json();
        if(d.success){
            document.getElementById('iss_issue_id').value=d.issue_id;
            _printAckIdsByEntry[entryId] = d.print_ack_ids || [];
            _empIssueIdByEntry[entryId] = d.employee_issue_id || null;
            updatePrintButtons(entryId);
        }
        return d;
    }catch(e){ return null; }
}

async function refreshLeafBarForCurrentBook(){
    const bankId=document.getElementById('iss_bank_account').value;
    if(!bankId){ return; }

    const res=await fetch('cheque_acknowledgment_new.php?action=get_books&bank_account_id='+bankId);
    const d=await res.json();
    _currentBooks=d.data||[];

    // Repopulate the book dropdown so its "N left" label is current, without
    // losing the user's current selection.
    const bookSel=document.getElementById('iss_cheque_book');
    const curVal=bookSel.value;
    bookSel.innerHTML='<option value="">-- Select Cheque Book --</option>';
    _currentBooks.forEach(bk=>{
        const o=document.createElement('option');
        o.value=bk.id;
        o.textContent=(bk.book_name||bk.bank_name_label||'Book #'+bk.id)+
                      ' ('+bk.leaf_no_start+'→'+bk.leaf_no_end+') · '+bk.leaves_remaining+' left';
        bookSel.appendChild(o);
    });
    if(curVal){ bookSel.value=curVal; }

    if(_selectedBookId){
        _selectedBookData=_currentBooks.find(b=>b.id==_selectedBookId)||null;
        if(_selectedBookData) updateIssLeafBar(_selectedBookData);
    }
}

/* Call after any action that changes which leaves are used/free — persists
   the current state then pulls fresh book stats. */
async function persistAndRefreshBook(){
    await saveIssueSilent();
    await refreshLeafBarForCurrentBook();
}

/* ═══════════════════════════════════════════════════════════════
   AUTO-ASSIGN ALL CHEQUES
═══════════════════════════════════════════════════════════════ */
async function autoAssignAllCheques(){
    if(!_selectedBookId){ toast('Select a cheque book first.','error'); return; }
    const unassigned=_custLines.filter(l=>!_custAssigned[l.id]||!_custAssigned[l.id].cheque_no||_custAssigned[l.id].cancelled);
    if(!unassigned.length){ toast('All customer lines already have cheques assigned.','info'); return; }

    const bankSel=document.getElementById('iss_bank_account');
    const bankOpt=bankSel.options[bankSel.selectedIndex];
    const bankId  =bankSel.value;
    const bankAccNo=bankOpt?.dataset.accountNo||'';
    const bankName =bankOpt?.dataset.bankName||bankOpt?.textContent||'';

    const btn=document.getElementById('autoAssignBtn');
    btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Assigning…';

    const res=await fetch(
        `cheque_acknowledgment_new.php?action=get_bulk_cheques&book_id=${_selectedBookId}&count=${unassigned.length}`
    );
    const d=await res.json();

    btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-bolt"></i> Auto-Assign All';

    if(!d.success){ toast(d.message||'Failed to fetch cheques','error'); return; }
    if(d.found < unassigned.length){
        toast(`Only ${d.found} cheques available, need ${unassigned.length}. Assigning what's available.`,'warn');
    }

    d.cheques.forEach((chequeNo, i)=>{
        const line=unassigned[i];
        if(!line) return;
        _custAssigned[line.id]={
            line_id:        line.id,
            bank_account_id:bankId,
            bank_account_no:bankAccNo,
            bank_name:      bankName,
            cheque_book_id: _selectedBookId,
            cheque_no:      chequeNo,
            cancelled:      false,
            cancel_reason:  '',
            ref_id:         line.ref_id,
            ref_code:       line.ref_code,
            ref_name:       line.ref_name,
            net_amount:     line.net_amount,
            vat_amount:     line.vat_amount,
            total_amount:   line.total_amount,
        };
    });

    // Refresh leaf bar
    if(d.book){
        _selectedBookData={..._selectedBookData,
            used_count: d.used_count, cancelled_count: d.cancelled_count,
            leaves_remaining: d.leaves_remaining - d.found,
        };
        updateIssLeafBar(_selectedBookData);
    }

    toast(`${d.found} cheque(s) assigned!`,'success');
    renderCustLines();
    await persistAndRefreshBook();
}

/* ═══════════════════════════════════════════════════════════════
   RENDER CUSTOMER LINES TABLE
═══════════════════════════════════════════════════════════════ */
function renderCustLines(){
    const wrap=document.getElementById('custLinesWrap');
    if(!_custLines.length){
        wrap.innerHTML='<div style="color:#94a3b8;text-align:center;padding:28px"><i class="fa-solid fa-inbox"></i><br>No customer lines in this entry.</div>';
        return;
    }

    const rows=_custLines.map((l,idx)=>{
        const a=_custAssigned[l.id];
        // A line only counts as "assigned" if it actually has a cheque_no
        // AND isn't cancelled — a removed cheque (cheque_no cleared) or a
        // cancelled one must fall through to their own branches below.
        const isAssigned  = !!(a && a.cheque_no && !a.cancelled);
        const isCancelled = !!(a && a.cancelled);
        let chequeCell='';
        let actionCell='';
        let trClass='';

        if(isAssigned){
            trClass='assigned';
            chequeCell=`<div class="ch-cell">
                <span class="ch-no">${eh(a.cheque_no)}</span>
                <span style="font-size:10px;color:#64748b">${eh(a.bank_name)}</span>
            </div>`;
            actionCell=`<div style="display:flex;gap:4px;white-space:nowrap">
                <button class="btn btn-danger btn-xs" onclick="removeCheque(${l.id})" title="Remove this cheque assignment">
                    <i class="fa-solid fa-xmark"></i> Remove
                </button>
                <button class="btn btn-amber btn-xs" onclick="openCancelModal('cust',${l.id},${a.cheque_book_id||0},'${eh(a.cheque_no)}')" title="Cancel this cheque leaf">
                    <i class="fa-solid fa-ban"></i> Cancel
                </button>
            </div>`;
        } else if(isCancelled){
            trClass='cancelled';
            chequeCell=`<div class="ch-cell">
                <span class="ch-no cancelled">${eh(a.cheque_no)}</span>
                <span class="tag tag-red" style="font-size:10px" title="${eh(a.cancel_reason||'')}">Cancelled</span>
            </div>`;
            actionCell=`<button class="btn btn-primary btn-xs" onclick="reassignCheque(${l.id})">
                <i class="fa-solid fa-rotate-right"></i> Reassign
            </button>`;
        } else {
            chequeCell=`<span class="ch-none">Not assigned</span>`;
            actionCell=`<button class="btn btn-green btn-xs" onclick="assignSingleCheque(${l.id})">
                <i class="fa-solid fa-money-check"></i> Assign
            </button>`;
        }

        return `<tr class="${trClass}">
            <td style="color:#94a3b8;font-size:11px">${idx+1}</td>
            <td><span class="pill-cust" style="font-size:11px">[${eh(l.ref_code)}]</span></td>
            <td style="font-weight:600;color:#1e293b">${eh(l.ref_name)}</td>
            <td class="r" style="color:#1d4ed8;font-weight:700">${fN(l.net_amount)}</td>
            <td class="r" style="color:#7c3aed">${fN(l.vat_amount)}</td>
            <td class="r" style="font-weight:800;color:#312e81">${fN(l.total_amount)}</td>
            <td>${chequeCell}</td>
            <td>${actionCell}</td>
        </tr>`;
    }).join('');

    const assignedCount=_custLines.filter(l=>_custAssigned[l.id]&&_custAssigned[l.id].cheque_no&&!_custAssigned[l.id].cancelled).length;
    wrap.innerHTML=`
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;flex-wrap:wrap;gap:8px">
            <div style="font-size:12px;color:#64748b">
                <strong style="color:#1d4ed8">${assignedCount}</strong> of <strong>${_custLines.length}</strong> lines have cheques assigned
            </div>
        </div>
        <div style="overflow-x:auto">
        <table class="lt">
            <thead><tr>
                <th>#</th><th>Code</th><th>Customer</th>
                <th class="r">Net</th><th class="r">VAT</th><th class="r">Total</th>
                <th>Cheque No</th><th>Actions</th>
            </tr></thead>
            <tbody>${rows}</tbody>
        </table>
        </div>`;
}

/* ── Individual cheque actions ───────────────────────────────── */
async function removeCheque(lineId){
    if(!confirm('Remove cheque assignment for this customer?')) return;
    // IMPORTANT: don't delete the entry outright — if the line was already
    // saved with a cheque before, deleting it here means save_issue() never
    // hears about the removal (it only acts on rows present in the payload),
    // so the old cheque_no would silently "stick" in the DB. Instead, keep
    // the row but clear its cheque fields so the next save explicitly wipes it.
    if(_custAssigned[lineId]){
        _custAssigned[lineId] = {..._custAssigned[lineId],
            cheque_no:'', cheque_book_id:0, bank_account_id:0, bank_account_no:'', bank_name:'',
            cancelled:false, cancel_reason:''
        };
    }
    renderCustLines();
    toast('Cheque assignment removed.','warn');
    // Persist the removal immediately (don't wait for manual Save) so the
    // freed leaf is instantly available again in the cheque book's counts
    // and reused — instead of skipped over — on the next assignment.
    await persistAndRefreshBook();
}

async function assignSingleCheque(lineId){
    if(!_selectedBookId){ toast('Select a Bank Account and Cheque Book first (in Common Settings above).','error'); return; }
    const bankSel=document.getElementById('iss_bank_account');
    const bankOpt=bankSel.options[bankSel.selectedIndex];
    const bankId  =bankSel.value;
    const bankAccNo=bankOpt?.dataset.accountNo||'';
    const bankName =bankOpt?.dataset.bankName||bankOpt?.textContent||'';

    // Get already-assigned (non-cancelled) cheque nos to exclude
    const exclude=Object.values(_custAssigned)
        .filter(a=>!a.cancelled&&a.cheque_no&&a.cheque_book_id==_selectedBookId)
        .map(a=>a.cheque_no);

    const res=await fetch(`cheque_acknowledgment_new.php?action=get_bulk_cheques&book_id=${_selectedBookId}&count=1`);
    const d=await res.json();
    if(!d.success||!d.cheques.length){ toast('No available cheques in selected book.','error'); return; }

    // Find one not in our current session's assignments
    const chequeNo=d.cheques.find(c=>!exclude.includes(c));
    if(!chequeNo){ toast('No available cheques in selected book.','error'); return; }

    const line=_custLines.find(l=>l.id==lineId);
    _custAssigned[lineId]={
        line_id:        lineId,
        bank_account_id:bankId,
        bank_account_no:bankAccNo,
        bank_name:      bankName,
        cheque_book_id: _selectedBookId,
        cheque_no:      chequeNo,
        cancelled:      false,
        cancel_reason:  '',
        ref_id:         line?.ref_id||0,
        ref_code:       line?.ref_code||'',
        ref_name:       line?.ref_name||'',
        net_amount:     line?.net_amount||0,
        vat_amount:     line?.vat_amount||0,
        total_amount:   line?.total_amount||0,
    };
    toast('Cheque '+chequeNo+' assigned!','success');
    renderCustLines();
    await persistAndRefreshBook();
}

async function reassignCheque(lineId){
    // Remove cancelled flag and reassign fresh cheque
    delete _custAssigned[lineId];
    await assignSingleCheque(lineId);
}

/* ═══════════════════════════════════════════════════════════════
   EMPLOYEE PANEL  (one cheque for ALL employees)
═══════════════════════════════════════════════════════════════ */
function renderEmpPanel(){
    const wrap=document.getElementById('empPanelWrap');
    if(!_empLines.length){
        wrap.innerHTML='<div style="color:#94a3b8;text-align:center;padding:28px"><i class="fa-solid fa-inbox"></i><br>No employee lines in this entry.</div>';
        return;
    }

    const total=_empLines.reduce((s,l)=>s+parseFloat(l.total_amount||0),0);
    const allIds=_empLines.map(l=>l.id);

    // Auto-set line_ids in batch to all employees
    if(!_empBatch){
        _empBatch={bank_account_id:'',bank_account_no:'',bank_name:'',
                   cheque_book_id:'',cheque_no:'',total_amount:total,
                   line_ids:allIds,cancelled:false,cancel_reason:''};
    } else {
        _empBatch.line_ids=allIds;
        _empBatch.total_amount=total;
    }

    const empRows=_empLines.map((l,i)=>`
        <tr>
            <td style="color:#94a3b8;font-size:11px">${i+1}</td>
            <td><span class="pill-emp" style="font-size:11px">[${eh(l.ref_code)}]</span></td>
            <td style="font-weight:600;color:#1e293b">${eh(l.ref_name)}</td>
            <td class="r">${fN(l.net_amount)}</td>
            <td class="r">${fN(l.vat_amount)}</td>
            <td class="r" style="font-weight:800;color:#92400e">${fN(l.total_amount)}</td>
        </tr>`).join('');

    const batchAssigned = _empBatch && _empBatch.cheque_no && !_empBatch.cancelled;
    const batchCancelled= _empBatch && _empBatch.cancelled;

    let chequeBoxHtml='';
    if(batchAssigned){
        chequeBoxHtml=`
            <div class="emp-cheque-box">
                <div class="emp-cheque-box-title"><i class="fa-solid fa-check-circle" style="color:#16a34a"></i> Batch Cheque Assigned</div>
                <div class="emp-cheque-assigned">
                    <span class="ch-no">${eh(_empBatch.cheque_no)}</span>
                    <span style="font-size:12px;color:#64748b">${eh(_empBatch.bank_name)}</span>
                    <span style="font-size:12px;color:#64748b">${fN(_empBatch.total_amount)}</span>
                    <button class="btn btn-danger btn-xs" onclick="removeEmpCheque()">
                        <i class="fa-solid fa-xmark"></i> Remove
                    </button>
                    <button class="btn btn-amber btn-xs" onclick="openCancelModal('emp',0,${_empBatch.cheque_book_id||0},'${eh(_empBatch.cheque_no)}')">
                        <i class="fa-solid fa-ban"></i> Cancel
                    </button>
                </div>
            </div>`;
    } else if(batchCancelled){
        chequeBoxHtml=`
            <div class="emp-cheque-box">
                <div class="emp-cheque-box-title"><i class="fa-solid fa-ban" style="color:#dc2626"></i> Batch Cheque Cancelled</div>
                <div class="emp-cheque-assigned">
                    <span class="ch-no cancelled" title="${eh(_empBatch.cancel_reason||'')}">${eh(_empBatch.cheque_no)}</span>
                    <button class="btn btn-amber btn-xs" onclick="assignEmpCheque()">
                        <i class="fa-solid fa-rotate-right"></i> Assign New Cheque
                    </button>
                </div>
            </div>`;
    } else {
        chequeBoxHtml=`
            <div class="emp-cheque-box">
                <div class="emp-cheque-box-title">Assign One Batch Cheque for All Employees</div>
                <p style="font-size:12px;color:#92400e;margin:0 0 10px">
                    Select Bank Account &amp; Cheque Book in Common Settings above, then click Assign.
                </p>
                <button class="btn btn-amber btn-sm" onclick="assignEmpCheque()">
                    <i class="fa-solid fa-money-check"></i> Assign Batch Cheque
                </button>
            </div>`;
    }

    wrap.innerHTML=`
        <div class="emp-panel">
            <div class="emp-panel-hdr"><i class="fa-solid fa-users"></i> Employee Lines</div>
            <div class="emp-panel-sub">All ${_empLines.length} employee(s) share a single cheque — total <strong>${fN(total)}</strong></div>
            <table class="emp-list-tbl">
                <thead><tr>
                    <th>#</th><th>Code</th><th>Employee</th>
                    <th class="r">Net</th><th class="r">VAT</th><th class="r">Total</th>
                </tr></thead>
                <tbody>${empRows}</tbody>
                <tfoot><tr class="emp-total-row">
                    <td colspan="5" style="text-align:right">Total</td>
                    <td class="r">${fN(total)}</td>
                </tr></tfoot>
            </table>
            ${chequeBoxHtml}
        </div>`;
}

async function assignEmpCheque(){
    if(!_selectedBookId){ toast('Select a Bank Account and Cheque Book in Common Settings first.','error'); return; }

    const bankSel=document.getElementById('iss_bank_account');
    const bankOpt=bankSel.options[bankSel.selectedIndex];
    const bankId  =bankSel.value;
    const bankAccNo=bankOpt?.dataset.accountNo||'';
    const bankName =bankOpt?.dataset.bankName||bankOpt?.textContent||'';

    const res=await fetch(`cheque_acknowledgment_new.php?action=get_bulk_cheques&book_id=${_selectedBookId}&count=1`);
    const d=await res.json();
    if(!d.success||!d.cheques.length){ toast('No available cheques in selected book.','error'); return; }

    const chequeNo=d.cheques[0];
    const allIds=_empLines.map(l=>l.id);
    const total=_empLines.reduce((s,l)=>s+parseFloat(l.total_amount||0),0);

    _empBatch={
        bank_account_id: bankId,
        bank_account_no: bankAccNo,
        bank_name:       bankName,
        cheque_book_id:  _selectedBookId,
        cheque_no:       chequeNo,
        total_amount:    total,
        line_ids:        allIds,
        cancelled:       false,
        cancel_reason:   '',
    };
    toast('Batch cheque '+chequeNo+' assigned!','success');
    renderEmpPanel();
    // Persist immediately + refresh the leaf bar — previously assigning the
    // employee batch cheque never touched the book's used/remaining display
    // at all until a manual Save.
    await persistAndRefreshBook();
}

async function removeEmpCheque(){
    if(!confirm('Remove employee batch cheque assignment?')) return;
    // As with removeCheque(), keep the object (don't null it out) so the
    // cleared state actually gets sent to save_issue() and persisted.
    _empBatch={..._empBatch, cheque_no:'', cheque_book_id:'', bank_account_id:'', bank_account_no:'', bank_name:'',
        cancelled:false, cancel_reason:''};
    renderEmpPanel();
    toast('Employee batch cheque removed.','warn');
    // Persist the removal right away so the freed leaf is instantly
    // available again (and actually disappears from "assigned" on screen
    // instead of only clearing after a manual Save).
    await persistAndRefreshBook();
}

/* ═══════════════════════════════════════════════════════════════
   CANCEL CHEQUE MODAL
═══════════════════════════════════════════════════════════════ */
function openCancelModal(type, lineId, bookId, chequeNo){
    _cancelCtx={type, lineId, bookId, chequeNo};
    document.getElementById('cm_chequeLabel').textContent='Cheque '+chequeNo+' — Book #'+bookId;
    document.getElementById('cm_reason').value='';
    document.getElementById('cancelModal').classList.add('open');
}
function closeCancelModal(){ document.getElementById('cancelModal').classList.remove('open'); }

async function confirmCancelCheque(){
    const reason=document.getElementById('cm_reason').value.trim();
    if(!reason){ toast('Please enter a reason.','error'); return; }
    const {type, lineId, bookId, chequeNo}=_cancelCtx;
    if(!bookId||!chequeNo){ toast('Invalid cheque data.','error'); return; }

    const btn=document.getElementById('cm_confirmBtn');
    btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Cancelling…';

    const fd=new FormData();
    fd.append('book_id',bookId);
    fd.append('cheque_no',chequeNo);
    fd.append('reason',reason);
    const res=await fetch('cheque_acknowledgment_new.php?action=cancel_cheque_leaf',{method:'POST',body:fd});
    const d=await res.json();

    btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-ban"></i> Cancel &amp; Load Next';

    if(!d.success){ toast(d.message||'Cancel failed','error'); return; }

    // Mark as cancelled in local state — the reason MUST be stored on the
    // assignment object itself, otherwise it never reaches save_issue() and
    // the line reloads as if nothing happened.
    if(type==='cust' && _custAssigned[lineId]){
        _custAssigned[lineId].cancelled=true;
        _custAssigned[lineId].cancel_reason=reason;
        // Auto-assign next cheque if available
        if(d.next){
            delete _custAssigned[lineId];
            await assignSingleCheque(lineId);
        }
        renderCustLines();
    } else if(type==='emp' && _empBatch){
        _empBatch.cancelled=true;
        _empBatch.cancel_reason=reason;
        // Auto-assign next for employee batch
        if(d.next){
            _empBatch={..._empBatch,
                cheque_no:     d.next.cheque_no,
                cancelled:     false,
                cancel_reason: '',
            };
        }
        renderEmpPanel();
    }

    toast('Cheque '+chequeNo+' cancelled.'+(d.next?' Next: '+d.next.cheque_no:''),'warn');
    closeCancelModal();

    // The book-level leaf cancellation (ca_cancelled_cheques) was just saved
    // by the AJAX call above, but the per-line/batch "cancelled" flag and
    // reason still only live in local JS state. Persist that immediately
    // and pull fresh, authoritative book stats — this also covers the case
    // where no replacement leaf was available (d.next is null).
    await persistAndRefreshBook();
}

/* ═══════════════════════════════════════════════════════════════
   SAVE ISSUE
═══════════════════════════════════════════════════════════════ */
async function saveIssue(){
    const entryId=parseInt(document.getElementById('iss_entry_id').value)||0;
    const issueId=parseInt(document.getElementById('iss_issue_id').value)||0;
    if(!entryId){ toast('No entry selected.','error'); return; }

    // Send EVERY customer line we know about — including removed/cancelled
    // ones — so the backend can explicitly clear or persist their state.
    // (Previously this filtered out cancelled rows entirely, which meant
    // save_issue() never touched them and old cheque data "stuck" in the DB.)
    const custPayload=Object.values(_custAssigned);
    // Same idea for the employee batch: always send the current object (even
    // if cleared/cancelled) rather than {} whenever there's no active cheque,
    // otherwise removal/cancellation never reaches the DB.
    const empPayload = _empBatch ? _empBatch : {};

    const btn=document.getElementById('saveIssueBtn');
    btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const fd=new FormData();
    fd.append('entry_id',entryId);
    fd.append('issue_id',issueId);
    fd.append('ack_date',document.getElementById('iss_ack_date').value);
    fd.append('cheque_issue_date',document.getElementById('iss_cheque_date').value);
    fd.append('common_date',document.getElementById('iss_common_date').value);
    fd.append('remarks',document.getElementById('iss_remarks').value);
    fd.append('customers',JSON.stringify(custPayload));
    fd.append('employees',JSON.stringify(empPayload));

    try{
        const res=await fetch('cheque_acknowledgment_new.php?action=save_issue',{method:'POST',body:fd});
        const d=await res.json();
        if(!d.success) throw new Error(d.message||'Save failed');
        document.getElementById('iss_issue_id').value=d.issue_id;

        // The backend just wrote/refreshed the cheque-book usage records
        // (dl_cheque_acknowledgments + ca_customer_claims) for every
        // customer-type cheque on this entry; remember the printable IDs.
        _printAckIdsByEntry[entryId] = d.print_ack_ids || [];
        _empIssueIdByEntry[entryId] = d.employee_issue_id || null;
        updatePrintButtons(entryId);

        toast('Acknowledgment saved!','success');
        const tr=document.getElementById('etr-'+entryId);
        if(tr){
            const cell=tr.querySelector('td:nth-child(9)');
            if(cell) cell.innerHTML='<span class="iss-badge-yes"><i class="fa-solid fa-check-circle"></i> Issued</span>';
        }
    }catch(e){ toast('Error: '+e.message,'error'); }
    finally{ btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Acknowledgment'; }
}

/* ═══════════════════════════════════════════════════════════════
   PRINT MODAL (kept for compatibility — not used by default flow,
   which now opens the legacy print_cheque_ack.php directly for
   customer-type cheques via printCustomerAcks())
═══════════════════════════════════════════════════════════════ */
async function openPrintModal(entryId){
    document.getElementById('printModal').classList.add('open');
    document.getElementById('printArea').innerHTML='<div style="text-align:center;padding:40px;color:#94a3b8"><i class="fa-solid fa-spinner fa-spin fa-2x"></i></div>';

    const r=await fetch('cheque_acknowledgment_new.php?action=get_entry_lines&entry_id='+entryId);
    const d=await r.json();
    if(!d.success||!d.issues||!d.issues.length){
        document.getElementById('printArea').innerHTML='<div style="text-align:center;color:#dc2626;padding:30px">No issue records found.</div>';
        return;
    }
    const r2=await fetch('cheque_acknowledgment_new.php?action=get_issue&issue_id='+d.issues[0].id);
    const d2=await r2.json();
    if(!d2.success){
        document.getElementById('printArea').innerHTML='<div style="text-align:center;color:#dc2626;padding:30px">Failed to load issue data.</div>';
        return;
    }
    renderPrint(d2);
}
function closePrintModal(){ document.getElementById('printModal').classList.remove('open'); }

function renderPrint(d){
    const h=d.header;
    const custs=(d.customers||[]).filter(c=>!parseInt(c.cancelled));
    const empBatch=d.employee_batch;
    const empLines=d.employee_lines||[];

    const custRows=custs.map(c=>`
        <tr>
            <td>[${eh(c.ref_code)}] ${eh(c.ref_name)}</td>
            <td class="r">${fN(c.net_amount)}</td>
            <td class="r">${fN(c.vat_amount)}</td>
            <td class="r">${fN(c.total_amount)}</td>
            <td>${eh(c.bank_name)}</td>
            <td style="font-weight:800;color:#1d4ed8;font-family:monospace">${eh(c.cheque_no)}</td>
        </tr>`).join('');

    const custTotal=custs.reduce((s,c)=>s+parseFloat(c.total_amount||0),0);

    let empSection='';
    if(empBatch && !parseInt(empBatch.cancelled)){
        const empNames=empLines.map(l=>'['+eh(l.ref_code)+'] '+eh(l.ref_name)).join(', ');
        empSection=`<div class="print-section">
            <div class="print-section-title"><i class="fa-solid fa-users"></i> Employee Batch Cheque</div>
            <table class="print-tbl">
                <thead><tr><th>Employees</th><th>Bank</th><th class="r">Total</th><th>Cheque No</th></tr></thead>
                <tbody><tr>
                    <td style="white-space:normal;font-size:11px;max-width:200px">${empNames}</td>
                    <td>${eh(empBatch.bank_name)}</td>
                    <td class="r">${fN(empBatch.total_amount)}</td>
                    <td style="font-weight:800;color:#1d4ed8;font-family:monospace">${eh(empBatch.cheque_no)}</td>
                </tr></tbody>
            </table>
        </div>`;
    }

    document.getElementById('printArea').innerHTML=`
        <div class="print-company">
            <h2>SSCL Cheque Acknowledgment</h2>
            <p>VAT Email Entry Cheque Issuance</p>
        </div>
        <div class="print-title">CHEQUE ACKNOWLEDGMENT SLIP</div>
        <div class="print-meta">
            <div class="pmr"><span class="pmr-lbl">Entry #:</span><span class="pmr-val">${h.entry_id}</span></div>
            <div class="pmr"><span class="pmr-lbl">Email Date:</span><span class="pmr-val">${formatDate(h.email_date)}</span></div>
            <div class="pmr"><span class="pmr-lbl">Ack. Date:</span><span class="pmr-val">${formatDate(h.ack_date)}</span></div>
            <div class="pmr"><span class="pmr-lbl">Cheque Issue Date:</span><span class="pmr-val">${formatDate(h.cheque_issue_date)}</span></div>
            <div class="pmr"><span class="pmr-lbl">Common Date:</span><span class="pmr-val">${formatDate(h.common_date)}</span></div>
            <div class="pmr"><span class="pmr-lbl">Entry Total:</span><span class="pmr-val" style="color:#1d4ed8">${fN(h.entry_total)}</span></div>
        </div>
        ${h.entry_desc?`<div style="font-size:12px;color:#475569;margin-bottom:12px"><strong>Description:</strong> ${eh(h.entry_desc)}</div>`:''}
        ${custs.length?`<div class="print-section">
            <div class="print-section-title"><i class="fa-solid fa-user"></i> Customer Cheques</div>
            <table class="print-tbl">
                <thead><tr><th>Customer</th><th class="r">Net</th><th class="r">VAT</th><th class="r">Total</th><th>Bank</th><th>Cheque No</th></tr></thead>
                <tbody>${custRows}</tbody>
                <tfoot><tr>
                    <td colspan="3" style="text-align:right;font-weight:700">Total</td>
                    <td class="r">${fN(custTotal)}</td><td colspan="2"></td>
                </tr></tfoot>
            </table>
        </div>`:''}
        ${empSection}
        ${h.remarks?`<div style="background:#fffbeb;border:1px solid #fde68a;padding:10px 12px;border-radius:7px;font-size:12px;margin-top:10px"><strong>Remarks:</strong> ${eh(h.remarks)}</div>`:''}
        <div class="print-sig">
            <div class="print-sig-line">Prepared By</div>
            <div class="print-sig-line">Checked By</div>
            <div class="print-sig-line">Authorized By</div>
        </div>`;
}

/* ── Backdrop close ── */
document.getElementById('issueModal').addEventListener('click',e=>{if(e.target===e.currentTarget)closeIssueModal();});
document.getElementById('cancelModal').addEventListener('click',e=>{if(e.target===e.currentTarget)closeCancelModal();});
document.getElementById('printModal').addEventListener('click',e=>{if(e.target===e.currentTarget)closePrintModal();});

/* ── On page load, check which already-issued entries have printable
   customer acks, so the row print button shows correctly without
   having to open the modal first. ── */
document.addEventListener('DOMContentLoaded', async ()=>{
    const issuedRows=document.querySelectorAll('.iss-badge-yes');
    for(const badge of issuedRows){
        const tr=badge.closest('tr');
        if(!tr) continue;
        const entryId=parseInt(tr.id.replace('etr-',''))||0;
        if(!entryId) continue;
        try{
            const r=await fetch('cheque_acknowledgment_new.php?action=get_entry_lines&entry_id='+entryId);
            const d=await r.json();
            if(d.success){
                _printAckIdsByEntry[entryId]=d.print_ack_ids||[];
                _empIssueIdByEntry[entryId] = (d.existing_emp && d.existing_emp.cheque_no && !parseInt(d.existing_emp.cancelled) && d.issues && d.issues.length)
                    ? d.issues[0].id : null;
                updatePrintButtons(entryId);
            }
        }catch(e){}
    }
});
</script>

<?php include 'footer.php'; ?>