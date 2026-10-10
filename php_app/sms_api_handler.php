<?php
/**
 * sms_api_handler.php
 * Dialog eSMS API Handler — handles all AJAX requests from sms_settings.php
 * Must be in the same directory as sms_settings.php
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

include 'config.php';

/* ── Helper: Get setting from DB ── */
function getSetting($conn, $key) {
    $k = mysqli_real_escape_string($conn, $key);
    $r = mysqli_query($conn, "SELECT `value` FROM sms_settings WHERE `key`='$k'");
    if ($r && $row = mysqli_fetch_assoc($r)) return $row['value'];
    return '';
}

/* ── Helper: Save setting to DB ── */
function setSetting($conn, $key, $value) {
    $k = mysqli_real_escape_string($conn, $key);
    $v = mysqli_real_escape_string($conn, $value);
    return mysqli_query($conn,
        "INSERT INTO sms_settings (`key`,`value`) VALUES ('$k','$v')
         ON DUPLICATE KEY UPDATE `value`='$v', updated_at=NOW()");
}

/* ── Helper: Call Dialog eSMS API ── */
function callDialogAPI($endpoint, $payload, $token = null) {
    $base_url = 'https://e-sms.dialog.lk';
    $url = $base_url . $endpoint;

    $headers = ['Content-Type: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
        // SSL — set to true in production if your server has valid CA certs
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; eSMS-Client/1.0)',
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    $curl_errno= curl_errno($ch);
    curl_close($ch);

    if ($curl_errno) {
        return [
            'error'    => true,
            'message'  => 'Connection failed (cURL #' . $curl_errno . '): ' . $curl_err,
            'http_code'=> 0,
        ];
    }

    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'error'    => true,
            'message'  => 'Invalid JSON response from API. HTTP ' . $http_code . '. Raw: ' . substr($response, 0, 200),
            'http_code'=> $http_code,
        ];
    }

    $decoded['_http_code'] = $http_code;
    return $decoded;
}

$action = trim($_POST['action'] ?? '');

/* ════════════════════════════════════════════
   ACTION: fetch_token
   Calls /api/v1/login, saves token + expiry
════════════════════════════════════════════ */
if ($action === 'fetch_token') {

    $username = getSetting($conn, 'sms_username');
    $password = getSetting($conn, 'sms_password');

    if (!$username || !$password) {
        echo json_encode(['success' => false, 'message' => 'Credentials not configured. Save your username and password first.']);
        exit;
    }

    $result = callDialogAPI('/api/v1/login', [
        'username' => $username,
        'password' => $password,
    ]);

    if (!empty($result['error'])) {
        echo json_encode(['success' => false, 'message' => $result['message']]);
        exit;
    }

    // API doc: success response has "status" => "success" and field "token" (not "accessToken")
    if (isset($result['status']) && $result['status'] === 'success') {
        $token       = $result['token']      ?? '';
        $expiration  = $result['expiration'] ?? 43200; // default 12 hours
        $wallet      = $result['userData']['walletBalance'] ?? 'N/A';
        $defaultMask = $result['userData']['defaultMask']   ?? '';
        $fname       = $result['userData']['fname']         ?? '';
        $lname       = $result['userData']['lname']         ?? '';
        $mobile      = $result['userData']['mobile']        ?? '';

        setSetting($conn, 'sms_token',        $token);
        setSetting($conn, 'sms_token_expiry', time() + intval($expiration));
        if ($wallet !== 'N/A') setSetting($conn, 'sms_wallet_balance', $wallet);

        echo json_encode([
            'success'     => true,
            'token_short' => substr($token, 0, 20) . '…',
            'wallet'      => $wallet,
            'defaultMask' => $defaultMask,
            'fname'       => $fname,
            'lname'       => $lname,
            'mobile'      => $mobile,
            'expiry'      => date('d M Y H:i', time() + intval($expiration)),
            'expiry_secs' => $expiration,
        ]);
    } else {
        $comment  = $result['comment']  ?? 'Login failed.';
        $errCode  = $result['errCode']  ?? '';
        $remaining= $result['remainingCount'] ?? null;
        $msg = $comment;
        if ($errCode)   $msg .= ' (Error code: ' . $errCode . ')';
        if ($remaining !== null) $msg .= ' — ' . $remaining . ' login attempt(s) remaining.';
        echo json_encode(['success' => false, 'message' => $msg]);
    }
    exit;
}

