<?php
];
usort($decisions, function($a, $b) use ($tdrPriority) {
    $pa = $tdrPriority[$a['decision_ref']] ?? 99;
    $pb = $tdrPriority[$b['decision_ref']] ?? 99;
    return $pa !== $pb ? $pa - $pb : strcmp($a['decision_ref'], $b['decision_ref']);
});
$filterStatus = trim((string)($_GET['status'] ?? ''));
?>

<div class="topbar" style="margin-bottom:16px">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
    <div>
      <h2>🧾 Trustee Decision Records</h2>
      <p>All TDRs of the CJVM Hybrid Trust, ordered by execution priority. Click any row to expand.</p>
    </div>
    <a href="./trustee_decisions.php?action=create" class="btn-primary">+ New TDR</a>
  </div>
</div>

<div style="background:rgba(82,184,122,.08);border:1px solid rgba(82,184,122,.25);border-radius:8px;
            padding:10px 16px;font-size:.78rem;color:var(--ok);margin-bottom:14px">
  <strong>✓ Hybrid Trust Non-MIS position covered</strong> —
  TDR-20260425-005 (executed) records the Non-MIS self-assessment for the CJVM Hybrid Trust
  under JVPA cl.4.9, Declaration cl.1.1A, and Sub-Trust A cl.10.4. This applies to the
  Hybrid Trust as a whole including Sub-Trusts B and C by operation of the instrument
  hierarchy. Per-sub-trust duplicate Non-MIS drafts have been removed.
</div>

<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:18px;align-items:center">
  <span style="font-size:.73rem;color:var(--dim);margin-right:4px">Show:</span>
  <?php foreach ([''=>'All','draft'=>'Draft','pending_execution'=>'Pending Execution','fully_executed'=>'Executed'] as $val=>$lbl):
    $isActive = ($filterStatus === $val); ?>
    <a href="./trustee_decisions.php<?= $val ? '?status='.urlencode($val) : '' ?>"
       style="font-size:.73rem;font-weight:<?= $isActive?'700':'400' ?>;padding:4px 11px;
              border-radius:20px;text-decoration:none;
              background:<?= $isActive?'rgba(212,178,92,.25)':'var(--panel2)' ?>;
              border:1px solid <?= $isActive?'rgba(212,178,92,.5)':'var(--line2)' ?>;
              color:<?= $isActive?'var(--gold)':'var(--sub)' ?>"><?= td_h($lbl) ?></a>
  <?php endforeach; ?>
  <span style="font-size:.72rem;color:var(--dim);margin-left:8px">
    <?= count($decisions) ?> record<?= count($decisions)!==1?'s':'' ?>
  </span>
</div>

<?php if (empty($decisions)): ?>
  <div style="text-align:center;padding:48px 0;color:var(--sub);font-size:.85rem">
    No Trustee Decision Records found.
  </div>
<?php else: ?>

<?php
$pendingCount = count(array_filter($decisions, fn($d) => $d['status'] !== 'fully_executed'));
$doneCount    = count($decisions) - $pendingCount;
?>
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;align-items:center">
  <span style="font-size:.73rem;color:var(--dim);margin-right:4px">Show:</span>
  <?php foreach ([''=>'All','draft'=>'Draft','pending_execution'=>'Pending Execution','fully_executed'=>'Executed'] as $val=>$lbl):
    $isActive = ($filterStatus === $val); ?>
    <a href="./trustee_decisions.php<?= $val ? '?status='.urlencode($val) : '' ?>"
       style="font-size:.73rem;font-weight:<?= $isActive?'700':'400' ?>;padding:4px 11px;
              border-radius:20px;text-decoration:none;
              background:<?= $isActive?'rgba(212,178,92,.25)':'var(--panel2)' ?>;
              border:1px solid <?= $isActive?'rgba(212,178,92,.5)':'var(--line2)' ?>;
              color:<?= $isActive?'var(--gold)':'var(--sub)' ?>"><?= td_h($lbl) ?></a>
  <?php endforeach; ?>
  <span style="font-size:.72rem;color:var(--dim);margin-left:8px">
    <?= $pendingCount ?> pending &nbsp;·&nbsp; <?= $doneCount ?> executed &nbsp;·&nbsp; <?= count($decisions) ?> total
  </span>
</div>

