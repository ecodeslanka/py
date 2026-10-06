<?php
/**
 * admin/orders.php — Order Manager
 * Lists every order placed at checkout, with status filtering, search, a
 * detail view (customer + delivery info + line items + status/tracking
 * timeline + payment history), a status-update modal (courier name /
 * tracking number / back-datable date), a payment-recording modal (manual
 * cash / bank transfer / cheque / etc. entries with optional proof upload —
 * orders.payment_status rolls up from these automatically), a delete
 * action, and a link to print a courier slip.
 *
 * Payment status for gateway orders (Genie Business Connect) is NEVER
 * editable here — it's only ever changed by payment_webhook.php the moment
 * the gateway confirms payment, so it always reflects the real, live state.
 */
require_once __DIR__ . '/includes/auth.php';
ensure_orders_table();

$pageTitle = 'Orders';
$active    = 'orders';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_status') {
        $id          = (int)($_POST['id'] ?? 0);
        $status      = (string)($_POST['status'] ?? '');
        $statusDate  = (string)($_POST['status_date'] ?? date('Y-m-d'));
        $courierName = trim((string)($_POST['courier_name'] ?? ''));
        $trackingNo  = trim((string)($_POST['tracking_no'] ?? ''));
        $note        = trim((string)($_POST['note'] ?? ''));

        if ($status === 'shipped' && ($courierName === '' || $trackingNo === '')) {
            flash('err', 'Please enter the courier name and tracking number for a shipped order.');
        } elseif (update_order_status_full($id, $status, $statusDate, $courierName ?: null, $trackingNo ?: null, $note ?: null)) {
            flash('ok', 'Order status updated.');
        } else {
            flash('err', 'Could not update that order\'s status.');
        }
        redirect('/admin/orders' . (!empty($_POST['redirect_qs']) ? '?' . $_POST['redirect_qs'] : ''));
    }

    if ($action === 'delete_order') {
        $id = (int)($_POST['id'] ?? 0);
        if (delete_order($id)) {
            flash('ok', 'Order deleted.');
        } else {
            flash('err', 'Could not delete that order.');
        }
        redirect('/admin/orders' . (!empty($_POST['redirect_qs']) ? '?' . $_POST['redirect_qs'] : ''));
    }

    if ($action === 'record_payment') {
        $id          = (int)($_POST['id'] ?? 0);
        $method      = (string)($_POST['method'] ?? 'cash');
        $amount      = (float)($_POST['amount'] ?? 0);
        $referenceNo = trim((string)($_POST['reference_no'] ?? ''));
        $paidDate    = (string)($_POST['paid_date'] ?? date('Y-m-d'));
        $note        = trim((string)($_POST['note'] ?? ''));

        $order = get_order_by_id($id);
        if (!$order) {
            flash('err', 'Order not found.');
        } elseif (!empty($order['payment_provider'])) {
            flash('err', 'This order\'s payment is handled automatically by ' . e(ucfirst((string)$order['payment_provider'])) . ' — it updates on its own once the gateway confirms payment, so manual entries are disabled for it.');
        } elseif ($amount <= 0) {
            flash('err', 'Please enter a valid payment amount.');
        } else {
            [$proofPath, $uploadErr] = process_payment_proof_upload('proof');
            if ($uploadErr) {
                flash('err', $uploadErr);
            } else {
                $paymentId = add_order_payment($id, $method, $amount, $referenceNo ?: null, $paidDate, $note ?: null, $proofPath, $adminName ?? 'Admin');
                if ($paymentId) {
                    flash('ok', 'Payment recorded.');
                } else {
                    flash('err', 'Could not record that payment.');
                }
            }
        }
        redirect('/admin/orders' . (!empty($_POST['redirect_qs']) ? '?' . $_POST['redirect_qs'] : ''));
    }

    if ($action === 'delete_payment') {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        if (delete_order_payment($paymentId)) {
            flash('ok', 'Payment entry removed.');
        } else {
            flash('err', 'Could not remove that payment entry.');
        }
        redirect('/admin/orders' . (!empty($_POST['redirect_qs']) ? '?' . $_POST['redirect_qs'] : ''));
    }
}

