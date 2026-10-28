<?php
/**
 * VAPE CO. — shared document foot.
 *
 * Closes the document and, on the storefront, loads the storefront controller.
 *
 * A page sets optional variables BEFORE including this file:
 *
 *     $loadApp     = false;                          // default true
 *     $pageScripts = '<script src="admin.js"></script>';
 *     require_once __DIR__ . '/includes/foot.php';
 *
 * Why $loadApp exists: app.js drives the storefront (catalog, cart, checkout)
 * and expects #product-grid, #age-gate and friends to be on the page. It throws
 * on a page that does not have them, so the account and admin pages load their
 * own small scripts instead.
 *
 * $pageScripts is printed AFTER app.js, so page scripts can use the API helper
 * that app.js defines.
 */

require_once __DIR__ . '/functions.php';

$__base      = asset_base();      // "" at the project root, "../" in /admin
$loadApp     = $loadApp     ?? true;
$pageScripts = $pageScripts ?? '';
?>
<?php if ($loadApp): ?>
  <script src="<?= e($__base) ?>app.js"></script>
<?php endif; ?>
<?= $pageScripts ?>
</body>
</html>
