# CCC Fix Session 13b: voice-text/video/confirmed — em-dash strip
# These files were merged directly to main in session-13.
# Pull main before starting: git pull --rebase origin main

## GROUND TRUTH RULES
- Read exact current file before every edit
- Stage only the three files listed below
- Zero em-dashes in any user-visible text
- Commit to review/session-13b — do NOT push to main

## FILES TO FIX

### intro/voice-text.html
1. Find: <title>Tell us why — COG$ of Australia</title>
   Replace: <title>Tell us why | COG$ of Australia</title>

### intro/voice-video.html
2. Find: <title>Record video — COG$ of Australia</title>
   Replace: <title>Record video | COG$ of Australia</title>

3. Find the cancel button/link containing: Cancel — go to my dashboard
   Replace em-dash with plain punctuation, e.g.:
   "Cancel. Go to my dashboard." or "Cancel and go to my dashboard"
   Read the exact text first, then fix.

### intro/voice-confirmed.html
4. Find: <title>Submission received — COG$ of Australia</title>
   Replace: <title>Submission received | COG$ of Australia</title>

5. Find the sentence containing: We'll email you when it's clea...
   (likely "We'll email you when it's cleared — ...")
   Replace em-dash with period or comma. Read exact text first.

6. Find: Pass the coin — your link is almost ready
   Replace em-dash with colon:
   "Pass the coin: your link is almost ready"

## VERIFICATION
python3 - << 'PYEOF2'
import re
for filepath in ["intro/voice-text.html","intro/voice-video.html","intro/voice-confirmed.html"]:
    with open(filepath) as f:
        content = f.read()
    stripped = re.sub(r'<script[^>]*>.*?</script>','',content,flags=re.DOTALL)
    stripped = re.sub(r'<style[^>]*>.*?</style>','',stripped,flags=re.DOTALL)
    stripped = re.sub(r'<!--.*?-->','',stripped,flags=re.DOTALL)
    em = re.findall(r'>([^<]{0,200}\u2014[^<]{0,200})<',stripped)
    print(f"{filepath}: em-dashes={len(em)} {'OK' if not em else 'FAIL: '+str([e.strip()[:60] for e in em])}")
PYEOF2

All three must show em-dashes=0.

## COMMIT
git add intro/voice-text.html intro/voice-video.html intro/voice-confirmed.html
git commit -m "fix(voice): strip em-dashes from title tags and user-visible text"
git checkout -b review/session-13b
git push origin review/session-13b
