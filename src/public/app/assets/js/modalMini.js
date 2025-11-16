// src/public/app/assets/js/modalMini.js
import { api } from './Remote.js';
import FormHandler from './FormHandler.js';
import { emit } from './eventBus.js';
import { initProductSelect } from './tomSelectInit.js';

/* -------------------------------- Template fetch/cache -------------------------------- */
const templateCache = new Map();

async function fetchTemplate(name) {
  if (templateCache.has(name)) return templateCache.get(name);
  const url = `/app/templates/${name}.jsr`;
  const bust = url + (url.includes('?') ? '&' : '?') + `_=${Date.now()}`;
  const res = await fetch(bust, { cache: 'no-cache' });
  const txt = await res.text();
  if (!res.ok || /<html[^>]*>/i.test(txt)) {
    console.error('[ModalMini] Template fetch failed:', url, 'status=', res.status, 'head=', txt.slice(0,120));
    throw new Error(`Failed to fetch template "${name}"`);
  }
  templateCache.set(name, txt);
  return txt;
}

/* -------------------------------------- Utils -------------------------------------- */
function parseJSON(v, fb) { if (!v) return fb; try { return JSON.parse(v); } catch { return fb; } }
function unwrapData(res)  { return (res && typeof res === 'object' && 'data' in res) ? res.data : res; }

/** Deep merge (immutable) – slår sammen b inn i a, rekursivt for plain objects/arrays */
function deepMerge(a, b) {
  if (b === undefined || b === null) return a;
  if (Array.isArray(a) && Array.isArray(b)) return b.slice(); // ta b sin array (enkelt og forutsigbart)
  if (isPlainObj(a) && isPlainObj(b)) {
    const out = { ...a };
    for (const k of Object.keys(b)) {
      out[k] = k in a ? deepMerge(a[k], b[k]) : b[k];
    }
    return out;
  }
  return b; // primitive/symbol/function, ta b
}
function isPlainObj(x) { return !!x && Object.prototype.toString.call(x) === '[object Object]'; }

/** Returner value[key] hvis value har en nested med samme key, ellers value */
function normalizeByKey(key, value) {
  if (value && typeof value === 'object' && key in value && value[key] && typeof value[key] === 'object') {
    return value[key];
  }
  return value;
}

function getByPath(obj, path) {
  if (!path) return obj;
  return path.split('.').reduce((cur, k) => (cur && k in cur) ? cur[k] : undefined, obj);
}

function replaceTokens(str, ctx) {
  if (!str || typeof str !== 'string') return str;
  return str.replace(/\{([^}]+)\}/g, (_, p) => {
    const v = getByPath(ctx, p.trim());
    return (v === undefined || v === null) ? '' : String(v);
  });
}

/* ----------------------------------- Modal renderer ----------------------------------- */
async function showModal({ template, data = {}, onShown, onHidden }) {
  const tpl = await fetchTemplate(template);
  const html = window.jsrender.templates(tpl).render(data);

  const shell = document.createElement('div');
  shell.className = 'modal fade';
  shell.setAttribute('tabindex', '-1');
  shell.setAttribute('aria-hidden', 'true');
  shell.innerHTML = `
    <div class="modal-dialog modal-dialog-scrollable">
      <div class="modal-content">${html}</div>
    </div>
  `;
  document.body.appendChild(shell);

  const inst = new bootstrap.Modal(shell, { backdrop: true, keyboard: true, focus: true });

  shell.addEventListener('shown.bs.modal', () => {
    const first = shell.querySelector('[autofocus]') || shell.querySelector('input,select,textarea,button');
    setTimeout(() => first?.focus?.(), 0);
    onShown?.(shell);
  }, { once: true });

  shell.addEventListener('hidden.bs.modal', () => {
    onHidden?.(shell);
    setTimeout(() => {
      try { shell.remove(); } catch {}
      if (!document.querySelector('.modal.show')) {
        document.querySelectorAll('.modal-backdrop.show').forEach(el => el.remove());
      }
    }, 150);
  }, { once: true });

  inst.show();
  return { modalEl: shell, instance: inst };
}

