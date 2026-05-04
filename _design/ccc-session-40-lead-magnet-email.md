# CCC Session 40 -- Fix lead_magnet_confirmation email template
# The confirmation email currently sends the lead back to /seat/inside/
# which is the page they just came from. The promised deliverable is the
# CEO Power Cheat Sheet on Substack. Fix the template to deliver what
# was promised: Cheat Sheet first, Seat at the Table guide second.
# Branch: review/session-40-lead-magnet-email
# Commit: fix(email): lead_magnet_confirmation delivers CEO Power Cheat Sheet

## GROUND TRUTH

git log --oneline -3
echo "---"
grep -n "lead_magnet_confirmation" _app/api/integrations/mailer.php

## STOP -- paste output. Confirm line number of lead_magnet_confirmation.

## STEP 1 -- Branch

git checkout -b review/session-40-lead-magnet-email

## STEP 2 -- Replace the lead_magnet_confirmation template

## The old block to replace (exact match required):

python3 /dev/stdin << 'PYEOF'
with open('_app/api/integrations/mailer.php', 'r') as f:
    c = f.read()

old = (
    "        'lead_magnet_confirmation' => (function() use ($p) {\n"
    "            $guideUrl  = $p['guide_url']  ?? 'https://cogsaustralia.org/seat/inside/';\n"
    "            $site      = 'https://cogsaustralia.org';\n"
    "\n"
    "            $html = '<!DOCTYPE html><html><body style=\"font-family:sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1a1a1a;\">'\n"
    "                . '<p style=\"font-size:18px;font-weight:bold;\">Your free guide is ready.</p>'\n"
    "                . '<p>You asked for the guide. Here it is.</p>'\n"
    "                . '<p><a href=\"' . htmlspecialchars($guideUrl) . '\" style=\"display:inline-block;background:#b8860b;color:#fff;padding:12px 24px;text-decoration:none;border-radius:4px;font-weight:bold;\">Read: Seat at the Table &rarr;</a></p>'\n"
    "                . '<p>It explains four things. How one share gets you into the room. Why ESG laws put a dollar value on your voice. What words open the door with a mining company. And why COG$ multiplies all of it.</p>'\n"
    "                . '<p>Five minutes. Plain English. No jargon.</p>'\n"
    "                . '<p>You can join now for $4 at <a href=\"https://cogsaustralia.org/join\">cogsaustralia.org/join</a>. Foundation Day is <strong>14 May 2026</strong> at 5pm AEST &mdash; that is when the first community vote happens.</p>'\n"
    "                . '<p style=\"margin-top:32px;\">Thomas<br>COG$ of Australia Foundation<br>Drake Village NSW &nbsp;|&nbsp; Wahlubal Country, Bundjalung Nation</p>'\n"
    "                . '<p style=\"font-size:11px;color:#888;margin-top:24px;\">You received this because you asked for the free guide at cogsaustralia.org. Reply to this email to unsubscribe.</p>'\n"
    "                . '</body></html>';\n"
    "\n"
    "            $plain = \"Your free guide is ready.\\n\\n\"\n"
    "                . \"You asked for the guide. Here it is:\\n\"\n"
    "                . $guideUrl . \"\\n\\n\"\n"
    "                . \"It explains four things. How one share gets you into the room. Why ESG laws put a dollar value on your voice. What words open the door with a mining company. And why COG\\$ multiplies all of it.\\n\\n\"\n"
    "                . \"Five minutes. Plain English. No jargon.\\n\\n\"\n"
    "                . \"You can join now for $4 at cogsaustralia.org/join\\n\\n\"\n"
    "                . \"Foundation Day is 14 May 2026 at 5pm AEST -- the first community vote.\\n\\n\"\n"
    "                . \"Thomas\\n\"\n"
    "                . \"COG\\$ of Australia Foundation\\n\"\n"
    "                . \"Drake Village NSW | Wahlubal Country, Bundjalung Nation\\n\\n\"\n"
    "                . \"---\\n\"\n"
    "                . \"You received this because you asked for the free guide at cogsaustralia.org. Reply to unsubscribe.\";\n"
    "\n"
    "            return [$html, $plain];\n"
    "        })(),"
)

