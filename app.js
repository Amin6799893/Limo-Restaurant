/* ============================================================================
   🍋 LIMO RESTAURANT — Frontend Application (Hardening Phase — Fixed v6)
   ============================================================================
   تغییرات v6:
   - ✅ FIX-12v2: Modal.open idempotent با isOpen boolean (ساده‌تر و پایدارتر)
   - ✅ FIX-13: parseServerTimestamp — تبدیل UTC به Asia/Tehran
   - ✅ FIX-14: formatServerDate — ظهر تهران (08:30 UTC)
   - ✅ FIX-15: withButtonLoading robust (label && loading)
   - ✅ FIX-16: fieldSelect — استفاده از property به‌جای attribute
   - ✅ FIX-17: renderProfile با catch
   - ✅ FIX-18: renderCapacity با Number()
   - ✅ FIX-19: مقدار پیش‌فرض filter-order-date
   - ✅ حفظ همه BUG FIX های v4 و v5
   ============================================================================ */

'use strict';

/* ============================================================================
   SECTION: Global State
   ============================================================================ */
const State = {
  userType: null,
  currentUser: null,
  mustChangeCredentials: false,
  customer: {
    menu: null,
    cart: {},
    todayOrder: null,
    orders: [],
    ordersPage: 1,
    currentCategory: 'all',
    currentTab: 'menu',
    profileLoaded: false,
  },
  admin: {
    section: 'dashboard',
    settingsTab: 'general',
    orders: [],
    ordersPage: 1,
    orderFilter: { status: '', date: '' },
    customers: [],
    customersPage: 1,
    products: [],
    capacity: [],
    settings: {},
    menuCategory: 'all',
  },
  busy: false,
};

/**
 * FE-016: پاکسازی کامل State بعد از logout
 * BUG-001: همچنین UI دکمه‌های دسته‌بندی مشتری را reset می‌کند
 */
function resetAppState() {
  State.userType = null;
  State.currentUser = null;
  State.mustChangeCredentials = false;
  State.busy = false;

  Object.assign(State.customer, {
    menu: null,
    cart: {},
    todayOrder: null,
    orders: [],
    ordersPage: 1,
    currentCategory: 'all',
    currentTab: 'menu',
    profileLoaded: false,
  });

  Object.assign(State.admin, {
    section: 'dashboard',
    settingsTab: 'general',
    orders: [],
    ordersPage: 1,
    orderFilter: { status: '', date: '' },
    customers: [],
    customersPage: 1,
    products: [],
    capacity: [],
    settings: {},
    menuCategory: 'all',
  });

  document.querySelectorAll('#category-tabs .cat-tab').forEach((b) => {
    const active = b.dataset.cat === 'all';
    b.classList.toggle('is-active', active);
    b.setAttribute('aria-selected', String(active));
  });

  document.querySelectorAll('#menu-category-tabs .tab').forEach((b) => {
    const active = b.dataset.mcat === 'all';
    b.classList.toggle('is-active', active);
    b.setAttribute('aria-selected', String(active));
  });

  API.setCsrf(null);
}

/* ============================================================================
   SECTION: ApiError class (FE-010)
   ============================================================================ */
class ApiError extends Error {
  constructor(message, { status = 0, code = null, requestId = null } = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.requestId = requestId;
  }
}

/* ============================================================================
   SECTION: DOM Helpers
   ============================================================================ */
const $  = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

function el(tag, attrs = {}, children = []) {
  const node = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (v === undefined || v === null || v === false) continue;
    if (k === 'class') node.className = v;
    else if (k === 'text') node.textContent = v;
    else if (k === 'style') node.setAttribute('style', v);
    else if (k.startsWith('on') && typeof v === 'function') {
      node.addEventListener(k.slice(2).toLowerCase(), v);
    } else if (k === 'checked' && v === 'checked') node.checked = true;
    else if (k === 'selected' && v === 'selected') node.selected = true;
    else node.setAttribute(k, v);
  }
  for (const c of [].concat(children)) {
    if (c == null || c === false) continue;
    node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
  }
  return node;
}

function setText(node, text) { if (node) node.textContent = text == null ? '' : String(text); }
function show(node) { if (node) node.classList.remove('hidden'); }
function hide(node) { if (node) node.classList.add('hidden'); }

/* ============================================================================
   SECTION: Persian Numbers / Dates
   ============================================================================ */
const FA_DIGITS = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
function toFa(n) {
  return String(n == null ? '' : n).replace(/\d/g, d => FA_DIGITS[+d]);
}
function faWeekday(idx) {
  return ['شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه','جمعه'][idx] || '';
}
function faStatus(s) {
  return ({
    PENDING: 'در انتظار تأیید',
    APPROVED: 'تأیید شده',
    REJECTED: 'رد شده',
    CANCELLED: 'لغو شده',
  })[s] || s;
}
function statusClass(s) {
  return ({
    PENDING: 'badge-warn',
    APPROVED: 'badge-ok',
    REJECTED: 'badge-danger',
    CANCELLED: 'badge-neutral',
  })[s] || 'badge-neutral';
}
function faCategory(c) {
  return ({ food: 'غذا', drink: 'نوشیدنی', dessert: 'دسر' })[c] || c;
}
function faDays(csv) {
  if (!csv) return '—';
  const map = ['شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه','جمعه'];
  const parts = String(csv).split(',').map(s => s.trim()).filter(s => s !== '');
  const set = new Set(parts.map(s => +s).filter(n => !isNaN(n) && n >= 0 && n <= 6));
  if (set.size === 6 && !set.has(6)) return 'تمام روزهای کاری';
  if (set.size === 7) return 'همه روزها';
  return map.filter((_, i) => set.has(i)).join('، ') || '—';
}

/**
 * FIX-14 + FE-017 + BUG-006: تبدیل تاریخ میلادی سرور به تاریخ شمسی
 * - ساعت 08:30 UTC = ظهر تهران → جلوگیری از مرز روز
 * - timeZone صریح برای Safari
 */
function formatServerDate(serverDate) {
  if (!serverDate) return '—';
  const s = String(serverDate).trim();
  const m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!m) return s.slice(0, 10) || '—';
  try {
    // ظهر تهران = 08:30 UTC
    const d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], 8, 30, 0));
    if (isNaN(d.getTime())) {
      return `${m[1]}/${m[2]}/${m[3]}`;
    }
    const fa = new Intl.DateTimeFormat('fa-IR', {
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      calendar: 'persian',
      numberingSystem: 'arabext',
      timeZone: 'Asia/Tehran',
    }).format(d);
    return fa;
  } catch (_) {
    return `${m[1]}/${m[2]}/${m[3]}`;
  }
}

/**
 * FIX-13 + FE-001: تبدیل timestamp سرور (UTC) به وقت تهران
 * - اگر timestamp شامل Z یا offset نباشد، فرض می‌کنیم UTC است
 * - با Intl.DateTimeFormat به Asia/Tehran تبدیل می‌شود
 */
function parseServerTimestamp(ts) {
  if (!ts) return { date: '', time: '' };
  const s = String(ts).trim();

  // اگر timezone ندارد، فرض کن UTC است
  const hasTz = /Z|[+-]\d{2}:?\d{2}$/.test(s);
  const iso = hasTz ? s.replace(' ', 'T') : s.replace(' ', 'T') + 'Z';
  const d = new Date(iso);

  if (isNaN(d.getTime())) {
    // fallback: پارس دستی
    const m = s.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
    if (!m) return { date: s.slice(0, 10), time: s.slice(11, 16) };
    return { date: `${m[1]}-${m[2]}-${m[3]}`, time: `${m[4]}:${m[5]}` };
  }

  try {
    const parts = new Intl.DateTimeFormat('en-CA', {
      timeZone: 'Asia/Tehran',
      hour12: false,
      year: 'numeric', month: '2-digit', day: '2-digit',
      hour: '2-digit', minute: '2-digit',
    }).formatToParts(d);
    const get = (k) => (parts.find(p => p.type === k) || {}).value || '';
    return {
      date: `${get('year')}-${get('month')}-${get('day')}`,
      time: `${get('hour')}:${get('minute')}`,
    };
  } catch (_) {
    const m = s.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
    return m ? { date: `${m[1]}-${m[2]}-${m[3]}`, time: `${m[4]}:${m[5]}` }
             : { date: '', time: '' };
  }
}

/* ============================================================================
   SECTION: Password Strength
   ============================================================================ */
function calculatePasswordStrength(password) {
  const pw = String(password || '');
  if (!pw) return { score: 0, level: 'very_weak', label: 'خیلی ضعیف' };
  let score = 0;
  const len = pw.length;
  if (len >= 8) score += 1;
  if (len >= 12) score += 1;
  if (/[a-z]/.test(pw)) score += 1;
  if (/[A-Z]/.test(pw)) score += 1;
  if (/\d/.test(pw)) score += 1;
  if (/[^A-Za-z0-9]/.test(pw)) score += 1;
  if (score > 5) score = 5;
  let level, label;
  if (score <= 1) { level = 'very_weak'; label = 'خیلی ضعیف'; }
  else if (score === 2) { level = 'weak'; label = 'ضعیف'; }
  else if (score === 3) { level = 'medium'; label = 'متوسط'; }
  else if (score === 4) { level = 'strong'; label = 'قوی'; }
  else { level = 'very_strong'; label = 'خیلی قوی'; }
  return { score, level, label };
}

function bindPasswordStrength(inputEl, containerEl, fillEl, labelEl, scoreEl) {
  if (!inputEl || !containerEl || !fillEl || !labelEl) return;
  function update() {
    const val = inputEl.value || '';
    const { score, level, label } = calculatePasswordStrength(val);
    const percent = Math.min(100, Math.round((score / 5) * 100));
    fillEl.style.width = percent + '%';
    containerEl.dataset.level = level;
    const bar = containerEl.querySelector('.password-strength-bar');
    if (bar) bar.setAttribute('aria-valuenow', String(score));
    labelEl.textContent = val ? label : 'قدرت رمز عبور';
    if (scoreEl) scoreEl.textContent = val ? `${toFa(score)} از ${toFa(5)}` : '';
  }
  inputEl.addEventListener('input', update);
  update();
}

/**
 * FE-008: جلوگیری از double-bind
 */
function bindPasswordToggles(root = document) {
  $$('[data-toggle-password]', root).forEach(btn => {
    if (btn.dataset.bound === '1') return;
    btn.dataset.bound = '1';
    btn.addEventListener('click', () => {
      const targetId = btn.dataset.togglePassword;
      const input = document.getElementById(targetId);
      if (!input) return;
      const isPassword = input.type === 'password';
      input.type = isPassword ? 'text' : 'password';
      btn.setAttribute('aria-label', isPassword ? 'پنهان کردن رمز' : 'نمایش رمز');
      btn.classList.toggle('is-active', isPassword);
    });
  });
}

/* ============================================================================
   SECTION: Toast
   ============================================================================ */
const Toast = (() => {
  function getContainer() {
    return document.getElementById('toast-container');
  }
  function push(message, type = 'info', duration = 3600) {
    const container = getContainer();
    if (!container) return;
    const iconChar = type === 'success' ? '✓'
                   : type === 'error' ? '✕'
                   : type === 'warning' ? '!'
                   : 'i';
    const t = el('div', { class: `toast toast-${type}`, role: 'status' }, [
      el('span', { class: 'toast-icon', 'aria-hidden': 'true', text: iconChar }),
      el('span', { class: 'toast-msg', text: message }),
    ]);
    container.appendChild(t);
    requestAnimationFrame(() => t.classList.add('is-visible'));
    setTimeout(() => {
      t.classList.remove('is-visible');
      setTimeout(() => t.remove(), 250);
    }, duration);
  }
  return {
    success: (m) => push(m, 'success'),
    error:   (m) => push(m, 'error'),
    warning: (m) => push(m, 'warning'),
    info:    (m) => push(m, 'info'),
  };
})();

/* ============================================================================
   SECTION: Modal
   ============================================================================
   FIX-12v2: استفاده از isOpen boolean به‌جای openCount پیچیده
   ============================================================================ */
