<?php
/**
 * VAPE CO. — current session endpoint.
 * Vape_ShopWEb / api / session.php
 *
 * Returns who is signed in and the CSRF token so the client can stay in sync.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helper.php';
require_once __DIR__ . '/../includes/csrf.php';

if (api_method() !== 'GET') {
    api_fail('METHOD_NOT_ALLOWED', 'This endpoint only accepts GET requests.', 405);
}

$user = current_user_public();

api_ok([
    'user' => $user,
    'csrfToken' => csrf_token(),
]);
