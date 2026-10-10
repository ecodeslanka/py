<?php
include 'config.php';

/* ── delete handler ── */
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $del_id = intval($_GET['delete']);
    // CASCADE FK removes current_stock_data rows automatically
    mysqli_query($conn, "DELETE FROM current_stock_uploads WHERE id=$del_id");
    header('Location: current_stock_history.php?deleted=1');
    exit;
}

/* ── filters ── */
$f_date_from = trim($_GET['date_from'] ?? '');
$f_date_to   = trim($_GET['date_to']   ?? '');
$f_search    = trim($_GET['search']    ?? '');

$where = ['1=1'];
if ($f_date_from) $where[] = "u.entry_date >= '".mysqli_real_escape_string($conn,$f_date_from)."'";
if ($f_date_to)   $where[] = "u.entry_date <= '".mysqli_real_escape_string($conn,$f_date_to)."'";
if ($f_search)    $where[] = "(u.filename LIKE '%".mysqli_real_escape_string($conn,$f_search)."%' OR u.note LIKE '%".mysqli_real_escape_string($conn,$f_search)."%')";
$where_sql = implode(' AND ', $where);

/* ── pagination ── */
$per_page = 25;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page-1)*$per_page;

$total_count = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) AS c FROM current_stock_uploads u WHERE $where_sql"))['c'];
$total_pages = max(1, ceil($total_count/$per_page));