$statusFilter = trim((string)($_GET['status'] ?? ''));
$search       = trim((string)($_GET['q'] ?? ''));
$orders       = get_orders_admin($statusFilter ?: null, $search);
$counts       = get_order_status_counts();

$statusLabels    = order_status_labels();
$payStatusLabels = payment_status_labels();
$paymentLabels   = payment_method_labels();

// Deep-link support: ?order=EMXXXXXXXX from the admin notification email opens that order's detail modal.
$openOrderId = null;
if (!empty($_GET['order'])) {
    $found = get_order_by_no((string)$_GET['order']);
    if ($found) { $openOrderId = (int)$found['id']; }
}

$redirectQs = $_SERVER['QUERY_STRING'] ?? '';

require __DIR__ . '/includes/header.php';
?>

<?php if ($m = flash('ok')): ?><div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= e($m) ?></div><?php endif; ?>
<?php if ($m = flash('err')): ?><div class="alert alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($m) ?></div><?php endif; ?>

<div class="order-filter-tabs">
  <a href="<?= BASE_URL ?>/admin/orders" class="<?= $statusFilter === '' ? 'on' : '' ?>">All <span><?= (int)$counts['all'] ?></span></a>
  <?php foreach ($statusLabels as $val => $label): ?>
  <a href="<?= BASE_URL ?>/admin/orders?status=<?= e($val) ?>" class="<?= $statusFilter === $val ? 'on' : '' ?> st-<?= e($val) ?>"><?= e($label) ?> <span><?= (int)($counts[$val] ?? 0) ?></span></a>
  <?php endforeach; ?>
</div>

<div class="panel">
  <div class="panel-head">
    <h3>Orders (<?= count($orders) ?>)</h3>
    <form method="get" class="order-search">
      <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?= e($statusFilter) ?>"><?php endif; ?>
      <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search order no, name, email, phone…">
      <button type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
    </form>
  </div>
  <div class="panel-body" style="padding:0">
    <table>
      <thead>
        <tr><th>Order</th><th>Customer</th><th>Items</th><th>Total</th><th>Payment</th><th>Status</th><th>Tracking</th><th>Date</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($orders as $o):
          $itemCount = (int)db()->query('SELECT COUNT(*) c FROM order_items WHERE order_id = ' . (int)$o['id'])->fetch()['c'];
          $payStatus = $o['payment_status'] ?: 'unpaid';
        ?>
        <tr>
          <td><b><?= e($o['order_no']) ?></b></td>
          <td><?= e($o['full_name']) ?><br><span class="crumb"><?= e($o['email']) ?></span></td>
          <td><?= $itemCount ?></td>
          <td><b>Rs. <?= number_format((float)$o['subtotal'], 2) ?></b></td>
          <td>
            <span class="pay-pill pay-<?= e($payStatus) ?>"><?= e($payStatusLabels[$payStatus] ?? ucfirst($payStatus)) ?></span><br>
            <span class="crumb"><?= e($paymentLabels[$o['payment_method']] ?? $o['payment_method']) ?></span>
            <?php if (empty($o['payment_provider'])): ?>
            <div style="margin-top:6px">
              <button type="button" class="mini-btn add-val" onclick='openPaymentModal(<?= (int)$o["id"] ?>, <?= json_encode($o["order_no"]) ?>, <?= json_encode($o["payment_method"]) ?>)'>Record Payment</button>
            </div>
            <?php else: ?>
              <div class="crumb" style="margin-top:4px"><i class="fa-solid fa-bolt"></i> Auto (<?= e(ucfirst((string)$o['payment_provider'])) ?>)</div>
            <?php endif; ?>
          </td>
          <td>
            <span class="status-select st-<?= e($o['status']) ?>" style="display:inline-block;cursor:default"><?= e($statusLabels[$o['status']] ?? $o['status']) ?></span>
            <div style="margin-top:6px">
              <button type="button" class="mini-btn toggle" onclick='openStatusModal(<?= (int)$o["id"] ?>, <?= json_encode($o["order_no"]) ?>, <?= json_encode($o["status"]) ?>, <?= json_encode($o["courier_name"]) ?>, <?= json_encode($o["tracking_no"]) ?>)'>Update Status</button>
            </div>
          </td>
          <td>
            <?php if ($o['tracking_no']): ?>
              <span class="crumb"><?= e($o['courier_name'] ?: '—') ?></span><br><b style="font-size:12px"><?= e($o['tracking_no']) ?></b>
            <?php else: ?>
              <span class="crumb">—</span>
            <?php endif; ?>
          </td>
          <td><span class="crumb"><?= e(date('M j, Y g:i A', strtotime((string)$o['created_at']))) ?></span></td>
          <td>
            <div class="row-actions">
              <button type="button" class="mini-btn edit" onclick='openOrderView(<?= (int)$o["id"] ?>)'>View</button>
              <a class="mini-btn toggle" href="<?= BASE_URL ?>/admin/courier_slip?id=<?= (int)$o['id'] ?>" target="_blank"><i class="fa-solid fa-print"></i></a>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete order <?= e($o['order_no']) ?>? This permanently removes it and its items. This cannot be undone.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_order">
                <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                <input type="hidden" name="redirect_qs" value="<?= e($redirectQs) ?>">
                <button class="mini-btn del" type="submit">Delete</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$orders): ?>
        <tr><td colspan="9" style="color:var(--muted)">No orders <?= $statusFilter !== '' ? 'with status "' . e($statusLabels[$statusFilter] ?? $statusFilter) . '"' : 'yet' ?>.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ===== MODAL: Order detail ===== -->
