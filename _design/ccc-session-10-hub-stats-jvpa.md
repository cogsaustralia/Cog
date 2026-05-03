# UX Audit Remediation — Session 10: partners/index.html — Stats, JVPA, Four Spokes, Truth Window
# Source: _design/audits/ux-audit-hub-2026-05-03.html findings 15.x, 16.x, 17.x, 18.x
# Pull main before starting: git pull --rebase origin main
# Read the file before every edit. Show diff. STOP before committing.

## GROUND TRUTH RULES
- Read exact current file state before every edit
- Stage only partners/index.html
- Verify div balance, script balance, </body>, </html> after all changes
- Zero AI tells — Grade 6 Australian plain English only

---

## CHANGE 15.01 — Separate IP valuation from headline Asset Pool figure
Find the "Asset Pool Value" display in the stats strip.
Find where the IP formula ($475k + $250×N) contributes to the headline figure.
Add a visible breakdown below the main figure:
  Main figure: community-verified assets only (CHESS holdings + verified RWA — exclude IP formula)
  Separate line below: "Includes $[IP_VALUE] self-assessed intellectual property valuation.
  <a href='#' onclick='...'>How is this calculated?</a>"
Style the IP line: font-size:.72rem; color:var(--text3); margin-top:4px;
The "How is this calculated?" link should expand an inline panel explaining the formula.
Keep all existing JS calculations — only change the display layer to separate the components.

## CHANGE 15.02 — Tier 2 "Proposed Reservation Value" caveat
Find the Tier 2 stats section header.
Add a one-liner directly below the heading:
  "These numbers show what Members plan to do — no money has been committed."
Style: font-size:.78rem; color:var(--text3); font-style:italic; margin-bottom:8px;

## CHANGE 16.01 — Move Four Spokes essay to own page
The Four Spokes essay (~1,300 words) is currently embedded in partners/index.html.
Replace the entire essay section with a single link card:
  <div class="hub-link-card" style="background:var(--panel);border:1px solid var(--border);
    border-radius:16px;padding:22px 24px;margin:24px 0;max-width:520px">
    <div style="font-size:.72rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;
      color:var(--text3);margin-bottom:8px">Foundation reading</div>
    <h3 style="font-family:var(--serif);font-size:1.1rem;font-weight:500;margin-bottom:8px">
      Why we are built like this</h3>
    <p style="font-size:.88rem;color:var(--text3);margin-bottom:14px;line-height:1.6">
      The four token classes, the no-fiat rule, and how the community owns real assets.
      About 5 minutes.</p>
    <a href="/about/four-spokes/" style="font-size:.88rem;font-weight:600;color:var(--gold-2);
      text-decoration:none">Read the full explanation ›</a>
  </div>

Remove the essay HTML entirely (the section starting at "From a Founding Member" and containing
all four spokes, the Rim, and the Road paragraphs).
Keep a comment: <!-- Four Spokes essay moved to /about/four-spokes/ -->

NOTE: The /about/four-spokes/ page does not need to be built in this session.
The link will 404 until built post-Foundation Day. That is acceptable.

## CHANGE 17.01 — JVPA modal: keep PDF in-page
Find the JVPA PDF link that currently opens in a new tab (target="_blank").
Change to target="_self" or better: embed the PDF link to open in-page using an iframe.
Replace the external link with:
  <div style="border:1px solid var(--border);border-radius:8px;overflow:hidden;margin:16px 0">
    <iframe src="../docs/COGS_JVPA.pdf" width="100%" height="400px"
            style="border:none;display:block" title="Joint Venture Participation Agreement"></iframe>
  </div>
  <a href="../docs/COGS_JVPA.pdf" download
     style="font-size:.82rem;color:var(--gold-2)">Download PDF ›</a>

Remove the onJvpaDocOpened click handler dependency — the checkbox should enable after a
fixed time period (30 seconds) OR after the iframe fires its load event, whichever comes first.
Change the checkbox enable logic:
  After 30 seconds: enable checkbox regardless (enough time to have seen the document)
  Show countdown: "Checkbox enables in [N]s" counting down.

## CHANGE 17.02 — JVPA acceptance instructions
Find: "The acceptance checkbox enables automatically once you have had time to review it."
Replace with: "Read the agreement above. The checkbox becomes active in 30 seconds."
Find: "Open the agreement above to enable your acceptance ↑" (the muted hint)
Replace with: "Scroll up to read the agreement."

## CHANGE 18.01 — Consolidate Truth Window + Foundation Holdings
Find the Truth Window section and the Foundation Holdings section.
Replace both with a single consolidated panel:

  <div class="community-holdings-panel">
    <div class="panel-heading">What the community owns right now</div>
    <p style="font-size:.84rem;color:var(--text3);margin-bottom:16px">
      Live data from the Foundation ledger and the ASX CHESS register.</p>

    <!-- Tab switcher -->
    <div class="holdings-tabs">
      <button class="holdings-tab active" onclick="switchHoldingsTab('tokens')">
        Tokens issued</button>
      <button class="holdings-tab" onclick="switchHoldingsTab('assets')">
        Assets backing them</button>
    </div>

    <!-- Tab 1: Token registry (from Truth Window) -->
    <div id="holdings-tab-tokens" class="holdings-tab-content">
      [Move existing Truth Window token registry content here]
    </div>

    <!-- Tab 2: ASX + RWA assets (from Foundation Holdings) -->
    <div id="holdings-tab-assets" class="holdings-tab-content" style="display:none">
      [Move existing Foundation Holdings ASX + RWA content here]
    </div>
  </div>

Add a JS switcher function:
  function switchHoldingsTab(tab) {
    document.querySelectorAll('.holdings-tab').forEach(function(b){ b.classList.remove('active'); });
    document.querySelectorAll('.holdings-tab-content').forEach(function(c){ c.style.display='none'; });
    document.getElementById('holdings-tab-'+tab).style.display='';
    event.target.classList.add('active');
  }

Add a one-paragraph link between the two tabs:
  "The tokens above are backed by the assets on the right. Every token class has a real-world
   asset or income stream behind it."

## VERIFICATION
1. div balance
2. script balance
3. </body> once, </html> once
4. IP formula has separate display line with "self-assessed" label
5. Four Spokes essay HTML removed, link card present
6. JVPA PDF embedded as iframe, not new-tab link
7. Checkbox countdown (30s) present
8. "What the community owns right now" panel present
9. Two-tab switcher (tokens / assets) present
10. Separate Truth Window and Foundation Holdings sections removed

## COMMIT
git add partners/index.html
git commit -m "fix(hub): stats IP separation, JVPA in-page PDF, four-spokes link card, holdings consolidation"
git checkout -b review/session-10 && git push origin review/session-10
