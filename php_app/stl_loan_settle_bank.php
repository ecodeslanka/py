<?php
/**
 * stl_loan_settle_bank.php
 * ─────────────────────────────────────────────────────────────────────
 * STL Loan Settlement with Bank
 *
 *  • Lists STL loans that are NOT settled or PARTIALLY settled
 *    (stl_records.actual_grant_amount − stl_settlement_payments).
 *  • Settle → shows open bank statement DEBIT lines of the Settings
 *    account / statement type:
 *        "AA Loan Payoff…"        and / or   "AA Loan EMI Decrease…"
 *    Lines with the SAME AMOUNT as the loan balance are shown first
 *    (one click to settle); below them a manual picker to choose one or
 *    more lines with a live tally.
 *  • Settling writes a payment into stl_settlement_payments (date, amount,
 *    bank ref of the bank line) and marks the bank line: status, category
 *    "STL Loan Settlement" and a remark.
 *  • Undo (History tab, or deleting the payment on STL Settlement) removes
 *    the payment and clears the bank line again.
 * ─────────────────────────────────────────────────────────────────────
 */
date_default_timezone_set('Asia/Colombo');

ob_start();
include_once 'config.php';
ob_end_clean();
include_once 'auth.php';
requireLogin();

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['sls_csrf'])) $_SESSION['sls_csrf'] = bin2hex(random_bytes(16));
$sls_user = isset($_SESSION['username']) ? (string)$_SESSION['username'] : 'unknown';

const SLS_SOURCE   = 'stl_settle_bank';
const SLS_CATEGORY = 'STL Loan Settlement';
$SLS_BANK_TYPES = ['BOC' => 'BOC', 'NDB' => 'NDB', 'SAMPATH' => 'Sampath'];
$SLS_KINDS = ['payoff' => 'AA Loan Payoff', 'emi' => 'AA Loan EMI Decrease'];

/* ═════════════ HELPERS ═════════════ */
function sls_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function sls_q($conn, $sql) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } }
function sls_norm($s) { return trim(preg_replace('/\s+/u', ' ', (string)$s)); }
function sls_money($v) { return number_format((float)$v, 2); }
function sls_valid_date($d) { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && $d !== '0000-00-00' && strtotime($d) > 0; }
function sls_fmt_date($d) { return sls_valid_date((string)$d) ? date('d M Y', strtotime($d)) : '—'; }
function sls_fmt_dt($d) { return $d ? date('d M Y, h:i A', strtotime($d)) : '—'; }
function sls_ids($ids) { $ids = array_filter(array_map('intval', (array)$ids)); return $ids ? implode(',', $ids) : '0'; }
function sls_has_col($conn, $t, $c) { $r = sls_q($conn, "SHOW COLUMNS FROM `$t` LIKE '" . mysqli_real_escape_string($conn, $c) . "'"); return $r && mysqli_num_rows($r) > 0; }
function sls_digits($s) { return ltrim(preg_replace('/\D/', '', (string)$s), '0'); }
function sls_ref_match($a, $b) {
    if ($a === '' || $b === '') return false;
    if ($a === $b) return true;
    $s = strlen($a) < strlen($b) ? $a : $b; $l = $s === $a ? $b : $a;
    return strlen($s) >= 6 && substr($l, -strlen($s)) === $s;
}
/* kind of a bank debit line: payoff | emi | '' */
function sls_kind($desc) {
    if (preg_match('/AA\s+Loan\s+Payoff/i', $desc)) return 'payoff';
    if (preg_match('/AA\s+Loan\s+EMI\s+Decrease/i', $desc)) return 'emi';
    return '';
}
/* loan number on a bank line, e.g. "Loan Account :114490282292" or a 9+ digit number */
function sls_line_ref($text) {
    if (preg_match('/Loan\s+Account\s*:?\s*(\d{6,})/i', $text, $m)) return sls_digits($m[1]);
    if (preg_match('/\b(\d{9,})\b/', $text, $m)) return sls_digits($m[1]);
    return '';
}

