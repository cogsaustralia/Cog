# UX Audit Remediation — Session 2: thank-you/index.html
# Read file before every edit. Show diff. STOP before committing.

## GROUND TRUTH RULES
- Read exact current file state before every edit
- Stage only thank-you/index.html
- Verify div balance, script balance, </body>, </html> after all changes
- Zero AI tells — Grade 6 Australian plain English only

---

## CHANGE 4.1 + 4.4 + 4.5 — Restructure payment section

Current order: vault CTA (gold primary) → Stripe block → bank transfer accordion → info cards
New order:
  1. Bank transfer FIRST — fully visible, no accordion, heading: "Pay $4 by bank transfer — no fee"
  2. Card payment SECOND — inside <details>, summary: "Prefer to pay by card? +40c fee ($4.40 total)"
     Contains restyled Stripe button (see 4.3 below)
  3. Vault setup CTA THIRD — btn-secondary style (not gold primary)
     Text: "Set up your member dashboard"
     Note below: "Your membership activates when your $4 arrives."
  4. Voice submission panel FOURTH (currently above vault CTA — move it here)
  5. Foundation Day band and info cards last (unchanged)

---

## CHANGE 4.3 — Restyle Stripe block

Remove all hardcoded inline styles from the stripe-pay-card div:
  background: linear-gradient(135deg,#1a1a2e 0%,#16213e 100%)
  border: 1px solid #8b6914
  and any other hardcoded hex colours

Replace with site CSS variables:
  background: var(--panel) — same dark panel as other cards
  border: 1px solid var(--gold-rim2)
  border-radius: var(--r, 20px)
  padding: 26px 28px

Restyle the Stripe pay button to match .btn-primary:
  background: linear-gradient(135deg,var(--gold-1),var(--gold-2))
  color: #1a0f00
  font-weight: 700
  border-radius: 99px
  padding: 13px 28px
  text-decoration: none
  display: inline-block

Update button label to: "Pay $4.40 by card"
Keep the client_reference_id JS append logic unchanged.

---

## CHANGE 4.2 — Move voice submission below vault CTA

The vs-ty-panel currently appears before the vault-cta div.
Move it to after the vault-cta div.
Add a heading above it: "While you wait"
Style: font-size .72rem, font-weight 700, letter-spacing .12em, text-transform uppercase,
color var(--gold-2), margin-bottom 10px.

---

## CHANGE 4.6 — Member number fallback message

In the JS DOMContentLoaded block, find:
  numEl.textContent = 'Check your email';
  if (subEl) subEl.textContent = 'Your Member number will arrive by email shortly...';

Change to:
  numEl.textContent = 'On its way';
  if (subEl) subEl.textContent = 'Your Member ID will arrive by email within 60 seconds. Use it to sign in to your member dashboard.';

---

## CHANGE X.6 — Mobile breakpoint for involve-grid

In the CSS, find the existing @media(max-width:560px) block with involve-grid.
Add a NEW breakpoint ABOVE it:
  @media(max-width:760px){
    .involve-grid{grid-template-columns:1fr 1fr}
  }

---

## VERIFICATION
1. div balance
2. script balance
3. </body> once, </html> once
4. No hardcoded #1a1a2e or #16213e in stripe block
5. vs-ty-panel appears AFTER vault-cta in DOM order
6. Bank transfer block appears BEFORE details/stripe block
7. Vault CTA button uses btn-secondary class

## COMMIT
  git add thank-you/index.html
  git commit -m "fix(thank-you): payment default bank transfer, restyle Stripe, move voice submission, vault CTA secondary"
  git pull --rebase origin main && git push origin main
