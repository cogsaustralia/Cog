<?php
declare(strict_types=1);

/**
 * execute_tdr.php
 * Trustee Decision Record — Electronic Execution Flow
 *
 * Access: one-time token ?token= delivered to sub-trust email.
 * Step A: Trustee acceptance (consumes execution token)
 * Step B: Witness attestation (uses witness token issued after Step A)
 *
 * Electronic Transactions Act 1999 (Cth) + s.14G ETA 2000 (NSW)
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

// ── Token + DB ────────────────────────────────────────────────────────────────
$rawToken = trim((string)($_GET['token'] ?? ''));
if ($rawToken === '' || strlen($rawToken) !== 64) {
    tdr_abort(403, 'Invalid or missing token.');
}

try { $db = getDB(); } catch (\Throwable $e) { tdr_abort(500, 'Database unavailable.'); }

// Determine token type: execution or witness
$tokenHash    = hash('sha256', $rawToken);
$stmtExec     = $db->prepare(
    "SELECT id, used_at, purpose FROM one_time_tokens
     WHERE token_hash = ? AND purpose LIKE 'tdr_execution:%' AND expires_at > UTC_TIMESTAMP() LIMIT 1"
);
$stmtExec->execute([$tokenHash]);
$execTokenRow = $stmtExec->fetch(PDO::FETCH_ASSOC);

$stmtWit     = $db->prepare(
    "SELECT id, used_at, purpose FROM one_time_tokens
     WHERE token_hash = ? AND purpose LIKE 'tdr_witness:%' AND expires_at > UTC_TIMESTAMP() LIMIT 1"
);
$stmtWit->execute([$tokenHash]);
$witTokenRow = $stmtWit->fetch(PDO::FETCH_ASSOC);

if (!$execTokenRow && !$witTokenRow) {
    tdr_abort(403, 'Token is invalid, expired, or already used.');
}

$isWitnessStep = ($witTokenRow && !$execTokenRow);
$tokenIsUsed   = $execTokenRow && $execTokenRow['used_at'] !== null;
$activeToken   = $isWitnessStep ? $witTokenRow : $execTokenRow;

// Decode decision_uuid from token purpose string e.g. "tdr_execution:uuid-here"
$parts        = explode(':', (string)($activeToken['purpose'] ?? ''), 2);
$decisionUuid = $parts[1] ?? '';
if ($decisionUuid === '') {
    tdr_abort(403, 'Token does not reference a valid Trustee Decision Record.');
}

$decision = TrusteeDecisionService::getDecision($db, $decisionUuid);
if (!$decision) {
    tdr_abort(404, 'Trustee Decision Record not found.');
}

$execRecords = TrusteeDecisionService::getExecutionRecords($db, $decisionUuid);
$ipAddress   = $_SERVER['REMOTE_ADDR']          ?? '';
$userAgent   = $_SERVER['HTTP_USER_AGENT']      ?? '';

$step    = 'A'; // trustee acceptance
$result  = null;
$errors  = [];

// ── Determine step ────────────────────────────────────────────────────────────
// If execution token is used OR we have an executor_complete record, show Step B (witness)
// If witness token present, definitely Step B
if ($isWitnessStep) {
    $step = 'B';
} elseif ($tokenIsUsed) {
    // Token used — recover session and go to Step B if execution is complete
    $hasExecComplete = false;
    foreach ($execRecords as $er) {
        if ($er['status'] === 'executor_complete' || $er['status'] === 'fully_executed') {
            $hasExecComplete = true;
            break;
        }
    }
    if (!$hasExecComplete) {
        tdr_abort(403, 'Execution token already used and no execution record found.');
    }
    $step = 'B_no_token'; // Show witness form but need witness token issued separately
}

// ── POST: Trustee acceptance (Step A) ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_step'] ?? '') === 'A') {
    $mobileEntered = trim($_POST['mobile_number'] ?? '');
    $accepted      = !empty($_POST['acceptance_flag']);

    if ($mobileEntered === '') {
        $errors[] = 'Mobile number is required to verify your identity before execution.';
    } elseif (!$accepted) {
        $errors[] = 'You must engage the acceptance flag to execute this Record.';
    } else {
        try {
            $mobileData = TrusteeDecisionService::lookupMobile($db, $mobileEntered);
            $result = TrusteeDecisionService::recordExecution($db, $decisionUuid, $ipAddress, $userAgent, $rawToken, $mobileData);
            // Issue witness token and store for page use
            $witnessRaw   = TrusteeDecisionService::generateExecutionToken($db, $decisionUuid, TrusteeDecisionService::TOKEN_PURPOSE_WITNESS);
            $witnessLink  = 'https://cogsaustralia.org/execute_tdr.php?token=' . urlencode($witnessRaw);
            $step = 'A_done';
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

// ── POST: Witness attestation (Step B) ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_step'] ?? '') === 'B') {
    $wFull = trim($_POST['witness_full_name']  ?? '');
    $wDob  = trim($_POST['witness_dob']        ?? '');
    $wOcc  = trim($_POST['witness_occupation'] ?? '');
    $wAddr = trim($_POST['witness_address']    ?? '');
    $wJP   = trim($_POST['witness_jp_number']  ?? '');
    $wCom  = trim($_POST['witness_comments']   ?? '');

    if ($wFull === '') $errors[] = 'Witness full name is required.';
    if ($wDob  === '') $errors[] = 'Witness date of birth is required.';
    if ($wOcc  === '') $errors[] = 'Witness occupation is required.';
    if ($wAddr === '') $errors[] = 'Witness address is required.';

    if (empty($errors)) {
        try {
            $result = TrusteeDecisionService::recordWitnessAttestation($db, $decisionUuid, [
                'full_name'  => $wFull,
                'dob'        => $wDob,
                'occupation' => $wOcc,
                'address'    => $wAddr,
                'jp_number'  => $wJP ?: null,
                'comments'   => $wCom ?: null,
            ], $rawToken, $ipAddress, $userAgent);
            $step = 'done';
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$subTrustLabel = [
    'sub_trust_a' => 'Sub-Trust A (Members Asset Pool)',
    'sub_trust_b' => 'Sub-Trust B',
    'sub_trust_c' => 'Sub-Trust C',
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
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --bg: #0d0e12; --panel: #14151a; --panel2: #1a1b22;
  --text: #d8d9e0; --sub: #7a7d8a; --dim: #555;
  --gold: #c8a84b; --ok: #52b87a; --err: #c0553a; --warn: #d4944a;
  --okb: rgba(82,184,122,.1); --warnb: rgba(212,148,74,.1); --errb: rgba(192,85,58,.1);
  --line: rgba(255,255,255,.08); --line2: rgba(255,255,255,.05);
}
body { background: var(--bg); color: var(--text); font-family: system-ui, sans-serif; min-height: 100vh;
       display: flex; align-items: flex-start; justify-content: center; padding: 40px 16px; }
.wrap { width: 100%; max-width: 700px; }
.logo { font-size: .85rem; color: var(--gold); font-weight: 700; letter-spacing: .06em; margin-bottom: 28px; }
.logo span { color: var(--sub); font-weight: 400; }
.card { background: var(--panel); border: 1px solid var(--line); border-radius: 12px; overflow: hidden; margin-bottom: 18px; }
.card-head { padding: 18px 22px; border-bottom: 1px solid var(--line); }
.card-head h1 { font-size: 1rem; font-weight: 700; color: var(--gold); margin-bottom: 4px; }
.card-head p  { font-size: .8rem; color: var(--sub); line-height: 1.5; }
.card-body { padding: 20px 22px; }
.dg { display: grid; grid-template-columns: 180px 1fr; gap: 6px 12px; font-size: .82rem; margin-bottom: 16px; }
.dg-l { color: var(--dim); }
.dg-v { color: var(--text); word-break: break-word; }
.dg-v.mono { font-family: monospace; font-size: .78rem; word-break: break-all; }
.dg-v.gold { color: var(--gold); font-weight: 700; }
.resolution-box {
  background: var(--panel2); border: 1px solid var(--line); border-left: 3px solid var(--gold);
  border-radius: 6px; padding: 14px 16px; font-size: .83rem; color: var(--text);
  line-height: 1.6; white-space: pre-wrap; word-break: break-word; margin-bottom: 16px;
}
.powers-list { list-style: none; margin-bottom: 16px; }
.powers-list li {
  background: var(--panel2); border: 1px solid var(--line2); border-radius: 5px;
  padding: 7px 12px; margin-bottom: 5px; font-size: .8rem;
}
.powers-list li span { color: var(--gold); font-family: monospace; margin-right: 8px; }
.non-mis {
  background: var(--okb); border: 1px solid rgba(82,184,122,.3); border-radius: 6px;
  padding: 10px 14px; font-size: .78rem; color: var(--ok); margin-bottom: 16px; line-height: 1.5;
}
.accept-block {
  background: var(--warnb); border: 1px solid rgba(212,148,74,.3); border-radius: 8px;
  padding: 16px 18px; margin-bottom: 16px;
}
.accept-block label { display: flex; gap: 10px; align-items: flex-start; cursor: pointer; font-size: .83rem; line-height: 1.5; }
.accept-block input[type=checkbox] { margin-top: 2px; flex-shrink: 0; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; font-size: .78rem; color: var(--sub); margin-bottom: 5px; }
.form-group input, .form-group textarea {
  width: 100%; background: var(--panel2); border: 1px solid var(--line);
  border-radius: 6px; color: var(--text); font-size: .83rem; padding: 8px 10px;
}
.form-group textarea { min-height: 70px; resize: vertical; }
.form-group .hint { font-size: .73rem; color: var(--dim); margin-top: 4px; }
.required { color: var(--err); }
.btn-exec {
  width: 100%; padding: 12px; border-radius: 8px; font-size: .9rem; font-weight: 700;
  cursor: pointer; border: none; background: rgba(200,168,75,.2);
  border: 1px solid rgba(200,168,75,.4); color: var(--gold); letter-spacing: .03em;
}
.btn-exec:hover { background: rgba(200,168,75,.35); }
.errors { background: var(--errb); border: 1px solid rgba(192,85,58,.3); border-radius: 7px; padding: 10px 14px; font-size: .82rem; color: var(--err); margin-bottom: 14px; }
.success { background: var(--okb); border: 1px solid rgba(82,184,122,.3); border-radius: 8px; padding: 18px 20px; }
.success h2 { font-size: .95rem; color: var(--ok); margin-bottom: 8px; }
.success p  { font-size: .82rem; color: var(--sub); line-height: 1.6; }
.hash-block {
  background: var(--panel2); border: 1px solid var(--line); border-radius: 6px;
  padding: 10px 14px; font-family: monospace; font-size: .75rem; color: var(--gold);
  word-break: break-all; margin-top: 10px;
}
.section-title {
  font-size: .7rem; letter-spacing: .1em; text-transform: uppercase;
  color: var(--gold); font-weight: 700; margin: 14px 0 8px;
}
.step-indicator { display: flex; gap: 8px; margin-bottom: 20px; }
.step-pill {
  padding: 4px 12px; border-radius: 20px; font-size: .73rem; font-weight: 700;
  background: var(--panel2); border: 1px solid var(--line2); color: var(--sub);
}
.step-pill.active { background: var(--warnb); border-color: rgba(212,148,74,.4); color: var(--warn); }
.step-pill.done   { background: var(--okb);   border-color: rgba(82,184,122,.3); color: var(--ok); }
</style>
</head>
<body>
<div class="wrap">
  <div class="logo">COG$™ FOUNDATION <span>— Trustee Record Execution</span></div>

  <!-- Step indicator -->
  <div class="step-indicator">
    <div class="step-pill <?= in_array($step, ['A_done','B','B_no_token','done'], true) ? 'done' : 'active' ?>">
      Step A — Trustee Acceptance
    </div>
    <div class="step-pill <?= ($step === 'done') ? 'done' : (in_array($step, ['B','A_done'], true) ? 'active' : '') ?>">
      Step B — Witness Attestation
    </div>
  </div>

  <!-- Record header card -->
  <div class="card">
    <div class="card-head">
      <h1><?= tdr_h($decision['decision_ref']) ?> — <?= tdr_h($decision['title']) ?></h1>
      <p><?= tdr_h($subTrustLabel) ?> &nbsp;·&nbsp; Effective <?= tdr_h($decision['effective_date']) ?></p>
    </div>
    <div class="card-body">
      <div class="dg">
        <span class="dg-l">Decision Reference</span><span class="dg-v gold"><?= tdr_h($decision['decision_ref']) ?></span>
        <span class="dg-l">Sub-Trust</span><span class="dg-v"><?= tdr_h($subTrustLabel) ?></span>
        <span class="dg-l">Effective Date</span><span class="dg-v"><?= tdr_h($decision['effective_date']) ?></span>
        <span class="dg-l">Executor</span><span class="dg-v"><?= tdr_h(TrusteeDecisionService::EXECUTOR_NAME) ?></span>
      </div>

      <div class="section-title">Powers Exercised</div>
      <ul class="powers-list">
        <?php
        $powers = json_decode((string)($decision['powers_json'] ?? '[]'), true) ?: [];
        foreach ($powers as $p): ?>
          <li><span><?= tdr_h($p['clause_ref'] ?? '') ?></span><?= tdr_h($p['description'] ?? '') ?></li>
        <?php endforeach; ?>
      </ul>

      <div class="section-title">Resolution</div>
      <div class="resolution-box"><?= tdr_h($decision['resolution_md']) ?></div>

      <div class="non-mis">
        ✓ Non-MIS Affirmation — <?= tdr_h(TrusteeDecisionService::NON_MIS_STATEMENT) ?>
      </div>
    </div>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="errors"><?= implode('<br>', array_map('tdr_h', $errors)) ?></div>
  <?php endif; ?>

  <?php if ($step === 'done'): ?>
  <!-- ── FULLY EXECUTED ─────────────────────────────────────────────── -->
  <div class="success">
    <h2>✓ Trustee Decision Record Fully Executed</h2>
    <p><?= tdr_h($decision['decision_ref']) ?> has been cryptographically executed and anchored to the evidence vault.</p>
    <div class="hash-block">Final Record SHA-256: <?= tdr_h($result['final_record_sha256'] ?? '') ?></div>
    <p style="margin-top:10px;font-size:.78rem;color:var(--dim)">
      Witness timestamp (UTC): <?= tdr_h($result['witness_timestamp_utc'] ?? '') ?><br>
      Evidence vault anchor: #<?= tdr_h((string)($result['witness_vault_id'] ?? '')) ?>
    </p>
  </div>

  <?php elseif ($step === 'A_done'): ?>
  <!-- ── STEP A COMPLETE — show witness link ────────────────────────── -->
  <div class="success">
    <h2>✓ Step A Complete — Trustee Acceptance Recorded</h2>
    <p>Your execution of <?= tdr_h($decision['decision_ref']) ?> has been recorded. A witness attestation is now required to finalise the record.</p>
    <p style="margin-top:10px;font-size:.82rem">
      A one-time witness link has been generated. Please provide this link to your witness:
    </p>
    <div class="hash-block" style="margin-top:8px;word-break:break-all;color:var(--warn)">
      <?= tdr_h($witnessLink ?? '(witness link — see email or ask admin)') ?>
    </div>
    <p style="margin-top:10px;font-size:.78rem;color:var(--dim)">
      Execution SHA-256: <?= tdr_h($result['record_sha256'] ?? '') ?>
    </p>
  </div>

  <?php elseif ($step === 'B' || $step === 'B_no_token'): ?>
  <!-- ── STEP B — Witness attestation ──────────────────────────────── -->
  <div class="card">
    <div class="card-head">
      <h1>Step B — Witness Attestation</h1>
      <p>
        The Trustee has executed this record. Please complete your witness details below.
        All fields except JP number and comments are required.
      </p>
    </div>
    <div class="card-body">
      <form method="POST">
        <input type="hidden" name="_step" value="B">
        <div class="form-group">
          <label>Full Name <span class="required">*</span></label>
          <input type="text" name="witness_full_name" required autocomplete="name"
                 value="<?= tdr_h($_POST['witness_full_name'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Date of Birth <span class="required">*</span></label>
          <input type="date" name="witness_dob" required
                 value="<?= tdr_h($_POST['witness_dob'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Occupation <span class="required">*</span></label>
          <input type="text" name="witness_occupation" required autocomplete="organization-title"
                 value="<?= tdr_h($_POST['witness_occupation'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Address <span class="required">*</span></label>
          <input type="text" name="witness_address" required autocomplete="street-address"
                 value="<?= tdr_h($_POST['witness_address'] ?? '') ?>">
          <div class="hint">Full street address including suburb, state, and postcode.</div>
        </div>
        <div class="form-group">
          <label>JP Registration Number <span style="color:var(--dim)">(optional)</span></label>
          <input type="text" name="witness_jp_number"
                 value="<?= tdr_h($_POST['witness_jp_number'] ?? '') ?>">
          <div class="hint">If you are a Justice of the Peace, enter your registration number.</div>
        </div>
        <div class="form-group">
          <label>Comments <span style="color:var(--dim)">(optional)</span></label>
          <textarea name="witness_comments"><?= tdr_h($_POST['witness_comments'] ?? '') ?></textarea>
        </div>
        <div class="accept-block" style="margin-top:8px">
          <label>
            <input type="checkbox" name="witness_acceptance" required>
            I attest that I have observed <?= tdr_h(TrusteeDecisionService::EXECUTOR_NAME) ?> execute
            Trustee Decision Record <?= tdr_h($decision['decision_ref']) ?> electronically.
            I am satisfied this is the same document I have reviewed.
            This attestation is given electronically under section 14G of the
            Electronic Transactions Act 2000 (NSW).
          </label>
        </div>
        <button type="submit" class="btn-exec" style="margin-top:16px">Submit Witness Attestation</button>
      </form>
    </div>
  </div>

  <?php else: ?>
  <!-- ── STEP A — Trustee acceptance ───────────────────────────────── -->
  <div class="card">
    <div class="card-head">
      <h1>Step A — Trustee Acceptance</h1>
      <p>
        Review the Trustee Decision Record above in full before engaging the acceptance flag.
        Once accepted, this record is cryptographically fixed and cannot be altered.
      </p>
    </div>
    <div class="card-body">
      <form method="POST">
        <input type="hidden" name="_step" value="A">

        <div style="margin-bottom:18px">
          <label style="display:block;font-size:.78rem;color:var(--sub);margin-bottom:6px">
            Mobile Number <span style="color:var(--err)">*</span>
          </label>
          <input type="tel" name="mobile_number" required
                 value="<?= tdr_h($_POST['mobile_number'] ?? '') ?>"
                 placeholder="04xx xxx xxx"
                 autocomplete="tel"
                 style="width:100%;box-sizing:border-box;background:var(--panel2);border:1px solid var(--line);
                        border-radius:6px;color:var(--text);font-size:.9rem;padding:9px 12px">
          <div style="font-size:.73rem;color:var(--dim);margin-top:5px">
            Enter the mobile number associated with your member record.
            This is used to verify your identity and is recorded in the cryptographic audit trail.
          </div>
        </div>

        <div class="accept-block">
          <label>
            <input type="checkbox" name="acceptance_flag" required>
            I, <?= tdr_h(TrusteeDecisionService::EXECUTOR_NAME) ?>, execute Trustee Decision Record
            <?= tdr_h($decision['decision_ref']) ?> as Caretaker Trustee of
            <?= tdr_h($subTrustLabel) ?> of the COGS of Australia Foundation Community Joint Venture
            Mainspring Hybrid Trust. I have read and understood this Record and I resolve as stated.
            <?= tdr_h(TrusteeDecisionService::EXECUTION_METHOD) ?>
          </label>
        </div>
        <button type="submit" class="btn-exec">Execute Trustee Decision Record</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <p style="text-align:center;font-size:.73rem;color:var(--dim);margin-top:24px">
    COGS of Australia Foundation &nbsp;·&nbsp; ABN 61 734 327 831
    &nbsp;·&nbsp; Wahlubal Country, Bundjalung Nation
  </p>
</div>
</body>
</html>
