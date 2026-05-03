# UX Audit Remediation — Session 4: wallets/member.html — Terminology & Copy Pass
# Source: _design/audits/ux-audit-vault-2026-05-03.html (Section 11 translation table + scattered copy fixes)
# SCOPE: Pure text/copy changes only. Do NOT change any IDs, class names, JS variable names,
# API keys, or data attributes. Only change visible user-facing display strings.
# Read the file before every edit. Show diff. STOP before committing.

## GROUND TRUTH RULES
- Read exact current file state before every edit
- Stage only wallets/member.html
- Python write only — never heredoc
- After all changes: verify div balance, script balance, </body>, </html>
- Zero AI tells — Grade 6 Australian plain English only
- Banned: em-dashes in user-facing strings, passive constructions

## IMPORTANT: SCOPE BOUNDARY
These changes touch DISPLAY TEXT ONLY. Do NOT change:
- Tab IDs (tab-holdings, tab-governance, tab-exchange etc.)
- JS function names, variable names, API route strings
- CSS class names
- data-* attributes
- Any string used as a JS/PHP identifier

---

## CHANGE A — Sidebar status pill
Find: Cryptographic DB
In display text, change to: Tamper-evident records
(Keep any JS variable or CSS class that references this unchanged)

## CHANGE B — Voting receipt copy
Find all instances of (case-insensitive): cryptographically receipted
Replace each visible instance with: securely logged with a one-way receipt

## CHANGE C — Tab labels (display text only, NOT IDs)
Find the tab button/link visible labels:
  "COG$ Management" → "Polls"
  "COG$ Reservations" → "My tokens"
  "Exchange" → "Send & receive"
  "COG$ News" → "Updates"
  "Activity" → "History"
IMPORTANT: Only change the visible label text inside tab buttons.
Do NOT change tab IDs, onclick handlers, or aria- attributes that reference old names.

## CHANGE D — Save button
Find: "Update My Options"
Replace with: "Save my reservations"

## CHANGE E — Poll initiation label
Find (user-facing label only): "Initiate a Members Poll"
Replace with: "Start a vote"

## CHANGE F — Past polls label
Find (user-facing label only): "Past Members Polls"
Replace with: "Past votes"

## CHANGE G — Mainspring references (user-facing only)
Find visible display text: "Mainspring Hub" or "Mainspring"
Replace with: "Community work"
Do NOT change any route paths, JS variable names, or CSS classes.

## CHANGE H — Soulbound (user-facing only)
Find visible display text containing: "soulbound"
Replace each with: "can't be sold or transferred"

## CHANGE I — GNAF references (user-facing only)
Find visible display text containing: "GNAF"
Replace each with the contextually appropriate plain version:
  "GNAF zone verification" → "address check"
  "Admin-activated via GNAF zone verification" → "Allocated by the Foundation based on your address"
  Any other GNAF user-facing text → remove or replace with "your address on your profile"

## CHANGE J — Member Vault / Independence Vault (user-facing headings/labels only)
Find visible display text:
  "Member Vault" → "Your COGS account"
  "Independence Vault" → "Your COGS account"
EXCEPTION: Do NOT change these inside <title> tag, page URL strings, or JS route names.
Only change H1/H2/H3 headings, paragraph text, button labels, and nav labels.

## CHANGE K — Sub-Trust plain names (user-facing labels only)
Find visible display text ONLY (not variable names, not API strings, not SQL field names):
  "Sub-Trust A" → "Members Asset Pool"
  "Sub-Trust B" → "Members Income Pool"
  "Sub-Trust C" → "Community Projects Fund"
EXCEPTION: Keep Sub-Trust A/B/C inside:
  - Legal tooltip/info panel content where the legal name is intentionally shown
  - Any JS object key or API parameter

## CHANGE L — Foundation Day / Expansion Day plain definitions
Find the FIRST occurrence of "Foundation Day" in user-facing display text.
Add after it in parentheses: (first national vote — 14 May 2026)

Find the FIRST occurrence of "Expansion Day" in user-facing display text.
Add after it in parentheses: (the day COGS is approved by ASIC — date TBC)

All subsequent occurrences: leave as-is (already defined).

## CHANGE M — "Initiate" → "Start" (user-facing button/heading only)
Already covered in Change E. Confirm "Initiate a Members Poll" button text is updated.

## CHANGE N — Distribution Election label (user-facing label only)
Find visible display text: "Distribution election"
Replace with: "How you'd like to be paid (choose later)"


## CHANGE O — K6 string rewrites (additional specific targets from k6 audit)
These are verbatim strings found in member.html that need exact replacement.
Find each exact string and replace with the K6 version:

  "You are entering your Member Vault"
  → "Welcome to your COGS account."

  "Foundation Day 14 May 2026" (in sidebar pill/display only, not in JS date logic)
  → "First COGS vote — 14 May 2026"

  "Class S · Soulbound · One equal national governance vote"
  → "This gives you 1 equal vote in Australia. You can't sell it."

  "Class C · Transferable now · Live exchange"
  → "You can send these to other members today."

  "Class Lr · Address-bound · Admin-activated via GNAF zone verification"
  → "Given to people who live in an area where COGS is helping. We use the address on your profile to check."

  "COG$ Reservations — No Obligation"
  → "Future COG$ — your interest list (no money, no commitment)."

  "Threshold: lesser of 10 Members or 1% of active Members"
  → "You need 10 other members to back your vote before it goes live."

  "Donation COG$ funds Sub-Trust C — First Nations programs, environmental stewardship, and community initiatives."
  → "Donation COG$ pay for First Nations programs, the environment, and community work."

  "Only Community COG$ can move Member-to-Member before Expansion Day."
  → "Right now, only Community COG$ can be sent to other members."

  "Member action log — Every action against your Membership — read-only and unalterable."
  → "Your history. Everything you have done in your account, in order. Cannot be changed."

  "Voting integrity"
  → "How we keep votes honest."

  "Your Community COG$ receive address"
  → "Your address — share this with friends so they can send you Community COG$."

  "Enter the recipient's Community COG$ hash address (COGS-CC-...) and the amount to transfer."
  → "Type the member's number, or paste their Community COG$ address."

  "All-time community contribution"
  → "What you have given so far."

  "Mobile and address changes are reviewed by the Foundation team — they are used for identity verification and cannot be self-served."
  → "To change your phone or address, send us a request. We check it by hand to keep your account safe."

NOTE: For each replacement, search for the exact string. If not found verbatim, find the closest
match and apply the same plain-English principle. Do not change JS variable names or API strings.

## VERIFICATION
1. div balance
2. script balance
3. </body> once, </html> once
4. No "Cryptographic DB" in user-facing text
5. No "soulbound" in user-facing text
6. No "GNAF" in user-facing text
7. "COG$ Management" tab label changed to "Polls"
8. "Exchange" tab label changed to "Send & receive"
9. No em-dashes in user-facing strings (placeholder — values in JS are acceptable)
10. No passive constructions in changed copy

## COMMIT
git add wallets/member.html
git commit -m "fix(vault): terminology and copy pass — plain-English translation table applied"
git pull --rebase origin main && git push origin review/session-4
