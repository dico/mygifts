// src/public/app/assets/js/modalRenderer.js
const root = document.getElementById('modal-root');
const templateCache = new Map();

async function fetchTemplate(name) {
  if (templateCache.has(name)) return templateCache.get(name);
  // Liten cache-buster + bedre feilhåndtering
  const url = `/app/templates/${name}.jsr`;
  const link = url + (url.includes('?') ? '&' : '?') + `_=${Date.now()}`;
  const res = await fetch(link, { cache: 'no-cache' });
  const txt = await res.text();

  // Hvis serveren pga. .htaccess fallback svarer med index.html, stopp
  if (!res.ok || /<html[^>]*>/i.test(txt)) {
    console.error('[modalRenderer] Template fetch failed:', url, 'status=', res.status, 'body starts with:', txt.slice(0,120));
    throw new Error(`Failed to fetch modal template "${name}"`);
  }

  templateCache.set(name, txt);
  return txt;
}

export async function showModal({ template, data = {}, onShown, onHidden }) {
  const tpl = await fetchTemplate(template);
  const html = window.jsrender.templates(tpl).render(data);

  root.innerHTML = `
    <div class="modal fade" tabindex="-1" id="globalModal">
      <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">${html}</div>
      </div>
    </div>
  `;

  const modalEl = document.getElementById('globalModal');
  const bs = new bootstrap.Modal(modalEl, { backdrop: 'static', keyboard: false });

  if (onShown)  modalEl.addEventListener('shown.bs.modal', onShown,  { once: true });
  if (onHidden) modalEl.addEventListener('hidden.bs.modal', onHidden, { once: true });

  bs.show();
  return { modalEl, bs };
}

export function closeModal() {
  const modalEl = document.getElementById('globalModal');
  if (!modalEl) return;
  const bs = bootstrap.Modal.getInstance(modalEl);
  bs?.hide();
}
