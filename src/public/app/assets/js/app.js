// src/public/app/assets/js/app.js
import { api } from './Remote.js';
import { setupRoutes, startRouter, requestRouteRefresh } from './routes.js';
import { initModalLinks } from './modalLinks.js';

const qs = (s) => document.querySelector(s);

window.addEventListener('error', (e) => {
  console.error('[global error]', e.message, e.error || '');
});
window.addEventListener('unhandledrejection', (e) => {
  console.error('[unhandledrejection]', e.reason || e);
});

function setActiveNav() {
  const path = location.pathname;
  console.log('[nav] setActiveNav for path:', path);
  document.querySelectorAll('#spaNav .nav-link').forEach(a => {
    const href = a.getAttribute('href');
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

	// 🚀 Globale modal-lenker (produkt/bruker/event) – én gang
  	initModalLinks();

    // Aktiv nav ved oppstart + ved historieendringer
    setActiveNav();
    window.addEventListener('popstate', setActiveNav);

    // Intercept interne /app-lenker (SPA-nav)
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

      // Hvis samme path: re-render via refresh-event
      if (location.pathname === href) {
        console.log('[nav] same path -> requestRouteRefresh()');
        requestRouteRefresh();
      } else {
        console.log('[nav] navigate -> page.show:', href);
        window.page.show(href);
      }

      setTimeout(() => {
        console.log('[nav] post-navigation setActiveNav()');
        setActiveNav();
      }, 0);
    });

    // Sikrere DnD: ikke dropp filer på siden globalt
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
