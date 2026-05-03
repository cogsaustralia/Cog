# UX Audit Remediation — Session 5: wallets/member.html — Overview Tab + Sidebar
# Source: _design/audits/ux-audit-vault-2026-05-03.html findings 3.x, 5.01-5.06, 4.x
# Read the file before every edit. Show diff. STOP before committing.
# This session runs AFTER session 4 is merged to main. Pull main before starting.

## GROUND TRUTH RULES
- git pull --rebase origin main before starting
- Read exact current file state before every edit
- Stage only wallets/member.html
- Verify div balance, script balance, </body>, </html> after all changes
- Zero AI tells — Grade 6 Australian plain English only

---

## CHANGE 3.01 — Governance record banner (finding 3.01)
Find the gold-bordered "Action required" banner asking for street address + date of birth.
Change the banner heading from "Action required" or similar urgent framing to:
  "Finish setting up your profile"
Change the body text to:
  "We need your date of birth and address to activate your vote for Foundation Day.
   Takes 30 seconds. We explain why we ask."
Change the CTA button text to: "Set up now"
Change border/background styling from gold/warning to a calm neutral:
  border-color: var(--border) or similar non-gold colour
  background: var(--panel) or var(--bg2)

## CHANGE 4.03 — Members Hub topbar pill (finding 4.03)
Find the topbar pill/button labelled "Members Hub" or similar with house icon
that links to /partners/index.html.
Change the label to: "Home"
Keep the link destination unchanged.

## CHANGE 4.04 — Surface key definitions inline (finding 4.04)
For the following sections that currently rely solely on iq-btn tooltips for definitions,
add a one-line plain-English subtitle directly below the section heading:

1. Community COG$ section heading: add subtitle
   "You can send these to other members today."

2. Overview/Holdings "Reservations" heading: add subtitle
   "No money, no commitment — just your interest list."

3. Polls/governance heading: add subtitle
   "One equal vote per member. Yours counts the same as everyone else's."

Style subtitle: font-size:.84rem; color:var(--text3); margin-top:4px; line-height:1.5;

## CHANGE 5.01 — Overview lead with vote (finding 5.01)
Find the Overview tab hero area (the first card or stat block a member sees on load).
Add as the FIRST visible element inside #tab-overview:
  A card showing:
  Heading: "You have 1 equal vote"
  Body: "Your vote counts the same as every other member. This never changes."
  Style: background var(--panel), border 1px solid var(--border), border-radius 12px,
         padding 18px 20px, margin-bottom 16px.
  The member number (already shown elsewhere) does not need to be repeated here.

## CHANGE 5.02 — Empty state for reservations (finding 5.02)
In the Overview reservations strip, find where ASX COG$ / RWA COG$ / Landholder COG$
placeholder rows are rendered with zero quantities.
Add an empty-state check: if all reservation quantities are zero or null,
show instead:
  "You have no reservations yet."
  "Reservations are your interest list for future COGS token classes — no money, no commitment."
  A link: "Learn more about reservations →" linking to #tab-holdings or the holdings tab.
Hide the zero-quantity rows entirely when no reservations are active.

## CHANGE 5.04 — Mainspring Hub link card (finding 5.04)
Find the Overview link card titled "Mainspring Hub" or similar.
(Note: Session 4 already renamed "Mainspring" to "Community work" in text.
Verify the card heading now reads "Community work".)
Change the card subtitle/description to:
  "Projects, acquisitions, partnerships and first nations governance."
Remove the word "Mainspring" if it still appears anywhere in this card.

## CHANGE 5.05 — Outstanding payments card styling (finding 5.05)
Find the "Outstanding payments" action card/banner.
It currently uses gold styling identical to the governance banner.
Change its border and background to red-tinted or amber-tinted:
  border-color: #c0392b or var(--warn, #c0392b)
  background: rgba(192,57,43,.06)
This visually separates financial actions (red/amber) from profile/setup actions (neutral).

## CHANGE 5.06 — Promote Member token to Overview (finding 5.06)
Find the Personal S-NFT / Member token display (currently only in holdings tab).
Add a read-only display card on the Overview tab showing:
  Heading: "Your Member token"
  Body: "1 of 1 — can't be sold or transferred"
  Sub: "This is proof of your membership and your right to vote."
  Style: same panel/border style as the vote card added in 5.01.
  Place it directly below the vote card.

## VERIFICATION
1. div balance
2. script balance
3. </body> once, </html> once
4. Governance banner no longer uses gold/warning styling
5. "You have 1 equal vote" card present in #tab-overview
6. Empty-state check present for zero reservations
7. No em-dashes in new user-facing strings

## COMMIT
git add wallets/member.html
git commit -m "fix(vault): overview tab — vote card, empty state reservations, governance banner, member token"
git checkout -b review/session-5 && git push origin review/session-5
