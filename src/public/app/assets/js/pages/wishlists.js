// src/public/app/assets/js/pages/wishlists.js
import { render } from '../view.js';

let offRefresh = null;

export async function mount() {
  await render('wishlists', {
    title: 'Wishlists',
    intro: 'Browse and edit wishlists.'
  });

  offRefresh = (() => {
    const handler = (e) => { if (e?.detail?.key === 'wishlists') mount(); };
    window.addEventListener('route:refresh', handler);
    return () => window.removeEventListener('route:refresh', handler);
  })();
}

export function unmount() {
  offRefresh?.();
  offRefresh = null;
}
