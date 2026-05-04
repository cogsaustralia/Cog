# CCC Session: Skills Migration — repo _skills/ directory
# Move all skill and reference md files from scattered locations into _skills/ in the repo.
# Both Claude (chat, via session-start clone) and CCC (directly) can read them from here.
# Branch: review/skills-migration
# Commit message: feat(skills): migrate all skill+reference md files to _skills/ directory

## GROUND TRUTH CHECK

git log --oneline -3
echo "---"
ls _private/
echo "---"
ls _design/ccc-session-* | sort -V | tail -5
echo "---"
test -d _skills && echo "_skills EXISTS" || echo "_skills NOT YET CREATED"

## STOP — paste output above before proceeding.

## STEP 1 — Create branch and _skills/ directory

git checkout -b review/skills-migration
mkdir -p _skills

## STEP 2 — Write all five skill files using Python open().write()
## Never use heredoc. All content written inline.

python3 << 'PYEOF'
import os

# ── FILE 1: PROJECT_STATE.md ──────────────────────────────────────────────────
f1 = r"""# PROJECT_STATE.md
# COGs of Australia Foundation — Ground Truth Document Register
# Read this first. It defines what is active, what is reference, and what is retired.
# Last updated: 4 May 2026 · D-10

---

## CAMPAIGN STATE

**Active campaign:** Lead magnet funnel at `/seat/` + personal lead nurture.
**Cold path:** `/seat/` -> email capture -> confirmation email -> `/seat/inside/` -> phone capture.
**Active conversion table:** `lead_captures`
**Campaign run-sheet:** `_private/CAMPAIGN.md` in the repo (ground truth).
**Control phrase:** "Seat at the table."
**Strategy document:** `COGS_Marketing_Pivot_Brief_v1_0.docx` (Jordan Schwann playbook, 2 May 2026).

Fair Say Relay posts continue on FB and YT as content, but the Fair Say Relay
is NOT the campaign strategy. Do not read Fair Say Relay documents as the
active operational plan.

**Foundation Day:** Thursday 14 May 2026, 5:00pm AEST.

---

## DOCUMENT STATUS

### ACTIVE

| Document | Purpose |
|---|---|
| `_skills/PROJECT_STATE.md` | This file. Ground truth for all sessions. |
| `_skills/compliance-list.md` | Binding section 2 banned-framing list |
| `_skills/cogs-compliance-check.md` | OpenClaw skill: compliance check on demand |
| `_skills/cogs-fday-countdown.md` | OpenClaw skill: twice-daily Foundation Day countdown |
| `_skills/cogs-queue-monitor.md` | OpenClaw skill: lead captures monitor |
| `_private/CLAUDE.md` | Claude session instructions |
| `_private/CAMPAIGN.md` | Active 11-day campaign run-sheet |
| `_private/FOUNDATION.md` | Foundation Statement and Governing Principles |
| `_design/CLAUDE_REFERENCE.md` | Claude session reference |
| `COGS_Marketing_Pivot_Brief_v1_0.docx` | Active campaign strategy |
| `Foundation_Day_Script_FairSayRelay_v1_1.docx` | Foundation Day livestream script (Version A/B) |
| All trust/legal PDFs | Governing documents |
| All Corridors strategy documents | Partnership and infrastructure strategy |
| `COGs_Jubullum_Partnership_Proposal_v2_0.docx` | JLALC engagement |
| `Gundoo_Ngari_Node_Spec_v1_1.docx` | Sovereign Node hardware spec |

### REFERENCE (deployed, not active campaign path)

| Document | Notes |
|---|---|
| `COGS_FairSayRelay_Asset_Checklist.xlsx` | Fair Say Relay posts continue. Relay chain + voice submission funnel not the active conversion path. |
| `COGS_MemberVoiceSubmission_Implementation.docx` | Voice submission system deployed. Not the active cold funnel. |
| `phase4_member_voice_submissions_v1.sql` | Deployed. Reference only. |
| `queue_status.php` | Voice submission queue endpoint. Not the active monitor target. |
| `COGS_Blockchain_AI_Rollout_pdf.txt` | Deferred until Sovereign Node installed. |

---

## OPEN DEPENDENCIES

| Item | Owner | Due |
|---|---|---|
| JLALC Acknowledgement of Country sign-off | Thomas | D-8 (6 May) |
| `cogs-fday-countdown.md` milestone update from CAMPAIGN.md | Coordinator | This week |
| `admin/api/lead_status.php` endpoint | CCC | Before D-5 |
| Stripe upsell $1K/$10K tiers | CCC | D-2 (12 May) |

---

## POST-FOUNDATION DAY BACKLOG

1. Stripe upsell $1K/$10K tiers (Steve Keen gets 10% on $2K tier)
2. Vault visual overhaul: light mode, warm neutrals, remove coin spin
3. `wallets/index.html` entrypoint chooser
4. Telegram connector setup
5. Ty Keynes feasibility modelling
6. UGC/AI avatar strategy (Q3 2026, requires counsel)

---

*Update this file whenever a document moves between states or a new dependency opens.*
"""
open('_skills/PROJECT_STATE.md', 'w').write(f1)
print("Written: _skills/PROJECT_STATE.md")


