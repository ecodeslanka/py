<?php
include 'config.php';
include 'header.php';

$import_id = intval($_GET['id'] ?? 0);
if (!$import_id) {
    echo '<div class="alert alert-error"><i class="fa-solid fa-circle-xmark"></i> Invalid import ID.</div>';
    include 'footer.php'; exit;
}

$imp_q = mysqli_query($conn, "SELECT * FROM ulcl_imports WHERE id=$import_id LIMIT 1");
$imp   = $imp_q ? mysqli_fetch_assoc($imp_q) : null;
if (!$imp) {
    echo '<div class="alert alert-error"><i class="fa-solid fa-circle-xmark"></i> Import record not found.</div>';
    include 'footer.php'; exit;
}

// Search & pagination
$search   = trim($_GET['search'] ?? '');
$txn_filter = trim($_GET['txn_type'] ?? '');
$page     = max(1, intval($_GET['page'] ?? 1));
$per_page = 50;
$offset   = ($page - 1) * $per_page;

$sw = "import_id = $import_id";
if ($search !== '') {
    $s  = mysqli_real_escape_string($conn, $search);
    $sw .= " AND (transaction_type LIKE '%$s%' OR customer_reference LIKE '%$s%')";
}
if ($txn_filter !== '') {
    $t  = mysqli_real_escape_string($conn, $txn_filter);
    $sw .= " AND transaction_type='$t'";
}

$count_q    = mysqli_query($conn, "SELECT COUNT(*) c FROM ulcl_ledger WHERE $sw");
$total_rows = $count_q ? (int)mysqli_fetch_assoc($count_q)['c'] : 0;
$total_pages= max(1, (int)ceil($total_rows / $per_page));

$rows_q = mysqli_query($conn, "SELECT * FROM ulcl_ledger WHERE $sw ORDER BY id ASC LIMIT $per_page OFFSET $offset");
$rows = [];
if ($rows_q) while ($r = mysqli_fetch_assoc($rows_q)) $rows[] = $r;

// Unique transaction types for filter
$types_q = mysqli_query($conn, "SELECT DISTINCT transaction_type FROM ulcl_ledger WHERE import_id=$import_id ORDER BY transaction_type");
$txn_types = [];
if ($types_q) while ($t = mysqli_fetch_assoc($types_q)) $txn_types[] = $t['transaction_type'];

