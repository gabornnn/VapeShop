# Vape Shop Frontend — Design Document

Date: 2026-10-19
Status: Approved — builder check complete, requester questions resolved
Scope: Frontend only. No backend, no real payments, no database.

---

## 1. Intent and Why

Build the complete frontend for a premium e-commerce vape shop: a responsive
storefront with an age gate, a live-updating cart, and a single-page 4-step
checkout that ends in a simulated order confirmation.

The deliverable is meant to read as production-ready frontend code — modular,
commented, and structured so that the mock data layer can be swapped for a real
API later without touching the UI code.

This is a coursework/portfolio-style project running on **XAMPP** (local PHP
server, no build tooling).

---

## 2. Goals

- Age verification as a full-screen brand moment that blocks the catalog until confirmed.
- Responsive product catalog: 6 categories, **exactly 40 products** (5–10 per category, unevenly distributed), filterable and searchable.
- Product quick-view modal from any card.
- Cart drawer with live quantity editing, per-line remove, and recalculated totals.
- Cart count badge in the header that updates in real time.
- Single-page 4-step checkout: Order Summary → Shipping → Payment → Confirmation.
- Form validation gating order placement.
- Simulated submit that clears the cart and shows a receipt with a unique reference number.
- Fully responsive across mobile / tablet / desktop.
- Clean hover, active, and slide-in transitions.

## 3. Non-Goals / Anti-Goals

- No backend, no API calls, no real payment processing. Payment selection is UI-only.
- No user accounts, login, session handling, or order history.
- No server-side rendering of the catalog; products come from a static JSON file.
- No persistence of orders beyond the confirmation view (localStorage holds only cart + age gate).
- No blog, reviews, wishlist, coupon codes, or promo engine.
- No framework (React/Vue) and no CSS framework (Tailwind) — explicitly rejected by the requester.
- No product detail *pages* — quick-view modal only, single page throughout.

---

## 4. Constraints

- **Runtime:** XAMPP, entry point is the existing `index.php`.
- **Stack:** vanilla HTML/CSS/JS. No build step, no npm, no bundler, no dependencies.
- **Files:** `index.php` (markup), `style.css`, `app.js`, `products.json` (mock data).
- **Data shape:** every product lives in `products.json` behind one normalizer so a
  future `fetch('/api/products')` replaces it with a one-line change.
- **No external image assets exist** in the project. Product `image` fields ship with
  generated inline-SVG placeholders; a real URL pasted into that field later is used as-is.
- **Fonts:** Google Fonts via `<link>` is acceptable (Space Grotesk + Inter), with
  system-font fallbacks so the page still renders offline.

---

## 5. Decided Design

### 5.1 Visual direction — Neon / Cyber

| Token | Value | Use |
|---|---|---|
| `--bg` | `#07090c` | page background (near-black, not pure black — pure black kills glow) |
| `--surface` | `#101318` | cards, drawer, panels |
| `--surface-2` | `#161a21` | inputs, elevated rows |
| `--accent` | `#00f0ff` (cyan) | CTAs, active states, badge, focus rings |
| `--accent-2` | `#c6ff00` (acid lime) | secondary highlight: prices, success, free-shipping bar |
| `--text` | `#e8edf2` | primary copy |
| `--text-dim` | `#8b95a3` | secondary copy, labels |
| `--danger` | `#ff4d6a` | remove actions, validation errors |

Rules decided:
- Neon is **functional only**: Add-to-Cart buttons, cart badge count, active checkout
  step, price accents, hover glows. Body copy is never neon.
- Glow = `box-shadow: 0 0 20px rgba(0,240,255,.35)` on primary buttons only; cards get
  a 1px accent border that intensifies on hover, not a permanent glow.
- Faint grid-line texture behind the header band only.
- Uppercase, letter-spaced micro-labels (`/// 02 — CART`) as section markers.
- Type: **Space Grotesk** (display/headings), **Inter** (body), monospace for
  reference numbers and quantities.
- Transitions: 150ms for hover/active, 300ms for drawer/modal, `ease-out`.
- 8px spacing scale (`4 / 8 / 16 / 24 / 32 / 48 / 64`).

### 5.2 Age gate — full-screen brand moment

- `position: fixed`, full viewport, rendered above the **already-laid-out** catalog.
- Catalog behind gets `filter: blur(12px)` + a dark scrim, so shapes and color are
  visible but nothing is legible — reads as "confirm to see through."