/* ----------------------------------- Public helpers ----------------------------------- */
export function closeModal() {
  const open = document.querySelector('.modal.show');
  if (!open) return;
  const inst = bootstrap.Modal.getInstance(open);
  inst?.hide();
}

/* ---------- Programmatisk åpning (for modalLinks.js m.fl.) ---------- */
export async function openModal({ template, title = '', method = 'GET', sources = [], transform, data = {} }) {
  if (!template) throw new Error('[ModalMini] openModal: template required');

  const fetched = {};
  if (Array.isArray(sources) && sources.length) {
    await Promise.all(sources.map(async (s) => {
      const u = s.url;
      const key = s.key;
      try {
        const raw   = await api(u);
        const unwr  = unwrapData(raw);
        const value = s.path ? (getByPath(unwr, s.path) ?? unwr) : normalizeByKey(key, unwr);
        fetched[key] = value;
      } catch (err) {
        console.warn('[ModalMini] openModal source fetch failed', s, err);
        fetched[key] = { __error: true, error: err?.message || 'fetch failed' };
      }
    }));
  }

  let renderData = deepMerge({ title, method }, fetched);
  renderData = deepMerge(renderData, data);

  if (typeof transform === 'function') {
    const synthetic = fetched.history ? { data: fetched.history } : { data: fetched };
    renderData = transform(synthetic) ?? renderData;
  }

  return showModal({
    template,
    data: renderData,
    onShown: () => {},
    onHidden: () => {}
  });
}

