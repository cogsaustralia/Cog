<?php
declare(strict_types=1);

/**
 * trustee/declare.php
 * Declaration Execution Flow — Thomas Boyd Cunliffe
 * Two-capacity deed execution: Declarant then Caretaker Trustee
 * Electronic Transactions Act 1999 (Cth) + s.14G ETA 2000 (NSW)
 *
 * Access: one-time token ?token= delivered out-of-band.
 * Step A: Declarant capacity
 * Step B: Caretaker Trustee capacity
 * On completion of both: witness token generated for Alex.
 */

require_once __DIR__ . '/../_app/api/config/bootstrap.php';
require_once __DIR__ . '/../_app/api/config/database.php';
require_once __DIR__ . '/../_app/api/helpers.php';
require_once __DIR__ . '/../_app/api/services/DeclarationExecutionService.php';

function de_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

function de_abort(int $code, string $msg): never {
    http_response_code($code);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Error</title>'
        . '<style>body{font-family:system-ui,sans-serif;background:#0d0d0d;color:#ccc;'
        . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
        . '.b{max-width:480px;text-align:center;padding:40px}'
        . 'h1{color:#b44;font-size:1.1rem}p{color:#888;font-size:.88rem;line-height:1.6}'
        . '</style></head><body><div class="b"><h1>' . de_h($msg) . '</h1>'
        . '<p>This link is invalid, expired, or already used. Contact the Foundation administrator.</p>'
        . '</div></body></html>';
    exit;
}

// ── Token + DB ────────────────────────────────────────────────────────────────
$rawToken = trim((string)($_GET['token'] ?? ''));
if ($rawToken === '' || strlen($rawToken) !== 64) de_abort(403, 'Invalid or missing token.');

try { $db = getDB(); } catch (\Throwable $e) { de_abort(500, 'Database unavailable.'); }

// Validate token exists (but do NOT consume yet — consumed on each capacity POST)
$tokenHash = hash('sha256', $rawToken);
$stmt = $db->prepare(
    'SELECT id FROM one_time_tokens
     WHERE token_hash = ? AND purpose = \'declaration_execution\'
       AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()
     LIMIT 1'
);
$stmt->execute([$tokenHash]);
if (!$stmt->fetch()) de_abort(403, 'Token is invalid, expired, or already used.');

// ── Session ID: stored in one_time_tokens notes field via URL param or generated
// We pass session_id as a second URL param after first capacity completes
$sessionId = trim((string)($_GET['session'] ?? ''));
if ($sessionId === '') $sessionId = ''; // will be generated on first POST

// ── Active session from DB (if exists)
$activeSession = null;
if ($sessionId !== '') {
    $activeSession = DeclarationExecutionService::getSession($db, $sessionId);
}

// Determine which capacities are already done
$doneCaps = [];
if ($activeSession) {
    foreach ($activeSession['records'] as $r) {
        $doneCaps[] = $r['capacity'];
    }
}
$declarantDone = in_array('declarant', $doneCaps, true);
$trusteeDone   = in_array('caretaker_trustee', $doneCaps, true);
$bothDone      = $declarantDone && $trusteeDone;

// ── Deed SHA-256 (computed once, stored in deed_version_anchors after first capacity)
$deedSha256 = DeclarationExecutionService::getDeedSha256($db);
// If not yet in DB, use the known hash of the uploaded PDF
// This is set on first recordExecution() call
if (!$deedSha256) {
    // SHA-256 must match the PDF on the server — fetched live from deed_version_anchors
    // after first execution. For display before first execution, show placeholder.
    $deedSha256Display = '(computed on first execution)';
} else {
    $deedSha256Display = $deedSha256;
}

