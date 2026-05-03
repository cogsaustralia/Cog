# CCC Session 18: wallets/member.html — strip all em-dashes from visible text
# Branch: review/session-18
# ONE FILE ONLY: wallets/member.html
# Do NOT push to main — stop for review after push to branch

## SETUP
git pull --rebase origin main

## GROUND TRUTH — READ FIRST
Read wallets/member.html from the repo before touching anything.

## CHANGE — single Python replacement pass

python3 << 'PYEOF'
with open('wallets/member.html', 'r') as f:
    c = f.read()

orig_len = len(c)

# GROUP 1: Title
c = c.replace(
    'Member Vault \u2014 COG$ of Australia Foundation',
    'Member Vault | COG$ of Australia Foundation'
)

# GROUP 2: Copy text em-dashes
c = c.replace(
    'First COGS vote \u2014 14 May 2026',
    'First COGS vote: 14 May 2026'
)
c = c.replace(
    'No money, no commitment \u2014 just your interest list.',
    'No money, no commitment. Just your interest list.'
)
c = c.replace(
    "Membership \u2014 can't be sold or transferred",
    "Membership: cannot be sold or transferred"
)
c = c.replace(
    'No money, no commitment \u2014 just your interest.</div>',
    'No money, no commitment. Just your interest.</div>'
)
c = c.replace(
    'Future COG$ \u2014 your interest list (no money, no commitment).',
    'Future COG$: your interest list (no money, no commitment).'
)
c = c.replace(
    'Edit freely \u2014 no obligation, no payment required.',
    'Edit freely. No obligation, no payment required.'
)
c = c.replace(
    'Pending \u2014 awaiting payment',
    'Pending: awaiting payment'
)
c = c.replace(
    '<h3>Your address \u2014 share this with friends so they can send you Community COG$.</h3>',
    '<h3>Your address. Share this with friends so they can send you Community COG$.</h3>'
)
# Dropdown placeholder — appears in HTML and JS; both need fixing
c = c.replace(
    '>\u2014 Select a contact \u2014</option>',
    '>Select a contact</option>'
)
c = c.replace(
    'Tallies show counts only \u2014 your identity and choice are never exposed.',
    'Tallies show counts only. Your identity and choice are never exposed.'
)
c = c.replace(
    "Choose how you'd like to pay \u2014 bank transfer and PayID are both instant and completely fee-free.",
    "Choose how you'd like to pay. Bank transfer and PayID are both instant and completely fee-free."
)
# Appears 3 times (BSB primary, PayID primary, BSB outstanding) -- global replace is correct
c = c.replace(
    "I've sent it \u2014 let me know when it lands \u2192</button>",
    "I've sent it. Let me know when it lands \u2192</button>"
)
c = c.replace(
    '>\u26a1 PayID \u2014 instant transfer</span>',
    '>\u26a1 PayID: instant transfer</span>'
)
# Appears twice (Stripe anchor + outstanding button) -- global replace is correct
c = c.replace(
    'Pay $4 + 40c card fee \u2014 by Apple Pay, Google Pay, or card.',
    'Pay $4 + 40c card fee by Apple Pay, Google Pay, or card.'
)

# GROUP 3: Placeholder dashes (empty state -- replace em-dash with en-dash)
c = c.replace('style="color:var(--text)">\u2014</strong>',  'style="color:var(--text)">\u2013</strong>')
c = c.replace('letter-spacing:.04em">\u2014</span>',        'letter-spacing:.04em">\u2013</span>')
c = c.replace('line-height:1;letter-spacing:.02em">\u2014</div>', 'line-height:1;letter-spacing:.02em">\u2013</div>')
c = c.replace('<span id="b-states">\u2014</span>',      '<span id="b-states">\u2013</span>')
c = c.replace('<span id="b-total-cogs">\u2014</span>',  '<span id="b-total-cogs">\u2013</span>')
c = c.replace('<span id="b-total-value">\u2014</span>', '<span id="b-total-value">\u2013</span>')
c = c.replace('<span id="b-partners">\u2014</span>',    '<span id="b-partners">\u2013</span>')
c = c.replace('id="hd-lr-val">\u2014</div>',            'id="hd-lr-val">\u2013</div>')
c = c.replace('id="xch-balance-val">\u2014</div>',      'id="xch-balance-val">\u2013</div>')
c = c.replace('id="outstanding-modal-total">\u2014</span>', 'id="outstanding-modal-total">\u2013</span>')
c = c.replace('id="ob-title">\u2014</div>',             'id="ob-title">\u2013</div>')

# GROUP 4: data-tip tooltip attributes (user-visible on ? press)
c = c.replace(
    'Your profile and settings \u2014 update',
    'Your profile and settings: update'
)
c = c.replace(
    'Return to the Members Hub \u2014 community stats',
    'Return to the Members Hub: community stats'
)
c = c.replace(
    'Expansion Day \u2014 when the Foundation',
    'Expansion Day, when the Foundation'
)
c = c.replace(
    'token issued \u2014 a share',
    'token issued, a share'
)
c = c.replace(
    'exactly equal terms \u2014 same governance',
    'exactly equal terms, with the same governance'
)

with open('wallets/member.html', 'w') as f:
    f.write(c)

print(f"File updated. Size change: {orig_len} -> {len(c)} bytes")
PYEOF

## VERIFICATION -- zero em-dashes remaining in visible text and data-tip attributes
python3 << 'PYEOF2'
import re

with open('wallets/member.html') as f:
    content = f.read()

# 1. Visible text nodes
stripped = re.sub(r'<script[^>]*>.*?</script>', '', content, flags=re.DOTALL)
stripped = re.sub(r'<style[^>]*>.*?</style>', '', stripped, flags=re.DOTALL)
stripped = re.sub(r'<!--.*?-->', '', stripped, flags=re.DOTALL)
text_hits = re.findall(r'>([^<]*\u2014[^<]*)<', stripped)

# 2. data-tip attributes (HTML only, before first script block)
first_script = content.find('<script')
html_only = content[:first_script] if first_script > 0 else content
tip_hits = re.findall(r'data-tip="[^"]*\u2014[^"]*"', html_only)

# 3. Structure check
checks = {
    '</style>': content.count('</style>'),
    '</head>':  content.count('</head>'),
    '<body':    content.count('<body'),
    '</body>':  content.count('</body>'),
    '</html>':  content.count('</html>'),
}

print("--- VERIFICATION ---")
print(f"Visible text em-dashes:  {len(text_hits)} {'OK' if not text_hits else 'FAIL: ' + str([h.strip()[:60] for h in text_hits])}")
print(f"data-tip em-dashes:      {len(tip_hits)} {'OK' if not tip_hits else 'FAIL: ' + str(tip_hits[:3])}")
for tag, count in checks.items():
    print(f"{tag}: {count} {'OK' if count == 1 else 'FAIL'}")
PYEOF2

## COMMIT TO REVIEW BRANCH -- stop here, do not merge to main
git add wallets/member.html
git commit -m "fix(vault): strip all em-dashes from visible text and tooltips (session-18)"
git checkout -b review/session-18
git push origin review/session-18

## STOP -- show Thomas the git diff and verification output, wait for approval before merge
