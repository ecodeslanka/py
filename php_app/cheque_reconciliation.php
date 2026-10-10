<?php
/**
 * cheque_reconciliation.php
 * ──────────────────────────────────────────────────────────────────
 *  Bank Statement Cheque Reconciliation Page
 *  • Statement Date field, upload history saved, not-found → separate table
 *  • Leading-zero-safe matching, NDB auto-detect, generic column mapping
 *  • crn_date_of_return set on cheques table when status → returned
 *  • History page: cheque_recon_history.php
 *  • PROTECTED: already-cleared/returned cheques shown with MANUAL UPDATE option
 *  • Amount mismatch → highlighted separately in amber/warning style
 *  • DUPLICATE CHEQUE NOs → nearest amount match selected automatically
 *  • NDB Pattern 4: Cheque Transfer/ANY_REF/CHQ NO - XXXXX → Cleared
 *  • FROM BANK STATEMENT mode: reconcile against statements already
 *    uploaded in Bank Statements (bank account + optional type set in
 *    Settings). Reconciled statement lines get status, category
 *    "Cheque Reconcile" and a remark. Deleting the batch clears them.
 * ──────────────────────────────────────────────────────────────────
 */
if (session_status() === PHP_SESSION_NONE) session_start();
function get_current_user_label() {
    return $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['name'] ??
           $_SESSION['full_name'] ?? $_SESSION['email'] ??
           (isset($_SESSION['user_id']) ? 'User #' . $_SESSION['user_id'] : 'system');
}
include_once 'config.php';

