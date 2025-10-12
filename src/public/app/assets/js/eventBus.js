// src/public/app/assets/js/eventBus.js
const listeners = new Map();

export function on(event, handler) {
  if (!listeners.has(event)) listeners.set(event, new Set());
  listeners.get(event).add(handler);
  return () => off(event, handler);
}

export function off(event, handler) {
  listeners.get(event)?.delete(handler);
}

export function emit(event, detail) {
  listeners.get(event)?.forEach(fn => {
    try { fn(detail); } catch (e) { console.error(`[eventBus] ${event} handler error`, e); }
  });
}
