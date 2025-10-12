// src/public/app/assets/js/Remote.js
const AT_KEY = 'access_token';
const RT_KEY = 'refresh_token';
const IDT_KEY = 'id_token';

// ---------- helpers ----------
const getAT = () => localStorage.getItem(AT_KEY);
const getRT = () => localStorage.getItem(RT_KEY);

function saveTokens(tokens) {
  if (tokens?.access_token)  localStorage.setItem(AT_KEY, tokens.access_token);
  if (tokens?.refresh_token) localStorage.setItem(RT_KEY, tokens.refresh_token);
  if (tokens?.id_token)      localStorage.setItem(IDT_KEY, tokens.id_token);
}

function authHeaders(extra = {}) {
  const headers = { 'Content-Type': 'application/json', ...extra };
  const token = getAT();
  if (token) headers['Authorization'] = `Bearer ${token}`;
  return headers;
}

async function parseJsonSafe(res) {
  try { return await res.json(); } catch { return null; }
}

function raiseHttp(res, json) {
  const msg = json?.message || `HTTP ${res.status}`;
  const err = new Error(msg);
  err.status = res.status;
  err.response = json;
  throw err;
}

// ---------- proactive refresh ----------
let refreshTimer;
function scheduleProactiveRefresh(accessToken) {
  try {
    const parts = accessToken.split('.');
    if (parts.length < 2) return;
    const payload = JSON.parse(atob(parts[1].replace(/-/g, '+').replace(/_/g, '/')));
    const expMs = (payload?.exp ?? 0) * 1000;
    // forny ~60 sek før utløp (med 5s minimum for sikkerhet)
    const skew = 60_000;
    const delay = Math.max(5_000, expMs - Date.now() - skew);
    if (refreshTimer) clearTimeout(refreshTimer);
    refreshTimer = setTimeout(() => {
      // stille refresh – ignorer feil
      refreshOnce().catch(() => {});
    }, delay);
  } catch {
    // ignorer parsing-feil
  }
}

// ---------- refresh flow ----------
async function refreshOnce() {
  const rt = getRT();
  if (!rt) return false;

  const r = await fetch('/api/auth/refresh', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ refresh_token: rt })
  });

  const data = await parseJsonSafe(r);
  const tokens = data?.data?.tokens || data?.tokens || data?.data;

  if (!r.ok || !tokens?.access_token) return false;

  saveTokens(tokens);
  scheduleProactiveRefresh(tokens.access_token);
  return true;
}

function redirectToLoginPreserveReturn() {
  const ret = encodeURIComponent(location.pathname + location.search + location.hash);
  location.replace('/login/?return=' + ret);
}

// ---------- core request wrappers ----------
async function handle(res) {
  const json = await parseJsonSafe(res);
  if (!res.ok) raiseHttp(res, json);
  // Start/oppdater proaktiv timer når et kall lykkes
  const at = getAT();
  if (at) scheduleProactiveRefresh(at);
  return json ?? {};
}

export async function api(url, opts = {}) {
  const { method = 'GET', body = undefined, headers = {} } = opts;
  const init = { method, headers: authHeaders(headers) };
  if (body !== undefined) init.body = typeof body === 'string' ? body : JSON.stringify(body);

  // Første forsøk
  let res = await fetch(url, init);
  if (res.status !== 401) return handle(res);

  // 401 → prøv refresh én gang
  const ok = await refreshOnce();
  if (!ok) {
    redirectToLoginPreserveReturn();
    const err = new Error('Unauthorized');
    err.status = 401;
    throw err;
  }

  // retry samme request med ny AT
  res = await fetch(url, { ...init, headers: authHeaders(headers) });
  if (res.status === 401) {
    redirectToLoginPreserveReturn();
    const err = new Error('Unauthorized');
    err.status = 401;
    throw err;
  }
  return handle(res);
}

export async function apiForm(url, formData, opts = {}) {
  const { headers = {} } = opts;
  const token = getAT();
  let res = await fetch(url, {
    method: 'POST',
    headers: token ? { 'Authorization': `Bearer ${token}`, ...headers } : headers,
    body: formData
  });

  if (res.status !== 401) return handle(res);

  // 401 → prøv refresh
  const ok = await refreshOnce();
  if (!ok) {
    redirectToLoginPreserveReturn();
    const err = new Error('Unauthorized');
    err.status = 401;
    throw err;
  }

  // retry
  const newToken = getAT();
  res = await fetch(url, {
    method: 'POST',
    headers: newToken ? { 'Authorization': `Bearer ${newToken}`, ...headers } : headers,
    body: formData
  });

  if (res.status === 401) {
    redirectToLoginPreserveReturn();
    const err = new Error('Unauthorized');
    err.status = 401;
    throw err;
  }
  return handle(res);
}
