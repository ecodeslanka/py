<?php
// ============================================================
//  sampath_payment_request.php  –  SEND TO PAYMENTS
//
//  Lists the Sampath Age Analysis invoices (same data rules: secondary invoices, GRN value / dates with
//  manual GRN links applied, ACT delivery date, cheque status). Select invoices (one by one or in bulk),
//  choose the SEND DATE, add a remark and press "Send to Payments". Each send is saved as one request
//  (sampath_pay_requests + sampath_pay_request_items, a snapshot of the figures at send time) and can be
//  viewed / deleted / turned into the "Payments request Acknowledgement" Excel on
//  sampath_payment_requests_sent.php.
//
//  An invoice can be in only one request at a time; delete it from the request to send it again.
// ============================================================
ob_start();
include 'config.php';
include_once 'auth.php';
requireLogin();
if (session_status() === PHP_SESSION_NONE) session_start();
mysqli_report(MYSQLI_REPORT_OFF);
$who = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'System';
$e   = function ($v) use ($conn) { return mysqli_real_escape_string($conn, (string)$v); };

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sampath_pay_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    send_date DATE NOT NULL,
    remark VARCHAR(500) NULL,
    item_count INT NOT NULL DEFAULT 0,
    total_value DECIMAL(16,2) NOT NULL DEFAULT 0,
    created_by VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_send_date (send_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sampath_pay_request_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    bill_no VARCHAR(100) NOT NULL,
    bill_date DATE NULL,
    party_name VARCHAR(255) NULL,
    bill_value DECIMAL(16,2) DEFAULT 0,
    secondary_value DECIMAL(16,2) DEFAULT 0,
    act_delivery_date DATE NULL,
    grn_date DATE NULL,
    grn_date2 DATE NULL,
    grn_value DECIMAL(16,4) DEFAULT 0,
    UNIQUE KEY uq_bill (bill_no),
    INDEX idx_req (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sampath_age_manual_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bill_no VARCHAR(100) NOT NULL,
    act_delivery_date DATE NULL,
    doc_submit_date DATE NULL,
    chq_clearing_date DATE NULL,
    chq_clearing_account VARCHAR(150) NULL,
    updated_by VARCHAR(100) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bill_no (bill_no),
    INDEX idx_bill_no (bill_no)
)");
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

/* GRN date column on sampath_grn_summary (same detection as the Age Analysis report) */
$grn_date_col = null;
$cr = mysqli_query($conn, "SHOW COLUMNS FROM sampath_grn_summary");
if ($cr) {
    $by_name = []; $by_type = [];
    while ($c = mysqli_fetch_assoc($cr)) {
        if (in_array($c['Field'], ['created_at', 'updated_at'], true)) continue;
        if (stripos($c['Field'], 'date') !== false) $by_name[] = $c['Field'];
        if (stripos($c['Type'], 'date') !== false || stripos($c['Type'], 'timestamp') !== false) $by_type[] = $c['Field'];
    }
    foreach (['grn_date', 'grn_dt', 'date'] as $p) if (in_array($p, $by_name, true)) { $grn_date_col = $p; break; }
    if (!$grn_date_col && $by_name) $grn_date_col = $by_name[0];
    if (!$grn_date_col && $by_type) $grn_date_col = $by_type[0];
}

/** Age Analysis invoices. $bills = exact bill list (ignores date / show filters). */
function spr_rows($conn, $from, $to, $search, $show, $chq, $grn_date_col, $bills = null) {
    $esc  = function ($v) use ($conn) { return mysqli_real_escape_string($conn, (string)$v); };
    $gd   = $grn_date_col ? "NULLIF(s.`$grn_date_col`,'0000-00-00')" : "NULL";
    $where = "d.final_bill_amount IS NOT NULL AND d.final_bill_amount <> 0";
    if ($bills !== null) {
        $in = implode(',', array_map(function ($b) use ($esc) { return "'" . $esc($b) . "'"; }, $bills));
        $where .= " AND d.bill_no IN ($in)";
    } else {
        $where .= " AND d.bill_date BETWEEN '" . $esc($from) . "' AND '" . $esc($to) . "'";
        if ($search !== '') {
            $s = $esc($search);
            $where .= " AND (d.bill_no LIKE '%$s%' OR d.party_name LIKE '%$s%' OR d.t_code LIKE '%$s%')";
        }
        if ($show === 'notsent') $where .= " AND pri.id IS NULL";
        if ($show === 'sent')    $where .= " AND pri.id IS NOT NULL";
        if ($chq === 'unsettled') $where .= " AND pay.cheque_date IS NULL";
    }
    $sql = "
    SELECT d.bill_no,
           MAX(d.bill_date) AS bill_date,
           MAX(COALESCE(NULLIF(d.party_name,''), d.customer_name)) AS party_name,
           MAX(d.bill_value) AS bill_value,
           MAX(d.final_bill_amount) AS secondary_value,
           MAX(ame.act_delivery_date) AS act_delivery_date,
           MAX(COALESCE(sg.grn_value,0)) AS grn_value,
           MAX(sg.d1) AS grn_date, MAX(sg.d2) AS grn_date2,
           MAX(pay.cheque_date) AS cheque_date,
           MAX(pri.request_id) AS sent_req
    FROM secondary_invoice_import_details d
    JOIN secondary_invoice_imports i ON i.id = d.import_id
    LEFT JOIN (
        SELECT e.eff_bill AS ref_no, SUM(e.grn_value) AS grn_value, MIN(e.gdt) AS d1, MAX(e.gdt) AS d2
        FROM (
            SELECT s.grn_value, $gd AS gdt,
                   CASE WHEN l.link_type='link' THEN l.bill_no COLLATE utf8mb4_general_ci
                        WHEN l.link_type='exclude' THEN NULL
                        ELSE s.ref_no COLLATE utf8mb4_general_ci END AS eff_bill
            FROM sampath_grn_summary s
            LEFT JOIN sampath_grn_invoice_links l ON l.grn_summary_id = s.id
        ) e WHERE e.eff_bill IS NOT NULL GROUP BY e.eff_bill
    ) sg ON sg.ref_no COLLATE utf8mb4_general_ci = d.bill_no COLLATE utf8mb4_general_ci
    LEFT JOIN (
        SELECT spi.bill_no, MAX(sp.cheque_date) AS cheque_date
        FROM sampath_super_payment_invoices spi
        JOIN sampath_super_payments sp ON sp.id = spi.payment_id
        GROUP BY spi.bill_no
    ) pay ON pay.bill_no COLLATE utf8mb4_general_ci = d.bill_no COLLATE utf8mb4_general_ci
    LEFT JOIN sampath_age_manual_entries ame ON ame.bill_no COLLATE utf8mb4_general_ci = d.bill_no COLLATE utf8mb4_general_ci
    LEFT JOIN sampath_pay_request_items pri ON pri.bill_no COLLATE utf8mb4_general_ci = d.bill_no COLLATE utf8mb4_general_ci
    WHERE $where
    GROUP BY d.bill_no
    ORDER BY bill_date ASC, d.bill_no ASC";
    $out = []; $res = mysqli_query($conn, $sql);
    if ($res) while ($r = mysqli_fetch_assoc($res)) {
        if (!empty($r['grn_date2']) && $r['grn_date2'] === $r['grn_date']) $r['grn_date2'] = null;
        $out[] = $r;
    }
    return [$out, $res ? '' : mysqli_error($conn)];
}

/* ── SEND ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    $bills = json_decode($_POST['bills'] ?? '[]', true);
    $bills = is_array($bills) ? array_values(array_unique(array_filter(array_map('trim', array_map('strval', $bills)), 'strlen'))) : [];
    $sd    = $_POST['send_date'] ?? '';
    $remark = trim($_POST['remark'] ?? '');
    if (!$bills || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $sd) || !checkdate((int)substr($sd, 5, 2), (int)substr($sd, 8, 2), (int)substr($sd, 0, 4))) {
        $_SESSION['spr_flash'] = ['err', 'Select at least one invoice and a valid send date.'];
        header('Location: sampath_payment_request.php'); exit;
    }
    [$rows, $err] = spr_rows($conn, '', '', '', 'all', 'all', $grn_date_col, $bills);
    $rows = array_values(array_filter($rows, function ($r) { return empty($r['sent_req']); }));
    $skipped = count($bills) - count($rows);
    if (!$rows) {
        $_SESSION['spr_flash'] = ['err', 'None of the selected invoices can be sent (already sent or not found).' . ($err ? " $err" : '')];
        header('Location: sampath_payment_request.php'); exit;
    }
    $tot = 0; foreach ($rows as $r) $tot += (float)$r['grn_value'];
    $n = count($rows);
    $st = mysqli_prepare($conn, "INSERT INTO sampath_pay_requests (send_date, remark, item_count, total_value, created_by) VALUES (?,?,?,?,?)");
    mysqli_stmt_bind_param($st, 'ssids', $sd, $remark, $n, $tot, $who);
    mysqli_stmt_execute($st);
    $rid = mysqli_insert_id($conn);
    $ins = mysqli_prepare($conn, "INSERT IGNORE INTO sampath_pay_request_items
        (request_id, bill_no, bill_date, party_name, bill_value, secondary_value, act_delivery_date, grn_date, grn_date2, grn_value)
        VALUES (?,?,?,?,?,?,?,?,?,?)");
    foreach ($rows as $r) {
        $bv = (float)$r['bill_value']; $sv = (float)$r['secondary_value']; $gv = (float)$r['grn_value'];
        $bd = $r['bill_date'] ?: null; $ad = $r['act_delivery_date'] ?: null; $g1 = $r['grn_date'] ?: null; $g2 = $r['grn_date2'] ?: null;
        mysqli_stmt_bind_param($ins, 'isssddsssd', $rid, $r['bill_no'], $bd, $r['party_name'], $bv, $sv, $ad, $g1, $g2, $gv);
        mysqli_stmt_execute($ins);
    }
    $_SESSION['spr_flash'] = ['ok', "$n invoice(s) sent to payments" . ($skipped > 0 ? " ($skipped skipped – already sent)" : '') . '.'];
    header('Location: sampath_payment_requests_sent.php?open=' . $rid); exit;
}

$today = date('Y-m-d');
$from  = $_GET['from'] ?? date('Y-m-d', strtotime('-60 days'));
$to    = $_GET['to']   ?? $today;
$q     = trim($_GET['q'] ?? '');
$show  = in_array($_GET['show'] ?? '', ['notsent', 'sent', 'all'], true) ? $_GET['show'] : 'notsent';
$chq   = in_array($_GET['chq'] ?? '', ['all', 'unsettled'], true) ? $_GET['chq'] : 'all';
[$rows, $qerr] = spr_rows($conn, $from, $to, $q, $show, $chq, $grn_date_col);
$flash = $_SESSION['spr_flash'] ?? null; unset($_SESSION['spr_flash']);
function spr_n($v) { return number_format((float)$v, 2); }
function spr_d($v) { return $v ? date('d/m/Y', strtotime($v)) : '—'; }

include 'header.php';
?>
<style>
.spr{padding:0 4px;} .spr h2{font-size:22px;font-weight:800;margin:0 0 4px;} .spr .sub{font-size:12px;color:#8892a4;margin-bottom:14px;}
.spr-card{background:#fff;border:1.5px solid #e2e6f0;border-radius:14px;padding:14px 18px;margin-bottom:16px;}
.spr-f{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;}
.spr-f label{display:block;font-size:10.5px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;}
.spr-in{border:1.5px solid #e2e6f0;border-radius:8px;padding:8px 10px;font-size:13px;max-width:100%;}
.spr-b{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;background:#0a0c14;color:#fff;text-decoration:none;}
.spr-b.g{background:#14532d;} .spr-b.l{background:#f7f8fc;color:#475569;border:1.5px solid #e2e6f0;} .spr-b[disabled]{opacity:.45;cursor:not-allowed;}
.spr-msg{border-radius:8px;padding:10px 16px;margin-bottom:12px;font-size:13px;} .spr-msg.ok{background:#dcfce7;border:1px solid #86efac;color:#166534;} .spr-msg.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;}
.spr-wrap{overflow:auto;max-height:68vh;border:1.5px solid #e2e6f0;border-radius:12px;}
table.spr-t{width:100%;border-collapse:collapse;font-size:12.5px;}
table.spr-t th{position:sticky;top:0;background:#0a0c14;color:#fff;font-size:10.5px;text-transform:uppercase;padding:8px 10px;text-align:left;white-space:nowrap;z-index:1;}
table.spr-t td{padding:6px 10px;border-bottom:1px solid #eef0f6;white-space:nowrap;} table.spr-t td.n,table.spr-t th.n{text-align:right;font-variant-numeric:tabular-nums;}
table.spr-t tr.sent td{background:#f8fafc;color:#94a3b8;} table.spr-t tr.sel td{background:#eff6ff;}
.pill{display:inline-block;padding:2px 9px;border-radius:11px;font-size:10.5px;font-weight:700;} .pill.ok{background:#dcfce7;color:#166534;} .pill.pe{background:#fef3c7;color:#92400e;}
.spr-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px;font-size:13px;}
.spr-modal{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;align-items:center;justify-content:center;z-index:9999;padding:16px;} .spr-modal.open{display:flex;}
.spr-mb{background:#fff;border-radius:12px;width:100%;max-width:460px;padding:20px;box-shadow:0 20px 50px rgba(0,0,0,.25);}
.spr-mb h3{margin:0 0 12px;font-size:16px;} .spr-mb .fld{margin-bottom:12px;} .spr-mb .fld label{display:block;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:4px;} .spr-mb .fld .spr-in{width:100%;box-sizing:border-box;}
@media (max-width:720px){.spr-f>div{flex:1 1 140px}.spr-b{justify-content:center}}
</style>
<div class="spr">
  <h2><i class="fa-solid fa-paper-plane"></i> Send to Payments</h2>
  <div class="sub">Pick Age Analysis invoices, add the send date and a remark, then send. Sent requests are on <a href="sampath_payment_requests_sent.php">Sent Payment Requests</a>, where the acknowledgement Excel is generated.</div>

  <?php if ($flash): ?><div class="spr-msg <?php echo $flash[0]; ?>"><?php echo htmlspecialchars($flash[1]); ?></div><?php endif; ?>
  <?php if ($qerr): ?><div class="spr-msg err">Query error: <?php echo htmlspecialchars($qerr); ?></div><?php endif; ?>

  <form method="get" class="spr-card">
    <div class="spr-f">
      <div><label>Bill date from</label><input class="spr-in" type="date" name="from" value="<?php echo htmlspecialchars($from); ?>"></div>
      <div><label>to</label><input class="spr-in" type="date" name="to" value="<?php echo htmlspecialchars($to); ?>"></div>
      <div><label>Search</label><input class="spr-in" type="text" name="q" placeholder="Bill no / party / T-code" value="<?php echo htmlspecialchars($q); ?>"></div>
      <div><label>Request status</label>
        <select class="spr-in" name="show">
          <option value="notsent" <?php echo $show === 'notsent' ? 'selected' : ''; ?>>Not sent yet</option>
          <option value="sent" <?php echo $show === 'sent' ? 'selected' : ''; ?>>Already sent</option>
          <option value="all" <?php echo $show === 'all' ? 'selected' : ''; ?>>All</option>
        </select></div>
      <div><label>Cheque</label>
        <select class="spr-in" name="chq">
          <option value="all" <?php echo $chq === 'all' ? 'selected' : ''; ?>>All</option>
          <option value="unsettled" <?php echo $chq === 'unsettled' ? 'selected' : ''; ?>>Unsettled (no cheque date)</option>
        </select></div>
      <div><button class="spr-b" type="submit"><i class="fa-solid fa-filter"></i> Filter</button></div>
    </div>
  </form>

  <div class="spr-bar">
    <button type="button" class="spr-b l" onclick="sprAll(true)"><i class="fa-solid fa-check-double"></i> Select all</button>
    <button type="button" class="spr-b l" onclick="sprAll(false)">Clear</button>
    <span><b id="sprCnt">0</b> selected · GRN value <b id="sprVal">0.00</b></span>
    <span style="color:#6b7280;"><?php echo count($rows); ?> invoice(s) listed</span>
    <button type="button" class="spr-b g" id="sprSendBtn" onclick="sprOpen()" disabled style="margin-left:auto;"><i class="fa-solid fa-paper-plane"></i> Send selected to Payments</button>
  </div>

  <div class="spr-wrap">
  <table class="spr-t">
    <thead><tr>
      <th><input type="checkbox" id="sprHead" onclick="sprAll(this.checked)"></th>
      <th>Bill Number</th><th>Bill Date</th><th>Party Name</th>
      <th class="n">Bill Mention Value</th><th class="n">Secondary Final Bill</th>
      <th>ACT Delivery</th><th>GRN Date</th><th>GRN Date 2</th><th class="n">GRN Value</th><th>Sched. Chq (GRN+21)</th><th>Cheque</th><th>Request</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $sent = !empty($r['sent_req']); ?>
      <tr class="<?php echo $sent ? 'sent' : ''; ?>" data-bill="<?php echo htmlspecialchars($r['bill_no'], ENT_QUOTES); ?>" data-val="<?php echo (float)$r['grn_value']; ?>">
        <td><?php if (!$sent): ?><input type="checkbox" class="spr-cb" onchange="sprUpd()"><?php endif; ?></td>
        <td><b><?php echo htmlspecialchars($r['bill_no']); ?></b></td>
        <td><?php echo spr_d($r['bill_date']); ?></td>
        <td><?php echo htmlspecialchars($r['party_name'] ?? ''); ?></td>
        <td class="n"><?php echo spr_n($r['bill_value']); ?></td>
        <td class="n"><?php echo spr_n($r['secondary_value']); ?></td>
        <td><?php echo spr_d($r['act_delivery_date']); ?></td>
        <td><?php echo spr_d($r['grn_date']); ?></td>
        <td><?php echo spr_d($r['grn_date2']); ?></td>
        <td class="n"><?php echo spr_n($r['grn_value']); ?></td>
        <td><?php echo $r['grn_date'] ? date('d/m/Y', strtotime($r['grn_date'] . ' +21 days')) : '—'; ?></td>
        <td><?php echo $r['cheque_date'] ? '<span class="pill ok">' . spr_d($r['cheque_date']) . '</span>' : '<span class="pill pe">Pending</span>'; ?></td>
        <td><?php echo $sent ? '<a class="pill ok" href="sampath_payment_requests_sent.php?open=' . (int)$r['sent_req'] . '">Sent #' . (int)$r['sent_req'] . '</a>' : '—'; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="13" style="text-align:center;padding:30px;color:#94a3b8;">No invoices for this filter.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="spr-modal" id="sprModal" onclick="if(event.target===this)this.classList.remove('open')">
  <form class="spr-mb" method="post" onsubmit="return sprSubmit(this)">
    <h3><i class="fa-solid fa-paper-plane"></i> Send to Payments</h3>
    <input type="hidden" name="action" value="send">
    <input type="hidden" name="bills" id="sprBills">
    <div id="sprSummary" style="font-size:13px;color:#475569;margin-bottom:12px;"></div>
    <div class="fld"><label>Send date</label><input class="spr-in" type="date" name="send_date" value="<?php echo $today; ?>" required></div>
    <div class="fld"><label>Remark</label><textarea class="spr-in" name="remark" rows="3" maxlength="500" placeholder="Optional remark"></textarea></div>
    <div style="display:flex;gap:8px;justify-content:flex-end;">
      <button type="button" class="spr-b l" onclick="document.getElementById('sprModal').classList.remove('open')">Cancel</button>
      <button type="submit" class="spr-b g"><i class="fa-solid fa-paper-plane"></i> Send</button>
    </div>
  </form>
</div>
<script>
function sprCbs(){ return Array.from(document.querySelectorAll('.spr-cb')); }
function sprAll(on){ sprCbs().forEach(c => c.checked = on); const h = document.getElementById('sprHead'); if (h) h.checked = on; sprUpd(); }
function sprUpd(){
    let n = 0, v = 0;
    sprCbs().forEach(c => { const tr = c.closest('tr'); tr.classList.toggle('sel', c.checked); if (c.checked) { n++; v += parseFloat(tr.dataset.val) || 0; } });
    document.getElementById('sprCnt').textContent = n;
    document.getElementById('sprVal').textContent = v.toLocaleString('en-LK', {minimumFractionDigits:2, maximumFractionDigits:2});
    document.getElementById('sprSendBtn').disabled = n === 0;
}
function sprOpen(){
    const sel = sprCbs().filter(c => c.checked).map(c => c.closest('tr').dataset.bill);
    if (!sel.length) return;
    document.getElementById('sprBills').value = JSON.stringify(sel);
    document.getElementById('sprSummary').innerHTML = '<b>' + sel.length + '</b> invoice(s) selected, GRN value <b>' + document.getElementById('sprVal').textContent + '</b>.';
    document.getElementById('sprModal').classList.add('open');
}
function sprSubmit(f){ f.querySelector('button[type=submit]').disabled = true; return true; }
sprUpd();
</script>
