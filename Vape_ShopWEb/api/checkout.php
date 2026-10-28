<?php
/**
 * VAPE CO. — checkout endpoint.
 * Vape_ShopWEb / api / checkout.php
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helper.php';
require_once __DIR__ . '/../includes/functions.php';

if (api_method() !== 'POST') {
    api_fail('METHOD_NOT_ALLOWED', 'This endpoint only accepts POST requests.', 405);
}

require_api_login();

$body          = api_body();
$shipping      = is_array($body['shipping'] ?? null) ? $body['shipping'] : [];
$paymentMethod = strtoupper(trim((string) ($body['paymentMethod'] ?? '')));

if ($paymentMethod === '') {
    $paymentMethod = 'COD';
}
if (!in_array($paymentMethod, ['CARD', 'GCASH', 'COD'], true)) {
    api_fail('VALIDATION_ERROR', 'Invalid payment method.', 400);
}

$name    = trim((string) ($shipping['name'] ?? ''));
$phone   = trim((string) ($shipping['phone'] ?? ''));
$address = trim((string) ($shipping['address'] ?? ''));
$notes   = trim((string) ($shipping['notes'] ?? ''));

$errors = [];
if ($name === '') {
    $errors['name'] = 'Full name is required.';
}
if ($phone === '' || !preg_match('/^(09\d{9}|\+639\d{9})$/', $phone)) {
    $errors['phone'] = 'Enter a valid PH number.';
}
if (strlen($address) < 10) {
    $errors['address'] = 'Address must be at least 10 characters.';
}

if ($errors) {
    api_fail('VALIDATION_ERROR', 'Please fix the highlighted fields.', 400, $errors);
}

$user   = current_user();
$userId = (int) $user['id'];
$pdo    = db();

try {
    $pdo->beginTransaction();

    $cartId = get_cart_id();
    $stmt   = $pdo->prepare(
        'SELECT ci.variant_id, ci.qty, ci.unit_price
           FROM cart_items ci
          WHERE ci.cart_id = ?
          FOR UPDATE'
    );
    $stmt->execute([$cartId]);
    $cartItems = $stmt->fetchAll();

    if (empty($cartItems)) {
        $pdo->rollBack();
        api_fail('EMPTY_CART', 'Your cart is empty.', 409);
    }

    $subtotal = 0.0;
    $orderLineItems = [];

    foreach ($cartItems as $item) {
        $variantId = (int) $item['variant_id'];
        $qty       = (int) $item['qty'];

        $vstmt = $pdo->prepare(
            'SELECT v.id, v.product_id, v.sku, v.strength, v.flavor, v.stock_qty, v.is_active AS variant_active,
                    p.name AS product_name, p.is_active AS product_active, p.base_price, v.price_adjustment
               FROM product_variants v
               JOIN products p ON p.id = v.product_id
              WHERE v.id = ?
              FOR UPDATE'
        );
        $vstmt->execute([$variantId]);
        $variant = $vstmt->fetch();

        if (!$variant || (int) $variant['variant_active'] !== 1 || (int) $variant['product_active'] !== 1) {
            $pdo->rollBack();
            api_fail('UNAVAILABLE', 'One or more items in your cart are no longer available.', 409);
        }

        if ((int) $variant['stock_qty'] < $qty) {
            $pdo->rollBack();
            api_fail('OUT_OF_STOCK', 'Not enough stock for one or more items.', 409);
        }

        $unitPrice = round((float) $variant['base_price'] + (float) $variant['price_adjustment'], 2);
        $lineTotal = round($unitPrice * $qty, 2);
        $subtotal += $lineTotal;

        $orderLineItems[] = [
            'product_id'   => (int) $variant['product_id'],
            'variant_id'   => $variantId,
            'product_name' => (string) $variant['product_name'],
            'sku'          => (string) $variant['sku'],
            'strength'     => (string) $variant['strength'],
            'flavor'       => (string) $variant['flavor'],
            'unit_price'   => $unitPrice,
            'qty'          => $qty,
            'line_total'   => $lineTotal,
        ];
    }

    $vatRate    = (float) VAT_RATE;
    $vatAmount  = round($subtotal * $vatRate, 2);
    $shippingFee = ($subtotal > 0 && $subtotal < (float) FREE_SHIPPING_ABOVE) ? (float) FLAT_SHIPPING : 0.00;
    $total      = round($subtotal + $vatAmount + $shippingFee, 2);

    // Create order with placeholder number.
    $ostmt = $pdo->prepare(
        'INSERT INTO orders
           (order_number, user_id, status, payment_method, subtotal, vat_rate, vat_amount, shipping_fee, total,
            ship_to_name, ship_to_phone, ship_to_address, ship_to_notes)
         VALUES
           (?, ?, "PENDING", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $placeholderRef = 'TEMP-' . uniqid();
    $ostmt->execute([
        $placeholderRef,
        $userId,
        $paymentMethod,
        $subtotal,
        $vatRate,
        $vatAmount,
        $shippingFee,
        $total,
        $name,
        $phone,
        $address,
        $notes !== '' ? $notes : null,
    ]);

    $orderId = (int) $pdo->lastInsertId();
    $ref     = 'VPS-' . date('ymd') . '-' . str_pad((string) $orderId, 4, '0', STR_PAD_LEFT);

    $up = $pdo->prepare('UPDATE orders SET order_number = ? WHERE id = ?');
    $up->execute([$ref, $orderId]);

    // Insert order items (frozen snapshots)
    $istmt = $pdo->prepare(
        'INSERT INTO order_items (order_id, product_id, variant_id, product_name, sku, strength, flavor, unit_price, qty, line_total)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($orderLineItems as $li) {
        $istmt->execute([
            $orderId,
            $li['product_id'],
            $li['variant_id'],
            $li['product_name'],
            $li['sku'],
            $li['strength'],
            $li['flavor'],
            $li['unit_price'],
            $li['qty'],
            $li['line_total'],
        ]);
    }

    // Status history
    $hstmt = $pdo->prepare(
        'INSERT INTO order_status_history (order_id, from_status, to_status, changed_by, note)
         VALUES (?, NULL, "PENDING", ?, "Order placed")'
    );
    $hstmt->execute([$orderId, $userId]);

    // Decrement stock with guard
    foreach ($orderLineItems as $li) {
        $ustmt = $pdo->prepare(
            'UPDATE product_variants SET stock_qty = stock_qty - ? WHERE id = ? AND stock_qty >= ?'
        );
        $ustmt->execute([$li['qty'], $li['variant_id'], $li['qty']]);
        if ($ustmt->rowCount() < 1) {
            throw new Exception('STOCK_RACE');
        }
    }

    // Clear cart
    $cstmt = $pdo->prepare('DELETE FROM cart_items WHERE cart_id = ?');
    $cstmt->execute([$cartId]);

    $pdo->commit();

    api_ok([
        'orderId'     => $orderId,
        'orderNumber' => $ref,
        'total'       => $total,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('checkout: ' . $e->getMessage());

    if ($e->getMessage() === 'STOCK_RACE') {
        api_fail('OUT_OF_STOCK', 'Stock changed while placing your order. Please review your cart.', 409);
    }

    api_fail('SERVER_ERROR', 'Could not place your order. Please try again.', 500);
}
