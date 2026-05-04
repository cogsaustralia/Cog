# CCC Session 37: Full funnel audit — PHP lint, JS checks, API coverage, smoke test
# Branch: review/session-37-funnel-audit
# READ-ONLY session. No file changes unless a bug is confirmed.
# If bugs are found: list them, STOP, wait for instruction before fixing anything.

## Purpose
Systematic audit of every file in the conversion funnel from /seat/ through to
thank-you/index.html. Covers: PHP syntax, JS structure, API route registration,
DB table guards, localStorage safety, redirect chain, and email pipeline.
No changes made unless explicitly instructed after review.

---

## Step 1 — Pull and baseline

```bash
git pull --rebase origin main
git log --oneline -5
echo "=== PHP version on server ==="
php --version
```

---

## Step 2 — PHP lint: every file the funnel touches

```bash
echo "=== PHP lint: API routes ==="
for f in \
  _app/api/index.php \
  _app/api/helpers.php \
  _app/api/config/database.php \
  _app/api/config/bootstrap.php \
  _app/api/config/app.php \
  _app/api/routes/lead-capture.php \
  _app/api/routes/snft-reserve.php \
  _app/api/routes/track.php \
  _app/api/routes/jvpa-click.php \
  _app/api/routes/invitations.php \
  _app/api/routes/voice-submissions.php \
  _app/api/routes/client-error.php \
  _app/api/routes/auth.php \
  _app/api/routes/admin.php \
  _app/api/integrations/mailer.php \
  _app/api/services/JvpaAcceptanceService.php; do
  result=$(php -l "$f" 2>&1)
  status=$(echo "$result" | grep -c "No syntax errors" || true)
  if [ "$status" = "1" ]; then
    echo "PASS $f"
  else
    echo "FAIL $f: $result"
  fi
done
```

## STOP — paste output. Every line must show PASS. Any FAIL is a blocker.

---

## Step 3 — API route registration audit

Confirm every route called by funnel pages is registered in index.php.

```bash
python3 << 'PYEOF'
import re

# Routes called by funnel pages (from static analysis)
required_routes = [
    'lead-capture',
    'track',           # covers track/visit and track/event
    'client-error',
    'snft-reserve',
    'invitations',     # covers invitations/validate and invitations/my-code
    'jvpa-click',
    'voice-submissions',
]

with open('_app/api/index.php') as f:
    index = f.read()

registered = re.findall(r"case '([^']+)':", index)
print(f"Registered routes ({len(registered)}): {registered}")
print()

all_ok = True
for route in required_routes:
    # Routes may be registered as 'foo' and handle 'foo/bar' internally
    base = route.split('/')[0]
    found = base in registered
    status = 'PASS' if found else 'FAIL -- MISSING'
    if not found:
        all_ok = False
    print(f"[{status}] {route} (base: {base})")

print()
print('ALL ROUTES REGISTERED' if all_ok else 'MISSING ROUTES -- BLOCKER')
PYEOF
```

---

## Step 4 — HTML structure audit: all funnel pages

```bash
python3 << 'PYEOF'
import re

pages = [
    ('seat/index.html',         'Seat'),
    ('seat/inside/index.html',  'Seat Inside'),
    ('join/index.html',         'Join'),
    ('thank-you/index.html',    'Thank You'),
]

all_ok = True
for path, label in pages:
    with open(path) as f:
        content = f.read()

    issues = []

    # Required structural tags
    for tag in ['<!DOCTYPE', '<html', '</head>', '<body', '</body>', '</html>']:
        if tag not in content:
            issues.append(f'MISSING {tag}')

    # Script balance (all tags including src)
    opens = len(re.findall(r'<script[^>]*>', content, re.IGNORECASE))
    closes = len(re.findall(r'</script>', content, re.IGNORECASE))
    if opens != closes:
        issues.append(f'SCRIPT TAG IMBALANCE: {opens} open, {closes} close')

    # Error beacon present (window.onerror in inline script or site.js loaded)
    has_beacon = 'window.onerror' in content
    has_sitejs = bool(re.search(r'src=["\'][^"\']*site\.js["\']', content))
    if not has_beacon and not has_sitejs:
        issues.append('NO ERROR BEACON (window.onerror not found, site.js not loaded)')

    # API endpoint reference
    if '_app/api/index.php' not in content:
        issues.append('NO API ENDPOINT REFERENCE')

    # localStorage always wrapped in try/catch
    ls_uses = re.findall(r'localStorage\.\w+Item\(', content)
    # Check each use has a nearby try
    # Simple heuristic: count try blocks containing localStorage
    try_blocks_with_ls = len(re.findall(r'try\s*\{[^}]*localStorage', content))
    bare_ls = len(ls_uses) - try_blocks_with_ls * 2  # rough estimate
    if len(ls_uses) > 0 and try_blocks_with_ls == 0:
        issues.append(f'POSSIBLE UNPROTECTED localStorage ({len(ls_uses)} uses, 0 try blocks found)')

    # User-facing em-dashes (in text nodes only, not CSS/JS comments)
    stripped = re.sub(r'<style[^>]*>.*?</style>', '', content, flags=re.DOTALL)
    stripped = re.sub(r'<script[^>]*>.*?</script>', '', stripped, flags=re.DOTALL)
    em_text = re.findall(r'>([^<]*\u2014[^<]*)<', stripped)
    if em_text:
        issues.append(f'EM-DASH IN USER-FACING TEXT: {[m.strip()[:60] for m in em_text]}')

    # Grade 6 AI-tell banned phrases in user-facing text
    banned = ['for the avoidance of doubt', 'straightforward', 'genuinely']
    for phrase in banned:
        if phrase.lower() in stripped.lower():
            issues.append(f'BANNED PHRASE: {phrase!r}')

    status = 'PASS' if not issues else 'FAIL'
    if issues:
        all_ok = False
    print(f"[{status}] {label} ({path})")
    for issue in issues:
        print(f"       {issue}")

print()
print('ALL PAGES CLEAN' if all_ok else 'ISSUES FOUND -- list above')
PYEOF
```

