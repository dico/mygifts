// src/public/app/assets/js/pages/dashboard.js
import { api } from '../Remote.js';
import { render } from '../view.js';

let offRefresh = null;

function formatDate(d) {
  if (!d) return '';
  try {
    const date = new Date(d);
    const now = new Date();
    const diffDays = Math.ceil((date - now) / (1000 * 60 * 60 * 24));
    const opts = { year: 'numeric', month: 'short', day: 'numeric' };
    const formatted = date.toLocaleDateString('en-US', opts);

    if (diffDays === 0) return formatted + ' (Today)';
    if (diffDays === 1) return formatted + ' (Tomorrow)';
    if (diffDays > 0 && diffDays <= 7) return formatted + ' (in ' + diffDays + ' days)';
    return formatted;
  } catch(e) { return String(d); }
}

export async function mount() {
  document.title = 'Home · MyGifts';

  // Initial render with loading state
  await render('dashboard', {
    title: 'Dashboard',
    loading: true,
    stats: {},
    recentEvents: [],
    upcomingEvents: []
  });

  await load();

  offRefresh = (() => {
    const handler = (e) => { if (e?.detail?.key === 'dashboard') load(); };
    window.addEventListener('route:refresh', handler);
    return () => window.removeEventListener('route:refresh', handler);
  })();
}

export function unmount() {
  offRefresh?.();
  offRefresh = null;
}

async function load() {
  try {
    // Fetch events data
    const eventsRes = await api('/api/events');
    const events = eventsRes?.data?.events || eventsRes?.events || [];

    // Calculate stats
    const totalEvents = events.length;
    const now = new Date();
    const todayStart = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const upcomingEvents = events.filter(e => {
      if (!e.event_date) return false;
      const eventDate = new Date(e.event_date);
      const eventDateStart = new Date(eventDate.getFullYear(), eventDate.getMonth(), eventDate.getDate());
      return eventDateStart >= todayStart;
    }).sort((a, b) => new Date(a.event_date) - new Date(b.event_date));

    const totalGifts = events.reduce((sum, e) => {
      const giftCount = (e.given_gifts?.length || 0) + (e.received_gifts?.length || 0);
      return sum + giftCount;
    }, 0);

    // Get recent events (last 5, sorted by created_at)
    const recentEvents = [...events]
      .sort((a, b) => new Date(b.created_at || 0) - new Date(a.created_at || 0))
      .slice(0, 5)
      .map(e => ({
        ...e,
        formattedDate: formatDate(e.event_date),
        giftCount: (e.given_gifts?.length || 0) + (e.received_gifts?.length || 0)
      }));

    const upcomingEventsFormatted = upcomingEvents.slice(0, 5).map(e => ({
      ...e,
      formattedDate: formatDate(e.event_date),
      giftCount: (e.given_gifts?.length || 0) + (e.received_gifts?.length || 0)
    }));

    await render('dashboard', {
      title: 'Dashboard',
      loading: false,
      stats: {
        totalEvents,
        totalGifts,
        upcomingCount: upcomingEvents.length
      },
      recentEvents,
      upcomingEvents: upcomingEventsFormatted
    });
  } catch (e) {
    console.error('[dashboard] load failed', e);
    await render('dashboard', {
      title: 'Dashboard',
      loading: false,
      error: 'Failed to load dashboard data',
      stats: {},
      recentEvents: [],
      upcomingEvents: []
    });
  }
}