# ── FILE 2: compliance-list.md ────────────────────────────────────────────────
f2 = r"""# compliance-list.md
# Section 2 Banned Framing — COGs Foundation Campaign

Workspace reference for cogs-compliance-check skill.
This list is binding for all public posts, member-facing messages, and consent copy.
Last updated: 4 May 2026.

---

## Hard prohibited phrases and concepts

These concepts must not appear in any form: not as direct statements,
not as implications, not as rhetorical questions, not in testimonials.

### Category A: Investment returns
- Any claim or suggestion that membership will produce a financial return
- "return on investment", "ROI", "yield", "profit", "earn money", "make money"
- "your $4 will grow", "your membership will be worth more"
- "investment opportunity", "invest in", "invest your $4"
- Any past-performance reference (e.g. "LGM shares have gone up")
- Any forward-looking financial projection (e.g. "shares could be worth...")
- Any comparison to other investments (e.g. "better than a bank account")
- "capital gains", "appreciation", "dividend income", "passive income"

### Category B: Financial product language
- "managed investment scheme", "MIS", "fund", "trust investment"
- "units in a fund", "units in a trust", "beneficial interests"
- "financial product", "financial service", "financial advice"
- "regulated by ASIC", "ASIC-registered", "ASIC-approved"
- "portfolio", "asset management", "wealth management"
- Any suggestion that COG$ is equivalent to a bank, super fund, or ETF

### Category C: Guaranteed outcomes
- "guaranteed", "guaranteed return", "guaranteed membership"
- "you will receive", "you are entitled to", "you have a right to"
  (unless referring to actual governance rights under the JVPA)
- "risk-free", "safe as", "secure investment"
- Any claim about future ASX share price

### Category D: Urgency or FOMO pressure
- "limited time offer", "offer expires", "last chance"
  (except on D-1 where factual deadline is allowed)
- "don't miss out", "act now or lose your chance"
- "prices are going up", "get in before..."
- Any countdown used to manufacture urgency
  (countdown to Foundation Day is factual: allowed)

### Category E: Unlicensed advice
- "you should buy/sell/hold [any financial instrument]"
- "this is a good investment", "this is a safe investment"
- Any statement that could be construed as personalised financial advice

### Category F: Misleading member claims
- "all Australians can join" (geofencing applies)
- "join from anywhere in the world" (domestic only)
- Any misstatement about the $4 once-only fee (e.g. "free", "only $4/year")
- Overstating governance rights (e.g. "you control the Foundation")

---

## Permitted framing

- "Join the Foundation's first community vote: $4, once only."
- "COG$ acquires and holds Australian resource company shares on behalf of members."
- "Members direct how the Foundation votes on company resolutions."
- "The Foundation holds Legacy Minerals Holdings (ASX: LGM) shares on the CHESS Register."
- "In-ground minerals have value. We hold the shares; we don't extract the resource."
- "Foundation Day is the first time members will vote together on [topic]."
- "Your $4 membership gives you a voice, not a managed investment."
- "Community governance backed by ASX-registered holdings."

---

## Borderline: requires Thomas's review before publishing

- Any reference to ASX share prices
- Member testimonials that mention financial value or personal benefit
- Comparisons to cooperatives, credit unions, or community banks
- Statements about Sub-Trust B dividend distribution
- Any post that references ASIC or regulatory status

---

## Member submission moderation

If a member's submitted text/audio/video includes any Category A to F language,
reject with a friendly rewrite suggestion:

"Thanks for your submission. We can't use it as written because it mentions
[brief description]. We don't include investment or financial return language
in our campaign. Would you like to resubmit focusing on why community
governance matters to you?"

---

Source: COG$ Master Implementation Plan v2.0, Section 2 Compliance Framework.
"""
open('_skills/compliance-list.md', 'w').write(f2)
print("Written: _skills/compliance-list.md")


