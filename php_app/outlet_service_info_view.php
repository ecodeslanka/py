<?php
include 'config.php';

/* ── filters ── */
$upload_id  = isset($_GET['upload_id']) && $_GET['upload_id'] !== '' ? (int)$_GET['upload_id'] : null;
$rs_code    = trim($_GET['rs_code']    ?? '');
$rssp_code  = trim($_GET['rssp_code']  ?? '');
$search     = trim($_GET['search']     ?? '');
$channel    = trim($_GET['channel']    ?? '');
$category   = trim($_GET['category']   ?? '');
$status     = trim($_GET['status']     ?? '');
$split_only = isset($_GET['split_only']) && $_GET['split_only'] === '1';
$page       = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page   = 100;
$offset     = ($page - 1) * $per_page;

$where  = [];
if ($upload_id)         $where[] = "d.upload_id = ".$upload_id;
if ($rs_code !== '')    $where[] = "d.rs_code = '".mysqli_real_escape_string($conn,$rs_code)."'";
if ($rssp_code !== '')  $where[] = "d.rssp_code = '".mysqli_real_escape_string($conn,$rssp_code)."'";
if ($channel !== '')    $where[] = "d.channel = '".mysqli_real_escape_string($conn,$channel)."'";
if ($category !== '')   $where[] = "d.category = '".mysqli_real_escape_string($conn,$category)."'";
if ($status !== '')     $where[] = "d.new_active_inactive_status = '".mysqli_real_escape_string($conn,$status)."'";
if ($split_only)        $where[] = "d.split_group_total > 1";
if ($search !== '') {
    $s = mysqli_real_escape_string($conn, $search);
    $where[] = "(d.party_name LIKE '%$s%' OR d.outlet_hul_code LIKE '%$s%' OR d.rssp_name LIKE '%$s%' OR d.address LIKE '%$s%' OR d.rs_name LIKE '%$s%')";
}
$whereSql = $where ? 'WHERE '.implode(' AND ', $where) : '';

/*
 * Only real Excel-sourced columns are ever shown — no internal id / upload_id /
 * source_row_no / split_group_no / split_group_total / original_* raw-combined
 * fields / source_format / created_at. Order matches the RTM sheet layout;
 * a simple day-route file (e.g. Monday.xlsx) just won't populate the RSSP-only
 * ones, and those columns are hidden automatically below since there's nothing
 * to show.
 */
$EXCEL_COLS = [
    'sr_no'                        => 'Sr No',
    'rs_code'                      => 'RS Code',
    'rs_name'                      => 'RS Name',
    'rssp_code'                    => 'RSSP Code',
    'rssp_name'                    => 'RSSP Name',
    'outlet_hul_code'              => 'Outlet HUL Code',
    'party_name'                   => 'Party Name',
    'address'                      => 'Address',
    'channel'                      => 'Channel',
    'category'                     => 'Category',
    'servicing_day'                => 'Servicing Day',
    'existing_visit_frequency'     => 'Existing Visit Frequency',
    'avg_sale_monthly'             => 'AVG Sale (Monthly)',
    'contri_pct'                   => 'Contri %',
    'outlet_rank'                  => 'Outlet Rank',
    'contribution_80pct'           => '80% Contribution',
    'avg_lppc_monthly'             => 'AVG LPPC (Monthly)',
    'avg_asmt_monthly'             => 'AVG ASMT (Monthly)',
    'avg_productive_calls_monthly' => 'AVG Productive Calls (Monthly)',
    'new_active_inactive_status'   => 'New Active/Inactive Status',
    'new_beat_name'                => 'New Beat Name',
    'new_beat'                     => 'New Beat',
    'new_visit_frequency'          => 'New Visit Frequency',
    'new_servicing_day'            => 'New Servicing Day',
    'split_out'                    => 'Split Out',
    'new_rssp_code'                => 'New RSSP Code',
    'new_rssp_name'                => 'New RSSP Name',
    'outlet_latitude'              => 'Outlet Latitude',
    'outlet_longitude'             => 'Outlet Longitude',
];

