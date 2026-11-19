<?php
/**
 * VAPE CO. — customer registration.
 * Vape_ShopWEb / register.php
 */

require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect('index.php');
}

$first_name = '';
$last_name  = '';
$email      = '';
$phone      = '';
$birthdate  = '';
$terms      = false;

$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $first_name = trim((string) ($_POST['first_name'] ?? ''));
    $last_name  = trim((string) ($_POST['last_name'] ?? ''));
    $email      = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $phone      = trim((string) ($_POST['phone'] ?? ''));
    $birthdate  = trim((string) ($_POST['birthdate'] ?? ''));
    $terms      = isset($_POST['terms']);
    $password   = (string) ($_POST['password'] ?? '');
    $confirm    = (string) ($_POST['confirm_password'] ?? '');

    if (!csrf_verify(csrf_submitted_token())) {
        $errors['form'] = 'Your session expired. Please reload the page and try again.';
    } else {
        if ($first_name === '') {
            $errors['first_name'] = 'Enter your first name.';
        }
        if ($last_name === '') {
            $errors['last_name'] = 'Enter your last name.';
        }
        if ($email === '') {
            $errors['email'] = 'Enter your email address.';
        } elseif (!preg_match('/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i', $email)) {
            $errors['email'] = 'Enter a valid email address.';
        } else {
            try {
                $stmt = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $errors['email'] = 'That email address is already registered.';
                }
            } catch (Throwable $e) {
                error_log('register email check failed: ' . $e->getMessage());
            }
        }
        if ($phone === '') {
            $errors['phone'] = 'Enter your phone number.';
        }
        if ($birthdate === '') {
            $errors['birthdate'] = 'Enter your date of birth.';
        } elseif (is_under_21($birthdate)) {
            $errors['birthdate'] = 'You must be 21 or older to register.';
        }
        if ($password === '') {
            $errors['password'] = 'Enter a password.';
        } elseif (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $errors['password'] = 'Password must be at least 8 characters and include a letter and a number.';
        }
        if ($confirm === '') {
            $errors['confirm_password'] = 'Confirm your password.';
        } elseif ($confirm !== $password) {
            $errors['confirm_password'] = 'Passwords do not match.';
        }
        if (!$terms) {
            $errors['terms'] = 'You must agree to the Terms & Policy to create an account.';
        }

        if (!$errors) {
            try {
                $pdo = db();
                $pdo->beginTransaction();

                $roleStmt = $pdo->prepare("SELECT id FROM roles WHERE role_name = 'customer' LIMIT 1");
                $roleStmt->execute();
                $roleId = $roleStmt->fetchColumn();
                if (!$roleId) {
                    throw new RuntimeException('Customer role not found');
                }

                $stmt = $pdo->prepare(
                    'INSERT INTO users
                       (role_id, email, password_hash, first_name, last_name, phone, birthdate,
                        is_active, terms_accepted_at, terms_version)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), ?)'
                );
                $stmt->execute([
                    (int) $roleId,
                    $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    $first_name,
                    $last_name,
                    $phone,
                    $birthdate,
                    TERMS_VERSION,
                ]);

                $userId = (int) $pdo->lastInsertId();
                $pdo->commit();

                $userStmt = $pdo->prepare(
                    'SELECT u.id, u.email, u.first_name, u.last_name, u.phone, u.birthdate,
                            u.is_active, u.blocked_reason, u.role_id, r.role_name
                       FROM users u
                       JOIN roles r ON r.id = u.role_id
                      WHERE u.id = ?
                      LIMIT 1'
                );
                $userStmt->execute([$userId]);
                $user = $userStmt->fetch();

                if ($user) {
                    login_succeed($user, $password);
                }

                flash('Account created. Welcome to ' . APP_NAME . '!', 'success');
                redirect('index.php');
            } catch (Throwable $e) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('registration failed: ' . $e->getMessage());
                $errors['form'] = 'We could not create your account. Please try again.';
            }
        }
    }
}

$pageTitle       = 'Create account — ' . APP_NAME;
$bodyClass       = 'page-auth';
$metaDescription = 'Create a ' . APP_NAME . ' account to shop.';
$loadApp         = false;

