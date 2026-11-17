<?php
/* YMS Bot — common (company-wide) settings in the database: table system_settings (setting_key, setting_value). See ymsbot_settings.php. */
if (!function_exists('ymsbot_settings_table')) {
    function ymsbot_settings_table($conn) {
        static $done = false; if ($done || !$conn) return; $done = true;
        @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS system_settings (setting_key VARCHAR(80) NOT NULL PRIMARY KEY, setting_value VARCHAR(255) NULL,
                              updated_by VARCHAR(100) NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        /* the first version kept "off for everyone" as a user preference of user 0 — carry it over once */
        $r = @mysqli_query($conn, "SELECT pref_value FROM user_ui_prefs WHERE user_id = 0 AND pref_key = 'ymsbot_global'");
        if ($r && ($x = mysqli_fetch_row($r))) { @mysqli_query($conn, "INSERT IGNORE INTO system_settings (setting_key, setting_value, updated_by) VALUES ('ymsbot_enabled', '" . ($x[0] === 'off' ? '0' : '1') . "', 'migrated')");
            @mysqli_query($conn, "DELETE FROM user_ui_prefs WHERE user_id = 0 AND pref_key = 'ymsbot_global'"); }
    }
    function ymsbot_settings($conn) {
        $S = ['ymsbot_enabled' => '1', 'ymsbot_user_hide' => '1', 'ymsbot_default' => 'full', 'ymsbot_ai' => '1', 'ymsbot_hello' => '1', '_at' => '', '_by' => ''];
        if (!$conn) return $S;
        $r = @mysqli_query($conn, "SELECT setting_key, setting_value, updated_by, updated_at FROM system_settings WHERE setting_key LIKE 'ymsbot\\_%'");
        while ($r && ($x = mysqli_fetch_assoc($r))) { $S[$x['setting_key']] = (string)$x['setting_value']; if ($x['updated_at'] > $S['_at']) { $S['_at'] = $x['updated_at']; $S['_by'] = $x['updated_by']; } }
        return $S;
    }
    function ymsbot_set($conn, $k, $v, $by) {
        $e = fn($s) => mysqli_real_escape_string($conn, (string)$s);
        @mysqli_query($conn, "INSERT INTO system_settings (setting_key, setting_value, updated_by) VALUES ('{$e($k)}', '{$e($v)}', '{$e($by)}') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)");
    }
}
