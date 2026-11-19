# VAPE CO. — Website Refresh Design

Date: 2026-11-16
Status: Proposed — awaiting approval
Scope: Storefront, authentication, checkout/payments, customer account, admin panel, database, and visual system.
Implementation plan: `docs/superpowers/plans/2026-11-16-vape-shop-refresh-plan.md`

---

## 1. Intent

Make the shop feel trustworthy, calm, and easy to use while closing the gaps that matter most for a real vape store in the Philippines:

- Adults-only access that is enforced on every refresh without nagging on Back/Forward.
- Clear terms of agreement accepted at registration.
- A way to create the first admin account without editing SQL.
- Prices that read as VAT-inclusive, with no VAT surprise at checkout.
- Payments limited to GCash, Maya, and Cash, including a two-method split payment on one order.
- A light, low-glare visual system with consistent storefront, auth, account, and admin screens.
- Repair of the baseline UI/runtime defects found during the audit so the new screens are usable.

## 2. Decisions already made

| Area | Decision |
|---|---|
| Admin registration | Open registration for now. No secret key. A config flag will allow it to be closed after the first admin exists. |
| Split payments | Split tender on one order. The customer chooses two different methods and the two amounts must add up to the order total. |
| Payment methods | GCash, Maya (PayMaya), and Cash only. Card and bank transfer are removed. |
| Terms / policy | A Terms & Policy page plus a required acceptance checkbox on customer registration and admin registration. Not required at checkout. |
| Visual direction | Light minimal: off-white background, soft neutral surfaces, muted green accent, high readability, no neon or glow. |
| UI fix scope | General polish across storefront, auth, account, checkout, and admin: layout, spacing, contrast, typography, form states, responsiveness, and consistency. |

## 3. Goals

1. A visitor always sees the age gate after a hard refresh of the storefront, but not when returning with Back/Forward.
2. A customer cannot register without accepting the Terms & Policy.
3. A new installation can create its first admin through `admin/register.php`.
4. Every total in the UI is `subtotal + shipping`. VAT is included in the displayed prices and is never added as a separate line.
5. A customer can pay with one method or split the total between two different methods, and the server validates the allocation.
6. Admin and customer order views show the payment breakdown accurately, including split payments.
7. The storefront, auth pages, account pages, and admin panel look like one calm, light design system.
8. The existing admin/account runtime defects are fixed before the new views are layered on top.

## 4. Non-goals

- Real GCash/Maya API integration, payment redirects, or webhook reconciliation.
- Card payments, bank transfers, refunds, chargebacks, or installment financing.
- Saved payment methods or stored wallet references.
- Email/SMS verification, password reset, or two-factor authentication.
- Admin approval queues, staff roles beyond `admin`, or audit-log UI.
- Legal advice. The Terms & Policy copy is a launch-ready template and should be reviewed by the store owner or counsel before production.

## 5. Current-state audit (what the plan must account for)

### 5.1 Age gate
- `app.js` stores `localStorage['ageVerified']` and never shows the gate again after the first confirmation.
- The gate is only present on `index.php`; auth/admin pages do not load `app.js`.
- The gate must now distinguish a reload from Back/Forward.

### 5.2 Pricing and VAT
- `api/cart.php` computes `vat = subtotal × 0.12` and adds it to `total`.
- `api/checkout.php` recomputes VAT and stores `vat_amount`, then adds it to `total`.
- `app.js` has its own `VAT_RATE` and renders a “VAT (12%)” row.
- `account/order.php` and `admin/order.php` render VAT rows.
- Requirement: `total = subtotal + shipping`; no VAT line in cart, checkout, receipt, account, or admin.

### 5.3 Payments
- `orders.payment_method` is `ENUM('CARD','GCASH','COD')`.
- The checkout API accepts one method string and writes one column.
- The storefront offers Card, GCash, and Cash on Delivery.
- Requirement: GCash, Maya, Cash; one method or two-method split; per-payment breakdown.

### 5.4 Terms and admin onboarding
- No `terms.php`, no acceptance checkbox, no `terms_accepted_at` record.
- No public admin registration. Admins are created with `database/seed_admin.php`.

### 5.5 Baseline defects discovered
These are not new features, but they block the new order/payment screens and the “general polish” request:

- `get_flash()` is called by several account/admin pages but only `get_flashes()` exists.
- `audit_log()` is called by admin actions but is not defined.
- `orders.created_at` is queried in several places, but the schema column is `orders.placed_at`.
- `order_status_history.changed_at` is queried, but the schema column is `created_at`.
- Admin status lists use `CONFIRMED` and `PROCESSING`, but the schema uses `PENDING`, `PAID`, `PACKING`, `SHIPPED`, `DELIVERED`, `CANCELLED`.
- The CSS file has no definitions for the admin shell, admin tables/cards, account pages, order items, status chips, or timeline. Many PHP pages render unstyled.
- Several account/admin pages contain hard-coded dark-theme colors (`#00f0ff`, `#8b95a3`, `#e8edf2`) that will be unreadable or visually inconsistent on a light theme.

