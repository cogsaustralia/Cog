# CCC Session 33: cron-error-digest.php — daily digest + hourly error alert
# Branch: review/session-33
# FILES: _app/api/cron-error-digest.php · _skills/cogs-error-digest.md
#         _app/monitoring/crontab.txt · _app/monitoring/install-crontab.sh

## Purpose
Adds belt-and-braces error monitoring on top of the real-time beacon alert
from sessions 31/32. Two new cron jobs:

  1. Hourly (at :05 past each hour) — fires only if NEW unacknowledged errors
     appeared in the last 65 minutes. Silent if nothing new.

  2. Daily at 07:00 AEST — full digest regardless of error count. Clean bill
     of health email when zero errors. Error breakdown table when errors exist.

Both jobs email three addresses directly:
  - admin@cogsaustralia.org (admin inbox)
  - ThomasC@cogsaustralia.org (Thomas direct)
  - MAIL_ADMIN_EMAIL from .env (if different from the above)

Uses the existing smtpSendEmail() — no new dependencies.
OpenClaw Telegram skill is written but DEFERRED until OpenClaw is stable.

---

## Step 1 — Pull and ground truth

```bash
git pull --rebase origin main

echo "=== cron-email.php exists as pattern reference ==="
ls -la _app/api/cron-email.php

echo "=== cron-error-digest.php in repo ==="
ls -la _app/api/cron-error-digest.php

echo "=== cogs-error-digest.md skill in repo ==="
ls -la _skills/cogs-error-digest.md

echo "=== mailer functions available ==="
grep -c "function smtpSendEmail\|function mailerEnabled" _app/api/integrations/mailer.php

echo "=== app_error_log table migration exists ==="
grep -c "app_error_log" sql/hub_monitor_queries_v1.sql

echo "=== cron-error-digest.php line count ==="
wc -l _app/api/cron-error-digest.php
```

Abort if:
- cron-error-digest.php is missing (was not pushed to main)
- smtpSendEmail and mailerEnabled both return 0
- line count of cron-error-digest.php is less than 200

---

## Step 2 — Verify cron-error-digest.php integrity

Run a structural check on the script before touching anything else.

```bash
python3 << 'PYEOF'
with open('_app/api/cron-error-digest.php') as f:
    content = f.read()

checks = [
    ('CLI-only guard',              "PHP_SAPI !== 'cli'" in content),
    ('ignore_user_abort',           'ignore_user_abort(true)' in content),
    ('--mode=daily handler',        "'--mode=daily'" in content),
    ('--mode=hourly handler',       "'--mode=hourly'" in content),
    ('mailerEnabled guard',         'mailerEnabled()' in content),
    ('table existence check',       'app_error_log' in content and 'information_schema' in content),
    ('hourly 65min window',         'INTERVAL 65 MINUTE' in content),
    ('daily 24h window',            'INTERVAL 24 HOUR' in content),
    ('sendHourlyAlert function',    'function sendHourlyAlert' in content),
    ('sendDailyDigest function',    'function sendDailyDigest' in content),
    ('smtpSendEmail called',        'smtpSendEmail' in content),
    ('admin@cogsaustralia.org',     'admin@cogsaustralia.org' in content),
    ('errors.php URL in email',     'admin/errors.php' in content),
    ('Throwable catch',             'catch (Throwable' in content),
    ('cron comment with path',      '/home4/cogsaust/public_html' in content),
    ('Bootstrap includes',          'config/database.php' in content and 'mailer.php' in content),
]

all_pass = True
for label, ok in checks:
    s = 'PASS' if ok else 'FAIL'
    if not ok:
        all_pass = False
    print(f'[{s}] {label}')

print()
print('ALL PASS' if all_pass else 'FAILURES — do not proceed')
PYEOF
```

Abort if any check FAILS.

---

## Step 3 — Verify crontab.txt and install-crontab.sh are in repo

The cron jobs are defined in `_app/monitoring/crontab.txt` (canonical record)
and installed via `_app/monitoring/install-crontab.sh` (one-command installer).
Both files must exist in the repo before this step passes.

```bash
echo "=== crontab.txt present ==="
cat _app/monitoring/crontab.txt

echo ""
echo "=== install-crontab.sh present and executable ==="
ls -la _app/monitoring/install-crontab.sh

echo ""
echo "=== error-digest jobs in crontab.txt ==="
grep "cron-error-digest" _app/monitoring/crontab.txt
```

Abort if either file is missing or if grep returns no lines.

