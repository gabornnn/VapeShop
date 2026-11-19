<?php
/**
 * VAPE CO. — shared helper functions.
 *
 * Small, dependency-free helpers used by every page and every endpoint.
 */

require_once __DIR__ . '/../config/config.php';

/* ---------- Escaping ---------- */

/**
 * Escape a value for safe output in HTML.
 *
 * Use this on EVERY value that reaches a page. It turns `<` into `&lt;` so a
 * product name containing angle brackets cannot become markup.
 */
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ---------- Money ---------- */

/**
 * Format a peso amount, e.g. 1299 -> ₱1,299.00
 *
 * Prefers Intl\NumberFormatter. This XAMPP build ships the intl extension but
 * does NOT register the namespaced Intl\* classes, so the class_exists() check
 * is the reliable test — extension_loaded('intl') is not.
 */
function money($amount): string
{
    $amount = (float) $amount;

    if (class_exists('Intl\NumberFormatter')) {
        static $fmt = null;
        if ($fmt === null) {
            $fmt = new Intl\NumberFormatter('en-PH', [
                'style'                   => 'currency',
                'currency'                => 'PHP',
                'minimum_fraction_digits' => 2,
                'maximum_fraction_digits' => 2,
            ]);
        }
        return $fmt->format($amount);
    }

    return '₱' . number_format($amount, 2);
}

/**
 * Format a decimal for an <input type="number"> value (no thousands comma).
 */
function decimal_input($amount): string
{
    return number_format((float) $amount, 2, '.', '');
}

/**
 * Format a percentage, e.g. 0.12 -> 12%
 */
function percent($rate): string
{
    return rtrim(rtrim(number_format((float) $rate * 100, 2, '.', ''), '0'), '.') . '%';
}

/* ---------- Order totals (VAT-inclusive) ---------- */

/**
 * Compute the order totals from a gross (VAT-inclusive) subtotal.
 *
 * @return array{
 *   subtotal: float,
 *   vatRate: float,
 *   vatIncluded: bool,
 *   vatIncludedAmount: float,
 *   shipping: float,
 *   shippingFee: float,
 *   freeShippingAbove: float,
 *   amountToFreeShipping: float,
 *   total: float
 * }
 */
function order_totals(float $subtotal): array
{
    $subtotal = round(max(0.0, $subtotal), 2);
    $shipping = ($subtotal > 0 && $subtotal < (float) FREE_SHIPPING_ABOVE)
        ? (float) FLAT_SHIPPING
        : 0.0;
    $vatRate = (float) VAT_RATE;
    $vatIncludedAmount = round($subtotal - ($subtotal / (1 + $vatRate)), 2);

    return [
        'subtotal'             => $subtotal,
        'vatRate'              => $vatRate,
        'vatIncluded'          => true,
        'vatIncludedAmount'    => $vatIncludedAmount,
        'shipping'             => $shipping,
        'shippingFee'          => $shipping,
        'freeShippingAbove'    => (float) FREE_SHIPPING_ABOVE,
        'amountToFreeShipping' => round(max(0.0, (float) FREE_SHIPPING_ABOVE - $subtotal), 2),
        'total'                => round($subtotal + $shipping, 2),
    ];
}

/* ---------- Dates ---------- */

/**
 * Format a MySQL DATETIME for display, e.g. "Oct 22, 2026 10:31 PM".
 */
function db_date($value, string $format = 'M j, Y g:i A'): string
{
    if (empty($value)) {
        return '—';
    }
    $ts = strtotime((string) $value);
    return $ts ? date($format, $ts) : '—';
}

/**
 * The YYMMDD stamp used inside order numbers, e.g. VPS-261022-0007.
 */
function order_stamp(?string $placedAt = null): string
{
    $ts = $placedAt ? strtotime($placedAt) : time();
    return date('ymd', $ts ?: time());
}

/**
 * Build an order number from the order's auto-increment id.
 *
 * The id is unique by definition, so two orders can never share a reference
 * even if they are created in the same second.
 */
function order_number(int $id, ?string $placedAt = null): string
{
    return 'VPS-' . order_stamp($placedAt) . '-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
}

/* ---------- Strings ---------- */

/**
 * Turn a title into a URL-safe slug: "Nova X5000 Disposable" -> "nova-x5000-disposable".
 */
function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    return trim($text, '-');
}

/**
 * Build a unique slug by appending -2, -3, ... if it is already taken.
 *
 * @param callable|null $exists Called with the candidate slug; returns true if taken.
 */
function unique_slug(string $text, ?callable $exists = null): string
{
    $base = slugify($text);
    if ($base === '') {
        $base = 'item';
    }
    $slug = $base;
    $n = 2;
    while ($exists && $exists($slug)) {
        $slug = $base . '-' . $n++;
    }
    return $slug;
}

