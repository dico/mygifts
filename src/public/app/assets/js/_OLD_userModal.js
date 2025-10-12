// src/public/app/assets/js/userModal.js
import { api } from './Remote.js';
import { showModal, closeModal } from './modalRenderer.js';
import FormHandler from './FormHandler.js';
import { emit } from './eventBus.js';

function normalizeUser(u = {}) {
  return {
    id:                u.id || null,
    firstname:         u.firstname || '',
    lastname:          u.lastname  || '',
    email:             u.email     || '',
    mobile:            u.mobile    || '',
    // Disse to er membership-flagg i household_members
    is_family_member:  Number(u.is_family_member ?? 1),
    is_manager:        Number(u.is_manager ?? 0),
    // Disse finnes ofte i users-tabellen, men backend update ignorerer dem — ufarlig å sende.
    can_login:         Number(u.can_login ?? 0),
    is_active:         Number(u.is_active ?? 1),
  };
}

export async function openUserModal({ id = null } = {}) {
  console.log('[userModal] openUserModal id=', id);
  let user;
  try {
    if (id) {
      const res = await api(`/api/users/${id}`);
      // VIKTIG: backend svarer { data: { user: {...} } }
      const raw = res?.data?.user || {};
      user = normalizeUser(raw);
      console.log('[userModal] fetched user:', user);
    } else {
      user = normalizeUser({});
      console.log('[userModal] new user defaults:', user);
    }
  } catch (err) {
    console.error('[userModal] load user failed', err);
    user = normalizeUser({});
  }

  const { modalEl } = await showModal({
    // NB: riktig sti under /app/templates/
    template: 'modals/modal_user_form',
    data: {
      title: id ? 'Edit user' : 'New user',
      submitLabel: id ? 'Save changes' : 'Create user',
      user
    },
    onShown: () => {
      const form = modalEl.querySelector('form');
      if (!form) {
        console.error('[userModal] form not found in modal');
        return;
      }

      // Wire FormHandler
      new FormHandler(form, {
        resetOnSuccess: false,
        onSuccess: (resp) => {
          console.log('[userModal] save OK', resp);
          // fortell lister at noe endret seg
          emit('entity:changed', {
            entity: 'user',
            id: resp?.data?.user_id || id,
            op: id ? 'update' : 'create'
          });
          closeModal();
        },
        onError: (err) => {
          console.error('[userModal] save ERROR', err);
        }
      });
    }
  });

  return modalEl;
}
