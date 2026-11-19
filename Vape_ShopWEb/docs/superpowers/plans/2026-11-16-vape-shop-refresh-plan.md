# VAPE CO. — Website Refresh Implementation Plan

Date: 2026-11-16
Design source of truth: `docs/superpowers/specs/2026-11-16-vape-shop-refresh-design.md`
Status: Proposed — no application code changes until approved
Workspace: `C:\xampp\htdocs\Cristobal_webprog\Vape_ShopWEb`

**Rule:** The design spec wins on any conflict. This plan assumes the engineer has the spec open.

**Workspace note:** This folder is not a Git repository. “Checkpoint” below means a timestamped backup of the changed files, or a Git commit only if the user initializes Git. Do not run Git commands unless the user asks.

**Build order principle:** each phase ends in a state that runs in the browser. Do not start a phase until the previous phase’s verification passes.

**Global constraints**
- PHP 8.x on XAMPP, MySQL/MariaDB, vanilla JS, no build step, no new Composer/npm dependencies.
- Use `C:\xampp\php\php.exe` and `C:\xampp\mysql\bin\mysql.exe` for local checks.
- Escape every dynamic HTML value with `e()`.
- Keep CSRF verification on every form and API mutation.
- Keep prepared statements. Never interpolate request data into SQL.
- Money is rounded to 2 decimals at every boundary.
- The server total is authoritative. Client totals are display-only.
- VAT is included in prices. No VAT line may appear in cart, checkout, receipt, account, or admin.
- Payment methods are only `GCASH`, `MAYA`, `CASH` (plus `SPLIT` as the order summary value).
- New files use the existing file style: short docblock, `require_once`, then code.
- Each task ends with the listed verification command or browser check.

---

## Phase 0 — Baseline repair (make existing pages run)

The audit found runtime defects that will block the new order/payment screens. Fix them before layering new UI on top.

### Task 0.1 — Add the missing flash and audit helpers

**Files:** `includes/functions.php`

**Steps**
1. In the “Flash messages & old input” section, immediately after `get_flashes()`, add:

```php
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
```

2. At the end of the file, add:

```php
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
```

**Verify**

```powershell
C:\xampp\php\php.exe -l includes\functions.php
C:\xampp\php\php.exe -r "require 'includes/functions.php'; var_dump(function_exists('get_flash'), function_exists('audit_log'));"
```

Expected: `bool(true) bool(true)`.

**Checkpoint:** back up `includes/functions.php`.

---

### Task 0.2 — Repair order columns and status values

**Files:** `admin/index.php`, `admin/orders.php`, `admin/order.php`, `account/orders.php`, `account/order.php`, `api/orders.php`

**Steps**
1. Replace every query/display that uses `orders.created_at` with `orders.placed_at`. In aliased queries this means `o.created_at` → `o.placed_at`. Known locations:
   - `admin/index.php`: `DATE(created_at)`, `YEARWEEK(created_at, 1)`.
   - `admin/orders.php`: date filters, `ORDER BY o.created_at DESC`, table date cell.
   - `account/orders.php`: `ORDER BY o.created_at DESC`, date cell.
   - `account/order.php`: “Placed on” cell.
   - `admin/order.php`: “Order Date” cell.
   - `api/orders.php`: any order date fields.
2. Replace every `order_status_history.changed_at` with `created_at`. Known locations:
   - `account/order.php`: history query and timeline time.
   - `admin/order.php`: history query and timeline time.
   - `api/orders.php`: history query.
3. Replace the admin status lists from `['PENDING','CONFIRMED','PROCESSING','SHIPPED','DELIVERED','CANCELLED']` to `['PENDING','PAID','PACKING','SHIPPED','DELIVERED','CANCELLED']`:
   - `admin/orders.php` filter validation and filter `<option>` loop.
   - `admin/order.php` POST validation and status `<select>` loop.
   - `admin/index.php` dashboard labels/counts.
4. Render status labels with `order_status_label($order['status'])` instead of raw `strtoupper` values in:
   - `admin/orders.php` status chip.
   - `admin/order.php` status chip and timeline.
   - `account/orders.php` status chip.
   - `account/order.php` status chip and timeline.
   - `admin/index.php` status summary.
5. Change the chip class modifier to use the real status:
   `status-chip--<?= strtolower(e($order['status'])) ?>`.

**Verify**

```powershell
Get-ChildItem admin,account,api -Recurse -File -Include *.php |
  Select-String -Pattern 'o\.created_at|orders\.created_at|changed_at|CONFIRMED|PROCESSING'
```

Expected: no matches (comments may be updated too). If a match is a legitimate non-order `created_at`, confirm it is not `users`, `products`, `reviews`, `addresses`, or `audit_log`.

**Checkpoint:** back up the six files.

---

## Phase 1 — Data model and shared domain helpers

### Task 1.1 — Add configuration constants

**File:** `config/config.php`

**Steps**
1. Replace the VAT comment with the VAT-inclusive explanation:

```php
/*
 * Prices are VAT-inclusive. This rate is only used to disclose the VAT already
 * contained in a price; it is never added to the cart or order total.
 */
define('VAT_RATE', 0.12);
```

2. After `MIN_AGE`, add:

```php
/* Terms of agreement version stored with each acceptance. */
define('TERMS_VERSION', '2026-11-16');

/*
 * Open staff registration is for initial setup only.
 * Set this to false after the first admin account exists.
 */
define('ALLOW_OPEN_ADMIN_REGISTRATION', true);
```

**Verify**

```powershell
C:\xampp\php\php.exe -l config\config.php
C:\xampp\php\php.exe -r "require 'config/config.php'; var_dump(TERMS_VERSION, ALLOW_OPEN_ADMIN_REGISTRATION);"
```

Expected: `string(10) "2026-11-16"` and `bool(true)`.

**Checkpoint:** back up `config/config.php`.

---

### Task 1.2 — Update the fresh-install schema

**File:** `database/schema.sql`

**Steps**
1. Update the header comment: “all 16 tables” → “all 17 tables”.
2. In `CREATE TABLE users`, after `last_login_at`, add:

```sql
  terms_accepted_at DATETIME DEFAULT NULL,
  terms_version     VARCHAR(20) DEFAULT NULL,
```

3. In `CREATE TABLE orders`, replace:

```sql
  payment_method  ENUM('CARD','GCASH','COD') NOT NULL,
```

with:

```sql
  payment_method  VARCHAR(20) NOT NULL,
```

4. After the `orders` table and before `order_items`, add:

```sql
-- ---------------------------------------------------------------- order payments
-- One row for a single payment, two rows for a split payment.
CREATE TABLE order_payments (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id   INT UNSIGNED NOT NULL,
  method     VARCHAR(20) NOT NULL,
  amount     DECIMAL(10,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_order_payments_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  INDEX idx_order_payments_order (order_id),
  CONSTRAINT chk_order_payments_amount CHECK (amount > 0)
) ENGINE=InnoDB;
```

**Verify**

```powershell
Select-String -Path database\schema.sql -Pattern 'terms_accepted_at|order_payments|payment_method  VARCHAR'
```

Expected: one match for each pattern.

**Checkpoint:** back up `database/schema.sql`.

---

### Task 1.3 — Add the migration for existing databases

**File to create:** `database/migrations/2026-11-16-refresh.sql`

**Content**

```sql
-- ============================================================================
--  VAPE CO. — refresh migration
--  Run once on an existing `vape_shop` database.
--
--  MySQL/MariaDB DDL auto-commits, so back up the database first:
--    C:\xampp\mysql\bin\mysqldump.exe -u root vape_shop > backup.sql
--
--  Fresh installs should import database/schema.sql instead.
-- ============================================================================

USE vape_shop;

ALTER TABLE users
  ADD COLUMN terms_accepted_at DATETIME DEFAULT NULL AFTER last_login_at,
  ADD COLUMN terms_version VARCHAR(20) DEFAULT NULL AFTER terms_accepted_at;

ALTER TABLE orders
  MODIFY payment_method VARCHAR(20) NOT NULL;

CREATE TABLE IF NOT EXISTS order_payments (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id   INT UNSIGNED NOT NULL,
  method     VARCHAR(20) NOT NULL,
  amount     DECIMAL(10,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_order_payments_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  INDEX idx_order_payments_order (order_id),
  CONSTRAINT chk_order_payments_amount CHECK (amount > 0)
) ENGINE=InnoDB;

-- Backfill old orders with one payment row each.
-- Old CARD/COD values remain readable because the column is now VARCHAR.
INSERT INTO order_payments (order_id, method, amount)
SELECT o.id, o.payment_method, o.total
  FROM orders o
 WHERE NOT EXISTS (
   SELECT 1 FROM order_payments op WHERE op.order_id = o.id
 );
```

**Verify (on a backed-up local database)**

```powershell
C:\xampp\mysql\bin\mysql.exe -u root vape_shop < database\migrations\2026-11-16-refresh.sql
C:\xampp\mysql\bin\mysql.exe -u root -e "USE vape_shop; DESCRIBE users; DESCRIBE orders; DESCRIBE order_payments;"
```

Expected: `users` has `terms_accepted_at` and `terms_version`; `orders.payment_method` is `varchar(20)`; `order_payments` exists.

**Checkpoint:** back up the database before running the migration.

---

### Task 1.4 — Add VAT-inclusive totals and payment helpers

**File:** `includes/functions.php`

**Steps**
1. Replace the existing `payment_method_map()` and `payment_method_label()` with the block below. Add `payment_method_options()`, `payment_summary_label()`, `validate_payments()`, `order_payment_rows()`, and `order_payments_for()`.
2. Add `order_totals()` near the money helpers.

```php
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
```

**Verify**

```powershell
C:\xampp\php\php.exe -l includes\functions.php
C:\xampp\php\php.exe -r "require 'includes/functions.php'; print_r(order_totals(1000));"
```

