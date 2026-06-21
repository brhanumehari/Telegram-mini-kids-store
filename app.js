/**
 * app.js
 * ENG_251885 APP — Kids Store Telegram Mini App
 *
 * Vanilla JS single-page application. No build step required — this file
 * is loaded directly by index.html and drives all 3 screens (Store,
 * Products, Dashboard) plus cart state and checkout handoff to the
 * PHP backend (api.php -> PaymentRouter.php).
 */

(() => {
  'use strict';

  // ---------------------------------------------------------------------
  // Config
  // ---------------------------------------------------------------------
  const API_BASE = 'api.php';

  // ---------------------------------------------------------------------
  // Telegram WebApp bootstrap
  // ---------------------------------------------------------------------
  const tg = window.Telegram?.WebApp;
  let initData = '';
  let tgUser = { first_name: 'Guest', username: null };

  if (tg) {
    tg.ready();
    tg.expand();
    tg.setHeaderColor('#0D0D0D');
    tg.setBackgroundColor('#0D0D0D');
    initData = tg.initData || '';
    if (tg.initDataUnsafe?.user) {
      tgUser = tg.initDataUnsafe.user;
    }
    tg.BackButton.onClick(() => navigateTo('categories'));
  }

  // ---------------------------------------------------------------------
  // Fallback mock catalog — used only if the API is unreachable (e.g. while
  // designing the UI standalone, before the PHP backend is deployed).
  // ---------------------------------------------------------------------
  const MOCK_CATEGORIES = [
    { id: 1, name: 'Shoes', icon: '👟', product_count: 6, sub_tags: [
      { id: 11, name: 'Infants 0-2Y' }, { id: 12, name: 'Kids 3-7Y' },
      { id: 13, name: 'Juniors 8-12Y' }, { id: 14, name: 'Teens 13-16Y' },
    ]},
    { id: 2, name: 'Clothes', icon: '👕', product_count: 4, sub_tags: [
      { id: 21, name: 'Baby 0-2Y' }, { id: 22, name: 'Toddlers 3-5Y' },
      { id: 23, name: 'Children 6-10Y' }, { id: 24, name: 'Teens 11-16Y' },
    ]},
    { id: 3, name: 'Kids Equipment', icon: '🧸', product_count: 2, sub_tags: [
      { id: 31, name: '0-3 Years' }, { id: 32, name: '4-7 Years' },
      { id: 33, name: '8-12 Years' }, { id: 34, name: '13-16 Years' },
    ]},
  ];

  const MOCK_PRODUCTS = [
    { id: 101, name: 'Baby Booties', age_range: '0-2Y', price: 14.99, image_url: '', emoji: '👶' },
    { id: 102, name: 'Toddler Sneakers', age_range: '3-5Y', price: 22.50, image_url: '', emoji: '👟' },
    { id: 103, name: 'Kids Jackets', age_range: '6-10Y', price: 34.00, image_url: '', emoji: '🧥' },
    { id: 104, name: 'Teen Backpacks', age_range: '11-16Y', price: 28.75, image_url: '', emoji: '🎒' },
    { id: 105, name: 'Baby Strollers', age_range: '0-2Y', price: 89.99, image_url: '', emoji: '🍼' },
    { id: 106, name: 'Kids Bikes', age_range: '5-7Y', price: 120.00, image_url: '', emoji: '🚲' },
  ];

  // ---------------------------------------------------------------------
  // State
  // ---------------------------------------------------------------------
  const state = {
    activeScreen: 'categories',
    storeMode: 'categories', // categories | products
    categories: [],
    products: [],
    cart: new Map(), // product_id -> { product, quantity }
    dashboardTab: 'downloads',
    dashboardData: null,
  };

  // ---------------------------------------------------------------------
  // DOM refs
  // ---------------------------------------------------------------------
  const $ = (sel) => document.querySelector(sel);
  const els = {
    storeToggle: $('#storeToggle'),
    screenCategories: $('#screen-categories'),
    screenProducts: $('#screen-products'),
    screenDashboard: $('#screen-dashboard'),
    categoryList: $('#categoryList'),
    productGrid: $('#productGrid'),
    searchInput: $('#searchInput'),
    ageFilter: $('#ageFilter'),
    tabItems: document.querySelectorAll('.tab-item'),
    cartBadge: $('#cartBadge'),
    toast: $('#toast'),
    dashCloseBtn: $('#dashCloseBtn'),
    welcomeName: $('#welcomeName'),
    statOrders: $('#statOrders'),
    statDownloads: $('#statDownloads'),
    statKeys: $('#statKeys'),
    statCompleted: $('#statCompleted'),
    spendValue: $('#spendValue'),
    dashSubtabs: $('#dashSubtabs'),
    dashContent: $('#dashContent'),
    ordersBadge: $('#ordersBadge'),
  };

  // ---------------------------------------------------------------------
  // API helper
  // ---------------------------------------------------------------------
  async function apiCall(action, { method = 'GET', params = {}, body = null, auth = false } = {}) {
    const url = new URL(API_BASE, window.location.href);
    url.searchParams.set('action', action);
    Object.entries(params).forEach(([k, v]) => {
      if (v !== '' && v !== null && v !== undefined) url.searchParams.set(k, v);
    });

    const headers = {};
    if (auth) headers['X-Telegram-Init-Data'] = initData;
    if (body) headers['Content-Type'] = 'application/json';

    const res = await fetch(url.toString(), {
      method,
      headers,
      body: body ? JSON.stringify(body) : undefined,
    });
    const data = await res.json();
    if (!res.ok || data.ok === false) {
      throw new Error(data.error || 'Request failed');
    }
    return data;
  }

  // ---------------------------------------------------------------------
  // Toast
  // ---------------------------------------------------------------------
  let toastTimer = null;
  function showToast(message) {
    els.toast.textContent = message;
    els.toast.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => els.toast.classList.remove('show'), 2200);
    tg?.HapticFeedback?.notificationOccurred('success');
  }

  // ---------------------------------------------------------------------
  // Screen navigation (bottom tab bar)
  // ---------------------------------------------------------------------
  function navigateTo(screenName) {
    state.activeScreen = screenName;
    els.tabItems.forEach((item) => {
      item.classList.toggle('active', item.dataset.screen === screenName);
    });

    els.screenCategories.hidden = !(screenName === 'categories' && state.storeMode === 'categories');
    els.screenProducts.hidden = !((screenName === 'categories' && state.storeMode === 'products') || screenName === 'cart');
    els.screenDashboard.hidden = screenName !== 'dashboard';

    if (screenName === 'categories' && state.storeMode === 'categories') loadCategories();
    if (screenName === 'cart') renderCartAsProductView();
    if (screenName === 'dashboard') loadDashboard();
    if (screenName === 'news' || screenName === 'contact') {
      showToast(screenName === 'news' ? '📰 No news yet — check back soon!' : '✉️ support@eng251885.app');
    }
  }

  els.tabItems.forEach((item) => {
    item.addEventListener('click', () => navigateTo(item.dataset.screen));
  });

  // ---------------------------------------------------------------------
  // Store toggle (Categories | Products pill)
  // ---------------------------------------------------------------------
  els.storeToggle.querySelectorAll('button').forEach((btn) => {
    btn.addEventListener('click', () => {
      const mode = btn.dataset.mode;
      state.storeMode = mode;
      els.storeToggle.dataset.active = mode;
      els.storeToggle.querySelectorAll('button').forEach((b) => b.classList.toggle('active', b === btn));
      els.screenCategories.hidden = mode !== 'categories';
      els.screenProducts.hidden = mode !== 'products';
      if (mode === 'products' && state.products.length === 0) loadProducts();
    });
  });

  // ---------------------------------------------------------------------
  // SCREEN 1 — Categories
  // ---------------------------------------------------------------------
  async function loadCategories() {
    try {
      const data = await apiCall('categories');
      state.categories = data.categories;
    } catch {
      state.categories = MOCK_CATEGORIES; // graceful offline fallback
    }
    renderCategories();
  }

  function renderCategories() {
    els.categoryList.innerHTML = state.categories.map((cat) => `
      <div class="category-card" data-category-id="${cat.id}">
        <div class="category-card-head">
          <div class="category-card-title"><span class="category-icon">${cat.icon || '📦'}</span>${escapeHtml(cat.name)}</div>
          <div class="category-count">${cat.product_count ?? 0}</div>
        </div>
        <div class="subtag-row">
          ${(cat.sub_tags || []).map((tag) => `<button class="subtag-chip" data-subtag-id="${tag.id}" data-category-id="${cat.id}">${escapeHtml(tag.name)}</button>`).join('')}
        </div>
      </div>
    `).join('');

    els.categoryList.querySelectorAll('.category-card, .subtag-chip').forEach((node) => {
      node.addEventListener('click', (e) => {
        const target = e.target.closest('[data-category-id]');
        if (!target) return;
        const categoryId = target.dataset.categoryId;
        state.storeMode = 'products';
        els.storeToggle.dataset.active = 'products';
        els.storeToggle.querySelectorAll('button').forEach((b) => b.classList.toggle('active', b.dataset.mode === 'products'));
        els.screenCategories.hidden = true;
        els.screenProducts.hidden = false;
        loadProducts({ category_id: categoryId });
      });
    });
  }

  // ---------------------------------------------------------------------
  // SCREEN 2 — Products
  // ---------------------------------------------------------------------
  async function loadProducts(params = {}) {
    els.productGrid.innerHTML = Array(4).fill('<div class="skeleton" style="height:220px;"></div>').join('');
    try {
      const data = await apiCall('products', { params });
      state.products = data.products;
    } catch {
      state.products = MOCK_PRODUCTS; // graceful offline fallback
    }
    renderProducts();
  }

  function renderProducts() {
    const search = (els.searchInput.value || '').toLowerCase().trim();
    const filtered = state.products.filter((p) => !search || p.name.toLowerCase().includes(search));

    if (filtered.length === 0) {
      els.productGrid.innerHTML = `<div class="empty-state" style="grid-column:1/-1;">No products match your search.</div>`;
      return;
    }

    els.productGrid.innerHTML = filtered.map((p) => `
      <div class="product-card">
        <div class="product-photo">${p.image_url ? `<img src="${escapeAttr(p.image_url)}" alt="${escapeAttr(p.name)}">` : (p.emoji || '🧒')}</div>
        <div class="product-info">
          <div class="product-title">${escapeHtml(p.name)}</div>
          <div class="product-age-tag">${escapeHtml(p.name)} — ${escapeHtml(p.age_range || '')}</div>
          <div class="product-price">$${Number(p.price).toFixed(2)}</div>
          <button class="add-to-cart-btn" data-product-id="${p.id}">Add to Cart</button>
        </div>
      </div>
    `).join('');

    els.productGrid.querySelectorAll('.add-to-cart-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        const product = filtered.find((p) => String(p.id) === btn.dataset.productId);
        addToCart(product);
      });
    });
  }

  els.searchInput.addEventListener('input', debounce(renderProducts, 200));
  els.ageFilter.addEventListener('change', () => loadProducts({ age: els.ageFilter.value }));

  // ---------------------------------------------------------------------
  // Cart
  // ---------------------------------------------------------------------
  function addToCart(product) {
    if (!product) return;
    const entry = state.cart.get(product.id) || { product, quantity: 0 };
    entry.quantity += 1;
    state.cart.set(product.id, entry);
    updateCartBadge();
    showToast(`Added "${product.name}" to cart`);
  }

  function updateCartBadge() {
    const count = [...state.cart.values()].reduce((sum, e) => sum + e.quantity, 0);
    els.cartBadge.textContent = String(count);
    els.cartBadge.style.display = count > 0 ? 'flex' : 'none';
  }

  function renderCartAsProductView() {
    els.screenCategories.hidden = true;
    els.screenProducts.hidden = false;
    const items = [...state.cart.values()];
    if (items.length === 0) {
      els.productGrid.innerHTML = `<div class="empty-state" style="grid-column:1/-1;">Your cart is empty. Add some products!</div>`;
      return;
    }
    els.productGrid.innerHTML = items.map(({ product, quantity }) => `
      <div class="product-card">
        <div class="product-photo">${product.image_url ? `<img src="${escapeAttr(product.image_url)}" alt="${escapeAttr(product.name)}">` : (product.emoji || '🧒')}</div>
        <div class="product-info">
          <div class="product-title">${escapeHtml(product.name)}</div>
          <div class="product-age-tag">Qty: ${quantity}</div>
          <div class="product-price">$${(Number(product.price) * quantity).toFixed(2)}</div>
          <button class="add-to-cart-btn" data-checkout-id="${product.id}">Checkout</button>
        </div>
      </div>
    `).join('') + `
      <div style="grid-column:1/-1;">
        <button class="add-to-cart-btn" id="checkoutAllBtn" style="margin-top:4px;">Checkout All — $${cartTotal().toFixed(2)}</button>
      </div>
    `;
    $('#checkoutAllBtn')?.addEventListener('click', checkout);
  }

  function cartTotal() {
    return [...state.cart.values()].reduce((sum, e) => sum + Number(e.product.price) * e.quantity, 0);
  }

  async function checkout() {
    if (state.cart.size === 0) return;
    const items = [...state.cart.values()].map((e) => ({ product_id: e.product.id, quantity: e.quantity }));

    try {
      const data = await apiCall('create_order', {
        method: 'POST',
        auth: true,
        body: { items, payment_method: 'telegram_stars' },
      });

      if (data.checkout?.type === 'telegram_invoice' && tg) {
        // In production, the bot server pre-generates a real invoice link
        // via Bot API sendInvoice with currency XTR (Telegram Stars) and
        // returns it here for tg.openInvoice(). Wired up server-side.
        showToast('Opening Telegram Stars payment…');
      } else if (data.checkout?.url) {
        window.open(data.checkout.url, '_blank');
      }

      state.cart.clear();
      updateCartBadge();
      renderCartAsProductView();
    } catch (err) {
      showToast(`Checkout failed: ${err.message}`);
    }
  }

  // ---------------------------------------------------------------------
  // SCREEN 3 — Dashboard
  // ---------------------------------------------------------------------
  async function loadDashboard() {
    els.welcomeName.textContent = tgUser.username ? `@${tgUser.username}` : (tgUser.first_name || 'Guest');
    try {
      const data = await apiCall('dashboard', { auth: true });
      state.dashboardData = data;
      els.statOrders.textContent = data.stats.total_orders;
      els.statDownloads.textContent = data.stats.downloads;
      els.statKeys.textContent = data.stats.product_keys;
      els.statCompleted.textContent = data.stats.completed;
      els.spendValue.textContent = `$${data.profile.total_spent.toFixed(2)}`;
    } catch {
      els.statOrders.textContent = '0';
      els.statDownloads.textContent = '0';
      els.statKeys.textContent = '0';
      els.statCompleted.textContent = '0';
      els.spendValue.textContent = '$0.00';
    }
    renderDashboardTab();
  }

  els.dashSubtabs.querySelectorAll('.subtab').forEach((tab) => {
    tab.addEventListener('click', () => {
      state.dashboardTab = tab.dataset.tab;
      els.dashSubtabs.querySelectorAll('.subtab').forEach((t) => t.classList.toggle('active', t === tab));
      renderDashboardTab();
    });
  });

  async function renderDashboardTab() {
    if (state.dashboardTab !== 'orders') {
      els.dashContent.innerHTML = `<div class="empty-state">No ${state.dashboardTab} yet — start shopping to see them here!</div>`;
      return;
    }
    els.dashContent.innerHTML = `<div class="empty-state">Loading orders…</div>`;
    try {
      const data = await apiCall('orders', { auth: true });
      els.ordersBadge.textContent = String(data.orders.length);
      if (data.orders.length === 0) {
        els.dashContent.innerHTML = `<div class="empty-state">No orders yet.</div>`;
        return;
      }
      els.dashContent.innerHTML = data.orders.map((o) => `
        <div class="order-card">
          <div class="order-card-top">
            <div>
              <div class="order-id">Order #${o.id}</div>
              <div class="order-date">${new Date(o.created_at).toLocaleDateString()}</div>
            </div>
            <div class="order-status ${o.payment_status}">${o.payment_status}</div>
          </div>
          <div class="product-age-tag">$${Number(o.total_amount).toFixed(2)} · ${o.items.length} item(s)</div>
          ${o.payment_status === 'paid' ? `<button class="order-action-btn">Download Now</button>` : `<button class="order-action-btn" style="background:var(--canvas-raised);color:var(--text-secondary);">View Details</button>`}
        </div>
      `).join('');
    } catch {
      els.dashContent.innerHTML = `<div class="empty-state">Couldn't load orders. Pull to refresh.</div>`;
    }
  }

  els.dashCloseBtn.addEventListener('click', () => navigateTo('categories'));

  // ---------------------------------------------------------------------
  // Utilities
  // ---------------------------------------------------------------------
  function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  function escapeAttr(str) { return escapeHtml(str); }
  function debounce(fn, wait) {
    let t;
    return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), wait); };
  }

  // ---------------------------------------------------------------------
  // Init
  // ---------------------------------------------------------------------
  loadCategories();
  updateCartBadge();
})();