<?php
$lastTier = null;
foreach ($decisions as $d):
  [$bc,$bl] = $statusBadge[$d['status']] ?? ['badge-warn',$d['status']];
  $pri  = $tdrPriority[$d['decision_ref']] ?? 99;
  $tier = null;
  foreach ($tierDefs as $td) { if ($pri>=$td['min']&&$pri<=$td['max']) { $tier=$td; break; } }
  if ($tier && ($lastTier===null||$lastTier['label']!==$tier['label'])):
    $lastTier=$tier;
?>
  <div style="font-size:.68rem;letter-spacing:.09em;text-transform:uppercase;font-weight:700;
              color:var(--sub);padding:10px 0 4px;display:flex;align-items:center;gap:8px">
    <span style="display:inline-block;width:7px;height:7px;border-radius:50%;
                 background:<?= $pri<=7?'rgba(192,85,58,.7)':($pri<=13?'rgba(212,148,74,.7)':'rgba(82,184,122,.6)') ?>"></span>
    <?= td_h($tier['label']) ?>
  </div>
<?php endif;
  $rowId  = 'row-'.preg_replace('/[^a-z0-9]/','-',strtolower($d['decision_ref']));
  $isOpen = ($d['status']==='pending_execution');
  $isDone = ($d['status']==='fully_executed');
  $icon   = $isDone ? '✓' : ($isOpen ? '◉' : '○');
  $iconC  = $isDone ? 'var(--ok)' : ($isOpen ? 'var(--warn)' : 'var(--dim)');
  $ctxBadge = $subTrustLabels[$d['sub_trust_context']] ?? $d['sub_trust_context'];
?>
<div style="border:1px solid var(--line2);border-radius:8px;margin-bottom:5px;overflow:hidden;
            <?= $isDone?'opacity:.65':'' ?>">
  <div onclick="toggleRow('<?= $rowId ?>')" id="<?= $rowId ?>-hdr"
       style="display:grid;grid-template-columns:18px 92px 1fr auto auto auto;gap:0 12px;
              align-items:center;padding:9px 14px;cursor:pointer;user-select:none;
              background:<?= $isOpen?'var(--panel2)':'var(--panel)' ?>">
    <span style="color:<?= $iconC ?>;font-size:.82rem;text-align:center"><?= $icon ?></span>
    <span style="font-family:monospace;font-size:.74rem;color:var(--gold);font-weight:700;white-space:nowrap">
      <?= td_h($d['decision_ref']) ?></span>
    <span style="font-size:.8rem;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
      <?= td_h($d['title']) ?></span>
    <span style="font-size:.68rem;color:var(--sub);white-space:nowrap"><?= td_h($ctxBadge) ?></span>
    <span class="badge <?= $bc ?>" style="white-space:nowrap"><?= $bl ?></span>
    <span id="<?= $rowId ?>-chev" style="color:var(--dim);font-size:.72rem">
      <?= $isOpen?'▲':'▼' ?></span>
  </div>
  <div id="<?= $rowId ?>" style="display:<?= $isOpen?'block':'none' ?>;
       border-top:1px solid var(--line2);background:var(--panel2)">
    <div style="padding:14px 16px">
      <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:10px;font-size:.77rem;color:var(--sub)">
        <span><?= td_h($categoryLabels[$d['decision_category']]??$d['decision_category']) ?></span>
        <span>·</span><span>Effective <?= td_h($d['effective_date']) ?></span>
        <span>·</span><span><?= td_h($ctxBadge) ?></span>
        <?php if ($d['record_sha256']): ?>
          <span>·</span>
          <span style="font-family:monospace;font-size:.69rem">
            <?= td_h(substr($d['record_sha256'],0,16)) ?>…</span>
        <?php endif; ?>
      </div>
      <?php if ($d['resolution_md']): ?>
      <div style="background:var(--panel);border-left:3px solid var(--gold);border-radius:0 4px 4px 0;
                  padding:9px 13px;font-size:.77rem;color:var(--text);line-height:1.55;
                  white-space:pre-wrap;word-break:break-word;max-height:110px;overflow-y:auto;
                  margin-bottom:12px"><?= td_h(mb_strimwidth($d['resolution_md'],0,420,'…')) ?></div>
      <?php endif; ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="./trustee_decisions.php?id=<?= urlencode($d['decision_uuid']) ?>"
           class="btn-primary btn-sm">View Full Record</a>
        <?php if (in_array($d['status'],['draft','pending_execution'],true)): ?>
          <a href="./trustee_decisions.php?action=edit&id=<?= urlencode($d['decision_uuid']) ?>"
             class="btn-primary btn-sm"
             style="background:none;border-color:var(--line2);color:var(--sub)">✎ Edit</a>
        <?php endif; ?>
        <?php if ($isDone): ?>
          <a href="./trustee_decisions.php?id=<?= urlencode($d['decision_uuid']) ?>"
             class="btn-primary btn-sm"
             style="background:var(--okb);border-color:rgba(82,184,122,.3);color:var(--ok)">
            📄 Certificate</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

