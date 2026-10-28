<?php
/**
 * VAPE CO. — product catalog endpoint.
 * Vape_ShopWEb / api / catalog.php
 *
 * GET api/catalog.php?category=&q=&sort=&page=&pageSize=
 *
 * The server is now the only place products are filtered, searched, sorted and
 * paginated. The storefront sends what the shopper chose and renders what comes
 * back, so a shared URL always reproduces the same view.
 *
 * Response data:
 *
 *     {
 *       "products":   [ { id, slug, name, brand, category, description, price,
 *                         image, puffs, featured, available, stock,
 *                         strengths[], flavors[], variants[] }, ... ],
 *       "categories": [ { slug, name, iconKey, sortOrder }, ... ],
 *       "total": 40, "page": 1, "pageSize": 24, "totalPages": 2
 *     }
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helper.php';

if (api_method() !== 'GET') {
    api_fail('METHOD_NOT_ALLOWED', 'This endpoint only accepts GET requests.', 405);
}

/* ---------- Input ---------- */

$sortOptions = [
    'featured'   => 'p.is_featured DESC, p.id ASC',
    'price_asc'  => 'p.base_price ASC, p.id ASC',
    'price_desc' => 'p.base_price DESC, p.id ASC',
    'newest'     => 'p.created_at DESC, p.id DESC',
];

$sort = trim((string) ($_GET['sort'] ?? 'featured'));
if ($sort === '') {
    $sort = 'featured';
}
if (!isset($sortOptions[$sort])) {
    api_fail('VALIDATION_ERROR', 'Unknown sort option.', 400, ['sort' => 'Choose featured, price_asc, price_desc or newest.']);
}

$category = trim((string) ($_GET['category'] ?? ''));
$query    = trim((string) ($_GET['q'] ?? ''));

$page = max(1, (int) ($_GET['page'] ?? 1));

$pageSize = (int) ($_GET['pageSize'] ?? 24);
if ($pageSize < 1) {
    $pageSize = 24;
}
$pageSize = min(60, $pageSize);

/* ---------- Query ---------- */

try {
    $pdo = db();

    $categories = $pdo->query(
        'SELECT slug, name, icon_key, sort_order
           FROM categories
          WHERE is_active = 1
          ORDER BY sort_order ASC, name ASC'
    )->fetchAll();

    $where  = ['p.is_active = 1'];
    $params = [];

    if ($category !== '' && $category !== 'all') {
        $where[]  = 'c.slug = ?';
        $params[] = $category;
    }

    if ($query !== '') {
        $like = '%' . $query . '%';
        // Variants are searchable too, so "mango" or "50mg" finds the product.
        $where[] = '(p.name LIKE ? OR p.description LIKE ? OR p.brand LIKE ?
                     OR EXISTS (
                          SELECT 1 FROM product_variants vq
                           WHERE vq.product_id = p.id AND vq.is_active = 1
                             AND (vq.strength LIKE ? OR vq.flavor LIKE ?)
                        ))';
        array_push($params, $like, $like, $like, $like, $like);
    }

    $whereSql = implode(' AND ', $where);

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*)
           FROM products p
           JOIN categories c ON c.id = p.category_id
          WHERE $whereSql"
    );
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $totalPages = max(1, (int) ceil($total / $pageSize));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $pageSize;

    $orderBy = $sortOptions[$sort];

    $stmt = $pdo->prepare(
        "SELECT p.id, p.slug, p.name, p.brand, p.description, p.base_price,
                p.image_url, p.puffs, p.is_featured, c.slug AS category
           FROM products p
           JOIN categories c ON c.id = p.category_id
          WHERE $whereSql
          ORDER BY $orderBy
          LIMIT $pageSize OFFSET $offset"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    /* ---------- Variants for this page, in one extra query ---------- */

    $variantsByProduct = [];

    if ($rows) {
        $ids          = array_map(static fn ($r) => (int) $r['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $variantStmt = $pdo->prepare(
            "SELECT id, product_id, sku, strength, flavor, price_adjustment, stock_qty
               FROM product_variants
              WHERE product_id IN ($placeholders) AND is_active = 1
              ORDER BY id ASC"
        );
        $variantStmt->execute($ids);

        foreach ($variantStmt->fetchAll() as $variant) {
            $variantsByProduct[(int) $variant['product_id']][] = $variant;
        }
    }

    /* ---------- Shape ---------- */

    $products = [];

    foreach ($rows as $row) {
        $productId = (int) $row['id'];
        $basePrice = (float) $row['base_price'];

        $variants  = [];
        $strengths = [];
        $flavors   = [];
        $stock     = 0;
        $available = false;

        foreach ($variantsByProduct[$productId] ?? [] as $variant) {
            $variantStock = (int) $variant['stock_qty'];
            $stock       += max(0, $variantStock);
            $available    = $available || $variantStock > 0;

            $strength = (string) $variant['strength'];
            $flavor   = (string) $variant['flavor'];

            if ($strength !== '' && !in_array($strength, $strengths, true)) {
                $strengths[] = $strength;
            }
            if ($flavor !== '' && !in_array($flavor, $flavors, true)) {
                $flavors[] = $flavor;
            }

            $variants[] = [
                'id'        => (int) $variant['id'],
                'sku'       => (string) $variant['sku'],
                'strength'  => $strength,
                'flavor'    => $flavor,
                'price'     => round($basePrice + (float) $variant['price_adjustment'], 2),
                'stock'     => $variantStock,
            ];
        }

        $image = trim((string) ($row['image_url'] ?? ''));

        $products[] = [
            'id'          => $productId,
            'slug'        => (string) $row['slug'],
            'name'        => (string) $row['name'],
            'brand'       => (string) ($row['brand'] ?? ''),
            'category'    => (string) $row['category'],
            'description' => (string) $row['description'],
            'price'       => $basePrice,
            'image'       => $image,
            'puffs'       => $row['puffs'] !== null ? (int) $row['puffs'] : null,
            'featured'    => (bool) $row['is_featured'],
            'available'   => $available,
            'stock'       => $stock,
            'strengths'   => $strengths,
            'flavors'     => $flavors,
            'variants'    => $variants,
        ];
    }

    api_ok([
        'products'   => $products,
        'categories' => array_map(static function (array $c): array {
            return [
                'slug'      => (string) $c['slug'],
                'name'      => (string) $c['name'],
                'iconKey'   => (string) $c['icon_key'],
                'sortOrder' => (int) $c['sort_order'],
            ];
        }, $categories),
        'total'      => $total,
        'page'       => $page,
        'pageSize'   => $pageSize,
        'totalPages' => $totalPages,
    ]);
} catch (Throwable $e) {
    error_log('catalog: ' . $e->getMessage());
    api_fail('SERVER_ERROR', 'Could not load the catalog. Please try again.', 500);
}
