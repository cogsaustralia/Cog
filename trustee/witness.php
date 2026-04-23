<?php
declare(strict_types=1);

/**
 * trustee/witness.php
 * Witness Attestation Flow — Alexander Stefan Gorshenin
 * s.14G Electronic Transactions Act 2000 (NSW)
 *
 * Access: one-time token ?token=&session= delivered out-of-band by Thomas.
 * Alex confirms he observed execution via AV link and attests electronically.
 */

require_once __DIR__ . '/../_app/api/config/bootstrap.php';
require_once __DIR__ . '/../_app/api/config/database.php';
require_once __DIR__ . '/../_app/api/helpers.php';
require_once __DIR__ . '/../_app/api/services/DeclarationExecutionService.php';

function wa_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

function wa_abort(int $code, string $msg): never {
    http_response_code($code);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Error</title>'
        . '<style>body{font-family:system-ui,sans-serif;background:#0d0d0d;color:#ccc;'
        . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
        . '.b{max-width:480px;text-align:center;padding:40px}'
        . 'h1{color:#b44;font-size:1.1rem}p{color:#888;font-size:.88rem;line-height:1.6}'
        . '</style></head><body><div class="b"><h1>' . wa_h($msg) . '</h1>'
        . '<p>This link is invalid, expired, or already used. Contact Thomas Boyd Cunliffe.</p>'
        . '</div></body></html>';
    exit;
}

$rawToken  = trim((string)($_GET['token']   ?? ''));
$sessionId = trim((string)($_GET['session'] ?? ''));

if ($rawToken === '' || strlen($rawToken) !== 64) wa_abort(403, 'Invalid or missing token.');
if ($sessionId === '')                             wa_abort(403, 'Missing session identifier.');

try { $db = getDB(); } catch (\Throwable $e) { wa_abort(500, 'Database unavailable.'); }

// Validate token (not yet consumed)
$tokenHash = hash('sha256', $rawToken);
$stmt = $db->prepare(
    'SELECT id FROM one_time_tokens
     WHERE token_hash = ? AND purpose = \'witness_attestation\'
       AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()
     LIMIT 1'
);
$stmt->execute([$tokenHash]);
if (!$stmt->fetch()) wa_abort(403, 'Token is invalid, expired, or already used.');

// Load session — both execution records must be complete
$session = DeclarationExecutionService::getSession($db, $sessionId);
if (!$session) wa_abort(404, 'Execution session not found.');

$capacities = array_column($session['records'], 'capacity');
if (!in_array('declarant', $capacities) || !in_array('caretaker_trustee', $capacities)) {
    wa_abort(403, 'Both executor capacity records must be complete before witness attestation.');
}

if ($session['attestation']) wa_abort(403, 'Witness attestation has already been completed for this session.');

$deedSha256 = DeclarationExecutionService::getDeedSha256($db);
if (!$deedSha256) wa_abort(500, 'Deed SHA-256 not found. Contact the administrator.');

// ── POST ──────────────────────────────────────────────────────────────────────
$postError = null;
$result    = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken   = trim((string)($_POST['token']    ?? ''));
    $postedSession = trim((string)($_POST['session']  ?? ''));
    $cb1           = ($_POST['cb_observed'] ?? '') === '1';
    $cb2           = ($_POST['cb_document'] ?? '') === '1';
    $cb3           = ($_POST['cb_identity'] ?? '') === '1';

    if ($postedToken !== $rawToken || $postedSession !== $sessionId) {
        $postError = 'Token or session mismatch. Reload and try again.';
    } elseif (!$cb1 || !$cb2 || !$cb3) {
        $postError = 'All three attestation confirmations must be actively engaged.';
    } else {
        if (!DeclarationExecutionService::validateOneTimeToken($db, $rawToken, 'witness_attestation')) {
            wa_abort(403, 'Token is no longer valid.');
        }
        try {
            $result = DeclarationExecutionService::recordWitnessAttestation(
                $db, $sessionId, $deedSha256,
                getClientIp(), (string)($_SERVER['HTTP_USER_AGENT'] ?? '')
            );
        } catch (\Throwable $e) {
            $postError = 'Attestation failed: ' . $e->getMessage();
        }
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Witness Attestation — COG$ Foundation</title>
<style>
*,*::before,*::after{box-sizing:border-box}
:root{
  --bg:#0d0d0d;--panel:#141414;--panel2:#1a1a1a;
  --line:rgba(255,255,255,.08);--line2:rgba(255,255,255,.13);
  --text:#e8e4d8;--sub:#aaa;--dim:#666;
  --gold:#d4b25c;--goldb:rgba(212,178,92,.12);--goldbr:rgba(212,178,92,.3);
  --ok:#52b87a;--okb:rgba(82,184,122,.12);
  --err:#c0553a;--errb:rgba(192,85,58,.12);
}
body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,sans-serif;font-size:15px;line-height:1.6}
.shell{max-width:760px;margin:0 auto;padding:40px 24px 80px}
.crest{text-align:center;margin-bottom:32px}
.crest .org{font-size:.72rem;letter-spacing:.15em;text-transform:uppercase;color:var(--dim);margin-bottom:6px}
.crest h1{font-size:1.1rem;font-weight:700;color:var(--gold);margin:0 0 4px}
.crest .sub{font-size:.82rem;color:var(--sub)}
.card{background:var(--panel);border:1px solid var(--line2);border-radius:12px;padding:26px 30px;margin-bottom:20px}
.card-title{font-size:.7rem;letter-spacing:.12em;text-transform:uppercase;color:var(--gold);font-weight:700;margin-bottom:14px}
.hash-box{font-family:monospace;font-size:.78rem;color:var(--gold);background:var(--goldb);
  border:1px solid var(--goldbr);border-radius:6px;padding:10px 14px;word-break:break-all;margin-bottom:14px}
