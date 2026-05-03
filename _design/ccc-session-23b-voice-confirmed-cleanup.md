# CCC Session 23b: intro/voice-confirmed.html — two pre-existing issues
# Branch: review/session-23b
# Base: review/session-23 (must be on top of session-23 changes)
# ONE FILE ONLY: intro/voice-confirmed.html

## SETUP
git pull --rebase origin review/session-23

## GROUND TRUTH
Read intro/voice-confirmed.html before touching anything.

## CHANGES

python3 << 'PYEOF'
with open('intro/voice-confirmed.html', 'r') as f:
    c = f.read()

orig = len(c)

# FIX 1 — fallback panel: "4 Australians" inconsistent with PTC fix above
# Audit 35.5: one person, not four
c = c.replace(
    'Share it with 4 Australians after you set up your vault.',
    'Pass it to one Australian after you set up your account.'
)

# FIX 2 — canvass prompt em-dash (audit UX copy rule: zero em-dashes in user-visible text)
c = c.replace(
    'What does a fair say and a fair share mean to you \u2014 for each company?',
    'What does a fair say and a fair share mean to you, for each company?'
)

with open('intro/voice-confirmed.html', 'w') as f:
    f.write(c)

print(f"Done. {orig} -> {len(c)} bytes")
PYEOF

## VERIFICATION
python3 << 'PYEOF2'
import re

with open('intro/voice-confirmed.html') as f:
    c = f.read()

stripped = re.sub(r'<script[^>]*>.*?</script>', '', c, flags=re.DOTALL)
stripped = re.sub(r'<style[^>]*>.*?</style>', '', stripped, flags=re.DOTALL)
em_hits = re.findall(r'>([^<]*\u2014[^<]*)<', stripped)

checks = [
    ('4 Australians fallback GONE', '4 Australians' not in c),
    ('one Australian replacement',  'one Australian after you set up your account' in c),
    ('canvass em-dash GONE',        'mean to you \u2014 for each company' not in c),
    ('canvass comma replacement',   'mean to you, for each company' in c),
    ('em-dashes in visible text',   len(em_hits) == 0),
    ('FB share URL intact',         'sharer.php?u=' in c and '&quote=' not in c),
    ('what-happens-next intact',    'A real person reads your message' in c),
    ('</style>', c.count('</style>') == 1),
    ('</head>',  c.count('</head>')  == 1),
    ('</html>',  c.count('</html>') == 1),
]
for label, ok in checks:
    print(f"[{'OK' if ok else 'FAIL'}] {label}")
PYEOF2

## COMMIT ON TOP OF session-23 branch — push as review/session-23b
git add intro/voice-confirmed.html
git commit -m "fix(voice-confirmed): 4-Aus fallback -> 1, canvass em-dash (session-23b)"
git push origin HEAD:review/session-23b

## STOP -- wait for review
