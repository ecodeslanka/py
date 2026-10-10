<?php
include 'config.php';
include 'vt_functions.php';
vt_ensure_tables($conn);
vt_ensure_schedule_tables($conn);

/* ══════════════════════════ AJAX: create a new Vehicle Schedule (Vehicle + Month + Year) ══════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_schedule') {
    header('Content-Type: application/json');
    $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
    $month     = (int)($_POST['month'] ?? 0);
    $year      = (int)($_POST['year'] ?? 0);
    $createdBy = $_SESSION['emp_name'] ?? $_SESSION['hms_user_name'] ?? ($_SESSION['username'] ?? null);

    if (!$vehicleId || $month < 1 || $month > 12 || $year < 2000) {
        echo json_encode(['ok'=>false,'msg'=>'Select a vehicle, month and year.']);
        exit;
    }

    $exists = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM vehicle_schedules WHERE vehicle_id=$vehicleId AND month=$month AND year=$year"));
    if ($exists) {
        echo json_encode(['ok'=>true,'existing'=>true,'id'=>$exists['id'],'msg'=>'That schedule already exists — opening it.']);
        exit;
    }

    mysqli_query($conn, "INSERT INTO vehicle_schedules (vehicle_id, month, year, created_by) VALUES ($vehicleId, $month, $year, ".vt_esc($conn,$createdBy).")");
    $id = mysqli_insert_id($conn);
    if (!$id) { echo json_encode(['ok'=>false,'msg'=>mysqli_error($conn)]); exit; }
    echo json_encode(['ok'=>true,'existing'=>false,'id'=>$id,'msg'=>'Schedule created.']);
    exit;
}

/* ══════════════════════════ AJAX: delete a whole Vehicle Schedule (one click — removes all its route rows too) ══════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_schedule') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'msg'=>'Missing schedule id.']); exit; }

    try {
        mysqli_begin_transaction($conn);
        mysqli_query($conn, "DELETE FROM vehicle_schedule_routes WHERE schedule_id=$id");
        $routesDeleted = mysqli_affected_rows($conn);
        mysqli_query($conn, "UPDATE vt_uploads SET schedule_id=NULL WHERE schedule_id=$id"); // keep the uploaded data, just unlink
        mysqli_query($conn, "DELETE FROM vehicle_schedules WHERE id=$id");
        mysqli_commit($conn);
        echo json_encode(['ok'=>true,'msg'=>"Deleted schedule and $routesDeleted route row(s)."]);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        echo json_encode(['ok'=>false,'msg'=>'Delete failed: '.$e->getMessage()]);
    }
    exit;
}

/* ══════════════════════════ filters ══════════════════════════ */
$fMonth   = (int)($_GET['month'] ?? date('n'));
$fYear    = (int)($_GET['year']  ?? date('Y'));
$fVehicle = (int)($_GET['vehicle_id'] ?? 0);

$vtVehicles = vt_all_vehicles($conn);
$months = vt_month_names();

$where = ["s.month=$fMonth", "s.year=$fYear"];
if ($fVehicle) $where[] = "s.vehicle_id=$fVehicle";
$whereSql = implode(' AND ', $where);

$schedules = [];
$res = mysqli_query($conn, "
    SELECT s.*, v.vehicle_number,
           (SELECT COUNT(*) FROM vehicle_schedule_routes r WHERE r.schedule_id=s.id) AS route_count
    FROM vehicle_schedules s
    LEFT JOIN vehicles v ON s.vehicle_id = v.id
    WHERE $whereSql
    ORDER BY v.vehicle_number ASC");
if ($res) while ($r = mysqli_fetch_assoc($res)) $schedules[] = $r;

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

.filter-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 18px;margin-bottom:18px;display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;}
.filter-group{display:flex;flex-direction:column;gap:5px;}
.filter-group label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;}
.filter-group select, .filter-group input{padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:inherit;min-width:160px;}

