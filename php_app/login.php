<?php
// login.php - Login page
include 'config.php';
include 'auth.php';

// If already logged in, redirect to dashboard
if (isLoggedIn()) {
    header('Location: index.php');
    exit();
}

$error_message   = '';
$success_message = '';

if (isset($_SESSION['login_error'])) {
    $error_message = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}
if (isset($_GET['logout']) && $_GET['logout'] == 1) {
    $success_message = 'You have been logged out successfully.';
}
if (isset($_GET['timeout']) && $_GET['timeout'] == 1) {
    $error_message = 'Your session has expired. Please login again.';
}
if (isset($_GET['unauthorized'])) {
    $error_message = 'Please login to access this page.';
}

// Fetch active notifications (active=1 and not yet expired)
// Using UTC_TIMESTAMP() to match DB timezone (+00:00)
$live_notifications = [];
if ($conn) {
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS notifications (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        type ENUM('info','success','warning','danger') DEFAULT 'info',
        valid_from DATETIME NOT NULL,
        valid_until DATETIME NOT NULL,
        active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    // Use UTC_TIMESTAMP() to match server DB timezone (+00:00)
    // Show if active=1 AND valid_until not yet passed — including scheduled ones
    $notif_sql    = "SELECT * FROM notifications 
                     WHERE active = 1 
                       AND valid_until >= UTC_TIMESTAMP()
                     ORDER BY valid_from ASC";
    $notif_result = mysqli_query($conn, $notif_sql);
    if ($notif_result && mysqli_num_rows($notif_result) > 0) {
        $live_notifications = mysqli_fetch_all($notif_result, MYSQLI_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - YMS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/login.css">

    <style>
    /* ===================== LOGIN NOTIFICATIONS ===================== */
    .login-notifications {
        width: 100%;
        max-width: 420px;
        margin: 0 auto 20px auto;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    .login-notif {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 13px 16px;
        border-radius: 10px;
        border-left: 4px solid;
        font-size: 13px;
        animation: notifIn 0.4s ease;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    }
    @keyframes notifIn {
        from { opacity: 0; transform: translateY(-8px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .login-notif i {
        font-size: 16px;
        margin-top: 2px;
        flex-shrink: 0;
    }
    .notif-content strong {
        display: block;
        font-weight: 600;
        margin-bottom: 3px;
        font-size: 13px;
    }
    .notif-content p {
        margin: 0;
        line-height: 1.5;
        font-size: 12px;
        opacity: 0.85;
    }
    .notif-info    { background: #eff6ff; border-color: #3b82f6; color: #1e40af; }
    .notif-success { background: #f0fdf4; border-color: #22c55e; color: #166534; }
    .notif-warning { background: #fffbeb; border-color: #f59e0b; color: #92400e; }
    .notif-danger  { background: #fef2f2; border-color: #ef4444; color: #991b1b; }
    </style>
</head>
<body>

<div class="login-container">
    <div class="login-left">
        <div class="login-branding">
            <div class="brand-logo">
                <div class="logo-icon">
                    <img src="images/logo.webp" style="width:40px;" alt="YMS Logo">
                </div>
                <div class="brand-text">
                    <h1>YMS</h1>
                    <p>Yelo Group Management System</p>
                </div>
            </div>
            <div class="brand-description">
                <h2>Welcome Back</h2>
                <p>Sign in to access your management dashboard and streamline your business operations.</p>
            </div>
            <div class="brand-features">
                <img src="images/login_bg.jpeg" style="border-radius:20px;" alt="Login background">
            </div>
        </div>
    </div>

    <div class="login-right">

        <?php if (!empty($live_notifications)): ?>
        <div class="login-notifications">
            <?php foreach ($live_notifications as $notif):
                $type = htmlspecialchars($notif['type']);
                $icon = $type === 'info'    ? 'fa-circle-info' :
                       ($type === 'success' ? 'fa-circle-check' :
                       ($type === 'warning' ? 'fa-triangle-exclamation' :
                                              'fa-circle-exclamation'));
            ?>
            <div class="login-notif notif-<?php echo $type; ?>">
                <i class="fa-solid <?php echo $icon; ?>"></i>
                <div class="notif-content">
                    <strong><?php echo htmlspecialchars($notif['title']); ?></strong>
                    <p><?php echo htmlspecialchars($notif['message']); ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="login-box">
            <div class="login-header">
                <h2>Sign In</h2>
                <p>Enter your credentials to continue</p>
            </div>

            <?php if (!empty($error_message)): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <span><?php echo htmlspecialchars($error_message); ?></span>
            </div>
            <?php endif; ?>

            <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <i class="fa-solid fa-circle-check"></i>
                <span><?php echo htmlspecialchars($success_message); ?></span>
            </div>
            <?php endif; ?>

            <form id="loginForm" method="POST" action="login_process.php">

                <div class="form-group">
                    <label for="username">
                        <i class="fa-solid fa-user"></i> Username
                    </label>
                    <input type="text" id="username" name="username"
                           placeholder="Enter your username"
                           required autocomplete="username" autofocus>
                </div>

                <div class="form-group">
                    <label for="password">
                        <i class="fa-solid fa-lock"></i> Password
                    </label>
                    <div class="password-input">
                        <input type="password" id="password" name="password"
                               placeholder="Enter your password"
                               required autocomplete="current-password">
                        <button type="button" class="toggle-password"
                                onclick="togglePassword()">
                            <i class="fa-solid fa-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>

                <input type="hidden" id="email" name="email" value="">

                <div class="form-options">
                    <label class="checkbox-container">
                        <input type="checkbox" name="remember" id="remember">
                        <span class="checkmark"></span>
                        Remember me
                    </label>
                    <a href="forgot_password.php" class="forgot-link">Forgot Password?</a>
                </div>

                <button type="submit" class="btn-login" id="loginBtn">
                    <span>Sign In</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </button>
            </form>
        </div>

        <div class="login-support">
            <a href="#"><i class="fa-solid fa-circle-question"></i> Need Help?</a>
            <span class="separator">•</span>
            <a href="privacy_policy.php" target="_blank"><i class="fa-solid fa-shield-halved"></i> Privacy Policy</a>
        </div>
    </div>
</div>

<script>
    function togglePassword() {
        const input = document.getElementById('password');
        const icon  = document.getElementById('toggleIcon');
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    }

    document.getElementById('loginForm').addEventListener('submit', function (e) {
        const username = document.getElementById('username').value.trim();
        const password = document.getElementById('password').value;
        const btn      = document.getElementById('loginBtn');

        if (!username || !password) {
            e.preventDefault();
            alert('Please fill in all fields');
            return;
        }
        btn.classList.add('loading');
        btn.disabled = true;
    });
</script>
</body>
</html>