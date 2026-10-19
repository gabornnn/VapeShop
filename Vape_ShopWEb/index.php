<?php
/**
 * VAPE CO. — storefront entry point (XAMPP).
 * Frontend-only demo: no backend, no real payments.
 * Markup shell only — all dynamic content is rendered by app.js.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="VAPE CO. — premium vape shop frontend demo. Adults 21+ only.">
  <title>VAPE CO. — Premium Vape Shop</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap">
  <link rel="stylesheet" href="style.css">
</head>
<body class="gate-open">

  <!-- ============================================================
       SVG sprite — one <symbol> per icon, referenced with <use>.
       ============================================================ -->
  <svg xmlns="http://www.w3.org/2000/svg" style="display:none" aria-hidden="true">
    <!-- Product placeholder art (used when a product has no image URL) -->
    <symbol id="ph-art" viewBox="0 0 120 120">
      <rect width="120" height="120" fill="#0b0e13"/>
      <circle cx="60" cy="52" r="26" fill="none" stroke="#00f0ff" stroke-width="1.5" opacity=".45"/>
      <circle cx="60" cy="52" r="17" fill="none" stroke="#00f0ff" stroke-width="1.5" opacity=".8"/>
      <path d="M52 76h16l-3 22a5 5 0 0 1-5 4h-0a5 5 0 0 1-5-4z" fill="none" stroke="#c6ff00" stroke-width="1.5" opacity=".7"/>
      <path d="M55 68h10" stroke="#c6ff00" stroke-width="1.5" opacity=".7"/>
      <path d="M60 30v-8M60 82v6M38 52h-8M90 52h-8" stroke="#00f0ff" stroke-width="1" opacity=".35"/>
    </symbol>
    <!-- UI icons -->
    <symbol id="icon-cart" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
      <path d="M3 4h2.4l2.1 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.55L20.5 8H6"/>
      <circle cx="10" cy="20" r="1.4"/><circle cx="17.5" cy="20" r="1.4"/>
    </symbol>
    <symbol id="icon-plus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
      <path d="M12 5v14M5 12h14"/>
    </symbol>
    <symbol id="icon-minus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
      <path d="M5 12h14"/>
    </symbol>
    <symbol id="icon-trash" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
      <path d="M4 7h16M10 7V5h4v2M6 7l1 13h10l1-13M10 11v5M14 11v5"/>
    </symbol>
    <symbol id="icon-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
      <path d="M6 6l12 12M18 6L6 18"/>
    </symbol>
    <symbol id="icon-search" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
      <circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/>
    </symbol>
    <symbol id="icon-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M4.5 12.5l5 5L19.5 6.5"/>
    </symbol>
    <symbol id="icon-box" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
      <path d="M3.5 8L12 3.5 20.5 8v8L12 20.5 3.5 16zM3.5 8L12 12.5 20.5 8M12 12.5V20.5"/>
    </symbol>
    <symbol id="icon-arrow-left" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M19 12H5M11 6l-6 6 6 6"/>
    </symbol>
    <symbol id="icon-card" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round">
      <rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 9.5h19M6 15h4"/>
    </symbol>
    <symbol id="icon-wallet" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
      <path d="M3 7a2 2 0 0 1 2-2h12v4"/><path d="M3 7v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V9H5a2 2 0 0 1-2-2z"/><circle cx="17" cy="14" r="1.3" fill="currentColor" stroke="none"/>
    </symbol>
    <symbol id="icon-cash" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round">
      <rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.6"/><path d="M6 12h.01M18 12h.01"/>
    </symbol>
    <!-- Category marks (card micro-labels + chips) -->
    <symbol id="cat-disposables" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round">
      <rect x="5.5" y="1.5" width="5" height="10" rx="1.5"/><path d="M8 13.5v1"/>
    </symbol>
    <symbol id="cat-pods" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round">
      <rect x="3" y="4" width="4.5" height="9" rx="1.2"/><rect x="9" y="6" width="4" height="7" rx="1.2"/>
    </symbol>
    <symbol id="cat-batteries" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round">
      <rect x="1.5" y="5" width="11" height="6" rx="1.4"/><path d="M14.5 7v2"/>
    </symbol>
    <symbol id="cat-e-juice" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
      <path d="M8 1.8s3.8 4.2 3.8 6.7a3.8 3.8 0 1 1-7.6 0C4.2 6 8 1.8 8 1.8z"/>
    </symbol>
    <symbol id="cat-cartridges" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round">
      <rect x="5" y="2.5" width="6" height="11" rx="3"/><path d="M8 2.5V1"/>
    </symbol>
    <symbol id="cat-kits" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
      <path d="M2 5l6-2.5L14 5v6l-6 2.5L2 11z"/><path d="M2 5l6 2.5L14 5M8 7.5v6"/>
    </symbol>
  </svg>

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
        <p class="result-count micro-label" id="result-count" aria-live="polite"></p>
        <div class="product-grid" id="product-grid"></div>
        <div class="empty-state" id="empty-state" hidden>
          <svg class="empty-state__icon" aria-hidden="true"><use href="#icon-box"></use></svg>
          <p class="empty-state__text">No products match.</p>
          <button class="btn btn--ghost" id="clear-filters">Clear filters</button>
        </div>
        <div class="error-panel" id="error-panel" hidden role="alert">
          <p class="error-panel__title">Couldn't load the catalog.</p>
          <p class="error-panel__text">products.json failed to load. Check that the file sits next to index.php.</p>
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

  <script src="app.js"></script>
</body>
</html>