## 6. User flows

### 6.1 Storefront
```
Open storefront
  ├─ First visit or hard refresh → age gate
  │    ├─ “I am 21 or older” → storefront, session marked verified
  │    └─ “I am under 21” → polite denial, gate remains
  └─ Back/Forward or in-site navigation with session verified → storefront, no gate

Browse catalog → quick view → add to cart → cart drawer → checkout
  → 1 Summary → 2 Shipping → 3 Payment → 4 Confirmation
```

### 6.2 Registration
```
Customer: register.php
  → name, email, phone, birthdate, password, terms checkbox
  → server validates 21+, unique email, password policy, terms acceptance
  → account created, terms version recorded, signed in

Admin: admin/login.php → “Create an admin account”
  → admin/register.php
  → same registration fields + terms checkbox
  → role = admin, signed in, redirected to dashboard
```

### 6.3 Payment
```
Step 3 — Payment
  ├─ Single payment
  │    └─ choose GCash | Maya | Cash → amount equals order total
  └─ Split payment
       ├─ choose two different methods
       ├─ enter two positive amounts
       └─ amounts must add up to order total (₱0.01 tolerance)
  → Place Order
  → API re-computes total, re-validates payments, stores order_payments
  → Confirmation shows payment breakdown
```

### 6.4 Order views
```
Customer account/order.php and admin/order.php
  → show items, subtotal, shipping, total (no VAT line)
  → show payment breakdown (one row or two split rows)
  → show status timeline using the real schema columns
```

## 7. Visual design system — “Light minimal”

### 7.1 Palette

Use CSS custom properties. The values are chosen for soft contrast and low glare.

```css
:root {
  --bg: #f7f8f5;              /* warm off-white, not pure white */
  --surface: #ffffff;
  --surface-2: #eef1ec;
  --surface-3: #e2e7e1;
  --text: #1f2521;            /* charcoal, not pure black */
  --text-dim: #5b665e;
  --text-soft: #7a857d;
  --accent: #2f6f4e;          /* deep muted green */
  --accent-hover: #265a3f;
  --accent-soft: #e4f0e9;
  --accent-contrast: #ffffff;
  --danger: #b3261e;
  --danger-soft: #fbe9e7;
  --warning: #8a5a00;
  --warning-soft: #fff4d6;
  --info: #2f5d7a;
  --info-soft: #e6f0f6;
  --border: rgba(31, 37, 33, .12);
  --border-strong: rgba(31, 37, 33, .24);
  --focus: #2f6f4e;
  --shadow-sm: 0 1px 2px rgba(31, 37, 33, .06);
  --shadow-md: 0 10px 30px rgba(31, 37, 33, .10);
  --glow-accent: none;        /* kept for compatibility; never a neon glow */
}
```

Rules:
- No neon cyan/lime, no grid texture, no animated glow, no heavy black surfaces.
- Accent is used for primary actions, active states, links, and focus rings only.
- Large areas use `--bg` or `--surface`, not saturated color.
- Status colors use soft backgrounds and dark text, not bright fills.

### 7.2 Typography
- Display: `Space Grotesk`, weights 500/600/700.
- Body: `Inter`, weights 400/500/600.
- Body size: 16px, line-height 1.6.
- Headings: 600 weight, line-height 1.2, sentence case.
- `micro-label`: uppercase, 11px, letter-spacing .12em, `--text-soft`. Use sparingly.
- Buttons: 14px, 600 weight, sentence case, no wide uppercase tracking.
- Long-form legal text: max-width 72ch, line-height 1.7.

### 7.3 Spacing, radius, elevation
- Keep the 4/8/16/24/32/48/64 scale (`--s1`…`--s7`).
- Radius: `--radius-sm: 8px`, `--radius: 12px`, `--radius-lg: 16px`.
- Elevation: `--shadow-sm` on cards; `--shadow-md` only on overlays (drawer, checkout, modals).
- Avoid box shadows on buttons except a subtle focus ring.

### 7.4 Components

**Buttons**
- Primary: `--accent` background, white text, no glow.
- Secondary: `--surface` background, `--border` border, `--text`.
- Ghost: transparent, `--text-dim`, border appears on hover.
- Danger: `--danger` text/border on a soft background.
- Min height 44px; `--lg` 52px; disabled opacity .45.

