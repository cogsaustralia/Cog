# UX Audit Remediation — Cold Funnel
# Source: _design/audits/ux-audit-cold-funnel-2026-05-03.html
# All changes approved. Read every file before touching it. No guessing.
# Show git diff and STOP before committing. Thomas reviews before every commit.
# Deploy order: each file independently — read, edit, verify, diff, wait.

## GROUND TRUTH RULES
- Read the exact current file state before every edit
- Python write only for file creation — never heredoc
- Stage only explicit filepaths — never git add .
- After every file: check div balance, script balance, </body>, </html>
- Zero AI tells in user-facing text — Grade 6 Australian plain English only
- Banned: em-dashes, "I understand" repeated, passive constructions, not-X-not-Y-not-Z

---

## FILE 1: join/index.html

**3.1 — Remove hidden caretaker invite inject**
Find the cold-path block (comment: "Caretaker default invite for cold visitors").
If no URL invite code is present: hide invite-wrap (display:none) only.
Do NOT inject COGS-FUHT2L and do NOT call validateInviteCode().
Cold path auto-validation is handled server-side. Remove the inject entirely.
If a real partner code IS in the URL (hasCode=true): show invite section normally.
_inviteValid must be set to true for cold visitors by default so Register button enables.
Add this after the hasCode check: if (!hasCode) { _inviteValid = true; checkSubmit(); return; }

**3.5 — Replace 6-step field stepper with flat 5-field form**
Remove the entire #field-stepper div and its IIFE (block starting "/* ── Field stepper ── */").
Replace with a flat form grid inside step-1, showing all 5 fields at once:
  Row 1: first_name (col 1) + middle_name optional (col 2)
  Row 2: last_name (full width)
  Row 3: email (full width)
  Row 4: mobile (full width)
Keep same input IDs/names. Keep existing validateStep1() — it validates by ID.
Remove buildSummary() function and fs-summary-text element.
Membership pill, cost row, and step-nav are VISIBLE from page load — remove the
hide-until-fs-done IIFE that sets them to display:none.
Keep the 2-step outer progress (step-1/step-2) unchanged.

**3.6 — Define vault on first mention**
In the hero trust box, first mention of "Independence Vault":
Change to: "your member dashboard (we call it the Independence Vault)"
In the side panel "What happens after you register" list, change any "vault" reference
to "your member dashboard" on first mention only.

**3.7 — Single spinner replaces theatrical progress**
In the submit handler, remove the _pMsgs array and _pTimers setTimeout loop entirely.
Replace with: status.textContent = 'Lodging your registration...';
Remove all _pTimers.forEach(clearTimeout) calls.

**3.8 — Replace auto-fill stewardship module with 5 real questions**
In the submit handler, find the hard-coded stewardship_module object. Remove it.
Add data['stewardship_module'] built from the actual user answers captured on step 2.

On step 2 HTML, ABOVE the confirm-summary div, add a stewardship section:
  - Heading: "Before you submit — 5 quick questions"
  - Subheading: "Your answers are recorded with your membership."
  - 5 question cards, each with 2 radio options (agree / learn-more)
  - "Learn more" expands an inline explanation div (toggle, no page nav)
  - All 5 must be "agree" to enable Register button (add to checkSubmit)

Question wording (exact, do not change):

Q1: "COG$ is a community joint venture, not an investment product. My $4 is a one-time membership fee. There is no guaranteed return and no way to get my $4 back."
  agree: "Yes, I get that"
  more: "Tell me more" → "Your $4 pays for your Member record and governance vote. It cannot be refunded. COG$ is not a bank, a fund, or a financial adviser."

Q2: "I am joining to have a say in how Australia's resources are managed. My vote counts the same as every other Member, regardless of when I joined."
  agree: "Yes, that's why I'm joining"
  more: "How does voting work?" → "Every Member gets one vote. No one gets extra votes for joining early or paying more. Votes are cast through your member dashboard."

Q3: "When the Foundation earns income from its shareholdings, half goes back to all Members equally. The other half buys more ASX shares to grow what the Foundation owns and increase everyone's governance weight over time."
  agree: "I get how the money works"
  more: "Tell me more" → "The split is fixed in the Foundation rules and cannot be changed without a Member vote. You do not need to do anything — it happens automatically."

Q4: "Traditional Custodians of Country have binding authority over extraction decisions on their land. This is written into the Foundation rules and cannot be changed without their agreement."
  agree: "I respect that and agree with it"
  more: "Why does this matter?" → "COG$ operates on Bundjalung Country and across Australia. First Nations Custodians have a binding veto on resource extraction. This is not a courtesy — it is a legal rule in the Foundation's governing documents."

