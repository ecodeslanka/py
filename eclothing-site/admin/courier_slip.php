<?php
/**
 * admin/courier_slip.php — Printable Shipping Label
 * Sized for a real 6 x 4 inch shipping/courier label (the standard thermal
 * label size — 4in wide x 6in tall), branded with the store's logo + colors.
 * Auto-print, no admin chrome — standalone print page.
 */
require_once __DIR__ . '/includes/auth.php';
ensure_orders_table();

$orderId = (int)($_GET['id'] ?? 0);
$order   = get_order_by_id($orderId);
if (!$order) {
    http_response_code(404);
    exit('Order not found.');
}

$siteSettings = get_site_settings();
$companyName  = $siteSettings['company_name'] ?: SITE_NAME;
$mainContact  = get_main_contact_number();
$logoUrl      = !empty($siteSettings['site_logo']) ? BASE_URL . $siteSettings['site_logo'] : '';

$paymentLabels = [
    'cod' => 'Cash on Delivery', 'bank_transfer' => 'Bank Transfer',
    'koko' => 'KOKO', 'frimi' => 'FRIMI', 'card' => 'Card', 'genie' => 'Card (Genie Business)',
];
$isCod    = $order['payment_method'] === 'cod';
$codTotal = (float)$order['subtotal'] + (float)($order['delivery_charge'] ?? 0);
$itemCount = 0;
foreach ($order['items'] as $it) { $itemCount += (int)$it['qty']; }

/* A crude, print-friendly "barcode" made of CSS bars keyed off the tracking/order
   number's characters — not a scannable symbology, just a visual cue that reads
   clearly on a thermal/label printer next to the printed number. */
function slip_barcode_bars(string $code): string
{
    $bars = '';
    $i = 0;
    foreach (str_split($code) as $ch) {
        $w = 2 + (ord($ch) % 4); // 2-5px
        $gap = ($i % 2 === 0) ? 1 : 2;
        $bars .= '<span style="display:inline-block;width:' . $w . 'px;height:100%;background:#111;margin-right:' . $gap . 'px"></span>';
        $i++;
    }
    return $bars;
}

