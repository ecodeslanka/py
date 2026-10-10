<?php
/**
 * cheque_deposit_list.php
 * ─────────────────────────────────────────────────────────────────
 *  • Auto-loads ALL deposits on page open (no search button needed)
 *  • Filter bar: date range, account, type, live search + quick shortcuts
 *  • "View" button → slide-in panel showing all cheques in that batch
 *  • Manual status change + bank ref per cheque inside the panel
 *  • NDB Bank CSV import:
 *      "Outward Cheque Deposit/CHQ NO - XXXX"  → Cleared + save bank ref
 *      "Outward Clg Chq Return/CHQ NO - XXXX"   → Returned
 *  • Preview table with match status BEFORE applying any DB changes
 *  • All changes logged to cheque_logs
 *  • Deposit types: normal | bulk | normal_bulk
 * ─────────────────────────────────────────────────────────────────
 */
if (session_status() === PHP_SESSION_NONE) session_start();

function get_current_user_label() {
    return $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['name'] ??
           $_SESSION['full_name'] ?? $_SESSION['email'] ??
           (isset($_SESSION['user_id']) ? 'User #'.$_SESSION['user_id'] : 'system');
}

include_once 'config.php';

/* ══════════════════════════════════════════════════════════════════
   AJAX HANDLERS  (before header.php)
══════════════════════════════════════════════════════════════════ */

/* ── AJAX: cheques for one deposit batch ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'batch_cheques') {
    header('Content-Type: application/json');
    $dep_date = trim(mysqli_real_escape_string($conn, $_GET['dep_date'] ?? ''));
    $acc_id   = intval($_GET['acc_id'] ?? 0);
    $dep_type = trim(mysqli_real_escape_string($conn, $_GET['dep_type'] ?? ''));
    if (!$dep_date) { echo json_encode(['success'=>false,'error'=>'Date required']); exit; }
    $w = ["ch.status IN('deposited','cleared','returned','sent_back')", "ch.deposit_date='$dep_date'"];
    if ($acc_id)   $w[] = "ch.deposited_account_id=$acc_id";
    if ($dep_type) $w[] = "ch.deposit_type='$dep_type'";
    $has_ref = mysqli_query($conn,"SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cheques' AND COLUMN_NAME='bank_ref' LIMIT 1");
    $ref_col = ($has_ref && mysqli_num_rows($has_ref)>0) ? "COALESCE(ch.bank_ref,'')" : "''";
    $sql = "SELECT ch.id, ch.cheque_no, ch.cheque_date, ch.total_amount,
                   ch.bank_code, ch.bank_name, ch.branch_code, ch.branch_name,
                   ch.status, ch.t_code,
                   COALESCE(NULLIF(ch.cheque_mode,''),'') AS cheque_mode,
                   $ref_col AS bank_ref,
                   COALESCE(NULLIF(fsd.customer_name,''), NULLIF(c.shop_name,''), ch.t_code) AS customer_name
            FROM cheques ch
            LEFT JOIN invoice_payments      ip  ON ip.id  = ch.invoice_payment_id
            LEFT JOIN field_summary         fs  ON fs.id  = ip.field_summary_id
            LEFT JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
            LEFT JOIN customers             c   ON c.t_code = ch.t_code
            WHERE ".implode(' AND ',$w)."
            ORDER BY ch.cheque_no ASC";
    $r = mysqli_query($conn,$sql); $rows=[];
    if ($r) {
        while ($row=mysqli_fetch_assoc($r)) $rows[]=$row;
    } else {
        echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]); exit;
    }
    echo json_encode(['success'=>true,'cheques'=>$rows]);
    exit;
}

/* ── AJAX: update single cheque status ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_cheque_status') {
    header('Content-Type: application/json');
    $cid    = intval($_POST['cheque_id'] ?? 0);
    $status = trim(mysqli_real_escape_string($conn, $_POST['status'] ?? ''));
    $ref    = trim(mysqli_real_escape_string($conn, $_POST['bank_ref'] ?? ''));
    $valid  = ['deposited','cleared','returned','sent_back','pending'];
    if (!$cid || !in_array($status,$valid)) { echo json_encode(['success'=>false,'error'=>'Invalid params']); exit; }
    $col_chk = mysqli_query($conn,"SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cheques' AND COLUMN_NAME='bank_ref' LIMIT 1");
    if (!($col_chk && mysqli_num_rows($col_chk)>0)) @mysqli_query($conn,"ALTER TABLE cheques ADD COLUMN bank_ref VARCHAR(100) DEFAULT NULL");
    $old_r  = mysqli_query($conn,"SELECT status FROM cheques WHERE id=$cid LIMIT 1");
    $old_st = ($old_r && $row=mysqli_fetch_assoc($old_r)) ? $row['status'] : '';
    $extra  = $ref ? ", bank_ref='$ref'" : '';
    if (mysqli_query($conn,"UPDATE cheques SET status='$status'$extra WHERE id=$cid")) {
        @mysqli_query($conn,"CREATE TABLE IF NOT EXISTS cheque_logs(id INT AUTO_INCREMENT PRIMARY KEY,
            cheque_id INT NOT NULL,action VARCHAR(100),old_value TEXT,new_value TEXT,note TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,created_by VARCHAR(100) DEFAULT 'system',
            INDEX idx_cid(cheque_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $cu   = mysqli_real_escape_string($conn, get_current_user_label());
        $note = mysqli_real_escape_string($conn, "Manual status change".($ref?" | Bank Ref: $ref":""));
        $old_e= mysqli_real_escape_string($conn, $old_st);
        mysqli_query($conn,"INSERT INTO cheque_logs(cheque_id,action,old_value,new_value,note,created_by)
            VALUES($cid,'status_change','$old_e','$status','$note','$cu')");
        echo json_encode(['success'=>true]);
    } else { echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]); }
    exit;
}

/* ── AJAX: NDB preview (DB matching, no changes) ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'ndb_preview') {
    header('Content-Type: application/json');
    $parsed = json_decode($_POST['parsed_rows'] ?? '[]', true);
    if (!is_array($parsed)) { echo json_encode(['success'=>false,'error'=>'Bad data']); exit; }
    $results = [];
    foreach ($parsed as $p) {
        $chq_no  = trim(mysqli_real_escape_string($conn, $p['cheque_no'] ?? ''));
        $amount  = floatval($p['amount'] ?? 0);
        $type    = $p['type'] ?? '';
        $bank_ref= trim(mysqli_real_escape_string($conn, $p['bank_ref'] ?? ''));
        $tx_date = trim(mysqli_real_escape_string($conn, $p['tx_date'] ?? ''));
        if (!$chq_no) continue;
        $r = mysqli_query($conn,"SELECT ch.id, ch.cheque_no, ch.total_amount, ch.status, ch.t_code,
                COALESCE(NULLIF(c.shop_name,''), ch.t_code) AS customer_name
             FROM cheques ch LEFT JOIN customers c ON c.t_code=ch.t_code
             WHERE ch.cheque_no='$chq_no' LIMIT 1");
        $db = ($r && $row=mysqli_fetch_assoc($r)) ? $row : null;
        $results[] = [
            'cheque_no'     => $p['cheque_no'],
            'bank_amount'   => $amount,
            'bank_ref'      => $p['bank_ref'],
            'tx_date'       => $p['tx_date'],
            'description'   => $p['description'] ?? '',
            'type'          => $type,
            'matched'       => $db !== null,
            'db_id'         => $db['id']           ?? null,
            'db_status'     => $db['status']       ?? null,
            'db_amount'     => $db['total_amount']  ?? null,
            'db_t_code'     => $db['t_code']       ?? null,
            'customer_name' => $db['customer_name'] ?? null,
            'amt_match'     => $db ? (abs(floatval($db['total_amount']) - $amount) < 0.01) : false,
        ];
    }
    echo json_encode(['success'=>true,'rows'=>$results]);
    exit;
}

/* ── AJAX: NDB apply confirmed updates ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'ndb_apply') {
    header('Content-Type: application/json');
    $items = json_decode($_POST['items'] ?? '[]', true);
    if (!is_array($items)) { echo json_encode(['success'=>false,'error'=>'Bad data']); exit; }
    $col_chk2 = mysqli_query($conn,"SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cheques' AND COLUMN_NAME='bank_ref' LIMIT 1");
    if (!($col_chk2 && mysqli_num_rows($col_chk2)>0)) @mysqli_query($conn,"ALTER TABLE cheques ADD COLUMN bank_ref VARCHAR(100) DEFAULT NULL");
    @mysqli_query($conn,"CREATE TABLE IF NOT EXISTS cheque_logs(id INT AUTO_INCREMENT PRIMARY KEY,
        cheque_id INT NOT NULL,action VARCHAR(100),old_value TEXT,new_value TEXT,note TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,created_by VARCHAR(100) DEFAULT 'system',
        INDEX idx_cid(cheque_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $updated=0; $skipped=0; $skip_list=[];
    $cu = mysqli_real_escape_string($conn, get_current_user_label());
    foreach ($items as $item) {
        $db_id   = intval($item['db_id'] ?? 0);
        $new_st  = ($item['type'] === 'returned') ? 'returned' : 'cleared';
        $bank_ref= mysqli_real_escape_string($conn, trim($item['bank_ref'] ?? ''));
        $tx_date = mysqli_real_escape_string($conn, trim($item['tx_date'] ?? ''));
        if (!$db_id) { $skipped++; $skip_list[]=$item['cheque_no']; continue; }
        $old_r  = mysqli_query($conn,"SELECT status FROM cheques WHERE id=$db_id LIMIT 1");
        $old_st = ($old_r && $row=mysqli_fetch_assoc($old_r)) ? mysqli_real_escape_string($conn,$row['status']) : '';
        if (mysqli_query($conn,"UPDATE cheques SET status='$new_st', bank_ref='$bank_ref' WHERE id=$db_id")) {
            $note = mysqli_real_escape_string($conn, "NDB Import | Tx Date: {$item['tx_date']} | Bank Ref: {$item['bank_ref']}");
            mysqli_query($conn,"INSERT INTO cheque_logs(cheque_id,action,old_value,new_value,note,created_by)
                VALUES($db_id,'status_change','$old_st','$new_st','$note','$cu')");
            $updated++;
        } else { $skipped++; $skip_list[]=$item['cheque_no']; }
    }
    echo json_encode(['success'=>true,'updated'=>$updated,'skipped'=>$skipped,'skip_list'=>$skip_list]);
    exit;
}

/* ══════════════════════════════════════════════════════════════════
   NORMAL PAGE
══════════════════════════════════════════════════════════════════ */
include 'header.php';

// ── Ensure bank_ref column exists ──
$col_chk3 = mysqli_query($conn,"SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cheques' AND COLUMN_NAME='bank_ref' LIMIT 1");
if (!($col_chk3 && mysqli_num_rows($col_chk3)>0)) {
    @mysqli_query($conn,"ALTER TABLE cheques ADD COLUMN bank_ref VARCHAR(100) DEFAULT NULL");
}

// ── Verify cheques table is reachable ──
$tbl_test = mysqli_query($conn,"SELECT COUNT(*) AS c FROM cheques WHERE deposit_date IS NOT NULL AND status IN('deposited','cleared','returned') LIMIT 1");
$tbl_err  = $tbl_test ? '' : mysqli_error($conn);

