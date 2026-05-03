# UX Audit Remediation — Session 12: intro/index.html — Structural Fixes
# Source: _design/audits/ux-audit-intro-2026-05-03.html findings 24.x, 30.x, 31.x
# Run AFTER session 11 is merged to main.
# Pull main before starting: git pull --rebase origin main

## GROUND TRUTH RULES
- Read exact current file state before every edit
- Stage only intro/index.html
- Verify div balance, script balance, </body>, </html> after all changes
- Zero AI tells — Grade 6 Australian plain English only

---

## CHANGE 24.01 — Inviter strip: show immediately, not async
The inviter strip currently shows display:none until an API call resolves.
For warm visitors arriving with a code in the URL, the code is already known client-side.
Fix: if the URL code is present on page load, show a provisional strip immediately
using "A member" as the inviter name, then update with the real name when the API resolves.

Find the inviter strip element (the green strip showing "Sarah thinks you deserve a say").
Add inline: if URLSearchParams has a code, immediately set strip visibility to visible
with placeholder text: "A member sent you this link."
Then the existing API call updates it with the real name when it resolves.
Do not leave the strip display:none on initial paint when a code is present.

## CHANGE 24.02 — Reduce pill row to essential items only (finding 24.01/24.02)
The first viewport currently shows: wordmark, step counter, two dismiss buttons,
ABN strip, THEN the inviter strip and card content.
Remove the ABN strip from the first viewport (above the fold).
Move it to the footer of the page, below the last card.
The first thing after the wordmark should be the inviter strip and card heading.

## CHANGE 30.01 — Fix localStorage cogs_intro_seen (finding 30.01)
Find: localStorage.setItem('cogs_intro_seen', '1')
This is currently set on ANY skip or next-past-card-5, permanently locking out return visits.
Change behaviour: only set cogs_intro_seen when the user reaches Card 5 AND
clicks the final join button. A partial visit (Skip from Card 1-4, or tab close) should
NOT set the flag.
Find all localStorage.setItem('cogs_intro_seen') calls and audit each one:
  - Remove calls that fire on Skip before Card 5
  - Remove calls that fire on card navigation before the final button
  - Keep only the call that fires when the user clicks the final join/proceed CTA

## CHANGE 30.02 — Skip button behaviour (finding 30.02)
Currently "Skip ›" routes straight to /join/ bypassing the intro entirely.
Change to route to Card 5 directly (show final card, let user decide).
This preserves the warm-traffic value while still being respectful of the user's time.
Find the Skip button onclick handler, change destination from /join/ or next-page
to showCard(4) or equivalent (jump to last card index).

## CHANGE 24.03 — Step counter accessibility (finding 24.03)
Find the step counter (e.g. "1 of 5", "2 of 5" etc.).
Add aria-live="polite" and aria-label="Step [N] of 5" to the counter element.
This is a low-risk accessibility fix.

## VERIFICATION
1. div balance
2. script balance
3. </body> once, </html> once
4. localStorage.setItem('cogs_intro_seen') only fires on final join CTA click
5. Skip button routes to Card 5, not directly to /join/
6. Inviter strip shows immediately (not display:none on first paint when code present)
7. ABN strip moved to page footer

## COMMIT
git add intro/index.html
git commit -m "fix(intro): inviter strip immediate, localStorage partial-visit fix, skip to card 5, ABN strip to footer"
git checkout -b review/session-12 && git push origin review/session-12
