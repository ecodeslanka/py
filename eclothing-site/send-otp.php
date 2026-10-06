<?php
/** send-otp.php — AJAX: generates and sends a mobile verification OTP. */
require_once __DIR__ . '/config/config.php';
ensure_customer_tables();
header('Content-Type: application/json');

try {
    csrf_verify();
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Session expired. Please refresh the page.']);
    exit;
}

$mobileRaw = trim((string)($_POST['mobile'] ?? ''));
$mobile    = normalize_lk_mobile($mobileRaw);

if (!$mobile) {
    echo json_encode(['success' => false, 'message' => 'Enter a valid Sri Lankan mobile number (e.g. 07XXXXXXXX).']);
    exit;
}

$existing = db()->prepare('SELECT id FROM customers WHERE mobile = ? LIMIT 1');
$existing->execute([$mobile]);
if ($existing->fetch()) {
    echo json_encode(['success' => false, 'message' => 'This mobile number is already registered.']);
    exit;
}

$code = generate_and_send_otp($mobile);
if ($code === null) {
    echo json_encode(['success' => false, 'message' => 'Please wait a moment before requesting another code.']);
    exit;
}

$response = ['success' => true, 'message' => 'A 6-digit code has been sent to ' . $mobile . '.'];

/* Dev convenience only: when SMS isn't fully configured yet and we're in
   development mode, surface the code so the flow can be tested end-to-end. */
if (ENVIRONMENT === 'development' && !sms_is_configured()) {
    $response['message'] .= " (dev mode — code: $code)";
}

echo json_encode($response);