/* ═════════════ TABLES ═════════════ */
function sls_ensure($conn) {
    sls_q($conn, "CREATE TABLE IF NOT EXISTS stl_settle_bank_settings (
        id INT NOT NULL PRIMARY KEY, bank_account_id INT NULL, bank_type VARCHAR(10) NULL,
        updated_by VARCHAR(100) NULL, updated_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    /* one row per bank line used to settle a loan */
    sls_q($conn, "CREATE TABLE IF NOT EXISTS stl_settle_bank (
        id INT AUTO_INCREMENT PRIMARY KEY,
        loan_id INT NOT NULL,
        payment_id INT NOT NULL,
        bank_txn_id INT UNSIGNED NOT NULL,
        kind VARCHAR(20) NULL,
        txn_date DATE NULL,
        description TEXT NULL,
        amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        balance_before DECIMAL(18,2) NOT NULL DEFAULT 0,
        balance_after DECIMAL(18,2) NOT NULL DEFAULT 0,
        remark TEXT NULL,
        bank_account_id INT NULL, bank_type VARCHAR(10) NULL,
        created_by VARCHAR(100) NULL, created_at DATETIME NOT NULL,
        UNIQUE KEY uq_txn (bank_txn_id), INDEX idx_loan (loan_id), INDEX idx_payment (payment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach ([
        'recon_status' => "VARCHAR(20) NULL DEFAULT NULL", 'recon_source' => "VARCHAR(30) NULL DEFAULT NULL",
        'recon_category' => "VARCHAR(50) NULL DEFAULT NULL", 'recon_ref_id' => "INT NULL DEFAULT NULL",
        'recon_remark' => "TEXT NULL", 'recon_by' => "VARCHAR(100) NULL DEFAULT NULL", 'recon_at' => "DATETIME NULL DEFAULT NULL",
    ] as $c => $def) {
        if (!sls_has_col($conn, 'bank_statement_transactions', $c)) sls_q($conn, "ALTER TABLE bank_statement_transactions ADD COLUMN `$c` $def");
    }
}
sls_ensure($conn);

function sls_accounts($conn) {
    $acc = [];
    $r = sls_q($conn, "SELECT account_id, UPPER(bank_type) AS bt, MAX(statement_date) AS last_stmt, COUNT(*) AS uploads FROM bank_statement_uploads GROUP BY account_id, UPPER(bank_type)");
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $id = (int)$row['account_id']; if ($id <= 0) continue;
        if (!isset($acc[$id])) $acc[$id] = ['label' => 'Account #' . $id, 'types' => [], 'last' => '', 'uploads' => 0];
        $acc[$id]['types'][$row['bt']] = $row['last_stmt'];
        $acc[$id]['uploads'] += (int)$row['uploads'];
        if ($row['last_stmt'] > $acc[$id]['last']) $acc[$id]['last'] = $row['last_stmt'];
    }
    if ($acc) {
        $r = sls_q($conn, "SELECT cba.id, CONCAT(COALESCE(NULLIF(b.bank_name,''), cba.bank_code, ''), ' / ', COALESCE(NULLIF(bb.branch_name,''), cba.branch_code, ''), ' (', cba.account_no, ')') AS label
                             FROM company_bank_accounts cba LEFT JOIN banks b ON b.bank_code = cba.bank_code
                        LEFT JOIN bank_branches bb ON bb.bank_code = cba.bank_code AND bb.branch_code = cba.branch_code
                            WHERE cba.id IN (" . sls_ids(array_keys($acc)) . ")");
        while ($r && ($row = mysqli_fetch_assoc($r))) $acc[(int)$row['id']]['label'] = $row['label'];
    }
    foreach ($acc as &$a) ksort($a['types']);
    unset($a);
    uasort($acc, function ($x, $y) { return strcasecmp($x['label'], $y['label']); });
    return $acc;
}
function sls_settings($conn, $accounts) {
    $s = ['account' => 0, 'type' => '', 'saved_account' => 0, 'saved_type' => '', 'updated_by' => '', 'updated_at' => ''];
    $r = sls_q($conn, "SELECT * FROM stl_settle_bank_settings WHERE id = 1 LIMIT 1");
    if ($r && ($row = mysqli_fetch_assoc($r))) {
        $s['saved_account'] = (int)$row['bank_account_id']; $s['saved_type'] = strtoupper((string)$row['bank_type']);
        $s['updated_by'] = (string)$row['updated_by']; $s['updated_at'] = (string)$row['updated_at'];
    }
    if (isset($accounts[$s['saved_account']])) {
        $s['account'] = $s['saved_account'];
        if ($s['saved_type'] !== '' && isset($accounts[$s['account']]['types'][$s['saved_type']])) $s['type'] = $s['saved_type'];
    }
    return $s;
}
function sls_type_label($bt) { global $SLS_BANK_TYPES; $bt = strtoupper((string)$bt); return $bt === '' ? 'All types' : ($SLS_BANK_TYPES[$bt] ?? $bt); }

/* one loan with paid / balance */
function sls_loan($conn, $id) {
    $id = (int)$id;
    $r = sls_q($conn, "SELECT sr.*, COALESCE((SELECT SUM(p.payment_amount) FROM stl_settlement_payments p WHERE p.stl_record_id = sr.id), 0) AS total_paid
                         FROM stl_records sr WHERE sr.id = $id LIMIT 1");
    $l = $r ? mysqli_fetch_assoc($r) : null;
    if ($l) {
        $l['granted'] = round((float)$l['actual_grant_amount'], 2);
        $l['paid']    = round((float)$l['total_paid'], 2);
        $l['balance'] = round($l['granted'] - $l['paid'], 2);
        $l['label']   = $l['loan_label'] ?: ('Loan #' . $l['id']);
    }
    return $l;
}

/* undo one bank settlement link: payment + bank line + link */
function sls_undo($conn, $link_id) {
    $link_id = (int)$link_id;
    $r = sls_q($conn, "SELECT * FROM stl_settle_bank WHERE id = $link_id LIMIT 1");
    $lk = $r ? mysqli_fetch_assoc($r) : null;
    if (!$lk) return false;
    mysqli_begin_transaction($conn);
    try {
        mysqli_query($conn, "DELETE FROM stl_settlement_payments WHERE id = " . (int)$lk['payment_id']);
        mysqli_query($conn, "UPDATE bank_statement_transactions SET recon_status = NULL, recon_source = NULL, recon_category = NULL, recon_ref_id = NULL,
                                recon_remark = NULL, recon_by = NULL, recon_at = NULL
                              WHERE id = " . (int)$lk['bank_txn_id'] . " AND recon_source = '" . SLS_SOURCE . "'");
        mysqli_query($conn, "DELETE FROM stl_settle_bank WHERE id = $link_id");
        mysqli_commit($conn);
        return $lk;
    } catch (Throwable $e) { mysqli_rollback($conn); return false; }
}

/* ═════════════ AJAX ═════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    if (!hash_equals((string)$_SESSION['sls_csrf'], (string)($_POST['csrf'] ?? ''))) { echo json_encode(['ok' => false, 'msg' => 'Security check failed. Reload the page and try again.']); exit; }
    $act = (string)$_POST['ajax'];
    $JSON = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    if ($act === 'save_settings') {
        $accounts = sls_accounts($conn);
        $acc = (int)($_POST['account_id'] ?? 0); $bt = strtoupper(trim((string)($_POST['bank_type'] ?? '')));
        if (!isset($accounts[$acc])) { echo json_encode(['ok' => false, 'msg' => 'Select a bank account that has bank statements uploaded.']); exit; }
        if ($bt !== '' && !isset($accounts[$acc]['types'][$bt])) { echo json_encode(['ok' => false, 'msg' => 'No ' . sls_type_label($bt) . ' statements are uploaded for this account.']); exit; }
        $btDb = $bt !== '' ? $bt : null; $now = date('Y-m-d H:i:s');
        $st = mysqli_prepare($conn, "INSERT INTO stl_settle_bank_settings (id, bank_account_id, bank_type, updated_by, updated_at) VALUES (1, ?, ?, ?, ?)
                                     ON DUPLICATE KEY UPDATE bank_account_id = VALUES(bank_account_id), bank_type = VALUES(bank_type), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)");
        mysqli_stmt_bind_param($st, 'isss', $acc, $btDb, $sls_user, $now);
        $ok = mysqli_stmt_execute($st);
        echo json_encode($ok ? ['ok' => true, 'msg' => 'Settings saved.'] : ['ok' => false, 'msg' => 'Could not save settings.']);
        exit;
    }

    /* open bank debit lines for one loan */
    if ($act === 'lines') {
        $cfg = sls_settings($conn, sls_accounts($conn));
        if ($cfg['account'] <= 0) { echo json_encode(['ok' => false, 'msg' => 'Set the bank account in Settings first.']); exit; }
        $loan = sls_loan($conn, (int)($_POST['loan_id'] ?? 0));
        if (!$loan) { echo json_encode(['ok' => false, 'msg' => 'Loan not found.']); exit; }
        $from = sls_valid_date($_POST['from'] ?? '') ? $_POST['from'] : '';
        $to   = sls_valid_date($_POST['to'] ?? '')   ? $_POST['to']   : '';
        if ($from === '' || $to === '') { echo json_encode(['ok' => false, 'msg' => 'Enter the date range.']); exit; }
        if ($to < $from) { $t = $from; $from = $to; $to = $t; }
        $all = !empty($_POST['all']);
        $acc = $cfg['account']; $bt = $cfg['type'];
        $st = mysqli_prepare($conn, "SELECT t.* FROM bank_statement_transactions t JOIN bank_statement_uploads u ON u.id = t.upload_id
                                      WHERE u.account_id = ? " . ($bt !== '' ? "AND UPPER(u.bank_type) = ?" : "AND ? = ''") . "
                                        AND t.transaction_date BETWEEN ? AND ? AND t.debit > 0 AND (t.recon_status IS NULL OR t.recon_status = '')
                                      ORDER BY t.transaction_date, t.id LIMIT 3000");
        mysqli_stmt_bind_param($st, 'isss', $acc, $bt, $from, $to);
        mysqli_stmt_execute($st);
        $rs = mysqli_stmt_get_result($st);
        $rows = []; $seen = [];
        $lref = sls_digits($loan['loan_ref']);
        while ($rs && ($tx = mysqli_fetch_assoc($rs))) {
            $dk = $tx['transaction_date'] . '|' . sls_norm($tx['description']) . '|' . $tx['debit'] . '|' . $tx['balance'];
            if (isset($seen[$dk])) continue;
            $seen[$dk] = true;
            $text = sls_norm($tx['description'] . ' ' . $tx['reference']);
            $kind = sls_kind($text);
            if (!$all && $kind === '') continue;
            $ref = sls_line_ref($text);
            $rows[] = ['id' => (int)$tx['id'], 'date' => $tx['transaction_date'], 'desc' => $text, 'debit' => round((float)$tx['debit'], 2),
                       'kind' => $kind, 'ref' => $ref, 'ref_ok' => sls_ref_match($lref, $ref)];
        }
        mysqli_stmt_close($st);
        echo json_encode(['ok' => true, 'rows' => $rows, 'balance' => $loan['balance']], $JSON);
        exit;
    }

    /* settle one loan with one or more bank lines */
    if ($act === 'settle') {
        $cfg = sls_settings($conn, sls_accounts($conn));
        if ($cfg['account'] <= 0) { echo json_encode(['ok' => false, 'msg' => 'Set the bank account in Settings first.']); exit; }
        $lid = (int)($_POST['loan_id'] ?? 0);
        $txnIds = array_values(array_unique(array_filter(array_map('intval', (array)json_decode((string)($_POST['txns'] ?? '[]'), true)))));
        $note = trim(mb_substr((string)($_POST['remark'] ?? ''), 0, 300));
        $loan = sls_loan($conn, $lid);
        if (!$loan) { echo json_encode(['ok' => false, 'msg' => 'Loan not found.']); exit; }
        if (!$txnIds) { echo json_encode(['ok' => false, 'msg' => 'Select at least one bank line.']); exit; }
        if ($loan['granted'] <= 0) { echo json_encode(['ok' => false, 'msg' => 'This loan has no grant amount.']); exit; }

        $txns = [];
        $r = mysqli_query($conn, "SELECT t.*, u.account_id, u.bank_type AS stmt_type FROM bank_statement_transactions t JOIN bank_statement_uploads u ON u.id = t.upload_id
                                   WHERE t.id IN (" . sls_ids($txnIds) . ") ORDER BY t.transaction_date, t.id");
        while ($r && ($row = mysqli_fetch_assoc($r))) $txns[(int)$row['id']] = $row;
        foreach ($txnIds as $tid) {
            if (!isset($txns[$tid])) { echo json_encode(['ok' => false, 'msg' => 'A bank line no longer exists. Reload the list.']); exit; }
            $tx = $txns[$tid];
            if (!empty($tx['recon_status'])) { echo json_encode(['ok' => false, 'msg' => 'A bank line is already reconciled. Reload the list.']); exit; }
            if ((int)$tx['account_id'] !== $cfg['account'] || ($cfg['type'] !== '' && strtoupper($tx['stmt_type']) !== $cfg['type'])) { echo json_encode(['ok' => false, 'msg' => 'A bank line is not from the Settings account / statement type.']); exit; }
            if ((float)$tx['debit'] <= 0) { echo json_encode(['ok' => false, 'msg' => 'A bank line is not a debit.']); exit; }
        }

        $now = date('Y-m-d H:i:s'); $balance = $loan['balance']; $done = 0;
        mysqli_begin_transaction($conn);
        try {
            $stP = mysqli_prepare($conn, "INSERT INTO stl_settlement_payments (stl_record_id, payment_date, payment_amount, remarks, bank_ref) VALUES (?,?,?,?,?)");
            $stL = mysqli_prepare($conn, "INSERT INTO stl_settle_bank (loan_id, payment_id, bank_txn_id, kind, txn_date, description, amount, balance_before, balance_after, remark, bank_account_id, bank_type, created_by, created_at)
                                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stU = mysqli_prepare($conn, "UPDATE bank_statement_transactions SET recon_status = ?, recon_source = ?, recon_category = ?, recon_ref_id = ?, recon_remark = ?, recon_by = ?, recon_at = ?
                                          WHERE id = ? AND (recon_status IS NULL OR recon_status = '')");
            foreach ($txns as $tid => $tx) {
                $amt   = round((float)$tx['debit'], 2);
                $text  = sls_norm($tx['description'] . ' ' . $tx['reference']);
                $kind  = sls_kind($text);
                $kindL = $kind === 'payoff' ? 'AA Loan Payoff' : ($kind === 'emi' ? 'AA Loan EMI Decrease' : 'Bank debit');
                $pdate = $tx['transaction_date'];
                $bref  = mb_substr(trim((string)$tx['reference']) !== '' ? trim((string)$tx['reference']) : $text, 0, 150);
                $prem  = 'Bank settle: ' . $kindL . ($note !== '' ? ' | ' . $note : '');
                mysqli_stmt_bind_param($stP, 'isdss', $lid, $pdate, $amt, $prem, $bref);
                if (!mysqli_stmt_execute($stP)) throw new Exception('payment');
                $pid = (int)mysqli_insert_id($conn);

                $before = $balance; $balance = round($balance - $amt, 2);
                $status = 'reconciled';
                $remark = 'STL Loan Settlement | ' . $loan['label'] . ($loan['loan_ref'] ? ' (ref ' . $loan['loan_ref'] . ')' : '')
                        . ' | Deposit date ' . $loan['deposit_date'] . ' | ' . $kindL . ' Rs ' . sls_money($amt)
                        . ' | Loan balance Rs ' . sls_money($before) . ' → Rs ' . sls_money($balance)
                        . ($balance < -0.005 ? ' (over-paid Rs ' . sls_money(-$balance) . ', interest)' : ($balance <= 0.005 ? ' (settled)' : ''))
                        . ' | Payment #' . $pid . ' | by ' . $sls_user . ' on ' . $now . ($note !== '' ? ' | Note: ' . $note : '');
                $acc = $cfg['account']; $bt = $cfg['type'];
                mysqli_stmt_bind_param($stL, 'iiisssdddsisss', $lid, $pid, $tid, $kind, $pdate, $text, $amt, $before, $balance, $remark, $acc, $bt, $sls_user, $now);
                if (!mysqli_stmt_execute($stL)) throw new Exception('link');
                $link = (int)mysqli_insert_id($conn);

                $src = SLS_SOURCE; $cat = SLS_CATEGORY;
                mysqli_stmt_bind_param($stU, 'sssisssi', $status, $src, $cat, $link, $remark, $sls_user, $now, $tid);
                mysqli_stmt_execute($stU);
                if (mysqli_stmt_affected_rows($stU) !== 1) throw new Exception('taken');
                $done++;
            }
            mysqli_commit($conn);
            echo json_encode(['ok' => true, 'done' => $done, 'balance' => $balance, 'settled' => $balance <= 0.005], $JSON);
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            echo json_encode(['ok' => false, 'msg' => 'Could not settle. A bank line may have just been used by someone else — reload and try again.']);
        }
        exit;
    }

    if ($act === 'undo') {
        $lk = sls_undo($conn, (int)($_POST['id'] ?? 0));
        echo json_encode($lk ? ['ok' => true] : ['ok' => false, 'msg' => 'Could not undo. It may already be removed.']);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Unknown action.']); exit;
}

/* ═════════════ PAGE DATA ═════════════ */
$accounts = sls_accounts($conn);
$cfg      = sls_settings($conn, $accounts);
$tab      = in_array($_GET['tab'] ?? '', ['history', 'settings'], true) ? $_GET['tab'] : 'loans';

/* open loans: not settled or partially settled */
$loans = []; $tot = ['granted' => 0, 'paid' => 0, 'balance' => 0, 'partial' => 0, 'pending' => 0];
if ($tab === 'loans') {
    $r = sls_q($conn, "SELECT sr.*, COALESCE(sp.total_paid, 0) AS total_paid, COALESCE(sp.cnt, 0) AS pay_count, sp.last_date,
                              COALESCE(sb.bank_cnt, 0) AS bank_cnt
                         FROM stl_records sr
                    LEFT JOIN (SELECT stl_record_id, SUM(payment_amount) AS total_paid, COUNT(*) AS cnt, MAX(payment_date) AS last_date
                                 FROM stl_settlement_payments GROUP BY stl_record_id) sp ON sp.stl_record_id = sr.id
                    LEFT JOIN (SELECT loan_id, COUNT(*) AS bank_cnt FROM stl_settle_bank GROUP BY loan_id) sb ON sb.loan_id = sr.id
                        WHERE COALESCE(sr.actual_grant_amount, 0) > 0
                          AND COALESCE(sp.total_paid, 0) < COALESCE(sr.actual_grant_amount, 0) - 0.005
                        ORDER BY COALESCE(DATE_ADD(sr.grant_date, INTERVAL sr.stl_days DAY), sr.deposit_date), sr.id");
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $g = round((float)$row['actual_grant_amount'], 2); $p = round((float)$row['total_paid'], 2);
        $row['granted'] = $g; $row['paid'] = $p; $row['balance'] = round($g - $p, 2);
        $row['label'] = $row['loan_label'] ?: ('Loan #' . $row['id']);
        $row['maturity'] = sls_valid_date((string)$row['grant_date']) ? date('Y-m-d', strtotime($row['grant_date'] . ' +' . (int)$row['stl_days'] . ' days')) : '';
        $row['state'] = $p > 0 ? 'partial' : 'pending';
        $loans[] = $row;
        $tot['granted'] += $g; $tot['paid'] += $p; $tot['balance'] += $row['balance']; $tot[$row['state']]++;
    }
}

/* history */
$h_from = sls_valid_date($_GET['h_from'] ?? '') ? $_GET['h_from'] : date('Y-m-d', strtotime('-60 days'));
$h_to   = sls_valid_date($_GET['h_to'] ?? '')   ? $_GET['h_to']   : date('Y-m-d');
$h_q    = trim((string)($_GET['h_q'] ?? ''));
$history = [];
if ($tab === 'history') {
    $sql = "SELECT b.*, sr.loan_label, sr.loan_ref, sr.deposit_date FROM stl_settle_bank b LEFT JOIN stl_records sr ON sr.id = b.loan_id
             WHERE b.created_at >= ? AND b.created_at < DATE_ADD(?, INTERVAL 1 DAY)";
    $types = 'ss'; $args = [$h_from, $h_to];
    if ($h_q !== '') { $sql .= " AND (sr.loan_label LIKE ? OR sr.loan_ref LIKE ? OR b.description LIKE ? OR b.created_by LIKE ?)"; $like = "%$h_q%"; $types .= 'ssss'; array_push($args, $like, $like, $like, $like); }
    $sql .= " ORDER BY b.created_at DESC, b.id DESC LIMIT 1000";
    $st = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($st, $types, ...$args);
    mysqli_stmt_execute($st);
    $rs = mysqli_stmt_get_result($st);
    while ($rs && ($row = mysqli_fetch_assoc($rs))) $history[] = $row;
    mysqli_stmt_close($st);
}
function sls_acc_label($accounts, $id) { return $accounts[(int)$id]['label'] ?? ('Account #' . (int)$id); }

include 'header.php';
?>
<style>

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
.dbr .filters .wide { grid-column: span 2; }
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
.dbr tr.failed td { background: #fef2f2; }
.dbr tr.staged td { background: #eff6ff; }
.dbr tr.used td { opacity: .45; }
.dbr .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.dbr th.sep, .dbr td.sep { border-right: 2px solid #e5e5e5; }
.dbr .muted { color: #777; font-size: 12px; }
.dbr .rowmsg { font-size: 12px; margin-top: 3px; }
.dbr .rowmsg.err { color: #b91c1c; } .dbr .rowmsg.info { color: #1d4ed8; }
.dbr .dep-link, .dbr .blink { color: #0f766e; font-weight: 700; text-decoration: none; }
.dbr .desc { color: #444; font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 11.5px; display: block; }
.dbr .badge { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 600; white-space: nowrap; }
.dbr .b-ok { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.dbr .b-diff { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
.dbr .b-no { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.dbr .b-man { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
.dbr .b-auto { background: #f5f5f5; color: #555; border: 1px solid #e5e5e5; }
.dbr .diff-pos { color: #15803d; font-weight: 700; } .dbr .diff-neg { color: #b91c1c; font-weight: 700; }
.dbr .name { font-weight: 600; }
.dbr code { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 12px; background: #f3f4f6; padding: 1px 5px; border-radius: 4px; }
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
.dbr .bhead { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-start; justify-content: space-between; background: #fff; border: 1px solid #e5e5e5; border-radius: 12px; padding: 16px; }
.dbr .bhead h3 { margin: 0 0 6px; font-size: 18px; }
.dbr .bmeta { display: flex; flex-wrap: wrap; gap: 6px 18px; color: #555; }
.dbr .bmeta b { color: #111; }
.dbr .modal { position: fixed; inset: 0; z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(0,0,0,.5); }
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
.dbr .mr-list tr.same td:nth-child(5) { color: #0f766e; font-weight: 700; }
.dbr .mr-list tr.eq td.num { color: #15803d; font-weight: 700; }
.dbr .mr-list tr.busy td { opacity: .45; cursor: not-allowed; }
.dbr .dfoot { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; padding: 12px 18px; border-top: 1px solid #e5e5e5; background: #fafafa; border-radius: 0 0 14px 14px; }
.dbr .tally { display: flex; gap: 18px; margin-right: auto; }
.dbr .tally div { display: flex; flex-direction: column; }
.dbr .tally b { font-size: 15px; font-variant-numeric: tabular-nums; }
.dbr .tally .ok { color: #15803d; } .dbr .tally .bad { color: #b91c1c; }
.dbr .toast { position: fixed; right: 20px; bottom: 80px; z-index: 3000; padding: 12px 16px; border-radius: 10px; color: #fff; font-weight: 600; box-shadow: 0 10px 30px rgba(0,0,0,.2); opacity: 0; transform: translateY(10px); transition: .2s; pointer-events: none; max-width: 440px; }
.dbr .toast.show { opacity: 1; transform: none; } .dbr .toast.ok { background: #15803d; } .dbr .toast.err { background: #b91c1c; }
@media (max-width: 760px) { .dbr .filters .wide { grid-column: auto; } .dbr .setgrid { grid-template-columns: 1fr; } }
@media print {
    .dbr .tabs, .dbr .noprint, .dbr .toast { display: none !important; }
    .dbr { padding: 0; }
}

.dbr h1 i { color: #0d9488; }
.dbr .ref { font-family: ui-monospace, Menlo, Consolas, monospace; font-weight: 700; color: #0369a1; background: #eff6ff; border: 1px solid #bfdbfe; padding: 1px 6px; border-radius: 5px; font-size: 11.5px; white-space: nowrap; }
.dbr .kind { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap; }
.dbr .k-payoff { background: #ede9fe; color: #5b21b6; border: 1px solid #ddd6fe; }
.dbr .k-emi { background: #e0f2fe; color: #075985; border: 1px solid #bae6fd; }
.dbr .k- { background: #f3f4f6; color: #555; border: 1px solid #e5e5e5; }
.dbr .seg { display: inline-flex; border: 1px solid #e5e5e5; border-radius: 8px; overflow: hidden; }
.dbr .seg button { border: 0; background: #fff; padding: 8px 12px; font: inherit; font-weight: 600; color: #555; cursor: pointer; }
.dbr .seg button.on { background: #111; color: #fff; }
.dbr .exact { border: 1.5px solid #86efac; background: #f0fdf4; border-radius: 10px; padding: 10px 12px; margin-bottom: 12px; }
.dbr .exact h4 { margin: 0 0 8px; font-size: 13px; color: #166534; }
.dbr .exact-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between; padding: 8px 10px; background: #fff; border: 1px solid #bbf7d0; border-radius: 8px; margin-top: 6px; }
.dbr .exact-row .desc { max-width: 520px; }
.dbr .bal-due { color: #b45309; font-weight: 800; }
.dbr tr.loan-row[hidden] { display: none; }
.dbr .mr-list tr.same td:nth-child(5) .ref { background: #0f766e; color: #fff; border-color: #0f766e; }
</style>

<div class="dbr">
    <h1><i class="fa-solid fa-money-bill-transfer"></i> STL Loan Settlement with Bank</h1>
    <p class="sub">Settle open STL loans from bank statement lines <code>AA Loan Payoff</code> / <code>AA Loan EMI Decrease</code>. Each line becomes a loan payment and is marked on the bank statement.
        <a href="stl_settlement.php" class="blink" style="margin-left:6px;"><i class="fa-solid fa-arrow-left"></i> STL Settlement</a></p>

    <div class="tabs">
        <a href="?tab=loans" class="<?php echo $tab === 'loans' ? 'on' : ''; ?>"><i class="fa-solid fa-list-check"></i> Open loans</a>
        <a href="?tab=history" class="<?php echo $tab === 'history' ? 'on' : ''; ?>"><i class="fa-solid fa-clock-rotate-left"></i> Bank settlements</a>
        <a href="?tab=settings" class="<?php echo $tab === 'settings' ? 'on' : ''; ?>"><i class="fa-solid fa-gear"></i> Settings</a>
    </div>

<?php if ($tab === 'loans'): ?>

    <?php if ($cfg['account'] <= 0): ?>
        <div class="alert warn">No bank account is set yet. <a href="?tab=settings">Open Settings</a> and choose the STL bank account.</div>
    <?php else: ?>
        <div class="setbar">
            <span><i class="fa-solid fa-building-columns"></i> <b><?php echo sls_h(sls_acc_label($accounts, $cfg['account'])); ?></b></span>
            <span class="pill"><?php echo sls_h(sls_type_label($cfg['type'])); ?></span>
            <a href="?tab=settings" class="setlink"><i class="fa-solid fa-gear"></i> Change</a>
        </div>
    <?php endif; ?>

    <div class="stats">
        <div class="stat"><div class="lbl">Open loans</div><div class="val"><?php echo count($loans); ?></div><div class="cnt"><?php echo $tot['pending']; ?> not settled · <?php echo $tot['partial']; ?> partial</div></div>
        <div class="stat"><div class="lbl">Granted</div><div class="val"><?php echo sls_money($tot['granted']); ?></div></div>
        <div class="stat g"><div class="lbl">Paid so far</div><div class="val"><?php echo sls_money($tot['paid']); ?></div></div>
        <div class="stat a"><div class="lbl">Balance to settle</div><div class="val"><?php echo sls_money($tot['balance']); ?></div></div>
    </div>

    <div class="toolbar" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:14px 0 10px;">
        <div class="seg" role="group" aria-label="Filter">
            <button type="button" class="on" data-f="all">All open</button>
            <button type="button" data-f="pending">Not settled</button>
            <button type="button" data-f="partial">Partially settled</button>
        </div>
        <input type="search" id="lSearch" placeholder="Search loan, ref or date…" style="flex:1 1 220px;max-width:320px;">
    </div>

    <div class="card">
    <?php if ($loans): ?>
        <table>
            <thead><tr><th>STL loan</th><th>Loan ref</th><th>Deposit date</th><th>Grant date</th><th>Maturity</th>
                <th class="num">Granted</th><th class="num">Paid</th><th class="num">Balance</th><th>Status</th><th style="text-align:right;">Action</th></tr></thead>
            <tbody>
            <?php foreach ($loans as $l):
                $late = $l['maturity'] && $l['maturity'] < date('Y-m-d');
                $md = ['id' => (int)$l['id'], 'label' => $l['label'], 'ref' => (string)$l['loan_ref'], 'dep' => $l['deposit_date'], 'grant' => (string)$l['grant_date'],
                       'maturity' => $l['maturity'], 'granted' => $l['granted'], 'paid' => $l['paid'], 'balance' => $l['balance']]; ?>
                <tr class="loan-row" data-state="<?php echo $l['state']; ?>"
                    data-search="<?php echo sls_h(strtolower($l['label'] . ' ' . $l['loan_ref'] . ' ' . $l['deposit_date'] . ' ' . $l['grant_date'])); ?>"
                    data-loan="<?php echo sls_h(json_encode($md, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)); ?>">
                    <td><b><?php echo sls_h($l['label']); ?></b><?php if ((int)$l['bank_cnt'] > 0): ?><div class="muted"><?php echo (int)$l['bank_cnt']; ?> bank settlement(s)</div><?php endif; ?></td>
                    <td><?php echo $l['loan_ref'] ? '<span class="ref">' . sls_h($l['loan_ref']) . '</span>' : '<span class="muted">—</span>'; ?></td>
                    <td style="white-space:nowrap;"><?php echo sls_fmt_date($l['deposit_date']); ?></td>
                    <td style="white-space:nowrap;"><?php echo sls_fmt_date($l['grant_date']); ?></td>
                    <td style="white-space:nowrap;<?php echo $late ? 'color:#b91c1c;font-weight:700;' : ''; ?>"><?php echo sls_fmt_date($l['maturity']); ?></td>
                    <td class="num"><?php echo sls_money($l['granted']); ?></td>
                    <td class="num"><?php echo $l['paid'] > 0 ? sls_money($l['paid']) : '<span class="muted">—</span>'; ?></td>
                    <td class="num bal-due"><?php echo sls_money($l['balance']); ?></td>
                    <td><?php echo $l['state'] === 'partial' ? '<span class="badge b-diff">Partially settled</span>' : '<span class="badge b-no">Not settled</span>'; ?></td>
                    <td style="text-align:right;"><button type="button" class="btn btn-dark btn-sm js-settle" <?php echo $cfg['account'] > 0 ? '' : 'disabled'; ?>><i class="fa-solid fa-money-bill-transfer"></i> Settle</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="empty" id="lNone" hidden>No loans match this filter.</div>
    <?php else: ?><div class="empty">All STL loans are settled. 🎉</div><?php endif; ?>
    </div>

<?php elseif ($tab === 'history'): ?>

    <form class="filters" method="get">
        <input type="hidden" name="tab" value="history">
        <div><label class="f" for="h_from">Settled from</label><input type="date" id="h_from" name="h_from" value="<?php echo sls_h($h_from); ?>"></div>
        <div><label class="f" for="h_to">Settled to</label><input type="date" id="h_to" name="h_to" value="<?php echo sls_h($h_to); ?>"></div>
        <div class="wide"><label class="f" for="h_q">Search</label><input type="search" id="h_q" name="h_q" value="<?php echo sls_h($h_q); ?>" placeholder="Loan, ref, bank text or user"></div>
        <div><button class="btn btn-dark" type="submit" style="width:100%;justify-content:center;"><i class="fa-solid fa-filter"></i> Filter</button></div>
    </form>
    <div class="card" style="margin-top:14px;">
    <?php if ($history): ?>
        <table>
            <thead><tr><th>Saved</th><th>STL loan</th><th>Txn date</th><th>Type</th><th>Bank transaction</th><th class="num">Amount</th><th class="num">Balance before → after</th><th style="text-align:right;">Action</th></tr></thead>
            <tbody>
            <?php foreach ($history as $hrow): ?>
                <tr data-id="<?php echo (int)$hrow['id']; ?>">
                    <td style="white-space:nowrap;"><?php echo sls_h(sls_fmt_dt($hrow['created_at'])); ?><div class="muted">by <?php echo sls_h($hrow['created_by'] ?: '—'); ?></div></td>
                    <td><b><?php echo sls_h($hrow['loan_label'] ?: ('Loan #' . $hrow['loan_id'])); ?></b><?php if ($hrow['loan_ref']): ?><div><span class="ref"><?php echo sls_h($hrow['loan_ref']); ?></span></div><?php endif; ?></td>
                    <td style="white-space:nowrap;"><?php echo sls_fmt_date($hrow['txn_date']); ?></td>
                    <td><span class="kind k-<?php echo sls_h($hrow['kind']); ?>"><?php echo sls_h($SLS_KINDS[$hrow['kind']] ?? 'Bank debit'); ?></span></td>
                    <td style="min-width:220px;"><span class="desc"><?php echo sls_h($hrow['description']); ?></span></td>
                    <td class="num"><b><?php echo sls_money($hrow['amount']); ?></b></td>
                    <td class="num" style="white-space:nowrap;"><?php echo sls_money($hrow['balance_before']); ?> → <?php echo sls_money($hrow['balance_after']); ?></td>
                    <td style="text-align:right;"><button type="button" class="btn btn-danger btn-sm js-undo"><i class="fa-solid fa-rotate-left"></i> Undo</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?><div class="empty">No bank settlements in this period.</div><?php endif; ?>
    </div>

<?php else: ?>

    <div class="setcard">
        <h3>Settings</h3>
        <p class="muted" style="margin:0 0 14px;">Choose the account the STL loans are paid from. Only accounts with bank statements are listed. Statement type is optional.</p>
        <?php if (!$accounts): ?>
            <div class="alert warn">No bank statements are uploaded yet. Upload one in <a href="bank_statements.php">Bank Statements</a> first.</div>
        <?php else: ?>
        <div class="setgrid">
            <div class="wide">
                <label class="f" for="set_account">Bank account <span style="color:#b91c1c">*</span></label>
                <select id="set_account">
                    <option value="">Select…</option>
                    <?php foreach ($accounts as $id => $a): ?>
                        <option value="<?php echo $id; ?>" data-types="<?php echo sls_h(json_encode($a['types'])); ?>" <?php echo $cfg['saved_account'] === $id ? 'selected' : ''; ?>>
                            <?php echo sls_h($a['label']); ?> — <?php echo (int)$a['uploads']; ?> statement(s), latest <?php echo sls_fmt_date($a['last']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label class="f" for="set_type">Statement type (optional)</label><select id="set_type" data-saved="<?php echo sls_h($cfg['saved_type']); ?>"><option value="">All types</option></select></div>
        </div>
        <div style="display:flex;gap:10px;align-items:center;margin-top:16px;flex-wrap:wrap;">
            <button type="button" class="btn btn-dark" id="setSave"><i class="fa-solid fa-floppy-disk"></i> Save settings</button>
            <?php if ($cfg['updated_at']): ?><span class="muted">Last saved <?php echo sls_h(sls_fmt_dt($cfg['updated_at'])); ?> by <?php echo sls_h($cfg['updated_by'] ?: '—'); ?></span><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

<?php endif; ?>

    <!-- settle dialog -->
    <div class="modal" id="stModal" hidden>
        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="stTitle">
            <div class="dhead"><h3 id="stTitle">Settle loan</h3><button type="button" class="xbtn" id="stClose" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>
            <div class="dbody">
                <div class="mr-dep" id="stLoan"></div>
                <div class="mr-filter">
                    <div class="seg" role="group" aria-label="Bank line type">
                        <button type="button" class="on" data-k="both">Both</button>
                        <button type="button" data-k="payoff">AA Loan Payoff</button>
                        <button type="button" data-k="emi">AA Loan EMI Decrease</button>
                    </div>
                    <div><label class="f" for="stFrom">Txn date from</label><input type="date" id="stFrom"></div>
                    <div><label class="f" for="stTo">Txn date to</label><input type="date" id="stTo"></div>
                    <div><label class="chk"><input type="checkbox" id="stAll"> All open debits</label></div>
                    <div><button type="button" class="btn btn-dark" id="stLoad"><i class="fa-solid fa-magnifying-glass"></i> Load</button></div>
                </div>

                <div class="exact" id="stExact" hidden>
                    <h4><i class="fa-solid fa-circle-check"></i> Same amount as the loan balance</h4>
                    <div id="stExactRows"></div>
                </div>

                <h4 style="margin:6px 0 8px;font-size:13px;"><i class="fa-solid fa-hand-pointer"></i> Manual reconcile — pick one or more lines</h4>
                <div class="mr-filter" style="margin-bottom:8px;"><div class="grow"><input type="search" id="stSearch" placeholder="Search description, ref or amount"></div></div>
                <div class="card mr-list">
                    <table>
                        <thead><tr><th style="width:34px;"></th><th>Txn date</th><th>Type</th><th>Bank transaction</th><th>Loan no</th><th class="num">Debit</th></tr></thead>
                        <tbody id="stRows"><tr><td colspan="6" class="empty">Loading…</td></tr></tbody>
                    </table>
                </div>
            </div>
            <div class="dfoot">
                <div class="tally" aria-live="polite">
                    <div><span class="muted">Loan balance</span><b id="stBal">0.00</b></div>
                    <div><span class="muted">Selected bank</span><b id="stSel">0.00</b></div>
                    <div><span class="muted">Balance after</span><b id="stAfter">—</b></div>
                </div>
                <input type="text" id="stRemark" maxlength="300" placeholder="Remark (optional)" style="flex:1 1 180px;width:auto;">
                <button type="button" class="btn btn-light" id="stCancel">Cancel</button>
                <button type="button" class="btn btn-dark" id="stSave" disabled><i class="fa-solid fa-check"></i> Settle selected</button>
            </div>
        </div>
    </div>

    <div class="toast" id="dbrToast" role="status" aria-live="polite"></div>
</div>

<script>
(function () {
    var CSRF = <?php echo json_encode($_SESSION['sls_csrf']); ?>;
    var KINDS = <?php echo json_encode($SLS_KINDS); ?>;
    var toastEl = document.getElementById('dbrToast'), toastT;
    function toast(msg, type) { toastEl.textContent = msg; toastEl.className = 'toast show ' + (type || 'ok'); clearTimeout(toastT); toastT = setTimeout(function () { toastEl.className = 'toast'; }, 4200); }
    function post(data) { var fd = new FormData(); fd.append('csrf', CSRF); Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        return fetch(location.pathname, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); }); }
    function money(n) { return Number(n).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
    function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    function addDays(d, n) { var x = d ? new Date(d + 'T00:00:00') : new Date(); x.setDate(x.getDate() + n); return x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0'); }
    function fmtD(d) { return d ? new Date(d + 'T00:00:00').toLocaleDateString('en-GB', {day: '2-digit', month: 'short', year: 'numeric'}) : '—'; }
    function r2(n) { return Math.round(n * 100) / 100; }
    function kindTag(k) { return '<span class="kind k-' + esc(k) + '">' + esc(KINDS[k] || 'Bank debit') + '</span>'; }

    /* settings */
    var setAcc = document.getElementById('set_account'), setType = document.getElementById('set_type');
    if (setAcc && setType) {
        var typeNames = <?php echo json_encode($SLS_BANK_TYPES); ?>;
        var fill = function (keep) {
            var o = setAcc.options[setAcc.selectedIndex], types = {};
            try { types = JSON.parse((o && o.dataset.types) || '{}'); } catch (e) {}
            setType.innerHTML = '<option value="">All types</option>' + Object.keys(types).map(function (bt) { return '<option value="' + bt + '">' + esc(typeNames[bt] || bt) + ' (latest ' + esc(types[bt]) + ')</option>'; }).join('');
            if (keep && types[keep]) setType.value = keep;
        };
        fill(setType.dataset.saved);
        setAcc.addEventListener('change', function () { fill(setType.value); });
        document.getElementById('setSave').addEventListener('click', function () {
            var b = this;
            if (!setAcc.value) { toast('Select the bank account.', 'err'); setAcc.focus(); return; }
            b.disabled = true;
            post({ajax: 'save_settings', account_id: setAcc.value, bank_type: setType.value})
            .then(function (res) { b.disabled = false; toast(res.msg || 'Done', res.ok ? 'ok' : 'err'); if (res.ok) setTimeout(function () { location.href = '?tab=loans'; }, 700); })
            .catch(function () { b.disabled = false; toast('Could not reach the server. Try again.', 'err'); });
        });
    }

    /* history: undo */
    document.querySelectorAll('.js-undo').forEach(function (b) {
        b.addEventListener('click', function () {
            var tr = b.closest('tr');
            if (!confirm('Undo this bank settlement?\n\nThe loan payment is deleted and the bank statement line becomes open again.')) return;
            b.disabled = true;
            post({ajax: 'undo', id: tr.dataset.id}).then(function (res) {
                if (!res.ok) { b.disabled = false; toast(res.msg || 'Could not undo.', 'err'); return; }
                tr.remove(); toast('Undone. Payment removed and bank line cleared.', 'ok');
            }).catch(function () { b.disabled = false; toast('Could not reach the server. Try again.', 'err'); });
        });
    });

    /* loans list: filter */
    var rowsL = Array.prototype.slice.call(document.querySelectorAll('tr.loan-row'));
    var fState = 'all', lSearch = document.getElementById('lSearch');
    function filterLoans() {
        var q = (lSearch ? lSearch.value : '').trim().toLowerCase(), n = 0;
        rowsL.forEach(function (tr) { var ok = (fState === 'all' || tr.dataset.state === fState) && (!q || tr.dataset.search.indexOf(q) !== -1); tr.hidden = !ok; if (ok) n++; });
        var none = document.getElementById('lNone'); if (none) none.hidden = n > 0;
    }
    document.querySelectorAll('.toolbar .seg button').forEach(function (b) {
        b.addEventListener('click', function () { document.querySelectorAll('.toolbar .seg button').forEach(function (x) { x.classList.toggle('on', x === b); }); fState = b.dataset.f; filterLoans(); });
    });
    if (lSearch) lSearch.addEventListener('input', filterLoans);

    /* settle dialog */
    var md = document.getElementById('stModal');
    if (!md || !rowsL.length) return;
    var cur = null, list = [], picked = {}, kindF = 'both', lastFocus = null;
    var stFrom = document.getElementById('stFrom'), stTo = document.getElementById('stTo'), stAll = document.getElementById('stAll'), stSearch = document.getElementById('stSearch');
    var stRows = document.getElementById('stRows'), stSave = document.getElementById('stSave');

    function tally() {
        var sum = 0, n = 0; Object.keys(picked).forEach(function (id) { sum += picked[id]; n++; }); sum = r2(sum);
        var after = r2(cur.balance - sum), a = document.getElementById('stAfter');
        document.getElementById('stSel').textContent = money(sum) + (n > 1 ? ' (' + n + ')' : '');
        if (!n) { a.textContent = '—'; a.className = ''; }
        else if (Math.abs(after) < 0.005) { a.textContent = '0.00 — settled'; a.className = 'ok'; }
        else if (after < 0) { a.textContent = money(after) + ' (interest)'; a.className = 'bad'; }
        else { a.textContent = money(after) + ' (partial)'; a.className = 'bad'; }
        stSave.disabled = n === 0;
    }
    function visible() {
        var q = stSearch.value.trim().toLowerCase();
        return list.filter(function (r) {
            if (!stAll.checked && kindF !== 'both' && r.kind !== kindF) return false;
            return !q || (r.desc + ' ' + r.ref + ' ' + r.debit + ' ' + money(r.debit)).toLowerCase().indexOf(q) !== -1;
        });
    }
    function render() {
        var rows = visible();
        /* 1. same amount as the balance first */
        var exact = rows.filter(function (r) { return Math.abs(r.debit - cur.balance) < 0.005; });
        exact.sort(function (a, b) { return (b.ref_ok ? 1 : 0) - (a.ref_ok ? 1 : 0) || a.date.localeCompare(b.date); });
        document.getElementById('stExact').hidden = !exact.length;
        document.getElementById('stExactRows').innerHTML = exact.map(function (r) {
            return '<div class="exact-row"><div>' + kindTag(r.kind) + ' <b>' + esc(fmtD(r.date)) + '</b> · <span class="desc" style="display:inline;">' + esc(r.desc) + '</span>' +
                   (r.ref ? ' <span class="ref">' + esc(r.ref) + '</span>' : '') + (r.ref_ok ? ' <span class="badge b-ok">Loan no matches</span>' : '') + '</div>' +
                   '<div style="display:flex;gap:10px;align-items:center;"><b>' + money(r.debit) + '</b><button type="button" class="btn btn-dark btn-sm js-quick" data-id="' + r.id + '"><i class="fa-solid fa-bolt"></i> Settle with this</button></div></div>';
        }).join('');
        /* 2. manual list: loan no match, then closest amount, then date */
        rows.sort(function (a, b) {
            return (b.ref_ok ? 1 : 0) - (a.ref_ok ? 1 : 0) || Math.abs(a.debit - cur.balance) - Math.abs(b.debit - cur.balance) || a.date.localeCompare(b.date);
        });
        stRows.innerHTML = rows.length ? rows.map(function (r) {
            var on = picked[r.id] !== undefined;
            var cls = [on ? 'pick' : '', r.ref_ok ? 'same' : '', Math.abs(r.debit - cur.balance) < 0.005 ? 'eq' : ''].join(' ');
            return '<tr class="' + cls + '" data-id="' + r.id + '"><td><input type="checkbox" ' + (on ? 'checked ' : '') + 'aria-label="Select line"></td>' +
                   '<td style="white-space:nowrap;">' + esc(fmtD(r.date)) + '</td><td>' + kindTag(r.kind) + '</td><td><span class="desc">' + esc(r.desc) + '</span></td>' +
                   '<td>' + (r.ref ? '<span class="ref">' + esc(r.ref) + '</span>' : '<span class="muted">—</span>') + '</td><td class="num">' + money(r.debit) + '</td></tr>';
        }).join('') : '<tr><td colspan="6" class="empty">No open ' + (stAll.checked ? 'debit' : (kindF === 'both' ? 'AA Loan Payoff / EMI Decrease' : KINDS[kindF])) + ' lines in this date range.</td></tr>';
    }
    function load() {
        if (!stFrom.value || !stTo.value) { toast('Enter the date range.', 'err'); return; }
        stRows.innerHTML = '<tr><td colspan="6" class="empty">Loading…</td></tr>';
        document.getElementById('stExact').hidden = true;
        post({ajax: 'lines', loan_id: cur.id, from: stFrom.value, to: stTo.value, all: stAll.checked ? 1 : 0}).then(function (res) {
            if (!res.ok) { stRows.innerHTML = '<tr><td colspan="6" class="empty">' + esc(res.msg || 'Could not load.') + '</td></tr>'; return; }
            list = res.rows; cur.balance = res.balance;
            document.getElementById('stBal').textContent = money(cur.balance);
            var keep = {}; list.forEach(function (r) { if (picked[r.id] !== undefined) keep[r.id] = r.debit; }); picked = keep;
            render(); tally();
        }).catch(function () { stRows.innerHTML = '<tr><td colspan="6" class="empty">Could not reach the server. Try again.</td></tr>'; });
    }
    function open(tr) {
        lastFocus = document.activeElement; cur = JSON.parse(tr.dataset.loan); list = []; picked = {};
        var start = cur.grant || cur.dep;
        stFrom.value = start ? start : addDays('', -60);
        stTo.value = addDays('', 0);   /* today */
        stSearch.value = ''; stAll.checked = false; document.getElementById('stRemark').value = '';
        document.getElementById('stTitle').textContent = 'Settle — ' + cur.label;
        document.getElementById('stLoan').innerHTML =
            '<div><span class="muted">STL loan</span><b>' + esc(cur.label) + '</b></div>' +
            '<div><span class="muted">Loan ref</span><b>' + (cur.ref ? esc(cur.ref) : '—') + '</b></div>' +
            '<div><span class="muted">Grant date</span><b>' + esc(fmtD(cur.grant)) + '</b></div>' +
            '<div><span class="muted">Maturity</span><b>' + esc(fmtD(cur.maturity)) + '</b></div>' +
            '<div><span class="muted">Granted</span><b>' + money(cur.granted) + '</b></div>' +
            '<div><span class="muted">Paid</span><b>' + money(cur.paid) + '</b></div>' +
            '<div><span class="muted">Balance</span><b class="bal-due">' + money(cur.balance) + '</b></div>';
        document.getElementById('stBal').textContent = money(cur.balance);
        md.hidden = false; tally(); load(); stFrom.focus();
    }
    function close() { md.hidden = true; if (lastFocus && document.contains(lastFocus)) lastFocus.focus(); }
    function settle(ids, btn) {
        var sum = 0; ids.forEach(function (id) { var r = list.filter(function (x) { return x.id === id; })[0]; if (r) sum += r.debit; });
        var after = r2(cur.balance - sum);
        var msg = 'Settle ' + cur.label + ' with ' + ids.length + ' bank line(s) of ' + money(sum) + '?\n\nBalance ' + money(cur.balance) + ' → ' + money(after) +
                  (after < -0.005 ? ' (over-paid ' + money(-after) + ', recorded as interest)' : (Math.abs(after) < 0.005 ? ' (fully settled)' : ' (partially settled)'));
        if (!confirm(msg)) return;
        var old = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
        post({ajax: 'settle', loan_id: cur.id, txns: JSON.stringify(ids), remark: document.getElementById('stRemark').value}).then(function (res) {
            btn.disabled = false; btn.innerHTML = old;
            if (!res.ok) { toast(res.msg || 'Could not settle.', 'err'); return; }
            close();
            toast(res.settled ? cur.label + ' is fully settled. Bank line(s) marked.' : 'Saved. Balance now ' + money(res.balance) + '. Bank line(s) marked.', 'ok');
            setTimeout(function () { location.reload(); }, 900);
        }).catch(function () { btn.disabled = false; btn.innerHTML = old; toast('Could not reach the server. Try again.', 'err'); });
    }

    document.querySelectorAll('.js-settle').forEach(function (b) { b.addEventListener('click', function () { open(b.closest('tr')); }); });
    document.querySelectorAll('#stModal .seg button').forEach(function (b) {
        b.addEventListener('click', function () { document.querySelectorAll('#stModal .seg button').forEach(function (x) { x.classList.toggle('on', x === b); }); kindF = b.dataset.k; render(); });
    });
    document.getElementById('stLoad').addEventListener('click', load);
    stAll.addEventListener('change', load);
    stSearch.addEventListener('input', render);
    [stFrom, stTo].forEach(function (el) { el.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); load(); } }); });
    document.getElementById('stClose').addEventListener('click', close);
    document.getElementById('stCancel').addEventListener('click', close);
    md.addEventListener('click', function (e) { if (e.target === md) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !md.hidden) close(); });
    document.getElementById('stExactRows').addEventListener('click', function (e) {
        var b = e.target.closest('.js-quick'); if (b) settle([parseInt(b.dataset.id, 10)], b);
    });
    stRows.addEventListener('click', function (e) {
        var tr = e.target.closest('tr[data-id]'); if (!tr) return;
        var cb = tr.querySelector('input'); if (e.target !== cb) cb.checked = !cb.checked;
        var id = tr.dataset.id, r = list.filter(function (x) { return String(x.id) === id; })[0];
        if (cb.checked) picked[id] = r.debit; else delete picked[id];
        tr.classList.toggle('pick', cb.checked); tally();
    });
    stSave.addEventListener('click', function () { settle(Object.keys(picked).map(Number), stSave); });
})();
</script>

<?php include 'footer.php'; ?>
