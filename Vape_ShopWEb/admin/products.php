<?php
/**
 * VAPE CO. — admin products management.
 * Vape_ShopWEb / admin / products.php
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
        $productId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
        
        if ($action === 'toggle' && $productId) {
            try {
                $stmt = $pdo->prepare('UPDATE products SET is_active = 1 - is_active WHERE id = ?');
                $stmt->execute([$productId]);
                audit_log('product.toggle', ['product_id' => $productId]);
                flash('Product status updated.', 'success');
                redirect('products.php');
            } catch (Throwable $e) {
                error_log('toggle product failed: ' . $e->getMessage());
                $notice = 'Failed to update product status.';
            }
        } elseif ($action === 'delete' && $productId) {
            try {
                // Soft delete as per plan
                $stmt = $pdo->prepare('UPDATE products SET is_active = 0 WHERE id = ?');
                $stmt->execute([$productId]);
                audit_log('product.delete', ['product_id' => $productId]);
                flash('Product archived.', 'success');
                redirect('products.php');
            } catch (Throwable $e) {
                error_log('delete product failed: ' . $e->getMessage());
                $notice = 'Failed to archive product.';
            }
        }
    }
}

// Filters
$search = trim((string)($_GET['q'] ?? ''));
$categoryFilter = (int)($_GET['category'] ?? 0);

// Get categories
$cats = $pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();

// Build query
$sql = "SELECT p.*, c.name as category_name, 
               (SELECT COUNT(*) FROM product_variants WHERE product_id = p.id) as variant_count,
               (SELECT COALESCE(SUM(stock_qty), 0) FROM product_variants WHERE product_id = p.id) as total_stock
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE 1=1";
$params = [];

if ($search !== '') {
    $sql .= " AND (p.name LIKE ? OR p.brand LIKE ? OR p.slug LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm; $params[] = $searchTerm; $params[] = $searchTerm;
}
if ($categoryFilter > 0) {
    $sql .= " AND p.category_id = ?";
    $params[] = $categoryFilter;
}

$sql .= " ORDER BY p.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$pageTitle = 'Products — ' . APP_NAME . ' admin';
$bodyClass = 'page-admin';
$loadApp   = false;

require_once __DIR__ . '/../includes/head.php';
require_once __DIR__ . '/../includes/admin_chrome.php';
?>
<header class="admin-page-header">
  <div>
    <p class="micro-label">Products</p>
    <h1 class="admin-page-title">Product Management</h1>
  </div>
  <a href="product-edit.php" class="btn btn--accent">Add Product</a>
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
      <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search products..." style="padding:0.5rem 0.75rem;background:var(--surface-2);border:1px solid var(--border);color:var(--text);border-radius:0.375rem;">
      <select name="category" style="padding:0.5rem 0.75rem;background:var(--surface-2);border:1px solid var(--border);color:var(--text);border-radius:0.375rem;">
        <option value="0">All Categories</option>
        <?php foreach ($cats as $cat): ?>
          <option value="<?= (int)$cat['id'] ?>" <?= $categoryFilter == $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn--ghost btn--sm" type="submit">Filter</button>
      <?php if ($search !== '' || $categoryFilter > 0): ?>
        <a href="products.php" class="btn btn--ghost btn--sm">Clear</a>
      <?php endif; ?>
    </form>
  </div>
  
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Product</th>
          <th>Category</th>
          <th>Brand</th>
          <th>Variants</th>
          <th>Stock</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($products as $p): ?>
          <tr>
            <td>
              <div style="font-weight:600;color:var(--text);"><?= e($p['name']) ?></div>
              <div style="font-size:0.75rem;color:var(--text-dim);"><?= e($p['slug']) ?></div>
            </td>
            <td><?= e($p['category_name'] ?? '-') ?></td>
            <td><?= e($p['brand'] ?? '-') ?></td>
            <td><?= (int)$p['variant_count'] ?></td>
            <td><?= (int)$p['total_stock'] ?></td>
            <td>
              <span class="status-chip" style="<?= $p['is_active'] ? 'background:var(--accent-soft);color:var(--accent);' : 'background:var(--surface-2);color:var(--text-dim);' ?>">
                <?= $p['is_active'] ? 'Active' : 'Inactive' ?>
              </span>
            </td>
            <td>
              <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                <a href="product-edit.php?id=<?= (int)$p['id'] ?>" class="btn btn--ghost btn--sm">Edit</a>
                <form method="post" style="display:inline;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                  <button class="btn btn--ghost btn--sm" type="submit"><?= $p['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                </form>
                <form method="post" style="display:inline;" onsubmit="return confirm('Archive this product? It will be removed from the storefront but order history will be preserved.');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                  <button class="btn btn--ghost btn--sm" type="submit" style="color:var(--danger);">Archive</button>
                </form>
              </div>
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