</div><!-- .main -->
</div><!-- .admin-shell -->


<script>
function addPower() {
  const c = document.getElementById('powers-container');
  const div = document.createElement('div');
  div.className = 'powers-row';
  div.innerHTML = '<input type="text" name="clause_ref[]" placeholder="e.g. Declaration-7.4">'
    + '<input type="text" name="clause_desc[]" placeholder="Description of power">'
    + '<button type="button" class="remove-power" onclick="removePower(this)">✕</button>';
  c.appendChild(div);
}
function removePower(btn) {
  const rows = document.querySelectorAll('.powers-row');
  if (rows.length > 1) {
    btn.closest('.powers-row').remove();
  }
}
function showCert() {
  document.getElementById('tdr-cert').classList.add('active');
  document.body.classList.add('cert-open');
  window.scrollTo(0, 0);
}
function hideCert() {
  document.getElementById('tdr-cert').classList.remove('active');
  document.body.classList.remove('cert-open');
}
function toggleRow(id) {
  const body = document.getElementById(id);
  const chev = document.getElementById(id + '-chev');
  const hdr  = document.getElementById(id + '-hdr');
  if (!body) return;
  const open = body.style.display !== 'none';
  body.style.display = open ? 'none' : 'block';
  if (chev) chev.textContent = open ? '▼' : '▲';
  if (hdr)  hdr.style.background = open ? 'var(--panel)' : 'var(--panel2)';
}
function toggleGroup(grpId) {
  const grp = document.getElementById(grpId);
  if (!grp) return;
  const rows = grp.querySelectorAll('[id^="row-"]:not([id$="-hdr"]):not([id$="-chev"])');
  const anyOpen = Array.from(rows).some(el => el.style.display !== 'none');
  rows.forEach(el => { el.style.display = anyOpen ? 'none' : 'block'; });
  grp.querySelectorAll('[id$="-chev"]').forEach(el => { el.textContent = anyOpen ? '▼' : '▲'; });
  grp.querySelectorAll('[id$="-hdr"]').forEach(el => {
    el.style.background = anyOpen ? 'var(--panel)' : 'var(--panel2)';
  });
}
</script>

<?php if ($decision && $decision['status'] === 'fully_executed'):
  $certExec  = !empty($execRecords) ? $execRecords[0] : [];
  $certPowers = json_decode((string)($decision['powers_json'] ?? '[]'), true) ?: [];