const Modal = (() => {
  const root = $('#modal-root');
  const titleEl = $('#modal-title');
  const bodyEl = $('#modal-body');
  const footerEl = $('#modal-footer');
  let lastFocus = null;
  let previousOverflow = '';
  let isOpen = false;

  function getFocusable() {
    return $$(
      'button:not([disabled]), input:not([disabled]):not([type="hidden"]), ' +
      'select:not([disabled]), textarea:not([disabled]), a[href], ' +
      '[tabindex]:not([tabindex="-1"])',
      root
    ).filter(n => n.offsetParent !== null);
  }

  function onKeydown(e) {
    if (e.key === 'Escape' && root.dataset.closable !== '0') {
      e.preventDefault();
      close();
      return;
    }
    if (e.key === 'Tab') {
      const focusable = getFocusable();
      if (!focusable.length) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    }
  }

  /**
   * FIX-12v2: idempotent — اگر مودال باز است، فقط محتوا را عوض کن
   */
  function open({ title, body, footer, onClose, closable = true }) {
    if (!root) return;

    if (!isOpen) {
      isOpen = true;
      lastFocus = document.activeElement;
      previousOverflow = document.body.style.overflow;
      document.addEventListener('keydown', onKeydown);
      document.body.style.overflow = 'hidden';
    }

    setText(titleEl, title || '');
    if (bodyEl) bodyEl.innerHTML = '';
    if (footerEl) footerEl.innerHTML = '';

    if (bodyEl) {
      if (typeof body === 'string') bodyEl.textContent = body;
      else if (body instanceof Node) bodyEl.appendChild(body);
    }

    if (footerEl) {
      if (typeof footer === 'string') footerEl.textContent = footer;
      else if (footer instanceof Node) footerEl.appendChild(footer);
    }

    root.classList.remove('hidden');
    root._onClose = onClose || null;
    root.dataset.closable = closable ? '1' : '0';

    const closeBtn = root.querySelector('.modal-close');
    if (closeBtn) closeBtn.style.display = closable ? '' : 'none';

    setTimeout(() => {
      const focusable = getFocusable();
      if (focusable.length) {
        try { focusable[0].focus(); } catch (_) {}
      }
      bindPasswordToggles(root);
    }, 150);
  }

  function close() {
    if (!root) return;
    if (root.dataset.closable === '0') return;
    if (!isOpen) return;

    isOpen = false;
    root.classList.add('hidden');
    root._onClose = null;
    document.body.style.overflow = previousOverflow || '';
    document.removeEventListener('keydown', onKeydown);
    if (lastFocus && typeof lastFocus.focus === 'function') {
      try { lastFocus.focus(); } catch (_) {}
    }
    lastFocus = null;
  }

  function confirm({ title, message, confirmText = 'تأیید', cancelText = 'انصراف', danger = false, onConfirm }) {
    const wrap = el('div', { class: 'modal-message', text: message });
    const footer = el('div', { class: 'modal-actions' }, [
      el('button', { class: 'btn btn-ghost', type: 'button', onclick: close, text: cancelText }),
      el('button', {
        class: `btn ${danger ? 'btn-danger' : 'btn-primary'}`,
        type: 'button',
        onclick: () => {
          close();
          if (typeof onConfirm === 'function') {
            try {
              const r = onConfirm();
              if (r && typeof r.catch === 'function') {
                r.catch(e => Toast.error((e && e.message) || 'خطا در انجام عملیات'));
              }
            } catch (e) {
              Toast.error((e && e.message) || 'خطا در انجام عملیات');
            }
          }
        },
        text: confirmText,
      }),
    ]);
    open({ title, body: wrap, footer });
  }

  function alert({ title, message, okText = 'باشه' }) {
    const wrap = el('div', { class: 'modal-message', text: message });
    const footer = el('div', { class: 'modal-actions' }, [
      el('button', { class: 'btn btn-primary', type: 'button', onclick: close, text: okText }),
    ]);
    open({ title, body: wrap, footer });
  }

  return { open, close, confirm, alert };
})();

const modalRootEl = $('#modal-root');
if (modalRootEl) {
  modalRootEl.addEventListener('click', (e) => {
    const t = e.target.closest('[data-modal-close]');
    if (!t) return;
    if (t.dataset.modalClose === 'overlay' || t.dataset.modalClose === 'button') {
      if (t === e.target || t.contains(e.target)) {
        Modal.close();
      }
    }
  });
}

/* ============================================================================
   SECTION: Loading
   ============================================================================ */
const Loading = (() => {
  const el = $('#global-loader');
  let count = 0;
  function show() { count++; if (el) el.classList.remove('hidden'); }
  function hide() { count = Math.max(0, count - 1); if (count === 0 && el) el.classList.add('hidden'); }
  return { show, hide };
})();

/* ============================================================================
   SECTION: API Client
   ============================================================================ */
const API = (() => {
  let csrfToken = null;
  function setCsrf(t) { csrfToken = t || null; }
  function getCsrf() { return csrfToken; }

  async function request(method, path, body) {
    const opts = {
      method,
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
    };
    if (method && method.toUpperCase() !== 'GET' && csrfToken) {
      opts.headers['X-CSRF-Token'] = csrfToken;
    }
    if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }

    let res;
    try {
      res = await fetch(path, opts);
    } catch (_e) {
      throw new ApiError(
        'خطا در ارتباط با سرور. لطفاً اتصال اینترنت خود را بررسی کنید.',
        { status: 0 }
      );
    }

    let payload = null;
    const ct = res.headers.get('Content-Type') || '';
    if (ct.includes('application/json')) {
      try { payload = await res.json(); } catch (_) { payload = null; }
    }

    const requestId = res.headers.get('X-Request-ID') || null;
    const code = payload && payload.code;
    const message = (payload && payload.message) || `خطا (${res.status})`;

    if (res.status === 401 && code === 'SESSION_STALE') {
      resetAppState();
      Toast.warning('نشست شما منقضی شده است. لطفاً دوباره وارد شوید.');
      setTimeout(() => routeBySession(), 400);
      throw new ApiError(message, { status: 401, code, requestId });
    }

    if (res.status === 401) {
      if (State.userType) {
        resetAppState();
        Toast.warning('نشست شما منقضی شده است. لطفاً دوباره وارد شوید.');
        setTimeout(() => routeBySession(), 400);
      }
      throw new ApiError(message, { status: 401, code, requestId });
    }

    if (res.status === 403 && code === 'MUST_CHANGE_CREDENTIALS') {
      State.mustChangeCredentials = true;
      showView('forceChange');
      bindForceChangeForm();
      Toast.warning('برای دسترسی کامل، ابتدا اطلاعات ورود پیش‌فرض را تغییر دهید.');
      throw new ApiError(message, { status: 403, code, requestId });
    }

    if (res.status === 403 && code === 'CSRF_STALE') {
      Toast.warning('توکن امنیتی منقضی شده است. لطفاً صفحه را دوباره بارگذاری کنید.');
      throw new ApiError(message, { status: 403, code, requestId });
    }

    if (res.status === 403) {
      throw new ApiError(message, { status: 403, code, requestId });
    }

    if (!res.ok) {
      throw new ApiError(message, { status: res.status, code, requestId });
    }

    if (payload && typeof payload.success !== 'undefined') {
      if (!payload.success) {
        throw new ApiError(payload.message || 'خطای نامشخص', { status: res.status, code, requestId });
      }
      return (payload.data === null || payload.data === undefined) ? {} : payload.data;
    }
    return payload === null ? {} : payload;
  }

  return {
    get:    (p)    => request('GET', p),
    post:   (p, b) => request('POST', p, b ?? {}),
    put:    (p, b) => request('PUT', p, b ?? {}),
    del:    (p)    => request('DELETE', p),
    setCsrf,
    getCsrf,
  };
})();

/* ============================================================================
   SECTION: Button Loading
   ============================================================================ */
/**
 * FIX-15: robust — وقتی فقط label هست یا فقط loading هست
 */
function withButtonLoading(btn, asyncFn) {
  if (!btn) return Promise.resolve().then(asyncFn);
  if (btn.dataset.loading === '1') return Promise.resolve();
  btn.dataset.loading = '1';

  const label = btn.querySelector('.btn-label');
  const loading = btn.querySelector('.btn-loading');
  const hasStructure = !!(label && loading);
  let originalHTML = '';
  if (!hasStructure) {
    originalHTML = btn.innerHTML;
    btn.textContent = '...';
  }

  if (label) label.classList.add('hidden');
  if (loading) loading.classList.remove('hidden');
  btn.disabled = true;

  return Promise.resolve()
    .then(asyncFn)
    .finally(() => {
      btn.dataset.loading = '0';
      btn.disabled = false;
      if (label) label.classList.remove('hidden');
      if (loading) loading.classList.add('hidden');
      if (!hasStructure) btn.innerHTML = originalHTML;
    });
}

/* ============================================================================
   SECTION: View Router
   ============================================================================ */
const Views = {
  customerLogin: $('#view-customer-login'),
  adminLogin: $('#view-admin-login'),
  forceChange: $('#view-force-change'),
  customerApp: $('#view-customer-app'),
  adminApp: $('#view-admin-app'),
  notFound: $('#view-not-found'),
};

function showView(name) {
  Object.values(Views).forEach(v => v && v.classList.add('hidden'));
  if (Views[name]) Views[name].classList.remove('hidden');
  document.body.dataset.view = name;
  window.scrollTo({ top: 0, behavior: 'auto' });
}

/**
 * BUG-014: نمایش پیام واضح در صورت خطای شبکه
 */
async function routeBySession() {
  const path = window.location.pathname;
  const isAdminPath = path.startsWith('/admin');

  try {
    const s = await API.get('/api/auth/session');

    if (isAdminPath) {
      if (s && s.authenticated && s.user_type === 'admin') {
        if (s.csrf_token) API.setCsrf(s.csrf_token);
        State.userType = 'admin';
        State.currentUser = s.user || {};
        State.mustChangeCredentials = !!s.must_change_credentials;
        if (State.mustChangeCredentials) {
          showView('forceChange');
          bindForceChangeForm();
          return;
        }
        showView('adminApp');
        await AdminApp.init();
        return;
      }
      showView('adminLogin');
      return;
    }

    if (s && s.authenticated && s.user_type === 'customer') {
      if (s.csrf_token) API.setCsrf(s.csrf_token);
      State.userType = 'customer';
      State.currentUser = s.user || {};
      State.mustChangeCredentials = false;
      showView('customerApp');
      await CustomerApp.init();
      return;
    }
    showView('customerLogin');
  } catch (err) {
    if (err && err.status === 0) {
      Toast.error('خطا در ارتباط با سرور. لطفاً اتصال اینترنت خود را بررسی کنید.');
    }
    showView(isAdminPath ? 'adminLogin' : 'customerLogin');
  }
}

/* ============================================================================
   SECTION: Force Change
   ============================================================================ */