/* ── KPI — includes amounts per status and not-cleared count/amount ── */
$kpi = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) AS total_cheques,
            COALESCE(SUM(total_amount),0)                                        AS total_amount,
            COUNT(DISTINCT deposit_date)                                         AS deposit_days,
            COUNT(DISTINCT deposited_account_id)                                 AS accounts_used,
            /* Cleared */
            SUM(CASE WHEN status='cleared'           THEN 1    ELSE 0 END)       AS cleared_count,
            COALESCE(SUM(CASE WHEN status='cleared'  THEN total_amount ELSE 0 END),0) AS cleared_amount,
            /* Returned */
            SUM(CASE WHEN status='returned'          THEN 1    ELSE 0 END)       AS returned_count,
            COALESCE(SUM(CASE WHEN status='returned' THEN total_amount ELSE 0 END),0) AS returned_amount,
            /* Not Cleared (deposited / sent_back / pending — anything not cleared or returned) */
            SUM(CASE WHEN status NOT IN('cleared','returned') THEN 1    ELSE 0 END) AS notclr_count,
            COALESCE(SUM(CASE WHEN status NOT IN('cleared','returned') THEN total_amount ELSE 0 END),0) AS notclr_amount,
            /* Deposit type breakdown */
            SUM(CASE WHEN deposit_type='bulk'        THEN 1 ELSE 0 END)          AS bulk_count,
            SUM(CASE WHEN deposit_type='normal'      THEN 1 ELSE 0 END)          AS normal_count,
            SUM(CASE WHEN deposit_type='normal_bulk' THEN 1 ELSE 0 END)          AS normal_bulk_count
     FROM cheques WHERE status IN('deposited','cleared','returned','sent_back','pending') AND deposit_date IS NOT NULL")) ?: [];

/* ── All deposit batches ── */
$batches = [];
$batches_sql = "
SELECT ch.deposit_date,
       ch.deposited_account_id,
       COALESCE(cba.account_name,'Unknown Account')  AS account_name,
       COALESCE(cba.account_no,'')                   AS account_no,
       COALESCE(cba.bank_code,'')                    AS bank_name,
       COALESCE(cba.branch_code,'')                  AS branch_name,
       ''                                             AS company_name,
       COALESCE(ch.deposit_type,'normal')             AS deposit_type,
       COUNT(ch.id)                                   AS cheque_count,
       COALESCE(SUM(ch.total_amount),0)               AS total_amount,
       SUM(CASE WHEN ch.status='cleared'   THEN 1 ELSE 0 END) AS cleared_count,
       SUM(CASE WHEN ch.status='returned'  THEN 1 ELSE 0 END) AS returned_count,
       SUM(CASE WHEN ch.status='deposited' THEN 1 ELSE 0 END) AS deposited_count,
       MIN(ch.cheque_date) AS min_cheque_date,
       MAX(ch.cheque_date) AS max_cheque_date
FROM cheques ch
LEFT JOIN company_bank_accounts cba ON cba.id = ch.deposited_account_id
WHERE ch.status IN('deposited','cleared','returned')
  AND ch.deposit_date IS NOT NULL
