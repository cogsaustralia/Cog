<?php
require_once __DIR__ . '/includes/ops_workflow.php';
ops_require_admin();
$pdo = ops_db();
$flash=''; $flashType='ok';
$selectedBatchId = (int)($_GET['batch_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
  try {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create_handoff') {
      $batchId = (int)($_POST['batch_id'] ?? 0);
      $chainTarget = trim((string)($_POST['chain_target'] ?? 'besu-prep'));
      $notes = trim((string)($_POST['notes'] ?? ''));
      $handoffId = ops_create_chain_handoff($pdo, $batchId, $chainTarget, $notes);
      $flash='Chain handoff created #'.$handoffId;
      $selectedBatchId = $batchId;
    }
    if ($action === 'update_handoff') {
      $handoffId = (int)($_POST['handoff_id'] ?? 0);
      $status = trim((string)($_POST['handoff_status'] ?? 'prepared'));
      $txRef = trim((string)($_POST['tx_reference'] ?? ''));
      $attestationHash = trim((string)($_POST['attestation_hash'] ?? ''));
      $notes = trim((string)($_POST['notes'] ?? ''));
      ops_update_chain_handoff($pdo, $handoffId, $status, $txRef, $attestationHash, $notes);
      $flash='Chain handoff updated.';
    }
  } catch (Throwable $e) {
    $flash = $e->getMessage();
    $flashType = 'err';
  }
}

$batches = ops_table_exists($pdo,'mint_batches') ? ops_fetch_all($pdo, "SELECT mb.*, COUNT(mbi.id) item_count FROM mint_batches mb LEFT JOIN mint_batch_items mbi ON mbi.batch_id = mb.id GROUP BY mb.id ORDER BY mb.id DESC") : [];
$handoffs = ops_table_exists($pdo,'chain_handoffs') ? ops_fetch_all($pdo, "SELECT ch.*, mb.batch_code, mb.batch_label FROM chain_handoffs ch JOIN mint_batches mb ON mb.id = ch.mint_batch_id ORDER BY ch.id DESC") : [];
$payload = $selectedBatchId > 0 ? ops_batch_payload($pdo, $selectedBatchId) : [];
ob_start(); ?>
<?php ops_admin_help_assets_once(); ?>
<div class="grid" style="margin-bottom:18px;gap:16px">
  <?= ops_admin_info_panel('Stage 5 · Bridge handoff', 'What this page does', 'Chain Handoff is the bridge page for preparing and tracking the manual or semi-manual export from a mint batch into a ledger/network handoff record. It is primarily a compatibility and audit surface, not the main execution control page.', [
    'Use this page when you need a formal handoff record tied to a legacy mint batch.',
    'The payload preview is for review and traceability, not a live mint action by itself.',
    'Transaction and attestation references belong here once a real external handoff occurs.'
  ]) ?>
  <?= ops_admin_workflow_panel('Typical workflow', 'Use this page after a batch exists and when a bridge/handoff record is required.', [
    ['title' => 'Choose a mint batch', 'body' => 'Select the existing batch that should have a chain or ledger handoff record.'],
    ['title' => 'Create handoff', 'body' => 'Create the handoff record with the intended chain target and operator notes.'],
    ['title' => 'Update handoff state', 'body' => 'Record submission references, attestation hashes, and status changes as the handoff progresses.'],
    ['title' => 'Use payload preview', 'body' => 'Review the payload for signer/operator confirmation before or alongside the external handoff.' ],
  ]) ?>
  <?= ops_admin_status_panel('Status guide', 'These statuses describe the bridge handoff record, not the authoritative execution batch state.', [
    ['label' => 'Prepared', 'body' => 'The handoff record exists but has not yet been externally submitted.'],
    ['label' => 'Submitted / updated', 'body' => 'The handoff has an external reference or is actively progressing.'],
    ['label' => 'Finalised / complete', 'body' => 'The bridge handoff record is settled and retained for traceability.']
  ]) ?>
