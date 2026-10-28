/* ============================================================
   VAPE CO. — app.js
   Vanilla JS storefront controller (no frameworks, no build step).

   Module organization:
    1.  Utilities (DOM, storage, money, focus trap / layers, API client)
    2.  Constants
    3.  State + store (cart mutations all funnel through commit())
    4.  Data loading (api/catalog.php behind one normalizer)
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

/**
 * The JSON API client.
 *
 * Every endpoint answers with exactly one of two shapes:
 *
 *   success  { "ok": true,  "data": { ... } }
 *   failure  { "ok": false, "error": { "code": "...", "message": "...", "fields": { ... } } }
 *
 * So this helper can always promise the same thing to its caller:
 *   - on success it RESOLVES with `json.data`  (NOT json.data.data)
 *   - on failure it REJECTS with an Error that carries `.code` and `.fields`
 *
 * The CSRF token comes from window.__APP__.csrf, which includes/head.php
 * prints into the page.
 */
const API = {
  /** Build a URL under the api/ folder from a path like 'cart.php'. */
  url(path) {
    const base = window.__APP__?.apiBase ?? '/api/';
    const p = String(path ?? '');
    // Already absolute (starts with '/') or already inside the api folder.
    if (p.startsWith('/') || p.startsWith(base)) return p;
    return base + p.replace(/^\/+/, '');
  },

  async request(path, options = {}) {
    const { headers: extraHeaders, body, ...rest } = options;
    const headers = { Accept: 'application/json', ...(extraHeaders || {}) };

    let payload = body;
    if (payload && typeof payload !== 'string') {
      headers['Content-Type'] = 'application/json';
      payload = JSON.stringify(payload);
    }

    // State-changing requests must prove they came from a page we served.
    if ((options.method || 'GET').toUpperCase() !== 'GET') {
      headers['X-CSRF-Token'] = window.__APP__?.csrf || '';
    }

    const res = await fetch(this.url(path), { ...rest, headers, body: payload });

    const json = await res.json().catch(() => ({
      ok: false,
      error: { code: 'SERVER_ERROR', message: 'Unexpected server response.' },
    }));

    if (!json.ok) {
      const err = new Error(json.error?.message || 'Request failed');
      err.code = json.error?.code || 'SERVER_ERROR';
      err.fields = json.error?.fields || null;
      err.status = res.status;
      throw err;
    }

    return json.data; // ← .data, not .data.data
  },

  get(path) {
    return this.request(path);
  },

  post(path, body) {
    return this.request(path, { method: 'POST', body });
  },
};

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

/**
 * Categories come from the database, so the admin can rename or reorder them.
 * This list is only a fallback for the first paint (or if the API is down).
 */
const CATEGORY_FALLBACK = [
  { slug: 'all', label: 'All', iconKey: '' },
  { slug: 'disposables', label: 'Disposables', iconKey: 'cat-disposables' },
  { slug: 'pods', label: 'Pods', iconKey: 'cat-pods' },
  { slug: 'batteries', label: 'Batteries', iconKey: 'cat-batteries' },
  { slug: 'e-juice', label: 'E-Juice', iconKey: 'cat-e-juice' },
  { slug: 'cartridges', label: 'Cartridges', iconKey: 'cat-cartridges' },
  { slug: 'kits', label: 'Kits', iconKey: 'cat-kits' },
];