$labelCode = $order['tracking_no'] ?: $order['order_no'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Shipping Label — <?= e($order['order_no']) ?></title>
<style>
  *{margin:0;padding:0;box-sizing:border-box}
  :root{--red:#141414;--red-dark:#141414;--gold:#0a84ff;}
  body{font-family:'Segoe UI',Arial,sans-serif;color:#161010;background:#ccc;padding:24px}

  /* ===== 6 x 4 inch label (4in wide x 6in tall) ===== */
  .label{width:4in;min-height:6in;margin:0 auto;background:#fff;border:1px solid #999;
    display:flex;flex-direction:column;overflow:hidden;box-shadow:0 6px 24px rgba(0,0,0,.25)}

  .label-head{background:var(--red);color:#fff;padding:10px 12px;display:flex;align-items:center;gap:8px}
  .label-head img{height:34px;width:auto;background:#fff;border-radius:4px;padding:2px}
  .label-head .co-name{font-size:15px;font-weight:800;line-height:1.15}
  .label-head .co-tag{font-size:8.5px;color:var(--gold);font-weight:700;letter-spacing:.4px;text-transform:uppercase}

  .label-body{padding:10px 12px;flex:1;display:flex;flex-direction:column;gap:8px}

  .from-box{font-size:9px;color:#555;border-bottom:1px dashed #ccc;padding-bottom:7px}
  .from-box b{color:#161010;font-size:9.5px}

  .to-box{border:2px solid #161010;border-radius:6px;padding:9px 10px}
  .to-box h4{font-size:8.5px;letter-spacing:1px;text-transform:uppercase;color:#666;margin-bottom:4px}
  .to-box .name{font-size:15px;font-weight:800;line-height:1.2;margin-bottom:3px}
  .to-box .addr{font-size:11.5px;line-height:1.4}
  .to-box .phone{font-size:12px;font-weight:700;margin-top:4px}

  .row-badges{display:flex;gap:6px;flex-wrap:wrap}
  .badge{border-radius:6px;padding:5px 9px;font-size:10.5px;font-weight:800;border:1.5px solid #161010}
  .badge.cod{background:var(--gold);border-color:#161010}
  .badge.pay{background:#f2f2f2;color:#444;border-color:#ccc;font-weight:700}

  .order-box{border:1.5px solid #ddd;border-radius:6px;padding:8px 10px;font-size:10.5px}
  .order-box .row{display:flex;justify-content:space-between;margin-bottom:3px}
  .order-box .row:last-child{margin-bottom:0}
  .order-box .lbl{color:#777}
  .order-box .val{font-weight:700;text-align:right;max-width:65%}

  .items-mini{font-size:9.5px;color:#555;border-top:1px dashed #ccc;padding-top:7px;line-height:1.5}
  .items-mini b{color:#161010}

  .track-box{margin-top:auto;border-top:2px solid #161010;padding-top:8px;text-align:center}
  .track-box .tk-label{font-size:8px;letter-spacing:1.5px;text-transform:uppercase;color:#777}
  .track-box .tk-no{font-size:15px;font-weight:800;letter-spacing:.5px;margin:2px 0 6px}
  .barcode{height:38px;display:flex;align-items:stretch;justify-content:center}

  .label-foot{background:#161010;color:#fff;text-align:center;font-size:8px;letter-spacing:.5px;padding:5px;text-transform:uppercase}

  .overlay{position:fixed;bottom:20px;right:20px;display:flex;gap:10px}
  .overlay button, .overlay a{font-family:inherit;font-size:13px;font-weight:700;padding:10px 16px;border-radius:8px;border:1px solid #111;background:#111;color:#fff;cursor:pointer;text-decoration:none}
  .overlay a.secondary{background:#fff;color:#111}

  @page{size:4in 6in;margin:0}
  @media print{
    body{background:#fff;padding:0}
    .label{box-shadow:none;border:none;width:4in;min-height:6in;height:6in;margin:0}
    .overlay{display:none}
  }
</style>
</head>
<body>

<div class="label">
  <div class="label-head">
    <?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt="<?= e($companyName) ?>"><?php endif; ?>
    <div>
      <div class="co-name"><?= e($companyName) ?></div>
      <div class="co-tag">Designed For Your Comfort</div>
    </div>
  </div>

  <div class="label-body">
    <div class="from-box">
      <b>FROM:</b> <?= e($companyName) ?><?php if (!empty($siteSettings['address'])): ?> — <?= e(preg_replace('/\s*\R\s*/', ', ', trim($siteSettings['address']))) ?><?php endif; ?><?php if ($mainContact): ?> — Tel: <?= e($mainContact['phone']) ?><?php endif; ?>
    </div>

    <div class="to-box">
      <h4>Deliver To</h4>
      <div class="name"><?= e($order['full_name']) ?></div>
      <div class="addr"><?= nl2br(e($order['address'])) ?><br><?= e($order['city']) ?><?= !empty($order['province']) ? ', ' . e($order['province']) : '' ?></div>
      <div class="phone">Tel: <?= e($order['phone']) ?></div>
    </div>

    <div class="row-badges">
      <?php if ($isCod): ?><span class="badge cod">COD: Rs. <?= number_format($codTotal, 2) ?></span><?php endif; ?>
      <span class="badge pay"><?= e($paymentLabels[$order['payment_method']] ?? $order['payment_method']) ?></span>
      <span class="badge pay"><?= (int)$itemCount ?> item<?= $itemCount === 1 ? '' : 's' ?></span>
    </div>

    <div class="order-box">
      <div class="row"><span class="lbl">Order No</span><span class="val"><?= e($order['order_no']) ?></span></div>
      <div class="row"><span class="lbl">Order Date</span><span class="val"><?= e(date('M j, Y', strtotime((string)$order['created_at']))) ?></span></div>
      <?php if ($order['courier_name']): ?><div class="row"><span class="lbl">Courier</span><span class="val"><?= e($order['courier_name']) ?></span></div><?php endif; ?>
      <div class="row"><span class="lbl">Weight/Notes</span><span class="val"><?= $order['notes'] ? e($order['notes']) : '—' ?></span></div>
    </div>

    <div class="items-mini">
      <b>Contents:</b>
      <?= e(implode(', ', array_map(fn($it) => $it['name'] . ' ×' . (int)$it['qty'], array_slice($order['items'], 0, 4)))) ?><?= count($order['items']) > 4 ? ' + ' . (count($order['items']) - 4) . ' more' : '' ?>
    </div>

    <div class="track-box">
      <div class="tk-label"><?= $order['tracking_no'] ? 'Tracking Number' : 'Order Number' ?></div>
      <div class="tk-no"><?= e($labelCode) ?></div>
      <div class="barcode"><?= slip_barcode_bars($labelCode) ?></div>
    </div>
  </div>

  <div class="label-foot">6 × 4 in Shipping Label · <?= e($companyName) ?></div>
</div>

<div class="overlay">
  <a class="secondary" href="<?= BASE_URL ?>/admin/orders">Close</a>
  <button type="button" onclick="window.print()">Print Label</button>
</div>

<script>
  window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 400); });
</script>
</body>
</html>
