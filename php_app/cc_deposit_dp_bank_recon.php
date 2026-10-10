<?php
/**
 * cc_deposit_dp_bank_recon.php
 * ─────────────────────────────────────────────────────────────────────
 * CC Cash Deposit (DP)  ↔  Bank Statement reconciliation — saved in BATCHES
 *
 *  Settings  : bank account (accounts that have bank statements), optional
 *              statement type, date tolerance, "only deposits to this account".
 *  Reconcile : deposits picked by delivery date and / or deposit date.
 *              Bank credits of the Settings account around the deposit dates.
 *              Ref read from the bank text: "CRDLESDEP 0000715283492 AECR"
 *              → last 10 digits 0715283492 → Cash Collectors → delivery person.
 *              One deposit (per delivery person) ↔ one bank transaction.
 *              Deposits not found can be reconciled manually (1+ bank credits).
 *  Save batch: everything ticked + manual picks are saved as ONE batch,
 *              together with a snapshot of what was NOT reconciled.
 *              Bank statement transaction + deposit get the reconcile remark.
 *  Delete    : deleting a batch deletes all its reconciliations and clears
 *              the remarks on the bank statement and the deposits.
 * ─────────────────────────────────────────────────────────────────────
 */
date_default_timezone_set('Asia/Colombo');

ob_start();
include_once 'config.php';
ob_end_clean();
include_once 'auth.php';
requireLogin();

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['dbr_csrf'])) $_SESSION['dbr_csrf'] = bin2hex(random_bytes(16));
$dbr_user = isset($_SESSION['username']) ? (string)$_SESSION['username'] : 'unknown';

const DBR_SOURCE   = 'cc_dp_deposit';
const DBR_CATEGORY = 'Collection Reconcile';   /* reconcile category shown on the bank statement */
$DBR_BANK_TYPES = ['BOC' => 'BOC', 'NDB' => 'NDB', 'SAMPATH' => 'Sampath'];

/* validation message that is safe to show the user */
class DbrUserError extends Exception {}

/* ═════════════════════════ HELPERS ═════════════════════════ */
function dbr_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function dbr_q($conn, $sql) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } }
function dbr_norm($n) { return trim(preg_replace('/\s+/u', ' ', (string)$n)); }
function dbr_key($n) {
    $n = dbr_norm($n);
    return function_exists('mb_strtolower') ? mb_strtolower($n, 'UTF-8') : strtolower($n);
}
function dbr_money($v) { return number_format((float)$v, 2); }
function dbr_valid_date($d) { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && $d !== '0000-00-00' && strtotime($d) > 0; }
function dbr_fmt_date($d) { return dbr_valid_date((string)$d) ? date('d M Y', strtotime($d)) : '—'; }
function dbr_fmt_dt($d) { return $d ? date('d M Y, h:i A', strtotime($d)) : '—'; }
function dbr_days($a, $b) { return (int)round((strtotime($a) - strtotime($b)) / 86400); }
function dbr_ids($ids) { $ids = array_filter(array_map('intval', (array)$ids)); return $ids ? implode(',', $ids) : '0'; }
function dbr_has_col($conn, $table, $col) {
    $r = dbr_q($conn, "SHOW COLUMNS FROM `$table` LIKE '" . mysqli_real_escape_string($conn, $col) . "'");
    return $r && mysqli_num_rows($r) > 0;
}
function dbr_batch_no($id) { return 'RB-' . str_pad((string)(int)$id, 5, '0', STR_PAD_LEFT); }

/* a date range; one side empty = that single day; both empty = not used */
function dbr_range($from, $to) {
    $from = dbr_valid_date((string)$from) ? $from : '';
    $to   = dbr_valid_date((string)$to)   ? $to   : '';
    if ($from === '' && $to === '') return ['', ''];
    if ($from === '') $from = $to;
    if ($to === '')   $to   = $from;
    return $to < $from ? [$to, $from] : [$from, $to];
}

/**
 * Mobile ref from a bank description. Each run of 9+ digits gives its last 10
 * digits and "0" + its last 9.  "CRDLESDEP 0000715283492 AECR" → ["0715283492"]
 */
function dbr_extract_refs($text) {
    $out = [];
    if (preg_match_all('/\d{9,}/', (string)$text, $m)) {
        foreach ($m[0] as $run) {
            if (strlen($run) >= 10) $out[] = substr($run, -10);
            $out[] = '0' . substr($run, -9);
        }
    }
    return array_values(array_unique($out));
}

