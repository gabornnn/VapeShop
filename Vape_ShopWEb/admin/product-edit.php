<?php
/**
 * VAPE CO. — admin product create/edit.
 * Vape_ShopWEb / admin / product-edit.php
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

require_admin();

$pdo = db();
$productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $productId > 0;
$errors = [];
$notice = '';

// Get categories
$cats = $pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();

// Load product if editing
$product = null;
$variants = [];
if ($isEdit) {
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([$productId]);
    $product = $stmt->fetch();
    if (!$product) {
        flash('Product not found.', 'error');
        redirect('products.php');
    }
    $vstmt = $pdo->prepare('SELECT * FROM product_variants WHERE product_id = ? ORDER BY strength, flavor');
    $vstmt->execute([$productId]);
    $variants = $vstmt->fetchAll();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify(csrf_submitted_token())) {
        $errors['form'] = 'Session expired. Please try again.';
    } else {
        $action = $_POST['action'] ?? 'save';
        
        if ($action === 'generate_variants') {
            // Generate variants from options
            $strengthsRaw = trim((string)($_POST['strengths'] ?? ''));
            $flavorsRaw = trim((string)($_POST['flavors'] ?? ''));
            
            $strengths = array_filter(array_map('trim', preg_split('/[\n,]+/', $strengthsRaw)));
            $flavors = array_filter(array_map('trim', preg_split('/[\n,]+/', $flavorsRaw)));
            
            if (empty($strengths) || empty($flavors)) {
                $errors['form'] = 'Provide at least one strength and one flavor.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $slug = $product['slug'] ?? slugify($_POST['name'] ?? '');
                    $count = 0;
                    
                    foreach ($strengths as $str) {
                        foreach ($flavors as $flav) {
                            $sku = generate_variant_sku($slug, $str, $flav, $productId);
                            // Upsert on (product_id, strength, flavor)
                            $stmt = $pdo->prepare('INSERT INTO product_variants (product_id, strength, flavor, sku, price_adjustment, stock_qty, is_active) VALUES (?, ?, ?, ?, 0, 0, 1) ON DUPLICATE KEY UPDATE sku = VALUES(sku)');
                            $stmt->execute([$productId, $str, $flav, $sku]);
                            if ($stmt->rowCount() > 0) $count++;
                        }
                    }
                    
                    $pdo->commit();
                    audit_log('variants.generate', ['product_id' => $productId, 'count' => $count]);
                    flash("Generated/updated variants from options.", 'success');
                    redirect('product-edit.php?id=' . $productId);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('generate variants failed: ' . $e->getMessage());
                    $errors['form'] = 'Failed to generate variants: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'save_variants') {
            try {
                $pdo->beginTransaction();
                $variantData = $_POST['variants'] ?? [];
                foreach ($variantData as $vid => $data) {
                    $vid = (int)$vid;
                    if ($vid < 0) {
                        // New variant
                        $slug = $product['slug'] ?? '';
                        $str = trim($data['strength']);
                        $flav = trim($data['flavor']);
                        $sku = $data['sku'] !== '' ? $data['sku'] : generate_variant_sku($slug, $str, $flav, $productId);
                        $stmt = $pdo->prepare('INSERT INTO product_variants (product_id, strength, flavor, sku, price_adjustment, stock_qty, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)');
                        $stmt->execute([$productId, $str, $flav, $sku, (float)($data['price_adjustment'] ?? 0), (int)($data['stock_qty'] ?? 0)]);
                    } else {
                        $stmt = $pdo->prepare('UPDATE product_variants SET strength=?, flavor=?, sku=?, price_adjustment=?, stock_qty=?, is_active=? WHERE id=? AND product_id=?');
                        $stmt->execute([
                            trim($data['strength']),
                            trim($data['flavor']),
                            trim($data['sku']),
                            (float)($data['price_adjustment'] ?? 0),
                            (int)($data['stock_qty'] ?? 0),
                            isset($data['is_active']) ? 1 : 0,
                            $vid,
                            $productId
                        ]);
                    }
                }
                $pdo->commit();
                audit_log('variants.save', ['product_id' => $productId]);
                flash('Variants updated.', 'success');
                redirect('product-edit.php?id=' . $productId);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('save variants failed: ' . $e->getMessage());
                $errors['form'] = 'Failed to save variants.';
            }
        } else {
            // Save product
            $name = trim((string)($_POST['name'] ?? ''));
            $slug = trim((string)($_POST['slug'] ?? ''));
            $brand = trim((string)($_POST['brand'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $basePrice = (float)($_POST['base_price'] ?? 0);
            $puffs = trim((string)($_POST['puffs'] ?? ''));
            $imageUrl = trim((string)($_POST['image_url'] ?? ''));
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $isFeatured = isset($_POST['is_featured']) ? 1 : 0;
            
            if ($name === '') $errors['name'] = 'Name is required.';
            if ($slug === '') $slug = slugify($name);
            if ($categoryId === 0) $errors['category_id'] = 'Category is required.';
            if ($basePrice <= 0) $errors['base_price'] = 'Price must be greater than 0.';
            
            if (!$errors) {
                try {
                    $pdo->beginTransaction();
                    if ($isEdit) {
                        $stmt = $pdo->prepare('UPDATE products SET category_id=?, name=?, slug=?, brand=?, description=?, base_price=?, puffs=?, image_url=?, is_active=?, is_featured=? WHERE id=?');
                        $stmt->execute([$categoryId, $name, $slug, $brand ?: null, $description ?: null, $basePrice, $puffs ?: null, $imageUrl ?: null, $isActive, $isFeatured, $productId]);
                        audit_log('product.update', ['product_id' => $productId]);
                    } else {
                        $stmt = $pdo->prepare('INSERT INTO products (category_id, name, slug, brand, description, base_price, puffs, image_url, is_active, is_featured) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                        $stmt->execute([$categoryId, $name, $slug, $brand ?: null, $description ?: null, $basePrice, $puffs ?: null, $imageUrl ?: null, $isActive, $isFeatured]);
                        $productId = (int)$pdo->lastInsertId();
                        audit_log('product.create', ['product_id' => $productId]);
                    }
                    $pdo->commit();
                    flash('Product saved.', 'success');
                    redirect('product-edit.php?id=' . $productId);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('save product failed: ' . $e->getMessage());
                    $errors['form'] = 'Failed to save product.';
                }
            }
        }
    }
}

// Helper function
function generate_variant_sku($productSlug, $strength, $flavor, $productId) {
    $s = strtoupper(substr(preg_replace('/[^A-Za-z0-9]+/', '', $strength), 0, 10));
    $f = strtoupper(substr(preg_replace('/[^A-Za-z0-9]+/', '', $flavor), 0, 10));
    $p = strtoupper(substr(preg_replace('/[^A-Za-z0-9]+/', '', $productSlug), 0, 15));
    $sku = $p . '-' . $s . '-' . $f;
    if (strlen($sku) > 40) $sku = substr($sku, 0, 40);
    
    $pdo = db();
    $baseSku = $sku;
    $suffix = 1;
    while (true) {
        $stmt = $pdo->prepare('SELECT id FROM product_variants WHERE sku = ? AND product_id != ?');
        $stmt->execute([$sku, $productId]);
        if (!$stmt->fetch()) break;
        $sku = substr($baseSku, 0, 37) . '-' . str_pad($suffix, 3, '0', STR_PAD_LEFT);
        $suffix++;
    }
    return $sku;
}

$pageTitle = ($isEdit ? 'Edit' : 'Add') . ' Product — ' . APP_NAME . ' admin';
$bodyClass = 'page-admin';
$loadApp   = false;

require_once __DIR__ . '/../includes/head.php';
require_once __DIR__ . '/../includes/admin_chrome.php';
?>
<header class="admin-page-header">
  <div>
    <p class="micro-label">Products</p>
    <h1 class="admin-page-title"><?= $isEdit ? 'Edit Product' : 'Add Product' ?></h1>
  </div>
  <a href="products.php" class="btn btn--ghost">Back to Products</a>
</header>

<?php if ($notice !== ''): ?>
  <div class="auth__note" role="status" style="margin-bottom:1rem;">
    <svg aria-hidden="true"><use href="#icon-box"></use></svg>
    <span><?= e($notice) ?></span>
  </div>
<?php endif; ?>
<?php if (!empty($errors['form'])): ?>
  <div class="auth__alert" role="alert" style="margin-bottom:1rem;">
    <svg aria-hidden="true"><use href="#icon-close"></use></svg>
    <span><?= e($errors['form']) ?></span>
  </div>
<?php endif; ?>

<div class="admin-grid" style="align-items:start;">
  <section class="admin-card">
    <div class="admin-card-header">
      <h2 class="admin-card-title">Product Details</h2>
    </div>
    <form class="form-grid" method="post" style="padding:1.25rem;" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      
      <div class="field<?= isset($errors['name']) ? ' has-error' : '' ?>">
        <label class="field__label" for="name">Product Name <span class="req">*</span></label>
        <input id="name" name="name" type="text" value="<?= e($product['name'] ?? '') ?>" required oninput="document.getElementById('slug').value = this.value.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'')">
        <span class="field__error"<?= isset($errors['name']) ? '' : ' hidden' ?>><?= e($errors['name'] ?? '') ?></span>
      </div>
      
      <div class="form-grid form-grid--two">
        <div class="field">
          <label class="field__label" for="slug">Slug</label>
          <input id="slug" name="slug" type="text" value="<?= e($product['slug'] ?? '') ?>">
        </div>
        <div class="field">
          <label class="field__label" for="brand">Brand</label>
          <input id="brand" name="brand" type="text" value="<?= e($product['brand'] ?? '') ?>">
        </div>
      </div>
      
      <div class="form-grid form-grid--two">
        <div class="field<?= isset($errors['category_id']) ? ' has-error' : '' ?>">
          <label class="field__label" for="category_id">Category <span class="req">*</span></label>
          <select id="category_id" name="category_id" required>
            <option value="0">Select category</option>
            <?php foreach ($cats as $cat): ?>
              <option value="<?= (int)$cat['id'] ?>" <?= isset($product['category_id']) && $product['category_id'] == $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="field__error"<?= isset($errors['category_id']) ? '' : ' hidden' ?>><?= e($errors['category_id'] ?? '') ?></span>
        </div>
        <div class="field<?= isset($errors['base_price']) ? ' has-error' : '' ?>">
          <label class="field__label" for="base_price">Base Price (₱) <span class="req">*</span></label>
          <input id="base_price" name="base_price" type="number" step="0.01" value="<?= e($product['base_price'] ?? '') ?>" required>
          <span class="field__error"<?= isset($errors['base_price']) ? '' : ' hidden' ?>><?= e($errors['base_price'] ?? '') ?></span>
        </div>
      </div>
      
      <div class="form-grid form-grid--two">
        <div class="field">
          <label class="field__label" for="puffs">Puffs</label>
          <input id="puffs" name="puffs" type="text" value="<?= e($product['puffs'] ?? '') ?>" placeholder="e.g., 5000 puffs">
        </div>
        <div class="field">
          <label class="field__label" for="image_url">Image URL</label>
          <input id="image_url" name="image_url" type="url" value="<?= e($product['image_url'] ?? '') ?>">
        </div>
      </div>
      
      <div class="field">
        <label class="field__label" for="description">Description</label>
        <textarea id="description" name="description" rows="4"><?= e($product['description'] ?? '') ?></textarea>
      </div>
      
      <div style="display:flex;gap:1.5rem;">
        <label style="display:flex;align-items:center;gap:0.5rem;color:#e8edf2;">
          <input type="checkbox" name="is_active" value="1" <?= !isset($product) || $product['is_active'] ? 'checked' : '' ?>>
          Active
        </label>
        <label style="display:flex;align-items:center;gap:0.5rem;color:#e8edf2;">
          <input type="checkbox" name="is_featured" value="1" <?= isset($product) && $product['is_featured'] ? 'checked' : '' ?>>
          Featured
        </label>
      </div>
      
      <button class="btn btn--accent" type="submit">Save Product</button>
    </form>
  </section>

  <?php if ($isEdit): ?>
    <div style="display:flex;flex-direction:column;gap:1.5rem;">
      <section class="admin-card">
        <div class="admin-card-header">
          <h2 class="admin-card-title">Generate Variants from Options</h2>
        </div>
        <form class="form-grid" method="post" style="padding:1.25rem;" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="generate_variants">
          
          <div class="field">
            <label class="field__label" for="strengths">Strengths (one per line or comma-separated)</label>
            <textarea id="strengths" name="strengths" rows="3" placeholder="0mg&#10;3mg&#10;6mg"></textarea>
          </div>
          
          <div class="field">
            <label class="field__label" for="flavors">Flavors (one per line or comma-separated)</label>
            <textarea id="flavors" name="flavors" rows="3" placeholder="Mango&#10;Watermelon&#10;Blueberry"></textarea>
          </div>
          
          <p style="color:#8b95a3;font-size:0.875rem;">This will create all combinations. Running it again is idempotent (updates existing variants).</p>
          <button class="btn btn--accent" type="submit">Generate from Options</button>
        </form>
      </section>

      <section class="admin-card">
        <div class="admin-card-header">
          <h2 class="admin-card-title">Variants</h2>
        </div>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_variants">
          
          <div class="admin-table-wrap">
            <table class="admin-table">
              <thead>
                <tr>
                  <th>Strength</th>
                  <th>Flavor</th>
                  <th>SKU</th>
                  <th>Price Adj. (₱)</th>
                  <th>Stock</th>
                  <th>Active</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($variants as $v): ?>
                  <tr>
                    <td><input type="text" name="variants[<?= (int)$v['id'] ?>][strength]" value="<?= e($v['strength']) ?>" style="width:100px;padding:0.375rem;background:var(--surface-2);border:1px solid var(--border);color:var(--text);"></td>
                    <td><input type="text" name="variants[<?= (int)$v['id'] ?>][flavor]" value="<?= e($v['flavor']) ?>" style="width:120px;padding:0.375rem;background:var(--surface-2);border:1px solid var(--border);color:var(--text);"></td>
                    <td><input type="text" name="variants[<?= (int)$v['id'] ?>][sku]" value="<?= e($v['sku']) ?>" style="width:150px;padding:0.375rem;background:var(--surface-2);border:1px solid var(--border);color:var(--text);"></td>
                    <td><input type="number" step="0.01" name="variants[<?= (int)$v['id'] ?>][price_adjustment]" value="<?= e($v['price_adjustment']) ?>" style="width:80px;padding:0.375rem;background:var(--surface-2);border:1px solid var(--border);color:var(--text);"></td>
                    <td><input type="number" name="variants[<?= (int)$v['id'] ?>][stock_qty]" value="<?= e($v['stock_qty']) ?>" style="width:80px;padding:0.375rem;background:var(--surface-2);border:1px solid var(--border);color:var(--text);<?= $v['stock_qty'] == 0 ? 'border-color:#ff6b6b;' : '' ?>"></td>
                    <td><input type="checkbox" name="variants[<?= (int)$v['id'] ?>][is_active]" value="1" <?= $v['is_active'] ? 'checked' : '' ?>></td>
                  </tr>
                <?php endforeach; ?>
                <tr>
                  <td colspan="6" style="text-align:center;color:#8b95a3;padding:0.75rem;">Add new variants below or use "Generate from Options" above</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div style="padding:1rem;">
            <button class="btn btn--accent" type="submit">Save Variants</button>
          </div>
        </form>
      </section>
    </div>
  <?php endif; ?>
</div>

<?php 
require_once __DIR__ . '/../includes/admin_chrome_end.php';
require_once __DIR__ . '/../includes/foot.php'; 
?>
