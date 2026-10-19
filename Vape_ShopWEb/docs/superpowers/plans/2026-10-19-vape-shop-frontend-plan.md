# Vape Shop Frontend — Implementation Plan

Date: 2026-10-19
Design source of truth: `docs/superpowers/specs/2026-10-19-vape-shop-frontend-design.md`
Rule: the design doc wins on any conflict. No code until a task says so.

**Stack:** vanilla HTML/CSS/JS in `index.php` + `style.css` + `app.js` + `products.json`. No build step, no dependencies.

**Build order principle:** every phase ends in a state that runs and can be visually checked in the browser. Nothing is built on top of a broken layer below it.

---

## Phase 0 — Foundation (shell, tokens, data)

### Task 0.1 — `products.json`: the mock catalog
- Write exactly **40 products**, uneven split: 8 disposables, 6 pods, 6 batteries, 7 e-juice, 5 cartridges, 8 kits.
- Every object follows the data contract in §6 of the design doc: `id`, `category`, `name`, `description`, `price`, `image` (`""` → placeholder), `strength`, `strengths[]`, `flavors[]`, `featured`.
- Prices ₱80–₱4,500. Flavor/nic variety spread so filters and search return visibly different result sets.
- **Done when:** JSON validates (loads without error), all 6 category slugs present, each category has 5–10 items.

### Task 0.2 — `style.css`: design tokens + base layer
- `:root` tokens exactly as §5.1: `--bg #07090c`, `--surface #101318`, `--surface-2 #161a21`, `--accent #00f0ff`, `--accent-2 #c6ff00`, `--text #e8edf2`, `--text-dim #8b95a3`, `--danger #ff4d6a`, spacing scale `4/8/16/24/32/48/64`, transition timings `150ms / 300ms / 400ms`, radius variables.
- Base: reset, `box-sizing`, body on `--bg` + Space Grotesk/Inter font links with system fallback, `::selection`, focus-visible outline (2px accent, offset 2px), `.micro-label` uppercase/letter-spaced helper, `prefers-reduced-motion` block.
- **Done when:** page renders dark with correct fonts, no layout yet.

### Task 0.3 — `index.php`: semantic markup skeleton
- `<header>` (wordmark "VAPE CO.", search, cart button + badge slot), `<main>` with `<section id="catalog">` (chip row + count + grid mount point), footer.
- Empty mount points: `#age-gate`, `#cart-drawer`, `#quick-view`, `#checkout`, `#toast`.
- **One inline SVG `<symbol>` sprite** for all icons (cart, plus, minus, trash, close, search, check, category marks) — `<use>` references only, no duplicated documents.
- **Done when:** static shell loads on XAMPP (`http://localhost/.../index.php`) with header and empty grid.

---

## Phase 1 — Age gate (front door)

### Task 1.1 — Gate markup + styles
- `position: fixed` full-viewport, `role="dialog" aria-modal="true" aria-labelledby`, focus trap, `Esc` does **not** dismiss, body scroll locked with `overscroll-behavior: contain`, `100dvh` with `100vh` fallback.
- Content: placeholder wordmark, oversized **21+**, one-line statement, `I AM 21 OR OLDER` solid accent + `I AM UNDER 21` ghost button.
- Catalog beneath gets `filter: blur(12px)` + dark scrim while gate is open.
- **Done when:** gate covers everything, background visibly blurred, nothing behind it readable.

### Task 1.2 — Gate behavior
- `localStorage['ageVerified']` gate on init (try/catch wrapped).
- Confirm → overlay fades **while** blur lifts, one continuous 400ms transition.
- Under 21 → polite in-place state change, no `alert()`, final for the session.
- **Done when:** confirm reveals sharp catalog; refresh does not re-nag; under-21 state is terminal.

---

## Phase 2 — Catalog, filters, search, quick-view

