<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';
require_once dirname(__DIR__) . '/_app/api/services/MedicareKycAgent.php';

ops_require_admin();
$pdo     = ops_db();
$adminId = function_exists('ops_current_admin_id') ? ops_current_admin_id($pdo) : null;

if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$agent  = new MedicareKycAgent($pdo);
$flash  = null;
$error  = null;
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : null;

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $subId  = (int)($_POST['submission_id'] ?? 0);
    try {
        if ($action === 'open_review' && $subId) {
            $agent->openForReview($subId, (int)$adminId);
            $flash = "Submission #{$subId} opened for review.";
            $viewId = $subId;
        }
        if ($action === 'approve' && $subId) {
            $agent->approve($subId, (int)$adminId, trim((string)($_POST['review_notes'] ?? '')));
            $flash = "✓ Submission #{$subId} approved. Member KYC status set to verified.";
        }
        if ($action === 'reject' && $subId) {
            $reason = trim((string)($_POST['rejection_reason'] ?? ''));
            if (!$reason) throw new RuntimeException('Rejection reason is required.');
            $agent->reject($subId, (int)$adminId, $reason);
            $flash = "Submission #{$subId} rejected.";
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// ── Data ──────────────────────────────────────────────────────────────────────
$queue   = $agent->getPendingQueue();
$pending = count(array_filter($queue, fn($r) => $r['status'] === 'pending'));
$review  = count(array_filter($queue, fn($r) => $r['status'] === 'under_review'));

// Recent verified/rejected (last 30 days)
$recent = $pdo->query(
    "SELECT s.*, m.full_name AS member_name
     FROM kyc_medicare_submissions s
     LEFT JOIN snft_memberships m ON m.id = s.member_id
     WHERE s.status IN ('verified','rejected')
       AND s.updated_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
     ORDER BY s.updated_at DESC LIMIT 50"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

// View a specific submission for review
$reviewData = null;
if ($viewId) {
    $raw = $agent->getSubmission($viewId);
    if ($raw && in_array($raw['status'], ['pending','under_review'], true)) {
        $reviewData = $agent->decryptForReview($raw);
        // Open it if still pending
        if ($raw['status'] === 'pending') {
            try { $agent->openForReview($viewId, (int)$adminId); } catch (Throwable $e) {}
            $reviewData['status'] = 'under_review';
        }
    } elseif ($raw) {
        $reviewData = $raw; // Show read-only for verified/rejected
    }
}

$reviewCompliance = null;
if ($reviewData && !empty($reviewData['member_id']) && function_exists('ops_partner_compliance_snapshot')) {
    $reviewCompliance = ops_partner_compliance_snapshot($pdo, (int)$reviewData['member_id']);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>KYC Review — COGS Admin</title>
<style>
:root{--bg:#0f1720;--panel:#17212b;--panel2:#1f2c38;--text:#eef2f7;--muted:#9fb0c1;--line:rgba(255,255,255,.08);--ok:#b8efc8;--bad:#ffb4be;--gold:#d4b25c;--warn:#ffa040}
*{box-sizing:border-box} body{margin:0;font-family:Inter,Arial,sans-serif;background:#0f1720;color:var(--text)}
.main{padding:20px;max-width:1100px}
.card{background:linear-gradient(180deg,var(--panel),var(--panel2));border:1px solid var(--line);border-radius:16px;padding:20px;margin-bottom:14px}
.stat-row{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.stat{flex:1;min-width:120px;padding:14px;background:rgba(255,255,255,.03);border:1px solid var(--line);border-radius:12px;text-align:center}
.stat .sv{font-size:26px;font-weight:800;color:var(--gold)} .stat .sl{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-top:3px}
.badge{display:inline-block;font-size:10px;font-weight:700;padding:3px 9px;border-radius:999px;text-transform:uppercase;letter-spacing:.04em}
.badge-pending{background:rgba(212,178,92,.12);color:var(--gold);border:1px solid rgba(212,178,92,.2)}
.badge-under_review{background:rgba(90,158,212,.12);color:#8ecef0;border:1px solid rgba(90,158,212,.25)}
.badge-verified{background:rgba(82,184,122,.12);color:#7ee0a0;border:1px solid rgba(82,184,122,.25)}
.badge-rejected{background:rgba(200,61,75,.12);color:var(--bad);border:1px solid rgba(200,61,75,.35)}
table{width:100%;border-collapse:collapse}
th,td{padding:9px 10px;border-bottom:1px solid rgba(255,255,255,.05);text-align:left;font-size:13px;vertical-align:middle}
th{color:var(--muted);font-size:11px;text-transform:uppercase;font-weight:600;letter-spacing:.04em}
tr:hover{background:rgba(255,255,255,.02)}
label{display:block;font-size:12px;color:var(--muted);margin:0 0 5px;font-weight:600}
input,textarea,select{width:100%;background:#0f1720;border:1px solid var(--line);color:var(--text);padding:.65rem .75rem;border-radius:10px;font:inherit;font-size:13px}
textarea{min-height:80px;resize:vertical}
button,.btn{display:inline-block;background:var(--gold);color:#201507;border:1px solid rgba(212,178,92,.35);padding:.6rem 1rem;border-radius:10px;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none}
.secondary{background:rgba(255,255,255,.04);color:var(--text);border-color:var(--line)}
.danger{background:rgba(200,61,75,.15);color:var(--bad);border-color:rgba(200,61,75,.35)}
.sm{padding:5px 12px;font-size:12px;border-radius:8px}
.msg{padding:10px 14px;border-radius:12px;margin-bottom:12px;font-size:13px}
.ok{background:rgba(47,143,87,.12);color:var(--ok);border:1px solid rgba(47,143,87,.35)}
.err{background:rgba(200,61,75,.12);color:var(--bad);border:1px solid rgba(200,61,75,.35)}
.muted{color:var(--muted)} .spacer{height:12px}
.review-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.field-row{margin-bottom:12px}
.field-val{font-size:20px;font-weight:700;color:var(--text);padding:10px 14px;background:rgba(212,178,92,.06);border:1px solid rgba(212,178,92,.15);border-radius:10px;letter-spacing:.1em;font-family:monospace}
.info-box{padding:10px 14px;background:rgba(212,178,92,.05);border:1px solid rgba(212,178,92,.12);border-radius:10px;font-size:12px;color:var(--muted);line-height:1.6;margin-bottom:12px}
.warn-box{padding:10px 14px;background:rgba(255,160,64,.06);border:1px solid rgba(255,160,64,.2);border-radius:10px;font-size:12px;color:var(--warn);margin-bottom:12px}
.compliance-chip{display:inline-block;font-size:10px;font-weight:700;padding:3px 8px;border-radius:999px;border:1px solid var(--line);background:rgba(255,255,255,.04);color:var(--muted)}
.compliance-chip.ok{background:rgba(82,184,122,.12);color:#7ee0a0;border-color:rgba(82,184,122,.25)}
.compliance-chip.warn{background:rgba(255,160,64,.08);color:var(--warn);border-color:rgba(255,160,64,.22)}
.compliance-chip.bad{background:rgba(200,61,75,.12);color:var(--bad);border-color:rgba(200,61,75,.3)}
@media(max-width:700px){.review-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php ops_admin_help_assets_once(); ?>
<div class="admin-shell">
<?php admin_sidebar_render('kyc'); ?>
<main class="main">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;padding:14px 18px;background:linear-gradient(160deg,var(--panel),var(--panel2));border:1px solid var(--line);border-radius:16px">
  <div>
    <h1 style="margin:0;font-size:18px">🪪 Medicare KYC Review <?= ops_admin_help_button('KYC review', 'Use KYC Review to manually assess submitted Medicare card details and decide whether the identity check is verified or rejected. This page is an evidence and compliance surface, not a payment or approval page.') ?></h1>
    <p class="muted" style="margin:4px 0 0;font-size:12px">Manual identity verification — Medicare card · Privacy Act 1988 · AML/CTF Act 2006</p>
  </div>
</div>

<?= ops_admin_info_panel('Intake · Compliance', 'What this page does', 'Review encrypted Medicare submissions and decide whether the identity evidence supports verification. This page is used only for manual KYC review.', [
  'Use open review when you are ready to inspect a submission in detail.',
  'Approve only when the Medicare details and member record are consistent.',
  'Reject when the evidence is insufficient, inconsistent, or requires resubmission.'
]) ?>

<?= ops_admin_workflow_panel('Typical workflow', 'KYC review sits alongside other intake evidence before approval and execution.', [
  ['title' => 'Open the submission', 'body' => 'Move a pending item into review and inspect the decrypted evidence safely.'],
  ['title' => 'Check related intake state', 'body' => 'Use the JVPA, payment, and approval chips as context around the KYC submission.'],
  ['title' => 'Approve or reject', 'body' => 'Record the identity outcome with notes or a rejection reason.'],
  ['title' => 'Continue the intake path', 'body' => 'Once KYC is verified, continue through the remaining compliance and approval steps.']
]) ?>

<?= ops_admin_status_panel('Status guide', 'These statuses describe the queue state, not the final trust execution state.', [
  ['label' => 'Pending', 'body' => 'Submitted and waiting for an operator to open it.'],
  ['label' => 'Under review', 'body' => 'Currently being reviewed by an operator.'],
  ['label' => 'Verified', 'body' => 'Identity evidence has been accepted.'],
  ['label' => 'Rejected', 'body' => 'The submission was declined and should not be relied on without a new submission.']
]) ?>

<?php if($flash): ?><div class="msg ok"><?=h($flash)?></div><?php endif; ?>
<?php if($error): ?><div class="msg err"><?=h($error)?></div><?php endif; ?>

<div class="stat-row">
  <div class="stat"><div class="sv"><?=$pending?></div><div class="sl">Pending review</div></div>
  <div class="stat"><div class="sv" style="color:#8ecef0"><?=$review?></div><div class="sl">Under review</div></div>
  <div class="stat"><div class="sv" style="color:#7ee0a0"><?=count(array_filter($recent,fn($r)=>$r['status']==='verified'))?></div><div class="sl">Verified (30d)</div></div>
  <div class="stat"><div class="sv" style="color:var(--bad)"><?=count(array_filter($recent,fn($r)=>$r['status']==='rejected'))?></div><div class="sl">Rejected (30d)</div></div>
</div>

<?php if($reviewData): ?>
<!-- ── REVIEW PANEL ─────────────────────────────────────────────────────── -->
<div class="card">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
    <h2 style="margin:0;font-size:16px">Review Submission #<?=(int)$reviewData['id']?></h2>
    <a href="./admin_kyc.php" class="btn secondary sm">← Back to queue</a>
  </div>

  <?php if(in_array($reviewData['status'],['pending','under_review'],true)): ?>
  <div class="warn-box">
    ⚠ You are viewing decrypted Medicare card details <?= ops_admin_help_button('Decrypted KYC evidence', 'This view contains sensitive identity information. Review it only for the purpose of deciding the KYC outcome, do not copy it elsewhere, and close the review once you are done.') ?>. This page is access-logged.
    Do not copy or record card details outside this system.
    Close this review when done.
  </div>
  <?php endif; ?>

  <?php if($reviewCompliance): ?>
  <div class="info-box" style="margin-bottom:14px">
    <strong style="color:var(--gold)">Related intake state:</strong>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
      <?php
        $jStatus = (string)($reviewCompliance['jvpa']['status'] ?? 'missing');
        $kStatus = (string)($reviewCompliance['kyc']['status'] ?? 'not_submitted');
        $aStatus = (string)($reviewCompliance['approval']['status'] ?? '');
        $pStatus = (string)($reviewCompliance['payment']['status'] ?? '');
        $jClass = $jStatus === 'verified' ? 'ok' : ($jStatus === 'missing' ? 'bad' : 'warn');
        $kClass = $kStatus === 'verified' ? 'ok' : (in_array($kStatus, ['pending','under_review'], true) ? 'warn' : 'bad');
        $aClass = $aStatus === 'approved' ? 'ok' : ($aStatus === 'pending' ? 'warn' : 'bad');
        $pClass = $pStatus === 'paid' ? 'ok' : ($pStatus === 'pending' ? 'warn' : 'bad');
      ?>
      <span class="compliance-chip <?=h($jClass)?>">JVPA: <?=h($reviewCompliance['jvpa']['label'] ?? 'Missing')?></span>
      <span class="compliance-chip <?=h($kClass)?>">KYC: <?=h($reviewCompliance['kyc']['label'] ?? 'Not submitted')?></span>
      <span class="compliance-chip <?=h($pClass)?>">Payment: <?=h($reviewCompliance['payment']['label'] ?? 'Unknown')?></span>
      <span class="compliance-chip <?=h($aClass)?>">Approval: <?=h($reviewCompliance['approval']['label'] ?? 'Unknown')?></span>
    </div>
    <?php if(!empty($reviewCompliance['jvpa']['accepted_version'])): ?>
      <div style="margin-top:8px;font-size:12px;color:var(--muted)">
        JVPA version: <strong style="color:var(--text)"><?=h($reviewCompliance['jvpa']['accepted_version'])?></strong>
        <?php if(!empty($reviewCompliance['jvpa']['accepted_at'])): ?>
          · accepted <?=h(substr((string)$reviewCompliance['jvpa']['accepted_at'],0,16))?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="review-grid">
    <!-- Member info -->
    <div>
      <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">Member</div>
      <div class="field-row"><label>Name</label><div class="field-val"><?=h($reviewData['member_name']??'—')?></div></div>
      <div class="field-row"><label>Member #</label><div class="field-val" style="font-size:14px"><?=h($reviewData['member_number'])?></div></div>
      <div class="field-row"><label>Purpose</label><div style="font-size:13px;padding:6px 0"><?=h(ucwords(str_replace('_',' ',$reviewData['purpose']??'')))?></div></div>
      <div class="field-row"><label>Submitted</label><div style="font-size:13px;color:var(--muted)"><?=h($reviewData['created_at']??'')?></div></div>
      <div class="field-row"><label>Status <?= ops_admin_help_button('KYC status', 'Pending means waiting to be opened. Under review means an operator is assessing it now. Verified means the evidence was accepted. Rejected means the evidence was declined.') ?></label><span class="badge badge-<?=h($reviewData['status']??'pending')?>"><?=h($reviewData['status']??'')?></span></div>
    </div>

    <!-- Medicare card details -->
    <div>
      <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">Medicare Card Details</div>
      <?php if(in_array($reviewData['status'],['pending','under_review'],true)): ?>
        <div class="field-row"><label>Name on card</label><div class="field-val"><?=h($reviewData['medicare_name']??'[encrypted]')?></div></div>
        <div class="field-row"><label>Card number</label>
          <div class="field-val" style="letter-spacing:.2em">
            <?php
            $num = $reviewData['medicare_number'] ?? '';
            // Display as #### ##### # format
            if(strlen($num)===10){
                echo h(substr($num,0,4).' '.substr($num,4,5).' '.substr($num,9,1));
            } else { echo h($num); }
            ?>
          </div>
        </div>
        <div class="field-row"><label>Individual Reference Number (IRN)</label><div class="field-val" style="font-size:24px;width:60px"><?=h($reviewData['medicare_irn']??'')?></div></div>
        <div class="field-row"><label>Expiry</label><div class="field-val" style="font-size:18px"><?=h($reviewData['medicare_expiry']??'')?></div></div>
        <div style="padding:8px 12px;background:rgba(0,0,0,.2);border-radius:8px;font-size:11px;color:var(--muted);margin-top:8px">
          Evidence hash <?= ops_admin_help_button('Evidence hash', 'This hash is the tamper-evident fingerprint of the evidence record used for audit tracing. It helps prove what was reviewed without exposing the sensitive source details everywhere else.') ?>: <code style="color:#d4b25c;word-break:break-all"><?=h($reviewData['evidence_hash']??'')?></code>
        </div>
      <?php else: ?>
        <div style="padding:12px;background:rgba(255,255,255,.02);border:1px solid var(--line);border-radius:10px;font-size:13px;color:var(--muted)">
          Name initial: <strong style="color:var(--text)"><?=h($reviewData['medicare_name_initial']??'?')?></strong> ·
          Card ending: <strong style="color:var(--text)">…<?=h($reviewData['medicare_number_last4']??'????')?></strong> ·
          Expiry: <strong style="color:var(--text)"><?=h($reviewData['medicare_expiry_month']??'??')?>/<?=h($reviewData['medicare_expiry_year']??'????')?></strong>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if(in_array($reviewData['status'],['pending','under_review'],true)): ?>
  <div style="margin-top:20px;display:grid;grid-template-columns:1fr 1fr;gap:16px">

    <!-- Approve -->
    <div style="padding:16px;background:rgba(82,184,122,.04);border:1px solid rgba(82,184,122,.15);border-radius:12px">
      <div style="font-size:12px;font-weight:700;color:#7ee0a0;margin-bottom:8px">✓ Approve verification <?= ops_admin_help_button('Approve verification', 'Approve only when the Medicare evidence matches the member record closely enough to support identity verification. This updates the KYC status; it does not itself execute any trust action.') ?></div>
      <div class="info-box">Admin confirms that the name and details on the Medicare card are consistent with the member record. An evidence hash will be created and written to the audit vault.</div>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?=ops_h(admin_csrf_token())?>">
        <input type="hidden" name="action" value="approve">
        <input type="hidden" name="submission_id" value="<?=(int)$reviewData['id']?>">
        <label>Review notes (optional)</label>
        <textarea name="review_notes" placeholder="e.g. Name matches registration, card current..."></textarea>
        <div class="spacer"></div>
        <button type="submit" onclick="return confirm('Approve this Medicare KYC submission?')">✓ Approve identity</button>
      </form>
    </div>

    <!-- Reject -->
    <div style="padding:16px;background:rgba(200,61,75,.04);border:1px solid rgba(200,61,75,.15);border-radius:12px">
      <div style="font-size:12px;font-weight:700;color:var(--bad);margin-bottom:8px">✕ Reject <?= ops_admin_help_button('Reject KYC', 'Reject when the evidence is inconsistent, incomplete, expired, or otherwise not acceptable. Use the rejection reason to tell later operators why the submission stopped here.') ?></div>
      <div class="info-box">Member will be notified and may resubmit. The rejection reason is stored in the audit log but not shown in full detail to the member.</div>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?=ops_h(admin_csrf_token())?>">
        <input type="hidden" name="action" value="reject">
        <input type="hidden" name="submission_id" value="<?=(int)$reviewData['id']?>">
        <label>Reason for rejection (required)</label>
        <textarea name="rejection_reason" required placeholder="e.g. Name on card does not match registration. Card appears expired. Details inconsistent."></textarea>
        <div class="spacer"></div>
        <button type="submit" class="danger" onclick="return confirm('Reject this submission?')">✕ Reject</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <?php if(!empty($reviewData['rejection_reason'])): ?>
  <div class="msg err" style="margin-top:12px"><strong>Rejection reason:</strong> <?=h($reviewData['rejection_reason'])?></div>
  <?php endif; ?>
</div>

<?php else: ?>
<!-- ── QUEUE ────────────────────────────────────────────────────────────── -->
<?php if($queue): ?>
<div class="card" style="padding:0;overflow:hidden">
  <div style="padding:12px 18px;border-bottom:1px solid var(--line)">
    <h2 style="margin:0;font-size:15px">Pending Review Queue</h2>
  </div>
  <div style="overflow-x:auto">
    <table>
      <thead>
        <tr>
          <th>#</th><th>Member</th><th>Purpose</th><th>JVPA</th><th>Payment</th><th>Approval</th><th>Submitted</th><th>Status</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($queue as $r):
        $snap = function_exists('ops_partner_compliance_snapshot') ? ops_partner_compliance_snapshot($pdo, (int)$r['member_id']) : [];
        $jLabel = $snap['jvpa']['label'] ?? 'Missing';
        $pLabel = $snap['payment']['label'] ?? 'Unknown';
        $aLabel = $snap['approval']['label'] ?? 'Unknown';
        $jClass = (($snap['jvpa']['status'] ?? '') === 'verified') ? 'ok' : (($snap['jvpa']['status'] ?? '') === 'missing' ? 'bad' : 'warn');
        $pClass = (($snap['payment']['status'] ?? '') === 'paid') ? 'ok' : (($snap['payment']['status'] ?? '') === 'pending' ? 'warn' : 'bad');
        $aClass = (($snap['approval']['status'] ?? '') === 'approved') ? 'ok' : (($snap['approval']['status'] ?? '') === 'pending' ? 'warn' : 'bad');
      ?>
      <tr>
        <td style="font-size:11px;color:var(--muted)">#<?=(int)$r['id']?></td>
        <td>
          <div style="font-weight:600"><?=h($r['member_name']??'')?></div>
          <div style="font-size:11px;color:var(--muted);font-family:monospace"><?=h($r['member_number'])?></div>
        </td>
        <td style="font-size:12px"><?=h(ucwords(str_replace('_',' ',$r['purpose']??'')))?></td>
        <td><span class="compliance-chip <?=h($jClass)?>"><?=h($jLabel)?></span></td>
        <td><span class="compliance-chip <?=h($pClass)?>"><?=h($pLabel)?></span></td>
        <td><span class="compliance-chip <?=h($aClass)?>"><?=h($aLabel)?></span></td>
        <td style="font-size:12px;color:var(--muted)"><?=h(substr($r['created_at']??'',0,16))?></td>
        <td><span class="badge badge-<?=h($r['status']??'')?>"><?=h($r['status']??'')?></span></td>
        <td>
          <a href="?view=<?=(int)$r['id']?>" class="btn sm">Review →</a>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php else: ?>
<div class="card" style="text-align:center;padding:32px;color:var(--muted)">
  No pending KYC submissions. Queue is clear.
</div>
<?php endif; ?>

<!-- ── RECENT ─────────────────────────────────────────────────────────── -->
<?php if($recent): ?>
<div class="card" style="padding:0;overflow:hidden">
  <div style="padding:12px 18px;border-bottom:1px solid var(--line)">
    <h2 style="margin:0;font-size:15px">Recent Decisions (30 days)</h2>
  </div>
  <div style="overflow-x:auto">
    <table>
      <thead><tr><th>#</th><th>Member</th><th>Status</th><th>Decided</th><th>Evidence hash</th></tr></thead>
      <tbody>
      <?php foreach($recent as $r): ?>
      <tr>
        <td style="font-size:11px;color:var(--muted)">#<?=(int)$r['id']?></td>
        <td>
          <div style="font-weight:600"><?=h($r['member_name']??'')?></div>
          <div style="font-size:11px;color:var(--muted);font-family:monospace"><?=h($r['member_number'])?></div>
        </td>
        <td><span class="badge badge-<?=h($r['status']??'')?>"><?=h($r['status']??'')?></span></td>
        <td style="font-size:12px;color:var(--muted)"><?=h(substr($r['updated_at']??'',0,16))?></td>
        <td style="font-size:11px;color:var(--muted);font-family:monospace;word-break:break-all"><?=h(substr($r['evidence_hash']??'',0,16))?>…</td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="info-box">
  <strong style="color:var(--gold)">Privacy &amp; compliance:</strong>
  Medicare card details are AES-256 encrypted at rest. Decrypted values are only shown during active review and are never logged to server access logs.
  All admin actions are recorded in <code>kyc_review_log</code>. Records retained 7 years per AML/CTF Act 2006 s.28.
  Besu attestation hash is generated on approval and will be anchored to the registry contract on Expansion Day.
</div>

</main>
</div>
</body>
</html>
