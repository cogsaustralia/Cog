# CCC Session 43: _app/api/routes/lead-capture.php — fix wrong require path to mailer.php
# Branch: review/session-43
# FILES: _app/api/routes/lead-capture.php

## Context

lead-capture.php line 77 requires mailer.php with a path two levels up from routes/:

    require_once __DIR__ . '/../../integrations/mailer.php';

__DIR__ is _app/api/routes. Two levels up lands at _app/ — not _app/api/.
The file _app/integrations/mailer.php does not exist.
The correct path is one level up: __DIR__ . '/../integrations/mailer.php'

This is the root cause of every "Could not save. Please try again." error on /seat/.
The fix is one character: remove one '../'.

---

## Step 1 — Pull latest main

```bash
git pull --rebase origin main
```

---

## Step 2 — Read before touching

```bash
grep -n 'require' _app/api/routes/lead-capture.php
```

Confirm you see exactly:
    77:        require_once __DIR__ . '/../../integrations/mailer.php';

If different, STOP and report.

---

## Step 3 — Apply fix

```bash
python3 << 'PYEOF'
with open('_app/api/routes/lead-capture.php', 'r') as f:
    c = f.read()

old = "require_once __DIR__ . '/../../integrations/mailer.php';"
new = "require_once __DIR__ . '/../integrations/mailer.php';"

assert c.count(old) == 1, f"ABORT: expected 1 occurrence, found {c.count(old)}"
c = c.replace(old, new)
assert c.count(new) == 1, "ABORT: replacement failed"
assert c.count(old) == 0, "ABORT: old string still present"

with open('_app/api/routes/lead-capture.php', 'w') as f:
    f.write(c)
print("Done.")
PYEOF
```

---

## Step 4 — Verify

```bash
python3 << 'PYEOF2'
with open('_app/api/routes/lead-capture.php') as f:
    c = f.read()

checks = [
    ('correct path present',  "require_once __DIR__ . '/../integrations/mailer.php';" in c),
    ('wrong path gone',       "require_once __DIR__ . '/../../integrations/mailer.php';" not in c),
]
all_ok = True
for label, ok in checks:
    print(f"[{'OK' if ok else 'FAIL'}] {label}")
    if not ok:
        all_ok = False
print()
print("RESULT:", "ALL OK" if all_ok else "FAILURES — do not commit")
PYEOF2
```

---

## Step 5 — Stage and commit to review branch

Only if all checks pass.

```bash
git checkout -b review/session-43
git add _app/api/routes/lead-capture.php
git commit -m "fix(lead-capture): correct require path to mailer.php — was ../../ should be ../"
git push origin review/session-43
```

---

## STOP — wait for review before merging to main