- Centered content: wordmark/logo (shop currently has **no name** — a neutral
  "VAPE CO." style placeholder wordmark with a neon accent mark, flagged as easily
  renameable), an oversized **21+** as the dominant element, one-line statement
  ("You must be 21 or older to enter"), two buttons:
  - `I AM 21 OR OLDER` — solid accent, glows on hover.
  - `I AM UNDER 21` — ghost/outline.
- Confirm → overlay fades out **while** the blur lifts, one continuous 400ms
  transition (page snaps into focus). Under 21 → polite in-place "come back later"
  state, never `alert()`.
- Answer stored in `localStorage` (`ageVerified`) so refresh doesn't nag.
- Accessibility: `role="dialog" aria-modal="true" aria-labelledby`, focus trapped
  inside the gate, `Esc` does not dismiss it, body scroll locked while open.

### 5.3 Layout & header

- Sticky header: wordmark left, category nav center on desktop, search field, then
  **cart button with count badge** right.
- **Search and category chips share one query state** — header search and the chip
  row drive the same `activeCategory + query` filter, so results can never diverge
  between the two controls.
- Badge is a small accent-colored circle over the cart icon, count animated with a
  brief scale-pop on change. Hidden when count is 0 (icon remains).
- **No hero section.** Page order is: header → (category chip strip) → result count
  → grid → footer. The faint grid-line texture lives in the header band only.
  Decided by requester, 2026-10-19.
- Mobile: header collapses to wordmark + search icon + cart. The search icon toggles
  an expanding full-width field beneath the header; category chips become a
  horizontally scrollable strip below that.
- **Header during checkout:** the header stays visible (sticky) but the cart button
  is disabled and the step indicator takes the nav slot, so the wordmark and page
  context never disappear mid-order.

### 5.4 Catalog

- Filter chip row: `All / Disposables / Pods / Batteries / E-Juice / Cartridges / Kits`.
  Active chip = accent fill, inactive = 1px border on surface.
- Search input matches product name, flavor, and nicotine strength (case-insensitive).
- **Filters and search combine**, and both the chip row and the header search field
  write to the same query state (see §5.3).
- Result count shown above the grid ("40 products" / "7 products"). Shared empty
  state for both no-filter-match and no-search-match: icon + "No products match"
  + clear-filters button (one component, not two).
- **Grid (mobile-first, explicit columns — the `minmax()` formula alone cannot hit
  the breakpoint table):**

  | Breakpoint | Columns |
  |---|---|
  | default (<480px) | **1-up, full bleed** |
  | ≥480px | 2-up |
  | ≥768px | 3-up |
  | ≥1200px | 4-up |

- **Card:** image area (SVG placeholder, aspect-ratio 1:1, dark gradient ground),
  category micro-label, product title, **one meta line** (strength · flavor), price
  (accent-2), `+ Add to Cart` button. The meta line is **omitted entirely — no
  reserved gap —** when a product has neither `strength` nor `flavor`.
  Card hover: lift 4px + border glow.
- **Loading / error:** skeleton cards while `products.json` loads; if the fetch
  fails, an inline error panel with a `Retry` button (never a blank grid).

### 5.5 Product quick-view

- Clicking a card body (not the Add button) opens a centered modal over a dimmed page:
  larger image, full description, category, **nicotine strength selector** (radio
  pills where a product offers multiple), **flavor selector** where applicable,
  price, quantity stepper, `Add to Cart`.
- Closing: `X`, backdrop click, `Esc`. Focus trapped, focus returned to the card on close.
- Selectors default to the product's first variant; selections are included in the
  cart line so the same product in two strengths is two lines (key = `id + strength + flavor`).

### 5.6 Cart state & drawer

- **State:** single `cart` array in `localStorage` (`cart_v1`):
  `{ id, name, price, image, qty, strength, flavor }`.
- **Store shape:** plain JS module-object with `state` and a `render()` pass —
  every mutation goes through one function (`addToCart`, `setQty`, `removeFromCart`,
  `clearCart`) so badge + drawer + totals can never disagree.
- **Robustness:** every `localStorage` read is wrapped in try/catch and falls back to
  an empty cart / unverified gate rather than throwing.
- **Quantity limits:** stepper min 1, max 10. `+` disabled at 10 in drawer and
  quick-view; `addToCart` clamps to 10 instead of erroring.
