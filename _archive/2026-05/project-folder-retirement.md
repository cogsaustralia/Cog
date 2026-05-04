# _archive/2026-05/project-folder-retirement.md
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
