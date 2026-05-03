# UX Audit Remediation — Session 11: intro/index.html — Copy Pass
# Source: _design/audits/ux-audit-intro-2026-05-03.html (Section 36 — i-rewrites table)
# SCOPE: Display text changes only. Do NOT change IDs, class names, JS variables, API strings.
# Run AFTER sessions 4-7 (vault) and 8-10 (hub) are complete.
# Pull main before starting: git pull --rebase origin main

## GROUND TRUTH RULES
- Read exact current file state before every edit
- Stage only intro/index.html
- Python write only — never heredoc
- Verify div balance, script balance, </body>, </html> after all changes
- Zero AI tells — Grade 6 Australian plain English only

---

## CHANGE A — Card 1 heading and deck
Find: "A community that owns its say on Country."
Replace with: "A community that owns a real say on Australian land."

Find: "COG$ started in Drake Village, NSW, a gold country town where locals decided there was another way."
Replace with: "COG$ started in Drake Village, a small gold-mining town in NSW. The locals decided they wanted a real say in what happens to the land around them."

Find: "Instead of value being extracted from the land and flowing elsewhere, what if the community held a registered stake and a legal voice in what happens?"
Replace with: "Right now most of the money from mining flows out of the community. We thought: what if the community owned part of the company, and got a vote on what happens?"

Find: "A community joint venture. Not a fund, not an app, not a promise."
Replace with: "This is not a fund or an app. It is a group of Australians who own shares together and vote together — like a club, but with real shares."

Find: "A real shareholding in an ASX-listed resource company, owned collectively by members, governed by members, one equal vote each."
Replace with: "Members own real shares in an Australian mining company. Every member gets one vote. Same vote for everyone."

Find the pill/callout: "For every Australian who thinks the people living on the land should have a say in what happens to it."
Replace with: "The friend who sent you this link doesn't get paid if you join. There is no referral fee. Your $4 goes to the same place theirs did."

## CHANGE B — Card 1 price prominence
Find the fact-row pills. The price pill currently reads "Once-only $4 membership"
and appears as one of four equal pills.
Add a standalone price callout ABOVE the fact-row pills:
  <div class="price-callout" style="font-size:1rem;font-weight:700;color:var(--text);
    background:rgba(232,184,75,.1);border:1px solid var(--gold-rim);border-radius:10px;
    padding:10px 16px;margin-bottom:14px;text-align:center">
    It costs $4. Just once. We don't take it until you say yes on the next page.
  </div>

## CHANGE C — Card 2 rewrites
Find: "One member. One vote. No exceptions."
Replace with: "One member, one vote. Same as a footy club election. No exceptions."

Find: "Members vote online through your Independence Vault."
Replace with: "Members vote online from their COG$ account."

Find: "Every poll is binding."
Replace with: "Every vote counts — the result is what we go with."

Find: "The Trustee holds legal title but does not make the decisions."
Replace with: "There is a Trustee. Their legal job is the paperwork (signing, filing, holding the shares in the right name). They don't decide what we do. We do."

Find: "Wealth does not buy additional voice. People are the voice."
Replace with: "You can't buy more votes. Whether you have $4 or $4 million, you get one vote."

Find: "More people, more cogs, more weight behind the decisions that affect Country."
Replace with: "The more people who join, the louder the community's voice on what happens to the land."

Find: "Your vote carries the same legal weight as every other member's, whether you joined on day one or day one thousand."
Replace with: "What kinds of things do members vote on? Three examples: which mining proposals to support; how the Foundation spends its money; which companies the Foundation buys shares in next."

## CHANGE D — Card 3 rewrites
Find: "Real shares. Real say at the company table."
Replace with: "Real shares. A real seat at the company table."

Find: "Members collectively hold CHESS-registered shares in ASX-listed resource companies. CHESS is the national securities register, the same one every stockbroker in Australia uses."
Replace with: "The shares are real. They sit on the same official Australian share-register that every stockbroker uses (it's called CHESS)."

Find: "We are building toward a 1% holding in Legacy Minerals Holdings Limited (ASX:LGM)."
Replace with: "We are working toward owning 1% of an Australian mining company together. When you join, your account will show you which company and how much."

Find: "At 1%, we can formally attend AGMs, lodge resolutions, and vote on company decisions affecting Country."
Replace with: "At 1% we can show up at the company's yearly meeting and ask them to vote on things — like should we mine here? or how much should we spend on cleaning up?"

Find: "Our view: minerals left in the ground may be worth more than minerals dug up."
Replace with: "Many members believe minerals can be more valuable to a community when they stay in the ground than when they are dug up. That is a community view, not financial advice."

Find: "Not a fund. Not a promise. A registered shareholding and a community member legal voice on extraction and valuation."
Replace with: "This is not a fund. Not a promise. It is a real share, held in members' names together, with a real legal voice on what gets dug up and how much it's said to be worth."

## CHANGE E — Card 4 rewrites
Find: "You join as an operator. Real protections built in."
Replace with: "You join as a member, not a customer. Here are four real protections built in."

Find all instances of: "soulbound" or "Soulbound"
Replace: "Your membership cannot be taken from you. It is soulbound."
      → "Your membership cannot be taken from you. It is yours and only yours."

Find: "The system cannot be financialised."
Replace with: "Nobody can buy the votes. The tokens cannot be sold for money."

Find: "Foundation Day" first occurrence in card copy
Add after it: "(the first national vote — 14 May 2026)"

Find: "Expansion Day" first occurrence in card copy
Add after it: "(the day COGS is approved by ASIC — date TBC)"

## CHANGE F — Card 5 button label
Find the final card button label: "Secure my place. $4 once only ›"
Replace with: "Join for $4 ›"
Add a sub-label below the button: "Once only. No subscription. Pay on the next page."

## CHANGE G — Card 5 milestone copy
Find: "Founding Phase — You are part of the first wave."
Replace with: "You are a founding member."

Find: "Active Partner · Full governance, distribution, and stewardship rights."
Replace with: "You are in. You can vote and see your share."

## CHANGE H — Voice welcome page integration
Find in intro/voice-welcome.html any text saying:
"In one sentence — why did you join?"
Add above it: "This is optional. Skip it if you're not ready — you can always add your voice later from your COG$ account."
Add a clearly visible Skip link: <a href="../partners/?welcome=1">Skip for now ›</a>

## VERIFICATION
1. div balance (intro/index.html)
2. script balance
3. </body> once, </html> once
4. No "soulbound" in user-facing text
5. No "financialised" in user-facing text
6. No "Independence Vault" in user-facing text
7. Price callout present in Card 1
8. "Join for $4 ›" button label on Card 5
9. No em-dashes in new user-facing strings

## COMMIT
git add intro/index.html intro/voice-welcome.html
git commit -m "fix(intro): copy pass — K6 rewrites, price prominence, soulbound removed, financial claim softened"
git checkout -b review/session-11 && git push origin review/session-11