<div id="orderOverlay" class="modal-overlay" onclick="if(event.target===this) closeOrderView()">
  <div class="modal-box" style="max-width:660px;max-height:85vh;overflow-y:auto">
    <h3 id="orderModalTitle">Order</h3>
    <div id="orderModalBody">Loading…</div>
    <div style="margin-top:18px">
      <button type="button" class="mini-btn toggle" onclick="closeOrderView()">Close</button>
    </div>
  </div>
</div>

<!-- ===== MODAL: Update status (with courier / tracking / back-datable date) ===== -->
<div id="statusOverlay" class="modal-overlay" onclick="if(event.target===this) closeStatusModal()">
  <div class="modal-box" style="max-width:460px">
    <h3 id="statusModalTitle">Update Order Status</h3>
    <form method="post" id="statusForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_status">
      <input type="hidden" name="id" id="statusOrderId" value="">
      <input type="hidden" name="redirect_qs" value="<?= e($redirectQs) ?>">
      <div class="form-grid" style="grid-template-columns:1fr 1fr">
        <label>Status
          <select name="status" id="statusSelect" onchange="toggleCourierFields()">
            <?php foreach ($statusLabels as $val => $label): ?>
              <option value="<?= e($val) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Status Date
          <input type="date" name="status_date" id="statusDate" value="<?= e(date('Y-m-d')) ?>" required>
        </label>
      </div>
      <div id="courierFields" style="margin-top:14px">
        <p style="font-size:12px;color:var(--muted);margin-bottom:10px"><i class="fa-solid fa-truck-fast"></i> Courier / tracking details (required when status is Shipped).</p>
        <div class="form-grid" style="grid-template-columns:1fr 1fr">
          <label>Courier Name
            <input type="text" name="courier_name" id="courierName" placeholder="e.g. Domex, Pronto, Aramex" maxlength="100">
          </label>
          <label>Tracking No.
            <input type="text" name="tracking_no" id="trackingNo" placeholder="e.g. TRK123456789" maxlength="100">
          </label>
        </div>
      </div>
      <div class="form-grid" style="grid-template-columns:1fr;margin-top:14px">
        <label>Note (optional)
          <input type="text" name="note" placeholder="Internal note about this update" maxlength="255">
        </label>
      </div>
      <div style="margin-top:18px;display:flex;gap:10px">
        <button type="submit" class="mini-btn edit" style="padding:10px 18px">Save</button>
        <button type="button" class="mini-btn toggle" style="padding:10px 18px" onclick="closeStatusModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ===== MODAL: Record manual payment (cash / bank transfer / cheque / etc.) ===== -->
