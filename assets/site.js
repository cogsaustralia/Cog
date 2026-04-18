/* SAFARI COMPAT BUILD: transpiled for older Safari */
(() => {
  (function() {
    const POLL_MS = 15e3;
    const TOKEN_PRICE = 4;
    const KIDS_TOKEN_PRICE = 1;
    const BNFT_FIXED_FEE = 40;
    const SITE_VERSION = "20260330-walletux17";
    let pollHandle = null;
    function root() {
      return window.COGS_ROOT || "./";
    }
    function vaultSpinPath(target) {
      return root() + "coin-spin/?next=" + encodeURIComponent(target);
    }
    function apiUrl(route) {
      return root() + "_app/api/index.php/" + route.replace(/^\/+/, "");
    }
    function getAdminToken() {
      try {
        return localStorage.getItem("cogs_admin_token") || "";
      } catch (e) {
        return "";
      }
    }
    function setAdminToken(value) {
      try {
        if (value) localStorage.setItem("cogs_admin_token", value);
        else localStorage.removeItem("cogs_admin_token");
      } catch (e) {
      }
    }
    function setActiveNav() {
      const current = window.location.pathname.split("/").filter(Boolean).slice(-2).join("/");
      document.querySelectorAll("[data-nav]").forEach((link) => {
        const href = link.getAttribute("href").replace(/^\.\.\//, "").replace(/^\.\//, "");
        if (current.endsWith(href.replace(/^\//, "")) || href === "index.html" && current === "index.html") link.classList.add("active");
      });
    }
    function injectGlobalLegalBanner() {
      if (document.querySelector("[data-legal-banner]")) return;
      const header = document.querySelector(".topbar");
      if (!header) return;
      const banner = document.createElement("div");
      banner.className = "legal-banner";
      banner.setAttribute("data-legal-banner", "1");
      banner.innerHTML = '<div class="wrap"><strong>Not for public disclosure.</strong> Private community use only \xB7 Available by direct link only \xB7 Reservation only \xB7 Nothing is being offered at this stage \xB7 Full regulatory compliance and AFSL approval will occur before anything is offered.</div>';
      header.insertAdjacentElement("afterend", banner);
    }
    async function request(route, options) {
      const config = Object.assign({ method: "GET", credentials: "include", headers: {} }, options || {});
      const headers = Object.assign({}, config.headers || {});
      const hasBody = config.body != null && config.body !== "";
      const isFormData = typeof FormData !== "undefined" && config.body instanceof FormData;
      if (hasBody && !isFormData && !Object.keys(headers).some((k) => k.toLowerCase() === "content-type")) {
        headers["Content-Type"] = "application/json";
      }
      config.headers = headers;
      const resp = await fetch(apiUrl(route), config);
      let data = {};
      try {
        data = await resp.json();
      } catch (e) {
      }
      if (!resp.ok || data.success === false) {
        throw new Error(data.error || "Request failed");
      }
      return data.data;
    }
    function showStatus(target, message, kind) {
      if (!target) return;
      target.textContent = message;
      target.className = "form-status show " + (kind || "success");
    }
    function hideStatus(target) {
      if (!target) return;
      target.className = "form-status";
      target.textContent = "";
    }
    function serialiseForm(form) {
      const obj = {};
      new FormData(form).forEach((v, k) => obj[k] = v);
      form.querySelectorAll("input[type=checkbox]").forEach((cb) => obj[cb.name] = cb.checked);
      return obj;
    }

    function normalizeReservationPayload(kind, payload) {
      const data = Object.assign({}, payload || {});
      if (kind === "snft") {
        if (data.dob != null && data.date_of_birth == null) data.date_of_birth = data.dob;
        if (data.street != null && data.street_address == null) data.street_address = data.street;
        if (data.state != null && data.state_code == null) data.state_code = data.state;
        if (data.additional_info != null && data.message == null) data.message = data.additional_info;
        if (data.hectares != null && data.landholder_hectares == null) data.landholder_hectares = data.hectares;
      } else if (kind === "bnft") {
        if (data.street != null && data.street_address == null) data.street_address = data.street;
        if (data.state != null && data.state_code == null) data.state_code = data.state;
      }
      return data;
    }
    function formatMoney(value) {
      return "$" + Number(value || 0).toLocaleString(void 0, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function formatInteger(value) {
      return Number(value || 0).toLocaleString();
    }
    function formatMemberNumberDisplay(value) {
      const digits = String(value || "").replace(/\D+/g, "");
      if (digits.length === 16) return digits.replace(/(\d{4})(?=\d)/g, "$1 ").trim();
      return value == null ? "" : String(value);
    }
    function escapeHtml(value) {
      return String(value == null ? "" : value).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#39;");
    }
    function setText(selector, value) {
      const el = document.querySelector(selector);
      if (el) el.textContent = value == null ? "" : value;
    }
    function setAllText(selector, value) {
      const text = value == null ? "" : value;
      document.querySelectorAll(selector).forEach((el) => {
        el.textContent = text;
      });
    }
    function getAllClassTokenTotal(data) {
      var _a, _b;
      return Number(data && ((_b = (_a = data.all_class_tokens_total) != null ? _a : data.all_class_token_total) != null ? _b : data.total_tokens) || 0);
    }
    let deferredInstallPrompt = null;
    function registerPWA() {
      if (!("serviceWorker" in navigator)) return;
      window.addEventListener("load", function() {
        navigator.serviceWorker.register(root() + "sw.js?v=" + encodeURIComponent(SITE_VERSION), { updateViaCache: "none" }).then(function(reg) {
          try {
            reg.update();
          } catch (e) {
          }
        }).catch(function() {
        });
      });
    }
    function attachInstallPrompt() {
      const banner = document.querySelector("[data-install-banner]");
      const button = document.querySelector("[data-install-app]");
      if (!banner || !button) return;
      window.addEventListener("beforeinstallprompt", function(e) {
        e.preventDefault();
        deferredInstallPrompt = e;
        banner.hidden = false;
      });
      button.addEventListener("click", async function() {
        if (deferredInstallPrompt) {
          deferredInstallPrompt.prompt();
          try {
            await deferredInstallPrompt.userChoice;
          } catch (e) {
          }
          deferredInstallPrompt = null;
          banner.hidden = true;
        } else {
          showStatus(document.querySelector(".form-status"), "Use your browser menu to install this app to your home screen.", "success");
        }
      });
      window.addEventListener("appinstalled", function() {
        banner.hidden = true;
      });
    }
    function bindFreshnessReload() {
      if (!("serviceWorker" in navigator)) return;
      navigator.serviceWorker.addEventListener("controllerchange", function() {
        try {
          document.documentElement.setAttribute("data-sw-updated", "1");
          sessionStorage.setItem("cogs_sw_updated", SITE_VERSION);
        } catch (e) {
        }
      });
    }
    function injectPhaseBanner() {
      const page = document.body.getAttribute("data-vault-page");
      if (!page || document.querySelector("[data-phase-banner]")) return;
      const header = document.querySelector(".topbar");
      if (!header) return;
      const banner = document.createElement("div");
      banner.className = "phase-banner";
      banner.setAttribute("data-phase-banner", "1");
      banner.innerHTML = '<div class="wrap"><strong>Current phase notice:</strong> every token figure, tally, and wallet value shown in this vault is proposed beta intent only. No entitlement, issuance, financial claim, legal effect, or blockchain state is active in this phase.</div>';
      header.insertAdjacentElement("afterend", banner);
    }
    async function hydratePublicMetrics() {
      const shell = document.querySelector("[data-public-community-shell]");
      const liveCountNodes = document.querySelectorAll("[data-live-member-count], [data-public-member-count]");
      if (!shell && !liveCountNodes.length) return;
      try {
        const data = await request("community", { method: "GET" });
        const snftMembers = Number(data.snft_members || data.snft_count || data.member_count || data.total_members || 0);
        const bnftBusinesses = Number(data.bnft_businesses || data.business_count || 0);
        const totalReservationValue = Number(data.total_reservation_value || data.total_value || 0);
        const foundingUsed = snftMembers + bnftBusinesses;
        const foundingRemaining = Math.max(0, 100 - foundingUsed);
        setAllText("[data-live-member-count]", formatInteger(snftMembers));
        setAllText("[data-public-member-count]", formatInteger(snftMembers));
        setAllText("[data-public-business-count]", formatInteger(bnftBusinesses));
        setAllText("[data-public-token-total]", formatInteger(getAllClassTokenTotal(data)));
        setAllText("[data-public-value-total]", formatMoney(totalReservationValue));
        setAllText("[data-public-founding-remaining]", formatInteger(foundingRemaining));
        setAllText("[data-founding-countdown]", formatInteger(foundingRemaining));
        setAllText("[data-public-value-display]", formatMoney(totalReservationValue));
      } catch (e) {
      }
    }
    function startPolling(fn) {
      if (pollHandle) window.clearInterval(pollHandle);
      pollHandle = window.setInterval(fn, POLL_MS);
    }
    function attachReservationForms() {
      document.querySelectorAll("[data-api-form]").forEach((form) => {
        if (form.hasAttribute("data-reservation-flow")) return;
        const route = form.getAttribute("data-api-form");
        const successTemplate = form.getAttribute("data-success-template") || "Saved successfully.";
        const status = form.querySelector(".form-status");
        form.addEventListener("submit", async (e) => {
          e.preventDefault();
          hideStatus(status);
          const btn = form.querySelector("button[type=submit]");
          if (btn) {
            btn.disabled = true;
            btn.dataset.original = btn.textContent;
            btn.textContent = "Submitting\u2026";
          }
          try {
            const data = serialiseForm(form);
            const result = await request(route, { method: "POST", body: JSON.stringify(data) });
            let message = successTemplate.replace(/\{\{member_number\}\}/g, result.member_number || result.abn || "").replace(/\{\{wallet_path\}\}/g, result.wallet_path || "").replace(/\{\{reservation_value\}\}/g, result.reservation_value || "");
            showStatus(status, message, "success");
            const target = form.getAttribute("data-redirect");
            if (target) {
              setTimeout(() => {
                window.location.href = target;
              }, 900);
            } else {
              form.reset();
            }
          } catch (err) {
            showStatus(status, err.message || "Unable to submit.", "error");
          } finally {
            if (btn) {
              btn.disabled = false;
              btn.textContent = btn.dataset.original || "Submit";
            }
          }
        });
      });
    }
    function safeLocalGet2(key, fallback) {
      try {
        const raw = localStorage.getItem(key);
        return raw == null ? fallback : raw;
      } catch (e) {
        return fallback;
      }
    }
    function safeLocalSet2(key, value) {
      try {
        localStorage.setItem(key, value);
      } catch (e) {
      }
    }
    function safeLocalRemove2(key) {
      try {
        localStorage.removeItem(key);
      } catch (e) {
      }
    }
    function readStoredJson(key) {
      try {
        return JSON.parse(localStorage.getItem(key) || "{}");
      } catch (e) {
        return {};
      }
    }
    function writeStoredJson(key, obj) {
      try {
        localStorage.setItem(key, JSON.stringify(obj || {}));
      } catch (e) {
      }
    }
    function walletStorageKey(role) {
      return role === "bnft" ? "cogs_business_login" : "cogs_member_login";
    }
    function walletProfileKey(role) {
      return role === "bnft" ? "cogs_business_profile" : "cogs_member_profile";
    }
    function normaliseDigits2(value) {
      return String(value || "").replace(/\D+/g, "");
    }
    function rememberChoiceKey(role) {
      return role === "bnft" ? "cogs_business_remember" : "cogs_member_remember";
    }
    function clearWalletIdentity(role) {
      const storageKey = walletStorageKey(role);
      const profileKey = walletProfileKey(role);
      safeLocalRemove2(storageKey);
      safeLocalRemove2(profileKey);
      safeLocalSet2(rememberChoiceKey(role), "0");
    }
    function clearAllWalletIdentity() {
      clearWalletIdentity("snft");
      clearWalletIdentity("bnft");
    }
    function shouldIsolateWalletFromQuery() {
      const page = document.body.getAttribute("data-vault-page");
      if (page !== "member" && page !== "business") return false;
      const params = new URLSearchParams(window.location.search);
      const mode = String(params.get("mode") || "").trim().toLowerCase();
      const hasSetupIdentity = !!(params.get("member_number") || params.get("abn") || params.get("email") || params.get("activation_token"));
      return mode === "setup" || hasSetupIdentity;
    }
    async function enforceWalletContextIsolation() {
      if (!shouldIsolateWalletFromQuery()) return;
      clearAllWalletIdentity();
      try {
        await request("auth/logout", { method: "POST", body: "{}" });
      } catch (e) {
      }
      window.COGS_FORCE_GUEST_WALLET_CONTEXT = true;
    }
    function persistWalletIdentity(role, payload, result, remember) {
      const storageKey = walletStorageKey(role);
      const profileKey = walletProfileKey(role);
      const numberField = role === "bnft" ? "abn" : "member_number";
      const resultNumber = role === "bnft" ? result && (result.abn || result.member_number || result.member_number_display || "") : result && (result.member_number || result.member_number_display || "");
      const payloadNumber = role === "bnft" ? payload.abn || payload.member_number || "" : payload.member_number || "";
      const cleanNumber = normaliseDigits2(resultNumber || payloadNumber);
      const displayNumber = role === "bnft" ? cleanNumber : formatMemberNumberDisplay(cleanNumber);
      const email = payload.email || payload.contact_email || result && (result.email || result.contact_email) || "";
      const profile = Object.assign({}, readStoredJson(profileKey), {
        role,
        email,
        remember: remember !== false,
        updated_at: (/* @__PURE__ */ new Date()).toISOString()
      });
      if (cleanNumber) {
        profile[numberField] = cleanNumber;
        profile.member_number = cleanNumber;
        profile.member_number_display = displayNumber;
      }
      writeStoredJson(profileKey, profile);
      if (remember === false) {
        safeLocalRemove2(storageKey);
        safeLocalSet2(rememberChoiceKey(role), "0");
        return;
      }
      const login = Object.assign({}, readStoredJson(storageKey));
      if (cleanNumber) {
        login[numberField] = role === "bnft" ? cleanNumber : displayNumber;
        if (role === "bnft") login.member_number = cleanNumber;
        else login.member_number = displayNumber;
      }
      if (email) login.email = email;
      login.remember_login = true;
      writeStoredJson(storageKey, login);
      safeLocalSet2(rememberChoiceKey(role), "1");
    }
    function looksLikeEmail2(value) {
      return /@/.test(String(value || "").trim());
    }
    function formatWalletNumberForRole(role, value) {
      const digits = normaliseDigits2(value);
      return role === "bnft" ? digits : formatMemberNumberDisplay(digits);
    }
    function reconcileWalletIdentityFields2(role, numberValue, emailValue, fallbackNumber, fallbackEmail) {
      let number = String(numberValue || "").trim();
      let email = String(emailValue || "").trim();
      const fallbackDigits = normaliseDigits2(fallbackNumber || "");
      const fallbackEmailClean = String(fallbackEmail || "").trim();
      const numberIsEmail = looksLikeEmail2(number);
      const emailIsEmail = looksLikeEmail2(email);
      const numberDigits = normaliseDigits2(number);
      const emailDigits = normaliseDigits2(email);
      if (numberIsEmail && emailDigits && !emailIsEmail) {
        const swappedEmail = number;
        number = formatWalletNumberForRole(role, emailDigits);
        email = swappedEmail;
      } else if (numberIsEmail) {
        email = number;
        number = fallbackDigits ? formatWalletNumberForRole(role, fallbackDigits) : "";
      }
      if ((!email || !looksLikeEmail2(email)) && fallbackEmailClean && looksLikeEmail2(fallbackEmailClean)) {
        email = fallbackEmailClean;
      }
      if ((!number || looksLikeEmail2(number) || !normaliseDigits2(number)) && fallbackDigits) {
        number = formatWalletNumberForRole(role, fallbackDigits);
      } else if (number) {
        number = formatWalletNumberForRole(role, number);
      }
      if (email && !looksLikeEmail2(email) && fallbackEmailClean && looksLikeEmail2(fallbackEmailClean)) {
        email = fallbackEmailClean;
      }
      return { number, email };
    }
    function cleanStoredWalletIdentity(role) {
      const storageKey = walletStorageKey(role);
      const profileKey = walletProfileKey(role);
      const login = readStoredJson(storageKey);
      const profile = readStoredJson(profileKey);
      let mutated = false;
      function repair(obj) {
        if (!obj || typeof obj !== "object") return obj;
        const fixed = Object.assign({}, obj);
        const numberKeys = role === "bnft" ? ["abn", "member_number"] : ["member_number", "member_number_display"];
        numberKeys.forEach((key) => {
          const value = fixed[key];
          if (looksLikeEmail2(value)) {
            if (!fixed.email) fixed.email = String(value).trim();
            fixed[key] = "";
            mutated = true;
          }
        });
        if (role === "bnft") {
          const reconciled = reconcileWalletIdentityFields2("bnft", fixed.abn || fixed.member_number || "", fixed.email || "", fixed.abn || fixed.member_number || "", fixed.email || "");
          fixed.abn = normaliseDigits2(reconciled.number || "");
          fixed.member_number = fixed.abn;
          fixed.email = reconciled.email || "";
        } else {
          const reconciled = reconcileWalletIdentityFields2("snft", fixed.member_number_display || fixed.member_number || "", fixed.email || "", fixed.member_number_display || fixed.member_number || "", fixed.email || "");
          const digits = normaliseDigits2(reconciled.number || "");
          if (digits) {
            fixed.member_number = digits;
            fixed.member_number_display = formatMemberNumberDisplay(digits);
          }
          fixed.email = reconciled.email || "";
        }
        return fixed;
      }
      const repairedLogin = repair(login);
      const repairedProfile = repair(profile);
      if (mutated) {
        writeStoredJson(storageKey, repairedLogin);
        writeStoredJson(profileKey, repairedProfile);
      }
      return { login: repairedLogin, profile: repairedProfile };
    }
    function applyStoredWalletPrefill() {
      const page = document.body.getAttribute("data-vault-page");
      if (page !== "member" && page !== "business") return;
      if (window.COGS_FORCE_GUEST_WALLET_CONTEXT) return;
      const role = page === "business" ? "bnft" : "snft";
      const repaired = cleanStoredWalletIdentity(role);
      const profile = repaired.profile;
      const login = repaired.login;
      const remember = safeLocalGet2(rememberChoiceKey(role), login.remember_login ? "1" : "0") === "1";
      const storedNumber = role === "bnft" ? normaliseDigits2(profile.abn || login.abn || login.member_number || profile.member_number || "") : formatMemberNumberDisplay(normaliseDigits2(profile.member_number_display || login.member_number || profile.member_number || ""));
      const storedEmail = String(profile.email || login.email || "").trim();
      document.querySelectorAll('form[data-auth-form="login"]').forEach((form) => {
        const numberInput = form.querySelector('[name="member_number"], [name="abn"]');
        const emailInput = form.querySelector('[name="email"]');
        const rememberInput = form.querySelector('[name="remember_login"]');
        if (numberInput && (!numberInput.value || looksLikeEmail2(numberInput.value)) && storedNumber) numberInput.value = storedNumber;
        if (emailInput && !emailInput.value && storedEmail) emailInput.value = storedEmail;
        if (numberInput && emailInput) {
          const reconciled = reconcileWalletIdentityFields2(role, numberInput.value, emailInput.value, storedNumber, storedEmail);
          if (reconciled.number && numberInput.value !== reconciled.number) numberInput.value = reconciled.number;
          if (reconciled.email !== void 0 && emailInput.value !== reconciled.email) emailInput.value = reconciled.email;
        }
        if (rememberInput) rememberInput.checked = remember;
      });
      document.querySelectorAll('form[data-auth-form="setup"], form[data-reset-password-form], form[data-recover-number-form]').forEach((form) => {
        const numberInput = form.querySelector('[name="member_number"], [name="abn"]');
        const emailInput = form.querySelector('[name="email"]');
        if (numberInput && (!numberInput.value || looksLikeEmail2(numberInput.value)) && storedNumber) numberInput.value = role === "bnft" ? normaliseDigits2(storedNumber) : storedNumber;
        if (emailInput && !emailInput.value && storedEmail) emailInput.value = storedEmail;
        if (numberInput && emailInput) {
          const reconciled = reconcileWalletIdentityFields2(role, numberInput.value, emailInput.value, storedNumber, storedEmail);
          if (reconciled.number && numberInput.value !== reconciled.number) numberInput.value = role === "bnft" ? normaliseDigits2(reconciled.number) : reconciled.number;
          if (reconciled.email !== void 0 && emailInput.value !== reconciled.email) emailInput.value = reconciled.email;
        }
      });
    }
    function attachRecoveryToggles() {
      const panels = Array.from(document.querySelectorAll("[data-recover-panel]"));
      if (!panels.length) return;
      function closePanels() {
        panels.forEach((panel) => {
          panel.classList.add("hidden");
          panel.classList.remove("open");
        });
      }
      closePanels();
      document.querySelectorAll("[data-recover-toggle]").forEach((link) => {
        link.addEventListener("click", function(e) {
          e.preventDefault();
          const id = link.getAttribute("data-recover-toggle");
          const panel = id ? document.getElementById(id) : null;
          if (!panel) return;
          const opening = panel.classList.contains("hidden") || !panel.classList.contains("open");
          closePanels();
          if (opening) {
            panel.classList.remove("hidden");
            panel.classList.add("open");
            try {
              panel.scrollIntoView({ behavior: "smooth", block: "nearest" });
            } catch (e2) {
            }
          }
        });
      });
      document.querySelectorAll("[data-recover-close]").forEach((link) => {
        link.addEventListener("click", function(e) {
          e.preventDefault();
          const panel = link.closest("[data-recover-panel]");
          if (panel) {
            panel.classList.add("hidden");
            panel.classList.remove("open");
          }
        });
      });
    }
    function attachAuthForms() {
      document.querySelectorAll("[data-auth-form]").forEach((form) => {
        const kind = form.getAttribute("data-auth-form");
        const status = form.querySelector(".form-status");
        form.addEventListener("submit", async (e) => {
          e.preventDefault();
          hideStatus(status);
          const btn = form.querySelector("button[type=submit]");
          if (btn) {
            btn.disabled = true;
            btn.dataset.original = btn.textContent;
            btn.textContent = "Working\u2026";
          }
          try {
            const payload = serialiseForm(form);
            const result = await request(kind === "setup" ? "auth/setup-password" : "auth/login", {
              method: "POST",
              body: JSON.stringify(payload)
            });
            if (result.setup_required) {
              showStatus(status, result.message + " Complete the password setup section below.", "success");
              const setup = document.querySelector("[data-setup-panel]");
              if (setup) {
                setup.classList.remove("hidden");
                const numField = setup.querySelector("[name=member_number]");
                const abnField = setup.querySelector("[name=abn]");
                const emailField = setup.querySelector("[name=email]");
                if (numField && !numField.value && result.member_number) numField.value = result.member_number;
                if (abnField && !abnField.value && result.abn) abnField.value = result.abn;
                if (emailField && !emailField.value) emailField.value = payload.email || payload.contact_email || "";
              }
            } else {
              const role = payload.role || (document.body.getAttribute("data-vault-page") === "business" ? "bnft" : "snft");
              persistWalletIdentity(role, payload, result, payload.remember_login !== false && payload.remember_login !== "false");
              showStatus(status, "Wallet opened. Loading your dashboard\u2026", "success");
              setTimeout(() => window.location.reload(), 700);
            }
          } catch (err) {
            showStatus(status, err.message || "Unable to authenticate", "error");
          } finally {
            if (btn) {
              btn.disabled = false;
              btn.textContent = btn.dataset.original || "Continue";
            }
          }
        });
      });
    }
    function renderWalletEvents(items) {
      if (!Array.isArray(items) || !items.length) {
        return '<div class="list-item"><div class="muted">No wallet activity yet.</div></div>';
      }
      return items.map((ev) => '\n      <div class="list-item">\n        <div class="wallet-meta"><span class="pill ok">'.concat(escapeHtml(ev.event_type), '</span><span class="muted">').concat(escapeHtml(ev.created_at), "</span></div>\n        <div>").concat(escapeHtml(ev.description || "Wallet event recorded."), "</div>\n      </div>\n    ")).join("");
    }
    function renderAnnouncements(items, canMark) {
      if (!Array.isArray(items) || !items.length) {
        return '<div class="list-item"><div class="muted">No announcements pushed yet.</div></div>';
      }
      return items.map((item) => '\n      <article class="announcement '.concat(item.is_read ? "is-read" : "is-unread", '">\n        <div class="wallet-meta">\n          <span class="pill ').concat(item.is_read ? "ok" : "warn", '">').concat(item.is_read ? "Read" : "New", '</span>\n          <span class="pill">').concat(escapeHtml(item.audience || "all"), '</span>\n          <span class="muted">').concat(escapeHtml(item.created_at), "</span>\n        </div>\n        <h3>").concat(escapeHtml(item.title), "</h3>\n        <p>").concat(escapeHtml(item.body), '</p>\n        <div class="inline-actions compact">\n          ').concat(item.created_by ? '<span class="muted">Posted by '.concat(escapeHtml(item.created_by), "</span>") : '<span class="muted">Admin push</span>', "\n          ").concat(canMark && !item.is_read ? '<button class="cta secondary small" type="button" data-mark-announcement="'.concat(item.id, '">Mark read</button>') : "", "\n        </div>\n      </article>\n    ")).join("");
    }
    function renderProposalCard(item, allowVote) {
      const options = Array.isArray(item.options) ? item.options : [];
      const tally = Array.isArray(item.tally) ? item.tally : [];
      const buttons = options.map((option) => {
        const selected = item.my_vote && item.my_vote === option;
        const disabled = !allowVote || !item.eligible_to_vote || item.status !== "open";
        return '<button class="cta '.concat(selected ? "" : "secondary", ' small" type="button" ').concat(disabled ? "disabled" : "", ' data-cast-vote="').concat(item.id, '" data-vote-choice="').concat(escapeHtml(option), '">').concat(selected ? "Your vote: " : "").concat(escapeHtml(option), "</button>");
      }).join("");
      const tallyHtml = tally.map((row) => '<div class="result-row"><span>'.concat(escapeHtml(row.label), "</span><strong>").concat(Number(row.votes || 0).toLocaleString(), "</strong></div>")).join("");
      const canDispute = item.status === "open" || item.status === "closed";
      return '\n    <article class="proposal '.concat(item.status === "open" ? "open" : "closed", '">\n      <div class="wallet-meta">\n        <span class="pill ').concat(item.status === "open" ? "ok" : "bad", '">').concat(escapeHtml(item.status), '</span>\n        <span class="pill ').concat(item.tally_status === "under_dispute" ? "warn" : "ok", '">').concat(escapeHtml(item.tally_status || "live"), '</span>\n        <span class="pill">').concat(escapeHtml(item.audience || "snft"), '</span>\n        <span class="muted">').concat(escapeHtml(item.created_at || ""), "</span>\n      </div>\n      <h3>").concat(escapeHtml(item.title), "</h3>\n      ").concat(item.summary ? "<p>".concat(escapeHtml(item.summary), "</p>") : "", "\n      ").concat(item.body ? '<div class="notice subtle">'.concat(escapeHtml(item.body), "</div>") : "", '\n      <div class="notice subtle">Beta test only. This live tally records proposed voting intent only. It does not certify entitlement, legal effect, or any on-chain outcome.</div>\n      <div class="result-grid">').concat(tallyHtml || '<div class="muted">No votes recorded yet.</div>', '</div>\n      <div class="wallet-meta" style="margin-top:.75rem"><span>Votes: ').concat(Number(item.total_votes || 0).toLocaleString(), "</span><span>Open disputes: ").concat(Number(item.open_disputes || 0).toLocaleString(), "</span>").concat(item.dispute_window_closes_at ? "<span>Disputes until ".concat(escapeHtml(item.dispute_window_closes_at), "</span>") : "", '</div>\n      <div class="inline-actions compact proposal-actions">\n        ').concat(allowVote ? buttons : '<span class="muted">Read-only in this wallet. Governance remains with SNFT human members.</span>', "\n        ").concat(canDispute ? '<button class="cta secondary small" type="button" data-open-dispute="'.concat(item.id, '">').concat(item.my_dispute_status === "open" ? "Update dispute" : "Dispute tally", "</button>") : "", "\n        ").concat(item.closes_at ? '<span class="muted">Closes '.concat(escapeHtml(item.closes_at), "</span>") : "", "\n      </div>\n      ").concat(item.my_dispute_status ? '<div class="muted">Your dispute status: '.concat(escapeHtml(item.my_dispute_status), "</div>") : "", "\n    </article>\n  ");
    }
    function renderAdminNews(items) {
      if (!Array.isArray(items) || !items.length) {
        return '<div class="list-item"><div class="muted">No admin news pushes yet.</div></div>';
      }
      return items.map((item) => '\n      <div class="list-item">\n        <div class="wallet-meta"><span class="pill">'.concat(escapeHtml(item.audience), '</span><span class="muted">').concat(escapeHtml(item.created_at), "</span></div>\n        <strong>").concat(escapeHtml(item.title), "</strong>\n        <div>").concat(escapeHtml(item.body), "</div>\n      </div>\n    ")).join("");
    }
    function renderAdminVotes(items, allowClose) {
      if (!Array.isArray(items) || !items.length) {
        return '<div class="list-item"><div class="muted">No votes pushed yet.</div></div>';
      }
      return items.map((item) => {
        const tallyHtml = (item.tally || []).map((row) => '<div class="result-row"><span>'.concat(escapeHtml(row.label), "</span><strong>").concat(Number(row.votes || 0).toLocaleString(), "</strong></div>")).join("");
        return '\n      <div class="proposal '.concat(item.status === "open" ? "open" : "closed", '">\n        <div class="wallet-meta">\n          <span class="pill ').concat(item.status === "open" ? "ok" : "bad", '">').concat(escapeHtml(item.status), '</span>\n          <span class="pill ').concat(item.tally_status === "under_dispute" ? "warn" : "ok", '">').concat(escapeHtml(item.tally_status || "live"), '</span>\n          <span class="pill">').concat(escapeHtml(item.audience), '</span>\n          <span class="muted">').concat(escapeHtml(item.created_at), "</span>\n        </div>\n        <h3>").concat(escapeHtml(item.title), "</h3>\n        ").concat(item.summary ? "<p>".concat(escapeHtml(item.summary), "</p>") : "", '\n        <div class="notice subtle">Beta tally only. This tracks proposed voting intent, not entitlement.</div>\n        <div class="result-grid">').concat(tallyHtml, '</div>\n        <div class="wallet-meta"><span>Total votes: ').concat(Number(item.total_votes || 0).toLocaleString(), "</span><span>Open disputes: ").concat(Number(item.open_disputes || 0).toLocaleString(), "</span>").concat(item.dispute_window_closes_at ? "<span>Disputes until ".concat(escapeHtml(item.dispute_window_closes_at), "</span>") : "", '</div>\n        <div class="inline-actions compact">\n          ').concat(allowClose && item.status === "open" ? '<button class="cta secondary small" type="button" data-close-vote="'.concat(item.id, '">Close vote</button>') : "", "\n        </div>\n      </div>\n    ");
      }).join("");
    }
    function calculateLandholderTokensFromHectaresClient(value) {
      const hectares = Number(value || 0);
      return hectares > 0 ? Math.ceil(hectares) * 1e3 : 0;
    }
    function businessUnitValue() {
      return BNFT_FIXED_FEE;
    }
    function lockedBalanceForKind(kind) {
      return kind === "business" ? BNFT_FIXED_FEE : TOKEN_PRICE;
    }
    function betaTokensFromWalletTotal(totalTokens) {
      return Math.max(0, Number(totalTokens || 0));
    }
    function betaValueFromWalletTotal(totalValue, kind) {
      return Math.max(0, Number(totalValue || 0));
    }
    function memberPreviewState(form) {
      var _a, _b, _c, _d, _e;
      const investmentTokens = Number(((_a = form.querySelector("[name=investment_tokens]")) == null ? void 0 : _a.value) || 0);
      const donationTokens = Number(((_b = form.querySelector("[name=donation_tokens]")) == null ? void 0 : _b.value) || 0);
      const payItForwardTokens = Number(((_c = form.querySelector("[name=pay_it_forward_tokens]")) == null ? void 0 : _c.value) || 0);
      const kidsTokens = Number(((_d = form.querySelector("[name=kids_tokens]")) == null ? void 0 : _d.value) || 0);
      const landholderHectares = Number(((_e = form.querySelector("[name=landholder_hectares]")) == null ? void 0 : _e.value) || 0);
      const landholderTokens = calculateLandholderTokensFromHectaresClient(landholderHectares);
      const betaTokens = investmentTokens + donationTokens + payItForwardTokens + landholderTokens;
      const betaValue = betaTokens * TOKEN_PRICE;
      const walletTokens = 1 + kidsTokens + betaTokens;
      const walletValue = betaValue;
      return { investmentTokens, donationTokens, payItForwardTokens, kidsTokens, landholderHectares, landholderTokens, betaTokens, betaValue, walletTokens, walletValue };
    }
    function businessPreviewState(form) {
      var _a, _b, _c, _d;
      const investTokens = Number(((_a = form.querySelector("[name=invest_tokens]")) == null ? void 0 : _a.value) || 0);
      const donationTokens = Number(((_b = form.querySelector("[name=donation_tokens]")) == null ? void 0 : _b.value) || 0);
      const payItForwardTokens = Number(((_c = form.querySelector("[name=pay_it_forward_tokens]")) == null ? void 0 : _c.value) || 0);
      const landholderHectares = Number(((_d = form.querySelector("[name=landholder_hectares]")) == null ? void 0 : _d.value) || 0);
      const landholderTokens = calculateLandholderTokensFromHectaresClient(landholderHectares);
      const betaTokens = investTokens + donationTokens + payItForwardTokens + landholderTokens;
      const betaValue = betaTokens * businessUnitValue();
      const walletTokens = 1 + betaTokens;
      const walletValue = lockedBalanceForKind("business") + betaValue;
      return { investTokens, donationTokens, payItForwardTokens, landholderHectares, landholderTokens, betaTokens, betaValue, walletTokens, walletValue };
    }
    function signedDeltaText(nextValue, currentValue, money) {
      const delta = Number(nextValue || 0) - Number(currentValue || 0);
      const abs = money ? formatMoney(Math.abs(delta)) : formatInteger(Math.abs(delta));
      return (delta > 0 ? "+" : delta < 0 ? "-" : "\xB1") + abs;
    }
    function applyClassPreview(selectorBase, proposedTokens, currentTokens, unitValue) {
      setAllText("[data-proposed-".concat(selectorBase, "-tokens]"), formatInteger(proposedTokens));
      setAllText("[data-proposed-".concat(selectorBase, "-value]"), formatMoney(Number(proposedTokens || 0) * unitValue));
      document.querySelectorAll("[data-change-".concat(selectorBase, "]")).forEach((el) => {
        const delta = Number(proposedTokens || 0) - Number(currentTokens || 0);
        el.textContent = signedDeltaText(proposedTokens, currentTokens, false);
        el.classList.remove("positive", "negative");
        if (delta > 0) el.classList.add("positive");
        if (delta < 0) el.classList.add("negative");
      });
    }
    function updateWalletReservationPreview(form, kind) {
      if (!form) return;
      const state = kind === "member" ? memberPreviewState(form) : businessPreviewState(form);
      const savedValue = Number(form.dataset.savedValue || 0);
      const savedTokens = Number(form.dataset.savedTokens || 0);
      const delta = state.betaValue - savedValue;
      const deltaText = delta === 0 ? formatMoney(0) : (delta > 0 ? "+" : "-") + formatMoney(Math.abs(delta));
      const lockedBalance = lockedBalanceForKind(kind);
      const approvedFund = Number(form.dataset.currentApprovedReservationValue || 0);
      setAllText("[data-locked-balance]", formatMoney(lockedBalance));
      setAllText("[data-current-total-value]", formatMoney(savedValue));
      setAllText("[data-current-total-tokens]", formatInteger(savedTokens));
      setAllText("[data-preview-total-value]", formatMoney(state.betaValue));
      setAllText("[data-preview-total-tokens]", formatInteger(state.betaTokens));
      setAllText("[data-reservation-preview]", formatMoney(state.walletValue));
      setAllText("[data-membership-fund]", formatMoney(approvedFund));
      document.querySelectorAll("[data-preview-delta]").forEach((el) => {
        el.textContent = deltaText;
        el.classList.remove("positive", "negative", "flat");
        el.classList.add(delta > 0 ? "positive" : delta < 0 ? "negative" : "flat");
      });
      if (kind === "member") {
        applyClassPreview("investment", state.investmentTokens, Number(form.dataset.currentInvestmentTokens || 0), TOKEN_PRICE);
        applyClassPreview("donation", state.donationTokens, Number(form.dataset.currentDonationTokens || 0), TOKEN_PRICE);
        applyClassPreview("payitforward", state.payItForwardTokens, Number(form.dataset.currentPayItForwardTokens || 0), TOKEN_PRICE);
        applyClassPreview("kids", state.kidsTokens, Number(form.dataset.currentKidsTokens || 0), KIDS_TOKEN_PRICE);
        setAllText("[data-proposed-landholder-tokens]", formatInteger(state.landholderTokens));
        setAllText("[data-proposed-landholder-value]", formatMoney(state.landholderTokens * TOKEN_PRICE));
        document.querySelectorAll("[data-change-landholder]").forEach((el) => {
          const currentTokens = Number(form.dataset.currentLandholderTokens || 0);
          const landholderDelta = state.landholderTokens - currentTokens;
          el.textContent = signedDeltaText(state.landholderTokens, currentTokens, false);
          el.classList.remove("positive", "negative", "flat");
          el.classList.add(landholderDelta > 0 ? "positive" : landholderDelta < 0 ? "negative" : "flat");
        });
      } else {
        applyClassPreview("business-investment", state.investTokens, Number(form.dataset.currentInvestTokens || 0), businessUnitValue());
        applyClassPreview("business-donation", state.donationTokens, Number(form.dataset.currentDonationTokens || 0), businessUnitValue());
        applyClassPreview("business-payitforward", state.payItForwardTokens, Number(form.dataset.currentPayItForwardTokens || 0), businessUnitValue());
        setAllText("[data-proposed-business-landholder-tokens]", formatInteger(state.landholderTokens));
        setAllText("[data-proposed-business-landholder-value]", formatMoney(state.landholderTokens * businessUnitValue()));
        document.querySelectorAll("[data-change-business-landholder]").forEach((el) => {
          const currentTokens = Number(form.dataset.currentLandholderTokens || 0);
          const landholderDelta = state.landholderTokens - currentTokens;
          el.textContent = signedDeltaText(state.landholderTokens, currentTokens, false);
          el.classList.remove("positive", "negative", "flat");
          el.classList.add(landholderDelta > 0 ? "positive" : landholderDelta < 0 ? "negative" : "flat");
        });
      }
    }
    function hydrateReservationWorkspace(kind, data, form) {
      if (!form) return;
      const refreshedAt = data.updated_at || data.refreshed_at || (/* @__PURE__ */ new Date()).toISOString();
      const walletTotalValue = Number(data.reservation_value || 0);
      const walletTotalTokens = Number((kind === "business" ? data.total_tokens || data.tokens_total : data.beta_tokens_total || 0) || 0);
      form.dataset.savedValue = betaValueFromWalletTotal(walletTotalValue, kind);
      form.dataset.savedTokens = betaTokensFromWalletTotal(walletTotalTokens);
      form.dataset.savedAt = refreshedAt;
      setAllText("[data-preview-saved-at]", refreshedAt);
      if (kind === "member") {
        form.dataset.currentInvestmentTokens = Number(data.investment_tokens || 0);
        form.dataset.currentDonationTokens = Number(data.donation_tokens || 0);
        form.dataset.currentPayItForwardTokens = Number(data.pay_it_forward_tokens || 0);
        form.dataset.currentKidsTokens = Number(data.kids_tokens || 0);
        setAllText("[data-current-investment-tokens]", formatInteger(data.investment_tokens));
        setAllText("[data-current-investment-value]", formatMoney(Number(data.investment_tokens || 0) * TOKEN_PRICE));
        setAllText("[data-current-donation-tokens]", formatInteger(data.donation_tokens));
        setAllText("[data-current-donation-value]", formatMoney(Number(data.donation_tokens || 0) * TOKEN_PRICE));
        setAllText("[data-current-payitforward-tokens]", formatInteger(data.pay_it_forward_tokens));
        setAllText("[data-current-payitforward-value]", formatMoney(Number(data.pay_it_forward_tokens || 0) * TOKEN_PRICE));
        setAllText("[data-current-kids-tokens]", formatInteger(data.kids_tokens));
        setAllText("[data-current-kids-value]", formatMoney(Number(data.kids_tokens || 0) * KIDS_TOKEN_PRICE));
        setAllText("[data-current-landholder-hectares]", Number(data.landholder_hectares || 0).toLocaleString(void 0, { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + " ha");
        setAllText("[data-current-landholder-tokens]", formatInteger(data.landholder_tokens));
        form.dataset.currentLandholderTokens = Number(data.landholder_tokens || 0);
        setAllText("[data-current-landholder-value]", formatMoney(Number(data.landholder_tokens || 0) * TOKEN_PRICE));
      } else {
        form.dataset.currentInvestTokens = Number(data.invest_tokens || 0);
        form.dataset.currentDonationTokens = Number(data.donation_tokens || 0);
        form.dataset.currentPayItForwardTokens = Number(data.pay_it_forward_tokens || 0);
        form.dataset.currentLandholderTokens = Number(data.landholder_tokens || 0);
        setAllText("[data-current-business-investment-tokens]", formatInteger(data.invest_tokens));
        setAllText("[data-current-business-investment-value]", formatMoney(Number(data.invest_tokens || 0) * businessUnitValue()));
        setAllText("[data-current-business-donation-tokens]", formatInteger(data.donation_tokens));
        setAllText("[data-current-business-donation-value]", formatMoney(Number(data.donation_tokens || 0) * businessUnitValue()));
        setAllText("[data-current-business-payitforward-tokens]", formatInteger(data.pay_it_forward_tokens));
        setAllText("[data-current-business-payitforward-value]", formatMoney(Number(data.pay_it_forward_tokens || 0) * businessUnitValue()));
        setAllText("[data-current-business-landholder-hectares]", Number(data.landholder_hectares || 0).toLocaleString(void 0, { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + " ha");
        setAllText("[data-current-business-landholder-tokens]", formatInteger(data.landholder_tokens || 0));
        setAllText("[data-current-business-landholder-value]", formatMoney(Number(data.landholder_tokens || 0) * businessUnitValue()));
      }
      updateWalletReservationPreview(form, kind);
    }
    function populateWallet(page, data) {
      var _a, _b, _c, _d, _e, _f, _g, _h, _i, _j, _k, _l, _m, _n, _o, _p, _q, _r, _s, _t, _u, _v, _w, _x, _y, _z, _A, _B, _C, _D, _E, _F;
      if (page === "member") {
        setAllText("[data-member-name]", data.full_name);
        setAllText("[data-member-number]", formatMemberNumberDisplay(data.member_number));
        setAllText("[data-member-state]", data.state);
        setAllText("[data-wallet-status]", data.wallet_status);
        setAllText("[data-token-total]", formatInteger(data.tokens_total));
        setAllText("[data-membership-fund]", formatMoney(Number(data.approved_reservation_value || 0)));
        setAllText("[data-reserved-tokens]", formatInteger(data.reserved_tokens));
        setAllText("[data-investment-tokens]", formatInteger(data.investment_tokens));
        setAllText("[data-donation-tokens]", formatInteger(data.donation_tokens));
        setAllText("[data-payitforward-tokens]", formatInteger(data.pay_it_forward_tokens));
        setAllText("[data-kids-tokens]", formatInteger(data.kids_tokens));
        setAllText("[data-landholder-hectares]", Number(data.landholder_hectares || 0).toLocaleString(void 0, { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + " ha");
        setAllText("[data-landholder-tokens]", formatInteger(data.landholder_tokens));
        setAllText("[data-beta-balance]", formatMoney(betaValueFromWalletTotal(data.reservation_value, "member")));
        setAllText("[data-reservation-value]", formatMoney(betaValueFromWalletTotal(data.reservation_value, "member")));
        setAllText("[data-preview-total-tokens]", formatInteger(data.beta_tokens_total || 0));
        setAllText("[data-unread-announcements]", Number(data.announcement_unread || 0).toLocaleString());
        setAllText("[data-open-proposals]", Number(data.open_proposals || 0).toLocaleString());
        setAllText("[data-refreshed-at]", data.refreshed_at || "");
        setAllText("[data-support-code-display]", data.support_code_display || "--- ---");
        setAllText("[data-support-reference]", data.support_reference || "Support verification only");
        const events = document.querySelector("[data-wallet-events]");
        const news = document.querySelector("[data-wallet-news]");
        const proposals = document.querySelector("[data-wallet-proposals]");
        if (events) events.innerHTML = renderWalletEvents(data.events || []);
        if (news) news.innerHTML = renderAnnouncements(data.announcements || [], true);
        if (proposals) proposals.innerHTML = (data.proposals || []).map((item) => renderProposalCard(item, true)).join("") || '<div class="list-item"><div class="muted">No active proposals.</div></div>';
        const form = document.querySelector("[data-reservation-update=member]");
        if (form) {
          const preview = form.querySelector("[data-reservation-preview]");
          const fields = {
            reserved_tokens: form.querySelector("[name=reserved_tokens]"),
            investment_tokens: form.querySelector("[name=investment_tokens]"),
            donation_tokens: form.querySelector("[name=donation_tokens]"),
            pay_it_forward_tokens: form.querySelector("[name=pay_it_forward_tokens]"),
            kids_tokens: form.querySelector("[name=kids_tokens]"),
            landholder_hectares: form.querySelector("[name=landholder_hectares]")
          };
          Object.entries(fields).forEach(([key, input]) => {
            if (input) input.value = Number(data[key] || 0);
          });
          const landholderPreview = form.querySelector("[data-landholder-preview]");
          if (landholderPreview) landholderPreview.textContent = formatInteger(Math.ceil(Number(data.landholder_hectares || 0)) > 0 ? Math.ceil(Number(data.landholder_hectares || 0)) * 1e3 : 0) + " COG$";
          if (preview) preview.textContent = formatMoney(data.reservation_value || 0);
          form.dataset.currentApprovedReservationValue = Number(data.approved_reservation_value || 0);
          hydrateReservationWorkspace("member", data, form);
        }
      }
      if (page === "business") {
        setAllText("[data-legal-name]", data.legal_name);
        setAllText("[data-abn]", data.abn);
        setAllText("[data-business-state]", data.state);
        setAllText("[data-business-status]", data.wallet_status);
        setAllText("[data-business-token-total]", formatInteger(data.total_tokens));
        setAllText("[data-business-reserved-tokens]", formatInteger(data.reserved_tokens));
        setAllText("[data-invest-tokens]", formatInteger(data.invest_tokens));
        setAllText("[data-business-donation-tokens]", formatInteger(data.donation_tokens));
        setAllText("[data-business-payitforward-tokens]", formatInteger(data.pay_it_forward_tokens));
        setAllText("[data-business-landholder-hectares]", Number(data.landholder_hectares || 0).toLocaleString(void 0, { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + " ha");
        setAllText("[data-business-landholder-tokens]", formatInteger(data.landholder_tokens || 0));
        setAllText("[data-business-beta-balance]", formatMoney(betaValueFromWalletTotal(data.reservation_value, "business")));
        setAllText("[data-business-value]", formatMoney(betaValueFromWalletTotal(data.reservation_value, "business")));
        setAllText("[data-fixed-bnft-fee]", formatMoney(data.fixed_bnft_fee || BNFT_FIXED_FEE));
        setAllText("[data-unread-announcements]", Number(data.announcement_unread || 0).toLocaleString());
        setAllText("[data-open-proposals]", Number(data.open_proposals || 0).toLocaleString());
        setAllText("[data-refreshed-at]", data.refreshed_at || "");
        setAllText("[data-support-code-display]", data.support_code_display || "--- ---");
        setAllText("[data-support-reference]", data.support_reference || "Support verification only");
        const events = document.querySelector("[data-wallet-events]");
        const news = document.querySelector("[data-wallet-news]");
        const proposals = document.querySelector("[data-wallet-proposals]");
        if (events) events.innerHTML = renderWalletEvents(data.events || []);
        if (news) news.innerHTML = renderAnnouncements(data.announcements || [], true);
        if (proposals) proposals.innerHTML = (data.proposals || []).map((item) => renderProposalCard(item, false)).join("") || '<div class="list-item"><div class="muted">No vote notices available.</div></div>';
        const form = document.querySelector("[data-reservation-update=business]");
        if (form) {
          const preview = form.querySelector("[data-reservation-preview]");
          const fields = {
            reserved_tokens: form.querySelector("[name=reserved_tokens]"),
            invest_tokens: form.querySelector("[name=invest_tokens]"),
            donation_tokens: form.querySelector("[name=donation_tokens]"),
            pay_it_forward_tokens: form.querySelector("[name=pay_it_forward_tokens]"),
            landholder_hectares: form.querySelector("[name=landholder_hectares]")
          };
          Object.entries(fields).forEach(([key, input]) => {
            if (input) input.value = Number(data[key] || 0);
          });
          const landholderPreview = form.querySelector("[data-landholder-preview]");
          if (landholderPreview) landholderPreview.textContent = formatInteger(Math.ceil(Number(data.landholder_hectares || 0)) > 0 ? Math.ceil(Number(data.landholder_hectares || 0)) * 1e3 : 0) + " COG$";
          if (preview) preview.textContent = formatMoney(data.reservation_value || 0);
          form.dataset.currentApprovedReservationValue = Number(data.approved_reservation_value || 0);
          hydrateReservationWorkspace("business", data, form);
        }
      }
      if (page === "admin") {
        setText("[data-admin-snft]", data.snft_members);
        setText("[data-admin-bnft]", data.bnft_businesses);
        setText("[data-admin-wallets]", data.active_wallets);
        setText("[data-admin-crm]", data.crm_pending);
        setText("[data-admin-announcements]", data.announcements_total);
        setText("[data-admin-open-votes]", data.open_votes);
        setText("[data-admin-open-disputes]", data.open_disputes);
        setText("[data-admin-name]", ((_a = data.admin_profile) == null ? void 0 : _a.display_name) || "");
        setText("[data-admin-role]", ((_b = data.admin_profile) == null ? void 0 : _b.role_name) || "");
        setText("[data-admin-reserved-tokens]", formatInteger(((_d = (_c = data.token_mix) == null ? void 0 : _c.all) == null ? void 0 : _d.reserved_tokens) || 0));
        setText("[data-admin-investment-tokens]", formatInteger(((_f = (_e = data.token_mix) == null ? void 0 : _e.all) == null ? void 0 : _f.investment_tokens) || 0));
        setText("[data-admin-donation-tokens]", formatInteger(((_h = (_g = data.token_mix) == null ? void 0 : _g.all) == null ? void 0 : _h.donation_tokens) || 0));
        setText("[data-admin-payitforward-tokens]", formatInteger(((_j = (_i = data.token_mix) == null ? void 0 : _i.all) == null ? void 0 : _j.pay_it_forward_tokens) || 0));
        setText("[data-admin-kids-tokens]", formatInteger(((_l = (_k = data.token_mix) == null ? void 0 : _k.all) == null ? void 0 : _l.kids_tokens) || 0));
        setText("[data-admin-landholder-hectares]", Number(((_n = (_m = data.token_mix) == null ? void 0 : _m.all) == null ? void 0 : _n.landholder_hectares) || 0).toLocaleString(void 0, { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + " ha");
        setText("[data-admin-landholder-tokens]", formatInteger(((_p = (_o = data.token_mix) == null ? void 0 : _o.all) == null ? void 0 : _p.landholder_tokens) || 0));
        setText("[data-admin-total-tokens]", formatInteger(((_r = (_q = data.token_mix) == null ? void 0 : _q.all) == null ? void 0 : _r.total_tokens) || 0));
        setAllText("[data-refreshed-at]", data.refreshed_at || "");
        const tbody = document.querySelector("[data-admin-table]");
        if (tbody) {
          tbody.innerHTML = (data.crm_queue || []).map((item) => "\n          <tr>\n            <td>".concat(escapeHtml(item.sync_entity), "</td>\n            <td>").concat(escapeHtml(item.entity_id), "</td>\n            <td>").concat(escapeHtml(item.status), "</td>\n            <td>").concat(escapeHtml(item.attempts), "</td>\n            <td>").concat(escapeHtml(item.last_error || ""), "</td>\n          </tr>\n        ")).join("") || '<tr><td colspan="5" class="muted">CRM queue is clear.</td></tr>';
        }
        const updates = document.querySelector("[data-admin-reservation-updates]");
        if (updates) {
          updates.innerHTML = (data.reservation_updates || []).map((item) => '\n          <div class="list-item">\n            <div class="wallet-meta"><span class="pill">'.concat(escapeHtml(item.subject_type), "</span><span>").concat(escapeHtml(item.subject_ref), '</span><span class="muted">').concat(escapeHtml(item.created_at || ""), "</span></div>\n            <strong>").concat(escapeHtml(item.action_type || "wallet_update"), "</strong>\n            <div>").concat(formatInteger(item.previous_units || 0), " \u2192 ").concat(formatInteger(item.new_units || 0), " COG$ \xB7 ").concat(formatMoney(item.previous_value || 0), " \u2192 ").concat(formatMoney(item.new_value || 0), '</div>\n            <div class="muted">\u0394 Invest ').concat(Number(item.investment_delta || 0).toLocaleString(), " \xB7 \u0394 Donation ").concat(Number(item.donation_delta || 0).toLocaleString(), " \xB7 \u0394 Pay It Forward ").concat(Number(item.pay_it_forward_delta || 0).toLocaleString(), " \xB7 \u0394 Kids ").concat(Number(item.kids_delta || 0).toLocaleString(), " \xB7 \u0394 Landholder ").concat(Number(item.landholder_delta || 0).toLocaleString(), " (").concat(Number(item.landholder_hectares_delta || 0).toLocaleString(void 0, { minimumFractionDigits: 0, maximumFractionDigits: 2 }), " ha)</div>\n            ").concat(item.note ? '<div class="muted">'.concat(escapeHtml(item.note), "</div>") : "", "\n          </div>\n        ")).join("") || '<div class="list-item"><div class="muted">No reservation updates yet.</div></div>';
        }
        const news = document.querySelector("[data-admin-news-list]");
        const votes = document.querySelector("[data-admin-votes-list]");
        const disputes = document.querySelector("[data-admin-disputes-list]");
        if (news) news.innerHTML = renderAdminNews(data.news || []);
        if (votes) votes.innerHTML = renderAdminVotes(data.votes || [], true);
        if (disputes) disputes.innerHTML = (data.disputes || []).map((item) => '\n        <div class="list-item">\n          <div class="wallet-meta"><span class="pill '.concat(item.status === "open" ? "warn" : "ok", '">').concat(escapeHtml(item.status), '</span><span class="muted">').concat(escapeHtml(item.created_at), "</span></div>\n          <strong>").concat(escapeHtml(item.proposal_title || ""), "</strong>\n          <div>").concat(escapeHtml(item.subject_ref || ""), " \xB7 ").concat(escapeHtml(item.reason || ""), '</div>\n          <div class="inline-actions compact">').concat(item.status === "open" ? '<button class="cta secondary small" type="button" data-resolve-dispute="'.concat(item.id, '" data-dispute-status="resolved">Resolve</button><button class="cta secondary small" type="button" data-resolve-dispute="').concat(item.id, '" data-dispute-status="rejected">Reject</button>') : '<span class="muted">'.concat(escapeHtml(item.resolved_by || ""), "</span>"), "</div>\n        </div>\n      ")).join("") || '<div class="list-item"><div class="muted">No tally disputes logged.</div></div>';
      }
      if (page === "community") {
        setText("[data-member-count]", formatInteger(data.snft_members));
        setText("[data-business-count]", formatInteger(data.bnft_businesses));
        setText("[data-total-wallets]", formatInteger(data.active_wallets));
        setText("[data-total-reservation]", formatMoney(data.total_reservation_value));
        setText("[data-snft-token-total]", formatInteger(data.snft_tokens_total));
        setText("[data-bnft-token-total]", formatInteger(data.bnft_tokens_total));
        setText("[data-all-class-token-total]", formatInteger(getAllClassTokenTotal(data)));
        setText("[data-all-reserved-tokens]", formatInteger(((_t = (_s = data.token_mix) == null ? void 0 : _s.all) == null ? void 0 : _t.reserved_tokens) || 0));
        setText("[data-all-investment-tokens]", formatInteger(((_v = (_u = data.token_mix) == null ? void 0 : _u.all) == null ? void 0 : _v.investment_tokens) || 0));
        setText("[data-all-donation-tokens]", formatInteger(((_x = (_w = data.token_mix) == null ? void 0 : _w.all) == null ? void 0 : _x.donation_tokens) || 0));
        setText("[data-all-payitforward-tokens]", formatInteger(((_z = (_y = data.token_mix) == null ? void 0 : _y.all) == null ? void 0 : _z.pay_it_forward_tokens) || 0));
        setText("[data-all-kids-tokens]", formatInteger(((_B = (_A = data.token_mix) == null ? void 0 : _A.all) == null ? void 0 : _B.kids_tokens) || 0));
        setText("[data-all-landholder-hectares]", Number(((_D = (_C = data.token_mix) == null ? void 0 : _C.all) == null ? void 0 : _D.landholder_hectares) || 0).toLocaleString(void 0, { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + " ha");
        setText("[data-all-landholder-tokens]", formatInteger(((_F = (_E = data.token_mix) == null ? void 0 : _E.all) == null ? void 0 : _F.landholder_tokens) || 0));
        setText("[data-snft-value-total]", formatMoney(data.snft_value_total));
        setText("[data-bnft-value-total]", formatMoney(data.bnft_value_total));
        setAllText("[data-refreshed-at]", data.refreshed_at || "");
        const feed = document.querySelector("[data-community-member-feed]");
        if (feed) {
          feed.innerHTML = (data.recent_member_numbers || []).map((item) => '\n          <div class="list-item feed-item">\n            <div class="wallet-meta">\n              <span class="pill">'.concat(escapeHtml(item.class_label), '</span>\n              <span class="muted">').concat(escapeHtml(item.created_at), "</span>\n            </div>\n            <div><strong>").concat(escapeHtml(formatMemberNumberDisplay(item.member_number)), "</strong></div>\n            <div>").concat(escapeHtml(item.display_name || ""), '</div>\n            <div class="muted">').concat(escapeHtml(item.token_mix_summary || ""), '</div>\n            <div class="wallet-meta"><span>').concat(formatInteger(item.token_units), " tokens</span><span>").concat(formatMoney(item.total_value), "</span></div>\n          </div>\n        ")).join("") || '<div class="list-item"><div class="muted">No member numbers recorded yet.</div></div>';
        }
        const tbody = document.querySelector("[data-community-events]");
        if (tbody) {
          tbody.innerHTML = (data.recent_events || []).map((item) => "\n          <tr>\n            <td>".concat(escapeHtml(item.created_at), "</td>\n            <td>").concat(escapeHtml(item.subject_type), "</td>\n            <td>").concat(escapeHtml(item.subject_ref), "</td>\n            <td>").concat(escapeHtml(item.event_type), "</td>\n          </tr>\n        ")).join("") || '<tr><td colspan="4" class="muted">No infrastructure events yet.</td></tr>';
        }
        const announcements = document.querySelector("[data-community-news]");
        const proposals = document.querySelector("[data-community-votes]");
        if (announcements) announcements.innerHTML = renderAdminNews(data.announcements || []);
        if (proposals) proposals.innerHTML = renderAdminVotes(data.proposals || [], false);
        const beta = document.querySelector("[data-community-binding]");
        if (beta) beta.textContent = "Beta only \xB7 proposed intent";
      }
    }
    function applyWalletPrefillFromQuery() {
      const page = document.body.getAttribute("data-vault-page");
      if (!page) return;
      const params = new URLSearchParams(window.location.search);
      const role = page === "business" ? "bnft" : "snft";
      const rawNumber = role === "bnft" ? normaliseDigits2(params.get("abn") || params.get("member_number") || "") : normaliseDigits2(params.get("member_number") || "");
      const displayNumber = role === "bnft" ? rawNumber : formatMemberNumberDisplay(rawNumber);
      const email = String(params.get("email") || "").trim();
      const mode = params.get("mode") || "";
      document.querySelectorAll('form[data-auth-form="login"], form[data-auth-form="setup"], form[data-reset-password-form], form[data-recover-number-form]').forEach((form) => {
        const numberInput = form.querySelector('[name="abn"], [name="member_number"]');
        const emailInput = form.querySelector('[name="email"]');
        if (numberInput) {
          const currentNumber = String(numberInput.value || "").trim();
          if ((!currentNumber || looksLikeEmail2(currentNumber)) && rawNumber) {
            numberInput.value = role === "bnft" ? rawNumber : displayNumber;
          }
        }
        if (emailInput) {
          const currentEmail = String(emailInput.value || "").trim();
          if (!currentEmail && email) {
            emailInput.value = email;
          }
        }
        if (numberInput && emailInput) {
          const reconciled = reconcileWalletIdentityFields2(role, numberInput.value, emailInput.value, displayNumber || rawNumber, email);
          if (reconciled.number && numberInput.value !== reconciled.number) numberInput.value = role === "bnft" ? normaliseDigits2(reconciled.number) : reconciled.number;
          if (reconciled.email !== void 0 && emailInput.value !== reconciled.email) emailInput.value = reconciled.email;
        }
      });
      const setupPanel = document.querySelector("[data-setup-panel]");
      if (setupPanel && (mode === "setup" || params.get("setup") === "1" || params.get("activation_token"))) {
        setupPanel.classList.remove("hidden");
      }
    }
    function hydrateThankYouPage() {
      const shell = document.querySelector("[data-thankyou-kind]");
      if (!shell) return;
      const params = new URLSearchParams(window.location.search);
      const role = shell.getAttribute("data-thankyou-kind") || "snft";
      const memberNumber = params.get("member_number") || params.get("abn") || "";
      const email = params.get("email") || "";
      const name = params.get("name") || params.get("business") || "";
      const reservationValue = params.get("reservation_value") || "";
      const walletPath = role === "bnft" ? "../../wallets/business.html" : "../../wallets/member.html";
      document.querySelectorAll("[data-thankyou-name]").forEach((el) => el.textContent = name || "Member");
      document.querySelectorAll("[data-thankyou-email]").forEach((el) => el.textContent = email || "\u2014");
      document.querySelectorAll("[data-thankyou-member-number]").forEach((el) => el.textContent = role === "snft" ? formatMemberNumberDisplay(memberNumber) : memberNumber || "\u2014");
      document.querySelectorAll("[data-thankyou-value]").forEach((el) => el.textContent = reservationValue || "\u2014");
      document.querySelectorAll("[data-wallet-setup-link]").forEach((link) => {
        const url = new URL(walletPath, window.location.href);
        if (memberNumber) url.searchParams.set(role === "bnft" ? "abn" : "member_number", memberNumber);
        if (email) url.searchParams.set("email", email);
        url.searchParams.set("mode", "setup");
        link.setAttribute("href", url.toString());
      });
    }
    function attachRecoveryForms() {
      document.querySelectorAll("[data-reset-password-form]").forEach((form) => {
        const status = form.querySelector(".form-status");
        form.addEventListener("submit", async (e) => {
          e.preventDefault();
          hideStatus(status);
          const btn = form.querySelector("button[type=submit]");
          if (btn) {
            btn.disabled = true;
            btn.dataset.original = btn.textContent;
            btn.textContent = "Resetting\u2026";
          }
          try {
            const payload = serialiseForm(form);
            const result = await request("auth/reset-password", { method: "POST", body: JSON.stringify(payload) });
            persistWalletIdentity(payload.role || (document.body.getAttribute("data-vault-page") === "business" ? "bnft" : "snft"), payload, result, true);
            showStatus(status, "Password reset complete. Loading your wallet\u2026", "success");
            setTimeout(() => window.location.reload(), 700);
          } catch (err) {
            showStatus(status, err.message || "Unable to reset password.", "error");
          } finally {
            if (btn) {
              btn.disabled = false;
              btn.textContent = btn.dataset.original || "Reset password";
            }
          }
        });
      });
      document.querySelectorAll("[data-recover-number-form]").forEach((form) => {
        const status = form.querySelector(".form-status");
        const resultBox = form.parentElement.querySelector("[data-recovery-result]");
        form.addEventListener("submit", async (e) => {
          e.preventDefault();
          hideStatus(status);
          if (resultBox) resultBox.textContent = "Checking the details on file\u2026";
          const btn = form.querySelector("button[type=submit]");
          if (btn) {
            btn.disabled = true;
            btn.dataset.original = btn.textContent;
            btn.textContent = "Recovering\u2026";
          }
          try {
            const payload = serialiseForm(form);
            const result = await request("auth/recover-number", { method: "POST", body: JSON.stringify(payload) });
            const display = payload.role === "snft" ? formatMemberNumberDisplay(result.member_number || result.member_number_display || "") : result.member_number || result.member_number_display || "";
            if (resultBox) resultBox.textContent = (payload.role === "snft" ? "Recovered member number: " : "Recovered ABN: ") + display;
            const numberFieldName = payload.role === "bnft" ? "abn" : "member_number";
            document.querySelectorAll("[name=" + numberFieldName + '], [name="member_number"]').forEach((input) => {
              if (!input.value) input.value = display;
            });
            if ((payload.auth_channel || "email") === "email") {
              document.querySelectorAll("[name=email]").forEach((input) => {
                if (!input.value) input.value = payload.auth_value || "";
              });
            }
            const setupPanel = document.querySelector("[data-setup-panel]");
            if (setupPanel) setupPanel.classList.remove("hidden");
            showStatus(status, "Recovery successful. Your wallet forms have been prefilled. Include the wallet security code when prompted for higher-assurance recovery.", "success");
          } catch (err) {
            if (resultBox) resultBox.textContent = "";
            showStatus(status, err.message || "Unable to recover the member number.", "error");
          } finally {
            if (btn) {
              btn.disabled = false;
              btn.textContent = btn.dataset.original || "Recover";
            }
          }
        });
      });
    }
    async function hydratePage() {
      const page = document.body.getAttribute("data-vault-page");
      if (!page) return;
      if (window.COGS_FORCE_GUEST_WALLET_CONTEXT && (page === "member" || page === "business")) {
        const authedPanels = Array.from(document.querySelectorAll("[data-authed-panel]"));
        const guestPanels = Array.from(document.querySelectorAll("[data-guest-panel]"));
        authedPanels.forEach((panel) => panel.classList.add("hidden"));
        guestPanels.forEach((panel) => panel.classList.remove("hidden"));
        return;
      }
      const authedPanels = Array.from(document.querySelectorAll("[data-authed-panel]"));
      const guestPanels = Array.from(document.querySelectorAll("[data-guest-panel]"));
      const status = document.querySelector("[data-vault-status]");
      try {
        const route = page === "member" ? "vault/member" : page === "business" ? "vault/business" : page === "admin" ? "admin/summary" : page === "community" ? "community" : null;
        if (!route) return;
        const data = await request(route, { method: "GET" });
        guestPanels.forEach((panel) => panel.classList.add("hidden"));
        authedPanels.forEach((panel) => panel.classList.remove("hidden"));
        if (status && page !== "community") showStatus(status, page === "admin" ? "Admin console live." : "Wallet session active.", "success");
        populateWallet(page, data);
      } catch (err) {
        if (page === "member" || page === "business" || page === "admin") {
          guestPanels.forEach((panel) => panel.classList.remove("hidden"));
          authedPanels.forEach((panel) => panel.classList.add("hidden"));
        }
        if (status) {
          showStatus(status, err.message || "Unable to load dashboard.", "error");
        }
      }
    }
    function attachWalletActivityToggle() {
      const toggleButtons = document.querySelectorAll("[data-activity-toggle]");
      const panels = document.querySelectorAll("[data-activity-panel]");
      const closeButtons = document.querySelectorAll("[data-activity-close]");
      if (!toggleButtons.length || !panels.length) return;
      const closeAll = () => panels.forEach((panel) => panel.classList.add("hidden"));
      toggleButtons.forEach((btn) => btn.addEventListener("click", () => {
        panels.forEach((panel) => panel.classList.toggle("hidden"));
      }));
      closeButtons.forEach((btn) => btn.addEventListener("click", closeAll));
    }
    function attachLogout() {
      document.querySelectorAll("[data-logout]").forEach((btn) => {
        btn.addEventListener("click", async () => {
          try {
            await request("auth/logout", { method: "POST", body: "{}" });
          } catch (e) {
          }
          window.location.reload();
        });
      });
    }
    function attachAdminAuthForms() {
      const loginForm = document.querySelector("[data-admin-login-form]");
      if (loginForm) {
        const status = loginForm.querySelector(".form-status");
        loginForm.addEventListener("submit", async (e) => {
          e.preventDefault();
          hideStatus(status);
          try {
            const payload = serialiseForm(loginForm);
            await request("auth/admin-login", { method: "POST", body: JSON.stringify(payload) });
            showStatus(status, "Admin session opened.", "success");
            setTimeout(() => window.location.reload(), 500);
          } catch (err) {
            showStatus(status, err.message || "Unable to sign in.", "error");
          }
        });
      }
      const bootstrapForm = document.querySelector("[data-admin-bootstrap-form]");
      if (bootstrapForm) {
        const status = bootstrapForm.querySelector(".form-status");
        bootstrapForm.addEventListener("submit", async (e) => {
          e.preventDefault();
          hideStatus(status);
          try {
            const payload = serialiseForm(bootstrapForm);
            const result = await request("auth/admin-bootstrap", { method: "POST", body: JSON.stringify(payload) });
            const secretBox = document.querySelector("[data-admin-bootstrap-result]");
            if (secretBox) {
              secretBox.hidden = false;
              const s = secretBox.querySelector("[data-admin-secret]");
              const o = secretBox.querySelector("[data-admin-otpauth]");
              if (s) s.textContent = result.totp_secret || "";
              if (o) o.textContent = result.otpauth_url || "";
            }
            showStatus(status, result.message || "Admin bootstrap complete.", "success");
          } catch (err) {
            showStatus(status, err.message || "Unable to bootstrap admin.", "error");
          }
        });
      }
    }
    function attachAdminForms() {
      const newsForm = document.querySelector("[data-admin-news-form]");
      if (newsForm) {
        const status = newsForm.querySelector(".form-status");
        newsForm.addEventListener("submit", async (e) => {
          e.preventDefault();
          hideStatus(status);
          try {
            const payload = serialiseForm(newsForm);
            await request("admin/news-push", { method: "POST", body: JSON.stringify(payload) });
            showStatus(status, "Announcement pushed to wallets.", "success");
            newsForm.reset();
            hydratePage();
          } catch (err) {
            showStatus(status, err.message || "Unable to push announcement.", "error");
          }
        });
      }
      const voteForm = document.querySelector("[data-admin-vote-form]");
      if (voteForm) {
        const status = voteForm.querySelector(".form-status");
        voteForm.addEventListener("submit", async (e) => {
          e.preventDefault();
          hideStatus(status);
          try {
            const payload = serialiseForm(voteForm);
            await request("admin/vote-push", { method: "POST", body: JSON.stringify(payload) });
            showStatus(status, "Vote pushed to wallets.", "success");
            voteForm.reset();
            hydratePage();
          } catch (err) {
            showStatus(status, err.message || "Unable to push vote.", "error");
          }
        });
      }
      const crmBtn = document.querySelector("[data-run-crm-sync]");
      if (crmBtn) {
        crmBtn.addEventListener("click", async () => {
          const status = document.querySelector("[data-admin-controls-status]");
          hideStatus(status);
          try {
            await request("admin/crm-sync", { method: "POST", body: "{}" });
            showStatus(status, "CRM sync processed.", "success");
            hydratePage();
          } catch (err) {
            showStatus(status, err.message || "Unable to run CRM sync.", "error");
          }
        });
      }
    }
    function attachReservationEditors() {
      document.querySelectorAll("[data-reservation-update]").forEach((form) => {
        const kind = form.getAttribute("data-reservation-update");
        const status = form.querySelector(".form-status") || document.querySelector(kind === "member" ? "[data-member-save-status]" : "[data-business-save-status]");
        const preview = form.querySelector("[data-reservation-preview]");
        const tokenFields = kind === "member" ? ["investment_tokens", "donation_tokens", "pay_it_forward_tokens", "kids_tokens", "landholder_hectares"] : ["invest_tokens", "donation_tokens", "pay_it_forward_tokens", "landholder_hectares"];
        const inputs = tokenFields.map((name) => form.querySelector("[name=".concat(name, "]"))).filter(Boolean);
        const updatePreview = () => {
          updateWalletReservationPreview(form, kind);
        };
        inputs.forEach((input) => {
          input.addEventListener("input", updatePreview);
          input.addEventListener("change", updatePreview);
        });
        updatePreview();
        form.addEventListener("submit", async (e) => {
          e.preventDefault();
          hideStatus(status);
          const btn = document.querySelector('button[form="'.concat(form.id, '"]')) || form.querySelector("button[type=submit]");
          if (btn) {
            btn.disabled = true;
            btn.dataset.original = btn.textContent;
            btn.textContent = "Saving\u2026";
          }
          try {
            const payload = serialiseForm(form);
            payload.reserved_tokens = 1;
            const route = kind === "member" ? "vault/member-update" : "vault/business-update";
            await request(route, { method: "POST", body: JSON.stringify(payload) });
            showStatus(status, "Wallet changes saved. The database-backed wallet has been refreshed with the new saved beta totals.", "success");
            hydratePage();
          } catch (err) {
            showStatus(status, err.message || "Unable to update token mix.", "error");
          } finally {
            if (btn) {
              btn.disabled = false;
              btn.textContent = btn.dataset.original || "Save";
            }
          }
        });
      });
      const snftInput = document.querySelector("[data-snft-reservation-input]");
      const snftPreview = document.querySelector("[data-snft-reservation-preview]");
      const updateSnftPreview = () => {
        if (snftInput && snftPreview) snftPreview.textContent = formatMoney(snftInput.value || 0);
      };
      if (snftInput) {
        snftInput.addEventListener("input", updateSnftPreview);
        snftInput.addEventListener("change", updateSnftPreview);
        updateSnftPreview();
      }
      const bnftInput = document.querySelector("[data-bnft-invest-input]");
      const bnftPreview = document.querySelector("[data-bnft-reservation-preview]");
      const updateBnftPreview = () => {
        if (bnftInput && bnftPreview) bnftPreview.textContent = formatMoney(BNFT_FIXED_FEE + Number(bnftInput.value || 0) * TOKEN_PRICE);
      };
      if (bnftInput) {
        bnftInput.addEventListener("input", updateBnftPreview);
        bnftInput.addEventListener("change", updateBnftPreview);
        updateBnftPreview();
      }
    }
    function clamp(value, min, max) {
      let num = Number(value || 0);
      if (!Number.isFinite(num)) num = 0;
      if (min != null) num = Math.max(min, num);
      if (max != null) num = Math.min(max, num);
      return num;
    }
    function attachStepControls(scope) {
      (scope || document).querySelectorAll("[data-stepper]").forEach((stepper) => {
        if (stepper.dataset.bound === "1") return;
        stepper.dataset.bound = "1";
        const input = stepper.querySelector("input");
        const min = Number(stepper.getAttribute("data-min") || (input == null ? void 0 : input.min) || 0);
        const maxAttr = stepper.getAttribute("data-max") || (input == null ? void 0 : input.max) || "";
        const max = maxAttr === "" ? null : Number(maxAttr);
        stepper.querySelectorAll("[data-step]").forEach((btn) => {
          btn.addEventListener("click", () => {
            const delta = Number(btn.getAttribute("data-step") || 0);
            const next = clamp(Number(input.value || 0) + delta, min, max);
            input.value = next;
            input.dispatchEvent(new Event("input", { bubbles: true }));
          });
        });
        input.addEventListener("change", () => {
          input.value = clamp(input.value, min, max);
        });
      });
    }
    function updatePublicReservationSummary(kind) {
      var _a, _b, _c;
      const shell = document.querySelector("[data-reserve-shell=".concat(kind, "]"));
      const summary = document.querySelector("[data-summary-box=".concat(kind, "]"));
      if (!shell || !summary) return;
      const map = kind === "snft" ? ["reserved_tokens", "investment_tokens", "donation_tokens", "pay_it_forward_tokens"] : ["reserved_tokens", "invest_tokens", "donation_tokens", "pay_it_forward_tokens"];
      let totalTokens = 0;
      let payableTokens = kind === "bnft" ? 0 : 0;
      map.forEach((name) => {
        const summaryInput = summary.querySelector("input[name=".concat(name, "]"));
        const formInput = shell.querySelector("form input[type=hidden][name=".concat(name, "]"));
        const value = Math.max(0, Number((summaryInput == null ? void 0 : summaryInput.value) || 0));
        totalTokens += value;
        if (formInput) formInput.value = value;
        if (kind === "snft" && name === "reserved_tokens") payableTokens += value;
        const countEl = summary.querySelector("[data-token-count=".concat(name, "]"));
        const valueEl = summary.querySelector("[data-line-value=".concat(name, "]"));
        if (countEl) countEl.textContent = formatInteger(value) + " COG$";
        if (valueEl) {
          const payableNow = kind === "bnft" ? name === "reserved_tokens" : name === "reserved_tokens";
          valueEl.textContent = payableNow ? formatMoney(value * TOKEN_PRICE) : value > 0 ? "Beta" : "Beta";
        }
      });
      if (kind === "snft") {
        const kidsValue = Math.max(0, Number(((_a = summary.querySelector("input[name=kids_tokens]")) == null ? void 0 : _a.value) || 0));
        const hectaresValue = Math.max(0, Number(((_b = summary.querySelector("input[name=landholder_hectares]")) == null ? void 0 : _b.value) || 0));
        const landholderTokens = Math.max(0, Number(((_c = summary.querySelector("input[name=landholder_tokens]")) == null ? void 0 : _c.value) || 0));
        totalTokens += kidsValue + landholderTokens;
        payableTokens += kidsValue;
        const hiddenKids = shell.querySelector("form input[name=kids_tokens]");
        const hiddenHa = shell.querySelector("form input[name=landholder_hectares]");
        const hiddenLand = shell.querySelector("form input[name=landholder_tokens]");
        const formKids = summary.querySelector("input[name=kids_count]");
        const formHa = summary.querySelector("input[name=hectares]");
        const formLand = summary.querySelector("input[name=landholder_tokens_input]");
        if (hiddenKids) hiddenKids.value = kidsValue;
        if (hiddenHa) hiddenHa.value = hectaresValue;
        if (hiddenLand) hiddenLand.value = landholderTokens;
        if (formKids && !formKids.matches(":focus")) formKids.value = String(kidsValue);
        if (formHa && !formHa.matches(":focus")) formHa.value = hectaresValue > 0 ? String(hectaresValue) : "";
        if (formLand && !formLand.matches(":focus")) formLand.value = String(landholderTokens || 0);
        const kidsCount = summary.querySelector("#kids-summary-count");
        const kidsValueEl = summary.querySelector("#kids-summary-value");
        const landHa = summary.querySelector("#land-summary-ha-display");
        const landTok = summary.querySelector("#land-summary-tok-count");
        const landVal = summary.querySelector("#land-summary-tok-value");
        const landCap = summary.querySelector("#form-land-cap-display");
        if (kidsCount) kidsCount.textContent = formatInteger(kidsValue) + " S-NFT COG$";
        if (kidsValueEl) kidsValueEl.textContent = formatMoney(kidsValue * KIDS_TOKEN_PRICE);
        if (landHa) landHa.textContent = hectaresValue > 0 ? "".concat(formatInteger(hectaresValue), " ha entered") : "0 ha entered";
        if (landTok) landTok.textContent = formatInteger(landholderTokens) + " COG$";
        if (landVal) landVal.textContent = landholderTokens > 0 ? "Beta tracked" : "Beta";
        if (landCap) landCap.textContent = hectaresValue > 0 ? "Maximum available: ".concat(formatInteger(Math.ceil(hectaresValue) * 1e3), " COG$") : "Maximum available: \u2014";
      }
      const totalValue = kind === "bnft" ? BNFT_FIXED_FEE : payableTokens * TOKEN_PRICE;
      const totalPreview = summary.querySelector("[data-total-preview]");
      const tokenPreview = summary.querySelector("[data-token-total-preview]");
      const button = shell.querySelector("[data-submit-label]");
      if (totalPreview) totalPreview.textContent = formatMoney(totalValue);
      if (tokenPreview) tokenPreview.textContent = formatInteger(totalTokens);
      if (button) button.textContent = (kind === "snft" ? "Continue to confirm and join \u2014 " : "Continue to business notice \u2014 ") + formatMoney(totalValue);
    }
    function showReserveSuccess(kind, result) {
      const shell = document.querySelector("[data-reserve-shell=".concat(kind, "]"));
      if (!shell) return;
      const form = shell.querySelector("form");
      const success = shell.querySelector("[data-reserve-success=".concat(kind, "]"));
      if (form) form.classList.add("hidden");
      if (success) {
        success.classList.add("show");
        const number = success.querySelector("[data-success-member-number]");
        const value = success.querySelector("[data-success-reservation-value]");
        if (number) number.textContent = formatMemberNumberDisplay(result.member_number || result.abn || "");
        if (value) value.textContent = result.reservation_value || "";
      }
    }
    function evaluateStewardshipQuiz(kind, form, modal) {
      const quiz = modal ? modal.querySelector("[data-stewardship-quiz=".concat(kind, "]")) : null;
      const progress = modal ? modal.querySelector("[data-quiz-progress=".concat(kind, "]")) : null;
      const accept = modal ? modal.querySelector("[data-accept-modal=".concat(kind, "]")) : null;
      if (!quiz) {
        return { passed: true, score: 0, total: 0, answers: {}, completed: true, paused: false };
      }
      const summaryHa = document.querySelector("[data-summary-box=".concat(kind, "] input[name=landholder_hectares]"));
      const hectaresField = form ? form.querySelector("[name=hectares]") : null;
      const hectares = Math.max(0, Number((summaryHa == null ? void 0 : summaryHa.value) || (hectaresField == null ? void 0 : hectaresField.value) || 0));
      const isLandholder = kind === "snft" && hectares > 0;
      quiz.querySelectorAll("[data-quiz-landholder]").forEach((el) => {
        el.classList.toggle("hidden", !isLandholder);
        if (!isLandholder) {
          el.querySelectorAll("input[type=radio]").forEach((input) => {
            input.checked = false;
          });
        }
      });
      const visibleQuestions = Array.from(quiz.querySelectorAll("[data-quiz-question]")).filter((question) => !question.classList.contains("hidden"));
      const answers = {};
      let score = 0;
      let completed = true;
      let paused = false;
      visibleQuestions.forEach((question) => {
        const key = question.getAttribute("data-quiz-question") || "";
        const correct = question.getAttribute("data-correct") || "";
        const selected = question.querySelector("input[type=radio]:checked");
        const value = selected ? selected.value : "";
        answers[key] = value;
        if (!value) completed = false;
        if (value && value === correct) score += 1;
        const pauseValue = question.getAttribute("data-pause-value");
        if (pauseValue && value === pauseValue) paused = true;
      });
      const total = visibleQuestions.length;
      const passed = completed && total > 0 && score === total && !paused;
      if (accept) accept.disabled = !passed;
      if (progress) {
        let kindClass = "warning";
        let title = "Complete the required stewardship questions.";
        let body = "Answer every visible question correctly to enable acceptance.";
        if (paused) {
          kindClass = "error";
          title = "Application paused for consultation.";
          body = "You selected a response that requires a 1:1 consultation before reservation can proceed.";
        } else if (completed && !passed) {
          kindClass = "error";
          title = "Stewardship score: ".concat(score, " / ").concat(total);
          body = "One or more answers are not yet aligned with the stewardship module requirements.";
        } else if (passed) {
          kindClass = "success";
          title = "Stewardship score: ".concat(score, " / ").concat(total);
          body = "Module passed. The accept button is now enabled.";
        } else if (total > 0) {
          title = "Stewardship score: ".concat(score, " / ").concat(total);
          body = "Work through each visible question to unlock acceptance.";
        }
        progress.className = "quiz-progress ".concat(kindClass);
        const actions = paused ? '<div class="quiz-progress-actions"><a class="cta secondary small" href="mailto:help_me@cogsaustralia.org?subject='.concat(encodeURIComponent("Help with the no-fiat rule"), '">Email help_me@cogsaustralia.org</a><a class="cta secondary small" href="').concat(escapeHtml(root() + "no-fiat/index.html"), '">Read the no-fiat rule</a></div>') : "";
        progress.innerHTML = "<strong>".concat(escapeHtml(title), "</strong><span>").concat(escapeHtml(body), "</span>").concat(actions);
      }
      return { passed, score, total, answers, completed, paused, isLandholder };
    }
    function resetReserveFlow(kind) {
      const shell = document.querySelector("[data-reserve-shell=".concat(kind, "]"));
      const summary = document.querySelector("[data-summary-box=".concat(kind, "]"));
      if (!shell || !summary) return;
      const form = shell.querySelector("form");
      const success = shell.querySelector("[data-reserve-success=".concat(kind, "]"));
      if (form) form.reset();
      if (success) success.classList.remove("show");
      if (form) form.classList.remove("hidden");
      summary.querySelectorAll("input[type=number]").forEach((input) => {
        if (input.name === "reserved_tokens" && kind === "snft") input.value = 1;
        else input.value = 0;
      });
      const kidsToggle = summary.querySelector("[data-kids-toggle]");
      const kidsRow = summary.querySelector("[data-kids-row]");
      const landToggle = summary.querySelector("[data-landholder-toggle]");
      const landRow = summary.querySelector("[data-landholder-row]");
      const summaryKids = summary.querySelector("input[name=kids_tokens]");
      const summaryHa = summary.querySelector("input[name=landholder_hectares]");
      const summaryLand = summary.querySelector("input[name=landholder_tokens]");
      if (kidsToggle) kidsToggle.checked = false;
      if (kidsRow) kidsRow.classList.add("hidden");
      if (landToggle) landToggle.checked = false;
      if (landRow) landRow.classList.add("hidden");
      if (summaryKids) summaryKids.value = 0;
      if (summaryHa) summaryHa.value = 0;
      if (summaryLand) summaryLand.value = 0;
      const status = form == null ? void 0 : form.querySelector(".form-status");
      hideStatus(status);
      updatePublicReservationSummary(kind);
    }
    function attachEnhancedReservationFlows() {
      attachStepControls(document);
      document.querySelectorAll("[data-reservation-flow]").forEach((form) => {
        const kind = form.getAttribute("data-reservation-flow");
        const modal = document.querySelector("[data-reservation-modal=".concat(kind, "]"));
        const status = form.querySelector(".form-status");
        const summary = document.querySelector("[data-summary-box=".concat(kind, "]"));
        const kidsToggle = summary == null ? void 0 : summary.querySelector("[data-kids-toggle]");
        const kidsRow = summary == null ? void 0 : summary.querySelector("[data-kids-row]");
        const kidsCountInput = summary == null ? void 0 : summary.querySelector("[name=kids_count]");
        const landToggle = summary == null ? void 0 : summary.querySelector("[data-landholder-toggle]");
        const landRow = summary == null ? void 0 : summary.querySelector("[data-landholder-row]");
        const hectaresInput = summary == null ? void 0 : summary.querySelector("[name=hectares]");
        const landholderTokensInput = summary == null ? void 0 : summary.querySelector("[name=landholder_tokens_input]");
        const summaryKids = summary == null ? void 0 : summary.querySelector("input[name=kids_tokens]");
        const summaryHa = summary == null ? void 0 : summary.querySelector("input[name=landholder_hectares]");
        const summaryLand = summary == null ? void 0 : summary.querySelector("input[name=landholder_tokens]");
        const syncKids = () => {
          if (kidsToggle && kidsRow) kidsRow.classList.toggle("hidden", !kidsToggle.checked);
          const kidsValue = kidsToggle && kidsToggle.checked ? Math.min(99, Math.max(0, Number((kidsCountInput == null ? void 0 : kidsCountInput.value) || 0))) : 0;
          if (kidsCountInput && !kidsCountInput.matches(":focus")) kidsCountInput.value = String(kidsValue);
          if (summaryKids) summaryKids.value = kidsValue;
          updatePublicReservationSummary(kind);
        };
        const syncLandholder = () => {
          if (landToggle && landRow) landRow.classList.toggle("hidden", !landToggle.checked);
          if (!landToggle || !landToggle.checked) {
            if (hectaresInput) hectaresInput.value = "";
            if (landholderTokensInput) landholderTokensInput.value = 0;
            if (summaryHa) summaryHa.value = 0;
            if (summaryLand) summaryLand.value = 0;
            const capDisplay2 = summary == null ? void 0 : summary.querySelector("#form-land-cap-display");
            if (capDisplay2) capDisplay2.textContent = "Maximum available: \u2014";
            updatePublicReservationSummary(kind);
            return;
          }
          const hectares = Math.max(0, Number((hectaresInput == null ? void 0 : hectaresInput.value) || 0));
          const cap = hectares >= 1 ? Math.ceil(hectares) * 1e3 : 0;
          let desired = Math.max(0, Number((landholderTokensInput == null ? void 0 : landholderTokensInput.value) || 0));
          if (cap > 0) desired = Math.min(desired, cap);
          else desired = 0;
          if (landholderTokensInput) {
            landholderTokensInput.max = String(cap || 0);
            if (!landholderTokensInput.matches(":focus")) landholderTokensInput.value = String(desired);
          }
          if (summaryHa) summaryHa.value = hectares;
          if (summaryLand) summaryLand.value = desired;
          const capDisplay = summary == null ? void 0 : summary.querySelector("#form-land-cap-display");
          if (capDisplay) capDisplay.textContent = cap > 0 ? "Maximum available: ".concat(formatInteger(cap), " COG$") : "Maximum available: \u2014";
          updatePublicReservationSummary(kind);
        };
        if (kidsToggle) kidsToggle.addEventListener("change", syncKids);
        if (kidsCountInput) {
          kidsCountInput.addEventListener("input", syncKids);
          kidsCountInput.addEventListener("change", syncKids);
        }
        if (landToggle) landToggle.addEventListener("change", syncLandholder);
        if (hectaresInput) {
          hectaresInput.addEventListener("input", syncLandholder);
          hectaresInput.addEventListener("change", syncLandholder);
        }
        if (landholderTokensInput) {
          landholderTokensInput.addEventListener("input", syncLandholder);
          landholderTokensInput.addEventListener("change", syncLandholder);
        }
        syncKids();
        syncLandholder();
        const quizSync = () => evaluateStewardshipQuiz(kind, form, modal);
        updatePublicReservationSummary(kind);
        quizSync();
        if (summary) {
          summary.querySelectorAll("input[type=number]").forEach((input) => {
            input.addEventListener("input", () => {
              updatePublicReservationSummary(kind);
              quizSync();
            });
            input.addEventListener("change", () => {
              updatePublicReservationSummary(kind);
              quizSync();
            });
          });
        }
        if (modal) {
          modal.querySelectorAll("input[type=radio]").forEach((input) => {
            input.addEventListener("change", quizSync);
          });
        }
        form.addEventListener("submit", (e) => {
          e.preventDefault();
          hideStatus(status);
          updatePublicReservationSummary(kind);
          if (!form.reportValidity()) return;
          const confirmed = form.querySelector("[name=confirmed]");
          if (confirmed && !confirmed.checked) {
            showStatus(status, "Please confirm the beta reservation statement before continuing.", "error");
            return;
          }
          if (kind === "bnft") {
            const abnField = form.querySelector("[name=abn]");
            if (abnField) {
              abnField.value = String(abnField.value || "").replace(/\D+/g, "").slice(0, 11);
              if (abnField.value.length !== 11) {
                showStatus(status, "Please enter a valid 11 digit ABN.", "error");
                return;
              }
            }
          }
          if (modal) {
            modal.classList.add("show");
            quizSync();
          }
        });
        document.querySelectorAll("[data-close-modal=".concat(kind, "], [data-reject-modal=").concat(kind, "]")).forEach((btn) => {
          btn.addEventListener("click", () => {
            if (modal) modal.classList.remove("show");
            showStatus(status, kind === "snft" ? "Reservation cancelled. No member record was created." : "Reservation cancelled. No BNFT business record was created.", "error");
          });
        });
        const accept = document.querySelector("[data-accept-modal=".concat(kind, "]"));
        if (accept) {
          accept.addEventListener("click", async () => {
            hideStatus(status);
            const quizState = evaluateStewardshipQuiz(kind, form, modal);
            if (!quizState.passed) {
              showStatus(status, quizState.paused ? "This application is paused. Choose email help or read the no-fiat rule in the notice before reservation can proceed." : "Please complete the Stewardship Awareness Module with a full pass before accepting.", "error");
              return;
            }
            const btnText = accept.textContent;
            accept.disabled = true;
            accept.textContent = kind === "snft" ? "Recording\u2026" : "Submitting\u2026";
            try {
              const payload = normalizeReservationPayload(kind, serialiseForm(form));
              payload.reservation_notice_accepted = true;
              payload.reservation_notice_version = kind === "snft" ? "snft-beta-ack-v2" : "bnft-beta-ack-v2";
              payload.reservation_notice_accepted_at = (/* @__PURE__ */ new Date()).toISOString();
              payload.referral_code = (payload.referral_code || "").toUpperCase().slice(0, 3);
              payload.stewardship_module = {
                module_name: "stewardship_awareness",
                passed: true,
                score: quizState.score,
                total_questions: quizState.total,
                completed_at: (/* @__PURE__ */ new Date()).toISOString(),
                answers: quizState.answers,
                landholder_context: !!quizState.isLandholder
              };
              const route = form.getAttribute("data-api-route");
              const result = await request(route, { method: "POST", body: JSON.stringify(payload) });
              if (modal) modal.classList.remove("show");
              const thankyou = form.getAttribute("data-thankyou-url");
              if (thankyou) {
                const url = new URL(thankyou, window.location.href);
                const number = result.member_number || result.abn || "";
                if (kind === "snft") {
                  url.searchParams.set("member_number", number);
                  url.searchParams.set("email", payload.email || "");
                  url.searchParams.set("name", payload.full_name || "");
                  try {
                    const snftData = Object.assign({}, result, {
                      full_name: payload.full_name || "",
                      email: payload.email || "",
                      mobile: payload.mobile || "",
                      state: payload.state || "",
                      reserved_tokens: payload.reserved_tokens || 1,
                      investment_tokens: payload.investment_tokens || 0,
                      donation_tokens: payload.donation_tokens || 0,
                      pay_it_forward_tokens: payload.pay_it_forward_tokens || 0,
                      kids_tokens: payload.kids_tokens || 0,
                      landholder_tokens: payload.landholder_tokens || 0
                    });
                    sessionStorage.setItem("cogs_snft_thankyou", JSON.stringify(snftData));
                    sessionStorage.setItem("cogs_snft_thankyou_stamp", Date.now().toString());
                  } catch (e) {
                  }
                } else {
                  url.searchParams.set("abn", number);
                  url.searchParams.set("email", payload.email || "");
                  url.searchParams.set("business", payload.legal_name || payload.contact_name || "");
                  try {
                    const bnftData = Object.assign({}, result, {
                      abn: payload.abn || "",
                      legal_name: payload.legal_name || "",
                      trading_name: payload.trading_name || "",
                      contact_name: payload.contact_name || "",
                      position_title: payload.position_title || "",
                      email: payload.email || "",
                      mobile: payload.mobile || "",
                      state_code: payload.state || payload.state_code || "",
                      reserved_tokens: payload.reserved_tokens || 1,
                      invest_tokens: payload.invest_tokens || 0,
                      donation_tokens: payload.donation_tokens || 0,
                      pay_it_forward_tokens: payload.pay_it_forward_tokens || 0
                    });
                    sessionStorage.setItem("cogs_bnft_thankyou", JSON.stringify(bnftData));
                    sessionStorage.setItem("cogs_bnft_thankyou_stamp", Date.now().toString());
                  } catch (e) {
                  }
                }
                url.searchParams.set("reservation_value", result.reservation_value || "");
                window.location.href = url.toString();
                return;
              }
              showReserveSuccess(kind, result);
              window.scrollTo({ top: (form.closest("[data-reserve-shell]") || form).offsetTop - 40, behavior: "smooth" });
            } catch (err) {
              if (modal) modal.classList.remove("show");
              showStatus(status, err.message || "Unable to record reservation.", "error");
            } finally {
              accept.disabled = false;
              accept.textContent = btnText;
            }
          });
        }
      });
      document.querySelectorAll("[data-reset-reserve]").forEach((btn) => {
        btn.addEventListener("click", () => resetReserveFlow(btn.getAttribute("data-reset-reserve")));
      });
    }
    function attachPageActions() {
      document.addEventListener("click", async (e) => {
        const readBtn = e.target.closest("[data-mark-announcement]");
        if (readBtn) {
          const status = document.querySelector("[data-vault-status]");
          try {
            await request("news/read/" + readBtn.getAttribute("data-mark-announcement"), { method: "POST", body: "{}" });
            showStatus(status, "Announcement marked as read.", "success");
            hydratePage();
          } catch (err) {
            showStatus(status, err.message || "Unable to update announcement.", "error");
          }
          return;
        }
        const voteBtn = e.target.closest("[data-cast-vote]");
        if (voteBtn) {
          const status = document.querySelector("[data-vault-status]");
          try {
            await request("vote/cast", {
              method: "POST",
              body: JSON.stringify({
                proposal_id: Number(voteBtn.getAttribute("data-cast-vote")),
                choice: voteBtn.getAttribute("data-vote-choice")
              })
            });
            showStatus(status, "Vote recorded in the live register.", "success");
            hydratePage();
          } catch (err) {
            showStatus(status, err.message || "Unable to record vote.", "error");
          }
          return;
        }
        const closeBtn = e.target.closest("[data-close-vote]");
        if (closeBtn) {
          const status = document.querySelector("[data-admin-controls-status]") || document.querySelector("[data-vault-status]");
          try {
            await request("admin/vote-close/" + closeBtn.getAttribute("data-close-vote"), { method: "POST", body: "{}" });
            showStatus(status, "Vote closed.", "success");
            hydratePage();
          } catch (err) {
            showStatus(status, err.message || "Unable to close vote.", "error");
          }
          return;
        }
        const disputeBtn = e.target.closest("[data-open-dispute]");
        if (disputeBtn) {
          const status = document.querySelector("[data-vault-status]");
          const reason = window.prompt("Enter a brief dispute reason for this beta tally:");
          if (!reason) return;
          try {
            await request("vote/dispute", { method: "POST", body: JSON.stringify({ proposal_id: Number(disputeBtn.getAttribute("data-open-dispute")), reason }) });
            showStatus(status, "Dispute recorded in the beta log.", "success");
            hydratePage();
          } catch (err) {
            showStatus(status, err.message || "Unable to record dispute.", "error");
          }
          return;
        }
        const resolveBtn = e.target.closest("[data-resolve-dispute]");
        if (resolveBtn) {
          const status = document.querySelector("[data-admin-controls-status]") || document.querySelector("[data-vault-status]");
          const resolution_note = window.prompt("Optional resolution note:") || "";
          try {
            await request("admin/dispute-status/" + resolveBtn.getAttribute("data-resolve-dispute"), { method: "POST", body: JSON.stringify({ status: resolveBtn.getAttribute("data-dispute-status"), resolution_note }) });
            showStatus(status, "Dispute updated.", "success");
            hydratePage();
          } catch (err) {
            showStatus(status, err.message || "Unable to update dispute.", "error");
          }
          return;
        }
      });
    }
    document.addEventListener("DOMContentLoaded", async () => {
      setActiveNav();
      bindFreshnessReload();
      registerPWA();
      attachInstallPrompt();
      injectGlobalLegalBanner();
      injectPhaseBanner();
      attachReservationForms();
      attachEnhancedReservationFlows();
      attachRecoveryToggles();
      attachAuthForms();
      attachWalletActivityToggle();
      attachLogout();
      attachAdminAuthForms();
      attachAdminForms();
      attachPageActions();
      attachReservationEditors();
      attachRecoveryForms();
      await enforceWalletContextIsolation();
      applyWalletPrefillFromQuery();
      applyStoredWalletPrefill();
      hydrateThankYouPage();
      hydratePage();
      hydratePublicMetrics();
      const page = document.body.getAttribute("data-vault-page");
      if (page && page !== "community") {
        startPolling(hydratePage);
      }
    });
  })();
  (function() {
    "use strict";
    var BUILD_VERSION = window.COGS_BUILD_VERSION || "20260330-walletux17";
    function qs(sel, scope) {
      return (scope || document).querySelector(sel);
    }
    function qsa(sel, scope) {
      return Array.prototype.slice.call((scope || document).querySelectorAll(sel));
    }
    function pathName() {
      return window.location.pathname || "/";
    }
    function pageKey() {
      var path = pathName();
      if (path === "/" || path === "/index.html") return "index.html";
      var parts = path.split("/").filter(Boolean);
      return parts.slice(-2).join("/");
    }
    function rootPrefix() {
      if (typeof window.COGS_ROOT === "string" && window.COGS_ROOT !== null) return window.COGS_ROOT;
      var path = pathName();
      if (path === "/" || path === "/index.html") return "";
      if (/^\/[^\/]+\/index\.html$/.test(path)) return "../";
      return "";
    }
    function landingHref() {
      return rootPrefix() === "" ? "/" : rootPrefix();
    }
    function withVersion(url) {
      try {
        var u = new URL(url, document.baseURI);
        u.searchParams.set("v", BUILD_VERSION);
        return u.toString();
      } catch (e) {
        return url;
      }
    }
    function injectStyles() {
      var old = document.getElementById("cogs-consolidated-v8-style");
      if (old) old.remove();
      var style = document.createElement("style");
      style.id = "cogs-consolidated-v8-style";
      style.textContent = [
        ".topbar{position:relative!important;z-index:20!important}",
        ".topbar .wrap.nav,.topbar .wrap.topbar-inner{display:flex!important;align-items:center!important;justify-content:space-between!important;gap:1rem!important}",
        ".topbar .brand,.topbar .brand:hover,.topbar .brand:focus,.topbar .brand *{background:transparent!important;box-shadow:none!important;border:0!important;outline:0!important;text-decoration:none!important}",
        ".topbar .brand{display:flex!important;align-items:center!important;gap:.9rem!important;min-width:0!important;flex:0 1 auto!important}",
        ".topbar .brand img{width:42px!important;height:42px!important;object-fit:contain!important;border-radius:50%!important;background:transparent!important;box-shadow:none!important}",
        ".topbar .brand > span,.topbar .brand > div,.topbar .brand > *:not(img){display:block!important;min-width:0!important}",
        ".topbar .brand strong{display:block!important;font-size:1.05rem!important;line-height:1.1!important;margin:0 0 .2rem 0!important;color:#f7f3e8!important}",
        ".topbar .brand em{display:block!important;font-size:.92rem!important;line-height:1.35!important;margin:0!important;font-style:normal!important;color:#9a8a74!important}",
        ".topbar .nav,.topbar .navlinks{display:flex!important;flex-wrap:wrap!important;gap:.7rem!important;justify-content:flex-end!important;align-items:center!important;flex:1 1 auto!important;min-width:0!important}",
        ".topbar .navlinks a[data-nav],.site-footer .footer-pill-links a{display:inline-flex!important;align-items:center!important;justify-content:center!important;padding:.58rem .95rem!important;border-radius:999px!important;font-size:12px!important;font-weight:700!important;line-height:1!important;text-decoration:none!important;text-transform:none!important;letter-spacing:0!important;border:1px solid transparent!important;box-shadow:0 10px 24px rgba(0,0,0,.16)!important;transition:transform .15s ease, box-shadow .15s ease, opacity .15s ease!important;white-space:nowrap!important}",
        ".topbar .navlinks a[data-nav]:hover,.topbar .navlinks a[data-nav]:focus,.site-footer .footer-pill-links a:hover,.site-footer .footer-pill-links a:focus{transform:translateY(-1px);box-shadow:0 14px 28px rgba(0,0,0,.22)!important}",
        ".topbar .navlinks a.active{outline:none!important}",
        ".topbar .navlinks a.navbtn-community,.site-footer .footer-pill-links a.navbtn-community{background:linear-gradient(180deg,#7a44c2 0%,#5d2fa0 100%)!important;border-color:rgba(196,160,255,.55)!important;color:#f7f3e8!important}",
        ".topbar .navlinks a.navbtn-personal-app,.site-footer .footer-pill-links a.navbtn-personal-app{background:linear-gradient(180deg,#d6dae0 0%,#8f979f 100%)!important;border-color:rgba(235,239,244,.70)!important;color:#171717!important}",
        ".topbar .navlinks a.navbtn-business-app,.site-footer .footer-pill-links a.navbtn-business-app{background:linear-gradient(180deg,#2f8f57 0%,#1f6f42 100%)!important;border-color:rgba(120,220,160,.55)!important;color:#f7f3e8!important}",
        ".topbar .navlinks a.navbtn-golden,.site-footer .footer-pill-links a.navbtn-golden{background:linear-gradient(180deg,#d4b25c 0%,#b98b2f 100%)!important;border-color:rgba(255,232,160,.55)!important;color:#201507!important}",
        ".topbar .navlinks a.navbtn-vaults,.site-footer .footer-pill-links a.navbtn-vaults{background:linear-gradient(180deg,#2d6cdf 0%,#1f4fa6 100%)!important;border-color:rgba(160,205,255,.55)!important;color:#f7f3e8!important}",
        ".topbar .navlinks a.navbtn-faq,.site-footer .footer-pill-links a.navbtn-faq{background:linear-gradient(180deg,#c83d4b 0%,#9f2433 100%)!important;border-color:rgba(255,170,180,.55)!important;color:#f7f3e8!important}",
        ".cta-personal-silver,a.cta-personal-silver,.hero-actions .cta-personal-silver{background:linear-gradient(180deg,#d6dae0 0%,#8f979f 100%)!important;border:1px solid rgba(235,239,244,.70)!important;color:#171717!important;box-shadow:0 10px 28px rgba(143,151,159,.24)!important}",
        ".cta-business,a.cta-business,.hero-actions .cta-business,.cta-vault-business,a.cta-vault-business,.hero-actions .cta-vault-business{background:linear-gradient(180deg,#2f8f57 0%,#1f6f42 100%)!important;border:1px solid rgba(120,220,160,.55)!important;color:#f7f3e8!important;box-shadow:0 10px 28px rgba(31,111,66,.32)!important}",
        ".cta-vault-personal,a.cta-vault-personal,.hero-actions .cta-vault-personal{background:linear-gradient(180deg,#d6dae0 0%,#8f979f 100%)!important;border:1px solid rgba(235,239,244,.70)!important;color:#171717!important;box-shadow:0 10px 28px rgba(143,151,159,.24)!important}",
        ".cta-paper-white,a.cta-paper-white,.hero-actions .cta-paper-white{background:#fff!important;border:1px solid rgba(255,255,255,.88)!important;color:#111!important;box-shadow:0 10px 28px rgba(255,255,255,.14)!important}",
        ".breadcrumbs a,.breadcrumbs span{background:transparent!important;box-shadow:none!important;border:0!important;outline:0!important;color:inherit!important}",
        ".site-footer{border-top:1px solid rgba(212,178,92,.12)!important;background:#070500!important;padding-top:2.25rem!important;padding-bottom:1.35rem!important;margin-top:0!important}",
        ".site-footer .footer-grid{display:grid!important;grid-template-columns:1.05fr 1.45fr 1fr!important;gap:2rem!important;align-items:start!important}",
        ".site-footer .footer-brand-row{display:flex!important;gap:.9rem!important;align-items:flex-start!important}",
        ".site-footer .footer-coin-mark{width:34px!important;height:34px!important;object-fit:contain!important;border-radius:50%!important;flex:0 0 auto!important;margin-top:.1rem!important}",
        ".site-footer .footer-logo-fallback{border-radius:0!important}",
        ".site-footer .footer-org-name{font-weight:700!important;font-size:1.15rem!important;line-height:1.25!important}",
        ".site-footer .footer-contact-block p{margin:0 0 .55rem 0!important;color:rgba(247,243,232,.78)!important}",
        ".site-footer .footer-contact-block a{color:#d4b25c!important;font-weight:600!important;text-decoration:none!important}",
        ".site-footer .footer-explore-block .eyebrow{margin-bottom:.75rem!important}",
        ".site-footer .footer-pill-links{display:grid!important;gap:.55rem!important}",
        ".site-footer .footer-pill-links a:not(.navbtn-community):not(.navbtn-personal-app):not(.navbtn-business-app):not(.navbtn-golden):not(.navbtn-vaults):not(.navbtn-faq){display:inline-flex!important;align-items:center!important;justify-content:center!important;padding:.58rem .95rem!important;border-radius:999px!important;text-decoration:none!important;border:1px solid rgba(212,178,92,.22)!important;color:#f7f3e8!important;background:rgba(255,255,255,.03)!important}",
        ".site-footer .copyright-notice{margin-top:1.8rem!important;padding-top:1rem!important;border-top:1px solid rgba(212,178,92,.1)!important}",
        "@media (max-width:960px){.site-footer .footer-grid{grid-template-columns:1fr!important;gap:1.35rem!important}.topbar .wrap.nav,.topbar .wrap.topbar-inner{align-items:flex-start!important}.topbar .brand{align-items:flex-start!important}.topbar .brand strong{font-size:1rem!important}.topbar .brand em{font-size:.88rem!important}.topbar .navlinks a[data-nav],.site-footer .footer-pill-links a{padding:.52rem .8rem!important}}"
      ].join("\n");
      document.head.appendChild(style);
    }
    function enforceBrandText() {
      qsa(".topbar .brand").forEach(function(brand) {
        var nonImg = Array.prototype.slice.call(brand.children).find(function(child) {
          return child.tagName && child.tagName.toLowerCase() !== "img";
        });
        if (!nonImg) {
          nonImg = document.createElement("span");
          brand.appendChild(nonImg);
        }
        nonImg.innerHTML = "<strong>COG$ of Australia Foundation</strong><em>Community owned gold &amp; silver<br>Community operated governance structure</em>";
        brand.setAttribute("href", landingHref());
      });
      var brandImg = qs(".topbar .brand img");
      if (brandImg) {
        brandImg.setAttribute("src", withVersion(rootPrefix() + "assets/logo_webcir.png"));
        brandImg.onerror = function() {
          this.onerror = null;
          this.src = withVersion(rootPrefix() + "assets/logo_web.png");
        };
      }
    }
    function normalizeLabelsInText() {
      if (document.title) {
        document.title = document.title.replace(/Personal SNFT/g, "Personal Vault Application").replace(/Business BNFT/g, "Business Vault Application").replace(/SNFT Humans/g, "Personal Vault Application").replace(/BNFT Businesses/g, "Business Vault Application").replace(/Golden COGS/g, "Golden COG$");
      }
      qsa("body *").forEach(function(el) {
        if (el.children.length === 0 && el.childNodes.length === 1 && el.childNodes[0].nodeType === 3) {
          var txt = el.textContent;
          txt = txt.replace(/Personal SNFT/g, "Personal Vault Application").replace(/Business BNFT/g, "Business Vault Application").replace(/SNFT Humans/g, "Personal Vault Application").replace(/BNFT Businesses/g, "Business Vault Application").replace(/Golden COGS/g, "Golden COG$").replace(/Reserve business membership/g, "Become a COG$ Business");
          if (txt !== el.textContent) el.textContent = txt;
        }
      });
    }
    function normalizeNav() {
      var prefix = rootPrefix();
      var nav = qs(".navlinks");
      if (!nav) return;
      nav.innerHTML = [
        '<a data-nav class="navbtn-community" href="' + landingHref() + '">Community</a>',
        '<a data-nav class="navbtn-personal-app" href="' + prefix + 'join/index.html">Personal Vault Application</a>',
        '<a data-nav class="navbtn-business-app" href="' + prefix + 'businesses/index.html">Business Vault Application</a>',
        '<a data-nav class="navbtn-golden" href="' + prefix + 'gold-cogs/index.html">Golden COG$</a>',
        '<a data-nav class="navbtn-vaults" href="' + prefix + 'wallets/index.html">Vault Wallets</a>',
        '<a data-nav class="navbtn-faq" href="' + prefix + 'faq/index.html">FAQ</a>'
      ].join("");
      var key = pageKey();
      qsa("a[data-nav]", nav).forEach(function(a) {
        if (key === "index.html" && a.classList.contains("navbtn-community") || key === "join/index.html" && a.classList.contains("navbtn-personal-app") || key === "businesses/index.html" && a.classList.contains("navbtn-business-app") || key === "gold-cogs/index.html" && a.classList.contains("navbtn-golden") || key === "wallets/index.html" && a.classList.contains("navbtn-vaults") || key === "faq/index.html" && a.classList.contains("navbtn-faq")) a.classList.add("active");
      });
    }
    function normalizeFooter() {
      var prefix = rootPrefix();
      var footer = qs("footer.footer, footer.site-footer");
      if (!footer) return;
      var logoPrimary = withVersion(prefix + "assets/logo_webcir.png");
      var logoFallback = withVersion(prefix + "assets/logo_web.png");
      footer.outerHTML = [
        '<footer class="site-footer">',
        '  <div class="wrap footer-grid">',
        '    <div class="footer-brand-block">',
        '      <div class="footer-brand-row">',
        '        <img class="footer-coin-mark" src="' + logoPrimary + '" alt="COG$ Australia" onerror="this.onerror=null;this.src=\'' + logoFallback + "';this.classList.add('footer-logo-fallback');\" />",
        '        <div><div class="footer-org-name">COGS of Australia Foundation Hybrid Trust</div></div>',
        "      </div>",
        "    </div>",
        '    <div class="footer-contact-block">',
        "      <p>The Trustee for COGS of Australia Foundation Hybrid Trust</p>",
        "      <p>ABN: 61 734 327 831</p>",
        "      <p>C/- Drake Village Resource Centre, Drake Village NSW 2469</p>",
        '      <p><a href="mailto:hello@cogsaustralia.org">hello@cogsaustralia.org</a></p>',
        "    </div>",
        '    <div class="footer-explore-block">',
        '      <div class="eyebrow">Explore</div>',
        '      <div class="footer-links footer-pill-links">',
        '        <a class="navbtn-community" href="' + landingHref() + '">Community</a>',
        '        <a class="navbtn-personal-app" href="' + prefix + 'join/index.html">Personal Vault Application</a>',
        '        <a class="navbtn-business-app" href="' + prefix + 'businesses/index.html">Business Vault Application</a>',
        '        <a class="navbtn-golden" href="' + prefix + 'gold-cogs/index.html">Golden COG$</a>',
        '        <a class="navbtn-vaults" href="' + prefix + 'wallets/index.html">Vault Wallets</a>',
        '        <a class="navbtn-faq" href="' + prefix + 'faq/index.html">FAQ</a>',
        '        <a href="' + prefix + 'terms/index.html">Terms</a>',
        '        <a href="' + prefix + 'privacy/index.html">Privacy</a>',
        "      </div>",
        "    </div>",
        "  </div>",
        '  <p class="copyright-notice">COPYRIGHT NOTICE \xA9 2026 COGS of Australia Foundation. All rights reserved. Private community use only. No unauthorised reproduction or distribution.</p>',
        "</footer>"
      ].join("");
    }
    function styleAndRenameCtas() {
      qsa("a, button").forEach(function(el) {
        var txt = (el.textContent || "").trim().toLowerCase();
        var href = (el.getAttribute("href") || "").toLowerCase();
        if (txt.indexOf("join as a person") !== -1 || txt.indexOf("become a supporter - $4") !== -1 || txt.indexOf("become a supporter \u2014 $4") !== -1) {
          el.classList.add("cta-personal-silver");
        }
        if (txt.indexOf("read the public paper") !== -1 || txt.indexOf("read the white paper") !== -1 || txt.indexOf("read the black & white paper") !== -1) {
          el.textContent = "Read the Black & White Paper";
          el.classList.add("cta-paper-white");
          if (el.tagName && el.tagName.toLowerCase() === "a") {
            el.setAttribute("href", rootPrefix() + "BW_Paper/index.html");
          }
        }
        if (txt.indexOf("reserve business membership") !== -1) {
          el.textContent = (el.textContent || "").replace(/Reserve business membership/i, "Become a COG$ Business");
          el.classList.add("cta-business");
        }
        if (pageKey() === "businesses/index.html" && txt.indexOf("open business vault") !== -1) {
          el.remove();
          return;
        }
        if (txt.indexOf("join as a business") !== -1 || txt.indexOf("become a cog$ business") !== -1 || href.indexOf("businesses/index.html") !== -1) {
          el.classList.add("cta-business");
        }
        if (txt.indexOf("personal vault") !== -1 && txt.indexOf("application") === -1) {
          el.classList.add("cta-vault-personal");
        }
        if (txt.indexOf("business vault") !== -1 && txt.indexOf("application") === -1 && txt.indexOf("open business vault") === -1) {
          el.classList.add("cta-vault-business");
        }
      });
    }
    function rewriteVaultsGateway() {
      if (pageKey() !== "wallets/index.html") return;
      var heroWrap = qs(".hero.page .wrap");
      if (!heroWrap) return;
      var prefix = rootPrefix();
      heroWrap.innerHTML = [
        '<div class="breadcrumbs"><a href="' + landingHref() + '">Community</a> / <span>Vault Wallets</span></div>',
        '<div class="split">',
        "  <div>",
        '    <div class="eyebrow">Vault Wallets</div>',
        "    <h1>Choose the wallet that fits your pathway.</h1>",
        '    <p class="copy-lead">This public page links only to the Personal Vault and Business Vault. Community Vault access is private and available only through the relevant internal pathway.</p>',
        '    <div class="hero-actions">',
        '      <a class="cta cta-vault-personal" href="' + prefix + 'wallets/member.html">Personal Vault</a>',
        '      <a class="cta cta-vault-business" href="' + prefix + 'wallets/business.html">Business Vault</a>',
        "    </div>",
        "  </div>",
        '  <div class="wallet-card">',
        '    <div class="eyebrow">Access model</div>',
        '    <div class="points" style="margin-top:1rem">',
        '      <div class="point"><i>1</i><div><strong>Personal Vault</strong><p>For personal member access and wallet activity.</p></div></div>',
        '      <div class="point"><i>2</i><div><strong>Business Vault</strong><p>For business reporting and wallet access.</p></div></div>',
        '      <div class="point"><i>3</i><div><strong>Community Vault</strong><p>Not publicly linked here.</p></div></div>',
        "    </div>",
        "  </div>",
        "</div>"
      ].join("");
    }
    function persistFormFields(form, storageKey, fields, rememberKey) {
      if (!form) return;
      var saved = {};
      var roleEl = form.querySelector('input[name="role"]');
      var role = roleEl ? roleEl.value : form.querySelector('[name="abn"]') ? "bnft" : "snft";
      try {
        saved = JSON.parse(localStorage.getItem(storageKey) || "{}");
      } catch (e) {
        saved = {};
      }
      var rememberInput = form.querySelector('[name="remember_login"]');
      var rememberEnabled = !!saved.remember_login || safeLocalGet(rememberKey, "0") === "1";
      if (rememberInput) rememberInput.checked = rememberEnabled;
      var numberInputInitial = form.querySelector('[name="member_number"], [name="abn"]');
      var emailInputInitial = form.querySelector('[name="email"]');
      if (numberInputInitial && emailInputInitial) {
        var initialRepaired = reconcileWalletIdentityFields(role, numberInputInitial.value || saved.member_number || saved.abn || "", emailInputInitial.value || saved.email || "", saved.member_number || saved.abn || "", saved.email || "");
        if (initialRepaired.number && (!numberInputInitial.value || looksLikeEmail(numberInputInitial.value))) numberInputInitial.value = role === "bnft" ? normaliseDigits(initialRepaired.number) : initialRepaired.number;
        if (initialRepaired.email && (!emailInputInitial.value || !looksLikeEmail(emailInputInitial.value))) emailInputInitial.value = initialRepaired.email;
      }
      fields.forEach(function(name) {
        var el = form.querySelector('[name="' + name + '"]');
        if (!el) return;
        if (rememberEnabled && saved[name] && !el.value) el.value = saved[name];
        el.addEventListener("input", function() {
          var numberInput = form.querySelector('[name="member_number"], [name="abn"]');
          var emailInput = form.querySelector('[name="email"]');
          if (numberInput && emailInput) {
            var repaired = reconcileWalletIdentityFields(role, numberInput.value, emailInput.value, saved.member_number || saved.abn || "", saved.email || "");
            if (repaired.number && !looksLikeEmail(numberInput.value)) numberInput.value = role === "bnft" ? normaliseDigits(repaired.number) : repaired.number;
            if (looksLikeEmail(numberInput.value) || !looksLikeEmail(emailInput.value) && repaired.email) emailInput.value = repaired.email;
          }
          if (rememberInput && !rememberInput.checked) return;
          var current = {};
          try {
            current = JSON.parse(localStorage.getItem(storageKey) || "{}");
          } catch (e) {
            current = {};
          }
          fields.forEach(function(fieldName) {
            var fieldEl = form.querySelector('[name="' + fieldName + '"]');
            if (fieldEl) current[fieldName] = fieldEl.value;
          });
          current.remember_login = true;
          localStorage.setItem(storageKey, JSON.stringify(current));
          safeLocalSet(rememberKey, "1");
        });
      });
      if (rememberInput && !rememberInput.dataset.bound) {
        rememberInput.dataset.bound = "1";
        rememberInput.addEventListener("change", function() {
          if (rememberInput.checked) {
            var current = {};
            try {
              current = JSON.parse(localStorage.getItem(storageKey) || "{}");
            } catch (e) {
              current = {};
            }
            fields.forEach(function(name) {
              var el = form.querySelector('[name="' + name + '"]');
              if (el && el.value) current[name] = el.value;
            });
            current.remember_login = true;
            localStorage.setItem(storageKey, JSON.stringify(current));
            safeLocalSet(rememberKey, "1");
          } else {
            safeLocalRemove(storageKey);
            safeLocalSet(rememberKey, "0");
          }
        });
      }
    }
    function setupVaultLoginPersistence() {
      qsa('form[data-auth-form="login"]').forEach(function(form) {
        var roleEl = form.querySelector('input[name="role"]');
        var role = roleEl ? roleEl.value : "";
        if (role === "snft") {
          persistFormFields(form, "cogs_member_login", ["member_number", "email"], "cogs_member_remember");
        } else if (role === "bnft") {
          persistFormFields(form, "cogs_business_login", [form.querySelector('[name="abn"]') ? "abn" : "member_number", "email"], "cogs_business_remember");
        } else {
          var hasAbn = !!form.querySelector('[name="abn"]');
          if (hasAbn) {
            persistFormFields(form, "cogs_business_login", [form.querySelector('[name="abn"]') ? "abn" : "member_number", "email"], "cogs_business_remember");
          } else {
            persistFormFields(form, "cogs_member_login", ["member_number", "email"], "cogs_member_remember");
          }
        }
      });
    }
    function fixCommunityLinksEverywhere() {
      qsa(".breadcrumbs a").forEach(function(a) {
        var txt = (a.textContent || "").trim().toLowerCase();
        if (txt === "community") a.setAttribute("href", landingHref());
      });
      qsa(".navbtn-community, .footer-pill-links .navbtn-community").forEach(function(a) {
        a.setAttribute("href", landingHref());
      });
    }
    function initConsolidatedV8() {
      injectStyles();
      enforceBrandText();
      normalizeLabelsInText();
      normalizeNav();
      normalizeFooter();
      rewriteVaultsGateway();
      styleAndRenameCtas();
      setupVaultLoginPersistence();
      fixCommunityLinksEverywhere();
    }
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", initConsolidatedV8);
    } else {
      initConsolidatedV8();
    }
    window.addEventListener("load", function() {
      styleAndRenameCtas();
      setupVaultLoginPersistence();
      fixCommunityLinksEverywhere();
    });
  })();
})();