</div>
<div class="stack">
  <div class="section">
    <div class="card-head"><h2>Create chain handoff<?= ops_admin_help_button('Create chain handoff', 'Create a bridge handoff record for an existing mint batch. This does not change wallet state by itself. It creates the traceable record that can carry chain target, notes, transaction references, and attestation hashes.') ?></h2></div>
  <div class="card-body">
    <form method="post" class="stack">
      <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
      <input type="hidden" name="action" value="create_handoff">
      <div class="form-grid">
        <div><label>Mint batch<?= ops_admin_help_button('Mint batch', 'Choose the existing batch that should be associated with this bridge handoff record.') ?></label><select name="batch_id"><?php foreach($batches as $b): ?><option value="<?= (int)$b['id'] ?>"<?= $selectedBatchId===(int)$b['id']?' selected':'' ?>><?= ops_h($b['batch_code'].' · '.$b['batch_label'].' · '.$b['batch_status']) ?></option><?php endforeach; ?></select></div>
        <div><label>Chain target<?= ops_admin_help_button('Chain target', 'The intended ledger/network destination for the handoff. Use this to record where the batch is meant to go, even if the handoff is still manual or preparatory.') ?></label><input name="chain_target" value="besu-prep"></div>
      </div>
      <div><label>Notes</label><textarea name="notes"></textarea></div>
      <div class="actions"><button class="btn" type="submit">Create handoff record</button></div>
    </form>
  </div>

  <div class="section">
    <div class="card-head"><h2>Chain handoffs<?= ops_admin_help_button('Chain handoffs', 'These are the bridge/handoff records already created. Update them as the external reference, attestation hash, or operator notes become known.') ?></h2></div>
  <div class="card-body">
    <div class="table-wrap"><table><thead><tr><th>ID</th><th>Batch</th><th>Status<?= ops_admin_help_button('Handoff status', 'Shows the state of the bridge handoff record itself. Use it to track whether the handoff is still preparatory, has been externally referenced, or is complete.') ?></th><th>Export hash<?= ops_admin_help_button('Export hash', 'A content hash or export-reference fingerprint for the payload associated with the handoff. Useful for audit and tamper checking.') ?></th><th>Update<?= ops_admin_help_button('Update handoff', 'Use this form to record tx/reference IDs, attestation hashes, and notes as the handoff progresses.') ?></th></tr></thead><tbody>
    <?php if(!$handoffs): ?><tr><td colspan="5">No handoff records yet.</td></tr><?php endif; ?>
    <?php foreach($handoffs as $h): ?><tr>
      <td><?= (int)$h['id'] ?></td>
      <td><?= ops_h($h['batch_code'].' · '.$h['batch_label']) ?></td>
      <td><?= ops_h($h['handoff_status']) ?></td>
      <td><code><?= ops_h((string)$h['export_hash']) ?></code></td>
      <td style="min-width:280px">
        <form method="post" class="stack">
      <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
          <input type="hidden" name="action" value="update_handoff">
          <input type="hidden" name="handoff_id" value="<?= (int)$h['id'] ?>">
          <select name="handoff_status"><?php foreach(ops_chain_handoff_statuses() as $s): ?><option value="<?= $s ?>"<?= $h['handoff_status']===$s?' selected':'' ?>><?= $s ?></option><?php endforeach; ?></select>
          <input name="tx_reference" value="<?= ops_h((string)$h['tx_reference']) ?>" placeholder="TX reference / submission ref">
          <input name="attestation_hash" value="<?= ops_h((string)$h['attestation_hash']) ?>" placeholder="Attestation hash">
          <textarea name="notes"><?= ops_h((string)$h['notes']) ?></textarea>
          <button class="btn-secondary" type="submit">Update handoff</button>
        </form>
      </td>
    </tr><?php endforeach; ?>
    </tbody></table></div>
  </div>

  <?php if($payload): ?>
  <div class="section">
    <div class="card-head"><h2>Selected batch payload preview<?= ops_admin_help_button('Payload preview', 'A review/export preview of the selected batch payload. It helps signers and operators confirm what is being handed off. It is not the live mint itself.') ?></h2></div>
  <div class="card-body">
    <textarea rows="20" readonly><?= ops_h(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></textarea>
    <p class="muted">This is the manual pre-blockchain payload preview. It is prepared for signer review and later chain handoff, not live mint execution.</p>
  </div>
  <?php endif; ?>
</div>
<?php $body=ob_get_clean(); ops_render_page('Chain Handoff','chain_handoff',$body,$flash,$flashType);