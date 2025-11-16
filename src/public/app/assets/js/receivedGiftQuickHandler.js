// Handler for received gift quick-add modal with optional photo upload
import { api, apiForm } from './Remote.js';
import { emit } from './eventBus.js';
import { closeModal } from './modalMini.js';

function getByPath(obj, path) {
  if (!path) return obj;
  return path.split('.').reduce((cur, k) => (cur && k in cur) ? cur[k] : undefined, obj);
}

export function bindReceivedGiftQuickModal(modalEl) {
  console.log('[ReceivedGiftQuick] Binding modal handler');
  const form = modalEl.querySelector('#receivedGiftForm');
  if (!form || form.__receivedGiftBound) return;
  form.__receivedGiftBound = true;

  // Debug: Log recipient hidden inputs
  const recipientInputs = form.querySelectorAll('[name="recipient_user_ids[]"]');
  console.log('[ReceivedGiftQuick] Found', recipientInputs.length, 'recipient input(s)');
  recipientInputs.forEach((input, i) => {
    console.log(`  [${i}] value="${input.value}"`);
  });

  const photoUploadBtn = modalEl.querySelector('#photoUploadBtn');
  const newGiftPhoto = modalEl.querySelector('#newGiftPhoto');
  const photoPreviewContainer = modalEl.querySelector('#photoPreviewContainer');
  const photoPreviewImg = modalEl.querySelector('#photoPreviewImg');
  const removePhotoBtn = modalEl.querySelector('#removePhotoBtn');

  // Existing product preview elements
  const existingProductPreview = modalEl.querySelector('#existingProductPreview');
  const existingProductImg = modalEl.querySelector('#existingProductImg');
  const existingProductSelect = modalEl.querySelector('#existingProductSelect');

  // File selection handler (photo upload button is now a <label> that triggers this automatically)
  if (newGiftPhoto) {
    newGiftPhoto.addEventListener('change', (e) => {
      const file = e.target.files?.[0];
      if (!file) return;

      // Show preview
      const reader = new FileReader();
      reader.onload = (ev) => {
        if (photoPreviewImg) {
          photoPreviewImg.src = ev.target.result;
        }
        if (photoPreviewContainer) {
          photoPreviewContainer.style.display = 'block';
        }
        if (photoUploadBtn) {
          photoUploadBtn.classList.add('d-none');
          photoUploadBtn.style.display = 'none';
        }
      };
      reader.onerror = (err) => {
        console.error('[ReceivedGiftQuick] FileReader error:', err);
      };
      reader.readAsDataURL(file);
    });
  }

  // Remove photo button handler
  if (removePhotoBtn && newGiftPhoto) {
    removePhotoBtn.addEventListener('click', (e) => {
      e.preventDefault();

      // Clear file input
      newGiftPhoto.value = '';

      // Hide preview, show upload button
      if (photoPreviewContainer) {
        photoPreviewContainer.style.display = 'none';
      }
      if (photoUploadBtn) {
        photoUploadBtn.classList.remove('d-none');
        photoUploadBtn.style.display = '';
      }
    });
  }

  // Existing product selection handler - show product image
  if (existingProductSelect) {
    // Wait for TomSelect to initialize, then hook into its onChange
    const checkTomSelect = setInterval(() => {
      const tomSelectInstance = existingProductSelect.tomselect;
      if (tomSelectInstance) {
        clearInterval(checkTomSelect);

        // Listen for product selection changes
        tomSelectInstance.on('change', (value) => {
          if (!value || value.startsWith('__new__:')) {
            // No product selected or new product - hide preview
            if (existingProductPreview) {
              existingProductPreview.style.display = 'none';
            }
            return;
          }

          // Get the selected product data from TomSelect options
          const productData = tomSelectInstance.options[value];
          if (productData && productData.image_url) {
            // Show product image
            if (existingProductImg) {
              existingProductImg.src = productData.image_url;
            }
            if (existingProductPreview) {
              existingProductPreview.style.display = 'block';
            }
          } else {
            // No image - hide preview
            if (existingProductPreview) {
              existingProductPreview.style.display = 'none';
            }
          }
        });
      }
    }, 100);

    // Clear interval after 5 seconds if TomSelect hasn't initialized
    setTimeout(() => clearInterval(checkTomSelect), 5000);
  }

  // Form submit handler
  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    const action = form.getAttribute('action');
    if (!action) {
      console.error('[ReceivedGiftQuick] No action URL');
      return;
    }

    const method = (form.dataset.method || 'POST').toUpperCase();
    const hasPhoto = newGiftPhoto?.files?.length > 0;

    // Get active tab to determine if we're adding new gift or selecting existing product
    const activeTab = modalEl.querySelector('.tab-pane.active');
    const isNewGift = activeTab?.id === 'paneNewGift';

    // Validate required fields based on active tab
    const errorEl = form.querySelector('.error-message');

    if (isNewGift) {
      const productNameNew = form.querySelector('[name="product_name_new"]');
      if (!productNameNew || !productNameNew.value.trim()) {
        if (errorEl) {
          errorEl.textContent = 'Please enter a gift name';
          errorEl.classList.remove('d-none');
        } else {
          alert('Please enter a gift name');
        }
        return;
      }
    } else {
      const productId = form.querySelector('[name="product_id"]');
      if (!productId || !productId.value) {
        if (errorEl) {
          errorEl.textContent = 'Please select a product';
          errorEl.classList.remove('d-none');
        } else {
          alert('Please select a product');
        }
        return;
      }
    }

    // Validate giver (required) - check TomSelect for selected values
    const giverSelect = form.querySelector('[name="giver_user_ids[]"]');
    const hasGiver = giverSelect && giverSelect.tomselect && giverSelect.tomselect.items.length > 0;
    if (!hasGiver) {
      if (errorEl) {
        errorEl.textContent = 'Please select who the gift is from';
        errorEl.classList.remove('d-none');
      } else {
        alert('Please select who the gift is from');
      }
      return;
    }

    // Prepare data
    let payload;
    let headers = {};

    if (hasPhoto) {
      // Use FormData for file upload
      payload = new FormData();

      // Add all form fields except the inactive tab's product field and photo (will add photo separately)
      const formData = new FormData(form);
      console.log('[ReceivedGiftQuick] FormData entries BEFORE processing:');
      for (const [key, value] of formData.entries()) {
        console.log('  RAW:', key, '=', typeof value === 'object' ? '[File]' : value);
      }

      const formData2 = new FormData(form); // Re-create since we consumed the iterator above
      for (const [key, value] of formData2.entries()) {
        // Skip product field from inactive tab
        if (isNewGift && key === 'product_id') continue;
        if (!isNewGift && key === 'product_name_new') continue;
        // Skip photo field from form (will add it separately)
        if (key === 'photo') continue;

        // Clean up recipient_user_ids if it has "set:" prefix (workaround for template issue)
        let cleanValue = value;
        if (key === 'recipient_user_ids[]' && typeof value === 'string' && value.startsWith('set:')) {
          cleanValue = value.substring(4); // Remove "set:" prefix
          console.log('[ReceivedGiftQuick] Cleaned recipient ID from', value, 'to', cleanValue);
        }

        // Strip [] suffix for array fields to match backend expectations
        let fieldName = key;
        if (key.endsWith('[]')) {
          fieldName = key.slice(0, -2);
          console.log('[ReceivedGiftQuick] Stripped [] from field name:', key, '->', fieldName);
        }

        console.log('[ReceivedGiftQuick] FormData entry:', fieldName, '=', cleanValue);
        payload.append(fieldName, cleanValue);
      }

      // Add photo file only once
      payload.append('photo', newGiftPhoto.files[0]);

      // Log final payload
      console.log('[ReceivedGiftQuick] Final payload:');
      for (const [key, value] of payload.entries()) {
        console.log('  ', key, '=', typeof value === 'object' ? value.name || '[File]' : value);
      }

      // FormData will set Content-Type with boundary automatically
    } else {
      // Use JSON for simple submission
      payload = {};
      const formData = new FormData(form);

      for (const [key, value] of formData.entries()) {
        // Skip product field from inactive tab
        if (isNewGift && key === 'product_id') continue;
        if (!isNewGift && key === 'product_name_new') continue;

        // Clean up recipient_user_ids if it has "set:" prefix (workaround for template issue)
        let cleanValue = value;
        if (key === 'recipient_user_ids[]' && typeof value === 'string' && value.startsWith('set:')) {
          cleanValue = value.substring(4); // Remove "set:" prefix
        }

        if (key.endsWith('[]')) {
          const arrayKey = key.slice(0, -2);
          if (!payload[arrayKey]) payload[arrayKey] = [];
          payload[arrayKey].push(cleanValue);
        } else {
          payload[key] = cleanValue;
        }
      }

      headers['Content-Type'] = 'application/json';
      payload = JSON.stringify(payload);
    }

    // Hide any previous errors
    if (errorEl) {
      errorEl.textContent = '';
      errorEl.classList.add('d-none');
    }

    // Show loading state
    const submitBtn = form.querySelector('button[type="submit"]');
    const origHtml = submitBtn ? submitBtn.innerHTML : null;
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving...';
    }

    try {
      let resp;

      if (hasPhoto) {
        // Use apiForm for FormData upload (handles authentication automatically)
        resp = await apiForm(action, payload);
      } else {
        // Use api helper for JSON
        resp = await api(action, {
          method: method,
          headers: headers,
          body: payload
        });
      }

      // Emit entity change event
      const emitEntity = form.dataset.emitEntity;
      const emitIdPath = form.dataset.emitIdPath;

      if (emitEntity) {
        const idVal = emitIdPath ? getByPath(resp, emitIdPath) : (resp?.data?.id || null);
        emit('entity:changed', {
          entity: emitEntity,
          id: idVal,
          op: method === 'PATCH' ? 'update' : 'create'
        });
      }

      // Close modal on success
      closeModal();

    } catch (err) {
      console.error('[ReceivedGiftQuick] Submit error:', err);

      if (errorEl) {
        errorEl.textContent = err.message || 'Failed to save gift';
        errorEl.classList.remove('d-none');
      } else {
        alert(err.message || 'Failed to save gift');
      }
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = origHtml;
      }
    }
  });
}