function bindForceChangeForm() {
  const form = $('#form-force-change');
  if (!form || form.dataset.bound === '1') return;
  form.dataset.bound = '1';

  const currentUsernameEl = $('#fc-current-username');
  if (currentUsernameEl) {
    currentUsernameEl.value = (State.currentUser && State.currentUser.username) || '';
  }

  bindPasswordToggles(form);

  const pwInput = $('#fc-new-password');
  const pwContainer = $('#fc-strength');
  const pwFill = $('#fc-strength-fill');
  const pwLabel = $('#fc-strength-label');
  const pwScore = $('#fc-strength-score');
  bindPasswordStrength(pwInput, pwContainer, pwFill, pwLabel, pwScore);

  const confirmInput = $('#fc-confirm-password');
  const confirmError = $('#fc-confirm-error');

  function checkConfirmMatch() {
    if (!confirmInput || !confirmError) return;
    const a = pwInput ? pwInput.value : '';
    const b = confirmInput.value;
    if (b && a !== b) {
      confirmError.classList.remove('hidden');
      confirmInput.classList.add('input-error');
    } else {
      confirmError.classList.add('hidden');
      confirmInput.classList.remove('input-error');
    }
  }
  if (pwInput) pwInput.addEventListener('input', checkConfirmMatch);
  if (confirmInput) confirmInput.addEventListener('input', checkConfirmMatch);

  const logoutBtn = $('#fc-logout-btn');
  if (logoutBtn && !logoutBtn.dataset.bound) {
    logoutBtn.dataset.bound = '1';
    logoutBtn.addEventListener('click', async () => {
      if (logoutBtn.dataset.loading === '1') return;
      logoutBtn.dataset.loading = '1';
      try {
        try { await API.post('/api/auth/admin/logout'); } catch (_) {}
        resetAppState();
        showView('adminLogin');
      } finally {
        logoutBtn.dataset.loading = '0';
      }
    });
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const currentPassword = $('#fc-current-password').value;
    const newUsername = $('#fc-new-username').value.trim();
    const newPassword = pwInput ? pwInput.value : '';
    const confirmPassword = confirmInput ? confirmInput.value : '';

    if (newUsername) {
      if (newUsername.length < 4) { Toast.warning('نام کاربری باید حداقل ۴ کاراکتر باشد.'); return; }
      if (!/^[A-Za-z0-9_.\-]+$/.test(newUsername)) {
        Toast.warning('نام کاربری فقط می‌تواند شامل حروف انگلیسی، عدد، نقطه، خط تیره و زیرخط باشد.');
        return;
      }
    }
    if (!currentPassword) { Toast.warning('رمز عبور فعلی را وارد کنید.'); return; }
    if (!newPassword) { Toast.warning('رمز عبور جدید را وارد کنید.'); return; }
    if (newPassword.length < 8) { Toast.warning('رمز عبور باید حداقل ۸ کاراکتر باشد.'); return; }
    const strength = calculatePasswordStrength(newPassword);
    if (strength.score < 2) {
      Toast.warning('رمز عبور انتخاب‌شده بسیار ضعیف است.');
      return;
    }
    if (newPassword !== confirmPassword) {
      if (confirmError) confirmError.classList.remove('hidden');
      Toast.warning('رمز عبور جدید و تکرار آن یکسان نیستند.');
      return;
    }

    const btn = $('#fc-submit-btn');
    await withButtonLoading(btn, async () => {
      try {
        const payload = {
          current_password: currentPassword,
          new_password: newPassword,
          confirm_password: confirmPassword,
        };
        if (newUsername) payload.new_username = newUsername;

        const data = await API.post('/api/admin/change-credentials', payload);
        if (data && data.csrf_token) API.setCsrf(data.csrf_token);
        State.mustChangeCredentials = false;
        if (State.currentUser && data && data.username) State.currentUser.username = data.username;
        Toast.success('اطلاعات حساب با موفقیت به‌روزرسانی شد.');
        showView('adminApp');
        await AdminApp.init();
      } catch (err) {
        Toast.error(err.message || 'خطا در تغییر اطلاعات حساب');
      }
    });
  });
}

/* ============================================================================
   SECTION: Customer App
   ============================================================================ */
