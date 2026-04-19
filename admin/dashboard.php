<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';

ops_require_admin();
$pdo = ops_db();

if (!function_exists('dh')) {
    function dh($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('d_has')) {
    function d_has(PDO $pdo, string $t): bool {
        return function_exists('ops_has_table') ? ops_has_table($pdo, $t) : false;
    }
}
if (!function_exists('d_val')) {
    // Safe single-value fetch via prepared statement — no string injection risk
    function d_val(PDO $pdo, string $sql, array $params = []): int {
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }
}
if (!function_exists('d_rows_safe')) {
    function d_rows_safe(PDO $pdo, string $sql, array $params = []): array {
        try { $st=$pdo->prepare($sql); $st->execute($params); return $st->fetchAll(PDO::FETCH_ASSOC) ?: []; }
        catch (Throwable $e) { return []; }
    }
}

// ── Actionable counts (these indicate work needed now) ─────────────────────
$pendingApprovals   = d_val($pdo, "SELECT COUNT(*) FROM approval_requests WHERE request_status='pending'");
$membersUnpaid      = d_val($pdo, "SELECT COUNT(DISTINCT m.id) FROM members m
    JOIN member_reservation_lines mrl ON mrl.member_id = m.id
    JOIN token_classes tc ON tc.id = mrl.token_class_id
    WHERE tc.class_code IN ('PERSONAL_SNFT','KIDS_SNFT','BUSINESS_BNFT')
      AND mrl.payment_status != 'paid' AND mrl.requested_units > 0");
$membersAwaiting    = d_val($pdo, "SELECT COUNT(DISTINCT m.id) FROM members m
    JOIN member_reservation_lines mrl ON mrl.member_id = m.id
    JOIN token_classes tc ON tc.id = mrl.token_class_id
    WHERE tc.class_code IN ('PERSONAL_SNFT','KIDS_SNFT','BUSINESS_BNFT')
      AND mrl.payment_status = 'paid' AND mrl.approval_status != 'approved'");
$mintQueueReady     = d_has($pdo, 'mint_queue')
    ? d_val($pdo, "SELECT COUNT(*) FROM mint_queue WHERE queue_status='prepared' AND live_status='not_live'")
    : 0;
$openExceptions     = d_has($pdo, 'system_exceptions')
    ? d_val($pdo, "SELECT COUNT(*) FROM system_exceptions WHERE resolved_at IS NULL") : 0;
$openEvidenceReviews = d_has($pdo, 'evidence_reviews')
    ? d_val($pdo, "SELECT COUNT(*) FROM evidence_reviews WHERE review_status='pending'") : 0;
$pendingKidsVerify  = d_has($pdo, 'member_applications')
    ? d_val($pdo, "SELECT COUNT(*) FROM member_applications WHERE application_type='kids_snft' AND application_status='submitted'") : 0;
$unreconciled       = d_val($pdo, "SELECT COUNT(*) FROM payments p
    LEFT JOIN (SELECT payment_id, SUM(amount_cents) alloc FROM payment_allocations GROUP BY payment_id) a
        ON a.payment_id = p.id
    WHERE p.payment_status='paid' AND COALESCE(a.alloc,0) < p.amount_cents");

// ── Informational counts ────────────────────────────────────────────────────
$membersTotal   = d_val($pdo, "SELECT COUNT(*) FROM members");
$membersActive  = d_val($pdo, "SELECT COUNT(*) FROM members WHERE wallet_status='active'");
$paymentsTotal  = d_val($pdo, "SELECT COUNT(*) FROM payments");
$classesActive  = d_has($pdo, 'token_classes')
    ? d_val($pdo, "SELECT COUNT(*) FROM token_classes WHERE is_active=1 OR is_active IS NULL") : 0;

// ── BNFT Business counts ────────────────────────────────────────────────────
$bizTotal       = d_val($pdo, "SELECT COUNT(*) FROM bnft_memberships");
$bizPaid        = d_val($pdo, "SELECT COUNT(*) FROM bnft_memberships WHERE signup_payment_status='paid'");
$bizUnpaid      = $bizTotal - $bizPaid;
$bizActive      = d_val($pdo, "SELECT COUNT(*) FROM bnft_memberships WHERE wallet_status='active'");

// ── Compliance intake counts ────────────────────────────────────────────────
$jvpaRecorded = 0;
$jvpaIncomplete = 0;
$kycPending = 0;
$kycVerified = 0;
$complianceQueue = [];

if (d_has($pdo, 'members')) {
    $memberRows = d_rows_safe($pdo, "SELECT id, full_name, member_number FROM members ORDER BY id DESC LIMIT 200");
    foreach ($memberRows as $mr) {
        $snap = function_exists('ops_partner_compliance_snapshot')
            ? ops_partner_compliance_snapshot($pdo, (int)$mr['id'])
            : [];
        $j = $snap['jvpa']['status'] ?? 'missing';
        $k = $snap['kyc']['status'] ?? 'not_submitted';
        if ($j === 'verified') { $jvpaRecorded++; }
        elseif ($j !== 'missing') { $jvpaIncomplete++; }
        else { $jvpaIncomplete++; }

        if ($k === 'verified') { $kycVerified++; }
        elseif (in_array($k, ['pending','under_review'], true)) { $kycPending++; }

        if (count($complianceQueue) < 8 && ($j !== 'verified' || in_array($k, ['pending','under_review','rejected'], true))) {
            $complianceQueue[] = [
                'member_id' => (int)$mr['id'],
                'full_name' => (string)($mr['full_name'] ?? ''),
                'member_number' => (string)($mr['member_number'] ?? ''),
                'jvpa_status' => $snap['jvpa']['label'] ?? 'Missing',
                'kyc_status' => $snap['kyc']['label'] ?? 'Not submitted',
                'kyc_submission_id' => $snap['kyc']['submission_id'] ?? null,
            ];
        }
    }
}

// ── Member funnel ───────────────────────────────────────────────────────────
$authAdmin = function_exists('ops_current_admin_user') ? ops_current_admin_user($pdo) : null;
$authRateRows = function_exists('ops_auth_rate_limit_rows') ? ops_auth_rate_limit_rows($pdo) : [];
$authLockedRows = array_values(array_filter($authRateRows, static fn(array $r): bool => !empty($r['locked_until'])));
$legacyBridgeEnabled = function_exists('ops_legacy_admin_bridge_enabled') ? ops_legacy_admin_bridge_enabled($pdo) : false;
$legacyBridgeDeps = function_exists('ops_bridge_dependency_counts') ? ops_bridge_dependency_counts($pdo) : [];
$legacyBridgeActive = 0;
foreach ($legacyBridgeDeps as $bridgeRow) {
    if ((int)($bridgeRow['count'] ?? 0) > 0) {
        $legacyBridgeActive += (int)$bridgeRow['count'];
    }
}

$funnelReserved  = d_val($pdo, "SELECT COUNT(DISTINCT member_id) FROM member_reservation_lines WHERE requested_units > 0");
$funnelPaid      = d_val($pdo, "SELECT COUNT(DISTINCT mrl.member_id) FROM member_reservation_lines mrl JOIN members m ON m.id = mrl.member_id WHERE mrl.paid_units >= mrl.requested_units AND mrl.requested_units > 0 AND m.signup_payment_status = 'paid'");
$funnelApproved  = d_val($pdo, "SELECT COUNT(DISTINCT member_id) FROM member_reservation_lines WHERE approved_units >= requested_units AND requested_units > 0");
$funnelActive    = d_val($pdo, "SELECT COUNT(*) FROM members WHERE wallet_status='active'");

// ── Recent activity ─────────────────────────────────────────────────────────
$recentActivity = d_has($pdo, 'wallet_activity')
    ? d_rows_safe($pdo, "SELECT wa.action_type, wa.created_at, wa.actor_type,
            m.full_name, m.member_number,
            tc.display_name AS token_name
       FROM wallet_activity wa
       LEFT JOIN members m ON m.id = wa.member_id
       LEFT JOIN token_classes tc ON tc.id = wa.token_class_id
       ORDER BY wa.id DESC LIMIT 12") : [];

// ── System checks ───────────────────────────────────────────────────────────
$systemChecks = [
    'wallet_messages'  => d_has($pdo, 'wallet_messages'),
    'announcements'    => d_has($pdo, 'announcements'),
    'vote_proposals'   => d_has($pdo, 'vote_proposals'),
    'wallet_polls'     => d_has($pdo, 'wallet_polls'),
    'email_templates'  => d_has($pdo, 'email_templates'),
    'token_classes'    => d_has($pdo, 'token_classes'),
    'mint_queue'       => d_has($pdo, 'mint_queue'),
    'approval_requests'=> d_has($pdo, 'approval_requests'),
];
$systemAllOk = !in_array(false, $systemChecks, true);

// ── Priority action items ───────────────────────────────────────────────────
$actionItems = [];
if ($pendingApprovals > 0)    $actionItems[] = ['label'=>"$pendingApprovals pending approval".($pendingApprovals===1?'':'s'), 'href'=>'./approvals.php', 'level'=>'high'];
if ($membersUnpaid > 0)       $actionItems[] = ['label'=>"$membersUnpaid member".($membersUnpaid===1?' has':'s have')." unpaid COG$", 'href'=>'./payments.php', 'level'=>'high'];
if ($unreconciled > 0)        $actionItems[] = ['label'=>"$unreconciled unallocated payment".($unreconciled===1?'':'s'), 'href'=>'./reconciliation.php', 'level'=>'medium'];
if ($membersAwaiting > 0)     $actionItems[] = ['label'=>"$membersAwaiting member".($membersAwaiting===1?' has':'s have')." paid, awaiting approval", 'href'=>'./approvals.php', 'level'=>'medium'];
if ($openEvidenceReviews > 0) $actionItems[] = ['label'=>"$openEvidenceReviews evidence review".($openEvidenceReviews===1?'':'s')." pending", 'href'=>'./evidence_reviews.php', 'level'=>'medium'];
if ($pendingKidsVerify > 0)  $actionItems[] = ['label'=>"$pendingKidsVerify Kids S-NFT ID verification".($pendingKidsVerify===1?'':'s')." pending", 'href'=>'./kids.php', 'level'=>'high'];
if ($openExceptions > 0)      $actionItems[] = ['label'=>"$openExceptions open exception".($openExceptions===1?'':'s'), 'href'=>'./exceptions.php', 'level'=>'low'];
if ($mintQueueReady > 0)      $actionItems[] = ['label'=>"$mintQueueReady bridge queue item".($mintQueueReady===1?'':'s')." prepared", 'href'=>'./mint_queue.php', 'level'=>'low'];
if ($bizUnpaid > 0)           $actionItems[] = ['label'=>"$bizUnpaid business".($bizUnpaid===1?'':'es')." unpaid ($40)", 'href'=>'./businesses.php', 'level'=>'medium'];

// ── Action type labels ──────────────────────────────────────────────────────
function activity_label(string $type): string {
    return match($type) {
        'approval_approved'   => 'COG$ approved',
        'approval_rejected'   => 'COG$ rejected',
        'payment_received'    => 'Payment recorded',
        'initial_reservation' => 'Member joined',
        'wallet_update'       => 'Wallet updated',
        'stewardship_move'    => 'Stewardship change',
        default               => ucwords(str_replace('_', ' ', $type)),
    };
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">

<title>Admin Dashboard | COG$ of Australia Foundation</title>
<style>
/* ── Dashboard-specific layout ── */
.topbar-left .eyebrow{font-size:11px;color:var(--sub);text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px}
.topbar-left h1{font-size:1.9rem;font-weight:700;letter-spacing:-.02em;margin-bottom:6px}
.topbar-left p{color:var(--sub);font-size:13px;line-height:1.5;max-width:480px}
.topbar-right{display:flex;gap:8px;flex-shrink:0;flex-wrap:wrap;padding-top:4px}

/* ── Dashboard guide panels ── */
.dashboard-intro-grid{display:grid;grid-template-columns:1.15fr .85fr;gap:16px;margin-bottom:22px}
.dashboard-intro-grid .admin-info-panel,.dashboard-intro-grid .admin-workflow-panel,.dashboard-intro-grid .admin-guide-panel{height:100%}
.dashboard-guide-stack{display:flex;flex-direction:column;gap:16px}
.card-head h2{display:flex;align-items:center;gap:6px;flex-wrap:wrap}


/* ── Funnel ── */
.funnel{display:flex;align-items:stretch;gap:0}
.funnel-step{flex:1;text-align:center;padding:18px 10px;border-right:1px solid var(--line);position:relative}
.funnel-step:last-child{border-right:none}
.funnel-step::after{content:'›';position:absolute;right:-10px;top:50%;transform:translateY(-50%);color:var(--dim);font-size:18px;z-index:1}
.funnel-step:last-child::after{display:none}
.funnel-n{font-size:2rem;font-weight:800;letter-spacing:-.03em;color:var(--gold);line-height:1;margin-bottom:4px}
.funnel-label{font-size:11px;color:var(--sub);font-weight:600;text-transform:uppercase;letter-spacing:.06em}

/* ── Activity feed ── */
.activity-list{list-style:none}
.activity-item{display:flex;align-items:flex-start;gap:12px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.05)}
.activity-item:last-child{border-bottom:none}
.act-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;margin-top:5px}
.act-dot-approve{background:var(--ok);box-shadow:0 0 6px rgba(82,184,122,.5)}
.act-dot-reject{background:var(--err)}
.act-dot-payment{background:var(--gold);box-shadow:0 0 6px rgba(212,178,92,.4)}
.act-dot-join{background:var(--blue)}
.act-dot-default{background:var(--dim)}
.act-main{font-size:13px;color:var(--text);line-height:1.4}
.act-sub{font-size:11px;color:var(--dim);margin-top:2px}

/* ── System checks ── */
.sys-summary{display:flex;align-items:center;gap:8px;cursor:pointer;user-select:none;padding:14px 20px}
.sys-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.sys-dot.ok{background:var(--ok);box-shadow:0 0 8px rgba(82,184,122,.5)}
.sys-dot.bad{background:var(--err);box-shadow:0 0 8px rgba(196,96,96,.5)}
.sys-text{font-size:13px;font-weight:600}
.sys-chevron{margin-left:auto;font-size:10px;color:var(--dim);transition:transform .2s}
.sys-chevron.open{transform:rotate(180deg)}
.sys-detail{display:none;padding:0 20px 14px}
.sys-detail.open{display:block}
.sys-row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:12px}
.sys-row:last-child{border-bottom:none}
.sys-ok{color:var(--ok);font-weight:600}
.sys-bad{color:var(--err);font-weight:600}

/* ── Quick links ── */
.quick-links{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.ql{display:flex;align-items:center;gap:10px;padding:11px 13px;border-radius:var(--r2);background:rgba(255,255,255,.03);border:1px solid var(--line);font-size:13px;transition:border-color .15s,background .15s}
.ql:hover{border-color:rgba(212,178,92,.3);background:var(--goldb)}
.ql-ico{font-size:16px;flex-shrink:0}
.ql-text strong{display:block;font-size:12px;font-weight:600}
.ql-text span{font-size:11px;color:var(--sub)}

/* ── Right col info cards ── */
.info-card{background:linear-gradient(160deg,var(--panel),var(--panel2));border:1px solid var(--line);border-radius:var(--r)}
.compliance-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.comp-stat{padding:12px 14px;background:rgba(255,255,255,.03);border:1px solid var(--line);border-radius:12px}
.comp-stat .n{font-size:1.4rem;font-weight:800;line-height:1;color:var(--gold)}
.comp-stat .l{font-size:10px;color:var(--sub);text-transform:uppercase;letter-spacing:.06em;margin-top:5px}
.comp-list{display:flex;flex-direction:column;gap:10px}
.comp-row{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.05)}
.comp-row:last-child{border-bottom:none}
.comp-main{font-size:13px;line-height:1.4}
.comp-sub{font-size:11px;color:var(--sub);margin-top:3px}
.comp-tags{display:flex;gap:6px;flex-wrap:wrap;margin-top:5px}
.comp-tag{display:inline-block;font-size:10px;font-weight:700;padding:3px 8px;border-radius:999px;border:1px solid var(--line);background:rgba(255,255,255,.04);color:var(--sub)}
.comp-tag.warn{background:var(--warnb);color:var(--warn);border-color:rgba(200,144,26,.2)}
.comp-tag.ok{background:var(--okb);color:var(--ok);border-color:rgba(82,184,122,.2)}

/* ── Responsive ── */
@media(max-width:1200px){.dashboard-intro-grid{grid-template-columns:1fr}.stat-grid{grid-template-columns:repeat(2,1fr)}.main-grid{grid-template-columns:1fr}}
@media(max-width:820px){.stat-grid{grid-template-columns:1fr 1fr}.funnel{flex-wrap:wrap}.main{padding:16px 14px}.topbar{flex-direction:column}}
@media(max-width:520px){.stat-grid{grid-template-columns:1fr}.topbar-right{width:100%}}
</style>
<?php ops_admin_help_assets_once(); ?>
</head>
<body>
<div class="admin-shell">
<?php admin_sidebar_render('dashboard'); ?>
<main class="main">

  <!-- Topbar -->
  <div class="topbar">
    <div class="topbar-left">
      <p class="eyebrow">Beta operations console</p>
      <h1>Admin dashboard<?= ops_admin_help_button('Admin dashboard', 'This is the main operator landing page. Use it to see what needs attention now, understand the control-plane workflow, and jump into the correct live admin section without relying on memory.') ?></h1>
      <p>Use the control-plane pages for live operations. Legacy bridge screens remain available for diagnostics and retirement checks only. This page should be the first stop for operators before they move into intake, approvals, execution, governance, or diagnostics.</p>
    </div>
    <div class="topbar-right">
      <a class="btn btn-gold" href="./payments.php">Record payment</a>
      <a class="btn btn-ghost" href="./approvals.php">Approvals</a>
      <a class="btn btn-ghost" href="./landing.php">Admin guide</a>
      <a class="btn btn-ghost" href="./members.php">Members</a>
      <a class="btn btn-ghost" href="./logout.php" style="color:var(--err);border-color:rgba(196,96,96,.3)">Logout</a>
    </div>
  </div>

  <?= ops_admin_collapsible_help('Page guide & workflow', [
    ops_admin_info_panel('Admin orientation', 'What this dashboard is for', 'Use this dashboard to understand the live operator route, see what needs action now, and move into the correct admin page with context. This page is the overview layer, not the place where approvals, execution, or governance decisions are completed.', [
      'Start here before acting on payments, approvals, execution, governance, or diagnostics.',
      'Operations pages are the authoritative live workflow for day-to-day administration.',
      'Bridge / Diagnostics pages remain available for bridge checks, session mapping, and retirement readiness only.',
      'Each section below explains what it measures, why it matters, and where the operator should go next.'
    ]),
    ops_admin_workflow_panel('Typical operator workflow', 'The most common admin path moves from intake to decision to publication. Use the control-plane pages in this order unless a diagnostic or exception tells you to investigate elsewhere.', [
      ['title' => 'Payments', 'body' => 'Record or verify money received, then confirm which partner or business it belongs to.'],
      ['title' => 'Approvals', 'body' => 'Review what is ready for operational sign-off after payment and compliance requirements are met.'],
      ['title' => 'Token Execution', 'body' => 'Create requests, batch them, pass quorum, submit, finalise, and publish the batch lifecycle.'],
      ['title' => 'Governance / Audit', 'body' => 'Check the published result, evidence trail, and any governance or infrastructure dependency that affects the record.']
    ]),
    ops_admin_status_panel('How to read this page', 'These dashboard sections are meant to reduce operator guesswork before you open a deeper admin page.', [
      ['label' => 'Action needed', 'body' => 'Immediate items that require operational attention now.'],
      ['label' => 'Pipeline cards', 'body' => 'High-level counts that show where partners are in the live intake flow.'],
      ['label' => 'Diagnostics cards', 'body' => 'Bridge, auth, and readiness checks that should inform troubleshooting, not daily processing.']
    ]),
    ops_admin_guide_panel('Admin section guide', 'Each admin section has a distinct job. Use this guide to decide where to go next and what each section is responsible for.', [
      ['title' => 'Payments', 'body' => 'Record or verify incoming money and confirm the intake side of the partner or business journey.'],
      ['title' => 'Approvals', 'body' => 'Apply operational sign-off after payment and compliance steps have been satisfied.'],
      ['title' => 'Token Execution', 'body' => 'Advance approved items through batching, quorum, submission, finalisation, and publication.'],
      ['title' => 'Partner Registry', 'body' => 'Review the current state of personal partner records, wallet status, and linked compliance details.'],
      ['title' => 'Governance & Compliance', 'body' => 'Inspect directions, governance records, auditability, and related supporting evidence.'],
      ['title' => 'Bridge / Diagnostics', 'body' => 'Check transitional bridge state, session mapping, and retirement readiness without using those pages as the primary workflow.']
    ]),
  ]) ?>

<!-- Priority action strip -->
  <div class="priority-strip">
    <span class="strip-label">Action needed</span><?= ops_admin_help_button('Action needed', 'This strip shows the most urgent items that should pull the operator into the next live admin page. These are prompts to act, not the final place where the work is completed.') ?>
    <?php if (empty($actionItems)): ?>
      <span class="strip-clear">✓ All clear</span>
    <?php else: ?>
      <?php foreach ($actionItems as $item): ?>
        <a class="action-pill <?= dh($item['level']) ?>" href="<?= dh($item['href']) ?>">
          <?= dh($item['label']) ?>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Stat cards -->
  <div class="stat-grid">
    <a class="stat-card <?= $pendingApprovals > 0 ? 'high-action' : '' ?>" href="./approvals.php">
      <div class="stat-label">Pending approvals<?= ops_admin_help_button('Pending approvals', 'These are items awaiting an admin decision in Approvals. Use this count to know how much operational sign-off is waiting right now.') ?></div>
      <div class="stat-value"><?= number_format($pendingApprovals) ?></div>
      <div class="stat-sub">COG$ awaiting admin decision</div>
    </a>
    <a class="stat-card <?= $membersUnpaid > 0 ? 'action-needed' : '' ?>" href="./payments.php">
      <div class="stat-label">Awaiting payment<?= ops_admin_help_button('Awaiting payment', 'These are personal identity COG$ records that still need payment to be recorded or reconciled before they can move forward.') ?></div>
      <div class="stat-value"><?= number_format($membersUnpaid) ?></div>
      <div class="stat-sub">Members with unpaid identity COG$</div>
    </a>
    <a class="stat-card <?= $membersAwaiting > 0 ? 'action-needed' : '' ?>" href="./approvals.php">
      <div class="stat-label">Paid, not approved<?= ops_admin_help_button('Paid, not approved', 'These records have money recorded but are still waiting for the operational approval step. This usually means the next stop is Approvals, not Payments.') ?></div>
      <div class="stat-value"><?= number_format($membersAwaiting) ?></div>
      <div class="stat-sub">Ready for approval</div>
    </a>
    <a class="stat-card <?= $mintQueueReady > 0 ? 'ok' : '' ?>" href="./mint_queue.php">
      <div class="stat-label">Mint queue<?= ops_admin_help_button('Mint queue', 'This card reflects bridge-era queue visibility. It is useful for diagnostics and compatibility tracing, but Execution remains the authoritative live batch workflow.') ?></div>
      <div class="stat-value"><?= number_format($mintQueueReady) ?></div>
      <div class="stat-sub">Prepared, not live</div>
    </a>
  </div>

  <!-- Main two-column grid -->
  <div class="main-grid">
    <div class="left-col">

      <!-- Member funnel -->
      <div class="card">
        <div class="card-head">
          <h2>Member pipeline<?= ops_admin_help_button('Member pipeline', 'This card shows the high-level journey from reservation to active wallet state. It helps the operator understand where the current intake is accumulating before opening a deeper registry or intake page.') ?></h2>
          <a href="./members.php">View all →</a>
        </div>
        <div class="funnel">
          <div class="funnel-step">
            <div class="funnel-n"><?= number_format($funnelReserved) ?></div>
            <div class="funnel-label">Reserved</div>
          </div>
          <div class="funnel-step">
            <div class="funnel-n"><?= number_format($funnelPaid) ?></div>
            <div class="funnel-label">Paid</div>
          </div>
          <div class="funnel-step">
            <div class="funnel-n"><?= number_format($funnelApproved) ?></div>
            <div class="funnel-label">Approved</div>
          </div>
          <div class="funnel-step">
            <div class="funnel-n"><?= number_format($funnelActive) ?></div>
            <div class="funnel-label">Active</div>
          </div>
        </div>
        <div class="card-body" style="padding-top:12px;border-top:1px solid var(--line);display:flex;gap:20px;font-size:13px;color:var(--sub)">
          <span>Total members: <strong style="color:var(--text)"><?= number_format($membersTotal) ?></strong></span>
          <span>Active wallets: <strong style="color:var(--ok)"><?= number_format($membersActive) ?></strong></span>
          <span>Businesses: <strong style="color:var(--text)"><?= number_format($bizTotal) ?></strong> (<?= number_format($bizPaid) ?> paid)</span>
          <span>Payments recorded: <strong style="color:var(--text)"><?= number_format($paymentsTotal) ?></strong></span>
          <span>COG$ classes: <strong style="color:var(--text)"><?= number_format($classesActive) ?></strong></span>
        </div>
      </div>

      <!-- Compliance intake -->
      <div class="card">
        <div class="card-head">
          <h2>Compliance intake<?= ops_admin_help_button('Compliance intake', 'This section highlights JVPA and KYC status for recent partner intake so the operator can see which records still need compliance attention.') ?></h2>
          <a href="./members.php">Partner Registry →</a>
        </div>
        <div class="card-body">
          <div class="compliance-grid" style="margin-bottom:14px">
            <div class="comp-stat"><div class="n"><?= number_format($jvpaRecorded) ?></div><div class="l">JVPA recorded</div></div>
            <div class="comp-stat"><div class="n" style="color:var(--warn)"><?= number_format($jvpaIncomplete) ?></div><div class="l">JVPA incomplete / missing</div></div>
            <div class="comp-stat"><div class="n" style="color:var(--warn)"><?= number_format($kycPending) ?></div><div class="l">KYC pending review</div></div>
            <div class="comp-stat"><div class="n" style="color:var(--ok)"><?= number_format($kycVerified) ?></div><div class="l">KYC verified</div></div>
          </div>
          <?php if (empty($complianceQueue)): ?>
            <p style="color:var(--sub);font-size:13px">No current JVPA / KYC exceptions in the recent intake queue.</p>
          <?php else: ?>
            <div class="comp-list">
              <?php foreach ($complianceQueue as $cq): ?>
                <div class="comp-row">
                  <div>
                    <div class="comp-main"><strong><?= dh($cq['full_name'] ?: 'Partner') ?></strong></div>
                    <div class="comp-sub"><?= dh($cq['member_number']) ?></div>
                    <div class="comp-tags">
                      <span class="comp-tag <?= stripos((string)$cq['jvpa_status'], 'Recorded') !== false ? 'ok' : 'warn' ?>">JVPA: <?= dh($cq['jvpa_status']) ?></span>
                      <span class="comp-tag <?= stripos((string)$cq['kyc_status'], 'Verified') !== false ? 'ok' : 'warn' ?>">KYC: <?= dh($cq['kyc_status']) ?></span>
                    </div>
                  </div>
                  <div style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;justify-content:flex-end">
                    <a class="btn btn-ghost" style="padding:7px 10px;font-size:12px" href="./members.php#member-<?= (int)$cq['member_id'] ?>">Open registry</a>
                    <?php if (!empty($cq['kyc_submission_id'])): ?>
                      <a class="btn btn-gold" style="padding:7px 10px;font-size:12px" href="./admin_kyc.php?view=<?= (int)$cq['kyc_submission_id'] ?>">Open KYC</a>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Activity feed -->
      <div class="card">
        <div class="card-head">
          <h2>Recent activity<?= ops_admin_help_button('Recent activity', 'This feed provides a quick operator-readable trace of what the admin system has recorded recently. Use Audit for the deeper evidence trail.') ?></h2>
          <a href="./audit.php">Full audit log →</a>
        </div>
        <div class="card-body" style="padding:0 20px">
          <?php if (empty($recentActivity)): ?>
            <p style="color:var(--sub);font-size:13px;padding:14px 0">No activity recorded yet.</p>
          <?php else: ?>
            <ul class="activity-list">
              <?php foreach ($recentActivity as $ev):
                $type = (string)($ev['action_type'] ?? '');
                $dotClass = match(true) {
                    str_contains($type, 'approved') => 'act-dot-approve',
                    str_contains($type, 'rejected') => 'act-dot-reject',
                    str_contains($type, 'payment')  => 'act-dot-payment',
                    str_contains($type, 'initial')  => 'act-dot-join',
                    default                         => 'act-dot-default',
                };
                $who = trim((string)($ev['full_name'] ?? ''));
                $tok = trim((string)($ev['token_name'] ?? ''));
                $when = (string)($ev['created_at'] ?? '');
              ?>
                <li class="activity-item">
                  <span class="act-dot <?= dh($dotClass) ?>"></span>
                  <div>
                    <div class="act-main">
                      <?= dh(activity_label($type)) ?>
                      <?php if ($who): ?> — <strong><?= dh($who) ?></strong><?php endif; ?>
                      <?php if ($tok): ?> <span style="color:var(--sub)">(<?= dh($tok) ?>)</span><?php endif; ?>
                    </div>
                    <?php if ($when): ?><div class="act-sub"><?= dh($when) ?></div><?php endif; ?>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- /left-col -->

    <!-- Right column -->
    <div style="display:flex;flex-direction:column;gap:18px">

      <!-- Quick links -->
      <div class="card">
        <div class="card-head"><h2>Quick links<?= ops_admin_help_button('Quick links', 'These links take you into the main authoritative admin sections. They are intended to shorten the time between reading the dashboard and opening the correct control-plane page.') ?></h2></div>
        <div class="card-body">
          <div class="quick-links">
            <a class="ql" href="./payments.php"><span class="ql-ico">💳</span><div class="ql-text"><strong>Payments</strong><span>Authoritative intake</span></div></a>
            <a class="ql" href="./approvals.php"><span class="ql-ico">✅</span><div class="ql-text"><strong>Approvals</strong><span>Operational sign-off</span></div></a>
            <a class="ql" href="./execution.php"><span class="ql-ico">⛓️</span><div class="ql-text"><strong>Execution</strong><span>Primary batch workflow</span></div></a>
            <a class="ql" href="./members.php"><span class="ql-ico">👥</span><div class="ql-text"><strong>Partner Registry</strong><span>View & manage</span></div></a>
            <a class="ql" href="./businesses.php"><span class="ql-ico">🏢</span><div class="ql-text"><strong>Business Registry</strong><span>B-NFT records</span></div></a>
            <a class="ql" href="./governance.php"><span class="ql-ico">🗳️</span><div class="ql-text"><strong>Governance</strong><span>Directions & evidence</span></div></a>
            <a class="ql" href="./messages.php"><span class="ql-ico">📣</span><div class="ql-text"><strong>Communications</strong><span>Notices & votes</span></div></a>
            <a class="ql" href="./zones.php"><span class="ql-ico">📍</span><div class="ql-text"><strong>Zones</strong><span>Eligibility & overlays</span></div></a>
            <a class="ql" href="./infrastructure.php"><span class="ql-ico">🛰️</span><div class="ql-text"><strong>Infrastructure</strong><span>Node & shard status</span></div></a>
            <a class="ql" href="./audit.php"><span class="ql-ico">📜</span><div class="ql-text"><strong>Audit / Recovery</strong><span>Evidence and remediation</span></div></a>
            <a class="ql" href="./admin_kyc.php"><span class="ql-ico">🪪</span><div class="ql-text"><strong>KYC Review</strong><span>Identity queue</span></div></a>
            <a class="ql" href="./operator_security.php"><span class="ql-ico">🔐</span><div class="ql-text"><strong>Operator security</strong><span>Password, 2FA, lockouts</span></div></a>
          </div>          </div>
        </div>
      </div>


      <div class="card">
        <div class="card-head"><h2>Legacy bridge diagnostics<?= ops_admin_help_button('Legacy bridge diagnostics', 'Use these pages to inspect transitional bridge writes, session mapping, or retirement readiness. They are diagnostic support surfaces, not the primary live workflow.') ?></h2><a href="./legacy-dependencies.php">Open bridge status →</a></div>
        <div class="card-body">
          <p style="font-size:12px;color:var(--sub);line-height:1.65;margin-bottom:12px">These pages remain available to verify bridge writes, session mapping, and retirement readiness. Use them for diagnostics, not as the primary operator path.</p>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
            <div><div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--sub);margin-bottom:4px">Bridge mode</div><div style="font-weight:700;color:<?= $legacyBridgeEnabled ? 'var(--warn)' : 'var(--ok)' ?>"><?= $legacyBridgeEnabled ? 'Enabled / transitional' : 'Disabled' ?></div></div>
            <div><div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--sub);margin-bottom:4px">Active legacy writes</div><div style="font-weight:700;color:<?= $legacyBridgeActive > 0 ? 'var(--warn)' : 'var(--ok)' ?>"><?= number_format($legacyBridgeActive) ?></div></div>
          </div>
          <div class="quick-links">
            <a class="ql" href="./session-check.php"><span class="ql-ico">🔐</span><div class="ql-text"><strong>Session Check</strong><span>Auth / role bridge state</span></div></a>
            <a class="ql" href="./legacy-dependencies.php"><span class="ql-ico">🧩</span><div class="ql-text"><strong>Legacy Bridge Status</strong><span>Retirement readiness</span></div></a>
            <a class="ql" href="./mint_queue.php"><span class="ql-ico">⛏️</span><div class="ql-text"><strong>Mint Queue</strong><span>Bridge mirror only</span></div></a>
            <a class="ql" href="./chain_handoff.php"><span class="ql-ico">🔗</span><div class="ql-text"><strong>Chain Handoff</strong><span>Bridge export trace</span></div></a>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><h2>Admin auth<?= ops_admin_help_button('Admin auth', 'This card shows the current operator security posture: who is signed in, whether 2FA is enabled, and whether any auth lockouts need clearing.') ?></h2><a href="./operator_security.php">Open security →</a></div>
        <div class="card-body">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
            <div><div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--sub);margin-bottom:4px">Username</div><div style="font-weight:700"><?= dh((string)($authAdmin['username'] ?? '—')) ?></div></div>
            <div><div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--sub);margin-bottom:4px">2FA</div><div style="font-weight:700;color:<?= !empty($authAdmin['two_factor_enabled']) ? 'var(--ok)' : 'var(--warn)' ?>"><?= !empty($authAdmin['two_factor_enabled']) ? 'Enabled' : 'Disabled' ?></div></div>
            <div><div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--sub);margin-bottom:4px">Last login</div><div style="font-weight:700"><?= dh((string)($authAdmin['last_login_at'] ?? '—')) ?></div></div>
            <div><div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--sub);margin-bottom:4px">Lockouts</div><div style="font-weight:700;color:<?= !empty($authLockedRows) ? 'var(--warn)' : 'var(--ok)' ?>"><?= !empty($authLockedRows) ? count($authLockedRows) . ' active' : 'Clear' ?></div></div>
          </div>
          <p style="font-size:12px;color:var(--sub);line-height:1.6">Use Operator security to rotate the live <code>admin_users</code> password from inside admin and clear stale login lockouts without dropping back into phpMyAdmin.</p>
        </div>
      </div>

      <!-- System checks (collapsible) -->
      <div class="info-card">
        <div class="sys-summary" onclick="toggleSys()">
          <span class="sys-dot <?= $systemAllOk ? 'ok' : 'bad' ?>"></span>
          <span class="sys-text"><?= $systemAllOk ? 'All systems ready' : 'System issues detected' ?></span>
          <span class="sys-chevron" id="sys-chevron">▼</span>
        </div>
        <div class="sys-detail" id="sys-detail">
          <?php foreach ($systemChecks as $table => $ok): ?>
            <div class="sys-row">
              <span style="color:var(--sub)"><?= dh($table) ?></span>
              <span class="<?= $ok ? 'sys-ok' : 'sys-bad' ?>"><?= $ok ? 'Ready' : 'Missing' ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

    </div><!-- /right col -->
  </div><!-- /main-grid -->

</main>
</div>
<script>
function toggleSys() {
  var d = document.getElementById('sys-detail');
  var c = document.getElementById('sys-chevron');
  var open = d.classList.toggle('open');
  c.classList.toggle('open', open);
}
// Auto-open system checks if there are issues
<?php if (!$systemAllOk): ?>toggleSys();<?php endif; ?>
</script>
</body>
</html>
