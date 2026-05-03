# UX Audit Remediation — Session 9: partners/index.html — Auth Gate + First Viewport + Hero Buttons
# Source: _design/audits/ux-audit-hub-2026-05-03.html findings 13.x, 14.x, 19.x
# Pull main before starting: git pull --rebase origin main
# Read the file before every edit. Show diff. STOP before committing.

## GROUND TRUTH RULES
- Read exact current file state before every edit
- Stage only partners/index.html
- Verify div balance, script balance, </body>, </html> after all changes
- Zero AI tells — Grade 6 Australian plain English only

---

## CHANGE 19.01 — Auth gate: also detect ?vt= token for setup panel
The existing code already switches to ap-setup when ?welcome=1 is present (line ~3021).
Find the ?welcome=1 check block (around line 2990).
Add an ADDITIONAL check BEFORE the welcome block:

  var vtParams = new URLSearchParams(window.location.search);
  if(vtParams.get('vt') && !vtParams.get('welcome')) {
    showPanel('ap-setup');
  }

This ensures Members arriving via a ?vt= voice token link also land on setup, not login.

## CHANGE 19.02 — Auth gate: single input field
Find the login form #f-login.
Currently it shows two separate fields: "Mobile number" (type=tel) and a secondary "or email address" (type=email).
Replace with a SINGLE input field:
  <label class="form-label">Mobile or email</label>
  <input class="form-input" type="text" name="login_identity" autocomplete="username"
         placeholder="04xx xxx xxx or you@example.com" required>
Update the login form submit handler to send the value as either mobile or email based on format detection:
  var val = form.querySelector('[name=login_identity]').value.trim();
  var isEmail = val.includes('@');
  body = isEmail ? {email: val, password: ...} : {mobile: val, password: ...};
Keep all existing auth API calls unchanged — only the field capture changes.

## CHANGE 19.03 — Auth gate: setup panel 16-digit Member number
Find the setup panel #f-setup.
The Member number field currently has placeholder "16-digit number".
Change placeholder to: "Paste from your welcome email"
Add a hint below the field (in the existing auth-hint or similar pattern):
  "Your 16-digit Member number is in the email we sent you. Copy and paste it here."
Style: font-size:.78rem; color:var(--text3); margin-top:4px;

## CHANGE 13.01 — Collapse pill row into account menu
Find the pill row at the top of the Hub (Log out · Home · Profile · My Voice · Pass the Coin · Invite Member · Email Admin · phone reveal).
Replace the entire row with a two-element topbar:
  LEFT: Member name + member number in serif (populated by JS after login, same as existing wb-greeting logic)
  RIGHT: Single "Account ▾" dropdown button that reveals the existing pill actions on click

The dropdown content should contain all the existing pill actions, preserving all existing onclick handlers.
Add a simple toggle: clicking "Account ▾" shows/hides a dropdown div.
The dropdown closes when clicking outside (document click listener).

CSS for dropdown: position absolute, right-aligned, background var(--bg2) or --panel,
border 1px solid var(--border), border-radius 12px, padding 8px, z-index 200.

## CHANGE 13.02 — Visual distinction for destructive vs navigation pills
Inside the new Account dropdown, apply visual weight differences:
  "Log out" — color var(--err) or red-tinted, separated by a border-top from the navigation items
  "Home", "Profile" etc — standard weight
  "Email Admin" — muted, smaller
Keep all existing onclick handlers — only visual changes.

## CHANGE 14.01 — Hero buttons: priority order by payment status
Find the hero buttons section.
The JS already knows payment status from the vault/member API response.
Reorder the 4 buttons based on Member status:

For UNPAID Members (signup_payment_status = 'unpaid'):
  1. Pay $4 — your membership is waiting (link to pay modal or thank-you payment flow)
  2. Open your account (vault)
  3. What we are doing together (hub)
  4. Invite a friend

For PAID Members (signup_payment_status = 'paid' or 'pending'):
  1. Open your account (vault)
  2. What we are doing together (hub)
  3. Invite a friend
  4. Why I joined (voice)

Add a JS reorder function that runs after enterCommunity() loads Member data.
The buttons should be the same HTML elements — just reordered in the DOM.
Function name: reorderHeroButtons(paymentStatus)
Call it inside enterCommunity() after the member data is available.

## CHANGE 14.02 — First button gets primary styling
After reordering, apply a visual primary emphasis to button[0] in the hero grid:
  border-color: var(--gold-1)
  background: rgba(232,184,75,.08)
All other buttons remain at their standard styling.

## VERIFICATION
1. div balance
2. script balance
3. </body> once, </html> once
4. Single login_identity input present in #f-login
5. Account dropdown present, all pill actions inside it
6. reorderHeroButtons function defined and called in enterCommunity
7. ?vt= param triggers ap-setup panel
8. "Paste from your welcome email" placeholder on Member number field

## COMMIT
git add partners/index.html
git commit -m "fix(hub): auth gate single input, ?vt= setup panel, pill collapse, hero button priority order"
git checkout -b review/session-9 && git push origin review/session-9
