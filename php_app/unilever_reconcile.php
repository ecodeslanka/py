<?php
/**
 * unilever_reconcile.php - FULLY CORRECTED VERSION
 * FIXES:
 * 1. Bank transactions now filter for "UNILEVER" keyword
 * 2. Transaction date properly loads and displays in manual reconcile modal
 * 3. After saving manual reconciliation, status persists on page reload
 * 4. Query checks reconcile_log_id to restore previous manual reconciliations
 * 5. Manual reconcile properly stores all bank details
 * 6. FIX: On page reload, previously reconciled/saved items are restored from DB
 * 7. NEW: A single ledger/claim row can now be settled by MULTIPLE bank
 *    reconciles over time. If a reconcile only partially clears a claim's
 *    total, the outstanding amount is shown as a "Remaining Balance" and the
 *    user can keep adding further bank reconciles against the SAME claim row
 *    until it is fully cleared.
 * 8. SETTINGS: bank account (accounts that have bank statements) + optional
 *    statement type are saved and used for every reconcile.
 * 9. BANK STATEMENT SIDE: every bank row used here gets recon status,
 *    category "Claim Reconcile" and a remark listing ALL claim rows applied
 *    to it (e.g. 3 claim balances → 1 bank row). Status is "reconciled" when
 *    the bank row is fully applied, "partial" while it still has a balance.
 *    Removing a reconcile rebuilds the remark, or clears it when nothing is
 *    left. Bank rows reconciled by other pages are never touched.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
include_once 'config.php';

/* ══════════════════════════════════════════════════════════
   SHARED: Known Unilever bank-statement description sets
   (from uploaded reference image). Used to restrict BOTH the
   manual-reconcile bank list AND the auto (P1) reconcile
   matcher to genuine Unilever counterparty rows only.
══════════════════════════════════════════════════════════ */
$UNILEVER_DESC_SETS = [
    'UNILEVER LANKA CONSUMER LIMITED',
    'UNILEVER SRI LANKA LIMITED',
    'UNILEVER SRI LANKA LIMITED USLL',
    'UNILEVER LANKA CONSUMER LIMITED ULCL',
    'UNILEVER SRI LANKA LTD',
    'UNILEVER LANKA CONSUMER LTD',
    'UNILEVER LIPTON CEYLON LIMITED',
];
$UNILEVER_DESC_SETS = array_values(array_unique($UNILEVER_DESC_SETS));

function unilever_desc_where($conn, $sets, $col = 't.description') {
    $conditions = [];
    foreach ($sets as $pat) {
        $pat_esc = mysqli_real_escape_string($conn, $pat);
        $conditions[] = "UPPER($col) LIKE '%$pat_esc%'";
    }
    return $conditions ? implode(' OR ', $conditions) : '1=0';
}

/* ══════════════════════════════════════════════════════════
   SCHEMA MIGRATION — run once, idempotent
══════════════════════════════════════════════════════════ */
$migrations = [
    "ALTER TABLE bank_statement_transactions ADD COLUMN reconciled TINYINT(1) NOT NULL DEFAULT 0" =>
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bank_statement_transactions' AND COLUMN_NAME='reconciled'",
    "ALTER TABLE bank_statement_transactions ADD COLUMN reconciled_at DATETIME NULL" =>
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bank_statement_transactions' AND COLUMN_NAME='reconciled_at'",
    "ALTER TABLE bank_statement_transactions ADD COLUMN reconciled_description TEXT NULL" =>
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bank_statement_transactions' AND COLUMN_NAME='reconciled_description'",
    "ALTER TABLE bank_statement_transactions ADD COLUMN reconciled_amount DECIMAL(15,2) NULL" =>
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bank_statement_transactions' AND COLUMN_NAME='reconciled_amount'",
    "ALTER TABLE bank_statement_transactions ADD COLUMN reconcile_balance DECIMAL(15,2) NULL" =>
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bank_statement_transactions' AND COLUMN_NAME='reconcile_balance'",
    "CREATE TABLE IF NOT EXISTS unilever_reconcile_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NOT NULL,
        bank_txn_id INT NOT NULL,
        bank_field VARCHAR(20) NOT NULL,
        bank_amount DECIMAL(15,2) NOT NULL,
        claim_total DECIMAL(15,2) NOT NULL,
        is_ai_combo TINYINT(1) DEFAULT 0,
        combo_claim_count INT DEFAULT 1,
        description TEXT,
        reconciled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reconciled_by VARCHAR(100),
        INDEX idx_bank_txn (bank_txn_id),
        INDEX idx_account (account_id)
    )" => "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='unilever_reconcile_log'",
    "CREATE TABLE IF NOT EXISTS unilever_reconcile_claim_lines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        log_id INT NOT NULL,
        banking_date DATE,
        entity VARCHAR(100),
        claim_total DECIMAL(15,2),
        claim_count INT,
        claim_types TEXT,
        customers TEXT,
        cert_amount DECIMAL(15,2),
        bank_ref VARCHAR(200),
        INDEX idx_log (log_id),
        INDEX idx_entity (entity)
    )" => "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='unilever_reconcile_claim_lines'",
];
foreach ([
    'recon_status'   => "VARCHAR(20)  NULL DEFAULT NULL",
    'recon_source'   => "VARCHAR(30)  NULL DEFAULT NULL",
    'recon_category' => "VARCHAR(50)  NULL DEFAULT NULL",
    'recon_ref_id'   => "INT          NULL DEFAULT NULL",
    'recon_remark'   => "TEXT         NULL",
    'recon_by'       => "VARCHAR(100) NULL DEFAULT NULL",
    'recon_at'       => "DATETIME     NULL DEFAULT NULL",
] as $rc_col => $rc_def) {
    $migrations["ALTER TABLE bank_statement_transactions ADD COLUMN `$rc_col` $rc_def"] =
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bank_statement_transactions' AND COLUMN_NAME='$rc_col'";
}
$migrations["CREATE TABLE IF NOT EXISTS unilever_recon_settings (
        id INT NOT NULL PRIMARY KEY,
        bank_account_id INT NULL,
        bank_type VARCHAR(10) NULL,
        updated_by VARCHAR(100) NULL,
        updated_at DATETIME NULL
    )"] = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='unilever_recon_settings'";

foreach ($migrations as $alter => $check) {
    $cr = mysqli_query($conn, $check);
    if (!$cr || !mysqli_num_rows($cr)) {
        mysqli_query($conn, $alter);
    }
}

/* ══════════════════════════════════════════════════════════
   SETTINGS + BANK STATEMENT RECON FIELDS
══════════════════════════════════════════════════════════ */
define('ULR_SOURCE',   'unilever_recon');
define('ULR_CATEGORY', 'Claim Reconcile');
$ULR_BANK_TYPES = ['BOC' => 'BOC', 'NDB' => 'NDB', 'SAMPATH' => 'Sampath'];

