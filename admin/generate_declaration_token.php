<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';
require_once __DIR__ . '/../_app/api/services/DeclarationExecutionService.php';

ops_require_admin();
$pdo = ops_db();
$canGenerate = ops_admin_can($pdo, 'admin.full');

function gdt_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$flash = null; $flashType = 'ok';
if (isset($_GET['flash'])) { $flash = (string)$_GET['flash']; $flashType = (string)($_GET['type'] ?? 'ok'); }

$rawToken  = null;
$rawWitnessToken = null;
$tokenError = null;
$activeSession = DeclarationExecutionService::getActiveSession($pdo);

// Determine session state
$bothDone       = false;
$fullyExecuted  = false;
$sessionId      = null;
if ($activeSession) {
    $sessionId  = $activeSession['session_id'];
    $caps       = array_column($activeSession['records'], 'capacity');
    $bothDone   = in_array('declarant', $caps) && in_array('caretaker_trustee', $caps);
    $fullyExecuted = $activeSession['attestation'] !== null;
}

// Token status
$execToken = null; $witnessToken = null;
try {
    $s = $pdo->prepare(
        'SELECT purpose, expires_at, created_at FROM one_time_tokens
         WHERE purpose IN (\'declaration_execution\',\'witness_attestation\')
           AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()
         ORDER BY created_at DESC'
    );
    $s->execute();
    foreach ($s->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        if ($row['purpose'] === 'declaration_execution' && !$execToken)    $execToken    = $row;
        if ($row['purpose'] === 'witness_attestation'  && !$witnessToken)  $witnessToken = $row;
    }
} catch (\Throwable $ignored) {}

// POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canGenerate) {
    if (function_exists('admin_csrf_verify')) admin_csrf_verify();
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'generate_exec_token') {
        try {
            $rawToken = DeclarationExecutionService::generateOneTimeToken($pdo, 'declaration_execution');
            // Re-fetch
            $s = $pdo->prepare('SELECT purpose,expires_at,created_at FROM one_time_tokens WHERE purpose=\'declaration_execution\' AND used_at IS NULL AND expires_at>UTC_TIMESTAMP() LIMIT 1');
            $s->execute(); $execToken = $s->fetch(\PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) { $tokenError = $e->getMessage(); }

    } elseif ($action === 'generate_witness_token') {
        if (!$bothDone) {
            $tokenError = 'Both executor capacity records must be complete before generating the witness token.';
        } elseif ($fullyExecuted) {
            $tokenError = 'The deed is already fully executed. No witness token needed.';
        } else {
            try {
                $rawWitnessToken = DeclarationExecutionService::generateOneTimeToken($pdo, 'witness_attestation');
                $s = $pdo->prepare('SELECT purpose,expires_at,created_at FROM one_time_tokens WHERE purpose=\'witness_attestation\' AND used_at IS NULL AND expires_at>UTC_TIMESTAMP() LIMIT 1');
                $s->execute(); $witnessToken = $s->fetch(\PDO::FETCH_ASSOC) ?: null;
            } catch (\Throwable $e) { $tokenError = $e->getMessage(); }
        }

    } elseif ($action === 'invalidate_exec_token') {
        $pdo->prepare('UPDATE one_time_tokens SET used_at=UTC_TIMESTAMP() WHERE purpose=\'declaration_execution\' AND used_at IS NULL')->execute();
        header('Location: ./generate_declaration_token.php?flash=Token+invalidated&type=ok'); exit;

    } elseif ($action === 'invalidate_witness_token') {
        $pdo->prepare('UPDATE one_time_tokens SET used_at=UTC_TIMESTAMP() WHERE purpose=\'witness_attestation\' AND used_at IS NULL')->execute();
        header('Location: ./generate_declaration_token.php?flash=Witness+token+invalidated&type=ok'); exit;
    }
}

$host = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'cogsaustralia.org');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Declaration Tokens | COG$ Admin</title>
<?php if (function_exists('ops_admin_help_assets_once')) ops_admin_help_assets_once(); ?>
</head>
<body>
<div class="admin-shell">
<?php admin_sidebar_render('generate_declaration_token'); ?>
<style>
.main{padding:24px 28px}
.topbar h2{font-size:1.1rem;font-weight:700;margin:0 0 4px}
.topbar p{color:var(--sub);font-size:13px;max-width:600px}
.card{background:var(--panel2);border:1px solid var(--line2);border-radius:10px;padding:22px 26px;margin-bottom:18px}
.card-title{font-size:.7rem;letter-spacing:.12em;text-transform:uppercase;color:var(--gold);font-weight:700;margin-bottom:12px}
.btn{display:inline-block;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:700;border:1px solid var(--line2);background:var(--panel2);color:var(--text);cursor:pointer}
.btn-gold{background:rgba(212,178,92,.15);border-color:rgba(212,178,92,.3);color:var(--gold)}
.btn-danger{background:rgba(192,85,58,.1);border-color:rgba(192,85,58,.3);color:var(--err)}
.alert{padding:12px 16px;border-radius:8px;font-size:13px;margin-bottom:16px}
.alert-ok{background:var(--okb);border:1px solid rgba(82,184,122,.3);color:var(--ok)}
.alert-err{background:var(--errb);border:1px solid rgba(192,85,58,.3);color:var(--err)}
.alert-warn{background:var(--warnb);border:1px solid rgba(212,148,74,.3);color:var(--warn)}
.token-box{font-family:monospace;font-size:.84rem;word-break:break-all;
  background:rgba(212,178,92,.08);border:1px solid rgba(212,178,92,.3);
  border-radius:8px;padding:14px 18px;color:var(--gold);margin:14px 0}