const CustomerApp = (() => {

  function resetCategoryUI() {
    State.customer.currentCategory = 'all';
    $$('#category-tabs .cat-tab').forEach((b) => {
      const active = b.dataset.cat === 'all';
      b.classList.toggle('is-active', active);
      b.setAttribute('aria-selected', String(active));
    });
  }

  async function init() {
    setText($('#cust-company-name'), (State.currentUser && State.currentUser.company_name) || '—');
    resetCategoryUI();
    bindEvents();
    switchTab('menu');
    await Promise.all([loadMenu(), loadTodayOrder()]);
    await loadOrders();
  }

  function bindEvents() {
    const logoutBtn = $('#cust-logout-btn');
    if (logoutBtn && !logoutBtn.dataset.bound) {
      logoutBtn.dataset.bound = '1';
      logoutBtn.addEventListener('click', async () => {
        if (logoutBtn.dataset.loading === '1') return;
        logoutBtn.dataset.loading = '1';
        try {
          try { await API.post('/api/auth/customer/logout'); } catch (_) {}
          resetAppState();
          showView('customerLogin');
        } finally {
          logoutBtn.dataset.loading = '0';
        }
      });
    }

    const catTabs = $('#category-tabs');
    if (catTabs && !catTabs.dataset.bound) {
      catTabs.dataset.bound = '1';
      catTabs.addEventListener('click', (e) => {
        const btn = e.target.closest('.cat-tab');
        if (!btn) return;
        $$('.cat-tab', catTabs).forEach(b => {
          const active = b === btn;
          b.classList.toggle('is-active', active);
          b.setAttribute('aria-selected', String(active));
        });
        State.customer.currentCategory = btn.dataset.cat || 'all';
        renderMenu();
      });
    }

    const bn = document.querySelector('.bottom-nav');
    if (bn && !bn.dataset.bound) {
      bn.dataset.bound = '1';
      bn.addEventListener('click', (e) => {
        const item = e.target.closest('.bn-item');
        if (!item) return;
        switchTab(item.dataset.custTab);
      });
    }

    const bobBtn = $('#bob-open-summary');
    if (bobBtn && !bobBtn.dataset.bound) {
      bobBtn.dataset.bound = '1';
      bobBtn.addEventListener('click', openSummary);
    }

    bindQtyDelegation();
  }

  function switchTab(tab) {
    State.customer.currentTab = tab;
    $$('.cust-tab').forEach(t => t.classList.add('hidden'));
    const map = { menu: '#cust-tab-menu', orders: '#cust-tab-orders', profile: '#cust-tab-profile' };
    const target = $(map[tab]);
    if (target) target.classList.remove('hidden');
    $$('.bn-item').forEach(b => b.classList.toggle('is-active', b.dataset.custTab === tab));

    if (tab === 'menu') {
      resetCategoryUI();
      renderMenu();
    }
    if (tab === 'orders') loadOrders();
    if (tab === 'profile') {
      renderProfile().catch(e => Toast.error(e.message || 'خطا در بارگذاری پروفایل'));
    }
  }

  async function loadMenu() {
    try {
      const data = await API.get('/api/customer/menu');
      State.customer.menu = data;
      const serverIdx = data.weekday ?? 0;
      setText($('#cust-today-label'), `${faWeekday(serverIdx)}، ${formatServerDate(data.date)}`);
      const badge = $('#cust-window-badge');
      if (badge) {
        if (data.is_friday) {
          badge.textContent = 'جمعه — تعطیل';
          badge.className = 'cust-window-badge badge badge-danger';
        } else if (data.ordering_allowed) {
          badge.textContent = 'سفارش‌گیری فعال';
          badge.className = 'cust-window-badge badge badge-ok';
        } else {
          badge.textContent = data.ordering_message || 'سفارش‌گیری غیرفعال';
          badge.className = 'cust-window-badge badge badge-warn';
        }
      }
      setText($('#cust-menu-sub'), data.is_friday
        ? 'امروز جمعه است و سفارش‌گیری انجام نمی‌شود.'
        : (data.ordering_allowed ? 'ظرفیت‌ها به‌صورت زنده به‌روزرسانی می‌شود.' : (data.ordering_message || '')));
      renderMenu();
      updateBottomBar();
    } catch (e) {
      Toast.error(e.message || 'خطا در بارگذاری منو');
    }
  }

  function renderMenu() {
    const container = $('#menu-container');
    const data = State.customer.menu;
    if (!data || !container) return;

    const cat = String(State.customer.currentCategory || 'all').toLowerCase();
    const allItems = Array.isArray(data.items) ? data.items : [];
    const items = allItems.filter((it) => {
      if (cat === 'all') return true;
      return String(it && it.category || '').toLowerCase() === cat;
    });

    if (!items.length) {
      container.innerHTML = '';
      container.appendChild(emptyState(
        '🍋',
        data.is_friday ? 'امروز جمعه است' : 'منویی برای نمایش نیست',
        data.is_friday ? 'سفارش‌گیری جمعه انجام نمی‌شود.' : 'لطفاً بعداً مراجعه کنید.'
      ));
      return;
    }
    container.innerHTML = '';
    for (const item of items) {
      container.appendChild(renderProductCard(item));
    }
  }

  function renderProductCard(item) {
    const card = el('div', { class: 'product-card' });
    const head = el('div', { class: 'pc-head' }, [
      el('div', { class: 'pc-emoji', 'aria-hidden': 'true', text: item.category === 'food' ? '🍗' : item.category === 'drink' ? '🥤' : '🍮' }),
      el('div', { class: 'pc-info' }, [
        el('h3', { class: 'pc-name', text: item.name }),
        el('p', { class: 'pc-desc', text: item.description || '' }),
        el('span', { class: 'pc-cat', text: faCategory(item.category) }),
      ]),
    ]);
    card.appendChild(head);
    if (item.has_variants) {
      const vWrap = el('div', { class: 'pc-variants' });
      for (const v of (item.variants || [])) {
        vWrap.appendChild(renderVariantRow(item, v));
      }
      card.appendChild(vWrap);
    } else {
      card.appendChild(renderSimpleRow(item));
    }
    return card;
  }

  function renderSimpleRow(item) {
    const avail = item.available ?? 0;
    const key = `${item.id}:`;
    const qty = State.customer.cart[key] || 0;
    const out = avail <= 0;
    const wrap = el('div', { class: 'pc-row' + (out ? ' is-out' : '') }, [
      el('div', { class: 'pc-avail' }, [
        el('span', { class: 'pc-avail-label', text: out ? 'موجودی به اتمام رسیده' : 'موجودی' }),
        !out && el('span', { class: 'pc-avail-num', text: `${toFa(avail)} نفر` }),
      ]),
    ]);
    if (!out) wrap.appendChild(quantitySelector({ key, qty, max: avail }));
    return wrap;
  }

  function renderVariantRow(item, v) {
    const avail = v.available ?? 0;
    const key = `${item.id}:${v.id}`;
    const qty = State.customer.cart[key] || 0;
    const out = avail <= 0;
    const wrap = el('div', { class: 'pc-row pc-row-variant' + (out ? ' is-out' : '') }, [
      el('div', { class: 'pc-variant-name' }, [
        el('strong', { text: v.name }),
        v.description ? el('span', { class: 'pc-variant-desc', text: v.description }) : null,
      ]),
      el('div', { class: 'pc-avail' }, [
        el('span', { class: 'pc-avail-label', text: out ? 'اتمام' : 'موجودی' }),
        !out && el('span', { class: 'pc-avail-num', text: `${toFa(avail)}` }),
      ]),
    ]);
    if (!out) wrap.appendChild(quantitySelector({ key, qty, max: avail }));
    return wrap;
  }

  function quantitySelector({ key, qty, max }) {
    const wrap = el('div', { class: 'qty-selector', 'data-qty-key': key, 'data-qty-max': String(max) });
    const minus = el('button', { class: 'qty-btn', type: 'button', 'aria-label': 'کاهش', 'data-act': 'dec', text: '−' });
    const val = el('span', { class: 'qty-value', text: toFa(qty), 'aria-live': 'polite' });
    const plus = el('button', { class: 'qty-btn', type: 'button', 'aria-label': 'افزایش', 'data-act': 'inc', text: '+' });
    minus.disabled = qty <= 0;
    plus.disabled = qty >= max;
    wrap.append(minus, val, plus);
    return wrap;
  }

  function bindQtyDelegation() {
    const root = document.getElementById('menu-container');
    if (!root || root.dataset.qtyBound === '1') return;
    root.dataset.qtyBound = '1';
    root.addEventListener('click', (e) => {
      const btn = e.target.closest('.qty-btn');
      if (!btn) return;
      const wrap = btn.closest('.qty-selector');
      if (!wrap) return;
      const key = wrap.dataset.qtyKey;
      const max = parseInt(wrap.dataset.qtyMax, 10) || 0;
      const cur = State.customer.cart[key] || 0;
      if (btn.dataset.act === 'dec') {
        if (cur <= 0) return;
        setCartQty(key, cur - 1, max);
      } else if (btn.dataset.act === 'inc') {
        if (cur >= max) {
          Toast.warning(`حداکثر موجودی این محصول ${toFa(max)} است.`);
          return;
        }
        setCartQty(key, cur + 1, max);
      }
    });
  }

  function setCartQty(key, qty, max) {
    if (qty <= 0) delete State.customer.cart[key];
    else State.customer.cart[key] = Math.min(qty, max);
    renderMenu();
    updateBottomBar();
  }

  function cartTotal() {
    return Object.values(State.customer.cart).reduce((a, b) => a + b, 0);
  }

  function updateBottomBar() {
    const bar = $('#bottom-order-bar');
    const count = cartTotal();
    if (count > 0) {
      show(bar);
      setText($('#bob-count'), toFa(count));
    } else {
      hide(bar);
    }
  }

  function openSummary() {
    const menu = State.customer.menu;
    if (!menu) return;
    const cart = State.customer.cart;
    const keys = Object.keys(cart);
    if (!keys.length) {
      Toast.info('سبد سفارش شما خالی است.');
      return;
    }
    const items = [];
    const phantomKeys = [];
    for (const key of keys) {
      const [pidStr, vidStr] = key.split(':');
      const pid = +pidStr, vid = vidStr ? +vidStr : null;
      const product = (menu.items || []).find(p => p.id === pid);
      if (!product) {
        phantomKeys.push(key);
        continue;
      }
      let name = product.name;
      if (vid) {
        const v = (product.variants || []).find(vv => vv.id === vid);
        if (!v) {
          phantomKeys.push(key);
          continue;
        }
        name += ` — ${v.name}`;
      }
      items.push({ key, product_id: pid, variant_id: vid, quantity: cart[key], name });
    }

    if (phantomKeys.length) {
      for (const k of phantomKeys) delete State.customer.cart[k];
      updateBottomBar();
      renderMenu();
    }

    if (!items.length) {
      Toast.info('سبد سفارش شما خالی است.');
      return;
    }

    const total = items.reduce((a, b) => a + b.quantity, 0);
    const body = el('div', { class: 'summary-body' });
    for (const it of items) {
      body.appendChild(el('div', { class: 'summary-row' }, [
        el('span', { class: 'summary-name', text: it.name }),
        el('span', { class: 'summary-qty', text: `× ${toFa(it.quantity)}` }),
      ]));
    }
    body.appendChild(el('div', { class: 'summary-total' }, [
      el('span', { text: 'تعداد کل اقلام' }),
      el('strong', { text: toFa(total) }),
    ]));
    const footer = el('div', { class: 'modal-actions' }, [
      el('button', { class: 'btn btn-ghost', type: 'button', onclick: Modal.close, text: 'ادامه انتخاب' }),
      el('button', {
        class: 'btn btn-primary', type: 'button', text: 'نهایی کردن سفارش',
        onclick: async (e) => {
          const btn = e.currentTarget;
          await withButtonLoading(btn, async () => {
            try {
              const token = 'req-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10);
              const payload = {
                request_token: token,
                items: items.map(it => ({
                  product_id: it.product_id,
                  variant_id: it.variant_id,
                  quantity: it.quantity,
                })),
              };
              const data = await API.post('/api/customer/orders', payload);
              State.customer.cart = {};
              State.customer.todayOrder = data;
              updateBottomBar();
              Modal.close();
              Modal.alert({
                title: 'سفارش شما ثبت شد',
                message: 'سفارش شما با موفقیت ثبت شد و در انتظار تأیید رستوران است.',
              });
              await loadMenu();
              await loadOrders();
              switchTab('orders');
            } catch (err) {
              if (err.status === 409) {
                Toast.warning(err.message);
                await loadMenu();
              } else {
                Toast.error(err.message || 'خطا در ثبت سفارش');
              }
            }
          });
        },
      }),
    ]);
    Modal.open({ title: 'خلاصه سفارش', body, footer });
  }

  async function loadTodayOrder() {
    try {
      const data = await API.get('/api/customer/orders/today');
      State.customer.todayOrder = (data && data.order) || null;
    } catch (_) {
      State.customer.todayOrder = null;
    }
  }

  async function loadOrders(page = 1) {
    const container = $('#customer-orders-container');
    if (!container) return;
    State.customer.ordersPage = page;
    container.innerHTML = '';
    container.appendChild(skeletonList(3));
    try {
      const data = await API.get(`/api/customer/orders?page=${page}`);
      State.customer.orders = (data && data.orders) || [];
      renderOrders();
      renderPagination('customer-orders-pagination', data && data.pagination, loadOrders);
    } catch (e) {
      container.innerHTML = '';
      container.appendChild(emptyState('⚠', 'خطا در بارگذاری سفارش‌ها', e.message || ''));
    }
  }

  function renderOrders() {
    const container = $('#customer-orders-container');
    if (!container) return;
    const list = State.customer.orders;
    container.innerHTML = '';
    if (!list.length) {
      container.appendChild(emptyState('🍋', 'هنوز سفارشی ثبت نشده', 'وقتی سفارشی ثبت کنید، اینجا نمایش داده می‌شود.'));
      return;
    }
    for (const o of list) container.appendChild(renderOrderCard(o));
  }

  function renderOrderCard(o) {
    const card = el('div', {
      class: 'order-card', role: 'button', tabindex: '0',
      'aria-label': `سفارش ${o.order_date} — ${faStatus(o.status)}`,
    });
    const ts = parseServerTimestamp(o.created_at);
    const head = el('div', { class: 'oc-head' }, [
      el('div', {}, [
        el('div', { class: 'oc-date', text: formatServerDate(o.order_date) }),
        el('div', { class: 'oc-time', text: ts.time }),
      ]),
      el('span', { class: `badge ${statusClass(o.status)}`, text: faStatus(o.status) }),
    ]);
    card.appendChild(head);
    if (o.status === 'REJECTED' && o.rejection_reason) {
      card.appendChild(el('div', { class: 'oc-reason' }, [
        el('strong', { text: 'دلیل رد: ' }),
        el('span', { text: o.rejection_reason }),
      ]));
    }
    card.addEventListener('click', () => openOrderDetail(o.id));
    card.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        openOrderDetail(o.id);
      }
    });
    return card;
  }

  async function openOrderDetail(id) {
    try {
      Loading.show();
      const o = await API.get(`/api/customer/orders/${id}`);
      Loading.hide();
      const body = el('div', { class: 'order-detail' }, [
        el('div', { class: 'od-row' }, [
          el('span', { text: 'وضعیت' }),
          el('span', { class: `badge ${statusClass(o.status)}`, text: faStatus(o.status) }),
        ]),
        el('div', { class: 'od-row' }, [el('span', { text: 'تاریخ' }), el('span', { text: formatServerDate(o.order_date) })]),
        el('div', { class: 'od-row' }, [el('span', { text: 'زمان ثبت' }), el('span', { text: parseServerTimestamp(o.created_at).time })]),
        el('div', { class: 'od-sep' }),
        el('h4', { text: 'اقلام سفارش' }),
        el('div', { class: 'od-items' }, (o.items || []).map(it =>
          el('div', { class: 'od-item' }, [
            el('span', { text: it.variant_name ? `${it.product_name} — ${it.variant_name}` : it.product_name }),
            el('strong', { text: `× ${toFa(it.quantity)}` }),
          ])
        )),
      ]);
      if (o.status === 'REJECTED' && o.rejection_reason) {
        body.appendChild(el('div', { class: 'oc-reason' }, [
          el('strong', { text: 'دلیل رد: ' }),
          el('span', { text: o.rejection_reason }),
        ]));
      }
      const footer = el('div', { class: 'modal-actions' });
      if (o.status === 'PENDING') {
        footer.appendChild(el('button', {
          class: 'btn btn-danger', type: 'button', text: 'لغو سفارش',
          onclick: () => {
            Modal.close();
            Modal.confirm({
              title: 'لغو سفارش',
              message: 'آیا از لغو این سفارش مطمئن هستید؟',
              danger: true,
              confirmText: 'بله، لغو کن',
              onConfirm: async () => {
                try {
                  await API.post(`/api/customer/orders/${id}/cancel`);
                  Toast.success('سفارش لغو شد.');
                  await loadOrders();
                  await loadMenu();
                } catch (e) {
                  Toast.error(e.message || 'خطا در لغو سفارش');
                }
              },
            });
          },
        }));
      }
      footer.appendChild(el('button', { class: 'btn btn-ghost', type: 'button', onclick: Modal.close, text: 'بستن' }));
      Modal.open({ title: `جزئیات سفارش #${toFa(o.id)}`, body, footer });
    } catch (e) {
      Loading.hide();
      Toast.error(e.message || 'خطا در بارگذاری جزئیات');
    }
  }

  async function renderProfile() {
    const container = $('#customer-profile-card');
    if (!container) return;
    container.innerHTML = '';
    container.appendChild(skeletonList(1));
    try {
      const p = await API.get('/api/customer/profile');
      container.innerHTML = '';
      container.append(
        infoRow('نام شرکت', p.company_name),
        infoRow('کد مشتری', p.customer_code),
        infoRow('تلفن', p.phone || '—'),
        infoRow('آدرس', p.address || '—'),
      );
    } catch (e) {
      container.innerHTML = '';
      container.appendChild(emptyState('⚠', 'خطا در بارگذاری پروفایل', e.message || ''));
    }
  }

  function infoRow(label, value) {
    return el('div', { class: 'info-row' }, [
      el('span', { class: 'info-label', text: label }),
      el('span', { class: 'info-value', text: value }),
    ]);
  }

  return { init, reload: () => Promise.all([loadMenu(), loadOrders()]) };
})();

/* ============================================================================
   SECTION: Pagination Helper
   ============================================================================ */
function renderPagination(containerId, pagination, onPageChange) {
  const container = document.getElementById(containerId);
  if (!container) return;
  container.innerHTML = '';
  if (!pagination || typeof pagination.total !== 'number' || pagination.total <= pagination.limit) {
    container.classList.add('hidden');
    return;
  }
  container.classList.remove('hidden');
  const { page, limit, total, has_more } = pagination;
  const totalPages = Math.max(1, Math.ceil(total / limit));

  const prev = el('button', {
    class: 'btn btn-ghost btn-sm', type: 'button', text: 'قبلی',
    onclick: () => { if (page > 1) onPageChange(page - 1); },
  });
  prev.disabled = page <= 1;

  const info = el('span', { class: 'pagination-info', text: `صفحه ${toFa(page)} از ${toFa(totalPages)}` });

  const next = el('button', {
    class: 'btn btn-ghost btn-sm', type: 'button', text: 'بعدی',
    onclick: () => { if (has_more) onPageChange(page + 1); },
  });
  next.disabled = !has_more;

  container.append(next, info, prev);
}

/* ============================================================================
   SECTION: Admin App
   ============================================================================ */
