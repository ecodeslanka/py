<?php
include 'config.php';

// ══════════════════════════════════════════════════════════════════
//  TABLE SETUP
// ══════════════════════════════════════════════════════════════════
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS dl_cheque_ack_new (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    source_type         ENUM('customer','employee') NOT NULL DEFAULT 'customer',
    ref_id              INT NULL,
    ref_code            VARCHAR(100) NULL,
    ref_name            VARCHAR(255) NULL,
    tax_invoice_no      VARCHAR(150) NULL,
    invoice_date        DATE NULL,
    entity              VARCHAR(100) NULL,
    claim_description   TEXT NULL,
    actual_amount       DECIMAL(18,4) DEFAULT 0,
    vat_amount          DECIMAL(18,4) DEFAULT 0,
    total_amount        DECIMAL(18,4) DEFAULT 0,
    claim_type          VARCHAR(100) NULL,
    cheque_book_id      INT NULL,
    cheque_no           VARCHAR(100) NULL,
    cheque_date         DATE NULL,
    common_date         DATE NULL,
    bank_account_id     INT NULL,
    bank_account_no     VARCHAR(100) NULL,
    bank_name           VARCHAR(255) NULL,
    status              ENUM('pending','assigned','printed') DEFAULT 'pending',
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_source (source_type, ref_id),
    INDEX idx_cheque (cheque_book_id, cheque_no),
    INDEX idx_status (status)
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS dl_cheque_ack_new_cancelled (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    cheque_book_id  INT NOT NULL,
    cheque_no       VARCHAR(100) NOT NULL,
    reason          TEXT NULL,
    cancelled_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_book (cheque_book_id)
)");

// Auto-migrate
$_acols = [
    'common_date'    => "DATE NULL AFTER cheque_date",
    'entity'         => "VARCHAR(100) NULL AFTER invoice_date",
    'claim_type'     => "VARCHAR(100) NULL AFTER claim_description",
    'status'         => "ENUM('pending','assigned','printed') DEFAULT 'pending' AFTER bank_name",
];
foreach ($_acols as $_col => $_def) {
    $chk = mysqli_query($conn, "SHOW COLUMNS FROM dl_cheque_ack_new LIKE '$_col'");
    if ($chk && mysqli_num_rows($chk) === 0)
        @mysqli_query($conn, "ALTER TABLE dl_cheque_ack_new ADD COLUMN $_col $_def");
}

// Ensure ca_cancelled_cheques exists (used by cheque_acknowledgments.php)
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS ca_cancelled_cheques (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    cheque_book_id  INT NOT NULL,
    cheque_no       VARCHAR(100) NOT NULL,
    reason          TEXT NULL,
    cancelled_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_book  (cheque_book_id)
)");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS ca_customer_claims (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    ack_id          INT NOT NULL,
    cheque_book_id  INT NULL,
    cheque_no       VARCHAR(100) NULL,
    bank_account_id INT NULL,
    bank_account_no VARCHAR(100) NULL,
    bank_name       VARCHAR(255) NULL,
    claim_amount    DECIMAL(18,4) DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// ══════════════════════════════════════════════════════════════════
//  HELPER: next available cheque
// ══════════════════════════════════════════════════════════════════
function get_next_cheque_dan($conn, $book_id) {
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

    // Used: from dl_cheque_ack_new + ca_customer_claims
    $used = [];
    $ur1 = mysqli_query($conn, "SELECT cheque_no FROM dl_cheque_ack_new WHERE cheque_book_id=$book_id AND cheque_no!='' AND cheque_no IS NOT NULL");
    while ($u = mysqli_fetch_assoc($ur1)) $used[] = $u['cheque_no'];
    $ur2 = mysqli_query($conn, "SELECT cheque_no FROM ca_customer_claims WHERE cheque_book_id=$book_id AND cheque_no!='' AND cheque_no IS NOT NULL");
    while ($u = mysqli_fetch_assoc($ur2)) $used[] = $u['cheque_no'];
    $used = array_unique($used);

    // Cancelled: from both tables
    $cancelled = [];
    $cr1 = mysqli_query($conn, "SELECT cheque_no FROM ca_cancelled_cheques WHERE cheque_book_id=$book_id");
    while ($c = mysqli_fetch_assoc($cr1)) $cancelled[] = $c['cheque_no'];
    $cr2 = mysqli_query($conn, "SELECT cheque_no FROM dl_cheque_ack_new_cancelled WHERE cheque_book_id=$book_id");
    while ($c = mysqli_fetch_assoc($cr2)) $cancelled[] = $c['cheque_no'];
    $cancelled = array_unique($cancelled);

    $blocked = array_unique(array_merge($used, $cancelled));

    for ($n = $start_num; $n <= $end_num; $n++) {
        $leaf = $prefix . str_pad($n, $pad_width, '0', STR_PAD_LEFT);
        if (!in_array($leaf, $blocked)) {
            $remaining = $total - count($used) - count($cancelled) - 1;
            return [
                'cheque_no'        => $leaf,
                'total_leaves'     => $total,
                'used_count'       => count($used),
                'cancelled_count'  => count($cancelled),
                'leaves_remaining' => max(0, $remaining),
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
    $action = $_GET['action'];

    // ── Search customers ──────────────────────────────────────────
    if ($action === 'search_customers') {
        $q   = mysqli_real_escape_string($conn, trim($_GET['q'] ?? ''));
        $sql = "SELECT id, t_code, shop_name FROM customers WHERE active=1";
        if ($q) $sql .= " AND (t_code LIKE '%$q%' OR shop_name LIKE '%$q%')";
        $sql .= " ORDER BY shop_name LIMIT 60";
        $res  = mysqli_query($conn, $sql);
        $rows = [];
        if ($res) while ($r = mysqli_fetch_assoc($res))
            $rows[] = ['id'=>$r['id'],'text'=>'['.$r['t_code'].'] '.$r['shop_name'],'code'=>$r['t_code'],'name'=>$r['shop_name']];
        echo json_encode(['results' => $rows]);
        exit;
    }

    // ── Search employees ──────────────────────────────────────────
    if ($action === 'search_employees') {
        $q   = mysqli_real_escape_string($conn, trim($_GET['q'] ?? ''));
        $sql = "SELECT id, employee_id, employee_full_name FROM employees WHERE active=1";
        if ($q) $sql .= " AND (employee_id LIKE '%$q%' OR employee_full_name LIKE '%$q%')";
        $sql .= " ORDER BY employee_full_name LIMIT 60";
        $res  = mysqli_query($conn, $sql);
        $rows = [];
        if ($res) while ($r = mysqli_fetch_assoc($res))
            $rows[] = ['id'=>$r['id'],'text'=>'['.$r['employee_id'].'] '.$r['employee_full_name'],'code'=>$r['employee_id'],'name'=>$r['employee_full_name']];
        echo json_encode(['results' => $rows]);
        exit;
    }

    // ── Get bank accounts ─────────────────────────────────────────
    if ($action === 'get_bank_accounts') {
        $res  = mysqli_query($conn,
            "SELECT cba.id, cba.account_no, cba.account_name, cba.account_type,
                    COALESCE(b.bank_name,'') AS bank_name
             FROM company_bank_accounts cba
             LEFT JOIN banks b ON b.bank_code=cba.bank_code
             WHERE cba.active=1 ORDER BY b.bank_name");
        $rows = [];
        while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
        echo json_encode(['success'=>true,'data'=>$rows]);
        exit;
    }

    // ── Get cheque books ──────────────────────────────────────────
    if ($action === 'get_cc_books') {
        $bank_id = intval($_GET['bank_account_id'] ?? 0);
        $where   = $bank_id ? "WHERE ccb.bank_account_id=$bank_id AND ccb.status='Active'" : "WHERE ccb.status='Active'";
        $res = mysqli_query($conn,
            "SELECT ccb.*,
                    COALESCE(NULLIF(b.bank_name,''),cba.bank_code,'') AS bank_name_label,
                    cba.account_no
             FROM customer_claim_cheque_books ccb
             LEFT JOIN company_bank_accounts cba ON cba.id=ccb.bank_account_id
             LEFT JOIN banks b ON b.bank_code=cba.bank_code
             $where ORDER BY ccb.id ASC");
        $books = [];
        while ($bk = mysqli_fetch_assoc($res)) {
            $next = get_next_cheque_dan($conn, $bk['id']);
            $bk['next_cheque_no']   = $next ? $next['cheque_no']        : null;
            $bk['leaves_remaining'] = $next ? $next['leaves_remaining']  : 0;
            $bk['used_count']       = $next ? $next['used_count']        : intval($bk['leaf_count']);
            $bk['cancelled_count']  = $next ? $next['cancelled_count']   : 0;
            $bk['total_leaves']     = intval($bk['leaf_count']);
            $books[] = $bk;
        }
        echo json_encode(['success'=>true,'data'=>$books]);
        exit;
    }

    // ── Look up cheque no in any active book ──────────────────────
    if ($action === 'lookup_cheque') {
        $cheque_no = mysqli_real_escape_string($conn, trim($_GET['cheque_no'] ?? ''));
        if (!$cheque_no) { echo json_encode(['success'=>false]); exit; }
        $bres = mysqli_query($conn,
            "SELECT ccb.*,
                    COALESCE(NULLIF(b.bank_name,''),cba.bank_code,'') AS bank_name_label,
                    cba.account_no
             FROM customer_claim_cheque_books ccb
             LEFT JOIN company_bank_accounts cba ON cba.id=ccb.bank_account_id
             LEFT JOIN banks b ON b.bank_code=cba.bank_code
             WHERE ccb.status='Active'");
        $book = null;
        while ($bk = mysqli_fetch_assoc($bres)) {
            $sn = intval(preg_replace('/\D/','',$bk['leaf_no_start']));
            $en = intval(preg_replace('/\D/','',$bk['leaf_no_end']));
            $cn = intval(preg_replace('/\D/','',$cheque_no));
            if ($cn>=$sn && $cn<=$en) { $book=$bk; break; }
        }
        if (!$book) { echo json_encode(['success'=>false,'message'=>'Not in any active CC book.']); exit; }
        $bid   = intval($book['id']);
        $total = intval($book['leaf_count']);
        $used_r   = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(DISTINCT cheque_no) as c FROM dl_cheque_ack_new WHERE cheque_book_id=$bid AND cheque_no!=''"));
        $used2_r  = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(DISTINCT cheque_no) as c FROM ca_customer_claims WHERE cheque_book_id=$bid AND cheque_no!=''"));
        $canc_r   = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as c FROM ca_cancelled_cheques WHERE cheque_book_id=$bid"));
        $canc2_r  = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as c FROM dl_cheque_ack_new_cancelled WHERE cheque_book_id=$bid"));
        $is_used  = intval(mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as c FROM dl_cheque_ack_new WHERE cheque_book_id=$bid AND cheque_no='$cheque_no'"))['c']) > 0;
        $is_used  = $is_used || intval(mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as c FROM ca_customer_claims WHERE cheque_book_id=$bid AND cheque_no='$cheque_no'"))['c']) > 0;
        $is_canc  = intval(mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as c FROM ca_cancelled_cheques WHERE cheque_book_id=$bid AND cheque_no='$cheque_no'"))['c']) > 0;
        $is_canc  = $is_canc || intval(mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as c FROM dl_cheque_ack_new_cancelled WHERE cheque_book_id=$bid AND cheque_no='$cheque_no'"))['c']) > 0;
        $used_cnt = intval($used_r['c']) + intval($used2_r['c']);
        $canc_cnt = intval($canc_r['c']) + intval($canc2_r['c']);
        $remaining = max(0, $total - $used_cnt - $canc_cnt - ($is_used ? 0 : 1));
        echo json_encode(['success'=>true,'data'=>array_merge($book,[
            'is_used'=>$is_used,'is_cancelled'=>$is_canc,
            'leaves_remaining'=>$remaining,'used_count'=>$used_cnt,'cancelled_count'=>$canc_cnt,'total_leaves'=>$total,
        ])]);
        exit;
    }

    // ── Cancel cheque ─────────────────────────────────────────────
    if ($action === 'cancel_cheque' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $book_id   = intval($_POST['book_id'] ?? 0);
        $cheque_no = mysqli_real_escape_string($conn, trim($_POST['cheque_no'] ?? ''));
        $reason    = mysqli_real_escape_string($conn, trim($_POST['reason']    ?? ''));
        if (!$book_id || !$cheque_no) { echo json_encode(['success'=>false,'message'=>'Book ID and cheque no required.']); exit; }
        $exists = mysqli_fetch_assoc(mysqli_query($conn,"SELECT id FROM dl_cheque_ack_new_cancelled WHERE cheque_book_id=$book_id AND cheque_no='$cheque_no' LIMIT 1"));
        if (!$exists) mysqli_query($conn,"INSERT INTO dl_cheque_ack_new_cancelled (cheque_book_id,cheque_no,reason) VALUES ($book_id,'$cheque_no','$reason')");
        $next = get_next_cheque_dan($conn, $book_id);
        echo json_encode(['success'=>true,'cancelled'=>$cheque_no,'next'=>$next]);
        exit;
    }

    // ── Save (create / update) ack record ────────────────────────
    if ($action === 'save_ack' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id              = intval($_POST['id'] ?? 0);
        $source_type     = in_array($_POST['source_type'] ?? '', ['customer','employee']) ? $_POST['source_type'] : 'customer';
        $ref_id          = intval($_POST['ref_id'] ?? 0);
        $ref_code        = mysqli_real_escape_string($conn, trim($_POST['ref_code']        ?? ''));
        $ref_name        = mysqli_real_escape_string($conn, trim($_POST['ref_name']        ?? ''));
        $tax_invoice_no  = mysqli_real_escape_string($conn, trim($_POST['tax_invoice_no']  ?? ''));
        $invoice_date    = mysqli_real_escape_string($conn, trim($_POST['invoice_date']    ?? ''));
        $entity          = mysqli_real_escape_string($conn, trim($_POST['entity']          ?? ''));
        $claim_desc      = mysqli_real_escape_string($conn, trim($_POST['claim_description'] ?? ''));
        $actual_amount   = is_numeric($_POST['actual_amount']  ?? '') ? floatval($_POST['actual_amount'])  : 0;
        $vat_amount      = is_numeric($_POST['vat_amount']     ?? '') ? floatval($_POST['vat_amount'])     : 0;
        $total_amount    = is_numeric($_POST['total_amount']   ?? '') ? floatval($_POST['total_amount'])   : 0;
        $claim_type      = mysqli_real_escape_string($conn, trim($_POST['claim_type']      ?? ''));
        $cheque_book_id  = intval($_POST['cheque_book_id'] ?? 0);
        $cheque_no       = mysqli_real_escape_string($conn, trim($_POST['cheque_no']   ?? ''));
        $cheque_date     = mysqli_real_escape_string($conn, trim($_POST['cheque_date'] ?? ''));
        $common_date     = mysqli_real_escape_string($conn, trim($_POST['common_date'] ?? ''));
        $bank_account_id = intval($_POST['bank_account_id'] ?? 0);
        $bank_account_no = mysqli_real_escape_string($conn, trim($_POST['bank_account_no'] ?? ''));
        $bank_name       = mysqli_real_escape_string($conn, trim($_POST['bank_name']       ?? ''));

        $inv_sql    = $invoice_date ? "'$invoice_date'" : 'NULL';
        $chq_sql    = $cheque_date  ? "'$cheque_date'"  : 'NULL';
        $com_sql    = $common_date  ? "'$common_date'"  : 'NULL';
        $bk_sql     = $cheque_book_id > 0 ? $cheque_book_id : 'NULL';
        $ba_sql     = $bank_account_id > 0 ? $bank_account_id : 'NULL';
        $status     = ($cheque_no && $cheque_date) ? 'assigned' : 'pending';

        if ($id > 0) {
            $ok = mysqli_query($conn,
                "UPDATE dl_cheque_ack_new SET
                    source_type='$source_type', ref_id=$ref_id, ref_code='$ref_code', ref_name='$ref_name',
                    tax_invoice_no='$tax_invoice_no', invoice_date=$inv_sql, entity='$entity',
                    claim_description='$claim_desc', actual_amount=$actual_amount,
                    vat_amount=$vat_amount, total_amount=$total_amount, claim_type='$claim_type',
                    cheque_book_id=$bk_sql, cheque_no='$cheque_no', cheque_date=$chq_sql,
                    common_date=$com_sql, bank_account_id=$ba_sql,
                    bank_account_no='$bank_account_no', bank_name='$bank_name', status='$status'
                 WHERE id=$id");
        } else {
            $ok = mysqli_query($conn,
                "INSERT INTO dl_cheque_ack_new
                    (source_type,ref_id,ref_code,ref_name,tax_invoice_no,invoice_date,entity,
                     claim_description,actual_amount,vat_amount,total_amount,claim_type,
                     cheque_book_id,cheque_no,cheque_date,common_date,bank_account_id,bank_account_no,bank_name,status)
                 VALUES ('$source_type',$ref_id,'$ref_code','$ref_name','$tax_invoice_no',$inv_sql,'$entity',
                     '$claim_desc',$actual_amount,$vat_amount,$total_amount,'$claim_type',
                     $bk_sql,'$cheque_no',$chq_sql,$com_sql,$ba_sql,'$bank_account_no','$bank_name','$status')");
            $id = mysqli_insert_id($conn);
        }
        echo json_encode(['success'=>(bool)$ok,'id'=>$id,'message'=>$ok?'':''.mysqli_error($conn)]);
        exit;
    }

    // ── Get single ack ────────────────────────────────────────────
    if ($action === 'get_ack') {
        $id  = intval($_GET['id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT a.*,
                    ccb.leaf_no_start, ccb.leaf_no_end, ccb.customer_name AS book_name,
                    ccb.leaf_count
             FROM dl_cheque_ack_new a
             LEFT JOIN customer_claim_cheque_books ccb ON ccb.id=a.cheque_book_id
             WHERE a.id=$id LIMIT 1"));
        echo json_encode(['success'=>(bool)$row,'data'=>$row]);
        exit;
    }

    // ── Delete ack ────────────────────────────────────────────────
    if ($action === 'delete_ack' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = intval($_POST['id'] ?? 0);
        $ok = mysqli_query($conn, "DELETE FROM dl_cheque_ack_new WHERE id=$id");
        echo json_encode(['success'=>(bool)$ok]);
        exit;
    }

    // ── List (AJAX) ───────────────────────────────────────────────
    if ($action === 'list') {
        $source_type = in_array($_GET['source_type'] ?? '', ['customer','employee']) ? $_GET['source_type'] : 'customer';
        $search      = mysqli_real_escape_string($conn, trim($_GET['search']    ?? ''));
        $date_from   = mysqli_real_escape_string($conn, trim($_GET['date_from'] ?? ''));
        $date_to     = mysqli_real_escape_string($conn, trim($_GET['date_to']   ?? ''));
        $status_f    = mysqli_real_escape_string($conn, trim($_GET['status']    ?? ''));

        $where = ["a.source_type='$source_type'"];
        if ($search)    $where[] = "(a.tax_invoice_no LIKE '%$search%' OR a.ref_name LIKE '%$search%' OR a.ref_code LIKE '%$search%' OR a.claim_description LIKE '%$search%')";
        if ($date_from) $where[] = "a.invoice_date >= '$date_from'";
        if ($date_to)   $where[] = "a.invoice_date <= '$date_to'";
        if ($status_f)  $where[] = "a.status='$status_f'";

        $sql = "SELECT a.*,
                       ccb.leaf_no_start, ccb.leaf_no_end
                FROM dl_cheque_ack_new a
                LEFT JOIN customer_claim_cheque_books ccb ON ccb.id=a.cheque_book_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY a.created_at DESC";
        $res  = mysqli_query($conn, $sql);
        $rows = [];
        while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
        $totals = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) as cnt,
                    COALESCE(SUM(actual_amount),0) as sum_net,
                    COALESCE(SUM(vat_amount),0) as sum_vat,
                    COALESCE(SUM(total_amount),0) as sum_tot,
                    SUM(status='assigned') as assigned_cnt,
                    SUM(status='pending') as pending_cnt
             FROM dl_cheque_ack_new a WHERE " . implode(' AND ', $where)));
        echo json_encode(['success'=>true,'rows'=>$rows,'totals'=>$totals]);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']);
    exit;
}

// ══════════════════════════════════════════════════════════════════
//  PAGE LOAD
// ══════════════════════════════════════════════════════════════════
$page_totals = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as cnt,
            COALESCE(SUM(total_amount),0) as sum_tot,
            SUM(source_type='customer') as cust_cnt,
            SUM(source_type='employee') as emp_cnt,
            SUM(status='assigned') as assigned_cnt,
            SUM(status='pending') as pending_cnt
     FROM dl_cheque_ack_new"));

include 'header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
*,*::before,*::after{box-sizing:border-box}
body{font-family:'Inter',system-ui,sans-serif}

/* ── Page ── */
.page-hdr{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;margin-bottom:22px}
.page-title{font-size:24px;font-weight:800;color:#0f172a;margin:0;display:flex;align-items:center;gap:10px}
.page-title i{color:#1e3a5f}
.page-sub{font-size:13px;color:#64748b;margin:4px 0 0}

/* ── Summary ── */
.sum-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:20px}
.sum-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px 18px}
.sum-lbl{font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.6px;margin-bottom:6px}
.sum-val{font-size:20px;font-weight:800;color:#0f172a}
.sum-sub{font-size:11px;color:#cbd5e1;margin-top:3px}

/* ── Tabs ── */
.tab-bar{display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:18px}
.tab-btn{padding:11px 28px;font-size:13px;font-weight:700;border:none;background:none;cursor:pointer;color:#64748b;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .18s;display:flex;align-items:center;gap:7px;font-family:inherit}
.tab-btn:hover{color:#1e3a5f}
.tab-btn.active{color:#1e3a5f;border-bottom-color:#1e3a5f}
.tab-badge{display:inline-flex;align-items:center;justify-content:center;background:#e2e8f0;color:#475569;font-size:10px;font-weight:800;padding:1px 8px;border-radius:10px;min-width:22px}
.tab-btn.active .tab-badge{background:#1e3a5f;color:#fff}

/* ── Filter bar ── */
.fbar{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:13px 16px;margin-bottom:14px}
.fg{display:flex;flex-direction:column;gap:4px}
.fl{font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px}
.fi{padding:8px 12px;border:1px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:inherit;outline:none;height:36px;background:#fff}
.fi:focus{border-color:#1e3a5f}

/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 20px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;white-space:nowrap;transition:all .18s;text-decoration:none}
.btn-sm{padding:7px 14px;font-size:12px}
.btn-xs{padding:4px 10px;font-size:11px}
.btn-navy{background:#1e3a5f;color:#fff}.btn-navy:hover{background:#0f2540}
.btn-light{background:#f8fafc;color:#374151;border:1px solid #e2e8f0}.btn-light:hover{background:#f1f5f9}
.btn-danger{background:#fef2f2;color:#dc2626;border:1px solid #fca5a5}.btn-danger:hover{background:#dc2626;color:#fff}
.btn-green{background:#f0fdf4;color:#16a34a;border:1px solid #86efac}.btn-green:hover{background:#16a34a;color:#fff}
.btn-violet{background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe}.btn-violet:hover{background:#7c3aed;color:#fff}
.btn-amber{background:#fffbeb;color:#d97706;border:1px solid #fde68a}.btn-amber:hover{background:#d97706;color:#fff}
.btn-print{background:#1e3a5f;color:#fff}.btn-print:hover{background:#0f2540}
.btn-new{background:linear-gradient(135deg,#1e3a5f,#2563eb);color:#fff;box-shadow:0 2px 8px rgba(30,58,95,.3);padding:10px 22px;font-size:14px}
.btn-new:hover{filter:brightness(1.09);transform:translateY(-1px)}

/* ── Table card ── */
.tbl-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden}
.tbl-hdr{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #f1f5f9;flex-wrap:wrap;gap:10px}
.tbl-title{font-size:14px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:8px}
.tbl-badge{background:#f1f5f9;color:#475569;font-size:11px;font-weight:700;padding:2px 9px;border-radius:10px}
.search-wrap{position:relative}
.search-wrap i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:12px;pointer-events:none}
.tbl-search{width:200px;padding:7px 12px 7px 30px;border:1px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:inherit;outline:none}
.tbl-search:focus{border-color:#1e3a5f}

.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table thead th{background:#f8fafc;padding:10px 14px;text-align:left;font-weight:700;font-size:11px;color:#475569;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;border-bottom:2px solid #e2e8f0}
.data-table th.num,.data-table td.num{text-align:right}
.data-table tbody tr{border-bottom:1px solid #f8fafc;transition:background .1s}
.data-table tbody tr:hover{background:#f8fafc}
.data-table td{padding:11px 14px;vertical-align:middle;white-space:nowrap}
.data-table tfoot td{padding:10px 14px;font-weight:800;background:#f0f9ff;border-top:2px solid #bae6fd}
.empty-state{text-align:center;padding:60px 20px;color:#94a3b8}
.empty-state i{font-size:36px;display:block;margin-bottom:12px;color:#cbd5e1}

/* ── Status badge ── */
.status-pill{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:10px;font-size:10px;font-weight:700}
.status-pending{background:#fef3c7;color:#92400e}
.status-assigned{background:#dcfce7;color:#166534}
.status-printed{background:#dbeafe;color:#1e40af}

/* ── Type badge ── */
.type-cust{display:inline-flex;align-items:center;gap:4px;background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;border-radius:5px;padding:2px 8px;font-size:10px;font-weight:700}
.type-emp{display:inline-flex;align-items:center;gap:4px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:5px;padding:2px 8px;font-size:10px;font-weight:700}

/* ── Action buttons ── */
.act-btns{display:flex;gap:5px;align-items:center;justify-content:center}
.abtn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:6px;border:1px solid #e2e8f0;background:#fff;color:#6b7280;cursor:pointer;font-size:12px;transition:all .18s}
.abtn:hover{transform:translateY(-1px);box-shadow:0 2px 4px rgba(0,0,0,.08)}
.abtn-view:hover{background:#3b82f6;color:#fff;border-color:#3b82f6}
.abtn-assign:hover{background:#7c3aed;color:#fff;border-color:#7c3aed}
.abtn-print:hover{background:#1e3a5f;color:#fff;border-color:#1e3a5f}
.abtn-del:hover{background:#ef4444;color:#fff;border-color:#ef4444}

/* ── Skeleton ── */
.skel-row{display:flex;gap:14px;padding:12px 0;border-bottom:1px solid #f3f4f6}
.skel-cell{height:13px;background:linear-gradient(90deg,#f3f4f6 25%,#e9eaec 50%,#f3f4f6 75%);background-size:200% 100%;animation:shimmer 1.4s infinite;border-radius:4px;flex-shrink:0}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

/* ══════════════════════════════════════════
   MODALS (shared)
══════════════════════════════════════════ */
.mo{position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:9000;display:none;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto;backdrop-filter:blur(2px)}
.mo.open{display:flex;animation:moFd .2s ease}
@keyframes moFd{from{opacity:0}to{opacity:1}}
.mo-box{background:#fff;border-radius:16px;width:100%;max-width:780px;box-shadow:0 24px 80px rgba(0,0,0,.22);animation:moSl .24s ease;margin:auto;display:flex;flex-direction:column;max-height:calc(100vh - 40px);overflow:hidden}
.mo-box.wide{max-width:1000px}
@keyframes moSl{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:translateY(0)}}
.mo-hdr{padding:20px 26px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.mo-hdr-title{color:#fff;font-size:16px;font-weight:800;display:flex;align-items:center;gap:10px}
.mo-hdr-navy{background:linear-gradient(135deg,#0f2540,#1e3a5f,#2563eb)}
.mo-hdr-violet{background:linear-gradient(135deg,#312e81,#4338ca,#6d28d9)}
.mo-hdr-green{background:linear-gradient(135deg,#064e3b,#065f46,#047857)}
.mo-close{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff;border-radius:8px;padding:7px 16px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .2s}
.mo-close:hover{background:rgba(255,255,255,.25)}
.mo-body{padding:22px 28px;overflow-y:auto;flex:1}
.mo-footer{padding:14px 28px;border-top:1px solid #f1f5f9;display:flex;justify-content:flex-end;gap:10px;background:#fafafa;flex-shrink:0}

/* ── Form components inside modal ── */
.fgrp{display:flex;flex-direction:column;gap:5px;margin-bottom:16px}
.flbl{font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px}
.flbl .opt{font-weight:400;font-size:10px;color:#94a3b8;text-transform:none;letter-spacing:0}
.finp{padding:10px 13px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;font-family:inherit;outline:none;width:100%;background:#fff}
.finp:focus{border-color:#1e3a5f;box-shadow:0 0 0 3px rgba(30,58,95,.1)}
.form-row2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}
.sec-div{display:flex;align-items:center;gap:12px;margin:20px 0 14px}
.sec-div-line{flex:1;height:1px;background:#e2e8f0}
.sec-div-lbl{font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.6px;white-space:nowrap}

/* ── Info strip (view modal) ── */
.info-strip{display:flex;flex-wrap:wrap;background:#f8fafc;border-bottom:2px solid #e2e8f0;flex-shrink:0}
.info-cell{display:flex;flex-direction:column;gap:2px;padding:12px 18px;border-right:1px solid #e2e8f0;min-width:110px}
.info-cell:last-child{border-right:none}
.ic-lbl{font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;font-weight:600}
.ic-val{font-size:15px;font-weight:800;color:#0f172a}
.ic-val.sky{color:#0369a1}.ic-val.purple{color:#7c3aed}.ic-val.green{color:#16a34a}.ic-val.amber{color:#d97706}.ic-val.navy{color:#1e3a5f}

/* ── Cheque book cards ── */
.cb-list{display:flex;flex-direction:column;gap:8px;margin-bottom:14px}
.cb-card{border:2px solid #e2e8f0;border-radius:9px;padding:11px 14px;cursor:pointer;transition:all .2s;background:#fff}
.cb-card:hover{border-color:#7c3aed;background:#faf5ff}
.cb-card.selected{border-color:#7c3aed;background:#f5f3ff}
.cb-card.exhausted{opacity:.55;cursor:not-allowed;border-color:#e2e8f0;background:#f9fafb}
.cb-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px}
.cb-name{font-size:13px;font-weight:700;color:#0f172a}
.cb-bank{font-size:11px;color:#64748b;margin-top:1px}
.cb-next{font-family:monospace;font-size:13px;font-weight:700;color:#7c3aed;background:#f5f3ff;border:1px solid #ddd6fe;padding:2px 8px;border-radius:5px}
.cb-leaf-bar{height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;margin:5px 0 3px}
.cb-leaf-fill{height:100%;border-radius:3px;transition:width .3s}
.cb-stats{display:flex;gap:12px;font-size:10.5px;color:#6b7280}
.cb-stat-g{color:#16a34a;font-weight:700}.cb-stat-a{color:#d97706;font-weight:700}.cb-stat-r{color:#dc2626;font-weight:700}.cb-stat-x{color:#94a3b8}

/* ── Cheque field ── */
.chq-hint{display:flex;align-items:center;gap:6px;margin-top:5px;font-size:11.5px;color:#64748b;flex-wrap:wrap}
.chq-hint.ok{color:#7c3aed;font-weight:600}
.chq-hint.err{color:#dc2626}
.chq-leaf-wrap{margin-top:8px;display:none}
.chq-leaf-stats{display:flex;gap:12px;font-size:10.5px;margin-top:3px;flex-wrap:wrap}
.cancel-box{background:#fff7ed;border:1.5px dashed #f59e0b;border-radius:9px;padding:13px 15px;margin-top:6px;display:none}
.cancel-box-title{font-size:12px;font-weight:700;color:#92400e;margin-bottom:8px;display:flex;align-items:center;gap:6px}

/* ── View detail sections ── */
.vw-section{margin-bottom:20px}
.vw-section-title{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;display:flex;align-items:center;gap:7px}
.vw-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px}
.vw-item{display:flex;flex-direction:column;gap:3px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 13px}
.vw-lbl{font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:.4px;font-weight:600}
.vw-val{font-size:14px;font-weight:700;color:#1e293b}
.vw-val.mono{font-family:monospace;color:#7c3aed}
.vw-val.green{color:#16a34a}.vw-val.sky{color:#0369a1}.vw-val.amber{color:#d97706}

/* ── Common date banner ── */
.common-date-bar{display:flex;align-items:center;gap:12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:9px;padding:11px 16px;margin-top:8px;flex-wrap:wrap}
.common-date-lbl{font-size:12px;font-weight:700;color:#1e40af;display:flex;align-items:center;gap:6px;flex-shrink:0}

/* ── Select2 ── */
.select2-container{width:100% !important}
.select2-container--default .select2-selection--single{height:42px;border:1px solid #d1d5db;border-radius:8px;display:flex;align-items:center;padding:0 12px}
.select2-container--default.select2-container--focus .select2-selection--single,
.select2-container--default.select2-container--open  .select2-selection--single{border-color:#1e3a5f;box-shadow:0 0 0 3px rgba(30,58,95,.1)}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:40px;font-size:14px;font-family:inherit;color:#1e293b;padding:0}
.select2-container--default .select2-selection--single .select2-selection__placeholder{color:#94a3b8}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:40px;right:10px}
.select2-dropdown{border:1px solid #d1d5db;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.1);font-size:13px;font-family:inherit;z-index:99999 !important}
.select2-container--default .select2-search--dropdown .select2-search__field{border:1px solid #d1d5db;border-radius:6px;padding:8px 12px;font-size:13px;font-family:inherit;outline:none}
.select2-container--default .select2-results__option{padding:10px 14px}
.select2-container--default .select2-results__option--highlighted[aria-selected]{background:#1e3a5f;color:#fff}

/* ── Toast ── */
#toast{position:fixed;bottom:24px;right:24px;padding:12px 22px;border-radius:10px;font-size:14px;font-weight:600;color:#fff;z-index:99999;display:none;box-shadow:0 4px 18px rgba(0,0,0,.18)}
#toast.success{background:#16a34a}#toast.error{background:#dc2626}
#toast.info{background:#1e3a5f}#toast.warn{background:#d97706}

@media(max-width:680px){.form-row2,.form-row3{grid-template-columns:1fr}.mo-body{padding:14px}}
</style>

<!-- ── Page Header ─────────────────────────────────────── -->
<div class="page-hdr">
    <div>
        <h2 class="page-title"><i class="fa-solid fa-money-check-dollar"></i> Cheque Acknowledgments</h2>
        <p class="page-sub">Assign cheques to customers and employees · print acknowledgment slips</p>
    </div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <a href="dal.php" class="btn btn-light btn-sm"><i class="fa-solid fa-arrow-left"></i> Back to D&amp;L</a>
        <button class="btn btn-new" onclick="openNewAck()"><i class="fa-solid fa-plus"></i> New Acknowledgment</button>
    </div>
</div>

<!-- ── Summary Cards ───────────────────────────────────── -->
<div class="sum-grid">
    <div class="sum-card">
        <div class="sum-lbl">Total Records</div>
        <div class="sum-val"><?php echo number_format($page_totals['cnt'] ?? 0); ?></div>
        <div class="sum-sub">All acknowledgments</div>
    </div>
    <div class="sum-card">
        <div class="sum-lbl">Customers</div>
        <div class="sum-val" style="color:#1e3a5f"><?php echo number_format($page_totals['cust_cnt'] ?? 0); ?></div>
        <div class="sum-sub">Customer entries</div>
    </div>
    <div class="sum-card">
        <div class="sum-lbl">Employees</div>
        <div class="sum-val" style="color:#92400e"><?php echo number_format($page_totals['emp_cnt'] ?? 0); ?></div>
        <div class="sum-sub">Employee entries</div>
    </div>
    <div class="sum-card">
        <div class="sum-lbl">Assigned</div>
        <div class="sum-val" style="color:#16a34a"><?php echo number_format($page_totals['assigned_cnt'] ?? 0); ?></div>
        <div class="sum-sub">Cheque assigned</div>
    </div>
    <div class="sum-card">
        <div class="sum-lbl">Pending</div>
        <div class="sum-val" style="color:#d97706"><?php echo number_format($page_totals['pending_cnt'] ?? 0); ?></div>
        <div class="sum-sub">Awaiting cheque</div>
    </div>
</div>

<!-- ── Tabs ───────────────────────────────────────────── -->
<div class="tab-bar">
    <button class="tab-btn active" id="tab-customer" onclick="switchTab('customer')">
        <i class="fa-solid fa-user"></i> Customers
        <span class="tab-badge" id="tab-badge-customer"><?php echo number_format($page_totals['cust_cnt'] ?? 0); ?></span>
    </button>
    <button class="tab-btn" id="tab-employee" onclick="switchTab('employee')">
        <i class="fa-solid fa-id-badge"></i> Employees
        <span class="tab-badge" id="tab-badge-employee"><?php echo number_format($page_totals['emp_cnt'] ?? 0); ?></span>
    </button>
</div>

<!-- ── Filter bar ─────────────────────────────────────── -->
<div class="fbar">
    <div class="fg">
        <label class="fl">Search</label>
        <input type="text" class="fi" id="filterSearch" placeholder="Name, invoice, description…" style="width:220px" oninput="debounceLoad()">
    </div>
    <div class="fg">
        <label class="fl">Invoice Date From</label>
        <input type="date" class="fi" id="filterFrom" style="width:145px">
    </div>
    <div class="fg">
        <label class="fl">To</label>
        <input type="date" class="fi" id="filterTo" style="width:145px">
    </div>
    <div class="fg">
        <label class="fl">Status</label>
        <select class="fi" id="filterStatus" style="width:130px">
            <option value="">All</option>
            <option value="pending">Pending</option>
            <option value="assigned">Assigned</option>
            <option value="printed">Printed</option>
        </select>
    </div>
    <button class="btn btn-navy btn-sm" onclick="loadTable()"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
    <button class="btn btn-light btn-sm" onclick="clearFilters()"><i class="fa-solid fa-xmark"></i> Clear</button>
</div>

<!-- ── Table ──────────────────────────────────────────── -->
<div class="tbl-card">
    <div class="tbl-hdr">
        <div class="tbl-title">
            <i class="fa-solid fa-table"></i>
            <span id="tblTabLabel">Customer</span> Acknowledgments
            <span class="tbl-badge" id="rowCountBadge">—</span>
        </div>
        <div class="search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" class="tbl-search" id="tblSearch" placeholder="Quick filter…" oninput="quickFilter()">
        </div>
    </div>

    <div id="skeletonArea" style="padding:0 18px">
        <?php for($i=0;$i<6;$i++): ?>
        <div class="skel-row">
            <?php foreach([30,120,100,80,100,120,90,80,80] as $w): ?>
            <div class="skel-cell" style="width:<?php echo $w; ?>px"></div>
            <?php endforeach; ?>
        </div>
        <?php endfor; ?>
    </div>

    <div id="tableArea" style="display:none;overflow-x:auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Name / Code</th>
                    <th>Invoice No.</th>
                    <th>Inv. Date</th>
                    <th>Entity</th>
                    <th class="num">Net Amt</th>
                    <th class="num">VAT</th>
                    <th class="num">Total</th>
                    <th>Cheque No.</th>
                    <th>Cheque Date</th>
                    <th>Status</th>
                    <th style="text-align:center">Actions</th>
                </tr>
            </thead>
            <tbody id="tableBody"></tbody>
            <tfoot id="tableFoot"></tfoot>
        </table>
        <div id="emptyState" class="empty-state" style="display:none">
            <i class="fa-solid fa-inbox"></i>
            <p>No acknowledgment records found.</p>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════
     NEW / EDIT ACK MODAL
══════════════════════════════════════════════════════ -->
<div class="mo" id="ackModal">
<div class="mo-box">
    <div class="mo-hdr mo-hdr-navy">
        <div class="mo-hdr-title"><i class="fa-solid fa-money-check-dollar"></i><span id="ackModalTitle">New Acknowledgment</span></div>
        <button class="mo-close" onclick="closeAckModal()"><i class="fa-solid fa-xmark"></i> Close</button>
    </div>
    <div class="mo-body">
        <input type="hidden" id="am_id" value="0">
        <input type="hidden" id="am_source_type" value="customer">

        <!-- Type selector -->
        <div style="display:flex;gap:10px;margin-bottom:18px;align-items:center">
            <span class="flbl" style="margin:0">Type:</span>
            <button type="button" id="am_type_cust" class="btn btn-sm btn-navy" onclick="setAckType('customer')"><i class="fa-solid fa-user"></i> Customer</button>
            <button type="button" id="am_type_emp"  class="btn btn-sm btn-light" onclick="setAckType('employee')"><i class="fa-solid fa-id-badge"></i> Employee</button>
        </div>

        <div class="fgrp">
            <label class="flbl" id="am_ref_label">Customer <span style="color:#ef4444">*</span></label>
            <select id="am_ref" class="finp" style="width:100%"></select>
        </div>

        <div class="form-row2">
            <div class="fgrp">
                <label class="flbl">Tax Invoice No. <span class="opt">(optional)</span></label>
                <input type="text" class="finp" id="am_invoice_no" placeholder="e.g. INV-0012">
            </div>
            <div class="fgrp">
                <label class="flbl">Invoice Date <span class="opt">(optional)</span></label>
                <input type="date" class="finp" id="am_invoice_date">
            </div>
        </div>
        <div class="form-row2">
            <div class="fgrp">
                <label class="flbl">Entity <span class="opt">(optional)</span></label>
                <input type="text" class="finp" id="am_entity" placeholder="e.g. SSCL">
            </div>
            <div class="fgrp">
                <label class="flbl">Claim Type <span class="opt">(optional)</span></label>
                <input type="text" class="finp" id="am_claim_type" placeholder="e.g. VAT Claim">
            </div>
        </div>
        <div class="fgrp">
            <label class="flbl">Claim Description <span class="opt">(optional)</span></label>
            <textarea class="finp" id="am_claim_desc" rows="2" placeholder="Brief description of this claim…" style="resize:vertical"></textarea>
        </div>
        <div class="form-row3">
            <div class="fgrp">
                <label class="flbl">Net Amount</label>
                <input type="number" step="0.01" min="0" class="finp" id="am_net" placeholder="0.00" oninput="calcAmounts()">
            </div>
            <div class="fgrp">
                <label class="flbl">VAT 18%</label>
                <input type="text" class="finp" id="am_vat" readonly style="background:#f8fafc;color:#7c3aed;font-weight:700" placeholder="0.00">
            </div>
            <div class="fgrp">
                <label class="flbl">Total Amount</label>
                <input type="text" class="finp" id="am_total" readonly style="background:#f0fdf4;color:#16a34a;font-weight:800" placeholder="0.00">
            </div>
        </div>

        <!-- ── Cheque Assignment Section ── -->
        <div class="sec-div">
            <div class="sec-div-line"></div>
            <div class="sec-div-lbl"><i class="fa-solid fa-book-bookmark" style="color:#7c3aed"></i> Cheque Assignment</div>
            <div class="sec-div-line"></div>
        </div>

        <div class="fgrp">
            <label class="flbl">Company Bank Account</label>
            <select class="finp" id="am_bank_account" style="width:100%" onchange="onBankChange()">
                <option value="">-- Select Bank Account --</option>
            </select>
        </div>

        <div id="am_book_loading" style="display:none;font-size:13px;color:#94a3b8;padding:6px 0"><i class="fa-solid fa-spinner fa-spin"></i> Loading cheque books…</div>
        <div id="am_book_empty"   style="display:none;font-size:13px;color:#94a3b8;padding:6px 0"><i class="fa-solid fa-circle-info"></i> No active cheque books for this bank account.</div>
        <div class="cb-list" id="am_book_list"></div>
        <input type="hidden" id="am_book_id" value="">

        <div id="am_cheque_section" style="display:none">
            <div class="fgrp">
                <label class="flbl">Cheque No. <span style="color:#ef4444">*</span></label>
                <input type="text" class="finp" id="am_cheque_no" placeholder="Auto-filled · or type manually"
                    style="font-family:monospace;font-weight:700;font-size:15px"
                    oninput="onChequeNoInput()">
                <div class="chq-hint" id="am_chq_hint"><i class="fa-solid fa-circle-info" style="color:#d1d5db"></i> <span>Select a cheque book above to auto-fill</span></div>
                <div class="chq-leaf-wrap" id="am_leaf_wrap">
                    <div style="display:flex;justify-content:space-between;margin-bottom:3px">
                        <span style="font-size:11px;font-weight:600;color:#64748b">Leaf usage</span>
                        <span id="am_leaf_label" style="font-size:11px;font-weight:700;color:#7c3aed"></span>
                    </div>
                    <div class="cb-leaf-bar" style="height:8px"><div id="am_leaf_fill" class="cb-leaf-fill" style="width:0%;background:#22c55e"></div></div>
                    <div class="chq-leaf-stats">
                        <span class="cb-stat-g"><i class="fa-solid fa-check-circle"></i> <span id="am_leaves_rem">—</span> remaining</span>
                        <span class="cb-stat-x">/ <span id="am_leaves_tot">—</span> total</span>
                        <span class="cb-stat-a"><i class="fa-solid fa-ban"></i> <span id="am_leaves_canc">—</span> cancelled</span>
                    </div>
                </div>
                <div class="cancel-box" id="am_cancel_box">
                    <div class="cancel-box-title"><i class="fa-solid fa-ban"></i> Cancel this cheque &amp; load next</div>
                    <div class="fgrp" style="margin-bottom:10px">
                        <label class="flbl">Reason <span style="color:#ef4444">*</span></label>
                        <textarea class="finp" id="am_cancel_reason" rows="2" placeholder="e.g. Cheque damaged or misprinted…"></textarea>
                    </div>
                    <button class="btn btn-amber btn-sm" onclick="cancelCurrentCheque()"><i class="fa-solid fa-rotate-right"></i> Cancel &amp; Load Next</button>
                </div>
                <div style="margin-top:6px">
                    <button class="btn btn-light btn-xs" id="am_toggle_cancel" style="display:none" onclick="toggleCancelBox()"><i class="fa-solid fa-ban"></i> Cancel this cheque</button>
                </div>
            </div>

            <div class="form-row2">
                <div class="fgrp">
                    <label class="flbl">Cheque Date</label>
                    <input type="date" class="finp" id="am_cheque_date">
                </div>
                <div class="fgrp">
                    <label class="flbl">Common Date <span class="opt">(optional)</span></label>
                    <input type="date" class="finp" id="am_common_date">
                    <div style="font-size:11px;color:#94a3b8;margin-top:4px"><i class="fa-solid fa-circle-info"></i> Shared date across multiple cheques (e.g. batch banking date)</div>
                </div>
            </div>
        </div>
    </div>
    <div class="mo-footer">
        <button class="btn btn-light" onclick="closeAckModal()">Cancel</button>
        <button class="btn btn-navy" id="saveAckBtn" onclick="saveAck()"><i class="fa-solid fa-floppy-disk"></i> Save</button>
    </div>
</div>
</div>

<!-- ══════════════════════════════════════════════════════
     VIEW MODAL
══════════════════════════════════════════════════════ -->
<div class="mo" id="viewModal">
<div class="mo-box">
    <div class="mo-hdr mo-hdr-green">
        <div class="mo-hdr-title"><i class="fa-solid fa-eye"></i><span id="vm_title">Acknowledgment Details</span></div>
        <div style="display:flex;gap:8px;align-items:center">
            <button class="btn btn-sm btn-print" id="vm_print_btn" onclick="printAck()"><i class="fa-solid fa-print"></i> Print Acknowledgment</button>
            <button class="mo-close" onclick="closeViewModal()"><i class="fa-solid fa-xmark"></i> Close</button>
        </div>
    </div>
    <div class="info-strip" id="vm_strip">
        <div class="info-cell"><div class="ic-lbl">Name</div><div class="ic-val" id="vm_name">—</div></div>
        <div class="info-cell"><div class="ic-lbl">Invoice No.</div><div class="ic-val navy" id="vm_inv_no">—</div></div>
        <div class="info-cell"><div class="ic-lbl">Net Amt</div><div class="ic-val sky" id="vm_net">—</div></div>
        <div class="info-cell"><div class="ic-lbl">VAT</div><div class="ic-val purple" id="vm_vat">—</div></div>
        <div class="info-cell"><div class="ic-lbl">Total</div><div class="ic-val green" id="vm_total">—</div></div>
        <div class="info-cell"><div class="ic-lbl">Status</div><div class="ic-val" id="vm_status">—</div></div>
    </div>
    <div class="mo-body">
        <div class="vw-section">
            <div class="vw-section-title"><i class="fa-solid fa-circle-info" style="color:#1e3a5f"></i> Claim Details</div>
            <div class="vw-grid">
                <div class="vw-item"><div class="vw-lbl">Type</div><div class="vw-val" id="vm_type">—</div></div>
                <div class="vw-item"><div class="vw-lbl">Code</div><div class="vw-val mono" id="vm_code">—</div></div>
                <div class="vw-item"><div class="vw-lbl">Invoice Date</div><div class="vw-val" id="vm_inv_date">—</div></div>
                <div class="vw-item"><div class="vw-lbl">Entity</div><div class="vw-val" id="vm_entity">—</div></div>
                <div class="vw-item"><div class="vw-lbl">Claim Type</div><div class="vw-val" id="vm_claim_type">—</div></div>
                <div class="vw-item" style="grid-column:1/-1"><div class="vw-lbl">Description</div><div class="vw-val" id="vm_desc" style="font-size:13px;white-space:normal;font-weight:600">—</div></div>
            </div>
        </div>
        <div class="vw-section">
            <div class="vw-section-title"><i class="fa-solid fa-book-bookmark" style="color:#7c3aed"></i> Cheque Details</div>
            <div class="vw-grid">
                <div class="vw-item"><div class="vw-lbl">Cheque No.</div><div class="vw-val mono" id="vm_cheque_no" style="font-size:16px">—</div></div>
                <div class="vw-item"><div class="vw-lbl">Cheque Date</div><div class="vw-val amber" id="vm_cheque_date">—</div></div>
                <div class="vw-item"><div class="vw-lbl">Common Date</div><div class="vw-val" id="vm_common_date">—</div></div>
                <div class="vw-item"><div class="vw-lbl">Bank Account</div><div class="vw-val" id="vm_bank_acc">—</div></div>
                <div class="vw-item"><div class="vw-lbl">Bank Name</div><div class="vw-val" id="vm_bank_name">—</div></div>
                <div class="vw-item"><div class="vw-lbl">Book Range</div><div class="vw-val mono" id="vm_book_range">—</div></div>
            </div>
        </div>
    </div>
    <div class="mo-footer" style="justify-content:space-between">
        <button class="btn btn-light btn-sm" onclick="openEditFromView()"><i class="fa-solid fa-pen"></i> Edit</button>
        <div style="display:flex;gap:10px">
            <button class="btn btn-light" onclick="closeViewModal()">Close</button>
            <button class="btn btn-print" onclick="printAck()"><i class="fa-solid fa-print"></i> Print Acknowledgment</button>
        </div>
    </div>
</div>
</div>

<div id="toast"></div>

<script>
/* ══ Globals ══ */
const fN  = v => parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
const eh  = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const $id = id => document.getElementById(id);
function toast(msg, type){ const t=$id('toast');t.textContent=msg;t.className=type;t.style.display='block';clearTimeout(t._t);t._t=setTimeout(()=>t.style.display='none',4200); }

let _activeTab     = 'customer';
let _debTimer      = null;
let _bankAccounts  = [];
let _selectedBookId   = 0;
let _currentChequeNo  = '';
let _currentBookData  = null;
let _chequeTimer      = null;
let _currentViewId    = 0;

/* ══ Tabs ══ */
function switchTab(tab) {
    _activeTab = tab;
    ['customer','employee'].forEach(t => {
        $id('tab-' + t).classList.toggle('active', t === tab);
    });
    $id('tblTabLabel').textContent = tab === 'customer' ? 'Customer' : 'Employee';
    clearFilters(false);
    loadTable();
}

/* ══ Filters ══ */
function clearFilters(reload = true) {
    ['filterSearch','filterFrom','filterTo'].forEach(id => $id(id).value = '');
    $id('filterStatus').value = '';
    $id('tblSearch').value = '';
    if (reload) loadTable();
}
function debounceLoad(){ clearTimeout(_debTimer); _debTimer = setTimeout(loadTable, 380); }
function quickFilter() {
    const q = $id('tblSearch').value.toLowerCase();
    let vis = 0;
    document.querySelectorAll('#tableBody tr').forEach(tr => {
        const show = !q || (tr.dataset.search||'').includes(q);
        tr.style.display = show ? '' : 'none';
        if (show) vis++;
    });
    $id('rowCountBadge').textContent = vis;
}

/* ══ Load Table ══ */
async function loadTable() {
    $id('skeletonArea').style.display = 'block';
    $id('tableArea').style.display = 'none';
    const params = new URLSearchParams({
        action      : 'list',
        source_type : _activeTab,
        search      : $id('filterSearch').value,
        date_from   : $id('filterFrom').value,
        date_to     : $id('filterTo').value,
        status      : $id('filterStatus').value,
    });
    try {
        const res  = await fetch('dl_cheque_ack_new.php?' + params);
        const data = await res.json();
        if (!data.success) throw new Error('Load failed');
        renderTable(data.rows || [], data.totals || {});
        // Update tab badge
        $id('tab-badge-' + _activeTab).textContent = data.totals.cnt || 0;
    } catch(e) {
        toast('Error loading: ' + e.message, 'error');
        $id('skeletonArea').style.display = 'none';
        $id('tableArea').style.display = 'block';
    }
}

function renderTable(rows, totals) {
    $id('skeletonArea').style.display = 'none';
    $id('tableArea').style.display = 'block';
    $id('rowCountBadge').textContent = rows.length;
    const empty = $id('emptyState');
    const body  = $id('tableBody');
    const foot  = $id('tableFoot');
    if (!rows.length) { body.innerHTML = ''; foot.innerHTML = ''; empty.style.display = 'block'; return; }
    empty.style.display = 'none';
    const typePill = _activeTab === 'customer'
        ? `<span class="type-cust"><i class="fa-solid fa-user"></i> Customer</span>`
        : `<span class="type-emp"><i class="fa-solid fa-id-badge"></i> Employee</span>`;
    let html = '';
    rows.forEach((r, idx) => {
        const statusHtml = {
            pending  : '<span class="status-pill status-pending"><i class="fa-solid fa-hourglass-half"></i> Pending</span>',
            assigned : '<span class="status-pill status-assigned"><i class="fa-solid fa-check-circle"></i> Assigned</span>',
            printed  : '<span class="status-pill status-printed"><i class="fa-solid fa-print"></i> Printed</span>',
        }[r.status] || '<span class="status-pill status-pending">Pending</span>';
        const chequeDisplay = r.cheque_no
            ? `<code style="font-family:monospace;font-weight:800;color:#7c3aed;background:#f5f3ff;padding:2px 7px;border-radius:5px">${eh(r.cheque_no)}</code>`
            : '<span style="color:#d1d5db">—</span>';
        html += `<tr id="row-${r.id}" data-search="${String(r.ref_name||'').toLowerCase()+' '+(r.tax_invoice_no||'').toLowerCase()+' '+(r.ref_code||'').toLowerCase()+' '+(r.claim_description||'').toLowerCase()}">
            <td style="color:#94a3b8;font-size:11px">${idx+1}</td>
            <td>
                <div style="font-weight:700;color:#1e293b;font-size:13px">${eh(r.ref_name||'—')}</div>
                <div style="font-size:10px;color:#94a3b8;font-family:monospace">[${eh(r.ref_code||'—')}]</div>
            </td>
            <td style="font-family:monospace;font-weight:700;color:#1e3a5f">${eh(r.tax_invoice_no||'—')}</td>
            <td style="font-size:12px">${r.invoice_date||'—'}</td>
            <td><span style="background:#e0e7ff;color:#3730a3;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700">${eh(r.entity||'—')}</span></td>
            <td class="num" style="color:#0369a1;font-weight:700">${fN(r.actual_amount)}</td>
            <td class="num" style="color:#7c3aed;font-weight:700">${fN(r.vat_amount)}</td>
            <td class="num" style="color:#16a34a;font-weight:800;font-size:14px">${fN(r.total_amount)}</td>
            <td>${chequeDisplay}</td>
            <td style="font-size:12px">${r.cheque_date||'<span style="color:#d1d5db">—</span>'}</td>
            <td>${statusHtml}</td>
            <td>
                <div class="act-btns">
                    <button class="abtn abtn-view"   title="View Details"      onclick="openViewModal(${r.id})"><i class="fa-solid fa-eye"></i></button>
                    <button class="abtn abtn-assign" title="Assign / Edit"     onclick="openEditAck(${r.id})"><i class="fa-solid fa-pen"></i></button>
                    <button class="abtn abtn-print"  title="Print Acknowledgment" onclick="printAckById(${r.id})"><i class="fa-solid fa-print"></i></button>
                    <button class="abtn abtn-del"    title="Delete"            onclick="deleteAck(${r.id})"><i class="fa-solid fa-trash"></i></button>
                </div>
            </td>
        </tr>`;
    });
    body.innerHTML = html;
    foot.innerHTML = `<tr>
        <td colspan="5" style="color:#64748b;font-size:11px;font-weight:600;text-align:right">Totals (${rows.length} records)</td>
        <td class="num" style="color:#0369a1">${fN(totals.sum_net)}</td>
        <td class="num" style="color:#7c3aed">${fN(totals.sum_vat)}</td>
        <td class="num" style="color:#16a34a">${fN(totals.sum_tot)}</td>
        <td colspan="4"></td>
    </tr>`;
}

/* ══ Load bank accounts ══ */
async function loadBankAccounts() {
    if (_bankAccounts.length > 0) return;
    try {
        const res  = await fetch('dl_cheque_ack_new.php?action=get_bank_accounts');
        const data = await res.json();
        if (data.success) {
            _bankAccounts = data.data || [];
            const sel = $id('am_bank_account');
            sel.innerHTML = '<option value="">-- Select Bank Account --</option>';
            _bankAccounts.forEach(b => {
                const opt = document.createElement('option');
                opt.value = b.id;
                opt.dataset.accountNo = b.account_no;
                opt.dataset.bankName  = b.bank_name;
                opt.textContent = `[${(b.account_type||'').toUpperCase()}] ${b.account_name} — ${b.account_no} (${b.bank_name||'?'})`;
                sel.appendChild(opt);
            });
        }
    } catch(e) {}
}

/* ══ Bank / Cheque book change ══ */
async function onBankChange() {
    const bankId = $id('am_bank_account').value;
    resetChequeSection();
    if (!bankId) return;
    $id('am_book_loading').style.display = 'block';
    $id('am_book_empty').style.display   = 'none';
    $id('am_book_list').innerHTML        = '';
    try {
        const res  = await fetch('dl_cheque_ack_new.php?action=get_cc_books&bank_account_id=' + encodeURIComponent(bankId));
        const data = await res.json();
        $id('am_book_loading').style.display = 'none';
        if (!data.success || !data.data.length) { $id('am_book_empty').style.display = 'block'; return; }
        renderBookList(data.data);
    } catch(e) {
        $id('am_book_loading').style.display = 'none';
        $id('am_book_empty').style.display   = 'block';
    }
}

function renderBookList(books) {
    const list = $id('am_book_list');
    let html = '';
    books.forEach(bk => {
        const total    = parseInt(bk.total_leaves || 0);
        const used     = parseInt(bk.used_count || 0);
        const cancelled= parseInt(bk.cancelled_count || 0);
        const avail    = total - used - cancelled;
        const pct      = total > 0 ? Math.min(100, Math.round((used + cancelled) / total * 100)) : 100;
        const barColor = avail <= 0 ? '#ef4444' : (avail <= 5 ? '#f59e0b' : '#22c55e');
        const next     = bk.next_cheque_no || '—';
        const exhausted= avail <= 0;
        html += `<div class="cb-card${exhausted ? ' exhausted' : ''}" id="book-card-${bk.id}"
                    onclick="${exhausted ? "toast('This cheque book has no remaining leaves.','warn')" : `selectBook(${bk.id},'${next.replace(/'/g,"\\'")}')` }">
            <div class="cb-top">
                <div>
                    <div class="cb-name"><i class="fa-solid fa-book-bookmark" style="color:#7c3aed;margin-right:5px"></i>${eh(bk.customer_name||'CC Book')}</div>
                    <div class="cb-bank"><i class="fa-solid fa-university" style="margin-right:3px;color:#94a3b8"></i>${eh(bk.bank_name_label||'')} (${eh(bk.account_no||'')})</div>
                    <div style="font-size:11px;color:#94a3b8;margin-top:2px">Range: <code style="background:#f1f5f9;padding:1px 5px;border-radius:3px">${eh(bk.leaf_no_start||'')}–${eh(bk.leaf_no_end||'')}</code></div>
                </div>
                <div style="text-align:right">
                    ${exhausted
                        ? '<span style="background:#fef2f2;color:#991b1b;padding:3px 9px;border-radius:6px;font-size:11px;font-weight:700"><i class="fa-solid fa-ban"></i> Exhausted</span>'
                        : `<div style="font-size:10px;color:#94a3b8;margin-bottom:3px">Next cheque</div><span class="cb-next">${eh(next)}</span>`}
                </div>
            </div>
            <div class="cb-leaf-bar"><div class="cb-leaf-fill" style="width:${pct}%;background:${barColor}"></div></div>
            <div class="cb-stats">
                <span class="${avail>5?'cb-stat-g':(avail>0?'cb-stat-a':'cb-stat-r')}"><i class="fa-solid fa-leaf"></i> ${avail} available</span>
                <span class="cb-stat-x">/ ${total} total</span>
                <span class="cb-stat-x"><i class="fa-solid fa-check"></i> ${used} used</span>
                ${cancelled > 0 ? `<span class="cb-stat-a"><i class="fa-solid fa-ban"></i> ${cancelled} cancelled</span>` : ''}
            </div>
        </div>`;
    });
    list.innerHTML = html;
}

function selectBook(bookId, nextChequeNo) {
    _selectedBookId  = bookId;
    _currentChequeNo = nextChequeNo;
    document.querySelectorAll('.cb-card').forEach(el => {
        el.classList.remove('selected');
        if (el.id === 'book-card-' + bookId) el.classList.add('selected');
    });
    $id('am_cheque_section').style.display = 'block';
    $id('am_cheque_no').value = nextChequeNo;
    $id('am_toggle_cancel').style.display  = 'inline-flex';
    $id('am_cancel_box').style.display     = 'none';
    $id('am_cancel_reason').value          = '';
    refreshChequeHint(bookId, nextChequeNo);
}

async function refreshChequeHint(bookId, chequeNo) {
    const hint    = $id('am_chq_hint');
    const leafWrap= $id('am_leaf_wrap');
    if (!bookId || !chequeNo) { leafWrap.style.display = 'none'; return; }
    hint.className = 'chq-hint';
    hint.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span>Looking up…</span>';
    try {
        const res  = await fetch('dl_cheque_ack_new.php?action=lookup_cheque&cheque_no=' + encodeURIComponent(chequeNo));
        const data = await res.json();
        if (!data.success || !data.data) {
            hint.className = 'chq-hint err';
            hint.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> <span>Not found in any active CC book</span>';
            leafWrap.style.display = 'none';
            return;
        }
        const b = data.data; _currentBookData = b;
        if (b.is_used) {
            hint.className = 'chq-hint err';
            hint.innerHTML = '<i class="fa-solid fa-circle-xmark" style="color:#dc2626"></i> <span style="color:#dc2626">Already used.</span>';
            leafWrap.style.display = 'none';
            return;
        }
        if (b.is_cancelled) {
            hint.className = 'chq-hint err';
            hint.innerHTML = '<i class="fa-solid fa-ban" style="color:#d97706"></i> <span style="color:#d97706">Cancelled.</span>';
            leafWrap.style.display = 'none';
            return;
        }
        const total = parseInt(b.total_leaves||0), rem = parseInt(b.leaves_remaining||0),
              used  = parseInt(b.used_count||0), canc = parseInt(b.cancelled_count||0);
        const pct      = total > 0 ? Math.min(100, Math.round((used + canc + 1) / total * 100)) : 100;
        const barColor = rem <= 0 ? '#ef4444' : (rem <= 5 ? '#f59e0b' : '#22c55e');
        hint.className = 'chq-hint ok';
        hint.innerHTML = `<i class="fa-solid fa-book-bookmark" style="color:#7c3aed"></i> <span>Found · <strong>${eh(b.leaf_no_start)}–${eh(b.leaf_no_end)}</strong></span><span style="color:#94a3b8"> | </span><span style="color:${barColor};font-weight:700">${rem} leaves left after issue</span>`;
        leafWrap.style.display = 'block';
        $id('am_leaf_fill').style.width      = pct + '%';
        $id('am_leaf_fill').style.background = barColor;
        $id('am_leaf_label').textContent = `${Math.max(0,total-used-canc-1)} of ${total} remaining`;
        $id('am_leaves_rem').textContent = rem;
        $id('am_leaves_tot').textContent = total;
        $id('am_leaves_canc').textContent= canc;
    } catch(e) {
        hint.className = 'chq-hint err';
        hint.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> <span>Lookup failed</span>';
        leafWrap.style.display = 'none';
    }
}

function onChequeNoInput() {
    const val = $id('am_cheque_no').value.trim();
    _currentChequeNo = val;
    clearTimeout(_chequeTimer);
    _chequeTimer = setTimeout(() => refreshChequeHint(_selectedBookId, val), 500);
}

function toggleCancelBox() {
    const box = $id('am_cancel_box');
    box.style.display = box.style.display !== 'none' && box.style.display !== '' ? 'none' : 'block';
}

async function cancelCurrentCheque() {
    const reason = $id('am_cancel_reason').value.trim();
    if (!reason) { toast('Please enter a reason.', 'error'); return; }
    if (!_selectedBookId || !_currentChequeNo) { toast('No cheque book selected.', 'error'); return; }
    const btn = document.querySelector('#am_cancel_box .btn-amber');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Cancelling…';
    const fd = new FormData();
    fd.append('book_id', _selectedBookId);
    fd.append('cheque_no', _currentChequeNo);
    fd.append('reason', reason);
    try {
        const res  = await fetch('dl_cheque_ack_new.php?action=cancel_cheque', {method:'POST', body:fd});
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'Cancel failed');
        toast('Cheque ' + _currentChequeNo + ' cancelled. Loading next…', 'warn');
        if (data.next) {
            _currentChequeNo = data.next.cheque_no;
            $id('am_cheque_no').value = data.next.cheque_no;
            $id('am_cancel_box').style.display = 'none';
            $id('am_cancel_reason').value = '';
            await refreshChequeHint(_selectedBookId, data.next.cheque_no);
            await silentRefreshBooks();
        } else {
            toast('No more available cheques in this book!', 'error');
            $id('am_cheque_no').value = '';
            $id('am_leaf_wrap').style.display = 'none';
        }
    } catch(e) { toast('Error: ' + e.message, 'error'); }
    finally { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-rotate-right"></i> Cancel &amp; Load Next'; }
}

async function silentRefreshBooks() {
    const bankId = $id('am_bank_account').value;
    if (!bankId) return;
    try {
        const res  = await fetch('dl_cheque_ack_new.php?action=get_cc_books&bank_account_id=' + encodeURIComponent(bankId));
        const data = await res.json();
        if (data.success && data.data.length) {
            renderBookList(data.data);
            if (_selectedBookId) {
                const card = $id('book-card-' + _selectedBookId);
                if (card) card.classList.add('selected');
            }
        }
    } catch(e) {}
}

function resetChequeSection() {
    _selectedBookId = 0; _currentChequeNo = ''; _currentBookData = null;
    $id('am_book_list').innerHTML       = '';
    $id('am_book_loading').style.display= 'none';
    $id('am_book_empty').style.display  = 'none';
    $id('am_cheque_section').style.display = 'none';
    $id('am_leaf_wrap').style.display   = 'none';
    $id('am_cancel_box').style.display  = 'none';
    $id('am_cheque_no').value           = '';
    $id('am_cancel_reason').value       = '';
    $id('am_chq_hint').className        = 'chq-hint';
    $id('am_chq_hint').innerHTML        = '<i class="fa-solid fa-circle-info" style="color:#d1d5db"></i><span>Select a cheque book above to auto-fill</span>';
    $id('am_toggle_cancel').style.display = 'none';
    $id('am_cheque_date').value  = '';
    $id('am_common_date').value  = '';
}

/* ══ Amount calc ══ */
function calcAmounts() {
    const net = parseFloat($id('am_net').value || 0) || 0;
    const vat = Math.round(net * 0.18 * 100) / 100;
    $id('am_vat').value   = fN(vat);
    $id('am_total').value = fN(net + vat);
}

/* ══ Modal: set type ══ */
function setAckType(type) {
    $id('am_source_type').value = type;
    const isCust = type === 'customer';
    $id('am_type_cust').className = 'btn btn-sm ' + (isCust ? 'btn-navy'  : 'btn-light');
    $id('am_type_emp').className  = 'btn btn-sm ' + (!isCust ? 'btn-navy' : 'btn-light');
    $id('am_ref_label').innerHTML = (isCust ? 'Customer' : 'Employee') + ' <span style="color:#ef4444">*</span>';
    reinitRefSelect(type);
}

function reinitRefSelect(type) {
    if ($('#am_ref').hasClass('select2-hidden-accessible')) $('#am_ref').select2('destroy');
    $('#am_ref').select2({
        dropdownParent: $('#ackModal'),
        placeholder   : type === 'customer' ? '— Search Customer —' : '— Search Employee —',
        allowClear    : true,
        minimumInputLength: 0,
        ajax: {
            url: 'dl_cheque_ack_new.php',
            dataType: 'json', delay: 250,
            data: params => ({ action: type === 'customer' ? 'search_customers' : 'search_employees', q: params.term || '' }),
            processResults: data => ({ results: data.results || [] }),
            cache: true,
        }
    });
}

/* ══ Open new ack modal ══ */
async function openNewAck() {
    resetAckModal();
    $id('ackModalTitle').textContent = 'New Acknowledgment';
    setAckType(_activeTab); // default to current tab's type
    $id('ackModal').classList.add('open');
    document.body.style.overflow = 'hidden';
    await loadBankAccounts();
}

/* ══ Open edit ack modal ══ */
async function openEditAck(id) {
    resetAckModal();
    $id('ackModalTitle').textContent = 'Edit Acknowledgment #' + id;
    $id('am_id').value = id;
    $id('ackModal').classList.add('open');
    document.body.style.overflow = 'hidden';
    await loadBankAccounts();
    // Load data
    try {
        const res  = await fetch('dl_cheque_ack_new.php?action=get_ack&id=' + id);
        const data = await res.json();
        if (!data.success || !data.data) { toast('Could not load record.', 'error'); return; }
        const d = data.data;
        // Set type first
        setAckType(d.source_type || 'customer');
        // Set ref
        if (d.ref_id && d.ref_name) {
            const opt = new Option('[' + (d.ref_code||'') + '] ' + d.ref_name, d.ref_id, true, true);
            $('#am_ref').append(opt).trigger('change');
        }
        $id('am_invoice_no').value    = d.tax_invoice_no  || '';
        $id('am_invoice_date').value  = d.invoice_date    || '';
        $id('am_entity').value        = d.entity          || '';
        $id('am_claim_type').value    = d.claim_type      || '';
        $id('am_claim_desc').value    = d.claim_description || '';
        $id('am_net').value           = parseFloat(d.actual_amount || 0).toFixed(2);
        calcAmounts();
        // Bank + cheque book
        if (d.bank_account_id) {
            $id('am_bank_account').value = d.bank_account_id;
            await onBankChange();
            // Re-select the book
            if (d.cheque_book_id) {
                _selectedBookId = parseInt(d.cheque_book_id);
                const card = $id('book-card-' + _selectedBookId);
                if (card) {
                    card.classList.add('selected');
                    $id('am_cheque_section').style.display = 'block';
                    $id('am_toggle_cancel').style.display  = 'inline-flex';
                }
            }
            if (d.cheque_no) {
                $id('am_cheque_no').value  = d.cheque_no;
                _currentChequeNo = d.cheque_no;
                $id('am_cheque_section').style.display = 'block';
                await refreshChequeHint(_selectedBookId, d.cheque_no);
            }
        }
        $id('am_cheque_date').value  = d.cheque_date  || '';
        $id('am_common_date').value  = d.common_date  || '';
    } catch(e) { toast('Load error: ' + e.message, 'error'); }
}

function resetAckModal() {
    $id('am_id').value = '0';
    ['am_invoice_no','am_invoice_date','am_entity','am_claim_type','am_claim_desc','am_net'].forEach(id => $id(id).value = '');
    $id('am_vat').value   = '0.00';
    $id('am_total').value = '0.00';
    $id('am_bank_account').value = '';
    resetChequeSection();
    if ($('#am_ref').hasClass('select2-hidden-accessible')) $('#am_ref').val(null).trigger('change');
}

function closeAckModal() {
    $id('ackModal').classList.remove('open');
    document.body.style.overflow = '';
}

/* ══ Save ack ══ */
async function saveAck() {
    const refSel   = $('#am_ref');
    const refVal   = refSel.val();
    const refText  = refSel.find(':selected').text();
    if (!refVal) { toast('Please select a ' + $id('am_source_type').value + '.', 'error'); return; }
    const net      = parseFloat($id('am_net').value || 0) || 0;
    if (!(net > 0)) { toast('Please enter a net amount greater than 0.', 'error'); return; }
    const match    = refText.match(/^\[([^\]]+)\]\s+(.+)$/);
    const refCode  = match ? match[1] : '';
    const refName  = match ? match[2] : refText;
    const bankSel  = $id('am_bank_account');
    const bankOpt  = bankSel.options[bankSel.selectedIndex];
    const vat      = Math.round(net * 0.18 * 100) / 100;
    const total    = net + vat;

    const btn = $id('saveAckBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData();
    fd.append('id',                 $id('am_id').value);
    fd.append('source_type',        $id('am_source_type').value);
    fd.append('ref_id',             refVal);
    fd.append('ref_code',           refCode);
    fd.append('ref_name',           refName);
    fd.append('tax_invoice_no',     $id('am_invoice_no').value);
    fd.append('invoice_date',       $id('am_invoice_date').value);
    fd.append('entity',             $id('am_entity').value);
    fd.append('claim_description',  $id('am_claim_desc').value);
    fd.append('claim_type',         $id('am_claim_type').value);
    fd.append('actual_amount',      net);
    fd.append('vat_amount',         vat);
    fd.append('total_amount',       total);
    fd.append('cheque_book_id',     _selectedBookId || '');
    fd.append('cheque_no',          $id('am_cheque_no').value);
    fd.append('cheque_date',        $id('am_cheque_date').value);
    fd.append('common_date',        $id('am_common_date').value);
    fd.append('bank_account_id',    bankSel.value || '');
    fd.append('bank_account_no',    bankOpt?.dataset?.accountNo || '');
    fd.append('bank_name',          bankOpt?.dataset?.bankName  || '');

    try {
        const res  = await fetch('dl_cheque_ack_new.php?action=save_ack', {method:'POST', body:fd});
        const data = await res.json();
        btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save';
        if (!data.success) { toast('Error: ' + (data.message || 'Save failed'), 'error'); return; }
        toast(parseInt($id('am_id').value) > 0 ? 'Updated!' : 'Created!', 'success');
        closeAckModal();
        loadTable();
    } catch(e) {
        btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save';
        toast('Network error: ' + e.message, 'error');
    }
}

/* ══ View modal ══ */
async function openViewModal(id) {
    _currentViewId = id;
    $id('vm_title').textContent = 'Acknowledgment #' + id;
    $id('viewModal').classList.add('open');
    document.body.style.overflow = 'hidden';
    // Clear
    ['vm_name','vm_inv_no','vm_net','vm_vat','vm_total','vm_status','vm_type','vm_code','vm_inv_date','vm_entity','vm_claim_type','vm_desc','vm_cheque_no','vm_cheque_date','vm_common_date','vm_bank_acc','vm_bank_name','vm_book_range'].forEach(i => $id(i).textContent = '…');
    try {
        const res  = await fetch('dl_cheque_ack_new.php?action=get_ack&id=' + id);
        const data = await res.json();
        if (!data.success || !data.data) { toast('Load failed.', 'error'); return; }
        const d = data.data;
        $id('vm_name').textContent       = d.ref_name || '—';
        $id('vm_inv_no').textContent     = d.tax_invoice_no || '—';
        $id('vm_net').textContent        = fN(d.actual_amount);
        $id('vm_vat').textContent        = fN(d.vat_amount);
        $id('vm_total').textContent      = fN(d.total_amount);
        $id('vm_status').innerHTML       = {
            pending  : '<span class="status-pill status-pending"><i class="fa-solid fa-hourglass-half"></i> Pending</span>',
            assigned : '<span class="status-pill status-assigned"><i class="fa-solid fa-check-circle"></i> Assigned</span>',
            printed  : '<span class="status-pill status-printed"><i class="fa-solid fa-print"></i> Printed</span>',
        }[d.status] || '—';
        $id('vm_type').innerHTML         = d.source_type === 'customer'
            ? '<span class="type-cust"><i class="fa-solid fa-user"></i> Customer</span>'
            : '<span class="type-emp"><i class="fa-solid fa-id-badge"></i> Employee</span>';
        $id('vm_code').textContent       = d.ref_code    || '—';
        $id('vm_inv_date').textContent   = d.invoice_date || '—';
        $id('vm_entity').textContent     = d.entity       || '—';
        $id('vm_claim_type').textContent = d.claim_type   || '—';
        $id('vm_desc').textContent       = d.claim_description || '—';
        $id('vm_cheque_no').textContent  = d.cheque_no    || '—';
        $id('vm_cheque_date').textContent= d.cheque_date  || '—';
        $id('vm_common_date').textContent= d.common_date  || '—';
        $id('vm_bank_acc').textContent   = d.bank_account_no || '—';
        $id('vm_bank_name').textContent  = d.bank_name    || '—';
        $id('vm_book_range').textContent = (d.leaf_no_start && d.leaf_no_end) ? d.leaf_no_start + '–' + d.leaf_no_end : '—';
    } catch(e) { toast('Load error: ' + e.message, 'error'); }
}

function closeViewModal() {
    $id('viewModal').classList.remove('open');
    document.body.style.overflow = '';
}

function openEditFromView() {
    const id = _currentViewId;
    closeViewModal();
    if (id) openEditAck(id);
}

/* ══ Print ══ */
function printAck() {
    if (!_currentViewId) return;
    printAckById(_currentViewId);
}
function printAckById(id) {
    window.open('print_cheque_ack_new.php?id=' + id, '_blank');
}

/* ══ Delete ══ */
async function deleteAck(id) {
    if (!confirm('Delete this acknowledgment record?\n\nThis cannot be undone.')) return;
    const fd = new FormData(); fd.append('id', id);
    const res  = await fetch('dl_cheque_ack_new.php?action=delete_ack', {method:'POST', body:fd});
    const data = await res.json();
    if (data.success) { toast('Deleted.', 'warn'); loadTable(); }
    else toast('Delete failed.', 'error');
}

/* ══ Backdrop close ══ */
$id('ackModal').addEventListener('click', e => { if(e.target===$id('ackModal')) closeAckModal(); });
$id('viewModal').addEventListener('click', e => { if(e.target===$id('viewModal')) closeViewModal(); });

/* ══ Init ══ */
document.addEventListener('DOMContentLoaded', () => { loadTable(); });
</script>

<?php include 'footer.php'; ?>
