<?php
/* YMS version: set YMS_VERSION (e.g. in config.php) to fix it by hand; otherwise it is the date of the newest .php file
   on the server, so it moves forward by itself every time an update is uploaded (e.g. v2026.11.17). */
if (!function_exists('yms_version')) {
    function yms_version() {
        if (defined('YMS_VERSION') && YMS_VERSION !== '') return (string)YMS_VERSION;
        $newest = 0;
        foreach ((array)glob(__DIR__ . '/*.php') as $f) { $t = @filemtime($f); if ($t > $newest) $newest = $t; }
        return $newest ? date('Y.m.d', $newest) : date('Y.m.d');
    }
}
?>
<footer class="yms-footer" style="margin:28px 0 8px;padding:14px 10px 4px;border-top:1px solid #e5e7eb;text-align:center;font:500 12.5px/1.5 'Inter',system-ui,sans-serif;color:#64748b">
  <b style="color:#1e3a8a">YMS</b> v<?= htmlspecialchars(yms_version()) ?>
  <span style="margin:0 8px;color:#cbd5e1">|</span>
  Copyright &copy; <?= date('Y') ?> by <b style="color:#334155">YELO GROUP OF COMPANIES</b>. All rights reserved.
</footer>
</div>
</body>
</html>
