<?php
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
?>