<div id="paymentOverlay" class="modal-overlay" onclick="if(event.target===this) closePaymentModal()">
  <div class="modal-box" style="max-width:480px">
    <h3 id="paymentModalTitle">Record Payment</h3>
    <form method="post" id="paymentForm" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="record_payment">
      <input type="hidden" name="id" id="paymentOrderId" value="">
      <input type="hidden" name="redirect_qs" value="<?= e($redirectQs) ?>">
      <div class="form-grid" style="grid-template-columns:1fr 1fr">
        <label>Payment Type
          <select name="method" id="paymentMethod">
            <?php foreach ($paymentLabels as $val => $label): if ($val === 'genie') continue; ?>
              <option value="<?= e($val) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Amount (Rs.)
          <input type="number" name="amount" id="paymentAmount" step="0.01" min="0.01" required>
        </label>
      </div>
      <div class="form-grid" style="grid-template-columns:1fr 1fr;margin-top:14px">
        <label>Reference No. (optional)
          <input type="text" name="reference_no" placeholder="Bank ref / cheque no / txn id" maxlength="100">
        </label>
        <label>Payment Date
          <input type="date" name="paid_date" id="paymentDate" value="<?= e(date('Y-m-d')) ?>" required>
        </label>
      </div>
      <div class="form-grid" style="grid-template-columns:1fr;margin-top:14px">
        <label>Proof / Slip (optional — image or PDF, max 5MB)
          <input type="file" name="proof" accept=".jpg,.jpeg,.png,.webp,.pdf">
        </label>
      </div>
      <div class="form-grid" style="grid-template-columns:1fr;margin-top:14px">
        <label>Note (optional)
          <input type="text" name="note" placeholder="e.g. Deposit, balance settled at delivery" maxlength="255">
        </label>
      </div>
      <p id="paymentBalanceHint" style="font-size:12px;color:var(--muted);margin-top:10px"></p>
      <div style="margin-top:18px;display:flex;gap:10px">
        <button type="submit" class="mini-btn add-val" style="padding:10px 18px">Save Payment</button>
        <button type="button" class="mini-btn toggle" style="padding:10px 18px" onclick="closePaymentModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
const ORDER_DATA = {};
<?php foreach ($orders as $o):
  $full = get_order_by_id((int)$o['id']);
  $full['history']  = get_order_status_history((int)$o['id']);
  $full['payments'] = get_order_payments((int)$o['id']);
  $full['grand_total'] = order_grand_total($o);
?>
ORDER_DATA[<?= (int)$o['id'] ?>] = <?= json_encode($full, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
<?php endforeach; ?>
const PAYMENT_LABELS     = <?= json_encode($paymentLabels) ?>;
const PAY_STATUS_LABELS  = <?= json_encode($payStatusLabels) ?>;
const STATUS_LABELS      = <?= json_encode($statusLabels) ?>;
const CSRF_FIELD_HTML    = <?= json_encode(csrf_field()) ?>;
const REDIRECT_QS        = <?= json_encode($redirectQs) ?>;

function moneyFmt(n){ return 'Rs. ' + Number(n).toLocaleString('en-LK', {minimumFractionDigits:2, maximumFractionDigits:2}); }
function escHtml(s){
  return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
  });
}
function dateFmt(s){
  if(!s) return '';
  const d = new Date(s + 'T00:00:00');
  if (isNaN(d.getTime())) return escHtml(s);
  return d.toLocaleDateString('en-LK', {year:'numeric', month:'short', day:'numeric'});
}

