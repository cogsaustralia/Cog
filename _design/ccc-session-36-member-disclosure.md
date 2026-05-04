# CCC Session 36 -- Member Disclosure Section in Joining Confirmation Email
# Adds the approved proactive disclosure to the snft_user_confirmation
# email template in _app/api/integrations/mailer.php.
# Branch: review/session-36-member-disclosure
# Commit: feat(email): add member disclosure section to snft_user_confirmation template

## GROUND TRUTH

git log --oneline -3
echo "---"
grep -n "Founding phase notice" _app/api/integrations/mailer.php | head -3
echo "---"
grep -n "Explore COG" _app/api/integrations/mailer.php | head -3

## STOP -- paste output. Confirm file exists and "Founding phase notice" is found.

## STEP 1 -- Branch

git checkout -b review/session-36-member-disclosure

## STEP 2 -- Write disclosure HTML block to temp file

python3 /dev/stdin << 'PYEOF'
block = (
    "\n    <!-- Member disclosure section -->\n"
    "    <tr><td style=\"padding:0 32px 0\">\n"
    "      <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\""
    " style=\"background:#f0f8f4;border:1px solid #a8d5b8;border-radius:10px;margin:0 0 8px\">\n"
    "        <tr><td style=\"padding:20px 22px\">\n"
    "          <div style=\"font-size:15px;font-weight:bold;color:#2d6e45;margin-bottom:14px;"
    "font-family:Georgia,serif\">What the Foundation holds right now</div>\n"
    "          <p style=\"font-size:13px;color:#1e3a2a;line-height:1.75;margin:0 0 12px;"
    "font-family:Arial,sans-serif\">\n"
    "            The Foundation holds 1,545 shares in Legacy Minerals Holdings (ASX: LGM),"
    " registered on the CHESS Share Register in the Foundation's name.\n"
    "          </p>\n"
    "          <p style=\"font-size:13px;color:#1e3a2a;line-height:1.75;margin:0 0 12px;"
    "font-family:Arial,sans-serif\">\n"
    "            Legacy Minerals holds around 9,000 square kilometres of mineral tenements"
    " across northern New South Wales, on Bundjalung Nation Country. Their current resource"
    " figures are in their 2025 JORC Mineral Resource Estimate at"
    " <a href=\"https://legacyminerals.com.au\" style=\"color:#2d6e45\">legacyminerals.com.au</a>.\n"
    "          </p>\n"
    "          <p style=\"font-size:13px;color:#1e3a2a;line-height:1.75;margin:0 0 12px;"
    "font-family:Arial,sans-serif\">\n"
    "            At today's price, 1,545 shares is a small position. That is the truth."
    " We are at the beginning.\n"
    "          </p>\n"
    "          <div style=\"font-size:13px;font-weight:bold;color:#2d6e45;margin:14px 0 6px;"
    "font-family:Georgia,serif\">Why the shares matter</div>\n"
    "          <p style=\"font-size:13px;color:#1e3a2a;line-height:1.75;margin:0 0 12px;"
    "font-family:Arial,sans-serif\">\n"
    "            The gold, gas, and minerals beneath this Country are worth real money right"
    " now, whether anyone digs them up or not. Mining companies price that value every day on"
    " the stock market. Our shares give this community a legal right to sit inside that system."
    " To show up at the AGM. To put questions to the CEO on the record. To vote on real decisions"
    " about what happens to the Country above those resources.\n"
    "          </p>\n"
    "          <p style=\"font-size:13px;color:#1e3a2a;line-height:1.75;margin:0 0 12px;"
    "font-family:Arial,sans-serif\">\n"
    "            That right exists because we hold the shares. As more people join, we buy more"
    " shares. More shares means a louder voice at the table.\n"
    "          </p>\n"
    "          <div style=\"font-size:13px;font-weight:bold;color:#2d6e45;margin:14px 0 6px;"
    "font-family:Georgia,serif\">Where your $4 goes</div>\n"
    "          <p style=\"font-size:13px;color:#1e3a2a;line-height:1.75;margin:0 0 12px;"
    "font-family:Arial,sans-serif\">\n"
    "            Three places. Running the Foundation. Buying more shares in ASX-listed resource"
    " companies operating on Country &mdash; starting with planned positions in Santos and Origin"
    " Energy. And building hardware the Foundation owns outright, run by us and our First Nations"
    " partners, answerable to no one else.\n"
    "          </p>\n"
    "          <p style=\"font-size:13px;color:#1e3a2a;line-height:1.75;margin:0 0 12px;"
    "font-family:Arial,sans-serif\">\n"
    "            Every purchase goes on the public record at cogsaustralia.org."
    " You can check it any time.\n"
    "          </p>\n"
    "          <div style=\"font-size:13px;font-weight:bold;color:#2d6e45;margin:14px 0 6px;"
    "font-family:Georgia,serif\">What this is not</div>\n"
    "          <p style=\"font-size:13px;color:#1e3a2a;line-height:1.75;margin:0 0 12px;"
    "font-family:Arial,sans-serif\">\n"
    "            This is a membership. Not an investment product. Not a managed fund."
    " You are not buying a financial return. You are buying a seat at the table and the right"
    " to use it.\n"
    "          </p>\n"
    "          <div style=\"font-size:13px;font-weight:bold;color:#2d6e45;margin:14px 0 6px;"
    "font-family:Georgia,serif\">Dividends</div>\n"
    "          <p style=\"font-size:13px;color:#1e3a2a;line-height:1.75;margin:0 0 12px;"
    "font-family:Arial,sans-serif\">\n"
    "            If the companies we hold pay dividends, we get a share. Half goes back to"
    " members. Half goes toward buying more shares. No dividend is guaranteed. It depends on"
    " what the companies decide to pay, if anything.\n"
    "          </p>\n"
    "          <div style=\"font-size:13px;font-weight:bold;color:#2d6e45;margin:14px 0 6px;"
    "font-family:Georgia,serif\">Your vote</div>\n"
    "          <p style=\"font-size:13px;color:#1e3a2a;line-height:1.75;margin:0 0 12px;"
    "font-family:Arial,sans-serif\">\n"
    "            One member, one vote. That does not change.\n"
    "          </p>\n"
    "          <p style=\"font-size:13px;color:#1e3a2a;line-height:1.75;margin:0 0 12px;"
    "font-family:Arial,sans-serif\">\n"
    "            For decisions about a specific place &mdash; a mine, a pipeline, a corridor"
    " &mdash; the people who live there and the Traditional Owners of that Country carry more"
    " weight on that specific vote. Not on everything. Just on the decisions that affect their"
    " backyard directly.\n"
    "          </p>\n"
    "          <p style=\"font-size:13px;color:#1e3a2a;line-height:1.75;margin:0 0 12px;"
    "font-family:Arial,sans-serif\">\n"
    "            That is what fair say looks like in practice. It is written into the"
    " Foundation's governing agreement and takes 75% of all members to change."
    " Full details at <a href=\"https://cogsaustralia.org/jvpa\" style=\"color:#2d6e45\">"
    "cogsaustralia.org/jvpa</a> and"
    " <a href=\"https://cogsaustralia.org/faq\" style=\"color:#2d6e45\">cogsaustralia.org/faq</a>.\n"
    "          </p>\n"
    "          <div style=\"font-size:13px;font-weight:bold;color:#2d6e45;margin:14px 0 6px;"
    "font-family:Georgia,serif\">14-day cooling off</div>\n"
    "          <p style=\"font-size:13px;color:#1e3a2a;line-height:1.75;margin:0 0 0;"
    "font-family:Arial,sans-serif\">\n"
    "            Changed your mind? Email"
    " <a href=\"mailto:ThomasC@cogsaustralia.org\" style=\"color:#2d6e45\">"
    "ThomasC@cogsaustralia.org</a> with your name and the word <strong>refund</strong>"
    " within 14 days. No questions. Done."
    " After 14 days your membership is permanent and your seat is locked in.\n"
    "          </p>\n"
    "          <p style=\"font-size:11px;color:#4a7a5a;margin:14px 0 0;font-family:Arial,sans-serif\">\n"
    "            <em>A community membership invitation. Not financial advice.<br>\n"
    "            Share holdings: CHESS Share Register, May 2026.<br>\n"
    "            Resource figures: Legacy Minerals Holdings 2025 JORC MRE.</em>\n"
    "          </p>\n"
    "        </td></tr>\n"
    "      </table>\n"
    "    </td></tr>\n\n"
)
with open('/tmp/disclosure_block.txt', 'w') as f:
    f.write(block)