Expected: `subtotal 1000`, `shipping 150`, `total 1150`, `vatIncludedAmount 107.14`.

**Checkpoint:** back up `includes/functions.php`.

---

### Task 1.5 — Add the PHP test runner

**File to create:** `tests/run.php`

**Content**

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

$failures = 0;

function check(string $label, bool $ok): void
{
    global $failures;
    if ($ok) {
        echo "PASS  {$label}\n";
        return;
    }
    $failures++;
    echo "FAIL  {$label}\n";
}

function expect_throw(string $label, callable $fn): void
{
    try {
        $fn();
        check($label, false);
    } catch (InvalidArgumentException $e) {
        check($label, true);
    }
}

/* ---------- Pricing ---------- */

$t = order_totals(1000.00);
check('subtotal is preserved', abs($t['subtotal'] - 1000.00) < 0.001);
check('shipping is added once', abs($t['shipping'] - 150.00) < 0.001);
check('total excludes a VAT add-on', abs($t['total'] - 1150.00) < 0.001);
check('VAT included portion is 107.14', abs($t['vatIncludedAmount'] - 107.14) < 0.001);
check('free shipping applies at threshold', abs(order_totals(2000.00)['shipping']) < 0.001);
check('empty cart is zero', abs(order_totals(0)['total']) < 0.001);

/* ---------- Payments ---------- */

$p = validate_payments([
    ['method' => 'GCASH', 'amount' => 600.00],
    ['method' => 'CASH',  'amount' => 400.00],
], 1000.00);
check('split payment accepted', count($p) === 2);
check('methods are normalized', $p[0]['method'] === 'GCASH' && $p[1]['method'] === 'CASH');

expect_throw('duplicate method rejected', static fn() => validate_payments([
    ['method' => 'GCASH', 'amount' => 500.00],
    ['method' => 'GCASH', 'amount' => 500.00],
], 1000.00));

expect_throw('sum mismatch rejected', static fn() => validate_payments([
    ['method' => 'GCASH', 'amount' => 500.00],
    ['method' => 'CASH',  'amount' => 400.00],
], 1000.00));

expect_throw('card rejected', static fn() => validate_payments([
    ['method' => 'CARD', 'amount' => 1000.00],
], 1000.00));

expect_throw('zero amount rejected', static fn() => validate_payments([
    ['method' => 'GCASH', 'amount' => 1000.00],
    ['method' => 'CASH',  'amount' => 0.00],
], 1000.00));

expect_throw('three methods rejected', static fn() => validate_payments([
    ['method' => 'GCASH', 'amount' => 400.00],
    ['method' => 'MAYA',  'amount' => 300.00],
    ['method' => 'CASH',  'amount' => 300.00],
], 1000.00));

$single = validate_payments([
    ['method' => 'MAYA', 'amount' => 1000.00],
], 1000.00);
check('single payment accepted', count($single) === 1);

echo $failures === 0 ? "\nAll tests passed.\n" : "\n{$failures} test(s) failed.\n";
exit($failures === 0 ? 0 : 1);
```

**Verify**

```powershell
C:\xampp\php\php.exe tests\run.php
```

Expected: every line starts with `PASS` and the last line is `All tests passed.`

**Checkpoint:** back up `tests/`.

---

## Phase 2 — VAT-inclusive totals

### Task 2.1 — Use the shared totals helper in the cart API

**File:** `api/cart.php`

**Steps**
1. Replace the body of `cart_totals()` with:

```php
function cart_totals(array $items): array
{
    $subtotal = 0.0;
    foreach ($items as $item) {
        $subtotal += (float) $item['price'] * (int) $item['qty'];
    }

    return order_totals($subtotal);
}
```

2. Remove the now-unused local `$vat`/`$shipping` logic. The response gains `vatIncluded` and `vatIncludedAmount` and loses the old `vat` key.

**Verify**

```powershell
C:\xampp\php\php.exe -l api\cart.php
```

Add a cart item in the browser, then inspect `GET api/cart.php` in DevTools. Expected totals JSON includes `subtotal`, `shipping`, `total`; no `vat` key; `total = subtotal + shipping`.

**Checkpoint:** back up `api/cart.php`.

---

### Task 2.2 — Remove VAT from the checkout total

**File:** `api/checkout.php`

**Steps**
1. Replace lines 115–118:

```php
$vatRate    = (float) VAT_RATE;
$vatAmount  = round($subtotal * $vatRate, 2);
$shippingFee = ($subtotal > 0 && $subtotal < (float) FREE_SHIPPING_ABOVE) ? (float) FLAT_SHIPPING : 0.00;
$total      = round($subtotal + $vatAmount + $shippingFee, 2);
```

with:

```php
$totals      = order_totals($subtotal);
$subtotal    = $totals['subtotal'];
$vatRate     = $totals['vatRate'];
$vatAmount   = $totals['vatIncludedAmount']; // already inside subtotal
$shippingFee = $totals['shipping'];
$total       = $totals['total'];
```

2. Keep the existing `INSERT INTO orders` columns unchanged. `vat_amount` now stores the included portion, not an add-on.

**Verify**

```powershell
C:\xampp\php\php.exe -l api\checkout.php
```

Place a test order in the browser. Expected: confirmation total equals subtotal + shipping; database `total = subtotal + shipping_fee`.

**Checkpoint:** back up `api/checkout.php`.

---

### Task 2.3 — Remove VAT from the client totals and receipt

**File:** `app.js`

**Steps**
1. Delete `const VAT_RATE = 0.12;`.
2. Replace `totalsFromLines()` and `totalsHTML()` with:

```js
function totalsFromLines(cart = state.cart) {
  const subtotal = cart.reduce((sum, l) => sum + l.price * l.qty, 0);
  const shipping = subtotal > 0 && subtotal < FREE_SHIPPING_ABOVE ? FLAT_SHIPPING : 0;
  return { subtotal, shipping, total: subtotal + shipping };
}

function totalsHTML(cart, totals = null) {
  const t = totals || state.cartTotals || totalsFromLines(cart);
  const subtotal = Number(t.subtotal ?? cart.reduce((sum, l) => sum + l.price * l.qty, 0));
  const shipping = Number(t.shipping ?? t.shippingFee
    ?? (subtotal > 0 && subtotal < FREE_SHIPPING_ABOVE ? FLAT_SHIPPING : 0));
  const total = Number(t.total ?? (subtotal + shipping));
  return `
    <div class="totals">
      <div class="totals__row"><span>Subtotal</span><span>${money(subtotal)}</span></div>
      <div class="totals__row"><span>Shipping</span><span>${shipping === 0 ? 'FREE' : money(shipping)}</span></div>
      <div class="totals__row totals__row--grand"><span>Total</span><span>${money(total)}</span></div>
    </div>`;
}
```

3. In the receipt, change:

```js
<div class="receipt__totals">${totalsHTML(o.lines)}</div>
```

to:

```js
<div class="receipt__totals">${totalsHTML(o.lines, o.totals)}</div>
```

This makes the receipt use the order-time snapshot even after the cart is cleared.

4. Search `app.js` for `VAT` and remove the remaining VAT references.

**Verify**

```powershell
Select-String -Path app.js -Pattern 'VAT|vat'
```

Expected: no matches.

Open the drawer, checkout step 1, and the confirmation. Expected: Subtotal, Shipping, Total only.

**Checkpoint:** back up `app.js`.

---

### Task 2.4 — Remove VAT rows from account and admin views

**Files:** `account/order.php`, `admin/order.php`

**Steps**
1. In `account/order.php`, delete the VAT row:

```php
<div class="totals__row"><span>VAT (<?= ($order['vat_rate']*100) ?>%)</span><span><?= money($order['vat_amount']) ?></span></div>
```

2. In `admin/order.php`, delete the VAT row:

```php
<div style="display:flex;justify-content:space-between;"><span style="color:#8b95a3;">VAT (<?= ($order['vat_rate']*100) ?>%)</span><span><?= money($order['vat_amount']) ?></span></div>
```

3. Replace the remaining inline styles in those blocks with the classes/styles from Phase 6. For now, at minimum replace `color:#8b95a3` with `color:var(--text-dim)` and `color:#e8edf2` with `color:var(--text)` so the text is readable after the theme change.

**Verify**

```powershell
Select-String -Path account\order.php,admin\order.php -Pattern 'VAT'
```

Expected: no matches.

**Checkpoint:** back up both files.

---

## Phase 3 — Terms and registration

### Task 3.1 — Create the Terms & Policy page

**File to create:** `terms.php`

**Structure**

```php
<?php
/**
 * VAPE CO. — Terms & Policy.
 * Vape_ShopWEb / terms.php
 */

require_once __DIR__ . '/includes/functions.php';

$pageTitle       = 'Terms & Policy — ' . APP_NAME;
$bodyClass       = 'page-legal';
$metaDescription = 'Terms, age policy, payment, shipping, and privacy information for ' . APP_NAME . '.';
$loadApp         = false;

require_once __DIR__ . '/includes/head.php';
?>
<main class="legal">
  <div class="legal__container">
    <p class="micro-label">Legal</p>
    <h1 class="legal__title">Terms &amp; Policy</h1>
    <p class="legal__meta">Last updated: November 16, 2026 · Version <?= e(TERMS_VERSION) ?></p>

    <nav class="legal__toc" aria-label="On this page">
      <ol>
        <li><a href="#acceptance">Acceptance</a></li>
        <li><a href="#age">Age requirement</a></li>
        <li><a href="#products">Products and nicotine warning</a></li>
        <li><a href="#accounts">Accounts and security</a></li>
        <li><a href="#orders">Orders and pricing</a></li>
        <li><a href="#payment">Payment</a></li>
        <li><a href="#shipping">Shipping and delivery</a></li>
        <li><a href="#returns">Returns, defects, and warranty</a></li>
        <li><a href="#prohibited">Prohibited use</a></li>
        <li><a href="#privacy">Privacy</a></li>
        <li><a href="#liability">Liability</a></li>
        <li><a href="#changes">Changes to these terms</a></li>
        <li><a href="#contact">Contact</a></li>
      </ol>
    </nav>

    <section class="legal__section" id="acceptance">
      <h2>1. Acceptance</h2>
      <p>By creating an account, accessing, or ordering from <?= e(APP_NAME) ?>, you agree to these Terms &amp; Policy. If you do not agree, do not use the site or place an order.</p>
    </section>

    <!-- Add sections 2–13 using the copy in the design spec §10. -->
  </div>
</main>
<?php require_once __DIR__ . '/includes/foot.php'; ?>
```

