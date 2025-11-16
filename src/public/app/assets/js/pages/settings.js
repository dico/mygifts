// src/public/app/assets/js/pages/settings.js
import { render } from '../view.js';

export async function mount() {
  document.title = 'Settings · MyGifts';
  await render('settings', {});

  // Populate user email from navbar (already set by app.js)
  const userLabel = document.getElementById('userLabel');
  const settingsEmail = document.getElementById('settingsEmail');
  if (userLabel && settingsEmail) {
    const email = userLabel.textContent;
    if (email && email !== 'Signed in') {
      settingsEmail.textContent = email;
    }
  }
}

export function unmount() {
  // No cleanup needed
}
