<?php
/**
 * cash_collectors.php
 * ─────────────────────────────────────────────────────────────────────
 * Master ▸ Cash Collectors
 *
 *  • Lists the delivery persons found on the field summaries of the
 *    two most recent delivery dates (field_summary.delivery_person_raw_name).
 *  • Each delivery person can be given a Ref Code (mobile number).
 *    Ref codes are kept in their own table: cash_collectors.
 *  • Every create / change / clear of a ref code is written to
 *    cash_collectors_history (old value, new value, who, when, IP).
 *  • "All saved" view also shows collectors saved earlier who are not
 *    on the latest two dates.
 * ─────────────────────────────────────────────────────────────────────
 */
date_default_timezone_set('Asia/Colombo');

/* config.php ends with stray whitespace after "?>" – swallow it so JSON / redirects work */
ob_start();
include_once 'config.php';
ob_end_clean();
include_once 'auth.php';
requireLogin();

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['ccol_csrf'])) $_SESSION['ccol_csrf'] = bin2hex(random_bytes(16));

function ccol_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* PHP 8.1+ throws on a failed query, older PHP returns false — behave the same on both */
function ccol_q($conn, $sql) {
    try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; }
}

/* trim + collapse inner spaces — the same name typed twice must match */
function ccol_norm_name($n) {
    return trim(preg_replace('/\s+/u', ' ', (string)$n));
}
function ccol_key($n) {
    return function_exists('mb_strtolower') ? mb_strtolower(ccol_norm_name($n), 'UTF-8') : strtolower(ccol_norm_name($n));
}

/**
 * Validate a Sri Lankan mobile number and return it as 07XXXXXXXX.
 * Accepts 07XXXXXXXX, 7XXXXXXXX, 947XXXXXXXX, +947XXXXXXXX (spaces / dashes allowed).
 * Returns '' for empty input, false when invalid.
 */
function ccol_norm_mobile($m) {
    $m = preg_replace('/[\s\-\(\)\.]/', '', (string)$m);
    if ($m === '') return '';
    if (preg_match('/^(?:\+?94|0)?(7\d{8})$/', $m, $mm)) return '0' . $mm[1];
    return false;
}