const SORT_OPTIONS = [
  { id: 'featured', label: 'Featured' },
  { id: 'price_asc', label: 'Price ↑' },
  { id: 'price_desc', label: 'Price ↓' },
  { id: 'newest', label: 'Newest' },
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
  categories: [],
  activeCategory: 'all',
  query: '',
  sort: 'featured',
  page: 1,
  pageSize: 24,
  total: 0,
  totalPages: 1,
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

const byId = (id) => state.products.find((p) => p.id === String(id));

/** Line identity: a variant is the line identity on the server. */
const lineKey = (id, strength, flavor) => `${id}::${strength || ''}::${flavor || ''}`;

/**
 * The cart store. Every mutation goes through the server. The client applies
 * the server's cart snapshot so the badge, drawer and totals never disagree.
 */
const store = {
  apply(data) {
    if (!data) return;
    state.cart = Array.isArray(data.items) ? data.items.map((item) => ({
      key: `v:${item.variantId}`,
      variantId: Number(item.variantId),
      productId: Number(item.productId) || null,
      id: String(item.productId || item.variantId),
      name: String(item.name || ''),
      price: Number(item.price) || 0,
      image: typeof item.image === 'string' ? item.image : '',
      qty: clampQty(item.qty),
      strength: String(item.strength || ''),
      flavor: String(item.flavor || ''),
      stock: Number(item.stock) || 0,
      inStock: item.inStock !== false,
      available: item.available !== false,
    })) : [];
    state.cartTotals = data.totals || null;
    state.cartCount = Number(data.count) || state.cart.reduce((n, l) => n + l.qty, 0);
  },

  async load() {
    try {
      // Migrate legacy localStorage cart first.
      await this.migrateLegacy();
      const data = await API.get('cart.php');
      this.apply(data);
    } catch (err) {
      console.error('Cart load failed:', err);
      state.cart = [];
      state.cartTotals = null;
      state.cartCount = 0;
    }
    renderAll();
  },

  async migrateLegacy() {
    const raw = storage.get(CART_KEY, null);
    if (!Array.isArray(raw) || raw.length === 0) return;

    const items = raw
      .map((l) => {
        // Old format: { id: productId (string), strength, flavor, qty }
        const productId = String(l.id || '');
        // We need slugs; look up in catalog if available
        const product = state.products.find((p) => p.id === productId);
        const slug = product?.slug || '';
        if (!slug) return null;
        return {
          productSlug: slug,
          strength: String(l.strength || ''),
          flavor: String(l.flavor || ''),
          qty: clampQty(l.qty),
        };
      })
      .filter(Boolean);

    if (items.length === 0) {
      storage.remove(CART_KEY);
      return;
    }

    try {
      const data = await API.post('cart.php', {
        action: 'merge',
        items,
        guestTokenHash: window.__APP__?.guestTokenHash || '',
      });
      this.apply(data);
    } catch (err) {
      console.error('Cart migration failed:', err);
    }

    storage.remove(CART_KEY);
  },

  async addToCart(product, { strength = '', flavor = '', qty = 1, variantId = null, unitPrice = null } = {}) {
    const vid = Number(variantId) || Number(product.variants?.[0]?.id) || 0;
    if (!vid) return;

    // Optimistic badge update
    const prevCount = state.cartCount;
    state.cartCount = (state.cartCount || cartCount()) + clampQty(qty);
    renderBadge();

    try {
      const data = await API.post('cart.php', { action: 'add', variantId: vid, qty: clampQty(qty) });
      this.apply(data);
    } catch (err) {
      state.cartCount = prevCount;
      console.error('Add to cart failed:', err);
      toast('Could not add to cart. Please try again.');
    }
    renderAll();
  },

  async setQty(keyOrVariantId, qty) {
    const variantId = typeof keyOrVariantId === 'string' && keyOrVariantId.startsWith('v:')
      ? Number(keyOrVariantId.slice(2))
      : Number(keyOrVariantId);

    try {
      const data = await API.post('cart.php', { action: 'update', variantId, qty: Number(qty) });
      this.apply(data);
    } catch (err) {
      console.error('Update cart failed:', err);
      toast('Could not update cart.');
    }
    renderAll();
  },

  async removeFromCart(keyOrVariantId) {
    const variantId = typeof keyOrVariantId === 'string' && keyOrVariantId.startsWith('v:')
      ? Number(keyOrVariantId.slice(2))
      : Number(keyOrVariantId);

    try {
      const data = await API.post('cart.php', { action: 'remove', variantId });
      this.apply(data);
    } catch (err) {
      console.error('Remove from cart failed:', err);
      toast('Could not remove item.');
    }
    renderAll();
  },

  async clearCart() {
    try {
      const data = await API.post('cart.php', { action: 'clear' });
      this.apply(data);
    } catch (err) {
      console.error('Clear cart failed:', err);
    }
    renderAll();
  },
};

const cartCount = () => state.cartCount ?? state.cart.reduce((n, l) => n + l.qty, 0);

/* ---------- 4. Data loading ---------- */

/**
 * One normalizer for everything the catalog API sends back. Variants are the
 * units that are really sold: a product plus a strength and a flavor.
 */
function normalizeProduct(raw) {
  const strengths = Array.isArray(raw.strengths) ? raw.strengths.map(String) : [];
  const flavors = Array.isArray(raw.flavors) ? raw.flavors.map(String) : [];
  const variants = Array.isArray(raw.variants) ? raw.variants.map((v) => ({
    id: String(v.id ?? ''),
    sku: String(v.sku ?? ''),
    strength: String(v.strength ?? ''),
    flavor: String(v.flavor ?? ''),
    price: Number(v.price) || 0,
    stock: Math.max(0, Math.floor(Number(v.stock) || 0)),
  })) : [];

  return {
    id: String(raw.id ?? ''),
    slug: String(raw.slug ?? ''),
    category: String(raw.category ?? ''),
    name: String(raw.name ?? 'Unnamed product'),
    description: String(raw.description ?? ''),
    brand: String(raw.brand ?? ''),
    price: Number(raw.price) || 0,
    image: typeof raw.image === 'string' ? raw.image : '',
    strength: strengths[0] || '',
    strengths,
    flavors,
    variants,
    stock: Math.max(0, Math.floor(Number(raw.stock) || 0)),
    available: raw.available !== undefined
      ? Boolean(raw.available)
      : variants.some((v) => v.stock > 0),
    featured: Boolean(raw.featured), // reserved for a future homepage (no UI consumer)
  };
}

function normalizeCategory(raw) {
  const slug = String(raw.slug ?? '');
  return {
    slug,
    label: String(raw.name ?? slug),
    iconKey: String(raw.iconKey ?? '') || `cat-${slug}`,
  };
}

function categoriesForUi() {
  return [{ slug: 'all', label: 'All', iconKey: '' }, ...state.categories];
}

function categoryFor(slug) {
  return categoriesForUi().find((c) => c.slug === slug) || null;
}

function categoryLabel(slug) {
  return categoryFor(slug)?.label || slug;
}

function categoryIcon(slug) {
  return categoryFor(slug)?.iconKey || `cat-${slug}`;
}

/** Build the query string mirroring the shareable URL state. */
function catalogQuery() {
  const params = new URLSearchParams();
  if (state.activeCategory !== 'all') params.set('category', state.activeCategory);
  if (state.query) params.set('q', state.query);
  if (state.sort !== 'featured') params.set('sort', state.sort);
  if (state.page > 1) params.set('page', String(state.page));
  return params.toString();
}

/** Read category, search, sort and page from the address bar. */
function readCatalogState() {
  const params = new URLSearchParams(location.search);
  const sort = params.get('sort') || '';

  state.activeCategory = params.get('category') || 'all';
  state.query = (params.get('q') || '').trim();
  state.sort = SORT_OPTIONS.some((s) => s.id === sort) ? sort : 'featured';
  state.page = Math.max(1, parseInt(params.get('page') || '1', 10) || 1);

  if (searchInput && searchInput.value !== state.query) searchInput.value = state.query;
  if (searchInputM && searchInputM.value !== state.query) searchInputM.value = state.query;
}

/** Write the current view back to the address bar (refresh/share reproduces it). */
function writeCatalogState() {
  const url = new URL(location.href);
  const set = (key, value) => {
    if (value) url.searchParams.set(key, value);
    else url.searchParams.delete(key);
  };

  set('category', state.activeCategory !== 'all' ? state.activeCategory : '');
  set('q', state.query);
  set('sort', state.sort !== 'featured' ? state.sort : '');
  set('page', state.page > 1 ? String(state.page) : '');

  history.replaceState(null, '', url);
}

async function loadCatalog() {
  const search = catalogQuery();
  const data = await API.get(search ? `catalog.php?${search}` : 'catalog.php');
  if (!data || !Array.isArray(data.products)) {
    throw new Error('Unexpected catalog response');
  }
  return data;
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
const sortControlEl = $('#sort-control');
const paginationEl = $('#pagination');

function displayStrength(p) { return p.strengths[0] || p.strength || ''; }
function displayFlavor(p) { return p.flavors[0] || ''; }

/** The variant (product + strength + flavor) that actually gets sold. */
function variantFor(p, strength, flavor) {
  return p.variants.find(
    (v) => v.strength === (strength || '') && v.flavor === (flavor || '')
  ) || null;
}

/** First in-stock variant, preferring the strength/flavor shown on the card. */
function defaultVariant(p) {
  if (!p.variants.length) return null;
  const preferred = variantFor(p, displayStrength(p), displayFlavor(p));
  if (preferred && preferred.stock > 0) return preferred;
  return p.variants.find((v) => v.stock > 0) || p.variants[0];
}

function productArt(p) {
  return p.image
    ? `<img src="${esc(p.image)}" alt="" loading="lazy">`
    : '<svg aria-hidden="true"><use href="#ph-art"></use></svg>';
}

function buildChips() {
  chipsEl.innerHTML = categoriesForUi().map((c) => `
    <button class="chip${c.slug === state.activeCategory ? ' is-active' : ''}"
            data-cat="${esc(c.slug)}" aria-pressed="${c.slug === state.activeCategory}">
      ${c.slug === 'all' ? '' : `<svg aria-hidden="true"><use href="#${esc(c.iconKey)}"></use></svg>`}
      ${esc(c.label)}
    </button>`).join('');
}

function buildNav() {
  navEl.innerHTML = categoriesForUi().map((c) => `
    <button class="desktop-nav__link${c.slug === state.activeCategory ? ' is-active' : ''}"
            data-cat="${esc(c.slug)}">${esc(c.label)}</button>`).join('');
}

function setCategory(slug) {
  if (slug === state.activeCategory) return;
  state.activeCategory = slug;
  state.page = 1;
  buildChips();
  buildNav();
  writeCatalogState();
  fetchCatalog();
}

let searchTimer = null;
function setQuery(q) {
  const next = q.trim();
  state.query = next;
  state.page = 1;
  if (searchInput.value !== next) searchInput.value = next;
  if (searchInputM.value !== next) searchInputM.value = next;

  // Debounce keystrokes so we do not fire one request per letter.
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    writeCatalogState();
    fetchCatalog();
  }, 250);
}

