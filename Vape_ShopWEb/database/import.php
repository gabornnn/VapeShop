<?php
/**
 * VAPE CO. — catalog importer.
 * Vape_ShopWEb / database / import.php
 *
 * Reads products.json and writes it into the database: one `categories` row
 * per category, one `products` row per product, and one `product_variants`
 * row per (strength x flavor) combination.
 *
 * THIS SCRIPT IS A COMMAND-LINE TOOL. It refuses to run through a browser.
 * The `database/` folder is also blocked over HTTP by database/.htaccess.
 *
 * USAGE
 *   Dry run (prints what it would do, changes nothing):
 *       C:\xampp\php\php.exe database\import.php
 *
 *   Write to the database:
 *       C:\xampp\php\php.exe database\import.php --confirm
 *
 *   Change the stock seeded per variant:
 *       C:\xampp\php\php.exe database\import.php --confirm --stock=25
 *
 * RE-RUNNING IS SAFE. Categories and products are matched by slug and updated
 * in place; a product's variants are replaced wholesale so the SKU list always
 * matches products.json exactly.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

/* ---------- Options ---------- */

$argvList = $argv ?? [];
$confirm  = in_array('--confirm', $argvList, true);
$stock    = 50;

foreach ($argvList as $arg) {
    if (strpos($arg, '--stock=') === 0) {
        $stock = max(0, (int) substr($arg, 8));
    }
}

/* ---------- Output helpers ---------- */

function out(string $line = ''): void
{
    echo $line, PHP_EOL;
}

function head(string $title): void
{
    out();
    out('== ' . $title . ' ' . str_repeat('=', max(0, 62 - strlen($title))));
}

/* ---------- The catalog source ---------- */

$jsonPath = dirname(__DIR__) . '/products.json';

if (!is_file($jsonPath)) {
    out('ERROR: products.json was not found at ' . $jsonPath);
    exit(1);
}

$raw = file_get_contents($jsonPath);
if ($raw === false) {
    out('ERROR: products.json could not be read.');
    exit(1);
}

$products = json_decode($raw, true);

if (!is_array($products) || !$products) {
    out('ERROR: products.json is empty or is not valid JSON.');
    exit(1);
}

/**
 * The six categories, in the order the storefront shows them.
 *
 * `icon_key` matches a <symbol id="..."> in the shared SVG sprite, so the
 * category mark on a product card renders without any extra plumbing.
 */
const CATEGORY_ROWS = [
    ['slug' => 'disposables', 'name' => 'Disposables', 'icon_key' => 'cat-disposables'],
    ['slug' => 'pods',       'name' => 'Pods',       'icon_key' => 'cat-pods'],
    ['slug' => 'batteries',  'name' => 'Batteries',  'icon_key' => 'cat-batteries'],
    ['slug' => 'e-juice',    'name' => 'E-Juice',    'icon_key' => 'cat-e-juice'],
    ['slug' => 'cartridges', 'name' => 'Cartridges', 'icon_key' => 'cat-cartridges'],
    ['slug' => 'kits',       'name' => 'Kits',       'icon_key' => 'cat-kits'],
];

/**
 * The (strength x flavor) combinations of one product.
 *
 * A product with no strengths gets one variant with strength = ''.
 * A product with no flavors gets one variant with flavor = ''.
 * Empty strings — not NULL — because the UNIQUE (product_id, strength, flavor)
 * key only works if neither column can be NULL.
 *
 * @param array<string,mixed> $p
 * @return array<int, array{strength: string, flavor: string}>
 */
function variant_combos(array $p): array
{
    $strengths = array_values(array_filter(
        array_map('strval', $p['strengths'] ?? []),
        static fn($s) => trim($s) !== ''
    ));
    $flavors = array_values(array_filter(
        array_map('strval', $p['flavors'] ?? []),
        static fn($s) => trim($s) !== ''
    ));

    if (!$strengths) {
        $strengths = [''];
    }
    if (!$flavors) {
        $flavors = [''];
    }

    $combos = [];
    foreach ($strengths as $strength) {
        foreach ($flavors as $flavor) {
            $combos[] = ['strength' => $strength, 'flavor' => $flavor];
        }
    }

    return $combos;
}

