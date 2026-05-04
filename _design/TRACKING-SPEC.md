# COG$ of Australia Foundation — Tracking & Analytics Specification
# Version: 1.0 — 4 May 2026
# Maintained by: Webmaster
# Ground truth for all funnel tracking decisions. Update this file whenever
# a new page, source, or UTM value is added. CCC must read this before
# touching any tracking-related file.

---

## Overview

Two tracking mechanisms run across the site:

1. **Page visits** (`track/visit`) — pixel beacon fired on page load, records
   which page was visited, from which source, with which UTM parameters.
   Writes to `page_visits` table.

2. **Funnel events** (`track/event`) — JS events fired at key interaction
   points (form submit, button click, etc). Writes to `funnel_events` table.

Both are unauthenticated, fail-silently, and never block page rendering.

---

## Allowed Paths

These are the only values that will be stored in `page_visits.path`.
Any value not on this list is stored as `other`.

Defined in: `_app/api/routes/track.php` → `$ALLOWED_PATHS`

| Path value     | Page file                        | Funnel role               |
|----------------|----------------------------------|---------------------------|
| `index`        | `index.html`                     | Homepage — warm entry     |
| `intro`        | `intro/index.html`               | Warm path entry card      |
| `seat`         | `seat/index.html`                | Cold path — lead capture  |
| `seat_inside`  | `seat/inside/index.html`         | Cold path — guide page    |
| `join`         | `join/index.html`                | Join form                 |
| `thank-you`    | `thank-you/index.html`           | Post-join confirmation    |
| `thank-you-business` | `thank-you-business/index.html` | Business confirmation |
| `welcome`      | `welcome/index.html`             | Warm path voice entry     |
| `skeptic`      | `skeptic/index.html`             | Objection handling        |
| `tell-me-more` | `tell-me-more/index.html`        | Deep information           |
| `vision`       | `vision/index.html`              | Vision page               |
| `landholders`  | `landholders/index.html`         | Landholder path           |
| `gold-cogs`    | `gold-cogs/index.html`           | Gold COG$ path            |
| `businesses`   | `businesses/index.html`          | Business path             |
| `community`    | `community/index.html`           | Community path            |
| `faq`          | `faq/index.html`                 | FAQ                       |

**Rule:** When adding a new tracked page, add its path value here AND to
`$ALLOWED_PATHS` in `track.php` in the same CCC session. Never one without
the other.

---

## Allowed Ref Sources

These are the only values stored in `page_visits.ref_source`.
Any value not on this list is stored as NULL (treated as direct).

Defined in: `_app/api/routes/track.php` → `$allowedRefs`
Also defined client-side in: `index.html`, `intro/index.html` → `allowedRefs`

| Value    | Meaning                                      |
|----------|----------------------------------------------|
| `fb`     | Facebook / Meta (all FB ad placements)       |
| `yt`     | YouTube (all YT ad placements)               |
| `ig`     | Instagram                                    |
| `tw`     | Twitter / X                                  |
| `li`     | LinkedIn                                     |
| `email`  | Email campaign                               |
| `sms`    | SMS campaign                                 |
| `direct` | Direct / typed URL                           |
| `qr`     | QR code (physical materials)                 |
| `other`  | Known other source not covered above         |

**Note:** `fb` and `yt` are platform-level only. Ad variant distinction
(fb-a vs fb-b, yt-a vs yt-b) is carried by `utm_content`, NOT by `ref`.
See UTM spec below.

---

## UTM Parameter Spec

UTM parameters are passed through from the landing URL and stored in
`page_visits.utm_campaign` and `page_visits.utm_content`.

Max length: 64 chars each. Truncated silently if longer.

### utm_campaign

Identifies the campaign wave. Format: `[name]-D[day offset from launch]`

| Value            | Meaning                                      |
|------------------|----------------------------------------------|
| `fairsay`        | Fair Say Relay organic posts                 |
| `fairsay-D-15`   | Fair Say Relay, Day -15 from Foundation Day  |
| `fairsay-D-10`   | Fair Say Relay, Day -10                      |
| `seat-launch`    | Seat campaign launch                         |

### utm_content

Identifies the specific ad creative or post variant. Used to distinguish
FB ad A vs B, YT video A vs B, etc. This is where platform-level
`ref` becomes variant-level.

