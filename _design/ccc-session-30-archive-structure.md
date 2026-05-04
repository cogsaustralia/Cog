# CCC Session 30 — Archive Structure and Project Folder Retirement
# Creates _archive/ in the repo with a manifest of what has been retired.
# Documents what was in the Claude.ai project folder and where it now lives.
# Branch: review/session-30-archive-structure
# Commit: feat(archive): create _archive/ directory and project folder retirement manifest

## GROUND TRUTH

git log --oneline -3
echo "---"
test -d _archive && echo "_archive EXISTS" || echo "_archive NOT YET CREATED"
test -d _skills && echo "_skills EXISTS" || echo "_skills NOT YET CREATED"

## STOP — paste output. _skills must exist (session 29 merged). _archive must NOT yet exist.

## STEP 1 — Branch

git checkout -b review/session-30-archive-structure

## STEP 2 — Create _archive/ and write manifest

mkdir -p _archive/2026-05

python3 << 'INNEREOF'

manifest = """# _archive/2026-05/project-folder-retirement.md
# COGs Foundation — Claude.ai Project Folder Retirement Record
# Date: 4 May 2026
# Reason: Project folder was a duplicate source of truth causing instruction drift.
#         The repo is now the single ground truth for all operational files.
#         Claude (chat) reads _skills/ at session start. CCC reads _skills/ directly.
#         Claude.ai project folder has been cleared by the Caretaker Trustee.

---

## What was in the project folder

These documents were uploaded to the Claude.ai project folder and have been retired from it.
They remain available on the Caretaker's iMac at ~/cogs/docs/ and in the repo where applicable.

### Skill and reference files (now in _skills/ in the repo)

| Old location | New location | Status |
|---|---|---|
| compliance-list.md | _skills/compliance-list.md | Migrated and updated |
| cogs-compliance-check.md | _skills/cogs-compliance-check.md | Migrated and updated |
| cogs-queue-monitor.md | _skills/cogs-queue-monitor.md | REWRITTEN: now monitors lead_captures |
| cogs-fday-countdown.md | _skills/cogs-fday-countdown.md | Migrated: milestones now read from CAMPAIGN.md |
| ground_truth_findings.md | _skills/ (not migrated -- DB-specific, per-session) | Reference only |

### Strategy and operations documents (available at ~/cogs/docs/ on iMac)

| Document | Category | Status |
|---|---|---|
| COGS_Marketing_Pivot_Brief_v1_0 | Campaign strategy | Active -- upload to chat if needed |
| Foundation_Day_Script_FairSayRelay_v1_1 | Foundation Day | Active -- upload to chat if needed |
| COGS_Geofencing_and_Location_Eligibility_Specification | Operations | Active |
| COGS_Godley_Accounting_Specification_v1_0 | Operations | Active |
| COGS_System_Valuation_Report_v1_0 | Plans | Active |
| COGS_AI_Layer_Strategy_v1 | Plans | Active |
| black-and-white-paper_2026 | Outreach | Active |
| TrusteeRecordsSystem_PlanAndStrategy_v1 | Operations | Active |
| COGS_Holdings_Monitor_Routine_v1_0 | Operations | Active |
| COGS_ASIC_Monitor_Routine_v1_0 | Operations | Active |
| COGS_Steve_Keen_Outcome_Brief_v1_0 | Partners | Active |
| COGS_Canada_Partnership_Analysis_April2026_Rev3 | Partners | Reference |
| COGs_Jubullum_Partnership_Proposal_v2_0 | Partners | Active |
| CRC_Pilot_Concept_Brief_Jubullum_v1_0 | Partners | Active |
| Gundoo_Ngari_Node_Spec_v1_1 | Plans | Active |
| FNAC_Briefing_Note_Santos_Origin_v1_1 | Legal | Active |
| Dual_Poor_ESG_Target_Acquisition_Strategy_v1_1 | Plans | Active |
| Corridors_Transport_and_Freight_Planning_Strategy_v1_1 | Corridors | Active |
| Corridors_Energy_Electricity_Planning_Strategy_v1_1 | Corridors | Active |
| Corridors_Energy_LPG_Planning_Strategy_v1_1 | Corridors | Active |
| Corridors_Communications_Planning_Strategy_v1_1 | Corridors | Active |
| COGS_Resource_Corridors_Master_Strategy_v1_0 | Corridors | Active |

### Legal governing documents (available at ~/cogs/docs/legal/ on iMac)

| Document | Notes |
|---|---|
| 1COGS_JVPA.pdf | Joint Venture Partnership Agreement -- executed instrument |
| 2CJVM_Hybrid_Trust_Declaration.pdf | Hybrid Trust Declaration -- executed instrument |
| 6COGS_Initial_Trust_Property.pdf | Initial Trust Property -- executed instrument |
| COGS_SubTrustA.pdf | Sub-Trust A deed -- executed instrument |
| COGS_SubTrustB.pdf | Sub-Trust B deed -- executed instrument |
| COGS_SubTrustC.pdf | Sub-Trust C deed -- executed instrument |
| Charity_registration_application.pdf | ACNC application |

### Fair Say Relay and voice submission infrastructure (reference, deployed)

| Document | Status |
|---|---|
| COGS_FairSayRelay_Asset_Checklist.xlsx | Reference. Posts continue. Relay chain not active. |
| COGS_MemberVoiceSubmission_Implementation.docx | Reference. System deployed, not active funnel. |
| phase4_member_voice_submissions_v1.sql | Reference. Deployed migration. |
| queue_status.php | Reference. Voice queue endpoint. Not the active monitor. |
| COGS_MemberVoiceSubmission_Implementation.docx | Reference. |

---

## How to access documents in future Claude sessions

1. Skill files: Claude reads _skills/ automatically at session start via repo clone.
2. Strategy docs: Thomas uploads the specific document to the chat when it is needed.
   Do not upload all documents by default. Upload the one relevant to the task at hand.
3. Legal instruments: Thomas uploads only when a task directly requires the deed text.
4. Large strategy docs: available at ~/cogs/docs/ on the iMac. Thomas opens them locally.

---

## What changed in OpenClaw workspace

Old skill files at ~/.openclaw/workspace/ should be replaced or symlinked to the repo.
Recommended: ln -s ~/cogs/repo/_skills ~/.openclaw/workspace/skills
Thomas to run once on the iMac after session 29 is merged.

---

Archived by: Project Coordinator (Claude)
Authorised by: Caretaker Trustee, Thomas Boyd Cunliffe
Date: 4 May 2026
"""

