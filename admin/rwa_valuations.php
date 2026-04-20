<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/ops_workflow.php';

ops_require_admin();
$pdo = ops_db();
$adminUserId = ops_admin_id();
$flash = null; $flashType = 'ok';

function rv_money(int|float $cents): string { return '$' . number_format(((float)$cents)/100, 2); }
function rv_num($n, int $dp = 0): string { return number_format((float)$n, $dp); }

$assets = ops_fetch_all($pdo, "SELECT id, asset_code, asset_key, asset_name, resource_id, status FROM rwa_asset_register WHERE status <> 'retired' ORDER BY COALESCE(asset_code, asset_key), asset_name ASC");
$focusAsset = (int)($_GET['asset_id'] ?? 0);
$focusRow = null; foreach ($assets as $a) { if ((int)$a['id'] === $focusAsset) { $focusRow = $a; break; } }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'add_valuation') {
            $assetId = (int)($_POST['asset_id'] ?? 0);
            $valuationDate = trim((string)($_POST['valuation_date'] ?? ''));
            $basis = trim((string)($_POST['valuation_basis'] ?? ''));
            $valuationAmount = trim((string)($_POST['valuation_cents_amount'] ?? ''));
            $valuationUnits = trim((string)($_POST['valuation_units'] ?? ''));
            $sourceReference = trim((string)($_POST['source_reference'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));
            if ($assetId < 1) throw new RuntimeException('Select an RWA asset first.');
            if ($valuationDate === '') throw new RuntimeException('Valuation date is required.');
            if ($basis === '') throw new RuntimeException('Valuation basis is required.');
            if ($valuationAmount === '' || !is_numeric($valuationAmount) || (float)$valuationAmount < 0) throw new RuntimeException('Enter a valid valuation amount.');
            $asset = ops_fetch_one($pdo, 'SELECT * FROM rwa_asset_register WHERE id = ? LIMIT 1', [$assetId]);
            if (!$asset) throw new RuntimeException('Selected asset not found.');
            $resourceId = (int)($asset['resource_id'] ?? 0);
            if ($resourceId < 1) throw new RuntimeException('This asset is not linked to a resource register row yet. Save the asset again on the RWA Assets page first.');
            $valuationCents = (int)round((float)$valuationAmount * 100);
            $valuationUnitsNum = $valuationUnits !== '' ? (float)$valuationUnits : floor($valuationCents / 400);
            $pdo->prepare('INSERT INTO resource_valuation_records (resource_id, valuation_date, valuation_basis, valuation_cents, valuation_units, source_reference, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
                ->execute([$resourceId, $valuationDate, $basis, $valuationCents, $valuationUnitsNum, $sourceReference !== '' ? $sourceReference : null, $notes !== '' ? $notes : null]);
            $flash = 'RWA valuation recorded.';
            header('Location: ./rwa_valuations.php?asset_id=' . $assetId . '&saved=1'); exit;
        }
    } catch (Throwable $e) {
        $flash = $e->getMessage();
        $flashType = 'error';
    }
}
if (isset($_GET['saved']) && !$flash) $flash = 'RWA valuation saved.';

$capacity = ops_table_exists($pdo, 'v_foundation_asset_backing_capacity') ? ops_fetch_one($pdo, "SELECT * FROM v_foundation_asset_backing_capacity WHERE backing_group='RWA' LIMIT 1") : null;
$recentVals = $focusAsset > 0
    ? ops_fetch_all($pdo, "SELECT rv.*, rar.asset_code, rar.asset_key, rar.asset_name FROM resource_valuation_records rv INNER JOIN rwa_asset_register rar ON rar.resource_id = rv.resource_id WHERE rar.id = ? ORDER BY rv.valuation_date DESC, rv.id DESC LIMIT 50", [$focusAsset])
    : ops_fetch_all($pdo, "SELECT rv.*, rar.asset_code, rar.asset_key, rar.asset_name FROM resource_valuation_records rv INNER JOIN rwa_asset_register rar ON rar.resource_id = rv.resource_id ORDER BY rv.valuation_date DESC, rv.id DESC LIMIT 50");
