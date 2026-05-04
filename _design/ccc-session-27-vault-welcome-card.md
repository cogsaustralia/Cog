# CCC Session 27: wallets/member.html — first-run welcome card
# Branch: review/session-27
# ONE FILE ONLY: wallets/member.html
# Audit ref: 3.01 (Critical) — first-run welcome for new members

## SETUP
git pull --rebase origin main

## GROUND TRUTH
Read wallets/member.html before touching anything.

## CHANGES — single Python pass

python3 << 'PYEOF'
with open('wallets/member.html', 'r') as f:
    c = f.read()

orig = len(c)

# ── CHANGE 1: inject welcome card HTML immediately inside #vault, before .vault-shell ──
# Insertion point: the one line after <div id="vault" style="display:none;min-height:100vh">

WELCOME_HTML = """
<!-- ── First-run welcome card ── -->
<div id="welcome-card" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(10,8,4,.82);display:flex;align-items:center;justify-content:center;padding:20px" aria-modal="true" role="dialog" aria-labelledby="wc-heading">
  <div style="background:var(--panel,#1a1509);border:1px solid var(--gold-rim,rgba(240,209,138,.18));border-radius:14px;max-width:380px;width:100%;padding:32px 28px;text-align:center">
    <div style="font-size:1.5rem;font-weight:700;color:var(--gold-1,#f0d18a);margin-bottom:8px;font-family:var(--serif,'Playfair Display',Georgia,serif)" id="wc-heading">Welcome to COG$ of Australia.</div>
    <div style="font-size:1rem;color:var(--text2,rgba(255,248,232,.88));margin-bottom:20px;font-weight:500">You are now a founding member.</div>
    <div style="font-size:.88rem;color:var(--text2,rgba(255,248,232,.88));line-height:1.7;margin-bottom:28px">
      <p style="margin:0 0 8px">Foundation Day is 14 May 2026. Your first vote is waiting.</p>
      <p style="margin:0">Have a look around. Everything here is yours.</p>
    </div>
    <button onclick="dismissWelcomeCard()" style="background:var(--gold-2,#c9973d);color:#0a0804;border:none;border-radius:8px;padding:12px 32px;font-size:.95rem;font-weight:700;cursor:pointer;width:100%;font-family:var(--sans,'DM Sans',system-ui,sans-serif)">Got it</button>
  </div>
</div>
<!-- ── End first-run welcome card ── -->
"""

c = c.replace(
    '<div id="vault" style="display:none;min-height:100vh">\n<div class="vault-shell">',
    '<div id="vault" style="display:none;min-height:100vh">\n' + WELCOME_HTML + '\n<div class="vault-shell">'
)

# ── CHANGE 2: inject dismissWelcomeCard() and showWelcomeCard() into block 3 JS ──
# Anchor: insert just before the existing startWalletTour function definition

WELCOME_JS = """
/* ── First-run welcome card ── */
function showWelcomeCard() {
  if (localStorage.getItem('cogs_vault_welcomed')) return;
  var card = document.getElementById('welcome-card');
  if (card) card.style.display = 'flex';
}

function dismissWelcomeCard() {
  localStorage.setItem('cogs_vault_welcomed', '1');
  var card = document.getElementById('welcome-card');
  if (card) card.style.display = 'none';
  startWalletTour();
}
/* ── End first-run welcome card ── */

"""

c = c.replace(
    '/* VAULT TOUR — global scope */',
    WELCOME_JS + '/* VAULT TOUR — global scope */'
)

# ── CHANGE 3: call showWelcomeCard() after vault is revealed ──
# The one call site where #vault goes display:block is also where updateTourPill() is called.
# Add showWelcomeCard() on the next line after updateTourPill().
c = c.replace(
    "    document.getElementById('vault').style.display = 'block';\n    updateTourPill();",
    "    document.getElementById('vault').style.display = 'block';\n    updateTourPill();\n    showWelcomeCard();"
)

with open('wallets/member.html', 'w') as f:
    f.write(c)

print(f"Done. {orig} -> {len(c)} bytes")
PYEOF

## VERIFICATION
python3 << 'PYEOF2'
import re

with open('wallets/member.html') as f:
    c = f.read()

# em-dash check on visible text
stripped = re.sub(r'<script[^>]*>.*?</script>', '', c, flags=re.DOTALL)
stripped = re.sub(r'<style[^>]*>.*?</style>', '', stripped, flags=re.DOTALL)
em = re.findall(r'>([^<]*\u2014[^<]*)<', stripped)

checks = [
    # welcome card HTML present
    ('welcome-card div present',         'id="welcome-card"' in c),
    ('welcome heading present',          'Welcome to COG\$ of Australia.' in c),
    ('founding member line present',     'You are now a founding member.' in c),
    ('Foundation Day line present',      'Foundation Day is 14 May 2026. Your first vote is waiting.' in c),
    ('look around line present',         'Have a look around. Everything here is yours.' in c),
    ('Got it button present',            '>Got it</button>' in c),
    # JS functions present
    ('showWelcomeCard defined',          'function showWelcomeCard()' in c),
    ('dismissWelcomeCard defined',       'function dismissWelcomeCard()' in c),
    ('localStorage key correct',         "localStorage.getItem('cogs_vault_welcomed')" in c),
    ("localStorage setItem correct",     "localStorage.setItem('cogs_vault_welcomed', '1')" in c),
    ('startWalletTour called on dismiss','dismissWelcomeCard' in c and 'startWalletTour()' in c),
    # trigger wired up
    ('showWelcomeCard called after reveal', "updateTourPill();\n    showWelcomeCard();" in c),
    # no regressions
    ('startWalletTour still present',    'function startWalletTour()' in c),
    ('updateTourPill still present',     'function updateTourPill()' in c),
    ('zero visible em-dashes',          len(em) == 0),
    # structure
    ('</style>', c.count('</style>') == 1),
    ('</head>',  c.count('</head>')  == 1),
    ('<body',    c.count('<body')    == 1),
    ('</body>',  c.count('</body>')  == 1),
    ('</html>',  c.count('</html>') == 1),
]

for label, ok in checks:
    print(f"[{'OK' if ok else 'FAIL'}] {label}")
PYEOF2

## COMMIT TO REVIEW BRANCH
git add wallets/member.html
git commit -m "feat(vault): first-run welcome card with tour auto-trigger (session-27)"
git checkout -b review/session-27
git push origin review/session-27

## STOP -- wait for review before merging to main