// ── POST handler ──────────────────────────────────────────────────────────────
$postError = null;
$postResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $capacity    = trim((string)($_POST['capacity'] ?? ''));
    $postedToken = trim((string)($_POST['token'] ?? ''));
    $postedSid   = trim((string)($_POST['session_id'] ?? ''));
    $cb1         = ($_POST['cb_read']     ?? '') === '1';
    $cb2         = ($_POST['cb_capacity'] ?? '') === '1';
    $cb3         = ($_POST['cb_identity'] ?? '') === '1';
    $postedHash  = trim((string)($_POST['deed_sha256'] ?? ''));

    if ($postedToken !== $rawToken) {
        $postError = 'Token mismatch. Reload the page and try again.';
    } elseif (!in_array($capacity, ['declarant', 'caretaker_trustee'], true)) {
        $postError = 'Invalid capacity value.';
    } elseif (!$cb1 || !$cb2 || !$cb3) {
        $postError = 'All three confirmations must be actively engaged.';
    } elseif ($postedHash === '' || strlen($postedHash) !== 64) {
        $postError = 'Deed SHA-256 is missing or invalid.';
    } else {
        // Generate session_id on first capacity
        if ($postedSid === '') $postedSid = (function() {
            $d = random_bytes(16);
            $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
            $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
        })();

        // Consume token only on first capacity
        if (!$declarantDone && !$trusteeDone) {
            if (!DeclarationExecutionService::validateOneTimeToken($db, $rawToken, 'declaration_execution')) {
                de_abort(403, 'Token is no longer valid.');
            }
        }

        try {
            $postResult = DeclarationExecutionService::recordExecution(
                $db, $capacity, $postedSid, $postedHash,
                getClientIp(), (string)($_SERVER['HTTP_USER_AGENT'] ?? '')
            );
            $sessionId = $postedSid;
            $activeSession = DeclarationExecutionService::getSession($db, $sessionId);
            $doneCaps = array_column($activeSession['records'], 'capacity');
            $declarantDone = in_array('declarant', $doneCaps, true);
            $trusteeDone   = in_array('caretaker_trustee', $doneCaps, true);
            $bothDone      = $declarantDone && $trusteeDone;
            // Update deedSha256 display
            $deedSha256 = DeclarationExecutionService::getDeedSha256($db) ?? $postedHash;
            $deedSha256Display = $deedSha256;
        } catch (\Throwable $e) {
            $postError = 'Execution failed: ' . $e->getMessage();
        }
    }
}

// Build the URL for the second capacity step (token is already consumed —
// but we allow the page to re-render with session param appended)
$pageUrl = '?token=' . urlencode($rawToken)
    . ($sessionId !== '' ? '&session=' . urlencode($sessionId) : '');

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Declaration Execution — COG$ Foundation</title>
<style>
*,*::before,*::after{box-sizing:border-box}
:root{
  --bg:#0d0d0d;--panel:#141414;--panel2:#1a1a1a;
  --line:rgba(255,255,255,.08);--line2:rgba(255,255,255,.13);
  --text:#e8e4d8;--sub:#aaa;--dim:#666;
  --gold:#d4b25c;--goldb:rgba(212,178,92,.12);--goldbr:rgba(212,178,92,.3);
  --ok:#52b87a;--okb:rgba(82,184,122,.12);
  --err:#c0553a;--errb:rgba(192,85,58,.12);
  --warn:#d4944a;
}
body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,sans-serif;font-size:15px;line-height:1.6}
.shell{max-width:820px;margin:0 auto;padding:40px 24px 80px}
.crest{text-align:center;margin-bottom:32px}
.crest .org{font-size:.72rem;letter-spacing:.15em;text-transform:uppercase;color:var(--dim);margin-bottom:6px}
.crest h1{font-size:1.1rem;font-weight:700;color:var(--gold);margin:0 0 4px}
.crest .sub{font-size:.82rem;color:var(--sub)}
.card{background:var(--panel);border:1px solid var(--line2);border-radius:12px;padding:28px 32px;margin-bottom:20px}
.card-title{font-size:.7rem;letter-spacing:.12em;text-transform:uppercase;color:var(--gold);font-weight:700;margin-bottom:16px}
.step-done{border-color:rgba(82,184,122,.35)}
.step-done .card-title{color:var(--ok)}
.step-active{border-color:var(--goldbr)}
.step-pending{opacity:.45;pointer-events:none}
.hash-box{font-family:monospace;font-size:.78rem;color:var(--gold);background:var(--goldb);
  border:1px solid var(--goldbr);border-radius:6px;padding:10px 14px;word-break:break-all;margin-bottom:16px}
.hash-box .lbl{color:var(--dim);font-size:.72rem;display:block;margin-bottom:4px}
.dl{display:inline-block;font-size:.82rem;color:var(--gold);text-decoration:underline;margin-bottom:18px}
.cb-row{display:flex;align-items:flex-start;gap:14px;padding:14px;
  background:var(--panel2);border:1px solid var(--line);border-radius:8px;margin-bottom:10px}
.cb-row input{width:20px;height:20px;flex-shrink:0;margin-top:2px;accent-color:var(--gold);cursor:pointer}
.cb-row label{font-size:.88rem;color:var(--text);cursor:pointer;line-height:1.5}
.cb-row label strong{color:var(--gold)}
.decl{background:var(--panel2);border:1px solid var(--line);border-radius:8px;
  padding:14px 18px;margin:12px 0 16px;font-size:.84rem;color:var(--sub);line-height:1.7}
.btn-exec{width:100%;padding:15px;border:2px solid var(--goldbr);border-radius:10px;
  background:var(--goldb);color:var(--gold);font-size:1rem;font-weight:700;cursor:pointer}
