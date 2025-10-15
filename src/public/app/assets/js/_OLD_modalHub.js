// src/public/app/assets/js/modalHub.js
import { api } from './Remote.js';
import { showModal, closeModal } from './modalRenderer.js';
import FormHandler from './FormHandler.js';
import { emit } from './eventBus.js';

/** Lokal, sikker template-replacer: "{token}" -> ctx[token] */
function _tpl(str, ctx) {
  if (!str || typeof str !== 'string') return str || '';
  return str.replace(/\{([^}]+)\}/g, (_, key) => (ctx && key in ctx ? ctx[key] : ''));
}

/** Liten deep-merge for modaldata */
function deepMerge(target = {}, source = {}) {
  for (const k of Object.keys(source || {})) {
    const v = source[k];
    if (v && typeof v === 'object' && !Array.isArray(v)) {
      target[k] = deepMerge(target[k] || {}, v);
    } else {
      target[k] = v;
    }
  }
  return target;
}

/** Trygg JSON-parse */
function parseJSON(value, fallback) {
  if (!value) return fallback;
  try { return JSON.parse(value); } catch { return fallback; }
}

/** data.gift_id -> verdi */
function getPath(obj, path) {
  if (!path) return undefined;
  return path.split('.').reduce((acc, p) => (acc == null ? acc : acc[p]), obj);
}

/** Pakk ut nyttelast typ data.{singular} */
function unwrapApiPayload(res) {
  if (res && typeof res === 'object' && 'data' in res) {
    const d = res.data;
    if (d && typeof d === 'object') {
      const keys = Object.keys(d);
      if (keys.length === 1 && typeof d[keys[0]] === 'object') return d[keys[0]];
      return d;
    }
  }
  return res;
}

/** Tom Select initer */
function initTomSelects(modalEl, fetched) {
  // Users (multi)
  modalEl.querySelectorAll('[data-tomselect="users-multi"]').forEach((el) => {
    const users = (fetched.members?.users) || fetched.members || [];
    const prefillG = fetched.prefill_giver_ids || [];
    const prefillR = fetched.prefill_recipient_ids || [];
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
    if (el.name.startsWith('recipient_user_ids') && prefillR.length) ts.setValue(prefillR, true);
    if (el.name.startsWith('giver_user_ids')     && prefillG.length) ts.setValue(prefillG, true);
  });

  // Product (single)
  const prodEl = modalEl.querySelector('[data-tomselect="product-single"]');
  if (prodEl) {
    const hiddenName = modalEl.querySelector('[data-product-name]') || modalEl.querySelector('#giftProductName');
    // eslint-disable-next-line no-undef
    const ts = new TomSelect(prodEl, {
      valueField: 'id',
      labelField: 'name',
      searchField: ['name'],
      maxItems: 1,
      delimiter: '\u0000',
      splitOn: null,
      plugins: [],
      persist: false,
      selectOnTab: true,
      closeAfterSelect: true,
      create: (input) => {
        if (hiddenName) hiddenName.value = input;
        return { id: '', name: input };
      },
      load: (query, cb) => {
        if (!query || query.trim() === '') return cb();
        api(`/api/products?q=${encodeURIComponent(query)}&limit=20`)
          .then((res) => cb((res?.data?.products) || res?.products || []))
          .catch(() => cb());
      },
      render: {
        option: (d) => `<div class="ts-opt">${d.name || ''}</div>`,
        item:   (d) => `<div class="ts-item">${d.name || ''}</div>`
      },
      onChange: (val) => {
        if (!hiddenName) return;
        const opt = val && ts.options[val];
        if (opt && opt.id) hiddenName.value = ''; // valgt eksisterende => tøm name
      }
    });

    // Prefill ved edit (fra ordre)
    const selectedId   = prodEl.getAttribute('data-selected-product-id') || fetched?.order?.product_id || '';
    const selectedName = prodEl.getAttribute('data-selected-product-name') || fetched?.order?.product_name || '';
    if (selectedId) {
      if (!ts.options[selectedId]) ts.addOption({ id: selectedId, name: selectedName || '(current product)' });
      ts.setValue(selectedId, true);
      if (hiddenName) hiddenName.value = '';
    }
  }
}

/** Preview for bildeopplasting */
function showPreview(previewEl, currentEl, file) {
  if (!previewEl || !file) return;
  try {
    const url = URL.createObjectURL(file);
    previewEl.src = url;
    previewEl.classList.remove('d-none');
    if (currentEl) currentEl.classList.add('d-none');
  } catch {}
}

