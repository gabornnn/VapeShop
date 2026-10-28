<?php
/**
 * VAPE CO. — storefront entry point (XAMPP).
 *
 * Markup shell only — all dynamic content is rendered by app.js.
 *
 * The <head>, the <body> open tag and the shared SVG icon sprite live in
 * includes/head.php, so the storefront, the account area and the admin panel
 * all share one header. includes/foot.php closes the document and loads app.js.
 */
require_once __DIR__ . '/config/config.php';

$pageTitle = APP_NAME . ' — ' . APP_TAGLINE;
$bodyClass = 'gate-open';

require_once __DIR__ . '/includes/head.php';
?>
  <!-- The shared SVG icon sprite is rendered by includes/head.php -->

  <!-- ============================================================
       Page (blurred while the age gate is open)
       ============================================================ -->
  <div id="page">

    <header class="site-header">
      <a class="wordmark" href="#" id="wordmark" aria-label="VAPE CO. — home">VAPE<span class="wordmark__accent">CO.</span></a>

      <!-- Desktop category nav (same query state as the chips) -->
      <nav class="desktop-nav" id="desktop-nav" aria-label="Product categories"></nav>

      <!-- Checkout step indicator takes the nav slot while an order is in progress -->
      <ol class="step-indicator" id="step-indicator" aria-label="Checkout progress" hidden>
        <li class="step-indicator__step" data-step="1"><span>01</span> Summary</li>
        <li class="step-indicator__step" data-step="2"><span>02</span> Shipping</li>
        <li class="step-indicator__step" data-step="3"><span>03</span> Payment</li>
        <li class="step-indicator__step" data-step="4"><span>04</span> Confirmation</li>
      </ol>

      <div class="header-search">
        <svg class="header-search__icon" aria-hidden="true"><use href="#icon-search"></use></svg>
        <input id="search-input" type="search" autocomplete="off"
               placeholder="Search products, flavors, strengths…" aria-label="Search products">
      </div>

      <button class="icon-btn header__search-toggle" id="search-toggle"
              aria-label="Toggle search" aria-expanded="false" aria-controls="mobile-search">
        <svg aria-hidden="true"><use href="#icon-search"></use></svg>
      </button>

      <div class="header-account" id="header-account">
        <div class="header-account__guest" id="header-account-guest">
          <a class="btn btn--ghost btn--sm" href="login.php">Sign in</a>
          <a class="btn btn--accent btn--sm" href="register.php">Create account</a>
        </div>
        <div class="header-account__user" id="header-account-user" hidden>
          <button class="header-account__trigger" id="header-account-trigger" aria-haspopup="menu" aria-expanded="false">
            <span class="header-account__name" id="header-account-name"></span>
            <svg class="header-account__caret" aria-hidden="true" viewBox="0 0 10 6"><path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </button>
          <div class="header-account__menu" id="header-account-menu" role="menu" hidden>
            <a class="header-account__item" role="menuitem" href="account/orders.php">My orders</a>
            <a class="header-account__item" role="menuitem" href="account/index.php">My profile</a>
            <a class="header-account__item" role="menuitem" href="account/addresses.php">Saved addresses</a>
            <a class="header-account__item header-account__item--danger" role="menuitem" href="logout.php">Sign out</a>
          </div>
        </div>
      </div>

      <button class="cart-btn" id="cart-btn" aria-label="Open cart" aria-haspopup="dialog">
        <svg aria-hidden="true"><use href="#icon-cart"></use></svg>
        <span class="cart-btn__badge" id="cart-badge" hidden>0</span>
      </button>
    </header>

    <!-- Expanding search field (mobile) -->
    <div class="mobile-search" id="mobile-search" hidden>
      <svg class="mobile-search__icon" aria-hidden="true"><use href="#icon-search"></use></svg>
      <input id="search-input-m" type="search" autocomplete="off"
             placeholder="Search products, flavors, strengths…" aria-label="Search products">
    </div>

    <main>
      <section id="catalog" class="catalog" aria-label="Product catalog">
        <div class="chips" id="chips" aria-label="Filter by category"></div>

        <div class="catalog-toolbar">
          <p class="result-count micro-label" id="result-count" aria-live="polite"></p>
          <div class="sort-control" id="sort-control" role="group" aria-label="Sort products">
            <button class="sort-btn is-active" data-sort="featured" aria-pressed="true">Featured</button>
            <button class="sort-btn" data-sort="price_asc" aria-pressed="false">Price ↑</button>
            <button class="sort-btn" data-sort="price_desc" aria-pressed="false">Price ↓</button>
            <button class="sort-btn" data-sort="newest" aria-pressed="false">Newest</button>
          </div>
        </div>

        <div class="product-grid" id="product-grid"></div>
        <div class="empty-state" id="empty-state" hidden>
          <svg class="empty-state__icon" aria-hidden="true"><use href="#icon-box"></use></svg>
          <p class="empty-state__text">No products match.</p>
          <button class="btn btn--ghost" id="clear-filters">Clear filters</button>
        </div>
        <nav class="pagination" id="pagination" aria-label="Catalog pages" hidden></nav>
        <div class="error-panel" id="error-panel" hidden role="alert">
          <p class="error-panel__title">Couldn't load the catalog.</p>
          <p class="error-panel__text">The catalog API did not answer. Check that MySQL is running, then retry.</p>
          <button class="btn btn--accent" id="retry-load">Retry</button>
        </div>
      </section>
    </main>

    <footer class="site-footer">
      <span class="wordmark wordmark--sm">VAPE<span class="wordmark__accent">CO.</span></span>
      <p>Frontend demo — no real products are sold and no payments are processed.</p>
      <p class="site-footer__age">Adults 21+ only.</p>
    </footer>

  </div><!-- /#page -->

  <!-- ============================================================
       Age gate — full-screen brand moment (static markup)
       ============================================================ -->
  <div id="age-gate" class="age-gate" role="dialog" aria-modal="true" aria-labelledby="age-gate-title" hidden>
    <div class="age-gate__inner">
      <span class="wordmark wordmark--lg">VAPE<span class="wordmark__accent">CO.</span></span>
      <p class="age-gate__age" aria-hidden="true">21+</p>
      <h2 id="age-gate-title" class="age-gate__statement">You must be 21 or older to enter.</h2>
      <div class="age-gate__actions" id="age-gate-actions">
        <button class="btn btn--accent btn--lg" id="age-yes" data-autofocus>I AM 21 OR OLDER</button>
        <button class="btn btn--ghost btn--lg" id="age-no">I AM UNDER 21</button>
      </div>
      <div class="age-gate__denied" id="age-denied" hidden>
        <p class="age-gate__denied-title">Sorry — come back when you're 21 or older.</p>
        <p class="age-gate__denied-text">This decision is final for this visit.</p>
      </div>
    </div>
  </div>

  <!-- ============================================================
       Cart drawer, quick-view modal, checkout, toast — JS-rendered
       ============================================================ -->
  <div id="cart-drawer" class="drawer-layer" hidden></div>
  <div id="quick-view" class="modal-layer" hidden></div>
  <div id="checkout" class="checkout-layer" hidden></div>
  <div class="toast" id="toast" role="status" hidden></div>

  <noscript>
    <div class="noscript">This storefront demo requires JavaScript. Please enable it to browse.</div>
  </noscript>

<?php require_once __DIR__ . '/includes/foot.php'; ?>

