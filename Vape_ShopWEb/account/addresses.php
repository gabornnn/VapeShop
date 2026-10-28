<?php
/**
 * VAPE CO. — customer addresses.
 * Vape_ShopWEb / account / addresses.php
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

require_login('login.php');

$user = current_user();
$pdo = db();
$userId = (int)$user['id'];
$errors = [];
$notice = '';
$flash = get_flash();
if ($flash) {
    $notice = $flash['message'];
}

// Handle actions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify(csrf_submitted_token())) {
        $errors['form'] = 'Your session expired. Please reload the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'add' || $action === 'edit') {
            $label = trim((string) ($_POST['label'] ?? ''));
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $line1 = trim((string) ($_POST['line1'] ?? ''));
            $line2 = trim((string) ($_POST['line2'] ?? ''));
            $city = trim((string) ($_POST['city'] ?? ''));
            $province = trim((string) ($_POST['province'] ?? ''));
            $postal = trim((string) ($_POST['postal_code'] ?? ''));
            $isDefault = isset($_POST['is_default']) ? 1 : 0;
            $addressId = isset($_POST['address_id']) ? (int)$_POST['address_id'] : 0;
            
            if ($fullName === '') $errors['full_name'] = 'Enter full name.';
            if ($phone === '') $errors['phone'] = 'Enter phone number.';
            if ($line1 === '') $errors['line1'] = 'Enter address line 1.';
            if ($city === '') $errors['city'] = 'Enter city.';
            if ($province === '') $errors['province'] = 'Enter province.';
            
            if (!$errors) {
                try {
                    $pdo->beginTransaction();
                    
                    if ($isDefault) {
                        $pdo->prepare('UPDATE addresses SET is_default = 0 WHERE user_id = ?')->execute([$userId]);
                    }
                    
                    if ($action === 'add') {
                        $stmt = $pdo->prepare('INSERT INTO addresses (user_id, label, full_name, phone, line1, line2, city, province, postal_code, is_default) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                        $stmt->execute([$userId, $label ?: 'Home', $fullName, $phone, $line1, $line2 ?: null, $city, $province, $postal ?: null, $isDefault]);
                    } else {
                        // Verify ownership
                        $check = $pdo->prepare('SELECT id FROM addresses WHERE id = ? AND user_id = ?');
                        $check->execute([$addressId, $userId]);
                        if (!$check->fetch()) {
                            throw new Exception('Invalid address');
                        }
                        $stmt = $pdo->prepare('UPDATE addresses SET label=?, full_name=?, phone=?, line1=?, line2=?, city=?, province=?, postal_code=?, is_default=? WHERE id=? AND user_id=?');
                        $stmt->execute([$label ?: 'Home', $fullName, $phone, $line1, $line2 ?: null, $city, $province, $postal ?: null, $isDefault, $addressId, $userId]);
                    }
                    
                    $pdo->commit();
                    flash('Address saved successfully.', 'success');
                    redirect('addresses.php');
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('address save failed: ' . $e->getMessage());
                    $errors['form'] = 'We could not save the address. Please try again.';
                }
            }
        } elseif ($action === 'delete') {
            $addressId = isset($_POST['address_id']) ? (int)$_POST['address_id'] : 0;
            try {
                $stmt = $pdo->prepare('DELETE FROM addresses WHERE id = ? AND user_id = ?');
                $stmt->execute([$addressId, $userId]);
                flash('Address deleted.', 'success');
                redirect('addresses.php');
            } catch (Throwable $e) {
                error_log('address delete failed: ' . $e->getMessage());
                $errors['form'] = 'We could not delete the address.';
            }
        } elseif ($action === 'set_default') {
            $addressId = isset($_POST['address_id']) ? (int)$_POST['address_id'] : 0;
            try {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE addresses SET is_default = 0 WHERE user_id = ?')->execute([$userId]);
                $pdo->prepare('UPDATE addresses SET is_default = 1 WHERE id = ? AND user_id = ?')->execute([$addressId, $userId]);
                $pdo->commit();
                flash('Default address updated.', 'success');
                redirect('addresses.php');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors['form'] = 'We could not update the default address.';
            }
        }
    }
}

// Get addresses
$stmt = $pdo->prepare('SELECT * FROM addresses WHERE user_id = ? ORDER BY is_default DESC, created_at ASC');
$stmt->execute([$userId]);
$addresses = $stmt->fetchAll();

// For editing
$editAddr = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    foreach ($addresses as $a) {
        if ((int)$a['id'] === $eid) {
            $editAddr = $a;
            break;
        }
    }
}

$pageTitle = 'Saved Addresses — ' . APP_NAME;
$bodyClass = 'page-account';
$loadApp   = false;

require_once __DIR__ . '/../includes/head.php';
?>
<div class="account">
  <div class="account__container">
    <header class="account__header">
      <div>
        <p class="micro-label">My Account</p>
        <h1 class="account__title">Addresses</h1>
      </div>
      <nav class="account__nav">
        <a class="account__nav-link" href="index.php">Profile</a>
        <a class="account__nav-link" href="orders.php">Orders</a>
        <a class="account__nav-link is-active" href="addresses.php">Addresses</a>
        <a class="account__nav-link account__nav-link--danger" href="../logout.php">Sign out</a>
      </nav>
    </header>

    <?php if ($notice !== ''): ?>
      <div class="auth__note" role="status">
        <svg aria-hidden="true"><use href="#icon-box"></use></svg>
        <span><?= e($notice) ?></span>
      </div>
    <?php endif; ?>
    <?php if (!empty($errors['form'])): ?>
      <div class="auth__alert" role="alert">
        <svg aria-hidden="true"><use href="#icon-close"></use></svg>
        <span><?= e($errors['form']) ?></span>
      </div>
    <?php endif; ?>

    <div class="account__grid">
      <section class="account__card">
        <h2 class="account__card-title"><?= $editAddr ? 'Edit Address' : 'Add New Address' ?></h2>
        <form class="form-grid" method="post" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="<?= $editAddr ? 'edit' : 'add' ?>">
          <?php if ($editAddr): ?><input type="hidden" name="address_id" value="<?= (int)$editAddr['id'] ?>"><?php endif; ?>
          
          <div class="form-grid form-grid--two">
            <div class="field">
              <label class="field__label" for="label">Label (e.g., Home, Office)</label>
              <input id="label" name="label" type="text" value="<?= e($editAddr['label'] ?? '') ?>">
            </div>
            <div class="field<?= isset($errors['full_name']) ? ' has-error' : '' ?>">
              <label class="field__label" for="full_name">Full name <span class="req">*</span></label>
              <input id="full_name" name="full_name" type="text" value="<?= e($editAddr['full_name'] ?? ($user['first_name'].' '.$user['last_name'])) ?>" required>
              <span class="field__error"<?= isset($errors['full_name']) ? '' : ' hidden' ?>><?= e($errors['full_name'] ?? '') ?></span>
            </div>
          </div>
          
          <div class="field<?= isset($errors['phone']) ? ' has-error' : '' ?>">
            <label class="field__label" for="phone">Phone number <span class="req">*</span></label>
            <input id="phone" name="phone" type="tel" value="<?= e($editAddr['phone'] ?? $user['phone']) ?>" required>
            <span class="field__error"<?= isset($errors['phone']) ? '' : ' hidden' ?>><?= e($errors['phone'] ?? '') ?></span>
          </div>
          
          <div class="field<?= isset($errors['line1']) ? ' has-error' : '' ?>">
            <label class="field__label" for="line1">Address line 1 <span class="req">*</span></label>
            <input id="line1" name="line1" type="text" value="<?= e($editAddr['line1'] ?? '') ?>" required>
            <span class="field__error"<?= isset($errors['line1']) ? '' : ' hidden' ?>><?= e($errors['line1'] ?? '') ?></span>
          </div>
          
          <div class="field">
            <label class="field__label" for="line2">Address line 2 (optional)</label>
            <input id="line2" name="line2" type="text" value="<?= e($editAddr['line2'] ?? '') ?>">
          </div>
          
          <div class="form-grid form-grid--two">
            <div class="field<?= isset($errors['city']) ? ' has-error' : '' ?>">
              <label class="field__label" for="city">City <span class="req">*</span></label>
              <input id="city" name="city" type="text" value="<?= e($editAddr['city'] ?? '') ?>" required>
              <span class="field__error"<?= isset($errors['city']) ? '' : ' hidden' ?>><?= e($errors['city'] ?? '') ?></span>
            </div>
            <div class="field<?= isset($errors['province']) ? ' has-error' : '' ?>">
              <label class="field__label" for="province">Province <span class="req">*</span></label>
              <input id="province" name="province" type="text" value="<?= e($editAddr['province'] ?? '') ?>" required>
              <span class="field__error"<?= isset($errors['province']) ? '' : ' hidden' ?>><?= e($errors['province'] ?? '') ?></span>
            </div>
          </div>
          
          <div class="field">
            <label class="field__label" for="postal_code">Postal code (optional)</label>
            <input id="postal_code" name="postal_code" type="text" value="<?= e($editAddr['postal_code'] ?? '') ?>">
          </div>
          
          <label style="display:flex;align-items:center;gap:0.5rem;color:#e8edf2;">
            <input type="checkbox" name="is_default" value="1" <?= ($editAddr && $editAddr['is_default']) || (!$editAddr && empty($addresses)) ? 'checked' : '' ?>>
            Set as default address
          </label>
          
          <div style="display:flex;gap:0.75rem;">
            <button class="btn btn--accent" type="submit"><?= $editAddr ? 'Update Address' : 'Add Address' ?></button>
            <?php if ($editAddr): ?><a href="addresses.php" class="btn btn--ghost">Cancel</a><?php endif; ?>
          </div>
        </form>
      </section>

      <section class="account__card">
        <h2 class="account__card-title">Saved Addresses</h2>
        <?php if (empty($addresses)): ?>
          <p style="color:#8b95a3;">No saved addresses yet.</p>
        <?php else: ?>
          <div style="display:flex;flex-direction:column;gap:1rem;">
            <?php foreach ($addresses as $addr): ?>
              <div style="padding:1rem;border:1px solid rgba(232,237,242,0.08);border-radius:0.5rem;<?= $addr['is_default'] ? 'border-color:rgba(0,240,255,0.4);' : '' ?>">
                <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:0.75rem;">
                  <div>
                    <p style="color:#e8edf2;font-weight:600;"><?= e($addr['label'] ?: 'Address') ?> <?= $addr['is_default'] ? '<span style="color:#00f0ff;font-size:0.75rem;">DEFAULT</span>' : '' ?></p>
                  </div>
                  <div style="display:flex;gap:0.5rem;">
                    <a href="addresses.php?edit=<?= (int)$addr['id'] ?>" class="btn btn--ghost btn--sm">Edit</a>
                    <?php if (!$addr['is_default']): ?>
                      <form method="post" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="set_default">
                        <input type="hidden" name="address_id" value="<?= (int)$addr['id'] ?>">
                        <button class="btn btn--ghost btn--sm" type="submit">Set Default</button>
                      </form>
                    <?php endif; ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this address?');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="address_id" value="<?= (int)$addr['id'] ?>">
                      <button class="btn btn--ghost btn--sm" type="submit" style="color:#ff6b6b;">Delete</button>
                    </form>
                  </div>
                </div>
                <p style="color:#8b95a3;line-height:1.6;font-size:0.875rem;">
                  <?= e($addr['full_name']) ?><br>
                  <?= e($addr['phone']) ?><br>
                  <?= e($addr['line1']) ?><?php if ($addr['line2']): ?>, <?= e($addr['line2']) ?><?php endif; ?><br>
                  <?= e($addr['city']) ?>, <?= e($addr['province']) ?><?php if ($addr['postal_code']): ?> <?= e($addr['postal_code']) ?><?php endif; ?>
                </p>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/foot.php'; ?>
