<?php
/**
 * rbac_repair.php — check & clean the role / permission tables
 *   • shows every problem it finds (missing tables / columns, rows for pages that no longer exist,
 *     rows of deleted roles) with the exact database error when something fails
 *   • "Fix tables" creates / repairs the tables and columns
 *   • "Remove stale rows" deletes permission rows whose page is no longer in the menu (after you confirm)
 *   • "Clear cache" rebuilds the page / button lists
 * Needs the Edit right on User Role (or Full Access).
 */
include_once 'config.php';
include_once 'auth.php';
require_once __DIR__ . '/rbac.php';
requireLogin();
if (!rbac_context()['full'] && !canEdit('role')) rbac_deny('edit', 'role');

$msgs = []; $errs = [];
function rr_q($sql) {            // run a query, return [result|false, error]
    global $conn;
    try { $r = mysqli_query($conn, $sql); return [$r, $r ? '' : mysqli_error($conn)]; }
    catch (Throwable $e) { return [false, $e->getMessage()]; }
}
function rr_rows($sql, &$err) { [$r, $err] = rr_q($sql); $o = []; while ($r && ($x = mysqli_fetch_assoc($r))) $o[] = $x; return $o; }

/* ── what is valid ── */
// Permission rows are valid ONLY for pages in the sidebar menu / sub menu (header.php).
$valid = array_flip(array_map('strtolower', array_keys(rbac_menu_pages())));
$all_buttons = function_exists('rbac_all_buttons') ? 'rbac_all_buttons' : fn() => [];

/* ── actions ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rbac_csrf_check()) { $errs[] = 'Session expired — try again.'; }
    else {
        $act = $_POST['do'] ?? '';
        if ($act === 'fix') {
            rbac_ensure_schema();
            // make sure the button table exists even when the foreign key could not be created
            [$ok, $e] = rr_q("CREATE TABLE IF NOT EXISTS role_page_buttons (
                id INT(11) AUTO_INCREMENT PRIMARY KEY, role_id INT(11) NOT NULL, page VARCHAR(150) NOT NULL,
                btn_key VARCHAR(80) NOT NULL, allowed TINYINT(1) NOT NULL DEFAULT 0, UNIQUE KEY uq_role_page_btn (role_id, page, btn_key))");
            $ok ? $msgs[] = 'Tables checked / created.' : $errs[] = 'role_page_buttons: ' . $e;
            rbac_cache_clear();
        } elseif ($act === 'clean') {
            $n = 0;
            [$ok, $e] = rr_q("DELETE FROM permissions WHERE can_access = 0 AND can_create = 0 AND can_edit = 0 AND can_delete = 0");
            $ok ? $n += mysqli_affected_rows($conn) : $errs[] = $e;
            $stale = rr_rows("SELECT DISTINCT module_name FROM permissions", $e);
            if ($e) $errs[] = 'Could not read permissions: ' . $e;
            $drop = [];
            foreach ($stale as $r) if (!isset($valid[strtolower($r['module_name'])])) $drop[] = "'" . mysqli_real_escape_string($conn, $r['module_name']) . "'";
            if ($drop) {
                [$ok, $e] = rr_q("DELETE FROM permissions WHERE module_name IN (" . implode(',', $drop) . ")");
                if ($ok) { $n += mysqli_affected_rows($conn); } else $errs[] = 'Delete failed: ' . $e;
            }
            [$ok, $e] = rr_q("DELETE FROM permissions WHERE role_id NOT IN (SELECT id FROM roles)");  $ok ? $n += mysqli_affected_rows($conn) : $errs[] = $e;
            [$ok, $e] = rr_q("DELETE FROM role_page_buttons WHERE role_id NOT IN (SELECT id FROM roles)"); $ok ? $n += mysqli_affected_rows($conn) : $errs[] = $e;
            $btnpages = $all_buttons();
            foreach (rr_rows("SELECT DISTINCT page, btn_key FROM role_page_buttons", $e) as $b) {
                if (!isset($btnpages[strtolower($b['page'])][$b['btn_key']])) {
                    [$ok, $e2] = rr_q("DELETE FROM role_page_buttons WHERE page='" . mysqli_real_escape_string($conn, $b['page']) . "' AND btn_key='" . mysqli_real_escape_string($conn, $b['btn_key']) . "'");
                    if ($ok) $n += mysqli_affected_rows($conn); else $errs[] = $e2;
                }
            }
            rbac_audit(null, '', 'cleanup', "Reset permissions to sidebar menu: removed $n stale / empty rows");
            $msgs[] = "Removed $n stale row(s).";
            rbac_cache_clear();
        } elseif ($act === 'cache') {
            rbac_cache_clear(); $msgs[] = 'Cache cleared — page and button lists rebuilt.';
        }
    }
}

/* ── diagnostics ── */
$checks = [];
foreach (['roles', 'permissions', 'users', 'role_audit_log', 'role_page_buttons'] as $t) {
    [$r, $e] = rr_q("SELECT COUNT(*) c FROM `$t`");
    $checks[] = [$t, $r ? true : false, $r ? number_format((int)mysqli_fetch_assoc($r)['c']) . ' rows' : $e];
}
foreach ([['roles', 'full_access'], ['roles', 'created_by'], ['permissions', 'can_create'], ['permissions', 'can_delete']] as [$t, $c]) {
    $ok = rbac_column_exists($t, $c);
    $checks[] = ["$t.$c", $ok, $ok ? 'present' : 'missing — press "Fix tables"'];
}
$stale = []; $err1 = '';
foreach (rr_rows("SELECT module_name, COUNT(*) roles_n FROM permissions GROUP BY module_name ORDER BY module_name", $err1) as $r)
    if (!isset($valid[strtolower($r['module_name'])])) $stale[] = $r;
