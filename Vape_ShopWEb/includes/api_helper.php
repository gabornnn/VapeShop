<?php
/**
 * VAPE CO. — JSON response helpers for the API endpoints.
 *
 * Every endpoint answers with exactly one of two shapes:
 *
 *   success  { "ok": true,  "data": { ... } }
 *   failure  { "ok": false, "error": { "code": "...", "message": "...", "fields": { ... } } }
 *
 * A client can therefore always predict what it will get back.
 */

require_once __DIR__ . '/functions.php';

/**
 * Send a success payload and stop.
 */
function api_ok(array $data = [], int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Send an error payload and stop.
 *
 * @param array<string,string> $fields Optional per-field messages for a form.
 */
function api_fail(string $code, string $message, int $status = 400, array $fields = []): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }

    $error = ['code' => $code, 'message' => $message];
    if ($fields) {
        $error['fields'] = $fields;
    }

    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Read the request body as JSON.
 *
 * Returns an empty array for an empty body; api_fail()s on a malformed one.
 */
function api_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        api_fail('VALIDATION_ERROR', 'The request body was not valid JSON.');
    }

    return $data;
}

/**
 * The current request method.
 */
function api_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

/**
 * Read a query-string parameter.
 */
function api_query(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}