.btn-exec:disabled{opacity:.35;cursor:not-allowed}
.btn-exec:not(:disabled):hover{background:rgba(212,178,92,.22);border-color:rgba(212,178,92,.5)}
.alert{padding:13px 17px;border-radius:8px;font-size:.88rem;margin-bottom:18px;line-height:1.5}
.alert-err{background:var(--errb);border:1px solid rgba(192,85,58,.3);color:var(--err)}
.alert-ok{background:var(--okb);border:1px solid rgba(82,184,122,.3);color:var(--ok)}
.done-card{background:var(--panel);border:2px solid rgba(82,184,122,.35);border-radius:12px;padding:28px 32px;margin-bottom:20px}
.done-card h2{color:var(--ok);font-size:1rem;margin:0 0 20px}
.drow{display:flex;gap:16px;margin-bottom:12px;flex-wrap:wrap}
.dlbl{font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:var(--dim);min-width:180px;padding-top:2px}
.dval{font-family:monospace;font-size:.82rem;color:var(--text);word-break:break-all}
.dval.gold{color:var(--gold)}
.notice{background:var(--panel2);border:1px solid var(--line);border-radius:8px;
  padding:14px 18px;font-size:.82rem;color:var(--sub);line-height:1.6;margin-top:20px}
.progress{display:flex;gap:8px;margin-bottom:28px}
.prog-step{flex:1;padding:10px;border-radius:8px;text-align:center;font-size:.78rem;font-weight:700}
.prog-done{background:var(--okb);border:1px solid rgba(82,184,122,.3);color:var(--ok)}
.prog-active{background:var(--goldb);border:1px solid var(--goldbr);color:var(--gold)}
.prog-pending{background:var(--panel2);border:1px solid var(--line);color:var(--dim)}
</style>
</head>
<body>
<div class="shell">
<div class="crest">
  <div class="org">COG$ of Australia Foundation</div>
  <h1>Declaration Execution — Deed</h1>
  <div class="sub">Electronic execution under ETA 1999 (Cth) and s.14G ETA 2000 (NSW)</div>
</div>

<?php if ($postError): ?>
  <div class="alert alert-err"><?= de_h($postError) ?></div>
<?php endif; ?>

<?php if ($bothDone): ?>
<!-- ── Both capacities complete ───────────────────────────────────────────── -->
<div class="done-card">
  <h2>✓ Both Execution Capacities Complete</h2>
  <?php foreach ($activeSession['records'] as $r): ?>
  <div style="margin-bottom:20px;padding-bottom:20px;border-bottom:1px solid var(--line)">
    <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.1em;color:var(--gold);margin-bottom:12px">
      <?= de_h(ucwords(str_replace('_', ' ', $r['capacity']))) ?>
    </div>
    <div class="drow"><div class="dlbl">Record ID</div><div class="dval gold"><?= de_h($r['record_id']) ?></div></div>
    <div class="drow"><div class="dlbl">UTC Timestamp</div><div class="dval"><?= de_h($r['execution_timestamp_utc']) ?></div></div>
    <div class="drow"><div class="dlbl">Deed SHA-256</div><div class="dval gold"><?= de_h($r['deed_sha256']) ?></div></div>
    <div class="drow"><div class="dlbl">Record SHA-256</div><div class="dval gold"><?= de_h($r['record_sha256']) ?></div></div>
    <div class="drow"><div class="dlbl">On-Chain Ref</div><div class="dval"><?= de_h((string)$r['onchain_commitment_txid']) ?></div></div>
  </div>
  <?php endforeach; ?>
  <div class="notice">
    Both execution capacities are complete. The witness attestation step is now required
    to finalise the deed under section 14G of the Electronic Transactions Act 2000 (NSW).
    The administrator must generate the witness token and deliver it to
    <strong style="color:var(--gold)"><?= de_h(DeclarationExecutionService::WITNESS_NAME) ?></strong>
    out-of-band. The witness must complete their attestation before this deed is fully executed.
  </div>
</div>

<?php else: ?>
<!-- ── Execution flow ─────────────────────────────────────────────────────── -->

<!-- Progress indicator -->
<div class="progress">
  <div class="prog-step <?= $declarantDone ? 'prog-done' : (!$declarantDone ? 'prog-active' : 'prog-pending') ?>">
    Step A<br>Declarant
  </div>
  <div class="prog-step <?= $declarantDone && !$trusteeDone ? 'prog-active' : ($trusteeDone ? 'prog-done' : 'prog-pending') ?>">
    Step B<br>Caretaker Trustee
  </div>
  <div class="prog-step prog-pending">
    Step C<br>Witness Attestation
  </div>
</div>

