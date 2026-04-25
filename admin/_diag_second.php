<?php
          <option value="<?= td_h($val) ?>" <?= $decision['decision_category'] === $val ? 'selected' : '' ?>>
            <?= td_h($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Title <span class="required">*</span></label>
      <input type="text" name="title" required value="<?= td_h($decision['title']) ?>">
    </div>
    <div class="form-group">
      <label>Effective Date <span class="required">*</span></label>
      <input type="date" name="effective_date" required value="<?= td_h($decision['effective_date']) ?>">
    </div>
  </div>

  <div class="form-card">
    <h3>Step 2 — Powers Exercised</h3>
    <div id="powers-container">
      <?php foreach ($editPowers as $ep): ?>
      <div class="powers-row">
        <input type="text" name="clause_ref[]"  value="<?= td_h($ep['clause_ref']  ?? '') ?>">
        <input type="text" name="clause_desc[]" value="<?= td_h($ep['description'] ?? '') ?>">
        <button type="button" class="remove-power" onclick="removePower(this)">✕</button>
      </div>
      <?php endforeach; ?>
      <?php if (empty($editPowers)): ?>
      <div class="powers-row">
        <input type="text" name="clause_ref[]"  placeholder="e.g. SubTrustA-1A.3(a)">
        <input type="text" name="clause_desc[]" placeholder="Description of power">
        <button type="button" class="remove-power" onclick="removePower(this)">✕</button>
      </div>
      <?php endif; ?>
    </div>
    <button type="button" class="add-power" onclick="addPower()">+ Add Clause</button>
  </div>

  <div class="form-card">
    <h3>Step 3 — Background &amp; Considerations</h3>
    <div class="form-group">
      <label>Background (Markdown)</label>
      <textarea name="background_md"><?= td_h($decision['background_md'] ?? '') ?></textarea>
    </div>
    <div class="form-group">
      <label>FNAC Consideration (Markdown)</label>
      <textarea name="fnac_consideration_md"><?= td_h($decision['fnac_consideration_md'] ?? '') ?></textarea>
    </div>
    <div class="form-group">
      <label>FPIC Consideration (Markdown)</label>
      <textarea name="fpic_consideration_md"><?= td_h($decision['fpic_consideration_md'] ?? '') ?></textarea>
    </div>
    <div class="form-group">
      <label>Cultural Heritage Consideration (Markdown)</label>
      <textarea name="cultural_heritage_md"><?= td_h($decision['cultural_heritage_md'] ?? '') ?></textarea>
    </div>
    <hr class="divider">
    <div class="form-group check">
      <input type="checkbox" name="fnac_consulted" id="fnac_consulted" value="1" <?= $decision['fnac_consulted'] ? 'checked' : '' ?>>
      <label for="fnac_consulted">FNAC consulted</label>
    </div>
    <div class="form-group">
      <label>FNAC Evidence Reference</label>
      <input type="text" name="fnac_evidence_ref" value="<?= td_h($decision['fnac_evidence_ref'] ?? '') ?>">
    </div>
    <div class="form-group check">
      <input type="checkbox" name="fpic_obtained" id="fpic_obtained" value="1" <?= $decision['fpic_obtained'] ? 'checked' : '' ?>>
      <label for="fpic_obtained">FPIC obtained</label>
    </div>
    <div class="form-group">
      <label>FPIC Evidence Reference</label>
      <input type="text" name="fpic_evidence_ref" value="<?= td_h($decision['fpic_evidence_ref'] ?? '') ?>">
    </div>
    <div class="form-group check">
      <input type="checkbox" name="cultural_heritage_assessed" id="cha" value="1" <?= $decision['cultural_heritage_assessed'] ? 'checked' : '' ?>>
      <label for="cha">Cultural Heritage assessed</label>
    </div>
    <div class="form-group">
      <label>Cultural Heritage Evidence Reference</label>
      <input type="text" name="cultural_heritage_ref" value="<?= td_h($decision['cultural_heritage_ref'] ?? '') ?>">
    </div>
  </div>

  <div class="form-card">
    <h3>Step 4 — Resolution</h3>
    <div class="form-group">
      <label>Resolution <span class="required">*</span></label>
      <textarea name="resolution_md" required style="min-height:140px"><?= td_h($decision['resolution_md']) ?></textarea>
    </div>
  </div>

  <div style="display:flex;gap:10px;align-items:center">
    <button type="submit" class="btn-primary">Save Changes</button>
    <a href="./trustee_decisions.php?id=<?= urlencode($decision['decision_uuid']) ?>"
       style="font-size:.8rem;color:var(--sub)">Cancel</a>
  </div>
</form>

<?php elseif ($decision): ?>
<!-- ════════════════════════════════════════════════════════ DETAIL VIEW -->
<div class="topbar" style="margin-bottom:20px">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px">
    <div>
      <h2><?= td_h($decision['decision_ref']) ?> — <?= td_h($decision['title']) ?></h2>
      <p>
        <?= td_h($subTrustLabels[$decision['sub_trust_context']] ?? $decision['sub_trust_context']) ?>
        &nbsp;·&nbsp;
        <?= td_h($categoryLabels[$decision['decision_category']] ?? $decision['decision_category']) ?>
        &nbsp;·&nbsp;
        Effective <?= td_h($decision['effective_date']) ?>
      </p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <?php [$bc, $bl] = $statusBadge[$decision['status']] ?? ['badge-warn', $decision['status']]; ?>
      <span class="badge <?= $bc ?>"><?= $bl ?></span>
      <?php if (in_array($decision['status'], ['draft','pending_execution'], true)): ?>
        <a href="./trustee_decisions.php?action=edit&amp;id=<?= urlencode($decision['decision_uuid']) ?>" class="btn-primary btn-sm">✎ Edit</a>
      <?php endif; ?>
      <a href="./trustee_decisions.php?action=create" class="btn-primary btn-sm">+ New TDR</a>
      <a href="./trustee_decisions.php" class="btn-primary btn-sm" style="background:none;border-color:var(--line2);color:var(--sub)">← All TDRs</a>
    </div>
  </div>
</div>

<div class="detail-card">
  <div class="detail-head"><h3>Record Details</h3></div>
  <div class="detail-body">
    <div class="dg">
      <span class="dg-l">Reference</span><span class="dg-v gold"><?= td_h($decision['decision_ref']) ?></span>
      <span class="dg-l">UUID</span><span class="dg-v mono"><?= td_h($decision['decision_uuid']) ?></span>
      <span class="dg-l">Sub-Committee</span><span class="dg-v"><?= td_h($subTrustLabels[$decision['sub_trust_context']] ?? $decision['sub_trust_context']) ?></span>
      <span class="dg-l">Category</span><span class="dg-v"><?= td_h($categoryLabels[$decision['decision_category']] ?? $decision['decision_category']) ?></span>
      <span class="dg-l">Effective Date</span><span class="dg-v"><?= td_h($decision['effective_date']) ?></span>
      <span class="dg-l">Status</span><span class="dg-v"><span class="badge <?= $bc ?>"><?= $bl ?></span></span>
      <span class="dg-l">Non-MIS Affirmation</span>
      <span class="dg-v <?= $decision['non_mis_affirmation'] ? 'ok' : '' ?>">
        <?= $decision['non_mis_affirmation'] ? '✓ Affirmed' : '✗ NOT AFFIRMED — cannot execute' ?>
      </span>
      <?php if ($decision['record_sha256']): ?>
        <span class="dg-l">Record SHA-256</span><span class="dg-v mono"><?= td_h($decision['record_sha256']) ?></span>
      <?php endif; ?>
      <?php if ($decision['evidence_vault_id']): ?>
        <span class="dg-l">Evidence Vault ID</span><span class="dg-v mono"><?= td_h((string)$decision['evidence_vault_id']) ?></span>
      <?php endif; ?>
    </div>

    <div class="section-title">Powers Exercised</div>
    <?php
      $powers = json_decode((string)($decision['powers_json'] ?? '[]'), true) ?: [];
      foreach ($powers as $p): ?>
      <div style="background:var(--panel);border:1px solid var(--line2);border-radius:6px;padding:8px 12px;margin-bottom:6px;font-size:.8rem">
        <span style="color:var(--gold);font-family:monospace"><?= td_h($p['clause_ref'] ?? '') ?></span>
        &nbsp;—&nbsp;<?= td_h($p['description'] ?? '') ?>
      </div>
    <?php endforeach; ?>

    <?php if ($decision['resolution_md']): ?>
      <div class="section-title">Resolution</div>
      <div class="md-preview"><?= td_h($decision['resolution_md']) ?></div>
    <?php endif; ?>

    <?php if ($decision['background_md']): ?>
      <div class="section-title">Background</div>
      <div class="md-preview"><?= td_h($decision['background_md']) ?></div>
    <?php endif; ?>
  </div>
</div>

<?php if ($execRecords): ?>
<div class="detail-card">
  <div class="detail-head"><h3>Execution Records</h3></div>
  <div class="detail-body">
    <?php foreach ($execRecords as $er): ?>
    <div class="exec-row">
      <div class="dg">
        <span class="dg-l">Execution UUID</span><span class="dg-v mono"><?= td_h($er['execution_uuid']) ?></span>
        <span class="dg-l">Capacity</span><span class="dg-v"><?= td_h($er['capacity_label']) ?></span>
        <span class="dg-l">Status</span><span class="dg-v"><?= td_h($er['status']) ?></span>
        <span class="dg-l">Timestamp (UTC)</span><span class="dg-v mono"><?= td_h($er['execution_timestamp_utc']) ?></span>
        <span class="dg-l">Record SHA-256</span><span class="dg-v mono"><?= td_h($er['record_sha256']) ?></span>
        <span class="dg-l">Evidence Vault ID</span><span class="dg-v mono"><?= td_h((string)($er['evidence_vault_id'] ?? '—')) ?></span>
        <?php if ($er['witness_full_name']): ?>
          <span class="dg-l">Witness</span><span class="dg-v"><?= td_h($er['witness_full_name']) ?></span>
          <span class="dg-l">Witness DOB</span><span class="dg-v"><?= td_h($er['witness_dob'] ?? '') ?></span>
          <span class="dg-l">Witness Occupation</span><span class="dg-v"><?= td_h($er['witness_occupation'] ?? '') ?></span>
          <span class="dg-l">Witness Address</span><span class="dg-v"><?= td_h($er['witness_address'] ?? '') ?></span>
          <?php if ($er['witness_jp_number']): ?>
            <span class="dg-l">JP Number</span><span class="dg-v"><?= td_h($er['witness_jp_number']) ?></span>
          <?php endif; ?>
          <?php if ($er['witness_timestamp_utc']): ?>
            <span class="dg-l">Witness Timestamp</span><span class="dg-v mono"><?= td_h($er['witness_timestamp_utc']) ?></span>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Reference document — Key Management Policy (TDR-20260425-018 only) -->
<?php if (($decision['decision_ref'] ?? '') === 'TDR-20260425-018'): ?>
<div class="detail-card">
  <div class="detail-head"><h3>Reference Document</h3></div>
  <div class="detail-body">
    <p style="font-size:.82rem;color:var(--sub);margin-bottom:14px">
      This Trustee Decision Record provisionally adopts the operational governance policy
      identified below under Declaration cl.15A.4. The Trustee should review the policy
      before issuing an execution token.
    </p>
    <a href="../docs/Key_Management_Policy.pdf" target="_blank" rel="noopener"
       style="display:flex;align-items:center;gap:14px;background:rgba(240,209,138,.07);
              border:1.5px solid rgba(240,209,138,.28);border-radius:12px;
              padding:14px 16px;text-decoration:none;transition:background .2s,border-color .2s"
       onmouseover="this.style.background='rgba(240,209,138,.14)';this.style.borderColor='rgba(240,209,138,.50)'"
       onmouseout="this.style.background='rgba(240,209,138,.07)';this.style.borderColor='rgba(240,209,138,.28)'">
      <div style="font-size:1.4rem">📄</div>
      <div style="flex:1">
        <div style="color:var(--gold);font-weight:600;font-size:.92rem">Key Management Policy</div>
        <div style="color:var(--sub);font-size:.74rem;margin-top:2px">
          Operational Governance Policy · Declaration cl.15A.4 · Effective 25 April 2026
        </div>
      </div>
      <div style="color:var(--gold);font-size:.78rem;font-weight:600;letter-spacing:.04em">OPEN PDF ↗</div>
    </a>
    <p style="font-size:.72rem;color:var(--dim);margin-top:10px;line-height:1.5">
      SHA-256: <span style="font-family:monospace">f87464e7a9dec0e1660f9632ef8df73e7fed67c852e825c4aadd7102ce21ace6</span>
    </p>
  </div>
</div>
<?php endif; ?>

<!-- Issue token / print actions -->
<?php if (in_array($decision['status'], ['draft','pending_execution'], true)): ?>
<div class="detail-card">
  <div class="detail-head"><h3>Execute This Record</h3></div>
  <div class="detail-body">
    <?php if (!$decision['non_mis_affirmation']): ?>
      <p style="color:var(--err);font-size:.83rem">⚠ non_mis_affirmation is not set. Contact admin to set before issuing token.</p>
    <?php else: ?>
      <p style="font-size:.82rem;color:var(--sub);margin-bottom:14px">
        Issuing an execution token will email a one-time link (valid 15 minutes) to the
        <strong><?= td_h($subTrustLabels[$decision['sub_trust_context']] ?? $decision['sub_trust_context']) ?></strong>
        trustee email address. The Trustee executes the record via that link.
      </p>
      <form method="POST">
        <input type="hidden" name="_action" value="issue_token">
        <input type="hidden" name="decision_uuid" value="<?= td_h($decision['decision_uuid']) ?>">
        <button type="submit" class="btn-primary">Issue Execution Token &amp; Email Trustee</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($decision['status'] === 'fully_executed'): ?>
<div style="margin-top:8px">
  <button class="btn-primary print-btn" onclick="showCert()">
    📄 View / Download Certified Copy
  </button>
</div>
<?php endif; ?>

<?php else: ?>
<!-- ════════════════════════════════════════════════════════ LIST VIEW -->
<?php
// Priority map for ordering — 007 and 010 removed (duplicate Non-MIS drafts, deleted)
// Non-MIS position for the entire Hybrid Trust is covered by executed TDR-005
$tdrPriority = [
    // Tier 1 — Immediate / blocking
    'TDR-20260422-001'=>1,  // Sub-Trust A bank account (executed)
    'TDR-20260425-002'=>2,  // CHESS Holding Policy (executed)
    'TDR-20260425-003'=>3,  // Ratification ASX:LGM holdings (executed)
    'TDR-20260425-004'=>4,  // IG/Citicorp authorisation (executed)
    'TDR-20260425-006'=>5,  // Sub-Trust B bank account
    'TDR-20260425-009'=>6,  // Sub-Trust C bank account
    'TDR-20260425-013'=>7,  // Indemnity & cost allocation policy
    // Tier 2 — Before Foundation Day (14 May 2026)
    'TDR-20260425-005'=>8,  // Non-MIS — Hybrid Trust (executed, covers all sub-trusts)
    'TDR-20260425-008'=>9,  // Beneficial Unit Register (STB)
    'TDR-20260425-011'=>10, // ACNC Registration (STC)
    'TDR-20260425-012'=>11, // DGR Endorsement (STC)
    'TDR-20260425-014'=>12, // Inaugural Meeting timetable
    'TDR-20260425-015'=>13, // Auditor appointment
    'TDR-20260425-018'=>14, // Key Management Policy (Declaration cl.15A.4 — required before GFD)
    // Tier 3 — Before Expansion Day
    'TDR-20260425-016'=>15, // Privacy policy
    'TDR-20260425-017'=>16, // AML/CTF procedure
];
$tierDefs = [
    ['min'=>1,  'max'=>7,  'label'=>'Tier 1 — Immediate'],
    ['min'=>8,  'max'=>14, 'label'=>'Tier 2 — Before Foundation Day'],
    ['min'=>15, 'max'=>99, 'label'=>'Tier 3 — Before Expansion Day'],
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
