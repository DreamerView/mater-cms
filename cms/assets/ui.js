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
})();
