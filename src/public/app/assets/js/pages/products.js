// src/public/app/assets/js/pages/products.js
import { api } from '../Remote.js';
import { render } from '../view.js';

let unbind = null;
let currentQ = '';

export async function mount() {
  document.title = 'Products · MyGifts';
  await render('products', { title: 'Products', loading: true, q: currentQ, products: [] });
  await load(currentQ);

  // bind search
  const root = document.getElementById('app');
  const onInput = (e) => {
    currentQ = e.target.value.trim();
    load(currentQ);
  };
  root.addEventListener('input', onInput);
  unbind = () => root.removeEventListener('input', onInput);
}

export function unmount() {
  if (unbind) { try { unbind(); } catch {} }
  unbind = null;
}

async function load(q = '') {
  try {
    const res = await api(`/api/products${q ? `?q=${encodeURIComponent(q)}` : ''}&limit=50`);
    const products = res?.data?.products || res?.products || [];
    await render('products', { title: 'Products', loading: false, q, products });
  } catch (e) {
    await render('products', { title: 'Products', loading: false, q, products: [] });
    console.error('[products] load failed', e);
  }
}
