# CCC Session 31: client-error beacon — surface browser JS errors in app_error_log
# Branch: review/session-31
# FILES: _app/api/routes/client-error.php · _app/api/index.php · assets/site.js

## Purpose
Users hitting JS errors in their browser produce zero signal in the current monitoring
stack. This session adds a lightweight client-side error beacon that:
  1. Catches window.onerror + unhandledrejection in site.js
  2. POSTs to a new /api/client-error endpoint (rate-limited to 3 per page load)
  3. Writes to app_error_log with http_status=0 (distinguishes client errors from server errors)
Admin errors.php already displays app_error_log — no changes needed there.

---

## Step 1 — Pull and ground truth

```bash
git pull --rebase origin main
echo "=== index.php routing block ==="
grep -n "case 'lead-capture'\|case 'designations'\|default:" _app/api/index.php
echo "=== app_error_log schema check ==="
grep -n "http_status" sql/hub_monitor_queries_v1.sql | head -3
echo "=== site.js tail ==="
tail -12 assets/site.js
echo "=== site.js line count ==="
wc -l assets/site.js
```

Confirm before proceeding:
- `case 'lead-capture':` and `case 'designations':` both exist in index.php
- `http_status  SMALLINT` exists in the SQL schema (confirms 0 is valid)
- site.js ends with the closing `})();` pattern visible in tail output

---

## Step 2 — Create _app/api/routes/client-error.php

```bash
python3 << 'PYEOF'
content = r'''<?php
/**
 * client-error.php — Browser JS error beacon.
 *
 *   POST /_app/api/index.php/client-error
 *   Body: {
 *     "message":  string   error message (required)
 *     "source":   string   script URL
 *     "line":     int      line number
 *     "col":      int      column number
 *     "stack":    string   stack trace (from Error.stack)
 *     "page":     string   window.location.pathname (truncated)
 *     "ua_hint":  string   first 80 chars of navigator.userAgent
 *   }
 *
 * Writes to app_error_log with http_status=0 to distinguish from server errors.
 * No authentication required. Rate-limiting is enforced client-side (3 per page load).
 * Fails silently — never returns an error to the browser.
 * IP and UA are hashed before storage. No raw PII stored.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

// Always return 204 No Content — browser beacons do not need a response body
http_response_code(204);
header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    exit;
}

try {
    $raw  = (string)file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        exit;
    }

    $message = substr(trim((string)($body['message'] ?? '')), 0, 4000);
    if ($message === '') {
        exit;
    }

    $source  = substr(trim((string)($body['source']  ?? '')), 0, 255)  ?: null;
    $line    = isset($body['line'])  ? (int)$body['line']  : null;
    $col     = isset($body['col'])   ? (int)$body['col']   : null;
    $stack   = substr(trim((string)($body['stack']   ?? '')), 0, 4000) ?: null;
    $page    = substr(trim((string)($body['page']    ?? '')), 0, 120)  ?: null;

    // Build a readable error message including location context
    $fullMessage = $message;
    if ($source !== null || $line !== null) {
        $loc = array_filter([
            $source,
            $line  !== null ? 'L' . $line  : null,
            $col   !== null ? 'C' . $col   : null,
        ]);
        if ($loc) {
            $fullMessage .= ' [' . implode(':', $loc) . ']';
        }
    }
    if ($stack !== null) {
        $fullMessage .= "\n" . $stack;
    }
    $fullMessage = substr($fullMessage, 0, 4000);

    // Route label: "client-error" + page path for grouping in errors.php
    $route = 'client-error' . ($page !== null ? ':' . $page : '');
    $route = substr($route, 0, 120);

    // Hash IP and UA — same privacy posture as server-side errors
    $rawIp = $_SERVER['REMOTE_ADDR'] ?? '';
    $rawUa = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ipHash = $rawIp !== '' ? hash('sha256', $rawIp) : null;
    $uaHash = $rawUa !== '' ? hash('sha256', $rawUa) : null;

    $db = getDB();
    $db->prepare(
        "INSERT INTO app_error_log
           (route, http_status, error_message, area_key,
            member_id, request_method, ip_hash, ua_hash, created_at)
         VALUES (?, 0, ?, ?, NULL, 'GET', ?, ?, NOW())"
    )->execute([
        $route,
        $fullMessage,
        'client-js',
        $ipHash,
        $uaHash,
    ]);

} catch (Throwable $e) {
    // Silent fail — never expose errors to the browser
    error_log('[client-error beacon] ' . $e->getMessage());
}

exit;
'''
with open('_app/api/routes/client-error.php', 'w') as f:
    f.write(content)
print(f"Written: {len(content)} bytes")
PYEOF
```

---

## Step 3 — Register route in _app/api/index.php

Add `case 'client-error':` immediately before `case 'lead-capture':`.

```bash
python3 << 'PYEOF'
with open('_app/api/index.php', 'r') as f:
    content = f.read()

old = "        case 'lead-capture':\n            require __DIR__ . '/routes/lead-capture.php';\n            break;"
new = "        case 'client-error':\n            require __DIR__ . '/routes/client-error.php';\n            break;\n        case 'lead-capture':\n            require __DIR__ . '/routes/lead-capture.php';\n            break;"

count = content.count(old)
print(f"Match count for insertion point: {count} (must be 1)")
if count == 1:
    content = content.replace(old, new)
    with open('_app/api/index.php', 'w') as f:
        f.write(content)
    print("Route registered.")
else:
    print("ABORT: match count wrong — do not write")
PYEOF
```

---

## Step 4 — Add error beacon to assets/site.js

