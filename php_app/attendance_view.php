<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'u645685294_ai');
define('DB_USER', 'u645685294_ai');
define('DB_PASS', 'SupunKadu@123');


try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8",
                   DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $search = $_GET['s'] ?? '';
    $method = $_GET['m'] ?? '';
    $status = $_GET['st'] ?? '';
    $date   = $_GET['d']  ?? '';

    $where = ['1=1'];
    $params = [];
    if ($search) { $where[] = '(name LIKE ? OR employee_no LIKE ?)'; $params[]="%$search%"; $params[]="%$search%"; }
    if ($method) { $where[] = 'method = ?'; $params[] = $method; }
    if ($status) { $where[] = 'status = ?'; $params[] = $status; }
    if ($date)   { $where[] = 'DATE(event_time) = ?'; $params[] = $date; }

    $sql = "SELECT * FROM attendance_logs WHERE ".implode(' AND ',$where)." ORDER BY event_time DESC LIMIT 500";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total = $pdo->query("SELECT COUNT(*) FROM attendance_logs")->fetchColumn();
    $today = $pdo->query("SELECT COUNT(*) FROM attendance_logs WHERE DATE(event_time)=CURDATE()")->fetchColumn();
    $users = $pdo->query("SELECT COUNT(DISTINCT employee_no) FROM attendance_logs")->fetchColumn();
    $last  = $pdo->query("SELECT event_time FROM attendance_logs ORDER BY event_time DESC LIMIT 1")->fetchColumn();
} catch (Exception $e) {
    $logs=[]; $total=$today=$users=0; $last='—';
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DS-K1T804BMF — Attendance</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=IBM+Plex+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#f5f4f0;--s:#fff;--b:#e0ddd6;--ac:#d64a00;--as:#fff0eb;--tx:#1a1a18;--dm:#888880;--gn:#1a7a45;--gs:#edf7f1;--rd:#c0392b;--rs:#fdf0ef;--mo:'IBM Plex Mono',monospace;--sa:'IBM Plex Sans',sans-serif;--sh:0 1px 3px rgba(0,0,0,.08),0 4px 16px rgba(0,0,0,.04)}
*{margin:0;padding:0;box-sizing:border-box}body{background:var(--bg);font-family:var(--sa);color:var(--tx);min-height:100vh}
.bar{background:var(--tx);color:#fff;padding:0 28px;height:50px;display:flex;align-items:center;justify-content:space-between}
.brd{display:flex;align-items:center;gap:12px}.bic{width:26px;height:26px;background:var(--ac);border-radius:5px;display:grid;place-items:center;font-size:14px}
.bnm{font-family:var(--mo);font-size:13px;font-weight:600}.bnm span{color:#999;font-weight:400;margin-left:6px}
.live{display:flex;align-items:center;gap:7px;font-family:var(--mo);font-size:11px;color:#aaa}
.dot{width:7px;height:7px;border-radius:50%;background:#2ecc71;box-shadow:0 0 6px #2ecc71;animation:pulse 1.5s infinite}
.wrap{max-width:1080px;margin:0 auto;padding:24px 20px}
<?php if(!empty($error)): ?>.err-banner{background:#fdf0ef;border:1px solid #f5c6c2;border-left:4px solid var(--rd);border-radius:8px;padding:14px 18px;margin-bottom:20px;font-family:var(--mo);font-size:13px;color:var(--rd);}<?php endif; ?>
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}
.stat{background:var(--s);border:1px solid var(--b);border-radius:10px;padding:16px 18px;box-shadow:var(--sh)}
.slb{font-family:var(--mo);font-size:10px;color:var(--dm);letter-spacing:2px;text-transform:uppercase;margin-bottom:7px}
.svl{font-family:var(--mo);font-size:24px;font-weight:600;line-height:1}.svl.g{color:var(--gn)}.svl.a{color:var(--ac);font-size:14px;margin-top:4px}
.panel{background:var(--s);border:1px solid var(--b);border-radius:10px;box-shadow:var(--sh);overflow:hidden}
.fbar{padding:14px 20px;border-bottom:1px solid var(--b);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
.ff{display:flex;gap:8px;flex-wrap:wrap}
.fi{height:33px;padding:0 11px;border:1.5px solid var(--b);border-radius:6px;font-family:var(--mo);font-size:12px;background:var(--bg);outline:none;color:var(--tx)}
.fi:focus{border-color:var(--ac)}
.btn{height:33px;padding:0 14px;border:1.5px solid var(--b);border-radius:6px;font-family:var(--mo);font-size:11px;font-weight:600;letter-spacing:1px;cursor:pointer;background:transparent;color:var(--dm);transition:all .2s;text-decoration:none;display:inline-flex;align-items:center}
.btn:hover{border-color:var(--tx);color:var(--tx)}.btn.prim{background:var(--ac);border-color:var(--ac);color:#fff}.btn.prim:hover{background:#b83d00}
table{width:100%;border-collapse:collapse}
thead tr{background:var(--bg);border-bottom:1px solid var(--b)}
th{padding:11px 16px;text-align:left;font-family:var(--mo);font-size:10px;letter-spacing:2px;color:var(--dm);text-transform:uppercase;white-space:nowrap}
tbody tr{border-bottom:1px solid var(--b);transition:background .15s}
tbody tr:last-child{border-bottom:none}tbody tr:hover{background:var(--bg)}
td{padding:12px 16px;font-size:13px}
.mid{font-family:var(--mo);font-size:12px;color:var(--dm)}.fw{font-weight:500}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-family:var(--mo);font-size:11px;font-weight:600}
.bf{background:var(--as);color:var(--ac)}.bc{background:#eef3ff;color:#3d5af1}.bp{background:#f7f0ff;color:#7c3aed}
.bg{background:var(--gs);color:var(--gn)}.brd{background:var(--rs);color:var(--rd)}
.empty{padding:50px;text-align:center;color:var(--dm);font-family:var(--mo);font-size:13px;line-height:1.8}
.pgr{padding:14px 20px;border-top:1px solid var(--b);font-family:var(--mo);font-size:12px;color:var(--dm);display:flex;justify-content:space-between;align-items:center}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}
@media(max-width:700px){.stats{grid-template-columns:1fr 1fr}.fbar{flex-direction:column;align-items:flex-start}}
</style>
</head>
<body>

<div class="bar">
  <div class="brd">
    <div class="bic">☞</div>
    <div class="bnm">DS-K1T804BMF <span>Live Attendance</span></div>
  </div>
  <div class="live"><div class="dot"></div> LIVE · Auto-refresh 30s</div>
</div>

<div class="wrap">

<?php if(!empty($error)): ?>
<div class="err-banner">
  ✖ Database Error: <?= htmlspecialchars($error) ?><br>
  <small>Update DB_HOST, DB_NAME, DB_USER, DB_PASS at top of this file.</small>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="stats">
  <div class="stat"><div class="slb">Total Records</div><div class="svl"><?= number_format($total) ?></div></div>
  <div class="stat"><div class="slb">Today's Scans</div><div class="svl g"><?= number_format($today) ?></div></div>
  <div class="stat"><div class="slb">Unique Users</div><div class="svl"><?= number_format($users) ?></div></div>
  <div class="stat"><div class="slb">Last Scan</div><div class="svl a"><?= $last ? date('H:i:s', strtotime($last)) : '—' ?></div></div>
</div>

<!-- Table -->
<div class="panel">
  <form method="GET" class="fbar">
    <div class="ff">
      <input class="fi" name="s"  placeholder="Search name / ID…" value="<?= htmlspecialchars($search) ?>" style="width:180px">
      <select class="fi" name="m">
        <option value="">All Methods</option>
        <option <?= $method==='Fingerprint'?'selected':'' ?>>Fingerprint</option>
        <option <?= $method==='Card'?'selected':'' ?>>Card</option>
        <option <?= $method==='PIN'?'selected':'' ?>>PIN</option>
      </select>
      <select class="fi" name="st">
        <option value="">All Status</option>
        <option <?= $status==='Granted'?'selected':'' ?>>Granted</option>
        <option <?= $status==='Denied'?'selected':'' ?>>Denied</option>
      </select>
      <input class="fi" type="date" name="d" value="<?= htmlspecialchars($date) ?>">
      <button class="btn prim" type="submit">FILTER</button>
      <a class="btn" href="?">RESET</a>
    </div>
    <div style="display:flex;gap:8px">
      <a class="btn" href="?<?= http_build_query(array_merge($_GET,['export'=>'csv'])) ?>">↓ CSV</a>
      <button class="btn" onclick="location.reload()">↻ REFRESH</button>
    </div>
  </form>

  <?php
  // CSV export
  if (isset($_GET['export']) && $_GET['export']==='csv') {
      header('Content-Type: text/csv');
      header('Content-Disposition: attachment; filename="attendance_'.date('Y-m-d').'.csv"');
      echo "ID,Employee No,Name,Event Time,Method,Door,Status\n";
      foreach ($logs as $l)
          printf("%s,%s,%s,%s,%s,%s,%s\n",$l['id'],$l['employee_no'],
              '"'.str_replace('"','""',$l['name']).'"',
              $l['event_time'],$l['method'],$l['door_no'],$l['status']);
      exit();
  }
  ?>

  <table>
    <thead>
      <tr>
        <th>#</th><th>USER ID</th><th>NAME</th>
        <th>TIME</th><th>DATE</th><th>METHOD</th><th>DOOR</th><th>STATUS</th>
      </tr>
    </thead>
    <tbody>
    <?php if(empty($logs)): ?>
      <tr><td colspan="8"><div class="empty">
        <?php if(!empty($error)): ?>
          ⚠ Fix database connection above first.
        <?php else: ?>
          ☟ No records yet.<br>Configure device to push events to this server,<br>then scan a fingerprint — it will appear here automatically.
        <?php endif; ?>
      </div></td></tr>
    <?php else: ?>
      <?php foreach($logs as $i => $l): ?>
      <tr>
        <td class="mid"><?= $l['id'] ?></td>
        <td class="mid"><?= htmlspecialchars($l['employee_no']) ?></td>
        <td class="fw"><?= htmlspecialchars($l['name']) ?></td>
        <td class="mid"><?= date('H:i:s', strtotime($l['event_time'])) ?></td>
        <td class="mid"><?= date('d/m/Y', strtotime($l['event_time'])) ?></td>
        <td><?php
          $m=$l['method'];
          $cls=$m==='Fingerprint'?'bf':($m==='Card'?'bc':'bp');
          $ic =$m==='Fingerprint'?'✦':($m==='Card'?'▣':'⌨');
          echo "<span class='badge $cls'>$ic $m</span>";
        ?></td>
        <td class="mid"><?= htmlspecialchars($l['door_no']) ?></td>
        <td><?php
          $st=$l['status'];
          echo "<span class='badge ".($st==='Granted'?'bg':'brd')."'>".($st==='Granted'?'✔':'✖')." $st</span>";
        ?></td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>

  <div class="pgr">
    <span>Showing <?= count($logs) ?> of <?= number_format($total) ?> total records</span>
    <span style="color:var(--ac)">Auto-refreshes every 30 seconds</span>
  </div>
</div>

</div>

<script>
// Auto-refresh every 30 seconds
setTimeout(() => location.reload(), 30000);
</script>
</body>
</html>