/* ── ensure_columns: auto-adds missing columns incl. crn_date_of_return ── */
function ensure_columns($conn) {
    $checks = [
        "cheques" => [
            "bank_ref"   => "ALTER TABLE cheques ADD COLUMN bank_ref VARCHAR(100) DEFAULT NULL",
            "return_reason" => "ALTER TABLE cheques ADD COLUMN return_reason TEXT DEFAULT NULL",
            "crn_date_of_return"   => "ALTER TABLE cheques ADD COLUMN crn_date_of_return DATE DEFAULT NULL COMMENT 'Cheque Return Notice Date – set when status is marked returned'"
        ]
    ];
    foreach ($checks as $table => $cols) {
        foreach ($cols as $col => $ddl) {
            $r = mysqli_query($conn,
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA=DATABASE()
                    AND TABLE_NAME='$table'
                    AND COLUMN_NAME='$col' LIMIT 1");
            if (!($r && mysqli_num_rows($r) > 0)) {
                @mysqli_query($conn, $ddl);
            }
        }
    }
}

function ensure_log_table($conn) {
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_logs(id INT AUTO_INCREMENT PRIMARY KEY,cheque_id INT NOT NULL,action VARCHAR(100),old_value TEXT,new_value TEXT,note TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,created_by VARCHAR(100) DEFAULT 'system',INDEX idx_cid(cheque_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function ensure_return_charge_table($conn) {
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_return_charges(id INT AUTO_INCREMENT PRIMARY KEY,cheque_id INT NOT NULL,cheque_no VARCHAR(100) NOT NULL,t_code VARCHAR(100) DEFAULT NULL,cheque_amount DECIMAL(15,2) DEFAULT 0,return_charge DECIMAL(15,2) NOT NULL DEFAULT 250.00,return_reason TEXT DEFAULT NULL,charged_at DATETIME DEFAULT CURRENT_TIMESTAMP,charged_by VARCHAR(100) DEFAULT 'system',INDEX idx_crc_cid(cheque_id),INDEX idx_crc_tcode(t_code),INDEX idx_crc_chqno(cheque_no)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function ensure_recon_tables($conn) {
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_recon_uploads(id INT AUTO_INCREMENT PRIMARY KEY,file_name VARCHAR(255) NOT NULL,statement_date DATE DEFAULT NULL,total_rows INT DEFAULT 0,matched INT DEFAULT 0,cleared INT DEFAULT 0,returned INT DEFAULT 0,not_found INT DEFAULT 0,already_done INT DEFAULT 0,skipped INT DEFAULT 0,status VARCHAR(20) DEFAULT 'applied',reversed_at DATETIME DEFAULT NULL,reversed_by VARCHAR(100) DEFAULT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,created_by VARCHAR(100) DEFAULT 'system',INDEX idx_stmt_date(statement_date),INDEX idx_status(status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_recon_upload_items(id INT AUTO_INCREMENT PRIMARY KEY,upload_id INT NOT NULL,cheque_id INT DEFAULT NULL,stmt_cheque_no VARCHAR(100),db_cheque_no VARCHAR(100) DEFAULT NULL,stmt_amount DECIMAL(15,2) DEFAULT 0,db_amount DECIMAL(15,2) DEFAULT NULL,type VARCHAR(20) DEFAULT 'cleared',matched TINYINT(1) DEFAULT 0,already_updated TINYINT(1) DEFAULT 0,applied TINYINT(1) DEFAULT 0,old_status VARCHAR(50) DEFAULT NULL,new_status VARCHAR(50) DEFAULT NULL,bank_ref VARCHAR(100) DEFAULT NULL,tx_date VARCHAR(50) DEFAULT NULL,return_reason TEXT DEFAULT NULL,customer_name VARCHAR(255) DEFAULT NULL,t_code VARCHAR(100) DEFAULT NULL,amt_match TINYINT(1) DEFAULT 0,description TEXT DEFAULT NULL,deposit_date DATE DEFAULT NULL,account_name VARCHAR(255) DEFAULT NULL,INDEX idx_upload(upload_id),INDEX idx_cheque(cheque_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_recon_not_found(id INT AUTO_INCREMENT PRIMARY KEY,upload_id INT NOT NULL,stmt_cheque_no VARCHAR(100) NOT NULL,stmt_amount DECIMAL(15,2) DEFAULT 0,type VARCHAR(20) DEFAULT 'cleared',bank_ref VARCHAR(100) DEFAULT NULL,tx_date VARCHAR(50) DEFAULT NULL,description TEXT DEFAULT NULL,statement_date DATE DEFAULT NULL,file_name VARCHAR(255) DEFAULT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,created_by VARCHAR(100) DEFAULT 'system',INDEX idx_upload(upload_id),INDEX idx_chqno(stmt_cheque_no),INDEX idx_stmt_date(statement_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ═════════════════════════════════════════════════════════════════
   FROM BANK STATEMENT MODE
   ═════════════════════════════════════════════════════════════════ */
define('CRS_SOURCE',   'cheque_recon');
define('CRS_CATEGORY', 'Cheque Reconcile');
$CRS_BANK_TYPES = ['BOC' => 'BOC', 'NDB' => 'NDB', 'SAMPATH' => 'Sampath'];

function crs_q($conn, $sql) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } }
function crs_has_col($conn, $t, $c) {
    $r = crs_q($conn, "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
                        AND TABLE_NAME='" . mysqli_real_escape_string($conn, $t) . "'
                        AND COLUMN_NAME='" . mysqli_real_escape_string($conn, $c) . "' LIMIT 1");
    return $r && mysqli_num_rows($r) > 0;
}
function crs_valid_date($d) { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) > 0; }
function crs_range($from, $to) {
    $from = crs_valid_date((string)$from) ? $from : '';
    $to   = crs_valid_date((string)$to)   ? $to   : '';
    if ($from === '' && $to === '') return ['', ''];
    if ($from === '') $from = $to;
    if ($to === '')   $to   = $from;
    return $to < $from ? [$to, $from] : [$from, $to];
}
function crs_type_label($bt) {
    global $CRS_BANK_TYPES;
    $bt = strtoupper((string)$bt);
    return $bt === '' ? 'All types' : ($CRS_BANK_TYPES[$bt] ?? $bt);
}

/* extra columns / tables for statement mode — existing data is not changed */
function ensure_stmt_recon($conn) {
    static $done = false;
    if ($done) return;
    $done = true;
    ensure_recon_tables($conn);
    ensure_return_charge_table($conn);
    $cols = [
        ['cheque_recon_uploads',        'source',            "VARCHAR(20) NOT NULL DEFAULT 'file'"],
        ['cheque_recon_uploads',        'bank_account_id',   "INT NULL"],
        ['cheque_recon_uploads',        'bank_type',         "VARCHAR(10) NULL"],
        ['cheque_recon_uploads',        'date_from',         "DATE NULL"],
        ['cheque_recon_uploads',        'date_to',           "DATE NULL"],
        ['cheque_recon_uploads',        'stmt_reconciled',   "INT NOT NULL DEFAULT 0"],
        ['cheque_recon_upload_items',   'bank_txn_id',       "INT NULL"],
        ['cheque_recon_upload_items',   'stmt_recon_status', "VARCHAR(20) NULL"],
        ['cheque_return_charges',       'recon_upload_id',   "INT NULL"],
        ['bank_statement_transactions', 'recon_status',      "VARCHAR(20) NULL DEFAULT NULL"],
        ['bank_statement_transactions', 'recon_source',      "VARCHAR(30) NULL DEFAULT NULL"],
        ['bank_statement_transactions', 'recon_category',    "VARCHAR(50) NULL DEFAULT NULL"],
        ['bank_statement_transactions', 'recon_ref_id',      "INT NULL DEFAULT NULL"],
        ['bank_statement_transactions', 'recon_remark',      "TEXT NULL"],
        ['bank_statement_transactions', 'recon_by',          "VARCHAR(100) NULL DEFAULT NULL"],
        ['bank_statement_transactions', 'recon_at',          "DATETIME NULL DEFAULT NULL"],
    ];
    foreach ($cols as $c) {
        if (!crs_has_col($conn, $c[0], $c[1])) crs_q($conn, "ALTER TABLE `{$c[0]}` ADD COLUMN `{$c[1]}` {$c[2]}");
    }
    crs_q($conn, "CREATE TABLE IF NOT EXISTS cheque_recon_settings (
        id INT NOT NULL PRIMARY KEY, bank_account_id INT NULL, bank_type VARCHAR(10) NULL,
        updated_by VARCHAR(100) NULL, updated_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ALL company bank accounts (with or without bank statements), with their statement types */
function crs_accounts($conn) {
    $acc = [];
    /* every company bank account, so it can be selected in Settings even before a statement is uploaded */
    $r = crs_q($conn, "SELECT cba.id, cba.account_no, COALESCE(cba.active, 1) AS active FROM company_bank_accounts cba");
    if ($r) while ($row = mysqli_fetch_assoc($r)) {
        $id = (int)$row['id'];
        if ($id <= 0) continue;
        $acc[$id] = ['label' => '', 'acno' => (string)$row['account_no'], 'types' => [], 'last' => '', 'uploads' => 0, 'active' => (int)$row['active']];
    }
    $r = crs_q($conn, "SELECT account_id, UPPER(bank_type) AS bt, MAX(statement_date) AS last_stmt,
                              MAX(account_number) AS acno, COUNT(*) AS uploads
                         FROM bank_statement_uploads GROUP BY account_id, UPPER(bank_type)");
    if ($r) while ($row = mysqli_fetch_assoc($r)) {
        $id = (int)$row['account_id'];
        if ($id <= 0) continue;
        if (!isset($acc[$id])) $acc[$id] = ['label' => '', 'acno' => (string)$row['acno'], 'types' => [], 'last' => '', 'uploads' => 0, 'active' => 1];
        $acc[$id]['types'][$row['bt']] = $row['last_stmt'];
        $acc[$id]['uploads'] += (int)$row['uploads'];
        if ($row['last_stmt'] > $acc[$id]['last']) $acc[$id]['last'] = $row['last_stmt'];
    }
    if ($acc) {
        $r = crs_q($conn, "SELECT cba.id,
                                  CONCAT(COALESCE(NULLIF(b.bank_name,''), cba.bank_code, ''), ' / ',
                                         COALESCE(NULLIF(bb.branch_name,''), cba.branch_code, ''), ' (', cba.account_no, ')') AS label
                             FROM company_bank_accounts cba
                        LEFT JOIN banks b ON b.bank_code = cba.bank_code
                        LEFT JOIN bank_branches bb ON bb.bank_code = cba.bank_code AND bb.branch_code = cba.branch_code
                            WHERE cba.id IN (" . implode(',', array_map('intval', array_keys($acc))) . ")");
        if ($r) while ($row = mysqli_fetch_assoc($r)) $acc[(int)$row['id']]['label'] = $row['label'];
    }
    foreach ($acc as $id => &$a) {
        if ($a['label'] === '') $a['label'] = 'Account #' . $id . ($a['acno'] !== '' ? ' (' . $a['acno'] . ')' : '');
        if (empty($a['active'])) $a['label'] .= ' [inactive]';
        ksort($a['types']);
    }
    unset($a);
    uasort($acc, function ($x, $y) { return strcasecmp($x['label'], $y['label']); });
    return $acc;
}

/* saved settings, checked against the company bank accounts */
function crs_settings($conn, $accounts) {
    $s = ['account' => 0, 'type' => '', 'saved_account' => 0, 'saved_type' => '', 'updated_by' => '', 'updated_at' => ''];
    $r = crs_q($conn, "SELECT * FROM cheque_recon_settings WHERE id = 1 LIMIT 1");
    if ($r && ($row = mysqli_fetch_assoc($r))) {
        $s['saved_account'] = (int)$row['bank_account_id'];
        $s['saved_type']    = strtoupper((string)$row['bank_type']);
        $s['updated_by']    = (string)$row['updated_by'];
        $s['updated_at']    = (string)$row['updated_at'];
    }
    if (isset($accounts[$s['saved_account']])) {
        $s['account'] = $s['saved_account'];
        global $CRS_BANK_TYPES;
        if ($s['saved_type'] !== '' && (isset($CRS_BANK_TYPES[$s['saved_type']]) || isset($accounts[$s['account']]['types'][$s['saved_type']]))) $s['type'] = $s['saved_type'];
    }
    return $s;
}

/**
 * Cheque number + type from one bank statement line. Returns null when the
 * line is not a customer-cheque line.
 *   NDB : Outward Cheque Deposit/CHQ NO - X      → cleared
 *         Outward Clg Chq Return/CHQ NO - X      → returned
 *         Cheque Transfer/…/CHQ NO - X           → cleared
 *   BOC : cheque no column on a credit           → cleared
 *         cheque no column on a debit marked RETURN / RTN / UNPAID → returned
 *   Any : "CHQ NO 12345", "CHEQUE 12345" in the description / reference
 * A debit with a cheque number but no return word is the company's own
 * payment, so it is skipped.
 */
function crs_parse_txn($t) {
    $desc = trim((string)($t['description'] ?? ''));
    $ref  = trim((string)($t['reference'] ?? ''));
    $cr   = (float)($t['credit'] ?? 0);
    $db   = abs((float)($t['debit'] ?? 0));
    $isRet = (bool)preg_match('/\b(RETURN(ED)?|RTN|RETD|DISHONOU?RED|UNPAID|R\/D)\b/i', $desc . ' ' . $ref);

    if (preg_match('/Outward\s+Cl[gq]\s+Chq\s+Return\s*\/\s*CHQ\s+NO\s*[-\x{2013}]\s*(\d+)/iu', $desc, $m)) return [$m[1], 'returned', $db ?: $cr];
    if (preg_match('/Outward\s+Cheque\s+Deposit\s*\/\s*CHQ\s+NO\s*[-\x{2013}]\s*(\d+)/iu', $desc, $m))       return [$m[1], 'cleared', $cr ?: $db];
    if (preg_match('/Cheque\s+Transfer\s*\/\s*[^\/]+\/\s*CHQ\s+NO\s*[-\x{2013}]\s*(\d+)/iu', $desc, $m))    return [$m[1], 'cleared', $cr ?: $db];

    $no = '';
    $cn = preg_replace('/\D/', '', (string)($t['cheque_no'] ?? ''));
    if ($cn !== '' && (int)$cn > 0) $no = $cn;
    elseif (preg_match('/\b(?:CHQ|CHEQUE|CHK)\.?\s*(?:NO|NUMBER|#)?\.?\s*[-:]?\s*(\d{4,})/i', $desc . ' ' . $ref, $m)) $no = $m[1];
    if ($no === '') return null;

    if ($cr > 0 && !$isRet) return [$no, 'cleared', $cr];
    if ($isRet)             return [$no, 'returned', $db ?: $cr];
    return null;
}

/* one preview row — same fields as the file preview, plus the bank line id */
function crs_preview_row($conn, $p) {
    $raw_no = trim($p['cheque_no']);
    $amount = floatval($p['amount']);
    $type   = $p['type'];
    $db     = find_cheque_by_no($conn, $raw_no, $amount);
    $target = ($type === 'returned') ? 'returned' : 'cleared';
    return [
        'stmt_cheque_no'  => $raw_no,
        'db_cheque_no'    => $db['cheque_no'] ?? null,
        'bank_amount'     => $amount,
        'bank_ref'        => $p['bank_ref'],
        'tx_date'         => $p['tx_date'],
        'description'     => $p['description'],
        'type'            => $type,
        'matched'         => $db !== null,
        'already_updated' => $db && ($db['status'] === $target || $db['status'] === 'cleared' || $db['status'] === 'returned'),
        'db_id'           => $db['id'] ?? null,
        'db_status'       => $db['status'] ?? null,
        'db_amount'       => $db['total_amount'] ?? null,
        'db_t_code'       => $db['t_code'] ?? null,
        'customer_name'   => $db['customer_name'] ?? null,
        'deposit_date'    => $db['deposit_date'] ?? null,
        'account_name'    => $db['account_name'] ?? null,
        'amt_match'       => $db ? abs(floatval($db['total_amount']) - $amount) < 0.01 : false,
        'bank_txn_id'     => (int)$p['bank_txn_id'],
    ];
}

/**
 * Delete a statement batch: clear the bank statement reconcile details,
 * optionally put the cheques back to their old status, remove the batch.
 */
function crs_delete_batch($conn, $uid, $revert, $user) {
    $uid = (int)$uid;
    $r = crs_q($conn, "SELECT * FROM cheque_recon_uploads WHERE id = $uid LIMIT 1");
    $up = $r ? mysqli_fetch_assoc($r) : null;
    if (!$up) return ['success' => false, 'error' => 'Batch not found.'];
    if (($up['source'] ?? 'file') !== 'statement') return ['success' => false, 'error' => 'Only bank statement batches can be deleted here. Use Reverse for file uploads.'];

    ensure_log_table($conn);
    $cu = mysqli_real_escape_string($conn, $user);
    $reverted = 0; $kept = 0; $cleared_lines = 0;
    mysqli_begin_transaction($conn);
    try {
        mysqli_query($conn, "UPDATE bank_statement_transactions
                                SET recon_status = NULL, recon_source = NULL, recon_category = NULL, recon_ref_id = NULL,
                                    recon_remark = NULL, recon_by = NULL, recon_at = NULL
                              WHERE recon_source = '" . CRS_SOURCE . "' AND recon_ref_id = $uid");
        $cleared_lines = mysqli_affected_rows($conn);

        if ($revert && ($up['status'] ?? '') !== 'reversed') {
            $ir = mysqli_query($conn, "SELECT cheque_id, old_status, new_status FROM cheque_recon_upload_items WHERE upload_id = $uid AND applied = 1");
            while ($ir && ($it = mysqli_fetch_assoc($ir))) {
                $cid = (int)$it['cheque_id'];
                if (!$cid || !$it['old_status'] || !$it['new_status']) { $kept++; continue; }
                $old_e = mysqli_real_escape_string($conn, $it['old_status']);
                $new_e = mysqli_real_escape_string($conn, $it['new_status']);
                $crn   = $it['new_status'] === 'returned' ? ', crn_date_of_return = NULL' : '';
                mysqli_query($conn, "UPDATE cheques SET status = '$old_e'$crn WHERE id = $cid AND status = '$new_e'");
                if (mysqli_affected_rows($conn) > 0) {
                    $note = mysqli_real_escape_string($conn, "Bank statement cheque recon batch #$uid deleted - status reverted");
                    mysqli_query($conn, "INSERT INTO cheque_logs(cheque_id,action,old_value,new_value,note,created_by) VALUES($cid,'status_change','$new_e','$old_e','$note','$cu')");
                    $reverted++;
                } else {
                    $kept++;   /* status changed again since this batch — left as it is */
                }
            }
            mysqli_query($conn, "DELETE FROM cheque_return_charges WHERE recon_upload_id = $uid");
        } else {
            mysqli_query($conn, "UPDATE cheque_return_charges SET recon_upload_id = NULL WHERE recon_upload_id = $uid");
        }

        mysqli_query($conn, "DELETE FROM cheque_recon_not_found     WHERE upload_id = $uid");
        mysqli_query($conn, "DELETE FROM cheque_recon_upload_items  WHERE upload_id = $uid");
        mysqli_query($conn, "DELETE FROM cheque_recon_uploads       WHERE id = $uid");
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        return ['success' => false, 'error' => 'Could not delete the batch. Try again.'];
    }
    return ['success' => true, 'reverted' => $reverted, 'kept' => $kept, 'lines' => $cleared_lines];
}

/**
 * find_cheque_by_no
 * ─────────────────────────────────────────────────────────────────
 * Fetches ALL cheques matching the numeric value of $raw_no (leading-
 * zero-safe).  When more than one row is found (duplicate cheque
 * numbers), the one whose total_amount is closest to $stmt_amount is
 * returned.  If $stmt_amount is 0 / null the first row is used as a
 * fallback (original behaviour).
 * ─────────────────────────────────────────────────────────────────
 */
function find_cheque_by_no($conn, $raw_no, $stmt_amount = 0) {
    $raw_no = trim($raw_no);
    if ($raw_no === '') return null;

    $esc        = (int) intval($raw_no);
    $stmt_amount = floatval($stmt_amount);

    $sql = "SELECT ch.id, ch.cheque_no, ch.total_amount, ch.status, ch.t_code, ch.bank_ref,
                   COALESCE(NULLIF(c.shop_name,''), ch.t_code) AS customer_name,
                   ch.deposit_date,
                   COALESCE(cba.account_name,'') AS account_name,
                   COALESCE(cba.account_no,'')   AS account_no
              FROM cheques ch
              LEFT JOIN customers c ON c.t_code = ch.t_code
              LEFT JOIN company_bank_accounts cba ON cba.id = ch.deposited_account_id
             WHERE CAST(ch.cheque_no AS UNSIGNED) = $esc
             ORDER BY ABS(ch.total_amount - $stmt_amount) ASC";   /* nearest amount first */

    $r = mysqli_query($conn, $sql);
    if (!$r) return null;

    $rows = [];
    while ($row = mysqli_fetch_assoc($r)) {
        $rows[] = $row;
    }
    if (empty($rows)) return null;

    /* ── Single result: return as-is (original behaviour) ── */
    if (count($rows) === 1) return $rows[0];

    /* ── Multiple duplicates: pick nearest amount ── */
    /* If stmt_amount is meaningful (> 0) the ORDER BY already sorted them;
       just return the first.  If stmt_amount is 0 we still return the first
       (lowest-difference row), which is the best we can do. */
    return $rows[0];
}

/* ── AJAX: stmt_save_settings ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'stmt_save_settings') {
    ob_start(); header('Content-Type: application/json'); ensure_stmt_recon($conn);
    $accounts = crs_accounts($conn);
    $acc = intval($_POST['account_id'] ?? 0);
    $bt  = strtoupper(trim($_POST['bank_type'] ?? ''));
    if (!isset($accounts[$acc])) { ob_end_clean(); echo json_encode(['success'=>false,'error'=>'Select a bank account.']); exit; }
    if ($bt !== '' && !isset($CRS_BANK_TYPES[$bt]) && !isset($accounts[$acc]['types'][$bt])) { ob_end_clean(); echo json_encode(['success'=>false,'error'=>'Select a valid statement type.']); exit; }
    $btDb = $bt !== '' ? $bt : null; $user = get_current_user_label(); $now = date('Y-m-d H:i:s');
    $st = mysqli_prepare($conn, "INSERT INTO cheque_recon_settings (id, bank_account_id, bank_type, updated_by, updated_at) VALUES (1, ?, ?, ?, ?)
                                 ON DUPLICATE KEY UPDATE bank_account_id = VALUES(bank_account_id), bank_type = VALUES(bank_type),
                                     updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)");
    mysqli_stmt_bind_param($st, 'isss', $acc, $btDb, $user, $now);
    $ok = mysqli_stmt_execute($st);
    ob_end_clean(); echo json_encode($ok ? ['success'=>true] : ['success'=>false,'error'=>'Could not save settings.']); exit;
}

/* ── AJAX: stmt_load — read cheque lines from the uploaded bank statements and match them ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'stmt_load') {
    ob_start(); header('Content-Type: application/json'); ensure_columns($conn); ensure_stmt_recon($conn);
    $accounts = crs_accounts($conn);
    $cfg = crs_settings($conn, $accounts);
    if (!$cfg['account']) { ob_end_clean(); echo json_encode(['success'=>false,'error'=>'Set the bank account in Settings first.']); exit; }
    list($from, $to) = crs_range($_POST['date_from'] ?? '', $_POST['date_to'] ?? '');
    if ($from === '') { ob_end_clean(); echo json_encode(['success'=>false,'error'=>'Enter the transaction date range.']); exit; }
    if ((strtotime($to) - strtotime($from)) / 86400 > 92) { ob_end_clean(); echo json_encode(['success'=>false,'error'=>'Pick a date range of 3 months or less.']); exit; }

    $acc = $cfg['account']; $bt = $cfg['type'];
    $st = mysqli_prepare($conn, "SELECT t.* FROM bank_statement_transactions t
                                   JOIN bank_statement_uploads u ON u.id = t.upload_id
                                  WHERE u.account_id = ? " . ($bt !== '' ? "AND UPPER(u.bank_type) = ?" : "AND ? = ''") . "
                                    AND t.transaction_date BETWEEN ? AND ?
                                  ORDER BY t.transaction_date, t.id");
    mysqli_stmt_bind_param($st, 'isss', $acc, $bt, $from, $to);
    mysqli_stmt_execute($st);
    $rs = mysqli_stmt_get_result($st);
    $rows = []; $seen = [];
    $stats = ['lines' => 0, 'already' => 0, 'other' => 0, 'cheque_lines' => 0, 'already_other' => 0];
    while ($rs && ($t = mysqli_fetch_assoc($rs))) {
        /* the same line uploaded in two overlapping statements counts once */
        $dk = $t['transaction_date'] . '|' . trim(preg_replace('/\s+/', ' ', (string)$t['description'])) . '|' . $t['debit'] . '|' . $t['credit'] . '|' . $t['balance'];
        if (isset($seen[$dk])) continue;
        $seen[$dk] = true;
        $stats['lines']++;
        $parsed = crs_parse_txn($t);
        if (!$parsed) { $stats['other']++; continue; }
        if (!empty($t['recon_status'])) {
            if (($t['recon_source'] ?? '') === CRS_SOURCE) $stats['already']++; else $stats['already_other']++;
            continue;
        }
        $stats['cheque_lines']++;
        $rows[] = crs_preview_row($conn, [
            'cheque_no'   => $parsed[0],
            'type'        => $parsed[1],
            'amount'      => $parsed[2],
            'bank_ref'    => trim((string)($t['reference'] ?? '')) ?: trim((string)($t['serial_no'] ?? '')),
            'tx_date'     => $t['transaction_date'],
            'description' => trim((string)$t['description']),
            'bank_txn_id' => $t['id'],
        ]);
    }
    mysqli_stmt_close($st);
    $label = 'Bank statement · ' . $accounts[$acc]['label'] . ' · ' . crs_type_label($bt) . ' · '
           . date('d M Y', strtotime($from)) . ($from !== $to ? ' – ' . date('d M Y', strtotime($to)) : '');
    ob_end_clean();
    echo json_encode(['success'=>true, 'rows'=>$rows, 'stats'=>$stats, 'label'=>$label, 'from'=>$from, 'to'=>$to],
                     JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/* ── AJAX: stmt_batch_delete — delete a bank statement batch ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'stmt_batch_delete') {
    ob_start(); header('Content-Type: application/json'); ensure_stmt_recon($conn);
    $res = crs_delete_batch($conn, intval($_POST['upload_id'] ?? 0), !empty($_POST['revert']), get_current_user_label());
    ob_end_clean(); echo json_encode($res); exit;
}

/* ── AJAX: recon_preview ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'recon_preview') {
    ob_start(); header('Content-Type: application/json'); ensure_columns($conn);
    $parsed = json_decode($_POST['parsed_rows'] ?? '[]', true);
    if (!is_array($parsed)) { ob_end_clean(); echo json_encode(['success'=>false,'error'=>'Bad data']); exit; }
    $results = [];
    foreach ($parsed as $p) {
        $raw_no  = trim($p['cheque_no'] ?? '');
        $amount  = floatval($p['amount'] ?? 0);
        $type    = $p['type'] ?? 'cleared';
        $bank_ref = trim($p['bank_ref'] ?? '');
        $tx_date  = trim($p['tx_date'] ?? '');
        $desc     = trim($p['description'] ?? '');
        if ($raw_no === '') continue;

        /* ── Pass $amount so duplicate cheques resolve to nearest match ── */
        $db = find_cheque_by_no($conn, $raw_no, $amount);

        $target_status  = ($type === 'returned') ? 'returned' : 'cleared';
        /* already_updated: status already matches target OR cheque is already cleared/returned (protected) */
        $already_updated = $db && ($db['status'] === $target_status || $db['status'] === 'cleared' || $db['status'] === 'returned');
        $amt_match = $db ? abs(floatval($db['total_amount']) - $amount) < 0.01 : false;

        /* ── Flag when this was a duplicate-cheque-no resolution ── */
        $results[] = [
            'stmt_cheque_no'  => $raw_no,
            'db_cheque_no'    => $db['cheque_no'] ?? null,
            'bank_amount'     => $amount,
            'bank_ref'        => $bank_ref,
            'tx_date'         => $tx_date,
            'description'     => $desc,
            'type'            => $type,
            'matched'         => $db !== null,
            'already_updated' => $already_updated,
            'db_id'           => $db['id'] ?? null,
            'db_status'       => $db['status'] ?? null,
            'db_amount'       => $db['total_amount'] ?? null,
            'db_t_code'       => $db['t_code'] ?? null,
            'customer_name'   => $db['customer_name'] ?? null,
            'deposit_date'    => $db['deposit_date'] ?? null,
            'account_name'    => $db['account_name'] ?? null,
            'amt_match'       => $amt_match,
        ];
    }
    ob_end_clean(); echo json_encode(['success'=>true,'rows'=>$results]); exit;
}

/* ── AJAX: manual_status_update — for already-cleared/returned cheques ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'manual_status_update') {
    ob_start(); header('Content-Type: application/json');
    ensure_columns($conn);
    ensure_log_table($conn);
    ensure_return_charge_table($conn);

    $db_id        = intval($_POST['db_id'] ?? 0);
    $new_status   = trim($_POST['new_status'] ?? '');
    $return_reason= trim($_POST['return_reason'] ?? '');
    $bank_ref     = trim($_POST['bank_ref'] ?? '');
    $tx_date      = trim($_POST['tx_date'] ?? '');
    $cu           = mysqli_real_escape_string($conn, get_current_user_label());

    if (!$db_id || !in_array($new_status, ['cleared','returned','deposited','pending','sent_back'])) {
        ob_end_clean(); echo json_encode(['success'=>false,'error'=>'Invalid parameters']); exit;
    }

    $old_r   = mysqli_query($conn, "SELECT status, cheque_no, t_code, total_amount FROM cheques WHERE id=$db_id LIMIT 1");
    $old_row = $old_r ? mysqli_fetch_assoc($old_r) : null;
    if (!$old_row) { ob_end_clean(); echo json_encode(['success'=>false,'error'=>'Cheque not found']); exit; }

    $old_st   = $old_row['status'];
    $db_chqno = $old_row['cheque_no'];
    $t_code   = $old_row['t_code'];
    $chq_amt  = floatval($old_row['total_amount']);

    $br_e  = mysqli_real_escape_string($conn, $bank_ref);
    $rr_e  = mysqli_real_escape_string($conn, $return_reason);
    $rr_set = $rr_e ? ", return_reason='$rr_e'" : '';
    $crn_set = '';

    if ($new_status === 'returned') {
        $crn_sql = 'NULL';
        if ($tx_date !== '') {
            $parsed = false;
            foreach (['d/m/Y','Y-m-d','m/d/Y','d-m-Y','d.m.Y','Y/m/d'] as $fmt) {
                $dt = DateTime::createFromFormat($fmt, $tx_date);
                if ($dt) { $parsed = $dt; break; }
            }
            if (!$parsed) { $ts = strtotime($tx_date); if ($ts !== false) $parsed = (new DateTime())->setTimestamp($ts); }
            if ($parsed) $crn_sql = "'" . $parsed->format('Y-m-d') . "'";
        }
        $crn_set = ", crn_date_of_return=$crn_sql";
    }

    $ok = mysqli_query($conn, "UPDATE cheques SET status='$new_status', bank_ref='$br_e'$rr_set$crn_set WHERE id=$db_id");

    if ($ok) {
        $note  = mysqli_real_escape_string($conn, "Manual Recon Update | Bank Ref: $bank_ref | Tx Date: $tx_date" . ($return_reason ? " | Reason: $return_reason" : ''));
        $old_e = mysqli_real_escape_string($conn, $old_st);
        mysqli_query($conn, "INSERT INTO cheque_logs(cheque_id,action,old_value,new_value,note,created_by) VALUES($db_id,'status_change','$old_e','$new_status','$note','$cu')");

        if ($new_status === 'returned') {
            $dup = mysqli_query($conn, "SELECT id FROM cheque_return_charges WHERE cheque_id=$db_id LIMIT 1");
            if (!($dup && mysqli_num_rows($dup) > 0)) {
                $cno_e = mysqli_real_escape_string($conn, $db_chqno);
                $tco_e = mysqli_real_escape_string($conn, $t_code);
                mysqli_query($conn, "INSERT INTO cheque_return_charges(cheque_id,cheque_no,t_code,cheque_amount,return_charge,return_reason,charged_by) VALUES($db_id,'$cno_e','$tco_e',$chq_amt,250.00,'$rr_e','$cu')");
            }
        }
        ob_end_clean(); echo json_encode(['success'=>true,'new_status'=>$new_status,'cheque_no'=>$db_chqno]); exit;
    }
    ob_end_clean(); echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]); exit;
}

/* ── AJAX: recon_apply ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'recon_apply') {
    ob_start(); header('Content-Type: application/json');
    ensure_columns($conn);
    ensure_log_table($conn);
    ensure_return_charge_table($conn);
    ensure_recon_tables($conn);

    $items         = json_decode($_POST['items']    ?? '[]', true);
    $all_rows      = json_decode($_POST['all_rows'] ?? '[]', true);
    $file_name     = trim($_POST['file_name']     ?? 'Unknown');
    $statement_date= trim($_POST['statement_date'] ?? '');

    /* bank statement mode: account / type from Settings, lines are marked reconciled */
    $mode = (($_POST['mode'] ?? '') === 'statement') ? 'statement' : 'file';
    $crs_cfg = null; $crs_from = ''; $crs_to = '';
    if ($mode === 'statement') {
        ensure_stmt_recon($conn);
        $crs_cfg = crs_settings($conn, crs_accounts($conn));
        if (!$crs_cfg['account']) { ob_end_clean(); echo json_encode(['success'=>false,'error'=>'Set the bank account in Settings first.']); exit; }
        list($crs_from, $crs_to) = crs_range($_POST['date_from'] ?? '', $_POST['date_to'] ?? '');
    }
    $applied_ids = [];
    $stmt_marked = 0;

    if (!is_array($items)) { ob_end_clean(); echo json_encode(['success'=>false,'error'=>'Bad data']); exit; }

    $updated = 0; $skipped = 0; $already_done = 0; $skip_list = [];
    $cu   = mysqli_real_escape_string($conn, get_current_user_label());
    $fn_e = mysqli_real_escape_string($conn, $file_name);
    $sd_e = $statement_date ? "'".mysqli_real_escape_string($conn,$statement_date)."'" : "NULL";
    $total = is_array($all_rows) ? count($all_rows) : count($items);

    mysqli_query($conn,
        "INSERT INTO cheque_recon_uploads(file_name,statement_date,total_rows,created_by)
         VALUES('$fn_e',$sd_e,$total,'$cu')");
    $upload_id = mysqli_insert_id($conn);
    if ($mode === 'statement' && $upload_id) {
        $bt_sql = $crs_cfg['type'] !== '' ? "'" . mysqli_real_escape_string($conn, $crs_cfg['type']) . "'" : 'NULL';
        mysqli_query($conn, "UPDATE cheque_recon_uploads
                                SET source = 'statement', bank_account_id = " . (int)$crs_cfg['account'] . ", bank_type = $bt_sql,
                                    date_from = " . ($crs_from ? "'$crs_from'" : 'NULL') . ", date_to = " . ($crs_to ? "'$crs_to'" : 'NULL') . "
                              WHERE id = $upload_id");
    }

    $selected_db_ids = [];
    foreach ($items as $item) {
        $did = intval($item['db_id'] ?? 0);
        if ($did) $selected_db_ids[$did] = $item;
    }

    $stat_matched = 0; $stat_cleared = 0; $stat_returned = 0;
    $stat_notfound = 0; $stat_already = 0;

    /* ── record every row from the statement ── */
    if (is_array($all_rows) && count($all_rows) > 0) {
        foreach ($all_rows as $arow) {
            $uid_e = intval($upload_id);
            $cid   = intval($arow['db_id'] ?? 0);
            $scn_e = mysqli_real_escape_string($conn, $arow['stmt_cheque_no'] ?? '');
            $dcn_e = mysqli_real_escape_string($conn, $arow['db_cheque_no']   ?? '');
            $sa    = floatval($arow['bank_amount'] ?? 0);
            $da    = isset($arow['db_amount']) && $arow['db_amount'] !== null ? floatval($arow['db_amount']) : 'NULL';
            $tp_e  = mysqli_real_escape_string($conn, $arow['type'] ?? 'cleared');
            $mtch  = $arow['matched']  ? 1 : 0;
            $alr   = !empty($arow['already_updated']) ? 1 : 0;
            $br_e  = mysqli_real_escape_string($conn, $arow['bank_ref']      ?? '');
            $td_e  = mysqli_real_escape_string($conn, $arow['tx_date']       ?? '');
            $cn_e  = mysqli_real_escape_string($conn, $arow['customer_name'] ?? '');
            $tc_e  = mysqli_real_escape_string($conn, $arow['db_t_code']     ?? '');
            $am    = !empty($arow['amt_match']) ? 1 : 0;
            $dsc_e = mysqli_real_escape_string($conn, $arow['description']   ?? '');
            $dd    = !empty($arow['deposit_date'])
                     ? "'".mysqli_real_escape_string($conn,$arow['deposit_date'])."'" : "NULL";
            $an_e  = mysqli_real_escape_string($conn, $arow['account_name']  ?? '');
            $old_st= mysqli_real_escape_string($conn, $arow['db_status']     ?? '');
            $is_applied   = ($cid && isset($selected_db_ids[$cid]) && !$alr) ? 1 : 0;
            $new_st       = $is_applied ? ($tp_e === 'returned' ? 'returned' : 'cleared') : '';
            $new_st_e     = mysqli_real_escape_string($conn, $new_st);
            $da_sql       = ($da === 'NULL') ? 'NULL' : $da;

            mysqli_query($conn,
                "INSERT INTO cheque_recon_upload_items
                    (upload_id,cheque_id,stmt_cheque_no,db_cheque_no,stmt_amount,db_amount,
                     type,matched,already_updated,applied,old_status,new_status,
                     bank_ref,tx_date,customer_name,t_code,amt_match,description,deposit_date,account_name)
                 VALUES($uid_e,".($cid?$cid:'NULL').",'$scn_e','$dcn_e',$sa,$da_sql,
                        '$tp_e',$mtch,$alr,$is_applied,'$old_st','$new_st_e',
                        '$br_e','$td_e','$cn_e','$tc_e',$am,'$dsc_e',$dd,'$an_e')");
            if ($mode === 'statement' && !empty($arow['bank_txn_id'])) {
                $item_row_id = mysqli_insert_id($conn);
                mysqli_query($conn, "UPDATE cheque_recon_upload_items SET bank_txn_id = " . intval($arow['bank_txn_id']) . " WHERE id = $item_row_id");
            }

            if (!$mtch) {
                $sd_nf = $statement_date
                         ? "'".mysqli_real_escape_string($conn,$statement_date)."'" : "NULL";
                mysqli_query($conn,
                    "INSERT INTO cheque_recon_not_found
                        (upload_id,stmt_cheque_no,stmt_amount,type,bank_ref,tx_date,
                         description,statement_date,file_name,created_by)
                     VALUES($uid_e,'$scn_e',$sa,'$tp_e','$br_e','$td_e',
                            '$dsc_e',$sd_nf,'$fn_e','$cu')");
                $stat_notfound++;
            } elseif ($alr) {
                $stat_already++;
            } else {
                $stat_matched++;
                if ($tp_e === 'cleared') $stat_cleared++; else $stat_returned++;
            }
        }
    }

    /* ── apply status changes to cheques ── */
    foreach ($items as $item) {
        $db_id    = intval($item['db_id'] ?? 0);
        $new_st   = ($item['type'] === 'returned') ? 'returned' : 'cleared';
        $bank_ref = mysqli_real_escape_string($conn, trim($item['bank_ref']      ?? ''));
        $return_reason = mysqli_real_escape_string($conn, trim($item['return_reason'] ?? ''));
        $cheque_no_raw = trim($item['stmt_cheque_no'] ?? '');

        if (!$db_id) {
            /* ── No db_id from preview: try to re-resolve by nearest amount ── */
            $stmt_amt_item = floatval($item['bank_amount'] ?? 0);
            $re_db = find_cheque_by_no($conn, $cheque_no_raw, $stmt_amt_item);
            if ($re_db) {
                $db_id = intval($re_db['id']);
            } else {
                $skipped++;
                $skip_list[] = $cheque_no_raw . ' (no DB match)';
                continue;
            }
        }

        $old_r   = mysqli_query($conn,
            "SELECT status, cheque_no, t_code, total_amount
               FROM cheques WHERE id=$db_id LIMIT 1");
        $old_row = $old_r ? mysqli_fetch_assoc($old_r) : null;
        $old_st  = $old_row['status']       ?? '';
        $db_chqno= $old_row['cheque_no']    ?? $cheque_no_raw;
        $t_code  = $old_row['t_code']       ?? '';
        $chq_amt = floatval($old_row['total_amount'] ?? 0);

        if ($old_st === $new_st) {
            $already_done++;
            $skip_list[] = $db_chqno . ' (already ' . $new_st . ')';
            continue;
        }

        /* ── GUARD: already cleared or returned — skip in bulk apply (manual update available) ── */
        if ($old_st === 'cleared' || $old_st === 'returned') {
            $skipped++;
            $skip_list[] = $db_chqno . ' (already ' . $old_st . ' — use Manual Update)';
            continue;
        }

        /* ── build UPDATE: set crn_date_of_return = tx_date from bank statement when returned ── */
        $rr_set  = '';
        $crn_set = '';
        if ($new_st === 'returned') {
            if ($return_reason) $rr_set = ", return_reason='$return_reason'";
            $raw_tx  = trim($item['tx_date'] ?? '');
            $crn_sql = 'NULL';
            if ($raw_tx !== '') {
                $parsed = false;
                foreach (['d/m/Y','Y-m-d','m/d/Y','d-m-Y','d.m.Y','Y/m/d'] as $fmt) {
                    $dt = DateTime::createFromFormat($fmt, $raw_tx);
                    if ($dt) { $parsed = $dt; break; }
                }
                if (!$parsed) {
                    $ts = strtotime($raw_tx);
                    if ($ts !== false) $parsed = (new DateTime())->setTimestamp($ts);
                }
                if ($parsed) {
                    $crn_sql = "'" . $parsed->format('Y-m-d') . "'";
                }
            }
            $crn_set = ", crn_date_of_return=$crn_sql";
        }

        $ok = mysqli_query($conn,
            "UPDATE cheques
                SET status='$new_st', bank_ref='$bank_ref'$rr_set$crn_set
              WHERE id=$db_id");

        if ($ok) {
            $note_parts = [
                'Bank Statement Recon',
                "Upload #$upload_id",
                "Bank Ref: {$item['bank_ref']}",
                "Tx Date: {$item['tx_date']}"
            ];
            if ($new_st === 'returned' && $return_reason)
                $note_parts[] = "Return Reason: $return_reason";

            $note  = mysqli_real_escape_string($conn, implode(' | ', $note_parts));
            $old_e = mysqli_real_escape_string($conn, $old_st);

            mysqli_query($conn,
                "INSERT INTO cheque_logs
                    (cheque_id, action, old_value, new_value, note, created_by)
                 VALUES($db_id,'status_change','$old_e','$new_st','$note','$cu')");

            mysqli_query($conn,
                "UPDATE cheque_recon_upload_items
                    SET applied=1, old_status='$old_e', new_status='$new_st'
                  WHERE upload_id=$upload_id AND cheque_id=$db_id LIMIT 1");

            if ($new_st === 'returned') {
                $dup = mysqli_query($conn,
                    "SELECT id FROM cheque_return_charges WHERE cheque_id=$db_id LIMIT 1");
                if (!($dup && mysqli_num_rows($dup) > 0)) {
                    $cno_e = mysqli_real_escape_string($conn, $db_chqno);
                    $tco_e = mysqli_real_escape_string($conn, $t_code);
                    mysqli_query($conn,
                        "INSERT INTO cheque_return_charges
                            (cheque_id,cheque_no,t_code,cheque_amount,return_charge,return_reason,charged_by)
                         VALUES($db_id,'$cno_e','$tco_e',$chq_amt,250.00,'$return_reason','$cu')");
                    if ($mode === 'statement') {
                        mysqli_query($conn, "UPDATE cheque_return_charges SET recon_upload_id = $upload_id WHERE id = " . mysqli_insert_id($conn));
                    }
                }
            }
            $updated++;
            $applied_ids[$db_id] = ['old' => $old_st, 'reason' => trim($item['return_reason'] ?? '')];
        } else {
            $skipped++;
            $skip_list[] = $db_chqno . ' (DB error: ' . mysqli_error($conn) . ')';
        }
    }

    /* ── bank statement mode: mark the statement lines whose cheque is now at the
          statement's status (updated in this batch, or already updated before) ── */
    if ($mode === 'statement' && is_array($all_rows)) {
        $now_dt = date('Y-m-d H:i:s');
        $user   = get_current_user_label();
        $src    = CRS_SOURCE; $cat = CRS_CATEGORY; $acc = (int)$crs_cfg['account'];
        $stU = mysqli_prepare($conn, "UPDATE bank_statement_transactions t
                                        JOIN bank_statement_uploads u ON u.id = t.upload_id
                                         SET t.recon_status = ?, t.recon_source = ?, t.recon_category = ?, t.recon_ref_id = ?,
                                             t.recon_remark = ?, t.recon_by = ?, t.recon_at = ?
                                       WHERE t.id = ? AND u.account_id = ? AND (t.recon_status IS NULL OR t.recon_status = '')");
        $done_txn = [];
        foreach ($all_rows as $arow) {
            $tid = intval($arow['bank_txn_id'] ?? 0);
            $cid = intval($arow['db_id'] ?? 0);
            if (!$tid || !$cid || isset($done_txn[$tid])) continue;
            $target = (($arow['type'] ?? '') === 'returned') ? 'returned' : 'cleared';
            $cr = mysqli_query($conn, "SELECT ch.status, ch.cheque_no, ch.t_code, ch.total_amount, ch.deposit_date,
                                              COALESCE(NULLIF(c.shop_name,''), ch.t_code) AS customer_name
                                         FROM cheques ch LEFT JOIN customers c ON c.t_code = ch.t_code
                                        WHERE ch.id = $cid LIMIT 1");
            $ch = $cr ? mysqli_fetch_assoc($cr) : null;
            if (!$ch || $ch['status'] !== $target) continue;      /* not at the statement's status → not reconciled */

            $bank_amt = floatval($arow['bank_amount'] ?? 0);
            $chq_amt  = floatval($ch['total_amount']);
            $diff     = round($bank_amt - $chq_amt, 2);
            $rstatus  = abs($diff) < 0.01 ? 'reconciled' : 'difference';
            $how      = isset($applied_ids[$cid])
                      ? 'status ' . ($applied_ids[$cid]['old'] ?: '—') . ' → ' . $target
                      : 'already ' . $target . ' before this recon';
            $cust     = (string)($ch['customer_name'] ?? '');
            $remark   = 'Cheque Recon #' . $upload_id
                      . ' | Chq ' . $ch['cheque_no'] . ' ' . ucfirst($target) . ' (' . $how . ')'
                      . ($cust !== '' ? ' | Customer: ' . $cust . (($ch['t_code'] && $ch['t_code'] !== $cust) ? ' (' . $ch['t_code'] . ')' : '') : '')
                      . ' | Cheque Rs ' . number_format($chq_amt, 2) . ' | Bank Rs ' . number_format($bank_amt, 2)
                      . ($rstatus === 'difference' ? ' | Difference Rs ' . number_format($diff, 2) : '')
                      . (!empty($ch['deposit_date']) ? ' | Deposit date: ' . $ch['deposit_date'] : '')
                      . (!empty($applied_ids[$cid]['reason']) ? ' | Return reason: ' . $applied_ids[$cid]['reason'] : '')
                      . ' | by ' . $user . ' on ' . $now_dt;
            $uidi = (int)$upload_id;
            mysqli_stmt_bind_param($stU, 'sssisssii', $rstatus, $src, $cat, $uidi, $remark, $user, $now_dt, $tid, $acc);
            mysqli_stmt_execute($stU);
            if (mysqli_stmt_affected_rows($stU) === 1) {
                $stmt_marked++;
                $done_txn[$tid] = true;
                mysqli_query($conn, "UPDATE cheque_recon_upload_items SET stmt_recon_status = '$rstatus' WHERE upload_id = $uidi AND bank_txn_id = $tid");
            }
        }
        mysqli_stmt_close($stU);
        mysqli_query($conn, "UPDATE cheque_recon_uploads SET stmt_reconciled = $stmt_marked WHERE id = $upload_id");
    }

    mysqli_query($conn,
        "UPDATE cheque_recon_uploads
            SET matched=$stat_matched, cleared=$stat_cleared, returned=$stat_returned,
                not_found=$stat_notfound, already_done=$stat_already, skipped=$skipped
          WHERE id=$upload_id");

    ob_end_clean();
    echo json_encode([
        'success'     => true,
        'updated'     => $updated,
        'skipped'     => $skipped,
        'already_done'=> $already_done,
        'skip_list'   => $skip_list,
        'upload_id'   => $upload_id,
        'stmt_marked' => $stmt_marked,
        'mode'        => $mode,
    ]);
    exit;
}

/* ── page data: bank statement mode ── */
ensure_stmt_recon($conn);
$crs_accounts = crs_accounts($conn);
$crs_cfg      = crs_settings($conn, $crs_accounts);
$crs_batches  = [];
$r = crs_q($conn, "SELECT * FROM cheque_recon_uploads WHERE source = 'statement' ORDER BY created_at DESC, id DESC LIMIT 25");
if ($r) while ($row = mysqli_fetch_assoc($r)) $crs_batches[] = $row;
function crs_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

include 'header.php';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<style>
:root{--ink:#0f172a;--ink2:#1e293b;--ink3:#334155;--muted:#64748b;--lite:#f8fafc;--card:#ffffff;--bdr:#e2e8f0;--pri:#1e3a5f;--pri2:#2563eb;--teal:#0d9488;--green:#16a34a;--red:#dc2626;--amber:#d97706;--violet:#7c3aed;--shadow:0 1px 3px rgba(0,0,0,.06),0 4px 16px rgba(0,0,0,.05);--shadow-lg:0 8px 32px rgba(0,0,0,.12);}
.rc-wrap{max-width:1280px;margin:0 auto;padding:20px 16px 80px;font-family:'Segoe UI',system-ui,sans-serif;}
.rc-hero{background:linear-gradient(135deg,var(--pri) 0%,#163354 50%,#0d2d4a 100%);border-radius:16px;padding:20px 26px;display:flex;align-items:center;gap:18px;flex-wrap:wrap;margin-bottom:22px;position:relative;overflow:hidden;}
.rc-hero::before{content:'';position:absolute;top:-40px;right:-60px;width:240px;height:240px;border-radius:50%;background:rgba(14,165,233,.12);pointer-events:none;}
.rc-hero::after{content:'';position:absolute;bottom:-60px;left:30%;width:180px;height:180px;border-radius:50%;background:rgba(13,148,136,.1);pointer-events:none;}
.hero-icon{width:52px;height:52px;border-radius:14px;background:rgba(255,255,255,.12);display:flex;align-items:center;justify-content:center;font-size:24px;color:#fff;flex-shrink:0;border:1px solid rgba(255,255,255,.18);}
.hero-title{color:#fff;font-size:20px;font-weight:800;line-height:1.2;}.hero-sub{color:rgba(255,255,255,.65);font-size:12px;margin-top:3px;}
.hero-right{margin-left:auto;display:flex;align-items:center;gap:10px;flex-wrap:wrap;position:relative;z-index:1;}
.btn-hero-back{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.22);border-radius:9px;padding:8px 16px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;transition:background .2s;}
.btn-hero-back:hover{background:rgba(255,255,255,.22);color:#fff;}
.step-wizard{display:flex;align-items:center;background:var(--card);border:1.5px solid var(--bdr);border-radius:14px;padding:14px 22px;margin-bottom:22px;gap:0;box-shadow:var(--shadow);overflow-x:auto;}
.wizard-step{display:flex;align-items:center;gap:10px;padding:6px 16px;border-radius:10px;cursor:default;flex-shrink:0;}
.wz-num{width:28px;height:28px;border-radius:50%;font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;background:#e2e8f0;color:var(--muted);}
.wz-label{font-size:13px;font-weight:700;color:var(--muted);}.wz-sep{flex:1;height:2px;background:var(--bdr);min-width:30px;margin:0 4px;}
.wizard-step.active .wz-num{background:var(--pri2);color:#fff;box-shadow:0 0 0 4px rgba(37,99,235,.2);}.wizard-step.active .wz-label{color:var(--pri2);}
.wizard-step.done .wz-num{background:var(--green);color:#fff;}.wizard-step.done .wz-label{color:var(--green);}
.step-panel{display:none;}.step-panel.active{display:block;animation:fadeUp .22s ease;}
@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.rc-card{background:var(--card);border:1.5px solid var(--bdr);border-radius:14px;box-shadow:var(--shadow);overflow:hidden;}
.rc-card-hdr{padding:14px 20px;border-bottom:1.5px solid var(--bdr);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;background:linear-gradient(to right,#fafbff,#fff);}
.rc-card-title{font-size:14px;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:8px;}
.rc-card-body{padding:20px;}
.upload-zone{border:2.5px dashed #93c5fd;border-radius:14px;padding:48px 24px;text-align:center;background:linear-gradient(135deg,#eff6ff,#f0f9ff);cursor:pointer;transition:all .2s;}
.upload-zone:hover,.upload-zone.drag-over{border-color:var(--pri2);background:linear-gradient(135deg,#dbeafe,#e0f2fe);transform:translateY(-2px);box-shadow:0 8px 24px rgba(37,99,235,.12);}
.uz-icon{font-size:48px;color:var(--pri2);margin-bottom:14px;display:block;}.uz-title{font-size:18px;font-weight:800;color:var(--ink);margin-bottom:6px;}
.uz-sub{font-size:12.5px;color:var(--muted);line-height:1.6;}
.uz-badge{display:inline-flex;align-items:center;gap:5px;background:var(--pri2);color:#fff;border-radius:8px;padding:8px 20px;font-size:13px;font-weight:700;margin-top:16px;}
.upload-zone:hover .uz-badge{background:#1d4ed8;}
.stmt-date-bar{display:flex;align-items:center;gap:14px;flex-wrap:wrap;background:linear-gradient(135deg,#f0fdfa,#ecfdf5);border:1.5px solid #5eead4;border-radius:12px;padding:14px 20px;margin-bottom:18px;}
.stmt-date-bar label{font-size:13px;font-weight:800;color:#0f766e;display:flex;align-items:center;gap:7px;}
.stmt-date-bar input[type="date"]{border:1.5px solid #5eead4;border-radius:8px;padding:8px 14px;font-size:13px;font-weight:600;outline:none;font-family:inherit;color:var(--ink);background:#fff;}
.fmt-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:18px;}@media(max-width:620px){.fmt-grid{grid-template-columns:1fr;}}
.fmt-box{border-radius:10px;padding:14px 16px;border:1.5px solid;}.fmt-box.ndb{background:#f0fdfa;border-color:#5eead4;}.fmt-box.gen{background:#fef3c7;border-color:#fde68a;}
.fmt-title{font-size:12px;font-weight:800;margin-bottom:8px;display:flex;align-items:center;gap:6px;}.fmt-box.ndb .fmt-title{color:#0f766e;}.fmt-box.gen .fmt-title{color:#92400e;}
.fmt-row{font-size:11.5px;line-height:1.8;color:#374151;}code.fc{background:rgba(0,0,0,.06);padding:1px 5px;border-radius:3px;font-size:10.5px;font-family:'Courier New',monospace;}
.cm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;}
.cm-field label{font-size:11px;font-weight:700;color:#78350f;display:block;margin-bottom:4px;}
.cm-field select{width:100%;border:1.5px solid #fde68a;border-radius:7px;padding:6px 10px;font-size:12px;background:#fff;color:var(--ink);outline:none;font-family:inherit;cursor:pointer;}
.kpi-strip{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:18px;}@media(max-width:900px){.kpi-strip{grid-template-columns:repeat(3,1fr);}}@media(max-width:480px){.kpi-strip{grid-template-columns:repeat(2,1fr);}}
.kpi-mini{background:var(--card);border:1.5px solid var(--bdr);border-radius:12px;padding:12px 14px;box-shadow:var(--shadow);}
.kpi-mini .km-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:4px;}
.kpi-mini .km-val{font-size:22px;font-weight:900;color:var(--ink);}.kpi-mini .km-val.green{color:var(--green);}.kpi-mini .km-val.red{color:var(--red);}.kpi-mini .km-val.amber{color:var(--amber);}.kpi-mini .km-val.sky{color:#0284c7;}.kpi-mini .km-val.violet{color:var(--violet);}
.ctrl-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:14px;padding:12px 16px;background:var(--lite);border:1.5px solid var(--bdr);border-radius:10px;}
.sc-btn{background:#fff;border:1.5px solid var(--bdr);border-radius:7px;padding:6px 13px;font-size:11.5px;font-weight:700;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:5px;color:var(--ink3);transition:all .15s;}
.sc-btn:hover{background:#f1f5f9;}.ctrl-sep{flex:1;}
.prev-outer{border:1.5px solid var(--bdr);border-radius:10px;overflow:hidden;}.prev-scroll{overflow-x:auto;max-height:520px;overflow-y:auto;}
.prev-table{width:100%;border-collapse:collapse;font-size:12px;min-width:1100px;}
.prev-table thead th{padding:9px 11px;background:var(--ink);color:#e2e8f0;font-size:10.5px;font-weight:700;text-align:left;white-space:nowrap;border-right:1px solid rgba(255,255,255,.08);position:sticky;top:0;z-index:5;}
.prev-table thead th.tr{text-align:right;}.prev-table thead th.tc{text-align:center;}
.prev-table tbody tr{border-bottom:1px solid #f1f5f9;}.prev-table tbody tr:hover td{background:#f8faff !important;}
.prev-table td{padding:9px 11px;vertical-align:middle;background:#fff;}

/* ── Row type styles ── */
.prev-table tr.row-cleared td{background:#f0fdf4;}
.prev-table tr.row-returned td{background:#fff5f5;}
.prev-table tr.row-already td{background:#fafafa;}
.prev-table tr.row-notfound td{opacity:.5;}

/* ── Amount mismatch row — amber highlight ── */
.prev-table tr.row-amt-mismatch td{background:#fffbeb !important;border-left:3px solid #f59e0b;}
.prev-table tr.row-amt-mismatch:hover td{background:#fef3c7 !important;}

/* ── Already-updated row with manual update option ── */
.prev-table tr.row-manual td{background:#f5f3ff;}
.prev-table tr.row-manual:hover td{background:#ede9fe !important;}

/* ── Duplicate-resolved row — teal tint ── */
.prev-table tr.row-dup-resolved td{background:#f0fdfa;}
.prev-table tr.row-dup-resolved:hover td{background:#ccfbf1 !important;}

.tr{text-align:right;}.tc{text-align:center;}
.chq-raw{font-family:'Courier New',monospace;font-size:11px;color:#9ca3af;}
.chq-db{font-family:'Courier New',monospace;font-size:12.5px;font-weight:800;color:#312e81;background:#ede9fe;padding:2px 7px;border-radius:5px;}
.chq-arrow{color:var(--muted);margin:0 4px;font-size:10px;}
.pill{display:inline-flex;align-items:center;gap:3px;padding:2px 9px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap;}
.p-blue{background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;}.p-green{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.p-red{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}.p-violet{background:#f5f3ff;color:#6d28d9;border:1px solid #ddd6fe;}
.p-gray{background:#f3f4f6;color:#374151;border:1px solid #e5e5e5;}.p-amber{background:#fef3c7;color:#92400e;border:1px solid #fde68a;}
.p-teal{background:#ccfbf1;color:#0f766e;border:1px solid #5eead4;}
.mb{display:inline-flex;align-items:center;gap:3px;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;white-space:nowrap;}
.mb-ok{background:#dcfce7;color:#166534;}.mb-no{background:#fee2e2;color:#991b1b;}.mb-clr{background:#dcfce7;color:#16a34a;}.mb-ret{background:#fee2e2;color:#dc2626;}
.mb-amtok{background:#dcfce7;color:#166534;}.mb-amtno{background:#fef3c7;color:#92400e;border:1px solid #fbbf24;font-weight:900;}.mb-skip{background:#e5e7eb;color:#6b7280;}.mb-lz{background:#ede9fe;color:#5b21b6;font-family:'Courier New',monospace;}
.mb-manual{background:#f5f3ff;color:#6d28d9;border:1px solid #ddd6fe;}
.mb-dup{background:#ccfbf1;color:#0f766e;border:1px solid #5eead4;font-weight:900;}
.reason-inp{border:1px solid #fecaca;border-radius:5px;padding:4px 8px;font-size:11px;width:150px;color:#991b1b;outline:none;background:#fff5f5;font-family:inherit;}
.reason-inp::placeholder{color:#fca5a5;font-style:italic;}

/* ── Manual update panel (inline in already-updated rows) ── */
.manual-update-panel{margin-top:8px;background:#f5f3ff;border:1.5px solid #c4b5fd;border-radius:10px;padding:12px 14px;display:none;animation:fadeUp .18s ease;}
.manual-update-panel.open{display:block;}
.mup-title{font-size:11px;font-weight:800;color:#6d28d9;margin-bottom:8px;display:flex;align-items:center;gap:6px;}
.mup-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px;margin-bottom:10px;}
.mup-field label{font-size:10px;font-weight:700;color:#5b21b6;display:block;margin-bottom:3px;}
.mup-field select,.mup-field input{width:100%;border:1.5px solid #c4b5fd;border-radius:6px;padding:5px 8px;font-size:11px;outline:none;font-family:inherit;background:#fff;color:var(--ink);}
.mup-btns{display:flex;align-items:center;gap:7px;flex-wrap:wrap;}
.btn-mup-save{display:inline-flex;align-items:center;gap:5px;background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;border:none;border-radius:7px;padding:6px 14px;font-size:11px;font-weight:800;cursor:pointer;font-family:inherit;}
.btn-mup-save:hover{filter:brightness(1.08);}
.btn-mup-cancel{display:inline-flex;align-items:center;gap:5px;background:#f3f4f6;color:#374151;border:1.5px solid #e5e7eb;border-radius:7px;padding:5px 12px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;}
.btn-mup-toggle{display:inline-flex;align-items:center;gap:4px;background:#7c3aed;color:#fff;border:none;border-radius:6px;padding:4px 10px;font-size:10px;font-weight:800;cursor:pointer;font-family:inherit;white-space:nowrap;}
.btn-mup-toggle:hover{background:#6d28d9;}
.mup-status-updated{display:inline-flex;align-items:center;gap:4px;background:#dcfce7;color:#166534;border:1px solid #86efac;border-radius:6px;padding:3px 9px;font-size:10px;font-weight:800;}

/* ── Amount mismatch warning badge ── */
.amt-mismatch-badge{display:inline-flex;align-items:center;gap:4px;background:#fef3c7;color:#92400e;border:1.5px solid #fbbf24;border-radius:6px;padding:3px 8px;font-size:10px;font-weight:900;animation:pulse-amber 1.5s ease-in-out infinite;}
@keyframes pulse-amber{0%,100%{box-shadow:0 0 0 0 rgba(245,158,11,.4);}50%{box-shadow:0 0 0 4px rgba(245,158,11,0);}}

.btn-primary{display:inline-flex;align-items:center;gap:7px;background:linear-gradient(135deg,var(--pri),var(--pri2));color:#fff;border:none;border-radius:10px;padding:11px 24px;font-size:13px;font-weight:800;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px rgba(37,99,235,.3);}
.btn-primary:hover{filter:brightness(1.08);}.btn-primary:disabled{opacity:.5;cursor:not-allowed;}
.btn-success{display:inline-flex;align-items:center;gap:7px;background:linear-gradient(135deg,#15803d,var(--green));color:#fff;border:none;border-radius:10px;padding:11px 24px;font-size:13px;font-weight:800;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px rgba(22,163,74,.3);}
.btn-secondary{background:var(--lite);border:1.5px solid var(--bdr);border-radius:10px;padding:10px 20px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;color:var(--ink3);}
.btn-secondary:hover{background:#e2e8f0;}
.action-footer{background:var(--card);border:1.5px solid var(--bdr);border-radius:14px;padding:16px 22px;display:flex;align-items:center;gap:12px;justify-content:flex-end;flex-wrap:wrap;box-shadow:var(--shadow);margin-top:18px;}
.af-info{flex:1;font-size:12.5px;color:var(--muted);font-weight:600;min-width:200px;}
.result-card{border-radius:14px;padding:32px 28px;text-align:center;background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:2px solid #4ade80;margin-bottom:16px;}
.result-card .ri{font-size:52px;color:var(--green);display:block;margin-bottom:12px;}.result-card .rn{font-size:28px;font-weight:900;color:#166534;margin-bottom:6px;}.result-card .rs{font-size:13px;color:#4b7c59;}
.zero-badge{display:inline-flex;align-items:center;gap:5px;background:#f5f3ff;border:1.5px solid #ddd6fe;border-radius:8px;padding:6px 13px;font-size:11.5px;font-weight:700;color:#6d28d9;}
.info-banner{background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:10px;padding:12px 16px;font-size:12.5px;color:#075985;line-height:1.7;margin-bottom:16px;}

/* ── Legend strip ── */
.legend-strip{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 16px;background:var(--lite);border:1.5px solid var(--bdr);border-radius:10px;margin-bottom:12px;font-size:11px;font-weight:700;}
.legend-dot{width:12px;height:12px;border-radius:3px;display:inline-block;margin-right:4px;}
.ld-green{background:#dcfce7;border:1.5px solid #86efac;}.ld-red{background:#fee2e2;border:1.5px solid #fecaca;}
.ld-amber{background:#fffbeb;border:1.5px solid #f59e0b;}.ld-violet{background:#f5f3ff;border:1.5px solid #c4b5fd;}
.ld-teal{background:#ccfbf1;border:1.5px solid #5eead4;}
.ld-gray{background:#f9fafb;border:1.5px solid #e5e7eb;opacity:.6;}

/* ── Bank statement mode ── */
.mode-tabs{display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap;}
.mode-tab{display:inline-flex;align-items:center;gap:8px;border:1.5px solid var(--bdr);background:#fff;border-radius:10px;padding:10px 18px;font-size:13px;font-weight:800;color:var(--ink3);cursor:pointer;font-family:inherit;}
.mode-tab.on{background:var(--pri);border-color:var(--pri);color:#fff;box-shadow:0 4px 14px rgba(30,58,95,.25);}
.crs-setbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:linear-gradient(135deg,#f0fdfa,#ecfdf5);border:1.5px solid #5eead4;border-radius:12px;padding:12px 16px;margin-bottom:14px;color:#0f766e;font-size:13px;}
.crs-setbar .pill{background:#fff;}
.crs-setbar .crs-change{margin-left:auto;background:#fff;border:1.5px solid #5eead4;color:#0f766e;border-radius:8px;padding:6px 12px;font-size:12px;font-weight:800;cursor:pointer;font-family:inherit;}
.crs-warn{background:#fffbeb;border:1.5px solid #fde68a;color:#92400e;border-radius:12px;padding:12px 16px;margin-bottom:14px;font-size:13px;font-weight:600;}
.crs-box{border:1.5px solid var(--bdr);border-radius:12px;padding:16px;margin-bottom:16px;background:var(--lite);}
.crs-box h4{margin:0 0 10px;font-size:13px;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:7px;}
.crs-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;align-items:end;}
.crs-grid label{font-size:11px;font-weight:700;color:var(--ink3);display:block;margin-bottom:4px;}
.crs-grid select,.crs-grid input{width:100%;border:1.5px solid var(--bdr);border-radius:8px;padding:8px 10px;font-size:13px;font-family:inherit;background:#fff;color:var(--ink);outline:none;}
.crs-grid .wide{grid-column:1/-1;}
.crs-note{font-size:11.5px;color:var(--muted);margin-top:8px;line-height:1.6;}
.crs-tbl{width:100%;border-collapse:collapse;font-size:12px;}
.crs-tbl th{padding:8px 10px;background:var(--ink);color:#e2e8f0;font-size:10.5px;text-align:left;white-space:nowrap;}
.crs-tbl td{padding:8px 10px;border-bottom:1px solid #f1f5f9;background:#fff;vertical-align:top;}
.crs-tbl tr.gone td{opacity:.4;}
.btn-crs-del{display:inline-flex;align-items:center;gap:5px;background:#fff;color:#b91c1c;border:1.5px solid #fecaca;border-radius:7px;padding:5px 10px;font-size:11px;font-weight:800;cursor:pointer;font-family:inherit;}
.btn-crs-del:hover{background:#fef2f2;}
.crs-modal{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9998;display:flex;align-items:center;justify-content:center;padding:16px;}
.crs-modal[hidden]{display:none;}
.crs-dialog{background:#fff;border-radius:14px;max-width:520px;width:100%;box-shadow:var(--shadow-lg);overflow:hidden;}
.crs-dialog h3{margin:0;padding:16px 20px;font-size:15px;font-weight:800;border-bottom:1.5px solid var(--bdr);}
.crs-dialog .body{padding:16px 20px;font-size:13px;color:var(--ink3);line-height:1.6;}
.crs-dialog .foot{display:flex;justify-content:flex-end;gap:8px;padding:12px 20px;border-top:1.5px solid var(--bdr);background:var(--lite);}
#toast{position:fixed;bottom:30px;right:26px;background:#166534;color:#fff;padding:12px 22px;border-radius:12px;font-size:13px;font-weight:700;z-index:9999;opacity:0;pointer-events:none;transition:opacity .3s;max-width:340px;box-shadow:var(--shadow-lg);}
#toast.show{opacity:1;}#toast.err{background:var(--red);}#toast.info{background:#1e40af;}
</style>

<div class="rc-wrap">
  <div class="rc-hero">
    <div class="hero-icon"><i class="fa-solid fa-scale-balanced"></i></div>
    <div style="position:relative;z-index:1;"><div class="hero-title">Cheque Reconciliation</div><div class="hero-sub">Upload a bank statement · Match &amp; clear cheques · Leading-zero-safe · Duplicate cheque nos → nearest amount match · crn_date_of_return on return · Manual Update for already-cleared/returned</div></div>
    <div class="hero-right">
      <a class="btn-hero-back" href="cheque_recon_history.php"><i class="fa-solid fa-clock-rotate-left"></i> Upload History</a>
      <a class="btn-hero-back" href="cheques.php"><i class="fa-solid fa-money-check"></i> Cheques</a>
      <a class="btn-hero-back" href="deposit.php"><i class="fa-solid fa-building-columns"></i> Deposits</a>
    </div>
  </div>

  <div class="step-wizard">
    <div class="wizard-step active" id="wz1"><span class="wz-num">1</span><span class="wz-label">Upload Statement</span></div><div class="wz-sep"></div>
    <div class="wizard-step" id="wz2"><span class="wz-num">2</span><span class="wz-label">Map Columns</span></div><div class="wz-sep"></div>
    <div class="wizard-step" id="wz3"><span class="wz-num">3</span><span class="wz-label">Review &amp; Match</span></div><div class="wz-sep"></div>
    <div class="wizard-step" id="wz4"><span class="wz-num">4</span><span class="wz-label">Apply &amp; Done</span></div>
  </div>

  <!-- STEP 1 -->
  <div class="step-panel active" id="step1">
    <div class="rc-card"><div class="rc-card-hdr"><div class="rc-card-title"><i class="fa-solid fa-upload"></i> Upload Bank Statement</div></div>
      <div class="rc-card-body">
        <div class="mode-tabs" role="tablist">
          <button type="button" class="mode-tab on" data-mode="statement" onclick="setMode('statement')"><i class="fa-solid fa-building-columns"></i> From Bank Statement</button>
          <button type="button" class="mode-tab" data-mode="file" onclick="setMode('file')"><i class="fa-solid fa-file-arrow-up"></i> Upload File</button>
        </div>

        <!-- ═══ FROM BANK STATEMENT ═══ -->
        <div id="modeStmt">
          <?php if ($crs_cfg['account']): ?>
            <div class="crs-setbar">
              <i class="fa-solid fa-building-columns"></i> <strong><?= crs_h($crs_accounts[$crs_cfg['account']]['label']) ?></strong>
              <span class="pill p-teal"><?= crs_h(crs_type_label($crs_cfg['type'])) ?></span>
              <span style="font-size:11.5px;"><?php $crs_last = $crs_cfg['type'] !== '' ? ($crs_accounts[$crs_cfg['account']]['types'][$crs_cfg['type']] ?? '') : $crs_accounts[$crs_cfg['account']]['last']; ?><?= $crs_last !== '' ? 'latest statement ' . crs_h($crs_last) : 'no bank statements uploaded yet' ?></span>
              <button type="button" class="crs-change" onclick="toggleCrsSettings()"><i class="fa-solid fa-gear"></i> Settings</button>
            </div>
          <?php elseif ($crs_accounts): ?>
            <div class="crs-warn"><i class="fa-solid fa-triangle-exclamation"></i> Choose the bank account in Settings below to reconcile cheques against its bank statements.</div>
          <?php else: ?>
            <div class="crs-warn"><i class="fa-solid fa-triangle-exclamation"></i> No bank statements are uploaded yet. Upload one in <a href="bank_statements.php">Bank Statements</a> first, or use <strong>Upload File</strong>.</div>
          <?php endif; ?>

          <?php if ($crs_accounts): ?>
          <div class="crs-box" id="crsSettings" <?= $crs_cfg['account'] ? 'hidden' : '' ?>>
            <h4><i class="fa-solid fa-gear"></i> Settings</h4>
            <div class="crs-grid">
              <div class="wide">
                <label for="crsAcc">Bank account *</label>
                <select id="crsAcc">
                  <option value="">Select…</option>
                  <?php foreach ($crs_accounts as $aid => $a): ?>
                    <option value="<?= (int)$aid ?>" data-types="<?= crs_h(json_encode($a['types'])) ?>" <?= $crs_cfg['saved_account'] === $aid ? 'selected' : '' ?>>
                      <?= crs_h($a['label']) ?> — <?= (int)$a['uploads'] ? (int)$a['uploads'] . ' statement(s), latest ' . crs_h($a['last']) : 'no statements yet' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label for="crsType">Statement type (optional)</label>
                <select id="crsType" data-saved="<?= crs_h($crs_cfg['saved_type']) ?>"><option value="">All types</option></select>
              </div>
              <div><button type="button" class="btn-primary" style="width:100%;justify-content:center;" onclick="saveCrsSettings(this)"><i class="fa-solid fa-floppy-disk"></i> Save settings</button></div>
            </div>
            <?php if ($crs_cfg['updated_at']): ?><div class="crs-note">Last saved <?= crs_h($crs_cfg['updated_at']) ?> by <?= crs_h($crs_cfg['updated_by']) ?></div><?php endif; ?>
          </div>
          <?php endif; ?>

          <?php if ($crs_cfg['account']): ?>
          <div class="crs-box">
            <h4><i class="fa-solid fa-magnifying-glass"></i> Reconcile cheques in the bank statement</h4>
            <div class="crs-grid">
              <div><label for="crsFrom">Txn date from</label><input type="date" id="crsFrom" value="<?= date('Y-m-d', strtotime('-7 days')) ?>"></div>
              <div><label for="crsTo">Txn date to</label><input type="date" id="crsTo" value="<?= date('Y-m-d') ?>"></div>
              <div><button type="button" class="btn-primary" id="crsLoadBtn" style="width:100%;justify-content:center;" onclick="stmtLoad()"><i class="fa-solid fa-link"></i> Load &amp; Match</button></div>
            </div>
            <div class="crs-note">
              Cheque numbers are read from each statement line: NDB <code class="fc">Outward Cheque Deposit/CHQ NO - X</code>, <code class="fc">Cheque Transfer/…/CHQ NO - X</code> → Cleared, <code class="fc">Outward Clg Chq Return/CHQ NO - X</code> → Returned;
              BOC cheque no column (credit → Cleared, debit marked RETURN → Returned); any <code class="fc">CHQ NO 12345</code> text.
              Lines already reconciled are left out. After Apply, reconciled lines are marked on the bank statement as <strong>Cheque Reconcile</strong> with a remark.
            </div>
          </div>
          <?php endif; ?>

          <div class="crs-box" style="background:#fff;">
            <h4><i class="fa-solid fa-layer-group"></i> Bank statement batches</h4>
            <?php if ($crs_batches): ?>
            <div style="overflow-x:auto;">
              <table class="crs-tbl">
                <thead><tr><th>#</th><th>Txn dates</th><th>Account</th><th class="tc">Matched</th><th class="tc">Cleared</th><th class="tc">Returned</th><th class="tc">Not found</th><th class="tc">Stmt lines reconciled</th><th>Saved</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($crs_batches as $b): ?>
                  <tr data-uid="<?= (int)$b['id'] ?>">
                    <td style="font-weight:800;">#<?= (int)$b['id'] ?></td>
                    <td style="white-space:nowrap;"><?= crs_h($b['date_from'] ?: '—') ?><?= ($b['date_to'] && $b['date_to'] !== $b['date_from']) ? ' – ' . crs_h($b['date_to']) : '' ?></td>
                    <td><?= crs_h($crs_accounts[(int)$b['bank_account_id']]['label'] ?? ('Account #' . (int)$b['bank_account_id'])) ?><div style="font-size:10.5px;color:var(--muted);"><?= crs_h(crs_type_label($b['bank_type'])) ?></div></td>
                    <td class="tc"><?= (int)$b['matched'] ?></td>
                    <td class="tc" style="color:#16a34a;font-weight:800;"><?= (int)$b['cleared'] ?></td>
                    <td class="tc" style="color:#dc2626;font-weight:800;"><?= (int)$b['returned'] ?></td>
                    <td class="tc" style="color:#d97706;"><?= (int)$b['not_found'] ?></td>
                    <td class="tc" style="font-weight:800;color:#0f766e;"><?= (int)$b['stmt_reconciled'] ?></td>
                    <td style="white-space:nowrap;font-size:11px;"><?= crs_h($b['created_at']) ?><div style="color:var(--muted);">by <?= crs_h($b['created_by']) ?></div></td>
                    <td><?= $b['status'] === 'reversed' ? '<span class="pill p-red">Reversed</span>' : '<span class="pill p-green">Applied</span>' ?></td>
                    <td style="white-space:nowrap;"><button type="button" class="btn-crs-del" onclick="askDeleteBatch(<?= (int)$b['id'] ?>, <?= $b['status'] === 'reversed' ? 'true' : 'false' ?>)"><i class="fa-solid fa-trash"></i> Delete batch</button></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php else: ?>
              <div class="crs-note">No bank statement batches yet.</div>
            <?php endif; ?>
          </div>
        </div>

        <!-- ═══ UPLOAD FILE (unchanged) ═══ -->
        <div id="modeFile" hidden>
        <div class="stmt-date-bar"><label><i class="fa-solid fa-calendar-day"></i> Statement Date</label><input type="date" id="stmtDate" value=""><span style="font-size:11.5px;color:#0f766e;">Select the bank statement date before uploading</span></div>
        <div class="info-banner">
          <strong><i class="fa-solid fa-circle-info"></i> Leading-Zero Smart Matching:</strong> Cheque <code style="background:#bae6fd;padding:1px 5px;border-radius:3px;">89</code> matches DB <code style="background:#bae6fd;padding:1px 5px;border-radius:3px;">00089</code> correctly. &nbsp;|&nbsp;
          <strong><i class="fa-solid fa-copy" style="color:#0d9488;"></i> Duplicate Cheque Nos:</strong> When multiple DB records share the same cheque number, the one with the <em>closest amount</em> to the bank statement is matched automatically. &nbsp;|&nbsp;
          <strong>crn_date_of_return</strong> is automatically set when a cheque is marked <em>Returned</em>. &nbsp;|&nbsp;
          <strong><i class="fa-solid fa-wand-magic-sparkles" style="color:#7c3aed;"></i> Already-cleared/returned cheques</strong> show a <strong>Manual Update</strong> button. &nbsp;|&nbsp;
          <strong><i class="fa-solid fa-triangle-exclamation" style="color:#d97706;"></i> Amount mismatches</strong> are highlighted in amber.
        </div>
        <div class="upload-zone" id="uploadZone" onclick="document.getElementById('fileInput').click()" ondragover="event.preventDefault();this.classList.add('drag-over')" ondragleave="this.classList.remove('drag-over')" ondrop="onDrop(event)">
          <span class="uz-icon"><i class="fa-solid fa-file-arrow-up"></i></span><div class="uz-title">Drop your bank statement here</div>
          <div class="uz-sub">Supports <strong>Excel (.xlsx, .xls)</strong> and <strong>CSV (.csv)</strong><br>NDB format auto-detected · Generic format: column mapping shown after upload</div>
          <div><span class="uz-badge"><i class="fa-solid fa-folder-open"></i> &nbsp;Browse File</span></div>
        </div>
        <input type="file" id="fileInput" accept=".xlsx,.xls,.csv" style="display:none" onchange="onFileSelect(this)">
        <div class="fmt-grid">
          <div class="fmt-box ndb"><div class="fmt-title"><i class="fa-solid fa-robot"></i> NDB Bank — Auto-Detected</div><div class="fmt-row">
            <i class="fa-solid fa-circle-check" style="color:#0d9488;"></i> <code class="fc">Outward Cheque Deposit/CHQ NO - XXXX</code> → Cleared<br>
            <i class="fa-solid fa-circle-check" style="color:#0d9488;"></i> <code class="fc">Cheque Transfer/NDB CHQ XXXX/CHQ NO - XXXX</code> → Cleared<br>
            <i class="fa-solid fa-circle-check" style="color:#0d9488;"></i> <code class="fc">Cheque Transfer/ANYREF/CHQ NO - XXXX</code> → Cleared<br>
            <i class="fa-solid fa-circle-xmark" style="color:#dc2626;"></i> <code class="fc">Outward Clg Chq Return/CHQ NO - XXXX</code> → Returned<br>
            <i class="fa-solid fa-table-columns"></i> Col A=Date · Col C=Desc · Col D=Ref · Col E=Debit · Col F=Credit
          </div></div>
          <div class="fmt-box gen"><div class="fmt-title"><i class="fa-solid fa-table"></i> Generic Excel / CSV</div><div class="fmt-row"><i class="fa-solid fa-wand-magic-sparkles"></i> Column mapping after upload<br><i class="fa-solid fa-hashtag"></i> Cheque number column<br><i class="fa-solid fa-coins"></i> Credit / Debit columns</div></div>
        </div>
        </div><!-- /modeFile -->
      </div>
    </div>
  </div>

  <!-- delete statement batch dialog -->
  <div class="crs-modal" id="crsDelModal" hidden>
    <div class="crs-dialog" role="dialog" aria-modal="true" aria-labelledby="crsDelTitle">
      <h3 id="crsDelTitle">Delete batch</h3>
      <div class="body">
        <p style="margin:0 0 10px;">The bank statement lines of this batch lose their <strong>Cheque Reconcile</strong> status, category and remark, and the batch is removed.</p>
        <label id="crsRevertWrap" style="display:flex;gap:8px;align-items:flex-start;font-weight:700;color:var(--ink);cursor:pointer;">
          <input type="checkbox" id="crsRevert" checked style="margin-top:3px;">
          <span>Also put the cheques back to their old status and remove the return charges added by this batch
            <span style="display:block;font-weight:500;color:var(--muted);font-size:12px;">Cheques whose status was changed again after this batch are left as they are.</span></span>
        </label>
        <p id="crsRevNote" style="margin:10px 0 0;font-size:12px;color:var(--muted);" hidden>This batch was already reversed, so cheque statuses are not touched.</p>
      </div>
      <div class="foot">
        <button type="button" class="btn-secondary" onclick="closeDelBatch()">Cancel</button>
        <button type="button" class="btn-primary" id="crsDelBtn" style="background:linear-gradient(135deg,#b91c1c,#dc2626);box-shadow:none;" onclick="doDeleteBatch()"><i class="fa-solid fa-trash"></i> Delete batch</button>
      </div>
    </div>
  </div>

  <!-- STEP 2 -->
  <div class="step-panel" id="step2">
    <div class="rc-card"><div class="rc-card-hdr"><div class="rc-card-title"><i class="fa-solid fa-table-columns"></i> Map Columns <span style="font-size:11px;font-weight:600;color:var(--muted);" id="fileNameLbl"></span></div><button class="btn-secondary" style="padding:7px 16px;font-size:12px;" onclick="resetUpload()"><i class="fa-solid fa-arrow-left"></i> Re-upload</button></div>
      <div class="rc-card-body">
        <div id="previewSheetArea"></div>
        <div style="background:#f0fdfa;border:1.5px solid #5eead4;border-radius:10px;padding:14px 18px;margin-bottom:18px;"><div style="font-size:13px;font-weight:800;color:#0f766e;margin-bottom:10px;"><i class="fa-solid fa-sliders"></i> Column Configuration</div><div class="cm-grid" id="colMapGrid"></div></div>
        <div style="background:#fef9f0;border:1.5px solid #fde68a;border-radius:10px;padding:14px 18px;margin-bottom:18px;">
          <div style="font-size:13px;font-weight:800;color:#92400e;margin-bottom:10px;"><i class="fa-solid fa-gear"></i> Action Mapping</div>
          <div class="cm-grid">
            <div class="cm-field"><label>Determine Type By</label><select id="typeBy" onchange="updateTypeByUI()"><option value="credit_col">Credit col → Cleared</option><option value="debit_col">Debit col → Returned</option><option value="single_credit">All → Cleared</option><option value="single_debit">All → Returned</option><option value="desc_keyword">Description keyword</option></select></div>
            <div class="cm-field" id="descKeyField" style="display:none;"><label>Cleared keyword</label><input type="text" id="clrKeyword" style="width:100%;border:1.5px solid #fde68a;border-radius:7px;padding:6px 10px;font-size:12px;outline:none;" value="CREDIT"></div>
          </div>
        </div>
        <div style="text-align:right;"><button class="btn-primary" onclick="runGenericPreview()"><i class="fa-solid fa-magnifying-glass"></i> Match Against Database →</button></div>
      </div>
    </div>
  </div>

  <!-- STEP 3 -->
  <div class="step-panel" id="step3">
    <div class="info-banner" id="stmtInfoBanner" hidden></div>
    <div class="kpi-strip">
      <div class="kpi-mini"><div class="km-lbl">Total Rows</div><div class="km-val sky" id="kpiTotal">—</div></div>
      <div class="kpi-mini"><div class="km-lbl">Matched</div><div class="km-val" id="kpiMatched">—</div></div>
      <div class="kpi-mini"><div class="km-lbl">→ Cleared</div><div class="km-val green" id="kpiCleared">—</div></div>
      <div class="kpi-mini"><div class="km-lbl">→ Returned</div><div class="km-val red" id="kpiReturned">—</div></div>
      <div class="kpi-mini"><div class="km-lbl">Not Found</div><div class="km-val amber" id="kpiNotFound">—</div></div>
      <div class="kpi-mini"><div class="km-lbl">Amt Mismatch</div><div class="km-val violet" id="kpiAmtMismatch">—</div></div>
    </div>

    <div class="rc-card"><div class="rc-card-hdr"><div class="rc-card-title"><i class="fa-solid fa-list-check"></i> Reconciliation Preview</div><div style="display:flex;gap:8px;align-items:center;"><div class="zero-badge"><i class="fa-solid fa-magic"></i> Leading-zero safe</div><button class="btn-secondary" style="padding:7px 14px;font-size:11.5px;" onclick="resetUpload()"><i class="fa-solid fa-arrow-left"></i> New File</button></div></div>
      <div class="rc-card-body" style="padding:14px 16px;">

        <!-- Legend -->
        <div class="legend-strip">
          <span><span class="legend-dot ld-green"></span>New Clear</span>
          <span><span class="legend-dot ld-red"></span>New Return</span>
          <span><span class="legend-dot ld-amber"></span>Amount Mismatch</span>
          <span><span class="legend-dot ld-violet"></span>Already Updated (Manual available)</span>
          <span><span class="legend-dot ld-teal"></span>Dup Cheque No (nearest amt matched)</span>
          <span><span class="legend-dot ld-gray"></span>Not Found</span>
        </div>

        <div class="ctrl-bar">
          <button class="sc-btn" onclick="selAll(true)"><i class="fa-solid fa-check-double"></i> Select All</button>
          <button class="sc-btn" onclick="selAll(false)"><i class="fa-regular fa-square"></i> Deselect</button>
          <button class="sc-btn" onclick="selByType('cleared')" style="color:#166534;"><i class="fa-solid fa-circle-check" style="color:#16a34a;"></i> Cleared</button>
          <button class="sc-btn" onclick="selByType('returned')" style="color:#991b1b;"><i class="fa-solid fa-circle-xmark" style="color:#dc2626;"></i> Returned</button>
          <button class="sc-btn" onclick="selMatched()"><i class="fa-solid fa-link"></i> Matched</button>
          <button class="sc-btn" onclick="filterAmtMismatch()" style="color:#92400e;"><i class="fa-solid fa-triangle-exclamation" style="color:#d97706;"></i> Amt Mismatch</button>
          <div class="ctrl-sep"></div>
          <input type="text" id="prevSearch" style="border:1.5px solid var(--bdr);border-radius:7px;padding:6px 11px;font-size:12px;outline:none;width:200px;font-family:inherit;" placeholder="Search…" oninput="filterPrev(this.value)">
        </div>

        <div class="prev-outer"><div class="prev-scroll"><table class="prev-table"><thead><tr>
          <th style="width:30px;"><input type="checkbox" id="allCb" onchange="selAll(this.checked)"></th>
          <th>Statement No.</th><th>DB Cheque No.</th><th>Action</th><th>Customer / T-Code</th><th>Deposit Date</th>
          <th class="tr">Stmt Amt</th><th class="tr">DB Amt</th><th class="tc">Amt</th><th class="tc">DB Status</th>
          <th>Bank Ref</th><th>Return Reason / Manual</th><th>Tx Date</th>
        </tr></thead><tbody id="prevBody"><tr><td colspan="13" style="padding:40px;text-align:center;color:var(--muted);"><i class="fa-solid fa-spinner fa-spin" style="font-size:24px;display:block;margin-bottom:8px;"></i>Loading…</td></tr></tbody></table></div></div>
      </div>
    </div>
    <div class="action-footer"><div class="af-info" id="afInfo">Review selections, then Apply.</div><button class="btn-secondary" onclick="resetUpload()"><i class="fa-solid fa-arrow-left"></i> New File</button><button class="btn-primary" id="applyBtn" onclick="applyRecon()"><i class="fa-solid fa-circle-check"></i> Apply Selected Updates</button></div>
  </div>

  <!-- STEP 4 -->
  <div class="step-panel" id="step4">
    <div id="resultArea"></div>
    <div class="action-footer" style="justify-content:center;">
      <button class="btn-secondary" onclick="resetUpload()"><i class="fa-solid fa-arrow-left"></i> Reconcile Another</button>
      <a class="btn-success" href="cheque_recon_history.php" style="text-decoration:none;"><i class="fa-solid fa-clock-rotate-left"></i> View History</a>
      <a class="btn-success" href="cheques.php" style="text-decoration:none;"><i class="fa-solid fa-money-check"></i> Go to Cheques</a>
    </div>
  </div>
</div>
<div id="toast"></div>

<script>
let _prevRows=[],_sheetData=[],_headers=[],_fileName='',_isNdb=false;
let _mode='statement',_stmtRange={from:'',to:''};

function goStep(n){['step1','step2','step3','step4'].forEach((id,i)=>{document.getElementById(id).classList.toggle('active',i+1===n);const wz=document.getElementById('wz'+(i+1));if(i+1<n){wz.className='wizard-step done';wz.querySelector('.wz-num').innerHTML='<i class="fa-solid fa-check"></i>';}else if(i+1===n){wz.className='wizard-step active';wz.querySelector('.wz-num').textContent=i+1;}else{wz.className='wizard-step';wz.querySelector('.wz-num').textContent=i+1;}});}

function resetUpload(){if(_mode==='statement'&&document.getElementById('step4').classList.contains('active')){location.reload();return;}_prevRows=[];_sheetData=[];_headers=[];_fileName='';_isNdb=false;document.getElementById('fileInput').value='';document.getElementById('uploadZone').innerHTML='<span class="uz-icon"><i class="fa-solid fa-file-arrow-up"></i></span><div class="uz-title">Drop your bank statement here</div><div class="uz-sub">Supports <strong>Excel (.xlsx, .xls)</strong> and <strong>CSV (.csv)</strong></div><div><span class="uz-badge"><i class="fa-solid fa-folder-open"></i> Browse File</span></div>';goStep(1);}

function onDrop(e){e.preventDefault();document.getElementById('uploadZone').classList.remove('drag-over');if(e.dataTransfer.files[0])processFile(e.dataTransfer.files[0]);}
function onFileSelect(inp){if(inp.files[0])processFile(inp.files[0]);}

async function processFile(file){_mode='file';document.getElementById('stmtInfoBanner').hidden=true;const ext=file.name.split('.').pop().toLowerCase();_fileName=file.name;document.getElementById('uploadZone').innerHTML='<span class="uz-icon"><i class="fa-solid fa-spinner fa-spin" style="color:var(--pri2);"></i></span><div class="uz-title">Parsing '+esc(_fileName)+'…</div>';let rows=[];try{if(ext==='csv')rows=csvTo2DArray(await file.text());else if(ext==='xlsx'||ext==='xls')rows=await excelTo2DArray(file);else{showToast('Unsupported file.','err');resetUpload();return;}}catch(e){showToast('Parse error: '+e.message,'err');resetUpload();return;}if(!rows.length){showToast('Empty file.','err');resetUpload();return;}_sheetData=rows;const ndb=tryParseNdb(rows);if(ndb.length>0){_isNdb=true;showToast('NDB format — '+ndb.length+' txn(s)','ok');await sendPreview(ndb);}else{_isNdb=false;showColumnMapper(rows);}}

/* ──────────────────────────────────────────────────────────────────────
   NDB auto-detect: 4 patterns
   Pattern 1: Outward Cheque Deposit/CHQ NO - XXXX                → Cleared
   Pattern 2: Outward Clg Chq Return/CHQ NO - XXXX                → Returned
   Pattern 3: Cheque Transfer/NDB CHQ XXXX/CHQ NO - XXXX          → Cleared
   Pattern 4: Cheque Transfer/ANY_REF/CHQ NO - XXXX               → Cleared
              e.g. "Cheque Transfer/111000304693/CHQ NO - 875244"
────────────────────────────────────────────────────────────────────── */
function tryParseNdb(rows){
  const out=[];
  rows.forEach(cols=>{
    const desc=String(cols[2]||''),
          br  =String(cols[3]||'').trim(),
          db  =parseAmt(cols[4]),
          cr  =parseAmt(cols[5]),
          td  =String(cols[0]||'').trim();
    let m;

    /* Pattern 1 — Outward Cheque Deposit */
    m=desc.match(/Outward\s+Cheque\s+Deposit\s*\/\s*CHQ\s+NO\s*[-–]\s*(\d+)/i);
    if(m){out.push({cheque_no:m[1],type:'cleared',amount:cr,bank_ref:br,tx_date:td,description:desc});return;}

    /* Pattern 2 — Outward Clg Chq Return */
    m=desc.match(/Outward\s+Cl[gq]\s+Chq\s+Return\s*\/\s*CHQ\s+NO\s*[-–]\s*(\d+)/i);
    if(m){out.push({cheque_no:m[1],type:'returned',amount:Math.abs(db),bank_ref:br,tx_date:td,description:desc});return;}

    /* Pattern 3 — Cheque Transfer/NDB CHQ XXXX/CHQ NO - XXXX */
    m=desc.match(/Cheque\s+Transfer\s*\/\s*NDB\s+CHQ\s+\d+\s*\/\s*CHQ\s+NO\s*[-–]\s*(\d+)/i);
    if(m){out.push({cheque_no:m[1],type:'cleared',amount:cr,bank_ref:br,tx_date:td,description:desc});return;}

    /* Pattern 4 — Cheque Transfer/ANY_REF/CHQ NO - XXXX (generic fallback)
       Matches any middle segment that is not already caught by pattern 3.
       e.g. "Cheque Transfer/111000304693/CHQ NO - 875244" */
    m=desc.match(/Cheque\s+Transfer\s*\/\s*[^\/]+\/\s*CHQ\s+NO\s*[-–]\s*(\d+)/i);
    if(m){out.push({cheque_no:m[1],type:'cleared',amount:cr,bank_ref:br,tx_date:td,description:desc});}
  });
  return out;
}

function showColumnMapper(rows){goStep(2);document.getElementById('fileNameLbl').textContent=_fileName;let hri=0;for(let i=0;i<Math.min(10,rows.length);i++){if(rows[i].filter(c=>String(c).trim()!=='').length>=3){hri=i;break;}}_headers=rows[hri].map((c,i)=>'Col '+(i+1)+(c?': '+String(c).slice(0,20):''));const pr=rows.slice(hri,Math.min(hri+7,rows.length));let tbl='<div style="margin-bottom:16px;overflow-x:auto;border:1.5px solid var(--bdr);border-radius:8px;"><table style="width:100%;border-collapse:collapse;font-size:11.5px;"><thead><tr>'+rows[hri].map(h=>'<th style="padding:7px 10px;background:var(--ink);color:#e2e8f0;white-space:nowrap;">'+esc(String(h).slice(0,18))+'</th>').join('')+'</tr></thead><tbody>';pr.slice(1).forEach(r=>{tbl+='<tr>'+r.map(c=>'<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;">'+esc(String(c).slice(0,22))+'</td>').join('')+'</tr>';});tbl+='</tbody></table></div>';document.getElementById('previewSheetArea').innerHTML=tbl;const no='<option value="-1">— Not used —</option>',co=_headers.map((h,i)=>'<option value="'+i+'">'+esc(h)+'</option>').join('');document.getElementById('colMapGrid').innerHTML='<div class="cm-field"><label>Cheque Number *</label><select id="cm_chqno">'+no+co+'</select></div><div class="cm-field"><label>Tx Date</label><select id="cm_date">'+no+co+'</select></div><div class="cm-field"><label>Description</label><select id="cm_desc">'+no+co+'</select></div><div class="cm-field"><label>Bank Ref</label><select id="cm_ref">'+no+co+'</select></div><div class="cm-field"><label>Credit Amount</label><select id="cm_credit">'+no+co+'</select></div><div class="cm-field"><label>Debit Amount</label><select id="cm_debit">'+no+co+'</select></div>';const gm={cm_chqno:['cheque','chq no','chq. no','cheque no','cheque number','chq'],cm_date:['date','tx date','transaction date','value date'],cm_desc:['description','narration','particulars','details','remarks'],cm_ref:['reference','ref','bank ref','ref no'],cm_credit:['credit','credit amount','credits','cr amount','cr'],cm_debit:['debit','debit amount','debits','dr amount','dr']},rh=rows[hri].map(h=>String(h).toLowerCase().trim());Object.entries(gm).forEach(([id,kw])=>{const s=document.getElementById(id);for(let i=0;i<rh.length;i++){if(kw.some(k=>rh[i].includes(k))){s.value=i;break;}}});}

function updateTypeByUI(){document.getElementById('descKeyField').style.display=document.getElementById('typeBy').value==='desc_keyword'?'':'none';}

function runGenericPreview(){const cc=parseInt(document.getElementById('cm_chqno').value),dc=parseInt(document.getElementById('cm_date').value),dsc=parseInt(document.getElementById('cm_desc').value),rc=parseInt(document.getElementById('cm_ref').value),crc=parseInt(document.getElementById('cm_credit').value),dbc=parseInt(document.getElementById('cm_debit').value),tb=document.getElementById('typeBy').value,ck=document.getElementById('clrKeyword').value.toLowerCase().trim();if(cc<0){showToast('Select Cheque Number column.','err');return;}const dr=_sheetData.slice(1),out=[];dr.forEach(cols=>{const rn=String(cols[cc]||'').trim();if(!rn||!/\d/.test(rn))return;const cr=crc>=0?parseAmt(cols[crc]):0,db=dbc>=0?parseAmt(cols[dbc]):0,desc=dsc>=0?String(cols[dsc]||''):'',ref=rc>=0?String(cols[rc]||'').trim():'',td=dc>=0?String(cols[dc]||'').trim():'';let type='cleared',amt=cr;if(tb==='credit_col'){type=cr>0?'cleared':'returned';amt=cr>0?cr:Math.abs(db);}else if(tb==='debit_col'){type='returned';amt=Math.abs(db);}else if(tb==='single_credit'){type='cleared';amt=cr||db;}else if(tb==='single_debit'){type='returned';amt=Math.abs(db)||cr;}else if(tb==='desc_keyword'){type=desc.toLowerCase().includes(ck)?'cleared':'returned';amt=cr>0?cr:Math.abs(db);}out.push({cheque_no:rn,type,amount:amt,bank_ref:ref,tx_date:td,description:desc});});if(!out.length){showToast('No valid rows.','err');return;}sendPreview(out);}

async function sendPreview(parsed){goStep(3);document.getElementById('prevBody').innerHTML='<tr><td colspan="13" style="padding:40px;text-align:center;color:var(--muted);"><i class="fa-solid fa-spinner fa-spin" style="font-size:28px;display:block;margin-bottom:10px;"></i>Matching '+parsed.length+' row(s)…</td></tr>';const fd=new FormData();fd.append('ajax_action','recon_preview');fd.append('parsed_rows',JSON.stringify(parsed));try{const res=await fetch('cheque_reconciliation.php',{method:'POST',body:fd});const data=await res.json();if(!data.success)throw new Error(data.error||'Failed');_prevRows=data.rows||[];renderPreview();}catch(e){showToast('Error: '+e.message,'err');goStep(_isNdb?1:2);}}

/* ─────────────────────────────────────────────
   renderPreview — main table builder
   Behaviours:
   1. Already-updated rows → Manual Update panel (violet row)
   2. Amount mismatch on matched row → amber highlight + pulsing badge
   3. Duplicate cheque no (stmt_no ≠ db_no numerically same but leading zeros differ) →
      teal "LZ" badge — already handled by nearest-amount PHP logic
───────────────────────────────────────────── */
function renderPreview(){
  let matched=0,toClr=0,toRet=0,notFound=0,amtMismatch=0;
  _prevRows.forEach(r=>{
    if(!r.matched){notFound++;return;}
    matched++;
    if(!r.already_updated){
      if(r.type==='cleared')toClr++;else toRet++;
    }
    if(!r.amt_match && r.matched) amtMismatch++;
  });
  document.getElementById('kpiTotal').textContent=_prevRows.length;
  document.getElementById('kpiMatched').textContent=matched;
  document.getElementById('kpiCleared').textContent=toClr;
  document.getElementById('kpiReturned').textContent=toRet;
  document.getElementById('kpiNotFound').textContent=notFound;
  document.getElementById('kpiAmtMismatch').textContent=amtMismatch;

  const stP={deposited:'<span class="pill p-blue">Deposited</span>',cleared:'<span class="pill p-green">Cleared</span>',returned:'<span class="pill p-red">Returned</span>',sent_back:'<span class="pill p-violet">Sent Back</span>',pending:'<span class="pill p-amber">Pending</span>'};

  let h='';
  _prevRows.forEach((row,idx)=>{
    const isA  = row.already_updated;
    const noM  = !row.matched;
    const amtOk= row.amt_match;

    /* ── Detect leading-zero / duplicate cheque number resolution ── */
    const isDupResolved = row.matched && row.stmt_cheque_no && row.db_cheque_no &&
                          row.stmt_cheque_no !== row.db_cheque_no &&
                          parseInt(row.stmt_cheque_no,10) === parseInt(row.db_cheque_no,10);

    /* Row class priority: mismatch > already > dup-resolved > type > not-found */
    let rc='';
    if(noM) rc='row-notfound';
    else if(isA) rc='row-manual';
    else if(!amtOk) rc='row-amt-mismatch';
    else if(isDupResolved) rc='row-dup-resolved';
    else if(row.type==='cleared') rc='row-cleared';
    else rc='row-returned';

    const sn='<span class="chq-raw">'+esc(row.stmt_cheque_no)+'</span>';
    const dn=row.db_cheque_no?'<span class="chq-arrow">→</span><span class="chq-db">'+esc(row.db_cheque_no)+'</span>':'';
    const lz=row.matched&&row.stmt_cheque_no!==row.db_cheque_no?'<span class="mb mb-lz">LZ</span>':'';

    const mB=row.matched?'<span class="mb mb-ok"><i class="fa-solid fa-check"></i> Match</span>':'<span class="mb mb-no"><i class="fa-solid fa-xmark"></i> Not Found</span>';
    const tB=row.type==='cleared'?'<span class="mb mb-clr"><i class="fa-solid fa-circle-check"></i> Clear</span>':'<span class="mb mb-ret"><i class="fa-solid fa-circle-xmark"></i> Return</span>';

    /* Amount badge — pulsing amber if mismatch */
    let aB='—';
    if(row.matched){
      if(amtOk) aB='<span class="mb mb-amtok"><i class="fa-solid fa-check"></i></span>';
      else aB='<span class="amt-mismatch-badge"><i class="fa-solid fa-triangle-exclamation"></i> Mismatch</span>';
    }

    /* Already-updated badge */
    let alB='';
    if(isA){
      const stIcon=row.db_status==='cleared'?'fa-circle-check':'fa-circle-xmark';
      const stColor=row.db_status==='cleared'?'#166534':'#991b1b';
      alB=`<span class="mb mb-manual"><i class="fa-solid ${stIcon}" style="color:${stColor};"></i> Already ${row.db_status}</span>`;
    }

    /* Return reason / Manual Update column content */
    let reasonCol='';
    if(isA && row.matched && row.db_id){
      const panelId='mup-panel-'+idx;
      const stOptions=['deposited','cleared','returned','sent_back','pending'].map(s=>`<option value="${s}"${s===row.db_status?' selected':''}>${s.charAt(0).toUpperCase()+s.slice(1).replace('_',' ')}</option>`).join('');
      reasonCol=`
        <div>
          <button class="btn-mup-toggle" onclick="toggleMup(${idx})"><i class="fa-solid fa-pen-to-square"></i> Manual Update</button>
          <span id="mup-done-${idx}" style="display:none;"></span>
          <div class="manual-update-panel" id="${panelId}">
            <div class="mup-title"><i class="fa-solid fa-wand-magic-sparkles"></i> Manual Status Update — Cheque #${esc(row.db_cheque_no||row.stmt_cheque_no)}</div>
            <div class="mup-grid">
              <div class="mup-field"><label>New Status</label><select id="mup-st-${idx}">${stOptions}</select></div>
              <div class="mup-field"><label>Bank Ref</label><input type="text" id="mup-br-${idx}" value="${esc(row.bank_ref||'')}" placeholder="Bank ref…"></div>
              <div class="mup-field"><label>Tx Date</label><input type="text" id="mup-td-${idx}" value="${esc(row.tx_date||'')}" placeholder="dd/mm/yyyy…"></div>
              <div class="mup-field"><label>Return Reason</label><input type="text" id="mup-rr-${idx}" value="" placeholder="If returning…"></div>
            </div>
            <div class="mup-btns">
              <button class="btn-mup-save" onclick="doManualUpdate(${idx},${row.db_id})"><i class="fa-solid fa-floppy-disk"></i> Save</button>
              <button class="btn-mup-cancel" onclick="toggleMup(${idx})"><i class="fa-solid fa-xmark"></i> Cancel</button>
            </div>
          </div>
        </div>`;
    } else if(row.type==='returned'&&!isA&&row.matched){
      reasonCol='<input type="text" class="reason-inp" id="rr-'+idx+'" placeholder="Reason…">';
    } else {
      reasonCol='<span style="color:#d1d5db;">—</span>';
    }

    h+=`<tr class="${rc}" data-idx="${idx}" data-amtmismatch="${(!amtOk&&row.matched)?'1':'0'}" data-search="${esc((row.stmt_cheque_no+' '+(row.db_cheque_no||'')+' '+(row.customer_name||'')+' '+(row.db_t_code||'')).toLowerCase())}">
      <td><input type="checkbox" class="row-cb" data-idx="${idx}" ${(row.matched&&!isA?'checked':'disabled')}></td>
      <td>${sn} ${lz}</td>
      <td>${dn||'—'}</td>
      <td>${tB}</td>
      <td>
        <div style="font-size:12.5px;font-weight:700;">${esc(row.customer_name||'—')}</div>
        <div style="font-size:10px;color:var(--muted);font-family:monospace;">${esc(row.db_t_code||'')}</div>
        ${mB} ${alB}
      </td>
      <td style="font-size:11.5px;">${fmtDate(row.deposit_date)}</td>
      <td class="tr" style="font-weight:700;">Rs. ${fmtN(row.bank_amount)}</td>
      <td class="tr" style="color:${!amtOk&&row.matched?'#d97706':'var(--muted)'};font-weight:${!amtOk&&row.matched?'800':'400'};">${row.db_amount!=null?'Rs. '+fmtN(row.db_amount):'—'}</td>
      <td class="tc">${aB}</td>
      <td class="tc">${stP[row.db_status]||'—'}</td>
      <td><span style="font-family:monospace;font-size:10px;color:#0284c7;">${esc(row.bank_ref||'')}</span></td>
      <td>${reasonCol}</td>
      <td style="font-size:11px;">${esc(row.tx_date||'')}</td>
    </tr>`;
  });

  document.getElementById('prevBody').innerHTML=h||'<tr><td colspan="13" style="padding:40px;text-align:center;color:var(--muted);">No rows</td></tr>';
  updateCount();
  document.getElementById('prevBody').addEventListener('change',updateCount);
}

/* ── Toggle Manual Update Panel ── */
function toggleMup(idx){
  const panel=document.getElementById('mup-panel-'+idx);
  if(panel) panel.classList.toggle('open');
}

/* ── doManualUpdate: AJAX call for already-updated cheques ── */
async function doManualUpdate(idx,dbId){
  const newSt  = document.getElementById('mup-st-'+idx)?.value||'';
  const bankRef= document.getElementById('mup-br-'+idx)?.value||'';
  const txDate = document.getElementById('mup-td-'+idx)?.value||'';
  const retRsn = document.getElementById('mup-rr-'+idx)?.value||'';

  if(!newSt){showToast('Select a status.','err');return;}

  const btn=document.querySelector('#mup-panel-'+idx+' .btn-mup-save');
  if(btn){btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';}

  const fd=new FormData();
  fd.append('ajax_action','manual_status_update');
  fd.append('db_id',dbId);
  fd.append('new_status',newSt);
  fd.append('bank_ref',bankRef);
  fd.append('tx_date',txDate);
  fd.append('return_reason',retRsn);

  try{
    const res=await fetch('cheque_reconciliation.php',{method:'POST',body:fd});
    const data=await res.json();
    if(!data.success) throw new Error(data.error||'Failed');

    _prevRows[idx].db_status=newSt;
    _prevRows[idx].already_updated=true;

    const panel=document.getElementById('mup-panel-'+idx);
    if(panel) panel.classList.remove('open');
    const doneEl=document.getElementById('mup-done-'+idx);
    if(doneEl){doneEl.style.display='inline-flex';doneEl.innerHTML=`<span class="mup-status-updated"><i class="fa-solid fa-check"></i> Updated → ${newSt}</span>`;}

    const tr=document.querySelector('#prevBody tr[data-idx="'+idx+'"]');
    if(tr){
      const stP={deposited:'<span class="pill p-blue">Deposited</span>',cleared:'<span class="pill p-green">Cleared</span>',returned:'<span class="pill p-red">Returned</span>',sent_back:'<span class="pill p-violet">Sent Back</span>',pending:'<span class="pill p-amber">Pending</span>'};
      const statusCell=tr.cells[9];
      if(statusCell) statusCell.innerHTML=stP[newSt]||newSt;
    }
    showToast('Cheque #'+esc(data.cheque_no||'')+' updated → '+newSt,'ok');
  }catch(e){
    showToast('Error: '+e.message,'err');
    if(btn){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save';}
  }
}

function selAll(v){document.querySelectorAll('.row-cb:not(:disabled)').forEach(cb=>cb.checked=v);document.getElementById('allCb').checked=v;updateCount();}
function selByType(t){document.querySelectorAll('.row-cb:not(:disabled)').forEach(cb=>{const r=_prevRows[parseInt(cb.dataset.idx)];cb.checked=r&&r.type===t;});updateCount();}
function selMatched(){document.querySelectorAll('.row-cb:not(:disabled)').forEach(cb=>{const r=_prevRows[parseInt(cb.dataset.idx)];cb.checked=r&&r.matched&&!r.already_updated;});updateCount();}
function updateCount(){document.getElementById('afInfo').textContent=document.querySelectorAll('.row-cb:checked').length+' cheque(s) selected.';}

function filterPrev(q){q=q.toLowerCase().trim();document.getElementById('prevSearch').value=q;document.querySelectorAll('#prevBody tr[data-idx]').forEach(tr=>{tr.style.display=(q&&!tr.dataset.search.includes(q))?'none':'';});}

function filterAmtMismatch(){
  document.getElementById('prevSearch').value='';
  let anyVisible=false;
  document.querySelectorAll('#prevBody tr[data-idx]').forEach(tr=>{
    const show=tr.dataset.amtmismatch==='1';
    tr.style.display=show?'':'none';
    if(show)anyVisible=true;
  });
  if(!anyVisible) showToast('No amount mismatches found.','info');
}

async function applyRecon(){
  const cbs=Array.from(document.querySelectorAll('.row-cb:checked'));
  if(!cbs.length){showToast('No cheques selected.','err');return;}
  const items=cbs.map(cb=>{
    const idx=parseInt(cb.dataset.idx),row=_prevRows[idx];
    if(!row||!row.matched||!row.db_id)return null;
    const rr=document.getElementById('rr-'+idx);
    return{...row,return_reason:rr?rr.value.trim():''};
  }).filter(Boolean);
  if(!items.length){showToast('No matched cheques.','err');return;}
  const btn=document.getElementById('applyBtn');
  btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Applying…';
  const fd=new FormData();
  fd.append('ajax_action','recon_apply');
  fd.append('items',JSON.stringify(items));
  fd.append('all_rows',JSON.stringify(_prevRows));
  fd.append('file_name',_fileName);
  fd.append('statement_date',_mode==='statement'?_stmtRange.to:(document.getElementById('stmtDate').value||''));
  fd.append('mode',_mode);
  if(_mode==='statement'){fd.append('date_from',_stmtRange.from);fd.append('date_to',_stmtRange.to);}
  try{
    const res=await fetch('cheque_reconciliation.php',{method:'POST',body:fd});
    const data=await res.json();
    if(!data.success)throw new Error(data.error||'Failed');
    showResult(data);
  }catch(e){
    showToast('Error: '+e.message,'err');
    btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-circle-check"></i> Apply Selected Updates';
  }
}

function showResult(data){
  goStep(4);
  const sl=data.skip_list||[];
  const ah=data.already_done?`<div style="background:#f3f4f6;border:1.5px solid var(--bdr);border-radius:10px;padding:14px 18px;margin-bottom:12px;"><div style="font-weight:700;color:var(--muted);"><i class="fa-solid fa-ban"></i> ${data.already_done} already at target status (use Manual Update in preview for these)</div></div>`:'';
  const sh=data.skipped?`<div style="background:#fef3c7;border:1.5px solid #fde68a;border-radius:10px;padding:14px 18px;margin-bottom:12px;"><div style="font-weight:700;color:#92400e;"><i class="fa-solid fa-triangle-exclamation"></i> ${data.skipped} skipped</div><div style="font-size:12px;color:#92400e;">${sl.map(s=>esc(s)).join(', ')}</div></div>`:'';
  const ui=data.upload_id?`<div style="margin-top:12px;text-align:center;"><span class="pill p-blue" style="font-size:12px;padding:4px 14px;">Upload #${data.upload_id} saved</span></div>`:'';
  document.getElementById('resultArea').innerHTML=`
    <div class="result-card">
      <span class="ri"><i class="fa-solid fa-circle-check"></i></span>
      <div class="rn">${data.updated} Cheque${data.updated===1?'':'s'} Updated</div>
      <div class="rs">All changes saved and logged.${data.updated>0?' Return charges Rs. 250 &amp; crn_date_of_return recorded for returned cheques.':''}${data.mode==='statement'?'<br><strong>'+(data.stmt_marked||0)+' bank statement line(s)</strong> marked as <strong>Cheque Reconcile</strong> with remarks.':''}</div>
      ${ui}
    </div>
    ${ah}${sh}
    <div style="background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:10px;padding:14px 18px;">
      <div style="font-weight:700;color:#0284c7;margin-bottom:6px;"><i class="fa-solid fa-circle-info"></i> Summary</div>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;font-size:13px;">
        <div><span style="font-weight:800;font-size:18px;color:#166534;">${data.updated}</span><br><span style="font-size:11px;color:var(--muted);">Updated</span></div>
        <div><span style="font-weight:800;font-size:18px;color:#6b7280;">${data.already_done||0}</span><br><span style="font-size:11px;color:var(--muted);">Already Done</span></div>
        <div><span style="font-weight:800;font-size:18px;color:#d97706;">${data.skipped||0}</span><br><span style="font-size:11px;color:var(--muted);">Skipped</span></div>
      </div>
    </div>`;
}

function csvTo2DArray(text){return text.split(/\r?\n/).map(line=>{const r=[];let cur='',inQ=false;for(let i=0;i<line.length;i++){const c=line[i];if(c==='"')inQ=!inQ;else if(c===','&&!inQ){r.push(cur.trim());cur='';}else cur+=c;}r.push(cur.trim());return r;}).filter(r=>r.some(c=>c!==''));}
function excelTo2DArray(file){return new Promise((res,rej)=>{const reader=new FileReader();reader.onload=e=>{try{const wb=XLSX.read(e.target.result,{type:'array',raw:true});res(XLSX.utils.sheet_to_json(wb.Sheets[wb.SheetNames[0]],{header:1,defval:''}));}catch(err){rej(err);}};reader.onerror=()=>rej(new Error('Read failed'));reader.readAsArrayBuffer(file);});}
function parseAmt(v){if(v==null||v==='')return 0;return parseFloat(String(v).replace(/[, \s]/g,''))||0;}
function esc(s){if(s==null)return '';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
function fmtN(v){return parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});}
function fmtDate(d){if(!d)return '—';try{return new Date(d).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});}catch{return d;}}
function showToast(msg,type){const t=document.getElementById('toast');t.className=type==='err'?'err':type==='info'?'info':'';t.textContent=msg;t.classList.add('show');clearTimeout(t._t);t._t=setTimeout(()=>t.classList.remove('show'),3800);}
document.getElementById('stmtDate').valueAsDate=new Date();

/* ═════════ FROM BANK STATEMENT MODE ═════════ */
const CRS_TYPES=<?= json_encode($CRS_BANK_TYPES) ?>;
function setMode(m){
  document.querySelectorAll('.mode-tab').forEach(b=>b.classList.toggle('on',b.dataset.mode===m));
  document.getElementById('modeStmt').hidden=m!=='statement';
  document.getElementById('modeFile').hidden=m!=='file';
  try{localStorage.setItem('chqReconMode',m);}catch(e){}
}
function toggleCrsSettings(){const b=document.getElementById('crsSettings');if(b)b.hidden=!b.hidden;}
function fillCrsTypes(keep){
  const acc=document.getElementById('crsAcc'),sel=document.getElementById('crsType');if(!acc||!sel)return;
  const o=acc.options[acc.selectedIndex];let types={};try{types=JSON.parse((o&&o.dataset.types)||'{}');}catch(e){}
  const all=Object.keys(CRS_TYPES);Object.keys(types).forEach(bt=>{if(all.indexOf(bt)<0)all.push(bt);});
  sel.innerHTML='<option value="">All types</option>'+all.map(bt=>'<option value="'+bt+'">'+esc(CRS_TYPES[bt]||bt)+(types[bt]?' (latest '+esc(types[bt])+')':' (no statements yet)')+'</option>').join('');
  if(keep&&all.indexOf(keep)>=0)sel.value=keep;
}
async function saveCrsSettings(btn){
  const acc=document.getElementById('crsAcc').value,bt=document.getElementById('crsType').value;
  if(!acc){showToast('Select the bank account.','err');return;}
  btn.disabled=true;
  const fd=new FormData();fd.append('ajax_action','stmt_save_settings');fd.append('account_id',acc);fd.append('bank_type',bt);
  try{const r=await fetch('cheque_reconciliation.php',{method:'POST',body:fd});const d=await r.json();if(!d.success)throw new Error(d.error||'Failed');
    showToast('Settings saved.','ok');setTimeout(()=>location.reload(),600);}
  catch(e){btn.disabled=false;showToast(e.message,'err');}
}
async function stmtLoad(){
  const from=document.getElementById('crsFrom').value,to=document.getElementById('crsTo').value;
  if(!from||!to){showToast('Enter the txn date range.','err');return;}
  const btn=document.getElementById('crsLoadBtn'),old=btn.innerHTML;
  btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Loading…';
  const fd=new FormData();fd.append('ajax_action','stmt_load');fd.append('date_from',from);fd.append('date_to',to);
  try{
    const r=await fetch('cheque_reconciliation.php',{method:'POST',body:fd});const d=await r.json();
    if(!d.success)throw new Error(d.error||'Failed');
    _mode='statement';_stmtRange={from:d.from,to:d.to};_fileName=d.label;_prevRows=d.rows||[];
    const s=d.stats,ban=document.getElementById('stmtInfoBanner');
    ban.innerHTML='<strong><i class="fa-solid fa-building-columns"></i> '+esc(d.label)+'</strong><br>'+
      s.lines+' statement line(s) · <strong>'+s.cheque_lines+'</strong> open cheque line(s) to match'+
      (s.already?' · '+s.already+' already Cheque Reconciled (left out)':'')+
      (s.already_other?' · '+s.already_other+' reconciled by another module (left out)':'')+
      ' · '+s.other+' other line(s) (not cheques)'+
      '<br>Rows already cleared / returned are marked on the statement too when you Apply.';
    ban.hidden=false;
    if(!_prevRows.length){showToast('No open cheque lines in this date range.','info');}
    goStep(3);renderPreview();
  }catch(e){showToast(e.message,'err');}
  finally{btn.disabled=false;btn.innerHTML=old;}
}
let _delUid=0;
function askDeleteBatch(uid,reversed){
  _delUid=uid;
  document.getElementById('crsDelTitle').textContent='Delete batch #'+uid+'?';
  document.getElementById('crsRevertWrap').style.display=reversed?'none':'flex';
  document.getElementById('crsRevNote').hidden=!reversed;
  document.getElementById('crsRevert').checked=!reversed;
  document.getElementById('crsDelModal').hidden=false;
}
function closeDelBatch(){document.getElementById('crsDelModal').hidden=true;}
async function doDeleteBatch(){
  const btn=document.getElementById('crsDelBtn');btn.disabled=true;
  const fd=new FormData();fd.append('ajax_action','stmt_batch_delete');fd.append('upload_id',_delUid);
  if(document.getElementById('crsRevert').checked)fd.append('revert','1');
  try{
    const r=await fetch('cheque_reconciliation.php',{method:'POST',body:fd});const d=await r.json();
    if(!d.success)throw new Error(d.error||'Failed');
    const tr=document.querySelector('tr[data-uid="'+_delUid+'"]');if(tr)tr.remove();
    closeDelBatch();
    showToast('Batch #'+_delUid+' deleted · '+d.lines+' statement line(s) cleared'+(d.reverted?' · '+d.reverted+' cheque(s) reverted':'')+(d.kept?' · '+d.kept+' left (status changed since)':''),'ok');
  }catch(e){showToast(e.message,'err');}
  finally{btn.disabled=false;}
}
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeDelBatch();});
(function(){
  const a=document.getElementById('crsAcc');
  if(a){fillCrsTypes(document.getElementById('crsType').dataset.saved);a.addEventListener('change',()=>fillCrsTypes(document.getElementById('crsType').value));}
  let m='statement';try{m=localStorage.getItem('chqReconMode')||'statement';}catch(e){}
  setMode(m==='file'?'file':'statement');
})();
</script>
<?php include 'footer.php'; ?>