# ── FILE 3: cogs-compliance-check.md ─────────────────────────────────────────
f3 = r"""# cogs-compliance-check

Slash command: evaluate draft post or submission text against the section 2 banned-framing list.
Returns Cleared, Pending, or Flagged with a specific reason and a rewrite suggestion.

## Trigger

Slash command: `/check <text>`
Also triggered by: `/c <text>` (short alias)

Example:
  /check Join the COG$ Foundation today. $4 once only. Cast your vote on Foundation Day.

## Model

claude-sonnet-4-6

## Mode

READ and REPLY only. No writes. No external calls.

## Steps

### 1. Extract the text to review

The text is everything after `/check ` or `/c ` in the message.
If the message is just `/check` with no text, reply:
`Usage: /check <draft post text>`

### 2. Load compliance-list.md

Read `_skills/compliance-list.md` from the repo.
This is the binding section 2 banned-framing reference.

### 3. Evaluate

Work through Categories A to F in compliance-list.md.
For each category, determine whether the submitted text contains any prohibited phrase,
concept, or implication including indirect implications, not just literal matches.

Assign one of three verdicts:

CLEARED: No issues found. Safe to schedule.
PENDING: Borderline language present. Needs Thomas's review before scheduling.
FLAGGED: One or more Category A to F violations present. Must not be published as written.

### 4. Format reply

If CLEARED:
  CLEARED
  "<first 60 chars of submitted text>..."
  No section 2 issues found. Safe to schedule.

If PENDING:
  PENDING: needs your review
  "<first 60 chars of submitted text>..."
  Borderline: <one-line description of what triggered review>
  Suggested revision: <one alternative sentence>

If FLAGGED:
  FLAGGED: do not publish
  "<first 60 chars of submitted text>..."
  Issue: <Category letter>: <one-line description of specific violation>
  Suggested rewrite: <one clean alternative version of the entire post>

If multiple issues are found, list each one on its own Issue line.
Keep the suggested rewrite to 2 to 3 sentences maximum.
The rewrite must use only approved framing from compliance-list.md.

### 5. No journal entry required

This is an on-demand interactive command. Do not log to the journal.

## Examples

Input: /check Your $4 will grow with the Foundation as we build Australia's community-owned resource base.

Output:
  FLAGGED: do not publish
  "Your $4 will grow with the Foundation as we build..."
  Issue: Category A: "will grow" implies financial return on membership
  Suggested rewrite: Join COG$ for $4, once only, and help build Australia's community-owned
  resource governance platform. Your vote. Your voice.

---

Input: /check Join the Foundation's first community vote. $4, once only. Foundation Day is 14 May.

Output:
  CLEARED
  "Join the Foundation's first community vote. $4, once only..."
  No section 2 issues found. Safe to schedule.

---

Input: /check COG$ holds ASX-listed shares similar to a managed investment scheme but community-owned.

Output:
  FLAGGED: do not publish
  "COG$ holds ASX-listed shares similar to a managed investment..."
  Issue: Category B: "similar to a managed investment scheme" is prohibited even with qualification
  Suggested rewrite: COG$ acquires and holds ASX-listed Australian resource company shares on
  behalf of members through a community joint venture structure, not a managed investment scheme.
"""
open('_skills/cogs-compliance-check.md', 'w').write(f3)
print("Written: _skills/cogs-compliance-check.md")