?>
<div class="cert-wrap" id="tdr-cert">
  <div class="cert-header">
    <div class="org">COGS of Australia Foundation · ABN 61 734 327 831</div>
    <h1>Trustee Decision Record</h1>
    <div class="sub">
      <?= htmlspecialchars($subTrustLabels[$decision['sub_trust_context']] ?? $decision['sub_trust_context'], ENT_QUOTES) ?>
      — Electronic Trustee Minute
    </div>
  </div>

  <div class="cert-status">
    <div class="tick">✓</div>
    <h2>Trustee Decision Record — Fully Executed</h2>
  </div>

  <div class="cert-section">
    <div class="cert-section-title">Record Identity</div>
    <div class="cert-row"><div class="cert-lbl">Reference</div><div class="cert-val highlight"><?= td_h($decision['decision_ref']) ?></div></div>
    <div class="cert-row"><div class="cert-lbl">Title</div><div class="cert-val highlight"><?= td_h($decision['title']) ?></div></div>
    <div class="cert-row"><div class="cert-lbl">Sub-Committee</div><div class="cert-val"><?= td_h($subTrustLabels[$decision['sub_trust_context']] ?? $decision['sub_trust_context']) ?></div></div>
    <div class="cert-row"><div class="cert-lbl">Category</div><div class="cert-val"><?= td_h($categoryLabels[$decision['decision_category']] ?? $decision['decision_category']) ?></div></div>
    <div class="cert-row"><div class="cert-lbl">Effective Date</div><div class="cert-val"><?= td_h($decision['effective_date']) ?></div></div>
    <div class="cert-row"><div class="cert-lbl">Decision UUID</div><div class="cert-val mono"><?= td_h($decision['decision_uuid']) ?></div></div>
  </div>

  <div class="cert-section">
    <div class="cert-section-title">Executor</div>
    <div class="cert-row"><div class="cert-lbl">Full Name</div><div class="cert-val highlight"><?= td_h(TrusteeDecisionService::EXECUTOR_NAME) ?></div></div>
    <div class="cert-row"><div class="cert-lbl">Address</div><div class="cert-val"><?= td_h(TrusteeDecisionService::EXECUTOR_ADDRESS) ?></div></div>
    <div class="cert-row"><div class="cert-lbl">Capacity</div><div class="cert-val"><?= td_h($certExec['capacity_label'] ?? '') ?></div></div>
    <div class="cert-row"><div class="cert-lbl">Execution Timestamp</div><div class="cert-val mono"><?= td_h($certExec['execution_timestamp_utc'] ?? '') ?> UTC</div></div>
    <div class="cert-row"><div class="cert-lbl">Execution Method</div><div class="cert-val"><?= td_h(TrusteeDecisionService::EXECUTION_METHOD) ?></div></div>
    <?php if (!empty($certExec['member_match_status']) && $certExec['member_match_status'] === 'matched'): ?>
    <div class="cert-row"><div class="cert-lbl">Identity Verified</div><div class="cert-val highlight" style="color:#2d7a4f">✓ Member <?= td_h($certExec['member_number_matched'] ?? '') ?> — <?= td_h($certExec['member_name_matched'] ?? '') ?></div></div>
    <?php endif; ?>
  </div>

  <div class="cert-section">
    <div class="cert-section-title">Powers Exercised</div>
    <?php foreach ($certPowers as $p): ?>
    <div class="cert-row">
      <div class="cert-lbl mono"><?= td_h($p['clause_ref'] ?? '') ?></div>
      <div class="cert-val"><?= td_h($p['description'] ?? '') ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="cert-section">
    <div class="cert-section-title">Resolution</div>
    <div class="cert-resolution"><?= td_h($decision['resolution_md']) ?></div>
  </div>

  <div class="cert-section">
    <div class="cert-section-title">Cryptographic Integrity</div>
    <div class="cert-row"><div class="cert-lbl">Record SHA-256</div><div class="cert-val mono highlight"><?= td_h($decision['record_sha256'] ?? '') ?></div></div>
    <div class="cert-row"><div class="cert-lbl">Evidence Vault Entry</div><div class="cert-val mono">#<?= td_h((string)($decision['evidence_vault_id'] ?? '')) ?></div></div>
    <div class="cert-row"><div class="cert-lbl">Non-MIS Affirmation</div><div class="cert-val" style="color:#2d7a4f">✓ Affirmed</div></div>
  </div>

  <div class="cert-notice">
    This Trustee Decision Record is a sole-trustee administrative minute of the COGS of Australia
    Foundation Community Joint Venture Mainspring Hybrid Trust (ABN 61 734 327 831). It is executed
    electronically in accordance with the Electronic Transactions Act 1999 (Cth). No wet-ink signature
    or paper counterpart is required or produced. The record is not a managed investment scheme
    instrument (JVPA clause 4.9, Declaration clause 1.1A). The SHA-256 hash above constitutes the
    cryptographic integrity reference for this record. An electronically authenticated copy of this
    record, produced from the Foundation's secure systems and bearing the SHA-256 hash, is a legal
    copy for all purposes consistent with Declaration clause 1.5A.
  </div>

  <div class="cert-footer">
    COGS of Australia Foundation · cogsaustralia.org · Wahlubal Country, Bundjalung Nation<br>
    Generated <?= td_h(gmdate('d F Y H:i:s')) ?> UTC
  </div>

  <div class="cert-actions no-print" style="margin-top:24px">
    <button class="print-btn" onclick="window.print()">🖨 Print / Save as PDF</button>
    <button class="print-btn" onclick="hideCert()">✕ Close</button>
  </div>
</div>
<?php endif; ?>

</body>
</html>
