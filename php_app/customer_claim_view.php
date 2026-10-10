<?php
/**
 * customer_claim_view.php
 * Detailed view of a single Customer Claim Certificate import batch
 */
ob_start();
include 'config.php';
ob_end_clean();
include 'header.php';

$import_id = intval($_GET['id'] ?? 0);
if (!$import_id) {
    header('Location: customer_claim_history.php');
    exit;
}

$import = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT * FROM customer_claim_imports WHERE id = $import_id"
));
if (!$import) {
    header('Location: customer_claim_history.php');
    exit;
}

/* ── Filters ─────────────────────────────────────────────────────────────── */
$f_scheme    = trim($_GET['f_scheme']    ?? '');
$f_invoice   = trim($_GET['f_invoice']  ?? '');
$f_entity    = trim($_GET['f_entity']   ?? '');
$f_claimtype = trim($_GET['f_claimtype']?? '');
$f_ledger    = trim($_GET['f_ledger']   ?? '');
$f_desc      = trim($_GET['f_desc']     ?? '');

$where = ["import_id = $import_id"];
if ($f_scheme)    $where[] = "scheme_code LIKE '%" . mysqli_real_escape_string($conn, $f_scheme)    . "%'";
if ($f_invoice)   $where[] = "tax_invoice_no LIKE '%" . mysqli_real_escape_string($conn, $f_invoice) . "%'";
if ($f_entity)    $where[] = "entity = '"           . mysqli_real_escape_string($conn, $f_entity)    . "'";
if ($f_claimtype) $where[] = "claim_type = '"       . mysqli_real_escape_string($conn, $f_claimtype) . "'";
if ($f_ledger)    $where[] = "ledger_type = '"      . mysqli_real_escape_string($conn, $f_ledger)    . "'";
if ($f_desc)      $where[] = "claim_description LIKE '%" . mysqli_real_escape_string($conn, $f_desc) . "%'";
$where_sql = 'WHERE ' . implode(' AND ', $where);

/* ── Pagination ──────────────────────────────────────────────────────────── */
$page     = max(1, intval($_GET['page'] ?? 1));
$per_page = 50;
$offset   = ($page - 1) * $per_page;

$total_rows  = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) AS cnt FROM customer_claim_certificates $where_sql"
))['cnt'] ?? 0;
$total_pages = max(1, ceil($total_rows / $per_page));

$rows = mysqli_query($conn,
    "SELECT * FROM customer_claim_certificates $where_sql ORDER BY id ASC LIMIT $per_page OFFSET $offset"
);

/* ── Summary aggregates ──────────────────────────────────────────────────── */
$agg = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        COUNT(*)                        AS total_rows,
        COUNT(DISTINCT scheme_code)     AS unique_schemes,
        COUNT(DISTINCT entity)          AS unique_entities,
        COUNT(DISTINCT claim_type)      AS unique_claim_types,
        COUNT(DISTINCT tax_invoice_no)  AS unique_invoices,
        SUM(CASE WHEN scheme_code != '' AND scheme_code IS NOT NULL THEN 1 ELSE 0 END) AS with_scheme,
        SUM(actual_amount)              AS total_actual,
        SUM(vat_amount)                 AS total_vat,
        SUM(actual_amount + vat_amount) AS total_with_vat
    FROM customer_claim_certificates
    WHERE import_id = $import_id
"));

/* ── Filter dropdown options ─────────────────────────────────────────────── */
$entities    = mysqli_query($conn, "SELECT DISTINCT entity FROM customer_claim_certificates WHERE import_id = $import_id AND entity != '' ORDER BY entity");
$claimtypes  = mysqli_query($conn, "SELECT DISTINCT claim_type FROM customer_claim_certificates WHERE import_id = $import_id AND claim_type != '' ORDER BY claim_type");
$ledgertypes = mysqli_query($conn, "SELECT DISTINCT ledger_type FROM customer_claim_certificates WHERE import_id = $import_id AND ledger_type != '' ORDER BY ledger_type");
?>

