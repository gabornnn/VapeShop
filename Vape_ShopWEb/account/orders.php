<?php
/**
 * VAPE CO. — customer orders list.
 * Vape_ShopWEb / account / orders.php
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

require_login('login.php');

$user = current_user();
$pdo = db();

$stmt = $pdo->prepare('SELECT o.*, (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count FROM orders o WHERE o.user_id = ? ORDER BY o.placed_at DESC');
$stmt->execute([(int)$user['id']]);
$orders = $stmt->fetchAll();

$pageTitle = 'My Orders — ' . APP_NAME;
$bodyClass = 'page-account';
$loadApp   = false;

require_once __DIR__ . '/../includes/head.php';
?>
<div class="account">
  <div class="account__container">
    <header class="account__header">
      <div>
        <p class="micro-label">My Account</p>
        <h1 class="account__title">Orders</h1>
      </div>
      <nav class="account__nav">
        <a class="account__nav-link" href="index.php">Profile</a>
        <a class="account__nav-link is-active" href="orders.php">Orders</a>
        <a class="account__nav-link" href="addresses.php">Addresses</a>
        <a class="account__nav-link account__nav-link--danger" href="../logout.php">Sign out</a>
      </nav>
    </header>

    <?php if (empty($orders)): ?>
      <div class="account__card">
        <p style="text-align:center;color:var(--text-dim);margin:2rem;">You haven't placed any orders yet.</p>
        <p style="text-align:center;"><a href="../index.php" class="btn btn--accent">Start Shopping</a></p>
      </div>
    <?php else: ?>
      <div class="account__card">
        <div class="orders-list">
          <?php foreach ($orders as $order): ?>
            <a href="order.php?id=<?= (int)$order['id'] ?>" class="order-item">
              <div class="order-item__main">
                <div class="order-item__header">
                  <span class="order-item__number"><?= e($order['order_number']) ?></span>
                  <span class="status-chip status-chip--<?= strtolower(e($order['status'])) ?>"><?= e(order_status_label($order['status'])) ?></span>
                </div>
                <p class="order-item__meta">
                  <?= date('M d, Y g:i A', strtotime($order['placed_at'])) ?> · 
                  <?= (int)$order['item_count'] ?> item<?= (int)$order['item_count'] === 1 ? '' : 's' ?>
                </p>
              </div>
              <div class="order-item__total">
                <span><?= money($order['total']) ?></span>
                <svg aria-hidden="true" width="16" height="16"><use href="#icon-arrow-left" style="transform:rotate(180deg)"></use></svg>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/foot.php'; ?>
