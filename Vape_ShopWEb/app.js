/* ============================================================
   VAPE CO. — app.js
   Vanilla JS storefront controller (no frameworks, no build step).

   Module organization:
    1.  Utilities (DOM, storage, money, focus trap / layers)
    2.  Constants
    3.  State + store (cart mutations all funnel through commit())
    4.  Data loading (products.json behind one normalizer)
    5.  Age gate
    6.  Catalog (chips, nav, search, cards, empty/error states)
    7.  Quick-view modal
    8.  Cart drawer (lines, totals, free-shipping bar)
    9.  Checkout (4 steps, validation, simulated submit, receipt)
   10.  Toast + init

   localStorage keys: ageVerified, cart_v1, shipping_draft_v1
   ============================================================ */

'use strict';

/* ---------- 1. Utilities ---------- */

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

const FOCUSABLE =
  'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/** Escape user-provided strings before injecting them into HTML. */
function esc(str) {
  return String(str ?? '').replace(/[&<>"']/g, (c) => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ));
}

/** try/catch-wrapped localStorage — never throws, always falls back. */
const storage = {
  get(key, fallback) {
    try {
      const raw = localStorage.getItem(key);
      return raw === null ? fallback : JSON.parse(raw);
    } catch { return fallback; }
  },
  set(key, value) {
    try { localStorage.setItem(key, JSON.stringify(value)); } catch { /* quota/private mode */ }
  },
  remove(key) {
    try { localStorage.removeItem(key); } catch { /* ignore */ }
  },
};

/** Philippine peso formatting, used for every price in the UI. */
const moneyFmt = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
const money = (n) => moneyFmt.format(n);

const clampQty = (n) => Math.min(QTY_MAX, Math.max(1, Math.floor(Number(n) || 1)));

/** Full-screen layer with focus trap + Esc handling (drawer, modal, gate). */
function createLayer(el, { escDismiss = true, initialFocus = null, onClose = null } = {}) {
  let lastFocused = null;

  function onKeydown(e) {
    if (e.key === 'Escape' && escDismiss) { e.preventDefault(); close(); return; }
    if (e.key !== 'Tab') return;
    // Keep keyboard focus inside the layer
    const nodes = $$(FOCUSABLE, el).filter((n) => n.offsetParent !== null);
    if (!nodes.length) { e.preventDefault(); return; }
    const first = nodes[0];
    const last = nodes[nodes.length - 1];
    if (!el.contains(document.activeElement)) { e.preventDefault(); (e.shiftKey ? last : first).focus(); return; }
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }

  function open() {
    lastFocused = document.activeElement;
    el.hidden = false;
    el.addEventListener('keydown', onKeydown);
    openLayerEls.add(el); // lock body scroll while any layer is up
    scrollLock();
    requestAnimationFrame(() => {
      el.classList.add('is-open');
      const target = (initialFocus && initialFocus()) || $('[data-autofocus]', el) || $(FOCUSABLE, el);
      target?.focus?.();
    });
  }

  function close() {
    if (el.hidden) return;
    el.removeEventListener('keydown', onKeydown);
    el.classList.remove('is-open');
    setTimeout(() => { el.hidden = true; }, 320); // let the slide-out play
    openLayerEls.delete(el);
    scrollLock();
    onClose?.();
    lastFocused?.focus?.();
  }

  return { open, close, get visible() { return !el.hidden; } };
}

const openLayerEls = new Set();
function scrollLock() {
  document.body.classList.toggle('layer-open', openLayerEls.size > 0);
}
function lockLayer(el) { openLayerEls.add(el); scrollLock(); }
function unlockLayer(el) { openLayerEls.delete(el); scrollLock(); }

/* ---------- 2. Constants ---------- */

const CART_KEY = 'cart_v1';
const DRAFT_KEY = 'shipping_draft_v1';
const AGE_KEY = 'ageVerified';
const QTY_MAX = 10;
const VAT_RATE = 0.12;
const FLAT_SHIPPING = 150;
const FREE_SHIPPING_ABOVE = 2000;

const CATEGORIES = [
  { slug: 'all', label: 'All' },
  { slug: 'disposables', label: 'Disposables' },
  { slug: 'pods', label: 'Pods' },
  { slug: 'batteries', label: 'Batteries' },
  { slug: 'e-juice', label: 'E-Juice' },
  { slug: 'cartridges', label: 'Cartridges' },
  { slug: 'kits', label: 'Kits' },
];

const PAYMENT_METHODS = [
  { id: 'card', label: 'Credit / Debit Card', icon: 'icon-card', desc: 'Visa, Mastercard, JCB.', hint: "You'll be redirected after order placement (demo)." },
  { id: 'gcash', label: 'E-Wallet / GCash', icon: 'icon-wallet', desc: 'Pay from your GCash or Maya balance.', hint: "You'll be redirected after order placement (demo)." },
  { id: 'cod', label: 'Cash on Delivery', icon: 'icon-cash', desc: 'Pay the rider in cash on arrival.', hint: 'Please prepare exact change if possible.' },
];

const EMPTY_SHIPPING = { name: '', phone: '', address: '', notes: '' };

/* ---------- 3. State + store ---------- */

const state = {
  products: [],
  activeCategory: 'all',
  query: '',
  cart: [],
  checkout: {
    step: 1, // 1 summary, 2 shipping, 3 payment, 4 confirmation
    shipping: { ...EMPTY_SHIPPING, ...storage.get(DRAFT_KEY, {}) },
    payment: '',
    submitting: false,
    order: null, // snapshot taken at confirmation time
  },
  qv: null, // active quick-view selection
};

const byId = (id) => state.products.find((p) => p.id === id);

/** Line identity: the same product in a different strength/flavor is a different line. */
const lineKey = (id, strength, flavor) => `${id}::${strength || ''}::${flavor || ''}`;

function computeTotals(cart) {
  const subtotal = cart.reduce((sum, l) => sum + l.price * l.qty, 0);
  const vat = Math.round(subtotal * VAT_RATE * 100) / 100;
  const shipping = subtotal > 0 && subtotal < FREE_SHIPPING_ABOVE ? FLAT_SHIPPING : 0;
  return { subtotal, vat, shipping, total: subtotal + vat + shipping };
}

/**
 * The cart store. Every mutation goes through commit(), which persists
 * and then re-renders badge + drawer + totals together — they can never disagree.
 */
const store = {
  load() {
    const raw = storage.get(CART_KEY, []);
    this.stateCart = null;
    state.cart = Array.isArray(raw) ? raw.filter((l) =>
      l && typeof l.id === 'string' && typeof l.name === 'string' &&
      Number.isFinite(l.price) && Number.isFinite(l.qty)
    ).map((l) => ({ ...l, qty: clampQty(l.qty) })) : [];
  },
  persist() { storage.set(CART_KEY, state.cart); },
  commit() { this.persist(); renderAll(); },

  addToCart(product, { strength = '', flavor = '', qty = 1 } = {}) {
    const key = lineKey(product.id, strength, flavor);
    const existing = state.cart.find((l) => l.key === key);
    if (existing) existing.qty = clampQty(existing.qty + qty);
    else state.cart.push({
      key,
      id: product.id,
      name: product.name,
      price: product.price,
      image: product.image || '',
      qty: clampQty(qty),
      strength: strength || '',
      flavor: flavor || '',
    });
    this.commit();
  },
  setQty(key, qty) {
    const line = state.cart.find((l) => l.key === key);
    if (!line) return;
    line.qty = clampQty(qty);
    this.commit();
  },
  removeFromCart(key) {
    state.cart = state.cart.filter((l) => l.key !== key);
    this.commit();
  },
  clearCart() {
    state.cart = [];
    this.commit();
  },
};

const cartCount = () => state.cart.reduce((n, l) => n + l.qty, 0);

/* ---------- 4. Data loading ---------- */

/** Single normalizer — swapping in a real API later is a one-line change here. */
function normalizeProduct(raw) {
  return {
    id: String(raw.id ?? ''),
    category: String(raw.category ?? ''),
    name: String(raw.name ?? 'Unnamed product'),
    description: String(raw.description ?? ''),
    price: Number(raw.price) || 0,
    image: typeof raw.image === 'string' ? raw.image : '',
    strength: String(raw.strength ?? ''),
    strengths: Array.isArray(raw.strengths) ? raw.strengths.map(String) : [],
    flavors: Array.isArray(raw.flavors) ? raw.flavors.map(String) : [],
    featured: Boolean(raw.featured), // reserved for a future homepage (no UI consumer)
  };
}

async function loadProducts() {
  const res = await fetch('products.json', { cache: 'no-store' });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const data = await res.json();
  const list = Array.isArray(data) ? data : data.products;
  if (!Array.isArray(list)) throw new Error('Unexpected products.json shape');
  return list.map(normalizeProduct);
}

/* ---------- 5. Age gate ---------- */

const ageGateEl = $('#age-gate');
const ageGate = createLayer(ageGateEl, {
  escDismiss: false, // Esc never dismisses the gate
  initialFocus: () => $('#age-yes'),
});

function initAgeGate() {
  if (storage.get(AGE_KEY, false)) {
    ageGateEl.hidden = true;
    document.body.classList.remove('gate-open');
    return;
  }
  document.body.classList.add('gate-open');
  ageGateEl.hidden = false;
  lockLayer(ageGateEl);
  ageGate.open();
}

$('#age-yes').addEventListener('click', () => {
  storage.set(AGE_KEY, true);
  // One continuous transition: overlay fades out while the blur lifts
  ageGateEl.classList.add('is-leaving');
  document.body.classList.remove('gate-open');
  unlockLayer(ageGateEl);
  setTimeout(() => {
    ageGateEl.classList.remove('is-leaving');
    ageGate.close();
  }, 400);
  announce('Age verified. Welcome.');
});

$('#age-no').addEventListener('click', () => {
  // Polite, in-place, and final for the session — no alert()
  $('#age-gate-actions').hidden = true;
  $('#age-denied').hidden = false;
});

/* ---------- 6. Catalog ---------- */

const gridEl = $('#product-grid');
const chipsEl = $('#chips');
const navEl = $('#desktop-nav');
const countEl = $('#result-count');
const emptyEl = $('#empty-state');
const errorEl = $('#error-panel');
const searchInput = $('#search-input');
const searchInputM = $('#search-input-m');

function displayStrength(p) { return p.strengths[0] || p.strength || ''; }
function displayFlavor(p) { return p.flavors[0] || ''; }

function productArt(p) {
  return p.image
    ? `<img src="${esc(p.image)}" alt="" loading="lazy">`
    : '<svg aria-hidden="true"><use href="#ph-art"></use></svg>';
}

function buildChips() {
  chipsEl.innerHTML = CATEGORIES.map((c) => `
    <button class="chip${c.slug === state.activeCategory ? ' is-active' : ''}"
            data-cat="${c.slug}" aria-pressed="${c.slug === state.activeCategory}">
      ${c.slug === 'all' ? '' : `<svg aria-hidden="true"><use href="#cat-${c.slug}"></use></svg>`}
      ${c.label}
    </button>`).join('');
}

function buildNav() {
  navEl.innerHTML = CATEGORIES.map((c) => `
    <button class="desktop-nav__link${c.slug === state.activeCategory ? ' is-active' : ''}"
            data-cat="${c.slug}">${c.label}</button>`).join('');
}

function setCategory(slug) {
  state.activeCategory = slug;
  buildChips();
  buildNav();
  renderCatalog();
}

function setQuery(q) {
  state.query = q;
  if (searchInput.value !== q) searchInput.value = q;
  if (searchInputM.value !== q) searchInputM.value = q;
  renderCatalog();
}

function matchesQuery(p, q) {
  if (!q) return true;
  const haystack = [p.name, p.description, p.category, displayStrength(p), ...p.strengths, ...p.flavors]
    .join(' ').toLowerCase();
  return haystack.includes(q);
}

function cardHTML(p) {
  const meta = [displayStrength(p), displayFlavor(p)].filter(Boolean).join(' · ');
  const catLabel = CATEGORIES.find((c) => c.slug === p.category)?.label || p.category;
  return `
    <article class="card" data-id="${esc(p.id)}" tabindex="0" role="button"
             aria-label="Quick view: ${esc(p.name)}">
      <div class="card__art">${productArt(p)}</div>
      <div class="card__body">
        <span class="micro-label card__cat">
          <svg aria-hidden="true"><use href="#cat-${esc(p.category)}"></use></svg>${esc(catLabel)}
        </span>
        <h3 class="card__title">${esc(p.name)}</h3>
        ${meta ? `<p class="card__meta">${esc(meta)}</p>` : ''}
        <div class="card__foot">
          <span class="card__price">${money(p.price)}</span>
          <button class="btn btn--accent btn--sm js-add" data-id="${esc(p.id)}"
                  aria-label="Add ${esc(p.name)} to cart">+ Add to Cart</button>
        </div>
      </div>
    </article>`;
}

function renderSkeletons() {
  countEl.textContent = '';
  gridEl.innerHTML = Array.from({ length: 8 }, () => `
    <div class="skeleton" aria-hidden="true">
      <div class="skeleton__art"></div>
      <div class="skeleton__line skeleton__line--mid"></div>
      <div class="skeleton__line skeleton__line--short"></div>
      <div class="skeleton__line"></div>
    </div>`).join('');
}

function renderCatalog() {
  if (!state.products.length) return; // still loading (or failed)
  const filtered = state.products.filter(
    (p) => (state.activeCategory === 'all' || p.category === state.activeCategory) &&
           matchesQuery(p, state.query)
  );
  const n = filtered.length;
  countEl.textContent = `/// ${n} product${n === 1 ? '' : 's'}`;
  gridEl.innerHTML = filtered.map(cardHTML).join('');
  gridEl.hidden = n === 0;
  emptyEl.hidden = n > 0;
}

function showCatalogError() {
  gridEl.hidden = true;
  countEl.textContent = '';
  errorEl.hidden = false;
}

async function fetchCatalog() {
  errorEl.hidden = true;
  renderSkeletons();
  try {
    state.products = await loadProducts();
    renderCatalog();
  } catch (err) {
    console.error('Catalog load failed:', err);
    showCatalogError();
  }
}

/* Catalog events */
gridEl.addEventListener('click', (e) => {
  const addBtn = e.target.closest('.js-add');
  if (addBtn) {
    e.stopPropagation();
    const p = byId(addBtn.dataset.id);
    if (!p) return;
    store.addToCart(p, { strength: displayStrength(p), flavor: displayFlavor(p) });
    toast(`Added — ${p.name}`);
    announce(`Added ${p.name} to cart. ${cartCount()} item${cartCount() === 1 ? '' : 's'} in cart.`);
    return;
  }
  const card = e.target.closest('.card');
  if (card) openQuickView(card.dataset.id, card);
});

gridEl.addEventListener('keydown', (e) => {
  if ((e.key === 'Enter' || e.key === ' ') && e.target.classList.contains('card')) {
    e.preventDefault();
    openQuickView(e.target.dataset.id, e.target);
  }
});

chipsEl.addEventListener('click', (e) => {
  const chip = e.target.closest('.chip');
  if (chip) setCategory(chip.dataset.cat);
});

navEl.addEventListener('click', (e) => {
  const link = e.target.closest('.desktop-nav__link');
  if (link) setCategory(link.dataset.cat);
});

searchInput.addEventListener('input', () => setQuery(searchInput.value.trim()));
searchInputM.addEventListener('input', () => setQuery(searchInputM.value.trim()));

$('#search-toggle').addEventListener('click', () => {
  const field = $('#mobile-search');
  const open = field.hidden;
  field.hidden = !open;
  $('#search-toggle').setAttribute('aria-expanded', String(open));
  if (open) searchInputM.focus();
});

$('#clear-filters').addEventListener('click', () => {
  setCategory('all');
  setQuery('');
});

$('#retry-load').addEventListener('click', fetchCatalog);

/* ---------- 7. Quick-view modal ---------- */

const qvEl = $('#quick-view');
const qvLayer = createLayer(qvEl, {
  initialFocus: () => $('.modal__close', qvEl),
});

function openQuickView(id, originEl) {
  const p = byId(id);
  if (!p) return;
  state.qv = {
    id,
    strength: displayStrength(p),
    flavor: displayFlavor(p),
    qty: 1,
    originEl: originEl || null,
  };
  renderQuickView();
  qvLayer.open();
}

function renderQuickView() {
  const s = state.qv;
  if (!s) return;
  const p = byId(s.id);
  if (!p) return;

  // Selectors appear only when a product offers multiple options
  const strengthSel = p.strengths.length > 1 ? `
    <fieldset class="selector">
      <legend class="selector__legend">Nicotine strength</legend>
      <div class="selector__pills">
        ${p.strengths.map((v, i) => `
          <label class="pill">
            <input type="radio" name="qv-strength" value="${esc(v)}" ${i === 0 ? 'checked' : ''}>
            <span>${esc(v)}</span>
          </label>`).join('')}
      </div>
    </fieldset>` : '';

  const flavorSel = p.flavors.length > 1 ? `
    <fieldset class="selector">
      <legend class="selector__legend">Flavor</legend>
      <div class="selector__pills">
        ${p.flavors.map((v, i) => `
          <label class="pill">
            <input type="radio" name="qv-flavor" value="${esc(v)}" ${i === 0 ? 'checked' : ''}>
            <span>${esc(v)}</span>
          </label>`).join('')}
      </div>
    </fieldset>` : '';

  const variantLine = [s.strength, s.flavor].filter(Boolean).join(' · ');

  qvEl.innerHTML = `
    <div class="layer-backdrop" data-close-qv></div>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="qv-title">
      <button class="icon-btn modal__close" data-close-qv aria-label="Close quick view">
        <svg aria-hidden="true"><use href="#icon-close"></use></svg>
      </button>
      <div class="qv">
        <div class="qv__art">${productArt(p)}</div>
        <div class="qv__body">
          <span class="micro-label">${esc(CATEGORIES.find((c) => c.slug === p.category)?.label || p.category)}</span>
          <h2 class="qv__title" id="qv-title">${esc(p.name)}</h2>
          <p class="qv__desc">${esc(p.description)}</p>
          ${variantLine ? `<p class="micro-label">${esc(variantLine)}</p>` : ''}
          ${strengthSel}
          ${flavorSel}
          <p class="qv__price">${money(p.price)}</p>
          <div class="qv__actions">
            <div class="stepper" aria-label="Quantity">
              <button class="icon-btn" data-qv-dec aria-label="Decrease quantity"${s.qty <= 1 ? ' disabled' : ''}>
                <svg aria-hidden="true"><use href="#icon-minus"></use></svg>
              </button>
              <span class="stepper__qty" aria-live="polite">${s.qty}</span>
              <button class="icon-btn" data-qv-inc aria-label="Increase quantity"${s.qty >= QTY_MAX ? ' disabled' : ''}>
                <svg aria-hidden="true"><use href="#icon-plus"></use></svg>
              </button>
            </div>
            <button class="btn btn--accent" data-qv-add>Add to Cart</button>
          </div>
        </div>
      </div>
    </div>`;
}

qvEl.addEventListener('click', (e) => {
  if (e.target.closest('[data-close-qv]')) { qvLayer.close(); return; }
  const s = state.qv;
  if (!s) return;
  if (e.target.closest('[data-qv-inc]')) { s.qty = clampQty(s.qty + 1); renderQuickView(); $('[data-qv-inc]', qvEl)?.focus(); return; }
  if (e.target.closest('[data-qv-dec]')) { s.qty = clampQty(s.qty - 1); renderQuickView(); $('[data-qv-dec]', qvEl)?.focus(); return; }
  if (e.target.closest('[data-qv-add]')) {
    const p = byId(s.id);
    if (!p) return;
    store.addToCart(p, { strength: s.strength, flavor: s.flavor, qty: s.qty });
    toast(`Added — ${p.name}`);
    announce(`Added ${p.name} to cart. ${cartCount()} item${cartCount() === 1 ? '' : 's'} in cart.`);
    qvLayer.close();
  }
});

qvEl.addEventListener('change', (e) => {
  const s = state.qv;
  if (!s) return;
  if (e.target.name === 'qv-strength') s.strength = e.target.value;
  if (e.target.name === 'qv-flavor') s.flavor = e.target.value;
});

/* ---------- 8. Cart drawer ---------- */

const drawerEl = $('#cart-drawer');
const drawerLayer = createLayer(drawerEl);

/** Cart line markup — shared by the drawer and checkout step 1. */
function cartLineHTML(line, { interactive = true } = {}) {
  const variant = [line.strength, line.flavor].filter(Boolean).join(' · ');
  const thumb = line.image
    ? `<img src="${esc(line.image)}" alt="">`
    : '<svg aria-hidden="true"><use href="#ph-art"></use></svg>';
  const controls = interactive ? `
    <div class="cart-line__controls">
      <div class="stepper" aria-label="Quantity for ${esc(line.name)}">
        <button class="icon-btn" data-action="dec" data-key="${esc(line.key)}" aria-label="Decrease quantity"${line.qty <= 1 ? ' disabled' : ''}>
          <svg aria-hidden="true"><use href="#icon-minus"></use></svg>
        </button>
        <span class="stepper__qty">${line.qty}</span>
        <button class="icon-btn" data-action="inc" data-key="${esc(line.key)}" aria-label="Increase quantity"${line.qty >= QTY_MAX ? ' disabled' : ''}>
          <svg aria-hidden="true"><use href="#icon-plus"></use></svg>
        </button>
      </div>
      <button class="icon-btn icon-btn--danger" data-action="remove" data-key="${esc(line.key)}" aria-label="Remove ${esc(line.name)} from cart">
        <svg aria-hidden="true"><use href="#icon-trash"></use></svg>
      </button>
    </div>` : '';
  return `
    <div class="cart-line">
      <div class="cart-line__thumb">${thumb}</div>
      <div class="cart-line__info">
        <span class="cart-line__name">${esc(line.name)}</span>
        ${variant ? `<span class="cart-line__variant">${esc(variant)}</span>` : ''}
        <span class="cart-line__unit">${money(line.price)} each</span>
        ${controls}
      </div>
      <div class="cart-line__side">
        <span class="cart-line__total">${money(line.price * line.qty)}</span>
      </div>
    </div>`;
}

/** Free-shipping progress bar — shared by drawer and checkout step 1. */
function shipProgressHTML(totals) {
  const remaining = Math.max(0, FREE_SHIPPING_ABOVE - totals.subtotal);
  const pct = Math.min(100, Math.round((totals.subtotal / FREE_SHIPPING_ABOVE) * 100));
  const label = remaining > 0
    ? `You're <strong>${money(remaining)}</strong> from free shipping`
    : '<strong>Free shipping unlocked</strong>';
  return `
    <div class="ship-progress">
      <p class="ship-progress__label">${label}</p>
      <div class="ship-progress__track" role="progressbar"
           aria-valuenow="${pct}" aria-valuemin="0" aria-valuemax="100" aria-label="Free shipping progress">
        <div class="ship-progress__bar" style="width:${pct}%"></div>
      </div>
    </div>`;
}

/** Totals block — one implementation, reused everywhere money is shown. */
function totalsHTML(cart) {
  const t = computeTotals(cart);
  return `
    <div class="totals">
      <div class="totals__row"><span>Subtotal</span><span>${money(t.subtotal)}</span></div>
      <div class="totals__row"><span>VAT (12%)</span><span>${money(t.vat)}</span></div>
      <div class="totals__row"><span>Shipping</span><span>${t.shipping === 0 ? 'FREE' : money(t.shipping)}</span></div>
      <div class="totals__row totals__row--grand"><span>Total</span><span>${money(t.total)}</span></div>
    </div>`;
}

function renderDrawer() {
  // Preserve focus across the re-render (steppers re-render on every mutation)
  const act = document.activeElement;
  const focusAction = act?.dataset?.action && drawerEl.contains(act)
    ? { action: act.dataset.action, key: act.dataset.key } : null;

  const count = cartCount();
  const lines = state.cart.length
    ? state.cart.map((l) => cartLineHTML(l)).join('')
    : `
      <div class="drawer__empty">
        <svg aria-hidden="true"><use href="#icon-box"></use></svg>
        <p>Your cart is empty.</p>
        <button class="btn btn--ghost" data-close-drawer>Browse products</button>
      </div>`;

  drawerEl.innerHTML = `
    <div class="layer-backdrop" data-close-drawer></div>
    <aside class="drawer" role="dialog" aria-modal="true" aria-labelledby="drawer-title">
      <header class="drawer__head">
        <h2 class="micro-label" id="drawer-title">/// Cart <span class="drawer__count">(${count})</span></h2>
        <button class="icon-btn" data-close-drawer aria-label="Close cart">
          <svg aria-hidden="true"><use href="#icon-close"></use></svg>
        </button>
      </header>
      <div class="drawer__body">${lines}</div>
      <footer class="drawer__foot">
        ${shipProgressHTML(computeTotals(state.cart))}
        ${totalsHTML(state.cart)}
        <button class="btn btn--accent btn--block" id="drawer-checkout"${state.cart.length ? '' : ' disabled'}>Checkout</button>
      </footer>
    </aside>`;

  if (focusAction) {
    $(`[data-action="${focusAction.action}"][data-key="${focusAction.key}"]`, drawerEl)?.focus();
  }
}

drawerEl.addEventListener('click', (e) => {
  if (e.target.closest('[data-close-drawer]')) { drawerLayer.close(); return; }
  if (e.target.closest('#drawer-checkout')) { openCheckout(); return; }
  const btn = e.target.closest('[data-action]');
  if (!btn) return;
  const { action, key } = btn.dataset;
  if (action === 'inc') {
    const line = state.cart.find((l) => l.key === key);
    if (line) store.setQty(key, line.qty + 1);
  } else if (action === 'dec') {
    const line = state.cart.find((l) => l.key === key);
    if (line) store.setQty(key, line.qty - 1);
  } else if (action === 'remove') {
    store.removeFromCart(key);
  }
});

function openDrawer() {
  if (document.body.classList.contains('checkout-open')) return; // cart button is disabled anyway
  renderDrawer();
  drawerLayer.open();
  lockLayer(drawerEl);
}
$('#cart-btn').addEventListener('click', openDrawer);

/* ---------- 9. Checkout ---------- */

const checkoutEl = $('#checkout');
const cartBtn = $('#cart-btn');
const stepIndicatorEl = $('#step-indicator');
let checkoutOpen = false;

function saveDraft() { storage.set(DRAFT_KEY, state.checkout.shipping); }

function validateField(field, value) {
  const v = String(value ?? '').trim();
  switch (field) {
    case 'name': return v ? '' : 'Full name is required.';
    case 'phone':
      if (!v) return 'Contact number is required.';
      return /^(09\d{9}|\+639\d{9})$/.test(v) ? '' : 'Enter a valid PH number (09XXXXXXXXX or +639XXXXXXXXX).';
    case 'address':
      if (!v) return 'Delivery address is required.';
      return v.length >= 10 ? '' : 'Address must be at least 10 characters.';
    default: return '';
  }
}

function validateShippingAll() {
  return ['name', 'phone', 'address'].map((f) => validateField(f, state.checkout.shipping[f]));
}

function setFieldError(field, msg) {
  const wrap = $(`[data-field="${field}"]`, checkoutEl);
  if (!wrap) return;
  wrap.classList.toggle('has-error', Boolean(msg));
  const err = $('.field__error', wrap);
  if (err) { err.textContent = msg; err.hidden = !msg; }
}

function updateStepIndicator() {
  $$('.step-indicator__step', stepIndicatorEl).forEach((li) => {
    const n = Number(li.dataset.step);
    li.classList.toggle('is-active', n === state.checkout.step);
    li.classList.toggle('is-done', n < state.checkout.step);
  });
}

function openCheckout() {
  if (!state.cart.length) { toast('Your cart is empty.'); return; }
  drawerLayer.close();
  state.checkout.step = 1;
  state.checkout.order = null;
  checkoutOpen = true;
  document.body.classList.add('checkout-open');
  cartBtn.disabled = true;
  stepIndicatorEl.hidden = false;
  checkoutEl.hidden = false;
  lockLayer(checkoutEl);
  renderCheckout();
  requestAnimationFrame(() => checkoutEl.classList.add('is-open'));
}

function closeCheckout({ reset = false } = {}) {
  if (!checkoutOpen) return;
  checkoutOpen = false;
  checkoutEl.classList.remove('is-open');
  setTimeout(() => { checkoutEl.hidden = true; }, 320);
  document.body.classList.remove('checkout-open');
  cartBtn.disabled = false;
  stepIndicatorEl.hidden = true;
  unlockLayer(checkoutEl);
  cartBtn.focus();
  if (reset) {
    // Order placed (or explicitly abandoned): back to a clean slate
    state.checkout = { step: 1, shipping: { ...EMPTY_SHIPPING }, payment: '', submitting: false, order: null };
    storage.remove(DRAFT_KEY);
  }
}

function checkoutStepTitle() {
  return { 1: 'Order Summary', 2: 'Shipping Details', 3: 'Payment', 4: 'Confirmation' }[state.checkout.step];
}

function renderCheckout() {
  if (!checkoutOpen) return;

  // Dead-checkout guard: nothing left to check out on step 1
  if (state.checkout.step === 1 && !state.cart.length) {
    closeCheckout();
    toast('Your cart is empty.');
    return;
  }

  // Preserve focus across the re-render (steppers re-render on every mutation)
  const act = document.activeElement;
  const focusAction = act?.dataset?.action && checkoutEl.contains(act)
    ? { action: act.dataset.action, key: act.dataset.key } : null;

  updateStepIndicator();
  const c = state.checkout;
  let body = '';
  let foot = '';

  if (c.step === 1) {
    body = `
      <div class="checkout__panel">
        ${state.cart.map((l) => cartLineHTML(l)).join('')}
        ${shipProgressHTML(computeTotals(state.cart))}
        ${totalsHTML(state.cart)}
      </div>`;
    foot = `
      <button class="btn btn--ghost" data-co="back">
        <svg width="16" height="16" aria-hidden="true"><use href="#icon-arrow-left"></use></svg> Back
      </button>
      <button class="btn btn--accent" data-co="next">Continue to shipping</button>`;
  }

  if (c.step === 2) {
    const s = c.shipping;
    body = `
      <div class="checkout__panel">
        <div class="form-grid form-grid--two">
          <div class="field" data-field="name">
            <label class="field__label" for="ship-name">Full name <span class="req">*</span></label>
            <input id="ship-name" name="name" type="text" autocomplete="name" value="${esc(s.name)}">
            <span class="field__error" hidden></span>
          </div>
          <div class="field" data-field="phone">
            <label class="field__label" for="ship-phone">Contact number <span class="req">*</span></label>
            <input id="ship-phone" name="phone" type="tel" inputmode="tel" placeholder="09XXXXXXXXX" value="${esc(s.phone)}">
            <span class="field__error" hidden></span>
          </div>
          <div class="field" data-field="address" style="grid-column: 1 / -1;">
            <label class="field__label" for="ship-address">Delivery address <span class="req">*</span></label>
            <input id="ship-address" name="address" type="text" autocomplete="street-address" value="${esc(s.address)}">
            <span class="field__error" hidden></span>
          </div>
          <div class="field" data-field="notes" style="grid-column: 1 / -1;">
            <label class="field__label" for="ship-notes">Delivery notes (optional)</label>
            <textarea id="ship-notes" name="notes" placeholder="Landmark, gate code…">${esc(s.notes)}</textarea>
          </div>
        </div>
      </div>`;
    foot = `
      <button class="btn btn--ghost" data-co="back">
        <svg width="16" height="16" aria-hidden="true"><use href="#icon-arrow-left"></use></svg> Back
      </button>
      <button class="btn btn--accent" data-co="next">Continue to payment</button>`;
  }

  if (c.step === 3) {
    body = `
      <div class="checkout__panel">
        <p class="micro-label" style="margin-bottom: var(--s3);">Select payment method</p>
        <div class="pay-grid">
          ${PAYMENT_METHODS.map((m) => `
            <label class="pay-card${c.payment === m.id ? ' is-selected' : ''}">
              <input type="radio" name="payment" value="${m.id}" ${c.payment === m.id ? 'checked' : ''}>
              <span class="pay-card__icon"><svg aria-hidden="true"><use href="#${m.icon}"></use></svg></span>
              <span class="pay-card__content">
                <span class="pay-card__name">${esc(m.label)}</span>
                <span class="pay-card__desc">${esc(m.desc)}</span>
                <span class="pay-card__hint">${esc(m.hint)}</span>
              </span>
            </label>`).join('')}
        </div>
        <p class="field__error" id="pay-error" style="margin-top: var(--s3);" hidden>Choose a payment method to continue.</p>
        <p class="micro-label" style="margin-top: var(--s3);">Demo only — no card numbers are ever collected.</p>
      </div>`;
    foot = `
      <button class="btn btn--ghost" data-co="back">
        <svg width="16" height="16" aria-hidden="true"><use href="#icon-arrow-left"></use></svg> Back
      </button>
      <button class="btn btn--accent" data-co="place"${c.submitting ? ' disabled' : ''}>Place Order</button>`;
  }

  if (c.step === 4 && c.order) {
    const o = c.order;
    body = `
      <div class="checkout__panel receipt">
        <span class="receipt__mark"><svg aria-hidden="true"><use href="#icon-check"></use></svg></span>
        <h2 class="receipt__title">ORDER CONFIRMED</h2>
        <p class="receipt__ref">${esc(o.ref)}</p>
        <div class="receipt__meta">
          <span>${esc(o.dateLabel)}</span>
          <span>Recipient: <strong>${esc(o.recipient)}</strong></span>
          <span>Payment: <strong>${esc(o.paymentLabel)}</strong></span>
        </div>
        <div class="receipt__lines">
          ${o.lines.map((l) => `
            <div class="totals__row">
              <span>${esc(l.name)} × ${l.qty}</span>
              <span>${money(l.price * l.qty)}</span>
            </div>`).join('')}
        </div>
        <div class="receipt__totals">${totalsHTML(o.lines)}</div>
      </div>`;
    foot = `<button class="btn btn--accent" data-co="done">Continue shopping</button>`;
  }

  checkoutEl.innerHTML = `
    <div class="checkout">
      <header class="checkout__head">
        <h2 class="checkout__title">${checkoutStepTitle()}</h2>
        <span class="micro-label">/// VAPE CO.</span>
      </header>
      <div class="checkout__section" tabindex="-1">${body}</div>
      <footer class="checkout__foot">${foot}</footer>
    </div>`;

  // Restore focus: stepper button if one was active, else the new step's panel
  if (focusAction) {
    $(`[data-action="${focusAction.action}"][data-key="${focusAction.key}"]`, checkoutEl)?.focus();
  } else {
    $('.checkout__section', checkoutEl)?.focus();
  }
}

/* Checkout events (delegated — the panel re-renders per step) */
checkoutEl.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-co]');
  if (btn) {
    const cmd = btn.dataset.co;
    const c = state.checkout;
    if (cmd === 'back') {
      if (c.step === 1) closeCheckout();
      else { c.step -= 1; renderCheckout(); }
      return;
    }
    if (cmd === 'next') {
      if (c.step === 2) {
        const errs = validateShippingAll();
        ['name', 'phone', 'address'].forEach((f, i) => setFieldError(f, errs[i]));
        const firstBad = ['name', 'phone', 'address'].find((f, i) => errs[i]);
        if (firstBad) { $(`[data-field="${firstBad}"] input`, checkoutEl)?.focus(); return; }
      }
      c.step += 1;
      renderCheckout();
      return;
    }
    if (cmd === 'place') { placeOrder(btn); return; }
    if (cmd === 'done') { closeCheckout({ reset: true }); return; }
  }

  // Step-1 qty steppers share the drawer's data-action handlers
  const lineBtn = e.target.closest('[data-action]');
  if (lineBtn) {
    const { action, key } = lineBtn.dataset;
    const line = state.cart.find((l) => l.key === key);
    if (!line) return;
    if (action === 'inc') store.setQty(key, line.qty + 1);
    if (action === 'dec') store.setQty(key, line.qty - 1);
    if (action === 'remove') store.removeFromCart(key);
  }
});

