<?php
/**
 * pi_cheque_bank_recon.php
 * ─────────────────────────────────────────────────────────────────────
 * Primary Invoice payment cheques  ↔  Bank Statement debits
 *
 *  Cheques : pi_settlements (cheque_no, paid_amount, paid_date) of the
 *            Primary Invoices page. One cheque can pay several invoices —
 *            its rows are added together into one cheque amount.
 *            Only cheques from cheque books of the Settings bank account
 *            (optionally also cheques without a cheque book).
 *  Bank    : debit lines of the Settings account / statement type, e.g.
 *              "Inward Clearing/CHQ NO - 249263"
 *            also the BOC cheque no column and any "CHQ NO 12345" text.
 *  Match   : same cheque number (leading zeros ignored); amount equal →
 *            Tallied, amount differs → Mismatch. Cheques not found can be
 *            reconciled manually against one or more bank debits.
 *  Save    : as a BATCH with the not-reconciled snapshot. Bank statement
 *            lines get status, category "Payment Cheque Reconcile" and a
 *            remark. Deleting a batch clears them again.
 * ─────────────────────────────────────────────────────────────────────
 */
date_default_timezone_set('Asia/Colombo');

ob_start();
include_once 'config.php';
ob_end_clean();
include_once 'auth.php';
requireLogin();

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['pcr_csrf'])) $_SESSION['pcr_csrf'] = bin2hex(random_bytes(16));
$pcr_user = isset($_SESSION['username']) ? (string)$_SESSION['username'] : 'unknown';

const PCR_SOURCE   = 'pi_cheque_recon';
const PCR_CATEGORY = 'Payment Cheque Reconcile';
$PCR_BANK_TYPES = ['BOC' => 'BOC', 'NDB' => 'NDB', 'SAMPATH' => 'Sampath'];

class PcrUserError extends Exception {}

/* ═════════════ HELPERS ═════════════ */
function pcr_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function pcr_q($conn, $sql) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } }
function pcr_norm($s) { return trim(preg_replace('/\s+/u', ' ', (string)$s)); }
function pcr_money($v) { return number_format((float)$v, 2); }
function pcr_valid_date($d) { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && $d !== '0000-00-00' && strtotime($d) > 0; }
function pcr_fmt_date($d) { return pcr_valid_date((string)$d) ? date('d M Y', strtotime($d)) : '—'; }
function pcr_fmt_dt($d) { return $d ? date('d M Y, h:i A', strtotime($d)) : '—'; }
function pcr_days($a, $b) { return (int)round((strtotime($a) - strtotime($b)) / 86400); }
function pcr_ids($ids) { $ids = array_filter(array_map('intval', (array)$ids)); return $ids ? implode(',', $ids) : '0'; }
function pcr_has_col($conn, $t, $c) {
    $r = pcr_q($conn, "SHOW COLUMNS FROM `$t` LIKE '" . mysqli_real_escape_string($conn, $c) . "'");
    return $r && mysqli_num_rows($r) > 0;
}
function pcr_batch_no($id) { return 'PB-' . str_pad((string)(int)$id, 5, '0', STR_PAD_LEFT); }
function pcr_range($from, $to) {
    $from = pcr_valid_date((string)$from) ? $from : '';
    $to   = pcr_valid_date((string)$to)   ? $to   : '';
    if ($from === '' && $to === '') return ['', ''];
    if ($from === '') $from = $to;
    if ($to === '')   $to   = $from;
    return $to < $from ? [$to, $from] : [$from, $to];
}
/* cheque number without leading zeros: "000249263" → "249263" */
function pcr_chq_num($s) {
    $d = preg_replace('/\D/', '', (string)$s);
    if ($d === '') return '';
    $d = ltrim($d, '0');
    return $d === '' ? '0' : $d;
}
/* cheque number of a bank debit line, '' if none */
function pcr_bank_chq($t) {
    $desc = (string)($t['description'] ?? '');
    $ref  = (string)($t['reference'] ?? '');
    if (preg_match('/Inward\s+Clearing\s*\/\s*CHQ\s*NO\s*[-\x{2013}:]?\s*(\d+)/iu', $desc, $m)) return pcr_chq_num($m[1]);
    $cn = preg_replace('/\D/', '', (string)($t['cheque_no'] ?? ''));
    if ($cn !== '' && (int)$cn > 0) return pcr_chq_num($cn);
    if (preg_match('/\b(?:CHQ|CHEQUE|CHK)\.?\s*(?:NO|NUMBER|#)?\.?\s*[-:\x{2013}]?\s*(\d{4,})/iu', $desc . ' ' . $ref, $m)) return pcr_chq_num($m[1]);
    return '';
}

