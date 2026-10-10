<?php
include 'config.php';
include 'vt_functions.php';
vt_ensure_tables($conn);

$vehicleId = (int)($_GET['vehicle_id'] ?? 0);
$useRange  = isset($_GET['use_range']) && $_GET['use_range'] === '1';
$fDate     = trim($_GET['date'] ?? '');
$fDateFrom = trim($_GET['date_from'] ?? '');
$fDateTo   = trim($_GET['date_to'] ?? '');

if (!$vehicleId) {
    include 'header.php';
    echo '<div style="text-align:center;padding:60px 20px;color:#9ca3af;">
            <i class="fa-solid fa-triangle-exclamation" style="font-size:38px;margin-bottom:12px;display:block;"></i>
            No vehicle selected. Go back to <a href="park_report_import.php">Park Report Import</a>.
          </div>';
    include 'footer.php';
    exit;
}

$vehicle = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, vehicle_number FROM vehicles WHERE id=$vehicleId"));
if (!$vehicle) {
    include 'header.php';
    echo '<div style="text-align:center;padding:60px 20px;color:#9ca3af;">Vehicle not found.</div>';
    include 'footer.php';
    exit;
}

/* ══════════════════════════ resolve default single date (latest available) ══════════════════════════ */
$availDates = [];
$dRes = mysqli_query($conn, "SELECT DISTINCT DATE(start_time) AS d FROM vt_park_reports WHERE vehicle_id=$vehicleId ORDER BY d ASC");
if ($dRes) while ($d = mysqli_fetch_assoc($dRes)) $availDates[] = $d['d'];
$minDate = $availDates ? $availDates[0] : date('Y-m-d');
$maxDate = $availDates ? end($availDates) : date('Y-m-d');
if ($fDate === '' && !$useRange) $fDate = $maxDate; // default: latest available date, single-date mode

/* ══════════════════════════ build date filter (using resolved default) ══════════════════════════ */
$where = ["vehicle_id=$vehicleId"];
$activeFilterLabel = 'All dates';
if ($useRange && $fDateFrom !== '' && $fDateTo !== '') {
    $dF = vt_esc($conn, $fDateFrom); $dT = vt_esc($conn, $fDateTo);
    $where[] = "DATE(start_time) BETWEEN $dF AND $dT";
    $activeFilterLabel = date('d M Y', strtotime($fDateFrom)).' - '.date('d M Y', strtotime($fDateTo));
} elseif (!$useRange && $fDate !== '') {
    $d = vt_esc($conn, $fDate);
    $where[] = "DATE(start_time) = $d";
    $activeFilterLabel = date('d M Y', strtotime($fDate));
}
$whereSql = implode(' AND ', $where);

/* summary over full vehicle history (not filtered) */
$summary = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(*) AS total_rows, MIN(start_time) AS date_from, MAX(end_time) AS date_to,
           SUM(park_seconds) AS total_seconds, MAX(park_seconds) AS longest_seconds
    FROM vt_park_reports WHERE vehicle_id=$vehicleId"));

/* filtered rows */
$rows = [];
$res = mysqli_query($conn, "SELECT * FROM vt_park_reports WHERE $whereSql ORDER BY start_time ASC");
if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;

$filteredTotalSeconds = 0;
foreach ($rows as $r) $filteredTotalSeconds += (int)($r['park_seconds'] ?? 0);

function parkFmtSeconds($sec) {
    if ($sec === null) return '—';
    $sec = (int)$sec;
    $h = intdiv($sec, 3600); $m = intdiv($sec % 3600, 60); $s = $sec % 60;
    $out = '';
    if ($h) $out .= $h.'H ';
    if ($h || $m) $out .= $m.'M ';
    $out .= $s.'S';
    return trim($out);
}

