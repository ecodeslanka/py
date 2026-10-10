<?php
// session_extend.php
// Called by session_timeout.js when the user clicks "Extend Session"
include 'config.php';
include 'auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['ok' => false, 'reason' => 'not_logged_in']);
    exit();
}

// Extend by 2 hours (regardless of original duration)
$extension = 7200;
$_SESSION['session_expires_at'] = time() + $extension;
$_SESSION['session_duration']   = $extension;
$_SESSION['remember_me']        = false; // back to normal sliding window
$_SESSION['last_activity']      = time();

echo json_encode(['ok' => true, 'seconds' => $extension]);
?>