### Task 2.1 — Data load + render pipeline
- `fetch('products.json')` with skeleton cards while loading and an inline error panel with `Retry` on failure.
- `renderCatalog(products)` driven by a single `state = { activeCategory:'all', query:'' }`.
- Product cards per §5.4: SVG placeholder art via `<use>`, category micro-label, title, single meta line (**omitted with no gap** if no strength/flavor), price in `--accent-2`, `+ Add to Cart`. Hover: lift 4px + border glow.
- **Done when:** 40 cards render with correct prices and placeholder art.

### Task 2.2 — Filters, search, empty state
- Chip row `All / Disposables / Pods / Batteries / E-Juice / Cartridges / Kits`; active chip accent-filled.
- Header search field and chips write to the **same** query state; matches name, flavor, strength, case-insensitive.
- Result count above grid; one shared empty state (icon + "No products match" + clear-filters).
- **Done when:** combining a chip + search narrows results and count updates; no-match shows one clean empty state.

### Task 2.3 — Product quick-view modal
- Opens on card body click (not the Add button): larger image, description, category, strength pills (from `strengths[]`), flavor selector (from `flavors[]`), price, qty stepper (max 10), `Add to Cart`.
- Selectors hidden entirely when arrays absent; selections go into the cart line (line key = `id + strength + flavor`).
- Close via `X`, backdrop, `Esc`. Focus trap + focus returned to originating card.
- **Done when:** adding from quick-view creates a distinct cart line from a different strength of the same product.

---

## Phase 3 — Cart state, drawer, totals

### Task 3.1 — Store + persistence
- Single `cart` array in `localStorage['cart_v1']`; shape `{ id, name, price, image, qty, strength, flavor }`.
- Only four mutation functions: `addToCart`, `setQty`, `removeFromCart`, `clearCart` — each ends in one `render()` that refreshes badge, drawer, and totals together.
- All storage access try/catch'd. Qty clamps 1–10.
- **Done when:** three rapid adds can't produce a badge/drawer mismatch.

### Task 3.2 — Header badge
- Count badge over cart icon, scale-pop animation on change, hidden at 0 (icon stays).
- **Done when:** count matches cart contents at all times, including after refresh.

### Task 3.3 — Slide-over drawer
- Right slide-over, 300ms transform, 420px desktop / 400px mid / full-width mobile; backdrop dim, click-to-close, `Esc`, scroll lock, focus trap.
- Header `/// CART` + count; line items with thumbnail, name, strength/flavor sub-line, unit price, −/qty/+ stepper (disabled at 1 and 10), line total, trash remove.
- Empty state with "Browse products" CTA. `Checkout` button disabled when empty.
- **Done when:** every quantity edit is instantly reflected in badge, line total, and totals block.

### Task 3.4 — Totals math
- Subtotal → VAT = subtotal × 0.12 → shipping = ₱150, **₱0 above ₱2,000** → Total.
- `Intl.NumberFormat('en-PH', {style:'currency', currency:'PHP'})` everywhere.
- Free-shipping progress bar: "You're ₱X from free shipping" / full + "Free shipping unlocked".
- **Done when:** a ₱2,001 cart shows free shipping and totals reconcile to the sum of parts.

---

## Phase 4 — Checkout (4 steps, single page)

### Task 4.1 — Step shell + navigation
- Full-screen panel over catalog, step indicator `01 Summary → 02 Shipping → 03 Payment → 04 Confirmation`.
- Header stays visible but cart button disabled during checkout (§5.3).
- Back rules: step 1 → catalog; steps 2–3 → previous step; step 4 → disabled.
- Cart emptied during step 1 → auto-close to catalog with empty-cart notice.
- Step position **not** persisted; refresh returns to catalog (harmless).
- **Done when:** forward/back works across steps 1–3 with no page reload.

### Task 4.2 — Step 1: Order Summary
- Line items editable with qty steppers; totals block identical to drawer (reuse the same render function — do not duplicate the math).
- **Done when:** changing qty here updates the drawer and badge simultaneously.

