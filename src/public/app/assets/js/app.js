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

  // Update old navbar links (if they exist)
  document.querySelectorAll('#spaNav .nav-link').forEach(a => {
    const href = normalizePath(a.getAttribute('href'));

    // Special case: /app should only be active for exact match (home page)
    // Other routes should match exact OR child paths
    const active = (href === '/app')
      ? (path === href)
      : ((path === href) || path.startsWith(href + '/'));

    if (active) console.log('[nav] active link:', href);
    a.classList.toggle('active', active);
  });

  // Update bottom nav tabs
  document.querySelectorAll('.ios-bottom-nav .nav-tab').forEach(tab => {
    const href = normalizePath(tab.getAttribute('href'));

    // Special case: /app should only be active for exact match (home page)
    // Other routes should match exact OR child paths
    const active = (href === '/app')
      ? (path === href)
      : ((path === href) || path.startsWith(href + '/'));

    tab.classList.toggle('active', active);
  });
}

// Set seasonal wallpaper based on current month
function setSeasonalWallpaper() {
  const month = new Date().getMonth(); // 0 = January, 11 = December
  const body = document.querySelector('.ios-app');

  // Remove all season classes
  body.classList.remove('season-spring', 'season-summer', 'season-autumn', 'season-winter');

  // Determine season
  let season;
  if (month >= 2 && month <= 4) {
    season = 'spring'; // March, April, May
  } else if (month >= 5 && month <= 7) {
    season = 'summer'; // June, July, August
  } else if (month >= 8 && month <= 10) {
    season = 'autumn'; // September, October, November
  } else {
    season = 'winter'; // December, January, February
  }

  body.classList.add(`season-${season}`);
  console.log('[wallpaper] Set season:', season);
}