$liveRows = ops_table_exists($pdo, 'v_foundation_rwa_assets_live') ? ops_fetch_all($pdo, 'SELECT * FROM v_foundation_rwa_assets_live ORDER BY asset_code ASC') : [];

ob_start();
?>
<?= ops_admin_collapsible_help('Page guide & workflow', [
  ops_admin_info_panel('RWA Valuation Ledger', 'What this page does', 'Use this page to record the verified value of each registered RWA asset. These valuation records drive the live RWA book value shown to Members and form the economic basis for RWA-token capacity.', [
    'Each submission creates one dated valuation record for the selected asset.',
    'Valuation method and evidence reference should be recorded for auditability.',
    'The latest valuation per asset is used for live capacity calculations.',
    'Older records are retained as the historical valuation trail.',
  ]),
  ops_admin_workflow_panel('Typical workflow', 'Follow this sequence when working with this page.', [
    ['title' => 'Select the asset', 'body' => 'Choose the registered RWA asset being valued.'],
    ['title' => 'Enter the valuation', 'body' => 'Record the value in AUD cents, date, method, and evidence reference.'],
    ['title' => 'Review live values', 'body' => 'The live table below shows the current position from the latest valuation per asset.'],
    ['title' => 'Monitor capacity', 'body' => 'RWA COG$ capacity updates automatically as new valuations are recorded.'],
  ]),
  ops_admin_guide_panel('How to use this page', 'Each section serves a different purpose.', [
    ['title' => 'Add valuation form', 'body' => 'One submission = one dated valuation record. Not an edit of a previous one.'],
    ['title' => 'Current live values', 'body' => 'Latest valuation per asset — this is what drives live capacity.'],
    ['title' => 'Recent valuation records', 'body' => 'Full historical trail of all valuation submissions.'],
  ]),
  ops_admin_status_panel('Status guide', 'These statuses appear throughout this page.', [
    ['label' => 'Active valuation', 'body' => 'The most recent record for an asset — used for live capacity.'],
    ['label' => 'Superseded', 'body' => 'An older valuation replaced by a newer one. Retained for audit history.'],
    ['label' => 'Pending verification', 'body' => 'Valuation submitted but evidence not yet confirmed.'],
  ]),
]) ?>

<div class="grid" style="gap:18px">
  



