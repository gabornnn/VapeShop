<?php
/**
 * VAPE CO. — admin customers management.
 * Vape_ShopWEb / admin / customers.php
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

// Handle actions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify(csrf_submitted_token())) {
        $notice = 'Session expired. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
        
        if ($action === 'toggle_block' && $userId) {
            try {
                $stmt = $pdo->prepare('SELECT is_active, blocked_reason FROM users WHERE id = ?');
                $stmt->execute([$userId]);
                $u = $stmt->fetch();
                if ($u) {
                    if ((int)$u['is_active'] === 1) {
                        $reason = trim((string)($_POST['reason'] ?? ''));
                        $stmt = $pdo->prepare('UPDATE users SET is_active = 0, blocked_reason = ?, blocked_at = NOW() WHERE id = ?');
                        $stmt->execute([$reason ?: null, $userId]);
                        audit_log('user.block', ['user_id' => $userId, 'reason' => $reason]);
                        flash('Customer blocked.', 'success');
                    } else {
                        $stmt = $pdo->prepare('UPDATE users SET is_active = 1, blocked_reason = NULL, blocked_at = NULL WHERE id = ?');
                        $stmt->execute([$userId]);
                        audit_log('user.unblock', ['user_id' => $userId]);
                        flash('Customer unblocked.', 'success');
                    }
                }
                redirect('customers.php');
            } catch (Throwable $e) {
                error_log('toggle block failed: ' . $e->getMessage());
                $notice = 'Failed to update customer status.';
            }
        }
    }
}

// Search
$search = trim((string)($_GET['q'] ?? ''));

// Build query
$sql = "SELECT u.*, r.role_name,
               (SELECT COUNT(*) FROM orders WHERE user_id = u.id) as order_count,
               (SELECT COALESCE(SUM(total), 0) FROM orders WHERE user_id = u.id AND status != 'CANCELLED') as total_spent
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE r.role_name = 'customer'";
$params = [];

if ($search !== '') {
    $sql .= " AND (u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm; $params[] = $searchTerm; $params[] = $searchTerm;
}

$sql .= " ORDER BY u.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

$pageTitle = 'Customers — ' . APP_NAME . ' admin';
$bodyClass = 'page-admin';
$loadApp   = false;

require_once __DIR__ . '/../includes/head.php';
require_once __DIR__ . '/../includes/admin_chrome.php';
?>
<header class="admin-page-header">
  <div>
    <p class="micro-label">Customers</p>
    <h1 class="admin-page-title">Customer Management</h1>
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
    <form method="get" style="display:flex;gap:0.75rem;align-items:center;">
      <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search customers..." style="padding:0.5rem 0.75rem;background:var(--surface-2);border:1px solid var(--border);color:var(--text);border-radius:0.375rem;">
      <button class="btn btn--ghost btn--sm" type="submit">Search</button>
      <?php if ($search !== ''): ?>
        <a href="customers.php" class="btn btn--ghost btn--sm">Clear</a>
      <?php endif; ?>
    </form>
  </div>
  
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Customer</th>
          <th>Email</th>
          <th>Phone</th>
          <th>Orders</th>
          <th>Total Spent</th>
          <th>Joined</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($customers as $cust): ?>
          <tr>
            <td style="font-weight:600;color:var(--text);"><?= e($cust['first_name'].' '.$cust['last_name']) ?></td>
            <td><?= e($cust['email']) ?></td>
            <td><?= e($cust['phone']) ?></td>
            <td><?= (int)$cust['order_count'] ?></td>
            <td><?= money($cust['total_spent']) ?></td>
            <td><?= date('M d, Y', strtotime($cust['created_at'])) ?></td>
            <td>
              <?php if ((int)$cust['is_active'] === 1): ?>
                <span class="status-chip" style="background:var(--accent-soft);color:var(--accent);">Active</span>
              <?php else: ?>
                <span class="status-chip" style="background:var(--danger-soft);color:var(--danger);">Blocked</span>
                <?php if ($cust['blocked_reason']): ?><br><span style="font-size:0.75rem;color:var(--text-dim);"><?= e($cust['blocked_reason']) ?></span><?php endif; ?>
              <?php endif; ?>
            </td>
            <td>
              <form method="post" style="display:inline;" onsubmit="return <?= (int)$cust['is_active'] === 1 ? 'confirm(\'Block this customer?\');' : 'confirm(\'Unblock this customer?\');' ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_block">
                <input type="hidden" name="user_id" value="<?= (int)$cust['id'] ?>">
                <?php if ((int)$cust['is_active'] === 1): ?>
                  <input type="text" name="reason" placeholder="Reason (optional)" style="padding:0.25rem 0.5rem;background:var(--surface-2);border:1px solid var(--border);color:var(--text);border-radius:0.25rem;width:120px;margin-right:0.25rem;">
                <?php endif; ?>
                <button class="btn btn--ghost btn--sm" type="submit" style="<?= (int)$cust['is_active'] === 1 ? 'color:var(--danger);' : '' ?>"><?= (int)$cust['is_active'] === 1 ? 'Block' : 'Unblock' ?></button>
              </form>
            </td>
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
