<?php
/**
 * payment_webhook.php — receives async payment-status callbacks from
 * Genie Business Connect (set as the "webhook" URL when a transaction is
 * created — see includes/functions.php -> genie_create_transaction()).
 *
 * This is a server-to-server endpoint (no browser session), so it must not
 * rely on anything in $_SESSION. It always responds 200 so Genie doesn't
 * keep retrying once we've logged the payload, even if we couldn't match
 * an order (which we log for manual follow-up).
 */
require_once __DIR__ . '/config/config.php';

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);

if (!is_array($payload)) {
    error_log('Genie webhook: could not parse payload: ' . $raw);
    http_response_code(200);
    echo json_encode(['ok' => false, 'error' => 'invalid payload']);
    exit;
}

/* Optional: if Genie signs webhook payloads for your account, verify the
   signature header here before trusting the payload. Ask Genie Business
   support (genie.integration@dialog.lk) whether your account has this
   enabled and what header it uses, then add the check below. */

$applied = genie_apply_webhook($payload);

http_response_code(200);
echo json_encode(['ok' => $applied]);
