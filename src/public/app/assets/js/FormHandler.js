// src/public/app/assets/js/FormHandler.js
import { api, apiForm } from './Remote.js';
import notification from './Notification.js';

class FormHandler {
  constructor(formOrElement, config = {}) {
    // Unngå dobbel binding
    if (formOrElement instanceof HTMLElement && formOrElement.dataset.submissionBound === 'true') {
      if (formOrElement.dataset.debugFormHandler === 'true') {
        console.warn('[FormHandler] Already bound - skip', formOrElement);
      }
      return;
    }

    this.config = { resetOnSuccess: true, debug: false, ...config };
    this.debug = !!this.config.debug;

    // Finn skjema
    this.form = typeof formOrElement === 'string'
      ? document.getElementById(formOrElement)
      : formOrElement;

    if (!this.form) throw new Error('[FormHandler] Form not found.');
    this.form.dataset.submissionBound = 'true';

    this.onSuccess = this.config.onSuccess || null;
    this.callbackFunctionName = this.form.getAttribute('data-callback') || this.config.callback || null;
    this.isModal = !!this.form.closest('.modal');

    this.initEventListeners();
    if (this.debug) console.log('[FormHandler] Initialized', this.form);
  }

  initEventListeners() {
    this.form.addEventListener('submit', (e) => {
      e.preventDefault();
      this.handleSubmit();
    });
  }

  async handleSubmit() {
    if (this.debug) console.log('[FormHandler] handleSubmit');
    this.clearErrorMessage();

    // Sync TinyMCE om det finnes
    if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
      tinymce.activeEditor.save();
    }

    const formData = new FormData(this.form);

