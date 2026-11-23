<?php
/**
 * bo_deposit_bank_recon.php — BO Cash Deposit ↔ Bank Statement reconciliation
 *  • Auto: a BO cash deposit (bo_bank_deposits) ↔ ONE bank credit of the same bank account with the SAME AMOUNT on the
 *          deposit date (or within the ± days picked). Each deposit / bank line is used once (closest date first).
 *  • Manual: a deposit that was not found ↔ 1 or more open bank credits of its account (difference allowed with a remark).
 *  • Saved in BATCHES (RB-BO-00001): the bank line gets the reconcile remark (recon_status / recon_source = bo_cash_deposit),
 *    the deposit gets bank_recon_*; deleting a batch undoes all of it. History tab lists the batches.
 */
date_default_timezone_set('Asia/Colombo');
include 'config.php';
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
if (session_status() === PHP_SESSION_NONE) session_start();

const BOR_SOURCE   = 'bo_cash_deposit';
const BOR_CATEGORY = 'BO Cash Deposit';

function bor_q($conn, $sql) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } }
function bor_json($a) { while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json'); echo json_encode($a); exit; }
function bor_col($conn, $t, $c) { $r = bor_q($conn, "SHOW COLUMNS FROM `$t` LIKE '" . mysqli_real_escape_string($conn, $c) . "'"); return $r && mysqli_num_rows($r) > 0; }
function bor_user() { return $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? (isset($_SESSION['user_id']) ? 'User #' . $_SESSION['user_id'] : 'system'); }
function bor_date($d) { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : ''; }
function bor_ids($a) { $a = array_values(array_filter(array_map('intval', (array)$a))); return $a ? implode(',', $a) : '0'; }
function bor_ensure($conn) {
    bor_q($conn, "CREATE TABLE IF NOT EXISTS bo_bank_recon_batches (
        id INT AUTO_INCREMENT PRIMARY KEY, batch_no VARCHAR(20) NULL, date_from DATE NULL, date_to DATE NULL, tolerance_days TINYINT NOT NULL DEFAULT 0,
        bank_account_id INT NULL, remark TEXT NULL, rec_count INT NOT NULL DEFAULT 0, rec_dep_amount DECIMAL(14,2) NOT NULL DEFAULT 0, rec_bank_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        unrec_dep_count INT NOT NULL DEFAULT 0, unrec_dep_amount DECIMAL(14,2) NOT NULL DEFAULT 0, created_by VARCHAR(100) NULL, created_at DATETIME NOT NULL, INDEX (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    bor_q($conn, "CREATE TABLE IF NOT EXISTS bo_bank_recon (
        id INT AUTO_INCREMENT PRIMARY KEY, batch_id INT NOT NULL, method VARCHAR(10) NOT NULL DEFAULT 'auto', deposit_id INT NOT NULL, bank_account_id INT NULL,
        deposit_date DATE NULL, deposit_amount DECIMAL(14,2) NOT NULL DEFAULT 0, bank_amount DECIMAL(14,2) NOT NULL DEFAULT 0, difference DECIMAL(14,2) NOT NULL DEFAULT 0,
        remark TEXT NULL, created_by VARCHAR(100) NULL, created_at DATETIME NOT NULL, UNIQUE KEY uq_dep (deposit_id), INDEX (batch_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    bor_q($conn, "CREATE TABLE IF NOT EXISTS bo_bank_recon_bank (
        id INT AUTO_INCREMENT PRIMARY KEY, recon_id INT NOT NULL, bank_txn_id INT NOT NULL, transaction_date DATE NULL, description TEXT NULL,
        credit DECIMAL(15,2) NOT NULL DEFAULT 0, UNIQUE KEY uq_txn (bank_txn_id), INDEX (recon_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach (['recon_status' => "VARCHAR(20) NULL", 'recon_source' => "VARCHAR(30) NULL", 'recon_category' => "VARCHAR(50) NULL", 'recon_ref_id' => "INT NULL",
              'recon_remark' => "TEXT NULL", 'recon_by' => "VARCHAR(100) NULL", 'recon_at' => "DATETIME NULL"] as $c => $d)
        if (!bor_col($conn, 'bank_statement_transactions', $c)) bor_q($conn, "ALTER TABLE bank_statement_transactions ADD COLUMN `$c` $d");
    foreach (['bank_recon_id' => "INT NULL", 'bank_recon_remark' => "TEXT NULL"] as $c => $d)
        if (!bor_col($conn, 'bo_bank_deposits', $c)) bor_q($conn, "ALTER TABLE bo_bank_deposits ADD COLUMN `$c` $d");
}
bor_ensure($conn);
function bor_can($a) { if (!function_exists('rbac_can') && file_exists(__DIR__ . '/rbac.php')) @include_once __DIR__ . '/rbac.php'; return function_exists('rbac_can') ? rbac_can($a, 'bo_deposit_bank_recon') : true; }
function bor_accounts($conn) {
    $o = []; $r = bor_q($conn, "SELECT cba.id, CONCAT(COALESCE(NULLIF(b.bank_name,''), cba.bank_code, ''), ' / ', COALESCE(NULLIF(bb.branch_name,''), cba.branch_code, ''), ' (', cba.account_no, ')') AS label
        FROM company_bank_accounts cba LEFT JOIN banks b ON b.bank_code = cba.bank_code LEFT JOIN bank_branches bb ON bb.bank_code = cba.bank_code AND bb.branch_code = cba.branch_code ORDER BY cba.id");
    while ($r && ($x = mysqli_fetch_assoc($r))) $o[(int)$x['id']] = $x['label']; return $o;
}
/* open bank credits (not reconciled by anything) of these accounts in the date range; the same line uploaded twice counts once */
function bor_bank_open($conn, array $accs, $from, $to) {
    $o = []; if (!$accs) return $o; $seen = [];
    $r = bor_q($conn, "SELECT t.id, u.account_id acc, t.transaction_date d, CONCAT_WS(' ', t.description, t.reference) descr, t.credit, t.balance
                       FROM bank_statement_transactions t JOIN bank_statement_uploads u ON u.id = t.upload_id
                       WHERE u.account_id IN (" . bor_ids($accs) . ") AND t.transaction_date BETWEEN '$from' AND '$to' AND t.credit > 0
                         AND (t.recon_status IS NULL OR t.recon_status = '')" . (bor_col($conn, 'bank_statement_transactions', 'reconciled') ? " AND COALESCE(t.reconciled,0) = 0" : "") . "
                       ORDER BY t.transaction_date, t.id LIMIT 5000");
    while ($r && ($x = mysqli_fetch_assoc($r))) { $k = $x['acc'] . '|' . $x['d'] . '|' . trim(preg_replace('/\s+/', ' ', $x['descr'])) . '|' . $x['credit'] . '|' . $x['balance']; if (isset($seen[$k])) continue; $seen[$k] = 1;
        $o[] = ['id' => (int)$x['id'], 'acc' => (int)$x['acc'], 'date' => $x['d'], 'desc' => trim(preg_replace('/\s+/', ' ', $x['descr'])), 'amount' => round((float)$x['credit'], 2)]; }
    return $o;
}

/* ═════════ AJAX ═════════ */
if (isset($_REQUEST['ajax'])) {
    $act = $_REQUEST['ajax'];
    if ($act === 'load') {
        $from = bor_date($_POST['from'] ?? ''); $to = bor_date($_POST['to'] ?? ''); $tol = max(0, min(7, (int)($_POST['tol'] ?? 0))); $acc = (int)($_POST['acc'] ?? 0);
        if ($from === '' || $to === '' || $to < $from) bor_json(['ok' => false, 'msg' => 'Pick the deposit date range.']);
        if ((strtotime($to) - strtotime($from)) / 86400 > 93) bor_json(['ok' => false, 'msg' => 'Pick 3 months or less.']);
        $w = "bd.deposit_date BETWEEN '$from' AND '$to'" . ($acc ? " AND bd.bank_account_id = $acc" : "");
        $deps = []; $rec = 0; $recAmt = 0;
        $empName = bor_col($conn, 'employees', 'name_with_initials') ? "COALESCE(NULLIF(e.name_with_initials,''), e.employee_full_name)" : "e.employee_full_name";
        $r = bor_q($conn, "SELECT bd.id, bd.delivery_date, bd.deposit_date, bd.bank_account_id acc, bd.amount, bd.remark, bd.bank_recon_id, $empName emp
                           FROM bo_bank_deposits bd LEFT JOIN employees e ON e.id = bd.employee_id WHERE $w ORDER BY bd.deposit_date, bd.id");
        if (!$r) bor_json(['ok' => false, 'msg' => 'Could not read the BO deposits: ' . mysqli_error($conn)]);
        while ($x = mysqli_fetch_assoc($r)) { if ((int)$x['bank_recon_id'] > 0) { $rec++; $recAmt += (float)$x['amount']; continue; }
            $deps[] = ['id' => (int)$x['id'], 'dd' => $x['delivery_date'], 'date' => $x['deposit_date'], 'acc' => (int)$x['acc'], 'amount' => round((float)$x['amount'], 2), 'emp' => $x['emp'] ?? '', 'remark' => $x['remark'] ?? '']; }
        $accs = $acc ? [$acc] : [];
        if (!$acc) { $r = bor_q($conn, "SELECT DISTINCT bank_account_id FROM bo_bank_deposits WHERE bank_account_id IS NOT NULL"); while ($r && ($x = mysqli_fetch_row($r))) $accs[] = (int)$x[0]; }   // every account BO deposits go to
        $bf = date('Y-m-d', strtotime("$from -$tol days")); $bt = date('Y-m-d', strtotime("$to +$tol days"));
        $bank = bor_bank_open($conn, $accs, $bf, $bt);
        /* matching: same account + same amount, date within tolerance — closest date first, each line once */
        $cand = [];
        foreach ($deps as $i => $d) foreach ($bank as $j => $b)
            if ($b['acc'] === $d['acc'] && abs($b['amount'] - $d['amount']) < 0.005) { $dd = abs((strtotime($b['date']) - strtotime($d['date'])) / 86400); if ($dd <= $tol) $cand[] = [$dd, $i, $j]; }
        usort($cand, fn($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1] ?: $a[2] <=> $b[2]);
        $usedD = []; $usedB = []; $matches = [];
        foreach ($cand as [$dd, $i, $j]) { if (isset($usedD[$i]) || isset($usedB[$j])) continue; $usedD[$i] = $usedB[$j] = 1; $matches[] = ['dep' => $deps[$i], 'bank' => $bank[$j], 'days' => (int)$dd]; }
        $unD = array_values(array_filter($deps, fn($d, $i) => !isset($usedD[$i]), ARRAY_FILTER_USE_BOTH));
        $unB = array_values(array_filter($bank, fn($b, $j) => !isset($usedB[$j]) && $b['date'] >= $from && $b['date'] <= $to, ARRAY_FILTER_USE_BOTH));
        bor_json(['ok' => true, 'matches' => $matches, 'unDeps' => $unD, 'unBank' => $unB, 'already' => ['n' => $rec, 'amt' => round($recAmt, 2)], 'accounts' => bor_accounts($conn)]);
    }
    if ($act === 'bank_open') {   // for a manual match: open credits of one account around a date
        $acc = (int)($_POST['acc'] ?? 0); $from = bor_date($_POST['from'] ?? ''); $to = bor_date($_POST['to'] ?? '');
        if (!$acc || $from === '' || $to === '') bor_json(['ok' => false, 'msg' => 'Missing account or dates.']);
        bor_json(['ok' => true, 'rows' => bor_bank_open($conn, [$acc], $from, $to)]);
    }
    if ($act === 'save_batch') {
        if (!bor_can('create') && !bor_can('edit')) bor_json(['ok' => false, 'msg' => 'Permission blocked: your role cannot save reconciliations here.']);
        $items = json_decode($_POST['items'] ?? '[]', true); if (!is_array($items) || !$items) bor_json(['ok' => false, 'msg' => 'Nothing ticked to save.']);
        $from = bor_date($_POST['from'] ?? ''); $to = bor_date($_POST['to'] ?? ''); $tol = (int)($_POST['tol'] ?? 0); $acc = (int)($_POST['acc'] ?? 0);
        $e = fn($v) => mysqli_real_escape_string($conn, (string)$v); $u = $e(bor_user()); $now = date('Y-m-d H:i:s'); $bremark = trim($_POST['remark'] ?? '');
        mysqli_begin_transaction($conn);
        try {
            if (!bor_q($conn, "INSERT INTO bo_bank_recon_batches (date_from, date_to, tolerance_days, bank_account_id, remark, created_by, created_at) VALUES (" . ($from ? "'$from'" : "NULL") . "," . ($to ? "'$to'" : "NULL") . ",$tol," . ($acc ?: "NULL") . ",'" . $e($bremark) . "','$u','$now')")) throw new Exception(mysqli_error($conn));
            $bid = (int)mysqli_insert_id($conn); $bno = 'RB-BO-' . str_pad((string)$bid, 5, '0', STR_PAD_LEFT);
            $n = 0; $da = 0; $ba = 0;
            foreach ($items as $it) {
                $did = (int)($it['dep'] ?? 0); $txs = array_values(array_unique(array_filter(array_map('intval', (array)($it['bank'] ?? [])))));
                $method = ($it['method'] ?? '') === 'manual' ? 'manual' : 'auto'; $rem = trim((string)($it['remark'] ?? ''));
                if (!$did || !$txs) throw new Exception('A line has no deposit or no bank line.');
                $r = bor_q($conn, "SELECT id, deposit_date, bank_account_id, amount, bank_recon_id FROM bo_bank_deposits WHERE id = $did FOR UPDATE"); $d = $r ? mysqli_fetch_assoc($r) : null;
                if (!$d) throw new Exception("BO deposit #$did not found."); if ((int)$d['bank_recon_id'] > 0) throw new Exception("BO deposit #$did is already reconciled — reload.");
                $r = bor_q($conn, "SELECT t.id, t.transaction_date, CONCAT_WS(' ', t.description, t.reference) descr, t.credit, t.recon_status, u.account_id FROM bank_statement_transactions t JOIN bank_statement_uploads u ON u.id = t.upload_id WHERE t.id IN (" . bor_ids($txs) . ") FOR UPDATE");
                $T = []; while ($r && ($x = mysqli_fetch_assoc($r))) $T[] = $x;
                if (count($T) !== count($txs)) throw new Exception('A bank line was not found — reload.');
                $sum = 0; foreach ($T as $t) { if (trim((string)$t['recon_status']) !== '') throw new Exception('Bank line of ' . $t['transaction_date'] . ' (' . number_format($t['credit'], 2) . ') is already reconciled — reload.');
                    if ((int)$t['account_id'] !== (int)$d['bank_account_id']) throw new Exception('A bank line is from another bank account than the deposit.'); $sum += (float)$t['credit']; }
                $diff = round((float)$d['amount'] - $sum, 2);
                if ($method === 'auto' && abs($diff) > 0.005) throw new Exception('Auto match amounts differ — reload.');
                if (abs($diff) > 0.005 && $rem === '') throw new Exception('Give a remark for the difference of ' . number_format($diff, 2) . " (deposit #$did).");
                if (!bor_q($conn, "INSERT INTO bo_bank_recon (batch_id, method, deposit_id, bank_account_id, deposit_date, deposit_amount, bank_amount, difference, remark, created_by, created_at)
                     VALUES ($bid, '$method', $did, " . (int)$d['bank_account_id'] . ", " . ($d['deposit_date'] ? "'" . $e($d['deposit_date']) . "'" : "NULL") . ", " . (float)$d['amount'] . ", $sum, $diff, '" . $e($rem) . "', '$u', '$now')")) throw new Exception(mysqli_error($conn));
                $rid = (int)mysqli_insert_id($conn);
                $txt = "$bno · BO cash deposit #$did (" . ($d['deposit_date'] ?: '') . ") Rs. " . number_format($d['amount'], 2) . ($method === 'manual' ? ' · manual' : '') . ($rem !== '' ? ' · ' . $rem : '');
                foreach ($T as $t) {
                    if (!bor_q($conn, "INSERT INTO bo_bank_recon_bank (recon_id, bank_txn_id, transaction_date, description, credit) VALUES ($rid, " . (int)$t['id'] . ", '" . $e($t['transaction_date']) . "', '" . $e($t['descr']) . "', " . (float)$t['credit'] . ")")) throw new Exception(mysqli_error($conn));
                    bor_q($conn, "UPDATE bank_statement_transactions SET recon_status = 'reconciled', recon_source = '" . BOR_SOURCE . "', recon_category = '" . BOR_CATEGORY . "', recon_ref_id = $rid, recon_remark = '" . $e($txt) . "', recon_by = '$u', recon_at = '$now' WHERE id = " . (int)$t['id']);
                }
                bor_q($conn, "UPDATE bo_bank_deposits SET bank_recon_id = $rid, bank_recon_remark = '" . $e($txt) . "' WHERE id = $did");
                $n++; $da += (float)$d['amount']; $ba += $sum;
            }
            $ud = json_decode($_POST['unrec'] ?? '{}', true) ?: [];
            bor_q($conn, "UPDATE bo_bank_recon_batches SET batch_no = '$bno', rec_count = $n, rec_dep_amount = $da, rec_bank_amount = $ba, unrec_dep_count = " . (int)($ud['n'] ?? 0) . ", unrec_dep_amount = " . (float)($ud['amt'] ?? 0) . " WHERE id = $bid");
            mysqli_commit($conn);
            bor_json(['ok' => true, 'batch' => $bno, 'n' => $n]);
        } catch (Throwable $ex) { mysqli_rollback($conn); bor_json(['ok' => false, 'msg' => 'Not saved: ' . $ex->getMessage()]); }
    }
    if ($act === 'batches') {
        $o = []; $r = bor_q($conn, "SELECT * FROM bo_bank_recon_batches ORDER BY id DESC LIMIT 300"); while ($r && ($x = mysqli_fetch_assoc($r))) $o[] = $x;
        bor_json(['ok' => true, 'rows' => $o, 'accounts' => bor_accounts($conn)]);
    }
    if ($act === 'batch_view') {
        $bid = (int)($_POST['id'] ?? 0); $o = [];
        $r = bor_q($conn, "SELECT r.*, GROUP_CONCAT(CONCAT(b.transaction_date, ' · ', FORMAT(b.credit, 2), ' · ', LEFT(b.description, 80)) SEPARATOR '\n') bank FROM bo_bank_recon r LEFT JOIN bo_bank_recon_bank b ON b.recon_id = r.id WHERE r.batch_id = $bid GROUP BY r.id ORDER BY r.deposit_date, r.id");
        while ($r && ($x = mysqli_fetch_assoc($r))) $o[] = $x; bor_json(['ok' => true, 'rows' => $o]);
    }
    if ($act === 'delete_batch') {
        if (!bor_can('delete')) bor_json(['ok' => false, 'msg' => 'Permission blocked: your role cannot delete reconciliation batches.']);
        $bid = (int)($_POST['id'] ?? 0); $rids = []; $r = bor_q($conn, "SELECT id FROM bo_bank_recon WHERE batch_id = $bid"); while ($r && ($x = mysqli_fetch_row($r))) $rids[] = (int)$x[0];
        mysqli_begin_transaction($conn);
        if ($rids) { $in = bor_ids($rids);
            bor_q($conn, "UPDATE bank_statement_transactions SET recon_status = NULL, recon_source = NULL, recon_category = NULL, recon_ref_id = NULL, recon_remark = NULL, recon_by = NULL, recon_at = NULL WHERE recon_source = '" . BOR_SOURCE . "' AND recon_ref_id IN ($in)");
            bor_q($conn, "UPDATE bo_bank_deposits SET bank_recon_id = NULL, bank_recon_remark = NULL WHERE bank_recon_id IN ($in)");
            bor_q($conn, "DELETE FROM bo_bank_recon_bank WHERE recon_id IN ($in)"); bor_q($conn, "DELETE FROM bo_bank_recon WHERE id IN ($in)"); }
        bor_q($conn, "DELETE FROM bo_bank_recon_batches WHERE id = $bid"); mysqli_commit($conn);
        bor_json(['ok' => true, 'n' => count($rids)]);
    }
    bor_json(['ok' => false, 'msg' => 'Unknown action']);
}

$accounts = bor_accounts($conn);
$acc_used = []; $r = bor_q($conn, "SELECT DISTINCT bank_account_id FROM bo_bank_deposits WHERE bank_account_id IS NOT NULL"); while ($r && ($x = mysqli_fetch_row($r))) $acc_used[] = (int)$x[0];
include 'header.php';
?>
<style>
.bor{padding:16px;color:#111827}
.bor h2{margin:0 0 4px;font-size:20px;font-weight:800;display:flex;gap:9px;align-items:center}.bor .sub{color:#6b7280;font-size:12.5px;margin-bottom:12px}
.bor-tabs{display:flex;gap:6px;margin-bottom:12px}.bor-tab{border:1px solid #e5e7eb;background:#fff;border-radius:8px;padding:8px 16px;font-weight:700;font-size:13px;cursor:pointer;color:#475569}.bor-tab.on{background:#1e3a8a;color:#fff;border-color:#1e3a8a}
.bor-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:10px 12px;margin-bottom:12px}
.bor-bar label{font-size:11.5px;font-weight:700;color:#475569;display:flex;flex-direction:column;gap:3px}
.bor-bar input,.bor-bar select{border:1.5px solid #e5e7eb;border-radius:8px;padding:6px 8px;font-size:13px}
.bb{border:none;border-radius:8px;padding:8px 14px;font-weight:700;font-size:13px;cursor:pointer;display:inline-flex;gap:6px;align-items:center;white-space:nowrap}
.bb.p{background:#2563eb;color:#fff}.bb.ok{background:#16a34a;color:#fff}.bb.g{background:#f3f4f6;color:#374151}.bb.r{background:#fee2e2;color:#b91c1c}.bb.o{background:#f59e0b;color:#fff}.bb:disabled{opacity:.5;cursor:not-allowed}
.kp{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}.kp div{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:8px 14px;min-width:150px}.kp b{display:block;font-size:17px}.kp span{font-size:11px;color:#6b7280;font-weight:700;text-transform:uppercase}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;margin-bottom:12px;overflow:hidden}
.card h3{margin:0;padding:10px 14px;font-size:14px;font-weight:800;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;gap:8px;background:#f8fafc}
.tw{overflow:auto;max-height:460px}
.t{width:100%;border-collapse:collapse;font-size:12.5px}.t th{background:#f1f5f9;text-align:left;padding:7px 8px;font-size:11px;text-transform:uppercase;color:#475569;position:sticky;top:0;white-space:nowrap}
.t td{padding:6px 8px;border-top:1px solid #f1f5f9;vertical-align:top}.t .r{text-align:right;white-space:nowrap}.t .nw{white-space:nowrap}.t .ds{max-width:360px;font-size:11.5px;color:#475569}
.pill{border-radius:99px;padding:1px 8px;font-size:11px;font-weight:800}.pill.a{background:#dcfce7;color:#166534}.pill.m{background:#ede9fe;color:#5b21b6}.pill.d{background:#fef3c7;color:#92400e}
.foot{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:10px 14px;background:#f8fafc;border-top:1px solid #e5e7eb}
.empty{padding:18px;color:#6b7280;text-align:center}
.mm{position:fixed;inset:0;z-index:2147483000;background:rgba(15,23,42,.6);display:none;align-items:flex-start;justify-content:center;padding:20px 10px;overflow:auto}.mm.open{display:flex}
.mm-box{background:#fff;border-radius:14px;width:min(1000px,100%);box-shadow:0 24px 60px rgba(0,0,0,.4);overflow:hidden}
.mm-hd{display:flex;align-items:center;gap:10px;padding:12px 16px;background:linear-gradient(90deg,#1e3a8a,#2563eb);color:#fff}.mm-hd b{flex:1}.mm-hd button{width:32px;height:32px;border:none;border-radius:50%;background:#dc2626;color:#fff;font-size:18px;cursor:pointer}
.mm-bd{padding:12px 16px}
@media(max-width:700px){.bor{padding:10px}.kp div{min-width:120px}}
</style>
<div class="bor">
  <h2><i class="fa-solid fa-building-columns" style="color:#2563eb"></i> BO Cash Deposit Reconciliation</h2>
  <div class="sub">BO cash deposits ↔ bank statement credits of the same bank account. <b>Auto</b>: same amount on the deposit date (± days). The rest: <b>Match manually</b> with 1 or more bank credits. Saved as a batch — delete the batch to undo.</div>
  <div class="bor-tabs"><button class="bor-tab on" data-t="rec" onclick="tab('rec')"><i class="fa-solid fa-scale-balanced"></i> Reconcile</button><button class="bor-tab" data-t="his" onclick="tab('his')"><i class="fa-solid fa-clock-rotate-left"></i> History (batches)</button></div>

  <div id="tRec">
    <div class="bor-bar">
      <label>Deposit date from<input type="date" id="fFrom"></label>
      <label>to<input type="date" id="fTo"></label>
      <label>Bank account<select id="fAcc"><option value="0">All accounts</option><?php foreach ($accounts as $id => $l): if ($acc_used && !in_array($id, $acc_used, true)) continue; ?><option value="<?= $id ?>"><?= htmlspecialchars($l) ?></option><?php endforeach; ?></select></label>
      <label>Date tolerance<select id="fTol"><option value="0">Same date</option><option value="1">± 1 day</option><option value="2">± 2 days</option><option value="3">± 3 days</option><option value="5">± 5 days</option></select></label>
      <button class="bb p" onclick="load()"><i class="fa-solid fa-wand-magic-sparkles"></i> Load &amp; match</button>
    </div>
    <div class="kp" id="kp"></div>
    <div class="card"><h3><span class="pill a">AUTO</span> Matched — deposit date + amount <span id="mCnt" style="margin-left:auto;font-size:12px;color:#6b7280"></span></h3>
      <div class="tw"><table class="t"><thead><tr><th><input type="checkbox" id="mAll" checked onchange="document.querySelectorAll('.mChk').forEach(c=>c.checked=this.checked);tally()"></th><th>Deposit date</th><th>Delivery date</th><th>Employee</th><th>Bank account</th><th class="r">Deposit</th><th>Bank date</th><th>Bank description</th><th class="r">Bank credit</th></tr></thead><tbody id="mRows"><tr><td colspan="9" class="empty">Pick the dates and press Load &amp; match.</td></tr></tbody></table></div></div>
    <div class="card"><h3><span class="pill d">NOT FOUND</span> BO deposits without a bank line — match manually <span id="uCnt" style="margin-left:auto;font-size:12px;color:#6b7280"></span></h3>
      <div class="tw"><table class="t"><thead><tr><th>Deposit date</th><th>Delivery date</th><th>Employee</th><th>Bank account</th><th class="r">Deposit</th><th>Manual match</th><th></th></tr></thead><tbody id="uRows"><tr><td colspan="7" class="empty">—</td></tr></tbody></table></div></div>
    <div class="card"><h3>Open bank credits in the range (not reconciled by anything) <span id="bCnt" style="margin-left:auto;font-size:12px;color:#6b7280"></span></h3>
      <div class="tw" style="max-height:300px"><table class="t"><thead><tr><th>Date</th><th>Bank account</th><th>Description</th><th class="r">Credit</th></tr></thead><tbody id="bRows"><tr><td colspan="4" class="empty">—</td></tr></tbody></table></div></div>
    <div class="card"><div class="foot"><input id="bRemark" placeholder="Batch remark (optional)" style="flex:1;min-width:200px;border:1.5px solid #e5e7eb;border-radius:8px;padding:8px 10px;font-size:13px"><span id="sumTxt" style="font-size:13px;font-weight:700"></span><button class="bb ok" id="saveBtn" onclick="saveBatch()" data-perm="create"><i class="fa-solid fa-floppy-disk"></i> Save batch</button></div></div>
  </div>

  <div id="tHis" style="display:none">
    <div class="card"><h3>Saved batches</h3><div class="tw" style="max-height:none"><table class="t"><thead><tr><th>Batch</th><th>Saved</th><th>Deposit dates</th><th>Account</th><th class="r">Reconciled</th><th class="r">Deposits</th><th class="r">Bank</th><th class="r">Left unreconciled</th><th>Remark</th><th></th></tr></thead><tbody id="hRows"></tbody></table></div></div>
  </div>
</div>

<div class="mm" id="mm"><div class="mm-box"><div class="mm-hd"><b id="mmT">Match manually</b><button onclick="mmClose()">&times;</button></div><div class="mm-bd" id="mmB"></div></div></div>

<script>
document.body.appendChild(document.getElementById('mm'));
let D=null, ACC={}, MAN={};   // MAN[depId] = {bank:[ids], rows:[...], remark}
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const N=v=>Number(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
const ad=(d,n)=>{ const x=new Date(d+'T00:00:00'); x.setDate(x.getDate()+n); return x.toISOString().slice(0,10); };
async function post(o){ const fd=new FormData(); Object.entries(o).forEach(([k,v])=>fd.append(k,v)); const r=await fetch(location.pathname,{method:'POST',body:fd}); const t=await r.text(); try{ return JSON.parse(t); }catch(e){ return {ok:false,msg:'Server error: '+t.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim().slice(0,200)}; } }
try{ const s=JSON.parse(localStorage.getItem('borF')||'{}'); ['fFrom','fTo','fAcc','fTol'].forEach(k=>{ if(s[k]!==undefined) document.getElementById(k).value=s[k]; }); }catch(e){}
if(!fFrom.value){ const d=new Date(); fTo.value=d.toISOString().slice(0,10); d.setDate(d.getDate()-14); fFrom.value=d.toISOString().slice(0,10); }
function tab(t){ document.querySelectorAll('.bor-tab').forEach(b=>b.classList.toggle('on',b.dataset.t===t)); tRec.style.display=t==='rec'?'':'none'; tHis.style.display=t==='his'?'':'none'; if(t==='his') hist(); }
async function load(){ try{ localStorage.setItem('borF',JSON.stringify({fFrom:fFrom.value,fTo:fTo.value,fAcc:fAcc.value,fTol:fTol.value})); }catch(e){}
  mRows.innerHTML='<tr><td colspan="9" class="empty">Loading…</td></tr>'; MAN={};
  const d=await post({ajax:'load',from:fFrom.value,to:fTo.value,acc:fAcc.value,tol:fTol.value});
  if(!d.ok){ mRows.innerHTML='<tr><td colspan="9" class="empty" style="color:#b91c1c">'+esc(d.msg)+'</td></tr>'; return; } D=d; ACC=d.accounts||{}; draw(); }
function draw(){ const M=D.matches, U=D.unDeps, B=D.unBank, a=id=>esc(ACC[id]||('#'+id));
  mRows.innerHTML=M.map((m,i)=>'<tr><td><input type="checkbox" class="mChk" data-i="'+i+'" checked onchange="tally()"></td><td class="nw"><b>'+esc(m.dep.date)+'</b></td><td class="nw">'+esc(m.dep.dd||'')+'</td><td>'+esc(m.dep.emp)+'</td><td style="font-size:11.5px">'+a(m.dep.acc)+'</td><td class="r"><b>'+N(m.dep.amount)+'</b></td><td class="nw">'+esc(m.bank.date)+(m.days?' <span class="pill d">'+m.days+'d</span>':'')+'</td><td class="ds">'+esc(m.bank.desc)+'</td><td class="r"><b>'+N(m.bank.amount)+'</b></td></tr>').join('')||'<tr><td colspan="9" class="empty">No automatic matches.</td></tr>';
  mCnt.textContent=M.length+' match(es)';
  uRows.innerHTML=U.map(u=>{ const m=MAN[u.id]; return '<tr><td class="nw"><b>'+esc(u.date)+'</b></td><td class="nw">'+esc(u.dd||'')+'</td><td>'+esc(u.emp)+(u.remark?'<div style="font-size:11px;color:#6b7280">'+esc(u.remark)+'</div>':'')+'</td><td style="font-size:11.5px">'+a(u.acc)+'</td><td class="r"><b>'+N(u.amount)+'</b></td>'
    +'<td>'+(m?'<span class="pill m">MANUAL</span> '+m.rows.length+' bank line(s) · Rs. '+N(m.sum)+(Math.abs(u.amount-m.sum)>0.005?' · <b style="color:#b45309">diff '+N(u.amount-m.sum)+'</b>':'')+(m.remark?'<div style="font-size:11px;color:#6b7280">'+esc(m.remark)+'</div>':''):'<span style="color:#9ca3af">—</span>')+'</td>'
    +'<td class="nw"><button class="bb g" style="padding:4px 10px;font-size:12px" onclick="mmOpen('+u.id+')"><i class="fa-solid fa-hand-pointer"></i> '+(m?'Change':'Match manually')+'</button>'+(m?' <button class="bb r" style="padding:4px 8px;font-size:12px" onclick="delete MAN['+u.id+'];draw()">×</button>':'')+'</td></tr>'; }).join('')||'<tr><td colspan="7" class="empty">Every BO deposit in the range found its bank line.</td></tr>';
  uCnt.textContent=U.length+' deposit(s) · Rs. '+N(U.reduce((t,x)=>t+x.amount,0));
  const usedMan=new Set(Object.values(MAN).flatMap(m=>m.bank));
  bRows.innerHTML=B.map(b=>'<tr'+(usedMan.has(b.id)?' style="background:#f5f3ff"':'')+'><td class="nw">'+esc(b.date)+'</td><td style="font-size:11.5px">'+a(b.acc)+'</td><td class="ds">'+esc(b.desc)+(usedMan.has(b.id)?' <span class="pill m">picked</span>':'')+'</td><td class="r">'+N(b.amount)+'</td></tr>').join('')||'<tr><td colspan="4" class="empty">No open bank credits.</td></tr>';
  bCnt.textContent=B.length+' line(s) · Rs. '+N(B.reduce((t,x)=>t+x.amount,0));
  kp.innerHTML='<div><b>'+M.length+'</b><span>Auto matches</span></div><div><b style="color:#b45309">'+U.length+'</b><span>Deposits not found</span></div><div><b>'+B.length+'</b><span>Open bank credits</span></div><div><b style="color:#15803d">'+D.already.n+'</b><span>Already reconciled (Rs. '+N(D.already.amt)+')</span></div>';
  tally(); }
function tally(){ if(!D) return; const ch=[...document.querySelectorAll('.mChk:checked')].map(c=>D.matches[+c.dataset.i]); const man=Object.values(MAN);
  const n=ch.length+man.length, amt=ch.reduce((t,m)=>t+m.dep.amount,0)+man.reduce((t,m)=>t+m.dep,0);
  sumTxt.textContent=n?('To save: '+n+' reconciliation(s) · Rs. '+N(amt)):''; saveBtn.disabled=!n; }
async function mmOpen(id){ const u=D.unDeps.find(x=>x.id===id); if(!u) return; mm.classList.add('open'); mmT.textContent='Match manually — BO deposit '+u.date+' · Rs. '+N(u.amount);
  const from=ad(u.date,-7), to=ad(u.date,14);
  mmB.innerHTML='<div class="bor-bar" style="margin:0 0 8px"><label>Bank dates from<input type="date" id="mmF" value="'+from+'"></label><label>to<input type="date" id="mmTo" value="'+to+'"></label><button class="bb g" onclick="mmLoad('+id+')">Show</button><span style="font-size:12px;color:#6b7280">'+esc(ACC[u.acc]||'')+'</span></div><div class="tw" style="max-height:380px"><table class="t"><thead><tr><th></th><th>Date</th><th>Description</th><th class="r">Credit</th></tr></thead><tbody id="mmRows"><tr><td colspan="4" class="empty">Loading…</td></tr></tbody></table></div>'
    +'<div class="foot" style="margin-top:8px"><span id="mmSum" style="font-weight:700;font-size:13px"></span><input id="mmRem" placeholder="Remark (needed when the amounts differ)" style="flex:1;min-width:200px;border:1.5px solid #e5e7eb;border-radius:8px;padding:7px 10px;font-size:13px" value="'+esc(MAN[id]?.remark||'')+'"><button class="bb p" onclick="mmUse('+id+')"><i class="fa-solid fa-check"></i> Use these</button></div>';
  mmLoad(id); }
let MMR=[];
async function mmLoad(id){ const u=D.unDeps.find(x=>x.id===id); const d=await post({ajax:'bank_open',acc:u.acc,from:mmF.value,to:mmTo.value}); if(!d.ok){ mmRows.innerHTML='<tr><td colspan="4" class="empty">'+esc(d.msg)+'</td></tr>'; return; }
  const auto=new Set([...document.querySelectorAll('.mChk:checked')].map(c=>D.matches[+c.dataset.i].bank.id)); const other=new Set(Object.entries(MAN).filter(([k])=>+k!==id).flatMap(([,m])=>m.bank)); const mine=new Set(MAN[id]?.bank||[]);
  MMR=d.rows.filter(b=>!auto.has(b.id)&&!other.has(b.id));
  mmRows.innerHTML=MMR.map(b=>'<tr'+(Math.abs(b.amount-u.amount)<0.005?' style="background:#ecfdf5"':'')+'><td><input type="checkbox" class="mmC" value="'+b.id+'" '+(mine.has(b.id)?'checked':'')+' onchange="mmTally('+id+')"></td><td class="nw">'+esc(b.date)+'</td><td class="ds">'+esc(b.desc)+'</td><td class="r"><b>'+N(b.amount)+'</b></td></tr>').join('')||'<tr><td colspan="4" class="empty">No open bank credits of this account in these dates.</td></tr>'; mmTally(id); }
function mmTally(id){ const u=D.unDeps.find(x=>x.id===id); const s=[...document.querySelectorAll('.mmC:checked')].reduce((t,c)=>t+(MMR.find(b=>b.id==c.value)?.amount||0),0); const df=u.amount-s;
  mmSum.innerHTML='Picked Rs. '+N(s)+' · deposit Rs. '+N(u.amount)+(Math.abs(df)>0.005?' · <span style="color:#b45309">difference '+N(df)+'</span>':' · <span style="color:#15803d">equal</span>'); }
function mmUse(id){ const u=D.unDeps.find(x=>x.id===id); const ids=[...document.querySelectorAll('.mmC:checked')].map(c=>+c.value); if(!ids.length){ alert('Tick at least one bank credit.'); return; }
  const rows=MMR.filter(b=>ids.includes(b.id)), sum=rows.reduce((t,b)=>t+b.amount,0), rem=mmRem.value.trim();
  if(Math.abs(u.amount-sum)>0.005&&!rem){ alert('The amounts differ by '+N(u.amount-sum)+' — give a remark.'); mmRem.focus(); return; }
  MAN[id]={bank:ids,rows,sum,dep:u.amount,remark:rem}; mmClose(); draw(); }
function mmClose(){ mm.classList.remove('open'); }
async function saveBatch(){ const items=[...document.querySelectorAll('.mChk:checked')].map(c=>{ const m=D.matches[+c.dataset.i]; return {dep:m.dep.id,bank:[m.bank.id],method:'auto'}; })
    .concat(Object.entries(MAN).map(([k,m])=>({dep:+k,bank:m.bank,method:'manual',remark:m.remark})));
  if(!items.length) return; if(!confirm('Save '+items.length+' reconciliation(s) as one batch?')) return;
  const left=D.unDeps.filter(u=>!MAN[u.id]); saveBtn.disabled=true;
  const d=await post({ajax:'save_batch',items:JSON.stringify(items),from:fFrom.value,to:fTo.value,acc:fAcc.value,tol:fTol.value,remark:bRemark.value,unrec:JSON.stringify({n:left.length,amt:left.reduce((t,x)=>t+x.amount,0)})});
  saveBtn.disabled=false; if(!d.ok){ alert(d.msg); return; } alert('Saved batch '+d.batch+' — '+d.n+' reconciliation(s).'); bRemark.value=''; load(); }
async function hist(){ hRows.innerHTML='<tr><td colspan="10" class="empty">Loading…</td></tr>'; const d=await post({ajax:'batches'}); if(!d.ok){ hRows.innerHTML='<tr><td colspan="10" class="empty">'+esc(d.msg)+'</td></tr>'; return; } const A=d.accounts||{};
  hRows.innerHTML=d.rows.map(b=>'<tr><td><b>'+esc(b.batch_no)+'</b></td><td class="nw">'+esc(b.created_at)+'<div style="font-size:11px;color:#6b7280">'+esc(b.created_by||'')+'</div></td><td class="nw">'+esc(b.date_from||'')+' → '+esc(b.date_to||'')+(+b.tolerance_days?' (±'+b.tolerance_days+'d)':'')+'</td><td style="font-size:11.5px">'+esc(b.bank_account_id?(A[b.bank_account_id]||'#'+b.bank_account_id):'All')+'</td>'
    +'<td class="r">'+b.rec_count+'</td><td class="r">'+N(b.rec_dep_amount)+'</td><td class="r">'+N(b.rec_bank_amount)+'</td><td class="r">'+b.unrec_dep_count+' · '+N(b.unrec_dep_amount)+'</td><td style="font-size:12px">'+esc(b.remark||'')+'</td>'
    +'<td class="nw"><button class="bb g" style="padding:4px 10px;font-size:12px" onclick="bView('+b.id+',\''+esc(b.batch_no)+'\')"><i class="fa-solid fa-eye"></i> View</button> <button class="bb r" style="padding:4px 10px;font-size:12px" data-perm="delete" onclick="bDel('+b.id+',\''+esc(b.batch_no)+'\')"><i class="fa-solid fa-trash"></i></button></td></tr>').join('')||'<tr><td colspan="10" class="empty">No batches yet.</td></tr>'; }
async function bView(id,no){ mm.classList.add('open'); mmT.textContent='Batch '+no; mmB.innerHTML='Loading…'; const d=await post({ajax:'batch_view',id});
  mmB.innerHTML='<div class="tw" style="max-height:70vh"><table class="t"><thead><tr><th>Method</th><th>Deposit date</th><th class="r">Deposit</th><th class="r">Bank</th><th class="r">Diff</th><th>Bank line(s)</th><th>Remark</th></tr></thead><tbody>'+(d.rows||[]).map(r=>'<tr><td><span class="pill '+(r.method==='manual'?'m':'a')+'">'+esc(r.method)+'</span></td><td class="nw">'+esc(r.deposit_date)+'</td><td class="r">'+N(r.deposit_amount)+'</td><td class="r">'+N(r.bank_amount)+'</td><td class="r">'+(Math.abs(r.difference)>0.005?'<b style="color:#b45309">'+N(r.difference)+'</b>':'')+'</td><td class="ds" style="white-space:pre-line">'+esc(r.bank||'')+'</td><td style="font-size:12px">'+esc(r.remark||'')+'</td></tr>').join('')+'</tbody></table></div>'; }
async function bDel(id,no){ if(!confirm('Delete batch '+no+'? Its deposits and bank lines become unreconciled again.')) return; const d=await post({ajax:'delete_batch',id}); if(!d.ok){ alert(d.msg); return; } hist(); }
document.addEventListener('keydown',e=>{ if(e.key==='Escape') mmClose(); });
</script>
<?php include 'footer.php'; ?>
