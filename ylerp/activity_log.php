<?php
include 'config.php';
include 'auth.php';
requireLogin();
require_once __DIR__ . '/activity_lib.php';
al_ensure();

/* ---------- filters ---------- */
$tab    = in_array($_GET['tab'] ?? '', ['activity', 'pages', 'users', 'security'], true) ? $_GET['tab'] : 'activity';
$from   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('-6 days'));
$to     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '')   ? $_GET['to']   : date('Y-m-d');
$f_user = intval($_GET['u'] ?? 0);
$f_page = trim($_GET['p'] ?? '');
$f_act  = trim($_GET['a'] ?? '');
$f_q    = trim($_GET['q'] ?? '');
$e = fn($v) => mysqli_real_escape_string($conn, (string)$v);
$SEC = ['login', 'logout', 'login_failed', 'login_blocked', 'user_locked', 'user_unlocked', 'user_auto_locked', 'blocked',
        'password_reset_sent', 'password_reset_failed', 'password_reset_done', 'email_settings_saved'];
$secIn = "'" . implode("','", $SEC) . "'";

$w = ["created_at >= '{$e($from)} 00:00:00'", "created_at <= '{$e($to)} 23:59:59'"];
if ($f_user) $w[] = "user_id = $f_user";
if ($f_page !== '') $w[] = "page = '{$e($f_page)}'";
if ($f_act !== '')  $w[] = "action = '{$e($f_act)}'";
if ($tab === 'security') $w[] = "action IN ($secIn)";
if ($f_q !== '') { $q = $e($f_q); $w[] = "(username LIKE '%$q%' OR detail LIKE '%$q%' OR page LIKE '%$q%' OR ip LIKE '%$q%')"; }
$where = implode(' AND ', $w);

/* ---------- Excel export of the current tab ---------- */
if (!empty($_GET['export']) && class_exists('ZipArchive')) {
    require_once __DIR__ . '/rbac_xlsx.php';
    $X = new MiniXlsx(); $sh = $X->sheet('Activity log');
    $H = ['b' => 1, 'color' => 'FFFFFF', 'bg' => '1E293B', 'h' => 'center', 'border' => 1];
    $sh->set(1, 1, 'Activity Log  ·  ' . $from . ' to ' . $to, ['b' => 1, 'sz' => 14]);
    foreach (['Time', 'User', 'Role', 'Page', 'Action', 'What happened', 'Detail', 'IP', 'Device'] as $i => $h) $sh->set(3, $i + 1, $h, $H);
    $r = 4; $res = mysqli_query($conn, "SELECT * FROM activity_log WHERE $where ORDER BY id DESC LIMIT 20000");
    while ($res && ($x = mysqli_fetch_assoc($res))) { foreach ([$x['created_at'], $x['username'], $x['role_name'], al_page_label($x['page']), $x['action'], al_describe($x), $x['detail'], $x['ip'], $x['device']] as $i => $v) $sh->set($r, $i + 1, (string)$v, ['border' => 1]); $r++; }
    foreach ([19, 16, 16, 26, 16, 60, 44, 15, 24] as $i => $wd) $sh->widths[$i + 1] = $wd;
    $tmp = tempnam(sys_get_temp_dir(), 'al'); $X->save($tmp);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="activity_log_' . date('Ymd_His') . '.xlsx"');
    header('Content-Length: ' . filesize($tmp)); readfile($tmp); @unlink($tmp); exit;
}

