<?php
include 'config.php';
include 'vt_functions.php';
vt_ensure_tables($conn);
vt_ensure_schedule_tables($conn);

$scheduleId = (int)($_GET['id'] ?? 0);
$schedule = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT s.*, v.vehicle_number FROM vehicle_schedules s
    LEFT JOIN vehicles v ON s.vehicle_id = v.id
    WHERE s.id=$scheduleId"));

if (!$schedule) {
    include 'header.php';
    echo '<div style="text-align:center;padding:60px;color:#9ca3af;">Schedule not found. <a href="vehicle_schedule.php">Back to schedules</a></div>';
    include 'footer.php';
    exit;
}

/* ══════════════════════════ AJAX: add or update a route schedule row ══════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'route_add') {
    header('Content-Type: application/json');
    $editId    = (int)($_POST['edit_id'] ?? 0);
    $routeId   = (int)($_POST['route_id'] ?? 0) ?: null;
    $dateFrom  = vt_date($_POST['date_from'] ?? '');
    $dateTo    = vt_date($_POST['date_to'] ?? '') ?: $dateFrom;
    $driverId  = (int)($_POST['driver_id'] ?? 0) ?: null;
    $salesRepId= (int)($_POST['sales_rep_id'] ?? 0) ?: null;
    $helperId  = (int)($_POST['route_helper_id'] ?? 0) ?: null;
    $remark    = trim($_POST['remark'] ?? '');

    if (!$dateFrom) { echo json_encode(['ok'=>false,'msg'=>'Start Date is required.']); exit; }
    if (!$routeId && !$driverId && !$salesRepId && !$helperId) {
        echo json_encode(['ok'=>false,'msg'=>'Select at least a Route, Driver, Sales Rep or Route Helper.']); exit;
    }

    if ($editId) {
        mysqli_query($conn, "UPDATE vehicle_schedule_routes SET
            route_id=".($routeId?:'NULL').", date_from=".vt_esc($conn,$dateFrom).", date_to=".vt_esc($conn,$dateTo).",
            driver_id=".($driverId?:'NULL').", sales_rep_id=".($salesRepId?:'NULL').", route_helper_id=".($helperId?:'NULL').",
            remark=".vt_esc($conn,$remark?:null)."
            WHERE id=$editId AND schedule_id=$scheduleId");
        $msg = 'Route schedule updated.';
        $rowId = $editId;
    } else {
        mysqli_query($conn, "INSERT INTO vehicle_schedule_routes
            (schedule_id, route_id, date_from, date_to, driver_id, sales_rep_id, route_helper_id, remark)
            VALUES ($scheduleId, ".($routeId?:'NULL').", ".vt_esc($conn,$dateFrom).", ".vt_esc($conn,$dateTo).",
             ".($driverId?:'NULL').", ".($salesRepId?:'NULL').", ".($helperId?:'NULL').", ".vt_esc($conn,$remark?:null).")");
        $msg = 'Route schedule added.';
        $rowId = mysqli_insert_id($conn);
    }

    if (mysqli_errno($conn)) { echo json_encode(['ok'=>false,'msg'=>mysqli_error($conn)]); exit; }
    echo json_encode(['ok'=>true,'msg'=>$msg,'id'=>$rowId]);
    exit;
}

/* ══════════════════════════ AJAX: fetch one route schedule row (for the edit modal) ══════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'route_get') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM vehicle_schedule_routes WHERE id=$id AND schedule_id=$scheduleId"));
    if (!$row) { echo json_encode(['ok'=>false,'msg'=>'Not found.']); exit; }
    echo json_encode(['ok'=>true,'row'=>$row]);
    exit;
}

/* ══════════════════════════ AJAX: delete a route schedule row (one click) ══════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'route_delete') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    mysqli_query($conn, "DELETE FROM vehicle_schedule_routes WHERE id=$id AND schedule_id=$scheduleId");
    echo json_encode(['ok'=>true,'msg'=>'Route schedule deleted.']);
    exit;
}

/* ══════════════════════════ page data ══════════════════════════ */
$months = vt_month_names();
$vtRoutes   = vt_all_routes($conn);
$vtDrivers  = vt_all_drivers($conn); // reused for Driver, Sales Rep, Route Helper — all pulled from the employees master