---

## Step 4 — Verification

```bash
python3 << 'PYEOF'
import re

checks = []

# cron-error-digest.php
with open('_app/api/cron-error-digest.php') as f:
    php = f.read()

checks.append(('PHP: CLI guard present',             "PHP_SAPI !== 'cli'" in php))
checks.append(('PHP: both modes handled',            "'--mode=daily'" in php and "'--mode=hourly'" in php))
checks.append(('PHP: mailerEnabled check',           'mailerEnabled()' in php))
checks.append(('PHP: 65min hourly window',           'INTERVAL 65 MINUTE' in php))
checks.append(('PHP: 24h daily window',              'INTERVAL 24 HOUR' in php))
checks.append(('PHP: table existence guard',         'information_schema' in php))
checks.append(('PHP: sendHourlyAlert defined',       'function sendHourlyAlert' in php))
checks.append(('PHP: sendDailyDigest defined',       'function sendDailyDigest' in php))
checks.append(('PHP: smtpSendEmail called',          'smtpSendEmail' in php))
checks.append(('PHP: CC to admin@cogsaustralia',     'admin@cogsaustralia.org' in php))
checks.append(('PHP: errors.php URL present',        'admin/errors.php' in php))
checks.append(('PHP: JS errors labelled http_status=0', "http_status'] === 0" in php or "http_status\"] === 0" in php))
checks.append(('PHP: clean exit on empty hourly',    'exit(0)' in php))
checks.append(('PHP: no heredoc',                    '<<' not in php.replace('<<<', '')))

# skill file
with open('_skills/cogs-error-digest.md') as f:
    skill = f.read()

checks.append(('Skill: hourly trigger present',      '5 * * * *' in skill))
checks.append(('Skill: daily trigger present',       '0 21 * * *' in skill))
checks.append(('Skill: admin-summary endpoint',      'admin-summary' in skill))
checks.append(('Skill: JS label defined',            '[JS]' in skill))
checks.append(('Skill: cookie auth documented',      'admin-session.cookie' in skill))
checks.append(('Skill: dependency note present',     'session 32' in skill))

all_pass = True
for label, ok in checks:
    s = 'PASS' if ok else 'FAIL'
    if not ok:
        all_pass = False
    print(f'[{s}] {label}')

print()
print('ALL PASS' if all_pass else 'FAILURES DETECTED — do not commit')
PYEOF
```

---

## Step 5 — Commit and push to review branch

Only if ALL PASS above.

```bash
git checkout -b review/session-33
git add _app/api/cron-error-digest.php _skills/cogs-error-digest.md \
        _app/monitoring/crontab.txt _app/monitoring/install-crontab.sh
git diff --cached --stat
git commit -m "feat(monitoring): error digest cron + deferred OpenClaw skill

cron-error-digest.php:
- --mode=hourly: emails admin on new unacknowledged errors every 65 minutes
- --mode=daily: full 24h digest at 07:00 AEST, clean bill of health when zero
- Emails admin@cogsaustralia.org + ThomasC@cogsaustralia.org + MAIL_ADMIN_EMAIL
- Deduplication guards prevent duplicate sends to same address
- HTML email with error class table + acknowledge link
- Guards: CLI-only, mailerEnabled(), table existence check
- Falls silent on empty hourly run (no errors = no email)
- Cron jobs defined in _app/monitoring/crontab.txt (version-controlled)
- Installed via _app/monitoring/install-crontab.sh (one command, backs up first)

cogs-error-digest.md (OpenClaw skill — DEFERRED):
- Telegram push layer, inactive until OpenClaw stable
- Email alerting via cron-error-digest.php is the active channel
- Skill contains activation steps for when OpenClaw is ready"
git push origin review/session-33
```

## STOP — wait for review before merging to main

## After merge — Thomas action required

The cron jobs are in the repo. Install them with one command via SSH or cPanel Terminal:

```bash
cd /home4/cogsaust/public_html && bash _app/monitoring/install-crontab.sh
```

This replaces the entire live crontab with `_app/monitoring/crontab.txt`.
It backs up the current crontab to `/home4/cogsaust/logs/` before overwriting.
It prints what was installed and the live job count for confirmation.

To add or change any cron job in future: edit `_app/monitoring/crontab.txt`,
commit, push, pull on server, run install-crontab.sh again.

OpenClaw Telegram push is deferred. The skill file (`_skills/cogs-error-digest.md`)
contains activation instructions for when OpenClaw is stable.