---

## Step 5 — lead-capture.php functional audit

```bash
python3 << 'PYEOF'
with open('_app/api/routes/lead-capture.php') as f:
    php = f.read()

checks = [
    ('INSERT INTO lead_captures',           'INSERT INTO lead_captures' in php),
    ('ON DUPLICATE KEY UPDATE',             'ON DUPLICATE KEY UPDATE' in php),
    ('Email validation',                    'FILTER_VALIDATE_EMAIL' in php or 'validateEmail' in php),
    ('queueEmail confirmation',             'lead_magnet_confirmation' in php),
    ('Thomas instant alert',                'ThomasC@cogsaustralia.org' in php),
    ('Alert inside rowCount guard',         php.index('ThomasC@cogsaustralia.org') > php.index('rowCount')),
    ('Alert try/catch',                     'catch (Throwable $alertEx)' in php),
    ('Alert silent fail',                   '[lead-capture alert]' in php),
    ('apiSuccess response',                 "json_encode(['success' => true" in php or "apiSuccess(" in php),
    ('declare strict_types',                'declare(strict_types=1)' in php),
]

all_ok = True
for label, ok in checks:
    s = 'PASS' if ok else 'FAIL'
    if not ok: all_ok = False
    print(f'[{s}] {label}')
print()
print('PASS' if all_ok else 'FAIL -- issues above')
PYEOF
```

---

## Step 6 — snft-reserve.php functional audit

```bash
python3 << 'PYEOF'
with open('_app/api/routes/snft-reserve.php') as f:
    php = f.read()

checks = [
    ('ignore_user_abort',                   'ignore_user_abort(true)' in php),
    ('requireMethod POST',                  "requireMethod('POST')" in php),
    ('Email validation',                    'validateEmail' in php),
    ('Members table guard',                 "trust_table_exists(\$db, 'members')" in php),
    ('Reservation lines table guard',       "trust_table_exists(\$db, 'member_reservation_lines')" in php),
    ('Member number generation',            'trust_generate_personal_member_number' in php),
    ('snft_user_confirmation email',        'snft_user_confirmation' in php),
    ('JvpaAcceptanceService required',      'JvpaAcceptanceService.php' in php),
    ('getDB called',                        'getDB()' in php),
    ('apiSuccess called',                   'apiSuccess(' in php),
    ('apiError on validation fail',         'apiError(' in php),
]

all_ok = True
for label, ok in checks:
    s = 'PASS' if ok else 'FAIL'
    if not ok: all_ok = False
    print(f'[{s}] {label}')
print()
print('PASS' if all_ok else 'FAIL -- issues above')
PYEOF
```

---

## Step 7 — client-error.php security audit

```bash
python3 << 'PYEOF'
with open('_app/api/routes/client-error.php') as f:
    php = f.read()

checks = [
    ('204 response code',                   'http_response_code(204)' in php),
    ('POST method guard',                   "REQUEST_METHOD" in php),
    ('Rate limit present',                  'INTERVAL 60 SECOND' in php),
    ('Rate limit threshold 10',             '>= 10' in php),
    ('http_status=0 in INSERT',             ', 0, ' in php),
    ('ip_hash privacy',                     "hash('sha256'" in php),
    ('Silent exit on breach',               'same 204 already sent' in php),
    ('Fail open on rate limit error',       'fail open' in php.lower()),
    ('Silent fail on exception',            '[client-error beacon]' in php),
]

all_ok = True
for label, ok in checks:
    s = 'PASS' if ok else 'FAIL'
    if not ok: all_ok = False
    print(f'[{s}] {label}')
print()
print('PASS' if all_ok else 'FAIL -- issues above')
PYEOF
```

---

## Step 8 — mailer.php integrity audit