- **Placeholder art:** one inline `<svg><symbol>` sprite defined once in `index.php`,
  referenced with `<use>` per card — not 40 duplicated inline SVG documents.
- **Drawer:** right-side slide-over (full-width sheet on mobile), 300ms transform,
  backdrop dim + click-to-close, `Esc` to close, scroll lock.
  Header `/// CART` + item count; empty state with "Browse products" CTA.
- Each line: thumbnail, name, strength/flavor sub-line, unit price, **− / qty / +**
  stepper (disabled at 1 and at a max of 10), line total, remove (trash) button.
- **Totals block** recalculates on every mutation:
  - Subtotal = Σ line totals
  - VAT (12%) = subtotal × 0.12
  - Shipping = ₱150 flat, **free above ₱2,000 subtotal**
  - Free-shipping progress bar: "You're ₱X from free shipping"; at/above threshold
  the bar is full and reads "Free shipping unlocked".
  - **Total** = subtotal + VAT + shipping (bold, accent)
- Money formatting: `Intl.NumberFormat('en-PH', { style:'currency', currency:'PHP' })`.
- `Checkout` button (accent) disabled when cart is empty.

### 5.7 Checkout — single page, 4 steps

Full-screen panel layered above the catalog view (never a page reload) with a step
indicator: `01 Summary → 02 Shipping → 03 Payment → 04 Confirmation`.

**Step & navigation rules (edge cases pinned):**
- `Back` on step 1 returns to the catalog (cart drawer reopens from the header).
- If the cart becomes empty during step 1 (last line removed), the panel
  auto-closes back to the catalog with an empty-cart notice — no dead checkout.
- `Back` works on steps 2 and 3. On step 4 back is disabled (order is "placed").
- **Only cart + shipping draft persist.** The current step is *not* persisted: a
  refresh mid-checkout returns to the catalog (step-4 refresh after a cleared cart
  is the same thing — harmless).

- **Step 1 — Order Summary:** read-only line items with qty steppers still editable,
  totals block identical to the drawer, `Continue to shipping`.
- **Step 2 — Shipping Details:** fields — full name, contact number, delivery
  address, delivery notes (textarea, optional). Inline validation on blur and on
  continue: required fields non-empty, phone matches a loose PH pattern
  (`09XXXXXXXXX` or `+639XXXXXXXXX`), **address minimum 10 characters**.
  Errors are inline text under the field in `--danger`, input border turns danger,
  focus jumps to first invalid field. `Back` / `Continue to payment`.
- **Step 3 — Payment Selection:** three radio cards — Credit/Debit Card, E-Wallet /
  GCash, Cash on Delivery, each with an icon and one-line description. Selected card
  gets the accent border + glow. COD shows an extra note; card/GCash show a
  non-functional "you'll be redirected after order placement" hint — **no card
  numbers are collected**, since payment is simulated. `Back` / `Place Order`.
- **Step 4 — Confirmation:** `Place Order` runs a **simulated** submission
  (brief loading state, ~800ms) → validates everything one final time → clears the
  cart → renders the receipt:
  - "ORDER CONFIRMED" with a checkmark mark
  - **Unique reference number:** `VPS-` + date `YYMMDD` + `-` + 4 random digits (e.g. `VPS-261019-4821`)
  - Order date/time, **recipient shown as first name + last initial** ("Juan D."),
    payment method label, line items, and the final totals
  - `Continue shopping` → returns to catalog, cart is empty, badge is 0
- Draft shipping details persist under `shipping_draft_v1` so a mid-checkout refresh
  doesn't wipe the form; **cleared on confirmed order** along with the cart.
- Back navigation works across steps 1–3; once confirmed, back is disabled
  (order is "placed").

### 5.8 Responsiveness breakpoints

| Breakpoint | Behavior |
|---|---|
| ≥1200px | 4-up grid, full header nav, drawer 420px wide |
| 992–1199px | 3-up grid, drawer 400px |
| 768–991px | 3-up grid, checkout fields 1-col, quick-view stacks image over details |
| 480–767px | 2-up grid, mobile header, chips scroll horizontally, drawer = full width |
| <480px | 1-up grid, full-bleed modals, stacked checkout buttons |

### 5.9 UX standards applied

