<?php
/**
 * stl_loan_grant_recon.php
 * ─────────────────────────────────────────────────────────────────────
 * STL Loan Granted  ←  Bank "Credit Arrangement" credits
 *
 *  List  : every credit of the Settings account / statement type whose
 *          description is like "Credit Arrangement/114490294053"
 *          (the number is the loan ref), in one list.
 *  Click : "Reconcile" on a line (or tick several and reconcile them):
 *            1. an STL loan that already matches this credit and is not yet
 *               linked (same loan ref, or same amount within ± window days
 *               of its grant / deposit date) is LINKED to the bank line;
 *            2. otherwise a NEW loan is created on STL Settlement:
 *                 deposit_date = grant_date = bank transaction date,
 *                 amount = credit, loan ref = Credit Arrangement number.
 *               When cheques were bulk-deposited on that date the loan shows
 *               under that deposit date; when there was no deposit the
 *               STL Settlement page shows the date as a red "Grant Date" row
 *               with the loan under it.
 *          The bank line gets status, category "STL Loan Reconcile" and a remark.
 *  Undo  : clears the bank line, and deletes the loan again when this page
 *          created it (only while it has no settlement payments).
 * ─────────────────────────────────────────────────────────────────────
 */
date_default_timezone_set('Asia/Colombo');

ob_start();
include_once 'config.php';
ob_end_clean();
include_once 'auth.php';
requireLogin();

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['slr_csrf'])) $_SESSION['slr_csrf'] = bin2hex(random_bytes(16));
$slr_user = isset($_SESSION['username']) ? (string)$_SESSION['username'] : 'unknown';

const SLR_SOURCE   = 'stl_grant_recon';
const SLR_CATEGORY = 'STL Loan Reconcile';
const SLR_STL_DAYS = 21;                       /* same default as STL Settlement */
$SLR_BANK_TYPES = ['BOC' => 'BOC', 'NDB' => 'NDB', 'SAMPATH' => 'Sampath'];

class SlrUserError extends Exception {}

/* ═════════════ HELPERS ═════════════ */
function slr_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function slr_q($conn, $sql) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } }
function slr_norm($s) { return trim(preg_replace('/\s+/u', ' ', (string)$s)); }
function slr_money($v) { return number_format((float)$v, 2); }
function slr_valid_date($d) { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && $d !== '0000-00-00' && strtotime($d) > 0; }
function slr_fmt_date($d) { return slr_valid_date((string)$d) ? date('d M Y', strtotime($d)) : '—'; }
function slr_fmt_dt($d) { return $d ? date('d M Y, h:i A', strtotime($d)) : '—'; }
function slr_days($a, $b) { return (int)round((strtotime($a) - strtotime($b)) / 86400); }
function slr_ids($ids) { $ids = array_filter(array_map('intval', (array)$ids)); return $ids ? implode(',', $ids) : '0'; }
function slr_has_col($conn, $t, $c) { $r = slr_q($conn, "SHOW COLUMNS FROM `$t` LIKE '" . mysqli_real_escape_string($conn, $c) . "'"); return $r && mysqli_num_rows($r) > 0; }
function slr_batch_no($id) { return 'SB-' . str_pad((string)(int)$id, 5, '0', STR_PAD_LEFT); }
function slr_range($from, $to) {
    $from = slr_valid_date((string)$from) ? $from : '';
    $to   = slr_valid_date((string)$to)   ? $to   : '';
    if ($from === '' && $to === '') return ['', ''];
    if ($from === '') $from = $to;
    if ($to === '')   $to   = $from;
    return $to < $from ? [$to, $from] : [$from, $to];
}
/* digits only, no leading zeros */
function slr_digits($s) { $d = ltrim(preg_replace('/\D/', '', (string)$s), '0'); return $d; }
/* loan ref ↔ bank ref: equal, or one ends with the other (6+ digits) */
function slr_ref_match($a, $b) {
    if ($a === '' || $b === '') return false;
    if ($a === $b) return true;
    $s = strlen($a) < strlen($b) ? $a : $b; $l = $s === $a ? $b : $a;
    return strlen($s) >= 6 && substr($l, -strlen($s)) === $s;
}
/* ref of a bank credit: "Credit Arrangement/114490294053" → 114490294053 */
function slr_bank_ref($t) {
    if (preg_match('/Credit\s+Arrangement\s*\/\s*(\d{5,})/i', (string)($t['description'] ?? '') . ' ' . (string)($t['reference'] ?? ''), $m)) return slr_digits($m[1]);
    return '';
}


