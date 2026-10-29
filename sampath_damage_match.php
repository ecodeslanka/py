<?php
// ============================================================
//  sampath_damage_match.php
//  SAMPATH DAMAGE ENTRY — AI SCAN, TALLY & CONFIRM
//
//  Shows every line of the Sampath Damage Entry imports (sampath_damage_grn_items, grouped by batch / GRN) and tallies each
//  line with a system item (the Sampath Item Stock Report items) by name and price (TUR), with a Gemini AI scan for weak
//  rows. Lines can be confirmed one by one, as a selection, or in bulk (all lines at or above a tally %). Lines that do not
//  tally can be pointed at any system item by hand before confirming.
//
//  CONFIRMED lines are stored in sampath_damage_item_map and become DAMAGED STOCK ("<code>-D" items) in the Sampath Item Stock
//  Report: the qty is added to the system item's Damaged Stock and appears in its ledger (inventory log) as
//  "Sampath Damage Entry" with the batch / GRN reference, dated by the GRN date.
//  A confirmed Sampath code -> system item pair is remembered (sampath_item_code_map) so the same code tallies 100% next time.
// ============================================================
include 'config.php';
include_once 'auth.php';
requireLogin();
require_once 'item_match_lib.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$who = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'System';

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sampath_damage_item_map (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_id INT NOT NULL,
    item_code VARCHAR(100) NOT NULL,
    item_name VARCHAR(500) NULL,
    name_pct DECIMAL(5,1) NULL,
    price_pct DECIMAL(5,1) NULL,
    method VARCHAR(30) NULL,
    confirmed_by VARCHAR(100) NULL,
    confirmed_at DATETIME NULL,
    UNIQUE KEY uq_line (line_id),
    INDEX idx_item (item_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sampath_item_code_map (
    sampath_code VARCHAR(100) PRIMARY KEY,
    item_code VARCHAR(100) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$have_dmg = im_table($conn, 'sampath_damage_grn_items') && im_table($conn, 'sampath_damage_grn_headers') && im_table($conn, 'sampath_damage_batches');
$e = function ($v) use ($conn) { return mysqli_real_escape_string($conn, (string)$v); };

function dm_learned($conn) {
    $m = [];
    $q = mysqli_query($conn, "SELECT sampath_code, item_code FROM sampath_item_code_map");
    while ($q && $r = mysqli_fetch_assoc($q)) $m[mb_strtoupper(trim($r['sampath_code']))] = $r['item_code'];
    return $m;
}

/* ── AJAX ── */
$ajax = $_GET['ajax'] ?? '';
if ($ajax === 'ai' && $_SERVER['REQUEST_METHOD'] === 'POST') im_handle_ai();

if ($ajax === 'sysitems') {                       // every system item, for the manual "change item" dropdown
    header('Content-Type: application/json; charset=utf-8');
    $out = [];
    foreach (im_load_masters($conn) as $m) $out[] = ['id' => $m['code'], 'text' => $m['code'] . ' - ' . $m['name'], 'name' => $m['name'], 'prices' => array_values(array_filter($m['prices'])), 'price' => $m['price'], 'psrc' => $m['psrc'] ?? '', 'src' => $m['src']];
    usort($out, function ($a, $b) { return strcmp($a['id'], $b['id']); });
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($ajax === 'match' && $_SERVER['REQUEST_METHOD'] === 'POST') {     // local tally of the given lines (+ weak rows for the AI scan)
    header('Content-Type: application/json; charset=utf-8');
    @set_time_limit(120);
    $in  = json_decode(file_get_contents('php://input'), true) ?: [];
    $ids = array_values(array_filter(array_map('intval', (array)($in['line_ids'] ?? []))));
    $ids = array_slice($ids, 0, 500);
    $want_ai = !empty($in['use_ai']) && im_gemini_key() !== '';
    $masters = im_load_masters($conn); $by = im_code_index($masters); $learned = dm_learned($conn);
    $rows = []; $weak = [];
    if ($ids && $have_dmg) {
        $q = mysqli_query($conn, "SELECT id, product_code, product_name, unit_price FROM sampath_damage_grn_items WHERE id IN (" . implode(',', $ids) . ")");
        while ($q && $r = mysqli_fetch_assoc($q)) {
            list($row, $cands) = im_match_one($masters, $by, $r['product_code'], $r['product_name'], (float)$r['unit_price'], $want_ai, $learned);
            $rows[(string)$r['id']] = $row;
            if ($cands) $weak[] = ['id' => (string)$r['id'], 'name' => $r['product_name'], 'price' => (float)$r['unit_price'], 'cands' => $cands];
        }
    }
    echo json_encode(['ok' => true, 'rows' => $rows, 'weak' => $weak, 'masters' => count($masters), 'ai_key' => im_gemini_key() !== ''], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($ajax === 'confirm' && $_SERVER['REQUEST_METHOD'] === 'POST') {   // confirm lines -> Damaged Stock
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $items = array_slice((array)($in['items'] ?? []), 0, 1000);
    $masters = im_load_masters($conn); $by = im_code_index($masters);
    $ok = 0; $skipped = 0;
    foreach ($items as $it) {
        $lid = (int)($it['line_id'] ?? 0); $code = trim((string)($it['item_code'] ?? ''));
        if (!$lid || $code === '' || !isset($by[mb_strtoupper($code)])) { $skipped++; continue; }
        $m = $masters[$by[mb_strtoupper($code)][0]];
        $lq = mysqli_query($conn, "SELECT product_code FROM sampath_damage_grn_items WHERE id = $lid LIMIT 1");
        $line = $lq ? mysqli_fetch_assoc($lq) : null;
        if (!$line) { $skipped++; continue; }
        $np = isset($it['name_pct']) ? round((float)$it['name_pct'], 1) : 'NULL';
        $pp = isset($it['price_pct']) && $it['price_pct'] !== null ? round((float)$it['price_pct'], 1) : 'NULL';
        $meth = $e(substr((string)($it['method'] ?? ''), 0, 30));
        $res = mysqli_query($conn, "INSERT INTO sampath_damage_item_map (line_id, item_code, item_name, name_pct, price_pct, method, confirmed_by, confirmed_at)
            VALUES ($lid, '" . $e($m['code']) . "', '" . $e($m['name']) . "', $np, $pp, '$meth', '" . $e($who) . "', NOW())
            ON DUPLICATE KEY UPDATE item_code = VALUES(item_code), item_name = VALUES(item_name), name_pct = VALUES(name_pct), price_pct = VALUES(price_pct),
                                    method = VALUES(method), confirmed_by = VALUES(confirmed_by), confirmed_at = NOW()");
        if (!$res) { $skipped++; continue; }
        $ok++;
        if (trim((string)$line['product_code']) !== '')   // remember this Sampath code -> system item pairing
            mysqli_query($conn, "INSERT INTO sampath_item_code_map (sampath_code, item_code) VALUES ('" . $e(trim($line['product_code'])) . "', '" . $e($m['code']) . "')
                                 ON DUPLICATE KEY UPDATE item_code = VALUES(item_code)");
    }
    echo json_encode(['ok' => true, 'confirmed' => $ok, 'skipped' => $skipped]);
    exit;
}

if ($ajax === 'unconfirm' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $ids = array_values(array_filter(array_map('intval', (array)($in['line_ids'] ?? []))));
    if ($ids) mysqli_query($conn, "DELETE FROM sampath_damage_item_map WHERE line_id IN (" . implode(',', $ids) . ")");
    echo json_encode(['ok' => true, 'removed' => count($ids)]);
    exit;
}

/* ── filters + data ── */
$f_batch  = (int)($_GET['batch'] ?? 0);
$f_from   = trim($_GET['date_from'] ?? '');
$f_to     = trim($_GET['date_to'] ?? '');
$f_status = in_array($_GET['status'] ?? '', ['pending', 'confirmed']) ? $_GET['status'] : '';
$f_search = trim($_GET['search'] ?? '');
$per_in   = (int)($_GET['per'] ?? 100);
$per      = in_array($per_in, [50, 100, 200, 500], true) ? $per_in : 100;
$page     = max(1, (int)($_GET['page'] ?? 1));

$batches = []; $lines = []; $total = 0; $pages = 1;
$kpi = ['lines' => 0, 'confirmed' => 0, 'qty' => 0, 'qty_conf' => 0];
if ($have_dmg) {
    $q = mysqli_query($conn, "SELECT id, batch_ref, import_date FROM sampath_damage_batches ORDER BY created_at DESC");
    while ($q && $r = mysqli_fetch_assoc($q)) $batches[] = $r;

    $w = ['1=1'];
    if ($f_batch)  $w[] = "b.id = $f_batch";
    if ($f_from)   $w[] = "h.grn_date >= '" . $e($f_from) . "'";
    if ($f_to)     $w[] = "h.grn_date <= '" . $e($f_to) . "'";
    if ($f_status === 'pending')   $w[] = "m.id IS NULL";
    if ($f_status === 'confirmed') $w[] = "m.id IS NOT NULL";
    if ($f_search) { $s = $e($f_search); $w[] = "(h.grn_no LIKE '%$s%' OR i.product_code LIKE '%$s%' OR i.product_name LIKE '%$s%' OR h.grn_branch_code LIKE '%$s%' OR m.item_code LIKE '%$s%')"; }
    $where = implode(' AND ', $w);
    $from_sql = "FROM sampath_damage_grn_items i
        JOIN sampath_damage_grn_headers h ON h.id = i.grn_header_id
        JOIN sampath_damage_batches b ON b.id = h.batch_id
        LEFT JOIN sampath_damage_item_map m ON m.line_id = i.id
        WHERE $where";
    $q = mysqli_query($conn, "SELECT COUNT(*) AS n, SUM(m.id IS NOT NULL) AS c, COALESCE(SUM(i.qty),0) AS qty, COALESCE(SUM(CASE WHEN m.id IS NOT NULL THEN i.qty END),0) AS qc $from_sql");
    if ($q && $r = mysqli_fetch_assoc($q)) $kpi = ['lines' => (int)$r['n'], 'confirmed' => (int)$r['c'], 'qty' => (float)$r['qty'], 'qty_conf' => (float)$r['qc']];
    $total = $kpi['lines']; $pages = max(1, (int)ceil($total / $per)); $page = min($page, $pages);
    $q = mysqli_query($conn, "SELECT i.id, i.line_no, i.product_code, i.product_name, i.location, i.unit_price, i.qty, i.line_total,
            h.grn_no, h.grn_branch_code, h.supplier, h.grn_date, b.id AS bid, b.batch_ref,
            m.item_code AS c_code, m.item_name AS c_name, m.name_pct AS c_name_pct, m.price_pct AS c_price_pct, m.method AS c_method, m.confirmed_by, m.confirmed_at
        $from_sql ORDER BY h.grn_date DESC, h.grn_no, i.line_no LIMIT $per OFFSET " . (($page - 1) * $per));
    while ($q && $r = mysqli_fetch_assoc($q)) $lines[] = $r;
}
$has_key = im_gemini_key() !== '';
$qs = ['batch' => $f_batch ?: '', 'date_from' => $f_from, 'date_to' => $f_to, 'status' => $f_status, 'search' => $f_search, 'per' => $per];
include 'header.php';
function dm_n($v) { return number_format(round($v), 0); }
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<style>
.dm{padding:0 4px;}
.dm h2{font-size:22px;font-weight:800;margin:0 0 4px;}
.dm .sub{font-size:12px;color:#8892a4;margin-bottom:16px;}
.dm-card{background:#fff;border:1.5px solid #e2e6f0;border-radius:14px;padding:16px 20px;margin-bottom:18px;}
.dm-f{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;}
.dm-f label{display:block;font-size:10px;font-weight:700;color:#475569;text-transform:uppercase;margin-bottom:4px;}
.dm-f input,.dm-f select{border:1.5px solid #e2e6f0;border-radius:8px;padding:8px 12px;font-size:13px;background:#fff;}
.dm-b{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;background:#0a0c14;color:#fff;text-decoration:none;}
.dm-b.l{background:#f7f8fc;color:#475569;border:1.5px solid #e2e6f0;} .dm-b.g{background:#14532d;} .dm-b.bl{background:#1e40af;} .dm-b.p{background:#6d28d9;} .dm-b.r{background:#dc2626;}
.dm-b.sm{padding:4px 9px;font-size:11px;} .dm-b[disabled]{opacity:.5;cursor:not-allowed;}
.dm-kpis{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;}
.dm-kpi{background:#fff;border:1.5px solid #e2e6f0;border-radius:12px;padding:10px 16px;min-width:140px;}
.dm-kpi .l{font-size:10px;font-weight:700;color:#8892a4;text-transform:uppercase;} .dm-kpi .v{font-size:20px;font-weight:800;margin-top:2px;}
.dm-wrap{overflow:auto;border:1.5px solid #e2e6f0;border-radius:12px;background:#fff;}
table.dm-t{width:100%;border-collapse:collapse;font-size:12px;}
table.dm-t th{background:#0a0c14;color:#fff;padding:9px 8px;text-align:left;font-size:10.5px;text-transform:uppercase;white-space:nowrap;position:sticky;top:0;}
table.dm-t td{padding:7px 8px;border-bottom:1px solid #eef0f6;vertical-align:top;}
table.dm-t .n{text-align:right;font-family:monospace;white-space:nowrap;}
table.dm-t tr.conf{background:#f0fdf4;}
.pill{display:inline-block;padding:2px 10px;border-radius:12px;font-size:11px;font-weight:700;}
.pill.Matched{background:#dcfce7;color:#166534;} .pill.Review{background:#fef3c7;color:#92400e;} .pill.NoMatch{background:#fee2e2;color:#991b1b;} .pill.Pending{background:#f3f4f6;color:#4b5563;} .pill.Confirmed{background:#bbf7d0;color:#14532d;}
.pct{font-family:monospace;font-weight:700;}
.dm-msg{border-radius:8px;padding:10px 16px;margin-bottom:12px;font-size:13px;}
.dm-msg.warn{background:#fffbeb;border:1px solid #fcd34d;color:#92400e;} .dm-msg.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;} .dm-msg.ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.dm-pag{display:flex;gap:6px;align-items:center;justify-content:center;margin:14px 0;flex-wrap:wrap;}
.dm-pag a,.dm-pag span{padding:6px 12px;border:1.5px solid #e2e6f0;border-radius:8px;font-size:12px;text-decoration:none;color:#475569;background:#fff;}
.dm-pag span.on{background:#0a0c14;color:#fff;border-color:#0a0c14;}
.select2-container .select2-selection--single{height:34px;border:1.5px solid #e2e6f0;border-radius:8px;} .select2-container{min-width:280px;}
</style>

<div class="dm">
    <h2><i class="fa-solid fa-triangle-exclamation"></i> Sampath Damage Entry — AI Scan, Tally &amp; Confirm</h2>
    <div class="sub">Every damage entry line is tallied with a system item by name and price (TUR) with a Gemini AI scan for weak matches. <strong>Confirmed</strong> lines are added as <strong>Damaged Stock</strong> (<em>&lt;code&gt;-D</em>) in the Sampath Item Stock Report and its item ledger (inventory log). If a line does not tally, choose the right system item with <em>Change</em> before confirming.</div>

    <?php if (!$have_dmg): ?><div class="dm-msg err">No Sampath damage entries found yet (sampath_damage_* tables are empty / missing). Import damage entries first.</div><?php endif; ?>
    <?php if (!$has_key): ?><div class="dm-msg warn">No Gemini API key was found, so only the local name/price tally will run. Save the key on the <strong>AI Settings</strong> page to enable the AI scan.</div><?php endif; ?>
    <div id="dmMsgs"></div>

    <div class="dm-kpis">
        <div class="dm-kpi"><div class="l">Entry lines</div><div class="v"><?php echo dm_n($kpi['lines']); ?></div></div>
        <div class="dm-kpi"><div class="l">Confirmed</div><div class="v" id="kpiConf"><?php echo dm_n($kpi['confirmed']); ?></div></div>
        <div class="dm-kpi"><div class="l">Pending</div><div class="v" id="kpiPend"><?php echo dm_n($kpi['lines'] - $kpi['confirmed']); ?></div></div>
        <div class="dm-kpi"><div class="l">Damage qty (all)</div><div class="v"><?php echo dm_n($kpi['qty']); ?></div></div>
        <div class="dm-kpi"><div class="l">Qty in Damaged Stock</div><div class="v"><?php echo dm_n($kpi['qty_conf']); ?></div></div>
    </div>

    <form method="GET" class="dm-card">
        <div class="dm-f">
            <div><label>Batch</label><select name="batch"><option value="">All batches</option>
                <?php foreach ($batches as $b): ?><option value="<?php echo $b['id']; ?>" <?php echo $f_batch == $b['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['batch_ref'] . ' (' . $b['import_date'] . ')'); ?></option><?php endforeach; ?></select></div>
            <div><label>GRN Date From</label><input type="date" name="date_from" value="<?php echo htmlspecialchars($f_from); ?>"></div>
            <div><label>GRN Date To</label><input type="date" name="date_to" value="<?php echo htmlspecialchars($f_to); ?>"></div>
            <div><label>Status</label><select name="status"><option value="">All</option>
                <option value="pending" <?php echo $f_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="confirmed" <?php echo $f_status === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option></select></div>
            <div><label>Search</label><input type="text" name="search" value="<?php echo htmlspecialchars($f_search); ?>" placeholder="GRN / code / product / branch" style="min-width:210px"></div>
            <div><label>Lines per page</label><select name="per"><?php foreach ([50, 100, 200, 500] as $p): ?><option value="<?php echo $p; ?>" <?php echo $per == $p ? 'selected' : ''; ?>><?php echo $p; ?></option><?php endforeach; ?></select></div>
            <button class="dm-b" type="submit"><i class="fa-solid fa-filter"></i> Apply</button>
            <a class="dm-b l" href="sampath_damage_match.php">Reset</a>
        </div>
    </form>

    <div class="dm-card" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <button class="dm-b p" id="btnScan" type="button" onclick="dmScan()"><i class="fa-solid fa-wand-magic-sparkles"></i> AI Scan &amp; Tally (pending lines on this page)</button>
        <label style="font-size:12px;font-weight:600;display:flex;align-items:center;gap:6px;"><input type="checkbox" id="useAi" <?php echo $has_key ? 'checked' : 'disabled'; ?>> Use Gemini AI scan</label>
        <span style="border-left:1.5px solid #e2e6f0;height:28px"></span>
        <label style="font-size:12px;font-weight:600;">Tally ≥ <input type="number" id="thr" value="85" min="1" max="100" style="width:64px;border:1.5px solid #e2e6f0;border-radius:6px;padding:5px;"> %</label>
        <button class="dm-b l" type="button" onclick="dmSelectTallied()">Select tallied</button>
        <button class="dm-b g" id="btnSel" type="button" onclick="dmConfirm('selected')"><i class="fa-solid fa-check"></i> Confirm Selected</button>
        <button class="dm-b bl" id="btnBulk" type="button" onclick="dmConfirm('bulk')"><i class="fa-solid fa-check-double"></i> Bulk Confirm (all tallied)</button>
        <a class="dm-b l" href="sampath_item_stock_report.php?loc=damaged" style="margin-left:auto"><i class="fa-solid fa-arrow-up-right-from-square"></i> Damaged Stock report</a>
    </div>

    <div class="dm-wrap"><table class="dm-t" id="dmTable">
        <thead><tr>
            <th><input type="checkbox" id="chkAll" onclick="dmToggleAll(this.checked)"></th>
            <th>Batch / GRN</th><th>GRN Date</th><th>Sampath Code</th><th>Damage Item (Sampath)</th><th class="n">Price</th><th class="n">Qty</th>
            <th>System Item (tallied)</th><th class="n">Name %</th><th class="n">Price %</th><th class="n">Tally %</th><th>Status</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($lines as $r): $conf = $r['c_code'] !== null; ?>
            <tr id="r<?php echo $r['id']; ?>" class="<?php echo $conf ? 'conf' : ''; ?>" data-id="<?php echo $r['id']; ?>" data-conf="<?php echo $conf ? 1 : 0; ?>"
                data-price="<?php echo (float)$r['unit_price']; ?>">
                <td><?php if (!$conf): ?><input type="checkbox" class="chk" value="<?php echo $r['id']; ?>"><?php endif; ?></td>
                <td><strong><?php echo htmlspecialchars($r['grn_no']); ?></strong><div style="color:#8892a4;font-size:10.5px"><?php echo htmlspecialchars($r['batch_ref'] . ' · ' . $r['grn_branch_code']); ?></div></td>
                <td style="white-space:nowrap"><?php echo htmlspecialchars($r['grn_date']); ?></td>
                <td><?php echo htmlspecialchars($r['product_code']); ?></td>
                <td style="max-width:260px"><?php echo htmlspecialchars($r['product_name']); ?><?php if ($r['location']): ?><div style="color:#8892a4;font-size:10.5px"><?php echo htmlspecialchars($r['location']); ?></div><?php endif; ?></td>
                <td class="n"><?php echo number_format((float)$r['unit_price'], 2); ?></td>
                <td class="n"><strong><?php echo dm_n($r['qty']); ?></strong></td>
                <td class="sys" style="min-width:290px">
                    <?php if ($conf): ?><strong><?php echo htmlspecialchars($r['c_code']); ?></strong> - <?php echo htmlspecialchars($r['c_name']); ?>
                        <div style="color:#8892a4;font-size:10.5px">confirmed <?php echo htmlspecialchars(substr((string)$r['confirmed_at'], 0, 16) . ' · ' . $r['confirmed_by']); ?> · <?php echo htmlspecialchars($r['c_method']); ?></div>
                    <?php else: ?><span style="color:#9ca3af">not scanned</span><?php endif; ?></td>
                <td class="n pct nm"><?php echo $conf && $r['c_name_pct'] !== null ? $r['c_name_pct'] . '%' : '—'; ?></td>
                <td class="n pct pr"><?php echo $conf && $r['c_price_pct'] !== null ? $r['c_price_pct'] . '%' : '—'; ?></td>
                <td class="n pct ov">—</td>
                <td class="st"><span class="pill <?php echo $conf ? 'Confirmed' : 'Pending'; ?>"><?php echo $conf ? 'Confirmed' : 'Pending'; ?></span></td>
                <td style="white-space:nowrap" class="act">
                    <?php if ($conf): ?><button class="dm-b l sm" type="button" onclick="dmUnconfirm(<?php echo $r['id']; ?>)">Undo</button>
                    <?php else: ?><button class="dm-b l sm" type="button" onclick="dmChange(<?php echo $r['id']; ?>)">Change</button>
                        <button class="dm-b g sm" type="button" onclick="dmConfirmRows([<?php echo $r['id']; ?>])">Confirm</button><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$lines): ?><tr><td colspan="13" style="text-align:center;padding:40px;color:#9ca3af;">No damage entry lines found.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?php if ($pages > 1): $base = '?' . http_build_query($qs) . '&page='; ?>
    <div class="dm-pag">
        <?php if ($page > 1): ?><a href="<?php echo $base . ($page - 1); ?>">‹ Prev</a><?php endif; ?>
        <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): echo $p == $page ? "<span class='on'>$p</span>" : "<a href='{$base}{$p}'>$p</a>"; endfor; ?>
        <?php if ($page < $pages): ?><a href="<?php echo $base . ($page + 1); ?>">Next ›</a><?php endif; ?>
        <span><?php echo number_format($total); ?> lines</span>
    </div>
    <?php endif; ?>
</div>

<script>
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const fm1 = v => v === null || v === undefined ? '—' : Number(v).toFixed(1) + '%';
const state = {};            // line id -> {code,name,price,psrc,src,name_pct,price_pct,overall,method,status}
let SYS = null;              // all system items (loaded on first "Change")

const imStatus = o => o >= 85 ? 'Matched' : (o >= 60 ? 'Review' : 'No Match');
const imOverall = (n, p) => p === null || p === undefined ? n : (0.6 * n + 0.4 * p);
function msg(html, cls){ document.getElementById('dmMsgs').innerHTML = html ? `<div class="dm-msg ${cls || 'ok'}">${html}</div>` : ''; }
function priceScore(excel, prices){
    let best = null;
    (prices || []).forEach(p => { if (!p || p <= 0 || excel <= 0) return; const r = Math.min(excel, p) / Math.max(excel, p);
        const sc = r >= 0.99 ? 100 : Math.max(0, 100 - (1 - r) * 200); if (best === null || sc > best) best = sc; });
    return best;
}

function renderRow(id){
    const tr = document.getElementById('r' + id), s = state[id]; if (!tr || !s || tr.dataset.conf === '1') return;
    tr.querySelector('.sys').innerHTML = s.code ? `<strong>${esc(s.code)}</strong> - ${esc(s.name)} <span style="color:#8892a4">(${esc(s.method)}${s.price !== null && s.price !== undefined ? ' · ' + Number(s.price).toFixed(2) + (s.psrc && s.psrc !== 'TUR' ? ' ' + esc(s.psrc) : ' TUR') : ''})</span>` : '<span style="color:#991b1b">no match found</span>';
    tr.querySelector('.nm').textContent = fm1(s.name_pct);
    tr.querySelector('.pr').textContent = s.price_pct === null ? 'n/a' : fm1(s.price_pct);
    const ov = tr.querySelector('.ov'); ov.textContent = fm1(s.overall); ov.style.color = s.overall >= 85 ? '#166534' : s.overall >= 60 ? '#92400e' : '#991b1b';
    tr.querySelector('.st').innerHTML = `<span class="pill ${s.status.replace(' ', '')}">${s.status}</span>`;
}
function pendingIds(){ return [...document.querySelectorAll('#dmTable tbody tr[data-conf="0"]')].map(t => +t.dataset.id); }

async function dmScan(){
    const ids = pendingIds(); if (!ids.length) { msg('No pending lines on this page.', 'warn'); return; }
    const btn = document.getElementById('btnScan'); btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Tallying…'; msg('');
    try {
        const r = await fetch('sampath_damage_match.php?ajax=match', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({line_ids: ids, use_ai: document.getElementById('useAi').checked})});
        const d = await r.json();
        Object.keys(d.rows).forEach(id => { const m = d.rows[id];
            state[id] = {code: m.m_code, name: m.m_name, price: m.m_price, psrc: m.m_psrc, src: m.m_src, name_pct: m.name_pct, price_pct: m.price_pct, overall: m.overall, method: m.method, status: m.status}; renderRow(id); });
        if (!d.masters) { msg('No system items found (Unilever Purchase Import / secondary sales data are empty).', 'err'); }
        if (d.weak.length) await dmAi(d.weak, btn);
        const vals = Object.values(state); const ok = vals.filter(v => v.overall >= +document.getElementById('thr').value).length;
        if (d.masters) msg(`Tallied ${vals.length} line(s): ${ok} at or above ${document.getElementById('thr').value}%.`, 'ok');
    } catch (e) { msg(esc(e.message), 'err'); }
    btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> AI Scan & Tally (pending lines on this page)';
}

async function dmAi(weak, btn){
    let fails = 0, used = 0, lastErr = '';
    for (let i = 0; i < weak.length; i += 5) {
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Gemini scan ' + Math.min(i + 5, weak.length) + '/' + weak.length + '…';
        const part = weak.slice(i, i + 5); let res;
        try {
            const r = await fetch('sampath_damage_match.php?ajax=ai', {method:'POST', headers:{'Content-Type':'application/json'},
                body: JSON.stringify({batch: part.map(w => ({ref: w.id, name: w.name, price: w.price, cands: w.cands.map(c => ({i: c.i, name: c.name, price: c.price}))}))})});
            res = await r.json();
        } catch (e) { res = {ok: false, error: 'Gemini request failed: ' + e.message}; }
        if (!res.ok) { lastErr = res.error; if (++fails >= 3) break; continue; }
        part.forEach(w => {
            const a = res.answers[w.id]; if (!a) return; used++;
            const s = state[w.id], c = a.cand !== null ? w.cands[a.cand] : null;
            if (c) Object.assign(s, {code: c.code, name: c.name, price: c.price, psrc: c.psrc, src: c.src, name_pct: a.conf, price_pct: c.price_pct, method: 'AI'});
            else { s.name_pct = Math.min(s.name_pct, 40); s.method = 'AI: no match'; }
            s.overall = Math.round(imOverall(s.name_pct, s.price_pct) * 10) / 10; s.status = imStatus(s.overall); renderRow(w.id);
        });
    }
    if (lastErr) msg('Gemini AI scan problem — those rows kept their local match: ' + esc(lastErr), 'err');
}

async function dmChange(id){        // pick any system item by hand
    const tr = document.getElementById('r' + id), cell = tr.querySelector('.sys');
    if (!SYS) { cell.innerHTML = 'Loading items…'; SYS = await (await fetch('sampath_damage_match.php?ajax=sysitems')).json(); }
    cell.innerHTML = '<select style="width:100%"></select>';
    const sel = $(cell).find('select');
    sel.select2({data: SYS, placeholder: 'Search item code or name…', minimumInputLength: 1, width: '100%', dropdownParent: $('body')});
    sel.on('select2:select', e => {
        const it = e.params.data, price = +tr.dataset.price, pp = priceScore(price, it.prices);
        state[id] = {code: it.id, name: it.name, price: it.price, psrc: it.psrc, src: it.src, name_pct: 100, price_pct: pp, overall: Math.round(imOverall(100, pp) * 10) / 10, method: 'Manual', status: ''};
        state[id].status = imStatus(state[id].overall); sel.select2('destroy'); renderRow(id);
    });
    sel.select2('open');
}

function dmToggleAll(on){ document.querySelectorAll('.chk').forEach(c => c.checked = on); }
function dmSelectTallied(){
    const thr = +document.getElementById('thr').value;
    document.querySelectorAll('.chk').forEach(c => { const s = state[c.value]; c.checked = !!(s && s.code && s.overall >= thr); });
}
async function dmConfirmRows(ids){
    const items = [], missing = [];
    ids.forEach(id => { const s = state[id]; if (s && s.code) items.push({line_id: id, item_code: s.code, name_pct: s.name_pct, price_pct: s.price_pct, method: s.method}); else missing.push(id); });
    if (!items.length) { msg('Scan the lines first (or use Change to pick a system item) — nothing to confirm.', 'warn'); return; }
    const r = await fetch('sampath_damage_match.php?ajax=confirm', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({items})});
    const d = await r.json();
    msg(`${d.confirmed} line(s) confirmed and added to Damaged Stock` + (d.skipped ? ` · ${d.skipped} skipped` : '') + (missing.length ? ` · ${missing.length} not scanned` : '') + '. Reloading…', 'ok');
    setTimeout(() => location.reload(), 900);
}
function dmConfirm(mode){
    if (mode === 'selected') {
        const ids = [...document.querySelectorAll('.chk:checked')].map(c => +c.value);
        if (!ids.length) { msg('Tick the lines you want to confirm first.', 'warn'); return; }
        if (confirm('Confirm ' + ids.length + ' selected line(s) as Damaged Stock?')) dmConfirmRows(ids);
    } else {
        const thr = +document.getElementById('thr').value;
        const ids = pendingIds().filter(id => state[id] && state[id].code && state[id].overall >= thr);
        if (!ids.length) { msg('No scanned line is at or above ' + thr + '%. Run "AI Scan & Tally" first.', 'warn'); return; }
        if (confirm('Bulk confirm ' + ids.length + ' line(s) tallying ' + thr + '% or more as Damaged Stock?')) dmConfirmRows(ids);
    }
}
async function dmUnconfirm(id){
    if (!confirm('Remove this confirmation? Its qty leaves Damaged Stock.')) return;
    await fetch('sampath_damage_match.php?ajax=unconfirm', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({line_ids: [id]})});
    location.reload();
}
</script>
<?php include 'footer.php'; ?>