Append the beacon IIFE after the final closing line of site.js.
The beacon:
- Catches window.onerror and unhandledrejection
- Rate-limits to 3 reports per page load (client-side guard)
- Uses sendBeacon (fire-and-forget, survives page unload)
- Falls back to fetch if sendBeacon unavailable
- Derives the API endpoint from window.COGS_ROOT (same pattern as site.min.js)
- Never throws — all logic wrapped in try/catch

```bash
python3 << 'PYEOF'
BEACON = r"""

// ─── Client-side JS error beacon ─────────────────────────────────────────────
// Posts uncaught JS errors to /api/client-error for visibility in admin/errors.php
// Rate-limited to 3 per page load. Silent fail. No PII. Uses sendBeacon.
(function () {
  'use strict';
  var MAX_REPORTS = 3;
  var reported = 0;

  function apiRoot() {
    var root = window.COGS_ROOT || './';
    return root + '_app/api/index.php/client-error';
  }

  function send(payload) {
    if (reported >= MAX_REPORTS) return;
    reported++;
    try {
      var body = JSON.stringify(payload);
      if (navigator.sendBeacon) {
        navigator.sendBeacon(apiRoot(), new Blob([body], { type: 'application/json' }));
      } else {
        fetch(apiRoot(), { method: 'POST', body: body, keepalive: true,
          headers: { 'Content-Type': 'application/json' } }).catch(function () {});
      }
    } catch (e) { /* silent */ }
  }

  window.onerror = function (msg, src, line, col, err) {
    send({
      message: String(msg || '').slice(0, 500),
      source:  String(src  || '').slice(0, 255),
      line:    line  || null,
      col:     col   || null,
      stack:   err && err.stack ? String(err.stack).slice(0, 2000) : null,
      page:    window.location.pathname.slice(0, 120),
      ua_hint: (navigator.userAgent || '').slice(0, 80)
    });
    return false; // don't suppress default browser handling
  };

  window.addEventListener('unhandledrejection', function (e) {
    var reason = e && e.reason;
    var msg = reason instanceof Error ? reason.message : String(reason || 'Unhandled promise rejection');
    var stack = reason instanceof Error && reason.stack ? String(reason.stack).slice(0, 2000) : null;
    send({
      message: msg.slice(0, 500),
      source:  null,
      line:    null,
      col:     null,
      stack:   stack,
      page:    window.location.pathname.slice(0, 120),
      ua_hint: (navigator.userAgent || '').slice(0, 80)
    });
  });
})();
"""

with open('assets/site.js', 'r') as f:
    content = f.read()

# Guard: do not append twice
if 'Client-side JS error beacon' in content:
    print("SKIP: beacon already present in site.js")
else:
    with open('assets/site.js', 'a') as f:
        f.write(BEACON)
    print(f"Appended beacon ({len(BEACON)} bytes) to assets/site.js")
PYEOF
```

---

## Step 5 — Verification

```bash
python3 << 'PYEOF'
import re

checks = []

# 1. Route file exists and has required strings
with open('_app/api/routes/client-error.php') as f:
    php = f.read()
checks.append(('PHP: http_status=0 in INSERT',       "http_status, error_message" in php and ', 0, ' in php))
checks.append(('PHP: area_key = client-js',          "'client-js'" in php))
checks.append(('PHP: ip_hash via sha256',            "hash('sha256'" in php))
checks.append(('PHP: 204 response code',             'http_response_code(204)' in php))
checks.append(('PHP: silent fail catch block',        'Silent fail' in php or 'silent fail' in php.lower()))

# 2. Route registered in index.php
with open('_app/api/index.php') as f:
    idx = f.read()
checks.append(("index.php: case 'client-error' present",  "case 'client-error':" in idx))
checks.append(("index.php: routes/client-error.php",      "routes/client-error.php" in idx))
checks.append(("index.php: client-error before lead-capture",
    idx.index("case 'client-error':") < idx.index("case 'lead-capture':")))

# 3. Beacon in site.js
with open('assets/site.js') as f:
    js = f.read()
checks.append(('site.js: beacon IIFE present',       'Client-side JS error beacon' in js))
checks.append(('site.js: window.onerror assigned',   'window.onerror = function' in js))
checks.append(('site.js: unhandledrejection listener', "addEventListener('unhandledrejection'" in js))
checks.append(('site.js: sendBeacon used',           'sendBeacon' in js))
checks.append(('site.js: MAX_REPORTS = 3',           'MAX_REPORTS = 3' in js))
checks.append(('site.js: rate limit guard present',  'reported >= MAX_REPORTS' in js))

# 4. Structure check on index.php
for tag in ['switch ($route)', 'default:', 'catch (Throwable $e)']:
    checks.append((f'index.php: {tag!r} intact', tag in idx))

all_pass = True
for label, ok in checks:
    status = 'PASS' if ok else 'FAIL'
    if not ok:
        all_pass = False
    print(f"[{status}] {label}")

print()
print("ALL PASS" if all_pass else "FAILURES DETECTED — do not commit")
PYEOF
```

---

## Step 6 — Commit and push to review branch

Only proceed if verification above shows ALL PASS.

```bash
git checkout -b review/session-31
git add _app/api/routes/client-error.php _app/api/index.php assets/site.js
git diff --cached --stat
git commit -m "feat(monitoring): client-side JS error beacon -> app_error_log

- New route: client-error — accepts POST from browser, writes to app_error_log
  with http_status=0 to distinguish client errors from server errors
- Registered in API router before lead-capture
- window.onerror + unhandledrejection handlers appended to assets/site.js
- Rate-limited to 3 reports per page load (client-side guard)
- Uses sendBeacon (fire-and-forget), falls back to fetch
- IP and UA hashed before storage — same privacy posture as server errors
- Silent fail on both server and client — never breaks page rendering"
git push origin review/session-31
```

## STOP — wait for review before merging to main