/* ═════════════ TABLES ═════════════ */
function slr_ensure($conn) {
    slr_q($conn, "CREATE TABLE IF NOT EXISTS stl_grant_recon_settings (
        id INT NOT NULL PRIMARY KEY, bank_account_id INT NULL, bank_type VARCHAR(10) NULL,
        window_days SMALLINT NOT NULL DEFAULT 3, updated_by VARCHAR(100) NULL, updated_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    /* one row per reconciled loan (batch_id = 0 for reconciles made from the list) */
    slr_q($conn, "CREATE TABLE IF NOT EXISTS stl_grant_recon (
        id INT AUTO_INCREMENT PRIMARY KEY, batch_id INT NOT NULL DEFAULT 0, method VARCHAR(10) NOT NULL DEFAULT 'auto',
        bank_type VARCHAR(10) NULL, bank_account_id INT NULL,
        loan_id INT NOT NULL, loan_label VARCHAR(100) NULL, deposit_date DATE NULL, grant_date DATE NULL,
        loan_ref VARCHAR(150) NULL, bank_ref VARCHAR(60) NULL, ref_matched TINYINT(1) NOT NULL DEFAULT 0,
        loan_ref_filled TINYINT(1) NOT NULL DEFAULT 0, txn_date DATE NULL,
        loan_amount DECIMAL(18,2) NOT NULL DEFAULT 0, bank_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        difference DECIMAL(18,2) NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT 'reconciled',
        created_by VARCHAR(100) NULL, created_at DATETIME NOT NULL,
        UNIQUE KEY uq_loan (loan_id), INDEX idx_batch (batch_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!slr_has_col($conn, 'stl_grant_recon', 'loan_created'))
        slr_q($conn, "ALTER TABLE stl_grant_recon ADD COLUMN loan_created TINYINT(1) NOT NULL DEFAULT 0 AFTER loan_ref_filled");
    slr_q($conn, "CREATE TABLE IF NOT EXISTS stl_grant_recon_bank (
        id INT AUTO_INCREMENT PRIMARY KEY, recon_id INT NOT NULL, bank_txn_id INT UNSIGNED NOT NULL,
        transaction_date DATE NULL, description TEXT NULL, credit DECIMAL(18,2) NOT NULL DEFAULT 0,
        UNIQUE KEY uq_txn (bank_txn_id), INDEX idx_recon (recon_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach ([
        'recon_status' => "VARCHAR(20) NULL DEFAULT NULL", 'recon_source' => "VARCHAR(30) NULL DEFAULT NULL",
        'recon_category' => "VARCHAR(50) NULL DEFAULT NULL", 'recon_ref_id' => "INT NULL DEFAULT NULL",
        'recon_remark' => "TEXT NULL", 'recon_by' => "VARCHAR(100) NULL DEFAULT NULL", 'recon_at' => "DATETIME NULL DEFAULT NULL",
    ] as $c => $def) {
        if (!slr_has_col($conn, 'bank_statement_transactions', $c)) slr_q($conn, "ALTER TABLE bank_statement_transactions ADD COLUMN `$c` $def");
    }
}
slr_ensure($conn);

function slr_accounts($conn) {
    $acc = [];
    $r = slr_q($conn, "SELECT account_id, UPPER(bank_type) AS bt, MAX(statement_date) AS last_stmt, COUNT(*) AS uploads
                         FROM bank_statement_uploads GROUP BY account_id, UPPER(bank_type)");
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $id = (int)$row['account_id'];
        if ($id <= 0) continue;
        if (!isset($acc[$id])) $acc[$id] = ['label' => 'Account #' . $id, 'types' => [], 'last' => '', 'uploads' => 0];
        $acc[$id]['types'][$row['bt']] = $row['last_stmt'];
        $acc[$id]['uploads'] += (int)$row['uploads'];
        if ($row['last_stmt'] > $acc[$id]['last']) $acc[$id]['last'] = $row['last_stmt'];
    }
    if ($acc) {
        $r = slr_q($conn, "SELECT cba.id, CONCAT(COALESCE(NULLIF(b.bank_name,''), cba.bank_code, ''), ' / ',
                                  COALESCE(NULLIF(bb.branch_name,''), cba.branch_code, ''), ' (', cba.account_no, ')') AS label
                             FROM company_bank_accounts cba
                        LEFT JOIN banks b ON b.bank_code = cba.bank_code
                        LEFT JOIN bank_branches bb ON bb.bank_code = cba.bank_code AND bb.branch_code = cba.branch_code
                            WHERE cba.id IN (" . slr_ids(array_keys($acc)) . ")");
        while ($r && ($row = mysqli_fetch_assoc($r))) $acc[(int)$row['id']]['label'] = $row['label'];
    }
    foreach ($acc as &$a) ksort($a['types']);
    unset($a);
    uasort($acc, function ($x, $y) { return strcasecmp($x['label'], $y['label']); });
    return $acc;
}
function slr_settings($conn, $accounts) {
    $s = ['account' => 0, 'type' => '', 'window' => 3, 'saved_account' => 0, 'saved_type' => '', 'updated_by' => '', 'updated_at' => ''];
    $r = slr_q($conn, "SELECT * FROM stl_grant_recon_settings WHERE id = 1 LIMIT 1");
    if ($r && ($row = mysqli_fetch_assoc($r))) {
        $s['saved_account'] = (int)$row['bank_account_id'];
        $s['saved_type']    = strtoupper((string)$row['bank_type']);
        $s['window']        = max(0, min(30, (int)$row['window_days']));
        $s['updated_by']    = (string)$row['updated_by'];
        $s['updated_at']    = (string)$row['updated_at'];
    }
    if (isset($accounts[$s['saved_account']])) {
        $s['account'] = $s['saved_account'];
        if ($s['saved_type'] !== '' && isset($accounts[$s['account']]['types'][$s['saved_type']])) $s['type'] = $s['saved_type'];
    }
    return $s;
}
function slr_type_label($bt) { global $SLR_BANK_TYPES; $bt = strtoupper((string)$bt); return $bt === '' ? 'All types' : ($SLR_BANK_TYPES[$bt] ?? $bt); }


/* ═════════════ DATA ═════════════ */

/* bulk / normal-bulk cheque deposits per date (the STL Settlement deposit rows) */
function slr_deposits($conn, $dates) {
    $out = [];
    $dates = array_values(array_filter(array_unique($dates), 'slr_valid_date'));
    if (!$dates) return $out;
    $in = implode(',', array_map(function ($d) use ($conn) { return "'" . mysqli_real_escape_string($conn, $d) . "'"; }, $dates));
    $r = slr_q($conn, "SELECT deposit_date, SUM(total_amount) AS amt, COUNT(*) AS cnt FROM cheques
                        WHERE deposit_type IN ('bulk','normal_bulk') AND deposit_date IN ($in)
                          AND status IN ('deposited','cleared','returned') GROUP BY deposit_date");
    while ($r && ($x = mysqli_fetch_assoc($r))) $out[$x['deposit_date']] = ['amt' => (float)$x['amt'], 'cnt' => (int)$x['cnt']];
    return $out;
}

/* STL loans not linked to a bank line yet (candidates to link instead of creating a new loan) */
function slr_open_loans($conn) {
    $out = [];
    $r = slr_q($conn, "SELECT sr.id, sr.deposit_date, sr.grant_date, sr.loan_label, sr.loan_ref, sr.actual_grant_amount
                         FROM stl_records sr
                    LEFT JOIN stl_grant_recon g ON g.loan_id = sr.id
                        WHERE g.id IS NULL AND COALESCE(sr.actual_grant_amount, 0) > 0");
    while ($r && ($x = mysqli_fetch_assoc($r))) {
        $x['ref'] = slr_digits($x['loan_ref']);
        $x['amt'] = round((float)$x['actual_grant_amount'], 2);
        $x['day'] = slr_valid_date((string)$x['grant_date']) ? $x['grant_date'] : $x['deposit_date'];
        $out[(int)$x['id']] = $x;
    }
    return $out;
}

/* existing loan that this bank credit belongs to: same ref first, then same amount within ± window days */
function slr_find_loan($loans, $ref, $amt, $date, $window, $taken = []) {
    $best = null; $bestScore = -1;
    foreach ($loans as $id => $l) {
        if (isset($taken[$id])) continue;
        $refOk = $ref !== '' && slr_ref_match($l['ref'], $ref);
        $amtOk = abs($l['amt'] - $amt) < 0.005;
        $days  = abs(slr_days($date, $l['day']));
        if ($refOk && $l['ref'] !== '')           $score = 3000 - min($days, 999) + ($amtOk ? 500 : 0);
        elseif ($amtOk && $days <= $window && $l['ref'] === '') $score = 1000 - $days;
        else continue;
        if ($score > $bestScore) { $bestScore = $score; $best = $l; }
    }
    return $best;
}

/**
 * The Credit Arrangement list.
 * returns [rows, stats] — each row: txn (bank line), ref, amount, state (open|done|other), plan / recon
 */
function slr_list($conn, $cfg, $from, $to) {
    $rows = [];
    $acc = (int)$cfg['account']; $type = (string)$cfg['type'];
    $st = mysqli_prepare($conn, "SELECT t.* FROM bank_statement_transactions t JOIN bank_statement_uploads u ON u.id = t.upload_id
                                  WHERE u.account_id = ? " . ($type !== '' ? "AND UPPER(u.bank_type) = ?" : "AND ? = ''") . "
                                    AND t.transaction_date BETWEEN ? AND ? AND t.credit > 0
                                    AND CONCAT(COALESCE(t.description,''), ' ', COALESCE(t.reference,'')) LIKE '%Credit%Arrangement%'
                                  ORDER BY t.transaction_date DESC, t.id DESC");
    mysqli_stmt_bind_param($st, 'isss', $acc, $type, $from, $to);
    mysqli_stmt_execute($st);
    $rs = mysqli_stmt_get_result($st);
    $seen = [];
    while ($rs && ($tx = mysqli_fetch_assoc($rs))) {
        $ref = slr_bank_ref($tx);
        if ($ref === '') continue;
        /* the same line uploaded twice (overlapping statements): keep one, the reconciled one if any */
        $dk = $tx['transaction_date'] . '|' . slr_norm($tx['description']) . '|' . $tx['credit'] . '|' . $tx['balance'];
        if (isset($seen[$dk])) {
            if (!empty($tx['recon_status']) && empty($rows[$seen[$dk]]['txn']['recon_status'])) $rows[$seen[$dk]]['txn'] = $tx;
            continue;
        }
        $seen[$dk] = count($rows);
        $rows[] = ['txn' => $tx, 'ref' => $ref, 'amount' => round((float)$tx['credit'], 2), 'date' => $tx['transaction_date']];
    }
    mysqli_stmt_close($st);

    /* reconciled links */
    $tids = array_map(function ($r) { return (int)$r['txn']['id']; }, $rows);
    $links = [];
    if ($tids) {
        $r = slr_q($conn, "SELECT rb.bank_txn_id, g.*, sr.id AS loan_exists, sr.loan_label AS cur_label, sr.deposit_date AS cur_dep,
                                  sr.grant_date AS cur_grant, sr.actual_grant_amount AS cur_amt
                             FROM stl_grant_recon_bank rb JOIN stl_grant_recon g ON g.id = rb.recon_id
                        LEFT JOIN stl_records sr ON sr.id = g.loan_id
                            WHERE rb.bank_txn_id IN (" . slr_ids($tids) . ")");
        while ($r && ($x = mysqli_fetch_assoc($r))) $links[(int)$x['bank_txn_id']] = $x;
    }

    /* plan for the open lines (oldest first, so two equal credits take two different loans) */
    $loans = slr_open_loans($conn);
    $taken = [];
    $depDates = [];
    foreach ($rows as $r) $depDates[] = $r['date'];
    foreach ($links as $l) if ($l['cur_dep']) $depDates[] = $l['cur_dep'];
    $deps = slr_deposits($conn, $depDates);

    $stats = ['open' => [0, 0.0], 'done' => [0, 0.0], 'other' => [0, 0.0]];
    for ($i = count($rows) - 1; $i >= 0; $i--) {
        $r =& $rows[$i];
        $tid = (int)$r['txn']['id'];
        if (isset($links[$tid])) {
            $r['state'] = 'done';
            $r['recon'] = $links[$tid];
            $d = $links[$tid]['cur_dep'] ?: $links[$tid]['deposit_date'];
            $r['dep'] = $deps[$d] ?? null;
        } elseif (!empty($r['txn']['recon_status'])) {
            $r['state'] = 'other';
        } else {
            $r['state'] = 'open';
            $m = slr_find_loan($loans, $r['ref'], $r['amount'], $r['date'], (int)$cfg['window'], $taken);
            if ($m) {
                $taken[(int)$m['id']] = true;
                $r['plan'] = ['type' => 'link', 'loan' => $m, 'dep' => $deps[$m['deposit_date']] ?? null];
            } else {
                $r['plan'] = ['type' => 'new', 'dep' => $deps[$r['date']] ?? null];
            }
        }
        $stats[$r['state']][0]++; $stats[$r['state']][1] += $r['amount'];
        unset($r);
    }
    return [$rows, $stats];
}

/* ═════════════ RECONCILE / UNDO ═════════════ */
function slr_reconcile($conn, $cfg, $tid, $user) {
    $tid = (int)$tid;
    $r = mysqli_query($conn, "SELECT t.*, u.account_id, u.bank_type AS stmt_type FROM bank_statement_transactions t
                               JOIN bank_statement_uploads u ON u.id = t.upload_id WHERE t.id = $tid FOR UPDATE");
    $tx = $r ? mysqli_fetch_assoc($r) : null;
    if (!$tx) throw new SlrUserError('This bank line no longer exists. Reload the page.');
    if ((int)$tx['account_id'] !== (int)$cfg['account'] || ($cfg['type'] !== '' && strtoupper($tx['stmt_type']) !== $cfg['type']))
        throw new SlrUserError('This bank line is not from the Settings account / statement type.');
    if ((float)$tx['credit'] <= 0) throw new SlrUserError('This bank line is not a credit.');
    if (!empty($tx['recon_status'])) throw new SlrUserError('This bank line is already reconciled.');
    $bref = slr_bank_ref($tx);
    if ($bref === '') throw new SlrUserError('This is not a Credit Arrangement line.');

    $amt  = round((float)$tx['credit'], 2);
    $date = $tx['transaction_date'];
    $now  = date('Y-m-d H:i:s');

    $loan = slr_find_loan(slr_open_loans($conn), $bref, $amt, $date, (int)$cfg['window']);
    $created = 0; $fill = 0;
    if ($loan) {
        $lid = (int)$loan['id'];
        $r = mysqli_query($conn, "SELECT * FROM stl_records WHERE id = $lid FOR UPDATE");
        $loan = $r ? mysqli_fetch_assoc($r) : null;
        if (!$loan) throw new SlrUserError('The matching loan was just deleted. Try again.');
        if (trim((string)$loan['loan_ref']) === '') {
            $fill = 1;
            $e = mysqli_real_escape_string($conn, $bref);
            mysqli_query($conn, "UPDATE stl_records SET loan_ref = '$e' WHERE id = $lid AND (loan_ref IS NULL OR loan_ref = '')");
        }
    } else {
        /* new loan on STL Settlement, dated by the bank transaction date */
        $r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM stl_records WHERE deposit_date = '" . mysqli_real_escape_string($conn, $date) . "'");
        $n = $r ? (int)mysqli_fetch_assoc($r)['c'] : 0;
        $label = 'Loan #' . ($n + 1);
        $days  = SLR_STL_DAYS;
        $note  = 'Created from bank line #' . $tid . ' (Credit Arrangement/' . $bref . ') on STL Loan Granted Recon by ' . $user . ' on ' . $now;
        $st = mysqli_prepare($conn, "INSERT INTO stl_records (deposit_date, loan_label, actual_grant_amount, grant_date, loan_ref, stl_days, notes)
                                     VALUES (?,?,?,?,?,?,?)");
        mysqli_stmt_bind_param($st, 'ssdssis', $date, $label, $amt, $date, $bref, $days, $note);
        if (!mysqli_stmt_execute($st)) throw new Exception('loan');
        $lid = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($st);
        $created = 1;
        $loan = ['id' => $lid, 'deposit_date' => $date, 'grant_date' => $date, 'loan_label' => $label, 'loan_ref' => $bref, 'actual_grant_amount' => $amt];
    }

    $label   = $loan['loan_label'] ?: ('Loan #' . $lid);
    $loanAmt = round((float)$loan['actual_grant_amount'], 2);
    $diff    = round($amt - $loanAmt, 2);
    $status  = abs($diff) < 0.005 ? 'reconciled' : 'difference';
    $loanRef = $fill ? '' : trim((string)$loan['loan_ref']);
    $refOk   = ($created || $fill || slr_ref_match(slr_digits($loanRef), $bref)) ? 1 : 0;
    $ddate   = $loan['deposit_date'];
    $gdate   = slr_valid_date((string)$loan['grant_date']) ? $loan['grant_date'] : null;
    $method  = $created ? 'create' : 'link';
    $acc = (int)$cfg['account']; $bt = $cfg['type']; $zero = 0; $lrDb = $created ? $bref : $loanRef;

    $st = mysqli_prepare($conn, "INSERT INTO stl_grant_recon (batch_id, method, bank_type, bank_account_id, loan_id, loan_label, deposit_date, grant_date,
                                    loan_ref, bank_ref, ref_matched, loan_ref_filled, loan_created, txn_date, loan_amount, bank_amount, difference, status, created_by, created_at)
                                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    mysqli_stmt_bind_param($st, 'issiisssssiiisdddsss', $zero, $method, $bt, $acc, $lid, $label, $ddate, $gdate,
                           $lrDb, $bref, $refOk, $fill, $created, $date, $loanAmt, $amt, $diff, $status, $user, $now);
    if (!mysqli_stmt_execute($st)) throw new SlrUserError('This loan is already reconciled with another bank line.');
    $rid = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($st);

    $desc = slr_norm($tx['description'] . ($tx['reference'] ? ' ' . $tx['reference'] : ''));
    $st = mysqli_prepare($conn, "INSERT INTO stl_grant_recon_bank (recon_id, bank_txn_id, transaction_date, description, credit) VALUES (?,?,?,?,?)");
    mysqli_stmt_bind_param($st, 'iissd', $rid, $tid, $date, $desc, $amt);
    if (!mysqli_stmt_execute($st)) throw new SlrUserError('This bank line is already reconciled.');
    mysqli_stmt_close($st);

    $remark = 'STL Recon #' . $rid . ' | ' . ($created ? 'New STL loan created' : 'Linked to existing STL loan')
            . ' | ' . $label . ' | Loan ref: ' . $bref
            . ' | ' . (slr_deposits($conn, [$ddate]) ? 'Deposit date: ' . $ddate : 'Grant date: ' . ($gdate ?: $ddate) . ' (no deposit)')
            . ' | Loan Rs ' . slr_money($loanAmt) . ' | Bank Rs ' . slr_money($amt)
            . ($status !== 'reconciled' ? ' | Difference Rs ' . slr_money($diff) : '')
            . ' | by ' . $user . ' on ' . $now;
    $src = SLR_SOURCE; $cat = SLR_CATEGORY;
    $st = mysqli_prepare($conn, "UPDATE bank_statement_transactions SET recon_status = ?, recon_source = ?, recon_category = ?, recon_ref_id = ?,
                                    recon_remark = ?, recon_by = ?, recon_at = ? WHERE id = ? AND (recon_status IS NULL OR recon_status = '')");
    mysqli_stmt_bind_param($st, 'sssisssi', $status, $src, $cat, $rid, $remark, $user, $now, $tid);
    mysqli_stmt_execute($st);
    if (mysqli_stmt_affected_rows($st) !== 1) throw new SlrUserError('This bank line was just reconciled by someone else.');
    mysqli_stmt_close($st);

    return ['recon_id' => $rid, 'loan_id' => $lid, 'created' => $created, 'label' => $label, 'deposit_date' => $ddate, 'status' => $status];
}

/* undo one reconcile: clear the bank line(s); delete the loan when this page created it */
function slr_undo($conn, $rid) {
    $rid = (int)$rid;
    $r = mysqli_query($conn, "SELECT * FROM stl_grant_recon WHERE id = $rid FOR UPDATE");
    $g = $r ? mysqli_fetch_assoc($r) : null;
    if (!$g) throw new SlrUserError('This reconcile was already removed. Reload the page.');
    $lid = (int)$g['loan_id'];
    $delLoan = false;
    if ((int)($g['loan_created'] ?? 0) === 1) {
        $r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM stl_settlement_payments WHERE stl_record_id = $lid");
        $n = $r ? (int)mysqli_fetch_assoc($r)['c'] : 0;
        if ($n > 0) throw new SlrUserError('The loan has ' . $n . ' settlement payment(s). Delete them on STL Settlement first.');
        $delLoan = true;
    }
    mysqli_query($conn, "UPDATE bank_statement_transactions SET recon_status = NULL, recon_source = NULL, recon_category = NULL,
                            recon_ref_id = NULL, recon_remark = NULL, recon_by = NULL, recon_at = NULL
                          WHERE recon_source = '" . SLR_SOURCE . "' AND recon_ref_id = $rid");
    if ($delLoan) {
        mysqli_query($conn, "DELETE FROM stl_records WHERE id = $lid");
    } elseif ((int)$g['loan_ref_filled'] === 1) {       /* undo the loan ref taken from the bank */
        mysqli_query($conn, "UPDATE stl_records SET loan_ref = NULL WHERE id = $lid AND loan_ref = '" . mysqli_real_escape_string($conn, (string)$g['bank_ref']) . "'");
    }
    mysqli_query($conn, "DELETE FROM stl_grant_recon_bank WHERE recon_id = $rid");
    mysqli_query($conn, "DELETE FROM stl_grant_recon WHERE id = $rid");
    if ((int)$g['batch_id'] > 0) {   /* reconcile saved by the earlier batch screen */
        slr_q($conn, "DELETE b FROM stl_grant_recon_batches b WHERE b.id = " . (int)$g['batch_id'] . " AND NOT EXISTS (SELECT 1 FROM stl_grant_recon x WHERE x.batch_id = b.id)");
    }
    return $delLoan;
}

/* ═════════════ AJAX ═════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    if (!hash_equals((string)$_SESSION['slr_csrf'], (string)($_POST['csrf'] ?? ''))) { echo json_encode(['ok' => false, 'msg' => 'Security check failed. Reload the page and try again.']); exit; }
    $act = (string)$_POST['ajax'];
    $JSON = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    if ($act === 'save_settings') {
        $accounts = slr_accounts($conn);
        $acc = (int)($_POST['account_id'] ?? 0); $bt = strtoupper(trim((string)($_POST['bank_type'] ?? ''))); $win = max(0, min(30, (int)($_POST['window'] ?? 3)));
        if (!isset($accounts[$acc])) { echo json_encode(['ok' => false, 'msg' => 'Select a bank account that has bank statements uploaded.']); exit; }
        if ($bt !== '' && !isset($accounts[$acc]['types'][$bt])) { echo json_encode(['ok' => false, 'msg' => 'No ' . slr_type_label($bt) . ' statements are uploaded for this account.']); exit; }
        $btDb = $bt !== '' ? $bt : null; $now = date('Y-m-d H:i:s');
        $st = mysqli_prepare($conn, "INSERT INTO stl_grant_recon_settings (id, bank_account_id, bank_type, window_days, updated_by, updated_at) VALUES (1, ?, ?, ?, ?, ?)
                                     ON DUPLICATE KEY UPDATE bank_account_id = VALUES(bank_account_id), bank_type = VALUES(bank_type),
                                     window_days = VALUES(window_days), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)");
        mysqli_stmt_bind_param($st, 'isiss', $acc, $btDb, $win, $slr_user, $now);
        $ok = mysqli_stmt_execute($st);
        echo json_encode($ok ? ['ok' => true, 'msg' => 'Settings saved.'] : ['ok' => false, 'msg' => 'Could not save settings.']);
        exit;
    }

    if ($act === 'reconcile') {
        $cfg = slr_settings($conn, slr_accounts($conn));
        if ($cfg['account'] <= 0) { echo json_encode(['ok' => false, 'msg' => 'Set the bank account in Settings first.']); exit; }
        $ids = json_decode((string)($_POST['ids'] ?? '[]'), true);
        $ids = is_array($ids) ? array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, 300) : [];
        if (!$ids) { echo json_encode(['ok' => false, 'msg' => 'Select a bank line.']); exit; }
        /* oldest first, so loan numbers follow the bank dates */
        $order = [];
        $r = slr_q($conn, "SELECT id FROM bank_statement_transactions WHERE id IN (" . slr_ids($ids) . ") ORDER BY transaction_date, id");
        while ($r && ($x = mysqli_fetch_assoc($r))) $order[] = (int)$x['id'];
        $done = []; $errors = [];
        foreach ($order as $tid) {
            mysqli_begin_transaction($conn);
            try {
                $done[$tid] = slr_reconcile($conn, $cfg, $tid, $slr_user);
                mysqli_commit($conn);
            } catch (SlrUserError $e) { mysqli_rollback($conn); $errors[$tid] = $e->getMessage(); }
              catch (Throwable $e)    { mysqli_rollback($conn); $errors[$tid] = 'Could not be saved (database error).'; }
        }
        $created = count(array_filter($done, function ($d) { return $d['created']; }));
        $msg = count($done) . ' line(s) reconciled' . ($created ? ', ' . $created . ' new STL loan(s) created' : '') . (count($done) - $created ? ', ' . (count($done) - $created) . ' linked to existing loan(s)' : '') . '.';
        if ($errors) $msg .= ' ' . count($errors) . ' could not be reconciled.';
        echo json_encode(['ok' => (bool)$done, 'msg' => $done ? $msg : 'Nothing was reconciled.', 'done' => $done, 'errors' => $errors], $JSON);
        exit;
    }

    if ($act === 'undo') {
        mysqli_begin_transaction($conn);
        try {
            $del = slr_undo($conn, (int)($_POST['id'] ?? 0));
            mysqli_commit($conn);
            echo json_encode(['ok' => true, 'msg' => $del ? 'Reconcile removed and the created STL loan deleted.' : 'Reconcile removed. The STL loan was kept.']);
        } catch (SlrUserError $e) { mysqli_rollback($conn); echo json_encode(['ok' => false, 'msg' => $e->getMessage()]); }
          catch (Throwable $e)    { mysqli_rollback($conn); echo json_encode(['ok' => false, 'msg' => 'Could not remove the reconcile. Try again.']); }
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Unknown action.']); exit;
}

/* ═════════════ PAGE DATA ═════════════ */
$accounts = slr_accounts($conn);
$cfg      = slr_settings($conn, $accounts);
$tab      = ($_GET['tab'] ?? '') === 'settings' ? 'settings' : 'list';
list($f_from, $f_to) = slr_range($_GET['from'] ?? date('Y-m-d', strtotime('-90 days')), $_GET['to'] ?? date('Y-m-d'));
if ($f_from === '') { $f_from = date('Y-m-d', strtotime('-90 days')); $f_to = date('Y-m-d'); }
$f_state = in_array($_GET['state'] ?? '', ['open', 'done', 'other'], true) ? $_GET['state'] : '';
$f_q     = trim((string)($_GET['q'] ?? ''));

$rows = []; $stats = ['open' => [0, 0], 'done' => [0, 0], 'other' => [0, 0]];
if ($tab === 'list' && $cfg['account'] > 0) list($rows, $stats) = slr_list($conn, $cfg, $f_from, $f_to);
$shown = array_values(array_filter($rows, function ($r) use ($f_state, $f_q) {
    if ($f_state !== '' && $r['state'] !== $f_state) return false;
    if ($f_q !== '') {
        $hay = strtolower($r['ref'] . ' ' . $r['txn']['description'] . ' ' . $r['txn']['reference'] . ' ' . $r['date'] . ' ' . number_format($r['amount'], 2, '.', '')
             . ' ' . ($r['recon']['loan_label'] ?? '') . ' ' . ($r['plan']['loan']['loan_label'] ?? ''));
        if (strpos($hay, strtolower($f_q)) === false) return false;
    }
    return true;
}));

function slr_acc_label($accounts, $id) { return $accounts[(int)$id]['label'] ?? ('Account #' . (int)$id); }
function slr_dep_chip($date, $dep) {
    if ($dep) return '<span class="chip dep" title="Bulk / normal-bulk cheque deposit on this date"><i class="fa-solid fa-building-columns"></i> Deposit ' . slr_fmt_date($date)
                   . '</span><div class="muted">' . (int)$dep['cnt'] . ' cheque(s) · Rs ' . slr_money($dep['amt']) . '</div>';
    return '<span class="chip grant" title="No cheque deposit on this date — shown as a Grant Date row on STL Settlement"><i class="fa-solid fa-calendar-plus"></i> Grant Date ' . slr_fmt_date($date) . '</span><div class="muted">No deposit on this date</div>';
}

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
.dbr .ref.bad { color: #92400e; background: #fffbeb; border-color: #fde68a; }
.dbr .mr-list tr.same td:nth-child(4) .ref { background: #0f766e; color: #fff; border-color: #0f766e; }
</style>
<style>
.dbr .chip { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 8px; font-size: 11.5px; font-weight: 700; white-space: nowrap; }
.dbr .chip.dep { background: linear-gradient(135deg, #1e1b4b, #312e81); color: #fff; }
.dbr .chip.grant { background: linear-gradient(135deg, #991b1b, #dc2626 55%, #f87171); color: #fff; box-shadow: 0 2px 6px rgba(220,38,38,.3); }
.dbr .chip.link { background: #eef2ff; color: #3730a3; border: 1px solid #c7d2fe; }
.dbr .chip.new { background: #f0fdfa; color: #0f766e; border: 1px solid #99f6e4; }
.dbr .plan { display: flex; flex-direction: column; gap: 4px; align-items: flex-start; }
.dbr tr.st-done td { background: #fbfefc; }
.dbr tr.st-other td { opacity: .6; }
.dbr .stat.clickable { cursor: pointer; text-decoration: none; color: inherit; display: block; }
.dbr .stat.clickable:hover, .dbr .stat.on { border-color: #0f766e; box-shadow: 0 0 0 2px rgba(15,118,110,.12); }
.dbr td.act { white-space: nowrap; text-align: right; }
.dbr .btn-rec { background: #0f766e; color: #fff; }
.dbr .btn-rec:hover { background: #115e59; }
.dbr input.pick { width: 16px; height: 16px; accent-color: #0f766e; cursor: pointer; }
.dbr .legend { display: flex; flex-wrap: wrap; gap: 8px 18px; align-items: center; margin: 10px 0 0; color: #555; font-size: 12px; }
</style>

<div class="dbr">
    <h1><i class="fa-solid fa-hand-holding-dollar"></i> STL Loan Granted ↔ Bank</h1>
    <p class="sub">Every <code>Credit Arrangement/…</code> credit of the bank account in one list. <b>Reconcile</b> creates the STL loan on STL Settlement from the bank line
        (on its transaction date) and marks the bank line reconciled.
        <a href="stl_settlement.php" class="blink" style="margin-left:6px;"><i class="fa-solid fa-arrow-right"></i> STL Settlement</a></p>

    <div class="tabs">
        <a href="?tab=list" class="<?php echo $tab === 'list' ? 'on' : ''; ?>"><i class="fa-solid fa-list-check"></i> Credit Arrangements</a>
        <a href="?tab=settings" class="<?php echo $tab === 'settings' ? 'on' : ''; ?>"><i class="fa-solid fa-gear"></i> Settings</a>
    </div>

<?php if ($tab === 'list'): ?>

    <?php if ($cfg['account'] <= 0): ?>
        <div class="alert warn">No bank account is set yet. <a href="?tab=settings">Open Settings</a> and choose the account the STL loans are credited to.</div>
    <?php else: ?>
    <div class="setbar">
        <i class="fa-solid fa-building-columns"></i>
        <span class="pill"><?php echo slr_h(slr_acc_label($accounts, $cfg['account'])); ?></span>
        <span class="pill"><?php echo slr_h(slr_type_label($cfg['type'])); ?></span>
        <span class="pill">Link existing loans by ref, or amount ± <?php echo (int)$cfg['window']; ?> day(s)</span>
        <a href="?tab=settings" class="setlink"><i class="fa-solid fa-gear"></i> Change</a>
    </div>

    <form class="filters" method="get">
        <input type="hidden" name="tab" value="list">
        <div><label class="f" for="f_from">Bank date from</label><input type="date" id="f_from" name="from" value="<?php echo slr_h($f_from); ?>"></div>
        <div><label class="f" for="f_to">Bank date to</label><input type="date" id="f_to" name="to" value="<?php echo slr_h($f_to); ?>"></div>
        <div><label class="f" for="f_state">Status</label>
            <select id="f_state" name="state">
                <option value="">All</option>
                <option value="open" <?php echo $f_state === 'open' ? 'selected' : ''; ?>>Not reconciled</option>
                <option value="done" <?php echo $f_state === 'done' ? 'selected' : ''; ?>>Reconciled</option>
                <option value="other" <?php echo $f_state === 'other' ? 'selected' : ''; ?>>Reconciled on another page</option>
            </select></div>
        <div class="wide"><label class="f" for="f_q">Search</label><input type="search" id="f_q" name="q" value="<?php echo slr_h($f_q); ?>" placeholder="Loan ref, amount, date, loan label…"></div>
        <div><button class="btn btn-dark" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Show</button></div>
    </form>

    <div class="stats">
        <?php foreach ([['open', 'Not reconciled', 'r'], ['done', 'Reconciled', 'g'], ['other', 'Reconciled on another page', '']] as $sx):
            $qs = http_build_query(['tab' => 'list', 'from' => $f_from, 'to' => $f_to, 'state' => $f_state === $sx[0] ? '' : $sx[0], 'q' => $f_q]); ?>
        <a class="stat clickable <?php echo $sx[2]; ?> <?php echo $f_state === $sx[0] ? 'on' : ''; ?>" href="?<?php echo slr_h($qs); ?>">
            <div class="lbl"><?php echo $sx[1]; ?></div>
            <div class="val"><?php echo slr_money($stats[$sx[0]][1]); ?></div>
            <div class="cnt"><?php echo (int)$stats[$sx[0]][0]; ?> line(s)</div>
        </a>
        <?php endforeach; ?>
    </div>
    <div class="legend">
        <span><span class="chip dep"><i class="fa-solid fa-building-columns"></i> Deposit</span> loan goes under that cheque deposit date</span>
        <span><span class="chip grant"><i class="fa-solid fa-calendar-plus"></i> Grant Date</span> no deposit that day — own Grant Date row on STL Settlement</span>
    </div>

    <h2><i class="fa-solid fa-list-ul" style="color:#0f766e"></i> Credit Arrangement lines <span class="count"><?php echo count($shown); ?></span>
        <span class="muted" style="font-weight:400;"><?php echo slr_fmt_date($f_from); ?> – <?php echo slr_fmt_date($f_to); ?></span></h2>
    <div class="card">
        <table>
            <thead><tr>
                <th style="width:34px;"><input type="checkbox" class="pick" id="pickAll" title="Select all not reconciled" aria-label="Select all not reconciled"></th>
                <th>Bank date</th>
                <th>Description</th>
                <th>Loan ref</th>
                <th class="num">Amount</th>
                <th>STL Settlement</th>
                <th>Status</th>
                <th class="num"></th>
            </tr></thead>
            <tbody>
            <?php if (!$shown): ?>
                <tr><td colspan="8" class="empty">No Credit Arrangement credits<?php echo $f_state !== '' || $f_q !== '' ? ' match the filter' : ' in this date range'; ?>.</td></tr>
            <?php endif; ?>
            <?php foreach ($shown as $r): $tx = $r['txn']; $tid = (int)$tx['id']; ?>
                <tr class="st-<?php echo $r['state']; ?>" data-tid="<?php echo $tid; ?>">
                    <td><?php if ($r['state'] === 'open'): ?><input type="checkbox" class="pick js-pick" value="<?php echo $tid; ?>" aria-label="Select"><?php endif; ?></td>
                    <td style="white-space:nowrap;"><b><?php echo slr_fmt_date($r['date']); ?></b></td>
                    <td><span class="desc"><?php echo slr_h(slr_norm($tx['description'] . ' ' . $tx['reference'])); ?></span></td>
                    <td><span class="ref"><?php echo slr_h($r['ref']); ?></span></td>
                    <td class="num"><b><?php echo slr_money($r['amount']); ?></b></td>
                    <td>
                    <?php if ($r['state'] === 'open'): $p = $r['plan']; ?>
                        <div class="plan">
                        <?php if ($p['type'] === 'link'): $l = $p['loan']; ?>
                            <span class="chip link"><i class="fa-solid fa-link"></i> Link to <?php echo slr_h($l['loan_label'] ?: 'Loan #' . $l['id']); ?></span>
                            <div class="muted">Existing loan Rs <?php echo slr_money($l['amt']); ?><?php echo $l['ref'] !== '' ? ' · ref ' . slr_h($l['loan_ref']) : ''; ?>
                                <?php echo abs($l['amt'] - $r['amount']) >= 0.005 ? ' · <b style="color:#b91c1c">amount differs</b>' : ''; ?></div>
                            <?php echo slr_dep_chip($l['deposit_date'], $p['dep']); ?>
                        <?php else: ?>
                            <span class="chip new"><i class="fa-solid fa-plus"></i> New loan Rs <?php echo slr_money($r['amount']); ?></span>
                            <?php echo slr_dep_chip($r['date'], $p['dep']); ?>
                        <?php endif; ?>
                        </div>
                    <?php elseif ($r['state'] === 'done'): $g = $r['recon']; $dd = $g['cur_dep'] ?: $g['deposit_date']; ?>
                        <div class="plan">
                            <?php if ($g['loan_exists']): ?>
                                <b><?php echo slr_h($g['cur_label'] ?: $g['loan_label']); ?></b>
                                <div class="muted">Loan Rs <?php echo slr_money($g['cur_amt']); ?><?php echo (int)($g['loan_created'] ?? 0) === 1 ? ' · created here' : ' · linked'; ?></div>
                                <?php echo slr_dep_chip($dd, $r['dep']); ?>
                            <?php else: ?>
                                <span class="badge b-no">Loan was deleted</span>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <span class="muted"><?php echo slr_h($tx['recon_category'] ?: 'Other page'); ?></span>
                    <?php endif; ?>
                    </td>
                    <td>
                    <?php if ($r['state'] === 'open'): ?>
                        <span class="badge b-no">Not reconciled</span>
                    <?php elseif ($r['state'] === 'done'): $g = $r['recon']; ?>
                        <span class="badge <?php echo $g['status'] === 'reconciled' ? 'b-ok' : 'b-diff'; ?>"><?php echo $g['status'] === 'reconciled' ? 'Reconciled' : 'Difference ' . slr_money($g['difference']); ?></span>
                        <div class="muted"><?php echo slr_h($g['created_by']); ?> · <?php echo slr_h(slr_fmt_dt($g['created_at'])); ?></div>
                    <?php else: ?>
                        <span class="badge b-auto"><?php echo slr_h(ucfirst((string)$tx['recon_status'])); ?></span>
                        <div class="muted"><?php echo slr_h($tx['recon_by']); ?></div>
                    <?php endif; ?>
                        <div class="rowmsg"></div>
                    </td>
                    <td class="act">
                    <?php if ($r['state'] === 'open'): ?>
                        <button type="button" class="btn btn-sm btn-rec js-rec" data-id="<?php echo $tid; ?>"><i class="fa-solid fa-check-double"></i> Reconcile</button>
                    <?php elseif ($r['state'] === 'done'): ?>
                        <button type="button" class="btn btn-sm btn-danger js-undo" data-id="<?php echo (int)$r['recon']['id']; ?>"
                                data-created="<?php echo (int)($r['recon']['loan_created'] ?? 0); ?>" title="Remove this reconcile"><i class="fa-solid fa-rotate-left"></i> Undo</button>
                    <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="savebar noprint">
        <div class="grow"><b id="selCount">0</b> line(s) selected · Rs <b id="selAmt">0.00</b></div>
        <a href="stl_settlement.php" class="btn btn-light"><i class="fa-solid fa-arrow-right"></i> Open STL Settlement</a>
        <button type="button" class="btn btn-dark" id="recSelected" disabled><i class="fa-solid fa-check-double"></i> Reconcile selected</button>
    </div>
    <?php endif; ?>

<?php else: ?>

    <div class="setcard">
        <h3>Reconcile settings</h3>
        <p class="muted" style="margin:0 0 14px;">Choose the account the STL loans are credited to (where the Credit Arrangement lines are). Only accounts with bank statements are listed. Statement type is optional.</p>
        <?php if (!$accounts): ?>
            <div class="alert warn">No bank statements are uploaded yet. Upload one in <a href="bank_statements.php">Bank Statements</a> first.</div>
        <?php else: ?>
        <div class="setgrid">
            <div class="wide">
                <label class="f" for="set_account">Bank account <span style="color:#b91c1c">*</span></label>
                <select id="set_account">
                    <option value="">Select…</option>
                    <?php foreach ($accounts as $id => $a): ?>
                        <option value="<?php echo $id; ?>" data-types="<?php echo slr_h(json_encode($a['types'])); ?>" <?php echo $cfg['saved_account'] === $id ? 'selected' : ''; ?>>
                            <?php echo slr_h($a['label']); ?> — <?php echo (int)$a['uploads']; ?> statement(s), latest <?php echo slr_fmt_date($a['last']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label class="f" for="set_type">Statement type (optional)</label><select id="set_type" data-saved="<?php echo slr_h($cfg['saved_type']); ?>"><option value="">All types</option></select></div>
            <div><label class="f" for="set_window">Date window (± days) to link an existing loan with the same amount</label><input type="text" id="set_window" inputmode="numeric" value="<?php echo (int)$cfg['window']; ?>"></div>
        </div>
        <div style="display:flex;gap:10px;align-items:center;margin-top:16px;flex-wrap:wrap;">
            <button type="button" class="btn btn-dark" id="setSave"><i class="fa-solid fa-floppy-disk"></i> Save settings</button>
            <?php if ($cfg['updated_at']): ?><span class="muted">Last saved <?php echo slr_h(slr_fmt_dt($cfg['updated_at'])); ?> by <?php echo slr_h($cfg['updated_by'] ?: '—'); ?></span><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

<?php endif; ?>
    <div class="toast" id="toast" role="status" aria-live="polite"></div>
</div>

<script>
(function () {
    var CSRF = <?php echo json_encode($_SESSION['slr_csrf']); ?>;
    var toastEl = document.getElementById('toast'), toastT;
    function toast(msg, type) { toastEl.textContent = msg; toastEl.className = 'toast show ' + (type || 'ok'); clearTimeout(toastT); toastT = setTimeout(function () { toastEl.className = 'toast'; }, 4500); }
    function post(data) {
        var fd = new FormData(); fd.append('csrf', CSRF);
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        return fetch(location.pathname, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); });
    }
    function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    function money(n) { return Number(n).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }

    var setAcc = document.getElementById('set_account'), setType = document.getElementById('set_type');
    if (setAcc && setType) {
        var typeNames = <?php echo json_encode($SLR_BANK_TYPES); ?>;
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
            var w = parseInt(document.getElementById('set_window').value, 10);
            if (isNaN(w) || w < 0 || w > 30) { toast('Date window must be 0 to 30 days.', 'err'); return; }
            b.disabled = true;
            post({ajax: 'save_settings', account_id: setAcc.value, bank_type: setType.value, window: w})
            .then(function (res) { b.disabled = false; toast(res.msg || 'Done', res.ok ? 'ok' : 'err'); if (res.ok) setTimeout(function () { location.href = '?tab=list'; }, 700); })
            .catch(function () { b.disabled = false; toast('Could not reach the server. Try again.', 'err'); });
        });
    }


    /* ── list ── */
    var picks = Array.prototype.slice.call(document.querySelectorAll('.js-pick'));
    var pickAll = document.getElementById('pickAll'), recSel = document.getElementById('recSelected');
    function amountOf(cb) { var td = cb.closest('tr').querySelector('td.num b'); return td ? parseFloat(td.textContent.replace(/,/g, '')) || 0 : 0; }
    function refresh() {
        var on = picks.filter(function (c) { return c.checked; });
        document.getElementById('selCount') && (document.getElementById('selCount').textContent = on.length);
        document.getElementById('selAmt') && (document.getElementById('selAmt').textContent = money(on.reduce(function (s, c) { return s + amountOf(c); }, 0)));
        if (recSel) recSel.disabled = on.length === 0;
        if (pickAll) { pickAll.checked = picks.length > 0 && on.length === picks.length; pickAll.disabled = picks.length === 0; }
    }
    picks.forEach(function (c) { c.addEventListener('change', refresh); });
    if (pickAll) pickAll.addEventListener('change', function () { picks.forEach(function (c) { c.checked = pickAll.checked; }); refresh(); });
    refresh();

    function reconcile(ids, btn) {
        if (btn) btn.disabled = true;
        post({ajax: 'reconcile', ids: JSON.stringify(ids)}).then(function (res) {
            Object.keys(res.errors || {}).forEach(function (tid) {
                var tr = document.querySelector('tr[data-tid="' + tid + '"]');
                if (tr) { var m = tr.querySelector('.rowmsg'); m.className = 'rowmsg err'; m.textContent = res.errors[tid]; tr.classList.add('failed'); }
            });
            toast(res.msg || 'Done', res.ok && !Object.keys(res.errors || {}).length ? 'ok' : 'err');
            if (res.ok) setTimeout(function () { location.reload(); }, Object.keys(res.errors || {}).length ? 2600 : 900);
            else if (btn) btn.disabled = false;
        }).catch(function () { if (btn) btn.disabled = false; toast('Could not reach the server. Try again.', 'err'); });
    }
    document.querySelectorAll('.js-rec').forEach(function (b) {
        b.addEventListener('click', function () { reconcile([parseInt(b.dataset.id, 10)], b); });
    });
    if (recSel) recSel.addEventListener('click', function () {
        var ids = picks.filter(function (c) { return c.checked; }).map(function (c) { return parseInt(c.value, 10); });
        if (!ids.length) return;
        if (!confirm('Reconcile ' + ids.length + ' Credit Arrangement line(s)?\nNew STL loans are created on STL Settlement where no matching loan exists.')) return;
        reconcile(ids, recSel);
    });
    document.querySelectorAll('.js-undo').forEach(function (b) {
        b.addEventListener('click', function () {
            var q = b.dataset.created === '1'
                ? 'Undo this reconcile?\n\nThe bank line is cleared and the STL loan created from it is DELETED from STL Settlement.'
                : 'Undo this reconcile?\n\nThe bank line is cleared. The STL loan stays on STL Settlement.';
            if (!confirm(q)) return;
            b.disabled = true;
            post({ajax: 'undo', id: b.dataset.id}).then(function (res) {
                toast(res.msg || 'Done', res.ok ? 'ok' : 'err');
                if (res.ok) setTimeout(function () { location.reload(); }, 800); else b.disabled = false;
            }).catch(function () { b.disabled = false; toast('Could not reach the server. Try again.', 'err'); });
        });
    });
})();
</script>

<?php include 'footer.php'; ?>