/* Step 2: draft persistence + validation on blur */
checkoutEl.addEventListener('input', (e) => {
  const field = e.target.name;
  if (['name', 'phone', 'address', 'notes'].includes(field)) {
    state.checkout.shipping[field] = e.target.value;
    saveDraft();
  }
});

checkoutEl.addEventListener('change', (e) => {
  if (e.target.name === 'payment') {
    state.checkout.payment = e.target.value;
    $('#pay-error', checkoutEl).hidden = true;
    $$('.pay-card', checkoutEl).forEach((card) => {
      card.classList.toggle('is-selected', $('input', card).value === state.checkout.payment);
    });
  }
});

checkoutEl.addEventListener('focusout', (e) => {
  const wrap = e.target.closest?.('[data-field]');
  if (!wrap) return;
  const field = wrap.dataset.field;
  if (!['name', 'phone', 'address'].includes(field)) return;
  setFieldError(field, validateField(field, e.target.value));
});

/* Simulated submission → receipt */
function makeRef() {
  const d = new Date();
  const yy = String(d.getFullYear()).slice(-2);
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  return `VPS-${yy}${mm}${dd}-${Math.floor(1000 + Math.random() * 9000)}`;
}

function shortName(full) {
  const parts = String(full).trim().split(/\s+/);
  if (parts.length === 1) return parts[0];
  return `${parts[0]} ${parts[parts.length - 1][0]}.`;
}

