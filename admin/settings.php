<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/ops_workflow.php';
ops_require_admin();
$pdo = ops_db();
$flash=''; $flashType='ok';

$groups = [
    'Display labels' => [
        'public_label_partner' => 'Public label for participant role',
        'public_label_contribution' => 'Public label for entry contribution',
        'internal_label_member' => 'Internal/legacy participant label',
        'internal_label_membership_fee' => 'Internal/legal alias for entry fee',
    ],
    'Execution and governance' => [
        'manual_control_mode' => 'Manual control mode',
        'default_chain_target' => 'Default chain target',
        'execution_console_mode' => 'Execution console mode',
        'execution_bridge_mode' => 'Execution bridge mode',
        'governance_verification_mode' => 'Governance verification mode',
        'governance_evidence_reporting' => 'Governance evidence reporting',
        'batch_minimum_items' => 'Batch minimum items',
        'handoff_requires_reviewed_batch' => 'Handoff requires reviewed batch',
    ],
    'Infrastructure and rollout' => [
        'phase1_control_plane_status' => 'Phase 1 control-plane status',
        'legacy_admin_auth_status' => 'Legacy admin auth status',
        'sovereign_infrastructure_mode' => 'Sovereign infrastructure mode',
        'evidence_review_required_for_landholder' => 'Landholder evidence review required',
        'evidence_review_required_for_zone' => 'Zone evidence review required',
    ],
    'Sender identity' => [
        'email_sender_name' => 'Email sender name',
        'email_sender_address' => 'Email sender address',
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
    try {
        foreach ($groups as $fields) {
            foreach (array_keys($fields) as $key) {
                $defaultVal = ops_settings_defaults()[$key] ?? '';
                $val = array_key_exists($key, $_POST) ? trim((string)$_POST[$key]) : (string)$defaultVal;
                ops_setting_set($pdo, $key, $val, 'string', 'Phase 1 app-layer remap settings update');
            }
        }
        $flash='Settings updated.';
    } catch (Throwable $e) {
        $flash = $e->getMessage();
        $flashType = 'error';
    }
}

$settings = [];
foreach (ops_settings_defaults() as $key => $defaultVal) {
    $settings[$key] = ops_setting_get($pdo, $key, (string)$defaultVal);
}
$labels = ops_label_settings($pdo);
ob_start(); ?>
<style>
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.field{display:grid;gap:6px}
.field label{font-size:.86rem;color:var(--muted);font-weight:600}
.field input{width:100%;padding:.85rem 1rem;border-radius:12px;border:1px solid var(--line);background:rgba(255,255,255,.03);color:var(--text)}
.group-stack{display:grid;gap:18px}
@media (max-width:760px){.form-grid{grid-template-columns:1fr}}
</style>
<div class="group-stack">
  <?= ops_admin_collapsible_help('Page guide & workflow', [
    <?= ops_admin_info_panel('System configuration', 'What this page does', 'Use this page to manage the shared settings that shape admin labels, bridge posture, control-plane mode, sender identity, and rollout behavior. This page changes how the system behaves, so operators should understand the impact before saving.', [
          'Change labels here when admin-generated wording needs to change globally.',
          'Use bridge and execution settings carefully because they affect operator workflow visibility and live process behavior.',
          'Treat this as a platform-level configuration page, not a routine work queue.'
      ]) ?>
      <?= ops_admin_workflow_panel('Typical workflow', 'Settings changes should be deliberate and traceable. Use this sequence before saving platform-level changes.', [
          ['title' => 'Review the current state', 'body' => 'Confirm which label, bridge, or rollout setting needs to change and why.'],
          ['title' => 'Change only the intended fields', 'body' => 'Avoid broad edits when a single setting is enough.'],
          ['title' => 'Save and verify', 'body' => 'After saving, confirm that the relevant admin pages and outputs now behave as expected.'],
          ['title' => 'Record the operational reason', 'body' => 'Treat settings changes as controlled operational decisions, especially where they affect execution or bridge mode.'],
      ]) ?>
      <?= ops_admin_status_panel('How to read this page', 'The setting groups below are organized by operational purpose so you can see which changes are cosmetic labels and which change how the platform behaves.', [
          ['label' => 'Public / internal labels', 'body' => 'These settings influence how admin-generated and public-facing text is described.'],
          ['label' => 'Control-plane and rollout settings', 'body' => 'These change bridge posture, execution mode, evidence posture, and rollout status.'],
          ['label' => 'Sender identity', 'body' => 'These settings affect the identity shown on outbound communications.'],
      ]) ?>
      <?= ops_admin_guide_panel('Settings section guide', 'Use this guide to understand the level of caution needed for each settings group.', [
          ['title' => 'Labels', 'body' => 'Use when wording should change without renaming the database or technical identifiers.'],
          ['title' => 'Control plane', 'body' => 'Use only when the operating posture of the admin system genuinely needs to change.'],
          ['title' => 'Sender identity', 'body' => 'Use when the display name or sender address for system email needs updating.'],
      ]) ?>
  ]) ?>
  <div class="section">
    <h2 style="margin-top:0">Phase 1 labels and control-plane settings<?= ops_admin_help_button('Phase 1 labels and control-plane settings', 'These settings drive admin labels, bridge posture, execution mode, rollout posture, and sender identity. Some settings are cosmetic; others materially change how operators see and use the control plane.') ?></h2>
    <p class="muted">These settings now drive admin display language, bridge state, execution mode, and the current rollout posture. Public-facing/admin-generated wording should follow the label settings even while legacy DB names remain unchanged.</p>
    <div class="msg" style="margin-top:12px">
      Current labels: <strong><?= ops_h($labels['public_label_partner'] ?? 'Partner') ?></strong> / <strong><?= ops_h($labels['public_label_contribution'] ?? 'partnership contribution') ?></strong>
    </div>
  </div>

  <form method="post" class="group-stack">
    <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
    <?php foreach ($groups as $groupLabel => $fields): ?>
      <div class="section">
        <h3 style="margin:0 0 14px"><?= ops_h($groupLabel) ?><?= ops_admin_help_button($groupLabel, match ($groupLabel) {
        'Public / internal labels' => 'These settings control how participant, contribution, and internal labels are described across admin-generated outputs.',
        'Control-plane and rollout settings' => 'These settings influence live admin posture such as bridge mode, execution mode, infrastructure mode, and review requirements. Change them with care.',
        'Sender identity' => 'These values control the display identity used on outbound email.',
        default => 'This setting group changes shared system behavior.'
    }) ?></h3>
        <div class="form-grid">
          <?php foreach ($fields as $key => $label): ?>
            <div class="field">
              <label for="<?= ops_h($key) ?>"><?= ops_h($label) ?><?= ops_admin_help_button($label, "This setting is saved at the platform level and may affect multiple admin surfaces or generated outputs.") ?></label>
              <input id="<?= ops_h($key) ?>" name="<?= ops_h($key) ?>" value="<?= ops_h((string)($settings[$key] ?? '')) ?>">
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <div class="actions"><button class="btn" type="submit">Save settings<?= ops_admin_help_button('Save settings', 'Saving writes these values into the shared admin settings store. Verify the affected admin pages or outbound outputs after saving, especially if you changed control-plane or bridge posture.') ?></button></div>
  </form>
</div>
<?php
$body = ob_get_clean();
ops_render_page('Settings', 'settings', $body, $flash, $flashType);
