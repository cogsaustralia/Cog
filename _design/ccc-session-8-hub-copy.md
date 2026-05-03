# UX Audit Remediation — Session 8: partners/index.html — Hub Copy Pass
# Source: _design/audits/ux-audit-hub-2026-05-03.html (Section 21 — hub-rewrites table)
# SCOPE: Display text changes only. Do NOT change IDs, class names, JS variables, API strings.
# Read the file before every edit. Show diff. STOP before committing.
# Pull main before starting: git pull --rebase origin main

## GROUND TRUTH RULES
- Read exact current file state before every edit
- Stage only partners/index.html
- Python write only — never heredoc
- Verify div balance, script balance, </body>, </html> after all changes
- Zero AI tells — Grade 6 Australian plain English only
- Do NOT change tab IDs, JS function names, route strings, or CSS class names

---

## CHANGE A — Welcome heading
Find user-facing text: "Welcome, Member."
Replace with: "Welcome, [first name]." (keep the JS interpolation — only change the fallback static text if present)

## CHANGE B — Page subheading
Find: "Your home base — identity, governance, community growth, and everything that matters."
Replace with: "This is your home page. Everything you can do is on here."

## CHANGE C — Hero button labels (display text only, not IDs or onclick handlers)
Find each button heading and sub-heading:

  "Enter Independence Vault" → "Open your account"
  Sub: "Financial Governance Profile" → "See what you own, what you can vote on, and your details."

  "Enter Mainspring Management Hub" → "What we are doing together"
  Sub: "Projects and Management" → "Projects, acquisitions, and partnerships."

  "Why I Joined" → "Why I joined" (lowercase i)
  Sub: "Add your voice" → "In one sentence: why did you join? (Optional.)"

  "Pass the Coin" → "Invite a friend"
  Sub: "Share your link" → "Your invite link is below."

  All "Tap here ›" affordances → "Open ›"

## CHANGE D — Stats strip labels
Find: "Founding Members Registry — Live"
Replace with: "How the community is doing today"

Find: "Real World Value — Verified asset backing, live from the Foundation ledger"
Replace with: "What the community owns together (real assets only)."

Find: "Proposed Reservation Value — Options only, no obligation, editable in your Vault"
Replace with: "What Members are planning to do (you can change yours any time)."

Find: "Avg. Value Per Member" (in the stats display label only)
Replace with: "Community assets per Member"

Find: "Asset Pool Value" (display label only)
Replace with: "Community assets, total value"

## CHANGE E — IP formula label
Find the IP formula display text: "IP formula ($475k + $250×N)" or similar
Add a parenthetical after it: "(self-assessed valuation — not a verified holding)"
Keep the formula itself unchanged.

## CHANGE F — JVPA banner
Find: "Action required — Joint Venture Agreement"
Replace with: "One thing left to do — sign the Members' agreement."

Find in JVPA modal body: "The current Joint Venture Participation Agreement requires your electronic acceptance before you can participate in governance."
Replace with: "Read the agreement and tick to accept it. Until you do, you can't vote."

Find JVPA checkbox label: "I have read and accept the COG$ of Australia Foundation Joint Venture Participation Agreement on equal terms with all existing Members."
Replace with: "I have read the Members' agreement (version [X.Y], [DD MMM YYYY]) and I accept it. I understand it can only be changed by a Members' vote, and any change applies to me and every other Member equally."
(Keep version and date dynamic if already interpolated from JS)

## CHANGE G — Milestone steps
Find: "Registered · Your Partner number and all interests are permanently recorded in the COGS Registry."
Replace with: "Step 1 — Registered. Your Member number is saved."

Find: "Sign the Agreement · Accept the Joint Venture Participation Agreement — your formal entry into the partnership."
Replace with: "Step 2 — Sign the Members' agreement. Read it, then tick to accept."

Find: "Complete payment · Your $4 joining contribution activates full vault access and confirms your Founding Partner status."
Replace with: "Step 3 — Pay $4. This activates your account."

Find: "Active Partner · Full governance, distribution, and stewardship rights. Your COG$ are live in the community pool."
Replace with: "Step 4 — You're in. You can vote, send Community COG$, and see your share."

Find: "Founding Phase — You are part of the first wave. These milestones are permanent."
Replace with: "You're a founding Member. Here are the steps to fully join."

## CHANGE H — Four Spokes section headings
Find: "The Four Spokes of the COG$ Circular Economy"
Replace with: "Why we're built like this (5 min read)"

Find: "NFT COG$ are the spoke of identity."
Replace with: "Membership tokens — one per Member, can't be sold or moved."

Find: "ASX COG$ are the spoke of equity."
Replace with: "Investment tokens — backed by shares we own in resource companies."

Find: "RWA COG$ are the spoke of resource."
Replace with: "Resource tokens — backed by minerals we've valued in the ground."

Find: "Community COG$ (CC) are the spoke of exchange."
Replace with: "Community tokens — for swapping help, services and gifts inside the community."

Find: "The Rim — The No-Fiat Rule"
Replace with: "Rule 1: you can't cash COG$ out for dollars."

Find: "The Road — The Real World"
Replace with: "Rule 2: every COG$ is backed by something real."

## CHANGE I — Truth Window heading
Find: "The Truth Window · Real-time Registry Information"
Replace with: "What the community holds, right now"

Find: "Live · Read only"
Replace with: "Live data — last updated [time]" (keep the dynamic timestamp)

Find: "Multi-signature requirement · Core vault operations require at least three board director authorisations."
Leave — this is correct governance information and should stay.

## CHANGE J — Activity log label
Find: "Member action log — Every action against your Membership — read-only and unalterable."
Replace with: "Your history. Everything you have done in your account, in order. Cannot be changed."

## CHANGE K — Mainspring references
Find any remaining user-facing "Mainspring" text not already covered.
Replace with: "Community work"

## VERIFICATION
1. div balance
2. script balance
3. </body> once, </html> once
4. No "Enter Independence Vault" in button text
5. No "Mainspring" in user-facing text
6. No "Action required — Joint Venture Agreement" in banner
7. No em-dashes in new user-facing strings
8. "Founding Phase" milestone text updated
9. No passive constructions in changed copy

## COMMIT
git add partners/index.html
git commit -m "fix(hub): copy pass — plain-English rewrites from hub audit section 21"
git checkout -b review/session-8 && git push origin review/session-8