.hash-box .lbl{color:var(--dim);font-size:.72rem;display:block;margin-bottom:4px}
.dl{display:inline-block;font-size:.82rem;color:var(--gold);text-decoration:underline;margin-bottom:16px}
.exec-summary{background:var(--panel2);border:1px solid var(--line);border-radius:8px;
  padding:14px 18px;margin-bottom:16px;font-size:.84rem;color:var(--sub);line-height:1.7}
.exec-summary strong{color:var(--text)}
.cb-row{display:flex;align-items:flex-start;gap:14px;padding:14px;
  background:var(--panel2);border:1px solid var(--line);border-radius:8px;margin-bottom:10px}
.cb-row input{width:20px;height:20px;flex-shrink:0;margin-top:2px;accent-color:var(--gold);cursor:pointer}
.cb-row label{font-size:.88rem;color:var(--text);cursor:pointer;line-height:1.5}
.cb-row label strong{color:var(--gold)}
.btn-attest{width:100%;padding:15px;border:2px solid var(--goldbr);border-radius:10px;
  background:var(--goldb);color:var(--gold);font-size:1rem;font-weight:700;cursor:pointer}
.btn-attest:disabled{opacity:.35;cursor:not-allowed}
.btn-attest:not(:disabled):hover{background:rgba(212,178,92,.22);border-color:rgba(212,178,92,.5)}
.alert{padding:13px 17px;border-radius:8px;font-size:.88rem;margin-bottom:18px;line-height:1.5}
.alert-err{background:var(--errb);border:1px solid rgba(192,85,58,.3);color:var(--err)}
.result-card{background:var(--panel);border:2px solid rgba(82,184,122,.35);border-radius:12px;padding:30px}
.result-card h2{color:var(--ok);font-size:1rem;margin:0 0 22px}
.drow{display:flex;gap:16px;margin-bottom:12px;flex-wrap:wrap}
.dlbl{font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:var(--dim);min-width:180px;padding-top:2px}
.dval{font-family:monospace;font-size:.82rem;color:var(--text);word-break:break-all}
.dval.gold{color:var(--gold)}
.notice{background:var(--panel2);border:1px solid var(--line);border-radius:8px;
  padding:14px 18px;font-size:.82rem;color:var(--sub);line-height:1.6;margin-top:20px}
</style>
</head>
<body>
<div class="shell">
<div class="crest">
  <div class="org">COG$ of Australia Foundation</div>
  <h1>Electronic Witness Attestation</h1>
  <div class="sub">Section 14G — Electronic Transactions Act 2000 (NSW)</div>
</div>

<?php if ($result): ?>
<div class="result-card">
  <h2>✓ Witness Attestation Complete — Deed Fully Executed</h2>
  <div class="drow"><div class="dlbl">Attestation ID</div><div class="dval gold"><?= wa_h($result['attestation_id']) ?></div></div>
  <div class="drow"><div class="dlbl">UTC Timestamp</div><div class="dval"><?= wa_h($result['attestation_timestamp_utc']) ?></div></div>
  <div class="drow"><div class="dlbl">Deed SHA-256</div><div class="dval gold"><?= wa_h($result['deed_sha256']) ?></div></div>
  <div class="drow"><div class="dlbl">Attestation SHA-256</div><div class="dval gold"><?= wa_h($result['record_sha256']) ?></div></div>
  <div class="drow"><div class="dlbl">On-Chain Ref</div><div class="dval"><?= wa_h((string)$result['onchain_commitment_txid']) ?></div></div>
  <div class="notice">
    Your electronic attestation has been recorded under section 14G of the
    Electronic Transactions Act 2000 (NSW). The <?= wa_h(DeclarationExecutionService::DEED_TITLE) ?>
    is now fully executed. This record is permanent and cryptographically anchored.
    No further action is required from you.
  </div>
  <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:20px">
    <a href="../docs/<?= wa_h(DeclarationExecutionService::DEED_PDF) ?>"
       target="_blank" rel="noopener"
       style="display:inline-block;padding:10px 18px;border-radius:8px;
              background:var(--goldb);border:1px solid var(--goldbr);
              color:var(--gold);font-size:.88rem;font-weight:700;text-decoration:none">
      ↓ Download Deed PDF
    </a>
    <a href="../admin/execution_records.php?cert=declaration"
       style="display:inline-block;padding:10px 18px;border-radius:8px;
              background:var(--panel2);border:1px solid var(--line2);
              color:var(--text);font-size:.88rem;font-weight:700;text-decoration:none">
      📋 View Execution Certificate
    </a>
  </div>