function setSort(sort) {
  if (sort === state.sort) return;
  state.sort = sort;
  state.page = 1;
  syncSortControl();
  writeCatalogState();
  fetchCatalog();
}

function setPage(page) {
  const next = Math.min(state.totalPages, Math.max(1, page));
  if (next === state.page) return;
  state.page = next;
  writeCatalogState();
  fetchCatalog().then(() => {
    $('#catalog')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
}

function cardHTML(p) {
  const meta = [displayStrength(p), displayFlavor(p)].filter(Boolean).join(' · ');
  const variant = defaultVariant(p);
  const canAdd = Boolean(p.available && variant && variant.stock > 0);
  return `
    <article class="card${canAdd ? '' : ' card--unavailable'}" data-id="${esc(p.id)}" tabindex="0" role="button"
             aria-label="Quick view: ${esc(p.name)}">
      <div class="card__art">${productArt(p)}</div>
      <div class="card__body">
        <span class="micro-label card__cat">
          <svg aria-hidden="true"><use href="#${esc(categoryIcon(p.category))}"></use></svg>${esc(categoryLabel(p.category))}
        </span>
        <h3 class="card__title">${esc(p.name)}</h3>
        ${meta ? `<p class="card__meta">${esc(meta)}</p>` : ''}
        <div class="card__foot">
          <span class="card__price">${money(p.price)}</span>
          <button class="btn btn--accent btn--sm js-add" data-id="${esc(p.id)}"
                  aria-label="${canAdd ? `Add ${esc(p.name)} to cart` : `${esc(p.name)} is out of stock`}"
                  ${canAdd ? '' : 'disabled'}>${canAdd ? '+ Add to Cart' : 'Out of Stock'}</button>
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
  const n = state.products.length;
  countEl.textContent = `/// ${state.total} product${state.total === 1 ? '' : 's'}`;
  gridEl.innerHTML = state.products.map(cardHTML).join('');
  gridEl.hidden = n === 0;
  emptyEl.hidden = n > 0;
}

function showCatalogError() {
  gridEl.hidden = true;
  emptyEl.hidden = true;
  countEl.textContent = '';
  errorEl.hidden = false;
}

function syncSortControl() {
  if (!sortControlEl) return;
  $$('[data-sort]', sortControlEl).forEach((btn) => {
    const active = btn.dataset.sort === state.sort;
    btn.classList.toggle('is-active', active);
    btn.setAttribute('aria-pressed', String(active));
  });
}

function renderPagination() {
  if (!paginationEl) return;

  if (state.totalPages <= 1) {
    paginationEl.hidden = true;
    paginationEl.innerHTML = '';
    return;
  }

  paginationEl.hidden = false;
  paginationEl.innerHTML = `
    <button class="btn btn--ghost btn--sm" data-page="${state.page - 1}"
            ${state.page <= 1 ? 'disabled' : ''}>← Prev</button>
    <span class="micro-label">Page ${state.page} of ${state.totalPages}</span>
    <button class="btn btn--ghost btn--sm" data-page="${state.page + 1}"
            ${state.page >= state.totalPages ? 'disabled' : ''}>Next →</button>`;
}

/** Adopt a server page and repaint everything that depends on it. */
function applyCatalog(data) {
  state.products = data.products.map(normalizeProduct);
  state.categories = (data.categories || []).map(normalizeCategory);
  state.total = Number(data.total) || 0;
  state.page = Math.max(1, Number(data.page) || 1);
  state.pageSize = Number(data.pageSize) || 24;
  state.totalPages = Math.max(1, Number(data.totalPages) || 1);

  buildChips();
  buildNav();
  syncSortControl();
  renderCatalog();
  renderPagination();
  writeCatalogState(); // the server may have clamped the page
}

let catalogLoaded = false;
let catalogRequest = 0;

async function fetchCatalog() {
  const requestId = ++catalogRequest;

  errorEl.hidden = true;
  if (!catalogLoaded) renderSkeletons(); // only the first load flashes skeletons
  gridEl.classList.add('is-loading');

  try {
    const data = await loadCatalog();
    if (requestId !== catalogRequest) return; // a newer request already won

    applyCatalog(data);
    catalogLoaded = true;
  } catch (err) {
    if (requestId !== catalogRequest) return;
    console.error('Catalog load failed:', err);
    showCatalogError();
  } finally {
    if (requestId === catalogRequest) gridEl.classList.remove('is-loading');
  }
}

/* Catalog events */
gridEl.addEventListener('click', (e) => {
  const addBtn = e.target.closest('.js-add');
  if (addBtn) {
    e.stopPropagation();
    const p = byId(addBtn.dataset.id);
    if (!p) return;
    const variant = defaultVariant(p);
    if (!variant || variant.stock <= 0) return;
    store.addToCart(p, {
      strength: variant.strength,
      flavor: variant.flavor,
      variantId: variant.id,
      unitPrice: variant.price,
    });
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

searchInput.addEventListener('input', () => setQuery(searchInput.value));
searchInputM.addEventListener('input', () => setQuery(searchInputM.value));

if (sortControlEl) {
  sortControlEl.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-sort]');
    if (btn) setSort(btn.dataset.sort);
  });
}

if (paginationEl) {
  paginationEl.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-page]');
    if (btn && !btn.disabled) setPage(Number(btn.dataset.page));
  });
}

