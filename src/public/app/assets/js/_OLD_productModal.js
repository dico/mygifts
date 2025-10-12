// src/public/app/assets/js/productModal.js
import { api } from './Remote.js';
import { showModal, closeModal } from './modalRenderer.js';
import FormHandler from './FormHandler.js';
import { emit } from './eventBus.js';

export async function openProductModal({ id = null } = {}) {
  let initial = { title: id ? 'Edit product' : 'New product', submitLabel: id ? 'Save changes' : 'Create product' };

  if (id) {
    const res = await api(`/api/products/${id}`);
    initial.product = res?.data || {};
  } else {
    initial.product = { name: '', description: '', url: '', image_url: '', default_price: '', currency_code: 'NOK' };
  }

  const { modalEl } = await showModal({
    template: 'modal_product_form',
    data: initial,
    onShown: () => {
      new FormHandler(modalEl.querySelector('form'), {
        resetOnSuccess: false,
        onSuccess: (resp) => {
          emit('entity:changed', { entity: 'product', id: resp?.data?.product_id || id, op: id ? 'update' : 'create' });
          closeModal();
        },
      });
    }
  });
}