function openOrderView(id){
  const o = ORDER_DATA[id];
  if (!o) return;
  document.getElementById('orderModalTitle').textContent = 'Order ' + o.order_no;
  const itemsHtml = o.items.map(function(it){
    return '<div class="order-detail-line">' +
      '<div class="order-detail-img">' + (it.image_path ? '<img src="<?= BASE_URL ?>' + escHtml(it.image_path) + '" alt="">' : '<i class="fa-solid fa-image"></i>') + '</div>' +
      '<div class="order-detail-info"><span class="name">' + escHtml(it.name) + '</span><span class="qty">Qty: ' + it.qty + ' × ' + moneyFmt(it.price) + '</span></div>' +
      '<div class="order-detail-total">' + moneyFmt(it.line_total) + '</div>' +
    '</div>';
  }).join('');

  let trackingHtml = '';
  if (o.tracking_no) {
    trackingHtml = '<div><span>Courier</span><p>' + escHtml(o.courier_name || '—') + '<br><b>' + escHtml(o.tracking_no) + '</b></p></div>';
  }

  const payStatus = o.payment_status || 'unpaid';
  const payHtml = '<div><span>Payment</span><p>' +
    '<span class="pay-pill pay-' + escHtml(payStatus) + '">' + escHtml(PAY_STATUS_LABELS[payStatus] || payStatus) + '</span> ' +
    escHtml(PAYMENT_LABELS[o.payment_method] || o.payment_method) +
    (o.payment_provider ? ' <span class="crumb">(auto via ' + escHtml(o.payment_provider) + ')</span>' : '') +
    '</p></div>';

  let historyHtml = '';
  if (o.history && o.history.length) {
    historyHtml = '<div class="order-detail-meta" style="margin-top:8px"><div style="grid-column:1/-1"><span>Status Timeline</span>' +
      '<div class="order-timeline">' +
      o.history.map(function(h){
        let extra = '';
        if (h.tracking_no) { extra = ' — ' + escHtml(h.courier_name || '') + ' <b>' + escHtml(h.tracking_no) + '</b>'; }
        if (h.note) { extra += ' <span class="crumb">(' + escHtml(h.note) + ')</span>'; }
        return '<div class="order-timeline-row"><span class="status-select st-' + escHtml(h.status) + '">' + escHtml(STATUS_LABELS[h.status] || h.status) + '</span> <span class="crumb">' + dateFmt(h.status_date) + '</span>' + extra + '</div>';
      }).join('') +
      '</div></div></div>';
  }

  const totalPaid = (o.payments || []).reduce(function(sum, p){ return sum + Number(p.amount); }, 0);
  const balance = Math.max(0, Number(o.grand_total) - totalPaid);
  let paymentsHtml = '<div class="order-detail-meta" style="margin-top:8px"><div style="grid-column:1/-1"><span>Payment History</span>';
  if (o.payments && o.payments.length) {
    paymentsHtml += '<div class="pay-history">' + o.payments.map(function(p){
      let del = '';
      if (!o.payment_provider) {
        del = '<form method="post" style="display:inline" onsubmit="return confirm(\'Remove this payment entry?\');">' + CSRF_FIELD_HTML +
          '<input type="hidden" name="action" value="delete_payment">' +
          '<input type="hidden" name="payment_id" value="' + p.id + '">' +
          '<input type="hidden" name="redirect_qs" value="' + escHtml(REDIRECT_QS) + '">' +
          '<button type="submit" class="mini-btn del" style="padding:3px 9px;font-size:11px">Remove</button></form>';
      }
      const proof = p.proof_path ? ' <a href="<?= BASE_URL ?>' + escHtml(p.proof_path) + '" target="_blank" class="mini-btn toggle" style="padding:3px 9px;font-size:11px">Proof</a>' : '';
      return '<div class="pay-history-row"><span>' + escHtml(PAYMENT_LABELS[p.method] || p.method) + ' — ' + dateFmt(p.paid_date) +
        (p.reference_no ? ' <span class="crumb">(' + escHtml(p.reference_no) + ')</span>' : '') +
        (p.note ? ' <span class="crumb">' + escHtml(p.note) + '</span>' : '') + '</span>' +
        '<span class="amt">' + moneyFmt(p.amount) + proof + ' ' + del + '</span></div>';
    }).join('') + '</div>';
  } else {
    paymentsHtml += '<p class="crumb">No manual payments recorded yet.</p>';
  }
  paymentsHtml += '<div class="pay-balance-bar"><span>Total: <b>' + moneyFmt(o.grand_total) + '</b></span><span>Paid: <b>' + moneyFmt(totalPaid) + '</b></span><span>Balance: <b>' + moneyFmt(balance) + '</b></span></div>';
  paymentsHtml += '</div></div>';

  document.getElementById('orderModalBody').innerHTML =
    '<div class="order-detail-items">' + itemsHtml + '</div>' +
    '<div class="order-detail-subtotal"><span>Subtotal</span><strong>' + moneyFmt(o.subtotal) + '</strong></div>' +
    '<div class="order-detail-meta">' +
      '<div><span>Customer</span><p>' + escHtml(o.full_name) + '<br>' + escHtml(o.email) + '<br>' + escHtml(o.phone) + '</p></div>' +
      '<div><span>Delivery Address</span><p>' + escHtml(o.address).replace(/\n/g,'<br>') + ', ' + escHtml(o.city) + '</p></div>' +
      payHtml +
      trackingHtml +
      (o.notes ? '<div><span>Notes</span><p>' + escHtml(o.notes) + '</p></div>' : '') +
    '</div>' +
    paymentsHtml +
    historyHtml +
    '<div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">' +
      '<a class="mini-btn toggle" target="_blank" href="<?= BASE_URL ?>/admin/courier_slip?id=' + o.id + '"><i class="fa-solid fa-print"></i> Print Courier Slip</a>' +
      (!o.payment_provider ? '<button type="button" class="mini-btn add-val" onclick="closeOrderView(); openPaymentModal(' + o.id + ', ' + JSON.stringify(o.order_no) + ', ' + JSON.stringify(o.payment_method) + ');">Record Payment</button>' : '') +
    '</div>';
  document.getElementById('orderOverlay').classList.add('show');
}
function closeOrderView(){ document.getElementById('orderOverlay').classList.remove('show'); }

