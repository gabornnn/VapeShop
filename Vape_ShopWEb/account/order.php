<?php
/**
 * VAPE CO. — customer order detail.
 * Vape_ShopWEb / account / order.php
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

require_login('login.php');

$user = current_user();
$pdo = db();
$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$orderId, (int)$user['id']]);
$order = $stmt->fetch();

if (!$order) {
    flash('Order not found.', 'error');
    redirect('orders.php');
}

$istmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
$istmt->execute([$orderId]);
$items = $istmt->fetchAll();

$hstmt = $pdo->prepare('SELECT * FROM order_status_history WHERE order_id = ? ORDER BY changed_at ASC, id ASC');
$hstmt->execute([$orderId]);
$history = $hstmt->fetchAll();

$pageTitle = 'Order ' . $order['order_number'] . ' — ' . APP_NAME;
$bodyClass = 'page-account';
$loadApp   = false;

require_once __DIR__ . '/../includes/head.php';
?>
<div class="account">
  <div class="account__container">
    <header class="account__header">
      <div>
        <p class="micro-label">My Account</p>
        <h1 class="account__title">Order Details</h1>
        <p style="color:#8b95a3;margin-top:0.5rem;"><?= e($order['order_number']) ?></p>
      </div>
      <nav class="account__nav">
        <a class="account__nav-link" href="index.php">Profile</a>
        <a class="account__nav-link is-active" href="orders.php">Orders</a>
        <a class="account__nav-link" href="addresses.php">Addresses</a>
        <a class="account__nav-link account__nav-link--danger" href="../logout.php">Sign out</a>
      </nav>
    </header>

    <div style="margin-bottom:1.5rem;">
      <a href="orders.php" class="btn btn--ghost btn--sm">← Back to Orders</a>
    </div>

    <div class="account__grid" style="grid-template-columns:2fr 1fr;">
      <section class="account__card">
        <h2 class="account__card-title">Items</h2>
        <div class="order-items">
          <?php foreach ($items as $item): ?>
            <div class="order-items__row">
              <div class="order-items__info">
                <p class="order-items__name"><?= e($item['product_name']) ?></p>
                <p class="order-items__meta"><?= e($item['strength']) ?> · <?= e($item['flavor']) ?> · SKU: <?= e($item['sku']) ?></p>
              </div>
              <div class="order-items__qty">×<?= (int)$item['qty'] ?></div>
              <div class="order-items__price"><?= money($item['line_total']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
        
        <div class="totals" style="margin-top:1.5rem;">
          <div class="totals__row"><span>Subtotal</span><span><?= money($order['subtotal']) ?></span></div>
          <div class="totals__row"><span>VAT (<?= ($order['vat_rate']*100) ?>%)</span><span><?= money($order['vat_amount']) ?></span></div>
          <div class="totals__row"><span>Shipping</span><span><?= $order['shipping_fee'] == 0 ? 'FREE' : money($order['shipping_fee']) ?></span></div>
          <div class="totals__row totals__row--grand"><span>Total</span><span><?= money($order['total']) ?></span></div>
        </div>
      </section>

      <div>
        <section class="account__card" style="margin-bottom:1.5rem;">
          <h2 class="account__card-title">Order Status</h2>
          <div class="status-chip status-chip--<?= strtolower(e($order['status'])) ?>" style="margin-bottom:1rem;"><?= e($order['status']) ?></div>
          <p style="color:#8b95a3;font-size:0.875rem;">Placed on <?= date('M d, Y g:i A', strtotime($order['created_at'])) ?></p>
          <p style="color:#8b95a3;font-size:0.875rem;margin-top:0.5rem;">Payment: <?= e($order['payment_method']) ?></p>
        </section>

        <section class="account__card" style="margin-bottom:1.5rem;">
          <h2 class="account__card-title">Shipping Address</h2>
          <p style="color:#e8edf2;line-height:1.6;">
            <?= e($order['ship_to_name']) ?><br>
            <?= e($order['ship_to_phone']) ?><br>
            <?= nl2br(e($order['ship_to_address'])) ?>
            <?php if ($order['ship_to_notes']): ?><br><br><em><?= nl2br(e($order['ship_to_notes'])) ?></em><?php endif; ?>
          </p>
        </section>

        <section class="account__card">
          <h2 class="account__card-title">Status Timeline</h2>
          <div class="timeline">
            <?php foreach ($history as $h): ?>
              <div class="timeline__item">
                <div class="timeline__dot"></div>
                <div class="timeline__content">
                  <p class="timeline__status"><?= e($h['to_status']) ?></p>
                  <?php if ($h['note']): ?><p class="timeline__note"><?= e($h['note']) ?></p><?php endif; ?>
                  <p class="timeline__time"><?= date('M d, Y g:i A', strtotime($h['changed_at'])) ?></p>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/foot.php'; ?>
