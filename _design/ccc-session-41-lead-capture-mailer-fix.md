# CCC Session 41: _app/api/integrations/mailer.php — fix missing $partnersUrl in IIFE use() clauses
# Branch: review/session-41
# FILES: _app/api/integrations/mailer.php

## Context

renderEmailTemplate() uses a PHP match expression where every arm is an immediately-invoked
closure (IIFE). PHP evaluates ALL arms when the match executes. Three IIFEs reference
$partnersUrl but do not declare it in their use() clause, causing a PHP fatal on every call
to renderEmailTemplate() — including from lead-capture.php. This is the root cause of the
"Could not save. Please try again." error on first submit of /seat/.

Fix: add $partnersUrl to the use() clause of the three affected IIFEs. No other changes.

---

## Step 1 — Pull latest main

```bash
git pull --rebase origin main
```

---

## Step 2 — Read the file before touching anything

```bash
grep -n 'function() use' _app/api/integrations/mailer.php
```

Confirm you see these three lines (exact text):
- `(function() use ($p, $setupUrl, $foundingNotice, $site, $activationToken) {`
- `'payment_intent_member' => (function() use ($p, $site) {`
- `(function() use ($p, $wrapOpen, $headerBar, $body, $footerBar, $wrapClose, $h2Style, $h3Style, $pStyle, $boxStyle, $btnStyle, $urlStyle, $noticeStyle, $site): array {`

If any are missing or different, STOP and report.

---

## Step 3 — Apply changes (Python replacement pass)

```bash
python3 << 'PYEOF'
with open('_app/api/integrations/mailer.php', 'r') as f:
    c = f.read()
orig = len(c)

# Fix 1: snft_user_confirmation — add $partnersUrl to use() clause
old1 = "(function() use ($p, $setupUrl, $foundingNotice, $site, $activationToken) {"
new1 = "(function() use ($p, $setupUrl, $foundingNotice, $site, $activationToken, $partnersUrl) {"
assert c.count(old1) == 1, f"ABORT: old1 count={c.count(old1)}"
c = c.replace(old1, new1)

# Fix 2: payment_intent_member — add $partnersUrl to use() clause
old2 = "'payment_intent_member' => (function() use ($p, $site) {"
new2 = "'payment_intent_member' => (function() use ($p, $site, $partnersUrl) {"
assert c.count(old2) == 1, f"ABORT: old2 count={c.count(old2)}"
c = c.replace(old2, new2)

# Fix 3: hub_weekly_digest — add $partnersUrl to use() clause
old3 = "(function() use ($p, $wrapOpen, $headerBar, $body, $footerBar, $wrapClose, $h2Style, $h3Style, $pStyle, $boxStyle, $btnStyle, $urlStyle, $noticeStyle, $site): array {"
new3 = "(function() use ($p, $wrapOpen, $headerBar, $body, $footerBar, $wrapClose, $h2Style, $h3Style, $pStyle, $boxStyle, $btnStyle, $urlStyle, $noticeStyle, $site, $partnersUrl): array {"
assert c.count(old3) == 1, f"ABORT: old3 count={c.count(old3)}"
c = c.replace(old3, new3)

with open('_app/api/integrations/mailer.php', 'w') as f:
    f.write(c)
print(f"Done. {orig} -> {len(c)} bytes (+{len(c)-orig})")
PYEOF
```

---

## Step 4 — Verify

```bash
python3 << 'PYEOF2'
with open('_app/api/integrations/mailer.php') as f:
    c = f.read()

checks = [
    ('snft_user_confirmation new use() present',
     "(function() use ($p, $setupUrl, $foundingNotice, $site, $activationToken, $partnersUrl) {" in c),
    ('snft_user_confirmation old use() gone',
     "(function() use ($p, $setupUrl, $foundingNotice, $site, $activationToken) {" not in c),
    ('payment_intent_member new use() present',
     "'payment_intent_member' => (function() use ($p, $site, $partnersUrl) {" in c),
    ('payment_intent_member old use() gone',
     "'payment_intent_member' => (function() use ($p, $site) {" not in c),
    ('hub_weekly_digest new use() present',
     "(function() use ($p, $wrapOpen, $headerBar, $body, $footerBar, $wrapClose, $h2Style, $h3Style, $pStyle, $boxStyle, $btnStyle, $urlStyle, $noticeStyle, $site, $partnersUrl): array {" in c),
    ('hub_weekly_digest old use() gone',
     "(function() use ($p, $wrapOpen, $headerBar, $body, $footerBar, $wrapClose, $h2Style, $h3Style, $pStyle, $boxStyle, $btnStyle, $urlStyle, $noticeStyle, $site): array {" not in c),
    ('$partnersUrl defined in function scope',
     "$partnersUrl = $site . '/partners/';" in c),
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

```bash
php -l _app/api/integrations/mailer.php
```

```bash
git diff _app/api/integrations/mailer.php
```

---

## Step 5 — Stage and commit to review branch

Only if all checks above pass and php -l reports no syntax errors.

```bash
git checkout -b review/session-41
git add _app/api/integrations/mailer.php
git commit -m "fix(mailer): add \$partnersUrl to three IIFE use() clauses — fixes /seat/ lead capture 500 error"
git push origin review/session-41
```

---

## STOP — wait for review before merging to main
