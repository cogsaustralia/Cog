# CCC Session 39: fix cold path tracking + email_sent_at
# Branch: review/session-39-tracking-fixes
# FILES: _app/api/routes/track.php · _app/api/integrations/mailer.php

## Purpose
Two bugs preventing accurate cold path monitoring:

Bug 1 — track.php: 'seat' and 'seat_inside' missing from $ALLOWED_PATHS.
Every /seat/ and /seat/inside/ visit is silently recorded as path='other'.
The monitor dashboard queries path='seat' and path='seat_inside' — finds zero.
Fix: add both to the allowlist. One line change.

Bug 2 — mailer.php: lead_captures.email_sent_at is never written.
queueEmail() inserts to email_queue but never updates the lead_captures row.
The column exists and is the intended send indicator — nothing writes it.
Fix: after successfully sending a lead_magnet_confirmation email, update
lead_captures SET email_sent_at = UTC_TIMESTAMP() WHERE id = entity_id.

---

## Step 1 — Pull and sync check

```bash
git fetch origin main --quiet
LOCAL=$(git rev-parse HEAD)
REMOTE=$(git rev-parse origin/main)
if [ "$LOCAL" != "$REMOTE" ]; then
  echo "ABORT: local repo is behind origin/main. Pull first."
  exit 1
fi
echo "SYNC OK: $(git log --oneline -1)"
git pull --rebase origin main
```

---

## Step 2 — Ground truth

```bash
echo "=== ALLOWED_PATHS block ==="
grep -A6 "ALLOWED_PATHS = \[" _app/api/routes/track.php

echo "=== seat not in allowlist ==="
grep -c "'seat'" _app/api/routes/track.php

echo "=== email processing loop anchor ==="
grep -n "status = .sent.*attempt_count" _app/api/integrations/mailer.php

echo "=== email_sent_at never written to lead_captures ==="
grep -c "email_sent_at" _app/api/integrations/mailer.php
grep -c "email_sent_at" _app/api/routes/track.php
```

Abort if:
- 'seat' already in track.php (count > 0 means already fixed)
- "status = sent" line not found in mailer.php

---

## Step 3 — Fix track.php: add seat and seat_inside to ALLOWED_PATHS

```bash
python3 << 'PYEOF'
with open('_app/api/routes/track.php') as f:
    content = f.read()

OLD = """$ALLOWED_PATHS = [
    'index', 'intro', 'join', 'thank-you', 'thank-you-business',
    'welcome', 'skeptic', 'tell-me-more', 'vision',
    'landholders', 'gold-cogs', 'businesses', 'community', 'faq',
];"""

NEW = """$ALLOWED_PATHS = [
    'index', 'intro', 'seat', 'seat_inside', 'join', 'thank-you', 'thank-you-business',
    'welcome', 'skeptic', 'tell-me-more', 'vision',
    'landholders', 'gold-cogs', 'businesses', 'community', 'faq',
];"""

count = content.count(OLD)
print(f"Anchor match: {count} (must be 1)")
if count != 1:
    print("ABORT")
    exit(1)

content = content.replace(OLD, NEW)
with open('_app/api/routes/track.php', 'w') as f:
    f.write(content)
print("track.php fixed.")
PYEOF
```

---

## Step 4 — Fix mailer.php: write email_sent_at after lead_magnet_confirmation sends

Insert the lead_captures update immediately after the email_queue sent update,
inside the existing try block, only for lead_magnet_confirmation template.

```bash
python3 << 'PYEOF'
with open('_app/api/integrations/mailer.php') as f:
    content = f.read()

OLD = """            $upd = $db->prepare('UPDATE email_queue SET status = "sent", attempt_count = attempt_count + 1, last_error = NULL, sent_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?');
            $upd->execute([(int)$row['id']]);"""

NEW = """            $upd = $db->prepare('UPDATE email_queue SET status = "sent", attempt_count = attempt_count + 1, last_error = NULL, sent_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?');
            $upd->execute([(int)$row['id']]);
            // Write email_sent_at on lead_captures when the lead magnet confirmation sends.
            // lead_captures.email_sent_at was never updated — this makes it the reliable send indicator.
            if ((string)$row['template_key'] === 'lead_magnet_confirmation'
                && (string)$row['entity_type'] === 'lead_capture'
                && (int)$row['entity_id'] > 0) {
                try {
                    $db->prepare(
                        'UPDATE lead_captures SET email_sent_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ? AND email_sent_at IS NULL'
                    )->execute([(int)$row['entity_id']]);
                } catch (Throwable $leadUpd) {
                    error_log('[mailer/lead_sent_at] ' . $leadUpd->getMessage());
                }
            }"""

count = content.count(OLD)
print(f"Anchor match: {count} (must be 1)")
if count != 1:
    print("ABORT")
    exit(1)

content = content.replace(OLD, NEW)
with open('_app/api/integrations/mailer.php', 'w') as f:
    f.write(content)
print("mailer.php fixed.")
PYEOF
```