print(f"Written: /tmp/disclosure_block.txt ({len(block)} chars)")
PYEOF

## STOP -- paste output. Must confirm file written.

## STEP 3 -- Insert disclosure block into mailer.php BEFORE founding phase notice

python3 /dev/stdin << 'PYEOF'
with open('_app/api/integrations/mailer.php', 'r') as f:
    c = f.read()

# Anchor: the comment line that opens the founding phase notice row
anchor = '    <!-- Founding phase notice -->'
assert anchor in c, 'FAIL: anchor not found -- check exact string'

with open('/tmp/disclosure_block.txt', 'r') as f:
    block = f.read()

new_c = c.replace(anchor, block + anchor, 1)
assert new_c != c, 'FAIL: no replacement made'
assert new_c.count('14-day cooling off') == 1, 'FAIL: disclosure count wrong'
assert new_c.count('Founding phase notice') == 1, 'FAIL: founding phase notice count wrong'
assert new_c.count('CHESS Share Register') == 1, 'FAIL: CHESS tag count wrong'

with open('_app/api/integrations/mailer.php', 'w') as f:
    f.write(new_c)

print('PASS: disclosure inserted before founding phase notice')
print(f'File size: {len(new_c)} chars')
PYEOF

## STOP -- paste output. PASS required before proceeding.

## STEP 4 -- PHP lint

php -l _app/api/integrations/mailer.php

## STOP -- must show "No syntax errors detected".

## STEP 5 -- Position check

python3 /dev/stdin << 'PYEOF'
with open('_app/api/integrations/mailer.php', 'r') as f:
    c = f.read()
d = c.find('14-day cooling off')
n = c.find('Founding phase notice')
print(f'Disclosure at char: {d}')
print(f'Founding phase notice at char: {n}')
print('PASS: disclosure before notice' if d < n else 'FAIL: wrong order')
PYEOF

## STOP -- paste output. PASS required.

## STEP 6 -- Stage and verify diff

git add _app/api/integrations/mailer.php
git diff --cached --stat
echo "---"
git diff --cached | grep "^+" | grep -i "CHESS\|JORC\|Santos\|cooling\|disclosure\|dividend" | head -10

## STOP -- paste output. Only mailer.php should be staged.
## Confirm disclosure phrases appear in the diff. Then commit.

## STEP 7 -- Commit and push

git commit -m "feat(email): add member disclosure section to snft_user_confirmation template"
git pull --rebase origin main
git push origin review/session-36-member-disclosure

## STOP -- paste push output. Wait for merge instruction from Thomas via Coordinator.
