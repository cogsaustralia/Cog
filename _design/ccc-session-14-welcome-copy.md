# UX Audit Remediation — Session 14: welcome/index.html — Copy Pass
# Source: _design/audits/ux-audit-welcome-2026-05-03.html (Section 50 rewrites table)
# SCOPE: Display text changes only. Do NOT change IDs, class names, JS variables, API strings.
# Run AFTER sessions 7-13 are complete.
# Pull main before starting: git pull --rebase origin main

## GROUND TRUTH RULES
- Read exact current file state before every edit
- Stage only welcome/index.html
- Python write only — never heredoc
- Verify div balance, script balance, </body>, </html> after all changes
- Zero AI tells — Grade 6 Australian plain English only

---

## CHANGE A — Brand subtitle (finding 41.1)
Find: "Community Owned Gold &amp; Silver&#x2122;" or "Community Owned Gold & Silver™"
Replace with: "Member-owned community joint venture · Drake Village NSW"
Remove the ™ symbol entirely.

## CHANGE B — Nav links (finding 41.5)
Find nav link "About" — change label to "What is COG$"
Find nav link "FAQ" — change label to "Common questions"
Keep href unchanged for both. Keep "Join" unchanged.

## CHANGE C — Voice eyebrow (finding 41.3)
Find: "Your Fair Say"
Replace with: "Welcome — first time here?"

## CHANGE D — Voice headline (finding 41.2)
Find: "What does a &#x2018;Fair go&#x2019; and a &#x2018;fair say&#x2019; mean for you?"
or the decoded version with curly quotes around 'Fair go' and 'fair say'
Replace with: "What would a fair say for everyday Australians look like to you?"

## CHANGE E — Voice sub copy (finding 40.4)
Find: "Tell us in your words. No account needed. No commitment required."
Replace with: "Tell us in your own words — it's optional. After you submit, we'll show you how to join for $4 if you'd like to."

## CHANGE F — Textarea placeholder (finding 42.7)
Find the textarea placeholder: "Type your thoughts here…" or similar
Replace with: "e.g. A fair say means everyone has the same vote, no matter their bank balance."

## CHANGE G — Submit button label (finding 43.1 / 48.x)
Find: "Share your voice →" or "Share your voice &#x2192;"
Replace with: "Send my message →"

## CHANGE H — Post-submission confirmation (finding 43.3)
Find: "✓ Your voice is in the queue" or the tick + "Your voice is in the queue"
Replace with: "Got it — we'll review your message before anything goes public."

## CHANGE I — Relay CTA copy (findings 44.1, 44.2, 43.2)
Find: "The relay starts when 4 Australians like you join. Will you be one?"
Replace with: "Three more Australians need to join before this group begins voting. Want to be one of the first?"

## CHANGE J — Join button label (finding 43.1)
Find: "Join the Foundation — $4 once only →" or "Join the Foundation &#x2014; $4 once only &#x2192;"
Replace with: "Join for $4 →"

## CHANGE K — Price reassurance below join button (finding 43.4)
Find: "One payment. No subscriptions. No lock-in."
Replace with: "One $4 payment. That's the whole price."

## CHANGE L — Countdown eyebrow (finding 45.1)
Find: "Governance Foundation Day" (in countdown section heading/eyebrow only)
Replace with: "Foundation Day · the community's first national vote"

## CHANGE M — Countdown post-expiry state (finding 45.3)
Find the expired countdown copy: "Governance Foundation Day — 14 May 2026" or similar static post-FD label
Replace with: "Foundation Day was 14 May 2026. The first vote is done — but new members are welcome any time, and the next vote is on the way."

## CHANGE N — Social proof section heading (finding 46.x)
Find: "Voices from the community"
Replace with: "What other members have said"

Find: "Loading voices…" (placeholder copy)
Replace with: "Loading messages from the community…"

Find empty state: "Be among the first to share your voice."
Replace with: "Quiet here at the moment — that just means there's room for you."

## CHANGE O — Joiner ticker (findings 46.3, 46.4)
Find section heading: "Joining now"
Replace with: "Recent welcomes"

Find empty/fallback state: "Members are joining — be next."
Replace with: "No new members in the last hour. Yours could be the next."

Find "Checking recent activity…" placeholder
Replace with: "Looking up new members…"

## CHANGE P — Country card (findings 47.2, 47.3)
Find card title: "Founding partners on Country"
Replace with: "Where the Foundation sits"

Find card body text about Drake Village Resource Centre
Change to: "The Foundation is based at the Drake Village Resource Centre, in Drake Village, NSW. The land is Wahlubal Country, part of the Bundjalung Nation."

## CHANGE Q — Foundation Day timezone comment (finding 45.4)
Find: Date.UTC(2026,4,14,7,0,0) or similar UTC calculation
Add inline comment after it: // 14 May 2026, 5pm AEST = 7am UTC (May is AEST, not AEDT)

## VERIFICATION
1. div balance
2. script balance
3. </body> once, </html> once
4. "Community Owned Gold" gone
5. "Your Fair Say" eyebrow gone
6. "Share your voice" button gone
7. "The relay starts when 4 Australians like you join" gone
8. "Governance Foundation Day" heading updated
9. No em-dashes in new user-facing strings
10. Grade 6 Australian language throughout

## COMMIT
git add welcome/index.html
git commit -m "fix(welcome): copy pass — K6 rewrites, relay copy, brand subtitle, social proof labels"
git checkout -b review/session-14 && git push origin review/session-14
