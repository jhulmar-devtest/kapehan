(function () {
  'use strict';

  function enhancePasswordFields(root) {
    const scope = root && root.querySelectorAll ? root : document;
    scope.querySelectorAll('input[type="password"]:not([data-no-password-toggle])').forEach(function (input) {
      if (input.dataset.passwordToggleEnhanced === 'true') return;

      // Settings and reset-password already have purpose-built controls.
      if (input.closest('.password-wrapper')) return;

      const wrapper = document.createElement('span');
      wrapper.className = 'password-toggle-wrap';
      input.parentNode.insertBefore(wrapper, input);
      wrapper.appendChild(input);
      input.dataset.passwordToggleEnhanced = 'true';
      input.dataset.passwordToggleInput = 'true';

      if (!input.id) input.id = 'password-field-' + Math.random().toString(36).slice(2, 10);

      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'password-toggle-control';
      button.innerHTML = '<i class="fa-solid fa-eye" aria-hidden="true"></i>';
      button.setAttribute('aria-label', 'Show password');
      button.setAttribute('aria-controls', input.id);
      button.setAttribute('aria-pressed', 'false');
      button.title = 'Show password';

      button.addEventListener('click', function () {
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.innerHTML = show
          ? '<i class="fa-solid fa-eye-slash" aria-hidden="true"></i>'
          : '<i class="fa-solid fa-eye" aria-hidden="true"></i>';
        button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        button.setAttribute('aria-pressed', String(show));
        button.title = show ? 'Hide password' : 'Show password';
      });

      wrapper.appendChild(button);
    });
  }

  function initialize() {
    enhancePasswordFields(document);
    const observer = new MutationObserver(function (mutations) {
      mutations.forEach(function (mutation) {
        mutation.addedNodes.forEach(function (node) {
          if (node.nodeType !== Node.ELEMENT_NODE) return;
          if (node.matches && node.matches('input[type="password"]')) enhancePasswordFields(node.parentNode);
          enhancePasswordFields(node);
        });
      });
    });
    observer.observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize, { once: true });
  } else {
    initialize();
  }
})();
