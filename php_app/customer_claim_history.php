<?php
/**
 * customer_claim_history.php
 * Lists all Customer Claim Certificate import batches
 */
ob_start();
include 'config.php';
ob_end_clean();
include 'header.php';

/* ── Filters ─────────────────────────────────────────────────────────────── */
$f_date_from = trim($_GET['date_from'] ?? '');
$f_date_to   = trim($_GET['date_to']   ?? '');
$f_status    = trim($_GET['status']    ?? '');
$f_filename  = trim($_GET['filename']  ?? '');

$where = ['1=1'];
if ($f_date_from) $where[] = "import_date >= '" . mysqli_real_escape_string($conn, $f_date_from) . "'";
if ($f_date_to)   $where[] = "import_date <= '" . mysqli_real_escape_string($conn, $f_date_to)   . "'";
if ($f_status)    $where[] = "status = '"        . mysqli_real_escape_string($conn, $f_status)    . "'";
if ($f_filename)  $where[] = "filename LIKE '%"  . mysqli_real_escape_string($conn, $f_filename)  . "%'";
$where_sql = implode(' AND ', $where);

/* ── Pagination ──────────────────────────────────────────────────────────── */
$page     = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset   = ($page - 1) * $per_page;

$total_rows  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM customer_claim_imports WHERE $where_sql"))['cnt'] ?? 0;
$total_pages = max(1, ceil($total_rows / $per_page));

$imports = mysqli_query($conn, "SELECT * FROM customer_claim_imports WHERE $where_sql ORDER BY imported_at DESC LIMIT $per_page OFFSET $offset");

/* ── Grand summary ───────────────────────────────────────────────────────── */
$grand = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        COUNT(*)                    AS total_batches,
        SUM(total_records)          AS total_records,
        SUM(imported_records)       AS total_imported,
        SUM(failed_records)         AS total_failed
    FROM customer_claim_imports
"));
?>

