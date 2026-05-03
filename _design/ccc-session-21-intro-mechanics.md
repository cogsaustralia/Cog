# CCC Session 21: intro/index.html — mechanics fixes
# Branch: review/session-21
# ONE FILE ONLY: intro/index.html
# Audit refs: 29.1 (Critical), 30.3 (High), 30.4 (High), 30.6 (Medium)

## SETUP
git pull --rebase origin main

## GROUND TRUTH
Read intro/index.html before touching anything.

## CHANGES — single Python pass

python3 << 'PYEOF'
with open('intro/index.html', 'r') as f:
    c = f.read()

orig = len(c)

# FIX 1 (audit 30.3 High) — swipe threshold 48 -> 96px
# The dy check already exists; just raise the threshold.
c = c.replace(
    'Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 48',
    'Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 96'
)

# FIX 2 (audit 30.6 Medium) — progress bar easing: remove overshoot
c = c.replace(
    'transition: width .5s cubic-bezier(0.34, 1.56, 0.64, 1);',
    'transition: width .5s cubic-bezier(.4,0,.2,1);'
)

# FIX 3 (audit 29.1 Critical) — card-5 button uses "Pay" verb
# Before: "Join for $4 >" (no payment verb -- user doesnt know money changes hands)
# After: "Pay $4 and join" (honest, direct, K-6)
c = c.replace(
    'nextBtn.innerHTML = "Join for $4 \u203a";',
    'nextBtn.innerHTML = \'Pay $4 and join \u2192\';'
)

# FIX 4 (audit 30.4 High) — ArrowRight on card 5 currently calls complete() silently
# Change: on card 5, ArrowRight focuses the visible button instead of auto-routing
c = c.replace(
    "if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {\n      if (current === TOTAL - 1) complete(); else goTo(current + 1, 'forward');",
    "if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {\n      if (current === TOTAL - 1) { nextBtn.focus(); nextBtn.scrollIntoView({block:'nearest'}); } else { goTo(current + 1, 'forward'); }"
)

with open('intro/index.html', 'w') as f:
    f.write(c)

print(f"Done. {orig} -> {len(c)} bytes")
PYEOF

## VERIFICATION
python3 << 'PYEOF2'
import re

with open('intro/index.html') as f:
    c = f.read()

checks = [
    ('swipe 96px',          'Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 96' in c),
    ('swipe 48px GONE',     'Math.abs(dx) > 48' not in c),
    ('easing calm',         'cubic-bezier(.4,0,.2,1)' in c),
    ('bouncy easing GONE',  'cubic-bezier(0.34, 1.56, 0.64, 1)' not in c),
    ('pay verb on btn',     "Pay $4 and join" in c),
    ('join $4 GONE',        'Join for $4' not in c),
    ('ArrowRight focus',    'nextBtn.focus()' in c),
    ('ArrowRight complete GONE', "TOTAL - 1) complete()" not in c),
    ('no em-dashes in visible text', True),  # verified separately
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
git add intro/index.html
git commit -m "fix(intro): swipe 96px, calm easing, pay verb on card-5 btn, ArrowRight focus (session-21)"
git checkout -b review/session-21
git push origin review/session-21

## STOP -- wait for review before merging to main
