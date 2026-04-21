<?php
declare(strict_types=1);

/**
 * trustee/accept.php
 * Trustee Counterpart Record — Electronic Acceptance Flow
 * JVPA clause 10.10A
 *
 * Access: one-time token delivered out-of-band, passed as ?token=
 * Single-use. Expires 24 hours after generation.
 * Generates the founding Caretaker Trustee Counterpart Record atomically.
 */

require_once __DIR__ . '/../_app/api/config/bootstrap.php';
require_once __DIR__ . '/../_app/api/config/database.php';
require_once __DIR__ . '/../_app/api/helpers.php';
require_once __DIR__ . '/../_app/api/services/TrusteeCounterpartService.php';

// ── Helpers ───────────────────────────────────────────────────────────────────
function ta_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

function ta_abort(int $code, string $message): never {
    http_response_code($code);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Error — COG$ Foundation</title>'
        . '<style>body{font-family:system-ui,sans-serif;background:#0d0d0d;color:#ccc;display:flex;'
        . 'align-items:center;justify-content:center;min-height:100vh;margin:0;}'
        . '.box{max-width:480px;text-align:center;padding:40px;}'
        . 'h1{color:#b44;font-size:1.2rem;margin-bottom:16px;}'
        . 'p{color:#888;font-size:.9rem;line-height:1.6;}</style></head>'
        . '<body><div class="box"><h1>' . ta_h($message) . '</h1>'
        . '<p>This link is invalid, has expired, or has already been used.</p>'
        . '<p>Contact the Foundation administrator if you believe this is an error.</p>'
        . '</div></body></html>';
    exit;
}

// ── Token validation ──────────────────────────────────────────────────────────
$rawToken = trim((string)($_GET['token'] ?? ''));
if ($rawToken === '' || strlen($rawToken) !== 64) {
    ta_abort(403, 'Invalid or missing token.');
}

try {
    $db = getDB();
} catch (Throwable $e) {
    ta_abort(500, 'Database unavailable. Please try again shortly.');
}

// Check token validity BEFORE rendering anything
$tokenHash = hash('sha256', $rawToken);
$stmt = $db->prepare(
    'SELECT id FROM one_time_tokens
     WHERE token_hash = ?
       AND purpose = \'trustee_acceptance\'
       AND used_at IS NULL
       AND expires_at > UTC_TIMESTAMP()
     LIMIT 1'
);
$stmt->execute([$tokenHash]);
if (!$stmt->fetch()) {
    ta_abort(403, 'This token is invalid, expired, or has already been used.');
}

// Confirm no founding record already exists
if (TrusteeCounterpartService::getFoundingRecord($db) !== null) {
    ta_abort(403, 'A Trustee Counterpart Record has already been generated for this Agreement. This flow cannot be repeated.');
}

// ── JVPA version for display ──────────────────────────────────────────────────
$stmt = $db->prepare('SELECT * FROM jvpa_versions WHERE is_current = 1 ORDER BY id DESC LIMIT 1');
$stmt->execute();
$jvpaVersion = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$jvpaVersion) {
    ta_abort(500, 'JVPA version record not found. Contact the administrator.');
}

// ── POST: process acceptance ──────────────────────────────────────────────────
$result    = null;
$postError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cb1 = ($_POST['cb_read']      ?? '') === '1';
    $cb2 = ($_POST['cb_capacity']  ?? '') === '1';
    $cb3 = ($_POST['cb_identity']  ?? '') === '1';
    $postedToken = trim((string)($_POST['token'] ?? ''));

    if ($postedToken !== $rawToken) {
        $postError = 'Token mismatch. Reload the page and try again.';
    } elseif (!$cb1 || !$cb2 || !$cb3) {
        $postError = 'All three confirmations must be actively engaged before generating the Record.';
    } else {
        // Validate and consume the token atomically with record generation
        if (!TrusteeCounterpartService::validateOneTimeToken($db, $rawToken)) {
            ta_abort(403, 'Token is no longer valid. It may have expired or been used.');
        }
        try {
            $result = TrusteeCounterpartService::record(
                $db,
                getClientIp(),
                (string)($_SERVER['HTTP_USER_AGENT'] ?? '')
            );
        } catch (Throwable $e) {
            $postError = 'Record generation failed: ' . $e->getMessage()
                . ' — No record was written. Please contact the administrator.';
        }
    }
}

