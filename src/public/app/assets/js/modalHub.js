// src/public/app/assets/js/modalHub.js
import { api } from './Remote.js';
import { showModal, closeModal } from './modalRenderer.js';
import FormHandler from './FormHandler.js';
import { emit } from './eventBus.js';

/** Replace {tokens} in a string with values from ctx (dataset + computed) */
function tpl(str, ctx) {
  if (!str || typeof str !== 'string') return str;
  return str.replace(/\{([^}]+)\}/g, (_, key) => (ctx[key] ?? ''));
}

/** Deep merge (very small, good enough for modal data) */
function deepMerge(target = {}, source = {}) {
  for (const k of Object.keys(source || {})) {
    if (source[k] && typeof source[k] === 'object' && !Array.isArray(source[k])) {
      target[k] = deepMerge(target[k] || {}, source[k]);
    } else {
      target[k] = source[k];
    }
  }
  return target;
}

/** Safe JSON parse for data attributes */
function parseJSON(value, fallback) {
  if (!value) return fallback;
  try { return JSON.parse(value); } catch { return fallback; }
}

/** Resolve a dotted path like "data.gift_id" from an object */
function getPath(obj, path) {
  if (!path) return undefined;
  return path.split('.').reduce((acc, p) => (acc == null ? acc : acc[p]), obj);
}

/** Try to unwrap common API shapes into the payload the template actually needs */
function unwrapApiPayload(res) {
  if (res && typeof res === 'object') {
    if ('data' in res) {
      const d = res.data;
      if (d && typeof d === 'object') {
        const keys = Object.keys(d);
        if (keys.length === 1 && typeof d[keys[0]] === 'object') {
          return d[keys[0]]; // e.g. data.user
        }
      }
      return d;
    }
  }
  return res;
}

/** Tom Select init helpers */
function initTomSelects(modalEl, fetched) {
  // Users (multi)
  modalEl.querySelectorAll('[data-tomselect="users-multi"]').forEach((el) => {
    const users = (fetched.members?.users) || fetched.members || [];
    const prefill = fetched.prefill_recipient_ids || [];
    const opts = users.map(u => ({
      id: u.id,
      display_name: u.display_name || `${u.firstname||''} ${u.lastname||''}`.trim() || u.email || 'User'
    }));
    // eslint-disable-next-line no-undef
    const ts = new TomSelect(el, {
      valueField: 'id',
      labelField: 'display_name',
      searchField: ['display_name','firstname','lastname','email'],
      options: opts,
      persist: false,
      create: false
    });
    if (el.name.startsWith('recipient_user_ids') && prefill.length) {
      ts.setValue(prefill, true);
    }
  });

  // Product (single, AUTHed AJAX + create)
  const prodEl = modalEl.querySelector('[data-tomselect="product-single"]');
  if (prodEl) {
    const hiddenName = modalEl.querySelector('#giftProductName');
    // eslint-disable-next-line no-undef
    const ts = new TomSelect(prodEl, {
      valueField: 'id',
      labelField: 'name',
      searchField: ['name'],
      create: (input) => {
        if (hiddenName) hiddenName.value = input;
        return { id: '', name: input };
      },
      load: function(query, cb) {
        if (!query || query.trim() === '') return cb();
        api(`/api/products?q=${encodeURIComponent(query)}&limit=20`)
          .then((res) => {
            const arr = (res?.data?.products) || res?.products || [];
            cb(arr);
          })
          .catch(() => cb());
      },
      onChange: (val) => {
        if (!hiddenName) return;
        if (val && ts.options[val] && ts.options[val].id) {
          hiddenName.value = '';
        }
      }
    });
  }
}

/** Image preview helper */
function showPreview(previewEl, currentEl, file) {
  if (!previewEl || !file) return;
  try {
    const url = URL.createObjectURL(file);
    previewEl.src = url;
    previewEl.classList.remove('d-none');
    if (currentEl) currentEl.classList.add('d-none');
  } catch {}
}

/** Drag & drop + single-open guarded picker */
function initUploadPickers(modalEl) {
  modalEl.querySelectorAll('[data-upload-picker]').forEach((wrap) => {
    if (wrap.__pickerInit) return;
    wrap.__pickerInit = true;

    const input     = wrap.querySelector('input[type="file"][data-file-input]');
    const previewEl = wrap.querySelector('[data-file-preview]');
    const currentEl = wrap.querySelector('[data-file-current]');
    const nameEl    = wrap.querySelector('[data-file-name]');
    const zone      = wrap.querySelector('[data-dropzone]') || wrap;

    if (!input) return;

    let opening = false;
    const openDialog = (e) => {
      e?.preventDefault?.();
      e?.stopPropagation?.();
      if (opening) return;
      opening = true;
      setTimeout(() => {
        input.click();
        setTimeout(() => { opening = false; }, 300);
      }, 0);
    };

    // Clicking the zone opens picker (but not if clicking directly on input)
    if (zone) {
      zone.addEventListener('click', (e) => {
        const realInput = e.target.closest('input[type="file"][data-file-input]');
        if (realInput) return;
        openDialog(e);
      });
    }

    // Prevent bubbling double-open
    input.addEventListener('click', (e) => e.stopPropagation(), { capture: true });

    // Change => preview + filename
    input.addEventListener('change', () => {
      const f = input.files && input.files[0];
      if (!f) return;
      if (nameEl) nameEl.textContent = f.name;
      showPreview(previewEl, currentEl, f);
      zone?.classList.remove('drop-active');
    });

    // DnD
    const stop = (e) => { e.preventDefault(); e.stopPropagation(); };
    const onDragOver = (e) => { stop(e); zone?.classList.add('drop-active'); };
    const onDragEnter = onDragOver;
    const onDragLeave = (e) => { stop(e); if (e.target === zone) zone?.classList.remove('drop-active'); };
    const onDrop = (e) => {
      stop(e);
      zone?.classList.remove('drop-active');
      const files = e.dataTransfer?.files;
      if (!files?.length) return;

      const f = files[0];
      if (!/^image\//i.test(f.type)) {
        alert('Please drop an image file.');
        return;
      }
      const dt = new DataTransfer();
      dt.items.add(f);
      input.files = dt.files;

      if (nameEl) nameEl.textContent = f.name;
      showPreview(previewEl, currentEl, f);
    };

    ['dragenter','dragover'].forEach(ev => zone.addEventListener(ev, onDragOver));
    zone.addEventListener('dragleave', onDragLeave);
    zone.addEventListener('drop', onDrop);
  });
}