/* ════════════════════════════════════════════
   ACTION: get_balance
   Re-fetches token to get fresh wallet balance
════════════════════════════════════════════ */
if ($action === 'get_balance') {
    // Re-use fetch_token logic
    $_POST['action'] = 'fetch_token';
    // Fall through handled by re-including — just echo same result
    // Actually just call same API
    $username = getSetting($conn, 'sms_username');
    $password = getSetting($conn, 'sms_password');

    if (!$username || !$password) {
        echo json_encode(['success' => false, 'message' => 'Credentials not configured.']);
        exit;
    }

    $result = callDialogAPI('/api/v1/login', ['username' => $username, 'password' => $password]);

    if (!empty($result['error'])) {
        echo json_encode(['success' => false, 'message' => $result['message']]);
        exit;
    }

    if (isset($result['status']) && $result['status'] === 'success') {
        $token      = $result['token']      ?? '';
        $expiration = $result['expiration'] ?? 43200;
        $wallet     = $result['userData']['walletBalance'] ?? 'N/A';
        setSetting($conn, 'sms_token',        $token);
        setSetting($conn, 'sms_token_expiry', time() + intval($expiration));
        if ($wallet !== 'N/A') setSetting($conn, 'sms_wallet_balance', $wallet);
        echo json_encode(['success' => true, 'wallet' => $wallet, 'expiry' => date('d M Y H:i', time() + intval($expiration))]);
    } else {
        echo json_encode(['success' => false, 'message' => $result['comment'] ?? 'Failed.']);
    }
    exit;
}

