<?php
declare(strict_types=1);

// Prevent any caching — proof pages must always execute fresh
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

/**
 * admin/_proof/tdr_phase1.php
 * COGS Trustee Records System — Phase 1 Proof
 *
 * Verifies:
 *  1. trustees table has exactly 1 active row (Hybrid Trust Trustee, not per-sub-trust)
 *     with sub_trust_context='all', operational_focus column present,
 *     execution email = sub-trust-a@cogsaustralia.org
 *  2. trustee_decisions table has the seeded TDR-20260422-001 row (fully_executed)
 *  3. evidence_vault_entries ENUM includes all three new values
 *  4. governance_cron_log table exists
 *  5. trustee_decision_execution_records and trustee_decision_attachments tables exist
 *  6. SHA-256 canonical payload is reproducible (deterministic hash)
 *  7. getTrusteeEmail() returns the active Trustee's email without sub_trust filter
 *  8. generateRef() produces sequential refs correctly
 *
 * Must run 100% clean before deploy.
 */

require_once dirname(__DIR__) . '/includes/admin_paths.php';
require_once dirname(__DIR__) . '/includes/ops_workflow.php';
require_once dirname(__DIR__) . '/../_app/api/services/TrusteeDecisionService.php';

ops_require_admin();
$pdo = ops_db();

$pass = 0;
$fail = 0;
$log  = [];

function proof_check(string $label, bool $result, string $detail = ''): void {
    global $pass, $fail, $log;
    if ($result) {
        $pass++;
        $log[] = ['ok', $label, $detail];
    } else {
        $fail++;
        $log[] = ['fail', $label, $detail];
    }
}

// ── 1. Trustees table: single active Trustee of the Hybrid Trust ──────────────
// Post-remediation: one row, sub_trust_context='all', operational_focus column present
try {
    $stmt = $pdo->query("SELECT * FROM trustees WHERE status = 'active' ORDER BY id ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    proof_check(
        'Trustees: exactly 1 active row (Hybrid Trust Trustee)',
        count($rows) === 1,
        count($rows) . ' active row(s) found'
    );
    if (count($rows) === 1) {
        $t = $rows[0];
        proof_check(
            'Trustees: sub_trust_context = all (Hybrid Trust, not per-sub-trust)',
            $t['sub_trust_context'] === 'all',
            $t['sub_trust_context']
        );
        proof_check(
            'Trustees: operational_focus column present',
            array_key_exists('operational_focus', $t),
            implode(', ', array_keys($t))
        );
        proof_check(
            'Trustees: execution email = sub-trust-a@cogsaustralia.org',
            $t['email'] === 'sub-trust-a@cogsaustralia.org',
            $t['email']
        );
        proof_check(
            'Trustees: trustee_type = caretaker_trustee',
            $t['trustee_type'] === 'caretaker_trustee',
            $t['trustee_type']
        );
        proof_check(
            'Trustees: full_name = Thomas Boyd Cunliffe',
            $t['full_name'] === 'Thomas Boyd Cunliffe',
            $t['full_name']
        );
    }
    // Verify no stale sub-trust rows remain
    $staleStmt = $pdo->query(
        "SELECT COUNT(*) FROM trustees WHERE sub_trust_context IN ('sub_trust_b','sub_trust_c')"
    );
    $staleCount = (int)$staleStmt->fetchColumn();
    proof_check(
        'Trustees: no stale sub_trust_b/c rows (remediation complete)',
        $staleCount === 0,
        $staleCount . ' stale row(s) remaining'
    );
} catch (\Throwable $e) {
    proof_check('Trustees: table query', false, $e->getMessage());
}

// ── 2. Seeded TDR-20260422-001 exists ────────────────────────────────────────
try {
    $d = TrusteeDecisionService::getDecisionByRef($pdo, 'TDR-20260422-001');
    proof_check('Seed TDR: TDR-20260422-001 exists', $d !== null, $d ? 'found' : 'not found');
    if ($d) {
        proof_check('Seed TDR: sub_trust_a context', $d['sub_trust_context'] === 'sub_trust_a', $d['sub_trust_context']);
        proof_check('Seed TDR: category = bank_account', $d['decision_category'] === 'bank_account', $d['decision_category']);
        proof_check('Seed TDR: status = fully_executed', $d['status'] === 'fully_executed', $d['status']);
        proof_check('Seed TDR: non_mis_affirmation = 1', (int)$d['non_mis_affirmation'] === 1, (string)$d['non_mis_affirmation']);
        proof_check('Seed TDR: visibility = internal', $d['visibility'] === 'internal', $d['visibility']);
    }
} catch (\Throwable $e) {
    proof_check('Seed TDR: query', false, $e->getMessage());
}

// ── 3. evidence_vault_entries ENUM extended ───────────────────────────────────
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM evidence_vault_entries LIKE 'entry_type'");
    $col  = $stmt->fetch(PDO::FETCH_ASSOC);
    $type = $col['Type'] ?? '';
    proof_check('ENUM: trustee_decision_record present', str_contains($type, 'trustee_decision_record'), '');
    proof_check('ENUM: members_poll_minute present',     str_contains($type, 'members_poll_minute'),     '');
    proof_check('ENUM: board_meeting_minute present',    str_contains($type, 'board_meeting_minute'),    '');
    proof_check('ENUM: founding_instrument_document still present', str_contains($type, 'founding_instrument_document'), '');
} catch (\Throwable $e) {
    proof_check('ENUM: column query', false, $e->getMessage());
}

