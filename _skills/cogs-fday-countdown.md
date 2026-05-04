# cogs-fday-countdown

Twice-daily Foundation Day countdown.
One short Telegram message each morning and evening until D+14.

## Trigger

Cron: `0 7,19 * * *`
Fires at 07:00 and 19:00 AEST every day.
Active from D-15 (29 Apr 2026) through D+14 (28 May 2026).
Outside that window, send nothing.

## Model

claude-sonnet-4-6

## Mode

READ and NOTIFY only. No writes. No social posts.

## Steps

### 1. Calculate countdown

Foundation Day is Thursday 14 May 2026 at 17:00 AEST.
Get the current datetime in Australia/Sydney timezone.
Calculate:
- Whole days remaining to Foundation Day (floor, not round)
- Hours remaining after subtracting whole days
- Whether today IS Foundation Day (D-0)
- Whether Foundation Day has passed (D+1 or later)

### 2. Identify next milestone

Read `_private/CAMPAIGN.md` and scan for the next milestone that has not yet passed.
The milestone is the earliest dated item in CAMPAIGN.md that is still in the future.

If CAMPAIGN.md cannot be read, fall back to:
- "Foundation Day Livestream (D-0, Thu 14 May, 5:00pm AEST)"

### 3. Format message

Before Foundation Day (D-15 to D-1):
  [D-XX] Foundation Day in N days, M hours.
  Next: <milestone name> on <date>.

Foundation Day morning (D-0, 07:00 run):
  TODAY IS FOUNDATION DAY.
  Livestream goes live at 5:00pm AEST.

Foundation Day evening (D-0, 19:00 run):
  Foundation Day complete.
  Stage 6 sustain phase begins tomorrow.

After Foundation Day (D+1 to D+14):
  [D+XX] Stage 6 sustain. N days since Foundation Day.
  Campaign closes <date>. Next: <milestone>.

Keep all messages under 200 characters.

### 4. Send to Telegram

Send one message. No journal entry required.

## Error handling

If calculation fails for any reason, send:
`cogs-fday-countdown: Failed to calculate countdown. Check CAMPAIGN.md dates.`
Do not retry. Wait for the next scheduled run.