/* Back/forward buttons should restore the view the URL describes. */
window.addEventListener('popstate', () => {
  readCatalogState();
  buildChips();
  buildNav();
  syncSortControl();
  fetchCatalog();
});

$('#search-toggle').addEventListener('click', () => {
  const field = $('#mobile-search');
  const open = field.hidden;
  field.hidden = !open;
  $('#search-toggle').setAttribute('aria-expanded', String(open));
  if (open) searchInputM.focus();
});

$('#clear-filters').addEventListener('click', () => {
  state.activeCategory = 'all';
  state.query = '';
  state.page = 1;
  if (searchInput.value !== '') searchInput.value = '';
  if (searchInputM.value !== '') searchInputM.value = '';
  buildChips();
  buildNav();
  writeCatalogState();
  fetchCatalog();
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
  const variant = defaultVariant(p);
  state.qv = {
    id,
    strength: variant ? variant.strength : displayStrength(p),
    flavor: variant ? variant.flavor : displayFlavor(p),
    qty: 1,
    originEl: originEl || null,
  };
  renderQuickView();
  qvLayer.open();
}

/**
 * Keep the quick-view selection pointing at a real variant. If the chosen
 * strength/flavor pair no longer exists (or is sold out), fall back to one
 * that does.
 */
function ensureVariant(p, s) {
  const current = variantFor(p, s.strength, s.flavor);
  if (current) return current;

  const inStock = p.variants.filter((v) => v.stock > 0);
  const pick = inStock.find((v) => v.strength === s.strength) || inStock[0] || p.variants[0] || null;
  if (pick) {
    s.strength = pick.strength;
    s.flavor = pick.flavor;
  }
  return pick;
}

function pillHTML(name, value, selected, disabled) {
  return `
    <label class="pill${disabled ? ' pill--disabled' : ''}">
      <input type="radio" name="${name}" value="${esc(value)}"
             ${selected ? 'checked' : ''} ${disabled ? 'disabled' : ''}>
      <span>${esc(value)}</span>
    </label>`;
}

function renderQuickView() {
  const s = state.qv;
  if (!s) return;
  const p = byId(s.id);
  if (!p) return;

  const variant = ensureVariant(p, s);
  const inStock = Boolean(variant && variant.stock > 0);
  const maxQty = Math.max(1, Math.min(QTY_MAX, variant ? variant.stock : 1));
  s.qty = Math.min(s.qty, maxQty);

  // A pill is available only if its pair with the other selection is in stock.
  const strengthAvailable = (value) => p.variants.some(
    (v) => v.strength === value && v.flavor === s.flavor && v.stock > 0
  );
  const flavorAvailable = (value) => p.variants.some(
    (v) => v.flavor === value && v.strength === s.strength && v.stock > 0
  );

  // Selectors appear only when a product offers multiple options
  const strengthSel = p.strengths.length > 1 ? `
    <fieldset class="selector">
      <legend class="selector__legend">Nicotine strength</legend>
      <div class="selector__pills">
        ${p.strengths.map((v) => pillHTML('qv-strength', v, v === s.strength, !strengthAvailable(v))).join('')}
      </div>
    </fieldset>` : '';

  const flavorSel = p.flavors.length > 1 ? `
    <fieldset class="selector">
      <legend class="selector__legend">Flavor</legend>
      <div class="selector__pills">
        ${p.flavors.map((v) => pillHTML('qv-flavor', v, v === s.flavor, !flavorAvailable(v))).join('')}
      </div>
    </fieldset>` : '';

  const variantLine = [s.strength, s.flavor].filter(Boolean).join(' · ');

  const stockLabel = !inStock
    ? 'Out of stock'
    : variant.stock <= 5
      ? `Only ${variant.stock} left in stock`
      : `In stock (${variant.stock})`;
  const stockClass = !inStock ? ' is-out' : variant.stock <= 5 ? ' is-low' : '';

  qvEl.innerHTML = `
    <div class="layer-backdrop" data-close-qv></div>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="qv-title">
      <button class="icon-btn modal__close" data-close-qv aria-label="Close quick view">
        <svg aria-hidden="true"><use href="#icon-close"></use></svg>
      </button>
      <div class="qv">
        <div class="qv__art">${productArt(p)}</div>
        <div class="qv__body">
          <span class="micro-label">
            <svg aria-hidden="true"><use href="#${esc(categoryIcon(p.category))}"></use></svg>
            ${esc(categoryLabel(p.category))}
          </span>
          <h2 class="qv__title" id="qv-title">${esc(p.name)}</h2>
          <p class="qv__desc">${esc(p.description)}</p>
          ${variantLine ? `<p class="micro-label">${esc(variantLine)}</p>` : ''}
          ${strengthSel}
          ${flavorSel}
          <p class="qv__price">${money(variant ? variant.price : p.price)}</p>
          <p class="qv__stock${stockClass}">${stockLabel}</p>
          <div class="qv__actions">
            <div class="stepper" aria-label="Quantity">
              <button class="icon-btn" data-qv-dec aria-label="Decrease quantity"${s.qty <= 1 ? ' disabled' : ''}>
                <svg aria-hidden="true"><use href="#icon-minus"></use></svg>
              </button>
              <span class="stepper__qty" aria-live="polite">${s.qty}</span>
              <button class="icon-btn" data-qv-inc aria-label="Increase quantity"${s.qty >= maxQty ? ' disabled' : ''}>
                <svg aria-hidden="true"><use href="#icon-plus"></use></svg>
              </button>
            </div>
            <button class="btn btn--accent" data-qv-add ${inStock ? '' : 'disabled'}>
              ${inStock ? 'Add to Cart' : 'Out of Stock'}
            </button>
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
    const variant = ensureVariant(p, s);
    if (!variant || variant.stock <= 0) return;
    store.addToCart(p, {
      strength: s.strength,
      flavor: s.flavor,
      qty: s.qty,
      variantId: variant.id,
      unitPrice: variant.price,
    });
    toast(`Added — ${p.name}`);
    announce(`Added ${p.name} to cart. ${cartCount()} item${cartCount() === 1 ? '' : 's'} in cart.`);
    qvLayer.close();
  }
});

qvEl.addEventListener('change', (e) => {
  const s = state.qv;
  if (!s) return;
  if (e.target.name !== 'qv-strength' && e.target.name !== 'qv-flavor') return;

  const name = e.target.name;
  const value = e.target.value;
  if (name === 'qv-strength') s.strength = value;
  else s.flavor = value;

  renderQuickView(); // repaint price, stock and pill availability

  // Re-rendering replaces the radio, so put focus back on the chosen pill.
  const next = $$(`input[name="${name}"]`, qvEl).find((input) => input.value === value && !input.disabled);
  next?.focus();
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
  const stockChip = !line.inStock || (line.stock !== undefined && line.stock < line.qty)
    ? '<span class="cart-line__stock">Out of stock</span>'
    : '';
  const key = line.variantId ? `v:${line.variantId}` : line.key;
  const controls = interactive ? `
    <div class="cart-line__controls">
      <div class="stepper" aria-label="Quantity for ${esc(line.name)}">
        <button class="icon-btn" data-action="dec" data-key="${esc(key)}" aria-label="Decrease quantity"${line.qty <= 1 || (!line.inStock) ? ' disabled' : ''}>
          <svg aria-hidden="true"><use href="#icon-minus"></use></svg>
        </button>
        <span class="stepper__qty">${line.qty}</span>
        <button class="icon-btn" data-action="inc" data-key="${esc(key)}" aria-label="Increase quantity"${line.qty >= QTY_MAX || (!line.inStock) ? ' disabled' : ''}>
          <svg aria-hidden="true"><use href="#icon-plus"></use></svg>
        </button>
      </div>
      <button class="icon-btn icon-btn--danger" data-action="remove" data-key="${esc(key)}" aria-label="Remove ${esc(line.name)} from cart">
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
        ${stockChip}
        ${controls}
      </div>
      <div class="cart-line__side">
        <span class="cart-line__total">${money(line.price * line.qty)}</span>
      </div>
    </div>`;
}

/** Free-shipping progress bar — uses the server's totals when available. */
function shipProgressHTML(totals) {
  const t = totals || state.cartTotals || {};
  const subtotal = Number(t.subtotal ?? state.cart.reduce((sum, l) => sum + l.price * l.qty, 0));
  const threshold = Number(t.freeShippingAbove ?? FREE_SHIPPING_ABOVE);
  const remaining = Math.max(0, threshold - subtotal);
  const pct = threshold > 0 ? Math.min(100, Math.round((subtotal / threshold) * 100)) : 100;
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

/** Totals block — uses the server totals object. */
function totalsFromLines(cart = state.cart) {
  const subtotal = cart.reduce((sum, l) => sum + l.price * l.qty, 0);
  const vat = Math.round(subtotal * VAT_RATE * 100) / 100;
  const shipping = subtotal > 0 && subtotal < FREE_SHIPPING_ABOVE ? FLAT_SHIPPING : 0;
  return { subtotal, vat, shipping, total: subtotal + vat + shipping };
}

function totalsHTML(cart) {
  const t = state.cartTotals || totalsFromLines(cart);
  const subtotal = Number(t.subtotal ?? cart.reduce((sum, l) => sum + l.price * l.qty, 0));
  const vat = Number(t.vat ?? (Math.round(subtotal * VAT_RATE * 100) / 100));
  const shipping = Number(t.shipping ?? t.shippingFee ?? (subtotal > 0 && subtotal < FREE_SHIPPING_ABOVE ? FLAT_SHIPPING : 0));
  const total = Number(t.total ?? (subtotal + vat + shipping));
  return `
    <div class="totals">
      <div class="totals__row"><span>Subtotal</span><span>${money(subtotal)}</span></div>
      <div class="totals__row"><span>VAT (12%)</span><span>${money(vat)}</span></div>
      <div class="totals__row"><span>Shipping</span><span>${shipping === 0 ? 'FREE' : money(shipping)}</span></div>
      <div class="totals__row totals__row--grand"><span>Total</span><span>${money(total)}</span></div>
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
        ${shipProgressHTML(state.cartTotals)}
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
  if (!key) return;
  const line = state.cart.find((l) => (l.variantId ? `v:${l.variantId}` : l.key) === key);
  if (action === 'inc') {
    if (line) store.setQty(key, line.qty + 1);
  } else if (action === 'dec') {
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
  if (!window.__APP__?.user) {
    drawerLayer.close();
    const next = encodeURIComponent('index.php#checkout');
    window.location.href = `login.php?next=${next}`;
    return;
  }
  drawerLayer.close();
  state.checkout.step = 1;
  state.checkout.order = null;
  checkoutOpen = true;
  document.body.classList.add('checkout-open');
  if (cartBtn) cartBtn.disabled = true;
  if (stepIndicatorEl) stepIndicatorEl.hidden = false;
  if (checkoutEl) checkoutEl.hidden = false;
  lockLayer(checkoutEl);
  renderCheckout();
  requestAnimationFrame(() => checkoutEl.classList.add('is-open'));
}

function closeCheckout({ reset = false } = {}) {
  if (!checkoutOpen) return;
  checkoutOpen = false;
  if (checkoutEl) checkoutEl.classList.remove('is-open');
  setTimeout(() => { if (checkoutEl) checkoutEl.hidden = true; }, 320);
  document.body.classList.remove('checkout-open');
  if (cartBtn) { cartBtn.disabled = false; cartBtn.focus(); }
  if (stepIndicatorEl) stepIndicatorEl.hidden = true;
  unlockLayer(checkoutEl);
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
        ${shipProgressHTML(state.cartTotals)}
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
if (checkoutEl) {
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
      if (cmd === 'done') { closeCheckout({ reset: true }); window.location.href = 'account/orders.php'; return; }
    }

    // Step-1 qty steppers share the drawer's data-action handlers
    const lineBtn = e.target.closest('[data-action]');
    if (lineBtn) {
      const { action, key } = lineBtn.dataset;
      if (!key) return;
      const line = state.cart.find((l) => (l.variantId ? `v:${l.variantId}` : l.key) === key);
      if (!line) return;
      if (action === 'inc') store.setQty(key, line.qty + 1);
      if (action === 'dec') store.setQty(key, line.qty - 1);
      if (action === 'remove') store.removeFromCart(key);
    }
  });
}

