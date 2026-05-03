# UX Audit Remediation — Session 7: wallets/member.html — Pay Modal + Distribution Election
# Source: _design/audits/ux-audit-vault-2026-05-03.html findings 10.01-10.05 + watch items
# Read the file before every edit. Show diff. STOP before committing.
# This session runs AFTER session 6 is merged to main. Pull main before starting.

## GROUND TRUTH RULES
- git pull --rebase origin main before starting
- Read exact current file state before every edit
- Stage only wallets/member.html
- Verify div balance, script balance, </body>, </html> after all changes
- Zero AI tells — Grade 6 Australian plain English only

---

## CHANGE 10.01 — Pay modal price consistency (finding 10.01)
Find the pay modal heading: "Complete your $4.00 membership contribution"
Change to: "Pay to activate your membership"

Find where "$4.40 by card via Stripe" appears in the modal.
Add a one-liner directly above it:
  "Bank transfer: $4.00 (no fee) | Card: $4.40 (40c Stripe fee)"
Style: font-size:.78rem; font-weight:600; color:var(--text3); margin-bottom:12px; text-align:center;

Ensure the bank transfer option appears FIRST (before card) and is open by default.
The card option should be collapsed inside a <details> element with summary:
  "Pay by card instead (+40c fee)"

## CHANGE 10.02 — CJVM Sub-Trust A account name explanation (finding 10.02)
Find in the pay modal where the account name "The Trustee for the CJVM Sub-Trust A" appears.
Add a plain-English line directly below it:
  "This is the legal name of the COGS member fund. CJVM = Community Joint Venture Members.
   Australian trust account held at Macquarie Bank."
Style: font-size:.78rem; color:var(--text3); margin-top:4px; line-height:1.5;

## CHANGE 10.03 — "I've sent this payment" button (finding 10.03)
Find all instances of: "I've sent this payment" (button label)
Replace with: "I've sent it — let me know when it lands"
Keep the onclick handler unchanged.

## CHANGE 10.04 — Stripe new tab (finding 10.04)
Find the Stripe payment link/button with target="_blank".
Change target="_blank" to target="_self".
Keep all other attributes (href, class, id) unchanged.

## CHANGE 10.05 — Consolidate duplicate pay modals (finding 10.05)
Find the "Outstanding payments" modal (separate from the main pay modal).
If it duplicates the same bank details, PayID, and Stripe link as the main pay modal:
  - Remove the full duplicate bank/PayID/Stripe block from the outstanding payments modal
  - Replace with a single line: "Payment details are shown below."
  - Add a JS call that opens the main pay modal, or include the items list at the top
    of the main pay modal when triggered from outstanding payments.
  - Ensure the $4/$4.40 fix from 10.01 propagates to both trigger points.

## CHANGE W.1 — Distribution election — hide until Expansion Day (watch item)
Find the Distribution election section in the profile drawer (lines ~1541-1548).
Wrap the entire section div in a conditional display:none container.
Add a replacement note in its place:
  <div class="profile-section" id="dist-election-placeholder">
    <div class="profile-section-title">How you'd like to be paid</div>
    <p style="font-size:.88rem;color:var(--text3);line-height:1.6">
      This option becomes available when COGS is approved by ASIC (Expansion Day).
      We will email you when you can choose.
    </p>
  </div>
Hide the original distribution election buttons (elect-aud, elect-cog divs) with display:none.
Keep the underlying JS setElection() function — just hide the UI.

## CHANGE W.2 — ABN and entity in topbar/footer (from priorities, fix-next)
Find the vault topbar or page footer.
Add a small text line in the footer (below the main content, above </body>):
  "COG$ of Australia Foundation · ABN 91 341 497 529 · Drake Village NSW 2469 ·
   <a href='mailto:admin@cogsaustralia.org'>admin@cogsaustralia.org</a>"
Style: font-size:.72rem; color:var(--text3); text-align:center; padding:16px;
margin-top:24px; border-top:1px solid var(--border);


## CHANGE K6-PAY — Sharper pay modal strings from k6 audit

  "Complete your $4.00 membership contribution" (modal heading)
  → "Pay your $4 to finish joining."

  "Pay $4.40 by card via Stripe" (Stripe button label)
  → "Pay $4 + 40c card fee — by Apple Pay, Google Pay, or card."

  "The Trustee for the CJVM Sub-Trust A" (account name display label, NOT the actual account name)
  Add below it: "COGS Members Fund (legal name: The Trustee for the CJVM Sub-Trust A)."

  "$2.00 is invested through Sub-Trust A into the Members Asset Pool, generating a permanent Donation Dividend Stream."
  → "$2 goes to community projects. The other $2 is invested, and the income from it goes to those projects."

  "Pay It Forward sponsors another Member's $4 entry who cannot afford it."
  → "Pay $4 for someone who can't afford to join. They get the same vote as you."

  "Distribution election — When dividend distributions are active (post-Expansion Day), choose how you receive your share."
  → "How would you like to be paid later? Choose Australian dollars or new COG$. Decide closer to the date."
  (Note: this section is being hidden per change W.1 — ensure the placeholder note uses this wording)

## VERIFICATION
1. div balance
2. script balance
3. </body> once, </html> once
4. Pay modal heading no longer says "$4.00 membership contribution"
5. Bank transfer appears first and open by default in pay modal
6. Stripe link uses target="_self" not target="_blank"
7. "I've sent it — let me know when it lands" button text present
8. Distribution election buttons are hidden (display:none)
9. Placeholder "How you'd like to be paid" note is visible
10. ABN line present in footer
11. CJVM explanation line present near account name

## COMMIT
git add wallets/member.html
git commit -m "fix(vault): pay modal consistency, distribution election hidden, ABN footer, Stripe target self"
git checkout -b review/session-7 && git push origin review/session-7
