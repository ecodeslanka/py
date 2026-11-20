<?php
/**
 * field_summary_verify.php — Field Summary Verify (works like Cheque FC Verify)
 * A list of field summaries; "Check" opens a large window with the summary's totals worked out the same way as
 * Edit Field Summary (active invoices only, payments of the delivery date), the SR subtotals and every invoice.
 * Verify / Undo Verify saves the same verify flag as View / Edit Field Summary (field_summary.is_verified + log),
 * then moves on to the next summary by itself. "Edit Field Summary" opens the edit page when something is wrong.
 */
include 'config.php';
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
if (file_exists(__DIR__ . '/fs_verify_lib.php')) include_once __DIR__ . '/fs_verify_lib.php';
if (file_exists(__DIR__ . '/rbac.php')) require_once __DIR__ . '/rbac.php';

function fsvp_q($conn, $sql) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } }
function fsvp_json($a) { while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json'); echo json_encode($a); exit; }
function fsvp_user() { return function_exists('fsv_user') ? fsv_user() : ($_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? $_SESSION['name'] ?? (isset($_SESSION['user_id']) ? 'User #' . $_SESSION['user_id'] : 'system')); }
function fsvp_ensure($conn) {
    if (function_exists('fsv_ensure')) { fsv_ensure($conn); return; }
    foreach (['is_verified' => "TINYINT(1) NOT NULL DEFAULT 0", 'verified_at' => "DATETIME NULL", 'verified_by' => "VARCHAR(100) NULL"] as $c => $d) {
        $r = fsvp_q($conn, "SHOW COLUMNS FROM field_summary LIKE '$c'"); if ($r && mysqli_num_rows($r) === 0) fsvp_q($conn, "ALTER TABLE field_summary ADD COLUMN `$c` $d"); }
    fsvp_q($conn, "CREATE TABLE IF NOT EXISTS field_summary_verify_log (id INT AUTO_INCREMENT PRIMARY KEY, field_summary_id INT NOT NULL, action VARCHAR(20) NOT NULL,
        done_by VARCHAR(100) NULL, done_at DATETIME NOT NULL, INDEX idx_fs (field_summary_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function fsvp_cols($conn, $t) { static $m = []; if (isset($m[$t])) return $m[$t]; $o = []; $r = fsvp_q($conn, "SHOW COLUMNS FROM `$t`"); while ($r && ($x = mysqli_fetch_assoc($r))) $o[strtolower($x['Field'])] = 1; return $m[$t] = $o; }
function fsvp_can($btn) { return function_exists('rbac_btn_can') ? rbac_btn_can($btn, 'field_summary_list') : true; }
fsvp_ensure($conn);
foreach ([['field_summary_details', 'field_summary_id'], ['invoice_payments', 'field_summary_id'], ['field_summary', 'delivery_date']] as [$t, $c]) {
    $r = fsvp_q($conn, "SHOW INDEX FROM `$t` WHERE Column_name = '$c'"); if ($r && mysqli_num_rows($r) === 0) fsvp_q($conn, "ALTER TABLE `$t` ADD INDEX `idx_$c` (`$c`)"); }

/* totals of the given summaries, the same way as Edit Field Summary: active invoices (not To Be Delivery) and their payments of the delivery date */
function fsvp_totals($conn, array $ids) {
    $ids = array_values(array_filter(array_map('intval', $ids))); $T = []; if (!$ids) return $T; $in = implode(',', $ids);
    $D = fsvp_cols($conn, 'field_summary_details');
    $n = fn($c) => isset($D[$c]) ? "COALESCE(d.$c,0)" : '0';
    $act = isset($D['to_be_delivery']) ? "COALESCE(d.to_be_delivery,0) = 0" : "1=1";
    $r = fsvp_q($conn, "SELECT d.field_summary_id fid, COUNT(*) inv, SUM(" . $n('net_value') . ") net, SUM(" . $n('scheme_discount') . "+" . $n('promotion_discount') . "+" . $n('tot_dis') . ") dis,
                               SUM(" . $n('market_return') . ") mkt, SUM(" . $n('damage_adjustment') . ") dmg, SUM(" . $n('cancel_value') . ") cancel, SUM(" . $n('adjust_net_value') . ") final,
                               SUM(" . $n('ikea_value') . ") ikea, SUM(" . $n('short_excess') . ") short" . (isset($D['updated']) ? ", SUM(COALESCE(d.updated,0)=1) upd" : ", 0 upd") . "
                        FROM field_summary_details d WHERE d.field_summary_id IN ($in) AND $act GROUP BY d.field_summary_id");
    while ($r && ($x = mysqli_fetch_assoc($r))) $T[(int)$x['fid']] = array_map('floatval', $x);
    if (isset($D['to_be_delivery'])) { $r = fsvp_q($conn, "SELECT field_summary_id, COUNT(*) FROM field_summary_details WHERE field_summary_id IN ($in) AND to_be_delivery = 1 GROUP BY field_summary_id");
        while ($r && ($x = mysqli_fetch_row($r))) $T[(int)$x[0]]['tbd'] = (int)$x[1]; }
    $r = fsvp_q($conn, "SELECT ip.field_summary_id fid, d.id did, SUM(ip.amount) paid, SUM(CASE WHEN LOWER(TRIM(ip.payment_method)) = 'cash' THEN ip.amount ELSE 0 END) cash
                        FROM invoice_payments ip
                        INNER JOIN field_summary fs ON fs.id = ip.field_summary_id AND ip.payment_date = fs.delivery_date
                        INNER JOIN field_summary_details d ON d.id = ip.field_summary_detail_id AND $act
                        WHERE ip.field_summary_id IN ($in)" . (isset(fsvp_cols($conn, 'invoice_payments')['payment_source']) ? " AND ip.payment_source = 'invoice'" : "") . " GROUP BY ip.field_summary_id, d.id");
    $paidRow = [];
    while ($r && ($x = mysqli_fetch_assoc($r))) { $f = (int)$x['fid']; $T[$f]['cash'] = ($T[$f]['cash'] ?? 0) + (float)$x['cash']; $T[$f]['cheque'] = ($T[$f]['cheque'] ?? 0) + (float)$x['paid'] - (float)$x['cash']; $paidRow[(int)$x['did']] = (float)$x['paid']; }
    /* invoices done = marked updated, or paid in full */
    $r = fsvp_q($conn, "SELECT d.id, d.field_summary_id fid, " . $n('adjust_net_value') . " fin" . (isset($D['updated']) ? ", COALESCE(d.updated,0) upd" : ", 0 upd") . " FROM field_summary_details d WHERE d.field_summary_id IN ($in) AND $act");
    while ($r && ($x = mysqli_fetch_assoc($r))) { $f = (int)$x['fid']; $T[$f]['done'] = ($T[$f]['done'] ?? 0) + (((int)$x['upd'] === 1 || ($paidRow[(int)$x['id']] ?? 0) >= (float)$x['fin']) ? 1 : 0); }
    foreach ($T as $f => &$t) { foreach (['inv','net','dis','mkt','dmg','cancel','final','ikea','short','cash','cheque','tbd','done'] as $k) $t[$k] = round((float)($t[$k] ?? 0), 2);
        $t['paid'] = round($t['cash'] + $t['cheque'], 2); $t['balance'] = round($t['final'] - $t['paid'], 2); }
    return $T;
}

/* ── verify / undo ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['verify', 'unverify'], true)) {
    $act = $_POST['action']; $id = (int)($_POST['id'] ?? 0);
    if ($act === 'verify' && !fsvp_can('fs_verify')) fsvp_json(['ok' => false, 'msg' => 'Permission blocked: your role cannot Verify field summaries (Users → Roles → Field Summary List → Verify).']);
    if ($act === 'unverify' && !fsvp_can('fs_reverse_verify')) fsvp_json(['ok' => false, 'msg' => 'Permission blocked: your role cannot Undo Verify (Users → Roles → Field Summary List → Reverse / Undo Verify).']);
    $r = fsvp_q($conn, "SELECT id FROM field_summary WHERE id = $id"); if (!$r || !mysqli_num_rows($r)) fsvp_json(['ok' => false, 'msg' => 'Field summary not found.']);
    $u = mysqli_real_escape_string($conn, fsvp_user()); $now = date('Y-m-d H:i:s');
    $ok = $act === 'verify' ? fsvp_q($conn, "UPDATE field_summary SET is_verified = 1, verified_at = '$now', verified_by = '$u' WHERE id = $id")
                            : fsvp_q($conn, "UPDATE field_summary SET is_verified = 0, verified_at = NULL, verified_by = NULL WHERE id = $id");
    if (!$ok) fsvp_json(['ok' => false, 'msg' => 'Could not save: ' . mysqli_error($conn)]);
    fsvp_q($conn, "INSERT INTO field_summary_verify_log (field_summary_id, action, done_by, done_at) VALUES ($id, '$act', '$u', '$now')");
    fsvp_json(['ok' => true, 'verified' => $act === 'verify', 'at' => $act === 'verify' ? $now : null, 'by' => $act === 'verify' ? fsvp_user() : null]);
}

/* ── list ── */
if (($_GET['ajax'] ?? '') === 'list') {
    $w = ['1=1']; $e = fn($v) => mysqli_real_escape_string($conn, $v);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '')) $w[] = "fs.delivery_date >= '" . $_GET['from'] . "'";
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '')) $w[] = "fs.delivery_date <= '" . $_GET['to'] . "'";
    $st = $_GET['status'] ?? 'not';
    if ($st === 'not') $w[] = "COALESCE(fs.is_verified,0) = 0"; elseif ($st === 'verified') $w[] = "fs.is_verified = 1";
    if (($q = trim($_GET['q'] ?? '')) !== '') { $q = $e($q); $w[] = "(fs.field_summary_code LIKE '%$q%' OR fs.route LIKE '%$q%' OR fs.sr_code LIKE '%$q%' OR fs.delivery_person_raw_name LIKE '%$q%')"; }
    $C = fsvp_cols($conn, 'field_summary'); $sc = isset($C['scan_count']) ? 'fs.scan_count' : '0';
    $r = fsvp_q($conn, "SELECT fs.id, fs.field_summary_code code, fs.delivery_date, fs.route, fs.sr_code, fs.delivery_person_raw_name dp, $sc scans, COALESCE(fs.is_verified,0) v, fs.verified_at, fs.verified_by
                        FROM field_summary fs WHERE " . implode(' AND ', $w) . " ORDER BY fs.delivery_date DESC, fs.id DESC LIMIT 500");
    if (!$r) fsvp_json(['ok' => false, 'msg' => 'Could not read the field summaries: ' . mysqli_error($conn)]);
    $L = []; while ($x = mysqli_fetch_assoc($r)) $L[] = $x;
    $T = fsvp_totals($conn, array_column($L, 'id'));
    foreach ($L as &$x) $x['t'] = $T[(int)$x['id']] ?? null;
    $k = fsvp_q($conn, "SELECT COUNT(*), SUM(COALESCE(is_verified,0)=1) FROM field_summary fs WHERE " . implode(' AND ', array_filter($w, fn($c) => strpos($c, 'is_verified') === false)));
    $kk = $k ? mysqli_fetch_row($k) : [0, 0];
    fsvp_json(['ok' => true, 'rows' => $L, 'all' => (int)$kk[0], 'verified' => (int)$kk[1], 'can_verify' => fsvp_can('fs_verify'), 'can_undo' => fsvp_can('fs_reverse_verify')]);
}

/* ── one summary: totals, SR subtotals, invoices, verify log ── */
if (($_GET['ajax'] ?? '') === 'detail') {
    $id = (int)($_GET['id'] ?? 0);
    $r = fsvp_q($conn, "SELECT * FROM field_summary WHERE id = $id"); $fs = $r ? mysqli_fetch_assoc($r) : null;
    if (!$fs) fsvp_json(['ok' => false, 'msg' => 'Field summary not found.']);
    $D = fsvp_cols($conn, 'field_summary_details'); $n = fn($c) => isset($D[$c]) ? "COALESCE(d.$c,0)" : '0';
    $dd = mysqli_real_escape_string($conn, (string)$fs['delivery_date']);
    $pay = []; $r = fsvp_q($conn, "SELECT field_summary_detail_id did, SUM(amount) paid, SUM(CASE WHEN LOWER(TRIM(payment_method)) = 'cash' THEN amount ELSE 0 END) cash
                                  FROM invoice_payments WHERE field_summary_id = $id AND payment_date = '$dd'" . (isset(fsvp_cols($conn, 'invoice_payments')['payment_source']) ? " AND payment_source = 'invoice'" : "") . " GROUP BY field_summary_detail_id");
    while ($r && ($x = mysqli_fetch_assoc($r))) $pay[(int)$x['did']] = $x;
    $hasL = fsvp_q($conn, "SHOW TABLES LIKE 'loading_summary_import_details'"); $hasL = $hasL && mysqli_num_rows($hasL) > 0;
    if ($hasL) { $LC = fsvp_cols($conn, 'loading_summary_import_details'); $hasL = isset($LC['bill_no'], $LC['delivery_date'], $LC['sales_person_code']); }
    $dsql = fn($hasL) => "SELECT d.id, d.invoice_num, d.t_code, COALESCE(NULLIF(d.customer_name,''), c.shop_name, d.invoice_num) cust, " . ($hasL ? "lsi.sales_person_code" : "''") . " sr,
                               " . $n('net_value') . " net, " . $n('scheme_discount') . "+" . $n('promotion_discount') . "+" . $n('tot_dis') . " dis, " . $n('market_return') . " mkt, " . $n('damage_adjustment') . " dmg,
                               " . $n('cancel_value') . " cancel, " . $n('adjust_net_value') . " final, " . $n('ikea_value') . " ikea, " . $n('short_excess') . " short,
                               " . (isset($D['updated']) ? "COALESCE(d.updated,0)" : "0") . " upd, " . (isset($D['to_be_delivery']) ? "COALESCE(d.to_be_delivery,0)" : "0") . " tbd
                        FROM field_summary_details d LEFT JOIN customers c ON c.t_code = d.t_code
                        " . ($hasL ? "LEFT JOIN loading_summary_import_details lsi ON lsi.bill_no = d.invoice_num AND lsi.delivery_date = '$dd'" : "") . "
                        WHERE d.field_summary_id = $id ORDER BY tbd, sr, d.invoice_num";
    $r = fsvp_q($conn, $dsql($hasL)); if (!$r && $hasL) $r = fsvp_q($conn, $dsql(false));   // without the SR of the loading summary when that join cannot run
    if (!$r) fsvp_json(['ok' => false, 'msg' => 'Could not read the invoices: ' . mysqli_error($conn)]);
    $rows = []; $seen = [];
    while ($r && ($x = mysqli_fetch_assoc($r))) { if (isset($seen[$x['id']])) continue; $seen[$x['id']] = 1;   // one row per invoice (the loading summary can repeat a bill)
        $p = $pay[(int)$x['id']] ?? ['paid' => 0, 'cash' => 0]; $x['cash'] = round((float)$p['cash'], 2); $x['cheque'] = round((float)$p['paid'] - (float)$p['cash'], 2); $x['paid'] = round((float)$p['paid'], 2);
        $x['balance'] = round((float)$x['final'] - $x['paid'], 2); $x['done'] = ((int)$x['upd'] === 1 || $x['paid'] >= (float)$x['final']) ? 1 : 0; $rows[] = $x; }
    $T = fsvp_totals($conn, [$id]); $t = $T[$id] ?? null;
    $log = []; $r = fsvp_q($conn, "SELECT action, done_by, done_at FROM field_summary_verify_log WHERE field_summary_id = $id ORDER BY id DESC LIMIT 20"); while ($r && ($x = mysqli_fetch_assoc($r))) $log[] = $x;
    fsvp_json(['ok' => true, 'fs' => ['id' => (int)$fs['id'], 'code' => $fs['field_summary_code'] ?? '', 'delivery_date' => $fs['delivery_date'] ?? '', 'route' => $fs['route'] ?? '', 'sr_code' => $fs['sr_code'] ?? '',
               'dp' => $fs['delivery_person_raw_name'] ?? '', 'vehicle' => $fs['vehicle_no'] ?? ($fs['vehicle'] ?? ''), 'scans' => (int)($fs['scan_count'] ?? 0), 'v' => (int)($fs['is_verified'] ?? 0), 'verified_at' => $fs['verified_at'] ?? null, 'verified_by' => $fs['verified_by'] ?? null],
               't' => $t, 'rows' => $rows, 'log' => $log]);
}

include 'header.php';
?>
<style>
.fv{padding:16px;color:#111827}
.fv h2{margin:0 0 4px;font-size:20px;font-weight:800;display:flex;gap:9px;align-items:center}
.fv .sub{color:#6b7280;font-size:12.5px;margin-bottom:14px}
.fv-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:10px 12px;margin-bottom:12px}
.fv-bar label{font-size:11.5px;font-weight:700;color:#475569;display:flex;flex-direction:column;gap:3px}
.fv-bar input,.fv-bar select{border:1.5px solid #e5e7eb;border-radius:8px;padding:6px 8px;font-size:13px}
.fv-btn{border:none;border-radius:8px;padding:8px 14px;font-weight:700;font-size:13px;cursor:pointer;display:inline-flex;gap:6px;align-items:center;text-decoration:none;white-space:nowrap}
.fv-btn.p{background:#2563eb;color:#fff}.fv-btn.g{background:#f3f4f6;color:#374151}.fv-btn.ok{background:#16a34a;color:#fff}.fv-btn.r{background:#fee2e2;color:#b91c1c}.fv-btn.o{background:#f59e0b;color:#fff}
.fv-btn:disabled{opacity:.5;cursor:not-allowed}
.fv-kpi{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}
.fv-kpi div{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:8px 14px;min-width:130px}.fv-kpi b{font-size:19px;display:block}.fv-kpi span{font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:700}
.fv-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:auto}
.fv-t{width:100%;border-collapse:collapse;font-size:12.5px}
.fv-t th{background:#f1f5f9;text-align:left;padding:8px;font-size:11px;text-transform:uppercase;color:#475569;white-space:nowrap;position:sticky;top:0}
.fv-t td{padding:7px 8px;border-top:1px solid #f1f5f9;white-space:nowrap}
.fv-t .r{text-align:right}.fv-t tr.cur{background:#eff6ff}
.badge{display:inline-flex;gap:4px;align-items:center;border-radius:99px;padding:2px 9px;font-size:11px;font-weight:800}
.badge.v{background:#dcfce7;color:#166534}.badge.n{background:#fef3c7;color:#92400e}
/* modal */
.fvm{position:fixed;inset:0;z-index:2147483000;background:rgba(15,23,42,.6);display:none;align-items:flex-start;justify-content:center;padding:14px;overflow:auto}
.fvm.open{display:flex}
.fvm-box{background:#fff;border-radius:14px;width:min(1400px,100%);box-shadow:0 24px 60px rgba(0,0,0,.45);overflow:hidden;display:flex;flex-direction:column;max-height:calc(100vh - 28px)}
.fvm-hd{display:flex;align-items:center;gap:12px;padding:12px 16px;background:linear-gradient(90deg,#0f172a,#1e3a8a);color:#fff;flex-wrap:wrap}
.fvm-hd .t{font-size:16px;font-weight:800}.fvm-hd .s{font-size:12px;opacity:.85}
.fvm-hd .x{margin-left:auto;width:34px;height:34px;border:none;border-radius:50%;background:#dc2626;color:#fff;font-size:18px;cursor:pointer}
.fvm-bd{padding:14px 16px;overflow:auto;flex:1}
.tot{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:8px;margin-bottom:12px}
.tot div{border:1px solid #e5e7eb;border-radius:10px;padding:8px 10px;background:#f8fafc}
.tot span{display:block;font-size:10.5px;font-weight:800;color:#64748b;text-transform:uppercase}.tot b{font-size:15.5px}
.tot .hl{background:#eff6ff;border-color:#bfdbfe}.tot .gr b{color:#15803d}.tot .rd b{color:#dc2626}
.chk{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
.chk span{border-radius:8px;padding:5px 10px;font-size:12px;font-weight:700}
.chk .ok{background:#dcfce7;color:#166534}.chk .bad{background:#fee2e2;color:#991b1b}.chk .warn{background:#fef3c7;color:#92400e}
.fvm-ft{display:flex;gap:8px;align-items:center;padding:10px 16px;border-top:1px solid #e5e7eb;background:#f8fafc;flex-wrap:wrap}
.vstamp{font-weight:800;border-radius:8px;padding:6px 12px;font-size:13px}
.inv-t td,.inv-t th{font-size:11.5px;padding:5px 6px}
.inv-t tr.sr td{background:#1f2937;color:#fff;font-weight:700}
.inv-t tr.sub td{background:#e0f2fe;color:#0369a1;font-weight:700}
.inv-t tr.tbd td{color:#6b7280;background:#f8fafc}
.inv-t tfoot td{font-weight:800;background:#f1f5f9;border-top:2px solid #cbd5e1}
</style>
<div class="fv">
  <h2><i class="fa-solid fa-clipboard-check" style="color:#2563eb"></i> Field Summary Verify</h2>
  <div class="sub">Check each field summary — totals as in Edit Field Summary, SR subtotals and invoices — then Verify; the next one opens by itself. Something wrong? Use <b>Edit Field Summary</b> in the window.</div>
  <div class="fv-bar">
    <label>Delivery from<input type="date" id="fFrom"></label>
    <label>to<input type="date" id="fTo"></label>
    <label>Status<select id="fSt"><option value="not">Not verified</option><option value="verified">Verified</option><option value="all">All</option></select></label>
    <label>Search<input id="fQ" placeholder="Code / route / SR / delivery person"></label>
    <button class="fv-btn p" onclick="load()" style="align-self:flex-end"><i class="fa-solid fa-magnifying-glass"></i> Load</button>
    <label style="flex-direction:row;align-items:center;gap:6px;margin-left:auto;align-self:flex-end"><input type="checkbox" id="mAuto" checked> Go to the next after Verify</label>
  </div>
  <div class="fv-kpi" id="kpi"></div>
  <div class="fv-card"><table class="fv-t"><thead><tr><th>#</th><th>Code</th><th>Delivery date</th><th>Route</th><th>SR</th><th>Delivery person</th><th class="r">Invoices</th><th class="r">Final B.V</th><th class="r">Cash</th><th class="r">Cheque</th><th class="r">Paid</th><th class="r">Balance</th><th>Done</th><th>Status</th><th></th></tr></thead><tbody id="tb"><tr><td colspan="15" style="padding:20px;color:#6b7280">Loading…</td></tr></tbody></table></div>
</div>

<div class="fvm" id="fvm"><div class="fvm-box">
  <div class="fvm-hd"><div><div class="t" id="mT">—</div><div class="s" id="mS"></div></div><span id="mStamp"></span><button class="x" onclick="closeM()" title="Close (Esc)">&times;</button></div>
  <div class="fvm-bd" id="mB"></div>
  <div class="fvm-ft">
    <button class="fv-btn g" onclick="step(-1)" title="Previous (←)"><i class="fa-solid fa-chevron-left"></i> Prev</button>
    <button class="fv-btn g" onclick="step(1)" title="Next (→)">Next <i class="fa-solid fa-chevron-right"></i></button>
    <span id="mPos" style="font-size:12px;color:#6b7280"></span>
    <a class="fv-btn o" id="mEdit" target="_blank" href="#" title="Open Edit Field Summary in a new tab — then press Reload here"><i class="fa-solid fa-pen-to-square"></i> Edit Field Summary</a>
    <a class="fv-btn g" id="mView" target="_blank" href="#"><i class="fa-solid fa-eye"></i> View</a>
    <button class="fv-btn g" onclick="openM(CUR)" title="Read the totals again (after editing)"><i class="fa-solid fa-rotate"></i> Reload</button>
    <span style="margin-left:auto"></span>
    <button class="fv-btn r" id="mUndo" onclick="doVerify('unverify')"><i class="fa-solid fa-rotate-left"></i> Undo Verify</button>
    <button class="fv-btn ok" id="mVer" onclick="doVerify('verify')" title="Verify (V)"><i class="fa-solid fa-circle-check"></i> Verify</button>
  </div>
</div></div>

<script>
let ROWS=[], CUR=null, CAN={v:true,u:true};
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const N=(v,d=2)=>Number(v||0).toLocaleString('en-US',{minimumFractionDigits:d,maximumFractionDigits:d});
const Z=v=>Number(v||0)!==0?N(v):'';
async function J(r){ const t=await r.text(); try{ return JSON.parse(t); }catch(e){ throw new Error('Server error: '+t.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim().slice(0,250)); } }
try{ const s=JSON.parse(localStorage.getItem('fsvF')||'{}'); ['fFrom','fTo','fSt','fQ'].forEach(k=>{ if(s[k]!==undefined) document.getElementById(k).value=s[k]; }); document.getElementById('mAuto').checked=localStorage.getItem('fsvAuto')!=='0'; }catch(e){}
if(!document.getElementById('fFrom').value){ const d=new Date(); d.setDate(d.getDate()-30); document.getElementById('fFrom').value=d.toISOString().slice(0,10); }
document.getElementById('mAuto').onchange=e=>{ try{ localStorage.setItem('fsvAuto',e.target.checked?'1':'0'); }catch(x){} };
async function load(){
  const p={from:fFrom.value,to:fTo.value,status:fSt.value,q:fQ.value.trim()}; try{ localStorage.setItem('fsvF',JSON.stringify({fFrom:p.from,fTo:p.to,fSt:p.status,fQ:p.q})); }catch(e){}
  tb.innerHTML='<tr><td colspan="15" style="padding:20px;color:#6b7280">Loading…</td></tr>';
  try{ const d=await J(await fetch(location.pathname+'?ajax=list&'+new URLSearchParams(p))); if(!d.ok) throw new Error(d.msg);
    ROWS=d.rows; CAN={v:d.can_verify,u:d.can_undo}; draw();
    kpi.innerHTML='<div><b>'+N(d.all,0)+'</b><span>Field summaries</span></div><div><b style="color:#15803d">'+N(d.verified,0)+'</b><span>Verified</span></div><div><b style="color:#b45309">'+N(d.all-d.verified,0)+'</b><span>Not verified</span></div><div><b>'+N(ROWS.length,0)+'</b><span>Shown'+(ROWS.length>=500?' (first 500)':'')+'</span></div>';
  }catch(e){ tb.innerHTML='<tr><td colspan="15" style="padding:20px;color:#b91c1c">'+esc(e.message)+'</td></tr>'; } }
function draw(){ tb.innerHTML=ROWS.map((x,i)=>{ const t=x.t||{};
  return '<tr id="r'+x.id+'"'+(CUR===i?' class="cur"':'')+'><td>'+(i+1)+'</td><td><b>'+esc(x.code)+'</b></td><td>'+esc(x.delivery_date)+'</td><td>'+esc(x.route)+'</td><td>'+esc(x.sr_code)+'</td><td>'+esc(x.dp)+'</td>'
   +'<td class="r">'+N(t.inv,0)+'</td><td class="r">'+N(t.final)+'</td><td class="r">'+N(t.cash)+'</td><td class="r">'+N(t.cheque)+'</td><td class="r"><b>'+N(t.paid)+'</b></td><td class="r" style="color:'+(Math.abs(t.balance||0)>0.009?'#dc2626':'#15803d')+'"><b>'+N(t.balance)+'</b></td>'
   +'<td>'+(t.inv?N(t.done,0)+'/'+N(t.inv,0):'—')+'</td><td>'+(Number(x.v)===1?'<span class="badge v"><i class="fa-solid fa-circle-check"></i> Verified</span>':'<span class="badge n">Not verified</span>')+'</td>'
   +'<td><button class="fv-btn p" style="padding:5px 10px;font-size:12px" onclick="openM('+i+')"><i class="fa-solid fa-magnifying-glass"></i> Check</button></td></tr>'; }).join('')||'<tr><td colspan="15" style="padding:20px;color:#6b7280">No field summaries.</td></tr>'; }
function closeM(){ fvm.classList.remove('open'); }
function step(k){ if(CUR===null) return; const n=CUR+k; if(n<0||n>=ROWS.length){ alert(k>0?'This was the last one in the list.':'This is the first one.'); return; } openM(n); }
async function openM(i){ CUR=i; const x=ROWS[i]; if(!x) return; draw(); fvm.classList.add('open');
  mT.textContent=x.code||('#'+x.id); mS.textContent=(x.delivery_date||'')+' · '+(x.route||'')+' · '+(x.dp||''); mPos.textContent=(i+1)+' / '+ROWS.length;
  mEdit.href='edit_field_summary.php?id='+x.id; mView.href='view_field_summary.php?id='+x.id; mB.innerHTML='<div style="padding:30px;color:#6b7280">Loading…</div>'; stamp(x);
  try{ const d=await J(await fetch(location.pathname+'?ajax=detail&id='+x.id)); if(!d.ok) throw new Error(d.msg); if(CUR!==i) return;
    x.v=d.fs.v; x.verified_at=d.fs.verified_at; x.verified_by=d.fs.verified_by; x.t=d.t||x.t; stamp(x); draw(); body(d); }
  catch(e){ mB.innerHTML='<div style="padding:20px;color:#b91c1c">'+esc(e.message)+'</div>'; } }
function stamp(x){ const v=Number(x.v)===1;
  mStamp.innerHTML=v?'<span class="vstamp" style="background:#dcfce7;color:#166534"><i class="fa-solid fa-circle-check"></i> VERIFIED'+(x.verified_by?' · '+esc(x.verified_by):'')+(x.verified_at?' · '+esc(x.verified_at):'')+'</span>':'<span class="vstamp" style="background:#fef3c7;color:#92400e">NOT VERIFIED</span>';
  mVer.style.display=v?'none':''; mVer.disabled=!CAN.v; mVer.title=CAN.v?'Verify (V)':'Your role cannot Verify';
  mUndo.style.display=v?'':'none'; mUndo.disabled=!CAN.u; mUndo.title=CAN.u?'Undo Verify':'Your role cannot Undo Verify'; }
function body(d){ const t=d.t||{}, R=d.rows||[], A=R.filter(r=>Number(r.tbd)!==1), B=R.filter(r=>Number(r.tbd)===1);
  const c=(l,v,cls)=>'<div class="'+(cls||'')+'"><span>'+l+'</span><b>'+v+'</b></div>';
  let h='<div class="tot">'+c('Invoices',N(t.inv,0))+c('Net Inv. Amt',N(t.net))+c('Total Discount',N(t.dis))+c('Mkt-Rtn',N(t.mkt))+c('Dmg/Exp/Sh',N(t.dmg))+c('Cancelled',N(t.cancel))
    +c('Final B.V',N(t.final),'hl')+c('Ikea',N(t.ikea))+c('Short/Excess',N(t.short),Math.abs(t.short||0)>0.009?'rd':'')+c('Cash Paid',N(t.cash),'gr')+c('Cheque Paid',N(t.cheque),'gr')+c('Total Paid',N(t.paid),'gr hl')
    +c('Balance',N(t.balance),Math.abs(t.balance||0)>0.009?'rd hl':'gr hl')+'</div>';
  const notDone=A.filter(r=>!Number(r.done)).length;
  h+='<div class="chk">'+(notDone?'<span class="bad"><i class="fa-solid fa-xmark"></i> '+notDone+' invoice(s) not updated / not fully paid</span>':'<span class="ok"><i class="fa-solid fa-check"></i> All invoices updated</span>')
    +(Math.abs(t.balance||0)>0.009?'<span class="warn"><i class="fa-solid fa-triangle-exclamation"></i> Balance Rs. '+N(t.balance)+'</span>':'<span class="ok"><i class="fa-solid fa-check"></i> No balance</span>')
    +(Math.abs(t.short||0)>0.009?'<span class="warn"><i class="fa-solid fa-triangle-exclamation"></i> Short/Excess Rs. '+N(t.short)+'</span>':'')
    +(d.fs.scans>0?'<span class="ok"><i class="fa-solid fa-image"></i> '+d.fs.scans+' scan(s)</span>':'<span class="warn"><i class="fa-solid fa-image"></i> No scan uploaded</span>')
    +(B.length?'<span class="warn"><i class="fa-solid fa-truck"></i> '+B.length+' To Be Delivery (not in the totals)</span>':'')+'</div>';
  /* invoices grouped by SR with subtotals, like the edit page */
  const cols=['net','dis','mkt','dmg','cancel','final','ikea','short','cash','cheque','paid','balance'];
  const cells=o=>cols.map(k=>'<td class="r"'+(k==='balance'&&Math.abs(o[k]||0)>0.009?' style="color:#dc2626;font-weight:700"':'')+'>'+(k==='net'||k==='final'||k==='paid'||k==='balance'?N(o[k]):Z(o[k]))+'</td>').join('');
  h+='<div style="overflow:auto"><table class="fv-t inv-t"><thead><tr><th>#</th><th>Invoice</th><th>T-Code</th><th>Customer</th><th class="r">Net Inv.Amt</th><th class="r">Total Disc.</th><th class="r">Mkt-Rtn</th><th class="r">Dmg/Exp/Sh</th><th class="r">Cancelled</th><th class="r">Final B.V</th><th class="r">Ikea</th><th class="r">Short/Excess</th><th class="r">Cash</th><th class="r">Cheque</th><th class="r">Total Paid</th><th class="r">Balance</th><th></th></tr></thead><tbody>';
  let sr=null, sub=null, n=0; const flush=()=>{ if(sub) h+='<tr class="sub"><td colspan="4" class="r">Subtotal — SR '+esc(sr||'(No SR)')+' ('+sub.n+')</td>'+cells(sub)+'<td></td></tr>'; };
  A.forEach(r=>{ if(r.sr!==sr){ flush(); sr=r.sr; sub={n:0}; h+='<tr class="sr"><td colspan="17"><i class="fa-solid fa-layer-group"></i> SR Code: '+esc(sr||'(No SR Code)')+'</td></tr>'; }
    cols.forEach(k=>sub[k]=(sub[k]||0)+Number(r[k]||0)); sub.n++;
    h+='<tr><td>'+(++n)+'</td><td>'+esc(r.invoice_num)+'</td><td>'+esc(r.t_code)+'</td><td>'+esc(r.cust)+'</td>'+cells(r)+'<td>'+(Number(r.done)?'<i class="fa-solid fa-circle-check" style="color:#16a34a" title="Updated / paid"></i>':'<i class="fa-solid fa-circle-xmark" style="color:#dc2626" title="Not updated / not fully paid"></i>')+'</td></tr>'; });
  flush();
  h+='</tbody><tfoot><tr><td colspan="4" class="r">Total ('+A.length+')</td>'+cells(t)+'<td></td></tr></tfoot></table></div>';
  if(B.length) h+='<div style="margin-top:12px;font-weight:800;color:#1e40af"><i class="fa-solid fa-truck"></i> To Be Delivery — '+B.length+' (not in the totals)</div><div style="overflow:auto"><table class="fv-t inv-t"><tbody>'+B.map((r,i)=>'<tr class="tbd"><td>'+(i+1)+'</td><td>'+esc(r.invoice_num)+'</td><td>'+esc(r.cust)+'</td><td class="r">'+N(r.final)+'</td></tr>').join('')+'</tbody></table></div>';
  if(d.log&&d.log.length) h+='<div style="margin-top:12px;font-size:12px;color:#475569"><b>Verify log:</b> '+d.log.map(l=>esc(l.action)+' · '+esc(l.done_by||'')+' · '+esc(l.done_at)).join(' &nbsp;|&nbsp; ')+'</div>';
  mB.innerHTML=h; }
async function doVerify(a){ const x=ROWS[CUR]; if(!x) return; if(a==='unverify'&&!confirm('Undo the verification of '+(x.code||'')+'?')) return;
  const btn=a==='verify'?mVer:mUndo; btn.disabled=true;
  try{ const fd=new FormData(); fd.append('action',a); fd.append('id',x.id); const d=await J(await fetch(location.pathname,{method:'POST',body:fd})); if(!d.ok) throw new Error(d.msg);
    x.v=d.verified?1:0; x.verified_at=d.at; x.verified_by=d.by; stamp(x); draw();
    if(a==='verify'&&mAuto.checked){ if(CUR<ROWS.length-1) setTimeout(()=>openM(CUR+1),350); else { alert('Verified. That was the last one in the list.'); } } }
  catch(e){ alert(e.message); } finally{ btn.disabled=false; stamp(x); } }
document.addEventListener('keydown',e=>{ if(!fvm.classList.contains('open')) return; if(/INPUT|TEXTAREA|SELECT/.test(e.target.tagName)) return;
  if(e.key==='Escape') closeM(); else if(e.key==='ArrowRight') step(1); else if(e.key==='ArrowLeft') step(-1); else if((e.key==='v'||e.key==='V')&&mVer.style.display!=='none'&&!mVer.disabled) doVerify('verify'); });
load();
</script>
<?php include 'footer.php'; ?>
