<?php
/** verify-otp.php — AJAX: verifies a submitted OTP code for a mobile number. */
require_once __DIR__ . '/config/config.php';
ensure_customer_tables();
header('Content-Type: application/json');

try {
    csrf_verify();
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Session expired. Please refresh the page.']);
    exit;
}

$mobile = normalize_lk_mobile((string)($_POST['mobile'] ?? ''));
$code   = trim((string)($_POST['code'] ?? ''));

if (!$mobile || !preg_match('/^\d{6}$/', $code)) {
    echo json_encode(['success' => false, 'message' => 'Enter the 6-digit code sent to your phone.']);
    exit;
}

if (verify_otp($mobile, $code)) {
    echo json_encode(['success' => true, 'message' => 'Mobile number verified.']);
} else {
    echo json_encode(['success' => false, 'message' => 'That code is invalid or has expired.']);
}
