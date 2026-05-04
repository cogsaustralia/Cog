# cogs-compliance-check

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
