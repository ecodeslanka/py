<?php
/**
 * bo_deposit_bank_recon.php — BO Cash Deposit ↔ Bank Statement reconciliation
 *  • Deposits = "Deposited to Bank" of BO Cash Deposit (SR) (bo_bank_deposits) and BO Cash Deposit (DP) (bo_bank_deposits_dp), by deposit date.
 *  • Auto: a BO cash deposit ↔ ONE bank credit of the same bank account with the SAME AMOUNT on the
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
    bor_q($conn, "CREATE TABLE IF NOT EXISTS bo_bank_recon_settings (
        id INT NOT NULL PRIMARY KEY, bank_account_id INT NULL, bank_type VARCHAR(10) NULL, tolerance_days TINYINT NOT NULL DEFAULT 1,
        acc_only TINYINT(1) NOT NULL DEFAULT 1, updated_by VARCHAR(100) NULL, updated_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    bor_q($conn, "CREATE TABLE IF NOT EXISTS bo_bank_recon_batch_unrec (   /* what was NOT reconciled when the batch was saved */
        id INT AUTO_INCREMENT PRIMARY KEY, batch_id INT NOT NULL, side VARCHAR(10) NOT NULL, deposit_id INT NULL, bank_txn_id INT NULL,
        ref_date DATE NULL, delivery_date DATE NULL, employee VARCHAR(191) NULL, description TEXT NULL, amount DECIMAL(15,2) NOT NULL DEFAULT 0, INDEX (batch_id, side)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach (['bank_type' => "VARCHAR(10) NULL", 'dd_from' => "DATE NULL", 'dd_to' => "DATE NULL", 'unrec_bank_count' => "INT NOT NULL DEFAULT 0", 'unrec_bank_amount' => "DECIMAL(14,2) NOT NULL DEFAULT 0"] as $c => $d)
        if (!bor_col($conn, 'bo_bank_recon_batches', $c)) bor_q($conn, "ALTER TABLE bo_bank_recon_batches ADD COLUMN `$c` $d");
    foreach (['recon_status' => "VARCHAR(20) NULL", 'recon_source' => "VARCHAR(30) NULL", 'recon_category' => "VARCHAR(50) NULL", 'recon_ref_id' => "INT NULL",
              'recon_remark' => "TEXT NULL", 'recon_by' => "VARCHAR(100) NULL", 'recon_at' => "DATETIME NULL"] as $c => $d)
        if (!bor_col($conn, 'bank_statement_transactions', $c)) bor_q($conn, "ALTER TABLE bank_statement_transactions ADD COLUMN `$c` $d");
    foreach (['bo_bank_deposits', 'bo_bank_deposits_dp'] as $tb) { $t = bor_q($conn, "SHOW TABLES LIKE '$tb'"); if (!$t || !mysqli_num_rows($t)) continue;
        foreach (['bank_recon_id' => "INT NULL", 'bank_recon_remark' => "TEXT NULL"] as $c => $d) if (!bor_col($conn, $tb, $c)) bor_q($conn, "ALTER TABLE `$tb` ADD COLUMN `$c` $d"); }
    /* deposits come from two pages: source 'sr' = BO Cash Deposit (SR), 'dp' = BO Cash Deposit (DP) */
    if (!bor_col($conn, 'bo_bank_recon', 'source')) { bor_q($conn, "ALTER TABLE bo_bank_recon ADD COLUMN source VARCHAR(5) NOT NULL DEFAULT 'sr' AFTER method");
        bor_q($conn, "ALTER TABLE bo_bank_recon DROP INDEX uq_dep"); bor_q($conn, "ALTER TABLE bo_bank_recon ADD UNIQUE KEY uq_src_dep (source, deposit_id)"); }
    if (!bor_col($conn, 'bo_bank_recon_batch_unrec', 'source')) bor_q($conn, "ALTER TABLE bo_bank_recon_batch_unrec ADD COLUMN source VARCHAR(5) NULL AFTER side");
}
bor_ensure($conn);
function bor_can($a) { if (!function_exists('rbac_can') && file_exists(__DIR__ . '/rbac.php')) @include_once __DIR__ . '/rbac.php'; return function_exists('rbac_can') ? rbac_can($a, 'bo_deposit_bank_recon') : true; }
function bor_accounts($conn) {
    $o = []; $r = bor_q($conn, "SELECT cba.id, CONCAT(COALESCE(NULLIF(b.bank_name,''), cba.bank_code, ''), ' / ', COALESCE(NULLIF(bb.branch_name,''), cba.branch_code, ''), ' (', cba.account_no, ')') AS label
        FROM company_bank_accounts cba LEFT JOIN banks b ON b.bank_code = cba.bank_code LEFT JOIN bank_branches bb ON bb.bank_code = cba.bank_code AND bb.branch_code = cba.branch_code ORDER BY cba.id");
    while ($r && ($x = mysqli_fetch_assoc($r))) $o[(int)$x['id']] = $x['label']; return $o;
}
$BOR_SRC = ['sr' => ['bo_bank_deposits', 'BO Cash Deposit (SR)'], 'dp' => ['bo_bank_deposits_dp', 'BO Cash Deposit (DP)']];
function bor_src_ok($conn, $k) { global $BOR_SRC; if (!isset($BOR_SRC[$k])) return false; $t = bor_q($conn, "SHOW TABLES LIKE '" . $BOR_SRC[$k][0] . "'"); return $t && mysqli_num_rows($t) > 0; }
$BOR_TYPES = ['BOC' => 'BOC', 'NDB' => 'NDB', 'SAMPATH' => 'Sampath'];
function bor_type_label($bt) { global $BOR_TYPES; $bt = strtoupper((string)$bt); return $bt === '' ? 'All types' : ($BOR_TYPES[$bt] ?? $bt); }
/* bank accounts that have bank statements uploaded, with their statement types and last statement date */
function bor_statement_accounts($conn) {
    $acc = []; $names = bor_accounts($conn);
    $r = bor_q($conn, "SELECT account_id, UPPER(bank_type) bt, MAX(statement_date) last_stmt, COUNT(*) uploads FROM bank_statement_uploads GROUP BY account_id, UPPER(bank_type)");
    while ($r && ($x = mysqli_fetch_assoc($r))) { $id = (int)$x['account_id']; if ($id <= 0) continue;
        if (!isset($acc[$id])) $acc[$id] = ['label' => $names[$id] ?? ('Account #' . $id), 'types' => [], 'last' => '', 'uploads' => 0];
        $acc[$id]['types'][$x['bt']] = ['last' => $x['last_stmt'], 'uploads' => (int)$x['uploads']]; $acc[$id]['uploads'] += (int)$x['uploads'];
        if ($x['last_stmt'] > $acc[$id]['last']) $acc[$id]['last'] = $x['last_stmt']; }
    uasort($acc, fn($a, $b) => strcasecmp($a['label'], $b['label'])); return $acc;
}
function bor_settings($conn) {
    $s = ['account' => 0, 'type' => '', 'tol' => 1, 'acc_only' => true, 'by' => '', 'at' => ''];
    $r = bor_q($conn, "SELECT * FROM bo_bank_recon_settings WHERE id = 1"); if ($r && ($x = mysqli_fetch_assoc($r))) $s = ['account' => (int)$x['bank_account_id'], 'type' => strtoupper((string)$x['bank_type']),
        'tol' => max(0, min(7, (int)$x['tolerance_days'])), 'acc_only' => (int)$x['acc_only'] === 1, 'by' => (string)$x['updated_by'], 'at' => (string)$x['updated_at']];
    $A = bor_statement_accounts($conn); if (!isset($A[$s['account']])) $s['account'] = 0;
    if ($s['type'] !== '' && (!$s['account'] || !isset($A[$s['account']]['types'][$s['type']]))) $s['type'] = '';
    return $s;
}
/* open bank credits (not reconciled by anything) of these accounts in the date range; the same line uploaded twice counts once */
function bor_bank_open($conn, array $accs, $from, $to, $type = '') {
    $o = []; if (!$accs) return $o; $seen = [];
    $r = bor_q($conn, "SELECT t.id, u.account_id acc, t.transaction_date d, CONCAT_WS(' ', t.description, t.reference) descr, t.credit, t.balance
                       FROM bank_statement_transactions t JOIN bank_statement_uploads u ON u.id = t.upload_id
                       WHERE u.account_id IN (" . bor_ids($accs) . ")" . ($type !== '' ? " AND UPPER(u.bank_type) = '" . mysqli_real_escape_string($conn, $type) . "'" : "") . " AND t.transaction_date BETWEEN '$from' AND '$to' AND t.credit > 0
                         AND (t.recon_status IS NULL OR t.recon_status = '')" . (bor_col($conn, 'bank_statement_transactions', 'reconciled') ? " AND COALESCE(t.reconciled,0) = 0" : "") . "
                       ORDER BY t.transaction_date, t.id LIMIT 5000");
    while ($r && ($x = mysqli_fetch_assoc($r))) { $k = $x['acc'] . '|' . $x['d'] . '|' . trim(preg_replace('/\s+/', ' ', $x['descr'])) . '|' . $x['credit'] . '|' . $x['balance']; if (isset($seen[$k])) continue; $seen[$k] = 1;
        $o[] = ['id' => (int)$x['id'], 'acc' => (int)$x['acc'], 'date' => $x['d'], 'desc' => trim(preg_replace('/\s+/', ' ', $x['descr'])), 'amount' => round((float)$x['credit'], 2)]; }
    return $o;
}

/* ═════════ AJAX ═════════ */
if (isset($_REQUEST['ajax'])) {
    $act = $_REQUEST['ajax'];
    if ($act === 'save_settings') {
        if (!bor_can('edit') && !bor_can('create')) bor_json(['ok' => false, 'msg' => 'Permission blocked: your role cannot change the settings.']);
        $A = bor_statement_accounts($conn); $acc = (int)($_POST['account_id'] ?? 0); $bt = strtoupper(trim((string)($_POST['bank_type'] ?? '')));
        $tol = max(0, min(7, (int)($_POST['tol'] ?? 1))); $only = !empty($_POST['acc_only']) ? 1 : 0;
        if (!isset($A[$acc])) bor_json(['ok' => false, 'msg' => 'Select a bank account that has bank statements uploaded.']);
        if ($bt !== '' && !isset($A[$acc]['types'][$bt])) bor_json(['ok' => false, 'msg' => 'No ' . bor_type_label($bt) . ' statements are uploaded for this account. Pick another type or "All types".']);
        $u = mysqli_real_escape_string($conn, bor_user()); $now = date('Y-m-d H:i:s');
        $ok = bor_q($conn, "INSERT INTO bo_bank_recon_settings (id, bank_account_id, bank_type, tolerance_days, acc_only, updated_by, updated_at) VALUES (1, $acc, " . ($bt !== '' ? "'" . mysqli_real_escape_string($conn, $bt) . "'" : "NULL") . ", $tol, $only, '$u', '$now')
             ON DUPLICATE KEY UPDATE bank_account_id = VALUES(bank_account_id), bank_type = VALUES(bank_type), tolerance_days = VALUES(tolerance_days), acc_only = VALUES(acc_only), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)");
        bor_json($ok ? ['ok' => true, 'msg' => 'Settings saved.'] : ['ok' => false, 'msg' => 'Could not save the settings.']);
    }
    if ($act === 'load') {
        $S = bor_settings($conn); if (!$S['account']) bor_json(['ok' => false, 'msg' => 'Set the bank account in Settings first.']);
        $acc = $S['account']; $tol = $S['tol']; $bt = $S['type'];
        global $BOR_SRC;
        $dpf = bor_date($_POST['dp_from'] ?? ''); $dpt = bor_date($_POST['dp_to'] ?? ''); $srcSel = $_POST['src'] ?? 'all';
        if ($dpf !== '' && $dpt === '') $dpt = $dpf; if ($dpt !== '' && $dpf === '') $dpf = $dpt;
        if ($dpf === '') bor_json(['ok' => false, 'msg' => 'Pick the deposit dates.']);
        if ($dpt < $dpf) bor_json(['ok' => false, 'msg' => 'The "to" date is before the "from" date.']);
        if ((strtotime($dpt) - strtotime($dpf)) / 86400 > 93) bor_json(['ok' => false, 'msg' => 'Pick 3 months or less.']);
        $deps = []; $rec = 0; $recAmt = 0;
        $empName = bor_col($conn, 'employees', 'name_with_initials') ? "COALESCE(NULLIF(e.name_with_initials,''), e.employee_full_name)" : "e.employee_full_name";
        foreach ($BOR_SRC as $sk => [$tb, $lbl]) {
            if (($srcSel !== 'all' && $srcSel !== $sk) || !bor_src_ok($conn, $sk)) continue;
            $dd = bor_col($conn, $tb, 'delivery_date') ? 'bd.delivery_date' : 'NULL';
            $r = bor_q($conn, "SELECT bd.id, $dd delivery_date, bd.deposit_date, bd.bank_account_id acc, bd.amount, bd.remark, bd.bank_recon_id, $empName emp
                               FROM `$tb` bd LEFT JOIN employees e ON e.id = bd.employee_id
                               WHERE bd.deposit_date BETWEEN '$dpf' AND '$dpt'" . ($S['acc_only'] ? " AND bd.bank_account_id = $acc" : "") . " ORDER BY bd.deposit_date, bd.id");
            if (!$r) bor_json(['ok' => false, 'msg' => "Could not read $lbl: " . mysqli_error($conn)]);
            while ($x = mysqli_fetch_assoc($r)) { if ((int)$x['bank_recon_id'] > 0) { $rec++; $recAmt += (float)$x['amount']; continue; }
                $deps[] = ['id' => $sk . '-' . (int)$x['id'], 'rid' => (int)$x['id'], 'src' => $sk, 'srcl' => $lbl, 'dd' => $x['delivery_date'], 'date' => $x['deposit_date'], 'acc' => (int)$x['acc'],
                           'amount' => round((float)$x['amount'], 2), 'emp' => $x['emp'] ?? '', 'remark' => $x['remark'] ?? '']; }
        }
        usort($deps, fn($a, $b) => strcmp($a['date'], $b['date']) ?: strcmp($a['id'], $b['id']));
        /* bank credits of the Settings account around the deposit dates */
        $from = $dpf; $to = $dpt;
        $bf = date('Y-m-d', strtotime("$from -$tol days")); $bto = date('Y-m-d', strtotime("$to +$tol days"));
        $bank = bor_bank_open($conn, [$acc], $bf, $bto, $bt);
        /* matching: same account + same amount, date within tolerance — closest date first, each line once */
        $cand = [];
        foreach ($deps as $i => $d) foreach ($bank as $j => $b)
            if ((!$S['acc_only'] || $b['acc'] === $d['acc']) && abs($b['amount'] - $d['amount']) < 0.005) { $dd = abs((strtotime($b['date']) - strtotime($d['date'])) / 86400); if ($dd <= $tol) $cand[] = [$dd, $i, $j]; }
        usort($cand, fn($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1] ?: $a[2] <=> $b[2]);
        $usedD = []; $usedB = []; $matches = [];
        foreach ($cand as [$dd, $i, $j]) { if (isset($usedD[$i]) || isset($usedB[$j])) continue; $usedD[$i] = $usedB[$j] = 1; $matches[] = ['dep' => $deps[$i], 'bank' => $bank[$j], 'days' => (int)$dd]; }
        $unD = array_values(array_filter($deps, fn($d, $i) => !isset($usedD[$i]), ARRAY_FILTER_USE_BOTH));
        $unB = array_values(array_filter($bank, fn($b, $j) => !isset($usedB[$j]) && $b['date'] >= $from && $b['date'] <= $to, ARRAY_FILTER_USE_BOTH));
        bor_json(['ok' => true, 'matches' => $matches, 'unDeps' => $unD, 'unBank' => $unB, 'already' => ['n' => $rec, 'amt' => round($recAmt, 2)], 'accounts' => bor_accounts($conn),
                  'range' => [$from, $to], 'settings' => ['account' => $acc, 'type' => $bt, 'tol' => $tol, 'acc_only' => $S['acc_only']]]);
    }
    if ($act === 'bank_open') {   // for a manual match: open credits of one account around a date
        $S = bor_settings($conn); $from = bor_date($_POST['from'] ?? ''); $to = bor_date($_POST['to'] ?? '');
        if (!$S['account']) bor_json(['ok' => false, 'msg' => 'Set the bank account in Settings first.']);
        if ($from === '' || $to === '') bor_json(['ok' => false, 'msg' => 'Pick the dates.']);
        bor_json(['ok' => true, 'rows' => bor_bank_open($conn, [$S['account']], $from, $to, $S['type'])]);
    }
    if ($act === 'save_batch') {
        if (!bor_can('create') && !bor_can('edit')) bor_json(['ok' => false, 'msg' => 'Permission blocked: your role cannot save reconciliations here.']);
        $items = json_decode($_POST['items'] ?? '[]', true); if (!is_array($items) || !$items) bor_json(['ok' => false, 'msg' => 'Nothing ticked to save.']);
        $S = bor_settings($conn); if (!$S['account']) bor_json(['ok' => false, 'msg' => 'Set the bank account in Settings first.']);
        global $BOR_SRC; $from = bor_date($_POST['dp_from'] ?? ''); $to = bor_date($_POST['dp_to'] ?? ''); $ddf = ''; $ddt = '';
        $tol = $S['tol']; $acc = $S['account'];
        $e = fn($v) => mysqli_real_escape_string($conn, (string)$v); $u = $e(bor_user()); $now = date('Y-m-d H:i:s'); $bremark = trim($_POST['remark'] ?? '');
        mysqli_begin_transaction($conn);
        try {
            if (!bor_q($conn, "INSERT INTO bo_bank_recon_batches (date_from, date_to, dd_from, dd_to, tolerance_days, bank_account_id, bank_type, remark, created_by, created_at) VALUES (" . ($from ? "'$from'" : "NULL") . "," . ($to ? "'$to'" : "NULL") . "," . ($ddf ? "'$ddf'" : "NULL") . "," . ($ddt ? "'$ddt'" : "NULL") . ",$tol,$acc," . ($S['type'] !== '' ? "'" . $e($S['type']) . "'" : "NULL") . ",'" . $e($bremark) . "','$u','$now')")) throw new Exception(mysqli_error($conn));
            $bid = (int)mysqli_insert_id($conn); $bno = 'RB-BO-' . str_pad((string)$bid, 5, '0', STR_PAD_LEFT);
            $n = 0; $da = 0; $ba = 0;
            foreach ($items as $it) {
                [$sk, $did] = array_pad(explode('-', (string)($it['dep'] ?? ''), 2), 2, 0); $did = (int)$did; if (!isset($BOR_SRC[$sk])) throw new Exception('Unknown deposit.'); $dtb = $BOR_SRC[$sk][0]; $dlbl = $BOR_SRC[$sk][1]; $txs = array_values(array_unique(array_filter(array_map('intval', (array)($it['bank'] ?? [])))));
                $method = ($it['method'] ?? '') === 'manual' ? 'manual' : 'auto'; $rem = trim((string)($it['remark'] ?? ''));
                if (!$did || !$txs) throw new Exception('A line has no deposit or no bank line.');
                $r = bor_q($conn, "SELECT id, deposit_date, bank_account_id, amount, bank_recon_id FROM `$dtb` WHERE id = $did FOR UPDATE"); $d = $r ? mysqli_fetch_assoc($r) : null;
                if (!$d) throw new Exception("$dlbl #$did not found."); if ((int)$d['bank_recon_id'] > 0) throw new Exception("$dlbl #$did is already reconciled — reload.");
                $r = bor_q($conn, "SELECT t.id, t.transaction_date, CONCAT_WS(' ', t.description, t.reference) descr, t.credit, t.recon_status, u.account_id FROM bank_statement_transactions t JOIN bank_statement_uploads u ON u.id = t.upload_id WHERE t.id IN (" . bor_ids($txs) . ") FOR UPDATE");
                $T = []; while ($r && ($x = mysqli_fetch_assoc($r))) $T[] = $x;
                if (count($T) !== count($txs)) throw new Exception('A bank line was not found — reload.');
                $sum = 0; foreach ($T as $t) { if (trim((string)$t['recon_status']) !== '') throw new Exception('Bank line of ' . $t['transaction_date'] . ' (' . number_format($t['credit'], 2) . ') is already reconciled — reload.');
                    if ((int)$t['account_id'] !== $acc) throw new Exception('A bank line is not from the Settings bank account.');
                    if ($S['acc_only'] && (int)$t['account_id'] !== (int)$d['bank_account_id']) throw new Exception("BO deposit #$did was made to another bank account."); $sum += (float)$t['credit']; }
                $diff = round((float)$d['amount'] - $sum, 2);
                if ($method === 'auto' && abs($diff) > 0.005) throw new Exception('Auto match amounts differ — reload.');
                if (abs($diff) > 0.005 && $rem === '') throw new Exception('Give a remark for the difference of ' . number_format($diff, 2) . " (deposit #$did).");
                if (!bor_q($conn, "INSERT INTO bo_bank_recon (batch_id, method, source, deposit_id, bank_account_id, deposit_date, deposit_amount, bank_amount, difference, remark, created_by, created_at)
                     VALUES ($bid, '$method', '$sk', $did, " . (int)$d['bank_account_id'] . ", " . ($d['deposit_date'] ? "'" . $e($d['deposit_date']) . "'" : "NULL") . ", " . (float)$d['amount'] . ", $sum, $diff, '" . $e($rem) . "', '$u', '$now')")) throw new Exception(mysqli_error($conn));
                $rid = (int)mysqli_insert_id($conn);
                $txt = "$bno · $dlbl #$did (" . ($d['deposit_date'] ?: '') . ") Rs. " . number_format($d['amount'], 2) . ($method === 'manual' ? ' · manual' : '') . ($rem !== '' ? ' · ' . $rem : '');
                foreach ($T as $t) {
                    if (!bor_q($conn, "INSERT INTO bo_bank_recon_bank (recon_id, bank_txn_id, transaction_date, description, credit) VALUES ($rid, " . (int)$t['id'] . ", '" . $e($t['transaction_date']) . "', '" . $e($t['descr']) . "', " . (float)$t['credit'] . ")")) throw new Exception(mysqli_error($conn));
                    bor_q($conn, "UPDATE bank_statement_transactions SET recon_status = 'reconciled', recon_source = '" . BOR_SOURCE . "', recon_category = '" . BOR_CATEGORY . "', recon_ref_id = $rid, recon_remark = '" . $e($txt) . "', recon_by = '$u', recon_at = '$now' WHERE id = " . (int)$t['id']);
                }
                bor_q($conn, "UPDATE `$dtb` SET bank_recon_id = $rid, bank_recon_remark = '" . $e($txt) . "' WHERE id = $did");
                $n++; $da += (float)$d['amount']; $ba += $sum;
            }
            /* snapshot of what was NOT reconciled (sent by the page: deposits not found, open bank credits not picked) */
            $un = json_decode($_POST['unrec'] ?? '{}', true) ?: []; $udn = 0; $uda = 0; $ubn = 0; $uba = 0;
            foreach ((array)($un['deps'] ?? []) as $x) { $udn++; $uda += (float)($x['amount'] ?? 0);
                bor_q($conn, "INSERT INTO bo_bank_recon_batch_unrec (batch_id, side, source, deposit_id, ref_date, delivery_date, employee, amount) VALUES ($bid, 'deposit', '" . (isset($BOR_SRC[$x['src'] ?? '']) ? $x['src'] : '') . "', " . (int)($x['rid'] ?? 0) . ", " . (bor_date($x['date'] ?? '') ? "'" . $x['date'] . "'" : "NULL") . ", " . (bor_date($x['dd'] ?? '') ? "'" . $x['dd'] . "'" : "NULL") . ", '" . $e(mb_substr((string)($x['emp'] ?? ''), 0, 190)) . "', " . (float)($x['amount'] ?? 0) . ")"); }
            foreach ((array)($un['bank'] ?? []) as $x) { $ubn++; $uba += (float)($x['amount'] ?? 0);
                bor_q($conn, "INSERT INTO bo_bank_recon_batch_unrec (batch_id, side, bank_txn_id, ref_date, description, amount) VALUES ($bid, 'bank', " . (int)($x['id'] ?? 0) . ", " . (bor_date($x['date'] ?? '') ? "'" . $x['date'] . "'" : "NULL") . ", '" . $e($x['desc'] ?? '') . "', " . (float)($x['amount'] ?? 0) . ")"); }
            bor_q($conn, "UPDATE bo_bank_recon_batches SET batch_no = '$bno', rec_count = $n, rec_dep_amount = $da, rec_bank_amount = $ba, unrec_dep_count = $udn, unrec_dep_amount = $uda, unrec_bank_count = $ubn, unrec_bank_amount = $uba WHERE id = $bid");
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
        $r = bor_q($conn, "SELECT r.*, IF(r.source = 'dp', 'DP', 'SR') src, GROUP_CONCAT(CONCAT(b.transaction_date, ' · ', FORMAT(b.credit, 2), ' · ', LEFT(b.description, 80)) SEPARATOR '\n') bank FROM bo_bank_recon r LEFT JOIN bo_bank_recon_bank b ON b.recon_id = r.id WHERE r.batch_id = $bid GROUP BY r.id ORDER BY r.deposit_date, r.id");
        while ($r && ($x = mysqli_fetch_assoc($r))) $o[] = $x;
        $un = []; $r = bor_q($conn, "SELECT * FROM bo_bank_recon_batch_unrec WHERE batch_id = $bid ORDER BY side DESC, ref_date, id"); while ($r && ($x = mysqli_fetch_assoc($r))) $un[] = $x;
        bor_json(['ok' => true, 'rows' => $o, 'unrec' => $un]);
    }
    if ($act === 'delete_batch') {
        if (!bor_can('delete')) bor_json(['ok' => false, 'msg' => 'Permission blocked: your role cannot delete reconciliation batches.']);
        $bid = (int)($_POST['id'] ?? 0); $rids = []; $r = bor_q($conn, "SELECT id FROM bo_bank_recon WHERE batch_id = $bid"); while ($r && ($x = mysqli_fetch_row($r))) $rids[] = (int)$x[0];
        mysqli_begin_transaction($conn);
        if ($rids) { $in = bor_ids($rids);
            bor_q($conn, "UPDATE bank_statement_transactions SET recon_status = NULL, recon_source = NULL, recon_category = NULL, recon_ref_id = NULL, recon_remark = NULL, recon_by = NULL, recon_at = NULL WHERE recon_source = '" . BOR_SOURCE . "' AND recon_ref_id IN ($in)");
            foreach (['sr' => 'bo_bank_deposits', 'dp' => 'bo_bank_deposits_dp'] as $sk => $tb) if (bor_src_ok($conn, $sk)) bor_q($conn, "UPDATE `$tb` SET bank_recon_id = NULL, bank_recon_remark = NULL WHERE bank_recon_id IN ($in) AND id IN (SELECT deposit_id FROM bo_bank_recon WHERE id IN ($in) AND source = '$sk')");
            bor_q($conn, "DELETE FROM bo_bank_recon_bank WHERE recon_id IN ($in)"); bor_q($conn, "DELETE FROM bo_bank_recon WHERE id IN ($in)"); }
        bor_q($conn, "DELETE FROM bo_bank_recon_batch_unrec WHERE batch_id = $bid"); bor_q($conn, "DELETE FROM bo_bank_recon_batches WHERE id = $bid"); mysqli_commit($conn);
        bor_json(['ok' => true, 'n' => count($rids)]);
    }
    bor_json(['ok' => false, 'msg' => 'Unknown action']);
}

$accounts = bor_accounts($conn); $stmt_accounts = bor_statement_accounts($conn); $SET = bor_settings($conn);

$tab = in_array($_GET['tab'] ?? '', ['run', 'saved', 'settings'], true) ? $_GET['tab'] : 'run';
if (!$SET['account'] && $tab === 'run' && !isset($_GET['tab'])) $tab = 'settings';
include 'header.php';
?>
<style>
/* same look as Deposit Reconciliation (cc_deposit_dp_bank_recon.php) */
.dbr { font-family: 'Inter', system-ui, sans-serif; font-size: 13px; color: #111; padding: 22px 26px 70px; }
.dbr *, .dbr *::before, .dbr *::after { box-sizing: border-box; }
.dbr [hidden] { display: none !important; }
.dbr h1 { margin: 0; font-size: 21px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
.dbr h1 i { color: #0f766e; }
.dbr .sub { margin: 4px 0 16px; color: #666; }
.dbr h2 { font-size: 15px; margin: 26px 0 10px; display: flex; align-items: center; gap: 8px; }
.dbr h2 .count { background: #f3f4f6; color: #444; border-radius: 999px; padding: 2px 9px; font-size: 12px; }
.dbr .btn { display: inline-flex; align-items: center; gap: 6px; height: 36px; padding: 0 16px; border: 1px solid transparent; border-radius: 8px; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; white-space: nowrap; }
.dbr .btn-dark { background: #0f766e; color: #fff; } .dbr .btn-dark:hover { background: #115e59; }
.dbr .btn-light { background: #fff; color: #333; border-color: #e5e5e5; } .dbr .btn-light:hover { background: #f5f5f5; }
.dbr .btn-danger { background: #fff; color: #b91c1c; border-color: #fecaca; } .dbr .btn-danger:hover { background: #fef2f2; }
.dbr .btn-sm { height: 30px; padding: 0 11px; font-size: 12.5px; }
.dbr .btn:disabled { opacity: .4; cursor: not-allowed; }
.dbr .btn:focus-visible, .dbr input:focus-visible, .dbr select:focus-visible, .dbr a:focus-visible { outline: 2px solid #0f766e; outline-offset: 2px; }
.dbr .tabs { display: flex; gap: 4px; border-bottom: 1px solid #e5e5e5; margin-bottom: 16px; }
.dbr .tabs a { padding: 10px 14px; color: #555; text-decoration: none; font-weight: 600; border-bottom: 2px solid transparent; margin-bottom: -1px; display: inline-flex; gap: 7px; align-items: center; }
.dbr .tabs a.on { color: #111; border-bottom-color: #0f766e; }
.dbr .filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; align-items: end; background: #fff; border: 1px solid #e5e5e5; border-radius: 12px; padding: 16px; }
.dbr label.f { display: block; font-size: 12px; font-weight: 600; color: #444; margin-bottom: 5px; }
.dbr input[type=date], .dbr input[type=text], .dbr input[type=search], .dbr select { width: 100%; height: 36px; padding: 0 10px; border: 1px solid #ddd; border-radius: 8px; font: inherit; background: #fff; }
.dbr .chk { display: flex; gap: 7px; align-items: center; font-weight: 500; cursor: pointer; height: 36px; }
.dbr .chk input { width: 16px; height: 16px; accent-color: #0f766e; }
.dbr .alert { padding: 11px 14px; border-radius: 10px; margin: 14px 0; }
.dbr .alert.err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.dbr .alert.warn { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
.dbr .alert.info { background: #f0fdfa; color: #134e4a; border: 1px solid #99f6e4; }
.dbr .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin: 18px 0 6px; }
.dbr .stat { background: #fff; border: 1px solid #e5e5e5; border-radius: 12px; padding: 13px 15px; }
.dbr .stat .lbl { color: #666; font-size: 12px; }
.dbr .stat .val { font-size: 19px; font-weight: 700; margin-top: 3px; font-variant-numeric: tabular-nums; }
.dbr .stat .cnt { color: #777; font-size: 12px; margin-top: 2px; }
.dbr .stat.g .val { color: #15803d; } .dbr .stat.a .val { color: #b45309; } .dbr .stat.r .val { color: #b91c1c; }
.dbr .card { background: #fff; border: 1px solid #e5e5e5; border-radius: 12px; overflow-x: auto; }
.dbr table { width: 100%; border-collapse: collapse; }
.dbr th { white-space: nowrap; padding: 10px 12px; text-align: left; font-size: 12px; font-weight: 600; color: #555; background: #fafafa; border-bottom: 1px solid #e5e5e5; }
.dbr td { padding: 10px 12px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
.dbr tbody tr:last-child td { border-bottom: 0; }
.dbr tr.staged td { background: #eff6ff; }
.dbr tr.used td { opacity: .45; }
.dbr .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.dbr th.sep, .dbr td.sep { border-right: 2px solid #e5e5e5; }
.dbr .muted { color: #777; font-size: 12px; }
.dbr .rowmsg { font-size: 12px; margin-top: 3px; } .dbr .rowmsg.info { color: #1d4ed8; }
.dbr .desc { color: #444; font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 11.5px; display: block; }
.dbr .badge { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 600; white-space: nowrap; }
.dbr .b-ok { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.dbr .b-diff { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
.dbr .b-no { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.dbr .b-man { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
.dbr .b-auto { background: #f5f5f5; color: #555; border: 1px solid #e5e5e5; }
.dbr .diff-pos { color: #15803d; font-weight: 700; } .dbr .diff-neg { color: #b91c1c; font-weight: 700; }
.dbr .name { font-weight: 600; }
.dbr .savebar { position: sticky; bottom: 0; z-index: 5; margin-top: 18px; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; background: #fff; border: 1px solid #e5e5e5; border-radius: 12px; padding: 12px 14px; box-shadow: 0 -6px 20px rgba(0,0,0,.06); }
.dbr .savebar .grow { flex: 1 1 240px; }
.dbr .empty { padding: 26px 16px; text-align: center; color: #666; }
.dbr .setbar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; background: #f0fdfa; border: 1px solid #99f6e4; color: #134e4a; border-radius: 12px; padding: 10px 14px; margin-bottom: 12px; }
.dbr .setbar .pill { background: #fff; border: 1px solid #99f6e4; border-radius: 999px; padding: 2px 10px; font-size: 12px; font-weight: 600; }
.dbr .setbar .setlink { margin-left: auto; color: #0f766e; font-weight: 600; text-decoration: none; }
.dbr .setcard { background: #fff; border: 1px solid #e5e5e5; border-radius: 12px; padding: 18px; max-width: 860px; }
.dbr .setcard h3 { margin: 0 0 4px; font-size: 16px; }
.dbr .setgrid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.dbr .setgrid .wide { grid-column: 1 / -1; }
.dbr .modal { position: fixed; inset: 0; z-index: 2147483000; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(0,0,0,.5); }
.dbr .modal[hidden] { display: none; }
.dbr .dialog { width: 100%; max-width: 1040px; max-height: 92vh; display: flex; flex-direction: column; background: #fff; border-radius: 14px; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
.dbr .dhead { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-bottom: 1px solid #e5e5e5; }
.dbr .dhead h3 { margin: 0; font-size: 16px; }
.dbr .xbtn { border: 0; background: none; font-size: 18px; cursor: pointer; color: #555; width: 34px; height: 34px; border-radius: 8px; }
.dbr .xbtn:hover { background: #f3f4f6; }
.dbr .dbody { padding: 14px 18px; overflow: auto; flex: 1; }
.dbr .mr-dep { display: flex; flex-wrap: wrap; gap: 8px 22px; background: #f0fdfa; border: 1px solid #99f6e4; border-radius: 10px; padding: 10px 14px; margin-bottom: 12px; }
.dbr .mr-dep div { display: flex; flex-direction: column; }
.dbr .mr-dep b { font-size: 14px; }
.dbr .mr-filter { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; margin-bottom: 10px; }
.dbr .mr-filter .grow { flex: 1 1 200px; }
.dbr .mr-list { max-height: 46vh; overflow: auto; }
.dbr .mr-list th { position: sticky; top: 0; z-index: 1; }
.dbr .mr-list tr { cursor: pointer; }
.dbr .mr-list tr.pick td { background: #f0fdfa; }
.dbr .mr-list tr.same td:nth-child(2) { color: #0f766e; font-weight: 700; }
.dbr .mr-list tr.eq td.num { color: #15803d; font-weight: 700; }
.dbr .dfoot { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; padding: 12px 18px; border-top: 1px solid #e5e5e5; background: #fafafa; border-radius: 0 0 14px 14px; }
.dbr .tally { display: flex; gap: 18px; margin-right: auto; }
.dbr .tally div { display: flex; flex-direction: column; }
.dbr .tally b { font-size: 15px; font-variant-numeric: tabular-nums; }
.dbr .tally .ok { color: #15803d; } .dbr .tally .bad { color: #b91c1c; }
.dbr .toast { position: fixed; right: 20px; bottom: 80px; z-index: 2147483600; padding: 12px 16px; border-radius: 10px; color: #fff; font-weight: 600; box-shadow: 0 10px 30px rgba(0,0,0,.2); opacity: 0; transform: translateY(10px); transition: .2s; pointer-events: none; max-width: 440px; }
.dbr .toast.show { opacity: 1; transform: none; } .dbr .toast.ok { background: #15803d; } .dbr .toast.err { background: #b91c1c; }
@media (max-width: 760px) { .dbr { padding: 14px 12px 70px; } .dbr .setgrid { grid-template-columns: 1fr; } }
@media print { .dbr .tabs, .dbr .noprint, .dbr .toast { display: none !important; } .dbr { padding: 0; } }
</style>

<div class="dbr">
    <h1><i class="fa-solid fa-scale-balanced"></i> BO Cash Deposit ↔ Bank Reconciliation</h1>
    <p class="sub">Match the "Deposited to Bank" entries of BO Cash Deposit (SR) and (DP) to bank statement credits by deposit date and amount, and save them as batches.</p>

    <div class="tabs">
        <a href="?tab=run" class="<?= $tab === 'run' ? 'on' : '' ?>"><i class="fa-solid fa-wand-magic-sparkles"></i> Reconcile</a>
        <a href="?tab=saved" class="<?= $tab === 'saved' ? 'on' : '' ?>"><i class="fa-solid fa-layer-group"></i> Saved batches</a>
        <a href="?tab=settings" class="<?= $tab === 'settings' ? 'on' : '' ?>"><i class="fa-solid fa-gear"></i> Settings</a>
    </div>

<?php if ($tab === 'run'): ?>
    <?php if (!$SET['account']): ?>
        <div class="alert warn">No bank account is set for reconcile yet. <a href="?tab=settings">Open Settings</a> and choose the bank account.</div>
    <?php else: ?>
        <div class="setbar">
            <span><i class="fa-solid fa-building-columns"></i> <b><?= htmlspecialchars($stmt_accounts[$SET['account']]['label'] ?? '') ?></b></span>
            <span class="pill"><?= htmlspecialchars(bor_type_label($SET['type'])) ?></span>
            <span class="pill"><?= $SET['tol'] === 0 ? 'Same date only' : '± ' . $SET['tol'] . ' day' . ($SET['tol'] > 1 ? 's' : '') ?></span>
            <span class="pill"><?= $SET['acc_only'] ? 'Only deposits to this account' : 'Deposits to any account' ?></span>
            <a href="?tab=settings" class="setlink"><i class="fa-solid fa-gear"></i> Change</a>
        </div>
    <?php endif; ?>

    <div class="filters">
        <div><label class="f" for="fFrom">Deposit date from</label><input type="date" id="fFrom"></div>
        <div><label class="f" for="fTo">Deposit date to</label><input type="date" id="fTo"></div>
        <div><label class="f" for="fSrc">Deposits from</label><select id="fSrc"><option value="all">All — BO Cash Deposit (SR) + (DP)</option><option value="sr">BO Cash Deposit (SR)</option><option value="dp">BO Cash Deposit (DP)</option></select></div>
        <div><button type="button" class="btn btn-dark" style="width:100%;justify-content:center;" onclick="load()" <?= $SET['account'] ? '' : 'disabled' ?>><i class="fa-solid fa-play"></i> Reconcile</button></div>
    </div>

    <div id="runOut"></div>

    <div class="savebar noprint" id="saveBar" hidden>
        <input type="text" class="grow" id="bRemark" placeholder="Batch remark (optional)" maxlength="500">
        <span class="muted" id="sumTxt"></span>
        <button type="button" class="btn btn-dark" id="saveBtn" onclick="saveBatch()" data-perm="create"><i class="fa-solid fa-floppy-disk"></i> Save batch</button>
    </div>

<?php elseif ($tab === 'saved'): ?>
    <div class="card"><table>
        <thead><tr><th>Batch</th><th>Saved</th><th>Deposit dates</th><th>Bank account</th><th class="num">Reconciled</th><th class="num">Deposit amount</th><th class="num sep">Bank amount</th><th class="num">Not reconciled</th><th>Remark</th><th></th></tr></thead>
        <tbody id="hRows"><tr><td colspan="10" class="empty">Loading…</td></tr></tbody>
    </table></div>

<?php else: ?>
    <div class="setcard">
        <h3><i class="fa-solid fa-gear" style="color:#0f766e"></i> Reconcile settings</h3>
        <p class="muted" style="margin:0 0 14px">Bank statement credits of this account are matched with the BO deposits.<?= $SET['by'] ? ' Last saved by <b>' . htmlspecialchars($SET['by']) . '</b> on ' . htmlspecialchars($SET['at']) . '.' : '' ?></p>
        <?php if (!$stmt_accounts): ?><div class="alert warn">No bank statements are uploaded yet — upload them on Bank Statements first.</div><?php endif; ?>
        <div class="setgrid">
            <div class="wide"><label class="f" for="setAcc">Bank account (accounts with bank statements)</label>
                <select id="setAcc" onchange="setTypes()"><option value="">— Select —</option>
                <?php foreach ($stmt_accounts as $id => $a): ?><option value="<?= $id ?>" data-types='<?= htmlspecialchars(json_encode(array_keys($a['types'])), ENT_QUOTES) ?>' <?= $id === $SET['account'] ? 'selected' : '' ?>><?= htmlspecialchars($a['label']) ?> — last statement <?= htmlspecialchars($a['last']) ?> (<?= (int)$a['uploads'] ?> upload<?= $a['uploads'] == 1 ? '' : 's' ?>)</option><?php endforeach; ?></select></div>
            <div><label class="f" for="setType">Statement type</label><select id="setType" data-cur="<?= htmlspecialchars($SET['type']) ?>"></select></div>
            <div><label class="f" for="setTol">Date tolerance</label><select id="setTol"><?php foreach ([0 => 'Same date only', 1 => '± 1 day', 2 => '± 2 days', 3 => '± 3 days', 5 => '± 5 days', 7 => '± 7 days'] as $v => $l): ?><option value="<?= $v ?>" <?= $v === (int)$SET['tol'] ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="wide"><label class="chk"><input type="checkbox" id="setOnly" <?= $SET['acc_only'] ? 'checked' : '' ?>> Only BO deposits made to this bank account</label></div>
            <div class="wide"><button type="button" class="btn btn-dark" onclick="saveSettings()" data-perm="edit"><i class="fa-solid fa-floppy-disk"></i> Save settings</button></div>
        </div>
    </div>
<?php endif; ?>

</div>
<!-- the window and the toast sit on <body> (moved by the script) so the page header never covers them -->
<div class="dbr" id="dbrPortal" style="padding:0">
    <div class="modal" id="mm" hidden><div class="dialog">
        <div class="dhead"><h3 id="mmT">Match manually</h3><button type="button" class="xbtn" onclick="mmClose()" aria-label="Close">&times;</button></div>
        <div class="dbody" id="mmB"></div>
        <div class="dfoot" id="mmF" hidden></div>
    </div></div>
    <div class="toast" id="toast"></div>
</div>

<script>
const TAB=<?= json_encode($tab) ?>;
let D=null, ACC={}, MAN={};   // MAN[depKey] = {bank:[ids], rows, sum, dep, remark}
const TYPES={'BOC':'BOC','NDB':'NDB','SAMPATH':'Sampath'};
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const N=v=>Number(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
const FD=d=>{ if(!d) return '—'; const x=new Date(d+'T00:00:00'); return isNaN(x)?d:x.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}); };
const ad=(d,n)=>{ const x=new Date(d+'T00:00:00'); x.setDate(x.getDate()+n); return x.toISOString().slice(0,10); };
const diffHtml=v=>Math.abs(v)<0.005?'<span class="badge b-ok">0.00</span>':'<span class="'+(v>0?'diff-pos':'diff-neg')+'">'+(v>0?'+':'')+N(v)+'</span>';
function toast(m,ok){ const t=document.getElementById('toast'); t.textContent=m; t.className='toast show '+(ok?'ok':'err'); clearTimeout(t._h); t._h=setTimeout(()=>t.className='toast',3500); }
async function post(o){ const fd=new FormData(); Object.entries(o).forEach(([k,v])=>fd.append(k,v)); const r=await fetch(location.pathname,{method:'POST',body:fd}); const t=await r.text(); try{ return JSON.parse(t); }catch(e){ return {ok:false,msg:'Server error: '+t.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim().slice(0,200)}; } }
document.body.appendChild(document.getElementById('dbrPortal'));

/* ── settings ── */
function setTypes(){ const s=document.getElementById('setAcc'); if(!s) return; const o=s.selectedOptions[0], t=o&&o.dataset.types?JSON.parse(o.dataset.types):[]; const st=document.getElementById('setType'); const cur=st.dataset.cur||st.value;
  st.innerHTML='<option value="">All types</option>'+t.map(x=>'<option value="'+esc(x)+'"'+(x===cur?' selected':'')+'>'+esc(TYPES[x]||x)+'</option>').join(''); st.dataset.cur=''; }
async function saveSettings(){ const d=await post({ajax:'save_settings',account_id:setAcc.value,bank_type:setType.value,tol:setTol.value,acc_only:setOnly.checked?1:0}); toast(d.msg||(d.ok?'Saved':'Error'),d.ok); if(d.ok) setTimeout(()=>location.href='?tab=run',700); }

/* ── reconcile ── */
if(TAB==='run'){
  try{ const s=JSON.parse(localStorage.getItem('borF')||'{}'); ['fFrom','fTo','fSrc'].forEach(k=>{ if(s[k]!==undefined) document.getElementById(k).value=s[k]; }); }catch(e){}
  if(!fFrom.value){ const d=new Date(); fTo.value=d.toISOString().slice(0,10); d.setDate(d.getDate()-14); fFrom.value=d.toISOString().slice(0,10); }
}
async function load(){ try{ localStorage.setItem('borF',JSON.stringify({fFrom:fFrom.value,fTo:fTo.value,fSrc:fSrc.value})); }catch(e){}
  runOut.innerHTML='<div class="alert info">Matching…</div>'; saveBar.hidden=true; MAN={};
  const d=await post({ajax:'load',dp_from:fFrom.value,dp_to:fTo.value,src:fSrc.value});
  if(!d.ok){ runOut.innerHTML='<div class="alert err">'+esc(d.msg)+'</div>'; return; } D=d; ACC=d.accounts||{}; draw(); }
function srcBadge(o){ return '<span class="badge '+(o.src==='dp'?'b-man':'b-auto')+'" title="'+esc(o.srcl)+'">'+o.src.toUpperCase()+'</span>'; }
function draw(){ const M=D.matches, U=D.unDeps, B=D.unBank, S=D.settings, sum=(a,f)=>a.reduce((t,x)=>t+f(x),0);
  const depOpen=sum(M,m=>m.dep.amount)+sum(U,u=>u.amount), bankOpen=sum(M,m=>m.bank.amount)+sum(B,b=>b.amount);
  let h='<div class="alert info">Deposits with deposit date <b>'+FD(D.range[0])+' – '+FD(D.range[1])+'</b> checked against <b>'+esc(S.type?(TYPES[S.type]||S.type):'All types')+'</b> statement credits of <b>'+esc(ACC[S.account]||'')+'</b>'+(S.tol?' (± '+S.tol+' day'+(S.tol>1?'s':'')+')':'')+'.'
    +(D.already.n?' Already reconciled and left out: '+D.already.n+' deposit(s) (Rs '+N(D.already.amt)+').':'')+'</div>';
  h+='<div class="stats"><div class="stat"><div class="lbl">Deposits (open)</div><div class="val">'+N(depOpen)+'</div><div class="cnt">'+(M.length+U.length)+' deposit(s)</div></div>'
    +'<div class="stat"><div class="lbl">Bank credits (open)</div><div class="val">'+N(bankOpen)+'</div><div class="cnt">'+(M.length+B.length)+' transaction(s)</div></div>'
    +'<div class="stat g"><div class="lbl">Tallied</div><div class="val">'+M.length+'</div><div class="cnt">Rs '+N(sum(M,m=>m.dep.amount))+'</div></div>'
    +'<div class="stat r"><div class="lbl">Not in bank</div><div class="val">'+U.length+'</div><div class="cnt">Deposit side only</div></div>'
    +'<div class="stat r"><div class="lbl">Not in deposits</div><div class="val">'+B.length+'</div><div class="cnt">Bank side only</div></div></div>';
  h+='<h2><i class="fa-solid fa-circle-check" style="color:#15803d"></i> Reconciled (tallied one-to-one) <span class="count">'+M.length+'</span></h2><div class="card">'
    +(M.length?'<table><thead><tr><th style="width:34px"><input type="checkbox" id="mAll" checked onchange="document.querySelectorAll(\'.mChk\').forEach(c=>c.checked=this.checked);tally()" aria-label="Select all"></th><th>Employee</th><th>Deposit</th><th>Deposit date</th><th class="num sep">Deposit amount</th><th>Txn date</th><th>Bank transaction</th><th class="num">Bank amount</th><th class="num">Difference</th></tr></thead><tbody>'
      +M.map((m,i)=>'<tr><td><input type="checkbox" class="mChk" data-i="'+i+'" checked onchange="tally()" aria-label="Select"></td><td class="name">'+esc(m.dep.emp||'—')+(m.dep.remark?'<div class="muted">'+esc(m.dep.remark)+'</div>':'')+'</td><td class="nw">'+srcBadge(m.dep)+' <span class="muted">#'+m.dep.rid+'</span>'+(m.dep.dd?'<div class="muted">delivery '+FD(m.dep.dd)+'</div>':'')+'</td><td style="white-space:nowrap">'+FD(m.dep.date)+'</td><td class="num sep"><b>'+N(m.dep.amount)+'</b></td>'
        +'<td style="white-space:nowrap">'+FD(m.bank.date)+(m.days?'<div class="muted">±'+m.days+' day(s)</div>':'')+'</td><td style="min-width:220px"><span class="desc">'+esc(m.bank.desc)+'</span></td><td class="num"><b>'+N(m.bank.amount)+'</b></td><td class="num">'+diffHtml(m.bank.amount-m.dep.amount)+'</td></tr>').join('')+'</tbody></table>'
      :'<div class="empty">No deposit tallied with a bank credit.</div>')+'</div>';
  h+='<h2><i class="fa-solid fa-circle-xmark" style="color:#b91c1c"></i> Not in bank (deposit side only) <span class="count">'+U.length+'</span></h2><p class="muted" style="margin:-4px 0 8px">Use <b>Reconcile manually</b> to pick one or more open bank credits for a deposit. Rows left as they are are saved in the batch as not reconciled.</p><div class="card">'
    +(U.length?'<table><thead><tr><th>Employee</th><th>Deposit</th><th>Deposit date</th><th class="num sep">Deposit amount</th><th>Manual reconcile</th><th></th></tr></thead><tbody>'
      +U.map(u=>{ const m=MAN[u.id]; return '<tr'+(m?' class="staged"':'')+'><td class="name">'+esc(u.emp||'—')+(u.remark?'<div class="muted">'+esc(u.remark)+'</div>':'')+(m?'<div class="rowmsg info">Staged — saved with the batch</div>':'')+'</td><td style="white-space:nowrap">'+srcBadge(u)+' <span class="muted">#'+u.rid+'</span>'+(u.dd?'<div class="muted">delivery '+FD(u.dd)+'</div>':'')+'</td><td style="white-space:nowrap">'+FD(u.date)+'</td><td class="num sep"><b>'+N(u.amount)+'</b></td>'
        +'<td>'+(m?'<span class="badge b-man">Manual</span> '+m.rows.length+' bank credit(s) · Rs '+N(m.sum)+' · '+diffHtml(m.sum-u.amount)+(m.remark?'<div class="muted">'+esc(m.remark)+'</div>':''):'<span class="badge b-no">Not reconciled</span>')+'</td>'
        +'<td style="white-space:nowrap"><button type="button" class="btn btn-light btn-sm" onclick="mmOpen(\''+u.id+'\')"><i class="fa-solid fa-hand-pointer"></i> '+(m?'Change':'Reconcile manually')+'</button>'+(m?' <button type="button" class="btn btn-danger btn-sm" onclick="delete MAN[\''+u.id+'\'];draw()" title="Remove the manual pick"><i class="fa-solid fa-xmark"></i></button>':'')+'</td></tr>'; }).join('')+'</tbody></table>'
      :'<div class="empty">Every deposit found its bank credit.</div>')+'</div>';
  const usedMan=new Set(Object.values(MAN).flatMap(m=>m.bank));
  h+='<h2><i class="fa-solid fa-circle-xmark" style="color:#b91c1c"></i> Not in deposits (bank side only) <span class="count">'+B.length+'</span></h2><div class="card">'
    +(B.length?'<table><thead><tr><th>Txn date</th><th>Bank transaction</th><th class="num">Bank amount</th><th></th></tr></thead><tbody>'
      +B.map(b=>'<tr'+(usedMan.has(b.id)?' class="used"':'')+'><td style="white-space:nowrap">'+FD(b.date)+'</td><td><span class="desc">'+esc(b.desc)+'</span></td><td class="num"><b>'+N(b.amount)+'</b></td><td>'+(usedMan.has(b.id)?'<span class="badge b-man">Picked</span>':'')+'</td></tr>').join('')+'</tbody></table>'
      :'<div class="empty">No open bank credits in this range.</div>')+'</div>';
  runOut.innerHTML=h; saveBar.hidden=false; tally(); }
function tally(){ if(!D) return; const ch=[...document.querySelectorAll('.mChk:checked')].map(c=>D.matches[+c.dataset.i]); const man=Object.values(MAN);
  const n=ch.length+man.length, amt=ch.reduce((t,m)=>t+m.dep.amount,0)+man.reduce((t,m)=>t+m.dep,0);
  sumTxt.textContent=n?(n+' reconciliation(s) to save · Rs '+N(amt)):'Nothing selected'; saveBtn.disabled=!n; }
/* ── manual reconcile window ── */
let MMR=[];
function mmOpen(id){ const u=D.unDeps.find(x=>x.id===id); if(!u) return; mm.hidden=false; mmT.textContent='Reconcile manually — '+(u.emp||'BO deposit');
  mmB.innerHTML='<div class="mr-dep"><div><span class="muted">Deposit</span><b>'+srcBadge(u)+' #'+u.rid+'</b></div><div><span class="muted">Deposit date</span><b>'+FD(u.date)+'</b></div><div><span class="muted">Amount</span><b>Rs '+N(u.amount)+'</b></div><div><span class="muted">Bank</span><b>'+esc(ACC[D.settings.account]||'')+'</b></div></div>'
    +'<div class="mr-filter"><div><label class="f">Bank date from</label><input type="date" id="mrF" value="'+ad(u.date,-7)+'"></div><div><label class="f">to</label><input type="date" id="mrT" value="'+ad(u.date,14)+'"></div><div><button type="button" class="btn btn-light" onclick="mmLoad(\''+id+'\')"><i class="fa-solid fa-magnifying-glass"></i> Show</button></div><div class="grow"><label class="f">Remark (needed when the amounts differ)</label><input type="text" id="mrRem" value="'+esc(MAN[id]?.remark||'')+'"></div></div>'
    +'<div class="card mr-list"><table><thead><tr><th style="width:34px"></th><th>Txn date</th><th>Bank transaction</th><th class="num">Bank amount</th></tr></thead><tbody id="mrRows"><tr><td colspan="4" class="empty">Loading…</td></tr></tbody></table></div>';
  mmF.hidden=false; mmF.innerHTML='<div class="tally" id="mrTally"></div><button type="button" class="btn btn-light" onclick="mmClose()">Cancel</button><button type="button" class="btn btn-dark" onclick="mmUse(\''+id+'\')"><i class="fa-solid fa-check"></i> Use these</button>';
  mmLoad(id); }
async function mmLoad(id){ const u=D.unDeps.find(x=>x.id===id); const d=await post({ajax:'bank_open',from:mrF.value,to:mrT.value}); if(!d.ok){ mrRows.innerHTML='<tr><td colspan="4" class="empty">'+esc(d.msg)+'</td></tr>'; return; }
  const auto=new Set([...document.querySelectorAll('.mChk:checked')].map(c=>D.matches[+c.dataset.i].bank.id)); const other=new Set(Object.entries(MAN).filter(([k])=>k!==id).flatMap(([,m])=>m.bank)); const mine=new Set(MAN[id]?.bank||[]);
  MMR=d.rows.filter(b=>!auto.has(b.id)&&!other.has(b.id));
  mrRows.innerHTML=MMR.map(b=>'<tr class="'+(mine.has(b.id)?'pick ':'')+(b.date===u.date?'same ':'')+(Math.abs(b.amount-u.amount)<0.005?'eq':'')+'" onclick="if(event.target.tagName!==\'INPUT\'){const c=this.querySelector(\'input\');c.checked=!c.checked;} this.classList.toggle(\'pick\',this.querySelector(\'input\').checked); mmTally(\''+id+'\')"><td><input type="checkbox" class="mmC" value="'+b.id+'" '+(mine.has(b.id)?'checked':'')+'></td><td style="white-space:nowrap">'+FD(b.date)+'</td><td><span class="desc">'+esc(b.desc)+'</span></td><td class="num">'+N(b.amount)+'</td></tr>').join('')||'<tr><td colspan="4" class="empty">No open bank credits in these dates.</td></tr>'; mmTally(id); }
function mmTally(id){ const u=D.unDeps.find(x=>x.id===id); const s=[...document.querySelectorAll('.mmC:checked')].reduce((t,c)=>t+(MMR.find(b=>b.id==c.value)?.amount||0),0); const df=s-u.amount;
  mrTally.innerHTML='<div><span class="muted">Deposit</span><b>'+N(u.amount)+'</b></div><div><span class="muted">Picked</span><b>'+N(s)+'</b></div><div><span class="muted">Difference</span><b class="'+(Math.abs(df)<0.005?'ok':'bad')+'">'+N(df)+'</b></div>'; }
function mmUse(id){ const u=D.unDeps.find(x=>x.id===id); const ids=[...document.querySelectorAll('.mmC:checked')].map(c=>+c.value); if(!ids.length){ toast('Tick at least one bank credit.'); return; }
  const rows=MMR.filter(b=>ids.includes(b.id)), sum=rows.reduce((t,b)=>t+b.amount,0), rem=mrRem.value.trim();
  if(Math.abs(u.amount-sum)>0.005&&!rem){ toast('The amounts differ by '+N(sum-u.amount)+' — give a remark.'); mrRem.focus(); return; }
  MAN[id]={bank:ids,rows,sum,dep:u.amount,remark:rem}; mmClose(); draw(); }
function mmClose(){ mm.hidden=true; mmF.hidden=true; }
async function saveBatch(){ const items=[...document.querySelectorAll('.mChk:checked')].map(c=>{ const m=D.matches[+c.dataset.i]; return {dep:m.dep.id,bank:[m.bank.id],method:'auto'}; })
    .concat(Object.entries(MAN).map(([k,m])=>({dep:k,bank:m.bank,method:'manual',remark:m.remark})));
  if(!items.length) return; if(!confirm('Save '+items.length+' reconciliation(s) as one batch?')) return;
  const usedB=new Set(items.flatMap(i=>i.bank)); const ticked=new Set(items.map(i=>i.dep));
  const leftD=D.unDeps.filter(u=>!MAN[u.id]).concat(D.matches.filter(m=>!ticked.has(m.dep.id)).map(m=>m.dep)), leftB=D.unBank.filter(b=>!usedB.has(b.id)).concat(D.matches.filter(m=>!ticked.has(m.dep.id)).map(m=>m.bank)); saveBtn.disabled=true;
  const d=await post({ajax:'save_batch',items:JSON.stringify(items),dp_from:fFrom.value,dp_to:fTo.value,remark:bRemark.value,unrec:JSON.stringify({deps:leftD,bank:leftB})});
  saveBtn.disabled=false; if(!d.ok){ toast(d.msg); return; } toast('Saved batch '+d.batch+' — '+d.n+' reconciliation(s).',true); bRemark.value=''; load(); }
/* ── saved batches ── */
async function hist(){ const d=await post({ajax:'batches'}); if(!d.ok){ hRows.innerHTML='<tr><td colspan="10" class="empty">'+esc(d.msg)+'</td></tr>'; return; } const A=d.accounts||{};
  hRows.innerHTML=d.rows.map(b=>'<tr><td class="name">'+esc(b.batch_no)+'</td><td style="white-space:nowrap">'+esc(b.created_at)+'<div class="muted">'+esc(b.created_by||'')+'</div></td><td style="white-space:nowrap">'+FD(b.date_from)+' – '+FD(b.date_to)+(+b.tolerance_days?'<div class="muted">± '+b.tolerance_days+' day(s)</div>':'')+'</td><td>'+esc(b.bank_account_id?(A[b.bank_account_id]||'#'+b.bank_account_id):'')+(b.bank_type?' <span class="badge b-auto">'+esc(TYPES[b.bank_type]||b.bank_type)+'</span>':'')+'</td>'
    +'<td class="num">'+b.rec_count+'</td><td class="num">'+N(b.rec_dep_amount)+'</td><td class="num sep">'+N(b.rec_bank_amount)+'</td><td class="num">'+b.unrec_dep_count+' dep · '+N(b.unrec_dep_amount)+'<div class="muted">'+(b.unrec_bank_count||0)+' bank · '+N(b.unrec_bank_amount||0)+'</div></td><td>'+esc(b.remark||'')+'</td>'
    +'<td style="white-space:nowrap"><button type="button" class="btn btn-light btn-sm" onclick="bView('+b.id+',\''+esc(b.batch_no)+'\')"><i class="fa-solid fa-eye"></i> View</button> <button type="button" class="btn btn-danger btn-sm" data-perm="delete" onclick="bDel('+b.id+',\''+esc(b.batch_no)+'\')"><i class="fa-solid fa-trash"></i></button></td></tr>').join('')||'<tr><td colspan="10" class="empty">No batches saved yet.</td></tr>'; }
async function bView(id,no){ mm.hidden=false; mmF.hidden=true; mmT.textContent='Batch '+no; mmB.innerHTML='<div class="empty">Loading…</div>'; const d=await post({ajax:'batch_view',id});
  const U=d.unrec||[], dep=U.filter(x=>x.side==='deposit'), bk=U.filter(x=>x.side==='bank');
  mmB.innerHTML='<h2 style="margin-top:0"><i class="fa-solid fa-circle-check" style="color:#15803d"></i> Reconciled <span class="count">'+(d.rows||[]).length+'</span></h2><div class="card"><table><thead><tr><th>Method</th><th>Deposit</th><th>Deposit date</th><th class="num sep">Deposit amount</th><th>Bank transaction(s)</th><th class="num">Bank amount</th><th class="num">Difference</th><th>Remark</th></tr></thead><tbody>'
    +(d.rows||[]).map(r=>'<tr><td><span class="badge '+(r.method==='manual'?'b-man':'b-auto')+'">'+esc(r.method==='manual'?'Manual':'Auto')+'</span></td><td><span class="badge '+(r.src==='DP'?'b-man':'b-auto')+'">'+esc(r.src||'SR')+'</span> <span class="muted">#'+r.deposit_id+'</span></td><td style="white-space:nowrap">'+FD(r.deposit_date)+'</td><td class="num sep">'+N(r.deposit_amount)+'</td><td><span class="desc" style="white-space:pre-line">'+esc(r.bank||'')+'</span></td><td class="num">'+N(r.bank_amount)+'</td><td class="num">'+diffHtml(r.bank_amount-r.deposit_amount)+'</td><td>'+esc(r.remark||'')+'</td></tr>').join('')+'</tbody></table></div>'
    +(U.length?'<h2><i class="fa-solid fa-circle-xmark" style="color:#b91c1c"></i> Not reconciled when saved <span class="count">'+U.length+'</span></h2><p class="muted" style="margin:-4px 0 8px">Deposits '+dep.length+' (Rs '+N(dep.reduce((t,x)=>t+ +x.amount,0))+') · bank credits '+bk.length+' (Rs '+N(bk.reduce((t,x)=>t+ +x.amount,0))+')</p><div class="card"><table><thead><tr><th>Side</th><th>Date</th><th>Employee / bank transaction</th><th class="num">Amount</th></tr></thead><tbody>'
      +U.map(x=>'<tr><td><span class="badge '+(x.side==='deposit'?'b-diff':'b-no')+'">'+(x.side==='deposit'?'Deposit'+(x.source?' '+x.source.toUpperCase():''):'Bank')+'</span></td><td style="white-space:nowrap">'+FD(x.ref_date)+'</td><td>'+(x.side==='deposit'?esc(x.employee||'')+(x.delivery_date?' <span class="muted">delivery '+FD(x.delivery_date)+'</span>':''):'<span class="desc">'+esc(x.description||'')+'</span>')+'</td><td class="num">'+N(x.amount)+'</td></tr>').join('')+'</tbody></table></div>':''); }
async function bDel(id,no){ if(!confirm('Delete batch '+no+'? Its deposits and bank lines become unreconciled again.')) return; const d=await post({ajax:'delete_batch',id}); toast(d.ok?'Batch '+no+' deleted.':d.msg,d.ok); if(d.ok) hist(); }
document.addEventListener('keydown',e=>{ if(e.key==='Escape') mmClose(); });
if(TAB==='saved') hist(); if(TAB==='settings') setTypes();
</script>
<?php include 'footer.php'; ?>
