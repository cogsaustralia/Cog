<?php
.detail-card {
  background: var(--panel2); border: 1px solid var(--line2);
  border-radius: 10px; padding: 0; margin-bottom: 18px; overflow: hidden;
}
.detail-head {
  display: flex; justify-content: space-between; align-items: center;
  padding: 14px 20px; border-bottom: 1px solid var(--line);
  flex-wrap: wrap; gap: 8px;
}
.detail-head h3 { font-size: .9rem; font-weight: 700; margin: 0; }
.detail-body { padding: 18px 20px; }
.dg { display: grid; grid-template-columns: 200px 1fr; gap: 6px 14px; font-size: .82rem; margin-bottom: 14px; }
.dg-l { color: var(--dim); }
.dg-v { color: var(--text); word-break: break-all; }
.dg-v.mono { font-family: monospace; font-size: .78rem; }
.dg-v.gold { color: var(--gold); }
.dg-v.ok   { color: var(--ok); }

.section-title {
  font-size: .7rem; letter-spacing: .1em; text-transform: uppercase;
  color: var(--gold); font-weight: 700; margin: 16px 0 8px;
}
.md-preview {
  background: var(--panel); border: 1px solid var(--line2); border-radius: 6px;
  padding: 10px 14px; font-size: .83rem; color: var(--text);
  white-space: pre-wrap; word-break: break-word; max-height: 200px; overflow-y: auto;
}
.exec-row {
  background: var(--panel); border: 1px solid var(--line2); border-radius: 6px;
  padding: 12px 14px; margin-bottom: 10px; font-size: .8rem;
}
.msg-ok  { background: var(--okb);   border: 1px solid rgba(82,184,122,.3);  color: var(--ok);   border-radius: 7px; padding: 10px 14px; font-size: .83rem; margin-bottom: 14px; }
.msg-err { background: var(--errb);  border: 1px solid rgba(192,85,58,.3);   color: var(--err);  border-radius: 7px; padding: 10px 14px; font-size: .83rem; margin-bottom: 14px; }

/* Create form */
.form-card {
  background: var(--panel2); border: 1px solid var(--line2); border-radius: 10px;
  padding: 22px 24px; margin-bottom: 18px;
}
.form-card h3 { font-size: .88rem; font-weight: 700; margin: 0 0 16px; color: var(--gold); }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; font-size: .78rem; color: var(--sub); margin-bottom: 5px; }
.form-group input, .form-group select, .form-group textarea {
  width: 100%; box-sizing: border-box;
  background: var(--input); border: 1px solid var(--line2); border-radius: 6px;
  color: var(--text); font-size: .83rem; padding: 7px 10px;
}
.form-group textarea { min-height: 90px; font-family: monospace; resize: vertical; }
.form-group.check { display: flex; align-items: center; gap: 8px; }
.form-group.check input { width: auto; }
.powers-row { display: grid; grid-template-columns: 200px 1fr 32px; gap: 8px; margin-bottom: 8px; align-items: start; }
.powers-row input { width: 100%; box-sizing: border-box; }
.remove-power { background: var(--errb); border: 1px solid rgba(192,85,58,.4); color: var(--err); border-radius: 5px; cursor: pointer; font-size: .8rem; padding: 4px 8px; }
.add-power { font-size: .78rem; color: var(--gold); background: none; border: 1px dashed rgba(212,178,92,.4); border-radius: 5px; padding: 5px 12px; cursor: pointer; }
.required { color: var(--err); }
.divider { border: none; border-top: 1px solid var(--line); margin: 18px 0; }
/* ── Certificate / print styles ── */
.cert-wrap {
  display: none; max-width: 780px; margin: 0 auto; padding: 40px 32px 60px;
  font-family: system-ui, sans-serif; color: #1a1a1a; background: #ffffff;
  position: relative; z-index: 10;
}
.cert-wrap.active { display: block; background: #ffffff; color: #1a1a1a; }
body.cert-open .admin-shell { display: none; }
@media print {
  .admin-shell, .main, .no-print, .cert-actions { display: none !important; }
  .cert-wrap { display: none !important; }
  .cert-wrap.active { display: block !important; padding: 0; }
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
.cert-val { font-size: .82rem; color: #1a1a1a; word-break: break-all; overflow-wrap: anywhere; }
.cert-val.mono { font-family: 'Courier New', monospace; }
.cert-val.highlight { color: #1a1a2e; font-weight: 600; }
.cert-resolution {
  background: #f8f7f4; border-left: 3px solid #8b6914; border-radius: 0 4px 4px 0;
  padding: 12px 16px; font-size: .82rem; color: #333; line-height: 1.6;
  white-space: pre-wrap; word-break: break-word; margin: 8px 0 0;
}
.cert-notice {
  background: #f0ede8; border: 1px solid #d4b25c; border-radius: 6px;
  padding: 14px 18px; margin-top: 24px; font-size: .8rem; color: #555; line-height: 1.6;
}
.cert-footer { text-align: center; margin-top: 32px; font-size: .72rem; color: #999; }
.print-btn {
  margin: 16px 8px 0 0; padding: 9px 18px; border-radius: 7px;
  background: transparent; border: 1px solid var(--line2); color: var(--sub);
  font-size: .82rem; cursor: pointer;
}
.print-btn:hover { border-color: rgba(212,178,92,.4); color: var(--gold); }
</style>
</head>
<body>
<div class="admin-shell">
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

  <div class="form-card">
?>