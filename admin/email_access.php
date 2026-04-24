<?php
declare(strict_types=1);
// session_start() removed: ops_require_admin() -> ops_start_admin_php_session()
// sets the session name before starting, so calling session_start() here first
// would use PHP's default name and make the admin cookie invisible (login loop).
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';
require_once __DIR__ . '/../_app/api/config/bootstrap.php';
require_once __DIR__ . '/../_app/api/integrations/mailer.php';

ops_require_admin();
$pdo = ops_db();

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
if (!function_exists('entity_type')) {
function entity_type(array $m): string { return (($m['member_type'] ?? '') === 'business') ? 'bnft_business' : 'snft_member'; }
}
if (!function_exists('user_template')) {
function user_template(array $m): string { return (($m['member_type'] ?? '') === 'business') ? 'bnft_user_confirmation' : 'snft_user_confirmation'; }
}
if (!function_exists('admin_template')) {
function admin_template(array $m): string { return (($m['member_type'] ?? '') === 'business') ? 'bnft_admin_alert' : 'snft_admin_alert'; }
}
if (!function_exists('user_subject')) {
function user_subject(array $m): string { return 'Welcome to COG$ — your COG$ registration is confirmed'; }
}
if (!function_exists('admin_subject')) {
function admin_subject(array $m): string { return (($m['member_type'] ?? '') === 'business') ? 'New COG\$ Business Partner — BNFT registration recorded' : 'New COG\$ Partner — SNFT registration recorded'; }
}
if (!function_exists('payload_for')) {
function payload_for(array $m): array {
    return [
        'full_name' => (string)($m['full_name'] ?? ''),
        'email' => (string)($m['email'] ?? ''),
        'member_number' => (string)($m['member_number'] ?? ''),
        'abn' => (string)($m['abn'] ?? ''),
        'member_type' => (string)($m['member_type'] ?? ''),
        'wallet_path' => (($m['member_type'] ?? '') === 'business') ? 'wallets/business.html' : 'wallets/member.html',
    ];
}
}
if (!function_exists('queue_now')) {
function queue_now(PDO $pdo, array $m, string $recipient, string $template, string $subject): int {
    $entityId = (int)($m['id'] ?? 0);
    $queueId = queueEmail($pdo, entity_type($m), $entityId, $recipient, $template, $subject, payload_for($m), true);
    processEmailQueue($pdo, 25);
    return $queueId;
}
}
if (!function_exists('queue_status_row')) {
function queue_status_row(PDO $pdo, int $queueId): ?array {
    return one($pdo, "SELECT id, recipient, template_key, subject, status, attempt_count, last_error, created_at, sent_at FROM email_queue WHERE id=? LIMIT 1", [$queueId]);
}
}
if (!function_exists('log_email_event')) {
function log_email_event(PDO $pdo, int $memberId, ?int $adminId, string $eventType, string $recipient, string $subject, string $note): void {
    if (function_exists('ops_has_table') && ops_has_table($pdo, 'email_access_log')) {
        $pdo->prepare("INSERT INTO email_access_log (member_id, admin_id, event_type, recipient_email, subject_line, event_details, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())")
            ->execute([$memberId ?: null, $adminId, $eventType, $recipient, $subject, $note]);
    }
    if (function_exists('ops_has_table') && ops_has_table($pdo, 'email_access_events')) {
        $pdo->prepare("INSERT INTO email_access_events (member_id, event_type, event_status, recipient, subject_line, notes, created_by_admin_id, created_at) VALUES (?, ?, 'sent', ?, ?, ?, ?, NOW())")
            ->execute([$memberId ?: null, $eventType, $recipient, $subject, $note, $adminId]);
    }
}
}

