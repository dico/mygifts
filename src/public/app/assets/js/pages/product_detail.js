// src/public/app/assets/js/pages/product_detail.js
import { api } from '../Remote.js';
import { render } from '../view.js';

let productIdRef = null;

export async function mount(productId) {
  productIdRef = productId;
  document.title = 'Product · MyGifts';
  await render('product_detail', { loading: true, product: null, items: [] });

  try {
    const [pr, hist] = await Promise.all([
      api(`/api/products/${encodeURIComponent(productId)}`),
      api(`/api/products/${encodeURIComponent(productId)}/gift-items`)
    ]);

    const product = pr?.data || pr;
    const items = hist?.data?.items || hist?.items || [];
    await render('product_detail', { loading: false, product, items });
  } catch (e) {
    console.error('[product_detail] load failed', e);
    await render('product_detail', { loading: false, product: null, items: [] });
  }
}

export function unmount() {
  productIdRef = null;
}
