<?php
/**
 * visa_reconciliation_status_report.php (NEW v1.0)
 *
 * Consolidated report across ALL VISA reconciliation batches (not just one).
 * Lists every bank transaction row and classifies it as:
 *   - Not Reconciled        (no invoice linked yet)
 *   - Fully Reconciled      (linked invoice total exactly matches bank amount)
 *   - Partially Reconciled  (linked invoice total does NOT exactly match — e.g.
 *                            a bulk match confirmed despite a mismatch, or a
 *                            manually re-linked invoice of a different amount)
 *
 * Filter tabs (server-side, via ?status=): all | unreconciled | full | partial
 * Read-only report — does not modify any data. Link back to
 * visa_reconciliation.php to actually reconcile rows.
 */
include 'config.php';
ob_start();

$status = $_GET['status'] ?? 'all';
if (!in_array($status, ['all','unreconciled','full','partial'], true)) $status = 'all';

$q = trim($_GET['q'] ?? '');

/* ── Pull every transaction row across all batches, with its header + linked invoice ── */
$rows = [];
$res = mysqli_query($conn, "
  SELECT rr.id, rr.reconciliation_id, rr.trx_date, rr.card_number, rr.terminal,
         rr.auth_code, rr.amount, rr.comm, rr.net, rr.invoice_id, rr.matched, rr.bulk_invoice_ids,
         vr.filename, vr.mid, vr.location, vr.statement_date,
         inv.doc_no, inv.customer_name, inv.total_amount AS inv_amount, inv.invoice_date
  FROM visa_reconciliation_rows rr
  INNER JOIN visa_reconciliations vr ON vr.id = rr.reconciliation_id
  LEFT JOIN ushop_invoices inv ON inv.id = rr.invoice_id
  ORDER BY rr.trx_date DESC, rr.id DESC
");
while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;

/* ── Resolve bulk-linked invoice lists (for status calc + display) ── */
$bulk_ids_needed = [];
foreach ($rows as $r) {
  if (!empty($r['matched']) && !empty($r['bulk_invoice_ids'])) {
    foreach (explode(',', $r['bulk_invoice_ids']) as $bid) {
      $bid = (int)$bid;
      if ($bid > 0) $bulk_ids_needed[$bid] = true;
    }
  }
}
$bulk_invoice_map = [];
if (!empty($bulk_ids_needed)) {
  $id_list = implode(',', array_keys($bulk_ids_needed));
  $bres = mysqli_query($conn, "SELECT id, doc_no, customer_name, total_amount FROM ushop_invoices WHERE id IN ($id_list)");
  while ($b = mysqli_fetch_assoc($bres)) $bulk_invoice_map[(int)$b['id']] = $b;
}

/* ── Classify each row: unreconciled / full / partial ── */
foreach ($rows as &$r) {
  $isRec = !empty($r['matched']);
  $r['bulk_invoices'] = [];
  if (!$isRec) {
    $r['status'] = 'unreconciled';
    continue;
  }
  if (!empty($r['bulk_invoice_ids'])) {
    $sum = 0;
    foreach (explode(',', $r['bulk_invoice_ids']) as $bid) {
      $bid = (int)$bid;
      if (isset($bulk_invoice_map[$bid])) {
        $r['bulk_invoices'][] = $bulk_invoice_map[$bid];
        $sum += (float)$bulk_invoice_map[$bid]['total_amount'];
      }
    }
    $r['status'] = (abs((float)$r['amount'] - $sum) < 0.01) ? 'full' : 'partial';
  } else {
    $cmp = $r['inv_amount'] !== null ? (float)$r['inv_amount'] : null;
    if ($cmp === null) {
      $r['status'] = 'full'; // matched but no invoice row found (shouldn't normally happen)
    } else {
      $r['status'] = (abs((float)$r['amount'] - $cmp) < 0.01) ? 'full' : 'partial';
    }
  }
}
unset($r);

/* ── Counts (computed on the FULL set, independent of the active filter/search) ── */
$counts = ['all'=>count($rows), 'unreconciled'=>0, 'full'=>0, 'partial'=>0];
foreach ($rows as $r) $counts[$r['status']]++;

/* ── Apply search (doc no / customer / card / auth / MID) ── */
if ($q !== '') {
  $needle = mb_strtolower($q);
  $rows = array_values(array_filter($rows, function($r) use ($needle) {
    $hay = mb_strtolower(implode(' ', [
      $r['doc_no'] ?? '', $r['customer_name'] ?? '', $r['card_number'] ?? '',
      $r['auth_code'] ?? '', $r['mid'] ?? '', $r['location'] ?? '', $r['filename'] ?? '',
    ]));
    return mb_strpos($hay, $needle) !== false;
  }));
}

/* ── Apply status filter for display ── */
$filtered_rows = ($status === 'all') ? $rows : array_values(array_filter($rows, fn($r) => $r['status'] === $status));

/* ── Totals for the currently displayed (filtered) rows ── */
$sum_amount = 0; $sum_comm = 0; $sum_net = 0;
foreach ($filtered_rows as $r) { $sum_amount += (float)$r['amount']; $sum_comm += (float)$r['comm']; $sum_net += (float)$r['net']; }

include 'header.php';

function badge_for_status(string $s): string {
  if ($s === 'unreconciled') return '<span class="badge b-red"><i class="fa-solid fa-xmark"></i> Not Reconciled</span>';
  if ($s === 'partial')      return '<span class="badge b-amber"><i class="fa-solid fa-triangle-exclamation"></i> Partial Match</span>';
  return '<span class="badge b-green"><i class="fa-solid fa-check"></i> Fully Reconciled</span>';
}
function fmt2($n): string { return number_format((float)$n, 2); }
?>
<style>
*{box-sizing:border-box;}
:root{--teal:#0e7490;--teal2:#155e75;--green:#15803d;--amber:#92400e;--red:#dc2626;--border:#e5e7eb;--tx:#111827;--txs:#6b7280;}
.pw{max-width:1500px;margin:0 auto;padding:0 8px;}
.breadcrumb{display:flex;align-items:center;gap:6px;font-size:11.5px;color:#9ca3af;margin-bottom:14px;flex-wrap:wrap;}
.breadcrumb a{color:var(--teal);text-decoration:none;font-weight:600;}.breadcrumb a:hover{text-decoration:underline;}
.breadcrumb .sep{color:#d1d5db;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:8px;font-size:12.5px;font-weight:700;cursor:pointer;font-family:inherit;text-decoration:none;white-space:nowrap;}
.btn-teal{background:linear-gradient(135deg,var(--teal),var(--teal2));color:#fff;}
.btn-ghost{background:#f1f5f9;color:#374151;border:1px solid var(--border);}
.badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;}
.b-teal{background:#cffafe;color:var(--teal);}
.b-green{background:#dcfce7;color:var(--green);}
.b-amber{background:#fef3c7;color:var(--amber);}
.b-red{background:#fee2e2;color:var(--red);}
.b-blue{background:#dbeafe;color:#1e40af;}
.sum-strip{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:16px;}
.sum-box{background:#fff;border:1px solid var(--border);border-radius:9px;padding:11px 16px;flex:1;min-width:130px;border-top:3px solid var(--teal);}
.sum-box-label{font-size:10px;color:var(--txs);font-weight:700;text-transform:uppercase;letter-spacing:.4px;}
.sum-box-val{font-size:18px;font-weight:700;color:var(--teal);margin-top:3px;}
.status-tab-bar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;}
.status-tab{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border-radius:20px;border:1.5px solid var(--border);background:#fff;font-size:12.5px;font-weight:700;color:#374151;text-decoration:none;transition:all .15s;}
.status-tab:hover{background:#f1f5f9;}
.status-tab .cnt{background:#f1f5f9;color:#374151;padding:1px 8px;border-radius:10px;font-size:11px;min-width:18px;text-align:center;display:inline-block;}
.status-tab.active{color:#fff;border-color:transparent;}
.status-tab.active .cnt{background:rgba(255,255,255,.28);color:#fff;}
.status-tab[data-s="all"].active{background:#0f172a;}
.status-tab[data-s="unreconciled"].active{background:linear-gradient(135deg,var(--red),#991b1b);}
.status-tab[data-s="full"].active{background:linear-gradient(135deg,var(--green),#166534);}
.status-tab[data-s="partial"].active{background:linear-gradient(135deg,#f59e0b,#d97706);}
.search-bar{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;}
.search-bar input{padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:12.5px;font-family:inherit;flex:1;min-width:220px;}
.search-bar input:focus{border-color:var(--teal);outline:none;}
.card{background:#fff;border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:18px;box-shadow:0 1px 5px rgba(0,0,0,.04);}
.tbl-wrap{overflow-x:auto;}
.rep-tbl{width:100%;border-collapse:collapse;font-size:12px;}
.rep-tbl th{padding:9px 10px;background:#0f172a;color:#e2e8f0;font-size:10px;text-transform:uppercase;white-space:nowrap;position:sticky;top:0;}
.rep-tbl td{padding:9px 10px;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
.rep-tbl tr:hover td{background:#f0f9ff!important;}
.rep-tbl tr.row-unreconciled{background:#fff9f9;}
.rep-tbl tr.row-partial{background:#fffbeb;}
.rep-tbl tr.row-full{background:#f7fdf9;}
.rep-tbl tfoot td{padding:9px 10px;background:#0f172a;color:#e2e8f0;font-weight:700;position:sticky;bottom:0;}
.tr{text-align:right!important;}.tc{text-align:center!important;}
.batch-info{font-size:11px;color:var(--txs);}
.batch-info strong{color:var(--teal);}
.inv-info{font-size:11px;color:#374151;}
.inv-info strong{color:var(--green);}
.empty-state{text-align:center;padding:50px 20px;color:#9ca3af;font-size:13px;}
.empty-state i{font-size:32px;display:block;margin-bottom:10px;opacity:.35;}
@media print{.no-print{display:none!important;}}
</style>

<div class="pw">
<div class="breadcrumb">
  <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
  <span class="sep">›</span>
  <a href="visa_reconciliation.php"><i class="fa-brands fa-cc-visa"></i> VISA Reconciliation</a>
  <span class="sep">›</span>
  <span style="color:var(--teal);font-weight:700;"><i class="fa-solid fa-chart-column"></i> Status Report</span>
</div>

<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:18px;">
  <div>
    <h2 style="margin:0;font-size:19px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-chart-column" style="color:var(--teal);margin-right:8px;"></i>VISA Reconciliation Status Report
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:var(--txs);">Every VISA bank transaction across all uploaded statements, in one place — filter by reconciliation status.</p>
  </div>
  <div style="display:flex;gap:8px;" class="no-print">
    <a href="visa_reconciliation.php" class="btn btn-ghost"><i class="fa-solid fa-arrow-left"></i> Back to Reconciliation</a>
    <button class="btn btn-teal" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
  </div>
</div>

<!-- Status tabs -->
<div class="status-tab-bar">
  <a href="?status=all<?= $q!==''?'&q='.urlencode($q):'' ?>" class="status-tab <?= $status==='all'?'active':'' ?>" data-s="all">
    <i class="fa-solid fa-list"></i> All <span class="cnt"><?= $counts['all'] ?></span>
  </a>
  <a href="?status=unreconciled<?= $q!==''?'&q='.urlencode($q):'' ?>" class="status-tab <?= $status==='unreconciled'?'active':'' ?>" data-s="unreconciled">
    <i class="fa-solid fa-xmark" style="color:var(--red);"></i> Not Reconciled <span class="cnt"><?= $counts['unreconciled'] ?></span>
  </a>
  <a href="?status=full<?= $q!==''?'&q='.urlencode($q):'' ?>" class="status-tab <?= $status==='full'?'active':'' ?>" data-s="full">
    <i class="fa-solid fa-check" style="color:var(--green);"></i> Fully Reconciled <span class="cnt"><?= $counts['full'] ?></span>
  </a>
  <a href="?status=partial<?= $q!==''?'&q='.urlencode($q):'' ?>" class="status-tab <?= $status==='partial'?'active':'' ?>" data-s="partial">
    <i class="fa-solid fa-triangle-exclamation" style="color:var(--amber);"></i> Partially Reconciled <span class="cnt"><?= $counts['partial'] ?></span>
  </a>
</div>

<!-- Search -->
<form method="GET" class="search-bar no-print">
  <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
  <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search doc no, customer, card number, auth code, MID, location, filename…">
  <button type="submit" class="btn btn-teal"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
  <?php if ($q !== ''): ?><a href="?status=<?= htmlspecialchars($status) ?>" class="btn btn-ghost"><i class="fa-solid fa-xmark"></i> Clear</a><?php endif; ?>
</form>

<!-- Totals for current view -->
<div class="sum-strip">
  <div class="sum-box"><div class="sum-box-label">Rows Shown</div><div class="sum-box-val"><?= count($filtered_rows) ?></div></div>
  <div class="sum-box"><div class="sum-box-label">Total Amount</div><div class="sum-box-val"><?= fmt2($sum_amount) ?></div></div>
  <div class="sum-box"><div class="sum-box-label">Commission</div><div class="sum-box-val" style="color:var(--amber);"><?= fmt2($sum_comm) ?></div></div>
  <div class="sum-box"><div class="sum-box-label">Net</div><div class="sum-box-val"><?= fmt2($sum_net) ?></div></div>
</div>

<div class="card">
  <div class="tbl-wrap">
    <table class="rep-tbl">
      <thead>
        <tr>
          <th>#</th>
          <th>Batch</th>
          <th>TRX Date</th>
          <th>Card Number</th>
          <th>Auth Code</th>
          <th class="tr">Amount</th>
          <th class="tr">Comm</th>
          <th class="tr">Net</th>
          <th>Invoice / Match</th>
          <th class="tc">Status</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($filtered_rows)): ?>
        <tr><td colspan="10" class="empty-state"><i class="fa-solid fa-inbox"></i>No transactions found<?= $q!==''?' for "'.htmlspecialchars($q).'"':'' ?> in this filter.</td></tr>
      <?php else: $i=1; foreach ($filtered_rows as $r): ?>
        <tr class="row-<?= $r['status'] ?>">
          <td style="color:#9ca3af;font-size:11px;"><?= $i++ ?></td>
          <td class="batch-info">
            <strong><?= htmlspecialchars($r['mid'] ?: '—') ?></strong><br>
            <?= htmlspecialchars($r['location'] ?: '—') ?>
            <?php if ($r['statement_date']): ?> · <?= date('d M Y', strtotime($r['statement_date'])) ?><?php endif; ?>
          </td>
          <td><span class="badge b-teal"><?= $r['trx_date'] ? date('d M Y', strtotime($r['trx_date'])) : '—' ?></span></td>
          <td style="font-family:monospace;font-size:10.5px;"><?= htmlspecialchars($r['card_number'] ?? '') ?></td>
          <td><span class="badge b-blue"><?= htmlspecialchars($r['auth_code'] ?? '') ?></span></td>
          <td class="tr"><?= fmt2($r['amount']) ?></td>
          <td class="tr" style="color:var(--amber);"><?= fmt2($r['comm']) ?></td>
          <td class="tr" style="color:var(--teal);"><?= fmt2($r['net']) ?></td>
          <td style="min-width:220px;">
            <?php if ($r['status'] === 'unreconciled'): ?>
              <span style="color:#d1d5db;">— not linked —</span>
            <?php elseif (!empty($r['bulk_invoices'])): ?>
              <div class="inv-info">
                <strong><i class="fa-solid fa-layer-group" style="color:var(--green);"></i> <?= count($r['bulk_invoices']) ?> invoices (bulk-linked)</strong>
                <?php foreach ($r['bulk_invoices'] as $bi): ?>
                  <div style="margin-top:2px;">• <strong><?= htmlspecialchars($bi['doc_no']) ?></strong> — <?= htmlspecialchars($bi['customer_name'] ?: 'Walk-in') ?> — <?= fmt2($bi['total_amount']) ?></div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="inv-info">
                <strong><i class="fa-solid fa-check-circle" style="color:var(--green);"></i> <?= htmlspecialchars($r['doc_no'] ?: '—') ?></strong>
                <div style="color:var(--txs);margin-top:2px;"><?= htmlspecialchars($r['customer_name'] ?: 'Walk-in') ?> · <?= fmt2($r['inv_amount']) ?></div>
              </div>
            <?php endif; ?>
          </td>
          <td class="tc"><?= badge_for_status($r['status']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
      <?php if (!empty($filtered_rows)): ?>
      <tfoot>
        <tr>
          <td colspan="5">TOTAL (<?= count($filtered_rows) ?> row<?= count($filtered_rows)===1?'':'s' ?>)</td>
          <td class="tr"><?= fmt2($sum_amount) ?></td>
          <td class="tr"><?= fmt2($sum_comm) ?></td>
          <td class="tr"><?= fmt2($sum_net) ?></td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

</div><!-- .pw -->

<?php include 'footer.php'; ?>