### Task 4.3 — Step 2: Shipping form + validation
- Fields: full name, contact number, delivery address (min 10 chars), delivery notes (optional textarea).
- Validate on blur and on Continue: required non-empty, phone matches `09XXXXXXXXX` or `+639XXXXXXXXX`.
- Inline errors in `--danger` under each field, danger border, focus jumps to first invalid field.
- Draft persisted to `localStorage['shipping_draft_v1']` (try/catch), restored on load.
- **Done when:** you cannot advance with an invalid field, and a refresh restores what was typed.

### Task 4.4 — Step 3: Payment selection
- Three radio cards: Credit/Debit Card, E-Wallet / GCash, Cash on Delivery — icon + one-liner each; selected card gets accent border + glow.
- **No card numbers collected** anywhere. Card/GCash show a "you'll be redirected after order placement" hint; COD shows a note.
- **Done when:** exactly one method can be selected and `Place Order` requires a selection.

### Task 4.5 — Step 4: Simulated submit + receipt
- `Place Order` → loading state (~800ms, button disabled) → final full validation → `clearCart()` + clear `shipping_draft_v1` → receipt view.
- Receipt: "ORDER CONFIRMED" + check mark, reference `VPS-YYMMDD-####`, date/time, recipient as first name + last initial, payment label, line items, final totals.
- `Continue shopping` → catalog, badge 0.
- **Done when:** cart and badge are empty after confirmation, reference is unique per order, and a back-navigation is impossible after placing.

---

## Phase 5 — Responsive, a11y, QA

### Task 5.1 — Breakpoint pass (mobile-first, explicit columns)
- <480px 1-up full-bleed, ≥480px 2-up, ≥768px 3-up, ≥1200px 4-up (explicit media queries — **not** the `minmax()` formula alone).
- Mobile header: wordmark + search icon (toggles expanding field) + cart; chips horizontally scrollable.
- Drawer 420/400/full-width; quick-view stacks image-over-details at ≤767px; checkout buttons stack <480px; checkout fields 1-col at ≤767px.
- Touch targets ≥44px.
- **Done when:** every breakpoint in the §5.8 table matches on a real resize.

### Task 5.2 — Interaction polish + a11y sweep
- Hover + focus-visible on all interactive elements; slide/blur transitions respect `prefers-reduced-motion`.
- Focus traps and focus restore verified on gate, drawer, quick-view, checkout.
- Keyboard-only run: Tab through header → chips → cards → drawer → checkout without trap escapes.
- **Done when:** full order flow completable using only the keyboard.

### Task 5.3 — End-to-end QA script
Walk this exact path and confirm no console errors at any step:
1. First visit → age gate → blur lifts → catalog.
2. Filter to a category → search within it → clear filters.
3. Open quick-view → pick a strength/flavor → add → badge = 1.
4. Add a second product from its card → badge = 2.
5. Drawer → bump qty to 10 (`+` disables) → drop one line → subtotal/VAT/shipping/total recalc.
6. Hit ₱2,000+ → free-shipping bar completes.
7. Checkout → back to catalog → return → invalid phone blocks advance → fix → advance.
8. Select GCash → Place Order → loading → receipt with unique reference.
9. Continue shopping → cart empty, badge 0.
10. Refresh at steps 2, 4, and after completion → nothing throws, no zombie cart.
11. Resize 375 / 480 / 768 / 992 / 1200 / 1440 px → layout matches table.
- **Done when:** all 11 pass with a clean console.

---

## Out of scope (do not build)
Backend/API, real payments, accounts/login, order history, coupons, reviews, wishlist, frameworks, build tooling, product detail pages, hero section.

## Deliverable checklist against the brief
- [x] Age verification blocking overlay (21+)
- [x] Responsive product grid: image, title, strength/flavor, price, Add to Cart
- [x] Live header cart badge
- [x] Slide-over cart: unit prices, +/−, per-line remove, subtotal
- [x] Subtotal → tax → shipping → total
- [x] 4-step checkout with validation
- [x] Simulated submit → cart cleared → receipt with unique reference
- [x] Mobile/tablet/desktop breakpoints
- [x] Hover/active/slide-in transitions
- [x] Modular, commented components
- [x] JSON mock data ready for API swap
