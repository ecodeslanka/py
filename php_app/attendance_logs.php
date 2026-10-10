<?php
include 'config.php';
include 'header.php';

// Filters
$filter_employee = mysqli_real_escape_string($conn, $_GET['employee'] ?? '');
$filter_date     = mysqli_real_escape_string($conn, $_GET['date'] ?? date('Y-m-d'));
$filter_machine  = intval($_GET['machine_id'] ?? 0);

// Build WHERE
$where = ["DATE(a.event_time) = '$filter_date'"];
if ($filter_employee) $where[] = "a.employee_no LIKE '%$filter_employee%'";
if ($filter_machine)  $where[] = "a.machine_id = $filter_machine";
$where_sql = 'WHERE ' . implode(' AND ', $where);

// Get logs
$logs_sql = "SELECT a.*, fm.machine_name, fm.machine_code, b.branch_name
             FROM fp_attendance_logs a
             LEFT JOIN fingerprint_machines fm ON a.machine_id = fm.id
             LEFT JOIN branches b ON fm.branch_id = b.id
             $where_sql
             ORDER BY a.event_time DESC
             LIMIT 500";
$logs_result = mysqli_query($conn, $logs_sql);

// Get machines for filter
$machines_result = mysqli_query($conn, "SELECT id, machine_code, machine_name FROM fingerprint_machines WHERE active=1 ORDER BY machine_name");

// Stats for today
$stats = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as total,
            COUNT(DISTINCT employee_no) as unique_employees
     FROM fp_attendance_logs
     WHERE DATE(event_time) = '$filter_date'"
));

// Receiver log count
$recv_logs = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as total,
            SUM(status='saved') as saved,
            SUM(status='error') as errors,
            SUM(status='duplicate') as dupes
     FROM fp_receiver_logs
     WHERE DATE(created_at) = '$filter_date'"
));
?>

<div class="page-header">
    <h2 class="page-title">Attendance Logs</h2>
    <p class="page-subtitle">Real-time fingerprint scan records from DS-K1T804BMF</p>
</div>

<!-- Stats Cards -->
<div class="stats-row">
    <div class="stat-card">
        <div class="stat-icon" style="background:#f0fdf4; color:#16a34a;">
            <i class="fa-solid fa-fingerprint"></i>
        </div>
        <div class="stat-info">
            <div class="stat-number"><?php echo number_format($stats['total']); ?></div>
            <div class="stat-label">Total Scans Today</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#eff6ff; color:#1e40af;">
            <i class="fa-solid fa-users"></i>
        </div>
        <div class="stat-info">
            <div class="stat-number"><?php echo number_format($stats['unique_employees']); ?></div>
            <div class="stat-label">Unique Employees</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fefce8; color:#a16207;">
            <i class="fa-solid fa-server"></i>
        </div>
        <div class="stat-info">
            <div class="stat-number"><?php echo number_format($recv_logs['saved'] ?? 0); ?></div>
            <div class="stat-label">Events Received</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fef2f2; color:#991b1b;">
            <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
        <div class="stat-info">
            <div class="stat-number"><?php echo number_format($recv_logs['errors'] ?? 0); ?></div>
            <div class="stat-label">Errors</div>
        </div>
    </div>
</div>