# ── FILE 4: cogs-queue-monitor.md ────────────────────────────────────────────
f4 = r"""# cogs-queue-monitor

Polls the live lead capture status via a read-only HTTPS endpoint.
Alerts Thomas if new leads are waiting for follow-up or going stale.
Silent when everything is within normal bounds.

## Trigger

Cron: `0 7,9,11,13,15,17,19 * * *`
Fires every two hours from 07:00 to 19:00 AEST.
Active from now through D+14 (28 May 2026).

## Model

claude-sonnet-4-6

## Mode

READ and NOTIFY only. One HTTPS GET call to the lead status endpoint.
No DB access. No file writes except journal.

## Endpoint

URL: `https://cogsaustralia.org/admin/api/lead_status.php`
Method: GET
Header: `Authorization: Bearer <COGS_QUEUE_API_KEY>`

The API key is stored at `~/.cogs-secrets/queue-api.key` (chmod 600).
Read the key from that file at runtime. Never hardcode it here.

## Expected response (JSON)

{
  "total_leads": 42,
  "new_today": 7,
  "uncontacted": 3,
  "oldest_uncontacted_seconds": 14400,
  "has_phone": 18,
  "generated_at": "2026-05-08T14:00:00+10:00"
}

Fields:
- total_leads: all records in lead_captures
- new_today: leads captured today (created_at date = today)
- uncontacted: leads with no follow-up recorded (contacted_at IS NULL)
- oldest_uncontacted_seconds: age in seconds of the oldest uncontacted lead
- has_phone: leads who completed the /seat/inside/ phone capture step

## Alert thresholds

| Condition | Threshold | Message |
|---|---|---|
| Stale uncontacted lead | oldest_uncontacted_seconds > 14400 (4 hours) | LEAD WAITING |
| Backlog building | uncontacted > 5 | LEAD BACKLOG |
| New lead arrived | new_today > 0 (first check after lead arrives) | NEW LEAD |

## Steps

### 1. Read API key

Read `~/.cogs-secrets/queue-api.key`. If file is missing or empty, send:
`cogs-queue-monitor: queue-api.key missing. Cannot check leads.`
Then stop.

### 2. Call endpoint

Make one HTTPS GET request with the Authorization header.
If HTTP response is not 200, send:
`cogs-queue-monitor: lead_status.php returned <status_code>. Check server.`
If response is not valid JSON, send:
`cogs-queue-monitor: Invalid JSON from lead_status.php.`
Then stop.

### 3. Evaluate thresholds

Check all three conditions. Collect alerts for each threshold exceeded.

### 4. Deduplicate

Before sending any LEAD WAITING or LEAD BACKLOG alert, check
`~/openclaw-cogs-journal.md` for the same alert type in the last 4 hours.
Suppress if found. Exception: if uncontacted > 10, override and always alert.

NEW LEAD alerts: send once per detection. Do not suppress.

### 5. Send to Telegram

If thresholds breached:

  LEAD REPORT [HH:MM AEST]
  Total: N  |  New today: N  |  With phone: N
  Uncontacted: N  |  Oldest waiting: Nh Mm
  Alert: <type>: <one-line description>
  Action: <one-line instruction>

If new lead only:

  NEW LEAD [HH:MM AEST]
  Total: N leads  |  New today: N
  Oldest uncontacted: Nh Mm
  Action: Follow up personally. Check admin panel for details.

Silence if nothing breached.

### 6. Log to journal

[YYYY-MM-DD HH:MM AEST] LEAD CHECK: total=N new_today=N uncontacted=N phone=N: <ALERT or OK>

## Error handling

Timeout after 10 seconds = connection error.
Send: `cogs-queue-monitor: Endpoint timeout. Site may be down.`
Three consecutive timeouts: disable this skill and alert Thomas.

## Dependency note

Requires `admin/api/lead_status.php` deployed on the server.
If endpoint does not exist, skill will alert on every run.
Build as a CCC session before activating.
"""
open('_skills/cogs-queue-monitor.md', 'w').write(f4)
print("Written: _skills/cogs-queue-monitor.md")


# ── FILE 5: cogs-fday-countdown.md ───────────────────────────────────────────
f5 = r"""# cogs-fday-countdown

Twice-daily Foundation Day countdown.
One short Telegram message each morning and evening until D+14.

## Trigger

Cron: `0 7,19 * * *`
Fires at 07:00 and 19:00 AEST every day.
Active from D-15 (29 Apr 2026) through D+14 (28 May 2026).
Outside that window, send nothing.

## Model

claude-sonnet-4-6

## Mode

READ and NOTIFY only. No writes. No social posts.

## Steps

### 1. Calculate countdown

Foundation Day is Thursday 14 May 2026 at 17:00 AEST.
Get the current datetime in Australia/Sydney timezone.
Calculate:
- Whole days remaining to Foundation Day (floor, not round)
- Hours remaining after subtracting whole days
- Whether today IS Foundation Day (D-0)
- Whether Foundation Day has passed (D+1 or later)

### 2. Identify next milestone

Read `_private/CAMPAIGN.md` and scan for the next milestone that has not yet passed.
The milestone is the earliest dated item in CAMPAIGN.md that is still in the future.

If CAMPAIGN.md cannot be read, fall back to:
- "Foundation Day Livestream (D-0, Thu 14 May, 5:00pm AEST)"

### 3. Format message

Before Foundation Day (D-15 to D-1):
  [D-XX] Foundation Day in N days, M hours.
  Next: <milestone name> on <date>.

Foundation Day morning (D-0, 07:00 run):
  TODAY IS FOUNDATION DAY.
  Livestream goes live at 5:00pm AEST.

Foundation Day evening (D-0, 19:00 run):
  Foundation Day complete.
  Stage 6 sustain phase begins tomorrow.

After Foundation Day (D+1 to D+14):
  [D+XX] Stage 6 sustain. N days since Foundation Day.
  Campaign closes <date>. Next: <milestone>.

Keep all messages under 200 characters.

### 4. Send to Telegram

Send one message. No journal entry required.

## Error handling

If calculation fails for any reason, send:
`cogs-fday-countdown: Failed to calculate countdown. Check CAMPAIGN.md dates.`
Do not retry. Wait for the next scheduled run.
"""
open('_skills/cogs-fday-countdown.md', 'w').write(f5)
print("Written: _skills/cogs-fday-countdown.md")