require_once __DIR__ . '/includes/head.php';
?>
<div class="auth">

  <main class="auth__main">
    <a class="wordmark wordmark--sm auth__brand" href="index.php" aria-label="VAPE CO. — back to the storefront">VAPE<span class="wordmark__accent">CO.</span></a>

    <div class="auth__inner">
      <p class="micro-label">Account</p>
      <h1 class="auth__title">Create account</h1>
      <p class="auth__lede">Register to check out, save addresses, and track your orders. Adults 21+ only.</p>

      <?php if (!empty($errors['form'])): ?>
        <div class="auth__alert" role="alert">
          <svg aria-hidden="true"><use href="#icon-close"></use></svg>
          <span><?= e($errors['form']) ?></span>
        </div>
      <?php endif; ?>

      <form class="auth__form form-grid" method="post" novalidate>
        <?= csrf_field() ?>

        <div class="form-grid form-grid--two">
          <div class="field<?= isset($errors['first_name']) ? ' has-error' : '' ?>">
            <label class="field__label" for="first_name">First name <span class="req">*</span></label>
            <input id="first_name" name="first_name" type="text" autocomplete="given-name"
                   value="<?= e($first_name) ?>" required
                   <?= isset($errors['first_name']) ? 'aria-invalid="true" aria-describedby="first_name-error"' : '' ?>>
            <span class="field__error" id="first_name-error"<?= isset($errors['first_name']) ? '' : ' hidden' ?>><?= e($errors['first_name'] ?? '') ?></span>
          </div>

          <div class="field<?= isset($errors['last_name']) ? ' has-error' : '' ?>">
            <label class="field__label" for="last_name">Last name <span class="req">*</span></label>
            <input id="last_name" name="last_name" type="text" autocomplete="family-name"
                   value="<?= e($last_name) ?>" required
                   <?= isset($errors['last_name']) ? 'aria-invalid="true" aria-describedby="last_name-error"' : '' ?>>
            <span class="field__error" id="last_name-error"<?= isset($errors['last_name']) ? '' : ' hidden' ?>><?= e($errors['last_name'] ?? '') ?></span>
          </div>
        </div>

        <div class="field<?= isset($errors['email']) ? ' has-error' : '' ?>">
          <label class="field__label" for="email">Email address <span class="req">*</span></label>
          <input id="email" name="email" type="email" inputmode="email" autocomplete="username" spellcheck="false"
                 value="<?= e($email) ?>" required
                 <?= isset($errors['email']) ? 'aria-invalid="true" aria-describedby="email-error"' : '' ?>>
          <span class="field__error" id="email-error"<?= isset($errors['email']) ? '' : ' hidden' ?>><?= e($errors['email'] ?? '') ?></span>
        </div>

        <div class="field<?= isset($errors['phone']) ? ' has-error' : '' ?>">
          <label class="field__label" for="phone">Phone number <span class="req">*</span></label>
          <input id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel"
                 value="<?= e($phone) ?>" required
                 <?= isset($errors['phone']) ? 'aria-invalid="true" aria-describedby="phone-error"' : '' ?>>
          <span class="field__error" id="phone-error"<?= isset($errors['phone']) ? '' : ' hidden' ?>><?= e($errors['phone'] ?? '') ?></span>
        </div>

        <div class="field<?= isset($errors['birthdate']) ? ' has-error' : '' ?>">
          <label class="field__label" for="birthdate">Date of birth <span class="req">*</span></label>
          <input id="birthdate" name="birthdate" type="date"
                 value="<?= e($birthdate) ?>" required
                 <?= isset($errors['birthdate']) ? 'aria-invalid="true" aria-describedby="birthdate-error"' : '' ?>>
          <span class="field__error" id="birthdate-error"<?= isset($errors['birthdate']) ? '' : ' hidden' ?>><?= e($errors['birthdate'] ?? '') ?></span>
        </div>

        <div class="field<?= isset($errors['password']) ? ' has-error' : '' ?>">
          <label class="field__label" for="password">Password <span class="req">*</span></label>
          <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required
                 <?= isset($errors['password']) ? 'aria-invalid="true" aria-describedby="password-error"' : '' ?>>
          <span class="field__error" id="password-error"<?= isset($errors['password']) ? '' : ' hidden' ?>><?= e($errors['password'] ?? '') ?></span>
        </div>

        <div class="field<?= isset($errors['confirm_password']) ? ' has-error' : '' ?>">
          <label class="field__label" for="confirm_password">Confirm password <span class="req">*</span></label>
          <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required
                 <?= isset($errors['confirm_password']) ? 'aria-invalid="true" aria-describedby="confirm_password-error"' : '' ?>>
          <span class="field__error" id="confirm_password-error"<?= isset($errors['confirm_password']) ? '' : ' hidden' ?>><?= e($errors['confirm_password'] ?? '') ?></span>
        </div>

        <div class="field<?= isset($errors['terms']) ? ' has-error' : '' ?>">
          <label class="check-field" for="terms">
            <input id="terms" name="terms" type="checkbox" value="1" <?= $terms ? 'checked' : '' ?>
                   <?= isset($errors['terms']) ? 'aria-invalid="true" aria-describedby="terms-error"' : '' ?>>
            <span>I agree to the <a href="terms.php" target="_blank" rel="noopener">Terms &amp; Policy</a> and confirm I am 21 or older.</span>
          </label>
          <span class="field__error" id="terms-error"<?= isset($errors['terms']) ? '' : ' hidden' ?>><?= e($errors['terms'] ?? '') ?></span>
        </div>

        <button class="btn btn--accent btn--lg btn--block" type="submit">Create account</button>
      </form>

      <p class="auth__alt">
        Already have an account? <a href="login.php">Sign in</a>
      </p>
      <p class="auth__alt"><a href="terms.php">Read the Terms &amp; Policy</a></p>
    </div>

    <p class="auth__foot"><?= e(APP_NAME) ?> · Adults 21+ only</p>
  </main>

  <aside class="auth__panel" aria-hidden="true">
    <span class="auth__panel-mark">VAPE<span>CO.</span></span>
    <span class="auth__panel-age">21+</span>
  </aside>

</div>
<?php require_once __DIR__ . '/includes/foot.php'; ?>
