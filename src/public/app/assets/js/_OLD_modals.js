import { openUserModal } from './userModal.js';
import { openProductModal } from './productModal.js';
import { openEventModal } from './eventModal.js';

export function registerGlobalModals() {
  window.App = window.App || {};
  window.App.Modals = {
    openUser: openUserModal,
    openProduct: openProductModal,
    openEvent: openEventModal,
  };
}