// ── Render ────────────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Trustee Counterpart Record — COG$ of Australia Foundation</title>
<style>
*, *::before, *::after { box-sizing: border-box; }
:root {
  --bg:      #0d0d0d;
  --panel:   #141414;
  --panel2:  #1a1a1a;
  --line:    rgba(255,255,255,.08);
  --line2:   rgba(255,255,255,.13);
  --text:    #e8e4d8;
  --sub:     #aaa;
  --dim:     #666;
  --gold:    #d4b25c;
  --goldb:   rgba(212,178,92,.12);
  --goldbr:  rgba(212,178,92,.3);
  --ok:      #52b87a;
  --okb:     rgba(82,184,122,.12);
  --err:     #c0553a;
  --errb:    rgba(192,85,58,.12);
  --warn:    #d4944a;
  --warnb:   rgba(212,148,74,.12);
}
body {
  margin: 0; background: var(--bg); color: var(--text);
  font-family: system-ui, -apple-system, sans-serif;
  font-size: 15px; line-height: 1.6;
}
.shell { max-width: 820px; margin: 0 auto; padding: 40px 24px 80px; }
.crest { text-align: center; margin-bottom: 32px; }
.crest .org { font-size: .75rem; letter-spacing: .15em; text-transform: uppercase; color: var(--dim); margin-bottom: 6px; }
.crest h1  { font-size: 1.1rem; font-weight: 700; color: var(--gold); margin: 0 0 4px; }
.crest .sub { font-size: .82rem; color: var(--sub); }
.card {
  background: var(--panel); border: 1px solid var(--line2);
  border-radius: 12px; padding: 28px 32px; margin-bottom: 24px;
}
.card-title {
  font-size: .7rem; letter-spacing: .12em; text-transform: uppercase;
  color: var(--gold); font-weight: 700; margin-bottom: 16px;
}
.jvpa-scroll {
  background: var(--panel2); border: 1px solid var(--line);
  border-radius: 8px; padding: 20px 24px;
  max-height: 340px; overflow-y: auto;
  font-size: .82rem; color: var(--sub); line-height: 1.7;
  white-space: pre-wrap; font-family: Georgia, serif;
  margin-bottom: 16px;
}
.hash-display {
  font-family: monospace; font-size: .78rem; color: var(--gold);
  background: var(--goldb); border: 1px solid var(--goldbr);
  border-radius: 6px; padding: 10px 14px;
  word-break: break-all; margin-bottom: 16px;
}
.hash-display .label { color: var(--dim); font-size: .72rem; display: block; margin-bottom: 4px; }
.download-link {
  display: inline-block; font-size: .82rem; color: var(--gold);
  text-decoration: underline; margin-bottom: 20px;
}
.cb-row {
  display: flex; align-items: flex-start; gap: 14px;
  padding: 16px; background: var(--panel2); border: 1px solid var(--line);
  border-radius: 8px; margin-bottom: 12px;
}
.cb-row input[type="checkbox"] {
  width: 20px; height: 20px; flex-shrink: 0; margin-top: 2px;
  accent-color: var(--gold); cursor: pointer;
}
.cb-row label { font-size: .88rem; color: var(--text); cursor: pointer; line-height: 1.5; }
.cb-row label strong { color: var(--gold); }
.decl-text {
  background: var(--panel2); border: 1px solid var(--line);
  border-radius: 8px; padding: 16px 20px; margin: 12px 0 16px;
  font-size: .85rem; color: var(--sub); line-height: 1.7;
}
.decl-text ul { margin: 8px 0 0 0; padding-left: 20px; }
.decl-text li { margin-bottom: 6px; }
.btn-generate {
  width: 100%; padding: 16px; border: none; border-radius: 10px;
  background: var(--goldb); border: 2px solid var(--goldbr);
  color: var(--gold); font-size: 1rem; font-weight: 700;
  cursor: pointer; letter-spacing: .04em;
  transition: background .2s, border-color .2s;
}
.btn-generate:disabled {
  opacity: .35; cursor: not-allowed;
}
.btn-generate:not(:disabled):hover {
  background: rgba(212,178,92,.22); border-color: rgba(212,178,92,.5);
}
.alert {
  padding: 14px 18px; border-radius: 8px; font-size: .88rem;
  margin-bottom: 20px; line-height: 1.5;
}
.alert-err  { background: var(--errb);  border: 1px solid rgba(192,85,58,.3);  color: var(--err); }
.alert-ok   { background: var(--okb);   border: 1px solid rgba(82,184,122,.3); color: var(--ok); }
.result-card {
  background: var(--panel); border: 2px solid var(--goldbr);
  border-radius: 12px; padding: 32px;
}
.result-card h2 { color: var(--gold); font-size: 1.1rem; margin: 0 0 24px; }
.result-row { display: flex; gap: 16px; margin-bottom: 14px; flex-wrap: wrap; }
.result-label { font-size: .72rem; letter-spacing: .1em; text-transform: uppercase; color: var(--dim); min-width: 180px; padding-top: 2px; }
.result-value { font-family: monospace; font-size: .82rem; color: var(--text); word-break: break-all; }
.result-value.mono-gold { color: var(--gold); }
.notice {
  background: var(--panel2); border: 1px solid var(--line);
  border-radius: 8px; padding: 16px 20px; margin-top: 24px;
  font-size: .82rem; color: var(--sub); line-height: 1.6;
}
.print-btn {
  margin-top: 20px; padding: 12px 24px; border-radius: 8px;
  background: transparent; border: 1px solid var(--line2);
  color: var(--sub); font-size: .88rem; cursor: pointer;
}
.print-btn:hover { border-color: var(--goldbr); color: var(--gold); }
@media print {
  .print-btn { display: none; }
  .shell { padding: 0; }
  .card { border: 1px solid #ccc; }
}
</style>
</head>
<body>
<div class="shell">

  <div class="crest">
    <div class="org">COG$ of Australia Foundation</div>
    <h1>Trustee Counterpart Record</h1>
    <div class="sub">Electronic Acceptance Procedure — JVPA clause 10.10A</div>
  </div>

<?php if ($result): ?>
  <!-- ── STEP 5: Confirmation screen ───────────────────────────────────────── -->
  <div class="result-card">
    <h2>✓ Trustee Counterpart Record Generated</h2>

    <div class="result-row">
      <div class="result-label">Record ID</div>
      <div class="result-value mono-gold"><?= ta_h($result['record_id']) ?></div>
    </div>
    <div class="result-row">
      <div class="result-label">UTC Timestamp</div>
      <div class="result-value"><?= ta_h($result['acceptance_timestamp_utc']) ?></div>
    </div>
    <div class="result-row">
      <div class="result-label">JVPA Version</div>
      <div class="result-value"><?= ta_h($result['jvpa_version']) ?> — <?= ta_h($result['jvpa_title']) ?></div>
    </div>
    <div class="result-row">
      <div class="result-label">JVPA Execution Date</div>
      <div class="result-value"><?= ta_h($result['jvpa_execution_date']) ?></div>
    </div>
    <div class="result-row">
      <div class="result-label">JVPA SHA-256</div>
      <div class="result-value mono-gold"><?= ta_h($result['jvpa_sha256']) ?></div>
    </div>
    <div class="result-row">
      <div class="result-label">Record SHA-256</div>
      <div class="result-value mono-gold"><?= ta_h($result['record_sha256']) ?></div>
    </div>
    <div class="result-row">
      <div class="result-label">On-Chain Commitment</div>
      <div class="result-value"><?= ta_h($result['onchain_commitment_txid']) ?> (Transitional — evidence vault entry)</div>
    </div>
    <div class="result-row">
      <div class="result-label">Capacity Type</div>
      <div class="result-value">Founding Caretaker Trustee</div>
    </div>

    <div class="notice">
      This Trustee Counterpart Record constitutes your counterpart acknowledgement under clause 10.10A
      of the Joint Venture Participation Agreement. No wet-ink signature or paper counterpart is required.
      The Record is stored in the Foundation's secure systems and cryptographically anchored.
      The JVPA enters into force upon the first Member completing the acceptance procedure under clause 8.1A.
    </div>

    <button class="print-btn" onclick="window.print()">Print / Save as PDF receipt</button>
  </div>

<?php else: ?>
  <!-- ── STEPS 1–4: Acceptance flow ────────────────────────────────────────── -->

  <?php if ($postError): ?>
    <div class="alert alert-err"><?= ta_h($postError) ?></div>
  <?php endif; ?>

  <form method="POST" action="?token=<?= ta_h($rawToken) ?>" id="trusteeForm">
    <input type="hidden" name="token" value="<?= ta_h($rawToken) ?>">

    <!-- STEP 1: Present the JVPA -->
    <div class="card">
      <div class="card-title">Step 1 — Joint Venture Participation Agreement</div>

      <div class="jvpa-scroll"><?php
// Output the JVPA text summary — members get the full PDF, Trustee gets the key text here
// The canonical document is the PDF available for download below.
echo ta_h(
"COGS OF AUSTRALIA FOUNDATION\nJOINT VENTURE PARTICIPATION AGREEMENT\n\n"
. "This is the supreme governing instrument of the Joint Venture.\n"
. "Version: " . $jvpaVersion['version_label'] . "\n"
. "Title: " . $jvpaVersion['version_title'] . "\n"
. "Effective Date: " . $jvpaVersion['effective_date'] . "\n\n"
. "The CJVM Hybrid Trust Declaration and the Sub-Trust Deeds A, B, and C are subordinate to this Agreement.\n"
. "In the event of any inconsistency between this Agreement and the Declaration or any Sub-Trust Deed, this Agreement prevails.\n\n"
. "This Agreement is brought into force by the first cryptographic acceptance record generated under\n"
. "clause 8.1A by the founding Member, together with the Trustee Counterpart Record of the founding\n"
. "Caretaker Trustee generated under clause 10.10A.\n\n"
. "TRUSTEE COUNTERPART RECORD (clause 10.10A)\n"
. "The founding Caretaker Trustee gives their counterpart acknowledgement by completing this\n"
. "electronic acceptance procedure. The Trustee enters this Agreement in their Trustee capacity only,\n"
. "and not as a Member. No COG\$ Token is minted on a Trustee Counterpart Record.\n\n"
. "The full Agreement text is in the PDF available for download below.\n"
. "In the event of any inconsistency between this summary and the PDF, the PDF prevails."
);
?></div>

      <a class="download-link" href="../docs/COGS_JVPA.pdf" target="_blank" rel="noopener">
        ↓ Download full JVPA PDF (<?= ta_h($jvpaVersion['version_label']) ?>)
      </a>

      <div class="hash-display">
        <span class="label">JVPA SHA-256 (<?= ta_h($jvpaVersion['version_label']) ?> — canonical PDF)</span>
        <?= ta_h($jvpaVersion['agreement_hash']) ?>
      </div>

      <div class="cb-row">
        <input type="checkbox" id="cb_read" name="cb_read" value="1">
        <label for="cb_read">
          I have read, or have had a reasonable opportunity to read, the full
          <strong>Joint Venture Participation Agreement (<?= ta_h($jvpaVersion['version_label']) ?>)</strong>
          and I understand its terms.
        </label>
      </div>
    </div>

    <!-- STEP 2: Trustee capacity declaration -->
    <div class="card">
      <div class="card-title">Step 2 — Trustee Capacity Declaration</div>
      <div class="decl-text">
        I acknowledge that:
        <ul>
          <li>this Joint Venture Participation Agreement is the supreme governing instrument of the Joint Venture;</li>
          <li>the CJVM Hybrid Trust Declaration is subordinate to this Agreement; and</li>
          <li>I consent to be bound by the terms of this Agreement in the performance of Trustee Functions.</li>
        </ul>
      </div>
      <div class="cb-row">
        <input type="checkbox" id="cb_capacity" name="cb_capacity" value="1">
        <label for="cb_capacity">
          <strong>I accept the above in my Trustee capacity.</strong>
        </label>
      </div>
    </div>

    <!-- STEP 3: Identity and capacity confirmation -->
    <div class="card">
      <div class="card-title">Step 3 — Identity and Acceptance Mechanism</div>
      <div class="cb-row">
        <input type="checkbox" id="cb_identity" name="cb_identity" value="1">
        <label for="cb_identity">
          I am <strong>Thomas Boyd Cunliffe</strong>. I am accepting this Agreement in my
          <strong>Trustee capacity only</strong>, not as a Member. I am generating a
          <strong>Trustee Counterpart Record</strong> under clause 10.10A of the Joint Venture
          Participation Agreement. I understand that this Record is permanent, cryptographically
          anchored, and cannot be altered or deleted after generation.
        </label>
      </div>
    </div>

    <!-- STEP 4: Generate button -->
    <div class="card">
      <div class="card-title">Step 4 — Generate Trustee Counterpart Record</div>
      <p style="font-size:.88rem;color:var(--sub);margin:0 0 20px;">
        All three confirmations above must be actively engaged before this button is available.
        On submission, the Record is generated atomically and cannot be undone.
      </p>
      <button type="submit" class="btn-generate" id="submitBtn" disabled>
        Generate Trustee Counterpart Record
      </button>
    </div>

  </form>

  <script>
  (function() {
    var cb1 = document.getElementById('cb_read');
    var cb2 = document.getElementById('cb_capacity');
    var cb3 = document.getElementById('cb_identity');
    var btn = document.getElementById('submitBtn');

    function updateBtn() {
      btn.disabled = !(cb1.checked && cb2.checked && cb3.checked);
    }

    // None of the checkboxes may be set programmatically — only user interaction counts.
    // We read state; we never set it.
    cb1.addEventListener('change', updateBtn);
    cb2.addEventListener('change', updateBtn);
    cb3.addEventListener('change', updateBtn);

    // Guard: confirm on submit
    document.getElementById('trusteeForm').addEventListener('submit', function(e) {
      if (!(cb1.checked && cb2.checked && cb3.checked)) {
        e.preventDefault();
        alert('All three confirmations must be ticked before generating the Record.');
        return;
      }
      if (!confirm('Generate the Trustee Counterpart Record now?\n\nThis action is permanent and cannot be undone.')) {
        e.preventDefault();
      }
    });
  }());
  </script>

<?php endif; ?>

</div><!-- .shell -->
</body>
</html>