**Forms**
- Labels above inputs; required marker `*` in `--danger`.
- Inputs: white, 1px `--border`, 10px radius, min-height 44px, 16px text.
- Focus: 2px `--focus` ring, 2px offset.
- Error: `--danger` border and message; `aria-invalid="true"` and `aria-describedby`.
- Hint text: `--text-soft`, 13px.
- `.check-field`: a bordered row with a native checkbox, used for terms acceptance.

**Cards**
- White surface, 1px border, 12px radius, `--shadow-sm`.
- No glow. No dark gradients.
- Card headers: 16px/600 title with a 1px bottom border.

**Storefront header**
- Sticky, `--surface` with a subtle bottom border, no backdrop blur needed.
- Wordmark in `--text`; accent dot only.
- Search field: `--surface-2` background, border on focus.
- Cart button: ghost with a count badge in `--accent`.

**Age gate**
- Full-screen overlay in `rgba(247,248,245,.94)`.
- Background page gets `filter: blur(4px)` only; no dark scrim.
- Large `21+` mark in `--accent-soft` with `--accent` text.
- Statement in `--text`; actions stacked on mobile.
- Under-21 denial is a polite in-place panel, not an alert.

**Cart drawer and checkout**
- White surfaces, soft backdrop `rgba(31,37,33,.28)`.
- Drawer header/footer separated by 1px borders.
- Checkout panels are white cards; step indicator uses accent for active, `--text-soft` for upcoming.
- Payment cards: white, 1px border, selected state `--accent` border + `--accent-soft` background.
- Split payment legs: two bordered cards with method select and amount input; a summary line shows “Remaining”.
- Receipt: white, centered check mark in `--accent-soft`, reference in `--text-soft`.

**Admin**
- Layout: sidebar 240px + content, background `--bg`.
- Sidebar: white, right border, nav links with icon + label, active link `--accent-soft` + `--accent` text.
- Content: max-width 1280px, 24px padding.
- Page header: title left, action right.
- Stat cards: white, 1px border, label in `--text-soft`, value in `--text`.
- Tables: white, header `--surface-2`, row border, hover `--surface-2`, horizontal scroll wrapper on small screens.
- Status chips: soft background + dark text per status.

**Account**
- Container max-width 1120px.
- Header with title and nav pills.
- Two-column grid on desktop, one column below 900px.
- Cards for profile, password, order items, status, address, timeline.
- Timeline: vertical line in `--border`, dots in `--accent`, status text 600.

**Legal page**
- Centered column, max-width 760px.
- Title + “Last updated” meta.
- Table of contents as a simple list of anchor links.
- Section headings 20–24px, body 16px/1.7.
- Contact block in a soft `--surface-2` card.

### 7.5 Accessibility
- Body text contrast at least 4.5:1; large text at least 3:1.
- Focus-visible ring on every interactive element.
- Minimum 44×44px touch targets.
- Form errors announced with `role="alert"` or linked by `aria-describedby`.
- Status is never communicated by color alone; chips include text.
- `prefers-reduced-motion` reduces transitions to near-zero.
- Tables have `<th scope="col">` where practical.
- Dialogs keep the existing focus trap and `aria-modal`.
- Age gate cannot be dismissed with Esc.

### 7.6 Motion
- Hover/press: 150ms.
- Drawer/checkout: 300ms.
- Age gate reveal: 300–400ms.
- No infinite or attention-seeking animation except the loading spinner.

## 8. Data model

### 8.1 `users` additions

```sql
terms_accepted_at DATETIME DEFAULT NULL,
terms_version     VARCHAR(20) DEFAULT NULL,
```

- `terms_accepted_at` is set when the customer or admin submits a registration form with the terms checkbox checked.
- `terms_version` stores `TERMS_VERSION` so a future terms change can be detected.
- Existing users keep `NULL`; they are not forced to re-accept on next login in this iteration.

### 8.2 `orders.payment_method`

Change from a restrictive `ENUM('CARD','GCASH','COD')` to `VARCHAR(20) NOT NULL`.

New values written by the application:
- `GCASH` — single GCash payment.
- `MAYA` — single Maya payment.
- `CASH` — single cash payment.
- `SPLIT` — two payment rows in `order_payments`.

Legacy values that may remain on old rows:
- `CARD`, `COD`. These are displayed as “Card (legacy)” and “Cash on Delivery (legacy)” and are never offered in the new checkout.

### 8.3 `order_payments`

```sql
CREATE TABLE order_payments (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id   INT UNSIGNED NOT NULL,
  method     VARCHAR(20) NOT NULL,
  amount     DECIMAL(10,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_order_payments_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  INDEX idx_order_payments_order (order_id),
  CONSTRAINT chk_order_payments_amount CHECK (amount > 0)
) ENGINE=InnoDB;
```

