<?php
/**
 * VAPE CO. — admin orders management.
 * Vape_ShopWEb / admin / orders.php
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

require_admin();

$pdo = db();
$notice = '';
$flash = get_flash();
if ($flash) {
    $notice = $flash['message'];
}

// Filters
$statusFilter = $_GET['status'] ?? '';
$dateFilter = $_GET['date'] ?? '';

// Build query
$sql = "SELECT o.*, u.first_name, u.last_name, u.email,
               (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
        FROM orders o
        LEFT JOIN users u ON u.id = o.user_id
        WHERE 1=1";
$params = [];

if ($statusFilter !== '' && in_array($statusFilter, ['PENDING','PAID','PACKING','SHIPPED','DELIVERED','CANCELLED'])) {
    $sql .= " AND o.status = ?";
    $params[] = $statusFilter;
}
if ($dateFilter === 'today') {
    $sql .= " AND DATE(o.placed_at) = CURDATE()";
} elseif ($dateFilter === 'week') {
    $sql .= " AND YEARWEEK(o.placed_at, 1) = YEARWEEK(CURDATE(), 1)";
} elseif ($dateFilter === 'month') {
    $sql .= " AND MONTH(o.placed_at) = MONTH(CURDATE()) AND YEAR(o.placed_at) = YEAR(CURDATE())";
}

$sql .= " ORDER BY o.placed_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();
$paymentsByOrder = order_payment_rows(array_column($orders, 'id'));

$pageTitle = 'Orders — ' . APP_NAME . ' admin';
$bodyClass = 'page-admin';
$loadApp   = false;

require_once __DIR__ . '/../includes/head.php';
require_once __DIR__ . '/../includes/admin_chrome.php';
?>
<header class="admin-page-header">
  <div>
    <p class="micro-label">Orders</p>
    <h1 class="admin-page-title">Order Management</h1>
  </div>
</header>

<?php if ($notice !== ''): ?>
  <div class="auth__note" role="status" style="margin-bottom:1rem;">
    <svg aria-hidden="true"><use href="#icon-box"></use></svg>
    <span><?= e($notice) ?></span>
  </div>
<?php endif; ?>

<section class="admin-card">
  <div class="admin-card-header">
    <form method="get" style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">
      <select name="status" style="padding:0.5rem 0.75rem;background:var(--surface-2);border:1px solid var(--border);color:var(--text);border-radius:0.375rem;">
        <option value="">All Status</option>
        <?php foreach (['PENDING','PAID','PACKING','SHIPPED','DELIVERED','CANCELLED'] as $st): ?>
          <option value="<?= e($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= e(order_status_label($st)) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="date" style="padding:0.5rem 0.75rem;background:var(--surface-2);border:1px solid var(--border);color:var(--text);border-radius:0.375rem;">
        <option value="">All Time</option>
        <option value="today" <?= $dateFilter === 'today' ? 'selected' : '' ?>>Today</option>
        <option value="week" <?= $dateFilter === 'week' ? 'selected' : '' ?>>This Week</option>
        <option value="month" <?= $dateFilter === 'month' ? 'selected' : '' ?>>This Month</option>
      </select>
      <button class="btn btn--ghost btn--sm" type="submit">Filter</button>
      <?php if ($statusFilter !== '' || $dateFilter !== ''): ?>
        <a href="orders.php" class="btn btn--ghost btn--sm">Clear</a>
      <?php endif; ?>
    </form>
  </div>
  
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Order #</th>
          <th>Customer</th>
          <th>Items</th>
          <th>Total</th>
          <th>Payment</th>
          <th>Status</th>
          <th>Date</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($orders as $order): ?>
          <tr>
            <td><a href="order.php?id=<?= (int)$order['id'] ?>" style="color:var(--accent);font-weight:600;"><?= e($order['order_number']) ?></a></td>
            <td><?= e($order['first_name'].' '.$order['last_name']) ?><br><span style="color:var(--text-dim);font-size:0.75rem;"><?= e($order['email']) ?></span></td>
            <td><?= (int)$order['item_count'] ?></td>
            <td><?= money($order['total']) ?></td>
            <?php $pays = $paymentsByOrder[(int) $order['id']] ?? []; ?>
            <td>
              <span class="pay-summary">
                <?= e($pays ? payment_summary_label($pays) : payment_method_label((string) $order['payment_method'])) ?>
              </span>
            </td>
            <td><span class="status-chip status-chip--<?= strtolower(e($order['status'])) ?>"><?= e(order_status_label($order['status'])) ?></span></td>
            <td><?= date('M d, Y g:i A', strtotime($order['placed_at'])) ?></td>
            <td><a href="order.php?id=<?= (int)$order['id'] ?>" class="btn btn--ghost btn--sm">View</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php 
require_once __DIR__ . '/../includes/admin_chrome_end.php';
require_once __DIR__ . '/../includes/foot.php'; 
?>
