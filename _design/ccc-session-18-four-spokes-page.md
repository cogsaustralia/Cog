# CCC Session 18: Create about/four-spokes/index.html
# Pull main before starting: git pull --rebase origin main

## GROUND TRUTH RULES
- Read partners/index.html and principles/index.html before writing a single line
- Create about/four-spokes/ directory and index.html
- Verify div balance, script balance, </body>, </html> after writing
- Zero AI tells — no em-dashes in user-visible text
- Commit to review/session-18 — do NOT push to main

---

## BACKGROUND
Session-10 removed the Four Spokes essay from partners/index.html and replaced it with
a link card pointing to /about/four-spokes/. That page was never created. The URL returns
403. This session creates the page.

The page pattern must match principles/index.html and the-fifty-years/index.html:
- Same CSS variables and dark theme
- Wordmark (COG$ of Australia) as the only nav, links back to /
- max-width:680px wrap, padding:48px 24px 72px
- No coin spin, no auth gate, no topbar nav tabs
- Simple footer with ABN and legal notice

---

## EXACT CONTENT TO USE
Use this text verbatim. Do not change any words.

EYEBROW: From a Founding Member
H1: Why we're built like this
LEAD: A 5-minute read.

BODY PARAGRAPHS (use exactly):

<p>A wheel is one of humanity's oldest and most elegant solutions. It doesn't consume what it moves across — it simply turns, converting energy into forward motion, distributing force evenly, and returning to the same point it started from before moving on. That is exactly what the COG$ circular economy is designed to do: not extract, not accumulate, not collapse inward — but turn. Continuously, sustainably, and together.</p>

<p>At the centre of the wheel sits the hub, the Community Joint Venture itself. It is the fixed point around which everything rotates, the anchor that holds the spokes in tension and gives the wheel its form. Without the community, there is no wheel. Without the wheel, the community goes nowhere.</p>

<p>From that hub extend four spokes. Each one is distinct in its material, its purpose, and the load it carries. But a wheel with uneven spokes wobbles. A wheel with a missing spoke buckles under pressure. The COG$ model is designed so that all four spokes are present, tensioned, and load-bearing. The integrity of the whole depends on the integrity of each.</p>

FOUR SPOKE CARDS (2x2 grid):

Card 1:
  Label: Spoke of identity
  Token: NFT COG$
  Body: <strong>Membership tokens: one per Member, cannot be sold or moved.</strong> This is the spoke that names every participant and gives them their place in the wheel. Minted once, at the moment an individual member or business joins the community, each NFT COG$ is permanently and uniquely bound to that participant — their signature on the wheel, their seat at the table. It cannot be transferred, replicated, or cashed out, because identity cannot be transferred, replicated, or sold. This spoke does not stretch to accommodate demand. It grows only as the community genuinely grows — one consenting participant at a time — because a community built on manufactured membership is not a community at all. It is a rim without a hub.

Card 2:
  Label: Spoke of equity
  Token: ASX COG$
  Body: <strong>Investment tokens: backed by shares we own in resource companies.</strong> This is the spoke that connects the wheel to the broader economy of real, publicly accountable resource companies. Minted against a pooled holding of shares in ASX-listed resource companies, every ASX COG$ represents a proportional stake in that pool — and it cannot exist without a corresponding share underpinning it. The spoke lengthens only when the pool grows, through new company joint ventures or additional share contributions. It is market-anchored and transparent by design, tied directly to the performance of real companies operating under real regulatory scrutiny. This spoke does not speculate. It reflects.

Card 3:
  Label: Spoke of resource
  Token: RWA COG$
  Body: <strong>Resource tokens: backed by minerals we have valued in the ground.</strong> This is the deepest spoke — reaching furthest from the hub, anchored not in markets or memberships but in the earth itself. Not yet in circulation, each RWA COG$ will be minted via smart contract and tied to an independently verified, in-situ resource: minerals, biological assets, or other natural assets valued before a single gram is extracted. The minting quantity is fixed at the point of independent valuation and cannot be revisited. If the underlying resource increases in value, existing tokens appreciate — the spoke carries more weight, but it does not grow longer. The only way to extend this spoke is to register additional verified resources. This is the spoke that makes inflation structurally impossible — not by rule, but by architecture.

