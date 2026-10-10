<?php
include 'config.php';

// ── AJAX ──────────────────────────────────────────────────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    if ($action === 'delete_upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id  = intval($_POST['id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT file_name FROM stock_uploads WHERE id=$id LIMIT 1"));
        $ok  = mysqli_query($conn, "DELETE FROM stock_uploads WHERE id=$id");
        if ($ok && $row) {
            $fp = __DIR__ . '/stock_uploads/' . $row['file_name'];
            if (file_exists($fp)) @unlink($fp);
        }
        echo json_encode(['success'=>(bool)$ok,'message'=>$ok?'':'DB error: '.mysqli_error($conn)]);
        exit;
    }

    if ($action === 'toggle_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id  = intval($_POST['id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT status FROM stock_uploads WHERE id=$id LIMIT 1"));
        $new = ($row['status'] === 'Active') ? 'Archived' : 'Active';
        $ok  = mysqli_query($conn, "UPDATE stock_uploads SET status='$new' WHERE id=$id");
        echo json_encode(['success'=>(bool)$ok,'new_status'=>$new]);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']); exit;
}

// ── PAGE DATA ─────────────────────────────────────────────────────
$f_status    = trim($_GET['f_status']    ?? '');
$f_date_from = trim($_GET['f_date_from'] ?? '');
$f_date_to   = trim($_GET['f_date_to']   ?? '');
$f_search    = trim($_GET['f_search']    ?? '');

$where = [];
if ($f_status)    $where[] = "status='".mysqli_real_escape_string($conn,$f_status)."'";
if ($f_date_from) $where[] = "deliver_date>='".mysqli_real_escape_string($conn,$f_date_from)."'";
if ($f_date_to)   $where[] = "deliver_date<='".mysqli_real_escape_string($conn,$f_date_to)."'";
if ($f_search)    $where[] = "(batch_ref LIKE '%".mysqli_real_escape_string($conn,$f_search)."%'
                               OR rs_name LIKE '%".mysqli_real_escape_string($conn,$f_search)."%'
                               OR notes   LIKE '%".mysqli_real_escape_string($conn,$f_search)."%')";
$where_sql = $where ? 'WHERE '.implode(' AND ',$where) : '';

$uploads = [];
$res = mysqli_query($conn, "SELECT * FROM stock_uploads $where_sql ORDER BY id DESC");
if ($res) while ($r = mysqli_fetch_assoc($res)) $uploads[] = $r;

$sum = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as cnt,
            COALESCE(SUM(total_rows),0)  as rows,
            COALESCE(SUM(total_units),0) as units,
            COALESCE(SUM(total_value),0) as value,
            SUM(CASE WHEN status='Active' THEN 1 ELSE 0 END) as active_cnt
     FROM stock_uploads"));

