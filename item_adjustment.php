<?php
// ============================================================
//  item_adjustment.php
//  ITEM ADJUSTMENT — add / delete stock adjustments per item
//
//  An adjustment is a manual +/- unit quantity against an item on a date (stock_manual_adjustments.adjustment_qty):
//    + adds units to stock, - reduces units. It feeds every stock report that reads Adjustment Qty:
//    Sampath Inventory Report, Inventory Reconciliation Report, Sampath Stock Reconciliation, Current Stock reports.
//  Entries can be deleted again (the reports update straight away).
// ============================================================
include 'config.php';
include_once 'auth.php';
requireLogin();
require_once 'item_match_lib.php';     // im_load_masters()
if (session_status() === PHP_SESSION_NONE) session_start();
$who = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'System';

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS stock_manual_adjustments (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(100) NOT NULL,
    entry_date DATE NOT NULL,
    rejected_qty DECIMAL(14,2) NOT NULL DEFAULT 0,
    adjustment_qty DECIMAL(14,2) NOT NULL DEFAULT 0,
    note VARCHAR(255) DEFAULT NULL,
    created_by VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_item_code (item_code),
    INDEX idx_entry_date (entry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$e = function ($v) use ($conn) { return mysqli_real_escape_string($conn, (string)$v); };

/* item master (code => name) for the item picker */
$items = [];
foreach (im_load_masters($conn) as $m) $items[$m['code']] = $m['name'];
ksort($items);

/* ── POST: save / delete ── */
$qs_back = function ($extra) {
    $keep = [];
    foreach (['date_from', 'date_to', 'items', 'search'] as $k) if (isset($_GET[$k]) && $_GET[$k] !== '' && $_GET[$k] !== []) $keep[$k] = $_GET[$k];
    return 'item_adjustment.php?' . http_build_query($keep + $extra);
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if ($act === 'save') {
        $code = trim((string)($_POST['item_code'] ?? ''));
        $date = trim((string)($_POST['entry_date'] ?? ''));
        $qty  = (float)str_replace(',', '', (string)($_POST['adjustment_qty'] ?? '0'));
        $note = trim((string)($_POST['note'] ?? ''));
        if ($code === '' || !isset($items[$code])) { header('Location: ' . $qs_back(['err' => 'Choose an item from the list.'])); exit; }
        if ($date === '' || !strtotime($date))      { header('Location: ' . $qs_back(['err' => 'Choose a valid date.'])); exit; }
        if ($qty == 0)                               { header('Location: ' . $qs_back(['err' => 'Enter an adjustment qty (+ to add, - to reduce) - it was zero.'])); exit; }
        $ok = mysqli_query($conn, "INSERT INTO stock_manual_adjustments (item_code, entry_date, adjustment_qty, note, created_by)
            VALUES ('" . $e($code) . "', '" . $e(date('Y-m-d', strtotime($date))) . "', $qty, '" . $e(mb_substr($note, 0, 255)) . "', '" . $e($who) . "')");
        header('Location: ' . $qs_back($ok ? ['msg' => 'Adjustment saved for ' . $code . '.'] : ['err' => 'Could not save: ' . mysqli_error($conn)]));
        exit;
    }
    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) mysqli_query($conn, "DELETE FROM stock_manual_adjustments WHERE id = $id");
        header('Location: ' . $qs_back(['msg' => 'Adjustment deleted.']));
        exit;
    }
}

/* ── filters + list ── */
$f_from = trim($_GET['date_from'] ?? '');
$f_to   = trim($_GET['date_to'] ?? '');
$f_search = trim($_GET['search'] ?? '');
$f_items = [];
foreach ((array)($_GET['items'] ?? []) as $v) { $v = trim((string)$v); if ($v !== '') $f_items[] = $v; }
$w = ['1=1'];
if ($f_from)   $w[] = "entry_date >= '" . $e($f_from) . "'";
if ($f_to)     $w[] = "entry_date <= '" . $e($f_to) . "'";
if ($f_items)  $w[] = "item_code IN ('" . implode("','", array_map($e, $f_items)) . "')";
if ($f_search) $w[] = "(note LIKE '%" . $e($f_search) . "%' OR item_code LIKE '%" . $e($f_search) . "%' OR created_by LIKE '%" . $e($f_search) . "%')";
$rows = []; $net = 0.0; $plus = 0.0; $minus = 0.0;
$q = mysqli_query($conn, "SELECT id, item_code, entry_date, rejected_qty, adjustment_qty, note, created_by, created_at
    FROM stock_manual_adjustments WHERE " . implode(' AND ', $w) . " ORDER BY entry_date DESC, id DESC LIMIT 1000");
while ($q && $r = mysqli_fetch_assoc($q)) {
    $r['item_name'] = $items[$r['item_code']] ?? '';
    $rows[] = $r;
    $a = (float)$r['adjustment_qty']; $net += $a; if ($a > 0) $plus += $a; else $minus += $a;
}
$flash_ok = $_GET['msg'] ?? ''; $flash_err = $_GET['err'] ?? '';
include 'header.php';
function ia_n($v) { return ($v < 0 ? '-' : ($v > 0 ? '+' : '')) . number_format(abs($v), 2); }
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.4.0/exceljs.min.js"></script>
<style>
.ia{padding:0 4px;}
.ia h2{font-size:22px;font-weight:800;margin:0 0 4px;}
.ia .sub{font-size:12px;color:#8892a4;margin-bottom:16px;}
.ia-card{background:#fff;border:1.5px solid #e2e6f0;border-radius:14px;padding:16px 20px;margin-bottom:18px;}
.ia-f{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;}
.ia-f label{display:block;font-size:10px;font-weight:700;color:#475569;text-transform:uppercase;margin-bottom:4px;}
.ia-f input,.ia-f select{border:1.5px solid #e2e6f0;border-radius:8px;padding:8px 12px;font-size:13px;background:#fff;}
.ia-b{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;background:#0a0c14;color:#fff;text-decoration:none;}
.ia-b.l{background:#f7f8fc;color:#475569;border:1.5px solid #e2e6f0;} .ia-b.g{background:#14532d;} .ia-b.r{background:#dc2626;} .ia-b.sm{padding:4px 10px;font-size:11px;}
.ia-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:18px;}
.ia-kpi{background:#fff;border:1.5px solid #e2e6f0;border-top:4px solid var(--c);border-radius:12px;padding:10px 16px;min-width:0;}
.ia-kpi .l{font-size:10px;font-weight:700;color:#8892a4;text-transform:uppercase;} .ia-kpi .v{font-size:20px;font-weight:800;color:var(--c);margin-top:2px;font-variant-numeric:tabular-nums;overflow-wrap:anywhere;}
.ia-wrap{overflow:auto;border:1.5px solid #e2e6f0;border-radius:12px;background:#fff;-webkit-overflow-scrolling:touch;}
table.ia-t{width:100%;border-collapse:collapse;font-size:12px;}
table.ia-t th{background:#0a0c14;color:#fff;padding:9px 10px;text-align:left;font-size:10.5px;text-transform:uppercase;white-space:nowrap;}
table.ia-t td{padding:7px 10px;border-bottom:1px solid #eef0f6;vertical-align:top;}
table.ia-t td.n{text-align:right;font-family:monospace;white-space:nowrap;}
.pos{color:#15803d;font-weight:800;} .negq{color:#dc2626;font-weight:800;}
.ia-msg{border-radius:8px;padding:10px 16px;margin-bottom:12px;font-size:13px;}
.ia-msg.ok{background:#dcfce7;border:1px solid #86efac;color:#166534;} .ia-msg.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;}
.select2-container{max-width:100% !important;}
@media (max-width:768px){ .ia-f{flex-direction:column;align-items:stretch;} .ia-f>div{width:100%;} .ia-f input,.ia-f select,.ia-f .select2-container{width:100% !important;} .ia-b{justify-content:center;} }
</style>

<div class="ia">
    <h2><i class="fa-solid fa-sliders"></i> Item Adjustment</h2>
    <div class="sub">Add a manual stock adjustment for an item: a <strong>+</strong> qty adds units to stock, a <strong>−</strong> qty reduces it. Adjustments feed the Sampath Inventory Report, Inventory Reconciliation Report and Sampath Stock Reconciliation. A wrong entry can be deleted — the reports update straight away.</div>

    <?php if ($flash_ok): ?><div class="ia-msg ok"><?php echo htmlspecialchars($flash_ok); ?></div><?php endif; ?>
    <?php if ($flash_err): ?><div class="ia-msg err"><?php echo htmlspecialchars($flash_err); ?></div><?php endif; ?>

    <!-- ADD -->
    <form method="POST" class="ia-card">
        <input type="hidden" name="action" value="save">
        <div style="font-size:14px;font-weight:800;margin-bottom:10px;"><i class="fa-solid fa-plus"></i> New Adjustment</div>
        <div class="ia-f">
            <div style="min-width:320px;flex:1.4"><label>Item (code - name)</label>
                <select name="item_code" id="adjItem" required style="width:100%">
                    <option value=""></option>
                    <?php foreach ($items as $c => $nm): ?><option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c . ' - ' . $nm); ?></option><?php endforeach; ?>
                </select></div>
            <div><label>Date</label><input type="date" name="entry_date" value="<?php echo date('Y-m-d'); ?>" required></div>
            <div><label>Adjustment Qty (units, + / −)</label><input type="number" step="0.01" name="adjustment_qty" placeholder="e.g. 24 or -12" required style="width:170px"></div>
            <div style="flex:1;min-width:200px"><label>Note</label><input type="text" name="note" maxlength="255" placeholder="Reason for the adjustment" style="width:100%"></div>
            <button class="ia-b g" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Adjustment</button>
        </div>
    </form>

    <div class="ia-kpis">
        <div class="ia-kpi" style="--c:#0a0c14"><div class="l">Entries</div><div class="v"><?php echo count($rows); ?></div></div>
        <div class="ia-kpi" style="--c:#15803d"><div class="l">Added (+)</div><div class="v"><?php echo ia_n($plus); ?></div></div>
        <div class="ia-kpi" style="--c:#dc2626"><div class="l">Reduced (−)</div><div class="v"><?php echo ia_n($minus); ?></div></div>
        <div class="ia-kpi" style="--c:#1d4ed8"><div class="l">Net adjustment</div><div class="v"><?php echo ia_n($net); ?></div></div>
    </div>

    <!-- FILTERS -->
    <form method="GET" class="ia-card">
        <div class="ia-f">
            <div><label>Date From</label><input type="date" name="date_from" value="<?php echo htmlspecialchars($f_from); ?>"></div>
            <div><label>Date To</label><input type="date" name="date_to" value="<?php echo htmlspecialchars($f_to); ?>"></div>
            <div style="min-width:300px"><label>Items</label>
                <select name="items[]" id="fltItems" multiple style="width:100%">
                    <?php foreach ($items as $c => $nm): ?><option value="<?php echo htmlspecialchars($c); ?>" <?php echo in_array((string)$c, $f_items, true) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c . ' - ' . $nm); ?></option><?php endforeach; ?>
                </select></div>
            <div><label>Search note / user</label><input type="text" name="search" value="<?php echo htmlspecialchars($f_search); ?>"></div>
            <button class="ia-b" type="submit"><i class="fa-solid fa-filter"></i> Apply</button>
            <a class="ia-b l" href="item_adjustment.php">Reset</a>
            <button class="ia-b g" type="button" onclick="iaExport()"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
        </div>
    </form>

    <div class="ia-wrap"><table class="ia-t">
        <thead><tr><th>Date</th><th>Item</th><th class="n" style="text-align:right">Adjustment (units)</th><th>Note</th><th>By</th><th>Saved at</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $a = (float)$r['adjustment_qty']; ?>
            <tr>
                <td style="white-space:nowrap"><?php echo htmlspecialchars($r['entry_date']); ?></td>
                <td><strong><?php echo htmlspecialchars($r['item_code']); ?></strong> <?php echo htmlspecialchars($r['item_name']); ?>
                    <?php if ((float)$r['rejected_qty'] != 0): ?><div style="font-size:10.5px;color:#92400e">also rejected qty on this entry: <?php echo number_format((float)$r['rejected_qty'], 2); ?></div><?php endif; ?></td>
                <td class="n <?php echo $a < 0 ? 'negq' : 'pos'; ?>"><?php echo $a == 0 ? '—' : ia_n($a); ?></td>
                <td><?php echo htmlspecialchars($r['note'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($r['created_by'] ?? ''); ?></td>
                <td style="white-space:nowrap;color:#8892a4"><?php echo htmlspecialchars(substr((string)$r['created_at'], 0, 16)); ?></td>
                <td>
                    <form method="POST" onsubmit="return confirm('Delete this adjustment (<?php echo htmlspecialchars(addslashes($r['item_code'])); ?>, <?php echo ia_n($a); ?>)? The stock reports will change.');" style="margin:0">
                        <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                        <button class="ia-b r sm" type="submit"><i class="fa-solid fa-trash"></i> Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" style="text-align:center;padding:30px;color:#8892a4;">No adjustments found.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<script>
$(function(){
    $('#adjItem').select2({placeholder:'Search item code or name…', allowClear:true, width:'100%'});
    $('#fltItems').select2({placeholder:'— All items —', allowClear:true, width:'100%'});
});
const IA_ROWS = <?php echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
async function iaExport(){
    const wb = new ExcelJS.Workbook(), ws = wb.addWorksheet('Item Adjustments');
    const h = ws.addRow(['Date','Item Code','Item Name','Adjustment (units)','Note','By','Saved at']);
    h.eachCell(c => { c.font = {bold:true}; c.fill = {type:'pattern', pattern:'solid', fgColor:{argb:'FFFBE5D6'}}; });
    IA_ROWS.forEach(r => ws.addRow([r.entry_date, r.item_code, r.item_name, +r.adjustment_qty, r.note || '', r.created_by || '', r.created_at]));
    ws.getColumn(4).numFmt = '#,##0.00'; ws.getColumn(3).width = 38; ws.getColumn(5).width = 32; ws.getColumn(1).width = 12; ws.getColumn(2).width = 14;
    const buf = await wb.xlsx.writeBuffer();
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([buf], {type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}));
    a.download = 'item_adjustments_' + new Date().toISOString().slice(0,10) + '.xlsx'; a.click(); URL.revokeObjectURL(a.href);
}
</script>
<?php include 'footer.php'; ?>