/** Drag & drop + guarded picker */
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

    if (zone) zone.addEventListener('click', (e) => {
      const realInput = e.target.closest('input[type="file"][data-file-input]');
      if (realInput) return;
      openDialog(e);
    });
    input.addEventListener('click', (e) => e.stopPropagation(), { capture: true });

    input.addEventListener('change', () => {
      const f = input.files && input.files[0];
      if (!f) return;
      if (nameEl) nameEl.textContent = f.name;
      showPreview(previewEl, currentEl, f);
      zone?.classList.remove('drop-active');
    });

    const stop = (e) => { e.preventDefault(); e.stopPropagation(); };
    const onDragOver = (e) => { stop(e); zone?.classList.add('drop-active'); };
    const onDragLeave = (e) => { stop(e); if (e.target === zone) zone?.classList.remove('drop-active'); };
    const onDrop = (e) => {
      stop(e);
      zone?.classList.remove('drop-active');
      const files = e.dataTransfer?.files;
      if (!files?.length) return;
      const f = files[0];
      if (!/^image\//i.test(f.type)) { alert('Please drop an image file.'); return; }
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

/** Åpne modal fra klikket element */
async function openFromElement(btn) {
  const template = btn.dataset.modalTemplate;
  if (!template) { console.error('[modalHub] Missing data-modal-template'); return; }

  // Dataset -> context (+ snake_case for xxxId)
  const datasetCtx = { ...btn.dataset };
  for (const k of Object.keys(btn.dataset)) {
    if (k.includes('-')) datasetCtx[k.replace(/-/g, '_')] = btn.dataset[k];
    if (/^[a-z]+Id$/.test(k)) datasetCtx[k.replace(/Id$/, '_id')] = btn.dataset[k];
  }

  const title       = btn.dataset.modalTitle || '';
  const submitLabel = btn.dataset.modalSubmitLabel || '';
  const formIdAttr  = btn.dataset.modalFormId || null;

  const preset  = parseJSON(btn.dataset.modalPreset, {});
  const sources = parseJSON(btn.dataset.modalSources, []);
  const fetched = {};

  // Hent oppgitte kilder
  if (Array.isArray(sources) && sources.length) {
    const jobs = sources.map(async (s) => {
      const key = s.key;
      const url = _tpl(s.url, datasetCtx);
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

  // Kun for gift-order-modal: hent ordren hvis vi har id og ikke allerede har den
  const datasetOrderId = datasetCtx.order_id || datasetCtx.orderId;
  if (datasetOrderId && !fetched.order && template === 'modals/modal_gift_form') {
    try {
      const res = await api(`/api/gift-orders/${datasetOrderId}`);
      fetched.order = unwrapApiPayload(res);
    } catch (e) {
      console.warn('[modalHub] could not fetch order for prefill', e);
    }
  }

  // Prefill deltakere fra ordre (kun relevant hvis vi faktisk har en ordre)
  if (fetched.order && typeof fetched.order === 'object') {
    const g = Array.isArray(fetched.order.givers)     ? fetched.order.givers.map(u => u.id) : [];
    const r = Array.isArray(fetched.order.recipients) ? fetched.order.recipients.map(u => u.id) : [];
    fetched.prefill_giver_ids = g;
    fetched.prefill_recipient_ids = r;
  }

  // Sett eventId i renderData (brukes i ny-opprett av ordre)
  const resolvedEventId = datasetCtx.event_id || datasetCtx.eventId || fetched?.order?.event_id || null;

  const renderData = deepMerge(
    { title, submitLabel, isEdit: !!(fetched.order?.id || preset?.order?.id || datasetOrderId), eventId: resolvedEventId },
    deepMerge(fetched, preset)
  );

  const safeOrderId = renderData?.order?.id || datasetOrderId || '';

  const { modalEl } = await showModal({
    template,
    data: renderData,
    onShown: () => {
      const form = modalEl.querySelector('form');
      if (!form) return;

      // ★★★ VIKTIG GUARD: Bare modalen for gift-orders har data-order-patch
      const isOrderForm = form.hasAttribute('data-order-patch');

      if (isOrderForm) {
        // Vi overstyrer action/method KUN for gift-order-skjemaet
        if (safeOrderId) {
          // PATCH
          form.dataset.method = 'PATCH';
          form.setAttribute('action', `/api/gift-orders/${safeOrderId}`);
          // ikke send event_id ved edit
          form.querySelector('input[name="event_id"]')?.remove();
        } else {
          // POST
          form.dataset.method = 'POST';
          form.setAttribute('action', `/api/gift-orders`);
        }
      } else {
        // For alle andre modaler (f.eks. user): respekter det som står i templaten
        // (IKKE rør action/method)
      }

      if (formIdAttr) form.setAttribute('id', formIdAttr);

      initTomSelects(modalEl, renderData);
      initUploadPickers(modalEl);

      new FormHandler(form, {
        resetOnSuccess: false,
        onSuccess: (resp) => {
          // Emit type avhenger: hvis ordreform -> 'order', ellers les fra data-emit-entity
          const explicitEntity = btn.dataset.emitEntity;
          const entity = isOrderForm ? 'order' : (explicitEntity || 'entity');
          const idPath = btn.dataset.emitIdPath || (isOrderForm ? 'data.gift_order_id' : null);

          emit('entity:changed', {
            entity,
            id: safeOrderId || (idPath ? getPath(resp, idPath) : null) || null,
            op: safeOrderId ? 'update' : 'create',
            event_id: isOrderForm ? (resolvedEventId || undefined) : undefined,
          });
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
