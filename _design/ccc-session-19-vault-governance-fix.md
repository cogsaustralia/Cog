# CCC Session 19: _app/api/routes/vault.php — fix governance record detection for existing members
# Pull main before starting: git pull --rebase origin main
# Read vault.php before every edit. Show diff. STOP before committing.

## GROUND TRUTH RULES
- Read exact current file state before every edit
- Stage only _app/api/routes/vault.php
- php -l _app/api/routes/vault.php must pass after changes
- Commit to review/session-19 — do NOT push to main

---

## BUG DESCRIPTION
Existing members who already provided DOB and address are being shown the onboarding modal
on every vault login. When they try to submit, the API call may fail or succeed silently
but the modal reappears next visit.

Root cause: three lines in the vault payload construction read from the wrong sources.

---

## FIX 1 — street variable (around line 282)

FIND this exact line:
    $street = (string)($meta['street_address'] ?? ($legacy['street'] ?? ''));

REPLACE with:
    $street = (string)($member['street_address'] ?? ($meta['street_address'] ?? ($legacy['street'] ?? '')));

Reason: governance-complete writes to members.street_address. The members table is
authoritative. meta_json and legacy are fallbacks only.

---

## FIX 2 — date_of_birth in payload (around line 678)

FIND this exact line:
        'date_of_birth' => (string)($legacy['date_of_birth'] ?? ($member['date_of_birth'] ?? '')),

REPLACE with:
        'date_of_birth' => (string)($member['date_of_birth'] ?? ($legacy['date_of_birth'] ?? '')),

Reason: governance-complete writes to members.date_of_birth. The members table is
authoritative. Legacy is the fallback only.

---

## FIX 3 — governance_record_complete in payload (around line 679)

FIND this exact line:
        'governance_record_complete' => ($street !== '' && !empty($legacy['date_of_birth'] ?? ($member['date_of_birth'] ?? ''))),

REPLACE with:
        'governance_record_complete' => ($street !== '' && !empty($member['date_of_birth'] ?? ($legacy['date_of_birth'] ?? ''))),

Reason: $street is now fixed by Fix 1 so it correctly reflects members.street_address.
The DOB check should also prefer members table over legacy.

---

## VERIFICATION
After all three changes:

1. Run: php -l _app/api/routes/vault.php
   Must output: No syntax errors detected

2. Confirm the three changed lines read exactly as specified above

3. Confirm no other lines were changed

## COMMIT
git config user.email "deploy@cogsaustralia.org"
git config user.name "COGs Deploy"
git add _app/api/routes/vault.php
git commit -m "fix(vault): read street/dob/governance_complete from members table first — fixes onboarding loop for existing members"
git checkout -b review/session-19
git push origin review/session-19