new = (
    "        'lead_magnet_confirmation' => (function() use ($p) {\n"
    "            $cheatSheetUrl = 'https://open.substack.com/pub/cogsaustralia/p/the-ceo-power-cheat-sheet?r=8bqc6h&utm_campaign=post&utm_medium=web';\n"
    "            $guideUrl      = 'https://cogsaustralia.org/seat/inside/';\n"
    "            $joinUrl       = 'https://cogsaustralia.org/join/';\n"
    "\n"
    "            $html = '<!DOCTYPE html>'\n"
    "                . '<html lang=\"en\"><head><meta charset=\"UTF-8\">'\n"
    "                . '<title>Your CEO Power Cheat Sheet</title></head>'\n"
    "                . '<body style=\"margin:0;padding:0;background:#f5f0e8;font-family:Georgia,serif\">'\n"
    "                . '<table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background:#f5f0e8\">'\n"
    "                . '<tr><td align=\"center\" style=\"padding:32px 16px\">'\n"
    "                . '<table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08)\">'\n"
    "\n"
    "                . '<tr><td style=\"background:#1a0e00;padding:24px 32px;border-bottom:3px solid #c8901a\">'\n"
    "                . '<div style=\"font-size:20px;font-weight:bold;color:#c8901a;font-family:Georgia,serif\">COG$ of Australia Foundation</div>'\n"
    "                . '<div style=\"font-size:12px;color:#9a8a74;margin-top:4px;font-family:Arial,sans-serif\">Drake Village NSW &middot; Wahlubal Country, Bundjalung Nation</div>'\n"
    "                . '</td></tr>'\n"
    "\n"
    "                . '<tr><td style=\"padding:32px 32px 0\">'\n"
    "                . '<div style=\"font-size:24px;font-weight:bold;color:#1a0e00;font-family:Georgia,serif;line-height:1.2\">Here is your Cheat Sheet.</div>'\n"
    "                . '<p style=\"font-size:15px;color:#2a1a08;line-height:1.75;margin:16px 0 0;font-family:Arial,sans-serif\">'\n"
    "                . 'One share puts the CEO in your pocket. This is how.'"\n"
    "                . '</p>'\n"
    "                . '</td></tr>'\n"
    "\n"
    "                . '<tr><td style=\"padding:24px 32px 0\" align=\"center\">'\n"
    "                . '<a href=\"' . htmlspecialchars($cheatSheetUrl) . '\" '\n"
    "                . 'style=\"display:inline-block;background:#c8901a;color:#ffffff;font-weight:bold;'\n"
    "                . 'text-decoration:none;padding:16px 36px;border-radius:6px;font-size:16px;'\n"
    "                . 'font-family:Arial,sans-serif;letter-spacing:.01em\">'\n"
    "                . 'Read the CEO Power Cheat Sheet &rarr;'\n"
    "                . '</a>'\n"
    "                . '</td></tr>'\n"
    "\n"
    "                . '<tr><td style=\"padding:28px 32px 0\">'\n"
    "                . '<hr style=\"border:none;border-top:1px solid #e8e0d0;margin:0 0 24px\">'\n"
    "                . '<p style=\"font-size:14px;color:#2a1a08;line-height:1.75;margin:0 0 14px;font-family:Arial,sans-serif\">'\n"
    "                . 'The Cheat Sheet is the quick version. The full guide goes deeper &mdash; '\n"
    "                . 'the three laws that give you standing, the sentence that opens the door, '\n"
    "                . 'and why five thousand of us changes the calculation entirely.'\n"
    "                . '</p>'\n"
    "                . '<p style=\"font-size:14px;color:#2a1a08;line-height:1.75;margin:0 0 24px;font-family:Arial,sans-serif\">'\n"
    "                . '<a href=\"' . htmlspecialchars($guideUrl) . '\" style=\"color:#c8901a;font-weight:bold;text-decoration:none\">'\n"
    "                . 'Read the full guide: Seat at the Table &rarr;</a>'\n"
    "                . '</p>'\n"
    "                . '<hr style=\"border:none;border-top:1px solid #e8e0d0;margin:0 0 24px\">'\n"
    "                . '</td></tr>'\n"
    "\n"
    "                . '<tr><td style=\"padding:0 32px 0;background:#fffdf7;border-top:1px solid #e8e0d0\">'\n"
    "                . '<table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">'\n"
    "                . '<tr><td style=\"padding:20px 0\">'\n"
    "                . '<div style=\"font-size:13px;font-weight:bold;color:#c8901a;text-transform:uppercase;'\n"
    "                . 'letter-spacing:.08em;font-family:Arial,sans-serif;margin-bottom:10px\">Foundation Day &mdash; 14 May 2026</div>'\n"
    "                . '<p style=\"font-size:14px;color:#2a1a08;line-height:1.75;margin:0 0 12px;font-family:Arial,sans-serif\">'\n"
    "                . 'The first community vote. $4. Once only. Your seat at the table is locked in for life.'\n"
    "                . '</p>'\n"
    "                . '<a href=\"' . htmlspecialchars($joinUrl) . '\" '\n"
    "                . 'style=\"display:inline-block;background:#1a0e00;color:#c8901a;font-weight:bold;'\n"
    "                . 'text-decoration:none;padding:12px 28px;border-radius:6px;font-size:14px;'\n"
    "                . 'font-family:Arial,sans-serif\">'\n"
    "                . 'Join for $4 on Foundation Day &rarr;'\n"
    "                . '</a>'\n"
    "                . '</td></tr></table>'\n"
    "                . '</td></tr>'\n"
    "\n"
    "                . '<tr><td style=\"background:#1a0e00;padding:18px 32px;border-top:2px solid #c8901a\">'\n"
    "                . '<div style=\"font-size:11px;color:#6b5c44;line-height:1.9;font-family:Arial,sans-serif\">'\n"
    "                . 'Thomas Cunliffe &middot; Caretaker Trustee &middot; COG$ of Australia Foundation<br>'\n"
    "                . 'ABN: 91 341 497 529 &middot; Drake Village NSW 2469<br>'\n"
    "                . 'You received this because you asked for the free guide at cogsaustralia.org. '\n"
    "                . 'Reply to unsubscribe.'\n"
    "                . '</div>'\n"
    "                . '</td></tr>'\n"
    "\n"
    "                . '</table></td></tr></table>'\n"
    "                . '</body></html>';\n"
    "\n"
    "            $plain = \"Here is your CEO Power Cheat Sheet.\\n\\n\"\n"
    "                . \"One share puts the CEO in your pocket. This is how.\\n\\n\"\n"
    "                . $cheatSheetUrl . \"\\n\\n\"\n"
    "                . \"---\\n\\n\"\n"
    "                . \"The Cheat Sheet is the quick version. The full guide goes deeper --\\n\"\n"
    "                . \"the three laws that give you standing, the sentence that opens the door,\\n\"\n"
    "                . \"and why five thousand of us changes the calculation entirely.\\n\\n\"\n"
    "                . \"Full guide: \" . $guideUrl . \"\\n\\n\"\n"
    "                . \"---\\n\\n\"\n"
    "                . \"Foundation Day is 14 May 2026 at 5pm AEST.\\n\"\n"
    "                . \"Membership is $4. Once only. Your seat at the table locked in for life.\\n\\n\"\n"
    "                . $joinUrl . \"\\n\\n\"\n"
    "                . \"Thomas Cunliffe\\n\"\n"
    "                . \"Caretaker Trustee, COG\\$ of Australia Foundation\\n\"\n"
    "                . \"Drake Village NSW | Wahlubal Country, Bundjalung Nation\\n\\n\"\n"
    "                . \"---\\n\"\n"
    "                . \"You received this because you asked for the free guide at cogsaustralia.org. Reply to unsubscribe.\";\n"
    "\n"
    "            return [$html, $plain];\n"
    "        })(),"
)

