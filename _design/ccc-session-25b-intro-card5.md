# CCC Session 25b: intro/index.html — card-5 callout cooling-off guarantee
# Branch: review/session-25b
# ONE FILE ONLY: intro/index.html
# Audit ref: 29.5 (MED) — card-5 callout echoes heading instead of offering reassurance

## SETUP
git pull --rebase origin main

## GROUND TRUTH
Read intro/index.html before touching anything.

## CHANGE

python3 << 'PYEOF'
with open('intro/index.html', 'r') as f: c = f.read()
orig = len(c)

# FIX (audit 29.5 MED) — replace echo callout with cooling-off guarantee
# Current: repeats "your position before both milestones" (same as heading)
# New: gives a refund guarantee — the single most trust-building thing on this card
c = c.replace(
    '<p>Joining now records your position before both milestones. Every member who joins before Foundation Day (the first national vote - 14 May 2026) votes on the first governance decision.</p>',
    '<p>You can change your mind. Email admin@cogsaustralia.org within 14 days and we will refund the $4 with no questions.</p>'
)

with open('intro/index.html', 'w') as f: f.write(c)
print(f"Done. {orig} -> {len(c)}")
PYEOF

## VERIFICATION
python3 << 'PYEOF2'
import re

with open('intro/index.html') as f: c = f.read()

s = re.sub(r'<script[^>]*>.*?</script>', '', c, flags=re.DOTALL)
s = re.sub(r'<style[^>]*>.*?</style>', '', s, flags=re.DOTALL)
em = re.findall(r'>([^<]*\u2014[^<]*)<', s)

checks = [
    ('old callout gone',       'Joining now records your position before both milestones' not in c),
    ('refund guarantee present', 'refund the $4 with no questions' in c),
    ('admin email present',    'admin@cogsaustralia.org' in c),
    ('pay verb intact',        'Pay $4 and join' in c),
    ('swipe 96px intact',      'Math.abs(dx) > 96' in c),
    ('zero visible em-dashes', len(em) == 0),
    ('</style>', c.count('</style>') == 1),
    ('</head>',  c.count('</head>')  == 1),
    ('</html>',  c.count('</html>') == 1),
]
for label, ok in checks:
    print(f"[{'OK' if ok else 'FAIL'}] {label}")
PYEOF2

## COMMIT TO REVIEW BRANCH
git add intro/index.html
git commit -m "fix(intro): card-5 callout -> cooling-off guarantee (session-25b)"
git checkout -b review/session-25b
git push origin review/session-25b

## STOP -- wait for review
