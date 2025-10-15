// src/public/app/assets/js/pages/event_detail.js
import { render } from '../view.js';
import { api } from '../Remote.js';
import { on } from '../eventBus.js';

let unbindActions = null;
let offChanged = null;
let offRefresh = null;
let currentEventId = null;

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
    let giveGroups     = data.give || [];
    let receivedGroups = data.received || [];

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

    const tabGive     = document.querySelector('#tabGive');
    const tabReceived = document.querySelector('#tabReceived');
    if (activeTab === 'received' && tabReceived) {
      const inst = bootstrap.Tab.getOrCreateInstance(tabReceived);
      inst.show();
    } else if (tabGive) {
      const inst = bootstrap.Tab.getOrCreateInstance(tabGive);
      inst.show();
    }
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
  offChanged?.();    offChanged = null;
  offRefresh?.();    offRefresh = null;
  currentEventId = null;
}
