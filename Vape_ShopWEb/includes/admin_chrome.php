<?php
/**
 * VAPE CO. — admin chrome.
 * Vape_ShopWEb / includes / admin_chrome.php
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_admin();

$admin = current_user();
$adminName = $admin['first_name'] . ' ' . $admin['last_name'];
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<div class="admin-layout">
  <aside class="admin-sidebar">
    <div class="admin-sidebar__header">
      <a class="wordmark wordmark--sm" href="../index.php" aria-label="VAPE CO. — storefront">VAPE<span class="wordmark__accent">CO.</span></a>
      <span class="admin-sidebar__tag">Staff</span>
    </div>
    
    <nav class="admin-nav">
      <a class="admin-nav__link <?= $currentPage === 'index.php' ? 'is-active' : '' ?>" href="index.php">
        <svg aria-hidden="true" width="18" height="18"><use href="#icon-box"></use></svg>
        Dashboard
      </a>
      <a class="admin-nav__link <?= $currentPage === 'products.php' || $currentPage === 'product-edit.php' ? 'is-active' : '' ?>" href="products.php">
        <svg aria-hidden="true" width="18" height="18"><use href="#icon-box"></use></svg>
        Products
      </a>
      <a class="admin-nav__link <?= $currentPage === 'orders.php' || $currentPage === 'order.php' ? 'is-active' : '' ?>" href="orders.php">
        <svg aria-hidden="true" width="18" height="18"><use href="#icon-box"></use></svg>
        Orders
      </a>
      <a class="admin-nav__link <?= $currentPage === 'customers.php' ? 'is-active' : '' ?>" href="customers.php">
        <svg aria-hidden="true" width="18" height="18"><use href="#icon-box"></use></svg>
        Customers
      </a>
    </nav>
    
    <div class="admin-sidebar__footer">
      <div class="admin-sidebar__user">
        <div class="admin-sidebar__user-name"><?= e($adminName) ?></div>
        <div class="admin-sidebar__user-role"><?= e($admin['role_name']) ?></div>
      </div>
      <a class="admin-sidebar__logout" href="logout.php">Sign out</a>
    </div>
  </aside>
  
  <main class="admin-content">
