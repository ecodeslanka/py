<?php
// ============================================================
//  sampath_payment_requests_sent.php  –  SENT PAYMENT REQUESTS
//
//  Every "Send to Payments" (sampath_payment_request.php) is listed here with its send date, remark,
//  invoice count and GRN total. Open a request to see its invoices, delete the whole request or single
//  invoices (they become available to send again) and generate the "Payments request Acknowledgement"
//  Excel (same layout as SST_Payments_request_summary.xlsx) for a whole request or for selected invoices.
// ============================================================
include 'config.php';
include_once 'auth.php';
requireLogin();
if (session_status() === PHP_SESSION_NONE) session_start();
mysqli_report(MYSQLI_REPORT_OFF);
$who = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'System';

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sampath_pay_requests (
    id INT AUTO_INCREMENT PRIMARY KEY, send_date DATE NOT NULL, remark VARCHAR(500) NULL,
    item_count INT NOT NULL DEFAULT 0, total_value DECIMAL(16,2) NOT NULL DEFAULT 0,
    created_by VARCHAR(100) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_send_date (send_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sampath_pay_request_items (
    id INT AUTO_INCREMENT PRIMARY KEY, request_id INT NOT NULL, bill_no VARCHAR(100) NOT NULL, bill_date DATE NULL,
    party_name VARCHAR(255) NULL, bill_value DECIMAL(16,2) DEFAULT 0, secondary_value DECIMAL(16,2) DEFAULT 0,
    act_delivery_date DATE NULL, grn_date DATE NULL, grn_date2 DATE NULL, grn_value DECIMAL(16,4) DEFAULT 0,
    UNIQUE KEY uq_bill (bill_no), INDEX idx_req (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function sprs_json($a) { while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
function sprs_recalc($conn, $rid) {
    $rid = (int)$rid;
    $t = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c, COALESCE(SUM(grn_value),0) v FROM sampath_pay_request_items WHERE request_id=$rid"));
    mysqli_query($conn, "UPDATE sampath_pay_requests SET item_count=" . (int)$t['c'] . ", total_value=" . (float)$t['v'] . " WHERE id=$rid");
}

/* ── JSON endpoints ── */
if (($_GET['ajax'] ?? '') === 'items') {
    $rid = (int)($_GET['id'] ?? 0);
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM sampath_pay_requests WHERE id=$rid"));
    if (!$r) sprs_json(['ok' => false, 'error' => 'Request not found']);
    $items = [];
    $q = mysqli_query($conn, "SELECT * FROM sampath_pay_request_items WHERE request_id=$rid ORDER BY bill_date, bill_no");
    if ($q) while ($x = mysqli_fetch_assoc($q)) $items[] = $x;
    sprs_json(['ok' => true, 'request' => $r, 'items' => $items]);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];
    if ($act === 'delete_request') {
        $rid = (int)($_POST['id'] ?? 0);
        mysqli_query($conn, "DELETE FROM sampath_pay_request_items WHERE request_id=$rid");
        mysqli_query($conn, "DELETE FROM sampath_pay_requests WHERE id=$rid");
        sprs_json(['ok' => true]);
    }
    if ($act === 'delete_items') {
        $rid = (int)($_POST['id'] ?? 0);
        $ids = array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')));
        if (!$ids) sprs_json(['ok' => false, 'error' => 'Nothing selected']);
        mysqli_query($conn, "DELETE FROM sampath_pay_request_items WHERE request_id=$rid AND id IN (" . implode(',', $ids) . ")");
        sprs_recalc($conn, $rid);
        $left = mysqli_fetch_assoc(mysqli_query($conn, "SELECT item_count FROM sampath_pay_requests WHERE id=$rid"));
        if ($left && (int)$left['item_count'] === 0) mysqli_query($conn, "DELETE FROM sampath_pay_requests WHERE id=$rid");
        sprs_json(['ok' => true]);
    }
    if ($act === 'edit_request') {
        $rid = (int)($_POST['id'] ?? 0);
        $sd = $_POST['send_date'] ?? ''; $rm = trim($_POST['remark'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sd)) sprs_json(['ok' => false, 'error' => 'Invalid date']);
        $st = mysqli_prepare($conn, "UPDATE sampath_pay_requests SET send_date=?, remark=? WHERE id=?");
        mysqli_stmt_bind_param($st, 'ssi', $sd, $rm, $rid); mysqli_stmt_execute($st);
        sprs_json(['ok' => true]);
    }
    sprs_json(['ok' => false, 'error' => 'Unknown action']);
}

$reqs = [];
$q = mysqli_query($conn, "SELECT * FROM sampath_pay_requests ORDER BY send_date DESC, id DESC");
if ($q) while ($r = mysqli_fetch_assoc($q)) $reqs[] = $r;
$open  = (int)($_GET['open'] ?? 0);
$flash = $_SESSION['spr_flash'] ?? null; unset($_SESSION['spr_flash']);
include 'header.php';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.4.0/exceljs.min.js"></script>
<style>
.sps{padding:0 4px;} .sps h2{font-size:22px;font-weight:800;margin:0 0 4px;} .sps .sub{font-size:12px;color:#8892a4;margin-bottom:14px;}
.sps-card{background:#fff;border:1.5px solid #e2e6f0;border-radius:14px;margin-bottom:12px;overflow:hidden;}
.sps-h{display:flex;gap:14px;flex-wrap:wrap;align-items:center;padding:12px 16px;}
.sps-h .t{font-weight:800;font-size:14px;} .sps-h .m{font-size:12px;color:#64748b;} .sps-h .rm{flex:1 1 200px;font-size:12.5px;color:#334155;}
.sps-b{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border:none;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;background:#0a0c14;color:#fff;text-decoration:none;}
.sps-b.g{background:#14532d;} .sps-b.r{background:#b91c1c;} .sps-b.l{background:#f7f8fc;color:#475569;border:1.5px solid #e2e6f0;} .sps-b[disabled]{opacity:.45;cursor:not-allowed;}
.sps-body{display:none;border-top:1px solid #eef0f6;padding:12px 16px;} .sps-card.open .sps-body{display:block;}
.sps-wrap{overflow:auto;max-height:55vh;border:1.5px solid #e2e6f0;border-radius:10px;}
table.sps-t{width:100%;border-collapse:collapse;font-size:12.5px;}
table.sps-t th{position:sticky;top:0;background:#0a0c14;color:#fff;font-size:10.5px;text-transform:uppercase;padding:7px 9px;text-align:left;white-space:nowrap;}
table.sps-t td{padding:6px 9px;border-bottom:1px solid #eef0f6;white-space:nowrap;} table.sps-t .n{text-align:right;font-variant-numeric:tabular-nums;}
.sps-msg{border-radius:8px;padding:10px 16px;margin-bottom:12px;font-size:13px;background:#dcfce7;border:1px solid #86efac;color:#166534;}
.sps-in{border:1.5px solid #e2e6f0;border-radius:8px;padding:7px 10px;font-size:13px;}
@media (max-width:720px){.sps-h{gap:8px}}
</style>
<div class="sps">
  <h2><i class="fa-solid fa-paper-plane"></i> Sent Payment Requests</h2>
  <div class="sub">Requests sent from <a href="sampath_payment_request.php">Send to Payments</a>. Open one to view its invoices, delete, or generate the acknowledgement Excel.</div>
  <?php if ($flash): ?><div class="sps-msg"><?php echo htmlspecialchars($flash[1]); ?></div><?php endif; ?>

  <?php if (!$reqs): ?>
    <div class="sps-card"><div style="padding:30px;text-align:center;color:#94a3b8;">Nothing sent yet. <a href="sampath_payment_request.php">Send invoices to payments</a>.</div></div>
  <?php endif; ?>

  <?php foreach ($reqs as $r): $id = (int)$r['id']; ?>
  <div class="sps-card <?php echo $open === $id ? 'open' : ''; ?>" id="req<?php echo $id; ?>" data-id="<?php echo $id; ?>" data-date="<?php echo htmlspecialchars($r['send_date']); ?>" data-remark="<?php echo htmlspecialchars($r['remark'] ?? '', ENT_QUOTES); ?>">
    <div class="sps-h">
      <div><div class="t">Request #<?php echo $id; ?> · <?php echo date('d/m/Y', strtotime($r['send_date'])); ?></div>
           <div class="m"><?php echo (int)$r['item_count']; ?> invoice(s) · GRN <?php echo number_format((float)$r['total_value'], 2); ?> · by <?php echo htmlspecialchars($r['created_by'] ?? ''); ?></div></div>
      <div class="rm"><?php echo $r['remark'] !== null && $r['remark'] !== '' ? htmlspecialchars($r['remark']) : '<span style="color:#94a3b8;">No remark</span>'; ?></div>
      <button class="sps-b l" onclick="spsToggle(<?php echo $id; ?>)"><i class="fa-solid fa-eye"></i> View</button>
      <button class="sps-b g" onclick="spsExcel(<?php echo $id; ?>, false)"><i class="fa-solid fa-file-excel"></i> Excel</button>
      <button class="sps-b l" onclick="spsEdit(<?php echo $id; ?>)"><i class="fa-solid fa-pen"></i></button>
      <button class="sps-b r" onclick="spsDelReq(<?php echo $id; ?>)"><i class="fa-solid fa-trash"></i> Delete</button>
    </div>
    <div class="sps-body" id="body<?php echo $id; ?>"><div style="color:#94a3b8;">Loading…</div></div>
  </div>
  <?php endforeach; ?>
</div>

<script>
const OPEN_ID = <?php echo $open; ?>;
const cache = {};
const esc = t => String(t == null ? '' : t).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const fmt = n => (isNaN(n) ? 0 : +n).toLocaleString('en-LK', {minimumFractionDigits:2, maximumFractionDigits:2});
const fd  = s => s ? s.substring(8, 10) + '/' + s.substring(5, 7) + '/' + s.substring(0, 4) : '—';
function post(data){ const f = new FormData(); Object.keys(data).forEach(k => f.append(k, data[k])); return fetch('sampath_payment_requests_sent.php', {method:'POST', body:f, credentials:'same-origin'}).then(r => r.json()); }

function spsToggle(id){
    const c = document.getElementById('req' + id);
    c.classList.toggle('open');
    if (c.classList.contains('open')) spsLoad(id);
}
function spsLoad(id){
    const b = document.getElementById('body' + id);
    fetch('sampath_payment_requests_sent.php?ajax=items&id=' + id, {credentials:'same-origin'}).then(r => r.json()).then(d => {
        if (!d.ok) { b.innerHTML = '<span style="color:#b91c1c;">' + esc(d.error) + '</span>'; return; }
        cache[id] = d;
        let h = '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;align-items:center;">'
              + '<button class="sps-b g" onclick="spsExcel(' + id + ', true)"><i class="fa-solid fa-file-excel"></i> Excel (selected)</button>'
              + '<button class="sps-b r" onclick="spsDelItems(' + id + ')"><i class="fa-solid fa-trash"></i> Remove selected</button>'
              + '<span style="font-size:12px;color:#64748b;">Tick invoices to generate the Excel from them, or to remove them from this request.</span></div>'
              + '<div class="sps-wrap"><table class="sps-t"><thead><tr><th><input type="checkbox" onclick="document.querySelectorAll(\'#body' + id + ' .ic\').forEach(c=>c.checked=this.checked)"></th>'
              + '<th>Bill Number</th><th>Bill Date</th><th>Party Name</th><th class="n">Bill Mention</th><th class="n">Secondary Final</th><th>ACT Delivery</th><th>GRN Date</th><th>GRN Date 2</th><th class="n">GRN Value</th><th>Sched. Chq</th></tr></thead><tbody>';
        d.items.forEach(i => {
            const sc = i.grn_date ? fd(new Date(Date.UTC(+i.grn_date.substr(0,4), +i.grn_date.substr(5,2)-1, +i.grn_date.substr(8,2)+21)).toISOString().substr(0,10)) : '—';
            h += '<tr><td><input type="checkbox" class="ic" value="' + i.id + '"></td><td><b>' + esc(i.bill_no) + '</b></td><td>' + fd(i.bill_date) + '</td><td>' + esc(i.party_name) + '</td>'
               + '<td class="n">' + fmt(i.bill_value) + '</td><td class="n">' + fmt(i.secondary_value) + '</td><td>' + fd(i.act_delivery_date) + '</td><td>' + fd(i.grn_date) + '</td><td>' + fd(i.grn_date2) + '</td>'
               + '<td class="n">' + fmt(i.grn_value) + '</td><td>' + sc + '</td></tr>';
        });
        b.innerHTML = h + '</tbody></table></div>';
    });
}
function spsSelected(id){ return Array.from(document.querySelectorAll('#body' + id + ' .ic:checked')).map(c => c.value); }
function spsDelItems(id){
    const ids = spsSelected(id);
    if (!ids.length) { alert('Tick the invoices to remove.'); return; }
    if (!confirm('Remove ' + ids.length + ' invoice(s) from this request? They can be sent again afterwards.')) return;
    post({action:'delete_items', id:id, ids:ids.join(',')}).then(d => { if (!d.ok) alert(d.error); else location.href = 'sampath_payment_requests_sent.php' + (document.getElementById('req' + id) ? '?open=' + id : ''); });
}
function spsDelReq(id){
    if (!confirm('Delete request #' + id + ' and all its invoices? The invoices can be sent again afterwards.')) return;
    post({action:'delete_request', id:id}).then(d => { if (!d.ok) alert(d.error); else location.href = 'sampath_payment_requests_sent.php'; });
}
function spsEdit(id){
    const c = document.getElementById('req' + id);
    const nd = prompt('Send date (YYYY-MM-DD):', c.dataset.date); if (nd === null) return;
    const nr = prompt('Remark:', c.dataset.remark); if (nr === null) return;
    post({action:'edit_request', id:id, send_date:nd.trim(), remark:nr}).then(d => { if (!d.ok) alert(d.error); else location.href = 'sampath_payment_requests_sent.php?open=' + id; });
}

/* ── Excel: same layout as SST_Payments_request_summary.xlsx ── */
async function spsExcel(id, onlySelected){
    if (typeof ExcelJS === 'undefined') { alert('Excel library still loading, try again in a moment.'); return; }
    if (!cache[id]) {
        const d = await fetch('sampath_payment_requests_sent.php?ajax=items&id=' + id, {credentials:'same-origin'}).then(r => r.json());
        if (!d.ok) { alert(d.error); return; }
        cache[id] = d;
    }
    let items = cache[id].items;
    if (onlySelected) {
        const sel = new Set(spsSelected(id));
        if (!sel.size) { alert('Tick at least one invoice.'); return; }
        items = items.filter(i => sel.has(String(i.id)));
    }
    buildPaymentExcel(items, cache[id].request.send_date, 'SST_Payments_request_summary_' + cache[id].request.send_date + '_#' + id + '.xlsx');
}
function utc(s){ return s ? new Date(Date.UTC(+s.substr(0,4), +s.substr(5,2)-1, +s.substr(8,2))) : null; }
async function buildPaymentExcel(items, sendDate, filename){
    const wb = new ExcelJS.Workbook();
    const ws = wb.addWorksheet('Sheet1', {pageSetup:{orientation:'landscape', fitToPage:true, fitToWidth:1, fitToHeight:0}});
    const FONT = (o) => Object.assign({name:'Courier New', size:9}, o || {});
    const thin = {style:'thin'}, BORD = {top:thin, left:thin, bottom:thin, right:thin};
    const ACC = '_(* #,##0.00_);_(* \\(#,##0.00\\);_(* "-"??_);_(@_)';
    [24.09, 10.82, 41.91, 14.91, 14.91, 10.82, 10.82, 6.82, 15.91, 10.82].forEach((w, i) => ws.getColumn(i + 1).width = w);

    ws.getCell('A1').value = 'Yelo Distributors Pvt Ltd';           ws.getCell('A1').font = FONT({size:16, bold:true});
    ws.getCell('A2').value = 'Payments request Acknowledgement';    ws.getCell('A2').font = FONT({size:12, bold:true});
    ws.getRow(1).height = 21.5; ws.getRow(2).height = 16; ws.getRow(3).height = 48;

    const HEAD = ['Bill Number','Bill Date','Party Name','Bill Mention value','Secondary Final Bill Amount(To be collected)','ACT Delivery Date','GRN date SST','GRN date 2 SST','GRN Value','Scheduled cheque date'];
    HEAD.forEach((t, i) => {
        const c = ws.getCell(3, i + 1);
        c.value = t; c.font = FONT({color:{argb:'FFFFFFFF'}});
        c.fill = {type:'pattern', pattern:'solid', fgColor:{argb:'FF002060'}};
        c.alignment = {wrapText:true, vertical:'middle', horizontal: i === 0 ? 'left' : undefined};
        c.border = BORD;
    });

    let r = 4;
    items.forEach(it => {
        const row = ws.getRow(r);
        const put = (col, v, fmtStr, align) => {
            const c = row.getCell(col);
            c.value = v; c.font = FONT(); c.border = BORD;
            c.alignment = {vertical:'center', horizontal: align};
            if (fmtStr) c.numFmt = fmtStr;
            return c;
        };
        put(1, it.bill_no, null, 'left');
        put(2, utc(it.bill_date), 'dd/mm/yyyy');
        put(3, it.party_name || '');
        put(4, +it.bill_value, ACC);
        put(5, +it.secondary_value, ACC);
        put(6, utc(it.act_delivery_date), 'mm-dd-yy');
        put(7, utc(it.grn_date), 'mm-dd-yy');
        put(8, utc(it.grn_date2), 'mm-dd-yy');
        put(9, +it.grn_value, ACC);
        if (it.grn_date) {
            const g = utc(it.grn_date); const res = new Date(g.getTime() + 21 * 86400000);
            put(10, {formula:'G' + r + '+21', result:res}, 'mm-dd-yy');
        } else put(10, null, 'mm-dd-yy');
        r++;
    });
    const last = r - 1, tot = r;
    const tc = ws.getCell(tot, 9);
    tc.value = {formula:'SUM(I4:I' + last + ')', result: items.reduce((a, i) => a + (+i.grn_value || 0), 0)};
    tc.font = FONT({bold:true}); tc.numFmt = ACC; tc.border = {top:thin, bottom:{style:'double'}};
    ws.getRow(tot).height = 15;

    const L = last;   // template: last data row 19 -> 22 / 24 / 25 / 26 / 27 / 28
    ws.getCell('A' + (L + 3)).value = 'Amended TAX invoices';   ws.getCell('A' + (L + 3)).font = FONT();
    ws.getCell('A' + (L + 3)).alignment = {vertical:'middle', horizontal:'left'};
    ws.getCell('A' + (L + 5)).value = 'Operation Manager stamp'; ws.getCell('A' + (L + 5)).font = FONT();
    ws.getCell('A' + (L + 5)).alignment = {horizontal:'left'};
    const dd = {style:'dashDot'};
    ws.mergeCells('F' + (L + 6) + ':I' + (L + 6));
    ws.mergeCells('F' + (L + 7) + ':I' + (L + 7));
    ws.mergeCells('A' + (L + 8) + ':B' + (L + 8));
    ['F', 'G', 'H', 'I'].forEach(col => ws.getCell(col + (L + 6)).border = {top:dd});
    ws.getCell('F' + (L + 6)).value = 'All the invoices received correctly';
    ws.getCell('F' + (L + 7)).value = '(Officer in charge - SST)';
    [['F', L + 6], ['F', L + 7]].forEach(([c, rr]) => { const x = ws.getCell(c + rr); x.font = FONT(); x.alignment = {vertical:'middle', horizontal:'center'}; });
    ws.getCell('A' + (L + 7)).border = {bottom:dd};
    ws.getCell('A' + (L + 8)).value = 'Signature (Operation Manager)';
    ws.getCell('A' + (L + 8)).font = FONT(); ws.getCell('A' + (L + 8)).alignment = {horizontal:'center'};
    ws.getCell('A' + (L + 8)).border = {top:dd}; ws.getCell('B' + (L + 8)).border = {top:dd};
    ws.getCell('F' + (L + 8)).value = 'Date -:'; ws.getCell('F' + (L + 8)).font = FONT({size:11});
    const dc = ws.getCell('A' + (L + 9));
    dc.value = utc(sendDate); dc.numFmt = 'mm-dd-yy'; dc.font = FONT(); dc.alignment = {vertical:'middle', horizontal:'right'};

    const buf = await wb.xlsx.writeBuffer();
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([buf], {type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}));
    a.download = filename; document.body.appendChild(a); a.click(); a.remove();
}
if (OPEN_ID) spsLoad(OPEN_ID);
</script>