**Steps**
1. Create the file with the structure above.
2. Fill sections 2–13 with the exact copy from the design spec §10. Keep the section IDs shown in the table of contents.
3. Use `<strong>` for the contact labels and a `.legal__contact` card for the contact block.
4. Do not load `app.js` (`$loadApp = false`).
5. Do not add a checkout terms checkbox.

**Verify**

```powershell
C:\xampp\php\php.exe -l terms.php
```

Open `terms.php`. Expected: readable light page, working anchor links, no console errors, “Last updated” and version visible.

**Checkpoint:** back up `terms.php`.

---

### Task 3.2 — Require terms acceptance on customer registration

**File:** `register.php`

**Steps**
1. Add `$terms = false;` next to the other field variables.
2. In the POST handler, read `$terms = isset($_POST['terms']);`.
3. After the password confirmation validation, add:

```php
if (!$terms) {
    $errors['terms'] = 'You must agree to the Terms & Policy to create an account.';
}
```

4. Change the `INSERT INTO users` statement to:

```php
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
```

5. Before the submit button, add:

```php
<div class="field<?= isset($errors['terms']) ? ' has-error' : '' ?>">
  <label class="check-field" for="terms">
    <input id="terms" name="terms" type="checkbox" value="1" <?= $terms ? 'checked' : '' ?>
           <?= isset($errors['terms']) ? 'aria-invalid="true" aria-describedby="terms-error"' : '' ?>>
    <span>I agree to the <a href="terms.php" target="_blank" rel="noopener">Terms &amp; Policy</a> and confirm I am 21 or older.</span>
  </label>
  <span class="field__error" id="terms-error"<?= isset($errors['terms']) ? '' : ' hidden' ?>><?= e($errors['terms'] ?? '') ?></span>
</div>
```

6. In the auth footer, add a direct link:

```php
<p class="auth__alt"><a href="terms.php">Read the Terms &amp; Policy</a></p>
```

**Verify**

1. Submit the form without the checkbox. Expected: “You must agree…” shown; no user inserted.
2. Submit with the checkbox. Expected: account created and signed in.
3. SQL check:

```sql
SELECT email, terms_accepted_at, terms_version FROM users ORDER BY id DESC LIMIT 1;
```

Expected: timestamp and `2026-11-16`.

**Checkpoint:** back up `register.php`.

---

### Task 3.3 — Create admin registration

**File to create:** `admin/register.php`

**Steps**
1. Copy the structure of `register.php`, but:
   - Require `../includes/auth.php`, `../includes/functions.php`, `../includes/csrf.php`.
   - Set `$bodyClass = 'page-auth'`, `$loadApp = false`.
   - Use `admin/`-relative links (`login.php`, `../index.php`, `../terms.php`).
2. At the top, after requires:

```php
if (!ALLOW_OPEN_ADMIN_REGISTRATION) {
    http_response_code(403);
    $pageTitle = 'Staff registration closed — ' . APP_NAME;
    $bodyClass = 'page-auth';
    $loadApp   = false;
    require_once __DIR__ . '/../includes/head.php';
    ?>
    <div class="auth">
      <main class="auth__main">
        <a class="wordmark wordmark--sm auth__brand" href="../index.php">VAPE<span class="wordmark__accent">CO.</span></a>
        <div class="auth__inner">
          <p class="micro-label">Staff</p>
          <h1 class="auth__title">Staff registration is closed</h1>
          <p class="auth__lede">Ask an existing admin to create your account, or sign in if you already have one.</p>
          <a class="btn btn--accent btn--lg btn--block" href="login.php">Staff sign in</a>
        </div>
      </main>
      <aside class="auth__panel" aria-hidden="true">
        <span class="auth__panel-mark">VAPE<span>CO.</span></span>
        <span class="auth__panel-age">21+</span>
      </aside>
    </div>
    <?php
    require_once __DIR__ . '/../includes/foot.php';
    exit;
}

if (is_admin()) {
    redirect('index.php');
}

if (is_logged_in()) {
    flash('Sign out of your customer account before registering as staff.', 'error');
    redirect('../index.php');
}
```

3. Use the same field variables and validation as `register.php`:
   - first name required
   - last name required
   - email required, valid, unique
   - phone required
   - birthdate required and 21+
   - password ≥ 8 chars with a letter and a number
   - confirm password matches
   - terms required
4. In the success branch, use the `admin` role and the same `terms_accepted_at`/`terms_version` insert:

```php
$roleStmt = $pdo->prepare("SELECT id FROM roles WHERE role_name = 'admin' LIMIT 1");
$roleStmt->execute();
$roleId = $roleStmt->fetchColumn();
if (!$roleId) {
    throw new RuntimeException('Admin role not found');
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

flash('Admin account created. Welcome to the staff panel.', 'success');
redirect('index.php');
```

5. Add the terms checkbox from Task 3.2, with the message:
   “I agree to the Terms & Policy and confirm I am 21 or older.”
6. Add the warning note above the form:

```php
<div class="auth__note" role="status">
  <svg aria-hidden="true"><use href="#icon-box"></use></svg>
  <span>Open staff registration is enabled for initial setup. After creating the first admin, set <code>ALLOW_OPEN_ADMIN_REGISTRATION</code> to <code>false</code> in <code>config/config.php</code>.</span>
</div>
```

7. Add the page title “Create staff account — VAPE CO.” and an auth footer link back to `login.php`.

**Verify**

1. Open `admin/login.php` (link added in Task 3.4) → “Create an admin account” → form loads.
2. Submit without terms → error; no user inserted.
3. Submit with terms → admin created, signed in, redirected to dashboard.
4. SQL check:

```sql
SELECT u.email, r.role_name, u.terms_accepted_at, u.terms_version
FROM users u JOIN roles r ON r.id = u.role_id
ORDER BY u.id DESC LIMIT 1;
```

Expected: `role_name = admin`, acceptance timestamp, version `2026-11-16`.

**Checkpoint:** back up `admin/register.php`.

---

### Task 3.4 — Link the new pages

**Files:** `index.php`, `register.php`, `admin/login.php`

**Steps**
1. `index.php` footer, after the age line:

```php
<p class="site-footer__legal">
  <a href="terms.php">Terms &amp; Policy</a>
</p>
```

2. `admin/login.php`, in the `.auth__alt` block, add:

```php
<p class="auth__alt">
  New staff? <a href="register.php">Create an admin account</a>
</p>
```

3. `register.php`: the terms link and auth footer link are added in Task 3.2.

**Verify**

Open `index.php`, `register.php`, `admin/login.php`. Expected: every terms link opens `terms.php`; the admin registration link opens the form.

**Checkpoint:** back up the three files.

---

## Phase 4 — Age gate on refresh only

### Task 4.1 — Change the age gate storage and navigation logic

**File:** `app.js`

**Steps**
1. Keep `AGE_KEY = 'ageVerified'`, but add a session helper below the existing `storage` object:

```js
/** Age verification is per-tab and per-session. */
const ageStorage = {
  get() {
    try { return sessionStorage.getItem(AGE_KEY) === '1'; } catch { return false; }
  },
  set() {
    try { sessionStorage.setItem(AGE_KEY, '1'); } catch { /* private mode */ }
  },
};
```

2. Replace `initAgeGate()` with:

```js
/**
 * True only when this page load is a hard refresh.
 * Fail closed (show the gate) when the browser cannot tell us.
 */
function isReloadNavigation() {
  try {
    const nav = performance.getEntriesByType('navigation')[0];
    if (nav && typeof nav.type === 'string') {
      return nav.type === 'reload';
    }
  } catch { /* fall through */ }

  try {
    return performance.navigation?.type === 1;
  } catch { /* fall through */ }

  return true;
}

function initAgeGate() {
  const mustShow = isReloadNavigation() || !ageStorage.get();
  if (!mustShow) {
    ageGateEl.hidden = true;
    document.body.classList.remove('gate-open');
    return;
  }

  document.body.classList.add('gate-open');
  ageGateEl.hidden = false;
  lockLayer(ageGateEl);
  ageGate.open();
}
```

3. In the “I am 21 or older” handler, replace `storage.set(AGE_KEY, true);` with:

```js
ageStorage.set();
try { localStorage.removeItem(AGE_KEY); } catch { /* ignore */ }
```

4. Add a bfcache handler after the age gate listeners:

```js
/* Back/Forward cache: never re-open the gate on a restored page. */
window.addEventListener('pageshow', (event) => {
  if (!event.persisted) return;
  ageGate.close();
  ageGateEl.hidden = true;
  document.body.classList.remove('gate-open');
});
```

5. Remove the now-unused localStorage age key from the top-of-file comment if it mentions `ageVerified`; the session key has the same name.

**Verify (manual, in this order)**
1. Fresh tab → gate shows.
2. “I am 21 or older” → gate hides.
3. Hard refresh (Ctrl+F5) → gate shows again.
4. Verify again → navigate to `login.php` → browser Back to `index.php` → gate does not show.
5. Verify again → click an in-site link → gate does not show.
6. Open a new tab to `index.php` → gate shows.
7. “I am under 21” → denial panel; refresh → gate shows again.
8. Disable site data / use a private window → gate shows; no console error.