</div>

<?php else: ?>

<?php if ($postError): ?>
  <div class="alert alert-err"><?= wa_h($postError) ?></div>
<?php endif; ?>

<!-- Context for the witness -->
<div class="card">
  <div class="card-title">Deed Being Witnessed</div>
  <p style="font-size:.88rem;color:var(--sub);margin:0 0 14px">
    You are being asked to attest electronically that you observed
    <strong style="color:var(--text)">Thomas Boyd Cunliffe</strong>
    execute the following deed via audio-visual link.
  </p>
  <div class="hash-box">
    <span class="lbl">Deed: <?= wa_h(DeclarationExecutionService::DEED_TITLE) ?> — <?= wa_h(DeclarationExecutionService::DEED_VERSION) ?></span>
    SHA-256: <?= wa_h($deedSha256) ?>
  </div>
  <a class="dl" href="../docs/<?= wa_h(DeclarationExecutionService::DEED_PDF) ?>" target="_blank" rel="noopener">
    ↓ View Declaration PDF
  </a>
  <div class="exec-summary">
    Thomas Boyd Cunliffe has executed this deed electronically in two capacities:<br>
    <strong>Declarant</strong> and <strong>Caretaker Trustee</strong>.<br><br>
    Both execution records have been generated and cryptographically anchored.
    Your attestation is the final step required to fully execute this deed under
    section 14G of the Electronic Transactions Act 2000 (NSW).
  </div>
</div>

<form method="POST" action="?token=<?= wa_h($rawToken) ?>&session=<?= wa_h($sessionId) ?>" id="witnessForm">
  <input type="hidden" name="token"   value="<?= wa_h($rawToken) ?>">
  <input type="hidden" name="session" value="<?= wa_h($sessionId) ?>">

  <div class="card">
    <div class="card-title">Witness Attestation</div>

    <div class="cb-row">
      <input type="checkbox" id="cb_observed" name="cb_observed" value="1">
      <label for="cb_observed">
        I, <strong>Alexander Stefan Gorshenin</strong>, confirm that I observed
        <strong>Thomas Boyd Cunliffe</strong> execute the
        <strong><?= wa_h(DeclarationExecutionService::DEED_TITLE) ?></strong>
        electronically via audio-visual link on
        <strong><?= wa_h(DeclarationExecutionService::EXECUTION_DATE) ?></strong>.
      </label>
    </div>

    <div class="cb-row">
      <input type="checkbox" id="cb_document" name="cb_document" value="1">
      <label for="cb_document">
        I am satisfied that the document executed is the same document identified by the
        SHA-256 hash shown above and available for download via the link above.
      </label>
    </div>

    <div class="cb-row">
      <input type="checkbox" id="cb_identity" name="cb_identity" value="1">
      <label for="cb_identity">
        I confirm my full name is <strong>Alexander Stefan Gorshenin</strong>,
        my address is <strong>1/118 Ridgeway Ave, Southport QLD 4215</strong>,
        and my occupation is <strong>Independent witness</strong>.
        I give this attestation electronically under
        section 14G of the Electronic Transactions Act 2000 (NSW).
      </label>
    </div>
  </div>

  <div class="card">
    <div class="card-title">Generate Witness Attestation Record</div>
    <p style="font-size:.88rem;color:var(--sub);margin:0 0 18px">
      All three confirmations must be actively engaged. This attestation is permanent.
    </p>
    <button type="submit" class="btn-attest" id="submitBtn" disabled>
      Submit Witness Attestation
    </button>
  </div>
</form>

<script>
(function(){
  var c1=document.getElementById('cb_observed'),
      c2=document.getElementById('cb_document'),
      c3=document.getElementById('cb_identity'),
      btn=document.getElementById('submitBtn');
  function upd(){ btn.disabled=!(c1.checked&&c2.checked&&c3.checked); }
  c1.addEventListener('change',upd);
  c2.addEventListener('change',upd);
  c3.addEventListener('change',upd);
  document.getElementById('witnessForm').addEventListener('submit',function(e){
    if(!(c1.checked&&c2.checked&&c3.checked)){e.preventDefault();return;}
    if(!confirm('Submit your witness attestation?\n\nThis action is permanent and cannot be undone.')){
      e.preventDefault();
    }
  });
}());
</script>

<?php endif; ?>
</div>
</body>
</html>