/* ═════════════════════════ TABLES ═════════════════════════ */
function dbr_ensure($conn) {
    dbr_q($conn, "CREATE TABLE IF NOT EXISTS cc_dp_bank_recon_batches (
        id                 INT AUTO_INCREMENT PRIMARY KEY,
        batch_no           VARCHAR(20)   NULL,
        bank_type          VARCHAR(10)   NULL,
        bank_account_id    INT           NULL,
        dd_from            DATE NULL, dd_to DATE NULL,
        dp_from            DATE NULL, dp_to DATE NULL,
        tolerance_days     TINYINT       NOT NULL DEFAULT 0,
        remark             TEXT          NULL,
        rec_count          INT           NOT NULL DEFAULT 0,
        rec_dep_amount     DECIMAL(14,2) NOT NULL DEFAULT 0,
        rec_bank_amount    DECIMAL(14,2) NOT NULL DEFAULT 0,
        unrec_dep_count    INT           NOT NULL DEFAULT 0,
        unrec_dep_amount   DECIMAL(14,2) NOT NULL DEFAULT 0,
        unrec_bank_count   INT           NOT NULL DEFAULT 0,
        unrec_bank_amount  DECIMAL(14,2) NOT NULL DEFAULT 0,
        created_by         VARCHAR(100)  NULL,
        created_at         DATETIME      NOT NULL,
        INDEX idx_created (created_at),
        INDEX idx_acc (bank_account_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    dbr_q($conn, "CREATE TABLE IF NOT EXISTS cc_dp_bank_recon (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        batch_id         INT           NULL,
        method           VARCHAR(10)   NOT NULL DEFAULT 'auto',
        bank_type        VARCHAR(10)   NULL,
        bank_account_id  INT           NULL,
        delivery_person  VARCHAR(191)  NOT NULL,
        ref_code         VARCHAR(20)   NULL,
        deposit_date     DATE          NULL,
        txn_date         DATE          NULL,
        deposit_amount   DECIMAL(14,2) NOT NULL DEFAULT 0,
        bank_amount      DECIMAL(14,2) NOT NULL DEFAULT 0,
        difference       DECIMAL(14,2) NOT NULL DEFAULT 0,
        status           VARCHAR(20)   NOT NULL DEFAULT 'reconciled',
        remark           TEXT          NULL,
        created_by       VARCHAR(100)  NULL,
        created_at       DATETIME      NOT NULL,
        INDEX idx_batch (batch_id),
        INDEX idx_txn_date (txn_date),
        INDEX idx_acc (bank_account_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    /* table from the earlier version: add the batch columns */
    if (!dbr_has_col($conn, 'cc_dp_bank_recon', 'batch_id')) {
        dbr_q($conn, "ALTER TABLE cc_dp_bank_recon ADD COLUMN batch_id INT NULL AFTER id, ADD INDEX idx_batch (batch_id)");
    }
    if (!dbr_has_col($conn, 'cc_dp_bank_recon', 'method')) {
        dbr_q($conn, "ALTER TABLE cc_dp_bank_recon ADD COLUMN method VARCHAR(10) NOT NULL DEFAULT 'auto' AFTER batch_id");
    }

    dbr_q($conn, "CREATE TABLE IF NOT EXISTS cc_dp_bank_recon_deposits (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        recon_id         INT           NOT NULL,
        deposit_id       INT           NOT NULL,
        dp_key           VARCHAR(191)  NOT NULL,
        delivery_person  VARCHAR(191)  NOT NULL,
        delivery_dates   VARCHAR(255)  NULL,
        deposit_date     DATE          NULL,
        amount           DECIMAL(14,2) NOT NULL DEFAULT 0,
        UNIQUE KEY uq_dep_dp (deposit_id, dp_key),
        INDEX idx_recon (recon_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    dbr_q($conn, "CREATE TABLE IF NOT EXISTS cc_dp_bank_recon_bank (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        recon_id         INT           NOT NULL,
        bank_txn_id      INT UNSIGNED  NOT NULL,
        transaction_date DATE          NULL,
        description      TEXT          NULL,
        credit           DECIMAL(15,2) NOT NULL DEFAULT 0,
        UNIQUE KEY uq_txn (bank_txn_id),
        INDEX idx_recon (recon_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    /* snapshot of what was NOT reconciled when the batch was saved */
    dbr_q($conn, "CREATE TABLE IF NOT EXISTS cc_dp_bank_recon_batch_unrec (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        batch_id         INT           NOT NULL,
        side             VARCHAR(10)   NOT NULL,
        delivery_person  VARCHAR(191)  NULL,
        dp_key           VARCHAR(191)  NULL,
        ref_code         VARCHAR(20)   NULL,
        deposit_id       INT           NULL,
        delivery_dates   VARCHAR(255)  NULL,
        ref_date         DATE          NULL,
        bank_txn_id      INT UNSIGNED  NULL,
        description      TEXT          NULL,
        amount           DECIMAL(15,2) NOT NULL DEFAULT 0,
        reason           VARCHAR(255)  NULL,
        INDEX idx_batch (batch_id, side)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    dbr_q($conn, "CREATE TABLE IF NOT EXISTS cc_dp_bank_recon_settings (
        id               INT          NOT NULL PRIMARY KEY,
        bank_account_id  INT          NULL,
        bank_type        VARCHAR(10)  NULL,
        tolerance_days   TINYINT      NOT NULL DEFAULT 1,
        acc_only         TINYINT(1)   NOT NULL DEFAULT 1,
        updated_by       VARCHAR(100) NULL,
        updated_at       DATETIME     NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach ([
        'recon_status' => "VARCHAR(20)  NULL DEFAULT NULL",
        'recon_source' => "VARCHAR(30)  NULL DEFAULT NULL",
        'recon_category' => "VARCHAR(50) NULL DEFAULT NULL",
        'recon_ref_id' => "INT          NULL DEFAULT NULL",
        'recon_remark' => "TEXT         NULL",
        'recon_by'     => "VARCHAR(100) NULL DEFAULT NULL",
        'recon_at'     => "DATETIME     NULL DEFAULT NULL",
    ] as $c => $def) {
        if (!dbr_has_col($conn, 'bank_statement_transactions', $c)) {
            dbr_q($conn, "ALTER TABLE bank_statement_transactions ADD COLUMN `$c` $def");
        }
    }
    /* lines reconciled before the category existed */
    dbr_q($conn, "UPDATE bank_statement_transactions SET recon_category = '" . DBR_CATEGORY . "'
                   WHERE recon_source = '" . DBR_SOURCE . "' AND (recon_category IS NULL OR recon_category = '')");
    if (!dbr_has_col($conn, 'cc_cash_deposit_dp', 'bank_recon_remark')) {
        dbr_q($conn, "ALTER TABLE cc_cash_deposit_dp ADD COLUMN bank_recon_remark TEXT NULL");
    }

    /* reconciliations saved before batches existed → one batch per save day */
    $r = dbr_q($conn, "SELECT DATE(created_at) AS d, MIN(bank_type) AS bt, MIN(bank_account_id) AS acc,
                              MIN(created_by) AS cb, MIN(created_at) AS ca, COUNT(*) AS c,
                              SUM(deposit_amount) AS da, SUM(bank_amount) AS ba
                         FROM cc_dp_bank_recon WHERE batch_id IS NULL GROUP BY DATE(created_at)");
    if ($r) while ($row = mysqli_fetch_assoc($r)) {
        $st = mysqli_prepare($conn, "INSERT INTO cc_dp_bank_recon_batches
            (bank_type, bank_account_id, remark, rec_count, rec_dep_amount, rec_bank_amount, created_by, created_at)
            VALUES (?, ?, 'Saved before batches were added', ?, ?, ?, ?, ?)");
        $acc = (int)$row['acc']; $c = (int)$row['c']; $da = (float)$row['da']; $ba = (float)$row['ba'];
        mysqli_stmt_bind_param($st, 'siiddss', $row['bt'], $acc, $c, $da, $ba, $row['cb'], $row['ca']);
        mysqli_stmt_execute($st);
        $bid = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($st);
        if ($bid) {
            dbr_q($conn, "UPDATE cc_dp_bank_recon_batches SET batch_no = '" . dbr_batch_no($bid) . "' WHERE id = $bid");
            dbr_q($conn, "UPDATE cc_dp_bank_recon SET batch_id = $bid WHERE batch_id IS NULL AND DATE(created_at) = '" . mysqli_real_escape_string($conn, $row['d']) . "'");
        }
    }
}
dbr_ensure($conn);

/* bank accounts that have bank statements uploaded, with their statement types */
function dbr_statement_accounts($conn) {
    $acc = [];
    $r = dbr_q($conn, "SELECT account_id, UPPER(bank_type) AS bt, MAX(statement_date) AS last_stmt,
                              MAX(account_number) AS stmt_acno, COUNT(*) AS uploads
                         FROM bank_statement_uploads GROUP BY account_id, UPPER(bank_type)");
    if ($r) while ($row = mysqli_fetch_assoc($r)) {
        $id = (int)$row['account_id'];
        if ($id <= 0) continue;
        if (!isset($acc[$id])) $acc[$id] = ['label' => '', 'acno' => (string)$row['stmt_acno'], 'types' => [], 'last' => '', 'uploads' => 0];
        $acc[$id]['types'][$row['bt']] = ['last' => $row['last_stmt'], 'uploads' => (int)$row['uploads']];
        $acc[$id]['uploads'] += (int)$row['uploads'];
        if ($row['last_stmt'] > $acc[$id]['last']) $acc[$id]['last'] = $row['last_stmt'];
    }
    if ($acc) {
        $r = dbr_q($conn, "SELECT cba.id,
                                  CONCAT(COALESCE(NULLIF(b.bank_name,''), cba.bank_code, ''), ' / ',
                                         COALESCE(NULLIF(bb.branch_name,''), cba.branch_code, ''), ' (', cba.account_no, ')') AS label
                             FROM company_bank_accounts cba
                        LEFT JOIN banks b ON b.bank_code = cba.bank_code
                        LEFT JOIN bank_branches bb ON bb.bank_code = cba.bank_code AND bb.branch_code = cba.branch_code
                            WHERE cba.id IN (" . dbr_ids(array_keys($acc)) . ")");
        if ($r) while ($row = mysqli_fetch_assoc($r)) $acc[(int)$row['id']]['label'] = $row['label'];
    }
    foreach ($acc as $id => &$a) {
        if ($a['label'] === '') $a['label'] = 'Account #' . $id . ($a['acno'] !== '' ? ' (' . $a['acno'] . ')' : '');
        ksort($a['types']);
    }
    unset($a);
    uasort($acc, function ($x, $y) { return strcasecmp($x['label'], $y['label']); });
    return $acc;
}

function dbr_get_settings($conn) {
    $s = ['account_id' => 0, 'bank_type' => '', 'tol' => 1, 'acc_only' => true, 'updated_by' => '', 'updated_at' => ''];
    $r = dbr_q($conn, "SELECT * FROM cc_dp_bank_recon_settings WHERE id = 1 LIMIT 1");
    if ($r && ($row = mysqli_fetch_assoc($r))) {
        $s['account_id'] = (int)$row['bank_account_id'];
        $s['bank_type']  = strtoupper((string)$row['bank_type']);
        $s['tol']        = max(0, min(7, (int)$row['tolerance_days']));
        $s['acc_only']   = (int)$row['acc_only'] === 1;
        $s['updated_by'] = (string)$row['updated_by'];
        $s['updated_at'] = (string)$row['updated_at'];
    }
    return $s;
}

/* settings checked against the accounts that really have statements */
function dbr_effective_settings($cfg, $accounts) {
    $acc  = isset($accounts[$cfg['account_id']]) ? $cfg['account_id'] : 0;
    $type = ($acc && $cfg['bank_type'] !== '' && isset($accounts[$acc]['types'][$cfg['bank_type']])) ? $cfg['bank_type'] : '';
    return ['account' => $acc, 'type' => $type, 'tol' => $cfg['tol'], 'acc_only' => $cfg['acc_only']];
}

function dbr_type_label($bt) {
    global $DBR_BANK_TYPES;
    $bt = strtoupper((string)$bt);
    return $bt === '' ? 'All types' : ($DBR_BANK_TYPES[$bt] ?? $bt);
}

/* cash collectors: ref (mobile) → delivery person, and person → ref */
function dbr_collectors($conn) {
    $by_ref = []; $by_key = [];
    $r = dbr_q($conn, "SELECT delivery_person, ref_code FROM cash_collectors WHERE ref_code IS NOT NULL AND ref_code <> ''");
    if ($r) while ($row = mysqli_fetch_assoc($r)) {
        $name = dbr_norm($row['delivery_person']);
        $by_ref[$row['ref_code']] = $name;
        $by_key[dbr_key($name)]   = $row['ref_code'];
    }
    return [$by_ref, $by_key];
}

/* rebuild the bank-side remark kept on a deposit, from all its saved reconciliations */
function dbr_refresh_deposit_remark($conn, $deposit_id) {
    $deposit_id = (int)$deposit_id;
    $parts = [];
    $r = dbr_q($conn, "SELECT r.id, r.delivery_person, r.status, bt.batch_no, b.transaction_date, b.credit, b.description
                         FROM cc_dp_bank_recon_deposits rd
                         JOIN cc_dp_bank_recon r      ON r.id = rd.recon_id
                         JOIN cc_dp_bank_recon_bank b ON b.recon_id = r.id
                    LEFT JOIN cc_dp_bank_recon_batches bt ON bt.id = r.batch_id
                        WHERE rd.deposit_id = $deposit_id
                        ORDER BY r.id, b.id");
    if ($r) while ($row = mysqli_fetch_assoc($r)) {
        $parts[] = ($row['batch_no'] ? $row['batch_no'] . ' ' : '') . 'Recon #' . $row['id']
                 . ' (' . $row['delivery_person'] . ($row['status'] !== 'reconciled' ? ', with difference' : '') . '): '
                 . 'Bank ' . $row['transaction_date'] . ' Rs ' . dbr_money($row['credit']) . ' — ' . dbr_norm($row['description']);
    }
    $txt = $parts ? implode(' || ', $parts) : null;
    $st = mysqli_prepare($conn, "UPDATE cc_cash_deposit_dp SET bank_recon_remark = ? WHERE id = ?");
    mysqli_stmt_bind_param($st, 'si', $txt, $deposit_id);
    mysqli_stmt_execute($st);
    mysqli_stmt_close($st);
}

/* ═════════════════════════ MATCHING ENGINE ═════════════════════════
 * $o = [account, type, tol, acc_only, dd_from, dd_to, dp_from, dp_to]
 * Returns pairs (one deposit ↔ one bank txn), dep_only, bank_only, totals …
 */
function dbr_run($conn, $o) {
    $R = ['error' => '', 'pairs' => [], 'dep_only' => [], 'bank_only' => [], 'already' => ['dep' => 0, 'bank' => 0],
          'totals' => ['dep' => 0.0, 'bank' => 0.0, 'dep_cnt' => 0, 'bank_cnt' => 0],
          'bank_range' => ['', ''], 'by_ref' => [], 'by_key' => []];
    if ($o['account'] <= 0)                        { $R['error'] = 'Set the bank account in the Settings tab first.'; return $R; }
    if ($o['dd_from'] === '' && $o['dp_from'] === '') { $R['error'] = 'Enter a delivery date range, a deposit date range, or both.'; return $R; }

    list($by_ref, $by_key) = dbr_collectors($conn);
    $R['by_ref'] = $by_ref; $R['by_key'] = $by_key;
    $tol = (int)$o['tol'];

    /* 1. deposits (bank deposits only) by delivery date and / or deposit date */
    $where = "d.handed_over_bo = 0";
    if ($o['acc_only']) $where .= " AND d.bank_account_id = " . (int)$o['account'];
    if ($o['dd_from'] !== '') {
        $where .= " AND d.id IN (SELECT p2.deposit_id FROM cc_cash_deposit_dp_persons p2
                                   JOIN cc_cash_deposit_dp d2 ON d2.id = p2.deposit_id
                                  WHERE COALESCE(p2.delivery_date, d2.delivery_date) BETWEEN '"
                . mysqli_real_escape_string($conn, $o['dd_from']) . "' AND '" . mysqli_real_escape_string($conn, $o['dd_to']) . "')";
    }
    if ($o['dp_from'] !== '') {
        $where .= " AND d.deposit_date BETWEEN '" . mysqli_real_escape_string($conn, $o['dp_from']) . "' AND '"
                . mysqli_real_escape_string($conn, $o['dp_to']) . "'";
    }
    $parts = [];
    $r = dbr_q($conn, "SELECT d.id, d.deposit_date, p.delivery_person, p.amount,
                              COALESCE(p.delivery_date, d.delivery_date) AS dd
                         FROM cc_cash_deposit_dp d
                         JOIN cc_cash_deposit_dp_persons p ON p.deposit_id = d.id
                        WHERE $where
                        ORDER BY d.deposit_date, d.id, p.sort_order");
    if ($r) while ($row = mysqli_fetch_assoc($r)) {
        $name = dbr_norm($row['delivery_person']);
        if ($name === '') continue;
        $k  = dbr_key($name);
        $pk = $row['id'] . '|' . $k;
        if (!isset($parts[$pk])) $parts[$pk] = ['deposit_id' => (int)$row['id'], 'dp' => $name, 'key' => $k,
                                                'deposit_date' => dbr_valid_date((string)$row['deposit_date']) ? $row['deposit_date'] : '',
                                                'amount' => 0.0, 'dd' => []];
        $parts[$pk]['amount'] += (float)$row['amount'];
        if (dbr_valid_date((string)$row['dd'])) $parts[$pk]['dd'][$row['dd']] = true;
    }
    if ($parts) {
        $ids = array_unique(array_map(function ($p) { return $p['deposit_id']; }, $parts));
        $r = dbr_q($conn, "SELECT deposit_id, dp_key FROM cc_dp_bank_recon_deposits WHERE deposit_id IN (" . dbr_ids($ids) . ")");
        if ($r) while ($row = mysqli_fetch_assoc($r)) {
            $pk = $row['deposit_id'] . '|' . $row['dp_key'];
            if (isset($parts[$pk])) { unset($parts[$pk]); $R['already']['dep']++; }
        }
    }
    $dunits = [];
    foreach ($parts as $p) {
        ksort($p['dd']);
        $u = ['dp' => $p['dp'], 'key' => $p['key'], 'date' => $p['deposit_date'], 'amount' => round($p['amount'], 2), 'parts' => [$p], 'used' => false];
        $R['totals']['dep'] += $u['amount']; $R['totals']['dep_cnt']++;
        if ($p['deposit_date'] === '') { $R['dep_only'][] = ['g' => $u, 'why' => 'No deposit date on the deposit']; continue; }
        $dunits[] = $u;
    }

    /* 2. bank credits around the deposit dates */
    $dates = array_map(function ($u) { return $u['date']; }, $dunits);
    if ($dates) {
        $b_from = date('Y-m-d', strtotime(min($dates) . " -$tol days"));
        $b_to   = date('Y-m-d', strtotime(max($dates) . " +$tol days"));
    } elseif ($o['dp_from'] !== '') {
        $b_from = date('Y-m-d', strtotime($o['dp_from'] . " -$tol days"));
        $b_to   = date('Y-m-d', strtotime($o['dp_to'] . " +$tol days"));
    } else {
        $b_from = $o['dd_from'];
        $b_to   = date('Y-m-d', strtotime($o['dd_to'] . " +$tol days"));
    }
    $R['bank_range'] = [$b_from, $b_to];

    $bunits = []; $seen = [];
    $type = (string)$o['type'];
    $acc  = (int)$o['account'];
    $st = mysqli_prepare($conn, "SELECT t.id, t.transaction_date, t.description, t.reference, t.credit, t.balance, t.recon_status
                                   FROM bank_statement_transactions t
                                   JOIN bank_statement_uploads u ON u.id = t.upload_id
                                  WHERE u.account_id = ? " . ($type !== '' ? "AND UPPER(u.bank_type) = ?" : "AND ? = ''") . "
                                    AND t.transaction_date BETWEEN ? AND ?
                                    AND t.credit > 0
                                  ORDER BY t.transaction_date, t.id");
    mysqli_stmt_bind_param($st, 'isss', $acc, $type, $b_from, $b_to);
    mysqli_stmt_execute($st);
    $rs = mysqli_stmt_get_result($st);
    while ($rs && ($t = mysqli_fetch_assoc($rs))) {
        /* the same line uploaded in two overlapping statements counts once */
        $dk = $t['transaction_date'] . '|' . dbr_norm($t['description']) . '|' . $t['credit'] . '|' . $t['balance'];
        if (isset($seen[$dk])) continue;
        $seen[$dk] = true;
        if ($t['recon_status'] !== null && $t['recon_status'] !== '') { $R['already']['bank']++; continue; }

        $t['credit'] = (float)$t['credit'];
        $R['totals']['bank'] += $t['credit']; $R['totals']['bank_cnt']++;
        $refs = dbr_extract_refs($t['description'] . ' ' . $t['reference']);
        $dp = ''; $ref = '';
        foreach ($refs as $c) if (isset($by_ref[$c])) { $dp = $by_ref[$c]; $ref = $c; break; }
        if ($ref === '' && $refs) $ref = $refs[0];

        $u = ['dp' => $dp, 'key' => $dp !== '' ? dbr_key($dp) : '', 'date' => $t['transaction_date'],
              'amount' => round($t['credit'], 2), 'txns' => [$t], 'ref' => $ref, 'used' => false];
        if ($dp === '') {
            $R['bank_only'][] = ['g' => $u, 'why' => $refs ? 'Ref ' . $ref . ' is not in Cash Collectors' : 'No ref number in the description'];
            continue;
        }
        $bunits[] = $u;
    }
    mysqli_stmt_close($st);

    /* 3. one deposit ↔ one bank txn, same delivery person.
          pass 1 same date + same amount → tallied; pass 2 within tolerance + same amount → tallied;
          pass 3 same date, amount differs → mismatch; pass 4 within tolerance, amount differs → mismatch.
          Closest date, then closest amount wins. */
    usort($dunits, function ($a, $b) { return strcmp($a['date'], $b['date']) ?: ($b['amount'] <=> $a['amount']); });
    foreach ([[0, true, 'matched'], [$tol, true, 'matched'], [0, false, 'mismatch'], [$tol, false, 'mismatch']] as $ps) {
        list($maxDays, $needEqual, $ptype) = $ps;
        foreach ($dunits as $di => $du) {
            if ($du['used']) continue;
            $best = null; $bestScore = null;
            foreach ($bunits as $bi => $bu) {
                if ($bu['used'] || $bu['key'] !== $du['key']) continue;
                $days = abs(dbr_days($bu['date'], $du['date']));
                if ($days > $maxDays) continue;
                $amtDiff = abs(round($bu['amount'] - $du['amount'], 2));
                if ($needEqual && $amtDiff >= 0.005) continue;
                $score = [$days, $amtDiff];
                if ($best === null || $score < $bestScore) { $best = $bi; $bestScore = $score; }
            }
            if ($best !== null) {
                $dunits[$di]['used'] = true; $bunits[$best]['used'] = true;
                $R['pairs'][] = ['type' => $ptype, 'd' => $dunits[$di], 'b' => $bunits[$best], 'days' => dbr_days($bunits[$best]['date'], $du['date'])];
            }
        }
    }
    foreach ($dunits as $du) if (!$du['used']) {
        $R['dep_only'][] = ['g' => $du, 'why' => isset($by_key[$du['key']]) ? 'No bank transaction found for this deposit' : 'Delivery person has no ref code in Cash Collectors'];
    }
    foreach ($bunits as $bu) if (!$bu['used']) {
        $R['bank_only'][] = ['g' => $bu, 'why' => 'No matching deposit for this delivery person'];
    }
    usort($R['pairs'], function ($a, $b) { return strcmp($a['d']['date'], $b['d']['date']) ?: strcasecmp($a['d']['dp'], $b['d']['dp']); });
    return $R;
}

/* ═════════════════════════ SAVE ONE RECONCILIATION (inside a batch transaction) ═════════════════════════ */
function dbr_save_item($conn, $it, &$ctx) {
    $dpKey    = dbr_key((string)($it['dp'] ?? ''));
    $depIds   = array_values(array_unique(array_filter(array_map('intval', (array)($it['deposits'] ?? [])))));
    $txnIds   = array_values(array_unique(array_filter(array_map('intval', (array)($it['txns'] ?? [])))));
    $allowDif = !empty($it['allow_diff']);
    $method   = (($it['method'] ?? '') === 'manual') ? 'manual' : 'auto';
    if ($dpKey === '' || !$depIds || !$txnIds) throw new DbrUserError('Incomplete row.');

    foreach ($depIds as $d) if (isset($ctx['dep_keys'][$d . '|' . $dpKey])) throw new DbrUserError('This deposit is used twice in the batch.');
    foreach ($txnIds as $t) if (isset($ctx['txn_ids'][$t]))               throw new DbrUserError('A bank transaction is used twice in the batch.');

    /* deposit side — always re-read from the database */
    $depRows = []; $depTotal = 0.0; $dpName = ''; $depDates = [];
    $r = mysqli_query($conn, "SELECT p.deposit_id, p.delivery_person, p.amount,
                                     COALESCE(p.delivery_date, d.delivery_date) AS dd, d.deposit_date, d.handed_over_bo
                                FROM cc_cash_deposit_dp_persons p
                                JOIN cc_cash_deposit_dp d ON d.id = p.deposit_id
                               WHERE p.deposit_id IN (" . dbr_ids($depIds) . ")");
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        if (dbr_key($row['delivery_person']) !== $dpKey || (int)$row['handed_over_bo'] === 1) continue;
        $id = (int)$row['deposit_id'];
        if (!isset($depRows[$id])) $depRows[$id] = ['amount' => 0.0, 'dd' => [], 'deposit_date' => $row['deposit_date']];
        $depRows[$id]['amount'] += (float)$row['amount'];
        if (dbr_valid_date((string)$row['dd'])) $depRows[$id]['dd'][$row['dd']] = true;
        $depTotal += (float)$row['amount'];
        $dpName = dbr_norm($row['delivery_person']);
        if (dbr_valid_date((string)$row['deposit_date'])) $depDates[$row['deposit_date']] = true;
    }
    if (count($depRows) !== count($depIds)) throw new DbrUserError('A deposit was changed or deleted. Run the reconcile again.');
    $r = mysqli_query($conn, "SELECT deposit_id FROM cc_dp_bank_recon_deposits
                               WHERE deposit_id IN (" . dbr_ids($depIds) . ") AND dp_key = '" . mysqli_real_escape_string($conn, $dpKey) . "' LIMIT 1");
    if ($r && mysqli_fetch_assoc($r)) throw new DbrUserError('This deposit is already reconciled.');

    /* bank side */
    $txns = []; $bankTotal = 0.0; $txnDates = [];
    $r = mysqli_query($conn, "SELECT t.id, t.transaction_date, t.description, t.reference, t.credit, t.recon_status, u.account_id, u.bank_type
                                FROM bank_statement_transactions t
                                JOIN bank_statement_uploads u ON u.id = t.upload_id
                               WHERE t.id IN (" . dbr_ids($txnIds) . ")");
    while ($r && ($row = mysqli_fetch_assoc($r))) $txns[(int)$row['id']] = $row;
    foreach ($txnIds as $tid) {
        if (!isset($txns[$tid])) throw new DbrUserError('A bank transaction no longer exists.');
        $t = $txns[$tid];
        if ($t['recon_status'] !== null && $t['recon_status'] !== '') throw new DbrUserError('A bank transaction is already reconciled.');
        if ((int)$t['account_id'] !== $ctx['account'] || ($ctx['type'] !== '' && strtoupper($t['bank_type']) !== $ctx['type'])) {
            throw new DbrUserError('A bank transaction is not from the bank account in Settings.');
        }
        if ((float)$t['credit'] <= 0) throw new DbrUserError('A bank transaction is not a credit.');
        $bankTotal += (float)$t['credit'];
        $txnDates[$t['transaction_date']] = true;
    }

    $depTotal  = round($depTotal, 2);
    $bankTotal = round($bankTotal, 2);
    $diff      = round($bankTotal - $depTotal, 2);
    if (abs($diff) >= 0.005 && !$allowDif) throw new DbrUserError('Amounts no longer tally. Run the reconcile again.');
    $status = abs($diff) < 0.005 ? 'reconciled' : 'difference';

    $ref = $ctx['by_key'][$dpKey] ?? '';
    foreach ($txns as $t) {
        foreach (dbr_extract_refs($t['description'] . ' ' . $t['reference']) as $cand) {
            if (isset($ctx['by_ref'][$cand]) && dbr_key($ctx['by_ref'][$cand]) === $dpKey) { $ref = $cand; break 2; }
        }
    }
    ksort($depDates); ksort($txnDates);
    $depDate = $depDates ? array_key_first($depDates) : null;
    $txnDate = $txnDates ? array_key_first($txnDates) : null;
    $now = $ctx['now']; $user = $ctx['user']; $bid = $ctx['batch_id']; $bankType = $ctx['type']; $acc = $ctx['account'];
    $remDb = $ctx['remark'] !== '' ? $ctx['remark'] : null;

    $st = mysqli_prepare($conn, "INSERT INTO cc_dp_bank_recon
        (batch_id, method, bank_type, bank_account_id, delivery_person, ref_code, deposit_date, txn_date,
         deposit_amount, bank_amount, difference, status, remark, created_by, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    mysqli_stmt_bind_param($st, 'ississssdddssss', $bid, $method, $bankType, $acc, $dpName, $ref, $depDate, $txnDate,
                           $depTotal, $bankTotal, $diff, $status, $remDb, $user, $now);
    if (!mysqli_stmt_execute($st)) throw new Exception('insert recon');
    $rid = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($st);

    $st = mysqli_prepare($conn, "INSERT INTO cc_dp_bank_recon_deposits
        (recon_id, deposit_id, dp_key, delivery_person, delivery_dates, deposit_date, amount) VALUES (?,?,?,?,?,?,?)");
    $allDD = [];
    foreach ($depRows as $did => $d) {
        ksort($d['dd']);
        $allDD += $d['dd'];
        $dds = implode(', ', array_keys($d['dd']));
        $amt = round($d['amount'], 2);
        $ddep = dbr_valid_date((string)$d['deposit_date']) ? $d['deposit_date'] : null;
        mysqli_stmt_bind_param($st, 'iissssd', $rid, $did, $dpKey, $dpName, $dds, $ddep, $amt);
        if (!mysqli_stmt_execute($st)) throw new DbrUserError('This deposit is already reconciled.');
    }
    mysqli_stmt_close($st);
    ksort($allDD);

    /* remark written on the bank statement transaction */
    $bankRemark = $ctx['batch_no'] . ' Recon #' . $rid . ($method === 'manual' ? ' (manual)' : '')
                . ' | CC DP Deposit #' . implode(', #', array_keys($depRows))
                . ' | DP: ' . $dpName . ($ref !== '' ? ' | Ref: ' . $ref : '')
                . ' | Deposit date: ' . ($depDate ?: '—')
                . ($allDD ? ' | Delivery date(s): ' . implode(', ', array_keys($allDD)) : '')
                . ' | Deposit Rs ' . dbr_money($depTotal) . ' | Bank Rs ' . dbr_money($bankTotal)
                . ($status !== 'reconciled' ? ' | Difference Rs ' . dbr_money($diff) : '')
                . ' | by ' . $user . ' on ' . $now
                . ($ctx['remark'] !== '' ? ' | Note: ' . $ctx['remark'] : '');

    $stB = mysqli_prepare($conn, "INSERT INTO cc_dp_bank_recon_bank (recon_id, bank_txn_id, transaction_date, description, credit) VALUES (?,?,?,?,?)");
    $stU = mysqli_prepare($conn, "UPDATE bank_statement_transactions
                                     SET recon_status = ?, recon_source = ?, recon_category = ?, recon_ref_id = ?, recon_remark = ?, recon_by = ?, recon_at = ?
                                   WHERE id = ? AND (recon_status IS NULL OR recon_status = '')");
    $src = DBR_SOURCE;
    $cat = DBR_CATEGORY;
    foreach ($txnIds as $tid) {
        $t    = $txns[$tid];
        $desc = dbr_norm($t['description'] . ($t['reference'] ? ' ' . $t['reference'] : ''));
        $cr   = (float)$t['credit'];
        $td   = $t['transaction_date'];
        mysqli_stmt_bind_param($stB, 'iissd', $rid, $tid, $td, $desc, $cr);
        if (!mysqli_stmt_execute($stB)) throw new DbrUserError('A bank transaction is already reconciled.');
        mysqli_stmt_bind_param($stU, 'sssisssi', $status, $src, $cat, $rid, $bankRemark, $user, $now, $tid);
        mysqli_stmt_execute($stU);
        if (mysqli_stmt_affected_rows($stU) !== 1) throw new DbrUserError('A bank transaction was just reconciled by someone else.');
    }
    mysqli_stmt_close($stB); mysqli_stmt_close($stU);

    foreach (array_keys($depRows) as $did) { $ctx['dep_keys'][$did . '|' . $dpKey] = true; $ctx['deposit_ids'][$did] = true; }
    foreach ($txnIds as $tid) $ctx['txn_ids'][$tid] = true;
    $ctx['rec_count']++;
    $ctx['rec_dep']  += $depTotal;
    $ctx['rec_bank'] += $bankTotal;
    return $rid;
}

/* delete a whole batch: its reconciliations, bank remarks, deposit remarks, snapshot */
function dbr_delete_batch($conn, $bid) {
    $bid = (int)$bid;
    $rids = []; $depIds = [];
    $r = dbr_q($conn, "SELECT id FROM cc_dp_bank_recon WHERE batch_id = $bid");
    if ($r) while ($row = mysqli_fetch_assoc($r)) $rids[] = (int)$row['id'];
    if ($rids) {
        $r = dbr_q($conn, "SELECT DISTINCT deposit_id FROM cc_dp_bank_recon_deposits WHERE recon_id IN (" . dbr_ids($rids) . ")");
        if ($r) while ($row = mysqli_fetch_assoc($r)) $depIds[] = (int)$row['deposit_id'];
    }
    $in = dbr_ids($rids);
    mysqli_begin_transaction($conn);
    try {
        if ($rids) {
            mysqli_query($conn, "UPDATE bank_statement_transactions
                                    SET recon_status = NULL, recon_source = NULL, recon_category = NULL, recon_ref_id = NULL,
                                        recon_remark = NULL, recon_by = NULL, recon_at = NULL
                                  WHERE recon_source = '" . DBR_SOURCE . "' AND recon_ref_id IN ($in)");
            mysqli_query($conn, "DELETE FROM cc_dp_bank_recon_bank     WHERE recon_id IN ($in)");
            mysqli_query($conn, "DELETE FROM cc_dp_bank_recon_deposits WHERE recon_id IN ($in)");
            mysqli_query($conn, "DELETE FROM cc_dp_bank_recon          WHERE id IN ($in)");
        }
        mysqli_query($conn, "DELETE FROM cc_dp_bank_recon_batch_unrec WHERE batch_id = $bid");
        mysqli_query($conn, "DELETE FROM cc_dp_bank_recon_batches     WHERE id = $bid");
        foreach ($depIds as $did) dbr_refresh_deposit_remark($conn, $did);
        mysqli_commit($conn);
        return count($rids);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        return false;
    }
}

/* ═════════════════════════ AJAX ═════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    if (!hash_equals((string)$_SESSION['dbr_csrf'], (string)($_POST['csrf'] ?? ''))) {
        echo json_encode(['ok' => false, 'msg' => 'Security check failed. Reload the page and try again.']); exit;
    }
    $act = (string)$_POST['ajax'];
    $JSON = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /* ── SAVE BATCH: selected + manual reconciliations, plus not-reconciled snapshot ── */
    if ($act === 'save_batch') {
        $accounts = dbr_statement_accounts($conn);
        $eff      = dbr_effective_settings(dbr_get_settings($conn), $accounts);
        if ($eff['account'] <= 0) { echo json_encode(['ok' => false, 'msg' => 'Set the bank account in Settings first.']); exit; }

        $items  = json_decode((string)($_POST['items'] ?? '[]'), true);
        $items  = is_array($items) ? array_slice($items, 0, 500) : [];
        $remark = trim(mb_substr((string)($_POST['remark'] ?? ''), 0, 500));
        list($dd_from, $dd_to) = dbr_range($_POST['dd_from'] ?? '', $_POST['dd_to'] ?? '');
        list($dp_from, $dp_to) = dbr_range($_POST['dp_from'] ?? '', $_POST['dp_to'] ?? '');

        /* run the same reconcile again to take the not-reconciled snapshot */
        $run = dbr_run($conn, $eff + ['dd_from' => $dd_from, 'dd_to' => $dd_to, 'dp_from' => $dp_from, 'dp_to' => $dp_to]);
        if ($run['error'] !== '') { echo json_encode(['ok' => false, 'msg' => $run['error']]); exit; }
        if (!$items && !$run['pairs'] && !$run['dep_only'] && !$run['bank_only']) {
            echo json_encode(['ok' => false, 'msg' => 'Nothing to save.']); exit;
        }

        $now = date('Y-m-d H:i:s');
        $ctx = ['account' => $eff['account'], 'type' => $eff['type'], 'by_ref' => $run['by_ref'], 'by_key' => $run['by_key'],
                'user' => $dbr_user, 'now' => $now, 'remark' => $remark, 'batch_id' => 0, 'batch_no' => '',
                'dep_keys' => [], 'txn_ids' => [], 'deposit_ids' => [], 'rec_count' => 0, 'rec_dep' => 0.0, 'rec_bank' => 0.0];

        mysqli_begin_transaction($conn);
        try {
            $st = mysqli_prepare($conn, "INSERT INTO cc_dp_bank_recon_batches
                (bank_type, bank_account_id, dd_from, dd_to, dp_from, dp_to, tolerance_days, remark, created_by, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?)");
            $bt  = $eff['type'] !== '' ? $eff['type'] : null;
            $acc = $eff['account']; $tol = $eff['tol'];
            $a1 = $dd_from ?: null; $a2 = $dd_to ?: null; $a3 = $dp_from ?: null; $a4 = $dp_to ?: null;
            $remDb = $remark !== '' ? $remark : null;
            mysqli_stmt_bind_param($st, 'sissssisss', $bt, $acc, $a1, $a2, $a3, $a4, $tol, $remDb, $dbr_user, $now);
            if (!mysqli_stmt_execute($st)) throw new Exception('batch insert');
            $bid = (int)mysqli_insert_id($conn);
            mysqli_stmt_close($st);
            $ctx['batch_id'] = $bid;
            $ctx['batch_no'] = dbr_batch_no($bid);
            mysqli_query($conn, "UPDATE cc_dp_bank_recon_batches SET batch_no = '" . $ctx['batch_no'] . "' WHERE id = $bid");

            /* 1. reconciliations — every row must save, otherwise nothing is saved */
            $errors = [];
            foreach ($items as $it) {
                try { dbr_save_item($conn, $it, $ctx); }
                catch (DbrUserError $e) { $errors[] = ['row' => (string)($it['row'] ?? ''), 'msg' => $e->getMessage()]; }
                catch (Throwable $e)    { $errors[] = ['row' => (string)($it['row'] ?? ''), 'msg' => 'Could not be saved (database error).']; }
            }
            if ($errors) {
                mysqli_rollback($conn);
                echo json_encode(['ok' => false, 'msg' => count($errors) . ' row(s) could not be saved, so the batch was not saved. Untick them or run the reconcile again.', 'errors' => $errors], $JSON);
                exit;
            }

            /* 2. snapshot of what is NOT reconciled */
            $unrec = [];
            foreach ($run['pairs'] as $p) {
                $d = $p['d']; $b = $p['b']; $t = $b['txns'][0]; $part = $d['parts'][0];
                if (!isset($ctx['dep_keys'][$part['deposit_id'] . '|' . $d['key']])) {
                    $unrec[] = ['deposit', $d, $p['type'] === 'matched'
                        ? 'Tallied with bank txn ' . $b['date'] . ' Rs ' . dbr_money($b['amount']) . ' but not selected'
                        : 'Amount mismatch: bank txn ' . $b['date'] . ' Rs ' . dbr_money($b['amount'])];
                }
                if (!isset($ctx['txn_ids'][(int)$t['id']])) {
                    $unrec[] = ['bank', $b, $p['type'] === 'matched'
                        ? 'Tallied with deposit #' . $part['deposit_id'] . ' but not selected'
                        : 'Amount mismatch: deposit #' . $part['deposit_id'] . ' Rs ' . dbr_money($d['amount'])];
                }
            }
            foreach ($run['dep_only'] as $x) {
                $part = $x['g']['parts'][0];
                if (!isset($ctx['dep_keys'][$part['deposit_id'] . '|' . $x['g']['key']])) $unrec[] = ['deposit', $x['g'], $x['why']];
            }
            foreach ($run['bank_only'] as $x) {
                if (!isset($ctx['txn_ids'][(int)$x['g']['txns'][0]['id']])) $unrec[] = ['bank', $x['g'], $x['why']];
            }

            $st = mysqli_prepare($conn, "INSERT INTO cc_dp_bank_recon_batch_unrec
                (batch_id, side, delivery_person, dp_key, ref_code, deposit_id, delivery_dates, ref_date, bank_txn_id, description, amount, reason)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $uc = ['deposit' => [0, 0.0], 'bank' => [0, 0.0]];
            foreach ($unrec as $u) {
                list($side, $g, $why) = $u;
                if ($side === 'deposit') {
                    $part = $g['parts'][0];
                    $dp = $g['dp']; $key = $g['key']; $ref = $run['by_key'][$key] ?? null;
                    $did = (int)$part['deposit_id']; $dds = implode(', ', array_keys($part['dd']));
                    $rdate = $g['date'] !== '' ? $g['date'] : null; $tid = null; $desc = null;
                } else {
                    $t = $g['txns'][0];
                    $dp = $g['dp'] !== '' ? $g['dp'] : null; $key = $g['key'] !== '' ? $g['key'] : null; $ref = $g['ref'] !== '' ? $g['ref'] : null;
                    $did = null; $dds = null; $rdate = $g['date']; $tid = (int)$t['id'];
                    $desc = dbr_norm($t['description'] . ' ' . $t['reference']);
                }
                $amt = round($g['amount'], 2);
                $why = mb_substr($why, 0, 255);
                mysqli_stmt_bind_param($st, 'issssissisds', $bid, $side, $dp, $key, $ref, $did, $dds, $rdate, $tid, $desc, $amt, $why);
                if (!mysqli_stmt_execute($st)) throw new Exception('unrec insert');
                $uc[$side][0]++; $uc[$side][1] += $amt;
            }
            mysqli_stmt_close($st);

            $st = mysqli_prepare($conn, "UPDATE cc_dp_bank_recon_batches
                SET rec_count = ?, rec_dep_amount = ?, rec_bank_amount = ?,
                    unrec_dep_count = ?, unrec_dep_amount = ?, unrec_bank_count = ?, unrec_bank_amount = ?
                WHERE id = ?");
            $rc = $ctx['rec_count']; $rd = round($ctx['rec_dep'], 2); $rb = round($ctx['rec_bank'], 2);
            $udc = $uc['deposit'][0]; $uda = round($uc['deposit'][1], 2); $ubc = $uc['bank'][0]; $uba = round($uc['bank'][1], 2);
            mysqli_stmt_bind_param($st, 'iddididi', $rc, $rd, $rb, $udc, $uda, $ubc, $uba, $bid);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);

            foreach (array_keys($ctx['deposit_ids']) as $did) dbr_refresh_deposit_remark($conn, $did);

            mysqli_commit($conn);
            echo json_encode(['ok' => true, 'batch_id' => $bid, 'batch_no' => $ctx['batch_no'], 'saved' => $rc], $JSON);
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            echo json_encode(['ok' => false, 'msg' => 'The batch could not be saved. Try again.']);
        }
        exit;
    }

    /* ── DELETE BATCH: all reconciliations in it ── */
    if ($act === 'delete_batch') {
        $bid = (int)($_POST['id'] ?? 0);
        $n = dbr_delete_batch($conn, $bid);
        echo json_encode($n === false ? ['ok' => false, 'msg' => 'Could not delete the batch. Try again.'] : ['ok' => true, 'deleted' => $n]);
        exit;
    }

    /* ── SAVE SETTINGS ── */
    if ($act === 'save_settings') {
        $acc_list = dbr_statement_accounts($conn);
        $acc  = (int)($_POST['account_id'] ?? 0);
        $bt   = strtoupper(trim((string)($_POST['bank_type'] ?? '')));
        $tol  = max(0, min(7, (int)($_POST['tol'] ?? 1)));
        $only = !empty($_POST['acc_only']) ? 1 : 0;
        if (!isset($acc_list[$acc])) { echo json_encode(['ok' => false, 'msg' => 'Select a bank account that has bank statements uploaded.']); exit; }
        if ($bt !== '' && !isset($acc_list[$acc]['types'][$bt])) {
            echo json_encode(['ok' => false, 'msg' => 'No ' . dbr_type_label($bt) . ' statements are uploaded for this account. Pick another type or "All types".']); exit;
        }
        $now = date('Y-m-d H:i:s'); $btDb = $bt !== '' ? $bt : null;
        $st = mysqli_prepare($conn, "INSERT INTO cc_dp_bank_recon_settings (id, bank_account_id, bank_type, tolerance_days, acc_only, updated_by, updated_at)
                                     VALUES (1, ?, ?, ?, ?, ?, ?)
                                     ON DUPLICATE KEY UPDATE bank_account_id = VALUES(bank_account_id), bank_type = VALUES(bank_type),
                                         tolerance_days = VALUES(tolerance_days), acc_only = VALUES(acc_only),
                                         updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)");
        mysqli_stmt_bind_param($st, 'isiiss', $acc, $btDb, $tol, $only, $dbr_user, $now);
        $ok = mysqli_stmt_execute($st);
        mysqli_stmt_close($st);
        echo json_encode($ok ? ['ok' => true, 'msg' => 'Settings saved.'] : ['ok' => false, 'msg' => 'Could not save settings. Try again.']);
        exit;
    }

    /* ── OPEN bank credits for manual reconcile ── */
    if ($act === 'bank_open') {
        $eff = dbr_effective_settings(dbr_get_settings($conn), dbr_statement_accounts($conn));
        if ($eff['account'] <= 0) { echo json_encode(['ok' => false, 'msg' => 'Set the bank account in Settings first.']); exit; }
        list($from, $to) = dbr_range($_POST['from'] ?? '', $_POST['to'] ?? '');
        if ($from === '') { echo json_encode(['ok' => false, 'msg' => 'Enter the date range.']); exit; }
        if (dbr_days($to, $from) > 92) { echo json_encode(['ok' => false, 'msg' => 'Pick a date range of 3 months or less.']); exit; }

        list($by_ref, ) = dbr_collectors($conn);
        $bt = $eff['type']; $acc = $eff['account'];
        $st = mysqli_prepare($conn, "SELECT t.id, t.transaction_date, t.description, t.reference, t.credit, t.balance
                                       FROM bank_statement_transactions t
                                       JOIN bank_statement_uploads u ON u.id = t.upload_id
                                      WHERE u.account_id = ? " . ($bt !== '' ? "AND UPPER(u.bank_type) = ?" : "AND ? = ''") . "
                                        AND t.transaction_date BETWEEN ? AND ?
                                        AND t.credit > 0
                                        AND (t.recon_status IS NULL OR t.recon_status = '')
                                      ORDER BY t.transaction_date, t.id
                                      LIMIT 2000");
        mysqli_stmt_bind_param($st, 'isss', $acc, $bt, $from, $to);
        mysqli_stmt_execute($st);
        $rs = mysqli_stmt_get_result($st);
        $rows = []; $seen = [];
        while ($rs && ($t = mysqli_fetch_assoc($rs))) {
            $dk = $t['transaction_date'] . '|' . dbr_norm($t['description']) . '|' . $t['credit'] . '|' . $t['balance'];
            if (isset($seen[$dk])) continue;
            $seen[$dk] = true;
            $refs = dbr_extract_refs($t['description'] . ' ' . $t['reference']);
            $dp = ''; $ref = '';
            foreach ($refs as $c) if (isset($by_ref[$c])) { $dp = $by_ref[$c]; $ref = $c; break; }
            if ($ref === '' && $refs) $ref = $refs[0];
            $rows[] = ['id' => (int)$t['id'], 'date' => $t['transaction_date'], 'desc' => dbr_norm($t['description'] . ' ' . $t['reference']),
                       'credit' => round((float)$t['credit'], 2), 'ref' => $ref, 'dp' => $dp, 'key' => $dp !== '' ? dbr_key($dp) : ''];
        }
        mysqli_stmt_close($st);
        echo json_encode(['ok' => true, 'rows' => $rows], $JSON);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Unknown action.']); exit;
}

/* ═════════════════════════ PAGE DATA ═════════════════════════ */
$accounts = dbr_statement_accounts($conn);
$cfg      = dbr_get_settings($conn);
$eff      = dbr_effective_settings($cfg, $accounts);
$tab      = in_array($_GET['tab'] ?? '', ['saved', 'settings'], true) ? $_GET['tab'] : 'run';

/* reconcile tab filters */
$ran = isset($_GET['run']);
if ($ran) {
    list($f_from,  $f_to)  = dbr_range($_GET['dd_from'] ?? '', $_GET['dd_to'] ?? '');
    list($fd_from, $fd_to) = dbr_range($_GET['dp_from'] ?? '', $_GET['dp_to'] ?? '');
} else {
    $f_from = date('Y-m-d', strtotime('-7 days')); $f_to = date('Y-m-d');
    $fd_from = ''; $fd_to = '';
}
$run = null;
if ($tab === 'run' && $ran) {
    $run = dbr_run($conn, $eff + ['dd_from' => $f_from, 'dd_to' => $f_to, 'dp_from' => $fd_from, 'dp_to' => $fd_to]);
}
$pairs     = $run ? $run['pairs'] : [];
$dep_only  = $run ? $run['dep_only'] : [];
$bank_only = $run ? $run['bank_only'] : [];
$by_key    = $run ? $run['by_key'] : [];
$matched   = array_values(array_filter($pairs, function ($p) { return $p['type'] === 'matched'; }));
$mismatch  = array_values(array_filter($pairs, function ($p) { return $p['type'] === 'mismatch'; }));
$sum = function ($list, $side) { $s = 0; foreach ($list as $p) $s += $p[$side]['amount']; return $s; };

/* saved tab: batch list or one batch */
$batch_id = (int)($_GET['batch'] ?? 0);
$b_from = dbr_valid_date($_GET['b_from'] ?? '') ? $_GET['b_from'] : date('Y-m-d', strtotime('-30 days'));
$b_to   = dbr_valid_date($_GET['b_to']   ?? '') ? $_GET['b_to']   : date('Y-m-d');
$b_q    = trim((string)($_GET['b_q'] ?? ''));
$batches = []; $B = null; $B_recons = []; $B_unrec = ['deposit' => [], 'bank' => []]; $now_dep = []; $now_bank = [];

if ($tab === 'saved' && $batch_id <= 0) {
    $sql = "SELECT * FROM cc_dp_bank_recon_batches WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)";
    $types = 'ss'; $args = [$b_from, $b_to];
    if ($b_q !== '') {
        $sql .= " AND (batch_no LIKE ? OR remark LIKE ? OR created_by LIKE ?
                   OR id IN (SELECT batch_id FROM cc_dp_bank_recon WHERE delivery_person LIKE ? OR ref_code LIKE ?))";
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
    $r = dbr_q($conn, "SELECT * FROM cc_dp_bank_recon_batches WHERE id = $batch_id");
    $B = $r ? mysqli_fetch_assoc($r) : null;
    if ($B) {
        $r = dbr_q($conn, "SELECT * FROM cc_dp_bank_recon WHERE batch_id = $batch_id ORDER BY txn_date, delivery_person, id");
        if ($r) while ($row = mysqli_fetch_assoc($r)) { $row['deps'] = []; $row['txns'] = []; $B_recons[(int)$row['id']] = $row; }
        if ($B_recons) {
            $in = dbr_ids(array_keys($B_recons));
            $r = dbr_q($conn, "SELECT * FROM cc_dp_bank_recon_deposits WHERE recon_id IN ($in) ORDER BY deposit_id");
            if ($r) while ($row = mysqli_fetch_assoc($r)) $B_recons[(int)$row['recon_id']]['deps'][] = $row;
            $r = dbr_q($conn, "SELECT * FROM cc_dp_bank_recon_bank WHERE recon_id IN ($in) ORDER BY transaction_date, id");
            if ($r) while ($row = mysqli_fetch_assoc($r)) $B_recons[(int)$row['recon_id']]['txns'][] = $row;
        }
        $r = dbr_q($conn, "SELECT * FROM cc_dp_bank_recon_batch_unrec WHERE batch_id = $batch_id ORDER BY ref_date, delivery_person, id");
        if ($r) while ($row = mysqli_fetch_assoc($r)) $B_unrec[$row['side'] === 'bank' ? 'bank' : 'deposit'][] = $row;

        /* what happened to the not-reconciled items after this batch */
        $dids = array_filter(array_map(function ($u) { return (int)$u['deposit_id']; }, $B_unrec['deposit']));
        if ($dids) {
            $r = dbr_q($conn, "SELECT rd.deposit_id, rd.dp_key, bt.id AS bid, bt.batch_no
                                 FROM cc_dp_bank_recon_deposits rd
                                 JOIN cc_dp_bank_recon r ON r.id = rd.recon_id
                            LEFT JOIN cc_dp_bank_recon_batches bt ON bt.id = r.batch_id
                                WHERE rd.deposit_id IN (" . dbr_ids($dids) . ")");
            if ($r) while ($row = mysqli_fetch_assoc($r)) $now_dep[$row['deposit_id'] . '|' . $row['dp_key']] = $row;
        }
        $tids = array_filter(array_map(function ($u) { return (int)$u['bank_txn_id']; }, $B_unrec['bank']));
        if ($tids) {
            $r = dbr_q($conn, "SELECT t.id, t.recon_status, t.recon_source, bt.id AS bid, bt.batch_no
                                 FROM bank_statement_transactions t
                            LEFT JOIN cc_dp_bank_recon_bank rb ON rb.bank_txn_id = t.id
                            LEFT JOIN cc_dp_bank_recon r ON r.id = rb.recon_id
                            LEFT JOIN cc_dp_bank_recon_batches bt ON bt.id = r.batch_id
                                WHERE t.id IN (" . dbr_ids($tids) . ")");
            if ($r) while ($row = mysqli_fetch_assoc($r)) $now_bank[(int)$row['id']] = $row;
        }
    }
}

function dbr_account_label($accounts, $id) {
    return $accounts[(int)$id]['label'] ?? ('Account #' . (int)$id);
}
function dbr_range_label($a, $b) {
    if (!dbr_valid_date((string)$a)) return '—';
    return $a === $b ? dbr_fmt_date($a) : dbr_fmt_date($a) . ' – ' . dbr_fmt_date($b);
}

include 'header.php';

/* ── render helpers ── */
function dbr_parts_html($g) {
    $h = '';
    foreach ($g['parts'] as $p) {
        $h .= '<a class="dep-link" href="cc_cash_deposit_dp.php?search=' . urlencode($p['dp']) . '" target="_blank" rel="noopener">#' . (int)$p['deposit_id'] . '</a>'
            . '<div class="muted">Delivery ' . dbr_h(implode(', ', array_map('dbr_fmt_date', array_keys($p['dd']))) ?: '—') . '</div>';
    }
    return $h;
}
function dbr_txns_html($g) {
    $h = '';
    foreach ($g['txns'] as $t) $h .= '<span class="desc">' . dbr_h(dbr_norm($t['description'] . ' ' . $t['reference'])) . '</span>';
    return $h;
}
function dbr_payload($d, $b) {
    return json_encode([
        'dp'       => $d['dp'],
        'deposits' => array_values(array_map(function ($p) { return $p['deposit_id']; }, $d['parts'])),
        'txns'     => array_values(array_map(function ($t) { return (int)$t['id']; }, $b['txns'])),
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
function dbr_diff_html($diff) {
    return abs($diff) < 0.005 ? '<span class="badge b-ok">Tallied</span>'
         : '<span class="' . ($diff > 0 ? 'diff-pos' : 'diff-neg') . '">' . ($diff > 0 ? '+' : '') . dbr_money($diff) . '</span>';
}
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
</style>

<div class="dbr">
    <h1><i class="fa-solid fa-scale-balanced"></i> DP Deposit ↔ Bank Reconciliation</h1>
    <p class="sub">Match CC cash deposits (DP) to bank statement credits using the delivery person's ref code (mobile no), and save them as batches.</p>

    <div class="tabs">
        <a href="?tab=run" class="<?php echo $tab === 'run' ? 'on' : ''; ?>"><i class="fa-solid fa-wand-magic-sparkles"></i> Reconcile</a>
        <a href="?tab=saved" class="<?php echo $tab === 'saved' ? 'on' : ''; ?>"><i class="fa-solid fa-layer-group"></i> Saved batches</a>
        <a href="?tab=settings" class="<?php echo $tab === 'settings' ? 'on' : ''; ?>"><i class="fa-solid fa-gear"></i> Settings</a>
    </div>

<?php if ($tab === 'run'): /* ═══════════ RECONCILE ═══════════ */ ?>

    <?php if ($eff['account'] <= 0): ?>
        <div class="alert warn">No bank account is set for reconcile yet. <a href="?tab=settings">Open Settings</a> and choose the bank account.</div>
    <?php else: ?>
        <div class="setbar">
            <span><i class="fa-solid fa-building-columns"></i> <b><?php echo dbr_h(dbr_account_label($accounts, $eff['account'])); ?></b></span>
            <span class="pill"><?php echo dbr_h(dbr_type_label($eff['type'])); ?></span>
            <span class="pill"><?php echo $eff['tol'] === 0 ? 'Same date only' : '± ' . $eff['tol'] . ' day' . ($eff['tol'] > 1 ? 's' : ''); ?></span>
            <span class="pill"><?php echo $eff['acc_only'] ? 'Only deposits to this account' : 'Deposits to any account'; ?></span>
            <a href="?tab=settings" class="setlink"><i class="fa-solid fa-gear"></i> Change</a>
        </div>
    <?php endif; ?>

    <form class="filters" method="get">
        <input type="hidden" name="tab" value="run">
        <input type="hidden" name="run" value="1">
        <div><label class="f" for="dd_from">Delivery date from</label><input type="date" id="dd_from" name="dd_from" value="<?php echo dbr_h($f_from); ?>"></div>
        <div><label class="f" for="dd_to">Delivery date to</label><input type="date" id="dd_to" name="dd_to" value="<?php echo dbr_h($f_to); ?>"></div>
        <div><label class="f" for="dp_from">Deposit date from</label><input type="date" id="dp_from" name="dp_from" value="<?php echo dbr_h($fd_from); ?>"></div>
        <div><label class="f" for="dp_to">Deposit date to</label><input type="date" id="dp_to" name="dp_to" value="<?php echo dbr_h($fd_to); ?>"></div>
        <div><button type="button" class="btn btn-light" id="dbrClearDates" style="width:100%;justify-content:center;"><i class="fa-solid fa-eraser"></i> Clear dates</button></div>
        <div><button class="btn btn-dark" type="submit" style="width:100%;justify-content:center;" <?php echo $eff['account'] > 0 ? '' : 'disabled'; ?>><i class="fa-solid fa-play"></i> Reconcile</button></div>
    </form>

    <?php if ($run && $run['error'] !== ''): ?><div class="alert err"><?php echo dbr_h($run['error']); ?></div><?php endif; ?>

    <?php if ($run && $run['error'] === ''): ?>
        <?php if (!$run['by_ref']): ?>
            <div class="alert warn">No ref codes are saved in <a href="cash_collectors.php">Cash Collectors</a> yet, so no bank credit can be linked to a delivery person.</div>
        <?php endif; ?>
        <div class="alert info">
            Deposits with
            <?php if ($f_from !== ''): ?>delivery date <b><?php echo dbr_range_label($f_from, $f_to); ?></b><?php endif; ?>
            <?php if ($f_from !== '' && $fd_from !== ''): ?> and <?php endif; ?>
            <?php if ($fd_from !== ''): ?>deposit date <b><?php echo dbr_range_label($fd_from, $fd_to); ?></b><?php endif; ?>
            checked against <b><?php echo dbr_h(dbr_type_label($eff['type'])); ?></b> statement credits from
            <b><?php echo dbr_range_label($run['bank_range'][0], $run['bank_range'][1]); ?></b>.
            <?php if ($run['already']['dep'] || $run['already']['bank']): ?>
                Already reconciled and left out: <?php echo $run['already']['dep']; ?> deposit line(s), <?php echo $run['already']['bank']; ?> bank credit(s).
            <?php endif; ?>
        </div>

        <div class="stats">
            <div class="stat"><div class="lbl">Deposits (open)</div><div class="val"><?php echo dbr_money($run['totals']['dep']); ?></div><div class="cnt"><?php echo $run['totals']['dep_cnt']; ?> line(s)</div></div>
            <div class="stat"><div class="lbl">Bank credits (open)</div><div class="val"><?php echo dbr_money($run['totals']['bank']); ?></div><div class="cnt"><?php echo $run['totals']['bank_cnt']; ?> transaction(s)</div></div>
            <div class="stat g"><div class="lbl">Tallied</div><div class="val"><?php echo count($matched); ?></div><div class="cnt">Rs <?php echo dbr_money($sum($matched, 'd')); ?></div></div>
            <div class="stat a"><div class="lbl">Amount mismatch</div><div class="val"><?php echo count($mismatch); ?></div><div class="cnt">Dep <?php echo dbr_money($sum($mismatch, 'd')); ?> / Bank <?php echo dbr_money($sum($mismatch, 'b')); ?></div></div>
            <div class="stat r"><div class="lbl">Not in bank</div><div class="val"><?php echo count($dep_only); ?></div><div class="cnt">Deposit side only</div></div>
            <div class="stat r"><div class="lbl">Not in deposits</div><div class="val"><?php echo count($bank_only); ?></div><div class="cnt">Bank side only</div></div>
        </div>

        <?php
        $sections = [
            ['title' => 'Reconciled (tallied one-to-one)', 'icon' => 'fa-circle-check', 'color' => '#15803d', 'rows' => $matched, 'type' => 'matched'],
            ['title' => 'Amount mismatch', 'icon' => 'fa-triangle-exclamation', 'color' => '#b45309', 'rows' => $mismatch, 'type' => 'mismatch'],
        ];
        $rowNo = 0;
        foreach ($sections as $sec): ?>
            <h2><i class="fa-solid <?php echo $sec['icon']; ?>" style="color:<?php echo $sec['color']; ?>"></i> <?php echo $sec['title']; ?> <span class="count"><?php echo count($sec['rows']); ?></span></h2>
            <?php if ($sec['type'] === 'mismatch' && $sec['rows']): ?>
                <p class="muted" style="margin:-4px 0 8px;">Not selected. Tick a row only to save it as reconciled <b>with a difference</b>. Unticked rows are saved in the batch as not reconciled.</p>
            <?php endif; ?>
            <div class="card">
            <?php if ($sec['rows']): ?>
                <table>
                    <thead><tr>
                        <th style="width:34px;"><input type="checkbox" class="js-all" data-sec="<?php echo $sec['type']; ?>" <?php echo $sec['type'] === 'matched' ? 'checked' : ''; ?> aria-label="Select all"></th>
                        <th>Delivery person</th><th>Deposit</th><th>Deposit date</th><th class="num sep">Deposit amount</th>
                        <th>Ref code</th><th>Txn date</th><th>Bank transaction</th><th class="num">Bank amount</th><th class="num">Difference</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($sec['rows'] as $p): $rowNo++; $d = $p['d']; $b = $p['b']; ?>
                        <tr data-row="r<?php echo $rowNo; ?>" data-sec="<?php echo $sec['type']; ?>" data-payload="<?php echo dbr_h(dbr_payload($d, $b)); ?>">
                            <td><input type="checkbox" class="js-pick" <?php echo $sec['type'] === 'matched' ? 'checked' : ''; ?> aria-label="Select"></td>
                            <td class="name"><?php echo dbr_h($d['dp']); ?><div class="rowmsg"></div></td>
                            <td style="white-space:nowrap;"><?php echo dbr_parts_html($d); ?></td>
                            <td style="white-space:nowrap;"><?php echo dbr_fmt_date($d['date']); ?></td>
                            <td class="num sep"><b><?php echo dbr_money($d['amount']); ?></b></td>
                            <td><code><?php echo dbr_h($b['ref']); ?></code></td>
                            <td style="white-space:nowrap;"><?php echo dbr_fmt_date($b['date']); ?>
                                <?php if ($p['days'] !== 0): ?><div class="muted"><?php echo ($p['days'] > 0 ? '+' : '') . $p['days']; ?> day(s)</div><?php endif; ?></td>
                            <td style="min-width:220px;"><?php echo dbr_txns_html($b); ?></td>
                            <td class="num"><b><?php echo dbr_money($b['amount']); ?></b></td>
                            <td class="num"><?php echo dbr_diff_html(round($b['amount'] - $d['amount'], 2)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?><div class="empty">None.</div><?php endif; ?>
            </div>
        <?php endforeach; ?>

        <h2><i class="fa-solid fa-building-columns" style="color:#b91c1c"></i> Deposits not found in bank statement <span class="count"><?php echo count($dep_only); ?></span></h2>
        <div class="card">
        <?php if ($dep_only): ?>
            <table>
                <thead><tr><th>Delivery person</th><th>Mobile no (ref code)</th><th>Deposit</th><th>Deposit date</th><th class="num">Deposit amount</th><th>Reason</th><th style="text-align:right;">Action</th></tr></thead>
                <tbody>
                <?php $mNo = 0; foreach ($dep_only as $x): $g = $x['g']; $mNo++;
                    $part = $g['parts'][0];
                    $mdata = [
                        'row' => 'm' . $mNo, 'dp' => $g['dp'], 'key' => $g['key'], 'mobile' => $by_key[$g['key']] ?? '',
                        'deposits' => [$part['deposit_id']], 'amount' => round($g['amount'], 2),
                        'date' => $g['date'] !== '' ? $g['date'] : (array_key_last($part['dd']) ?? date('Y-m-d')),
                        'dep_date' => $g['date'],
                    ];
                ?>
                    <tr data-mrow="m<?php echo $mNo; ?>" data-manual="<?php echo dbr_h(json_encode($mdata, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)); ?>">
                        <td class="name"><?php echo dbr_h($g['dp']); ?><div class="rowmsg"></div></td>
                        <td><?php $mob = $by_key[$g['key']] ?? ''; echo $mob !== '' ? '<code>' . dbr_h($mob) . '</code>' : '<a href="cash_collectors.php" class="muted" title="Add the mobile no in Cash Collectors">Not set</a>'; ?></td>
                        <td style="white-space:nowrap;"><?php echo dbr_parts_html($g); ?></td>
                        <td><?php echo dbr_fmt_date($g['date']); ?></td>
                        <td class="num"><b><?php echo dbr_money($g['amount']); ?></b></td>
                        <td><span class="badge b-no"><?php echo dbr_h($x['why']); ?></span></td>
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

        <h2><i class="fa-solid fa-file-invoice-dollar" style="color:#b91c1c"></i> Bank credits not matched to a deposit <span class="count"><?php echo count($bank_only); ?></span></h2>
        <div class="card">
        <?php if ($bank_only): ?>
            <table>
                <thead><tr><th>Txn date</th><th>Delivery person</th><th>Ref (last 10 digits)</th><th>Bank transaction</th><th class="num">Bank amount</th><th>Reason</th></tr></thead>
                <tbody>
                <?php foreach ($bank_only as $x): $g = $x['g']; ?>
                    <tr data-txn="<?php echo (int)$g['txns'][0]['id']; ?>">
                        <td style="white-space:nowrap;"><?php echo dbr_fmt_date($g['date']); ?></td>
                        <td class="name"><?php echo $g['dp'] !== '' ? dbr_h($g['dp']) : '<span class="muted">—</span>'; ?></td>
                        <td><?php echo $g['ref'] !== '' ? '<code>' . dbr_h($g['ref']) . '</code>' : '<span class="muted">—</span>'; ?></td>
                        <td style="min-width:240px;"><?php echo dbr_txns_html($g); ?><div class="rowmsg info"></div></td>
                        <td class="num"><b><?php echo dbr_money($g['amount']); ?></b></td>
                        <td><span class="badge b-no"><?php echo dbr_h($x['why']); ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?><div class="empty">None.</div><?php endif; ?>
        </div>

        <?php if ($pairs || $dep_only || $bank_only): ?>
        <div class="savebar" id="dbrSavebar"
             data-filters="<?php echo dbr_h(json_encode(['dd_from' => $f_from, 'dd_to' => $f_to, 'dp_from' => $fd_from, 'dp_to' => $fd_to])); ?>"
             data-dep-total="<?php echo count($pairs) + count($dep_only); ?>"
             data-bank-total="<?php echo count($pairs) + count($bank_only); ?>">
            <div class="grow"><input type="text" id="dbrRemark" maxlength="500" placeholder="Batch remark (optional)"></div>
            <span class="muted" id="dbrSelInfo"></span>
            <button type="button" class="btn btn-dark" id="dbrSave"><i class="fa-solid fa-layer-group"></i> Save batch</button>
        </div>
        <?php endif; ?>
    <?php elseif (!$ran): ?>
        <div class="alert info">Enter the delivery date and / or deposit date, then press <b>Reconcile</b>.</div>
    <?php endif; ?>

<?php elseif ($tab === 'settings'): /* ═══════════ SETTINGS ═══════════ */ ?>

    <div class="setcard">
        <h3>Reconcile settings</h3>
        <p class="muted" style="margin:0 0 14px;">The bank account list shows only accounts that have bank statements uploaded. Statement type is optional — leave it on <b>All types</b> to check every statement of the account.</p>
        <?php if (!$accounts): ?>
            <div class="alert warn">No bank statements are uploaded yet. Upload one in <a href="bank_statements.php">Bank Statements</a> first.</div>
        <?php else: ?>
        <div class="setgrid">
            <div class="wide">
                <label class="f" for="set_account">Bank account <span style="color:#b91c1c">*</span></label>
                <select id="set_account">
                    <option value="">Select…</option>
                    <?php foreach ($accounts as $id => $a): ?>
                        <option value="<?php echo $id; ?>" data-types="<?php echo dbr_h(json_encode(array_map(function ($t) { return $t['last']; }, $a['types']))); ?>"
                                <?php echo $cfg['account_id'] === $id ? 'selected' : ''; ?>>
                            <?php echo dbr_h($a['label']); ?> — <?php echo $a['uploads']; ?> statement(s), latest <?php echo dbr_fmt_date($a['last']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="f" for="set_type">Statement type (optional)</label>
                <select id="set_type" data-saved="<?php echo dbr_h($cfg['bank_type']); ?>"><option value="">All types</option></select>
                <span class="muted" id="set_type_hint"></span>
            </div>
            <div>
                <label class="f" for="set_tol">Date tolerance</label>
                <select id="set_tol">
                    <?php for ($i = 0; $i <= 7; $i++): ?><option value="<?php echo $i; ?>" <?php echo $cfg['tol'] === $i ? 'selected' : ''; ?>><?php echo $i === 0 ? 'Same date only' : "± $i day" . ($i > 1 ? 's' : ''); ?></option><?php endfor; ?>
                </select>
            </div>
            <div class="wide"><label class="chk"><input type="checkbox" id="set_acc_only" <?php echo $cfg['acc_only'] ? 'checked' : ''; ?>> Only include deposits entered for this bank account</label></div>
        </div>
        <div style="display:flex;gap:10px;align-items:center;margin-top:16px;flex-wrap:wrap;">
            <button type="button" class="btn btn-dark" id="setSave"><i class="fa-solid fa-floppy-disk"></i> Save settings</button>
            <?php if ($cfg['updated_at']): ?><span class="muted">Last saved <?php echo dbr_h(dbr_fmt_dt($cfg['updated_at'])); ?> by <?php echo dbr_h($cfg['updated_by'] ?: '—'); ?></span><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

<?php elseif ($batch_id <= 0): /* ═══════════ SAVED BATCHES: LIST ═══════════ */ ?>

    <form class="filters" method="get">
        <input type="hidden" name="tab" value="saved">
        <div><label class="f" for="b_from">Saved from</label><input type="date" id="b_from" name="b_from" value="<?php echo dbr_h($b_from); ?>"></div>
        <div><label class="f" for="b_to">Saved to</label><input type="date" id="b_to" name="b_to" value="<?php echo dbr_h($b_to); ?>"></div>
        <div class="wide"><label class="f" for="b_q">Search</label><input type="search" id="b_q" name="b_q" value="<?php echo dbr_h($b_q); ?>" placeholder="Batch no, remark, user, delivery person or ref"></div>
        <div><button class="btn btn-dark" type="submit" style="width:100%;justify-content:center;"><i class="fa-solid fa-filter"></i> Filter</button></div>
    </form>

    <div class="card" style="margin-top:14px;">
    <?php if ($batches): ?>
        <table>
            <thead><tr>
                <th>Batch</th><th>Saved</th><th>Bank account</th><th>Filters</th>
                <th class="num">Reconciled</th><th class="num">Not reconciled — deposits</th><th class="num">Not reconciled — bank</th>
                <th style="text-align:right;">Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($batches as $bt): ?>
                <tr data-bid="<?php echo (int)$bt['id']; ?>" data-bno="<?php echo dbr_h($bt['batch_no']); ?>" data-rc="<?php echo (int)$bt['rec_count']; ?>">
                    <td><a class="blink" href="?tab=saved&batch=<?php echo (int)$bt['id']; ?>"><?php echo dbr_h($bt['batch_no']); ?></a>
                        <?php if ($bt['remark']): ?><div class="muted"><?php echo dbr_h(mb_strimwidth($bt['remark'], 0, 60, '…')); ?></div><?php endif; ?></td>
                    <td style="white-space:nowrap;"><?php echo dbr_h(dbr_fmt_dt($bt['created_at'])); ?><div class="muted">by <?php echo dbr_h($bt['created_by'] ?: '—'); ?></div></td>
                    <td><?php echo dbr_h(dbr_account_label($accounts, $bt['bank_account_id'])); ?><div class="muted"><?php echo dbr_h(dbr_type_label($bt['bank_type'])); ?></div></td>
                    <td class="muted" style="white-space:nowrap;">
                        <?php if ($bt['dd_from']): ?>Delivery <?php echo dbr_range_label($bt['dd_from'], $bt['dd_to']); ?><br><?php endif; ?>
                        <?php if ($bt['dp_from']): ?>Deposit <?php echo dbr_range_label($bt['dp_from'], $bt['dp_to']); ?><?php endif; ?>
                        <?php if (!$bt['dd_from'] && !$bt['dp_from']): ?>—<?php endif; ?>
                    </td>
                    <td class="num"><b><?php echo (int)$bt['rec_count']; ?></b><div class="muted">Rs <?php echo dbr_money($bt['rec_dep_amount']); ?></div></td>
                    <td class="num"><b><?php echo (int)$bt['unrec_dep_count']; ?></b><div class="muted">Rs <?php echo dbr_money($bt['unrec_dep_amount']); ?></div></td>
                    <td class="num"><b><?php echo (int)$bt['unrec_bank_count']; ?></b><div class="muted">Rs <?php echo dbr_money($bt['unrec_bank_amount']); ?></div></td>
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

<?php else: /* ═══════════ SAVED BATCHES: ONE BATCH ═══════════ */ ?>

    <p class="noprint" style="margin:0 0 12px;"><a class="blink" href="?tab=saved"><i class="fa-solid fa-arrow-left"></i> All batches</a></p>

    <div class="bhead" data-bid="<?php echo (int)$B['id']; ?>" data-bno="<?php echo dbr_h($B['batch_no']); ?>" data-rc="<?php echo (int)$B['rec_count']; ?>">
        <div>
            <h3>Batch <?php echo dbr_h($B['batch_no']); ?></h3>
            <div class="bmeta">
                <span>Saved <b><?php echo dbr_h(dbr_fmt_dt($B['created_at'])); ?></b> by <b><?php echo dbr_h($B['created_by'] ?: '—'); ?></b></span>
                <span>Account <b><?php echo dbr_h(dbr_account_label($accounts, $B['bank_account_id'])); ?></b> · <?php echo dbr_h(dbr_type_label($B['bank_type'])); ?></span>
                <?php if ($B['dd_from']): ?><span>Delivery date <b><?php echo dbr_range_label($B['dd_from'], $B['dd_to']); ?></b></span><?php endif; ?>
                <?php if ($B['dp_from']): ?><span>Deposit date <b><?php echo dbr_range_label($B['dp_from'], $B['dp_to']); ?></b></span><?php endif; ?>
                <span>Tolerance <b><?php echo (int)$B['tolerance_days'] === 0 ? 'same date' : '± ' . (int)$B['tolerance_days'] . ' day(s)'; ?></b></span>
            </div>
            <?php if ($B['remark']): ?><div style="margin-top:6px;"><b>Remark:</b> <?php echo dbr_h($B['remark']); ?></div><?php endif; ?>
        </div>
        <div class="noprint" style="display:flex;gap:8px;">
            <button type="button" class="btn btn-light" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
            <button type="button" class="btn btn-danger js-del-batch"><i class="fa-solid fa-trash"></i> Delete batch</button>
        </div>
    </div>

    <div class="stats">
        <div class="stat g"><div class="lbl">Reconciled</div><div class="val"><?php echo (int)$B['rec_count']; ?></div><div class="cnt">Deposit Rs <?php echo dbr_money($B['rec_dep_amount']); ?> · Bank Rs <?php echo dbr_money($B['rec_bank_amount']); ?></div></div>
        <div class="stat r"><div class="lbl">Not reconciled — deposits</div><div class="val"><?php echo (int)$B['unrec_dep_count']; ?></div><div class="cnt">Rs <?php echo dbr_money($B['unrec_dep_amount']); ?></div></div>
        <div class="stat r"><div class="lbl">Not reconciled — bank credits</div><div class="val"><?php echo (int)$B['unrec_bank_count']; ?></div><div class="cnt">Rs <?php echo dbr_money($B['unrec_bank_amount']); ?></div></div>
    </div>

    <h2><i class="fa-solid fa-circle-check" style="color:#15803d"></i> Reconciled <span class="count"><?php echo count($B_recons); ?></span></h2>
    <div class="card">
    <?php if ($B_recons): ?>
        <table>
            <thead><tr>
                <th>Recon</th><th>Delivery person</th><th>Deposit</th><th>Deposit date</th><th class="num sep">Deposit amount</th>
                <th>Ref code</th><th>Txn date</th><th>Bank transaction</th><th class="num">Bank amount</th><th class="num">Difference</th>
            </tr></thead>
            <tbody>
            <?php foreach ($B_recons as $rc): ?>
                <tr>
                    <td style="white-space:nowrap;">#<?php echo (int)$rc['id']; ?>
                        <div><span class="badge <?php echo $rc['method'] === 'manual' ? 'b-man' : 'b-auto'; ?>"><?php echo $rc['method'] === 'manual' ? 'Manual' : 'Auto'; ?></span></div></td>
                    <td class="name"><?php echo dbr_h($rc['delivery_person']); ?></td>
                    <td style="white-space:nowrap;">
                        <?php foreach ($rc['deps'] as $d): ?>
                            <a class="dep-link" href="cc_cash_deposit_dp.php?search=<?php echo urlencode($d['delivery_person']); ?>" target="_blank" rel="noopener">#<?php echo (int)$d['deposit_id']; ?></a>
                            <div class="muted">Delivery <?php echo dbr_h($d['delivery_dates'] ? implode(', ', array_map('dbr_fmt_date', array_map('trim', explode(',', $d['delivery_dates'])))) : '—'); ?></div>
                        <?php endforeach; ?>
                    </td>
                    <td style="white-space:nowrap;"><?php echo dbr_fmt_date($rc['deposit_date']); ?></td>
                    <td class="num sep"><b><?php echo dbr_money($rc['deposit_amount']); ?></b></td>
                    <td><?php echo $rc['ref_code'] ? '<code>' . dbr_h($rc['ref_code']) . '</code>' : '<span class="muted">—</span>'; ?></td>
                    <td style="white-space:nowrap;"><?php echo dbr_fmt_date($rc['txn_date']); ?></td>
                    <td style="min-width:220px;">
                        <?php foreach ($rc['txns'] as $t): ?>
                            <span class="desc"><?php echo dbr_h($t['description']); ?><?php echo count($rc['txns']) > 1 ? ' — ' . dbr_money($t['credit']) : ''; ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td class="num"><b><?php echo dbr_money($rc['bank_amount']); ?></b></td>
                    <td class="num"><?php echo dbr_diff_html((float)$rc['difference']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?><div class="empty">No reconciliations in this batch.</div><?php endif; ?>
    </div>

    <h2><i class="fa-solid fa-building-columns" style="color:#b91c1c"></i> Not reconciled — deposits <span class="count"><?php echo count($B_unrec['deposit']); ?></span></h2>
    <div class="card">
    <?php if ($B_unrec['deposit']): ?>
        <table>
            <thead><tr><th>Delivery person</th><th>Mobile no (ref code)</th><th>Deposit</th><th>Deposit date</th><th class="num">Deposit amount</th><th>Reason</th><th>Now</th></tr></thead>
            <tbody>
            <?php foreach ($B_unrec['deposit'] as $u): $now = $now_dep[$u['deposit_id'] . '|' . $u['dp_key']] ?? null; ?>
                <tr>
                    <td class="name"><?php echo dbr_h($u['delivery_person']); ?></td>
                    <td><?php echo $u['ref_code'] ? '<code>' . dbr_h($u['ref_code']) . '</code>' : '<span class="muted">Not set</span>'; ?></td>
                    <td style="white-space:nowrap;"><a class="dep-link" href="cc_cash_deposit_dp.php?search=<?php echo urlencode($u['delivery_person']); ?>" target="_blank" rel="noopener">#<?php echo (int)$u['deposit_id']; ?></a>
                        <div class="muted">Delivery <?php echo dbr_h($u['delivery_dates'] ? implode(', ', array_map('dbr_fmt_date', array_map('trim', explode(',', $u['delivery_dates'])))) : '—'); ?></div></td>
                    <td><?php echo dbr_fmt_date($u['ref_date']); ?></td>
                    <td class="num"><b><?php echo dbr_money($u['amount']); ?></b></td>
                    <td><span class="badge b-no"><?php echo dbr_h($u['reason']); ?></span></td>
                    <td><?php echo $now
                        ? '<span class="badge b-ok">Reconciled in ' . ($now['bid'] ? '<a href="?tab=saved&batch=' . (int)$now['bid'] . '">' . dbr_h($now['batch_no']) . '</a>' : 'another batch') . '</span>'
                        : '<span class="badge b-diff">Still open</span>'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?><div class="empty">None — every deposit in this run was reconciled.</div><?php endif; ?>
    </div>

    <h2><i class="fa-solid fa-file-invoice-dollar" style="color:#b91c1c"></i> Not reconciled — bank credits <span class="count"><?php echo count($B_unrec['bank']); ?></span></h2>
    <div class="card">
    <?php if ($B_unrec['bank']): ?>
        <table>
            <thead><tr><th>Txn date</th><th>Delivery person</th><th>Ref (last 10 digits)</th><th>Bank transaction</th><th class="num">Bank amount</th><th>Reason</th><th>Now</th></tr></thead>
            <tbody>
            <?php foreach ($B_unrec['bank'] as $u): $now = $now_bank[(int)$u['bank_txn_id']] ?? null; ?>
                <tr>
                    <td style="white-space:nowrap;"><?php echo dbr_fmt_date($u['ref_date']); ?></td>
                    <td class="name"><?php echo $u['delivery_person'] ? dbr_h($u['delivery_person']) : '<span class="muted">—</span>'; ?></td>
                    <td><?php echo $u['ref_code'] ? '<code>' . dbr_h($u['ref_code']) . '</code>' : '<span class="muted">—</span>'; ?></td>
                    <td style="min-width:240px;"><span class="desc"><?php echo dbr_h($u['description']); ?></span></td>
                    <td class="num"><b><?php echo dbr_money($u['amount']); ?></b></td>
                    <td><span class="badge b-no"><?php echo dbr_h($u['reason']); ?></span></td>
                    <td><?php
                        if (!$now) echo '<span class="badge b-no">Removed from statement</span>';
                        elseif ($now['bid']) echo '<span class="badge b-ok">Reconciled in <a href="?tab=saved&batch=' . (int)$now['bid'] . '">' . dbr_h($now['batch_no']) . '</a></span>';
                        elseif ($now['recon_status']) echo '<span class="badge b-ok">Reconciled elsewhere</span>';
                        else echo '<span class="badge b-diff">Still open</span>';
                    ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?><div class="empty">None — every bank credit in this run was reconciled.</div><?php endif; ?>
    </div>

<?php endif; ?>

    <!-- manual reconcile dialog -->
    <div class="modal" id="mrModal" hidden>
        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="mrTitle">
            <div class="dhead">
                <h3 id="mrTitle">Manual reconcile</h3>
                <button type="button" class="xbtn" id="mrClose" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="dbody">
                <div class="mr-dep" id="mrDep"></div>
                <div class="mr-filter">
                    <div><label class="f" for="mrFrom">Bank txn date from</label><input type="date" id="mrFrom"></div>
                    <div><label class="f" for="mrTo">Bank txn date to</label><input type="date" id="mrTo"></div>
                    <div class="grow"><label class="f" for="mrSearch">Search</label><input type="search" id="mrSearch" placeholder="Description, ref, name or amount"></div>
                    <div><label class="chk"><input type="checkbox" id="mrSameDp"> This delivery person only</label></div>
                    <div><button type="button" class="btn btn-dark" id="mrLoad"><i class="fa-solid fa-magnifying-glass"></i> Load</button></div>
                </div>
                <div class="card mr-list">
                    <table>
                        <thead><tr><th style="width:34px;"></th><th>Txn date</th><th>Bank transaction</th><th>Ref</th><th>Delivery person</th><th class="num">Bank amount</th></tr></thead>
                        <tbody id="mrRows"><tr><td colspan="6" class="empty">Loading…</td></tr></tbody>
                    </table>
                </div>
            </div>
            <div class="dfoot">
                <div class="tally" aria-live="polite">
                    <div><span class="muted">Deposit</span><b id="mrDepAmt">0.00</b></div>
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
    var CSRF = <?php echo json_encode($_SESSION['dbr_csrf']); ?>;
    var TOL  = <?php echo (int)$eff['tol']; ?>;
    var toastEl = document.getElementById('dbrToast'), toastT;
    function toast(msg, type) {
        toastEl.textContent = msg; toastEl.className = 'toast show ' + (type || 'ok');
        clearTimeout(toastT); toastT = setTimeout(function () { toastEl.className = 'toast'; }, 4200);
    }
    function post(data) {
        var fd = new FormData(); fd.append('csrf', CSRF);
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        return fetch(location.pathname, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); });
    }
    function money(n) { return Number(n).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
    function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    function addDays(d, n) {
        var x = new Date(d + 'T00:00:00'); x.setDate(x.getDate() + n);
        return x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0');
    }
    function fmtD(d) { return d ? new Date(d + 'T00:00:00').toLocaleDateString('en-GB', {day: '2-digit', month: 'short', year: 'numeric'}) : '—'; }
    function dayDiff(a, b) { return Math.round((new Date(a + 'T00:00:00') - new Date(b + 'T00:00:00')) / 86400000); }
    function r2(n) { return Math.round(n * 100) / 100; }

    /* ── settings ── */
    var setAcc = document.getElementById('set_account'), setType = document.getElementById('set_type');
    if (setAcc && setType) {
        var typeNames = <?php echo json_encode($DBR_BANK_TYPES); ?>;
        var typeHint = document.getElementById('set_type_hint');
        var fillTypes = function (keep) {
            var o = setAcc.options[setAcc.selectedIndex], types = {};
            try { types = JSON.parse((o && o.dataset.types) || '{}'); } catch (e) {}
            setType.innerHTML = '<option value="">All types</option>';
            Object.keys(types).forEach(function (bt) {
                var op = document.createElement('option');
                op.value = bt; op.textContent = (typeNames[bt] || bt) + ' (latest ' + types[bt] + ')';
                setType.appendChild(op);
            });
            if (keep && types[keep]) setType.value = keep;
            typeHint.textContent = setAcc.value ? Object.keys(types).length + ' statement type(s) uploaded for this account' : '';
        };
        fillTypes(setType.dataset.saved);
        setAcc.addEventListener('change', function () { fillTypes(setType.value); });
        document.getElementById('setSave').addEventListener('click', function () {
            var b = this;
            if (!setAcc.value) { toast('Select the bank account.', 'err'); setAcc.focus(); return; }
            b.disabled = true;
            post({ajax: 'save_settings', account_id: setAcc.value, bank_type: setType.value,
                  tol: document.getElementById('set_tol').value, acc_only: document.getElementById('set_acc_only').checked ? 1 : 0})
            .then(function (res) {
                b.disabled = false;
                toast(res.msg || (res.ok ? 'Saved.' : 'Could not save.'), res.ok ? 'ok' : 'err');
                if (res.ok) setTimeout(function () { location.href = '?tab=run'; }, 700);
            }).catch(function () { b.disabled = false; toast('Could not reach the server. Try again.', 'err'); });
        });
    }

    /* ── clear dates ── */
    var clr = document.getElementById('dbrClearDates');
    if (clr) clr.addEventListener('click', function () {
        ['dd_from', 'dd_to', 'dp_from', 'dp_to'].forEach(function (id) { document.getElementById(id).value = ''; });
        document.getElementById('dd_from').focus();
    });

    /* ── delete batch (list + batch view) ── */
    document.querySelectorAll('.js-del-batch').forEach(function (b) {
        b.addEventListener('click', function () {
            var holder = b.closest('[data-bid]'), id = holder.dataset.bid, no = holder.dataset.bno, rc = holder.dataset.rc;
            if (!confirm('Delete batch ' + no + '?\n\nAll ' + rc + ' reconciliation(s) in it will be deleted. The bank statement transactions and deposits become unreconciled again and their remarks are cleared.')) return;
            b.disabled = true;
            post({ajax: 'delete_batch', id: id}).then(function (res) {
                if (!res.ok) { b.disabled = false; toast(res.msg || 'Could not delete.', 'err'); return; }
                if (holder.tagName === 'TR') { holder.remove(); toast('Batch ' + no + ' deleted (' + res.deleted + ' reconciliation(s)).', 'ok'); }
                else { location.href = '?tab=saved'; }
            }).catch(function () { b.disabled = false; toast('Could not reach the server. Try again.', 'err'); });
        });
    });

    /* ═════════ RECONCILE TAB ═════════ */
    var savebar = document.getElementById('dbrSavebar');
    if (!savebar) return;

    var autoRows = Array.prototype.slice.call(document.querySelectorAll('tr[data-payload]'));
    var staged = {};   /* manual picks waiting for "Save batch": mrow → {data, txns:{id:credit}, info:{id:{desc,date}}} */
    var info = document.getElementById('dbrSelInfo'), saveBtn = document.getElementById('dbrSave');
    var depTotal = +savebar.dataset.depTotal, bankTotal = +savebar.dataset.bankTotal;

    function autoTxnIds(onlyChecked) {
        var u = {};
        autoRows.forEach(function (tr) {
            if (onlyChecked && !tr.querySelector('.js-pick').checked) return;
            JSON.parse(tr.dataset.payload).txns.forEach(function (id) { u[id] = tr; });
        });
        return u;
    }
    function refresh() {
        var a = 0, dif = 0;
        autoRows.forEach(function (tr) { if (tr.querySelector('.js-pick').checked) { a++; if (tr.dataset.sec === 'mismatch') dif++; } });
        var m = Object.keys(staged).length;
        var bankUsed = a;
        Object.keys(staged).forEach(function (k) { Object.keys(staged[k].txns).forEach(function (id) { if (document.querySelector('tr[data-txn="' + id + '"]')) bankUsed++; }); });
        info.innerHTML = '<b>' + (a + m) + '</b> to reconcile (' + a + ' auto' + (dif ? ', ' + dif + ' with difference' : '') + ', ' + m + ' manual) · ' +
                         '<b>' + Math.max(0, depTotal - a - m) + '</b> deposit(s) and <b>' + Math.max(0, bankTotal - bankUsed) + '</b> bank credit(s) saved as not reconciled';
    }
    document.querySelectorAll('.js-pick').forEach(function (c) { c.addEventListener('change', refresh); });
    document.querySelectorAll('.js-all').forEach(function (a) {
        a.addEventListener('change', function () {
            document.querySelectorAll('tr[data-sec="' + a.dataset.sec + '"] .js-pick').forEach(function (c) { c.checked = a.checked; });
            refresh();
        });
    });
    refresh();

    /* ── save batch ── */
    saveBtn.addEventListener('click', function () {
        var items = [], dif = 0, seen = {}, clash = '';
        autoRows.forEach(function (tr) {
            tr.classList.remove('failed'); tr.querySelector('.rowmsg').textContent = '';
            if (!tr.querySelector('.js-pick').checked) return;
            var p = JSON.parse(tr.dataset.payload);
            p.row = tr.dataset.row; p.method = 'auto'; p.allow_diff = tr.dataset.sec === 'mismatch' ? 1 : 0;
            if (p.allow_diff) dif++;
            p.txns.forEach(function (id) { if (seen[id]) clash = 'A bank transaction is used twice.'; seen[id] = 1; });
            items.push(p);
        });
        Object.keys(staged).forEach(function (k) {
            var s = staged[k], ids = Object.keys(s.txns).map(Number), sum = 0;
            ids.forEach(function (id) { sum += s.txns[id]; if (seen[id]) clash = 'A bank transaction picked manually for ' + s.data.dp + ' is also ticked in the auto list.'; seen[id] = 1; });
            var hasDiff = Math.abs(r2(sum - s.data.amount)) >= 0.005;
            if (hasDiff) dif++;
            items.push({row: k, method: 'manual', dp: s.data.dp, deposits: s.data.deposits, txns: ids, allow_diff: hasDiff ? 1 : 0});
        });
        if (clash) { toast(clash + ' Untick one of them.', 'err'); return; }
        var msg = items.length
            ? 'Save batch with ' + items.length + ' reconciliation(s)' + (dif ? ' (' + dif + ' with difference)' : '') + '?\n\nEverything not reconciled is saved in the batch as not reconciled.'
            : 'Nothing is selected to reconcile.\nSave a batch with only the not-reconciled list?';
        if (!confirm(msg)) return;

        var f = JSON.parse(savebar.dataset.filters), old = saveBtn.innerHTML;
        saveBtn.disabled = true; saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving batch…';
        post({ajax: 'save_batch', items: JSON.stringify(items), remark: document.getElementById('dbrRemark').value,
              dd_from: f.dd_from, dd_to: f.dd_to, dp_from: f.dp_from, dp_to: f.dp_to})
        .then(function (res) {
            if (res.ok) {
                staged = {}; window.onbeforeunload = null;
                toast('Batch ' + res.batch_no + ' saved.', 'ok');
                setTimeout(function () { location.href = '?tab=saved&batch=' + res.batch_id; }, 600);
                return;
            }
            saveBtn.disabled = false; saveBtn.innerHTML = old;
            (res.errors || []).forEach(function (e) {
                var tr = document.querySelector('tr[data-row="' + e.row + '"], tr[data-mrow="' + e.row + '"]');
                if (!tr) return;
                tr.classList.add('failed');
                var m = tr.querySelector('.rowmsg'); m.className = 'rowmsg err'; m.textContent = e.msg;
            });
            toast(res.msg || 'Could not save the batch.', 'err');
        })
        .catch(function () { saveBtn.disabled = false; saveBtn.innerHTML = old; toast('Could not reach the server. Try again.', 'err'); });
    });

    window.onbeforeunload = function (e) { if (Object.keys(staged).length) { e.preventDefault(); e.returnValue = ''; } };

    /* ═════════ MANUAL RECONCILE (adds to the batch) ═════════ */
    var mr = document.getElementById('mrModal');
    if (!document.querySelector('.js-manual')) return;
    var mrRows = document.getElementById('mrRows'), mrAdd = document.getElementById('mrAdd');
    var mrFrom = document.getElementById('mrFrom'), mrTo = document.getElementById('mrTo');
    var mrSearch = document.getElementById('mrSearch'), mrSame = document.getElementById('mrSameDp');
    var cur = null, curTr = null, list = [], picked = {}, lastFocus = null;

    function usedElsewhere() {
        var u = {}, auto = autoTxnIds(true);
        Object.keys(auto).forEach(function (id) { u[id] = 'Ticked in the auto list'; });
        Object.keys(staged).forEach(function (k) {
            if (k === cur.row) return;
            Object.keys(staged[k].txns).forEach(function (id) { u[id] = 'Added to batch for ' + staged[k].data.dp; });
        });
        return u;
    }
    function tally() {
        var sum = 0, n = 0;
        Object.keys(picked).forEach(function (id) { sum += picked[id]; n++; });
        sum = r2(sum);
        var diff = r2(sum - cur.amount), d = document.getElementById('mrDiff');
        document.getElementById('mrBankAmt').textContent = money(sum) + (n > 1 ? ' (' + n + ')' : '');
        if (!n) { d.textContent = '—'; d.className = ''; }
        else if (Math.abs(diff) < 0.005) { d.textContent = 'Tallied'; d.className = 'ok'; }
        else { d.textContent = (diff > 0 ? '+' : '') + money(diff); d.className = 'bad'; }
        mrAdd.disabled = n === 0;
    }
    function render() {
        var q = mrSearch.value.trim().toLowerCase(), used = usedElsewhere();
        var rows = list.filter(function (r) {
            if (mrSame.checked && r.key !== cur.key) return false;
            return !q || (r.desc + ' ' + r.ref + ' ' + r.dp + ' ' + r.credit + ' ' + money(r.credit)).toLowerCase().indexOf(q) !== -1;
        });
        rows.sort(function (a, b) {
            var sa = a.key === cur.key ? 0 : 1, sb = b.key === cur.key ? 0 : 1;
            if (sa !== sb) return sa - sb;
            var ea = Math.abs(a.credit - cur.amount) < 0.005 ? 0 : 1, eb = Math.abs(b.credit - cur.amount) < 0.005 ? 0 : 1;
            if (ea !== eb) return ea - eb;
            return Math.abs(dayDiff(a.date, cur.date)) - Math.abs(dayDiff(b.date, cur.date)) || a.id - b.id;
        });
        mrRows.innerHTML = rows.length ? rows.map(function (r) {
            var busy = used[r.id], on = picked[r.id] !== undefined;
            var cls = [on ? 'pick' : '', r.key && r.key === cur.key ? 'same' : '', Math.abs(r.credit - cur.amount) < 0.005 ? 'eq' : '', busy ? 'busy' : ''].join(' ');
            return '<tr class="' + cls + '" data-id="' + r.id + '">' +
                   '<td><input type="checkbox" ' + (on ? 'checked ' : '') + (busy ? 'disabled ' : '') + 'aria-label="Select transaction"></td>' +
                   '<td style="white-space:nowrap;">' + esc(fmtD(r.date)) + '</td>' +
                   '<td><span class="desc">' + esc(r.desc) + '</span>' + (busy ? '<div class="muted">' + esc(busy) + '</div>' : '') + '</td>' +
                   '<td>' + (r.ref ? '<code>' + esc(r.ref) + '</code>' : '<span class="muted">—</span>') + '</td>' +
                   '<td>' + (r.dp ? esc(r.dp) : '<span class="muted">—</span>') + '</td>' +
                   '<td class="num">' + money(r.credit) + '</td></tr>';
        }).join('') : '<tr><td colspan="6" class="empty">No open bank credits for this date range' + (mrSame.checked ? ' and delivery person' : '') + '.</td></tr>';
    }
    function load() {
        if (!mrFrom.value || !mrTo.value) { toast('Enter the bank txn date range.', 'err'); return; }
        mrRows.innerHTML = '<tr><td colspan="6" class="empty">Loading…</td></tr>';
        post({ajax: 'bank_open', from: mrFrom.value, to: mrTo.value}).then(function (res) {
            if (!res.ok) { mrRows.innerHTML = '<tr><td colspan="6" class="empty">' + esc(res.msg || 'Could not load.') + '</td></tr>'; return; }
            list = res.rows; render(); tally();
        }).catch(function () { mrRows.innerHTML = '<tr><td colspan="6" class="empty">Could not reach the server. Try again.</td></tr>'; });
    }
    function open(tr) {
        lastFocus = document.activeElement;
        curTr = tr; cur = JSON.parse(tr.dataset.manual); list = [];
        picked = {};
        if (staged[cur.row]) Object.keys(staged[cur.row].txns).forEach(function (id) { picked[id] = staged[cur.row].txns[id]; });
        var span = Math.max(TOL, 3);
        mrFrom.value = addDays(cur.date, -span); mrTo.value = addDays(cur.date, span);
        mrSearch.value = ''; mrSame.checked = false;
        document.getElementById('mrTitle').textContent = 'Manual reconcile — ' + cur.dp;
        document.getElementById('mrDep').innerHTML =
            '<div><span class="muted">Delivery person</span><b>' + esc(cur.dp) + '</b></div>' +
            '<div><span class="muted">Mobile no</span><b>' + (cur.mobile ? esc(cur.mobile) : '—') + '</b></div>' +
            '<div><span class="muted">Deposit</span><b>#' + cur.deposits.join(', #') + '</b></div>' +
            '<div><span class="muted">Deposit date</span><b>' + esc(fmtD(cur.dep_date)) + '</b></div>' +
            '<div><span class="muted">Deposit amount</span><b>' + money(cur.amount) + '</b></div>';
        document.getElementById('mrDepAmt').textContent = money(cur.amount);
        mr.hidden = false; tally(); load(); mrFrom.focus();
    }
    function close() { mr.hidden = true; if (lastFocus && document.contains(lastFocus)) lastFocus.focus(); }

    /* mark the deposit row + the bank rows on the page as "added to batch" */
    function paintStaged() {
        document.querySelectorAll('tr[data-txn]').forEach(function (b) { b.classList.remove('used'); b.querySelector('.rowmsg').textContent = ''; });
        document.querySelectorAll('tr[data-mrow]').forEach(function (tr) {
            var k = tr.dataset.mrow, s = staged[k], msg = tr.querySelector('.rowmsg'), btn = tr.querySelector('.js-manual');
            tr.classList.toggle('staged', !!s);
            tr.querySelector('.js-unstage').hidden = !s;
            if (!s) { if (!tr.classList.contains('failed')) msg.textContent = ''; btn.innerHTML = '<i class="fa-solid fa-hand-pointer"></i> Manual reconcile'; return; }
            var ids = Object.keys(s.txns), sum = 0; ids.forEach(function (id) { sum += s.txns[id]; });
            var diff = r2(sum - s.data.amount);
            msg.className = 'rowmsg info';
            msg.textContent = 'Added to batch: ' + ids.length + ' bank txn(s), ' + money(sum) + (Math.abs(diff) < 0.005 ? ' — tallied' : ' — difference ' + money(diff));
            btn.innerHTML = '<i class="fa-solid fa-pen"></i> Change';
            ids.forEach(function (id) {
                var b = document.querySelector('tr[data-txn="' + id + '"]');
                if (b) { b.classList.add('used'); b.querySelector('.rowmsg').textContent = 'Added to batch for ' + s.data.dp; }
            });
        });
        refresh();
    }

    document.querySelectorAll('.js-manual').forEach(function (b) { b.addEventListener('click', function () { open(b.closest('tr')); }); });
    document.querySelectorAll('.js-unstage').forEach(function (b) {
        b.addEventListener('click', function () { delete staged[b.closest('tr').dataset.mrow]; paintStaged(); });
    });
    document.getElementById('mrLoad').addEventListener('click', load);
    [mrFrom, mrTo].forEach(function (el) { el.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); load(); } }); });
    mrSearch.addEventListener('input', render);
    mrSame.addEventListener('change', render);
    document.getElementById('mrClose').addEventListener('click', close);
    document.getElementById('mrCancel').addEventListener('click', close);
    mr.addEventListener('click', function (e) { if (e.target === mr) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !mr.hidden) close(); });

    mrRows.addEventListener('click', function (e) {
        var tr = e.target.closest('tr[data-id]');
        if (!tr) return;
        var cb = tr.querySelector('input');
        if (cb.disabled) return;
        if (e.target !== cb) cb.checked = !cb.checked;
        var id = tr.dataset.id, r = list.filter(function (x) { return String(x.id) === id; })[0];
        if (cb.checked) picked[id] = r.credit; else delete picked[id];
        tr.classList.toggle('pick', cb.checked);
        tally();
    });

    mrAdd.addEventListener('click', function () {
        var ids = Object.keys(picked);
        if (!ids.length) return;
        staged[cur.row] = {data: cur, txns: Object.assign({}, picked)};
        curTr.classList.remove('failed');
        paintStaged();
        close();
        toast('Added to batch. Press "Save batch" to save.', 'ok');
    });
})();
</script>

<?php include 'footer.php'; ?>
