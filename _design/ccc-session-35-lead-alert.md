# CCC Session 35: instant email alert to Thomas on new lead capture
# Branch: review/session-35
# FILES: _app/api/routes/lead-capture.php

## Purpose
There is no proactive alerting when a new lead submits on /seat/.
Thomas must check admin/monitor.php manually to know a lead arrived.
In the 10 days before Foundation Day, a lead going cold costs real members.

This session adds a direct email to ThomasC@cogsaustralia.org the moment
a new lead is captured — same request, same PHP process, after the
confirmation email is queued. Fires only on genuine new leads (rowCount=1),
never on duplicate email updates. Uses existing smtpSendEmail() — no new
dependencies, no schema changes, one file only.

---

## Step 1 — Pull and ground truth

```bash
git pull --rebase origin main

echo "=== lead-capture.php structure ==="
grep -n "rowCount\|queueEmail\|smtpSendEmail\|mailer\|require" _app/api/routes/lead-capture.php

echo "=== confirm insertion anchor exists exactly once ==="
grep -c "Only send confirmation on first capture" _app/api/routes/lead-capture.php

echo "=== confirm mailer already required in this block ==="
grep -c "require.*mailer.php" _app/api/routes/lead-capture.php

echo "=== confirm smtpSendEmail exists in mailer ==="
grep -c "function smtpSendEmail" _app/api/integrations/mailer.php

echo "=== confirm mailerEnabled exists ==="
grep -c "function mailerEnabled" _app/api/integrations/mailer.php

echo "=== MAIL_ADMIN_EMAIL constant ==="
grep "MAIL_ADMIN_EMAIL" _app/api/config/bootstrap.php
```

Abort if:
- "Only send confirmation on first capture" count != 1
- smtpSendEmail count = 0
- mailerEnabled count = 0

---

## Step 2 — Insert lead alert

Add the Thomas alert immediately after the queueEmail() call,
still inside the if ($stmt->rowCount() === 1 && $leadId > 0) block.
mailer.php is already required at that point.

```bash
python3 << 'PYEOF'
with open('_app/api/routes/lead-capture.php') as f:
    content = f.read()

OLD = """        queueEmail(
            $db,
            'lead_capture',
            $leadId,
            $email,
            'lead_magnet_confirmation',
            'Your free guide - a seat at the table',
            [
                'email'    => $email,
                'guide_url'=> 'https://cogsaustralia.org/seat/inside/',
            ]
        );
    }"""

NEW = """        queueEmail(
            $db,
            'lead_capture',
            $leadId,
            $email,
            'lead_magnet_confirmation',
            'Your free guide - a seat at the table',
            [
                'email'    => $email,
                'guide_url'=> 'https://cogsaustralia.org/seat/inside/',
            ]
        );

        // Instant alert to Thomas — fires only on genuine new leads.
        // Uses smtpSendEmail directly (not the queue) so Thomas is
        // notified in the same request, not delayed by cron.
        try {
            if (mailerEnabled()) {
                $hasPhone  = $phone !== '' ? 'Yes' : 'No';
                $srcLabel  = $source !== '' ? $source : 'direct';
                $pageLabel = $page   !== '' ? $page   : 'unknown';
                $alertTo   = 'ThomasC@cogsaustralia.org';
                $subject   = '[COGS] New lead #' . $leadId . ' — ' . $srcLabel;
                $html = '<p><strong>New lead captured on cogsaustralia.org/seat/</strong></p>'
                    . '<table style="font-family:Arial,sans-serif;font-size:0.9em;border-collapse:collapse;">'
                    . '<tr><td style="padding:4px 12px 4px 0;color:#64748b;">Lead ID</td><td><strong>#' . $leadId . '</strong></td></tr>'
                    . '<tr><td style="padding:4px 12px 4px 0;color:#64748b;">Email</td><td>' . htmlspecialchars(substr($email, 0, 3)) . '***@' . htmlspecialchars(explode('@', $email)[1] ?? '') . '</td></tr>'
                    . '<tr><td style="padding:4px 12px 4px 0;color:#64748b;">Phone</td><td>' . $hasPhone . '</td></tr>'
                    . '<tr><td style="padding:4px 12px 4px 0;color:#64748b;">Source</td><td>' . htmlspecialchars($srcLabel) . '</td></tr>'
                    . '<tr><td style="padding:4px 12px 4px 0;color:#64748b;">Page</td><td>' . htmlspecialchars($pageLabel) . '</td></tr>'
                    . '<tr><td style="padding:4px 12px 4px 0;color:#64748b;">Time</td><td>' . date('Y-m-d H:i:s T') . '</td></tr>'
                    . '</table>'
                    . '<p style="margin-top:16px;"><a href="https://cogsaustralia.org/admin/monitor.php" style="background:#1e293b;color:#fff;padding:8px 16px;border-radius:4px;text-decoration:none;font-weight:bold;">View all leads</a></p>';
                $text = "New lead captured on cogsaustralia.org/seat/\n"
                    . "Lead ID: #" . $leadId . "\n"
                    . "Email: " . substr($email, 0, 3) . "***@" . (explode('@', $email)[1] ?? '') . "\n"
                    . "Phone: " . $hasPhone . "\n"
                    . "Source: " . $srcLabel . "\n"
                    . "Page: " . $pageLabel . "\n"
                    . "Time: " . date('Y-m-d H:i:s T') . "\n"
                    . "View leads: https://cogsaustralia.org/admin/monitor.php";
                smtpSendEmail($alertTo, $subject, $html, $text);
            }
        } catch (Throwable $alertEx) {
            // Silent fail — lead is already saved. Alert failure must never
            // affect the lead capture response or the visitor experience.
            error_log('[lead-capture alert] ' . $alertEx->getMessage());
        }
    }"""

count = content.count(OLD)
print(f"Anchor match: {count} (must be 1)")
if count != 1:
    print("ABORT")
    exit(1)

content = content.replace(OLD, NEW)
with open('_app/api/routes/lead-capture.php', 'w') as f:
    f.write(content)
print("Lead alert block inserted.")
PYEOF
```

