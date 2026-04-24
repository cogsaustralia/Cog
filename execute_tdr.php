<?php
declare(strict_types=1);

/**
 * execute_tdr.php
 * Trustee Decision Record — Electronic Execution
 *
 * Single-step: Trustee enters mobile number, engages acceptance flag, submits.
 * No witness required for TDRs — spec §13: minutes are not deeds.
 *
 * Access: one-time token ?token= delivered to sub-trust OTP email.
 */

require_once __DIR__ . '/_app/api/config/bootstrap.php';
require_once __DIR__ . '/_app/api/config/database.php';
require_once __DIR__ . '/_app/api/helpers.php';
require_once __DIR__ . '/_app/api/services/TrusteeDecisionService.php';

function tdr_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

function tdr_abort(int $code, string $msg): never {
    http_response_code($code);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Error</title>'
        . '<style>body{font-family:system-ui,sans-serif;background:#0d0d0d;color:#ccc;'
        . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
        . '.b{max-width:480px;text-align:center;padding:40px}'
        . 'h1{color:#b44;font-size:1.1rem}p{color:#888;font-size:.88rem;line-height:1.6}'
        . '</style></head><body><div class="b"><h1>' . tdr_h($msg) . '</h1>'
        . '<p>This link is invalid, expired, or already used. Contact the Foundation administrator.</p>'
        . '</div></body></html>';
    exit;
}

$rawToken = trim((string)($_GET['token'] ?? ''));
if ($rawToken === '' || strlen($rawToken) !== 64) tdr_abort(403, 'Invalid or missing token.');

try { $db = getDB(); } catch (\Throwable $e) { tdr_abort(500, 'Database unavailable.'); }

$tokenHash = hash('sha256', $rawToken);
$stmtToken = $db->prepare(
    "SELECT id, used_at, purpose FROM one_time_tokens
     WHERE token_hash = ? AND purpose LIKE 'tdr_execution:%' AND expires_at > UTC_TIMESTAMP() LIMIT 1"
);
$stmtToken->execute([$tokenHash]);
$tokenRow = $stmtToken->fetch(PDO::FETCH_ASSOC);
if (!$tokenRow) tdr_abort(403, 'Token is invalid, expired, or already used.');

$parts        = explode(':', (string)($tokenRow['purpose'] ?? ''), 2);
$decisionUuid = $parts[1] ?? '';
if ($decisionUuid === '') tdr_abort(403, 'Token does not reference a valid Trustee Decision Record.');

if ($tokenRow['used_at'] !== null) {
    $chk = $db->prepare("SELECT status FROM trustee_decisions WHERE decision_uuid = ? LIMIT 1");
    $chk->execute([$decisionUuid]);
    if ($chk->fetchColumn() === 'fully_executed') tdr_abort(200, 'This record has already been executed.');
    tdr_abort(403, 'Token is invalid, expired, or already used.');
}

$decision = TrusteeDecisionService::getDecision($db, $decisionUuid);
if (!$decision) tdr_abort(404, 'Trustee Decision Record not found.');
if ($decision['status'] !== 'pending_execution') tdr_abort(403, 'This record is not currently pending execution.');