| Value      | Meaning                                      |
|------------|----------------------------------------------|
| `fb-a`     | Facebook ad — creative variant A             |
| `fb-b`     | Facebook ad — creative variant B             |
| `yt-a`     | YouTube ad — video variant A                 |
| `yt-b`     | YouTube ad — video variant B                 |
| `ig-a`     | Instagram post / ad — creative variant A     |
| `ig-b`     | Instagram post / ad — creative variant B     |
| `post-1`   | Organic post number 1                        |
| `post-2`   | Organic post number 2                        |

### Campaign Link Library — Ready to Paste

Copy the exact link for each platform and variant. Do not edit the parameters.
These are the canonical links. If a new variant is needed, add it here first.

#### COLD PATH — /seat/ (lead magnet)

**Facebook (Meta)**
```
Variant A: https://cogsaustralia.org/seat/?ref=fb&utm_campaign=seat-launch&utm_content=fb-a
Variant B: https://cogsaustralia.org/seat/?ref=fb&utm_campaign=seat-launch&utm_content=fb-b
```

**YouTube**
```
Variant A: https://cogsaustralia.org/seat/?ref=yt&utm_campaign=seat-launch&utm_content=yt-a
Variant B: https://cogsaustralia.org/seat/?ref=yt&utm_campaign=seat-launch&utm_content=yt-b
```

**Instagram**
```
Variant A: https://cogsaustralia.org/seat/?ref=ig&utm_campaign=seat-launch&utm_content=ig-a
Variant B: https://cogsaustralia.org/seat/?ref=ig&utm_campaign=seat-launch&utm_content=ig-b
```

#### WARM PATH — / (homepage, organic Fair Say posts)

**Facebook (Meta)**
```
Post 1: https://cogsaustralia.org/?ref=fb&utm_campaign=fairsay&utm_content=post-1
Post 2: https://cogsaustralia.org/?ref=fb&utm_campaign=fairsay&utm_content=post-2
Post 3: https://cogsaustralia.org/?ref=fb&utm_campaign=fairsay&utm_content=post-3
```

**YouTube**
```
Post 1: https://cogsaustralia.org/?ref=yt&utm_campaign=fairsay&utm_content=post-1
Post 2: https://cogsaustralia.org/?ref=yt&utm_campaign=fairsay&utm_content=post-2
```

**Instagram**
```
Post 1: https://cogsaustralia.org/?ref=ig&utm_campaign=fairsay&utm_content=post-1
Post 2: https://cogsaustralia.org/?ref=ig&utm_campaign=fairsay&utm_content=post-2
```

#### WARM PATH — /intro/ (intro flow)

**Facebook (Meta)**
```
https://cogsaustralia.org/intro/?ref=fb&utm_campaign=fairsay&utm_content=fb-a
```

**YouTube**
```
https://cogsaustralia.org/intro/?ref=yt&utm_campaign=fairsay&utm_content=yt-a
```

**Instagram**
```
https://cogsaustralia.org/intro/?ref=ig&utm_campaign=fairsay&utm_content=ig-a
```

#### RULE: These links are displayed live in admin/monitor.php Campaign Links panel.
#### If you add a new link here, also add it to the monitor panel (see CCC session 40).

---

## Funnel Event Vocabulary

These are the only event values stored in `funnel_events.event`.
Any unknown event is dropped silently.

Defined in: `_app/api/routes/track.php` → `$ALLOWED_EVENTS`

| Event                  | Fired when                                    | Page          |
|------------------------|-----------------------------------------------|---------------|
| `intro_card_seen`      | Intro card displayed                          | intro         |
| `intro_completed`      | User finishes intro flow                      | intro         |
| `intro_skipped`        | User skips intro                              | intro         |
| `questions_clicked`    | FAQ or questions button clicked               | any           |
| `join_started`         | User begins filling join form                 | join          |
| `join_field_focus`     | First field focused on join form              | join          |
| `join_invite_validated`| Invitation code validated successfully        | join          |
| `join_invite_failed`   | Invitation code validation failed             | join          |
| `join_submitted`       | Join form submitted                           | join          |
| `thankyou_seen`        | Thank-you page loaded                         | thank-you     |
| `stripe_clicked`       | Stripe payment button clicked                 | thank-you     |
| `payid_clicked`        | PayID bank transfer button clicked            | thank-you     |
| `voice_started`        | Voice submission recording started            | welcome       |
| `voice_submitted`      | Voice submission completed                    | welcome       |
| `vault_setup_completed`| Vault onboarding finished                     | wallets       |
| `payment_received`     | Payment confirmed server-side                 | wallets       |

---

## Cold Path Funnel — Monitor Display

The admin monitor `admin/monitor.php` cold path panel maps to these
`page_visits` + `funnel_events` queries:

