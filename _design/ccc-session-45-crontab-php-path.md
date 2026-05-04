# CCC Session 45: _app/monitoring/crontab.txt — fix PHP binary path in cron jobs
# Branch: review/session-45
# FILES: _app/monitoring/crontab.txt

## Context

cPanel cron invokes a different PHP binary than /usr/local/bin/php.
The bare 'php' command in crontab runs as web SAPI, triggering the 403 access
guard in cron-email.php on every execution. No emails have ever been sent by cron.
Fix: replace bare 'php' with '/usr/local/bin/php' in all three cron job lines.

---

## Step 1 — Pull latest main

```bash
git pull --rebase origin main
```

---

## Step 2 — Read before touching

```bash
grep 'php' _app/monitoring/crontab.txt
```

Confirm you see exactly these three lines containing bare 'php':
- */5 * * * * php /home4/cogsaust/public_html/_app/api/cron-email.php
- 0 8 * * 5 php /home4/cogsaust/public_html/_app/api/cron-hub-digest.php
- 0 21 * * * php /home4/cogsaust/public_html/_app/api/cron-error-digest.php

If different, STOP and report.

---

## Step 3 — Apply fix

```bash
python3 << 'PYEOF'
with open('_app/monitoring/crontab.txt', 'r') as f:
    c = f.read()
orig = c

assert c.count('*/5 * * * * php ') == 1, "ABORT: email cron line not found"
assert c.count('0 8 * * 5 php ') == 1, "ABORT: hub digest cron line not found"
assert c.count('0 21 * * * php ') == 1, "ABORT: error digest cron line not found"

c = c.replace('*/5 * * * * php ', '*/5 * * * * /usr/local/bin/php ')
c = c.replace('0 8 * * 5 php ', '0 8 * * 5 /usr/local/bin/php ')
c = c.replace('0 21 * * * php ', '0 21 * * * /usr/local/bin/php ')

assert c.count('/usr/local/bin/php') == 3, "ABORT: replacement count wrong"
assert 'cron-email.php' in c, "ABORT: email cron missing"
assert 'cron-hub-digest.php' in c, "ABORT: hub digest cron missing"
assert 'cron-error-digest.php' in c, "ABORT: error digest cron missing"

with open('_app/monitoring/crontab.txt', 'w') as f:
    f.write(c)
print(f"Done. {len(orig)} -> {len(c)} bytes")
PYEOF
```

---

## Step 4 — Verify

```bash
python3 << 'PYEOF2'
with open('_app/monitoring/crontab.txt') as f:
    c = f.read()

checks = [
    ('email cron uses /usr/local/bin/php',      '*/5 * * * * /usr/local/bin/php /home4/cogsaust/public_html/_app/api/cron-email.php' in c),
    ('hub digest cron uses /usr/local/bin/php', '0 8 * * 5 /usr/local/bin/php /home4/cogsaust/public_html/_app/api/cron-hub-digest.php' in c),
    ('error digest cron uses /usr/local/bin/php','0 21 * * * /usr/local/bin/php /home4/cogsaust/public_html/_app/api/cron-error-digest.php' in c),
    ('no bare php cron remaining',              '* * php ' not in c and '5 php ' not in c),
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
git checkout -b review/session-45
git add _app/monitoring/crontab.txt
git commit -m "fix(cron): use /usr/local/bin/php in all cron jobs — bare php runs as web SAPI and gets 403"
git push origin review/session-45
```

---

## STOP — wait for review before merging to main
