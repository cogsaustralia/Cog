# CCC Verification Session 9b: partners/index.html — Em-dash audit and fix
# Previous session (Claude chat) directly edited partners/index.html and pushed to main.
# This session verifies that work meets the zero-AI-tells standard and fixes any failures.
# Pull main before starting: git pull --rebase origin main

## GROUND TRUTH RULES
- Read the exact current file before every edit
- Stage only partners/index.html
- Verify div balance, script balance, </body>, </html> after all changes
- Zero AI tells — Grade 6 Australian plain English only
- Banned characters in user-visible text: em-dash (—), en-dash used as separator
- Commit to review/session-9b branch — do NOT push to main

---

## STEP 1 — Audit
Run this Python audit script and record the output:

python3 - << 'PYEOF'
import re
with open("partners/index.html") as f:
    content = f.read()
stripped = re.sub(r'<script[^>]*>.*?</script>', '', content, flags=re.DOTALL)
stripped = re.sub(r'<style[^>]*>.*?</style>', '', stripped, flags=re.DOTALL)
stripped = re.sub(r'<!--.*?-->', '', stripped, flags=re.DOTALL)
text_nodes = re.findall(r'>([^<]{0,300}—[^<]{0,300})<', stripped)
print(f"Em-dashes in text nodes: {len(text_nodes)}")
for t in text_nodes:
    print(repr(t.strip()))
PYEOF

## STEP 2 — Fix any remaining em-dashes
If STEP 1 finds any em-dashes in text nodes, fix each one using plain English:
- Title/label patterns (X — Y): replace with colon (X: Y)
- Sentence joins (clause — clause): replace with period, comma, or colon as meaning requires
- Standalone — separators: remove entirely
- Do NOT change any text inside <script> or <style> blocks
- Do NOT change CSS variable names, class names, or code

## STEP 3 — Re-audit
Re-run the STEP 1 script. Result must be: "Em-dashes in text nodes: 0"
If not zero, repeat STEP 2.

## STEP 4 — Full ground truth check
python3 - << 'PYEOF'
import re
with open("partners/index.html") as f:
    content = f.read()
lines = content.split("\n")
print(f"Total lines: {len(lines)}")
for tag in ["</style>","</head>","<body","</body>","</html>"]:
    count = content.count(tag)
    print(f"  {tag}: {'OK' if count==1 else 'FAIL '+str(count)}")
scripts = re.findall(r'<script[^>]*>(.*?)</script>', content, re.DOTALL)
print(f"  Script blocks: {len(scripts)}")
for i,s in enumerate(scripts):
    o,c = s.count('{'),s.count('}')
    print(f"    Block {i+1}: {'OK' if o==c else 'FAIL open='+str(o)+' close='+str(c)}")
print("  Escaped ops: " + ("FAIL" if r'\!=' in content or r'\==' in content else "OK"))
PYEOF

All checks must pass before committing.

## STEP 5 — Commit to review branch only
git config user.email "deploy@cogsaustralia.org"
git config user.name "COGs Deploy"
git add partners/index.html
git commit -m "verify(hub): CCC em-dash audit pass on partners/index.html"
git checkout -b review/session-9b
git push origin review/session-9b

## VERIFICATION CHECKLIST (report each item)
1. Em-dashes in text nodes: 0
2. Structure tags: all OK
3. Script brace balance: all OK
4. Escaped operators: OK
5. Committed to review/session-9b only — NOT merged to main
