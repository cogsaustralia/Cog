# CCC Session 25: seat + join + thank-you — em-dashes, ABN, vault language
# Branch: review/session-25
# FILES: seat/index.html · join/index.html · thank-you/index.html
# Audit refs: 1.1 (HIGH), RULE em-dash, MED vault language

## SETUP
git pull --rebase origin main

## GROUND TRUTH
Read all three files before touching anything.

## CHANGES

python3 << 'PYEOF'
# ── seat/index.html ──────────────────────────────────────────────────────────
with open('seat/index.html', 'r') as f: c = f.read()
orig = len(c)

# FIX 1 (audit 1.1 HIGH) — add entity name + ABN to header
# Adds a one-line identity proof visible above the fold — no logo file needed
c = c.replace(
    '<a href="/seat/" class="hdr-mark" aria-label="COG$ of Australia Foundation">COG$</a>\n  </header>',
    '<a href="/seat/" class="hdr-mark" aria-label="COG$ of Australia Foundation">COG$</a>\n    <span style="font-size:.72rem;letter-spacing:.06em;color:var(--muted);font-family:var(--sans)">COG$ of Australia Foundation &middot; ABN 91 341 497 529</span>\n  </header>'
)

# FIX 2 (RULE) — title em-dash
c = c.replace(
    '<title>Get a Seat at the Table \u2014 COG$ of Australia Foundation</title>',
    '<title>Get a Seat at the Table | COG$ of Australia Foundation</title>'
)

with open('seat/index.html', 'w') as f: f.write(c)
print(f"seat: {orig} -> {len(c)}")

# ── join/index.html ───────────────────────────────────────────────────────────
with open('join/index.html', 'r') as f: c = f.read()
orig = len(c)

# FIX 3 (RULE) — 4 standalone em-dash placeholders -> en-dash
# All 4 are empty-state spans/strongs confirmed unique by element ID
c = c.replace('<strong id="cs-name">\u2014</strong>', '<strong id="cs-name">\u2013</strong>')
c = c.replace('<span id="cs-contact">\u2014</span>', '<span id="cs-contact">\u2013</span>')
# The other two are DOB and mobile display spans — identified by surrounding style
c = c.replace('opacity:.7">\u2014</span>', 'opacity:.7">\u2013</span>')
c = c.replace('color:var(--text2)">\u2014</span>', 'color:var(--text2)">\u2013</span>')

with open('join/index.html', 'w') as f: f.write(c)
print(f"join: {orig} -> {len(c)}")

# ── thank-you/index.html ──────────────────────────────────────────────────────
with open('thank-you/index.html', 'r') as f: c = f.read()
orig = len(c)

# FIX 4 (MED) — vault language -> account
c = c.replace('set up your Independence Vault to access governance',
              'set up your account to access governance')
c = c.replace(
    'Your unique invite link will be in your vault. Share it with 4 Australians after you set up your vault.',
    'Your unique invite link will be in your account. Pass it to one Australian after you set up your account.'
)
c = c.replace('shown in your vault payment options', 'shown in your account payment options')
c = c.replace('<div class="vs-kicker">Before you open your vault</div>',
              '<div class="vs-kicker">Before you open your account</div>')
c = c.replace('Your vault updates after payment arrives.', 'Your account updates after payment arrives.')

# FIX 5 (RULE) — em-dashes in copy
c = c.replace('Pass the coin \u2014 your link is almost ready',
              'Pass the coin: your link is almost ready')
c = c.replace('Pay $4 by bank transfer \u2014 no fee',
              'Pay $4 by bank transfer (no fee)')
c = c.replace('$4.40 \u2014 processed securely via Stripe',
              '$4.40 via Stripe, processed securely')
c = c.replace('In one sentence \u2014 why did you join?',
              'In one sentence: why did you join?')
c = c.replace('Governance Foundation Day \u2014 14 May 2026',
              'Governance Foundation Day: 14 May 2026')
c = c.replace(
    'The first Members Poll of the Joint Venture \u2014 the first live test of the cryptographic governance system.',
    'The first Members Poll of the Joint Venture. The first live test of the governance system.'
)
c = c.replace(
    'The full constitutional statement of the Foundation \u2014 written in plain language for community reading.',
    'The full constitutional statement of the Foundation, written in plain language for community reading.'
)
c = c.replace(
    'Governance, stewardship, the vault, tokens \u2014 answers to the most common questions about how COG$ works.',
    'Governance, stewardship, your account, tokens: answers to the most common questions about how COG$ works.'
)

# FIX 6 (RULE) — 3 standalone em-dash placeholders -> en-dash (member name, link, mobile)
c = c.replace('data-member-name style="color:var(--gold-1)">\u2014</strong>',
              'data-member-name style="color:var(--gold-1)">\u2013</strong>')
c = c.replace('style="color:var(--text2);font-size:.82rem;word-break:break-all">\u2014</strong>',
              'style="color:var(--text2);font-size:.82rem;word-break:break-all">\u2013</strong>')
c = c.replace('id="member-mobile" style="color:var(--text2)">\u2014</strong>',
              'id="member-mobile" style="color:var(--text2)">\u2013</strong>')

with open('thank-you/index.html', 'w') as f: f.write(c)
print(f"thank-you: {orig} -> {len(c)}")
PYEOF

## VERIFICATION
python3 << 'PYEOF2'
import re

def em_visible(path):
    with open(path) as f: c = f.read()
    s = re.sub(r'<script[^>]*>.*?</script>', '', c, flags=re.DOTALL)
    s = re.sub(r'<style[^>]*>.*?</style>', '', s, flags=re.DOTALL)
    return re.findall(r'>([^<]*\u2014[^<]*)<', s), c

seat_em, seat = em_visible('seat/index.html')
join_em, join = em_visible('join/index.html')
ty_em, ty = em_visible('thank-you/index.html')

checks = [
    # seat
    ('seat: ABN present',           'ABN 91 341 497 529' in seat),
    ('seat: entity name present',   'COG$ of Australia Foundation' in seat),
    ('seat: title em-dash gone',    '\u2014 COG$ of Australia Foundation</title>' not in seat),
    ('seat: zero visible em-dashes', len(seat_em) == 0),
    # join
    ('join: zero visible em-dashes', len(join_em) == 0),
    # thank-you
    ('ty: Independence Vault gone',  'Independence Vault' not in ty),
    ('ty: 4 Australians gone',       '4 Australians' not in ty),
    ('ty: vault -> account',         'your account' in ty),
    ('ty: Pass the coin copy',       'Pass the coin: your link' in ty),
    ('ty: bank transfer copy',       'bank transfer (no fee)' in ty),
    ('ty: cryptographic gone',       'cryptographic governance system' not in ty),
    ('ty: zero visible em-dashes',   len(ty_em) == 0),
    # structure
    ('seat </html>', seat.count('</html>') == 1),
    ('join </html>', join.count('</html>') == 1),
    ('ty </html>',   ty.count('</html>') == 1),
]
for label, ok in checks:
    print(f"[{'OK' if ok else 'FAIL'}] {label}")
PYEOF2

## COMMIT TO REVIEW BRANCH
git add seat/index.html join/index.html thank-you/index.html
git commit -m "fix(cold-funnel): ABN above fold, vault->account, em-dashes seat+join+ty (session-25)"
git checkout -b review/session-25
git push origin review/session-25

## STOP -- wait for review
