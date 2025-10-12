// src/public/app/assets/js/routes.js
// Minimal router som delegerer alt per side til assets/js/pages/<page>.js
// Hver side-modul skal eksportere: async function mount(...args) og optional unmount()

// Enkel cleanup for hver rute
let currentCleanup = null;
let currentRouteKey = null;

function setCleanup(fn, key) {
  if (typeof currentCleanup === 'function') {
    try { currentCleanup(); } catch (e) { console.warn('[router] previous cleanup error', e); }
  }
  currentCleanup = fn || null;
  currentRouteKey = key || null;
}



export function setupRoutes() {
  const p = window.page;

  p('/app', async () => {
    const mod = await import('/app/assets/js/pages/dashboard.js');
    await mod.mount();
    setCleanup(() => mod.unmount?.(), 'dashboard');
  });

  p('/app/events', async () => {
    const mod = await import('/app/assets/js/pages/events.js');
    await mod.mount();
    setCleanup(() => mod.unmount?.(), 'events');
  });

  p('/app/events/:id', async (ctx) => {
    const mod = await import('/app/assets/js/pages/event_detail.js');
    await mod.mount(ctx.params.id);
    setCleanup(() => mod.unmount?.(), 'event-detail');
  });

  p('/app/people', async () => {
    const mod = await import('/app/assets/js/pages/people.js');
    await mod.mount();
    setCleanup(() => mod.unmount?.(), 'people');
  });

  p('/app/wishlists', async () => {
    const mod = await import('/app/assets/js/pages/wishlists.js');
    await mod.mount();
    setCleanup(() => mod.unmount?.(), 'wishlists');
  });

  p('/app/products', async () => {
    const mod = await import('/app/assets/js/pages/products.js');
    await mod.mount();
    setCleanup(() => mod.unmount?.(), 'products');
  });

  p('/app/products/:id', async (ctx) => {
    const mod = await import('/app/assets/js/pages/product_detail.js');
    await mod.mount(ctx.params.id);
    setCleanup(() => mod.unmount?.(), 'product-detail');
  });
}





export function startRouter() {
  console.log('[router] page.start()');
  window.page.start({ dispatch: true });
}

export function requestRouteRefresh() {
  if (!currentRouteKey) return;
  window.dispatchEvent(new CustomEvent('route:refresh', { detail: { key: currentRouteKey } }));
}
