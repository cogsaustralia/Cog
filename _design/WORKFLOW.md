# CCC Session Workflow
# _design/WORKFLOW.md
# Reference for all CCC sessions. Read this before executing any session prompt.

---

## Roles

- **Claude (chat)** — reads repo files, writes session prompts, verifies review branches,
  gives merge commands. Never edits, creates, or modifies any file directly.
- **CCC** — executes session prompts, pushes branches. Never merges to main without
  Claude sign-off.
- **Thomas** — runs CCC, pastes results back to Claude, confirms merge.

---

## Step-by-step workflow

### Step 1 — Ground truth
Before writing any prompt Claude clones or pulls main and reads the target file(s).
All find/replace strings are confirmed to exist the correct number of times before
the session is written. Never guess. Never work from memory of a previous session.

### Step 2 — Write prompt
Claude writes the session `.md` to `_design/ccc-session-NN-short-description.md`.

**Every prompt contains in this order:**
1. Header comment block (see format below)
2. `git pull --rebase origin main`
3. Ground truth instruction: read the file before touching anything
4. Changes (Python pass preferred — see rules below)
5. Verification (Python script preferred — see rules below)
6. Commit and push to review branch
7. Hard stop line: `## STOP -- wait for review before merging to main`

**Header format:**
```
# CCC Session NN: filename(s) — short description
# Branch: review/session-NN
# FILES: path/to/file.html [· path/to/other.html]
# Audit refs: X.XX (SEVERITY) [optional — omit if no audit source]
```

### Step 3 — Push prompt to repo
Claude copies the `.md` to `_design/` on main and pushes. This is the file CCC reads.

### Step 4 — CCC executes
Thomas gives CCC:
```
git pull origin main && read _design/ccc-session-NN-description.md and execute it
exactly on review/session-NN, then push.
```
CCC pushes the review branch. Thomas pastes the verification output back to Claude.

### Step 5 — Claude verifies
Claude pulls `review/session-NN` and runs an independent verification script.
Checks every targeted change, every old string gone, structure intact, zero visible
em-dashes, no regressions on prior session changes.

### Step 6 — Result

**If clean:** Claude gives the exact merge command (see Step 7).

**If a pre-existing issue is found (not in scope for this session):**
Claude writes a `session-NNb` (or `session-NNc`) prompt, pushes it to `_design/`,
and gives Thomas:
```
git pull origin main && git checkout review/session-NN &&
read _design/ccc-session-NNb-description.md and execute it exactly,
pushing as review/session-NNb.
```
CCC runs it on top of the current review branch. Thomas pastes result.
Claude re-verifies both together, then gives a combined merge command that
merges `review/session-NN` first, then `review/session-NNb`.

### Step 7 — Merge
Thomas gives CCC:
```
git checkout main && git pull origin main &&
git merge review/session-NN &&
git push origin main &&
git branch -d review/session-NN &&
git push origin --delete review/session-NN
```
For combined sessions: merge in order (NN, then NNb, then NNc).

### Step 8 — Next session
```
git pull origin main && read _design/ccc-session-[next].md and execute it
exactly on review/session-[next], then push.
```

---

## Change authoring rules

### Python replacement pass (preferred for string replacements)
Use a `python3 << 'PYEOF'` block. Never heredoc (`cat > file << 'EOF'`).

```python
with open('path/to/file.html', 'r') as f:
    c = f.read()
orig = len(c)

c = c.replace('exact old string', 'exact new string')

with open('path/to/file.html', 'w') as f:
    f.write(c)
print(f"Done. {orig} -> {len(c)} bytes")
```

### Prose description (acceptable for complex structural changes)
When a change involves inserting new HTML blocks, restructuring JS logic, or adding
new functions — and a Python replace would be brittle — describe the change in prose:
find X, replace with Y, using exact anchor strings. CCC interprets and executes.
In this case the verification script carries more weight — it must confirm the outcome,
not the method.

### File cleanup before commit
CCC removes any temp files it created (e.g. `_sNN_fix.py`, `_sNN_verify.py`) before
staging and committing. Stage only the target file(s):
```
git add path/to/file.html [path/to/other.html]
```
Never `git add .`

---

## Verification rules

### Python verification script (preferred)
Use a `python3 << 'PYEOF2'` block immediately after the change block.

**Every verification script checks:**
- All targeted strings present (new copy, replacement IDs, new functions)
- All old strings gone (find/replace worked, no regression)
- Structure: each of `</style>`, `</head>`, `<body`, `</body>`, `</html>` appears
  exactly once
- Zero visible em-dashes in non-script/non-style text nodes
- Any required IDs or function names that must not have been removed

```python
import re

with open('path/to/file.html') as f:
    c = f.read()

stripped = re.sub(r'<script[^>]*>.*?</script>', '', c, flags=re.DOTALL)
stripped = re.sub(r'<style[^>]*>.*?</style>', '', stripped, flags=re.DOTALL)
em = re.findall(r'>([^<]*\u2014[^<]*)<', stripped)

checks = [
    ('new string present',     'exact new string' in c),
    ('old string gone',        'exact old string' not in c),
    ('zero visible em-dashes', len(em) == 0),
    ('</style>', c.count('</style>') == 1),
    ('</head>',  c.count('</head>')  == 1),
    ('<body',    c.count('<body')    == 1),
    ('</body>',  c.count('</body>')  == 1),
    ('</html>',  c.count('</html>') == 1),
]
for label, ok in checks:
    print(f"[{'OK' if ok else 'FAIL'}] {label}")
```

### Manual checklist (acceptable for structural sessions)
Numbered list that CCC runs and reports. Must include at minimum:
1. div balance unchanged
2. script brace balance
3. `</body>` once, `</html>` once
4. Zero em-dashes in user-visible text
5. Specific outcome checks for this session

---

## JS rules (all sessions)

- The IIFE (block 2) is never modified. All new JS goes in block 3 only.
- Every `window.X = Y` export must have a matching function definition in the same
  block. Verify before every commit.
- `new Function()` on partial chunks gives false positives — only test complete blocks.
- Functions missing from the IIFE are defined in block 3 with a comment replacing
  their export line in block 2.

---

## Deploy order

SQL → PHP → HTML/JS. Never skip. Never reverse.

---

## Content rules (all user-facing text in every session)

- Zero em-dashes (`—`) in any visible text node, attribute, or tooltip.
  Placeholder empty states use en-dash (`–`). Titles use pipe (`|`).
- Zero AI tells. Banned: em-dashes, "I understand" repeated, "for the avoidance
  of doubt", passive constructions, tricolon not-X-not-Y-not-Z patterns.
- Grade 6 Australian plain English only.
- No content changes during formatting passes — formatting sessions touch only
  punctuation and whitespace, never meaning.

---

## Source of truth rules

- Website files: always clone the repo — never use chat attachments as base.
  Chat attachments are for legal documents and SQL dumps only.
- Always read the current file from the repo before writing any change.
  Never assume a file matches a previous session's output.
- SQL migrations deploy before any PHP or HTML that depends on them.

---

## Naming convention

```
ccc-session-NN-short-description.md       main session
ccc-session-NNb-short-description.md      follow-up for pre-existing out-of-scope issue
ccc-session-NNc-short-description.md      second follow-up if required
```

Session numbers are sequential across all files and do not reset per file.
If sessions 18 and 19 already exist, the next session is 20 regardless of file.

---

## Review branch is the diff gate

The review branch replaces the old "show diff → Thomas approves → commit to main"
pattern used in sessions 1–8. Thomas sees the full diff on the review branch.
Claude's independent verification is the sign-off gate. No merge to main without it.
