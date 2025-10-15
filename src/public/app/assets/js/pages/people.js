// src/public/app/assets/js/pages/people.js
import { render } from '../view.js';
import { api } from '../Remote.js';
import { on } from '../eventBus.js';

let offChanged = null;
let unbindDelete = null;

function bindDeleteActions(rootEl, reloadFn) {
  const handler = async (e) => {
    const delBtn = e.target.closest('[data-delete="user"]');
    if (!delBtn || !rootEl.contains(delBtn)) return;

    const id = delBtn.getAttribute('data-id');
    if (!id) return;

    if (!confirm('Remove this user from the household?')) return;

    try {
      await api(`/api/users/${id}`, { method: 'DELETE' });
      await reloadFn();
    } catch (err) {
      console.error('[people] delete error', err);
      alert('Failed to delete user.');
    }
  };

  rootEl.addEventListener('click', handler);
  return () => rootEl.removeEventListener('click', handler);
}

export async function mount() {
  const load = async () => {
    await render('people', {
      title: 'People',
      intro: 'Manage members in your household.',
      users: [],
      loading: true
    });

    try {
      const res = await api('/api/users');
      const users = res?.data?.users || [];

      await render('people', {
        title: 'People',
        intro: 'Manage members in your household.',
        users,
        loading: false
      });

      const rootEl = document.getElementById('app');

      if (typeof unbindDelete === 'function') { try { unbindDelete(); } catch {} }
      unbindDelete = bindDeleteActions(rootEl, load);

    } catch (err) {
      console.error('[people] load ERROR', err);
      await render('people', {
        title: 'People',
        intro: 'Failed to load members.',
        users: [],
        loading: false
      });
    }
  };

  await load();

  // Reload listen når en user er opprettet/oppdatert
  offChanged = on('entity:changed', (p) => {
    if (!p) return;
    if (p.entity === 'user') {
      load();
    }
  });
}

export function unmount() {
  offChanged?.(); offChanged = null;
  if (typeof unbindDelete === 'function') { try { unbindDelete(); } catch {} }
  unbindDelete = null;
}
