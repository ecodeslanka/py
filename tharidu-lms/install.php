<?php
// One-time installer. Creates tables + default admin. DELETE THIS FILE after running.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$done = false; $err = '';
if (is_post()) {
    try {
        $sql = file_get_contents(ROOT . '/sql/database.sql');
        // Ignore "already exists" for the ALTER on re-run
        foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
            try { db()->exec($stmt); } catch (PDOException $e) { if (stripos($stmt, 'ALTER TABLE') !== 0) throw $e; }
        }
        require __DIR__ . '/inc/migrate.php'; run_migrations();   // upgrades older installs, sets schema version
        if (!val("SELECT COUNT(*) FROM users WHERE role='admin'")) {
            q("INSERT INTO users(role,name,mobile,password_hash) VALUES('admin','Administrator','700000000',?)", [password_hash('Admin@123', PASSWORD_DEFAULT)]);
        }
        if (!val('SELECT COUNT(*) FROM classes')) foreach ([['Senara – Kegalle', 1], ['Sithumina – Kurunegala', 2]] as [$n, $o]) q('INSERT INTO classes(name,sort_order) VALUES(?,?)', [$n, $o]);
        if (!val('SELECT COUNT(*) FROM grades')) foreach (['A/L Theory', 'A/L Paper Class', 'A/L Revision'] as $g) q('INSERT INTO grades(name) VALUES(?)', [$g]);
        if (!val('SELECT COUNT(*) FROM batches')) foreach ([['2027 A/L', 1], ['2028 A/L', 2]] as [$n, $o]) q('INSERT INTO batches(name,sort_order) VALUES(?,?)', [$n, $o]);
        $defaults = ['site_name' => SITE_NAME, 'whatsapp' => '94703957020', 'phone' => '0703957020',
            'about_title' => 'Learn chemistry the genuine way',
            'about_text' => "Tharidu K. Wijekoon is a science graduate in Chemistry and Physics who teaches G.C.E. A/L Chemistry.\n\nTheory classes, paper classes and online revision — in Kegalle, Kurunegala and from your home."];
        foreach ($defaults as $k => $v) if (setting($k) === '') set_setting($k, $v);
        $done = true;
    } catch (Throwable $e) { $err = $e->getMessage(); }
}
head_html('Install'); ?>
<main class="band"><div class="authwrap card">
<h2>Install</h2><br>
<?php if ($done): ?>
  <div class="alert ok">Installed ✔. Default admin → mobile <b>0700000000</b> / password <b>Admin@123</b>. Log in and change it immediately.</div>
  <p class="alert err">Now DELETE <b>install.php</b> from the server.</p>
  <a class="btn btn-r" href="<?= url('login') ?>">Go to login</a>
<?php else: ?>
  <?php if ($err) echo '<div class="alert err">' . e($err) . '</div>'; ?>
  <p class="muted">Creates all database tables and the default admin user. Edit <code>inc/config.php</code> first with your MySQL details.</p>
  <form method="post"><?= csrf_field() ?><button class="btn btn-r">Run installer</button></form>
<?php endif; ?>
</div></main><?php foot_html();