/* ---------- data ---------- */
$per = 100; $pg = max(1, intval($_GET['pg'] ?? 1)); $off = ($pg - 1) * $per;
$total = (int)(mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM activity_log WHERE $where"))[0] ?? 0);
$rows = []; $pagesum = []; $usersum = [];
if ($tab === 'activity' || $tab === 'security') {
    $r = mysqli_query($conn, "SELECT * FROM activity_log WHERE $where ORDER BY id DESC LIMIT $per OFFSET $off");
    while ($r && ($x = mysqli_fetch_assoc($r))) $rows[] = $x;
} elseif ($tab === 'pages') {
    $r = mysqli_query($conn, "SELECT page, COUNT(*) n, COUNT(DISTINCT user_id) users, SUM(action='view') v, SUM(action IN ('create','save')) c, SUM(action='edit') ed, SUM(action='delete') d,
                              SUM(action IN ('export','print')) ex, SUM(action='blocked') b, MAX(created_at) last_at FROM activity_log WHERE $where GROUP BY page ORDER BY n DESC LIMIT 300");
    while ($r && ($x = mysqli_fetch_assoc($r))) $pagesum[] = $x;
} else {
    $r = mysqli_query($conn, "SELECT user_id, MAX(username) username, MAX(role_name) role_name, COUNT(*) n, COUNT(DISTINCT page) pages, SUM(action='view') v, SUM(action IN ('create','save')) c, SUM(action='edit') ed,
                              SUM(action='delete') d, SUM(action IN ('export','print')) ex, SUM(action='blocked') b, SUM(action='login') logins, MAX(created_at) last_at
                              FROM activity_log WHERE $where AND user_id IS NOT NULL GROUP BY user_id ORDER BY n DESC LIMIT 300");
    while ($r && ($x = mysqli_fetch_assoc($r))) $usersum[] = $x;
}
$userList = []; $r = mysqli_query($conn, "SELECT id, username FROM users ORDER BY username"); while ($r && ($x = mysqli_fetch_assoc($r))) $userList[] = $x;
$pageList = []; $r = mysqli_query($conn, "SELECT DISTINCT page FROM activity_log WHERE created_at >= (NOW() - INTERVAL 90 DAY) ORDER BY page"); while ($r && ($x = mysqli_fetch_assoc($r))) $pageList[] = $x['page'];
$locked = []; $r = @mysqli_query($conn, "SELECT id, username, locked_until, lock_reason, failed_attempts FROM users WHERE locked_until IS NOT NULL AND locked_until > NOW() ORDER BY username");
while ($r && ($x = mysqli_fetch_assoc($r))) $locked[] = $x;
$kpi = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) n, COUNT(DISTINCT user_id) users, COUNT(DISTINCT page) pages, SUM(action='login_failed') failed, SUM(action='blocked') blocked, SUM(action='delete') del
                                              FROM activity_log WHERE created_at >= '{$e($from)} 00:00:00' AND created_at <= '{$e($to)} 23:59:59'")) ?: [];
$ACT = ['view' => ['View', '#2563eb', '#dbeafe'], 'create' => ['Add', '#16a34a', '#dcfce7'], 'save' => ['Save', '#16a34a', '#dcfce7'], 'edit' => ['Edit', '#d97706', '#fef3c7'], 'delete' => ['Delete', '#dc2626', '#fee2e2'],
        'export' => ['Export', '#7c3aed', '#ede9fe'], 'print' => ['Print', '#7c3aed', '#ede9fe'], 'blocked' => ['Blocked', '#991b1b', '#fecaca'], 'login' => ['Login', '#0f766e', '#ccfbf1'],
        'logout' => ['Logout', '#475569', '#e2e8f0'], 'login_failed' => ['Login failed', '#b91c1c', '#fee2e2'], 'login_blocked' => ['Login blocked', '#b91c1c', '#fee2e2'],
        'user_locked' => ['User locked', '#92400e', '#fde68a'], 'user_auto_locked' => ['Auto locked', '#92400e', '#fde68a'], 'user_unlocked' => ['User unlocked', '#166534', '#bbf7d0']];
$badge = function ($a) use ($ACT) { [$l, $c, $b] = $ACT[$a] ?? [ucwords(str_replace('_', ' ', $a)), '#374151', '#e5e7eb']; return '<span class="b" style="color:' . $c . ';background:' . $b . '">' . htmlspecialchars($l) . '</span>'; };
$qs = fn($ov = []) => http_build_query(array_merge(['tab' => $tab, 'from' => $from, 'to' => $to, 'u' => $f_user ?: '', 'p' => $f_page, 'a' => $f_act, 'q' => $f_q], $ov));
$pages = max(1, (int)ceil($total / $per));
include 'header.php';
?>
<style>
.al{max-width:1500px;margin:0 auto;padding:16px;font-family:'Inter',system-ui,sans-serif}.al h2{margin:0 0 3px;font-size:22px}.al .sub{color:#6b7280;font-size:13px;margin:0 0 14px}
.al .kp{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:14px}.al .k{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:10px 14px}.al .k b{display:block;font-size:22px}.al .k span{font-size:11.5px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em}
.al .tabs{display:flex;gap:4px;background:#f3f4f6;border-radius:10px;padding:4px;margin-bottom:12px;flex-wrap:wrap}.al .tabs a{padding:8px 16px;border-radius:7px;text-decoration:none;color:#4b5563;font-weight:600;font-size:13.5px}.al .tabs a.on{background:#fff;color:#111827;box-shadow:0 1px 3px rgba(0,0,0,.12)}
.al .fl{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:12px;margin-bottom:12px}.al .fl div{display:flex;flex-direction:column;gap:3px}.al .fl label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase}
.al .fl input,.al .fl select{padding:7px 9px;border:1.5px solid #d1d5db;border-radius:7px;font:inherit;font-size:13px;min-width:120px}.al .bt{padding:8px 16px;border:none;border-radius:7px;background:#4f46e5;color:#fff;font-weight:600;cursor:pointer;text-decoration:none;font-size:13px}.al .bt.o{background:#fff;color:#4f46e5;border:1.5px solid #c7d2fe}
.al .tw{background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:auto}.al table{width:100%;border-collapse:collapse;font-size:13px}.al th{background:#1e293b;color:#fff;padding:9px 10px;text-align:left;position:sticky;top:0;white-space:nowrap}.al td{padding:8px 10px;border-bottom:1px solid #f1f5f9;vertical-align:top}.al tr:hover td{background:#f8fafc}
.al .b{display:inline-block;padding:2px 9px;border-radius:99px;font-size:11.5px;font-weight:700;white-space:nowrap}.al .det{color:#6b7280;font-size:12px;max-width:520px;word-break:break-word}.al .num{text-align:right;font-variant-numeric:tabular-nums}
.al .lock{background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:10px 14px;margin-bottom:12px;font-size:13px}.al .lock a{margin-left:8px;color:#166534;font-weight:700;text-decoration:none;background:#dcfce7;padding:2px 9px;border-radius:99px;display:inline-block;margin-top:4px}
.al .pgn{display:flex;gap:6px;align-items:center;justify-content:center;margin:12px 0;font-size:13px}.al .pgn a{padding:5px 12px;border:1px solid #d1d5db;border-radius:6px;text-decoration:none;color:#374151;background:#fff}
</style>
<div class="al">
  <h2><i class="fa-solid fa-clock-rotate-left" style="color:#4f46e5"></i> Activity Log</h2>
  <p class="sub">Every page opened, saved, edited, deleted, exported or blocked — by user and by page — plus logins, lockouts and password resets.</p>

  <?php if ($locked): ?><div class="lock"><b><i class="fa-solid fa-lock"></i> <?php echo count($locked); ?> locked user(s):</b>
    <?php foreach ($locked as $l): $adm = al_is_admin_lock($l['locked_until']); ?>
      <div><?php echo htmlspecialchars($l['username']); ?> — <?php echo $adm ? 'locked by administrator' : 'auto-locked until ' . htmlspecialchars($l['locked_until']) . ' (' . (int)$l['failed_attempts'] . ' wrong passwords)'; ?>
      <a href="users.php?action=unlock_user&uid=<?php echo (int)$l['id']; ?>" onclick="return confirm('Unlock <?php echo htmlspecialchars($l['username'], ENT_QUOTES); ?>?')"><i class="fa-solid fa-lock-open"></i> Unlock</a></div>
    <?php endforeach; ?></div><?php endif; ?>

  <div class="kp">
    <div class="k"><b><?php echo number_format((int)($kpi['n'] ?? 0)); ?></b><span>Events</span></div>
    <div class="k"><b><?php echo (int)($kpi['users'] ?? 0); ?></b><span>Users</span></div>
    <div class="k"><b><?php echo (int)($kpi['pages'] ?? 0); ?></b><span>Pages used</span></div>
    <div class="k"><b style="color:#dc2626"><?php echo (int)($kpi['del'] ?? 0); ?></b><span>Deletes</span></div>
    <div class="k"><b style="color:#b91c1c"><?php echo (int)($kpi['failed'] ?? 0); ?></b><span>Failed logins</span></div>
    <div class="k"><b style="color:#991b1b"><?php echo (int)($kpi['blocked'] ?? 0); ?></b><span>Blocked</span></div>
  </div>

  <div class="tabs">
    <?php foreach (['activity' => 'All activity', 'pages' => 'By page', 'users' => 'By user', 'security' => 'Logins & locks'] as $k => $l): ?>
      <a href="?<?php echo $qs(['tab' => $k, 'pg' => 1]); ?>" class="<?php echo $tab === $k ? 'on' : ''; ?>"><?php echo $l; ?></a>
    <?php endforeach; ?>
  </div>

  <form class="fl" method="get"><input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
    <div><label>From</label><input type="date" name="from" value="<?php echo $from; ?>"></div>
    <div><label>To</label><input type="date" name="to" value="<?php echo $to; ?>"></div>
    <div><label>User</label><select name="u"><option value="">All users</option><?php foreach ($userList as $u): ?><option value="<?php echo $u['id']; ?>" <?php echo $f_user == $u['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['username']); ?></option><?php endforeach; ?></select></div>
    <div><label>Page</label><select name="p"><option value="">All pages</option><?php foreach ($pageList as $p): ?><option <?php echo $f_page === $p ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option><?php endforeach; ?></select></div>
    <div><label>Action</label><select name="a"><option value="">All actions</option><?php foreach ($ACT as $k => $v): ?><option value="<?php echo $k; ?>" <?php echo $f_act === $k ? 'selected' : ''; ?>><?php echo $v[0]; ?></option><?php endforeach; ?></select></div>
    <div><label>Search</label><input type="text" name="q" value="<?php echo htmlspecialchars($f_q); ?>" placeholder="user, IP, detail…"></div>
    <button class="bt"><i class="fa-solid fa-magnifying-glass"></i> Show</button>
    <a class="bt o" href="?<?php echo $qs(['export' => 1]); ?>"><i class="fa-solid fa-file-excel"></i> Export Excel</a>
  </form>

  <div class="tw">
  <?php if ($tab === 'pages'): ?>
    <table><thead><tr><th>Page</th><th class="num">Events</th><th class="num">Users</th><th class="num">Views</th><th class="num">Add/Save</th><th class="num">Edit</th><th class="num">Delete</th><th class="num">Export/Print</th><th class="num">Blocked</th><th>Last used</th></tr></thead><tbody>
    <?php foreach ($pagesum as $x): ?><tr><td><a href="?<?php echo $qs(['tab' => 'activity', 'p' => $x['page'], 'pg' => 1]); ?>"><b><?php echo htmlspecialchars($x['page']); ?></b></a></td>
      <td class="num"><b><?php echo $x['n']; ?></b></td><td class="num"><?php echo $x['users']; ?></td><td class="num"><?php echo $x['v']; ?></td><td class="num"><?php echo $x['c']; ?></td><td class="num"><?php echo $x['ed']; ?></td>
      <td class="num" style="color:#dc2626"><?php echo $x['d']; ?></td><td class="num"><?php echo $x['ex']; ?></td><td class="num" style="color:#991b1b"><?php echo $x['b']; ?></td><td><?php echo $x['last_at']; ?></td></tr>
    <?php endforeach; if (!$pagesum) echo '<tr><td colspan="10" style="text-align:center;color:#9ca3af;padding:30px">No activity in this period.</td></tr>'; ?></tbody></table>
  <?php elseif ($tab === 'users'): ?>
    <table><thead><tr><th>User</th><th>Role</th><th class="num">Events</th><th class="num">Pages</th><th class="num">Logins</th><th class="num">Views</th><th class="num">Add/Save</th><th class="num">Edit</th><th class="num">Delete</th><th class="num">Export/Print</th><th class="num">Blocked</th><th>Last active</th></tr></thead><tbody>
    <?php foreach ($usersum as $x): ?><tr><td><a href="?<?php echo $qs(['tab' => 'activity', 'u' => $x['user_id'], 'pg' => 1]); ?>"><b><?php echo htmlspecialchars($x['username']); ?></b></a></td><td><?php echo htmlspecialchars($x['role_name']); ?></td>
      <td class="num"><b><?php echo $x['n']; ?></b></td><td class="num"><?php echo $x['pages']; ?></td><td class="num"><?php echo $x['logins']; ?></td><td class="num"><?php echo $x['v']; ?></td><td class="num"><?php echo $x['c']; ?></td><td class="num"><?php echo $x['ed']; ?></td>
      <td class="num" style="color:#dc2626"><?php echo $x['d']; ?></td><td class="num"><?php echo $x['ex']; ?></td><td class="num" style="color:#991b1b"><?php echo $x['b']; ?></td><td><?php echo $x['last_at']; ?></td></tr>
    <?php endforeach; if (!$usersum) echo '<tr><td colspan="12" style="text-align:center;color:#9ca3af;padding:30px">No activity in this period.</td></tr>'; ?></tbody></table>
  <?php else: ?>
    <table><thead><tr><th>Time</th><th>User</th><th>Page</th><th>Action</th><th>What happened</th><th>IP</th><th>Device</th></tr></thead><tbody>
    <?php foreach ($rows as $x): ?><tr><td style="white-space:nowrap"><?php echo $x['created_at']; ?></td><td><b><?php echo htmlspecialchars($x['username'] ?: '—'); ?></b><br><small style="color:#9ca3af"><?php echo htmlspecialchars($x['role_name']); ?></small></td>
      <td><?php echo htmlspecialchars(al_page_label($x['page'])); ?><br><small style="color:#9ca3af"><?php echo htmlspecialchars($x['page']); ?>.php</small></td><td><?php echo $badge($x['action']); ?></td><td style="max-width:560px;white-space:normal"><b style="color:#111827"><?php echo htmlspecialchars(al_describe($x)); ?></b><?php if ($x['detail'] !== '' && $x['detail'] !== null): ?><br><span class="det" title="<?php echo htmlspecialchars($x['detail']); ?>"><?php echo htmlspecialchars(mb_strimwidth($x['detail'], 0, 110, '…')); ?></span><?php endif; ?></td><td><?php echo htmlspecialchars($x['ip']); ?></td><td><small><?php echo htmlspecialchars($x['device']); ?></small></td></tr>
    <?php endforeach; if (!$rows) echo '<tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:30px">No activity found for these filters.</td></tr>'; ?></tbody></table>
  <?php endif; ?>
  </div>
  <?php if (($tab === 'activity' || $tab === 'security') && $pages > 1): ?>
    <div class="pgn"><?php if ($pg > 1): ?><a href="?<?php echo $qs(['pg' => $pg - 1]); ?>">‹ Prev</a><?php endif; ?> Page <?php echo $pg; ?> of <?php echo $pages; ?> (<?php echo number_format($total); ?> events)
    <?php if ($pg < $pages): ?><a href="?<?php echo $qs(['pg' => $pg + 1]); ?>">Next ›</a><?php endif; ?></div>
  <?php endif; ?>
</div>
<?php include 'footer.php'; ?>
