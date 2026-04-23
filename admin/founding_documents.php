<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';

ops_require_admin();
$pdo = ops_db();
$canManage = ops_admin_can($pdo, 'admin.full');
$adminUserId = ops_current_admin_user_id($pdo);

function fd2_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function fd2_size(int $bytes): string {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)    return number_format($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

// ── Instrument definitions ────────────────────────────────────────────────────
$instruments = [
    'jvpa' => [
        'title'          => 'COGS OF AUSTRALIA FOUNDATION JOINT VENTURE PARTICIPATION AGREEMENT',
        'short'          => 'JVPA',
        'pdf_filename'   => 'COGS_JVPA.pdf',
        'effective_date' => '2026-04-20',
        'executed_at'    => '2026-04-21 00:42:55',
        'sha256'         => '7a4ffe9731ac837678b033aeb25bf7bcc2d178efdec09ac63ad957152047afe4',
        'size'           => 751624,
        'type'           => 'jvpa',
    ],
    'declaration' => [
        'title'          => 'COGS OF AUSTRALIA FOUNDATION HYBRID TRUST DECLARATION',
        'short'          => 'Declaration',
        'pdf_filename'   => 'CJVM_Hybrid_Trust_Declaration.pdf',
        'effective_date' => '2026-04-21',
        'executed_at'    => '2026-04-21 08:59:52',
        'sha256'         => '7c34e319798285c1e9d78643fc2431936e7f8fa7a8a264aa17e1f922b0ffc570',
        'size'           => 1562816,
        'type'           => 'deed',
    ],
    'sub_trust_a' => [
        'title'          => 'COGS OF AUSTRALIA FOUNDATION MEMBERS ASSET POOL UNIT TRUST DEED',
        'short'          => 'Sub-Trust A',
        'pdf_filename'   => 'COGS_SubTrustA.pdf',
        'effective_date' => '2026-04-21',
        'executed_at'    => null,
        'sha256'         => '2c4157a9483bbf9f5368b3b8ae074f8e61f6383e830637ca6645e089cfe7cddf',
        'size'           => 544369,
        'type'           => 'deed',
    ],
    'sub_trust_b' => [
        'title'          => 'COGS OF AUSTRALIA FOUNDATION DIVIDEND DISTRIBUTION UNIT TRUST DEED',
        'short'          => 'Sub-Trust B',
        'pdf_filename'   => 'COGS_SubTrustB.pdf',
        'effective_date' => '2026-04-21',
        'executed_at'    => null,
        'sha256'         => 'fd3e9e184fcd19242184a15b4196d255b048e8ffb894c3d8a70158df9c447a5c',
        'size'           => 356929,
        'type'           => 'deed',
    ],
    'sub_trust_c' => [
        'title'          => 'COGS OF AUSTRALIA FOUNDATION DISCRETIONARY CHARITABLE TRUST DEED',
        'short'          => 'Sub-Trust C',
        'pdf_filename'   => 'COGS_SubTrustC.pdf',
        'effective_date' => '2026-04-21',
        'executed_at'    => null,
        'sha256'         => '63eb7ef704001a8819a178b857efc3ef15f04e1a41e7fda7007392373672fdfa',
        'size'           => 480934,
        'type'           => 'deed',
    ],
];

// ── Download handler — runs before any output ─────────────────────────────────
$download = trim((string)($_GET['download'] ?? ''));
if ($download !== '') {
    $stmt = $pdo->prepare(
        'SELECT instrument_key, instrument_title, version_label, pdf_filename,
                source_path, sha256_hash, file_size_bytes
         FROM founding_instrument_documents
         WHERE id = ? AND is_current = 1 LIMIT 1'
    );
    $stmt->execute([(int)$download]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) {
        http_response_code(404);
        exit('Document not found.');
    }
    $filePath = $doc['source_path'];
    if (!file_exists($filePath) || !is_readable($filePath)) {
        http_response_code(404);
        exit('File not found on server.');
    }
    // Verify integrity before serving
    $liveHash = hash_file('sha256', $filePath);
    if (!hash_equals($liveHash, $doc['sha256_hash'])) {
        http_response_code(500);
        exit('Integrity check failed — file SHA-256 does not match recorded hash.');
    }
    $safeFilename = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $doc['pdf_filename']);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
    header('Content-Length: ' . $doc['file_size_bytes']);
    header('Cache-Control: no-store');
    readfile($filePath);
    exit;
}