const AdminApp = (() => {
  async function init() {
    bindEvents();
    bindSettingsTabs();
    bindSecurityForm();
    await loadDashboard();
    switchSection('dashboard');
  }

  function bindEvents() {
    const nav = $('.sidebar-nav');
    if (nav && !nav.dataset.bound) {
      nav.dataset.bound = '1';
      nav.addEventListener('click', (e) => {
        const btn = e.target.closest('.sn-item');
        if (!btn) return;
        switchSection(btn.dataset.adminSection);
        closeDrawer();
      });
    }

    const logoutBtn = $('#admin-logout-btn');
    if (logoutBtn && !logoutBtn.dataset.bound) {
      logoutBtn.dataset.bound = '1';
      logoutBtn.addEventListener('click', async () => {
        if (logoutBtn.dataset.loading === '1') return;
        logoutBtn.dataset.loading = '1';
        try {
          try { await API.post('/api/auth/admin/logout'); } catch (_) {}
          resetAppState();
          showView('adminLogin');
        } finally {
          logoutBtn.dataset.loading = '0';
        }
      });
    }

    const dt = $('#admin-drawer-toggle');
    if (dt && !dt.dataset.bound) {
      dt.dataset.bound = '1';
      dt.addEventListener('click', openDrawer);
    }
    const backdrop = $('#sidebar-backdrop');
    if (backdrop && !backdrop.dataset.bound) {
      backdrop.dataset.bound = '1';
      backdrop.addEventListener('click', closeDrawer);
    }

    const fStatus = $('#filter-order-status');
    if (fStatus && !fStatus.dataset.bound) {
      fStatus.dataset.bound = '1';
      fStatus.addEventListener('change', () => {
        State.admin.orderFilter.status = fStatus.value;
        loadOrders(1);
      });
    }

    /**
     * FIX-19: مقدار پیش‌فرض برای filter-order-date
     */
    const fDate = $('#filter-order-date');
    if (fDate && !fDate.dataset.bound) {
      fDate.dataset.bound = '1';
      if (!fDate.value) {
        const _d = new Date();
        const _p = (n) => String(n).padStart(2, '0');
        fDate.value = `${_d.getFullYear()}-${_p(_d.getMonth() + 1)}-${_p(_d.getDate())}`;
      }
      fDate.addEventListener('change', () => {
        State.admin.orderFilter.date = fDate.value;
        loadOrders(1);
      });
    }
    const refreshOrders = $('#btn-refresh-orders');
    if (refreshOrders && !refreshOrders.dataset.bound) {
      refreshOrders.dataset.bound = '1';
      refreshOrders.addEventListener('click', () => loadOrders(1));
    }

    const searchCust = $('#search-customers');
    if (searchCust && !searchCust.dataset.bound) {
      searchCust.dataset.bound = '1';
      let t = null;
      searchCust.addEventListener('input', () => {
        clearTimeout(t);
        t = setTimeout(() => loadCustomers(1), 300);
      });
    }
    const newCust = $('#btn-new-customer');
    if (newCust && !newCust.dataset.bound) {
      newCust.dataset.bound = '1';
      newCust.addEventListener('click', () => openCustomerForm(null));
    }

    const newProd = $('#btn-new-product');
    if (newProd && !newProd.dataset.bound) {
      newProd.dataset.bound = '1';
      newProd.addEventListener('click', () => openProductForm(null));
    }
    const mcatTabs = $('#menu-category-tabs');
    if (mcatTabs && !mcatTabs.dataset.bound) {
      mcatTabs.dataset.bound = '1';
      mcatTabs.addEventListener('click', (e) => {
        const b = e.target.closest('.tab');
        if (!b) return;
        $$('.tab', mcatTabs).forEach(x => {
          const active = x === b;
          x.classList.toggle('is-active', active);
          x.setAttribute('aria-selected', String(active));
        });
        State.admin.menuCategory = b.dataset.mcat;
        renderProducts();
      });
    }

    const refreshCap = $('#btn-refresh-capacity');
    if (refreshCap && !refreshCap.dataset.bound) {
      refreshCap.dataset.bound = '1';
      refreshCap.addEventListener('click', loadCapacity);
    }

    const dl = $('#btn-download-csv');
    if (dl && !dl.dataset.bound) {
      dl.dataset.bound = '1';
      dl.addEventListener('click', () => {
        const d = $('#report-date').value;
        const q = d ? `?date=${encodeURIComponent(d)}` : '';
        window.location.href = `/api/admin/reports/orders.csv${q}`;
      });
    }

    const cleanupBtn = $('#btn-cleanup');
    if (cleanupBtn && !cleanupBtn.dataset.bound) {
      cleanupBtn.dataset.bound = '1';
      cleanupBtn.addEventListener('click', onCleanup);
    }
  }

  function bindSettingsTabs() {
    const tabsRoot = $('#settings-tabs');
    if (!tabsRoot || tabsRoot.dataset.bound === '1') return;
    tabsRoot.dataset.bound = '1';
    tabsRoot.addEventListener('click', (e) => {
      const tab = e.target.closest('.tab');
      if (!tab) return;
      const name = tab.dataset.settingsTab;
      State.admin.settingsTab = name;
      $$('.tab', tabsRoot).forEach(t => {
        const active = t === tab;
        t.classList.toggle('is-active', active);
        t.setAttribute('aria-selected', String(active));
      });
      const panelGeneral = $('#settings-panel-general');
      const panelSecurity = $('#settings-panel-security');
      if (name === 'security') {
        if (panelGeneral) panelGeneral.classList.add('hidden');
        if (panelSecurity) panelSecurity.classList.remove('hidden');
        loadSecurityAccount();
      } else {
        if (panelSecurity) panelSecurity.classList.add('hidden');
        if (panelGeneral) panelGeneral.classList.remove('hidden');
        loadSettings();
      }
    });
  }

  function bindSecurityForm() {
    const form = $('#form-change-credentials');
    if (!form || form.dataset.bound === '1') return;
    form.dataset.bound = '1';

    bindPasswordToggles(form);

    const pwInput = $('#sec-new-password');
    const pwContainer = $('#sec-strength');
    const pwFill = $('#sec-strength-fill');
    const pwLabel = $('#sec-strength-label');
    const pwScore = $('#sec-strength-score');
    bindPasswordStrength(pwInput, pwContainer, pwFill, pwLabel, pwScore);

    const confirmInput = $('#sec-confirm-password');
    const confirmError = $('#sec-confirm-error');

    function checkConfirmMatch() {
      if (!confirmInput || !confirmError) return;
      const a = pwInput ? pwInput.value : '';
      const b = confirmInput.value;
      if (b && a !== b) {
        confirmError.classList.remove('hidden');
        confirmInput.classList.add('input-error');
      } else {
        confirmError.classList.add('hidden');
        confirmInput.classList.remove('input-error');
      }
    }
    if (pwInput) pwInput.addEventListener('input', checkConfirmMatch);
    if (confirmInput) confirmInput.addEventListener('input', checkConfirmMatch);

    const resetBtn = $('#sec-reset-btn');
    if (resetBtn && !resetBtn.dataset.bound) {
      resetBtn.dataset.bound = '1';
      resetBtn.addEventListener('click', () => {
        form.reset();
        if (confirmError) confirmError.classList.add('hidden');
        if (confirmInput) confirmInput.classList.remove('input-error');
        if (pwContainer) pwContainer.dataset.level = '0';
        if (pwFill) pwFill.style.width = '0%';
        if (pwLabel) pwLabel.textContent = 'قدرت رمز عبور';
        if (pwScore) pwScore.textContent = '';
      });
    }

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const newUsername = ($('#sec-new-username').value || '').trim();
      const currentPassword = $('#sec-current-password').value;
      const newPassword = pwInput ? pwInput.value : '';
      const confirmPassword = confirmInput ? confirmInput.value : '';

      if (!newUsername && !newPassword) {
        Toast.warning('حداقل یکی از فیلدهای نام کاربری یا رمز عبور جدید را وارد کنید.');
        return;
      }
      if (!currentPassword) { Toast.warning('رمز عبور فعلی را وارد کنید.'); return; }

      if (newUsername) {
        if (newUsername.length < 4) { Toast.warning('نام کاربری باید حداقل ۴ کاراکتر باشد.'); return; }
        if (!/^[A-Za-z0-9_.\-]+$/.test(newUsername)) {
          Toast.warning('نام کاربری فقط می‌تواند شامل حروف انگلیسی، عدد، نقطه، خط تیره و زیرخط باشد.');
          return;
        }
      }

      if (newPassword) {
        if (newPassword.length < 8) { Toast.warning('رمز عبور باید حداقل ۸ کاراکتر باشد.'); return; }
        const strength = calculatePasswordStrength(newPassword);
        if (strength.score < 2) {
          Toast.warning('رمز عبور انتخاب‌شده بسیار ضعیف است.');
          return;
        }
        if (newPassword !== confirmPassword) {
          if (confirmError) confirmError.classList.remove('hidden');
          Toast.warning('رمز عبور جدید و تکرار آن یکسان نیستند.');
          return;
        }
      }

      Modal.confirm({
        title: 'تأیید تغییر اطلاعات ورود',
        message: 'اطلاعات ورود حساب مدیر تغییر خواهد کرد.\nآیا مطمئن هستید؟',
        confirmText: 'تأیید و ذخیره',
        danger: false,
        onConfirm: async () => {
          const btn = $('#sec-save-btn');
          await withButtonLoading(btn, async () => {
            try {
              const payload = { current_password: currentPassword };
              if (newUsername) payload.new_username = newUsername;
              if (newPassword) {
                payload.new_password = newPassword;
                payload.confirm_password = confirmPassword;
              }
              const data = await API.post('/api/admin/change-credentials', payload);
              if (data && data.csrf_token) API.setCsrf(data.csrf_token);
              if (State.currentUser && data && data.username) State.currentUser.username = data.username;
              Toast.success('اطلاعات حساب با موفقیت به‌روزرسانی شد.');
              form.reset();
              if (confirmError) confirmError.classList.add('hidden');
              if (pwContainer) pwContainer.dataset.level = '0';
              if (pwFill) pwFill.style.width = '0%';
              if (pwLabel) pwLabel.textContent = 'قدرت رمز عبور';
              if (pwScore) pwScore.textContent = '';
              await loadSecurityAccount();
            } catch (err) {
              Toast.error(err.message || 'خطا در تغییر اطلاعات حساب');
            }
          });
        },
      });
    });
  }

  async function loadSecurityAccount() {
    try {
      const acc = await API.get('/api/admin/account');
      setText($('#sec-current-username'), (acc && acc.username) || '—');
    } catch (e) {
      setText($('#sec-current-username'), '—');
      Toast.error(e.message || 'خطا در بارگذاری اطلاعات حساب');
    }
  }

  function openDrawer() {
    const sb = $('#admin-sidebar');
    if (sb) sb.classList.add('is-open');
    const bd = $('#sidebar-backdrop');
    if (bd) bd.classList.remove('hidden');
    const dt = $('#admin-drawer-toggle');
    if (dt) dt.setAttribute('aria-expanded', 'true');
  }
  function closeDrawer() {
    const sb = $('#admin-sidebar');
    if (sb) sb.classList.remove('is-open');
    const bd = $('#sidebar-backdrop');
    if (bd) bd.classList.add('hidden');
    const dt = $('#admin-drawer-toggle');
    if (dt) dt.setAttribute('aria-expanded', 'false');
  }

  function switchSection(name) {
    State.admin.section = name;
    $$('.admin-section').forEach(s => s.classList.add('hidden'));
    const target = $(`#section-${name}`);
    if (target) target.classList.remove('hidden');
    $$('.sn-item').forEach(b => b.classList.toggle('is-active', b.dataset.adminSection === name));

    const loaders = {
      dashboard: loadDashboard,
      requests: () => loadOrders(1),
      customers: () => loadCustomers(1),
      menu: loadProducts,
      capacity: loadCapacity,
      downloads: onDownloadsInit,
      settings: () => {
        State.admin.settingsTab = 'general';
        const tabsRoot = $('#settings-tabs');
        if (tabsRoot) {
          $$('.tab', tabsRoot).forEach(t => {
            const active = t.dataset.settingsTab === 'general';
            t.classList.toggle('is-active', active);
            t.setAttribute('aria-selected', String(active));
          });
        }
        const panelGeneral = $('#settings-panel-general');
        const panelSecurity = $('#settings-panel-security');
        if (panelGeneral) panelGeneral.classList.remove('hidden');
        if (panelSecurity) panelSecurity.classList.add('hidden');
        loadSettings();
      },
      cleanup: onCleanupInit,
    };
    if (loaders[name]) loaders[name]();
  }

  async function loadDashboard() {
    try {
      const d = await API.get('/api/admin/dashboard');
      setText($('#kpi-pending'), toFa(d.pending));
      setText($('#kpi-approved'), toFa(d.approved_today));
      setText($('#kpi-rejected'), toFa(d.rejected_today));
      setText($('#kpi-customers'), toFa(d.active_customers));
      setText($('#kpi-products'), toFa(d.active_products));
      setText($('#kpi-window'), d.ordering_allowed ? 'فعال' : 'غیرفعال');
      setText($('#dashboard-sub'), `امروز — ${formatServerDate(d.today)}`);
      const badge = $('#sidebar-pending-badge');
      if (badge) {
        if (d.pending > 0) {
          badge.textContent = toFa(d.pending);
          badge.classList.remove('hidden');
        } else {
          badge.classList.add('hidden');
        }
      }
    } catch (e) {
      Toast.error(e.message || 'خطا در بارگذاری داشبورد');
    }
  }

  async function loadOrders(page = 1) {
    const container = $('#admin-orders-container');
    if (!container) return;
    State.admin.ordersPage = page;
    container.innerHTML = '';
    container.appendChild(skeletonList(4));
    try {
      const params = new URLSearchParams();
      if (State.admin.orderFilter.status) params.set('status', State.admin.orderFilter.status);
      if (State.admin.orderFilter.date)   params.set('date',   State.admin.orderFilter.date);
      params.set('page', String(page));
      const qs = params.toString();
      const data = await API.get('/api/admin/orders' + (qs ? `?${qs}` : ''));
      State.admin.orders = (data && data.orders) || [];
      renderOrders();
      renderPagination('admin-orders-pagination', data && data.pagination, loadOrders);
    } catch (e) {
      container.innerHTML = '';
      container.appendChild(emptyState('⚠', 'خطا در بارگذاری درخواست‌ها', e.message || ''));
    }
  }

  function renderOrders() {
    const container = $('#admin-orders-container');
    if (!container) return;
    const list = State.admin.orders;
    container.innerHTML = '';
    if (!list.length) {
      container.appendChild(emptyState('🍋', 'درخواستی برای نمایش نیست', 'در حال حاضر سفارشی برای بررسی وجود ندارد.'));
      return;
    }
    for (const o of list) container.appendChild(renderRequestCard(o));
  }

  function renderRequestCard(o) {
    const card = el('div', { class: 'request-card' });
    const ts = parseServerTimestamp(o.created_at);
    card.appendChild(el('div', { class: 'rc-head' }, [
      el('div', {}, [
        el('div', { class: 'rc-company', text: o.company_name }),
        el('div', { class: 'rc-code', text: `کد: ${o.customer_code}` }),
      ]),
      el('span', { class: `badge ${statusClass(o.status)}`, text: faStatus(o.status) }),
    ]));
    card.appendChild(el('div', { class: 'rc-meta' }, [
      el('span', { text: `تاریخ: ${formatServerDate(o.order_date)}` }),
      el('span', { text: `زمان: ${ts.time}` }),
      el('span', { text: `${toFa(o.items_count)} نوع — ${toFa(o.total_qty)} عدد` }),
    ]));
    const actions = el('div', { class: 'rc-actions' });
    actions.appendChild(el('button', {
      class: 'btn btn-ghost btn-sm', type: 'button', text: 'مشاهده جزئیات',
      onclick: () => openRequestDetail(o.id),
    }));
    if (o.status === 'PENDING') {
      actions.appendChild(el('button', {
        class: 'btn btn-primary btn-sm', type: 'button', text: 'تأیید',
        onclick: () => approveOrder(o.id),
      }));
      actions.appendChild(el('button', {
        class: 'btn btn-danger-ghost btn-sm', type: 'button', text: 'رد',
        onclick: () => openRejectDialog(o.id),
      }));
    }
    card.appendChild(actions);
    return card;
  }

  async function openRequestDetail(id) {
    try {
      Loading.show();
      const o = await API.get(`/api/admin/orders/${id}`);
      Loading.hide();
      const c = o.customer || {};
      const ts = parseServerTimestamp(o.created_at);
      const body = el('div', { class: 'order-detail' });
      body.append(
        el('div', { class: 'od-row' }, [el('span', { text: 'شرکت' }), el('strong', { text: c.company_name || '—' })]),
        el('div', { class: 'od-row' }, [el('span', { text: 'کد مشتری' }), el('span', { text: c.customer_code || '—' })]),
        el('div', { class: 'od-row' }, [el('span', { text: 'تلفن' }), el('span', { text: c.phone || '—' })]),
        el('div', { class: 'od-row' }, [el('span', { text: 'آدرس' }), el('span', { text: c.address || '—' })]),
        el('div', { class: 'od-row' }, [el('span', { text: 'تاریخ' }), el('span', { text: formatServerDate(o.order_date) })]),
        el('div', { class: 'od-row' }, [el('span', { text: 'زمان' }), el('span', { text: ts.time })]),
        el('div', { class: 'od-row' }, [el('span', { text: 'وضعیت' }), el('span', { class: `badge ${statusClass(o.status)}`, text: faStatus(o.status) })]),
        el('div', { class: 'od-sep' }),
        el('h4', { text: 'اقلام' }),
        el('div', { class: 'od-items' }, (o.items || []).map(it =>
          el('div', { class: 'od-item' }, [
            el('span', { text: it.variant_name ? `${it.product_name} — ${it.variant_name}` : it.product_name }),
            el('strong', { text: `× ${toFa(it.quantity)}` }),
          ])
        )),
      );
      if (o.status === 'REJECTED' && o.rejection_reason) {
        body.appendChild(el('div', { class: 'oc-reason' }, [
          el('strong', { text: 'دلیل رد: ' }),
          el('span', { text: o.rejection_reason }),
        ]));
      }
      const footer = el('div', { class: 'modal-actions' });
      if (o.status === 'PENDING') {
        footer.appendChild(el('button', {
          class: 'btn btn-primary', type: 'button', text: 'تأیید سفارش',
          onclick: async () => { Modal.close(); await approveOrder(o.id); },
        }));
        footer.appendChild(el('button', {
          class: 'btn btn-danger-ghost', type: 'button', text: 'رد سفارش',
          onclick: () => { Modal.close(); openRejectDialog(o.id); },
        }));
      }
      footer.appendChild(el('button', { class: 'btn btn-ghost', type: 'button', text: 'بستن', onclick: Modal.close }));
      Modal.open({ title: `سفارش #${toFa(o.id)}`, body, footer });
    } catch (e) {
      Loading.hide();
      Toast.error(e.message || 'خطا در بارگذاری');
    }
  }

  async function approveOrder(id) {
    try {
      await API.post(`/api/admin/orders/${id}/approve`);
      Toast.success('سفارش تأیید شد.');
      await loadOrders(State.admin.ordersPage);
      loadDashboard();
    } catch (e) {
      Toast.error(e.message || 'خطا در تأیید سفارش');
    }
  }

  function openRejectDialog(id) {
    const uniqueId = `reject-reason-${Date.now()}`;
    const body = el('div', {}, [
      el('p', { text: 'دلیل رد سفارش (اختیاری):' }),
      el('textarea', { class: 'input', rows: '3', id: uniqueId, placeholder: 'مثال: امکان تأمین کامل سفارش وجود ندارد.' }),
    ]);
    const footer = el('div', { class: 'modal-actions' }, [
      el('button', { class: 'btn btn-ghost', type: 'button', onclick: Modal.close, text: 'انصراف' }),
      el('button', {
        class: 'btn btn-danger', type: 'button', text: 'رد سفارش',
        onclick: async (e) => {
          const btn = e.currentTarget;
          const reasonEl = document.getElementById(uniqueId);
          const reason = reasonEl ? reasonEl.value.trim() : '';
          await withButtonLoading(btn, async () => {
            try {
              await API.post(`/api/admin/orders/${id}/reject`, { reason });
              Modal.close();
              Toast.success('سفارش رد شد.');
              await loadOrders(State.admin.ordersPage);
              loadDashboard();
            } catch (err) {
              Toast.error(err.message || 'خطا در رد سفارش');
            }
          });
        },
      }),
    ]);
    Modal.open({ title: 'رد سفارش', body, footer });
  }

  async function loadCustomers(page = 1) {
    const container = $('#admin-customers-container');
    if (!container) return;
    State.admin.customersPage = page;
    container.innerHTML = '';
    container.appendChild(skeletonList(4));
    try {
      const searchEl = $('#search-customers');
      const q = searchEl ? (searchEl.value || '').trim() : '';
      const params = new URLSearchParams();
      if (q) params.set('q', q);
      params.set('page', String(page));
      const data = await API.get('/api/admin/customers?' + params.toString());
      State.admin.customers = (data && data.customers) || [];
      renderCustomers();
      renderPagination('admin-customers-pagination', data && data.pagination, loadCustomers);
    } catch (e) {
      container.innerHTML = '';
      container.appendChild(emptyState('⚠', 'خطا در بارگذاری مشتریان', e.message || ''));
    }
  }

  function renderCustomers() {
    const container = $('#admin-customers-container');
    if (!container) return;
    const list = State.admin.customers;
    container.innerHTML = '';
    if (!list.length) {
      container.appendChild(emptyState('🍋', 'مشتری‌ای ثبت نشده', 'برای شروع، اولین مشتری را اضافه کنید.'));
      return;
    }
    for (const c of list) {
      const card = el('div', { class: 'customer-card' + (c.active ? '' : ' is-inactive') });
      card.append(
        el('div', { class: 'cc-head' }, [
          el('div', { class: 'cc-company', text: c.company_name }),
          el('span', { class: `badge ${c.active ? 'badge-ok' : 'badge-neutral'}`, text: c.active ? 'فعال' : 'غیرفعال' }),
        ]),
        el('div', { class: 'cc-row' }, [el('span', { text: 'کد: ' }), el('strong', { text: c.customer_code })]),
        c.phone ? el('div', { class: 'cc-row' }, [el('span', { text: 'تلفن: ' }), el('span', { text: c.phone })]) : null,
        c.address ? el('div', { class: 'cc-row cc-addr' }, [el('span', { text: c.address })]) : null,
      );
      const actions = el('div', { class: 'cc-actions' });
      actions.appendChild(el('button', {
        class: 'btn btn-ghost btn-sm', type: 'button', text: 'ویرایش',
        onclick: () => openCustomerForm(c),
      }));
      if (c.active) {
        actions.appendChild(el('button', {
          class: 'btn btn-danger-ghost btn-sm', type: 'button', text: 'غیرفعال',
          onclick: () => {
            Modal.confirm({
              title: 'غیرفعال کردن مشتری',
              message: `آیا از غیرفعال کردن «${c.company_name}» مطمئن هستید؟`,
              danger: true, confirmText: 'غیرفعال کن',
              onConfirm: async () => {
                try {
                  await API.del(`/api/admin/customers/${c.id}`);
                  Toast.success('مشتری غیرفعال شد.');
                  loadCustomers(State.admin.customersPage);
                } catch (e) { Toast.error(e.message || 'خطا'); }
              },
            });
          },
        }));
      }
      card.appendChild(actions);
      container.appendChild(card);
    }
  }

  function openCustomerForm(c) {
    const isEdit = !!c;
    const body = el('div', { class: 'form-grid' }, [
      fieldText('c-company', 'نام شرکت', c?.company_name || '', true),
      fieldText('c-code', 'کد مشتری', c?.customer_code || '', true, 'LIMO-1001'),
      fieldText('c-phone', 'تلفن', c?.phone || '', false),
      fieldText('c-address', 'آدرس', c?.address || '', false),
    ]);
    const footer = el('div', { class: 'modal-actions' }, [
      el('button', { class: 'btn btn-ghost', type: 'button', onclick: Modal.close, text: 'انصراف' }),
      el('button', {
        class: 'btn btn-primary', type: 'button', text: isEdit ? 'ذخیره تغییرات' : 'ایجاد مشتری',
        onclick: async (e) => {
          const btn = e.currentTarget;
          const payload = {
            company_name: $('#c-company').value.trim(),
            customer_code: $('#c-code').value.trim(),
            phone: $('#c-phone').value.trim(),
            address: $('#c-address').value.trim(),
          };
          if (!payload.company_name) { Toast.warning('نام شرکت الزامی است.'); return; }
          if (!payload.customer_code) { Toast.warning('کد مشتری الزامی است.'); return; }
          await withButtonLoading(btn, async () => {
            try {
              if (isEdit) await API.put(`/api/admin/customers/${c.id}`, payload);
              else await API.post('/api/admin/customers', payload);
              Toast.success(isEdit ? 'مشتری ویرایش شد.' : 'مشتری ایجاد شد.');
              Modal.close();
              loadCustomers(State.admin.customersPage);
            } catch (err) {
              Toast.error(err.message || 'خطا در ذخیره');
            }
          });
        },
      }),
    ]);
    Modal.open({ title: isEdit ? 'ویرایش مشتری' : 'افزودن مشتری', body, footer });
  }

  async function loadProducts() {
    const container = $('#admin-products-container');
    if (!container) return;
    container.innerHTML = '';
    container.appendChild(skeletonList(3));
    try {
      const data = await API.get('/api/admin/products');
      State.admin.products = (data && data.products) || [];
      renderProducts();
    } catch (e) {
      container.innerHTML = '';
      container.appendChild(emptyState('⚠', 'خطا در بارگذاری محصولات', e.message || ''));
    }
  }

  function renderProducts() {
    const container = $('#admin-products-container');
    if (!container) return;
    const cat = State.admin.menuCategory;
    const list = (State.admin.products || []).filter(p => cat === 'all' || p.category === cat);
    container.innerHTML = '';
    if (!list.length) {
      container.appendChild(emptyState('🍋', 'محصولی برای نمایش نیست', 'برای شروع، اولین محصول را اضافه کنید.'));
      return;
    }
    for (const p of list) container.appendChild(renderProductAdminCard(p));
  }

  function renderProductAdminCard(p) {
    const card = el('div', { class: 'product-admin-card' + (p.active ? '' : ' is-inactive') });
    card.append(
      el('div', { class: 'pac-head' }, [
        el('div', {}, [
          el('div', { class: 'pac-name', text: p.name }),
          el('div', { class: 'pac-cat', text: faCategory(p.category) }),
        ]),
        el('span', { class: `badge ${p.active ? 'badge-ok' : 'badge-neutral'}`, text: p.active ? 'فعال' : 'غیرفعال' }),
      ]),
      el('div', { class: 'pac-row' }, [el('span', { text: 'ظرفیت پایه: ' }), el('strong', { text: toFa(p.base_capacity) })]),
      el('div', { class: 'pac-row' }, [el('span', { text: 'روزهای فعال: ' }), el('span', { text: faDays(p.available_days) })]),
    );
    if (p.variants && p.variants.length) {
      card.appendChild(el('div', { class: 'pac-variants' }, p.variants.map(v =>
        el('div', { class: 'pac-variant' }, [
          el('span', { text: v.name }),
          el('span', { text: `${toFa(v.base_capacity)} نفر` }),
          el('span', { class: `badge ${v.active ? 'badge-ok' : 'badge-neutral'} badge-xs`, text: v.active ? 'فعال' : 'غیرفعال' }),
        ])
      )));
    }
    const actions = el('div', { class: 'pac-actions' });
    actions.appendChild(el('button', { class: 'btn btn-ghost btn-sm', type: 'button', text: 'ویرایش', onclick: () => openProductForm(p) }));
    actions.appendChild(el('button', { class: 'btn btn-ghost btn-sm', type: 'button', text: 'Variant‌ها', onclick: () => openVariantsManager(p) }));
    if (p.active) {
      actions.appendChild(el('button', {
        class: 'btn btn-danger-ghost btn-sm', type: 'button', text: 'غیرفعال',
        onclick: () => {
          Modal.confirm({
            title: 'غیرفعال کردن محصول',
            message: `آیا از غیرفعال کردن «${p.name}» مطمئن هستید؟`,
            danger: true, confirmText: 'غیرفعال کن',
            onConfirm: async () => {
              try {
                await API.del(`/api/admin/products/${p.id}`);
                Toast.success('غیرفعال شد.');
                loadProducts();
              } catch (e) { Toast.error(e.message || 'خطا'); }
            },
          });
        },
      }));
    }
    card.appendChild(actions);
    return card;
  }

  function openProductForm(p) {
    const isEdit = !!p;

    const currentDaysSet = new Set();
    if (p?.available_days) {
      String(p.available_days).split(',').forEach(s => {
        const n = +s;
        if (Number.isFinite(n) && n >= 0 && n <= 6) currentDaysSet.add(n);
      });
    } else {
      [0,1,2,3,4,5].forEach(d => currentDaysSet.add(d));
    }

    const dayLabels = ['شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه','جمعه'];
    const days = [0,1,2,3,4,5];
    const showFriday = isEdit && currentDaysSet.has(6);
    if (showFriday) days.push(6);

    const body = el('div', { class: 'form-grid' }, [
      fieldText('p-name', 'نام محصول', p?.name || '', true),
      fieldSelect('p-category', 'دسته‌بندی', [
        { v: 'food', t: 'غذا' },
        { v: 'drink', t: 'نوشیدنی' },
        { v: 'dessert', t: 'دسر' },
      ], p?.category || 'food'),
      fieldNumber('p-capacity', 'ظرفیت پایه', p?.base_capacity ?? 0, true),
      fieldText('p-description', 'توضیحات', p?.description || '', false),
      el('div', { class: 'field' }, [
        el('label', { class: 'field-label', text: 'روزهای فعال' }),
        el('div', { class: 'days-picker', id: 'p-days' }, days.map(d =>
          el('label', { class: 'day-chip' + (currentDaysSet.has(d) ? ' is-checked' : '') }, [
            el('input', { type: 'checkbox', value: d, ...(currentDaysSet.has(d) ? { checked: 'checked' } : {}) }),
            el('span', { text: dayLabels[d] }),
          ])
        )),
        el('p', { class: 'field-hint', text: 'جمعه روز سفارش‌گیری نیست.' }),
      ]),
      el('label', { class: 'check-row' }, [
        el('input', { type: 'checkbox', id: 'p-active', ...((p?.active ?? 1) ? { checked: 'checked' } : {}) }),
        el('span', { text: 'فعال' }),
      ]),
    ]);
    const footer = el('div', { class: 'modal-actions' }, [
      el('button', { class: 'btn btn-ghost', type: 'button', onclick: Modal.close, text: 'انصراف' }),
      el('button', {
        class: 'btn btn-primary', type: 'button', text: isEdit ? 'ذخیره' : 'ایجاد',
        onclick: async (e) => {
          const btn = e.currentTarget;
          const name = $('#p-name').value.trim();
          const category = $('#p-category').value;
          const base_capacity = +$('#p-capacity').value || 0;
          const description = $('#p-description').value.trim();
          const active = $('#p-active').checked;
          const daysSel = $$('#p-days input:checked').map(i => +i.value);
          if (!name) { Toast.warning('نام محصول الزامی است.'); return; }
          if (!daysSel.length) { Toast.warning('حداقل یک روز فعال انتخاب کنید.'); return; }
          const payload = { name, category, description, base_capacity, active, available_days: daysSel };
          await withButtonLoading(btn, async () => {
            try {
              if (isEdit) await API.put(`/api/admin/products/${p.id}`, payload);
              else await API.post('/api/admin/products', payload);
              Toast.success(isEdit ? 'محصول ویرایش شد.' : 'محصول ایجاد شد.');
              Modal.close();
              loadProducts();
            } catch (err) { Toast.error(err.message || 'خطا در ذخیره'); }
          });
        },
      }),
    ]);
    Modal.open({ title: isEdit ? 'ویرایش محصول' : 'افزودن محصول', body, footer });

    setTimeout(() => {
      $$('#p-days input[type="checkbox"]').forEach(cb => {
        const chip = cb.closest('.day-chip');
        if (!chip) return;
        chip.classList.toggle('is-checked', cb.checked);
        cb.addEventListener('change', () => {
          chip.classList.toggle('is-checked', cb.checked);
        });
      });
    }, 160);
  }

  function openVariantsManager(p) {
    const renderBody = () => {
      const wrap = el('div', {});
      wrap.appendChild(el('p', { class: 'field-hint', text: 'Variant‌ها برای محصولاتی مثل نوشیدنی که اندازه‌های مختلف دارند استفاده می‌شوند.' }));
      const list = el('div', { class: 'variants-list' });
      for (const v of (p.variants || [])) {
        list.appendChild(el('div', { class: 'variant-item' }, [
          el('div', {}, [
            el('strong', { text: v.name }),
            el('div', { class: 'field-hint', text: `ظرفیت: ${toFa(v.base_capacity)}` }),
          ]),
          el('span', { class: `badge ${v.active ? 'badge-ok' : 'badge-neutral'}`, text: v.active ? 'فعال' : 'غیرفعال' }),
          el('button', { class: 'btn btn-ghost btn-sm', type: 'button', text: 'ویرایش', onclick: () => openVariantForm(p, v) }),
        ]));
      }
      if (!p.variants || !p.variants.length) {
        list.appendChild(el('p', { class: 'field-hint', text: 'هنوز Variant‌ای تعریف نشده است.' }));
      }
      wrap.appendChild(list);
      return wrap;
    };
    const footer = el('div', { class: 'modal-actions' }, [
      el('button', { class: 'btn btn-ghost', type: 'button', onclick: Modal.close, text: 'بستن' }),
      el('button', { class: 'btn btn-primary', type: 'button', text: 'افزودن Variant جدید', onclick: () => openVariantForm(p, null) }),
    ]);
    Modal.open({ title: `Variant‌های ${p.name}`, body: renderBody(), footer });
  }

  function openVariantForm(p, v) {
    const isEdit = !!v;
    const body = el('div', { class: 'form-grid' }, [
      fieldText('v-name', 'نام Variant', v?.name || '', true, 'مثال: کوچک / خانواده'),
      fieldText('v-description', 'توضیحات', v?.description || '', false),
      fieldNumber('v-capacity', 'ظرفیت پایه', v?.base_capacity ?? 0, true),
      el('label', { class: 'check-row' }, [
        el('input', { type: 'checkbox', id: 'v-active', ...((v?.active ?? 1) ? { checked: 'checked' } : {}) }),
        el('span', { text: 'فعال' }),
      ]),
    ]);
    const footer = el('div', { class: 'modal-actions' }, [
      el('button', { class: 'btn btn-ghost', type: 'button', onclick: Modal.close, text: 'انصراف' }),
      el('button', {
        class: 'btn btn-primary', type: 'button', text: isEdit ? 'ذخیره' : 'ایجاد',
        onclick: async (e) => {
          const btn = e.currentTarget;
          const name = $('#v-name').value.trim();
          if (!name) { Toast.warning('نام الزامی است.'); return; }
          const payload = {
            name,
            description: $('#v-description').value.trim(),
            base_capacity: +$('#v-capacity').value || 0,
            active: $('#v-active').checked,
          };
          await withButtonLoading(btn, async () => {
            try {
              if (isEdit) await API.put(`/api/admin/variants/${v.id}`, payload);
              else await API.post(`/api/admin/products/${p.id}/variants`, payload);
              Toast.success(isEdit ? 'ذخیره شد.' : 'Variant ایجاد شد.');
              Modal.close();
              await loadProducts();
            } catch (err) { Toast.error(err.message || 'خطا'); }
          });
        },
      }),
    ]);
    Modal.open({ title: isEdit ? 'ویرایش Variant' : 'افزودن Variant', body, footer });
  }

  async function loadCapacity() {
    const container = $('#admin-capacity-container');
    if (!container) return;
    container.innerHTML = '';
    container.appendChild(skeletonList(3));
    try {
      const data = await API.get('/api/admin/capacity');
      State.admin.capacity = (data && data.items) || [];
      setText($('#capacity-sub'), `وضعیت ظرفیت امروز — ${formatServerDate(data && data.date)}`);
      renderCapacity();
    } catch (e) {
      container.innerHTML = '';
      container.appendChild(emptyState('⚠', 'خطا در بارگذاری ظرفیت‌ها', e.message || ''));
    }
  }

  /**
   * FIX-18: با Number() امن
   */
  function renderCapacity() {
    const container = $('#admin-capacity-container');
    if (!container) return;
    container.innerHTML = '';
    const list = State.admin.capacity;
    if (!list.length) {
      container.appendChild(emptyState('🍋', 'محصولی برای نمایش نیست', ''));
      return;
    }
    for (const item of list) {
      const card = el('div', { class: 'capacity-card' });
      card.appendChild(el('div', { class: 'cap-head' }, [
        el('div', { class: 'cap-name', text: item.name }),
        el('span', { class: 'cap-cat', text: faCategory(item.category) }),
      ]));
      if (item.has_variants) {
        for (const v of item.variants) {
          card.appendChild(renderCapacityBar(
            `${v.name} — ${toFa(v.base_capacity)} پایه`,
            Number(v.base_capacity) || 0,
            Number(v.used) || 0
          ));
        }
      } else {
        card.appendChild(renderCapacityBar(
          '',
          Number(item.base_capacity) || 0,
          Number(item.used) || 0
        ));
      }
      container.appendChild(card);
    }
  }

  function renderCapacityBar(label, base, used) {
    const avail = Math.max(0, base - used);
    const percent = base > 0 ? Math.min(100, Math.round((used / base) * 100)) : 0;
    const wrap = el('div', { class: 'cap-bar-wrap' });
    if (label) wrap.appendChild(el('div', { class: 'cap-bar-label', text: label }));
    wrap.appendChild(el('div', { class: 'cap-bar-stats' }, [
      el('span', { text: `پایه: ${toFa(base)}` }),
      el('span', { text: `استفاده: ${toFa(used)}` }),
      el('span', { class: avail > 0 ? 'ok' : 'danger', text: `باقی: ${toFa(avail)}` }),
    ]));
    const fillStyle = `width:${percent}%;` +
      (percent >= 90 ? 'background:var(--danger)' : percent >= 70 ? 'background:var(--warn)' : '');
    wrap.appendChild(el('div', { class: 'cap-bar' }, [
      el('div', { class: 'cap-bar-fill', style: fillStyle }),
    ]));
    return wrap;
  }

  function onDownloadsInit() {
    const dateInput = $('#report-date');
    if (dateInput && !dateInput.value) {
      const d = new Date();
      const pad = (n) => String(n).padStart(2, '0');
      dateInput.value = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    }
  }

  async function loadSettings() {
    const container = $('#settings-card');
    if (!container) return;
    container.innerHTML = '';
    try {
      const s = await API.get('/api/admin/settings');
      State.admin.settings = s;
      const body = el('div', { class: 'form-grid' }, [
        el('h3', { text: 'تنظیمات سفارش‌گیری' }),
        fieldText('s-start', 'شروع سفارش‌گیری', s.ordering_start || '07:30', true, 'HH:MM'),
        fieldText('s-end', 'پایان سفارش‌گیری', s.ordering_end || '12:00', true, 'HH:MM'),
        el('h3', { text: 'تنظیمات عمومی' }),
        fieldText('s-site', 'نام سایت', s.site_name || 'رستوران لیمو', false),
        fieldText('s-tz', 'Timezone', s.timezone || 'Asia/Tehran', false),
      ]);
      container.appendChild(body);
      container.appendChild(el('button', {
        class: 'btn btn-primary', type: 'button', text: 'ذخیره تنظیمات',
        onclick: async (e) => {
          const btn = e.currentTarget;
          const payload = {
            ordering_start: $('#s-start').value.trim(),
            ordering_end: $('#s-end').value.trim(),
            site_name: $('#s-site').value.trim(),
            timezone: $('#s-tz').value.trim(),
          };
          await withButtonLoading(btn, async () => {
            try {
              await API.put('/api/admin/settings', payload);
              Toast.success('تنظیمات ذخیره شد.');
              loadSettings();
            } catch (err) { Toast.error(err.message || 'خطا'); }
          });
        },
      }));
    } catch (e) {
      container.appendChild(emptyState('⚠', 'خطا در بارگذاری تنظیمات', e.message || ''));
    }
  }

  function onCleanupInit() {
    const input = $('#cleanup-before-date');
    if (input && !input.value) {
      const d = new Date();
      d.setMonth(d.getMonth() - 3);
      const pad = (n) => String(n).padStart(2, '0');
      input.value = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    }
    const pwInput = $('#cleanup-current-password');
    if (pwInput) pwInput.value = '';
  }

  async function onCleanup() {
    const date = $('#cleanup-before-date').value;
    const currentPassword = ($('#cleanup-current-password')?.value || '').trim();
    if (!date) { Toast.warning('تاریخ مرجع را وارد کنید.'); return; }
    if (!currentPassword) { Toast.warning('برای تأیید، رمز عبور فعلی را وارد کنید.'); return; }

    Modal.confirm({
      title: 'تأیید پاک‌سازی داده‌ها',
      message: `این عملیات اطلاعات تاریخی قبل از ${date} را حذف می‌کند.\nاطلاعات اصلی مشتریان و منو حذف نخواهد شد.\nقبل از حذف، یک Backup ذخیره خواهد شد.`,
      danger: true,
      confirmText: 'تأیید پاک‌سازی',
      onConfirm: async () => {
        Loading.show();
        try {
          const res = await API.post('/api/admin/cleanup', {
            before_date: date,
            current_password: currentPassword,
          });
          Loading.hide();
          Toast.success((res && res.message) || 'پاک‌سازی انجام شد.');
          if (res && res.backup) Toast.info(`Backup: ${res.backup}`, 5000);
          const pwInput = $('#cleanup-current-password');
          if (pwInput) pwInput.value = '';
          loadDashboard();
        } catch (e) {
          Loading.hide();
          Toast.error(e.message || 'خطا در پاک‌سازی');
        }
      },
    });
  }

  return { init };
})();