function placeOrder(btn) {
  const c = state.checkout;
  if (!c.payment) {
    const err = $('#pay-error', checkoutEl);
    if (err) err.hidden = false;
    $('.pay-card input', checkoutEl)?.focus();
    return;
  }
  btn.classList.add('is-loading');
  btn.disabled = true;
  c.submitting = true;

  setTimeout(() => {
    // Final full validation before "submitting"
    const errs = validateShippingAll();
    if (errs.some(Boolean) || !state.cart.length || !c.payment) {
      c.submitting = false;
      if (errs.some(Boolean)) {
        c.step = 2;
        renderCheckout();
        ['name', 'phone', 'address'].forEach((f, i) => setFieldError(f, errs[i]));
        const firstBad = ['name', 'phone', 'address'].find((f, i) => errs[i]);
        $(`[data-field="${firstBad}"] input`, checkoutEl)?.focus();
        return;
      }
      renderCheckout();
      return;
    }
    const method = PAYMENT_METHODS.find((m) => m.id === c.payment);
    c.order = {
      ref: makeRef(),
      dateLabel: new Date().toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' }),
      recipient: shortName(c.shipping.name),
      paymentLabel: method ? method.label : c.payment,
      lines: state.cart.map((l) => ({ ...l })),
      totals: computeTotals(state.cart),
    };
    store.clearCart();        // clears cart_v1 + re-renders badge to 0
    storage.remove(DRAFT_KEY); // and forgets the shipping draft
    c.shipping = { ...EMPTY_SHIPPING };
    c.payment = '';
    c.submitting = false;
    c.step = 4;
    renderCheckout();
    announce(`Order confirmed. Reference ${c.order.ref}.`);
  }, 800);
}