include 'header.php';
?>
<style>
*{box-sizing:border-box;}
.ph-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:18px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-primary{background:#1e40af;color:#fff;}.btn-primary:hover{background:#1e3a8a;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}

.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:18px;}
.summary-tile{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;}
.summary-tile .label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;}
.summary-tile .value{font-size:17px;font-weight:700;color:#111827;}

.filter-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 18px;margin-bottom:16px;}
.filter-row{display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;}
.filter-group{display:flex;flex-direction:column;gap:5px;}
.filter-group label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;}
.filter-group select, .filter-group input{padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:inherit;min-width:150px;}
.chk-row{display:flex;align-items:center;gap:8px;padding-bottom:8px;}
.chk-row label{font-size:12.5px;font-weight:600;color:#374151;}

.table-wrap{background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:auto;}
table.pk-table{border-collapse:collapse;width:100%;font-size:12.5px;white-space:nowrap;}
table.pk-table thead th{position:sticky;top:0;background:#111827;color:#fff;padding:9px 10px;text-align:left;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.3px;z-index:1;}
table.pk-table tbody td{padding:7px 10px;border-bottom:1px solid #f1f5f9;color:#1f2937;}
table.pk-table tbody tr:nth-child(even){background:#fafbfc;}
table.pk-table tbody tr:hover{background:#eff6ff;}
.addr-cell{white-space:normal;max-width:380px;min-width:240px;color:#6b7280;font-size:12px;}
.dur-badge{background:#eff6ff;color:#1e40af;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;}
.dur-badge.long{background:#fef2f2;color:#991b1b;}

.empty-state{text-align:center;padding:60px 20px;color:#9ca3af;}
.info-line{font-size:12.5px;color:#6b7280;margin:10px 0 14px;}
</style>

<div class="ph-row">
  <div>
    <h2 style="margin:0;font-size:18px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-square-parking" style="color:#1e40af;margin-right:8px;"></i>
      Park Report Preview — <?= htmlspecialchars($vehicle['vehicle_number'] ?: '—') ?>
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">Showing: <?= $activeFilterLabel ?></p>
  </div>
  <a href="park_report_import.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to Import</a>
</div>

<div class="summary-grid">
  <div class="summary-tile">
    <div class="label">Total Park Events</div>
    <div class="value"><?= number_format($summary['total_rows'] ?? 0) ?></div>
  </div>
  <div class="summary-tile">
    <div class="label">Data Range</div>
    <div class="value" style="font-size:13.5px;">
      <?= $summary['date_from'] ? date('d M Y', strtotime($summary['date_from'])) : '—' ?>
      &rarr;
      <?= $summary['date_to'] ? date('d M Y', strtotime($summary['date_to'])) : '—' ?>
    </div>
  </div>
  <div class="summary-tile">
    <div class="label">Total Park Time (All)</div>
    <div class="value" style="font-size:14.5px;"><?= parkFmtSeconds($summary['total_seconds'] ?? 0) ?></div>
  </div>
  <div class="summary-tile">
    <div class="label">Longest Single Park</div>
    <div class="value" style="font-size:14.5px;"><?= parkFmtSeconds($summary['longest_seconds'] ?? 0) ?></div>
  </div>
  <div class="summary-tile">
    <div class="label">Park Time (Filtered)</div>
    <div class="value" style="font-size:14.5px;"><?= parkFmtSeconds($filteredTotalSeconds) ?></div>
  </div>
</div>

<form class="filter-card" method="get" id="filterForm">
  <input type="hidden" name="vehicle_id" value="<?= $vehicleId ?>">
  <div class="filter-row">
    <div class="chk-row">
      <input type="checkbox" id="fUseRange" name="use_range" value="1" <?= $useRange?'checked':'' ?> onchange="toggleFilterMode()">
      <label for="fUseRange">Date range (instead of a single date)</label>
    </div>

    <div id="singleDateWrap" class="filter-group" style="<?= $useRange?'display:none;':'' ?>">
      <label>Date</label>
      <input type="date" name="date" value="<?= htmlspecialchars($fDate) ?>" min="<?= $minDate ?>" max="<?= $maxDate ?>">
    </div>

    <div id="rangeDateWrap" class="filter-row" style="<?= $useRange?'':'display:none;' ?>">
      <div class="filter-group">
        <label>From Date</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($fDateFrom ?: $minDate) ?>" min="<?= $minDate ?>" max="<?= $maxDate ?>">
      </div>
      <div class="filter-group">
        <label>To Date</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($fDateTo ?: $maxDate) ?>" min="<?= $minDate ?>" max="<?= $maxDate ?>">
      </div>
    </div>

    <div class="filter-group">
      <button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter"></i> Apply</button>
    </div>
    <div class="filter-group">
      <a href="?vehicle_id=<?= $vehicleId ?>&use_range=1&date_from=<?= $minDate ?>&date_to=<?= $maxDate ?>" class="btn btn-secondary"><i class="fa-solid fa-calendar-week"></i> Show All Dates</a>
    </div>
  </div>
</form>

<?php if (!$rows): ?>
  <div class="empty-state">
    <i class="fa-solid fa-inbox" style="font-size:38px;margin-bottom:12px;display:block;"></i>
    No park events for <?= $activeFilterLabel ?>.
  </div>
<?php else: ?>
  <div class="info-line">Showing <?= number_format(count($rows)) ?> park event(s) for <?= $activeFilterLabel ?>.</div>
  <div class="table-wrap">
    <table class="pk-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Device</th>
          <th>Start Time</th>
          <th>End Time</th>
          <th>Park Duration</th>
          <th>Address</th>
          <th>Longitude</th>
          <th>Latitude</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $i => $r): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><?= htmlspecialchars($r['device_name'] ?: '—') ?></td>
            <td><?= htmlspecialchars($r['start_time']) ?></td>
            <td><?= htmlspecialchars($r['end_time']) ?></td>
            <td><span class="dur-badge <?= ((int)$r['park_seconds'] >= 3600 ? 'long' : '') ?>"><?= htmlspecialchars($r['park_duration'] ?: parkFmtSeconds($r['park_seconds'])) ?></span></td>
            <td class="addr-cell"><?= htmlspecialchars($r['address'] ?: '—') ?></td>
            <td><?= $r['longitude'] !== null ? $r['longitude'] : '—' ?></td>
            <td><?= $r['latitude'] !== null ? $r['latitude'] : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<script>
function toggleFilterMode() {
  const useRange = document.getElementById('fUseRange').checked;
  document.getElementById('singleDateWrap').style.display = useRange ? 'none' : 'flex';
  document.getElementById('rangeDateWrap').style.display = useRange ? 'flex' : 'none';
}
</script>

<?php include 'footer.php'; ?>
