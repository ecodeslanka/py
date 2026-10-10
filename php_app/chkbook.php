<?php
include 'config.php';

// ══════════════════════════════════════════════════════════════════
//  TABLE SETUP & AJAX — must be BEFORE header.php
// ══════════════════════════════════════════════════════════════════

// Ensure tables exist
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

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_book_entries (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    cheque_book_id      INT NOT NULL,
    bank_account_id     INT NOT NULL,
    company             ENUM('USLL','ULCL') NOT NULL,
    cheque_leaf_no      VARCHAR(50) NOT NULL,
    payee               VARCHAR(255) NULL,
    amount              DECIMAL(14,2) NULL,
    cheque_date         DATE NULL,
    sent_date           DATE NULL,
    remark              TEXT NULL,
    status              ENUM('Issued','Cleared','Bounced','Cancelled','Pending') NOT NULL DEFAULT 'Pending',
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_book(cheque_book_id),
    INDEX idx_bank(bank_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── AJAX ──────────────────────────────────────────────────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    // ── Save cheque book ─────────────────────────────────────────
    if ($action === 'save_book' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id             = intval($_POST['id'] ?? 0);
        $bank_account_id= intval($_POST['bank_account_id'] ?? 0);
        $company        = in_array($_POST['company'] ?? '', ['USLL','ULCL']) ? $_POST['company'] : '';
        $leaf_start     = mysqli_real_escape_string($conn, trim($_POST['leaf_no_start'] ?? ''));
        $leaf_end       = mysqli_real_escape_string($conn, trim($_POST['leaf_no_end'] ?? ''));
        $sent_date      = mysqli_real_escape_string($conn, trim($_POST['sent_date'] ?? ''));
        $remark         = mysqli_real_escape_string($conn, trim($_POST['remark'] ?? ''));
        $status         = in_array($_POST['status'] ?? '', ['Active','Used','Cancelled']) ? $_POST['status'] : 'Active';

        if (!$bank_account_id || !$company || !$leaf_start || !$leaf_end) {
            echo json_encode(['success'=>false,'message'=>'Bank Account, Company, Leaf Start and Leaf End are required.']);
            exit;
        }

        // Auto-calculate leaf count
        $start_num = intval(preg_replace('/\D/','',$leaf_start));
        $end_num   = intval(preg_replace('/\D/','',$leaf_end));
        $leaf_count = ($end_num >= $start_num) ? ($end_num - $start_num + 1) : 0;
        $sent_sql = $sent_date ? "'$sent_date'" : 'NULL';

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
        $id = intval($_POST['id'] ?? 0);
        // Check for entries
        $cnt = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as c FROM cheque_book_entries WHERE cheque_book_id=$id"));
        if ($cnt['c'] > 0) {
            echo json_encode(['success'=>false,'message'=>'Cannot delete: this cheque book has '.$cnt['c'].' entries. Delete entries first.']);
            exit;
        }
        $ok = mysqli_query($conn,"DELETE FROM cheque_books WHERE id=$id");
        echo json_encode(['success'=>(bool)$ok,'message'=>$ok?'':mysqli_error($conn)]);
        exit;
    }

    // ── Get cheque books for dropdown ───────────────────────────
    if ($action === 'get_books_for_bank') {
        $bank_id = intval($_GET['bank_id'] ?? 0);
        $company = mysqli_real_escape_string($conn, $_GET['company'] ?? '');
        $where   = "cb.bank_account_id=$bank_id AND cb.status='Active'";
        if ($company) $where .= " AND cb.company='$company'";
        $res  = mysqli_query($conn, "SELECT cb.id,cb.leaf_no_start,cb.leaf_no_end,cb.leaf_count,cb.company FROM cheque_books cb WHERE $where ORDER BY cb.id DESC");
        $data = [];
        while ($r = mysqli_fetch_assoc($res)) $data[] = $r;
        echo json_encode(['success'=>true,'data'=>$data]);
        exit;
    }

    // ── Save cheque entry ────────────────────────────────────────
    if ($action === 'save_entry' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id             = intval($_POST['id'] ?? 0);
        $cheque_book_id = intval($_POST['cheque_book_id'] ?? 0);
        $bank_account_id= intval($_POST['bank_account_id'] ?? 0);
        $company        = in_array($_POST['company'] ?? '', ['USLL','ULCL']) ? $_POST['company'] : '';
        $cheque_leaf_no = mysqli_real_escape_string($conn, trim($_POST['cheque_leaf_no'] ?? ''));
        $payee          = mysqli_real_escape_string($conn, trim($_POST['payee'] ?? ''));
        $amount         = is_numeric($_POST['amount'] ?? '') ? floatval($_POST['amount']) : 'NULL';
        $cheque_date    = mysqli_real_escape_string($conn, trim($_POST['cheque_date'] ?? ''));
        $sent_date      = mysqli_real_escape_string($conn, trim($_POST['sent_date'] ?? ''));
        $remark         = mysqli_real_escape_string($conn, trim($_POST['remark'] ?? ''));
        $status         = in_array($_POST['status'] ?? '', ['Issued','Cleared','Bounced','Cancelled','Pending']) ? $_POST['status'] : 'Pending';

        if (!$bank_account_id || !$company || !$cheque_leaf_no) {
            echo json_encode(['success'=>false,'message'=>'Bank Account, Company and Cheque Leaf No are required.']);
            exit;
        }

        $cd_sql   = $cheque_date ? "'$cheque_date'" : 'NULL';
        $sd_sql   = $sent_date   ? "'$sent_date'"   : 'NULL';
        $amt_sql  = is_numeric($amount) ? $amount : 'NULL';
        $cb_sql   = $cheque_book_id > 0 ? $cheque_book_id : 'NULL';

        if ($id > 0) {
            $ok = mysqli_query($conn, "UPDATE cheque_book_entries SET
                cheque_book_id=$cb_sql, bank_account_id=$bank_account_id,
                company='$company', cheque_leaf_no='$cheque_leaf_no',
                payee='$payee', amount=$amt_sql, cheque_date=$cd_sql,
                sent_date=$sd_sql, remark='$remark', status='$status'
                WHERE id=$id");
        } else {
            $ok = mysqli_query($conn, "INSERT INTO cheque_book_entries
                (cheque_book_id,bank_account_id,company,cheque_leaf_no,payee,amount,cheque_date,sent_date,remark,status)
                VALUES ($cb_sql,$bank_account_id,'$company','$cheque_leaf_no','$payee',$amt_sql,$cd_sql,$sd_sql,'$remark','$status')");
            if ($ok) $id = mysqli_insert_id($conn);
        }
        echo json_encode(['success'=>(bool)$ok,'id'=>$id,'message'=>$ok?'':mysqli_error($conn)]);
        exit;
    }

    // ── Get single entry ─────────────────────────────────────────
    if ($action === 'get_entry') {
        $id  = intval($_GET['id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT ce.*,
                CONCAT(COALESCE(NULLIF(b.bank_name,''),cba.bank_code,''),' / ',
                       COALESCE(NULLIF(bb.branch_name,''),cba.branch_code,''),' (',cba.account_no,')') AS bank_label
             FROM cheque_book_entries ce
             LEFT JOIN company_bank_accounts cba ON cba.id=ce.bank_account_id
             LEFT JOIN banks b ON b.bank_code=cba.bank_code
             LEFT JOIN bank_branches bb ON bb.bank_code=cba.bank_code AND bb.branch_code=cba.branch_code
             WHERE ce.id=$id LIMIT 1"));
        echo json_encode(['success'=>(bool)$row,'data'=>$row]);
        exit;
    }

    // ── Delete entry ─────────────────────────────────────────────
    if ($action === 'delete_entry' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = intval($_POST['id'] ?? 0);
        $ok = mysqli_query($conn,"DELETE FROM cheque_book_entries WHERE id=$id");
        echo json_encode(['success'=>(bool)$ok,'message'=>$ok?'':mysqli_error($conn)]);
        exit;
    }

    // ── Get leaf numbers for a cheque book ───────────────────────
    if ($action === 'get_leaf_nos') {
        $book_id = intval($_GET['book_id'] ?? 0);
        $book = mysqli_fetch_assoc(mysqli_query($conn,"SELECT * FROM cheque_books WHERE id=$book_id LIMIT 1"));
        if (!$book) { echo json_encode(['success'=>false,'data'=>[]]); exit; }
        $start = intval(preg_replace('/\D/','',$book['leaf_no_start']));
        $end   = intval(preg_replace('/\D/','',$book['leaf_no_end']));
        $prefix= preg_replace('/\d+$/','',$book['leaf_no_start']);
        $used_res = mysqli_query($conn,"SELECT cheque_leaf_no FROM cheque_book_entries WHERE cheque_book_id=$book_id");
        $used = [];
        while ($r = mysqli_fetch_assoc($used_res)) $used[] = $r['cheque_leaf_no'];
        $leaves = [];
        for ($i=$start; $i<=$end; $i++) {
            $no = $prefix . str_pad($i, strlen($book['leaf_no_start']) - strlen($prefix), '0', STR_PAD_LEFT);
            $leaves[] = ['no'=>$no,'used'=>in_array($no,$used)];
        }
        echo json_encode(['success'=>true,'data'=>$leaves,'book'=>$book]);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']);
    exit;
}

// ══════════════════════════════════════════════════════════════════
//  PAGE LOAD — DATA
// ══════════════════════════════════════════════════════════════════

// Filters
$tab           = isset($_GET['tab']) ? $_GET['tab'] : 'entries';
$f_company     = trim($_GET['f_company']  ?? '');
$f_bank        = intval($_GET['f_bank']   ?? 0);
$f_status      = trim($_GET['f_status']   ?? '');
$f_from        = trim($_GET['f_from']     ?? '');
$f_to          = trim($_GET['f_to']       ?? '');
$f_search      = trim($_GET['f_search']   ?? '');

// Bank accounts dropdown
$bank_accounts = [];
$br = mysqli_query($conn, "SELECT cba.id,
    CONCAT(COALESCE(NULLIF(b.bank_name,''),cba.bank_code,''),' / ',
    COALESCE(NULLIF(bb.branch_name,''),cba.branch_code,''),' (',cba.account_no,')') AS label
    FROM company_bank_accounts cba
    LEFT JOIN banks b ON b.bank_code=cba.bank_code
    LEFT JOIN bank_branches bb ON bb.bank_code=cba.bank_code AND bb.branch_code=cba.branch_code
    WHERE cba.active=1 ORDER BY cba.account_name ASC");
if ($br) while ($r = mysqli_fetch_assoc($br)) $bank_accounts[] = $r;

// ── Cheque Books list ────────────────────────────────────────────
$bk_where = [];
if ($f_company) $bk_where[] = "cb.company='".mysqli_real_escape_string($conn,$f_company)."'";
if ($f_bank)    $bk_where[] = "cb.bank_account_id=$f_bank";
if ($f_status)  $bk_where[] = "cb.status='".mysqli_real_escape_string($conn,$f_status)."'";
$bk_where_sql = $bk_where ? 'WHERE '.implode(' AND ',$bk_where) : '';

$books = [];
$bres = mysqli_query($conn,
    "SELECT cb.*,
        CONCAT(COALESCE(NULLIF(b.bank_name,''),cba.bank_code,''),' / ',
               COALESCE(NULLIF(bb.branch_name,''),cba.branch_code,'')) AS bank_label,
        cba.account_no,
        (SELECT COUNT(*) FROM cheque_book_entries ce WHERE ce.cheque_book_id=cb.id) AS used_leaves
     FROM cheque_books cb
     LEFT JOIN company_bank_accounts cba ON cba.id=cb.bank_account_id
     LEFT JOIN banks b ON b.bank_code=cba.bank_code
     LEFT JOIN bank_branches bb ON bb.bank_code=cba.bank_code AND bb.branch_code=cba.branch_code
     $bk_where_sql
     ORDER BY cb.id DESC");
if ($bres) while ($r = mysqli_fetch_assoc($bres)) $books[] = $r;

// ── Cheque Entries list ──────────────────────────────────────────
$en_where = ['1=1'];
if ($f_company) $en_where[] = "ce.company='".mysqli_real_escape_string($conn,$f_company)."'";
if ($f_bank)    $en_where[] = "ce.bank_account_id=$f_bank";
if ($f_status)  $en_where[] = "ce.status='".mysqli_real_escape_string($conn,$f_status)."'";
if ($f_from)    $en_where[] = "ce.cheque_date >= '".mysqli_real_escape_string($conn,$f_from)."'";
if ($f_to)      $en_where[] = "ce.cheque_date <= '".mysqli_real_escape_string($conn,$f_to)."'";
if ($f_search) {
    $fs = mysqli_real_escape_string($conn,$f_search);
    $en_where[] = "(ce.cheque_leaf_no LIKE '%$fs%' OR ce.payee LIKE '%$fs%' OR ce.remark LIKE '%$fs%')";
}
$en_where_sql = implode(' AND ',$en_where);

$entries = [];
$eres = mysqli_query($conn,
    "SELECT ce.*,
        CONCAT(COALESCE(NULLIF(b.bank_name,''),cba.bank_code,''),' / ',
               COALESCE(NULLIF(bb.branch_name,''),cba.branch_code,'')) AS bank_label,
        cba.account_no
     FROM cheque_book_entries ce
     LEFT JOIN company_bank_accounts cba ON cba.id=ce.bank_account_id
     LEFT JOIN banks b ON b.bank_code=cba.bank_code
     LEFT JOIN bank_branches bb ON bb.bank_code=cba.bank_code AND bb.branch_code=cba.branch_code
     WHERE $en_where_sql
     ORDER BY ce.cheque_date DESC, ce.id DESC");
if ($eres) while ($r = mysqli_fetch_assoc($eres)) $entries[] = $r;

// ── Summary stats ────────────────────────────────────────────────
$total_books   = count($books);
$total_entries = count($entries);
$total_amount  = array_sum(array_column($entries,'amount'));
$issued_count  = count(array_filter($entries, fn($e)=>$e['status']==='Issued'));
$pending_count = count(array_filter($entries, fn($e)=>$e['status']==='Pending'));

include 'header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
/* ═══════════════════════ BASE ════════════════════════════════════ */
*{box-sizing:border-box;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .18s;white-space:nowrap;}
.btn-sm{padding:6px 12px;font-size:12px;}
.btn-xs{padding:4px 9px;font-size:11px;border-radius:5px;}
.btn-primary{background:#0f172a;color:#fff;}.btn-primary:hover{background:#1e293b;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e0e0e0;}.btn-secondary:hover{background:#ececec;}
.btn-danger{background:#dc2626;color:#fff;}.btn-danger:hover{background:#b91c1c;}
.btn-success{background:#15803d;color:#fff;}.btn-success:hover{background:#166534;}
.btn-book{background:#7c3aed;color:#fff;}.btn-book:hover{background:#6d28d9;}
.btn-warning{background:#d97706;color:#fff;}.btn-warning:hover{background:#b45309;}

/* ═══════════════════════ TABS ════════════════════════════════════ */
.tab-nav{display:flex;gap:0;border-bottom:2px solid #e5e7eb;margin-bottom:22px;}
.tab-btn{padding:10px 24px;border:none;background:none;font-size:13.5px;font-weight:600;color:#6b7280;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;transition:all .2s;font-family:inherit;display:flex;align-items:center;gap:7px;}
.tab-btn.active{color:#0f172a;border-bottom-color:#0f172a;}
.tab-btn:hover:not(.active){color:#374151;background:#f9fafb;}
.tab-pane{display:none;}.tab-pane.active{display:block;}

/* ═══════════════════════ SUMMARY CARDS ══════════════════════════ */
.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:14px;margin-bottom:22px;}
.s-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 18px;position:relative;overflow:hidden;}
.s-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;}
.s-card.blue::before{background:linear-gradient(90deg,#3b82f6,#60a5fa);}
.s-card.green::before{background:linear-gradient(90deg,#22c55e,#4ade80);}
.s-card.purple::before{background:linear-gradient(90deg,#8b5cf6,#a78bfa);}
.s-card.amber::before{background:linear-gradient(90deg,#f59e0b,#fbbf24);}
.s-card.red::before{background:linear-gradient(90deg,#ef4444,#f87171);}
.s-label{font-size:10.5px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.s-value{font-size:22px;font-weight:800;color:#0f172a;line-height:1;}
.s-sub{font-size:11px;color:#9ca3af;margin-top:4px;}

/* ═══════════════════════ FILTER CARD ════════════════════════════ */
.filter-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 20px;margin-bottom:18px;}
.filter-row{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;}
.ffg{display:flex;flex-direction:column;gap:4px;}
.ffg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;}
.fctrl{padding:8px 11px;border:1px solid #e0e0e0;border-radius:7px;font-size:13px;font-family:inherit;color:#111827;background:#fff;outline:none;transition:border-color .2s;}
.fctrl:focus{border-color:#0f172a;box-shadow:0 0 0 3px rgba(15,23,42,.07);}

/* ═══════════════════════ TABLE ═══════════════════════════════════ */
.table-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:13px 18px;border-bottom:1px solid #f0f0f0;gap:10px;flex-wrap:wrap;}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px;}
.dt-wrap{overflow-x:auto;max-height:62vh;overflow-y:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.data-table thead{position:sticky;top:0;z-index:5;}
.data-table thead th{padding:10px 11px;text-align:left;font-weight:700;font-size:11px;color:#f1f5f9;background:#0f172a;white-space:nowrap;}
.data-table thead th.tr{text-align:right;}.data-table thead th.tc{text-align:center;}
.data-table tbody tr{border-bottom:1px solid #f3f4f6;transition:background .1s;}
.data-table tbody tr:hover td{background:#f8fafc;}
.data-table td{padding:9px 11px;color:#374151;vertical-align:middle;background:#fff;}
.data-table td.tr{text-align:right;}.data-table td.tc{text-align:center;}
.data-table tfoot td{padding:10px 11px;font-weight:800;font-size:12px;background:#0f172a;color:#e2e8f0;}
.data-table tfoot td.tr{text-align:right;}
.empty-state{text-align:center;padding:55px 20px;color:#9ca3af;}
.empty-state i{font-size:36px;display:block;margin-bottom:12px;opacity:.25;}
.empty-state p{font-size:14px;font-weight:500;}
.pill{padding:2px 9px;border-radius:12px;font-size:11px;font-weight:700;}
.pill-blue{background:#dbeafe;color:#1e40af;}

/* ═══════════════════════ BADGES ══════════════════════════════════ */
.badge{display:inline-block;padding:2px 9px;border-radius:10px;font-size:10.5px;font-weight:700;letter-spacing:.02em;}
.badge-usll{background:#dbeafe;color:#1e40af;}
.badge-ulcl{background:#fce7f3;color:#9d174d;}
.badge-active{background:#d1fae5;color:#065f46;}
.badge-used{background:#f3f4f6;color:#6b7280;}
.badge-cancelled{background:#fee2e2;color:#991b1b;}
.badge-issued{background:#eff6ff;color:#1d4ed8;}
.badge-cleared{background:#dcfce7;color:#166534;}
.badge-bounced{background:#fef2f2;color:#dc2626;}
.badge-pending{background:#fef9c3;color:#854d0e;}

/* leaf usage bar */
.leaf-bar{width:80px;height:7px;background:#e5e7eb;border-radius:4px;overflow:hidden;display:inline-block;vertical-align:middle;margin-left:5px;}
.leaf-bar-fill{height:100%;border-radius:4px;transition:width .3s;}

/* ═══════════════════════ MODAL ════════════════════════════════════ */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:10000;display:none;align-items:center;justify-content:center;padding:16px;}
.modal-overlay.open{display:flex;}
.modal-box{background:#fff;border-radius:12px;width:96%;max-width:680px;max-height:92vh;display:flex;flex-direction:column;box-shadow:0 24px 70px rgba(0,0,0,.25);overflow:hidden;}
.modal-header{padding:18px 24px;border-bottom:1px solid #e5e7eb;background:#fafafa;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.modal-title{font-size:16px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:9px;}
.modal-close{width:30px;height:30px;border-radius:6px;border:1px solid #e5e7eb;background:#fff;color:#6b7280;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;transition:all .15s;}
.modal-close:hover{background:#f5f5f5;color:#111;}
.modal-body{padding:22px 24px;overflow-y:auto;flex:1;}
.modal-footer{padding:14px 24px;border-top:1px solid #e5e7eb;background:#fafafa;display:flex;justify-content:flex-end;gap:10px;flex-shrink:0;}

/* form */
.fg{display:flex;flex-direction:column;gap:5px;margin-bottom:14px;}
.fg label{font-size:12px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px;}
.req{color:#ef4444;margin-left:2px;}
.form-input{padding:9px 12px;border:1px solid #d1d5db;border-radius:7px;font-size:13.5px;font-family:inherit;color:#111827;background:#fff;width:100%;outline:none;transition:border-color .2s,box-shadow .2s;}
.form-input:focus{border-color:#0f172a;box-shadow:0 0 0 3px rgba(15,23,42,.07);}
.form-input.error{border-color:#ef4444;}
.form-input:disabled{background:#f9fafb;color:#9ca3af;}
.fgrid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.fgrid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;}
.sec-divider{font-size:10.5px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid #e5e7eb;padding-bottom:5px;margin:4px 0 14px;}
textarea.form-input{resize:vertical;min-height:62px;}

/* leaf count display */
.leaf-count-display{display:flex;align-items:center;gap:10px;background:#0f172a;border-radius:8px;padding:12px 16px;margin-bottom:14px;}
.lcd-icon{font-size:20px;color:#60a5fa;}
.lcd-label{font-size:11px;color:#94a3b8;font-weight:600;}
.lcd-value{font-size:24px;font-weight:800;color:#60a5fa;line-height:1;}
.lcd-sub{font-size:11px;color:#94a3b8;}

/* company picker */
.company-picker{display:flex;gap:10px;margin-bottom:14px;}
.company-opt{flex:1;padding:12px;border:2px solid #e5e7eb;border-radius:8px;text-align:center;cursor:pointer;transition:all .2s;background:#fff;}
.company-opt:hover{border-color:#0f172a;background:#f8fafc;}
.company-opt.selected-usll{border-color:#3b82f6;background:#eff6ff;}
.company-opt.selected-ulcl{border-color:#ec4899;background:#fdf2f8;}
.company-opt .co-name{font-size:15px;font-weight:800;margin-bottom:2px;}
.company-opt .co-sub{font-size:10px;color:#9ca3af;}
.company-opt input{display:none;}
.company-opt.selected-usll .co-name{color:#1d4ed8;}
.company-opt.selected-ulcl .co-name{color:#9d174d;}

/* toast */
#cbToast{position:fixed;bottom:28px;right:28px;padding:12px 22px;border-radius:8px;font-size:14px;font-weight:600;color:#fff;z-index:99999;display:none;box-shadow:0 4px 16px rgba(0,0,0,.18);}
#cbToast.success{background:#15803d;}
#cbToast.error{background:#dc2626;}

/* select2 override */
.select2-container--default .select2-selection--single{height:38px!important;border:1px solid #d1d5db!important;border-radius:7px!important;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:36px!important;padding-left:12px!important;font-size:13.5px!important;color:#111827!important;font-family:inherit!important;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:36px!important;}
.select2-dropdown{border:1px solid #d1d5db!important;border-radius:7px!important;font-size:13px!important;font-family:inherit!important;box-shadow:0 8px 24px rgba(0,0,0,.12)!important;z-index:100020!important;}
.select2-results__option--highlighted{background:#0f172a!important;}

@media(max-width:640px){
  .fgrid-2,.fgrid-3{grid-template-columns:1fr;}
  .filter-row{flex-direction:column;}
}
</style>

<!-- ══ PAGE HEADER ══════════════════════════════════════════════ -->
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:22px;">
    <div>
        <h2 class="page-title"><i class="fa-solid fa-book-bookmark"></i> Cheque Book Management</h2>
        <p class="page-subtitle">Manage cheque books and track individual cheque leaf entries</p>
    </div>
    <div style="display:flex;gap:10px;">
        <button class="btn btn-book" onclick="openBookModal()">
            <i class="fa-solid fa-book-medical"></i> Add Cheque Book
        </button>
        <button class="btn btn-primary" onclick="openEntryModal()">
            <i class="fa-solid fa-plus"></i> Add Cheque Entry
        </button>
    </div>
</div>

<!-- ══ SUMMARY ════════════════════════════════════════════════════ -->
<div class="summary-grid">
    <div class="s-card blue">
        <div class="s-label">Cheque Books</div>
        <div class="s-value"><?php echo number_format($total_books); ?></div>
        <div class="s-sub">Registered books</div>
    </div>
    <div class="s-card purple">
        <div class="s-label">Total Entries</div>
        <div class="s-value"><?php echo number_format($total_entries); ?></div>
        <div class="s-sub">Cheque leaf records</div>
    </div>
    <div class="s-card green">
        <div class="s-label">Total Amount</div>
        <div class="s-value" style="font-size:17px;"><?php echo $total_amount>0?number_format($total_amount,2):'—'; ?></div>
        <div class="s-sub">Sum of cheque amounts</div>
    </div>
    <div class="s-card amber">
        <div class="s-label">Issued</div>
        <div class="s-value"><?php echo number_format($issued_count); ?></div>
        <div class="s-sub">Issued cheques</div>
    </div>
    <div class="s-card red">
        <div class="s-label">Pending</div>
        <div class="s-value"><?php echo number_format($pending_count); ?></div>
        <div class="s-sub">Pending cheques</div>
    </div>
</div>

<!-- ══ TABS ═══════════════════════════════════════════════════════ -->
<div class="tab-nav">
    <button class="tab-btn <?php echo $tab==='entries'?'active':''; ?>" onclick="switchTab('entries')">
        <i class="fa-solid fa-file-lines"></i> Cheque Entries
        <span class="pill pill-blue"><?php echo count($entries); ?></span>
    </button>
    <button class="tab-btn <?php echo $tab==='books'?'active':''; ?>" onclick="switchTab('books')">
        <i class="fa-solid fa-book"></i> Cheque Books
        <span class="pill pill-blue"><?php echo count($books); ?></span>
    </button>
</div>

<!-- ══ FILTER BAR ═════════════════════════════════════════════════ -->
<div class="filter-card">
    <form method="GET" action="" id="filterForm">
        <input type="hidden" name="tab" id="fTab" value="<?php echo htmlspecialchars($tab); ?>">
        <div class="filter-row">
            <div class="ffg">
                <label>Company</label>
                <select name="f_company" class="fctrl" style="width:130px;">
                    <option value="">— All —</option>
                    <option value="USLL" <?php echo $f_company==='USLL'?'selected':''; ?>>USLL</option>
                    <option value="ULCL" <?php echo $f_company==='ULCL'?'selected':''; ?>>ULCL</option>
                </select>
            </div>
            <div class="ffg">
                <label>Bank Account</label>
                <select name="f_bank" class="fctrl" id="fFilterBank" style="width:220px;">
                    <option value="">— All Banks —</option>
                    <?php foreach ($bank_accounts as $ba): ?>
                    <option value="<?php echo $ba['id']; ?>" <?php echo $f_bank==$ba['id']?'selected':''; ?>><?php echo htmlspecialchars($ba['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ffg" id="entryDateFilters">
                <label>Cheque Date From</label>
                <input type="date" name="f_from" class="fctrl" value="<?php echo htmlspecialchars($f_from); ?>">
            </div>
            <div class="ffg" id="entryDateFiltersTo">
                <label>To</label>
                <input type="date" name="f_to" class="fctrl" value="<?php echo htmlspecialchars($f_to); ?>">
            </div>
            <div class="ffg">
                <label>Status</label>
                <select name="f_status" class="fctrl" style="width:140px;">
                    <option value="">— All Status —</option>
                    <?php if ($tab==='books'): ?>
                    <option value="Active"    <?php echo $f_status==='Active'   ?'selected':''; ?>>Active</option>
                    <option value="Used"      <?php echo $f_status==='Used'     ?'selected':''; ?>>Used</option>
                    <option value="Cancelled" <?php echo $f_status==='Cancelled'?'selected':''; ?>>Cancelled</option>
                    <?php else: ?>
                    <option value="Pending"   <?php echo $f_status==='Pending'  ?'selected':''; ?>>Pending</option>
                    <option value="Issued"    <?php echo $f_status==='Issued'   ?'selected':''; ?>>Issued</option>
                    <option value="Cleared"   <?php echo $f_status==='Cleared'  ?'selected':''; ?>>Cleared</option>
                    <option value="Bounced"   <?php echo $f_status==='Bounced'  ?'selected':''; ?>>Bounced</option>
                    <option value="Cancelled" <?php echo $f_status==='Cancelled'?'selected':''; ?>>Cancelled</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="ffg" id="entrySearchFilter">
                <label>Search</label>
                <input type="text" name="f_search" class="fctrl" placeholder="Leaf no, payee…" value="<?php echo htmlspecialchars($f_search); ?>" style="width:180px;">
            </div>
            <div class="ffg" style="flex-direction:row;gap:6px;align-items:flex-end;">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Filter</button>
                <a href="cheque_book_entry.php" class="btn btn-secondary btn-sm" title="Clear"><i class="fa-solid fa-rotate-left"></i></a>
            </div>
        </div>
    </form>
</div>

<!-- ══ TAB: ENTRIES ═══════════════════════════════════════════════ -->
<div class="tab-pane <?php echo $tab==='entries'?'active':''; ?>" id="pane-entries">
<div class="table-card">
    <div class="table-toolbar">
        <div class="tbl-title"><i class="fa-solid fa-file-lines"></i> Cheque Entries <span class="pill pill-blue"><?php echo count($entries); ?></span></div>
        <button class="btn btn-primary btn-sm" onclick="openEntryModal()"><i class="fa-solid fa-plus"></i> Add Entry</button>
    </div>
    <div class="dt-wrap">
        <table class="data-table" id="entriesTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Company</th>
                    <th>Bank Account</th>
                    <th>Leaf No</th>
                    <th>Payee</th>
                    <th class="tr">Amount (Rs.)</th>
                    <th>Cheque Date</th>
                    <th>Sent Date</th>
                    <th>Status</th>
                    <th>Remark</th>
                    <th class="tc">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($entries)): ?>
                <tr><td colspan="11"><div class="empty-state"><i class="fa-solid fa-file-lines"></i><p>No cheque entries found. Click <strong>Add Cheque Entry</strong> to get started.</p></div></td></tr>
            <?php else: $rn=1; foreach ($entries as $e):
                $sclass = strtolower($e['status']);
                $co     = $e['company'];
            ?>
                <tr id="entry-tr-<?php echo $e['id']; ?>">
                    <td style="color:#9ca3af;font-size:11px;"><?php echo $rn++; ?></td>
                    <td><span class="badge badge-<?php echo strtolower($co); ?>"><?php echo htmlspecialchars($co); ?></span></td>
                    <td style="font-size:12px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars(trim(($e['bank_label']??'').' ('.$e['account_no'].')','/ ')); ?>">
                        <?php echo htmlspecialchars(trim(($e['bank_label']??''),'/ ')); ?><br>
                        <span style="color:#9ca3af;font-size:10.5px;"><?php echo htmlspecialchars($e['account_no']??''); ?></span>
                    </td>
                    <td><strong style="font-size:13px;color:#0f172a;"><?php echo htmlspecialchars($e['cheque_leaf_no']); ?></strong></td>
                    <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($e['payee']??'—'); ?></td>
                    <td class="tr" style="font-weight:700;color:#0f172a;"><?php echo $e['amount']!==null?'Rs. '.number_format($e['amount'],2):'—'; ?></td>
                    <td><?php echo !empty($e['cheque_date'])&&$e['cheque_date']!='0000-00-00'?'<span style="background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;display:inline-block;padding:2px 7px;border-radius:6px;font-size:11px;font-weight:600;">'.date('d M Y',strtotime($e['cheque_date'])).'</span>':'—'; ?></td>
                    <td><?php echo !empty($e['sent_date'])&&$e['sent_date']!='0000-00-00'?'<span style="background:#fef9c3;color:#713f12;border:1px solid #fde68a;display:inline-block;padding:2px 7px;border-radius:6px;font-size:11px;font-weight:600;">'.date('d M Y',strtotime($e['sent_date'])).'</span>':'—'; ?></td>
                    <td><span class="badge badge-<?php echo $sclass; ?>"><?php echo htmlspecialchars($e['status']); ?></span></td>
                    <td style="font-size:11.5px;color:#6b7280;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($e['remark']??'—'); ?></td>
                    <td class="tc" style="white-space:nowrap;">
                        <button class="btn btn-xs btn-secondary" onclick="editEntry(<?php echo $e['id']; ?>)" title="Edit"><i class="fa-solid fa-pen"></i></button>
                        <button class="btn btn-xs btn-danger" onclick="deleteEntry(<?php echo $e['id']; ?>)" title="Delete" style="margin-left:4px;"><i class="fa-solid fa-trash"></i></button>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($entries)): ?>
            <tfoot>
                <tr>
                    <td colspan="5" style="text-align:right;font-size:11px;opacity:.7;">TOTAL — <?php echo count($entries); ?> entries</td>
                    <td class="tr">Rs. <?php echo number_format($total_amount,2); ?></td>
                    <td colspan="5"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>
</div>

<!-- ══ TAB: CHEQUE BOOKS ══════════════════════════════════════════ -->
<div class="tab-pane <?php echo $tab==='books'?'active':''; ?>" id="pane-books">
<div class="table-card">
    <div class="table-toolbar">
        <div class="tbl-title"><i class="fa-solid fa-book"></i> Cheque Books <span class="pill pill-blue"><?php echo count($books); ?></span></div>
        <button class="btn btn-book btn-sm" onclick="openBookModal()"><i class="fa-solid fa-book-medical"></i> Add Cheque Book</button>
    </div>
    <div class="dt-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Company</th>
                    <th>Bank Account</th>
                    <th>Leaf Start</th>
                    <th>Leaf End</th>
                    <th class="tc">Total Leaves</th>
                    <th class="tc">Used / Remaining</th>
                    <th>Sent Date</th>
                    <th>Status</th>
                    <th>Remark</th>
                    <th class="tc">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($books)): ?>
                <tr><td colspan="11"><div class="empty-state"><i class="fa-solid fa-book"></i><p>No cheque books found. Click <strong>Add Cheque Book</strong> to get started.</p></div></td></tr>
            <?php else: $rn=1; foreach ($books as $bk):
                $used = intval($bk['used_leaves']);
                $total_lv = intval($bk['leaf_count']);
                $remaining = $total_lv - $used;
                $pct = $total_lv > 0 ? min(100, round($used/$total_lv*100)) : 0;
                $bar_color = $pct < 50 ? '#22c55e' : ($pct < 80 ? '#f59e0b' : '#ef4444');
                $sclass = strtolower($bk['status']);
                $co = $bk['company'];
            ?>
                <tr id="book-tr-<?php echo $bk['id']; ?>">
                    <td style="color:#9ca3af;font-size:11px;"><?php echo $rn++; ?></td>
                    <td><span class="badge badge-<?php echo strtolower($co); ?>"><?php echo htmlspecialchars($co); ?></span></td>
                    <td style="font-size:12px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars(trim(($bk['bank_label']??''),' /')); ?>">
                        <?php echo htmlspecialchars(trim($bk['bank_label']??'','/ ')); ?><br>
                        <span style="color:#9ca3af;font-size:10.5px;"><?php echo htmlspecialchars($bk['account_no']??''); ?></span>
                    </td>
                    <td><code style="background:#f1f5f9;padding:2px 7px;border-radius:4px;font-size:12px;font-weight:700;color:#0f172a;"><?php echo htmlspecialchars($bk['leaf_no_start']); ?></code></td>
                    <td><code style="background:#f1f5f9;padding:2px 7px;border-radius:4px;font-size:12px;font-weight:700;color:#0f172a;"><?php echo htmlspecialchars($bk['leaf_no_end']); ?></code></td>
                    <td class="tc"><strong style="font-size:15px;color:#0f172a;"><?php echo $total_lv; ?></strong></td>
                    <td class="tc">
                        <span style="font-size:12px;font-weight:700;color:#374151;"><?php echo $used; ?> / <?php echo $remaining; ?></span>
                        <div class="leaf-bar"><div class="leaf-bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $bar_color; ?>;"></div></div>
                        <span style="font-size:10px;color:#9ca3af;"><?php echo $pct; ?>%</span>
                    </td>
                    <td><?php echo !empty($bk['sent_date'])&&$bk['sent_date']!='0000-00-00'?'<span style="background:#fef9c3;color:#713f12;border:1px solid #fde68a;display:inline-block;padding:2px 7px;border-radius:6px;font-size:11px;font-weight:600;">'.date('d M Y',strtotime($bk['sent_date'])).'</span>':'—'; ?></td>
                    <td><span class="badge badge-<?php echo $sclass; ?>"><?php echo htmlspecialchars($bk['status']); ?></span></td>
                    <td style="font-size:11.5px;color:#6b7280;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($bk['remark']??'—'); ?></td>
                    <td class="tc" style="white-space:nowrap;">
                        <button class="btn btn-xs btn-secondary" onclick="editBook(<?php echo $bk['id']; ?>)" title="Edit"><i class="fa-solid fa-pen"></i></button>
                        <button class="btn btn-xs btn-danger" onclick="deleteBook(<?php echo $bk['id']; ?>)" title="Delete" style="margin-left:4px;"><i class="fa-solid fa-trash"></i></button>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<!-- ════════════════════════════════════════════════════════════════
     ADD / EDIT CHEQUE BOOK MODAL
     ════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="bookModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title"><i class="fa-solid fa-book-bookmark" style="color:#7c3aed;"></i><span id="bookModalTitle">Add Cheque Book</span></div>
            <button class="modal-close" onclick="closeBookModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="bm_id" value="0">

            <!-- Company Picker -->
            <div class="sec-divider">Company</div>
            <div class="company-picker">
                <label class="company-opt" id="bm_co_usll" onclick="selectBookCompany('USLL')">
                    <input type="radio" name="bm_company_radio" value="USLL">
                    <div class="co-name">USLL</div>
                    <div class="co-sub">United Silica Lanka Ltd</div>
                </label>
                <label class="company-opt" id="bm_co_ulcl" onclick="selectBookCompany('ULCL')">
                    <input type="radio" name="bm_company_radio" value="ULCL">
                    <div class="co-name">ULCL</div>
                    <div class="co-sub">United Lanka Coatings Ltd</div>
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
            <div style="margin-bottom:14px;"></div>

            <!-- Leaf Count Display -->
            <div class="leaf-count-display" id="leafCountDisplay">
                <i class="fa-solid fa-layer-group lcd-icon"></i>
                <div>
                    <div class="lcd-label">Total Leaf Count</div>
                    <div class="lcd-value" id="leafCountVal">—</div>
                </div>
                <div style="margin-left:auto;text-align:right;">
                    <div class="lcd-sub" id="leafRangeDisplay">Enter start & end numbers</div>
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

<!-- ════════════════════════════════════════════════════════════════
     ADD / EDIT CHEQUE ENTRY MODAL
     ════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="entryModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title"><i class="fa-solid fa-file-lines" style="color:#0f172a;"></i><span id="entryModalTitle">Add Cheque Entry</span></div>
            <button class="modal-close" onclick="closeEntryModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="em_id" value="0">

            <!-- Company Picker -->
            <div class="sec-divider">Company</div>
            <div class="company-picker">
                <label class="company-opt" id="em_co_usll" onclick="selectEntryCompany('USLL')">
                    <input type="radio" name="em_company_radio" value="USLL">
                    <div class="co-name">USLL</div>
                    <div class="co-sub">United Silica Lanka Ltd</div>
                </label>
                <label class="company-opt" id="em_co_ulcl" onclick="selectEntryCompany('ULCL')">
                    <input type="radio" name="em_company_radio" value="ULCL">
                    <div class="co-name">ULCL</div>
                    <div class="co-sub">United Lanka Coatings Ltd</div>
                </label>
            </div>
            <input type="hidden" id="em_company" value="">

            <div class="sec-divider">Bank Account</div>
            <div class="fg">
                <label>Bank Account <span class="req">*</span></label>
                <select id="em_bank_account" class="form-input" style="width:100%;" onchange="loadChequeBooks()">
                    <option value="">— select bank account —</option>
                    <?php foreach ($bank_accounts as $ba): ?>
                    <option value="<?php echo $ba['id']; ?>"><?php echo htmlspecialchars($ba['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="fg">
                <label>Cheque Book (Optional)</label>
                <select id="em_cheque_book" class="form-input" style="width:100%;" onchange="loadLeafNos()">
                    <option value="">— select cheque book (optional) —</option>
                </select>
            </div>

            <div class="sec-divider">Cheque Details</div>
            <div class="fgrid-2">
                <div class="fg" style="margin-bottom:0;">
                    <label>Cheque Leaf No <span class="req">*</span></label>
                    <select id="em_leaf_no_sel" class="form-input" style="width:100%;display:none;"></select>
                    <input type="text" class="form-input" id="em_leaf_no" placeholder="e.g. 000015">
                </div>
                <div class="fg" style="margin-bottom:0;">
                    <label>Payee</label>
                    <input type="text" class="form-input" id="em_payee" placeholder="Payee name">
                </div>
            </div>
            <div style="margin-bottom:14px;"></div>
            <div class="fgrid-3">
                <div class="fg" style="margin-bottom:0;">
                    <label>Amount (Rs.)</label>
                    <input type="number" step="0.01" min="0" class="form-input" id="em_amount" placeholder="0.00">
                </div>
                <div class="fg" style="margin-bottom:0;">
                    <label>Cheque Date</label>
                    <input type="date" class="form-input" id="em_cheque_date">
                </div>
                <div class="fg" style="margin-bottom:0;">
                    <label>Sent Date</label>
                    <input type="date" class="form-input" id="em_sent_date">
                </div>
            </div>
            <div style="margin-bottom:14px;"></div>
            <div class="fgrid-2">
                <div class="fg" style="margin-bottom:0;">
                    <label>Status</label>
                    <select class="form-input" id="em_status">
                        <option value="Pending">Pending</option>
                        <option value="Issued">Issued</option>
                        <option value="Cleared">Cleared</option>
                        <option value="Bounced">Bounced</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="fg" style="margin-bottom:0;">
                    <label>Remark</label>
                    <input type="text" class="form-input" id="em_remark" placeholder="Optional notes…">
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeEntryModal()">Cancel</button>
            <button class="btn btn-primary" id="saveEntryBtn" onclick="saveEntry()"><i class="fa-solid fa-floppy-disk"></i> Save Entry</button>
        </div>
    </div>
</div>

<div id="cbToast"></div>

<script>
// ════════════════════════════════════════════════════════════════
//  SELECT2 INIT
// ════════════════════════════════════════════════════════════════
$(function(){
    // Move modals to body (avoid z-index issues)
    document.body.appendChild(document.getElementById('bookModal'));
    document.body.appendChild(document.getElementById('entryModal'));

    $('#bm_bank_account').select2({placeholder:'— select bank account —', allowClear:true, dropdownParent:$('#bookModal')});
    $('#em_bank_account').select2({placeholder:'— select bank account —', allowClear:true, dropdownParent:$('#entryModal')});
    $('#em_cheque_book').select2({placeholder:'— select cheque book —',   allowClear:true, dropdownParent:$('#entryModal')});
    $('#em_leaf_no_sel').select2({placeholder:'— select leaf no —',        allowClear:true, dropdownParent:$('#entryModal')});
    $('#fFilterBank').select2({placeholder:'— All Banks —', allowClear:true, width:'220px'});

    $('#em_bank_account').on('change', loadChequeBooks);
    $('#em_cheque_book').on('change', loadLeafNos);
});

// ════════════════════════════════════════════════════════════════
//  TABS
// ════════════════════════════════════════════════════════════════
function switchTab(tab){
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p=>p.classList.remove('active'));
    document.getElementById('pane-'+tab).classList.add('active');
    document.querySelector('.tab-btn[onclick*="'+tab+'"]').classList.add('active');
    document.getElementById('fTab').value = tab;
}

// ════════════════════════════════════════════════════════════════
//  LEAF COUNT CALCULATOR
// ════════════════════════════════════════════════════════════════
function calcLeafCount(){
    const start = document.getElementById('bm_leaf_start').value.trim();
    const end   = document.getElementById('bm_leaf_end').value.trim();
    const sn    = parseInt(start.replace(/\D/g,''));
    const en    = parseInt(end.replace(/\D/g,''));
    const lcv   = document.getElementById('leafCountVal');
    const lrd   = document.getElementById('leafRangeDisplay');
    if (!isNaN(sn) && !isNaN(en) && en >= sn) {
        const cnt = en - sn + 1;
        lcv.textContent = cnt.toLocaleString();
        lrd.textContent = start + ' → ' + end;
    } else {
        lcv.textContent = '—';
        lrd.textContent = 'Enter start & end numbers';
    }
}

// ════════════════════════════════════════════════════════════════
//  COMPANY PICKER
// ════════════════════════════════════════════════════════════════
function selectBookCompany(co){
    document.getElementById('bm_company').value = co;
    document.getElementById('bm_co_usll').className = 'company-opt' + (co==='USLL' ? ' selected-usll' : '');
    document.getElementById('bm_co_ulcl').className = 'company-opt' + (co==='ULCL' ? ' selected-ulcl' : '');
}
function selectEntryCompany(co){
    document.getElementById('em_company').value = co;
    document.getElementById('em_co_usll').className = 'company-opt' + (co==='USLL' ? ' selected-usll' : '');
    document.getElementById('em_co_ulcl').className = 'company-opt' + (co==='ULCL' ? ' selected-ulcl' : '');
    loadChequeBooks();
}

// ════════════════════════════════════════════════════════════════
//  CHEQUE BOOK → LEAF NO LOADING
// ════════════════════════════════════════════════════════════════
function loadChequeBooks(){
    const bankId  = $('#em_bank_account').val();
    const company = document.getElementById('em_company').value;
    const sel     = $('#em_cheque_book');
    sel.empty().append('<option value="">— select cheque book (optional) —</option>');
    if (!bankId) return;
    const params = 'action=get_books_for_bank&bank_id='+bankId+(company?'&company='+company:'');
    fetch('cheque_book_entry.php?'+params)
        .then(r=>r.json())
        .then(res=>{
            if(res.success && res.data.length){
                res.data.forEach(b=>{
                    sel.append(`<option value="${b.id}">[${escH(b.company)}] ${escH(b.leaf_no_start)} → ${escH(b.leaf_no_end)} (${b.leaf_count} leaves)</option>`);
                });
            }
            sel.trigger('change.select2');
        });
}

function loadLeafNos(){
    const bookId = $('#em_cheque_book').val();
    const selSel = document.getElementById('em_leaf_no_sel');
    const txtInp = document.getElementById('em_leaf_no');
    if (!bookId) {
        selSel.style.display = 'none';
        txtInp.style.display = '';
        return;
    }
    fetch('cheque_book_entry.php?action=get_leaf_nos&book_id='+bookId)
        .then(r=>r.json())
        .then(res=>{
            if(!res.success){ return; }
            $('#em_leaf_no_sel').empty().append('<option value="">— select leaf no —</option>');
            res.data.forEach(lf=>{
                const opt = document.createElement('option');
                opt.value = lf.no;
                opt.textContent = lf.no + (lf.used ? ' (used)' : '');
                if (lf.used) opt.disabled = true;
                document.getElementById('em_leaf_no_sel').appendChild(opt);
            });
            $('#em_leaf_no_sel').trigger('change.select2');
            selSel.style.display = '';
            txtInp.style.display = 'none';
        });
}

// ════════════════════════════════════════════════════════════════
//  CHEQUE BOOK MODAL
// ════════════════════════════════════════════════════════════════
function openBookModal(){
    document.getElementById('bookModalTitle').textContent = 'Add Cheque Book';
    document.getElementById('bm_id').value = '0';
    document.getElementById('bm_company').value = '';
    document.getElementById('bm_co_usll').className = 'company-opt';
    document.getElementById('bm_co_ulcl').className = 'company-opt';
    $('#bm_bank_account').val('').trigger('change');
    document.getElementById('bm_leaf_start').value = '';
    document.getElementById('bm_leaf_end').value   = '';
    document.getElementById('bm_sent_date').value  = '';
    document.getElementById('bm_remark').value     = '';
    document.getElementById('bm_status').value     = 'Active';
    document.getElementById('leafCountVal').textContent = '—';
    document.getElementById('leafRangeDisplay').textContent = 'Enter start & end numbers';
    document.getElementById('bookModal').classList.add('open');
}
function closeBookModal(){ document.getElementById('bookModal').classList.remove('open'); }

function editBook(id){
    fetch('cheque_book_entry.php?action=get_book&id='+id)
        .then(r=>r.json())
        .then(res=>{
            if(!res.success||!res.data){ showToast('Could not load record.','error'); return; }
            const d = res.data;
            document.getElementById('bookModalTitle').textContent = 'Edit Cheque Book';
            document.getElementById('bm_id').value = d.id;
            selectBookCompany(d.company);
            $('#bm_bank_account').val(d.bank_account_id).trigger('change');
            document.getElementById('bm_leaf_start').value = d.leaf_no_start||'';
            document.getElementById('bm_leaf_end').value   = d.leaf_no_end||'';
            document.getElementById('bm_sent_date').value  = d.sent_date&&d.sent_date!='0000-00-00'?d.sent_date:'';
            document.getElementById('bm_remark').value     = d.remark||'';
            document.getElementById('bm_status').value     = d.status||'Active';
            calcLeafCount();
            document.getElementById('bookModal').classList.add('open');
        })
        .catch(()=>showToast('Failed to load record.','error'));
}

function saveBook(){
    const company  = document.getElementById('bm_company').value;
    const bankId   = $('#bm_bank_account').val();
    const leafStart= document.getElementById('bm_leaf_start').value.trim();
    const leafEnd  = document.getElementById('bm_leaf_end').value.trim();
    if (!company)   { showToast('Please select a company.','error'); return; }
    if (!bankId)    { showToast('Please select a bank account.','error'); return; }
    if (!leafStart) { showToast('Please enter Leaf No Start.','error'); return; }
    if (!leafEnd)   { showToast('Please enter Leaf No End.','error'); return; }

    const sn = parseInt(leafStart.replace(/\D/g,''));
    const en = parseInt(leafEnd.replace(/\D/g,''));
    if (isNaN(sn)||isNaN(en)||en<sn){ showToast('Leaf End must be ≥ Leaf Start.','error'); return; }

    const fd = new FormData();
    fd.append('id',              document.getElementById('bm_id').value);
    fd.append('bank_account_id', bankId);
    fd.append('company',         company);
    fd.append('leaf_no_start',   leafStart);
    fd.append('leaf_no_end',     leafEnd);
    fd.append('sent_date',       document.getElementById('bm_sent_date').value);
    fd.append('remark',          document.getElementById('bm_remark').value);
    fd.append('status',          document.getElementById('bm_status').value);

    const btn = document.getElementById('saveBookBtn');
    btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    fetch('cheque_book_entry.php?action=save_book',{method:'POST',body:fd})
        .then(r=>r.json())
        .then(res=>{
            btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Cheque Book';
            if(res.success){
                showToast('Cheque book saved!','success');
                closeBookModal();
                setTimeout(()=>location.reload(),700);
            } else {
                showToast('Error: '+(res.message||'Unknown'),'error');
            }
        })
        .catch(()=>{ btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Cheque Book'; showToast('Network error.','error'); });
}

function deleteBook(id){
    if(!confirm('Delete this cheque book? This action cannot be undone.')) return;
    const fd=new FormData(); fd.append('id',id);
    fetch('cheque_book_entry.php?action=delete_book',{method:'POST',body:fd})
        .then(r=>r.json())
        .then(res=>{
            if(res.success){ document.getElementById('book-tr-'+id)?.remove(); showToast('Cheque book deleted.','success'); }
            else showToast('Error: '+(res.message||'Delete failed'),'error');
        })
        .catch(()=>showToast('Network error.','error'));
}

// ════════════════════════════════════════════════════════════════
//  CHEQUE ENTRY MODAL
// ════════════════════════════════════════════════════════════════
function openEntryModal(){
    document.getElementById('entryModalTitle').textContent = 'Add Cheque Entry';
    document.getElementById('em_id').value = '0';
    document.getElementById('em_company').value = '';
    document.getElementById('em_co_usll').className = 'company-opt';
    document.getElementById('em_co_ulcl').className = 'company-opt';
    $('#em_bank_account').val('').trigger('change');
    $('#em_cheque_book').empty().append('<option value="">— select cheque book (optional) —</option>').trigger('change.select2');
    document.getElementById('em_leaf_no').value       = '';
    document.getElementById('em_leaf_no').style.display= '';
    document.getElementById('em_leaf_no_sel').style.display='none';
    document.getElementById('em_payee').value         = '';
    document.getElementById('em_amount').value        = '';
    document.getElementById('em_cheque_date').value   = '';
    document.getElementById('em_sent_date').value     = '';
    document.getElementById('em_remark').value        = '';
    document.getElementById('em_status').value        = 'Pending';
    document.getElementById('entryModal').classList.add('open');
}
function closeEntryModal(){ document.getElementById('entryModal').classList.remove('open'); }

function editEntry(id){
    fetch('cheque_book_entry.php?action=get_entry&id='+id)
        .then(r=>r.json())
        .then(res=>{
            if(!res.success||!res.data){ showToast('Could not load record.','error'); return; }
            const d = res.data;
            document.getElementById('entryModalTitle').textContent = 'Edit Cheque Entry';
            document.getElementById('em_id').value = d.id;
            selectEntryCompany(d.company);
            $('#em_bank_account').val(d.bank_account_id).trigger('change');
            setTimeout(()=>{
                if(d.cheque_book_id){
                    $('#em_cheque_book').val(d.cheque_book_id).trigger('change');
                }
                document.getElementById('em_leaf_no').value     = d.cheque_leaf_no||'';
                document.getElementById('em_leaf_no').style.display = '';
                document.getElementById('em_leaf_no_sel').style.display = 'none';
            }, 300);
            document.getElementById('em_payee').value     = d.payee||'';
            document.getElementById('em_amount').value    = d.amount||'';
            document.getElementById('em_cheque_date').value= d.cheque_date&&d.cheque_date!='0000-00-00'?d.cheque_date:'';
            document.getElementById('em_sent_date').value = d.sent_date&&d.sent_date!='0000-00-00'?d.sent_date:'';
            document.getElementById('em_remark').value    = d.remark||'';
            document.getElementById('em_status').value    = d.status||'Pending';
            document.getElementById('entryModal').classList.add('open');
        })
        .catch(()=>showToast('Failed to load record.','error'));
}

function saveEntry(){
    const company = document.getElementById('em_company').value;
    const bankId  = $('#em_bank_account').val();
    const leafNoSel = document.getElementById('em_leaf_no_sel');
    const leafNoTxt = document.getElementById('em_leaf_no');
    const leafNo = leafNoSel.style.display!=='none' ? ($('#em_leaf_no_sel').val()||'') : leafNoTxt.value.trim();

    if (!company)  { showToast('Please select a company.','error'); return; }
    if (!bankId)   { showToast('Please select a bank account.','error'); return; }
    if (!leafNo)   { showToast('Please enter/select a cheque leaf no.','error'); return; }

    const fd = new FormData();
    fd.append('id',              document.getElementById('em_id').value);
    fd.append('cheque_book_id',  $('#em_cheque_book').val()||'');
    fd.append('bank_account_id', bankId);
    fd.append('company',         company);
    fd.append('cheque_leaf_no',  leafNo);
    fd.append('payee',           document.getElementById('em_payee').value);
    fd.append('amount',          document.getElementById('em_amount').value);
    fd.append('cheque_date',     document.getElementById('em_cheque_date').value);
    fd.append('sent_date',       document.getElementById('em_sent_date').value);
    fd.append('remark',          document.getElementById('em_remark').value);
    fd.append('status',          document.getElementById('em_status').value);

    const btn = document.getElementById('saveEntryBtn');
    btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    fetch('cheque_book_entry.php?action=save_entry',{method:'POST',body:fd})
        .then(r=>r.json())
        .then(res=>{
            btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Entry';
            if(res.success){
                showToast(parseInt(document.getElementById('em_id').value)>0?'Entry updated!':'Entry added!','success');
                closeEntryModal();
                setTimeout(()=>location.reload(),700);
            } else {
                showToast('Error: '+(res.message||'Unknown'),'error');
            }
        })
        .catch(()=>{ btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Entry'; showToast('Network error.','error'); });
}

function deleteEntry(id){
    if(!confirm('Delete this cheque entry? This cannot be undone.')) return;
    const fd=new FormData(); fd.append('id',id);
    fetch('cheque_book_entry.php?action=delete_entry',{method:'POST',body:fd})
        .then(r=>r.json())
        .then(res=>{
            if(res.success){ document.getElementById('entry-tr-'+id)?.remove(); showToast('Entry deleted.','success'); }
            else showToast('Error: '+(res.message||'Delete failed'),'error');
        })
        .catch(()=>showToast('Network error.','error'));
}

// ════════════════════════════════════════════════════════════════
//  TOAST
// ════════════════════════════════════════════════════════════════
function showToast(msg, type){
    const t = document.getElementById('cbToast');
    t.textContent = msg;
    t.className   = type;
    t.style.display = 'block';
    clearTimeout(t._t);
    t._t = setTimeout(()=>t.style.display='none', 3500);
}

function escH(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// Close modals on backdrop click
document.getElementById('bookModal').addEventListener('click',  function(e){ if(e.target===this) closeBookModal(); });
document.getElementById('entryModal').addEventListener('click', function(e){ if(e.target===this) closeEntryModal(); });
document.addEventListener('keydown', e=>{ if(e.key==='Escape'){ closeBookModal(); closeEntryModal(); } });
</script>

<?php include 'footer.php'; ?>