$uploads = mysqli_query($conn,"
  SELECT u.*, 
         (SELECT SUM(cu.total_rows) FROM current_stock_uploads cu WHERE cu.id=u.id) AS rows_check
  FROM current_stock_uploads u
  WHERE $where_sql
  ORDER BY u.uploaded_at DESC
  LIMIT $per_page OFFSET $offset
");

$stats = mysqli_fetch_assoc(mysqli_query($conn,"
  SELECT COUNT(*) AS total_uploads, COALESCE(SUM(total_rows),0) AS total_rows,
         COUNT(DISTINCT entry_date) AS unique_dates
  FROM current_stock_uploads
"));

include 'header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<style>
*{box-sizing:border-box;}
.ph-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:20px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-primary{background:#1e40af;color:#fff;}.btn-primary:hover{background:#1e3a8a;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-success{background:#15803d;color:#fff;}.btn-success:hover{background:#166534;}
.btn-danger{background:#dc2626;color:#fff;}.btn-danger:hover{background:#b91c1c;}
.btn-warning{background:#d97706;color:#fff;}.btn-warning:hover{background:#b45309;}
.btn-sm{padding:5px 12px;font-size:12px;}

.summary-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:22px;}
@media(max-width:700px){.summary-grid{grid-template-columns:1fr;}}
.sum-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:16px 18px;box-shadow:0 1px 3px rgba(0,0,0,.05);}
.sum-card-label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;}
.sum-card-val{font-size:22px;font-weight:800;color:#1e40af;}

.filter-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:16px 20px;margin-bottom:18px;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.filter-row{display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:10px;align-items:flex-end;}
@media(max-width:800px){.filter-row{grid-template-columns:1fr 1fr;}}
.fg label{font-size:11.5px;font-weight:700;color:#374151;display:block;margin-bottom:4px;}
.fctrl{padding:7px 10px;border:1px solid #e0e0e0;border-radius:6px;font-size:12.5px;width:100%;font-family:inherit;background:#fff;color:#111;outline:none;}
.fctrl:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.1);}

.table-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);margin-bottom:18px;}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:12px 18px;border-bottom:1px solid #f0f0f0;flex-wrap:wrap;gap:8px;}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.data-table thead th{padding:8px 10px;text-align:left;font-weight:700;font-size:11px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;}
.data-table thead th.tr{text-align:right;}
.data-table thead th.tc{text-align:center;}
.data-table tbody tr{border-bottom:1px solid #f3f4f6;transition:background .1s;}
.data-table tbody tr:hover td{background:#f0f7ff;}
.data-table td{padding:9px 10px;color:#374151;vertical-align:middle;background:#fff;}
.data-table td.tr{text-align:right;}
.data-table td.tc{text-align:center;}
.badge{display:inline-block;padding:2px 10px;border-radius:10px;font-size:10.5px;font-weight:700;}
.badge-blue{background:#dbeafe;color:#1e40af;}
.badge-green{background:#d1fae5;color:#065f46;}
.pill{padding:2px 9px;border-radius:12px;font-size:11px;font-weight:600;background:#dbeafe;color:#1e40af;}

/* pagination */
.pager{display:flex;justify-content:center;align-items:center;gap:6px;padding:14px;}
.pager a,.pager span{padding:5px 12px;border-radius:6px;font-size:12.5px;font-weight:600;text-decoration:none;border:1px solid #e5e5e5;}
.pager a{color:#1e40af;background:#fff;}.pager a:hover{background:#eff6ff;}
.pager span.active{background:#1e40af;color:#fff;border-color:#1e40af;}
.pager span.disabled{color:#d1d5db;background:#f9f9f9;}

/* delete confirm modal */
.modal-backdrop{position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;padding:16px;}
.modal-backdrop.open{display:flex;}
.modal-dialog{background:#fff;border-radius:12px;width:100%;max-width:420px;box-shadow:0 24px 70px rgba(0,0,0,.3);overflow:hidden;}
.modal-hdr{padding:16px 22px;border-bottom:1px solid #e5e5e5;background:#fafafa;display:flex;align-items:center;justify-content:space-between;}
.modal-hdr h3{font-size:15px;font-weight:700;color:#1f2937;margin:0;}
.modal-body{padding:22px;}
.modal-ftr{padding:11px 22px;border-top:1px solid #e5e5e5;background:#fafafa;display:flex;justify-content:flex-end;gap:8px;}
.modal-x{width:28px;height:28px;border-radius:6px;border:1px solid #e5e5e5;background:#fff;color:#6b7280;cursor:pointer;font-size:13px;display:flex;align-items:center;justify-content:center;}

/* toast */
#toast{position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:100020;padding:12px 26px;border-radius:9px;font-size:13px;font-weight:700;font-family:inherit;box-shadow:0 8px 28px rgba(0,0,0,.2);display:none;pointer-events:none;}
.toast-ok{background:#166534;color:#fff;}.toast-err{background:#dc2626;color:#fff;}
</style>

<div class="ph-row">
  <div>
    <h2 class="page-title" style="margin:0 0 4px;">Upload History</h2>
    <p style="margin:0;font-size:13px;color:#6b7280;">All Current Stock uploads — view, manage and delete records.</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a href="current_stock_upload.php" class="btn btn-success btn-sm"><i class="fa-solid fa-upload"></i> New Upload</a>
    <a href="current_stock_view.php" class="btn btn-primary btn-sm"><i class="fa-solid fa-table"></i> View Data</a>
  </div>
</div>

<?php if (isset($_GET['deleted'])): ?>
<div style="background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;padding:12px 18px;border-radius:8px;font-weight:600;font-size:13px;margin-bottom:16px;">
  <i class="fa-solid fa-circle-check"></i> Upload and all its data deleted successfully.
</div>
<?php endif; ?>

<!-- SUMMARY CARDS -->
<div class="summary-grid">
  <div class="sum-card">
    <div class="sum-card-label"><i class="fa-solid fa-upload" style="margin-right:4px;"></i>Total Uploads</div>
    <div class="sum-card-val"><?= number_format($stats['total_uploads']) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label"><i class="fa-solid fa-table-rows" style="margin-right:4px;"></i>Total Rows Stored</div>
    <div class="sum-card-val"><?= number_format($stats['total_rows']) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label"><i class="fa-solid fa-calendar-days" style="margin-right:4px;"></i>Unique Entry Dates</div>
    <div class="sum-card-val"><?= number_format($stats['unique_dates']) ?></div>
  </div>
</div>

<!-- FILTERS -->
<div class="filter-card">
  <form method="GET">
    <div class="filter-row">
      <div class="fg">
        <label>Entry Date — From</label>
        <input type="date" name="date_from" class="fctrl" value="<?= htmlspecialchars($f_date_from) ?>">
      </div>
      <div class="fg">
        <label>Entry Date — To</label>
        <input type="date" name="date_to" class="fctrl" value="<?= htmlspecialchars($f_date_to) ?>">
      </div>
      <div class="fg">
        <label>Search Filename / Note</label>
        <input type="text" name="search" class="fctrl" placeholder="Search…" value="<?= htmlspecialchars($f_search) ?>">
      </div>
      <div style="display:flex;gap:6px;align-items:flex-end;">
        <button type="submit" class="btn btn-primary" style="height:35px;"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
        <a href="current_stock_history.php" class="btn btn-secondary" style="height:35px;" title="Clear"><i class="fa-solid fa-rotate-left"></i></a>
      </div>
    </div>
  </form>
</div>

<!-- HISTORY TABLE -->
<div class="table-card">
  <div class="table-toolbar">
    <div class="tbl-title">Upload Records <span class="pill"><?= number_format($total_count) ?></span></div>
    <div style="display:flex;gap:8px;">
      <button class="btn btn-secondary btn-sm" onclick="exportHistory()"><i class="fa-solid fa-file-excel"></i> Export</button>
    </div>
  </div>
  <div class="dt-wrap">
  <table class="data-table" id="historyTable">
    <thead>
      <tr>
        <th>#</th>
        <th>Entry Date</th>
        <th>Filename</th>
        <th class="tr">Rows</th>
        <th>Note</th>
        <th>Uploaded At</th>
        <th class="tc">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php $i = ($page-1)*$per_page+1; while ($r = mysqli_fetch_assoc($uploads)): ?>
      <tr>
        <td style="color:#9ca3af;font-size:11px;"><?= $i++ ?></td>
        <td><span class="badge badge-blue"><?= date('d M Y',strtotime($r['entry_date'])) ?></span></td>
        <td style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;" title="<?= htmlspecialchars($r['filename']) ?>">
          <i class="fa-solid fa-file-excel" style="color:#16a34a;margin-right:5px;"></i><?= htmlspecialchars($r['filename']) ?>
        </td>
        <td class="tr"><span class="badge badge-green"><?= number_format($r['total_rows']) ?></span></td>
        <td style="font-size:11.5px;color:#6b7280;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($r['note']??'') ?>"><?= htmlspecialchars($r['note'] ?: '—') ?></td>
        <td style="font-size:11.5px;white-space:nowrap;"><?= date('d M Y H:i',strtotime($r['uploaded_at'])) ?></td>
        <td class="tc" style="white-space:nowrap;">
          <a href="current_stock_view.php?upload_id=<?= $r['id'] ?>" class="btn btn-primary btn-sm" title="View Data"><i class="fa-solid fa-eye"></i></a>
          <button class="btn btn-danger btn-sm" onclick="confirmDelete(<?= $r['id'] ?>,'<?= htmlspecialchars(addslashes($r['filename'])) ?>',<?= $r['total_rows'] ?>)" title="Delete"><i class="fa-solid fa-trash"></i></button>
        </td>
      </tr>
    <?php endwhile; ?>
    <?php if ($total_count === 0): ?>
      <tr><td colspan="7" style="text-align:center;padding:50px;color:#9ca3af;">
        <i class="fa-solid fa-inbox" style="font-size:30px;display:block;margin-bottom:10px;opacity:.3;"></i>No uploads found.
      </td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>

  <!-- PAGINATION -->
  <?php if ($total_pages > 1): ?>
  <div class="pager">
    <?php if ($page > 1): ?>
      <a href="?page=<?=$page-1?>&date_from=<?=urlencode($f_date_from)?>&date_to=<?=urlencode($f_date_to)?>&search=<?=urlencode($f_search)?>"><i class="fa-solid fa-chevron-left"></i></a>
    <?php else: ?>
      <span class="disabled"><i class="fa-solid fa-chevron-left"></i></span>
    <?php endif; ?>

    <?php for ($p=max(1,$page-2);$p<=min($total_pages,$page+2);$p++): ?>
      <?php if ($p===$page): ?>
        <span class="active"><?=$p?></span>
      <?php else: ?>
        <a href="?page=<?=$p?>&date_from=<?=urlencode($f_date_from)?>&date_to=<?=urlencode($f_date_to)?>&search=<?=urlencode($f_search)?>"><?=$p?></a>
      <?php endif; ?>
    <?php endfor; ?>

    <?php if ($page < $total_pages): ?>
      <a href="?page=<?=$page+1?>&date_from=<?=urlencode($f_date_from)?>&date_to=<?=urlencode($f_date_to)?>&search=<?=urlencode($f_search)?>"><i class="fa-solid fa-chevron-right"></i></a>
    <?php else: ?>
      <span class="disabled"><i class="fa-solid fa-chevron-right"></i></span>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<!-- DELETE CONFIRM MODAL -->
<div class="modal-backdrop" id="deleteModal">
  <div class="modal-dialog">
    <div class="modal-hdr">
      <h3><i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;margin-right:7px;"></i>Confirm Delete</h3>
      <button class="modal-x" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <p style="margin:0 0 10px;font-size:13.5px;color:#374151;">Are you sure you want to delete this upload?</p>
      <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px 14px;font-size:12.5px;color:#991b1b;">
        <strong>File:</strong> <span id="delFilename"></span><br>
        <strong>Rows:</strong> <span id="delRows"></span> rows will be permanently deleted.
      </div>
      <p style="margin:12px 0 0;font-size:12px;color:#6b7280;">This action cannot be undone.</p>
    </div>
    <div class="modal-ftr">
      <button class="btn btn-secondary btn-sm" onclick="closeModal()">Cancel</button>
      <a href="#" id="delConfirmBtn" class="btn btn-danger btn-sm"><i class="fa-solid fa-trash"></i> Delete</a>
    </div>
  </div>
</div>

<div id="toast"></div>

<script>
function confirmDelete(id, filename, rows) {
  document.getElementById('delFilename').textContent = filename;
  document.getElementById('delRows').textContent = rows.toLocaleString();
  document.getElementById('delConfirmBtn').href = 'current_stock_history.php?delete='+id;
  document.getElementById('deleteModal').classList.add('open');
}
function closeModal() {
  document.getElementById('deleteModal').classList.remove('open');
}
document.getElementById('deleteModal').addEventListener('click', function(e){ if(e.target===this) closeModal(); });

function exportHistory() {
  const tbl = document.getElementById('historyTable');
  const wb = XLSX.utils.book_new();
  const ws = XLSX.utils.table_to_sheet(tbl);
  XLSX.utils.book_append_sheet(wb, ws, 'Upload History');
  XLSX.writeFile(wb, 'stock_upload_history_'+new Date().toISOString().slice(0,10)+'.xlsx');
}

<?php if (isset($_GET['deleted'])): ?>
(function(){
  const t=document.getElementById('toast');
  t.className='toast-ok';t.textContent='Upload deleted successfully.';t.style.display='block';t.style.opacity='1';
  setTimeout(()=>{t.style.opacity='0';setTimeout(()=>t.style.display='none',300);},2800);
})();
<?php endif; ?>

function showToast(msg,type){
  const t=document.getElementById('toast');
  t.className=type==='ok'?'toast-ok':'toast-err';
  t.textContent=msg;t.style.display='block';t.style.opacity='1';
  clearTimeout(t._t);
  t._t=setTimeout(()=>{t.style.opacity='0';setTimeout(()=>t.style.display='none',300);},2800);
}
</script>

<?php include 'footer.php'; ?>
