<?php
/**
 * unilever_bank_transactions_report.php — Unilever Bank Transactions
 * Only the bank-statement rows of the SELECTED bank account whose description is a known Unilever counterparty
 * (same description list as Unilever Reconcile). List + totals + Excel export. Read only.
 */
date_default_timezone_set('Asia/Colombo');
ob_start();
include 'config.php';
function ub_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ub_ok($d) { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d); }
function ub_q($conn, $sql) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } }
function ub_has($conn, $t) { $r = ub_q($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $t) . "'"); return $r && mysqli_num_rows($r) > 0; }
function ub_m($v) { return $v === null || $v === '' ? '' : number_format((float)$v, 2); }
$e = fn($v) => mysqli_real_escape_string($GLOBALS['conn'], (string)$v);
$UL_DESC = ['UNILEVER LANKA CONSUMER LIMITED', 'UNILEVER SRI LANKA LIMITED', 'UNILEVER SRI LANKA LIMITED USLL', 'UNILEVER LANKA CONSUMER LIMITED ULCL', 'UNILEVER SRI LANKA LTD', 'UNILEVER LANKA CONSUMER LTD', 'UNILEVER LIPTON CEYLON LIMITED'];
$hasBank = ub_has($conn, 'bank_statement_transactions') && ub_has($conn, 'bank_statement_uploads');

/* accounts that have bank statements */
$accounts = [];
if ($hasBank) {
    $r = ub_q($conn, "SELECT u.account_id, MAX(u.statement_date) AS last_stmt, COUNT(*) AS uploads FROM bank_statement_uploads u WHERE u.account_id > 0 GROUP BY u.account_id");
    while ($r && ($x = mysqli_fetch_assoc($r))) $accounts[(int)$x['account_id']] = ['label' => 'Account #' . $x['account_id'], 'last' => $x['last_stmt']];
    if ($accounts) {
        $r = ub_q($conn, "SELECT cba.id, cba.account_no, cba.account_name, b.bank_name, c.company_code FROM company_bank_accounts cba LEFT JOIN banks b ON cba.bank_code = b.bank_code LEFT JOIN companies c ON cba.company_id = c.id WHERE cba.id IN (" . implode(',', array_keys($accounts)) . ")");
        while ($r && ($x = mysqli_fetch_assoc($r))) $accounts[(int)$x['id']]['label'] = '[' . ($x['company_code'] ?? '') . '] ' . ($x['bank_name'] ?? '') . ' — ' . ($x['account_name'] ?? '') . ' (' . ($x['account_no'] ?? '') . ')';
        uasort($accounts, fn($a, $b) => strcasecmp($a['label'], $b['label']));
    }
}
$def = 0;
if (ub_has($conn, 'unilever_recon_settings')) { $r = ub_q($conn, "SELECT bank_account_id FROM unilever_recon_settings WHERE id = 1"); $x = $r ? mysqli_fetch_row($r) : null; $def = (int)($x[0] ?? 0); }
$f_acc = (int)($_GET['account'] ?? 0); if (!$f_acc || !isset($accounts[$f_acc])) $f_acc = isset($accounts[$def]) ? $def : (int)(array_key_first($accounts) ?? 0);
$f_from = ub_ok($_GET['date_from'] ?? '') ? $_GET['date_from'] : ''; $f_to = ub_ok($_GET['date_to'] ?? '') ? $_GET['date_to'] : '';
if ($f_from && $f_to && $f_to < $f_from) [$f_from, $f_to] = [$f_to, $f_from];
$f_dir = in_array($_GET['dir'] ?? '', ['credit', 'debit'], true) ? $_GET['dir'] : '';
$f_rc = in_array($_GET['rec'] ?? '', ['yes', 'part', 'no'], true) ? $_GET['rec'] : '';
$f_q = trim($_GET['q'] ?? '');

