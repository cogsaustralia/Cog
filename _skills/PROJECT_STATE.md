# PROJECT_STATE.md
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
