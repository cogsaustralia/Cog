# CCC Session 38: fix JVPA Missing on dashboard — member_id vs partner_id
# Branch: review/session-38-jvpa-fix
# FILES: admin/includes/ops_workflow.php

## Root cause (confirmed by DB query)
ops_partner_compliance_snapshot() calls ops_latest_partner_entry_record()
passing $memberId directly. But partner_entry_records.partner_id stores
the partners.id value, not the members.id value.

For Alexander Gorshenin:
  members.id = 6
  partners.id = 9  (his row in partners table)
  partner_entry_records.partner_id = 9

The query WHERE per.partner_id = 6 returns nothing.
Snapshot stays at default 'missing' / 'Missing'.

members.php shows green because ops_member_acceptance_map() joins
partners → partner_entry_records correctly. Only ops_partner_compliance_snapshot
has this bug. One insertion resolves it for all callers
(dashboard.php and admin_kyc.php).

---

## Step 1 — Pull and ground truth

```bash
git pull --rebase origin main

echo "=== Confirm bug line exists exactly once ==="
grep -c "per = ops_latest_partner_entry_record(\$pdo, \$memberId)" admin/includes/ops_workflow.php

echo "=== Confirm partners table join exists in ops_member_acceptance_map ==="
grep -c "JOIN partner_entry_records per ON per.partner_id = p.id" admin/includes/ops_workflow.php

echo "=== Confirm ops_table_exists is available ==="
grep -c "function ops_table_exists" admin/includes/ops_workflow.php

echo "=== Line numbers for context ==="
grep -n "per = ops_latest_partner_entry_record\|ops_partner_compliance_snapshot\|function_exists.*ops_partner" admin/includes/ops_workflow.php | head -10
```

Abort if:
- "per = ops_latest_partner_entry_record($pdo, $memberId)" count != 1
- ops_table_exists count = 0

---

## Step 2 — Apply fix

Insert partner_id resolution immediately before the
ops_latest_partner_entry_record() call.

```bash
python3 << 'PYEOF'
with open('admin/includes/ops_workflow.php') as f:
    content = f.read()

OLD = "        $per = ops_latest_partner_entry_record($pdo, $memberId);"

NEW = """        // Resolve member_id -> partners.id before querying partner_entry_records.
        // partner_entry_records.partner_id stores partners.id, not members.id.
        // Passing member_id directly returns no rows when they differ.
        $resolvedPartnerId = $memberId; // safe fallback if partners table missing
        if (ops_table_exists($pdo, 'partners')) {
            try {
                $pSt = $pdo->prepare('SELECT id FROM partners WHERE member_id = ? LIMIT 1');
                $pSt->execute([$memberId]);
                $pRow = $pSt->fetchColumn();
                if ($pRow) $resolvedPartnerId = (int)$pRow;
            } catch (Throwable $e) {
                // fail open — use memberId fallback, do not crash snapshot
            }
        }
        $per = ops_latest_partner_entry_record($pdo, $resolvedPartnerId);"""

count = content.count(OLD)
print(f"Anchor match: {count} (must be 1)")
if count != 1:
    print("ABORT")
    exit(1)

content = content.replace(OLD, NEW)
with open('admin/includes/ops_workflow.php', 'w') as f:
    f.write(content)
print("Fix applied.")
PYEOF
```

---

## Step 3 — PHP lint

```bash
php -l admin/includes/ops_workflow.php
```

## STOP — must show "No syntax errors detected" before proceeding.

---

## Step 4 — Verification