| Monitor stage      | Source table    | Query                                          |
|--------------------|-----------------|------------------------------------------------|
| Saw /seat/         | page_visits     | `path = 'seat'`, DISTINCT session_token, 7d   |
| Read guide         | page_visits     | `path = 'seat_inside'`, DISTINCT session_token, 7d |
| Email captured     | lead_captures   | `COUNT(*)`, 7d                                 |
| Hit join page      | page_visits     | `path = 'join'`, DISTINCT session_token, 7d   |
| Submitted form     | funnel_events   | `event = 'join_submitted'`, DISTINCT session_token, 7d |
| Paid               | funnel_events   | `event = 'payment_received'`, DISTINCT session_token, 7d |

## Warm Path Funnel — Monitor Display

| Monitor stage      | Source table    | Query                                          |
|--------------------|-----------------|------------------------------------------------|
| Landed (all)       | page_visits     | ALL paths, DISTINCT session_token, 7d          |
| Saw /welcome/      | page_visits     | `path = 'welcome'`, DISTINCT session_token, 7d |
| Saw /intro/        | page_visits     | `path = 'intro'`, DISTINCT session_token, 7d  |
| Hit join page      | page_visits     | `path = 'join'`, DISTINCT session_token, 7d   |
| Started join       | funnel_events   | `event = 'join_started'`, DISTINCT session_token, 7d |
| Submitted form     | funnel_events   | `event = 'join_submitted'`, DISTINCT session_token, 7d |
| Paid               | funnel_events   | `event = 'payment_received'`, DISTINCT session_token, 7d |

---

## Pages by Source Matrix — Monitor Display

The matrix in admin/monitor.php is built from `source_per_path` which
queries `page_visits` grouped by `path` AND `ref_source` (7d window).

Columns = all distinct `ref_source` values seen in the window.
Rows = all distinct `path` values seen in the window.

**Expected rows** (once traffic is flowing correctly after session 39):
- `index` — homepage visitors
- `intro` — intro page visitors
- `seat` — cold path lead magnet page
- `seat_inside` — cold path guide page
- `join` — join form
- `thank-you` — post-join

**Expected columns:**
- `fb` — Facebook/Meta traffic
- `yt` — YouTube traffic
- `direct` — typed URL / no ref
- Any other ref source with visits in the window

**Important:** FB ad variant A vs B distinction is NOT shown in this matrix
(both are `fb`). To see variant breakdown, query `utm_content` directly:

```sql
SELECT utm_content, COUNT(DISTINCT session_token) AS sessions
FROM page_visits
WHERE path = 'seat'
  AND ref_source = 'fb'
  AND visited_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY
GROUP BY utm_content
ORDER BY sessions DESC;
```

---

## Historical Data Note — 4 May 2026

All visits to `/seat/` and `/seat/inside/` before session 39 was deployed
were recorded as `path = 'other'` due to a missing allowlist entry. This
affects data from campaign launch through approximately 22:00 AEST 4 May 2026.

Historical correction query (run once after session 39 deploy, safe because
`other` rows in this window are all seat traffic — campaign had not started
on other paths):

```sql
-- ONLY run this if page_visits has no legitimate 'other' rows from non-seat pages.
-- Verify first: SELECT DISTINCT path FROM page_visits WHERE path = 'other';
-- If all 'other' rows are from the seat campaign window, this is safe.

-- DO NOT run without Thomas approval. Document run date here if executed.
-- UPDATE page_visits
-- SET path = 'seat'
-- WHERE path = 'other'
--   AND visited_at BETWEEN '2026-05-02 00:00:00' AND '2026-05-04 22:00:00';
```

This query is commented out deliberately. Uncomment and run only with
explicit approval after verifying no legitimate 'other' traffic exists.

---

## Rules for Future Changes

1. Adding a new tracked page: add to `$ALLOWED_PATHS` in track.php AND to
   this document in the same session. Never one without the other.

2. Adding a new ref source: add to `$allowedRefs` in track.php AND in the
   client-side `allowedRefs` array in every page that uses it, AND to this
   document.

3. Adding a new funnel event: add to `$ALLOWED_EVENTS` in track.php AND to
   this document.

4. New ad campaign: define utm_campaign and utm_content values here BEFORE
   creating ad links. Consistency in UTM values is required for accurate
   attribution.

5. Never create ad links without ref + utm_content populated. A link to
   /seat/ from a Facebook ad must include `?ref=fb&utm_content=fb-a` (or
   whichever variant). Without this, the visit is attributed to null/direct
   and is invisible in the source matrix.
