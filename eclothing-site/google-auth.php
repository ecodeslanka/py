<?php
/**
 * google-auth.php — AJAX: receives the Google Identity Services ID token
 * from register.php / login.php, verifies it with Google, then either
 * logs in the matching customer or creates a new one from the Google profile.
 */
require_once __DIR__ . '/config/config.php';
ensure_customer_tables();
header('Content-Type: application/json');

$siteSettings = get_site_settings();
if (empty($siteSettings['enable_google_signin']) || empty($siteSettings['google_client_id'])) {
    echo json_encode(['success' => false, 'message' => 'Google sign-in is not enabled.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$sent  = is_array($input) ? (string)($input['csrf_token'] ?? '') : '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $sent)) {
    echo json_encode(['success' => false, 'message' => 'Session expired. Please refresh the page and try again.']);
    exit;
}

$idToken = is_array($input) ? (string)($input['credential'] ?? '') : '';
if ($idToken === '') {
    echo json_encode(['success' => false, 'message' => 'Missing Google credential.']);
    exit;
}

$payload = verify_google_id_token($idToken);
if (!$payload) {
    echo json_encode(['success' => false, 'message' => 'Could not verify your Google account. Please try again.']);
    exit;
}

$googleId  = $payload['sub'];
$email     = $payload['email'];
$firstName = $payload['given_name'] ?? explode(' ', (string)($payload['name'] ?? 'Customer'))[0];
$lastName  = $payload['family_name'] ?? '';
$avatar    = $payload['picture'] ?? null;

/* 1) Already linked to this Google account → log in */
$st = db()->prepare('SELECT * FROM customers WHERE google_id = ? LIMIT 1');
$st->execute([$googleId]);
$customer = $st->fetch();

/* 2) Existing email/password account with the same email → link Google to it */
if (!$customer) {
    $st = db()->prepare('SELECT * FROM customers WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    $customer = $st->fetch();
    if ($customer) {
        db()->prepare('UPDATE customers SET google_id = ?, avatar = COALESCE(avatar, ?) WHERE id = ?')
            ->execute([$googleId, $avatar, $customer['id']]);
    }
}

/* 3) Brand-new customer signing up via Google */
$isNew = false;
if (!$customer) {
    $placeholderMobile = 'g-' . substr($googleId, 0, 18); // temp unique placeholder until the customer adds a real number
    $st = db()->prepare('INSERT INTO customers
        (first_name, last_name, email, mobile, google_id, avatar, mobile_verified, agreed_terms, marketing_opt_in)
        VALUES (?, ?, ?, ?, ?, ?, 0, 1, 0)');
    $st->execute([$firstName ?: 'Customer', $lastName, $email, $placeholderMobile, $googleId, $avatar]);
    $customerId = (int)db()->lastInsertId();

    $st = db()->prepare('SELECT * FROM customers WHERE id = ?');
    $st->execute([$customerId]);
    $customer = $st->fetch();
    $isNew = true;

    send_welcome_email($customer);
}

session_regenerate_id(true);
$_SESSION['customer_id']   = (int)$customer['id'];
$_SESSION['customer_name'] = $customer['first_name'];
db()->prepare('UPDATE customers SET last_login = NOW() WHERE id = ?')->execute([$customer['id']]);

echo json_encode(['success' => true, 'new_account' => $isNew]);