/**
 * Truncate a string for a card or list preview.
 */
function excerpt(?string $text, int $length = 120): string
{
    $text = trim((string) $text);
    if (mb_strlen($text) <= $length) {
        return $text;
    }
    return rtrim(mb_substr($text, 0, $length - 1)) . '…';
}

/* ---------- URLs ---------- */

/**
 * The project's URL prefix, e.g. "/Cristobal_webprog/Vape_ShopWEb".
 *
 * Empty string when the project sits at the web root.
 */
function project_root_url(): string
{
    static $url = null;
    if ($url !== null) {
        return $url;
    }

    $root = str_replace('\\', '/', dirname(__DIR__));           // physical project folder
    $doc  = str_replace('\\', '/', rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/'));

    if ($doc !== '' && strpos($root, $doc) === 0) {
        $url = str_replace('\\', '/', substr($root, strlen($doc)));
    } else {
        $url = '';
    }

    return rtrim($url, '/');
}

/**
 * A relative path back to the project root, e.g. "" at the root, "../" in /admin.
 *
 * Used for stylesheets and scripts so the same header works at any depth.
 */
function asset_base(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $scriptFile = $_SERVER['SCRIPT_FILENAME'] ?? __FILE__;
    $scriptDir  = str_replace('\\', '/', dirname(realpath($scriptFile) ?: __DIR__));
    $root       = str_replace('\\', '/', dirname(__DIR__));

    $rel   = trim(str_replace($root, '', $scriptDir), '/');
    $parts = $rel === '' ? [] : explode('/', $rel);

    return $base = str_repeat('../', count($parts));
}

/**
 * Absolute URL for an API endpoint, e.g. "/Cristobal_webprog/Vape_ShopWEb/api/cart.php".
 */
function api_url(string $path = ''): string
{
    return project_root_url() . '/api/' . ltrim($path, '/');
}

/**
 * Redirect and stop.
 */
function redirect(string $path): void
{
    if (!headers_sent()) {
        header('Location: ' . $path);
    }
    exit;
}

/**
 * Is this a safe redirect target? Only same-origin, absolute paths.
 */
function safe_next(?string $next): ?string
{
    if (!$next) {
        return null;
    }
    // Must start with a single slash and must not look like a protocol or "//host".
    if ($next[0] !== '/' || strpos($next, '//') === 0 || strpos($next, '/\\') === 0) {
        return null;
    }
    return $next;
}

/* ---------- Flash messages & old input ---------- */

/**
 * Queue a one-shot message for the next page load.
 */
function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/**
 * Read and clear the queued messages.
 *
 * @return array<int, array{type: string, message: string}>
 */
function get_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($messages) ? $messages : [];
}

/**
 * First queued flash message, or null.
 *
 * Compatibility wrapper for pages that expect a single message. New code
 * should prefer get_flashes() and render every message.
 *
 * @return array{type: string, message: string}|null
 */
function get_flash(): ?array
{
    $flashes = get_flashes();
    return $flashes[0] ?? null;
}

/**
 * Remember what the user typed so a failed form can be re-rendered with it.
 */
function remember_old(array $data): void
{
    $_SESSION['old'] = $data;
}

/**
 * Read a remembered value.
 */
function old(string $key, string $default = ''): string
{
    return (string) ($_SESSION['old'][$key] ?? $default);
}

/**
 * Forget remembered values.
 */
function clear_old(): void
{
    unset($_SESSION['old']);
}

/* ---------- Status labels ---------- */

/**
 * Human label for an order status.
 */
function order_status_label(string $status): string
{
    return [
        'PENDING'   => 'Pending',
        'PAID'      => 'Paid',
        'PACKING'   => 'Packing',
        'SHIPPED'   => 'Shipped',
        'DELIVERED' => 'Delivered',
        'CANCELLED' => 'Cancelled',
    ][$status] ?? ucfirst(strtolower($status));
}

/* ---------- Payments ---------- */

/**
 * The payment methods the storefront offers.
 *
 * @return array<string, array{label: string, icon: string, desc: string}>
 */
function payment_method_options(): array
{
    return [
        'GCASH' => [
            'label' => 'GCash',
            'icon'  => 'icon-wallet',
            'desc'  => 'Pay from your GCash wallet.',
        ],
        'MAYA' => [
            'label' => 'Maya',
            'icon'  => 'icon-wallet',
            'desc'  => 'Pay from your Maya (PayMaya) wallet.',
        ],
        'CASH' => [
            'label' => 'Cash',
            'icon'  => 'icon-cash',
            'desc'  => 'Pay in cash when your order arrives.',
        ],
    ];
}

