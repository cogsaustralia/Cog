(() => {
  const bodyRoot = document.body.getAttribute('data-root') || './';
  const drawerButton = document.querySelector('[data-mobile-toggle]');
  const drawer = document.querySelector('[data-mobile-drawer]');
  if (drawerButton && drawer) {
    drawerButton.addEventListener('click', () => {
      const open = drawer.getAttribute('data-open') === 'true';
      drawer.setAttribute('data-open', String(!open));
      drawerButton.setAttribute('aria-expanded', String(!open));
    });
  }

  const page = document.body.getAttribute('data-page');
  document.querySelectorAll('[data-nav-key]').forEach((link) => {
    link.classList.toggle('active', link.getAttribute('data-nav-key') === page);
  });

  const setDeep = (target, path, value) => {
    let node = target;
    path.forEach((part, index) => {
      if (index === path.length - 1) {
        if (Object.prototype.hasOwnProperty.call(node, part)) {
          node[part] = Array.isArray(node[part]) ? [...node[part], value] : [node[part], value];
        } else {
          node[part] = value;
        }
        return;
      }
      if (!node[part] || typeof node[part] !== 'object' || Array.isArray(node[part])) {
        node[part] = {};
      }
      node = node[part];
    });
  };

  const parseName = (name) => {
    const parts = [];
    String(name).replace(/[^\[\]]+/g, (match) => { parts.push(match); return match; });
    return parts.length ? parts : [name];
  };

  const serialiseForm = (form) => {
    const out = {};
    const data = new FormData(form);
    data.forEach((value, key) => {
      const field = form.elements.namedItem(key);
      if (field && field.type === 'checkbox') return;
      setDeep(out, parseName(key), value);
    });
    form.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
      setDeep(out, parseName(cb.name), cb.checked);
    });
    return out;
  };

  const setStatus = (form, kind, message) => {
    const target = form.querySelector('.form-status');
    if (!target) return;
    target.className = `form-status show ${kind}`;
    target.textContent = message;
  };

  const validateForm = (form) => {
    const invalid = Array.from(form.elements).find((el) => typeof el.checkValidity === 'function' && !el.checkValidity());
    if (!invalid) return null;
    invalid.reportValidity?.();
    invalid.focus?.();
    return invalid.validationMessage || 'Please complete the required fields and try again.';
  };

  const toRouteUrls = (route) => {
    const clean = String(route || '').replace(/^\/+/, '');
    return [
      `${bodyRoot}_app/api/index.php?route=${encodeURIComponent(clean)}`,
      `${bodyRoot}_app/api/index.php/${clean}`,
      `${bodyRoot}_app/api/index.php/${clean}`
    ];
  };

  const requestJson = async (url, payload) => {
    const response = await fetch(url, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(payload)
    });
    const text = await response.text();
    let json = {};
    try { json = text ? JSON.parse(text) : {}; } catch { json = { error: text || 'Unable to submit the form.' }; }
    return { response, json };
  };

  document.querySelectorAll('[data-now-iso]').forEach((input) => {
    input.value = new Date().toISOString();
  });

  const syncDerivedFields = () => {
    document.querySelectorAll('form[data-api-route]').forEach((form) => {
      const kidsInterest = form.querySelector('[name="kids_nft"]');
      const kidsCount = form.querySelector('[name="kids_count"]');
      const kidsTokens = form.querySelector('[name="kids_tokens"]');
      const hectares = form.querySelector('[name="landholder_hectares"]');
      const landholderTokens = form.querySelector('[name="landholder_tokens"]');
      const landQuestion = form.querySelector('[data-landholder-question]');

      const update = () => {
        const count = Number(kidsCount?.value || 0);
        if (kidsTokens) kidsTokens.value = String(Math.max(0, count));
        const ha = Number(hectares?.value || 0);
        if (landholderTokens) landholderTokens.value = String(Math.max(0, Math.floor(ha)));
        if (kidsCount) kidsCount.disabled = !(kidsInterest && kidsInterest.checked);
        if (landQuestion) landQuestion.hidden = ha <= 0;
        const q32 = form.querySelector('[name="stewardship_module[answers][q32]"]');
        if (q32) q32.required = ha > 0;
      };

      kidsInterest?.addEventListener('change', update);
      kidsCount?.addEventListener('input', update);
      hectares?.addEventListener('input', update);
      update();
    });
  };

  syncDerivedFields();

  document.querySelectorAll('form[data-api-route]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const validationError = validateForm(form);
      if (validationError) {
        setStatus(form, 'error', validationError);
        return;
      }

      const button = form.querySelector('button[type="submit"]');
      if (button) {
        button.disabled = true;
        button.dataset.original = button.textContent;
        button.textContent = 'Submitting…';
      }

      const payload = serialiseForm(form);
      if ((!payload.full_name || String(payload.full_name).trim() === '') && (payload.first_name || payload.last_name)) {
        payload.full_name = [payload.first_name || '', payload.last_name || ''].join(' ').replace(/\s+/g, ' ').trim();
      }
      const urls = toRouteUrls(form.getAttribute('data-api-route'));

      try {
        let success = null;
        for (const url of urls) {
          try {
            const { response, json } = await requestJson(url, payload);
            if (!response.ok || json.success === false) throw new Error(json.error || 'Unable to submit the form.');
            success = json.data || json;
            break;
          } catch (error) {
            if (url === urls[urls.length - 1]) throw error;
          }
        }

        const flow = form.getAttribute('data-reservation-flow') || '';
        if (flow === 'snft') {
          sessionStorage.removeItem('cogs_bnft_thankyou');
          const firstName = (payload.first_name || success?.first_name || '').trim();
          const lastName = (payload.last_name || success?.last_name || '').trim();
          const fullName = (payload.full_name || success?.full_name || `${firstName} ${lastName}`.trim() || '').trim();
          sessionStorage.setItem('cogs_snft_thankyou', JSON.stringify({
            member_number: success?.member_number || '',
            first_name: firstName,
            last_name: lastName,
            full_name: fullName,
            email: payload.email || success?.email || '',
            mobile: payload.mobile || success?.mobile || '',
            street: payload.street || success?.street || '',
            suburb: payload.suburb || success?.suburb || '',
            state: payload.state || success?.state || '',
            postcode: payload.postcode || success?.postcode || '',
            joining_fee_due_now: success?.joining_fee_due_now || '$4.00',
            reservation_value: success?.reservation_value || '',
            wallet_path: success?.wallet_path || 'wallets/member.html',
            wallet_mode: success?.wallet_mode || 'setup',
            kids_tokens: Number(success?.kids_tokens ?? payload.kids_tokens ?? 0),
            landholder_tokens: Number(success?.landholder_tokens ?? payload.landholder_tokens ?? 0),
            investment_tokens: Number(success?.investment_tokens ?? payload.investment_tokens ?? 0),
            donation_tokens: Number(success?.donation_tokens ?? payload.donation_tokens ?? 0),
            pay_it_forward_tokens: Number(success?.pay_it_forward_tokens ?? payload.pay_it_forward_tokens ?? 0),
            rwa_tokens: Number(success?.rwa_tokens ?? payload.rwa_tokens ?? 0),
            lr_tokens: Number(success?.lr_tokens ?? payload.lr_tokens ?? 0)
          }));
        }
        if (flow === 'bnft') {
          sessionStorage.removeItem('cogs_snft_thankyou');
          sessionStorage.setItem('cogs_bnft_thankyou', JSON.stringify({
            member_number: success?.member_number || payload.abn || '',
            abn: payload.abn || success?.abn || '',
            legal_name: payload.legal_name || success?.legal_name || '',
            trading_name: payload.trading_name || success?.trading_name || '',
            contact_name: payload.contact_name || success?.contact_name || '',
            email: payload.email || success?.email || '',
            mobile: payload.mobile || success?.mobile || '',
            state: payload.state || success?.state || '',
            postcode: payload.postcode || success?.postcode || '',
            joining_fee_due_now: success?.joining_fee_due_now || '',
            reservation_value: success?.reservation_value || '',
            reserved_tokens: Number(success?.reserved_tokens ?? payload.reserved_tokens ?? 0),
            invest_tokens: Number(success?.invest_tokens ?? payload.invest_tokens ?? 0),
            donation_tokens: Number(success?.donation_tokens ?? payload.donation_tokens ?? 0),
            pay_it_forward_tokens: Number(success?.pay_it_forward_tokens ?? payload.pay_it_forward_tokens ?? 0)
          }));
        }

        setStatus(form, 'success', 'Thanks. Your application has been received. Redirecting now…');
        const redirect = form.getAttribute('data-thankyou-url');
        if (redirect) {
          try {
            const target = new URL(redirect, window.location.href);
            const thankyouData = JSON.parse(sessionStorage.getItem(flow === 'bnft' ? 'cogs_bnft_thankyou' : 'cogs_snft_thankyou') || '{}');
            const redirectFields = {
              member_number: thankyouData.member_number || '',
              abn: thankyouData.abn || '',
              email: thankyouData.email || '',
              name: thankyouData.full_name || thankyouData.contact_name || '',
              joining_fee_due_now: thankyouData.joining_fee_due_now || '',
              reservation_value: thankyouData.reservation_value || '',
              mobile: thankyouData.mobile || '',
              state: thankyouData.state || '',
              suburb: thankyouData.suburb || '',
              postcode: thankyouData.postcode || ''
            };
            Object.entries(redirectFields).forEach(([key, value]) => {
              if (value !== '' && value !== null && value !== undefined) target.searchParams.set(key, value);
            });
            window.setTimeout(() => { window.location.href = target.pathname + target.search + target.hash; }, 800);
          } catch (e) {
            window.setTimeout(() => { window.location.href = redirect; }, 800);
          }
        }
      } catch (err) {
        setStatus(form, 'error', err && err.message ? err.message : 'Unable to submit the form.');
      } finally {
        if (button) {
          button.disabled = false;
          button.textContent = button.dataset.original || 'Submit';
        }
      }
    });
  });

  const countdown = document.querySelector('[data-countdown-target]');
  if (countdown) {
    const target = new Date(countdown.getAttribute('data-countdown-target'));
    const items = {
      days: document.querySelector('[data-countdown-days]'),
      hours: document.querySelector('[data-countdown-hours]'),
      mins: document.querySelector('[data-countdown-mins]'),
      secs: document.querySelector('[data-countdown-secs]')
    };
    const tick = () => {
      const diff = Math.max(0, target.getTime() - Date.now());
      const days = Math.floor(diff / 86400000);
      const hours = Math.floor((diff % 86400000) / 3600000);
      const mins = Math.floor((diff % 3600000) / 60000);
      const secs = Math.floor((diff % 60000) / 1000);
      if (items.days) items.days.textContent = String(days);
      if (items.hours) items.hours.textContent = String(hours).padStart(2, '0');
      if (items.mins) items.mins.textContent = String(mins).padStart(2, '0');
      if (items.secs) items.secs.textContent = String(secs).padStart(2, '0');
    };
    tick();
    window.setInterval(tick, 1000);
  }
})();




