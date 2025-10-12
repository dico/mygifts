import { render } from '../view.js';
import { api } from '../Remote.js';
import { on } from '../eventBus.js';

let unbindActions = null;
let offChanged = null;
let offRefresh = null;
let currentEventId = null;

const TAB_KEY = (eid) => `eventTab:${eid}`; // 'give' | 'received'

function statusOrder(s) {
  const order = { idea: 1, reserved: 2, purchased: 3, given: 4, cancelled: 9 };
  return order[s] ?? 99;
}

function displayName(u) {
  return (
    u?.display_name ||
    `${u?.firstname || ''} ${u?.lastname || ''}`.trim() ||
    u?.email ||
    'User'
  );
}
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

function flattenOrdersToGifts(orders, members) {
  const memberIds = new Set(members.map(u => u.id));
  const byUser = new Map(members.map(u => [u.id, u]));

  const out = [];
  for (const o of orders) {
    const givers = o.givers || [];
    const recips = o.recipients || [];
    for (const it of (o.items || [])) {
      const g = {
        id: it.id,
        event_id: o.event_id,
        title: it.title || it.product_name || '—',
        notes: it.notes,
        status: it.status,
        planned_price: it.planned_price,
        purchase_price: it.purchase_price,
        currency_code: it.currency_code,
        status_class: badgeClass(it.status),
        // arrays fra order
        givers,
        recipients: recips,
        // display-strenger (NYTT): list opp alle
        givers_display: (givers && givers.length)
          ? givers.map(x => x.display_name || '').filter(Boolean).join(', ')
          : '—',
        recipients_display: (recips && recips.length)
          ? recips.map(x => x.display_name || '').filter(Boolean).join(', ')
          : '—',
        // “primær” for legacy grouping:
        giver_user_id: givers[0]?.id || null,
        recipient_user_id: recips[0]?.id || null,
      };
      out.push(g);
    }
  }

  // Vi gir = minst én giver er medlem
  const giftsWeGive = out.filter(g =>
    (g.givers || []).some(x => memberIds.has(x.id))
  );
  // Vi mottar = minst én mottaker er medlem
  const giftsWeReceived = out.filter(g =>
    (g.recipients || []).some(x => memberIds.has(x.id))
  );

  function groupByRecipient(arr) {
    const map = new Map();
    for (const g of arr) {
      const rid = g.recipient_user_id || g.recipients?.[0]?.id;
      const u   = rid ? byUser.get(rid) : null;
      if (!u) continue;
      if (!map.has(rid)) map.set(rid, { user: u, gifts: [] });
      map.get(rid).gifts.push(g);
    }
    const list = Array.from(map.values()).sort((a, b) =>
      displayName(a.user).localeCompare(displayName(b.user))
    );
    for (const grp of list) {
      grp.gifts.sort((a, b) => {
        const sa = statusOrder(a.status);
        const sb = statusOrder(b.status);
        if (sa !== sb) return sa - sb;
        return (a.title || '').toLowerCase().localeCompare((b.title || '').toLowerCase());
      });
    }
    return list;
  }

  return {
    giveGroups: groupByRecipient(giftsWeGive),
    receivedGroups: groupByRecipient(giftsWeReceived),
  };
}

async function load(eventId) {
  try {
    const [evRes, ordersRes, membersRes] = await Promise.all([
      api(`/api/events/${eventId}`),
      api(`/api/gift-orders?event_id=${encodeURIComponent(eventId)}`),
      api(`/api/users`),
    ]);

    const event   = evRes?.data?.event || null;
    const orders  = ordersRes?.data?.orders || [];
    const members = membersRes?.data?.users || [];

    const { giveGroups, receivedGroups } = flattenOrdersToGifts(orders, members);

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
    console.error('[eventDetailPage] load error', err);
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
    const delGift = e.target.closest('[data-delete="gift"]');
    if (delGift && rootEl.contains(delGift)) {
      const id = delGift.dataset.id;
      if (!id) return;
      if (!confirm('Delete this gift?')) return;
      try {
        await api(`/api/gifts/${id}`, { method: 'DELETE' });
        await load(currentEventId);
      } catch (err) {
        console.error('[eventDetailPage] delete gift error', err);
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
        console.error('[eventDetailPage] delete event error', err);
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

  const $root = document.getElementById('app');
  unbindActions = bindActions($root);

  offChanged = on('entity:changed', (p) => {
    if (p?.entity === 'gift' || p?.entity === 'event' || p?.entity === 'order') load(currentEventId);
  });

  offRefresh = (() => {
    const handler = (e) => { if (e?.detail?.key === 'event-detail') load(currentEventId); };
    window.addEventListener('route:refresh', handler);
    return () => window.removeEventListener('route:refresh', handler);
  })();
}

export function unmount() {
  unbindActions?.();
  offChanged?.();
  offRefresh?.();
  unbindActions = null;
  offChanged = null;
  offRefresh = null;
  currentEventId = null;
}