<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px">
    <div class="card"><div class="card-head"><h2>RWA verified value</h2></div><div class="card-body"><div class="stat-value"><?= rv_money((int)($capacity['source_book_value_cents'] ?? 0)) ?></div></div></div>
    <div class="card"><div class="card-head"><h2>COG$ backed</h2></div><div class="card-body"><div class="stat-value"><?= rv_num((float)($capacity['cogs_backed'] ?? 0), 0) ?></div></div></div>
    <div class="card"><div class="card-head"><h2>COG$ minted</h2></div><div class="card-body"><div class="stat-value"><?= rv_num((float)($capacity['cogs_minted'] ?? 0), 0) ?></div></div></div>
    <div class="card"><div class="card-head"><h2>COG$ available <?= ops_admin_help_button('COG$ available', 'Calculated from the latest live RWA valuation figures at the $4-per-token rule.') ?></h2></div><div class="card-body"><div class="stat-value"><?= rv_num((float)($capacity['cogs_available_to_back'] ?? 0), 0) ?></div></div></div>
  </div>

  <div class="card">
    <div class="card-head"><h2>Add RWA valuation <?= ops_admin_help_button('RWA valuation form', 'Each submission creates one dated valuation record for the selected asset.') ?></h2></div>
    <div class="card-body">
      <form method="post" class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px">
        <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>"><input type="hidden" name="action" value="add_valuation">
        <label><div class="muted" style="margin-bottom:6px">RWA asset</div><select name="asset_id" style="width:100%"><option value="">Select…</option><?php foreach ($assets as $a): ?><option value="<?= (int)$a['id'] ?>"<?= $focusAsset === (int)$a['id'] ? ' selected' : '' ?>><?= ops_h((string)($a['asset_code'] ?: $a['asset_key'])) ?> — <?= ops_h((string)$a['asset_name']) ?></option><?php endforeach; ?></select></label>
        <label><div class="muted" style="margin-bottom:6px">Valuation date</div><input type="date" name="valuation_date" value="<?= date('Y-m-d') ?>" style="width:100%"></label>
        <label><div class="muted" style="margin-bottom:6px">Valuation basis</div><input type="text" name="valuation_basis" placeholder="Independent report" style="width:100%"></label>
        <label><div class="muted" style="margin-bottom:6px">Valuation amount</div><input type="number" min="0" step="0.01" name="valuation_cents_amount" placeholder="250000.00" style="width:100%"></label>
        <label><div class="muted" style="margin-bottom:6px">Valuation units (optional)</div><input type="number" min="0" step="0.000001" name="valuation_units" placeholder="Auto-calc from $4 if blank" style="width:100%"></label>
        <label><div class="muted" style="margin-bottom:6px">Source reference</div><input type="text" name="source_reference" placeholder="Report / deed / valuation ref" style="width:100%"></label>
        <label style="grid-column:1/-1"><div class="muted" style="margin-bottom:6px">Notes</div><textarea name="notes" rows="4" style="width:100%"></textarea></label>
        <div style="grid-column:1/-1;display:flex;gap:10px;flex-wrap:wrap"><button type="submit">Record valuation</button><a class="mini-btn secondary" href="./rwa_assets.php">Manage RWA Assets</a></div>
      </form>
      <?php if ($focusRow): ?><p class="muted" style="margin-top:12px">Selected asset: <strong><?= ops_h((string)($focusRow['asset_code'] ?: $focusRow['asset_key'])) ?></strong> — <?= ops_h((string)$focusRow['asset_name']) ?></p><?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h2>Current live RWA values <?= ops_admin_help_button('Current live RWA values', 'These values come from the latest valuation per asset and represent the live position used by the display layer and backing-capacity view.') ?></h2></div>
    <div class="card-body table-wrap">
      <table>
        <thead><tr><th>Code</th><th>Asset</th><th>Verified value</th><th>Basis</th><th>Valuation date</th><th>COG$ available</th></tr></thead>
        <tbody>
          <?php if (!$liveRows): ?><tr><td colspan="6" class="muted">No live RWA valuations yet.</td></tr><?php else: foreach ($liveRows as $r): ?>
            <tr>
              <td><strong><?= ops_h((string)$r['asset_code']) ?></strong></td>
              <td><?= ops_h((string)$r['pool_name']) ?></td>
              <td><?= rv_money((int)$r['verified_valuation_cents']) ?></td>
              <td><?= ops_h((string)$r['valuation_basis']) ?></td>
              <td><?= ops_h((string)$r['valuation_date']) ?></td>
              <td><?= rv_num((float)$r['cogs_available_to_back'], 0) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h2>Recent valuation records</h2></div>
    <div class="card-body table-wrap">
      <table>
        <thead><tr><th>Date</th><th>Asset</th><th>Basis</th><th>Value</th><th>Units</th><th>Source ref</th></tr></thead>
        <tbody>
          <?php if (!$recentVals): ?><tr><td colspan="6" class="muted">No valuation records entered yet.</td></tr><?php else: foreach ($recentVals as $v): ?>
            <tr>
              <td><?= ops_h((string)$v['valuation_date']) ?></td>
              <td><strong><?= ops_h((string)($v['asset_code'] ?: $v['asset_key'])) ?></strong><div class="muted"><?= ops_h((string)$v['asset_name']) ?></div></td>
              <td><?= ops_h((string)$v['valuation_basis']) ?></td>
              <td><?= rv_money((int)$v['valuation_cents']) ?></td>
              <td><?= rv_num((float)$v['valuation_units'], 6) ?></td>
              <td><?= ops_h((string)($v['source_reference'] ?? '')) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php
$body = ob_get_clean();
ops_render_page('RWA Valuations', 'rwa_valuations', $body, $flash, $flashType);