/* which of these columns actually have data for the current filtered set? */
$presenceSelect = [];
foreach ($EXCEL_COLS as $col => $label) {
    $presenceSelect[] = "SUM(CASE WHEN d.`$col` IS NOT NULL AND d.`$col` <> '' THEN 1 ELSE 0 END) AS `has_$col`";
}
$presence = mysqli_fetch_assoc(mysqli_query($conn, "
  SELECT ".implode(',', $presenceSelect)." FROM outlet_service_info_data d $whereSql
")) ?: [];

$visibleCols = [];
foreach ($EXCEL_COLS as $col => $label) {
    if ((int)($presence["has_$col"] ?? 0) > 0) $visibleCols[$col] = $label;
}
if (!$visibleCols) $visibleCols = $EXCEL_COLS; // fallback if the set is empty (e.g. no rows at all)

/* ── csv export (excel-sourced columns only, matching what's visible) ── */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = mysqli_query($conn, "
      SELECT d.* FROM outlet_service_info_data d
      $whereSql
      ORDER BY d.upload_id DESC, d.source_row_no ASC, d.split_group_no ASC
    ");
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename=outlet_service_info_export_'.date('Ymd_His').'.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, array_values($visibleCols));
    while ($r = mysqli_fetch_assoc($rows)) {
        $line = [];
        foreach ($visibleCols as $col => $label) $line[] = $r[$col];
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

/* ── counts ── */
$total_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM outlet_service_info_data d $whereSql"));
$total     = (int)($total_row['c'] ?? 0);
$pages     = max(1, (int)ceil($total / $per_page));
$page      = min($page, $pages);
$offset    = ($page - 1) * $per_page;

$agg = mysqli_fetch_assoc(mysqli_query($conn, "
  SELECT COUNT(DISTINCT d.rssp_code) AS unique_rssp,
         COUNT(DISTINCT d.rs_code)   AS unique_rs,
         SUM(CASE WHEN d.split_group_total > 1 THEN 1 ELSE 0 END) AS split_rows,
         COUNT(DISTINCT d.outlet_hul_code) AS unique_outlets
  FROM outlet_service_info_data d $whereSql
")) ?: ['unique_rssp'=>0,'unique_rs'=>0,'split_rows'=>0,'unique_outlets'=>0];

/* ── dropdown option sources (unfiltered, for filter UI) ── */
$uploadsList = mysqli_query($conn, "SELECT id, filename, uploaded_at FROM outlet_service_info_uploads ORDER BY uploaded_at DESC LIMIT 50");
$channelsList  = mysqli_query($conn, "SELECT DISTINCT channel FROM outlet_service_info_data WHERE channel IS NOT NULL AND channel<>'' ORDER BY channel LIMIT 200");
$categoryList  = mysqli_query($conn, "SELECT DISTINCT category FROM outlet_service_info_data WHERE category IS NOT NULL AND category<>'' ORDER BY category LIMIT 200");
$statusList    = mysqli_query($conn, "SELECT DISTINCT new_active_inactive_status FROM outlet_service_info_data WHERE new_active_inactive_status IS NOT NULL AND new_active_inactive_status<>'' ORDER BY new_active_inactive_status");

/* ── main data ── */
$data = mysqli_query($conn, "
  SELECT d.* FROM outlet_service_info_data d
  $whereSql
  ORDER BY d.upload_id DESC, d.source_row_no ASC, d.split_group_no ASC
  LIMIT $per_page OFFSET $offset
");

function qs($overrides = []) {
    $params = array_merge($_GET, $overrides);
    foreach ($params as $k => $v) { if ($v === null || $v === '') unset($params[$k]); }
    return '?'.http_build_query($params);
}

include 'header.php';
?>

<style>
*{box-sizing:border-box;}
.ph-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:20px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-teal{background:#0f766e;color:#fff;}.btn-teal:hover{background:#115e59;}
.btn-sm{padding:5px 10px;font-size:12px;}
.btn-disabled{opacity:.4;pointer-events:none;}

.breadcrumb{display:flex;align-items:center;gap:6px;font-size:11.5px;color:#9ca3af;margin-bottom:16px;flex-wrap:wrap;}
.breadcrumb a{color:#0f766e;text-decoration:none;font-weight:600;}.breadcrumb a:hover{text-decoration:underline;}
.breadcrumb .sep{color:#d1d5db;}

.sum-cards{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:20px;}
.sum-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 20px;flex:1;min-width:150px;box-shadow:0 1px 4px rgba(0,0,0,.04);border-top:3px solid #0f766e;}
.sum-card-label{font-size:10.5px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;}
.sum-card-val{font-size:20px;font-weight:700;color:#0f766e;}

.filter-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 20px;margin-bottom:18px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
.filter-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;align-items:end;}
.form-group label{display:block;font-size:10.5px;font-weight:700;color:#6b7280;margin-bottom:5px;text-transform:uppercase;letter-spacing:.4px;}
.form-control{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12.5px;font-family:inherit;color:#111827;outline:none;}
.form-control:focus{border-color:#0f766e;box-shadow:0 0 0 3px rgba(15,118,110,.1);}
.filter-actions{display:flex;gap:8px;margin-top:14px;}
.chk-row{display:flex;align-items:center;gap:6px;font-size:12px;color:#374151;font-weight:600;padding-bottom:8px;}

.table-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #f3f4f6;flex-wrap:wrap;gap:8px;}
.tbl-title{font-size:14px;font-weight:700;color:#111827;}
.dt-wrap{overflow-x:auto;max-height:70vh;}
.data-table{width:100%;border-collapse:collapse;font-size:11.5px;}
.data-table th{padding:9px 10px;text-align:left;background:#f9fafb;border-bottom:2px solid #e5e7eb;color:#374151;font-size:10.5px;text-transform:uppercase;white-space:nowrap;position:sticky;top:0;z-index:1;}
.data-table td{padding:8px 10px;border-bottom:1px solid #f3f4f6;color:#111827;white-space:nowrap;max-width:240px;overflow:hidden;text-overflow:ellipsis;}
.data-table tr:hover td{background:#f9fafb;}
.tr{text-align:right!important;}
.tc{text-align:center!important;}

.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:10.5px;font-weight:600;white-space:nowrap;}
.badge-teal{background:#ccfbf1;color:#0f766e;}
.badge-green{background:#dcfce7;color:#15803d;}
.badge-red{background:#fee2e2;color:#991b1b;}
.badge-gray{background:#f3f4f6;color:#4b5563;}

.pagination{display:flex;align-items:center;justify-content:center;gap:6px;padding:16px;}
.pagination a, .pagination span{padding:6px 12px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;color:#374151;background:#f5f5f5;}
.pagination a:hover{background:#e8e8e8;}
.pagination .active{background:#0f766e;color:#fff;}

.empty-state{text-align:center;padding:50px;color:#9ca3af;}
</style>

<div class="breadcrumb">
  <a href="index.php"><i class="fa-solid fa-house"></i> Home</a>
  <span class="sep">›</span>
  <a href="outlet_service_info_upload.php"><i class="fa-solid fa-route"></i> Outlet Service Info</a>
  <span class="sep">›</span>
  <span style="color:#0f766e;font-weight:700;"><i class="fa-solid fa-table"></i> View Data</span>
</div>

<div class="ph-row">
  <div>
    <h2 style="margin:0;font-size:18px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-table" style="color:#0f766e;margin-right:8px;"></i>Outlet Service Info — Data
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">Showing only the columns that came from the imported Excel file. Columns with no data for the current filter are hidden.</p>
  </div>
  <div style="display:flex;gap:8px;">
    <a href="<?= htmlspecialchars(qs(['export'=>'csv'])) ?>" class="btn btn-teal"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
    <a href="outlet_service_info_upload.php" class="btn btn-secondary"><i class="fa-solid fa-file-arrow-up"></i> Upload</a>
  </div>
</div>

<div class="sum-cards">
  <div class="sum-card">
    <div class="sum-card-label"><i class="fa-solid fa-list-ol"></i> Rows (filtered)</div>
    <div class="sum-card-val"><?= number_format($total) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label"><i class="fa-solid fa-shop"></i> Unique Outlets</div>
    <div class="sum-card-val"><?= number_format($agg['unique_outlets']) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label"><i class="fa-solid fa-id-badge"></i> Unique RSSP Codes</div>
    <div class="sum-card-val"><?= number_format($agg['unique_rssp']) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label"><i class="fa-solid fa-user-tie"></i> Unique RS Codes</div>
    <div class="sum-card-val"><?= number_format($agg['unique_rs']) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label"><i class="fa-solid fa-code-branch"></i> Split Rows</div>
    <div class="sum-card-val"><?= number_format($agg['split_rows']) ?></div>
  </div>
</div>

<form class="filter-card" method="get" id="filterForm">
  <div class="filter-grid">
    <div class="form-group">
      <label>Upload</label>
      <select name="upload_id" class="form-control">
        <option value="">All uploads</option>
        <?php mysqli_data_seek($uploadsList, 0); while ($u = mysqli_fetch_assoc($uploadsList)): ?>
          <option value="<?= $u['id'] ?>" <?= $upload_id == $u['id'] ? 'selected' : '' ?>>
            #<?= $u['id'] ?> — <?= htmlspecialchars($u['filename']) ?> (<?= date('d M Y', strtotime($u['uploaded_at'])) ?>)
          </option>
        <?php endwhile; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Search</label>
      <input type="text" name="search" class="form-control" placeholder="Outlet, HUL code, address…" value="<?= htmlspecialchars($search) ?>">
    </div>
    <div class="form-group">
      <label>RS Code</label>
      <input type="text" name="rs_code" class="form-control" placeholder="e.g. 803559" value="<?= htmlspecialchars($rs_code) ?>">
    </div>
    <div class="form-group">
      <label>RSSP Code</label>
      <input type="text" name="rssp_code" class="form-control" placeholder="e.g. SMN00002" value="<?= htmlspecialchars($rssp_code) ?>">
    </div>
    <div class="form-group">
      <label>Channel</label>
      <select name="channel" class="form-control">
        <option value="">All channels</option>
        <?php while ($c = mysqli_fetch_assoc($channelsList)): ?>
          <option value="<?= htmlspecialchars($c['channel']) ?>" <?= $channel === $c['channel'] ? 'selected' : '' ?>><?= htmlspecialchars($c['channel']) ?></option>
        <?php endwhile; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Category</label>
      <select name="category" class="form-control">
        <option value="">All categories</option>
        <?php while ($c = mysqli_fetch_assoc($categoryList)): ?>
          <option value="<?= htmlspecialchars($c['category']) ?>" <?= $category === $c['category'] ? 'selected' : '' ?>><?= htmlspecialchars($c['category']) ?></option>
        <?php endwhile; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Active Status</label>
      <select name="status" class="form-control">
        <option value="">All statuses</option>
        <?php while ($c = mysqli_fetch_assoc($statusList)): ?>
          <option value="<?= htmlspecialchars($c['new_active_inactive_status']) ?>" <?= $status === $c['new_active_inactive_status'] ? 'selected' : '' ?>><?= htmlspecialchars($c['new_active_inactive_status']) ?></option>
        <?php endwhile; ?>
      </select>
    </div>
    <div class="chk-row">
      <label style="display:flex;align-items:center;gap:6px;text-transform:none;font-size:12.5px;">
        <input type="checkbox" name="split_only" value="1" <?= $split_only ? 'checked' : '' ?> style="width:15px;height:15px;">
        Split rows only
      </label>
    </div>
  </div>
  <div class="filter-actions">
    <button type="submit" class="btn btn-teal btn-sm"><i class="fa-solid fa-filter"></i> Apply Filters</button>
    <a href="outlet_service_info_view.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-rotate-left"></i> Reset</a>
  </div>
</form>

<div class="table-card">
  <div class="table-toolbar">
    <div class="tbl-title"><i class="fa-solid fa-list" style="margin-right:6px;color:#0f766e;"></i>Results — Page <?= $page ?> of <?= $pages ?> (<?= number_format($total) ?> rows)</div>
  </div>
  <div class="dt-wrap">
  <table class="data-table">
    <thead>
      <tr>
        <?php foreach ($visibleCols as $col => $label): ?>
          <th class="<?= in_array($col, ['avg_sale_monthly','contri_pct','contribution_80pct','avg_lppc_monthly','avg_asmt_monthly','avg_productive_calls_monthly']) ? 'tr' : (in_array($col,['outlet_rank']) ? 'tc' : '') ?>"><?= htmlspecialchars($label) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
    <?php $any = false; while ($r = mysqli_fetch_assoc($data)): $any = true;
        $statusVal = strtolower(trim((string)$r['new_active_inactive_status']));
    ?>
      <tr>
        <?php foreach ($visibleCols as $col => $label):
            $v = $r[$col];
        ?>
          <?php if ($col === 'rssp_code'): ?>
            <td><?= $v !== null && $v !== '' ? '<span class="badge badge-teal">'.htmlspecialchars($v).'</span>' : '—' ?></td>
          <?php elseif ($col === 'new_rssp_code'): ?>
            <td><?= $v !== null && $v !== '' ? '<span class="badge badge-gray">'.htmlspecialchars($v).'</span>' : '—' ?></td>
          <?php elseif ($col === 'new_active_inactive_status'): ?>
            <td>
              <?php if ($statusVal === 'active'): ?>
                <span class="badge badge-green">Active</span>
              <?php elseif ($statusVal !== ''): ?>
                <span class="badge badge-red"><?= htmlspecialchars($v) ?></span>
              <?php else: ?>
                <span class="badge badge-gray">—</span>
              <?php endif; ?>
            </td>
          <?php elseif ($col === 'avg_sale_monthly'): ?>
            <td class="tr"><?= $v !== null ? number_format($v,2) : '—' ?></td>
          <?php elseif (in_array($col, ['avg_lppc_monthly','avg_asmt_monthly','avg_productive_calls_monthly'])): ?>
            <td class="tr"><?= $v !== null ? number_format($v,2) : '—' ?></td>
          <?php elseif ($col === 'contri_pct'): ?>
            <td class="tr"><?= $v !== null ? number_format($v*100,2).'%' : '—' ?></td>
          <?php elseif ($col === 'contribution_80pct'): ?>
            <td class="tr"><?= $v !== null ? number_format($v,2) : '—' ?></td>
          <?php elseif ($col === 'outlet_rank'): ?>
            <td class="tc"><?= $v !== null ? (int)$v : '—' ?></td>
          <?php elseif ($col === 'outlet_latitude' || $col === 'outlet_longitude'): ?>
            <td><?= $v !== null && $v !== '' ? $v : '—' ?></td>
          <?php else: ?>
            <td title="<?= htmlspecialchars((string)$v) ?>"><?= $v !== null && $v !== '' ? htmlspecialchars($v) : '—' ?></td>
          <?php endif; ?>
        <?php endforeach; ?>
      </tr>
    <?php endwhile; ?>
    <?php if (!$any): ?>
      <tr><td colspan="<?= count($visibleCols) ?>"><div class="empty-state"><i class="fa-solid fa-inbox" style="font-size:32px;margin-bottom:10px;display:block;"></i>No rows match these filters.</div></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>

  <?php if ($pages > 1): ?>
  <div class="pagination">
    <a href="<?= $page > 1 ? htmlspecialchars(qs(['page'=>$page-1])) : '#' ?>" class="<?= $page <= 1 ? 'btn-disabled' : '' ?>"><i class="fa-solid fa-chevron-left"></i> Prev</a>
    <?php
      $startP = max(1, $page - 3);
      $endP   = min($pages, $page + 3);
      for ($p = $startP; $p <= $endP; $p++):
    ?>
      <a href="<?= htmlspecialchars(qs(['page'=>$p])) ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
    <?php endfor; ?>
    <a href="<?= $page < $pages ? htmlspecialchars(qs(['page'=>$page+1])) : '#' ?>" class="<?= $page >= $pages ? 'btn-disabled' : '' ?>">Next <i class="fa-solid fa-chevron-right"></i></a>
  </div>
  <?php endif; ?>
</div>

<?php include 'footer.php'; ?>