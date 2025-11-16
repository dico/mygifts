import { render } from '../view.js';
import { api } from '../Remote.js';
import { on } from '../eventBus.js';

let unbindActions = null;
let offChanged = null;
let offRefresh = null;
let currentEventId = null;
let importModal = null;
let unbindImport = null;

const TAB_KEY = (eid) => `eventTab:${eid}`;

function badgeClass(status) {
  switch (status) {
    case 'idea':      return 'text-bg-secondary';
    case 'reserved':  return 'text-bg-warning';
    case 'purchased': return 'text-bg-primary';
    case 'given':     return 'text-bg-success';
    case 'cancelled': return 'text-bg-dark';
    default:          return 'text-bg-light';
  }
}

async function load(eventId) {
  try {
    const [evRes, groupedRes] = await Promise.all([
      api(`/api/events/${eventId}`),
      api(`/api/gift-orders?event_id=${encodeURIComponent(eventId)}`)
    ]);

    const event = evRes?.data?.event || null;
    const data = groupedRes?.data || groupedRes || {};

    // Bruk alltid *grouped* hvis det finnes, ellers fallback til flat
    let giveGroups     = Array.isArray(data.give_grouped)     ? data.give_grouped     : (data.give || []);
    let receivedGroups = Array.isArray(data.received_grouped) ? data.received_grouped : (data.received || []);

    const ensureStatusClass = (groups) => {
      for (const grp of groups || []) {
        for (const g of grp.gifts || []) {
          if (!g.status_class) g.status_class = badgeClass(g.status);
        }
      }
    };
    ensureStatusClass(giveGroups);
    ensureStatusClass(receivedGroups);

    const saved = localStorage.getItem(TAB_KEY(eventId));
    const activeTab = (saved === 'received') ? 'received' : 'give';

    await render('event_detail', {
      title: event?.name || 'Event',
      event,
      eventId,
      giveGroups,
      receivedGroups,
      activeTab
    });

    // Restore valgt fane
    const tabGive     = document.querySelector('#tabGive');
    const tabReceived = document.querySelector('#tabReceived');
    if (activeTab === 'received' && tabReceived) {
      const inst = bootstrap.Tab.getOrCreateInstance(tabReceived);
      inst.show();
    } else if (tabGive) {
      const inst = bootstrap.Tab.getOrCreateInstance(tabGive);
      inst.show();
    }

    // Husk valgt fane mellom reloads
    const tabsEl = document.querySelector('#giftTabs');
    tabsEl?.addEventListener('shown.bs.tab', (e) => {
      const id = e.target?.id;
      const val = id === 'tabReceived' ? 'received' : 'give';
      localStorage.setItem(TAB_KEY(eventId), val);
    });

  } catch (err) {
    console.error('[event_detail] load error', err);
    await render('event_detail', {
      title: 'Event',
      event: null,
      eventId,
      giveGroups: [],
      receivedGroups: [],
      activeTab: 'give'
    });
  }
}

