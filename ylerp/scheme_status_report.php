<?php
/**
 * scheme_status_report.php — Current Scheme Status Report (Vendor Ledger)
 * Upload the "Current Scheme Status Report" Excel, preview it (new / duplicate rows are marked), import only the new rows,
 * then edit / delete rows or a whole upload. The Excel's "Sr No" column is NOT imported (skipped).
 * A row is a duplicate when the same Scheme Activity Type + Activity Code + MOC Ref already exists (in the file or in the database).
 */
date_default_timezone_set('Asia/Colombo');
ob_start();
include 'config.php';

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
    UNIQUE KEY uq_scheme (activity_type, activity_code, moc_ref), INDEX idx_upload (upload_id), INDEX idx_moc (moc_ref)
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
function ss_key($r) { return strtoupper($r['activity_type']) . '|' . strtoupper($r['activity_code']) . '|' . strtoupper($r['moc_ref']); }
$COLS = ['activity_type', 'activity_code', 'activity_desc', 'moc_ref', 'start_date', 'end_date', 'total_budget', 'rs_budget_amount', 'rs_approval_amount', 'tsi_approval_date', 'tsi_approval_amount',
         'payout_amount', 'payout_close_date', 'payout_close_amount', 'pending_payout_amount', 'claim_moc_ref', 'claim_date', 'claim_amount'];

