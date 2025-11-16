// src/public/app/assets/js/pages/templates.js
import { render } from '../view.js';
import { api } from '../Remote.js';
import { on } from '../eventBus.js';

let offChanged = null;
let unbind = null;

async function load() {
  await render('templates', { loading: true, templates: [] });

  try {
    const res = await api('/api/gift-templates');
    const templates = res?.data?.templates || res?.templates || [];
    await render('templates', { loading: false, templates });

    const rootEl = document.getElementById('app');
    if (unbind) { try { unbind(); } catch {} }
    unbind = bindActions(rootEl);
  } catch (err) {
    console.error('[templates] load error', err);
    await render('templates', { loading: false, templates: [] });
  }
}

function bindActions(rootEl) {
  const handler = async (e) => {
    const delBtn = e.target.closest('[data-delete="template"]');
    if (delBtn && rootEl.contains(delBtn)) {
      const id = delBtn.getAttribute('data-id');
      if (!id) return;
      if (!confirm('Delete this template? All relationships in this template will be lost.')) return;
      try {
        await api(`/api/gift-templates/${id}`, { method: 'DELETE' });
        await load();
      } catch (err) {
        console.error('[templates] delete error', err);
        alert('Failed to delete template.');
      }
      return;
    }
  };
  rootEl.addEventListener('click', handler);
  return () => rootEl.removeEventListener('click', handler);
}

export async function mount() {
  document.title = 'Gift Templates · MyGifts';
  await load();

  offChanged = on('entity:changed', (p) => {
    if (p?.entity === 'template') load();
  });
}

export function unmount() {
  offChanged?.(); offChanged = null;
  unbind?.(); unbind = null;
}