<!-- Receiver URL Info Card -->
<div class="content-card" style="margin-bottom:20px; border-left: 4px solid #000;">
    <div style="display:flex; align-items:flex-start; gap:16px;">
        <i class="fa-solid fa-circle-info" style="font-size:20px; color:#666; margin-top:2px;"></i>
        <div>
            <strong style="font-size:14px;">Device Push URL (set this in your fingerprint machine)</strong><br>
            <code class="url-badge">
                <?php echo 'https://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/fingerprint_receiver.php'; ?>
            </code>
            <br>
            <small style="color:#666; margin-top:6px; display:block;">
                Go to device login → <strong>Configuration → Network → Advanced → HTTP Listening</strong> → paste URL above
            </small>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="content-card" style="margin-bottom:20px;">
    <form method="GET" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <div class="form-group" style="margin:0; flex:1; min-width:150px;">
            <label class="form-label">Date</label>
            <input type="date" name="date" class="form-input" value="<?php echo htmlspecialchars($filter_date); ?>">
        </div>
        <div class="form-group" style="margin:0; flex:1; min-width:150px;">
            <label class="form-label">Employee No</label>
            <input type="text" name="employee" class="form-input" placeholder="Search employee..." value="<?php echo htmlspecialchars($filter_employee); ?>">
        </div>
        <div class="form-group" style="margin:0; flex:1; min-width:150px;">
            <label class="form-label">Machine</label>
            <select name="machine_id" class="form-input">
                <option value="">All Machines</option>
                <?php while ($m = mysqli_fetch_assoc($machines_result)): ?>
                    <option value="<?php echo $m['id']; ?>" <?php echo $filter_machine == $m['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($m['machine_code'] . ' - ' . $m['machine_name']); ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>
        <div>
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-filter"></i> Filter
            </button>
            <a href="?" class="btn btn-secondary" style="margin-left:8px;">
                <i class="fa-solid fa-rotate"></i> Reset
            </a>
        </div>
    </form>
</div>

<!-- Logs Table -->
<div class="content-card">
    <h3 class="card-title">
        <i class="fa-solid fa-list" style="margin-right:8px;"></i>
        Scan Records — <?php echo date('F d, Y', strtotime($filter_date)); ?>
        <span style="font-weight:400; font-size:13px; color:#666; margin-left:8px;">
            (<?php echo mysqli_num_rows($logs_result); ?> records)
        </span>
    </h3>

    <?php if (mysqli_num_rows($logs_result) > 0): ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Employee No</th>
                    <th>Date & Time</th>
                    <th>Machine</th>
                    <th>Branch</th>
                    <th>Event Type</th>
                    <th>Door</th>
                    <th>Verify Mode</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; while ($log = mysqli_fetch_assoc($logs_result)): ?>
                <tr>
                    <td style="color:#999;"><?php echo $i++; ?></td>
                    <td><strong><?php echo htmlspecialchars($log['employee_no']); ?></strong></td>
                    <td>
                        <strong><?php echo date('h:i:s A', strtotime($log['event_time'])); ?></strong><br>
                        <small style="color:#999;"><?php echo date('M d, Y', strtotime($log['event_time'])); ?></small>
                    </td>
                    <td>
                        <?php if ($log['machine_code']): ?>
                            <strong><?php echo htmlspecialchars($log['machine_code']); ?></strong><br>
                            <small style="color:#666;"><?php echo htmlspecialchars($log['machine_name']); ?></small>
                        <?php else: ?>
                            <small style="color:#999;">Unknown (<?php echo htmlspecialchars($log['ip_source']); ?>)</small>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($log['branch_name'] ?? '-'); ?></td>
                    <td>
                        <?php
                        $typeColors = [
                            'fingerprint'       => ['#f0fdf4','#166534','#bbf7d0'],
                            'card'              => ['#eff6ff','#1e40af','#bfdbfe'],
                            'face'              => ['#fefce8','#a16207','#fde68a'],
                            'password'          => ['#fdf4ff','#7e22ce','#e9d5ff'],
                            'card+fingerprint'  => ['#f0fdf4','#166534','#bbf7d0'],
                            'face+fingerprint'  => ['#fff7ed','#c2410c','#fed7aa'],
                            'door_open'         => ['#f0fdf4','#166534','#bbf7d0'],
                            'door_closed'       => ['#fafafa','#666','#e5e5e5'],
                        ];
                        $et = $log['event_type'] ?? 'access_event';
                        $c  = $typeColors[$et] ?? ['#fafafa','#666','#e5e5e5'];
                        ?>
                        <span class="badge" style="background:<?php echo $c[0];?>;color:<?php echo $c[1];?>;border:1px solid <?php echo $c[2];?>;">
                            <i class="fa-solid fa-<?php echo $et === 'fingerprint' ? 'fingerprint' : ($et === 'card' ? 'id-card' : ($et === 'face' || $et === 'face+fingerprint' ? 'face-smile' : 'key')); ?>"></i>
                            <?php echo ucwords(str_replace('_', ' ', $et)); ?>
                        </span>
                    </td>
                    <td><span style="color:#666;">Door <?php echo $log['door_no']; ?></span></td>
                    <td><small style="color:#999;"><?php echo htmlspecialchars($log['verify_mode'] ?? '-'); ?></small></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p style="color:#666; font-size:13px; text-align:center; padding:40px 20px;">
        <i class="fa-solid fa-fingerprint" style="font-size:32px; display:block; margin-bottom:12px; color:#ccc;"></i>
        No attendance records found for <?php echo date('F d, Y', strtotime($filter_date)); ?>.
        <br><small style="margin-top:8px; display:block;">Make sure the device HTTP Listening URL is configured correctly.</small>
    </p>
    <?php endif; ?>
</div>

<!-- Receiver Debug Logs -->
<div class="content-card" style="margin-top:20px;">
    <h3 class="card-title" style="cursor:pointer;" onclick="toggleDebug()">
        <i class="fa-solid fa-bug" style="margin-right:8px;"></i>
        Receiver Debug Log
        <small style="font-weight:400; color:#999; margin-left:8px;">(click to expand)</small>
    </h3>
    <div id="debugTable" style="display:none; margin-top:16px;">
        <?php
        $debug_result = mysqli_query($conn,
            "SELECT * FROM fp_receiver_logs
             WHERE DATE(created_at) = '$filter_date'
             ORDER BY created_at DESC LIMIT 50"
        );
        if ($debug_result && mysqli_num_rows($debug_result) > 0):
        ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr><th>Time</th><th>IP</th><th>Status</th><th>Note</th></tr>
                </thead>
                <tbody>
                    <?php while ($dl = mysqli_fetch_assoc($debug_result)): ?>
                    <tr>
                        <td><small><?php echo date('H:i:s', strtotime($dl['created_at'])); ?></small></td>
                        <td><code style="font-size:11px;"><?php echo htmlspecialchars($dl['ip_source']); ?></code></td>
                        <td>
                            <?php
                            $sc = ['saved'=>'badge-success','error'=>'badge-error','duplicate'=>'badge-dupe','rejected'=>'badge-error','no_employee'=>'badge-warn'];
                            $cls = $sc[$dl['status']] ?? 'badge-inactive';
                            ?>
                            <span class="badge <?php echo $cls; ?>"><?php echo htmlspecialchars($dl['status']); ?></span>
                        </td>
                        <td><small style="color:#666;"><?php echo htmlspecialchars($dl['note']); ?></small></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p style="color:#999; font-size:13px; text-align:center; padding:20px;">No receiver logs for this date.</p>
        <?php endif; ?>
    </div>
</div>

<style>
.stats-row { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:24px; }
.stat-card { background:#fff; border:1px solid #e5e5e5; border-radius:12px; padding:20px; display:flex; align-items:center; gap:16px; }
.stat-icon { width:48px; height:48px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
.stat-number { font-size:24px; font-weight:700; color:#111; }
.stat-label  { font-size:12px; color:#666; margin-top:2px; }

.url-badge {
    display:inline-block; background:#f9fafb; border:1px solid #e5e5e5;
    border-radius:6px; padding:6px 12px; font-size:13px;
    font-family:monospace; color:#333; margin-top:6px; word-break:break-all;
}

/* Alert */
.alert { padding:16px 20px; border-radius:8px; margin-bottom:24px; display:flex; align-items:center; gap:12px; font-size:13px; font-weight:500; }
.alert-success { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
.alert-error   { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }

/* Form */
.form-group { margin-bottom:20px; }
.form-label { display:block; font-size:13px; font-weight:600; margin-bottom:8px; color:#333333; }
.form-input { width:100%; padding:12px 16px; border:1px solid #e5e5e5; border-radius:8px; font-size:14px; font-family:'Inter',sans-serif; transition:all 0.3s; background:#ffffff; box-sizing:border-box; }
.form-input:focus { outline:none; border-color:#000000; box-shadow:0 0 0 3px rgba(0,0,0,0.05); }
select.form-input { cursor:pointer; }

/* Buttons */
.btn { display:inline-flex; align-items:center; gap:8px; padding:12px 24px; border:none; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; transition:all 0.3s; text-decoration:none; font-family:'Inter',sans-serif; }
.btn-primary   { background:#000000; color:#ffffff; }
.btn-primary:hover { background:#333333; }
.btn-secondary { background:#f5f5f5; color:#333333; border:1px solid #e5e5e5; }
.btn-secondary:hover { background:#e5e5e5; }

/* Table */
.table-responsive { overflow-x:auto; margin-top:20px; }
.data-table { width:100%; border-collapse:collapse; font-size:13px; }
.data-table thead { background:#fafafa; border-bottom:2px solid #e5e5e5; }
.data-table th { padding:12px 16px; text-align:left; font-weight:600; color:#333333; font-size:12px; text-transform:uppercase; letter-spacing:0.5px; }
.data-table tbody tr { border-bottom:1px solid #f0f0f0; transition:background 0.2s; }
.data-table tbody tr:hover { background:#fafafa; }
.data-table td { padding:14px 16px; color:#333333; }

/* Badges */
.badge { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:12px; font-size:11px; font-weight:600; }
.badge-success  { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
.badge-inactive { background:#fafafa; color:#666666; border:1px solid #e5e5e5; }
.badge-error    { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
.badge-dupe     { background:#fefce8; color:#a16207; border:1px solid #fde68a; }
.badge-warn     { background:#fff7ed; color:#c2410c; border:1px solid #fed7aa; }

@media (max-width:768px) {
    .stats-row { grid-template-columns:1fr 1fr; }
}
@media (max-width:480px) {
    .stats-row { grid-template-columns:1fr; }
}
</style>

<script>
function toggleDebug() {
    const el = document.getElementById('debugTable');
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

// Auto-refresh every 30 seconds to show new scans
setTimeout(() => location.reload(), 30000);
</script>

<?php include 'footer.php'; ?>
