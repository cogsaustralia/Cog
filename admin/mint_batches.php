<?php
require_once __DIR__ . '/includes/ops_workflow.php';
ops_require_admin();
$pdo = ops_db();
$flash=''; $flashType='ok';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
  try {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create_batch') {
      $queueIds = array_map('intval', $_POST['queue_ids'] ?? []);
      $label = trim((string)($_POST['batch_label'] ?? ''));
      $chainTarget = trim((string)($_POST['chain_target'] ?? 'besu-prep'));
      $notes = trim((string)($_POST['notes'] ?? ''));
      $batchId = ops_create_mint_batch($pdo, $label, $chainTarget, $notes, $queueIds);
      $flash='Mint batch created #'.$batchId;
    }
    if ($action === 'update_batch') {
      $batchId = (int)($_POST['batch_id'] ?? 0);
      $status = trim((string)($_POST['batch_status'] ?? 'prepared'));
      $notes = trim((string)($_POST['notes'] ?? ''));
      $allowed = ['prepared','reviewed','approved_for_handoff','handed_off','rejected_back'];
      if (!in_array($status, $allowed, true)) throw new RuntimeException('Invalid batch status.');
      $pdo->prepare('UPDATE mint_batches SET batch_status=?, reviewed_by_admin_id=?, notes=?, updated_at=? WHERE id=?')
        ->execute([$status, ops_admin_id(), $notes ?: null, ops_now(), $batchId]);
      $flash='Mint batch updated.';
    }
  } catch (Throwable $e) {
    $flash = $e->getMessage();
    $flashType = 'err';
  }
}

$queueRows = ops_table_exists($pdo,'mint_queue') ? ops_fetch_all($pdo, "SELECT mq.*, m.full_name, m.member_number, tc.class_code, tc.display_name FROM mint_queue mq JOIN members m ON m.id=mq.member_id JOIN token_classes tc ON tc.id=mq.token_class_id WHERE mq.batch_id IS NULL AND mq.queue_status IN ('ready_for_blockchain','ready_for_batch','locked_manual','manual_hold','held_manual') ORDER BY mq.id DESC") : [];
$batches = ops_table_exists($pdo,'mint_batches') ? ops_fetch_all($pdo, "SELECT mb.*, COUNT(mbi.id) item_count FROM mint_batches mb LEFT JOIN mint_batch_items mbi ON mbi.batch_id = mb.id GROUP BY mb.id ORDER BY mb.id DESC") : [];
ob_start(); ?>
<?php ops_admin_help_assets_once(); ?>
<div class="grid" style="margin-bottom:18px;gap:16px">
  <?= ops_admin_info_panel('Stage 5 · Legacy bridge batches', 'What this page does', 'Mint Batches is the older batching surface used by the mint/chain-handoff bridge workflow. It remains useful for compatibility and historical operator flow, but the authoritative live execution batching happens on the Execution console.', [
    'Use this page when you need to create or review bridge batches from queue items.',
    'Do not confuse these legacy mint batches with the authoritative execution batches on the Execution page.',
    'Chain Handoff works from these batch records when the bridge path is still in use.'
  ]) ?>
  <?= ops_admin_workflow_panel('Typical workflow', 'The bridge batch path is simpler than the main execution console but should still be used in order.', [
    ['title' => 'Select queue items', 'body' => 'Choose only queue items that genuinely belong together in one bridge batch.'],
    ['title' => 'Create batch', 'body' => 'Create the legacy batch with a clear label, chain target, and notes.'],
    ['title' => 'Review / update batch', 'body' => 'Record the bridge batch status and any notes needed for later handoff.'],
    ['title' => 'Open handoff', 'body' => 'Move into Chain Handoff when the batch needs an external bridge record or payload review.']
  ]) ?>
  <?= ops_admin_status_panel('Status guide', 'These statuses are bridge batch states, not the authoritative live execution statuses.', [
    ['label' => 'Prepared', 'body' => 'The bridge batch exists and is being assembled or checked.'],
    ['label' => 'Reviewed / approved for handoff', 'body' => 'The batch is ready to move into the handoff/export stage.'],
    ['label' => 'Handed off / rejected back', 'body' => 'The batch has either moved out to handoff or been returned for more work.']
  ]) ?>