/**
 * Build a SKU from the source id and the variant options.
 *
 * dsp-001 + 50mg + Blue Razz  ->  dsp-001-50mg-blue-razz
 *
 * The column is VARCHAR(40), so an over-long SKU is shortened with a numeric
 * suffix rather than truncated (two shortened SKUs must never collide).
 */
function build_sku(string $sourceId, string $strength, string $flavor, int $seq): string
{
    $parts = array_filter([$sourceId, $strength, $flavor], static fn($p) => trim($p) !== '');
    $sku   = slugify(implode('-', $parts));

    if ($sku === '') {
        $sku = 'sku';
    }

    if (strlen($sku) > 40) {
        $sku = substr($sku, 0, 34) . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    return $sku;
}

/* ---------- Plan the work ---------- */

$plan      = [];
$variantNo = 0;
$unknown   = [];

foreach ($products as $p) {
    if (!is_array($p)) {
        continue;
    }

    $combos = variant_combos($p);
    $variantNo += count($combos);

    $plan[] = [
        'source_id'  => (string) ($p['id'] ?? ''),
        'category'   => (string) ($p['category'] ?? ''),
        'name'       => (string) ($p['name'] ?? ''),
        'description'=> (string) ($p['description'] ?? ''),
        'price'      => (float) ($p['price'] ?? 0),
        'image'      => (string) ($p['image'] ?? ''),
        'featured'   => !empty($p['featured']),
        'combos'     => $combos,
    ];

    if (!in_array((string) ($p['category'] ?? ''), array_column(CATEGORY_ROWS, 'slug'), true)) {
        $unknown[] = (string) ($p['category'] ?? '');
    }
}

/* ---------- Dry run ---------- */

head('VAPE CO. — catalog import');

out('  source file   : ' . $jsonPath);
out('  products      : ' . count($plan));
out('  categories    : ' . count(CATEGORY_ROWS));
out('  variants      : ' . $variantNo);
out('  stock each    : ' . $stock);
out('  mode          : ' . ($confirm ? 'WRITE' : 'DRY RUN (nothing is changed)'));

if ($unknown) {
    out();
    out('  WARNING: products reference unknown categories: ' . implode(', ', array_unique($unknown)));
}

out();
out('  id         category     price    feat  variants  name');
out('  ---------- ------------ -------- ----- -------- ------------------------------------');

foreach ($plan as $row) {
    out(sprintf(
        '  %-10s %-12s %8s %5s %8d  %s',
        $row['source_id'],
        $row['category'],
        number_format($row['price'], 2),
        $row['featured'] ? 'yes' : '-',
        count($row['combos']),
        $row['name']
    ));
}

if (!$confirm) {
    out();
    out('  Nothing was written. Run again with --confirm to import:');
    out('      C:\xampp\php\php.exe database\import.php --confirm');
    out();
    exit(0);
}

/* ---------- Write ---------- */

$pdo = db();
$pdo->beginTransaction();

try {
    /* Categories: match on slug, then insert or update. */
    $findCategory = $pdo->prepare('SELECT id FROM categories WHERE slug = ? LIMIT 1');
    $addCategory  = $pdo->prepare(
        'INSERT INTO categories (slug, name, icon_key, sort_order, is_active)
         VALUES (?, ?, ?, ?, 1)'
    );
    $updateCategory = $pdo->prepare(
        'UPDATE categories SET name = ?, icon_key = ?, sort_order = ? WHERE id = ?'
    );

    $categoryIds = [];

    foreach (CATEGORY_ROWS as $i => $cat) {
        $findCategory->execute([$cat['slug']]);
        $existing = $findCategory->fetch();

        if ($existing) {
            $updateCategory->execute([$cat['name'], $cat['icon_key'], $i + 1, $existing['id']]);
            $categoryIds[$cat['slug']] = (int) $existing['id'];
        } else {
            $addCategory->execute([$cat['slug'], $cat['name'], $cat['icon_key'], $i + 1]);
            $categoryIds[$cat['slug']] = (int) $pdo->lastInsertId();
        }
    }

    /* Products: match on slug, then insert or update. */
    $findProduct = $pdo->prepare('SELECT id FROM products WHERE slug = ? LIMIT 1');
    $addProduct  = $pdo->prepare(
        'INSERT INTO products
            (category_id, slug, name, brand, description, base_price, image_url, puffs, is_active, is_featured)
         VALUES (?, ?, ?, NULL, ?, ?, ?, NULL, 1, ?)'
    );
    $updateProduct = $pdo->prepare(
        'UPDATE products
            SET category_id = ?, name = ?, description = ?, base_price = ?, image_url = ?,
                is_active = 1, is_featured = ?
          WHERE id = ?'
    );

    /* Variants are replaced wholesale for each product. */
    $dropVariants = $pdo->prepare('DELETE FROM product_variants WHERE product_id = ?');
    $addVariant   = $pdo->prepare(
        'INSERT INTO product_variants
            (product_id, sku, strength, flavor, price_adjustment, stock_qty, is_active)
         VALUES (?, ?, ?, ?, 0.00, ?, 1)'
    );

    $slugExists = static function (string $slug) use ($pdo): bool {
        $stmt = $pdo->prepare('SELECT id FROM products WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        return (bool) $stmt->fetch();
    };

    $productCount = 0;
    $variantCount = 0;

    foreach ($plan as $row) {
        /*
         * The slug is the identity of a product across imports, so it must be
         * derived from the name and NOT "the next free slug" — otherwise a
         * second run would see its own slug as taken and create a duplicate
         * product under "nova-x5000-disposable-2".
         */
        $baseSlug = slugify($row['name']);
        if ($baseSlug === '') {
            $baseSlug = 'product';
        }

        $findProduct->execute([$baseSlug]);
        $existing = $findProduct->fetch();

        if ($existing) {
            $slug = $baseSlug;
        } else {
            /* A genuine name collision with a different product: -2, -3, ... */
            $slug = unique_slug($row['name'], $slugExists);
        }

        $imageUrl = trim($row['image']) !== '' ? trim($row['image']) : null;

        if ($existing) {
            $productId = (int) $existing['id'];
            $updateProduct->execute([
                $categoryIds[$row['category']] ?? 0,
                $row['name'],
                $row['description'],
                $row['price'],
                $imageUrl,
                $row['featured'] ? 1 : 0,
                $productId,
            ]);
        } else {
            $addProduct->execute([
                $categoryIds[$row['category']] ?? 0,
                $slug,
                $row['name'],
                $row['description'],
                $row['price'],
                $imageUrl,
                $row['featured'] ? 1 : 0,
            ]);
            $productId = (int) $pdo->lastInsertId();
        }

        $productCount++;

        $dropVariants->execute([$productId]);

        $seq = 0;
        foreach ($row['combos'] as $combo) {
            $seq++;
            $sku = build_sku($row['source_id'], $combo['strength'], $combo['flavor'], $seq);
            $addVariant->execute([
                $productId,
                $sku,
                $combo['strength'],
                $combo['flavor'],
                $stock,
            ]);
            $variantCount++;
        }
    }

    $pdo->commit();

    head('Done');
    out('  categories written : ' . count($categoryIds));
    out('  products written   : ' . $productCount);
    out('  variants written   : ' . $variantCount);
    out();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    out();
    out('IMPORT FAILED: ' . $e->getMessage());
    out('Nothing was committed — the database was rolled back.');
    exit(1);
}
