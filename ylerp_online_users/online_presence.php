<?php
// online_presence.php – live "who is online" tracking (heartbeat based)
// Included from header.php (touch on every page load), logout.php (remove)
// and online_users_api.php (list).  Uses the DB clock (NOW()) everywhere so
// PHP / MySQL timezone differences never matter.

if (!defined('PRESENCE_ONLINE_SECS')) define('PRESENCE_ONLINE_SECS', 90);   // no heartbeat for 90s  => offline
if (!defined('PRESENCE_IDLE_SECS'))   define('PRESENCE_IDLE_SECS',   120);  // no real activity 2min => idle
if (!defined('PRESENCE_VIEW_ALL'))    define('PRESENCE_VIEW_ALL',    false); // false = only full-access (admin) roles see the panel

function presence_ensure_table($conn) {
    if (!empty($_SESSION['_presence_tbl'])) return;
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS user_presence (
        session_id   VARCHAR(128) NOT NULL PRIMARY KEY,
        user_id      INT NOT NULL,
        username     VARCHAR(100) NOT NULL,
        role_name    VARCHAR(100) DEFAULT NULL,
        ip_address   VARCHAR(45)  DEFAULT NULL,
        user_agent   VARCHAR(500) DEFAULT NULL,
        browser      VARCHAR(80)  DEFAULT NULL,
        os           VARCHAR(80)  DEFAULT NULL,
        device_type  VARCHAR(10)  DEFAULT 'desktop',
        current_page VARCHAR(150) DEFAULT NULL,
        login_time   DATETIME NOT NULL,
        last_seen    DATETIME NOT NULL,
        last_active  DATETIME NOT NULL,
        INDEX idx_last_seen (last_seen),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $_SESSION['_presence_tbl'] = 1;
}

function presence_ip() {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    // Trust proxy headers only when the direct peer is private/loopback (i.e. a local proxy).
    $public = filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    if (!$public) {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', $_SERVER[$k])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
    }
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
}

function presence_parse_ua($ua) {
    $device = 'desktop';
    if (preg_match('/iPad|Tablet|PlayBook|Silk/i', $ua) || (preg_match('/Android/i', $ua) && !preg_match('/Mobile/i', $ua))) $device = 'tablet';
    elseif (preg_match('/Mobile|Android|iPhone|iPod|BlackBerry|IEMobile|Opera Mini/i', $ua)) $device = 'mobile';

    $os = 'Unknown OS';
    if     (preg_match('/Windows NT 10/i', $ua))               $os = 'Windows 10/11';
    elseif (preg_match('/Windows NT 6\.3/i', $ua))             $os = 'Windows 8.1';
    elseif (preg_match('/Windows NT 6\.1/i', $ua))             $os = 'Windows 7';
    elseif (preg_match('/Windows/i', $ua))                     $os = 'Windows';
    elseif (preg_match('/Android ([\d\.]+)/i', $ua, $m))       $os = 'Android ' . $m[1];
    elseif (preg_match('/CPU iPhone OS ([\d_]+)/i', $ua, $m))  $os = 'iOS ' . str_replace('_', '.', $m[1]);
    elseif (preg_match('/iPad.*OS ([\d_]+)/i', $ua, $m))       $os = 'iPadOS ' . str_replace('_', '.', $m[1]);
    elseif (preg_match('/Mac OS X ([\d_]+)/i', $ua, $m))       $os = 'macOS ' . str_replace('_', '.', $m[1]);
    elseif (preg_match('/Linux/i', $ua))                       $os = 'Linux';

    $browser = 'Unknown Browser';
    if     (preg_match('/Edg\/([\d]+)/i', $ua, $m))     $browser = 'Edge '    . $m[1];
    elseif (preg_match('/OPR\/([\d]+)/i', $ua, $m))     $browser = 'Opera '   . $m[1];
    elseif (preg_match('/CriOS\/([\d]+)/i', $ua, $m))   $browser = 'Chrome '  . $m[1];
    elseif (preg_match('/FxiOS\/([\d]+)/i', $ua, $m))   $browser = 'Firefox ' . $m[1];
    elseif (preg_match('/Chrome\/([\d]+)/i', $ua, $m))  $browser = 'Chrome '  . $m[1];
    elseif (preg_match('/Firefox\/([\d]+)/i', $ua, $m)) $browser = 'Firefox ' . $m[1];
    elseif (preg_match('/Version\/([\d]+).*Safari/i', $ua, $m)) $browser = 'Safari ' . $m[1];
    elseif (preg_match('/Trident|MSIE/i', $ua))         $browser = 'Internet Explorer';

    return ['browser' => $browser, 'os' => $os, 'device' => $device];
}

/**
 * Record a heartbeat for the current session.
 * $active = true when the user really did something (page load / click / key).
 */
function presence_touch($conn, $active = true, $page = null) {
    if (empty($_SESSION['user_id']) || empty($_SESSION['username']) || !$conn) return;
    presence_ensure_table($conn);

    $sid   = mysqli_real_escape_string($conn, session_id());
    $uid   = intval($_SESSION['user_id']);
    $uname = mysqli_real_escape_string($conn, $_SESSION['username']);
    $role  = mysqli_real_escape_string($conn, $_SESSION['role_name'] ?? '');
    $ip    = mysqli_real_escape_string($conn, presence_ip());
    $ua    = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
    $p     = presence_parse_ua($ua);
    $ua    = mysqli_real_escape_string($conn, $ua);
    $br    = mysqli_real_escape_string($conn, $p['browser']);
    $os    = mysqli_real_escape_string($conn, $p['os']);
    $dev   = mysqli_real_escape_string($conn, $p['device']);
    $page  = $page ?: basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
    $pg    = mysqli_real_escape_string($conn, substr($page, 0, 150));

    $activeSql = $active ? "last_active = NOW()," : "";
    $pageSql   = $active ? "current_page = VALUES(current_page)," : "";

    mysqli_query($conn, "INSERT INTO user_presence
            (session_id, user_id, username, role_name, ip_address, user_agent, browser, os, device_type,
             current_page, login_time, last_seen, last_active)
        VALUES ('$sid', $uid, '$uname', '$role', '$ip', '$ua', '$br', '$os', '$dev', '$pg', NOW(), NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id), username = VALUES(username), role_name = VALUES(role_name),
            ip_address = VALUES(ip_address), user_agent = VALUES(user_agent), browser = VALUES(browser),
            os = VALUES(os), device_type = VALUES(device_type),
            $pageSql $activeSql last_seen = NOW()");

    // Housekeeping (≈1% of requests): drop rows nobody has touched for a day.
    if (mt_rand(1, 100) === 1) {
        mysqli_query($conn, "DELETE FROM user_presence WHERE last_seen < (NOW() - INTERVAL 1 DAY)");
    }
}

function presence_remove($conn, $session_id = null) {
    if (!$conn) return;
    presence_ensure_table($conn);
    $sid = mysqli_real_escape_string($conn, $session_id ?: session_id());
    mysqli_query($conn, "DELETE FROM user_presence WHERE session_id = '$sid'");
}

function presence_fmt_secs($s) {
    $s = max(0, intval($s));
    if ($s < 60)   return $s . 's';
    if ($s < 3600) return floor($s / 60) . 'm ' . ($s % 60) . 's';
    return floor($s / 3600) . 'h ' . floor(($s % 3600) / 60) . 'm';
}

/** Everyone with a heartbeat in the last PRESENCE_ONLINE_SECS, newest activity first. */
function presence_list($conn) {
    presence_ensure_table($conn);
    $on = intval(PRESENCE_ONLINE_SECS);
    $res = mysqli_query($conn, "SELECT session_id, user_id, username, role_name, ip_address, browser, os,
            device_type, current_page, login_time,
            TIMESTAMPDIFF(SECOND, login_time,  NOW()) AS session_secs,
            TIMESTAMPDIFF(SECOND, last_active, NOW()) AS idle_secs,
            TIMESTAMPDIFF(SECOND, last_seen,   NOW()) AS seen_secs
        FROM user_presence
        WHERE last_seen >= (NOW() - INTERVAL $on SECOND)
        ORDER BY last_active DESC");
    $out = [];
    $me  = session_id();
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $idle = intval($r['idle_secs']);
        $out[] = [
            'user_id'      => intval($r['user_id']),
            'username'     => $r['username'],
            'role'         => $r['role_name'] ?: 'User',
            'ip'           => $r['ip_address'],
            'browser'      => $r['browser'],
            'os'           => $r['os'],
            'device'       => $r['device_type'],
            'page'         => $r['current_page'],
            'login_time'   => $r['login_time'],
            'session_for'  => presence_fmt_secs($r['session_secs']),
            'idle_for'     => presence_fmt_secs($idle),
            'status'       => $idle <= PRESENCE_IDLE_SECS ? 'working' : 'idle',
            'is_me'        => $r['session_id'] === $me,
        ];
    }
    return $out;
}

/** May the current user see the live panel / page? */
function presence_can_view() {
    if (PRESENCE_VIEW_ALL) return !empty($_SESSION['user_id']);
    if (!function_exists('rbac_context')) require_once __DIR__ . '/rbac.php';
    $ctx = rbac_context();
    return !empty($_SESSION['user_id']) && !empty($ctx['full']);
}
