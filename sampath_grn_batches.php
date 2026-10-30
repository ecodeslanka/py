<?php
// ============================================================
//  sampath_grn_batches.php  –  Sampath GRN Summary: BATCHES
//  Page 2 of 3:  Import  |  Batches  |  All Records
//  List every upload batch; view a batch's rows; delete a row
//  or an entire batch; detect & remove DUPLICATE records.
//
//  Duplicate rule: same GRN NO, or same REF NO + VALUE.
//  The OLDEST copy (lowest id) is kept, later copies are dups.
// ============================================================
include 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── Helper: recompute totals for one batch ──────────────────
function sgs_recalc_batch($conn, $bid) {
    $bid = (int)$bid;
    $tot = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(*) c, SUM(grn_value) v, SUM(balance_value) b
         FROM sampath_grn_summary WHERE batch_id=$bid"));
    $c = (int)($tot['c'] ?? 0); $v = (float)($tot['v'] ?? 0); $b = (float)($tot['b'] ?? 0);
    mysqli_query($conn, "UPDATE sampath_grn_batches
                         SET total_records=$c, total_value=$v, total_balance=$b
                         WHERE id=$bid");
}

// ── Helper: recompute totals for every batch ────────────────
function sgs_recalc_all($conn) {
    mysqli_query($conn,
        "UPDATE sampath_grn_batches b
         LEFT JOIN (SELECT batch_id, COUNT(*) c, SUM(grn_value) v, SUM(balance_value) bal
                    FROM sampath_grn_summary GROUP BY batch_id) t ON t.batch_id = b.id
         SET b.total_records = IFNULL(t.c,0),
             b.total_value   = IFNULL(t.v,0),
             b.total_balance = IFNULL(t.bal,0)");
}

// SQL join condition that matches a row (s1) to an OLDER copy (s2)
// Same rule as the import page: same GRN NO, OR same REF NO + VALUE
$DUP_JOIN = "JOIN sampath_grn_summary s2
               ON s2.id < s1.id
              AND ( TRIM(s2.grn_no) = TRIM(s1.grn_no)
                    OR (TRIM(s2.ref_no) = TRIM(s1.ref_no) AND s2.grn_value = s1.grn_value) )";

// ── Flash message helpers (survive the redirect) ─────────────
function sgs_flash($type, $text) { $_SESSION['sgs_flash'] = ['type' => $type, 'text' => $text]; }
function sgs_take_flash() { $f = $_SESSION['sgs_flash'] ?? null; unset($_SESSION['sgs_flash']); return $f; }

/** Accepts yyyy-mm-dd (from <input type=date>) – returns Y-m-d or null */
function sgs_valid_date($d) {
    $d = trim((string)$d);
    if ($d === '') return null;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return $d;
    return false;
}

// ============================================================
//  EDIT A GRN RECORD (modal form)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_row') {
    $rid      = (int)($_POST['row_id'] ?? 0);
    $grn_no   = trim(preg_replace('/\s+/', ' ', $_POST['grn_no'] ?? ''));
    $grn_date = sgs_valid_date($_POST['grn_date'] ?? '');
    $supplier = trim(preg_replace('/\s+/', ' ', $_POST['supplier'] ?? ''));
    $ref_no   = trim(preg_replace('/\s+/', ' ', $_POST['ref_no'] ?? ''));
    $value    = round((float)str_replace(',', '', $_POST['grn_value'] ?? 0), 2);
    $settle   = round((float)str_replace(',', '', $_POST['settlement_value'] ?? 0), 2);
    $balance  = round((float)str_replace(',', '', $_POST['balance_value'] ?? 0), 2);

    $cur = $rid ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, batch_id FROM sampath_grn_summary WHERE id=$rid")) : null;
    $back = $cur ? "sampath_grn_batches.php?view_batch=" . (int)$cur['batch_id'] : "sampath_grn_batches.php";

    $errors = [];
    if (!$cur)              $errors[] = 'Record not found (it may have been deleted).';
    if ($grn_no === '')     $errors[] = 'GRN No is required.';
    if ($ref_no === '')     $errors[] = 'Ref No is required.';
    if ($supplier === '')   $errors[] = 'Supplier is required.';
    if ($grn_date === false) $errors[] = 'GRN Date is not a valid date.';
    if ($value < 0)         $errors[] = 'Value cannot be negative.';
    if ($settle < 0)        $errors[] = 'Settlement cannot be negative.';

    // Duplicate check against every OTHER record
    if (!$errors) {
        $st = mysqli_prepare($conn,
            "SELECT s.grn_no, s.ref_no, s.grn_value, b.batch_ref
             FROM sampath_grn_summary s LEFT JOIN sampath_grn_batches b ON b.id = s.batch_id
             WHERE s.id <> ? AND ( TRIM(s.grn_no) = ? OR (TRIM(s.ref_no) = ? AND s.grn_value = ?) )
             LIMIT 1");
        mysqli_stmt_bind_param($st, 'issd', $rid, $grn_no, $ref_no, $value);
        mysqli_stmt_execute($st);
        $d = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
        if ($d) {
            $errors[] = (strcasecmp(trim($d['grn_no']), $grn_no) === 0)
                ? "GRN No $grn_no already exists in batch {$d['batch_ref']}."
                : "Ref No $ref_no with value " . number_format($value, 2) . " already exists in batch {$d['batch_ref']} (GRN {$d['grn_no']}).";
        }
    }

    if ($errors) {
        sgs_flash('danger', 'Not saved: ' . htmlspecialchars(implode(' ', $errors)));
        $_SESSION['sgs_reopen'] = ['type' => 'row', 'data' => $_POST];   // reopen modal with user's input
        header("Location: $back");
        exit;
    }

    try {
        $st = mysqli_prepare($conn,
            "UPDATE sampath_grn_summary
             SET grn_no=?, grn_date=?, supplier=?, ref_no=?, grn_value=?, settlement_value=?, balance_value=?
             WHERE id=?");
        mysqli_stmt_bind_param($st, 'ssssdddi', $grn_no, $grn_date, $supplier, $ref_no, $value, $settle, $balance, $rid);
        mysqli_stmt_execute($st);
        sgs_recalc_batch($conn, (int)$cur['batch_id']);
        sgs_flash('success', "GRN <strong>" . htmlspecialchars($grn_no) . "</strong> updated. Batch totals recalculated.");
    } catch (Throwable $e) {
        sgs_flash('danger', 'Database error: ' . htmlspecialchars($e->getMessage()));
    }
    header("Location: $back&hl=$rid#row-$rid");
    exit;
}

// ============================================================
//  EDIT BATCH DETAILS (modal form)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_batch') {
    $bid         = (int)($_POST['batch_id'] ?? 0);
    $batch_ref   = trim($_POST['batch_ref'] ?? '');
    $import_date = sgs_valid_date($_POST['import_date'] ?? '');
    $notes       = trim($_POST['notes'] ?? '');
    $from_list   = !empty($_POST['from_list']);
    $back = $from_list ? "sampath_grn_batches.php" : "sampath_grn_batches.php?view_batch=$bid";

    $errors = [];
    $exists = $bid ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM sampath_grn_batches WHERE id=$bid")) : null;
    if (!$exists)                         $errors[] = 'Batch not found.';
    if ($batch_ref === '')                $errors[] = 'Batch Ref is required.';
    if (!$import_date)                    $errors[] = 'Import Date is required and must be valid.';
    if (!$errors) {
        $st = mysqli_prepare($conn, "SELECT id FROM sampath_grn_batches WHERE batch_ref=? AND id<>? LIMIT 1");
        mysqli_stmt_bind_param($st, 'si', $batch_ref, $bid);
        mysqli_stmt_execute($st);
        if (mysqli_fetch_assoc(mysqli_stmt_get_result($st))) $errors[] = "Batch Ref $batch_ref is already used by another batch.";
    }

    if ($errors) {
        sgs_flash('danger', 'Not saved: ' . htmlspecialchars(implode(' ', $errors)));
        $_SESSION['sgs_reopen'] = ['type' => 'batch', 'data' => $_POST];
        header("Location: $back");
        exit;
    }

    try {
        $st = mysqli_prepare($conn, "UPDATE sampath_grn_batches SET batch_ref=?, import_date=?, notes=? WHERE id=?");
        mysqli_stmt_bind_param($st, 'sssi', $batch_ref, $import_date, $notes, $bid);
        mysqli_stmt_execute($st);
        sgs_flash('success', "Batch <strong>" . htmlspecialchars($batch_ref) . "</strong> updated.");
    } catch (Throwable $e) {
        sgs_flash('danger', 'Database error: ' . htmlspecialchars($e->getMessage()));
    }
    header("Location: $back");
    exit;
}

// ── Remove ALL duplicates (every batch) ─────────────────────
if (isset($_GET['remove_dups_all'])) {
    mysqli_query($conn, "DELETE s1 FROM sampath_grn_summary s1 $DUP_JOIN");
    $n = mysqli_affected_rows($conn);
    sgs_recalc_all($conn);
    header("Location: sampath_grn_batches.php?dups_removed=" . max(0, (int)$n));
    exit;
}

// ── Remove duplicates inside ONE batch ──────────────────────
if (isset($_GET['remove_dups_batch']) && is_numeric($_GET['remove_dups_batch'])) {
    $bid = (int)$_GET['remove_dups_batch'];
    mysqli_query($conn, "DELETE s1 FROM sampath_grn_summary s1 $DUP_JOIN WHERE s1.batch_id=$bid");
    $n = mysqli_affected_rows($conn);
    sgs_recalc_batch($conn, $bid);
    header("Location: sampath_grn_batches.php?view_batch=$bid&dups_removed=" . max(0, (int)$n));
    exit;
}

// ── Delete a single record (recomputes parent batch totals) ─
if (isset($_GET['delete_row']) && is_numeric($_GET['delete_row'])) {
    $rid = (int)$_GET['delete_row'];
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT batch_id FROM sampath_grn_summary WHERE id=$rid"));
    $back_batch = $r ? (int)$r['batch_id'] : null;
    mysqli_query($conn, "DELETE FROM sampath_grn_summary WHERE id=$rid");
    if ($back_batch) {
        sgs_recalc_batch($conn, $back_batch);
        header("Location: sampath_grn_batches.php?view_batch=$back_batch&row_deleted=1");
        exit;
    }
    header("Location: sampath_grn_batches.php?row_deleted=1");
    exit;
}

// ── Delete an entire batch ───────────────────────────────────
if (isset($_GET['delete_batch']) && is_numeric($_GET['delete_batch'])) {
    $bid = (int)$_GET['delete_batch'];
    mysqli_query($conn, "DELETE FROM sampath_grn_summary WHERE batch_id=$bid");
    mysqli_query($conn, "DELETE FROM sampath_grn_batches WHERE id=$bid");
    header("Location: sampath_grn_batches.php?batch_deleted=1");
    exit;
}

// ── Duplicate count per batch ────────────────────────────────
$dup_by_batch = [];
$total_dups = 0;
$dq = mysqli_query($conn,
    "SELECT s1.batch_id, COUNT(DISTINCT s1.id) c
     FROM sampath_grn_summary s1 $DUP_JOIN
     GROUP BY s1.batch_id");
if ($dq) while ($d = mysqli_fetch_assoc($dq)) {
    $dup_by_batch[(int)$d['batch_id']] = (int)$d['c'];
    $total_dups += (int)$d['c'];
}

// ── Fetch all batches ─────────────────────────────────────────
// ============================================================
//  INVOICE LINK STATUS for each GRN
//  (same rule as sampath_balancing_report.php)
//   • manual  – linked by hand to an invoice (sampath_grn_invoice_links)
//   • auto    – Ref No matches a Secondary invoice bill_no
//   • excluded– removed from its invoice on the Balancing Report
//   • unlinked– no matching Secondary invoice found
// ============================================================
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sampath_grn_invoice_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    grn_summary_id INT NOT NULL,
    bill_no VARCHAR(100) NULL,
    link_type ENUM('link','exclude') NOT NULL DEFAULT 'link',
    note VARCHAR(255) NULL,
    linked_by VARCHAR(100) NULL,
    linked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_grn (grn_summary_id),
    INDEX idx_bill (bill_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$t = mysqli_query($conn, "SHOW TABLES LIKE 'secondary_invoice_import_details'");
$HAS_INVOICES = $t && mysqli_num_rows($t) > 0;

// ============================================================
//  LINK SUGGESTIONS + LINK / UNLINK BY CLICK  (JSON endpoints)
//  Tally rule: the LAST characters of the GRN Ref No are compared
//  with the end of every Secondary invoice bill_no; invoices whose
//  amount equals the GRN value are offered too.
// ============================================================
function sgs_json($arr) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

function sgs_common_tail($a, $b) {
    $a = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$a));
    $b = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$b));
    $n = 0; $i = strlen($a) - 1; $j = strlen($b) - 1;
    while ($i >= 0 && $j >= 0 && $a[$i] === $b[$j]) { $n++; $i--; $j--; }
    return $n;
}

