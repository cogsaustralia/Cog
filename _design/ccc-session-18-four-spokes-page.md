# CCC Session 18: Create about/four-spokes/index.html
# Pull main before starting: git pull --rebase origin main

## GROUND TRUTH RULES
- Read principles/index.html before writing a single line — use it as the page template
- Create about/four-spokes/ directory and about/four-spokes/index.html
- Verify div balance, no script blocks, </body> x1, </html> x1 after writing
- Zero AI tells — no em-dashes anywhere in the file, grade-9 plain English only
- Commit to review/session-18 — do NOT push to main

---

## BACKGROUND
Session-10 removed the Four Spokes essay from partners/index.html and replaced it with
a link card pointing to /about/four-spokes/. That page was never created. It returns 403.
This session creates it.

---

## PAGE PATTERN
Follow principles/index.html exactly for structure, CSS variables, fonts, and layout.
- max-width 680px wrap, padding 48px 24px 72px
- Wordmark "COG$ of Australia" links back to /
- No JavaScript, no coin spin, no auth gate, no topbar tabs
- Dark theme (--bg:#0a0804)

---

## CONTENT
Use this text exactly. Do not add words. Do not change words.
All em-dashes have been removed and language simplified to grade-9 level.

TITLE TAG: Why We Built It Like This | COG$ of Australia Foundation
META DESCRIPTION: The four-spoke structure behind COG$. Why membership, shares, resources and community exchange work together.

EYEBROW: From a Founding Member
H1: Why we built it like this
LEAD: A five-minute read about the structure behind COG$.

--- BODY ---

<p>A wheel is one of the oldest and most useful shapes in human history. It does not wear down what it rolls over. It turns, moves things forward, spreads the load evenly, and comes back to where it started. That is what the COG$ model is designed to do: not extract, not hoard, not collapse. Just turn. Steadily, together, over time.</p>

<p>At the centre of the wheel sits the hub. The hub is the Community Joint Venture itself. It is the fixed point that everything else rotates around. Without the community, there is no wheel. Without the wheel, the community has no way forward.</p>

<p>From the hub extend four spokes. Each one is different in what it carries and how it works. But a wheel with uneven spokes wobbles. A wheel with a missing spoke breaks under pressure. COG$ is built so that all four spokes are present, under tension, and carrying their share of the load. The strength of the whole depends on the strength of each.</p>

--- SPOKE CARDS (2x2 grid) ---

Card 1:
  Label: Spoke of identity
  Token: NFT COG$
  Body: Membership tokens: one per Member, cannot be sold or moved. This spoke gives every participant their name and their place in the wheel. Each NFT COG$ is minted once, when a member or business joins. It is permanently tied to that person. It cannot be passed on, copied, or cashed out, because identity cannot be passed on, copied, or sold. This spoke only grows when the community genuinely grows, one consenting person at a time. A community built on fake membership is not a community. It is a shell.

Card 2:
  Label: Spoke of equity
  Token: ASX COG$
  Body: Investment tokens: backed by shares we own in resource companies. This spoke connects the community to real, publicly listed resource companies. Each ASX COG$ is minted against a share in the community pool. It cannot exist without a real share behind it. The spoke grows only when the pool grows, through new company partnerships or new share contributions. It is tied to companies operating under real rules and public scrutiny. This spoke does not speculate. It reflects.

Card 3:
  Label: Spoke of resource
  Token: RWA COG$
  Body: Resource tokens: backed by minerals we have valued in the ground. This is the deepest spoke. It is not anchored in markets or memberships but in the earth itself. Not yet in circulation, each RWA COG$ will be tied to an independently verified resource: minerals or other natural assets valued before anything is extracted. The number of tokens is fixed at the point of valuation and cannot be changed. If the resource increases in value, existing tokens appreciate. The spoke does not get longer. The only way to extend it is to register additional verified resources. This is the spoke that makes inflation impossible by design, not by rule.

Card 4:
  Label: Spoke of exchange
  Token: Community COG$ (CC)
  Body: Community tokens: for swapping help, services and gifts inside the community. This is the most active spoke. It moves constantly with the daily life of the community. Community COG$ have no linked asset behind them. Their value comes entirely from what members agree they are worth through real exchanges of goods and services. Every Individual Member receives 1,000 CC on joining. Every Business Member receives 10,000 CC. Enough to start, not enough to dominate. Additional CC can only be created in exchange for real goods or services. This spoke grows in direct proportion to the productive activity of the community.

CLOSING LINE (italic, centred, after grid):
  Four spokes. One hub. One rim. One road.

--- BACK LINK (above footer) ---
<a href="/partners/">Back to Members Hub</a>

--- FOOTER ---
COG$ of Australia Foundation · ABN 91 341 497 529 · Drake Village NSW 2469 · Wahlubal Country, Bundjalung Nation

---

## SPOKE CARD CSS
Add to the page style block:
.spokes-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px;margin:32px 0 28px}
.spoke-card{background:var(--panel2);border:1px solid var(--gold-rim);border-radius:12px;padding:20px 22px}
.spoke-label{font-size:.67rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--gold-2);margin-bottom:4px}
.spoke-token{font-family:var(--serif);font-size:1rem;color:var(--gold-1);margin-bottom:12px;font-weight:500}
.spoke-body{font-size:.86rem;color:var(--text2);line-height:1.75}
.spoke-closing{font-family:var(--serif);font-size:1.1rem;color:var(--gold-1);text-align:center;margin:8px 0 40px;font-style:italic}
.spokes-grid .spoke-card:nth-child(1){border-left:3px solid rgba(110,168,216,.5)}
.spokes-grid .spoke-card:nth-child(2){border-left:3px solid rgba(240,209,138,.5)}
.spokes-grid .spoke-card:nth-child(3){border-left:3px solid rgba(120,196,120,.5)}
.spokes-grid .spoke-card:nth-child(4){border-left:3px solid rgba(196,140,220,.5)}
@media(max-width:600px){.spokes-grid{grid-template-columns:1fr}}

---

## VERIFICATION CHECKLIST
1. File exists at about/four-spokes/index.html
2. Div balance: open count = close count
3. </body> x 1, </html> x 1
4. Zero script blocks (static page, no JS)
5. Zero em-dashes anywhere in the file
6. Four spoke cards present
7. Wordmark links to /
8. Back link to /partners/ present
9. ABN in footer
10. No words from the content section changed or added

## COMMIT
git add about/four-spokes/index.html
git commit -m "feat(about): create four-spokes page — grade-9 plain English, no em-dashes"
git checkout -b review/session-18
git push origin review/session-18