<style>
:root{--teal:#1a7f8e;--teal2:#155f6c;--green:#16a34a;--red:#dc2626;--amber:#d97706;--gray2:#e2e8f0;--gray3:#94a3b8;--shadow:0 2px 8px rgba(0,0,0,.10);}

.back-link{display:inline-flex;align-items:center;gap:6px;font-size:.82rem;color:#64748b;text-decoration:none;margin-bottom:8px;}
.back-link:hover{color:var(--teal);}
.pg-title{font-size:1.35rem;font-weight:700;color:#1e293b;margin:0 0 3px;display:flex;align-items:center;gap:8px;}
.pg-sub{font-size:.83rem;color:#64748b;margin:0;}

.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:12px;font-size:.72rem;font-weight:600;}
.b-green {background:#dcfce7;color:#166534;}
.b-amber {background:#fef3c7;color:#92400e;}
.b-red   {background:#fee2e2;color:#991b1b;}
.b-blue  {background:#dbeafe;color:#1d4ed8;}
.b-teal  {background:#ccfbf1;color:#0f766e;}
.b-gray  {background:#f1f5f9;color:#64748b;}

/* Summary grid */
.sum-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(148px,1fr));gap:12px;margin:18px 0;}
.scard{background:#fff;border:1px solid var(--gray2);border-radius:8px;padding:13px 16px;text-align:center;}
.scard .sv{display:block;font-size:1.25rem;font-weight:700;color:#1e293b;margin-bottom:3px;}
.scard .sl{display:block;font-size:.70rem;color:var(--gray3);font-weight:600;text-transform:uppercase;}
.scard.hi{border-top:3px solid var(--teal);} .scard.hi .sv{color:var(--teal);}
.scard.hg{border-top:3px solid var(--green);} .scard.hg .sv{color:var(--green);}
.scard.ha{border-top:3px solid var(--amber);} .scard.ha .sv{color:var(--amber);}

/* Filter bar */
.filter-card{background:#fff;border:1px solid var(--gray2);border-radius:8px;padding:16px 18px;margin-bottom:16px;}
.filter-form{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;}
.fg label{font-size:.73rem;font-weight:600;color:#475569;display:block;margin-bottom:3px;}
.fg input,.fg select{height:34px;padding:0 10px;border:1px solid var(--gray2);border-radius:6px;font-size:.82rem;background:#f8fafc;min-width:140px;}
.fg input[type=text]{min-width:160px;}
.btn-f{height:34px;padding:0 16px;background:var(--teal);color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:.82rem;font-weight:600;}
.btn-c{height:34px;padding:0 12px;background:#fff;color:#475569;border:1px solid var(--gray2);border-radius:6px;cursor:pointer;font-size:.82rem;text-decoration:none;display:inline-flex;align-items:center;}

/* Table */
.tbl-card{background:#fff;border:1px solid var(--gray2);border-radius:10px;overflow:hidden;box-shadow:var(--shadow);}
.tbl-head{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid var(--gray2);flex-wrap:wrap;gap:8px;}
.tbl-head h3{margin:0;font-size:.93rem;color:#1e293b;display:flex;align-items:center;gap:7px;}
.tbl-scroll{overflow-x:auto;}
.dtbl{width:100%;border-collapse:collapse;font-size:.78rem;white-space:nowrap;}
.dtbl thead th{background:var(--teal);color:#fff;padding:10px 12px;text-align:left;font-weight:600;font-size:.74rem;}
.dtbl thead th.r{text-align:right;}
.dtbl tbody tr:nth-child(even){background:#f8fafc;}
.dtbl tbody tr:hover{background:#e0f2f1;}
.dtbl tbody td{padding:8px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
.dtbl tbody td.r{text-align:right;font-variant-numeric:tabular-nums;}
.empty-row{text-align:center;color:#94a3b8;padding:40px!important;font-size:.88rem;}

.scheme-tag{font-size:.7rem;background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:10px;font-weight:700;}

/* Pagination */
.pager{display:flex;gap:6px;justify-content:center;margin:16px 8px 8px;flex-wrap:wrap;}
.pg-btn{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;padding:0 8px;border-radius:6px;border:1px solid var(--gray2);background:#fff;color:#333;text-decoration:none;font-size:.82rem;font-weight:600;}
.pg-btn:hover{background:#f0f0f0;}
.pg-btn.active{background:var(--teal);color:#fff;border-color:var(--teal);}
.pg-ellipsis{color:#94a3b8;padding:0 4px;line-height:34px;}

@media(max-width:800px){.sum-grid{grid-template-columns:repeat(2,1fr);}}
</style>

<!-- Back + title -->
<div style="margin-bottom:18px;">
    <a href="customer_claim_history.php" class="back-link">
        <i class="fa-solid fa-arrow-left"></i> Import History
    </a>
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div>
            <h2 class="pg-title">
                <i class="fa-solid fa-file-certificate" style="color:var(--teal);"></i>
                Import #<?php echo $import_id; ?> — Claim Certificates
            </h2>
            <p class="pg-sub">
                <i class="fa-solid fa-file-excel" style="color:#22c55e;"></i>
                <?php echo htmlspecialchars(basename($import['filename'])); ?>
                &nbsp;·&nbsp; Import Date: <strong><?php echo date('d M Y', strtotime($import['import_date'])); ?></strong>
                &nbsp;·&nbsp; Imported At: <?php echo date('d M Y H:i', strtotime($import['imported_at'])); ?>
                <?php if ($import['remarks']): ?>
                    &nbsp;·&nbsp; <em style="color:#64748b;"><?php echo htmlspecialchars($import['remarks']); ?></em>
                <?php endif; ?>
            </p>
        </div>
        <?php
        $sb = match($import['status']) {
            'completed'  => ['b-green','fa-circle-check','Completed'],
            'processing' => ['b-amber','fa-spinner','Processing'],
            'failed'     => ['b-red','fa-circle-xmark','Failed'],
            default      => ['b-gray','fa-clock',ucfirst($import['status'])],
        };
        ?>
        <span class="badge <?php echo $sb[0]; ?>" style="font-size:.8rem;padding:7px 14px;">
            <i class="fa-solid <?php echo $sb[1]; ?>"></i> <?php echo $sb[2]; ?>
        </span>
    </div>
</div>

<!-- Summary cards -->
<div class="sum-grid">
    <div class="scard hi">
        <span class="sv"><?php echo number_format($agg['total_rows']); ?></span>
        <span class="sl">Total Records</span>
    </div>
    <div class="scard hi">
        <span class="sv"><?php echo number_format($agg['unique_invoices']); ?></span>
        <span class="sl">Unique Invoices</span>
    </div>
    <div class="scard hi">
        <span class="sv"><?php echo number_format($agg['unique_schemes']); ?></span>
        <span class="sl">Unique Schemes</span>
    </div>
    <div class="scard hi">
        <span class="sv"><?php echo number_format($agg['with_scheme']); ?></span>
        <span class="sl">With Scheme Code</span>
    </div>
    <div class="scard hi">
        <span class="sv"><?php echo number_format($agg['unique_entities']); ?></span>
        <span class="sl">Entities</span>
    </div>
    <div class="scard hi">
        <span class="sv"><?php echo number_format($agg['unique_claim_types']); ?></span>
        <span class="sl">Claim Types</span>
    </div>
    <div class="scard hg">
        <span class="sv"><?php echo number_format($agg['total_actual'], 2); ?></span>
        <span class="sl">Total Actual Amount</span>
    </div>
    <div class="scard ha">
        <span class="sv"><?php echo number_format($agg['total_vat'], 2); ?></span>
        <span class="sl">Total VAT Amount</span>
    </div>
    <div class="scard hi" style="border-top-color:#6366f1;">
        <span class="sv" style="color:#4f46e5;"><?php echo number_format($agg['total_with_vat'], 2); ?></span>
        <span class="sl">Total (Actual + VAT)</span>
    </div>
</div>

<!-- Filters -->
<div class="filter-card">
    <form method="GET" class="filter-form">
        <input type="hidden" name="id" value="<?php echo $import_id; ?>">
        <div class="fg">
            <label>Scheme Code</label>
            <input type="text" name="f_scheme" value="<?php echo htmlspecialchars($f_scheme); ?>" placeholder="e.g. 41426909">
        </div>
        <div class="fg">
            <label>Tax Invoice No</label>
            <input type="text" name="f_invoice" value="<?php echo htmlspecialchars($f_invoice); ?>" placeholder="Invoice number…">
        </div>
        <div class="fg">
            <label>Description</label>
            <input type="text" name="f_desc" value="<?php echo htmlspecialchars($f_desc); ?>" placeholder="Search description…" style="min-width:180px;">
        </div>
        <div class="fg">
            <label>Entity</label>
            <select name="f_entity">
                <option value="">— All —</option>
                <?php while ($r = mysqli_fetch_assoc($entities)): ?>
                    <option value="<?php echo htmlspecialchars($r['entity']); ?>" <?php echo $f_entity===$r['entity']?'selected':''; ?>>
                        <?php echo htmlspecialchars($r['entity']); ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="fg">
            <label>Claim Type</label>
            <select name="f_claimtype">
                <option value="">— All —</option>
                <?php while ($r = mysqli_fetch_assoc($claimtypes)): ?>
                    <option value="<?php echo htmlspecialchars($r['claim_type']); ?>" <?php echo $f_claimtype===$r['claim_type']?'selected':''; ?>>
                        <?php echo htmlspecialchars($r['claim_type']); ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="fg">
            <label>Ledger Type</label>
            <select name="f_ledger">
                <option value="">— All —</option>
                <?php while ($r = mysqli_fetch_assoc($ledgertypes)): ?>
                    <option value="<?php echo htmlspecialchars($r['ledger_type']); ?>" <?php echo $f_ledger===$r['ledger_type']?'selected':''; ?>>
                        <?php echo htmlspecialchars($r['ledger_type']); ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>
        <div style="display:flex;gap:7px;">
            <button type="submit" class="btn-f"><i class="fa fa-filter"></i> Filter</button>
            <a href="?id=<?php echo $import_id; ?>" class="btn-c"><i class="fa fa-xmark"></i> Clear</a>
        </div>
    </form>
</div>

<!-- Detail table -->
<div class="tbl-card">
    <div class="tbl-head">
        <h3><i class="fa-solid fa-table-list" style="color:var(--teal);"></i> Records</h3>
        <span style="font-size:.78rem;color:#94a3b8;">
            Showing <?php echo number_format(min($offset+1,$total_rows)); ?>–<?php echo number_format(min($offset+$per_page,$total_rows)); ?>
            of <?php echo number_format($total_rows); ?>
        </span>
    </div>
    <div class="tbl-scroll">
    <table class="dtbl">
        <thead>
            <tr>
                <th style="width:36px;">#</th>
                <th>No</th>
                <th>Ledger Type</th>
                <th>Status</th>
                <th>Claim Type</th>
                <th>Tax Invoice No</th>
                <th>Invoice Date</th>
                <th>Banking Date</th>
                <th>Entity</th>
                <th>Claim Description</th>
                <th>Scheme Code</th>
                <th class="r">Actual Amount</th>
                <th class="r">VAT Amount</th>
            </tr>
        </thead>
        <tbody>
        <?php if (mysqli_num_rows($rows) === 0): ?>
            <tr><td colspan="13" class="empty-row"><i class="fa-solid fa-inbox"></i> &nbsp;No records found for these filters.</td></tr>
        <?php endif; ?>
        <?php $rn = $offset + 1; while ($row = mysqli_fetch_assoc($rows)):
            $lt_lc = strtolower($row['ledger_type'] ?? '');
            $lt_badge = str_contains($lt_lc,'vend') || str_contains($lt_lc,'pega') ? 'b-amber' : 'b-teal';
            $ct_badge = match(true){
                str_contains(strtolower($row['claim_type']??''),'damage')     => 'b-red',
                str_contains(strtolower($row['claim_type']??''),'vat')        => 'b-amber',
                str_contains(strtolower($row['claim_type']??''),'drive')      => 'b-blue',
                str_contains(strtolower($row['claim_type']??''),'loyalty')    => 'b-blue',
                str_contains(strtolower($row['claim_type']??''),'incentive')  => 'b-green',
                str_contains(strtolower($row['claim_type']??''),'weekly')     => 'b-teal',
                default => 'b-gray',
            };
        ?>
            <tr>
                <td style="color:#94a3b8;font-size:.72rem;"><?php echo $rn++; ?></td>
                <td><?php echo htmlspecialchars($row['row_no'] ?? '—'); ?></td>
                <td>
                    <?php if ($row['ledger_type']): ?>
                        <span class="badge <?php echo $lt_badge; ?>"><?php echo htmlspecialchars($row['ledger_type']); ?></span>
                    <?php else: echo '<span style="color:#cbd5e1;">—</span>'; endif; ?>
                </td>
                <td>
                    <?php if ($row['status']): ?>
                        <span class="badge b-green"><?php echo htmlspecialchars($row['status']); ?></span>
                    <?php else: echo '<span style="color:#cbd5e1;">—</span>'; endif; ?>
                </td>
                <td>
                    <?php if ($row['claim_type']): ?>
                        <span class="badge <?php echo $ct_badge; ?>"><?php echo htmlspecialchars($row['claim_type']); ?></span>
                    <?php else: echo '<span style="color:#cbd5e1;">—</span>'; endif; ?>
                </td>
                <td style="font-family:monospace;font-size:.76rem;"><?php echo htmlspecialchars($row['tax_invoice_no'] ?? '—'); ?></td>
                <td><?php echo $row['invoice_date'] ? date('d M Y', strtotime($row['invoice_date'])) : '—'; ?></td>
                <td><?php echo $row['banking_date'] ? date('d M Y', strtotime($row['banking_date'])) : '—'; ?></td>
                <td>
                    <?php if ($row['entity']): ?>
                        <span class="badge b-gray"><?php echo htmlspecialchars($row['entity']); ?></span>
                    <?php else: echo '<span style="color:#cbd5e1;">—</span>'; endif; ?>
                </td>
                <td style="max-width:240px;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($row['claim_description']??''); ?>">
                    <?php echo htmlspecialchars(mb_substr($row['claim_description']??'—',0,40)); ?>
                    <?php echo mb_strlen($row['claim_description']??'')>40?'…':''; ?>
                </td>
                <td>
                    <?php if (!empty($row['scheme_code'])): ?>
                        <span class="scheme-tag"><?php echo htmlspecialchars($row['scheme_code']); ?></span>
                    <?php else: ?>
                        <span style="color:#cbd5e1;font-size:.72rem;">—</span>
                    <?php endif; ?>
                </td>
                <td class="r" style="font-weight:600;"><?php echo number_format((float)($row['actual_amount']??0),2); ?></td>
                <td class="r" style="color:var(--amber);"><?php echo number_format((float)($row['vat_amount']??0),2); ?></td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1):
        $qp = http_build_query(array_filter([
            'id'          => $import_id,
            'f_scheme'    => $f_scheme,
            'f_invoice'   => $f_invoice,
            'f_entity'    => $f_entity,
            'f_claimtype' => $f_claimtype,
            'f_ledger'    => $f_ledger,
            'f_desc'      => $f_desc,
        ]));
    ?>
    <div class="pager">
        <?php if ($page > 1): ?>
            <a href="?<?php echo $qp; ?>&page=<?php echo $page-1; ?>" class="pg-btn"><i class="fa fa-chevron-left"></i></a>
        <?php endif; ?>
        <?php
        $s = max(1,$page-2); $e = min($total_pages,$page+2);
        if ($s > 1) echo '<span class="pg-ellipsis">…</span>';
        for ($p=$s; $p<=$e; $p++):
        ?><a href="?<?php echo $qp; ?>&page=<?php echo $p; ?>" class="pg-btn <?php echo $p===$page?'active':''; ?>"><?php echo $p; ?></a><?php
        endfor;
        if ($e < $total_pages) echo '<span class="pg-ellipsis">…</span>';
        ?>
        <?php if ($page < $total_pages): ?>
            <a href="?<?php echo $qp; ?>&page=<?php echo $page+1; ?>" class="pg-btn"><i class="fa fa-chevron-right"></i></a>
        <?php endif; ?>
    </div>
    <p style="text-align:center;font-size:.74rem;color:#94a3b8;margin-bottom:10px;">
        Page <?php echo $page; ?> of <?php echo $total_pages; ?> &nbsp;·&nbsp; <?php echo number_format($total_rows); ?> records
    </p>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
