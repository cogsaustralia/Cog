<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';
ops_require_admin();
$pdo = ops_db();
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin Guide | COG$ Admin</title>
  <style>
    :root{--bg:#0f1720;--panel:#17212b;--panel2:#1f2c38;--text:#eef2f7;--muted:#9fb0c1;--line:rgba(255,255,255,.08);--gold:#d4b25c}
    *{box-sizing:border-box}
    body{margin:0;font-family:Inter,Arial,sans-serif;background:linear-gradient(180deg,#0c1319,#121d27 24%,#0f1720);color:var(--text)}
    .main{padding:26px;min-width:0}
    .topbar{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;margin-bottom:22px}
    .eyebrow{display:inline-block;font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px}
    h1{margin:0 0 8px;font-size:2rem}
    .lede{margin:0;color:var(--muted);line-height:1.7;max-width:760px;font-size:14px}
    .btn{display:inline-block;padding:9px 15px;border-radius:12px;border:1px solid var(--line);background:rgba(255,255,255,.04);color:var(--text);text-decoration:none;font-weight:700}
    .btn.gold{background:linear-gradient(180deg,#d4b25c,#b98b2f);color:#201507;border-color:rgba(212,178,92,.35)}
    .grid{display:grid;gap:18px}
    .two{grid-template-columns:1.1fr .9fr}
    .three{grid-template-columns:repeat(3,minmax(0,1fr))}
    .card{background:linear-gradient(180deg,var(--panel),var(--panel2));border:1px solid var(--line);border-radius:20px;padding:18px 20px}
    .card h2{margin:0 0 10px;font-size:1.05rem;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
    .card p{margin:0;color:var(--muted);font-size:13px;line-height:1.7}
    .guide-links{display:grid;gap:10px}
    .guide-link{display:block;padding:12px 14px;border:1px solid var(--line);border-radius:14px;background:rgba(255,255,255,.03);text-decoration:none;color:var(--text)}
    .guide-link strong{display:block;margin-bottom:4px;font-size:13px}
    .guide-link span{display:block;color:var(--muted);font-size:12px;line-height:1.55}
    .note-list{display:grid;gap:10px;padding:0;margin:0;list-style:none}
    .note-list li{padding:12px 14px;border:1px solid var(--line);border-radius:14px;background:rgba(255,255,255,.03);font-size:12px;line-height:1.6}
    @media(max-width:980px){.two,.three{grid-template-columns:1fr}}
  </style>
  <?php ops_admin_help_assets_once(); ?>
</head>
<body>
<div class="admin-shell">
<?php admin_sidebar_render('dashboard'); ?>
<main class="main">
  <div class="topbar">
    <div>
      <span class="eyebrow">Admin guide</span>
      <h1>How to use the Admin section<?= ops_admin_help_button('Admin guide', 'This page explains how the admin section is organised and which pages should be used for live operations versus diagnostics. Use it when onboarding a new operator or when you need to confirm the correct workflow.') ?></h1>
      <p class="lede">Use this page to understand the role of each Admin section before you start operational work. The live operator path runs through the control-plane pages. Legacy bridge diagnostics remain available for session checks, bridge tracing, and retirement readiness, but they should not be treated as the primary route for day-to-day processing.</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn gold" href="./dashboard.php">Open dashboard</a>
      <a class="btn" href="./legacy-dependencies.php">Bridge diagnostics</a>
    </div>
  </div>

  <div class="grid two" style="margin-bottom:18px">
    <?= ops_admin_info_panel('Start here', 'Admin section purpose', 'The Admin area exists to move partner and business records through intake, compliance, approval, execution, publication, governance visibility, and supporting diagnostics. Each page should answer a specific operator question, not require memorised system knowledge.', [
      'Payments is where money intake is recorded and checked.',
      'Approvals is where operational sign-off happens after prerequisites are satisfied.',
      'Execution is where approved items move through batching, quorum, submission, finalisation, and publication.',
      'Governance, Audit, Infrastructure, and Diagnostics provide evidence, review, and support visibility around the operational record.'
    ]) ?>
    <?= ops_admin_workflow_panel('Recommended live workflow', 'For most operator tasks, use this sequence.', [
      ['title' => 'Dashboard', 'body' => 'Orient yourself and see what requires attention now.'],
      ['title' => 'Payments / Compliance', 'body' => 'Confirm money, identity, and intake requirements.'],
      ['title' => 'Approvals', 'body' => 'Apply the operational decision once prerequisites are met.'],
      ['title' => 'Execution / Publish', 'body' => 'Move the approved work through the formal execution lifecycle and then verify the evidence trail.'],
    ]) ?>
  </div>

  <?= ops_admin_guide_panel('What each Admin section does', 'Use these descriptions to decide where to go next.', [
    ['title' => 'Dashboard', 'body' => 'Operator landing page for orientation, current workload, and quick routing into live admin sections.'],
    ['title' => 'Payments', 'body' => 'Records or verifies money received and links that intake to the correct partner or business record.'],
    ['title' => 'Approvals', 'body' => 'Holds the operational decision point after payment and compliance checks are in place.'],
    ['title' => 'Execution', 'body' => 'Processes approved items through create request, batch, quorum, submit, finalise, and publish.'],
    ['title' => 'Partner and Business Registry', 'body' => 'Shows the live state of participant records, wallet status, and linked compliance context.'],
    ['title' => 'Governance & Evidence', 'body' => 'Displays governance records, directions, zones, and the evidence surface around those actions.'],
    ['title' => 'Diagnostics', 'body' => 'Checks legacy bridge, session state, lockouts, reconciliation, and retirement readiness without becoming the primary operator workflow.'],
  ]) ?>

  <div class="grid three" style="margin-top:18px">
    <div class="card">
      <h2>Live operator pages<?= ops_admin_help_button('Live operator pages', 'These pages are the authoritative control-plane route for day-to-day administration. Start here when you need to process real work, not just inspect state.') ?></h2>
      <div class="guide-links">
        <a class="guide-link" href="./payments.php"><strong>Payments</strong><span>Money intake, payment verification, and entry into the operational pipeline.</span></a>
        <a class="guide-link" href="./approvals.php"><strong>Approvals</strong><span>Operational decision and sign-off once prerequisites are met.</span></a>
        <a class="guide-link" href="./execution.php"><strong>Execution</strong><span>Batch lifecycle from request creation through publication.</span></a>
      </div>
    </div>
    <div class="card">
      <h2>Supporting review pages<?= ops_admin_help_button('Supporting review pages', 'These pages help operators inspect the wider state around the live workflow, including partner records, governance, and evidence, without duplicating the intake and execution path.') ?></h2>
      <div class="guide-links">
        <a class="guide-link" href="./members.php"><strong>Partner Registry</strong><span>Current participant state, wallet status, and linked intake records.</span></a>
        <a class="guide-link" href="./governance.php"><strong>Governance</strong><span>Governance objects, bridge visibility, and decision/evidence context.</span></a>
        <a class="guide-link" href="./audit.php"><strong>Audit</strong><span>Operational traceability, review, and remediation visibility.</span></a>
      </div>
    </div>
    <div class="card">
      <h2>Diagnostic pages<?= ops_admin_help_button('Diagnostic pages', 'Diagnostics are for faults, bridge checks, and retirement readiness. They should support live operations, not replace them.') ?></h2>
      <ul class="note-list">
        <li><strong>Legacy Bridge Status:</strong> shows bridge dependencies and retirement readiness.</li>
        <li><strong>Session Check:</strong> confirms auth/session mapping and transitional admin state.</li>
        <li><strong>Mint / Chain pages:</strong> bridge-era tracing only while the execution control plane remains authoritative.</li>
      </ul>
    </div>
  </div>
</main>
</div>
</body>
</html>