**Checkpoint:** back up `app.js`.

---

### Task 4.2 — Restyle the age gate for the light theme

**File:** `style.css` (the `.age-gate` section)

**Steps**
1. Replace the dark scrim/blur with:

```css
.age-gate {
  position: fixed;
  inset: 0;
  z-index: 120;
  display: grid;
  place-items: center;
  padding: var(--s4);
  background: rgba(247, 248, 245, .94);
}

body.gate-open #page {
  filter: blur(4px);
}

.age-gate__inner {
  width: min(100%, 520px);
  padding: var(--s6) var(--s4);
  text-align: center;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-md);
}

.age-gate__age {
  margin: var(--s3) 0 var(--s2);
  font-family: var(--font-display);
  font-size: clamp(4rem, 12vw, 7rem);
  line-height: 1;
  font-weight: 700;
  color: var(--accent-soft);
  -webkit-text-stroke: 2px var(--accent);
}

.age-gate__statement {
  margin: 0 0 var(--s4);
  font-family: var(--font-display);
  font-size: clamp(1.4rem, 4vw, 2rem);
  line-height: 1.2;
  color: var(--text);
}

.age-gate__actions {
  display: flex;
  flex-direction: column;
  gap: var(--s2);
}

@media (min-width: 560px) {
  .age-gate__actions {
    flex-direction: row;
    justify-content: center;
  }
}
```

2. Remove any remaining neon glow/`text-shadow` on `.age-gate__age`.
3. Keep the existing `is-leaving` transition and the denied panel, but use `--danger-soft`/`--danger` for the denial text.

**Verify:** gate shows on refresh; light overlay; buttons stack at 360px; no neon.

**Checkpoint:** back up `style.css`.

---

## Phase 5 — Split payments

### Task 5.1 — Accept and validate split payments in the checkout API

**File:** `api/checkout.php`

**Steps**
1. Replace the top payment parsing (lines 17–26) with:

```php
$body         = api_body();
$shipping     = is_array($body['shipping'] ?? null) ? $body['shipping'] : [];
$rawPayments  = is_array($body['payments'] ?? null) ? $body['payments'] : [];
$legacyMethod = strtoupper(trim((string) ($body['paymentMethod'] ?? '')));
$legacyMethod = payment_method_map()[strtolower($legacyMethod)] ?? $legacyMethod;
```

2. After the item loop and the new totals block from Task 2.2, add:

```php
if (!$rawPayments && $legacyMethod !== '') {
    $rawPayments = [[
        'method' => $legacyMethod,
        'amount' => $total,
    ]];
}

try {
    $payments = validate_payments($rawPayments, $total);
} catch (InvalidArgumentException $e) {
    $pdo->rollBack();
    api_fail('VALIDATION_ERROR', $e->getMessage(), 400, ['payments' => $e->getMessage()]);
}

$paymentMethod = count($payments) === 1 ? $payments[0]['method'] : 'SPLIT';
```

3. Change the order insert’s `$paymentMethod` argument to the new summary value. The SQL column list does not change.
4. After the order items insert (around line 168), add:

```php
// Payment breakdown (1 row for single, 2 rows for split).
$pstmt = $pdo->prepare(
    'INSERT INTO order_payments (order_id, method, amount) VALUES (?, ?, ?)'
);
foreach ($payments as $payment) {
    $pstmt->execute([$orderId, $payment['method'], $payment['amount']]);
}
```

5. Replace the response with:

```php
api_ok([
    'orderId'      => $orderId,
    'orderNumber'  => $ref,
    'total'        => $total,
    'payments'     => $payments,
    'paymentLabel' => payment_summary_label($payments),
]);
```

**Edge behavior**
- `CARD` is in the legacy map but not in `payment_method_options()`, so `validate_payments()` rejects it.
- `COD` maps to `CASH` for one release.
- If `payments` is present, `paymentMethod` is ignored.
- The client total is ignored; `validate_payments()` uses the server `$total`.

**Verify**

```powershell
C:\xampp\php\php.exe -l api\checkout.php
```

Use the browser flow in Task 5.7. Then:

```sql
SELECT o.order_number, o.payment_method, o.total,
       op.method, op.amount
FROM orders o
JOIN order_payments op ON op.order_id = o.id
WHERE o.order_number = 'VPS-...';
```

Expected: one row for a single payment, two rows for a split; amounts sum to `o.total`; split summary is `SPLIT`.

**Checkpoint:** back up `api/checkout.php`.

---

### Task 5.2 — Add split-payment state and helpers to `app.js`

**File:** `app.js`

**Steps**
1. Replace `PAYMENT_METHODS` with:

```js
const PAYMENT_METHODS = [
  {
    id: 'gcash',
    method: 'GCASH',
    label: 'GCash',
    icon: 'icon-wallet',
    desc: 'Pay from your GCash wallet.',
    hint: 'You will receive payment instructions after placing the order.',
  },
  {
    id: 'maya',
    method: 'MAYA',
    label: 'Maya',
    icon: 'icon-wallet',
    desc: 'Pay from your Maya (PayMaya) wallet.',
    hint: 'You will receive payment instructions after placing the order.',
  },
  {
    id: 'cash',
    method: 'CASH',
    label: 'Cash',
    icon: 'icon-cash',
    desc: 'Pay in cash when your order arrives.',
    hint: 'Please prepare exact change if possible.',
  },
];
```

2. Add these properties to `state.checkout`:

```js
paymentMode: 'single', // 'single' | 'split'
split: {
  methods: ['GCASH', 'MAYA'],
  amounts: ['', ''],
},
```

3. In `closeCheckout({ reset: true })`, replace the reset object with:

```js
state.checkout = {
  step: 1,
  shipping: { ...EMPTY_SHIPPING },
  payment: '',
  paymentMode: 'single',
  split: { methods: ['GCASH', 'MAYA'], amounts: ['', ''] },
  submitting: false,
  order: null,
};
```

4. In `openCheckout()`, after `state.checkout.order = null;`, reset the payment state:

```js
state.checkout.payment = '';
state.checkout.paymentMode = 'single';
state.checkout.split = { methods: ['GCASH', 'MAYA'], amounts: ['', ''] };
```

5. Add the helpers near `totalsHTML()`:

```js
function checkoutTotal() {
  const t = state.cartTotals || totalsFromLines(state.cart);
  return Math.round(Number(t.total || 0) * 100) / 100;
}

function paymentMethodLabel(method) {
  return PAYMENT_METHODS.find((m) => m.method === method)?.label || method;
}

function defaultSplitAmounts() {
  const total = checkoutTotal();
  const first = Math.round((total / 2) * 100) / 100;
  const second = Math.max(0, Math.round((total - first) * 100) / 100);
  return [first.toFixed(2), second.toFixed(2)];
}

function resetSplit() {
  state.checkout.split = {
    methods: ['GCASH', 'MAYA'],
    amounts: defaultSplitAmounts(),
  };
}

function splitRemaining() {
  const total = checkoutTotal();
  const [a, b] = state.checkout.split.amounts.map((v) => Number(v) || 0);
  return Math.round((total - a - b) * 100) / 100;
}

function validatePayment() {
  const c = state.checkout;
  if (c.paymentMode === 'single') {
    return c.payment ? '' : 'Choose a payment method to continue.';
  }

  const [m1, m2] = c.split.methods;
  if (!m1 || !m2) return 'Choose two payment methods.';
  if (m1 === m2) return 'Choose two different payment methods.';

  const [a, b] = c.split.amounts.map((v) => Number(v) || 0);
  if (a <= 0 || b <= 0) return 'Both split amounts must be greater than zero.';
  if (Math.abs((a + b) - checkoutTotal()) > 0.01) {
    return 'Split amounts must add up to the order total.';
  }
  return '';
}

function paymentsPayload() {
  const c = state.checkout;
  if (c.paymentMode === 'split') {
    return c.split.methods.map((method, i) => ({
      method,
      amount: Math.round((Number(c.split.amounts[i]) || 0) * 100) / 100,
    }));
  }

  const method = PAYMENT_METHODS.find((m) => m.id === c.payment)?.method || 'CASH';
  return [{ method, amount: checkoutTotal() }];
}
```

**Checkpoint:** back up `app.js`.

---

### Task 5.3 — Replace checkout step 3 with single/split UI

**File:** `app.js` (`renderCheckout`, step 3 block)

**Steps**
1. Replace the entire `if (c.step === 3) { ... }` body with:

```js
if (c.step === 3) {
  const split = c.paymentMode === 'split';
  if (split && (!c.split.amounts[0] || !c.split.amounts[1])) {
    c.split.amounts = defaultSplitAmounts();
  }

  const remaining = splitRemaining();
  const remainingLabel = remaining < -0.01
    ? `Over by ${money(Math.abs(remaining))}`
    : `Remaining: ${money(Math.max(0, remaining))}`;

  body = `
    <div class="checkout__panel">
      <div class="pay-mode" role="group" aria-label="Payment mode">
        <button type="button" class="pay-mode__btn${!split ? ' is-active' : ''}" data-pay-mode="single">Single payment</button>
        <button type="button" class="pay-mode__btn${split ? ' is-active' : ''}" data-pay-mode="split">Split payment</button>
      </div>

      ${!split ? `
        <p class="micro-label" style="margin: var(--s4) 0 var(--s3);">Select payment method</p>
        <div class="pay-grid">
          ${PAYMENT_METHODS.map((m) => `
            <label class="pay-card${c.payment === m.id ? ' is-selected' : ''}">
              <input type="radio" name="payment" value="${m.id}" ${c.payment === m.id ? 'checked' : ''}>
              <span class="pay-card__icon"><svg aria-hidden="true"><use href="#${m.icon}"></use></svg></span>
              <span class="pay-card__content">
                <span class="pay-card__name">${esc(m.label)}</span>
                <span class="pay-card__desc">${esc(m.desc)}</span>
                <span class="pay-card__hint">${esc(m.hint)}</span>
              </span>
            </label>`).join('')}
        </div>` : `
        <p class="micro-label" style="margin: var(--s4) 0 var(--s3);">Split between two methods</p>
        <div class="split-pay">
          ${[0, 1].map((i) => `
            <div class="split-pay__leg">
              <div class="split-pay__row">
                <label class="field__label" for="split-method-${i}">Method ${i + 1}</label>
                <select id="split-method-${i}" data-split-method="${i}" class="split-pay__select">
                  ${PAYMENT_METHODS.map((m) => `
                    <option value="${m.method}" ${c.split.methods[i] === m.method ? 'selected' : ''}>${esc(m.label)}</option>
                  `).join('')}
                </select>
              </div>
              <div class="split-pay__row">
                <label class="field__label" for="split-amount-${i}">Amount</label>
                <input id="split-amount-${i}" data-split-amount="${i}" class="split-pay__amount"
                       type="number" inputmode="decimal" min="0.01" step="0.01"
                       value="${esc(c.split.amounts[i] ?? '')}">
              </div>
            </div>`).join('')}
        </div>
        <p class="split-pay__remaining" aria-live="polite">${remainingLabel}</p>
        <p class="field__hint">The two amounts must add up to ${money(checkoutTotal())}. Change one amount and the other updates automatically.</p>`}

      <p class="field__error" id="pay-error" style="margin-top: var(--s3);" hidden></p>
      <p class="field__hint" style="margin-top: var(--s3);">No card numbers are collected. Payment is recorded for this demo.</p>
    </div>`;

  foot = `
    <button class="btn btn--ghost" data-co="back">
      <svg width="16" height="16" aria-hidden="true"><use href="#icon-arrow-left"></use></svg> Back
    </button>
    <button class="btn btn--accent" data-co="place"${c.submitting ? ' disabled' : ''}>Place Order</button>`;
}
```

2. Remove the old “Select payment method” micro-label and “Demo only — no card numbers” markup after the old pay grid; it is replaced above.

**Checkpoint:** back up `app.js`.

---

### Task 5.4 — Wire payment-mode events and update `placeOrder`

**File:** `app.js`

**Steps**
1. In the checkout click handler, add this before the `[data-co]` block (or immediately after it, before the `[data-action]` block):

```js
const modeBtn = e.target.closest('[data-pay-mode]');
if (modeBtn) {
  const mode = modeBtn.dataset.payMode;
  if (mode === 'split') {
    state.checkout.paymentMode = 'split';
    if (!state.checkout.split.amounts[0] || !state.checkout.split.amounts[1]) {
      resetSplit();
    }
  } else {
    state.checkout.paymentMode = 'single';
  }
  const err = $('#pay-error', checkoutEl);
  if (err) err.hidden = true;
  renderCheckout();
  return;
}
```

2. In the existing `change` handler, add split-method handling:

```js
if (e.target.matches('[data-split-method]')) {
  const i = Number(e.target.dataset.splitMethod);
  state.checkout.split.methods[i] = e.target.value;
  const err = $('#pay-error', checkoutEl);
  if (err) err.hidden = true;
  return;
}
```

3. In the same `change` handler, keep the existing `name === 'payment'` logic but guard it for single mode:

```js
if (e.target.name === 'payment' && state.checkout.paymentMode === 'single') {
  state.checkout.payment = e.target.value;
  const err = $('#pay-error', checkoutEl);
  if (err) err.hidden = true;
  $$('.pay-card', checkoutEl).forEach((card) => {
    card.classList.toggle('is-selected', $('input', card).value === state.checkout.payment);
  });
}
```

4. Add an `input` handler for split amounts inside the existing input listener:

```js
if (e.target.matches('[data-split-amount]')) {
  const c = state.checkout;
  const i = Number(e.target.dataset.splitAmount);
  const other = i === 0 ? 1 : 0;
  c.split.amounts[i] = e.target.value;

  const total = checkoutTotal();
  const entered = Number(e.target.value) || 0;
  const remaining = Math.max(0, Math.round((total - entered) * 100) / 100);
  const otherInput = $(`[data-split-amount="${other}"]`, checkoutEl);
  if (otherInput) {
    otherInput.value = remaining.toFixed(2);
    c.split.amounts[other] = otherInput.value;
  }

  const remainingEl = $('.split-pay__remaining', checkoutEl);
  if (remainingEl) {
    const left = splitRemaining();
    remainingEl.textContent = left < -0.01
      ? `Over by ${money(Math.abs(left))}`
      : `Remaining: ${money(Math.max(0, left))}`;
  }

  const err = $('#pay-error', checkoutEl);
  if (err) err.hidden = true;
  return;
}
```

5. Replace the top of `placeOrder(btn)` payment guard:

```js
const paymentError = validatePayment();
if (paymentError) {
  const err = $('#pay-error', checkoutEl);
  if (err) {
    err.textContent = paymentError;
    err.hidden = false;
  }
  if (state.checkout.paymentMode === 'single') {
    $('.pay-card input', checkoutEl)?.focus();
  } else {
    $('[data-split-amount]', checkoutEl)?.focus();
  }
  return;
}
```

6. Replace the final validation condition that uses `!c.payment` with `validatePayment()`:

```js
const errs = validateShippingAll();
const payErr = validatePayment();
if (errs.some(Boolean) || !state.cart.length || payErr) {
  c.submitting = false;
  btn.disabled = false;
  btn.classList.remove('is-loading');
  if (errs.some(Boolean)) {
    c.step = 2;
    renderCheckout();
    ['name', 'phone', 'address'].forEach((f, i) => setFieldError(f, errs[i]));
    const firstBad = ['name', 'phone', 'address'].find((f, i) => errs[i]);
    $(`[data-field="${firstBad}"] input`, checkoutEl)?.focus();
    return;
  }
  renderCheckout();
  const err = $('#pay-error', checkoutEl);
  if (err) {
    err.textContent = payErr;
    err.hidden = false;
  }
  return;
}
```

7. Replace the request body and receipt snapshot:

```js
const payments = paymentsPayload();
const paymentLabel = state.checkout.paymentMode === 'split'
  ? 'Split — ' + payments
      .map((p) => `${paymentMethodLabel(p.method)} ${money(p.amount)}`)
      .join(' + ')
  : paymentMethodLabel(payments[0].method);

const data = await API.post('checkout.php', {
  shipping: {
    name: c.shipping.name,
    phone: c.shipping.phone,
    address: c.shipping.address,
    notes: c.shipping.notes || '',
  },
  payments,
});

c.order = {
  ref: data.orderNumber || makeRef(),
  dateLabel: new Date().toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' }),
  recipient: shortName(c.shipping.name),
  paymentLabel: data.paymentLabel || paymentLabel,
  lines: state.cart.map((l) => ({ ...l })),
  totals: state.cartTotals || totalsFromLines(state.cart),
};
```

8. In the success cleanup, reset payment mode too:

```js
c.payment = '';
c.paymentMode = 'single';
c.split = { methods: ['GCASH', 'MAYA'], amounts: ['', ''] };
```

**Verify**

```powershell
Select-String -Path app.js -Pattern 'paymentMap|CARD|COD|VAT'
```

Expected: no legacy payment map, no `CARD`/`COD` in the client, no VAT.

**Checkpoint:** back up `app.js`.

---

### Task 5.5 — Show the payment breakdown in account and admin order views

**Files:** `admin/orders.php`, `admin/order.php`, `account/order.php`, `api/orders.php`

**Steps**
1. `admin/orders.php`, after `$orders = $stmt->fetchAll();`, add:

```php
$paymentsByOrder = order_payment_rows(array_column($orders, 'id'));
```

2. Replace the payment cell:

```php
<td><?= e($order['payment_method']) ?></td>
```

with:

```php
<?php $pays = $paymentsByOrder[(int) $order['id']] ?? []; ?>
<td>
  <span class="pay-summary">
    <?= e($pays ? payment_summary_label($pays) : payment_method_label((string) $order['payment_method'])) ?>
  </span>
</td>
```

3. `admin/order.php`, after loading the order, add:

```php
$payments = order_payments_for($orderId);
```

4. In the totals block, after the Shipping row and before Total, add:

```php
<div class="pay-breakdown">
  <p class="micro-label">Payment</p>
  <?php if ($payments): ?>
    <?php foreach ($payments as $payment): ?>
      <div class="pay-breakdown__row">
        <span><?= e(payment_method_label($payment['method'])) ?></span>
        <span><?= money($payment['amount']) ?></span>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <div class="pay-breakdown__row">
      <span><?= e(payment_method_label((string) $order['payment_method'])) ?></span>
      <span><?= money($order['total']) ?></span>
    </div>
  <?php endif; ?>
</div>
```

5. Replace the “Payment Method” row in Order Info with:

```php
<div style="display:flex;justify-content:space-between;">
  <span style="color:var(--text-dim);">Payment Method</span>
  <span><?= e($payments ? payment_summary_label($payments) : payment_method_label((string) $order['payment_method'])) ?></span>
</div>
```

6. `account/order.php`, after loading the order, add:

```php
$payments = order_payments_for($orderId);
```

7. In the totals block, add the same `.pay-breakdown` block after Shipping and before Total.
8. Replace the “Payment: …” line in Order Status with:

```php
<p class="order-meta">Payment: <?= e($payments ? payment_summary_label($payments) : payment_method_label((string) $order['payment_method'])) ?></p>
```

9. `api/orders.php`, after the items query, add:

