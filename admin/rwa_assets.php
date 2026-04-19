<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/ops_workflow.php';

ops_require_admin();
$pdo = ops_db();
$adminUserId = ops_admin_id();
$flash = null; $flashType = 'ok';

function rwa_money(int|float $cents): string { return '$' . number_format(((float)$cents)/100, 2); }
function rwa_num($n, int $dp = 0): string { return number_format((float)$n, $dp); }
function rwa_slug(string $v): string {
    $v = strtoupper(trim($v));
    $v = preg_replace('/[^A-Z0-9]+/', '_', $v) ?: 'RWA';
    return trim($v, '_');
}
function rwa_category_id(PDO $pdo): ?int {
    $row = ops_fetch_one($pdo, "SELECT id FROM resource_categories WHERE category_key='rwa_asset' LIMIT 1");
    return $row ? (int)$row['id'] : null;
}
function rwa_sync_resource(PDO $pdo, int $assetId): void {
    $asset = ops_fetch_one($pdo, 'SELECT * FROM rwa_asset_register WHERE id = ? LIMIT 1', [$assetId]);
    if (!$asset) return;
    $catId = rwa_category_id($pdo);
    if (!$catId) return;
    if (!empty($asset['resource_id'])) {
        $pdo->prepare('UPDATE resource_register SET resource_key=?, category_id=?, resource_name=?, external_ref=?, subject_table=?, subject_id=?, status=?, notes=?, updated_at=NOW() WHERE id=?')
            ->execute([
                'RWA_' . rwa_slug((string)($asset['asset_code'] ?: $asset['asset_key'] ?: $asset['asset_name'])),
                $catId,
                $asset['asset_name'],
                $asset['asset_code'] ?: $asset['asset_key'],
                'rwa_asset_register',
                $assetId,
                in_array($asset['status'], ['draft','active','retired'], true) ? $asset['status'] : 'draft',
                $asset['notes'],
                (int)$asset['resource_id'],
            ]);
    } else {
        $pdo->prepare('INSERT INTO resource_register (resource_key, category_id, resource_name, external_ref, subject_table, subject_id, status, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
            ->execute([
                'RWA_' . rwa_slug((string)($asset['asset_code'] ?: $asset['asset_key'] ?: $asset['asset_name'])),
                $catId,
                $asset['asset_name'],
                $asset['asset_code'] ?: $asset['asset_key'],
                'rwa_asset_register',
                $assetId,
                in_array($asset['status'], ['draft','active','retired'], true) ? $asset['status'] : 'draft',
                $asset['notes'],
            ]);
        $resourceId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE rwa_asset_register SET resource_id=?, updated_at=NOW() WHERE id=?')->execute([$resourceId, $assetId]);
    }
}

$hasAssetCode = ops_has_column($pdo, 'rwa_asset_register', 'asset_code');
$hasLiveView = ops_table_exists($pdo, 'v_foundation_rwa_assets_live');
$editId = (int)($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_asset') {
            $id = (int)($_POST['asset_id'] ?? 0);
            $assetCode = strtoupper(trim((string)($_POST['asset_code'] ?? '')));
            $assetKey = trim((string)($_POST['asset_key'] ?? ''));
            $assetName = trim((string)($_POST['asset_name'] ?? ''));
            $assetType = (string)($_POST['asset_type'] ?? 'other');
            $jurisdiction = strtoupper(trim((string)($_POST['jurisdiction'] ?? '')));
            $location = trim((string)($_POST['location_summary'] ?? ''));
            $status = (string)($_POST['status'] ?? 'draft');
            $notes = trim((string)($_POST['notes'] ?? ''));
            if ($assetName === '') throw new RuntimeException('Asset name is required.');
            if ($assetCode === '') throw new RuntimeException('3-letter RWA code is required.');
            if (!preg_match('/^[A-Z0-9]{3,6}$/', $assetCode)) throw new RuntimeException('Use a short RWA code such as TIM or LGMR.');
            if ($assetKey === '') $assetKey = rwa_slug($assetCode . '_' . $assetName);
            if (!in_array($assetType, ['land','mineral_right','infrastructure','other'], true)) $assetType = 'other';
            if (!in_array($status, ['draft','active','retired'], true)) $status = 'draft';
            if ($hasAssetCode) {
                $dup = ops_fetch_one($pdo, 'SELECT id FROM rwa_asset_register WHERE asset_code = ? AND id <> ? LIMIT 1', [$assetCode, $id]);
                if ($dup) throw new RuntimeException('That RWA code already exists.');
            }
            $dupKey = ops_fetch_one($pdo, 'SELECT id FROM rwa_asset_register WHERE asset_key = ? AND id <> ? LIMIT 1', [$assetKey, $id]);
            if ($dupKey) throw new RuntimeException('That asset key already exists.');

            $pdo->beginTransaction();
            if ($id > 0) {
                $sql = 'UPDATE rwa_asset_register SET asset_key=?, asset_name=?, asset_type=?, jurisdiction=?, location_summary=?, status=?, notes=?, updated_at=NOW()';
                $params = [$assetKey,$assetName,$assetType,$jurisdiction !== '' ? $jurisdiction : null,$location !== '' ? $location : null,$status,$notes !== '' ? $notes : null];
                if ($hasAssetCode) { $sql .= ', asset_code=?'; $params[] = $assetCode; }
                $sql .= ' WHERE id=?'; $params[] = $id;
                $pdo->prepare($sql)->execute($params);
                rwa_sync_resource($pdo, $id);
                $flash = 'RWA asset updated.';
            } else {
                $fields = ['resource_id','asset_key','asset_name','asset_type','jurisdiction','location_summary','status','notes','created_at','updated_at'];
                $vals = ['NULL','?','?','?','?','?','?','?','NOW()','NOW()'];
                $params = [$assetKey,$assetName,$assetType,$jurisdiction !== '' ? $jurisdiction : null,$location !== '' ? $location : null,$status,$notes !== '' ? $notes : null];
                if ($hasAssetCode) { array_splice($fields,1,0,'asset_code'); array_splice($vals,1,0,'?'); array_splice($params,1,0,$assetCode); }
                $pdo->prepare('INSERT INTO rwa_asset_register (' . implode(',', $fields) . ') VALUES (' . implode(',', $vals) . ')')->execute($params);
                $id = (int)$pdo->lastInsertId();
                rwa_sync_resource($pdo, $id);
                $flash = 'RWA asset created.';
            }
            $pdo->commit();
            header('Location: ./rwa_assets.php?edit=' . $id . '&saved=1'); exit;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flash = $e->getMessage();
        $flashType = 'error';
    }
}
if (isset($_GET['saved']) && !$flash) $flash = 'RWA asset saved.';