include 'header.php';
?>
<style>
*{box-sizing:border-box;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .18s;white-space:nowrap;}
.btn-sm{padding:6px 12px;font-size:12px;}
.btn-xs{padding:4px 9px;font-size:11px;border-radius:5px;}
.btn-primary{background:#0f172a;color:#fff;}.btn-primary:hover{background:#1e293b;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e0e0e0;}.btn-secondary:hover{background:#ececec;}
.btn-danger{background:#dc2626;color:#fff;}.btn-danger:hover{background:#b91c1c;}
.btn-teal{background:#0d9488;color:#fff;}.btn-teal:hover{background:#0f766e;}

.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:22px;}
.s-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 18px;position:relative;overflow:hidden;}
.s-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;}
.s-card.blue::before{background:linear-gradient(90deg,#3b82f6,#60a5fa);}
.s-card.teal::before{background:linear-gradient(90deg,#0d9488,#2dd4bf);}
.s-card.amber::before{background:linear-gradient(90deg,#f59e0b,#fbbf24);}
.s-card.green::before{background:linear-gradient(90deg,#22c55e,#4ade80);}
.s-label{font-size:10.5px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.s-value{font-size:21px;font-weight:800;color:#0f172a;line-height:1;}
.s-sub{font-size:11px;color:#9ca3af;margin-top:4px;}

.filter-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 18px;margin-bottom:18px;}
.filter-row{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;}
.ffg{display:flex;flex-direction:column;gap:4px;}
.ffg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;}
.fctrl{padding:8px 11px;border:1px solid #e0e0e0;border-radius:7px;font-size:13px;font-family:inherit;color:#111827;background:#fff;outline:none;}
.fctrl:focus{border-color:#0f172a;}

.table-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:13px 18px;border-bottom:1px solid #f0f0f0;gap:10px;flex-wrap:wrap;}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px;}
.dt-wrap{overflow-x:auto;max-height:68vh;overflow-y:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.data-table thead{position:sticky;top:0;z-index:5;}
.data-table thead th{padding:10px 11px;text-align:left;font-weight:700;font-size:11px;color:#f1f5f9;background:#0f172a;white-space:nowrap;}
.data-table thead th.tr{text-align:right;}.data-table thead th.tc{text-align:center;}
.data-table tbody tr{border-bottom:1px solid #f3f4f6;}
.data-table tbody tr:hover td{background:#f8fafc;}
.data-table td{padding:9px 11px;color:#374151;vertical-align:middle;background:#fff;}
.data-table td.tr{text-align:right;}.data-table td.tc{text-align:center;}
.data-table tfoot td{padding:10px 11px;font-weight:800;font-size:12px;background:#0f172a;color:#e2e8f0;}
.empty-state{text-align:center;padding:55px 20px;color:#9ca3af;}

.badge{display:inline-block;padding:2px 9px;border-radius:10px;font-size:10.5px;font-weight:700;}
.badge-active{background:#d1fae5;color:#065f46;}
.badge-archived{background:#f3f4f6;color:#6b7280;}
.batch-chip{background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;padding:2px 8px;font-size:11px;font-weight:700;color:#0f172a;font-family:monospace;}

#slToast{position:fixed;bottom:28px;right:28px;padding:12px 22px;border-radius:8px;font-size:14px;font-weight:600;color:#fff;z-index:99999;display:none;box-shadow:0 4px 16px rgba(0,0,0,.18);}
#slToast.success{background:#15803d;}
#slToast.error{background:#dc2626;}
</style>

<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:22px;">
    <div>
        <h2 class="page-title"><i class="fa-solid fa-layer-group"></i> Stock Upload History</h2>
        <p class="page-subtitle">All daily stock uploads — manage, view and delete batches</p>
    </div>
    <a href="stock_upload.php" class="btn btn-teal"><i class="fa-solid fa-file-arrow-up"></i> New Upload</a>
</div>

<div class="summary-grid">
    <div class="s-card blue"><div class="s-label">Total Batches</div><div class="s-value"><?php echo number_format($sum['cnt']); ?></div><div class="s-sub"><?php echo $sum['active_cnt']; ?> active</div></div>
    <div class="s-card teal"><div class="s-label">Total SKU Rows</div><div class="s-value"><?php echo number_format($sum['rows']); ?></div><div class="s-sub">All batches</div></div>
    <div class="s-card amber"><div class="s-label">Total Units</div><div class="s-value"><?php echo number_format($sum['units']); ?></div><div class="s-sub">All batches</div></div>
    <div class="s-card green"><div class="s-label">Total Stock Value</div><div class="s-value" style="font-size:14px;"><?php echo number_format($sum['value'],2); ?></div><div class="s-sub">Cumulative</div></div>
</div>

<div class="filter-card">
    <form method="GET">
        <div class="filter-row">
            <div class="ffg">
                <label>Search</label>
                <input type="text" name="f_search" class="fctrl" style="width:200px;" placeholder="Batch ref / RS name…" value="<?php echo htmlspecialchars($f_search); ?>">
            </div>
            <div class="ffg">
                <label>Deliver Date From</label>
                <input type="date" name="f_date_from" class="fctrl" value="<?php echo htmlspecialchars($f_date_from); ?>">
            </div>
            <div class="ffg">
                <label>Deliver Date To</label>
                <input type="date" name="f_date_to" class="fctrl" value="<?php echo htmlspecialchars($f_date_to); ?>">
            </div>
            <div class="ffg">
                <label>Status</label>
                <select name="f_status" class="fctrl" style="width:130px;">
                    <option value="">— All —</option>
                    <option value="Active"   <?php echo $f_status==='Active'  ?'selected':''; ?>>Active</option>
                    <option value="Archived" <?php echo $f_status==='Archived'?'selected':''; ?>>Archived</option>
                </select>
            </div>
            <div class="ffg" style="flex-direction:row;gap:6px;align-items:flex-end;">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Filter</button>
                <a href="stock_list.php" class="btn btn-secondary btn-sm" title="Clear"><i class="fa-solid fa-rotate-left"></i></a>
            </div>
        </div>
    </form>
</div>

<div class="table-card">
    <div class="table-toolbar">
        <div class="tbl-title">
            <i class="fa-solid fa-table-list" style="color:#0d9488;"></i> Upload Batches
            <span style="background:#f1f5f9;color:#374151;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:700;"><?php echo count($uploads); ?></span>
        </div>
        <a href="stock_upload.php" class="btn btn-teal btn-sm"><i class="fa-solid fa-file-arrow-up"></i> New Upload</a>
    </div>
    <div class="dt-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Batch Ref</th>
                    <th>Delivery Date</th>
                    <th>RS Name</th>
                    <th>Report Date</th>
                    <th class="tr">SKU Rows</th>
                    <th class="tr">Total Units</th>
                    <th class="tr">Stock Value</th>
                    <th>Uploaded By</th>
                    <th>Uploaded At</th>
                    <th>Notes</th>
                    <th class="tc">Status</th>
                    <th class="tc">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($uploads)): ?>
                <tr><td colspan="13">
                    <div class="empty-state">
                        <i class="fa-solid fa-folder-open" style="font-size:36px;display:block;margin-bottom:12px;opacity:.2;"></i>
                        <p style="font-size:14px;font-weight:500;">No uploads found. <a href="stock_upload.php" style="color:#0d9488;font-weight:700;">Upload your first stock report →</a></p>
                    </div>
                </td></tr>
            <?php else: $rn=1; foreach ($uploads as $up): ?>
                <tr id="up-tr-<?php echo $up['id']; ?>">
                    <td style="color:#9ca3af;font-size:11px;"><?php echo $rn++; ?></td>
                    <td><span class="batch-chip"><?php echo htmlspecialchars($up['batch_ref']); ?></span></td>
                    <td>
                        <span style="background:#fef9c3;color:#713f12;border:1px solid #fde68a;display:inline-block;padding:2px 8px;border-radius:6px;font-size:11.5px;font-weight:700;">
                            <?php echo date('d M Y', strtotime($up['deliver_date'])); ?>
                        </span>
                    </td>
                    <td style="font-size:12.5px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($up['rs_name']??''); ?>"><?php echo htmlspecialchars($up['rs_name'] ?? '—'); ?></td>
                    <td style="font-size:12px;color:#6b7280;"><?php echo (!empty($up['report_date']) && $up['report_date']!=='0000-00-00') ? date('d M Y',strtotime($up['report_date'])) : '—'; ?></td>
                    <td class="tr"><strong><?php echo number_format($up['total_rows']); ?></strong></td>
                    <td class="tr" style="color:#0d9488;font-weight:700;"><?php echo number_format($up['total_units']); ?></td>
                    <td class="tr" style="color:#1d4ed8;font-weight:700;"><?php echo number_format($up['total_value'],2); ?></td>
                    <td style="font-size:12px;color:#6b7280;"><?php echo htmlspecialchars($up['uploaded_by'] ?? '—'); ?></td>
                    <td style="font-size:11px;color:#9ca3af;white-space:nowrap;"><?php echo date('d M Y H:i',strtotime($up['created_at'])); ?></td>
                    <td style="font-size:11.5px;color:#6b7280;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($up['notes']??''); ?>"><?php echo htmlspecialchars($up['notes'] ?? '—'); ?></td>
                    <td class="tc">
                        <span class="badge badge-<?php echo strtolower($up['status']); ?>" id="sbadge-<?php echo $up['id']; ?>"><?php echo $up['status']; ?></span>
                    </td>
                    <td class="tc" style="white-space:nowrap;">
                        <a href="stock_view.php?id=<?php echo $up['id']; ?>" class="btn btn-xs btn-primary" title="View Details"><i class="fa-solid fa-eye"></i></a>
                        <button class="btn btn-xs btn-secondary" style="margin-left:2px;"
                                onclick="toggleStatus(<?php echo $up['id']; ?>)"
                                id="tbtn-<?php echo $up['id']; ?>" title="Toggle Status">
                            <i class="fa-solid fa-toggle-<?php echo $up['status']==='Active'?'on':'off'; ?>"></i>
                        </button>
                        <button class="btn btn-xs btn-danger" style="margin-left:2px;"
                                onclick="deleteUpload(<?php echo $up['id']; ?>,'<?php echo addslashes(htmlspecialchars($up['batch_ref'])); ?>')" title="Delete">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($uploads)):
                $t_rows  = array_sum(array_column($uploads,'total_rows'));
                $t_units = array_sum(array_column($uploads,'total_units'));
                $t_val   = array_sum(array_column($uploads,'total_value'));
            ?>
            <tfoot>
                <tr>
                    <td colspan="5" style="text-align:right;font-size:10.5px;opacity:.7;">TOTALS — <?php echo count($uploads); ?> batches</td>
                    <td class="tr" style="color:#e2e8f0;"><?php echo number_format($t_rows); ?></td>
                    <td class="tr" style="color:#2dd4bf;"><?php echo number_format($t_units); ?></td>
                    <td class="tr" style="color:#60a5fa;"><?php echo number_format($t_val,2); ?></td>
                    <td colspan="5"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<div id="slToast"></div>
<script>
function toggleStatus(id) {
    const fd = new FormData(); fd.append('id', id);
    fetch('stock_list.php?action=toggle_status', {method:'POST',body:fd})
    .then(r=>r.json()).then(res=>{
        if (res.success) {
            const b = document.getElementById('sbadge-' + id);
            const t = document.getElementById('tbtn-' + id);
            b.textContent  = res.new_status;
            b.className    = 'badge badge-' + res.new_status.toLowerCase();
            t.innerHTML    = '<i class="fa-solid fa-toggle-' + (res.new_status==='Active'?'on':'off') + '"></i>';
            showToast('Status changed to ' + res.new_status, 'success');
        } else showToast('Error: '+(res.message||'Failed'), 'error');
    }).catch(()=>showToast('Network error.','error'));
}

function deleteUpload(id, ref) {
    if (!confirm('Delete batch "' + ref + '"?\n\nAll ' + ref + ' stock rows will be permanently removed. This cannot be undone.')) return;
    const fd = new FormData(); fd.append('id', id);
    fetch('stock_list.php?action=delete_upload', {method:'POST',body:fd})
    .then(r=>r.json()).then(res=>{
        if (res.success) { document.getElementById('up-tr-'+id)?.remove(); showToast('Batch deleted.','success'); }
        else showToast('Error: '+(res.message||'Delete failed'),'error');
    }).catch(()=>showToast('Network error.','error'));
}

function showToast(msg, type) {
    const t = document.getElementById('slToast');
    t.textContent = msg; t.className = type; t.style.display = 'block';
    clearTimeout(t._t); t._t = setTimeout(()=>t.style.display='none', 3500);
}
</script>
<?php include 'footer.php'; ?>