Rules:
- Single payment: one row; `orders.payment_method` equals the method; amount equals the order total.
- Split payment: exactly two rows with two different methods; amounts are positive and sum to the order total; `orders.payment_method = 'SPLIT'`.
- The server recomputes the total and re-validates the allocation. Client-side totals are never trusted.
- Existing orders are backfilled with one row using their old `payment_method` and `total`.

### 8.4 VAT-inclusive semantics

- `subtotal` is the gross amount of the items (VAT already inside).
- `vat_amount` stores the VAT portion already included in the subtotal:
  `vat_amount = subtotal − (subtotal ÷ (1 + VAT_RATE))`.
- `total = subtotal + shipping_fee`.
- VAT is never added to the total and is never rendered as a line item in cart, checkout, receipt, account, or admin.
- `vat_rate` and `vat_amount` remain in the database for accounting/reporting only.
- The Terms page states that displayed prices are VAT-inclusive.

## 9. Functional specifications

### 9.1 Age gate

**Storage:** `sessionStorage['ageVerified'] = '1'` after confirmation. Remove the old `localStorage['ageVerified']` key when encountered to avoid stale behavior.

**Show rules:**

| Situation | Navigation type | sessionStorage verified | Gate shown? |
|---|---|---|---|
| First visit / new tab | `navigate` | no | Yes |
| Hard refresh | `reload` | yes or no | Yes |
| In-site link to another storefront page | `navigate` | yes | No |
| Browser Back/Forward | `back_forward` | yes | No |
| Back/Forward after refresh | `back_forward` | yes | No |
| New tab | `navigate` | no (new tab storage) | Yes |
| sessionStorage unavailable | any | treated as no | Yes |

**Implementation rules:**
- Detect reload with `performance.getEntriesByType('navigation')[0].type === 'reload'`.
- Fall back to `performance.navigation.type === 1` if the newer API is unavailable.
- If neither is available, show the gate (fail closed for age verification).
- On `pageshow` with `event.persisted === true` (bfcache), hide the gate and remove `gate-open`.
- “I am under 21” remains an in-place denial. A refresh may show the gate again; no permanent cookie is set.
- The gate remains `Esc`-dismiss-proof and focus-trapped.
- Scope: storefront `index.php`. Auth pages enforce 21+ through registration/login; admin pages are staff-only. This is documented so it is not mistaken for a site-wide gate.

### 9.2 Terms & Policy

- New page: `terms.php`, publicly accessible.
- `TERMS_VERSION` constant in `config/config.php`, initial value `2026-11-16`.
- Required checkbox on:
  - `register.php` (customer)
  - `admin/register.php` (staff)
- Server-side validation: if unchecked, the form is rejected with a field error; no account is created.
- On successful registration, store `terms_accepted_at = NOW()` and `terms_version = TERMS_VERSION`.
- Links:
  - Storefront footer: “Terms & Policy”.
  - Customer registration: inside the checkbox label and a standalone link.
  - Admin registration: inside the checkbox label.
  - Admin login: link to admin registration.
- Checkout does **not** add a terms checkbox.

### 9.3 Customer registration changes

- Add `terms` checkbox and `terms` error.
- Insert `terms_accepted_at` and `terms_version`.
- Keep the existing 21+ birthdate check, unique email check, and password policy.
- Keep the existing autologin after registration.
- Add `.check-field` styling.
- Add a link to `terms.php` in the login page footer area as well.

### 9.4 Admin registration

New page: `admin/register.php`.

**Access:**
- If `ALLOW_OPEN_ADMIN_REGISTRATION` is `false`, return HTTP 403 with a short “Staff registration is closed” page and a link to `admin/login.php`.
- If an admin is already signed in, redirect to `admin/index.php`.
- If a customer is signed in, sign them out or redirect with a flash asking them to sign out first. Simpler and safer: redirect to `../index.php` with “Sign out before registering as staff.”
- Otherwise, show the form.

**Fields:**
- First name, last name, email, phone, birthdate, password, confirm password, terms checkbox.
- Same validation as customer registration, plus 21+ (the schema and login already require a birthdate and 21+).
- Role lookup: `roles.role_name = 'admin'`.
- On success:
  - Insert user with `role_id`, hashed password, birthdate, `terms_accepted_at`, `terms_version`, `is_active = 1`.
  - `login_succeed($user, $password)`.
  - Flash “Admin account created. Welcome to the staff panel.”
  - Redirect to `admin/index.php`.