$summary = ['lines'=>0,'book'=>0,'backed'=>0,'minted'=>0,'available'=>0];
$rows = [];
if ($hasLiveView) {
    $rows = ops_fetch_all($pdo, 'SELECT rar.id, rar.status, rar.notes, live.* FROM v_foundation_rwa_assets_live live INNER JOIN rwa_asset_register rar ON rar.id = live.rwa_asset_id ORDER BY live.asset_code ASC');
    foreach ($rows as $r) {
        $summary['lines']++;
        $summary['book'] += (int)($r['verified_valuation_cents'] ?? 0);
        $summary['backed'] += (float)($r['cogs_backed'] ?? 0);
        $summary['minted'] += (float)($r['cogs_minted'] ?? 0);
        $summary['available'] += (float)($r['cogs_available_to_back'] ?? 0);
    }
} else {
    $rows = ops_fetch_all($pdo, 'SELECT * FROM rwa_asset_register ORDER BY asset_key ASC');
}
$editRow = null;
foreach ($rows as $r) { if ((int)($r['id'] ?? 0) === $editId) { $editRow = $r; break; } }
if (!$editRow) { $editRow = ['id'=>'','asset_code'=>'','asset_key'=>'','asset_name'=>'','asset_type'=>'other','jurisdiction'=>'','location_summary'=>'','status'=>'draft','notes'=>'']; }

