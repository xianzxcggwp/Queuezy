(() => {
  const addPasswordToggles = () => {
    document.querySelectorAll('input[type="password"]').forEach((input) => {
      if (input.dataset.passwordToggleReady === 'true') return;
      input.dataset.passwordToggleReady = 'true';
      input.minLength = 8;
      input.maxLength = 15;

      const wrapper = document.createElement('span');
      wrapper.style.position = 'relative';
      wrapper.style.display = 'block';
      wrapper.style.width = '100%';
      input.parentNode.insertBefore(wrapper, input);
      wrapper.appendChild(input);
      input.style.paddingRight = '42px';

      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'password-toggle';
      button.setAttribute('aria-label', 'Show password');
      button.innerHTML = '<i class="fa-solid fa-eye" aria-hidden="true"></i>';
      button.style.position = 'absolute';
      button.style.top = '50%';
      button.style.right = '10px';
      button.style.transform = 'translateY(-50%)';
      button.style.display = 'inline-flex';
      button.style.alignItems = 'center';
      button.style.justifyContent = 'center';
      button.style.width = '28px';
      button.style.height = '28px';
      button.style.padding = '0';
      button.style.border = '0';
      button.style.background = 'transparent';
      button.style.color = '#64748b';
      button.style.cursor = 'pointer';
      button.style.fontSize = '14px';

      button.addEventListener('click', () => {
        const isVisible = input.type === 'text';
        input.type = isVisible ? 'password' : 'text';
        button.setAttribute('aria-label', isVisible ? 'Show password' : 'Hide password');
        button.innerHTML = `<i class="fa-solid fa-eye${isVisible ? '' : '-slash'}" aria-hidden="true"></i>`;
      });
      wrapper.appendChild(button);
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addPasswordToggles);
  } else {
    addPasswordToggles();
  }
})();
