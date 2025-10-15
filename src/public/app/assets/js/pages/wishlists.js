// src/public/app/assets/js/pages/wishlists.js
import { render } from '../view.js';
import { api } from '../Remote.js';
import { on } from '../eventBus.js';

let offChanged = null;
let unbind = null;

function bindActions(rootEl) {
  const handler = async (e) => {
    const delBtn = e.target.closest('[data-delete="wishlist"]');
    if (delBtn && rootEl.contains(delBtn)) {
      const id = delBtn.getAttribute('data-id');
      if (!id) return;
      if (!confirm('Delete this wish?')) return;
      try {
        await api(`/api/wishlists/${id}`, { method: 'DELETE' });
        await load();
      } catch (err) {
        console.error('[wishlists] delete error', err);
        alert('Failed to delete wish.');
      }
      return;
    }
  };
  rootEl.addEventListener('click', handler);
  return () => rootEl.removeEventListener('click', handler);
}

async function load() {
  await render('wishlists', { title: 'Wishlists', intro: 'Browse and edit wishlists.', loading: true });

  try {
    const res = await api('/api/wishlists?include_empty=1');
    const groups = (res?.data?.wishlists || res?.wishlists || []).map(g => ({
      user: g.user,
      items: g.items || []
    }));

    await render('wishlists', { title: 'Wishlists', intro: 'Browse and edit wishlists.', loading: false, groups });

    const rootEl = document.getElementById('app');

    if (unbind) { try { unbind(); } catch {} }
    unbind = bindActions(rootEl);

  } catch (err) {
    console.error('[wishlists] load error', err);
    await render('wishlists', { title: 'Wishlists', intro: 'Failed to load.', loading: false, groups: [] });
  }
}

export async function mount() {
  await load();

  offChanged = on('entity:changed', (p) => {
    if (p?.entity === 'wishlist' || p?.entity === 'product') load();
  });
}

export function unmount() {
  offChanged?.(); offChanged = null;
  unbind?.(); unbind = null;
}
