<?php
declare(strict_types=1);
// session_start() removed: ops_require_admin() -> ops_start_admin_php_session()
// sets the session name correctly before starting. Calling it here first would
// use PHP's default name and make the admin cookie invisible (login loop).
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';
require_once __DIR__ . '/../_app/api/config/bootstrap.php';
require_once __DIR__ . '/../_app/api/integrations/mailer.php';

ops_require_admin();
$pdo = ops_db();
$labels = function_exists('ops_label_settings') ? ops_label_settings($pdo) : [];
$publicPartnerLabel = (string)($labels['public_label_partner'] ?? 'Partner');
$publicContributionLabel = (string)($labels['public_label_contribution'] ?? 'partnership contribution');
$internalMemberLabel = (string)($labels['internal_label_member'] ?? 'Member');

if (!function_exists('h')) {
function h($v): string { return ops_h($v); }
}
if (!function_exists('rows')) {
function rows(PDO $pdo, string $sql, array $params=[]): array { $st=$pdo->prepare($sql); $st->execute($params); return $st->fetchAll(PDO::FETCH_ASSOC) ?: []; }
}
if (!function_exists('one')) {
function one(PDO $pdo, string $sql, array $params=[]): ?array { $st=$pdo->prepare($sql); $st->execute($params); $row=$st->fetch(PDO::FETCH_ASSOC); return $row ?: null; }
}
if (!function_exists('member_ref')) {
function member_ref(array $m): string { return (($m['member_type'] ?? '') === 'business') ? (string)($m['abn'] ?? '') : (string)($m['member_number'] ?? ''); }
}
if (!function_exists('member_entity_type')) {
function member_entity_type(array $m): string { return (($m['member_type'] ?? '') === 'business') ? 'bnft_business' : 'snft_member'; }
}
if (!function_exists('user_template')) {
function user_template(array $m): string { return (($m['member_type'] ?? '') === 'business') ? 'bnft_user_confirmation' : 'snft_user_confirmation'; }
}
if (!function_exists('admin_template')) {
function admin_template(array $m): string { return (($m['member_type'] ?? '') === 'business') ? 'bnft_admin_alert' : 'snft_admin_alert'; }
}
if (!function_exists('user_subject')) {
function user_subject(array $m): string { return (($m['member_type'] ?? '') === 'business') ? 'Welcome, Partner — your business partnership record is active' : 'Welcome, Partner — your partnership record is active'; }
}
if (!function_exists('admin_subject')) {
function admin_subject(array $m): string { return (($m['member_type'] ?? '') === 'business') ? 'New business Partner recorded — BNFT pathway' : 'New Partner recorded — SNFT pathway'; }
}
if (!function_exists('payload_for')) {
function payload_for(array $m): array {
    return [
        'full_name'=>(string)($m['full_name'] ?? ''),
        'email'=>(string)($m['email'] ?? ''),
        'member_number'=>(string)($m['member_number'] ?? ''),
        'abn'=>(string)($m['abn'] ?? ''),
        'member_type'=>(string)($m['member_type'] ?? ''),
        'wallet_path'=>(($m['member_type'] ?? '')==='business') ? 'wallets/business.html' : 'wallets/member.html'
    ];
}
}
if (!function_exists('send_template')) {
function send_template(PDO $pdo, array $m, string $recipient, string $template, string $subject): int {
    $id=(int)($m['id'] ?? 0);
    $qid=queueEmail($pdo, member_entity_type($m), $id, $recipient, $template, $subject, payload_for($m), true);
    processEmailQueue($pdo, 25);
    return $qid;
}
}
if (!function_exists('qrow')) {
function qrow(PDO $pdo, int $queueId): ?array {
    return one($pdo,"SELECT id, recipient, template_key, status, created_at, last_error FROM email_queue WHERE id=? LIMIT 1",[$queueId]);
}
}
if (!function_exists('bucket_label')) {
function bucket_label(array $r): string {
    $requested = (int)($r['requested_units'] ?? 0);
    $paid = (int)($r['paid_units'] ?? 0);
    $approved = (int)($r['approved_units'] ?? 0);
    if ($requested === 0) return 'No active reservation lines';
    if ($approved >= $requested) return 'Approved / live-ready';
    if ($paid > 0) return 'Payment received, approval still required';
    return 'Reserved only, payment not yet recorded';
}
}
if (!function_exists('open_class_count')) {
function open_class_count(array $r): int {
    return (int)($r['identity_requested'] ?? 0);
}
}
if (!function_exists('reservation_summary')) {
function reservation_summary(array $r): array {
    return [
        'reserved' => (int)($r['requested_units'] ?? 0),
        'paid' => (int)($r['paid_units'] ?? 0),
        'approved' => (int)($r['approved_units'] ?? 0),
        'identity_reserved' => (int)($r['identity_requested'] ?? 0),
        'other_reserved' => max(0, (int)($r['requested_units'] ?? 0) - (int)($r['identity_requested'] ?? 0)),
    ];
}
}

if (!function_exists('log_partner_support_event')) {
function log_partner_support_event(PDO $pdo, int $memberId, string $eventType, ?int $adminUserId, array $details = []): void {
    if (!ops_has_table($pdo, 'partner_support_events') || !ops_has_table($pdo, 'partners')) return;
    try {
        $partnerId = (int)(ops_fetch_val($pdo, 'SELECT id FROM partners WHERE member_id = ? LIMIT 1', [$memberId]) ?? 0);
        if ($partnerId < 1) return;
        $payload = $details ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
        $pdo->prepare('INSERT INTO partner_support_events (partner_id, event_type, details_json, created_by_admin_user_id, created_at) VALUES (?,?,?,?,NOW())')
            ->execute([$partnerId, $eventType, $payload, $adminUserId ?: null]);
    } catch (Throwable $e) {}
}
}