export async function initApp() {
  console.log('[app] initApp start');

  // Set seasonal wallpaper
  setSeasonalWallpaper();

  // Register service worker for PWA functionality
  if ('serviceWorker' in navigator) {
    try {
      await navigator.serviceWorker.register('/sw.js');
      console.log('[app] Service Worker registered');
    } catch (error) {
      console.warn('[app] Service Worker registration failed:', error);
    }
  }

  const $loading = qs('#loading');
  const $app = qs('#app');
  const $error = qs('#errorBox');

  const showError = (msg, status, options = {}) => {
    $error.innerHTML = ''; // Clear existing content
    $error.className = 'alert alert-danger text-center'; // Center the content

    // Add message
    const msgDiv = document.createElement('div');
    msgDiv.className = 'mb-3 fs-5';
    msgDiv.textContent = msg; // No status in main message
    $error.appendChild(msgDiv);

    // Add retry button if requested
    if (options.showRetry) {
      const btnContainer = document.createElement('div');
      btnContainer.className = 'd-flex flex-column flex-sm-row gap-3 justify-content-center align-items-center';

      const retryBtn = document.createElement('button');
      retryBtn.className = 'btn btn-primary btn-lg';
      retryBtn.innerHTML = '<i class="fa-solid fa-rotate-right me-2"></i>Try Again';
      retryBtn.onclick = () => {
        // Clear all auth data and force fresh login
        localStorage.removeItem('login_blocked');
        localStorage.removeItem('access_token');
        localStorage.removeItem('refresh_token');
        localStorage.removeItem('id_token');
        sessionStorage.removeItem('auth_redirect_count');
        sessionStorage.removeItem('just_logged_in');

        // Redirect to login to get fresh token
        const ret = encodeURIComponent(location.pathname + location.search + location.hash);
        location.replace('/login/?return=' + ret);
      };

      const logoutBtn = document.createElement('button');
      logoutBtn.className = 'btn btn-outline-light btn-lg';
      logoutBtn.innerHTML = '<i class="fa-solid fa-right-from-bracket me-2"></i>Log Out';
      logoutBtn.onclick = () => {
        // Clear block flag and redirect to logout (which handles Keycloak logout)
        localStorage.removeItem('login_blocked');
        sessionStorage.removeItem('auth_redirect_count');
        sessionStorage.removeItem('just_logged_in');
        location.replace('/logout/');
      };

      btnContainer.appendChild(retryBtn);
      btnContainer.appendChild(logoutBtn);
      $error.appendChild(btnContainer);
    }

    // Add debug info at bottom if status is provided
    if (status && options.showDebug) {
      const debugDiv = document.createElement('div');
      debugDiv.className = 'text-muted small mt-3';
      debugDiv.textContent = `Debug: HTTP ${status}`;
      $error.appendChild(debugDiv);
    }

    $error.classList.remove('d-none');

    // Hide navigation if requested
    if (options.hideNav) {
      const topNav = document.querySelector('.navbar');
      const bottomNav = document.querySelector('.ios-bottom-nav');
      if (topNav) topNav.style.display = 'none';
      if (bottomNav) bottomNav.style.display = 'none';
    }
  };
  const hideError = () => {
    console.log('[app] hideError');
    $error.classList.add('d-none');
  };

  try {
    // CRITICAL: Check for persistent login block flag FIRST
    const loginBlockFlag = localStorage.getItem('login_blocked');
    if (loginBlockFlag) {
      const blockTime = parseInt(loginBlockFlag, 10);
      const timeSinceBlock = Date.now() - blockTime;
      // Keep block active for 30 seconds
      if (timeSinceBlock < 30000) {
        console.error('[app] Login is BLOCKED due to previous loop detection');
        $loading.style.display = 'none';
        showError(
          'You do not have access to this application yet.\n\n' +
          'You need to be added to a household first. Please contact your administrator, then click "Try Again" below.',
          401,
          { showRetry: true, hideNav: true, showDebug: true }
        );
        return;
      } else {
        // Block expired, clear it
        localStorage.removeItem('login_blocked');
      }
    }

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

    // Clear redirect counter and login flag on successful auth
    sessionStorage.removeItem('auth_redirect_count');
    sessionStorage.removeItem('just_logged_in');
    localStorage.removeItem('login_blocked');

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

    // Intercept internal /app links (SPA-nav) - works for both old nav and bottom nav
    document.addEventListener('click', (e) => {
      const a = e.target.closest('a.nav-link, a.nav-tab');
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

    // Check if we just came from login callback (within last 10 seconds)
    const justLoggedIn = sessionStorage.getItem('just_logged_in');
    const timeSinceLogin = justLoggedIn ? Date.now() - parseInt(justLoggedIn, 10) : null;
    const recentLogin = justLoggedIn && timeSinceLogin < 10000;

    // Check redirect loop counter
    const redirectKey = 'auth_redirect_count';
    const redirectCount = parseInt(sessionStorage.getItem(redirectKey) || '0', 10);

    console.log('[app] Auth error debug:', {
      status: e.status,
      justLoggedIn,
      timeSinceLogin,
      recentLogin,
      redirectCount,
      hasAccessToken: !!localStorage.getItem('access_token')
    });

    if ((e.status === 401) || !localStorage.getItem('access_token')) {
      // If we just came from login OR already redirected too many times, show error instead
      if (recentLogin || redirectCount >= 3) {
        sessionStorage.removeItem(redirectKey);
        sessionStorage.removeItem('just_logged_in');

        // Set persistent block flag in localStorage to prevent any further redirects
        localStorage.setItem('login_blocked', Date.now().toString());

        showError(
          'You do not have access to this application yet.\n\n' +
          'You need to be added to a household first. Please contact your administrator, then click "Try Again" below.',
          e?.status,
          { showRetry: true, hideNav: true, showDebug: true }
        );
        console.error('[app] BLOCKING REDIRECT - Login loop detected. recentLogin=' + recentLogin + ', redirectCount=' + redirectCount);
        console.error('[app] Set login_blocked flag in localStorage to prevent further redirects');
        // EXPLICITLY RETURN to prevent any further execution
        return;
      } else {
        // Increment redirect counter and redirect to login
        sessionStorage.setItem(redirectKey, String(redirectCount + 1));
        const ret = encodeURIComponent(location.pathname + location.search + location.hash);
        console.log('[app] Redirecting to login (attempt ' + (redirectCount + 1) + ')');
        setTimeout(() => location.replace('/login/?return=' + ret), 800);
      }
    } else {
      showError(e?.message || 'Could not load /auth/me', e?.status);
    }
  }
}