$rows = []; $tot = ['n' => 0, 'debit' => 0.0, 'credit' => 0.0, 'left' => 0.0];
if ($hasBank && $f_acc) {
    $dw = []; foreach ($UL_DESC as $d) $dw[] = "UPPER(t.description) LIKE '%" . $e($d) . "%'";
    $w = ["u.account_id = $f_acc", '(' . implode(' OR ', $dw) . ')'];
    if ($f_from) $w[] = "t.transaction_date >= '{$e($f_from)}'";
    if ($f_to)   $w[] = "t.transaction_date <= '{$e($f_to)}'";
    if ($f_dir === 'credit') $w[] = "t.credit > 0"; elseif ($f_dir === 'debit') $w[] = "t.debit > 0";
    if ($f_q !== '') $w[] = "(t.description LIKE '%{$e($f_q)}%' OR t.reference LIKE '%{$e($f_q)}%' OR CAST(t.credit AS CHAR) LIKE '%{$e($f_q)}%' OR CAST(t.debit AS CHAR) LIKE '%{$e($f_q)}%')";
    $r = ub_q($conn, "SELECT t.id, t.transaction_date, t.value_date, t.description, t.reference, t.debit, t.credit, t.balance, t.reconciled, t.reconcile_balance, u.bank_type, u.original_filename
                      FROM bank_statement_transactions t JOIN bank_statement_uploads u ON u.id = t.upload_id WHERE " . implode(' AND ', $w) . " ORDER BY t.transaction_date DESC, t.id DESC LIMIT 20000");
    while ($r && ($x = mysqli_fetch_assoc($r))) {
        $amt = (float)$x['credit'] > 0 ? (float)$x['credit'] : (float)$x['debit'];
        $left = $x['reconcile_balance'] !== null ? (float)$x['reconcile_balance'] : ((int)$x['reconciled'] ? 0.0 : $amt);
        if ((float)$x['credit'] <= 0) { $st = 'na'; $left = 0.0; }                      // debits are not reconciled by Unilever Reconcile
        else $st = $left <= 0.009 ? 'yes' : (abs($left - $amt) <= 0.009 ? 'no' : 'part');
        if ($f_rc !== '' && $st !== $f_rc) continue;
        $x['st'] = $st; $x['left'] = round($left, 2); $rows[] = $x;
        $tot['n']++; $tot['debit'] += (float)$x['debit']; $tot['credit'] += (float)$x['credit']; $tot['left'] += $x['left'];
    }
}
$STL = ['yes' => ['Reconciled', 'ok'], 'part' => ['Partly reconciled', 'pt'], 'no' => ['Not reconciled', 'no'], 'na' => ['—', 'na']];

if (isset($_GET['export']) && class_exists('ZipArchive')) {
    require_once __DIR__ . '/rbac_xlsx.php';
    while (ob_get_level()) ob_end_clean();
    $X = new MiniXlsx(); $sh = $X->sheet('Unilever Transactions');
    $H = ['b' => 1, 'color' => 'FFFFFF', 'bg' => '1E3A8A', 'h' => 'center', 'border' => 1, 'wrap' => 1];
    $sh->set(1, 1, 'Unilever Bank Transactions', ['b' => 1, 'sz' => 15]);
    $fl = array_filter(['Account: ' . ($accounts[$f_acc]['label'] ?? ''), $f_from || $f_to ? 'Date ' . ($f_from ?: '…') . ' → ' . ($f_to ?: '…') : '', $f_dir ? ucfirst($f_dir) . ' only' : '', $f_rc ? 'Status: ' . $STL[$f_rc][0] : '', $f_q !== '' ? 'Search: ' . $f_q : '']);
    $sh->set(2, 1, implode(' · ', $fl), ['i' => 1, 'color' => '6B7280']);
    foreach (['#', 'Transaction Date', 'Value Date', 'Description', 'Reference', 'Debit', 'Credit', 'Balance', 'Reconcile Status', 'Left to Reconcile', 'Statement File'] as $i => $h) $sh->set(4, $i + 1, $h, $H);
    $r = 5; foreach ($rows as $n => $x) {
        foreach ([$n + 1, $x['transaction_date'], $x['value_date'], $x['description'], $x['reference'], $x['debit'] !== null ? (float)$x['debit'] : '', $x['credit'] !== null ? (float)$x['credit'] : '', $x['balance'] !== null ? (float)$x['balance'] : '', $STL[$x['st']][0], $x['st'] === 'na' ? '' : $x['left'], $x['original_filename']] as $i => $v)
            $sh->set($r, $i + 1, $v === null ? '' : $v, ['border' => 1] + (in_array($i, [5, 6, 7, 9], true) ? ['h' => 'right', 'nf' => 4] : []));
        $r++;
    }
    $tb = ['b' => 1, 'border' => 1, 'bg' => 'FDE68A', 'h' => 'right', 'nf' => 4];
    $sh->set($r, 4, 'TOTAL (' . $tot['n'] . ' rows)', ['b' => 1, 'border' => 1, 'bg' => 'FDE68A']); $sh->set($r, 6, round($tot['debit'], 2), $tb); $sh->set($r, 7, round($tot['credit'], 2), $tb); $sh->set($r, 10, round($tot['left'], 2), $tb);
    foreach ([5, 14, 14, 60, 30, 16, 16, 18, 18, 18, 28] as $i => $wd) $sh->widths[$i + 1] = $wd; $sh->freeze = [4, 0]; $sh->filter = 'A4:K' . max(5, $r - 1);
    $tmp = tempnam(sys_get_temp_dir(), 'ub'); $X->save($tmp);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="unilever_bank_transactions_' . date('Ymd_His') . '.xlsx"');
    header('Content-Length: ' . filesize($tmp)); readfile($tmp); @unlink($tmp); exit;
}
ob_end_flush();
include 'header.php';
?>
<style>
.ub{max-width:1500px;margin:0 auto;padding:16px;font-family:'Inter',system-ui,sans-serif;color:#111827}.ub h2{margin:0 0 3px;font-size:22px}.ub .sub{color:#6b7280;font-size:13px;margin:0 0 14px}
.ub .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 16px;margin-bottom:14px}.ub .row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}.ub .fld{display:flex;flex-direction:column;gap:3px}.ub .fld label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase}
.ub input,.ub select{padding:8px 10px;border:1.5px solid #d1d5db;border-radius:8px;font:inherit;font-size:13px;max-width:100%}.ub .btn{padding:8px 16px;border:none;border-radius:8px;background:#1e3a8a;color:#fff;font:600 13px inherit;cursor:pointer;text-decoration:none;display:inline-flex;gap:6px;align-items:center}.ub .btn.o{background:#fff;color:#1e3a8a;border:1.5px solid #c7d2fe}.ub .btn.g{background:#16a34a}
.ub .kp{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,190px),1fr));gap:10px;margin-bottom:12px}.ub .k{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:10px 14px}.ub .k span{font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:700;display:block}.ub .k b{font-size:19px;white-space:nowrap}
.ub .tw{overflow:auto;border:1px solid #e5e7eb;border-radius:10px;background:#fff;max-height:72vh}.ub table{width:100%;border-collapse:collapse;font-size:13px}.ub th{background:#1e3a8a;color:#fff;padding:9px 10px;text-align:left;position:sticky;top:0;white-space:nowrap}.ub td{padding:7px 10px;border-bottom:1px solid #f1f5f9}.ub td.r,.ub th.r{text-align:right;font-variant-numeric:tabular-nums}.ub tfoot td{background:#fde68a;font-weight:800;position:sticky;bottom:0}
.ub .st{display:inline-block;padding:2px 10px;border-radius:99px;font-size:11.5px;font-weight:700;white-space:nowrap}.ub .st.ok{background:#dcfce7;color:#166534}.ub .st.pt{background:#fef3c7;color:#92400e}.ub .st.no{background:#fee2e2;color:#991b1b}.ub .st.na{color:#9ca3af}
</style>
<div class="ub">
  <h2><i class="fa-solid fa-building-columns" style="color:#1e3a8a"></i> Unilever Bank Transactions</h2>
  <p class="sub">Only the bank-statement rows of the <b>selected account</b> whose description is a Unilever counterparty (the same list Unilever Reconcile uses), with their reconcile status.</p>
  <?php if (!$accounts): ?><div class="card" style="color:#92400e;background:#fffbeb">No bank statements uploaded yet.</div><?php endif; ?>
  <form method="get" class="card"><div class="row">
    <div class="fld"><label>Bank account</label><select name="account"><?php foreach ($accounts as $id => $a): ?><option value="<?= $id ?>" <?= $f_acc === $id ? 'selected' : '' ?>><?= ub_h($a['label']) ?></option><?php endforeach; ?></select></div>
    <div class="fld"><label>Date from</label><input type="date" name="date_from" value="<?= ub_h($f_from) ?>"></div>
    <div class="fld"><label>Date to</label><input type="date" name="date_to" value="<?= ub_h($f_to) ?>"></div>
    <div class="fld"><label>Type</label><select name="dir"><option value="">Credit &amp; debit</option><option value="credit" <?= $f_dir === 'credit' ? 'selected' : '' ?>>Credits only</option><option value="debit" <?= $f_dir === 'debit' ? 'selected' : '' ?>>Debits only</option></select></div>
    <div class="fld"><label>Reconcile status</label><select name="rec"><option value="">All</option><option value="yes" <?= $f_rc === 'yes' ? 'selected' : '' ?>>Reconciled</option><option value="part" <?= $f_rc === 'part' ? 'selected' : '' ?>>Partly reconciled</option><option value="no" <?= $f_rc === 'no' ? 'selected' : '' ?>>Not reconciled</option></select></div>
    <div class="fld"><label>Search</label><input type="search" name="q" value="<?= ub_h($f_q) ?>" placeholder="description, reference, amount"></div>
    <button class="btn" type="submit"><i class="fa-solid fa-filter"></i> Show</button><a class="btn o" href="unilever_bank_transactions_report.php">Reset</a>
    <a class="btn g" data-no-loader href="?<?= ub_h(http_build_query(array_merge($_GET, ['export' => 1]))) ?>"><i class="fa-solid fa-file-excel"></i> Export Excel</a></div></form>
  <div class="kp"><div class="k"><span>Transactions</span><b><?= number_format($tot['n']) ?></b></div><div class="k"><span>Total credit</span><b style="color:#15803d">Rs. <?= number_format($tot['credit'], 2) ?></b></div><div class="k"><span>Total debit</span><b style="color:#b91c1c">Rs. <?= number_format($tot['debit'], 2) ?></b></div><div class="k"><span>Credit left to reconcile</span><b style="color:#b45309">Rs. <?= number_format($tot['left'], 2) ?></b></div></div>
  <div class="tw"><table><thead><tr><th>#</th><th>Date</th><th>Value Date</th><th>Description</th><th>Reference</th><th class="r">Debit</th><th class="r">Credit</th><th class="r">Balance</th><th>Status</th><th class="r">Left to Reconcile</th></tr></thead><tbody>
  <?php foreach ($rows as $n => $x): ?><tr><td><?= $n + 1 ?></td><td style="white-space:nowrap"><b><?= $x['transaction_date'] ? date('d M Y', strtotime($x['transaction_date'])) : '' ?></b></td><td style="white-space:nowrap"><?= $x['value_date'] ? date('d M Y', strtotime($x['value_date'])) : '' ?></td><td><?= ub_h($x['description']) ?></td><td style="font-size:12px;color:#6b7280"><?= ub_h($x['reference']) ?></td>
    <td class="r" style="color:#b91c1c"><?= (float)$x['debit'] > 0 ? ub_m($x['debit']) : '' ?></td><td class="r" style="color:#15803d"><?= (float)$x['credit'] > 0 ? ub_m($x['credit']) : '' ?></td><td class="r"><?= ub_m($x['balance']) ?></td><td><span class="st <?= $STL[$x['st']][1] ?>"><?= $STL[$x['st']][0] ?></span></td><td class="r"><?= $x['st'] === 'na' ? '' : number_format($x['left'], 2) ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="10" style="text-align:center;color:#9ca3af;padding:28px">No Unilever transactions for these filters.</td></tr><?php endif; ?></tbody>
  <?php if ($rows): ?><tfoot><tr><td colspan="5">TOTAL</td><td class="r"><?= number_format($tot['debit'], 2) ?></td><td class="r"><?= number_format($tot['credit'], 2) ?></td><td></td><td></td><td class="r"><?= number_format($tot['left'], 2) ?></td></tr></tfoot><?php endif; ?></table></div>
</div>
<?php include 'footer.php'; ?>