$ipAddress = $_SERVER['REMOTE_ADDR']     ?? '';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$errors    = [];
$result    = null;
$step      = 'form';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mobileEntered = trim($_POST['mobile_number']  ?? '');
    $accepted      = !empty($_POST['acceptance_flag']);
    if ($mobileEntered === '') $errors[] = 'Mobile number is required to verify your identity.';
    if (!$accepted)            $errors[] = 'You must engage the acceptance flag to execute this Record.';
    if (empty($errors)) {
        try {
            $mobileData = TrusteeDecisionService::lookupMobile($db, $mobileEntered);
            $result     = TrusteeDecisionService::recordExecution($db, $decisionUuid, $ipAddress, $userAgent, $rawToken, $mobileData);
            $step = 'done';
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$subTrustLabel = [
    'sub_trust_a' => 'Sub-Trust A (Members Asset Pool)',
    'sub_trust_b' => 'Sub-Trust B (Dividend Distribution)',
    'sub_trust_c' => 'Sub-Trust C (Discretionary Charitable)',
    'all'         => 'All Sub-Trusts',
][$decision['sub_trust_context']] ?? $decision['sub_trust_context'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= tdr_h($decision['decision_ref']) ?> — Execute | COG$ Foundation</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#0d0e12;--panel:#14151a;--panel2:#1a1b22;--text:#d8d9e0;--sub:#7a7d8a;--dim:#555;
--gold:#c8a84b;--ok:#52b87a;--err:#c0553a;--warn:#d4944a;
--okb:rgba(82,184,122,.1);--warnb:rgba(212,148,74,.1);--errb:rgba(192,85,58,.1);
--line:rgba(255,255,255,.08);--line2:rgba(255,255,255,.05);--input:#1e1f28}
body{background:var(--bg);color:var(--text);font-family:system-ui,sans-serif;
     min-height:100vh;display:flex;align-items:flex-start;justify-content:center;padding:40px 16px}
.wrap{width:100%;max-width:700px}
.logo{font-size:.85rem;color:var(--gold);font-weight:700;letter-spacing:.06em;margin-bottom:28px}
.logo span{color:var(--sub);font-weight:400}
.card{background:var(--panel);border:1px solid var(--line);border-radius:12px;overflow:hidden;margin-bottom:18px}
.card-head{padding:18px 22px;border-bottom:1px solid var(--line)}
.card-head h1{font-size:1rem;font-weight:700;color:var(--gold);margin-bottom:4px}
.card-head p{font-size:.8rem;color:var(--sub);line-height:1.5}
.card-body{padding:20px 22px}
.dg{display:grid;grid-template-columns:180px 1fr;gap:6px 12px;font-size:.82rem;margin-bottom:16px}
.dg-l{color:var(--dim)}.dg-v{color:var(--text);word-break:break-word}.dg-v.gold{color:var(--gold);font-weight:700}
.resolution-box{background:var(--panel2);border:1px solid var(--line);border-left:3px solid var(--gold);
border-radius:6px;padding:14px 16px;font-size:.83rem;line-height:1.6;white-space:pre-wrap;word-break:break-word;margin-bottom:16px}
.powers-list{list-style:none;margin-bottom:16px}
.powers-list li{background:var(--panel2);border:1px solid var(--line2);border-radius:5px;padding:7px 12px;margin-bottom:5px;font-size:.8rem}
.powers-list li span{color:var(--gold);font-family:monospace;margin-right:8px}
.non-mis{background:var(--okb);border:1px solid rgba(82,184,122,.3);border-radius:6px;
padding:10px 14px;font-size:.78rem;color:var(--ok);margin-bottom:16px;line-height:1.5}
.section-title{font-size:.7rem;letter-spacing:.1em;text-transform:uppercase;color:var(--gold);font-weight:700;margin:14px 0 8px}
.fg{margin-bottom:16px}
.fg label{display:block;font-size:.78rem;color:var(--sub);margin-bottom:6px}
.fg input[type=tel]{width:100%;background:var(--panel2);border:1px solid var(--line);
border-radius:6px;color:var(--text);font-size:.95rem;padding:10px 12px}
.hint{font-size:.73rem;color:var(--dim);margin-top:5px}
.accept-block{background:var(--warnb);border:1px solid rgba(212,148,74,.3);border-radius:8px;padding:16px 18px;margin-bottom:16px}
.accept-block label{display:flex;gap:10px;align-items:flex-start;cursor:pointer;font-size:.83rem;line-height:1.5}
.accept-block input[type=checkbox]{margin-top:3px;flex-shrink:0}
.btn-exec{width:100%;padding:13px;border-radius:8px;font-size:.92rem;font-weight:700;cursor:pointer;
border:1px solid rgba(200,168,75,.4);background:rgba(200,168,75,.2);color:var(--gold)}
.btn-exec:hover{background:rgba(200,168,75,.35)}
.errors{background:var(--errb);border:1px solid rgba(192,85,58,.3);border-radius:7px;padding:10px 14px;font-size:.82rem;color:var(--err);margin-bottom:14px}
.success{background:var(--okb);border:1px solid rgba(82,184,122,.3);border-radius:10px;padding:22px 24px}
.success h2{font-size:1rem;color:var(--ok);margin-bottom:10px}
.success p{font-size:.82rem;color:var(--sub);line-height:1.6;margin-bottom:6px}
.hash-block{background:var(--panel2);border:1px solid var(--line);border-radius:6px;
padding:10px 14px;font-family:monospace;font-size:.75rem;color:var(--gold);word-break:break-all;margin-top:12px}
.required{color:var(--err)}
</style>
</head>
<body>
<div class="wrap">
  <div class="logo">COG$&#8482; FOUNDATION <span>&#8212; Trustee Record Execution</span></div>

  <div class="card">
    <div class="card-head">
      <h1><?= tdr_h($decision['decision_ref']) ?> &#8212; <?= tdr_h($decision['title']) ?></h1>
      <p><?= tdr_h($subTrustLabel) ?> &nbsp;&middot;&nbsp; Effective <?= tdr_h($decision['effective_date']) ?></p>
    </div>
    <div class="card-body">
      <div class="dg">
        <span class="dg-l">Reference</span><span class="dg-v gold"><?= tdr_h($decision['decision_ref']) ?></span>
        <span class="dg-l">Sub-Trust</span><span class="dg-v"><?= tdr_h($subTrustLabel) ?></span>
        <span class="dg-l">Effective Date</span><span class="dg-v"><?= tdr_h($decision['effective_date']) ?></span>
        <span class="dg-l">Executor</span><span class="dg-v"><?= tdr_h(TrusteeDecisionService::EXECUTOR_NAME) ?></span>
      </div>

      <div class="section-title">Powers Exercised</div>
      <ul class="powers-list">
        <?php foreach (json_decode((string)($decision['powers_json'] ?? '[]'), true) ?: [] as $p): ?>
          <li><span><?= tdr_h($p['clause_ref'] ?? '') ?></span><?= tdr_h($p['description'] ?? '') ?></li>
        <?php endforeach; ?>
      </ul>

      <div class="section-title">Resolution</div>
      <div class="resolution-box"><?= tdr_h($decision['resolution_md']) ?></div>

      <div class="non-mis">&#10003; Non-MIS Affirmation &#8212; <?= tdr_h(TrusteeDecisionService::NON_MIS_STATEMENT) ?></div>
    </div>
  </div>

  <?php if ($step === 'done'): ?>
  <div class="success">
    <h2>&#10003; Trustee Decision Record Executed</h2>
    <p><?= tdr_h($decision['decision_ref']) ?> has been cryptographically executed and anchored to the evidence vault.</p>
    <div class="hash-block">Record SHA-256: <?= tdr_h($result['record_sha256'] ?? '') ?></div>
    <p style="margin-top:10px;font-size:.76rem;color:var(--dim)">
      Executed: <?= tdr_h($result['execution_timestamp_utc'] ?? '') ?> UTC
      &nbsp;&middot;&nbsp; Evidence vault: #<?= tdr_h((string)($result['evidence_vault_id'] ?? '')) ?>
    </p>
  </div>

  <?php else: ?>
  <?php if (!empty($errors)): ?>
    <div class="errors"><?= implode('<br>', array_map('tdr_h', $errors)) ?></div>
  <?php endif; ?>
  <div class="card">
    <div class="card-head">
      <h1>Execute This Record</h1>
      <p>Review the record above in full. Enter your mobile number, then engage the acceptance flag.
         Once submitted this record is cryptographically fixed and cannot be altered.</p>
    </div>
    <div class="card-body">
      <form method="POST">
        <div class="fg">
          <label>Mobile Number <span class="required">*</span></label>
          <input type="tel" name="mobile_number" required autocomplete="tel"
                 value="<?= tdr_h($_POST['mobile_number'] ?? '') ?>" placeholder="04xx xxx xxx">
          <div class="hint">Enter the mobile number associated with your member record.
            This is recorded in the cryptographic audit trail.</div>
        </div>
        <div class="accept-block">
          <label>
            <input type="checkbox" name="acceptance_flag" required
                   <?= !empty($_POST['acceptance_flag']) ? 'checked' : '' ?>>
            I, <?= tdr_h(TrusteeDecisionService::EXECUTOR_NAME) ?>, execute Trustee Decision Record
            <?= tdr_h($decision['decision_ref']) ?> as Caretaker Trustee of <?= tdr_h($subTrustLabel) ?>
            of the COGS of Australia Foundation Community Joint Venture Mainspring Hybrid Trust.
            I have read and understood this Record and I resolve as stated.
            <?= tdr_h(TrusteeDecisionService::EXECUTION_METHOD) ?>
          </label>
        </div>
        <button type="submit" class="btn-exec">Execute Trustee Decision Record</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <p style="text-align:center;font-size:.73rem;color:var(--dim);margin-top:24px">
    COGS of Australia Foundation &nbsp;&middot;&nbsp; ABN 61 734 327 831
    &nbsp;&middot;&nbsp; Wahlubal Country, Bundjalung Nation
  </p>
</div>
</body>
</html>
