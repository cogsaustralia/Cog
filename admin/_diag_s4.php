<?php
<?php admin_sidebar_render('trustee_decisions'); ?>
<div class="main">

<?php if ($message): ?>
  <div class="msg-ok"><?= $message ?></div>
<?php endif; ?>
<?php if ($smtpFallbackLink): ?>
  <div style="background:var(--warnb);border:1px solid rgba(212,148,74,.4);border-radius:8px;padding:14px 18px;margin-bottom:14px">
    <div style="font-size:.75rem;color:var(--warn);font-weight:700;margin-bottom:8px;text-transform:uppercase;letter-spacing:.06em">
      ⚠ Execution Link — Send to Trustee
    </div>
    <div style="font-size:.78rem;color:var(--sub);margin-bottom:8px">
      Copy this link and send it to the trustee email address manually. The link is single-use and expires in 15 minutes.
    </div>
    <div style="background:var(--panel);border:1px solid var(--line);border-radius:6px;padding:10px 12px;font-family:monospace;font-size:.78rem;color:var(--gold);word-break:break-all;user-select:all">
      <?= td_h($smtpFallbackLink) ?>
    </div>
    <button onclick="navigator.clipboard.writeText(<?= json_encode($smtpFallbackLink) ?>).then(()=>{this.textContent='Copied ✓';setTimeout(()=>{this.textContent='Copy Link'},2000)})"
            style="margin-top:10px;padding:5px 14px;background:rgba(212,178,92,.2);border:1px solid rgba(212,178,92,.4);color:var(--gold);border-radius:6px;font-size:.78rem;font-weight:700;cursor:pointer">
      Copy Link
    </button>
  </div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="msg-err"><?= td_h($error) ?></div>
<?php endif; ?>

<?php if ($action === 'create'): ?>
<!-- ════════════════════════════════════════════════════════ CREATE FORM -->
<div class="topbar" style="margin-bottom:20px">
  <h2>🧾 New Trustee Decision Record</h2>
  <p>Create a draft TDR. The record remains in draft until you issue an execution token.</p>
</div>
<form method="POST">
  <input type="hidden" name="_action" value="create_draft">

?>