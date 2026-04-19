<?php
require_once __DIR__ . '/includes/ops_workflow.php';
ops_require_admin();
$pdo = ops_db();
$flash=''; $flashType='ok';
if($_SERVER['REQUEST_METHOD']==='POST'){
  admin_csrf_verify();
  try {
    $queueId=(int)($_POST['queue_id']??0);
    $status=trim((string)($_POST['queue_status']??''));
    $notes=trim((string)($_POST['notes']??''));
    $evidence=trim((string)($_POST['evidence_reference']??''));
    if(!in_array($status, ops_mint_queue_allowed_statuses(), true)) throw new RuntimeException('Invalid queue status.');
    $pdo->prepare('UPDATE mint_queue SET queue_status=?, notes=?, evidence_reference=?, signed_off_by_admin_id=?, signed_off_at=?, updated_at=? WHERE id=?')
      ->execute([$status,$notes?:null,$evidence?:null,ops_admin_id(),ops_now(),ops_now(),$queueId]);
    $flash='Queue item updated.';
  } catch(Throwable $e){ $flash=$e->getMessage(); $flashType='err'; }
}
$rows=ops_table_exists($pdo,'mint_queue')?ops_fetch_all($pdo,'SELECT mq.*, m.full_name, m.member_number, tc.class_code, tc.display_name FROM mint_queue mq JOIN members m ON m.id=mq.member_id JOIN token_classes tc ON tc.id=mq.token_class_id ORDER BY mq.id DESC'):[];
ob_start(); ?>
<?php ops_admin_help_assets_once(); ?>
<div class="grid" style="margin-bottom:18px;gap:16px">
  <?= ops_admin_info_panel('Stage 5 · Legacy bridge queue', 'What this page does', 'Mint / Manual Queue is the compatibility queue for the older mint-preparation path. It remains useful for bridge oversight, but it is not the primary execution control page. Use it to review legacy queue items, confirm lane/status alignment, and prepare items that may still need to be batched or handed off through the bridge.', [
    'The authoritative live operator flow is on the Execution console.',
    'This queue is still useful for legacy compatibility, evidence references, and batch preparation tracing.',
    'Queue changes here should be made carefully because they affect the bridge interpretation of the item.'
  ]) ?>
  <?= ops_admin_workflow_panel('Typical workflow', 'Use this page when checking or updating the bridge queue, not when running the main live execution lifecycle.', [
    ['title' => 'Review queue rows', 'body' => 'Confirm the correct member, class, lane, and queue status for each bridge item.'],
    ['title' => 'Update evidence / notes', 'body' => 'Record evidence references or operator notes that explain why the queue row is in its current state.'],
    ['title' => 'Open batches or handoff', 'body' => 'Use the linked batch and handoff pages when the queue item needs to move into those bridge stages.']
  ]) ?>
  <?= ops_admin_status_panel('Status guide', 'Queue statuses here describe the legacy bridge item, not the formal Partners-facing published state.', [
    ['label' => 'Ready for batch / blockchain', 'body' => 'The item is eligible to move further through the bridge path.'],
    ['label' => 'Locked / held manual', 'body' => 'The row still needs operator judgment or additional evidence before it should move.'],
    ['label' => 'Batch linked', 'body' => 'The queue row has already been attached to a mint batch and should be read with that batch record.']
  ]) ?>
</div>
<div class="section">
  <div class="card-head"><h2>Manual queue and execution prep<?= ops_admin_help_button('Manual queue and execution prep', 'This page is the bridge queue for older/manual mint-preparation workflows. The main live execution lifecycle now sits on the Execution console, but this queue remains useful for evidence references, lane/status review, and bridge compatibility.') ?></h2></div>
  <div class="card-body">

  <div class="actions" style="margin-bottom:12px">
    <a class="btn" href="<?= ops_h(admin_url('mint_batches.php')) ?>">Open mint batches</a>
    <a class="btn-secondary" href="<?= ops_h(admin_url('chain_handoff.php')) ?>">Open chain handoff</a>
  </div>
  <div class="table-wrap"><table><thead><tr><th>ID</th><th>Member</th><th>Class</th><th>Lane<?= ops_admin_help_button('Lane', 'The manual sign-off lane or bridge lane assigned to this queue item. Use it to understand which review path the item belongs to.') ?></th><th>Status<?= ops_admin_help_button('Queue status', 'The legacy bridge status for this item. This is not the same as the authoritative execution batch lifecycle.') ?></th><th>Batch</th><th>Notes<?= ops_admin_help_button('Notes and evidence', 'Use notes and evidence references to explain why the queue item is in its current state and what support material exists.') ?></th><th>Update<?= ops_admin_help_button('Update queue item', 'Change the bridge queue state only when you are intentionally managing the legacy compatibility path.') ?></th></tr></thead><tbody>
  <?php if(!$rows): ?><tr><td colspan="8">No queue items found.</td></tr><?php endif; ?>
  <?php foreach($rows as $r): ?><tr>
    <td><?= (int)$r['id'] ?></td>
    <td><?= ops_h($r['full_name'].' · '.$r['member_number']) ?></td>
    <td><?= ops_h($r['display_name']) ?> <span class="muted">(<?= ops_h($r['class_code']) ?>)</span></td>
    <td><span class="badge"><?= ops_h((string)$r['manual_signoff_lane']) ?></span></td>
    <td><?= ops_h($r['queue_status']) ?></td>
    <td><?= !empty($r['batch_id']) ? '#'.(int)$r['batch_id'] : '—' ?></td>
    <td><?= ops_h((string)$r['notes']) ?><?php if(!empty($r['evidence_reference'])): ?><div class="muted">Ref: <?= ops_h($r['evidence_reference']) ?></div><?php endif; ?></td>
    <td><form method="post" style="display:grid;gap:8px;min-width:220px">
      <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
      <input type="hidden" name="queue_id" value="<?= (int)$r['id'] ?>">
      <select name="queue_status"><?php foreach(ops_mint_queue_allowed_statuses() as $s): ?><option value="<?= $s ?>"<?= $r['queue_status']===$s?' selected':'' ?>><?= $s ?></option><?php endforeach; ?></select>
      <input name="evidence_reference" value="<?= ops_h((string)$r['evidence_reference']) ?>" placeholder="Evidence ref / attestation ref">
      <textarea name="notes" placeholder="Queue notes"><?= ops_h((string)$r['notes']) ?></textarea>
      <button class="btn-secondary" type="submit">Update queue item</button>
    </form></td>
  </tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php $body=ob_get_clean(); ops_render_page('Mint / Manual Queue','mint_queue',$body,$flash,$flashType);