with open('_archive/2026-05/project-folder-retirement.md', 'w') as f:
    f.write(manifest)
print("Written: _archive/2026-05/project-folder-retirement.md")

readme = """# _archive/

Superseded files and retirement records.
These are reference only. Never use anything in this directory as an operational source.

## Structure

_archive/YYYY-MM/    monthly archive folders
  *.md               retirement records and change notes

## Principle

The repo and _skills/ are the operational ground truth.
Git history is the version history.
This folder is for structured retirement records and manifests only.
"""

with open('_archive/README.md', 'w') as f:
    f.write(readme)
print("Written: _archive/README.md")

INNEREOF

## STEP 3 — Verify

python3 -c "
import os
files = ['_archive/README.md', '_archive/2026-05/project-folder-retirement.md']
ok = True
for f in files:
    s = os.path.getsize(f) if os.path.exists(f) else 0
    status = 'OK' if s > 100 else 'FAIL'
    if status == 'FAIL': ok = False
    print(f'[{status}] {f} ({s} bytes)')
print()
print('ALL PASS' if ok else 'FAILURES -- DO NOT PROCEED')
"

## STOP -- paste verification. Proceed only if ALL PASS.

## STEP 4 -- Stage and diff

git add _archive/
git diff --cached | cat

## STOP -- paste full diff for Thomas review. Proceed only on approval.

## STEP 5 -- Commit and push

git commit -m "feat(archive): create _archive/ directory and project folder retirement manifest"
git pull --rebase origin main
git push origin review/session-30-archive-structure

## STOP -- paste push output. Wait for merge instruction from Thomas via Coordinator.

## ── THOMAS ACTION REQUIRED AFTER MERGE ──────────────────────────────────────
##
## 1. Clear the Claude.ai project folder:
##    Remove all uploaded documents from the project.
##    The repo is now the ground truth. The project folder is no longer used.
##
## 2. Link OpenClaw skills to repo:
##    Run once on the iMac terminal:
##    ln -s ~/cogs/repo/_skills ~/.openclaw/workspace/skills
##
## 3. Confirm the link works:
##    ls ~/.openclaw/workspace/skills/
##    You should see the 5 skill files from the repo.
##
## ─────────────────────────────────────────────────────────────────────────────
