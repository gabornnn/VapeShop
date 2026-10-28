<?php
/**
 * VAPE CO. — CSRF protection.
 *
 * A CSRF token proves that a form submission came from a page we served,
 * not from another site that a logged-in user happened to visit.
 *
 * How it works:
 *   1. The server generates a random token and stores it in the session.
 *   2. The token is delivered to the page (a hidden field for forms, a
 *      <meta> tag for the JavaScript API).
 *   3. Every state-changing request sends the token back.
 *   4. The server compares it with hash_equals(), which is constant-time.
 *
 * The client gets its token from includes/head.php:
 *     <meta name="csrf-token" content="...">
 *     window.__APP__.csrf = "..."
 */

require_once __DIR__ . '/functions.php';

/**
 * The current session's CSRF token, generated on first use.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * A ready-to-print hidden input for an HTML form.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Check a submitted token against the session's token.
 */
function csrf_verify(?string $token): bool
{
    $expected = $_SESSION['csrf_token'] ?? '';

    if (!is_string($expected) || $expected === '' || !is_string($token) || $token === '') {
        return false;
    }

    return hash_equals($expected, $token);
}

/**
 * The token from a form post or the X-CSRF-Token request header.
 */
function csrf_submitted_token(): ?string
{
    if (!empty($_POST['csrf_token'])) {
        return (string) $_POST['csrf_token'];
    }
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    return is_string($header) ? $header : null;
}

/**
 * Stop the request if the token is missing or wrong.
 */
function csrf_require(): void
{
    if (csrf_verify(csrf_submitted_token())) {
        return;
    }

    if (!headers_sent()) {
        http_response_code(403);
    }

    $isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;

    if ($isApi) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'    => false,
            'error' => [
                'code'    => 'CSRF_FAILED',
                'message' => 'Your session expired. Please refresh the page and try again.',
            ],
        ]);
        exit;
    }

    echo 'Invalid or missing security token. Please go back and reload the page.';
    exit;
}
