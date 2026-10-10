<?php
/**
 * cheque_deposit_letter.php
 * ─────────────────────────────────────────────────────────────────
 *  • Filter cheques by deposit date range + deposit type (multi-select)
 *  • Cheque table with live search + select all / individual select
 *  • Cart: add selected cheques, select company account, generate letter
 *  • Save letter + items to DB (cheque_deposit_letters, cheque_deposit_letter_items)
 *  • Saved letters list with print / view / delete
 *  • Print-ready letter layout
 * ─────────────────────────────────────────────────────────────────
 */
if (session_status() === PHP_SESSION_NONE) session_start();

function get_current_user_label_cdl() {
    return $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['name'] ??
           $_SESSION['full_name'] ?? $_SESSION['email'] ??
           (isset($_SESSION['user_id']) ? 'User #'.$_SESSION['user_id'] : 'system');
}

include_once 'config.php';

/* ══════════════════════════════════════════════════════════
   AUTO-CREATE TABLES
══════════════════════════════════════════════════════════ */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_deposit_letters (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    letter_no        VARCHAR(50)       NOT NULL,
    letter_date      DATE              NOT NULL,
    company_account_id INT             DEFAULT NULL,
    deposit_type     VARCHAR(30)       DEFAULT 'normal',
    total_cheques    INT               DEFAULT 0,
    total_amount     DECIMAL(15,2)     DEFAULT 0.00,
    bank_name        VARCHAR(200)      DEFAULT '',
    account_name     VARCHAR(200)      DEFAULT '',
    account_no       VARCHAR(100)      DEFAULT '',
    notes            TEXT,
    status           VARCHAR(20)       DEFAULT 'draft',
    created_by       VARCHAR(100)      DEFAULT 'system',
    created_at       DATETIME          DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ldate  (letter_date),
    INDEX idx_acc    (company_account_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_deposit_letter_items (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    letter_id        INT               NOT NULL,
    cheque_id        INT               NOT NULL,
    cheque_no        VARCHAR(50)       DEFAULT '',
    t_code           VARCHAR(50)       DEFAULT '',
    customer_name    VARCHAR(250)      DEFAULT '',
    cheque_amount    DECIMAL(15,2)     DEFAULT 0.00,
    cheque_date      DATE              DEFAULT NULL,
    bank_code        VARCHAR(30)       DEFAULT '',
    branch_code      VARCHAR(30)       DEFAULT '',
    bank_name        VARCHAR(150)      DEFAULT '',
    branch_name      VARCHAR(150)      DEFAULT '',
    INDEX idx_lid    (letter_id),
    INDEX idx_cid    (cheque_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ══════════════════════════════════════════════════════════
   AJAX HANDLERS
══════════════════════════════════════════════════════════ */

/* ── Load cheques by filter ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load_cheques') {
    header('Content-Type: application/json');
    $from      = trim(mysqli_real_escape_string($conn, $_GET['date_from']    ?? ''));
    $to        = trim(mysqli_real_escape_string($conn, $_GET['date_to']      ?? ''));
    $types_raw = $_GET['dep_types'] ?? '';
    $types     = array_filter(array_map('trim', explode(',', $types_raw)));
    $allowed   = ['normal','bulk','normal_bulk'];
    $types     = array_values(array_filter($types, fn($t) => in_array($t, $allowed)));

    if (!$from || !$to) { echo json_encode(['success'=>false,'error'=>'Date range required']); exit; }

    $w = ["ch.deposit_date >= '$from'", "ch.deposit_date <= '$to'",
          "ch.status IN('deposited')"];
    if ($types) {
        $tlist = "'".implode("','", $types)."'";
        $w[]   = "ch.deposit_type IN($tlist)";
    }
    $where = implode(' AND ', $w);

    $sql = "SELECT ch.id, ch.cheque_no, ch.cheque_date, ch.total_amount,
                   ch.bank_code, ch.bank_name, ch.branch_code, ch.branch_name,
                   ch.status, ch.deposit_type, ch.t_code, ch.deposit_date,
                   COALESCE(NULLIF(fsd.customer_name,''), NULLIF(c.shop_name,''), ch.t_code) AS customer_name
            FROM cheques ch
            LEFT JOIN invoice_payments      ip  ON ip.id  = ch.invoice_payment_id
            LEFT JOIN field_summary         fs  ON fs.id  = ip.field_summary_id
            LEFT JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
            LEFT JOIN customers             c   ON c.t_code = ch.t_code
            WHERE $where
            ORDER BY ch.deposit_date DESC, ch.cheque_no ASC";
    $r = mysqli_query($conn, $sql);
    $rows = [];
    if ($r) while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;
    else { echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]); exit; }
    echo json_encode(['success'=>true,'cheques'=>$rows,'count'=>count($rows)]);
    exit;
}

/* ── Save letter ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_letter') {
    header('Content-Type: application/json');
    $letter_date = trim(mysqli_real_escape_string($conn, $_POST['letter_date'] ?? date('Y-m-d')));
    $acc_id      = intval($_POST['company_account_id'] ?? 0);
    $dep_type    = trim(mysqli_real_escape_string($conn, $_POST['deposit_type'] ?? 'normal'));
    $notes       = trim(mysqli_real_escape_string($conn, $_POST['notes'] ?? ''));
    $items       = json_decode($_POST['items'] ?? '[]', true);
    if (!is_array($items) || !count($items)) { echo json_encode(['success'=>false,'error'=>'No cheques selected']); exit; }
    if (!$letter_date) { echo json_encode(['success'=>false,'error'=>'Letter date required']); exit; }
    if (!$acc_id)      { echo json_encode(['success'=>false,'error'=>'Please select a bank account']); exit; }

    /* Get account info */
    $acc_info = ['account_name'=>'','account_no'=>'','bank_name'=>''];
    $ar = mysqli_query($conn, "SELECT cba.account_name, cba.account_no,
            COALESCE(NULLIF(b.bank_name,''), cba.bank_code, '') AS bank_name
            FROM company_bank_accounts cba
            LEFT JOIN banks b ON b.bank_code = cba.bank_code
            WHERE cba.id=$acc_id LIMIT 1");
    if ($ar && $row = mysqli_fetch_assoc($ar)) $acc_info = $row;

    /* Generate letter_no */
    $yr      = date('Y', strtotime($letter_date));
    $cnt_r   = mysqli_query($conn, "SELECT COUNT(*)+1 AS n FROM cheque_deposit_letters WHERE YEAR(letter_date)='$yr'");
    $seq     = $cnt_r ? (int)mysqli_fetch_assoc($cnt_r)['n'] : 1;
    $letter_no = 'CDL-'.$yr.'-'.str_pad($seq, 4, '0', STR_PAD_LEFT);

    $total_cheques = count($items);
    $total_amount  = array_sum(array_column($items, 'cheque_amount'));
    $an_e  = mysqli_real_escape_string($conn, $acc_info['account_name']);
    $ano_e = mysqli_real_escape_string($conn, $acc_info['account_no']);
    $bn_e  = mysqli_real_escape_string($conn, $acc_info['bank_name']);
    $cu    = mysqli_real_escape_string($conn, get_current_user_label_cdl());
    $dt_e  = mysqli_real_escape_string($conn, $dep_type);
    $ln_e  = mysqli_real_escape_string($conn, $letter_no);

    if (!mysqli_query($conn, "INSERT INTO cheque_deposit_letters
            (letter_no, letter_date, company_account_id, deposit_type, total_cheques, total_amount,
             bank_name, account_name, account_no, notes, status, created_by)
            VALUES ('$ln_e','$letter_date',$acc_id,'$dt_e',$total_cheques,$total_amount,
                    '$bn_e','$an_e','$ano_e','$notes','draft','$cu')")) {
        echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]); exit;
    }
    $letter_id = mysqli_insert_id($conn);

    foreach ($items as $item) {
        $cid  = intval($item['cheque_id']     ?? 0);
        $cno  = mysqli_real_escape_string($conn, $item['cheque_no']      ?? '');
        $tc   = mysqli_real_escape_string($conn, $item['t_code']         ?? '');
        $cn   = mysqli_real_escape_string($conn, $item['customer_name']  ?? '');
        $amt  = floatval($item['cheque_amount'] ?? 0);
        $cd   = mysqli_real_escape_string($conn, $item['cheque_date']    ?? '');
        $bk   = mysqli_real_escape_string($conn, $item['bank_code']      ?? '');
        $br   = mysqli_real_escape_string($conn, $item['branch_code']    ?? '');
        $bkn  = mysqli_real_escape_string($conn, $item['bank_name']      ?? '');
        $brn  = mysqli_real_escape_string($conn, $item['branch_name']    ?? '');
        mysqli_query($conn, "INSERT INTO cheque_deposit_letter_items
                (letter_id, cheque_id, cheque_no, t_code, customer_name, cheque_amount,
                 cheque_date, bank_code, branch_code, bank_name, branch_name)
                VALUES ($letter_id,$cid,'$cno','$tc','$cn',$amt,'$cd','$bk','$br','$bkn','$brn')");
    }
    echo json_encode(['success'=>true,'letter_id'=>$letter_id,'letter_no'=>$letter_no]);
    exit;
}

/* ── Get letter detail for print/view ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'letter_detail') {
    header('Content-Type: application/json');
    $lid = intval($_GET['letter_id'] ?? 0);
    if (!$lid) { echo json_encode(['success'=>false,'error'=>'Invalid ID']); exit; }
    $lr  = mysqli_query($conn, "SELECT * FROM cheque_deposit_letters WHERE id=$lid LIMIT 1");
    $letter = ($lr && $row = mysqli_fetch_assoc($lr)) ? $row : null;
    if (!$letter) { echo json_encode(['success'=>false,'error'=>'Letter not found']); exit; }
    $ir   = mysqli_query($conn, "SELECT * FROM cheque_deposit_letter_items WHERE letter_id=$lid ORDER BY id ASC");
    $items = [];
    if ($ir) while ($row = mysqli_fetch_assoc($ir)) $items[] = $row;
    echo json_encode(['success'=>true,'letter'=>$letter,'items'=>$items]);
    exit;
}

/* ── Delete letter ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_letter') {
    header('Content-Type: application/json');
    $lid = intval($_POST['letter_id'] ?? 0);
    if (!$lid) { echo json_encode(['success'=>false,'error'=>'Invalid ID']); exit; }
    mysqli_query($conn, "DELETE FROM cheque_deposit_letter_items WHERE letter_id=$lid");
    mysqli_query($conn, "DELETE FROM cheque_deposit_letters WHERE id=$lid");
    echo json_encode(['success'=>true]);
    exit;
}

/* ── Company accounts dropdown ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'company_accounts') {
    header('Content-Type: application/json');
    $rows = [];
    $r = mysqli_query($conn, "SELECT cba.id, cba.account_name, cba.account_no, cba.account_type,
            cba.bank_code, cba.branch_code,
            COALESCE(NULLIF(b.bank_name,''), cba.bank_code,'') AS bank_name,
            COALESCE(NULLIF(bb.branch_name,''), cba.branch_code,'') AS branch_name
            FROM company_bank_accounts cba
            LEFT JOIN banks         b  ON b.bank_code   = cba.bank_code
            LEFT JOIN bank_branches bb ON bb.bank_code  = cba.bank_code AND bb.branch_code = cba.branch_code
            WHERE cba.active=1 ORDER BY cba.account_name ASC");
    if ($r) while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;
    echo json_encode(['success'=>true,'accounts'=>$rows]);
    exit;
}

/* ══════════════════════════════════════════════════════════
   NORMAL PAGE
══════════════════════════════════════════════════════════ */
include 'header.php';

/* Saved letters */
$letters = [];
$lr = mysqli_query($conn, "SELECT * FROM cheque_deposit_letters ORDER BY created_at DESC LIMIT 100");
if ($lr) while ($row = mysqli_fetch_assoc($lr)) $letters[] = $row;

/* Company info for letter header */
$company_info = ['company_name'=>'Yelo Logistics (Pvt) Ltd','address'=>'','phone'=>'','email'=>''];
$ci = mysqli_query($conn,"SELECT company_name FROM companies LIMIT 1");
if ($ci && $row = mysqli_fetch_assoc($ci)) $company_info['company_name'] = $row['company_name'];
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
*,*::before,*::after{box-sizing:border-box}
.page-title{font-size:22px;font-weight:800;color:#1e1b4b;margin:0 0 4px}
.page-subtitle{font-size:13px;color:#6b7280;margin:0}
.btn{display:inline-flex;align-items:center;gap:5px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .2s;white-space:nowrap}
.btn-primary{background:#6366f1;color:#fff}.btn-primary:hover{background:#4f46e5}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}.btn-secondary:hover{background:#e8e8e8}
.btn-teal{background:linear-gradient(135deg,#0d9488,#14b8a6);color:#fff;border:none}.btn-teal:hover{filter:brightness(1.08)}
.btn-success{background:#16a34a;color:#fff}.btn-success:hover{background:#15803d}
.btn-danger{background:#dc2626;color:#fff}.btn-danger:hover{background:#b91c1c}
.btn-sm{padding:5px 12px;font-size:11px}

/* Layout */
.two-col{display:grid;grid-template-columns:1fr 380px;gap:18px;align-items:start}
.main-col{min-width:0}
.side-col{position:sticky;top:16px}

/* Filter card */
.filter-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:16px 18px;margin-bottom:16px;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.filter-row{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap}
.ffg{display:flex;flex-direction:column;gap:5px;flex:1;min-width:140px}
.ffg label{font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em}
.ffg input,.ffg select{border:1px solid #e5e5e5;border-radius:7px;padding:8px 10px;font-size:13px;font-family:inherit;color:#1f2937;width:100%;outline:none;transition:border .2s;background:#fff}
.ffg input:focus,.ffg select:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.1)}

/* Table card */
.table-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-bottom:1px solid #f0f0f0;background:#fafafa;flex-wrap:wrap;gap:8px}
.tbl-title{font-size:13px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.pill{padding:2px 10px;border-radius:12px;font-size:11px;font-weight:600;white-space:nowrap}
.p-violet{background:#ede9fe;color:#5b21b6}.p-teal{background:#ccfbf1;color:#0f766e}
.p-green{background:#dcfce7;color:#166534}.p-blue{background:#dbeafe;color:#1e40af}
.p-amber{background:#fef3c7;color:#92400e}.p-red{background:#fee2e2;color:#991b1b}
.p-gray{background:#f3f4f6;color:#374151}
.search-box{position:relative;display:flex;align-items:center}
.search-box input{border:1.5px solid #e0e7ff;border-radius:7px;padding:6px 10px 6px 30px;font-size:12px;font-family:inherit;width:220px;outline:none;transition:all .2s;background:#fff}
.search-box input:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.1);width:260px}
.search-box .si{position:absolute;left:9px;color:#9ca3af;font-size:11px;pointer-events:none}
.dt-outer{overflow-x:auto;max-height:480px;overflow-y:auto}
.data-table{width:100%;border-collapse:collapse;font-size:12px;min-width:700px}
.data-table thead th{padding:8px 9px;text-align:left;font-weight:700;font-size:10.5px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;border-right:1px solid rgba(255,255,255,.1);position:sticky;top:0;z-index:10}
.data-table thead th:last-child{border-right:none}
.data-table thead th.tr{text-align:right}.data-table thead th.tc{text-align:center}
.data-table tbody tr{border-bottom:1px solid #f0f2f5;transition:background .1s}
.data-table tbody tr:hover td{background:#f0f9ff!important}
.data-table tbody tr.in-cart td{background:#f0fdf4!important}
.data-table tbody tr.in-cart:hover td{background:#dcfce7!important}
.data-table td{padding:7px 9px;color:#374151;vertical-align:middle;background:#fff}
.tr{text-align:right}.tc{text-align:center}
.data-table tfoot td{padding:9px 9px;font-weight:800;font-size:12px;background:#0f172a;color:#e2e8f0;border-top:2px solid #334155;position:sticky;bottom:0}
.data-table tfoot td.tr{text-align:right}
.mono{font-family:'Courier New',monospace;font-weight:700;letter-spacing:.02em}
.cb{width:15px;height:15px;accent-color:#6366f1;cursor:pointer}
.add-to-cart-btn{display:inline-flex;align-items:center;gap:3px;background:linear-gradient(135deg,#0d9488,#14b8a6);color:#fff;border:none;border-radius:5px;padding:3px 9px;font-size:10.5px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap;transition:filter .2s}
.add-to-cart-btn:hover{filter:brightness(1.1)}
.add-to-cart-btn.in-cart{background:linear-gradient(135deg,#16a34a,#4ade80)}
.add-to-cart-btn:disabled{opacity:.4;cursor:not-allowed}
.state-empty{text-align:center;padding:60px 20px;color:#9ca3af}
.state-empty i{font-size:40px;display:block;margin-bottom:12px;opacity:.3}
.state-empty p{font-size:13px;font-weight:500}
.state-empty small{font-size:11px}
.dep-type-tag{display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700;white-space:nowrap}
.dtt-normal{background:#dbeafe;color:#1e40af}.dtt-bulk{background:#ede9fe;color:#5b21b6}.dtt-normal_bulk{background:#fef3c7;color:#92400e}

/* Cart / side panel */
.cart-panel{background:#fff;border:1.5px solid #e0e7ff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08)}
.cart-header{background:linear-gradient(135deg,#1e1b4b,#312e81);padding:13px 16px;display:flex;align-items:center;justify-content:space-between}
.cart-title{color:#fff;font-size:14px;font-weight:800;display:flex;align-items:center;gap:8px}
.cart-badge{background:linear-gradient(135deg,#0d9488,#14b8a6);color:#fff;border-radius:12px;padding:2px 10px;font-size:12px;font-weight:800}
.cart-body{max-height:340px;overflow-y:auto;padding:8px 10px}
.cart-empty{text-align:center;padding:30px 12px;color:#9ca3af;font-size:12px}
.cart-empty i{font-size:28px;display:block;margin-bottom:8px;opacity:.4}
.cart-item{display:flex;align-items:center;gap:8px;padding:7px 8px;border:1px solid #e0e7ff;border-radius:8px;margin-bottom:6px;background:#fafbff;transition:border-color .15s}
.cart-item:hover{border-color:#a5b4fc}
.ci-no{background:#ede9fe;color:#3730a3;border-radius:5px;padding:3px 8px;font-family:'Courier New',monospace;font-size:11px;font-weight:800;flex-shrink:0}
.ci-info{flex:1;min-width:0}
.ci-cust{font-size:11.5px;font-weight:700;color:#1f2937;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ci-amt{font-size:11px;color:#0d9488;font-weight:700;white-space:nowrap}
.ci-remove{background:none;border:none;color:#dc2626;cursor:pointer;padding:2px 4px;font-size:12px;flex-shrink:0;border-radius:4px;transition:background .15s}
.ci-remove:hover{background:#fee2e2}
.cart-footer{padding:12px 14px;border-top:1px solid #e0e7ff;background:#f8faff}
.cart-total{font-size:15px;font-weight:800;color:#0d9488;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center}
.cart-total-lbl{font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em}
.gen-btn{width:100%;background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;border:none;border-radius:8px;padding:11px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:7px;transition:filter .2s}
.gen-btn:hover{filter:brightness(1.08)}
.gen-btn:disabled{opacity:.5;cursor:not-allowed}
.clear-cart-btn{width:100%;background:#f3f4f6;color:#374151;border:1px solid #e5e5e5;border-radius:7px;padding:7px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;margin-top:7px;transition:background .15s}
.clear-cart-btn:hover{background:#e5e7eb}

/* Letter modal */
#letterModal{display:none;position:fixed;inset:0;z-index:999998;background:rgba(0,0,0,.65);overflow-y:auto;padding:28px 14px 40px}
#letterModal.open{display:block}
.lm-box{background:#fff;border-radius:16px;width:100%;max-width:680px;margin:0 auto;box-shadow:0 32px 100px rgba(0,0,0,.45);overflow:hidden;animation:modalIn .22s cubic-bezier(.16,1,.3,1)}
@keyframes modalIn{from{transform:translateY(-30px) scale(.97);opacity:0}to{transform:translateY(0) scale(1);opacity:1}}
.lm-hdr{background:linear-gradient(135deg,#1e1b4b,#312e81);padding:15px 20px;display:flex;align-items:center;justify-content:space-between}
.lm-title{color:#fff;font-size:15px;font-weight:800;display:flex;align-items:center;gap:8px}
.lm-x{background:rgba(255,255,255,.15);border:none;border-radius:7px;color:#fff;width:30px;height:30px;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;transition:background .2s}
.lm-x:hover{background:rgba(255,255,255,.3)}
.lm-body{padding:20px}
.lm-section{margin-bottom:18px}
.lm-section-title{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#6366f1;margin-bottom:10px;display:flex;align-items:center;gap:6px;padding-bottom:7px;border-bottom:1px solid #e0e7ff}
.lm-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.lm-field{display:flex;flex-direction:column;gap:5px}
.lm-field label{font-size:10px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.04em}
.lm-field input,.lm-field select,.lm-field textarea{border:1.5px solid #e0e7ff;border-radius:7px;padding:9px 11px;font-size:13px;font-family:inherit;color:#1f2937;outline:none;transition:border .2s;background:#fff;width:100%}
.lm-field input:focus,.lm-field select:focus,.lm-field textarea:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.1)}
.lm-summary{background:linear-gradient(135deg,#f0f9ff,#dbeafe);border:1px solid #bfdbfe;border-radius:9px;padding:11px 14px;display:flex;gap:16px;flex-wrap:wrap;margin-bottom:14px}
.lms-item{display:flex;flex-direction:column;gap:2px}
.lms-lbl{font-size:9px;font-weight:700;color:#1d4ed8;text-transform:uppercase;letter-spacing:.05em}
.lms-val{font-size:13px;font-weight:800;color:#1e3a8a}
.acc-card{border:2px solid #e0e7ff;border-radius:9px;padding:10px 12px;cursor:pointer;transition:all .15s;background:#fff;display:flex;align-items:center;gap:10px;margin-bottom:6px}
.acc-card:hover{border-color:#6366f1;background:#f5f3ff}
.acc-card.selected{border-color:#6366f1;background:#f5f3ff;box-shadow:0 0 0 3px rgba(99,102,241,.1)}
.acc-card-radio{width:16px;height:16px;accent-color:#6366f1;flex-shrink:0}
.acc-card-avatar{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,#0d9488,#14b8a6);color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;flex-shrink:0}
.acc-card-info{flex:1;min-width:0}
.acc-card-name{font-size:12px;font-weight:700;color:#0f766e}
.acc-card-no{font-family:'Courier New',monospace;font-size:11px;color:#0d9488;font-weight:700}
.acc-card-bank{font-size:10px;color:#6b7280;margin-top:2px}
.lm-footer{display:flex;gap:10px;padding:14px 20px;border-top:1px solid #e0e7ff;background:#f8faff}
.lm-btn-save{flex:1;background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;border:none;border-radius:8px;padding:11px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:6px;transition:filter .2s}
.lm-btn-save:hover{filter:brightness(1.08)}.lm-btn-save:disabled{opacity:.5;cursor:not-allowed}
.lm-btn-cancel{background:#f3f4f6;color:#374151;border:1px solid #e5e5e5;border-radius:8px;padding:11px 18px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit}

/* Print / view letter modal */
#printModal{display:none;position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,.72);overflow-y:auto;padding:20px 14px 40px}
#printModal.open{display:block}
.pm-box{background:#fff;border-radius:12px;width:100%;max-width:820px;margin:0 auto;box-shadow:0 32px 100px rgba(0,0,0,.45);overflow:hidden}
.pm-toolbar{display:flex;align-items:center;justify-content:space-between;padding:10px 16px;background:#1e1b4b;flex-wrap:wrap;gap:8px}
.pm-toolbar-title{color:#fff;font-size:14px;font-weight:700;display:flex;align-items:center;gap:7px}
.pm-toolbar-btns{display:flex;gap:8px}
.pm-content{padding:30px 36px}

/* Saved letters table */
.letters-section{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05);margin-top:18px}
.letters-table{width:100%;border-collapse:collapse;font-size:12px}
.letters-table thead th{padding:8px 10px;text-align:left;font-weight:700;font-size:10.5px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;border-right:1px solid rgba(255,255,255,.08)}
.letters-table tbody tr{border-bottom:1px solid #f0f2f5;transition:background .1s}
.letters-table tbody tr:hover td{background:#f8faff!important}
.letters-table td{padding:8px 10px;color:#374151;vertical-align:middle;background:#fff}

/* Select2 */
.select2-container--default .select2-selection--multiple{border:1px solid #e5e5e5!important;border-radius:7px!important;min-height:38px!important;background:#fff!important}
.select2-container--default .select2-selection--multiple .select2-selection__choice{background:#6366f1!important;border:none!important;color:#fff!important;border-radius:4px!important;padding:2px 8px!important;font-size:11px!important;font-weight:600!important}
.select2-container--default .select2-selection--multiple .select2-selection__choice__remove{color:#fff!important;margin-right:4px!important;opacity:.75}
.select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover{opacity:1}
.select2-container--default.select2-container--focus .select2-selection--multiple{border-color:#6366f1!important;box-shadow:0 0 0 3px rgba(99,102,241,.1)!important}
.select2-dropdown{border:1px solid #e5e5e5!important;border-radius:8px!important;box-shadow:0 8px 30px rgba(0,0,0,.12)!important;font-size:13px!important}
.select2-results__option--highlighted{background:#6366f1!important}

#toast{position:fixed;bottom:28px;right:28px;z-index:9999999;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 6px 24px rgba(0,0,0,.2);color:#fff;transform:translateY(80px);opacity:0;transition:transform .3s,opacity .3s;pointer-events:none;max-width:360px}
#toast.show{transform:translateY(0);opacity:1}

/* ── Print styles ── */
@media print{
    body > *:not(#printModal){display:none!important}
    #printModal{position:static!important;background:none!important;padding:0!important;display:block!important}
    .pm-box{box-shadow:none!important;border-radius:0!important}
    .pm-toolbar{display:none!important}
    .pm-content{padding:20px!important}
}
@media(max-width:1000px){.two-col{grid-template-columns:1fr}.side-col{position:static}.cart-body{max-height:200px}}
</style>

<!-- PAGE HEADER -->
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:20px;" class="no-print">
  <div>
    <h2 class="page-title"><i class="fa-solid fa-file-pen" style="color:#6366f1;"></i> Cheque Deposit Letter</h2>
    <p class="page-subtitle">Filter cheques by deposit date &amp; type · Add to cart · Generate &amp; save deposit letter</p>
  </div>
  <div style="display:flex;gap:8px;" class="no-print">
    <a href="cheques.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-arrow-left"></i> Cheque Register</a>
    <a href="cheque_deposit_list.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-building-columns"></i> Deposit List</a>
  </div>
</div>

<div class="two-col">
<!-- ══ LEFT: Filters + Cheque Table ══ -->
<div class="main-col">

  <!-- FILTER CARD -->
  <div class="filter-card">
    <div style="font-size:11px;font-weight:800;color:#6b7280;text-transform:uppercase;letter-spacing:.06em;margin-bottom:12px;display:flex;align-items:center;gap:6px;">
      <i class="fa-solid fa-sliders" style="color:#6366f1;"></i> Filter Cheques
    </div>
    <div class="filter-row">
      <div class="ffg" style="max-width:160px;">
        <label><i class="fa-solid fa-calendar-day"></i> Deposit Date From</label>
        <input type="date" id="fDateFrom" value="<?=date('Y-m-01')?>">
      </div>
      <div class="ffg" style="max-width:160px;">
        <label><i class="fa-solid fa-calendar-day"></i> Deposit Date To</label>
        <input type="date" id="fDateTo" value="<?=date('Y-m-d')?>">
      </div>
      <div class="ffg" style="min-width:260px;">
        <label><i class="fa-solid fa-layer-group"></i> Deposit Type(s)</label>
        <select id="fDepTypes" multiple style="width:100%;">
          <option value="normal" selected>Normal Deposit</option>
          <option value="bulk" selected>Bulk Deposit</option>
          <option value="normal_bulk" selected>Normal Bulk Deposit</option>
        </select>
      </div>
      <div style="display:flex;align-items:flex-end;gap:8px;">
        <button class="btn btn-primary" id="loadBtn" onclick="loadCheques()"><i class="fa-solid fa-magnifying-glass"></i> Load Cheques</button>
        <button class="btn btn-secondary btn-sm" onclick="clearFilters()" title="Clear"><i class="fa-solid fa-rotate-left"></i></button>
      </div>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:11px;padding-top:10px;border-top:1px solid #f0f0f0;align-items:center;">
      <span style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Quick:</span>
      <button class="btn btn-secondary btn-sm" onclick="setQuick('today')">Today</button>
      <button class="btn btn-secondary btn-sm" onclick="setQuick('yesterday')">Yesterday</button>
      <button class="btn btn-secondary btn-sm" onclick="setQuick('week')">This Week</button>
      <button class="btn btn-secondary btn-sm" onclick="setQuick('month')">This Month</button>
    </div>
  </div>

  <!-- CHEQUE TABLE -->
  <div class="table-card">
    <div class="table-toolbar">
      <div class="tbl-title">
        <i class="fa-solid fa-money-check" style="color:#0d9488;"></i> Cheques
        <span class="pill p-teal" id="tblCount">—</span>
        <span class="pill p-green" id="selectedCountPill" style="display:none;"></span>
      </div>
      <div style="display:flex;align-items:center;gap:8px;">
        <button class="btn btn-teal btn-sm" id="addAllBtn" onclick="addAllVisible()" style="display:none;"><i class="fa-solid fa-cart-plus"></i> Add All Filtered</button>
        <div class="search-box">
          <i class="fa-solid fa-magnifying-glass si"></i>
          <input type="text" id="tblSearch" placeholder="Search cheque no, customer, bank…" oninput="filterTable()">
        </div>
      </div>
    </div>
    <div class="dt-outer">
    <table class="data-table" id="chequeTable">
      <thead>
        <tr>
          <th class="tc" style="width:36px;"><input type="checkbox" class="cb" id="selectAllChk" onchange="toggleSelectAll(this)" title="Select all visible"></th>
          <th>Cheque No.</th>
          <th>Customer</th>
          <th class="tr">Amount</th>
          <th class="tc">Cheque Date</th>
          <th class="tc">Bank Code</th>
          <th class="tc">Branch Code</th>
          <th class="tc">Dep. Type</th>
          <th class="tc" style="width:90px;">Cart</th>
        </tr>
      </thead>
      <tbody id="chequeTbody">
        <tr><td colspan="9"><div class="state-empty">
          <i class="fa-solid fa-magnifying-glass"></i>
          <p>Set filters and click <strong>Load Cheques</strong></p>
          <small>Cheques with deposited / cleared / to_be_bank status will appear here</small>
        </div></td></tr>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="3" style="font-size:11px;opacity:.6;">VISIBLE TOTAL</td>
          <td class="tr">Rs.&nbsp;<span id="tblTotal">0.00</span></td>
          <td colspan="5" style="font-size:11px;opacity:.6;"><span id="tblFootNote"></span></td>
        </tr>
      </tfoot>
    </table>
    </div>
  </div>

  <!-- SAVED LETTERS -->
  <div class="letters-section">
    <div class="table-toolbar">
      <div class="tbl-title"><i class="fa-solid fa-folder-open" style="color:#6366f1;"></i> Saved Letters
        <span class="pill p-violet"><?=count($letters)?></span>
      </div>
    </div>
    <div style="overflow-x:auto;">
    <table class="letters-table">
      <thead>
        <tr>
          <th style="width:28px;">#</th>
          <th>Letter No.</th>
          <th>Date</th>
          <th>Bank Account</th>
          <th class="tc">Cheques</th>
          <th class="tr">Amount</th>
          <th class="tc">Type</th>
          <th class="tc" style="width:160px;">Actions</th>
        </tr>
      </thead>
      <tbody id="lettersBody">
        <?php if(empty($letters)): ?>
        <tr><td colspan="8" style="text-align:center;padding:30px;color:#9ca3af;font-size:12px;">No letters saved yet</td></tr>
        <?php else: $li=1; foreach($letters as $lt):
            $dt = $lt['deposit_type'] ?? 'normal';
            $dtc = ['normal'=>'dtt-normal','bulk'=>'dtt-bulk','normal_bulk'=>'dtt-normal_bulk'];
            $dtl = ['normal'=>'Normal','bulk'=>'Bulk','normal_bulk'=>'Norm.Bulk'];
        ?>
        <tr id="letter-row-<?=$lt['id']?>">
          <td style="color:#9ca3af;font-size:11px;"><?=$li++?></td>
          <td><span class="mono" style="color:#4338ca;font-size:12px;"><?=htmlspecialchars($lt['letter_no'])?></span></td>
          <td style="font-size:11.5px;"><?=htmlspecialchars($lt['letter_date'])?></td>
          <td>
            <div style="font-size:12px;font-weight:600;color:#1f2937;"><?=htmlspecialchars($lt['account_name'])?></div>
            <div style="font-size:10.5px;color:#6b7280;font-family:'Courier New',monospace;"><?=htmlspecialchars($lt['account_no'])?></div>
          </td>
          <td class="tc"><span style="background:#ede9fe;color:#5b21b6;border-radius:10px;padding:2px 9px;font-size:11px;font-weight:700;"><?=$lt['total_cheques']?></span></td>
          <td style="text-align:right;font-weight:700;color:#0d9488;white-space:nowrap;">Rs.&nbsp;<?=number_format(floatval($lt['total_amount']),2)?></td>
          <td class="tc">
            <span class="dep-type-tag <?=($dtc[$dt]??'dtt-normal')?>"><?=($dtl[$dt]??$dt)?></span>
          </td>
          <td class="tc">
            <div style="display:flex;gap:5px;justify-content:center;flex-wrap:wrap;">
              <?php if ($dt === 'normal' || $dt === 'normal_bulk'): ?>
                <!-- Normal / Normal Bulk: Cheque List only -->
                <a href="deposit_letter_bulk.php?letter_id=<?=$lt['id']?>" target="_blank"
                   class="btn btn-success btn-sm" title="Cheque List">
                  <i class="fa-solid fa-table-list"></i> Cheque List
                </a>
              <?php elseif ($dt === 'bulk'): ?>
                <!-- Bulk: Cheque List + Covering Letter (no STL) -->
                <a href="deposit_letter_bulk.php?letter_id=<?=$lt['id']?>" target="_blank"
                   class="btn btn-success btn-sm" title="Cheque List">
                  <i class="fa-solid fa-table-list"></i> Cheque List
                </a>
                <a href="deposit_letter_cover.php?letter_id=<?=$lt['id']?>" target="_blank"
                   class="btn btn-sm" title="Covering Letter"
                   style="background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;">
                  <i class="fa-solid fa-envelope-open-text"></i> Cover Letter
                </a>
              <?php endif; ?>
              <button class="btn btn-danger btn-sm"
                      onclick="deleteLetter(<?=$lt['id']?>, '<?=htmlspecialchars($lt['letter_no'])?>')"
                      title="Delete">
                <i class="fa-solid fa-trash"></i>
              </button>
            </div>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>
  </div>

</div><!-- /main-col -->

<!-- ══ RIGHT: Cart ══ -->
<div class="side-col">
  <div class="cart-panel">
    <div class="cart-header">
      <div class="cart-title"><i class="fa-solid fa-cart-shopping"></i> Letter Cart <span class="cart-badge" id="cartBadge">0</span></div>
      <span style="color:rgba(255,255,255,.6);font-size:11px;" id="cartAmtHeader">Rs. 0.00</span>
    </div>
    <div class="cart-body" id="cartBody">
      <div class="cart-empty" id="cartEmpty">
        <i class="fa-solid fa-cart-shopping"></i>
        <div>Select cheques and add to cart</div>
      </div>
    </div>
    <div class="cart-footer">
      <div class="cart-total">
        <span class="cart-total-lbl">Total Amount</span>
        <span id="cartTotal">Rs. 0.00</span>
      </div>
      <button class="gen-btn" id="genLetterBtn" onclick="openLetterModal()" disabled>
        <i class="fa-solid fa-file-pen"></i> Generate Letter
      </button>
      <button class="clear-cart-btn" onclick="clearCart()"><i class="fa-solid fa-trash-can"></i> Clear Cart</button>
    </div>
  </div>
</div>

</div><!-- /two-col -->

<!-- ══ LETTER CONFIG MODAL ══ -->
<div id="letterModal">
  <div class="lm-box">
    <div class="lm-hdr">
      <div class="lm-title"><i class="fa-solid fa-file-pen"></i> Generate Deposit Letter</div>
      <button class="lm-x" onclick="closeLetterModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="lm-body">
      <div class="lm-summary" id="lmSummary"></div>
      <div class="lm-section">
        <div class="lm-section-title"><i class="fa-solid fa-landmark"></i> Select Company Bank Account</div>
        <div id="lmAccList" style="max-height:240px;overflow-y:auto;padding-right:2px;"><div style="text-align:center;padding:20px;color:#6b7280;font-size:12px;"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div></div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="lm-field">
          <label><i class="fa-solid fa-calendar-day"></i> Letter Date</label>
          <input type="date" id="lmLetterDate" value="<?=date('Y-m-d')?>">
        </div>
        <div class="lm-field" style="grid-column:span 2;">
          <label><i class="fa-solid fa-comment-dots"></i> Notes (optional)</label>
          <textarea id="lmNotes" rows="2" placeholder="Any remarks for this deposit letter…" style="resize:vertical;"></textarea>
        </div>
      </div>
    </div>
    <div class="lm-footer">
      <button class="lm-btn-cancel" onclick="closeLetterModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
      <button class="lm-btn-save" id="lmSaveBtn" onclick="saveLetter()"><i class="fa-solid fa-circle-check"></i> Save & Generate Letter</button>
    </div>
  </div>
</div>

<!-- ══ PRINT / VIEW LETTER MODAL ══ -->
<div id="printModal">
  <div class="pm-box">
    <div class="pm-toolbar no-print">
      <div class="pm-toolbar-title"><i class="fa-solid fa-file-lines"></i><span id="pmTitleNo"></span></div>
      <div class="pm-toolbar-btns">
        <button class="btn btn-primary btn-sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        <button class="btn btn-secondary btn-sm" onclick="closePrintModal()"><i class="fa-solid fa-xmark"></i> Close</button>
      </div>
    </div>
    <div class="pm-content" id="pmContent"></div>
  </div>
</div>

<div id="toast"></div>

<script>
const CO_NAME = <?=json_encode($co_name)?>;

/* ════════════════════════════════════
   STATE  — single source of truth
════════════════════════════════════ */
let _all      = [];   // all cheques loaded from server
let _visible  = [];   // after table search
let _cart     = {};   // { "stringId": chequeObj }
let _selAcc   = null;
let _accounts = [];

/* ════════════════════════════════════
   HELPERS — get elements by actual HTML IDs
════════════════════════════════════ */
const $ = (id) => document.getElementById(id);

function getEl(id){ return document.getElementById(id); }

/* ════════════════════════════════════
   SELECT2 INIT
════════════════════════════════════ */
jQuery(function(){
    jQuery('#fDepTypes').select2({
        placeholder   : '— Select type(s) —',
        allowClear    : false,
        width         : '100%',
        closeOnSelect : false,
    });
    renderTable(); // show empty state immediately
});

/* ════════════════════════════════════
   QUICK DATES
════════════════════════════════════ */
function setQuick(k) {
    const today = new Date();
    const fmt   = d => d.toISOString().slice(0,10);
    let f = new Date(today), t = new Date(today);
    if (k === 'yesterday') { f.setDate(f.getDate()-1); t.setDate(t.getDate()-1); }
    else if (k === 'week')  { f.setDate(f.getDate() - ((f.getDay()+6)%7)); }
    else if (k === 'month') { f = new Date(today.getFullYear(), today.getMonth(), 1); }
    getEl('fDateFrom').value = fmt(f);
    getEl('fDateTo').value   = fmt(t);
}

/* ════════════════════════════════════
   LOAD CHEQUES
════════════════════════════════════ */
async function loadCheques() {
    const from  = getEl('fDateFrom').value;
    const to    = getEl('fDateTo').value;
    const types = (jQuery('#fDepTypes').val() || []).join(',');
    if (!from || !to) { showToast('Please select a date range','err'); return; }

    const btn = getEl('loadBtn');
    if (btn) { btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Loading…'; }

    try {
        const res  = await fetch(`cheque_deposit_letter.php?ajax=load_cheques&date_from=${encodeURIComponent(from)}&date_to=${encodeURIComponent(to)}&dep_types=${encodeURIComponent(types)}`);
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Load failed');
        _all = data.cheques || [];
        getEl('tblSearch').value = '';
        applySearch();
        showToast('Loaded ' + _all.length + ' cheque(s)','ok');
    } catch(e) { showToast('Error: ' + e.message,'err'); }

    if (btn) { btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-magnifying-glass"></i> Load Cheques'; }
}

/* ════════════════════════════════════
   SEARCH  →  update _visible  →  renderTable()
════════════════════════════════════ */
function filterTable() { applySearch(); }

function applySearch() {
    const q = (getEl('tblSearch').value || '').trim().toLowerCase();
    _visible = q ? _all.filter(c =>
        (c.cheque_no     || '').toLowerCase().includes(q) ||
        (c.customer_name || '').toLowerCase().includes(q) ||
        (c.t_code        || '').toLowerCase().includes(q) ||
        (c.bank_code     || '').toLowerCase().includes(q) ||
        (c.branch_code   || '').toLowerCase().includes(q) ||
        String(c.total_amount || '').includes(q)
    ) : [..._all];
    renderTable();
}

/* ════════════════════════════════════
   RENDER TABLE
   Rebuilds tbody from _visible.
   Reads _cart for initial checked state.
   Called ONLY when the visible data set changes.
════════════════════════════════════ */
function renderTable() {
    const tbody = getEl('chequeTbody');
    const n     = _visible.length;

    getEl('tblCount').textContent = n + ' cheque' + (n!==1?'s':'');
    getEl('addAllBtn').style.display = n ? '' : 'none';

    if (!n) {
        const msg = _all.length
            ? '<div class="state-empty"><i class="fa-solid fa-magnifying-glass"></i><p>No cheques match search</p></div>'
            : '<div class="state-empty"><i class="fa-solid fa-magnifying-glass"></i><p>Set filters and click <strong>Load Cheques</strong></p><small>Cheques with deposited / cleared / to_be_bank status will appear</small></div>';
        tbody.innerHTML = '<tr><td colspan="9">' + msg + '</td></tr>';
        getEl('tblTotal').textContent    = '0.00';
        getEl('tblFootNote').textContent = '';
        syncMasterCb();
        return;
    }

    const dtcls = {normal:'dtt-normal', bulk:'dtt-bulk', normal_bulk:'dtt-normal_bulk'};
    const dtlbl = {normal:'Normal',     bulk:'Bulk',     normal_bulk:'Norm.Bulk'};
    let html = '', tot = 0;

    _visible.forEach(c => {
        const sid    = String(c.id);
        const inCart = !!_cart[sid];
        const dt     = c.deposit_type || 'normal';
        tot += parseFloat(c.total_amount || 0);
        html += `
        <tr id="tr-${sid}"${inCart?' class="in-cart"':''}>
          <td class="tc">
            <input type="checkbox" class="cb" id="cb-${sid}"
                   ${inCart?'checked':''}
                   onchange="onCbChange('${sid}', this.checked)">
          </td>
          <td><span class="mono" style="color:#4338ca;font-size:12px;">${esc(c.cheque_no)}</span></td>
          <td>
            <div style="font-size:12px;font-weight:600;color:#1f2937;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(c.customer_name||'')}">
              ${esc(c.customer_name || c.t_code || '—')}
            </div>
            <div style="font-size:10px;color:#9ca3af;font-family:'Courier New',monospace;">${esc(c.t_code||'')}</div>
          </td>
          <td class="tr" style="font-weight:700;color:#0d9488;white-space:nowrap;">
            Rs.&nbsp;${parseFloat(c.total_amount||0).toLocaleString('en-US',{minimumFractionDigits:2})}
          </td>
          <td class="tc" style="font-size:11.5px;white-space:nowrap;">${fmtDate(c.cheque_date)}</td>
          <td class="tc"><span class="mono" style="font-size:11px;">${esc(c.bank_code||'—')}</span></td>
          <td class="tc"><span class="mono" style="font-size:11px;">${esc(c.branch_code||'—')}</span></td>
          <td class="tc"><span class="dep-type-tag ${dtcls[dt]||'dtt-normal'}">${dtlbl[dt]||dt}</span></td>
          <td class="tc">
            <button class="add-to-cart-btn${inCart?' in-cart':''}" id="atc-${sid}"
                    onclick="onAtcClick('${sid}')">
              <i class="fa-solid fa-${inCart?'check':'cart-plus'}"></i>${inCart?' Added':' Add'}
            </button>
          </td>
        </tr>`;
    });

    tbody.innerHTML = html;
    getEl('tblTotal').textContent    = tot.toLocaleString('en-US',{minimumFractionDigits:2});
    getEl('tblFootNote').textContent = n + ' row' + (n!==1?'s':'') + ' shown';
    syncMasterCb();
}

/* ════════════════════════════════════
   SYNC TABLE ROWS
   Patches existing DOM after cart changes.
   No full re-render.
════════════════════════════════════ */
function syncTableRows() {
    _visible.forEach(c => {
        const sid    = String(c.id);
        const inCart = !!_cart[sid];
        const tr     = getEl('tr-'  + sid);
        const cb     = getEl('cb-'  + sid);
        const btn    = getEl('atc-' + sid);
        if (tr)  { if (inCart) tr.classList.add('in-cart'); else tr.classList.remove('in-cart'); }
        if (cb)  cb.checked = inCart;
        if (btn) {
            btn.className = 'add-to-cart-btn' + (inCart ? ' in-cart' : '');
            btn.innerHTML = '<i class="fa-solid fa-' + (inCart?'check':'cart-plus') + '"></i>' + (inCart?' Added':' Add');
        }
    });
    syncMasterCb();
}

/* Master checkbox state reflects _visible vs _cart */
function syncMasterCb() {
    const master = getEl('selectAllChk');
    if (!master) return;
    if (!_visible.length) { master.checked=false; master.indeterminate=false; return; }
    const allIn  = _visible.every(c => !!_cart[String(c.id)]);
    const someIn = _visible.some (c => !!_cart[String(c.id)]);
    master.checked       = allIn;
    master.indeterminate = !allIn && someIn;
}

/* ════════════════════════════════════
   SYNC CART PANEL
════════════════════════════════════ */
function syncCart() {
    const items = Object.values(_cart);
    const n     = items.length;
    const total = items.reduce((s,c) => s + parseFloat(c.total_amount||0), 0);
    const fmt   = v => 'Rs. ' + v.toLocaleString('en-US',{minimumFractionDigits:2});

    getEl('cartBadge').textContent    = n;
    getEl('cartAmtHeader').textContent = fmt(total);
    getEl('cartTotal').textContent    = fmt(total);
    getEl('genLetterBtn').disabled    = (n === 0);

    const pill = getEl('selectedCountPill');
    pill.textContent   = n + ' in cart';
    pill.style.display = n ? '' : 'none';

    const body = getEl('cartBody');
    if (!n) {
        body.innerHTML = '<div class="cart-empty"><i class="fa-solid fa-cart-shopping"></i><div>Select cheques and add to cart</div></div>';
        return;
    }
    let html = '';
    items.forEach(c => {
        html += `<div class="cart-item">
          <div style="background:#ede9fe;color:#3730a3;border-radius:5px;padding:3px 7px;font-family:'Courier New',monospace;font-size:11px;font-weight:800;flex-shrink:0;">${esc(c.cheque_no)}</div>
          <div style="flex:1;min-width:0;">
            <div style="font-size:11px;font-weight:700;color:#1f2937;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(c.customer_name||'')}">
              ${esc(c.customer_name||c.t_code||'—')}
            </div>
            <div style="font-size:10px;color:#6b7280;margin-top:1px;display:flex;gap:5px;">
              <span style="font-weight:700;color:#0d9488;">Rs. ${parseFloat(c.total_amount||0).toLocaleString('en-US',{minimumFractionDigits:2})}</span>
              <span>·</span><span>${esc(c.bank_code||'—')}</span>
              <span>·</span><span>${esc(c.cheque_date||'')}</span>
            </div>
          </div>
          <button onclick="cartRemove('${String(c.id)}')" title="Remove"
                  style="background:none;border:none;color:#dc2626;cursor:pointer;padding:2px 5px;font-size:12px;flex-shrink:0;border-radius:4px;line-height:1;">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>`;
    });
    body.innerHTML = html;
}

/* ════════════════════════════════════
   SINGLE syncUI() CALL AFTER ANY CART CHANGE
════════════════════════════════════ */
function syncUI() {
    syncTableRows();
    syncCart();
}

/* ════════════════════════════════════
   CART DATA OPERATIONS  (no DOM)
════════════════════════════════════ */
function cartAdd(cheque) {
    _cart[String(cheque.id)] = cheque;
}
function cartRemove(sid) {
    delete _cart[String(sid)];
    syncUI();
}
function clearCart() {
    _cart = {};
    syncUI();
}

/* ════════════════════════════════════
   EVENT HANDLERS
════════════════════════════════════ */

/* Individual row checkbox */
function onCbChange(sid, checked) {
    if (checked) {
        const c = _all.find(x => String(x.id) === sid);
        if (c) cartAdd(c);
    } else {
        delete _cart[String(sid)];
    }
    syncUI();
}

/* Add/Remove button in each row */
function onAtcClick(sid) {
    if (_cart[String(sid)]) {
        delete _cart[String(sid)];
    } else {
        const c = _all.find(x => String(x.id) === sid);
        if (c) cartAdd(c);
    }
    syncUI();
}

/* Master checkbox — handles normal / checked / indeterminate */
function toggleSelectAll(master) {
    /* If clicked while indeterminate → treat as "select all" */
    if (master.indeterminate) {
        master.indeterminate = false;
        master.checked = true;
    }
    if (master.checked) {
        _visible.forEach(c => cartAdd(c));
    } else {
        _visible.forEach(c => { delete _cart[String(c.id)]; });
    }
    syncUI();
}

/* "Add All" button in toolbar */
function addAllVisible() {
    _visible.forEach(c => cartAdd(c));
    syncUI();
}

/* ════════════════════════════════════
   LETTER MODAL
════════════════════════════════════ */
function openLetterModal() {
    const items = Object.values(_cart);
    if (!items.length) { showToast('Cart is empty','err'); return; }
    const total = items.reduce((s,c) => s + parseFloat(c.total_amount||0), 0);
    getEl('lmSummary').innerHTML =
        `<div class="dep-sum-item"><span class="dep-sum-lbl">Cheques</span><span class="dep-sum-val">${items.length}</span></div>
         <div class="dep-sum-item"><span class="dep-sum-lbl">Total Amount</span><span class="dep-sum-val">Rs. ${total.toLocaleString('en-US',{minimumFractionDigits:2})}</span></div>
         <div class="dep-sum-item"><span class="dep-sum-lbl">Period</span><span class="dep-sum-val">${esc(getEl('fDateFrom').value||'—')} → ${esc(getEl('fDateTo').value||'—')}</span></div>`;
    getEl('letterModal').classList.add('open');
    getEl('letterModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
    loadAccounts();
}
function closeLetterModal() {
    getEl('letterModal').classList.remove('open');
    getEl('letterModal').style.display = 'none';
    document.body.style.overflow = '';
}

async function loadAccounts() {
    if (_accounts.length) { renderAccounts(); return; }
    getEl('lmAccList').innerHTML = '<div style="text-align:center;padding:18px;color:#6b7280;font-size:12px;"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
    try {
        const res  = await fetch('cheque_deposit_letter.php?ajax=company_accounts');
        const data = await res.json();
        _accounts  = data.accounts || [];
        renderAccounts();
    } catch(e) {
        getEl('lmAccList').innerHTML = '<div style="color:#dc2626;font-size:12px;padding:8px;">Error loading accounts</div>';
    }
}
function renderAccounts() {
    const list = getEl('lmAccList');
    if (!_accounts.length) { list.innerHTML='<div style="color:#9ca3af;font-size:12px;padding:8px;">No active bank accounts found</div>'; return; }
    let html = '';
    _accounts.forEach(acc => {
        const ini  = (acc.account_name||'?').substring(0,2).toUpperCase();
        const sel  = (_selAcc === parseInt(acc.id));
        const bank = [acc.bank_name, acc.bank_code?'('+acc.bank_code+')':''].filter(Boolean).join(' ')||'—';
        html += `<div class="acc-card${sel?' selected':''}" id="ac-${acc.id}" onclick="selectAcc(${acc.id})">
          <input type="radio" class="acc-card-radio" name="lmacc" value="${acc.id}" ${sel?'checked':''}>
          <div class="acc-card-avatar">${esc(ini)}</div>
          <div class="acc-card-info">
            <div class="acc-card-name">${esc(acc.account_name)}</div>
            <div class="acc-card-no">${esc(acc.account_no)}</div>
            <div class="acc-card-bank">${esc(bank)}</div>
          </div>
        </div>`;
    });
    list.innerHTML = html;
}
function selectAcc(id) {
    _selAcc = id;
    document.querySelectorAll('.acc-card').forEach(c => c.classList.remove('selected'));
    const card = getEl('ac-' + id);
    if (card) { card.classList.add('selected'); card.querySelector('input').checked = true; }
}

async function saveLetter() {
    if (!_selAcc) { showToast('Please select a bank account','err'); return; }
    const d = getEl('lmLetterDate').value;
    if (!d) { showToast('Please set a letter date','err'); return; }

    const items = Object.values(_cart).map(c => ({
        cheque_id    : c.id,
        cheque_no    : c.cheque_no      || '',
        t_code       : c.t_code         || '',
        customer_name: c.customer_name  || c.t_code || '',
        cheque_amount: parseFloat(c.total_amount || 0),
        cheque_date  : c.cheque_date    || '',
        bank_code    : c.bank_code      || '',
        branch_code  : c.branch_code    || '',
        bank_name    : c.bank_name      || '',
        branch_name  : c.branch_name    || '',
    }));

    const btn = getEl('lmSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData();
    fd.append('ajax_action',        'save_letter');
    fd.append('letter_date',        d);
    fd.append('company_account_id', _selAcc);
    fd.append('deposit_type', (jQuery('#fDepTypes').val()||[]).join(',') || 'normal');
    fd.append('notes',              getEl('lmNotes').value);
    fd.append('items',              JSON.stringify(items));

    try {
        const res  = await fetch('cheque_deposit_letter.php',{method:'POST',body:fd});
        const data = await res.json();
        if (!data.success) throw new Error(data.error||'Save failed');
        closeLetterModal();
        showToast('✓ Letter ' + data.letter_no + ' saved','ok');
        clearCart();
        setTimeout(() => location.reload(), 1300);
    } catch(e) { showToast('Error: '+e.message,'err'); }

    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Save & Generate Letter';
}

/* ════════════════════════════════════
   VIEW / PRINT LETTER
════════════════════════════════════ */
async function viewLetter(id) {
    try {
        const res  = await fetch('cheque_deposit_letter.php?ajax=letter_detail&letter_id='+id);
        const data = await res.json();
        if (!data.success) throw new Error(data.error||'Not found');
        buildPrint(data.letter, data.items);
    } catch(e) { showToast('Error: '+e.message,'err'); }
}
function buildPrint(letter, items) {
    const total  = parseFloat(letter.total_amount||0);
    const dtLbls = {normal:'Normal Deposit',bulk:'Bulk Deposit',normal_bulk:'Normal Bulk Deposit'};
    getEl('pmTitleNo').textContent = ' ' + letter.letter_no;
    let rows = '';
    items.forEach((it,i) => {
        rows += `<tr style="border-bottom:1px solid #f0f0f0;">
          <td style="padding:6px 8px;text-align:center;color:#6b7280;font-size:11px;">${i+1}</td>
          <td style="padding:6px 8px;font-family:'Courier New',monospace;font-size:12px;font-weight:700;color:#1e1b4b;">${esc(it.cheque_no)}</td>
          <td style="padding:6px 8px;font-size:12px;">${esc(it.customer_name||it.t_code||'—')}</td>
          <td style="padding:6px 8px;font-size:11px;font-family:'Courier New',monospace;">${esc(it.bank_code||'—')}</td>
          <td style="padding:6px 8px;font-size:11px;font-family:'Courier New',monospace;">${esc(it.branch_code||'—')}</td>
          <td style="padding:6px 8px;text-align:center;font-size:11.5px;">${esc(it.cheque_date||'—')}</td>
          <td style="padding:6px 8px;text-align:right;font-weight:700;font-size:12px;color:#1e3a8a;">Rs. ${parseFloat(it.cheque_amount||0).toLocaleString('en-US',{minimumFractionDigits:2})}</td>
        </tr>`;
    });
    getEl('pmContent').innerHTML = `
    <div style="font-family:Arial,sans-serif;color:#1f2937;max-width:750px;margin:0 auto;">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:22px;padding-bottom:14px;border-bottom:2.5px solid #1e1b4b;">
        <div>
          <div style="font-size:20px;font-weight:900;color:#1e1b4b;">${esc(CO_NAME)}</div>
          <div style="font-size:12px;color:#6b7280;margin-top:3px;">Cheque Deposit Letter</div>
        </div>
        <div style="text-align:right;">
          <div style="font-size:18px;font-weight:800;color:#1e1b4b;font-family:'Courier New',monospace;">${esc(letter.letter_no)}</div>
          <div style="font-size:12px;color:#6b7280;margin-top:2px;">Date: <strong>${esc(letter.letter_date)}</strong></div>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
        <div style="background:#f8faff;border:1px solid #e0e7ff;border-radius:8px;padding:12px 14px;">
          <div style="font-size:9px;font-weight:700;color:#6366f1;text-transform:uppercase;letter-spacing:.06em;margin-bottom:7px;">To — Company Bank Account</div>
          <div style="font-size:14px;font-weight:800;color:#1e1b4b;">${esc(letter.bank_name)}</div>
          <div style="font-size:12px;color:#374151;margin-top:3px;">${esc(letter.account_name)}</div>
          <div style="font-family:'Courier New',monospace;font-size:13px;color:#0369a1;font-weight:700;margin-top:3px;">${esc(letter.account_no)}</div>
        </div>
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px 14px;">
          <div style="font-size:9px;font-weight:700;color:#16a34a;text-transform:uppercase;letter-spacing:.06em;margin-bottom:7px;">Deposit Summary</div>
          <div style="display:flex;justify-content:space-between;margin-bottom:4px;"><span style="font-size:12px;color:#6b7280;">Deposit Type</span><span style="font-size:12px;font-weight:700;">${esc(dtLbls[letter.deposit_type]||letter.deposit_type)}</span></div>
          <div style="display:flex;justify-content:space-between;margin-bottom:4px;"><span style="font-size:12px;color:#6b7280;">Total Cheques</span><span style="font-size:12px;font-weight:700;">${esc(String(letter.total_cheques))}</span></div>
          <div style="display:flex;justify-content:space-between;"><span style="font-size:12px;color:#6b7280;">Total Amount</span><span style="font-size:14px;font-weight:900;color:#166534;">Rs. ${total.toLocaleString('en-US',{minimumFractionDigits:2})}</span></div>
        </div>
      </div>
      <p style="font-size:12.5px;line-height:1.7;margin-bottom:14px;">Dear Sir/Madam,<br><br>We hereby submit the following cheque(s) for deposit to the above-mentioned account. Please find the details below:</p>
      <table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:16px;">
        <thead>
          <tr style="background:#1e1b4b;">
            <th style="padding:8px;text-align:center;color:#e0e7ff;font-size:10.5px;width:32px;">#</th>
            <th style="padding:8px;text-align:left;color:#e0e7ff;font-size:10.5px;">Cheque No.</th>
            <th style="padding:8px;text-align:left;color:#e0e7ff;font-size:10.5px;">Customer / Drawer</th>
            <th style="padding:8px;text-align:left;color:#e0e7ff;font-size:10.5px;">Bank</th>
            <th style="padding:8px;text-align:left;color:#e0e7ff;font-size:10.5px;">Branch</th>
            <th style="padding:8px;text-align:center;color:#e0e7ff;font-size:10.5px;">Cheque Date</th>
            <th style="padding:8px;text-align:right;color:#e0e7ff;font-size:10.5px;">Amount (Rs.)</th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
        <tfoot>
          <tr style="background:#0f172a;">
            <td colspan="6" style="padding:9px 8px;font-weight:800;font-size:12px;color:#e2e8f0;">TOTAL AMOUNT</td>
            <td style="padding:9px 8px;font-weight:900;font-size:14px;color:#4ade80;text-align:right;">Rs. ${total.toLocaleString('en-US',{minimumFractionDigits:2})}</td>
          </tr>
        </tfoot>
      </table>
      ${letter.notes?`<div style="background:#fef3c7;border-left:4px solid #f59e0b;border-radius:0 7px 7px 0;padding:9px 12px;margin-bottom:16px;font-size:12px;color:#92400e;"><strong>Note:</strong> ${esc(letter.notes)}</div>`:''}
      <p style="font-size:12.5px;line-height:1.7;margin-bottom:30px;">Kindly acknowledge receipt of the above cheques and credit our account accordingly. Thank you.</p>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-top:20px;">
        <div style="text-align:center;"><div style="border-top:1px solid #374151;padding-top:8px;font-size:12px;color:#6b7280;margin-top:50px;">Prepared By</div></div>
        <div style="text-align:center;"><div style="border-top:1px solid #374151;padding-top:8px;font-size:12px;color:#6b7280;margin-top:50px;">Authorized Signature</div></div>
      </div>
      <div style="margin-top:20px;padding-top:10px;border-top:1px dashed #d1d5db;font-size:10px;color:#9ca3af;text-align:center;">
        Generated by YMS · ${esc(CO_NAME)} · ${esc(letter.letter_no)} · ${esc(letter.letter_date)}
      </div>
    </div>`;
    getEl('printModal').classList.add('open');
    getEl('printModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}
function closePrintModal() {
    getEl('printModal').classList.remove('open');
    getEl('printModal').style.display = 'none';
    document.body.style.overflow = '';
}

/* ════════════════════════════════════
   DELETE LETTER
════════════════════════════════════ */
async function deleteLetter(id, no) {
    if (!confirm('Delete letter ' + no + '?\nThis cannot be undone.')) return;
    const fd = new FormData();
    fd.append('ajax_action','delete_letter');
    fd.append('letter_id', id);
    try {
        const res  = await fetch('cheque_deposit_letter.php',{method:'POST',body:fd});
        const data = await res.json();
        if (!data.success) throw new Error(data.error||'Delete failed');
        const row = getEl('letter-row-'+id);
        if (row) row.remove();
        showToast('Letter ' + no + ' deleted','ok');
    } catch(e) { showToast('Error: '+e.message,'err'); }
}

/* ════════════════════════════════════
   UTILITIES
════════════════════════════════════ */
function esc(s) {
    if (s == null) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                    .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
function fmtDate(s) {
    if (!s || s === '0000-00-00') return '<span style="color:#d1d5db;">—</span>';
    try {
        const d = new Date(s.replace(' ','T'));
        const m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return String(d.getDate()).padStart(2,'0') + ' ' + m[d.getMonth()] + ' ' + d.getFullYear();
    } catch(e) { return s; }
}
function showToast(msg, type) {
    const t = getEl('toast');
    t.style.background = (type==='ok') ? '#166534' : '#dc2626';
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(t._tt);
    t._tt = setTimeout(() => t.classList.remove('show'), 3200);
}

/* Close on backdrop click / Escape */
getEl('letterModal').addEventListener('click', function(e){ if(e.target===this) closeLetterModal(); });
getEl('printModal').addEventListener('click', function(e){ if(e.target===this) closePrintModal(); });
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closePrintModal(); closeLetterModal(); }
});

/* Move modals to document.body to escape any header stacking context */
(function() {
    ['letterModal','printModal'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el && el.parentNode !== document.body) {
            document.body.appendChild(el);
        }
    });
})();
</script>

<?php include 'footer.php'; ?>