/* ═════════════ TABLES ═════════════ */
function pcr_ensure($conn) {
    pcr_q($conn, "CREATE TABLE IF NOT EXISTS pi_chq_recon_settings (
        id INT NOT NULL PRIMARY KEY,
        bank_account_id INT NULL,
        bank_type VARCHAR(10) NULL,
        window_days SMALLINT NOT NULL DEFAULT 30,
        include_unbooked TINYINT(1) NOT NULL DEFAULT 0,
        updated_by VARCHAR(100) NULL,
        updated_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    pcr_q($conn, "CREATE TABLE IF NOT EXISTS pi_chq_recon_batches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        batch_no VARCHAR(20) NULL,
        bank_type VARCHAR(10) NULL,
        bank_account_id INT NULL,
        date_from DATE NULL, date_to DATE NULL,
        window_days SMALLINT NOT NULL DEFAULT 30,
        remark TEXT NULL,
        rec_count INT NOT NULL DEFAULT 0,
        rec_chq_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        rec_bank_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        unrec_chq_count INT NOT NULL DEFAULT 0,
        unrec_chq_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        unrec_bank_count INT NOT NULL DEFAULT 0,
        unrec_bank_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        created_by VARCHAR(100) NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    /* one row per reconciled cheque (cheque_key = number|cheque_book_id) */
    pcr_q($conn, "CREATE TABLE IF NOT EXISTS pi_chq_recon (
        id INT AUTO_INCREMENT PRIMARY KEY,
        batch_id INT NOT NULL,
        method VARCHAR(10) NOT NULL DEFAULT 'auto',
        bank_type VARCHAR(10) NULL,
        bank_account_id INT NULL,
        cheque_key VARCHAR(60) NOT NULL,
        cheque_no VARCHAR(60) NOT NULL,
        cheque_book_id INT NULL,
        paid_date DATE NULL,
        txn_date DATE NULL,
        cheque_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        bank_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        difference DECIMAL(15,2) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'reconciled',
        invoices TEXT NULL,
        settlement_ids TEXT NULL,
        created_by VARCHAR(100) NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_cheque (cheque_key),
        INDEX idx_batch (batch_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    pcr_q($conn, "CREATE TABLE IF NOT EXISTS pi_chq_recon_bank (
        id INT AUTO_INCREMENT PRIMARY KEY,
        recon_id INT NOT NULL,
        bank_txn_id INT UNSIGNED NOT NULL,
        transaction_date DATE NULL,
        description TEXT NULL,
        debit DECIMAL(15,2) NOT NULL DEFAULT 0,
        UNIQUE KEY uq_txn (bank_txn_id),
        INDEX idx_recon (recon_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    pcr_q($conn, "CREATE TABLE IF NOT EXISTS pi_chq_recon_unrec (
        id INT AUTO_INCREMENT PRIMARY KEY,
        batch_id INT NOT NULL,
        side VARCHAR(10) NOT NULL,
        cheque_key VARCHAR(60) NULL,
        cheque_no VARCHAR(60) NULL,
        ref_date DATE NULL,
        bank_txn_id INT UNSIGNED NULL,
        description TEXT NULL,
        invoices TEXT NULL,
        amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        reason VARCHAR(255) NULL,
        INDEX idx_batch (batch_id, side)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach ([
        'recon_status'   => "VARCHAR(20)  NULL DEFAULT NULL",
        'recon_source'   => "VARCHAR(30)  NULL DEFAULT NULL",
        'recon_category' => "VARCHAR(50)  NULL DEFAULT NULL",
        'recon_ref_id'   => "INT          NULL DEFAULT NULL",
        'recon_remark'   => "TEXT         NULL",
        'recon_by'       => "VARCHAR(100) NULL DEFAULT NULL",
        'recon_at'       => "DATETIME     NULL DEFAULT NULL",
    ] as $c => $def) {
        if (!pcr_has_col($conn, 'bank_statement_transactions', $c)) pcr_q($conn, "ALTER TABLE bank_statement_transactions ADD COLUMN `$c` $def");
    }
}
pcr_ensure($conn);

/* bank accounts that have bank statements uploaded, with their statement types */
function pcr_accounts($conn) {
    $acc = [];
    $r = pcr_q($conn, "SELECT account_id, UPPER(bank_type) AS bt, MAX(statement_date) AS last_stmt, COUNT(*) AS uploads
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
        $r = pcr_q($conn, "SELECT cba.id,
                                  CONCAT(COALESCE(NULLIF(b.bank_name,''), cba.bank_code, ''), ' / ',
                                         COALESCE(NULLIF(bb.branch_name,''), cba.branch_code, ''), ' (', cba.account_no, ')') AS label
                             FROM company_bank_accounts cba
                        LEFT JOIN banks b ON b.bank_code = cba.bank_code
                        LEFT JOIN bank_branches bb ON bb.bank_code = cba.bank_code AND bb.branch_code = cba.branch_code
                            WHERE cba.id IN (" . pcr_ids(array_keys($acc)) . ")");
        while ($r && ($row = mysqli_fetch_assoc($r))) $acc[(int)$row['id']]['label'] = $row['label'];
    }
    foreach ($acc as &$a) ksort($a['types']);
    unset($a);
    uasort($acc, function ($x, $y) { return strcasecmp($x['label'], $y['label']); });
    return $acc;
}

function pcr_settings($conn, $accounts) {
    $s = ['account' => 0, 'type' => '', 'window' => 30, 'unbooked' => false,
          'saved_account' => 0, 'saved_type' => '', 'updated_by' => '', 'updated_at' => ''];
    $r = pcr_q($conn, "SELECT * FROM pi_chq_recon_settings WHERE id = 1 LIMIT 1");
    if ($r && ($row = mysqli_fetch_assoc($r))) {
        $s['saved_account'] = (int)$row['bank_account_id'];
        $s['saved_type']    = strtoupper((string)$row['bank_type']);
        $s['window']        = max(0, min(120, (int)$row['window_days']));
        $s['unbooked']      = (int)$row['include_unbooked'] === 1;
        $s['updated_by']    = (string)$row['updated_by'];
        $s['updated_at']    = (string)$row['updated_at'];
    }
    if (isset($accounts[$s['saved_account']])) {
        $s['account'] = $s['saved_account'];
        if ($s['saved_type'] !== '' && isset($accounts[$s['account']]['types'][$s['saved_type']])) $s['type'] = $s['saved_type'];
    }
    return $s;
}
function pcr_type_label($bt) {
    global $PCR_BANK_TYPES;
    $bt = strtoupper((string)$bt);
    return $bt === '' ? 'All types' : ($PCR_BANK_TYPES[$bt] ?? $bt);
}

/* ═════════════ MATCHING ENGINE ═════════════
 * $o = [account, type, window, unbooked, from, to]  (from/to = cheque paid date)
 */
function pcr_run($conn, $o) {
    $R = ['error' => '', 'pairs' => [], 'chq_only' => [], 'bank_only' => [], 'already' => ['chq' => 0, 'bank' => 0],
          'totals' => ['chq' => 0.0, 'bank' => 0.0, 'chq_cnt' => 0, 'bank_cnt' => 0], 'other_debits' => 0, 'bank_range' => ['', '']];
    if ($o['account'] <= 0) { $R['error'] = 'Set the bank account in the Settings tab first.'; return $R; }
    if ($o['from'] === '')  { $R['error'] = 'Enter the cheque paid date range.'; return $R; }
    $acc = (int)$o['account'];
    $f = mysqli_real_escape_string($conn, $o['from']);
    $t = mysqli_real_escape_string($conn, $o['to']);

    /* 1. cheques: every settlement row of a cheque that has a row in the date range */
    $book = "(cb.bank_account_id = $acc" . ($o['unbooked'] ? " OR s.cheque_book_id IS NULL" : "") . ")";
    $r = pcr_q($conn, "SELECT s.id, s.pi_id, s.paid_date, s.paid_amount, s.cheque_no, s.cheque_book_id, p.invoice_no, p.company
                         FROM pi_settlements s
                         JOIN primary_invoices p ON p.id = s.pi_id
                    LEFT JOIN cheque_books cb ON cb.id = s.cheque_book_id
                        WHERE s.cheque_no IS NOT NULL AND TRIM(s.cheque_no) <> '' AND $book
                          AND s.cheque_no IN (SELECT s2.cheque_no FROM pi_settlements s2
                                               WHERE s2.paid_date BETWEEN '$f' AND '$t' AND s2.cheque_no IS NOT NULL AND s2.cheque_no <> '')
                        ORDER BY s.paid_date, s.id");
    $units = [];
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $num = pcr_chq_num($row['cheque_no']);
        if ($num === '' || $num === '0') continue;
        $key = $num . '|' . (int)$row['cheque_book_id'];
        if (!isset($units[$key])) $units[$key] = ['key' => $key, 'num' => $num, 'no' => trim($row['cheque_no']), 'book' => (int)$row['cheque_book_id'],
                                                  'date' => '', 'amount' => 0.0, 'sids' => [], 'inv' => [], 'used' => false];
        $u =& $units[$key];
        $u['amount'] += (float)$row['paid_amount'];
        $u['sids'][] = (int)$row['id'];
        $u['inv'][]  = ['no' => $row['invoice_no'], 'co' => $row['company'], 'amt' => (float)$row['paid_amount']];
        if (pcr_valid_date((string)$row['paid_date']) && ($u['date'] === '' || $row['paid_date'] < $u['date'])) $u['date'] = $row['paid_date'];
        unset($u);
    }
    if ($units) {
        $keys = array_map(function ($k) use ($conn) { return "'" . mysqli_real_escape_string($conn, $k) . "'"; }, array_keys($units));
        $r = pcr_q($conn, "SELECT cheque_key FROM pi_chq_recon WHERE cheque_key IN (" . implode(',', $keys) . ")");
        while ($r && ($row = mysqli_fetch_assoc($r))) {
            if (isset($units[$row['cheque_key']])) { unset($units[$row['cheque_key']]); $R['already']['chq']++; }
        }
    }
    foreach ($units as &$u) { $u['amount'] = round($u['amount'], 2); $R['totals']['chq'] += $u['amount']; $R['totals']['chq_cnt']++; }
    unset($u);

    /* 2. bank debits: from 3 days before the range to "window" days after it */
    $b_from = date('Y-m-d', strtotime($o['from'] . ' -3 days'));
    $b_to   = date('Y-m-d', strtotime($o['to'] . ' +' . (int)$o['window'] . ' days'));
    $R['bank_range'] = [$b_from, $b_to];
    $type = (string)$o['type'];
    $st = mysqli_prepare($conn, "SELECT t.* FROM bank_statement_transactions t
                                   JOIN bank_statement_uploads u ON u.id = t.upload_id
                                  WHERE u.account_id = ? " . ($type !== '' ? "AND UPPER(u.bank_type) = ?" : "AND ? = ''") . "
                                    AND t.transaction_date BETWEEN ? AND ? AND t.debit > 0
                                  ORDER BY t.transaction_date, t.id");
    mysqli_stmt_bind_param($st, 'isss', $acc, $type, $b_from, $b_to);
    mysqli_stmt_execute($st);
    $rs = mysqli_stmt_get_result($st);
    $banks = []; $seen = [];
    while ($rs && ($tx = mysqli_fetch_assoc($rs))) {
        $dk = $tx['transaction_date'] . '|' . pcr_norm($tx['description']) . '|' . $tx['debit'] . '|' . $tx['balance'];
        if (isset($seen[$dk])) continue;
        $seen[$dk] = true;
        $num = pcr_bank_chq($tx);
        if ($num === '') { $R['other_debits']++; continue; }
        if (!empty($tx['recon_status'])) { $R['already']['bank']++; continue; }
        $amt = round((float)$tx['debit'], 2);
        $R['totals']['bank'] += $amt; $R['totals']['bank_cnt']++;
        $banks[] = ['num' => $num, 'date' => $tx['transaction_date'], 'amount' => $amt, 'txns' => [$tx], 'used' => false];
    }
    mysqli_stmt_close($st);

    /* 3. same cheque number: equal amount first (closest date), then closest amount */
    uasort($units, function ($a, $b) { return strcmp($a['date'], $b['date']) ?: strcmp($a['num'], $b['num']); });
    foreach ([true, false] as $needEqual) {
        foreach ($units as $k => $u) {
            if ($u['used']) continue;
            $best = null; $bestScore = null;
            foreach ($banks as $bi => $b) {
                if ($b['used'] || $b['num'] !== $u['num']) continue;
                $diff = abs(round($b['amount'] - $u['amount'], 2));
                if ($needEqual && $diff >= 0.005) continue;
                $score = [$diff, $u['date'] !== '' ? abs(pcr_days($b['date'], $u['date'])) : 0];
                if ($best === null || $score < $bestScore) { $best = $bi; $bestScore = $score; }
            }
            if ($best !== null) {
                $units[$k]['used'] = true; $banks[$best]['used'] = true;
                $R['pairs'][] = ['type' => $needEqual ? 'matched' : 'mismatch', 'c' => $units[$k], 'b' => $banks[$best],
                                 'days' => $units[$k]['date'] !== '' ? pcr_days($banks[$best]['date'], $units[$k]['date']) : 0];
            }
        }
    }
    foreach ($units as $u) if (!$u['used']) $R['chq_only'][] = ['c' => $u, 'why' => 'Cheque not in the bank statement yet'];
    foreach ($banks as $b) if (!$b['used']) $R['bank_only'][] = ['b' => $b, 'why' => 'Cheque no not in payments of this account'];
    usort($R['pairs'], function ($a, $b) { return strcmp($a['c']['date'], $b['c']['date']) ?: strcmp($a['c']['num'], $b['c']['num']); });
    return $R;
}

function pcr_inv_text($inv) {
    return implode('; ', array_map(function ($i) { return $i['no'] . ' (' . $i['co'] . ') Rs ' . pcr_money($i['amt']); }, $inv));
}

/* ═════════════ SAVE ONE CHEQUE (inside the batch transaction) ═════════════ */
function pcr_save_item($conn, $it, &$ctx) {
    $key    = (string)($it['key'] ?? '');
    $txnIds = array_values(array_unique(array_filter(array_map('intval', (array)($it['txns'] ?? [])))));
    $method = (($it['method'] ?? '') === 'manual') ? 'manual' : 'auto';
    if (!preg_match('/^(\d+)\|(\d+)$/', $key, $km) || !$txnIds) throw new PcrUserError('Incomplete row.');
    $num = $km[1]; $book = (int)$km[2];
    if (isset($ctx['keys'][$key])) throw new PcrUserError('This cheque is used twice in the batch.');
    foreach ($txnIds as $tid) if (isset($ctx['txns'][$tid])) throw new PcrUserError('A bank transaction is used twice in the batch.');

    /* cheque side — re-read from the database */
    $like = mysqli_real_escape_string($conn, '%' . $num);
    $r = mysqli_query($conn, "SELECT s.id, s.paid_date, s.paid_amount, s.cheque_no, s.cheque_book_id, p.invoice_no, p.company, cb.bank_account_id
                                FROM pi_settlements s
                                JOIN primary_invoices p ON p.id = s.pi_id
                           LEFT JOIN cheque_books cb ON cb.id = s.cheque_book_id
                               WHERE COALESCE(s.cheque_book_id, 0) = $book AND s.cheque_no LIKE '$like'");
    $rows = []; $amount = 0.0; $date = ''; $inv = []; $no = '';
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        if (pcr_chq_num($row['cheque_no']) !== $num) continue;
        if ($book > 0 && (int)$row['bank_account_id'] !== $ctx['account']) throw new PcrUserError('Cheque book is not of the Settings bank account.');
        if ($book === 0 && !$ctx['unbooked']) throw new PcrUserError('Cheques without a cheque book are off in Settings.');
        $rows[] = (int)$row['id'];
        $amount += (float)$row['paid_amount'];
        $inv[] = ['no' => $row['invoice_no'], 'co' => $row['company'], 'amt' => (float)$row['paid_amount']];
        $no = trim($row['cheque_no']);
        if (pcr_valid_date((string)$row['paid_date']) && ($date === '' || $row['paid_date'] < $date)) $date = $row['paid_date'];
    }
    if (!$rows) throw new PcrUserError('Cheque was changed or deleted in Primary Invoices. Run the reconcile again.');
    $r = mysqli_query($conn, "SELECT id FROM pi_chq_recon WHERE cheque_key = '" . mysqli_real_escape_string($conn, $key) . "' LIMIT 1");
    if ($r && mysqli_fetch_assoc($r)) throw new PcrUserError('This cheque is already reconciled.');

    /* bank side */
    $txns = []; $bank = 0.0; $tdates = [];
    $r = mysqli_query($conn, "SELECT t.id, t.transaction_date, t.description, t.reference, t.debit, t.recon_status, u.account_id, u.bank_type
                                FROM bank_statement_transactions t JOIN bank_statement_uploads u ON u.id = t.upload_id
                               WHERE t.id IN (" . pcr_ids($txnIds) . ")");
    while ($r && ($row = mysqli_fetch_assoc($r))) $txns[(int)$row['id']] = $row;
    foreach ($txnIds as $tid) {
        if (!isset($txns[$tid])) throw new PcrUserError('A bank transaction no longer exists.');
        $tx = $txns[$tid];
        if (!empty($tx['recon_status'])) throw new PcrUserError('A bank transaction is already reconciled.');
        if ((int)$tx['account_id'] !== $ctx['account'] || ($ctx['type'] !== '' && strtoupper($tx['bank_type']) !== $ctx['type'])) {
            throw new PcrUserError('A bank transaction is not from the Settings account / statement type.');
        }
        if ((float)$tx['debit'] <= 0) throw new PcrUserError('A bank transaction is not a debit.');
        $bank += (float)$tx['debit'];
        $tdates[$tx['transaction_date']] = true;
    }
    $amount = round($amount, 2); $bank = round($bank, 2); $diff = round($bank - $amount, 2);
    if (abs($diff) >= 0.005 && empty($it['allow_diff'])) throw new PcrUserError('Amounts no longer tally. Run the reconcile again.');
    $status = abs($diff) < 0.005 ? 'reconciled' : 'difference';
    ksort($tdates);
    $tdate = $tdates ? array_key_first($tdates) : null;
    $pdate = $date !== '' ? $date : null;
    $invText = pcr_inv_text($inv);
    $sids = implode(',', $rows);
    $bookDb = $book ?: null;

    $st = mysqli_prepare($conn, "INSERT INTO pi_chq_recon
        (batch_id, method, bank_type, bank_account_id, cheque_key, cheque_no, cheque_book_id, paid_date, txn_date,
         cheque_amount, bank_amount, difference, status, invoices, settlement_ids, created_by, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $bid = $ctx['batch_id']; $bt = $ctx['type']; $acc = $ctx['account']; $user = $ctx['user']; $now = $ctx['now'];
    mysqli_stmt_bind_param($st, 'issississdddsssss', $bid, $method, $bt, $acc, $key, $no, $bookDb, $pdate, $tdate,
                           $amount, $bank, $diff, $status, $invText, $sids, $user, $now);
    if (!mysqli_stmt_execute($st)) throw new PcrUserError('This cheque is already reconciled.');
    $rid = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($st);

    $remark = $ctx['batch_no'] . ' Recon #' . $rid . ($method === 'manual' ? ' (manual)' : '')
            . ' | Payment Cheque ' . $no
            . ' | Invoices: ' . $invText
            . ' | Paid date: ' . ($pdate ?: '—')
            . ' | Cheque Rs ' . pcr_money($amount) . ' | Bank Rs ' . pcr_money($bank)
            . ($status !== 'reconciled' ? ' | Difference Rs ' . pcr_money($diff) : '')
            . ' | by ' . $user . ' on ' . $now
            . ($ctx['remark'] !== '' ? ' | Note: ' . $ctx['remark'] : '');

    $stB = mysqli_prepare($conn, "INSERT INTO pi_chq_recon_bank (recon_id, bank_txn_id, transaction_date, description, debit) VALUES (?,?,?,?,?)");
    $stU = mysqli_prepare($conn, "UPDATE bank_statement_transactions
                                     SET recon_status = ?, recon_source = ?, recon_category = ?, recon_ref_id = ?, recon_remark = ?, recon_by = ?, recon_at = ?
                                   WHERE id = ? AND (recon_status IS NULL OR recon_status = '')");
    $src = PCR_SOURCE; $cat = PCR_CATEGORY;
    foreach ($txnIds as $tid) {
        $tx = $txns[$tid];
        $desc = pcr_norm($tx['description'] . ($tx['reference'] ? ' ' . $tx['reference'] : ''));
        $db = (float)$tx['debit']; $td = $tx['transaction_date'];
        mysqli_stmt_bind_param($stB, 'iissd', $rid, $tid, $td, $desc, $db);
        if (!mysqli_stmt_execute($stB)) throw new PcrUserError('A bank transaction is already reconciled.');
        mysqli_stmt_bind_param($stU, 'sssisssi', $status, $src, $cat, $rid, $remark, $user, $now, $tid);
        mysqli_stmt_execute($stU);
        if (mysqli_stmt_affected_rows($stU) !== 1) throw new PcrUserError('A bank transaction was just reconciled by someone else.');
        $ctx['txns'][$tid] = true;
    }
    mysqli_stmt_close($stB); mysqli_stmt_close($stU);
    $ctx['keys'][$key] = true;
    $ctx['rec_count']++; $ctx['rec_chq'] += $amount; $ctx['rec_bank'] += $bank;
    return $rid;
}

function pcr_delete_batch($conn, $bid) {
    $bid = (int)$bid;
    $rids = [];
    $r = pcr_q($conn, "SELECT id FROM pi_chq_recon WHERE batch_id = $bid");
    while ($r && ($row = mysqli_fetch_assoc($r))) $rids[] = (int)$row['id'];
    $in = pcr_ids($rids);
    mysqli_begin_transaction($conn);
    try {
        if ($rids) {
            mysqli_query($conn, "UPDATE bank_statement_transactions
                                    SET recon_status = NULL, recon_source = NULL, recon_category = NULL, recon_ref_id = NULL,
                                        recon_remark = NULL, recon_by = NULL, recon_at = NULL
                                  WHERE recon_source = '" . PCR_SOURCE . "' AND recon_ref_id IN ($in)");
            mysqli_query($conn, "DELETE FROM pi_chq_recon_bank WHERE recon_id IN ($in)");
            mysqli_query($conn, "DELETE FROM pi_chq_recon      WHERE id IN ($in)");
        }
        mysqli_query($conn, "DELETE FROM pi_chq_recon_unrec   WHERE batch_id = $bid");
        mysqli_query($conn, "DELETE FROM pi_chq_recon_batches WHERE id = $bid");
        mysqli_commit($conn);
        return count($rids);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        return false;
    }
}

/* ═════════════ AJAX ═════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    if (!hash_equals((string)$_SESSION['pcr_csrf'], (string)($_POST['csrf'] ?? ''))) {
        echo json_encode(['ok' => false, 'msg' => 'Security check failed. Reload the page and try again.']); exit;
    }
    $act = (string)$_POST['ajax'];
    $JSON = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    if ($act === 'save_settings') {
        $accounts = pcr_accounts($conn);
        $acc = (int)($_POST['account_id'] ?? 0);
        $bt  = strtoupper(trim((string)($_POST['bank_type'] ?? '')));
        $win = max(0, min(120, (int)($_POST['window'] ?? 30)));
        $unb = !empty($_POST['unbooked']) ? 1 : 0;
        if (!isset($accounts[$acc])) { echo json_encode(['ok' => false, 'msg' => 'Select a bank account that has bank statements uploaded.']); exit; }
        if ($bt !== '' && !isset($accounts[$acc]['types'][$bt])) { echo json_encode(['ok' => false, 'msg' => 'No ' . pcr_type_label($bt) . ' statements are uploaded for this account.']); exit; }
        $btDb = $bt !== '' ? $bt : null; $now = date('Y-m-d H:i:s');
        $st = mysqli_prepare($conn, "INSERT INTO pi_chq_recon_settings (id, bank_account_id, bank_type, window_days, include_unbooked, updated_by, updated_at)
                                     VALUES (1, ?, ?, ?, ?, ?, ?)
                                     ON DUPLICATE KEY UPDATE bank_account_id = VALUES(bank_account_id), bank_type = VALUES(bank_type),
                                         window_days = VALUES(window_days), include_unbooked = VALUES(include_unbooked),
                                         updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)");
        mysqli_stmt_bind_param($st, 'isiiss', $acc, $btDb, $win, $unb, $pcr_user, $now);
        $ok = mysqli_stmt_execute($st);
        echo json_encode($ok ? ['ok' => true, 'msg' => 'Settings saved.'] : ['ok' => false, 'msg' => 'Could not save settings.']);
        exit;
    }

    if ($act === 'save_batch') {
        $accounts = pcr_accounts($conn);
        $cfg = pcr_settings($conn, $accounts);
        if ($cfg['account'] <= 0) { echo json_encode(['ok' => false, 'msg' => 'Set the bank account in Settings first.']); exit; }
        $items  = json_decode((string)($_POST['items'] ?? '[]'), true);
        $items  = is_array($items) ? array_slice($items, 0, 500) : [];
        $remark = trim(mb_substr((string)($_POST['remark'] ?? ''), 0, 500));
        list($from, $to) = pcr_range($_POST['from'] ?? '', $_POST['to'] ?? '');
        $run = pcr_run($conn, ['account' => $cfg['account'], 'type' => $cfg['type'], 'window' => $cfg['window'],
                               'unbooked' => $cfg['unbooked'], 'from' => $from, 'to' => $to]);
        if ($run['error'] !== '') { echo json_encode(['ok' => false, 'msg' => $run['error']]); exit; }
        if (!$items && !$run['pairs'] && !$run['chq_only'] && !$run['bank_only']) { echo json_encode(['ok' => false, 'msg' => 'Nothing to save.']); exit; }

        $now = date('Y-m-d H:i:s');
        $ctx = ['account' => $cfg['account'], 'type' => $cfg['type'], 'unbooked' => $cfg['unbooked'], 'user' => $pcr_user, 'now' => $now,
                'remark' => $remark, 'batch_id' => 0, 'batch_no' => '', 'keys' => [], 'txns' => [], 'rec_count' => 0, 'rec_chq' => 0.0, 'rec_bank' => 0.0];
        mysqli_begin_transaction($conn);
        try {
            $st = mysqli_prepare($conn, "INSERT INTO pi_chq_recon_batches (bank_type, bank_account_id, date_from, date_to, window_days, remark, created_by, created_at)
                                         VALUES (?,?,?,?,?,?,?,?)");
            $bt = $cfg['type'] !== '' ? $cfg['type'] : null; $acc = $cfg['account']; $win = $cfg['window']; $remDb = $remark !== '' ? $remark : null;
            mysqli_stmt_bind_param($st, 'sississs', $bt, $acc, $from, $to, $win, $remDb, $pcr_user, $now);
            if (!mysqli_stmt_execute($st)) throw new Exception('batch');
            $bid = (int)mysqli_insert_id($conn);
            mysqli_stmt_close($st);
            $ctx['batch_id'] = $bid; $ctx['batch_no'] = pcr_batch_no($bid);
            mysqli_query($conn, "UPDATE pi_chq_recon_batches SET batch_no = '" . $ctx['batch_no'] . "' WHERE id = $bid");

            $errors = [];
            foreach ($items as $it) {
                try { pcr_save_item($conn, $it, $ctx); }
                catch (PcrUserError $e) { $errors[] = ['row' => (string)($it['row'] ?? ''), 'msg' => $e->getMessage()]; }
                catch (Throwable $e)    { $errors[] = ['row' => (string)($it['row'] ?? ''), 'msg' => 'Could not be saved (database error).']; }
            }
            if ($errors) {
                mysqli_rollback($conn);
                echo json_encode(['ok' => false, 'msg' => count($errors) . ' row(s) could not be saved, so the batch was not saved. Untick them or run the reconcile again.', 'errors' => $errors], $JSON);
                exit;
            }

            /* not reconciled snapshot */
            $unrec = [];
            foreach ($run['pairs'] as $p) {
                if (!isset($ctx['keys'][$p['c']['key']])) {
                    $unrec[] = ['cheque', $p['c'], $p['type'] === 'matched'
                        ? 'Tallied with bank ' . $p['b']['date'] . ' Rs ' . pcr_money($p['b']['amount']) . ' but not selected'
                        : 'Amount mismatch: bank ' . $p['b']['date'] . ' Rs ' . pcr_money($p['b']['amount'])];
                }
                if (!isset($ctx['txns'][(int)$p['b']['txns'][0]['id']])) {
                    $unrec[] = ['bank', $p['b'], $p['type'] === 'matched'
                        ? 'Tallied with cheque ' . $p['c']['no'] . ' but not selected'
                        : 'Amount mismatch: cheque ' . $p['c']['no'] . ' Rs ' . pcr_money($p['c']['amount'])];
                }
            }
            foreach ($run['chq_only'] as $x)  if (!isset($ctx['keys'][$x['c']['key']])) $unrec[] = ['cheque', $x['c'], $x['why']];
            foreach ($run['bank_only'] as $x) if (!isset($ctx['txns'][(int)$x['b']['txns'][0]['id']])) $unrec[] = ['bank', $x['b'], $x['why']];

            $st = mysqli_prepare($conn, "INSERT INTO pi_chq_recon_unrec (batch_id, side, cheque_key, cheque_no, ref_date, bank_txn_id, description, invoices, amount, reason)
                                         VALUES (?,?,?,?,?,?,?,?,?,?)");
            $uc = ['cheque' => [0, 0.0], 'bank' => [0, 0.0]];
            foreach ($unrec as $u) {
                list($side, $g, $why) = $u;
                if ($side === 'cheque') {
                    $key = $g['key']; $no = $g['no']; $rd = $g['date'] ?: null; $tid = null; $desc = null; $inv = pcr_inv_text($g['inv']);
                } else {
                    $tx = $g['txns'][0];
                    $key = null; $no = $g['num']; $rd = $g['date']; $tid = (int)$tx['id']; $desc = pcr_norm($tx['description'] . ' ' . $tx['reference']); $inv = null;
                }
                $amt = round($g['amount'], 2); $why = mb_substr($why, 0, 255);
                mysqli_stmt_bind_param($st, 'issssissds', $bid, $side, $key, $no, $rd, $tid, $desc, $inv, $amt, $why);
                if (!mysqli_stmt_execute($st)) throw new Exception('unrec');
                $uc[$side][0]++; $uc[$side][1] += $amt;
            }
            mysqli_stmt_close($st);

            $st = mysqli_prepare($conn, "UPDATE pi_chq_recon_batches SET rec_count = ?, rec_chq_amount = ?, rec_bank_amount = ?,
                                             unrec_chq_count = ?, unrec_chq_amount = ?, unrec_bank_count = ?, unrec_bank_amount = ? WHERE id = ?");
            $rc = $ctx['rec_count']; $ra = round($ctx['rec_chq'], 2); $rb = round($ctx['rec_bank'], 2);
            $ucc = $uc['cheque'][0]; $uca = round($uc['cheque'][1], 2); $ubc = $uc['bank'][0]; $uba = round($uc['bank'][1], 2);
            mysqli_stmt_bind_param($st, 'iddididi', $rc, $ra, $rb, $ucc, $uca, $ubc, $uba, $bid);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);

            mysqli_commit($conn);
            echo json_encode(['ok' => true, 'batch_id' => $bid, 'batch_no' => $ctx['batch_no'], 'saved' => $rc], $JSON);
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            echo json_encode(['ok' => false, 'msg' => 'The batch could not be saved. Try again.']);
        }
        exit;
    }

    if ($act === 'delete_batch') {
        $n = pcr_delete_batch($conn, (int)($_POST['id'] ?? 0));
        echo json_encode($n === false ? ['ok' => false, 'msg' => 'Could not delete the batch. Try again.'] : ['ok' => true, 'deleted' => $n]);
        exit;
    }

    /* open bank debits for manual reconcile */
    if ($act === 'bank_open') {
        $cfg = pcr_settings($conn, pcr_accounts($conn));
        if ($cfg['account'] <= 0) { echo json_encode(['ok' => false, 'msg' => 'Set the bank account in Settings first.']); exit; }
        list($from, $to) = pcr_range($_POST['from'] ?? '', $_POST['to'] ?? '');
        if ($from === '') { echo json_encode(['ok' => false, 'msg' => 'Enter the date range.']); exit; }
        if (pcr_days($to, $from) > 184) { echo json_encode(['ok' => false, 'msg' => 'Pick a date range of 6 months or less.']); exit; }
        $acc = $cfg['account']; $bt = $cfg['type'];
        $st = mysqli_prepare($conn, "SELECT t.* FROM bank_statement_transactions t JOIN bank_statement_uploads u ON u.id = t.upload_id
                                      WHERE u.account_id = ? " . ($bt !== '' ? "AND UPPER(u.bank_type) = ?" : "AND ? = ''") . "
                                        AND t.transaction_date BETWEEN ? AND ? AND t.debit > 0
                                        AND (t.recon_status IS NULL OR t.recon_status = '')
                                      ORDER BY t.transaction_date, t.id LIMIT 3000");
        mysqli_stmt_bind_param($st, 'isss', $acc, $bt, $from, $to);
        mysqli_stmt_execute($st);
        $rs = mysqli_stmt_get_result($st);
        $rows = []; $seen = [];
        while ($rs && ($tx = mysqli_fetch_assoc($rs))) {
            $dk = $tx['transaction_date'] . '|' . pcr_norm($tx['description']) . '|' . $tx['debit'] . '|' . $tx['balance'];
            if (isset($seen[$dk])) continue;
            $seen[$dk] = true;
            $rows[] = ['id' => (int)$tx['id'], 'date' => $tx['transaction_date'], 'desc' => pcr_norm($tx['description'] . ' ' . $tx['reference']),
                       'debit' => round((float)$tx['debit'], 2), 'num' => pcr_bank_chq($tx)];
        }
        mysqli_stmt_close($st);
        echo json_encode(['ok' => true, 'rows' => $rows], $JSON);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Unknown action.']); exit;
}

/* ═════════════ PAGE DATA ═════════════ */
$accounts = pcr_accounts($conn);
$cfg      = pcr_settings($conn, $accounts);
$tab      = in_array($_GET['tab'] ?? '', ['saved', 'settings'], true) ? $_GET['tab'] : 'run';

$ran = isset($_GET['run']);
if ($ran) list($f_from, $f_to) = pcr_range($_GET['from'] ?? '', $_GET['to'] ?? '');
else { $f_from = date('Y-m-d', strtotime('-30 days')); $f_to = date('Y-m-d'); }
$run = ($tab === 'run' && $ran)
     ? pcr_run($conn, ['account' => $cfg['account'], 'type' => $cfg['type'], 'window' => $cfg['window'], 'unbooked' => $cfg['unbooked'], 'from' => $f_from, 'to' => $f_to])
     : null;
$pairs     = $run ? $run['pairs'] : [];
$chq_only  = $run ? $run['chq_only'] : [];
$bank_only = $run ? $run['bank_only'] : [];
$matched   = array_values(array_filter($pairs, function ($p) { return $p['type'] === 'matched'; }));
$mismatch  = array_values(array_filter($pairs, function ($p) { return $p['type'] === 'mismatch'; }));
$sumOf = function ($list, $side) { $s = 0; foreach ($list as $p) $s += $p[$side]['amount']; return $s; };

/* saved batches */
$batch_id = (int)($_GET['batch'] ?? 0);
$b_from = pcr_valid_date($_GET['b_from'] ?? '') ? $_GET['b_from'] : date('Y-m-d', strtotime('-60 days'));
$b_to   = pcr_valid_date($_GET['b_to']   ?? '') ? $_GET['b_to']   : date('Y-m-d');
$b_q    = trim((string)($_GET['b_q'] ?? ''));
$batches = []; $B = null; $B_rec = []; $B_unrec = ['cheque' => [], 'bank' => []]; $now_chq = []; $now_bank = [];
if ($tab === 'saved' && $batch_id <= 0) {
    $sql = "SELECT * FROM pi_chq_recon_batches WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)";
    $types = 'ss'; $args = [$b_from, $b_to];
    if ($b_q !== '') {
        $sql .= " AND (batch_no LIKE ? OR remark LIKE ? OR created_by LIKE ? OR id IN (SELECT batch_id FROM pi_chq_recon WHERE cheque_no LIKE ? OR invoices LIKE ?))";
        $like = "%$b_q%"; $types .= 'sssss'; array_push($args, $like, $like, $like, $like, $like);
    }
    $sql .= " ORDER BY created_at DESC, id DESC LIMIT 500";
    $st = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($st, $types, ...$args);
    mysqli_stmt_execute($st);
    $rs = mysqli_stmt_get_result($st);
    while ($rs && ($row = mysqli_fetch_assoc($rs))) $batches[] = $row;
    mysqli_stmt_close($st);
}
if ($tab === 'saved' && $batch_id > 0) {
    $r = pcr_q($conn, "SELECT * FROM pi_chq_recon_batches WHERE id = $batch_id");
    $B = $r ? mysqli_fetch_assoc($r) : null;
    if ($B) {
        $r = pcr_q($conn, "SELECT * FROM pi_chq_recon WHERE batch_id = $batch_id ORDER BY txn_date, cheque_no");
        while ($r && ($row = mysqli_fetch_assoc($r))) { $row['txns'] = []; $B_rec[(int)$row['id']] = $row; }
        if ($B_rec) {
            $r = pcr_q($conn, "SELECT * FROM pi_chq_recon_bank WHERE recon_id IN (" . pcr_ids(array_keys($B_rec)) . ") ORDER BY transaction_date, id");
            while ($r && ($row = mysqli_fetch_assoc($r))) $B_rec[(int)$row['recon_id']]['txns'][] = $row;
        }
        $r = pcr_q($conn, "SELECT * FROM pi_chq_recon_unrec WHERE batch_id = $batch_id ORDER BY ref_date, cheque_no");
        while ($r && ($row = mysqli_fetch_assoc($r))) $B_unrec[$row['side'] === 'bank' ? 'bank' : 'cheque'][] = $row;
        $keys = array_filter(array_map(function ($u) use ($conn) { return $u['cheque_key'] ? "'" . mysqli_real_escape_string($conn, $u['cheque_key']) . "'" : ''; }, $B_unrec['cheque']));
        if ($keys) {
            $r = pcr_q($conn, "SELECT r.cheque_key, b.id AS bid, b.batch_no FROM pi_chq_recon r LEFT JOIN pi_chq_recon_batches b ON b.id = r.batch_id
                                WHERE r.cheque_key IN (" . implode(',', $keys) . ")");
            while ($r && ($row = mysqli_fetch_assoc($r))) $now_chq[$row['cheque_key']] = $row;
        }
        $tids = array_filter(array_map(function ($u) { return (int)$u['bank_txn_id']; }, $B_unrec['bank']));
        if ($tids) {
            $r = pcr_q($conn, "SELECT t.id, t.recon_status, rb.recon_id, b.id AS bid, b.batch_no
                                 FROM bank_statement_transactions t
                            LEFT JOIN pi_chq_recon_bank rb ON rb.bank_txn_id = t.id
                            LEFT JOIN pi_chq_recon r ON r.id = rb.recon_id
                            LEFT JOIN pi_chq_recon_batches b ON b.id = r.batch_id
                                WHERE t.id IN (" . pcr_ids($tids) . ")");
            while ($r && ($row = mysqli_fetch_assoc($r))) $now_bank[(int)$row['id']] = $row;
        }
    }
}

function pcr_acc_label($accounts, $id) { return $accounts[(int)$id]['label'] ?? ('Account #' . (int)$id); }
function pcr_range_label($a, $b) { return !pcr_valid_date((string)$a) ? '—' : ($a === $b ? pcr_fmt_date($a) : pcr_fmt_date($a) . ' – ' . pcr_fmt_date($b)); }
function pcr_diff_html($d) {
    return abs($d) < 0.005 ? '<span class="badge b-ok">Tallied</span>'
         : '<span class="' . ($d > 0 ? 'diff-pos' : 'diff-neg') . '">' . ($d > 0 ? '+' : '') . pcr_money($d) . '</span>';
}
function pcr_inv_html($inv) {
    $h = '';
    foreach ($inv as $i) $h .= '<div class="sub-line"><b>' . pcr_h($i['no']) . '</b> <span class="muted">' . pcr_h($i['co']) . '</span> · ' . pcr_money($i['amt']) . '</div>';
    return $h;
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

.dbr .mr-list tr.same td:nth-child(4) .chq { background:#0f766e; color:#fff; }

.dbr .chq { font-family: ui-monospace, Menlo, Consolas, monospace; font-weight: 800; color: #312e81; background: #ede9fe; padding: 1px 7px; border-radius: 5px; }
.dbr h1 i { color: #7c3aed; }
</style>

<div class="dbr">
    <h1><i class="fa-solid fa-money-check-dollar"></i> Payment Cheque ↔ Bank Reconciliation</h1>
    <p class="sub">Match Primary Invoice payment cheques to bank statement debits (e.g. <code>Inward Clearing/CHQ NO - 249263</code>) and save them as batches.
        <a href="primary_invoices.php" class="blink" style="margin-left:6px;"><i class="fa-solid fa-arrow-left"></i> Primary Invoices</a></p>

    <div class="tabs">
        <a href="?tab=run" class="<?php echo $tab === 'run' ? 'on' : ''; ?>"><i class="fa-solid fa-wand-magic-sparkles"></i> Reconcile</a>
        <a href="?tab=saved" class="<?php echo $tab === 'saved' ? 'on' : ''; ?>"><i class="fa-solid fa-layer-group"></i> Saved batches</a>
        <a href="?tab=settings" class="<?php echo $tab === 'settings' ? 'on' : ''; ?>"><i class="fa-solid fa-gear"></i> Settings</a>
    </div>

<?php if ($tab === 'run'): /* ═══════════ RECONCILE ═══════════ */ ?>

    <?php if ($cfg['account'] <= 0): ?>
        <div class="alert warn">No bank account is set yet. <a href="?tab=settings">Open Settings</a> and choose the bank account.</div>
    <?php else: ?>
        <div class="setbar">
            <span><i class="fa-solid fa-building-columns"></i> <b><?php echo pcr_h(pcr_acc_label($accounts, $cfg['account'])); ?></b></span>
            <span class="pill"><?php echo pcr_h(pcr_type_label($cfg['type'])); ?></span>
            <span class="pill">Bank up to <?php echo (int)$cfg['window']; ?> days after paid date</span>
            <span class="pill"><?php echo $cfg['unbooked'] ? 'Incl. cheques without cheque book' : 'Cheque books of this account only'; ?></span>
            <a href="?tab=settings" class="setlink"><i class="fa-solid fa-gear"></i> Change</a>
        </div>
    <?php endif; ?>

    <form class="filters" method="get">
        <input type="hidden" name="tab" value="run"><input type="hidden" name="run" value="1">
        <div><label class="f" for="from">Cheque paid date from</label><input type="date" id="from" name="from" value="<?php echo pcr_h($f_from); ?>"></div>
        <div><label class="f" for="to">Cheque paid date to</label><input type="date" id="to" name="to" value="<?php echo pcr_h($f_to); ?>"></div>
        <div><button class="btn btn-dark" type="submit" style="width:100%;justify-content:center;" <?php echo $cfg['account'] > 0 ? '' : 'disabled'; ?>><i class="fa-solid fa-play"></i> Reconcile</button></div>
    </form>

    <?php if ($run && $run['error'] !== ''): ?><div class="alert err"><?php echo pcr_h($run['error']); ?></div><?php endif; ?>

    <?php if ($run && $run['error'] === ''): ?>
        <div class="alert info">
            Payment cheques paid <b><?php echo pcr_range_label($f_from, $f_to); ?></b> checked against bank debits
            <b><?php echo pcr_range_label($run['bank_range'][0], $run['bank_range'][1]); ?></b>
            (<?php echo pcr_h(pcr_type_label($cfg['type'])); ?>). <?php echo (int)$run['other_debits']; ?> other debit line(s) without a cheque number were skipped.
            <?php if ($run['already']['chq'] || $run['already']['bank']): ?>
                Already reconciled and left out: <?php echo $run['already']['chq']; ?> cheque(s), <?php echo $run['already']['bank']; ?> bank line(s).
            <?php endif; ?>
        </div>
        <div class="stats">
            <div class="stat"><div class="lbl">Cheques (open)</div><div class="val"><?php echo pcr_money($run['totals']['chq']); ?></div><div class="cnt"><?php echo $run['totals']['chq_cnt']; ?> cheque(s)</div></div>
            <div class="stat"><div class="lbl">Bank cheque debits (open)</div><div class="val"><?php echo pcr_money($run['totals']['bank']); ?></div><div class="cnt"><?php echo $run['totals']['bank_cnt']; ?> line(s)</div></div>
            <div class="stat g"><div class="lbl">Tallied</div><div class="val"><?php echo count($matched); ?></div><div class="cnt">Rs <?php echo pcr_money($sumOf($matched, 'c')); ?></div></div>
            <div class="stat a"><div class="lbl">Amount mismatch</div><div class="val"><?php echo count($mismatch); ?></div><div class="cnt">Chq <?php echo pcr_money($sumOf($mismatch, 'c')); ?> / Bank <?php echo pcr_money($sumOf($mismatch, 'b')); ?></div></div>
            <div class="stat r"><div class="lbl">Cheques not in bank</div><div class="val"><?php echo count($chq_only); ?></div><div class="cnt">Not cleared yet</div></div>
            <div class="stat r"><div class="lbl">Bank not in payments</div><div class="val"><?php echo count($bank_only); ?></div><div class="cnt">Cheque debits</div></div>
        </div>

        <?php
        $rowNo = 0;
        foreach ([['Reconciled (cheque no + amount tally)', 'fa-circle-check', '#15803d', $matched, 'matched'],
                  ['Amount mismatch (same cheque no)', 'fa-triangle-exclamation', '#b45309', $mismatch, 'mismatch']] as $sec):
            list($title, $icon, $color, $rows, $type) = $sec; ?>
            <h2><i class="fa-solid <?php echo $icon; ?>" style="color:<?php echo $color; ?>"></i> <?php echo $title; ?> <span class="count"><?php echo count($rows); ?></span></h2>
            <?php if ($type === 'mismatch' && $rows): ?><p class="muted" style="margin:-4px 0 8px;">Not selected. Tick a row only to save it as reconciled <b>with a difference</b>.</p><?php endif; ?>
            <div class="card">
            <?php if ($rows): ?>
                <table>
                    <thead><tr>
                        <th style="width:34px;"><input type="checkbox" class="js-all" data-sec="<?php echo $type; ?>" <?php echo $type === 'matched' ? 'checked' : ''; ?> aria-label="Select all"></th>
                        <th>Cheque no</th><th>Invoices paid</th><th>Paid date</th><th class="num sep">Cheque amount</th>
                        <th>Txn date</th><th>Bank transaction</th><th class="num">Bank debit</th><th class="num">Difference</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($rows as $p): $rowNo++; $c = $p['c']; $b = $p['b']; $tx = $b['txns'][0]; ?>
                        <tr data-row="r<?php echo $rowNo; ?>" data-sec="<?php echo $type; ?>"
                            data-payload="<?php echo pcr_h(json_encode(['key' => $c['key'], 'txns' => [(int)$tx['id']]])); ?>">
                            <td><input type="checkbox" class="js-pick" <?php echo $type === 'matched' ? 'checked' : ''; ?> aria-label="Select"></td>
                            <td><span class="chq"><?php echo pcr_h($c['no']); ?></span><div class="rowmsg"></div></td>
                            <td><?php echo pcr_inv_html($c['inv']); ?></td>
                            <td style="white-space:nowrap;"><?php echo pcr_fmt_date($c['date']); ?></td>
                            <td class="num sep"><b><?php echo pcr_money($c['amount']); ?></b></td>
                            <td style="white-space:nowrap;"><?php echo pcr_fmt_date($b['date']); ?><?php if ($p['days']): ?><div class="muted"><?php echo ($p['days'] > 0 ? '+' : '') . $p['days']; ?> day(s)</div><?php endif; ?></td>
                            <td style="min-width:220px;"><span class="desc"><?php echo pcr_h(pcr_norm($tx['description'] . ' ' . $tx['reference'])); ?></span></td>
                            <td class="num"><b><?php echo pcr_money($b['amount']); ?></b></td>
                            <td class="num"><?php echo pcr_diff_html(round($b['amount'] - $c['amount'], 2)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?><div class="empty">None.</div><?php endif; ?>
            </div>
        <?php endforeach; ?>

        <h2><i class="fa-solid fa-money-check" style="color:#b91c1c"></i> Cheques not found in bank statement <span class="count"><?php echo count($chq_only); ?></span></h2>
        <div class="card">
        <?php if ($chq_only): ?>
            <table>
                <thead><tr><th>Cheque no</th><th>Invoices paid</th><th>Paid date</th><th class="num">Cheque amount</th><th>Reason</th><th style="text-align:right;">Action</th></tr></thead>
                <tbody>
                <?php $mNo = 0; foreach ($chq_only as $x): $c = $x['c']; $mNo++;
                    $md = ['row' => 'm' . $mNo, 'key' => $c['key'], 'no' => $c['no'], 'num' => $c['num'], 'amount' => $c['amount'],
                           'date' => $c['date'] ?: date('Y-m-d'), 'inv' => pcr_inv_text($c['inv'])]; ?>
                    <tr data-mrow="m<?php echo $mNo; ?>" data-manual="<?php echo pcr_h(json_encode($md, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)); ?>">
                        <td><span class="chq"><?php echo pcr_h($c['no']); ?></span><div class="rowmsg"></div></td>
                        <td><?php echo pcr_inv_html($c['inv']); ?></td>
                        <td><?php echo pcr_fmt_date($c['date']); ?></td>
                        <td class="num"><b><?php echo pcr_money($c['amount']); ?></b></td>
                        <td><span class="badge b-no"><?php echo pcr_h($x['why']); ?></span></td>
                        <td style="text-align:right;white-space:nowrap;">
                            <button type="button" class="btn btn-light btn-sm js-manual"><i class="fa-solid fa-hand-pointer"></i> Manual reconcile</button>
                            <button type="button" class="btn btn-danger btn-sm js-unstage" hidden title="Remove from batch"><i class="fa-solid fa-xmark"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?><div class="empty">None.</div><?php endif; ?>
        </div>

        <h2><i class="fa-solid fa-file-invoice-dollar" style="color:#b91c1c"></i> Bank cheque debits not matched <span class="count"><?php echo count($bank_only); ?></span></h2>
        <div class="card">
        <?php if ($bank_only): ?>
            <table>
                <thead><tr><th>Txn date</th><th>Cheque no</th><th>Bank transaction</th><th class="num">Bank debit</th><th>Reason</th></tr></thead>
                <tbody>
                <?php foreach ($bank_only as $x): $b = $x['b']; $tx = $b['txns'][0]; ?>
                    <tr data-txn="<?php echo (int)$tx['id']; ?>">
                        <td style="white-space:nowrap;"><?php echo pcr_fmt_date($b['date']); ?></td>
                        <td><span class="chq"><?php echo pcr_h($b['num']); ?></span></td>
                        <td style="min-width:240px;"><span class="desc"><?php echo pcr_h(pcr_norm($tx['description'] . ' ' . $tx['reference'])); ?></span><div class="rowmsg info"></div></td>
                        <td class="num"><b><?php echo pcr_money($b['amount']); ?></b></td>
                        <td><span class="badge b-no"><?php echo pcr_h($x['why']); ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?><div class="empty">None.</div><?php endif; ?>
        </div>

        <?php if ($pairs || $chq_only || $bank_only): ?>
        <div class="savebar" id="dbrSavebar" data-filters="<?php echo pcr_h(json_encode(['from' => $f_from, 'to' => $f_to])); ?>"
             data-chq-total="<?php echo count($pairs) + count($chq_only); ?>" data-bank-total="<?php echo count($pairs) + count($bank_only); ?>">
            <div class="grow"><input type="text" id="dbrRemark" maxlength="500" placeholder="Batch remark (optional)"></div>
            <span class="muted" id="dbrSelInfo"></span>
            <button type="button" class="btn btn-dark" id="dbrSave"><i class="fa-solid fa-layer-group"></i> Save batch</button>
        </div>
        <?php endif; ?>
    <?php elseif (!$ran): ?>
        <div class="alert info">Enter the cheque paid date range and press <b>Reconcile</b>.</div>
    <?php endif; ?>

<?php elseif ($tab === 'settings'): /* ═══════════ SETTINGS ═══════════ */ ?>

    <div class="setcard">
        <h3>Reconcile settings</h3>
        <p class="muted" style="margin:0 0 14px;">Only accounts with bank statements are listed. Cheques are taken from the cheque books of this account. Statement type is optional.</p>
        <?php if (!$accounts): ?>
            <div class="alert warn">No bank statements are uploaded yet. Upload one in <a href="bank_statements.php">Bank Statements</a> first.</div>
        <?php else: ?>
        <div class="setgrid">
            <div class="wide">
                <label class="f" for="set_account">Bank account <span style="color:#b91c1c">*</span></label>
                <select id="set_account">
                    <option value="">Select…</option>
                    <?php foreach ($accounts as $id => $a): ?>
                        <option value="<?php echo $id; ?>" data-types="<?php echo pcr_h(json_encode($a['types'])); ?>" <?php echo $cfg['saved_account'] === $id ? 'selected' : ''; ?>>
                            <?php echo pcr_h($a['label']); ?> — <?php echo (int)$a['uploads']; ?> statement(s), latest <?php echo pcr_fmt_date($a['last']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="f" for="set_type">Statement type (optional)</label>
                <select id="set_type" data-saved="<?php echo pcr_h($cfg['saved_type']); ?>"><option value="">All types</option></select>
            </div>
            <div>
                <label class="f" for="set_window">Bank clearing window (days after paid date)</label>
                <input type="text" id="set_window" inputmode="numeric" value="<?php echo (int)$cfg['window']; ?>">
            </div>
            <div class="wide"><label class="chk"><input type="checkbox" id="set_unbooked" <?php echo $cfg['unbooked'] ? 'checked' : ''; ?>> Also include cheques not linked to a cheque book</label></div>
        </div>
        <div style="display:flex;gap:10px;align-items:center;margin-top:16px;flex-wrap:wrap;">
            <button type="button" class="btn btn-dark" id="setSave"><i class="fa-solid fa-floppy-disk"></i> Save settings</button>
            <?php if ($cfg['updated_at']): ?><span class="muted">Last saved <?php echo pcr_h(pcr_fmt_dt($cfg['updated_at'])); ?> by <?php echo pcr_h($cfg['updated_by'] ?: '—'); ?></span><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

<?php elseif ($batch_id <= 0): /* ═══════════ SAVED BATCHES ═══════════ */ ?>

    <form class="filters" method="get">
        <input type="hidden" name="tab" value="saved">
        <div><label class="f" for="b_from">Saved from</label><input type="date" id="b_from" name="b_from" value="<?php echo pcr_h($b_from); ?>"></div>
        <div><label class="f" for="b_to">Saved to</label><input type="date" id="b_to" name="b_to" value="<?php echo pcr_h($b_to); ?>"></div>
        <div class="wide"><label class="f" for="b_q">Search</label><input type="search" id="b_q" name="b_q" value="<?php echo pcr_h($b_q); ?>" placeholder="Batch no, remark, user, cheque no or invoice no"></div>
        <div><button class="btn btn-dark" type="submit" style="width:100%;justify-content:center;"><i class="fa-solid fa-filter"></i> Filter</button></div>
    </form>
    <div class="card" style="margin-top:14px;">
    <?php if ($batches): ?>
        <table>
            <thead><tr><th>Batch</th><th>Saved</th><th>Bank account</th><th>Paid dates</th>
                <th class="num">Reconciled</th><th class="num">Not reconciled — cheques</th><th class="num">Not reconciled — bank</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($batches as $bt): ?>
                <tr data-bid="<?php echo (int)$bt['id']; ?>" data-bno="<?php echo pcr_h($bt['batch_no']); ?>" data-rc="<?php echo (int)$bt['rec_count']; ?>">
                    <td><a class="blink" href="?tab=saved&batch=<?php echo (int)$bt['id']; ?>"><?php echo pcr_h($bt['batch_no']); ?></a>
                        <?php if ($bt['remark']): ?><div class="muted"><?php echo pcr_h(mb_strimwidth($bt['remark'], 0, 60, '…')); ?></div><?php endif; ?></td>
                    <td style="white-space:nowrap;"><?php echo pcr_h(pcr_fmt_dt($bt['created_at'])); ?><div class="muted">by <?php echo pcr_h($bt['created_by'] ?: '—'); ?></div></td>
                    <td><?php echo pcr_h(pcr_acc_label($accounts, $bt['bank_account_id'])); ?><div class="muted"><?php echo pcr_h(pcr_type_label($bt['bank_type'])); ?></div></td>
                    <td class="muted" style="white-space:nowrap;"><?php echo pcr_range_label($bt['date_from'], $bt['date_to']); ?></td>
                    <td class="num"><b><?php echo (int)$bt['rec_count']; ?></b><div class="muted">Rs <?php echo pcr_money($bt['rec_chq_amount']); ?></div></td>
                    <td class="num"><b><?php echo (int)$bt['unrec_chq_count']; ?></b><div class="muted">Rs <?php echo pcr_money($bt['unrec_chq_amount']); ?></div></td>
                    <td class="num"><b><?php echo (int)$bt['unrec_bank_count']; ?></b><div class="muted">Rs <?php echo pcr_money($bt['unrec_bank_amount']); ?></div></td>
                    <td><div style="display:flex;gap:6px;justify-content:flex-end;">
                        <a class="btn btn-light btn-sm" href="?tab=saved&batch=<?php echo (int)$bt['id']; ?>"><i class="fa-solid fa-eye"></i> View</a>
                        <button type="button" class="btn btn-danger btn-sm js-del-batch"><i class="fa-solid fa-trash"></i> Delete batch</button>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?><div class="empty">No batches saved in this period.</div><?php endif; ?>
    </div>

<?php elseif (!$B): ?>

    <div class="alert warn">This batch was not found. It may have been deleted. <a href="?tab=saved">Back to saved batches</a></div>

<?php else: /* ═══════════ ONE BATCH ═══════════ */ ?>

    <p class="noprint" style="margin:0 0 12px;"><a class="blink" href="?tab=saved"><i class="fa-solid fa-arrow-left"></i> All batches</a></p>
    <div class="bhead" data-bid="<?php echo (int)$B['id']; ?>" data-bno="<?php echo pcr_h($B['batch_no']); ?>" data-rc="<?php echo (int)$B['rec_count']; ?>">
        <div>
            <h3>Batch <?php echo pcr_h($B['batch_no']); ?></h3>
            <div class="bmeta">
                <span>Saved <b><?php echo pcr_h(pcr_fmt_dt($B['created_at'])); ?></b> by <b><?php echo pcr_h($B['created_by'] ?: '—'); ?></b></span>
                <span>Account <b><?php echo pcr_h(pcr_acc_label($accounts, $B['bank_account_id'])); ?></b> · <?php echo pcr_h(pcr_type_label($B['bank_type'])); ?></span>
                <span>Paid dates <b><?php echo pcr_range_label($B['date_from'], $B['date_to']); ?></b></span>
                <span>Window <b><?php echo (int)$B['window_days']; ?> days</b></span>
            </div>
            <?php if ($B['remark']): ?><div style="margin-top:6px;"><b>Remark:</b> <?php echo pcr_h($B['remark']); ?></div><?php endif; ?>
        </div>
        <div class="noprint" style="display:flex;gap:8px;">
            <button type="button" class="btn btn-light" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
            <button type="button" class="btn btn-danger js-del-batch"><i class="fa-solid fa-trash"></i> Delete batch</button>
        </div>
    </div>
    <div class="stats">
        <div class="stat g"><div class="lbl">Reconciled</div><div class="val"><?php echo (int)$B['rec_count']; ?></div><div class="cnt">Cheques Rs <?php echo pcr_money($B['rec_chq_amount']); ?> · Bank Rs <?php echo pcr_money($B['rec_bank_amount']); ?></div></div>
        <div class="stat r"><div class="lbl">Not reconciled — cheques</div><div class="val"><?php echo (int)$B['unrec_chq_count']; ?></div><div class="cnt">Rs <?php echo pcr_money($B['unrec_chq_amount']); ?></div></div>
        <div class="stat r"><div class="lbl">Not reconciled — bank</div><div class="val"><?php echo (int)$B['unrec_bank_count']; ?></div><div class="cnt">Rs <?php echo pcr_money($B['unrec_bank_amount']); ?></div></div>
    </div>

    <h2><i class="fa-solid fa-circle-check" style="color:#15803d"></i> Reconciled <span class="count"><?php echo count($B_rec); ?></span></h2>
    <div class="card">
    <?php if ($B_rec): ?>
        <table>
            <thead><tr><th>Recon</th><th>Cheque no</th><th>Invoices paid</th><th>Paid date</th><th class="num sep">Cheque amount</th><th>Txn date</th><th>Bank transaction</th><th class="num">Bank debit</th><th class="num">Difference</th></tr></thead>
            <tbody>
            <?php foreach ($B_rec as $rc): ?>
                <tr>
                    <td style="white-space:nowrap;">#<?php echo (int)$rc['id']; ?><div><span class="badge <?php echo $rc['method'] === 'manual' ? 'b-man' : 'b-auto'; ?>"><?php echo $rc['method'] === 'manual' ? 'Manual' : 'Auto'; ?></span></div></td>
                    <td><span class="chq"><?php echo pcr_h($rc['cheque_no']); ?></span></td>
                    <td style="min-width:200px;"><?php foreach (explode('; ', (string)$rc['invoices']) as $iv): ?><div class="sub-line"><?php echo pcr_h($iv); ?></div><?php endforeach; ?></td>
                    <td style="white-space:nowrap;"><?php echo pcr_fmt_date($rc['paid_date']); ?></td>
                    <td class="num sep"><b><?php echo pcr_money($rc['cheque_amount']); ?></b></td>
                    <td style="white-space:nowrap;"><?php echo pcr_fmt_date($rc['txn_date']); ?></td>
                    <td style="min-width:220px;"><?php foreach ($rc['txns'] as $t): ?><span class="desc"><?php echo pcr_h($t['description']); ?><?php echo count($rc['txns']) > 1 ? ' — ' . pcr_money($t['debit']) : ''; ?></span><?php endforeach; ?></td>
                    <td class="num"><b><?php echo pcr_money($rc['bank_amount']); ?></b></td>
                    <td class="num"><?php echo pcr_diff_html((float)$rc['difference']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?><div class="empty">No reconciliations in this batch.</div><?php endif; ?>
    </div>

    <h2><i class="fa-solid fa-money-check" style="color:#b91c1c"></i> Not reconciled — cheques <span class="count"><?php echo count($B_unrec['cheque']); ?></span></h2>
    <div class="card">
    <?php if ($B_unrec['cheque']): ?>
        <table>
            <thead><tr><th>Cheque no</th><th>Invoices paid</th><th>Paid date</th><th class="num">Cheque amount</th><th>Reason</th><th>Now</th></tr></thead>
            <tbody>
            <?php foreach ($B_unrec['cheque'] as $u): $n = $now_chq[$u['cheque_key']] ?? null; ?>
                <tr>
                    <td><span class="chq"><?php echo pcr_h($u['cheque_no']); ?></span></td>
                    <td style="min-width:200px;"><?php foreach (explode('; ', (string)$u['invoices']) as $iv): ?><div class="sub-line"><?php echo pcr_h($iv); ?></div><?php endforeach; ?></td>
                    <td><?php echo pcr_fmt_date($u['ref_date']); ?></td>
                    <td class="num"><b><?php echo pcr_money($u['amount']); ?></b></td>
                    <td><span class="badge b-no"><?php echo pcr_h($u['reason']); ?></span></td>
                    <td><?php echo $n ? '<span class="badge b-ok">Reconciled in <a href="?tab=saved&batch=' . (int)$n['bid'] . '">' . pcr_h($n['batch_no']) . '</a></span>' : '<span class="badge b-diff">Still open</span>'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?><div class="empty">None.</div><?php endif; ?>
    </div>

    <h2><i class="fa-solid fa-file-invoice-dollar" style="color:#b91c1c"></i> Not reconciled — bank cheque debits <span class="count"><?php echo count($B_unrec['bank']); ?></span></h2>
    <div class="card">
    <?php if ($B_unrec['bank']): ?>
        <table>
            <thead><tr><th>Txn date</th><th>Cheque no</th><th>Bank transaction</th><th class="num">Bank debit</th><th>Reason</th><th>Now</th></tr></thead>
            <tbody>
            <?php foreach ($B_unrec['bank'] as $u): $n = $now_bank[(int)$u['bank_txn_id']] ?? null; ?>
                <tr>
                    <td style="white-space:nowrap;"><?php echo pcr_fmt_date($u['ref_date']); ?></td>
                    <td><span class="chq"><?php echo pcr_h($u['cheque_no']); ?></span></td>
                    <td style="min-width:240px;"><span class="desc"><?php echo pcr_h($u['description']); ?></span></td>
                    <td class="num"><b><?php echo pcr_money($u['amount']); ?></b></td>
                    <td><span class="badge b-no"><?php echo pcr_h($u['reason']); ?></span></td>
                    <td><?php
                        if (!$n) echo '<span class="badge b-no">Removed from statement</span>';
                        elseif ($n['bid']) echo '<span class="badge b-ok">Reconciled in <a href="?tab=saved&batch=' . (int)$n['bid'] . '">' . pcr_h($n['batch_no']) . '</a></span>';
                        elseif ($n['recon_status']) echo '<span class="badge b-ok">Reconciled elsewhere</span>';
                        else echo '<span class="badge b-diff">Still open</span>';
                    ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?><div class="empty">None.</div><?php endif; ?>
    </div>

<?php endif; ?>

    <!-- manual reconcile dialog -->
    <div class="modal" id="mrModal" hidden>
        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="mrTitle">
            <div class="dhead"><h3 id="mrTitle">Manual reconcile</h3><button type="button" class="xbtn" id="mrClose" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>
            <div class="dbody">
                <div class="mr-dep" id="mrDep"></div>
                <div class="mr-filter">
                    <div><label class="f" for="mrFrom">Bank txn date from</label><input type="date" id="mrFrom"></div>
                    <div><label class="f" for="mrTo">Bank txn date to</label><input type="date" id="mrTo"></div>
                    <div class="grow"><label class="f" for="mrSearch">Search</label><input type="search" id="mrSearch" placeholder="Description, cheque no or amount"></div>
                    <div><label class="chk"><input type="checkbox" id="mrChqOnly"> Cheque debits only</label></div>
                    <div><button type="button" class="btn btn-dark" id="mrLoad"><i class="fa-solid fa-magnifying-glass"></i> Load</button></div>
                </div>
                <div class="card mr-list">
                    <table>
                        <thead><tr><th style="width:34px;"></th><th>Txn date</th><th>Bank transaction</th><th>Cheque no</th><th class="num">Debit</th></tr></thead>
                        <tbody id="mrRows"><tr><td colspan="5" class="empty">Loading…</td></tr></tbody>
                    </table>
                </div>
            </div>
            <div class="dfoot">
                <div class="tally" aria-live="polite">
                    <div><span class="muted">Cheque</span><b id="mrDepAmt">0.00</b></div>
                    <div><span class="muted">Selected bank</span><b id="mrBankAmt">0.00</b></div>
                    <div><span class="muted">Difference</span><b id="mrDiff">—</b></div>
                </div>
                <button type="button" class="btn btn-light" id="mrCancel">Cancel</button>
                <button type="button" class="btn btn-dark" id="mrAdd" disabled><i class="fa-solid fa-plus"></i> Add to batch</button>
            </div>
        </div>
    </div>

    <div class="toast" id="dbrToast" role="status" aria-live="polite"></div>
</div>

<script>
(function () {
    var CSRF = <?php echo json_encode($_SESSION['pcr_csrf']); ?>;
    var toastEl = document.getElementById('dbrToast'), toastT;
    function toast(msg, type) { toastEl.textContent = msg; toastEl.className = 'toast show ' + (type || 'ok'); clearTimeout(toastT); toastT = setTimeout(function () { toastEl.className = 'toast'; }, 4200); }
    function post(data) {
        var fd = new FormData(); fd.append('csrf', CSRF);
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        return fetch(location.pathname, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); });
    }
    function money(n) { return Number(n).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
    function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    function addDays(d, n) { var x = new Date(d + 'T00:00:00'); x.setDate(x.getDate() + n); return x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0'); }
    function fmtD(d) { return d ? new Date(d + 'T00:00:00').toLocaleDateString('en-GB', {day: '2-digit', month: 'short', year: 'numeric'}) : '—'; }
    function dayDiff(a, b) { return Math.round((new Date(a + 'T00:00:00') - new Date(b + 'T00:00:00')) / 86400000); }
    function r2(n) { return Math.round(n * 100) / 100; }
    var WINDOW = <?php echo (int)$cfg['window']; ?>;

    /* ── settings ── */
    var setAcc = document.getElementById('set_account'), setType = document.getElementById('set_type');
    if (setAcc && setType) {
        var typeNames = <?php echo json_encode($PCR_BANK_TYPES); ?>;
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
            if (isNaN(w) || w < 0 || w > 120) { toast('Clearing window must be 0 to 120 days.', 'err'); return; }
            b.disabled = true;
            post({ajax: 'save_settings', account_id: setAcc.value, bank_type: setType.value, window: w, unbooked: document.getElementById('set_unbooked').checked ? 1 : 0})
            .then(function (res) { b.disabled = false; toast(res.msg || 'Done', res.ok ? 'ok' : 'err'); if (res.ok) setTimeout(function () { location.href = '?tab=run'; }, 700); })
            .catch(function () { b.disabled = false; toast('Could not reach the server. Try again.', 'err'); });
        });
    }

    /* ── delete batch ── */
    document.querySelectorAll('.js-del-batch').forEach(function (b) {
        b.addEventListener('click', function () {
            var h = b.closest('[data-bid]'), id = h.dataset.bid, no = h.dataset.bno, rc = h.dataset.rc;
            if (!confirm('Delete batch ' + no + '?\n\nAll ' + rc + ' reconciliation(s) in it are deleted and the bank statement lines lose their status, category and remark.')) return;
            b.disabled = true;
            post({ajax: 'delete_batch', id: id}).then(function (res) {
                if (!res.ok) { b.disabled = false; toast(res.msg || 'Could not delete.', 'err'); return; }
                if (h.tagName === 'TR') { h.remove(); toast('Batch ' + no + ' deleted (' + res.deleted + ' reconciliation(s)).', 'ok'); }
                else location.href = '?tab=saved';
            }).catch(function () { b.disabled = false; toast('Could not reach the server. Try again.', 'err'); });
        });
    });

    /* ═════ RECONCILE TAB ═════ */
    var savebar = document.getElementById('dbrSavebar');
    if (!savebar) return;
    var autoRows = Array.prototype.slice.call(document.querySelectorAll('tr[data-payload]'));
    var staged = {};
    var info = document.getElementById('dbrSelInfo'), saveBtn = document.getElementById('dbrSave');
    var chqTotal = +savebar.dataset.chqTotal, bankTotal = +savebar.dataset.bankTotal;

    function refresh() {
        var a = 0, dif = 0, bankUsed = 0;
        autoRows.forEach(function (tr) { if (tr.querySelector('.js-pick').checked) { a++; if (tr.dataset.sec === 'mismatch') dif++; } });
        bankUsed = a;
        var m = Object.keys(staged).length;
        Object.keys(staged).forEach(function (k) { Object.keys(staged[k].txns).forEach(function (id) { if (document.querySelector('tr[data-txn="' + id + '"]')) bankUsed++; }); });
        info.innerHTML = '<b>' + (a + m) + '</b> to reconcile (' + a + ' auto' + (dif ? ', ' + dif + ' with difference' : '') + ', ' + m + ' manual) · <b>' +
            Math.max(0, chqTotal - a - m) + '</b> cheque(s) and <b>' + Math.max(0, bankTotal - bankUsed) + '</b> bank line(s) saved as not reconciled';
    }
    document.querySelectorAll('.js-pick').forEach(function (c) { c.addEventListener('change', refresh); });
    document.querySelectorAll('.js-all').forEach(function (a) {
        a.addEventListener('change', function () { document.querySelectorAll('tr[data-sec="' + a.dataset.sec + '"] .js-pick').forEach(function (c) { c.checked = a.checked; }); refresh(); });
    });
    refresh();

    saveBtn.addEventListener('click', function () {
        var items = [], dif = 0, seen = {}, clash = '';
        autoRows.forEach(function (tr) {
            tr.classList.remove('failed'); tr.querySelector('.rowmsg').textContent = '';
            if (!tr.querySelector('.js-pick').checked) return;
            var p = JSON.parse(tr.dataset.payload);
            p.row = tr.dataset.row; p.method = 'auto'; p.allow_diff = tr.dataset.sec === 'mismatch' ? 1 : 0;
            if (p.allow_diff) dif++;
            p.txns.forEach(function (id) { if (seen[id]) clash = 'A bank line is used twice.'; seen[id] = 1; });
            items.push(p);
        });
        Object.keys(staged).forEach(function (k) {
            var s = staged[k], ids = Object.keys(s.txns).map(Number), sum = 0;
            ids.forEach(function (id) { sum += s.txns[id]; if (seen[id]) clash = 'A bank line picked for cheque ' + s.data.no + ' is also ticked in the auto list.'; seen[id] = 1; });
            var hasDiff = Math.abs(r2(sum - s.data.amount)) >= 0.005;
            if (hasDiff) dif++;
            items.push({row: k, method: 'manual', key: s.data.key, txns: ids, allow_diff: hasDiff ? 1 : 0});
        });
        if (clash) { toast(clash + ' Untick one of them.', 'err'); return; }
        var msg = items.length ? 'Save batch with ' + items.length + ' reconciliation(s)' + (dif ? ' (' + dif + ' with difference)' : '') + '?\n\nEverything not reconciled is saved in the batch as not reconciled.'
                               : 'Nothing is selected to reconcile.\nSave a batch with only the not-reconciled list?';
        if (!confirm(msg)) return;
        var f = JSON.parse(savebar.dataset.filters), old = saveBtn.innerHTML;
        saveBtn.disabled = true; saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving batch…';
        post({ajax: 'save_batch', items: JSON.stringify(items), remark: document.getElementById('dbrRemark').value, from: f.from, to: f.to})
        .then(function (res) {
            if (res.ok) { staged = {}; window.onbeforeunload = null; toast('Batch ' + res.batch_no + ' saved.', 'ok'); setTimeout(function () { location.href = '?tab=saved&batch=' + res.batch_id; }, 600); return; }
            saveBtn.disabled = false; saveBtn.innerHTML = old;
            (res.errors || []).forEach(function (e) {
                var tr = document.querySelector('tr[data-row="' + e.row + '"], tr[data-mrow="' + e.row + '"]');
                if (!tr) return;
                tr.classList.add('failed'); var m = tr.querySelector('.rowmsg'); m.className = 'rowmsg err'; m.textContent = e.msg;
            });
            toast(res.msg || 'Could not save the batch.', 'err');
        })
        .catch(function () { saveBtn.disabled = false; saveBtn.innerHTML = old; toast('Could not reach the server. Try again.', 'err'); });
    });
    window.onbeforeunload = function (e) { if (Object.keys(staged).length) { e.preventDefault(); e.returnValue = ''; } };

    /* ═════ MANUAL RECONCILE (adds to the batch) ═════ */
    if (!document.querySelector('.js-manual')) return;
    var mr = document.getElementById('mrModal'), mrRows = document.getElementById('mrRows'), mrAdd = document.getElementById('mrAdd');
    var mrFrom = document.getElementById('mrFrom'), mrTo = document.getElementById('mrTo'), mrSearch = document.getElementById('mrSearch'), mrChq = document.getElementById('mrChqOnly');
    var cur = null, curTr = null, list = [], picked = {}, lastFocus = null;

    function usedElsewhere() {
        var u = {};
        autoRows.forEach(function (tr) { if (tr.querySelector('.js-pick').checked) JSON.parse(tr.dataset.payload).txns.forEach(function (id) { u[id] = 'Ticked in the auto list'; }); });
        Object.keys(staged).forEach(function (k) { if (k !== cur.row) Object.keys(staged[k].txns).forEach(function (id) { u[id] = 'Added to batch for cheque ' + staged[k].data.no; }); });
        return u;
    }
    function tally() {
        var sum = 0, n = 0; Object.keys(picked).forEach(function (id) { sum += picked[id]; n++; }); sum = r2(sum);
        var diff = r2(sum - cur.amount), d = document.getElementById('mrDiff');
        document.getElementById('mrBankAmt').textContent = money(sum) + (n > 1 ? ' (' + n + ')' : '');
        if (!n) { d.textContent = '—'; d.className = ''; } else if (Math.abs(diff) < 0.005) { d.textContent = 'Tallied'; d.className = 'ok'; } else { d.textContent = (diff > 0 ? '+' : '') + money(diff); d.className = 'bad'; }
        mrAdd.disabled = n === 0;
    }
    function render() {
        var q = mrSearch.value.trim().toLowerCase(), used = usedElsewhere();
        var rows = list.filter(function (r) {
            if (mrChq.checked && !r.num) return false;
            return !q || (r.desc + ' ' + r.num + ' ' + r.debit + ' ' + money(r.debit)).toLowerCase().indexOf(q) !== -1;
        });
        rows.sort(function (a, b) {
            var sa = a.num === cur.num ? 0 : 1, sb = b.num === cur.num ? 0 : 1; if (sa !== sb) return sa - sb;
            var ea = Math.abs(a.debit - cur.amount) < 0.005 ? 0 : 1, eb = Math.abs(b.debit - cur.amount) < 0.005 ? 0 : 1; if (ea !== eb) return ea - eb;
            return Math.abs(dayDiff(a.date, cur.date)) - Math.abs(dayDiff(b.date, cur.date)) || a.id - b.id;
        });
        mrRows.innerHTML = rows.length ? rows.map(function (r) {
            var busy = used[r.id], on = picked[r.id] !== undefined;
            var cls = [on ? 'pick' : '', r.num && r.num === cur.num ? 'same' : '', Math.abs(r.debit - cur.amount) < 0.005 ? 'eq' : '', busy ? 'busy' : ''].join(' ');
            return '<tr class="' + cls + '" data-id="' + r.id + '"><td><input type="checkbox" ' + (on ? 'checked ' : '') + (busy ? 'disabled ' : '') + 'aria-label="Select transaction"></td>' +
                   '<td style="white-space:nowrap;">' + esc(fmtD(r.date)) + '</td><td><span class="desc">' + esc(r.desc) + '</span>' + (busy ? '<div class="muted">' + esc(busy) + '</div>' : '') + '</td>' +
                   '<td>' + (r.num ? '<span class="chq">' + esc(r.num) + '</span>' : '<span class="muted">—</span>') + '</td><td class="num">' + money(r.debit) + '</td></tr>';
        }).join('') : '<tr><td colspan="5" class="empty">No open bank debits for this date range.</td></tr>';
    }
    function load() {
        if (!mrFrom.value || !mrTo.value) { toast('Enter the bank txn date range.', 'err'); return; }
        mrRows.innerHTML = '<tr><td colspan="5" class="empty">Loading…</td></tr>';
        post({ajax: 'bank_open', from: mrFrom.value, to: mrTo.value}).then(function (res) {
            if (!res.ok) { mrRows.innerHTML = '<tr><td colspan="5" class="empty">' + esc(res.msg || 'Could not load.') + '</td></tr>'; return; }
            list = res.rows; render(); tally();
        }).catch(function () { mrRows.innerHTML = '<tr><td colspan="5" class="empty">Could not reach the server. Try again.</td></tr>'; });
    }
    function open(tr) {
        lastFocus = document.activeElement; curTr = tr; cur = JSON.parse(tr.dataset.manual); list = []; picked = {};
        if (staged[cur.row]) Object.keys(staged[cur.row].txns).forEach(function (id) { picked[id] = staged[cur.row].txns[id]; });
        mrFrom.value = addDays(cur.date, -3); mrTo.value = addDays(cur.date, Math.max(WINDOW, 7));
        mrSearch.value = ''; mrChq.checked = false;
        document.getElementById('mrTitle').textContent = 'Manual reconcile — cheque ' + cur.no;
        document.getElementById('mrDep').innerHTML =
            '<div><span class="muted">Cheque no</span><b>' + esc(cur.no) + '</b></div>' +
            '<div><span class="muted">Paid date</span><b>' + esc(fmtD(cur.date)) + '</b></div>' +
            '<div><span class="muted">Cheque amount</span><b>' + money(cur.amount) + '</b></div>' +
            '<div style="flex:1 1 260px;"><span class="muted">Invoices</span><b style="font-size:12px;font-weight:600;">' + esc(cur.inv) + '</b></div>';
        document.getElementById('mrDepAmt').textContent = money(cur.amount);
        mr.hidden = false; tally(); load(); mrFrom.focus();
    }
    function close() { mr.hidden = true; if (lastFocus && document.contains(lastFocus)) lastFocus.focus(); }
    function paintStaged() {
        document.querySelectorAll('tr[data-txn]').forEach(function (b) { b.classList.remove('used'); b.querySelector('.rowmsg').textContent = ''; });
        document.querySelectorAll('tr[data-mrow]').forEach(function (tr) {
            var k = tr.dataset.mrow, s = staged[k], msg = tr.querySelector('.rowmsg'), btn = tr.querySelector('.js-manual');
            tr.classList.toggle('staged', !!s); tr.querySelector('.js-unstage').hidden = !s;
            if (!s) { if (!tr.classList.contains('failed')) msg.textContent = ''; btn.innerHTML = '<i class="fa-solid fa-hand-pointer"></i> Manual reconcile'; return; }
            var ids = Object.keys(s.txns), sum = 0; ids.forEach(function (id) { sum += s.txns[id]; });
            var diff = r2(sum - s.data.amount);
            msg.className = 'rowmsg info';
            msg.textContent = 'Added to batch: ' + ids.length + ' bank line(s), ' + money(sum) + (Math.abs(diff) < 0.005 ? ' — tallied' : ' — difference ' + money(diff));
            btn.innerHTML = '<i class="fa-solid fa-pen"></i> Change';
            ids.forEach(function (id) { var b = document.querySelector('tr[data-txn="' + id + '"]'); if (b) { b.classList.add('used'); b.querySelector('.rowmsg').textContent = 'Added to batch for cheque ' + s.data.no; } });
        });
        refresh();
    }
    document.querySelectorAll('.js-manual').forEach(function (b) { b.addEventListener('click', function () { open(b.closest('tr')); }); });
    document.querySelectorAll('.js-unstage').forEach(function (b) { b.addEventListener('click', function () { delete staged[b.closest('tr').dataset.mrow]; paintStaged(); }); });
    document.getElementById('mrLoad').addEventListener('click', load);
    [mrFrom, mrTo].forEach(function (el) { el.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); load(); } }); });
    mrSearch.addEventListener('input', render); mrChq.addEventListener('change', render);
    document.getElementById('mrClose').addEventListener('click', close); document.getElementById('mrCancel').addEventListener('click', close);
    mr.addEventListener('click', function (e) { if (e.target === mr) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !mr.hidden) close(); });
    mrRows.addEventListener('click', function (e) {
        var tr = e.target.closest('tr[data-id]'); if (!tr) return;
        var cb = tr.querySelector('input'); if (cb.disabled) return;
        if (e.target !== cb) cb.checked = !cb.checked;
        var id = tr.dataset.id, r = list.filter(function (x) { return String(x.id) === id; })[0];
        if (cb.checked) picked[id] = r.debit; else delete picked[id];
        tr.classList.toggle('pick', cb.checked); tally();
    });
    mrAdd.addEventListener('click', function () {
        if (!Object.keys(picked).length) return;
        staged[cur.row] = {data: cur, txns: Object.assign({}, picked)};
        curTr.classList.remove('failed'); paintStaged(); close();
        toast('Added to batch. Press "Save batch" to save.', 'ok');
    });
})();
</script>

<?php include 'footer.php'; ?>
