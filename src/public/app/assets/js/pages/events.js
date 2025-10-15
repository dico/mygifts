// src/public/app/assets/js/pages/events.js
import { render } from '../view.js';
import { api } from '../Remote.js';
import { on } from '../eventBus.js';
import { registerModalMini } from '../modalMini.js';   // <- endret navn

let offChanged = null;
let unbindClicks = null;
let unbindModals = null;

function bindActions(rootEl) {
  const handler = async (e) => {
    const delBtn = e.target.closest('[data-delete="event"]');
    if (delBtn && rootEl.contains(delBtn)) {
      const id = delBtn.getAttribute('data-id');
      if (!id) return;
      if (!confirm('Delete this event?')) return;
      try {
        await api(`/api/events/${id}`, { method: 'DELETE' });
        await load(); // reload list
      } catch (err) {
        console.error('[events] delete error', err);
        alert('Failed to delete event.');
      }
      return;
    }
  };
  rootEl.addEventListener('click', handler);
  return () => rootEl.removeEventListener('click', handler);
}

async function load() {
  await render('events', {
    title: 'Gift Lists',
    intro: 'Manage your events.',
    loading: true,
    events: []
  });

  try {
    const res = await api('/api/events');
    const events = res?.data?.events || [];
    await render('events', {
      title: 'Gift Lists',
      intro: 'Manage your events.',
      loading: false,
      events
    });

    const rootEl = document.getElementById('app');
    if (unbindClicks) { try { unbindClicks(); } catch {} }
    unbindClicks = bindActions(rootEl);

    // (Re)bind generiske modaler på nytt DOM-tre
    if (unbindModals) { try { unbindModals(); } catch {} }
    unbindModals = registerModalMini(rootEl);   // <- endret funksjon

  } catch (err) {
    console.error('[events] load error', err);
    await render('events', {
      title: 'Gift Lists',
      intro: 'Failed to load.',
      loading: false,
      events: []
    });
  }
}

export async function mount() {
  await load();
  offChanged = on('entity:changed', (p) => {
    if (p?.entity === 'event') load();
  });
}

export function unmount() {
  offChanged?.(); offChanged = null;
  unbindClicks?.(); unbindClicks = null;
  unbindModals?.(); unbindModals = null;
}
