<?php
        <?php foreach ($categoryLabels as $val => $lbl): ?>
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
?>