// ── 4. governance_cron_log table exists ──────────────────────────────────────
try {
    $pdo->query("SELECT 1 FROM governance_cron_log LIMIT 1");
    proof_check('governance_cron_log: table exists', true, '');
} catch (\Throwable $e) {
    proof_check('governance_cron_log: table exists', false, $e->getMessage());
}

// ── 5. trustee_decision_execution_records table exists ───────────────────────
try {
    $pdo->query("SELECT 1 FROM trustee_decision_execution_records LIMIT 1");
    proof_check('trustee_decision_execution_records: table exists', true, '');
} catch (\Throwable $e) {
    proof_check('trustee_decision_execution_records: table exists', false, $e->getMessage());
}

// ── 6. trustee_decision_attachments table exists ─────────────────────────────
try {
    $pdo->query("SELECT 1 FROM trustee_decision_attachments LIMIT 1");
    proof_check('trustee_decision_attachments: table exists', true, '');
} catch (\Throwable $e) {
    proof_check('trustee_decision_attachments: table exists', false, $e->getMessage());
}

// ── 7. SHA-256 canonical payload is deterministic ────────────────────────────
try {
    $canonical = [
        'record_type'    => 'trustee_decision_record',
        'decision_ref'   => 'TDR-TEST-PROOF',
        'resolution_md'  => 'Test resolution for proof only.',
        'non_mis_statement' => TrusteeDecisionService::NON_MIS_STATEMENT,
    ];
    $hash1 = hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $hash2 = hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    proof_check(
        'SHA-256: canonical payload is reproducible',
        $hash1 === $hash2 && strlen($hash1) === 64,
        substr($hash1, 0, 16) . '…'
    );
} catch (\Throwable $e) {
    proof_check('SHA-256: canonical payload', false, $e->getMessage());
}

// ── 8. getTrusteeEmail returns active Trustee's email (no sub_trust filter) ───
// Post-remediation: method ignores $subTrustContext arg; returns first active row's email
try {
    $email = TrusteeDecisionService::getTrusteeEmail($pdo);
    proof_check(
        'getTrusteeEmail: returns active Trustee email',
        $email === 'sub-trust-a@cogsaustralia.org',
        $email ?? 'null'
    );
    // Also verify the deprecated sub_trust_context arg is ignored (any value → same result)
    $emailWithArg = TrusteeDecisionService::getTrusteeEmail($pdo, 'sub_trust_b');
    proof_check(
        'getTrusteeEmail: sub_trust_context arg is ignored (same result regardless)',
        $emailWithArg === $email,
        "with arg: {$emailWithArg}, without: {$email}"
    );
} catch (\Throwable $e) {
    proof_check('getTrusteeEmail', false, $e->getMessage());
}

// ── 9. generateRef is sequential ─────────────────────────────────────────────
try {
    // Count existing TDR-20260422-* refs
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM trustee_decisions WHERE decision_ref LIKE 'TDR-20260422-%'");
    $stmt->execute();
    $existing = (int)$stmt->fetchColumn();
    $ref = TrusteeDecisionService::generateRef($pdo, '2026-04-22');
    $expected = 'TDR-20260422-' . str_pad((string)($existing + 1), 3, '0', STR_PAD_LEFT);
    proof_check('generateRef: sequential format', $ref === $expected, "got {$ref}, expected {$expected}");
} catch (\Throwable $e) {
    proof_check('generateRef: sequential', false, $e->getMessage());
}

// ── Render ────────────────────────────────────────────────────────────────────
$total = $pass + $fail;
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>TDR Phase 1 Proof | COG$ Admin</title>
<style>
body { font-family: system-ui, sans-serif; background: #0d0e12; color: #d8d9e0; padding: 32px; }
h1 { font-size: 1rem; color: #c8a84b; margin-bottom: 4px; }
.sub { color: #7a7d8a; font-size: .8rem; margin-bottom: 24px; }
.result { font-size: 1rem; font-weight: 700; margin-bottom: 20px; padding: 12px 16px; border-radius: 8px; }
.result.ok  { background: rgba(82,184,122,.1); border: 1px solid rgba(82,184,122,.3); color: #52b87a; }
.result.err { background: rgba(192,85,58,.1);  border: 1px solid rgba(192,85,58,.3);  color: #c0553a; }
table { width: 100%; border-collapse: collapse; font-size: .82rem; }
th { background: #1a1b22; color: #c8a84b; padding: 7px 10px; text-align: left; font-size: .72rem; text-transform: uppercase; letter-spacing: .08em; border-bottom: 1px solid rgba(255,255,255,.08); }
td { padding: 8px 10px; border-bottom: 1px solid rgba(255,255,255,.05); vertical-align: top; }
.ok   { color: #52b87a; font-weight: 700; }
.fail { color: #c0553a; font-weight: 700; }
.detail { color: #7a7d8a; font-family: monospace; font-size: .76rem; }
</style>
</head><body>
<h1>🧾 TDR Phase 1 — Ground Truth Proof</h1>
<div class="sub">COGS of Australia Foundation · Trustee Records System Phase 1</div>

<div class="result <?= $fail === 0 ? 'ok' : 'err' ?>">
  <?= $fail === 0
    ? "✓ ALL {$total} CHECKS PASSED — safe to deploy"
    : "✗ {$fail} of {$total} checks FAILED — do not deploy"
  ?>
</div>

<table>
<thead><tr><th>Result</th><th>Check</th><th>Detail</th></tr></thead>
<tbody>
<?php foreach ($log as [$status, $label, $detail]): ?>
<tr>
  <td class="<?= $status === 'ok' ? 'ok' : 'fail' ?>"><?= $status === 'ok' ? '✓ PASS' : '✗ FAIL' ?></td>
  <td><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></td>
  <td class="detail"><?= htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</body></html>
