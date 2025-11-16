// Handler for wishlist URL import
import { api } from './Remote.js';
import { emit } from './eventBus.js';
import { closeModal } from './modalMini.js';

export function bindWishlistUrlImportModal(modalEl) {
  console.log('[WishlistUrlImport] Binding modal handler');

  const form = modalEl.querySelector('#wishlistForm');
  if (!form || form.__wishlistUrlImportBound) return;
  form.__wishlistUrlImportBound = true;

  const urlInput = modalEl.querySelector('#wishlistProductUrl');
  const fetchBtn = modalEl.querySelector('#wishlistFetchMetadataBtn');
  const fetchingIndicator = modalEl.querySelector('#wishlistFetchingIndicator');
  const productPreview = modalEl.querySelector('#wishlistProductPreview');
  const errorEl = modalEl.querySelector('.error-message');
  const saveBtn = modalEl.querySelector('#saveWishlistBtn');

  // Form fields
  const nameInput = modalEl.querySelector('#wishlistProductName');
  const descInput = modalEl.querySelector('#wishlistProductDescription');
  const priceInput = modalEl.querySelector('#wishlistProductPrice');
  const imageUrlInput = modalEl.querySelector('#wishlistProductImageUrl');
  const sourceUrlInput = modalEl.querySelector('#wishlistProductSourceUrl');
  const sourceTitleInput = modalEl.querySelector('#wishlistProductSourceTitle');
  const productImage = modalEl.querySelector('#wishlistProductImage');
  const imageContainer = modalEl.querySelector('#wishlistProductImageContainer');
  const duplicateWarning = modalEl.querySelector('#duplicateWarning');
  const existingProductIdInput = modalEl.querySelector('#wishlistExistingProductId');

  // Tab elements
  const urlImportTab = modalEl.querySelector('#paneUrlImport');
  const manualTab = modalEl.querySelector('#paneManual');

  // Disable save button initially only if we're on URL import tab
  if (urlImportTab && urlImportTab.classList.contains('active')) {
    saveBtn.disabled = true;
  }

  // Enable/disable save button based on active tab
  const tabButtons = modalEl.querySelectorAll('[data-bs-toggle="tab"]');
  tabButtons.forEach(btn => {
    btn.addEventListener('shown.bs.tab', (e) => {
      const targetPane = e.target.getAttribute('data-bs-target');
      if (targetPane === '#paneUrlImport') {
        // URL import tab - disable save until metadata is fetched
        if (!productPreview || productPreview.classList.contains('d-none')) {
          saveBtn.disabled = true;
        }
      } else {
        // Manual tab - enable save button
        saveBtn.disabled = false;
      }
    });
  });

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
    duplicateWarning.classList.add('d-none');

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

      console.log('[WishlistUrlImport] Metadata:', metadata);

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
      saveBtn.disabled = false;

      // Focus on name field
      nameInput.focus();

    } catch (err) {
      console.error('[WishlistUrlImport] Fetch error:', err);
      showError(err.message || 'Failed to fetch product information from URL');
      productPreview.classList.add('d-none');
    } finally {
      fetchingIndicator.classList.add('d-none');
      fetchBtn.disabled = false;
    }
  });

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
        console.log('[WishlistUrlImport] Found duplicate product by name+domain:', product);
        duplicateWarning.classList.remove('d-none');
        existingProductIdInput.value = product.id;
        return product;
      }
    } catch (err) {
      // 404 is expected when no duplicate found
      if (err.status !== 404) {
        console.warn('[WishlistUrlImport] Duplicate check failed:', err);
      }
    }

    existingProductIdInput.value = '';
    return null;
  }

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

    // Check which tab is active
    const isUrlImportActive = urlImportTab && urlImportTab.classList.contains('active');

    if (isUrlImportActive) {
      // URL import flow
      await handleUrlImportSubmit();
    } else {
      // Manual entry flow - use default FormHandler
      await handleManualSubmit();
    }
  });

  async function handleUrlImportSubmit() {
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

        console.log('[WishlistUrlImport] Creating product:', productPayload);

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

      // Now create the wishlist item
      const recipientUserId = form.querySelector('[name="recipient_user_id"]')?.value;

      const wishlistPayload = {
        product_id: productId,
        recipient_user_id: recipientUserId || null,
        links: [sourceUrlInput.value.trim()].filter(Boolean),
        default_price: priceInput.value.trim() || null,
        priority: null,
        notes: null,
      };

      console.log('[WishlistUrlImport] Creating wishlist item:', wishlistPayload);

      const wishlistResponse = await api('/api/wishlists', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(wishlistPayload)
      });

      const wishlistId = wishlistResponse.data?.wishlist_item_id || wishlistResponse.wishlist_item_id || wishlistResponse.data?.id || wishlistResponse.id;

      // Emit wishlist creation event
      emit('entity:changed', {
        entity: 'wishlist',
        id: wishlistId,
        op: 'create'
      });

      // Close modal
      closeModal();

    } catch (err) {
      console.error('[WishlistUrlImport] Save error:', err);
      showError(err.message || 'Failed to save wishlist item');
    } finally {
      saveBtn.disabled = false;
      saveBtn.innerHTML = origHtml;
    }
  }

  async function handleManualSubmit() {
    // Get form data from manual entry tab
    const formData = new FormData(form);
    const payload = {};

    // Convert FormData to object
    for (const [key, value] of formData.entries()) {
      if (key === 'links[]') {
        if (!payload.links) payload.links = [];
        if (value) payload.links.push(value);
      } else {
        payload[key] = value || null;
      }
    }

    // Show loading state
    saveBtn.disabled = true;
    const origHtml = saveBtn.innerHTML;
    saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving...';

    try {
      const action = form.getAttribute('action') || '/api/wishlists';
      const method = form.dataset.method || 'POST';

      console.log('[WishlistUrlImport] Manual submit:', payload);

      const response = await api(action, {
        method: method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });

      const wishlistId = response.data?.wishlist_item_id || response.wishlist_item_id || response.data?.id || response.id;

      // Emit entity change event
      emit('entity:changed', {
        entity: 'wishlist',
        id: wishlistId,
        op: method === 'PATCH' ? 'update' : 'create'
      });

      // Close modal
      closeModal();

    } catch (err) {
      console.error('[WishlistUrlImport] Manual submit error:', err);
      showError(err.message || 'Failed to save wishlist item');
    } finally {
      saveBtn.disabled = false;
      saveBtn.innerHTML = origHtml;
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