// ── POST: record founding version or register amendment ───────────────────────
$flash = null; $flashType = 'ok';
if (isset($_GET['flash'])) {
    $flash = (string)$_GET['flash'];
    $flashType = (string)($_GET['type'] ?? 'ok');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    if (function_exists('admin_csrf_verify')) admin_csrf_verify();
    $action = trim((string)($_POST['action'] ?? ''));

    // ── Record founding version ───────────────────────────────────────────────
    if ($action === 'record_founding') {
        $key = trim((string)($_POST['instrument_key'] ?? ''));
        if (!isset($instruments[$key])) {
            $flash = 'Unknown instrument key.'; $flashType = 'err';
        } else {
            try {
                $inst = $instruments[$key];
                $docsRoot = dirname(__DIR__) . '/docs';
                $filePath = $docsRoot . '/' . $inst['pdf_filename'];

                if (!file_exists($filePath)) {
                    throw new RuntimeException('PDF not found at ' . $filePath);
                }
                $liveHash = hash_file('sha256', $filePath);
                if (!hash_equals($liveHash, $inst['sha256'])) {
                    throw new RuntimeException(
                        'SHA-256 mismatch — file on server does not match locked hash. ' .
                        'Live: ' . $liveHash . ' Expected: ' . $inst['sha256']
                    );
                }
                $liveSize = (int)filesize($filePath);

                // Check not already recorded
                $existing = $pdo->prepare(
                    'SELECT id FROM founding_instrument_documents
                     WHERE instrument_key = ? AND version_label = ? LIMIT 1'
                );
                $existing->execute([$key, 'v1.0']);
                if ($existing->fetch()) {
                    throw new RuntimeException('v1.0 of this instrument is already recorded.');
                }

                $pdo->beginTransaction();

                // Evidence vault entry
                $pdo->prepare(
                    'INSERT INTO evidence_vault_entries
                     (entry_type, subject_type, subject_id, subject_ref,
                      payload_hash, payload_summary, source_system,
                      chain_tx_hash, created_by_type, created_at)
                     VALUES (\'founding_instrument_document\', \'instrument\', 0, ?,
                      ?, ?, \'admin_recording\', ?, \'admin\', NOW())'
                )->execute([
                    $key . '_v1.0',
                    $liveHash,
                    $inst['short'] . ' v1.0 — founding instrument PDF recorded',
                    '0x' . $liveHash,
                ]);
                $eveId = (int)$pdo->lastInsertId();

                // Look up jvpa_version_id or deed_version_anchor_id
                $jvpaVersionId = null;
                $deedAnchorId  = null;
                if ($inst['type'] === 'jvpa') {
                    $jvpaRow = $pdo->prepare(
                        'SELECT id FROM jvpa_versions WHERE agreement_hash = ? LIMIT 1'
                    );
                    $jvpaRow->execute([$liveHash]);
                    $jvpaVersionId = (int)($jvpaRow->fetchColumn() ?: 0) ?: null;
                } else {
                    $anchorRow = $pdo->prepare(
                        'SELECT id FROM deed_version_anchors WHERE deed_sha256 = ? LIMIT 1'
                    );
                    $anchorRow->execute([$liveHash]);
                    $deedAnchorId = (int)($anchorRow->fetchColumn() ?: 0) ?: null;
                }

                // Insert founding_instrument_documents row
                $pdo->prepare(
                    'INSERT INTO founding_instrument_documents
                     (instrument_key, version_label, instrument_title,
                      is_amendment, pdf_filename, source_path,
                      sha256_hash, file_size_bytes, effective_date, executed_at,
                      jvpa_version_id, deed_version_anchor_id, evidence_vault_id,
                      is_current, recorded_at, recorded_by_admin_user_id)
                     VALUES (?,\'v1.0\',?,0,?,?,?,?,?,?,?,?,?,1,NOW(),?)'
                )->execute([
                    $key,
                    $inst['title'],
                    $inst['pdf_filename'],
                    $filePath,
                    $liveHash,
                    $liveSize,
                    $inst['effective_date'],
                    $inst['executed_at'],
                    $jvpaVersionId,
                    $deedAnchorId,
                    $eveId,
                    $adminUserId ?: null,
                ]);

                $pdo->commit();
                $flash = $inst['short'] . ' v1.0 recorded successfully.';
                $flashType = 'ok';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $flash = 'Error: ' . $e->getMessage();
                $flashType = 'err';
            }
        }

    // ── Register amendment ────────────────────────────────────────────────────
    } elseif ($action === 'register_amendment') {
        $key         = trim((string)($_POST['instrument_key'] ?? ''));
        $newVersion  = trim((string)($_POST['version_label'] ?? ''));
        $pdfFilename = trim((string)($_POST['pdf_filename'] ?? ''));
        $amendDesc   = trim((string)($_POST['amendment_description'] ?? ''));
        $authType    = trim((string)($_POST['amendment_authority_type'] ?? ''));
        $pollId      = (int)($_POST['amendment_poll_id'] ?? 0);
        $effDate     = trim((string)($_POST['effective_date'] ?? ''));

        if (!isset($instruments[$key]) || $newVersion === '' || $pdfFilename === '' || $amendDesc === '') {
            $flash = 'All fields are required.'; $flashType = 'err';
        } else {
            try {
                $docsRoot = dirname(__DIR__) . '/docs';
                $filePath = $docsRoot . '/' . basename($pdfFilename);
                if (!file_exists($filePath)) {
                    throw new RuntimeException('PDF file not found in /docs/: ' . $pdfFilename);
                }
                $liveHash = hash_file('sha256', $filePath);
                $liveSize = (int)filesize($filePath);

                // Find the current version to amend
                $currentStmt = $pdo->prepare(
                    'SELECT id FROM founding_instrument_documents
                     WHERE instrument_key = ? AND is_current = 1 LIMIT 1'
                );
                $currentStmt->execute([$key]);
                $currentId = (int)($currentStmt->fetchColumn() ?: 0);
                if (!$currentId) {
                    throw new RuntimeException('No current version found for this instrument. Record v1.0 first.');
                }

                // Check version label not already used
                $dupCheck = $pdo->prepare(
                    'SELECT id FROM founding_instrument_documents
                     WHERE instrument_key = ? AND version_label = ? LIMIT 1'
                );
                $dupCheck->execute([$key, $newVersion]);
                if ($dupCheck->fetch()) {
                    throw new RuntimeException("Version {$newVersion} already recorded for this instrument.");
                }

                $pdo->beginTransaction();

                // Supersede current version
                $pdo->prepare(
                    'UPDATE founding_instrument_documents
                     SET is_current = 0, superseded_at = NOW()
                     WHERE id = ?'
                )->execute([$currentId]);

                // Evidence vault entry
                $pdo->prepare(
                    'INSERT INTO evidence_vault_entries
                     (entry_type, subject_type, subject_id, subject_ref,
                      payload_hash, payload_summary, source_system,
                      chain_tx_hash, created_by_type, created_at)
                     VALUES (\'founding_instrument_document\', \'instrument\', 0, ?,
                      ?, ?, \'admin_recording\', ?, \'admin\', NOW())'
                )->execute([
                    $key . '_' . $newVersion,
                    $liveHash,
                    $instruments[$key]['short'] . ' ' . $newVersion . ' — amendment recorded',
                    '0x' . $liveHash,
                ]);
                $eveId = (int)$pdo->lastInsertId();

                // Resolve jvpa or deed anchor
                $jvpaVersionId = null; $deedAnchorId = null;
                if ($instruments[$key]['type'] === 'jvpa') {
                    $jvpaRow = $pdo->prepare('SELECT id FROM jvpa_versions WHERE agreement_hash = ? LIMIT 1');
                    $jvpaRow->execute([$liveHash]);
                    $jvpaVersionId = (int)($jvpaRow->fetchColumn() ?: 0) ?: null;
                } else {
                    $anchorRow = $pdo->prepare('SELECT id FROM deed_version_anchors WHERE deed_sha256 = ? LIMIT 1');
                    $anchorRow->execute([$liveHash]);
                    $deedAnchorId = (int)($anchorRow->fetchColumn() ?: 0) ?: null;
                }

                $pdo->prepare(
                    'INSERT INTO founding_instrument_documents
                     (instrument_key, version_label, instrument_title,
                      is_amendment, amends_id, amendment_authority_type,
                      amendment_poll_id, amendment_description,
                      pdf_filename, source_path, sha256_hash, file_size_bytes,
                      effective_date, jvpa_version_id, deed_version_anchor_id,
                      evidence_vault_id, is_current, recorded_at, recorded_by_admin_user_id)
                     VALUES (?,?,?,1,?,?,?,?,?,?,?,?,?,?,?,?,1,NOW(),?)'
                )->execute([
                    $key, $newVersion, $instruments[$key]['title'],
                    $currentId,
                    $authType ?: null,
                    $pollId ?: null,
                    $amendDesc,
                    basename($pdfFilename), $filePath,
                    $liveHash, $liveSize,
                    $effDate ?: date('Y-m-d'),
                    $jvpaVersionId, $deedAnchorId, $eveId,
                    $adminUserId ?: null,
                ]);

                $pdo->commit();
                $flash = $instruments[$key]['short'] . ' ' . $newVersion . ' amendment registered.';
                $flashType = 'ok';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $flash = 'Error: ' . $e->getMessage();
                $flashType = 'err';
            }
        }
    }

    header('Location: ./founding_documents.php?flash=' . urlencode((string)$flash) . '&type=' . $flashType);
    exit;
}

// ── Load all recorded documents ───────────────────────────────────────────────
$allDocs = [];
try {
    $rows = $pdo->query(
        'SELECT * FROM founding_instrument_documents ORDER BY instrument_key, id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $allDocs[$row['instrument_key']][] = $row;
    }
} catch (Throwable $e) {
    $flash = 'Table not yet created — run the SQL migration first.';
    $flashType = 'err';
}

$csrfField = function_exists('admin_csrf_token')
    ? '<input type="hidden" name="_csrf" value="' . fd2_h(admin_csrf_token()) . '">'
    : '';

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Founding Documents | COG$ Admin</title>
<?php if (function_exists('ops_admin_help_assets_once')) ops_admin_help_assets_once(); ?>
<style>
.main { padding: 24px 28px; }
.topbar h2 { font-size: 1.1rem; font-weight: 700; margin: 0 0 4px; }
.topbar p  { color: var(--sub); font-size: 13px; max-width: 640px; }
.card { background: var(--panel2); border: 1px solid var(--line2); border-radius: 10px; margin-bottom: 20px; overflow: hidden; }
.card-head { display: flex; justify-content: space-between; align-items: center; padding: 14px 20px; border-bottom: 1px solid var(--line); flex-wrap: wrap; gap: 8px; }
.card-head h3 { font-size: .92rem; font-weight: 700; margin: 0; }
.card-head .sub { font-size: .75rem; color: var(--sub); margin-top: 2px; }
.card-body { padding: 16px 20px; }
.badge { font-size: .72rem; font-weight: 700; padding: 4px 10px; border-radius: 20px; white-space: nowrap; }
.badge-ok   { background: var(--okb);   color: var(--ok);   border: 1px solid rgba(82,184,122,.3); }
.badge-warn { background: var(--warnb); color: var(--warn); border: 1px solid rgba(212,148,74,.3); }
.badge-err  { background: var(--errb);  color: var(--err);  border: 1px solid rgba(192,85,58,.3); }
.badge-gold { background: rgba(212,178,92,.12); color: var(--gold); border: 1px solid rgba(212,178,92,.3); }
.btn { display: inline-block; padding: 7px 14px; border-radius: 7px; font-size: 12px; font-weight: 700; border: 1px solid var(--line2); background: var(--panel2); color: var(--text); cursor: pointer; text-decoration: none; }
.btn-gold { background: rgba(212,178,92,.15); border-color: rgba(212,178,92,.3); color: var(--gold); }
.btn-sm { padding: 5px 10px; font-size: 11px; }
.alert { padding: 12px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 18px; }
.alert-ok  { background: var(--okb);  border: 1px solid rgba(82,184,122,.3); color: var(--ok); }
.alert-err { background: var(--errb); border: 1px solid rgba(192,85,58,.3);  color: var(--err); }
.version-row { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-top: 1px solid var(--line); flex-wrap: wrap; }
.version-row:first-child { border-top: none; }
.version-label { font-size: .78rem; font-weight: 700; color: var(--gold); min-width: 48px; }
.version-meta  { flex: 1; font-size: .78rem; color: var(--sub); }
.version-meta strong { color: var(--text); }
.hash-sm { font-family: monospace; font-size: .72rem; color: var(--dim); word-break: break-all; }
.integrity-ok   { color: var(--ok);  font-size: .72rem; }
.integrity-fail { color: var(--err); font-size: .72rem; font-weight: 700; }
.amendment-form { background: var(--panel); border: 1px solid var(--line2); border-radius: 8px; padding: 18px 20px; margin-top: 14px; }
.amendment-form h4 { font-size: .82rem; color: var(--gold); font-weight: 700; margin: 0 0 12px; }
.form-row { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; }
.form-row label { font-size: .75rem; color: var(--dim); }
.form-row input, .form-row select, .form-row textarea {
  background: var(--panel2); border: 1px solid var(--line2); border-radius: 6px;
  color: var(--text); padding: 8px 10px; font-size: .82rem; width: 100%;
}
.form-row textarea { resize: vertical; min-height: 60px; }
.form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
details summary { cursor: pointer; font-size: .78rem; color: var(--sub); padding: 4px 0; }
details summary:hover { color: var(--gold); }
</style>
</head>
<body>
<div class="admin-shell">
<?php admin_sidebar_render('founding_documents'); ?>
<div class="main">
  <div class="topbar">
    <h2>📄 Founding Instrument Documents</h2>
    <p>Version history of all five founding instrument PDFs. Record founding versions (v1.0),
       download authenticated copies, and register amendments with authorising poll references.</p>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= fd2_h($flashType) ?>"><?= fd2_h($flash) ?></div>
  <?php endif; ?>

<?php foreach ($instruments as $key => $inst):
    $versions = $allDocs[$key] ?? [];
    $currentVersion = null;
    foreach ($versions as $v) { if ($v['is_current']) { $currentVersion = $v; break; } }
    $isRecorded = count($versions) > 0;
    $docsRoot = dirname(__DIR__) . '/docs';
    $filePath = $docsRoot . '/' . $inst['pdf_filename'];
    $fileExists = file_exists($filePath);

    // Live integrity check for current version
    $integrityOk = false;
    $integrityMsg = '';
    if ($currentVersion && $fileExists) {
        $liveHash = hash_file('sha256', $filePath);
        $integrityOk = hash_equals($liveHash, $currentVersion['sha256_hash']);
        $integrityMsg = $integrityOk ? '✓ SHA-256 verified' : '✗ Hash mismatch — file may have changed';
    } elseif (!$fileExists) {
        $integrityMsg = '✗ File not found on server';
    }
?>
  <div class="card <?= $isRecorded ? '' : '' ?>">
    <div class="card-head">
      <div>
        <h3><?= fd2_h($inst['short']) ?></h3>
        <div class="sub"><?= fd2_h($inst['title']) ?></div>
      </div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <?php if ($isRecorded): ?>
          <span class="badge badge-ok">✓ Recorded</span>
          <?php if ($currentVersion): ?>
            <span class="badge badge-gold"><?= fd2_h($currentVersion['version_label']) ?></span>
            <a class="btn btn-gold btn-sm"
               href="?download=<?= (int)$currentVersion['id'] ?>">
              ↓ Download PDF
            </a>
          <?php endif; ?>
        <?php else: ?>
          <span class="badge badge-warn">⚠ Not Yet Recorded</span>
          <?php if ($fileExists && $canManage): ?>
            <form method="POST" style="display:inline">
              <?= $csrfField ?>
              <input type="hidden" name="action" value="record_founding">
              <input type="hidden" name="instrument_key" value="<?= fd2_h($key) ?>">
              <button type="submit" class="btn btn-gold btn-sm"
                onclick="return confirm('Record <?= fd2_h($inst['short']) ?> v1.0 as the founding instrument?')">
                Record v1.0
              </button>
            </form>
          <?php elseif (!$fileExists): ?>
            <span class="badge badge-err">File missing from /docs/</span>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="card-body">

      <?php if ($isRecorded && $currentVersion): ?>
        <!-- Integrity check -->
        <div style="margin-bottom:12px">
          <span class="<?= $integrityOk ? 'integrity-ok' : 'integrity-fail' ?>">
            <?= fd2_h($integrityMsg) ?>
          </span>
          <?php if ($integrityOk): ?>
            &nbsp;<span class="hash-sm"><?= fd2_h(substr($currentVersion['sha256_hash'], 0, 32)) ?>…</span>
          <?php endif; ?>
        </div>

        <!-- Version history -->
        <?php foreach ($versions as $v): ?>
        <div class="version-row">
          <span class="version-label"><?= fd2_h($v['version_label']) ?></span>
          <div class="version-meta">
            <?php if ($v['is_amendment']): ?>
              <span class="badge badge-gold" style="font-size:.68rem;margin-right:6px">Amendment</span>
            <?php else: ?>
              <span class="badge badge-ok" style="font-size:.68rem;margin-right:6px">Founding</span>
            <?php endif; ?>
            <strong><?= fd2_h($v['pdf_filename']) ?></strong>
            &nbsp;·&nbsp; <?= fd2_size((int)$v['file_size_bytes']) ?>
            &nbsp;·&nbsp; Effective <?= fd2_h($v['effective_date']) ?>
            <?php if ($v['executed_at']): ?>
              &nbsp;·&nbsp; Executed <?= fd2_h($v['executed_at']) ?> UTC
            <?php endif; ?>
            <?php if ($v['amendment_description']): ?>
              <br><span style="color:var(--sub)"><?= fd2_h($v['amendment_description']) ?></span>
            <?php endif; ?>
            <br><span class="hash-sm">SHA-256: <?= fd2_h($v['sha256_hash']) ?></span>
          </div>
          <div style="display:flex;gap:6px;align-items:center">
            <?php if (!$v['is_current']): ?>
              <span class="badge" style="font-size:.68rem;color:var(--dim)">Superseded</span>
            <?php endif; ?>
            <a class="btn btn-sm" href="?download=<?= (int)$v['id'] ?>">↓</a>
          </div>
        </div>
        <?php endforeach; ?>

        <!-- Register amendment -->
        <?php if ($canManage): ?>
        <details style="margin-top:14px">
          <summary>+ Register amendment to <?= fd2_h($inst['short']) ?></summary>
          <div class="amendment-form">
            <h4>Register Amendment</h4>
            <p style="font-size:.78rem;color:var(--sub);margin:0 0 12px">
              Upload the amended PDF to <code>/docs/</code> on the server before registering.
              The amendment must have been authorised by Members Poll (Special Resolution, 75%)
              under JVPA clause 10.1<?= $inst['type'] === 'deed' ? ' and executed as a deed' : '' ?>.
            </p>
            <form method="POST">
              <?= $csrfField ?>
              <input type="hidden" name="action" value="register_amendment">
              <input type="hidden" name="instrument_key" value="<?= fd2_h($key) ?>">
              <div class="form-grid-2">
                <div class="form-row">
                  <label>New Version Label *</label>
                  <input type="text" name="version_label" placeholder="e.g. v1.1" required>
                </div>
                <div class="form-row">
                  <label>Effective Date *</label>
                  <input type="date" name="effective_date" required>
                </div>
              </div>
              <div class="form-row">
                <label>PDF Filename in /docs/ *</label>
                <input type="text" name="pdf_filename"
                  placeholder="e.g. COGS_JVPA_v1.1.pdf" required>
              </div>
              <div class="form-grid-2">
                <div class="form-row">
                  <label>Amendment Authority</label>
                  <select name="amendment_authority_type">
                    <option value="members_poll">Members Poll (Special Resolution)</option>
                    <option value="court_order">Court Order</option>
                    <option value="founding_period_correction">Founding Period Correction</option>
                  </select>
                </div>
                <div class="form-row">
                  <label>Authorising Poll ID (if applicable)</label>
                  <input type="number" name="amendment_poll_id" placeholder="community_polls.id">
                </div>
              </div>
              <div class="form-row">
                <label>Amendment Description *</label>
                <textarea name="amendment_description"
                  placeholder="Brief description of what was changed and why"></textarea>
              </div>
              <button type="submit" class="btn btn-gold"
                onclick="return confirm('Register this amendment? The current version will be marked as superseded.')">
                Register Amendment
              </button>
            </form>
          </div>
        </details>
        <?php endif; ?>

      <?php elseif (!$isRecorded): ?>
        <div style="font-size:.82rem;color:var(--sub)">
          <?php if ($fileExists): ?>
            File is present in <code>/docs/<?= fd2_h($inst['pdf_filename']) ?></code>
            (<?= fd2_size($inst['size']) ?>). Click <strong>Record v1.0</strong> to register it in the database.
          <?php else: ?>
            PDF not found at <code>/docs/<?= fd2_h($inst['pdf_filename']) ?></code>.
            Upload the file to the server before recording.
          <?php endif; ?>
        </div>
      <?php endif; ?>

    </div><!-- .card-body -->
  </div><!-- .card -->
<?php endforeach; ?>

</div><!-- .main -->
</div><!-- .admin-shell -->
</body>
</html>