<!-- Deed presentation (shown for both capacities) -->
<div class="card">
  <div class="card-title">Declaration — <?= de_h(DeclarationExecutionService::DEED_VERSION) ?></div>
  <div class="hash-box">
    <span class="lbl">Deed PDF SHA-256 (<?= de_h(DeclarationExecutionService::DEED_VERSION) ?>)</span>
    <?= de_h($deedSha256Display) ?>
  </div>
  <a class="dl" href="../docs/<?= de_h(DeclarationExecutionService::DEED_PDF) ?>" target="_blank" rel="noopener">
    ↓ Download full Declaration PDF
  </a>
</div>

<?php if ($declarantDone): ?>
<!-- Step A complete notice -->
<div class="card step-done">
  <div class="card-title">✓ Step A — Declarant Capacity Complete</div>
  <p style="font-size:.85rem;color:var(--sub);margin:0">
    Declarant execution record generated at
    <?= de_h(($activeSession['records'][0]['execution_timestamp_utc'] ?? '')) ?>.
    Proceed to Step B below.
  </p>
</div>
<?php endif; ?>

<!-- ── Capacity form ──────────────────────────────────────────────────────── -->
<?php
$currentCap = $declarantDone ? 'caretaker_trustee' : 'declarant';
$stepLabel   = $declarantDone ? 'Step B — Caretaker Trustee Capacity' : 'Step A — Declarant Capacity';
$ackText     = $currentCap === 'declarant'
    ? DeclarationExecutionService::DECLARANT_ACKNOWLEDGEMENT
    : DeclarationExecutionService::TRUSTEE_ACKNOWLEDGEMENT;
$capLabel = $currentCap === 'declarant' ? 'Declarant' : 'Caretaker Trustee';
?>
<form method="POST" action="<?= de_h($pageUrl) ?>" id="execForm">
  <input type="hidden" name="token"      value="<?= de_h($rawToken) ?>">
  <input type="hidden" name="session_id" value="<?= de_h($sessionId) ?>">
  <input type="hidden" name="capacity"   value="<?= de_h($currentCap) ?>">
  <input type="hidden" name="deed_sha256" value="<?= de_h($deedSha256 ?? '') ?>">

  <div class="card step-active">
    <div class="card-title"><?= de_h($stepLabel) ?></div>

    <div class="decl"><?= nl2br(de_h($ackText)) ?></div>

    <div class="cb-row">
      <input type="checkbox" id="cb_read" name="cb_read" value="1">
      <label for="cb_read">
        I have read the full <strong><?= de_h(DeclarationExecutionService::DEED_TITLE) ?></strong>
        (<?= de_h(DeclarationExecutionService::DEED_VERSION) ?>) and understand its terms.
      </label>
    </div>

    <div class="cb-row">
      <input type="checkbox" id="cb_capacity" name="cb_capacity" value="1">
      <label for="cb_capacity">
        I accept the acknowledgement above in my capacity as
        <strong><?= de_h($capLabel) ?></strong> and execute this deed electronically.
      </label>
    </div>

    <div class="cb-row">
      <input type="checkbox" id="cb_identity" name="cb_identity" value="1">
      <label for="cb_identity">
        I am <strong>Thomas Boyd Cunliffe</strong>. I am executing this deed in my capacity as
        <strong><?= de_h($capLabel) ?></strong> only. I understand this execution record is permanent
        and cannot be altered after generation.
      </label>
    </div>
  </div>

  <div class="card">
    <div class="card-title"><?= de_h($declarantDone ? 'Generate Caretaker Trustee Execution Record' : 'Generate Declarant Execution Record') ?></div>
    <p style="font-size:.88rem;color:var(--sub);margin:0 0 18px">
      All three confirmations must be actively engaged. This action is permanent.
    </p>
    <button type="submit" class="btn-exec" id="submitBtn" disabled>
      Execute — <?= de_h($capLabel) ?> Capacity
    </button>
  </div>
</form>

<script>
(function(){
  var c1=document.getElementById('cb_read'),
      c2=document.getElementById('cb_capacity'),
      c3=document.getElementById('cb_identity'),
      btn=document.getElementById('submitBtn');
  function upd(){ btn.disabled=!(c1.checked&&c2.checked&&c3.checked); }
  c1.addEventListener('change',upd);
  c2.addEventListener('change',upd);
  c3.addEventListener('change',upd);
  document.getElementById('execForm').addEventListener('submit',function(e){
    if(!(c1.checked&&c2.checked&&c3.checked)){e.preventDefault();return;}
    if(!confirm('Generate the <?= de_h($capLabel) ?> execution record?\n\nThis action is permanent and cannot be undone.')){
      e.preventDefault();
    }
  });
}());
</script>

<?php endif; ?>
</div>
</body>
</html>