document.addEventListener('DOMContentLoaded', () => {
  const entry = document.querySelector('[data-entry-screen]');
  if (!entry) return;

  const destination = entry.getAttribute('data-entry-link') || 'tell-me-more/index.html';
  const isStatic = entry.classList.contains('entry-screen-static');
  const sequenceImg = entry.querySelector('[data-coin-sequence]');
  const frames = Array.from({ length: 29 }, (_, index) => {
    const n = String(index + 1).padStart(4, '0');
    return `assets/frames/frame_${n}.png`;
  });

  let entering = false;
  const go = () => {
    if (entering) return;
    entering = true;
    entry.classList.add('entry-entering');
    window.setTimeout(() => {
      window.location.assign(destination);
    }, 320);
  };

  if (sequenceImg) {
    let frameIndex = 0;
    let lastTime = 0;
    const frameDuration = 70;
    const cache = [];

    frames.forEach((src) => {
      const img = new Image();
      img.src = src;
      cache.push(img);
    });

    const animate = (time) => {
      if (time - lastTime >= frameDuration) {
        frameIndex = (frameIndex + 1) % frames.length;
        sequenceImg.src = frames[frameIndex];
        lastTime = time;
      }
      if (!entering) window.requestAnimationFrame(animate);
    };
    window.requestAnimationFrame(animate);
  }

  if (!isStatic) {
    document.addEventListener('click', go);
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        go();
      }
    });
  }
});
