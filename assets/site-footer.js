/* COG$ of Australia Foundation — shared site footer
   Injected into every page via <div id="cogs-footer"></div>
   Uses absolute paths so works from any depth (root or subdirectory).
   To update the footer: edit this one file only.
*/
(function () {
  'use strict';

  var pillStyle = 'text-decoration:none; color:#e7d3a1; font-size:12px; line-height:1.2; display:inline-flex; align-items:center; gap:8px; padding:7px 10px; border:1px solid rgba(176,141,87,.28); border-radius:999px; background:rgba(176,141,87,.06); width:max-content; max-width:100%;';
  var stackStyle = 'display:flex; flex-direction:column; gap:8px; align-items:flex-start;';
  var iconBase = 'display:block; flex:0 0 auto;';

  var HTML = `
  <style>
  .site-footer{border-top:1px solid rgba(240,209,138,.13);padding:36px 28px 38px;background:rgba(8,6,2,1)}
  .footer-inner{max-width:960px;margin:0 auto;display:grid;grid-template-columns:1.8fr 1fr 1fr 1fr;gap:32px;align-items:start}
  .footer-name{font-family:'Playfair Display',Georgia,serif;font-size:.9rem;color:#f0d18a;margin-bottom:8px;font-weight:600}
  .footer-detail{font-size:.67rem;color:rgba(210,185,130,.58);line-height:1.76}
  .footer-col h4{font-size:.65rem;font-weight:500;letter-spacing:.14em;text-transform:uppercase;color:#c9973d;margin-bottom:12px}
  .footer-links{display:flex;flex-direction:column;gap:7px}
  .footer-links a{font-size:.76rem;color:rgba(210,185,130,.58);text-decoration:none;line-height:1.3;transition:color .2s}
  .footer-links a:hover{color:#f0d18a}
  .footer-link-locked{display:flex;align-items:center;gap:6px;font-size:.76rem;color:rgba(210,185,130,.58);text-decoration:none;line-height:1.3;transition:color .2s}
  .footer-link-locked:hover{color:#f0d18a}
  .lock-badge{display:inline-flex;align-items:center;gap:3px;font-size:.58rem;font-weight:500;letter-spacing:.08em;color:#7a5518;background:rgba(120,80,20,.25);border:1px solid rgba(140,100,30,.35);padding:1px 6px;border-radius:4px;white-space:nowrap}
  .footer-bottom{max-width:960px;margin:24px auto 0;padding:18px 28px 0;border-top:1px solid rgba(240,209,138,.13)}
  .footer-bottom-left{font-size:.65rem;color:rgba(210,185,130,.35);line-height:1.6}
  @media(max-width:860px){.footer-inner{grid-template-columns:1fr 1fr}.footer-inner>div:first-child{grid-column:1/-1}}
  @media(max-width:580px){.footer-inner{grid-template-columns:1fr}.footer-inner>div:first-child{grid-column:1}.footer-bottom{flex-direction:column;align-items:flex-start}}
  </style>
  <div class="site-disclosure" style="background:linear-gradient(180deg,rgba(42,29,11,.55),rgba(23,17,9,.3));border-top:1px solid rgba(240,209,138,.13);padding:9px 24px;text-align:center">
    <p style="font-size:.73rem;color:rgba(255,244,221,.62);line-height:1.5;margin:0">Not for public broadcast · Invited Partner use only · Available by referral link only<br>Community Joint Venture Cryptographic Resource Management and Distribution System
.</p>
  </div>
  <footer class="site-footer">
    <div class="footer-inner">
      <div>The Trustee for 
        <div class="footer-name">COGS of Australia Foundation Community Joint Venture Mainspring Hybrid Trust</div>
        <div class="footer-detail">
          ABN: 61 734 327 831<br>
          Community Owned Gold &amp; Silver&#8482; &middot; <br>Community Operated Governance Structure&#8482;<br>
          C/- Drake Village Resource Centre<br>Drake Village NSW 2469<br>
          &copy; 2026 Copyright Protected
        </div>
      </div>

      <div class="footer-col">
        <h4>Community</h4>
        <div class="footer-links">
          <a href="/businesses/index.html">Businesses</a>
          <a href="/landholders/index.html">Landholders</a>
          <a href="/gold-cogs/index.html">Golden COG$ in the Community</a>
          <a class="footer-link-locked" href="/partners/index.html" style="display:inline-flex;align-items:center;gap:10px;margin-top:6px;padding:9px 14px 9px 10px;border:1px solid rgba(201,151,61,.45);border-radius:12px;background:rgba(6,4,2,.85);text-decoration:none;transition:border-color .2s,background .2s;max-width:100%;" onmouseover="this.style.borderColor='rgba(240,209,138,.75)';this.style.background='rgba(12,9,3,.95)'" onmouseout="this.style.borderColor='rgba(201,151,61,.45)';this.style.background='rgba(6,4,2,.85)'">
            <span style="font-size:1.1rem;line-height:1;filter:sepia(.4) saturate(1.6) brightness(1.1)">&#x1F6E1;&#xFE0E;</span>
            <span style="display:flex;flex-direction:column;gap:2px">
              <span style="font-family:'Playfair Display',Georgia,serif;font-size:.82rem;font-weight:600;color:#f0d18a;line-height:1.15;white-space:nowrap;letter-spacing:.01em">Partner's Vault</span>
              <span style="font-size:.6rem;font-weight:500;letter-spacing:.1em;text-transform:uppercase;color:rgba(201,151,61,.72);line-height:1.2;white-space:nowrap">Secure Partner access</span>
            </span>
          </a>
        </div>
      </div>

      <div class="footer-col">
        <h4>Explore</h4>
        <div class="footer-links">
          <a href="/faq/index.html">FAQ</a>
          <a href="/vision/index.html">The Vision</a>
          <a href="/skeptic/index.html">I'm a Skeptic</a>
          <a href="/tell-me-more/index.html">Tell me how it works</a>
          <a href="/kids/index.html">Kids</a>

      </div>
      </div>

      <div class="footer-col">
        <h4>Policies</h4>
        <div class="footer-links">
          <a href="/terms/index.html">Terms</a>
          <a href="/privacy/index.html">Privacy Policy</a>
        </div>

        <h4 style="margin-top:18px">Contact</h4>
        <div style="${stackStyle}">
          <a href="mailto:home@cogsaustralia.org" style="${pillStyle}">
            <svg aria-hidden="true" viewBox="0 0 24 24" width="18" height="18" fill="currentColor" style="${iconBase} color:#b08d57;">
              <path d="M2 4h20a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zm10 8.75L21.2 6H2.8L12 12.75zm0 2.5L2 8.5V18h20V8.5l-10 6.75z"/>
            </svg>
            <span>Email us</span>
          </a>

          <a href="https://wa.me/61494578706" target="_blank" rel="noopener" style="${pillStyle}">
            <svg aria-hidden="true" viewBox="0 0 32 32" width="18" height="18" fill="currentColor" style="${iconBase} color:#25D366;">
              <path d="M19.11 17.21c-.27-.13-1.6-.79-1.85-.88-.25-.09-.43-.13-.61.13-.18.27-.7.88-.86 1.06-.16.18-.31.2-.58.07-.27-.13-1.12-.41-2.13-1.32-.79-.7-1.32-1.56-1.48-1.83-.16-.27-.02-.42.12-.55.12-.12.27-.31.4-.47.13-.16.18-.27.27-.45.09-.18.04-.34-.02-.47-.07-.13-.61-1.47-.83-2.02-.22-.53-.44-.46-.61-.47h-.52c-.18 0-.47.07-.72.34-.25.27-.95.93-.95 2.27s.97 2.64 1.11 2.82c.13.18 1.9 2.91 4.6 4.08.64.27 1.14.44 1.53.56.64.2 1.22.17 1.68.1.51-.08 1.6-.65 1.83-1.27.22-.63.22-1.16.16-1.27-.07-.11-.25-.18-.52-.31z"/>
              <path d="M16.02 3.2c-7.07 0-12.8 5.73-12.8 12.8 0 2.25.58 4.45 1.69 6.38L3 29l6.8-1.78a12.75 12.75 0 0 0 6.22 1.6h.01c7.07 0 12.8-5.73 12.8-12.8S23.1 3.2 16.02 3.2zm0 23.47h-.01a10.63 10.63 0 0 1-5.42-1.49l-.39-.23-4.03 1.06 1.08-3.93-.25-.4a10.63 10.63 0 0 1-1.63-5.66c0-5.88 4.78-10.66 10.66-10.66s10.66 4.78 10.66 10.66-4.78 10.65-10.67 10.65z"/>
            </svg>
            <span>WhatsApp</span>
          </a>
        </div>
      </div>
    </div>

    <div class="footer-bottom" style="display:flex; flex-wrap:wrap; gap:14px 18px; align-items:center;">
      <div class="footer-bottom-left">Stewardship &middot; Fair Share &middot; Fair Say &middot; Fair Go &middot; Real World Value for Real World Assets</div>

    </div>
  </footer>`;


  function renderFooter() {
    var placeholder = document.getElementById('cogs-footer');
    if (!placeholder) return;
    placeholder.outerHTML = HTML;
  }

  if (document.getElementById('cogs-footer')) {
    renderFooter();
    return;
  }

  document.addEventListener('DOMContentLoaded', renderFooter);
}());