function ccol_ensure($conn) {
    ccol_q($conn, "CREATE TABLE IF NOT EXISTS cash_collectors (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        delivery_person  VARCHAR(191) NOT NULL,
        ref_code         VARCHAR(20)  NULL,
        last_seen_date   DATE         NULL,
        created_by       VARCHAR(100) NULL,
        updated_by       VARCHAR(100) NULL,
        created_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        updated_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_dp (delivery_person),
        INDEX idx_ref (ref_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    ccol_q($conn, "CREATE TABLE IF NOT EXISTS cash_collectors_history (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        collector_id     INT          NOT NULL,
        delivery_person  VARCHAR(255) NOT NULL,
        action           VARCHAR(20)  NOT NULL,
        old_ref_code     VARCHAR(20)  NULL,
        new_ref_code     VARCHAR(20)  NULL,
        changed_by       VARCHAR(100) NULL,
        changed_at       DATETIME     NOT NULL,
        ip_address       VARCHAR(45)  NULL,
        INDEX idx_collector (collector_id),
        INDEX idx_changed (changed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
ccol_ensure($conn);

$ccol_user = isset($_SESSION['username']) ? (string)$_SESSION['username'] : 'unknown';

/**
 * Save one ref code. Returns ['ok'=>bool, 'msg'=>..., 'changed'=>bool, 'ref_code'=>..., 'id'=>...]
 */
function ccol_save_one($conn, $name, $rawRef, $lastSeen, $user) {
    $name = ccol_norm_name($name);
    if ($name === '' || !preg_match('//u', $name) || mb_strlen($name, 'UTF-8') > 191) {
        return ['ok' => false, 'msg' => 'Invalid delivery person name.'];
    }
    $ref = ccol_norm_mobile($rawRef);
    if ($ref === false) {
        return ['ok' => false, 'msg' => "“$name”: enter a valid mobile number (e.g. 0771234567)."];
    }
    $lastSeen = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$lastSeen) ? $lastSeen : null;

    /* same mobile already given to another person? */
    if ($ref !== '') {
        $st = mysqli_prepare($conn, "SELECT delivery_person FROM cash_collectors WHERE ref_code = ? AND delivery_person <> ? LIMIT 1");
        mysqli_stmt_bind_param($st, 'ss', $ref, $name);
        mysqli_stmt_execute($st);
        $rs = mysqli_stmt_get_result($st);
        $dup = $rs ? mysqli_fetch_assoc($rs) : null;
        mysqli_stmt_close($st);
        if ($dup) {
            return ['ok' => false, 'msg' => "Mobile $ref is already the ref code of “{$dup['delivery_person']}”."];
        }
    }

    $st = mysqli_prepare($conn, "SELECT id, ref_code FROM cash_collectors WHERE delivery_person = ? LIMIT 1");
    mysqli_stmt_bind_param($st, 's', $name);
    mysqli_stmt_execute($st);
    $rs  = mysqli_stmt_get_result($st);
    $cur = $rs ? mysqli_fetch_assoc($rs) : null;
    mysqli_stmt_close($st);

    $old = $cur ? (string)$cur['ref_code'] : '';
    if ($cur && $old === $ref) {
        return ['ok' => true, 'changed' => false, 'ref_code' => $ref, 'id' => (int)$cur['id'], 'msg' => 'No change.'];
    }
    if (!$cur && $ref === '') {
        return ['ok' => true, 'changed' => false, 'ref_code' => '', 'id' => 0, 'msg' => 'No change.'];
    }

    $now = date('Y-m-d H:i:s');
    $ip  = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $refDb = ($ref === '') ? null : $ref;

    mysqli_begin_transaction($conn);
    try {
        if ($cur) {
            $id = (int)$cur['id'];
            $st = mysqli_prepare($conn, "UPDATE cash_collectors
                                            SET ref_code = ?, updated_by = ?,
                                                last_seen_date = COALESCE(?, last_seen_date)
                                          WHERE id = ?");
            mysqli_stmt_bind_param($st, 'sssi', $refDb, $user, $lastSeen, $id);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
            $action = ($ref === '') ? 'cleared' : ($old === '' ? 'added' : 'updated');
        } else {
            $st = mysqli_prepare($conn, "INSERT INTO cash_collectors
                                            (delivery_person, ref_code, last_seen_date, created_by, updated_by)
                                         VALUES (?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($st, 'sssss', $name, $refDb, $lastSeen, $user, $user);
            mysqli_stmt_execute($st);
            $id = (int)mysqli_insert_id($conn);
            mysqli_stmt_close($st);
            $action = 'added';
        }

        $oldDb = ($old === '') ? null : $old;
        $st = mysqli_prepare($conn, "INSERT INTO cash_collectors_history
                                        (collector_id, delivery_person, action, old_ref_code, new_ref_code, changed_by, changed_at, ip_address)
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($st, 'isssssss', $id, $name, $action, $oldDb, $refDb, $user, $now, $ip);
        mysqli_stmt_execute($st);
        mysqli_stmt_close($st);

        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        return ['ok' => false, 'msg' => "“$name” could not be saved. Try again."];
    }

    return ['ok' => true, 'changed' => true, 'ref_code' => $ref, 'id' => $id, 'action' => $action,
            'updated_by' => $user, 'updated_at' => $now, 'msg' => 'Saved.'];
}

/* ═════════════════════════ AJAX ═════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    if (!hash_equals((string)$_SESSION['ccol_csrf'], (string)($_POST['csrf'] ?? ''))) {
        echo json_encode(['ok' => false, 'msg' => 'Security check failed. Reload the page and try again.']);
        exit;
    }

    $act = (string)($_POST['ajax'] ?? '');

    if ($act === 'save') {
        $items = json_decode((string)($_POST['items'] ?? '[]'), true);
        if (!is_array($items) || !$items) {
            echo json_encode(['ok' => false, 'msg' => 'Nothing to save.']);
            exit;
        }
        if (count($items) > 500) $items = array_slice($items, 0, 500);

        $results = []; $saved = 0; $failed = 0;
        foreach ($items as $it) {
            $r = ccol_save_one($conn,
                               (string)($it['name'] ?? ''),
                               (string)($it['ref'] ?? ''),
                               (string)($it['last_seen'] ?? ''),
                               $ccol_user);
            $r['key'] = (string)($it['key'] ?? '');
            $results[] = $r;
            if (!$r['ok']) $failed++; elseif (!empty($r['changed'])) $saved++;
        }
        echo json_encode(['ok' => $failed === 0, 'saved' => $saved, 'failed' => $failed, 'results' => $results],
                         JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    if ($act === 'history') {
        $name = ccol_norm_name((string)($_POST['name'] ?? ''));
        $rows = [];
        $st = mysqli_prepare($conn, "SELECT action, old_ref_code, new_ref_code, changed_by, changed_at, ip_address
                                       FROM cash_collectors_history
                                      WHERE delivery_person = ?
                                      ORDER BY changed_at DESC, id DESC
                                      LIMIT 200");
        mysqli_stmt_bind_param($st, 's', $name);
        mysqli_stmt_execute($st);
        $rs = mysqli_stmt_get_result($st);
        if ($rs) while ($row = mysqli_fetch_assoc($rs)) $rows[] = $row;
        mysqli_stmt_close($st);
        echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Unknown action.']);
    exit;
}

/* ═════════════════════════ PAGE DATA ═════════════════════════ */
$view = (($_GET['view'] ?? '') === 'all') ? 'all' : 'recent';
$tab  = (($_GET['tab']  ?? '') === 'history') ? 'history' : 'list';

/* 1. latest two delivery dates on field summaries */
$recent_dates = [];
$r = ccol_q($conn, "SELECT DISTINCT delivery_date
                      FROM field_summary
                     WHERE delivery_person_raw_name IS NOT NULL
                       AND delivery_person_raw_name <> ''
                       AND delivery_date IS NOT NULL
                     ORDER BY delivery_date DESC
                     LIMIT 2");
if ($r) while ($row = mysqli_fetch_assoc($r)) $recent_dates[] = $row['delivery_date'];

/* 2. delivery persons on those dates */
$people = [];   /* key => [...] */
if ($recent_dates) {
    $in = implode(',', array_map(function ($d) use ($conn) {
        return "'" . mysqli_real_escape_string($conn, $d) . "'";
    }, $recent_dates));
    $r = ccol_q($conn, "SELECT fs.delivery_person_raw_name AS dp,
                               fs.delivery_date,
                               fs.field_summary_code,
                               fs.route
                          FROM field_summary fs
                         WHERE fs.delivery_date IN ($in)
                           AND fs.delivery_person_raw_name IS NOT NULL
                           AND fs.delivery_person_raw_name <> ''
                         ORDER BY fs.delivery_date DESC, fs.id DESC");
    if ($r) while ($row = mysqli_fetch_assoc($r)) {
        $name = ccol_norm_name($row['dp']);
        if ($name === '') continue;
        $k = ccol_key($name);
        if (!isset($people[$k])) {
            $people[$k] = [
                'name' => $name, 'last_date' => $row['delivery_date'], 'dates' => [],
                'summaries' => [], 'routes' => [], 'recent' => true,
                'id' => 0, 'ref_code' => '', 'updated_by' => '', 'updated_at' => '', 'changes' => 0,
            ];
        }
        $people[$k]['dates'][$row['delivery_date']] = true;
        if (!empty($row['field_summary_code'])) $people[$k]['summaries'][$row['field_summary_code']] = true;
        if (!empty($row['route']))              $people[$k]['routes'][trim($row['route'])] = true;
    }
}

/* 3. saved ref codes */
$r = ccol_q($conn, "SELECT c.id, c.delivery_person, c.ref_code, c.last_seen_date, c.updated_by, c.updated_at,
                           (SELECT COUNT(*) FROM cash_collectors_history h WHERE h.collector_id = c.id) AS changes
                      FROM cash_collectors c");
if ($r) while ($row = mysqli_fetch_assoc($r)) {
    $k = ccol_key($row['delivery_person']);
    if (!isset($people[$k])) {
        if ($view !== 'all') continue;
        $people[$k] = [
            'name' => ccol_norm_name($row['delivery_person']), 'last_date' => $row['last_seen_date'],
            'dates' => [], 'summaries' => [], 'routes' => [], 'recent' => false,
        ];
    }
    $people[$k]['id']         = (int)$row['id'];
    $people[$k]['ref_code']   = (string)$row['ref_code'];
    $people[$k]['updated_by'] = (string)$row['updated_by'];
    $people[$k]['updated_at'] = (string)$row['updated_at'];
    $people[$k]['changes']    = (int)$row['changes'];
}

uasort($people, function ($a, $b) {
    if ($a['recent'] !== $b['recent']) return $a['recent'] ? -1 : 1;
    return strcasecmp($a['name'], $b['name']);
});

$cnt_total = count($people);
$cnt_with  = 0;
foreach ($people as $p) if ($p['ref_code'] !== '') $cnt_with++;
$cnt_missing = $cnt_total - $cnt_with;

/* 4. update history (tab) */
$h_from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['h_from'] ?? '')) ? $_GET['h_from'] : date('Y-m-d', strtotime('-30 days'));
$h_to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['h_to']   ?? '')) ? $_GET['h_to']   : date('Y-m-d');
$h_q    = trim((string)($_GET['h_q'] ?? ''));
$history = [];
if ($tab === 'history') {
    $sql = "SELECT id, delivery_person, action, old_ref_code, new_ref_code, changed_by, changed_at, ip_address
              FROM cash_collectors_history
             WHERE changed_at >= ? AND changed_at < DATE_ADD(?, INTERVAL 1 DAY)";
    $types = 'ss'; $args = [$h_from, $h_to];
    if ($h_q !== '') {
        $sql .= " AND (delivery_person LIKE ? OR old_ref_code LIKE ? OR new_ref_code LIKE ? OR changed_by LIKE ?)";
        $like = '%' . $h_q . '%';
        $types .= 'ssss'; array_push($args, $like, $like, $like, $like);
    }
    $sql .= " ORDER BY changed_at DESC, id DESC LIMIT 1000";
    try {
        $st = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($st, $types, ...$args);
        mysqli_stmt_execute($st);
        $rs = mysqli_stmt_get_result($st);
        if ($rs) while ($row = mysqli_fetch_assoc($rs)) $history[] = $row;
        mysqli_stmt_close($st);
    } catch (Throwable $e) { $history = []; }
}

function ccol_fmt_date($d) { return $d ? date('d M Y', strtotime($d)) : '—'; }
function ccol_fmt_dt($d)   { return $d ? date('d M Y, h:i A', strtotime($d)) : '—'; }

include 'header.php';
?>
<style>
.ccol { font-family: 'Inter', system-ui, sans-serif; font-size: 13px; color: #111; padding: 22px 26px 60px; }
.ccol *, .ccol *::before, .ccol *::after { box-sizing: border-box; }
.ccol .top { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 12px; }
.ccol h1 { margin: 0; font-size: 21px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
.ccol h1 i { color: #7c3aed; }
.ccol .sub { margin: 4px 0 0; color: #666; }
.ccol .btn { display: inline-flex; align-items: center; gap: 6px; height: 36px; padding: 0 16px; border: 1px solid transparent; border-radius: 8px; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; white-space: nowrap; }
.ccol .btn-dark { background: #000; color: #fff; }
.ccol .btn-dark:hover { background: #333; }
.ccol .btn-light { background: #fff; color: #333; border-color: #e5e5e5; }
.ccol .btn-light:hover { background: #f5f5f5; }
.ccol .btn-sm { height: 30px; padding: 0 11px; font-size: 12.5px; }
.ccol .btn:disabled { opacity: .4; cursor: not-allowed; }
.ccol .btn:focus-visible, .ccol input:focus-visible, .ccol a:focus-visible { outline: 2px solid #7c3aed; outline-offset: 2px; }

.ccol .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin: 18px 0; }
.ccol .stat { background: #fff; border: 1px solid #e5e5e5; border-radius: 12px; padding: 14px 16px; }
.ccol .stat .lbl { color: #666; font-size: 12px; font-weight: 500; }
.ccol .stat .val { font-size: 22px; font-weight: 700; margin-top: 4px; }
.ccol .stat .val.small { font-size: 14px; font-weight: 600; line-height: 1.5; }
.ccol .stat.ok .val { color: #15803d; }
.ccol .stat.warn .val { color: #b45309; }

.ccol .tabs { display: flex; gap: 4px; border-bottom: 1px solid #e5e5e5; margin-bottom: 14px; }
.ccol .tabs a { padding: 10px 14px; color: #555; text-decoration: none; font-weight: 600; border-bottom: 2px solid transparent; margin-bottom: -1px; display: inline-flex; gap: 7px; align-items: center; }
.ccol .tabs a.on { color: #111; border-bottom-color: #7c3aed; }

.ccol .toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 12px; }
.ccol .toolbar .grow { flex: 1 1 220px; }
.ccol .seg { display: inline-flex; border: 1px solid #e5e5e5; border-radius: 8px; overflow: hidden; }
.ccol .seg a { padding: 8px 13px; text-decoration: none; color: #555; font-weight: 600; background: #fff; }
.ccol .seg a.on { background: #111; color: #fff; }
.ccol input[type="text"], .ccol input[type="search"], .ccol input[type="date"], .ccol input[type="tel"] { height: 36px; padding: 0 11px; border: 1px solid #ddd; border-radius: 8px; font: inherit; font-size: 13px; background: #fff; }
.ccol input.search { width: 100%; }

.ccol .card { background: #fff; border: 1px solid #e5e5e5; border-radius: 12px; overflow-x: auto; }
.ccol table { width: 100%; border-collapse: collapse; }
.ccol th { white-space: nowrap; padding: 11px 14px; text-align: left; font-size: 12px; font-weight: 600; color: #555; background: #fafafa; border-bottom: 1px solid #e5e5e5; position: sticky; top: 0; z-index: 1; }
.ccol td { padding: 10px 14px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
.ccol tbody tr:last-child td { border-bottom: 0; }
.ccol tbody tr:hover td { background: #fcfcfd; }
.ccol tr.dirty td { background: #fffbeb !important; }
.ccol tr.errrow td { background: #fef2f2 !important; }
.ccol .name { font-weight: 600; }
.ccol .muted { color: #777; font-size: 12px; }
.ccol .ref-in { width: 150px; font-variant-numeric: tabular-nums; letter-spacing: .3px; }
.ccol .ref-in.bad { border-color: #ef4444; background: #fef2f2; }
.ccol .rowmsg { display: block; font-size: 11.5px; margin-top: 4px; }
.ccol .rowmsg.err { color: #b91c1c; }
.ccol .rowmsg.ok { color: #15803d; }
.ccol .badge { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 600; white-space: nowrap; }
.ccol .badge.on { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.ccol .badge.miss { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
.ccol .badge.old { background: #f5f5f5; color: #666; border: 1px solid #e5e5e5; }
.ccol .badge.a-added { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
.ccol .badge.a-updated { background: #f5f3ff; color: #5b21b6; border: 1px solid #ddd6fe; }
.ccol .badge.a-cleared { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.ccol .chip { display: inline-block; background: #f3f4f6; border-radius: 6px; padding: 2px 7px; margin: 1px 3px 1px 0; font-size: 11.5px; }
.ccol .actions { display: flex; gap: 6px; justify-content: flex-end; }
.ccol .empty { padding: 36px 16px; text-align: center; color: #666; }
.ccol .note { background: #f5f3ff; border: 1px solid #ddd6fe; color: #4c1d95; padding: 10px 14px; border-radius: 10px; margin-bottom: 14px; }
.ccol .arrow { color: #999; margin: 0 6px; }
.ccol code { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 12.5px; }

.ccol .modal { position: fixed; inset: 0; z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(0,0,0,.5); }
.ccol .modal[hidden] { display: none; }
.ccol .dialog { width: 100%; max-width: 680px; max-height: 85vh; display: flex; flex-direction: column; background: #fff; border-radius: 12px; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
.ccol .dialog h2 { margin: 0; padding: 16px 20px; font-size: 16px; border-bottom: 1px solid #e5e5e5; }
.ccol .dbody { padding: 0; overflow: auto; }
.ccol .dfoot { display: flex; justify-content: flex-end; padding: 12px 20px; border-top: 1px solid #e5e5e5; background: #fafafa; border-radius: 0 0 12px 12px; }

.ccol .toast { position: fixed; right: 20px; bottom: 70px; z-index: 3000; padding: 12px 16px; border-radius: 10px; color: #fff; font-weight: 600; box-shadow: 0 10px 30px rgba(0,0,0,.2); opacity: 0; transform: translateY(10px); transition: .2s; pointer-events: none; }
.ccol .toast.show { opacity: 1; transform: none; }
.ccol .toast.ok { background: #15803d; }
.ccol .toast.err { background: #b91c1c; }
</style>

<div class="ccol">
    <div class="top">
        <div>
            <h1><i class="fa-solid fa-hand-holding-dollar"></i> Cash Collectors</h1>
            <p class="sub">Delivery persons from the latest two field summary dates, with their ref code (mobile number).</p>
        </div>
    </div>

    <div class="stats">
        <div class="stat">
            <div class="lbl">Recent field summary dates</div>
            <div class="val small">
                <?php if ($recent_dates): foreach ($recent_dates as $d): ?>
                    <span class="chip"><?php echo ccol_h(ccol_fmt_date($d)); ?></span>
                <?php endforeach; else: ?>—<?php endif; ?>
            </div>
        </div>
        <div class="stat"><div class="lbl">Delivery persons shown</div><div class="val"><?php echo $cnt_total; ?></div></div>
        <div class="stat ok"><div class="lbl">With ref code</div><div class="val"><?php echo $cnt_with; ?></div></div>
        <div class="stat warn"><div class="lbl">Missing ref code</div><div class="val"><?php echo $cnt_missing; ?></div></div>
    </div>

    <div class="tabs" role="tablist">
        <a href="?tab=list&view=<?php echo $view; ?>" class="<?php echo $tab === 'list' ? 'on' : ''; ?>"><i class="fa-solid fa-users"></i> Collectors</a>
        <a href="?tab=history" class="<?php echo $tab === 'history' ? 'on' : ''; ?>"><i class="fa-solid fa-clock-rotate-left"></i> Update history</a>
    </div>

<?php if ($tab === 'list'): ?>

    <div class="toolbar">
        <div class="seg">
            <a href="?view=recent" class="<?php echo $view === 'recent' ? 'on' : ''; ?>">Recent 2 dates</a>
            <a href="?view=all" class="<?php echo $view === 'all' ? 'on' : ''; ?>">All saved</a>
        </div>
        <div class="grow"><input type="search" class="search" id="ccolSearch" placeholder="Search name, mobile, route or summary code…"></div>
        <label class="muted" style="display:flex;align-items:center;gap:6px;cursor:pointer;">
            <input type="checkbox" id="ccolMissingOnly"> Missing ref code only
        </label>
        <button type="button" class="btn btn-dark" id="ccolSaveAll" disabled>
            <i class="fa-solid fa-floppy-disk"></i> Save all changes <span id="ccolDirtyCount"></span>
        </button>
    </div>

    <div class="card">
        <?php if ($people): ?>
        <table id="ccolTable">
            <thead>
                <tr>
                    <th style="width:48px;">#</th>
                    <th>Delivery person</th>
                    <th>Ref code (mobile no)</th>
                    <th>Status</th>
                    <th>Last delivery date</th>
                    <th>Routes / Field summaries</th>
                    <th>Last updated</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php $i = 0; foreach ($people as $k => $p): $i++;
                $routes    = array_keys($p['routes']);
                $summaries = array_keys($p['summaries']);
                $search    = strtolower($p['name'] . ' ' . $p['ref_code'] . ' ' . implode(' ', $routes) . ' ' . implode(' ', $summaries));
            ?>
                <tr data-key="<?php echo ccol_h($k); ?>"
                    data-name="<?php echo ccol_h($p['name']); ?>"
                    data-orig="<?php echo ccol_h($p['ref_code']); ?>"
                    data-last="<?php echo ccol_h($p['last_date']); ?>"
                    data-search="<?php echo ccol_h($search); ?>">
                    <td class="muted"><?php echo $i; ?></td>
                    <td>
                        <div class="name"><?php echo ccol_h($p['name']); ?></div>
                        <?php if (!$p['recent']): ?><span class="muted">Not on the latest two dates</span><?php endif; ?>
                    </td>
                    <td>
                        <input type="tel" class="ref-in" inputmode="tel" maxlength="16"
                               value="<?php echo ccol_h($p['ref_code']); ?>" placeholder="07XXXXXXXX"
                               aria-label="Ref code (mobile) for <?php echo ccol_h($p['name']); ?>">
                        <span class="rowmsg" aria-live="polite"></span>
                    </td>
                    <td class="st">
                        <?php if ($p['ref_code'] !== ''): ?><span class="badge on">Ref code set</span>
                        <?php else: ?><span class="badge miss">Missing</span><?php endif; ?>
                    </td>
                    <td><?php echo ccol_h(ccol_fmt_date($p['last_date'])); ?></td>
                    <td style="max-width:320px;">
                        <?php foreach ($routes as $rt): ?><span class="chip"><?php echo ccol_h($rt); ?></span><?php endforeach; ?>
                        <?php if ($summaries): ?><div class="muted" style="margin-top:3px;"><?php echo ccol_h(implode(', ', $summaries)); ?></div><?php endif; ?>
                        <?php if (!$routes && !$summaries): ?><span class="muted">—</span><?php endif; ?>
                    </td>
                    <td class="upd">
                        <?php if ($p['updated_at']): ?>
                            <?php echo ccol_h(ccol_fmt_dt($p['updated_at'])); ?>
                            <div class="muted">by <?php echo ccol_h($p['updated_by'] ?: '—'); ?></div>
                        <?php else: ?><span class="muted">Never</span><?php endif; ?>
                    </td>
                    <td>
                        <div class="actions">
                            <button type="button" class="btn btn-dark btn-sm js-save" disabled><i class="fa-solid fa-check"></i> Save</button>
                            <button type="button" class="btn btn-light btn-sm js-hist" title="Update history">
                                <i class="fa-solid fa-clock-rotate-left"></i> <span class="hc"><?php echo (int)($p['changes'] ?? 0); ?></span>
                            </button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="empty" id="ccolNoMatch" hidden>No delivery persons match your search.</div>
        <?php else: ?>
            <div class="empty">
                <?php if (!$recent_dates): ?>
                    No field summaries with a delivery person were found yet.
                <?php else: ?>
                    No delivery persons found for the latest two dates.
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

<?php else: /* ───────── HISTORY TAB ───────── */ ?>

    <form class="toolbar" method="get">
        <input type="hidden" name="tab" value="history">
        <label class="muted">From <input type="date" name="h_from" value="<?php echo ccol_h($h_from); ?>"></label>
        <label class="muted">To <input type="date" name="h_to" value="<?php echo ccol_h($h_to); ?>"></label>
        <div class="grow"><input type="search" class="search" name="h_q" value="<?php echo ccol_h($h_q); ?>" placeholder="Search name, mobile or user…"></div>
        <button class="btn btn-dark" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
        <a class="btn btn-light" href="?tab=history">Reset</a>
    </form>

    <div class="card">
        <?php if ($history): ?>
        <table>
            <thead>
                <tr>
                    <th>Date &amp; time</th>
                    <th>Delivery person</th>
                    <th>Action</th>
                    <th>Ref code change</th>
                    <th>Updated by</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($history as $h): ?>
                <tr>
                    <td style="white-space:nowrap;"><?php echo ccol_h(ccol_fmt_dt($h['changed_at'])); ?></td>
                    <td class="name"><?php echo ccol_h($h['delivery_person']); ?></td>
                    <td><span class="badge a-<?php echo ccol_h($h['action']); ?>"><?php echo ccol_h(ucfirst($h['action'])); ?></span></td>
                    <td style="white-space:nowrap;">
                        <code><?php echo ccol_h($h['old_ref_code'] ?: '—'); ?></code><span class="arrow">→</span><code><?php echo ccol_h($h['new_ref_code'] ?: '—'); ?></code>
                    </td>
                    <td><?php echo ccol_h($h['changed_by'] ?: '—'); ?></td>
                    <td class="muted"><?php echo ccol_h($h['ip_address'] ?: '—'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <div class="empty">No ref code updates in this period.</div>
        <?php endif; ?>
    </div>
    <?php if (count($history) >= 1000): ?><p class="muted" style="margin-top:8px;">Showing the latest 1,000 updates. Narrow the date range to see older ones.</p><?php endif; ?>

<?php endif; ?>

    <!-- per-person history dialog -->
    <div class="modal" id="ccolModal" hidden>
        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="ccolModalTitle">
            <h2 id="ccolModalTitle">Update history</h2>
            <div class="dbody" id="ccolModalBody"></div>
            <div class="dfoot"><button type="button" class="btn btn-light" id="ccolModalClose">Close</button></div>
        </div>
    </div>

    <div class="toast" id="ccolToast" role="status" aria-live="polite"></div>
</div>

<script>
(function () {
    var CSRF = <?php echo json_encode($_SESSION['ccol_csrf']); ?>;
    var table = document.getElementById('ccolTable');
    var toastEl = document.getElementById('ccolToast');
    var toastT;

    function toast(msg, type) {
        toastEl.textContent = msg;
        toastEl.className = 'toast show ' + (type || 'ok');
        clearTimeout(toastT);
        toastT = setTimeout(function () { toastEl.className = 'toast'; }, 3200);
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }
    /* same rule as the server: 07XXXXXXXX / 7XXXXXXXX / 947XXXXXXXX / +947XXXXXXXX */
    function normMobile(v) {
        v = String(v || '').replace(/[\s\-().]/g, '');
        if (v === '') return '';
        var m = v.match(/^(?:\+?94|0)?(7\d{8})$/);
        return m ? '0' + m[1] : null;
    }
    function fmtNow(s) {
        var d = s ? new Date(s.replace(' ', 'T')) : new Date();
        return d.toLocaleString('en-GB', {day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit', hour12:true});
    }
    function post(data) {
        var fd = new FormData();
        fd.append('csrf', CSRF);
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        return fetch(location.pathname, {method: 'POST', body: fd, credentials: 'same-origin'})
            .then(function (r) { return r.json(); });
    }

    /* ── per-person history dialog (works on both tabs) ── */
    var modal = document.getElementById('ccolModal');
    var mBody = document.getElementById('ccolModalBody');
    var mTitle = document.getElementById('ccolModalTitle');
    var lastFocus = null;
    function openHistory(name) {
        lastFocus = document.activeElement;
        mTitle.textContent = 'Update history — ' + name;
        mBody.innerHTML = '<div class="empty">Loading…</div>';
        modal.hidden = false;
        document.getElementById('ccolModalClose').focus();
        post({ajax: 'history', name: name}).then(function (res) {
            if (!res.ok || !res.rows.length) { mBody.innerHTML = '<div class="empty">No updates saved yet for this delivery person.</div>'; return; }
            var h = '<table><thead><tr><th>Date &amp; time</th><th>Action</th><th>Ref code change</th><th>Updated by</th></tr></thead><tbody>';
            res.rows.forEach(function (r) {
                h += '<tr><td style="white-space:nowrap;">' + esc(fmtNow(r.changed_at)) + '</td>' +
                     '<td><span class="badge a-' + esc(r.action) + '">' + esc(r.action.charAt(0).toUpperCase() + r.action.slice(1)) + '</span></td>' +
                     '<td style="white-space:nowrap;"><code>' + esc(r.old_ref_code || '—') + '</code><span class="arrow">→</span><code>' + esc(r.new_ref_code || '—') + '</code></td>' +
                     '<td>' + esc(r.changed_by || '—') + '</td></tr>';
            });
            mBody.innerHTML = h + '</tbody></table>';
        }).catch(function () { mBody.innerHTML = '<div class="empty">Could not load the history. Try again.</div>'; });
    }
    function closeModal() { modal.hidden = true; if (lastFocus && document.contains(lastFocus)) lastFocus.focus(); }
    document.getElementById('ccolModalClose').addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) closeModal(); });

    if (!table) return;

    var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
    var saveAllBtn = document.getElementById('ccolSaveAll');
    var dirtyCount = document.getElementById('ccolDirtyCount');

    function rowState(tr) {
        var inp = tr.querySelector('.ref-in');
        var val = inp.value.trim();
        var norm = normMobile(val);
        var orig = tr.dataset.orig || '';
        var dirty = (norm === null) ? val !== orig : norm !== orig;
        return {inp: inp, val: val, norm: norm, dirty: dirty};
    }
    function refreshRow(tr) {
        var s = rowState(tr);
        var msg = tr.querySelector('.rowmsg');
        tr.classList.toggle('dirty', s.dirty);
        tr.classList.remove('errrow');
        s.inp.classList.toggle('bad', s.norm === null);
        tr.querySelector('.js-save').disabled = !s.dirty || s.norm === null;
        if (s.norm === null) { msg.className = 'rowmsg err'; msg.textContent = 'Enter a valid mobile, e.g. 0771234567'; }
        else if (s.dirty && s.norm === '' ) { msg.className = 'rowmsg'; msg.textContent = 'Ref code will be cleared'; }
        else { msg.className = 'rowmsg'; msg.textContent = ''; }
        refreshSaveAll();
    }
    function refreshSaveAll() {
        var n = 0, bad = 0;
        rows.forEach(function (tr) { var s = rowState(tr); if (s.dirty) { n++; if (s.norm === null) bad++; } });
        saveAllBtn.disabled = (n === 0 || bad > 0);
        dirtyCount.textContent = n ? '(' + n + ')' : '';
    }
    function applyResult(tr, r) {
        var msg = tr.querySelector('.rowmsg');
        if (!r.ok) {
            tr.classList.add('errrow');
            msg.className = 'rowmsg err'; msg.textContent = r.msg;
            return;
        }
        tr.dataset.orig = r.ref_code || '';
        tr.querySelector('.ref-in').value = r.ref_code || '';
        tr.querySelector('.st').innerHTML = r.ref_code ? '<span class="badge on">Ref code set</span>' : '<span class="badge miss">Missing</span>';
        if (r.changed) {
            tr.querySelector('.upd').innerHTML = esc(fmtNow(r.updated_at)) + '<div class="muted">by ' + esc(r.updated_by) + '</div>';
            var hc = tr.querySelector('.hc'); hc.textContent = (parseInt(hc.textContent, 10) || 0) + 1;
            tr.dataset.search = tr.dataset.search.replace(/\s0?7\d{8}\b/, '') + ' ' + (r.ref_code || '');
        }
        refreshRow(tr);
        if (r.changed) { msg.className = 'rowmsg ok'; msg.textContent = 'Saved'; setTimeout(function () { if (msg.textContent === 'Saved') msg.textContent = ''; }, 2500); }
    }
    function save(trs, btn) {
        var items = trs.map(function (tr) {
            var s = rowState(tr);
            return {key: tr.dataset.key, name: tr.dataset.name, ref: s.norm === null ? s.val : s.norm, last_seen: tr.dataset.last || ''};
        });
        if (!items.length) return;
        var old = btn.innerHTML;
        btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
        post({ajax: 'save', items: JSON.stringify(items)}).then(function (res) {
            btn.innerHTML = old;
            if (!res.results) { toast(res.msg || 'Could not save.', 'err'); refreshSaveAll(); return; }
            res.results.forEach(function (r) {
                var tr = rows.filter(function (x) { return x.dataset.key === r.key; })[0];
                if (tr) applyResult(tr, r);
            });
            if (res.failed) toast(res.failed + ' row(s) not saved — see the red rows.', 'err');
            else toast(res.saved ? res.saved + ' ref code(s) saved.' : 'Nothing changed.', 'ok');
            refreshSaveAll();
        }).catch(function () {
            btn.innerHTML = old; refreshSaveAll();
            toast('Could not reach the server. Try again.', 'err');
        });
    }

    rows.forEach(function (tr) {
        var inp = tr.querySelector('.ref-in');
        inp.addEventListener('input', function () { refreshRow(tr); });
        inp.addEventListener('blur', function () { var n = normMobile(inp.value); if (n) { inp.value = n; refreshRow(tr); } });
        inp.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); var b = tr.querySelector('.js-save'); if (!b.disabled) save([tr], b); }
        });
        tr.querySelector('.js-save').addEventListener('click', function () { save([tr], this); });
        tr.querySelector('.js-hist').addEventListener('click', function () { openHistory(tr.dataset.name); });
    });

    saveAllBtn.addEventListener('click', function () {
        var dirty = rows.filter(function (tr) { return rowState(tr).dirty; });
        save(dirty, saveAllBtn);
    });

    /* search + missing-only filter */
    var searchEl = document.getElementById('ccolSearch');
    var missEl = document.getElementById('ccolMissingOnly');
    var noMatch = document.getElementById('ccolNoMatch');
    function filter() {
        var q = searchEl.value.trim().toLowerCase(), shown = 0;
        rows.forEach(function (tr) {
            var ok = (!q || tr.dataset.search.indexOf(q) !== -1) && (!missEl.checked || !tr.dataset.orig);
            tr.hidden = !ok; if (ok) shown++;
        });
        noMatch.hidden = shown !== 0;
    }
    searchEl.addEventListener('input', filter);
    missEl.addEventListener('change', filter);

    window.addEventListener('beforeunload', function (e) {
        if (rows.some(function (tr) { return rowState(tr).dirty; })) { e.preventDefault(); e.returnValue = ''; }
    });
})();
</script>

<?php include 'footer.php'; ?>