print("\nAll 5 files written to _skills/")
PYEOF

## STEP 3 — Verify all five files exist and are non-empty

python3 -c "
import os
files = [
    '_skills/PROJECT_STATE.md',
    '_skills/compliance-list.md',
    '_skills/cogs-compliance-check.md',
    '_skills/cogs-queue-monitor.md',
    '_skills/cogs-fday-countdown.md',
]
all_ok = True
for f in files:
    size = os.path.getsize(f) if os.path.exists(f) else 0
    status = 'OK' if size > 100 else 'FAIL'
    if status == 'FAIL':
        all_ok = False
    print(f'[{status}] {f} ({size} bytes)')
print()
print('ALL PASS' if all_ok else 'FAILURES ABOVE: DO NOT COMMIT')
"

## STOP — paste verification output. Proceed only if ALL PASS.

## STEP 4 — Update _private/CLAUDE.md to reference _skills/

## Read current CLAUDE.md and find the session-start section
python3 << 'PYEOF'
with open('_private/CLAUDE.md', 'r') as f:
    content = f.read()

# Print first 60 lines to locate the session-start section
lines = content.split('\n')
for i, line in enumerate(lines[:80], 1):
    print(f"{i:03d}: {line}")
PYEOF

## STOP — paste CLAUDE.md head. I will write the exact str.replace() for the
## session-start block based on what is there. Do not proceed past this point.

## STEP 5 — Stage, commit, push review branch

git config user.email "deploy@cogsaustralia.org"
git config user.name "COGs Deploy"
git add _skills/
git status
## STOP — confirm all 5 files staged before committing.

git commit -m "feat(skills): migrate all skill+reference md files to _skills/ directory"
git pull --rebase origin main
git push origin review/skills-migration

## STOP — paste push output for verification before merging to main.


---

## CLAUDE.MD CHANGES (str.replace, three edits)

### Edit 1: Section 2 — add PROJECT_STATE.md to session-start

OLD (exact):
**Ground truth before any action.**
Run: git status && git log --oneline -5
At session start and before any edit. Never assume a file matches a
previous session. Read it first.

NEW:
**Ground truth before any action.**
Run: git status && git log --oneline -5
Then read: _skills/PROJECT_STATE.md
PROJECT_STATE.md defines the active campaign, active documents, and open dependencies.
Read it at session start and before any edit. Never assume a file matches a
previous session. Read it first.

### Edit 2: Section 4 — add _skills/ and _archive/ to directory map

OLD (exact):
  _private/
    CLAUDE.md              this file
    FOUNDATION.md          Foundation Statement + Eight Governing Principles
    CAMPAIGN.md            Stage 4 campaign operations — active until 14 May 2026

NEW:
  _private/
    CLAUDE.md              this file
    FOUNDATION.md          Foundation Statement + Eight Governing Principles
    CAMPAIGN.md            Stage 4 campaign operations -- active until 14 May 2026
  _skills/               operational skill and reference files -- read by Claude (chat) and CCC
    PROJECT_STATE.md       ground truth: campaign state, active docs, open dependencies
    compliance-list.md     section 2 banned-framing list
    cogs-compliance-check.md  OpenClaw compliance check skill
    cogs-queue-monitor.md     OpenClaw lead captures monitor skill
    cogs-fday-countdown.md    OpenClaw Foundation Day countdown skill
  _archive/              superseded files -- reference only, never operational

### Edit 3: Section 13 — add _skills/ update trigger

OLD (exact):
9. The repo gains a permanent new top-level folder

NEW:
9. The repo gains a permanent new top-level folder
10. A skill in _skills/ changes its trigger, endpoint, or alert thresholds