/* ---------- 10. Toast, live region, render orchestration, init ---------- */

let toastTimer = null;
function toast(msg) {
  const el = $('#toast');
  el.textContent = msg;
  el.hidden = false;
  requestAnimationFrame(() => el.classList.add('is-visible'));
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => {
    el.classList.remove('is-visible');
    setTimeout(() => { el.hidden = true; }, 200);
  }, 2200);
}

function announce(msg) {
  let live = $('#live-region');
  if (!live) {
    live = document.createElement('div');
    live.id = 'live-region';
    live.className = 'sr-only';
    live.setAttribute('aria-live', 'polite');
    document.body.appendChild(live);
  }
  live.textContent = msg;
}

let prevCount = 0;
function renderBadge() {
  const count = cartCount();
  const badge = $('#cart-badge');
  badge.hidden = count === 0;
  badge.textContent = count;
  if (count > prevCount) {
    badge.classList.remove('pop');
    void badge.offsetWidth; // restart the animation
    badge.classList.add('pop');
  }
  cartBtn.setAttribute('aria-label', count ? `Open cart, ${count} item${count === 1 ? '' : 's'}` : 'Open cart');
  prevCount = count;
}

/** One render pass — badge, drawer, and checkout can never disagree. */
function renderAll() {
  renderBadge();
  if (drawerEl.classList.contains('is-open')) renderDrawer();
  if (checkoutOpen) renderCheckout();
}

/* Wordmark returns to the catalog */
$('#wordmark').addEventListener('click', (e) => {
  e.preventDefault();
  if (checkoutOpen) closeCheckout();
  window.scrollTo({ top: 0, behavior: 'smooth' });
});

/* Init */
store.load();
initAgeGate();
buildChips();
buildNav();
renderBadge();
fetchCatalog();
