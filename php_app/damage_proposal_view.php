<?php
include 'config.php';
include 'header.php';

$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: damage_proposal_history.php'); exit; }

$imp = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM damage_proposal_imports WHERE id = $id"));
if (!$imp) { echo '<div class="alert alert-error" style="margin:20px;"><i class="fa-solid fa-circle-xmark"></i> Import record not found.</div>'; include 'footer.php'; exit; }

$month_names = ['','January','February','March','April','May','June',
                'July','August','September','October','November','December'];

// ── Filters ───────────────────────────────────────────────────────────────────
$search_retailer = trim($_GET['search_retailer'] ?? '');
$search_product  = trim($_GET['search_product']  ?? '');
$search_ref      = trim($_GET['search_ref']      ?? '');
$page            = max(1, intval($_GET['page'] ?? 1));
$per_page        = 50;
$offset          = ($page - 1) * $per_page;

$where = "import_id = $id";
if ($search_retailer !== '') {
    $r = mysqli_real_escape_string($conn, $search_retailer);
    $where .= " AND (retailer_name LIKE '%$r%' OR retailer_code LIKE '%$r%')";
}
if ($search_product !== '') {
    $p = mysqli_real_escape_string($conn, $search_product);
    $where .= " AND (product_name LIKE '%$p%' OR product_code LIKE '%$p%')";
}
if ($search_ref !== '') {
    $rf = mysqli_real_escape_string($conn, $search_ref);
    $where .= " AND (trans_ref_no LIKE '%$rf%' OR credit_note_no LIKE '%$rf%')";
}

$total_rows = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM damage_proposal_transaction_details WHERE $where"))[0];
$total_pages = ceil($total_rows / $per_page);

$result = mysqli_query($conn, "SELECT * FROM damage_proposal_transaction_details WHERE $where ORDER BY id LIMIT $per_page OFFSET $offset");
$rows = [];
if ($result) while ($row = mysqli_fetch_assoc($result)) $rows[] = $row;

// Summary stats
$sum = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as cnt,
            COALESCE(SUM(qty_free_qty),0) as total_qty,
            COALESCE(SUM(total_tur_value),0) as total_tur,
            COALESCE(SUM(total_aip),0) as total_aip,
            COALESCE(SUM(crdr_amt),0) as total_cr,
            COUNT(DISTINCT retailer_code) as retailers,
            COUNT(DISTINCT product_code) as products
     FROM damage_proposal_transaction_details WHERE import_id=$id"));

function plink($page,$id,$extras=[]){
    return '?'.http_build_query(array_merge(['id'=>$id,'page'=>$page],$extras));
}
?>

<div class="page-header">
    <div class="breadcrumb">
        <a href="damage_proposal_history.php"><i class="fa-solid fa-arrow-left"></i> Import History</a>
        <span class="bc-sep">/</span>
        <span><?= $month_names[$imp['proposal_month']] ?> <?= $imp['proposal_year'] ?></span>
    </div>
    <h2 class="page-title">
        <i class="fa-solid fa-receipt"></i>
        <?= $month_names[$imp['proposal_month']] ?> <?= $imp['proposal_year'] ?> — Transaction Details
    </h2>
    <p class="page-subtitle">
        <?= htmlspecialchars($imp['filename']) ?> &nbsp;·&nbsp; Imported <?= date('d M Y H:i', strtotime($imp['imported_at'])) ?>
        <?= $imp['remarks'] ? ' &nbsp;·&nbsp; ' . htmlspecialchars($imp['remarks']) : '' ?>
    </p>
</div>

