// src/public/app/assets/js/modalLinks.js
// Global drill-down linker for modaler (produkt ⇄ bruker ⇄ event)
import { registerModalMini } from './modalMini.js';

let unbind = null;

function openProductHistory(productId, fallbackName = '') {
  if (!productId) return;
  window.modalMini.open({
    template: 'modals/modal_product_history',
    title: 'Gift history',
    method: 'GET',
    sources: [{ key: 'history', url: `/api/products/${productId}/gift-orders` }],
    // Backend svarer med { data: { rows: [...] } }
    // modalMini.open sender transform et objekt som ligner { data: ... } (syntetisk)
    transform: (res) => {
      const rows = (res?.data?.rows) || [];
      const fromRow = rows[0]?.product || null;
      return {
        product: fromRow || { id: productId, name: fallbackName },
        rows
      };
    }
  });
}

function openUserHistory(userId, fallbackName = '') {
  if (!userId) return;
  window.modalMini.open({
    template: 'modals/modal_user_history',
    title: 'Gifts received',
    method: 'GET',
    sources: [{ key: 'history', url: `/api/users/${userId}/received-gifts` }],
    transform: (res) => ({
      user: { id: userId, display_name: fallbackName },
      rows: (res?.data?.rows) || []
    })
  });
}

function goToEvent(eventId) {
  if (!eventId) return;
  window.page?.show?.(`/app/events/${eventId}`);
}

export function initModalLinks() {
  // Sørg for at data-mm-knapper funker globalt, og at vi ikke dobbelbinder
  registerModalMini(document.body);

  const onClick = (e) => {
    const a = e.target.closest('a');
    if (!a) return;

    // Produkt-drill
    const pid = a.getAttribute('data-link-product');
    if (pid) {
      e.preventDefault();
      const name = a.textContent?.trim() || '';
      openProductHistory(pid, name);
      return;
    }

    // Bruker-drill
    const uid = a.getAttribute('data-link-user');
    if (uid) {
      e.preventDefault();
      const name = a.textContent?.trim() || '';
      openUserHistory(uid, name);
      return;
    }

    // Event-drill
    const eid = a.getAttribute('data-link-event');
    if (eid) {
      e.preventDefault();
      goToEvent(eid);
      return;
    }
  };

  document.body.addEventListener('click', onClick);
  unbind = () => document.body.removeEventListener('click', onClick);
}

export function teardownModalLinks() {
  unbind?.();
  unbind = null;
}
