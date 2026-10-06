<?php
/**
 * koko_callback.php — the single URL given to KOKO as _returnUrl, _cancelUrl
 * AND _responseUrl (see includes/functions.php -> koko_build_redirect_form()).
 * KOKO actually calls this URL in two completely different ways, and this
 * file must treat them very differently:
 *
 *   1. _responseUrl — a server-to-server HTTP POST made directly by KOKO's
 *      backend (no customer browser/session involved). Per KOKO's official
 *      API docs, this is the ONLY one of the three that carries a
 *      `signature` param. It is the sole source of truth for marking an
 *      order "paid" -> handled by koko_apply_backend_response().
 *
 *   2. _returnUrl / _cancelUrl — the customer's own browser being redirected
 *      back after finishing (or cancelling) payment on KOKO's hosted page.
 *      Per KOKO's docs these carry orderId/trnId/status only — NO signature
 *      — so they are never trusted to mark an order "paid" (only an explicit
 *      "cancelled" is recorded, since forging that has no upside for anyone)
 *      -> handled by koko_handle_browser_return().
 *
 * We tell the two apart by whether a non-empty `signature` param is present,
 * since that's the one field KOKO's docs say only ever appears on the
 * backend call.
 */
require_once __DIR__ . '/config/config.php';
ensure_orders_table();

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$params = $isPost ? $_POST : $_GET;
$hasSignature = trim((string)($params['signature'] ?? '')) !== '';

$orderNo = (string)($params['orderId'] ?? '');
if ($orderNo === '') {
    error_log('KOKO callback: request had no orderId at all: ' . json_encode($params));
    if ($hasSignature) {
        header('Content-Type: text/plain');
        http_response_code(400);
        echo 'Missing orderId';
        exit;
    }
    redirect('/checkout.php');
}

/* ---------- 1. Signed server-to-server response (_responseUrl) ---------- */
if ($hasSignature) {
    $order = koko_apply_backend_response($params);
    header('Content-Type: text/plain');
    if ($order) {
        echo 'OK';
    } else {
        http_response_code(400);
        echo 'Signature verification failed';
    }
    exit; // there is no customer browser/session on this leg — nothing to redirect
}

/* ---------- 2. Unsigned customer browser redirect (_returnUrl / _cancelUrl) ---------- */
$order = koko_handle_browser_return($params);

if (!$order) {
    flash('err', 'We could not find your order. If you completed payment, please contact us with your order number and we will verify it manually.');
    redirect('/checkout.php');
}

$_SESSION['recent_orders'] = $_SESSION['recent_orders'] ?? [];
if (!in_array($order['order_no'], $_SESSION['recent_orders'], true)) {
    $_SESSION['recent_orders'][] = $order['order_no'];
}

if ($order['payment_status'] === 'paid') {
    redirect('/checkout.php?order=' . urlencode($order['order_no']));
}

if ($order['payment_status'] === 'failed') {
    flash('err', 'Your KOKO payment was cancelled or could not be completed. Your order is still saved — you can try paying again or choose another payment method from your account.');
    redirect('/checkout.php');
}

// Still "pending" here — KOKO's signed backend confirmation (which is what
// actually marks the order paid) typically arrives within moments of this
// redirect, sometimes slightly before it. Rather than falsely tell the
// customer their payment failed, show the normal order-placed confirmation
// with a note that payment is still being confirmed.
flash('ok', "We're confirming your KOKO payment now — you'll get an email as soon as it's verified (usually within a few seconds). Your order is already saved either way.");
redirect('/checkout.php?order=' . urlencode($order['order_no']));