---

## Step 5 — PHP lint both files

```bash
php -l _app/api/routes/track.php
php -l _app/api/integrations/mailer.php
```

## STOP — both must show "No syntax errors detected" before proceeding.

---

## Step 6 — Verification

```bash
python3 << 'PYEOF'
checks = []

with open('_app/api/routes/track.php') as f:
    track = f.read()

with open('_app/api/integrations/mailer.php') as f:
    mailer = f.read()

# track.php fixes
checks.append(("track: 'seat' in ALLOWED_PATHS",
    "'seat'" in track and "ALLOWED_PATHS" in track))
checks.append(("track: 'seat_inside' in ALLOWED_PATHS",
    "'seat_inside'" in track))
checks.append(("track: seat before join in list",
    track.index("'seat'") < track.index("'join'")))
checks.append(("track: 'other' fallback still present",
    "'other'" in track))
checks.append(("track: visit action intact",
    "action === 'visit'" in track))
checks.append(("track: event action intact",
    "action === 'event'" in track))
checks.append(("track: pixel response intact",
    "track_pixel_response" in track))
checks.append(("track: page_visits INSERT intact",
    "INSERT INTO page_visits" in track))
checks.append(("track: funnel_events INSERT intact",
    "INSERT INTO funnel_events" in track))
checks.append(("track: session token intact",
    "cogs_st" in track))

# mailer.php fixes
checks.append(("mailer: email_sent_at update present",
    "UPDATE lead_captures SET email_sent_at" in mailer))
checks.append(("mailer: only for lead_magnet_confirmation",
    "template_key'] === 'lead_magnet_confirmation'" in mailer))
checks.append(("mailer: only for lead_capture entity_type",
    "entity_type'] === 'lead_capture'" in mailer))
checks.append(("mailer: entity_id > 0 guard",
    "entity_id'] > 0" in mailer))
checks.append(("mailer: email_sent_at IS NULL guard",
    "email_sent_at IS NULL" in mailer))
checks.append(("mailer: update inside own try/catch",
    "leadUpd" in mailer))
checks.append(("mailer: original queue UPDATE intact",
    'status = \\"sent\\"' in mailer or "status = \"sent\"" in mailer))
checks.append(("mailer: queueEmail function intact",
    "function queueEmail" in mailer))
checks.append(("mailer: smtpSendEmail intact",
    "function smtpSendEmail" in mailer))
checks.append(("mailer: disclosure block intact",
    "Member disclosure section" in mailer))
checks.append(("mailer: no bare apostrophes in disclosure",
    "Foundation's" not in mailer[
        mailer.find("Member disclosure section"):
        mailer.find("Founding phase notice")
    ]))

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

## Step 7 — Commit and push to review branch

Only if ALL PASS and both files pass PHP lint.

```bash
git checkout -b review/session-39-tracking-fixes
git add _app/api/routes/track.php _app/api/integrations/mailer.php
git diff --cached
git commit -m "fix(tracking): seat/seat_inside paths + email_sent_at on lead confirmation

Bug 1 — track.php:
'seat' and 'seat_inside' were missing from \$ALLOWED_PATHS.
Every /seat/ and /seat/inside/ visit was silently filed as path='other'.
Monitor dashboard queries path='seat' — found zero despite real traffic.
Fix: add both to allowlist. All future seat visits recorded correctly.

Bug 2 — mailer.php:
lead_captures.email_sent_at was never written despite column existing.
Fix: after successfully sending lead_magnet_confirmation, update
lead_captures SET email_sent_at = UTC_TIMESTAMP() WHERE id = entity_id
AND email_sent_at IS NULL. Wrapped in own try/catch — never affects
email send success or other queue processing."
git push origin review/session-39-tracking-fixes
```

## STOP — paste full verification output and diff for review before merge.
