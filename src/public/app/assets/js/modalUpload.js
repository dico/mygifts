// Generisk opplasting for former med data-send="formdata" (multipart/form-data).
// Valgfri styring via data-attributter på form:
// - data-file-field:    navnet på file-feltet (default "file")
// - data-emit-entity:   navn på entitet som skal emittes (valgfritt)
// - data-emit-id-path:  dot-path i responsen for ID som emittes (valgfritt, f.eks. "data.user_id")
// - data-emit-id-fixed: fast ID (f.eks. "{{:user.id}}") om responsen ikke gir ID
// - data-delete-url:    eksplisitt URL for DELETE (valgfritt; faller tilbake til form action)
// - data-success-close: "true" for å auto-lukke modal (default true)

import { api, apiForm } from './Remote.js';
import { emit } from './eventBus.js';
import { closeModal } from './modalMini.js';

function getByPath(obj, path) {
  if (!path) return obj;
  return path.split('.').reduce((cur, k) => (cur && k in cur) ? cur[k] : undefined, obj);
}

function deriveEntityAndIdFromAction(action) {
  // Prøv å hente /api/<entities>/<ULID>/... og singularize entities -> entity
  const m = action.match(/\/api\/([a-z-]+)\/([0-9A-HJKMNP-TV-Z]{26})\b/i);
  if (!m) return { entity: null, id: null };
  const plural = m[1];
  const id = m[2];
  let entity = plural.replace(/s$/, '').replace(/-([a-z])/g, (_, c) => c.toUpperCase());
  return { entity, id };
}

function wireDropzone(zone, fileInput, previewImg, currentImg, fileNameEl) {
  // Klikk hvor som helst i sonen åpner filvelger
  zone.addEventListener('click', (e) => {
    if (e.target.closest('[data-file-input]')) return;
    e.preventDefault();
    fileInput?.click();
  });

  const stop = (e) => { e.preventDefault(); e.stopPropagation(); };

  zone.addEventListener('dragover', (e) => { stop(e); zone.classList.add('drop-active'); });
  zone.addEventListener('dragenter', (e) => { stop(e); zone.classList.add('drop-active'); });

  zone.addEventListener('dragleave', (e) => {
    stop(e);
    const rect = zone.getBoundingClientRect();
    const { clientX:x, clientY:y } = e;
    if (x < rect.left || x > rect.right || y < rect.top || y > rect.bottom) {
      zone.classList.remove('drop-active');
    }
  });

  zone.addEventListener('drop', (e) => {
    stop(e);
    zone.classList.remove('drop-active');
    const files = e.dataTransfer?.files;
    if (!files?.length) return;
    const f = files[0];
    fileInput.files = files;
    showPreview(f, previewImg, currentImg, fileNameEl);
  });

  fileInput.addEventListener('change', () => {
    const f = fileInput.files?.[0];
    if (!f) return;
    showPreview(f, previewImg, currentImg, fileNameEl);
  });
}

function showPreview(file, previewImg, currentImg, fileNameEl) {
  if (!file || !/^image\//i.test(file.type)) return;
  if (fileNameEl) fileNameEl.textContent = file.name;

  const reader = new FileReader();
  reader.onload = (ev) => {
    if (previewImg) {
      previewImg.src = ev.target.result;
      previewImg.classList.remove('d-none');
    }
    if (currentImg) currentImg.classList.add('d-none');
  };
  reader.readAsDataURL(file);
}

export function bindGenericUpload(modalEl) {
  modalEl.querySelectorAll('form[data-send="formdata"]').forEach((form) => {
    if (form.__uploadBound) return;
    form.__uploadBound = true;

    // Sørg for at FormHandler ikke binder seg
    form.setAttribute('data-no-formhandler', 'true');

    const dropzone   = form.querySelector('[data-dropzone]') || form.querySelector('[data-upload-picker]');
    const fileField  = form.dataset.fileField || 'file';
    const fileInput  = form.querySelector(`[data-file-input], input[type="file"][name="${CSS.escape(fileField)}"]`);
    const previewImg = form.querySelector('[data-file-preview]');
    const currentImg = form.querySelector('[data-file-current]');
    const fileNameEl = form.querySelector('[data-file-name]');

    if (dropzone && fileInput) {
      wireDropzone(dropzone, fileInput, previewImg, currentImg, fileNameEl);
    }

    // Slett bilde (valgfritt)
    const removeBtn  = form.querySelector('[data-upload-delete], [data-remove-avatar], [data-remove-image]');
    if (removeBtn) {
      removeBtn.addEventListener('click', async (e) => {
        e.preventDefault();
        const delUrl = form.dataset.deleteUrl || form.getAttribute('action') || '';
        if (!delUrl) return;
        if (!confirm('Remove image?')) return;

        try {
          await api(delUrl, { method: 'DELETE' });

          // Emit change
          const explicitEntity = form.dataset.emitEntity || null;
          const explicitId     = form.dataset.emitIdFixed || null;
          let entity = explicitEntity;
          let id = explicitId;

          if (!entity || !id) {
            const inf = deriveEntityAndIdFromAction(delUrl);
            if (!entity) entity = inf.entity;
            if (!id) id = inf.id;
          }

          if (entity) emit('entity:changed', { entity, id: id || null, op: 'update' });

          const shouldClose = (form.dataset.successClose ?? 'true') === 'true';
          if (shouldClose) closeModal();
        } catch (err) {
          console.error('[upload] delete failed', err);
          alert('Failed to remove image.');
        }
      });
    }

    // Submit = opplasting
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const action = form.getAttribute('action') || '';
      if (!action) return;

      // Krev minst én fil i det definerte feltet
      const hasFile = [...form.querySelectorAll(`input[type="file"][name="${CSS.escape(fileField)}"]`)]
        .some(inp => inp.files && inp.files.length > 0);
      if (!hasFile) {
        alert('Please choose a file.');
        return;
      }

      const data     = new FormData(form);
      const btn      = form.querySelector('button[type="submit"], [data-submit]');
      const origHtml = btn ? btn.innerHTML : null;
      if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Uploading…'; }

      try {
        const resp    = await apiForm(action, data); // boundary settes av browser

        // Finn entity/id – først via eksplisitte data-attributter, ellers fra URL
        const explicitEntity = form.dataset.emitEntity || null;
        const explicitIdPath = form.dataset.emitIdPath || '';
        const explicitIdFix  = form.dataset.emitIdFixed || null;

        let entity = explicitEntity;
        let id = explicitIdFix || (explicitIdPath ? getByPath(resp, explicitIdPath) : null);

        if (!entity || !id) {
          const inf = deriveEntityAndIdFromAction(action);
          if (!entity) entity = inf.entity;
          if (!id) id = inf.id;
        }

        if (entity) emit('entity:changed', { entity, id: id || null, op: 'update' });

        // Oppdater forhåndsvisning dersom server gir endelig URL
        const url = getByPath(resp, 'data.url') || getByPath(resp, 'url');
        if (url && previewImg) previewImg.src = url;

        const shouldClose = (form.dataset.successClose ?? 'true') === 'true';
        if (shouldClose) closeModal();
      } catch (err) {
        console.error('[upload] error', err);
        alert('Upload failed.');
      } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
      }
    });
  });
}