**Security note shown on the page:**
> Open staff registration is enabled for initial setup. After creating the first admin, set `ALLOW_OPEN_ADMIN_REGISTRATION` to `false` in `config/config.php`.

**Login page link:**
`admin/login.php` gets: “New staff? Create an admin account.”

### 9.5 Payment methods and split tender

**Methods:**

| ID | DB value | Label | Icon | Description |
|---|---|---|---|---|
| `gcash` | `GCASH` | GCash | `icon-wallet` | Pay from your GCash wallet. |
| `maya` | `MAYA` | Maya | `icon-wallet` | Pay from your Maya (PayMaya) wallet. |
| `cash` | `CASH` | Cash | `icon-cash` | Pay in cash when your order arrives. |

Card and bank transfer are removed from the UI and rejected by the API.

**Single payment:**
- Radio card selection.
- Amount is implicit and equals the server-computed order total.
- Server stores one `order_payments` row and `orders.payment_method = method`.

**Split payment:**
- A segmented “Single payment / Split payment” control.
- Two legs, each with a method selector and an amount input.
- Default allocation: half and remainder, rounded to centavos.
- When the customer edits one amount, the other leg auto-fills to the remaining balance.
- Methods must be different.
- Both amounts must be greater than zero.
- The two amounts must sum to the order total within ₱0.01.
- Server re-validates the same rules against its own total.
- Server stores two `order_payments` rows and `orders.payment_method = 'SPLIT'`.

**API contract:**

`POST api/checkout.php`

```json
{
  "shipping": {
    "name": "Maria Santos",
    "phone": "09171234567",
    "address": "123 Mabini Street, Barangay San Lorenzo, Makati City",
    "notes": "Leave at the guardhouse"
  },
  "payments": [
    { "method": "GCASH", "amount": 600.00 },
    { "method": "CASH", "amount": 550.00 }
  ]
}
```

- `payments` is required for new clients.
- Legacy `paymentMethod` is accepted for one release and converted to a single payment using the server total.
- Error code `VALIDATION_ERROR` with a `payments` field message for invalid allocations.
- Response includes `orderId`, `orderNumber`, `total`, `payments`, and `paymentLabel`.

**Display:**
- Single: `GCash`, `Maya`, or `Cash`.
- Split: `Split — GCash ₱600.00 + Cash ₱550.00`.
- Admin list uses a compact summary; admin detail and customer order detail show both rows.

### 9.6 VAT-inclusive pricing

- Add a shared `order_totals(float $subtotal): array` helper in `includes/functions.php`.
- `api/cart.php` and `api/checkout.php` both use it.
- Response shape:
  ```php
  [
    'subtotal' => 1000.00,
    'vatRate' => 0.12,
    'vatIncluded' => true,
    'vatIncludedAmount' => 107.14,
    'shipping' => 150.00,
    'shippingFee' => 150.00,
    'freeShippingAbove' => 2000.00,
    'amountToFreeShipping' => 1000.00,
    'total' => 1150.00,
  ]
  ```
- No `vat` key that clients could accidentally add.
- Client `app.js` removes its own `VAT_RATE` and renders only Subtotal, Shipping, Total.
- Receipt uses the order-time totals snapshot, not the cleared cart.
- Account/admin pages remove VAT rows.
- `orders.vat_amount` stores the included portion for records only.

### 9.7 Admin/account consistency repairs

Before adding split-payment displays, repair these:

- Add `get_flash(): ?array` as a compatibility wrapper around `get_flashes()`.
- Add `audit_log(string $action, array $details = []): void` using the existing `audit_log` table.
- Replace `orders.created_at` with `orders.placed_at` in queries and displays:
  - `admin/index.php`
  - `admin/orders.php`
  - `account/orders.php`
  - `account/order.php`
  - `api/orders.php`
- Replace `order_status_history.changed_at` with `created_at`:
  - `account/order.php`
  - `admin/order.php`
  - `api/orders.php`
- Replace admin status lists:
  - From `PENDING, CONFIRMED, PROCESSING, SHIPPED, DELIVERED, CANCELLED`
  - To `PENDING, PAID, PACKING, SHIPPED, DELIVERED, CANCELLED`
  - Files: `admin/orders.php`, `admin/order.php`, `admin/index.php`.
- Render status labels with `order_status_label()` and add `status-chip--pending`, `--paid`, `--packing`, `--shipped`, `--delivered`, `--cancelled`.
- Add the missing admin/account CSS (see §9.8).
- Replace hard-coded dark colors in PHP views with CSS classes or theme variables.

### 9.8 UI polish scope

