# CCC Session 46: seat/inside/index.html — fix email fallback in phone form
# Branch: review/session-46
# FILES: seat/inside/index.html
# Depends on: review/session-42 must be merged to main first

## Context

The phone capture form on /seat/inside/ shows "We could not find your email.
Please go back and enter it again." when the user taps "Send me a text".

The email is written to localStorage when the user arrives from /seat/ via the
?e= URL parameter. But localStorage fails silently in private browsing, Safari ITP,
and some mobile browsers — leaving email empty.

The fix: when localStorage returns no email, fall back to reading ?e= directly
from the current URL before showing the error. The email is always in the URL
because /seat/ sets it on redirect: /seat/inside/?e=encoded@email.com

The error message is also rewritten to be more helpful if both sources fail.

---

## Step 1 — Pull latest main

```bash
git pull --rebase origin main
```

Confirm session-42 is merged (seat/inside/index.html must NOT contain \! sequences):

```bash
python3 << 'PYEOF'
with open('seat/inside/index.html', 'rb') as f:
    raw = f.read()
import re
hits = re.findall(b'\\\\!', raw)
print(f"Backslash-! count: {len(hits)} (must be 0 — session-42 must be merged first)")
PYEOF
```

If backslash-! count is not 0, STOP. Session-42 must be merged before this runs.

---

## Step 2 — Read before touching

```bash
grep -n 'readLead\|lead.email\|could not find' seat/inside/index.html
```

Confirm you see exactly:
    var lead = readLead();
    var email = (lead && lead.email) ? lead.email : '';
    if (!email) { msg.textContent = 'We could not find your email. Please go back and enter it again.'; return; }

If different, STOP and report.

---

## Step 3 — Apply fix

```bash
python3 << 'PYEOF'
with open('seat/inside/index.html', 'r') as f:
    c = f.read()

old = """    var lead = readLead();
    var email = (lead && lead.email) ? lead.email : '';
    if (!email) { msg.textContent = 'We could not find your email. Please go back and enter it again.'; return; }"""

new = """    var lead = readLead();
    var email = (lead && lead.email) ? lead.email : '';
    if (!email) {
      try { var _up = new URLSearchParams(window.location.search); email = (_up.get('e') || '').trim().toLowerCase(); } catch(_e) {}
    }
    if (!email) { msg.textContent = 'Enter your mobile number above and tap the button. If this keeps happening, go back and re-enter your email.'; return; }"""

assert c.count(old) == 1, f"ABORT: old string count={c.count(old)} (must be 1)"
c = c.replace(old, new)
assert c.count(new) == 1, "ABORT: replacement failed"
assert c.count(old) == 0, "ABORT: old string still present"

with open('seat/inside/index.html', 'w') as f:
    f.write(c)
print("Done.")
PYEOF
```

---

## Step 4 — Verify

```bash
python3 << 'PYEOF2'
with open('seat/inside/index.html') as f:
    c = f.read()

import re

# Strip script blocks for em-dash check
stripped = re.sub(r'<script[^>]*>.*?</script>', '', c, flags=re.DOTALL)
stripped = re.sub(r'<style[^>]*>.*?</style>', '', stripped, flags=re.DOTALL)
em = re.findall(r'>([^<]*\u2014[^<]*)<', stripped)

checks = [
    ('URL fallback present',          "_up.get('e')" in c),
    ('old error message gone',        'could not find your email' not in c),
    ('new error message present',     'Enter your mobile number above' in c),
    ('old single-line check gone',    "if (!email) { msg.textContent = 'We could not find" not in c),
    ('localStorage read still present', 'readLead()' in c),
    ('no raw em-dashes in text nodes', len(em) == 0),
    ('</head> once',                  c.count('</head>') == 1),
    ('<body once',                    c.count('<body') == 1),
    ('</body> once',                  c.count('</body>') == 1),
    ('</html> once',                  c.count('</html>') == 1),
]
all_ok = True
for label, ok in checks:
    print(f"[{'OK' if ok else 'FAIL'}] {label}")
    if not ok:
        all_ok = False
print()
print("RESULT:", "ALL OK" if all_ok else "FAILURES — do not commit")
PYEOF2
```

---

## Step 5 — Stage and commit to review branch

Only if all checks pass.

```bash
git checkout -b review/session-46
git add seat/inside/index.html
git commit -m "fix(seat/inside): fall back to URL ?e= param when localStorage email missing — fixes phone form error"
git push origin review/session-46
```

---

## STOP — wait for review before merging to main
