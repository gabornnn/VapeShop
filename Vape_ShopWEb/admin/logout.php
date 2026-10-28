<?php
/**
 * VAPE CO. — staff sign-out.
 * Vape_ShopWEb / admin / logout.php
 *
 * Destroys the session completely: the user id, the CSRF token, the admin
 * activity stamp and the guest-cart token all go. The next page load starts a
 * brand-new session, so a shared computer cannot be left holding a cart that
 * the previous person filled.
 */

require_once __DIR__ . '/../includes/auth.php';

/* Forget the session data. */
$_SESSION = [];

/* Expire the session cookie in the browser. */
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => (bool) $params['secure'],
            'httponly' => (bool) $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]
    );
}

/* Forget the session on the server. */
session_destroy();

redirect(admin_login_url() . '?expired=1');
