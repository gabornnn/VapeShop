<?php
/**
 * VAPE CO. — server-side cart endpoint.
 * Vape_ShopWEb / api / cart.php
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helper.php';

if (!in_array(api_method(), ['GET', 'POST'], true)) {
    api_fail('METHOD_NOT_ALLOWED', 'This endpoint only accepts GET or POST requests.', 405);
}

/**
 * Compute totals from a cart payload.
 */
function cart_totals(array $items): array
{
    $subtotal = 0.0;
    foreach ($items as $item) {
        $subtotal += (float) $item['price'] * (int) $item['qty'];
    }

    return order_totals($subtotal);
}

/**
 * Load the cart for a cart id, joined to product/variant data.
 */
function cart_load(int $cartId): array
{
    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT ci.variant_id, ci.qty, ci.unit_price,
                v.strength, v.flavor, v.stock_qty, v.is_active AS variant_active,
                p.id AS product_id, p.name, p.image_url,
                pv.is_active AS product_active
           FROM cart_items ci
           JOIN product_variants v ON v.id = ci.variant_id
           JOIN products p ON p.id = v.product_id
           LEFT JOIN products pv ON pv.id = p.id
          WHERE ci.cart_id = ?
          ORDER BY ci.id ASC'
    );
    $stmt->execute([$cartId]);
    $rows = $stmt->fetchAll();

    $items = [];
    foreach ($rows as $row) {
        $items[] = [
            'variantId' => (int) $row['variant_id'],
            'productId' => (int) $row['product_id'],
            'name'       => (string) $row['name'],
            'price'      => (float) $row['unit_price'],
            'image'      => (string) ($row['image_url'] ?? ''),
            'qty'        => (int) $row['qty'],
            'strength'   => (string) $row['strength'],
            'flavor'     => (string) $row['flavor'],
            'stock'      => (int) $row['stock_qty'],
            'inStock'    => (int) $row['stock_qty'] >= (int) $row['qty'],
            'available'  => ((int) $row['product_active'] === 1) && ((int) $row['variant_active'] === 1),
        ];
    }

    return [
        'items'  => $items,
        'totals' => cart_totals($items),
        'count'  => array_sum(array_column($items, 'qty')),
    ];
}

/**
 * Resolve product+strength+flavor to a variant id (for merge).
 */
function resolve_variant_id(string $productSlug, string $strength, string $flavor): ?int
{
    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT v.id
           FROM products p
           JOIN product_variants v ON v.product_id = p.id
          WHERE p.slug = ? AND p.is_active = 1 AND v.is_active = 1
            AND v.strength = ? AND v.flavor = ?
          LIMIT 1'
    );
    $stmt->execute([$productSlug, $strength, $flavor]);
    $id = $stmt->fetchColumn();

    return $id === false ? null : (int) $id;
}

/**
 * Upsert/merge a variant into a cart with constraints enforced.
 */
function cart_add_variant(int $cartId, int $variantId, int $qty): array
{
    $pdo = db();
    $qty = max(1, min(MAX_QTY, $qty));

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'SELECT v.id, v.product_id, v.strength, v.flavor, v.stock_qty, v.is_active AS variant_active,
                    p.is_active AS product_active, p.base_price, v.price_adjustment
               FROM product_variants v
               JOIN products p ON p.id = v.product_id
              WHERE v.id = ?
              FOR UPDATE'
        );
        $stmt->execute([$variantId]);
        $variant = $stmt->fetch();

        if (!$variant || (int) $variant['variant_active'] !== 1 || (int) $variant['product_active'] !== 1) {
            throw new Exception('UNAVAILABLE');
        }

        $unitPrice = round((float) $variant['base_price'] + (float) $variant['price_adjustment'], 2);
        $stock     = (int) $variant['stock_qty'];

        $stmt = $pdo->prepare('SELECT id, qty FROM cart_items WHERE cart_id = ? AND variant_id = ? FOR UPDATE');
        $stmt->execute([$cartId, $variantId]);
        $existing = $stmt->fetch();

        if ($existing) {
            $newQty = max(1, min(MAX_QTY, min($stock, (int) $existing['qty'] + $qty)));
            if ($newQty <= 0) {
                throw new Exception('OUT_OF_STOCK');
            }
            $upd = $pdo->prepare('UPDATE cart_items SET qty = ?, unit_price = ? WHERE id = ?');
            $upd->execute([$newQty, $unitPrice, (int) $existing['id']]);
        } else {
            if ($stock < 1) {
                throw new Exception('OUT_OF_STOCK');
            }
            $qtyClamped = max(1, min(MAX_QTY, min($stock, $qty)));
            $ins = $pdo->prepare('INSERT INTO cart_items (cart_id, variant_id, qty, unit_price) VALUES (?, ?, ?, ?)');
            $ins->execute([$cartId, $variantId, $qtyClamped, $unitPrice]);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    return cart_load($cartId);
}

