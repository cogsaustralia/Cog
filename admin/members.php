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
$publicPartnerLabel = (string)($labels['public_label_partner'] ?? 'Member');
$publicContributionLabel = (string)($labels['public_label_contribution'] ?? 'membership contribution');
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
function user_subject(array $m): string { return (($m['member_type'] ?? '') === 'business') ? 'Welcome, Member — your business membership record is active' : 'Welcome, Member — your membership record is active'; }
}
if (!function_exists('admin_subject')) {
function admin_subject(array $m): string { return (($m['member_type'] ?? '') === 'business') ? 'New business Member recorded — BNFT pathway' : 'New Member recorded — SNFT pathway'; }
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
            $flash = 'Member record deactivated.';
        } elseif ($action === 'activate') {
            $pdo->prepare("UPDATE members SET is_active=1, updated_at=NOW() WHERE id=?")->execute([$memberId]);
            if (function_exists('ops_log_wallet_activity')) ops_log_wallet_activity($pdo, $memberId, null, 'member_activated', 'admin', $adminId, null);
            log_partner_support_event($pdo, $memberId, 'partner_activated', $adminUserId, ['source'=>'partner_registry']);
            $flash = 'Member record activated.';
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
            $flash = 'Entry contribution marked as received for member #' . $memberId . '.';
        } elseif ($action === 'resend_thankyou') {
            if (trim((string)($m['email'] ?? '')) === '') throw new RuntimeException('Member email missing.');
            $qid = send_template($pdo, $m, (string)$m['email'], user_template($m), user_subject($m));
            $pdo->prepare("UPDATE members SET last_access_email_sent_at=NOW(), updated_at=NOW() WHERE id=?")->execute([$memberId]);
            $lastActionSummary = qrow($pdo, $qid);
            log_partner_support_event($pdo, $memberId, 'partner_thankyou_resent', $adminUserId, ['queue_id'=>$qid]);
            $flash = 'Member welcome email sent.';
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
            $flash = 'Member welcome email and admin notice sent.';
        } elseif ($action === 'update_address') {
            $street  = trim((string)($_POST['street_address'] ?? ''));
            $suburb  = trim((string)($_POST['suburb'] ?? ''));
            $state   = strtoupper(trim((string)($_POST['state_code'] ?? '')));
            $postcode = trim((string)($_POST['postcode'] ?? ''));
            if ($street === '') throw new RuntimeException('Street address is required.');
            if ($suburb === '') throw new RuntimeException('Suburb is required.');
            if ($state === '')  throw new RuntimeException('State is required.');
            // Clear G-NAF verification so it re-runs on next login/check
            $pdo->prepare("UPDATE members SET street_address=?, suburb=?, state_code=?, postcode=?,
                gnaf_pid=NULL, address_verified_at=NULL, updated_at=NOW() WHERE id=?")
                ->execute([$street, $suburb, $state, $postcode, $memberId]);
            // Mirror to snft_memberships if column exists
            if (ops_has_table($pdo, 'snft_memberships')) {
                try {
                    $pdo->prepare("UPDATE snft_memberships SET street_address=?, suburb=?, state_code=?, postcode=?, updated_at=NOW() WHERE member_number=?")
                        ->execute([$street, $suburb, $state, $postcode, (string)$m['member_number']]);
                } catch (Throwable $ignored) {}
            }
            log_partner_support_event($pdo, $memberId, 'address_corrected_by_admin', $adminUserId, [
                'street' => $street, 'suburb' => $suburb, 'state' => $state, 'postcode' => $postcode,
                'gnaf_cleared' => true,
            ]);
            $flash = 'Address updated. G-NAF verification cleared — will re-run on next address check.';
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
    // ID Verified = manual admin ID verification (members.id_verified=1) OR
    // formal Medicare KYC verified via canonical source (snft_memberships.kyc_status='verified').
    // Do NOT use members.kyc_status — that legacy column includes the value 'address_verified'
    // which represents G-NAF address verification, NOT identity verification, and inflates the count.
    if (ops_has_table($pdo, 'snft_memberships')) {
        $snftIdV = (int)(one($pdo,"
            SELECT COUNT(*) AS c
            FROM members m
            WHERE m.member_type='personal'
              AND (
                    m.id_verified = 1
                    OR EXISTS (
                        SELECT 1 FROM snft_memberships sm
                        WHERE sm.member_number = m.member_number
                          AND sm.kyc_status = 'verified'
                    )
                  )
        ")['c'] ?? 0);
    } else {
        // Fallback: snft_memberships not available — count manual ID verification only.
        $snftIdV = (int)(one($pdo,"SELECT COUNT(*) AS c FROM members WHERE member_type='personal' AND id_verified=1")['c'] ?? 0);
    }
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
?>?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Member Registry | COG$ Admin</title>
<?php ops_admin_help_assets_once(); ?>
<style>
/* ── Member Registry ── */
.mr-main { padding: 24px 28px; }
.mr-topbar { display:flex; justify-content:space-between; align-items:flex-start;
             flex-wrap:wrap; gap:12px; margin-bottom:20px; }
.mr-topbar h2 { font-size:1.1rem; font-weight:700; margin:0 0 3px; }
.mr-topbar p  { font-size:.8rem; color:var(--sub); margin:0; max-width:560px; }

/* Nav tabs */
.mr-tabs { display:flex; gap:4px; margin-bottom:22px; flex-wrap:wrap; }
.mr-tab  { padding:7px 16px; border-radius:8px; font-size:.8rem; font-weight:700;
           text-decoration:none; border:1px solid var(--line2);
           color:var(--sub); background:var(--panel2); }
.mr-tab:hover  { border-color:rgba(212,178,92,.3); color:var(--gold); }
.mr-tab.active { background:rgba(212,178,92,.15); border-color:rgba(212,178,92,.4);
                 color:var(--gold); }

/* Pipeline stages */
.pipeline { display:flex; gap:0; margin-bottom:22px; border:1px solid var(--line2);
            border-radius:10px; overflow:hidden; }
.pipeline-stage { flex:1; padding:12px 14px; border-right:1px solid var(--line2);
                  text-align:center; }
.pipeline-stage:last-child { border-right:none; }
.pipeline-stage .ps-num   { font-size:1.4rem; font-weight:800; line-height:1; margin-bottom:3px; }
.pipeline-stage .ps-label { font-size:.68rem; text-transform:uppercase;
                             letter-spacing:.07em; color:var(--sub); }
.pipeline-stage .ps-note  { font-size:.7rem; color:var(--dim); margin-top:2px; }
.pipeline-stage.ps-done   .ps-num { color:var(--ok); }
.pipeline-stage.ps-action .ps-num { color:var(--gold); }
.pipeline-stage.ps-warn   .ps-num { color:var(--warn); }

/* Summary cards */
.reg-cards { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:22px; }
.reg-card  { background:var(--panel2); border:1px solid var(--line2);
             border-radius:10px; overflow:hidden; }
.rc-head   { padding:14px 18px 12px; border-bottom:1px solid var(--line2);
             display:flex; justify-content:space-between; align-items:flex-end; }
.rc-head h3    { font-size:.78rem; font-weight:700; text-transform:uppercase;
                 letter-spacing:.07em; margin:0 0 4px; }
.rc-head .rc-n { font-size:2rem; font-weight:800; line-height:1; }
.rc-stats  { display:grid; grid-template-columns:repeat(4,1fr); }
.rc-stat   { padding:10px 12px; border-right:1px solid var(--line2);
             text-align:center; }
.rc-stat:last-child { border-right:none; }
.rc-stat-v { font-size:1rem; font-weight:800; }
.rc-stat-l { font-size:.65rem; text-transform:uppercase; letter-spacing:.05em;
             color:var(--sub); margin-top:2px; }

/* Recent mini-table */
.recent-section { margin-bottom:22px; }
.recent-head { display:flex; justify-content:space-between; align-items:center;
               margin-bottom:10px; }
.recent-head h3 { font-size:.78rem; font-weight:700; text-transform:uppercase;
                  letter-spacing:.08em; color:var(--sub); margin:0; }
.mr-table { width:100%; border-collapse:collapse; font-size:.8rem; }
.mr-table th { background:var(--panel2); padding:7px 12px; text-align:left;
               font-size:.7rem; text-transform:uppercase; letter-spacing:.07em;
               color:var(--gold); border-bottom:1px solid var(--line); font-weight:700; }
.mr-table td { padding:9px 12px; border-bottom:1px solid var(--line2);
               vertical-align:middle; }
.mr-table tr:last-child td { border-bottom:none; }
.mr-table tr:hover td { background:rgba(255,255,255,.02); }

/* Search bar */
.mr-search { display:flex; gap:8px; align-items:center; margin-bottom:18px;
             flex-wrap:wrap; }
.mr-search input { flex:1; min-width:220px; background:var(--panel2);
                   border:1px solid var(--line2); border-radius:8px; color:var(--text);
                   font-size:.83rem; padding:8px 12px; }
.mr-search input:focus { outline:none; border-color:rgba(212,178,92,.4); }

/* Member cards (list view) */
.member-card { border:1px solid var(--line2); border-radius:10px; margin-bottom:8px;
               overflow:hidden; transition:border-color .15s; }
.member-card:hover { border-color:rgba(212,178,92,.2); }
.rsnft-row:hover { background:rgba(212,178,92,.04); }
.mc-header { display:grid; grid-template-columns:1fr auto auto 28px;
             gap:10px; align-items:center; padding:12px 16px;
             cursor:pointer; background:var(--panel); user-select:none;
             transition:background .15s; }
.mc-header:hover { background:rgba(212,178,92,.04); }
.mc-identity h4  { font-size:.85rem; font-weight:700; margin:0 0 2px; }
.mc-identity .mc-sub { font-size:.73rem; color:var(--sub); }
.mc-status-flags { display:flex; gap:5px; flex-wrap:wrap; align-items:center; }
.mc-body { border-top:1px solid var(--line2); background:var(--panel2);
           display:none; }
.mc-body.open { display:block; }
.mc-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:0;
           border-bottom:1px solid var(--line2); }
.mc-col  { padding:16px; border-right:1px solid var(--line2); }
.mc-col:last-child { border-right:none; }
.mc-col-title { font-size:.68rem; text-transform:uppercase; letter-spacing:.08em;
                color:var(--gold); font-weight:700; margin-bottom:10px; }
.mc-row  { display:flex; justify-content:space-between; align-items:flex-start;
           margin-bottom:5px; font-size:.78rem; }
.mc-row-l { color:var(--dim); flex-shrink:0; margin-right:8px; }
.mc-row-v { color:var(--text); text-align:right; word-break:break-word; }
.mc-actions { padding:14px 16px; display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.mc-actions-group { display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
.mc-divider { width:1px; height:20px; background:var(--line2); margin:0 2px; }

/* Status flags */
.sf { display:inline-flex; align-items:center; gap:3px; font-size:.68rem;
      font-weight:700; padding:3px 7px; border-radius:20px; white-space:nowrap; }
.sf-ok   { background:var(--okb);   color:var(--ok);   border:1px solid rgba(82,184,122,.25); }
.sf-warn { background:var(--warnb); color:var(--warn); border:1px solid rgba(212,148,74,.25); }
.sf-err  { background:var(--errb);  color:var(--err);  border:1px solid rgba(192,85,58,.25); }
.sf-dim  { background:var(--panel2);color:var(--sub);  border:1px solid var(--line2); }

/* Progress bar */
.mr-bar { height:6px; border-radius:3px; background:rgba(255,255,255,.07); overflow:hidden;
          margin-top:5px; }
.mr-bar-fill { height:100%; border-radius:3px; background:var(--gold); }

/* Buttons */
.btn-mr  { display:inline-block; padding:6px 13px; border-radius:7px; font-size:.76rem;
           font-weight:700; cursor:pointer; border:none; text-decoration:none; }
.btn-mr-primary { background:rgba(212,178,92,.18); border:1px solid rgba(212,178,92,.4);
                  color:var(--gold); }
.btn-mr-primary:hover { background:rgba(212,178,92,.3); }
.btn-mr-ghost { background:none; border:1px solid var(--line2); color:var(--sub); }
.btn-mr-ghost:hover { border-color:rgba(212,178,92,.3); color:var(--gold); }
.btn-mr-ok    { background:rgba(82,184,122,.12); border:1px solid rgba(82,184,122,.3);
                color:var(--ok); }
.btn-mr-ok:hover { background:rgba(82,184,122,.22); }
.btn-mr-err   { background:rgba(192,85,58,.1); border:1px solid rgba(192,85,58,.3);
                color:var(--err); }

/* Verification panels */
.vp { background:var(--panel); border:1px solid var(--line2); border-radius:8px;
      padding:12px 14px; margin-top:10px; }
.vp-title { font-size:.68rem; text-transform:uppercase; letter-spacing:.07em;
            color:var(--sub); font-weight:700; margin-bottom:8px; }
.vp-grid { display:grid; grid-template-columns:1fr 1fr; gap:6px; }
.vp-field label { font-size:.7rem; color:var(--dim); display:block; margin-bottom:3px; }
.vp-field select, .vp-field input {
  width:100%; background:var(--panel2); border:1px solid var(--line2);
  border-radius:6px; color:var(--text); font-size:.78rem; padding:5px 8px; }
.vp-result { margin-top:8px; font-size:.77rem; display:none; }

/* Wallet status */
.ws-invited { color:var(--sub); }
.ws-active  { color:var(--ok); }
.ws-locked  { color:var(--err); }

/* JVPA mini block */
.jvpa-block { background:var(--panel); border:1px solid var(--line2);
              border-radius:7px; padding:10px 12px; margin-top:8px; }
.jvpa-block .jvpa-status { font-size:.8rem; font-weight:700; }
.jvpa-block .jvpa-meta   { font-size:.71rem; color:var(--sub); margin-top:3px; }

@media (max-width:900px) {
  .mc-grid { grid-template-columns:1fr; }
  .mc-col  { border-right:none; border-bottom:1px solid var(--line2); }
  .reg-cards { grid-template-columns:1fr; }
  .pipeline { flex-direction:column; }
  .pipeline-stage { border-right:none; border-bottom:1px solid var(--line2); }
}
</style>
</head>
<body>
<div class="shell">
<?php admin_sidebar_render('partner_registry'); ?>
<main class="main mr-main">

<div class="mr-topbar">
  <div>
    <h2>👥 Member Registry</h2>
    <p>Registry progress: <strong>Reserved</strong> → <strong>Contribution received</strong> → <strong>Approved</strong> → <strong>Execution-ready</strong>.</p>
  </div>
</div>

<!-- Navigation tabs -->
<div class="mr-tabs">
  <a href="./members.php"                class="mr-tab <?= $showSummary   ? 'active' : '' ?>">📊 Summary</a>
  <a href="./members.php?type=personal"  class="mr-tab <?= $type==='personal' ? 'active' : '' ?>">👤 Personal (S-NFT)</a>
  <a href="./businesses.php"             class="mr-tab">🏢 Business (B-NFT)</a>
</div>

<?php if ($error): ?>
  <div style="background:var(--errb);border:1px solid rgba(192,85,58,.3);border-radius:8px;
              padding:10px 14px;font-size:.82rem;color:var(--err);margin-bottom:16px">
    <?= h($error) ?>
  </div>
<?php endif; ?>

<!-- ════════════════════════ SUMMARY VIEW ════════════════════════ -->
<?php if ($showSummary): ?>

<!-- Pipeline overview -->
<div class="pipeline">
  <div class="pipeline-stage">
    <div class="ps-num"><?= $snftTotal + $bnftTotal ?></div>
    <div class="ps-label">Total Registered</div>
    <div class="ps-note">All pathways</div>
  </div>
  <div class="pipeline-stage ps-action">
    <div class="ps-num"><?= $snftPaid + $bnftPaid ?></div>
    <div class="ps-label">Entry Paid</div>
    <div class="ps-note">S-NFT $4 · B-NFT $40</div>
  </div>
  <div class="pipeline-stage">
    <div class="ps-num"><?= $snftGnaf + $bnftGnaf ?></div>
    <div class="ps-label">G-NAF Verified</div>
    <div class="ps-note">Address confirmed</div>
  </div>
  <div class="pipeline-stage">
    <div class="ps-num"><?= $snftIdV ?></div>
    <div class="ps-label">ID Verified</div>
    <div class="ps-note">KYC complete</div>
  </div>
  <div class="pipeline-stage ps-done">
    <div class="ps-num"><?= $snftActive + $bnftActive ?></div>
    <div class="ps-label">Active Wallets</div>
    <div class="ps-note">Ready to operate</div>
  </div>
</div>

<!-- Registry cards -->
<div class="reg-cards">
  <div class="reg-card">
    <div class="rc-head">
      <div>
        <h3 style="color:var(--gold)">Personal Members (S-NFT)</h3>
        <div class="rc-n"><?= $snftTotal ?></div>
      </div>
      <a href="./members.php?type=personal" class="btn-mr btn-mr-primary" style="font-size:.75rem">
        View all →
      </a>
    </div>
    <div class="rc-stats">
      <div class="rc-stat">
        <div class="rc-stat-v" style="color:var(--ok)"><?= $snftPaid ?></div>
        <div class="rc-stat-l">Entry paid</div>
      </div>
      <div class="rc-stat">
        <div class="rc-stat-v" style="color:#60b4d8"><?= $snftGnaf ?></div>
        <div class="rc-stat-l">G-NAF</div>
      </div>
      <div class="rc-stat">
        <div class="rc-stat-v" style="color:#b088d4"><?= $snftIdV ?></div>
        <div class="rc-stat-l">ID verified</div>
      </div>
      <div class="rc-stat">
        <div class="rc-stat-v" style="color:var(--gold)"><?= $snftActive ?></div>
        <div class="rc-stat-l">Active</div>
      </div>
    </div>
  </div>

  <div class="reg-card">
    <div class="rc-head">
      <div>
        <h3 style="color:#60d4b8">Business Members (B-NFT)</h3>
        <div class="rc-n"><?= $bnftTotal ?></div>
      </div>
      <a href="./businesses.php" class="btn-mr btn-mr-ghost" style="font-size:.75rem">
        View all →
      </a>
    </div>
    <div class="rc-stats">
      <div class="rc-stat">
        <div class="rc-stat-v" style="color:var(--ok)"><?= $bnftPaid ?></div>
        <div class="rc-stat-l">Entry paid</div>
      </div>
      <div class="rc-stat">
        <div class="rc-stat-v" style="color:#60d4b8"><?= $bnftGnaf ?></div>
        <div class="rc-stat-l">G-NAF</div>
      </div>
      <div class="rc-stat">
        <div class="rc-stat-v" style="color:var(--gold)"><?= $bnftSteward ?></div>
        <div class="rc-stat-l">Stewardship</div>
      </div>
      <div class="rc-stat">
        <div class="rc-stat-v" style="color:#60d4b8"><?= $bnftActive ?></div>
        <div class="rc-stat-l">Active</div>
      </div>
    </div>
  </div>
</div>

<!-- Recent personal members -->
<div class="recent-section">
  <div class="recent-head">
    <h3>Recent Personal Members</h3>
    <a href="./members.php?type=personal" class="btn-mr btn-mr-ghost" style="font-size:.73rem">
      View all →
    </a>
  </div>
  <div style="background:var(--panel2);border:1px solid var(--line2);border-radius:10px;overflow:hidden">
    <table class="mr-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Member #</th>
          <th>Entry</th>
          <th>JVPA</th>
          <th>G-NAF</th>
          <th>ID</th>
          <th>Wallet</th>
          <th>Joined</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($recentSnft as $s):
        $sPaid = ($s['signup_payment_status'] ?? '') === 'paid';
        $sGnaf = !empty($s['gnaf_pid']);
        $sIdV  = ((int)($s['id_verified'] ?? 0) === 1) || in_array($s['kyc_status'] ?? '', ['verified','address_verified'], true);
        $sAcc  = $recentSnftAcceptance[(int)($s['id'] ?? 0)] ?? null;
        $sAccLabel = function_exists('ops_acceptance_status_label') ? ops_acceptance_status_label($sAcc) : '—';
        $sAccTone  = function_exists('ops_acceptance_status_tone')  ? ops_acceptance_status_tone($sAcc)  : 'warn';
        $wClass = 'ws-' . ($s['wallet_status'] ?? 'invited');
        $rowId  = 'rsnft-' . (int)($s['id'] ?? 0);
        $kycStatus = $s['kyc_status'] ?? 'none';
        $kycLabel  = match($kycStatus) {
            'verified'     => 'Verified',
            'pending'      => 'Pending review',
            'under_review' => 'Under review',
            'rejected'     => 'Rejected',
            default        => 'Not submitted',
        };
        $kycColor  = match($kycStatus) {
            'verified'     => 'var(--ok)',
            'pending','under_review' => 'var(--warn)',
            default        => 'var(--dim)',
        };
      ?>
        <tr onclick="toggleRsnft('<?= $rowId ?>')" style="cursor:pointer" class="rsnft-row">
          <td>
            <strong style="font-size:.82rem"><?= h($s['full_name'] ?? '') ?></strong>
            <div style="font-size:.7rem;color:var(--sub)"><?= h($s['email'] ?? '') ?></div>
          </td>
          <td style="font-family:monospace;font-size:.72rem;color:var(--sub)"><?= h($s['member_number'] ?? '') ?></td>
          <td><?= $sPaid ? '<span class="sf sf-ok">✓ Paid</span>' : '<span class="sf sf-warn">Pending</span>' ?></td>
          <td><span class="sf sf-<?= $sAccTone === 'ok' ? 'ok' : ($sAccTone === 'warn' ? 'warn' : 'err') ?>"><?= h($sAccLabel) ?></span></td>
          <td><?= $sGnaf ? '<span class="sf sf-ok">✓</span>' : '<span style="color:var(--dim)">—</span>' ?></td>
          <td><?= $sIdV  ? '<span class="sf sf-ok">✓</span>' : '<span style="color:var(--dim)">—</span>' ?></td>
          <td><span class="<?= $wClass ?>" style="font-size:.75rem"><?= h($s['wallet_status'] ?? '') ?></span></td>
          <td style="font-size:.72rem;color:var(--dim)"><?= h(substr($s['created_at'] ?? '', 0, 10)) ?></td>
        </tr>
        <tr id="<?= $rowId ?>" class="rsnft-detail" style="display:none">
          <td colspan="8" style="padding:0">
            <div style="padding:12px 16px;background:var(--panel2);border-top:1px solid var(--line2);display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start">
              <div style="flex:1;min-width:200px">
                <div style="font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--sub);margin-bottom:6px">Identity</div>
                <div style="font-size:.8rem;color:var(--text);margin-bottom:3px"><?= h($s['full_name'] ?? '') ?></div>
                <div style="font-size:.75rem;color:var(--sub)"><?= h($s['email'] ?? '') ?></div>
                <div style="font-family:monospace;font-size:.72rem;color:var(--sub);margin-top:3px"><?= h($s['member_number'] ?? '') ?></div>
              </div>
              <div style="min-width:140px">
                <div style="font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--sub);margin-bottom:6px">Status</div>
                <div style="display:flex;flex-direction:column;gap:4px">
                  <span class="sf sf-<?= $sPaid ? 'ok' : 'warn' ?>" style="width:fit-content"><?= $sPaid ? '✓ $4 paid' : 'Payment pending' ?></span>
                  <span class="sf sf-<?= $sAccTone === 'ok' ? 'ok' : ($sAccTone === 'warn' ? 'warn' : 'err') ?>" style="width:fit-content">JVPA: <?= h($sAccLabel) ?></span>
                  <span class="sf sf-<?= $sGnaf ? 'ok' : 'dim' ?>" style="width:fit-content"><?= $sGnaf ? 'G-NAF ✓' : 'No G-NAF' ?></span>
                </div>
              </div>
              <div style="min-width:140px">
                <div style="font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--sub);margin-bottom:6px">KYC / Identity</div>
                <span style="font-size:.78rem;color:<?= $kycColor ?>"><?= h($kycLabel) ?></span>
                <div style="font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--sub);margin:8px 0 4px">Wallet</div>
                <span class="<?= $wClass ?>" style="font-size:.75rem"><?= h($s['wallet_status'] ?? '') ?></span>
              </div>
              <div style="min-width:120px">
                <div style="font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--sub);margin-bottom:6px">Joined</div>
                <div style="font-size:.78rem;color:var(--text)"><?= h(substr($s['created_at'] ?? '', 0, 10)) ?></div>
                <div style="margin-top:10px">
                  <a href="./members.php?type=personal&search=<?= urlencode($s['member_number'] ?? '') ?>" class="btn-mr btn-mr-ghost" style="font-size:.72rem">Full profile →</a>
                </div>
              </div>
            </div>
          </td>
        </tr>
      <?php endforeach; if (!$recentSnft): ?>
        <tr><td colspan="8" style="text-align:center;color:var(--sub);padding:20px">No personal members yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Recent businesses -->
<?php if (!empty($recentBnft)): ?>
<div class="recent-section">
  <div class="recent-head">
    <h3>Recent Business Members</h3>
    <a href="./businesses.php" class="btn-mr btn-mr-ghost" style="font-size:.73rem">View all →</a>
  </div>
  <div style="background:var(--panel2);border:1px solid var(--line2);border-radius:10px;overflow:hidden">
    <table class="mr-table">
      <thead>
        <tr><th>Business</th><th>ABN</th><th>Entry</th><th>G-NAF</th><th>Stewardship</th><th>Wallet</th><th>Joined</th></tr>
      </thead>
      <tbody>
      <?php foreach ($recentBnft as $b):
        $bPaid = ($b['signup_payment_status'] ?? '') === 'paid';
        $bGnaf = !empty($b['gnaf_pid']);
        $bSw   = !empty($b['attestation_hash']);
      ?>
        <tr>
          <td><strong style="font-size:.82rem"><?= h($b['legal_name'] ?? '') ?></strong>
              <div style="font-size:.7rem;color:var(--sub)"><?= h($b['email'] ?? '') ?></div></td>
          <td style="font-family:monospace;font-size:.72rem;color:var(--sub)"><?= h($b['abn'] ?? '') ?></td>
          <td><?= $bPaid ? '<span class="sf sf-ok">✓ Paid</span>' : '<span class="sf sf-warn">Pending</span>' ?></td>
          <td><?= $bGnaf ? '<span class="sf sf-ok">✓</span>' : '<span style="color:var(--dim)">—</span>' ?></td>
          <td><?= $bSw   ? '<span class="sf sf-ok">✓</span>' : '<span style="color:var(--dim)">—</span>' ?></td>
          <td style="font-size:.75rem;color:var(--sub)"><?= h($b['wallet_status'] ?? '') ?></td>
          <td style="font-size:.72rem;color:var(--dim)"><?= h(substr($b['created_at'] ?? '', 0, 10)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ════════════════════════ LIST VIEW ════════════════════════ -->
<?php else: ?>

<?php if ($lastActionSummary): ?>
  <div style="background:var(--okb);border:1px solid rgba(82,184,122,.3);border-radius:8px;
              padding:10px 14px;font-size:.8rem;color:var(--ok);margin-bottom:14px">
    Queue result — #<?= (int)$lastActionSummary['id'] ?> · <?= h($lastActionSummary['recipient']) ?>
    · <?= h($lastActionSummary['template_key']) ?> · <?= h($lastActionSummary['status']) ?>
    <?php if (!empty($lastActionSummary['last_error'])): ?> · <?= h($lastActionSummary['last_error']) ?><?php endif; ?>
  </div>
<?php endif; ?>

<!-- Search -->
<form method="get" class="mr-search">
  <input type="hidden" name="type" value="<?= h($type) ?>">
  <input type="text" name="search" value="<?= h($fSearch) ?>"
         placeholder="Search by name, email, or member number…" autofocus>
  <button type="submit" class="btn-mr btn-mr-primary">Search</button>
  <a href="./members.php?type=<?= h($type) ?>" class="btn-mr btn-mr-ghost">Reset</a>
  <?php if ($fSearch !== ''): ?>
    <span style="font-size:.78rem;color:var(--gold)"><?= count($rows) ?> result<?= count($rows) !== 1 ? 's' : '' ?></span>
  <?php endif; ?>
</form>

<!-- Member count / context -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
  <div style="font-size:.78rem;color:var(--sub)">
    Showing <?= count($rows) ?> <?= h($type) ?> member<?= count($rows) !== 1 ? 's' : '' ?>
    <?= $fSearch ? '— filtered' : '(most recent 50)' ?>
  </div>
  <div style="display:flex;gap:6px">
    <a href="./payments.php" class="btn-mr btn-mr-ghost" style="font-size:.73rem">→ Payments</a>
    <a href="./approvals.php" class="btn-mr btn-mr-ghost" style="font-size:.73rem">→ Approvals</a>
    <a href="./admin_kyc.php" class="btn-mr btn-mr-ghost" style="font-size:.73rem">→ KYC</a>
  </div>
</div>

<!-- Member cards -->
<?php if (empty($rows)): ?>
  <div style="text-align:center;padding:48px;color:var(--sub);font-size:.85rem;
              background:var(--panel2);border:1px solid var(--line2);border-radius:10px">
    No <?= h($type) ?> records found.<?= $fSearch ? ' Try a different search term.' : '' ?>
  </div>
<?php endif; ?>

<?php foreach ($rows as $r):
  $ref   = member_ref($r);
  $sum   = reservation_summary($r);
  $reserved = max(0, $sum['reserved']);
  $paid     = min($reserved, max(0, $sum['paid']));
  $approved = min($reserved, max(0, $sum['approved']));
  $identityReserved = max(0, $sum['identity_reserved']);
  $identityBase = max(1, $identityReserved);
  $paidPct     = $identityReserved > 0 ? min(100, round(($paid     / $identityBase) * 100)) : 0;
  $approvedPct = $identityReserved > 0 ? min(100, round(($approved / $identityBase) * 100)) : 0;
  $signupPaid  = ($r['signup_payment_status'] ?? 'pending') === 'paid';
  $isActive    = (int)($r['is_active'] ?? 0) === 1;
  $isLocked    = ($r['wallet_status'] ?? '') === 'locked';
  $acceptance  = $rowAcceptance[(int)($r['id'] ?? 0)] ?? null;
  $acceptanceLabel = function_exists('ops_acceptance_status_label') ? ops_acceptance_status_label($acceptance) : '—';
  $acceptanceTone  = function_exists('ops_acceptance_status_tone')  ? ops_acceptance_status_tone($acceptance)  : 'warn';
  $kyc    = $rowKyc[(int)($r['id'] ?? 0)] ?? null;
  $cardId = 'mc-' . (int)$r['id'];
  $hasGnaf = !empty($r['gnaf_pid']);
  $hasLand = !empty($r['landholder_verified_at']);
?>

<div class="member-card">

  <!-- ── Card header ── -->
  <div class="mc-header" onclick="toggleMember('<?= $cardId ?>')">

    <div class="mc-identity">
      <h4><?= h($r['full_name'] ?? '') ?></h4>
      <div class="mc-sub">
        <?= h($r['email'] ?? '') ?>
        <?php if (!empty($r['partner_number'])): ?>
          &nbsp;·&nbsp; <?= h($r['partner_number']) ?>
        <?php endif; ?>
        &nbsp;·&nbsp; <span style="font-family:monospace"><?= h($ref) ?></span>
      </div>
    </div>

    <div class="mc-status-flags">
      <?php if ($signupPaid): ?>
        <span class="sf sf-ok">✓ $4 paid</span>
      <?php else: ?>
        <span class="sf sf-warn">Fee pending</span>
      <?php endif; ?>

      <span class="sf sf-<?= $acceptanceTone === 'ok' ? 'ok' : ($acceptanceTone === 'warn' ? 'warn' : 'err') ?>">
        JVPA: <?= h($acceptanceLabel) ?>
      </span>

      <?php if ($hasGnaf): ?>
        <span class="sf sf-ok">G-NAF ✓</span>
      <?php else: ?>
        <span class="sf sf-dim">No G-NAF</span>
      <?php endif; ?>

      <?php
        $kycStatus = $kyc['status'] ?? 'none';
        $kycLabel  = $kyc['status_label'] ?? 'KYC: Not submitted';
        $kycTone   = $kyc['status_tone'] ?? 'bad';
        $kycSfClass = $kycTone === 'ok' ? 'sf-ok' : ($kycTone === 'warn' ? 'sf-warn' : 'sf-dim');
      ?>
      <span class="sf <?= $kycSfClass ?>">KYC: <?= h($kycLabel) ?></span>

      <?php
        $wStatus = $r['wallet_status'] ?? 'invited';
        $wClass  = $wStatus === 'active' ? 'sf-ok' : ($wStatus === 'locked' ? 'sf-err' : 'sf-dim');
      ?>
      <span class="sf <?= $wClass ?>"><?= h($wStatus) ?></span>

      <?php if (!$isActive): ?>
        <span class="sf sf-err">Inactive</span>
      <?php endif; ?>
    </div>

    <div style="text-align:right;font-size:.73rem;color:var(--dim)">
      <?= h(substr($r['created_at'] ?? '', 0, 10)) ?>
    </div>

    <div style="color:var(--dim);font-size:.75rem;text-align:center" id="<?= $cardId ?>-chev">▼</div>
  </div>

  <!-- ── Expanded body ── -->
  <div class="mc-body" id="<?= $cardId ?>">
    <div class="mc-grid">

      <!-- Col 1: Member details + JVPA + KYC -->
      <div class="mc-col">
        <div class="mc-col-title">Member</div>

        <div class="mc-row"><span class="mc-row-l">Name</span>
          <span class="mc-row-v" style="font-weight:700"><?= h($r['full_name'] ?? '') ?></span></div>
        <div class="mc-row"><span class="mc-row-l">Email</span>
          <span class="mc-row-v"><?= h($r['email'] ?? '') ?></span></div>
        <?php if (!empty($r['mobile'] ?? $r['phone'] ?? '')): ?>
        <div class="mc-row"><span class="mc-row-l">Mobile</span>
          <span class="mc-row-v"><?= h($r['mobile'] ?? $r['phone'] ?? '') ?></span></div>
        <?php endif; ?>
        <div class="mc-row"><span class="mc-row-l">Member #</span>
          <span class="mc-row-v" style="font-family:monospace;font-size:.72rem"><?= h($ref) ?></span></div>
        <?php if (!empty($r['partner_number'])): ?>
        <div class="mc-row"><span class="mc-row-l">Partner #</span>
          <span class="mc-row-v" style="font-family:monospace;font-size:.72rem;color:var(--gold)"><?= h($r['partner_number']) ?></span></div>
        <?php endif; ?>
        <div class="mc-row"><span class="mc-row-l">Wallet</span>
          <span class="mc-row-v ws-<?= h($r['wallet_status'] ?? 'invited') ?>"><?= h($r['wallet_status'] ?? '') ?></span></div>
        <div class="mc-row"><span class="mc-row-l">Entry fee</span>
          <span class="mc-row-v" style="color:<?= $signupPaid ? 'var(--ok)' : 'var(--warn)' ?>">
            <?= $signupPaid ? '✓ Received' : '● Pending' ?></span></div>
        <?php if (!empty($r['governance_status'])): ?>
        <div class="mc-row"><span class="mc-row-l">Governance</span>
          <span class="mc-row-v"><?= h($r['governance_status']) ?></span></div>
        <?php endif; ?>

        <!-- JVPA -->
        <div class="jvpa-block" style="margin-top:10px">
          <div style="font-size:.68rem;text-transform:uppercase;letter-spacing:.07em;
                      color:var(--sub);font-weight:700;margin-bottom:6px">JVPA Acceptance</div>
          <div class="jvpa-status" style="color:<?= $acceptanceTone === 'ok' ? 'var(--ok)' : ($acceptanceTone === 'warn' ? 'var(--warn)' : 'var(--err)') ?>">
            <?= h($acceptanceLabel) ?>
          </div>
          <div class="jvpa-meta">
            <?php if (!empty($acceptance['accepted_version'])): ?><?= h($acceptance['accepted_version']) ?><?php else: ?>No version recorded<?php endif; ?>
            <?php if (!empty($acceptance['accepted_at'])): ?> · <?= h(substr((string)$acceptance['accepted_at'], 0, 16)) ?><?php endif; ?>
          </div>
          <?php if (!empty($acceptance['acceptance_record_hash'])): ?>
          <div class="jvpa-meta" style="font-family:monospace;font-size:.68rem;margin-top:2px">
            <?= h(substr((string)$acceptance['acceptance_record_hash'], 0, 20)) ?>…
          </div>
          <?php endif; ?>
        </div>

        <!-- KYC -->
        <div class="jvpa-block" style="margin-top:8px">
          <div style="font-size:.68rem;text-transform:uppercase;letter-spacing:.07em;
                      color:var(--sub);font-weight:700;margin-bottom:6px">KYC / Identity</div>
          <div class="jvpa-status" style="color:<?= ($kyc['status_tone'] ?? '') === 'ok' ? 'var(--ok)' : (($kyc['status_tone'] ?? '') === 'warn' ? 'var(--warn)' : 'var(--err)') ?>">
            <?= h((string)($kyc['status_label'] ?? 'Not submitted')) ?>
          </div>
          <div class="jvpa-meta">
            <?php if (!empty($kyc['submission_id'])): ?>
              Sub #<?= (int)$kyc['submission_id'] ?>
              <?php if (!empty($kyc['submission_status'])): ?> · <?= h((string)$kyc['submission_status']) ?><?php endif; ?>
            <?php else: ?>No submission recorded<?php endif; ?>
          </div>
          <?php if (!empty($kyc['submission_id'])): ?>
          <div style="margin-top:6px">
            <a href="./admin_kyc.php?view=<?= (int)$kyc['submission_id'] ?>"
               class="btn-mr btn-mr-ghost" style="font-size:.72rem;padding:4px 10px">
              Open KYC Review →
            </a>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Col 2: Reservations + Address + Landholder -->
      <div class="mc-col">
        <div class="mc-col-title">Reservations & Verification</div>

        <!-- Reservation progress -->
        <div style="margin-bottom:14px">
          <div class="mc-row" style="margin-bottom:6px">
            <span class="mc-row-l">Total reserved</span>
            <span class="mc-row-v" style="font-weight:700"><?= number_format($reserved) ?></span>
          </div>
          <?php if ($identityReserved > 0): ?>
          <div style="margin-bottom:5px">
            <div style="display:flex;justify-content:space-between;font-size:.72rem;color:var(--sub);margin-bottom:3px">
              <span>Entry paid <span style="color:var(--dim)">(identity tokens)</span></span>
              <span><?= number_format($paid) ?> / <?= number_format($identityReserved) ?></span>
            </div>
            <div class="mr-bar"><div class="mr-bar-fill" style="width:<?= $paidPct ?>%"></div></div>
          </div>
          <div>
            <div style="display:flex;justify-content:space-between;font-size:.72rem;color:var(--sub);margin-bottom:3px">
              <span>Approved</span>
              <span><?= number_format($approved) ?></span>
            </div>
            <div class="mr-bar"><div class="mr-bar-fill" style="width:<?= $approvedPct ?>%;background:var(--ok)"></div></div>
          </div>
          <?php else: ?>
          <div style="font-size:.77rem;color:var(--dim)">No identity token reservations recorded.</div>
          <?php endif; ?>
          <div style="font-size:.72rem;color:var(--dim);margin-top:6px">
            <?= (int)($r['line_count'] ?? 0) ?> reservation line<?= (int)($r['line_count'] ?? 0) !== 1 ? 's' : '' ?>
            · <?= h(bucket_label($r)) ?>
          </div>
        </div>

        <!-- Address -->
        <?php if ($type === 'personal' && !empty($r['street_address'])): ?>
        <div class="vp">
          <div class="vp-title">Address Verification</div>
          <div style="font-size:.79rem;margin-bottom:8px">
            <?= h($r['street_address'] ?? '') ?>, <?= h($r['suburb'] ?? '') ?>
            <?= h($r['state_code'] ?? '') ?> <?= h($r['postcode'] ?? '') ?>
          </div>
          <?php if ($hasGnaf): ?>
            <div style="font-size:.77rem;color:var(--ok);margin-bottom:4px">✓ G-NAF verified — <?= h($r['gnaf_pid']) ?></div>
            <?php if (!empty($r['zone_id'])): ?>
              <div style="font-size:.77rem;color:var(--gold)">◈ In Affected Zone (ID: <?= (int)$r['zone_id'] ?>)</div>
            <?php else: ?>
              <div style="font-size:.77rem;color:var(--dim)">Not in an Affected Zone</div>
            <?php endif; ?>
          <?php else: ?>
            <div style="font-size:.77rem;color:var(--dim);margin-bottom:6px">Not yet G-NAF verified</div>
          <?php endif; ?>
          <button type="button" onclick="gnafVerify(<?= (int)$r['id'] ?>, this)"
                  class="btn-mr btn-mr-ghost" style="font-size:.72rem;padding:4px 10px;margin-top:6px">
            <?= $hasGnaf ? 'Re-verify Address' : 'Run G-NAF Verification' ?>
          </button>
          <div class="vp-result" id="gnaf-result-<?= (int)$r['id'] ?>"></div>

          <details style="margin-top:8px">
            <summary style="font-size:.73rem;color:var(--dim);cursor:pointer">Edit address</summary>
            <form method="post" style="margin-top:8px;display:grid;gap:5px">
              <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
              <input type="hidden" name="action" value="update_address">
              <input type="hidden" name="member_id" value="<?= (int)$r['id'] ?>">
              <input name="street_address" value="<?= h($r['street_address'] ?? '') ?>"
                     placeholder="Street address" style="font-size:.78rem;padding:5px 8px;background:var(--panel);border:1px solid var(--line2);border-radius:6px;color:var(--text);width:100%">
              <div style="display:grid;grid-template-columns:1fr 52px 68px;gap:5px">
                <input name="suburb"     value="<?= h($r['suburb']     ?? '') ?>" placeholder="Suburb"   style="font-size:.78rem;padding:5px 8px;background:var(--panel);border:1px solid var(--line2);border-radius:6px;color:var(--text)">
                <input name="state_code" value="<?= h($r['state_code'] ?? '') ?>" placeholder="State" maxlength="3" style="font-size:.78rem;padding:5px 8px;background:var(--panel);border:1px solid var(--line2);border-radius:6px;color:var(--text)">
                <input name="postcode"   value="<?= h($r['postcode']   ?? '') ?>" placeholder="Postcode" maxlength="4" style="font-size:.78rem;padding:5px 8px;background:var(--panel);border:1px solid var(--line2);border-radius:6px;color:var(--text)">
              </div>
              <button type="submit" class="btn-mr btn-mr-ghost" style="font-size:.73rem;padding:5px 12px;margin-top:2px">
                Save &amp; clear G-NAF
              </button>
            </form>
          </details>
        </div>
        <?php endif; ?>

        <!-- Landholder -->
        <?php if ($type === 'personal'): ?>
        <div class="vp" style="margin-top:8px">
          <div class="vp-title">Landholder COG$ Verification</div>
          <?php if ($hasLand): ?>
            <div style="font-size:.79rem;color:var(--ok);margin-bottom:4px">
              ✓ <?= number_format((float)($r['landholder_hectares'] ?? 0), 2) ?> ha
              · <?= number_format((int)($r['landholder_tokens_calculated'] ?? 0)) ?> Lh tokens
            </div>
            <div style="font-size:.72rem;color:var(--sub)">
              <?= h($r['landholder_holder_type'] ?? 'freehold') ?>
              · Verified <?= h(substr($r['landholder_verified_at'], 0, 10)) ?>
              <?= !empty($r['landholder_zero_cost']) ? ' · <span style="color:var(--gold)">Zero-cost</span>' : '' ?>
              <?= !empty($r['landholder_fnac_required']) ? ' · <span style="color:var(--warn)">FNAC required</span>' : '' ?>
            </div>
          <?php else: ?>
            <div style="font-size:.77rem;color:var(--dim);margin-bottom:8px">No parcel claim verified.</div>
          <?php endif; ?>

          <div class="vp-grid" style="margin-top:8px">
            <div class="vp-field">
              <label>Holder type</label>
              <select id="pf-type-<?= (int)$r['id'] ?>">
                <?php foreach(['freehold'=>'Freehold','lalc'=>'LALC','pbc'=>'PBC','native_title_group'=>'Native Title Group'] as $v=>$l): ?>
                  <option value="<?= $v ?>"><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="vp-field">
              <label>Jurisdiction</label>
              <select id="pf-juris-<?= (int)$r['id'] ?>">
                <?php foreach(['NSW','VIC','QLD','WA','SA','TAS','ACT','NT'] as $st): ?>
                  <option value="<?= $st ?>" <?= ($r['state_code'] ?? '') === $st ? 'selected' : '' ?>><?= $st ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="vp-field">
              <label>Lot number</label>
              <input id="pf-lot-<?= (int)$r['id'] ?>" type="text" placeholder="e.g. 1">
            </div>
            <div class="vp-field">
              <label>Plan number</label>
              <input id="pf-plan-<?= (int)$r['id'] ?>" type="text" placeholder="e.g. DP755123">
            </div>
          </div>
          <div class="vp-field" style="margin-top:5px">
            <label>Title reference (optional)</label>
            <input id="pf-title-<?= (int)$r['id'] ?>" type="text" placeholder="e.g. CT/12345/67">
          </div>
          <button type="button" onclick="parcelVerify(<?= (int)$r['id'] ?>, this)"
                  class="btn-mr btn-mr-ghost" style="font-size:.72rem;padding:4px 10px;margin-top:8px">
            Verify Parcel Claim
          </button>
          <div class="vp-result" id="parcel-result-<?= (int)$r['id'] ?>"></div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Col 3: Actions -->
      <div class="mc-col">
        <div class="mc-col-title">Actions</div>

        <!-- Entry contribution -->
        <div style="margin-bottom:14px">
          <div style="font-size:.73rem;color:var(--sub);font-weight:700;
                      text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">
            Entry Contribution
          </div>
          <?php if (!$signupPaid): ?>
            <a href="./payments.php?member_ref=<?= urlencode((string)$ref) ?>"
               class="btn-mr btn-mr-primary" style="display:block;text-align:center;margin-bottom:5px">
              → Receive Contribution
            </a>
            <form method="post">
              <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
              <input type="hidden" name="member_id" value="<?= $r['id'] ?>">
              <button type="submit" name="action" value="mark_paid"
                      onclick="return confirm('Mark entry payment as received for <?= h(addslashes($r['full_name'] ?? '')) ?>?')"
                      class="btn-mr btn-mr-ok" style="width:100%">
                ✓ Mark Entry Paid
              </button>
            </form>
          <?php else: ?>
            <a href="./payments.php?member_ref=<?= urlencode((string)$ref) ?>"
               class="btn-mr btn-mr-ghost" style="display:block;text-align:center">
              View Contributions →
            </a>
          <?php endif; ?>
        </div>

        <!-- Approvals -->
        <div style="margin-bottom:14px">
          <div style="font-size:.73rem;color:var(--sub);font-weight:700;
                      text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">
            Reservations
          </div>
          <a href="./approvals.php" class="btn-mr btn-mr-ghost" style="display:block;text-align:center">
            Open Approvals →
          </a>
        </div>

        <!-- Notifications -->
        <div style="margin-bottom:14px">
          <div style="font-size:.73rem;color:var(--sub);font-weight:700;
                      text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">
            Notifications
          </div>
          <div style="display:grid;gap:5px">
            <form method="post">
              <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
              <input type="hidden" name="member_id" value="<?= $r['id'] ?>">
              <button type="submit" name="action" value="resend_thankyou"
                      class="btn-mr btn-mr-ghost" style="width:100%">Resend Welcome Email</button>
            </form>
            <form method="post">
              <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
              <input type="hidden" name="member_id" value="<?= $r['id'] ?>">
              <button type="submit" name="action" value="resend_admin_notice"
                      class="btn-mr btn-mr-ghost" style="width:100%">Resend Admin Notice</button>
            </form>
          </div>
          <div style="font-size:.7rem;color:var(--dim);margin-top:5px">→ <?= h($adminRecipient) ?></div>
        </div>

        <!-- Account control -->
        <div>
          <div style="font-size:.73rem;color:var(--sub);font-weight:700;
                      text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">
            Account Control
          </div>
          <div style="display:grid;gap:5px">
            <?php if ($isLocked): ?>
              <form method="post">
                <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
                <input type="hidden" name="member_id" value="<?= $r['id'] ?>">
                <button type="submit" name="action" value="unlock"
                        class="btn-mr btn-mr-ok" style="width:100%">🔓 Unlock Wallet</button>
              </form>
            <?php else: ?>
              <form method="post">
                <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
                <input type="hidden" name="member_id" value="<?= $r['id'] ?>">
                <button type="submit" name="action" value="lock"
                        class="btn-mr btn-mr-ghost" style="width:100%">🔒 Lock Wallet</button>
              </form>
            <?php endif; ?>
            <?php if ($isActive): ?>
              <form method="post">
                <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
                <input type="hidden" name="member_id" value="<?= $r['id'] ?>">
                <button type="submit" name="action" value="deactivate"
                        onclick="return confirm('Deactivate <?= h(addslashes($r['full_name'] ?? '')) ?>?')"
                        class="btn-mr btn-mr-err" style="width:100%">Deactivate</button>
              </form>
            <?php else: ?>
              <form method="post">
                <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
                <input type="hidden" name="member_id" value="<?= $r['id'] ?>">
                <button type="submit" name="action" value="activate"
                        class="btn-mr btn-mr-ok" style="width:100%">Activate</button>
              </form>
            <?php endif; ?>
          </div>
        </div>

      </div>
    </div><!-- /mc-grid -->
  </div><!-- /mc-body -->
</div><!-- /member-card -->
<?php endforeach; ?>

<?php endif; /* end summary/list */ ?>

</main>
</div>

<script>
function toggleRsnft(id) {
  var row = document.getElementById(id);
  if (!row) return;
  var open = row.style.display !== 'none';
  row.style.display = open ? 'none' : 'table-row';
  // toggle highlight on the trigger row
  var triggerRow = row.previousElementSibling;
  if (triggerRow) triggerRow.style.background = open ? '' : 'rgba(212,178,92,.04)';
}

function toggleMember(id) {
  var body = document.getElementById(id);
  var chev = document.getElementById(id + '-chev');
  if (!body) return;
  var open = body.classList.contains('open');
  body.classList.toggle('open', !open);
  if (chev) chev.textContent = open ? '▼' : '▲';
}

function gnafVerify(memberId, btn) {
  var resultEl = document.getElementById('gnaf-result-' + memberId);
  var orig = btn.textContent;
  btn.disabled = true; btn.textContent = 'Verifying…';
  if (resultEl) { resultEl.style.display = 'none'; resultEl.textContent = ''; }
  fetch('../_app/api/index.php/address-verify', {
    method: 'POST', credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'member', member_id: memberId })
  }).then(function(r){ return r.json(); }).then(function(data) {
    btn.disabled = false; btn.textContent = orig;
    if (!resultEl) return;
    resultEl.style.display = 'block';
    if (data.success) {
      var d = data.data;
      var zoneMsg = d.in_affected_zone ? '◈ Zone: ' + (d.zone_code || 'unknown') : 'Not in an Affected Zone';
      var color = d.status === 'verified' ? '#7ee0a0' : d.status === 'low_confidence' ? '#d4b25c' : '#ffb4be';
      resultEl.style.color = color;
      resultEl.innerHTML = 'Status: <strong>' + d.status + '</strong> · Confidence: ' + (d.confidence || 0).toFixed(0) + '% · ' + zoneMsg +
        (d.gnaf_address ? '<br>' + d.gnaf_address : '') +
        (d.requires_review ? '<br><span style="color:#ffb4be">⚠ Manual review required</span>' : '');
      if (d.status === 'verified' || d.in_affected_zone) setTimeout(function(){ window.location.reload(); }, 1500);
    } else {
      resultEl.style.color = '#ffb4be';
      resultEl.textContent = 'Error: ' + (data.error || 'Verification failed');
    }
  }).catch(function(err) {
    btn.disabled = false; btn.textContent = orig;
    if (resultEl) { resultEl.style.display = 'block'; resultEl.style.color = '#ffb4be'; resultEl.textContent = 'Request failed: ' + err.message; }
  });
}

function parcelVerify(memberId, btn) {
  var resultEl = document.getElementById('parcel-result-' + memberId);
  var orig = btn.textContent;
  btn.disabled = true; btn.textContent = 'Verifying…';
  if (resultEl) { resultEl.style.display = 'none'; resultEl.textContent = ''; }
  var holderType = document.getElementById('pf-type-'  + memberId)?.value  || 'freehold';
  var juris      = document.getElementById('pf-juris-' + memberId)?.value  || 'NSW';
  var lot        = (document.getElementById('pf-lot-'  + memberId)?.value  || '').trim();
  var plan       = (document.getElementById('pf-plan-' + memberId)?.value  || '').trim();
  var titleRef   = (document.getElementById('pf-title-'+ memberId)?.value  || '').trim();
  var parcelPid  = (document.getElementById('pf-pid-'  + memberId)?.value  || '').trim();
  if (!lot && !plan && !parcelPid) {
    btn.disabled = false; btn.textContent = orig;
    if (resultEl) { resultEl.style.display = 'block'; resultEl.style.color = '#ffb4be'; resultEl.textContent = 'Enter a lot number, plan number, or parcel PID to verify.'; }
    return;
  }
  fetch('../_app/api/index.php/parcel-verify', {
    method: 'POST', credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action:'member', member_id:memberId, holder_type:holderType, lot:lot, plan:plan, jurisdiction:juris, title_reference:titleRef, parcel_pid:parcelPid })
  }).then(function(r){ return r.json(); }).then(function(data) {
    btn.disabled = false; btn.textContent = orig;
    if (!resultEl) return;
    resultEl.style.display = 'block';
    if (data.success) {
      var d = data.data;
      var sc = d.status === 'cadastre_matched' || d.status === 'verified' ? '#7ee0a0' : d.status === 'fnac_pending' ? '#d4b25c' : '#ffb4be';
      var html = '<strong style="color:' + sc + '">' + d.status + '</strong>';
      if (d.area_hectares) html += ' · ' + parseFloat(d.area_hectares).toFixed(2) + ' ha';
      if (d.tokens_calculated) html += ' · <strong>' + d.tokens_calculated.toLocaleString() + '</strong> Lh tokens';
      if (d.zero_cost_eligible) html += ' · <span style="color:#d4b25c">Zero-cost (LALC/PBC)</span>';
      if (d.tenement_overlap) html += '<br>◈ Tenement zone: ' + (d.tenement_zone_code || 'matched');
      else html += '<br><span style="color:#ffb4be">⚠ No tenement adjacency</span>';
      if (d.fnac_routing_required) html += '<br><span style="color:#d4b25c">⚑ FNAC endorsement required</span>';
      if (d.nntt_overlap) html += '<br><span style="color:#d4b25c">◈ NNTT / Country overlay detected</span>';
      if (d.note) html += '<br><span style="color:var(--muted);font-size:11px">' + d.note + '</span>';
      if (d.mock_mode) html += '<br><span style="color:var(--muted);font-size:11px">⚙ Mock mode</span>';
      resultEl.innerHTML = html;
      if (d.status === 'cadastre_matched' || d.status === 'verified') setTimeout(function(){ window.location.reload(); }, 2000);
    } else {
      resultEl.style.color = '#ffb4be'; resultEl.textContent = 'Error: ' + (data.error || 'Verification failed');
    }
  }).catch(function(err) {
    btn.disabled = false; btn.textContent = orig;
    if (resultEl) { resultEl.style.display = 'block'; resultEl.style.color = '#ffb4be'; resultEl.textContent = 'Request failed: ' + err.message; }
  });
}
</script>
</body>
</html>
