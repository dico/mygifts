// src/public/app/assets/js/Notification.js

class Notification {
  success(text) {
    new Noty({
      theme: 'nest',
      type: 'success',
      layout: 'bottomRight',
      text: `<i class="fas fa-check"></i> ${text}`,
      timeout: 3500
    }).show();
  }

  error(text) {
    new Noty({
      theme: 'nest',
      type: 'error',
      layout: 'topRight',
      text: `<i class="fas fa-exclamation-triangle"></i> ${text}`,
      timeout: 6000
    }).show();
  }

  warning(text) {
    new Noty({
      theme: 'nest',
      type: 'warning',
      layout: 'topRight',
      text: `<i class="far fa-engine-warning"></i> ${text}`,
      timeout: 6000
    }).show();
  }
}

export default new Notification();