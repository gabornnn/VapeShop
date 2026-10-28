<?php
/**
 * VAPE CO. — customer sign in.
 * Vape_ShopWEb / login.php
 */

require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect('index.php');
}

$email     = '';
$password  = '';
$errors    = [];
$notice    = '';
$lockedOut = false;

if (isset($_GET['timeout'])) {
    $notice = 'Your session expired. Please sign in again.';
} elseif (isset($_GET['expired'])) {
    $notice = 'Your session expired. Please sign in again.';
}

$next = safe_next($_GET['next'] ?? null);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $email    = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $next     = safe_next($_POST['next'] ?? ($next ?? null));

    if (!csrf_verify(csrf_submitted_token())) {
        $errors['form'] = 'Your session expired. Please reload the page and try again.';
    } elseif ($email === '' || $password === '') {
        if ($email === '') {
            $errors['email'] = 'Enter your email address.';
        }
        if ($password === '') {
            $errors['password'] = 'Enter your password.';
        }
    } else {
        $lock = login_lockout_status($email);

        if ($lock['locked']) {
            $lockedOut = true;
            $minutes   = $lock['minutesLeft'];
            $errors['form'] = 'Too many failed attempts. Try again in '
                . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.';
        } else {
            $stmt = db()->prepare(
                'SELECT u.id, u.email, u.password_hash, u.first_name, u.last_name,
                        u.is_active, u.blocked_reason, u.birthdate, r.role_name
                   FROM users u
                   JOIN roles r ON r.id = u.role_id
                  WHERE u.email = ?
                  LIMIT 1'
            );
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, (string) $user['password_hash'])) {
                login_attempt_record($email, false);
                $errors['form'] = 'Email or password is incorrect.';
            } elseif ((int) $user['is_active'] !== 1) {
                login_attempt_record($email, false);
                $errors['form'] = 'This account has been blocked.'
                    . (!empty($user['blocked_reason']) ? ' Reason: ' . $user['blocked_reason'] : '');
            } elseif (is_under_21((string) $user['birthdate'])) {
                login_attempt_record($email, false);
                $errors['form'] = 'This account is not eligible to shop here.';
            } else {
                login_attempt_record($email, true);
                login_succeed($user, $password);

                if ($user['role_name'] === 'admin') {
                    $_SESSION['admin_last_activity'] = time();
                    redirect('admin/index.php');
                }

                redirect($next ?: 'index.php');
            }
        }
    }
}

$pageTitle       = 'Sign in — ' . APP_NAME;
$bodyClass       = 'page-auth';
$metaDescription = 'Sign in to your ' . APP_NAME . ' account.';
$loadApp         = false;

require_once __DIR__ . '/includes/head.php';
?>
<div class="auth">

  <main class="auth__main">
    <a class="wordmark wordmark--sm auth__brand" href="index.php" aria-label="VAPE CO. — back to the storefront">VAPE<span class="wordmark__accent">CO.</span></a>

    <div class="auth__inner">
      <p class="micro-label">Account</p>
      <h1 class="auth__title">Sign in</h1>
      <p class="auth__lede">Welcome back. Sign in to checkout faster and track your orders.</p>

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

      <form class="auth__form form-grid" method="post" novalidate>
        <?= csrf_field() ?>
        <?php if ($next !== null): ?>
          <input type="hidden" name="next" value="<?= e($next) ?>">
        <?php endif; ?>

        <div class="field<?= isset($errors['email']) ? ' has-error' : '' ?>">
          <label class="field__label" for="email">Email address <span class="req">*</span></label>
          <input id="email" name="email" type="email" inputmode="email"
                 autocomplete="username" spellcheck="false"
                 value="<?= e($email) ?>" required
                 <?= isset($errors['email']) ? 'aria-invalid="true" aria-describedby="email-error"' : '' ?>>
          <span class="field__error" id="email-error"<?= isset($errors['email']) ? '' : ' hidden' ?>><?= e($errors['email'] ?? '') ?></span>
        </div>

        <div class="field<?= isset($errors['password']) ? ' has-error' : '' ?>">
          <label class="field__label" for="password">Password <span class="req">*</span></label>
          <input id="password" name="password" type="password"
                 autocomplete="current-password" required
                 <?= isset($errors['password']) ? 'aria-invalid="true" aria-describedby="password-error"' : '' ?>>
          <span class="field__error" id="password-error"<?= isset($errors['password']) ? '' : ' hidden' ?>><?= e($errors['password'] ?? '') ?></span>
        </div>

        <button class="btn btn--accent btn--lg btn--block" type="submit"<?= $lockedOut ? ' disabled' : '' ?>>
          <?= $lockedOut ? 'Temporarily locked' : 'Sign in' ?>
        </button>
      </form>

      <p class="auth__alt">
        New to <?= e(APP_NAME) ?>? <a href="register.php">Create an account</a>
      </p>
      <p class="auth__alt">
        Staff? <a href="admin/login.php">Sign in here</a>
      </p>
    </div>

    <p class="auth__foot"><?= e(APP_NAME) ?> · Adults 21+ only</p>
  </main>

  <aside class="auth__panel" aria-hidden="true">
    <span class="auth__panel-mark">VAPE<span>CO.</span></span>
    <span class="auth__panel-age">21+</span>
  </aside>

</div>
<?php require_once __DIR__ . '/includes/foot.php'; ?>