// Totals for this import view
$tot_q = mysqli_query($conn, "SELECT
    COALESCE(SUM(debit),0)  total_debit,
    COALESCE(SUM(credit),0) total_credit
    FROM ulcl_ledger WHERE $sw");
$totals = $tot_q ? mysqli_fetch_assoc($tot_q) : ['total_debit'=>0,'total_credit'=>0];

function fmt($v,$dec=2){
    if($v===null||$v==='') return '<span style="color:#ccc">—</span>';
    $n=(float)$v;
    if($n==0) return '<span style="color:#ccc">0.00</span>';
    return number_format($n,$dec);
}
function fmtStr($v){
    if($v===null||$v==='') return '<span style="color:#ccc">—</span>';
    return htmlspecialchars($v);
}
function statusBadge($s){
    $m=['completed'=>['badge-success','fa-circle-check'],'processing'=>['badge-warning','fa-spinner fa-spin'],'failed'=>['badge-error','fa-circle-xmark']];
    [$c,$i]=$m[$s]??['badge-inactive','fa-clock'];
    return "<span class='badge $c'><i class='fa-solid $i'></i> ".ucfirst($s)."</span>";
}
function txnBadge($t){
    $t=strtolower($t);
    if(str_contains($t,'billing')&&str_contains($t,'rtn'))
        return 'txn-return';
    if(str_contains($t,'billing'))
        return 'txn-billing';
    if(str_contains($t,'cheque')||str_contains($t,'presented'))
        return 'txn-cheque';
    if(str_contains($t,'opening'))
        return 'txn-open';
    if(str_contains($t,'closing'))
        return 'txn-close';
    if(str_contains($t,'claims'))
        return 'txn-claims';
    if(str_contains($t,'rtgs')||str_contains($t,'partial'))
        return 'txn-rtgs';
    return 'txn-other';
}
?>

<div class="page-header">
    <div>
        <h2 class="page-title"><i class="fa-solid fa-eye"></i> Customer Ledger — Detail View</h2>
        <p class="page-subtitle">
            <span class="co-badge <?= $imp['company']==='ULCL'?'co-ulcl':'co-usll' ?>"><?= $imp['company'] ?></span>
            &nbsp;<?= htmlspecialchars($imp['filename']) ?>
        </p>
    </div>
    <a href="ulcl_history.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to History</a>
</div>

<!-- Summary cards -->
<div class="summary-grid">
    <div class="summary-card">
        <div class="sum-label">Company</div>
        <div class="sum-value"><span class="co-badge <?= $imp['company']==='ULCL'?'co-ulcl':'co-usll' ?>"><?= $imp['company'] ?></span></div>
    </div>
    <div class="summary-card">
        <div class="sum-label">Entry Date</div>
        <div class="sum-value date-val"><i class="fa-solid fa-calendar-day"></i><?= date('d M Y', strtotime($imp['entry_date'])) ?></div>
    </div>
    <div class="summary-card">
        <div class="sum-label">Imported At</div>
        <div class="sum-value"><?= date('d M Y H:i', strtotime($imp['imported_at'])) ?></div>
    </div>
    <div class="summary-card sc-green">
        <div class="sum-label">Imported</div>
        <div class="sum-value" style="color:#166534;"><?= number_format($imp['imported_records']) ?></div>
    </div>
    <div class="summary-card sc-yellow">
        <div class="sum-label">Duplicates Skipped</div>
        <div class="sum-value" style="color:#92400e;"><?= number_format($imp['duplicate_records']) ?></div>
    </div>
    <?php if($imp['failed_records']>0): ?>
    <div class="summary-card sc-red">
        <div class="sum-label">Failed</div>
        <div class="sum-value" style="color:#991b1b;"><?= number_format($imp['failed_records']) ?></div>
    </div>
    <?php endif; ?>
    <div class="summary-card">
        <div class="sum-label">Status</div>
        <div class="sum-value"><?= statusBadge($imp['status']) ?></div>
    </div>
    <div class="summary-card sc-blue">
        <div class="sum-label">Total Debit (filtered)</div>
        <div class="sum-value" style="color:#991b1b;font-size:14px;"><?= number_format((float)$totals['total_debit'],2) ?></div>
    </div>
    <div class="summary-card sc-green">
        <div class="sum-label">Total Credit (filtered)</div>
        <div class="sum-value" style="color:#166534;font-size:14px;"><?= number_format((float)$totals['total_credit'],2) ?></div>
    </div>
</div>

<!-- Search + Table -->
<div class="content-card">
    <div class="card-header-row">
        <h3 class="card-title">
            <i class="fa-solid fa-table-list"></i> Ledger Records
            <span class="record-count"><?= number_format($total_rows) ?></span>
        </h3>
        <form method="GET" class="search-form">
            <input type="hidden" name="id" value="<?= $import_id ?>">
            <select name="txn_type" class="form-input" style="width:190px;font-size:13px;padding:8px 12px;" onchange="this.form.submit()">
                <option value="">All Transaction Types</option>
                <?php foreach($txn_types as $tt): ?>
                <option value="<?= htmlspecialchars($tt) ?>" <?= $txn_filter===$tt?'selected':'' ?>>
                    <?= htmlspecialchars($tt) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <div class="search-wrap">
                <i class="fa-solid fa-search search-icon"></i>
                <input type="text" name="search" class="search-input"
                       placeholder="Search reference or type…"
                       value="<?= htmlspecialchars($search) ?>">
                <?php if($search): ?>
                <a href="ulcl_view.php?id=<?= $import_id ?><?= $txn_filter?'&txn_type='.urlencode($txn_filter):'' ?>" class="search-clear" title="Clear">
                    <i class="fa-solid fa-xmark"></i>
                </a>
                <?php endif; ?>
            </div>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-search"></i></button>
        </form>
    </div>

    <?php if(($search||$txn_filter)&&$total_rows===0): ?>
    <div class="empty-state">
        <i class="fa-solid fa-magnifying-glass"></i>
        <p>No records found for the applied filters.</p>
        <a href="ulcl_view.php?id=<?= $import_id ?>" class="btn btn-secondary btn-sm">Clear Filters</a>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Txn Date</th>
                    <th>Transaction Type</th>
                    <th>Customer Reference</th>
                    <th>Debit</th>
                    <th>Credit</th>
                    <th>Balance</th>
                </tr>
            </thead>
            <tbody>
                <?php $n=$offset+1; foreach($rows as $row):
                    $txnCls = txnBadge($row['transaction_type']??'');
                ?>
                <tr>
                    <td class="td-seq"><?= $n++ ?></td>
                    <td class="td-date">
                        <?= $row['txn_date'] ? date('d M Y', strtotime($row['txn_date'])) : '<span style="color:#ccc">—</span>' ?>
                    </td>
                    <td><span class="txn-badge <?= $txnCls ?>"><?= fmtStr($row['transaction_type']) ?></span></td>
                    <td class="td-ref"><?= fmtStr($row['customer_reference']) ?></td>
                    <td class="td-num td-debit"><?= fmt($row['debit']) ?></td>
                    <td class="td-num td-credit"><?= fmt($row['credit']) ?></td>
                    <td class="td-num td-bal <?= (float)$row['balance']<0?'td-bal-neg':'' ?>"><?= fmt($row['balance']) ?></td>
                </tr>
                <?php endforeach; ?>
                <!-- Totals row -->
                <tr class="totals-row">
                    <td colspan="4" style="text-align:right;font-weight:700;font-size:12px;color:#374151;padding-right:16px;">
                        Page Totals:
                    </td>
                    <td class="td-num td-debit"><?= number_format(array_sum(array_column($rows,'debit')),2) ?></td>
                    <td class="td-num td-credit"><?= number_format(array_sum(array_column($rows,'credit')),2) ?></td>
                    <td></td>
                </tr>
            </tbody>
        </table>
    </div>

    <?php if($total_pages>1): ?>
    <div class="pagination">
        <span class="page-info">
            Showing <?= number_format($offset+1) ?>–<?= number_format(min($offset+$per_page,$total_rows)) ?>
            of <?= number_format($total_rows) ?> records
        </span>
        <div class="page-links">
            <?php
            $base="ulcl_view.php?id=$import_id".($search?'&search='.urlencode($search):'').($txn_filter?'&txn_type='.urlencode($txn_filter):'');
            if($page>1): ?>
            <a href="<?= $base ?>&page=<?= $page-1 ?>" class="page-btn"><i class="fa-solid fa-chevron-left"></i></a>
            <?php endif;
            $st=max(1,$page-2); $en=min($total_pages,$page+2);
            if($st>1): ?>
                <a href="<?= $base ?>&page=1" class="page-btn">1</a>
                <?php if($st>2): ?><span class="page-dots">…</span><?php endif;
            endif;
            for($p=$st;$p<=$en;$p++): ?>
            <a href="<?= $base ?>&page=<?= $p ?>" class="page-btn <?= $p===$page?'active':'' ?>"><?= $p ?></a>
            <?php endfor;
            if($en<$total_pages): ?>
                <?php if($en<$total_pages-1): ?><span class="page-dots">…</span><?php endif; ?>
                <a href="<?= $base ?>&page=<?= $total_pages ?>" class="page-btn"><?= $total_pages ?></a>
            <?php endif;
            if($page<$total_pages): ?>
            <a href="<?= $base ?>&page=<?= $page+1 ?>" class="page-btn"><i class="fa-solid fa-chevron-right"></i></a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<style>
.page-header{margin-bottom:24px;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px}
.page-title{font-size:22px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:10px;margin:0 0 6px}
.page-subtitle{color:#6b7280;font-size:14px;margin:0;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.co-badge{font-size:11px;font-weight:700;padding:3px 10px;border-radius:8px;white-space:nowrap}
.co-ulcl{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
.co-usll{background:#fdf4ff;color:#7e22ce;border:1px solid #e9d5ff}
.summary-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin-bottom:20px}
.summary-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:14px 16px}
.sc-green{border-left:3px solid #22c55e}
.sc-red{border-left:3px solid #ef4444}
.sc-yellow{border-left:3px solid #f59e0b}
.sc-blue{border-left:3px solid #3b82f6}
.sum-label{font-size:10px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px}
.sum-value{font-size:15px;font-weight:700;color:#1f2937}
.date-val{display:flex;align-items:center;gap:6px;color:#1e40af}
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:24px;margin-bottom:20px}
.card-header-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px}
.card-title{font-size:16px;font-weight:600;color:#1f2937;display:flex;align-items:center;gap:8px;margin:0}
.record-count{background:#eff6ff;color:#1e40af;font-size:11px;padding:2px 8px;border-radius:99px;font-weight:700}
.search-form{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.search-wrap{position:relative;display:flex;align-items:center}
.search-icon{position:absolute;left:10px;color:#9ca3af;font-size:13px;pointer-events:none}
.search-input{padding:9px 14px 9px 32px;border:1px solid #e5e5e5;border-radius:8px;font-size:13px;font-family:inherit;width:220px;box-sizing:border-box}
.search-input:focus{outline:none;border-color:#000}
.search-clear{position:absolute;right:8px;color:#9ca3af;text-decoration:none;font-size:14px;display:flex;align-items:center}
.search-clear:hover{color:#ef4444}
.form-input{padding:9px 12px;border:1px solid #e5e5e5;border-radius:7px;font-family:inherit;background:#fff}
.form-input:focus{outline:none;border-color:#000}
.table-responsive{overflow-x:auto}
.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table thead th{background:#fafafa;padding:10px 12px;text-align:left;font-size:11px;font-weight:700;color:#374151;border-bottom:2px solid #e5e5e5;white-space:nowrap}
.data-table tbody tr{border-bottom:1px solid #f0f0f0}
.data-table tbody tr:hover{background:#fafafa}
.data-table td{padding:11px 12px;color:#333;vertical-align:middle;white-space:nowrap}
.totals-row td{background:#f8fafc;border-top:2px solid #e5e5e5;padding:12px}
.td-seq{color:#9ca3af;font-size:11px}
.td-date{color:#6b7280;font-size:12px}
.td-ref{font-family:monospace;font-size:12px}
.td-num{text-align:right;font-variant-numeric:tabular-nums;font-weight:600}
.td-debit{color:#991b1b}
.td-credit{color:#166534}
.td-bal{color:#1e40af}
.td-bal-neg{color:#991b1b}
/* Transaction type badges */
.txn-badge{display:inline-block;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:600}
.txn-billing{background:#eff6ff;color:#1e40af}
.txn-return{background:#fef2f2;color:#991b1b}
.txn-cheque{background:#f0fdf4;color:#166534}
.txn-open{background:#f5f3ff;color:#7e22ce}
.txn-close{background:#fafafa;color:#374151}
.txn-claims{background:#fef3c7;color:#92400e}
.txn-rtgs{background:#ecfdf5;color:#065f46}
.txn-other{background:#f5f5f5;color:#6b7280}
.badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:600;white-space:nowrap}
.badge-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.badge-warning{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.badge-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.badge-inactive{background:#fafafa;color:#666;border:1px solid #e5e5e5}
.pagination{display:flex;justify-content:space-between;align-items:center;margin-top:20px;padding-top:16px;border-top:1px solid #f0f0f0;flex-wrap:wrap;gap:10px}
.page-info{font-size:13px;color:#6b7280}
.page-links{display:flex;gap:4px;align-items:center}
.page-btn{display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;padding:0 8px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;color:#374151;text-decoration:none;transition:all .2s;background:#fff}
.page-btn:hover{background:#f5f5f5}
.page-btn.active{background:#000;color:#fff;border-color:#000}
.page-dots{color:#9ca3af;font-size:13px;padding:0 4px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .2s}
.btn-sm{padding:8px 14px;font-size:13px}
.btn-primary{background:#000;color:#fff}
.btn-primary:hover{background:#333}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}
.btn-secondary:hover{background:#e5e5e5}
.alert{padding:13px 16px;border-radius:8px;margin-bottom:16px;display:flex;align-items:center;gap:10px;font-size:14px}
.alert-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.empty-state{text-align:center;padding:60px 20px;color:#999}
.empty-state i{font-size:48px;display:block;margin-bottom:12px;color:#ddd}
.empty-state p{margin-bottom:16px;font-size:14px}
@media(max-width:768px){.summary-grid{grid-template-columns:1fr 1fr}.page-header{flex-direction:column}}
</style>
<?php include 'footer.php'; ?>