**CSS additions (missing today):**
- Admin shell: `.admin-layout`, `.admin-sidebar`, `.admin-sidebar__header`, `.admin-sidebar__tag`, `.admin-sidebar__footer`, `.admin-sidebar__user`, `.admin-sidebar__user-name`, `.admin-sidebar__user-role`, `.admin-sidebar__logout`, `.admin-nav`, `.admin-nav__link`, `.admin-content`.
- Admin pages: `.admin-page-header`, `.admin-page-title`, `.admin-stats`, `.admin-stat-card`, `.admin-stat-label`, `.admin-stat-value`, `.admin-stat-meta`, `.admin-grid`, `.admin-card`, `.admin-card-header`, `.admin-card-title`, `.admin-table-wrap`, `.admin-table`.
- Account: `.account`, `.account__container`, `.account__header`, `.account__title`, `.account__nav`, `.account__nav-link`, `.account__nav-link--danger`, `.account__grid`, `.account__card`, `.account__card-title`.
- Orders: `.orders-list`, `.order-item`, `.order-item__header`, `.order-item__main`, `.order-item__meta`, `.order-item__number`, `.order-item__total`, `.order-items`, `.order-items__row`, `.order-items__info`, `.order-items__name`, `.order-items__meta`, `.order-items__qty`, `.order-items__price`.
- Status/timeline: `.status-chip` and modifiers, `.timeline`, `.timeline__item`, `.timeline__dot`, `.timeline__content`, `.timeline__status`, `.timeline__note`, `.timeline__time`.
- Forms/legal/payment: `.field__hint`, `.check-field`, `.terms`, `.terms__meta`, `.terms__toc`, `.terms__section`, `.pay-mode`, `.pay-mode__btn`, `.split-pay`, `.split-pay__leg`, `.split-pay__row`, `.split-pay__remaining`.

**Responsive rules:**
- `≤ 900px`: account grid one column; admin sidebar becomes a top bar or collapses into a horizontal nav; checkout two-column layouts become one column.
- `≤ 640px`: header wraps; search full width; buttons full width in checkout/auth forms; payment split legs stack; tables scroll horizontally inside `.admin-table-wrap`; cards use 16px padding.
- No horizontal page scroll at 360px.

**Inline-style cleanup:**
- Replace dark inline colors in `account/order.php`, `account/orders.php`, `account/addresses.php`, `admin/index.php`, `admin/orders.php`, `admin/order.php`, `admin/customers.php`, `admin/products.php`, `admin/product-edit.php` with classes or `var(--…)` values.
- Replace the dark unauthorized page inline CSS in `includes/auth.php` with the light palette.
- Update the placeholder SVG art in `includes/head.php` from neon cyan/lime to muted green/gray.

**Consistency checks:**
- All pages use the same button, field, card, table, and chip styles.
- All forms show inline errors and focus the first invalid field.
- All empty states use the same icon + message + action pattern.
- No `#00f0ff`, `#c6ff00`, `#07090c`, or dark-theme hard-coded colors remain in app CSS/PHP views.

## 10. Terms & Policy copy

The page title is **Terms & Policy**. It is a plain-language template for a Philippine vape shop. Replace the contact details with the real support inbox/phone before launch.

### 10.1 Acceptance

By creating an account, accessing, or ordering from VAPE CO., you agree to these Terms & Policy. If you do not agree, do not use the site or place an order.

### 10.2 Age requirement

You must be at least 21 years old to browse, register, or buy. We may ask for a valid government ID or other proof of age at any time. If we believe an account belongs to a minor, we may suspend the account and cancel orders. Nicotine products are not for minors, non-smokers, pregnant or breastfeeding people, or people with heart conditions.

### 10.3 Products and nicotine warning

- Products may contain nicotine, which is addictive.
- Keep products and e-liquids away from children and pets.
- Use devices and batteries according to the manufacturer’s instructions. Do not modify, short-circuit, or use damaged batteries.
- Product images are for illustration. Actual packaging, color, and batch may vary.
- We do not provide medical advice. Consult a healthcare professional if you have concerns.

### 10.4 Accounts and security

- Provide accurate, current information.
- You are responsible for keeping your password confidential and for activity under your account.
- Do not share your account or create accounts on behalf of minors.
- Tell us immediately if you suspect unauthorized access.

### 10.5 Orders and pricing

- Prices are in Philippine pesos and include VAT. Shipping is shown separately at checkout.
- We may correct pricing or availability errors and cancel or refund an affected order.
- An order is confirmed only after we accept it. Stock is not reserved until the order is placed.
- We may refuse or cancel orders that appear fraudulent, abusive, or prohibited by law.

### 10.6 Payment

- We accept GCash, Maya, and Cash.
- Split payment is allowed on one order using two different methods. The amounts must add up to the order total.
- We do not accept card or bank transfer payments on this site.
- For e-wallet payments, follow the payment instructions shown after checkout. For cash, prepare the amount due on delivery.
- We are not responsible for fees charged by your e-wallet provider.