.sched-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;}
.sched-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
.sched-card h3{margin:0 0 4px;font-size:16px;font-weight:700;color:#111827;display:flex;align-items:center;gap:8px;}
.sched-card .sub{font-size:12px;color:#6b7280;margin-bottom:14px;}
.sched-card .routecount{display:inline-block;background:#eff6ff;color:#1e40af;padding:3px 10px;border-radius:20px;font-size:11.5px;font-weight:700;margin-bottom:14px;}
.sched-card .actions{display:flex;gap:8px;}
.sched-card .actions .btn{flex:1;justify-content:center;}

.vt-modal{display:none;position:fixed;inset:0;background:rgba(17,24,39,.55);z-index:9998;align-items:flex-start;justify-content:center;padding:60px 16px;overflow-y:auto;}
.vt-modal.active{display:flex;}
.vt-modal-content{background:#fff;border-radius:14px;width:100%;max-width:460px;box-shadow:0 20px 60px rgba(0,0,0,.3);}
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
      <i class="fa-solid fa-calendar-days" style="color:#1e40af;margin-right:8px;"></i>Vehicle Schedule
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">One schedule per vehicle per month. Open a schedule to add route assignments and upload GPS report files.</p>
  </div>
  <button class="btn btn-success" onclick="openCreateModal()"><i class="fa-solid fa-plus"></i> Create Schedule</button>
</div>

<form class="filter-card" method="get">
  <div class="filter-group">
    <label>Month</label>
    <select name="month" class="form-control">
      <?php foreach ($months as $mNum => $mName): ?>
        <option value="<?= $mNum ?>" <?= $fMonth===$mNum?'selected':'' ?>><?= $mName ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-group">
    <label>Year</label>
    <input type="number" name="year" class="form-control" value="<?= $fYear ?>" min="2020" max="2100">
  </div>
  <div class="filter-group">
    <label>Lorry No</label>
    <select name="vehicle_id" class="select2-basic form-control">
      <option value="">All Vehicles</option>
      <?php foreach ($vtVehicles as $vid => $vnum): ?>
        <option value="<?= $vid ?>" <?= $fVehicle===$vid?'selected':'' ?>><?= htmlspecialchars($vnum) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-group">
    <button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
  </div>
</form>

<?php if (!$schedules): ?>
  <div style="text-align:center;padding:60px 20px;color:#9ca3af;">
    <i class="fa-solid fa-calendar-xmark" style="font-size:38px;margin-bottom:12px;display:block;"></i>
    No schedules for <?= $months[$fMonth] ?> <?= $fYear ?>. Click <b>Create Schedule</b> to add one.
  </div>
<?php else: ?>
  <div class="sched-grid">
    <?php foreach ($schedules as $s): ?>
      <div class="sched-card" id="sched-card-<?= $s['id'] ?>">
        <h3><i class="fa-solid fa-truck" style="color:#1e40af;"></i> <?= htmlspecialchars($s['vehicle_number'] ?: '—') ?></h3>
        <div class="sub"><?= $months[$s['month']] ?> <?= $s['year'] ?></div>
        <div class="routecount"><i class="fa-solid fa-route"></i> <?= $s['route_count'] ?> route entr<?= $s['route_count']==1?'y':'ies' ?></div>
        <div class="actions">
          <a href="vehicle_schedule_view.php?id=<?= $s['id'] ?>" class="btn btn-primary"><i class="fa-solid fa-eye"></i> View</a>
          <button class="btn btn-danger" onclick="deleteSchedule(<?= $s['id'] ?>, this)" title="Delete this schedule and all its route rows — cannot be undone">
            <i class="fa-solid fa-trash"></i>
          </button>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- Create schedule modal -->
<div id="createModal" class="vt-modal">
  <div class="vt-modal-content">
    <div class="vt-modal-hdr">
      <h3><i class="fa-solid fa-calendar-plus" style="margin-right:8px;"></i>Create Vehicle Schedule</h3>
      <button class="vt-modal-close" onclick="closeCreateModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="vt-modal-body">
      <div class="form-group">
        <label>Lorry No (Vehicle)</label>
        <select id="cVehicle" class="form-control select2-basic" data-dropdown-parent="#createModal">
          <option value="">Select vehicle…</option>
          <?php foreach ($vtVehicles as $vid => $vnum): ?><option value="<?= $vid ?>"><?= htmlspecialchars($vnum) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
        <div class="form-group">
          <label>Month</label>
          <select id="cMonth" class="form-control">
            <?php foreach ($months as $mNum => $mName): ?>
              <option value="<?= $mNum ?>" <?= $mNum===(int)date('n')?'selected':'' ?>><?= $mName ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Year</label>
          <input type="number" id="cYear" class="form-control" value="<?= date('Y') ?>" min="2020" max="2100">
        </div>
      </div>
      <button class="btn btn-success" onclick="createSchedule()" style="width:100%;height:42px;justify-content:center;">
        <i class="fa-solid fa-check"></i> Create &amp; Open
      </button>
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

function openCreateModal() { document.getElementById('createModal').classList.add('active'); }
function closeCreateModal() { document.getElementById('createModal').classList.remove('active'); }
document.getElementById('createModal').addEventListener('click', e => { if (e.target === e.currentTarget) closeCreateModal(); });

async function createSchedule() {
  const vehicleId = document.getElementById('cVehicle').value;
  const month = document.getElementById('cMonth').value;
  const year  = document.getElementById('cYear').value;
  if (!vehicleId || !month || !year) { showToast('Select vehicle, month and year.','err'); return; }

  const fd = new FormData();
  fd.append('action','create_schedule');
  fd.append('vehicle_id', vehicleId);
  fd.append('month', month);
  fd.append('year', year);

  const res = await fetch('vehicle_schedule.php', {method:'POST', body:fd});
  const data = await res.json();
  if (data.ok) {
    showToast(data.msg,'ok');
    setTimeout(() => window.location.href = 'vehicle_schedule_view.php?id='+data.id, 500);
  } else {
    showToast(data.msg,'err');
  }
}

async function deleteSchedule(id, btn) {
  if (!confirm('Permanently delete this schedule and ALL its route rows? Uploaded GPS files stay but will be unlinked. This cannot be undone.')) return;
  btn.disabled = true;
  const fd = new FormData();
  fd.append('action','delete_schedule');
  fd.append('id', id);
  try {
    const res = await fetch('vehicle_schedule.php', {method:'POST', body:fd});
    const data = await res.json();
    showToast(data.msg, data.ok?'ok':'err');
    if (data.ok) document.getElementById('sched-card-'+id)?.remove();
    else btn.disabled = false;
  } catch(e) {
    showToast('Network error.','err');
    btn.disabled = false;
  }
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