ob_start();
?>
<?= ops_admin_collapsible_help('Page guide & workflow', [
  ops_admin_info_panel('RWA Asset Register', 'What this page does', 'Use this page to register each real-world asset held or stewarded by the partnership. Each line becomes the identity anchor for valuation records, live display, and RWA-token capacity.', [
    'Create one registry line per distinct real-world asset.',
    'Asset type, location, and First Nations approval status are recorded here.',
    'Valuation records are entered separately on the RWA Valuations page.',
    'Capacity calculations use the latest valuation per asset.',
  ]),
  ops_admin_workflow_panel('Typical workflow', 'Follow this sequence when working with this page.', [
    ['title' => 'Register the asset', 'body' => 'Create the identity record here with type, location, and approval status.'],
    ['title' => 'Record valuations', 'body' => 'Go to RWA Valuations to add dated valuation records for each asset.'],
    ['title' => 'Review live register', 'body' => 'The table below shows the current position per asset from the latest valuation.'],
    ['title' => 'Monitor capacity', 'body' => 'RWA COG$ capacity updates as new valuations are recorded.'],
  ]),
  ops_admin_guide_panel('How to use this page', 'Each section serves a different purpose.', [
    ['title' => 'Asset form', 'body' => 'Create or edit an asset identity record. Not the valuation.'],
    ['title' => 'Live RWA register', 'body' => 'Current position per asset — values come from the latest valuation record.'],
    ['title' => 'COG$ capacity', 'body' => 'Unallocated RWA token capacity at $4 of verified value per COG$.'],
  ]),
  ops_admin_status_panel('Status guide', 'These statuses appear throughout this page.', [
    ['label' => 'Active', 'body' => 'Asset is registered and contributes to the portfolio.'],
    ['label' => 'Pending FNAC / FPIC', 'body' => 'Awaiting First Nations Advisory Council or FPIC approval.'],
    ['label' => 'Suspended / retired', 'body' => 'Asset is no longer active.'],
  ]),
]) ?>

<div class="grid" style="gap:18px">
  



