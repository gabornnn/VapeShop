<?php
/**
 * VAPE CO. — admin order detail.
 * Vape_ShopWEb / admin / order.php
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

require_admin();

$pdo = db();
$admin = current_user();
$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$notice = '';
$flash = get_flash();
if ($flash) {
    $notice = $flash['message'];
}

// Handle status update
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify(csrf_submitted_token())) {
        $notice = 'Session expired. Please try again.';
    } else {
        $newStatus = $_POST['status'] ?? '';
        $note = trim((string)($_POST['note'] ?? ''));
        if (in_array($newStatus, ['PENDING','CONFIRMED','PROCESSING','SHIPPED','DELIVERED','CANCELLED'])) {
            try {
                $pdo->beginTransaction();
                // Get current status
                $stmt = $pdo->prepare('SELECT status FROM orders WHERE id = ? FOR UPDATE');
                $stmt->execute([$orderId]);
                $currentStatus = $stmt->fetchColumn();
                
                if ($currentStatus !== $newStatus) {
                    // Update order
                    $ustmt = $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?');
                    $ustmt->execute([$newStatus, $orderId]);
                    
                    // Add to history
                    $hstmt = $pdo->prepare('INSERT INTO order_status_history (order_id, from_status, to_status, changed_by, note) VALUES (?, ?, ?, ?, ?)');
                    $hstmt->execute([$orderId, $currentStatus, $newStatus, (int)$admin['id'], $note ?: null]);
                    
                    audit_log('order.status_update', ['order_id' => $orderId, 'from' => $currentStatus, 'to' => $newStatus]);
                    
                    $pdo->commit();
                    flash('Order status updated.', 'success');
                    redirect('order.php?id=' . $orderId);
                } else {
                    $pdo->rollBack();
                    $notice = 'Status unchanged.';
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('status update failed: ' . $e->getMessage());
                $notice = 'Failed to update status.';
            }
        }
    }
}

// Load order
$stmt = $pdo->prepare('SELECT o.*, u.first_name, u.last_name, u.email, u.phone as user_phone FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE o.id = ?');
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
    flash('Order not found.', 'error');
    redirect('orders.php');
}

$istmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
$istmt->execute([$orderId]);
$items = $istmt->fetchAll();

$hstmt = $pdo->prepare('SELECT h.*, a.first_name, a.last_name FROM order_status_history h LEFT JOIN users a ON a.id = h.changed_by WHERE h.order_id = ? ORDER BY h.changed_at ASC, h.id ASC');
$hstmt->execute([$orderId]);
$history = $hstmt->fetchAll();

$pageTitle = 'Order ' . $order['order_number'] . ' — ' . APP_NAME . ' admin';
$bodyClass = 'page-admin';
$loadApp   = false;

require_once __DIR__ . '/../includes/head.php';
require_once __DIR__ . '/../includes/admin_chrome.php';
?>
<header class="admin-page-header">
  <div>
    <p class="micro-label">Orders</p>
    <h1 class="admin-page-title"><?= e($order['order_number']) ?></h1>
  </div>
  <a href="orders.php" class="btn btn--ghost">Back to Orders</a>
</header>

<?php if ($notice !== ''): ?>
  <div class="auth__note" role="status" style="margin-bottom:1rem;">
    <svg aria-hidden="true"><use href="#icon-box"></use></svg>
    <span><?= e($notice) ?></span>
  </div>
<?php endif; ?>

<div class="admin-grid" style="align-items:start;">
  <div style="display:flex;flex-direction:column;gap:1.5rem;">
    <section class="admin-card">
      <div class="admin-card-header">
        <h2 class="admin-card-title">Order Items</h2>
      </div>
      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th>Product</th>
              <th>SKU</th>
              <th>Variant</th>
              <th>Price</th>
              <th>Qty</th>
              <th>Total</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $item): ?>
              <tr>
                <td><?= e($item['product_name']) ?></td>
                <td><?= e($item['sku']) ?></td>
                <td><?= e($item['strength']) ?> · <?= e($item['flavor']) ?></td>
                <td><?= money($item['unit_price']) ?></td>
                <td><?= (int)$item['qty'] ?></td>
                <td><?= money($item['line_total']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="padding:1rem;display:flex;flex-direction:column;gap:0.5rem;max-width:300px;margin-left:auto;">
        <div style="display:flex;justify-content:space-between;"><span style="color:#8b95a3;">Subtotal</span><span><?= money($order['subtotal']) ?></span></div>
        <div style="display:flex;justify-content:space-between;"><span style="color:#8b95a3;">VAT (<?= ($order['vat_rate']*100) ?>%)</span><span><?= money($order['vat_amount']) ?></span></div>
        <div style="display:flex;justify-content:space-between;"><span style="color:#8b95a3;">Shipping</span><span><?= $order['shipping_fee'] == 0 ? 'FREE' : money($order['shipping_fee']) ?></span></div>
        <div style="display:flex;justify-content:space-between;padding-top:0.75rem;border-top:1px solid var(--border);font-weight:600;font-size:1.125rem;"><span>Total</span><span><?= money($order['total']) ?></span></div>
      </div>
    </section>

    <section class="admin-card">
      <div class="admin-card-header">
        <h2 class="admin-card-title">Status Timeline</h2>
      </div>
      <div style="padding:1.25rem;">
        <div class="timeline">
          <?php foreach ($history as $h): ?>
            <div class="timeline__item">
              <div class="timeline__dot"></div>
              <div class="timeline__content">
                <p class="timeline__status"><?= e($h['to_status']) ?></p>
                <?php if ($h['note']): ?><p class="timeline__note"><?= e($h['note']) ?></p><?php endif; ?>
                <?php if ($h['first_name']): ?><p class="timeline__note">Updated by <?= e($h['first_name'].' '.$h['last_name']) ?></p><?php endif; ?>
                <p class="timeline__time"><?= date('M d, Y g:i A', strtotime($h['changed_at'])) ?></p>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
  </div>

  <div style="display:flex;flex-direction:column;gap:1.5rem;">
    <section class="admin-card">
      <div class="admin-card-header">
        <h2 class="admin-card-title">Update Status</h2>
      </div>
      <form class="form-grid" method="post" style="padding:1.25rem;">
        <?= csrf_field() ?>
        <div class="field">
          <label class="field__label" for="status">Status</label>
          <select id="status" name="status">
            <?php foreach (['PENDING','CONFIRMED','PROCESSING','SHIPPED','DELIVERED','CANCELLED'] as $st): ?>
              <option value="<?= $st ?>" <?= $order['status'] === $st ? 'selected' : '' ?>><?= $st ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="field__label" for="note">Note (optional)</label>
          <input id="note" name="note" type="text" placeholder="e.g., Tracking number added">
        </div>
        <button class="btn btn--accent" type="submit">Update Status</button>
      </form>
    </section>

    <section class="admin-card">
      <div class="admin-card-header">
        <h2 class="admin-card-title">Customer</h2>
      </div>
      <div style="padding:1.25rem;display:flex;flex-direction:column;gap:0.5rem;">
        <p style="color:#e8edf2;font-weight:600;"><?= e($order['first_name'].' '.$order['last_name']) ?></p>
        <p style="color:#8b95a3;font-size:0.875rem;"><?= e($order['email']) ?></p>
        <p style="color:#8b95a3;font-size:0.875rem;"><?= e($order['user_phone'] ?: $order['ship_to_phone']) ?></p>
      </div>
    </section>

    <section class="admin-card">
      <div class="admin-card-header">
        <h2 class="admin-card-title">Shipping Address</h2>
      </div>
      <div style="padding:1.25rem;">
        <p style="color:#e8edf2;line-height:1.6;">
          <?= e($order['ship_to_name']) ?><br>
          <?= e($order['ship_to_phone']) ?><br>
          <?= nl2br(e($order['ship_to_address'])) ?>
          <?php if ($order['ship_to_notes']): ?><br><br><em style="color:#8b95a3;"><?= nl2br(e($order['ship_to_notes'])) ?></em><?php endif; ?>
        </p>
      </div>
    </section>

    <section class="admin-card">
      <div class="admin-card-header">
        <h2 class="admin-card-title">Order Info</h2>
      </div>
      <div style="padding:1.25rem;display:flex;flex-direction:column;gap:0.5rem;">
        <div style="display:flex;justify-content:space-between;"><span style="color:#8b95a3;">Order Date</span><span><?= date('M d, Y g:i A', strtotime($order['created_at'])) ?></span></div>
        <div style="display:flex;justify-content:space-between;"><span style="color:#8b95a3;">Payment Method</span><span><?= e($order['payment_method']) ?></span></div>
        <div style="display:flex;justify-content:space-between;"><span style="color:#8b95a3;">Status</span><span class="status-chip status-chip--<?= strtolower(e($order['status'])) ?>"><?= e($order['status']) ?></span></div>
      </div>
    </section>
  </div>
</div>

<?php 
require_once __DIR__ . '/../includes/admin_chrome_end.php';
require_once __DIR__ . '/../includes/foot.php'; 
?>
