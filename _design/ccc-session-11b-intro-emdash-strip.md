# CCC Fix Session 11b: intro/index.html + intro/voice-welcome.html — em-dash strip
# Pull main before starting: git pull --rebase origin main
# Then: git checkout origin/review/session-11 -- intro/index.html intro/voice-welcome.html

## GROUND TRUTH RULES
- Read exact current files before every edit
- Stage only intro/index.html and intro/voice-welcome.html
- Zero AI tells — no em-dashes in any user-visible text
- Commit to review/session-11b — do NOT push to main

## CHANGES REQUIRED

### intro/index.html
1. Find: <title>Before You Join — COG$ of Australia Foundation</title>
   Replace with: <title>Before You Join | COG$ of Australia Foundation</title>

### intro/voice-welcome.html
2. Find: <title>Add your voice — COG$ of Australia</title>
   Replace with: <title>Add your voice | COG$ of Australia</title>

3. Find the Skip link button/anchor containing: Skip — I'll do this later
   Replace the em-dash with a comma or remove it:
   e.g. "Skip. I'll do this later from my dashboard"
   or   "No thanks — do this later" becomes "No thanks. Do this later."
   Read the exact text first, then fix.

## VERIFICATION
Run this after changes:

python3 - << 'PYEOF2'
import re
for filepath in ["intro/index.html", "intro/voice-welcome.html"]:
    with open(filepath) as f:
        content = f.read()
    stripped = re.sub(r'<script[^>]*>.*?</script>', '', content, flags=re.DOTALL)
    stripped = re.sub(r'<style[^>]*>.*?</style>', '', stripped, flags=re.DOTALL)
    stripped = re.sub(r'<!--.*?-->', '', stripped, flags=re.DOTALL)
    em = re.findall(r'>([^<]{0,200}—[^<]{0,200})<', stripped)
    print(f"{filepath}: em-dashes={len(em)} {'OK' if not em else 'FAIL'}")
PYEOF2

Result must be 0 for both files.

## COMMIT
git add intro/index.html intro/voice-welcome.html
git commit -m "fix(intro): strip em-dashes from title tags and skip link"
git checkout -b review/session-11b
git push origin review/session-11b
