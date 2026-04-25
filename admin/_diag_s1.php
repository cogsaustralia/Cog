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
?>