<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/ops_workflow.php';

ops_require_admin();
$pdo = ops_db();
$flash = null; $flashType = 'ok';
$adminUserId = ops_current_admin_user_id($pdo) ?: null;

function ab_money(int|float $cents): string { return '$' . number_format(((float)$cents)/100, 2); }
function ab_num(float|int $n, int $dp = 0): string { return number_format((float)$n, $dp); }
function ab_rows(PDO $pdo, string $sql, array $params = []): array { try { return ops_fetch_all($pdo, $sql, $params); } catch (Throwable $e) { return []; } }
function ab_row(PDO $pdo, string $sql, array $params = []): ?array { try { return ops_fetch_one($pdo, $sql, $params); } catch (Throwable $e) { return null; } }

function ab_request_rows(PDO $pdo): array {
    return ab_rows($pdo, "SELECT ar.id, ar.member_id, ar.token_class_id, ar.request_status, ar.mint_status, ar.requested_units,
            COALESCE(mrl.approved_units, ar.requested_units) AS approved_units,
            m.member_number, COALESCE(m.full_name, m.email) AS member_name,
            tc.class_code, tc.display_name,
            p.id AS partner_id
        FROM approval_requests ar
        INNER JOIN token_classes tc ON tc.id = ar.token_class_id
        INNER JOIN members m ON m.id = ar.member_id
        LEFT JOIN partners p ON p.member_id = ar.member_id
        LEFT JOIN member_reservation_lines mrl ON mrl.member_id = ar.member_id AND mrl.token_class_id = ar.token_class_id
        WHERE ar.request_status = 'approved'
          AND tc.class_code IN ('ASX_INVESTMENT_COG','RWA_COG')
        ORDER BY ar.id DESC");
}

function ab_asx_sources(PDO $pdo): array {
    if (!ops_has_table($pdo, 'asx_trades') || !ops_has_table($pdo, 'asx_holdings')) return [];
    return ab_rows($pdo, "SELECT t.id, h.ticker AS source_code, h.company_name AS source_name, t.trade_ref, t.trade_date,
            (t.total_cost_cents + COALESCE(t.brokerage_cents,0)) AS source_value_cents,
            FLOOR(((t.total_cost_cents + COALESCE(t.brokerage_cents,0)) - COALESCE((SELECT SUM(sba.allocated_value_cents) FROM stewardship_backing_allocations sba WHERE sba.asx_trade_id = t.id AND sba.allocation_status IN ('reserved','mint_ready','minted')),0)) / 400) AS available_units,
            ((t.total_cost_cents + COALESCE(t.brokerage_cents,0)) - COALESCE((SELECT SUM(sba.allocated_value_cents) FROM stewardship_backing_allocations sba WHERE sba.asx_trade_id = t.id AND sba.allocation_status IN ('reserved','mint_ready','minted')),0)) AS available_value_cents
        FROM asx_trades t
        INNER JOIN asx_holdings h ON h.id = t.holding_id
        WHERE t.status = 'settled'
        AND t.trade_type != 'legacy_seed'
        ORDER BY t.trade_date DESC, t.id DESC");
}

function ab_rwa_sources(PDO $pdo): array {
    if (!ops_has_table($pdo, 'resource_valuation_records') || !ops_has_table($pdo, 'rwa_asset_register')) return [];
    return ab_rows($pdo, "SELECT rv.id, COALESCE(rar.asset_code, rar.asset_key) AS source_code, rar.asset_name AS source_name,
            rv.valuation_date AS trade_date, rv.valuation_basis, rv.source_reference,
            rv.valuation_cents AS source_value_cents,
            FLOOR((rv.valuation_cents - COALESCE((SELECT SUM(sba.allocated_value_cents) FROM stewardship_backing_allocations sba WHERE sba.resource_valuation_record_id = rv.id AND sba.allocation_status IN ('reserved','mint_ready','minted')),0)) / 400) AS available_units,
            (rv.valuation_cents - COALESCE((SELECT SUM(sba.allocated_value_cents) FROM stewardship_backing_allocations sba WHERE sba.resource_valuation_record_id = rv.id AND sba.allocation_status IN ('reserved','mint_ready','minted')),0)) AS available_value_cents
        FROM resource_valuation_records rv
        INNER JOIN rwa_asset_register rar ON rar.resource_id = rv.resource_id
        WHERE rar.status <> 'retired'
          AND rv.id IN (SELECT MAX(rv2.id) FROM resource_valuation_records rv2 GROUP BY rv2.resource_id)
        ORDER BY rv.valuation_date DESC, rv.id DESC");
}

$requests = ab_request_rows($pdo);
$asxSources = array_values(array_filter(ab_asx_sources($pdo), fn($r) => (int)($r['available_units'] ?? 0) > 0 && (int)($r['available_value_cents'] ?? 0) > 0));
$rwaSources = array_values(array_filter(ab_rwa_sources($pdo), fn($r) => (int)($r['available_units'] ?? 0) > 0 && (int)($r['available_value_cents'] ?? 0) > 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'allocate_backing') {
            $approvalId = (int)($_POST['approval_request_id'] ?? 0);
            $sourceType = (string)($_POST['source_type'] ?? '');
            $sourceId = (int)($_POST['source_id'] ?? 0);
            $units = (float)($_POST['allocate_units'] ?? 0);
            if ($approvalId <= 0) throw new RuntimeException('Approval request is required.');
            if ($units <= 0) throw new RuntimeException('Enter a positive number of units to allocate.');
            $status = ops_asset_backing_status_for_approval($pdo, $approvalId);
            if (!$status['required']) throw new RuntimeException('This approval does not require asset backing.');
            if ($status['remaining_units'] <= 0.000001) throw new RuntimeException('This approval is already fully backed.');
            $mode = (string)$status['mode'];
            if ($sourceType !== $mode) throw new RuntimeException('The selected source type does not match the required backing type.');
            $request = ab_row($pdo, 'SELECT ar.id, ar.member_id, ar.token_class_id, p.id AS partner_id FROM approval_requests ar LEFT JOIN partners p ON p.member_id = ar.member_id WHERE ar.id = ? LIMIT 1', [$approvalId]);
            if (!$request) throw new RuntimeException('Approval request not found.');

            if ($sourceType === 'asx_trade') {
                $source = ab_row($pdo, "SELECT t.id,
                        FLOOR(((t.total_cost_cents + COALESCE(t.brokerage_cents,0)) - COALESCE((SELECT SUM(sba.allocated_value_cents) FROM stewardship_backing_allocations sba WHERE sba.asx_trade_id = t.id AND sba.allocation_status IN ('reserved','mint_ready','minted')),0)) / 400) AS available_units,
                        ((t.total_cost_cents + COALESCE(t.brokerage_cents,0)) - COALESCE((SELECT SUM(sba.allocated_value_cents) FROM stewardship_backing_allocations sba WHERE sba.asx_trade_id = t.id AND sba.allocation_status IN ('reserved','mint_ready','minted')),0)) AS available_value_cents
                    FROM asx_trades t WHERE t.id = ? AND t.status = 'settled' AND t.trade_type != 'legacy_seed' LIMIT 1", [$sourceId]);
                if (!$source) throw new RuntimeException('ASX purchase lot not found, not settled, or is a legacy seed lot — trust forming property cannot back token minting.');
            } else {
                $source = ab_row($pdo, "SELECT rv.id,
                        FLOOR((rv.valuation_cents - COALESCE((SELECT SUM(sba.allocated_value_cents) FROM stewardship_backing_allocations sba WHERE sba.resource_valuation_record_id = rv.id AND sba.allocation_status IN ('reserved','mint_ready','minted')),0)) / 400) AS available_units,
                        (rv.valuation_cents - COALESCE((SELECT SUM(sba.allocated_value_cents) FROM stewardship_backing_allocations sba WHERE sba.resource_valuation_record_id = rv.id AND sba.allocation_status IN ('reserved','mint_ready','minted')),0)) AS available_value_cents
                    FROM resource_valuation_records rv WHERE rv.id = ? LIMIT 1", [$sourceId]);
                if (!$source) throw new RuntimeException('RWA valuation record not found.');
            }
            $maxUnits = min((float)$status['remaining_units'], (float)($source['available_units'] ?? 0));
            if ($maxUnits <= 0) throw new RuntimeException('No backing capacity remains on the selected source.');
            if ($units > $maxUnits) throw new RuntimeException('Requested allocation exceeds the available backing capacity.');
            $valueCents = (int)round($units * 400);
            $availableValue = (int)($source['available_value_cents'] ?? 0);
            if ($valueCents > $availableValue) throw new RuntimeException('Requested allocation exceeds the available source value.');

            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO stewardship_backing_allocations (backing_source_type, asx_trade_id, resource_valuation_record_id, approval_request_id, partner_id, token_class_id, allocated_units, allocated_value_cents, allocation_status, notes, created_by_admin_user_id, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'reserved', ?, ?, NOW(), NOW())")
                ->execute([
                    $sourceType,
                    $sourceType === 'asx_trade' ? $sourceId : null,
                    $sourceType === 'rwa_valuation' ? $sourceId : null,
                    $approvalId,
                    (int)($request['partner_id'] ?? 0) ?: null,
                    (int)$request['token_class_id'],
                    $units,
                    $valueCents,
                    'Allocated from admin asset backing console',
                    $adminUserId,
                ]);
            ops_asset_backing_sync_approval_state($pdo, $approvalId);
            $pdo->commit();
            $flash = 'Asset backing allocation recorded.';
            $requests = ab_request_rows($pdo);
            $asxSources = array_values(array_filter(ab_asx_sources($pdo), fn($r) => (int)($r['available_units'] ?? 0) > 0 && (int)($r['available_value_cents'] ?? 0) > 0));
            $rwaSources = array_values(array_filter(ab_rwa_sources($pdo), fn($r) => (int)($r['available_units'] ?? 0) > 0 && (int)($r['available_value_cents'] ?? 0) > 0));
        } elseif ($action === 'release_allocation') {
            $allocationId = (int)($_POST['allocation_id'] ?? 0);
            $row = ab_row($pdo, 'SELECT id, approval_request_id, allocation_status FROM stewardship_backing_allocations WHERE id = ? LIMIT 1', [$allocationId]);
            if (!$row) throw new RuntimeException('Allocation not found.');
            if ((string)$row['allocation_status'] === 'minted') throw new RuntimeException('Minted backing allocations cannot be released.');
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE stewardship_backing_allocations SET allocation_status='cancelled', updated_at = NOW() WHERE id = ?")->execute([$allocationId]);
            ops_asset_backing_sync_approval_state($pdo, (int)$row['approval_request_id']);
            $pdo->commit();
            $flash = 'Backing allocation released.';
            $requests = ab_request_rows($pdo);
            $asxSources = array_values(array_filter(ab_asx_sources($pdo), fn($r) => (int)($r['available_units'] ?? 0) > 0 && (int)($r['available_value_cents'] ?? 0) > 0));
            $rwaSources = array_values(array_filter(ab_rwa_sources($pdo), fn($r) => (int)($r['available_units'] ?? 0) > 0 && (int)($r['available_value_cents'] ?? 0) > 0));
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flash = $e->getMessage();
        $flashType = 'error';
    }
}

$capacityRows = ops_has_table($pdo, 'v_foundation_asset_backing_capacity') ? ab_rows($pdo, 'SELECT * FROM v_foundation_asset_backing_capacity') : [];
$capacity = [];
foreach ($capacityRows as $row) { $capacity[(string)$row['backing_group']] = $row; }
$recentAllocations = ops_has_table($pdo, 'stewardship_backing_allocations') ? ab_rows($pdo, "SELECT sba.*, tc.display_name, m.member_number, COALESCE(m.full_name, m.email) AS member_name,
        at.trade_ref, ah.ticker AS asx_code, ah.company_name,
        rv.valuation_basis, rv.valuation_date, rar.asset_name AS rwa_asset_name, COALESCE(rar.asset_code, rar.asset_key) AS rwa_code
    FROM stewardship_backing_allocations sba
    LEFT JOIN token_classes tc ON tc.id = sba.token_class_id
    LEFT JOIN approval_requests ar ON ar.id = sba.approval_request_id
    LEFT JOIN members m ON m.id = ar.member_id
    LEFT JOIN asx_trades at ON at.id = sba.asx_trade_id
    LEFT JOIN asx_holdings ah ON ah.id = at.holding_id
    LEFT JOIN resource_valuation_records rv ON rv.id = sba.resource_valuation_record_id
    LEFT JOIN rwa_asset_register rar ON rar.resource_id = rv.resource_id
    ORDER BY sba.id DESC LIMIT 40") : [];

ob_start(); ?>
<div class="grid" style="gap:18px">
  <?<?= ops_admin_collapsible_help('Page guide & workflow', [
  ops_admin_info_panel('Stage 3 · Asset backing', 'What this page does', 'This page connects approved ASX and RWA token demand to real settled asset value. It is the control surface between the asset registry, approvals, and execution.', [
      'Allocate real ASX trade lots or RWA valuation value to approved stewardship token requests.',
      'Work at the fixed rule of $4 of backing value per stewardship token.',
      'Only fully backed approvals should move forward into execution.',
      'Published execution turns reserved backing into minted backing and updates stewardship positions.',
  ]),
]) ?>
<?<?= ops_admin_collapsible_help('Page guide & workflow', [
  ops_admin_workflow_panel('Typical workflow', 'Move from approved request to backed request, then into execution.', [
      ['title' => 'Approve the request', 'body' => 'Approvals sets the stewardship token request to approved, but asset-backed classes remain gated until backing is allocated.'],
      ['title' => 'Allocate live backing', 'body' => 'Reserve enough settled ASX purchase value or RWA valuation value to cover the approved units.'],
      ['title' => 'Create the execution request', 'body' => 'Only once the request is fully backed should it move into the execution console.'],
      ['title' => 'Publish the batch', 'body' => 'Publication marks the backing as minted and grows the live stewardship position.'],
  ]),
]) ?>
<?<?= ops_admin_collapsible_help('Page guide & workflow', [
  ops_admin_status_panel('Status guide', 'Use these meanings consistently on backing allocations.', [
      ['label' => 'Awaiting asset backing', 'body' => 'The approval is real, but not yet supported by enough live asset value.'],
      ['label' => 'Reserved', 'body' => 'Asset value has been set aside for the approval but is not yet attached to an execution request.'],
      ['label' => 'Mint ready', 'body' => 'The backing is attached to the execution request and ready to move through the execution console.'],
      ['label' => 'Minted', 'body' => 'The batch has published and the backing is now part of the live minted stewardship position.'],
      ['label' => 'Cancelled', 'body' => 'The reservation was released before minting and no longer counts toward coverage.'],
  ]),
]) ?>
<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px">
    <?php foreach (['ASX','RWA'] as $grp): $c = $capacity[$grp] ?? ['source_book_value_cents'=>0,'cogs_backed'=>0,'cogs_minted'=>0,'cogs_available_to_back'=>0]; ?>
      <div class="card"><div class="card-head"><h2><?= ops_h($grp) ?> capacity</h2></div><div class="card-body">
        <div><strong>Source value:</strong> <?= ab_money((int)$c['source_book_value_cents']) ?></div>
        <div><strong>COG$ backed:</strong> <?= ab_num((float)$c['cogs_backed'], 0) ?></div>
        <div><strong>COG$ minted:</strong> <?= ab_num((float)$c['cogs_minted'], 0) ?></div>
        <div><strong>COG$ free:</strong> <?= ab_num((float)$c['cogs_available_to_back'], 0) ?></div>
      </div></div>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <div class="card-head"><h2>Approved requests awaiting backing <?= ops_admin_help_button('Approved requests awaiting backing', 'Allocate backing here before you create execution requests for ASX or RWA token approvals.') ?></h2></div>
    <div class="card-body table-wrap">
      <table>
        <thead><tr><th>Partner</th><th>Class</th><th>Approved</th><th>Backing status</th><th>Allocate</th></tr></thead>
        <tbody>
        <?php if (!$requests): ?><tr><td colspan="5" class="muted">No approved ASX/RWA requests found.</td></tr><?php else: foreach ($requests as $r): $st = ops_asset_backing_status_for_approval($pdo, (int)$r['id']); $srcs = $st['mode'] === 'asx_trade' ? $asxSources : $rwaSources; ?>
          <tr id="approval-<?= (int)$r['id'] ?>">
            <td><strong><?= ops_h((string)$r['member_name']) ?></strong><div class="muted mono"><?= ops_h((string)$r['member_number']) ?></div></td>
            <td><?= ops_h((string)$r['display_name']) ?></td>
            <td><?= ab_num((float)$st['approved_units'], 0) ?></td>
            <td><span class="chip"><?= ops_h((string)$st['gate_status']) ?></span><div class="muted" style="margin-top:6px">Reserved <?= ab_num((float)$st['allocated_reserved_units'], 0) ?> · Ready <?= ab_num((float)$st['allocated_mint_ready_units'], 0) ?> · Minted <?= ab_num((float)$st['allocated_minted_units'], 0) ?> · Remaining <?= ab_num((float)$st['remaining_units'], 0) ?></div></td>
            <td>
              <form method="post" style="display:grid;gap:8px;min-width:280px">
                <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
                <input type="hidden" name="action" value="allocate_backing">
                <input type="hidden" name="approval_request_id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="source_type" value="<?= ops_h((string)$st['mode']) ?>">
                <select name="source_id">
                  <option value="">Select <?= $st['mode'] === 'asx_trade' ? 'settled ASX lot' : 'RWA valuation' ?>…</option>
                  <?php foreach ($srcs as $s): ?><option value="<?= (int)$s['id'] ?>"><?= ops_h((string)$s['source_code']) ?> — <?= ops_h((string)$s['source_name']) ?> — <?= ab_num((float)$s['available_units'], 0) ?> free (<?= ab_money((int)$s['available_value_cents']) ?>)</option><?php endforeach; ?>
                </select>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                  <input type="number" min="1" step="1" name="allocate_units" value="<?= max(1, (int)round((float)$st['remaining_units'])) ?>" style="width:120px">
                  <button type="submit">Allocate backing</button>
                  <?php if (!empty($st['is_fully_backed'])): ?><a class="mini-btn secondary" href="./execution.php">Go to execution</a><?php endif; ?>
                </div>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h2>Recent backing allocations</h2></div>
    <div class="card-body table-wrap">
      <table>
        <thead><tr><th>Source</th><th>Partner</th><th>Class</th><th>Units</th><th>Value</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
          <?php if (!$recentAllocations): ?><tr><td colspan="7" class="muted">No backing allocations recorded yet.</td></tr><?php else: foreach ($recentAllocations as $a): $src = (($a['backing_source_type'] ?? '') === 'asx_trade') ? ((($a['asx_code'] ?? 'ASX') . ' · ' . ($a['trade_ref'] ?? 'lot'))) : ((($a['rwa_code'] ?? 'RWA') . ' · ' . ($a['rwa_asset_name'] ?? 'valuation'))); ?>
            <tr>
              <td><strong><?= ops_h($src) ?></strong><div class="muted"><?= ops_h((string)($a['trade_date'] ?? $a['valuation_date'] ?? '')) ?></div></td>
              <td><?= ops_h((string)$a['member_name']) ?><div class="muted mono"><?= ops_h((string)$a['member_number']) ?></div></td>
              <td><?= ops_h((string)$a['display_name']) ?></td>
              <td><?= ab_num((float)$a['allocated_units'], 0) ?></td>
              <td><?= ab_money((int)$a['allocated_value_cents']) ?></td>
              <td><span class="chip"><?= ops_h((string)$a['allocation_status']) ?></span></td>
              <td><?php if (!in_array((string)$a['allocation_status'], ['minted','cancelled'], true)): ?><form method="post"><input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>"><input type="hidden" name="action" value="release_allocation"><input type="hidden" name="allocation_id" value="<?= (int)$a['id'] ?>"><button type="submit" class="mini-btn secondary">Release</button></form><?php else: ?><span class="muted">Locked</span><?php endif; ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php
$body = ob_get_clean();
ops_render_page('Asset Backing', 'asset_backing', $body, $flash, $flashType);