/**
 * Set exact qty (qty 0 removes the line).
 */
function cart_update_variant(int $cartId, int $variantId, int $qty): array
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        if ($qty <= 0) {
            $del = $pdo->prepare('DELETE FROM cart_items WHERE cart_id = ? AND variant_id = ?');
            $del->execute([$cartId, $variantId]);
            $pdo->commit();
            return cart_load($cartId);
        }

        $qty = max(1, min(MAX_QTY, $qty));

        $stmt = $pdo->prepare(
            'SELECT v.stock_qty, v.is_active AS variant_active,
                    p.is_active AS product_active
               FROM product_variants v
               JOIN products p ON p.id = v.product_id
              WHERE v.id = ?
              FOR UPDATE'
        );
        $stmt->execute([$variantId]);
        $variant = $stmt->fetch();

        if (!$variant || (int) $variant['variant_active'] !== 1 || (int) $variant['product_active'] !== 1) {
            throw new Exception('UNAVAILABLE');
        }

        $qty = min($qty, (int) $variant['stock_qty']);
        if ($qty < 1) {
            $del = $pdo->prepare('DELETE FROM cart_items WHERE cart_id = ? AND variant_id = ?');
            $del->execute([$cartId, $variantId]);
            $pdo->commit();
            return cart_load($cartId);
        }

        $upd = $pdo->prepare('UPDATE cart_items SET qty = ? WHERE cart_id = ? AND variant_id = ?');
        $upd->execute([$qty, $cartId, $variantId]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    return cart_load($cartId);
}

function cart_remove_variant(int $cartId, int $variantId): array
{
    $pdo = db();
    $stmt = $pdo->prepare('DELETE FROM cart_items WHERE cart_id = ? AND variant_id = ?');
    $stmt->execute([$cartId, $variantId]);
    return cart_load($cartId);
}

function cart_clear(int $cartId): array
{
    $pdo = db();
    $stmt = $pdo->prepare('DELETE FROM cart_items WHERE cart_id = ?');
    $stmt->execute([$cartId]);
    return ['items' => [], 'totals' => cart_totals([]), 'count' => 0];
}

/**
 * Merge items (resolves product+strength+flavor to variant) into target cart.
 */