### 10.7 Shipping and delivery

- Deliveries are made to the address you provide. Check it carefully; we are not responsible for delays or losses caused by an incorrect address.
- Delivery estimates are not guarantees. Weather, courier volume, and local restrictions may cause delays.
- Risk passes to you on delivery. If a package is damaged, report it within 48 hours with photos.

### 10.8 Returns, defects, and warranty

- Report damaged, missing, or incorrect items within 48 hours of delivery.
- For hygiene and safety reasons, opened e-liquids, pods, cartridges, and disposable devices are not returnable unless defective.
- Defective devices are covered by the manufacturer’s warranty, if any. Contact us with your order number and a description of the issue.
- We do not accept returns for change of mind.

### 10.9 Prohibited use

You may not:
- Buy for or supply products to anyone under 21.
- Resell products without written permission.
- Use the site for fraud, harassment, or unlawful activity.
- Attempt to bypass age verification, security controls, or payment validation.

### 10.10 Privacy

We collect the information needed to run the shop: name, email, phone, birthdate, delivery address, order history, and technical data such as IP address and session data. We use it to:
- Verify age and eligibility.
- Process and deliver orders.
- Provide support and prevent fraud.
- Comply with legal obligations.

We do not sell personal data. We share only what is needed with couriers and payment providers. We keep records for as long as needed for operations and legal compliance. You may request access or correction of your information through the contact channel below. The site uses cookies, localStorage, and sessionStorage for sign-in, cart, and age-verification state.

### 10.11 Liability

To the extent allowed by law, VAPE CO. is not liable for indirect or consequential losses, misuse of products, battery misuse, or losses caused by inaccurate information you provide. Nothing in these terms limits rights that cannot be limited under Philippine law.

### 10.12 Changes to these terms

We may update these terms. The “Last updated” date and version will change. Continued use after an update means you accept the new version. If you do not agree, stop using the site and contact us to close your account.

### 10.13 Contact

Questions, requests, or complaints:
- Email: `support@vapeco.ph` (replace with the real support inbox before launch)
- Phone: `+63 917 000 0000` (replace with the real support number before launch)
- Address: Metro Manila, Philippines (replace with the real business address before launch)

### 10.14 Governing law

These terms are governed by the laws of the Republic of the Philippines.

## 11. Edge cases

| Case | Expected behavior |
|---|---|
| Age gate storage throws (private mode) | Gate shows; site remains usable; no console error. |
| Refresh after verifying | Gate shows again. |
| Back/Forward after verifying | Gate does not show. |
| Under-21 denial then refresh | Gate shows again; user can change their answer. |
| Terms unchecked | Form rejects; no account inserted; checkbox error shown. |
| Terms page opened mid-registration | Opens in a new tab; form state remains. |
| Duplicate email | Field error; no account inserted. |
| Birthdate under 21 | Field error; no account inserted. |
| Admin registration disabled | 403 page with link to staff sign-in. |
| Admin registration while customer signed in | Redirect with flash asking to sign out. |
| Split methods identical | Inline error; no API call. |
| Split amount zero/negative | Inline error; no API call. |
| Split sums off by more than ₱0.01 | Inline error; no API call. |
| Client total tampered | Server rejects because amounts do not match server total. |
| Single payment amount ≠ total | Server rejects. |
| Card/bank method sent by API | Server rejects with `VALIDATION_ERROR`. |
| Stock changes during checkout | Existing `STOCK_RACE` handling remains; user sees out-of-stock message. |
| Legacy order with `CARD`/`COD` | Admin/account show legacy label; no new order can use it. |
| Existing user has no terms acceptance | Can sign in; not forced to re-accept in this iteration. |
| VAT rounding | `order_totals()` rounds to 2 decimals; split validation tolerates ₱0.01. |
| Receipt after cart cleared | Uses stored order totals snapshot, not `state.cartTotals`. |
| Admin/account pages before CSS fix | Fixed in the baseline phase so no unstyled pages remain. |

## 12. Security and privacy

- Keep CSRF verification on all state-changing forms and API calls.
- Keep prepared statements; never interpolate user input into SQL.
- Keep `password_hash()` / `password_verify()`; enforce the existing password policy.
- Do not collect or store card numbers, CVV, or bank details.
- Server-side payment validation is authoritative. Client-side validation is UX only.
- Open admin registration is a temporary convenience. The config flag must be set to `false` after the first admin is created. The page displays this warning.
- `audit_log()` records admin actions with user id, action, entity, entity id, details, IP, and timestamp.
- Terms acceptance stores a timestamp and version for auditability.
- Age verification is a good-faith gate, not a substitute for ID checks at delivery.
- The Terms page is a template, not legal advice; have it reviewed before launch.