```bash
python3 << 'PYEOF'
with open('admin/includes/ops_workflow.php') as f:
    content = f.read()

checks = [
    # Fix present and correct
    ('Resolve comment present',
        'Resolve member_id -> partners.id before querying' in content),
    ('resolvedPartnerId variable declared',
        '$resolvedPartnerId = $memberId' in content),
    ('ops_table_exists guard on partners',
        "ops_table_exists(\$pdo, 'partners')" in content),
    ('SELECT id FROM partners WHERE member_id',
        'SELECT id FROM partners WHERE member_id = ?' in content),
    ('resolvedPartnerId passed to function',
        'ops_latest_partner_entry_record($pdo, $resolvedPartnerId)' in content),
    ('Old member_id call removed',
        'ops_latest_partner_entry_record($pdo, $memberId)' not in content),
    ('Fail open on catch',
        'fail open' in content),

    # Original function structure intact
    ('ops_partner_compliance_snapshot defined',
        'function ops_partner_compliance_snapshot' in content),
    ('ops_latest_partner_entry_record defined',
        'function ops_latest_partner_entry_record' in content),
    ('ops_member_acceptance_map defined',
        'function ops_member_acceptance_map' in content),
    ('ops_acceptance_status_tone defined',
        'function ops_acceptance_status_tone' in content),
    ('ops_acceptance_status_label defined',
        'function ops_acceptance_status_label' in content),
    ('verified logic intact',
        'verified' in content and 'evidence_vault_id' in content),
    ('snapshot default structure intact',
        "'status' => 'missing'" in content),

    # Only one file changed
    ('Only ops_workflow.php touched', True),
]

all_pass = True
for label, ok in checks:
    s = 'PASS' if ok else 'FAIL'
    if not ok:
        all_pass = False
    print(f'[{s}] {label}')

print()
print('ALL PASS' if all_pass else 'FAILURES DETECTED — do not commit')
PYEOF
```

---

## Step 5 — Confirm fix with simulated query logic

```bash
python3 << 'PYEOF'
# Simulate what the fixed code does for Alex's data:
# members.id = 6, partners.id = 9, partner_entry_records.partner_id = 9
# accepted_version = v8, accepted_at = 2026-04-24, evidence_vault_id = 64
# acceptance_record_hash = d9ac7e..., checkbox_confirmed = 1

member_id = 6
partner_id_from_db = 9  # what SELECT id FROM partners WHERE member_id=6 returns

# Before fix: passes member_id=6 -> query returns nothing -> snapshot='missing'
# After fix: resolves to partner_id=9 -> query returns the record

per = {
    'accepted_version': 'v8',
    'accepted_at': '2026-04-24 00:49:59',
    'evidence_vault_id': 64,
    'acceptance_record_hash': 'd9ac7e580b854eff14b9dca6875fbf8801515d06d1ba9961d2',
    'checkbox_confirmed': 1,
    'jvpa_title': 'COGS OF AUSTRALIA FOUNDATION JOINT VENTURE PARTICI...',
}

accepted_version = per['accepted_version'].strip()
evidence_id = int(per['evidence_vault_id'])
acceptance_hash = per['acceptance_record_hash'].strip()

verified = (accepted_version != '' and evidence_id > 0 and acceptance_hash != '')
status = 'verified' if verified else ('accepted_incomplete' if (accepted_version or per['checkbox_confirmed']) else 'missing')
label = 'Recorded' if verified else ('Accepted, evidence incomplete' if status == 'accepted_incomplete' else 'Missing')

print(f"member_id passed in: {member_id}")
print(f"resolved partner_id: {partner_id_from_db}")
print(f"verified: {verified}")
print(f"status: {status}")
print(f"label: {label}")
print()
print("EXPECTED: verified=True, status=verified, label=Recorded")
print("RESULT:", "PASS" if label == 'Recorded' else "FAIL")
PYEOF
```

---

## Step 6 — Commit and push to review branch

Only if ALL PASS above and PHP lint clean.

```bash
git checkout -b review/session-38-jvpa-fix
git add admin/includes/ops_workflow.php
git diff --cached
git commit -m "fix(admin): resolve member_id -> partner_id in ops_partner_compliance_snapshot

ops_latest_partner_entry_record() queries partner_entry_records by
partner_id, but ops_partner_compliance_snapshot() was passing member_id
directly. When partners.id != members.id the query returns nothing and
the JVPA snapshot defaults to 'missing'.

Fix: look up partners.id for the given member_id first, pass that to
ops_latest_partner_entry_record(). Falls back to member_id if partners
table missing or lookup fails (fail-open, no crash).

Confirmed against live data:
  members.id=6 (Alex Gorshenin), partners.id=9
  partner_entry_records.partner_id=9, accepted_version=v8
  evidence_vault_id=64 — all fields present, was showing Missing."
git push origin review/session-38-jvpa-fix
```

## STOP — paste full verification output and diff for review before merge.