/* ═════════ AJAX ═════════ */
$ajax = $_POST['action'] ?? '';
if ($ajax !== '') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $existing = function ($rows) use ($conn) {      // keys already in the database
        $ex = [];
        $codes = array_values(array_unique(array_map(fn($r) => "'" . mysqli_real_escape_string($conn, $r['activity_code']) . "'", $rows)));
        if (!$codes) return $ex;
        $q = mysqli_query($conn, "SELECT id, activity_type, activity_code, moc_ref FROM scheme_status_items WHERE activity_code IN (" . implode(',', $codes) . ")");
        while ($q && ($x = mysqli_fetch_assoc($q))) $ex[ss_key($x)] = (int)$x['id'];
        return $ex;
    };
    /* preview / duplicate check (read only) */
    if ($ajax === 'check_rows') {
        $in = json_decode($_POST['rows'] ?? '', true); if (!is_array($in)) { echo json_encode(['success' => false, 'message' => 'No rows received.']); exit; }
        $rows = array_map('ss_clean', $in); $ex = $existing($rows); $seen = []; $out = [];
        foreach ($rows as $i => $r) {
            $k = ss_key($r);
            if ($r['activity_code'] === '' || $r['activity_type'] === '') $st = 'invalid';
            elseif (isset($seen[$k])) $st = 'dup_file';
            elseif (isset($ex[$k])) $st = 'dup_db';
            else $st = 'new';
            $seen[$k] = true; $out[] = ['i' => $i, 'status' => $st];
        }
        echo json_encode(['success' => true, 'status' => $out]); exit;
    }
    /* import: only NEW rows are inserted; duplicates (file or database) are skipped */
    if ($ajax === 'import_rows') {
        $in = json_decode($_POST['rows'] ?? '', true); if (!is_array($in) || !$in) { echo json_encode(['success' => false, 'message' => 'No rows received.']); exit; }
        $rows = array_map('ss_clean', $in); $ex = $existing($rows); $seen = []; $new = []; $dup = 0; $bad = 0;
        foreach ($rows as $r) {
            if ($r['activity_code'] === '' || $r['activity_type'] === '') { $bad++; continue; }
            $k = ss_key($r);
            if (isset($seen[$k]) || isset($ex[$k])) { $dup++; $seen[$k] = true; continue; }
            $seen[$k] = true; $new[] = $r;
        }
        if (!$new) { echo json_encode(['success' => false, 'message' => 'Nothing to import — every row is a duplicate' . ($bad ? " or invalid ($bad)" : '') . '. Delete the old rows first if you need to import them again.']); exit; }
        $file = mysqli_real_escape_string($conn, ss_txt($_POST['file_name'] ?? '')); $moc = mysqli_real_escape_string($conn, ss_txt($_POST['moc_ref'] ?? '', 30));
        $rd = ss_date($_POST['report_date'] ?? ''); $by = mysqli_real_escape_string($conn, ss_user());
        mysqli_begin_transaction($conn);
        mysqli_query($conn, "INSERT INTO scheme_status_uploads (file_name, moc_ref, report_date, total_rows, imported_by) VALUES ('$file', '$moc', " . ($rd ? "'$rd'" : 'NULL') . ", " . count($in) . ", '$by')");
        $uid = (int)mysqli_insert_id($conn); $ok = $uid > 0; $n = 0;
        if ($ok) foreach (array_chunk($new, 100) as $chunk) {
            $vals = [];
            foreach ($chunk as $r) { $v = ["$uid"]; foreach ($GLOBALS['COLS'] as $c) $v[] = ss_sql_val($conn, $c, $r[$c]); $vals[] = '(' . implode(',', $v) . ')'; }
            if (!mysqli_query($conn, "INSERT IGNORE INTO scheme_status_items (upload_id, " . implode(',', $GLOBALS['COLS']) . ") VALUES " . implode(',', $vals))) { $ok = false; break; }
            $n += mysqli_affected_rows($conn);
        }
        if (!$ok) { mysqli_rollback($conn); echo json_encode(['success' => false, 'message' => 'Import failed: ' . mysqli_error($conn)]); exit; }
        mysqli_query($conn, "UPDATE scheme_status_uploads SET imported_rows = $n, duplicate_rows = " . ($dup + (count($new) - $n)) . " WHERE id = $uid");
        mysqli_commit($conn);
        echo json_encode(['success' => true, 'imported' => $n, 'duplicates' => $dup + (count($new) - $n), 'invalid' => $bad, 'upload_id' => $uid]); exit;
    }
    if ($ajax === 'update_item') {
        $id = (int)($_POST['id'] ?? 0); $r = ss_clean($_POST);
        if ($id <= 0 || $r['activity_code'] === '' || $r['activity_type'] === '') { echo json_encode(['success' => false, 'message' => 'Activity type and code are required.']); exit; }
        $k = mysqli_real_escape_string($conn, $r['activity_code']); $t = mysqli_real_escape_string($conn, $r['activity_type']); $m = mysqli_real_escape_string($conn, $r['moc_ref']);
        $d = mysqli_query($conn, "SELECT id FROM scheme_status_items WHERE activity_type = '$t' AND activity_code = '$k' AND moc_ref = '$m' AND id <> $id LIMIT 1");
        if ($d && mysqli_num_rows($d)) { echo json_encode(['success' => false, 'message' => 'Another row already has this Activity Type + Code + MOC Ref (duplicate).']); exit; }
        $set = []; foreach ($COLS as $c) $set[] = "$c = " . ss_sql_val($conn, $c, $r[$c]);
        $ok = mysqli_query($conn, "UPDATE scheme_status_items SET " . implode(', ', $set) . " WHERE id = $id");
        echo json_encode(['success' => (bool)$ok, 'message' => $ok ? '' : 'Could not save: ' . mysqli_error($conn)]); exit;
    }
    if ($ajax === 'delete_item') {
        $id = (int)($_POST['id'] ?? 0); $ok = $id > 0 && mysqli_query($conn, "DELETE FROM scheme_status_items WHERE id = $id");
        echo json_encode(['success' => (bool)$ok, 'message' => $ok ? '' : 'Could not delete.']); exit;
    }
    if ($ajax === 'delete_upload') {
        $id = (int)($_POST['id'] ?? 0); $ok = $id > 0;
        if ($ok) { mysqli_query($conn, "DELETE FROM scheme_status_items WHERE upload_id = $id"); $ok = mysqli_query($conn, "DELETE FROM scheme_status_uploads WHERE id = $id"); }
        echo json_encode(['success' => (bool)$ok, 'message' => $ok ? '' : 'Could not delete.']); exit;
    }
    echo json_encode(['success' => false, 'message' => 'Unknown action.']); exit;
}