/* bank accounts that have bank statements uploaded, with their statement types */
function ulr_accounts($conn) {
    $acc = [];
    $r = mysqli_query($conn, "SELECT account_id, UPPER(bank_type) AS bt, MAX(statement_date) AS last_stmt, COUNT(*) AS uploads
                                FROM bank_statement_uploads GROUP BY account_id, UPPER(bank_type)");
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $id = (int)$row['account_id'];
        if ($id <= 0) continue;
        if (!isset($acc[$id])) $acc[$id] = ['id' => $id, 'label' => 'Account #' . $id, 'types' => [], 'last' => '', 'uploads' => 0];
        $acc[$id]['types'][$row['bt']] = $row['last_stmt'];
        $acc[$id]['uploads'] += (int)$row['uploads'];
        if ($row['last_stmt'] > $acc[$id]['last']) $acc[$id]['last'] = $row['last_stmt'];
    }
    if ($acc) {
        $r = mysqli_query($conn, "SELECT cba.id, cba.account_no, cba.account_name, b.bank_name, c.company_code
                                    FROM company_bank_accounts cba
                               LEFT JOIN banks b ON cba.bank_code = b.bank_code
                               LEFT JOIN companies c ON cba.company_id = c.id
                                   WHERE cba.id IN (" . implode(',', array_map('intval', array_keys($acc))) . ")");
        while ($r && ($row = mysqli_fetch_assoc($r))) {
            $acc[(int)$row['id']]['label'] = '[' . ($row['company_code'] ?? '') . '] ' . ($row['bank_name'] ?? '') . ' — '
                                           . ($row['account_name'] ?? '') . ' (' . ($row['account_no'] ?? '') . ')';
        }
    }
    uasort($acc, function ($x, $y) { return strcasecmp($x['label'], $y['label']); });
    return $acc;
}

/* saved settings, checked against the accounts that really have statements */
function ulr_cfg($conn, $accounts = null) {
    if ($accounts === null) $accounts = ulr_accounts($conn);
    $c = ['account' => 0, 'type' => '', 'saved_account' => 0, 'saved_type' => '', 'updated_by' => '', 'updated_at' => ''];
    $r = mysqli_query($conn, "SELECT * FROM unilever_recon_settings WHERE id = 1 LIMIT 1");
    if ($r && ($row = mysqli_fetch_assoc($r))) {
        $c['saved_account'] = (int)$row['bank_account_id'];
        $c['saved_type']    = strtoupper((string)$row['bank_type']);
        $c['updated_by']    = (string)$row['updated_by'];
        $c['updated_at']    = (string)$row['updated_at'];
    }
    if (isset($accounts[$c['saved_account']])) {
        $c['account'] = $c['saved_account'];
        if ($c['saved_type'] !== '' && isset($accounts[$c['account']]['types'][$c['saved_type']])) $c['type'] = $c['saved_type'];
    }
    return $c;
}

/* SQL: statement type filter + leave out rows reconciled by other pages */
function ulr_bank_scope_sql($conn, $type) {
    $sql = " AND (t.recon_source IS NULL OR t.recon_source = '' OR t.recon_source = '" . ULR_SOURCE . "')";
    if ($type !== '') $sql .= " AND UPPER(u.bank_type) = '" . mysqli_real_escape_string($conn, $type) . "'";
    return $sql;
}

/* bank row must belong to the Settings account / type and not be used by another page */
function ulr_check_bank_txn($conn, $bank_txn_id, $cfg) {
    $bank_txn_id = (int)$bank_txn_id;
    $r = mysqli_query($conn, "SELECT t.id FROM bank_statement_transactions t
                                JOIN bank_statement_uploads u ON u.id = t.upload_id
                               WHERE t.id = $bank_txn_id AND u.account_id = " . (int)$cfg['account'] . ulr_bank_scope_sql($conn, $cfg['type']) . " LIMIT 1");
    return $r && mysqli_num_rows($r) > 0;
}

/**
 * Rebuild recon status / category / remark of ONE bank row from every
 * reconcile saved against it. Several claim rows can share one bank row
 * (e.g. 3 claim balances paid in one bank credit) — all are listed.
 */
function ulr_refresh_bank_recon($conn, $bank_txn_id) {
    $bank_txn_id = (int)$bank_txn_id;
    if (!$bank_txn_id) return;
    $logs = []; $lines = [];
    $r = mysqli_query($conn, "SELECT l.id, l.bank_field, l.claim_total AS applied, l.is_ai_combo, l.description,
                                     l.reconciled_by, l.reconciled_at,
                                     cl.banking_date, cl.entity, cl.cert_amount
                                FROM unilever_reconcile_log l
                           LEFT JOIN unilever_reconcile_claim_lines cl ON cl.log_id = l.id
                               WHERE l.bank_txn_id = $bank_txn_id
                               ORDER BY l.id, cl.id");
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $lid = (int)$row['id'];
        if (!isset($logs[$lid])) $logs[$lid] = $row;
        if ($row['entity'] !== null) {
            $lines[] = date('Y-m-d', strtotime($row['banking_date'])) . ' ' . $row['entity']
                     . ' Rs ' . number_format((float)$row['cert_amount'], 2)
                     . ($row['is_ai_combo'] ? ' (AI combo)' : '');
        }
    }

    if (!$logs) {   /* nothing left against this bank row → clear, only if this page set it */
        mysqli_query($conn, "UPDATE bank_statement_transactions
                                SET recon_status = NULL, recon_source = NULL, recon_category = NULL, recon_ref_id = NULL,
                                    recon_remark = NULL, recon_by = NULL, recon_at = NULL
                              WHERE id = $bank_txn_id AND recon_source = '" . ULR_SOURCE . "'");
        return;
    }

    $bq = mysqli_query($conn, "SELECT credit, debit, balance FROM bank_statement_transactions WHERE id = $bank_txn_id");
    $bk = $bq ? mysqli_fetch_assoc($bq) : null;
    if (!$bk) return;
    $first = reset($logs);
    $last  = end($logs);
    $field = in_array($first['bank_field'], ['credit', 'debit', 'balance'], true) ? $first['bank_field'] : 'credit';
    $bank_amt = (float)$bk[$field];
    $applied  = 0.0;
    foreach ($logs as $lg) $applied += (float)$lg['applied'];
    $applied  = round($applied, 2);
    $balance  = round($bank_amt - $applied, 2);
    $status   = $balance <= 0.009 ? 'reconciled' : 'partial';

    $shown = array_slice($lines, 0, 12);
    $more  = count($lines) - count($shown);
    $notes = [];
    foreach ($logs as $lg) if (trim((string)$lg['description']) !== '' && strpos($lg['description'], 'Auto P1') !== 0) $notes[] = trim($lg['description']);
    $notes = array_values(array_unique($notes));

    $remark = 'Unilever Claim Recon | ' . count($lines) . ' claim row(s): ' . implode('; ', $shown)
            . ($more > 0 ? '; +' . $more . ' more' : '')
            . ' | Applied Rs ' . number_format($applied, 2) . ' of Rs ' . number_format($bank_amt, 2) . ' (' . $field . ')'
            . ($status === 'partial' ? ' | Balance Rs ' . number_format(max(0, $balance), 2) : '')
            . ' | Log #' . implode(', #', array_keys($logs))
            . ($notes ? ' | Note: ' . implode(' / ', array_slice($notes, 0, 3)) : '')
            . ' | last by ' . ($last['reconciled_by'] ?? '') . ' on ' . ($last['reconciled_at'] ?? '');

    $src = ULR_SOURCE; $cat = ULR_CATEGORY;
    reset($logs); $ref = (int)key($logs);        /* first reconcile log on this bank row */
    $by = (string)($last['reconciled_by'] ?? ''); $at = (string)($last['reconciled_at'] ?? date('Y-m-d H:i:s'));
    $st = mysqli_prepare($conn, "UPDATE bank_statement_transactions
                                    SET recon_status = ?, recon_source = ?, recon_category = ?, recon_ref_id = ?,
                                        recon_remark = ?, recon_by = ?, recon_at = ?
                                  WHERE id = ? AND (recon_source IS NULL OR recon_source = '' OR recon_source = ?)");
    mysqli_stmt_bind_param($st, 'sssisssis', $status, $src, $cat, $ref, $remark, $by, $at, $bank_txn_id, $src);
    mysqli_stmt_execute($st);
    mysqli_stmt_close($st);
}

/* ══════════════════════════════════════════════════════════
   AJAX: settings_get / settings_save
══════════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'settings_get') {
    header('Content-Type: application/json');
    ob_start();
    $accounts = ulr_accounts($conn);
    $cfg = ulr_cfg($conn, $accounts);
    ob_end_clean();
    echo json_encode(['ok' => true, 'accounts' => array_values($accounts), 'settings' => $cfg, 'types' => $ULR_BANK_TYPES]);
    exit;
}
if (isset($_GET['ajax']) && $_GET['ajax'] === 'settings_save') {
    header('Content-Type: application/json');
    ob_start();
    $body = json_decode(file_get_contents('php://input'), true);
    $accounts = ulr_accounts($conn);
    $acc = intval($body['account_id'] ?? 0);
    $bt  = strtoupper(trim($body['bank_type'] ?? ''));
    if (!isset($accounts[$acc])) { ob_end_clean(); echo json_encode(['ok'=>false,'msg'=>'Select a bank account that has bank statements uploaded.']); exit; }
    if ($bt !== '' && !isset($accounts[$acc]['types'][$bt])) { ob_end_clean(); echo json_encode(['ok'=>false,'msg'=>'No '.$bt.' statements are uploaded for this account.']); exit; }
    $btDb = $bt !== '' ? $bt : null; $user = $_SESSION['username'] ?? 'system'; $now = date('Y-m-d H:i:s');
    $st = mysqli_prepare($conn, "INSERT INTO unilever_recon_settings (id, bank_account_id, bank_type, updated_by, updated_at) VALUES (1, ?, ?, ?, ?)
                                 ON DUPLICATE KEY UPDATE bank_account_id = VALUES(bank_account_id), bank_type = VALUES(bank_type),
                                     updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)");
    mysqli_stmt_bind_param($st, 'isss', $acc, $btDb, $user, $now);
    $ok = mysqli_stmt_execute($st);
    ob_end_clean();
    echo json_encode($ok ? ['ok'=>true, 'settings'=>ulr_cfg($conn, $accounts)] : ['ok'=>false,'msg'=>'Could not save settings.']);
    exit;
}

/* ══════════════════════════════════════════════════════════
   AJAX: reconcile  (Pass 1 — exact 1-to-1 match)
   FIX: Also restores previously saved/manual reconciliations
   NEW: A claim/ledger row may now have MULTIPLE reconcile log
        entries against it (partial payments). We aggregate all
        of them, work out how much is still outstanding, and try
        to auto-match the OUTSTANDING amount rather than the full
        claim total.
══════════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'reconcile') {
    header('Content-Type: application/json');
    ob_start();
    $ulr_cfg    = ulr_cfg($conn);
    $account_id = (int)$ulr_cfg['account'];
    if (!$account_id) { ob_end_clean(); echo json_encode(['ok'=>false,'msg'=>'Set the bank account in Settings first']); exit; }
    $scope_sql  = ulr_bank_scope_sql($conn, $ulr_cfg['type']);

    /* --- Step 1: Load all claim groups --- */
    $q = mysqli_query($conn,
        "SELECT DATE(banking_date) AS bdate, entity,
            SUM(total_amount) AS claim_total, COUNT(*) AS claim_count,
            GROUP_CONCAT(DISTINCT claim_type ORDER BY claim_type SEPARATOR ', ') AS claim_types,
            GROUP_CONCAT(DISTINCT customer_code ORDER BY customer_code SEPARATOR ', ') AS customers
         FROM claim_cert_items
         WHERE banking_date IS NOT NULL AND banking_date <> ''
         GROUP BY DATE(banking_date), entity
         ORDER BY bdate ASC, entity ASC");
    $claims = [];
    while ($r = mysqli_fetch_assoc($q)) $claims[] = $r;

    if (!$claims) { ob_end_clean(); echo json_encode(['ok'=>true,'claims'=>[],'bank_total'=>0,'bank_rows'=>[]]); exit; }

    /* --- Step 2: Load ALL existing reconcile log entries for this account --- */
    /* Key: "DATE_ENTITY" => ARRAY of log rows + bank txn info.
       IMPORTANT: a claim key can now map to MULTIPLE entries
       (multiple partial bank reconciles against the same ledger row). */
    $existing_recon = [];
    $qlog = mysqli_query($conn,
        "SELECT l.id AS log_id, l.bank_txn_id, l.bank_field, l.bank_amount,
                l.claim_total AS log_claim_total, l.description AS log_desc,
                l.is_ai_combo, l.reconciled_at,
                cl.banking_date, cl.entity, cl.bank_ref, cl.cert_amount AS applied_amount,
                t.transaction_date, t.credit, t.debit, t.balance,
                t.description AS bank_desc, t.reference, t.cheque_no,
                t.reconcile_balance, t.reconciled AS bank_reconciled
         FROM unilever_reconcile_log l
         JOIN unilever_reconcile_claim_lines cl ON cl.log_id = l.id
         JOIN bank_statement_transactions t ON t.id = l.bank_txn_id
         WHERE l.account_id = $account_id
         ORDER BY l.reconciled_at ASC, l.id ASC");
    while ($lr = mysqli_fetch_assoc($qlog)) {
        $key = date('Y-m-d', strtotime($lr['banking_date'])) . '___' . $lr['entity'];
        $existing_recon[$key][] = $lr; /* APPEND — do not overwrite, a key can have many entries */
    }

    /* --- Step 3: Load all bank rows for this account --- */
    $q2 = mysqli_query($conn,
        "SELECT t.id, t.transaction_date, t.value_date,
            t.description, t.credit, t.debit, t.balance,
            t.reference, t.branch_code, t.cheque_no, t.serial_no,
            t.reconciled, t.reconciled_at, t.reconciled_amount, t.reconcile_balance
         FROM bank_statement_transactions t
         JOIN bank_statement_uploads u ON t.upload_id = u.id
         WHERE u.account_id = $account_id $scope_sql
         ORDER BY t.transaction_date ASC, t.id ASC");
    $bank_rows = [];
    while ($r = mysqli_fetch_assoc($q2)) $bank_rows[] = $r;

    /* --- Step 4: Build lookup maps for P1 matching (skip already-logged bank txns) --- */
    /* Collect bank_txn_ids already used in ANY existing reconciliation entry */
    $already_used_bank_ids = [];
    foreach ($existing_recon as $key => $entries) {
        foreach ($entries as $er) {
            $already_used_bank_ids[$er['bank_txn_id']] = true;
        }
    }

    /* Auto (P1) reconcile: restricted to Unilever description-set rows only,
       but checks BOTH credit and debit amounts (some settlements post as
       credit, some as debit — restricting to one side was under-matching). */
    $bank_by_credit = [];
    $bank_by_debit  = [];
    foreach ($bank_rows as $br) {
        /* Skip fully reconciled OR already assigned to a log entry */
        if ($br['reconciled'] && !isset($already_used_bank_ids[$br['id']])) continue;
        if (isset($already_used_bank_ids[$br['id']])) continue;

        $desc_upper = strtoupper($br['description'] ?? '');
        $is_unilever = false;
        foreach ($UNILEVER_DESC_SETS as $pat) {
            if (strpos($desc_upper, strtoupper($pat)) !== false) { $is_unilever = true; break; }
        }
        if (!$is_unilever) continue;

        if ((float)$br['credit'] > 0) {
            $c = number_format((float)$br['credit'], 2, '.', '');
            $bank_by_credit[$c][] = $br;
        }
        if ((float)$br['debit'] > 0) {
            $d = number_format((float)$br['debit'], 2, '.', '');
            $bank_by_debit[$d][] = $br;
        }
    }

    /* --- Step 5: Match claims — restore saved ones (may be several per claim),
                    then P1 match whatever is still OUTSTANDING --- */
    $used_bank_ids = array_keys($already_used_bank_ids);

    foreach ($claims as &$claim) {
        $claim_total_f = (float)$claim['claim_total'];
        $key = $claim['bdate'] . '___' . $claim['entity'];

        /* Default */
        $claim['recon_entries']     = [];   /* every bank reconcile applied to this ledger row */
        $claim['reconciled_total']  = 0.0;  /* sum of amounts applied so far */
        $claim['remaining_balance'] = round($claim_total_f, 2);
        $claim['match_status']      = 'unmatched';

        /* Restore any previously-saved reconciliations for this claim key */
        if (isset($existing_recon[$key])) {
            foreach ($existing_recon[$key] as $er) {
                $applied = (float)($er['applied_amount'] ?? $er['log_claim_total'] ?? 0);
                $claim['reconciled_total'] += $applied;
                $claim['recon_entries'][] = [
                    'log_id'           => (int)$er['log_id'],
                    'bank_txn_id'      => (int)$er['bank_txn_id'],
                    'bank_field'       => $er['bank_field'],
                    'bank_amount'      => (float)$er['bank_amount'],
                    'applied_amount'   => $applied,
                    'bank_ref'         => $er['bank_ref'] ?? '',
                    'log_desc'         => $er['log_desc'] ?? '',
                    'reconciled_at'    => $er['reconciled_at'] ?? '',
                    'bank_txn_date'    => $er['transaction_date'] ?? '',
                    'bank_desc'        => $er['bank_desc'] ?? '',
                    'bank_credit'      => (float)($er['credit'] ?? 0),
                    'bank_debit'       => (float)($er['debit'] ?? 0),
                    'bank_balance'     => (float)($er['balance'] ?? 0),
                    'bank_remaining'   => (float)($er['reconcile_balance'] ?? 0),
                    'fully_reconciled' => (bool)($er['bank_reconciled'] ?? false),
                    'is_ai_combo'      => (bool)($er['is_ai_combo'] ?? false),
                    'is_new_match'     => false,
                ];
            }
            $claim['remaining_balance'] = round($claim_total_f - $claim['reconciled_total'], 2);
            if ($claim['remaining_balance'] < 0) $claim['remaining_balance'] = 0.0;
        }

        /* P1: exact match on the OUTSTANDING amount only — Unilever description
           sets only, either credit or debit. This lets a partially-reconciled
           ledger row keep picking up further exact-match bank rows for
           whatever is still owed. */
        $outstanding = $claim['remaining_balance'];
        if ($outstanding > 0.009) {
            $ot = number_format($outstanding, 2, '.', '');
            $try = [
                'credit' => $bank_by_credit[$ot] ?? [],
                'debit'  => $bank_by_debit[$ot]  ?? [],
            ];
            foreach ($try as $matched_field => $candidates) {
            foreach ($candidates as $br) {
                if (in_array($br['id'], $used_bank_ids)) continue;
                $used_bank_ids[] = $br['id'];

                /* Auto-save this match to the DB immediately */
                $bank_txn_id  = (int)$br['id'];
                $applied_amt  = $outstanding;
                $bank_field   = $matched_field;
                $field_amount = (float)$br[$bank_field];
                $recon_by     = mysqli_real_escape_string($conn, ($_SESSION['username'] ?? 'system') . ' (auto)');
                $auto_desc    = 'Auto P1 reconcile (' . $bank_field . ' match)';

                mysqli_query($conn,
                    "INSERT INTO unilever_reconcile_log
                        (account_id, bank_txn_id, bank_field, bank_amount, claim_total,
                         is_ai_combo, combo_claim_count, description, reconciled_by)
                     VALUES
                        ($account_id, $bank_txn_id, '$bank_field', $field_amount, $applied_amt,
                         0, 1, '$auto_desc', '$recon_by')"
                );
                $log_id = mysqli_insert_id($conn);

                $bdate_e  = mysqli_real_escape_string($conn, $claim['bdate']);
                $entity_e = mysqli_real_escape_string($conn, $claim['entity']);
                $ctypes_e = mysqli_real_escape_string($conn, $claim['claim_types'] ?? '');
                $custs_e  = mysqli_real_escape_string($conn, $claim['customers'] ?? '');
                $bref_e   = mysqli_real_escape_string($conn, $br['reference'] ?: ($br['cheque_no'] ?: ''));

                mysqli_query($conn,
                    "INSERT INTO unilever_reconcile_claim_lines
                        (log_id, banking_date, entity, claim_total, claim_count, claim_types, customers, cert_amount, bank_ref)
                     VALUES
                        ($log_id, '$bdate_e', '$entity_e', $claim_total_f, " . (int)$claim['claim_count'] . ",
                         '$ctypes_e', '$custs_e', $applied_amt, '$bref_e')"
                );

                $col_check = mysqli_query($conn,
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='claim_cert_items'
                     AND COLUMN_NAME IN ('bank_ref','reconcile_log_id','reconciled_at')");
                $existing_cols = [];
                while ($cc = mysqli_fetch_assoc($col_check)) $existing_cols[] = $cc['COLUMN_NAME'];
                $set_parts = [];
                if (in_array('bank_ref',         $existing_cols)) $set_parts[] = "bank_ref='$bref_e'";
                if (in_array('reconcile_log_id', $existing_cols)) $set_parts[] = "reconcile_log_id=$log_id";
                if (in_array('reconciled_at',    $existing_cols)) $set_parts[] = "reconciled_at=NOW()";
                if ($set_parts) {
                    mysqli_query($conn,
                        "UPDATE claim_cert_items SET " . implode(',', $set_parts) .
                        " WHERE DATE(banking_date)='$bdate_e' AND entity='$entity_e'"
                    );
                }

                $prev_bal   = $br['reconcile_balance'] !== null ? (float)$br['reconcile_balance'] : $field_amount;
                $new_bal    = round($prev_bal - $applied_amt, 2);
                $fully_done = ($new_bal <= 0);
                $bal_val    = max(0, $new_bal);
                mysqli_query($conn,
                    "UPDATE bank_statement_transactions
                     SET reconciled=" . ($fully_done ? 1 : 0) . ",
                         reconciled_at=NOW(),
                         reconciled_amount=COALESCE(reconciled_amount,0)+$applied_amt,
                         reconcile_balance=$bal_val,
                         reconciled_description='$auto_desc'
                     WHERE id=$bank_txn_id"
                );
                ulr_refresh_bank_recon($conn, $bank_txn_id);

                /* Append this as another entry on the ledger row's reconcile list */
                $claim['recon_entries'][] = [
                    'log_id'           => (int)$log_id,
                    'bank_txn_id'      => $bank_txn_id,
                    'bank_field'       => $bank_field,
                    'bank_amount'      => $field_amount,
                    'applied_amount'   => $applied_amt,
                    'bank_ref'         => $bref_e,
                    'log_desc'         => $auto_desc,
                    'reconciled_at'    => date('Y-m-d H:i:s'),
                    'bank_txn_date'    => $br['transaction_date'] ?? '',
                    'bank_desc'        => $br['description'] ?? '',
                    'bank_credit'      => (float)($br['credit'] ?? 0),
                    'bank_debit'       => (float)($br['debit'] ?? 0),
                    'bank_balance'     => (float)($br['balance'] ?? 0),
                    'bank_remaining'   => $bal_val,
                    'fully_reconciled' => $fully_done,
                    'is_ai_combo'      => false,
                    'is_new_match'     => true,
                ];
                $claim['reconciled_total']  += $applied_amt;
                $claim['remaining_balance']  = 0.0; /* exact match closes the outstanding amount */
                continue 3;
            }
            }
        }

        /* Final status for this ledger/claim row */
        if (empty($claim['recon_entries'])) {
            $claim['match_status'] = 'unmatched';
        } elseif ($claim['remaining_balance'] <= 0.009) {
            $claim['match_status'] = 'manual-matched'; /* fully cleared — 1 or more bank rows applied */
        } else {
            $claim['match_status'] = 'partial-matched'; /* 1+ bank rows applied, still an outstanding balance */
        }
    }
    unset($claim);

    ob_end_clean();
    echo json_encode([
        'ok'            => true,
        'claims'        => $claims,
        'bank_total'    => count($bank_rows),
        'bank_rows'     => $bank_rows,
        'used_bank_ids' => $used_bank_ids,
    ]);
    exit;
}

/* ══════════════════════════════════════════════════════════
   AJAX: save_reconcile — save matched rows to DB
   (Used only by the AI-combo pass, which does not write to the
   database until the user explicitly confirms & saves.)
══════════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'save_reconcile') {
    header('Content-Type: application/json');
    ob_start();
    $body = json_decode(file_get_contents('php://input'), true);
    $ulr_cfg     = ulr_cfg($conn);
    $account_id  = (int)$ulr_cfg['account'];
    $reconcile   = $body['reconcile'] ?? [];
    $description = mysqli_real_escape_string($conn, trim($body['description'] ?? ''));
    $user        = $_SESSION['username'] ?? 'system';

    if (!$account_id || !$reconcile) {
        ob_end_clean();
        echo json_encode(['ok'=>false,'msg'=>'Missing data']);
        exit;
    }

    mysqli_begin_transaction($conn);
    $saved = 0;
    try {
        foreach ($reconcile as $item) {
            $bank_txn_id  = intval($item['bank_txn_id']);
            $bank_field   = mysqli_real_escape_string($conn, $item['bank_field'] ?? 'credit');
            $bank_amount  = floatval($item['bank_amount'] ?? 0);
            $claim_total  = floatval($item['claim_total'] ?? 0);
            $is_ai        = intval($item['is_ai_combo'] ?? 0);
            $combo_count  = intval($item['combo_claim_count'] ?? 1);
            $lines        = $item['lines'] ?? [];

            if (!ulr_check_bank_txn($conn, $bank_txn_id, $ulr_cfg)) {
                throw new Exception('Bank row #' . $bank_txn_id . ' is not in the Settings account / statement type, or is reconciled by another page.');
            }
            $q_bk = mysqli_query($conn, "SELECT credit,debit,balance,reconcile_balance FROM bank_statement_transactions WHERE id=$bank_txn_id");
            $bk   = mysqli_fetch_assoc($q_bk);
            $field_val  = floatval($bk[$bank_field] ?? 0);
            $prev_bal   = $bk['reconcile_balance'] !== null ? floatval($bk['reconcile_balance']) : $field_val;
            $new_bal    = round($prev_bal - $claim_total, 2);
            $fully_done = ($new_bal <= 0);

            $desc_esc = mysqli_real_escape_string($conn, $description);
            $recon_by = mysqli_real_escape_string($conn, $user);
            mysqli_query($conn,
                "INSERT INTO unilever_reconcile_log
                    (account_id, bank_txn_id, bank_field, bank_amount, claim_total,
                     is_ai_combo, combo_claim_count, description, reconciled_by)
                 VALUES
                    ($account_id, $bank_txn_id, '$bank_field', $bank_amount, $claim_total,
                     $is_ai, $combo_count, '$desc_esc', '$recon_by')"
            );
            $log_id = mysqli_insert_id($conn);

            foreach ($lines as $ln) {
                $bdate   = mysqli_real_escape_string($conn, $ln['bdate'] ?? '');
                $entity  = mysqli_real_escape_string($conn, $ln['entity'] ?? '');
                $ctot    = floatval($ln['claim_total'] ?? 0);
                $ccount  = intval($ln['claim_count'] ?? 0);
                $ctypes  = mysqli_real_escape_string($conn, $ln['claim_types'] ?? '');
                $custs   = mysqli_real_escape_string($conn, $ln['customers'] ?? '');
                $cert_amt= floatval($ln['cert_amount'] ?? $ctot);
                $bref    = mysqli_real_escape_string($conn, $ln['bank_ref'] ?? '');

                mysqli_query($conn,
                    "INSERT INTO unilever_reconcile_claim_lines
                        (log_id, banking_date, entity, claim_total, claim_count, claim_types, customers, cert_amount, bank_ref)
                     VALUES
                        ($log_id, '$bdate', '$entity', $ctot, $ccount, '$ctypes', '$custs', $cert_amt, '$bref')"
                );

                if ($bdate && $entity) {
                    $bdate_e  = mysqli_real_escape_string($conn, $bdate);
                    $entity_e = mysqli_real_escape_string($conn, $entity);
                    $ref_esc  = mysqli_real_escape_string($conn, $ln['bank_ref'] ?? '');
                    $col_check = mysqli_query($conn,
                        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='claim_cert_items'
                         AND COLUMN_NAME IN ('bank_ref','reconcile_log_id','reconciled_at')");
                    $existing_cols = [];
                    while($cc = mysqli_fetch_assoc($col_check)) $existing_cols[] = $cc['COLUMN_NAME'];
                    $set_parts = [];
                    if (in_array('bank_ref',         $existing_cols)) $set_parts[] = "bank_ref='$ref_esc'";
                    if (in_array('reconcile_log_id', $existing_cols)) $set_parts[] = "reconcile_log_id=$log_id";
                    if (in_array('reconciled_at',    $existing_cols)) $set_parts[] = "reconciled_at=NOW()";
                    if ($set_parts) {
                        mysqli_query($conn,
                            "UPDATE claim_cert_items SET " . implode(',',$set_parts) .
                            " WHERE DATE(banking_date)='$bdate_e' AND entity='$entity_e'"
                        );
                    }
                }
            }

            $recon_flag = $fully_done ? 1 : 0;
            $bal_val    = max(0, $new_bal);
            mysqli_query($conn,
                "UPDATE bank_statement_transactions
                 SET reconciled=$recon_flag,
                     reconciled_at=NOW(),
                     reconciled_amount=COALESCE(reconciled_amount,0)+$claim_total,
                     reconcile_balance=$bal_val,
                     reconciled_description=" . ($desc_esc ? "'$desc_esc'" : "reconciled_description") . "
                 WHERE id=$bank_txn_id"
            );
            ulr_refresh_bank_recon($conn, $bank_txn_id);
            $saved++;
        }
        mysqli_commit($conn);
        ob_end_clean();
        echo json_encode(['ok'=>true,'saved'=>$saved]);
    } catch(Exception $e) {
        mysqli_rollback($conn);
        ob_end_clean();
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

/* ══════════════════════════════════════════════════════════
   AJAX: bank_transactions — for manual reconcile modal
   Filters for Unilever entity description sets (see image-based
   list below). Restricting to these known description variants
   (instead of a broad "%UNILEVER%" match) so only genuine
   Unilever counterparty rows are offered for manual reconcile.
══════════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'bank_transactions') {
    header('Content-Type: application/json');
    ob_start();
    $ulr_cfg    = ulr_cfg($conn);
    $account_id = (int)$ulr_cfg['account'];
    $search     = mysqli_real_escape_string($conn, trim($_GET['search'] ?? ''));
    $amt        = floatval($_GET['amount'] ?? 0);

    if (!$account_id) { ob_end_clean(); echo json_encode(['ok'=>false,'msg'=>'No account']); exit; }

    /* Known Unilever description sets (from uploaded reference image) */
    global $UNILEVER_DESC_SETS;
    $desc_where = unilever_desc_where($conn, $UNILEVER_DESC_SETS, 't.description');

    $where = "u.account_id=$account_id " . ulr_bank_scope_sql($conn, $ulr_cfg['type']) . " AND (t.reconciled=0 OR t.reconcile_balance>0)
              AND (t.credit>0)
              AND ($desc_where)";

    if ($search) {
        $s = '%' . $search . '%';
        $where .= " AND (t.description LIKE '$s' OR t.reference LIKE '$s' OR t.cheque_no LIKE '$s' OR CAST(t.credit AS CHAR) LIKE '$s' OR CAST(t.debit AS CHAR) LIKE '$s')";
    }
    if ($amt > 0) {
        $alo = $amt * 0.999;
        $ahi = $amt * 1.001;
        $where .= " AND (t.credit BETWEEN $alo AND $ahi OR t.debit BETWEEN $alo AND $ahi OR t.balance BETWEEN $alo AND $ahi OR (t.reconcile_balance IS NOT NULL AND t.reconcile_balance BETWEEN $alo AND $ahi))";
    }

    $q = mysqli_query($conn,
        "SELECT t.id, t.transaction_date, t.description, t.credit, t.debit, t.balance,
                t.reference, t.cheque_no, t.serial_no,
                t.reconcile_balance, t.reconciled_amount
         FROM bank_statement_transactions t
         JOIN bank_statement_uploads u ON t.upload_id=u.id
         WHERE $where
         ORDER BY t.transaction_date DESC
         LIMIT 100");
    $rows = [];
    while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;
    ob_end_clean();
    echo json_encode(['ok'=>true,'rows'=>$rows]);
    exit;
}

/* ══════════════════════════════════════════════════════════
   AJAX: manual_reconcile — save a manual reconcile
   NOTE: cert_amount sent from the client may be LESS than the
   claim's full total (a partial payment) or may be the full
   remaining balance — either way it is stored as-is, and further
   manual reconciles can be added later against the same ledger
   row (banking_date + entity) to clear whatever is still owed.
══════════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'manual_reconcile') {
    header('Content-Type: application/json');
    ob_start();
    $body        = json_decode(file_get_contents('php://input'), true);
    $ulr_cfg     = ulr_cfg($conn);
    $account_id  = (int)$ulr_cfg['account'];
    $bank_txn_id = intval($body['bank_txn_id'] ?? 0);
    $bank_field  = mysqli_real_escape_string($conn, $body['bank_field'] ?? 'credit');
    $cert_amount = floatval($body['cert_amount'] ?? 0);
    $description = mysqli_real_escape_string($conn, trim($body['description'] ?? ''));
    $line        = $body['claim_line'] ?? [];
    $user        = $_SESSION['username'] ?? 'system';

    if (!$bank_txn_id || !$cert_amount) {
        ob_end_clean();
        echo json_encode(['ok'=>false,'msg'=>'Missing bank transaction or amount']);
        exit;
    }
    if (!ulr_check_bank_txn($conn, $bank_txn_id, $ulr_cfg)) {
        ob_end_clean();
        echo json_encode(['ok'=>false,'msg'=>'This bank row is not in the Settings account / statement type, or is reconciled by another page.']);
        exit;
    }

    $q_bk = mysqli_query($conn, "SELECT credit,debit,balance,reconcile_balance FROM bank_statement_transactions WHERE id=$bank_txn_id");
    $bk   = mysqli_fetch_assoc($q_bk);
    $field_val = floatval($bk[$bank_field] ?? $bk['credit'] ?? 0);
    $prev_bal  = $bk['reconcile_balance'] !== null ? floatval($bk['reconcile_balance']) : $field_val;
    $new_bal   = round($prev_bal - $cert_amount, 2);
    $fully_done= ($new_bal <= 0);

    mysqli_begin_transaction($conn);
    try {
        $recon_by = mysqli_real_escape_string($conn, $user);
        mysqli_query($conn,
            "INSERT INTO unilever_reconcile_log
                (account_id, bank_txn_id, bank_field, bank_amount, claim_total,
                 is_ai_combo, combo_claim_count, description, reconciled_by)
             VALUES
                ($account_id, $bank_txn_id, '$bank_field', $field_val, $cert_amount,
                 0, 1, '$description', '$recon_by')"
        );
        $log_id = mysqli_insert_id($conn);

        if ($line) {
            $bdate  = mysqli_real_escape_string($conn, $line['bdate']  ?? '');
            $entity = mysqli_real_escape_string($conn, $line['entity'] ?? '');
            $ctot   = floatval($line['claim_total']  ?? 0);
            $ccount = intval($line['claim_count']    ?? 0);
            $ctypes = mysqli_real_escape_string($conn, $line['claim_types'] ?? '');
            $custs  = mysqli_real_escape_string($conn, $line['customers']   ?? '');
            $bref   = mysqli_real_escape_string($conn, $body['bank_ref']    ?? '');

            mysqli_query($conn,
                "INSERT INTO unilever_reconcile_claim_lines
                    (log_id, banking_date, entity, claim_total, claim_count, claim_types, customers, cert_amount, bank_ref)
                 VALUES
                    ($log_id, '$bdate', '$entity', $ctot, $ccount, '$ctypes', '$custs', $cert_amount, '$bref')"
            );

            if ($bdate && $entity) {
                $col_check = mysqli_query($conn,
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='claim_cert_items'
                     AND COLUMN_NAME IN ('bank_ref','reconcile_log_id','reconciled_at')");
                $existing_cols = [];
                while($cc = mysqli_fetch_assoc($col_check)) $existing_cols[] = $cc['COLUMN_NAME'];
                $set_parts = [];
                if (in_array('bank_ref',         $existing_cols)) $set_parts[] = "bank_ref='$bref'";
                if (in_array('reconcile_log_id', $existing_cols)) $set_parts[] = "reconcile_log_id=$log_id";
                if (in_array('reconciled_at',    $existing_cols)) $set_parts[] = "reconciled_at=NOW()";
                if ($set_parts) {
                    mysqli_query($conn,
                        "UPDATE claim_cert_items SET " . implode(',',$set_parts) .
                        " WHERE DATE(banking_date)='$bdate' AND entity='$entity'"
                    );
                }
            }
        }

        $recon_flag = $fully_done ? 1 : 0;
        $bal_val    = max(0, $new_bal);
        mysqli_query($conn,
            "UPDATE bank_statement_transactions
             SET reconciled=$recon_flag, reconciled_at=NOW(),
                 reconciled_amount=COALESCE(reconciled_amount,0)+$cert_amount,
                 reconcile_balance=$bal_val,
                 reconciled_description='$description'
             WHERE id=$bank_txn_id"
        );
        ulr_refresh_bank_recon($conn, $bank_txn_id);

        mysqli_commit($conn);
        ob_end_clean();
        echo json_encode(['ok'=>true,'log_id'=>$log_id,'fully_reconciled'=>$fully_done,'remaining_balance'=>$bal_val]);
    } catch(Exception $e) {
        mysqli_rollback($conn);
        ob_end_clean();
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

/* ══════════════════════════════════════════════════════════
   AJAX: delete_manual_reconcile — reverse ONE reconcile entry
   (identified by log_id). Since a ledger row can now have several
   entries, this only removes the single entry requested; any
   other entries for the same claim row are untouched.
══════════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'delete_manual_reconcile') {
    header('Content-Type: application/json');
    ob_start();
    $body   = json_decode(file_get_contents('php://input'), true);
    $log_id = intval($body['log_id'] ?? 0);

    if (!$log_id) { ob_end_clean(); echo json_encode(['ok'=>false,'msg'=>'Missing log_id']); exit; }

    $lr = mysqli_query($conn, "SELECT * FROM unilever_reconcile_log WHERE id=$log_id");
    $log = $lr ? mysqli_fetch_assoc($lr) : null;
    if (!$log) { ob_end_clean(); echo json_encode(['ok'=>false,'msg'=>'Log record not found']); exit; }

    $bank_txn_id = intval($log['bank_txn_id']);
    $cert_amount = floatval($log['claim_total']);

    mysqli_begin_transaction($conn);
    try {
$bq  = mysqli_query($conn, "SELECT credit,debit,balance,reconciled_amount,reconcile_balance FROM bank_statement_transactions WHERE id=$bank_txn_id");        $bk  = $bq ? mysqli_fetch_assoc($bq) : null;
        if ($bk) {
            $field      = $log['bank_field'] ?? 'credit';
            $field_val  = floatval($bk[$field] ?? $bk['credit'] ?? 0);
            $prev_recon = floatval($bk['reconciled_amount'] ?? 0);
            $new_recon  = max(0, round($prev_recon - $cert_amount, 2));
            $restored_bal = round($field_val - $new_recon, 2);
            $fully_done   = ($restored_bal <= 0);

            mysqli_query($conn,
                "UPDATE bank_statement_transactions
                 SET reconciled_amount=$new_recon,
                     reconcile_balance=" . max(0, $restored_bal) . ",
                     reconciled=" . ($fully_done ? 1 : 0) . ",
                     reconciled_at=" . ($new_recon > 0 ? 'reconciled_at' : 'NULL') . ",
                     reconciled_description=" . ($new_recon > 0 ? 'reconciled_description' : 'NULL') . "
                 WHERE id=$bank_txn_id"
            );
        }

        $cl = mysqli_query($conn, "SELECT banking_date, entity FROM unilever_reconcile_claim_lines WHERE log_id=$log_id LIMIT 1");
        $cl_row = $cl ? mysqli_fetch_assoc($cl) : null;
        if ($cl_row) {
            $bdate_e  = mysqli_real_escape_string($conn, $cl_row['banking_date'] ?? '');
            $entity_e = mysqli_real_escape_string($conn, $cl_row['entity'] ?? '');
            /* Only clear the claim_cert_items bank_ref/log link if there is no
               OTHER remaining reconcile entry left for this same ledger row —
               otherwise we'd wipe the link to a still-valid reconcile. */
            $other = mysqli_query($conn,
                "SELECT cl2.log_id FROM unilever_reconcile_claim_lines cl2
                 WHERE cl2.banking_date='$bdate_e' AND cl2.entity='$entity_e' AND cl2.log_id<>$log_id
                 ORDER BY cl2.id DESC LIMIT 1");
            $other_row = $other ? mysqli_fetch_assoc($other) : null;

            $col_check = mysqli_query($conn,
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='claim_cert_items'
                 AND COLUMN_NAME IN ('bank_ref','reconcile_log_id','reconciled_at')");
            $existing_cols = [];
            while ($cc = mysqli_fetch_assoc($col_check)) $existing_cols[] = $cc['COLUMN_NAME'];

            if ($other_row) {
                /* Re-point claim_cert_items to the remaining reconcile entry instead of clearing it */
                $other_log_id = (int)$other_row['log_id'];
                $set_parts = [];
                if (in_array('reconcile_log_id', $existing_cols)) $set_parts[] = "reconcile_log_id=$other_log_id";
                if ($set_parts && $bdate_e && $entity_e) {
                    mysqli_query($conn,
                        "UPDATE claim_cert_items SET " . implode(',', $set_parts) .
                        " WHERE DATE(banking_date)='$bdate_e' AND entity='$entity_e'"
                    );
                }
            } else {
                $set_parts = [];
                if (in_array('bank_ref',         $existing_cols)) $set_parts[] = "bank_ref=NULL";
                if (in_array('reconcile_log_id', $existing_cols)) $set_parts[] = "reconcile_log_id=NULL";
                if (in_array('reconciled_at',    $existing_cols)) $set_parts[] = "reconciled_at=NULL";
                if ($set_parts && $bdate_e && $entity_e) {
                    mysqli_query($conn,
                        "UPDATE claim_cert_items SET " . implode(',', $set_parts) .
                        " WHERE DATE(banking_date)='$bdate_e' AND entity='$entity_e'"
                    );
                }
            }
        }

        mysqli_query($conn, "DELETE FROM unilever_reconcile_claim_lines WHERE log_id=$log_id");
        mysqli_query($conn, "DELETE FROM unilever_reconcile_log WHERE id=$log_id");
        ulr_refresh_bank_recon($conn, $bank_txn_id);   /* rebuild remark from what is left, or clear it */

        mysqli_commit($conn);
        ob_end_clean();
        echo json_encode(['ok'=>true,'bank_txn_id'=>$bank_txn_id]);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        ob_end_clean();
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

/* ══════════════════════════════════════════════════════════
   AJAX: ai_verify  (Pass 2 — multi-claim combo matching)
══════════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'ai_verify') {
    header('Content-Type: application/json');
    ob_start();
    $body = json_decode(file_get_contents('php://input'), true);
    $unmatched_claims    = $body['unmatched_claims']    ?? [];
    $unmatched_bank_rows = $body['unmatched_bank_rows'] ?? [];

    if (!$unmatched_claims || !$unmatched_bank_rows) {
        ob_end_clean(); echo json_encode(['ok'=>false,'msg'=>'Nothing to verify']); exit;
    }

    $kr     = mysqli_query($conn, "SELECT `value` FROM ai_settings WHERE `key`='gemini_api_key'");
    $kr_row = $kr ? mysqli_fetch_assoc($kr) : null;
    $gemini_key = $kr_row['value'] ?? '';
    if (!$gemini_key) {
        ob_end_clean(); echo json_encode(['ok'=>false,'msg'=>'Gemini API key not configured.']); exit;
    }

    $claims_json = json_encode($unmatched_claims,    JSON_PRETTY_PRINT);
    $bank_json   = json_encode($unmatched_bank_rows, JSON_PRETTY_PRINT);

    $prompt = <<<PROMPT
You are a financial reconciliation engine. Your task is to find combinations of unmatched claim groups whose TOTAL sum exactly matches a bank statement row's credit, debit, or balance amount (rounded to 2 decimal places).

UNMATCHED CLAIM GROUPS (each has a "bdate", "entity", "claim_total"):
$claims_json

UNMATCHED BANK ROWS (each has "id", "transaction_date", "credit", "debit", "balance", "description"):
$bank_json

RULES:
1. Try combining 2 claims, then 3 claims (do NOT try more than 3).
2. A combination matches a bank row if: round(sum of claim_totals, 2) == round(bank.credit, 2) OR round(bank.debit, 2) OR round(bank.balance, 2).
3. Each claim and each bank row can only be used ONCE across all matches.
4. Prefer credit matches over debit, debit over balance.
5. Report ONLY confirmed exact matches. Do not guess.

Return ONLY a valid JSON array (no markdown, no explanation) with objects like:
[
  {
    "bank_row_id": <integer id of the bank row>,
    "bank_field": "credit" | "debit" | "balance",
    "bank_amount": <float>,
    "bank_description": "<string>",
    "bank_transaction_date": "<string>",
    "claim_indices": [<0-based index in unmatched_claims array>, ...],
    "claim_entities": ["entity1", "entity2"],
    "claim_dates": ["date1", "date2"],
    "claim_totals": [float, float],
    "combined_total": <float>
  }
]

If no combinations are found, return an empty array: []
PROMPT;

    $payload = json_encode([
        'contents'         => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 4096],
    ]);

    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . urlencode($gemini_key));
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 60,
    ]);
    $raw = curl_exec($ch); $err = curl_error($ch); $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err)            { ob_end_clean(); echo json_encode(['ok'=>false,'msg'=>'cURL error: '.$err]); exit; }
    if ($http_code!==200){ ob_end_clean(); echo json_encode(['ok'=>false,'msg'=>'Gemini HTTP '.$http_code.': '.substr($raw,0,300)]); exit; }

    $gemini = json_decode($raw, true);
    if (!$gemini)                         { ob_end_clean(); echo json_encode(['ok'=>false,'msg'=>'Invalid Gemini JSON']); exit; }
    if (isset($gemini['error']))          { ob_end_clean(); echo json_encode(['ok'=>false,'msg'=>$gemini['error']['message']]); exit; }
    if (!isset($gemini['candidates'][0])) { ob_end_clean(); echo json_encode(['ok'=>false,'msg'=>'Empty candidates']); exit; }

    $text = trim($gemini['candidates'][0]['content']['parts'][0]['text'] ?? '');
    $text = preg_replace('/^[\s]*```(?:json)?\s*\n?/im', '', $text);
    $text = preg_replace('/\n?[\s]*```[\s]*$/im', '', $text);
    $text = trim($text);
    if ($text && $text[0] !== '[' && $text[0] !== '{') {
        $bp = min(array_filter([strpos($text,'['),strpos($text,'{')], fn($v)=>$v!==false));
        if ($bp !== null) $text = substr($text, $bp);
    }

    $combos = json_decode($text, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        ob_end_clean(); echo json_encode(['ok'=>false,'msg'=>'JSON parse error: '.json_last_error_msg()]); exit;
    }
    if (!is_array($combos)) {
        ob_end_clean(); echo json_encode(['ok'=>false,'msg'=>'AI response not array']); exit;
    }

    ob_end_clean();
    echo json_encode(['ok'=>true,'combos'=>$combos]);
    exit;
}

/* ══════════════════════════════════════════════════════════
   AJAX: accounts list
══════════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'accounts') {
    header('Content-Type: application/json');
    ob_start();
    $q = mysqli_query($conn,
        "SELECT cba.id, cba.account_no, cba.account_name, cba.account_type,
                b.bank_name, c.company_name, c.company_code
         FROM company_bank_accounts cba
         LEFT JOIN banks b ON cba.bank_code = b.bank_code
         LEFT JOIN companies c ON cba.company_id = c.id
         WHERE cba.active = 1
         ORDER BY b.bank_name, cba.account_name");
    $rows = [];
    while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;
    ob_end_clean();
    echo json_encode(['ok'=>true,'accounts'=>$rows]);
    exit;
}

include 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Sora:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#f0f2f5;--surface:#ffffff;--surface2:#f7f8fb;--border:#e4e8ef;--border2:#d0d6e2;
  --ink:#0d1117;--ink3:#4a5568;--muted:#8492a6;--blue:#1a56db;--green:#0d7a4e;
  --amber:#b45309;--red:#b91c1c;--violet:#6d28d9;--ai-a:#7c3aed;--ai-b:#a855f7;
  --matched-bg:#f0fdf6;--unmatched-bg:#fef2f2;--ai-matched-bg:#faf5ff;--partial-bg:#fffbeb;
  --font:'Sora',system-ui,sans-serif;--mono:'DM Mono','Fira Mono',monospace;
  --radius:12px;--shadow:0 1px 4px rgba(0,0,0,.06),0 4px 20px rgba(0,0,0,.06);
  --shadow-md:0 8px 32px rgba(0,0,0,.1);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--font);background:var(--bg);color:var(--ink);}

.recon-wrap{max-width:1600px;margin:0 auto;padding:24px 20px 80px;}

.page-head{background:linear-gradient(120deg,#0b1d3a 0%,#132d5e 55%,#1a3f80 100%);border-radius:16px;padding:22px 28px;display:flex;align-items:center;gap:18px;margin-bottom:22px;position:relative;overflow:hidden;}
.page-head::after{content:'';position:absolute;right:-60px;top:-60px;width:220px;height:220px;border-radius:50%;background:radial-gradient(circle,rgba(99,179,237,.18) 0%,transparent 70%);pointer-events:none;}
.ph-icon{width:54px;height:54px;border-radius:14px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:24px;color:#fff;flex-shrink:0;}
.ph-title{color:#fff;font-size:21px;font-weight:900;line-height:1.2;}
.ph-sub{color:rgba(255,255,255,.58);font-size:12px;margin-top:4px;}

.selector-card{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius);padding:20px 24px;margin-bottom:20px;box-shadow:var(--shadow);display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.sel-label{font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;}
.sel-select{flex:1;min-width:260px;max-width:520px;padding:10px 14px;border:1.5px solid var(--border2);border-radius:9px;font-size:13px;font-family:var(--font);color:var(--ink);background:#fff;outline:none;transition:border-color .18s;}
.sel-select:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(26,86,219,.12);}

.btn-run{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,#1a56db,#2563eb);color:#fff;border:none;border-radius:10px;padding:11px 22px;font-size:13px;font-weight:800;font-family:var(--font);cursor:pointer;box-shadow:0 4px 14px rgba(26,86,219,.3);transition:filter .18s;}
.btn-run:hover{filter:brightness(1.08);}
.btn-run:disabled{opacity:.5;cursor:not-allowed;}

.btn-save{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,#059669,#10b981);color:#fff;border:none;border-radius:10px;padding:11px 22px;font-size:13px;font-weight:800;font-family:var(--font);cursor:pointer;box-shadow:0 4px 14px rgba(5,150,105,.35);transition:filter .18s;}
.btn-save:hover{filter:brightness(1.08);}
.btn-save:disabled{opacity:.5;cursor:not-allowed;}

.btn-ai{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,#7c3aed,#a855f7);color:#fff;border:none;border-radius:10px;padding:11px 22px;font-size:13px;font-weight:800;font-family:var(--font);cursor:pointer;box-shadow:0 4px 14px rgba(124,58,237,.35);transition:filter .18s;position:relative;overflow:hidden;}
.btn-ai::before{content:'';position:absolute;inset:0;background:linear-gradient(90deg,transparent,rgba(255,255,255,.15),transparent);transform:translateX(-100%);transition:transform .5s;}
.btn-ai:hover::before{transform:translateX(100%);}
.btn-ai:hover{filter:brightness(1.1);}
.btn-ai:disabled{opacity:.5;cursor:not-allowed;}
.btn-ai-wrap{display:none;align-items:center;gap:10px;flex-wrap:wrap;}

.kpi-row{display:grid;grid-template-columns:repeat(8,1fr);gap:12px;margin-bottom:18px;}
@media(max-width:1200px){.kpi-row{grid-template-columns:repeat(4,1fr);}}
@media(max-width:560px){.kpi-row{grid-template-columns:repeat(2,1fr);}}
.kpi-box{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius);padding:14px 16px;box-shadow:var(--shadow);position:relative;overflow:hidden;}
.kpi-box::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:3px 3px 0 0;}
.kpi-box.k-blue::before{background:var(--blue);}
.kpi-box.k-green::before{background:var(--green);}
.kpi-box.k-red::before{background:var(--red);}
.kpi-box.k-violet::before{background:var(--violet);}
.kpi-box.k-amber::before{background:#d97706;}
.kpi-box.k-ai::before{background:linear-gradient(90deg,var(--ai-a),var(--ai-b));}
.kpi-box.k-partial::before{background:#d97706;}
.kpi-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:6px;}
.kpi-val{font-size:26px;font-weight:900;color:var(--ink);line-height:1;}
.kpi-val.green{color:var(--green);}
.kpi-val.red{color:var(--red);}
.kpi-val.violet{color:var(--violet);}
.kpi-val.blue{color:var(--blue);}
.kpi-val.amber{color:var(--amber);}
.kpi-val.ai{color:var(--ai-a);}

.legend-bar{display:flex;align-items:center;gap:18px;flex-wrap:wrap;padding:10px 16px;background:var(--surface2);border:1.5px solid var(--border);border-radius:10px;margin-bottom:14px;font-size:11px;font-weight:600;color:var(--ink3);}
.leg-dot{width:12px;height:12px;border-radius:3px;border:1.5px solid;display:inline-block;margin-right:4px;vertical-align:middle;}

.filter-bar{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;box-shadow:var(--shadow);}
.filter-bar .fb-label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;}
.flt-btn{display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border-radius:8px;border:1.5px solid var(--border2);background:#fff;font-size:11.5px;font-weight:700;font-family:var(--font);cursor:pointer;color:var(--ink3);transition:all .15s;}
.flt-btn:hover{background:var(--surface2);border-color:var(--blue);color:var(--blue);}
.flt-btn.active{background:var(--blue);border-color:var(--blue);color:#fff;}
.flt-btn.flt-matched.active{background:#059669;border-color:#059669;}
.flt-btn.flt-unmatched.active{background:var(--red);border-color:var(--red);}
.flt-btn.flt-ai.active{background:var(--ai-a);border-color:var(--ai-a);}
.flt-btn.flt-partial.active{background:#d97706;border-color:#d97706;}
.flt-sep{flex:1;}
.flt-search{border:1.5px solid var(--border2);border-radius:8px;padding:7px 12px;font-size:12px;font-family:var(--font);color:var(--ink);outline:none;width:200px;}
.flt-search:focus{border-color:var(--blue);}

.spinner{display:inline-block;width:16px;height:16px;border:2.5px solid #d1d5db;border-top-color:var(--blue);border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle;}
.spinner-ai{display:inline-block;width:16px;height:16px;border:2.5px solid #d1d5db;border-top-color:var(--ai-a);border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle;}
.spinner-sm{display:inline-block;width:12px;height:12px;border:2px solid #d1d5db;border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle;}
@keyframes spin{to{transform:rotate(360deg);}}

#recon-toast{position:fixed;bottom:26px;right:22px;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:700;color:#fff;background:#166534;box-shadow:var(--shadow-md);z-index:9999;opacity:0;pointer-events:none;transition:opacity .3s;}
#recon-toast.show{opacity:1;}
#recon-toast.err{background:var(--red);}
#recon-toast.ai{background:var(--ai-a);}
#recon-toast.info{background:var(--blue);}

.save-recon-bar{display:none;background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:2px solid #86efac;border-radius:var(--radius);padding:16px 22px;margin-bottom:16px;box-shadow:var(--shadow);}
.save-recon-inner{display:flex;align-items:center;gap:14px;flex-wrap:wrap;}
.srb-title{font-size:13px;font-weight:800;color:#065f46;flex-shrink:0;}
.srb-desc-input{flex:1;min-width:220px;padding:9px 13px;border:1.5px solid #6ee7b7;border-radius:8px;font-family:var(--font);font-size:12px;color:var(--ink);outline:none;background:#fff;}
.srb-desc-input:focus{border-color:#059669;box-shadow:0 0 0 3px rgba(5,150,105,.12);}
.srb-count{font-size:11px;font-weight:700;color:#059669;white-space:nowrap;}

.ai-section{display:none;margin-bottom:16px;background:var(--surface);border:2px solid #c4b5fd;border-radius:var(--radius);box-shadow:var(--shadow),0 0 0 3px rgba(124,58,237,.06);overflow:hidden;}
.ai-section-head{display:flex;align-items:center;gap:14px;padding:14px 20px;border-bottom:1.5px solid #e9d5ff;background:linear-gradient(135deg,#faf5ff,#f5f3ff);flex-wrap:wrap;}
.ai-head-icon{width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#7c3aed,#a855f7);display:flex;align-items:center;justify-content:center;font-size:18px;color:#fff;flex-shrink:0;}
.ai-head-title{font-size:14px;font-weight:800;color:#4c1d95;}
.ai-head-sub{font-size:11px;color:#7c3aed;margin-top:2px;}
.ai-combo-grid{padding:16px 20px;display:flex;flex-direction:column;gap:12px;}
.ai-combo-card{border:1.5px solid #ddd6fe;border-radius:10px;background:linear-gradient(135deg,#faf5ff 0%,#fff 100%);overflow:hidden;}
.ai-combo-head{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:11px 16px;background:#f5f3ff;border-bottom:1px solid #ddd6fe;}
.ai-combo-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 11px;border-radius:20px;background:linear-gradient(135deg,#7c3aed,#a855f7);color:#fff;font-size:10px;font-weight:800;border:none;}
.ai-combo-total{font-family:var(--mono);font-size:14px;font-weight:800;color:#4c1d95;margin-left:auto;}
.ai-combo-body{padding:12px 16px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start;}
.ai-claims-col{flex:1;min-width:200px;}
.ai-claims-lbl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#7c3aed;margin-bottom:7px;}
.ai-claim-item{display:flex;align-items:center;justify-content:space-between;padding:6px 10px;border-radius:7px;background:#fff;border:1.5px solid #ddd6fe;margin-bottom:5px;}
.ai-claim-item:last-child{margin-bottom:0;}
.ai-claim-meta{font-size:11px;color:var(--ink3);}
.ai-claim-meta strong{color:var(--ink);font-weight:700;display:block;font-size:12px;}
.ai-claim-amt{font-family:var(--mono);font-size:12px;font-weight:800;color:#4c1d95;white-space:nowrap;margin-left:8px;}
.ai-plus{text-align:center;font-size:18px;font-weight:900;color:#a855f7;padding:2px 0;}
.ai-bank-col{flex:1;min-width:200px;}
.ai-bank-lbl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#0d7a4e;margin-bottom:7px;}
.ai-bank-card{padding:10px 12px;border-radius:8px;background:#f0fdf4;border:1.5px solid #86efac;}
.ai-bank-field-badge{display:inline-flex;padding:2px 9px;border-radius:12px;font-size:10px;font-weight:800;text-transform:uppercase;margin-bottom:5px;}
.ai-bank-field-credit{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;}
.ai-bank-field-debit{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.ai-bank-field-balance{background:#ede9fe;color:#5b21b6;border:1px solid #c4b5fd;}
.ai-bank-amt{font-family:var(--mono);font-size:16px;font-weight:900;color:#0d7a4e;display:block;margin:3px 0;}
.ai-bank-desc{font-size:11px;color:var(--ink3);}
.ai-bank-date{font-size:11px;color:var(--muted);margin-top:2px;}
.ai-ok-pill{display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border-radius:20px;background:#d1fae5;color:#065f46;border:1.5px solid #6ee7b7;font-size:11px;font-weight:800;white-space:nowrap;margin-top:8px;}
.ai-arrow{font-size:20px;color:#c4b5fd;align-self:center;flex-shrink:0;padding:0 4px;}
.ai-empty{padding:28px;text-align:center;color:var(--muted);font-size:13px;font-style:italic;}

.recon-table-wrap{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;}
.rtw-header{padding:14px 18px;border-bottom:1.5px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;background:linear-gradient(to right,#fafbff,#fff);}
.rtw-title{font-size:14px;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:8px;}
.rtw-scroll{overflow-x:auto;max-height:72vh;overflow-y:auto;}
.recon-tbl{width:100%;border-collapse:collapse;font-size:12px;min-width:1300px;}
.recon-tbl thead th{padding:10px 12px;text-align:left;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;position:sticky;top:0;z-index:10;}
.recon-tbl thead th.tr{text-align:right;}
.recon-tbl thead th.tc{text-align:center;}
.thead-claim{background:#0b2045;color:#a5c8ff;}
.thead-bank{background:#0d3527;color:#6ee7b7;}
.thead-tally{background:#1e1229;color:#d8b4fe;}
.thead-action{background:#1a1a2e;color:#a5b4fc;}
.recon-tbl tbody tr{border-bottom:1px solid #f1f5f9;}
.recon-tbl tbody tr:hover td{filter:brightness(.97);}
.row-matched td{background:var(--matched-bg);}
.row-unmatched td{background:var(--unmatched-bg);}
.row-ai-matched td{background:var(--ai-matched-bg);}
.row-manual-matched td{background:#f0f9ff;}
.row-saved td{background:#f0fdf6;}
.row-restored td{background:#f0f9ff;}
.row-partial-matched td{background:var(--partial-bg);}

.status-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:800;white-space:nowrap;border:1.5px solid;}
.sb-matched{background:#d1fae5;color:#065f46;border-color:#6ee7b7;}
.sb-unmatched{background:#fee2e2;color:#991b1b;border-color:#fca5a5;}
.sb-ai-matched{background:#ede9fe;color:#4c1d95;border-color:#c4b5fd;}
.sb-manual{background:#e0f2fe;color:#075985;border-color:#7dd3fc;}
.sb-saved{background:#d1fae5;color:#065f46;border-color:#6ee7b7;}
.sb-restored{background:#e0f2fe;color:#075985;border-color:#7dd3fc;}
.sb-partial{background:#fef3c7;color:#92400e;border-color:#fcd34d;}

.amt{font-family:var(--mono);font-weight:700;white-space:nowrap;}
.amt-pos{color:#065f46;}
.amt-neg{color:var(--red);}

.tally-cell{padding:6px 10px !important;vertical-align:middle;}
.tally-box{display:flex;flex-direction:column;border:1.5px solid var(--border2);border-radius:9px;overflow:hidden;min-width:215px;font-size:11px;}
.tf-row{display:grid;grid-template-columns:58px 1fr auto;align-items:center;padding:5px 10px;gap:8px;border-bottom:1px solid var(--border);}
.tf-row:last-child{border-bottom:none;}
.tf-row.tf-hit{background:#d1fae5;}
.tf-row.tf-miss{background:#fff;}
.tf-row.tf-none{background:#f9fafb;}
.tf-badge{display:inline-flex;align-items:center;justify-content:center;padding:2px 0;border-radius:5px;font-size:9.5px;font-weight:900;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap;}
.tf-badge-credit{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;}
.tf-badge-debit{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.tf-badge-balance{background:#ede9fe;color:#5b21b6;border:1px solid #c4b5fd;}
.tf-amt{font-family:var(--mono);font-weight:700;font-size:11px;white-space:nowrap;}
.tf-amt-credit{color:#065f46;}
.tf-amt-debit{color:#b91c1c;}
.tf-amt-balance{color:#6d28d9;}
.tf-amt-zero{color:#9ca3af;}
.tf-ok{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:20px;font-size:9.5px;font-weight:900;flex-shrink:0;border:1.5px solid;}
.tf-ok-yes{background:#d1fae5;color:#065f46;border-color:#6ee7b7;}
.tf-ok-no{background:#f3f4f6;color:#9ca3af;border-color:#e5e7eb;}

.tally-box-saved{display:flex;flex-direction:column;border:1.5px solid #6ee7b7;border-radius:9px;overflow:hidden;min-width:215px;font-size:11px;}
.ts-head{padding:5px 10px;background:linear-gradient(135deg,#059669,#10b981);color:#fff;font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;display:flex;align-items:center;gap:5px;}
.ts-row{display:flex;align-items:center;justify-content:space-between;padding:5px 10px;border-bottom:1px solid #d1fae5;gap:6px;background:#f0fdf4;}
.ts-row:last-child{border-bottom:none;}
.ts-label{font-size:10px;color:#059669;font-weight:700;white-space:nowrap;}
.ts-val{font-family:var(--mono);font-size:11px;font-weight:800;color:#065f46;}
.ts-bal{color:var(--amber);}

.tally-box-restored{display:flex;flex-direction:column;border:1.5px solid #7dd3fc;border-radius:9px;overflow:hidden;min-width:215px;font-size:11px;}
.tr-head{padding:5px 10px;background:linear-gradient(135deg,#0369a1,#0ea5e9);color:#fff;font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;display:flex;align-items:center;gap:5px;}
.tr-row{display:flex;align-items:center;justify-content:space-between;padding:5px 10px;border-bottom:1px solid #bae6fd;gap:6px;background:#f0f9ff;}
.tr-row:last-child{border-bottom:none;}
.tr-label{font-size:10px;color:#0369a1;font-weight:700;white-space:nowrap;}
.tr-val{font-family:var(--mono);font-size:11px;font-weight:800;color:#075985;}

.tally-box-partial{display:flex;flex-direction:column;border:1.5px solid #fcd34d;border-radius:9px;overflow:hidden;min-width:215px;font-size:11px;}
.tp-head{padding:5px 10px;background:linear-gradient(135deg,#b45309,#d97706);color:#fff;font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;display:flex;align-items:center;gap:5px;}
.tp-row{display:flex;align-items:center;justify-content:space-between;padding:5px 10px;border-bottom:1px solid #fde68a;gap:6px;background:#fffbeb;}
.tp-row:last-child{border-bottom:none;}
.tp-label{font-size:10px;color:#b45309;font-weight:700;white-space:nowrap;}
.tp-val{font-family:var(--mono);font-size:11px;font-weight:800;color:#92400e;}
.tp-val.tp-bal{color:#b91c1c;}

.recon-entry-list{display:flex;flex-direction:column;gap:5px;padding:6px 4px;}
.recon-entry-item{display:flex;align-items:center;gap:8px;padding:6px 10px;border-radius:7px;background:#fff;border:1.5px solid var(--border2);}
.recon-entry-item.re-partial{border-color:#fcd34d;background:#fffdf5;}
.recon-entry-item.re-new{border-color:#6ee7b7;background:#f0fdf6;}
.re-meta{flex:1;min-width:0;}
.re-date{font-family:var(--mono);font-size:10.5px;font-weight:700;color:var(--ink3);}
.re-desc{font-size:10.5px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:220px;}
.re-amt{font-family:var(--mono);font-size:12px;font-weight:800;color:#065f46;white-space:nowrap;}
.re-del-btn{flex-shrink:0;width:22px;height:22px;border-radius:6px;border:1.5px solid #fca5a5;background:#fee2e2;color:#991b1b;font-size:11px;font-weight:900;cursor:pointer;display:flex;align-items:center;justify-content:center;}
.re-del-btn:hover{background:#fecaca;border-color:#f87171;}
.re-outstanding-note{font-size:10.5px;font-weight:800;color:#b45309;background:#fef3c7;border:1.5px solid #fcd34d;border-radius:7px;padding:5px 10px;text-align:center;}

.btn-manual{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:7px;border:1.5px solid #7dd3fc;background:#e0f2fe;color:#075985;font-size:11px;font-weight:700;font-family:var(--font);cursor:pointer;transition:all .15s;white-space:nowrap;}
.btn-manual:hover{background:#bae6fd;border-color:#0ea5e9;}
.btn-manual:disabled{opacity:.4;cursor:not-allowed;}
.btn-add-more{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:7px;border:1.5px solid #fcd34d;background:#fef3c7;color:#92400e;font-size:11px;font-weight:700;font-family:var(--font);cursor:pointer;transition:all .15s;white-space:nowrap;}
.btn-add-more:hover{background:#fde68a;border-color:#f59e0b;}
.btn-del-recon{display:inline-flex;align-items:center;gap:5px;padding:5px 10px;border-radius:7px;border:1.5px solid #fca5a5;background:#fee2e2;color:#991b1b;font-size:11px;font-weight:700;font-family:var(--font);cursor:pointer;transition:all .15s;white-space:nowrap;margin-top:4px;}
.btn-del-recon:hover{background:#fecaca;border-color:#f87171;}

.chip{display:inline-flex;align-items:center;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:700;white-space:nowrap;}
.chip-violet{background:#ede9fe;color:#4c1d95;}
.chip-ref{background:#f0f9ff;color:#075985;font-family:var(--mono);}
.chip-blue{background:#dbeafe;color:#1e40af;}

.state-empty{padding:70px 40px;text-align:center;color:var(--muted);}
.state-empty i{font-size:44px;display:block;margin-bottom:14px;color:#d1d5db;}
.state-empty p{font-size:14px;font-weight:600;}
.state-empty small{font-size:12px;color:#bbc4d0;display:block;margin-top:6px;}
.no-results-row td{padding:30px;text-align:center;color:var(--muted);font-size:13px;font-style:italic;}
.no-bank-msg{color:#9ca3af;font-size:11px;padding:8px 12px;display:block;font-style:italic;}

.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:2000;display:flex;align-items:center;justify-content:center;padding:20px;}
.modal-box{background:#fff;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.25);width:100%;max-width:860px;max-height:90vh;display:flex;flex-direction:column;overflow:hidden;}
.modal-head{padding:18px 22px;border-bottom:1.5px solid var(--border);display:flex;align-items:center;gap:12px;flex-shrink:0;background:linear-gradient(135deg,#0b1d3a,#132d5e);}
.modal-head-icon{width:38px;height:38px;border-radius:10px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:17px;color:#fff;flex-shrink:0;}
.modal-head-title{color:#fff;font-size:15px;font-weight:800;}
.modal-head-sub{color:rgba(255,255,255,.55);font-size:11px;margin-top:2px;}
.modal-close{margin-left:auto;background:rgba(255,255,255,.15);border:none;border-radius:8px;width:32px;height:32px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;cursor:pointer;}
.modal-close:hover{background:rgba(255,255,255,.25);}
.modal-body{padding:18px 22px;overflow-y:auto;flex:1;}
.modal-foot{padding:14px 22px;border-top:1.5px solid var(--border);display:flex;gap:10px;align-items:center;justify-content:flex-end;flex-shrink:0;flex-wrap:wrap;}

.claim-strip{background:#f0f9ff;border:1.5px solid #7dd3fc;border-radius:10px;padding:12px 16px;margin-bottom:16px;}
.claim-strip.claim-strip-partial{background:#fffbeb;border-color:#fcd34d;}
.claim-strip-title{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#0369a1;margin-bottom:8px;}
.claim-strip-partial .claim-strip-title{color:#b45309;}
.claim-strip-row{display:flex;gap:20px;flex-wrap:wrap;}
.cs-item{font-size:12px;color:var(--ink3);}
.cs-item strong{color:var(--ink);font-weight:800;}
.cs-item.cs-bal strong{color:#b45309;}

.msr-search-row{display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;}
.msr-input{flex:1;min-width:160px;padding:9px 13px;border:1.5px solid var(--border2);border-radius:8px;font-family:var(--font);font-size:12px;outline:none;}
.msr-input:focus{border-color:var(--blue);}
.msr-btn{padding:9px 16px;background:var(--blue);color:#fff;border:none;border-radius:8px;font-family:var(--font);font-size:12px;font-weight:700;cursor:pointer;}
.msr-btn:hover{filter:brightness(1.08);}

.bank-txn-table{width:100%;border-collapse:collapse;font-size:12px;}
.bank-txn-table thead th{padding:8px 10px;background:#1e293b;color:#94a3b8;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;text-align:left;position:sticky;top:0;}
.bank-txn-table thead th.tr{text-align:right;}
.bank-txn-table tbody tr{border-bottom:1px solid #f1f5f9;cursor:pointer;transition:background .12s;}
.bank-txn-table tbody tr:hover td{background:#f0f9ff;}
.bank-txn-table tbody tr.selected td{background:#dbeafe;outline:2px solid var(--blue);}
.bank-txn-table tbody td{padding:8px 10px;vertical-align:middle;}
.btxn-wrap{max-height:280px;overflow-y:auto;border:1.5px solid var(--border);border-radius:9px;margin-bottom:14px;}

.cert-area{background:#f8fafc;border:1.5px solid var(--border2);border-radius:10px;padding:14px 16px;margin-bottom:12px;}
.cert-area-title{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:10px;}
.cert-row{display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;}
.cert-field{display:flex;flex-direction:column;gap:5px;}
.cert-label{font-size:11px;font-weight:700;color:var(--ink3);}
.cert-input{padding:9px 13px;border:1.5px solid var(--border2);border-radius:8px;font-family:var(--mono);font-size:14px;font-weight:800;color:var(--ink);width:180px;outline:none;text-align:right;}
.cert-input:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(26,86,219,.12);}
.cert-field-select{padding:9px 13px;border:1.5px solid var(--border2);border-radius:8px;font-family:var(--font);font-size:12px;color:var(--ink);outline:none;}
.cert-field-select:focus{border-color:var(--blue);}
.balance-pill{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:9px;font-size:12px;font-weight:800;}
.balance-pill.positive{background:#fef3c7;color:#92400e;border:1.5px solid #fcd34d;}
.balance-pill.zero{background:#d1fae5;color:#065f46;border:1.5px solid #6ee7b7;}
.desc-input{width:100%;padding:9px 13px;border:1.5px solid var(--border2);border-radius:8px;font-family:var(--font);font-size:12px;color:var(--ink);outline:none;margin-top:10px;}
.desc-input:focus{border-color:var(--blue);}

.save-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:2100;display:flex;align-items:center;justify-content:center;padding:20px;}
.save-modal-box{background:#fff;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.25);width:100%;max-width:520px;overflow:hidden;}
.save-modal-head{padding:16px 20px;background:linear-gradient(135deg,#059669,#10b981);display:flex;align-items:center;gap:10px;}
.save-modal-head-title{color:#fff;font-size:15px;font-weight:800;}
.save-modal-body{padding:20px;}
.save-modal-foot{padding:14px 20px;border-top:1.5px solid var(--border);display:flex;gap:10px;justify-content:flex-end;}
.sml{font-size:12px;color:var(--ink3);margin-bottom:14px;}
.smf-label{font-size:11px;font-weight:700;color:var(--ink3);margin-bottom:5px;display:block;}
.smf-input{width:100%;padding:9px 13px;border:1.5px solid var(--border2);border-radius:8px;font-family:var(--font);font-size:12px;color:var(--ink);outline:none;margin-bottom:14px;}
.smf-input:focus{border-color:#059669;}
.smf-summary{background:#f0fdf4;border:1.5px solid #6ee7b7;border-radius:9px;padding:12px 14px;font-size:12px;color:#065f46;}
.smf-summary strong{display:block;margin-bottom:6px;font-size:13px;}
.btn-cancel-sm{padding:9px 20px;border:1.5px solid var(--border2);border-radius:8px;background:#fff;font-family:var(--font);font-size:12px;font-weight:700;cursor:pointer;color:var(--ink3);}
.btn-cancel-sm:hover{background:var(--surface2);}
.btn-cancel-sm.dirty{border-color:#f59e0b;background:#fffbeb;color:#92400e;}
</style>

<div class="recon-wrap">
  <div class="page-head">
    <div class="ph-icon"><i class="fa-solid fa-scale-balanced"></i></div>
    <div style="position:relative;z-index:1;">
      <div class="ph-title">Unilever Payment Reconciliation</div>
      <div class="ph-sub">Pass 1: Exact match on outstanding balance · Pass 2: AI multi-claim combos · Manual per-row reconcile · Multiple bank reconciles can be applied to one ledger row until fully cleared (UNILEVER transactions)</div>
    </div>
  </div>

  <div class="selector-card">
    <span class="sel-label"><i class="fa-solid fa-building-columns" style="margin-right:5px;"></i>Bank Account</span>
    <select id="accountSelect" class="sel-select" onchange="fillTypeSelect('');clearResults();markSettingsDirty()">
      <option value="">— Loading accounts… —</option>
    </select>
    <span class="sel-label">Statement Type</span>
    <select id="typeSelect" class="sel-select" style="min-width:150px;max-width:220px;flex:0 1 auto;" onchange="clearResults();markSettingsDirty()">
      <option value="">All types</option>
    </select>
    <button class="btn-cancel-sm" id="saveSettingsBtn" onclick="saveSettings(true)" title="Save account + type as the default"><i class="fa-solid fa-floppy-disk"></i> Save settings</button>
    <span id="settingsInfo" style="font-size:11px;color:var(--muted);font-weight:600;"></span>
    <button class="btn-run" id="runBtn" onclick="runReconcile()">
      <i class="fa-solid fa-arrows-rotate"></i> Reconcile
    </button>
    <div id="loadingSpinner" style="display:none;">
      <span class="spinner"></span>
      <span style="font-size:12px;color:var(--muted);margin-left:8px;font-weight:600;">Reconciling…</span>
    </div>
    <div class="btn-ai-wrap" id="aiVerifyWrap">
      <div style="width:1px;height:30px;background:var(--border);"></div>
      <button class="btn-ai" id="aiBtn" onclick="runAiVerify()">
        <i class="fa-solid fa-wand-magic-sparkles"></i> AI Verify
      </button>
      <div id="aiLoadingSpinner" style="display:none;align-items:center;gap:7px;">
        <span class="spinner-ai"></span>
        <span style="font-size:12px;color:var(--ai-a);font-weight:700;">AI analysing…</span>
      </div>
      <span id="aiHint" style="font-size:11px;color:var(--muted);font-weight:600;"></span>
    </div>
  </div>

  <div class="save-recon-bar" id="saveReconBar">
    <div class="save-recon-inner">
      <i class="fa-solid fa-floppy-disk" style="color:#059669;font-size:18px;"></i>
      <span class="srb-title">Save Reconciliation</span>
      <input type="text" class="srb-desc-input" id="saveDesc" placeholder="Description / Notes (optional)…">
      <span class="srb-count" id="saveCountLabel"></span>
      <button class="btn-save" id="saveFinalBtn" onclick="openSaveModal()">
        <i class="fa-solid fa-circle-check"></i> Save Reconcile
      </button>
    </div>
  </div>

  <div class="kpi-row" id="kpiRow" style="display:none;">
    <div class="kpi-box k-blue"><div class="kpi-lbl">Claim Groups</div><div class="kpi-val blue" id="kClaims">—</div></div>
    <div class="kpi-box k-green"><div class="kpi-lbl">Matched (P1)</div><div class="kpi-val green" id="kMatched">—</div></div>
    <div class="kpi-box k-green"><div class="kpi-lbl">Fully Reconciled</div><div class="kpi-val green" id="kReconciled">—</div></div>
    <div class="kpi-box k-partial"><div class="kpi-lbl">Partial (Balance Due)</div><div class="kpi-val amber" id="kPartial">—</div></div>
    <div class="kpi-box k-red"><div class="kpi-lbl">Unmatched</div><div class="kpi-val red" id="kUnmatched">—</div></div>
    <div class="kpi-box k-violet"><div class="kpi-lbl">Total Claim Amt</div><div class="kpi-val violet" id="kTotal" style="font-size:15px;">—</div></div>
    <div class="kpi-box k-amber"><div class="kpi-lbl">Outstanding Amt</div><div class="kpi-val amber" id="kOutstandingAmt" style="font-size:15px;">—</div></div>
    <div class="kpi-box k-ai"><div class="kpi-lbl">AI Combos</div><div class="kpi-val ai" id="kAiCombos">—</div></div>
  </div>

  <div class="legend-bar" id="legendBar" style="display:none;">
    <strong style="font-size:11px;color:var(--ink);">Legend:</strong>
    <span><span class="leg-dot" style="background:#f0fdf6;border-color:#6ee7b7;"></span>Fully Reconciled</span>
    <span><span class="leg-dot" style="background:#fffbeb;border-color:#fcd34d;"></span>Partial (Balance Due)</span>
    <span><span class="leg-dot" style="background:#fee2e2;border-color:#fca5a5;"></span>Unmatched</span>
    <span><span class="leg-dot" style="background:#faf5ff;border-color:#c4b5fd;"></span>AI Combo</span>
  </div>

  <div class="filter-bar" id="filterBar" style="display:none;">
    <span class="fb-label"><i class="fa-solid fa-filter"></i> Filter</span>
    <button class="flt-btn active" onclick="setFilter('all',this)">All</button>
    <button class="flt-btn flt-unmatched" onclick="setFilter('unmatched',this)"><i class="fa-solid fa-circle-xmark"></i> Unmatched</button>
    <button class="flt-btn flt-partial" onclick="setFilter('partial-matched',this)" id="fltPartialBtn"><i class="fa-solid fa-circle-half-stroke"></i> Partial</button>
    <button class="flt-btn flt-ai" onclick="setFilter('ai-matched',this)" id="fltAiBtn" style="display:none;"><i class="fa-solid fa-wand-magic-sparkles"></i> AI Matched</button>
    <button class="flt-btn" onclick="setFilter('manual-matched',this)" id="fltManualBtn"><i class="fa-solid fa-circle-check"></i> Fully Reconciled</button>
    <div class="flt-sep"></div>
    <input type="text" class="flt-search" id="searchInput" placeholder="Search date / entity / ref…" oninput="applyFilters()">
    <button class="flt-btn" style="padding:6px 10px;" onclick="document.getElementById('searchInput').value='';applyFilters();"><i class="fa-solid fa-xmark"></i></button>
  </div>

  <div class="ai-section" id="aiSection">
    <div class="ai-section-head">
      <div class="ai-head-icon"><i class="fa-solid fa-wand-magic-sparkles"></i></div>
      <div>
        <div class="ai-head-title">AI Multi-Claim Combination Matches</div>
        <div class="ai-head-sub">Claims that combine (2 or 3) to match a single bank row</div>
      </div>
      <div id="aiComboCount" style="margin-left:auto;font-size:12px;font-weight:700;color:#7c3aed;"></div>
    </div>
    <div class="ai-combo-grid" id="aiComboGrid"></div>
  </div>

  <div class="recon-table-wrap" id="reconTableWrap" style="display:none;">
    <div class="rtw-header">
      <div class="rtw-title">
        <i class="fa-solid fa-table-list"></i> Reconciliation Detail
        <span id="rowCountBadge" style="font-size:11px;font-weight:600;color:var(--muted);"></span>
      </div>
      <div style="font-size:11px;color:var(--muted);">Green=Fully Reconciled · Amber=Partial (Balance Due) · Purple=AI · Red=Unmatched</div>
    </div>
    <div class="rtw-scroll">
      <table class="recon-tbl" id="reconTbl">
        <thead>
          <tr>
            <th class="thead-claim">#</th>
            <th class="thead-claim">Status</th>
            <th class="thead-claim">Banking Date</th>
            <th class="thead-claim">Entity</th>
            <th class="thead-claim tc">Claims</th>
            <th class="thead-claim tr">Claim Total</th>
            <th class="thead-bank tc" colspan="5">Bank Reconcile(s) Applied</th>
            <th class="thead-tally tc" style="min-width:220px;">Balance / Tally</th>
            <th class="thead-action tc">Action</th>
          </tr>
        </thead>
        <tbody id="reconBody">
          <tr><td colspan="13">
            <div class="state-empty">
              <i class="fa-solid fa-scale-balanced"></i>
              <p>Select a bank account and click Reconcile</p>
              <small>Pass 1: exact match · Pass 2: AI combos · Previously reconciled items will be restored automatically</small>
            </div>
          </td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>
<div id="recon-toast"></div>

<!-- Manual Reconcile Modal -->
<div class="modal-overlay" id="manualModal" style="display:none;" onclick="if(event.target===this)closeManualModal()">
  <div class="modal-box">
    <div class="modal-head">
      <div class="modal-head-icon"><i class="fa-solid fa-hand-pointer"></i></div>
      <div>
        <div class="modal-head-title">Manual Reconcile</div>
        <div class="modal-head-sub" id="manualModalSub">Select a UNILEVER bank transaction to match</div>
      </div>
      <button class="modal-close" onclick="closeManualModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div class="claim-strip" id="claimStrip">
        <div class="claim-strip-title" id="claimStripTitle"><i class="fa-solid fa-file-invoice"></i> Claim Group</div>
        <div class="claim-strip-row">
          <div class="cs-item"><strong id="mr_entity">—</strong>Entity</div>
          <div class="cs-item"><strong id="mr_bdate">—</strong>Banking Date</div>
          <div class="cs-item"><strong id="mr_claim_total">—</strong>Claim Total</div>
          <div class="cs-item"><strong id="mr_claim_count">—</strong>Claims</div>
          <div class="cs-item"><strong id="mr_already_paid">—</strong>Already Reconciled</div>
          <div class="cs-item cs-bal"><strong id="mr_outstanding">—</strong>Outstanding Balance</div>
        </div>
      </div>
      <div class="msr-search-row">
        <input type="text" class="msr-input" id="mrSearch" placeholder="Search by description, reference, amount…" oninput="debounceSearch()">
        <input type="number" class="msr-input" id="mrAmtSearch" placeholder="Amount filter…" style="max-width:150px;" oninput="debounceSearch()">
        <button class="msr-btn" onclick="loadBankTxns()"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
      </div>
      <div class="btxn-wrap" id="btxnWrap">
        <table class="bank-txn-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Description</th>
              <th>Ref</th>
              <th class="tr">Debit</th>
              <th class="tr">Credit</th>
              <th class="tr">Balance</th>
              <th class="tr">Remaining Bal</th>
            </tr>
          </thead>
          <tbody id="btxnBody"><tr><td colspan="7" style="padding:20px;text-align:center;color:var(--muted);font-size:12px;">Loading…</td></tr></tbody>
        </table>
      </div>
      <div class="cert-area" id="certArea" style="display:none;">
        <div class="cert-area-title"><i class="fa-solid fa-calculator"></i> Reconcile Details</div>
        <div class="cert-row">
          <div class="cert-field">
            <span class="cert-label">Match Field</span>
            <select class="cert-field-select" id="mrField" onchange="updateBalance()">
              <option value="credit">Credit</option>
              <option value="debit">Debit</option>
              <option value="balance">Balance</option>
            </select>
          </div>
          <div class="cert-field">
            <span class="cert-label">Cert Amount (LKR)</span>
            <input type="number" class="cert-input" id="mrCertAmt" step="0.01" oninput="updateBalance()">
          </div>
          <div class="cert-field" style="justify-content:flex-end;">
            <span class="cert-label">Remaining Balance</span>
            <div id="balPill" class="balance-pill positive"><i class="fa-solid fa-scale-unbalanced"></i> LKR 0.00</div>
          </div>
        </div>
        <input type="text" class="desc-input" id="mrDesc" placeholder="Notes / Description…">
      </div>
      <div id="noTxnMsg" style="display:none;padding:20px;text-align:center;color:var(--muted);font-size:13px;font-style:italic;">No UNILEVER transactions found. Try adjusting your search.</div>
    </div>
    <div class="modal-foot">
      <span id="mrSelectedInfo" style="font-size:11px;color:var(--muted);font-weight:600;margin-right:auto;"></span>
      <button class="btn-cancel-sm" onclick="closeManualModal()">Cancel</button>
      <button class="btn-save" id="mrSaveBtn" onclick="saveManualReconcile()" disabled>
        <i class="fa-solid fa-circle-check"></i> Save Reconcile
      </button>
    </div>
  </div>
</div>

<!-- Save Reconcile Confirmation Modal -->
<div class="save-modal-overlay" id="saveModal" style="display:none;" onclick="if(event.target===this)closeSaveModal()">
  <div class="save-modal-box">
    <div class="save-modal-head">
      <i class="fa-solid fa-floppy-disk" style="color:#fff;font-size:18px;"></i>
      <div class="save-modal-head-title">Confirm Save Reconciliation</div>
    </div>
    <div class="save-modal-body">
      <p class="sml">This will mark matched bank statement rows as <strong>Reconciled</strong> and update claim records with bank references.</p>
      <label class="smf-label">Description / Notes</label>
      <input type="text" class="smf-input" id="saveModalDesc" placeholder="e.g. June 2025 UL reconcile batch…">
      <div class="smf-summary" id="saveModalSummary">
        <strong>Summary</strong>
        Loading…
      </div>
    </div>
    <div class="save-modal-foot">
      <button class="btn-cancel-sm" onclick="closeSaveModal()">Cancel</button>
      <button class="btn-save" id="saveModalBtn" onclick="executeSave()">
        <i class="fa-solid fa-circle-check"></i> Confirm &amp; Save
      </button>
    </div>
  </div>
</div>

<script>
let _claims         = [];
let _allBankRows    = [];
let _usedBankIds    = [];
let _aiCombos       = [];
let _aiClaimIdxSet  = new Set();
let _filterStatus   = 'all';
let _accountId      = 0;
let _mrClaimIdx     = -1;
let _mrSelectedTxn  = null;
let _searchTimer    = null;
const _page         = 'unilever_reconcile.php';

function esc(s){if(s==null)return '';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
function fmtN(v,dp){return parseFloat(v||0).toLocaleString('en-LK',{minimumFractionDigits:dp??2,maximumFractionDigits:dp??2});}
function fmtDate(d){if(!d)return '—';try{const dt=new Date(d);if(isNaN(dt))return d;return dt.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});}catch(e){return d;}}
function amtCell(v,cls){const f=parseFloat(v||0);if(!f)return '<span style="color:#9ca3af;">—</span>';return '<span class="amt '+cls+'">LKR '+fmtN(f)+'</span>';}

function showToast(msg,type){
  const t=document.getElementById('recon-toast');
  t.textContent=msg;
  t.className=type||'';
  t.classList.add('show');
  clearTimeout(t._t);
  t._t=setTimeout(()=>t.classList.remove('show'),5000);
}

let _ulrAccounts={}, _ulrTypes={}, _ulrSaved={account:0,type:''};
async function loadAccounts(){
  try{
    const res=await fetch(_page+'?ajax=settings_get');
    const data=await res.json();
    _ulrTypes=data.types||{};
    _ulrAccounts={};
    const sel=document.getElementById('accountSelect');
    sel.innerHTML='<option value="">— Select Bank Account (with bank statements) —</option>';
    (data.accounts||[]).forEach(a=>{
      _ulrAccounts[a.id]=a;
      const o=document.createElement('option');
      o.value=a.id;
      o.textContent=a.label+' — '+a.uploads+' statement(s), latest '+(a.last||'—');
      sel.appendChild(o);
    });
    if(!(data.accounts||[]).length) sel.innerHTML='<option value="">— No bank statements uploaded yet —</option>';
    const st=data.settings||{};
    _ulrSaved={account:parseInt(st.account||0),type:st.type||''};
    if(_ulrSaved.account){ sel.value=_ulrSaved.account; }
    fillTypeSelect(_ulrSaved.type);
    document.getElementById('settingsInfo').textContent=st.updated_at?('Saved '+st.updated_at+' by '+(st.updated_by||'')):'Not saved yet';
    document.getElementById('saveSettingsBtn').classList.remove('dirty');
  }catch(e){showToast('Failed to load accounts','err');}
}
function fillTypeSelect(keep){
  const acc=_ulrAccounts[document.getElementById('accountSelect').value];
  const ts=document.getElementById('typeSelect');
  ts.innerHTML='<option value="">All types</option>';
  if(acc&&acc.types){
    Object.keys(acc.types).forEach(bt=>{
      const o=document.createElement('option');
      o.value=bt; o.textContent=(_ulrTypes[bt]||bt)+' (latest '+acc.types[bt]+')';
      ts.appendChild(o);
    });
  }
  if(keep&&acc&&acc.types&&acc.types[keep]) ts.value=keep;
}
function markSettingsDirty(){
  const acc=parseInt(document.getElementById('accountSelect').value)||0, bt=document.getElementById('typeSelect').value;
  document.getElementById('saveSettingsBtn').classList.toggle('dirty', acc!==_ulrSaved.account || bt!==_ulrSaved.type);
}
async function saveSettings(showMsg){
  const acc=parseInt(document.getElementById('accountSelect').value)||0, bt=document.getElementById('typeSelect').value;
  if(!acc){showToast('Please select a bank account first','err');return false;}
  if(acc===_ulrSaved.account && bt===_ulrSaved.type){ if(showMsg) showToast('Settings already saved','info'); return true; }
  try{
    const res=await fetch(_page+'?ajax=settings_save',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({account_id:acc,bank_type:bt})});
    const data=await res.json();
    if(!data.ok) throw new Error(data.msg||'Save failed');
    _ulrSaved={account:acc,type:bt};
    document.getElementById('saveSettingsBtn').classList.remove('dirty');
    document.getElementById('settingsInfo').textContent='Saved '+(data.settings.updated_at||'')+' by '+(data.settings.updated_by||'');
    if(showMsg) showToast('Settings saved','info');
    return true;
  }catch(e){showToast('Error: '+e.message,'err');return false;}
}

function clearResults(){
  ['kpiRow','legendBar','filterBar','reconTableWrap','aiSection','saveReconBar'].forEach(id=>document.getElementById(id).style.display='none');
  document.getElementById('aiVerifyWrap').style.display='none';
  _claims=[];_allBankRows=[];_usedBankIds=[];_aiCombos=[];_aiClaimIdxSet=new Set();
}

function refreshReconciledKpi(){
  let reconciledCount=0, partialCount=0, outstandingAmt=0;
  _claims.forEach(c=>{
    if(c.match_status==='manual-matched' || c.match_status==='saved'){
      reconciledCount++;
    } else if(c.match_status==='partial-matched'){
      partialCount++;
      outstandingAmt+=parseFloat(c.remaining_balance||0);
    } else if(c.match_status==='unmatched'){
      outstandingAmt+=parseFloat(c.claim_total||0);
    }
  });
  document.getElementById('kReconciled').textContent=reconciledCount;
  document.getElementById('kPartial').textContent=partialCount;
  document.getElementById('kOutstandingAmt').textContent='LKR '+fmtN(outstandingAmt);
}

async function runReconcile(){
  _accountId=parseInt(document.getElementById('accountSelect').value)||0;
  if(!_accountId){showToast('Please select a bank account first','err');return;}
  if(!(await saveSettings(false))) return;   /* reconcile always runs on the saved settings */
  document.getElementById('runBtn').disabled=true;
  document.getElementById('loadingSpinner').style.display='';
  clearResults();
  try{
    const res=await fetch(_page+'?ajax=reconcile&account_id='+_accountId);
    const data=await res.json();
    if(!data.ok)throw new Error(data.msg||'Failed');
    _claims=data.claims||[];
    _allBankRows=data.bank_rows||[];
    _usedBankIds=data.used_bank_ids||[];

    if(!_claims.length){showToast('No claim data found.','err');return;}

    /* Each claim now arrives with: recon_entries[] (every bank reconcile
       applied so far), reconciled_total, remaining_balance, match_status
       already computed server-side ('unmatched' | 'partial-matched' | 'manual-matched'). */
    let newAutoCount=0, restoredEntryCount=0;
    _claims.forEach(c=>{
      c.recon_entries=c.recon_entries||[];
      c.recon_entries.forEach(en=>{ en.is_new_match ? newAutoCount++ : restoredEntryCount++; });
    });

    /* ── KPI counts ── */
    let matched=0,unmatched=0,partial=0,claimTotal=0,matchedAmt=0;
    _claims.forEach(c=>{
      claimTotal+=parseFloat(c.claim_total||0);
      (c.recon_entries||[]).forEach(en=>{ if(en.is_new_match){matched++;matchedAmt+=parseFloat(en.applied_amount||0);} });
      if(c.match_status==='unmatched') unmatched++;
      else if(c.match_status==='partial-matched') partial++;
    });

    document.getElementById('kClaims').textContent=_claims.length;
    document.getElementById('kMatched').textContent=matched;
    document.getElementById('kUnmatched').textContent=unmatched;
    document.getElementById('kTotal').textContent='LKR '+fmtN(claimTotal);
    document.getElementById('kAiCombos').textContent='—';
    refreshReconciledKpi();

    document.getElementById('kpiRow').style.display='grid';
    document.getElementById('legendBar').style.display='flex';
    document.getElementById('filterBar').style.display='flex';
    document.getElementById('reconTableWrap').style.display='';

    const anyReconciled=_claims.some(c=>c.match_status==='manual-matched'||c.match_status==='saved');
    document.getElementById('fltManualBtn').style.display=anyReconciled?'':'none';
    document.getElementById('fltPartialBtn').style.display=partial>0?'':'none';

    _filterStatus='all';
    document.querySelectorAll('.flt-btn').forEach(b=>b.classList.remove('active'));
    document.querySelectorAll('.flt-btn')[0].classList.add('active');
    document.getElementById('searchInput').value='';
    document.getElementById('fltAiBtn').style.display='none';
    renderTable(_claims);
    updateSaveBar();

    let msg='Reconciled '+_claims.length+' claim group(s)';
    if(newAutoCount>0) msg+=' · '+newAutoCount+' auto-matched & saved to DB';
    if(restoredEntryCount>0) msg+=' · '+restoredEntryCount+' previously reconciled entr'+(restoredEntryCount===1?'y':'ies')+' restored';
    if(partial>0) msg+=' · '+partial+' partially reconciled (balance due)';
    showToast(msg);

    /* Show AI verify button only if there are truly unmatched with unused bank rows */
    if(unmatched>0){
      const unusedBank=_allBankRows.filter(br=>!_usedBankIds.includes(br.id));
      if(unusedBank.length>0){
        document.getElementById('aiVerifyWrap').style.display='flex';
        document.getElementById('aiHint').textContent=unmatched+' unmatched · '+unusedBank.length+' unused bank rows';
      }
    }
  }catch(e){
    showToast('Error: '+e.message,'err');
  }finally{
    document.getElementById('runBtn').disabled=false;
    document.getElementById('loadingSpinner').style.display='none';
  }
}

function updateSaveBar(){
  /* Only AI-combo matches need the explicit Save step — P1 auto-matches and
     manual reconciles are already committed to the DB as soon as they happen. */
  const toSave=_claims.filter(c=>c.match_status==='ai-matched');
  if(toSave.length>0){
    document.getElementById('saveReconBar').style.display='';
    document.getElementById('saveCountLabel').textContent=toSave.length+' AI-matched row(s) ready to save';
  }else{
    document.getElementById('saveReconBar').style.display='none';
  }
}

async function runAiVerify(){
  const unmatchedClaims=_claims
    .map((c,i)=>({idx:i,bdate:c.bdate,entity:c.entity,claim_total:parseFloat(c.remaining_balance??c.claim_total??0)}))
    .filter((_,i)=>_claims[i].match_status==='unmatched');
  const unusedBankRows=_allBankRows
    .filter(br=>!_usedBankIds.includes(br.id))
    .map(br=>({id:br.id,transaction_date:br.transaction_date,description:(br.description||'').slice(0,80),credit:parseFloat(br.credit||0),debit:parseFloat(br.debit||0),balance:parseFloat(br.balance||0),reference:br.reference||br.cheque_no||''}));

  if(!unmatchedClaims.length||!unusedBankRows.length){showToast('Nothing to verify.','err');return;}

  document.getElementById('aiBtn').disabled=true;
  document.getElementById('aiLoadingSpinner').style.display='flex';
  document.getElementById('aiHint').textContent='Asking AI…';

  try{
    const res=await fetch(_page+'?ajax=ai_verify',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({unmatched_claims:unmatchedClaims,unmatched_bank_rows:unusedBankRows})});
    const data=await res.json();
    if(!data.ok)throw new Error(data.msg||'AI failed');

    _aiCombos=data.combos||[];
    _aiClaimIdxSet=new Set();
    _aiCombos.forEach(combo=>(combo.claim_indices||[]).forEach(i=>_aiClaimIdxSet.add(i)));
    _aiClaimIdxSet.forEach(i=>{if(_claims[i])_claims[i].match_status='ai-matched';});

    document.getElementById('kAiCombos').textContent=_aiCombos.length;
    document.getElementById('kUnmatched').textContent=_claims.filter(c=>c.match_status==='unmatched').length;

    if(_aiCombos.length>0){
      renderAiCombos();
      document.getElementById('aiSection').style.display='';
      document.getElementById('fltAiBtn').style.display='';
      showToast('AI found '+_aiCombos.length+' combination match(es)!','ai');
    }else{
      showToast('AI found no combination matches.','err');
    }

    document.getElementById('aiHint').textContent=_aiCombos.length+' AI combo(s) found';
    updateSaveBar();
    renderTable(_claims);
  }catch(e){
    showToast('AI Error: '+e.message,'err');
    document.getElementById('aiHint').textContent='AI failed.';
  }finally{
    document.getElementById('aiBtn').disabled=false;
    document.getElementById('aiLoadingSpinner').style.display='none';
  }
}

function openSaveModal(){
  const toSave=_claims.filter(c=>c.match_status==='ai-matched');
  if(!toSave.length){showToast('Nothing new to save. Previously reconciled items are already in the database.','info');return;}
  document.getElementById('saveModalDesc').value=document.getElementById('saveDesc').value||'';
  document.getElementById('saveModalSummary').innerHTML=
    '<strong>'+toSave.length+' claim group(s) will be saved:</strong>'+
    '<div style="margin-top:8px;display:flex;gap:12px;flex-wrap:wrap;">'+
    '<span style="font-size:12px;color:#4c1d95;"><i class="fa-solid fa-wand-magic-sparkles"></i> AI Combos: '+toSave.length+'</span>'+
    '</div>';
  document.getElementById('saveModal').style.display='flex';
}
function closeSaveModal(){document.getElementById('saveModal').style.display='none';}

async function executeSave(){
  const desc=document.getElementById('saveModalDesc').value.trim();
  const btn=document.getElementById('saveModalBtn');
  btn.disabled=true;
  btn.innerHTML='<span class="spinner-sm"></span> Saving…';

  const reconcile=[];
  const processed=new Set();

  _claims.forEach((c,idx)=>{
    if(c.match_status!=='ai-matched') return;
    const combo=_aiCombos.find(cb=>(cb.claim_indices||[]).includes(idx));
    if(!combo||processed.has('ai_'+combo.bank_row_id)) return;
    processed.add('ai_'+combo.bank_row_id);
    const lines=combo.claim_indices.map(i=>({
      bdate:_claims[i].bdate, entity:_claims[i].entity,
      claim_total:parseFloat(_claims[i].claim_total||0),
      claim_count:parseInt(_claims[i].claim_count||1),
      claim_types:_claims[i].claim_types||'',
      customers:_claims[i].customers||'',
      cert_amount:parseFloat(_claims[i].remaining_balance??_claims[i].claim_total??0),
      bank_ref:(combo.bank_description||'').slice(0,100),
    }));
    reconcile.push({
      bank_txn_id:combo.bank_row_id, bank_field:combo.bank_field,
      bank_amount:combo.bank_amount, claim_total:combo.combined_total,
      is_ai_combo:1, combo_claim_count:combo.claim_indices.length, lines,
    });
  });

  if(!reconcile.length){
    closeSaveModal();
    showToast('Nothing new to save.','info');
    btn.disabled=false;
    btn.innerHTML='<i class="fa-solid fa-circle-check"></i> Confirm & Save';
    return;
  }

  try{
    const res=await fetch(_page+'?ajax=save_reconcile',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({account_id:_accountId,reconcile,description:desc})});
    const data=await res.json();
    if(!data.ok) throw new Error(data.msg||'Save failed');
    closeSaveModal();
    showToast('Saved '+data.saved+' reconciliation record(s) successfully!','');
    /* Mark saved AI-combo claims as fully handled */
    _claims.forEach(c=>{
      if(c.match_status==='ai-matched') c.match_status='saved';
    });
    renderTable(_claims);
    updateSaveBar();
    refreshReconciledKpi();
  }catch(e){
    showToast('Save failed: '+e.message,'err');
  }finally{
    btn.disabled=false;
    btn.innerHTML='<i class="fa-solid fa-circle-check"></i> Confirm & Save';
  }
}

function openManualModal(claimIdx){
  _mrClaimIdx=claimIdx;
  _mrSelectedTxn=null;
  const c=_claims[claimIdx];
  const isPartial = c.match_status==='partial-matched';
  const outstanding = parseFloat((isPartial?c.remaining_balance:c.claim_total)||0);

  document.getElementById('mr_entity').textContent=c.entity||'—';
  document.getElementById('mr_bdate').textContent=fmtDate(c.bdate);
  document.getElementById('mr_claim_total').textContent='LKR '+fmtN(c.claim_total||0);
  document.getElementById('mr_claim_count').textContent=c.claim_count||0;
  document.getElementById('mr_already_paid').textContent='LKR '+fmtN(c.reconciled_total||0);
  document.getElementById('mr_outstanding').textContent='LKR '+fmtN(outstanding);
  document.getElementById('claimStrip').className='claim-strip'+(isPartial?' claim-strip-partial':'');
  document.getElementById('claimStripTitle').innerHTML=isPartial
    ? '<i class="fa-solid fa-circle-half-stroke"></i> Claim Group — Adding another reconcile to clear balance'
    : '<i class="fa-solid fa-file-invoice"></i> Claim Group';
  document.getElementById('manualModalSub').textContent='Entity: '+(c.entity||'')+'  |  Outstanding: LKR '+fmtN(outstanding);
  document.getElementById('mrSearch').value='';
  document.getElementById('mrAmtSearch').value=outstanding.toFixed(2);
  document.getElementById('certArea').style.display='none';
  document.getElementById('mrSaveBtn').disabled=true;
  document.getElementById('mrSelectedInfo').textContent='';
  document.getElementById('noTxnMsg').style.display='none';
  document.getElementById('manualModal').style.display='flex';
  loadBankTxns();
}
function closeManualModal(){document.getElementById('manualModal').style.display='none';}

let _searchDebounce=null;
function debounceSearch(){
  clearTimeout(_searchDebounce);
  _searchDebounce=setTimeout(loadBankTxns,400);
}

async function loadBankTxns(){
  if(!_accountId) return;
  const search=encodeURIComponent(document.getElementById('mrSearch').value||'');
  const amt=parseFloat(document.getElementById('mrAmtSearch').value||0)||0;
  const tbody=document.getElementById('btxnBody');
  tbody.innerHTML='<tr><td colspan="7" style="padding:20px;text-align:center;"><span class="spinner"></span></td></tr>';
  try{
    const res=await fetch(_page+'?ajax=bank_transactions&account_id='+_accountId+'&search='+search+'&amount='+amt);
    const data=await res.json();
    const rows=data.rows||[];
    if(!rows.length){
      tbody.innerHTML='<tr><td colspan="7" style="padding:20px;text-align:center;color:var(--muted);font-size:12px;font-style:italic;">No matching UNILEVER transactions found.</td></tr>';
      document.getElementById('noTxnMsg').style.display='';
      return;
    }
    document.getElementById('noTxnMsg').style.display='none';
    let h='';
    rows.forEach(br=>{
      const remBal=br.reconcile_balance!==null?parseFloat(br.reconcile_balance):null;
      const txnDate=br.transaction_date?fmtDate(br.transaction_date):'—';
      h+='<tr data-id="'+br.id+'" data-txn-date="'+esc(br.transaction_date||'')+'" data-credit="'+parseFloat(br.credit||0)+'" data-debit="'+parseFloat(br.debit||0)+'" data-balance="'+parseFloat(br.balance||0)+'" data-rembal="'+(remBal!==null?remBal:'')+'" data-desc="'+esc((br.description||'').slice(0,40))+'" data-ref="'+esc(br.reference||br.cheque_no||'')+'" onclick="selectBankTxn(this)">' +
        '<td style="font-family:var(--mono);font-size:11px;font-weight:700;white-space:nowrap;">'+esc(txnDate)+'</td>'+
        '<td style="font-size:11px;max-width:200px;">'+esc((br.description||'').slice(0,55))+'</td>'+
        '<td style="font-size:11px;"><span class="chip chip-ref">'+esc((br.reference||br.cheque_no||'—').slice(0,20))+'</span></td>'+
        '<td style="text-align:right;">'+amtCell(br.debit,'amt-neg')+'</td>'+
        '<td style="text-align:right;font-weight:900;">'+amtCell(br.credit,'amt-pos')+'</td>'+
        '<td style="text-align:right;">'+amtCell(br.balance,'')+'</td>'+
        '<td style="text-align:right;">'+(remBal!==null?'<span class="amt" style="color:'+(remBal<=0?'var(--green)':'var(--amber)')+'">LKR '+fmtN(remBal)+'</span>':'<span style="color:#9ca3af;font-size:11px;">—</span>')+'</td>'+
        '</tr>';
    });
    tbody.innerHTML=h;
  }catch(e){tbody.innerHTML='<tr><td colspan="7" style="color:red;padding:12px;">Error: '+esc(e.message)+'</td></tr>';}
}

function selectBankTxn(tr){
  document.querySelectorAll('#btxnBody tr').forEach(r=>r.classList.remove('selected'));
  tr.classList.add('selected');
  const id     =parseInt(tr.dataset.id);
  const txnDate=tr.dataset.txnDate||'';
  const credit =parseFloat(tr.dataset.credit||0);
  const debit  =parseFloat(tr.dataset.debit||0);
  const balance=parseFloat(tr.dataset.balance||0);
  const remBal =tr.dataset.rembal!==''?parseFloat(tr.dataset.rembal):null;
  _mrSelectedTxn={id, txnDate, credit, debit, balance, remBal, desc:tr.dataset.desc, ref:tr.dataset.ref};

  const fieldSel=document.getElementById('mrField');
  if(credit>0) fieldSel.value='credit';
  else if(debit>0) fieldSel.value='debit';
  else fieldSel.value='balance';

  const c=_claims[_mrClaimIdx];
  const outstanding=parseFloat((c.match_status==='partial-matched'?c.remaining_balance:c.claim_total)||0);
  document.getElementById('mrCertAmt').value=outstanding.toFixed(2);
  document.getElementById('mrDesc').value='';
  document.getElementById('certArea').style.display='';
  document.getElementById('mrSaveBtn').disabled=false;
  document.getElementById('mrSelectedInfo').textContent='Selected: '+fmtDate(txnDate)+' '+_mrSelectedTxn.desc;
  updateBalance();
}

function updateBalance(){
  if(!_mrSelectedTxn) return;
  const field=document.getElementById('mrField').value;
  const certAmt=parseFloat(document.getElementById('mrCertAmt').value||0);
  const fieldVal=_mrSelectedTxn.remBal!==null?_mrSelectedTxn.remBal:(_mrSelectedTxn[field]||0);
  const remaining=Math.round((fieldVal-certAmt)*100)/100;
  const pill=document.getElementById('balPill');
  if(remaining<=0){
    pill.className='balance-pill zero';
    pill.innerHTML='<i class="fa-solid fa-circle-check"></i> Bank Row Fully Applied';
  }else{
    pill.className='balance-pill positive';
    pill.innerHTML='<i class="fa-solid fa-scale-unbalanced"></i> Bank Row Balance: LKR '+fmtN(remaining);
  }
}

async function saveManualReconcile(){
  if(!_mrSelectedTxn||_mrClaimIdx<0) return;
  const certAmt=parseFloat(document.getElementById('mrCertAmt').value||0);
  if(!certAmt||certAmt<=0){showToast('Enter a valid cert amount','err');return;}
  const field=document.getElementById('mrField').value;
  const desc=document.getElementById('mrDesc').value.trim();
  const c=_claims[_mrClaimIdx];

  const btn=document.getElementById('mrSaveBtn');
  btn.disabled=true;
  btn.innerHTML='<span class="spinner-sm"></span> Saving…';

  try{
    const res=await fetch(_page+'?ajax=manual_reconcile',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({
      account_id:_accountId, bank_txn_id:_mrSelectedTxn.id,
      bank_field:field, cert_amount:certAmt, description:desc,
      bank_ref:_mrSelectedTxn.ref||'',
      claim_line:{bdate:c.bdate,entity:c.entity,claim_total:parseFloat(c.claim_total||0),claim_count:parseInt(c.claim_count||1),claim_types:c.claim_types||'',customers:c.customers||''},
    })});
    const data=await res.json();
    if(!data.ok) throw new Error(data.msg||'Save failed');

    /* Append this new reconcile entry onto the claim's list (it may now be
       the 2nd, 3rd... entry against the same ledger row) */
    c.recon_entries = c.recon_entries || [];
    c.recon_entries.push({
      log_id: data.log_id,
      bank_txn_id: _mrSelectedTxn.id,
      bank_field: field,
      bank_amount: parseFloat(_mrSelectedTxn[field]||0),
      applied_amount: certAmt,
      bank_ref: _mrSelectedTxn.ref||'',
      log_desc: desc,
      reconciled_at: new Date().toISOString(),
      bank_txn_date: _mrSelectedTxn.txnDate,
      bank_desc: _mrSelectedTxn.desc,
      bank_credit: _mrSelectedTxn.credit,
      bank_debit: _mrSelectedTxn.debit,
      bank_balance: _mrSelectedTxn.balance,
      bank_remaining: data.remaining_balance||0,
      fully_reconciled: !!data.fully_reconciled,
      is_ai_combo: false,
      is_new_match: true,
    });
    c.reconciled_total  = (parseFloat(c.reconciled_total||0) + certAmt);
    c.remaining_balance = Math.max(0, Math.round((parseFloat(c.claim_total||0) - c.reconciled_total)*100)/100);
    c.match_status = c.remaining_balance<=0.009 ? 'manual-matched' : 'partial-matched';

    _usedBankIds.push(_mrSelectedTxn.id);

    document.getElementById('kUnmatched').textContent=_claims.filter(c=>c.match_status==='unmatched').length;
    document.getElementById('fltManualBtn').style.display='';
    document.getElementById('fltPartialBtn').style.display=_claims.some(cc=>cc.match_status==='partial-matched')?'':'none';
    updateSaveBar();
    refreshReconciledKpi();

    closeManualModal();
    renderTable(_claims);
    const balMsg = c.match_status==='manual-matched'
      ? 'Ledger row fully reconciled!'
      : 'Remaining ledger balance: LKR '+fmtN(c.remaining_balance)+' — add another reconcile to clear it.';
    showToast('Reconcile saved. '+balMsg,'info');
  }catch(e){
    showToast('Error: '+e.message,'err');
  }finally{
    btn.disabled=false;
    btn.innerHTML='<i class="fa-solid fa-circle-check"></i> Save Reconcile';
  }
}

async function deleteReconEntry(claimIdx,logId){
  const c=_claims[claimIdx];
  if(!logId){showToast('No log to delete','err');return;}
  if(!confirm('Remove this bank reconcile from the ledger row? The balance will become outstanding again.'))return;

  try{
    const res=await fetch(_page+'?ajax=delete_manual_reconcile',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({log_id:logId})});
    const data=await res.json();
    if(!data.ok) throw new Error(data.msg||'Delete failed');

    const removed=(c.recon_entries||[]).find(en=>en.log_id===logId);
    c.recon_entries=(c.recon_entries||[]).filter(en=>en.log_id!==logId);
    if(removed) _usedBankIds=_usedBankIds.filter(id=>id!==removed.bank_txn_id);

    c.reconciled_total = (c.recon_entries||[]).reduce((s,en)=>s+parseFloat(en.applied_amount||0),0);
    c.remaining_balance = Math.max(0, Math.round((parseFloat(c.claim_total||0) - c.reconciled_total)*100)/100);
    c.match_status = c.recon_entries.length===0 ? 'unmatched'
                    : (c.remaining_balance<=0.009 ? 'manual-matched' : 'partial-matched');

    document.getElementById('kUnmatched').textContent=_claims.filter(cc=>cc.match_status==='unmatched').length;
    document.getElementById('fltPartialBtn').style.display=_claims.some(cc=>cc.match_status==='partial-matched')?'':'none';
    updateSaveBar();
    refreshReconciledKpi();
    renderTable(_claims);
    showToast('Reconcile entry removed — ledger balance updated','info');
  }catch(e){
    showToast('Error: '+e.message,'err');
  }
}

function renderAiCombos(){
  const grid=document.getElementById('aiComboGrid');
  document.getElementById('aiComboCount').textContent=_aiCombos.length+' combo(s) found';
  if(!_aiCombos.length){grid.innerHTML='<div class="ai-empty">No combination matches found.</div>';return;}
  let h='';
  _aiCombos.forEach((combo,ci)=>{
    const n=(combo.claim_indices||[]).length;
    const fc='ai-bank-field-'+(combo.bank_field||'credit');
    const fl=(combo.bank_field||'credit').toUpperCase();
    let claimsHtml='';
    (combo.claim_indices||[]).forEach((idx,k)=>{
      const c=_claims[idx];
      const tot=combo.claim_totals?combo.claim_totals[k]:(c?parseFloat(c.claim_total||0):0);
      const ent=combo.claim_entities?combo.claim_entities[k]:(c?c.entity:'');
      const dt =combo.claim_dates   ?combo.claim_dates[k]   :(c?c.bdate :'');
      if(k>0)claimsHtml+='<div class="ai-plus">+</div>';
      claimsHtml+='<div class="ai-claim-item"><div class="ai-claim-meta"><strong>'+esc(ent||'—')+'</strong>'+esc(fmtDate(dt))+'</div><span class="ai-claim-amt">LKR '+fmtN(tot)+'</span></div>';
    });
    h+='<div class="ai-combo-card"><div class="ai-combo-head"><span class="ai-combo-badge"><i class="fa-solid fa-wand-magic-sparkles"></i> '+n+'-Claim Combo #'+(ci+1)+'</span><span style="font-size:11px;color:var(--ink3);">Combined: </span><span class="ai-combo-total">LKR '+fmtN(combo.combined_total||0)+'</span><span class="ai-ok-pill"><i class="fa-solid fa-circle-check"></i> Exact Match</span></div>'+
    '<div class="ai-combo-body"><div class="ai-claims-col"><div class="ai-claims-lbl"><i class="fa-solid fa-file-invoice"></i> Claim Groups</div>'+claimsHtml+'</div>'+
    '<div class="ai-arrow">→</div>'+
    '<div class="ai-bank-col"><div class="ai-bank-lbl"><i class="fa-solid fa-building-columns"></i> Bank Row</div><div class="ai-bank-card"><span class="ai-bank-field-badge '+fc+'">'+fl+'</span><span class="ai-bank-amt">LKR '+fmtN(combo.bank_amount||0)+'</span><div class="ai-bank-desc">'+esc((combo.bank_description||'').slice(0,60))+'</div><div class="ai-bank-date">'+esc(fmtDate(combo.bank_transaction_date))+'</div></div></div>'+
    '</div></div>';
  });
  grid.innerHTML=h;
}

function renderTable(claims){
  const tbody=document.getElementById('reconBody');
  if(!claims.length){
    tbody.innerHTML='<tr class="no-results-row"><td colspan="13">No records match the current filter.</td></tr>';
    document.getElementById('rowCountBadge').textContent='(0 rows)';
    return;
  }
  let h='';
  claims.forEach((c,idx)=>{
    const status=c.match_status||'unmatched';
    const claimTot=parseFloat(c.claim_total||0);
    const entries=c.recon_entries||[];
    const isAi     = status==='ai-matched';
    const isSaved  = status==='saved';
    const isFull   = status==='manual-matched';
    const isPartial= status==='partial-matched';
    const reconciledTotal = parseFloat(c.reconciled_total||0);
    const remainingBal    = parseFloat(c.remaining_balance||0);

    /* ── Status badge ── */
    let badge;
    if(isSaved)
      badge='<span class="status-badge sb-saved"><i class="fa-solid fa-circle-check"></i> Saved</span>';
    else if(isAi)
      badge='<span class="status-badge sb-ai-matched"><i class="fa-solid fa-wand-magic-sparkles"></i> AI Match</span>';
    else if(isFull)
      badge='<span class="status-badge sb-restored"><i class="fa-solid fa-circle-check"></i> Fully Reconciled</span>';
    else if(isPartial)
      badge='<span class="status-badge sb-partial"><i class="fa-solid fa-circle-half-stroke"></i> Partial · Bal LKR '+fmtN(remainingBal)+'</span>';
    else
      badge='<span class="status-badge sb-unmatched"><i class="fa-solid fa-circle-xmark"></i> Unmatched</span>';

    const entityChip=c.entity?'<span class="chip chip-violet">'+esc(c.entity)+'</span>':'<span style="color:var(--muted)">—</span>';

    /* ── Bank reconcile(s) column — one cell listing every entry applied ── */
    let bankCell;
    if(isSaved){
      bankCell='<td colspan="5"><span style="font-size:11px;color:#059669;font-weight:700;padding:8px 12px;display:block;"><i class="fa-solid fa-circle-check"></i> Saved to database</span></td>';
    } else if(isAi){
      const combo=_aiCombos.find(cb=>(cb.claim_indices||[]).includes(idx));
      if(combo){
        bankCell='<td colspan="5"><div style="font-size:11px;max-width:280px;color:#7c3aed;padding:6px 4px;">'+esc((combo.bank_description||'').slice(0,55))+
          '<br><span style="font-size:10px;color:#a855f7;font-weight:700;">Shared '+combo.claim_indices.length+'-claim combo · AI Combo #'+(_aiCombos.indexOf(combo)+1)+'</span></div></td>';
      }else{
        bankCell='<td colspan="5"><span class="no-bank-msg">AI matched (see AI section)</span></td>';
      }
    } else if(entries.length>0){
      let list='<div class="recon-entry-list">';
      entries.forEach(en=>{
        const cls='recon-entry-item'+(en.is_new_match?' re-new':'');
        list+='<div class="'+cls+'">'+
          '<div class="re-meta"><span class="re-date">'+esc(fmtDate(en.bank_txn_date))+'</span> · <span class="re-desc">'+esc((en.bank_desc||'').slice(0,40))+'</span>'+
          (en.bank_ref?' · <span class="chip chip-ref">'+esc(String(en.bank_ref).slice(0,16))+'</span>':'')+'</div>'+
          '<span class="re-amt">LKR '+fmtN(en.applied_amount||0)+'</span>'+
          '<button class="re-del-btn" title="Remove this reconcile" onclick="deleteReconEntry('+idx+','+en.log_id+')"><i class="fa-solid fa-xmark"></i></button>'+
          '</div>';
      });
      if(isPartial){
        list+='<div class="re-outstanding-note"><i class="fa-solid fa-triangle-exclamation"></i> Outstanding: LKR '+fmtN(remainingBal)+' — add another reconcile to clear</div>';
      }
      list+='</div>';
      bankCell='<td colspan="5" style="padding:6px 8px;">'+list+'</td>';
    } else {
      bankCell='<td colspan="5"><span class="no-bank-msg">✗ No match — LKR '+fmtN(claimTot)+'</span></td>';
    }

    /* ── Tally column ── */
    let tallyHtml='<td class="tally-cell">';
    if(isPartial){
      tallyHtml+='<div class="tally-box-partial">'+
        '<div class="tp-head"><i class="fa-solid fa-circle-half-stroke"></i>&nbsp;Partial · '+entries.length+' entr'+(entries.length===1?'y':'ies')+'</div>'+
        '<div class="tp-row"><span class="tp-label">Claim Total</span><span class="tp-val">LKR '+fmtN(claimTot)+'</span></div>'+
        '<div class="tp-row"><span class="tp-label">Reconciled</span><span class="tp-val">LKR '+fmtN(reconciledTotal)+'</span></div>'+
        '<div class="tp-row"><span class="tp-label">Balance Due</span><span class="tp-val tp-bal">LKR '+fmtN(remainingBal)+'</span></div>'+
        '</div>';
    } else if(isFull){
      tallyHtml+='<div class="tally-box-restored">'+
        '<div class="tr-head"><i class="fa-solid fa-circle-check"></i>&nbsp;Fully Reconciled · '+entries.length+' entr'+(entries.length===1?'y':'ies')+'</div>'+
        '<div class="tr-row"><span class="tr-label">Claim Total</span><span class="tr-val">LKR '+fmtN(claimTot)+'</span></div>'+
        '<div class="tr-row"><span class="tr-label">Reconciled</span><span class="tr-val">LKR '+fmtN(reconciledTotal)+'</span></div>'+
        '</div>';
    } else if(isSaved){
      tallyHtml+='<div class="tally-box-saved">'+
        '<div class="ts-head"><i class="fa-solid fa-circle-check"></i>&nbsp;Saved</div>'+
        '<div class="ts-row"><span class="ts-label">Claim</span><span class="ts-val">LKR '+fmtN(claimTot)+'</span></div>'+
        '</div>';
    } else {
      tallyHtml+='<span style="color:#9ca3af;font-size:11px;">—</span>';
    }
    tallyHtml+='</td>';

    /* ── Action column ── */
    let actionHtml='<td style="text-align:center;vertical-align:middle;white-space:nowrap;padding:8px;">';
    if(status==='unmatched'){
      actionHtml+='<button class="btn-manual" onclick="openManualModal('+idx+')"><i class="fa-solid fa-hand-pointer"></i> Manual Reconcile</button>';
    } else if(isPartial){
      actionHtml+='<button class="btn-add-more" onclick="openManualModal('+idx+')"><i class="fa-solid fa-circle-plus"></i> Add Reconcile</button>';
    } else if(isFull){
      actionHtml+='<span style="font-size:11px;color:#059669;font-weight:700;"><i class="fa-solid fa-circle-check"></i> Fully Reconciled</span>';
    } else if(isSaved){
      actionHtml+='<span style="font-size:11px;color:#059669;font-weight:700;"><i class="fa-solid fa-circle-check"></i> Saved</span>';
    } else {
      actionHtml+='<span style="font-size:11px;color:var(--muted);">—</span>';
    }
    actionHtml+='</td>';

    /* ── Row class ── */
    let rowClass='row-unmatched';
    if(isSaved)        rowClass='row-saved';
    else if(isAi)      rowClass='row-ai-matched';
    else if(isFull)    rowClass='row-restored';
    else if(isPartial) rowClass='row-partial-matched';

    const refBits=entries.map(en=>en.bank_ref||'').join(' ');
    const searchData=esc((c.bdate+' '+(c.entity||'')+' '+(c.customers||'')+' '+refBits).toLowerCase());

    h+='<tr class="'+rowClass+'" data-status="'+status+'" data-search="'+searchData+'">' +
      '<td style="font-size:11px;color:var(--muted);font-family:var(--mono);">'+(idx+1)+'</td>'+
      '<td>'+badge+'</td>'+
      '<td style="font-family:var(--mono);font-size:12px;font-weight:700;white-space:nowrap;">'+esc(fmtDate(c.bdate))+'</td>'+
      '<td>'+entityChip+'</td>'+
      '<td style="text-align:center;font-weight:800;color:var(--blue);">'+(c.claim_count||0)+'</td>'+
      '<td style="text-align:right;"><span class="amt" style="font-size:13px;">LKR '+fmtN(claimTot)+'</span></td>'+
      bankCell+
      tallyHtml+
      actionHtml+
      '</tr>';
  });

  tbody.innerHTML=h;
  document.getElementById('rowCountBadge').textContent='('+claims.length+' claim groups)';
}

function setFilter(status,btn){
  _filterStatus=status;
  document.querySelectorAll('.flt-btn').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
  applyFilters();
}

function applyFilters(){
  const q=(document.getElementById('searchInput').value||'').toLowerCase().trim();
  const rows=document.querySelectorAll('#reconBody tr[data-status]');
  let visible=0;
  rows.forEach(tr=>{
    const ok=(_filterStatus==='all'||tr.dataset.status===_filterStatus)&&(!q||(tr.dataset.search||'').includes(q));
    tr.style.display=ok?'':'none';
    if(ok)visible++;
  });
  document.getElementById('rowCountBadge').textContent='('+visible+' claim groups)';
  const tbody=document.getElementById('reconBody');
  const existing=tbody.querySelector('.no-results-row');
  if(!visible&&!existing){const tr=document.createElement('tr');tr.className='no-results-row';tr.innerHTML='<td colspan="13">No records match the current filter / search.</td>';tbody.appendChild(tr);}
  else if(visible&&existing)existing.remove();
}

loadAccounts();
</script>

<?php include 'footer.php'; ?>