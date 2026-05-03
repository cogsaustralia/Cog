# CCC Session 24: intro/voice-text.html — consent label rewrite
# Branch: review/session-24
# ONE FILE ONLY: intro/voice-text.html
# Audit refs: 33.3 (High) — consent is a wall of text with bundled clauses

## SETUP
git pull --rebase origin main

## GROUND TRUTH
Read intro/voice-text.html before touching anything.

## CHANGE — single Python pass

python3 << 'PYEOF'
with open('intro/voice-text.html', 'r') as f:
    c = f.read()

orig = len(c)

# FIX (audit 33.3 High) — consent label: plain English, single clear statement
# Old: legal wall of text with "state may be shown" default implication
# New: short plain statement + separate how-to-change line
c = c.replace(
    "I agree my submission can be used on COG$ social media as a member quote. My first name and state may be shown. I can withdraw consent at any time by emailing info@cogsaustralia.org or from my member dashboard.",
    "I'm OK with COG$ posting this on Facebook or YouTube. (Change your mind any time: email info@cogsaustralia.org or click 'My voice' in your account.)"
)

with open('intro/voice-text.html', 'w') as f:
    f.write(c)

print(f"Done. {orig} -> {len(c)} bytes")
PYEOF

## VERIFICATION
python3 << 'PYEOF2'
import re

with open('intro/voice-text.html') as f:
    c = f.read()

em_stripped = re.sub(r'<script[^>]*>.*?</script>', '', c, flags=re.DOTALL)
em_stripped = re.sub(r'<style[^>]*>.*?</style>', '', em_stripped, flags=re.DOTALL)
em_hits = re.findall(r'>([^<]*\u2014[^<]*)<', em_stripped)

checks = [
    ('old consent GONE',   'I agree my submission can be used' not in c),
    ('new consent present', "I'm OK with COG$ posting this" in c),
    ('state-may-be-shown GONE', 'state may be shown' not in c),
    ('em-dashes in visible text', len(em_hits) == 0),
    ('</style>',  c.count('</style>') == 1),
    ('</head>',   c.count('</head>')  == 1),
    ('<body',     c.count('<body')    >= 1),
    ('</html>',   c.count('</html>') == 1),
]

for label, ok in checks:
    print(f"[{'OK' if ok else 'FAIL'}] {label}")
PYEOF2

## COMMIT TO REVIEW BRANCH
git add intro/voice-text.html
git commit -m "fix(voice-text): plain-English consent label (session-24)"
git checkout -b review/session-24
git push origin review/session-24

## STOP -- wait for review before merging to main
