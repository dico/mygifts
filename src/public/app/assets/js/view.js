// src/public/app/assets/js/view.js
const $app = document.getElementById('app');
const cache = new Map();

async function fetchTemplate(name) {
  if (cache.has(name)) {
    console.log('[view] cache hit for', name);
    return cache.get(name);
  }
  const url = `/app/templates/${name}.jsr`;
  console.log('[view] fetching template:', url);

  let res;
  try {
    const bust = `_=${Date.now()}`;
    const link = url + (url.includes('?') ? '&' : '?') + bust;
    res = await fetch(link, { cache: 'no-cache' });
  } catch (e) {
    console.error('[view] fetch failed for', url, e);
    throw new Error(`Network error while fetching template: ${name}`);
  }

  if (!res.ok) {
    const txt = await res.text().catch(() => '');
    console.error(`[view] HTTP ${res.status} for ${url}`, txt);
    throw new Error(`Failed to fetch template: ${name} (HTTP ${res.status})`);
  }

  const text = await res.text();
  cache.set(name, text);
  return text;
}

export async function render(name, data = {}) {
  $app.innerHTML = `
    <div class="text-center my-5">
      <div class="spinner-border" role="status"></div>
      <div class="text-muted mt-2">Loading ${name}…</div>
    </div>
  `;
  try {
    if (!window.jsrender || !window.jsrender.templates) {
      console.error('[view] jsrender global not found');
      throw new Error('Template engine (jsrender) is not available');
    }

    const tpl = await fetchTemplate(name);
    const tmpl = window.jsrender.templates(tpl);
    const html = tmpl.render(data);
    $app.innerHTML = html;
    console.log('[view] rendered', name);
  } catch (err) {
    console.error('[view] render error for', name, err);
    $app.innerHTML = `
      <div class="alert alert-danger mt-4" role="alert">
        Could not render <strong>${name}</strong>: ${err?.message || 'Unknown error'}
      </div>
    `;
  }
}

// expose to page modules
window.appView = { render };
