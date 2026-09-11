(() => {
  'use strict';

  const setPasswordVisibility = (button, visible) => {
    const scope = button.closest('.password-control, .auth-input-wrap') || button.parentElement;
    const input = scope?.querySelector('[data-password-input]');
    if (!input) return;

    input.type = visible ? 'text' : 'password';
    button.setAttribute('aria-pressed', visible ? 'true' : 'false');
    button.setAttribute('aria-label', visible ? 'Скрыть пароль' : 'Показать пароль');
    button.title = visible ? 'Скрыть пароль' : 'Показать пароль';

    const icon = button.querySelector('i');
    if (icon) {
      icon.classList.toggle('bi-eye', !visible);
      icon.classList.toggle('bi-eye-slash', visible);
    }
  };

  document.addEventListener('click', event => {
    const button = event.target.closest('[data-password-toggle]');
    if (!button) return;
    event.preventDefault();
    setPasswordVisibility(button, button.getAttribute('aria-pressed') !== 'true');
  });

  // Global network preloader. Every fetch gets its own 100ms threshold, so
  // fast requests never flash the overlay while genuinely slow requests do.
  if (typeof window.fetch === 'function') {
    const nativeFetch = window.fetch.bind(window);
    let slowRequests = 0;
    let overlay = null;

    const getOverlay = () => {
      if (overlay?.isConnected) return overlay;
      overlay = document.createElement('div');
      overlay.className = 'matercms-server-preloader';
      overlay.setAttribute('aria-hidden', 'true');
      overlay.innerHTML = '<span class="matercms-server-preloader-spinner"></span>';
      document.body.appendChild(overlay);
      return overlay;
    };

    const show = () => {
      slowRequests += 1;
      getOverlay().classList.add('is-visible');
    };

    const hide = () => {
      slowRequests = Math.max(0, slowRequests - 1);
      if (slowRequests === 0) getOverlay().classList.remove('is-visible');
    };

    window.fetch = (...args) => {
      let thresholdReached = false;
      const timer = window.setTimeout(() => {
        thresholdReached = true;
        show();
      }, 100);

      return nativeFetch(...args).finally(() => {
        window.clearTimeout(timer);
        if (thresholdReached) hide();
      });
    };
  }
})();
