# cogs-error-digest

Monitors the application error log and alerts Thomas via Telegram when
errors need attention. Companion to the email digest cron — Telegram
gives the same information as a push notification so nothing waits until
Thomas opens his email.

## Trigger

Two schedules:

- `5 * * * *`  — Hourly check (fires at :05 past each hour, every hour)
- `0 21 * * *` — Daily summary (07:00 AEST = 21:00 UTC), every day

Active: always. No expiry.

## Model

claude-sonnet-4-6

## Mode

READ and NOTIFY only. One HTTPS GET to the admin error summary endpoint.
No file writes except journal. No DB access directly.

## Endpoint

URL: `https://cogsaustralia.org/admin/monitor.php?ajax=admin-summary`
Method: GET
Auth: send session cookie `cogs_admin_session` stored in
`~/.cogs-secrets/admin-session.cookie` (chmod 600).

If the cookie file is missing or the endpoint returns non-200, send:
`cogs-error-digest: Cannot reach admin-summary endpoint. Check manually.`
Then stop.

## Expected response fields

```json
{
  "data": {
    "unack_errors": 3,
    "recent_errors": [
      {
        "route": "client-error:seat/index.html",
        "http_status": 0,
        "msg": "Cannot read properties of null...",
        "area_key": "client-js",
        "created_at": "2026-05-15 08:43:11"
      }
    ]
  }
}
```

`http_status = 0` means a browser JS error. Any other value is a server error.

## Alert logic

### Hourly check

Read `unack_errors` from the response.

If `unack_errors = 0`: silent. Log OK to journal. Done.

If `unack_errors > 0`:
- Check journal for a ERRORS ACTIVE alert in the last 50 minutes.
- If found AND unack_errors has not increased: silent (already notified).
- If not found OR count has increased: send Telegram alert (see format below).

### Daily summary (07:00 AEST)

Always send — even if zero errors. The daily message is a status report.

## Telegram message formats

### Hourly alert (new errors detected)

```
ERROR ALERT [HH:MM AEST]
N unacknowledged error(s) on cogsaustralia.org

<for each error in recent_errors, max 5>
[JS] client-error:seat/index.html
Cannot read properties of null (22 chars truncated)
2026-05-15 08:43

[500] vault/member
Server error: ... (truncated to 100 chars)
2026-05-15 08:45

Action: Acknowledge at admin/errors.php
```

Label rules:
- http_status = 0 → label is [JS]
- http_status >= 500 → label is [500] in red (bold)
- http_status 4xx → label is [404] etc

### Daily summary — errors present

```
DAILY ERROR REPORT [07:00 AEST Mon DD MMM]
Last 24h: use unack_errors as proxy
Unacknowledged: N

Top errors: (list route and short message for each in recent_errors)
Action: Review and acknowledge at admin/errors.php
```

### Daily summary — clean

```
DAILY ERROR REPORT [07:00 AEST Mon DD MMM]
All clear. No unacknowledged errors.
```

## Steps

### 1. Read admin session cookie

Read `~/.cogs-secrets/admin-session.cookie`.
If missing: send `cogs-error-digest: admin-session.cookie missing.` Then stop.

### 2. Fetch admin-summary endpoint

GET `https://cogsaustralia.org/admin/monitor.php?ajax=admin-summary`
with the session cookie as a Cookie header.

Timeout: 10 seconds.
If non-200 or invalid JSON: send connection error message. Then stop.

### 3. Extract fields

Read `data.unack_errors` (integer) and `data.recent_errors` (array).

### 4. Apply alert logic

Follow the alert logic section above exactly.

### 5. Send to Telegram

Use the message format matching the trigger and condition.

### 6. Log to journal

Hourly check:
`[YYYY-MM-DD HH:MM AEST] ERROR-DIGEST/hourly: unack=N — ALERTED` or `OK`

Daily:
`[YYYY-MM-DD HH:MM AEST] ERROR-DIGEST/daily: unack=N — SENT`

## Error handling

- Endpoint timeout (10s): send `cogs-error-digest: Endpoint timeout. Site may be down.`
- Three consecutive timeouts: disable and alert Thomas.
- JSON parse failure: send `cogs-error-digest: Invalid response from admin-summary.`
- Never crash silently — always log to journal before exiting.

## Dependency note

Requires session 32 to be merged (admin-summary AJAX endpoint on monitor.php).
Requires the admin session cookie to be generated and stored at
`~/.cogs-secrets/admin-session.cookie`.

To generate the cookie: log in to admin panel in a browser, export the
`cogs_admin_session` cookie value, write it to the secrets file:
`echo "cogs_admin_session=<value>" > ~/.cogs-secrets/admin-session.cookie && chmod 600 ~/.cogs-secrets/admin-session.cookie`