/**
 * Map client ids to database values. Legacy ids are kept for one release.
 *
 * @return array<string, string>
 */
function payment_method_map(): array
{
    return [
        'gcash' => 'GCASH',
        'maya'  => 'MAYA',
        'cash'  => 'CASH',
        'cod'   => 'CASH',  // legacy client compatibility
        'card'  => 'CARD',  // rejected by validate_payments()
    ];
}

/**
 * Human label for a stored payment method.
 */
function payment_method_label(string $method): string
{
    $method = strtoupper($method);
    $labels = [
        'GCASH' => 'GCash',
        'MAYA'  => 'Maya',
        'CASH'  => 'Cash',
        'SPLIT' => 'Split payment',
        'CARD'  => 'Card (legacy)',
        'COD'   => 'Cash on Delivery (legacy)',
    ];
    return $labels[$method] ?? $method;
}

/**
 * One-line summary for an order's payment rows.
 *
 * @param array<int, array{method: string, amount: float|string}> $payments
 */
function payment_summary_label(array $payments): string
{
    if (!$payments) {
        return '—';
    }
    if (count($payments) === 1) {
        return payment_method_label((string) $payments[0]['method']);
    }

    $parts = array_map(
        static fn(array $p): string => payment_method_label((string) $p['method'])
            . ' ' . money((float) $p['amount']),
        $payments
    );
    return 'Split — ' . implode(' + ', $parts);
}

/**
 * Validate one or two payment allocations against the server total.
 *
 * @param array<int, mixed> $raw
 * @return array<int, array{method: string, amount: float}>
 * @throws InvalidArgumentException
 */
function validate_payments(array $raw, float $expectedTotal): array
{
    $allowed = array_keys(payment_method_options());
    if (count($raw) < 1 || count($raw) > 2) {
        throw new InvalidArgumentException('Choose one payment method or split between two.');
    }

    $payments = [];
    $seen = [];
    $sum = 0.0;

    foreach ($raw as $row) {
        if (!is_array($row)) {
            throw new InvalidArgumentException('Invalid payment entry.');
        }

        $method = strtoupper(trim((string) ($row['method'] ?? '')));
        $amount = round((float) ($row['amount'] ?? 0), 2);

        if (!in_array($method, $allowed, true)) {
            throw new InvalidArgumentException('Payment methods are limited to GCash, Maya, and Cash.');
        }
        if (isset($seen[$method])) {
            throw new InvalidArgumentException('A split payment must use two different methods.');
        }
        if ($amount <= 0) {
            throw new InvalidArgumentException('Each payment amount must be greater than zero.');
        }

        $seen[$method] = true;
        $sum += $amount;
        $payments[] = ['method' => $method, 'amount' => $amount];
    }

    $sumCents = (int) round($sum * 100);
    $totalCents = (int) round($expectedTotal * 100);
    if (abs($sumCents - $totalCents) > 1) {
        throw new InvalidArgumentException('Payment amounts must add up to the order total.');
    }

    return $payments;
}

/**
 * Fetch payment rows for many orders in one query.
 *
 * @param array<int, int|string> $orderIds
 * @return array<int, array<int, array{method: string, amount: float}>>
 */
function order_payment_rows(array $orderIds): array
{
    $orderIds = array_values(array_filter(array_map('intval', $orderIds)));
    if (!$orderIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $stmt = db()->prepare(
        "SELECT order_id, method, amount
           FROM order_payments
          WHERE order_id IN ($placeholders)
          ORDER BY id ASC"
    );
    $stmt->execute($orderIds);

    $rows = [];
    foreach ($stmt as $row) {
        $rows[(int) $row['order_id']][] = [
            'method' => (string) $row['method'],
            'amount' => (float) $row['amount'],
        ];
    }
    return $rows;
}

/**
 * Fetch payment rows for one order.
 *
 * @return array<int, array{method: string, amount: float}>
 */
function order_payments_for(int $orderId): array
{
    return order_payment_rows([$orderId])[$orderId] ?? [];
}

/* ---------- Audit log ---------- */

/**
 * Record a staff action in the audit_log table.
 *
 * Never let an audit failure break the action being audited.
 *
 * @param array<string,mixed> $details
 */
function audit_log(string $action, array $details = []): void
{
    if (!function_exists('db')) {
        return;
    }

    try {
        $entity = explode('.', $action, 2)[0] ?: 'system';
        $entityId = $details['order_id']
            ?? $details['user_id']
            ?? $details['product_id']
            ?? null;

        $stmt = db()->prepare(
            'INSERT INTO audit_log (user_id, action, entity, entity_id, details, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            $action,
            $entity,
            $entityId !== null ? (string) $entityId : null,
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('audit_log failed: ' . $e->getMessage());
    }
}