```php
$payments = order_payments_for($orderId);
```

Include `'payments' => $payments` and `'paymentLabel' => payment_summary_label($payments)` in the order-detail JSON response. If the endpoint returns a totals object, do not add a `vat` key.

**Verify**

Place one single-payment order and one split order. Open:
- `admin/orders.php` — compact summary in the Payment column.
- `admin/order.php?id=...` — two payment rows for a split.
- `account/order.php?id=...` — two payment rows for a split.
Expected: amounts sum to the order total; no VAT row.

**Checkpoint:** back up the four files.

---

### Task 5.6 — Add split-payment CSS

**File:** `style.css`

Append (or place near the checkout section):

```css
.pay-mode {
  display: inline-flex;
  gap: 4px;
  padding: 4px;
  background: var(--surface-2);
  border-radius: var(--radius-sm);
}

.pay-mode__btn {
  border: 0;
  background: transparent;
  color: var(--text-dim);
  padding: 10px 16px;
  border-radius: 6px;
  font: inherit;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
}

.pay-mode__btn.is-active {
  background: var(--surface);
  color: var(--text);
  box-shadow: var(--shadow-sm);
}

.pay-mode__btn:focus-visible {
  outline: 2px solid var(--focus);
  outline-offset: 2px;
}

.split-pay {
  display: grid;
  gap: var(--s3);
}

.split-pay__leg {
  display: grid;
  gap: var(--s2);
  padding: var(--s3);
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
}

.split-pay__row {
  display: grid;
  gap: 6px;
}

.split-pay__select,
.split-pay__amount {
  width: 100%;
  min-height: 44px;
  padding: 10px 12px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--surface);
  color: var(--text);
  font: inherit;
}

.split-pay__select:focus-visible,
.split-pay__amount:focus-visible {
  outline: 2px solid var(--focus);
  outline-offset: 1px;
}

.split-pay__remaining {
  margin: var(--s2) 0 0;
  font-size: 14px;
  color: var(--text-dim);
}

.pay-breakdown {
  display: grid;
  gap: 6px;
  padding-top: var(--s3);
  margin-top: var(--s3);
  border-top: 1px solid var(--border);
}

.pay-breakdown__row {
  display: flex;
  justify-content: space-between;
  gap: var(--s2);
  font-size: 14px;
}

.pay-summary {
  display: inline-block;
  max-width: 28ch;
  line-height: 1.35;
}
```

**Verify:** step 3 split mode stacks cleanly at 360px; the selected mode is clear; amounts remain readable.

**Checkpoint:** back up `style.css`.

---

## Phase 6 — Light-minimal theme and general UI polish

### Task 6.1 — Replace the dark token block

**File:** `style.css`

**Steps**
1. Change the header comment from “Neon / Cyber design system” to “Light minimal design system”.
2. Replace the `:root` block (lines 23–60) with:

```css
:root {
  --bg: #f7f8f5;
  --surface: #ffffff;
  --surface-2: #eef1ec;
  --surface-3: #e2e7e1;
  --text: #1f2521;
  --text-dim: #5b665e;
  --text-soft: #7a857d;
  --accent: #2f6f4e;
  --accent-hover: #265a3f;
  --accent-soft: #e4f0e9;
  --accent-2: #6b8f7a;
  --accent-contrast: #ffffff;
  --danger: #b3261e;
  --danger-soft: #fbe9e7;
  --warning: #8a5a00;
  --warning-soft: #fff4d6;
  --info: #2f5d7a;
  --info-soft: #e6f0f6;

  --border: rgba(31, 37, 33, .12);
  --border-strong: rgba(31, 37, 33, .24);
  --border-accent: rgba(47, 111, 78, .24);
  --focus: #2f6f4e;
  --glow-accent: none;

  --shadow-sm: 0 1px 2px rgba(31, 37, 33, .06);
  --shadow-md: 0 10px 30px rgba(31, 37, 33, .10);

  /* 8px spacing scale */
  --s1: 4px;
  --s2: 8px;
  --s3: 16px;
  --s4: 24px;
  --s5: 32px;
  --s6: 48px;
  --s7: 64px;

  --radius: 12px;
  --radius-sm: 8px;
  --radius-lg: 16px;

  --t-fast: 150ms;
  --t-med: 300ms;
  --t-reveal: 400ms;
  --ease: ease-out;

  --header-h: 64px;
  --drawer-w: 420px;

  --font-display: "Space Grotesk", "Segoe UI", system-ui, sans-serif;
  --font-body: "Inter", "Segoe UI", system-ui, sans-serif;
  --font-mono: ui-monospace, "Cascadia Mono", "Consolas", monospace;
}
```

3. Update the body base:

```css
body {
  margin: 0;
  background: var(--bg);
  color: var(--text);
  font-family: var(--font-body);
  font-size: 16px;
  line-height: 1.6;
  min-height: 100vh;
}
```

4. Replace remaining dark-theme literals in `style.css` using this table. Keep gradients/overlays but move them to the light palette.

| Old value | New value / rule |
|---|---|
| `#07090c` | `var(--bg)` |
| `#101318` | `var(--surface)` |
| `#161a21` | `var(--surface-2)` |
| `#0d1117`, `#0a0d11`, `#1d222b` | `var(--surface)` or `var(--surface-2)` depending on the surface |
| `#e8edf2` | `var(--text)` |
| `#8b95a3` | `var(--text-dim)` |
| `#00f0ff` | `var(--accent)` |
| `#c6ff00` | `var(--accent-2)` |
| `#04252a` | `var(--accent-contrast)` |
| `#ff4d6a` | `var(--danger)` |
| `#ffb547` | `var(--warning)` |
| `#ffb3c1` | `var(--danger-soft)` |
| `rgba(0, 240, 255, ...)` | `rgba(47, 111, 78, ...)` or the matching `--accent-soft`/border token |
| `rgba(7, 9, 12, ...)` | `rgba(31, 37, 33, ...)` for overlays |
| `rgba(198, 255, 0, ...)` | `rgba(107, 143, 122, ...)` |
| `rgba(255, 77, 106, ...)` | `rgba(179, 38, 30, ...)` |

5. Remove or neutralize glows:
   - `box-shadow: var(--glow-accent)` → `box-shadow: none` or `var(--shadow-sm)`.
   - `text-shadow` on headings/age gate → remove.
   - `filter: blur(12px)` on `#page` → `blur(4px)`.
   - Background grid textures → remove.

**Verify**

```powershell
Select-String -Path style.css -Pattern '#00f0ff|#c6ff00|#07090c|#101318|#161a21|#0d1117|#0a0d11|#1d222b|#8b95a3|#e8edf2|#04252a|rgba\(0, ?240, ?255'
```

Expected: no matches.

**Checkpoint:** back up `style.css`.

---

### Task 6.2 — Add the missing admin/account/order/legal styles

**File:** `style.css`

**Steps**
1. Append the following block. It covers the classes the PHP pages already use but the stylesheet does not define.
2. If an existing rule conflicts, prefer the new light rule and remove the old dark rule.

