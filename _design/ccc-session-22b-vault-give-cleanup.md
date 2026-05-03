# CCC Session 22b: wallets/member.html — remove remaining D Class income unit reference
# Branch: review/session-22b
# ONE FILE ONLY: wallets/member.html
# One string. Merges straight to main after verification.

## SETUP
git pull --rebase origin main

## GROUND TRUTH
Read wallets/member.html before touching anything.

## CHANGE

python3 << 'PYEOF'
with open('wallets/member.html', 'r') as f:
    c = f.read()

orig = len(c)

# Remove the D Class income unit bullet from the donation-pif info popup.
# Sub-Trust B income unit references throughout the file are correct and stay.
# Only this Sub-Trust C / D Class reference carries ASIC-risk language.
c = c.replace(
    'Sub-Trust C holds a D Class income unit for each Donation COG$ issued \u2014 a permanent share of future distributions',
    'Your Donation COG$ is a permanent receipt recorded in the Foundation ledger'
)

with open('wallets/member.html', 'w') as f:
    f.write(c)

print(f"Done. {orig} -> {len(c)} bytes")
PYEOF

## VERIFICATION
python3 << 'PYEOF2'
with open('wallets/member.html') as f:
    c = f.read()

checks = [
    ('D Class income unit GONE', 'D Class income unit' not in c),
    ('replacement present', 'permanent receipt recorded in the Foundation ledger' in c),
    ('Sub-Trust B income unit intact', c.count('Sub-Trust B income unit') >= 5),
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
git commit -m "fix(vault): remove D Class income unit from donation popup (session-22b)"
git checkout -b review/session-22b
git push origin review/session-22b

## STOP -- wait for review before merging to main