<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px">
    <div class="card"><div class="card-head"><h2>Asset lines</h2></div><div class="card-body"><div class="stat-value"><?= rwa_num($summary['lines']) ?></div></div></div>
    <div class="card"><div class="card-head"><h2>Verified value <?= ops_admin_help_button('Verified value', 'This comes from the latest valuation record for each active RWA asset.') ?></h2></div><div class="card-body"><div class="stat-value"><?= rwa_money($summary['book']) ?></div></div></div>
    <div class="card"><div class="card-head"><h2>COG$ backed</h2></div><div class="card-body"><div class="stat-value"><?= rwa_num($summary['backed']) ?></div></div></div>
    <div class="card"><div class="card-head"><h2>COG$ available <?= ops_admin_help_button('COG$ available', 'Calculated from the latest valuation basis using the Stage 1 live backing views at $4 of value per RWA COG$.') ?></h2></div><div class="card-body"><div class="stat-value"><?= rwa_num($summary['available']) ?></div></div></div>
  </div>

  <div class="card">
    <div class="card-head"><h2><?= $editId > 0 ? 'Edit RWA asset' : 'Add RWA asset' ?> <?= ops_admin_help_button('RWA asset form', 'Create the registry identity first. Book value is entered separately on the RWA Valuations page.') ?></h2></div>
    <div class="card-body">
      <form method="post" class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px">
        <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>"><input type="hidden" name="action" value="save_asset"><input type="hidden" name="asset_id" value="<?= ops_h((string)$editRow['id']) ?>">
        <label><div class="muted" style="margin-bottom:6px">RWA code <?= ops_admin_help_button('RWA code', 'Use a short code such as TIM, GOL, or NOD.') ?></div><input type="text" name="asset_code" value="<?= ops_h((string)($editRow['asset_code'] ?? '')) ?>" placeholder="TIM" style="width:100%"></label>
        <label><div class="muted" style="margin-bottom:6px">Asset key</div><input type="text" name="asset_key" value="<?= ops_h((string)($editRow['asset_key'] ?? '')) ?>" placeholder="TIMBARA_MINERAL_RIGHT" style="width:100%"></label>
        <label><div class="muted" style="margin-bottom:6px">Asset name</div><input type="text" name="asset_name" value="<?= ops_h((string)($editRow['pool_name'] ?? $editRow['asset_name'] ?? '')) ?>" placeholder="Timbara Mineral Right" style="width:100%"></label>
        <label><div class="muted" style="margin-bottom:6px">Asset type</div><select name="asset_type" style="width:100%"><option value="land"<?= (($editRow['asset_type'] ?? '')==='land')?' selected':'' ?>>Land</option><option value="mineral_right"<?= (($editRow['asset_type'] ?? '')==='mineral_right')?' selected':'' ?>>Mineral right</option><option value="infrastructure"<?= (($editRow['asset_type'] ?? '')==='infrastructure')?' selected':'' ?>>Infrastructure</option><option value="other"<?= (($editRow['asset_type'] ?? '')==='other')?' selected':'' ?>>Other</option></select></label>
        <label><div class="muted" style="margin-bottom:6px">Jurisdiction</div><input type="text" name="jurisdiction" value="<?= ops_h((string)($editRow['jurisdiction'] ?? '')) ?>" placeholder="NSW" style="width:100%"></label>
        <label><div class="muted" style="margin-bottom:6px">Status</div><select name="status" style="width:100%"><option value="draft"<?= (($editRow['status'] ?? '')==='draft')?' selected':'' ?>>Draft</option><option value="active"<?= (($editRow['status'] ?? '')==='active')?' selected':'' ?>>Active</option><option value="retired"<?= (($editRow['status'] ?? '')==='retired')?' selected':'' ?>>Retired</option></select></label>
        <label style="grid-column:1/-1"><div class="muted" style="margin-bottom:6px">Location summary</div><input type="text" name="location_summary" value="<?= ops_h((string)($editRow['location_summary'] ?? '')) ?>" placeholder="Drake / Clarence River region" style="width:100%"></label>
        <label style="grid-column:1/-1"><div class="muted" style="margin-bottom:6px">Notes</div><textarea name="notes" rows="4" style="width:100%"><?= ops_h((string)($editRow['notes'] ?? '')) ?></textarea></label>
        <div style="grid-column:1/-1;display:flex;gap:10px;flex-wrap:wrap"><button type="submit">Save asset</button><a class="mini-btn secondary" href="./rwa_assets.php">Clear</a><a class="mini-btn secondary" href="./rwa_valuations.php">Go to RWA Valuations</a></div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h2>Live RWA register <?= ops_admin_help_button('Live RWA register', 'This is the current asset-level register view. Values shown here come from the latest valuation record per asset.') ?></h2></div>
    <div class="card-body table-wrap">
      <table>
        <thead><tr><th>Code</th><th>Asset</th><th>Type</th><th>Verified value</th><th>Basis</th><th>COG$ backed</th><th>COG$ minted</th><th>COG$ available</th><th></th></tr></thead>
        <tbody>
          <?php if (!$rows): ?><tr><td colspan="9" class="muted">No RWA assets registered yet.</td></tr><?php else: foreach ($rows as $r): ?>
            <tr>
              <td><strong><?= ops_h((string)($r['asset_code'] ?? $r['asset_key'] ?? '')) ?></strong></td>
              <td><?= ops_h((string)($r['pool_name'] ?? $r['asset_name'] ?? '')) ?><div class="muted"><?= ops_h((string)($r['location_summary'] ?? '')) ?></div></td>
              <td><?= ops_h((string)($r['asset_type'] ?? '')) ?></td>
              <td><?= rwa_money((int)($r['verified_valuation_cents'] ?? 0)) ?></td>
              <td><?= ops_h((string)($r['valuation_basis'] ?? '')) ?><div class="muted"><?= ops_h((string)($r['valuation_date'] ?? '')) ?></div></td>
              <td><?= rwa_num((float)($r['cogs_backed'] ?? 0), 0) ?></td>
              <td><?= rwa_num((float)($r['cogs_minted'] ?? 0), 0) ?></td>
              <td><?= rwa_num((float)($r['cogs_available_to_back'] ?? 0), 0) ?></td>
              <td><a class="mini-btn secondary" href="./rwa_assets.php?edit=<?= (int)$r['id'] ?>">Edit</a></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php
$body = ob_get_clean();
ops_render_page('RWA Assets', 'rwa_assets', $body, $flash, $flashType);
