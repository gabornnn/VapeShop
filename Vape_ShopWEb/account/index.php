<?php
/**
 * VAPE CO. — customer profile.
 * Vape_ShopWEb / account / index.php
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

require_login('login.php');

$user = current_user();
$errors = [];
$notice = '';
$flash = get_flash();
if ($flash) {
    $notice = $flash['message'];
}

$firstName = $user['first_name'] ?? '';
$lastName  = $user['last_name'] ?? '';
$phone     = $user['phone'] ?? '';
$email     = $user['email'] ?? '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify(csrf_submitted_token())) {
        $errors['form'] = 'Your session expired. Please reload the page and try again.';
    } else {
        $action = $_POST['action'] ?? 'profile';
        
        if ($action === 'profile') {
            $firstName = trim((string) ($_POST['first_name'] ?? ''));
            $lastName  = trim((string) ($_POST['last_name'] ?? ''));
            $phone     = trim((string) ($_POST['phone'] ?? ''));
            
            if ($firstName === '') $errors['first_name'] = 'Enter your first name.';
            if ($lastName === '') $errors['last_name'] = 'Enter your last name.';
            if ($phone === '') $errors['phone'] = 'Enter your phone number.';
            
            if (!$errors) {
                try {
                    $stmt = db()->prepare('UPDATE users SET first_name = ?, last_name = ?, phone = ? WHERE id = ?');
                    $stmt->execute([$firstName, $lastName, $phone, (int)$user['id']]);
                    // Refresh session user data
                    unset($user);
                    $user = current_user();
                    $firstName = $user['first_name'] ?? $firstName;
                    $lastName  = $user['last_name'] ?? $lastName;
                    $phone     = $user['phone'] ?? $phone;
                    flash('Profile updated successfully.', 'success');
                    redirect('account/index.php');
                } catch (Throwable $e) {
                    error_log('profile update failed: ' . $e->getMessage());
                    $errors['form'] = 'We could not update your profile. Please try again.';
                }
            }
        } elseif ($action === 'password') {
            $currentPass = (string) ($_POST['current_password'] ?? '');
            $newPass     = (string) ($_POST['new_password'] ?? '');
            $confirmPass = (string) ($_POST['confirm_password'] ?? '');
            
            if ($currentPass === '') $errors['current_password'] = 'Enter your current password.';
            if ($newPass === '') {
                $errors['new_password'] = 'Enter a new password.';
            } elseif (strlen($newPass) < 8 || !preg_match('/[A-Za-z]/', $newPass) || !preg_match('/[0-9]/', $newPass)) {
                $errors['new_password'] = 'Password must be at least 8 characters and include a letter and a number.';
            }
            if ($confirmPass === '') {
                $errors['confirm_password'] = 'Confirm your new password.';
            } elseif ($confirmPass !== $newPass) {
                $errors['confirm_password'] = 'Passwords do not match.';
            }
            
            if (!$errors) {
                try {
                    // Verify current password
                    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
                    $stmt->execute([(int)$user['id']]);
                    $hash = $stmt->fetchColumn();
                    
                    if (!$hash || !password_verify($currentPass, (string)$hash)) {
                        $errors['current_password'] = 'Current password is incorrect.';
                    } else {
                        $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                        $stmt->execute([password_hash($newPass, PASSWORD_DEFAULT), (int)$user['id']]);
                        flash('Password changed successfully.', 'success');
                        redirect('account/index.php');
                    }
                } catch (Throwable $e) {
                    error_log('password change failed: ' . $e->getMessage());
                    $errors['form'] = 'We could not change your password. Please try again.';
                }
            }
        }
    }
}

$pageTitle = 'My Profile — ' . APP_NAME;
$bodyClass = 'page-account';
$loadApp   = false;

require_once __DIR__ . '/../includes/head.php';
?>
<div class="account">
  <div class="account__container">
    <header class="account__header">
      <div>
        <p class="micro-label">My Account</p>
        <h1 class="account__title">Profile</h1>
      </div>
      <nav class="account__nav">
        <a class="account__nav-link is-active" href="index.php">Profile</a>
        <a class="account__nav-link" href="orders.php">Orders</a>
        <a class="account__nav-link" href="addresses.php">Addresses</a>
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
        <h2 class="account__card-title">Personal Information</h2>
        <form class="form-grid" method="post" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="profile">
          
          <div class="form-grid form-grid--two">
            <div class="field<?= isset($errors['first_name']) ? ' has-error' : '' ?>">
              <label class="field__label" for="first_name">First name <span class="req">*</span></label>
              <input id="first_name" name="first_name" type="text" value="<?= e($firstName) ?>" required>
              <span class="field__error"<?= isset($errors['first_name']) ? '' : ' hidden' ?>><?= e($errors['first_name'] ?? '') ?></span>
            </div>
            <div class="field<?= isset($errors['last_name']) ? ' has-error' : '' ?>">
              <label class="field__label" for="last_name">Last name <span class="req">*</span></label>
              <input id="last_name" name="last_name" type="text" value="<?= e($lastName) ?>" required>
              <span class="field__error"<?= isset($errors['last_name']) ? '' : ' hidden' ?>><?= e($errors['last_name'] ?? '') ?></span>
            </div>
          </div>
          
          <div class="field">
            <label class="field__label" for="email">Email address</label>
            <input id="email" type="email" value="<?= e($email) ?>" disabled>
            <p class="field__hint">Email cannot be changed.</p>
          </div>
          
          <div class="field<?= isset($errors['phone']) ? ' has-error' : '' ?>">
            <label class="field__label" for="phone">Phone number <span class="req">*</span></label>
            <input id="phone" name="phone" type="tel" value="<?= e($phone) ?>" required>
            <span class="field__error"<?= isset($errors['phone']) ? '' : ' hidden' ?>><?= e($errors['phone'] ?? '') ?></span>
          </div>
          
          <button class="btn btn--accent" type="submit">Save changes</button>
        </form>
      </section>

      <section class="account__card">
        <h2 class="account__card-title">Change Password</h2>
        <form class="form-grid" method="post" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="password">
          
          <div class="field<?= isset($errors['current_password']) ? ' has-error' : '' ?>">
            <label class="field__label" for="current_password">Current password <span class="req">*</span></label>
            <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
            <span class="field__error"<?= isset($errors['current_password']) ? '' : ' hidden' ?>><?= e($errors['current_password'] ?? '') ?></span>
          </div>
          
          <div class="field<?= isset($errors['new_password']) ? ' has-error' : '' ?>">
            <label class="field__label" for="new_password">New password <span class="req">*</span></label>
            <input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="8" required>
            <span class="field__error"<?= isset($errors['new_password']) ? '' : ' hidden' ?>><?= e($errors['new_password'] ?? '') ?></span>
            <p class="field__hint">At least 8 characters with letter and number.</p>
          </div>
          
          <div class="field<?= isset($errors['confirm_password']) ? ' has-error' : '' ?>">
            <label class="field__label" for="confirm_password">Confirm new password <span class="req">*</span></label>
            <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required>
            <span class="field__error"<?= isset($errors['confirm_password']) ? '' : ' hidden' ?>><?= e($errors['confirm_password'] ?? '') ?></span>
          </div>
          
          <button class="btn btn--accent" type="submit">Change password</button>
        </form>
      </section>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/foot.php'; ?>
