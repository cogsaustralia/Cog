# CCC Session 22: wallets/member.html — Give tab ASIC-risk language
# Branch: review/session-22
# ONE FILE ONLY: wallets/member.html
# Audit refs: 9.01 (Critical), 9.02 (Critical) — investment language in donation flow

## SETUP
git pull --rebase origin main

## GROUND TRUTH
Read wallets/member.html before touching anything.

## CHANGES — single Python pass

python3 << 'PYEOF'
with open('wallets/member.html', 'r') as f:
    c = f.read()

orig = len(c)

# FIX 1 (audit 9.02 Critical) — info popup body: remove "permanent Donation Dividend Stream"
# The phrase "generating a permanent Donation Dividend Stream for Sub-Trust C"
# is securities-marketing language inside a donation flow. Replace with plain description.
c = c.replace(
    '$2 is invested through Sub-Trust A into the Members Asset Pool, generating a permanent Donation Dividend Stream for Sub-Trust C.',
    '$2 goes to Sub-Trust C (the Community Projects Fund) and is held there to generate income for those projects over time.'
)

# FIX 2 (audit 9.02 Critical) — Donation COG$ data-tip: remove "income unit" / "perpetuity"
# Old tooltip has "Sub-Trust C holds a D Class income unit for each token issued,
# a share of future distributions in perpetuity."
c = c.replace(
    'Sub-Trust C holds a D Class income unit for each token issued, a share of future distributions in perpetuity.',
    'Your Donation COG$ is a permanent receipt for your contribution. It cannot be sold or transferred.'
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

checks = [
    ('dividend stream GONE',  'Donation Dividend Stream' not in c),
    ('income unit GONE',      'D Class income unit' not in c),
    ('in perpetuity GONE',    'in perpetuity' not in c),
    ('replacement present',   'Community Projects Fund' in c and 'held there to generate income' in c),
    ('receipt replacement',   'permanent receipt for your contribution' in c),
    ('</style>',  c.count('</style>') == 1),
    ('</head>',   c.count('</head>')  == 1),
    ('<body',     c.count('<body')    == 1),
    ('</body>',   c.count('</body>')  == 1),
    ('</html>',   c.count('</html>') == 1),
]

for label, ok in checks:
    print(f"[{'OK' if ok else 'FAIL'}] {label}")
PYEOF2

## COMMIT TO REVIEW BRANCH
git add wallets/member.html
git commit -m "fix(vault): remove ASIC-risk dividend stream language from Give tab (session-22)"
git checkout -b review/session-22
git push origin review/session-22

## STOP -- wait for review before merging to main