<!-- Summary tiles -->
<div class="tile-row">
    <div class="tile tile-blue">
        <div class="tile-lbl">Total Records</div>
        <div class="tile-val"><?= number_format($sum['cnt']) ?></div>
    </div>
    <div class="tile tile-purple">
        <div class="tile-lbl">Unique Retailers</div>
        <div class="tile-val"><?= number_format($sum['retailers']) ?></div>
    </div>
    <div class="tile tile-orange">
        <div class="tile-lbl">Unique Products</div>
        <div class="tile-val"><?= number_format($sum['products']) ?></div>
    </div>
    <div class="tile tile-teal">
        <div class="tile-lbl">Total Qty</div>
        <div class="tile-val"><?= number_format($sum['total_qty']) ?></div>
    </div>
    <div class="tile tile-amber">
        <div class="tile-lbl">Total TUR Value</div>
        <div class="tile-val"><?= number_format($sum['total_tur'],2) ?></div>
    </div>
    <div class="tile tile-green">
        <div class="tile-lbl">Total Cr/Dr Amt</div>
        <div class="tile-val"><?= number_format($sum['total_cr'],2) ?></div>
    </div>
</div>

<!-- Table card -->
<div class="content-card">
    <!-- Search -->
    <form method="GET" class="search-bar">
        <input type="hidden" name="id"   value="<?= $id ?>">
        <input type="hidden" name="page" value="1">
        <input type="text" name="search_retailer" class="form-input" placeholder="Retailer name / code…" value="<?= htmlspecialchars($search_retailer) ?>">
        <input type="text" name="search_product"  class="form-input" placeholder="Product name / code…"  value="<?= htmlspecialchars($search_product) ?>">
        <input type="text" name="search_ref"      class="form-input" placeholder="Trans ref / credit note…" value="<?= htmlspecialchars($search_ref) ?>">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-search"></i> Search</button>
        <a href="?id=<?= $id ?>" class="btn btn-secondary btn-sm"><i class="fa-solid fa-rotate-left"></i></a>
    </form>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>TSO PLG</th>
                    <th>Trans Type</th>
                    <th>Trans Ref No</th>
                    <th>Trans Date</th>
                    <th>Retailer Code</th>
                    <th>Retailer Name</th>
                    <th>Product Code</th>
                    <th>Product Name</th>
                    <th>Return Type</th>
                    <th>App Reason</th>
                    <th>PKM</th>
                    <th>Batch Code</th>
                    <th>Qty</th>
                    <th>AIP</th>
                    <th>MRP</th>
                    <th>TUR</th>
                    <th>Total TUR Val</th>
                    <th>Total AIP</th>
                    <th>Credit Note No</th>
                    <th>Credit Note Dt</th>
                    <th>Cr/Dr Amt</th>
                    <th>Adj Bill — Billing</th>
                    <th>Adj Bill — Collection</th>
                    <th>Original Bill No</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($rows) > 0): $n = $offset + 1; foreach ($rows as $r): ?>
                <tr>
                    <td class="td-seq"><?= $n++ ?></td>
                    <td class="td-center"><span class="tso-pill"><?= htmlspecialchars($r['tso_plg'] ?? '') ?></span></td>
                    <td class="td-type"><?= htmlspecialchars($r['transaction_type'] ?? '') ?></td>
                    <td class="td-ref"><?= htmlspecialchars($r['trans_ref_no'] ?? '') ?></td>
                    <td class="td-date"><?= $r['trans_date'] ? date('d M Y', strtotime($r['trans_date'])) : '—' ?></td>
                    <td class="td-code"><?= htmlspecialchars($r['retailer_code'] ?? '') ?></td>
                    <td class="td-name"><?= htmlspecialchars($r['retailer_name'] ?? '') ?></td>
                    <td class="td-code"><?= htmlspecialchars($r['product_code'] ?? '') ?></td>
                    <td class="td-name"><?= htmlspecialchars($r['product_name'] ?? '') ?></td>
                    <td class="td-rtype"><?= htmlspecialchars($r['return_type'] ?? '') ?></td>
                    <td class="td-small"><?= htmlspecialchars($r['app_reason'] ?? '—') ?></td>
                    <td class="td-center td-mono"><?= htmlspecialchars($r['pkm'] ?? '') ?></td>
                    <td class="td-center td-mono"><?= htmlspecialchars($r['batch_code'] ?? '') ?></td>
                    <td class="td-num"><?= $r['qty_free_qty'] !== null ? number_format($r['qty_free_qty']) : '—' ?></td>
                    <td class="td-num"><?= $r['aip'] !== null ? number_format($r['aip'],4) : '—' ?></td>
                    <td class="td-num"><?= $r['mrp'] !== null ? number_format($r['mrp'],2) : '—' ?></td>
                    <td class="td-num"><?= $r['tur'] !== null ? number_format($r['tur'],3) : '—' ?></td>
                    <td class="td-num td-amt"><?= $r['total_tur_value'] !== null ? number_format($r['total_tur_value'],2) : '—' ?></td>
                    <td class="td-num td-amt"><?= $r['total_aip'] !== null ? number_format($r['total_aip'],2) : '—' ?></td>
                    <td class="td-ref"><?= htmlspecialchars($r['credit_note_no'] ?? '') ?></td>
                    <td class="td-date"><?= $r['credit_note_dt'] ? date('d M Y', strtotime($r['credit_note_dt'])) : '—' ?></td>
                    <td class="td-num td-amt td-cr"><?= $r['crdr_amt'] !== null ? number_format($r['crdr_amt'],2) : '—' ?></td>
                    <td class="td-ref"><?= htmlspecialchars($r['adj_bill_ref_billing'] ?? '—') ?></td>
                    <td class="td-ref"><?= htmlspecialchars($r['adj_bill_ref_collection'] ?? '—') ?></td>
                    <td class="td-ref"><?= htmlspecialchars($r['original_bill_no'] ?? '—') ?></td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="25" class="empty-state">
                    <i class="fa-solid fa-inbox"></i>
                    <p>No records found<?= ($search_retailer||$search_product||$search_ref) ? ' for this search.' : '.' ?></p>
                </td></tr>
                <?php endif; ?>
            </tbody>
            <?php if (count($rows) > 0): ?>
            <tfoot>
                <tr class="tfoot-row">
                    <td colspan="13" style="text-align:right;font-weight:700;font-size:12px;color:#6b7280;padding:10px 12px;">
                        Page totals (<?= count($rows) ?> rows):
                    </td>
                    <td class="td-num td-foot"><?= number_format(array_sum(array_column($rows,'qty_free_qty'))) ?></td>
                    <td colspan="3"></td>
                    <td class="td-num td-foot td-amt"><?= number_format(array_sum(array_column($rows,'total_tur_value')),2) ?></td>
                    <td class="td-num td-foot td-amt"><?= number_format(array_sum(array_column($rows,'total_aip')),2) ?></td>
                    <td colspan="2"></td>
                    <td class="td-num td-foot td-amt td-cr"><?= number_format(array_sum(array_column($rows,'crdr_amt')),2) ?></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <span class="page-info">Showing <?= number_format($offset+1) ?>–<?= number_format(min($offset+$per_page,$total_rows)) ?> of <?= number_format($total_rows) ?> records</span>
        <div class="page-btns">
            <?php if ($page > 1): ?>
            <a href="<?= plink($page-1,$id,['search_retailer'=>$search_retailer,'search_product'=>$search_product,'search_ref'=>$search_ref]) ?>" class="pg-btn">‹ Prev</a>
            <?php endif; ?>
            <?php for ($p=max(1,$page-2);$p<=min($total_pages,$page+2);$p++): ?>
            <a href="<?= plink($p,$id,['search_retailer'=>$search_retailer,'search_product'=>$search_product,'search_ref'=>$search_ref]) ?>" class="pg-btn <?= $p==$page?'pg-active':'' ?>"><?= $p ?></a>
            <?php endfor; ?>
            <?php if ($page < $total_pages): ?>
            <a href="<?= plink($page+1,$id,['search_retailer'=>$search_retailer,'search_product'=>$search_product,'search_ref'=>$search_ref]) ?>" class="pg-btn">Next ›</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