/* ============================================================================
   SECTION: Reusable UI Components
   ============================================================================ */
function fieldText(id, label, value = '', required = false, placeholder = '') {
  return el('div', { class: 'field' }, [
    el('label', { class: 'field-label', for: id, text: label + (required ? ' *' : '') }),
    el('input', { id, class: 'input', type: 'text', value, ...(placeholder ? { placeholder } : {}) }),
  ]);
}

function fieldNumber(id, label, value = 0, required = false) {
  return el('div', { class: 'field' }, [
    el('label', { class: 'field-label', for: id, text: label + (required ? ' *' : '') }),
    el('input', { id, class: 'input', type: 'number', value, min: '0', inputmode: 'numeric' }),
  ]);
}

/**
 * FIX-16: استفاده از property selected به‌جای attribute
 */
function fieldSelect(id, label, options, value) {
  const sel = el('select', { id, class: 'input' });
  for (const o of options) {
    const opt = el('option', { value: o.v, text: o.t });
    if (o.v === value) opt.selected = true;
    sel.appendChild(opt);
  }
  if (!options.some(o => o.v === value) && options.length > 0) {
    sel.value = options[0].v;
  }
  return el('div', { class: 'field' }, [
    el('label', { class: 'field-label', for: id, text: label }),
    sel,
  ]);
}