if (($_GET['ajax'] ?? '') === 'suggest') {
    $gid = (int)($_GET['grn'] ?? 0);
    $g = $gid ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, grn_no, ref_no, grn_value FROM sampath_grn_summary WHERE id=$gid")) : null;
    if (!$g) sgs_json(['ok' => false, 'error' => 'GRN not found']);
    if (!$HAS_INVOICES) sgs_json(['ok' => false, 'error' => 'Secondary invoice table not found']);

    $ref  = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$g['ref_no']));
    $val  = (float)$g['grn_value'];
    $q    = trim((string)($_GET['q'] ?? ''));
    $cond = [];
    if ($q !== '') {
        $e = mysqli_real_escape_string($conn, str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q));
        $cond[] = "bill_no LIKE '%$e%' OR party_name LIKE '%$e%'";
    } else {
        if (strlen($ref) >= 3) {
            $tail = mysqli_real_escape_string($conn, substr($ref, -min(6, strlen($ref))));   // last 6 chars (or fewer)
            $t4   = mysqli_real_escape_string($conn, substr($ref, -min(4, strlen($ref))));
            $cond[] = "bill_no LIKE '%$tail'";
            $cond[] = "bill_no LIKE '%$t4'";
            $cond[] = "'" . mysqli_real_escape_string($conn, $ref) . "' LIKE CONCAT('%', REPLACE(REPLACE(bill_no,'-',''),' ',''))";
        }
        if ($val > 0) $cond[] = "ABS(COALESCE(NULLIF(final_bill_amount,0), bill_value) - $val) <= 1";
    }
    if (!$cond) sgs_json(['ok' => true, 'grn' => $g, 'items' => []]);

    $sql = "SELECT bill_no, MAX(bill_date) AS bill_date, MAX(party_name) AS party,
                   MAX(COALESCE(NULLIF(final_bill_amount,0), bill_value)) AS amount
            FROM secondary_invoice_import_details
            WHERE bill_no IS NOT NULL AND bill_no<>'' AND (" . implode(' OR ', $cond) . ")
            GROUP BY bill_no LIMIT 200";
    $res = mysqli_query($conn, $sql);
    // bills already linked to a GRN (manual links, or another GRN whose Ref No equals the bill)
    $taken = [];
    $tq = mysqli_query($conn, "SELECT l.bill_no, s.grn_no FROM sampath_grn_invoice_links l JOIN sampath_grn_summary s ON s.id=l.grn_summary_id
                               WHERE l.link_type='link' AND l.grn_summary_id<>$gid");
    if ($tq) while ($r = mysqli_fetch_assoc($tq)) $taken[strtoupper(trim($r['bill_no']))] = $r['grn_no'];

    $items = [];
    if ($res) while ($r = mysqli_fetch_assoc($res)) {
        $tail = sgs_common_tail($ref, $r['bill_no']);
        $amt  = (float)$r['amount'];
        $diff = round($amt - $val, 2);
        $exact = abs($diff) <= 0.01;
        $near  = abs($diff) <= 1;
        $score = $tail * 10 + ($exact ? 60 : ($near ? 30 : 0));
        $items[] = [
            'bill_no' => $r['bill_no'], 'bill_date' => $r['bill_date'], 'party' => (string)$r['party'],
            'amount' => $amt, 'diff' => $diff, 'tail' => $tail, 'exact' => $exact, 'near' => $near,
            'score' => $score, 'taken' => $taken[strtoupper(trim($r['bill_no']))] ?? '',
        ];
    }
    usort($items, function ($a, $b) { return $b['score'] <=> $a['score'] ?: abs($a['diff']) <=> abs($b['diff']); });
    sgs_json(['ok' => true, 'grn' => ['id' => (int)$g['id'], 'grn_no' => $g['grn_no'], 'ref_no' => $g['ref_no'], 'grn_value' => $val], 'items' => array_slice($items, 0, 25)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['link_invoice', 'unlink_invoice'], true)) {
    $gid  = (int)($_POST['grn_id'] ?? 0);
    $g    = $gid ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM sampath_grn_summary WHERE id=$gid")) : null;
    if (!$g) sgs_json(['ok' => false, 'error' => 'GRN not found']);
    $by   = $_SESSION['username'] ?? ($_SESSION['user'] ?? '');
    if ($_POST['action'] === 'unlink_invoice') {
        mysqli_query($conn, "DELETE FROM sampath_grn_invoice_links WHERE grn_summary_id=$gid AND link_type='link'");
        sgs_json(['ok' => true]);
    }
    $bill = trim((string)($_POST['bill_no'] ?? ''));
    if ($bill === '' || !$HAS_INVOICES) sgs_json(['ok' => false, 'error' => 'Choose an invoice']);
    $st = mysqli_prepare($conn, "SELECT 1 FROM secondary_invoice_import_details WHERE bill_no=? LIMIT 1");
    mysqli_stmt_bind_param($st, 's', $bill); mysqli_stmt_execute($st);
    if (!mysqli_stmt_get_result($st)->fetch_row()) sgs_json(['ok' => false, 'error' => 'Invoice ' . $bill . ' not found in Secondary invoices']);
    $note = 'Linked by last-no tally on GRN batches page';
    $st = mysqli_prepare($conn, "INSERT INTO sampath_grn_invoice_links (grn_summary_id, bill_no, link_type, note, linked_by)
                                 VALUES (?,?, 'link', ?, ?)
                                 ON DUPLICATE KEY UPDATE bill_no=VALUES(bill_no), link_type='link', note=VALUES(note), linked_by=VALUES(linked_by), linked_at=NOW()");
    mysqli_stmt_bind_param($st, 'isss', $gid, $bill, $note, $by);
    $ok = mysqli_stmt_execute($st);
    sgs_json(['ok' => (bool)$ok, 'error' => $ok ? '' : 'Could not save the link']);
}

/** Returns [grn_id => ['status'=>..., 'bill'=>..., 'party'=>..., 'batch_id'=>...]] */
function sgs_link_status($conn, $where_sql, $has_invoices) {
    $res = mysqli_query($conn,
        "SELECT s.id, s.batch_id, s.ref_no, l.link_type, l.bill_no AS link_bill
         FROM sampath_grn_summary s
         LEFT JOIN sampath_grn_invoice_links l ON l.grn_summary_id = s.id
         WHERE $where_sql");
    $out = []; $bills = [];
    if ($res) while ($r = mysqli_fetch_assoc($res)) {
        if ($r['link_type'] === 'exclude')   $eff = null;
        elseif ($r['link_type'] === 'link')  $eff = trim((string)$r['link_bill']);
        else                                 $eff = trim((string)$r['ref_no']);
        $out[(int)$r['id']] = ['type' => $r['link_type'], 'bill' => $eff, 'batch_id' => (int)$r['batch_id']];
        if ($eff !== null && $eff !== '') $bills[strtoupper($eff)] = $eff;
    }

    // Which of those bill numbers exist as Secondary invoices?
    $inv = [];
    if ($has_invoices && $bills) {
        foreach (array_chunk(array_values($bills), 500) as $chunk) {
            $in = implode(',', array_map(function ($b) use ($conn) { return "'" . mysqli_real_escape_string($conn, $b) . "'"; }, $chunk));
            try {
                $q = mysqli_query($conn,
                    "SELECT bill_no, MAX(COALESCE(NULLIF(party_name,''), customer_name)) AS party
                     FROM secondary_invoice_import_details
                     WHERE bill_no IN ($in) GROUP BY bill_no");
                if (!$q) $q = mysqli_query($conn,                                 // fallback if customer_name column is missing
                    "SELECT bill_no, MAX(party_name) AS party FROM secondary_invoice_import_details
                     WHERE bill_no IN ($in) GROUP BY bill_no");
            } catch (Throwable $e) {
                $q = mysqli_query($conn, "SELECT bill_no, '' AS party FROM secondary_invoice_import_details WHERE bill_no IN ($in) GROUP BY bill_no");
            }
            if ($q) while ($i = mysqli_fetch_assoc($q)) $inv[strtoupper(trim($i['bill_no']))] = $i['party'];
        }
    }

    foreach ($out as $id => &$o) {
        if ($o['type'] === 'exclude')                                  { $o['status'] = 'excluded'; $o['party'] = ''; continue; }
        $key = strtoupper((string)$o['bill']);
        if ($o['bill'] !== '' && array_key_exists($key, $inv)) {
            $o['status'] = ($o['type'] === 'link') ? 'manual' : 'auto';
            $o['party']  = (string)$inv[$key];
        } else {
            $o['status'] = 'unlinked'; $o['party'] = '';
        }
    }
    unset($o);
    return $out;
}

// Unlinked count per batch (for the batch list)
$unlinked_by_batch = [];
if (!isset($_GET['view_batch'])) {
    foreach (sgs_link_status($conn, '1=1', $HAS_INVOICES) as $st) {
        if ($st['status'] === 'unlinked' || $st['status'] === 'excluded') {
            $unlinked_by_batch[$st['batch_id']] = ($unlinked_by_batch[$st['batch_id']] ?? 0) + 1;
        }
    }
}

$batches = [];
$br = mysqli_query($conn, "SELECT * FROM sampath_grn_batches ORDER BY created_at DESC");
if ($br) while ($b = mysqli_fetch_assoc($br)) $batches[] = $b;

// ── View a single batch ───────────────────────────────────────
$view_batch = null; $rows = []; $dup_ids = [];
if (isset($_GET['view_batch']) && is_numeric($_GET['view_batch'])) {
    $vbid = (int)$_GET['view_batch'];
    $vr = mysqli_query($conn, "SELECT * FROM sampath_grn_batches WHERE id=$vbid");
    if ($vr) $view_batch = mysqli_fetch_assoc($vr);
    if ($view_batch) {
        $rr = mysqli_query($conn, "SELECT * FROM sampath_grn_summary WHERE batch_id=$vbid ORDER BY grn_date DESC, id DESC");
        if ($rr) while ($row = mysqli_fetch_assoc($rr)) $rows[] = $row;

        // Which rows in this batch are duplicates, and which batch holds the original
        $dr = mysqli_query($conn,
            "SELECT s1.id, MIN(s2.id) orig_id
             FROM sampath_grn_summary s1 $DUP_JOIN
             WHERE s1.batch_id=$vbid
             GROUP BY s1.id");
        if ($dr) while ($d = mysqli_fetch_assoc($dr)) $dup_ids[(int)$d['id']] = (int)$d['orig_id'];

        // Invoice link status for every row in this batch + counts for the filter chips
        $link_status = sgs_link_status($conn, "s.batch_id=$vbid", $HAS_INVOICES);
        $lc = ['all' => count($rows), 'linked' => 0, 'auto' => 0, 'manual' => 0, 'unlinked' => 0, 'excluded' => 0, 'dup' => count($dup_ids)];
        $lv = ['linked' => 0, 'unlinked' => 0];
        foreach ($rows as $row) {
            $s = $link_status[(int)$row['id']]['status'] ?? 'unlinked';
            $lc[$s]++;
            if ($s === 'auto' || $s === 'manual') { $lc['linked']++; $lv['linked'] += $row['grn_value']; }
            else                                  { $lv['unlinked'] += $row['grn_value']; }
        }

        // Map original id -> batch ref (to show where the first copy lives)
        $orig_batch_ref = [];
        if ($dup_ids) {
            $ids = implode(',', array_map('intval', array_unique(array_values($dup_ids))));
            $or = mysqli_query($conn,
                "SELECT s.id, b.batch_ref FROM sampath_grn_summary s
                 LEFT JOIN sampath_grn_batches b ON b.id = s.batch_id
                 WHERE s.id IN ($ids)");
            if ($or) while ($o = mysqli_fetch_assoc($or)) $orig_batch_ref[(int)$o['id']] = $o['batch_ref'];
        }
    }
}

$flash  = sgs_take_flash();
$reopen = $_SESSION['sgs_reopen'] ?? null; unset($_SESSION['sgs_reopen']);
$hl     = isset($_GET['hl']) ? (int)$_GET['hl'] : 0;

/** Safe JSON for a data-* attribute */
function sgs_attr_json($arr) { return htmlspecialchars(json_encode($arr, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); }

include 'header.php';
?>
<style>
*{box-sizing:border-box;}
:root{
    --ink:#0f172a;--muted:#64748b;--border:#e2e8f0;--bg:#f8fafc;
    --accent:#0ea5e9;--accent-dark:#0284c7;--success:#10b981;
    --warn:#f59e0b;--danger:#ef4444;--white:#fff;
    --radius:10px;--shadow:0 2px 12px rgba(15,23,42,.07);
}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--ink);}
.sgs-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;flex-wrap:wrap;gap:12px;}
.sgs-title{font-size:22px;font-weight:800;margin:0 0 3px;}
.sgs-sub{font-size:13px;color:var(--muted);margin:0;}

.sgs-nav{display:flex;gap:6px;margin-bottom:22px;flex-wrap:wrap;}
.sgs-nav a{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;color:var(--muted);background:#f1f5f9;border:1px solid var(--border);transition:all .15s;}
.sgs-nav a:hover{background:#e2e8f0;color:var(--ink);}
.sgs-nav a.active{background:var(--accent);color:#fff;border-color:var(--accent);}

.sgs-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;margin-bottom:20px;}
.sgs-card-h{display:flex;align-items:center;gap:10px;padding:14px 20px;border-bottom:1px solid var(--border);background:#f1f5f9;font-size:14px;font-weight:700;color:var(--ink);flex-wrap:wrap;}
.sgs-card-b{padding:0;}

.sgs-alert{display:flex;align-items:center;gap:10px;padding:11px 16px;border-radius:8px;font-size:13px;margin-bottom:16px;flex-wrap:wrap;}
.sgs-alert-success{background:#dcfce7;border:1px solid #bbf7d0;color:#166534;}
.sgs-alert-danger{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}
.sgs-alert-info{background:#e0f2fe;border:1px solid #bae6fd;color:#075985;}
.sgs-alert-warn{background:#fef3c7;border:1px solid #fde68a;color:#92400e;}

.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:all .18s;text-decoration:none;font-family:inherit;white-space:nowrap;}
.btn-sm{padding:6px 12px;font-size:12px;}
.btn-primary{background:var(--accent);color:#fff;}.btn-primary:hover{background:var(--accent-dark);}
.btn-ghost{background:#f1f5f9;color:var(--ink);border:1px solid var(--border);}.btn-ghost:hover{background:#e2e8f0;}
.btn-danger{background:#fee2e2;color:var(--danger);border:1px solid #fecaca;}.btn-danger:hover{background:var(--danger);color:#fff;}
.btn-warn{background:var(--warn);color:#fff;}.btn-warn:hover{background:#d97706;}

.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-blue{background:#dbeafe;color:#1e40af;}
.badge-green{background:#dcfce7;color:#166534;}
.badge-amber{background:#fef3c7;color:#92400e;}
.badge-red{background:#fee2e2;color:#991b1b;}

.btable{width:100%;border-collapse:collapse;font-size:13px;}
.btable th{padding:10px 14px;background:#f1f5f9;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:var(--muted);font-weight:700;}
.btable td{padding:10px 14px;border-top:1px solid #f1f5f9;color:var(--ink);}
.btable tr:hover td{background:#f8fafc;}

.gtable{width:100%;border-collapse:collapse;font-size:12.5px;}
.gtable th{padding:9px 12px;background:#f1f5f9;text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.3px;color:var(--muted);font-weight:700;}
.gtable td{padding:9px 12px;border-top:1px solid #f1f5f9;color:var(--ink);}
.gtable tr:hover td{background:#f8fafc;}
.gtable tr.dup-row td{background:#fff7ed;}

/* Link filter bar */
.lf-bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:14px 20px;border-bottom:1px solid var(--border);}
.lf-chip{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:20px;border:1.5px solid var(--border);background:#fff;font-size:12.5px;font-weight:600;color:var(--muted);cursor:pointer;font-family:inherit;transition:all .15s;}
.lf-chip .n{background:#f1f5f9;color:var(--ink);border-radius:10px;padding:0 7px;font-size:11px;}
.lf-chip:hover{border-color:#94a3b8;color:var(--ink);}
.lf-chip.active{background:var(--ink);border-color:var(--ink);color:#fff;}
.lf-chip.active .n{background:rgba(255,255,255,.2);color:#fff;}
.lf-chip.c-linked.active{background:#059669;border-color:#059669;}
.lf-chip.c-unlinked.active{background:#dc2626;border-color:#dc2626;}
.lf-chip.c-manual.active{background:#7c3aed;border-color:#7c3aed;}
.lf-chip.c-excluded.active{background:#9a3412;border-color:#9a3412;}
.lf-chip.c-dup.active{background:#d97706;border-color:#d97706;}
.lf-search{margin-left:auto;position:relative;}
.lf-search input{padding:8px 12px 8px 32px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;width:240px;font-family:inherit;}
.lf-search input:focus{outline:none;border-color:var(--accent);}
.lf-search i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:12px;}
.lf-info{padding:8px 20px;font-size:12px;color:var(--muted);background:#f8fafc;border-bottom:1px solid var(--border);display:flex;gap:16px;flex-wrap:wrap;}
.lf-info b{color:var(--ink);font-variant-numeric:tabular-nums;}
.lk{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:10px;font-size:10.5px;font-weight:700;white-space:nowrap;}
.lk-auto{background:#dcfce7;color:#166534;}
.lk-manual{background:#ede9fe;color:#6d28d9;}
.lk-unlinked{background:#fee2e2;color:#991b1b;}
.lk-excluded{background:#ffedd5;color:#9a3412;}
.lk-inv{font-size:11px;margin-top:3px;color:var(--ink);font-weight:600;}
.lk-inv a{color:var(--accent-dark);text-decoration:none;}
.lk-inv a:hover{text-decoration:underline;}
.lk-act{margin-top:4px;}
.lk-btn{border:1px solid #fca5a5;background:#fff;color:#991b1b;border-radius:6px;font-size:10.5px;font-weight:700;padding:2px 8px;cursor:pointer;}
.lk-btn:hover{background:#fee2e2;}
.lk-btn.un{border-color:#c4b5fd;color:#6d28d9;}
.lk-btn.un:hover{background:#ede9fe;}
.sg-tbl{width:100%;border-collapse:collapse;font-size:12.5px;}
.sg-tbl th{background:#f8fafc;text-align:left;padding:6px 8px;font-size:11px;text-transform:uppercase;color:var(--muted);}
.sg-tbl td{padding:6px 8px;border-top:1px solid var(--border);vertical-align:middle;}
.sg-tbl .n{text-align:right;font-variant-numeric:tabular-nums;}
.sg-pill{display:inline-block;padding:1px 7px;border-radius:9px;font-size:10px;font-weight:700;margin-right:3px;}
.sg-p-ok{background:#dcfce7;color:#166534;}.sg-p-mid{background:#fef3c7;color:#92400e;}.sg-p-no{background:#f1f5f9;color:#475569;}.sg-p-tk{background:#fee2e2;color:#991b1b;}
.lk-party{font-size:10.5px;color:var(--muted);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.gtable tr.lf-hide{display:none;}
.lf-none{display:none;text-align:center;padding:30px;color:var(--muted);font-size:13px;}
@media(max-width:700px){ .lf-search{margin-left:0;width:100%;} .lf-search input{width:100%;} }
.table-wrap{max-height:560px;overflow:auto;}

.stat-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px;}
.stat-card{background:#f8fafc;border:1px solid var(--border);border-radius:8px;padding:14px 16px;text-align:center;}
.stat-label{font-size:10px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);font-weight:700;margin-bottom:6px;}
.stat-val{font-size:19px;font-weight:800;color:var(--ink);}
.stat-val.blue{color:var(--accent);}
.stat-val.amber{color:var(--warn);}
.stat-val.red{color:var(--danger);}

.btn-edit{background:#e0f2fe;color:var(--accent-dark);border:1px solid #bae6fd;}.btn-edit:hover{background:var(--accent);color:#fff;}
.gtable tr.hl-row td{background:#ecfdf5 !important;transition:background 2s;}

/* ── Modal ─────────────────────────────────────── */
.sgs-modal{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;align-items:flex-start;justify-content:center;z-index:9999;padding:40px 16px;overflow-y:auto;}
.sgs-modal.open{display:flex;}
.sgs-modal-box{background:#fff;border-radius:12px;width:100%;max-width:620px;box-shadow:0 20px 50px rgba(0,0,0,.25);animation:sgsPop .18s ease-out;}
@keyframes sgsPop{from{transform:translateY(-12px);opacity:0}to{transform:none;opacity:1}}
.sgs-modal-h{display:flex;align-items:center;gap:10px;padding:16px 20px;border-bottom:1px solid var(--border);font-size:15px;font-weight:800;}
.sgs-modal-h .x{margin-left:auto;background:none;border:none;font-size:22px;line-height:1;cursor:pointer;color:var(--muted);}
.sgs-modal-b{padding:20px;}
.sgs-modal-f{display:flex;justify-content:flex-end;gap:8px;padding:14px 20px;border-top:1px solid var(--border);background:#f8fafc;border-radius:0 0 12px 12px;}
.fgrid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.fgrid .full{grid-column:1 / -1;}
.fld label{display:block;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;}
.fld label .req{color:var(--danger);}
.fld input,.fld textarea{width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:13.5px;font-family:inherit;color:var(--ink);background:#fff;}
.fld input:focus,.fld textarea:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(14,165,233,.12);}
.fld input[readonly]{background:#f1f5f9;}
.fld input.num{text-align:right;font-variant-numeric:tabular-nums;}
.fld .hint{font-size:11.5px;color:var(--muted);margin-top:4px;}
.auto-row{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted);margin-top:5px;}
.calc-box{display:flex;justify-content:space-between;padding:10px 14px;border-radius:8px;background:#f0f9ff;border:1px solid #bae6fd;font-size:13px;margin-top:4px;}
.calc-box strong{font-variant-numeric:tabular-nums;}
.calc-box.bad{background:#fef2f2;border-color:#fecaca;color:#991b1b;}
@media(max-width:600px){ .fgrid{grid-template-columns:1fr;} }

.empty-box{text-align:center;padding:50px 20px;color:var(--muted);}
.empty-box i{font-size:38px;color:#cbd5e1;margin-bottom:10px;display:block;}

@media(max-width:900px){ .stat-grid{grid-template-columns:repeat(3,1fr);} }
@media(max-width:700px){
    .stat-grid{grid-template-columns:1fr 1fr;}
    .sgs-card-h{flex-direction:column;align-items:flex-start;}
}
</style>

<div class="sgs-header">
    <div>
        <h2 class="sgs-title"><i class="fa-solid fa-layer-group" style="color:var(--accent);"></i> Sampath GRN Summary — Batches</h2>
        <p class="sgs-sub">Every GRN Summary import, grouped by upload batch.</p>
    </div>
    <a href="sampath_grn_import.php" class="btn btn-primary"><i class="fa-solid fa-file-import"></i> New Import</a>
</div>

<div class="sgs-nav">
    <a href="sampath_grn_import.php"><i class="fa-solid fa-cloud-arrow-up"></i> New Import</a>
    <a href="sampath_grn_batches.php" class="active"><i class="fa-solid fa-layer-group"></i> Batches</a>
    <a href="sampath_grn_all.php"><i class="fa-solid fa-table-list"></i> All Records</a>
</div>

<?php if ($flash): ?>
<div class="sgs-alert sgs-alert-<?php echo $flash['type'] === 'success' ? 'success' : 'danger'; ?>">
    <i class="fa-solid fa-<?php echo $flash['type'] === 'success' ? 'circle-check' : 'circle-xmark'; ?>"></i>
    <div><?php echo $flash['text']; ?></div>
</div>
<?php endif; ?>
<?php if (isset($_GET['imported'])): ?>
<div class="sgs-alert sgs-alert-success"><i class="fa-solid fa-circle-check"></i> Batch imported successfully.</div>
<?php endif; ?>
<?php if (isset($_GET['batch_deleted'])): ?>
<div class="sgs-alert sgs-alert-danger"><i class="fa-solid fa-trash"></i> Batch deleted successfully.</div>
<?php endif; ?>
<?php if (isset($_GET['row_deleted'])): ?>
<div class="sgs-alert sgs-alert-success"><i class="fa-solid fa-trash"></i> Record deleted successfully.</div>
<?php endif; ?>
<?php if (isset($_GET['dups_removed'])): ?>
<div class="sgs-alert sgs-alert-success"><i class="fa-solid fa-broom"></i> <strong><?php echo (int)$_GET['dups_removed']; ?></strong>&nbsp;duplicate record(s) removed. Batch totals updated.</div>
<?php endif; ?>

<?php if ($view_batch): ?>
<?php $this_dups = count($dup_ids); ?>
<!-- ══════════════════════════════════════════════════════
     VIEW SINGLE BATCH
════════════════════════════════════════════════════════ -->
<div class="sgs-card">
    <div class="sgs-card-h">
        <i class="fa-solid fa-box-archive" style="color:var(--accent);"></i>
        <span><?php echo htmlspecialchars($view_batch['batch_ref']); ?></span>
        <a href="sampath_grn_batches.php" class="btn btn-ghost btn-sm" style="margin-left:auto;"><i class="fa-solid fa-arrow-left"></i> All Batches</a>
        <button type="button" class="btn btn-edit btn-sm"
                data-batch="<?php echo sgs_attr_json(['batch_id' => $view_batch['id'], 'batch_ref' => $view_batch['batch_ref'], 'import_date' => $view_batch['import_date'], 'notes' => $view_batch['notes'] ?? '']); ?>"
                onclick="openBatchModal(this)">
            <i class="fa-solid fa-pen-to-square"></i> Edit Batch
        </button>
        <?php if ($this_dups > 0): ?>
        <a href="sampath_grn_batches.php?remove_dups_batch=<?php echo (int)$view_batch['id']; ?>" class="btn btn-warn btn-sm"
           onclick="return confirm('Remove <?php echo $this_dups; ?> duplicate record(s) from this batch?\n\nThe original (first imported) copies are kept.');">
            <i class="fa-solid fa-broom"></i> Remove <?php echo $this_dups; ?> Duplicate(s)
        </a>
        <?php endif; ?>
        <a href="sampath_grn_batches.php?delete_batch=<?php echo (int)$view_batch['id']; ?>" class="btn btn-danger btn-sm"
           onclick="return confirm('Delete batch <?php echo htmlspecialchars(addslashes($view_batch['batch_ref'])); ?> and ALL its <?php echo count($rows); ?> record(s)?\n\nThis cannot be undone.')">
            <i class="fa-solid fa-trash"></i> Delete Batch
        </a>
    </div>
    <div class="sgs-card-b" style="padding:22px;">
        <div class="stat-grid">
            <div class="stat-card"><div class="stat-label">Records</div><div class="stat-val blue"><?php echo count($rows); ?></div></div>
            <div class="stat-card"><div class="stat-label">Duplicates</div><div class="stat-val <?php echo $this_dups ? 'red' : ''; ?>"><?php echo $this_dups; ?></div></div>
            <div class="stat-card"><div class="stat-label">Total Value</div><div class="stat-val"><?php echo number_format($view_batch['total_value'], 2); ?></div></div>
            <div class="stat-card"><div class="stat-label">Total Balance</div><div class="stat-val amber"><?php echo number_format($view_batch['total_balance'], 2); ?></div></div>
            <div class="stat-card"><div class="stat-label">Imported</div><div class="stat-val" style="font-size:14px;"><?php echo htmlspecialchars($view_batch['imported_by']); ?><br><span style="font-size:11px;color:var(--muted);font-weight:500;"><?php echo date('d M Y, H:i', strtotime($view_batch['created_at'])); ?></span></div></div>
        </div>
        <?php if ($this_dups > 0): ?>
        <div class="sgs-alert sgs-alert-warn">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div><strong><?php echo $this_dups; ?></strong> record(s) in this batch were already imported earlier (same GRN No, or same Ref No + Value). They are highlighted below.</div>
        </div>
        <?php endif; ?>
        <?php if (!empty($view_batch['notes'])): ?>
        <div class="sgs-alert sgs-alert-info"><i class="fa-solid fa-note-sticky"></i><div><?php echo htmlspecialchars($view_batch['notes']); ?></div></div>
        <?php endif; ?>
        <?php if ((int)$view_batch['skipped_dup'] > 0 || (int)$view_batch['skipped_other'] > 0): ?>
        <div class="sgs-alert sgs-alert-info">
            <i class="fa-solid fa-circle-info"></i>
            <div>At upload time, <strong><?php echo (int)$view_batch['skipped_dup']; ?></strong> duplicate row(s) and <strong><?php echo (int)$view_batch['skipped_other']; ?></strong> other-supplier row(s) were skipped.</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="sgs-card">
    <div class="sgs-card-h"><i class="fa-solid fa-table" style="color:var(--success);"></i> Records in this Batch <span class="badge badge-blue"><?php echo count($rows); ?></span></div>
    <div class="sgs-card-b">
        <?php if (empty($rows)): ?>
        <div class="empty-box"><i class="fa-solid fa-box-open"></i><p style="margin:0;">No records left in this batch.</p></div>
        <?php else: ?>

        <!-- Filter: linked / unlinked -->
        <div class="lf-bar" id="lfBar">
            <button type="button" class="lf-chip active" data-f="all">All <span class="n"><?php echo $lc['all']; ?></span></button>
            <button type="button" class="lf-chip c-linked" data-f="linked"><i class="fa-solid fa-link"></i> Linked <span class="n"><?php echo $lc['linked']; ?></span></button>
            <button type="button" class="lf-chip c-unlinked" data-f="unlinked"><i class="fa-solid fa-link-slash"></i> Unlinked <span class="n"><?php echo $lc['unlinked']; ?></span></button>
            <button type="button" class="lf-chip c-manual" data-f="manual"><i class="fa-solid fa-hand-pointer"></i> Manual link <span class="n"><?php echo $lc['manual']; ?></span></button>
            <?php if ($lc['excluded']): ?>
            <button type="button" class="lf-chip c-excluded" data-f="excluded"><i class="fa-solid fa-ban"></i> Excluded <span class="n"><?php echo $lc['excluded']; ?></span></button>
            <?php endif; ?>
            <?php if ($lc['dup']): ?>
            <button type="button" class="lf-chip c-dup" data-f="dup"><i class="fa-solid fa-clone"></i> Duplicates <span class="n"><?php echo $lc['dup']; ?></span></button>
            <?php endif; ?>
            <div class="lf-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="lfSearch" placeholder="Search GRN, Ref No, invoice, party…" autocomplete="off">
            </div>
        </div>
        <div class="lf-info">
            <span>Linked value: <b><?php echo number_format($lv['linked'], 2); ?></b></span>
            <span>Unlinked value: <b style="color:<?php echo $lv['unlinked'] > 0 ? 'var(--danger)' : 'inherit'; ?>"><?php echo number_format($lv['unlinked'], 2); ?></b></span>
            <span>Showing: <b id="lfShown"><?php echo $lc['all']; ?></b> row(s) · value <b id="lfShownVal">0.00</b></span>
            <?php if (!$HAS_INVOICES): ?><span style="color:var(--danger);">Secondary invoice table not found — every GRN shows as Unlinked.</span><?php endif; ?>
        </div>

        <div class="table-wrap">
        <table class="gtable" id="grnTable">
            <thead><tr>
                <th>GRN No</th><th>GRN Date</th><th>Supplier</th><th>Ref No</th>
                <th style="text-align:right;">Value</th>
                <th style="text-align:right;">Settlement</th>
                <th style="text-align:right;">Balance</th>
                <th>Invoice Link</th>
                <th>Status</th>
                <th style="text-align:center;">Action</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r):
                $rid = (int)$r['id'];
                $is_dup = isset($dup_ids[$rid]);
                $orig_ref = $is_dup ? ($orig_batch_ref[$dup_ids[$rid]] ?? '—') : '';
                $ls  = $link_status[$rid] ?? ['status' => 'unlinked', 'bill' => '', 'party' => ''];
                $grp = in_array($ls['status'], ['auto', 'manual'], true) ? 'linked' : $ls['status'];
                $search_txt = strtolower($r['grn_no'] . ' ' . $r['ref_no'] . ' ' . $r['supplier'] . ' ' . $ls['bill'] . ' ' . $ls['party']);
            ?>
            <tr id="row-<?php echo $rid; ?>"
                class="<?php echo $is_dup ? 'dup-row' : ''; ?> <?php echo $hl === $rid ? 'hl-row' : ''; ?>"
                data-link="<?php echo $grp; ?>" data-ltype="<?php echo $ls['status']; ?>" data-dup="<?php echo $is_dup ? 1 : 0; ?>"
                data-val="<?php echo (float)$r['grn_value']; ?>" data-s="<?php echo htmlspecialchars($search_txt); ?>">
                <td style="font-weight:700;"><?php echo htmlspecialchars($r['grn_no']); ?></td>
                <td><?php echo $r['grn_date'] ? htmlspecialchars($r['grn_date']) : '—'; ?></td>
                <td><?php echo htmlspecialchars($r['supplier']); ?></td>
                <td><?php echo htmlspecialchars($r['ref_no']); ?></td>
                <td style="text-align:right;"><?php echo number_format($r['grn_value'], 2); ?></td>
                <td style="text-align:right;"><?php echo number_format($r['settlement_value'], 2); ?></td>
                <td style="text-align:right;font-weight:700;color:var(--accent-dark);"><?php echo number_format($r['balance_value'], 2); ?></td>
                <td>
                    <?php if ($ls['status'] === 'auto'): ?>
                        <span class="lk lk-auto"><i class="fa-solid fa-link"></i> Linked</span>
                    <?php elseif ($ls['status'] === 'manual'): ?>
                        <span class="lk lk-manual" title="Linked manually on the Balancing Report"><i class="fa-solid fa-hand-pointer"></i> Manual</span>
                    <?php elseif ($ls['status'] === 'excluded'): ?>
                        <span class="lk lk-excluded" title="Removed from its invoice on the Balancing Report"><i class="fa-solid fa-ban"></i> Excluded</span>
                    <?php else: ?>
                        <span class="lk lk-unlinked" title="No Secondary invoice with this Ref No"><i class="fa-solid fa-link-slash"></i> Unlinked</span>
                    <?php endif; ?>
                    <?php if (in_array($ls['status'], ['unlinked', 'excluded'], true)): ?>
                        <div class="lk-act"><button type="button" class="lk-btn" onclick="sgFind(<?php echo $rid; ?>)"><i class="fa-solid fa-magnifying-glass"></i> Find &amp; Link</button></div>
                    <?php elseif ($ls['status'] === 'manual'): ?>
                        <div class="lk-act"><button type="button" class="lk-btn un" onclick="sgUnlink(<?php echo $rid; ?>)"><i class="fa-solid fa-link-slash"></i> Unlink</button></div>
                    <?php endif; ?>
                    <?php if (in_array($ls['status'], ['auto', 'manual'], true)): ?>
                        <div class="lk-inv">
                            <a href="sampath_balancing_report.php?date_from=2000-01-01&amp;date_to=<?php echo date('Y-m-d'); ?>&amp;search=<?php echo urlencode($ls['bill']); ?>"
                               title="Open in Balancing Report" target="_blank">Inv <?php echo htmlspecialchars($ls['bill']); ?> <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:9px;"></i></a>
                        </div>
                        <?php if ($ls['party']): ?><div class="lk-party" title="<?php echo htmlspecialchars($ls['party']); ?>"><?php echo htmlspecialchars($ls['party']); ?></div><?php endif; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($is_dup): ?>
                        <span class="badge badge-red" title="First copy is in batch <?php echo htmlspecialchars($orig_ref); ?>">Duplicate</span>
                        <div style="font-size:10.5px;color:var(--muted);margin-top:3px;">of <?php echo htmlspecialchars($orig_ref); ?></div>
                    <?php else: ?>
                        <span class="badge badge-green">OK</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:center;white-space:nowrap;">
                    <button type="button" class="btn btn-edit btn-sm" title="Edit GRN"
                            data-row="<?php echo sgs_attr_json([
                                'row_id' => $rid, 'grn_no' => $r['grn_no'], 'grn_date' => $r['grn_date'],
                                'supplier' => $r['supplier'], 'ref_no' => $r['ref_no'],
                                'grn_value' => $r['grn_value'], 'settlement_value' => $r['settlement_value'],
                                'balance_value' => $r['balance_value']]); ?>"
                            onclick="openRowModal(this)">
                        <i class="fa-solid fa-pen-to-square"></i>
                    </button>
                    <a href="sampath_grn_batches.php?delete_row=<?php echo $rid; ?>" class="btn btn-danger btn-sm" title="Delete"
                       onclick="return confirm('Delete this GRN record (Ref: <?php echo htmlspecialchars(addslashes($r['ref_no'])); ?>)?');">
                        <i class="fa-solid fa-trash"></i>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="lf-none" id="lfNone"><i class="fa-solid fa-filter-circle-xmark" style="font-size:26px;display:block;margin-bottom:8px;color:#cbd5e1;"></i>No GRNs match this filter.</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function(){
    const bar = document.getElementById('lfBar');
    if (!bar) return;
    const rows   = Array.from(document.querySelectorAll('#grnTable tbody tr'));
    const search = document.getElementById('lfSearch');
    const KEY    = 'sgs_link_filter_<?php echo (int)$view_batch['id']; ?>';
    let filter   = 'all';
    try { const s = sessionStorage.getItem(KEY); if (s && bar.querySelector('[data-f="' + s + '"]')) filter = s; } catch (e) {}
    const urlF = new URLSearchParams(location.search).get('link');
    if (urlF && bar.querySelector('[data-f="' + urlF + '"]')) filter = urlF;

    function apply(){
        const q = search.value.trim().toLowerCase();
        let shown = 0, val = 0;
        rows.forEach(tr => {
            let ok;
            switch (filter) {
                case 'linked':   ok = tr.dataset.link === 'linked'; break;
                case 'unlinked': ok = tr.dataset.link === 'unlinked'; break;
                case 'manual':   ok = tr.dataset.ltype === 'manual'; break;
                case 'excluded': ok = tr.dataset.ltype === 'excluded'; break;
                case 'dup':      ok = tr.dataset.dup === '1'; break;
                default:         ok = true;
            }
            if (ok && q) ok = tr.dataset.s.indexOf(q) !== -1;
            tr.classList.toggle('lf-hide', !ok);
            if (ok) { shown++; val += parseFloat(tr.dataset.val) || 0; }
        });
        bar.querySelectorAll('.lf-chip').forEach(c => c.classList.toggle('active', c.dataset.f === filter));
        document.getElementById('lfShown').textContent = shown;
        document.getElementById('lfShownVal').textContent = val.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
        document.getElementById('lfNone').style.display = shown ? 'none' : 'block';
        try { sessionStorage.setItem(KEY, filter); } catch (e) {}
    }
    bar.addEventListener('click', e => {
        const c = e.target.closest('.lf-chip'); if (!c) return;
        filter = c.dataset.f; apply();
    });
    search.addEventListener('input', apply);
    apply();
})();
</script>

<?php else: ?>
<!-- ══════════════════════════════════════════════════════
     BATCH LIST
════════════════════════════════════════════════════════ -->
<?php if ($total_dups > 0): ?>
<div class="sgs-alert sgs-alert-warn">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <div style="flex:1;"><strong><?php echo $total_dups; ?></strong> duplicate record(s) found across batches (same GRN No, or same Ref No + Value, imported more than once).</div>
    <a href="sampath_grn_batches.php?remove_dups_all=1" class="btn btn-warn btn-sm"
       onclick="return confirm('Remove all <?php echo $total_dups; ?> duplicate record(s)?\n\nThe first imported copy of each GRN is kept. This cannot be undone.');">
        <i class="fa-solid fa-broom"></i> Remove All Duplicates
    </a>
</div>
<?php endif; ?>

<div class="sgs-card">
    <div class="sgs-card-h"><i class="fa-solid fa-table" style="color:var(--success);"></i> <span>All Batches</span> <span class="badge badge-blue"><?php echo count($batches); ?></span></div>
    <div class="sgs-card-b">
    <?php if (empty($batches)): ?>
    <div class="empty-box">
        <i class="fa-solid fa-box-open"></i>
        <h3 style="margin:0 0 8px;color:#374151;">No batches imported yet</h3>
        <p style="margin:0 0 16px;font-size:13px;">Import a GRN Summary Excel file to get started.</p>
        <a href="sampath_grn_import.php" class="btn btn-primary"><i class="fa-solid fa-file-import"></i> Import Now</a>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="btable">
        <thead><tr>
            <th>Batch Ref</th>
            <th>Import Date</th>
            <th>Imported By</th>
            <th style="text-align:right;">Records</th>
            <th style="text-align:right;">Duplicates</th>
            <th style="text-align:right;">Unlinked</th>
            <th style="text-align:right;">Total Value</th>
            <th style="text-align:right;">Total Balance</th>
            <th>Notes</th>
            <th style="text-align:center;">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($batches as $b):
            $bid = (int)$b['id'];
            $bd  = $dup_by_batch[$bid] ?? 0;
        ?>
        <tr>
            <td>
                <a href="sampath_grn_batches.php?view_batch=<?php echo $bid; ?>" style="font-weight:700;color:var(--accent);text-decoration:none;">
                    <?php echo htmlspecialchars($b['batch_ref']); ?>
                </a>
            </td>
            <td><?php echo htmlspecialchars($b['import_date']); ?></td>
            <td><?php echo htmlspecialchars($b['imported_by']); ?></td>
            <td style="text-align:right;"><span class="badge badge-blue"><?php echo (int)$b['total_records']; ?></span></td>
            <td style="text-align:right;">
                <?php if ($bd > 0): ?><span class="badge badge-red"><?php echo $bd; ?></span>
                <?php else: ?><span style="color:var(--muted);">0</span><?php endif; ?>
            </td>
            <td style="text-align:right;">
                <?php $ul = $unlinked_by_batch[$bid] ?? 0; ?>
                <?php if ($ul > 0): ?>
                    <a href="sampath_grn_batches.php?view_batch=<?php echo $bid; ?>&amp;link=unlinked" title="Show unlinked GRNs" style="text-decoration:none;"><span class="badge badge-red"><?php echo $ul; ?></span></a>
                <?php else: ?><span class="badge badge-green" title="All GRNs linked to an invoice">0</span><?php endif; ?>
            </td>
            <td style="text-align:right;font-weight:700;"><?php echo number_format($b['total_value'], 2); ?></td>
            <td style="text-align:right;"><?php echo number_format($b['total_balance'], 2); ?></td>
            <td style="max-width:160px;font-size:12px;color:var(--muted);"><?php echo htmlspecialchars($b['notes'] ?: '—'); ?></td>
            <td>
                <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
                    <a href="sampath_grn_batches.php?view_batch=<?php echo $bid; ?>" class="btn btn-ghost btn-sm"><i class="fa-solid fa-eye"></i> View</a>
                    <button type="button" class="btn btn-edit btn-sm" title="Edit batch"
                            data-batch="<?php echo sgs_attr_json(['batch_id' => $bid, 'batch_ref' => $b['batch_ref'], 'import_date' => $b['import_date'], 'notes' => $b['notes'] ?? '', 'from_list' => 1]); ?>"
                            onclick="openBatchModal(this)">
                        <i class="fa-solid fa-pen-to-square"></i>
                    </button>
                    <?php if ($bd > 0): ?>
                    <a href="sampath_grn_batches.php?remove_dups_batch=<?php echo $bid; ?>" class="btn btn-warn btn-sm" title="Remove duplicates"
                       onclick="return confirm('Remove <?php echo $bd; ?> duplicate record(s) from batch <?php echo htmlspecialchars(addslashes($b['batch_ref'])); ?>?');">
                        <i class="fa-solid fa-broom"></i>
                    </a>
                    <?php endif; ?>
                    <a href="sampath_grn_batches.php?delete_batch=<?php echo $bid; ?>"
                       class="btn btn-danger btn-sm"
                       onclick="return confirm('Delete batch <?php echo htmlspecialchars(addslashes($b['batch_ref'])); ?> and ALL its records?\n\nThis cannot be undone.')">
                        <i class="fa-solid fa-trash"></i>
                    </a>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- MODAL: LINK GRN TO SECONDARY INVOICE -->
<div class="sgs-modal" id="linkModal" onclick="if(event.target===this)closeModal('linkModal')">
  <div class="sgs-modal-box" style="max-width:820px;">
    <div class="sgs-modal-h">
      <i class="fa-solid fa-link" style="color:var(--accent);"></i>
      <span>Link GRN to Secondary invoice — <span id="lk_title"></span></span>
      <button type="button" class="x" onclick="closeModal('linkModal')">&times;</button>
    </div>
    <div class="sgs-modal-b">
      <div id="lk_info" style="font-size:12.5px;color:var(--muted);margin-bottom:8px;"></div>
      <div style="display:flex;gap:6px;margin-bottom:10px;">
        <input type="text" id="lk_q" placeholder="Search any bill no / customer…" style="flex:1;padding:7px 10px;border:1px solid var(--border);border-radius:7px;" onkeydown="if(event.key==='Enter'){sgLoad();}">
        <button type="button" class="btn btn-ghost btn-sm" onclick="sgLoad()"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
        <button type="button" class="btn btn-ghost btn-sm" onclick="$id('lk_q').value='';sgLoad()">Suggested</button>
      </div>
      <div id="lk_body" style="max-height:55vh;overflow:auto;"></div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════
     MODAL: EDIT GRN RECORD
════════════════════════════════════════════════════════ -->
<div class="sgs-modal" id="rowModal" onclick="if(event.target===this)closeModal('rowModal')">
  <div class="sgs-modal-box">
    <form method="post" id="rowForm" onsubmit="return validateRowForm()">
      <input type="hidden" name="action" value="edit_row">
      <input type="hidden" name="row_id" id="f_row_id">
      <div class="sgs-modal-h">
        <i class="fa-solid fa-pen-to-square" style="color:var(--accent);"></i>
        <span>Edit GRN — <span id="f_title"></span></span>
        <button type="button" class="x" onclick="closeModal('rowModal')">&times;</button>
      </div>
      <div class="sgs-modal-b">
        <div class="fgrid">
          <div class="fld"><label>GRN No <span class="req">*</span></label><input type="text" name="grn_no" id="f_grn_no" required maxlength="80"></div>
          <div class="fld"><label>GRN Date</label><input type="date" name="grn_date" id="f_grn_date"></div>
          <div class="fld full"><label>Supplier <span class="req">*</span></label><input type="text" name="supplier" id="f_supplier" required maxlength="255"></div>
          <div class="fld"><label>Ref No <span class="req">*</span></label><input type="text" name="ref_no" id="f_ref_no" required maxlength="150"></div>
          <div class="fld"><label>Value (LKR) <span class="req">*</span></label><input type="number" step="0.01" min="0" class="num" name="grn_value" id="f_value" required oninput="recalcBalance()"></div>
          <div class="fld"><label>Settlement (LKR)</label><input type="number" step="0.01" min="0" class="num" name="settlement_value" id="f_settle" oninput="recalcBalance()"></div>
          <div class="fld">
            <label>Balance (LKR)</label>
            <input type="number" step="0.01" class="num" name="balance_value" id="f_balance" readonly>
            <label class="auto-row" style="text-transform:none;letter-spacing:0;font-weight:500;">
              <input type="checkbox" id="f_auto" checked onchange="toggleAuto()" style="width:auto;"> Auto-calculate (Value − Settlement)
            </label>
          </div>
          <div class="full">
            <div class="calc-box" id="f_calc"><span>Value − Settlement</span><strong id="f_calc_val">0.00</strong></div>
          </div>
        </div>
        <div class="hint" style="font-size:12px;color:var(--muted);margin-top:12px;">
          <i class="fa-solid fa-shield-halved"></i> Saving is blocked if another record already has the same <strong>GRN No</strong>, or the same <strong>Ref No + Value</strong>. Batch totals update automatically.
        </div>
      </div>
      <div class="sgs-modal-f">
        <button type="button" class="btn btn-ghost" onclick="closeModal('rowModal')">Cancel</button>
        <button type="submit" class="btn btn-primary" id="f_save"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════
     MODAL: EDIT BATCH
════════════════════════════════════════════════════════ -->
<div class="sgs-modal" id="batchModal" onclick="if(event.target===this)closeModal('batchModal')">
  <div class="sgs-modal-box" style="max-width:500px;">
    <form method="post" onsubmit="this.querySelector('[type=submit]').disabled=true;">
      <input type="hidden" name="action" value="edit_batch">
      <input type="hidden" name="batch_id" id="b_id">
      <input type="hidden" name="from_list" id="b_from_list">
      <div class="sgs-modal-h">
        <i class="fa-solid fa-box-archive" style="color:var(--accent);"></i>
        <span>Edit Batch</span>
        <button type="button" class="x" onclick="closeModal('batchModal')">&times;</button>
      </div>
      <div class="sgs-modal-b">
        <div class="fgrid">
          <div class="fld"><label>Batch Ref <span class="req">*</span></label><input type="text" name="batch_ref" id="b_ref" required maxlength="60"></div>
          <div class="fld"><label>Import Date <span class="req">*</span></label><input type="date" name="import_date" id="b_date" required></div>
          <div class="fld full"><label>Notes</label><textarea name="notes" id="b_notes" rows="3"></textarea></div>
        </div>
        <div class="hint" style="font-size:12px;color:var(--muted);margin-top:10px;">Totals (records, value, balance) are calculated from the GRN rows and can't be edited here.</div>
      </div>
      <div class="sgs-modal-f">
        <button type="button" class="btn btn-ghost" onclick="closeModal('batchModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Batch</button>
      </div>
    </form>
  </div>
</div>

<script>
function $id(i){ return document.getElementById(i); }
function fmt(n){ return (isNaN(n)?0:n).toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function openModal(id){ $id(id).classList.add('open'); document.body.style.overflow='hidden'; }
function closeModal(id){ $id(id).classList.remove('open'); document.body.style.overflow=''; }
document.addEventListener('keydown', e => { if (e.key === 'Escape') ['rowModal','batchModal','linkModal'].forEach(closeModal); });

// ── Link a GRN to a Secondary invoice (last-no tally, click to link) ──
let sgGrn = 0;
function sgEsc(t){ return String(t==null?'':t).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function sgFind(id){ sgGrn = id; $id('lk_q').value = ''; openModal('linkModal'); sgLoad(); }
function sgLoad(){
    const body = $id('lk_body');
    body.innerHTML = '<div style="padding:18px;text-align:center;color:var(--muted);"><i class="fa-solid fa-spinner fa-spin"></i> Finding invoices…</div>';
    fetch('sampath_grn_batches.php?ajax=suggest&grn=' + sgGrn + '&q=' + encodeURIComponent($id('lk_q').value.trim()), {credentials:'same-origin'})
      .then(r => r.json()).then(d => {
        if (!d.ok) { body.innerHTML = '<div style="padding:14px;color:#991b1b;">' + sgEsc(d.error) + '</div>'; return; }
        $id('lk_title').textContent = d.grn.grn_no;
        $id('lk_info').innerHTML = 'Ref No <b>' + sgEsc(d.grn.ref_no) + '</b> · GRN value <b>' + fmt(d.grn.grn_value) + '</b> — invoices are ranked by how many <b>last characters</b> of the Ref No tally with the bill no, plus amount match.';
        if (!d.items.length) { body.innerHTML = '<div style="padding:18px;text-align:center;color:var(--muted);">No tallying invoice found. Type a bill no / customer above and Search.</div>'; return; }
        let h = '<table class="sg-tbl"><thead><tr><th>Invoice</th><th>Date</th><th>Customer</th><th class="n">Amount</th><th>Tally</th><th></th></tr></thead><tbody>';
        d.items.forEach(i => {
            const tail = i.tail >= 4 ? '<span class="sg-pill sg-p-ok">last ' + i.tail + ' match</span>' : (i.tail > 0 ? '<span class="sg-pill sg-p-mid">last ' + i.tail + '</span>' : '<span class="sg-pill sg-p-no">no tail match</span>');
            const amt  = i.exact ? '<span class="sg-pill sg-p-ok">amount tally</span>' : (i.near ? '<span class="sg-pill sg-p-mid">Δ ' + fmt(i.diff) + '</span>' : '<span class="sg-pill sg-p-no">Δ ' + fmt(i.diff) + '</span>');
            const tk   = i.taken ? '<span class="sg-pill sg-p-tk" title="Already linked to GRN ' + sgEsc(i.taken) + '">used: ' + sgEsc(i.taken) + '</span>' : '';
            h += '<tr><td><b>' + sgEsc(i.bill_no) + '</b></td><td>' + sgEsc(i.bill_date||'') + '</td><td>' + sgEsc(i.party) + '</td><td class="n">' + fmt(i.amount) + '</td><td>' + tail + amt + tk + '</td>'
               + '<td><button type="button" class="btn btn-primary btn-sm" onclick="sgDoLink(this,' + JSON.stringify(i.bill_no).replace(/"/g,'&quot;') + ')"><i class="fa-solid fa-link"></i> Link</button></td></tr>';
        });
        body.innerHTML = h + '</tbody></table>';
      }).catch(e => { body.innerHTML = '<div style="padding:14px;color:#991b1b;">Request failed: ' + sgEsc(e.message) + '</div>'; });
}
function sgPost(data){
    const fd = new FormData(); Object.keys(data).forEach(k => fd.append(k, data[k]));
    return fetch('sampath_grn_batches.php', {method:'POST', body:fd, credentials:'same-origin'}).then(r => r.json());
}
function sgDoLink(btn, bill){
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
    sgPost({action:'link_invoice', grn_id:sgGrn, bill_no:bill}).then(d => {
        if (!d.ok) { alert(d.error || 'Failed'); btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-link"></i> Link'; return; }
        const u = new URL(location.href); u.searchParams.set('hl', sgGrn); location.href = u.toString();
    }).catch(e => { alert(e.message); btn.disabled = false; });
}
function sgUnlink(id){
    if (!confirm('Remove the manual invoice link from this GRN?')) return;
    sgPost({action:'unlink_invoice', grn_id:id}).then(d => {
        if (!d.ok) { alert(d.error || 'Failed'); return; }
        const u = new URL(location.href); u.searchParams.set('hl', id); location.href = u.toString();
    });
}

// ── GRN row modal ─────------------------------------
function fillRowModal(d){
    $id('f_row_id').value   = d.row_id || '';
    $id('f_grn_no').value   = d.grn_no || '';
    $id('f_grn_date').value = d.grn_date || '';
    $id('f_supplier').value = d.supplier || '';
    $id('f_ref_no').value   = d.ref_no || '';
    $id('f_value').value    = parseFloat(d.grn_value || 0).toFixed(2);
    $id('f_settle').value   = parseFloat(d.settlement_value || 0).toFixed(2);
    $id('f_balance').value  = parseFloat(d.balance_value || 0).toFixed(2);
    $id('f_title').textContent = d.grn_no || '';
    // Auto-calc on only if the stored balance already equals value - settlement
    const v = parseFloat(d.grn_value||0), s = parseFloat(d.settlement_value||0), b = parseFloat(d.balance_value||0);
    $id('f_auto').checked = Math.abs((v - s) - b) < 0.01;
    $id('f_save').disabled = false;
    toggleAuto();
}
function openRowModal(btn){
    fillRowModal(JSON.parse(btn.getAttribute('data-row')));
    openModal('rowModal');
    setTimeout(() => $id('f_grn_no').focus(), 50);
}
function recalcBalance(){
    const v = parseFloat($id('f_value').value) || 0;
    const s = parseFloat($id('f_settle').value) || 0;
    const diff = Math.round((v - s) * 100) / 100;
    $id('f_calc_val').textContent = fmt(diff);
    if ($id('f_auto').checked) $id('f_balance').value = diff.toFixed(2);
    $id('f_calc').classList.toggle('bad', s > v);
}
function toggleAuto(){
    $id('f_balance').readOnly = $id('f_auto').checked;
    recalcBalance();
}
function validateRowForm(){
    const v = parseFloat($id('f_value').value) || 0, s = parseFloat($id('f_settle').value) || 0;
    if (s > v && !confirm('Settlement (' + fmt(s) + ') is more than Value (' + fmt(v) + ').\n\nSave anyway?')) return false;
    $id('f_save').disabled = true;
    $id('f_save').innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    return true;
}

// ── Batch modal ───────────────────────────────
function fillBatchModal(d){
    $id('b_id').value        = d.batch_id || '';
    $id('b_ref').value       = d.batch_ref || '';
    $id('b_date').value      = (d.import_date || '').substring(0, 10);
    $id('b_notes').value     = d.notes || '';
    $id('b_from_list').value = d.from_list ? 1 : '';
}
function openBatchModal(btn){
    fillBatchModal(JSON.parse(btn.getAttribute('data-batch')));
    openModal('batchModal');
}

// ── Re-open a modal with the user's input after a validation error ──
<?php if ($reopen): ?>
(function(){
    const d = <?php echo json_encode($reopen['data'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    <?php if ($reopen['type'] === 'row'): ?>
    fillRowModal(d); openModal('rowModal');
    <?php else: ?>
    fillBatchModal(d); openModal('batchModal');
    <?php endif; ?>
})();
<?php endif; ?>

// Scroll to + flash the row that was just edited
<?php if ($hl): ?>
(function(){ const r = $id('row-<?php echo $hl; ?>'); if (r) { r.scrollIntoView({block:'center'}); setTimeout(() => r.classList.remove('hl-row'), 2500); } })();
<?php endif; ?>
</script>

<?php include 'footer.php'; ?>