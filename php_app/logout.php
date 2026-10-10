<?php
// logout.php - Handle user logout
include 'config.php';
include 'auth.php';

// Log the logout (optional)
if (isLoggedIn()) {
    $user_id = $_SESSION['user_id'];
    
    // Create logout logs table if needed
    $createLogoutTable = "CREATE TABLE IF NOT EXISTS logout_logs (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        user_id INT(11) NOT NULL,
        logout_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    mysqli_query($conn, $createLogoutTable);
    
    // Log the logout
    $log_sql = "INSERT INTO logout_logs (user_id) VALUES ($user_id)";
    mysqli_query($conn, $log_sql);
    
    // Delete remember token if exists
    if (isset($_COOKIE['remember_token'])) {
        $token = $_COOKIE['remember_token'];
        $hashed_token = hash('sha256', $token);
        mysqli_query($conn, "DELETE FROM remember_tokens WHERE token = '$hashed_token'");
        setcookie('remember_token', '', time() - 3600, '/');
    }
}

// Destroy session
destroyUserSession();

// Redirect to login page
header('Location: login.php?logout=1');
exit();
?>