GROUP BY ch.deposit_date, ch.deposited_account_id, ch.deposit_type
ORDER BY ch.deposit_date DESC, cba.account_name ASC";
$r = mysqli_query($conn, $batches_sql);
if (!$r) {
    $r = mysqli_query($conn,"
        SELECT ch.deposit_date,
               ch.deposited_account_id,
               'Account' AS account_name,
               ''        AS account_no,
               ''        AS bank_name,
               ''        AS branch_name,
               ''        AS company_name,
               COALESCE(ch.deposit_type,'normal') AS deposit_type,
               COUNT(ch.id)                        AS cheque_count,
               COALESCE(SUM(ch.total_amount),0)    AS total_amount,
               SUM(CASE WHEN ch.status='cleared'   THEN 1 ELSE 0 END) AS cleared_count,
               SUM(CASE WHEN ch.status='returned'  THEN 1 ELSE 0 END) AS returned_count,
               SUM(CASE WHEN ch.status='deposited' THEN 1 ELSE 0 END) AS deposited_count,
               MIN(ch.cheque_date) AS min_cheque_date,
               MAX(ch.cheque_date) AS max_cheque_date
        FROM cheques ch
        WHERE ch.status IN('deposited','cleared','returned')
          AND ch.deposit_date IS NOT NULL
        GROUP BY ch.deposit_date, ch.deposited_account_id, ch.deposit_type
        ORDER BY ch.deposit_date DESC");
}
if ($r) while ($row=mysqli_fetch_assoc($r)) $batches[]=$row;

$grand_count  = array_sum(array_column($batches,'cheque_count') ?: [0]);
$grand_amount = array_sum(array_column($batches,'total_amount')  ?: [0]);
$max_amt      = max(array_column($batches,'total_amount') ?: [1]);

/* Company accounts for NDB dropdown */
$acc_rows = [];
$ar = mysqli_query($conn,"SELECT id, account_name, account_no FROM company_bank_accounts ORDER BY account_name");
if ($ar) while ($row=mysqli_fetch_assoc($ar)) $acc_rows[]=$row;

/* ── Pre-compute KPI rates ── */
$kpi_total     = intval($kpi['total_cheques'] ?? 0);
$kpi_clr_cnt   = intval($kpi['cleared_count']  ?? 0);
$kpi_clr_amt   = floatval($kpi['cleared_amount'] ?? 0);
$kpi_ret_cnt   = intval($kpi['returned_count'] ?? 0);
$kpi_ret_amt   = floatval($kpi['returned_amount'] ?? 0);
$kpi_nc_cnt    = intval($kpi['notclr_count']   ?? 0);
$kpi_nc_amt    = floatval($kpi['notclr_amt']   ?? $kpi['notclr_amount'] ?? 0);
$kpi_tot_amt   = floatval($kpi['total_amount'] ?? 0);

$rate_clr = $kpi_total > 0 ? round(($kpi_clr_cnt / $kpi_total) * 100, 1) : 0;
$rate_ret = $kpi_total > 0 ? round(($kpi_ret_cnt / $kpi_total) * 100, 1) : 0;
$rate_nc  = $kpi_total > 0 ? round(($kpi_nc_cnt  / $kpi_total) * 100, 1) : 0;
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
.btn-success{background:#16a34a;color:#fff}.btn-success:hover{background:#15803d}
.btn-teal{background:linear-gradient(135deg,#0d9488,#14b8a6);color:#fff;border:none}.btn-teal:hover{filter:brightness(1.08)}
.btn-sm{padding:5px 12px;font-size:11px}

/* ════ KPI — Status Trio + Summary Row ════ */
.kpi-section{margin-bottom:20px}

/* Big 3-column status cards */
.kpi-status-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:14px}
.kpi-status-card{background:#fff;border-radius:12px;padding:14px 18px;box-shadow:0 2px 8px rgba(0,0,0,.06);border:1.5px solid transparent;position:relative;overflow:hidden}
.kpi-status-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;border-radius:12px 12px 0 0}
.ksc-green{border-color:#bbf7d0}.ksc-green::before{background:linear-gradient(90deg,#16a34a,#4ade80)}
.ksc-red{border-color:#fecaca}.ksc-red::before{background:linear-gradient(90deg,#dc2626,#f87171)}
.ksc-blue{border-color:#bfdbfe}.ksc-blue::before{background:linear-gradient(90deg,#2563eb,#60a5fa)}

/* label row */
.ksc-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.ksc-label{display:flex;align-items:center;gap:6px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.07em}
.ksc-green .ksc-label{color:#166534}
.ksc-red   .ksc-label{color:#991b1b}
.ksc-blue  .ksc-label{color:#1e40af}
.ksc-icon{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0}
.ksc-green .ksc-icon{background:#dcfce7;color:#16a34a}
.ksc-red   .ksc-icon{background:#fee2e2;color:#dc2626}
.ksc-blue  .ksc-icon{background:#dbeafe;color:#2563eb}

/* inline stats row: count | divider | amount | divider | rate% */
.ksc-stats-row{display:flex;align-items:center;gap:0;margin-bottom:10px}
.ksc-stat{display:flex;flex-direction:column;gap:2px;flex:1;min-width:0}
.ksc-stat-lbl{font-size:9px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap}
.ksc-stat-val{font-weight:800;line-height:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ksc-stat-val.big{font-size:26px}
.ksc-stat-val.mid{font-size:13px}
.ksc-stat-val.pct{font-size:18px}
.ksc-green .ksc-stat-val{color:#15803d}
.ksc-red   .ksc-stat-val{color:#b91c1c}
.ksc-blue  .ksc-stat-val{color:#1d4ed8}
.ksc-divider{width:1px;background:#e5e7eb;height:36px;margin:0 14px;flex-shrink:0}

/* progress bar */
.ksc-rate-wrap{display:flex;align-items:center;gap:8px}
.ksc-rate-bar-track{flex:1;background:#e5e7eb;border-radius:99px;height:5px;overflow:hidden}
.ksc-green .ksc-rate-bar-track .ksc-rate-bar{background:linear-gradient(90deg,#16a34a,#4ade80);height:100%;border-radius:99px;transition:width .6s ease}
.ksc-red   .ksc-rate-bar-track .ksc-rate-bar{background:linear-gradient(90deg,#dc2626,#f87171);height:100%;border-radius:99px;transition:width .6s ease}
.ksc-blue  .ksc-rate-bar-track .ksc-rate-bar{background:linear-gradient(90deg,#2563eb,#60a5fa);height:100%;border-radius:99px;transition:width .6s ease}
.ksc-rate-pct-sm{font-size:10.5px;font-weight:700;white-space:nowrap;min-width:34px;text-align:right}
.ksc-green .ksc-rate-pct-sm{color:#16a34a}
.ksc-red   .ksc-rate-pct-sm{color:#dc2626}
.ksc-blue  .ksc-rate-pct-sm{color:#2563eb}

/* Small 3-card summary row */
.kpi-summary-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
.kpi-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:14px 16px;box-shadow:0 1px 3px rgba(0,0,0,.04);position:relative;overflow:hidden}
.kpi-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:10px 10px 0 0}
.kc-teal::before{background:linear-gradient(90deg,#0d9488,#14b8a6)}
.kc-sky::before{background:linear-gradient(90deg,#0369a1,#0ea5e9)}
.kc-amber::before{background:linear-gradient(90deg,#d97706,#f59e0b)}
.kpi-lbl{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px}
.kpi-val{font-size:20px;font-weight:800;line-height:1;margin-bottom:3px}
.kpi-sub{font-size:10.5px;color:#9ca3af}
.kc-teal .kpi-val{color:#0d9488}.kc-sky .kpi-val{color:#0369a1}.kc-amber .kpi-val{color:#d97706}
/* Deposit type breakdown inside amber card */
.dep-type-breakdown{display:flex;flex-direction:column;gap:3px;margin-top:6px}
.dtb-row{display:flex;align-items:center;justify-content:space-between;gap:4px}
.dtb-pill{display:inline-flex;align-items:center;gap:3px;padding:1px 7px;border-radius:10px;font-size:9.5px;font-weight:700;white-space:nowrap}
.dtb-normal{background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe}
.dtb-bulk{background:#ede9fe;color:#5b21b6;border:1px solid #c4b5fd}
.dtb-normal-bulk{background:#fef3c7;color:#92400e;border:1px solid #fcd34d}
.dtb-cnt{font-size:11px;font-weight:800;color:#374151}

/* Filter bar */
.filter-bar{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:12px 16px;margin-bottom:16px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.filter-row{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap}
.ffg{display:flex;flex-direction:column;gap:4px}
.ffg label{font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em}
.ffg input,.ffg select{border:1px solid #e5e5e5;border-radius:7px;padding:7px 10px;font-size:13px;font-family:inherit;color:#1f2937;background:#fff;outline:none;transition:border .2s}
.ffg input:focus,.ffg select:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.1)}
.sc-wrap{display:flex;gap:5px;flex-wrap:wrap;margin-top:10px;padding-top:10px;border-top:1px solid #f0f0f0;align-items:center}
.sc-lbl{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em}
.sc-btn{background:#f3f4f6;border:1px solid #e5e5e5;border-radius:6px;padding:4px 10px;font-size:11px;font-weight:600;color:#374151;cursor:pointer;font-family:inherit;transition:all .15s;white-space:nowrap}
.sc-btn:hover{background:#ede9fe;color:#5b21b6;border-color:#c4b5fd}

/* Table */
.table-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-bottom:1px solid #f0f0f0;background:#fafafa;flex-wrap:wrap;gap:8px}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.pill{padding:2px 10px;border-radius:12px;font-size:11px;font-weight:600;white-space:nowrap}
.p-teal{background:#ccfbf1;color:#0f766e}.p-violet{background:#ede9fe;color:#5b21b6}
.p-blue{background:#dbeafe;color:#1e40af}.p-green{background:#dcfce7;color:#166534}
.p-red{background:#fee2e2;color:#991b1b}.p-gray{background:#f3f4f6;color:#374151}
.pager{display:flex;gap:4px;flex-wrap:wrap;align-items:center}
.pager-btn{background:#fff;border:1.5px solid #e0e7ff;border-radius:6px;padding:4px 9px;font-size:11px;font-weight:600;color:#4f46e5;cursor:pointer;font-family:inherit;transition:all .15s}
.pager-btn:hover{background:#ede9fe}.pager-btn.active{background:#6366f1;color:#fff;border-color:#6366f1}
.pager-btn:disabled{opacity:.4;cursor:not-allowed}
.pager-info{font-size:11px;color:#6b7280}
.chq-search-box{position:relative;display:flex;align-items:center}
.chq-search-box input{border:1.5px solid #e0e7ff;border-radius:8px;padding:7px 12px 7px 32px;font-size:12.5px;font-family:inherit;width:200px;outline:none;transition:all .2s;background:#fff}
.chq-search-box input:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.1);width:250px}
.chq-search-box .si{position:absolute;left:9px;color:#9ca3af;font-size:11px;pointer-events:none}
.dt-outer{overflow-x:auto;max-height:72vh;overflow-y:auto}
.data-table{width:100%;border-collapse:collapse;font-size:12px;min-width:900px}
.data-table thead th{padding:9px 10px;text-align:left;font-weight:700;font-size:10.5px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;border-right:1px solid rgba(255,255,255,.08);position:sticky;top:0;z-index:10}
.data-table thead th.tr{text-align:right}.data-table thead th.tc{text-align:center}
.data-table tbody tr.dep-row{border-bottom:1px solid #f0f2f5;transition:background .1s}
.data-table tbody tr.dep-row:hover td{background:#f8faff!important}
.data-table td{padding:9px 10px;color:#374151;vertical-align:middle;background:#fff}
.tr{text-align:right}.tc{text-align:center}
.data-table tfoot td{padding:10px;font-weight:800;font-size:12px;background:#0f172a;color:#e2e8f0;border-top:2px solid #334155;position:sticky;bottom:0}
.data-table tfoot td.tr{text-align:right}
.dep-date-badge{display:inline-flex;flex-direction:column;align-items:center;background:linear-gradient(135deg,#1e1b4b,#312e81);color:#fff;border-radius:8px;padding:5px 11px;min-width:68px;text-align:center}
.ddb-day{font-size:18px;font-weight:800;line-height:1}.ddb-mo{font-size:10px;font-weight:600;opacity:.8;text-transform:uppercase;letter-spacing:.04em}
.cnt-badge{display:inline-flex;align-items:center;gap:4px;background:linear-gradient(135deg,#0d9488,#14b8a6);color:#fff;border-radius:20px;padding:3px 11px;font-size:12px;font-weight:800;white-space:nowrap}
.cnt-badge.bulk{background:linear-gradient(135deg,#7c3aed,#8b5cf6)}
.cnt-badge.normal_bulk{background:linear-gradient(135deg,#d97706,#f59e0b)}
.prog-wrap{background:#f0f2f5;border-radius:4px;height:4px;width:80px;overflow:hidden;margin-top:4px}
.prog-bar-g{height:100%;border-radius:4px;background:linear-gradient(90deg,#0d9488,#14b8a6)}
.dep-type-pill{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap}
.dtp-normal{background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe}
.dtp-bulk{background:#ede9fe;color:#5b21b6;border:1px solid #c4b5fd}
.dtp-normal-bulk{background:#fef3c7;color:#92400e;border:1px solid #fcd34d}
.bst-row{display:flex;gap:5px;flex-wrap:wrap;justify-content:center;margin-top:5px}
.bsp{display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700;white-space:nowrap}
.bsp-dep{background:#dbeafe;color:#1e40af}.bsp-clr{background:#dcfce7;color:#166534}.bsp-ret{background:#fee2e2;color:#991b1b}
.view-btn{display:inline-flex;align-items:center;gap:4px;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border:none;border-radius:7px;padding:6px 14px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;transition:filter .2s;text-decoration:none}
.view-btn:hover{filter:brightness(1.1)}
.amt-large{font-size:14px;font-weight:800;color:#0d9488;white-space:nowrap}
.mono{font-family:'Courier New',monospace;font-weight:700}
.bank-info .bn{font-size:12.5px;font-weight:700;color:#1f2937}
.bank-info .bno{font-family:'Courier New',monospace;font-size:11px;color:#0369a1;margin-top:1px;font-weight:700}
.bank-info .bsub{font-size:10.5px;color:#6b7280;margin-top:1px}
.bank-av{width:36px;height:36px;border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,#0d9488,#14b8a6);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:800}

/* PANEL */
#detailPanel{position:fixed;top:0;right:0;bottom:0;width:840px;max-width:96vw;background:#fff;box-shadow:-8px 0 48px rgba(0,0,0,.28);z-index:99999;transform:translateX(110%);transition:transform .32s cubic-bezier(.16,1,.3,1);display:flex;flex-direction:column}
#detailPanel.open{transform:translateX(0)}
#panelOverlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99998;opacity:0;pointer-events:none;transition:opacity .3s}
#panelOverlay.open{opacity:1;pointer-events:auto}
.panel-hdr{background:linear-gradient(135deg,#1e1b4b,#312e81);padding:14px 20px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.panel-title{color:#fff;font-size:15px;font-weight:800;display:flex;align-items:center;gap:9px}
.panel-x{background:rgba(255,255,255,.15);border:none;border-radius:8px;color:#fff;width:32px;height:32px;cursor:pointer;font-size:15px;display:flex;align-items:center;justify-content:center;transition:background .2s}
.panel-x:hover{background:rgba(255,255,255,.3)}
.panel-meta{background:#f0f2f5;border-bottom:1px solid #e5e5e5;padding:10px 20px;display:flex;gap:18px;flex-wrap:wrap;flex-shrink:0}
.pmi{display:flex;flex-direction:column;gap:2px}
.pmi-l{font-size:9px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em}
.pmi-v{font-size:12.5px;font-weight:700;color:#1f2937}
.panel-body{flex:1;overflow-y:auto;padding:14px 18px}
.panel-footer{border-top:1px solid #e5e5e5;padding:12px 20px;background:#fafafa;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;flex-wrap:wrap;gap:8px}
.panel-tot{font-size:15px;font-weight:800;color:#0d9488}
.panel-cnt{font-size:11px;color:#6b7280;font-weight:600}
.pchq-card{background:#fff;border:1.5px solid #e0e7ff;border-radius:10px;padding:11px 13px;margin-bottom:9px;display:flex;align-items:center;gap:11px;transition:border-color .15s,box-shadow .15s}
.pchq-card:hover{border-color:#a5b4fc;box-shadow:0 2px 10px rgba(99,102,241,.1)}
.pchq-card.cleared{border-left:4px solid #16a34a;background:#f0fdf4}
.pchq-card.returned{border-left:4px solid #dc2626;background:#fff5f5}
.pchq-card.deposited{border-left:4px solid #2563eb;background:#f0f9ff}
.pchq-card.sent_back{border-left:4px solid #7c3aed;background:#fdf4ff}
.chqno-b{background:#ede9fe;color:#3730a3;border-radius:6px;padding:5px 10px;font-family:'Courier New',monospace;font-size:12px;font-weight:800;white-space:nowrap;flex-shrink:0;letter-spacing:.03em}
.chqi{flex:1;min-width:0}
.chqi-cust{font-size:12.5px;font-weight:700;color:#1f2937;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.chqi-meta{display:flex;gap:8px;flex-wrap:wrap;margin-top:3px}
.chqi-m{font-size:10.5px;color:#6b7280;display:flex;align-items:center;gap:3px}
.bank-ref-tag{font-size:10px;font-family:'Courier New',monospace;color:#0369a1;background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;padding:1px 6px;white-space:nowrap}
.pstat-sel{border:1.5px solid #e5e5e5;border-radius:6px;padding:4px 8px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;outline:none;transition:all .2s;min-width:108px}
.pstat-sel.deposited{background:#dbeafe;color:#1e40af;border-color:#bfdbfe}
.pstat-sel.cleared{background:#dcfce7;color:#166534;border-color:#86efac}
.pstat-sel.returned{background:#fee2e2;color:#991b1b;border-color:#fecaca}
.pstat-sel.sent_back{background:#fdf4ff;color:#7e22ce;border-color:#d8b4fe}
.pstat-sel.pending{background:#fef3c7;color:#92400e;border-color:#fde68a}
.psave-btn{display:none;align-items:center;gap:3px;background:#6366f1;color:#fff;border:none;border-radius:5px;padding:4px 10px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .2s;white-space:nowrap}
.psave-btn:hover{background:#4f46e5}.psave-btn.vis{display:inline-flex}.psave-btn:disabled{opacity:.5;cursor:not-allowed}
.pref-inp{border:1px solid #e5e5e5;border-radius:5px;padding:3px 7px;font-size:10px;font-family:'Courier New',monospace;width:115px;color:#374151;outline:none;transition:border .2s}
.pref-inp:focus{border-color:#6366f1}
.p-body-loading{text-align:center;padding:60px 20px;color:#6b7280;font-size:13px}
.p-body-loading i{font-size:28px;display:block;margin-bottom:10px;color:#6366f1}

/* NDB MODAL */
#ndbModal{display:none;position:fixed;inset:0;z-index:999990;background:rgba(0,0,0,.62);overflow-y:auto;padding:28px 14px 40px}
#ndbModal.open{display:block}
.ndb-box{background:#fff;border-radius:16px;width:100%;max-width:1140px;margin:0 auto;box-shadow:0 32px 100px rgba(0,0,0,.45);overflow:hidden;animation:ndbIn .22s cubic-bezier(.16,1,.3,1)}
@keyframes ndbIn{from{transform:translateY(28px) scale(.98);opacity:0}to{transform:translateY(0) scale(1);opacity:1}}
.ndb-hdr{background:linear-gradient(135deg,#0f766e,#0d9488);padding:16px 22px;display:flex;align-items:center;justify-content:space-between}
.ndb-htitle{color:#fff;font-size:15px;font-weight:800;display:flex;align-items:center;gap:9px}
.ndb-x{background:rgba(255,255,255,.15);border:none;border-radius:8px;color:#fff;width:32px;height:32px;cursor:pointer;font-size:15px;display:flex;align-items:center;justify-content:center;transition:background .2s}
.ndb-x:hover{background:rgba(255,255,255,.3)}
.ndb-body{padding:20px 22px}
.ndb-tabs{display:flex;gap:0;border-bottom:2px solid #e5e5e5;margin-bottom:18px}
.ndb-tab{padding:8px 20px;font-size:12px;font-weight:700;color:#9ca3af;border-bottom:2px solid transparent;margin-bottom:-2px;display:flex;align-items:center;gap:6px}
.ndb-tab.active{color:#0d9488;border-bottom-color:#0d9488}.ndb-tab.done{color:#16a34a}
.step-n{width:20px;height:20px;border-radius:50%;font-size:10px;font-weight:800;display:inline-flex;align-items:center;justify-content:center;background:#e5e5e5;color:#6b7280}
.ndb-tab.active .step-n{background:#0d9488;color:#fff}.ndb-tab.done .step-n{background:#16a34a;color:#fff}
.upload-area{border:2.5px dashed #99f6e4;border-radius:12px;padding:36px 24px;text-align:center;background:#f0fdfa;cursor:pointer;transition:all .2s}
.upload-area:hover,.upload-area.drag-over{border-color:#0d9488;background:#ccfbf1}
.ua-icon{font-size:36px;color:#0d9488;margin-bottom:10px}.ua-title{font-size:15px;font-weight:700;color:#0f766e;margin-bottom:5px}.ua-sub{font-size:12px;color:#6b7280}
.stmt-bar{background:linear-gradient(135deg,#f0fdfa,#ccfbf1);border:1px solid #5eead4;border-radius:10px;padding:11px 14px;margin-bottom:14px}
.stmt-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:7px}
.stmt-item{display:flex;flex-direction:column;gap:2px}
.stmt-lbl{font-size:9px;font-weight:700;color:#0d9488;text-transform:uppercase;letter-spacing:.05em}
.stmt-val{font-size:12px;font-weight:700;color:#0f766e}
.prev-stats{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
.prev-stat{background:#f0fdfa;border:1px solid #99f6e4;border-radius:7px;padding:5px 11px;font-size:11px;font-weight:700;color:#0f766e;display:flex;align-items:center;gap:4px}
.prev-stat.warn{background:#fef3c7;border-color:#fde68a;color:#92400e}.prev-stat.err{background:#fee2e2;border-color:#fecaca;color:#991b1b}
.prev-table{width:100%;border-collapse:collapse;font-size:11.5px;border:1px solid #e5e5e5;border-radius:8px;overflow:hidden}
.prev-table thead th{background:#0f766e;color:#fff;padding:8px 9px;text-align:left;font-size:10.5px;font-weight:700;white-space:nowrap}
.prev-table thead th.tr{text-align:right}
.prev-table tbody tr{border-bottom:1px solid #f0f0f0}
.prev-table tbody tr:hover td{background:#f0fdfa!important}
.prev-table td{padding:7px 9px;vertical-align:middle;background:#fff}
.prev-table td.tr{text-align:right}
.prev-table tr.row-cleared td{background:#f0fdf4}.prev-table tr.row-returned td{background:#fff5f5}
.prev-table tr.row-notfound{opacity:.6}
.mb{display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700;white-space:nowrap}
.mb-ok{background:#dcfce7;color:#166534;border:1px solid #86efac}.mb-no{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
.mb-clr{background:#dcfce7;color:#166534;border:1px solid #86efac}.mb-ret{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
.mb-amt{background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe}.mb-amtd{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.ndb-chk{width:15px;height:15px;accent-color:#0d9488;cursor:pointer}
.ndb-foot{display:flex;gap:10px;padding:12px 22px;border-top:1px solid #ccfbf1;background:#f0fdfa;flex-wrap:wrap;align-items:center}
.ndb-foot-info{flex:1;font-size:12px;color:#0f766e;font-weight:600}
.ndb-apply-btn{background:linear-gradient(135deg,#0d9488,#0f766e);color:#fff;border:none;border-radius:8px;padding:10px 20px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:6px;transition:filter .2s}
.ndb-apply-btn:hover{filter:brightness(1.08)}.ndb-apply-btn:disabled{opacity:.5;cursor:not-allowed}
.ndb-cancel-btn{background:#f3f4f6;color:#374151;border:1px solid #e5e5e5;border-radius:8px;padding:10px 16px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit}
.prev-ctrl{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:10px}

#toast{position:fixed;bottom:28px;right:28px;z-index:9999999;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 6px 24px rgba(0,0,0,.2);color:#fff;transform:translateY(80px);opacity:0;transition:transform .3s,opacity .3s;pointer-events:none;max-width:380px}
#toast.show{transform:translateY(0);opacity:1}
.select2-container--default .select2-selection--single{height:36px!important;border:1px solid #e5e5e5!important;border-radius:7px!important}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:34px!important;padding-left:10px!important;font-size:13px!important;font-family:inherit!important;color:#1f2937!important}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:34px!important}
.select2-dropdown{border:1px solid #e5e5e5!important;border-radius:8px!important;box-shadow:0 8px 30px rgba(0,0,0,.12)!important;font-size:13px!important}
.select2-results__option--highlighted{background:#0d9488!important}

@media(max-width:900px){.kpi-status-grid{grid-template-columns:1fr};.kpi-summary-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:600px){.kpi-summary-grid{grid-template-columns:1fr};.filter-row{flex-direction:column}}
@media print{
    .no-print{display:none!important}
    .data-table thead th{background:#1e1b4b!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .data-table tfoot td{background:#0f172a!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
}
</style>

<!-- HEADER -->
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:18px;" class="no-print">
  <div>
    <h2 class="page-title"><i class="fa-solid fa-building-columns" style="color:#0d9488;"></i> Cheque Deposit List</h2>
    <p class="page-subtitle">All deposit batches auto-loaded · click <strong>View</strong> to see cheques &amp; update status · Import NDB statement to auto-clear / return</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;" class="no-print">
   <a href="cheque_reconciliation.php" class="btn btn-teal">
    <i class="fa-solid fa-file-import"></i> Import NDB Statement
   </a>
    <button onclick="window.print()" class="btn btn-secondary btn-sm"><i class="fa-solid fa-print"></i> Print</button>
    <button onclick="exportCSV()" class="btn btn-success btn-sm"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
    <a href="cheques.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-arrow-left"></i> Cheque Register</a>
  </div>
</div>

<?php if($tbl_err): ?>
<div style="background:#fee2e2;border:1px solid #fecaca;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#991b1b;">
  <strong><i class="fa-solid fa-triangle-exclamation"></i> Database Error:</strong> <?=htmlspecialchars($tbl_err)?>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════
     KPI SECTION — 3 Status Cards + 3 Summary Cards
══════════════════════════════════════════════════════════ -->
<div class="kpi-section">

  <!-- Row 1: Cleared | Returned | Not Cleared -->
  <div class="kpi-status-grid">

    <!-- CLEARED -->
    <div class="kpi-status-card ksc-green">
      <div class="ksc-header">
        <div class="ksc-label"><i class="fa-solid fa-circle-check"></i> Cleared</div>
        <div class="ksc-icon"><i class="fa-solid fa-circle-check"></i></div>
      </div>
      <div class="ksc-stats-row">
        <div class="ksc-stat">
          <div class="ksc-stat-lbl">Cheques</div>
          <div class="ksc-stat-val big"><?=number_format($kpi_clr_cnt)?></div>
        </div>
        <div class="ksc-divider"></div>
        <div class="ksc-stat">
          <div class="ksc-stat-lbl">Amount</div>
          <div class="ksc-stat-val mid">Rs.&nbsp;<?=number_format($kpi_clr_amt, 2)?></div>
        </div>
        <div class="ksc-divider"></div>
        <div class="ksc-stat">
          <div class="ksc-stat-lbl">Rate</div>
          <div class="ksc-stat-val pct"><?=$rate_clr?>%</div>
        </div>
      </div>
      <div class="ksc-rate-wrap">
        <div class="ksc-rate-bar-track"><div class="ksc-rate-bar" style="width:<?=$rate_clr?>%"></div></div>
        <div class="ksc-rate-pct-sm"><?=$rate_clr?>% of total</div>
      </div>
    </div>

    <!-- RETURNED -->
    <div class="kpi-status-card ksc-red">
      <div class="ksc-header">
        <div class="ksc-label"><i class="fa-solid fa-circle-xmark"></i> Returned</div>
        <div class="ksc-icon"><i class="fa-solid fa-circle-xmark"></i></div>
      </div>
      <div class="ksc-stats-row">
        <div class="ksc-stat">
          <div class="ksc-stat-lbl">Cheques</div>
          <div class="ksc-stat-val big"><?=number_format($kpi_ret_cnt)?></div>
        </div>
        <div class="ksc-divider"></div>
        <div class="ksc-stat">
          <div class="ksc-stat-lbl">Amount</div>
          <div class="ksc-stat-val mid">Rs.&nbsp;<?=number_format($kpi_ret_amt, 2)?></div>
        </div>
        <div class="ksc-divider"></div>
        <div class="ksc-stat">
          <div class="ksc-stat-lbl">Rate</div>
          <div class="ksc-stat-val pct"><?=$rate_ret?>%</div>
        </div>
      </div>
      <div class="ksc-rate-wrap">
        <div class="ksc-rate-bar-track"><div class="ksc-rate-bar" style="width:<?=$rate_ret?>%"></div></div>
        <div class="ksc-rate-pct-sm"><?=$rate_ret?>% of total</div>
      </div>
    </div>

    <!-- NOT CLEARED (deposited / pending) -->
    <div class="kpi-status-card ksc-blue">
      <div class="ksc-header">
        <div class="ksc-label"><i class="fa-solid fa-hourglass-half"></i> Not Cleared</div>
        <div class="ksc-icon"><i class="fa-solid fa-hourglass-half"></i></div>
      </div>
      <div class="ksc-stats-row">
        <div class="ksc-stat">
          <div class="ksc-stat-lbl">Cheques</div>
          <div class="ksc-stat-val big"><?=number_format($kpi_nc_cnt)?></div>
        </div>
        <div class="ksc-divider"></div>
        <div class="ksc-stat">
          <div class="ksc-stat-lbl">Amount</div>
          <div class="ksc-stat-val mid">Rs.&nbsp;<?=number_format($kpi_nc_amt, 2)?></div>
        </div>
        <div class="ksc-divider"></div>
        <div class="ksc-stat">
          <div class="ksc-stat-lbl">Rate</div>
          <div class="ksc-stat-val pct"><?=$rate_nc?>%</div>
        </div>
      </div>
      <div class="ksc-rate-wrap">
        <div class="ksc-rate-bar-track"><div class="ksc-rate-bar" style="width:<?=$rate_nc?>%"></div></div>
        <div class="ksc-rate-pct-sm"><?=$rate_nc?>% of total</div>
      </div>
    </div>

  </div><!-- /kpi-status-grid -->

  <!-- Row 2: Total Amount | Deposit Days | Deposit Types -->
  <div class="kpi-summary-grid">

    <div class="kpi-card kc-teal">
      <div class="kpi-lbl"><i class="fa-solid fa-sack-dollar"></i> Total Deposited</div>
      <div class="kpi-val">Rs.&nbsp;<?=number_format($kpi_tot_amt, 0)?></div>
      <div class="kpi-sub"><?=number_format($kpi_total)?> cheques in total</div>
    </div>

    <div class="kpi-card kc-sky">
      <div class="kpi-lbl"><i class="fa-solid fa-calendar-check"></i> Deposit Days</div>
      <div class="kpi-val"><?=intval($kpi['deposit_days'] ?? 0)?></div>
      <div class="kpi-sub">Unique deposit dates</div>
    </div>

    <div class="kpi-card kc-amber">
      <div class="kpi-lbl"><i class="fa-solid fa-layer-group"></i> Deposit Types</div>
      <div class="dep-type-breakdown">
        <div class="dtb-row">
          <span class="dtb-pill dtb-normal"><i class="fa-solid fa-file-lines" style="font-size:8px;"></i> Normal</span>
          <span class="dtb-cnt"><?=number_format(intval($kpi['normal_count'] ?? 0))?></span>
        </div>
        <div class="dtb-row">
          <span class="dtb-pill dtb-bulk"><i class="fa-solid fa-layer-group" style="font-size:8px;"></i> Bulk</span>
          <span class="dtb-cnt"><?=number_format(intval($kpi['bulk_count'] ?? 0))?></span>
        </div>
        <div class="dtb-row">
          <span class="dtb-pill dtb-normal-bulk"><i class="fa-solid fa-layer-group" style="font-size:8px;"></i> Normal Bulk</span>
          <span class="dtb-cnt"><?=number_format(intval($kpi['normal_bulk_count'] ?? 0))?></span>
        </div>
      </div>
    </div>

  </div><!-- /kpi-summary-grid -->

</div><!-- /kpi-section -->

<!-- FILTER BAR -->
<div class="filter-bar no-print">
  <div class="filter-row">
    <div class="ffg"><label><i class="fa-solid fa-calendar-day"></i> From</label><input type="date" id="fFrom" oninput="applyFilters()"></div>
    <div class="ffg"><label><i class="fa-solid fa-calendar-day"></i> To</label><input type="date" id="fTo" oninput="applyFilters()"></div>
    <div class="ffg"><label><i class="fa-solid fa-landmark"></i> Account</label>
      <select id="fAcc" style="min-width:190px;">
        <option value="">— All Accounts —</option>
        <?php foreach($acc_rows as $ac): ?>
        <option value="<?=intval($ac['id'])?>"><?=htmlspecialchars($ac['account_name'].' ('.$ac['account_no'].')')?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="ffg"><label><i class="fa-solid fa-layer-group"></i> Type</label>
      <select id="fType" style="min-width:160px;">
        <option value="">— All Types —</option>
        <option value="normal">Normal Deposit</option>
        <option value="bulk">Bulk Deposit</option>
        <option value="normal_bulk">Normal Bulk Deposit</option>
      </select>
    </div>
    <div class="ffg">
      <label><i class="fa-solid fa-magnifying-glass"></i> Live Search</label>
      <div class="chq-search-box"><i class="fa-solid fa-magnifying-glass si"></i><input type="text" id="srchBox" placeholder="Date / account / cheque…" oninput="applyFilters()"></div>
    </div>
    <div style="display:flex;align-items:flex-end;">
      <button class="btn btn-secondary btn-sm" onclick="clearFilters()"><i class="fa-solid fa-rotate-left"></i> Clear</button>
    </div>
  </div>
  <div class="sc-wrap">
    <span class="sc-lbl">Quick Date:</span>
    <?php
    $sc=[
      'Today'=>[date('Y-m-d'),date('Y-m-d')],
      'Yesterday'=>[date('Y-m-d',strtotime('-1 day')),date('Y-m-d',strtotime('-1 day'))],
      'This Week'=>[date('Y-m-d',strtotime('monday this week')),date('Y-m-d')],
      'This Month'=>[date('Y-m-01'),date('Y-m-d')],
      'Last Month'=>[date('Y-m-01',strtotime('first day of last month')),date('Y-t',strtotime('last month'))],
      'Last 30 Days'=>[date('Y-m-d',strtotime('-30 days')),date('Y-m-d')],
      'Last 90 Days'=>[date('Y-m-d',strtotime('-90 days')),date('Y-m-d')],
    ];
    foreach($sc as $lbl=>$range):
    ?><button class="sc-btn" onclick="setDates('<?=$range[0]?>','<?=$range[1]?>')"><?=htmlspecialchars($lbl)?></button><?php endforeach; ?>
    <span class="sc-lbl" style="margin-left:8px;">Quick Type:</span>
    <button class="sc-btn" onclick="filterByType('normal')"><i class="fa-solid fa-file-lines" style="color:#1e40af;"></i> Normal</button>
    <button class="sc-btn" onclick="filterByType('bulk')"><i class="fa-solid fa-layer-group" style="color:#5b21b6;"></i> Bulk</button>
    <button class="sc-btn" onclick="filterByType('normal_bulk')"><i class="fa-solid fa-layer-group" style="color:#92400e;"></i> Normal Bulk</button>
  </div>
</div>

<!-- TABLE -->
<div class="table-card">
  <div class="table-toolbar no-print">
    <div class="tbl-title">
      <i class="fa-solid fa-calendar-days" style="color:#0d9488;"></i> Deposit Batches
      <span class="pill p-teal" id="visCount"><?=count($batches)?> batches</span>
      <span class="pill p-gray" id="visCheques"><?=$grand_count?> cheques</span>
    </div>
    <div style="display:flex;align-items:center;gap:8px;">
      <span class="pager-info" id="pagerInfo"></span>
      <div class="pager" id="pager"></div>
    </div>
  </div>

  <div class="dt-outer">
    <table class="data-table" id="mainTable">
      <thead>
        <tr>
          <th style="width:30px;" class="tc no-print">#</th>
          <th style="min-width:110px;">Deposit Date</th>
          <th>Company Bank Account</th>
          <th class="tc">Type</th>
          <th class="tc">Total</th>
          <th class="tc" style="background:#14532d;min-width:110px;"><i class="fa-solid fa-circle-check" style="font-size:9px;"></i> Cleared</th>
          <th class="tc" style="background:#7f1d1d;min-width:110px;"><i class="fa-solid fa-circle-xmark" style="font-size:9px;"></i> Returned</th>
          <th class="tc" style="background:#1e3a5f;min-width:110px;"><i class="fa-solid fa-hourglass-half" style="font-size:9px;"></i> Not Cleared</th>
          <th class="tr">Total Amount</th>
          <th class="tc no-print" style="width:80px;">View</th>
        </tr>
      </thead>
      <tbody id="tableBody">
      <?php $rn=1; foreach($batches as $row):
        $dd  = $row['deposit_date']??'';
        $dt  = strtolower(trim($row['deposit_type']??'normal'));
        $cn  = intval($row['cheque_count']);
        $ta  = floatval($row['total_amount']);
        $ai  = intval($row['deposited_account_id']??0);
        $an  = $row['account_name']??'—';
        $ano = $row['account_no']??'';
        $bn  = $row['bank_name']??'';
        $bra = $row['branch_name']??'';
        $co  = $row['company_name']??'';
        $clr = intval($row['cleared_count']);
        $ret = intval($row['returned_count']);
        $dep = intval($row['deposited_count']);
        $day = $dd?date('d',strtotime($dd)):'—';
        $mo  = $dd?date('M Y',strtotime($dd)):'';
        $ini = strtoupper(substr($an,0,2));
        $bar = $max_amt>0?min(100,round(($ta/$max_amt)*100)):0;
        $cpct= $cn>0?round(($clr/$cn)*100):0;
        $uid = 'dr'.md5($dd.'_'.$ai.'_'.$dt);
        if ($dt === 'bulk') {
            $tp = '<span class="dep-type-pill dtp-bulk"><i class="fa-solid fa-layer-group"></i> Bulk</span>';
        } elseif ($dt === 'normal_bulk') {
            $tp = '<span class="dep-type-pill dtp-normal-bulk"><i class="fa-solid fa-layer-group"></i> Normal Bulk</span>';
        } else {
            $tp = '<span class="dep-type-pill dtp-normal"><i class="fa-solid fa-file-lines"></i> Normal</span>';
        }
      ?>
      <tr class="dep-row" id="row-<?=$uid?>"
          data-uid="<?=$uid?>"
          data-dep-date="<?=htmlspecialchars($dd)?>"
          data-acc-id="<?=$ai?>"
          data-dep-type="<?=htmlspecialchars($dt)?>"
          data-search="<?=htmlspecialchars(strtolower($dd.' '.$an.' '.$ano.' '.$dt))?>">
        <td class="tc no-print row-num" style="color:#9ca3af;font-size:11px;font-weight:600;"><?=$rn++?></td>
        <td>
          <div class="dep-date-badge"><span class="ddb-day"><?=$day?></span><span class="ddb-mo"><?=$mo?></span></div>
        </td>
        <td>
          <div style="display:flex;align-items:center;gap:10px;">
            <div class="bank-av"><?=htmlspecialchars($ini)?></div>
            <div class="bank-info">
              <div class="bn"><?=htmlspecialchars($an)?></div>
              <div class="bno"><?=htmlspecialchars($ano)?></div>
              <div class="bsub">
                <?php if($bn): ?><i class="fa-solid fa-building-columns" style="font-size:9px;"></i> <?=htmlspecialchars($bn)?><?php if($bra): ?> · <?=htmlspecialchars($bra)?><?php endif; ?><br><?php endif; ?>
                <?php if($co): ?><i class="fa-solid fa-building" style="font-size:9px;"></i> <?=htmlspecialchars($co)?><?php endif; ?>
              </div>
            </div>
          </div>
        </td>
        <td class="tc"><?=$tp?></td>
        <?php
          $nc_cnt = $cn - $clr - $ret; // not cleared = total minus cleared minus returned
          $nc_cnt = max(0, $nc_cnt);
          $clr_amt = 0; $ret_amt = 0; $nc_amt = 0; // amounts not in batch query, show counts only
        ?>
        <!-- Total -->
        <td class="tc">
          <span class="cnt-badge <?=$dt?>"><?=$cn?></span>
          <div style="font-size:10px;color:#6b7280;margin-top:3px;"><?=$cpct?>% cleared</div>
        </td>
        <!-- Cleared -->
        <td class="tc" style="background:#f0fdf4;">
          <?php if($clr > 0): ?>
          <div style="display:flex;flex-direction:column;align-items:center;gap:3px;">
            <span style="font-size:18px;font-weight:900;color:#15803d;line-height:1;"><?=$clr?></span>
            <div style="background:#bbf7d0;border-radius:99px;height:4px;width:52px;overflow:hidden;">
              <div style="height:100%;border-radius:99px;background:linear-gradient(90deg,#16a34a,#4ade80);width:<?=$cn>0?round(($clr/$cn)*100):0?>%;"></div>
            </div>
            <span style="font-size:9.5px;font-weight:700;color:#16a34a;"><?=$cn>0?round(($clr/$cn)*100):0?>%</span>
          </div>
          <?php else: ?><span style="color:#d1fae5;font-size:11px;">—</span><?php endif; ?>
        </td>
        <!-- Returned -->
        <td class="tc" style="background:#fff5f5;">
          <?php if($ret > 0): ?>
          <div style="display:flex;flex-direction:column;align-items:center;gap:3px;">
            <span style="font-size:18px;font-weight:900;color:#b91c1c;line-height:1;"><?=$ret?></span>
            <div style="background:#fecaca;border-radius:99px;height:4px;width:52px;overflow:hidden;">
              <div style="height:100%;border-radius:99px;background:linear-gradient(90deg,#dc2626,#f87171);width:<?=$cn>0?round(($ret/$cn)*100):0?>%;"></div>
            </div>
            <span style="font-size:9.5px;font-weight:700;color:#dc2626;"><?=$cn>0?round(($ret/$cn)*100):0?>%</span>
          </div>
          <?php else: ?><span style="color:#fecaca;font-size:11px;">—</span><?php endif; ?>
        </td>
        <!-- Not Cleared -->
        <td class="tc" style="background:#eff6ff;">
          <?php if($nc_cnt > 0): ?>
          <div style="display:flex;flex-direction:column;align-items:center;gap:3px;">
            <span style="font-size:18px;font-weight:900;color:#1d4ed8;line-height:1;"><?=$nc_cnt?></span>
            <div style="background:#bfdbfe;border-radius:99px;height:4px;width:52px;overflow:hidden;">
              <div style="height:100%;border-radius:99px;background:linear-gradient(90deg,#2563eb,#60a5fa);width:<?=$cn>0?round(($nc_cnt/$cn)*100):0?>%;"></div>
            </div>
            <span style="font-size:9.5px;font-weight:700;color:#2563eb;"><?=$cn>0?round(($nc_cnt/$cn)*100):0?>%</span>
          </div>
          <?php else: ?><span style="color:#bfdbfe;font-size:11px;">—</span><?php endif; ?>
        </td>
        <td class="tr">
          <div class="amt-large">Rs.&nbsp;<?=number_format($ta,2)?></div>
          <div class="prog-wrap" style="margin-left:auto;"><div class="prog-bar-g" style="width:<?=$bar?>%"></div></div>
        </td>
        <td class="tc no-print">
          <a class="view-btn"
             href="deposit_view.php?dep_date=<?=urlencode($dd)?>&acc_id=<?=$ai?>&dep_type=<?=urlencode($dt)?>"
             target="_blank">
            <i class="fa-solid fa-eye"></i> View
          </a>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="4" class="no-print"></td>
          <td class="tc"><span id="footerCnt"><?=$grand_count?></span> cheques</td>
          <td class="tc" style="color:#4ade80;" id="footerClr">—</td>
          <td class="tc" style="color:#f87171;" id="footerRet">—</td>
          <td class="tc" style="color:#60a5fa;" id="footerNc">—</td>
          <td class="tr">Rs.&nbsp;<span id="footerAmt"><?=number_format($grand_amount,2)?></span></td>
          <td style="font-size:11px;opacity:.6;" class="no-print">TOTAL VISIBLE</td>
        </tr>
      </tfoot>
    </table>
  </div>

  <?php if(empty($batches)): ?>
  <div style="text-align:center;padding:60px 20px;color:#9ca3af;">
    <i class="fa-solid fa-building-columns" style="font-size:44px;display:block;margin-bottom:14px;opacity:.3;"></i>
    <p style="font-size:14px;font-weight:500;">No deposited cheques found in the database.</p>
    <?php if($tbl_err): ?>
    <p style="font-size:12px;color:#dc2626;margin-top:8px;">SQL Error: <?=htmlspecialchars($tbl_err)?></p>
    <?php else: ?>
    <p style="font-size:12px;color:#9ca3af;margin-top:4px;">
      Cheques must have <code>status</code> = deposited / cleared / returned AND a non-null <code>deposit_date</code>.
    </p>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ══ SLIDE-IN PANEL ══ -->
<div id="panelOverlay" onclick="closePanel()"></div>
<div id="detailPanel">
  <div class="panel-hdr">
    <div class="panel-title"><i class="fa-solid fa-money-check-dollar"></i><span id="panelTitleTxt">Deposit Batch</span></div>
    <button class="panel-x" onclick="closePanel()"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="panel-meta" id="panelMeta"></div>
  <div class="panel-body" id="panelBody"><div class="p-body-loading"><i class="fa-solid fa-spinner fa-spin"></i>Loading…</div></div>
  <div class="panel-footer">
    <div><div class="panel-cnt" id="panelCntTag"></div><div class="panel-tot" id="panelTotAmt"></div></div>
    <button class="btn btn-secondary btn-sm" onclick="closePanel()"><i class="fa-solid fa-xmark"></i> Close</button>
  </div>
</div>

<!-- ══ NDB IMPORT MODAL ══ -->
<div id="ndbModal">
  <div class="ndb-box">
    <div class="ndb-hdr">
      <div class="ndb-htitle"><i class="fa-solid fa-file-import"></i> NDB Bank Statement Import
        <span style="background:rgba(255,255,255,.15);border-radius:6px;padding:2px 10px;font-size:11px;">Auto-clear &amp; Return Cheques</span>
      </div>
      <button class="ndb-x" onclick="closeNdbModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="ndb-body">
      <div class="ndb-tabs">
        <div class="ndb-tab active" id="ndbTab1"><span class="step-n">1</span> Upload File</div>
        <div class="ndb-tab" id="ndbTab2"><span class="step-n">2</span> Review &amp; Match</div>
        <div class="ndb-tab" id="ndbTab3"><span class="step-n">3</span> Apply Updates</div>
      </div>

      <!-- Step 1 -->
      <div id="ndbStep1">
        <div class="upload-area" id="uploadArea"
             onclick="document.getElementById('ndbFileInput').click()"
             ondragover="event.preventDefault();this.classList.add('drag-over')"
             ondragleave="this.classList.remove('drag-over')"
             ondrop="handleFileDrop(event)">
          <div class="ua-icon"><i class="fa-solid fa-file-excel"></i></div>
          <div class="ua-title">Upload NDB Bank Statement</div>
          <div class="ua-sub">CSV file · Drag &amp; drop or click to browse<br><small style="opacity:.7;">(For Excel files: save as CSV first from Excel/Google Sheets)</small></div>
          <div style="margin-top:14px;"><span style="background:#0d9488;color:#fff;padding:7px 18px;border-radius:7px;font-size:12px;font-weight:700;"><i class="fa-solid fa-folder-open"></i> Browse CSV File</span></div>
        </div>
        <input type="file" id="ndbFileInput" accept=".csv" onchange="handleFileSelect(this)">
        <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;margin-top:14px;font-size:12px;color:#92400e;line-height:1.7;">
          <strong><i class="fa-solid fa-triangle-exclamation"></i> What gets imported:</strong><br>
          &nbsp;• <code style="background:#fff3c4;padding:1px 5px;border-radius:3px;font-size:11px;">Outward Cheque Deposit/CHQ NO - XXXX</code> → cheque marked <strong style="color:#16a34a;">Cleared</strong>, bank reference saved<br>
          &nbsp;• <code style="background:#fff3c4;padding:1px 5px;border-radius:3px;font-size:11px;">Outward Clg Chq Return/CHQ NO - XXXX</code> → cheque marked <strong style="color:#dc2626;">Returned</strong>
        </div>
      </div>

      <!-- Step 2 -->
      <div id="ndbStep2" style="display:none;">
        <div class="stmt-bar" id="stmtBar"></div>
        <div class="prev-stats" id="prevStats"></div>
        <div class="prev-ctrl">
          <button class="sc-btn" onclick="selAll(true)">Select All</button>
          <button class="sc-btn" onclick="selAll(false)">Deselect All</button>
          <button class="sc-btn" onclick="selByType('cleared')"><i class="fa-solid fa-circle-check" style="color:#16a34a;"></i> Cleared Only</button>
          <button class="sc-btn" onclick="selByType('returned')"><i class="fa-solid fa-circle-xmark" style="color:#dc2626;"></i> Returned Only</button>
          <button class="sc-btn" onclick="selMatched(true)"><i class="fa-solid fa-check"></i> Matched Only</button>
        </div>
        <div style="max-height:400px;overflow-y:auto;border:1px solid #e5e5e5;border-radius:8px;">
          <table class="prev-table">
            <thead>
              <tr>
                <th style="width:28px;"><input type="checkbox" class="ndb-chk" id="prevSelAll" onchange="selAll(this.checked)"></th>
                <th>Cheque No.</th><th>Action</th><th>Customer / T-Code</th>
                <th class="tr">Bank Amount</th><th class="tr">DB Amount</th>
                <th>Amt Match</th><th>DB Status</th><th>Bank Ref</th><th>Tx Date</th>
              </tr>
            </thead>
            <tbody id="prevBody"></tbody>
          </table>
        </div>
      </div>

      <!-- Step 3 -->
      <div id="ndbStep3" style="display:none;"><div id="applyResult"></div></div>
    </div>
    <div class="ndb-foot">
      <div class="ndb-foot-info" id="ndbInfo">Upload a NDB bank statement CSV to begin.</div>
      <button class="ndb-cancel-btn" onclick="closeNdbModal()">Cancel</button>
      <button class="ndb-apply-btn" id="ndbApplyBtn" onclick="applyNdb()" style="display:none;"><i class="fa-solid fa-circle-check"></i> Apply Selected Updates</button>
      <button class="ndb-apply-btn" id="ndbDoneBtn" onclick="closeNdbModal();location.reload()" style="display:none;background:linear-gradient(135deg,#16a34a,#22c55e);"><i class="fa-solid fa-rotate-right"></i> Done — Reload</button>
    </div>
  </div>
</div>

<div id="toast"></div>

<script>
const ALL_BATCHES=<?=json_encode(array_values($batches))?>;
const PAGE_SIZE=50;let curPage=1;
let filteredRows=Array.from(document.querySelectorAll('#tableBody tr.dep-row'));

/* ════ FILTERS & PAGINATION ════ */
function applyFilters(){
    const from=document.getElementById('fFrom').value;
    const to=document.getElementById('fTo').value;
    const acc=document.getElementById('fAcc').value;
    const type=document.getElementById('fType').value;
    const srch=document.getElementById('srchBox').value.trim().toLowerCase();
    filteredRows=[];
    document.querySelectorAll('#tableBody tr.dep-row').forEach(tr=>{
        const dd=tr.dataset.depDate||'';
        let ok=true;
        if(from&&dd<from) ok=false;
        if(to&&dd>to) ok=false;
        if(acc&&tr.dataset.accId!==acc) ok=false;
        if(type&&tr.dataset.depType!==type) ok=false;
        if(srch&&!tr.dataset.search.includes(srch)) ok=false;
        if(ok)filteredRows.push(tr);
    });
    curPage=1;applyPage();
}
function applyPage(){
    const total=filteredRows.length;
    const pages=Math.max(1,Math.ceil(total/PAGE_SIZE));
    if(curPage>pages)curPage=pages;
    const start=(curPage-1)*PAGE_SIZE,end=start+PAGE_SIZE;
    document.querySelectorAll('#tableBody tr.dep-row').forEach(tr=>tr.style.display='none');
    let num=start+1;
    filteredRows.slice(start,end).forEach(tr=>{tr.style.display='';const nc=tr.querySelector('.row-num');if(nc)nc.textContent=num++;});
    let tc=0,ta=0,tclr=0,tret=0;
    filteredRows.forEach(tr=>{
        const b=ALL_BATCHES.find(x=>bUid(x)===tr.dataset.uid);
        if(b){
            const cnt=parseInt(b.cheque_count||0);
            const clr=parseInt(b.cleared_count||0);
            const ret=parseInt(b.returned_count||0);
            tc+=cnt; ta+=parseFloat(b.total_amount||0);
            tclr+=clr; tret+=ret;
        }
    });
    const tnc=Math.max(0,tc-tclr-tret);
    const fc=document.getElementById('footerCnt'),fa=document.getElementById('footerAmt'),vc=document.getElementById('visCount'),vcc=document.getElementById('visCheques');
    const fclr=document.getElementById('footerClr'),fret=document.getElementById('footerRet'),fnc=document.getElementById('footerNc');
    if(fc)fc.textContent=tc;if(fa)fa.textContent=ta.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    if(vc)vc.textContent=total+' batch'+(total!==1?'es':'');if(vcc)vcc.textContent=tc+' cheques';
    if(fclr)fclr.textContent=tclr>0?tclr:'—';
    if(fret)fret.textContent=tret>0?tret:'—';
    if(fnc)fnc.textContent=tnc>0?tnc:'—';
    buildPager(pages,total,start,Math.min(end,total));
}
function buildPager(pages,total,start,end){
    const pager=document.getElementById('pager'),info=document.getElementById('pagerInfo');
    if(!pager)return;if(pages<=1){pager.innerHTML='';if(info)info.textContent='';return;}
    if(info)info.textContent=`${start+1}–${end} of ${total}`;
    let h=`<button class="pager-btn" onclick="goPage(${curPage-1})" ${curPage===1?'disabled':''}>‹</button>`;
    const lo=Math.max(1,curPage-2),hi=Math.min(pages,curPage+2);
    if(lo>1)h+=`<button class="pager-btn" onclick="goPage(1)">1</button>${lo>2?'<span style="color:#9ca3af;padding:0 2px">…</span>':''}`;
    for(let p=lo;p<=hi;p++)h+=`<button class="pager-btn${p===curPage?' active':''}" onclick="goPage(${p})">${p}</button>`;
    if(hi<pages)h+=`${hi<pages-1?'<span style="color:#9ca3af;padding:0 2px">…</span>':''}<button class="pager-btn" onclick="goPage(${pages})">${pages}</button>`;
    h+=`<button class="pager-btn" onclick="goPage(${curPage+1})" ${curPage===pages?'disabled':''}>›</button>`;
    pager.innerHTML=h;
}
function goPage(p){curPage=p;applyPage();document.querySelector('.dt-outer')?.scrollTo(0,0);}
function setDates(f,t){document.getElementById('fFrom').value=f;document.getElementById('fTo').value=t;applyFilters();}
function filterByType(type){
    const sel=document.getElementById('fType');
    if(sel){sel.value=type;if(window.$)$(sel).val(type).trigger('change');}
    applyFilters();
}
function clearFilters(){
    ['fFrom','fTo','srchBox'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
    $('#fAcc').val('').trigger('change');
    $('#fType').val('').trigger('change');
    applyFilters();
}
function bUid(b){return 'dr'+simpleHash(b.deposit_date+'_'+b.deposited_account_id+'_'+b.deposit_type);}
function simpleHash(s){let h=0;for(let i=0;i<s.length;i++){h=Math.imul(31,h)+s.charCodeAt(i)|0;}return Math.abs(h).toString(16);}
$(function(){
    $('#fAcc').select2({placeholder:'— All Accounts —',allowClear:true,width:'auto',dropdownAutoWidth:true});
    $('#fType').select2({placeholder:'— All Types —',allowClear:true,width:'auto',dropdownAutoWidth:true});
    $('#fAcc,#fType').on('change',function(){applyFilters();});
});
applyPage();

/* ════ PANEL ════ */
let _panelDirty=new Set();
function openPanel(depDate,accId,depType,title,count,total){
    document.getElementById('panelTitleTxt').textContent=title;
    document.getElementById('panelCntTag').textContent=count+' cheque'+(count!==1?'s':'');
    document.getElementById('panelTotAmt').textContent='Rs. '+parseFloat(total).toLocaleString('en-US',{minimumFractionDigits:2});
    const typeLabels={'bulk':'Bulk Deposit','normal_bulk':'Normal Bulk Deposit','normal':'Normal Deposit'};
    const typeLabel=typeLabels[depType]||'Normal Deposit';
    document.getElementById('panelMeta').innerHTML=`
        <div class="pmi"><span class="pmi-l">Deposit Date</span><span class="pmi-v">${fmtDate(depDate)}</span></div>
        <div class="pmi"><span class="pmi-l">Type</span><span class="pmi-v">${typeLabel}</span></div>
        <div class="pmi"><span class="pmi-l">Cheques</span><span class="pmi-v">${count}</span></div>
        <div class="pmi"><span class="pmi-l">Total Amount</span><span class="pmi-v" style="color:#0d9488;">Rs. ${parseFloat(total).toLocaleString('en-US',{minimumFractionDigits:2})}</span></div>`;
    document.getElementById('panelBody').innerHTML='<div class="p-body-loading"><i class="fa-solid fa-spinner fa-spin"></i><br>Loading cheques…</div>';
    _panelDirty.clear();
    document.getElementById('detailPanel').classList.add('open');
    document.getElementById('panelOverlay').classList.add('open');
    document.body.style.overflow='hidden';
    loadPanel(depDate,accId,depType);
}
function closePanel(){
    if(_panelDirty.size>0&&!confirm('Unsaved changes exist. Close anyway?'))return;
    document.getElementById('detailPanel').classList.remove('open');
    document.getElementById('panelOverlay').classList.remove('open');
    document.body.style.overflow='';_panelDirty.clear();
}
async function loadPanel(dd,ai,dt){
    try{
        const res=await fetch(`cheque_deposit_list.php?ajax=batch_cheques&dep_date=${encodeURIComponent(dd)}&acc_id=${ai}&dep_type=${encodeURIComponent(dt)}`);
        const data=await res.json();
        if(!data.success)throw new Error(data.error||'Load failed');
        renderPanel(data.cheques||[]);
    }catch(e){document.getElementById('panelBody').innerHTML=`<div class="p-body-loading"><i class="fa-solid fa-circle-xmark" style="color:#dc2626;font-size:28px;display:block;margin-bottom:10px;"></i>${esc(e.message)}</div>`;}
}
function renderPanel(cheques){
    if(!cheques.length){document.getElementById('panelBody').innerHTML='<div class="p-body-loading"><i class="fa-solid fa-inbox"></i><br>No cheques in this batch.</div>';return;}
    const opts=['deposited','cleared','returned','sent_back','pending'];
    const slbls={deposited:'Deposited',cleared:'Cleared',returned:'Returned',sent_back:'Sent Back',pending:'Pending'};
    let h='';
    cheques.forEach(ch=>{
        const st=ch.status||'deposited';
        const cm=(ch.cheque_mode||'').toLowerCase();
        let mBadge='';
        if(['payee_only','payee only','payee','account payee'].includes(cm))mBadge='<span style="background:#ede9fe;color:#5b21b6;border-radius:4px;padding:1px 5px;font-size:9.5px;font-weight:700;margin-left:4px;">Payee</span>';
        else if(['cash','bearer','open'].includes(cm))mBadge='<span style="background:#dcfce7;color:#166534;border-radius:4px;padding:1px 5px;font-size:9.5px;font-weight:700;margin-left:4px;">Bearer</span>';
        const selOpts=opts.map(s=>`<option value="${s}"${st===s?' selected':''}>${slbls[s]}</option>`).join('');
        const bankRef=ch.bank_ref||'';
        h+=`<div class="pchq-card ${esc(st)}" id="pcard-${ch.id}">
            <div class="chqno-b">${esc(ch.cheque_no)}</div>
            <div class="chqi">
                <div class="chqi-cust">${esc(ch.customer_name||ch.t_code||'—')}</div>
                <div class="chqi-meta">
                    <span class="chqi-m"><i class="fa-solid fa-tag" style="font-size:9px;"></i> ${esc(ch.t_code||'—')}</span>
                    <span class="chqi-m"><i class="fa-regular fa-calendar" style="font-size:9px;"></i> ${fmtDate(ch.cheque_date)}</span>
                    <span class="chqi-m"><i class="fa-solid fa-building-columns" style="font-size:9px;"></i> ${esc(ch.bank_name||ch.bank_code||'—')}</span>
                    ${mBadge}
                    ${bankRef?`<span class="bank-ref-tag" title="Bank Reference"><i class="fa-solid fa-hashtag" style="font-size:9px;"></i> ${esc(bankRef)}</span>`:''}
                </div>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:5px;flex-shrink:0;">
                <div style="font-size:14px;font-weight:800;color:#0d9488;">Rs.&nbsp;${parseFloat(ch.total_amount||0).toLocaleString('en-US',{minimumFractionDigits:2})}</div>
                <select class="pstat-sel ${esc(st)}" id="pstat-${ch.id}" onchange="pDirty(${ch.id},this)">
                    ${selOpts}
                </select>
                <input type="text" class="pref-inp" id="pref-${ch.id}" value="${esc(bankRef)}" placeholder="Bank ref…" oninput="pDirty(${ch.id},null)">
                <button class="psave-btn" id="psave-${ch.id}" onclick="pSave(${ch.id})">
                    <i class="fa-solid fa-floppy-disk"></i> Save
                </button>
            </div>
        </div>`;
    });
    document.getElementById('panelBody').innerHTML=h;
}
function pDirty(id,sel){
    _panelDirty.add(id);
    const btn=document.getElementById('psave-'+id);
    if(btn)btn.classList.add('vis');
    if(sel){const card=document.getElementById('pcard-'+id);if(card)card.className='pchq-card '+sel.value;sel.className='pstat-sel '+sel.value;}
}
async function pSave(id){
    const stEl=document.getElementById('pstat-'+id);
    const rEl=document.getElementById('pref-'+id);
    const btn=document.getElementById('psave-'+id);
    if(!stEl||!btn)return;
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i>';
    const fd=new FormData();
    fd.append('ajax_action','update_cheque_status');fd.append('cheque_id',id);
    fd.append('status',stEl.value);fd.append('bank_ref',rEl?rEl.value:'');
    try{
        const res=await fetch('cheque_deposit_list.php',{method:'POST',body:fd});
        const data=await res.json();
        if(data.success){_panelDirty.delete(id);btn.classList.remove('vis');btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save';showToast('Saved ✓','ok');}
        else{showToast(data.error||'Save failed','err');btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save';}
    }catch(e){showToast('Network error','err');btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save';}
}

/* ════ NDB IMPORT ════ */
let _prevRows=[];let _stmtMeta={};
function openNdbModal(){document.getElementById('ndbModal').classList.add('open');document.body.style.overflow='hidden';}
function closeNdbModal(){document.getElementById('ndbModal').classList.remove('open');document.body.style.overflow='';}
function handleFileDrop(e){e.preventDefault();document.getElementById('uploadArea').classList.remove('drag-over');const f=e.dataTransfer.files[0];if(f)processFile(f);}
function handleFileSelect(inp){const f=inp.files[0];if(f)processFile(f);}

async function processFile(file){
    const ext=file.name.split('.').pop().toLowerCase();
    if(ext!=='csv'){showToast('Please upload a CSV file. For Excel, use File → Save As → CSV first.','err');return;}
    document.getElementById('ndbInfo').textContent='Parsing file…';
    document.getElementById('uploadArea').innerHTML=`<div class="ua-icon"><i class="fa-solid fa-spinner fa-spin" style="color:#0d9488;"></i></div><div class="ua-title">Parsing ${esc(file.name)}…</div>`;
    const text=await file.text();
    const {rows,meta}=parseNdb(text);
    if(!rows.length){showToast('No cheque deposit or return rows found in this file.','err');resetUpload();return;}
    _stmtMeta=meta;
    document.getElementById('ndbInfo').textContent='Matching '+rows.length+' entries with database…';
    const fd=new FormData();fd.append('ajax_action','ndb_preview');fd.append('parsed_rows',JSON.stringify(rows));
    try{
        const res=await fetch('cheque_deposit_list.php',{method:'POST',body:fd});
        const data=await res.json();
        if(!data.success)throw new Error(data.error||'Preview failed');
        _prevRows=data.rows||[];showStep2();
    }catch(e){showToast('Error: '+e.message,'err');resetUpload();}
}

function parseNdb(text){
    const lines=text.split('\n').map(l=>l.trim());
    const rows=[];const meta={account:'',customer:'',period:'',opening:'',closing:''};
    lines.forEach(l=>{
        if(l.startsWith('Account Number,'))   meta.account=l.split(',')[1]?.trim()||'';
        if(l.startsWith('Owning Customer,'))   meta.customer=l.split(',')[1]?.trim()||'';
        if(l.startsWith('Statement Period,'))  meta.period=l.split(',')[1]?.trim()||'';
        if(l.startsWith('Opening Balance,'))   meta.opening=l.split(',')[1]?.trim()||'';
        if(l.startsWith('Closing Balance,'))   meta.closing=l.split(',')[1]?.trim()||'';
    });
    let hi=-1;lines.forEach((l,i)=>{if(l.startsWith('Transaction Date,'))hi=i;});
    if(hi<0)return{rows,meta};
    for(let i=hi+1;i<lines.length;i++){
        const cols=parseCsvLine(lines[i]);
        if(cols.length<6)continue;
        const txDate=cols[0]?.trim()||'';
        const desc  =cols[2]?.trim()||'';
        const ref   =cols[3]?.trim()||'';
        const debit =parseFloat((cols[4]||'').replace(/,/g,''))||0;
        const credit=parseFloat((cols[5]||'').replace(/,/g,''))||0;
        let m=desc.match(/Outward Cheque Deposit\/CHQ NO\s*-\s*(\S+)/i);
        if(m){rows.push({cheque_no:m[1].trim(),type:'cleared',amount:credit||debit,bank_ref:ref,tx_date:txDate,description:desc});continue;}
        m=desc.match(/Outward Clg Chq Return\/CHQ NO\s*-\s*(\S+)/i);
        if(m)rows.push({cheque_no:m[1].trim(),type:'returned',amount:debit||credit,bank_ref:ref,tx_date:txDate,description:desc});
    }
    return{rows,meta};
}
function parseCsvLine(line){
    const res=[];let cur='';let inQ=false;
    for(let i=0;i<line.length;i++){const ch=line[i];if(ch==='"'){inQ=!inQ;}else if(ch===','&&!inQ){res.push(cur);cur='';}else{cur+=ch;}}
    res.push(cur);return res;
}

function showStep2(){
    document.getElementById('ndbStep1').style.display='none';
    document.getElementById('ndbStep2').style.display='block';
    document.getElementById('ndbTab1').className='ndb-tab done';
    document.getElementById('ndbTab2').className='ndb-tab active';
    const m=_stmtMeta;
    document.getElementById('stmtBar').innerHTML=`<div style="font-size:11px;font-weight:800;color:#0f766e;margin-bottom:7px;display:flex;align-items:center;gap:6px;"><i class="fa-solid fa-landmark"></i> Statement Details</div>
    <div class="stmt-grid">
        <div class="stmt-item"><span class="stmt-lbl">Customer</span><span class="stmt-val">${esc(m.customer)}</span></div>
        <div class="stmt-item"><span class="stmt-lbl">Period</span><span class="stmt-val">${esc(m.period)}</span></div>
        <div class="stmt-item"><span class="stmt-lbl">Opening</span><span class="stmt-val">${esc(m.opening)}</span></div>
        <div class="stmt-item"><span class="stmt-lbl">Closing</span><span class="stmt-val">${esc(m.closing)}</span></div>
    </div>`;
    const matched=_prevRows.filter(r=>r.matched).length;
    const notFound=_prevRows.filter(r=>!r.matched).length;
    const toClr=_prevRows.filter(r=>r.type==='cleared'&&r.matched).length;
    const toRet=_prevRows.filter(r=>r.type==='returned'&&r.matched).length;
    const amtD=_prevRows.filter(r=>r.matched&&!r.amt_match).length;
    document.getElementById('prevStats').innerHTML=`
        <span class="prev-stat"><i class="fa-solid fa-check-circle"></i> ${matched} matched</span>
        <span class="prev-stat" style="background:#dcfce7;border-color:#86efac;color:#166534;"><i class="fa-solid fa-circle-check"></i> ${toClr} → Cleared</span>
        <span class="prev-stat" style="background:#fee2e2;border-color:#fecaca;color:#991b1b;"><i class="fa-solid fa-circle-xmark"></i> ${toRet} → Returned</span>
        ${notFound?`<span class="prev-stat err"><i class="fa-solid fa-triangle-exclamation"></i> ${notFound} not found in DB</span>`:''}
        ${amtD?`<span class="prev-stat warn"><i class="fa-solid fa-scale-unbalanced"></i> ${amtD} amount mismatch</span>`:''}`;
    const slbls={deposited:'<span class="pill p-blue">Deposited</span>',cleared:'<span class="pill p-green">Cleared</span>',returned:'<span class="pill p-red">Returned</span>',sent_back:'<span class="pill p-violet">Sent Back</span>',pending:'<span class="pill p-gray">Pending</span>'};
    let h='';
    _prevRows.forEach((row,idx)=>{
        const rc=row.type==='cleared'?'row-cleared':row.type==='returned'?'row-returned':'';
        const notFnd=!row.matched?'row-notfound':'';
        const matchB=row.matched?'<span class="mb mb-ok"><i class="fa-solid fa-check"></i> Matched</span>':'<span class="mb mb-no"><i class="fa-solid fa-xmark"></i> Not Found</span>';
        const typeB=row.type==='cleared'?'<span class="mb mb-clr"><i class="fa-solid fa-circle-check"></i> Clear</span>':'<span class="mb mb-ret"><i class="fa-solid fa-circle-xmark"></i> Return</span>';
        const amtB=row.matched?(row.amt_match?'<span class="mb mb-amt"><i class="fa-solid fa-check"></i> OK</span>':'<span class="mb mb-amtd"><i class="fa-solid fa-triangle-exclamation"></i> Diff</span>'):'—';
        const dbA=row.db_amount!=null?'Rs. '+parseFloat(row.db_amount).toLocaleString('en-US',{minimumFractionDigits:2}):'—';
        const bnkA='Rs. '+parseFloat(row.bank_amount).toLocaleString('en-US',{minimumFractionDigits:2});
        const dbSt=slbls[row.db_status]||'—';
        h+=`<tr class="${rc} ${notFnd}" data-idx="${idx}">
            <td><input type="checkbox" class="ndb-chk row-ndb-cb" data-idx="${idx}" ${row.matched?'checked':'disabled'} title="${!row.matched?'Not found in database':''}"></td>
            <td><span class="mono" style="color:#3730a3;font-size:12px;">${esc(row.cheque_no)}</span></td>
            <td>${typeB}</td>
            <td><div style="font-size:11.5px;font-weight:600;color:#1f2937;">${esc(row.customer_name||'—')}</div><div style="font-size:10px;color:#9ca3af;font-family:'Courier New',monospace;">${esc(row.db_t_code||'')}</div>${matchB}</td>
            <td class="tr" style="font-weight:700;">${bnkA}</td>
            <td class="tr" style="color:#6b7280;">${dbA}</td>
            <td class="tc">${amtB}</td>
            <td class="tc">${dbSt}</td>
            <td><span style="font-family:'Courier New',monospace;font-size:10px;color:#0369a1;">${esc(row.bank_ref||'')}</span></td>
            <td style="font-size:11px;white-space:nowrap;">${esc(row.tx_date||'')}</td>
        </tr>`;
    });
    document.getElementById('prevBody').innerHTML=h||'<tr><td colspan="10" style="text-align:center;padding:20px;color:#9ca3af;">No matching rows found</td></tr>';
    const selN=_prevRows.filter(r=>r.matched).length;
    document.getElementById('ndbInfo').textContent=`${selN} cheque(s) ready to update. Review selections then click Apply.`;
    document.getElementById('ndbApplyBtn').style.display='flex';
    document.getElementById('prevBody').addEventListener('change',updateNdbCount);
}
function selAll(checked){document.querySelectorAll('.row-ndb-cb:not(:disabled)').forEach(cb=>cb.checked=checked);document.getElementById('prevSelAll').checked=checked;updateNdbCount();}
function selByType(type){document.querySelectorAll('.row-ndb-cb').forEach(cb=>{if(!cb.disabled){const r=_prevRows[parseInt(cb.dataset.idx)];cb.checked=r&&r.type===type;}});updateNdbCount();}
function selMatched(v){document.querySelectorAll('.row-ndb-cb').forEach(cb=>{if(!cb.disabled){const r=_prevRows[parseInt(cb.dataset.idx)];cb.checked=r&&r.matched&&v;}});updateNdbCount();}
function updateNdbCount(){const n=document.querySelectorAll('.row-ndb-cb:checked').length;document.getElementById('ndbInfo').textContent=`${n} cheque(s) selected. Click Apply to update.`;}

async function applyNdb(){
    const cbs=Array.from(document.querySelectorAll('.row-ndb-cb:checked'));
    if(!cbs.length){showToast('No cheques selected','err');return;}
    const items=cbs.map(cb=>_prevRows[parseInt(cb.dataset.idx)]).filter(r=>r&&r.matched&&r.db_id);
    if(!items.length){showToast('No matched cheques selected','err');return;}
    const btn=document.getElementById('ndbApplyBtn');
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Applying…';
    const fd=new FormData();fd.append('ajax_action','ndb_apply');fd.append('items',JSON.stringify(items));
    try{
        const res=await fetch('cheque_deposit_list.php',{method:'POST',body:fd});
        const data=await res.json();
        if(!data.success)throw new Error(data.error||'Apply failed');
        showStep3(data);
    }catch(e){showToast('Error: '+e.message,'err');btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-circle-check"></i> Apply Selected Updates';}
}
function showStep3(data){
    document.getElementById('ndbStep2').style.display='none';
    document.getElementById('ndbStep3').style.display='block';
    document.getElementById('ndbTab2').className='ndb-tab done';
    document.getElementById('ndbTab3').className='ndb-tab active';
    document.getElementById('ndbApplyBtn').style.display='none';
    document.getElementById('ndbDoneBtn').style.display='flex';
    document.getElementById('ndbInfo').textContent='Update complete!';
    const skip=data.skip_list||[];
    document.getElementById('applyResult').innerHTML=`
        <div style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:2px solid #4ade80;border-radius:12px;padding:24px;text-align:center;margin-bottom:16px;">
            <i class="fa-solid fa-circle-check" style="font-size:42px;color:#16a34a;margin-bottom:12px;display:block;"></i>
            <div style="font-size:22px;font-weight:800;color:#166534;margin-bottom:6px;">${data.updated} Cheque(s) Updated</div>
            <div style="font-size:13px;color:#4b7c59;">All changes saved to database and logged to cheque_logs.</div>
        </div>
        ${data.skipped?`<div style="background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:14px 16px;">
            <div style="font-weight:700;color:#92400e;margin-bottom:5px;"><i class="fa-solid fa-triangle-exclamation"></i> ${data.skipped} skipped</div>
            <div style="font-size:12px;color:#92400e;">${skip.map(s=>esc(s)).join(', ')}</div>
        </div>`:''}`;
}
function resetUpload(){
    document.getElementById('uploadArea').innerHTML=`<div class="ua-icon"><i class="fa-solid fa-file-excel"></i></div><div class="ua-title">Upload NDB Bank Statement</div><div class="ua-sub">CSV file · Drag &amp; drop or click to browse</div><div style="margin-top:14px;"><span style="background:#0d9488;color:#fff;padding:7px 18px;border-radius:7px;font-size:12px;font-weight:700;"><i class="fa-solid fa-folder-open"></i> Browse CSV File</span></div>`;
    document.getElementById('ndbFileInput').value='';
    document.getElementById('ndbInfo').textContent='Upload a NDB bank statement CSV to begin.';
}

/* ════ CSV EXPORT ════ */
function exportCSV(){
    const rows=document.querySelectorAll('#tableBody tr.dep-row');
    if(!rows.length){alert('No data');return;}
    const h=['No','Deposit Date','Account','Acc No','Bank','Type','Total Cheques','Deposited','Cleared','Returned','Total Amount'];
    const lines=[h.join(',')];const q=v=>'"'+(v||'').toString().replace(/"/g,'""').replace(/\s+/g,' ').trim()+'"';
    rows.forEach((tr,i)=>{
        const b=ALL_BATCHES.find(x=>bUid(x)===tr.dataset.uid)||{};
        lines.push([i+1,q(tr.dataset.depDate),q(b.account_name),q(b.account_no),q(b.bank_name),q(tr.dataset.depType),q(b.cheque_count),q(b.deposited_count),q(b.cleared_count),q(b.returned_count),q(b.total_amount)].join(','));
    });
    const blob=new Blob([lines.join('\n')],{type:'text/csv'});
    const url=URL.createObjectURL(blob);const a=document.createElement('a');
    a.href=url;a.download='deposit_list_<?=date("Ymd_Hi")?>.csv';a.click();URL.revokeObjectURL(url);
}

/* ════ HELPERS ════ */
function esc(s){if(s==null)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
function fmtDate(s){if(!s)return'—';try{const d=new Date(s.replace(' ','T'));return d.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});}catch(e){return s;}}
function showToast(msg,type){
    const t=document.getElementById('toast');
    t.style.background=type==='ok'?'#166534':'#dc2626';
    t.textContent=msg;t.classList.add('show');
    clearTimeout(t._t);t._t=setTimeout(()=>t.classList.remove('show'),3500);
}
</script>

<?php include 'footer.php'; ?>