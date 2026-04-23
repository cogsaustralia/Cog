<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';
require_once __DIR__ . '/../_app/api/services/TrusteeCounterpartService.php';
require_once __DIR__ . '/../_app/api/services/DeclarationExecutionService.php';
require_once __DIR__ . '/../_app/api/services/SubTrustAExecutionService.php';
require_once __DIR__ . '/../_app/api/services/SubTrustBExecutionService.php';
require_once __DIR__ . '/../_app/api/services/SubTrustCExecutionService.php';

ops_require_admin();
$pdo = ops_db();

function er_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function er_hash(string $h): string {
    // Return full hash — CSS handles word-break
    return er_h($h);
}

// ── Fetch all five instrument records ──────────────────────────────────────────
$tcr = TrusteeCounterpartService::getFoundingRecord($pdo);

$declSession  = DeclarationExecutionService::getActiveSession($pdo);
$subASession  = SubTrustAExecutionService::getActiveSession($pdo);
$subBSession  = SubTrustBExecutionService::getActiveSession($pdo);
$subCSession  = SubTrustCExecutionService::getActiveSession($pdo);

// Helper: extract record by capacity from a session
function er_cap(array $session, string $cap): ?array {
    foreach ($session['records'] as $r) {
        if ($r['capacity'] === $cap) return $r;
    }
    return null;
}

// ── Print mode: render a specific certificate ─────────────────────────────────
$printMode = trim((string)($_GET['print'] ?? ''));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Execution Records | COG$ Admin</title>
<?php if (function_exists('ops_admin_help_assets_once')) ops_admin_help_assets_once(); ?>
<style>
/* ── Screen styles ── */
.main { padding: 24px 28px; }
.topbar h2 { font-size: 1.1rem; font-weight: 700; margin: 0 0 4px; }
.topbar p  { color: var(--sub); font-size: 13px; max-width: 640px; }

.inst-card {
  background: var(--panel2); border: 1px solid var(--line2);
  border-radius: 10px; padding: 0; margin-bottom: 18px; overflow: hidden;
}
.inst-card.fully-executed { border-color: rgba(82,184,122,.35); }
.inst-card.pending        { border-color: rgba(212,148,74,.35); }
.inst-card.not-started    { border-color: rgba(192,85,58,.35); }

.inst-head {
  display: flex; justify-content: space-between; align-items: center;
  padding: 14px 20px; border-bottom: 1px solid var(--line);
  flex-wrap: wrap; gap: 8px;
}
.inst-head h3 { font-size: .88rem; font-weight: 700; margin: 0; color: var(--text); }
.inst-head .sub { font-size: .75rem; color: var(--sub); margin-top: 2px; }

.badge { font-size: .72rem; font-weight: 700; padding: 4px 10px; border-radius: 20px; white-space: nowrap; }
.badge-ok   { background: var(--okb);   color: var(--ok);   border: 1px solid rgba(82,184,122,.3); }
.badge-warn { background: var(--warnb); color: var(--warn); border: 1px solid rgba(212,148,74,.3); }
.badge-err  { background: var(--errb);  color: var(--err);  border: 1px solid rgba(192,85,58,.3); }

.inst-body { padding: 16px 20px; }

.rec-section { margin-bottom: 14px; }
.rec-section-title {
  font-size: .68rem; letter-spacing: .1em; text-transform: uppercase;
  color: var(--gold); font-weight: 700; margin-bottom: 8px;
}
.rec-grid {
  display: grid; grid-template-columns: 190px 1fr;
  gap: 5px 12px; font-size: .81rem;
}
.rec-lbl { color: var(--dim); padding-top: 1px; }
.rec-val { color: var(--text); font-family: monospace; word-break: break-all; }
.rec-val.gold { color: var(--gold); }
.rec-val.ok   { color: var(--ok); }

.btn-cert {
  display: inline-block; padding: 7px 14px; border-radius: 7px; font-size: .78rem;
  font-weight: 700; text-decoration: none; cursor: pointer; border: none;
  background: rgba(212,178,92,.15); border: 1px solid rgba(212,178,92,.3);
  color: var(--gold);
}
.btn-cert:hover { background: rgba(212,178,92,.25); }

.divider { border: none; border-top: 1px solid var(--line); margin: 14px 0; }