/* -------------------------------------- Open-from-button (ny syntaks) -------------------------------------- */
async function openFromButton(btn) {
  const ds = btn.dataset;

  const template    = ds.mmTemplate;
  const title       = ds.mmTitle || '';
  const submitLabel = ds.mmSubmitLabel || '';
  const action      = ds.mmAction || '';
  const method      = (ds.mmMethod || 'POST').toUpperCase();
  const formIdAttr  = ds.mmFormId || null;

  const emitEntity  = ds.mmEmitEntity || ds.emitEntity || null;
  const emitIdPath  = ds.mmEmitIdPath || ds.emitIdPath || '';

  if (!template) { console.error('[ModalMini] Missing template'); return; }

  const ctx = { ...ds };
  for (const k of Object.keys(ds)) {
    if (k.includes('-')) ctx[k.replace(/-/g, '_')] = ds[k];
    if (/^[a-z]+Id$/.test(k)) ctx[k.replace(/Id$/, '_id')] = ds[k];
  }

  const sources = parseJSON(ds.mmSources, []);
  const preset  = parseJSON(ds.mmPreset, {});

  const fetched = {};
  if (Array.isArray(sources) && sources.length) {
    await Promise.all(sources.map(async (s) => {
      const u = replaceTokens(s.url || '', ctx);
      const key = s.key;
      try {
        const raw   = await api(u);
        const unwr  = unwrapData(raw);
        const value = s.path ? (getByPath(unwr, s.path) ?? unwr) : normalizeByKey(key, unwr);
        fetched[key] = value;
      } catch (err) {
        console.warn('[ModalMini] source fetch failed', s, err);
        fetched[key] = { __error: true, error: err?.message || 'fetch failed' };
      }
    }));
  }

  const defaults = { title, submitLabel, action, method };

  // 🔧 Viktig: deep-merge for å UNNGÅ at preset.order overskriver fetched.order
  let renderData = deepMerge(defaults, fetched);
  renderData = deepMerge(renderData, preset);

  const tokenCtx = { ...renderData, ...ctx };

  let unenhance = null;

  const { modalEl } = await showModal({
    template,
    data: renderData,
    onShown: (modalEl) => {
      // ✅ Scope TomSelect til selve modalens DOM
      try { unenhance = initProductSelect(modalEl); } catch (e) { console.warn('[ModalMini] initProductSelect failed', e); }

      // ✅ Bind received gift quick modal handler
      if (template === 'modals/modal_received_gift_quick') {
        import('./receivedGiftQuickHandler.js').then(m => {
          m.bindReceivedGiftQuickModal(modalEl);
        }).catch(e => {
          console.error('[ModalMini] receivedGiftQuickHandler failed', e);
        });
      }

      // ✅ Bind product URL import modal handler
      if (template === 'modals/modal_product_url_import') {
        import('./productUrlImportHandler.js').then(m => {
          m.bindProductUrlImportModal(modalEl);
        }).catch(e => {
          console.error('[ModalMini] productUrlImportHandler failed', e);
        });
      }

      // ✅ Bind wishlist URL import modal handler
      if (template === 'modals/modal_wishlist_item_form') {
        import('./wishlistUrlImportHandler.js').then(m => {
          m.bindWishlistUrlImportModal(modalEl);
        }).catch(e => {
          console.error('[ModalMini] wishlistUrlImportHandler failed', e);
        });
      }

      // ✅ Bind gift URL import modal handler
      if (template === 'modals/modal_gift_form') {
        import('./giftUrlImportHandler.js').then(m => {
          m.bindGiftUrlImportModal(modalEl);
        }).catch(e => {
          console.error('[ModalMini] giftUrlImportHandler failed', e);
        });
      }

      modalEl.querySelectorAll('form').forEach((form) => {
        let formAction = form.getAttribute('action') || '';
        if (action) {
          const resolved = replaceTokens(action, tokenCtx);
          if (!formAction || /{[^}]+}/.test(formAction)) {
            form.setAttribute('action', resolved);
          }
        }

        const rawMethod = form.dataset.method || form.getAttribute('method') || method || 'POST';
        form.dataset.method = rawMethod.toUpperCase();

        if (formIdAttr && !form.id) form.id = formIdAttr;

        const sendMode = (form.dataset.send || 'json').toLowerCase();

        if (sendMode === 'formdata' || form.enctype === 'multipart/form-data') {
          import('./modalUpload.js').then(m => m.bindGenericUpload(modalEl)).catch(() => {});
          if (emitEntity && !form.dataset.emitEntity) form.dataset.emitEntity = emitEntity;
          if (emitIdPath && !form.dataset.emitIdPath) form.dataset.emitIdPath = emitIdPath;
        } else if (!form.dataset.noFormhandler) {
          new FormHandler(form, {
            resetOnSuccess: false,
            onSuccess: (resp) => {
              const fe = form.dataset.emitEntity || emitEntity || null;
              const fp = form.dataset.emitIdPath || emitIdPath || '';
              let idVal = null;

              if (fp) {
                idVal = getByPath(resp, fp) ?? null;
              } else {
                idVal = resp?.data?.id || resp?.data?.user_id || resp?.data?.event_id || resp?.data?.product_id || null;
              }

              if (fe) {
                emit('entity:changed', {
                  entity: fe,
                  id: idVal,
                  op: (form.dataset.method || 'POST').toUpperCase() === 'PATCH' ? 'update' : 'create'
                });
              }

              closeModal();
            }
          });
        }
      });
    },
    onHidden: () => {
      if (typeof unenhance === 'function') { try { unenhance(); } catch {} }
      unenhance = null;
    }
  });

  return { modalEl };
}

/* -------------------------------------- Public API -------------------------------------- */
export function registerModalMini(root = document) {
  const handler = (e) => {
    const btn = e.target.closest('[data-mm="open"]');
    if (!btn || !root.contains(btn)) return;
    e.preventDefault();
    openFromButton(btn);
  };
  root.addEventListener('click', handler);
  return () => root.removeEventListener('click', handler);
}

/* Gjør tilgjengelig globalt (modalLinks m.m.) */
window.modalMini = {
  open: openModal,
  close: closeModal,
};

export default { registerModalMini, close: closeModal, open: openModal };