Q5: "COG$ is new. There is no guaranteed return and no government protection. I am joining because I believe in what COG$ is trying to do."
  agree: "I understand the risk and I'm in"
  more: "What are the risks?" → "COG$ may not achieve its goals. The Foundation could fail. You could lose your $4. No government scheme protects this membership. Join only if you support the community purpose."

Style for question cards: use existing panel/border variables, radio inputs styled as
selection cards (border highlights on selection), consistent with site dark-gold system.
Store answers as: { q1: 'agree', q2: 'agree', ... } in stewardship_module.answers.

**3.9 — Fix scrollIntoView**
Find all instances of .scrollIntoView({behavior:'smooth',block:'start'}) or similar.
Replace with:
  var _el = document.getElementById('form');
  window.scrollTo({ top: _el.getBoundingClientRect().top + window.scrollY - 80, behavior: 'smooth' });

**3.10 — Promote card fee to cost row**
In the cost row (class="cost-row"), find the cost-note div ("Once only. Not refundable.").
Add a second line below it: "Pay by bank transfer (no fee) or card (+40c Stripe fee)."
Style: same font-size as cost-note, color var(--muted).

---

## FILE 2: thank-you/index.html

**4.1 + 4.4 + 4.5 — Restructure payment section**
Current order: vault CTA (gold primary) → Stripe card block → bank transfer accordion.
New order:
  1. Bank transfer block FIRST — as the default, no accordion, fully visible.
     Heading: "Pay $4 by bank transfer — no fee"
     Show PayID, BSB, Account, Account name, Reference fields (keep copy buttons).
  2. Card payment SECOND — collapsed in a <details> element.
     Summary: "Prefer to pay by card? +40c fee ($4.40 total)"
     Content: the Stripe button (restyled per 4.3 below).
  3. Vault setup CTA THIRD — as secondary action, NOT the primary gold button.
     Change text to: "Set up your member dashboard"
     Change style from btn-primary to btn-secondary.
     Add note below: "Your membership activates when payment arrives."

**4.3 — Restyle Stripe block to match site visual system**
Replace the current Stripe card inline styles (dark blue gradient, #8b6914 button) with
the site's existing glass-card system. Use:
  background: var(--panel) or equivalent dark panel
  border: 1px solid var(--gold-rim2)
  border-radius: var(--r) or 16px
  button: same gold gradient as .btn-primary on the site
Remove all hardcoded #1a1a2e, #16213e, #8b6914 colours from this block.

**4.2 — Move voice submission below payment**
The vs-ty-panel currently appears before the vault CTA.
Move it to AFTER the vault CTA section, with a new heading: "While you wait for payment"
This makes payment the clear primary action and voice submission an optional extra.

**4.5 — Fix primary CTA wording**
The main vault link button currently says "Open my vault ›".
Change to: "Set up your member dashboard"
Add a line below: "Your membership activates when your $4 arrives."

**4.6 — Member number fallback**
In the JS, find the fallback when num is empty:
  numEl.textContent = 'Check your email';
Change to: 'Your Member ID will arrive by email within 60 seconds.'
Change the sub text to: 'Use it to sign in to your member dashboard.'

**X.6 — Mobile breakpoint for involve-grid**
In the thank-you CSS, find the 560px breakpoint with involve-grid: 1 column.
Add a new breakpoint at 760px: involve-grid { grid-template-columns: 1fr 1fr; }
(This catches the awkward 640–760px zone noted in the audit.)

---

## FILE 3: seat/index.html

**X.2 — Privacy link on email capture**
Find the email capture form/input on the seat page.
Add a one-liner directly below the submit button:
  "We will not share your email. <a href='/privacy/'>Privacy policy</a>."
Style: font-size 0.78rem, color var(--muted) or equivalent.

**2.5 — Move Substack link out of CTA area**
Find the "Read the CEO Power Cheat Sheet" Substack link near the join CTA.
Move it to the page footer or remove from the cold path entirely.
It must not appear adjacent to the primary $4 CTA.

---

## EXECUTION ORDER
1. Read join/index.html → make all join changes → verify → diff → STOP
2. Read thank-you/index.html → make all thank-you changes → verify → diff → STOP
3. Read seat/index.html → make seat changes → verify → diff → STOP
4. After Thomas approves all 3 diffs: commit all 3 files in one commit.

## COMMIT MESSAGE
"fix: UX audit remediation — flat form, stewardship questions, payment default, voice submission order, Stripe restyle, privacy link"
