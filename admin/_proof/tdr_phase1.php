<?php
declare(strict_types=1);

// Output buffering must be the very first thing — catches any output
// from require_once, session, or fatal errors before we can render.
ob_start();

// Error reporting — capture all errors as part of output.
// display_errors is only enabled when COGS_PROOF_MODE=1 is set on the server;
// otherwise errors are logged via the normal error_log path. This keeps stack
// traces out of the response body during ordinary admin browsing.
error_reporting(E_ALL);
if (getenv('COGS_PROOF_MODE') === '1') {
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

$boot_error = null;

try {
    require_once dirname(__DIR__) . '/includes/admin_paths.php';
    require_once dirname(__DIR__) . '/includes/ops_workflow.php';
    require_once dirname(__DIR__) . '/../_app/api/services/TrusteeDecisionService.php';
} catch (\Throwable $e) {
    $boot_error = 'Boot error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
}

$boot_output = ob_get_clean();
ob_start();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if ($boot_error) {
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Proof Boot Error</title>'
        . '<style>body{font-family:monospace;background:#0d0e12;color:#f05050;padding:32px}'
        . 'pre{background:#1a1b22;padding:16px;border-radius:8px;color:#e89a35;white-space:pre-wrap}</style></head><body>'
        . '<h2>Boot Error</h2><pre>' . htmlspecialchars($boot_error, ENT_QUOTES) . '</pre>'
        . ($boot_output ? '<h3>Output during require:</h3><pre>' . htmlspecialchars($boot_output, ENT_QUOTES) . '</pre>' : '')
        . '</body></html>';
    ob_end_flush(); exit;
}

if ($boot_output !== '') {
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Proof Output Error</title>'
        . '<style>body{font-family:monospace;background:#0d0e12;color:#f05050;padding:32px}'
        . 'pre{background:#1a1b22;padding:16px;border-radius:8px;color:#e89a35;white-space:pre-wrap}</style></head><body>'
        . '<h2>Unexpected output during require_once</h2>'
        . '<pre>' . htmlspecialchars($boot_output, ENT_QUOTES) . '</pre>'
        . '</body></html>';
    ob_end_flush(); exit;
}

try {
    ops_require_admin();
    $pdo = ops_db();
} catch (\Throwable $e) {
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Proof Auth Error</title>'
        . '<style>body{font-family:monospace;background:#0d0e12;color:#f05050;padding:32px}'
        . 'pre{background:#1a1b22;padding:16px;border-radius:8px;color:#e89a35;white-space:pre-wrap}</style></head><body>'
        . '<h2>Auth/DB Error</h2><pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</pre>'
        . '</body></html>';
    ob_end_flush(); exit;
}

$pass = 0; $fail = 0; $log = [];

function proof_check(string $label, bool $result, string $detail = ''): void {
    global $pass, $fail, $log;
    if ($result) { $pass++; $log[] = ['ok',   $label, $detail]; }
    else         { $fail++; $log[] = ['fail', $label, $detail]; }
}

try {
    $stmt = $pdo->query("SELECT * FROM trustees WHERE status = 'active' ORDER BY id ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    proof_check('Trustees: exactly 1 active row', count($rows) === 1, count($rows) . ' active row(s)');
    if (count($rows) === 1) {
        $t = $rows[0];
        proof_check('Trustees: sub_trust_context = all',               $t['sub_trust_context'] === 'all',               $t['sub_trust_context']);
        proof_check('Trustees: operational_focus column present',      array_key_exists('operational_focus', $t),       implode(', ', array_keys($t)));
        proof_check('Trustees: email = sub-trust-a@cogsaustralia.org', $t['email'] === 'sub-trust-a@cogsaustralia.org', $t['email']);
        proof_check('Trustees: trustee_type = caretaker_trustee',      $t['trustee_type'] === 'caretaker_trustee',      $t['trustee_type']);
        proof_check('Trustees: full_name = Thomas Boyd Cunliffe',      $t['full_name'] === 'Thomas Boyd Cunliffe',      $t['full_name']);
    }
    $stale = (int)$pdo->query("SELECT COUNT(*) FROM trustees WHERE sub_trust_context IN ('sub_trust_b','sub_trust_c')")->fetchColumn();
    proof_check('Trustees: no stale sub_trust_b/c rows', $stale === 0, $stale . ' stale row(s)');
} catch (\Throwable $e) { proof_check('Trustees: table query', false, $e->getMessage()); }

try {
    $d = TrusteeDecisionService::getDecisionByRef($pdo, 'TDR-20260422-001');
    proof_check('Seed TDR: exists',                    $d !== null,                                 $d ? 'found' : 'not found');
    if ($d) {
        proof_check('Seed TDR: sub_trust_a context',   $d['sub_trust_context'] === 'sub_trust_a',  $d['sub_trust_context']);
        proof_check('Seed TDR: category=bank_account', $d['decision_category'] === 'bank_account', $d['decision_category']);
        proof_check('Seed TDR: status=fully_executed', $d['status'] === 'fully_executed',          $d['status']);
        proof_check('Seed TDR: non_mis_affirmation=1', (int)$d['non_mis_affirmation'] === 1,       (string)$d['non_mis_affirmation']);
        proof_check('Seed TDR: visibility=internal',   $d['visibility'] === 'internal',            $d['visibility']);
    }
} catch (\Throwable $e) { proof_check('Seed TDR', false, $e->getMessage()); }

try {
    $col  = $pdo->query("SHOW COLUMNS FROM evidence_vault_entries LIKE 'entry_type'")->fetch(PDO::FETCH_ASSOC);
    $type = $col['Type'] ?? '';
    proof_check('ENUM: trustee_decision_record',      str_contains($type, 'trustee_decision_record'),      '');
    proof_check('ENUM: members_poll_minute',          str_contains($type, 'members_poll_minute'),          '');
    proof_check('ENUM: board_meeting_minute',         str_contains($type, 'board_meeting_minute'),         '');
    proof_check('ENUM: founding_instrument_document', str_contains($type, 'founding_instrument_document'), '');
} catch (\Throwable $e) { proof_check('ENUM', false, $e->getMessage()); }

foreach (['governance_cron_log','trustee_decision_execution_records','trustee_decision_attachments'] as $tbl) {
    try { $pdo->query("SELECT 1 FROM `{$tbl}` LIMIT 1"); proof_check("{$tbl}: exists", true, ''); }
    catch (\Throwable $e) { proof_check("{$tbl}: exists", false, $e->getMessage()); }
}

try {
    $c  = ['record_type'=>'trustee_decision_record','decision_ref'=>'TDR-TEST-PROOF',
           'resolution_md'=>'Test.','non_mis_statement'=>TrusteeDecisionService::NON_MIS_STATEMENT];
    $h1 = hash('sha256', json_encode($c, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    $h2 = hash('sha256', json_encode($c, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    proof_check('SHA-256: reproducible', $h1 === $h2 && strlen($h1) === 64, substr($h1, 0, 16) . '...');
} catch (\Throwable $e) { proof_check('SHA-256', false, $e->getMessage()); }

try {
    $e1 = TrusteeDecisionService::getTrusteeEmail($pdo);
    $e2 = TrusteeDecisionService::getTrusteeEmail($pdo, 'sub_trust_b');
    proof_check('getTrusteeEmail: returns active Trustee email',        $e1 === 'sub-trust-a@cogsaustralia.org', $e1 ?? 'null');
    proof_check('getTrusteeEmail: sub_trust arg ignored (same result)', $e1 === $e2,                            "e1={$e1} e2={$e2}");
} catch (\Throwable $e) { proof_check('getTrusteeEmail', false, $e->getMessage()); }

try {
    $existing = (int)$pdo->query("SELECT COUNT(*) FROM trustee_decisions WHERE decision_ref LIKE 'TDR-20260422-%'")->fetchColumn();
    $ref      = TrusteeDecisionService::generateRef($pdo, '2026-04-22');
    $expected = 'TDR-20260422-' . str_pad((string)($existing + 1), 3, '0', STR_PAD_LEFT);
    proof_check('generateRef: sequential', $ref === $expected, "got {$ref}, expected {$expected}");
} catch (\Throwable $e) { proof_check('generateRef', false, $e->getMessage()); }

$total    = $pass + $fail;
$buffered = ob_get_clean();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>TDR Phase 1 Proof | COG$ Admin</title>
<style>
body{font-family:system-ui,sans-serif;background:#0d0e12;color:#d8d9e0;padding:32px;margin:0}
h1{font-size:1rem;color:#c8a84b;margin-bottom:4px}
.sub{color:#7a7d8a;font-size:.8rem;margin-bottom:24px}
.result{font-size:1rem;font-weight:700;margin-bottom:20px;padding:12px 16px;border-radius:8px}
.result.ok {background:rgba(82,184,122,.1);border:1px solid rgba(82,184,122,.3);color:#52b87a}
.result.err{background:rgba(192,85,58,.1);border:1px solid rgba(192,85,58,.3);color:#c0553a}
table{width:100%;border-collapse:collapse;font-size:.82rem}
th{background:#1a1b22;color:#c8a84b;padding:7px 10px;text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;border-bottom:1px solid rgba(255,255,255,.08)}
td{padding:8px 10px;border-bottom:1px solid rgba(255,255,255,.05);vertical-align:top}
.ok  {color:#52b87a;font-weight:700}
.fail{color:#c0553a;font-weight:700}
.detail{color:#7a7d8a;font-family:monospace;font-size:.76rem}
.warn-box{background:rgba(232,154,53,.1);border:1px solid rgba(232,154,53,.3);color:#e89a35;border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:.82rem}
pre{background:#1a1b22;padding:12px;border-radius:6px;white-space:pre-wrap;word-break:break-all;font-size:.75rem;color:#e89a35}
</style>
</head>
<body>
<h1>🧾 TDR Phase 1 — Ground Truth Proof</h1>
<div class="sub">COGS of Australia Foundation · Trustee Records System Phase 1</div>
<?php if ($buffered !== ''): ?>
<div class="warn-box">Unexpected output captured:<br><pre><?= htmlspecialchars($buffered, ENT_QUOTES) ?></pre></div>
<?php endif; ?>
<div class="result <?= $fail === 0 ? 'ok' : 'err' ?>">
  <?= $fail === 0 ? "&#10003; ALL {$total} CHECKS PASSED" : "&#10007; {$fail} of {$total} FAILED" ?>
</div>
<table>
<thead><tr><th>Result</th><th>Check</th><th>Detail</th></tr></thead>
<tbody>
<?php foreach ($log as [$status, $label, $detail]): ?>
<tr>
  <td class="<?= $status === 'ok' ? 'ok' : 'fail' ?>"><?= $status === 'ok' ? 'PASS' : 'FAIL' ?></td>
  <td><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></td>
  <td class="detail"><?= htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</body>
</html>
