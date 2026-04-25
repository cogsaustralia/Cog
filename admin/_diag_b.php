<?php
    <h3>Step 1 — Identification</h3>
    <div class="form-group">
      <label>Sub-Committee / Sub-Trust Context <span class="required">*</span></label>
      <select name="sub_trust_context" required>
        <option value="">— select —</option>
        <option value="sub_trust_a">STA — Operations, Financial &amp; Technical (Ops · Finance · Tech/Blockchain)</option>
        <option value="sub_trust_b">STB — Research, ESG &amp; Education (Research &amp; Acquisitions · ESG · Education &amp; Outreach)</option>
        <option value="sub_trust_c">STC — FNAC, Community &amp; Place-Based (First Nations · Community Projects · Place-Based)</option>
        <option value="all">All Sub-Committees — Hybrid Trust-wide</option>
      </select>
    </div>
    <div class="form-group">
      <label>Decision Category <span class="required">*</span></label>
      <select name="decision_category" required>
        <option value="">— select —</option>
        <?php foreach ($categoryLabels as $val => $lbl): ?>
          <option value="<?= td_h($val) ?>"><?= td_h($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Title <span class="required">*</span></label>
      <input type="text" name="title" required placeholder="e.g. Establishment of Sub-Trust A Bank Account">
    </div>
    <div class="form-group">
      <label>Effective Date <span class="required">*</span></label>
      <input type="date" name="effective_date" required value="<?= td_h(date('Y-m-d')) ?>">
    </div>
  </div>

  <div class="form-card">
    <h3>Step 2 — Powers Exercised</h3>
    <p style="font-size:.8rem;color:var(--sub);margin-bottom:12px">
      List every clause reference and corresponding power being exercised. At least one required.
    </p>
    <div id="powers-container">
      <div class="powers-row">
        <input type="text" name="clause_ref[]" placeholder="e.g. SubTrustA-1A.3(a)">
        <input type="text" name="clause_desc[]" placeholder="Description of power">
        <button type="button" class="remove-power" onclick="removePower(this)">✕</button>
      </div>
    </div>
    <button type="button" class="add-power" onclick="addPower()">+ Add Clause</button>
  </div>

  <div class="form-card">
    <h3>Step 3 — Background &amp; Considerations</h3>
    <div class="form-group">
      <label>Background (Markdown)</label>
      <textarea name="background_md" placeholder="Factual background for the decision..."></textarea>
    </div>
    <div class="form-group">
      <label>FNAC Consideration (Markdown)</label>
      <textarea name="fnac_consideration_md" placeholder="First Nations Advisory Committee consideration, if applicable..."></textarea>
    </div>
    <div class="form-group">
      <label>FPIC Consideration (Markdown)</label>
      <textarea name="fpic_consideration_md" placeholder="Free, Prior and Informed Consent, if applicable..."></textarea>
    </div>
    <div class="form-group">
      <label>Cultural Heritage Consideration (Markdown)</label>
      <textarea name="cultural_heritage_md" placeholder="Cultural heritage assessment, if applicable..."></textarea>
    </div>
    <hr class="divider">
    <div class="form-group check">
      <input type="checkbox" name="fnac_consulted" id="fnac_consulted" value="1">
      <label for="fnac_consulted">FNAC consulted</label>
    </div>
    <div class="form-group">
      <label>FNAC Evidence Reference</label>
      <input type="text" name="fnac_evidence_ref" placeholder="e.g. EVE-2026-001">
    </div>
    <div class="form-group check">
      <input type="checkbox" name="fpic_obtained" id="fpic_obtained" value="1">
      <label for="fpic_obtained">FPIC obtained</label>
    </div>
    <div class="form-group">
      <label>FPIC Evidence Reference</label>
      <input type="text" name="fpic_evidence_ref" placeholder="">
    </div>
    <div class="form-group check">
      <input type="checkbox" name="cultural_heritage_assessed" id="cha" value="1">
      <label for="cha">Cultural Heritage assessed</label>
    </div>
    <div class="form-group">
      <label>Cultural Heritage Evidence Reference</label>
      <input type="text" name="cultural_heritage_ref" placeholder="">
    </div>
  </div>

  <div class="form-card">
    <h3>Step 4 — Resolution</h3>
    <p style="font-size:.8rem;color:var(--sub);margin-bottom:10px">
      The operative resolution text. This is the legal substance of the decision.
    </p>
    <div class="form-group">
      <label>Resolution <span class="required">*</span></label>
      <textarea name="resolution_md" required style="min-height:140px"
        placeholder="The Caretaker Trustee RESOLVES to..."></textarea>
    </div>
  </div>

  <div style="display:flex;gap:10px;align-items:center">
    <button type="submit" class="btn-primary">Save as Draft</button>
    <a href="./trustee_decisions.php" style="font-size:.8rem;color:var(--sub)">Cancel</a>
  </div>
</form>

<?php elseif ($action === 'edit' && $decision): ?>
<!-- ════════════════════════════════════════════════════════ EDIT FORM -->
<?php
  $editPowers = json_decode((string)($decision['powers_json'] ?? '[]'), true) ?: [];
?>
<div class="topbar" style="margin-bottom:20px">
  <h2>✎ Edit — <?= td_h($decision['decision_ref']) ?></h2>
  <p>
    Editing a pending-execution record will invalidate the outstanding execution token.
    You must re-issue a new token after saving.
  </p>
</div>
<form method="POST">
  <input type="hidden" name="_action" value="update_draft">
  <input type="hidden" name="decision_uuid" value="<?= td_h($decision['decision_uuid']) ?>">

  <div class="form-card">
    <h3>Step 1 — Identification</h3>
    <div class="form-group">
      <label>Sub-Committee / Sub-Trust Context <span class="required">*</span></label>
      <select name="sub_trust_context" required>
        <option value="">— select —</option>
        <?php foreach ($subTrustLabels as $val => $lbl): ?>
          <option value="<?= td_h($val) ?>" <?= $decision['sub_trust_context'] === $val ? 'selected' : '' ?>>
            <?= td_h($lbl) ?></option>
        <?php endforeach; ?>
      </select>
      <?php $hubs = $subCommitteeHubs[$decision['sub_trust_context']] ?? []; if ($hubs): ?>
        <div style="font-size:.72rem;color:var(--sub);margin-top:4px">
          Hubs: <?= td_h(implode(' · ', $hubs)) ?>
        </div>
      <?php endif; ?>
    </div>
    <div class="form-group">
      <label>Decision Category <span class="required">*</span></label>
      <select name="decision_category" required>
        <option value="">— select —</option>
?>