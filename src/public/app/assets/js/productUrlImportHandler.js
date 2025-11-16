// Handler for product URL import modal
import { api } from './Remote.js';
import { emit } from './eventBus.js';
import { closeModal } from './modalMini.js';

export function bindProductUrlImportModal(modalEl) {
  console.log('[ProductUrlImport] Binding modal handler');

  const form = modalEl.querySelector('#productUrlImportForm');
  if (!form || form.__urlImportBound) return;
  form.__urlImportBound = true;

  const urlInput = modalEl.querySelector('#productUrl');
  const fetchBtn = modalEl.querySelector('#fetchMetadataBtn');
  const fetchingIndicator = modalEl.querySelector('#fetchingIndicator');
  const productPreview = modalEl.querySelector('#productPreview');
  const errorEl = modalEl.querySelector('.error-message');
  const saveBtn = modalEl.querySelector('#saveProductBtn');

  // Form fields
  const nameInput = modalEl.querySelector('#productName');
  const descInput = modalEl.querySelector('#productDescription');
  const priceInput = modalEl.querySelector('#productPrice');
  const imageUrlInput = modalEl.querySelector('#productImageUrl');
  const sourceUrlInput = modalEl.querySelector('#productSourceUrl');
  const sourceTitleInput = modalEl.querySelector('#productSourceTitle');
  const productImage = modalEl.querySelector('#productImage');
  const imageContainer = modalEl.querySelector('#productImageContainer');

  // Fetch metadata button click
  fetchBtn.addEventListener('click', async () => {
    const url = urlInput.value.trim();
    if (!url) {
      showError('Please enter a URL');
      return;
    }

    // Validate URL format
    try {
      new URL(url);
    } catch (e) {
      showError('Please enter a valid URL');
      return;
    }

    // Hide previous errors
    hideError();

    // Show loading
    fetchingIndicator.classList.remove('d-none');
    productPreview.classList.add('d-none');
    fetchBtn.disabled = true;
    saveBtn.disabled = true;

    try {
      // Call backend API to extract metadata
      const response = await api('/api/products/extract-url', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ url })
      });

      const metadata = response.data || response;

      console.log('[ProductUrlImport] Metadata:', metadata);

      // Populate form fields
      if (metadata.title) nameInput.value = metadata.title;
      if (metadata.description) descInput.value = metadata.description;
      if (metadata.price) priceInput.value = metadata.price;
      if (metadata.image_url) {
        imageUrlInput.value = metadata.image_url;
        productImage.src = metadata.image_url;
        imageContainer.style.display = 'block';
      } else {
        imageContainer.style.display = 'none';
      }

      // Store the original URL and title
      sourceUrlInput.value = url;
      sourceTitleInput.value = metadata.title || '';

      // Show preview
      productPreview.classList.remove('d-none');
      saveBtn.disabled = false;

      // Focus on name field
      nameInput.focus();

    } catch (err) {
      console.error('[ProductUrlImport] Fetch error:', err);
      showError(err.message || 'Failed to fetch product information from URL');
      productPreview.classList.add('d-none');
    } finally {
      fetchingIndicator.classList.add('d-none');
      fetchBtn.disabled = false;
    }
  });

  // Allow Enter key in URL field to trigger fetch
  urlInput.addEventListener('keypress', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      fetchBtn.click();
    }
  });

  // Form submission
  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    const name = nameInput.value.trim();
    if (!name) {
      showError('Product name is required');
      return;
    }

    hideError();

    // Show loading state
    saveBtn.disabled = true;
    const origHtml = saveBtn.innerHTML;
    saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving...';

    try {
      // Prepare payload
      const payload = {
        name: name,
        source_title: sourceTitleInput.value.trim() || name,
        description: descInput.value.trim() || null,
        default_price: priceInput.value.trim() || null,
        image_url: imageUrlInput.value.trim() || null,
        url: sourceUrlInput.value.trim() || null,
      };

      console.log('[ProductUrlImport] Saving product:', payload);

      const response = await api('/api/products', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });

      const productId = response.data?.product_id || response.product_id || response.data?.id || response.id;

      // Emit entity change event
      emit('entity:changed', {
        entity: 'product',
        id: productId,
        op: 'create'
      });

      // Close modal
      closeModal();

    } catch (err) {
      console.error('[ProductUrlImport] Save error:', err);
      showError(err.message || 'Failed to save product');
    } finally {
      saveBtn.disabled = false;
      saveBtn.innerHTML = origHtml;
    }
  });

  function showError(message) {
    if (errorEl) {
      errorEl.textContent = message;
      errorEl.classList.remove('d-none');
    }
  }

  function hideError() {
    if (errorEl) {
      errorEl.textContent = '';
      errorEl.classList.add('d-none');
    }
  }
}
