# CCC Session 25c: thank-you/index.html — PTC heading copy
# Branch: review/session-25c
# Base: review/session-25
# ONE FILE ONLY: thank-you/index.html

## SETUP
git pull --rebase origin review/session-25

## GROUND TRUTH
Read thank-you/index.html before touching anything.

## CHANGE

python3 << 'PYEOF'
with open('thank-you/index.html', 'r') as f: c = f.read()
orig = len(c)

c = c.replace(
    'Now send this to 4 Australians you think deserve a say.',
    'Pass the link to one Australian who would want a say.'
)

with open('thank-you/index.html', 'w') as f: f.write(c)
print(f"Done. {orig} -> {len(c)}")
PYEOF

## VERIFICATION
python3 << 'PYEOF2'
import re
with open('thank-you/index.html') as f: c = f.read()
s = re.sub(r'<script[^>]*>.*?</script>', '', c, flags=re.DOTALL)
s = re.sub(r'<style[^>]*>.*?</style>', '', s, flags=re.DOTALL)
em = re.findall(r'>([^<]*\u2014[^<]*)<', s)
checks = [
    ('4 Australians gone',      '4 Australians' not in c),
    ('one Australian present',  'one Australian who would want a say' in c),
    ('zero visible em-dashes',  len(em) == 0),
    ('vault->account intact',   'your account' in c),
    ('</html>', c.count('</html>') == 1),
]
for label, ok in checks:
    print(f"[{'OK' if ok else 'FAIL'}] {label}")
PYEOF2

## COMMIT
git add thank-you/index.html
git commit -m "fix(thank-you): PTC heading 4 Australians -> 1 (session-25c)"
git push origin HEAD:review/session-25c

## STOP -- wait for review
