# CCC Session 28: admin/monitor.php — fix \! SyntaxErrors + content cleanup
# Branch: review/session-28
# ONE FILE ONLY: admin/monitor.php
# Critical: two JS SyntaxErrors prevent all data loading; page renders but all panels empty

## SETUP
git pull --rebase origin main

## GROUND TRUTH
Read admin/monitor.php before touching anything.

## CHANGES — single Python pass

python3 << 'PYEOF'
with open('admin/monitor.php', 'r') as f:
    c = f.read()

orig = len(c)

# ── CRITICAL FIX 1: JS SyntaxError — \!res.ok ──
c = c.replace(
    'if (\\!res.ok) return;',
    'if (!res.ok) return;'
)

# ── CRITICAL FIX 2: JS SyntaxError — \!== ──
c = c.replace(
    "leads.filter(l => l.converted \\!== 'not yet').length;",
    "leads.filter(l => l.converted !== 'not yet').length;"
)

# ── FIX 3: broken HTML comment \!-- ──
c = c.replace(
    '<\\!-- \u2500\u2500 CAMPAIGN LEADS PANEL \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500 -->',
    '<!-- CAMPAIGN LEADS PANEL -->'
)

# ── CONTENT: strip emoji from headings ──
c = c.replace('<h1>\U0001f680 COGS Monitoring Dashboard</h1>', '<h1>COGS Monitoring Dashboard</h1>')
c = c.replace('<h2 style="margin:0;">\U0001f9f2 Lead Captures \u2014 /seat/</h2>', '<h2 style="margin:0;">Lead Captures \u2013 /seat/</h2>')
c = c.replace('<h2>\U0001f4ca Current Metrics</h2>', '<h2>Current Metrics</h2>')
c = c.replace('<h2>\U0001f4c8 Trend Analysis</h2>', '<h2>Trend Analysis</h2>')
c = c.replace('<h2>\U0001f6a8 Recent Alerts (Last 7 Days)</h2>', '<h2>Recent Alerts (Last 7 Days)</h2>')
c = c.replace('<h2>\U0001f4cb JVPA Download Funnel', '<h2>JVPA Download Funnel')
c = c.replace('<h2>\U0001f3af Conversion Funnels', '<h2>Conversion Funnels')
c = c.replace('>\U0001f9f2 Cold path \u2014 /seat/<', '>Cold path \u2013 /seat/<')
c = c.replace('>\U0001f91d Warm path \u2014 invited<', '>Warm path \u2013 invited<')
c = c.replace('\u26a0\ufe0f DB tables not ready \u2014 run', 'DB tables not ready \u2013 run')

# ── CONTENT: strip box-drawing from CSS comment ──
c = c.replace(
    '        /* \u2500\u2500 JVPA Funnel panel \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500 */',
    '        /* JVPA Funnel panel */'
)

with open('admin/monitor.php', 'w') as f:
    f.write(c)

print(f"Done. {orig} -> {len(c)} bytes")
PYEOF

## VERIFICATION
python3 << 'PYEOF2'
import re

with open('admin/monitor.php') as f:
    c = f.read()

# JS block
m = re.search(r'<script\b[^>]*>(.*?)</script>', c, re.DOTALL)
js = m.group(1) if m else ''

# em-dash check on visible text
stripped = re.sub(r'<script[^>]*>.*?</script>', '', c, flags=re.DOTALL)
stripped = re.sub(r'<style[^>]*>.*?</style>', '', stripped, flags=re.DOTALL)
em = re.findall(r'>([^<]*\u2014[^<]*)<', stripped)

# High-unicode in JS
js_high = [(hex(ord(ch))) for ch in js if ord(ch) > 0x7f]
js_backslash_bang = '\!' in js

checks = [
    # Critical fixes
    ('\\!res.ok GONE from JS',         '\\!res.ok' not in js),
    ('\\!== GONE from JS',             '\\!==' not in js),
    ('!res.ok present in JS',          '!res.ok' in js),
    ('!== present in JS',              '!==' in js),
    ('broken HTML comment GONE',       '<\\!--' not in c),
    # Content
    ('rocket emoji GONE',              '\U0001f680' not in c),
    ('magnet emoji GONE',              '\U0001f9f2' not in c),
    ('chart emoji GONE',               '\U0001f4ca' not in c),
    ('trend emoji GONE',               '\U0001f4c8' not in c),
    ('siren emoji GONE',               '\U0001f6a8' not in c),
    ('clipboard emoji GONE',           '\U0001f4cb' not in c),
    ('target emoji GONE',              '\U0001f3af' not in c),
    ('handshake emoji GONE',           '\U0001f91d' not in c),
    ('warning emoji GONE',             '\u26a0' not in c),
    ('box-drawing GONE from CSS',      '\u2500' not in c),
    ('zero visible em-dashes',         len(em) == 0),
    # JS structure
    ('backtick count even',            js.count('`') % 2 == 0),
    ('brace balance',                  js.count('{') == js.count('}')),
    ('no backslash-bang in JS',        not js_backslash_bang),
    # HTML structure
    ('</style> == 1',  c.count('</style>') == 1),
    ('</head> == 1',   c.count('</head>')  == 1),
    ('<body == 1',     c.count('<body')    == 1),
    ('</body> == 1',   c.count('</body>')  == 1),
    ('</html> == 1',   c.count('</html>') == 1),
]

all_ok = True
for label, ok in checks:
    status = 'OK' if ok else 'FAIL'
    if not ok:
        all_ok = False
    print(f"[{status}] {label}")

if em:
    print(f"\nResidual em-dashes ({len(em)}):")
    for e in em:
        print(f"  {e[:80]}")

if js_high:
    print(f"\nHigh-unicode still in JS: {len(js_high)} chars")
    for h in list(dict.fromkeys(js_high)):
        print(f"  {h}")

print(f"\n{'ALL PASS' if all_ok else 'FAILURES ABOVE - DO NOT COMMIT'}")
PYEOF2

## COMMIT TO REVIEW BRANCH
git checkout -b review/session-28
git add admin/monitor.php
git commit -m "fix(monitor): fix JS SyntaxErrors \\!res.ok and \\!== + strip emoji/box-drawing (session-28)"
git push origin review/session-28

## STOP -- wait for review before merging to main