/* ── Certificate / print styles ── */
.cert-wrap {
  display: none;
  max-width: 780px; margin: 0 auto; padding: 40px 32px 60px;
  font-family: system-ui, sans-serif; color: #1a1a1a;
  background: #ffffff;
  position: relative; z-index: 10;
}
.cert-wrap.active {
  display: block;
  background: #ffffff; color: #1a1a1a;
  padding: 40px 32px 60px;
  max-width: 780px; margin: 0 auto;
}
/* Hide the admin shell when a cert is active — normal doc flow for print */
body.cert-open .admin-shell { display: none; }
body.cert-open .no-print { display: none; }

@media print {
  .admin-shell, .main > .topbar, .inst-card, .no-print { display: none !important; }
  .cert-wrap { display: block !important; padding: 0; }
  body { background: white; color: black; }
}

.cert-header { text-align: center; margin-bottom: 32px; border-bottom: 2px solid #8b6914; padding-bottom: 20px; }
.cert-header .org { font-size: .72rem; letter-spacing: .15em; text-transform: uppercase; color: #666; }
.cert-header h1  { font-size: 1.3rem; font-weight: 700; color: #1a1a2e; margin: 8px 0 4px; }
.cert-header .sub { font-size: .82rem; color: #666; }

.cert-status { text-align: center; margin: 20px 0 28px; }
.cert-status .tick { font-size: 2rem; color: #52b87a; }
.cert-status h2   { font-size: 1rem; font-weight: 700; color: #1a1a2e; margin: 6px 0 0; }

.cert-section { margin-bottom: 20px; }
.cert-section-title {
  font-size: .68rem; letter-spacing: .1em; text-transform: uppercase;
  color: #8b6914; font-weight: 700; margin-bottom: 10px;
  border-bottom: 1px solid #e0d8c8; padding-bottom: 4px;
}
.cert-row { display: flex; gap: 16px; margin-bottom: 8px; }
.cert-lbl { font-size: .75rem; color: #666; min-width: 200px; padding-top: 2px; }
.cert-val { font-size: .82rem; color: #1a1a1a; font-family: 'Courier New', monospace; word-break: break-all; overflow-wrap: anywhere; }
.cert-val.highlight { color: #1a1a2e; font-weight: 600; }

.cert-notice {
  background: #f0ede8; border: 1px solid #d4b25c; border-radius: 6px;
  padding: 14px 18px; margin-top: 24px; font-size: .8rem; color: #555; line-height: 1.6;
}
.cert-footer { text-align: center; margin-top: 32px; font-size: .72rem; color: #999; }

.print-btn {
  margin: 16px 0 0; padding: 9px 18px; border-radius: 7px;
  background: transparent; border: 1px solid var(--line2); color: var(--sub);
  font-size: .82rem; cursor: pointer;
}
.print-btn:hover { border-color: var(--goldbr); color: var(--gold); }
</style>
</head>
<body>
<div class="admin-shell">
<?php admin_sidebar_render('execution_records'); ?>

<div class="main">
  <div class="topbar no-print">
    <h2>📋 Execution Records</h2>
    <p>Cryptographic execution records for all five founding instruments.
       Click <strong>View Certificate</strong> on any fully executed instrument to view and print the certified record.</p>
  </div>

<?php
// ── Build instrument display data ──────────────────────────────────────────────

// Instrument 1: JVPA Trustee Counterpart Record
$instruments = [];

// JVPA TCR
$jvpaTcr = [
  'id'         => 'jvpa_tcr',
  'title'      => 'Joint Venture Participation Agreement',
  'subtitle'   => 'Trustee Counterpart Record — JVPA clause 10.10A',
  'type'       => 'counterpart',
  'record'     => $tcr,
];

// Declaration + Sub-Trusts A/B/C
$deedInstruments = [
  ['id'=>'declaration',  'title'=>'CJVM Hybrid Trust Declaration',            'subtitle'=>'Two-capacity deed execution + s.14G witness attestation', 'svc_title'=>DeclarationExecutionService::DEED_TITLE,  'session'=>$declSession],
  ['id'=>'sub_trust_a',  'title'=>'Members Asset Pool Unit Trust Deed',       'subtitle'=>'Sub-Trust A — two-capacity deed execution + s.14G witness attestation', 'svc_title'=>SubTrustAExecutionService::DEED_TITLE, 'session'=>$subASession],
  ['id'=>'sub_trust_b',  'title'=>'Dividend Distribution Unit Trust Deed',    'subtitle'=>'Sub-Trust B — two-capacity deed execution + s.14G witness attestation', 'svc_title'=>SubTrustBExecutionService::DEED_TITLE, 'session'=>$subBSession],
  ['id'=>'sub_trust_c',  'title'=>'Discretionary Charitable Trust Deed',      'subtitle'=>'Sub-Trust C — two-capacity deed execution + s.14G witness attestation', 'svc_title'=>SubTrustCExecutionService::DEED_TITLE, 'session'=>$subCSession],
];

// ── Render JVPA TCR card ───────────────────────────────────────────────────────
$tcrDone = $tcr !== null;
$tcrClass = $tcrDone ? 'fully-executed' : 'not-started';
?>
  <div class="inst-card <?= $tcrClass ?> no-print">
    <div class="inst-head">
      <div>
        <h3>Joint Venture Participation Agreement</h3>
        <div class="sub">Trustee Counterpart Record — JVPA clause 10.10A</div>
      </div>
      <div style="display:flex;gap:8px;align-items:center">
        <?php if ($tcrDone): ?>
          <span class="badge badge-ok">✓ Fully Executed</span>
          <button class="btn-cert" onclick="showCert('jvpa_tcr')">View Certificate</button>
        <?php else: ?>
          <span class="badge badge-err">⚠ Not Generated</span>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($tcrDone): ?>
    <div class="inst-body">
      <div class="rec-grid">
        <span class="rec-lbl">Record ID</span>       <span class="rec-val gold"><?= er_h($tcr['record_id']) ?></span>
        <span class="rec-lbl">UTC Timestamp</span>   <span class="rec-val"><?= er_h($tcr['acceptance_timestamp_utc']) ?></span>
        <span class="rec-lbl">JVPA SHA-256</span>    <span class="rec-val gold"><?= er_hash($tcr['jvpa_sha256']) ?></span>
        <span class="rec-lbl">Record SHA-256</span>  <span class="rec-val gold"><?= er_hash($tcr['record_sha256']) ?></span>
        <span class="rec-lbl">On-Chain Ref</span>    <span class="rec-val"><?= er_h((string)$tcr['onchain_commitment_txid']) ?> (transitional)</span>
        <span class="rec-lbl">Capacity</span>        <span class="rec-val ok">Founding Caretaker Trustee</span>
      </div>
    </div>
    <?php endif; ?>
  </div>

<?php
// ── Render deed cards ──────────────────────────────────────────────────────────
foreach ($deedInstruments as $inst):
    $session = $inst['session'];
    $fullyExecuted = $session && ($session['attestation'] !== null);
    $bothDone = false;
    $declarantRec = null; $trusteeRec = null; $attestation = null;
    if ($session) {
        $declarantRec = er_cap($session, 'declarant');
        $trusteeRec   = er_cap($session, 'caretaker_trustee');
        $bothDone     = $declarantRec && $trusteeRec;
        $attestation  = $session['attestation'] ?: null;
    }
    $cardClass = $fullyExecuted ? 'fully-executed' : ($session ? 'pending' : 'not-started');
    $badgeClass = $fullyExecuted ? 'badge-ok' : ($session ? 'badge-warn' : 'badge-err');
    $badgeText = $fullyExecuted ? '✓ Fully Executed' : ($bothDone ? '⏳ Witness Pending' : ($session ? '⏳ Execution In Progress' : '⚠ Not Started'));
?>
  <div class="inst-card <?= $cardClass ?> no-print">
    <div class="inst-head">
      <div>
        <h3><?= er_h($inst['title']) ?></h3>
        <div class="sub"><?= er_h($inst['subtitle']) ?></div>
      </div>
      <div style="display:flex;gap:8px;align-items:center">
        <span class="badge <?= $badgeClass ?>"><?= $badgeText ?></span>
        <?php if ($fullyExecuted): ?>
          <button class="btn-cert" onclick="showCert('<?= $inst['id'] ?>')">View Certificate</button>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($session): ?>
    <div class="inst-body">
      <div class="rec-grid">
      <?php foreach (['declarant' => 'Declarant', 'caretaker_trustee' => 'Caretaker Trustee'] as $cap => $capLabel):
            $rec = er_cap($session, $cap);
            if (!$rec) continue; ?>
        <span class="rec-lbl"><?= $capLabel ?> Record ID</span>     <span class="rec-val gold"><?= er_h($rec['record_id']) ?></span>
        <span class="rec-lbl"><?= $capLabel ?> Timestamp</span>     <span class="rec-val"><?= er_h($rec['execution_timestamp_utc']) ?></span>
        <span class="rec-lbl"><?= $capLabel ?> Record SHA-256</span> <span class="rec-val gold"><?= er_hash($rec['record_sha256']) ?></span>
      <?php endforeach; ?>
      <?php if ($attestation): ?>
        <span class="rec-lbl">Deed SHA-256</span>             <span class="rec-val gold"><?= er_hash($declarantRec['deed_sha256'] ?? '') ?></span>
        <span class="rec-lbl">Attestation ID</span>           <span class="rec-val gold"><?= er_h($attestation['attestation_id']) ?></span>
        <span class="rec-lbl">Attestation Timestamp</span>    <span class="rec-val"><?= er_h($attestation['attestation_timestamp_utc']) ?></span>
        <span class="rec-lbl">Attestation SHA-256</span>      <span class="rec-val gold"><?= er_hash($attestation['record_sha256']) ?></span>
        <span class="rec-lbl">Witness</span>                  <span class="rec-val ok"><?= er_h($attestation['witness_full_name']) ?> ✓</span>
        <span class="rec-lbl">On-Chain Ref</span>             <span class="rec-val"><?= er_h((string)$attestation['onchain_commitment_txid']) ?> (transitional)</span>
      <?php elseif ($bothDone): ?>
        <span class="rec-lbl">Witness Attestation</span>      <span class="rec-val" style="color:var(--warn)">⏳ Pending — generate witness token from the deed's admin page</span>
      <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

</div><!-- .main -->
</div><!-- .admin-shell -->

<?php
// ── Certificate panels (hidden until activated) ────────────────────────────────

// JVPA TCR Certificate
if ($tcrDone): ?>
<div class="cert-wrap" id="cert-jvpa_tcr">
  <div class="cert-header">
    <div class="org">COG$ of Australia Foundation</div>
    <h1>Trustee Counterpart Record</h1>
    <div class="sub">Electronic Acceptance Procedure — JVPA clause 10.10A</div>
  </div>
  <div class="cert-status">
    <div class="tick">✓</div>
    <h2>Trustee Counterpart Record Generated</h2>
  </div>
  <div class="cert-section">
    <div class="cert-section-title">Record Identity</div>
    <div class="cert-row"><div class="cert-lbl">Record ID</div><div class="cert-val highlight"><?= er_h($tcr['record_id']) ?></div></div>
    <div class="cert-row"><div class="cert-lbl">UTC Timestamp</div><div class="cert-val"><?= er_h($tcr['acceptance_timestamp_utc']) ?></div></div>
    <div class="cert-row"><div class="cert-lbl">Capacity Type</div><div class="cert-val highlight">Founding Caretaker Trustee</div></div>
    <div class="cert-row"><div class="cert-lbl">On-Chain Commitment</div><div class="cert-val"><?= er_h((string)$tcr['onchain_commitment_txid']) ?> (Transitional — evidence vault entry)</div></div>
  </div>
  <div class="cert-section">
    <div class="cert-section-title">Agreement Details</div>
    <div class="cert-row"><div class="cert-lbl">JVPA Version</div><div class="cert-val"><?= er_h($tcr['jvpa_version']) ?></div></div>
    <div class="cert-row"><div class="cert-lbl">JVPA Title</div><div class="cert-val"><?= er_h($tcr['jvpa_title']) ?></div></div>
    <div class="cert-row"><div class="cert-lbl">Execution Date</div><div class="cert-val"><?= er_h($tcr['jvpa_execution_date']) ?></div></div>
  </div>
  <div class="cert-section">
    <div class="cert-section-title">Cryptographic Integrity</div>
    <div class="cert-row"><div class="cert-lbl">JVPA SHA-256</div><div class="cert-val highlight"><?= er_h($tcr['jvpa_sha256']) ?></div></div>
    <div class="cert-row"><div class="cert-lbl">Record SHA-256</div><div class="cert-val highlight"><?= er_h($tcr['record_sha256']) ?></div></div>
  </div>
  <div class="cert-notice">
    This Trustee Counterpart Record constitutes the counterpart acknowledgement under clause 10.10A of the Joint Venture Participation Agreement. No wet-ink signature or paper counterpart is required. The Record is stored in the Foundation's secure systems and cryptographically anchored.
  </div>
  <div class="cert-footer">COG$ of Australia Foundation · cogsaustralia.org · Generated <?= er_h(gmdate('Y-m-d H:i:s')) ?> UTC</div>
  <div class="no-print" style="margin-top:20px">
    <button class="print-btn" onclick="window.print()">🖨 Print / Save as PDF</button>
    <button class="print-btn" onclick="hideCert()" style="margin-left:8px">✕ Close</button>
  </div>
</div>
<?php endif; ?>

<?php
// Deed certificates
$deedCertData = [
  'declaration' => ['session' => $declSession, 'svc_title' => DeclarationExecutionService::DEED_TITLE,  'deed_version' => DeclarationExecutionService::DEED_VERSION,  'deed_pdf' => DeclarationExecutionService::DEED_PDF,  'instrument_title' => 'CJVM Hybrid Trust Declaration'],
  'sub_trust_a' => ['session' => $subASession,  'svc_title' => SubTrustAExecutionService::DEED_TITLE,   'deed_version' => SubTrustAExecutionService::DEED_VERSION,   'deed_pdf' => SubTrustAExecutionService::DEED_PDF,   'instrument_title' => 'Members Asset Pool Unit Trust Deed (Sub-Trust A)'],
  'sub_trust_b' => ['session' => $subBSession,  'svc_title' => SubTrustBExecutionService::DEED_TITLE,   'deed_version' => SubTrustBExecutionService::DEED_VERSION,   'deed_pdf' => SubTrustBExecutionService::DEED_PDF,   'instrument_title' => 'Dividend Distribution Unit Trust Deed (Sub-Trust B)'],
  'sub_trust_c' => ['session' => $subCSession,  'svc_title' => SubTrustCExecutionService::DEED_TITLE,   'deed_version' => SubTrustCExecutionService::DEED_VERSION,   'deed_pdf' => SubTrustCExecutionService::DEED_PDF,   'instrument_title' => 'Discretionary Charitable Trust Deed (Sub-Trust C)'],
];

foreach ($deedCertData as $certId => $cd):
    $session = $cd['session'];
    if (!$session || !$session['attestation']) continue;
    $declRec = er_cap($session, 'declarant');
    $trustRec = er_cap($session, 'caretaker_trustee');
    $att = $session['attestation'];
?>
<div class="cert-wrap" id="cert-<?= $certId ?>">
  <div class="cert-header">
    <div class="org">COG$ of Australia Foundation</div>
    <h1>Declaration Execution Record</h1>
    <div class="sub"><?= er_h($cd['instrument_title']) ?> — Electronic Deed Execution</div>
  </div>
  <div class="cert-status">
    <div class="tick">✓</div>
    <h2>Deed Fully Executed</h2>
  </div>

  <div class="cert-section">
    <div class="cert-section-title">Instrument</div>
    <div class="cert-row"><div class="cert-lbl">Title</div><div class="cert-val highlight"><?= er_h($cd['svc_title']) ?></div></div>
    <div class="cert-row"><div class="cert-lbl">Version / Identifier</div><div class="cert-val"><?= er_h($cd['deed_version']) ?></div></div>
    <div class="cert-row"><div class="cert-lbl">Execution Date</div><div class="cert-val">21 April 2026</div></div>
    <div class="cert-row"><div class="cert-lbl">Execution Method</div><div class="cert-val">Electronic — Electronic Transactions Act 1999 (Cth) and section 14G Electronic Transactions Act 2000 (NSW)</div></div>
    <div class="cert-row"><div class="cert-lbl">Deed SHA-256</div><div class="cert-val highlight"><?= er_h($declRec['deed_sha256'] ?? '') ?></div></div>
  </div>

  <?php if ($declRec): ?>
  <div class="cert-section">
    <div class="cert-section-title">Declarant Execution Record</div>
    <div class="cert-row"><div class="cert-lbl">Executor</div><div class="cert-val highlight">Thomas Boyd Cunliffe</div></div>
    <div class="cert-row"><div class="cert-lbl">Capacity</div><div class="cert-val">Declarant</div></div>
    <div class="cert-row"><div class="cert-lbl">Record ID</div><div class="cert-val"><?= er_h($declRec['record_id']) ?></div></div>
    <div class="cert-row"><div class="cert-lbl">UTC Timestamp</div><div class="cert-val"><?= er_h($declRec['execution_timestamp_utc']) ?></div></div>
    <div class="cert-row"><div class="cert-lbl">Record SHA-256</div><div class="cert-val highlight"><?= er_h($declRec['record_sha256']) ?></div></div>
    <div class="cert-row"><div class="cert-lbl">On-Chain Ref</div><div class="cert-val"><?= er_h((string)$declRec['onchain_commitment_txid']) ?> (Transitional)</div></div>
  </div>
  <?php endif; ?>

  <?php if ($trustRec): ?>
  <div class="cert-section">
    <div class="cert-section-title">Caretaker Trustee Execution Record</div>
    <div class="cert-row"><div class="cert-lbl">Executor</div><div class="cert-val highlight">Thomas Boyd Cunliffe</div></div>
    <div class="cert-row"><div class="cert-lbl">Capacity</div><div class="cert-val">Caretaker Trustee</div></div>
    <div class="cert-row"><div class="cert-lbl">Record ID</div><div class="cert-val"><?= er_h($trustRec['record_id']) ?></div></div>
    <div class="cert-row"><div class="cert-lbl">UTC Timestamp</div><div class="cert-val"><?= er_h($trustRec['execution_timestamp_utc']) ?></div></div>
    <div class="cert-row"><div class="cert-lbl">Record SHA-256</div><div class="cert-val highlight"><?= er_h($trustRec['record_sha256']) ?></div></div>
    <div class="cert-row"><div class="cert-lbl">On-Chain Ref</div><div class="cert-val"><?= er_h((string)$trustRec['onchain_commitment_txid']) ?> (Transitional)</div></div>
  </div>
  <?php endif; ?>

  <div class="cert-section">
    <div class="cert-section-title">Electronic Witness Attestation — s.14G ETA 2000 (NSW)</div>
    <div class="cert-row"><div class="cert-lbl">Witness</div><div class="cert-val highlight"><?= er_h($att['witness_full_name']) ?></div></div>
    <div class="cert-row"><div class="cert-lbl">Address</div><div class="cert-val"><?= er_h($att['witness_address'] ?? '') ?></div></div>
    <div class="cert-row"><div class="cert-lbl">Occupation</div><div class="cert-val"><?= er_h($att['witness_occupation'] ?? '') ?></div></div>
    <div class="cert-row"><div class="cert-lbl">Attestation ID</div><div class="cert-val"><?= er_h($att['attestation_id']) ?></div></div>
    <div class="cert-row"><div class="cert-lbl">UTC Timestamp</div><div class="cert-val"><?= er_h($att['attestation_timestamp_utc']) ?></div></div>
    <div class="cert-row"><div class="cert-lbl">Attestation SHA-256</div><div class="cert-val highlight"><?= er_h($att['record_sha256']) ?></div></div>
    <div class="cert-row"><div class="cert-lbl">On-Chain Ref</div><div class="cert-val"><?= er_h((string)$att['onchain_commitment_txid']) ?> (Transitional)</div></div>
  </div>

  <div class="cert-notice">
    This Declaration Execution Record constitutes the cryptographically verified record of the electronic execution of the <?= er_h($cd['instrument_title']) ?> by Thomas Boyd Cunliffe as Declarant and as Caretaker Trustee, witnessed electronically by <?= er_h($att['witness_full_name']) ?> via audio-visual link under section 14G of the Electronic Transactions Act 2000 (NSW). No wet-ink signature or paper counterpart is required. The Record is stored in the Foundation's secure systems and cryptographically anchored.
  </div>
  <div class="cert-footer">COG$ of Australia Foundation · cogsaustralia.org · Generated <?= er_h(gmdate('Y-m-d H:i:s')) ?> UTC</div>
  <div class="no-print" style="margin-top:20px">
    <button class="print-btn" onclick="window.print()">🖨 Print / Save as PDF</button>
    <button class="print-btn" onclick="hideCert()" style="margin-left:8px">✕ Close</button>
  </div>
</div>
<?php endforeach; ?>

<script>
function showCert(id) {
  document.querySelectorAll('.cert-wrap').forEach(function(el){ el.classList.remove('active'); });
  var el = document.getElementById('cert-' + id);
  if (el) {
    el.classList.add('active');
    document.body.classList.add('cert-open');
    window.scrollTo({top:0,behavior:'instant'});
  }
}
function hideCert() {
  document.querySelectorAll('.cert-wrap').forEach(function(el){ el.classList.remove('active'); });
  document.body.classList.remove('cert-open');
  window.scrollTo({top:0,behavior:'smooth'});
}
</script>
</body>
</html>