---

## Step 3 — Verification

```bash
python3 << 'PYEOF'
with open('_app/api/routes/lead-capture.php') as f:
    content = f.read()

checks = [
    # Alert block present and correct
    ('Alert fires only on rowCount=1',          content.index('ThomasC@cogsaustralia.org') > content.index('rowCount() === 1')),
    ('Alert after queueEmail',                  content.index('ThomasC@cogsaustralia.org') > content.index('queueEmail(')),
    ('mailerEnabled guard present',             'mailerEnabled()' in content),
    ('smtpSendEmail called directly',           'smtpSendEmail($alertTo' in content),
    ('Lead ID in subject',                      "'[COGS] New lead #'" in content),
    ('Email masked in alert',                   'substr($email, 0, 3)' in content),
    ('Phone captured in alert',                 'has_phone' in content.lower() or '$hasPhone' in content),
    ('Source in alert',                         '$srcLabel' in content),
    ('Silent fail on alert error',              "[lead-capture alert]" in content),
    ('Alert inside try/catch',                  'catch (Throwable $alertEx)' in content),
    ('Alert never affects response',            'never' in content and 'visitor experience' in content),
    ('admin/monitor.php link in email',         'admin/monitor.php' in content),

    # Original functionality untouched
    ('INSERT query intact',                     'INSERT INTO lead_captures' in content),
    ('ON DUPLICATE KEY UPDATE intact',          'ON DUPLICATE KEY UPDATE' in content),
    ('queueEmail still called',                 'queueEmail(' in content),
    ('lead_magnet_confirmation intact',         'lead_magnet_confirmation' in content),
    ('200 success response intact',             "json_encode(['success' => true" in content),
    ('Error handler intact',                    "[lead-capture]" in content),
    ('Email validation intact',                 'FILTER_VALIDATE_EMAIL' in content),
    ('declare strict_types intact',             'declare(strict_types=1)' in content),
]

all_pass = True
for label, ok in checks:
    s = 'PASS' if ok else 'FAIL'
    if not ok: all_pass = False
    print(f'[{s}] {label}')

print()
print('ALL PASS' if all_pass else 'FAILURES DETECTED — do not commit')
PYEOF
```

---

## Step 4 — Commit and push to review branch

Only if ALL PASS above.

```bash
git checkout -b review/session-35
git add _app/api/routes/lead-capture.php
git diff --cached
git commit -m "feat(leads): instant email alert to Thomas on new lead capture

No proactive alerting existed when a new lead submitted on /seat/.
Thomas had to check admin/monitor.php manually.

Fix: send direct smtpSendEmail to ThomasC@cogsaustralia.org
immediately on every genuine new lead (rowCount=1 only, never
on duplicate email updates).

Email includes: lead ID, masked email, phone y/n, source, page,
timestamp, and direct link to admin/monitor.php.
Silent fail — alert failure never affects lead save or visitor response.
No schema changes. No new dependencies. One file only."
git push origin review/session-35
```

## STOP — wait for Thomas to review diff before merging to main