.page-header{margin-bottom:24px}
.breadcrumb{font-size:13px;color:#6b7280;margin-bottom:8px;display:flex;align-items:center;gap:6px}
.breadcrumb a{color:#1e40af;text-decoration:none;display:flex;align-items:center;gap:5px}
.breadcrumb a:hover{text-decoration:underline}
.bc-sep{color:#d1d5db}
.page-title{font-size:22px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:10px;margin:0 0 6px}
.page-subtitle{color:#6b7280;font-size:13px;margin:0}
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:24px;margin-bottom:20px}
.alert-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:13px 16px;border-radius:8px;display:flex;align-items:center;gap:8px;font-size:14px}

/* Tiles */
.tile-row{display:grid;grid-template-columns:repeat(6,1fr);gap:14px;margin-bottom:20px}
.tile{border-radius:10px;padding:16px 14px;display:flex;flex-direction:column;gap:4px}
.tile-lbl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;opacity:.7}
.tile-val{font-size:20px;font-weight:800}
.tile-blue  {background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
.tile-purple{background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe}
.tile-orange{background:#fff7ed;color:#c2410c;border:1px solid #fed7aa}
.tile-teal  {background:#f0fdfa;color:#0f766e;border:1px solid #99f6e4}
.tile-amber {background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.tile-green {background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}

/* Search */
.search-bar{display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;align-items:center}
.search-bar .form-input{flex:1;min-width:160px;padding:9px 14px;border:1px solid #e5e5e5;border-radius:8px;font-size:13px;font-family:inherit}
.search-bar .form-input:focus{outline:none;border-color:#000}

/* Table */
.table-responsive{overflow-x:auto}
.data-table{width:100%;border-collapse:collapse;font-size:12px}
.data-table thead th{background:#fafafa;padding:9px 10px;text-align:left;font-size:11px;font-weight:700;color:#374151;border-bottom:2px solid #e5e5e5;white-space:nowrap}
.data-table tbody tr{border-bottom:1px solid #f0f0f0}
.data-table tbody tr:hover{background:#fafafa}
.data-table td{padding:9px 10px;color:#333;vertical-align:middle}
.td-seq{color:#9ca3af;font-size:11px;width:30px}
.td-num{text-align:right;font-family:'JetBrains Mono','Fira Code',monospace;font-size:11.5px;white-space:nowrap}
.td-amt{font-weight:700}
.td-cr{color:#166534}
.td-date{white-space:nowrap;font-size:11px;color:#6b7280}
.td-ref{font-family:'JetBrains Mono','Fira Code',monospace;font-size:11px;white-space:nowrap;color:#374151}
.td-code{font-size:11px;white-space:nowrap;color:#374151}
.td-name{max-width:170px;font-size:12px}
.td-type{white-space:nowrap;font-weight:600;font-size:11px}
.td-rtype{max-width:150px;font-size:11px;color:#6b7280}
.td-small{font-size:11px;color:#6b7280}
.td-center{text-align:center}
.td-mono{font-family:'JetBrains Mono','Fira Code',monospace;font-size:11px}
.tso-pill{background:#f3f4f6;padding:2px 8px;border-radius:8px;font-size:11px;font-weight:700;white-space:nowrap}

/* Foot row */
.tfoot-row{background:#fafafa;border-top:2px solid #e5e5e5}
.td-foot{font-weight:700;font-size:12px;color:#1f2937}

/* Buttons */
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .2s}
.btn-sm{padding:8px 14px;font-size:12px}
.btn-primary{background:#000;color:#fff}
.btn-primary:hover{background:#333}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}
.btn-secondary:hover{background:#e5e5e5}

/* Pagination */
.pagination{display:flex;justify-content:space-between;align-items:center;margin-top:16px;flex-wrap:wrap;gap:10px}
.page-info{font-size:13px;color:#6b7280}
.page-btns{display:flex;gap:4px}
.pg-btn{padding:6px 12px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;font-weight:600;color:#374151;text-decoration:none;transition:all .2s}
.pg-btn:hover{background:#f0f0f0}
.pg-active{background:#000;color:#fff;border-color:#000}

/* Empty */
.empty-state{text-align:center;padding:50px 20px;color:#999}
.empty-state i{font-size:40px;display:block;margin-bottom:10px;color:#ddd}
.empty-state p{font-size:13px}

@media(max-width:1100px){.tile-row{grid-template-columns:repeat(3,1fr)}}
@media(max-width:600px){.tile-row{grid-template-columns:repeat(2,1fr)}}
</style>
<?php include 'footer.php'; ?>
