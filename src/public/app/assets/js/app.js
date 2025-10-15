// src/public/app/assets/js/app.js
import { api } from './Remote.js';
import { setupRoutes, startRouter, requestRouteRefresh } from './routes.js';
import { initModalLinks } from './modalLinks.js';

const qs = (s) => document.querySelector(s);

function normalizePath(p) {
  try { return new URL(p, window.location.origin).pathname.replace(/\/+$/, ''); }
  catch { return String(p || '').replace(/\/+$/, ''); }
}

window.addEventListener('error', (e) => {
  console.error('[global error]', e.message, e.error || '');
});
window.addEventListener('unhandledrejection', (e) => {
  console.error('[unhandledrejection]', e.reason || e);
});

function setActiveNav() {
  const path = normalizePath(location.pathname);
  console.log('[nav] setActiveNav for path:', path);
  document.querySelectorAll('#spaNav .nav-link').forEach(a => {
    const href = normalizePath(a.getAttribute('href'));
    const active = (path === href) || path.startsWith(href + '/');
    if (active) console.log('[nav] active link:', href);
    a.classList.toggle('active', active);
  });
}

export async function initApp() {
  console.log('[app] initApp start');
  const $loading = qs('#loading');
  const $app = qs('#app');
  const $error = qs('#errorBox');

  const showError = (msg, status) => {
    $error.textContent = status ? `${msg} (HTTP ${status})` : msg;
    $error.classList.remove('d-none');
  };
  const hideError = () => {
    console.log('[app] hideError');
    $error.classList.add('d-none');
  };

  try {
    hideError();
    console.log('[api] GET /api/auth/me');

    const watchdog = setTimeout(() => {
      console.warn('[app] /api/auth/me still pending after 6000ms');
      const note = document.createElement('div');
      note.className = 'text-center text-muted mt-3';
      note.textContent = 'Still loading profile… If this takes long, try refreshing.';
      $loading.appendChild(note);
    }, 6000);

    const res = await api('/api/auth/me');

    clearTimeout(watchdog);
    console.log('[api] /api/auth/me OK:', res);

    if (res?.data?.needs_setup) {
      location.replace('/app/onboarding.html');
      return;
    }

    const email = res?.data?.email || '';
    qs('#userLabel').textContent = email || 'Signed in';
    qs('#ddEmail').textContent = email || '—';

    $loading.style.display = 'none';
    $app.style.display = '';

    // Router
    setupRoutes();
    console.log('[router] startRouter()');
    startRouter();

    // Global modals (product/user/event) – once
    initModalLinks();

    // Active nav on startup + on history changes
    setActiveNav();
    window.addEventListener('popstate', setActiveNav);

    // Intercept internal /app links (SPA-nav)
    document.addEventListener('click', (e) => {
      const a = e.target.closest('a.nav-link');
      if (!a) return;

      const href = a.getAttribute('href');
      console.log(
        '[nav] click on', href,
        'shiftKey=', e.shiftKey, 'metaKey=', e.metaKey, 'ctrlKey=', e.ctrlKey
      );

      if (!href || !href.startsWith('/app')) return;
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

      e.preventDefault();

      const to = normalizePath(href);
      const cur = normalizePath(location.pathname);

      if (to === cur) {
        console.log('[nav] same path -> requestRouteRefresh()');
        requestRouteRefresh();
      } else {
        console.log('[nav] navigate -> page.show:', href);
        window.page.show(href);
      }

      // Let page.js update history, then re-highlight
      setTimeout(() => {
        console.log('[nav] post-navigation setActiveNav()');
        setActiveNav();
      }, 0);
    });

    // Safer DnD: don’t drop files globally
    window.addEventListener('dragover', (e) => e.preventDefault());
    window.addEventListener('drop', (e) => {
      if (!e.target.closest?.('[data-dropzone]')) e.preventDefault();
    });

  } catch (e) {
    console.error('[app] initApp ERROR', e);
    $loading.style.display = 'none';
    showError(e?.message || 'Could not load /auth/me', e?.status);
    if ((e.status === 401) || !localStorage.getItem('access_token')) {
      const ret = encodeURIComponent(location.pathname + location.search + location.hash);
      setTimeout(() => location.replace('/login/?return=' + ret), 800);
    }
  }
}