/** Open a modal from a clicked element */
async function openFromElement(btn) {
  const template = btn.dataset.modalTemplate;
  if (!template) { console.error('[modalHub] Missing data-modal-template'); return; }

  // Dataset -> context
  const datasetCtx = { ...btn.dataset };
  for (const k of Object.keys(btn.dataset)) {
    if (k.includes('-')) datasetCtx[k.replace(/-/g, '_')] = btn.dataset[k];
    if (/^[a-z]+Id$/.test(k)) {
      const snake = k.replace(/Id$/, '_id');
      datasetCtx[snake] = btn.dataset[k];
    }
  }

  const title       = btn.dataset.modalTitle || '';
  const submitLabel = btn.dataset.modalSubmitLabel || '';
  const formIdAttr  = btn.dataset.modalFormId || null;
  const actionRaw   = btn.dataset.modalAction || '';
  const action      = tpl(actionRaw, datasetCtx);
  const hasId       = !!(btn.dataset.id);

  // Only use explicit data-modal-method (don't default PATCH)
  const method = btn.dataset.modalMethod ? btn.dataset.modalMethod.toUpperCase() : null;

  const preset  = parseJSON(btn.dataset.modalPreset, {});
  const sources = parseJSON(btn.dataset.modalSources, []);
  const fetched = {};

  if (Array.isArray(sources) && sources.length) {
    const jobs = sources.map(async (s) => {
      const key = s.key;
      const url = tpl(s.url, datasetCtx);
      try {
        const res = await api(url);
        fetched[key] = unwrapApiPayload(res);
      } catch (err) {
        console.error('[modalHub] Source fetch failed:', s, err);
        fetched[key] = { __error: true, error: err?.message || 'fetch failed' };
      }
    });
    await Promise.all(jobs);
  }

  const renderData = deepMerge(
    { title, submitLabel, isEdit: hasId, action, method },
    deepMerge(fetched, preset)
  );

  const { modalEl } = await showModal({
    template,
    data: renderData,
    onShown: () => {
      const form = modalEl.querySelector('form');
      if (!form) return;

      if (action) form.setAttribute('action', action);
      if (method) form.dataset.method = method; // don't override unless set
      if (formIdAttr) form.setAttribute('id', formIdAttr);

      // INIT widgets
      initTomSelects(modalEl, renderData);
      initUploadPickers(modalEl);

      // --- Remove avatar button handler (if present in template) ---
      const rmBtn = modalEl.querySelector('[data-remove-avatar]');
      if (rmBtn) {
        rmBtn.addEventListener('click', async () => {
          const uid = rmBtn.getAttribute('data-user-id');
          if (!uid) return;
          if (!confirm('Remove this photo?')) return;

          try {
            await api(`/api/users/${uid}/avatar`, { method: 'DELETE' });
            // Notify lists to refresh and close modal
            emit('entity:changed', { entity: 'user', id: uid, op: 'update' });
            closeModal();
          } catch (e) {
            console.error('[modalHub] remove avatar failed', e);
            alert('Failed to remove photo');
          }
        });
      }
      // -------------------------------------------------------------

      // Bind submit handler
      new FormHandler(form, {
        resetOnSuccess: false,
        onSuccess: (resp) => {
          const entity   = btn.dataset.emitEntity || null;
          const idPath   = btn.dataset.emitIdPath || 'data.id';
          const idValue  = getPath(resp, idPath) || btn.dataset.id || null;
          const opCreate = btn.dataset.emitOpCreate || 'create';
          const opUpdate = btn.dataset.emitOpUpdate || 'update';
          const op       = hasId ? opUpdate : opCreate;

          if (entity) {
            emit('entity:changed', {
              entity,
              id: idValue,
              op,
              event_id: datasetCtx.event_id || datasetCtx.eventId || undefined,
            });
          }
          closeModal();
        },
      });
    }
  });
}

export function registerDataModals(root = document) {
  const handler = (e) => {
    const btn = e.target.closest('[data-modal="open"]');
    if (!btn) return;
    if (!root.contains(btn)) return;

    e.preventDefault();
    openFromElement(btn);
  };
  root.addEventListener('click', handler);
  return () => root.removeEventListener('click', handler);
}
