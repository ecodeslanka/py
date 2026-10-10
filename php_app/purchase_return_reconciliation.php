<?php
include 'config.php';
include 'header.php';

// ── Get invoice number from URL ───────────────────────────────────────────────
$inv_no = trim($_GET['inv_no'] ?? '');
if (!$inv_no) {
    echo '<div class="alert alert-error"><i class="fa-solid fa-circle-xmark"></i> Invalid invoice number.</div>';
    include 'footer.php'; exit;
}
$inv_safe = mysqli_real_escape_string($conn, $inv_no);

// ── Tolerance for "nearly same" matching ──────────────────────────────────────
$tolerance = 10.00;

// ── Fetch the purchase return invoice summary ─────────────────────────────────
$pr_q = mysqli_query($conn,
    "SELECT
        reg.company_invoice_number,
        reg.company_invoice_date,
        COALESCE(SUM(det.net_amount), 0) AS total_net_amount,
        reg.reconciled,
        reg.reconciled_ledger_id,
        reg.reconciled_credit,
        reg.reconciled_company
     FROM purchase_return_register_details reg
     LEFT JOIN purchase_return_details det
       ON reg.purchase_return_no = det.purchase_ret_no
     WHERE reg.company_invoice_number = '$inv_safe'
     GROUP BY reg.company_invoice_number, reg.company_invoice_date,
              reg.reconciled, reg.reconciled_ledger_id,
              reg.reconciled_credit, reg.reconciled_company
     LIMIT 1");
$pr = $pr_q ? mysqli_fetch_assoc($pr_q) : null;

if (!$pr) {
    echo '<div class="alert alert-error"><i class="fa-solid fa-circle-xmark"></i> Purchase return invoice not found.</div>';
    include 'footer.php'; exit;
}

$pr_amount   = (float)$pr['total_net_amount'];
$is_reconciled = (int)$pr['reconciled'] === 1;

// ── Company filter ────────────────────────────────────────────────────────────
$company_filter = trim($_GET['company'] ?? '');

// ── Fetch matching ledger rows (SL GT Rtn Billing, both or filtered company) ──
$company_where = '';
if ($company_filter !== '') {
    $cf = mysqli_real_escape_string($conn, $company_filter);
    $company_where = "AND i.company = '$cf'";
}

$ledger_q = mysqli_query($conn,
    "SELECT
        l.id,
        l.txn_date,
        l.transaction_type,
        l.customer_reference,
        l.credit,
        l.debit,
        l.balance,
        i.company,
        i.filename,
        ABS(l.credit - $pr_amount) AS diff
     FROM ulcl_ledger l
     JOIN ulcl_imports i ON l.import_id = i.id
     WHERE l.transaction_type = 'SL GT Rtn Billing'
       AND l.reconciled_pr_inv IS NULL
       $company_where
       AND ABS(l.credit - $pr_amount) <= $tolerance
     ORDER BY ABS(l.credit - $pr_amount) ASC, l.txn_date DESC
     LIMIT 50");
$ledger_rows = [];
if ($ledger_q) while ($r = mysqli_fetch_assoc($ledger_q)) $ledger_rows[] = $r;

// ── Also fetch already-linked ledger row if reconciled ────────────────────────
$linked_row = null;
if ($is_reconciled && $pr['reconciled_ledger_id']) {
    $lid = (int)$pr['reconciled_ledger_id'];
    $lq  = mysqli_query($conn,
        "SELECT l.*, i.company, i.filename
         FROM ulcl_ledger l
         JOIN ulcl_imports i ON l.import_id = i.id
         WHERE l.id = $lid LIMIT 1");
    $linked_row = $lq ? mysqli_fetch_assoc($lq) : null;
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmtAmt($v) {
    if ($v === null || $v === '') return '<span class="empty-dash">—</span>';
    return number_format((float)$v, 2);
}
function fmtDate($v) {
    if (!$v) return '<span class="empty-dash">—</span>';
    $ts = strtotime($v);
    return $ts ? date('d M Y', $ts) : htmlspecialchars($v);
}
function diffBadge($diff) {
    $diff = (float)$diff;
    if ($diff == 0)      return '<span class="diff-badge diff-exact">Exact</span>';
    if ($diff <= 1)      return '<span class="diff-badge diff-close">±' . number_format($diff, 2) . '</span>';
    if ($diff <= 5)      return '<span class="diff-badge diff-near">±' . number_format($diff, 2) . '</span>';
    return '<span class="diff-badge diff-far">±' . number_format($diff, 2) . '</span>';
}
?>

<!-- ── Page Header ──────────────────────────────────────────────────────────── -->
<div class="page-header">
    <div>
        <h2 class="page-title">
            <i class="fa-solid fa-code-compare"></i> Reconciliation
        </h2>
        <p class="page-subtitle">
            Matching Purchase Return Invoice against Customer Ledger
            <span class="inv-chip"><?= htmlspecialchars($inv_no) ?></span>
        </p>
    </div>
    <a href="purchase_return_summary_report.php" class="btn btn-secondary">
        <i class="fa-solid fa-arrow-left"></i> Back to Summary
    </a>
</div>

<!-- ── Purchase Return Card ─────────────────────────────────────────────────── -->
<div class="pr-card <?= $is_reconciled ? 'pr-card-done' : '' ?>">
    <div class="pr-card-header">
        <div class="pr-card-label">
            <i class="fa-solid fa-file-invoice"></i> Purchase Return Invoice
        </div>
        <?php if ($is_reconciled): ?>
        <span class="status-badge status-reconciled">
            <i class="fa-solid fa-circle-check"></i> Reconciled
        </span>
        <?php else: ?>
        <span class="status-badge status-pending">
            <i class="fa-solid fa-clock"></i> Pending
        </span>
        <?php endif; ?>
    </div>
    <div class="pr-card-body">
        <div class="pr-field">
            <div class="pr-field-label">Invoice Number</div>
            <div class="pr-field-value mono"><?= htmlspecialchars($pr['company_invoice_number']) ?></div>
        </div>
        <div class="pr-field">
            <div class="pr-field-label">Invoice Date</div>
            <div class="pr-field-value"><?= fmtDate($pr['company_invoice_date']) ?></div>
        </div>
        <div class="pr-field">
            <div class="pr-field-label">Total Net Amount</div>
            <div class="pr-field-value amount-highlight"><?= fmtAmt($pr_amount) ?></div>
        </div>
        <?php if ($is_reconciled): ?>
        <div class="pr-field">
            <div class="pr-field-label">Linked Credit</div>
            <div class="pr-field-value amount-green"><?= fmtAmt($pr['reconciled_credit']) ?></div>
        </div>
        <div class="pr-field">
            <div class="pr-field-label">Company</div>
            <div class="pr-field-value">
                <span class="co-badge <?= $pr['reconciled_company']==='ULCL'?'co-ulcl':'co-usll' ?>">
                    <?= htmlspecialchars($pr['reconciled_company'] ?? '') ?>
                </span>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($is_reconciled && $linked_row): ?>
<!-- ── Already Reconciled View ──────────────────────────────────────────────── -->
<div class="recon-done-card">
    <div class="recon-done-header">
        <i class="fa-solid fa-link"></i> Linked Ledger Row
    </div>
    <div class="recon-done-body">
        <div class="recon-done-grid">
            <div>
                <div class="rd-label">Company</div>
                <div class="rd-val">
                    <span class="co-badge <?= $linked_row['company']==='ULCL'?'co-ulcl':'co-usll' ?>">
                        <?= htmlspecialchars($linked_row['company']) ?>
                    </span>
                </div>
            </div>
            <div>
                <div class="rd-label">Txn Date</div>
                <div class="rd-val"><?= fmtDate($linked_row['txn_date']) ?></div>
            </div>
            <div>
                <div class="rd-label">Transaction Type</div>
                <div class="rd-val"><span class="txn-badge txn-billing"><?= htmlspecialchars($linked_row['transaction_type']) ?></span></div>
            </div>
            <div>
                <div class="rd-label">Customer Reference</div>
                <div class="rd-val mono"><?= htmlspecialchars($linked_row['customer_reference'] ?? '—') ?></div>
            </div>
            <div>
                <div class="rd-label">Credit Amount</div>
                <div class="rd-val amount-green"><?= fmtAmt($linked_row['credit']) ?></div>
            </div>
            <div>
                <div class="rd-label">File</div>
                <div class="rd-val text-muted"><?= htmlspecialchars($linked_row['filename']) ?></div>
            </div>
        </div>
        <form method="POST" action="purchase_return_reconciliation_action.php"
              onsubmit="return confirm('Unlink this reconciliation? This will reset both records.')">
            <input type="hidden" name="action"     value="unlink">
            <input type="hidden" name="inv_no"     value="<?= htmlspecialchars($inv_no) ?>">
            <input type="hidden" name="ledger_id"  value="<?= (int)$pr['reconciled_ledger_id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm" style="margin-top:16px">
                <i class="fa-solid fa-link-slash"></i> Unlink Reconciliation
            </button>
        </form>
    </div>
</div>

<?php else: ?>
<!-- ── Company Filter ─────────────────────────────────────────────────────────── -->
<div class="filter-bar">
    <span class="filter-bar-label"><i class="fa-solid fa-filter"></i> Filter by Company:</span>
    <a href="?inv_no=<?= urlencode($inv_no) ?>"
       class="co-filter-btn <?= $company_filter===''?'active':'' ?>">Both</a>
    <a href="?inv_no=<?= urlencode($inv_no) ?>&company=ULCL"
       class="co-filter-btn co-ulcl-btn <?= $company_filter==='ULCL'?'active':'' ?>">ULCL</a>
    <a href="?inv_no=<?= urlencode($inv_no) ?>&company=USLL"
       class="co-filter-btn co-usll-btn <?= $company_filter==='USLL'?'active':'' ?>">USLL</a>
    <span class="tolerance-note">
        <i class="fa-solid fa-circle-info"></i>
        Showing ledger rows where credit is within ±<?= number_format($tolerance, 2) ?> of
        <strong><?= fmtAmt($pr_amount) ?></strong>
    </span>
</div>

<!-- ── Matching Ledger Rows ──────────────────────────────────────────────────── -->
<div class="content-card">
    <div class="card-header-row">
        <h3 class="card-title">
            <i class="fa-solid fa-table-list"></i>
            Matching Ledger Rows
            <span class="record-count"><?= count($ledger_rows) ?></span>
        </h3>
        <span class="txn-type-note">
            <span class="txn-badge txn-billing">SL GT Rtn Billing</span>
            &nbsp;— unlinked rows only
        </span>
    </div>

    <?php if (empty($ledger_rows)): ?>
    <div class="empty-state">
        <i class="fa-solid fa-magnifying-glass"></i>
        <p>No matching ledger rows found within ±<?= number_format($tolerance, 2) ?> tolerance.</p>
        <p class="text-muted" style="font-size:13px">
            Invoice amount: <strong><?= fmtAmt($pr_amount) ?></strong>
            &nbsp;|&nbsp; Range checked:
            <strong><?= fmtAmt($pr_amount - $tolerance) ?></strong> –
            <strong><?= fmtAmt($pr_amount + $tolerance) ?></strong>
        </p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Company</th>
                    <th>Txn Date</th>
                    <th>Transaction Type</th>
                    <th>Customer Reference</th>
                    <th class="th-num">Credit Amount</th>
                    <th class="th-num">PR Amount</th>
                    <th class="th-num">Difference</th>
                    <th>Source File</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ledger_rows as $i => $row): ?>
                <tr class="<?= $i===0?'row-best':'' ?>">
                    <td class="td-seq"><?= $i+1 ?></td>
                    <td>
                        <span class="co-badge <?= $row['company']==='ULCL'?'co-ulcl':'co-usll' ?>">
                            <?= htmlspecialchars($row['company']) ?>
                        </span>
                    </td>
                    <td class="td-date"><?= fmtDate($row['txn_date']) ?></td>
                    <td><span class="txn-badge txn-billing"><?= htmlspecialchars($row['transaction_type']) ?></span></td>
                    <td class="td-ref"><?= htmlspecialchars($row['customer_reference'] ?? '—') ?></td>
                    <td class="td-num td-credit"><?= fmtAmt($row['credit']) ?></td>
                    <td class="td-num td-pr-amt"><?= fmtAmt($pr_amount) ?></td>
                    <td class="td-num"><?= diffBadge($row['diff']) ?></td>
                    <td class="td-file text-muted"><?= htmlspecialchars($row['filename']) ?></td>
                    <td>
                        <form method="POST" action="purchase_return_reconciliation_action.php"
                              onsubmit="return confirm('Link invoice <?= htmlspecialchars(addslashes($inv_no)) ?> to this ledger row?\n\nCredit: <?= fmtAmt($row['credit']) ?>\nCompany: <?= $row['company'] ?>\n\nThis cannot be undone easily.')">
                            <input type="hidden" name="action"              value="link">
                            <input type="hidden" name="inv_no"             value="<?= htmlspecialchars($inv_no) ?>">
                            <input type="hidden" name="ledger_id"          value="<?= (int)$row['id'] ?>">
                            <input type="hidden" name="credit"             value="<?= (float)$row['credit'] ?>">
                            <input type="hidden" name="company"            value="<?= htmlspecialchars($row['company']) ?>">
                            <input type="hidden" name="customer_reference" value="<?= htmlspecialchars($row['customer_reference'] ?? '') ?>">
                            <input type="hidden" name="txn_date"           value="<?= htmlspecialchars($row['txn_date'] ?? '') ?>">
                            <input type="hidden" name="company_filter"     value="<?= htmlspecialchars($company_filter) ?>">
                            <button type="submit" class="btn btn-link-row <?= $i===0?'btn-link-best':'' ?>">
                                <i class="fa-solid fa-link"></i>
                                <?= $i===0 ? 'Link (Best Match)' : 'Link' ?>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<style>
/* ── Base ── */
.page-header{margin-bottom:24px;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px}
.page-title{font-size:22px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:10px;margin:0 0 6px}
.page-subtitle{color:#6b7280;font-size:14px;margin:0;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.inv-chip{background:#f1f5f9;border:1px solid #e2e8f0;color:#334155;padding:3px 10px;border-radius:6px;font-family:monospace;font-size:13px;font-weight:700}

/* ── PR Card ── */
.pr-card{background:#fff;border:1px solid #e5e5e5;border-radius:12px;margin-bottom:20px;overflow:hidden;border-top:4px solid #f59e0b}
.pr-card-done{border-top-color:#22c55e}
.pr-card-header{display:flex;justify-content:space-between;align-items:center;padding:14px 20px;background:#fafafa;border-bottom:1px solid #f0f0f0}
.pr-card-label{font-size:13px;font-weight:700;color:#374151;display:flex;align-items:center;gap:8px}
.pr-card-body{display:flex;flex-wrap:wrap;gap:0;padding:0}
.pr-field{padding:14px 20px;border-right:1px solid #f0f0f0;min-width:160px;flex:1}
.pr-field:last-child{border-right:none}
.pr-field-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px}
.pr-field-value{font-size:15px;font-weight:700;color:#1f2937}
.amount-highlight{color:#1e40af;font-size:18px}
.amount-green{color:#166534}
.mono{font-family:monospace}

/* ── Status badges ── */
.status-badge{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:99px;font-size:12px;font-weight:700}
.status-reconciled{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.status-pending{background:#fef3c7;color:#92400e;border:1px solid #fde68a}

/* ── Company badges ── */
.co-badge{font-size:11px;font-weight:700;padding:3px 10px;border-radius:8px;white-space:nowrap}
.co-ulcl{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
.co-usll{background:#fdf4ff;color:#7e22ce;border:1px solid #e9d5ff}

/* ── Filter bar ── */
.filter-bar{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.filter-bar-label{font-size:13px;font-weight:600;color:#374151}
.co-filter-btn{display:inline-flex;align-items:center;padding:6px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;border:1px solid #e5e5e5;background:#fff;color:#374151;transition:all .18s}
.co-filter-btn.active,.co-filter-btn:hover{background:#1f2937;color:#fff;border-color:#1f2937}
.co-ulcl-btn{border-color:#bfdbfe;color:#1e40af;background:#eff6ff}
.co-ulcl-btn.active{background:#1e40af;color:#fff;border-color:#1e40af}
.co-usll-btn{border-color:#e9d5ff;color:#7e22ce;background:#fdf4ff}
.co-usll-btn.active{background:#7e22ce;color:#fff;border-color:#7e22ce}
.tolerance-note{font-size:12px;color:#6b7280;margin-left:auto;background:#f9fafb;border:1px solid #e5e5e5;padding:5px 12px;border-radius:8px}

/* ── Content card ── */
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:24px;margin-bottom:20px}
.card-header-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px}
.card-title{font-size:16px;font-weight:600;color:#1f2937;display:flex;align-items:center;gap:8px;margin:0}
.record-count{background:#eff6ff;color:#1e40af;font-size:11px;padding:2px 8px;border-radius:99px;font-weight:700}
.txn-type-note{font-size:12px;color:#6b7280;display:flex;align-items:center;gap:6px}

/* ── Table ── */
.table-responsive{overflow-x:auto}
.data-table{width:100%;border-collapse:collapse;font-size:12px}
.data-table thead th{background:#fafafa;padding:10px 12px;text-align:left;font-size:11px;font-weight:700;color:#374151;border-bottom:2px solid #e5e5e5;white-space:nowrap}
.th-num{text-align:right!important}
.data-table tbody tr{border-bottom:1px solid #f0f0f0;transition:background .15s}
.data-table tbody tr:hover{background:#f9fafb}
.row-best{background:#f0fdf4!important}
.row-best:hover{background:#dcfce7!important}
.data-table td{padding:11px 12px;color:#333;vertical-align:middle;white-space:nowrap}
.td-seq{color:#9ca3af;font-size:11px;text-align:center;width:36px}
.td-date{color:#6b7280;font-size:12px}
.td-ref{font-family:monospace;font-size:12px;color:#374151}
.td-num{text-align:right;font-variant-numeric:tabular-nums;font-weight:600}
.td-credit{color:#166534;font-size:13px}
.td-pr-amt{color:#1e40af;font-size:13px}
.td-file{font-size:11px;max-width:160px;overflow:hidden;text-overflow:ellipsis}
.text-muted{color:#9ca3af}
.empty-dash{color:#d1d5db}

/* ── Diff badges ── */
.diff-badge{display:inline-block;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700}
.diff-exact{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.diff-close{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.diff-near{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.diff-far{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}

/* ── Transaction type badge ── */
.txn-badge{display:inline-block;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:600}
.txn-billing{background:#eff6ff;color:#1e40af}

/* ── Link buttons ── */
.btn-link-row{display:inline-flex;align-items:center;gap:5px;padding:6px 14px;background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap}
.btn-link-row:hover{background:#1f2937;color:#fff;border-color:#1f2937}
.btn-link-best{background:#166534;color:#fff;border-color:#166534}
.btn-link-best:hover{background:#14532d;border-color:#14532d}

/* ── Already reconciled card ── */
.recon-done-card{background:#fff;border:1px solid #bbf7d0;border-radius:12px;margin-bottom:20px;overflow:hidden}
.recon-done-header{padding:14px 20px;background:#f0fdf4;border-bottom:1px solid #bbf7d0;font-size:14px;font-weight:700;color:#166534;display:flex;align-items:center;gap:8px}
.recon-done-body{padding:20px}
.recon-done-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px}
.rd-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px}
.rd-val{font-size:14px;font-weight:600;color:#1f2937}

/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .2s}
.btn-sm{padding:7px 14px;font-size:13px}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}
.btn-secondary:hover{background:#e5e5e5}
.btn-danger{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.btn-danger:hover{background:#fee2e2}

/* ── Alert ── */
.alert{padding:13px 16px;border-radius:8px;margin-bottom:16px;display:flex;align-items:center;gap:10px;font-size:14px}
.alert-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}

/* ── Empty state ── */
.empty-state{text-align:center;padding:50px 20px;color:#999}
.empty-state i{font-size:42px;display:block;margin-bottom:12px;color:#ddd}
.empty-state p{margin-bottom:8px;font-size:14px}

@media(max-width:768px){
    .page-header{flex-direction:column}
    .pr-card-body{flex-direction:column}
    .pr-field{border-right:none;border-bottom:1px solid #f0f0f0}
    .filter-bar{flex-direction:column;align-items:flex-start}
    .tolerance-note{margin-left:0}
}
</style>

<?php include 'footer.php'; ?>