// src/public/app/assets/js/tomSelectInit.js
// src/public/app/assets/js/tomSelectInit.js
import { api } from './Remote.js';

// Init Tom Selects inside a modal/root: product-single + users-multi
export function initProductSelect(root) {
  if (!root || typeof window.TomSelect !== 'function') return () => {};

  const destroyers = [];

  /* ---------- product-single ---------- */
  const prodSelects = Array.from(root.querySelectorAll('select[data-tomselect="product-single"]'));
  for (const el of prodSelects) {
    const preId = el.getAttribute('data-selected-product-id') || '';
    const preName = el.getAttribute('data-selected-product-name') || '';
    const hiddenNameEl = root.querySelector('[data-product-name]');

    const setAsExisting = (id) => {
      el.value = id || '';
      if (hiddenNameEl) hiddenNameEl.value = '';
    };
    const setAsNew = (name) => {
      el.value = '';
      if (hiddenNameEl) hiddenNameEl.value = name || '';
    };

    const ts = new window.TomSelect(el, {
      valueField: 'value',
      labelField: 'text',
      searchField: ['text'],
      maxItems: 1,
      createOnBlur: true,
      persist: false,
      preload: preId ? true : 'focus',
      placeholder: 'Type to search or create…',
      create: (input) => {
        const name = (input || '').trim();
        if (!name) return null;
        return { value: `__new__:${name}`, text: name };
      },
      load: async (query, cb) => {
        try {
          const q = (query || '').trim();
          const url = q.length
            ? `/api/products?limit=20&q=${encodeURIComponent(q)}`
            : '/api/products?limit=20';
          const res = await api(url);
          const arr = (res?.data?.products || []).map(p => ({
            value: p.id,
            text: p.name,
            image_url: p.image_url || null,
            price: p.price || null
          }));
          cb(arr);
        } catch (e) {
          console.error('[tomselect] product load error', e);
          cb();
        }
      },
      render: {
		option: (data) => {
			const img = data.image_url
			? `<img src="${data.image_url}" class="ts-thumb me-2">`
			: `<span class="ts-thumb--placeholder me-2">🛍️</span>`;
			const price = data.price ? `<small class="text-muted ms-1">(${data.price})</small>` : '';
			return `<div class="d-flex align-items-center">${img}<span>${data.text}${price}</span></div>`;
		},
		item: (data) => {
			const img = data.image_url
			? `<img src="${data.image_url}" class="ts-thumb me-1">`
			: `<span class="ts-thumb--placeholder me-1">🛍️</span>`;
			return `<div class="d-flex align-items-center">${img}${data.text}</div>`;
		}
		},
      onChange: (val) => {
        if (!val) { setAsNew(''); return; }
        if (typeof val === 'string' && val.startsWith('__new__:')) {
          const name = val.substring('__new__:'.length);
          setAsNew(name);
        } else {
          setAsExisting(val);
        }
      },
      onInitialize: function () {
        if (preId) {
          if (!this.options[preId]) this.addOption({ value: preId, text: preName || preId });
          this.setValue(preId, true);
          setAsExisting(preId);
        } else if (preName) {
          const v = `__new__:${preName}`;
          if (!this.options[v]) this.addOption({ value: v, text: preName });
          this.setValue(v, true);
          setAsNew(preName);
        }
      }
    });

    destroyers.push(() => { try { ts.destroy(); } catch {} });
  }

  /* ---------- users-multi ---------- */
  const userSelects = Array.from(root.querySelectorAll('select[data-tomselect="users-multi"]'));
  for (const el of userSelects) {
    // Les preselect FØR TomSelect opprettes
    const preOpts = Array.from(el.querySelectorAll('option[selected]')).map(opt => ({
      value: opt.value,
      text:  (opt.textContent || opt.value).trim(),
      email: '',
      profile_image_url: ''
    }));
    const preItems = preOpts.map(o => o.value);

    const ts = new window.TomSelect(el, {
      valueField: 'value',
      labelField: 'text',
      searchField: ['text', 'email'],
      maxItems: null,
      create: false,
      persist: false,
      preload: 'focus',
      placeholder: 'Select people…',
      plugins: ['remove_button'],
      closeAfterSelect: true,

      // forhåndsvalg
      options: preOpts,
      items: preItems,

      render: {
		option: (data) => {
			const img = data.profile_image_url
			? `<img src="${data.profile_image_url}" class="ts-avatar me-2">`
			: `<span class="ts-avatar--initials me-2">${(data.text || '?').slice(0,2)}</span>`;
			const email = data.email ? ` <small class="text-muted">(${data.email})</small>` : '';
			return `<div class="d-flex align-items-center">${img}<span>${data.text}${email}</span></div>`;
		},
		item: (data) => {
			const img = data.profile_image_url
			? `<img src="${data.profile_image_url}" class="ts-avatar me-1">`
			: `<span class="ts-avatar--initials me-1">${(data.text || '?').slice(0,2)}</span>`;
			return `<div class="d-flex align-items-center">${img}${data.text}</div>`;
		}
		},


      load: async (query, cb) => {
        try {
          const res = await api('/api/users');
          const users = res?.data?.users || res?.data?.rows || res?.users || [];
          const q = (query || '').toLowerCase();
          const mapped = users.map(u => ({
            value: u.id,
            text: u.display_name || `${u.firstname || ''} ${u.lastname || ''}`.trim() || u.email || 'User',
            email: u.email || '',
            profile_image_url: u.profile_image_url || null
          }));
          const filtered = q
            ? mapped.filter(x => x.text.toLowerCase().includes(q) || x.email.toLowerCase().includes(q))
            : mapped;

          // Sørg for at allerede valgte items finnes i options (dupes ignoreres)
          if (preOpts.length) {
            for (const o of preOpts) { if (!ts.options[o.value]) ts.addOption(o); }
          }
          cb(filtered);
        } catch (e) {
          console.error('[tomselect] users load error', e);
          cb();
        }
      },

      // 🚀 Fjern søketekst etter valg
      onItemAdd: function () {
        this.clearActiveItems();
        this.setTextboxValue('');
        this.refreshOptions();
      }
    });

    destroyers.push(() => { try { ts.destroy(); } catch {} });
  }

  // Unbind/destroy
  return () => { for (const d of destroyers) { try { d(); } catch {} } };
}