Card 4:
  Label: Spoke of exchange
  Token: Community COG$ (CC)
  Body: <strong>Community tokens: for swapping help, services and gifts inside the community.</strong> This is the most active spoke — the one in constant motion, flexing and turning with the daily life of the community. Carrying no directly linked underlying asset, Community COG$ derive their value entirely from what members agree they are worth through the exchange of real goods and services. Every Individual Member receives 1,000 CC upon entry; every Business Member receives 10,000 CC — enough to begin participating, not enough to dominate. Additional CC may be minted and issued only in exchange for genuine goods or services. This spoke grows in direct proportion to the productive activity of the community itself.

CLOSING LINE (after grid):
  Four spokes. One hub. One rim. One road.

---

## PAGE STRUCTURE

Follow principles/index.html exactly for:
- DOCTYPE, head, meta, fonts, CSS variables
- .wrap max-width:680px
- .wordmark link back to /
- .eyebrow / h1 / .lead pattern

Add CSS for the spoke grid and cards, matching the style from partners/index.html:
- .spokes-grid: display:grid; grid-template-columns:repeat(2,1fr); gap:16px; margin:32px 0 24px
- .spoke-card: background:var(--panel2); border:1px solid var(--gold-rim); border-radius:12px; padding:20px 22px
- .spoke-label: font-size:.67rem; font-weight:700; letter-spacing:.14em; text-transform:uppercase; color:var(--gold-2); margin-bottom:4px
- .spoke-token: font-family:var(--serif); font-size:1rem; color:var(--gold-1); margin-bottom:12px; font-weight:500
- .spoke-body: font-size:.86rem; color:var(--text2); line-height:1.75
- .spoke-closing: font-family:var(--serif); font-size:1.1rem; color:var(--gold-1); text-align:center; margin:8px 0 40px; font-style:italic
- @media(max-width:600px): .spokes-grid grid-template-columns:1fr

Add left border accents matching partners/index.html:
- .spokes-grid .spoke-card:nth-child(1){border-left:3px solid rgba(110,168,216,.5)}
- .spokes-grid .spoke-card:nth-child(2){border-left:3px solid rgba(240,209,138,.5)}
- .spokes-grid .spoke-card:nth-child(3){border-left:3px solid rgba(120,196,120,.5)}
- .spokes-grid .spoke-card:nth-child(4){border-left:3px solid rgba(196,140,220,.5)}

FOOTER:
  <p class="notice">COG$ of Australia Foundation · ABN 91 341 497 529 · Drake Village NSW 2469 · Wahlubal Country, Bundjalung Nation</p>

BACK LINK above footer:
  <a href="/partners/" class="wordmark" style="font-size:.82rem;margin-bottom:0;margin-top:32px;display:inline-block">← Back to Members Hub</a>

NO JavaScript required on this page. Static content only.

---

## DIRECTORY
Create the directory: about/four-spokes/
Create the file: about/four-spokes/index.html

---

## VERIFICATION CHECKLIST
1. File exists at about/four-spokes/index.html
2. Div balance: open = close
3. </body> x 1, </html> x 1
4. No em-dashes in user-visible text (NOTE: the original essay contains em-dashes in the
   spoke body text — these are original author text, preserve them as-is)
5. No script blocks (static page — no JS needed)
6. Four spoke cards present
7. Wordmark links to /
8. Back link to /partners/ present
9. ABN in footer

## COMMIT
git add about/four-spokes/index.html
git commit -m "feat(about): create four-spokes page with original essay content"
git checkout -b review/session-18
git push origin review/session-18
