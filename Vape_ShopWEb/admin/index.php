<?php
/**
 * VAPE CO. — admin dashboard.
 * Vape_ShopWEb / admin / index.php
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

require_admin();

$admin = current_user();
$pdo = db();

// Set timezone for "today" calculations
$pdo->exec("SET time_zone = '+08:00'");

// Total revenue (non-cancelled)
$stmt = $pdo->query("SELECT COALESCE(SUM(total), 0) as revenue FROM orders WHERE status != 'CANCELLED'");
$totalRevenue = $stmt->fetchColumn();

// Orders today
$stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()");
$ordersToday = $stmt->fetchColumn();

// Orders this week
$stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)");
$ordersWeek = $stmt->fetchColumn();

// Orders by status
$stmt = $pdo->query("SELECT status, COUNT(*) as cnt FROM orders GROUP BY status");
$statusCounts = [];
foreach ($stmt as $row) {
    $statusCounts[$row['status']] = (int)$row['cnt'];
}

// Total customers
$stmt = $pdo->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.role_name = 'customer'");
$totalCustomers = $stmt->fetchColumn();

// New customers this month
$stmt = $pdo->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.role_name = 'customer' AND MONTH(u.created_at) = MONTH(CURDATE()) AND YEAR(u.created_at) = YEAR(CURDATE())");
$newCustomersMonth = $stmt->fetchColumn();

// Top products
$stmt = $pdo->query("
    SELECT oi.product_name, SUM(oi.qty) as units_sold, SUM(oi.line_total) as revenue
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    WHERE o.status != 'CANCELLED'
    GROUP BY oi.product_id, oi.product_name
    ORDER BY units_sold DESC
    LIMIT 10
");
$topProducts = $stmt->fetchAll();

// Low stock
$stmt = $pdo->query("
    SELECT v.id, v.sku, v.strength, v.flavor, v.stock_qty, p.name as product_name
    FROM product_variants v
    JOIN products p ON p.id = v.product_id
    WHERE v.stock_qty <= 5 AND v.is_active = 1 AND p.is_active = 1
    ORDER BY v.stock_qty ASC, p.name
    LIMIT 20
");
$lowStock = $stmt->fetchAll();

// Recent orders
$stmt = $pdo->query("
    SELECT o.*, u.first_name, u.last_name, u.email,
           (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
    FROM orders o
    LEFT JOIN users u ON u.id = o.user_id
    ORDER BY o.created_at DESC
    LIMIT 10
");
$recentOrders = $stmt->fetchAll();

$pageTitle = 'Dashboard — ' . APP_NAME . ' admin';
$bodyClass = 'page-admin';
$metaDescription = 'Staff dashboard.';
$loadApp   = false;

require_once __DIR__ . '/../includes/head.php';
require_once __DIR__ . '/../includes/admin_chrome.php';
?>
<header class="admin-page-header">
  <div>
    <p class="micro-label">Dashboard</p>
    <h1 class="admin-page-title">Overview</h1>
  </div>
</header>

<div class="admin-stats">
  <div class="admin-stat-card">
    <p class="admin-stat-label">Total Revenue</p>
    <p class="admin-stat-value"><?= money($totalRevenue) ?></p>
    <p class="admin-stat-meta">Excluding cancelled</p>
  </div>
  <div class="admin-stat-card">
    <p class="admin-stat-label">Orders Today</p>
    <p class="admin-stat-value"><?= number_format($ordersToday) ?></p>
    <p class="admin-stat-meta">Philippine Time</p>
  </div>
  <div class="admin-stat-card">
    <p class="admin-stat-label">Orders This Week</p>
    <p class="admin-stat-value"><?= number_format($ordersWeek) ?></p>
  </div>
  <div class="admin-stat-card">
    <p class="admin-stat-label">Total Customers</p>
    <p class="admin-stat-value"><?= number_format($totalCustomers) ?></p>
    <p class="admin-stat-meta"><?= number_format($newCustomersMonth) ?> new this month</p>
  </div>
</div>

<div class="admin-grid">
  <section class="admin-card" style="grid-column:span 2;">
    <div class="admin-card-header">
      <h2 class="admin-card-title">Recent Orders</h2>
      <a href="orders.php" class="btn btn--ghost btn--sm">View all</a>
    </div>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Order</th>
            <th>Customer</th>
            <th>Items</th>
            <th>Total</th>
            <th>Status</th>
            <th>Date</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentOrders as $order): ?>
            <tr>
              <td><a href="order.php?id=<?= (int)$order['id'] ?>" style="color:#00f0ff;"><?= e($order['order_number']) ?></a></td>
              <td><?= e($order['first_name'].' '.$order['last_name']) ?><br><span style="color:#8b95a3;font-size:0.75rem;"><?= e($order['email']) ?></span></td>
              <td><?= (int)$order['item_count'] ?></td>
              <td><?= money($order['total']) ?></td>
              <td><span class="status-chip status-chip--<?= strtolower(e($order['status'])) ?>"><?= e($order['status']) ?></span></td>
              <td><?= date('M d, Y g:i A', strtotime($order['created_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="admin-card">
    <div class="admin-card-header">
      <h2 class="admin-card-title">Orders by Status</h2>
    </div>
    <div style="padding:1rem;display:flex;flex-direction:column;gap:0.75rem;">
      <?php foreach (['PENDING','CONFIRMED','PROCESSING','SHIPPED','DELIVERED','CANCELLED'] as $st): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <span class="status-chip status-chip--<?= strtolower($st) ?>"><?= $st ?></span>
          <span style="color:#e8edf2;font-weight:600;"><?= isset($statusCounts[$st]) ? $statusCounts[$st] : 0 ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
</div>

<div class="admin-grid" style="margin-top:1.5rem;">
  <section class="admin-card">
    <div class="admin-card-header">
      <h2 class="admin-card-title">Top Products</h2>
    </div>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Product</th>
            <th>Units Sold</th>
            <th>Revenue</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($topProducts as $prod): ?>
            <tr>
              <td><?= e($prod['product_name']) ?></td>
              <td><?= (int)$prod['units_sold'] ?></td>
              <td><?= money($prod['revenue']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="admin-card">
    <div class="admin-card-header">
      <h2 class="admin-card-title">Low Stock (≤ 5)</h2>
    </div>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Product</th>
            <th>Variant</th>
            <th>SKU</th>
            <th>Stock</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($lowStock)): ?>
            <tr><td colspan="4" style="text-align:center;color:#8b95a3;">All well stocked</td></tr>
          <?php else: ?>
            <?php foreach ($lowStock as $ls): ?>
              <tr>
                <td><?= e($ls['product_name']) ?></td>
                <td><?= e($ls['strength']) ?> · <?= e($ls['flavor']) ?></td>
                <td><?= e($ls['sku']) ?></td>
                <td style="color:<?= $ls['stock_qty'] == 0 ? '#ff6b6b' : '#ffa94d' ?>;font-weight:600;"><?= (int)$ls['stock_qty'] ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<?php 
require_once __DIR__ . '/../includes/admin_chrome_end.php';
require_once __DIR__ . '/../includes/foot.php'; 
?>
