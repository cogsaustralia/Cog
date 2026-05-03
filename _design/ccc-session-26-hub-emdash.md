# CCC Session 26: hubs/mainspring/index.html — em-dash strip
# Branch: review/session-26
# ONE FILE ONLY: hubs/mainspring/index.html
# Audit ref: RULE — zero em-dashes in user-visible text (16 remaining)

## SETUP
git pull --rebase origin main

## GROUND TRUTH
Read hubs/mainspring/index.html before touching anything.

## CHANGES

python3 << 'PYEOF'
with open('hubs/mainspring/index.html', 'r') as f: c = f.read()
orig = len(c)

# GROUP 1: title and description copy
c = c.replace(
    'Mainspring \u2014 COG$ of Australia Foundation',
    'Mainspring | COG$ of Australia Foundation'
)
c = c.replace(
    'All nine management hubs at a glance \u2014 live stats, recent activity, and quick access to every governance area.',
    'All nine management hubs at a glance: live stats, recent activity, and quick access to every governance area.'
)
c = c.replace('Operational Activity \u2014 All Hubs', 'Operational Activity: All Hubs')

# GROUP 2: Sub-Trust abbreviation labels (colon is the right separator for "STA = Sub-Trust A")
c = c.replace('STA \u2014 Sub-Trust A', 'STA: Sub-Trust A')
c = c.replace('STB \u2014 Sub-Trust B', 'STB: Sub-Trust B')
c = c.replace('STC \u2014 Sub-Trust C', 'STC: Sub-Trust C')

# GROUP 3: Sub-Trust section headings
c = c.replace('Sub-Trust A \u2014 Operations', 'Sub-Trust A: Operations')
c = c.replace('Sub-Trust B \u2014 Governance', 'Sub-Trust B: Governance')
c = c.replace('Sub-Trust C \u2014 Community', 'Sub-Trust C: Community')

# GROUP 4: standalone placeholder dashes -> en-dash
# Confirmed: 6 standalone — in visible HTML (non-script/style)
# Replace by unique surrounding context to avoid accidental matches
import re

# Read fresh for targeted replacements
lines = c.split('\n')
new_lines = []
in_script = False
in_style = False
for line in lines:
    if re.search(r'<script', line, re.I): in_script = True
    if re.search(r'</script', line, re.I): in_script = False
    if re.search(r'<style', line, re.I): in_style = True
    if re.search(r'</style', line, re.I): in_style = False
    if not in_script and not in_style:
        # Replace standalone em-dash between > and < in visible HTML only
        line = re.sub(r'(>)\u2014(<)', r'\1\u2013\2', line)
    new_lines.append(line)
c = '\n'.join(new_lines)

with open('hubs/mainspring/index.html', 'w') as f: f.write(c)
print(f"Done. {orig} -> {len(c)}")
PYEOF

## VERIFICATION
python3 << 'PYEOF2'
import re

with open('hubs/mainspring/index.html') as f: c = f.read()

s = re.sub(r'<script[^>]*>.*?</script>', '', c, flags=re.DOTALL)
s = re.sub(r'<style[^>]*>.*?</style>', '', s, flags=re.DOTALL)
s = re.sub(r'<!--.*?-->', '', s, flags=re.DOTALL)
em = re.findall(r'>([^<]*\u2014[^<]*)<', s)

checks = [
    ('title em-dash gone',      'Mainspring \u2014 COG$' not in c),
    ('title pipe present',      'Mainspring | COG$' in c),
    ('STA colon',               'STA: Sub-Trust A' in c),
    ('SubTrust A colon',        'Sub-Trust A: Operations' in c),
    ('Operational colon',       'Operational Activity: All Hubs' in c),
    ('zero visible em-dashes',  len(em) == 0),
    ('</style>', c.count('</style>') == 1),
    ('</head>',  c.count('</head>')  == 1),
    ('</html>',  c.count('</html>') == 1),
]
for label, ok in checks:
    print(f"[{'OK' if ok else 'FAIL'}] {label}")
if em:
    print(f"  remaining: {[e.strip()[:60] for e in em]}")
PYEOF2

## COMMIT TO REVIEW BRANCH
git add hubs/mainspring/index.html
git commit -m "fix(hub): strip all em-dashes from visible text (session-26)"
git checkout -b review/session-26
git push origin review/session-26

## STOP -- wait for review
