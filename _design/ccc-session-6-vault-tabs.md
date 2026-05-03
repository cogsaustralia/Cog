# UX Audit Remediation — Session 6: wallets/member.html — Holdings, Polls, Send/Receive, Give tabs
# Source: _design/audits/ux-audit-vault-2026-05-03.html findings 6.01-9.x
# Read the file before every edit. Show diff. STOP before committing.
# This session runs AFTER session 5 is merged to main. Pull main before starting.

## GROUND TRUTH RULES
- git pull --rebase origin main before starting
- Read exact current file state before every edit
- Stage only wallets/member.html
- Verify div balance, script balance, </body>, </html> after all changes
- Zero AI tells — Grade 6 Australian plain English only

---

## CHANGE 6.01 — Holdings tab group structure (finding 6.01)
The holdings tab currently shows all token types unsorted.
Add two visual group headings inside #tab-holdings:

Group 1 heading: "What you have today"
  - Member token (Personal S-NFT)
  - Kids tokens (kS-NFT) — if any exist
  - Resident COG$ (Class Lr) — if any exist
  - Community COG$ balance (Class C)

Group 2 heading: "Your interest list"
  - Reservations (ASX COG$, RWA COG$, Landholder COG$)
  - Caption under heading: "No money, no commitment — just your interest."

Style group headings: font-size:.72rem; font-weight:700; letter-spacing:.12em;
text-transform:uppercase; color:var(--text3); margin:20px 0 10px;

## CHANGE 6.02 — Kids token copy (finding 6.02)
Find the kS-NFT row subtitle/description in the holdings tab.
Current text contains: "Class kS · Guardian-held soulbound token · converts to Class S on child's 18th birthday"
Replace the entire subtitle with:
  "Held by you for your child. On their 18th birthday it becomes their own Member token with one equal vote. Can't be sold or transferred."

## CHANGE 6.03 — GNAF zone text (finding 6.03)
(Note: Session 4 removed GNAF from display text. Verify no GNAF remains in user-facing strings in this tab.)
Find the Resident COG$ (Class Lr) row description.
Ensure it reads:
  "Resident COG$ are given to people who live in areas where COGS is running a project. We check using the address on your profile."
If this text already matches (from session 4), confirm and move on.

## CHANGE 6.04 — Save button (finding 6.04)
(Note: Session 4 changed "Update My Options" to "Save my reservations". Verify.)

## CHANGE 6.05 — Released after Expansion Day text (finding 6.05)
Find all three instances of: "Released after Expansion Day" or "released after Expansion Day"
Replace each with:
  "Becomes available when COGS is approved by ASIC. We will email you when this happens."

## CHANGE 7.02 — Poll supporter guidance (finding 7.02)
Find the "Start a vote" / "Initiate a Members Poll" section heading in the polls tab.
Add a guidance block directly ABOVE the draft form:
  "Polls need 10 supporters before they go to a vote. Save a draft first, then share it
   with members you know. Once 10 members support it, it goes live."
Style: font-size:.88rem; color:var(--text3); line-height:1.6; margin-bottom:16px;
background:var(--panel); border:1px solid var(--border); border-radius:10px; padding:14px 16px;

## CHANGE 7.03 — Voting receipt copy (finding 7.03)
(Note: Session 4 changed "cryptographically receipted" to "securely logged with a one-way receipt". Verify in polls tab.)

## CHANGE 7.04 — Hide past polls when empty (finding 7.04)
Find the "Past votes" / "Past Members Polls" section in the polls tab.
Add a JS check: if the past polls list is empty (zero items rendered),
set the entire past-polls section to display:none.
Show it only when at least one past poll/vote exists.

## CHANGE 8.01 — Exchange/Send & receive rename verification
(Note: Session 4 renamed "Exchange" tab label to "Send & receive". Verify the tab heading
inside the tab content panel also reads "Send & receive", not "Exchange".)
If the in-panel heading still reads "Exchange", update to "Send & receive".

## CHANGE 9.x — Give tab disabled state (findings 9.x)
Find the disabled state on the Give tab stepper/quantity input.
Find the button or caption that shows when quantity is zero and the continue button is disabled.
Change the disabled caption/tooltip to: "Add at least one to continue."
If the input starts at 0, pre-fill it to 1 as the default value.

## VERIFICATION
1. div balance
2. script balance
3. </body> once, </html> once
4. "What you have today" group heading present in holdings tab
5. "Your interest list" group heading present in holdings tab
6. Kids token description updated
7. No "Released after Expansion Day" remaining
8. Past polls hidden when empty
9. Poll supporter guidance present above draft form

## COMMIT
git add wallets/member.html
git commit -m "fix(vault): holdings/polls/send-receive/give tab fixes — grouping, empty states, guidance copy"
git checkout -b review/session-6 && git push origin review/session-6