/* ════════════════════════════════════════════
   ACTION: send_test_sms
   Calls /api/v1/sms
   NOTE: transaction_id must be a unique INT
════════════════════════════════════════════ */
if ($action === 'send_test_sms') {

    $to      = trim($_POST['test_number']  ?? '');
    $message = trim($_POST['test_message'] ?? '');
    $mask    = trim($_POST['test_mask']    ?? '');

    if (!$to) {
        echo json_encode(['success' => false, 'message' => 'Recipient phone number is required.']);
        exit;
    }
    if (!$message) {
        echo json_encode(['success' => false, 'message' => 'Message cannot be empty.']);
        exit;
    }

    // Get/refresh token
    $token       = getSetting($conn, 'sms_token');
    $token_expiry= getSetting($conn, 'sms_token_expiry');

    // Auto-refresh token if expired or missing
    if (!$token || (intval($token_expiry) > 0 && time() > intval($token_expiry))) {
        $username = getSetting($conn, 'sms_username');
        $password = getSetting($conn, 'sms_password');
        if (!$username || !$password) {
            echo json_encode(['success' => false, 'message' => 'No valid token and credentials not configured.']);
            exit;
        }
        $loginResult = callDialogAPI('/api/v1/login', ['username' => $username, 'password' => $password]);
        if (!empty($loginResult['error']) || ($loginResult['status'] ?? '') !== 'success') {
            echo json_encode(['success' => false, 'message' => 'Token refresh failed: ' . ($loginResult['comment'] ?? $loginResult['message'] ?? 'Unknown error')]);
            exit;
        }
        $token = $loginResult['token'] ?? '';
        setSetting($conn, 'sms_token',        $token);
        setSetting($conn, 'sms_token_expiry', time() + intval($loginResult['expiration'] ?? 43200));
    }

    if (!$token) {
        echo json_encode(['success' => false, 'message' => 'Could not obtain access token. Check your credentials.']);
        exit;
    }

    // Normalize mobile number to 9-digit format as required by API
    // API doc: msisdn array with "mobile": "7XXXXXXXX" (9 digits)
    $mobile = preg_replace('/\D/', '', $to); // strip non-digits
    if (strlen($mobile) === 11 && substr($mobile, 0, 2) === '94') {
        $mobile = substr($mobile, 2); // remove country code 94
    } elseif (strlen($mobile) === 10 && $mobile[0] === '0') {
        $mobile = substr($mobile, 1); // remove leading 0
    }
    // mobile should now be 9 digits
    if (strlen($mobile) !== 9) {
        echo json_encode(['success' => false, 'message' => 'Invalid mobile number format. Use 07XXXXXXXX, 94XXXXXXXXX, or 9-digit format.']);
        exit;
    }

    // Use saved mask if not overridden
    if (!$mask) {
        $mask = getSetting($conn, 'sms_mask');
    }

    // transaction_id: unique integer, 1 to 19 digits — use microseconds-based unique int
    $transaction_id = (int)(microtime(true) * 1000) % 9999999999999999999;

    $payload = [
        'msisdn'         => [['mobile' => $mobile]],
        'message'        => $message,
        'transaction_id' => $transaction_id,
    ];
    // Only add sourceAddress if we have one (it's optional per API doc)
    if ($mask) {
        $payload['sourceAddress'] = $mask;
    }

    $result = callDialogAPI('/api/v1/sms', $payload, $token);

    if (!empty($result['error'])) {
        echo json_encode(['success' => false, 'message' => $result['message']]);
        exit;
    }

    if (isset($result['status']) && $result['status'] === 'success') {
        $data = $result['data'] ?? [];
        $wallet = $data['walletBalance'] ?? '';
        if ($wallet !== '') setSetting($conn, 'sms_wallet_balance', $wallet);
        echo json_encode([
            'success'     => true,
            'campaign_id' => $data['campaignId']         ?? '',
            'cost'        => $data['campaignCost']        ?? 0,
            'balance'     => $wallet,
            'duplicates'  => $data['duplicatesRemoved']   ?? 0,
            'invalid'     => $data['invalidNumbers']      ?? 0,
            'comment'     => $result['comment']           ?? '',
        ]);
    } else {
        $comment = $result['comment'] ?? 'Failed to send SMS.';
        $errCode = $result['errCode'] ?? '';
        $msg = $comment;
        if ($errCode) $msg .= ' (Error code: ' . $errCode . ')';
        echo json_encode(['success' => false, 'message' => $msg]);
    }
    exit;
}

/* ════════════════════════════════════════════
   ACTION: check_transaction
   Calls /api/v1/sms/check-transaction
════════════════════════════════════════════ */
if ($action === 'check_transaction') {
    $txn_id = trim($_POST['transaction_id'] ?? '');
    if (!$txn_id) {
        echo json_encode(['success' => false, 'message' => 'Transaction ID required.']);
        exit;
    }

    $token = getSetting($conn, 'sms_token');
    if (!$token) {
        echo json_encode(['success' => false, 'message' => 'No access token. Please refresh token first.']);
        exit;
    }

    $result = callDialogAPI('/api/v1/sms/check-transaction', ['transaction_id' => intval($txn_id)], $token);

    if (!empty($result['error'])) {
        echo json_encode(['success' => false, 'message' => $result['message']]);
        exit;
    }

    if (isset($result['status']) && $result['status'] === 'success') {
        echo json_encode([
            'success'         => true,
            'campaign_status' => $result['data']['campaign status'] ?? 'unknown',
            'transaction_id'  => $result['transaction_id'] ?? $txn_id,
            'comment'         => $result['comment'] ?? '',
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => $result['comment'] ?? 'Failed to check transaction.']);
    }
    exit;
}

// Unknown action
echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);
exit;
