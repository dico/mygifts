// Handler for gift URL import
import { api } from './Remote.js';
import { emit } from './eventBus.js';
import { closeModal } from './modalMini.js';

export function bindGiftUrlImportModal(modalEl) {
  console.log('[GiftUrlImport] Binding modal handler');

  const form = modalEl.querySelector('#giftForm');
  if (!form || form.__giftUrlImportBound) return;
  form.__giftUrlImportBound = true;

  const urlInput = modalEl.querySelector('#giftProductUrl');
  const fetchBtn = modalEl.querySelector('#giftFetchMetadataBtn');
  const fetchingIndicator = modalEl.querySelector('#giftFetchingIndicator');
  const productPreview = modalEl.querySelector('#giftProductPreview');
  const errorEl = modalEl.querySelector('.gift-error-message');

  // Form fields
  const nameInput = modalEl.querySelector('#giftProductName');
  const descInput = modalEl.querySelector('#giftProductDescription');
  const priceInput = modalEl.querySelector('#giftProductPrice');
  const imageUrlInput = modalEl.querySelector('#giftProductImageUrl');
  const sourceUrlInput = modalEl.querySelector('#giftProductSourceUrl');
  const sourceTitleInput = modalEl.querySelector('#giftProductSourceTitle');
  const productImage = modalEl.querySelector('#giftProductImage');
  const imageContainer = modalEl.querySelector('#giftProductImageContainer');
  const duplicateWarning = modalEl.querySelector('#giftDuplicateWarning');
  const existingProductIdInput = modalEl.querySelector('#giftExistingProductId');
  const productIdField = modalEl.querySelector('#giftProductIdField');

  // Tab elements
  const urlImportTab = modalEl.querySelector('#paneGiftUrl');

  // Fetch metadata button click
  if (fetchBtn) {
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
      if (duplicateWarning) duplicateWarning.classList.add('d-none');

      // Show loading
      fetchingIndicator.classList.remove('d-none');
      productPreview.classList.add('d-none');
      fetchBtn.disabled = true;

      try {
        // Call backend API to extract metadata
        const response = await api('/api/products/extract-url', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ url })
        });

        const metadata = response.data || response;

        console.log('[GiftUrlImport] Metadata:', metadata);

        // Store the original URL and title (for duplicate detection)
        sourceUrlInput.value = url;
        sourceTitleInput.value = metadata.title || '';

        // Check for duplicate product by URL first, then by name
        const existingProduct = await checkDuplicateProduct(url, metadata.title);

        // Populate form fields: use existing product data if found, otherwise use scraped metadata
        if (existingProduct) {
          // Use existing product's data (including edited title)
          nameInput.value = existingProduct.name || '';
          descInput.value = existingProduct.description || '';
          priceInput.value = existingProduct.default_price || '';
          if (existingProduct.image_url) {
            imageUrlInput.value = existingProduct.image_url;
            productImage.src = existingProduct.image_url;
            imageContainer.style.display = 'block';
          } else {
            imageContainer.style.display = 'none';
          }
        } else {
          // Use freshly scraped metadata
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
        }

        // Show preview
        productPreview.classList.remove('d-none');

        // Focus on name field
        nameInput.focus();

      } catch (err) {
        console.error('[GiftUrlImport] Fetch error:', err);
        showError(err.message || 'Failed to fetch product information from URL');
        productPreview.classList.add('d-none');
      } finally {
        fetchingIndicator.classList.add('d-none');
        fetchBtn.disabled = false;
      }
    });
  }

  // Check for duplicate product by name and domain
  async function checkDuplicateProduct(productUrl, productName) {
    try {
      if (!productUrl || !productName) {
        existingProductIdInput.value = '';
        return null;
      }

      // Call backend endpoint to check for duplicate by name + domain
      const response = await api('/api/products/find-by-name-domain', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: productName, url: productUrl })
      });

      const product = response.data?.product || response.product || null;

      if (product) {
        console.log('[GiftUrlImport] Found duplicate product by name+domain:', product);
        if (duplicateWarning) duplicateWarning.classList.remove('d-none');
        existingProductIdInput.value = product.id;
        return product;
      }
    } catch (err) {
      // 404 is expected when no duplicate found
      if (err.status !== 404) {
        console.warn('[GiftUrlImport] Duplicate check failed:', err);
      }
    }

    existingProductIdInput.value = '';
    return null;
  }

  // Allow Enter key in URL field to trigger fetch
  if (urlInput) {
    urlInput.addEventListener('keypress', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        fetchBtn.click();
      }
    });
  }

  // Intercept form submission to handle product creation from URL tab
  const originalSubmitHandler = async (e) => {
    e.preventDefault();

    // Check which tab is active
    const isUrlImportActive = urlImportTab && urlImportTab.classList.contains('active');

    if (isUrlImportActive) {
      // URL import flow - create/select product first, then submit gift order
      await handleUrlImportSubmit();
    } else {
      // Manual entry flow - submit as normal
      await handleManualSubmit();
    }
  };

  form.addEventListener('submit', originalSubmitHandler);

  async function handleUrlImportSubmit() {
    const name = nameInput.value.trim();
    if (!name) {
      showError('Product name is required');
      return;
    }

    hideError();

    try {
      let productId = existingProductIdInput.value;

      // If no existing product, create new one
      if (!productId) {
        const productPayload = {
          name: name,
          source_title: sourceTitleInput.value.trim() || name, // Original scraped title
          description: descInput.value.trim() || null,
          default_price: priceInput.value.trim() || null,
          image_url: imageUrlInput.value.trim() || null,
          url: sourceUrlInput.value.trim() || null,
        };

        console.log('[GiftUrlImport] Creating product:', productPayload);

        const productResponse = await api('/api/products', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(productPayload)
        });

        productId = productResponse.data?.product_id || productResponse.product_id || productResponse.data?.id || productResponse.id;

        // Emit product creation event
        emit('entity:changed', {
          entity: 'product',
          id: productId,
          op: 'create'
        });
      }

      // Set the product_id in the form
      if (productIdField) {
        productIdField.value = productId;
      }

      // Now submit the gift order with all form fields
      await handleManualSubmit();

    } catch (err) {
      console.error('[GiftUrlImport] Product creation error:', err);
      showError(err.message || 'Failed to create product');
    }
  }

  async function handleManualSubmit() {
    // Get form data
    const formData = new FormData(form);
    const payload = {};

    // Convert FormData to object
    for (const [key, value] of formData.entries()) {
      if (key.endsWith('[]')) {
        const arrayKey = key.slice(0, -2);
        if (!payload[arrayKey]) payload[arrayKey] = [];
        payload[arrayKey].push(value);
      } else {
        payload[key] = value || null;
      }
    }

    const action = form.getAttribute('action');
    const method = form.dataset.method || 'POST';

    console.log('[GiftUrlImport] Submitting gift order:', payload);

    try {
      const response = await api(action, {
        method: method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });

      const orderId = response.data?.gift_order_id || response.gift_order_id || response.data?.order_id || response.order_id || response.data?.id || response.id;

      // Emit entity change event
      emit('entity:changed', {
        entity: 'order',
        id: orderId,
        op: method === 'PATCH' ? 'update' : 'create'
      });

      // Close modal
      closeModal();

    } catch (err) {
      console.error('[GiftUrlImport] Submit error:', err);
      showError(err.message || 'Failed to save gift order');
    }
  }

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