assert old in c, 'FAIL: old block not found -- check exact string match'
new_c = c.replace(old, new, 1)
assert new_c != c, 'FAIL: no replacement made'
assert new_c.count('lead_magnet_confirmation') == 1, 'FAIL: template count wrong'
assert 'ceo-power-cheat-sheet' in new_c, 'FAIL: Cheat Sheet URL missing'
assert 'seat/inside' in new_c, 'FAIL: guide URL missing'

with open('_app/api/integrations/mailer.php', 'w') as f:
    f.write(new_c)

print('PASS: lead_magnet_confirmation template replaced')
print(f'File size: {len(new_c)} chars')
PYEOF

## STOP -- paste output. Must show PASS before proceeding.

## STEP 3 -- PHP lint

php -l _app/api/integrations/mailer.php

## STOP -- must show "No syntax errors detected".

## STEP 4 -- Verify key strings present in correct order

python3 /dev/stdin << 'PYEOF'
with open('_app/api/integrations/mailer.php', 'r') as f:
    c = f.read()

checks = [
    ('ceo-power-cheat-sheet', 'Cheat Sheet URL'),
    ('seat/inside', 'Guide URL'),
    ('cogsaustralia.org/join', 'Join URL'),
    ('CEO Power Cheat Sheet', 'CTA label'),
    ('Foundation Day', 'Foundation Day mention'),
]
ok = True
for needle, label in checks:
    found = needle in c
    print(f"{'PASS' if found else 'FAIL'}: {label}")
    if not found:
        ok = False

cheat_pos = c.find('ceo-power-cheat-sheet')
guide_pos = c.find('seat/inside')
print()
print('PASS: Cheat Sheet before guide' if cheat_pos < guide_pos else 'FAIL: wrong order')
PYEOF

## STOP -- paste output. All PASS required.

## STEP 5 -- Stage and diff

git add _app/api/integrations/mailer.php
git diff --cached --stat
echo "---"
git diff --cached | grep "^+" | grep -i "cheat\|substack\|guide\|join" | head -10

## STOP -- paste output. Only mailer.php staged.
## Cheat Sheet and guide URLs must appear in the diff. Then commit.

## STEP 6 -- Commit and push

git commit -m "fix(email): lead_magnet_confirmation delivers CEO Power Cheat Sheet first"
git pull --rebase origin main
git push origin review/session-40-lead-magnet-email

## STOP -- paste push output. Wait for merge instruction from Thomas via Coordinator.
