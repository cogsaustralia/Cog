# CCC Session 34: server-side rate limiting on client-error beacon
# Branch: review/session-34
# FILES: _app/api/routes/client-error.php

## Purpose
The client-error beacon endpoint accepts unauthenticated POSTs with no
server-side rate limiting. A trivial script loop can flood app_error_log,
bury real errors, and degrade DB performance during the campaign.

This session adds IP-based rate limiting directly inside client-error.php:
- 10 writes per IP per 60 seconds (generous for real browsers, kills floods)
- Uses app_error_log itself as the counter — no new table required
- Silent exit on breach — never reveal throttling to an attacker
- Updates the docblock to reflect the new posture

One file. One insertion. No schema changes. No new dependencies.

---

## Step 1 — Pull and ground truth

```bash
git pull --rebase origin main

echo "=== client-error.php current state ==="
grep -n "rate\|limit\|REMOTE_ADDR\|ipHash\|getDB\|INSERT" _app/api/routes/client-error.php

echo "=== confirm insertion anchor exists exactly once ==="
grep -c "Hash IP and UA" _app/api/routes/client-error.php

echo "=== confirm getDB call exists exactly once ==="
grep -c "getDB()" _app/api/routes/client-error.php
```

Abort if:
- "Hash IP and UA" count != 1
- getDB() count != 1

---

## Step 2 — Insert rate limit block

The rate limit check is inserted immediately after the IP hash is computed
and before getDB() is called. This means:
- We have the IP hash available to query against
- We fail fast before any DB write or email attempt
- Silent 204 exit — identical response to a successful write

```bash
python3 << 'PYEOF'
with open('_app/api/routes/client-error.php') as f:
    content = f.read()

OLD = """    // Hash IP and UA — same privacy posture as server-side errors
    $rawIp = $_SERVER['REMOTE_ADDR'] ?? '';
    $rawUa = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ipHash = $rawIp !== '' ? hash('sha256', $rawIp) : null;
    $uaHash = $rawUa !== '' ? hash('sha256', $rawUa) : null;

    $db = getDB();"""

NEW = """    // Hash IP and UA — same privacy posture as server-side errors
    $rawIp = $_SERVER['REMOTE_ADDR'] ?? '';
    $rawUa = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ipHash = $rawIp !== '' ? hash('sha256', $rawIp) : null;
    $uaHash = $rawUa !== '' ? hash('sha256', $rawUa) : null;

    // Server-side rate limit: 10 writes per IP per 60 seconds.
    // Uses app_error_log as the counter — no new table required.
    // Silent exit on breach — never reveal throttling to an attacker.
    // Legitimate browsers send at most 3 per page load; 10/60s is generous.
    $db = getDB();
    if ($ipHash !== null) {
        try {
            $rl = $db->prepare(
                "SELECT COUNT(*) FROM app_error_log
                  WHERE ip_hash = ?
                    AND http_status = 0
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 60 SECOND)"
            );
            $rl->execute([$ipHash]);
            if ((int)($rl->fetchColumn() ?: 0) >= 10) {
                exit; // silent — same 204 already sent above
            }
        } catch (Throwable $rlEx) {
            // Rate limit check failed — fail open (allow write) rather than
            // block legitimate errors. Log silently.
            error_log('[client-error rate-limit] ' . $rlEx->getMessage());
        }
    }"""

count = content.count(OLD)
print(f"Anchor match: {count} (must be 1)")
if count != 1:
    print("ABORT")
    exit(1)

content = content.replace(OLD, NEW)

with open('_app/api/routes/client-error.php', 'w') as f:
    f.write(content)
print("Rate limit block inserted.")
PYEOF
```

---

## Step 3 — Update docblock

Replace the old rate-limiting note in the docblock to reflect server-side enforcement.

```bash
python3 << 'PYEOF'
with open('_app/api/routes/client-error.php') as f:
    content = f.read()

OLD = " * No authentication required. Rate-limiting is enforced client-side (3 per page load)."
NEW = " * No authentication required. Rate-limiting: 10 writes per IP per 60 seconds (server-side,\n * via app_error_log count) + 3 per page load (client-side). Silent exit on breach."

count = content.count(OLD)
print(f"Docblock anchor match: {count} (must be 1)")
if count != 1:
    print("ABORT")
    exit(1)

content = content.replace(OLD, NEW)
with open('_app/api/routes/client-error.php', 'w') as f:
    f.write(content)
print("Docblock updated.")
PYEOF
```

---

## Step 4 — Verification

```bash
python3 << 'PYEOF'
with open('_app/api/routes/client-error.php') as f:
    content = f.read()

checks = [
    # Rate limit block present and correct
    ('Rate limit comment present',          'Server-side rate limit: 10 writes per IP' in content),
    ('Rate limit query present',            'INTERVAL 60 SECOND' in content),
    ('ip_hash used in rate limit query',    'WHERE ip_hash = ?' in content),
    ('http_status = 0 filter in query',     'http_status = 0' in content),
    ('Threshold is 10',                     '>= 10' in content),
    ('Silent exit on breach',               'silent — same 204' in content),
    ('Fail-open on rate limit error',       'fail open' in content.lower() or 'fail open' in content),
    ('Rate limit inside try block',         content.index('INTERVAL 60 SECOND') > content.index('try {')),
    ('Rate limit before INSERT',            content.index('INTERVAL 60 SECOND') < content.index('INSERT INTO app_error_log')),
    ('Rate limit after ipHash computed',    content.index('INTERVAL 60 SECOND') > content.index('ipHash = $rawIp')),

    # Original functionality intact
    ('INSERT still present',                'INSERT INTO app_error_log' in content),
    ('priorCount email debounce intact',    'priorCount' in content),
    ('smtpSendEmail intact',                'smtpSendEmail' in content),
    ('204 response intact',                 'http_response_code(204)' in content),
    ('Silent fail catch intact',            "error_log('[client-error beacon]" in content),
    ('ip_hash still stored in INSERT',      content.count('$ipHash') >= 2),

    # Docblock updated
    ('Docblock updated to server-side',     'server-side' in content and '10 writes per IP' in content),
    ('Old docblock line removed',           'enforced client-side (3 per page load)' not in content),

    # Structure
    ('declare strict_types intact',         "declare(strict_types=1)" in content),
    ('PHP method guard intact',             "REQUEST_METHOD" in content),
]

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
git checkout -b review/session-34
git add _app/api/routes/client-error.php
git diff --cached
git commit -m "fix(security): server-side rate limiting on client-error beacon

Unauthenticated POST endpoint had no server-side rate limiting.
A trivial loop could flood app_error_log, obscure real errors,
and degrade DB performance during the campaign.

Fix: check app_error_log for writes from this ip_hash in the last
60 seconds before writing. If count >= 10, exit silently (same 204).
Uses existing table — no schema changes, no new dependencies.
Fails open on rate-limit query error to protect legitimate errors.
Threshold: 10/60s — generous for real browsers (max 3 per page load),
blocks any scripted flood."
git push origin review/session-34
```

## STOP — wait for Thomas to review diff before merging to main