$orph = rr_rows("SELECT COUNT(*) c FROM permissions WHERE role_id NOT IN (SELECT id FROM roles)", $e); $orph = (int)($orph[0]['c'] ?? 0);
$norole = rr_rows("SELECT COUNT(*) c FROM users WHERE role_id IS NULL OR role_id NOT IN (SELECT id FROM roles)", $e); $norole = (int)($norole[0]['c'] ?? 0);
$pages_n = count(rbac_menu_pages()); $btn_n = 0; foreach ($all_buttons() as $b) $btn_n += count($b);
$csrf = rbac_csrf_token();
include 'header.php';
?>
<div style="max-width:980px;margin:18px auto;padding:0 14px;font-family:Inter,system-ui,sans-serif">
  <h2 style="margin:0 0 4px"><i class="fa-solid fa-screwdriver-wrench"></i> Role &amp; Permission Repair</h2>
  <p style="color:#6b7280;margin:0 0 14px;font-size:13px">Checks the permission tables against the pages in the system (<?= $pages_n ?> pages, <?= $btn_n ?> tagged buttons) and cleans what is left over.</p>
  <?php foreach ($msgs as $m): ?><div style="background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46;padding:9px 12px;border-radius:8px;margin-bottom:10px"><?= htmlspecialchars($m) ?></div><?php endforeach; ?>
  <?php foreach ($errs as $m): ?><div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:9px 12px;border-radius:8px;margin-bottom:10px"><b>Error:</b> <?= htmlspecialchars($m) ?></div><?php endforeach; ?>

  <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;margin-bottom:14px">
    <h3 style="margin:0 0 8px;font-size:15px">1. Tables &amp; columns</h3>
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <?php foreach ($checks as [$n, $ok, $d]): ?>
        <tr style="border-top:1px solid #f1f5f9"><td style="padding:5px 0;width:34px"><?= $ok ? '✅' : '❌' ?></td><td><code><?= htmlspecialchars($n) ?></code></td>
            <td style="color:<?= $ok ? '#64748b' : '#b91c1c' ?>"><?= htmlspecialchars($d) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <form method="post" style="margin-top:10px"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="do" value="fix">
      <button style="padding:8px 14px;border-radius:7px;border:1px solid #2563eb;background:#2563eb;color:#fff;font-weight:600;cursor:pointer">Fix tables</button></form>
  </div>

  <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;margin-bottom:14px">
    <h3 style="margin:0 0 8px;font-size:15px">2. Stale rows</h3>
    <?php if ($err1): ?><div style="color:#b91c1c;font-size:13px">Could not read permissions: <?= htmlspecialchars($err1) ?></div><?php endif; ?>
    <p style="font-size:13px;margin:0 0 6px">Permission rows for pages that are <b>not in the sidebar menu / sub menu</b>: <b><?= count($stale) ?></b> page name(s)
      · rows of deleted roles: <b><?= $orph ?></b> · users without a valid role: <b><?= $norole ?></b> (they get <?= RBAC_NO_ROLE_FULL_ACCESS ? 'full access' : 'dashboard only' ?>).</p>
    <?php if ($stale): ?>
      <div style="max-height:220px;overflow:auto;border:1px solid #f1f5f9;border-radius:8px;padding:6px 10px;font-size:12.5px;margin-bottom:8px">
        <?php foreach ($stale as $r): ?><div><code><?= htmlspecialchars($r['module_name']) ?></code> <span style="color:#94a3b8">· <?= (int)$r['roles_n'] ?> role(s)</span></div><?php endforeach; ?>
      </div>
    <?php endif; ?>
    <form method="post" onsubmit="return confirm('Delete every permission row whose page is not in the sidebar menu (and empty rows)? Rights on menu / sub menu pages are kept.')"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="do" value="clean">
      <button style="padding:8px 14px;border-radius:7px;border:1px solid #dc2626;background:#dc2626;color:#fff;font-weight:600;cursor:pointer">Clear &amp; keep only sidebar-menu permissions</button></form>
  </div>

  <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px">
    <h3 style="margin:0 0 8px;font-size:15px">3. Cache</h3>
    <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="do" value="cache">
      <button style="padding:8px 14px;border-radius:7px;border:1px solid #d1d5db;background:#fff;font-weight:600;cursor:pointer">Clear cache</button></form>
  </div>
</div>
<?php include 'footer.php'; ?>
