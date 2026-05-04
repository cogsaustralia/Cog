# CLAUDE SESSION REFERENCE
# Project: COG$ of Australia Foundation — cogsaustralia.org
# File: _design/CLAUDE_REFERENCE.md
# Purpose: Claude reads this at the start of every session to stay on course.
# Thomas pastes this into context when Claude loses track.

---

## What Claude does and does not do

Claude reads, verifies, and writes CCC prompts.
Claude never edits, creates, or modifies any website file directly.
All file edits, commits, and pushes are done by CCC (Claude Code).

---

## Session start checklist

Before anything else, every session:

1. Search past chats for the most recent deploy and ground truth state.
2. Check `_design/` for the highest existing session number — the next session is NN+1.
3. Clone or pull main: `git clone https://[CLASSIC_TOKEN]@github.com/cogsaustralia/Cog.git`
   (Token is in ~/.bash_profile or Thomas's password manager — never paste into this file)
4. Read the target file(s) from the repo. Never from memory. Never from a chat attachment.
5. State the file, the commit, and the current session number before doing anything else.

---

## The six things Claude does in a session

### 1. Ground truth
Read the exact current file from the repo.
Confirm every find string exists the correct number of times.
Never write a prompt based on assumed file state.

### 2. Write the session prompt
Save to `/home/claude/sessions/ccc-session-NN-description.md`.
Every prompt must contain in order:
- Header (session number, files, audit refs)
- `git pull --rebase origin main`
- "Read file before touching anything"
- Python replacement pass (preferred) or prose description (structural only)
- Python verification script
- `git checkout -b review/session-NN && git push origin review/session-NN`
- `## STOP -- wait for review before merging to main`

### 3. Push prompt to repo
Copy to `_design/ccc-session-NN-description.md` and push to main.
This is what CCC reads. Do not give Thomas the merge command yet.

### 4. Wait for CCC result
Thomas pastes the verification output. Do not proceed until it arrives.
Do not guess what the output will be.

### 5. Verify independently
Pull `review/session-NN`. Run a fresh Python verification script against the actual file.
Do not rely on CCC's own verification output alone — always re-check from the repo.

Check every session:
- All targeted new strings are present
- All targeted old strings are gone
- `</style>`, `</head>`, `<body`, `</body>`, `</html>` each appear exactly once
- Zero visible em-dashes (outside script and style blocks)
- No regressions from prior sessions (spot-check key IDs and functions)

If a FAIL is a genuine false positive (string appears in unrelated JS context):
read the exact lines, confirm the targeted block is fixed, document the reason,
and mark it OK with explanation.

If a FAIL is a pre-existing out-of-scope issue:
write a `session-NNb` prompt. Push it. Give Thomas the `git checkout review/session-NN`
run instruction. Verify NNb result. Then give combined merge command.

### 6. Give the merge command
Only after verification passes. Exact command:
```
git checkout main && git pull origin main && git merge review/session-NN &&
git push origin main && git branch -d review/session-NN &&
git push origin --delete review/session-NN
```
For combined sessions merge in order: NN first, then NNb, then NNc.
Then give the next session run command immediately.

---

## Prompt authoring rules

**Python replacement pass — use for all string replacements:**
```python
with open('path/to/file.html', 'r') as f:
    c = f.read()
orig = len(c)
c = c.replace('exact old string', 'exact new string')
with open('path/to/file.html', 'w') as f:
    f.write(c)
print(f"Done. {orig} -> {len(c)} bytes")
```
Block label: `python3 << 'PYEOF'` ... `PYEOF`
Never heredoc. Never `cat > file << 'EOF'`.

**Prose description — use only for structural changes** where Python replace would be
brittle (new functions, new HTML sections, JS logic restructure). The verification
script must confirm the outcome, not the method.

**Verification block label:** `python3 << 'PYEOF2'` ... `PYEOF2`

---

## JS rules

- IIFE (block 2) is never modified. All new JS goes in block 3 only.
- Every `window.X = Y` export must have a matching function definition. Verify before
  every session is pushed.
- `new Function()` on partial chunks gives false positives — test complete blocks only.

---

## Content rules — enforced in every session

- Zero em-dashes in any visible text, attribute, or tooltip.
  Empty-state placeholders → en-dash (`–`). Page titles → pipe (`|`).
- Zero AI tells: no em-dashes, no "I understand" repeated, no "for the avoidance of
  doubt", no passive constructions, no tricolon not-X-not-Y-not-Z patterns.
- Grade 6 Australian plain English in all copy strings.
- Formatting passes: punctuation and whitespace only — never change meaning.

---

## Deploy order

SQL → PHP → HTML/JS. Never skip. Never reverse.

---

## Source of truth

- Repo is ground truth for all website files.
- Chat attachments are ground truth for legal documents and SQL dumps only.
- Never assume a file matches a previous session's output — always read it.

---

## Naming and numbering

```
ccc-session-NN-short-description.md
ccc-session-NNb-short-description.md   (pre-existing out-of-scope issue)
ccc-session-NNc-short-description.md   (second follow-up if needed)
```

Session numbers are sequential across all files. Check `_design/` for the current
highest number before assigning NN. Do not reuse numbers.

---

## Key infrastructure

- Repo: `github.com/cogsaustralia/Cog`
- Clone token (classic, repo+workflow): stored in ~/.bash_profile as $COGS_GITHUB_TOKEN
  Never paste the raw token into any file in this repo
- Push pattern: `git pull --rebase origin main && git push origin main`
- Git config: `user.email "deploy@cogsaustralia.org"` / `user.name "COGs Deploy"`
- Server: `cogsaust@shorty.serversaurus.com.au` → `/home4/cogsaust/public_html`
- Live DB tunnel: `tunnel` then `livedb` aliases → forwards 3307 → live MariaDB
- DB: `cogsaust_TRUST` / user: `cogsaust`
- Local mirror: `mysql -u root -p cogs_mirror` (pw: Cogs2026!!)

---

## When Claude loses track

Thomas will paste this file into the conversation.
Claude reads it, runs the session start checklist above, states current position,
and continues from where the session left off.
Do not re-do work that is already merged to main.

---

## What is never done in this project

- Claude never edits a file directly
- Claude never gives a merge command without running independent verification first
- Claude never writes a session prompt without reading the current file from the repo
- Claude never uses heredoc for file creation in CCC prompts
- Claude never modifies the IIFE (block 2)
- Claude never skips the deploy order (SQL → PHP → HTML/JS)
- Claude never works from a chat attachment for website files
- Claude never guesses at file state from a previous session
