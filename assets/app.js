(function () {
  const formatCurrency = (value) => {
    const number = Number.isFinite(value) ? value : 0;
    return new Intl.NumberFormat('en-AU', { style: 'currency', currency: 'AUD' }).format(number);
  };

  const updateTokenModule = (module) => {
    let payable = 0;
    let interest = 0;

    module.querySelectorAll('[data-token-class]').forEach((entry) => {
      const type = entry.dataset.type || 'interest';
      const unitPrice = parseFloat(entry.dataset.unitPrice || '0');
      const unitValue = parseFloat(entry.dataset.unitValue || '0');
      const fieldName = entry.dataset.field;
      const qtyInput = entry.querySelector('input[type="number"][name]:not([name="' + (entry.dataset.hectaresField || '') + '"])');
      const qty = Math.max(0, parseFloat((qtyInput && qtyInput.value) || '0') || 0);
      const hiddenInput = fieldName && entry.querySelector('input[type="hidden"][name="' + fieldName + '"]');

      if (hiddenInput) {
        hiddenInput.value = String(qty);
      }

      if (type === 'payable') {
        payable += qty * unitPrice;
      } else {
        interest += qty * (unitValue || 1);
      }
    });

    const payableNode = module.querySelector('[data-payable-total]');
    const interestNode = module.querySelector('[data-interest-total]');
    if (payableNode) payableNode.textContent = formatCurrency(payable);
    if (interestNode) interestNode.textContent = String(interest);
  };

  document.querySelectorAll('[data-token-module]').forEach((module) => {
    updateTokenModule(module);
    module.addEventListener('input', () => updateTokenModule(module));
  });

  document.querySelectorAll('.cogs-form').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const status = form.querySelector('.form-status');
      const button = form.querySelector('button[type="submit"]');
      const originalButtonText = button ? button.textContent : '';
      const endpoint = form.dataset.endpoint || '';
      const type = form.dataset.formType || 'individual';
      const apiBase = (window.COGS_THEME_DATA && window.COGS_THEME_DATA.apiBaseUrl) || '/api';
      const thankYou = type === 'business'
        ? ((window.COGS_THEME_DATA && window.COGS_THEME_DATA.thankYouBusiness) || '/thank-you-business/')
        : ((window.COGS_THEME_DATA && window.COGS_THEME_DATA.thankYouIndividual) || '/thank-you/');

      if (status) {
        status.textContent = (window.COGS_THEME_DATA && window.COGS_THEME_DATA.copy.submitWorking) || 'Submitting...';
        status.classList.remove('is-error', 'is-success');
      }
      if (button) {
        button.disabled = true;
        button.textContent = (window.COGS_THEME_DATA && window.COGS_THEME_DATA.copy.submitWorking) || 'Submitting...';
      }

      try {
        const formData = new FormData(form);
        const payload = Object.fromEntries(formData.entries());
        const response = await fetch(apiBase.replace(/\/$/, '') + '/' + endpoint.replace(/^\//, ''), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify(payload)
        });

        if (!response.ok) {
          throw new Error('Request failed with status ' + response.status);
        }

        if (status) {
          status.textContent = (window.COGS_THEME_DATA && window.COGS_THEME_DATA.copy.submitSuccess) || 'Saved. Redirecting...';
          status.classList.add('is-success');
        }

        window.location.href = thankYou;
      } catch (error) {
        if (status) {
          status.textContent = error && error.message ? error.message : ((window.COGS_THEME_DATA && window.COGS_THEME_DATA.copy.submitError) || 'Something went wrong. Please try again.');
          status.classList.add('is-error');
        }
      } finally {
        if (button) {
          button.disabled = false;
          button.textContent = originalButtonText || ((window.COGS_THEME_DATA && window.COGS_THEME_DATA.copy.submitIdle) || 'Submit reservation');
        }
      }
    });
  });
})();