/* Step 2: draft persistence + validation on blur */
if (checkoutEl) {
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
      const err = $('#pay-error', checkoutEl);
      if (err) err.hidden = true;
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
}

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

async function placeOrder(btn) {
  const c = state.checkout;
  if (!window.__APP__?.user) {
    toast('Please sign in to check out.');
    closeCheckout();
    window.location.href = `login.php?next=${encodeURIComponent('index.php#checkout')}`;
    return;
  }
  if (!c.payment) {
    const err = $('#pay-error', checkoutEl);
    if (err) err.hidden = false;
    $('.pay-card input', checkoutEl)?.focus();
    return;
  }
  btn.classList.add('is-loading');
  btn.disabled = true;
  c.submitting = true;

  // Final full validation before submitting
  const errs = validateShippingAll();
  if (errs.some(Boolean) || !state.cart.length || !c.payment) {
    c.submitting = false;
    btn.disabled = false;
    btn.classList.remove('is-loading');
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
  const paymentMap = { card: 'CARD', gcash: 'GCASH', cod: 'COD' };

  try {
    const data = await API.post('checkout.php', {
      shipping: {
        name: c.shipping.name,
        phone: c.shipping.phone,
        address: c.shipping.address,
        notes: c.shipping.notes || '',
      },
      paymentMethod: paymentMap[c.payment] || 'COD',
    });

    c.order = {
      ref: data.orderNumber || makeRef(),
      dateLabel: new Date().toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' }),
      recipient: shortName(c.shipping.name),
      paymentLabel: method ? method.label : c.payment,
      lines: state.cart.map((l) => ({ ...l })),
      totals: state.cartTotals || totalsFromLines(),
    };

    state.cart = [];
    state.cartTotals = null;
    state.cartCount = 0;
    renderAll();
    storage.remove(DRAFT_KEY);
    c.shipping = { ...EMPTY_SHIPPING };
    c.payment = '';
    c.submitting = false;
    c.step = 4;
    btn.disabled = false;
    btn.classList.remove('is-loading');
    renderCheckout();
    announce(`Order confirmed. Reference ${c.order.ref}.`);
  } catch (err) {
    console.error('Checkout failed:', err);
    toast(err.message || 'Could not place order. Please try again.');
    c.submitting = false;
    btn.disabled = false;
    btn.classList.remove('is-loading');
    renderCheckout();
  }
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
  if (!badge) return;
  badge.hidden = count === 0;
  badge.textContent = count;
  if (count > prevCount) {
    badge.classList.remove('pop');
    void badge.offsetWidth; // restart the animation
    badge.classList.add('pop');
  }
  if (cartBtn) cartBtn.setAttribute('aria-label', count ? `Open cart, ${count} item${count === 1 ? '' : 's'}` : 'Open cart');
  prevCount = count;
}

/** One render pass — badge, drawer, and checkout can never disagree. */
function renderAll() {
  renderBadge();
  if (drawerEl.classList.contains('is-open')) renderDrawer();
  if (checkoutOpen) renderCheckout();
}

/* Wordmark returns to the catalog */
const wordmark = $('#wordmark');
if (wordmark) {
  wordmark.addEventListener('click', (e) => {
    e.preventDefault();
    if (checkoutOpen) closeCheckout();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
}

/* Header account menu */
function initHeaderAccount() {
  const guest = document.getElementById('header-account-guest');
  const userWrap = document.getElementById('header-account-user');
  const trigger = document.getElementById('header-account-trigger');
  const nameEl = document.getElementById('header-account-name');
  const menu = document.getElementById('header-account-menu');
  const user = window.__APP__?.user;

  if (!guest || !userWrap || !trigger || !nameEl || !menu) return;

  if (user) {
    guest.hidden = true;
    userWrap.hidden = false;
    nameEl.textContent = user.firstName + ' ' + user.lastName;
  } else {
    guest.hidden = false;
    userWrap.hidden = true;
  }

  trigger.addEventListener('click', (e) => {
    e.stopPropagation();
    const open = !menu.hidden;
    if (open) {
      menu.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
    } else {
      menu.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
    }
  });

  document.addEventListener('click', (e) => {
    if (!userWrap.contains(e.target)) {
      menu.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      menu.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
      trigger.focus();
    }
  });
}

/* Init */
initAgeGate();
readCatalogState();
buildChips();
buildNav();
syncSortControl();
renderBadge();
initHeaderAccount();
fetchCatalog();
store.load();
