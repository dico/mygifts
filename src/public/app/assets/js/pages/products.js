// src/public/app/assets/js/pages/products.js
import { api } from '../Remote.js';
import { render } from '../view.js';
import { on } from '../eventBus.js';

let unbind = null;
let offChanged = null;
let currentQ = '';
let debounceTimer = null;

export async function mount() {
  document.title = 'Products · MyGifts';
  await render('products', { title: 'Products', loading: true, q: currentQ, products: [] });
  await load(currentQ);

  const root = document.getElementById('app');

  // Søk
  const onInput = (e) => {
    currentQ = (e?.target?.value ?? '').trim();
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => load(currentQ), 200);
  };
  const sEl = document.getElementById('prodSearch');
  if (sEl) sEl.addEventListener('input', onInput);

  // Sletting
  const onClick = async (e) => {
    const delBtn = e.target.closest('[data-delete="product"]');
    if (delBtn && root.contains(delBtn)) {
      const id = delBtn.getAttribute('data-id');
      if (!id) return;
      if (!confirm('Delete this product?')) return;
      try {
        await api(`/api/products/${id}`, { method: 'DELETE' });
        await load(currentQ);
      } catch (err) {
        console.error('[products] delete error', err);
        alert('Failed to delete product.');
      }
    }
  };
  root.addEventListener('click', onClick);

  // Refresh ved create/update/upload/delete via modaler
  offChanged = on('entity:changed', (p) => {
    if (p?.entity === 'product') load(currentQ);
  });

  unbind = () => {
    if (sEl) sEl.removeEventListener('input', onInput);
    root.removeEventListener('click', onClick);
  };
}

export function unmount() {
  if (unbind) { try { unbind(); } catch {} }
  if (offChanged) { try { offChanged(); } catch {} }
  unbind = null;
  offChanged = null;
}

async function load(q = '') {
  try {
    const params = new URLSearchParams();
    if (q) params.set('q', q);
    params.set('limit', '50');

    const url = `/api/products?${params.toString()}`;
    const res = await api(url);
    const products = res?.data?.products || res?.products || [];
    await render('products', { title: 'Products', loading: false, q, products });
  } catch (e) {
    console.error('[products] load failed', e);
    await render('products', { title: 'Products', loading: false, q, products: [] });
  }
}
