// src/public/app/assets/js/pages/dashboard.js
import { render } from '../view.js';

let offRefresh = null;

export async function mount() {
  await render('dashboard', {
    title: 'Gift Lists',
    intro: 'Your gift lists will appear here.'
  });

  offRefresh = (() => {
    const handler = (e) => { if (e?.detail?.key === 'dashboard') mount(); };
    window.addEventListener('route:refresh', handler);
    return () => window.removeEventListener('route:refresh', handler);
  })();
}

export function unmount() {
  offRefresh?.();
  offRefresh = null;
}
