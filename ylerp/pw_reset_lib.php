<?php
/** pw_reset_lib.php — shared by forgot_password.php / reset_password.php */
require_once __DIR__ . '/mail_lib.php';
require_once __DIR__ . '/activity_lib.php';

function pr_ensure() {
    global $conn;
    al_ensure(); ml_ensure();
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL, used_at DATETIME NULL, ip VARCHAR(45) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_pr_hash (token_hash), INDEX idx_pr_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function pr_find_valid($token) {
    global $conn; pr_ensure();
    if (!preg_match('/^[a-f0-9]{64}$/', (string)$token)) return null;
    $h = hash('sha256', $token);
    $r = mysqli_query($conn, "SELECT pr.id AS rid, u.id, u.username, u.email FROM password_resets pr JOIN users u ON u.id = pr.user_id
                              WHERE pr.token_hash = '$h' AND pr.used_at IS NULL AND pr.expires_at > NOW() LIMIT 1");
    return $r ? (mysqli_fetch_assoc($r) ?: null) : null;
}
function pr_page($title, $inner) {
    $logo = is_file(__DIR__ . '/yelo_logo.png') ? '<img src="yelo_logo.png" alt="" style="height:54px;margin-bottom:10px">' : '';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($title) . '</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#0f172a,#1e293b 55%,#312e81);font-family:Inter,"Segoe UI",system-ui,sans-serif;padding:16px}
.card{background:#fff;border-radius:16px;padding:32px 30px;width:100%;max-width:420px;box-shadow:0 25px 70px rgba(0,0,0,.4)}
h1{margin:0 0 6px;font-size:21px;color:#111827}p.sub{margin:0 0 20px;color:#6b7280;font-size:13.5px;line-height:1.5}
label{display:block;font-weight:600;font-size:13px;color:#374151;margin:14px 0 6px}
.in{width:100%;padding:11px 13px;border:1.5px solid #d1d5db;border-radius:9px;font:inherit;font-size:14px}.in:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.15)}
.btn{margin-top:20px;width:100%;padding:12px;border:none;border-radius:9px;background:#4f46e5;color:#fff;font:600 14px inherit;cursor:pointer}.btn:hover{background:#4338ca}
.al{padding:11px 13px;border-radius:9px;font-size:13px;margin-bottom:14px;line-height:1.5}.er{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
a.back{display:block;text-align:center;margin-top:18px;color:#4f46e5;font-size:13px;text-decoration:none;font-weight:600}.pw{position:relative}.pw button{position:absolute;right:8px;top:50%;transform:translateY(-50%);border:none;background:none;color:#6b7280;cursor:pointer;padding:6px}
</style></head><body><div class="card"><div style="text-align:center">' . $logo . '</div>' . $inner . '</div></body></html>';
}