$flash = null; $error = null; $lastActionSummary = null;
$adminId = function_exists('ops_legacy_admin_write_id') ? ops_legacy_admin_write_id($pdo) : (function_exists('ops_admin_id') ? ops_admin_id() : null);
$adminRecipient = trim((string)MAIL_ADMIN_EMAIL);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'send_test_email') {
            $recipient = trim((string)($_POST['recipient_email'] ?? $adminRecipient));
            if ($recipient === '') throw new RuntimeException('Recipient email is required.');
            $dummy = ['id'=>0,'full_name'=>'Admin Test Recipient','email'=>$recipient,'member_number'=>'0000000000000000','abn'=>'','member_type'=>'personal'];
            $queueId = queue_now($pdo, $dummy, $recipient, 'snft_admin_alert', 'COG$ admin test email');
            $lastActionSummary = queue_status_row($pdo, $queueId);
            $flash = 'Admin test email created a fresh queue entry and processed immediately.';
        } elseif ($action === 'process_queue_now') {
            $result = processEmailQueue($pdo, 50);
            $flash = 'Queue processed: ' . (int)($result['processed'] ?? 0) . '.';
        } elseif ($action === 'resend_all_outstanding') {
            $members = rows($pdo, "SELECT * FROM members WHERE last_access_email_sent_at IS NULL AND is_active=1 ORDER BY id DESC");
            $count=0; $latestQueueId = 0;
            foreach ($members as $m2) {
                if (trim((string)($m2['email'] ?? '')) === '') continue;
                $latestQueueId = queue_now($pdo, $m2, (string)$m2['email'], user_template($m2), user_subject($m2));
                log_email_event($pdo, (int)$m2['id'], $adminId, 'resend_thankyou', (string)$m2['email'], user_subject($m2), 'Bulk resend thank-you from Email Access');
                if (!empty($_POST['send_admin_copy']) && $adminRecipient !== '') {
                    $latestQueueId = queue_now($pdo, $m2, $adminRecipient, admin_template($m2), admin_subject($m2));
                    log_email_event($pdo, (int)$m2['id'], $adminId, 'resend_admin_notice', $adminRecipient, admin_subject($m2), 'Bulk resend admin notice from Email Access');
                }
                $pdo->prepare("UPDATE members SET last_access_email_sent_at=NOW(), updated_at=NOW() WHERE id=?")->execute([(int)$m2['id']]);
                $count++;
            }
            $lastActionSummary = $latestQueueId ? queue_status_row($pdo, $latestQueueId) : null;
            $flash = 'Processed ' . $count . ' outstanding member email bundle(s).';
        } else {
            $memberId = (int)($_POST['member_id'] ?? 0);
            if ($memberId <= 0) throw new RuntimeException('Member missing.');
            $m = one($pdo, "SELECT * FROM members WHERE id=? LIMIT 1", [$memberId]);
            if (!$m) throw new RuntimeException('Member not found.');
            if ($action === 'send_member_email') {
                if (trim((string)($m['email'] ?? '')) === '') throw new RuntimeException('Member email missing.');
                $queueId = queue_now($pdo, $m, (string)$m['email'], user_template($m), user_subject($m));
                $pdo->prepare("UPDATE members SET last_access_email_sent_at=NOW(), updated_at=NOW() WHERE id=?")->execute([$memberId]);
                log_email_event($pdo, $memberId, $adminId, 'resend_thankyou', (string)$m['email'], user_subject($m), 'Manual thank-you resend from Email Access');
                $lastActionSummary = queue_status_row($pdo, $queueId);
                $flash = 'Member thank-you email sent.';
            } elseif ($action === 'send_admin_email') {
                if ($adminRecipient === '') throw new RuntimeException('MAIL_ADMIN_EMAIL is empty.');
                $queueId = queue_now($pdo, $m, $adminRecipient, admin_template($m), admin_subject($m));
                log_email_event($pdo, $memberId, $adminId, 'resend_admin_notice', $adminRecipient, admin_subject($m), 'Manual admin resend from Email Access');
                $lastActionSummary = queue_status_row($pdo, $queueId);
                $flash = 'Admin notice sent.';
            } elseif ($action === 'send_both') {
                if (trim((string)($m['email'] ?? '')) === '') throw new RuntimeException('Member email missing.');
                $queueId = queue_now($pdo, $m, (string)$m['email'], user_template($m), user_subject($m));
                log_email_event($pdo, $memberId, $adminId, 'resend_thankyou', (string)$m['email'], user_subject($m), 'Manual thank-you resend from Email Access');
                if ($adminRecipient !== '') {
                    $queueId = queue_now($pdo, $m, $adminRecipient, admin_template($m), admin_subject($m));
                    log_email_event($pdo, $memberId, $adminId, 'resend_admin_notice', $adminRecipient, admin_subject($m), 'Manual admin resend from Email Access');
                }
                $pdo->prepare("UPDATE members SET last_access_email_sent_at=NOW(), updated_at=NOW() WHERE id=?")->execute([$memberId]);
                $lastActionSummary = queue_status_row($pdo, $queueId);
                $flash = 'Member thank-you email and admin notice sent.';
            }
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$outstanding = rows($pdo, "SELECT id, full_name, member_type, member_number, abn, email, wallet_status, signup_payment_status, last_access_email_sent_at FROM members WHERE last_access_email_sent_at IS NULL AND is_active=1 ORDER BY id DESC LIMIT 100");
$queueRows = function_exists('ops_has_table') && ops_has_table($pdo,'email_queue')
    ? rows($pdo, "SELECT id, recipient, template_key, subject, status, attempt_count, last_error, created_at, sent_at FROM email_queue ORDER BY id DESC LIMIT 20")
    : [];
$helpCards = [
    ['key'=>'resend_all','title'=>'Resend all outstanding','body'=>'Sends a new thank-you email to every active member who still has no last_access_email_sent_at value. If the admin-copy box is checked, it also sends the admin notice to MAIL_ADMIN_EMAIL.'],
    ['key'=>'test_email','title'=>'Send admin test email','body'=>'Creates a fresh queue row to the test recipient using the admin alert template, then processes the queue immediately so you can verify SMTP delivery and recent queue updates.'],
    ['key'=>'process_queue','title'=>'Process queue now','body'=>'Runs the queue worker against any pending or failed rows already in email_queue. Use this if a row exists but has not yet moved to sent or failed.'],
    ['key'=>'send_thankyou','title'=>'Send thank you','body'=>'Creates a fresh member email row using the user confirmation template for that member type, updates the sent timestamp, and logs the action.'],
    ['key'=>'send_admin','title'=>'Send admin email','body'=>'Creates a fresh admin notice row to MAIL_ADMIN_EMAIL using the matching admin alert template for that member type.'],
    ['key'=>'send_both','title'=>'Send both','body'=>'Runs both actions together: member thank-you to the member email and admin notice to MAIL_ADMIN_EMAIL.'],
];
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Email Access — Admin</title>
<style>:root{--bg:#0f1720;--panel:#17212b;--panel2:#1f2c38;--text:#eef2f7;--muted:#9fb0c1;--line:rgba(255,255,255,.08);--ok:#b8efc8;--bad:#ffb4be;--gold:#d4b25c}*{box-sizing:border-box}body{margin:0;font-family:Inter,Arial,sans-serif;background:linear-gradient(180deg,#0c1319,#121d27 24%,#0f1720);color:var(--text)}.shell{display:grid;grid-template-columns:260px minmax(0,1fr);min-height:100vh}.main{padding:18px 20px;min-width:0}.card{background:linear-gradient(180deg,var(--panel),var(--panel2));border:1px solid var(--line);border-radius:20px;padding:16px;margin-bottom:16px;min-width:0}.grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(320px,.85fr);gap:16px}.subgrid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.table-wrap{overflow:auto;max-width:100%}table{width:100%;border-collapse:collapse;font-size:14px}th,td{padding:8px 6px;border-bottom:1px dashed rgba(255,255,255,.08);text-align:left;vertical-align:top}th{color:var(--muted);font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.03em}input,button{width:100%;background:var(--panel2);border:1px solid var(--line);color:var(--text);padding:.85rem .95rem;border-radius:12px;font:inherit}button{background:#d4b25c;color:#201507;font-weight:800;cursor:pointer}.msg{padding:12px 14px;border-radius:14px;margin-bottom:12px}.ok{background:rgba(47,143,87,.12);color:var(--ok);border:1px solid rgba(47,143,87,.35)}.err{background:rgba(200,61,75,.12);color:var(--bad);border:1px solid rgba(200,61,75,.35)}.muted{color:var(--muted)}.inline{display:flex;gap:8px;align-items:center}.inline input[type=checkbox]{width:auto}.tight td,.tight th{padding:7px 5px;font-size:13px}.heading-line{display:flex;justify-content:space-between;align-items:center;gap:8px}.help-btn{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:999px;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.04);color:#fff;font-size:12px;font-weight:700;cursor:pointer}.action-stack{display:grid;gap:8px}.summary-box{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:12px}.modal{position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;padding:18px;z-index:50}.modal.open{display:flex}.modal-panel{max-width:560px;width:100%;background:linear-gradient(180deg,#16212b,#1d2b39);border:1px solid rgba(255,255,255,.1);border-radius:18px;padding:18px}.modal-panel h3{margin:0 0 10px}.modal-actions{display:flex;justify-content:flex-end;margin-top:14px}@media(max-width:1100px){.grid{grid-template-columns:1fr}}@media(max-width:900px){.shell{grid-template-columns:1fr}.main{padding:14px}.subgrid{grid-template-columns:1fr}}</style></head><body><div class="shell"><?php admin_sidebar_render('email_access'); ?><main class="main">
<div class="card"><div class="muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px">Email operations</div><h1 style="margin:0 0 8px">Email Access</h1><p class="muted" style="margin:0">Fully executable queue-backed email tools. Admin notices go to <strong><?=h($adminRecipient)?></strong>. Sender/from remains <strong><?=h(MAIL_FROM_EMAIL)?></strong>. Reply-to remains <strong><?=h(MAIL_REPLY_TO)?></strong>.</p></div>
<?php ops_admin_help_assets_once(); ?>
<?= ops_admin_collapsible_help('Page guide & workflow', [
  ops_admin_info_panel(
    'Email operations',
    'What this page does',
    'Email Access is the queue-backed outbound email operations page. Use it to send a test email, process the queue, resend outstanding access emails, or send the thank-you and admin notice bundle for a specific member record.',
    [
      'Use this page for delivery operations, not template editing.',
      'Use Communications \u2192 Email Templates when you need to change email content.',
      'Use the recent queue table to confirm whether an email was sent, failed, or is still pending.',
    ]
  ),
  ops_admin_workflow_panel(
    'Typical workflow',
    'Work through sending actions in order so delivery and content issues stay separate.',
    [
      ['title' => 'Check content and recipient', 'body' => 'Confirm the correct template exists and the recipient email is present before sending.'],
      ['title' => 'Choose the right action', 'body' => 'Use test email for SMTP checks, resend-all for outstanding access records, or member-specific actions for one record only.'],
      ['title' => 'Process and verify', 'body' => 'After sending, check Latest queue result and Recent queue for status, attempts, and any error detail.'],
      ['title' => 'Escalate content issues separately', 'body' => 'If wording is wrong, fix the template in Communications rather than repeatedly resending from this page.'],
    ]
  ),
  ops_admin_guide_panel(
    'How to read this page',
    'Each section covers a different part of the email delivery surface.',
    [
      ['title' => 'Actions', 'body' => 'The live operational tools for queue processing and resend actions.'],
      ['title' => 'Outstanding members', 'body' => 'Records that still have no last access email timestamp and may need sending or cleanup.'],
      ['title' => 'Latest queue result', 'body' => 'The most recent queue-backed action you performed on this page.'],
      ['title' => 'Recent queue', 'body' => 'A delivery trace table showing recipient, template, attempts, status, and any last error.'],
    ]
  ),
]) ?>
<?php if($flash): ?><div class="msg ok"><?=h($flash)?></div><?php endif; ?>
<?php if($error): ?><div class="msg err"><?=h($error)?></div><?php endif; ?>
<?php if($lastActionSummary): ?><div class="card summary-box"><div class="heading-line"><strong>Latest queue result</strong></div><div class="table-wrap"><table class="tight"><thead><tr><th>Queue ID</th><th>Recipient</th><th>Template</th><th>Status</th><th>Created</th></tr></thead><tbody><tr><td><?= (int)$lastActionSummary['id'] ?></td><td><?=h($lastActionSummary['recipient'])?></td><td><?=h($lastActionSummary['template_key'])?><div class="muted"><?=h($lastActionSummary['subject'])?></div></td><td><?=h($lastActionSummary['status'])?><?php if(!empty($lastActionSummary['last_error'])): ?><div class="muted"><?=h($lastActionSummary['last_error'])?></div><?php endif; ?></td><td><?=h((string)$lastActionSummary['created_at'])?></td></tr></tbody></table></div></div><?php endif; ?>
<div class="grid"><section>
<div class="card"><div class="heading-line"><div><strong>Actions</strong></div></div><div class="subgrid">
<form method="post">
      <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>"><div class="heading-line"><label style="margin:0">Resend all outstanding</label><button type="button" class="help-btn" data-help="resend_all">?</button></div><input type="hidden" name="action" value="resend_all_outstanding"><div class="inline"><input type="checkbox" id="send-admin-copy" name="send_admin_copy" value="1" checked><label for="send-admin-copy">Also send admin copy to <?=h($adminRecipient)?></label></div><div style="height:12px"></div><button type="submit">Resend all outstanding</button></form>
<form method="post">
      <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>"><div class="heading-line"><label style="margin:0">Send admin test email</label><button type="button" class="help-btn" data-help="test_email">?</button></div><input type="hidden" name="action" value="send_test_email"><input name="recipient_email" value="<?=h($adminRecipient)?>"><div style="height:12px"></div><button type="submit">Send admin test email</button></form>
</div><form method="post" style="margin-top:12px">
      <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>"><div class="heading-line"><label style="margin:0">Process queue now</label><button type="button" class="help-btn" data-help="process_queue">?</button></div><input type="hidden" name="action" value="process_queue_now"><div style="height:12px"></div><button type="submit">Process queue now</button></form></div>
<div class="card"><div class="heading-line"><strong>Outstanding members</strong></div><div class="table-wrap"><table class="tight"><thead><tr><th>Member</th><th>Status</th><th>Email</th><th>Action</th></tr></thead>
<?php if($outstanding): ?>
<tr><td colspan="4" style="font-size:12px;color:var(--muted);padding:6px 6px 2px">⚠ Members with no email address are skipped by &ldquo;Resend all outstanding&rdquo; and will remain in this list permanently until an email is added to their record.</td></tr>
<?php endif; ?><tbody>
<?php if(!$outstanding): ?><tr><td colspan="4">No outstanding email records.</td></tr><?php else: foreach($outstanding as $m): ?><tr><td><strong><?=h($m['full_name'])?></strong><div class="muted"><?=h(member_ref($m))?></div></td><td><?=h($m['wallet_status'])?><div class="muted"><?=h($m['signup_payment_status'])?></div></td><td><?=h($m['email'])?></td><td><div class="action-stack">
<form method="post">
      <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>"><div class="heading-line"><span>Send thank you</span><button type="button" class="help-btn" data-help="send_thankyou">?</button></div><input type="hidden" name="action" value="send_member_email"><input type="hidden" name="member_id" value="<?=$m['id']?>"><div style="height:8px"></div><button type="submit">Send thank you</button></form>
<form method="post">
      <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>"><div class="heading-line"><span>Send admin email</span><button type="button" class="help-btn" data-help="send_admin">?</button></div><input type="hidden" name="action" value="send_admin_email"><input type="hidden" name="member_id" value="<?=$m['id']?>"><div style="height:8px"></div><button type="submit">Send admin email</button></form>
<form method="post">
      <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>"><div class="heading-line"><span>Send both</span><button type="button" class="help-btn" data-help="send_both">?</button></div><input type="hidden" name="action" value="send_both"><input type="hidden" name="member_id" value="<?=$m['id']?>"><div style="height:8px"></div><button type="submit">Send both</button></form>
</div></td></tr><?php endforeach; endif; ?>
</tbody></table></div></div></section>
<aside><div class="card"><div class="heading-line"><strong>Recent queue</strong></div><div class="table-wrap"><table class="tight"><thead><tr><th>ID</th><th>Recipient</th><th>Template</th><th>Status</th><th>Attempts</th></tr></thead><tbody><?php if(!$queueRows): ?><tr><td colspan="5">No queue rows found.</td></tr><?php else: foreach($queueRows as $q): ?><tr><td><?= (int)$q['id'] ?></td><td><?=h($q['recipient'])?><div class="muted"><?=h((string)$q['created_at'])?></div></td><td><?=h($q['template_key'])?><div class="muted"><?=h($q['subject'])?></div></td><td><?=h($q['status'])?><?php if(!empty($q['last_error'])): ?><div class="muted"><?=h($q['last_error'])?></div><?php endif; ?></td><td><?= (int)$q['attempt_count'] ?></td></tr><?php endforeach; endif; ?></tbody></table></div></div></aside>
</div></main></div>
<div id="help-modal" class="modal" aria-hidden="true"><div class="modal-panel"><h3 id="help-title">Help</h3><div id="help-body" class="muted"></div><div class="modal-actions"><button type="button" id="help-close">Close</button></div></div></div>
<script>const helpData = <?= json_encode($helpCards, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>.reduce((a,c)=>{a[c.key]=c;return a;},{});const modal=document.getElementById('help-modal');const titleEl=document.getElementById('help-title');const bodyEl=document.getElementById('help-body');document.querySelectorAll('[data-help]').forEach(btn=>btn.addEventListener('click',()=>{const item=helpData[btn.dataset.help];if(!item)return;titleEl.textContent=item.title;bodyEl.textContent=item.body;modal.classList.add('open');modal.setAttribute('aria-hidden','false');}));document.getElementById('help-close').addEventListener('click',()=>{modal.classList.remove('open');modal.setAttribute('aria-hidden','true');});modal.addEventListener('click',e=>{if(e.target===modal){modal.classList.remove('open');modal.setAttribute('aria-hidden','true');}});</script>
</body></html>