```css
/* ---------- Forms: terms acceptance ---------- */
.check-field {
  display: flex;
  align-items: flex-start;
  gap: var(--s2);
  padding: var(--s3);
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  font-size: 14px;
  line-height: 1.5;
  color: var(--text-dim);
  cursor: pointer;
}

.check-field input {
  margin-top: 3px;
  accent-color: var(--accent);
}

.check-field a {
  color: var(--accent);
  text-decoration: underline;
  text-underline-offset: 2px;
}

.check-field.has-error {
  border-color: var(--danger);
  background: var(--danger-soft);
}

/* ---------- Legal page ---------- */
.legal {
  padding: var(--s6) var(--s3);
}

.legal__container {
  max-width: 760px;
  margin: 0 auto;
}

.legal__title {
  margin: var(--s2) 0;
  font-family: var(--font-display);
  font-size: clamp(2rem, 4vw, 2.75rem);
  line-height: 1.1;
}

.legal__meta {
  color: var(--text-dim);
  font-size: 14px;
}

.legal__toc {
  margin: var(--s4) 0;
  padding: var(--s3);
  background: var(--surface-2);
  border-radius: var(--radius);
}

.legal__toc ol {
  margin: 0;
  padding-left: 1.25rem;
}

.legal__toc a {
  color: var(--accent);
}

.legal__section {
  margin-top: var(--s5);
  scroll-margin-top: calc(var(--header-h) + 16px);
}

.legal__section h2 {
  margin-bottom: var(--s2);
  font-family: var(--font-display);
  font-size: 1.35rem;
}

.legal__section p,
.legal__section li {
  color: var(--text-dim);
  line-height: 1.7;
}

.legal__contact {
  padding: var(--s3);
  background: var(--surface-2);
  border-radius: var(--radius);
}

/* ---------- Admin shell ---------- */
.admin-layout {
  display: grid;
  grid-template-columns: 240px minmax(0, 1fr);
  min-height: 100vh;
  background: var(--bg);
}

.admin-sidebar {
  position: sticky;
  top: 0;
  display: flex;
  flex-direction: column;
  gap: var(--s4);
  height: 100vh;
  padding: var(--s3);
  background: var(--surface);
  border-right: 1px solid var(--border);
}

.admin-sidebar__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--s2);
}

.admin-sidebar__tag {
  padding: 2px 8px;
  border: 1px solid var(--border);
  border-radius: 999px;
  color: var(--text-soft);
  font-size: 11px;
  letter-spacing: .12em;
  text-transform: uppercase;
}

.admin-nav {
  display: grid;
  gap: 4px;
}

.admin-nav__link {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border-radius: var(--radius-sm);
  color: var(--text-dim);
  font-size: 14px;
  font-weight: 500;
  text-decoration: none;
}

.admin-nav__link:hover {
  background: var(--surface-2);
  color: var(--text);
}

.admin-nav__link.is-active {
  background: var(--accent-soft);
  color: var(--accent);
}

.admin-sidebar__footer {
  display: grid;
  gap: var(--s2);
  margin-top: auto;
  padding-top: var(--s3);
  border-top: 1px solid var(--border);
}

.admin-sidebar__user-name {
  font-size: 14px;
  font-weight: 600;
}

.admin-sidebar__user-role {
  color: var(--text-soft);
  font-size: 12px;
}

.admin-sidebar__logout {
  color: var(--danger);
  font-size: 13px;
}

.admin-content {
  width: 100%;
  max-width: 1280px;
  padding: var(--s4);
}

.admin-page-header {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: var(--s3);
  margin-bottom: var(--s4);
}

.admin-page-title {
  margin: 0;
  font-family: var(--font-display);
  font-size: clamp(1.5rem, 2.5vw, 2rem);
}

.admin-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: var(--s3);
  margin-bottom: var(--s4);
}

.admin-stat-card,
.admin-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-sm);
}

.admin-stat-card {
  display: grid;
  gap: 4px;
  padding: var(--s3);
}

.admin-stat-label {
  color: var(--text-soft);
  font-size: 13px;
}

.admin-stat-value {
  font-family: var(--font-display);
  font-size: 1.6rem;
  font-weight: 600;
}

.admin-stat-meta {
  color: var(--text-dim);
  font-size: 12px;
}

.admin-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: var(--s3);
}

.admin-card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--s2);
  padding: var(--s3);
  border-bottom: 1px solid var(--border);
}

.admin-card-title {
  margin: 0;
  font-size: 1rem;
}

.admin-table-wrap {
  overflow-x: auto;
}

.admin-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 14px;
}

.admin-table th,
.admin-table td {
  padding: 12px var(--s3);
  text-align: left;
  vertical-align: middle;
  border-bottom: 1px solid var(--border);
}

.admin-table th {
  background: var(--surface-2);
  color: var(--text-dim);
  font-weight: 600;
  white-space: nowrap;
}

.admin-table tbody tr:hover {
  background: var(--surface-2);
}

/* ---------- Account ---------- */
.account {
  padding: var(--s5) var(--s3);
}

.account__container {
  max-width: 1120px;
  margin: 0 auto;
}

.account__header {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  justify-content: space-between;
  gap: var(--s3);
  margin-bottom: var(--s4);
}

.account__title {
  margin: 0;
  font-family: var(--font-display);
  font-size: clamp(1.75rem, 3vw, 2.25rem);
}

.account__nav {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.account__nav-link {
  padding: 8px 12px;
  border-radius: 999px;
  color: var(--text-dim);
  font-size: 14px;
  text-decoration: none;
}

.account__nav-link:hover {
  background: var(--surface-2);
  color: var(--text);
}

.account__nav-link.is-active {
  background: var(--accent-soft);
  color: var(--accent);
  font-weight: 600;
}

.account__nav-link--danger {
  color: var(--danger);
}

.account__grid {
  display: grid;
  grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
  gap: var(--s3);
}

.account__card {
  padding: var(--s4);
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-sm);
}

.account__card-title {
  margin: 0 0 var(--s3);
  font-size: 1.05rem;
}

.order-meta {
  color: var(--text-dim);
  font-size: 14px;
}

/* ---------- Orders ---------- */
.orders-list {
  display: grid;
  gap: var(--s2);
}

.order-item {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: var(--s2);
  padding: var(--s3);
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  color: inherit;
  text-decoration: none;
}

.order-item:hover {
  background: var(--surface-2);
  border-color: var(--border-strong);
}

.order-item__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--s2);
}

.order-item__number {
  font-weight: 600;
}

.order-item__meta {
  color: var(--text-dim);
  font-size: 13px;
}

.order-item__total {
  font-weight: 600;
  text-align: right;
}

.order-items {
  display: grid;
  gap: var(--s2);
}

.order-items__row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto auto;
  gap: var(--s3);
  align-items: center;
  padding: var(--s2) 0;
  border-bottom: 1px solid var(--border);
}

.order-items__name {
  font-weight: 500;
}

.order-items__meta,
.order-items__qty {
  color: var(--text-dim);
  font-size: 13px;
}

.order-items__price {
  font-weight: 600;
  text-align: right;
}

/* ---------- Status chips ---------- */
.status-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 10px;
  border-radius: 999px;
  background: var(--surface-2);
  color: var(--text-dim);
  font-size: 12px;
  font-weight: 600;
}

.status-chip--pending { background: var(--warning-soft); color: var(--warning); }
.status-chip--paid { background: var(--accent-soft); color: var(--accent); }
.status-chip--packing { background: var(--info-soft); color: var(--info); }
.status-chip--shipped { background: #e8eaf6; color: #3f4a8a; }
.status-chip--delivered { background: var(--accent-soft); color: var(--accent); }
.status-chip--cancelled { background: var(--danger-soft); color: var(--danger); }

/* ---------- Timeline ---------- */
.timeline {
  position: relative;
  display: grid;
  gap: var(--s3);
  margin: 0;
  padding: 0;
  list-style: none;
}

.timeline__item {
  position: relative;
  display: grid;
  grid-template-columns: 16px 1fr;
  gap: var(--s2);
}

.timeline__item::before {
  content: "";
  position: absolute;
  top: 16px;
  bottom: calc(-1 * var(--s3));
  left: 7px;
  width: 2px;
  background: var(--border);
}

.timeline__item:last-child::before {
  display: none;
}

.timeline__dot {
  width: 16px;
  height: 16px;
  margin-top: 2px;
  background: var(--accent-soft);
  border: 2px solid var(--accent);
  border-radius: 50%;
}

.timeline__content {
  display: grid;
  gap: 2px;
}

.timeline__status {
  font-weight: 600;
}

.timeline__note,
.timeline__time {
  color: var(--text-dim);
  font-size: 13px;
}

/* ---------- Responsive ---------- */
@media (max-width: 900px) {
  .admin-layout {
    grid-template-columns: 1fr;
  }

  .admin-sidebar {
    position: static;
    height: auto;
    border-right: 0;
    border-bottom: 1px solid var(--border);
  }

  .admin-nav {
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  }

  .admin-sidebar__footer {
    margin-top: 0;
  }

  .account__grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 640px) {
  .admin-content,
  .account {
    padding: var(--s3);
  }

  .admin-page-header,
  .account__header {
    flex-direction: column;
    align-items: flex-start;
  }

  .order-items__row {
    grid-template-columns: 1fr auto;
  }

  .order-items__qty {
    grid-column: 1 / -1;
  }

  .pay-mode {
    display: flex;
    width: 100%;
  }

  .pay-mode__btn {
    flex: 1;
  }

  .split-pay__leg {
    padding: var(--s2);
  }
}
```

**Verify**

Open every admin/account page. Expected: sidebar/nav/cards/tables/chips/timeline are styled; no unstyled text blocks; tables scroll inside their wrapper at 360px.

**Checkpoint:** back up `style.css`.

---

### Task 6.3 — Remove hard-coded dark colors from PHP views

**Files:** `account/order.php`, `account/orders.php`, `account/addresses.php`, `admin/index.php`, `admin/orders.php`, `admin/order.php`, `admin/customers.php`, `admin/products.php`, `admin/product-edit.php`

**Steps**
1. Replace inline `color:#8b95a3` with `color:var(--text-dim)`.
2. Replace inline `color:#e8edf2` with `color:var(--text)`.
3. Replace inline `color:#00f0ff` with `color:var(--accent)`.
4. Replace inline `color:#c6ff00` with `color:var(--accent-2)`.
5. Replace inline dark backgrounds (`background:#07090c`, `#101318`, `#161a21`, `#0d1117`, `#0a0d11`, `#1d222b`) with the matching token or remove them so the class styles apply.
6. Prefer adding classes over inline styles where the same style repeats. At minimum, use the classes from Task 6.2 for:
   - `.order-meta` for muted order text.
   - `.pay-breakdown` for payment rows.
   - `.admin-card`, `.admin-card-header`, `.admin-card-title`.
   - `.account__card`, `.account__card-title`.
   - `.status-chip`.
7. Keep layout-only inline styles (`display:flex`, `gap`, `margin`) if they are one-off, but replace color and background values.

**Verify**

```powershell
Get-ChildItem admin,account -Recurse -File -Include *.php |
  Select-String -Pattern '#00f0ff|#c6ff00|#07090c|#101318|#161a21|#0d1117|#0a0d11|#1d222b|#8b95a3|#e8edf2'
```

Expected: no matches.

**Checkpoint:** back up the touched PHP files.

---

### Task 6.4 — Update the auth error page and placeholder art

**Files:** `includes/auth.php`, `includes/head.php`

**Steps**
1. `includes/auth.php`: in the unauthorized page inline CSS, replace the dark palette with:
   - background `#f7f8f5`
   - text `#1f2521`
   - muted `#5b665e`
   - accent `#2f6f4e`
   - border `#2f6f4e`
2. `includes/head.php`: in the `#ph-art` SVG symbol, replace neon fills/strokes with:
   - background rect `#eef1ec`
   - primary stroke `#2f6f4e`
   - secondary stroke `#8a9a8f`
   - text/line `#8a9a8f`
3. Do not change the icon sprite IDs.

**Verify**

Open an unauthorized admin URL while signed out. Expected: light error page, readable text, working “Go home”/“Sign in” link. Product cards without images use the muted placeholder art.

**Checkpoint:** back up both files.

---

### Task 6.5 — General polish pass

**Files:** `style.css`, `index.php`, `login.php`, `register.php`, `admin/login.php`, `account/*.php`, `admin/*.php`