async function showImportModal() {
  console.log('[event_detail] showImportModal called');

  // Create modal element
  const modalEl = document.createElement('div');
  modalEl.className = 'modal fade';
  modalEl.innerHTML = '<div class="modal-dialog"><div class="modal-content" id="importModalContent"></div></div>';
  document.body.appendChild(modalEl);
  console.log('[event_detail] Modal element created and appended');

  importModal = new bootstrap.Modal(modalEl);
  console.log('[event_detail] Bootstrap modal instance created');

  // Render loading state
  const loadingHtml = `
    <div class="modal-header">
      <h5 class="modal-title">Import from Template</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body text-center py-4">
      <div class="spinner-border" role="status"></div>
      <div class="text-muted mt-2">Loading templates...</div>
    </div>
  `;
  document.getElementById('importModalContent').innerHTML = loadingHtml;
  console.log('[event_detail] Loading state rendered');

  importModal.show();
  console.log('[event_detail] Modal shown');

  // Load templates
  try {
    const res = await api('/api/gift-templates');
    const templates = res?.data?.templates || res?.templates || [];

    let templatesHtml = '';
    if (templates.length === 0) {
      templatesHtml = `
        <div class="alert alert-light">
          <p class="mb-0">No templates available.</p>
          <p class="mb-0 small text-muted">Create a template first in Settings.</p>
        </div>
      `;
    } else {
      templatesHtml = templates.map(t => `
        <div class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
          <div>
            <div class="fw-semibold">${escapeHtml(t.name)}</div>
            ${t.description ? `<div class="text-muted small">${escapeHtml(t.description)}</div>` : ''}
          </div>
          <button
            type="button"
            class="btn btn-sm btn-primary"
            data-import-template="${t.id}"
          >
            Import
          </button>
        </div>
      `).join('');
    }

    const contentHtml = `
      <div class="modal-header">
        <h5 class="modal-title">Import from Template</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="error-message alert alert-danger d-none"></div>
        <div class="list-group">
          ${templatesHtml}
        </div>
      </div>
    `;

    document.getElementById('importModalContent').innerHTML = contentHtml;
    console.log('[event_detail] Templates rendered');

    // Bind import handlers
    bindImportHandlers();
  } catch (err) {
    console.error('[event_detail] failed to load templates', err);
    const errorHtml = `
      <div class="modal-header">
        <h5 class="modal-title">Import from Template</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-danger">
          Failed to load templates: ${escapeHtml(err.message || 'Unknown error')}
        </div>
      </div>
    `;
    document.getElementById('importModalContent').innerHTML = errorHtml;
  }

  // Cleanup on hide
  modalEl.addEventListener('hidden.bs.modal', () => {
    importModal?.dispose();
    importModal = null;
    modalEl.remove();
  });
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

function bindImportHandlers() {
  const content = document.getElementById('importModalContent');
  if (!content) return;

  const handler = async (e) => {
    const btn = e.target.closest('[data-import-template]');
    if (!btn) return;

    const templateId = btn.dataset.importTemplate;
    if (!templateId || !currentEventId) return;

    // Disable button during import
    btn.disabled = true;
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Importing...';

    try {
      await api(`/api/gift-templates/${templateId}/import`, {
        method: 'POST',
        body: { event_id: currentEventId }
      });

      // Close modal and reload event
      importModal?.hide();
      await load(currentEventId);
    } catch (err) {
      console.error('[event_detail] import error', err);

      // Show error in modal
      const errorDiv = content.querySelector('.error-message');
      if (errorDiv) {
        errorDiv.textContent = err.message || 'Failed to import template';
        errorDiv.classList.remove('d-none');
      }

      // Re-enable button
      btn.disabled = false;
      btn.innerHTML = originalHtml;
    }
  };

  content.addEventListener('click', handler);
  unbindImport = () => content.removeEventListener('click', handler);
}

function bindActions(rootEl) {
  const handler = async (e) => {
    const delOrder = e.target.closest('[data-delete="order"]');
    if (delOrder && rootEl.contains(delOrder)) {
      const orderId = delOrder.dataset.id;
      if (!orderId) return;
      if (!confirm('Delete this gift?')) return;
      try {
        await api(`/api/gift-orders/${orderId}`, { method: 'DELETE' });
        await load(currentEventId);
      } catch (err) {
        console.error('[event_detail] delete order error', err);
        alert('Failed to delete gift.');
      }
      return;
    }

    const delEvent = e.target.closest('[data-delete="event"]');
    if (delEvent && rootEl.contains(delEvent)) {
      const id = delEvent.dataset.id;
      if (!id) return;
      if (!confirm('Delete this event?')) return;
      try {
        await api(`/api/events/${id}`, { method: 'DELETE' });
        window.page.show('/app/events');
      } catch (err) {
        console.error('[event_detail] delete event error', err);
        alert('Failed to delete event.');
      }
    }
  };
  rootEl.addEventListener('click', handler);
  return () => rootEl.removeEventListener('click', handler);
}

export async function mount(eventId) {
  currentEventId = eventId;

  await render('event_detail', {
    title: 'Event',
    event: null,
    eventId,
    giveGroups: [],
    receivedGroups: [],
    activeTab: 'give'
  });
  await load(eventId);

  const rootEl = document.getElementById('app');
  unbindActions = bindActions(rootEl);

  // Bind import template button
  const importBtn = document.getElementById('importTemplateBtn');
  console.log('[event_detail] Import button:', importBtn);
  if (importBtn) {
    console.log('[event_detail] Binding import button click handler');
    importBtn.addEventListener('click', () => {
      console.log('[event_detail] Import button clicked!');
      showImportModal();
    });
  } else {
    console.error('[event_detail] Import button not found in DOM');
  }

  offChanged = on('entity:changed', (p) => {
    if (p?.entity === 'gift' || p?.entity === 'event' || p?.entity === 'order' || p?.entity === 'product') {
      load(currentEventId);
    }
  });

  offRefresh = (() => {
    const handler = (e) => { if (e?.detail?.key === 'event-detail') load(currentEventId); };
    window.addEventListener('route:refresh', handler);
    return () => window.removeEventListener('route:refresh', handler);
  })();
}

export function unmount() {
  unbindActions?.(); unbindActions = null;
  unbindImport?.();  unbindImport = null;
  offChanged?.();    offChanged = null;
  offRefresh?.();    offRefresh = null;
  importModal?.hide();
  importModal?.dispose();
  importModal = null;
  currentEventId = null;
}