$routeRows = [];
$res = mysqli_query($conn, "
    SELECT sr.*, r.route_code, r.route_name,
           d.employee_full_name AS driver_name, d.employee_id AS driver_code,
           sre.employee_full_name AS sales_rep_name, sre.employee_id AS sales_rep_code,
           h.employee_full_name AS helper_name, h.employee_id AS helper_code
    FROM vehicle_schedule_routes sr
    LEFT JOIN routes r ON sr.route_id = r.id
    LEFT JOIN employees d ON sr.driver_id = d.id
    LEFT JOIN employees sre ON sr.sales_rep_id = sre.id
    LEFT JOIN employees h ON sr.route_helper_id = h.id
    WHERE sr.schedule_id=$scheduleId
    ORDER BY sr.date_from ASC");
if ($res) while ($r = mysqli_fetch_assoc($res)) $routeRows[] = $r;

include 'header.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/css/select2.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/js/select2.min.js"></script>
<style>
*{box-sizing:border-box;}
.select2-container{width:100%!important;}
.select2-container .select2-selection--single{height:38px!important;border:1px solid #d1d5db!important;border-radius:6px!important;padding-top:3px;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:36px!important;}
.select2-container--default .select2-results__option--highlighted[aria-selected]{background:#1e40af!important;}

.ph-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:18px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-primary{background:#1e40af;color:#fff;}.btn-primary:hover{background:#1e3a8a;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-success{background:#15803d;color:#fff;}.btn-success:hover{background:#166534;}
.btn-danger{background:#dc2626;color:#fff;}.btn-danger:hover{background:#b91c1c;}
.btn-sm{padding:5px 10px;font-size:12px;}

.table-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.05);margin-bottom:22px;}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #f3f4f6;}
.tbl-title{font-size:14px;font-weight:700;color:#111827;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12px;}
.data-table th{padding:9px 11px;text-align:left;background:#f9fafb;border-bottom:2px solid #e5e7eb;color:#374151;font-size:10.5px;text-transform:uppercase;white-space:nowrap;}
.data-table td{padding:8px 11px;border-bottom:1px solid #f3f4f6;color:#111827;white-space:nowrap;}
.data-table tr:hover td{background:#f9fafb;}
.tc{text-align:center!important;}
.emp-line{display:block;line-height:1.5;}

.vt-modal{display:none;position:fixed;inset:0;background:rgba(17,24,39,.55);z-index:9998;align-items:flex-start;justify-content:center;padding:40px 16px;overflow-y:auto;}
.vt-modal.active{display:flex;}
.vt-modal-content{background:#fff;border-radius:14px;width:100%;max-width:640px;box-shadow:0 20px 60px rgba(0,0,0,.3);}
.vt-modal-hdr{padding:18px 22px;border-bottom:1px solid #f0f0f0;display:flex;justify-content:space-between;align-items:center;}
.vt-modal-hdr h3{margin:0;font-size:15.5px;font-weight:700;color:#111827;}
.vt-modal-close{background:none;border:none;font-size:18px;color:#6b7280;cursor:pointer;width:32px;height:32px;border-radius:8px;}
.vt-modal-close:hover{background:#f3f4f6;color:#111827;}
.vt-modal-body{padding:22px;}
.form-group{margin-bottom:16px;}
.form-group label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;}
.form-control{width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:7px;font-size:13px;font-family:inherit;}

#toast{position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;display:none;opacity:0;z-index:9999;transition:opacity .3s;max-width:380px;box-shadow:0 4px 20px rgba(0,0,0,.15);}
.toast-ok{background:#166534;color:#fff;}
.toast-err{background:#991b1b;color:#fff;}
</style>

<div class="ph-row">
  <div>
    <h2 style="margin:0;font-size:18px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-truck" style="color:#1e40af;margin-right:8px;"></i><?= htmlspecialchars($schedule['vehicle_number'] ?: '—') ?>
      <span style="font-weight:400;color:#6b7280;font-size:14px;"> — <?= $months[$schedule['month']] ?> <?= $schedule['year'] ?></span>
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">Route assignments for this vehicle's month.</p>
  </div>
  <div style="display:flex;gap:8px;">
    <a href="vehicle_schedule.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
    <button class="btn btn-success" onclick="openRouteModal()"><i class="fa-solid fa-route"></i> Add Route Schedule</button>
    <a href="vehicle_schedule_data_view.php?schedule_id=<?= $scheduleId ?>" class="btn btn-primary"><i class="fa-solid fa-chart-line"></i> View Position &amp; Park Data</a>
  </div>
</div>

<div class="table-card">
  <div class="table-toolbar"><div class="tbl-title">Route Schedules</div></div>
  <div class="dt-wrap">
  <table class="data-table">
    <thead>
      <tr>
        <th>Start Date</th><th>End Date</th><th>Route</th><th>Driver</th><th>Sales Rep</th><th>Route Helper</th><th>Remark</th><th class="tc">Actions</th>
      </tr>
    </thead>
    <tbody id="routeTableBody">
      <?php foreach ($routeRows as $r): ?>
      <tr id="route-row-<?= $r['id'] ?>">
        <td><?= date('d M Y', strtotime($r['date_from'])) ?></td>
        <td><?= date('d M Y', strtotime($r['date_to'])) ?></td>
        <td><?= $r['route_name'] ? htmlspecialchars($r['route_code'].' - '.$r['route_name']) : '—' ?></td>
        <td><span class="emp-line"><?= $r['driver_name'] ? htmlspecialchars($r['driver_name'].' ('.$r['driver_code'].')') : '—' ?></span></td>
        <td><span class="emp-line"><?= $r['sales_rep_name'] ? htmlspecialchars($r['sales_rep_name'].' ('.$r['sales_rep_code'].')') : '—' ?></span></td>
        <td><span class="emp-line"><?= $r['helper_name'] ? htmlspecialchars($r['helper_name'].' ('.$r['helper_code'].')') : '—' ?></span></td>
        <td style="white-space:normal;max-width:200px;color:#6b7280;"><?= htmlspecialchars($r['remark'] ?: '—') ?></td>
        <td class="tc">
          <a href="vehicle_schedule_data_view.php?schedule_id=<?= $scheduleId ?>&route_id=<?= $r['id'] ?>" class="btn btn-primary btn-sm" title="View Position &amp; Park data for this row's date range">
            <i class="fa-solid fa-eye"></i>
          </a>
          <button class="btn btn-secondary btn-sm" onclick="editRoute(<?= $r['id'] ?>)" title="Edit"><i class="fa-solid fa-pen"></i></button>
          <button class="btn btn-danger btn-sm" onclick="deleteRoute(<?= $r['id'] ?>)" title="Delete"><i class="fa-solid fa-trash"></i></button>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$routeRows): ?>
      <tr id="route-empty-row"><td colspan="8" style="text-align:center;padding:30px;color:#9ca3af;">No route schedules yet — click "Add Route Schedule" above.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- Add/Edit Route Schedule modal -->
<div id="routeModal" class="vt-modal">
  <div class="vt-modal-content">
    <div class="vt-modal-hdr">
      <h3 id="routeModalTitle"><i class="fa-solid fa-route" style="margin-right:8px;"></i>Add Route Schedule</h3>
      <button class="vt-modal-close" onclick="closeRouteModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="vt-modal-body">
      <input type="hidden" id="rEditId" value="">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
        <div class="form-group">
          <label>Start Date</label>
          <input type="date" id="rDateFrom" class="form-control">
        </div>
        <div class="form-group">
          <label>End Date</label>
          <input type="date" id="rDateTo" class="form-control">
        </div>
      </div>
      <div class="form-group">
        <label>Route</label>
        <select id="rRoute" class="form-control select2-basic" data-dropdown-parent="#routeModal">
          <option value="">Select route…</option>
          <?php foreach ($vtRoutes as $rid => $rname): ?><option value="<?= $rid ?>"><?= htmlspecialchars($rname) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Driver</label>
        <select id="rDriver" class="form-control select2-basic" data-dropdown-parent="#routeModal">
          <option value="">Select driver…</option>
          <?php foreach ($vtDrivers as $eid => $ename): ?><option value="<?= $eid ?>"><?= htmlspecialchars($ename) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Sales Rep</label>
        <select id="rSalesRep" class="form-control select2-basic" data-dropdown-parent="#routeModal">
          <option value="">Select sales rep…</option>
          <?php foreach ($vtDrivers as $eid => $ename): ?><option value="<?= $eid ?>"><?= htmlspecialchars($ename) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Route Helper</label>
        <select id="rHelper" class="form-control select2-basic" data-dropdown-parent="#routeModal">
          <option value="">Select route helper…</option>
          <?php foreach ($vtDrivers as $eid => $ename): ?><option value="<?= $eid ?>"><?= htmlspecialchars($ename) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Remark</label>
        <textarea id="rRemark" class="form-control" rows="2" placeholder="Optional notes…"></textarea>
      </div>
      <button class="btn btn-success" id="routeSaveBtn" onclick="addRouteSchedule()" style="width:100%;height:42px;justify-content:center;">
        <i class="fa-solid fa-plus"></i> Add
      </button>
      <p id="routeModalHint" style="font-size:11.5px;color:#9ca3af;margin-top:10px;text-align:center;">Saved rows appear in the table behind this window — keep this open to add more than one.</p>
    </div>
  </div>
</div>

<div id="toast"></div>

<script>
$(function() {
  $('.select2-basic').each(function() {
    const parent = $(this).data('dropdown-parent');
    $(this).select2({ width:'100%', allowClear:true, dropdownParent: parent ? $(parent) : $(document.body) });
  });
});

/* ── Route Schedule modal ── */
function resetRouteForm() {
  document.getElementById('rEditId').value = '';
  document.getElementById('rDateFrom').value = '';
  document.getElementById('rDateTo').value = '';
  $('#rRoute, #rDriver, #rSalesRep, #rHelper').val('').trigger('change');
  document.getElementById('rRemark').value = '';
  document.getElementById('routeModalTitle').innerHTML = '<i class="fa-solid fa-route" style="margin-right:8px;"></i>Add Route Schedule';
  document.getElementById('routeSaveBtn').innerHTML = '<i class="fa-solid fa-plus"></i> Add';
  document.getElementById('routeModalHint').textContent = 'Saved rows appear in the table behind this window — keep this open to add more than one.';
}
function openRouteModal() { resetRouteForm(); document.getElementById('routeModal').classList.add('active'); }
function closeRouteModal() { document.getElementById('routeModal').classList.remove('active'); }
document.getElementById('routeModal').addEventListener('click', e => { if (e.target === e.currentTarget) closeRouteModal(); });

async function editRoute(id) {
  const fd = new FormData();
  fd.append('action','route_get');
  fd.append('id', id);
  const res = await fetch(window.location.href, {method:'POST', body:fd});
  const data = await res.json();
  if (!data.ok) { showToast(data.msg,'err'); return; }

  resetRouteForm();
  const row = data.row;
  document.getElementById('rEditId').value = row.id;
  document.getElementById('rDateFrom').value = row.date_from;
  document.getElementById('rDateTo').value = row.date_to;
  $('#rRoute').val(row.route_id || '').trigger('change');
  $('#rDriver').val(row.driver_id || '').trigger('change');
  $('#rSalesRep').val(row.sales_rep_id || '').trigger('change');
  $('#rHelper').val(row.route_helper_id || '').trigger('change');
  document.getElementById('rRemark').value = row.remark || '';
  document.getElementById('routeModalTitle').innerHTML = '<i class="fa-solid fa-pen" style="margin-right:8px;"></i>Edit Route Schedule';
  document.getElementById('routeSaveBtn').innerHTML = '<i class="fa-solid fa-check"></i> Save Changes';
  document.getElementById('routeModalHint').textContent = '';
  document.getElementById('routeModal').classList.add('active');
}

async function addRouteSchedule() {
  const editId   = document.getElementById('rEditId').value;
  const dateFrom = document.getElementById('rDateFrom').value;
  const dateTo   = document.getElementById('rDateTo').value || dateFrom;
  const routeId  = document.getElementById('rRoute').value;
  const driverId = document.getElementById('rDriver').value;
  const salesRepId = document.getElementById('rSalesRep').value;
  const helperId = document.getElementById('rHelper').value;
  const remark   = document.getElementById('rRemark').value;

  if (!dateFrom) { showToast('Start Date is required.','err'); return; }
  if (!routeId && !driverId && !salesRepId && !helperId) { showToast('Select at least a Route, Driver, Sales Rep or Route Helper.','err'); return; }

  const fd = new FormData();
  fd.append('action','route_add');
  fd.append('edit_id', editId);
  fd.append('date_from', dateFrom);
  fd.append('date_to', dateTo);
  fd.append('route_id', routeId);
  fd.append('driver_id', driverId);
  fd.append('sales_rep_id', salesRepId);
  fd.append('route_helper_id', helperId);
  fd.append('remark', remark);

  const res = await fetch(window.location.href, {method:'POST', body:fd});
  const data = await res.json();
  showToast(data.msg, data.ok?'ok':'err');
  if (data.ok) {
    resetRouteForm();
    setTimeout(() => window.location.reload(), 600);
  }
}

async function deleteRoute(id) {
  if (!confirm('Delete this route schedule row?')) return;
  const fd = new FormData();
  fd.append('action','route_delete');
  fd.append('id', id);
  const res = await fetch(window.location.href, {method:'POST', body:fd});
  const data = await res.json();
  showToast(data.msg, data.ok?'ok':'err');
  if (data.ok) document.getElementById('route-row-'+id)?.remove();
}

function showToast(msg,type) {
  const t = document.getElementById('toast');
  t.className = type==='ok' ? 'toast-ok' : 'toast-err';
  t.textContent = msg; t.style.display='block'; t.style.opacity='1';
  clearTimeout(t._t);
  t._t = setTimeout(()=>{t.style.opacity='0';setTimeout(()=>t.style.display='none',300);},3200);
}
</script>

<?php include 'footer.php'; ?>