**Checklist**
- [ ] Header: consistent 64px height, no overlapping search/cart at 360px.
- [ ] Buttons: 44px min height, sentence case, visible focus ring, disabled opacity.
- [ ] Forms: 44px inputs, labels above, inline errors, `aria-invalid`/`aria-describedby`.
- [ ] Cards: 1px border, 12px radius, `--shadow-sm`, consistent 16–24px padding.
- [ ] Tables: header background, row hover, horizontal scroll wrapper.
- [ ] Empty states: icon + message + action, centered.
- [ ] Drawer/checkout: white surfaces, soft backdrop, no glow.
- [ ] Age gate: light overlay, blur 4px, no neon.
- [ ] Footer: terms link, age line, muted text.
- [ ] Typography: body 16px/1.6, headings 600, no wide uppercase except micro-labels.
- [ ] Motion: `prefers-reduced-motion` block still works.
- [ ] No horizontal page scroll at 360px.
- [ ] No page renders text directly on the background without a container where a card is expected.

**Verify:** browser pass at 360px, 768px, and 1280px on:
`index.php`, `login.php`, `register.php`, `terms.php`, `account/index.php`, `account/orders.php`, `account/order.php`, `account/addresses.php`, `admin/login.php`, `admin/register.php`, `admin/index.php`, `admin/products.php`, `admin/product-edit.php`, `admin/orders.php`, `admin/order.php`, `admin/customers.php`.

**Checkpoint:** back up the project folder before this broad pass.

---

## Phase 7 — End-to-end verification and rollout

### Task 7.1 — Automated checks

Run from the project root:

```powershell
C:\xampp\php\php.exe -l includes\functions.php
C:\xampp\php\php.exe -l includes\auth.php
C:\xampp\php\php.exe -l includes\head.php
C:\xampp\php\php.exe -l config\config.php
C:\xampp\php\php.exe -l api\cart.php
C:\xampp\php\php.exe -l api\checkout.php
C:\xampp\php\php.exe -l api\orders.php
C:\xampp\php\php.exe -l register.php
C:\xampp\php\php.exe -l terms.php
C:\xampp\php\php.exe -l admin\register.php
C:\xampp\php\php.exe -l admin\login.php
C:\xampp\php\php.exe -l admin\orders.php
C:\xampp\php\php.exe -l admin\order.php
C:\xampp\php\php.exe -l account\order.php
C:\xampp\php\php.exe -l account\orders.php
C:\xampp\php\php.exe tests\run.php
```

Expected: every file reports `No syntax errors detected`; tests report `All tests passed.`

### Task 7.2 — Database checks

```sql
USE vape_shop;

DESCRIBE users;
DESCRIBE orders;
DESCRIBE order_payments;

SELECT o.order_number, o.payment_method, o.subtotal, o.vat_amount, o.shipping_fee, o.total,
       SUM(op.amount) AS paid_total, COUNT(op.id) AS payment_rows
FROM orders o
LEFT JOIN order_payments op ON op.order_id = o.id
GROUP BY o.id
ORDER BY o.id DESC
LIMIT 10;

SELECT subtotal, vat_amount,
       ROUND(subtotal - (subtotal / 1.12), 2) AS expected_included_vat
FROM orders
ORDER BY id DESC
LIMIT 5;

SELECT email, terms_accepted_at, terms_version
FROM users
WHERE terms_accepted_at IS NOT NULL
ORDER BY id DESC
LIMIT 5;
```

Expected:
- `price_total = total` for every new order.
- `payment_rows` is 1 or 2.
- `total = subtotal + shipping_fee`.
- `vat_amount = expected_included_vat`.
- Accepted users have a timestamp and `2026-11-16`.

### Task 7.3 — Manual browser matrix

**Age gate**
- [ ] Fresh tab → gate.
- [ ] Verify → hidden.
- [ ] Ctrl+F5 → gate.
- [ ] Verify → `login.php` → Back → no gate.
- [ ] Verify → in-site link → no gate.
- [ ] New tab → gate.
- [ ] Under 21 → denial; refresh → gate.

**Terms and registration**
- [ ] Customer registration without terms → error; no account.
- [ ] Customer registration with terms → signed in; DB acceptance set.
- [ ] `admin/login.php` → admin registration link works.
- [ ] Admin registration without terms → error.
- [ ] Admin registration with terms → admin signed in.
- [ ] Set `ALLOW_OPEN_ADMIN_REGISTRATION=false` → admin/register.php returns 403 with staff login link.
- [ ] Reset to `true` for the demo.

**Pricing**
- [ ] Cart: Subtotal, Shipping, Total; no VAT.
- [ ] Checkout step 1: same.
- [ ] Confirmation: same.
- [ ] Account order detail: no VAT.
- [ ] Admin order detail: no VAT.
- [ ] DB: `total = subtotal + shipping_fee`.

**Payments**
- [ ] Single GCash → one row, method `GCASH`.
- [ ] Single Maya → one row, method `MAYA`.
- [ ] Single Cash → one row, method `CASH`.
- [ ] GCash + Cash split → two rows, order method `SPLIT`.
- [ ] Maya + Cash split → accepted.
- [ ] GCash + Maya split → accepted.
- [ ] Same method twice → inline error.
- [ ] Zero amount → inline error.
- [ ] Amounts off by more than ₱0.01 → inline error.
- [ ] API `CARD` → `VALIDATION_ERROR`.
- [ ] Admin list shows compact summary.
- [ ] Admin detail and customer detail show both rows.

**UI**
- [ ] 360px: no horizontal page scroll.
- [ ] 360px: admin tables scroll in wrapper.
- [ ] 360px: split legs stack; buttons full width where appropriate.
- [ ] 768px: account grid one column; admin sidebar becomes top bar.
- [ ] 1280px: admin sidebar 240px; content max-width respected.
- [ ] Focus rings visible on all controls.
- [ ] Form errors readable and linked.
- [ ] `prefers-reduced-motion` reduces motion.
- [ ] No `#00f0ff`/`#c6ff00`/neon glow remains.

### Task 7.4 — Rollout

1. Back up the database:

```powershell
C:\xampp\mysql\bin\mysqldump.exe -u root vape_shop > "backup-vape-shop-2026-11-16.sql"
```

2. Run the migration on the existing DB (Task 1.3) or import `database/schema.sql` on a fresh DB.
3. Deploy the changed files.
4. Hard-refresh the browser (CSS and JS changed).
5. Run `tests\run.php` and the manual matrix.
6. Create the first admin through `admin/register.php`.
7. Set `ALLOW_OPEN_ADMIN_REGISTRATION` to `false` in `config/config.php`.
8. Replace the sample support email/phone/address in the terms copy with real details.
9. Keep the backup until the client signs off.

**Rollback**
- Restore the database backup.
- Restore the backed-up PHP/JS/CSS files.
- If the schema was already migrated, restoring the backup is the cleanest rollback. Do not try to drop columns from a live database without a backup.

---

## Appendix A — File change map

| File | Change |
|---|---|
| `config/config.php` | VAT comment, `TERMS_VERSION`, `ALLOW_OPEN_ADMIN_REGISTRATION` |
| `database/schema.sql` | users terms columns, orders payment method, `order_payments` |
| `database/migrations/2026-11-16-refresh.sql` | new migration + backfill |
| `includes/functions.php` | `get_flash`, `audit_log`, `order_totals`, payment helpers |
| `includes/auth.php` | light unauthorized page |
| `includes/head.php` | muted placeholder SVG |
| `api/cart.php` | VAT-inclusive totals helper |
| `api/checkout.php` | VAT-inclusive totals, split payment validation/storage |
| `api/orders.php` | date columns, payment rows, no VAT |
| `app.js` | age gate, no VAT, split payment UI, receipt snapshot |
| `style.css` | light tokens, missing admin/account/order/legal styles, split UI, responsive |
| `index.php` | terms footer link |
| `register.php` | terms checkbox + acceptance storage |
| `terms.php` | new Terms & Policy page |
| `admin/register.php` | new staff registration page |
| `admin/login.php` | staff registration link |
| `admin/index.php` | `placed_at`, status values/labels |
| `admin/orders.php` | `placed_at`, status values, payment summary |
| `admin/order.php` | status values, dates, payment breakdown, no VAT |
| `admin/customers.php` | inline color cleanup |
| `admin/products.php` | inline color cleanup |
| `admin/product-edit.php` | inline color cleanup |
| `account/index.php` | inline color cleanup if present |
| `account/orders.php` | `placed_at`, status labels, inline color cleanup |
| `account/order.php` | `created_at` history, `placed_at`, payment breakdown, no VAT |
| `account/addresses.php` | inline color cleanup |
| `tests/run.php` | new pricing/payment tests |

## Appendix B — Manual test data

Use any active product with stock. A useful cart for split testing:
- 1 item at ₱1,000.00 → subtotal ₱1,000.00, shipping ₱150.00, total ₱1,150.00.
- Split: GCash ₱600.00 + Cash ₱550.00.
- DB expected: `payment_method = SPLIT`; `order_payments` rows `(GCASH, 600.00)` and `(CASH, 550.00)`; `total = 1150.00`.

For free shipping:
- Subtotal ₱2,000.00 → shipping ₱0.00, total ₱2,000.00.
- Split: Maya ₱1,000.00 + Cash ₱1,000.00.

## Appendix C — Approval checklist

Before any code is written, confirm:
- [ ] Age gate is scoped to the storefront (`index.php`), not auth/admin pages.
- [ ] Open admin registration is acceptable as a temporary setup flow.
- [ ] No card/bank payment remains, including legacy labels in new orders.
- [ ] VAT will not be displayed anywhere in the buying flow.
- [ ] Split payments are limited to two different methods.
- [ ] Terms acceptance is required on customer and admin registration, not checkout.
- [ ] The light-minimal palette and the baseline repairs are in scope.
- [ ] The sample terms contact details will be replaced before launch.
