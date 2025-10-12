import { api } from './Remote.js';
import { showModal, closeModal } from './modalRenderer.js';
import FormHandler from './FormHandler.js';
import { emit } from './eventBus.js';

function normalizeEvent(e = {}) {
  return {
    id:               e.id || null,
    name:             e.name || '',
    event_date:       e.event_date || '',
    event_type:       e.event_type || 'other',
    honoree_user_id:  e.honoree_user_id || '',
    notes:            e.notes || ''
  };
}

export async function openEventModal({ id = null } = {}) {
  let eventData;
  if (id) {
    const res = await api(`/api/events/${id}`);
    eventData = normalizeEvent(res?.data?.event || {});
  } else {
    eventData = normalizeEvent({});
  }

  const { modalEl } = await showModal({
    template: 'modals/modal_event_form',
    data: {
      title: id ? 'Edit event' : 'New event',
      submitLabel: id ? 'Save changes' : 'Create event',
      event: eventData
    },
    onShown: () => {
      new FormHandler(modalEl.querySelector('form'), {
        resetOnSuccess: true,
        onSuccess: (resp) => {
          // Emit for liste-refresh
          emit('entity:changed', {
            entity: 'event',
            id: resp?.data?.event_id || id,
            op: id ? 'update' : 'create'
          });
          closeModal();
        },
      });
    }
  });
}
