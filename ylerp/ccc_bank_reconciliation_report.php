<?php
/**
 * ccc_bank_reconciliation_report.php — CCC vs Bank Reconciliation (Reports → Cash & Bank Reports)
 * CCC = Customer Claim Certificate. Base (same grouping as Unilever Reconcile): the claim certificates grouped by BANKING DATE + ENTITY with the TOTAL AMOUNT.
 * Bank side: what Unilever Reconcile has already matched to bank statement transactions for that banking date + entity.
 *   Difference = CCC total − bank reconciled.   Status: Reconciled · Partly reconciled · Not reconciled · Over-applied.
 * Click a row: its certificates and the bank matches. Export Excel (summary + certificates) follows the filters.
 */
date_default_timezone_set('Asia/Colombo');
ob_start();
include 'config.php';
function cb_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function cb_ok($d) { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d); }
function cb_d($v) { return $v ? date('d M Y', strtotime($v)) : '—'; }
/** a query that never throws (a missing table gives false instead of an HTTP 500) */
function cb_q($conn, $sql) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } }
function cb_has($conn, $t) { $r = cb_q($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $t) . "'"); return $r && mysqli_num_rows($r) > 0; }
$e = fn($v) => mysqli_real_escape_string($GLOBALS['conn'], (string)$v);
$hasCC = cb_has($conn, 'claim_cert_items');
$hasRec = cb_has($conn, 'unilever_reconcile_log') && cb_has($conn, 'unilever_reconcile_claim_lines');
$canRemove = function_exists('rbac_can') ? (bool)rbac_can('delete', 'unilever_reconcile') : true;   // removing a reconciled payment = a delete on Unilever Reconcile