<style>
:root{--teal:#1a7f8e;--teal2:#155f6c;--green:#16a34a;--red:#dc2626;--amber:#d97706;--gray2:#e2e8f0;--gray3:#94a3b8;--shadow:0 2px 8px rgba(0,0,0,.10);}

.pg-header{background:linear-gradient(135deg,var(--teal),var(--teal2));color:#fff;padding:22px 28px;border-radius:10px;margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;}
.pg-header h2{margin:0 0 4px;font-size:1.5rem;}
.pg-header p{margin:0;opacity:.85;font-size:.9rem;}
.btn-new{background:#fff;color:var(--teal);border:none;border-radius:7px;padding:10px 20px;font-size:.85rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:7px;text-decoration:none;}
.btn-new:hover{background:#f0fdfa;}

.stat-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:20px;}
.scard{background:#fff;border:1px solid var(--gray2);border-radius:8px;padding:14px 16px;text-align:center;}
.scard-label{font-size:.72rem;color:var(--gray3);font-weight:600;text-transform:uppercase;margin-bottom:4px;}
.scard-val{font-size:1.3rem;font-weight:700;color:#1e293b;}
.scard.t-teal  {border-top:3px solid var(--teal); } .scard.t-teal  .scard-val{color:var(--teal);}
.scard.t-green {border-top:3px solid var(--green);} .scard.t-green .scard-val{color:var(--green);}
.scard.t-red   {border-top:3px solid var(--red);  } .scard.t-red   .scard-val{color:var(--red);}
.scard.t-amber {border-top:3px solid var(--amber);} .scard.t-amber .scard-val{color:var(--amber);}

.filter-bar{background:#fff;border:1px solid var(--gray2);border-radius:8px;padding:16px 20px;margin-bottom:18px;display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;}
.filter-bar label{font-size:.75rem;font-weight:600;color:#475569;display:block;margin-bottom:3px;}
.filter-bar input,.filter-bar select{height:36px;padding:0 10px;border:1px solid var(--gray2);border-radius:6px;font-size:.83rem;background:#f8fafc;min-width:150px;}
.btn-filter{height:36px;padding:0 18px;background:var(--teal);color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:.83rem;font-weight:600;}
.btn-clear {height:36px;padding:0 14px;background:#fff;color:#475569;border:1px solid var(--gray2);border-radius:6px;cursor:pointer;font-size:.83rem;text-decoration:none;display:inline-flex;align-items:center;}

.tbl-card{background:#fff;border:1px solid var(--gray2);border-radius:10px;overflow:hidden;box-shadow:var(--shadow);}
.tbl-card table{width:100%;border-collapse:collapse;font-size:.82rem;}
.tbl-card thead th{background:var(--teal);color:#fff;padding:11px 12px;text-align:left;font-weight:600;font-size:.77rem;white-space:nowrap;}
.tbl-card thead th.r{text-align:right;}
.tbl-card tbody tr:nth-child(even){background:#f8fafc;}
.tbl-card tbody tr:hover{background:#e0f2f1;}
.tbl-card tbody td{padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
.tbl-card tbody td.r{text-align:right;font-variant-numeric:tabular-nums;}

.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:12px;font-size:.72rem;font-weight:600;}
.b-green {background:#dcfce7;color:#166534;}
.b-amber {background:#fef3c7;color:#92400e;}
.b-red   {background:#fee2e2;color:#991b1b;}
.b-blue  {background:#dbeafe;color:#1d4ed8;}
.b-gray  {background:#f1f5f9;color:#64748b;}

.prog-mini{height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;min-width:60px;}
.prog-mini-fill{height:6px;background:var(--green);border-radius:3px;}

.btn-view{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;background:var(--teal);color:#fff;border-radius:5px;text-decoration:none;font-size:.75rem;font-weight:600;}
.btn-view:hover{background:var(--teal2);}
.btn-del{display:inline-flex;align-items:center;gap:5px;padding:5px 10px;background:#fff;color:var(--red);border:1px solid #fca5a5;border-radius:5px;text-decoration:none;font-size:.75rem;font-weight:600;cursor:pointer;}
.btn-del:hover{background:#fee2e2;}

.pager{display:flex;gap:6px;justify-content:center;margin:18px 0 4px;flex-wrap:wrap;}
.pg-btn{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;padding:0 8px;border-radius:6px;border:1px solid var(--gray2);background:#fff;color:#333;text-decoration:none;font-size:.82rem;font-weight:600;}
.pg-btn:hover{background:#f0f0f0;}
.pg-btn.active{background:var(--teal);color:#fff;border-color:var(--teal);}
.pg-ellipsis{color:#94a3b8;padding:0 4px;line-height:34px;}

.empty-row{text-align:center;color:#94a3b8;padding:40px!important;font-size:.9rem;}
</style>

<!-- Header -->
<div class="pg-header">
    <div>
        <h2><i class="fa-solid fa-clock-rotate-left"></i> &nbsp;Claim Certificates — Import History</h2>
        <p>All Customer Claim Certificate import batches</p>
    </div>
    <a href="customer_claim_import.php" class="btn-new">
        <i class="fa-solid fa-plus"></i> New Import
    </a>
</div>

<!-- Grand summary cards -->
<div class="stat-row">
    <div class="scard t-teal">
        <div class="scard-label">Total Batches</div>
        <div class="scard-val"><?php echo number_format($grand['total_batches']); ?></div>
    </div>
    <div class="scard t-teal">
        <div class="scard-label">Total Records</div>
        <div class="scard-val"><?php echo number_format($grand['total_records']); ?></div>
    </div>
    <div class="scard t-green">
        <div class="scard-label">Imported</div>
        <div class="scard-val"><?php echo number_format($grand['total_imported']); ?></div>
    </div>
    <div class="scard t-red">
        <div class="scard-label">Failed</div>
        <div class="scard-val"><?php echo number_format($grand['total_failed']); ?></div>
    </div>
</div>

<!-- Filters -->
<form method="GET" class="filter-bar">
    <div>
        <label>Filename</label>
        <input type="text" name="filename" value="<?php echo htmlspecialchars($f_filename); ?>" placeholder="Search filename…">
    </div>
    <div>
        <label>Status</label>
        <select name="status">
            <option value="">— All —</option>
            <option value="completed"  <?php echo $f_status==='completed'  ?'selected':''; ?>>Completed</option>
            <option value="processing" <?php echo $f_status==='processing' ?'selected':''; ?>>Processing</option>
            <option value="failed"     <?php echo $f_status==='failed'     ?'selected':''; ?>>Failed</option>
            <option value="pending"    <?php echo $f_status==='pending'    ?'selected':''; ?>>Pending</option>
        </select>
    </div>
    <div>
        <label>Date From</label>
        <input type="date" name="date_from" value="<?php echo htmlspecialchars($f_date_from); ?>">
    </div>
    <div>
        <label>Date To</label>
        <input type="date" name="date_to" value="<?php echo htmlspecialchars($f_date_to); ?>">
    </div>
    <div style="display:flex;gap:8px;">
        <button type="submit" class="btn-filter"><i class="fa fa-filter"></i> Filter</button>
        <a href="customer_claim_history.php" class="btn-clear"><i class="fa fa-xmark"></i> Clear</a>
    </div>
</form>

<!-- Table -->
<div class="tbl-card">
<table>
    <thead>
        <tr>
            <th style="width:36px;">#</th>
            <th>Import ID</th>
            <th>Filename</th>
            <th>Import Date</th>
            <th>Remarks</th>
            <th class="r">Total</th>
            <th class="r">Imported</th>
            <th class="r">Failed</th>
            <th>Progress</th>
            <th>Status</th>
            <th>Imported At</th>
            <th style="text-align:center;">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php if (mysqli_num_rows($imports) === 0): ?>
        <tr><td colspan="12" class="empty-row"><i class="fa-solid fa-inbox"></i> &nbsp;No import records found.</td></tr>
    <?php endif; ?>
    <?php $row_num = $offset + 1; while ($imp = mysqli_fetch_assoc($imports)):
        $pct = $imp['total_records'] > 0 ? min(100, round($imp['imported_records'] / $imp['total_records'] * 100)) : 0;
        $status_badge = match($imp['status']) {
            'completed'  => ['b-green', 'fa-circle-check',  'Completed'],
            'processing' => ['b-amber', 'fa-spinner',        'Processing'],
            'failed'     => ['b-red',   'fa-circle-xmark',  'Failed'],
            default      => ['b-gray',  'fa-clock',          ucfirst($imp['status'])],
        };
    ?>
        <tr>
            <td style="color:#94a3b8;font-size:.73rem;"><?php echo $row_num++; ?></td>
            <td><strong style="color:var(--teal);">#<?php echo $imp['id']; ?></strong></td>
            <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($imp['filename']); ?>">
                <i class="fa-solid fa-file-excel" style="color:#22c55e;margin-right:5px;"></i>
                <?php echo htmlspecialchars(basename($imp['filename'])); ?>
            </td>
            <td><?php echo date('d M Y', strtotime($imp['import_date'])); ?></td>
            <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#64748b;" title="<?php echo htmlspecialchars($imp['remarks']??''); ?>">
                <?php echo $imp['remarks'] ? htmlspecialchars($imp['remarks']) : '<span style="color:#cbd5e1;">—</span>'; ?>
            </td>
            <td class="r"><?php echo number_format($imp['total_records']); ?></td>
            <td class="r" style="color:var(--green);font-weight:600;"><?php echo number_format($imp['imported_records']); ?></td>
            <td class="r" style="color:<?php echo $imp['failed_records'] > 0 ? 'var(--red)' : '#94a3b8'; ?>;">
                <?php echo number_format($imp['failed_records']); ?>
            </td>
            <td>
                <div style="display:flex;align-items:center;gap:6px;min-width:80px;">
                    <div class="prog-mini" style="flex:1;">
                        <div class="prog-mini-fill" style="width:<?php echo $pct; ?>%;"></div>
                    </div>
                    <span style="font-size:.72rem;color:#64748b;white-space:nowrap;"><?php echo $pct; ?>%</span>
                </div>
            </td>
            <td>
                <span class="badge <?php echo $status_badge[0]; ?>">
                    <i class="fa-solid <?php echo $status_badge[1]; ?>"></i>
                    <?php echo $status_badge[2]; ?>
                </span>
            </td>
            <td style="color:#64748b;font-size:.78rem;white-space:nowrap;">
                <?php echo date('d M Y H:i', strtotime($imp['imported_at'])); ?>
            </td>
            <td style="text-align:center;white-space:nowrap;">
                <a href="customer_claim_view.php?id=<?php echo $imp['id']; ?>" class="btn-view">
                    <i class="fa-solid fa-eye"></i> View
                </a>
                &nbsp;
                <button class="btn-del" onclick="deleteImport(<?php echo $imp['id']; ?>, '<?php echo addslashes(basename($imp['filename'])); ?>')">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </td>
        </tr>
    <?php endwhile; ?>
    </tbody>
</table>

<!-- Pagination -->
<?php if ($total_pages > 1):
    $qp = http_build_query(array_filter(['date_from'=>$f_date_from,'date_to'=>$f_date_to,'status'=>$f_status,'filename'=>$f_filename]));
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
<p style="text-align:center;font-size:.75rem;color:#94a3b8;margin-top:6px;">
    Showing <?php echo number_format(min($offset+1,$total_rows)); ?>–<?php echo number_format(min($offset+$per_page,$total_rows)); ?> of <?php echo number_format($total_rows); ?> batches
</p>
<?php endif; ?>
</div>

<script>
function deleteImport(id, name) {
    if (!confirm('Delete import batch #' + id + ' (' + name + ') and ALL its records?\n\nThis cannot be undone.')) return;
    const fd = new FormData();
    fd.append('action', 'delete_import');
    fd.append('import_id', id);
    fetch('customer_claim_ajax.php', {method:'POST', body:fd})
    .then(r => r.json())
    .then(res => {
        if (res.success) { location.reload(); }
        else { alert('Delete failed: ' + res.message); }
    })
    .catch(() => alert('Network error'));
}
</script>

<?php include 'footer.php'; ?>
