# CCC Session 42: seat/inside/index.html — remove backslash-escaped ! sequences
# Branch: review/session-42
# FILES: seat/inside/index.html

## Context

seat/inside/index.html contains 7 occurrences of \! (backslash before exclamation mark).
One is in the DOCTYPE declaration, causing literal text to render at the top of the page.
Six are in JavaScript logical-NOT expressions, making them syntax errors.
All 7 must be replaced with plain ! — no other changes.

---

## Step 1 — Pull latest main

```bash
git pull --rebase origin main
```

---

## Step 2 — Read before touching

```bash
python3 << 'PYEOF'
with open('seat/inside/index.html', 'rb') as f:
    raw = f.read()
import re
hits = [m.start() for m in re.finditer(b'\\\\!', raw)]
print(f"\\! occurrences found: {len(hits)}")
for pos in hits:
    print(f"  pos {pos}: {repr(raw[max(0,pos-20):pos+40])}")
PYEOF
```

Confirm exactly 7 occurrences. If count differs, STOP and report.

---

## Step 3 — Apply fix (Python replacement pass)

```bash
python3 << 'PYEOF'
with open('seat/inside/index.html', 'rb') as f:
    raw = f.read()
orig_count = raw.count(b'\\!')
assert orig_count == 7, f"ABORT: expected 7 occurrences, found {orig_count}"
fixed = raw.replace(b'\\!', b'!')
assert fixed.count(b'\\!') == 0, "ABORT: replacement incomplete"
assert fixed.count(b'<!DOCTYPE') == 1, "ABORT: DOCTYPE missing after fix"
with open('seat/inside/index.html', 'wb') as f:
    f.write(fixed)
print(f"Done. Replaced {orig_count} occurrences. {len(raw)} -> {len(fixed)} bytes ({len(raw)-len(fixed)} bytes removed)")
PYEOF
```

---

## Step 4 — Verify

```bash
python3 << 'PYEOF2'
with open('seat/inside/index.html', 'rb') as f:
    raw = f.read()
content = raw.decode('utf-8')

import re

checks = [
    ('no \\! sequences remaining',   b'\\!' not in raw),
    ('DOCTYPE correct',              raw.startswith(b'<!DOCTYPE html>')),
    ('<!DOCTYPE count == 1',         content.count('<!DOCTYPE') == 1),
    ('JS ! not= operator intact',    '!raw' in content or '!d' in content or '!form' in content or '!phone' in content or '!email' in content),
    ('</head> once',                 content.count('</head>') == 1),
    ('<body once',                   content.count('<body') == 1),
    ('</body> once',                 content.count('</body>') == 1),
    ('</html> once',                 content.count('</html>') == 1),
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
git checkout -b review/session-42
git add seat/inside/index.html
git commit -m "fix(seat/inside): remove 7 backslash-escaped ! sequences — fixes DOCTYPE render and JS syntax errors"
git push origin review/session-42
```

---

## STOP — wait for review before merging to main