- All interactive elements have visible hover **and** focus-visible states
  (2px accent outline, offset 2px) for keyboard use.
- Modals/drawer trap focus and restore it on close.
- `prefers-reduced-motion` disables slide/blur transitions.
- Buttons show a brief disabled/`loading` state during simulated submit.
- Touch targets ≥44px on mobile steppers and CTAs.

---

## 6. Data contract (what a backend must eventually satisfy)

```json
{
  "id": "dsp-001",
  "category": "disposables",
  "name": "Nova X5000 Disposable",
  "description": "5000 puffs, rechargeable, mesh coil.",
  "price": 1299,
  "image": "",
  "strength": "50mg",
  "strengths": ["20mg", "35mg", "50mg"],
  "flavors": ["Blue Razz", "Mango Ice"],
  "featured": true
}
```

- `image: ""` → renders the generated SVG placeholder; a URL renders the image.
- `strengths` / `flavors` optional → quick-view hides selectors when absent.
- `featured` is **reserved, no UI consumer today** (there is no hero section); kept
  so a future homepage can use it without a schema change.
- Category keys are lowercase slugs matching the filter chips.

## 7. Mock content

- 6 categories × 5–10 products = **exactly 40 items**, written directly into
  `products.json` (uneven split, e.g. 8 disposables, 6 pods, 6 batteries,
  7 e-juice, 5 cartridges, 8 kits).
- Names, brands, descriptions, and prices are invented but plausible for a PH market
  (₱80–₱4,500 range). No real brand logos or trademarked product imagery.
- Flavor/nicotine variety is spread so filters and search produce visibly different
  result sets.

---

## 8. What's left to the builder

- Exact markup structure inside `index.php` (semantic sections, ARIA wiring).
- CSS architecture: tokens block → base → layout → components → responsive,
  commented per section.
- JS module organization inside `app.js`: data loading → state/store → renderers
  (catalog, quick-view, drawer, checkout, receipt) → event wiring → init.
- The placeholder wordmark design (no shop name exists yet).
- Any micro-decisions (icon set — inline SVG, exact easing curves) not pinned above.

## 9. Builder's calls (settled during review — no requester input needed)

Made by the builder and pinned here so the plan doesn't re-litigate them:

1. **Product count is exactly 40**, unevenly distributed (§7). Resolves the
   36–60 / ~40 mismatch.
2. **Grid columns are set by explicit media queries**, not the `minmax()` formula
   alone (§5.4) — the formula can't produce 1-up under ~500px.
3. **`featured: true`** stays in the data contract but has **no UI consumer** (no
   hero section exists); ignored by render code.
4. **Card meta line** is omitted entirely when a product has neither `strength`
   nor `flavor` — no reserved gap (§5.4).
5. **`strengths[0]` drives display** when present, else `strength`; cards always
   show a single strength value.
6. **Mobile search** = icon toggling an expanding field under the header (§5.3).
7. **Receipt name** shows first name + last initial (§5.7).
8. **Free-shipping bar** full + "Free shipping unlocked" at threshold (§5.6).
9. **Qty clamped at 10** everywhere; `+` disabled at max (§5.6).
10. **Loading/error states** for `products.json`: skeleton cards + retry panel (§5.4).
11. **`shipping_draft_v1`** is the draft key, cleared on confirmed order (§5.7).
12. **Under-21 state is final for the session** — no escape link, no `alert()` (§5.2).
13. **`localStorage` reads wrapped in try/catch** with safe fallbacks (§5.6).
14. **One shared empty state** for filter and search misses (§5.4).
15. **SVG placeholders ship as one `<symbol>` sprite** referenced by `<use>` (§5.6).
16. **Viewport units: `100dvh` with `100vh` fallback**, plus `overscroll-behavior`
    on scroll-locked layers (age gate, drawer, checkout) to avoid mobile
    scroll-through bugs (§5.2, §5.9).

## 10. Requester decisions (resolved 2026-10-19)

1. ~~Shop name / wordmark~~ → **Neutral "VAPE CO." placeholder accepted.** Rename is
   a single find-and-replace across header, `<title>`, age gate, and receipt.
2. ~~Money defaults~~ → **Confirmed final:** ₱ PHP currency, 12% VAT on subtotal,
   ₱150 flat shipping, free above ₱2,000 subtotal.
3. ~~Hero / featured section~~ → **None.** Header → chips → grid → footer.