$flash=null; $error=null; $lastActionSummary=null;
$adminId = function_exists('ops_current_admin_user_id') ? ops_current_admin_user_id($pdo) : null;
$adminUserId = function_exists('ops_current_admin_user_id') ? ops_current_admin_user_id($pdo) : null;
$adminRecipient = trim((string)MAIL_ADMIN_EMAIL);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
    try {
        $action = (string)($_POST['action'] ?? '');
        $memberId = (int)($_POST['member_id'] ?? 0);
        if ($memberId <= 0) throw new RuntimeException('Member missing.');
        $m = one($pdo, "SELECT * FROM members WHERE id=? LIMIT 1", [$memberId]);
        if (!$m) throw new RuntimeException('Member not found.');

        if ($action === 'lock') {
            $pdo->prepare("UPDATE members SET wallet_status='locked', updated_at=NOW() WHERE id=?")->execute([$memberId]);
            if (function_exists('ops_log_wallet_activity')) ops_log_wallet_activity($pdo, $memberId, null, 'member_wallet_locked', 'admin', $adminId, null);
            log_partner_support_event($pdo, $memberId, 'wallet_locked', $adminUserId, ['source'=>'partner_registry']);
            $flash = 'Wallet locked.';
        } elseif ($action === 'unlock') {
            $pdo->prepare("UPDATE members SET wallet_status='active', updated_at=NOW() WHERE id=?")->execute([$memberId]);
            if (function_exists('ops_log_wallet_activity')) ops_log_wallet_activity($pdo, $memberId, null, 'member_wallet_unlocked', 'admin', $adminId, null);
            log_partner_support_event($pdo, $memberId, 'wallet_unlocked', $adminUserId, ['source'=>'partner_registry']);
            $flash = 'Wallet unlocked.';
        } elseif ($action === 'deactivate') {
            $pdo->prepare("UPDATE members SET is_active=0, updated_at=NOW() WHERE id=?")->execute([$memberId]);
            if (function_exists('ops_log_wallet_activity')) ops_log_wallet_activity($pdo, $memberId, null, 'member_deactivated', 'admin', $adminId, null);
            log_partner_support_event($pdo, $memberId, 'partner_deactivated', $adminUserId, ['source'=>'partner_registry']);
            $flash = 'Partner record deactivated.';
        } elseif ($action === 'activate') {
            $pdo->prepare("UPDATE members SET is_active=1, updated_at=NOW() WHERE id=?")->execute([$memberId]);
            if (function_exists('ops_log_wallet_activity')) ops_log_wallet_activity($pdo, $memberId, null, 'member_activated', 'admin', $adminId, null);
            log_partner_support_event($pdo, $memberId, 'partner_activated', $adminUserId, ['source'=>'partner_registry']);
            $flash = 'Partner record activated.';
        } elseif ($action === 'mark_paid') {
            $pdo->prepare("UPDATE members SET signup_payment_status='paid', updated_at=NOW() WHERE id=?")->execute([$memberId]);
            try {
                $mn = one($pdo, "SELECT member_number FROM members WHERE id=? LIMIT 1", [$memberId]);
                if ($mn) {
                    $pdo->prepare("UPDATE snft_memberships SET signup_payment_status='paid', wallet_status='active', updated_at=UTC_TIMESTAMP() WHERE member_number=?")->execute([$mn['member_number']]);
                }
            } catch (Throwable $e) {}
            if (function_exists('ops_log_wallet_activity')) ops_log_wallet_activity($pdo, $memberId, null, 'payment_marked_paid', 'admin', $adminId, null);
            log_partner_support_event($pdo, $memberId, 'signup_payment_marked_paid', $adminUserId, ['source'=>'partner_registry']);
            $flash = 'Entry contribution marked as received for partner #' . $memberId . '.';
        } elseif ($action === 'resend_thankyou') {
            if (trim((string)($m['email'] ?? '')) === '') throw new RuntimeException('Member email missing.');
            $qid = send_template($pdo, $m, (string)$m['email'], user_template($m), user_subject($m));
            $pdo->prepare("UPDATE members SET last_access_email_sent_at=NOW(), updated_at=NOW() WHERE id=?")->execute([$memberId]);
            $lastActionSummary = qrow($pdo, $qid);
            log_partner_support_event($pdo, $memberId, 'partner_thankyou_resent', $adminUserId, ['queue_id'=>$qid]);
            $flash = 'Partner welcome email sent.';
        } elseif ($action === 'resend_admin_notice') {
            if ($adminRecipient === '') throw new RuntimeException('MAIL_ADMIN_EMAIL is empty.');
            $qid = send_template($pdo, $m, $adminRecipient, admin_template($m), admin_subject($m));
            $lastActionSummary = qrow($pdo, $qid);
            log_partner_support_event($pdo, $memberId, 'partner_admin_notice_resent', $adminUserId, ['queue_id'=>$qid]);
            $flash = 'Admin notice sent.';
        } elseif ($action === 'resend_both') {
            if (trim((string)($m['email'] ?? '')) === '') throw new RuntimeException('Member email missing.');
            $qid = send_template($pdo, $m, (string)$m['email'], user_template($m), user_subject($m));
            if ($adminRecipient !== '') $qid = send_template($pdo, $m, $adminRecipient, admin_template($m), admin_subject($m));
            $pdo->prepare("UPDATE members SET last_access_email_sent_at=NOW(), updated_at=NOW() WHERE id=?")->execute([$memberId]);
            $lastActionSummary = qrow($pdo, $qid);
            log_partner_support_event($pdo, $memberId, 'partner_both_notices_resent', $adminUserId, ['queue_id'=>$qid]);
            $flash = 'Partner welcome email and admin notice sent.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$type = trim((string)($_GET['type'] ?? ''));
if ($type !== '' && !in_array($type, ['personal','business'], true)) $type = '';
$showSummary = ($type === '');

$snftTotal=0;$snftPaid=0;$snftGnaf=0;$snftIdV=0;$snftActive=0;
$bnftTotal=0;$bnftPaid=0;$bnftGnaf=0;$bnftSteward=0;$bnftActive=0;
$recentSnft=[];$recentBnft=[];

if ($showSummary) {
    if (ops_has_table($pdo, 'v_phase1_partner_registry')) {
        $snftTotal = (int)(one($pdo,"SELECT COUNT(*) AS c FROM v_phase1_partner_registry WHERE partner_kind='personal'")['c'] ?? 0);
        $snftActive = (int)(one($pdo,"SELECT COUNT(*) AS c FROM v_phase1_partner_registry WHERE partner_kind='personal' AND wallet_status_snapshot='active'")['c'] ?? 0);
        $bnftTotal = (int)(one($pdo,"SELECT COUNT(*) AS c FROM v_phase1_partner_registry WHERE partner_kind='business'")['c'] ?? 0);
    } else {
        $snftTotal  =(int)(one($pdo,"SELECT COUNT(*) AS c FROM members WHERE member_type='personal'")['c']??0);
        $bnftTotal  =(int)(one($pdo,"SELECT COUNT(*) AS c FROM members WHERE member_type='business'")['c']??0);
    }
    $snftPaid   =(int)(one($pdo,"SELECT COUNT(*) AS c FROM members WHERE member_type='personal' AND signup_payment_status='paid'")['c']??0);
    $snftPaid   =(int)(one($pdo,"SELECT COUNT(*) AS c FROM members WHERE member_type='personal' AND signup_payment_status='paid'")['c']??0);
    $snftGnaf   =(int)(one($pdo,"SELECT COUNT(*) AS c FROM members WHERE member_type='personal' AND gnaf_pid IS NOT NULL AND gnaf_pid!=''")['c']??0);
    $snftIdV    =(int)(one($pdo,"SELECT COUNT(*) AS c FROM members WHERE member_type='personal' AND (id_verified=1 OR kyc_status IN ('verified','address_verified'))")['c']??0);
    $snftActive =(int)(one($pdo,"SELECT COUNT(*) AS c FROM members WHERE member_type='personal' AND wallet_status='active'")['c']??0);
    try{
        if (!$bnftTotal) $bnftTotal  =(int)(one($pdo,"SELECT COUNT(*) AS c FROM bnft_memberships")['c']??0);
        $bnftPaid   =(int)(one($pdo,"SELECT COUNT(*) AS c FROM bnft_memberships WHERE signup_payment_status='paid'")['c']??0);
        $bnftGnaf   =(int)(one($pdo,"SELECT COUNT(*) AS c FROM bnft_memberships WHERE gnaf_pid IS NOT NULL AND gnaf_pid!=''")['c']??0);
        $bnftSteward=(int)(one($pdo,"SELECT COUNT(*) AS c FROM bnft_memberships WHERE attestation_hash IS NOT NULL AND attestation_hash!=''")['c']??0);
        $bnftActive =(int)(one($pdo,"SELECT COUNT(*) AS c FROM bnft_memberships WHERE wallet_status='active'")['c']??0);
    }catch(Throwable $e){}
    $recentSnft=rows($pdo,"SELECT id,member_number,full_name,email,signup_payment_status,gnaf_pid,id_verified,kyc_status,wallet_status,created_at FROM members WHERE member_type='personal' ORDER BY id DESC LIMIT 8");
    try{ $recentBnft=rows($pdo,"SELECT id,abn,legal_name,email,signup_payment_status,gnaf_pid,attestation_hash,wallet_status,created_at FROM bnft_memberships ORDER BY id DESC LIMIT 8"); }catch(Throwable $e){}
}

$rows=[];
if(!$showSummary){
    $fSearch = trim((string)($_GET['search'] ?? ''));
    $mWhere  = 'WHERE m.member_type=?';
    $mParams = [$type];
    if ($fSearch !== '') {
        $mWhere .= ' AND (m.full_name LIKE ? OR m.email LIKE ? OR m.member_number LIKE ?)';
        $s = '%' . $fSearch . '%';
        $mParams[] = $s; $mParams[] = $s; $mParams[] = $s;
    }
    $sql="SELECT m.*, p.id AS partner_id, p.partner_number, p.partner_kind, p.governance_status, p.stewardship_status AS partner_stewardship_status, pwa.access_status AS partner_access_status, pwa.last_accessed_at, COALESCE(SUM(mrl.requested_units),0) AS requested_units,COALESCE(SUM(mrl.paid_units),0) AS paid_units,COALESCE(SUM(mrl.approved_units),0) AS approved_units,COUNT(mrl.id) AS line_count,COALESCE(SUM(CASE WHEN tc.class_code IN ('PERSONAL_SNFT','KIDS_SNFT','BUSINESS_BNFT') THEN mrl.requested_units ELSE 0 END),0) AS identity_requested FROM members m LEFT JOIN partners p ON p.member_id=m.id LEFT JOIN partner_wallet_access pwa ON pwa.partner_id = p.id AND pwa.wallet_type = CASE WHEN m.member_type='business' THEN 'business' ELSE 'personal' END LEFT JOIN member_reservation_lines mrl ON mrl.member_id=m.id LEFT JOIN token_classes tc ON tc.id=mrl.token_class_id $mWhere GROUP BY m.id, p.id, p.partner_number, p.partner_kind, p.governance_status, p.stewardship_status, pwa.access_status, pwa.last_accessed_at ORDER BY m.id DESC LIMIT 50";
    $rows=rows($pdo,$sql,$mParams);
} else {
    $fSearch = '';
}
$recentSnftAcceptance = function_exists('ops_member_acceptance_map') ? ops_member_acceptance_map($pdo, array_map(fn($r) => (int)($r['id'] ?? 0), $recentSnft)) : [];
$rowAcceptance = function_exists('ops_member_acceptance_map') ? ops_member_acceptance_map($pdo, array_map(fn($r) => (int)($r['id'] ?? 0), $rows)) : [];
$rowKyc = function_exists('ops_member_kyc_map') ? ops_member_kyc_map($pdo, array_map(fn($r) => (int)($r['id'] ?? 0), $rows)) : [];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">

<title>Partner Registry</title>
<style>
/* ── Members page specific ── */
.table-wrap{overflow:auto;max-width:100%}
.btns{display:flex;gap:8px;flex-wrap:wrap}
.btns form{display:inline-flex}
.hint{font-size:12px;color:var(--muted)}
.segmented{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.segmented a{text-decoration:none;color:var(--text);padding:.7rem .95rem;border:1px solid var(--line);border-radius:12px;background:rgba(255,255,255,.04);font-weight:700;font-size:13px}
.segmented a.active{background:rgba(212,178,92,.12);color:var(--gold);border-color:rgba(212,178,92,.3)}
.progress-stack{display:grid;gap:8px;min-width:240px}
.progress-pill{display:inline-block;padding:.35rem .6rem;border-radius:999px;border:1px solid var(--line);background:rgba(255,255,255,.04);font-size:12px}
.progress-meters{display:grid;gap:6px}
.progress-row{display:grid;grid-template-columns:120px minmax(0,1fr) 70px;gap:10px;align-items:center}
.meter{height:8px;border-radius:999px;background:rgba(255,255,255,.08);overflow:hidden}
.meter > span{display:block;height:100%;background:var(--gold)}
.kpi-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:16px}
.kpi-box{padding:14px 16px;border-radius:14px;border:1px solid var(--line);background:rgba(255,255,255,.03)}
.kpi-box .label{color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px}
.kpi-box .value{font-size:1.6rem;font-weight:800}
/* ── Summary grid ── */
.summary-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
.summary-card{border-color:rgba(212,178,92,.2);padding:16px 20px}
.summary-card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.summary-card-title{font-size:11px;font-weight:700;color:var(--gold);text-transform:uppercase;letter-spacing:.08em}
.summary-card-count{font-size:1.8rem;font-weight:800;margin-top:4px}
.mini-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}
.mini-stat{padding:10px;background:rgba(255,255,255,.03);border:1px solid var(--line);border-radius:10px;text-align:center}
.mini-stat-val{font-size:1.1rem;font-weight:800}
.mini-stat-label{font-size:10px;color:var(--muted);text-transform:uppercase;margin-top:2px}
.mini-stat.ok .mini-stat-val{color:var(--ok)}
.mini-stat.warn .mini-stat-val{color:var(--warn)}
.mini-stat.gold .mini-stat-val{color:var(--gold)}
@media(max-width:980px){.summary-grid{grid-template-columns:1fr}.kpi-grid{grid-template-columns:1fr}.progress-row{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php ops_admin_help_assets_once(); ?>
<div class="shell">
<?php admin_sidebar_render('members'); ?>
<main class="main">
  <div class="card">
    <div class="card-body">
    <h1 style="margin:0 0 8px">Partner Registry <?= ops_admin_help_button('Partner Registry', 'Use this page to understand where a person or entity sits in the registry journey. It shows identity, wallet readiness, JVPA acceptance, reservation mix, and whether the record is ready to move into later operational pages such as Payments, Approvals, and Execution.') ?></h1>
    <?php if($showSummary): ?>
    <p style="color:#9fb0c1;margin:0 0 14px">Overview of the authoritative partner registry across personal and business pathways.</p>
    <?php else: ?>
    <p style="color:#9fb0c1;margin:0 0 14px">Registry progress: <strong>reserved</strong> → <strong>contribution received</strong> → <strong>approved</strong> → <strong>execution-ready</strong>.</p>
    <?php endif; ?>
    <div class="segmented">
      <a href="./members.php" class="<?=$showSummary?'active':''?>">Summary</a>
      <a href="./members.php?type=personal" class="<?=$type==='personal'?'active':''?>">Personal registry</a>
      <a href="./businesses.php">Business (B-NFT)</a>
    </div>
    </div>
  </div>

  <details class="help-section">
    <summary>Page guide &amp; workflow</summary>
    <div class="help-section-body">
      <?= ops_admin_info_panel('Stage 4 · Registry management', 'What this page does', 'The registry pages are the operator view of who is in the system, what evidence exists for them, what their current state is, and what should happen next.', ['Summary view is for orientation and health checks.', 'Personal registry shows Partner records, JVPA state, and readiness signals.', 'Business records are on the Business Registry page; children on the Kids page.']) ?>
      <?= ops_admin_workflow_panel('Typical workflow', '', [['title'=>'Locate the record','body'=>'Open summary for a health check, or open the personal registry for a specific Partner.'], ['title'=>'Read the evidence trail','body'=>'Check JVPA, wallet status, contribution, G-NAF, and KYC before acting.'], ['title'=>'Use the correct next page','body'=>'Payments for money, Approvals for sign-off, KYC for identity, Execution after approval.']]) ?>
    </div>
  </details>
  <?php if($error): ?><div class="msg err"><?=h($error)?></div><?php endif; ?>

<?php if($showSummary): ?>
  <div class="summary-grid">
    <div class="card summary-card">
      <div class="summary-card-header">
        <div><div class="summary-card-title">Personal Partners (S-NFT)</div><div class="summary-card-count"><?=$snftTotal?></div></div>
        <a href="./members.php?type=personal" class="btn btn-sm">View all →</a>
      </div>
      <div class="mini-stats">
        <div class="mini-stat ok"><div class="mini-stat-val"><?=$snftPaid?></div><div class="mini-stat-label">Entry paid</div></div>
        <div class="mini-stat"><div class="mini-stat-val" style="color:var(--blue)"><?=$snftGnaf?></div><div class="mini-stat-label">G-NAF</div></div>
        <div class="mini-stat"><div class="mini-stat-val" style="color:var(--purple)"><?=$snftIdV?></div><div class="mini-stat-label">ID Verified</div></div>
        <div class="mini-stat gold"><div class="mini-stat-val"><?=$snftActive?></div><div class="mini-stat-label">Active</div></div>
      </div>
    </div>
    <div class="card summary-card" style="border-color:rgba(96,212,184,.2)">
      <div class="summary-card-header">
        <div><div class="summary-card-title" style="color:#60d4b8">Business Partners (B-NFT)</div><div class="summary-card-count"><?=$bnftTotal?></div></div>
        <a href="./businesses.php" class="btn btn-sm">View all →</a>
      </div>
      <div class="mini-stats">
        <div class="mini-stat ok"><div class="mini-stat-val"><?=$bnftPaid?></div><div class="mini-stat-label">Paid ($40)</div></div>
        <div class="mini-stat"><div class="mini-stat-val" style="color:#60d4b8"><?=$bnftGnaf?></div><div class="mini-stat-label">G-NAF</div></div>
        <div class="mini-stat gold"><div class="mini-stat-val"><?=$bnftSteward?></div><div class="mini-stat-label">Stewardship</div></div>
        <div class="mini-stat"><div class="mini-stat-val" style="color:#60d4b8"><?=$bnftActive?></div><div class="mini-stat-label">Active</div></div>
      </div>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h2>Recent Personal Members</h2><a href="./members.php?type=personal">View all →</a></div>
    <div class="card-body table-wrap"><table><thead><tr><th>Name</th><th>Partner / Member # <?= ops_admin_help_button('Partner / Member number', 'This is the primary record reference. Use the Partner number where available for operator-facing support, while the legacy member number remains the underlying technical identifier.') ?></th><th>Payment</th><th>G-NAF</th><th>ID</th><th>Status <?= ops_admin_help_button('Status', 'Status combines wallet and registry readiness signals. Read it with JVPA, contribution, and approval context before taking any action.') ?></th><th>Joined</th></tr></thead><tbody>
    <?php foreach($recentSnft as $s): $sPaid=($s['signup_payment_status']??'')=='paid'; $sGnaf=!empty($s['gnaf_pid']); $sIdV=((int)($s['id_verified']??0)===1)||in_array($s['kyc_status']??'',['verified','address_verified'],true); $sAcc=$recentSnftAcceptance[(int)($s['id']??0)]??null; $sAccLabel=function_exists('ops_acceptance_status_label')?ops_acceptance_status_label($sAcc):'—'; $sAccTone=function_exists('ops_acceptance_status_tone')?ops_acceptance_status_tone($sAcc):'warn'; ?>
      <tr><td><strong><?=h($s['full_name']??'')?></strong><div class="muted small"><?=h($s['email']??'')?></div></td><td class="mono muted"><?=h($s['member_number']??'')?></td><td><?=$sPaid?'<span class="st st-ok">✓ Paid</span>':'<span class="st st-warn">Pending</span>'?></td><td><span class="st <?= $sAccTone==='ok'?'st-ok':($sAccTone==='warn'?'st-warn':'st-bad') ?>"><?=h($sAccLabel)?></span></td><td><?=$sGnaf?'<span class="st st-ok">✓</span>':'<span class="muted">—</span>'?></td><td><?=$sIdV?'<span class="st st-ok">✓</span>':'<span class="muted">—</span>'?></td><td><span class="st st-dim"><?=h($s['wallet_status']??'')?></span></td><td class="muted small"><?=h(substr($s['created_at']??'',0,10))?></td></tr>
    <?php endforeach; if(!$recentSnft):?><tr><td colspan="8" class="empty">No personal members yet.</td></tr><?php endif;?>
    </tbody></table></div>
  </div>
  <div class="card">
    <div class="card-head"><h2>Recent Businesses</h2><a href="./businesses.php">View all →</a></div>
    <div class="card-body table-wrap"><table><thead><tr><th>Business</th><th>ABN</th><th>Payment</th><th>G-NAF</th><th>Stewardship</th><th>Status</th><th>Joined</th></tr></thead><tbody>
    <?php foreach($recentBnft as $b): $bPaid=($b['signup_payment_status']??'')=='paid'; $bGnaf=!empty($b['gnaf_pid']); $bSw=!empty($b['attestation_hash']); ?>
      <tr><td><strong><?=h($b['legal_name']??'')?></strong><div class="muted small"><?=h($b['email']??'')?></div></td><td class="mono muted"><?=h($b['abn']??'')?></td><td><?=$bPaid?'<span class="st st-ok">✓ Paid</span>':'<span class="st st-warn">Pending</span>'?></td><td><?=$bGnaf?'<span class="st st-ok">✓</span>':'<span class="muted">—</span>'?></td><td><?=$bSw?'<span class="st st-ok">✓</span>':'<span class="muted">—</span>'?></td><td><span class="st st-dim"><?=h($b['wallet_status']??'')?></span></td><td class="muted small"><?=h(substr($b['created_at']??'',0,10))?></td></tr>
    <?php endforeach; if(!$recentBnft):?><tr><td colspan="7" style="color:#9fb0c1;text-align:center;padding:16px">No business registrations yet.</td></tr><?php endif;?>
    </tbody></table></div>
  </div>
<?php else: /* LIST VIEW */ ?>
  <?php if($lastActionSummary): ?>
    <div class="card"><div class="card-body"><strong>Latest queue result</strong><div class="hint" style="margin-top:6px">#<?= (int)$lastActionSummary['id'] ?> · <?=h($lastActionSummary['recipient'])?> · <?=h($lastActionSummary['template_key'])?> · <?=h($lastActionSummary['status'])?><?php if(!empty($lastActionSummary['last_error'])): ?> · <?=h($lastActionSummary['last_error'])?><?php endif; ?></div></div></div>
  <?php endif; ?>

  <form method="get" style="margin-bottom:0">
  <input type="hidden" name="type" value="<?=h($type)?>">
  <div class="filter-bar">
    <div class="filter-group">
      <label>Name / email / member no.</label>
      <input type="text" name="search" value="<?=h($fSearch)?>" placeholder="Search…" style="min-width:220px" autofocus>
    </div>
    <div style="display:flex;gap:6px;align-items:flex-end">
      <button type="submit" class="btn btn-sm" style="background:rgba(212,178,92,.15);border-color:rgba(212,178,92,.3);color:var(--gold)">Search</button>
      <a href="members.php?type=<?=h($type)?>" class="btn btn-sm">Reset</a>
    </div>
    <?php if ($fSearch !== ''): ?>
      <span style="font-size:11px;color:var(--gold);align-self:center"><?=count($rows)?> result<?=count($rows)!==1?'s':''?></span>
    <?php endif; ?>
  </div>
  </form>

  <div class="card">
    <div class="card-body">
    <div class="kpi-grid">
      <div class="kpi-box"><div class="label">Personal records shown</div><div class="value"><?=count($rows)?></div></div>
      <div class="kpi-box"><div class="label">What "reserved" means</div><div class="hint" style="margin-top:8px">The member has asked for those classes or units in the reservation mix.</div></div>
      <div class="kpi-box"><div class="label">What "approved / live-ready" means</div><div class="hint" style="margin-top:8px">Admin has approved the relevant lines.</div></div>
    </div>
    </div>
  </div>

  <div class="card">
    <div class="card-head">
      <h2><?= $type === 'business' ? 'Business Registry' : 'Personal Registry' ?></h2>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th><?= $type === 'business' ? 'Business' : 'Name' ?></th>
            <th>Partner / Member #</th>
            <th>Status</th>
            <th>JVPA <?= ops_admin_help_button('JVPA', 'This column shows whether the partnership acceptance record is complete enough to support the registry record.') ?></th>
            <th>Reserved <?= ops_admin_help_button('Reserved units', 'Reserved shows recorded units in the reservation mix. It does not mean approved, issued, or published.') ?></th>
            <th>G-NAF</th>
            <th style="width:28px"></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($rows as $r):
          $ref = member_ref($r);
          $sum = reservation_summary($r);
          $reserved = max(0, $sum['reserved']);
          $paid = min($reserved, max(0, $sum['paid']));
          $approved = min($reserved, max(0, $sum['approved']));
          // Use identity_reserved (PERSONAL_SNFT + KIDS_SNFT) as the base for the paid/approved
          // percentage — avoids the bar showing ~0% just because ASX/Landholder/RWA tokens
          // (which require no payment) dominate the total reserved count.
          $identityReserved = max(0, $sum['identity_reserved']);
          $identityBase = max(1, $identityReserved);
          $paidPct     = $identityReserved > 0 ? min(100, round(($paid     / $identityBase) * 100)) : 0;
          $approvedPct = $identityReserved > 0 ? min(100, round(($approved / $identityBase) * 100)) : 0;
          $signupPaid  = ($r['signup_payment_status'] ?? 'pending') === 'paid';
          $acceptance = $rowAcceptance[(int)($r['id'] ?? 0)] ?? null;
          $acceptanceLabel = function_exists('ops_acceptance_status_label') ? ops_acceptance_status_label($acceptance) : '—';
          $acceptanceTone = function_exists('ops_acceptance_status_tone') ? ops_acceptance_status_tone($acceptance) : 'warn';
          $kyc = $rowKyc[(int)($r['id'] ?? 0)] ?? null;
          $rowId = 'mrow-' . (int)$r['id'];
        ?>
          <!-- Compact summary row -->
          <tr onclick="toggleMember('<?=$rowId?>')" style="cursor:pointer" id="<?=$rowId?>-hdr">
            <td>
              <strong style="font-size:13px"><?=h($r['full_name'] ?? '')?></strong>
              <div style="font-size:11px;color:#9fb0c1"><?=h($r['email'] ?? '')?></div><?php if(!empty($r['partner_number'])): ?><div style="font-size:11px;color:#d4b25c">Partner # <?=h($r['partner_number'])?></div><?php endif; ?>
            </td>
            <td style="font-size:12px;font-family:monospace;color:#9fb0c1"><?=h($ref)?></td>
            <td>
              <span class="progress-pill" style="font-size:11px"><?=h($r['wallet_status'] ?? '')?></span>
              <?php if($signupPaid): ?>
                <span style="font-size:11px;color:#7ee0a0;margin-left:4px">✓ $4 paid</span>
              <?php else: ?>
                <span style="font-size:11px;color:#ffb4be;margin-left:4px">● fee unpaid</span>
              <?php endif; ?>
              <?php if((int)($r['is_active']??0)!==1): ?><span style="font-size:11px;color:#ffb4be;margin-left:4px">inactive</span><?php endif; ?>
            </td>
            <td><span style="font-size:11px;color:<?= $acceptanceTone==='ok' ? '#7ee0a0' : ($acceptanceTone==='warn' ? '#d4b25c' : '#ffb4be') ?>"><?=h($acceptanceLabel)?></span></td>
            <td style="font-size:12px"><?=number_format($reserved)?></td>
            <td><?php if(!empty($r['gnaf_pid'])): ?><span style="color:#7ee0a0;font-size:11px">✓</span><?php else: ?><span style="color:#9fb0c1;font-size:11px">—</span><?php endif; ?></td>
            <td style="text-align:center;color:#9fb0c1;font-size:12px" id="<?=$rowId?>-chev">▼</td>
          </tr>
          <!-- Expandable detail row -->
          <tr id="<?=$rowId?>" style="display:none">
            <td colspan="7" style="padding:0">
              <div style="padding:16px;background:rgba(255,255,255,.02);border-top:1px solid rgba(255,255,255,.06)">
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:16px">
                  <!-- Col 1: Identity -->
                  <div>
                    <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">Member</div>
                    <div style="font-size:13px;font-weight:700"><?=h($r['full_name']??'')?></div>
                    <div style="font-size:12px;color:#9fb0c1"><?php if(!empty($r['partner_number'])): ?><?=h($r['partner_number'])?> · <?php endif; ?><?=h($ref)?></div>
                    <div style="font-size:12px;color:#9fb0c1"><?=h($r['email']??'')?></div>
                    <div style="font-size:12px;color:#9fb0c1;margin-top:4px"><?=h($r['mobile']??$r['phone']??'')?></div>
                    <div style="margin-top:6px">
                      <span class="progress-pill"><?=h($r['wallet_status']??'')?></span>
                      <span style="font-size:11px;margin-left:6px;<?=$signupPaid?'color:#7ee0a0':'color:#ffb4be'?>">
                        <?=$signupPaid?'✓ Entry contribution received':'● Entry contribution outstanding'?>
                      </span>
                    </div>
                    <div style="font-size:11px;color:#9fb0c1;margin-top:4px"><?php if(!empty($r['governance_status'])): ?>Governance: <?=h($r['governance_status'])?> · <?php endif; ?><?=bucket_label($r)?></div>
                    <div style="margin-top:8px;padding:10px 12px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:12px">
                      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">JVPA acceptance</div>
                      <div style="font-size:13px;font-weight:700;color:<?= $acceptanceTone==='ok' ? '#7ee0a0' : ($acceptanceTone==='warn' ? '#d4b25c' : '#ffb4be') ?>"><?=h($acceptanceLabel)?></div>
                      <div style="font-size:11px;color:#9fb0c1;margin-top:4px">
                        <?php if(!empty($acceptance['accepted_version'])): ?><?=h($acceptance['accepted_version'])?><?php else: ?>No recorded version yet<?php endif; ?>
                        <?php if(!empty($acceptance['accepted_at'])): ?> · <?=h(substr((string)$acceptance['accepted_at'],0,16))?><?php endif; ?>
                      </div>
                      <div style="font-size:11px;color:#9fb0c1;margin-top:4px">
                        Evidence: <?php if(!empty($acceptance['evidence_vault_id'])): ?>linked<?php else: ?>not linked<?php endif; ?>
                        <?php if(!empty($acceptance['jvpa_title'])): ?> · <?=h($acceptance['jvpa_title'])?><?php endif; ?>
                      </div>
                      <?php if(!empty($acceptance['acceptance_record_hash'])): ?><div style="font-size:11px;color:#9fb0c1;margin-top:4px;font-family:monospace">Hash <?=h(substr((string)$acceptance['acceptance_record_hash'],0,16))?>…</div><?php endif; ?>
                      <div style="font-size:11px;color:#9fb0c1;margin-top:6px"><?= $acceptanceTone==='ok' ? 'Admin action: none — acceptance record is present.' : ($acceptanceTone==='warn' ? 'Admin action: acceptance exists but the evidence trail is incomplete or legacy.' : 'Admin action: no usable JVPA acceptance record is available yet.') ?></div>
                    </div>
                    <div style="margin-top:8px;padding:10px 12px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:12px">
                      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">KYC / Medicare</div>
                      <div style="font-size:13px;font-weight:700;color:<?= (($kyc['status_tone'] ?? 'bad')==='ok' ? '#7ee0a0' : (($kyc['status_tone'] ?? 'bad')==='warn' ? '#d4b25c' : '#ffb4be')) ?>"><?=h((string)($kyc['status_label'] ?? 'Not submitted'))?></div>
                      <div style="font-size:11px;color:#9fb0c1;margin-top:4px">
                        <?php if(!empty($kyc['submission_id'])): ?>Submission #<?= (int)$kyc['submission_id'] ?><?php else: ?>No Medicare submission recorded<?php endif; ?>
                        <?php if(!empty($kyc['submission_status'])): ?> · <?=h((string)$kyc['submission_status'])?><?php endif; ?>
                        <?php if(!empty($kyc['kyc_method'])): ?> · <?=h((string)$kyc['kyc_method'])?><?php endif; ?>
                      </div>
                      <?php if(!empty($kyc['latest_review_action'])): ?><div style="font-size:11px;color:#9fb0c1;margin-top:4px">Latest review action: <?=h((string)$kyc['latest_review_action'])?><?php if(!empty($kyc['latest_review_at'])): ?> · <?=h(substr((string)$kyc['latest_review_at'],0,16))?><?php endif; ?></div><?php endif; ?>
                      <?php if(!empty($kyc['submission_verified_at']) || !empty($kyc['kyc_verified_at']) || !empty($kyc['id_verified_at'])): ?><div style="font-size:11px;color:#9fb0c1;margin-top:4px">Verified at: <?=h(substr((string)($kyc['submission_verified_at'] ?? $kyc['kyc_verified_at'] ?? $kyc['id_verified_at'] ?? ''),0,16))?></div><?php endif; ?>
                      <?php if(!empty($kyc['submission_id'])): ?><div style="margin-top:8px"><a class="btn secondary" style="font-size:12px;padding:6px 12px" href="./admin_kyc.php?view=<?= (int)$kyc['submission_id'] ?>">Open KYC review</a></div><?php endif; ?>
                    </div>
                  </div>
                  <!-- Col 2: Reservation progress -->
                  <div>
                    <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">Reservations</div>
                    <div style="font-size:12px;color:#9fb0c1;margin-bottom:8px"><strong style="color:#eef2f7"><?=number_format($reserved)?></strong> reserved · <?=number_format((int)($r['line_count']??0))?> lines</div>
                    <div class="progress-meters">
                      <div class="progress-row"><div>All reserved</div><div class="meter"><span style="width:100%"></span></div><div><?=number_format($reserved)?></div></div>
                      <div class="progress-row" title="Identity tokens only (S-NFT + Kids S-NFT — these require payment)"><div>Fee paid</div><div class="meter"><span style="width:<?=$paidPct?>%"></span></div><div><?=number_format($paid)?><?=$identityReserved>0?' / '.number_format($identityReserved):''?></div></div>
                      <div class="progress-row"><div>Approved</div><div class="meter"><span style="width:<?=$approvedPct?>%"></span></div><div><?=number_format($approved)?></div></div>
                    </div>
                  </div>
                  <!-- Col 3: Actions -->
                  <div>
                    <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">Actions</div>
                    <div class="btns" style="flex-direction:column;align-items:flex-start">
                <?php if(!$signupPaid): ?>
                <a class="btn secondary" href="./payments.php?member_ref=<?=urlencode((string)$ref)?>">Receive contribution</a>
                <form method="post" style="display:inline">
      <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>"><input type="hidden" name="member_id" value="<?=$r['id']?>"><button class="secondary" type="submit" name="action" value="mark_paid" onclick="return confirm('Mark signup payment as received for this member?')" style="background:rgba(82,184,122,.08);border-color:rgba(82,184,122,.25);color:#7ee0a0">✓ Mark Entry paid</button></form>
                <?php else: ?>
                <a class="btn secondary" href="./payments.php?member_ref=<?=urlencode((string)$ref)?>">View contributions</a>
                <?php endif; ?>
                <a class="btn secondary" href="./approvals.php">Open approvals</a>
                <form method="post">
      <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>"><input type="hidden" name="member_id" value="<?=$r['id']?>"><button class="secondary" type="submit" name="action" value="resend_thankyou">Resend thank you</button></form>
                <form method="post">
      <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>"><input type="hidden" name="member_id" value="<?=$r['id']?>"><button class="secondary" type="submit" name="action" value="resend_admin_notice">Resend admin email</button></form>
                <form method="post">
      <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>"><input type="hidden" name="member_id" value="<?=$r['id']?>"><button class="secondary" type="submit" name="action" value="resend_both">Resend both</button></form>
                <?php if(($r['wallet_status'] ?? '') === 'locked'): ?>
                  <form method="post">
      <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>"><input type="hidden" name="member_id" value="<?=$r['id']?>"><button class="secondary" type="submit" name="action" value="unlock">Unlock</button></form>
                <?php else: ?>
                  <form method="post">
      <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>"><input type="hidden" name="member_id" value="<?=$r['id']?>"><button class="secondary" type="submit" name="action" value="lock">Lock</button></form>
                <?php endif; ?>
                <?php if((int)($r['is_active'] ?? 0) === 1): ?>
                  <form method="post">
      <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>"><input type="hidden" name="member_id" value="<?=$r['id']?>"><button class="secondary" type="submit" name="action" value="deactivate">Deactivate</button></form>
                <?php else: ?>
                  <form method="post">
      <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>"><input type="hidden" name="member_id" value="<?=$r['id']?>"><button class="secondary" type="submit" name="action" value="activate">Activate</button></form>
                <?php endif; ?>
                    </div><!-- /btns -->
                    <div style="font-size:11px;color:var(--muted);margin-top:6px">Notices → <?=h($adminRecipient)?></div>
                  </div><!-- /col3 -->
                </div><!-- /grid -->

                <!-- Verification panels -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <!-- G-NAF Address Verification -->
              <?php if($type === 'personal' && !empty($r['street_address'])): ?>
              <div style="margin-top:12px;padding:12px 14px;background:rgba(255,255,255,.02);border:1px solid rgba(255,255,255,.08);border-radius:12px">
                <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Address Verification</div>
                <div style="font-size:13px;margin-bottom:8px">
                  <?=h($r['street_address'] ?? '')?>, <?=h($r['suburb'] ?? '')?> <?=h($r['state_code'] ?? '')?> <?=h($r['postcode'] ?? '')?>
                </div>
                <?php if(!empty($r['gnaf_pid'])): ?>
                  <div style="font-size:12px;color:#7ee0a0;margin-bottom:6px">✓ Verified — PID: <?=h($r['gnaf_pid'])?></div>
                  <?php if(!empty($r['zone_id'])): ?>
                    <div style="font-size:12px;color:#d4b25c">◈ In Affected Zone (ID: <?=(int)$r['zone_id']?>)</div>
                  <?php else: ?>
                    <div style="font-size:12px;color:var(--muted)">Not in an Affected Zone</div>
                  <?php endif; ?>
                <?php else: ?>
                  <div style="font-size:12px;color:var(--muted);margin-bottom:6px">Not yet verified</div>
                <?php endif; ?>
                <button
                  class="secondary"
                  style="margin-top:8px;font-size:12px;padding:6px 14px"
                  onclick="gnafVerify(<?=(int)$r['id']?>, this)"
                  type="button"
                ><?=empty($r['gnaf_pid']) ? 'Run G-NAF Verification' : 'Re-verify Address'?></button>
                <div id="gnaf-result-<?=(int)$r['id']?>" style="margin-top:8px;font-size:12px;display:none"></div>
              </div>
              <?php endif; ?>
                <!-- Landholder Parcel Verification -->
              <?php if($type === 'personal'): ?>
              <div style="margin-top:10px;padding:12px 14px;background:rgba(255,255,255,.02);border:1px solid rgba(255,255,255,.08);border-radius:12px">
                <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Landholder COG$ Verification</div>
                <?php if(!empty($r['landholder_verified_at'])): ?>
                  <div style="font-size:12px;color:#7ee0a0;margin-bottom:4px">
                    ✓ Verified — <?=number_format((float)($r['landholder_hectares'] ?? 0), 2)?> ha
                    · <?=number_format((int)($r['landholder_tokens_calculated'] ?? 0))?> Lh tokens
                    <?=!empty($r['landholder_zero_cost']) ? '· <span style="color:#d4b25c">Zero-cost (LALC/PBC)</span>' : ''?>
                    <?=!empty($r['landholder_fnac_required']) ? '· <span style="color:#ffb4be">FNAC required</span>' : ''?>
                  </div>
                  <div style="font-size:11px;color:var(--muted);margin-bottom:8px">
                    Type: <?=h($r['landholder_holder_type'] ?? 'freehold')?> · Verified: <?=h(substr($r['landholder_verified_at'],0,10))?>
                  </div>
                <?php else: ?>
                  <div style="font-size:12px;color:var(--muted);margin-bottom:8px">No parcel claim verified yet.</div>
                <?php endif; ?>

                <div id="parcel-form-<?=(int)$r['id']?>" style="margin-top:4px">
                  <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:6px">
                    <div>
                      <label style="font-size:10px;color:var(--muted);display:block;margin-bottom:2px">Holder type</label>
                      <select id="pf-type-<?=(int)$r['id']?>" style="width:100%;background:#0f1720;border:1px solid rgba(255,255,255,.1);color:#eef2f7;padding:5px 7px;border-radius:8px;font-size:12px">
                        <option value="freehold">Freehold</option>
                        <option value="lalc">LALC (Aboriginal Land Rights Act)</option>
                        <option value="pbc">PBC (Native Title Act)</option>
                        <option value="native_title_group">Native Title Holder Group</option>
                      </select>
                    </div>
                    <div>
                      <label style="font-size:10px;color:var(--muted);display:block;margin-bottom:2px">Jurisdiction</label>
                      <select id="pf-juris-<?=(int)$r['id']?>" style="width:100%;background:#0f1720;border:1px solid rgba(255,255,255,.1);color:#eef2f7;padding:5px 7px;border-radius:8px;font-size:12px">
                        <?php foreach(['NSW','VIC','QLD','WA','SA','TAS','ACT','NT'] as $st): ?>
                          <option value="<?=$st?>" <?=($r['state_code']??'')===$st?'selected':''?>><?=$st?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                  <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:6px">
                    <div>
                      <label style="font-size:10px;color:var(--muted);display:block;margin-bottom:2px">Lot number</label>
                      <input id="pf-lot-<?=(int)$r['id']?>" type="text" placeholder="e.g. 1" style="width:100%;background:#0f1720;border:1px solid rgba(255,255,255,.1);color:#eef2f7;padding:5px 7px;border-radius:8px;font-size:12px">
                    </div>
                    <div>
                      <label style="font-size:10px;color:var(--muted);display:block;margin-bottom:2px">Plan number</label>
                      <input id="pf-plan-<?=(int)$r['id']?>" type="text" placeholder="e.g. DP755123" style="width:100%;background:#0f1720;border:1px solid rgba(255,255,255,.1);color:#eef2f7;padding:5px 7px;border-radius:8px;font-size:12px">
                    </div>
                  </div>
                  <div style="margin-bottom:6px">
                    <label style="font-size:10px;color:var(--muted);display:block;margin-bottom:2px">Title reference (optional)</label>
                    <input id="pf-title-<?=(int)$r['id']?>" type="text" placeholder="e.g. CT/12345/67" style="width:100%;background:#0f1720;border:1px solid rgba(255,255,255,.1);color:#eef2f7;padding:5px 7px;border-radius:8px;font-size:12px">
                  </div>
                  <?php if(!empty($r['landholder_verified_at'])): ?>
                  <div style="margin-bottom:6px">
                    <label style="font-size:10px;color:var(--muted);display:block;margin-bottom:2px">Parcel PID (from G-NAF, optional)</label>
                    <input id="pf-pid-<?=(int)$r['id']?>" type="text" placeholder="auto-detected if blank" style="width:100%;background:#0f1720;border:1px solid rgba(255,255,255,.1);color:#eef2f7;padding:5px 7px;border-radius:8px;font-size:12px">
                  </div>
                  <?php endif; ?>
                  <button
                    class="secondary"
                    style="margin-top:4px;font-size:12px;padding:6px 14px"
                    onclick="parcelVerify(<?=(int)$r['id']?>, this)"
                    type="button"
                  >Verify Parcel Claim</button>
                </div>
                <div id="parcel-result-<?=(int)$r['id']?>" style="margin-top:8px;font-size:12px;display:none"></div>
              </div>
              <?php endif; ?>
                </div><!-- /verification grid -->
              </div><!-- /detail inner -->
            </td>
          </tr>
        <?php endforeach; if(!$rows): ?>
          <tr><td colspan="4">No <?=h($type)?> records found.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; /* end showSummary/list conditional */ ?>
</main>
</div>
<script>
function toggleMember(rowId) {
  var row  = document.getElementById(rowId);
  var chev = document.getElementById(rowId + '-chev');
  if (!row) return;
  var open = row.style.display !== 'none';
  row.style.display  = open ? 'none' : 'table-row';
  if (chev) chev.textContent = open ? '▼' : '▲';
}

function gnafVerify(memberId, btn) {
  var resultEl = document.getElementById('gnaf-result-' + memberId);
  var orig = btn.textContent;
  btn.disabled = true;
  btn.textContent = 'Verifying…';
  if (resultEl) { resultEl.style.display = 'none'; resultEl.textContent = ''; }

  fetch('../_app/api/index.php/address-verify', {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'member', member_id: memberId })
  })
  .then(function(r){ return r.json(); })
  .then(function(data) {
    btn.disabled = false;
    btn.textContent = orig;
    if (!resultEl) return;
    resultEl.style.display = 'block';
    if (data.success) {
      var d = data.data;
      var zoneMsg = d.in_affected_zone
        ? '◈ Zone: ' + (d.zone_code || 'unknown')
        : 'Not in an Affected Zone';
      var color = d.status === 'verified' ? '#7ee0a0'
                : d.status === 'low_confidence' ? '#d4b25c'
                : '#ffb4be';
      resultEl.style.color = color;
      resultEl.innerHTML =
        'Status: <strong>' + d.status + '</strong> · ' +
        'Confidence: ' + (d.confidence || 0).toFixed(0) + '% · ' +
        zoneMsg +
        (d.gnaf_address ? '<br>Address: ' + d.gnaf_address : '') +
        (d.requires_review ? '<br><span style="color:#ffb4be">⚠ Routed to manual review</span>' : '');
      if (d.status === 'verified' || d.in_affected_zone) {
        setTimeout(function(){ window.location.reload(); }, 1500);
      }
    } else {
      resultEl.style.color = '#ffb4be';
      resultEl.textContent = 'Error: ' + (data.error || 'Verification failed');
    }
  })
  .catch(function(err) {
    btn.disabled = false;
    btn.textContent = orig;
    if (resultEl) {
      resultEl.style.display = 'block';
      resultEl.style.color = '#ffb4be';
      resultEl.textContent = 'Request failed: ' + err.message;
    }
  });
}

function parcelVerify(memberId, btn) {
  var resultEl = document.getElementById('parcel-result-' + memberId);
  var orig = btn.textContent;
  btn.disabled = true;
  btn.textContent = 'Verifying…';
  if (resultEl) { resultEl.style.display = 'none'; resultEl.textContent = ''; }

  var holderType = document.getElementById('pf-type-'  + memberId)?.value  || 'freehold';
  var juris      = document.getElementById('pf-juris-' + memberId)?.value  || 'NSW';
  var lot        = (document.getElementById('pf-lot-'  + memberId)?.value  || '').trim();
  var plan       = (document.getElementById('pf-plan-' + memberId)?.value  || '').trim();
  var titleRef   = (document.getElementById('pf-title-'+ memberId)?.value  || '').trim();
  var parcelPid  = (document.getElementById('pf-pid-'  + memberId)?.value  || '').trim();

  if (!lot && !plan && !parcelPid) {
    btn.disabled = false;
    btn.textContent = orig;
    if (resultEl) {
      resultEl.style.display = 'block';
      resultEl.style.color = '#ffb4be';
      resultEl.textContent = 'Enter a lot number, plan number, or parcel PID to verify.';
    }
    return;
  }

  fetch('../_app/api/index.php/parcel-verify', {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      action: 'member',
      member_id: memberId,
      holder_type: holderType,
      lot: lot,
      plan: plan,
      jurisdiction: juris,
      title_reference: titleRef,
      parcel_pid: parcelPid
    })
  })
  .then(function(r){ return r.json(); })
  .then(function(data) {
    btn.disabled = false;
    btn.textContent = orig;
    if (!resultEl) return;
    resultEl.style.display = 'block';

    if (data.success) {
      var d = data.data;
      var statusColor = d.status === 'cadastre_matched' || d.status === 'verified' ? '#7ee0a0'
                      : d.status === 'fnac_pending' ? '#d4b25c'
                      : '#ffb4be';
      var html = '<strong style="color:' + statusColor + '">' + d.status + '</strong>';

      if (d.area_hectares) {
        html += ' · ' + parseFloat(d.area_hectares).toFixed(2) + ' ha';
      }
      if (d.tokens_calculated) {
        html += ' · <strong>' + d.tokens_calculated.toLocaleString() + '</strong> Lh tokens';
      }
      if (d.zero_cost_eligible) {
        html += ' · <span style="color:#d4b25c">Zero-cost (LALC/PBC)</span>';
      }
      if (d.tenement_overlap) {
        html += '<br>◈ Tenement zone: ' + (d.tenement_zone_code || 'matched');
      } else {
        html += '<br><span style="color:#ffb4be">⚠ No tenement adjacency found</span>';
      }
      if (d.fnac_routing_required) {
        html += '<br><span style="color:#d4b25c">⚑ FNAC endorsement required (MD 23.4.3)</span>';
      }
      if (d.nntt_overlap) {
        html += '<br><span style="color:#d4b25c">◈ NNTT / Country overlay detected</span>';
      }
      if (d.note) {
        html += '<br><span style="color:var(--muted);font-size:11px">' + d.note + '</span>';
      }
      if (d.mock_mode) {
        html += '<br><span style="color:var(--muted);font-size:11px">⚙ Mock mode — add GEOSCAPE_API_KEY to .env for live data</span>';
      }
      resultEl.innerHTML = html;

      // Reload page after short delay if verified
      if (d.status === 'cadastre_matched' || d.status === 'verified') {
        setTimeout(function(){ window.location.reload(); }, 2000);
      }
    } else {
      resultEl.style.color = '#ffb4be';
      resultEl.textContent = 'Error: ' + (data.error || 'Verification failed');
    }
  })
  .catch(function(err) {
    btn.disabled = false;
    btn.textContent = orig;
    if (resultEl) {
      resultEl.style.display = 'block';
      resultEl.style.color = '#ffb4be';
      resultEl.textContent = 'Request failed: ' + err.message;
    }
  });
}
</script>
</body>
</html>
