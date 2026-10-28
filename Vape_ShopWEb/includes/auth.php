<?php
/**
 * VAPE CO. — sessions, identity, and access control.
 *
 * Every page and every API endpoint includes this file first. It starts the
 * session, works out who is signed in, and provides the guards that keep
 * ordinary customers out of the admin panel.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/csrf.php';

/* ---------- Session ---------- */

/**
 * Start the session once per request.
 *
 * The cookie is HttpOnly (JavaScript cannot read it) and SameSite=Lax (the
 * browser does not send it on cross-site form posts). "secure" is false
 * because XAMPP serves plain HTTP — turn it on for a real deployment.
 */
function session_boot(): void
{
    /*
     * Command-line tools (database/import.php, database/seed_admin.php) have no
     * browser and no cookie jar. Starting a session there would only emit
     * "headers already sent" warnings, so give them a plain in-memory array and
     * let flash() / old() / csrf_token() keep working without touching a file.
     */
    if (PHP_SAPI === 'cli') {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }
        return;
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => false,
        ]);
        session_start();
    }
}

session_boot();

/* ---------- Identity ---------- */

/**
 * The signed-in user, or null.
 *
 * Returns the row joined to its role, so the role name is always available.
 * @return array<string,mixed>|null
 */
function current_user(): ?array
{
    static $user = null;
    static $loaded = false;

    if ($loaded) {
        return $user;
    }
    $loaded = true;

    $id = $_SESSION['user_id'] ?? null;
    if (!$id) {
        return $user = null;
    }

    try {
        $stmt = db()->prepare(
            'SELECT u.id, u.email, u.first_name, u.last_name, u.phone, u.birthdate,
                    u.is_active, u.blocked_reason, u.role_id, r.role_name
               FROM users u
               JOIN roles r ON r.id = u.role_id
              WHERE u.id = ?
              LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $user = $row ?: null;
    } catch (Throwable $e) {
        error_log('current_user() failed: ' . $e->getMessage());
        $user = null;
    }

    return $user;
}

/**
 * The signed-in user in the shape the browser receives.
 *
 * Only the fields the UI needs — never the password hash.
 */
function current_user_public(): ?array
{
    $user = current_user();
    if (!$user) {
        return null;
    }

    return [
        'id'        => (int) $user['id'],
        'firstName' => $user['first_name'],
        'lastName'  => $user['last_name'],
        'email'     => $user['email'],
        'role'      => $user['role_name'],
    ];
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_admin(): bool
{
    $user = current_user();
    return $user !== null && $user['role_name'] === 'admin';
}

/**
 * Is this date of birth under the store's minimum age?
 *
 * Fails closed: an unparseable date counts as under age.
 */
function is_under_21(string $birthdate): bool
{
    $dob = DateTimeImmutable::createFromFormat('!Y-m-d', trim($birthdate));
    if ($dob === false) {
        return true;
    }
    $cutoff = (new DateTimeImmutable('today'))->modify('-' . MIN_AGE . ' years');
    return $dob > $cutoff;
}

/* ---------- Guest cart token ---------- */

/**
 * A stable token identifying a visitor who has not signed in.
 *
 * IMPORTANT: the token lives INSIDE the session, and the database stores a
 * SHA-256 hash of it. It must not be derived from session_id(), because
 * session_regenerate_id() runs on login and would change it — which would
 * orphan the guest's cart at the exact moment we need to merge it.
 */
function guest_token(): string
{
    if (empty($_SESSION['guest_token']) || !is_string($_SESSION['guest_token'])) {
        $_SESSION['guest_token'] = bin2hex(random_bytes(32));
    }
    return hash('sha256', $_SESSION['guest_token']);
}

/**
 * The raw guest token, captured before the session id is regenerated.
 *
 * Used by the cart merge so it can find the guest cart even after
 * session_regenerate_id() has run.
 */
function guest_token_raw(): string
{
    if (empty($_SESSION['guest_token']) || !is_string($_SESSION['guest_token'])) {
        $_SESSION['guest_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['guest_token'];
}

/* ---------- Cart resolution ---------- */

/**
 * Get (or create) the cart row for the current visitor.
 *
 * Logged in  → cart keyed by user_id
 * Guest       → cart keyed by guest_token (hash stored in DB)
 */
function get_cart_id(): int
{
    $pdo = db();
    $user = current_user();

    if ($user) {
        $userId = (int) $user['id'];

        $stmt = $pdo->prepare('SELECT id FROM carts WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $cartId = $stmt->fetchColumn();

        if ($cartId !== false) {
            return (int) $cartId;
        }

        $stmt = $pdo->prepare('INSERT INTO carts (user_id, guest_token) VALUES (?, NULL)');
        $stmt->execute([$userId]);

        return (int) $pdo->lastInsertId();
    }

    $tokenHash = guest_token();

    $stmt = $pdo->prepare('SELECT id FROM carts WHERE guest_token = ? LIMIT 1');
    $stmt->execute([$tokenHash]);
    $cartId = $stmt->fetchColumn();

    if ($cartId !== false) {
        return (int) $cartId;
    }

    $stmt = $pdo->prepare('INSERT INTO carts (user_id, guest_token) VALUES (NULL, ?)');
    $stmt->execute([$tokenHash]);

    return (int) $pdo->lastInsertId();
}

/**
 * Find a cart by guest token hash (used during merge before session changes).
 */
function get_cart_id_by_guest_hash(string $guestTokenHash): ?int
{
    if ($guestTokenHash === '') {
        return null;
    }

    $stmt = db()->prepare('SELECT id FROM carts WHERE guest_token = ? LIMIT 1');
    $stmt->execute([$guestTokenHash]);
    $cartId = $stmt->fetchColumn();

    return $cartId === false ? null : (int) $cartId;
}

/* ---------- Guards ---------- */

/**
 * Require a signed-in customer. Send them to the login page if they are not.
 *
 * @param string $loginUrl Which login page to send them to. Admin areas pass
 *                         'admin/login.php' so customers never see the panel.
 */
function require_login(string $loginUrl = 'login.php'): void
{
    if (is_logged_in()) {
        return;
    }

    $next = $_SERVER['REQUEST_URI'] ?? '';
    $url  = $loginUrl . ($next !== '' ? '?next=' . rawurlencode($next) : '');

    redirect($url);
}

/**
 * Require an authenticated user for API endpoints (no redirect).
 */
function require_api_login(): void
{
    if (is_logged_in()) {
        return;
    }

    api_fail('NOT_AUTHENTICATED', 'Please sign in to continue.', 401);
}

/**
 * The absolute URL path of the admin sign-in page.
 *
 * Absolute (starts with "/") on purpose: require_admin() runs from inside
 * /admin as well as from the project root, and a relative "admin/login.php"
 * would resolve to /admin/admin/login.php when the script is already there.
 */
function admin_login_url(): string
{
    return project_root_url() . '/admin/login.php';
}

/**
 * Require an administrator.
 *
 * Order matters: first make sure there is a user at all, then check the
 * role, then check for an idle timeout.
 */
function require_admin(): void
{
    require_login(admin_login_url());

    if (!is_admin()) {
        deny_admin();
    }

    $last = (int) ($_SESSION['admin_last_activity'] ?? 0);
    if ($last > 0 && (time() - $last) > ADMIN_IDLE_TIMEOUT) {
        session_unset();
        session_destroy();
        redirect(admin_login_url() . '?timeout=1');
    }

    $_SESSION['admin_last_activity'] = time();
}

/**
 * Answer 403 for a signed-in visitor who is not an administrator.
 *
 * This is a PAGE guard, so it renders a branded HTML page rather than the JSON
 * envelope the API endpoints use (sending JSON to a browser shows the visitor a
 * wall of braces). The page is self-contained, following the same approach as
 * db_fail() in config/database.php — no includes, no way to recurse.
 */
function deny_admin(): void
{
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
    }

    $name    = e(APP_NAME);
    $home    = e(project_root_url() . '/index.php');
    /* Phase 2 adds a storefront-wide logout.php; until then use the staff one. */
    $signOut = e(project_root_url() . (is_file(dirname(__DIR__) . '/logout.php') ? '/logout.php' : '/admin/logout.php'));

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Not authorised — {$name}</title>
<style>
  body{margin:0;min-height:100vh;display:grid;place-items:center;background:#07090c;color:#e8edf2;
       font-family:"Inter",system-ui,sans-serif;}
  .box{max-width:34rem;padding:2rem;text-align:center;}
  .tag{font-family:ui-monospace,Consolas,monospace;font-size:11px;letter-spacing:.18em;
       text-transform:uppercase;color:#00f0ff;}
  h1{font-family:"Space Grotesk",system-ui,sans-serif;font-size:1.5rem;margin:.75rem 0;}
  p{color:#8b95a3;line-height:1.6;margin:0 0 1.5rem;}
  a{display:inline-block;padding:.75rem 1.5rem;border:1px solid #00f0ff;color:#00f0ff;
    text-decoration:none;font-size:.8rem;letter-spacing:.1em;text-transform:uppercase;margin:0 .25rem;}
  a.ghost{border-color:rgba(232,237,242,.14);color:#8b95a3;}
</style></head>
<body><div class="box">
  <p class="tag">Error 403</p>
  <h1>This area is for store staff.</h1>
  <p>Your account does not have administrator access. You are still signed in — head back to the
     storefront, or sign out and use a staff account.</p>
  <a href="{$home}">Back to the store</a>
  <a class="ghost" href="{$signOut}">Sign out</a>
</div></body>
</html>
HTML;
    exit;
}



/* ---------- Brute-force protection ---------- */

/**
 * The visitor's IP address, as far as we can trust it.
 *
 * REMOTE_ADDR is the only value the client cannot forge. Forwarded-for headers
 * are deliberately ignored — they are trivially spoofed and would let an
 * attacker dodge the lockout by sending a new value each time.
 */
function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return is_string($ip) && $ip !== '' ? substr($ip, 0, 45) : '0.0.0.0';
}

/**
 * Record a sign-in attempt. Successes are recorded too — they reset the clock.
 *
 * A failure here must never block the sign-in flow, so errors only reach the log.
 */
function login_attempt_record(string $email, bool $wasSuccess): void
{
    $email = mb_strtolower(trim($email));

    if ($email === '') {
        return;
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO login_attempts (email, ip_address, was_success) VALUES (?, ?, ?)'
        );
        $stmt->execute([substr($email, 0, 190), client_ip(), $wasSuccess ? 1 : 0]);
    } catch (Throwable $e) {
        error_log('login_attempt_record() failed: ' . $e->getMessage());
    }
}

/**
 * Is this email locked out, and for how much longer?
 *
 * The rule: LOGIN_MAX_ATTEMPTS failures inside LOGIN_LOCKOUT_MINUTES locks the
 * email for LOGIN_LOCKOUT_MINUTES counted from the most recent failure. A
 * successful sign-in resets the run, so someone who forgot their password once
 * is not punished forever.
 *
 * The lockout is per EMAIL, not per IP, so it protects a customer whose
 * password is being guessed from many machines.
 *
 * @return array{locked: bool, minutesLeft: int, failures: int}
 */
function login_lockout_status(string $email): array
{
    $email = mb_strtolower(trim($email));

    $result = ['locked' => false, 'minutesLeft' => 0, 'failures' => 0];

    if ($email === '') {
        return $result;
    }

    $windowStart = date('Y-m-d H:i:s', time() - LOGIN_LOCKOUT_MINUTES * 60);

    try {
        $pdo = db();

        /* The last success starts a fresh run of failures. */
        $stmt = $pdo->prepare(
            'SELECT MAX(created_at) FROM login_attempts WHERE email = ? AND was_success = 1'
        );
        $stmt->execute([$email]);
        $lastSuccess = $stmt->fetchColumn();

        $since = $windowStart;
        if (is_string($lastSuccess) && $lastSuccess !== '' && $lastSuccess > $since) {
            $since = $lastSuccess;
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS failures, MAX(created_at) AS last_failure
               FROM login_attempts
              WHERE email = ? AND was_success = 0 AND created_at >= ?'
        );
        $stmt->execute([$email, $since]);
        $row = $stmt->fetch() ?: [];

        $failures = (int) ($row['failures'] ?? 0);
        $result['failures'] = $failures;

        if ($failures >= LOGIN_MAX_ATTEMPTS) {
            $result['locked'] = true;

            $lastFailure = $row['last_failure'] ?? null;
            if (is_string($lastFailure) && $lastFailure !== '') {
                $unlockAt = strtotime($lastFailure) + LOGIN_LOCKOUT_MINUTES * 60;
                $result['minutesLeft'] = max(1, (int) ceil(($unlockAt - time()) / 60));
            } else {
                $result['minutesLeft'] = LOGIN_LOCKOUT_MINUTES;
            }
        }
    } catch (Throwable $e) {
        /* Never lock someone out because of a database hiccup. */
        error_log('login_lockout_status() failed: ' . $e->getMessage());
    }

    return $result;
}

/**
 * Complete a successful sign-in for a user row.
 *
 * Regenerating the session id stops session fixation: an id planted before
 * login is useless afterwards. The $_SESSION DATA survives regeneration, which
 * is exactly why the guest-cart token is stored there (see guest_token()).
 *
 * @param array<string,mixed> $user          A row from users joined to roles.
 * @param string|null         $plainPassword The password just verified, so the
 *                                           stored hash can be upgraded if PHP's
 *                                           default algorithm has changed.
 */
function login_succeed(array $user, ?string $plainPassword = null): void
{
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int) $user['id'];

    try {
        $stmt = db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
        $stmt->execute([(int) $user['id']]);
    } catch (Throwable $e) {
        error_log('login_succeed() failed to stamp last_login_at: ' . $e->getMessage());
    }

    if ($user['role_name'] === 'admin') {
        $_SESSION['admin_last_activity'] = time();
    }

    /* Keep the stored hash current if PHP's default algorithm has moved on. */
    if ($plainPassword !== null
        && isset($user['password_hash'])
        && password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)
    ) {
        try {
            $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($plainPassword, PASSWORD_DEFAULT), (int) $user['id']]);
        } catch (Throwable $e) {
            error_log('login_succeed() failed to rehash: ' . $e->getMessage());
        }
    }
}
