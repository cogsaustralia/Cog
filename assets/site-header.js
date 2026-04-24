/* COG$ of Australia Foundation — shared site header
   Injected into every page via <div id="cogs-header"></div>
   Uses absolute paths so works from any depth (root or subdirectory).
   Uses CSS variables defined in each page's :root so theming is automatic.
   Active nav item is auto-detected from window.location.pathname.
   To update the header or nav: edit this one file only.
*/
(function () {
  'use strict';

  function normalisePath(pathname) {
    var path = (pathname || '').replace(/\/index\.html$/, '/');
    if (path !== '/' && !path.endsWith('/')) path += '/';
    return path;
  }

  var path = normalisePath(window.location.pathname);

  /* Landing page intentionally does not use the shared header */
  if (path === '/' || path === '/index.html') {
    return;
  }

  var HTML = `
  <style>
  /* ── Reset: box-sizing and margin only — NO padding:0 (would override all spacing via ID specificity) ── */
  #cogs-header *,
  #cogs-header *::before,
  #cogs-header *::after { box-sizing:border-box; margin:0 }

  /* ── Outer wrapper — position:fixed so it is immune to ancestor overflow:hidden ──
     position:sticky breaks silently when any ancestor has overflow-x/y:hidden.
     position:fixed is always relative to the viewport regardless of ancestor
     overflow. padding-top is added to document.body dynamically (see JS below)
     to compensate for the header being removed from document flow.              ── */
  .sh-wrap {
    position:fixed;
    top:0; left:0; right:0;
    width:100%;
    z-index:200
  }

  /* ── Header bar ── */
  .sh-header {
    border-bottom:1px solid var(--line, rgba(240,209,138,.13));
    background:rgba(8,6,2,.88);
    backdrop-filter:blur(18px);
    -webkit-backdrop-filter:blur(18px);
    overflow:hidden
  }

  /* ── Container ── */
  .sh-container {
    max-width:960px;
    margin:0 auto;
    padding:0 24px
  }

  /* ── Nav row ── */
  .sh-row {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding:10px 0;
    min-width:0
  }

  /* ── Brand ── */
  .sh-brand {
    display:flex; align-items:center; gap:10px;
    text-decoration:none; flex-shrink:1; min-width:0; overflow:hidden
  }
  .sh-logo {
    width:36px; height:36px; border-radius:50%;
    border:1.5px solid var(--gold-rim, rgba(240,209,138,.20));
    display:block; flex-shrink:0
  }
  .sh-copy { display:flex; flex-direction:column; gap:3px }
  .sh-name {
    font-family:var(--serif, 'Playfair Display',Georgia,serif);
    font-size:14px; font-weight:600;
    color:var(--gold-1, #f0d18a); line-height:1.1
  }
  .sh-sub {
    font-size:9.5px; color:rgba(255,248,232,.65);
    letter-spacing:.04em; line-height:1.2
  }
  .sh-tag {
    font-size:9px;
    color:var(--muted, rgba(210,185,130,.58));
    letter-spacing:.05em; line-height:1.2
  }

  /* ── Nav links ── */
  .sh-nav { display:flex; align-items:center; gap:8px }
  .sh-nav a {
    font-size:12px; font-weight:400;
    color:var(--muted, rgba(210,185,130,.58));
    text-decoration:none;
    padding:6px 13px; border-radius:99px;
    transition:color .2s, background .2s;
    white-space:nowrap; letter-spacing:.02em
  }
  .sh-nav a:hover,
  .sh-nav a.sh-active {
    color:var(--gold-1, #f0d18a);
    background:var(--gold-pale, rgba(240,209,138,.10))
  }

  /* ── CTA ── */
  .sh-cta { display:flex; align-items:center; gap:10px; flex-shrink:0 }

  /* ── Join pill ── */
  .sh-join {
    font-size:12px; font-weight:500; color:#1a0f00;
    text-decoration:none;
    background:linear-gradient(135deg, var(--gold-1, #f0d18a), var(--gold-2, #c9973d));
    padding:8px 18px; border-radius:99px;
    white-space:nowrap; transition:opacity .2s
  }
  .sh-join:hover { opacity:.88 }

  /* ── Member Portal button ── */
  .sh-portal {
    display:inline-flex; align-items:center; gap:9px;
    text-decoration:none;
    background:rgba(6,4,2,.92);
    border:1px solid rgba(201,151,61,.45);
    border-radius:10px;
    padding:6px 13px 6px 10px;
    transition:border-color .2s, background .2s, box-shadow .2s;
    box-shadow:0 0 0 0 rgba(201,151,61,0);
    flex-shrink:0;
    position:relative
  }
  .sh-portal:hover {
    border-color:rgba(240,209,138,.75);
    background:rgba(12,9,3,.95);
    box-shadow:0 0 14px rgba(201,151,61,.18)
  }
  .sh-portal-shield {
    font-size:17px; line-height:1; flex-shrink:0;
    filter:sepia(.4) saturate(1.6) brightness(1.1)
  }
  .sh-portal-copy {
    display:flex; flex-direction:column; gap:1px
  }
  .sh-portal-label {
    font-family:var(--serif, 'Playfair Display',Georgia,serif);
    font-size:12.5px; font-weight:600; letter-spacing:.02em;
    color:var(--gold-1, #f0d18a); line-height:1.15;
    white-space:nowrap
  }
  .sh-portal-sub {
    font-size:9px; font-weight:500; letter-spacing:.10em;
    text-transform:uppercase;
    color:rgba(201,151,61,.72); line-height:1.2;
    white-space:nowrap
  }

  /* Foundation Day note — only shown when data-foundation-note is set on #cogs-header */
  .sh-portal-note {
    position:absolute; bottom:-19px; left:0; right:0;
    text-align:center;
    font-size:8px; letter-spacing:.08em; text-transform:uppercase;
    color:rgba(201,151,61,.55); white-space:nowrap; pointer-events:none
  }

  /* Drawer member portal entry */
  .sh-drawer-portal {
    font-size:14px; font-weight:500;
    color:var(--gold-1, #f0d18a) !important;
    text-decoration:none;
    padding:10px 0 !important;
    border-top:1px solid rgba(201,151,61,.22) !important;
    margin-top:4px;
    display:block; transition:color .2s;
    letter-spacing:.01em
  }
  .sh-drawer-portal:hover { color:#fff !important }

  /* ── Mobile toggle ── */
  .sh-toggle {
    display:none; background:none;
    border:1px solid var(--gold-rim, rgba(240,209,138,.20));
    color:var(--muted, rgba(210,185,130,.58));
    padding:7px 11px; border-radius:10px;
    cursor:pointer; font-size:16px; line-height:1
  }

  /* ── Mobile drawer — inside .sh-container so inherits 24px side padding ── */
  .sh-drawer {
    display:none; flex-direction:column; gap:2px;
    padding:10px 0 14px;
    border-top:1px solid var(--line, rgba(240,209,138,.13))
  }
  .sh-drawer a {
    font-size:14px;
    color:var(--muted, rgba(210,185,130,.58));
    text-decoration:none;
    padding:9px 0;
    border-bottom:1px solid var(--line, rgba(240,209,138,.13));
    display:block; transition:color .2s
  }
  .sh-drawer a:last-child { border-bottom:none }
  .sh-drawer a:hover,
  .sh-drawer a.sh-active { color:var(--gold-1, #f0d18a) }
  .sh-drawer.sh-open { display:flex }

  /* ── Responsive ── */
  @media(max-width:720px) {
    .sh-nav    { display:none }
    .sh-toggle { display:block }
    .sh-copy   { overflow:hidden }
    .sh-name   { font-size:14px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis }
    .sh-sub,
    .sh-tag    { display:none }
    /* Keep portal but collapse copy text — shield + border remain visible */
    .sh-portal { padding:6px 9px }
    .sh-portal-copy { display:none }
    .sh-portal-note { display:none }
  }
  @media(max-width:400px) {
    .sh-portal { display:none }
  }
  </style>

  <div class="sh-wrap">

    <div class="sh-header">
      <div class="sh-container">

        <div class="sh-row">
          <a class="sh-brand" href="/">
            <img class="sh-logo" src="/assets/logo_webcir.png" alt="COG$ of Australia Foundation"
              onerror="this.style.display='none'">
            <div class="sh-copy">
              <span class="sh-name">COG$ of Australia Foundation</span>
              <span class="sh-sub">Community Owned Gold &amp; Silver&#8482;</span>
              <span class="sh-tag">Community Operated Governance Structure&#8482;</span>
            </div>
          </a>

          <nav class="sh-nav" aria-label="Primary">
            <a href="/vision/">The Vision</a>
            <a href="/tell-me-more/">How it works</a>
            <a href="/faq/">FAQ</a>
            <a href="/skeptic/">Skeptic&rsquo;s Guide</a>
          </nav>

          <div class="sh-cta">
            <a class="sh-portal" href="/partners/index.html" aria-label="Member's Vault &mdash; secure Mmember access">
              <span class="sh-portal-shield">&#x1F6E1;&#xFE0E;</span>
              <span class="sh-portal-copy">
                <span class="sh-portal-label">Member's Vault</span>
                <span class="sh-portal-sub">Secure access</span>
              </span>
            </a>
            <a class="sh-join" href="/join/">Become a Member &mdash; $4</a>
            <button class="sh-toggle" id="shToggle" aria-expanded="false" aria-label="Open navigation">&#9776;</button>
          </div>
        </div>

        <nav class="sh-drawer" id="shDrawer" aria-label="Mobile navigation">
          <a href="/">Home</a>
          <a href="/vision/">The Vision</a>
          <a href="/tell-me-more/">How it works</a>
          <a href="/faq/">FAQ</a>
          <a href="/skeptic/">Skeptic&rsquo;s Guide</a>
          <a href="/landholders/">Landholders &amp; Affected Community</a>
          <a href="/gold-cogs/">Get Involved &amp; Golden COG$</a>
          <a href="/join/">Become a Member &mdash; $4</a>
          <a class="sh-drawer-portal" href="/partners/index.html">&#x1F6E1;&#xFE0E; Member's Vault &mdash; secure access</a>
        </nav>

      </div>
    </div>

  </div>
  `;

  function mountHeader() {
    var target = document.getElementById('cogs-header');
    if (!target) return false;
    if (target.getAttribute('data-cogs-mounted') === '1') return true;

    target.innerHTML = HTML;
    target.setAttribute('data-cogs-mounted', '1');

    target.querySelectorAll('.sh-nav a, .sh-drawer a').forEach(function (a) {
      var href = a.getAttribute('href');
      if (!href) return;
      if (path === href || (href !== '/' && path.startsWith(href))) {
        a.classList.add('sh-active');
      }
    });

    var note = target.getAttribute('data-foundation-note');
    if (note) {
      var portalWithNote = target.querySelector('.sh-portal');
      if (portalWithNote) {
        var noteEl = document.createElement('span');
        noteEl.className = 'sh-portal-note';
        noteEl.textContent = note;
        portalWithNote.appendChild(noteEl);
      }
    }

    var portal = target.querySelector('.sh-portal');
    if (portal) {
      portal.title = 'Partner\'s Vault — Established under Common Equity Law · The Trustee for COGS of Australia Foundation Community Joint Venture Mainspring Hybrid Trust';
    }

    var toggle = target.querySelector('#shToggle');
    var drawer = target.querySelector('#shDrawer');
    if (toggle && drawer) {
      toggle.addEventListener('click', function () {
        var isOpen = drawer.classList.toggle('sh-open');
        toggle.setAttribute('aria-expanded', String(isOpen));
        toggle.innerHTML = isOpen ? '&#x2715;' : '&#9776;';
        setTimeout(applyHeaderOffset, 10);
      });
    }

    function applyHeaderOffset() {
      var wrap = target.querySelector('.sh-wrap');
      if (!wrap) return;
      document.body.style.paddingTop = wrap.offsetHeight + 'px';
    }

    requestAnimationFrame(function () {
      applyHeaderOffset();
    });

    // Re-measure after full page load — catches pages with coin animations,
    // SVG filters, or late-loading assets that affect layout after rAF fires.
    window.addEventListener('load', applyHeaderOffset);
    // Belt-and-suspenders: one more pass after any CSS animations settle
    setTimeout(applyHeaderOffset, 350);

    window.addEventListener('resize', applyHeaderOffset);

    return true;
  }

  if (!mountHeader()) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', mountHeader, { once: true });
    } else {
      setTimeout(mountHeader, 0);
    }
  }
})();