    const rawMethod = (this.form.dataset.method || this.form.method || 'POST').toUpperCase();
    // Inkluder PATCH i whitelist
    const method = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].includes(rawMethod) ? rawMethod : 'POST';

    const action = this.form.getAttribute('action');
    const sendMode = (this.form.dataset.send || 'json').toLowerCase(); // 'json' | 'formdata'

    try {
      this.setButtonLoading(true);
      let response;

      if (method === 'POST' || method === 'PUT' || method === 'PATCH') {
        if (sendMode === 'formdata') {
          // Bruk apiForm (lar browser sette boundary)
          response = await apiForm(action, formData);
        } else {
          // Bygg JSON payload fra FormData
          const payload = {};
          for (const [key, value] of formData.entries()) {
            if (key.endsWith('[]')) {
              const clean = key.slice(0, -2);
              (payload[clean] ||= []).push(value);
            } else if (key.includes('[') && key.includes(']')) {
              const match = key.match(/^([^\[]+)\[([^\]]+)\]$/);
              if (match) {
                const [, outer, inner] = match;
                (payload[outer] ||= {})[inner] = value;
              } else {
                payload[key] = value;
              }
            } else {
              // Normaliser vanlige 0/1-flags til tall
              payload[key] = (value === '0' || value === '1') ? Number(value) : value;
            }
          }
          response = await api(action, { method, body: payload });
        }
      } else if (method === 'GET') {
        const id = formData.get('id');
        response = await api(id ? `${action}/${id}` : action, { method: 'GET' });
      } else if (method === 'DELETE') {
        const id = formData.get('id');
        response = await api(id ? `${action}/${id}` : action, { method: 'DELETE' });
      } else {
        throw new Error(`Ukjent metode: ${method}`);
      }

      this.handleFormResponse(response);

      // Callback-er
      this.executeCallback(response, this.form);
      if (this.onSuccess) this.onSuccess(response);

      if (response?.status === 'success') {
        window.isFormDirty = false;
        notification?.success?.('Fullført!');

        // Lukk modal hvis aktuelt
        if (this.isModal && window.bootstrap) {
          const modalEl = this.form.closest('.modal');
          const modalInst = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
          modalInst.hide();
          setTimeout(() => document.querySelectorAll('.modal-backdrop').forEach(el => el.remove()), 500);
        }

        // Reset skjema om ønsket
        const shouldReset = this.form.dataset.reset !== 'false' && this.config.resetOnSuccess;
        if (shouldReset) {
          this.form.reset();
          this.form.querySelectorAll('select').forEach(sel => sel.tomselect?.clear());
        }
      }
    } catch (err) {
      this.handleFormError(err);
    } finally {
      this.setButtonLoading(false);
    }
  }

  handleFormResponse(response) {
    if (this.debug) console.log('[FormHandler] handleFormResponse', response);
    this.clearErrorMessage();
  }

  handleFormError(err) {
    if (this.debug) console.log('[FormHandler] handleFormError', err);
    console.error('[FormHandler] Form submission error', err);

    // Finn eller lag error-element
    let errorElement = this.form.querySelector('.error-message');
    if (!errorElement) {
      errorElement = document.createElement('div');
      errorElement.className = 'error-message alert alert-danger';
      const container = this.isModal
        ? this.form.closest('.modal-content')?.querySelector('.modal-body') || this.form
        : this.form;
      container.prepend(errorElement);
    }

    // Bygg brukerrettet feilmelding
    let displayMessage = '';
    if (err.response && err.response.message) {
      displayMessage = err.response.message;
      if (Array.isArray(err.response.errors)) {
        displayMessage += err.response.errors.map(e => `<br> - ${e}`).join('');
      }
    } else if (err.status && err.statusText) {
      displayMessage = `Feil ${err.status}: ${err.statusText}`;
    } else if (err.message) {
      displayMessage = err.message;
    } else {
      displayMessage = 'En ukjent feil oppstod.';
    }

    errorElement.innerHTML = displayMessage;
    notification?.error?.(displayMessage);
  }

  clearErrorMessage() {
    const elm = this.form.querySelector('.error-message');
    if (elm) elm.remove();
  }

  setButtonLoading(loading) {
    const buttons = this.form.querySelectorAll('button[type="submit"]');
    if (loading) {
      this._orig = [];
      buttons.forEach(btn => {
        this._orig.push({ btn, html: btn.innerHTML });
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Laster...';
      });
    } else if (this._orig) {
      this._orig.forEach(({ btn, html }) => {
        btn.disabled = false;
        btn.innerHTML = html;
      });
      this._orig = null;
    }
  }

  executeCallback(response, form) {
    if (this.debug) {
      console.log('[FormHandler] executeCallback');
      console.log('[FormHandler] Form dataset:', form.dataset);
    }

    const callbackFunctionName = form.getAttribute('data-callback') || this.config.callback || null;
    if (!callbackFunctionName) return;

    let callbackFunction = null;

    if (typeof callbackFunctionName === 'function') {
      callbackFunction = callbackFunctionName;
    } else if (typeof callbackFunctionName === 'string') {
      if (typeof window[callbackFunctionName] === 'function') {
        callbackFunction = window[callbackFunctionName];
      } else if (callbackFunctionName.includes('.')) {
        try {
          const parts = callbackFunctionName.split('.');
          let context = window;
          for (const part of parts) {
            if (!context[part]) { context = null; break; }
            context = context[part];
          }
          if (typeof context === 'function') callbackFunction = context;
        } catch (e) {
          if (this.debug) console.warn('[FormHandler] Error resolving nested callback:', e);
        }
      } else if (window.componentRefreshers && typeof window.componentRefreshers[callbackFunctionName] === 'function') {
        callbackFunction = window.componentRefreshers[callbackFunctionName];
      }
    }

    if (typeof callbackFunction === 'function') {
      try {
        callbackFunction(response, form);
      } catch (ex) {
        console.error('[FormHandler] Error executing callback-function:', callbackFunctionName, ex);
      }
    } else if (this.debug) {
      console.warn('[FormHandler] No valid callback found for:', callbackFunctionName);
    }
  }

  // Praktisk helper for modaler
  static addModalFormListeners(config = {}) {
    document.querySelectorAll('form[data-callback]:not([data-no-formhandler])')
      .forEach(f => new FormHandler(f, config));

    document.addEventListener('shown.bs.modal', e => {
      e.target.querySelectorAll('form[data-callback]:not([data-no-formhandler])')
        .forEach(f => new FormHandler(f, config));
    });
  }
}

export default FormHandler;