/* ═════════ page data ═════════ */
$f_up   = (int)($_GET['upload'] ?? 0);
$f_moc  = trim($_GET['moc'] ?? '');
$f_type = trim($_GET['type'] ?? '');
$f_q    = trim($_GET['q'] ?? '');
$w = ['1=1'];
if ($f_up)   $w[] = "i.upload_id = $f_up";
if ($f_moc !== '')  $w[] = "i.moc_ref = '" . mysqli_real_escape_string($conn, $f_moc) . "'";
if ($f_type !== '') $w[] = "i.activity_type = '" . mysqli_real_escape_string($conn, $f_type) . "'";
if ($f_q !== '') { $q = mysqli_real_escape_string($conn, $f_q); $w[] = "(i.activity_code LIKE '%$q%' OR i.activity_desc LIKE '%$q%')"; }
$where = implode(' AND ', $w);
$items = []; $res = mysqli_query($conn, "SELECT i.* FROM scheme_status_items i WHERE $where ORDER BY i.moc_ref DESC, i.activity_type, i.activity_code");
while ($res && ($x = mysqli_fetch_assoc($res))) $items[] = $x;
$tot = ['payout_amount' => 0, 'payout_close_amount' => 0, 'pending_payout_amount' => 0, 'claim_amount' => 0, 'tsi_approval_amount' => 0, 'rs_budget_amount' => 0];
foreach ($items as $x) foreach ($tot as $k => $_) $tot[$k] += (float)$x[$k];
$uploads = []; $res = mysqli_query($conn, "SELECT u.*, (SELECT COUNT(*) FROM scheme_status_items i WHERE i.upload_id = u.id) AS live_rows FROM scheme_status_uploads u ORDER BY u.id DESC LIMIT 60");
while ($res && ($x = mysqli_fetch_assoc($res))) $uploads[] = $x;
$mocs = []; $res = mysqli_query($conn, "SELECT DISTINCT moc_ref FROM scheme_status_items ORDER BY moc_ref DESC"); while ($res && ($x = mysqli_fetch_assoc($res))) $mocs[] = $x['moc_ref'];
$types = []; $res = mysqli_query($conn, "SELECT DISTINCT activity_type FROM scheme_status_items ORDER BY activity_type"); while ($res && ($x = mysqli_fetch_assoc($res))) $types[] = $x['activity_type'];
$can_add = rbac_can('create'); $can_edit = rbac_can('edit'); $can_del = rbac_can('delete');
function ss_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ss_m($v) { return $v === null || $v === '' ? '<span class="dash">-</span>' : number_format((float)$v, 2); }
function ss_d($v) { return $v ? date('d M Y', strtotime($v)) : '<span class="dash">-</span>'; }

