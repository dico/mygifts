// src/public/app/assets/js/pages/products.js
import { api } from '../Remote.js';
import { render } from '../view.js';
import { on } from '../eventBus.js';

let unbind = null;
let offChanged = null;
let currentQ = '';
let debounceTimer = null;

// Søk handler (defined outside mount so it can be reused)
const onInput = (e) => {
  currentQ = (e?.target?.value ?? '').trim();
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => load(currentQ), 200);
};

// Attach search listener (called after each render)
function attachSearchListener() {
  const sEl = document.getElementById('prodSearch');
  if (sEl) {
    sEl.removeEventListener('input', onInput); // remove old listener if any
    sEl.addEventListener('input', onInput);
  }
}

// Toggle search field visibility
function attachToggleListener() {
  const toggleBtn = document.getElementById('toggleSearchBtn');
  const searchContainer = document.getElementById('searchContainer');
  const searchInput = document.getElementById('prodSearch');

  if (toggleBtn && searchContainer && searchInput) {
    toggleBtn.addEventListener('click', () => {
      const isHidden = searchContainer.style.display === 'none' ||
                       window.getComputedStyle(searchContainer).display === 'none';

      if (isHidden) {
        searchContainer.style.display = 'block';
        // Focus the input when showing - use requestAnimationFrame for better reliability
        requestAnimationFrame(() => {
          searchInput.focus();
        });
      } else {
        searchContainer.style.display = 'none';
        // Clear search when hiding
        if (currentQ) {
          searchInput.value = '';
          currentQ = '';
          load('');
        }
      }
    });
  }
}

export async function mount() {
  document.title = 'Products · MyGifts';
  await render('products', { title: 'Products', loading: true, q: currentQ, products: [] });
  attachSearchListener(); // Attach listener after initial render
  attachToggleListener(); // Attach toggle listener after initial render
  await load(currentQ);

  const root = document.getElementById('app');

  // Sletting
  const onClick = async (e) => {
    const delBtn = e.target.closest('[data-delete="product"]');
    if (delBtn && root.contains(delBtn)) {
      const id = delBtn.getAttribute('data-id');
      if (!id) return;
      if (!confirm('Delete this product?')) return;
      try {
        await api(`/api/products/${id}`, { method: 'DELETE' });
        await load(currentQ);
      } catch (err) {
        console.error('[products] delete error', err);
        alert('Failed to delete product.');
      }
    }
  };
  root.addEventListener('click', onClick);

  // Refresh ved create/update/upload/delete via modaler
  offChanged = on('entity:changed', (p) => {
    if (p?.entity === 'product') load(currentQ);
  });

  unbind = () => {
    const sEl = document.getElementById('prodSearch');
    if (sEl) sEl.removeEventListener('input', onInput);
    root.removeEventListener('click', onClick);
  };
}

export function unmount() {
  if (unbind) { try { unbind(); } catch {} }
  if (offChanged) { try { offChanged(); } catch {} }
  unbind = null;
  offChanged = null;
}

async function load(q = '') {
  try {
    const params = new URLSearchParams();
    if (q) params.set('q', q);
    params.set('limit', '50');

    // Store focus state before render
    const searchInput = document.getElementById('prodSearch');
    const hadFocus = searchInput && document.activeElement === searchInput;
    const cursorPos = hadFocus ? searchInput.selectionStart : null;

    const url = `/api/products?${params.toString()}`;
    const res = await api(url);
    let products = res?.data?.products || res?.products || [];

    // Sort by last modified (updated_at or created_at) - most recent first
    products = products.sort((a, b) => {
      const dateA = new Date(a.updated_at || a.created_at || 0);
      const dateB = new Date(b.updated_at || b.created_at || 0);
      return dateB - dateA; // Descending order (newest first)
    });

    await render('products', { title: 'Products', loading: false, q, products });
    attachSearchListener(); // Re-attach listener after render
    attachToggleListener(); // Re-attach toggle listener after render

    // Restore focus and cursor position if input had focus before
    if (hadFocus) {
      const newSearchInput = document.getElementById('prodSearch');
      if (newSearchInput) {
        newSearchInput.focus();
        if (cursorPos !== null) {
          newSearchInput.setSelectionRange(cursorPos, cursorPos);
        }
      }
    }
  } catch (e) {
    console.error('[products] load failed', e);
    await render('products', { title: 'Products', loading: false, q, products: [] });
    attachSearchListener(); // Re-attach listener after render
    attachToggleListener(); // Re-attach toggle listener after render
  }
}
