# CCC Session 28b: _app/api/routes/admin.php — fix \\!empty PHP parse error
# Branch: review/session-28b
# ONE FILE ONLY: _app/api/routes/admin.php
# Run AFTER session-28 is merged. Deploy order: PHP before HTML/JS.

## SETUP
git pull --rebase origin main

## GROUND TRUTH
Read _app/api/routes/admin.php before touching anything.

## CHANGES — single Python pass

python3 << 'PYEOF'
with open('_app/api/routes/admin.php', 'r') as f:
    c = f.read()

orig = len(c)

# ── FIX: \\!empty() → !empty() ──
# This is a PHP parse error: \\ before ! is invalid PHP operator syntax
c = c.replace(
    "$lead['converted'] = \\!empty($lead['converted_to_member_id']) ? '\u2713 joined' : 'not yet';",
    "$lead['converted'] = !empty($lead['converted_to_member_id']) ? 'joined' : 'not yet';"
)

with open('_app/api/routes/admin.php', 'w') as f:
    f.write(c)

print(f"Done. {orig} -> {len(c)} bytes")
PYEOF

## VERIFICATION
python3 << 'PYEOF2'
with open('_app/api/routes/admin.php') as f:
    c = f.read()

checks = [
    ('\\\\!empty GONE',                  '\\!empty' not in c),
    ('!empty present',                   "= !empty($lead['converted_to_member_id'])" in c),
    ('converted logic intact',           "'joined' : 'not yet'" in c),
    ('adminVisitFunnel still present',   'function adminVisitFunnel' in c),
    ('adminJvpaFunnel still present',    'function adminJvpaFunnel' in c),
    ('requireAdminRole still present',   'requireAdminRole' in c),
    ('apiSuccess still present',         'apiSuccess' in c),
]

all_ok = True
for label, ok in checks:
    status = 'OK' if ok else 'FAIL'
    if not ok:
        all_ok = False
    print(f"[{status}] {label}")

print(f"\n{'ALL PASS' if all_ok else 'FAILURES ABOVE - DO NOT COMMIT'}")
PYEOF2

## COMMIT TO REVIEW BRANCH
git checkout -b review/session-28b
git add _app/api/routes/admin.php
git commit -m "fix(admin): fix \\\\!empty PHP parse error in adminVisitFunnel (session-28b)"
git push origin review/session-28b

## STOP -- wait for review before merging to main
