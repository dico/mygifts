// src/public/app/assets/js/pages/people.js
import { render } from '../view.js';
import { api } from '../Remote.js';
import { on } from '../eventBus.js';

let offChanged = null;

/**
 * Gjør om gamle knapper (data-open="user") til nye data-modal="open"
 * slik at modalHub tar over. Bruker "users"-array for å pre-fylle edit-skjema.
 */
function upgradeUserButtons(rootEl, users = []) {
  const byId = new Map(users.map(u => [u.id, u]));

  // Ny bruker-knapp (ingen data-id)
  rootEl.querySelectorAll('button[data-open="user"]:not([data-id])').forEach(btn => {
    btn.removeAttribute('data-open');

    btn.setAttribute('data-modal', 'open');
    btn.setAttribute('data-modal-template', 'modals/modal_user_form');
    btn.setAttribute('data-modal-title', 'New user');
    btn.setAttribute('data-modal-action', '/api/users');
    btn.setAttribute('data-modal-method', 'POST');
    btn.setAttribute('data-modal-form-id', 'userForm');

    // Viktig: slik at lister reloader + vi får ID fra create-responsen
    btn.setAttribute('data-emit-entity', 'user');
    btn.setAttribute('data-emit-id-path', 'data.user_id');

    // Tom preset (skjema starter blankt)
    btn.setAttribute('data-modal-preset', JSON.stringify({
      user: {
        id: null,
        firstname: '',
        lastname: '',
        email: null,
        mobile: null,
        is_family_member: 1,
        is_manager: 0,
      }
    }));
  });

  // Edit-knapper (har data-id)
  rootEl.querySelectorAll('button[data-open="user"][data-id]').forEach(btn => {
    const id = btn.getAttribute('data-id');
    const u  = byId.get(id);
    btn.removeAttribute('data-open');

    btn.setAttribute('data-modal', 'open');
    btn.setAttribute('data-modal-template', 'modals/modal_user_form');
    btn.setAttribute('data-modal-title', 'Edit user');
    btn.setAttribute('data-modal-action', `/api/users/${id}`);
    btn.setAttribute('data-modal-method', 'PATCH');
    btn.setAttribute('data-modal-form-id', 'userForm');

    // Etter PATCH svarer backend bare OK, men modalHub vil bruke dataset.id som fallback
    btn.setAttribute('data-emit-entity', 'user');

    // Pre-fyll skjemaet fra listen vi allerede lastet
    // (unngår ekstra GET /api/users/{id})
    const presetUser = u ? {
      id: u.id,
      firstname: u.firstname || '',
      lastname:  u.lastname  || '',
      email:     u.email ?? null,
      mobile:    u.mobile ?? null,
      // is_family_member/is_manager kommer fra API som boolean – modal/template støtter både 0/1 og bool
      is_family_member: u.is_family_member ? 1 : 0,
      is_manager:       u.is_manager ? 1 : 0,
    } : {
      id, firstname: '', lastname: '', email: null, mobile: null, is_family_member: 1, is_manager: 0
    };

    btn.setAttribute('data-modal-preset', JSON.stringify({ user: presetUser }));
  });
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

      // Etter render: oppgrader knappene til data-modal-varianten
      const rootEl = document.getElementById('app');
      upgradeUserButtons(rootEl, users);

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
  if (typeof offChanged === 'function') {
    try { offChanged(); } catch {}
  }
  offChanged = null;
}