```bash
python3 << 'PYEOF'
import re

with open('_app/api/integrations/mailer.php') as f:
    content = f.read()

checks = [
    ('PHP opens correctly',                 '<?php' in content),
    ('smtpSendEmail function defined',      'function smtpSendEmail' in content),
    ('mailerEnabled function defined',      'function mailerEnabled' in content),
    ('queueEmail function defined',         'function queueEmail' in content),
    ('snft_user_confirmation template',     'snft_user_confirmation' in content),
    ('lead_magnet_confirmation template',   'lead_magnet_confirmation' in content),
    ('Disclosure block present',            'Member disclosure section' in content),
    ('Disclosure before founding notice',   content.index('Member disclosure section') < content.index('Founding phase notice')),
    ('Apostrophes escaped in disclosure',   "Foundation's" not in content[content.index('Member disclosure section'):content.index('Founding phase notice')]),
    ('CHESS Register source cited',         'CHESS Share Register' in content),
    ('JORC MRE source cited',               '2025 JORC' in content),
    ('Not financial advice footer',         'Not financial advice' in content),
    ('14-day cooling off',                  '14-day cooling off' in content),
    ('ThomasC refund address',              'ThomasC@cogsaustralia.org' in content),
    ('1545 shares wording correct',         '1,545 ordinary shares is a relatively small position' in content),
    ('No banned today price wording',       "today's price" not in content),
    ('No unescaped single quotes in block', True),  # validated by PHP lint in step 2
]

all_ok = True
for label, ok in checks:
    s = 'PASS' if ok else 'FAIL'
    if not ok: all_ok = False
    print(f'[{s}] {label}')

# Extra: scan disclosure block for bare single quotes
start = content.find('<!-- Member disclosure section -->')
end = content.find('<!-- Founding phase notice -->')
if start > 0 and end > 0:
    block = content[start:end]
    bare_sq = re.findall(r"(?<!\\)(?<!&#39)(?<![a-z])'(?![^<]*>)", block)
    if bare_sq:
        all_ok = False
        print(f"[FAIL] Bare single quotes in disclosure block: {bare_sq[:5]}")
    else:
        print("[PASS] No bare single quotes in disclosure block")

print()
print('PASS' if all_ok else 'FAIL -- issues above')
PYEOF
```

---

## Step 9 — join page redirect chain and storage keys

```bash
python3 << 'PYEOF'
import re

with open('join/index.html') as f:
    join = f.read()
with open('thank-you/index.html') as f:
    ty = f.read()

checks = []

# Thank-you URL in join page
ty_url = re.search(r'data-thankyou-url=["\']([^"\']+)["\']', join)
checks.append(('join: thankyou URL set', ty_url is not None))
if ty_url:
    print(f"  Thank-you URL: {ty_url.group(1)}")

# Storage key written in join
checks.append(('join: cogs_snft_member written', 'cogs_snft_member' in join))
checks.append(('join: cogs_snft_thankyou written', 'cogs_snft_thankyou' in join))

# Storage key read in thank-you
checks.append(('thank-you: cogs_snft_member read', 'cogs_snft_member' in ty))

# Storage reads wrapped in try/catch
checks.append(('thank-you: storage read in try/catch',
    bool(re.search(r'try\s*\{[^}]*localStorage', ty))))

# snft-reserve route called by join
checks.append(('join: snft-reserve route referenced', 'snft-reserve' in join))

# Vault/partners link on thank-you
checks.append(('thank-you: vault link present',
    'partners/index.html' in ty or 'wallets/member' in ty))

# Invitation code handling
checks.append(('join: invitation validation present', 'invitations/validate' in join))
checks.append(('thank-you: my-code fetch present', 'invitations/my-code' in ty))

all_ok = True
for label, ok in checks:
    s = 'PASS' if ok else 'FAIL'
    if not ok: all_ok = False
    print(f'[{s}] {label}')

print()
print('PASS' if all_ok else 'FAIL -- issues above')
PYEOF
```

---

## Step 10 — Compile report

```bash
echo "======================================================"
echo "FUNNEL AUDIT REPORT — $(date)"
echo "======================================================"
echo ""
echo "Summarise findings from steps 2-9:"
echo "- Any PHP lint FAIL = BLOCKER (admin cannot login if mailer.php breaks)"
echo "- Any missing API route = BLOCKER (join will fail silently)"
echo "- Any structural HTML issue = HIGH (page may not render)"
echo "- Storage/redirect issues = HIGH (member data lost between steps)"
echo "- Beacon/monitoring gaps = MEDIUM (errors not visible)"
echo ""
echo "List all FAILs found. If zero FAILs: report ALL CLEAR."
echo "======================================================"
```

## STOP — Paste full report output here. Do NOT fix anything without explicit instruction.
## List every FAIL with the file name, step number, and exact error.
## If all steps show PASS: state ALL CLEAR and stop.
