# cogs-queue-monitor

Polls the live lead capture status via a read-only HTTPS endpoint.
Alerts Thomas if new leads are waiting for follow-up or going stale.
Silent when everything is within normal bounds.

## Trigger

Cron: `0 7,9,11,13,15,17,19 * * *`
Fires every two hours from 07:00 to 19:00 AEST.
Active from now through D+14 (28 May 2026).

## Model

claude-sonnet-4-6

## Mode

READ and NOTIFY only. One HTTPS GET call to the lead status endpoint.
No DB access. No file writes except journal.

## Endpoint

URL: `https://cogsaustralia.org/admin/api/lead_status.php`
Method: GET
Header: `Authorization: Bearer <COGS_QUEUE_API_KEY>`

The API key is stored at `~/.cogs-secrets/queue-api.key` (chmod 600).
Read the key from that file at runtime. Never hardcode it here.

## Expected response (JSON)

{
  "total_leads": 42,
  "new_today": 7,
  "uncontacted": 3,
  "oldest_uncontacted_seconds": 14400,
  "has_phone": 18,
  "generated_at": "2026-05-08T14:00:00+10:00"
}

Fields:
- total_leads: all records in lead_captures
- new_today: leads captured today (created_at date = today)
- uncontacted: leads with no follow-up recorded (contacted_at IS NULL)
- oldest_uncontacted_seconds: age in seconds of the oldest uncontacted lead
- has_phone: leads who completed the /seat/inside/ phone capture step

## Alert thresholds

| Condition | Threshold | Message |
|---|---|---|
| Stale uncontacted lead | oldest_uncontacted_seconds > 14400 (4 hours) | LEAD WAITING |
| Backlog building | uncontacted > 5 | LEAD BACKLOG |
| New lead arrived | new_today > 0 (first check after lead arrives) | NEW LEAD |

## Steps

### 1. Read API key

Read `~/.cogs-secrets/queue-api.key`. If file is missing or empty, send:
`cogs-queue-monitor: queue-api.key missing. Cannot check leads.`
Then stop.

### 2. Call endpoint

Make one HTTPS GET request with the Authorization header.
If HTTP response is not 200, send:
`cogs-queue-monitor: lead_status.php returned <status_code>. Check server.`
If response is not valid JSON, send:
`cogs-queue-monitor: Invalid JSON from lead_status.php.`
Then stop.

### 3. Evaluate thresholds

Check all three conditions. Collect alerts for each threshold exceeded.

### 4. Deduplicate

Before sending any LEAD WAITING or LEAD BACKLOG alert, check
`~/openclaw-cogs-journal.md` for the same alert type in the last 4 hours.
Suppress if found. Exception: if uncontacted > 10, override and always alert.

NEW LEAD alerts: send once per detection. Do not suppress.

### 5. Send to Telegram

If thresholds breached:

  LEAD REPORT [HH:MM AEST]
  Total: N  |  New today: N  |  With phone: N
  Uncontacted: N  |  Oldest waiting: Nh Mm
  Alert: <type>: <one-line description>
  Action: <one-line instruction>

If new lead only:

  NEW LEAD [HH:MM AEST]
  Total: N leads  |  New today: N
  Oldest uncontacted: Nh Mm
  Action: Follow up personally. Check admin panel for details.

Silence if nothing breached.

### 6. Log to journal

[YYYY-MM-DD HH:MM AEST] LEAD CHECK: total=N new_today=N uncontacted=N phone=N: <ALERT or OK>

## Error handling

Timeout after 10 seconds = connection error.
Send: `cogs-queue-monitor: Endpoint timeout. Site may be down.`
Three consecutive timeouts: disable this skill and alert Thomas.

## Dependency note

Requires `admin/api/lead_status.php` deployed on the server.
If endpoint does not exist, skill will alert on every run.
Build as a CCC session before activating.
