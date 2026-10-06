<?php
/**
 * cart.php — Session-based shopping cart API (no login required).
 * Called from includes/footer.php's cart drawer via fetch().
 *
 *   GET  ?action=list                                   → current cart (JSON)
 *   POST action=add    item_id, variation_id?, qty?      → add/increment a line
 *   POST action=update key, qty                          → set a line's quantity (0 removes it)
 *   POST action=remove key                                → remove a line
 *   POST action=clear                                      → empty the cart
 *
 * Every response is JSON: {"ok": bool, "error"?: string, "items": [...], "count": n, "subtotal": n}
 */
require_once __DIR__ . '/config/config.php';

header('Content-Type: application/json; charset=utf-8');

/** Always emits valid JSON, even if the payload has bad UTF-8 in it somewhere. */
function cart_json_out(array $payload): void
{
    $out = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    if ($out === false) {
        $out = json_encode(['ok' => false, 'error' => 'Server error building the cart response.']);
    }
    echo $out;
}

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$action = $isPost ? (string)($_POST['action'] ?? '') : (string)($_GET['action'] ?? 'list');

/* Every branch below can hit the database; if any of it throws (a missing
   column, a lock timeout, a stray notice with display_errors briefly on,
   etc.) we still want to answer with valid JSON instead of letting a raw
   PHP error page reach the browser — that's what turns into the generic
   "Could not add to cart" toast client-side, because fetch() can't parse
   HTML as JSON. Catching here keeps the real reason in the server log
   while the customer still gets a clean, safe response. */
try {
    switch ($action) {
        case 'add':
            $itemId      = (int)($_POST['item_id'] ?? 0);
            $variationId = !empty($_POST['variation_id']) ? (int)$_POST['variation_id'] : null;
            $qty         = (int)($_POST['qty'] ?? 1);
            cart_json_out(cart_add($itemId, $variationId, $qty ?: 1));
            break;

        case 'update':
            $key = (string)($_POST['key'] ?? '');
            $qty = (int)($_POST['qty'] ?? 1);
            cart_json_out(cart_update_qty($key, $qty));
            break;

        case 'remove':
            $key = (string)($_POST['key'] ?? '');
            cart_json_out(cart_remove($key));
            break;

        case 'clear':
            cart_json_out(cart_clear());
            break;

        case 'list':
        default:
            cart_json_out(['ok' => true] + cart_summary());
            break;
    }
} catch (\Throwable $e) {
    error_log('cart.php [' . $action . '] failed: ' . $e->getMessage());
    http_response_code(200); // keep it a clean JSON response, not a broken 500 page
    cart_json_out(['ok' => false, 'error' => 'Something went wrong updating your cart. Please try again.']);
}