.token-box .tlbl{font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;color:var(--dim);display:block;margin-bottom:6px}
.url-box{font-family:monospace;font-size:.78rem;word-break:break-all;color:var(--sub);margin-top:8px;line-height:1.6}
.row{display:flex;gap:14px;margin-bottom:8px;flex-wrap:wrap}
.rlbl{font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:var(--dim);min-width:150px;padding-top:2px}
.rval{font-size:.84rem;color:var(--text);font-family:monospace;word-break:break-all}
.status-ok{color:var(--ok);font-weight:700}
.status-pending{color:var(--warn);font-weight:700}
.banner-err{background:rgba(192,85,58,.1);border:2px solid rgba(192,85,58,.4);border-radius:10px;
  padding:16px 22px;margin-bottom:20px;font-size:.92rem;color:var(--err);font-weight:600}
.banner-ok{background:var(--okb);border:2px solid rgba(82,184,122,.3);border-radius:10px;
  padding:16px 22px;margin-bottom:20px;font-size:.92rem;color:var(--ok);font-weight:600}
</style>

<div class="main">
  <div class="topbar">
    <h2>📜 Declaration Execution Tokens</h2>
    <p><?= gdt_h(DeclarationExecutionService::DEED_TITLE) ?> — <?= gdt_h(DeclarationExecutionService::DEED_VERSION) ?><br>
    Two-capacity electronic execution + s.14G witness attestation.</p>
  </div>

  <?php if ($flash): ?><div class="alert alert-<?= gdt_h($flashType) ?>"><?= gdt_h($flash) ?></div><?php endif; ?>
  <?php if ($tokenError): ?><div class="alert alert-err"><?= gdt_h($tokenError) ?></div><?php endif; ?>

  <!-- Execution status -->
  <?php if ($fullyExecuted): ?>
    <div class="banner-ok">✓ Declaration fully executed — both capacities complete and witness attestation recorded.</div>
  <?php elseif ($bothDone): ?>
    <div class="alert alert-warn">Both executor capacities complete. Witness attestation still required.</div>
  <?php elseif ($activeSession): ?>
    <div class="alert alert-warn">Execution in progress — awaiting second capacity or witness.</div>
  <?php else: ?>
    <div class="banner-err">⚠ Declaration not yet executed.</div>
  <?php endif; ?>


  <?php if ($fullyExecuted && $activeSession): ?>
  <div class="card" style="border-color:rgba(82,184,122,.35)">
    <div class="card-title">📄 Instrument Documents</div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;padding:4px 0">
      <a href="<?= gdt_h(admin_url('../docs/' . DeclarationExecutionService::DEED_PDF)) ?>"
         target="_blank" rel="noopener" class="btn btn-gold">
        ↓ Download Deed PDF
      </a>
      <a href="<?= gdt_h(admin_url('execution_records.php')) ?>?cert=declaration"
         class="btn">
        📋 View Execution Certificate
      </a>
    </div>
  </div>
  <?php endif; ?>

  <!-- Session status -->
  <?php if ($activeSession): ?>
  <div class="card">
    <div class="card-title">Execution Session</div>
    <?php foreach ($activeSession['records'] as $r): ?>
    <div class="row"><div class="rlbl"><?= gdt_h(ucwords(str_replace('_',' ',$r['capacity']))) ?></div>
      <div class="rval">
        <span class="status-ok">✓ <?= gdt_h($r['status']) ?></span>
        &nbsp;·&nbsp; <?= gdt_h($r['execution_timestamp_utc']) ?>
      </div></div>
    <?php endforeach; ?>
    <?php if ($activeSession['attestation']): $a = $activeSession['attestation']; ?>
    <div class="row"><div class="rlbl">Witness</div>
      <div class="rval"><span class="status-ok">✓ Attested</span> &nbsp;·&nbsp; <?= gdt_h($a['attestation_timestamp_utc']) ?></div></div>
    <?php else: ?>
    <div class="row"><div class="rlbl">Witness</div><div class="rval"><span class="status-pending">⏳ Pending</span></div></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (!$fullyExecuted && $canGenerate): ?>

  <!-- Execution token (Thomas) -->
  <div class="card">
    <div class="card-title">Step 1 — Thomas Execution Token</div>
    <?php if ($execToken && !$rawToken): ?>
      <div class="alert alert-warn">Valid unused execution token exists (expires <?= gdt_h($execToken['expires_at']) ?> UTC). Raw token not retrievable — if lost, invalidate and regenerate.</div>
      <form method="POST">
        <input type="hidden" name="action" value="invalidate_exec_token">
        <?php if (function_exists('admin_csrf_token')): ?><input type="hidden" name="_csrf" value="<?= gdt_h(admin_csrf_token()) ?>"><?php endif; ?>
        <button class="btn btn-danger" onclick="return confirm('Invalidate execution token?')">Invalidate Token</button>
      </form>
    <?php elseif (!$execToken && !$rawToken && !$activeSession): ?>
      <p style="font-size:.85rem;color:var(--sub);margin:0 0 14px">Generate a one-time token for Thomas to access the Declaration execution flow.</p>
      <form method="POST">
        <input type="hidden" name="action" value="generate_exec_token">
        <?php if (function_exists('admin_csrf_token')): ?><input type="hidden" name="_csrf" value="<?= gdt_h(admin_csrf_token()) ?>"><?php endif; ?>
        <button class="btn btn-gold">Generate Execution Token</button>
      </form>
    <?php elseif ($activeSession && !$bothDone): ?>
      <p style="font-size:.85rem;color:var(--sub);margin:0">Execution in progress. Token consumed. Continue at the declaration execution URL.</p>
    <?php elseif ($bothDone): ?>
      <p style="font-size:.85rem;color:var(--ok);margin:0">✓ Both execution capacities complete.</p>
    <?php endif; ?>
    <?php if ($rawToken): ?>
      <div class="token-box">
        <span class="tlbl">Execution Token (raw — copy now, shown once)</span>
        <?= gdt_h($rawToken) ?>
      </div>
      <div class="url-box">URL: <?= gdt_h($host) ?>/trustee/declare.php?token=<?= gdt_h($rawToken) ?></div>
    <?php endif; ?>
  </div>

  <!-- Witness token (Alex) — only available after both capacities done -->
  <div class="card" style="<?= $bothDone ? '' : 'opacity:.45;pointer-events:none' ?>">
    <div class="card-title">Step 2 — Witness Token (<?= gdt_h(DeclarationExecutionService::WITNESS_NAME) ?>)</div>
    <?php if (!$bothDone): ?>
      <p style="font-size:.85rem;color:var(--dim);margin:0">Available after Thomas completes both execution capacities.</p>
    <?php elseif ($witnessToken && !$rawWitnessToken): ?>
      <div class="alert alert-warn">Valid unused witness token exists (expires <?= gdt_h($witnessToken['expires_at']) ?> UTC).</div>
      <form method="POST">
        <input type="hidden" name="action" value="invalidate_witness_token">
        <?php if (function_exists('admin_csrf_token')): ?><input type="hidden" name="_csrf" value="<?= gdt_h(admin_csrf_token()) ?>"><?php endif; ?>
        <button class="btn btn-danger" onclick="return confirm('Invalidate witness token?')">Invalidate</button>
      </form>
    <?php elseif (!$witnessToken && !$rawWitnessToken): ?>
      <p style="font-size:.85rem;color:var(--sub);margin:0 0 14px">Generate a one-time witness token for <?= gdt_h(DeclarationExecutionService::WITNESS_NAME) ?>. Deliver out-of-band.</p>
      <form method="POST">
        <input type="hidden" name="action" value="generate_witness_token">
        <?php if (function_exists('admin_csrf_token')): ?><input type="hidden" name="_csrf" value="<?= gdt_h(admin_csrf_token()) ?>"><?php endif; ?>
        <button class="btn btn-gold">Generate Witness Token</button>
      </form>
    <?php endif; ?>
    <?php if ($rawWitnessToken && $sessionId): ?>
      <div class="token-box">
        <span class="tlbl">Witness Token (raw — copy now, shown once)</span>
        <?= gdt_h($rawWitnessToken) ?>
      </div>
      <div class="url-box">URL: <?= gdt_h($host) ?>/trustee/witness.php?token=<?= gdt_h($rawWitnessToken) ?>&session=<?= gdt_h($sessionId) ?></div>
    <?php endif; ?>
  </div>

  <?php endif; ?>

</div><!-- .main -->
</div><!-- .admin-shell -->
</body>
</html>