function emptyState(emoji, title, sub) {
  return el('div', { class: 'empty-state' }, [
    el('div', { class: 'empty-emoji', 'aria-hidden': 'true', text: emoji }),
    el('h3', { text: title }),
    sub ? el('p', { text: sub }) : null,
  ]);
}

function skeletonList(n) {
  const wrap = el('div', { class: 'skeleton-list' });
  for (let i = 0; i < n; i++) {
    wrap.appendChild(el('div', { class: 'skeleton-card' }, [
      el('div', { class: 'sk-line sk-line-lg' }),
      el('div', { class: 'sk-line' }),
      el('div', { class: 'sk-line sk-line-sm' }),
    ]));
  }
  return wrap;
}

/* ============================================================================
   SECTION: Auth Forms
   ============================================================================ */
function bindAuthForms() {
  const custForm = $('#form-customer-login');
  if (custForm && !custForm.dataset.bound) {
    custForm.dataset.bound = '1';

    function normalizeIranMobile(raw) {
      let s = String(raw || '').trim();
      s = s.replace(/[\s\-().]/g, '');
      s = s.replace(/[۰-۹]/g, d => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)))
           .replace(/[٠-٩]/g, d => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)));
      if (!/^\+?\d+$/.test(s)) return null;
      if (s.startsWith('+98') && s.length === 13) s = '0' + s.slice(3);
      else if (s.startsWith('98') && s.length === 12) s = '0' + s.slice(2);
      else if (s.length === 10 && s[0] === '9') s = '0' + s;
      if (s.length !== 11 || !s.startsWith('09')) return null;
      return s;
    }

    custForm.addEventListener('submit', async (e) => {
      e.preventDefault();

      const phoneEl = $('#customer-phone-input');
      const codeEl  = $('#customer-code-input');
      const phoneHint = $('#customer-phone-hint');
      const codeHint  = $('#customer-login-hint');

      const rawPhone = phoneEl ? phoneEl.value.trim() : '';
      const code = codeEl ? codeEl.value.trim() : '';

      if (phoneHint) phoneHint.textContent = '';
      if (codeHint)  codeHint.textContent = '';
      if (phoneEl) phoneEl.classList.remove('input-error');
      if (codeEl)  codeEl.classList.remove('input-error');

      if (!rawPhone) {
        if (phoneHint) phoneHint.textContent = 'شماره موبایل الزامی است.';
        if (phoneEl) phoneEl.classList.add('input-error');
        Toast.warning('شماره موبایل را وارد کنید.');
        return;
      }
      const phone = normalizeIranMobile(rawPhone);
      if (!phone) {
        if (phoneHint) phoneHint.textContent = 'شماره موبایل معتبر نیست. (مثال: 09121234567)';
        if (phoneEl) phoneEl.classList.add('input-error');
        Toast.warning('شماره موبایل معتبر نیست.');
        return;
      }
      if (!code) {
        if (codeHint) codeHint.textContent = 'کد مشتری الزامی است.';
        if (codeEl) codeEl.classList.add('input-error');
        Toast.warning('کد مشتری را وارد کنید.');
        return;
      }

      const btn = $('#customer-login-btn');
      await withButtonLoading(btn, async () => {
        try {
          const user = await API.post('/api/auth/customer/login', {
            customer_code: code,
            phone: phone,
          });
          if (user && user.csrf_token) API.setCsrf(user.csrf_token);
          State.userType = 'customer';
          State.currentUser = user || {};
          State.mustChangeCredentials = false;
          Toast.success(`خوش آمدید، ${(user && user.company_name) || ''}`);
          showView('customerApp');
          await CustomerApp.init();
        } catch (err) {
          Toast.error(err.message || 'ورود ناموفق');
        }
      });
    });
  }

  const adminForm = $('#form-admin-login');
  if (adminForm && !adminForm.dataset.bound) {
    adminForm.dataset.bound = '1';
    adminForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const username = $('#admin-username').value.trim();
      const password = $('#admin-password').value;
      if (!username || !password) { Toast.warning('نام کاربری و رمز عبور را وارد کنید.'); return; }
      const btn = $('#admin-login-btn');
      await withButtonLoading(btn, async () => {
        try {
          const user = await API.post('/api/auth/admin/login', { username, password });
          if (user && user.csrf_token) API.setCsrf(user.csrf_token);
          State.userType = 'admin';
          State.currentUser = user || {};
          State.mustChangeCredentials = !!(user && user.must_change_credentials);
          if (State.mustChangeCredentials) {
            Toast.warning('برای افزایش امنیت، لطفاً اطلاعات ورود پیش‌فرض را تغییر دهید.');
            showView('forceChange');
            bindForceChangeForm();
            return;
          }
          Toast.success('ورود موفق به پنل مدیریت');
          showView('adminApp');
          await AdminApp.init();
        } catch (err) {
          Toast.error(err.message || 'ورود ناموفق');
        }
      });
    });
  }
}

/* ============================================================================
   SECTION: Bootstrap
   ============================================================================ */
document.addEventListener('DOMContentLoaded', async () => {
  bindAuthForms();
  bindPasswordToggles(document);
  await routeBySession();
});