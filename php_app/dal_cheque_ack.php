<?php
include 'config.php';

// ══════════════════════════════════════════════════════════════════
//  ENSURE TABLES
// ══════════════════════════════════════════════════════════════════
function ensure_dca_tables($conn) {
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS dl_cheque_acknowledgments (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        confirmed_payment_id INT NULL,
        claim_item_id       INT NOT NULL DEFAULT 0,
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
        cheque_date         DATE NULL,
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
    // safe migrations
    $cols = [
        'dal_entry_id'  => "INT NULL",
        'line_type'     => "VARCHAR(20) NULL DEFAULT 'customer'",
        'ref_id'        => "INT NULL",
        'common_date'   => "DATE NULL",
    ];
    foreach ($cols as $col => $def) {
        $chk = mysqli_query($conn, "SHOW COLUMNS FROM dl_cheque_acknowledgments LIKE '$col'");
        if ($chk && mysqli_num_rows($chk) === 0)
            @mysqli_query($conn, "ALTER TABLE dl_cheque_acknowledgments ADD COLUMN $col $def");
    }
    $cc_cols = ['cheque_date' => "DATE NULL", 'dal_entry_id' => "INT NULL"];
    foreach ($cc_cols as $col => $def) {
        $chk = mysqli_query($conn, "SHOW COLUMNS FROM ca_customer_claims LIKE '$col'");
        if ($chk && mysqli_num_rows($chk) === 0)
            @mysqli_query($conn, "ALTER TABLE ca_customer_claims ADD COLUMN $col $def");
    }
}
ensure_dca_tables($conn);

// ══════════════════════════════════════════════════════════════════
//  CHEQUE BOOK HELPER
// ══════════════════════════════════════════════════════════════════
function get_next_available_cheque($conn, $book_id) {
    $book_id = intval($book_id);
    $book = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM customer_claim_cheque_books WHERE id=$book_id LIMIT 1"));
    if (!$book) return null;

    preg_match('/^([A-Za-z\-]*)(\d+)$/', $book['leaf_no_start'], $sm);
    $prefix    = $sm[1] ?? '';
    $start_str = $sm[2] ?? $book['leaf_no_start'];
    $pad_width = strlen($start_str);
    $start_num = intval(preg_replace('/\D/', '', $book['leaf_no_start']));
    $end_num   = intval(preg_replace('/\D/', '', $book['leaf_no_end']));
    $total     = $end_num - $start_num + 1;

    $used_res = mysqli_query($conn,
        "SELECT cheque_no FROM ca_customer_claims WHERE cheque_book_id=$book_id AND cheque_no!='' AND cheque_no IS NOT NULL");
    $used = [];
    while ($u = mysqli_fetch_assoc($used_res)) $used[] = $u['cheque_no'];

    $canc_res = mysqli_query($conn,
        "SELECT cheque_no FROM ca_cancelled_cheques WHERE cheque_book_id=$book_id");
    $cancelled = [];
    while ($c = mysqli_fetch_assoc($canc_res)) $cancelled[] = $c['cheque_no'];

    $blocked = array_merge($used, $cancelled);

    for ($n = $start_num; $n <= $end_num; $n++) {
        $leaf_no = $prefix . str_pad($n, $pad_width, '0', STR_PAD_LEFT);
        if (!in_array($leaf_no, $blocked)) {
            return [
                'cheque_no'        => $leaf_no,
                'total_leaves'     => $total,
                'used_count'       => count($used),
                'cancelled_count'  => count($cancelled),
                'leaves_remaining' => max(0, $total - count($used) - count($cancelled) - 1),
                'book'             => $book,
            ];
        }
    }
    return null;
}

// ══════════════════════════════════════════════════════════════════
//  AJAX HANDLERS
// ══════════════════════════════════════════════════════════════════
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    // ── List dal entries with their lines ────────────────────────
    if ($action === 'list') {
        $tab       = $_GET['tab']       ?? 'customer';  // customer|employee
        $search    = mysqli_real_escape_string($conn, trim($_GET['search']    ?? ''));
        $date_from = mysqli_real_escape_string($conn, trim($_GET['date_from'] ?? ''));
        $date_to   = mysqli_real_escape_string($conn, trim($_GET['date_to']   ?? ''));

        $line_type = $tab === 'employee' ? 'employee' : 'customer';

        $where = ["l.line_type = '$line_type'"];
        if ($search)    $where[] = "(l.ref_name LIKE '%$search%' OR l.ref_code LIKE '%$search%' OR e.description LIKE '%$search%')";
        if ($date_from) $where[] = "e.email_date >= '$date_from'";
        if ($date_to)   $where[] = "e.email_date <= '$date_to'";

        $sql = "SELECT l.id AS line_id, l.entry_id, l.line_type, l.ref_id, l.ref_code, l.ref_name,
                       l.net_amount, l.vat_amount, l.total_amount,
                       e.email_date, e.description AS entry_desc, e.total_amount AS entry_total,
                       (SELECT COUNT(*) FROM ca_customer_claims cc
                        WHERE cc.dal_entry_id = l.entry_id AND cc.customer_id = l.ref_id) AS assigned_count
                FROM sscl_vat_email_lines l
                JOIN sscl_vat_email_entries e ON e.id = l.entry_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY e.email_date DESC, l.sort_order ASC, l.id ASC";

        $res  = mysqli_query($conn, $sql);
        $rows = [];
        if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
        echo json_encode(['success' => true, 'rows' => $rows]);
        exit;
    }

    // ── Get entry detail for assign modal ────────────────────────
    if ($action === 'get_entry_detail') {
        $entry_id = intval($_GET['entry_id'] ?? 0);
        $line_id  = intval($_GET['line_id']  ?? 0);

        $entry = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT * FROM sscl_vat_email_entries WHERE id=$entry_id"));
        $line = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT * FROM sscl_vat_email_lines WHERE id=$line_id"));

        // All lines in this entry (for employees: all of them)
        $all_lines = [];
        $lr = mysqli_query($conn,
            "SELECT * FROM sscl_vat_email_lines WHERE entry_id=$entry_id ORDER BY sort_order, id");
        if ($lr) while ($l = mysqli_fetch_assoc($lr)) $all_lines[] = $l;

        // Existing ack for this entry
        $existing_acks = [];
        $ar = mysqli_query($conn,
            "SELECT dca.*, GROUP_CONCAT(cc.cheque_no SEPARATOR ',') AS cheque_nos
             FROM dl_cheque_acknowledgments dca
             LEFT JOIN ca_customer_claims cc ON cc.ack_id = dca.id
             WHERE dca.dal_entry_id = $entry_id
             GROUP BY dca.id ORDER BY dca.id");
        if ($ar) while ($a = mysqli_fetch_assoc($ar)) $existing_acks[] = $a;

        echo json_encode([
            'success'       => true,
            'entry'         => $entry,
            'line'          => $line,
            'all_lines'     => $all_lines,
            'existing_acks' => $existing_acks,
        ]);
        exit;
    }

    // ── Get bank accounts ────────────────────────────────────────
    if ($action === 'get_bank_accounts') {
        $res  = mysqli_query($conn,
            "SELECT cba.id, cba.account_no, cba.account_name, b.bank_name
             FROM company_bank_accounts cba
             LEFT JOIN banks b ON cba.bank_code = b.bank_code
             WHERE cba.active = 1
             ORDER BY b.bank_name ASC");
        $rows = [];
        if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
        echo json_encode(['success' => true, 'data' => $rows]);
        exit;
    }

    // ── Get cheque books for a bank account ──────────────────────
    if ($action === 'get_cc_books') {
        $bank_id = intval($_GET['bank_account_id'] ?? 0);
        $where   = $bank_id ? "WHERE ccb.bank_account_id=$bank_id AND ccb.status='Active'" : "WHERE ccb.status='Active'";

        $res = mysqli_query($conn,
            "SELECT ccb.*,
                    COALESCE(NULLIF(b.bank_name,''), cba.bank_code, '') AS bank_name_label,
                    cba.account_no
             FROM customer_claim_cheque_books ccb
             LEFT JOIN company_bank_accounts cba ON cba.id = ccb.bank_account_id
             LEFT JOIN banks b ON b.bank_code = cba.bank_code
             $where ORDER BY ccb.id ASC");

        $books = [];
        if ($res) {
            while ($bk = mysqli_fetch_assoc($res)) {
                $next = get_next_available_cheque($conn, $bk['id']);
                $bk['next_cheque_no']   = $next ? $next['cheque_no']       : null;
                $bk['leaves_remaining'] = $next ? $next['leaves_remaining'] : 0;
                $bk['used_count']       = $next ? $next['used_count']       : intval($bk['leaf_count']);
                $bk['cancelled_count']  = $next ? $next['cancelled_count']  : 0;
                $bk['total_leaves']     = intval($bk['leaf_count']);
                $books[] = $bk;
            }
        }
        echo json_encode(['success' => true, 'data' => $books]);
        exit;
    }

    // ── Get next cheque in book ──────────────────────────────────
    if ($action === 'get_next_cheque') {
        $book_id = intval($_GET['book_id'] ?? 0);
        $result  = get_next_available_cheque($conn, $book_id);
        if ($result) echo json_encode(['success' => true, 'data' => $result]);
        else         echo json_encode(['success' => false, 'message' => 'No available leaves.']);
        exit;
    }

    // ── Save cheque assignment ───────────────────────────────────
    if ($action === 'save_assignment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $entry_id    = intval($_POST['entry_id']    ?? 0);
        $line_id     = intval($_POST['line_id']     ?? 0);
        $common_date = mysqli_real_escape_string($conn, trim($_POST['common_date'] ?? ''));
        $common_date_sql = $common_date ? "'$common_date'" : 'NULL';

        $assignments = json_decode($_POST['assignments'] ?? '[]', true);
        if (!is_array($assignments) || empty($assignments)) {
            echo json_encode(['success' => false, 'message' => 'No assignments provided.']); exit;
        }

        // Get entry + line info
        $entry = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM sscl_vat_email_entries WHERE id=$entry_id"));
        $line  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM sscl_vat_email_lines WHERE id=$line_id"));
        if (!$entry || !$line) { echo json_encode(['success' => false, 'message' => 'Entry or line not found.']); exit; }

        $by   = mysqli_real_escape_string($conn, $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'system');
        $desc = mysqli_real_escape_string($conn, $entry['description'] ?? '');
        $line_type = mysqli_real_escape_string($conn, $line['line_type'] ?? 'customer');

        $saved = 0;
        foreach ($assignments as $asgn) {
            $ref_id      = intval($asgn['ref_id']         ?? 0);
            $ref_code    = mysqli_real_escape_string($conn, trim($asgn['ref_code']      ?? ''));
            $ref_name    = mysqli_real_escape_string($conn, trim($asgn['ref_name']      ?? ''));
            $net_amount  = floatval($asgn['net_amount']   ?? 0);
            $vat_amount  = floatval($asgn['vat_amount']   ?? 0);
            $total_amount= floatval($asgn['total_amount'] ?? 0);
            $cheque_no   = mysqli_real_escape_string($conn, trim($asgn['cheque_no']     ?? ''));
            $cheque_book_id  = intval($asgn['cheque_book_id']  ?? 0);
            $bank_account_id = intval($asgn['bank_account_id'] ?? 0);
            $bank_account_no = mysqli_real_escape_string($conn, trim($asgn['bank_account_no'] ?? ''));
            $bank_name       = mysqli_real_escape_string($conn, trim($asgn['bank_name']       ?? ''));
            $cheque_date     = mysqli_real_escape_string($conn, trim($asgn['cheque_date']      ?? ''));
            $cheque_date_sql = $cheque_date ? "'$cheque_date'" : 'NULL';
            $email_date      = mysqli_real_escape_string($conn, $entry['email_date'] ?? '');
            $email_date_sql  = $email_date ? "'$email_date'" : 'NULL';
            $cb_sql = $cheque_book_id > 0 ? $cheque_book_id : 'NULL';

            // Insert into dl_cheque_acknowledgments
            mysqli_query($conn, "INSERT INTO dl_cheque_acknowledgments
                (dal_entry_id, claim_item_id, ref_id, line_type,
                 invoice_date, banking_date, entity,
                 claim_description, actual_amount, vat_amount, total_amount,
                 claim_type, customer_code, customer_name, common_date,
                 ack_sent_at, ack_sent_by)
                VALUES
                ($entry_id, 0, $ref_id, '$line_type',
                 $email_date_sql, $common_date_sql, 'DAL',
                 '$desc', $net_amount, $vat_amount, $total_amount,
                 'DAL', '$ref_code', '$ref_name', $common_date_sql,
                 NOW(), '$by')");
            $ack_id = mysqli_insert_id($conn);

            if ($ack_id) {
                mysqli_query($conn, "INSERT INTO ca_customer_claims
                    (ack_id, dal_entry_id, customer_id, customer_code, customer_name,
                     claim_amount, cheque_no, cheque_book_id, bank_account_id,
                     bank_account_no, bank_name, cheque_date)
                    VALUES
                    ($ack_id, $entry_id, $ref_id, '$ref_code', '$ref_name',
                     $total_amount, '$cheque_no', $cb_sql, $bank_account_id,
                     '$bank_account_no', '$bank_name', $cheque_date_sql)");
                $saved++;
            }
        }

        echo json_encode(['success' => true, 'saved' => $saved]);
        exit;
    }

    // ── Get existing acks for an entry (for the inner table) ──────
    if ($action === 'get_acks') {
        $entry_id = intval($_GET['entry_id'] ?? 0);
        $acks = [];
        $ar = mysqli_query($conn,
            "SELECT dca.*, cc.cheque_no, cc.bank_account_no, cc.bank_name, cc.cheque_date, cc.id AS cc_id
             FROM dl_cheque_acknowledgments dca
             LEFT JOIN ca_customer_claims cc ON cc.ack_id = dca.id
             WHERE dca.dal_entry_id = $entry_id
             ORDER BY dca.id ASC");
        if ($ar) while ($a = mysqli_fetch_assoc($ar)) $acks[] = $a;
        echo json_encode(['success' => true, 'acks' => $acks]);
        exit;
    }

    // ── Delete an ack ────────────────────────────────────────────
    if ($action === 'delete_ack' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = intval($_POST['id'] ?? 0);
        mysqli_query($conn, "DELETE FROM ca_customer_claims WHERE ack_id=$id");
        $ok = mysqli_query($conn, "DELETE FROM dl_cheque_acknowledgments WHERE id=$id");
        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

// ══════════════════════════════════════════════════════════════════
//  PAGE LOAD
// ══════════════════════════════════════════════════════════════════
include 'header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
*{box-sizing:border-box}
body{font-family:'Inter',system-ui,sans-serif}

/* ── Page header ── */
.page-hdr{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:22px}
.page-title{font-size:24px;font-weight:800;color:#0f172a;margin:0;display:flex;align-items:center;gap:10px}
.page-title i{color:#7c3aed}
.page-sub{font-size:13px;color:#64748b;margin:3px 0 0}

/* ── Tabs ── */
.tabs{display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:20px}
.tab-btn{padding:11px 26px;font-size:13px;font-weight:700;color:#64748b;background:none;border:none;cursor:pointer;font-family:inherit;border-bottom:3px solid transparent;margin-bottom:-2px;transition:all .18s;display:flex;align-items:center;gap:7px}
.tab-btn.active{color:#7c3aed;border-bottom-color:#7c3aed}
.tab-btn:hover:not(.active){color:#1e293b}
.tab-badge{background:#f3f4f6;color:#374151;font-size:10px;font-weight:800;padding:1px 7px;border-radius:10px}
.tab-btn.active .tab-badge{background:#ede9fe;color:#6d28d9}

/* ── Filter bar ── */
.fbar{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 18px;margin-bottom:18px}
.fbar-fg{display:flex;flex-direction:column;gap:4px}
.fbar-lbl{font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px}
.fbar-inp{padding:8px 12px;border:1px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:inherit;outline:none;height:36px;background:#fff}
.fbar-inp:focus{border-color:#7c3aed}

/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;white-space:nowrap;transition:all .18s;text-decoration:none}
.btn-sm{padding:7px 14px;font-size:12px}
.btn-xs{padding:4px 10px;font-size:11px;border-radius:6px}
.btn-primary{background:#7c3aed;color:#fff}.btn-primary:hover{background:#6d28d9}
.btn-light{background:#f8fafc;color:#374151;border:1px solid #e2e8f0}.btn-light:hover{background:#f1f5f9}
.btn-danger{background:#fef2f2;color:#dc2626;border:1px solid #fca5a5}.btn-danger:hover{background:#dc2626;color:#fff}
.btn-success{background:#f0fdf4;color:#16a34a;border:1px solid #86efac}.btn-success:hover{background:#16a34a;color:#fff}
.btn-violet{background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe}.btn-violet:hover{background:#7c3aed;color:#fff}
.btn-print{background:#1e3a5f;color:#fff}.btn-print:hover{background:#0f2540}
.btn-amber{background:#fffbeb;color:#d97706;border:1px solid #fde68a}.btn-amber:hover{background:#d97706;color:#fff}

/* ── Table ── */
.tbl-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden}
.tbl-hdr{display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-bottom:1px solid #f1f5f9;flex-wrap:wrap;gap:10px}
.tbl-title{font-size:14px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:8px}
.count-badge{background:#f1f5f9;color:#475569;font-size:11px;font-weight:700;padding:2px 9px;border-radius:10px}
.srch-wrap{position:relative}
.srch-wrap i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:12px;pointer-events:none}
.srch-inp{width:220px;padding:7px 12px 7px 30px;border:1px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:inherit;outline:none}
.srch-inp:focus{border-color:#7c3aed}

.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table thead th{background:#f8fafc;padding:10px 13px;text-align:left;font-weight:700;font-size:11px;color:#475569;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;border-bottom:2px solid #e2e8f0}
.data-table th.num,.data-table td.num{text-align:right}
.data-table tbody tr{border-bottom:1px solid #f3f4f6;transition:background .1s}
.data-table tbody tr:hover{background:#fafafa}
.data-table td{padding:10px 13px;color:#1e293b;vertical-align:middle;white-space:nowrap}
.empty-state{text-align:center;padding:50px 20px;color:#94a3b8}
.empty-state i{font-size:36px;display:block;margin-bottom:12px;color:#cbd5e1}

.tag{display:inline-flex;align-items:center;gap:3px;padding:2px 9px;border-radius:10px;font-size:10px;font-weight:700}
.tag-violet{background:#ede9fe;color:#6d28d9}
.tag-blue{background:#dbeafe;color:#1e40af}
.tag-amber{background:#fef3c7;color:#92400e}
.tag-green{background:#dcfce7;color:#166534}
.tag-gray{background:#f3f4f6;color:#374151}

.action-btns{display:flex;gap:5px;align-items:center}
.abtn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:6px;border:1px solid #e2e8f0;background:#fff;color:#6b7280;cursor:pointer;font-size:12px;transition:all .18s}
.abtn:hover{transform:translateY(-1px);box-shadow:0 2px 4px rgba(0,0,0,.08)}
.abtn-assign:hover{background:#7c3aed;color:#fff;border-color:#7c3aed}
.abtn-view:hover{background:#3b82f6;color:#fff;border-color:#3b82f6}
.abtn-del:hover{background:#ef4444;color:#fff;border-color:#ef4444}
.abtn-print:hover{background:#1e3a5f;color:#fff;border-color:#1e3a5f}

/* inner acks table */
.acks-wrap{background:#fafbff;border-top:1px solid #e2e8f0;padding:12px 16px}
.acks-tbl{width:100%;border-collapse:collapse;font-size:12px;margin-top:8px}
.acks-tbl thead th{background:#ede9fe;padding:7px 10px;font-size:10px;font-weight:700;color:#5b21b6;text-transform:uppercase;letter-spacing:.3px;border-bottom:1px solid #ddd6fe}
.acks-tbl th.r,.acks-tbl td.r{text-align:right}
.acks-tbl td{padding:7px 10px;border-bottom:1px solid #f3f4f6;vertical-align:middle}
.acks-tbl tr:last-child td{border-bottom:none}
.ack-cheque{font-family:monospace;font-weight:800;color:#7c3aed;font-size:13px}
.acks-empty{text-align:center;padding:14px;color:#94a3b8;font-size:12px;font-style:italic}

/* ── Assign Modal ── */
.mo{position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:9000;display:none;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto;backdrop-filter:blur(2px)}
.mo.open{display:flex;animation:mFade .2s ease}
@keyframes mFade{from{opacity:0}to{opacity:1}}
.mo-box{background:#fff;border-radius:16px;width:100%;max-width:980px;box-shadow:0 24px 80px rgba(0,0,0,.25);animation:mSlide .25s ease;margin:auto;display:flex;flex-direction:column;max-height:calc(100vh - 40px);overflow:hidden}
@keyframes mSlide{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.mo-hdr{background:linear-gradient(135deg,#4c1d95,#6d28d9,#7c3aed);padding:18px 24px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.mo-title{color:#fff;font-size:16px;font-weight:800;display:flex;align-items:center;gap:10px}
.mo-close{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff;border-radius:8px;padding:7px 16px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit}
.mo-close:hover{background:rgba(255,255,255,.25)}
.mo-body{padding:22px 26px;overflow-y:auto;flex:1}
.mo-footer{padding:14px 26px;border-top:1px solid #f1f5f9;display:flex;justify-content:flex-end;gap:10px;background:#fafafa;flex-shrink:0}

/* info strip */
.info-strip{display:flex;flex-wrap:wrap;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;margin-bottom:20px}
.info-cell{padding:12px 20px;border-right:1px solid #e2e8f0;display:flex;flex-direction:column;gap:2px;min-width:120px}
.info-cell:last-child{border-right:none}
.info-lbl{font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;font-weight:700}
.info-val{font-size:14px;font-weight:800;color:#0f172a}
.info-val.violet{color:#7c3aed}.info-val.green{color:#16a34a}.info-val.blue{color:#0369a1}

/* bank/book section */
.sec-hdr{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:800;color:#374151;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid #e2e8f0}
.form-row2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}
.fg{display:flex;flex-direction:column;gap:5px;margin-bottom:16px}
.fl{font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px}
.fi{padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;font-family:inherit;outline:none;width:100%;background:#fff;transition:border-color .18s}
.fi:focus{border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.1)}

/* book cards */
.book-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px;margin-bottom:18px}
.book-card{border:2px solid #e5e7eb;border-radius:10px;padding:12px 14px;cursor:pointer;transition:all .2s;background:#fff;position:relative}
.book-card:hover{border-color:#7c3aed;background:#faf5ff}
.book-card.selected{border-color:#7c3aed;background:#f5f3ff}
.book-card.exhausted{opacity:.5;cursor:not-allowed}
.book-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px}
.book-name{font-size:13px;font-weight:700;color:#111}
.book-next{font-family:monospace;font-size:12px;font-weight:800;color:#7c3aed;background:#ede9fe;padding:2px 8px;border-radius:5px}
.book-bank{font-size:11px;color:#6b7280;margin-bottom:6px}
.leaf-bar{height:5px;background:#e5e7eb;border-radius:3px;overflow:hidden;margin:4px 0}
.leaf-fill{height:100%;border-radius:3px}
.leaf-stats{display:flex;gap:10px;font-size:10.5px;color:#6b7280}
.leaf-stat-g{color:#16a34a;font-weight:700}
.leaf-stat-a{color:#d97706;font-weight:700}
.leaf-stat-r{color:#dc2626;font-weight:700}

/* assignment rows table */
.asgn-tbl-wrap{border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;margin-bottom:14px}
.asgn-tbl{width:100%;border-collapse:collapse;font-size:13px}
.asgn-tbl thead th{background:#f5f3ff;padding:9px 12px;text-align:left;font-size:11px;font-weight:700;color:#5b21b6;text-transform:uppercase;letter-spacing:.4px;border-bottom:2px solid #ddd6fe;white-space:nowrap}
.asgn-tbl th.r,.asgn-tbl td.r{text-align:right}
.asgn-tbl tbody tr{border-bottom:1px solid #f3f4f6}
.asgn-tbl tbody tr:last-child{border-bottom:none}
.asgn-tbl td{padding:9px 12px;vertical-align:middle}
.cheque-inp{font-family:monospace;font-size:13px;font-weight:700;padding:7px 10px;border:1px solid #ddd6fe;border-radius:7px;background:#f5f3ff;color:#7c3aed;width:160px;outline:none}
.cheque-inp:focus{border-color:#7c3aed;box-shadow:0 0 0 2px rgba(124,58,237,.1)}
.date-inp{padding:7px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:13px;font-family:inherit;outline:none;width:145px}
.date-inp:focus{border-color:#7c3aed}
.emp-badge{display:inline-flex;align-items:center;gap:4px;background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700}
.cust-badge{display:inline-flex;align-items:center;gap:4px;background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700}

/* Select2 */
.select2-container{width:100% !important}
.select2-container--default .select2-selection--single{height:42px;border:1px solid #d1d5db;border-radius:8px;display:flex;align-items:center;padding:0 12px}
.select2-container--default.select2-container--focus .select2-selection--single,
.select2-container--default.select2-container--open .select2-selection--single{border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.1)}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:40px;font-size:14px;font-family:inherit;color:#333;padding:0}
.select2-container--default .select2-selection--single .select2-selection__placeholder{color:#9ca3af}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:40px;right:10px}
.select2-dropdown{border:1px solid #d1d5db;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.1);font-size:13px;font-family:inherit;z-index:99999 !important}
.select2-container--default .select2-search--dropdown .select2-search__field{border:1px solid #d1d5db;border-radius:6px;padding:8px 12px;font-size:13px;font-family:inherit;outline:none}
.select2-container--default .select2-results__option{padding:9px 14px}
.select2-container--default .select2-results__option--highlighted[aria-selected]{background:#7c3aed;color:#fff}

/* Toast */
#toast{position:fixed;bottom:24px;right:24px;padding:12px 22px;border-radius:10px;font-size:14px;font-weight:600;color:#fff;z-index:99999;display:none;box-shadow:0 4px 20px rgba(0,0,0,.18)}
#toast.success{background:#16a34a}#toast.error{background:#dc2626}
#toast.warn{background:#d97706}#toast.info{background:#7c3aed}

/* ── Print styles ── */
@media print {
    body * { visibility: hidden !important; }
    #printArea, #printArea * { visibility: visible !important; }
    #printArea { position: fixed !important; inset: 0 !important; background: #fff !important; padding: 30px !important; z-index: 999999 !important; }
    .no-print { display: none !important; }
}
#printArea { display: none; }

@media(max-width:680px){.form-row2,.form-row3{grid-template-columns:1fr}}
</style>

<!-- ══ PAGE ══════════════════════════════════════════════════════ -->
<div class="page-hdr">
    <div>
        <h2 class="page-title"><i class="fa-solid fa-money-check-dollar"></i> DAL Cheque Acknowledgment</h2>
        <p class="page-sub">Assign cheques to DAL entry lines — Customer and Employee cheque acknowledgments</p>
    </div>
</div>

<!-- TABS -->
<div class="tabs">
    <button class="tab-btn active" id="tab-customer" onclick="switchTab('customer')">
        <i class="fa-solid fa-user"></i> Customers
        <span class="tab-badge" id="badge-customer">0</span>
    </button>
    <button class="tab-btn" id="tab-employee" onclick="switchTab('employee')">
        <i class="fa-solid fa-id-badge"></i> Employees
        <span class="tab-badge" id="badge-employee">0</span>
    </button>
</div>

<!-- FILTER -->
<div class="fbar">
    <div class="fbar-fg">
        <div class="fbar-lbl">Date From</div>
        <input type="date" class="fbar-inp" id="f_date_from" style="width:145px">
    </div>
    <div class="fbar-fg">
        <div class="fbar-lbl">To</div>
        <input type="date" class="fbar-inp" id="f_date_to" style="width:145px">
    </div>
    <button class="btn btn-primary btn-sm" onclick="loadTable()"><i class="fa-solid fa-filter"></i> Filter</button>
    <button class="btn btn-light btn-sm" onclick="clearFilter()"><i class="fa-solid fa-xmark"></i> Clear</button>
</div>

<!-- TABLE -->
<div class="tbl-card">
    <div class="tbl-hdr">
        <div class="tbl-title">
            <i class="fa-solid fa-table"></i>
            <span id="tableTitle">Customer Lines</span>
            <span class="count-badge" id="rowCountBadge">0</span>
        </div>
        <div class="srch-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" class="srch-inp" id="tblSearch" placeholder="Search name, code…" oninput="loadTable()">
        </div>
    </div>
    <div style="overflow-x:auto">
        <table class="data-table" id="mainTable">
            <thead>
                <tr>
                    <th style="width:40px">#</th>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Entry Date</th>
                    <th>Description</th>
                    <th class="num">Net</th>
                    <th class="num">VAT</th>
                    <th class="num">Total</th>
                    <th style="text-align:center">Assigned</th>
                    <th style="text-align:center">Actions</th>
                </tr>
            </thead>
            <tbody id="mainTbody">
                <tr><td colspan="10" class="empty-state"><i class="fa-solid fa-spinner fa-spin"></i><p>Loading…</p></td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- ══ ASSIGN MODAL ═════════════════════════════════════════════ -->
<div class="mo" id="assignModal">
<div class="mo-box">
    <div class="mo-hdr">
        <div class="mo-title"><i class="fa-solid fa-money-check-dollar"></i> <span id="am_title">Assign Cheques</span></div>
        <button class="mo-close" onclick="closeAssignModal()"><i class="fa-solid fa-xmark"></i> Close</button>
    </div>
    <div class="mo-body">

        <!-- Entry info strip -->
        <div class="info-strip">
            <div class="info-cell"><div class="info-lbl">Entry Date</div><div class="info-val" id="am_date">—</div></div>
            <div class="info-cell"><div class="info-lbl">Description</div><div class="info-val" id="am_desc">—</div></div>
            <div class="info-cell"><div class="info-lbl">Line Type</div><div class="info-val" id="am_type">—</div></div>
            <div class="info-cell"><div class="info-lbl">Net</div><div class="info-val blue" id="am_net">0.00</div></div>
            <div class="info-cell"><div class="info-lbl">VAT</div><div class="info-val violet" id="am_vat">0.00</div></div>
            <div class="info-cell"><div class="info-lbl">Total</div><div class="info-val green" id="am_total">0.00</div></div>
        </div>

        <!-- Bank + Common Date -->
        <div class="sec-hdr"><i class="fa-solid fa-building-columns"></i> Bank Account &amp; Common Date</div>
        <div class="form-row2" style="margin-bottom:18px">
            <div class="fg">
                <div class="fl">Bank Account</div>
                <select id="am_bank" onchange="onBankChange()"><option value="">-- Select Bank Account --</option></select>
            </div>
            <div class="fg">
                <div class="fl">Common Date <span style="font-size:10px;color:#9ca3af;font-weight:400">(applied to all)</span></div>
                <input type="date" class="fi" id="am_common_date" onchange="applyCommonDate()">
            </div>
        </div>

        <!-- Cheque Books -->
        <div class="sec-hdr"><i class="fa-solid fa-book"></i> Cheque Book</div>
        <div id="am_book_loading" style="display:none;padding:12px;color:#94a3b8;font-size:13px"><i class="fa-solid fa-spinner fa-spin"></i> Loading cheque books…</div>
        <div id="am_book_empty"  style="display:none;padding:12px;color:#94a3b8;font-size:13px;font-style:italic">No active cheque books for selected bank account.</div>
        <div class="book-grid" id="am_book_grid"></div>

        <!-- Assignments table -->
        <div class="sec-hdr" style="margin-top:4px"><i class="fa-solid fa-list-check"></i> Cheque Assignments</div>
        <div class="asgn-tbl-wrap">
            <table class="asgn-tbl">
                <thead>
                    <tr>
                        <th style="width:36px">#</th>
                        <th>Name</th>
                        <th style="width:90px;text-align:center">Type</th>
                        <th class="r" style="width:110px">Amount</th>
                        <th style="width:175px">Cheque No</th>
                        <th style="width:155px">Cheque Date</th>
                    </tr>
                </thead>
                <tbody id="am_asgn_tbody"></tbody>
            </table>
        </div>

        <div id="am_no_book_note" style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;font-size:12px;color:#92400e;margin-bottom:14px;display:none">
            <i class="fa-solid fa-triangle-exclamation"></i> Select a cheque book above to auto-fill cheque numbers.
        </div>

    </div>
    <div class="mo-footer">
        <button class="btn btn-light" onclick="closeAssignModal()">Cancel</button>
        <button class="btn btn-print" onclick="printAck()"><i class="fa-solid fa-print"></i> Print Acknowledgment</button>
        <button class="btn btn-primary" id="saveAsgBtn" onclick="saveAssignment()"><i class="fa-solid fa-floppy-disk"></i> Save Assignment</button>
    </div>
</div>
</div>

<!-- ══ PRINT AREA ═══════════════════════════════════════════════ -->
<div id="printArea"></div>

<!-- ══ VIEW ACKS MODAL ══════════════════════════════════════════ -->
<div class="mo" id="viewAcksModal">
<div class="mo-box" style="max-width:760px">
    <div class="mo-hdr">
        <div class="mo-title"><i class="fa-solid fa-eye"></i> Assigned Cheques</div>
        <button class="mo-close" onclick="closeViewModal()"><i class="fa-solid fa-xmark"></i> Close</button>
    </div>
    <div class="mo-body" id="viewAcksBody"></div>
</div>
</div>

<div id="toast"></div>

<script>
let _currentTab  = 'customer';
let _allRows     = [];
let _currentEntry = null;
let _currentLine  = null;
let _allLines     = [];
let _selectedBookId = 0;
let _selectedBookData = null;
let _asgnRows = [];    // [{ref_id, ref_code, ref_name, net, vat, total, cheque_no, cheque_date, line_type}]
let _bankData = [];

const fN = v => parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
const eh = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
function $id(id){ return document.getElementById(id); }

function showToast(msg, type='info') {
    const t = $id('toast');
    t.className = type; t.textContent = msg; t.style.display = 'block';
    clearTimeout(t._t); t._t = setTimeout(()=>t.style.display='none', 3200);
}

// ══ TAB ═══════════════════════════════════════════════════════
function switchTab(tab) {
    _currentTab = tab;
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    $id('tab-'+tab).classList.add('active');
    $id('tableTitle').textContent = tab === 'customer' ? 'Customer Lines' : 'Employee Lines';
    loadTable();
}

// ══ LOAD TABLE ════════════════════════════════════════════════
async function loadTable() {
    const tbody = $id('mainTbody');
    tbody.innerHTML = `<tr><td colspan="10" class="empty-state"><i class="fa-solid fa-spinner fa-spin"></i><p>Loading…</p></td></tr>`;

    const params = new URLSearchParams({
        action    : 'list',
        tab       : _currentTab,
        search    : $id('tblSearch').value || '',
        date_from : $id('f_date_from').value || '',
        date_to   : $id('f_date_to').value || '',
    });

    try {
        const res = await fetch('dal_cheque_ack.php?' + params);
        const d   = await res.json();
        if (!d.success) throw new Error(d.message || 'Failed');

        _allRows = d.rows || [];
        $id('rowCountBadge').textContent = _allRows.length;
        $id('badge-'+_currentTab).textContent = _allRows.length;

        if (!_allRows.length) {
            tbody.innerHTML = `<tr><td colspan="10"><div class="empty-state"><i class="fa-solid fa-inbox"></i><p>No ${_currentTab} lines found.</p></div></td></tr>`;
            return;
        }

        tbody.innerHTML = _allRows.map((r, i) => {
            const typePill = r.line_type === 'employee'
                ? `<span class="emp-badge"><i class="fa-solid fa-id-badge"></i> Employee</span>`
                : `<span class="cust-badge"><i class="fa-solid fa-user"></i> Customer</span>`;
            const assigned = parseInt(r.assigned_count||0);
            const asgBadge = assigned > 0
                ? `<span class="tag tag-green"><i class="fa-solid fa-check"></i> ${assigned}</span>`
                : `<span class="tag tag-gray">—</span>`;

            return `<tr>
                <td style="color:#94a3b8;font-size:11px">${i+1}</td>
                <td style="font-family:monospace;font-weight:700;color:#374151">${eh(r.ref_code)}</td>
                <td style="font-weight:600">${eh(r.ref_name)}</td>
                <td><span style="font-size:12px;color:#374151">${r.email_date||'—'}</span></td>
                <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;color:#64748b;font-size:12px" title="${eh(r.entry_desc)}">${eh((r.entry_desc||'').slice(0,50))}</td>
                <td class="num" style="color:#0369a1;font-weight:700">${fN(r.net_amount)}</td>
                <td class="num" style="color:#7c3aed;font-weight:700">${fN(r.vat_amount)}</td>
                <td class="num" style="color:#16a34a;font-weight:800">${fN(r.total_amount)}</td>
                <td style="text-align:center">${asgBadge}</td>
                <td>
                    <div class="action-btns" style="justify-content:center">
                        <button class="abtn abtn-assign" title="Assign Cheques" onclick="openAssign(${r.entry_id},${r.line_id})"><i class="fa-solid fa-money-check-dollar"></i></button>
                        <button class="abtn abtn-view" title="View Assigned Cheques" onclick="openViewAcks(${r.entry_id})"><i class="fa-solid fa-eye"></i></button>
                    </div>
                </td>
            </tr>`;
        }).join('');

    } catch(e) {
        tbody.innerHTML = `<tr><td colspan="10" class="empty-state"><i class="fa-solid fa-circle-exclamation"></i><p>Error: ${eh(e.message)}</p></td></tr>`;
    }
}

function clearFilter() {
    $id('f_date_from').value = '';
    $id('f_date_to').value   = '';
    $id('tblSearch').value   = '';
    loadTable();
}

// ══ OPEN ASSIGN MODAL ═════════════════════════════════════════
async function openAssign(entryId, lineId) {
    // Reset
    _currentEntry = null; _currentLine = null; _allLines = [];
    _selectedBookId = 0; _selectedBookData = null; _asgnRows = [];
    $id('am_book_grid').innerHTML = '';
    $id('am_book_loading').style.display = 'none';
    $id('am_book_empty').style.display   = 'none';
    $id('am_no_book_note').style.display = 'none';
    $id('am_bank').value = '';
    $id('am_common_date').value = '';
    $id('am_asgn_tbody').innerHTML = '';
    $id('assignModal').classList.add('open');

    try {
        const res = await fetch(`dal_cheque_ack.php?action=get_entry_detail&entry_id=${entryId}&line_id=${lineId}`);
        const d   = await res.json();
        if (!d.success) throw new Error('Load failed');

        _currentEntry = d.entry;
        _currentLine  = d.line;
        _allLines     = d.all_lines || [];

        // Fill info strip
        const isEmp = _currentLine.line_type === 'employee';
        $id('am_title').textContent = `Assign Cheques — ${eh(_currentLine.ref_name)}`;
        $id('am_date').textContent  = _currentEntry.email_date || '—';
        $id('am_desc').textContent  = _currentEntry.description || '—';
        $id('am_type').innerHTML    = isEmp
            ? `<span class="emp-badge"><i class="fa-solid fa-id-badge"></i> Employee</span>`
            : `<span class="cust-badge"><i class="fa-solid fa-user"></i> Customer</span>`;
        $id('am_net').textContent   = fN(_currentLine.net_amount);
        $id('am_vat').textContent   = fN(_currentLine.vat_amount);
        $id('am_total').textContent = fN(_currentLine.total_amount);

        // Build assignment rows
        // For employee: all lines of that entry; for customer: just this line
        if (isEmp) {
            _asgnRows = _allLines.map(l => ({
                ref_id   : l.ref_id,
                ref_code : l.ref_code,
                ref_name : l.ref_name,
                net      : parseFloat(l.net_amount),
                vat      : parseFloat(l.vat_amount),
                total    : parseFloat(l.total_amount),
                line_type: l.line_type,
                cheque_no  : '',
                cheque_date: '',
            }));
        } else {
            _asgnRows = [{
                ref_id   : _currentLine.ref_id,
                ref_code : _currentLine.ref_code,
                ref_name : _currentLine.ref_name,
                net      : parseFloat(_currentLine.net_amount),
                vat      : parseFloat(_currentLine.vat_amount),
                total    : parseFloat(_currentLine.total_amount),
                line_type: _currentLine.line_type,
                cheque_no  : '',
                cheque_date: '',
            }];
        }

        renderAsgnTable();
        await loadBankAccounts();

    } catch(e) {
        showToast('Failed to load entry: '+e.message, 'error');
        closeAssignModal();
    }
}

function closeAssignModal() { $id('assignModal').classList.remove('open'); }

// ══ RENDER ASSIGNMENT TABLE ═══════════════════════════════════
function renderAsgnTable() {
    const tbody = $id('am_asgn_tbody');
    if (!_asgnRows.length) { tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:14px;color:#94a3b8">No lines.</td></tr>'; return; }

    tbody.innerHTML = _asgnRows.map((r, i) => {
        const pill = r.line_type === 'employee'
            ? `<span class="emp-badge"><i class="fa-solid fa-id-badge"></i> Emp</span>`
            : `<span class="cust-badge"><i class="fa-solid fa-user"></i> Cust</span>`;
        return `<tr>
            <td style="color:#94a3b8;font-size:11px">${i+1}</td>
            <td>
                <div style="font-weight:700;font-size:13px">${eh(r.ref_name)}</div>
                <div style="font-size:11px;color:#94a3b8;font-family:monospace">${eh(r.ref_code)}</div>
            </td>
            <td style="text-align:center">${pill}</td>
            <td class="r" style="font-weight:800;color:#16a34a">${fN(r.total)}</td>
            <td>
                <input type="text" class="cheque-inp" id="cheque_inp_${i}"
                    value="${eh(r.cheque_no)}" placeholder="Cheque No"
                    oninput="_asgnRows[${i}].cheque_no=this.value">
            </td>
            <td>
                <input type="date" class="date-inp" id="cheque_date_${i}"
                    value="${r.cheque_date}"
                    oninput="_asgnRows[${i}].cheque_date=this.value">
            </td>
        </tr>`;
    }).join('');
}

// ══ COMMON DATE ═══════════════════════════════════════════════
function applyCommonDate() {
    const d = $id('am_common_date').value;
    _asgnRows.forEach((r, i) => {
        r.cheque_date = d;
        const inp = $id('cheque_date_'+i);
        if (inp) inp.value = d;
    });
}

// ══ BANK ACCOUNTS ═════════════════════════════════════════════
async function loadBankAccounts() {
    const sel = $id('am_bank');
    sel.innerHTML = '<option value="">Loading…</option>';
    try {
        const res = await fetch('dal_cheque_ack.php?action=get_bank_accounts');
        const d   = await res.json();
        _bankData = d.data || [];
        sel.innerHTML = '<option value="">-- Select Bank Account --</option>';
        _bankData.forEach(b => {
            const o = document.createElement('option');
            o.value       = b.id;
            o.textContent = `${b.bank_name} — ${b.account_no}`;
            o.dataset.accountNo = b.account_no;
            o.dataset.bankName  = b.bank_name;
            sel.appendChild(o);
        });
    } catch(e) {
        sel.innerHTML = '<option value="">Error loading banks</option>';
    }
}

async function onBankChange() {
    const bankId = $id('am_bank').value;
    _selectedBookId   = 0;
    _selectedBookData = null;
    $id('am_book_grid').innerHTML     = '';
    $id('am_no_book_note').style.display = 'block';

    if (!bankId) { $id('am_book_loading').style.display='none'; $id('am_book_empty').style.display='none'; return; }

    $id('am_book_loading').style.display = 'block';
    $id('am_book_empty').style.display   = 'none';

    try {
        const res = await fetch(`dal_cheque_ack.php?action=get_cc_books&bank_account_id=${bankId}`);
        const d   = await res.json();
        $id('am_book_loading').style.display = 'none';

        const books = d.data || [];
        if (!books.length) { $id('am_book_empty').style.display = 'block'; return; }

        renderBookGrid(books);
    } catch(e) {
        $id('am_book_loading').style.display = 'none';
        showToast('Failed to load cheque books.', 'error');
    }
}

function renderBookGrid(books) {
    const grid = $id('am_book_grid');
    grid.innerHTML = books.map(b => {
        const total   = parseInt(b.total_leaves || b.leaf_count || 0);
        const used    = parseInt(b.used_count   || 0);
        const canc    = parseInt(b.cancelled_count || 0);
        const remain  = parseInt(b.leaves_remaining ?? (total - used - canc));
        const pct     = total > 0 ? Math.round((used+canc)/total*100) : 0;
        const fillCol = pct >= 90 ? '#dc2626' : pct >= 70 ? '#d97706' : '#16a34a';
        const exhausted = remain <= 0;
        return `<div class="book-card${exhausted?' exhausted':''}" id="book-card-${b.id}" onclick="${exhausted?'':('selectBook('+b.id+',this)')}">
            <div class="book-top">
                <div class="book-name">${eh(b.book_name||b.leaf_no_start+' — '+b.leaf_no_end)}</div>
                ${b.next_cheque_no ? `<div class="book-next">${eh(b.next_cheque_no)}</div>` : '<div style="font-size:11px;color:#dc2626;font-weight:700">Exhausted</div>'}
            </div>
            <div class="book-bank">${eh(b.bank_name_label||'')} ${b.account_no ? '· '+eh(b.account_no) : ''}</div>
            <div class="leaf-bar"><div class="leaf-fill" style="width:${pct}%;background:${fillCol}"></div></div>
            <div class="leaf-stats">
                <span class="leaf-stat-g"><i class="fa-solid fa-circle-check"></i> ${remain} left</span>
                <span class="leaf-stat-a"><i class="fa-solid fa-check-double"></i> ${used} used</span>
                ${canc>0?`<span class="leaf-stat-r"><i class="fa-solid fa-ban"></i> ${canc} cancelled</span>`:''}
            </div>
        </div>`;
    }).join('');

    // store books data
    grid._booksData = books;
}

async function selectBook(bookId, card) {
    document.querySelectorAll('.book-card').forEach(c => c.classList.remove('selected'));
    card.classList.add('selected');
    _selectedBookId   = bookId;
    _selectedBookData = ($id('am_book_grid')._booksData||[]).find(b=>b.id==bookId)||null;
    $id('am_no_book_note').style.display = 'none';

    // auto-fill cheque numbers sequentially from next available
    await autoFillCheques(bookId);
}

async function autoFillCheques(bookId) {
    // We need to assign sequential cheques to each row
    // Get current next cheque, then simulate incrementing
    try {
        const res = await fetch(`dal_cheque_ack.php?action=get_next_cheque&book_id=${bookId}`);
        const d   = await res.json();
        if (!d.success || !d.data) { showToast('No available cheques in this book.','error'); return; }

        const nextData  = d.data;
        const book      = nextData.book;
        const startNum  = parseInt(nextData.leaf_number);
        const endNum    = parseInt(String(book.leaf_no_end).replace(/\D/g,''));
        const prefix    = String(book.leaf_no_start).match(/^([A-Za-z\-]*)/)[1] || '';
        const padWidth  = String(book.leaf_no_start).replace(/^[A-Za-z\-]*/,'').length;

        let counter = startNum;
        _asgnRows.forEach((r, i) => {
            if (counter <= endNum) {
                r.cheque_no = prefix + String(counter).padStart(padWidth,'0');
                counter++;
            }
            const inp = $id('cheque_inp_'+i);
            if (inp) inp.value = r.cheque_no;
        });

        showToast(`Cheque numbers auto-filled from ${nextData.cheque_no}.`, 'success');
    } catch(e) {
        showToast('Failed to auto-fill cheques.','error');
    }
}

// ══ SAVE ASSIGNMENT ═══════════════════════════════════════════
async function saveAssignment() {
    if (!_currentEntry || !_currentLine) return;

    // Validate
    for (let i=0; i<_asgnRows.length; i++) {
        if (!_asgnRows[i].cheque_no.trim()) {
            showToast(`Row ${i+1}: Please enter a cheque number.`, 'error'); return;
        }
    }

    const bankSel = $id('am_bank');
    const bankId  = bankSel.value;
    if (!bankId) { showToast('Please select a bank account.','error'); return; }
    const bankOpt = bankSel.options[bankSel.selectedIndex];

    const assignments = _asgnRows.map(r => ({
        ref_id        : r.ref_id,
        ref_code      : r.ref_code,
        ref_name      : r.ref_name,
        net_amount    : r.net,
        vat_amount    : r.vat,
        total_amount  : r.total,
        cheque_no     : r.cheque_no,
        cheque_book_id: _selectedBookId||'',
        bank_account_id: bankId,
        bank_account_no: bankOpt.dataset.accountNo||'',
        bank_name      : bankOpt.dataset.bankName||'',
        cheque_date    : r.cheque_date||'',
    }));

    const btn = $id('saveAsgBtn');
    btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData();
    fd.append('entry_id',     _currentEntry.id);
    fd.append('line_id',      _currentLine.id);
    fd.append('common_date',  $id('am_common_date').value||'');
    fd.append('assignments',  JSON.stringify(assignments));

    try {
        const res = await fetch('dal_cheque_ack.php?action=save_assignment',{method:'POST',body:fd});
        const d   = await res.json();
        if (!d.success) throw new Error(d.message||'Save failed');
        showToast(`${d.saved} cheque assignment(s) saved!`,'success');
        closeAssignModal();
        loadTable();
    } catch(e) {
        showToast('Error: '+e.message,'error');
    } finally {
        btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Assignment';
    }
}

// ══ VIEW ACKS ════════════════════════════════════════════════
async function openViewAcks(entryId) {
    $id('viewAcksBody').innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
    $id('viewAcksModal').classList.add('open');

    try {
        const res = await fetch(`dal_cheque_ack.php?action=get_acks&entry_id=${entryId}`);
        const d   = await res.json();
        const acks = d.acks||[];

        if (!acks.length) {
            $id('viewAcksBody').innerHTML = '<div class="acks-empty">No cheques assigned to this entry yet.</div>'; return;
        }

        $id('viewAcksBody').innerHTML = `
        <table class="asgn-tbl" style="width:100%">
            <thead><tr>
                <th>#</th>
                <th>Name</th>
                <th style="text-align:center">Type</th>
                <th class="r">Amount</th>
                <th>Cheque No</th>
                <th>Bank</th>
                <th>Cheque Date</th>
                <th>Common Date</th>
                <th style="text-align:center">Del</th>
            </tr></thead>
            <tbody>
            ${acks.map((a,i) => `<tr>
                <td style="color:#94a3b8">${i+1}</td>
                <td><div style="font-weight:700">${eh(a.customer_name)}</div><div style="font-size:11px;font-family:monospace;color:#94a3b8">${eh(a.customer_code)}</div></td>
                <td style="text-align:center"><span class="${a.line_type==='employee'?'emp-badge':'cust-badge'}">${a.line_type==='employee'?'<i class="fa-solid fa-id-badge"></i> Emp':'<i class="fa-solid fa-user"></i> Cust'}</span></td>
                <td class="r" style="font-weight:800;color:#16a34a">${fN(a.total_amount)}</td>
                <td class="ack-cheque">${eh(a.cheque_no||'—')}</td>
                <td style="font-size:12px">${eh(a.bank_name||'—')}</td>
                <td style="font-size:12px">${a.cheque_date||'—'}</td>
                <td style="font-size:12px">${a.common_date||'—'}</td>
                <td style="text-align:center">
                    <button class="abtn abtn-del" title="Delete" onclick="deleteAck(${a.id},${entryId})"><i class="fa-solid fa-trash"></i></button>
                </td>
            </tr>`).join('')}
            </tbody>
        </table>`;
    } catch(e) {
        $id('viewAcksBody').innerHTML = '<div class="acks-empty">Error loading acknowledgments.</div>';
    }
}

function closeViewModal() { $id('viewAcksModal').classList.remove('open'); }

async function deleteAck(id, entryId) {
    if (!confirm('Delete this cheque acknowledgment?')) return;
    const fd = new FormData(); fd.append('id', id);
    const res = await fetch('dal_cheque_ack.php?action=delete_ack',{method:'POST',body:fd});
    const d   = await res.json();
    if (d.success) { showToast('Deleted.','warn'); openViewAcks(entryId); loadTable(); }
    else showToast('Delete failed.','error');
}

// ══ PRINT ACK ═════════════════════════════════════════════════
function printAck() {
    if (!_currentEntry || !_asgnRows.length) { showToast('No data to print.','error'); return; }

    const bankSel = $id('am_bank');
    const bankText = bankSel.selectedIndex > 0 ? bankSel.options[bankSel.selectedIndex].text : '—';
    const commonDate = $id('am_common_date').value || '—';
    const today  = new Date().toLocaleDateString('en-GB',{day:'2-digit',month:'long',year:'numeric'});

    const rows = _asgnRows.map((r, i) => `
        <tr>
            <td>${i+1}</td>
            <td><strong>${eh(r.ref_name)}</strong><br><span style="font-size:11px;color:#64748b;font-family:monospace">${eh(r.ref_code)}</span></td>
            <td style="text-align:center">${r.line_type==='employee'?'Employee':'Customer'}</td>
            <td style="text-align:right;font-weight:700">LKR ${fN(r.total)}</td>
            <td style="font-family:monospace;font-weight:700;color:#4c1d95">${eh(r.cheque_no||'—')}</td>
            <td style="text-align:center">${r.cheque_date||'—'}</td>
        </tr>`).join('');

    const totalAmt = _asgnRows.reduce((s,r)=>s+r.total,0);

    $id('printArea').innerHTML = `
    <div style="font-family:Arial,sans-serif;font-size:13px;color:#0f172a;max-width:900px;margin:0 auto">
        <div style="text-align:center;border-bottom:3px solid #7c3aed;padding-bottom:16px;margin-bottom:20px">
            <h2 style="margin:0;font-size:20px;color:#4c1d95">CHEQUE ACKNOWLEDGMENT</h2>
            <p style="margin:4px 0 0;font-size:12px;color:#64748b">DAL — Drivers &amp; Loyalty</p>
        </div>
        <div style="display:flex;gap:30px;flex-wrap:wrap;margin-bottom:18px">
            <div><strong>Entry Date:</strong> ${eh(_currentEntry.email_date||'—')}</div>
            <div><strong>Description:</strong> ${eh(_currentEntry.description||'—')}</div>
            <div><strong>Bank:</strong> ${eh(bankText)}</div>
            <div><strong>Common Date:</strong> ${commonDate}</div>
            <div><strong>Printed:</strong> ${today}</div>
        </div>
        <table style="width:100%;border-collapse:collapse;font-size:13px">
            <thead>
                <tr style="background:#f5f3ff">
                    <th style="border:1px solid #ddd6fe;padding:9px 12px;text-align:left">#</th>
                    <th style="border:1px solid #ddd6fe;padding:9px 12px;text-align:left">Name</th>
                    <th style="border:1px solid #ddd6fe;padding:9px 12px;text-align:center">Type</th>
                    <th style="border:1px solid #ddd6fe;padding:9px 12px;text-align:right">Amount</th>
                    <th style="border:1px solid #ddd6fe;padding:9px 12px;text-align:left">Cheque No</th>
                    <th style="border:1px solid #ddd6fe;padding:9px 12px;text-align:center">Cheque Date</th>
                </tr>
            </thead>
            <tbody>
                ${rows}
                <tr style="background:#f0fdf4">
                    <td colspan="3" style="border:1px solid #ddd;padding:9px 12px;font-weight:700;text-align:right">TOTAL</td>
                    <td style="border:1px solid #ddd;padding:9px 12px;font-weight:800;text-align:right;color:#16a34a">LKR ${fN(totalAmt)}</td>
                    <td colspan="2" style="border:1px solid #ddd;padding:9px 12px"></td>
                </tr>
            </tbody>
        </table>
        <div style="margin-top:40px;display:flex;justify-content:space-between">
            <div style="text-align:center"><div style="border-top:1px solid #000;padding-top:6px;min-width:160px">Prepared By</div></div>
            <div style="text-align:center"><div style="border-top:1px solid #000;padding-top:6px;min-width:160px">Authorized By</div></div>
            <div style="text-align:center"><div style="border-top:1px solid #000;padding-top:6px;min-width:160px">Received By</div></div>
        </div>
    </div>`;

    $id('printArea').style.display = 'block';
    window.print();
    $id('printArea').style.display = 'none';
}

// ══ BACKDROP ═════════════════════════════════════════════════
$id('assignModal').addEventListener('click', e => { if(e.target===$id('assignModal')) closeAssignModal(); });
$id('viewAcksModal').addEventListener('click', e => { if(e.target===$id('viewAcksModal')) closeViewModal(); });

// ══ INIT ═════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', loadTable);
</script>

<?php include 'footer.php'; ?>