</div>
<div class="stack">
  <div class="section">
    <div class="card-head"><h2>Create mint batch<?= ops_admin_help_button('Create mint batch', 'Create a legacy bridge batch from queue items that belong together. This is not the same as the authoritative execution batch on the Execution console.') ?></h2></div>
  <div class="card-body">

    <form method="post" class="stack">
      <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
      <input type="hidden" name="action" value="create_batch">
      <div class="form-grid">
        <div><label>Batch label<?= ops_admin_help_button('Batch label', 'Use a clear human-readable label so operators can tell what this bridge batch is for without opening it.') ?></label><input name="batch_label" placeholder="Foundation Day BNFT Batch"></div>
        <div><label>Chain target<?= ops_admin_help_button('Chain target', 'Record the intended ledger/network destination for the bridge batch.') ?></label><input name="chain_target" value="besu-prep"></div>
      </div>
      <div><label>Notes</label><textarea name="notes"></textarea></div>
      <div class="table-wrap"><table><thead><tr><th>Select<?= ops_admin_help_button('Select queue items', 'Choose only queue rows that should move together in one bridge batch.') ?></th><th>Queue</th><th>Member</th><th>Class</th><th>Lane</th><th>Status</th></tr></thead><tbody>
      <?php if(!$queueRows): ?><tr><td colspan="6">No queue items available for batching.</td></tr><?php endif; ?>
      <?php foreach($queueRows as $q): ?><tr>
        <td><input type="checkbox" name="queue_ids[]" value="<?= (int)$q['id'] ?>"></td>
        <td>#<?= (int)$q['id'] ?></td>
        <td><?= ops_h($q['full_name'].' · '.$q['member_number']) ?></td>
        <td><?= ops_h($q['display_name']) ?> <div class="muted"><?= ops_h($q['class_code']) ?></div></td>
        <td><?= ops_h((string)$q['manual_signoff_lane']) ?></td>
        <td><?= ops_h($q['queue_status']) ?></td>
      </tr><?php endforeach; ?>
      </tbody></table></div>
      <div class="actions"><button class="btn" type="submit">Create batch from selected items</button></div>
    </form>
  </div>

  <div class="section">
    <div class="card-head"><h2>Mint batches<?= ops_admin_help_button('Mint batches', 'Existing bridge batches created from the legacy queue. Update their bridge status here or move into Chain Handoff for export/traceability work.') ?></h2></div>
  <div class="card-body">

    <div class="table-wrap"><table><thead><tr><th>ID</th><th>Code</th><th>Label</th><th>Chain</th><th>Status<?= ops_admin_help_button('Batch status', 'The current bridge status of the batch. Use it to track whether the batch is still being assembled, reviewed, handed off, or rejected back.') ?></th><th>Items</th><th>Action<?= ops_admin_help_button('Update or handoff', 'Update the bridge batch status here, or open Chain Handoff when the batch is ready for the next bridge stage.') ?></th></tr></thead><tbody>
    <?php if(!$batches): ?><tr><td colspan="7">No batches created yet.</td></tr><?php endif; ?>
    <?php foreach($batches as $b): ?><tr>
      <td><?= (int)$b['id'] ?></td>
      <td><?= ops_h($b['batch_code']) ?></td>
      <td><?= ops_h($b['batch_label']) ?></td>
      <td><?= ops_h($b['chain_target']) ?></td>
      <td><?= ops_h($b['batch_status']) ?></td>
      <td><?= (int)$b['item_count'] ?></td>
      <td style="min-width:260px">
        <form method="post" class="stack">
      <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
          <input type="hidden" name="action" value="update_batch">
          <input type="hidden" name="batch_id" value="<?= (int)$b['id'] ?>">
          <select name="batch_status"><?php foreach(['prepared','reviewed','approved_for_handoff','handed_off','rejected_back'] as $s): ?><option value="<?= $s ?>"<?= $b['batch_status']===$s?' selected':'' ?>><?= $s ?></option><?php endforeach; ?></select>
          <textarea name="notes"><?= ops_h((string)$b['notes']) ?></textarea>
          <div class="actions">
            <button class="btn-secondary" type="submit">Update batch</button>
            <a class="btn-secondary" href="<?= ops_h(admin_url('chain_handoff.php')) ?>?batch_id=<?= (int)$b['id'] ?>">Open handoff</a>
          </div>
        </form>
      </td>
    </tr><?php endforeach; ?>
    </tbody></table></div>
  </div>
</div>
<?php $body=ob_get_clean(); ops_render_page('Mint Batches','mint_batches',$body,$flash,$flashType);