function toggleCourierFields(){
  const status = document.getElementById('statusSelect').value;
  const req    = status === 'shipped';
  document.getElementById('courierName').required = req;
  document.getElementById('trackingNo').required   = req;
}

function openStatusModal(id, orderNo, currentStatus, courierName, trackingNo){
  document.getElementById('statusModalTitle').textContent = 'Update Status — ' + orderNo;
  document.getElementById('statusOrderId').value = id;
  document.getElementById('statusSelect').value  = currentStatus;
  document.getElementById('statusDate').value    = new Date().toISOString().slice(0,10);
  document.getElementById('courierName').value   = courierName || '';
  document.getElementById('trackingNo').value    = trackingNo || '';
  toggleCourierFields();
  document.getElementById('statusOverlay').classList.add('show');
}
function closeStatusModal(){ document.getElementById('statusOverlay').classList.remove('show'); }

function openPaymentModal(id, orderNo, method){
  const o = ORDER_DATA[id] || {};
  document.getElementById('paymentModalTitle').textContent = 'Record Payment — ' + orderNo;
  document.getElementById('paymentOrderId').value = id;
  document.getElementById('paymentDate').value    = new Date().toISOString().slice(0,10);
  if (method && method !== 'genie' && document.querySelector('#paymentMethod option[value="' + method + '"]')) {
    document.getElementById('paymentMethod').value = method;
  }
  const totalPaid = ((o.payments || [])).reduce(function(sum, p){ return sum + Number(p.amount); }, 0);
  const balance = Math.max(0, Number(o.grand_total || 0) - totalPaid);
  document.getElementById('paymentAmount').value = balance > 0 ? balance.toFixed(2) : '';
  document.getElementById('paymentBalanceHint').textContent = o.grand_total != null
    ? ('Order total: ' + moneyFmt(o.grand_total) + ' · Already paid: ' + moneyFmt(totalPaid) + ' · Balance due: ' + moneyFmt(balance))
    : '';
  document.getElementById('paymentOverlay').classList.add('show');
}
function closePaymentModal(){ document.getElementById('paymentOverlay').classList.remove('show'); }

<?php if ($openOrderId): ?>
openOrderView(<?= (int)$openOrderId ?>);
<?php endif; ?>
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