## 13. Verification matrix

### 13.1 Automated checks

```powershell
C:\xampp\php\php.exe -l includes\functions.php
C:\xampp\php\php.exe -l api\cart.php
C:\xampp\php\php.exe -l api\checkout.php
C:\xampp\php\php.exe -l register.php
C:\xampp\php\php.exe -l admin\register.php
C:\xampp\php\php.exe -l terms.php
C:\xampp\php\php.exe tests\run.php
```

### 13.2 Database checks

```sql
DESCRIBE users;
DESCRIBE orders;
DESCRIBE order_payments;

SELECT o.order_number, o.payment_method, o.subtotal, o.shipping_fee, o.total,
       SUM(op.amount) AS paid_total, COUNT(op.id) AS payment_rows
FROM orders o
LEFT JOIN order_payments op ON op.order_id = o.id
GROUP BY o.id;

SELECT subtotal, vat_amount, ROUND(subtotal - (subtotal / 1.12), 2) AS expected_vat
FROM orders
ORDER BY id DESC
LIMIT 5;
```

Expected: `paid_total = total` for every new order; `payment_rows` is 1 or 2; `vat_amount` matches the included portion; `total = subtotal + shipping_fee`.

### 13.3 Manual browser checks

**Age gate**
1. Fresh tab → gate shows.
2. Verify → gate hides.
3. Hard refresh → gate shows again.
4. Verify → navigate to `login.php` → browser Back → gate does not show.
5. New tab → gate shows.
6. Under 21 → denial panel; refresh → gate shows again.

**Terms and registration**
1. Customer registration without checkbox → error; no account.
2. With checkbox → account created; `terms_accepted_at` and `terms_version` set.
3. Admin registration page loads from `admin/login.php`.
4. Admin registration without checkbox → error.
5. With checkbox → admin account created and signed in.
6. Set `ALLOW_OPEN_ADMIN_REGISTRATION` to `false` → page returns 403.

**Pricing**
1. Cart shows Subtotal, Shipping, Total. No VAT row.
2. Checkout steps 1 and 4 show the same; no VAT row.
3. Account order detail and admin order detail show no VAT row.
4. `total = subtotal + shipping` on screen and in DB.

**Payments**
1. Single GCash → order saves one `GCASH` row.
2. Single Maya → one `MAYA` row.
3. Single Cash → one `CASH` row.
4. GCash + Cash split → two rows, `payment_method = SPLIT`.
5. Maya + Cash split → accepted.
6. GCash + Maya split → accepted.
7. Same method twice → rejected.
8. Amounts not summing → rejected.
9. API request with `CARD` → rejected.
10. Admin list and detail show the split breakdown.
11. Customer order detail shows the split breakdown.

**UI**
1. Test at 360px, 768px, and 1280px.
2. No horizontal page scroll at 360px.
3. Admin tables scroll inside their wrapper.
4. Every page uses the light palette; no neon/glow/grid.
5. Focus rings visible on all controls.
6. Form errors are readable and linked.
7. `prefers-reduced-motion` disables non-essential motion.

## 14. Rollout

1. Back up the database (`mysqldump`) and the current project folder.
2. Run `database/migrations/2026-11-16-refresh.sql` on an existing database, or import the updated `database/schema.sql` on a fresh database.
3. Deploy the PHP/JS/CSS changes.
4. Clear the browser cache (the stylesheet and app.js changed).
5. Run the automated and manual checks above.
6. Create the first admin through `admin/register.php`.
7. Set `ALLOW_OPEN_ADMIN_REGISTRATION` to `false` in `config/config.php`.
8. Replace the sample support email, phone, and address in `terms.php`/config before going live.
9. Optionally remove the old `database/seed_admin.php` instructions from onboarding docs once the web flow is verified.

This workspace is not a Git repository. Use a timestamped backup or initialize Git before execution if commit checkpoints are desired.

## 15. Assumptions and open items

- The age gate is scoped to the storefront. If the owner wants it on every public page, a shared gate partial/script is a follow-up.
- Payment is recorded, not processed. There is no GCash/Maya gateway integration in this iteration.
- VAT remains 12% for the included-VAT calculation; only the display changes.
- Terms copy is a template and uses sample contact details that must be replaced before launch.
- Open admin registration is intentional for initial setup and must be disabled after the first admin exists.
- Existing users are not forced to accept the new terms on next login.
- The plan will include a small PHP test runner for the shared pricing and payment helpers; UI behavior is verified manually in the browser.
