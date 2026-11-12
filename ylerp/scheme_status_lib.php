<?php
/** scheme_status_lib.php — tables + helpers shared by scheme_status_report.php (import) and scheme_status_list.php (report) */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS scheme_status_uploads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_name VARCHAR(255) NULL, moc_ref VARCHAR(30) NULL, report_date DATE NULL,
    total_rows INT NOT NULL DEFAULT 0, imported_rows INT NOT NULL DEFAULT 0, duplicate_rows INT NOT NULL DEFAULT 0,
    imported_by VARCHAR(100) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS scheme_status_items (
    id INT AUTO_INCREMENT PRIMARY KEY, upload_id INT NOT NULL,
    activity_type VARCHAR(50) NOT NULL, activity_code VARCHAR(100) NOT NULL, activity_desc VARCHAR(255) NULL, moc_ref VARCHAR(30) NOT NULL DEFAULT '',
    start_date DATE NULL, end_date DATE NULL,
    total_budget DECIMAL(15,2) NULL, rs_budget_amount DECIMAL(15,2) NULL, rs_approval_amount DECIMAL(15,2) NULL,
    tsi_approval_date VARCHAR(30) NULL, tsi_approval_amount DECIMAL(15,2) NULL,
    payout_amount DECIMAL(15,2) NULL, payout_close_date DATE NULL, payout_close_amount DECIMAL(15,2) NULL, pending_payout_amount DECIMAL(15,2) NULL,
    claim_moc_ref VARCHAR(30) NULL, claim_date DATE NULL, claim_amount DECIMAL(15,2) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    row_hash CHAR(32) NULL, UNIQUE KEY uq_row_hash (row_hash), INDEX idx_upload (upload_id), INDEX idx_moc (moc_ref), INDEX idx_code (activity_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function ss_user() { if (session_status() === PHP_SESSION_NONE) @session_start(); return $_SESSION['username'] ?? 'user'; }
function ss_num($v) { if ($v === null) return null; $v = trim((string)$v); if ($v === '' || $v === '-' ) return null; $v = str_replace(',', '', $v); return is_numeric($v) ? round((float)$v, 2) : null; }
function ss_date($v) { $v = trim((string)$v); if ($v === '' || $v === '-') return null; return preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? substr($v, 0, 10) : null; }
function ss_txt($v, $n = 255) { $v = trim((string)$v); return ($v === '' || $v === '-') ? '' : mb_substr($v, 0, $n); }
/** one posted row → clean row (the Sr No is never read) */
function ss_clean($r) {
    return ['activity_type' => ss_txt($r['activity_type'] ?? '', 50), 'activity_code' => ss_txt($r['activity_code'] ?? '', 100), 'activity_desc' => ss_txt($r['activity_desc'] ?? ''),
            'moc_ref' => ss_txt($r['moc_ref'] ?? '', 30), 'start_date' => ss_date($r['start_date'] ?? ''), 'end_date' => ss_date($r['end_date'] ?? ''),
            'total_budget' => ss_num($r['total_budget'] ?? null), 'rs_budget_amount' => ss_num($r['rs_budget_amount'] ?? null), 'rs_approval_amount' => ss_num($r['rs_approval_amount'] ?? null),
            'tsi_approval_date' => ss_txt($r['tsi_approval_date'] ?? '', 30), 'tsi_approval_amount' => ss_num($r['tsi_approval_amount'] ?? null),
            'payout_amount' => ss_num($r['payout_amount'] ?? null), 'payout_close_date' => ss_date($r['payout_close_date'] ?? ''), 'payout_close_amount' => ss_num($r['payout_close_amount'] ?? null),
            'pending_payout_amount' => ss_num($r['pending_payout_amount'] ?? null), 'claim_moc_ref' => ss_txt($r['claim_moc_ref'] ?? '', 30), 'claim_date' => ss_date($r['claim_date'] ?? ''), 'claim_amount' => ss_num($r['claim_amount'] ?? null)];
}
function ss_sql_val($conn, $k, $v) {
    if ($v === null || $v === '') return in_array($k, ['activity_type', 'activity_code', 'moc_ref'], true) ? "''" : 'NULL';
    return is_float($v) || is_int($v) ? (string)$v : "'" . mysqli_real_escape_string($conn, $v) . "'";
}
/** duplicate key = EVERY column of the row (the Excel's Sr No is not one of them): same type, code, description, MOC, dates, all amounts, claim ... = duplicate */
function ss_key($r) {
    $p = [];
    foreach (['activity_type', 'activity_code', 'activity_desc', 'moc_ref', 'start_date', 'end_date', 'total_budget', 'rs_budget_amount', 'rs_approval_amount', 'tsi_approval_date', 'tsi_approval_amount',
              'payout_amount', 'payout_close_date', 'payout_close_amount', 'pending_payout_amount', 'claim_moc_ref', 'claim_date', 'claim_amount'] as $c) {
        $v = $r[$c] ?? null;
        if ($v === null || $v === '') { $p[] = ''; continue; }
        $p[] = is_numeric($v) && !preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$v) && in_array($c, ['total_budget', 'rs_budget_amount', 'rs_approval_amount', 'tsi_approval_amount', 'payout_amount', 'payout_close_amount', 'pending_payout_amount', 'claim_amount'], true)
             ? number_format((float)$v, 2, '.', '') : strtoupper(trim((string)$v));
    }
    return md5(implode('|', $p));
}
/* one-time upgrade: the old unique key (type + code + MOC) is replaced by a unique key on the whole row */
$__rh = @mysqli_query($conn, "SHOW COLUMNS FROM scheme_status_items LIKE 'row_hash'");
if ($__rh && !mysqli_num_rows($__rh)) {
    @mysqli_query($conn, "ALTER TABLE scheme_status_items ADD COLUMN row_hash CHAR(32) NULL");
    @mysqli_query($conn, "ALTER TABLE scheme_status_items DROP INDEX uq_scheme");
    $__q = mysqli_query($conn, "SELECT * FROM scheme_status_items");
    while ($__q && ($__x = mysqli_fetch_assoc($__q))) {
        $__h = ss_key($__x); $__id = (int)$__x['id'];
        if (!@mysqli_query($conn, "UPDATE scheme_status_items SET row_hash = '$__h' WHERE id = $__id")) @mysqli_query($conn, "UPDATE scheme_status_items SET row_hash = '" . md5($__h . $__id) . "' WHERE id = $__id");
    }
    @mysqli_query($conn, "ALTER TABLE scheme_status_items ADD UNIQUE KEY uq_row_hash (row_hash)");
}
$COLS = ['activity_type', 'activity_code', 'activity_desc', 'moc_ref', 'start_date', 'end_date', 'total_budget', 'rs_budget_amount', 'rs_approval_amount', 'tsi_approval_date', 'tsi_approval_amount',
         'payout_amount', 'payout_close_date', 'payout_close_amount', 'pending_payout_amount', 'claim_moc_ref', 'claim_date', 'claim_amount'];
function ss_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ss_m($v) { return $v === null || $v === '' ? '<span class="dash">-</span>' : number_format((float)$v, 2); }
function ss_d($v) { return $v ? date('d M Y', strtotime($v)) : '<span class="dash">-</span>'; }