/* ═════════ Excel export ═════════ */
if (isset($_GET['export']) && class_exists('ZipArchive')) {
    require_once __DIR__ . '/rbac_xlsx.php';
    while (ob_get_level()) ob_end_clean();
    $X = new MiniXlsx(); $sh = $X->sheet('Scheme Status');
    $H = ['b' => 1, 'color' => 'FFFFFF', 'bg' => '1E3A8A', 'h' => 'center', 'border' => 1, 'wrap' => 1];
    $sh->set(1, 1, 'Current Scheme Status Report', ['b' => 1, 'sz' => 15]);
    $heads = ['Scheme Activity Type', 'Scheme Activity Code', 'Scheme Activity Description', 'MOC Ref', 'Start Date', 'End Date', 'Total Budget', 'RS Budget Amount', 'RS Approval Amount', 'TSI Approval Date', 'TSI Approval Amount', 'Payout Amount', 'Payout Close Date', 'Payout Close Amount', 'Pending Payout Amount', 'Claim MOC Ref', 'Claim Date', 'Claim Amount'];
    foreach ($heads as $i => $h) $sh->set(3, $i + 1, $h, $H);
    $r = 4; $money = [7, 8, 9, 11, 12, 14, 15, 18];
    foreach ($items as $x) { foreach ($COLS as $i => $c) { $v = $x[$c]; $st = ['border' => 1]; if (in_array($i + 1, $money, true)) { $st += ['h' => 'right', 'nf' => 4]; $v = $v === null ? '' : (float)$v; } $sh->set($r, $i + 1, $v === null ? '' : $v, $st); } $r++; }
    foreach ([14, 16, 34, 10, 12, 12, 14, 14, 14, 13, 14, 14, 13, 14, 14, 11, 12, 14] as $i => $wd) $sh->widths[$i + 1] = $wd;
    $sh->freeze = [3, 2]; $tmp = tempnam(sys_get_temp_dir(), 'ss'); $X->save($tmp);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="current_scheme_status_' . date('Ymd_His') . '.xlsx"');
    header('Content-Length: ' . filesize($tmp)); readfile($tmp); @unlink($tmp); exit;
}
ob_end_flush();
include 'header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<style>
.ss{max-width:1650px;margin:0 auto;padding:16px;font-family:'Inter',system-ui,sans-serif;color:#111827}.ss h2{margin:0 0 3px;font-size:22px}.ss .sub{color:#6b7280;font-size:13px;margin:0 0 14px}
.ss .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:14px}.ss .card h3{margin:0 0 10px;font-size:15px;display:flex;gap:8px;align-items:center}
.ss .btn{padding:8px 16px;border:none;border-radius:8px;background:#1e3a8a;color:#fff;font:600 13px inherit;cursor:pointer;text-decoration:none;display:inline-flex;gap:6px;align-items:center}.ss .btn.o{background:#fff;color:#1e3a8a;border:1.5px solid #c7d2fe}.ss .btn.g{background:#15803d}.ss .btn.r{background:#dc2626}.ss .btn:disabled{opacity:.5;cursor:not-allowed}
.ss .row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}.ss .fld{display:flex;flex-direction:column;gap:3px}.ss .fld label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase}
.ss input,.ss select{padding:8px 10px;border:1.5px solid #d1d5db;border-radius:8px;font:inherit;font-size:13px}
.ss .kp{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:12px}.ss .k{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:10px 14px}.ss .k span{font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:700}.ss .k b{display:block;font-size:18px;margin-top:2px;font-variant-numeric:tabular-nums}
.ss .tw{overflow:auto;border:1px solid #e5e7eb;border-radius:10px;background:#fff;max-height:70vh}.ss table{width:100%;border-collapse:collapse;font-size:12.5px}.ss th{background:#1e3a8a;color:#fff;padding:8px 9px;text-align:left;position:sticky;top:0;white-space:nowrap;z-index:1}
.ss td{padding:7px 9px;border-bottom:1px solid #f1f5f9;white-space:nowrap}.ss td.r,.ss th.r{text-align:right;font-variant-numeric:tabular-nums}.ss tr:hover td{background:#f8fafc}.ss .dash{color:#cbd5e1}
.ss .b{display:inline-block;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700}.ss .b.new{background:#dcfce7;color:#166534}.ss .b.dup{background:#fef3c7;color:#92400e}.ss .b.bad{background:#fee2e2;color:#991b1b}
.ss tr.dupr td{background:#fffbeb;color:#92400e}.ss tr.badr td{background:#fef2f2}
.ss .ic{border:none;background:#eef2ff;color:#1e3a8a;width:28px;height:28px;border-radius:7px;cursor:pointer}.ss .ic.d{background:#fee2e2;color:#b91c1c}
.ss .al{padding:10px 14px;border-radius:9px;margin:10px 0;font-size:13px}.ss .al.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}.ss .al.er{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.ss .al.in{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
.ssm.ss{max-width:none;margin:0;padding:14px}.ssm{position:fixed;inset:0;background:rgba(15,23,42,.6);display:none;align-items:center;justify-content:center;z-index:2147483000;padding:14px}.ssm.open{display:flex}
.ssm .bx{background:#fff;border-radius:14px;width:100%;max-width:860px;max-height:92vh;overflow:auto;padding:18px}.ssm .g{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px}
</style>
<div class="ss">
  <h2><i class="fa-solid fa-clipboard-check" style="color:#1e3a8a"></i> Current Scheme Status Report</h2>
  <p class="sub">Upload the Excel, check the preview, import. The <b>Sr No</b> column is skipped. A row is a <b>duplicate</b> when the same Activity Type + Activity Code + MOC Ref is already in the file or already imported — duplicates are skipped.</p>

  <?php if ($can_add): ?>
  <div class="card" id="upCard">
    <h3><i class="fa-solid fa-file-arrow-up"></i> Upload Excel</h3>
    <div class="row"><div class="fld"><label>Excel file (.xlsx)</label><input type="file" id="ssFile" accept=".xlsx,.xls"></div></div>
    <div id="ssMsg"></div>
  </div>
  <?php endif; ?>

  <div class="card"><h3><i class="fa-solid fa-clock-rotate-left"></i> Uploads</h3>
    <div class="tw" style="max-height:220px"><table><thead><tr><th>#</th><th>File</th><th>MOC Ref</th><th>Report date</th><th class="r">Rows in file</th><th class="r">Imported</th><th class="r">Duplicates skipped</th><th class="r">Rows now</th><th>By</th><th>When</th><th></th></tr></thead><tbody>
    <?php foreach ($uploads as $u): ?><tr><td><?= (int)$u['id'] ?></td><td><?= ss_h($u['file_name']) ?></td><td><?= ss_h($u['moc_ref']) ?></td><td><?= ss_d($u['report_date']) ?></td><td class="r"><?= (int)$u['total_rows'] ?></td><td class="r"><?= (int)$u['imported_rows'] ?></td><td class="r"><?= (int)$u['duplicate_rows'] ?></td><td class="r"><?= (int)$u['live_rows'] ?></td><td><?= ss_h($u['imported_by']) ?></td><td><?= ss_h($u['created_at']) ?></td>
      <td><a class="btn o" style="padding:3px 10px" href="?upload=<?= (int)$u['id'] ?>">View</a> <?php if ($can_del): ?><button class="ic d" type="button" title="Delete this whole upload" data-delup="<?= (int)$u['id'] ?>" data-nm="<?= ss_h($u['file_name']) ?>"><i class="fa-solid fa-trash"></i></button><?php endif; ?></td></tr>
    <?php endforeach; if (!$uploads) echo '<tr><td colspan="11" style="text-align:center;color:#9ca3af;padding:18px">Nothing uploaded yet.</td></tr>'; ?></tbody></table></div></div>

  <form class="card" method="get"><div class="row">
    <div class="fld"><label>MOC Ref</label><select name="moc"><option value="">All</option><?php foreach ($mocs as $m): ?><option <?= $f_moc === $m ? 'selected' : '' ?>><?= ss_h($m) ?></option><?php endforeach; ?></select></div>
    <div class="fld"><label>Activity type</label><select name="type"><option value="">All</option><?php foreach ($types as $t): ?><option <?= $f_type === $t ? 'selected' : '' ?>><?= ss_h($t) ?></option><?php endforeach; ?></select></div>
    <div class="fld"><label>Search</label><input type="search" name="q" value="<?= ss_h($f_q) ?>" placeholder="code or description"></div>
    <?php if ($f_up): ?><input type="hidden" name="upload" value="<?= $f_up ?>"><?php endif; ?>
    <button class="btn" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Show</button><a class="btn o" href="scheme_status_report.php">Reset</a>
    <a class="btn o" href="?<?= ss_h(http_build_query(array_merge($_GET, ['export' => 1]))) ?>"><i class="fa-solid fa-file-excel"></i> Export Excel</a></div></form>

  <div class="kp">
    <div class="k"><span>Rows</span><b><?= number_format(count($items)) ?></b></div><div class="k"><span>RS budget</span><b><?= number_format($tot['rs_budget_amount'], 2) ?></b></div>
    <div class="k"><span>TSI approval</span><b><?= number_format($tot['tsi_approval_amount'], 2) ?></b></div><div class="k"><span>Payout</span><b style="color:#1d4ed8"><?= number_format($tot['payout_amount'], 2) ?></b></div>
    <div class="k"><span>Payout closed</span><b style="color:#15803d"><?= number_format($tot['payout_close_amount'], 2) ?></b></div><div class="k"><span>Pending payout</span><b style="color:#b91c1c"><?= number_format($tot['pending_payout_amount'], 2) ?></b></div>
    <div class="k"><span>Claim</span><b style="color:#7c3aed"><?= number_format($tot['claim_amount'], 2) ?></b></div></div>

  <div class="tw"><table><thead><tr><th>#</th><th>Type</th><th>Activity code</th><th>Description</th><th>MOC Ref</th><th>Start</th><th>End</th><th class="r">Total budget</th><th class="r">RS budget</th><th class="r">RS approval</th><th>TSI date</th><th class="r">TSI amount</th><th class="r">Payout</th><th>Payout close</th><th class="r">Close amt</th><th class="r">Pending</th><th>Claim MOC</th><th>Claim date</th><th class="r">Claim amt</th><th></th></tr></thead><tbody>
  <?php foreach ($items as $n => $x): ?><tr><td><?= $n + 1 ?></td><td><b><?= ss_h($x['activity_type']) ?></b></td><td><?= ss_h($x['activity_code']) ?></td><td style="white-space:normal;min-width:220px"><?= ss_h($x['activity_desc']) ?></td><td><?= ss_h($x['moc_ref']) ?></td><td><?= ss_d($x['start_date']) ?></td><td><?= ss_d($x['end_date']) ?></td>
    <td class="r"><?= ss_m($x['total_budget']) ?></td><td class="r"><?= ss_m($x['rs_budget_amount']) ?></td><td class="r"><?= ss_m($x['rs_approval_amount']) ?></td><td><?= $x['tsi_approval_date'] !== '' && $x['tsi_approval_date'] !== null ? ss_h($x['tsi_approval_date']) : '<span class="dash">-</span>' ?></td><td class="r"><?= ss_m($x['tsi_approval_amount']) ?></td>
    <td class="r"><b><?= ss_m($x['payout_amount']) ?></b></td><td><?= ss_d($x['payout_close_date']) ?></td><td class="r"><?= ss_m($x['payout_close_amount']) ?></td><td class="r"><?= ss_m($x['pending_payout_amount']) ?></td><td><?= $x['claim_moc_ref'] ? ss_h($x['claim_moc_ref']) : '<span class="dash">-</span>' ?></td><td><?= ss_d($x['claim_date']) ?></td><td class="r"><?= ss_m($x['claim_amount']) ?></td>
    <td><?php if ($can_edit): ?><button class="ic" type="button" title="Edit" data-edit='<?= ss_h(json_encode($x)) ?>'><i class="fa-solid fa-pen"></i></button> <?php endif; ?><?php if ($can_del): ?><button class="ic d" type="button" title="Delete" data-del="<?= (int)$x['id'] ?>" data-nm="<?= ss_h($x['activity_code']) ?>"><i class="fa-solid fa-trash"></i></button><?php endif; ?></td></tr>
  <?php endforeach; if (!$items) echo '<tr><td colspan="20" style="text-align:center;color:#9ca3af;padding:26px">No rows. Upload the Current Scheme Status Report Excel above.</td></tr>'; ?></tbody></table></div>
</div>

<?php if ($can_add): ?>
<div class="ssm ss" id="ssPrevModal"><div class="bx" style="max-width:96vw;height:94vh;display:flex;flex-direction:column;padding:0;overflow:hidden">
  <div style="padding:14px 18px;background:linear-gradient(120deg,#0f172a,#1e3a8a);color:#fff;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
    <div style="font-weight:800;font-size:16px"><i class="fa-solid fa-table-list"></i> Preview — Current Scheme Status</div><div id="ssPrevFile" style="opacity:.8;font-size:12.5px"></div>
    <div style="margin-left:auto;display:flex;gap:8px"><button class="btn g" id="ssImport" type="button" disabled><i class="fa-solid fa-check"></i> Import new rows</button><button class="btn o" id="ssCancel" type="button">Cancel</button></div></div>
  <div id="ssSum" style="padding:10px 18px 0"></div>
  <div style="padding:8px 18px 14px;flex:1;min-height:0"><div class="tw" style="max-height:none;height:100%"><table><thead><tr><th>#</th><th>Status</th><th>Type</th><th>Activity code</th><th>Description</th><th>MOC Ref</th><th>Start</th><th>End</th><th class="r">Total budget</th><th class="r">RS budget</th><th class="r">RS approval</th><th>TSI date</th><th class="r">TSI amount</th><th class="r">Payout</th><th>Payout close</th><th class="r">Close amt</th><th class="r">Pending</th><th>Claim MOC</th><th>Claim date</th><th class="r">Claim amt</th></tr></thead><tbody id="ssPrevBody"></tbody></table></div></div>
</div></div>
<?php endif; ?>
<div class="ssm ss" id="ssEdit"><div class="bx"><h3 style="margin:0 0 12px">Edit scheme row</h3><input type="hidden" id="e_id"><div class="g" id="e_g"></div>
  <div id="e_msg"></div><div class="row" style="margin-top:14px;justify-content:flex-end"><button class="btn o" type="button" id="e_x">Cancel</button><button class="btn g" type="button" id="e_s"><i class="fa-solid fa-floppy-disk"></i> Save</button></div></div></div>
<script>
(function(){
  var $=function(i){ return document.getElementById(i); }, esc=function(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
  document.body.appendChild($('ssEdit')); if($('ssPrevModal')) document.body.appendChild($('ssPrevModal'));   // above the site header
  function post(action,data){ var fd=new FormData(); fd.append('action',action); Object.keys(data||{}).forEach(function(k){ fd.append(k,data[k]); });
    return fetch('scheme_status_report.php',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){ return r.json().catch(function(){ return {success:false,message:'Server error (blocked or invalid response).'}; }); }); }
  var FIELDS=[['activity_type','Activity type'],['activity_code','Activity code'],['activity_desc','Description'],['moc_ref','MOC Ref'],['start_date','Start date','date'],['end_date','End date','date'],['total_budget','Total budget','num'],['rs_budget_amount','RS budget amount','num'],['rs_approval_amount','RS approval amount','num'],
    ['tsi_approval_date','TSI approval date'],['tsi_approval_amount','TSI approval amount','num'],['payout_amount','Payout amount','num'],['payout_close_date','Payout close date','date'],['payout_close_amount','Payout close amount','num'],['pending_payout_amount','Pending payout amount','num'],['claim_moc_ref','Claim MOC Ref'],['claim_date','Claim date','date'],['claim_amount','Claim amount','num']];

  /* ── read the Excel (Sr No column is never used) ── */
  var ROWS=[], FILEN='', MOC='', RDATE='';
  function iso(v){ if(v instanceof Date && !isNaN(v)){ var z=new Date(v.getTime()-v.getTimezoneOffset()*60000); return z.toISOString().slice(0,10); } var s=String(v==null?'':v).trim(); if(!s||s==='-') return ''; var m=s.match(/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/); if(m) return m[3]+'-'+('0'+m[2]).slice(-2)+'-'+('0'+m[1]).slice(-2); return /^\d{4}-\d{2}-\d{2}/.test(s)?s.slice(0,10):''; }
  function txt(v){ if(v instanceof Date) return iso(v); var s=String(v==null?'':v).trim(); return s==='-'?'':s; }
  var fileEl=$('ssFile'); if(fileEl) fileEl.addEventListener('change',function(){
    var f=this.files[0]; ROWS=[]; $('ssMsg').innerHTML=''; if(!f) return; FILEN=f.name; var rd=new FileReader();
    rd.onload=function(e){ try{
      var wb=XLSX.read(e.target.result,{type:'array',cellDates:true}), ws=wb.Sheets[wb.SheetNames[0]], a=XLSX.utils.sheet_to_json(ws,{header:1,defval:'',raw:true}), hi=-1, map={};
      MOC=''; RDATE=''; for(var i=0;i<Math.min(a.length,12);i++){ a[i].forEach(function(c,j){ var t=String(c).toLowerCase(); if(t.indexOf('date:')===0&&a[i][j+1]!=='') RDATE=iso(a[i][j+1])||(String(a[i][j+1]).match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/)?String(a[i][j+1]).replace(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/,'$3-$2-$1'):''); if(t.indexOf('moc refr no')===0||t.indexOf('moc ref no')===0) MOC=String(a[i][j+1]||'').trim(); }); }
      for(var i=0;i<Math.min(a.length,15);i++){ var low=a[i].map(function(x){ return String(x).toLowerCase().trim(); }); if(low.indexOf('scheme activity code')>-1){ hi=i; low.forEach(function(h,j){ map[h]=j; }); break; } }
      if(hi<0) throw new Error('"Scheme Activity Code" column not found — is this the Current Scheme Status Report?');
      var col=function(n){ var j=map[n]; return j==null?-1:j; }, C={type:col('scheme activity type'),code:col('scheme activity code'),desc:col('scheme activity description'),moc:col('moc ref'),sd:col('start date'),ed:col('end date'),tb:col('total budget'),rb:col('rs budget amount'),ra:col('rs approval amount'),
        td:col('tsi approval date'),ta:col('tsi approval amount'),pa:col('payout amount'),pd:col('payout close date'),pc:col('payout close amount'),pp:col('pending payout amount'),cm:col('claim moc ref'),cd:col('claim date'),ca:col('claim amount')};
      var g=function(r,k){ return C[k]>-1?r[C[k]]:''; };
      for(var j=hi+1;j<a.length;j++){ var r=a[j]; if(!txt(g(r,'code'))&&!txt(g(r,'type'))) continue;
        ROWS.push({activity_type:txt(g(r,'type')),activity_code:txt(g(r,'code')),activity_desc:txt(g(r,'desc')),moc_ref:txt(g(r,'moc')),start_date:iso(g(r,'sd')),end_date:iso(g(r,'ed')),total_budget:txt(g(r,'tb')),rs_budget_amount:txt(g(r,'rb')),rs_approval_amount:txt(g(r,'ra')),
          tsi_approval_date:txt(g(r,'td')),tsi_approval_amount:txt(g(r,'ta')),payout_amount:txt(g(r,'pa')),payout_close_date:iso(g(r,'pd')),payout_close_amount:txt(g(r,'pc')),pending_payout_amount:txt(g(r,'pp')),claim_moc_ref:txt(g(r,'cm')),claim_date:iso(g(r,'cd')),claim_amount:txt(g(r,'ca'))}); }
      if(!ROWS.length) throw new Error('No data rows found.'); if(!MOC) MOC=ROWS[0].moc_ref;
      $('ssMsg').innerHTML='<div class="al in">Checking '+ROWS.length+' rows for duplicates…</div>';
      post('check_rows',{rows:JSON.stringify(ROWS)}).then(function(res){ if(!res.success){ $('ssMsg').innerHTML='<div class="al er">'+esc(res.message||'Check failed')+'</div>'; return; } preview(res.status); });
    }catch(err){ $('ssMsg').innerHTML='<div class="al er">Could not read the file: '+esc(err.message)+'</div>'; } };
    rd.readAsArrayBuffer(f); });
  function money(v){ if(v===''||v==null) return '<span class="dash">-</span>'; var n=parseFloat(String(v).replace(/,/g,'')); return isNaN(n)?esc(v):n.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
  function preview(st){ var cnt={new:0,dup_file:0,dup_db:0,invalid:0}, h='';
    ROWS.forEach(function(r,i){ var s=st[i]?st[i].status:'new'; cnt[s]++; var b=s==='new'?'<span class="b new">NEW</span>':(s==='invalid'?'<span class="b bad">INVALID</span>':'<span class="b dup">'+(s==='dup_file'?'DUPLICATE IN FILE':'ALREADY IMPORTED')+'</span>');
      h+='<tr class="'+(s==='new'?'':(s==='invalid'?'badr':'dupr'))+'"><td>'+(i+1)+'</td><td>'+b+'</td><td>'+esc(r.activity_type)+'</td><td>'+esc(r.activity_code)+'</td><td style="white-space:normal;min-width:200px">'+esc(r.activity_desc)+'</td><td>'+esc(r.moc_ref)+'</td><td>'+esc(r.start_date)+'</td><td>'+esc(r.end_date)+'</td><td class="r">'+money(r.total_budget)+'</td><td class="r">'+money(r.rs_budget_amount)+'</td><td class="r">'+money(r.rs_approval_amount)+'</td><td>'+esc(r.tsi_approval_date)+'</td><td class="r">'+money(r.tsi_approval_amount)+'</td><td class="r">'+money(r.payout_amount)+'</td><td>'+esc(r.payout_close_date)+'</td><td class="r">'+money(r.payout_close_amount)+'</td><td class="r">'+money(r.pending_payout_amount)+'</td><td>'+esc(r.claim_moc_ref)+'</td><td>'+esc(r.claim_date)+'</td><td class="r">'+money(r.claim_amount)+'</td></tr>'; });
    $('ssPrevBody').innerHTML=h; $('ssPrevFile').textContent=FILEN+(MOC?' · MOC '+MOC:''); $('ssMsg').innerHTML=''; $('ssPrevModal').classList.add('open'); document.body.style.overflow='hidden';
    $('ssSum').innerHTML='<div class="al '+(cnt.new?'ok':'er')+'"><b>'+ROWS.length+'</b> rows read (Sr No skipped) · <b>'+cnt.new+'</b> new · <b>'+(cnt.dup_file+cnt.dup_db)+'</b> duplicates (will be skipped'+(cnt.dup_db?' — '+cnt.dup_db+' already imported':'')+')'+(cnt.invalid?' · <b>'+cnt.invalid+'</b> invalid':'')+'.</div>';
    $('ssImport').disabled=!cnt.new; $('ssImport').innerHTML='<i class="fa-solid fa-check"></i> Import '+cnt.new+' new rows'; }
  function closePrev(){ $('ssPrevModal').classList.remove('open'); document.body.style.overflow=''; }
  $('ssCancel')&&$('ssCancel').addEventListener('click',function(){ ROWS=[]; fileEl.value=''; closePrev(); $('ssImport').disabled=true; });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape'&&$('ssPrevModal')&&$('ssPrevModal').classList.contains('open')) closePrev(); });
  $('ssImport')&&$('ssImport').addEventListener('click',function(){ var b=this; b.disabled=true; b.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Importing…';
    post('import_rows',{rows:JSON.stringify(ROWS),file_name:FILEN,moc_ref:MOC,report_date:RDATE}).then(function(res){
      if(!res.success){ $('ssSum').innerHTML='<div class="al er">'+esc(res.message||'Import failed')+'</div>'; b.disabled=false; b.innerHTML='<i class="fa-solid fa-check"></i> Import new rows'; return; }
      $('ssSum').innerHTML='<div class="al ok">Imported <b>'+res.imported+'</b> rows · '+res.duplicates+' duplicates skipped'+(res.invalid?' · '+res.invalid+' invalid':'')+'. Reloading…</div>'; setTimeout(function(){ location.href='scheme_status_report.php?upload='+res.upload_id; },1200); }); });

  /* ── edit / delete ── */
  document.addEventListener('click',function(e){
    var ed=e.target.closest('[data-edit]'), dl=e.target.closest('[data-del]'), du=e.target.closest('[data-delup]');
    if(ed){ var d=JSON.parse(ed.dataset.edit); $('e_id').value=d.id; $('e_msg').innerHTML='';
      $('e_g').innerHTML=FIELDS.map(function(f){ var v=d[f[0]]==null?'':d[f[0]]; return '<div class="fld"><label>'+f[1]+'</label><input id="f_'+f[0]+'" type="'+(f[2]==='date'?'date':'text')+'" '+(f[2]==='num'?'inputmode="decimal"':'')+' value="'+esc(v)+'"></div>'; }).join(''); $('ssEdit').classList.add('open'); }
    if(dl){ if(!confirm('Delete scheme row "'+dl.dataset.nm+'"?')) return; post('delete_item',{id:dl.dataset.del}).then(function(r){ if(r.success) location.reload(); else alert(r.message||'Could not delete.'); }); }
    if(du){ if(!confirm('Delete the WHOLE upload "'+du.dataset.nm+'" with all its rows? This cannot be undone.')) return; post('delete_upload',{id:du.dataset.delup}).then(function(r){ if(r.success) location.href='scheme_status_report.php'; else alert(r.message||'Could not delete.'); }); }
  });
  $('e_x').addEventListener('click',function(){ $('ssEdit').classList.remove('open'); });
  $('e_s').addEventListener('click',function(){ var d={id:$('e_id').value}; FIELDS.forEach(function(f){ d[f[0]]=$('f_'+f[0]).value; });
    post('update_item',d).then(function(r){ if(r.success) location.reload(); else $('e_msg').innerHTML='<div class="al er">'+esc(r.message||'Could not save.')+'</div>'; }); });
})();
</script>
<?php include 'footer.php'; ?>