function cart_merge_into(int $targetCartId, array $items, string $guestTokenHash = ''): array
{
    $pdo = db();
    $skipped = [];

    $pdo->beginTransaction();

    try {
        foreach ($items as $item) {
            $productSlug = (string) ($item['productSlug'] ?? '');
            $strength    = (string) ($item['strength'] ?? '');
            $flavor      = (string) ($item['flavor'] ?? '');
            $qty         = (int) ($item['qty'] ?? 0);

            if ($productSlug === '' || $qty < 1) {
                $skipped[] = ['productSlug' => $productSlug, 'reason' => 'invalid'];
                continue;
            }

            $variantId = resolve_variant_id($productSlug, $strength, $flavor);
            if (!$variantId) {
                $skipped[] = ['productSlug' => $productSlug, 'strength' => $strength, 'flavor' => $flavor, 'reason' => 'unavailable'];
                continue;
            }

            $stmt = $pdo->prepare(
                'SELECT v.stock_qty, v.is_active AS variant_active,
                        p.is_active AS product_active, p.base_price, v.price_adjustment
                   FROM product_variants v
                   JOIN products p ON p.id = v.product_id
                  WHERE v.id = ?
                  FOR UPDATE'
            );
            $stmt->execute([$variantId]);
            $variant = $stmt->fetch();

            if (!$variant || (int) $variant['variant_active'] !== 1 || (int) $variant['product_active'] !== 1) {
                $skipped[] = ['productSlug' => $productSlug, 'reason' => 'unavailable'];
                continue;
            }

            $unitPrice = round((float) $variant['base_price'] + (float) $variant['price_adjustment'], 2);
            $stock     = (int) $variant['stock_qty'];
            $qty       = max(1, min(MAX_QTY, $qty));

            $stmt = $pdo->prepare('SELECT id, qty FROM cart_items WHERE cart_id = ? AND variant_id = ? FOR UPDATE');
            $stmt->execute([$targetCartId, $variantId]);
            $existing = $stmt->fetch();

            if ($existing) {
                $newQty = max(1, min(MAX_QTY, min($stock, (int) $existing['qty'] + $qty)));
                if ($newQty >= 1) {
                    $upd = $pdo->prepare('UPDATE cart_items SET qty = ?, unit_price = ? WHERE id = ?');
                    $upd->execute([$newQty, $unitPrice, (int) $existing['id']]);
                } else {
                    $del = $pdo->prepare('DELETE FROM cart_items WHERE id = ?');
                    $del->execute([(int) $existing['id']]);
                }
            } else {
                if ($stock < 1) {
                    $skipped[] = ['productSlug' => $productSlug, 'reason' => 'out_of_stock'];
                    continue;
                }
                $qtyClamped = max(1, min(MAX_QTY, min($stock, $qty)));
                $ins = $pdo->prepare('INSERT INTO cart_items (cart_id, variant_id, qty, unit_price) VALUES (?, ?, ?, ?)');
                $ins->execute([$targetCartId, $variantId, $qtyClamped, $unitPrice]);
            }
        }

        // Guest → user merge: move remaining guest cart lines into target if guest cart exists.
        if ($guestTokenHash !== '') {
            $sourceCartId = get_cart_id_by_guest_hash($guestTokenHash);
            if ($sourceCartId && $sourceCartId !== $targetCartId) {
                // Move lines, merging on conflict.
                $srcStmt = $pdo->prepare(
                    'SELECT variant_id, qty, unit_price FROM cart_items WHERE cart_id = ?'
                );
                $srcStmt->execute([$sourceCartId]);
                $srcItems = $srcStmt->fetchAll();

                foreach ($srcItems as $src) {
                    $variantId = (int) $src['variant_id'];
                    $qty       = max(1, min(MAX_QTY, (int) $src['qty']));

                    $stmt = $pdo->prepare(
                        'SELECT v.stock_qty, v.is_active AS variant_active,
                                p.is_active AS product_active, p.base_price, v.price_adjustment
                           FROM product_variants v
                           JOIN products p ON p.id = v.product_id
                          WHERE v.id = ?
                          FOR UPDATE'
                    );
                    $stmt->execute([$variantId]);
                    $variant = $stmt->fetch();

                    if (!$variant || (int) $variant['variant_active'] !== 1 || (int) $variant['product_active'] !== 1) {
                        continue;
                    }

                    $unitPrice = round((float) $variant['base_price'] + (float) $variant['price_adjustment'], 2);
                    $stock     = (int) $variant['stock_qty'];

                    $stmt = $pdo->prepare('SELECT id, qty FROM cart_items WHERE cart_id = ? AND variant_id = ? FOR UPDATE');
                    $stmt->execute([$targetCartId, $variantId]);
                    $existing = $stmt->fetch();

                    if ($existing) {
                        $newQty = max(1, min(MAX_QTY, min($stock, (int) $existing['qty'] + $qty)));
                        if ($newQty >= 1) {
                            $upd = $pdo->prepare('UPDATE cart_items SET qty = ?, unit_price = ? WHERE id = ?');
                            $upd->execute([$newQty, $unitPrice, (int) $existing['id']]);
                        } else {
                            $del = $pdo->prepare('DELETE FROM cart_items WHERE id = ?');
                            $del->execute([(int) $existing['id']]);
                        }
                    } else {
                        if ($stock >= 1) {
                            $qtyClamped = max(1, min(MAX_QTY, min($stock, $qty)));
                            $ins = $pdo->prepare('INSERT INTO cart_items (cart_id, variant_id, qty, unit_price) VALUES (?, ?, ?, ?)');
                            $ins->execute([$targetCartId, $variantId, $qtyClamped, $unitPrice]);
                        }
                    }
                }

                $delCart = $pdo->prepare('DELETE FROM carts WHERE id = ?');
                $delCart->execute([$sourceCartId]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $cart = cart_load($targetCartId);
    $cart['skipped'] = $skipped;

    return $cart;
}

/* ---------- Route ---------- */

try {
    $cartId = get_cart_id();

    if (api_method() === 'GET') {
        api_ok(cart_load($cartId));
    }

    // POST
    $body   = api_body();
    $action = (string) ($body['action'] ?? '');

    if ($action === '') {
        api_fail('VALIDATION_ERROR', 'Missing action.', 400);
    }

    switch ($action) {
        case 'add':
            $variantId = (int) ($body['variantId'] ?? 0);
            $qty       = (int) ($body['qty'] ?? 1);
            if ($variantId < 1) {
                api_fail('VALIDATION_ERROR', 'Invalid variant.', 400);
            }
            try {
                api_ok(cart_add_variant($cartId, $variantId, $qty));
            } catch (Exception $e) {
                if ($e->getMessage() === 'OUT_OF_STOCK') {
                    api_fail('OUT_OF_STOCK', 'This variant is out of stock.', 409);
                }
                if ($e->getMessage() === 'UNAVAILABLE') {
                    api_fail('UNAVAILABLE', 'This product is no longer available.', 409);
                }
                throw $e;
            }
            break;

        case 'update':
            $variantId = (int) ($body['variantId'] ?? 0);
            $qty       = (int) ($body['qty'] ?? 0);
            if ($variantId < 1) {
                api_fail('VALIDATION_ERROR', 'Invalid variant.', 400);
            }
            try {
                api_ok(cart_update_variant($cartId, $variantId, $qty));
            } catch (Exception $e) {
                if ($e->getMessage() === 'UNAVAILABLE') {
                    api_fail('UNAVAILABLE', 'This product is no longer available.', 409);
                }
                throw $e;
            }
            break;

        case 'remove':
            $variantId = (int) ($body['variantId'] ?? 0);
            if ($variantId < 1) {
                api_fail('VALIDATION_ERROR', 'Invalid variant.', 400);
            }
            api_ok(cart_remove_variant($cartId, $variantId));
            break;

        case 'clear':
            api_ok(cart_clear($cartId));
            break;

        case 'merge':
            $items = is_array($body['items'] ?? null) ? $body['items'] : [];
            $guestHash = is_string($body['guestTokenHash'] ?? null) ? (string) $body['guestTokenHash'] : '';
            // Also try to merge current guest cart if logged in.
            if (is_logged_in()) {
                $guestHash = $guestHash !== '' ? $guestHash : guest_token();
            }
            api_ok(cart_merge_into($cartId, $items, $guestHash));
            break;

        default:
            api_fail('VALIDATION_ERROR', 'Unknown action.', 400);
    }
} catch (Throwable $e) {
    error_log('cart: ' . $e->getMessage());
    api_fail('SERVER_ERROR', 'Could not update the cart.', 500);
}
