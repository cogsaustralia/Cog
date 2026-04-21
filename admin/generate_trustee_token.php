<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';
require_once __DIR__ . '/../_app/api/services/TrusteeCounterpartService.php';

ops_require_admin();
$pdo = ops_db();

// Only admin.full may generate a Trustee acceptance token
$canGenerate = ops_admin_can($pdo, 'admin.full');

function gt_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$flash = null; $flashType = 'ok';
if (isset($_GET['flash'])) {
    $flash = (string)$_GET['flash'];
    $flashType = (string)($_GET['type'] ?? 'ok');
}

$rawToken  = null;
$tokenError = null;

// Check founding record status
$foundingRecord = TrusteeCounterpartService::getFoundingRecord($pdo);

// Check for existing valid unused token
$stmt = $pdo->prepare(
    'SELECT id, expires_at, created_at FROM one_time_tokens
     WHERE purpose = \'trustee_acceptance\'
       AND used_at IS NULL
       AND expires_at > UTC_TIMESTAMP()
     ORDER BY id DESC LIMIT 1'
);
$stmt->execute();
$existingToken = $stmt->fetch(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canGenerate) {
    if (function_exists('admin_csrf_verify')) { admin_csrf_verify(); }
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'generate_token') {
        if ($foundingRecord) {
            $tokenError = 'A Trustee Counterpart Record has already been generated. No new token is needed.';
        } elseif ($existingToken) {
            $tokenError = 'A valid unused token already exists (expires ' . gt_h($existingToken['expires_at']) . ' UTC). '
                . 'Invalidate it first or wait for it to expire.';
        } else {
            try {
                $rawToken = TrusteeCounterpartService::generateOneTimeToken($pdo);
                // Refresh existing token check
                $stmt->execute();
                $existingToken = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $tokenError = 'Token generation failed: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'invalidate_token' && $existingToken) {
        $pdo->prepare(
            'UPDATE one_time_tokens SET used_at = UTC_TIMESTAMP() WHERE id = ? AND purpose = \'trustee_acceptance\' AND used_at IS NULL'
        )->execute([(int)$existingToken['id']]);
        header('Location: ./generate_trustee_token.php?flash=' . urlencode('Token invalidated.') . '&type=ok');
        exit;
    }
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Trustee Token | COG$ Admin</title>
<?php if (function_exists('ops_admin_help_assets_once')) ops_admin_help_assets_once(); ?>
</head>
<body>
<div class="admin-shell">
<?php admin_sidebar_render('generate_trustee_token'); ?>
<style>
.main { padding: 24px 28px; }
.topbar h2 { font-size: 1.1rem; font-weight: 700; margin: 0 0 4px; }
.topbar p  { color: var(--sub); font-size: 13px; max-width: 580px; }
.card { background: var(--panel2); border: 1px solid var(--line2); border-radius: 10px; padding: 24px 28px; margin-bottom: 20px; }
.card-title { font-size: .7rem; letter-spacing: .12em; text-transform: uppercase; color: var(--gold); font-weight: 700; margin-bottom: 14px; }
.btn { display: inline-block; padding: 9px 18px; border-radius: 8px; font-size: 13px; font-weight: 700; border: 1px solid var(--line2); background: var(--panel2); color: var(--text); cursor: pointer; }
.btn-gold { background: rgba(212,178,92,.15); border-color: rgba(212,178,92,.3); color: var(--gold); }
.btn-danger { background: rgba(192,85,58,.1); border-color: rgba(192,85,58,.3); color: var(--err); }
.alert { padding: 12px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 18px; }
.alert-ok   { background: var(--okb);   border: 1px solid rgba(82,184,122,.3); color: var(--ok); }
.alert-err  { background: var(--errb);  border: 1px solid rgba(192,85,58,.3);  color: var(--err); }
.alert-warn { background: var(--warnb); border: 1px solid rgba(212,148,74,.3); color: var(--warn); }
.token-display {
  font-family: monospace; font-size: .85rem; word-break: break-all;
  background: rgba(212,178,92,.08); border: 1px solid rgba(212,178,92,.3);
  border-radius: 8px; padding: 16px 20px; color: var(--gold);
  margin: 16px 0; line-height: 1.6;
}
.token-display .token-label { font-size: .72rem; letter-spacing: .1em; text-transform: uppercase; color: var(--dim); display: block; margin-bottom: 8px; }
.token-once-warning { font-size: .82rem; color: var(--warn); margin-top: 10px; }
.row { display: flex; gap: 16px; margin-bottom: 10px; flex-wrap: wrap; }
.row-label { font-size: .72rem; text-transform: uppercase; letter-spacing: .08em; color: var(--dim); min-width: 160px; padding-top: 2px; }
.row-value { font-size: .85rem; color: var(--text); font-family: monospace; word-break: break-all; }
.record-ok { background: var(--okb); border: 1px solid rgba(82,184,122,.3); border-radius: 10px; padding: 20px 24px; }
.banner-not-yet {
  background: rgba(192,85,58,.1); border: 2px solid rgba(192,85,58,.4);
  border-radius: 10px; padding: 18px 24px; margin-bottom: 24px;
  font-size: .92rem; color: var(--err); font-weight: 600;
}
</style>

<div class="main">
  <div class="topbar">
    <h2>🔑 Generate Trustee Acceptance Token</h2>
    <p>One-time token for the Trustee Counterpart Record acceptance flow (JVPA clause 10.10A).
       The raw token is shown once and must be delivered out-of-band.</p>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= gt_h($flashType) ?>"><?= gt_h($flash) ?></div>
  <?php endif; ?>

  <?php if ($tokenError): ?>
    <div class="alert alert-err"><?= gt_h($tokenError) ?></div>
  <?php endif; ?>

  <!-- Founding record status -->
  <?php if ($foundingRecord): ?>
    <div class="card">
      <div class="card-title">Founding Trustee Counterpart Record — Status</div>
      <div class="record-ok">
        <div class="row"><div class="row-label">Status</div><div class="row-value" style="color:var(--ok)">✓ Generated</div></div>
        <div class="row"><div class="row-label">Record ID</div><div class="row-value"><?= gt_h($foundingRecord['record_id']) ?></div></div>
        <div class="row"><div class="row-label">UTC Timestamp</div><div class="row-value"><?= gt_h($foundingRecord['acceptance_timestamp_utc']) ?></div></div>
        <div class="row"><div class="row-label">JVPA SHA-256</div><div class="row-value"><?= gt_h($foundingRecord['jvpa_sha256']) ?></div></div>
        <div class="row"><div class="row-label">Record SHA-256</div><div class="row-value"><?= gt_h($foundingRecord['record_sha256']) ?></div></div>
        <div class="row"><div class="row-label">On-Chain Ref</div><div class="row-value"><?= gt_h((string)$foundingRecord['onchain_commitment_txid']) ?></div></div>
      </div>
      <p style="font-size:.82rem;color:var(--sub);margin-top:14px;">
        The JVPA enters into force upon the first Member completing the acceptance procedure under clause 8.1A.
        No further Trustee acceptance token is needed for the founding period.
      </p>
    </div>
  <?php else: ?>
    <div class="banner-not-yet">
      ⚠ FOUNDING TRUSTEE COUNTERPART RECORD NOT YET GENERATED — JVPA NOT YET IN FORCE
    </div>
  <?php endif; ?>

  <?php if (!$foundingRecord && $canGenerate): ?>

    <!-- Existing token status -->
    <?php if ($existingToken): ?>
      <div class="card">
        <div class="card-title">Existing Valid Token</div>
        <div class="alert alert-warn">
          A valid unused Trustee acceptance token exists (created <?= gt_h($existingToken['created_at']) ?> UTC,
          expires <?= gt_h($existingToken['expires_at']) ?> UTC).
          The raw token is not stored and cannot be retrieved. If the token was lost, invalidate it and generate a new one.
        </div>
        <form method="POST">
          <input type="hidden" name="action" value="invalidate_token">
          <?php if (function_exists('admin_csrf_field')) admin_csrf_field(); ?>
          <button type="submit" class="btn btn-danger"
            onclick="return confirm('Invalidate the existing token? This cannot be undone.')">
            Invalidate Existing Token
          </button>
        </form>
      </div>
    <?php endif; ?>

    <!-- Generate token -->
    <?php if (!$existingToken): ?>
      <div class="card">
        <div class="card-title">Generate One-Time Acceptance Token</div>
        <p style="font-size:.88rem;color:var(--sub);margin:0 0 16px;">
          The token is 256 bits (64 hex characters), cryptographically random. Only the SHA-256 hash
          is stored. The raw token is shown once below — copy it immediately and deliver it to Thomas
          Boyd Cunliffe out-of-band (encrypted email or Signal). The token expires 24 hours after generation.
        </p>
        <form method="POST">
          <input type="hidden" name="action" value="generate_token">
          <?php if (function_exists('admin_csrf_field')) admin_csrf_field(); ?>
          <button type="submit" class="btn btn-gold">Generate Token</button>
        </form>
      </div>
    <?php endif; ?>

    <!-- Display the raw token (shown once, immediately after generation) -->
    <?php if ($rawToken): ?>
      <div class="card">
        <div class="card-title">⚠ Raw Token — Shown Once Only</div>
        <div class="token-display">
          <span class="token-label">One-Time Acceptance Token (raw — copy now)</span>
          <?= gt_h($rawToken) ?>
        </div>
        <div class="token-once-warning">
          This token will not be shown again. Copy it now. The acceptance URL is:<br>
          <code style="font-size:.8rem;color:var(--gold);">
            <?= gt_h((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']) ?>/trustee/accept.php?token=<?= gt_h($rawToken) ?>
          </code>
        </div>
      </div>
    <?php endif; ?>

  <?php endif; ?>

</div><!-- .main -->
</div><!-- .admin-shell -->
</body>
</html>