/* ═════════ detail of one banking date + entity ═════════ */
if (($_GET['ajax'] ?? '') === 'detail') {
    while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
    $d = $_GET['d'] ?? ''; $en = (string)($_GET['e'] ?? '');
    if (!cb_ok($d) || !$hasCC) { echo json_encode(['success' => false, 'message' => 'Not found.']); exit; }
    $certs = []; $r = cb_q($conn, "SELECT customer_code, customer_name, claim_type, tax_invoice_no, invoice_date, claim_description, actual_amount, vat_amount, total_amount FROM claim_cert_items WHERE DATE(banking_date) = '{$e($d)}' AND COALESCE(entity,'') = '{$e($en)}' ORDER BY id LIMIT 3000");
    while ($r && ($x = mysqli_fetch_assoc($r))) $certs[] = $x;
    $recs = [];
    if ($hasRec) {
        $r = cb_q($conn, "SELECT l.id, l.id AS log_id, (SELECT COUNT(*) FROM unilever_reconcile_claim_lines c2 WHERE c2.log_id = l.id) AS n_lines, l.bank_field, l.bank_amount, cl.cert_amount, cl.bank_ref, l.reconciled_at, l.reconciled_by, l.description AS note, t.transaction_date, t.description AS bank_desc, t.credit, t.debit
                          FROM unilever_reconcile_claim_lines cl JOIN unilever_reconcile_log l ON l.id = cl.log_id LEFT JOIN bank_statement_transactions t ON t.id = l.bank_txn_id
                          WHERE DATE(cl.banking_date) = '{$e($d)}' AND COALESCE(cl.entity,'') = '{$e($en)}' ORDER BY l.reconciled_at, l.id");
        while ($r && ($x = mysqli_fetch_assoc($r))) $recs[] = $x;
    }
    echo json_encode(['success' => true, 'certs' => $certs, 'recs' => $recs], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR); exit;
}

/* ═════════ filters ═════════ */
$f_from = cb_ok($_GET['date_from'] ?? '') ? $_GET['date_from'] : ''; $f_to = cb_ok($_GET['date_to'] ?? '') ? $_GET['date_to'] : '';
if ($f_from && $f_to && $f_to < $f_from) [$f_from, $f_to] = [$f_to, $f_from];
$f_ent = trim($_GET['entity'] ?? ''); $f_st = in_array($_GET['status'] ?? '', ['reconciled', 'partial', 'none', 'over'], true) ? $_GET['status'] : ''; $f_q = trim($_GET['q'] ?? '');

/* ═════════ data: CCC per banking date + entity, and what the bank reconcile applied to it ═════════ */
$groups = []; $entities = [];
if ($hasCC) {
    $w = ["banking_date IS NOT NULL", "banking_date <> ''"];
    if ($f_from) $w[] = "DATE(banking_date) >= '{$e($f_from)}'";
    if ($f_to)   $w[] = "DATE(banking_date) <= '{$e($f_to)}'";
    if ($f_ent !== '') $w[] = "COALESCE(entity,'') = '{$e($f_ent)}'";
    $r = cb_q($conn, "SELECT DATE(banking_date) AS bdate, COALESCE(entity,'') AS entity, COUNT(*) AS n, SUM(total_amount) AS total FROM claim_cert_items WHERE " . implode(' AND ', $w) . " GROUP BY DATE(banking_date), COALESCE(entity,'') ORDER BY bdate DESC, entity ASC");
    while ($r && ($x = mysqli_fetch_assoc($r))) $groups[$x['bdate'] . '___' . $x['entity']] = ['d' => $x['bdate'], 'entity' => $x['entity'], 'n' => (int)$x['n'], 'total' => round((float)$x['total'], 2), 'applied' => 0.0, 'last' => '', 'refs' => '', 'banks' => 0];
    $r = cb_q($conn, "SELECT DISTINCT COALESCE(entity,'') FROM claim_cert_items WHERE entity IS NOT NULL AND entity <> '' ORDER BY 1"); while ($r && ($x = mysqli_fetch_row($r))) $entities[] = $x[0];
}
if ($hasRec && $groups) {
    $r = cb_q($conn, "SELECT DATE(cl.banking_date) AS bdate, COALESCE(cl.entity,'') AS entity, SUM(cl.cert_amount) AS applied, MAX(t.transaction_date) AS last_bank, COUNT(DISTINCT l.bank_txn_id) AS banks,
                             GROUP_CONCAT(DISTINCT NULLIF(cl.bank_ref,'') SEPARATOR ', ') AS refs
                      FROM unilever_reconcile_claim_lines cl JOIN unilever_reconcile_log l ON l.id = cl.log_id LEFT JOIN bank_statement_transactions t ON t.id = l.bank_txn_id
                      GROUP BY DATE(cl.banking_date), COALESCE(cl.entity,'')");
    while ($r && ($x = mysqli_fetch_assoc($r))) { $k = $x['bdate'] . '___' . $x['entity']; if (isset($groups[$k])) { $groups[$k]['applied'] = round((float)$x['applied'], 2); $groups[$k]['last'] = (string)$x['last_bank']; $groups[$k]['refs'] = (string)$x['refs']; $groups[$k]['banks'] = (int)$x['banks']; } }
}
$rows = [];
foreach ($groups as $g) {
    $g['diff'] = round($g['total'] - $g['applied'], 2);
    $g['status'] = $g['applied'] <= 0.009 ? 'none' : (abs($g['diff']) <= 0.5 ? 'reconciled' : ($g['diff'] > 0 ? 'partial' : 'over'));
    if ($f_st !== '' && $g['status'] !== $f_st) continue;
    if ($f_q !== '' && stripos($g['entity'] . ' ' . $g['refs'] . ' ' . $g['d'], $f_q) === false) continue;
    $rows[] = $g;
}
/* ═════════ rest of the bank: Unilever bank-statement credits (Unilever Reconcile Settings account / type) that are not fully reconciled ═════════ */
$UL_DESC = ['UNILEVER LANKA CONSUMER LIMITED', 'UNILEVER SRI LANKA LIMITED', 'UNILEVER SRI LANKA LIMITED USLL', 'UNILEVER LANKA CONSUMER LIMITED ULCL', 'UNILEVER SRI LANKA LTD', 'UNILEVER LANKA CONSUMER LTD', 'UNILEVER LIPTON CEYLON LIMITED'];
$bank = []; $bankTot = ['n' => 0, 'credit' => 0.0, 'applied' => 0.0, 'left' => 0.0]; $bankCfg = ''; $bankErr = '';
if (cb_has($conn, 'bank_statement_transactions') && cb_has($conn, 'bank_statement_uploads') && cb_has($conn, 'unilever_recon_settings')) {
    $sr = cb_q($conn, "SELECT bank_account_id, UPPER(bank_type) AS bt FROM unilever_recon_settings WHERE id = 1 LIMIT 1"); $sx = $sr ? mysqli_fetch_assoc($sr) : null;
    $acc = (int)($sx['bank_account_id'] ?? 0); $bt = (string)($sx['bt'] ?? '');
    if ($acc > 0) {
        $dw = []; foreach ($UL_DESC as $d) $dw[] = "UPPER(t.description) LIKE '%" . $e($d) . "%'";
        $w = ["u.account_id = $acc", "(t.recon_source IS NULL OR t.recon_source = '' OR t.recon_source = 'unilever_recon')", "(t.reconciled = 0 OR t.reconcile_balance > 0)", "t.credit > 0", '(' . implode(' OR ', $dw) . ')'];
        if ($bt !== '') $w[] = "UPPER(u.bank_type) = '{$e($bt)}'";
        if ($f_from) $w[] = "t.transaction_date >= '{$e($f_from)}'";
        if ($f_to)   $w[] = "t.transaction_date <= '{$e($f_to)}'";
        if ($f_q !== '') $w[] = "(t.description LIKE '%{$e($f_q)}%' OR t.reference LIKE '%{$e($f_q)}%' OR CAST(t.credit AS CHAR) LIKE '%{$e($f_q)}%')";
        $r = cb_q($conn, "SELECT t.id, t.transaction_date, t.description, t.reference, t.credit, t.reconcile_balance, t.reconciled_amount FROM bank_statement_transactions t JOIN bank_statement_uploads u ON u.id = t.upload_id WHERE " . implode(' AND ', $w) . " ORDER BY t.transaction_date DESC, t.id DESC LIMIT 3000");
        while ($r && ($x = mysqli_fetch_assoc($r))) {
            $cr = round((float)$x['credit'], 2); $left = $x['reconcile_balance'] !== null ? round((float)$x['reconcile_balance'], 2) : $cr;
            if ($left <= 0.009) continue;
            $x['left'] = $left; $x['applied'] = round($cr - $left, 2); $x['credit'] = $cr; $bank[] = $x;
            $bankTot['n']++; $bankTot['credit'] += $cr; $bankTot['applied'] += $x['applied']; $bankTot['left'] += $left;
        }
        $bankCfg = 'Account #' . $acc . ($bt !== '' ? ' · ' . $bt : '');
    } else $bankErr = 'No bank account is saved in Unilever Reconcile settings yet.';
} else $bankErr = 'No bank statements / Unilever Reconcile settings yet.';
$ST = ['reconciled' => ['Reconciled', 'ok'], 'partial' => ['Partly reconciled', 'pt'], 'none' => ['Not reconciled', 'no'], 'over' => ['Over-applied', 'ov']];
$tot = ['n' => 0, 'total' => 0.0, 'applied' => 0.0, 'diff' => 0.0]; $cnt = ['reconciled' => 0, 'partial' => 0, 'none' => 0, 'over' => 0];
foreach ($rows as $g) { $tot['n'] += $g['n']; $tot['total'] += $g['total']; $tot['applied'] += $g['applied']; $tot['diff'] += $g['diff']; $cnt[$g['status']]++; }

/* ═════════ Excel ═════════ */
if (isset($_GET['export']) && class_exists('ZipArchive')) {
    require_once __DIR__ . '/rbac_xlsx.php';
    while (ob_get_level()) ob_end_clean();
    $X = new MiniXlsx(); $sh = $X->sheet('CCC vs Bank');
    $H = ['b' => 1, 'color' => 'FFFFFF', 'bg' => '1E3A8A', 'h' => 'center', 'border' => 1, 'wrap' => 1];
    $sh->set(1, 1, 'CCC vs Bank Reconciliation', ['b' => 1, 'sz' => 15]);
    $fl = array_filter([$f_from || $f_to ? 'Banking date ' . ($f_from ?: '…') . ' → ' . ($f_to ?: '…') : '', $f_ent !== '' ? 'Entity: ' . $f_ent : '', $f_st !== '' ? 'Status: ' . $ST[$f_st][0] : '', $f_q !== '' ? 'Search: ' . $f_q : '']);
    $sh->set(2, 1, $fl ? 'Filters — ' . implode(' · ', $fl) : 'All banking dates', ['i' => 1, 'color' => '6B7280']);
    foreach (['#', 'Banking Date', 'Entity', 'Certificates', 'Total Amount (CCC)', 'Bank Reconciled', 'Difference', 'Status', 'Last Bank Date', 'Bank Ref'] as $i => $h) $sh->set(4, $i + 1, $h, $H);
    $r = 5; foreach ($rows as $n => $g) {
        $col = ['reconciled' => '15803D', 'partial' => 'B45309', 'none' => 'B91C1C', 'over' => '6D28D9'][$g['status']];
        foreach ([$n + 1, $g['d'], $g['entity'], $g['n'], $g['total'], $g['applied'], $g['diff'], $ST[$g['status']][0], $g['last'], $g['refs']] as $i => $v) $sh->set($r, $i + 1, $v, ['border' => 1] + (in_array($i, [4, 5, 6], true) ? ['h' => 'right', 'nf' => 4] : []) + ($i === 7 ? ['b' => 1, 'color' => $col, 'h' => 'center'] : []));
        $r++;
    }
    $tb = ['b' => 1, 'border' => 1, 'bg' => 'FDE68A', 'h' => 'right', 'nf' => 4];
    $sh->set($r, 3, 'TOTAL', ['b' => 1, 'border' => 1, 'bg' => 'FDE68A']); $sh->set($r, 4, $tot['n'], ['b' => 1, 'border' => 1, 'bg' => 'FDE68A', 'h' => 'right']); $sh->set($r, 5, round($tot['total'], 2), $tb); $sh->set($r, 6, round($tot['applied'], 2), $tb); $sh->set($r, 7, round($tot['diff'], 2), $tb);
    foreach ([5, 14, 22, 13, 18, 18, 16, 18, 15, 30] as $i => $wd) $sh->widths[$i + 1] = $wd; $sh->freeze = [4, 0]; $sh->filter = 'A4:J' . max(5, $r - 1);
    $s2 = $X->sheet('Certificates'); $s2->set(1, 1, 'Claim certificates of the banking dates above', ['b' => 1, 'sz' => 14]);
    foreach (['Banking Date', 'Entity', 'Customer', 'Customer Name', 'Claim Type', 'Tax Invoice No', 'Invoice Date', 'Claim Description', 'Actual Amount', 'VAT Amount', 'Total Amount', 'Status of the day'] as $i => $h) $s2->set(3, $i + 1, $h, $H);
    $rr = 4; $maxc = 20000;
    foreach ($rows as $g) { if ($rr > $maxc) break;
        $c = cb_q($conn, "SELECT customer_code, customer_name, claim_type, tax_invoice_no, invoice_date, claim_description, actual_amount, vat_amount, total_amount FROM claim_cert_items WHERE DATE(banking_date) = '{$e($g['d'])}' AND COALESCE(entity,'') = '{$e($g['entity'])}' ORDER BY id");
        while ($c && ($x = mysqli_fetch_assoc($c)) && $rr <= $maxc) { foreach ([$g['d'], $g['entity'], $x['customer_code'], $x['customer_name'], $x['claim_type'], $x['tax_invoice_no'], $x['invoice_date'], $x['claim_description'], (float)$x['actual_amount'], (float)$x['vat_amount'], (float)$x['total_amount'], $ST[$g['status']][0]] as $i => $v) $s2->set($rr, $i + 1, $v === null ? '' : $v, ['border' => 1] + (in_array($i, [8, 9, 10], true) ? ['h' => 'right', 'nf' => 4] : [])); $rr++; }
    }
    foreach ([13, 18, 14, 26, 16, 16, 13, 44, 14, 12, 14, 18] as $i => $wd) $s2->widths[$i + 1] = $wd; $s2->freeze = [3, 0]; $s2->filter = 'A3:L' . max(4, $rr - 1);
    $tmp = tempnam(sys_get_temp_dir(), 'cb'); $X->save($tmp);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="ccc_vs_bank_reconciliation_' . date('Ymd_His') . '.xlsx"');
    header('Content-Length: ' . filesize($tmp)); readfile($tmp); @unlink($tmp); exit;
}
ob_end_flush();
include 'header.php';
?>
<style>
.cb{max-width:1500px;margin:0 auto;padding:16px;font-family:'Inter',system-ui,sans-serif;color:#111827}.cb h2{margin:0 0 3px;font-size:22px}.cb .sub{color:#6b7280;font-size:13px;margin:0 0 14px}
.cb .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 16px;margin-bottom:14px}.cb .row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}.cb .fld{display:flex;flex-direction:column;gap:3px}.cb .fld label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase}
.cb input,.cb select{padding:8px 10px;border:1.5px solid #d1d5db;border-radius:8px;font:inherit;font-size:13px}.cb .btn{padding:8px 16px;border:none;border-radius:8px;background:#1e3a8a;color:#fff;font:600 13px inherit;cursor:pointer;text-decoration:none;display:inline-flex;gap:6px;align-items:center}.cb .btn.o{background:#fff;color:#1e3a8a;border:1.5px solid #c7d2fe}.cb .btn.g{background:#16a34a}
.cb .kp{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,190px),1fr));gap:10px;margin-bottom:12px}.cb .k.wide{grid-column:span 2}@media(max-width:560px){.cb .k.wide{grid-column:1/-1}}.cb .k b{white-space:nowrap}.cb .k b span{display:inline;font-size:inherit;text-transform:none;color:inherit;font-weight:inherit}.cb .k b span.sl{color:#9ca3af;margin:0 4px}.cb .k.wide b{font-size:clamp(17px,2.2vw,22px)}.cb .tabs{display:flex;gap:6px;margin:0 0 12px;border-bottom:2px solid #e5e7eb;flex-wrap:wrap}.cb .tabs button{padding:9px 16px;border:none;background:none;font:700 13.5px inherit;color:#6b7280;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px}.cb .tabs button.on{color:#1e3a8a;border-bottom-color:#1e3a8a}.cb .tabs .c{background:#e0e7ff;color:#1e3a8a;border-radius:99px;padding:1px 8px;font-size:11.5px;margin-left:5px}.cb .pane{display:none}.cb .pane.on{display:block}.cb .k{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:10px 14px}.cb .k span{font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:700;display:block}.cb .k b{font-size:19px}
.cb .tw{overflow:auto;border:1px solid #e5e7eb;border-radius:10px;background:#fff;max-height:72vh}.cb table{width:100%;border-collapse:collapse;font-size:13px}.cb th{background:#1e3a8a;color:#fff;padding:9px 10px;text-align:left;position:sticky;top:0;white-space:nowrap}.cb td{padding:8px 10px;border-bottom:1px solid #f1f5f9}.cb td.r,.cb th.r{text-align:right;font-variant-numeric:tabular-nums}
.cb tbody tr.g{cursor:pointer}.cb tbody tr.g:hover td{background:#eef2ff}.cb tfoot td{background:#fde68a;font-weight:800;position:sticky;bottom:0}
.cb .st{display:inline-block;padding:2px 10px;border-radius:99px;font-size:11.5px;font-weight:700}.cb .st.ok{background:#dcfce7;color:#166534}.cb .st.pt{background:#fef3c7;color:#92400e}.cb .st.no{background:#fee2e2;color:#991b1b}.cb .st.ov{background:#ede9fe;color:#5b21b6}
.cbm{position:fixed;inset:0;background:rgba(15,23,42,.6);display:none;align-items:center;justify-content:center;z-index:2147483000;padding:14px}.cbm.open{display:flex}.cbm .bx{background:#fff;border-radius:14px;width:100%;max-width:1200px;height:92vh;display:flex;flex-direction:column;overflow:hidden;font-family:'Inter',system-ui,sans-serif}
.cbm .hd{padding:14px 18px;background:linear-gradient(120deg,#0f172a,#1e3a8a);color:#fff;display:flex;gap:12px;align-items:center}.cbm .bd{padding:14px 18px;overflow:auto;flex:1}.cbm h4{margin:14px 0 6px}.cbm .rm{background:#fff;border:1.5px solid #fca5a5;color:#b91c1c;border-radius:8px;padding:4px 11px;font:700 12px inherit;cursor:pointer;white-space:nowrap}.cbm .rm:hover{background:#fee2e2}.cbm .rmbox{margin:8px 0;padding:12px 14px;border-radius:10px;background:#fef2f2;border:1.5px solid #fecaca;color:#7f1d1d;font-size:13px}.cbm .rmbox button{margin-right:8px;border-radius:8px;padding:7px 14px;font:700 12.5px inherit;cursor:pointer;border:1.5px solid #d1d5db;background:#fff}.cbm .rmbox button.danger{background:#dc2626;border-color:#dc2626;color:#fff}.cbm table{width:100%;border-collapse:collapse;font-size:12.5px}.cbm th{background:#eef2ff;color:#1e3a8a;padding:7px 9px;text-align:left;white-space:nowrap}.cbm td{padding:6px 9px;border-bottom:1px solid #f1f5f9}.cbm td.r,.cbm th.r{text-align:right}
</style>
<div class="cb">
  <h2><i class="fa-solid fa-building-columns" style="color:#1e3a8a"></i> CCC vs Bank Reconciliation</h2>
  <p class="sub">Customer Claim Certificates (CCC) grouped by <b>banking date</b> and <b>entity</b> with their <b>total amount</b>, against what <a href="unilever_reconcile.php">Unilever Reconcile</a> has matched to the bank statement. <b>Click a row</b> for its certificates and bank matches.</p>
  <?php if (!$hasCC): ?><div class="card" style="color:#92400e;background:#fffbeb">No Customer Claim Certificate data yet — import it on the Customer Claim Certificate Import page.</div><?php endif; ?>
  <?php if ($hasCC && !$hasRec): ?><div class="card" style="color:#92400e;background:#fffbeb">Nothing has been reconciled yet (open Unilever Reconcile and run the reconcile) — every banking date shows as not reconciled.</div><?php endif; ?>
  <form method="get" class="card"><div class="row">
    <div class="fld"><label>Banking date from</label><input type="date" name="date_from" value="<?= cb_h($f_from) ?>"></div>
    <div class="fld"><label>Banking date to</label><input type="date" name="date_to" value="<?= cb_h($f_to) ?>"></div>
    <div class="fld"><label>Entity</label><select name="entity"><option value="">All</option><?php foreach ($entities as $en): ?><option <?= $f_ent === $en ? 'selected' : '' ?>><?= cb_h($en) ?></option><?php endforeach; ?></select></div>
    <div class="fld"><label>Status</label><select name="status"><option value="">All</option><?php foreach ($ST as $k => $v): ?><option value="<?= $k ?>" <?= $f_st === $k ? 'selected' : '' ?>><?= $v[0] ?></option><?php endforeach; ?></select></div>
    <div class="fld"><label>Search</label><input type="search" name="q" value="<?= cb_h($f_q) ?>" placeholder="entity, bank ref, date"></div>
    <button class="btn" type="submit"><i class="fa-solid fa-filter"></i> Show</button><a class="btn o" href="ccc_bank_reconciliation_report.php">Reset</a>
    <a class="btn g" data-no-loader href="?<?= cb_h(http_build_query(array_merge($_GET, ['export' => 1]))) ?>"><i class="fa-solid fa-file-excel"></i> Export Excel</a></div></form>
  <div class="tabs"><button type="button" class="on" data-p="p1">CCC vs Bank</button><button type="button" data-p="p2">Rest of the bank — not reconciled<span class="c"><?= number_format($bankTot['n']) ?></span></button></div>
  <div class="pane on" id="p1">
  <div class="kp"><div class="k"><span>Banking dates × entity</span><b><?= number_format(count($rows)) ?></b></div><div class="k"><span>CCC total amount</span><b style="color:#1e3a8a">Rs. <?= number_format($tot['total'], 2) ?></b></div><div class="k"><span>Bank reconciled</span><b style="color:#15803d">Rs. <?= number_format($tot['applied'], 2) ?></b></div>
    <div class="k"><span>Difference</span><b style="color:<?= $tot['diff'] > 0.5 ? '#b91c1c' : ($tot['diff'] < -0.5 ? '#6d28d9' : '#15803d') ?>">Rs. <?= $tot['diff'] < 0 ? '(' . number_format(abs($tot['diff']), 2) . ')' : number_format($tot['diff'], 2) ?></b></div>
    <div class="k wide"><span>Reconciled / partly / not</span><b><span style="color:#15803d"><?= $cnt['reconciled'] ?></span><span class="sl">/</span><span style="color:#b45309"><?= $cnt['partial'] ?></span><span class="sl">/</span><span style="color:#b91c1c"><?= $cnt['none'] ?></span><?php if ($cnt['over']): ?><span class="sl">/</span><span style="color:#6d28d9"><?= $cnt['over'] ?> over</span><?php endif; ?></b></div></div>
  <div class="tw"><table><thead><tr><th>#</th><th>Banking Date</th><th>Entity</th><th class="r">Certificates</th><th class="r">Total Amount (CCC)</th><th class="r">Bank Reconciled</th><th class="r">Difference</th><th>Status</th><th>Last Bank Date</th><th>Bank Ref</th></tr></thead><tbody>
  <?php foreach ($rows as $n => $g): ?><tr class="g" data-d="<?= cb_h($g['d']) ?>" data-e="<?= cb_h($g['entity']) ?>" data-t="<?= $g['total'] ?>"><td><?= $n + 1 ?></td><td style="white-space:nowrap"><b><?= cb_d($g['d']) ?></b></td><td><?= cb_h($g['entity'] ?: '—') ?></td><td class="r"><?= number_format($g['n']) ?></td><td class="r"><b><?= number_format($g['total'], 2) ?></b></td><td class="r"><?= $g['applied'] > 0 ? number_format($g['applied'], 2) : '<span style="color:#cbd5e1">—</span>' ?></td>
    <td class="r"><b style="color:<?= $g['diff'] > 0.5 ? '#b91c1c' : ($g['diff'] < -0.5 ? '#6d28d9' : '#15803d') ?>"><?= $g['diff'] < 0 ? '(' . number_format(abs($g['diff']), 2) . ')' : number_format($g['diff'], 2) ?></b></td><td><span class="st <?= $ST[$g['status']][1] ?>"><?= $ST[$g['status']][0] ?></span></td><td style="white-space:nowrap"><?= $g['last'] ? cb_d($g['last']) : '—' ?></td><td style="font-size:12px;color:#6b7280"><?= cb_h($g['refs']) ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="10" style="text-align:center;color:#9ca3af;padding:28px">No banking dates for these filters.</td></tr><?php endif; ?></tbody>
  <?php if ($rows): ?><tfoot><tr><td colspan="3">TOTAL</td><td class="r"><?= number_format($tot['n']) ?></td><td class="r"><?= number_format($tot['total'], 2) ?></td><td class="r"><?= number_format($tot['applied'], 2) ?></td><td class="r"><?= $tot['diff'] < 0 ? '(' . number_format(abs($tot['diff']), 2) . ')' : number_format($tot['diff'], 2) ?></td><td colspan="3"></td></tr></tfoot><?php endif; ?></table></div>
  </div>
  <div class="pane" id="p2">
    <p class="sub" style="margin-top:0">Unilever credits on the bank statement (<?= cb_h($bankCfg ?: 'account not set') ?>, same rows as Unilever Reconcile) that are <b>not reconciled yet</b> or only <b>partly</b> applied. The date / search filters above apply (bank transaction date).</p>
    <?php if ($bankErr): ?><div class="card" style="color:#92400e;background:#fffbeb"><?= cb_h($bankErr) ?></div><?php endif; ?>
    <div class="kp"><div class="k"><span>Bank rows left</span><b><?= number_format($bankTot['n']) ?></b></div><div class="k"><span>Bank credit</span><b>Rs. <?= number_format($bankTot['credit'], 2) ?></b></div><div class="k"><span>Already applied</span><b style="color:#15803d">Rs. <?= number_format($bankTot['applied'], 2) ?></b></div><div class="k wide"><span>Still to reconcile</span><b style="color:#b91c1c">Rs. <?= number_format($bankTot['left'], 2) ?></b></div></div>
    <div class="tw"><table><thead><tr><th>#</th><th>Bank Date</th><th>Description</th><th>Reference</th><th class="r">Credit</th><th class="r">Applied</th><th class="r">Left to reconcile</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($bank as $n => $b): ?><tr><td><?= $n + 1 ?></td><td style="white-space:nowrap"><b><?= cb_d($b['transaction_date']) ?></b></td><td><?= cb_h($b['description']) ?></td><td style="font-size:12px;color:#6b7280"><?= cb_h($b['reference']) ?></td><td class="r"><?= number_format($b['credit'], 2) ?></td><td class="r"><?= $b['applied'] > 0 ? number_format($b['applied'], 2) : '<span style="color:#cbd5e1">—</span>' ?></td><td class="r"><b style="color:#b91c1c"><?= number_format($b['left'], 2) ?></b></td><td><span class="st <?= $b['applied'] > 0 ? 'pt' : 'no' ?>"><?= $b['applied'] > 0 ? 'Partly reconciled' : 'Not reconciled' ?></span></td></tr>
    <?php endforeach; if (!$bank): ?><tr><td colspan="8" style="text-align:center;color:#9ca3af;padding:28px">Every Unilever bank credit is reconciled for these filters.</td></tr><?php endif; ?></tbody>
    <?php if ($bank): ?><tfoot><tr><td colspan="4">TOTAL</td><td class="r"><?= number_format($bankTot['credit'], 2) ?></td><td class="r"><?= number_format($bankTot['applied'], 2) ?></td><td class="r"><?= number_format($bankTot['left'], 2) ?></td><td></td></tr></tfoot><?php endif; ?></table></div>
  </div>
</div>
<div class="cbm" id="cbM"><div class="bx"><div class="hd"><div><div style="font-weight:800;font-size:16px" id="cbT">Banking date</div><div id="cbS" style="opacity:.8;font-size:12.5px"></div></div><button class="btn o" id="cbX" type="button" style="margin-left:auto">Close</button></div><div class="bd" id="cbB"></div></div></div>
<script>
(function(){
  (function(){ var bs=document.querySelectorAll('.cb .tabs button'); function go(id){ bs.forEach(function(b){ b.classList.toggle('on',b.dataset.p===id); }); document.querySelectorAll('.cb .pane').forEach(function(p){ p.classList.toggle('on',p.id===id); }); try{ history.replaceState(null,'','#'+id); }catch(e){} } bs.forEach(function(b){ b.addEventListener('click',function(){ go(b.dataset.p); }); }); if(location.hash==='#p2') go('p2'); window.addEventListener('hashchange',function(){ go(location.hash==='#p2'?'p2':'p1'); }); })();
  var CAN_REMOVE=<?= $canRemove ? 'true' : 'false' ?>, DIRTY=false;
  var $=function(i){return document.getElementById(i);}, esc=function(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}, m2=function(v){return Number(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});};
  document.body.appendChild($('cbM'));
  function close(){ $('cbM').classList.remove('open'); document.body.style.overflow=''; if(DIRTY) location.reload(); }
  $('cbX').addEventListener('click',close); $('cbM').addEventListener('click',function(e){ if(e.target===this) close(); }); document.addEventListener('keydown',function(e){ if(e.key==='Escape') close(); });
  var CUR={d:'',e:''};
  function load(d,en){
    $('cbS').textContent='Loading…'; $('cbB').innerHTML='';
    fetch('ccc_bank_reconciliation_report.php?ajax=detail&d='+encodeURIComponent(d)+'&e='+encodeURIComponent(en),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(x){
      if(!x.success){ $('cbS').textContent=''; $('cbB').innerHTML='<p style="color:#b91c1c">'+esc(x.message||'Could not load')+'</p>'; return; }
      var tc=x.certs.reduce(function(a,c){return a+(+c.total_amount||0);},0), ta=x.recs.reduce(function(a,c){return a+(+c.cert_amount||0);},0);
      $('cbS').textContent=x.certs.length+' certificates · total '+m2(tc)+' · bank reconciled '+m2(ta)+' · difference '+m2(tc-ta);
      var h='<h4>Bank payments reconciled to this banking date (Unilever Reconcile)</h4><div id="cbRm"></div><table><thead><tr><th>Bank date</th><th>Bank description</th><th>Field</th><th class="r">Bank amount</th><th class="r">Applied to this day</th><th>Bank ref</th><th>Reconciled</th>'+(CAN_REMOVE?'<th></th>':'')+'</tr></thead><tbody>'
        +(x.recs.length?x.recs.map(function(c){ return '<tr><td>'+esc(c.transaction_date||'')+'</td><td>'+esc(c.bank_desc||c.note||'')+'</td><td>'+esc(c.bank_field||'')+'</td><td class="r">'+m2(c.bank_amount)+'</td><td class="r"><b>'+m2(c.cert_amount)+'</b></td><td>'+esc(c.bank_ref||'')+'</td><td>'+esc((c.reconciled_at||'')+' '+(c.reconciled_by||''))+'</td>'+(CAN_REMOVE?'<td><button type="button" class="rm" data-log="'+(+c.log_id)+'" data-lines="'+(+c.n_lines||1)+'" data-amt="'+m2(c.cert_amount)+'" data-bank="'+m2(c.bank_amount)+'"><i class="fa-solid fa-trash-can"></i> Remove</button></td>':'')+'</tr>'; }).join(''):'<tr><td colspan="'+(CAN_REMOVE?8:7)+'" style="color:#9ca3af;padding:12px">Not reconciled to any bank transaction yet.</td></tr>')+'</tbody></table>';
      h+='<h4>Claim certificates</h4><table><thead><tr><th>Customer</th><th>Claim type</th><th>Tax invoice no</th><th>Invoice date</th><th>Description</th><th class="r">Actual</th><th class="r">VAT</th><th class="r">Total</th></tr></thead><tbody>'
        +x.certs.map(function(c){ return '<tr><td>'+esc(c.customer_code)+' <span style="color:#6b7280">'+esc(c.customer_name||'')+'</span></td><td>'+esc(c.claim_type||'')+'</td><td><b>'+esc(c.tax_invoice_no||'')+'</b></td><td>'+esc(c.invoice_date||'')+'</td><td style="white-space:normal;min-width:240px">'+esc(c.claim_description||'')+'</td><td class="r">'+m2(c.actual_amount)+'</td><td class="r">'+m2(c.vat_amount)+'</td><td class="r"><b>'+m2(c.total_amount)+'</b></td></tr>'; }).join('')+'</tbody></table>';
      $('cbB').innerHTML=h; }).catch(function(){ $('cbS').textContent=''; $('cbB').innerHTML='<p style="color:#b91c1c">Could not load the details.</p>'; });
  }
  document.addEventListener('click',function(e){ var tr=e.target.closest('tr.g'); if(!tr) return; CUR={d:tr.dataset.d,e:tr.dataset.e};
    $('cbT').textContent=CUR.d+(CUR.e?' · '+CUR.e:''); $('cbM').classList.add('open'); document.body.style.overflow='hidden'; load(CUR.d,CUR.e);
  });
  /* remove a reconciled payment (the same undo as Unilever Reconcile): ask inside the modal first */
  document.addEventListener('click',function(e){
    var b=e.target.closest('button.rm'); if(b){ var n=+b.dataset.lines||1;
      $('cbRm').innerHTML='<div class="rmbox"><b>Remove this reconciled payment?</b><br>Bank amount '+b.dataset.bank+' · applied '+b.dataset.amt+' to this banking date.<br>'+(n>1?'<b>This payment is shared with '+(n-1)+' other banking date / entity group'+(n>2?'s':'')+' — removing it re-opens all '+n+' of them.</b><br>':'')+'The bank row becomes free to reconcile again.<div style="margin-top:10px"><button type="button" class="danger" id="cbRmYes" data-log="'+b.dataset.log+'"><i class="fa-solid fa-trash-can"></i> Yes, remove</button><button type="button" id="cbRmNo">Keep it</button></div></div>';
      $('cbRm').scrollIntoView({block:'nearest'}); return; }
    if(e.target.closest('#cbRmNo')){ $('cbRm').innerHTML=''; return; }
    var y=e.target.closest('#cbRmYes'); if(y){ y.disabled=true; y.textContent='Removing…';
      fetch('unilever_reconcile.php?ajax=delete_manual_reconcile',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({log_id:+y.dataset.log})}).then(function(r){return r.text();}).then(function(t){ var j; try{ j=JSON.parse(t); }catch(er){ throw new Error('Unexpected reply — you may not be allowed to delete on Unilever Reconcile.'); }
        if(!j.ok) throw new Error(j.message||j.msg||'Could not remove'); DIRTY=true; load(CUR.d,CUR.e); setTimeout(function(){ var m=$('cbRm'); if(m) m.innerHTML='<div class="rmbox" style="background:#ecfdf5;border-color:#6ee7b7;color:#065f46">✔ Payment removed — the banking date is open again.</div>'; },400);
      }).catch(function(er){ $('cbRm').innerHTML='<div class="rmbox"><b>Not removed:</b> '+esc(er.message)+'</div>'; });
    }
  });
})();
</script>
<?php include 'footer.php'; ?>
