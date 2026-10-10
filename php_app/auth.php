<?php
// auth.php - Authentication and Session Management

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ----------------------------------------------------------------
// Check if user is logged in
// ----------------------------------------------------------------
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['username']);
}

// ----------------------------------------------------------------
// Require login (redirect if not)
// ----------------------------------------------------------------
function requireLogin() {
    if (!isLoggedIn()) {
        // Try remember-me cookie auto-login before redirecting
        if (!autoLoginFromCookie()) {
            header('Location: login.php?unauthorized=1');
            exit();
        }
    }
}

// ----------------------------------------------------------------
// Auto-login from remember-me cookie
// ----------------------------------------------------------------
function autoLoginFromCookie() {
    global $conn;

    if (!isset($_COOKIE['remember_token'])) {
        return false;
    }

    $hashed = hash('sha256', $_COOKIE['remember_token']);
    $hashed = mysqli_real_escape_string($conn, $hashed);

    $sql = "SELECT rt.user_id, rt.expires_at, u.username, u.role_id, u.active,
                   r.role_name
            FROM remember_tokens rt
            JOIN users u ON u.id = rt.user_id
            LEFT JOIN roles r ON r.id = u.role_id
            WHERE rt.token = '$hashed'
              AND rt.expires_at > NOW()
            LIMIT 1";

    $result = mysqli_query($conn, $sql);
    if (!$result || mysqli_num_rows($result) === 0) {
        // Token invalid / expired — clear cookie
        setcookie('remember_token', '', time() - 3600, '/');
        return false;
    }

    $row = mysqli_fetch_assoc($result);

    if ($row['active'] != 1) {
        return false;
    }

    setUserSession([
        'id'        => $row['user_id'],
        'username'  => $row['username'],
        'role_id'   => $row['role_id'],
        'role_name' => $row['role_name'] ?? 'User',
        'active'    => $row['active'],
    ]);

    // Extend remember-me session: roll token expiry another 30 days
    $new_expiry = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60));
    $user_id    = intval($row['user_id']);
    mysqli_query($conn, "UPDATE remember_tokens
                         SET expires_at = '$new_expiry'
                         WHERE token = '$hashed' AND user_id = $user_id");

    setcookie('remember_token', $_COOKIE['remember_token'],
              time() + (30 * 24 * 60 * 60), '/', '', true, true);

    return true;
}

// ----------------------------------------------------------------
// Set user session
// ----------------------------------------------------------------
function setUserSession($user_data) {
    session_regenerate_id(true);          // Prevent session fixation
    $_SESSION['user_id']       = $user_data['id'];
    $_SESSION['username']      = $user_data['username'];
    $_SESSION['role_id']       = $user_data['role_id'];
    $_SESSION['role_name']     = $user_data['role_name'];
    $_SESSION['active']        = $user_data['active'];
    $_SESSION['last_activity'] = time();
}

// ----------------------------------------------------------------
// Destroy user session
// ----------------------------------------------------------------
function destroyUserSession() {
    session_unset();
    session_destroy();
}

// ----------------------------------------------------------------
// Get current user data
// ----------------------------------------------------------------
function getCurrentUser() {
    if (!isLoggedIn()) return null;

    return [
        'id'        => $_SESSION['user_id'],
        'username'  => $_SESSION['username'],
        'role_id'   => $_SESSION['role_id']   ?? null,
        'role_name' => $_SESSION['role_name'] ?? 'User',
        'active'    => $_SESSION['active']    ?? 1,
    ];
}

// ----------------------------------------------------------------
// User initials helper
// ----------------------------------------------------------------
function getUserInitials($username) {
    $words = explode(' ', $username);
    if (count($words) >= 2) {
        return strtoupper(substr($words[0], 0, 1) . substr($words[1], 0, 1));
    }
    return strtoupper(substr($username, 0, 2));
}

// ----------------------------------------------------------------
// Permission check
// ----------------------------------------------------------------
function hasPermission($module, $action = 'access') {
    global $conn;

    if (!isLoggedIn() || !isset($_SESSION['role_id'])) return false;

    $role_id = intval($_SESSION['role_id']);
    $module  = mysqli_real_escape_string($conn, $module);

    $sql    = "SELECT can_$action FROM permissions
               WHERE role_id = $role_id AND module_name = '$module'";
    $result = mysqli_query($conn, $sql);

    if ($row = mysqli_fetch_assoc($result)) {
        return $row["can_$action"] == 1;
    }
    return false;
}

// ----------------------------------------------------------------
// Session timeout check (30 min — skipped when "remember me" active)
// ----------------------------------------------------------------
if (isLoggedIn()) {
    $timeout = isset($_COOKIE['remember_token']) ? 86400 : 1800; // 24 h vs 30 min

    if (isset($_SESSION['last_activity']) &&
        (time() - $_SESSION['last_activity'] > $timeout)) {
        destroyUserSession();
        header('Location: login.php?timeout=1');
        exit();
    }
    $_SESSION['last_activity'] = time();
}
?>