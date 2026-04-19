<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/ops_workflow.php';
ops_require_admin();
$pdo = ops_db();
$flash = null; $flashType = 'ok';

if (!function_exists('inf_rows')) {
    function inf_rows(PDO $pdo, string $sql, array $params = []): array {
        try { return ops_fetch_all($pdo, $sql, $params); } catch (Throwable $e) { return []; }
    }
}
if (!function_exists('inf_one')) {
    function inf_one(PDO $pdo, string $sql, array $params = []): ?array {
        try { return ops_fetch_one($pdo, $sql, $params); } catch (Throwable $e) { return null; }
    }
}
if (!function_exists('inf_val')) {
    function inf_val(PDO $pdo, string $sql, array $params = []) {
        try { return ops_fetch_val($pdo, $sql, $params); } catch (Throwable $e) { return null; }
    }
}
if (!function_exists('inf_key')) {
    function inf_key(string $prefix): string {
        return strtoupper($prefix) . '-' . gmdate('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }
}
if (!function_exists('inf_redirect')) {
    function inf_redirect(string $flash, string $type = 'ok'): never {
        $qs = http_build_query(['flash' => $flash, 'type' => $type]);
        header('Location: ' . admin_url('infrastructure.php?' . $qs));
        exit;
    }
}

$adminUserId = ops_current_admin_user_id($pdo);
$canManage = ops_admin_can($pdo, 'infrastructure.manage');
if (isset($_GET['flash'])) { $flash = (string)$_GET['flash']; $flashType = (string)($_GET['type'] ?? 'ok'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
    if (!$canManage) {
        inf_redirect('You do not have permission to manage sovereign infrastructure.', 'error');
    }
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'record_health_check') {
            if (!ops_has_table($pdo, 'node_health_checks')) throw new RuntimeException('node_health_checks table not available.');
            $nodeId = (int)($_POST['node_id'] ?? 0);
            $healthStatus = trim((string)($_POST['health_status'] ?? ''));
            $summary = trim((string)($_POST['summary'] ?? ''));
            $details = trim((string)($_POST['details_json'] ?? ''));
            if ($nodeId < 1) throw new RuntimeException('Select a node.');
            $valid = ['ok','warning','critical','offline'];
            if (!in_array($healthStatus, $valid, true)) throw new RuntimeException('Select a valid health status.');
            $detailsJson = null;
            if ($details !== '') {
                json_decode($details, true, 512, JSON_THROW_ON_ERROR);
                $detailsJson = $details;
            }
            $pdo->prepare('INSERT INTO node_health_checks (node_id, health_status, summary, details_json, checked_at) VALUES (?,?,?,?,NOW())')
                ->execute([$nodeId, $healthStatus, $summary !== '' ? $summary : null, $detailsJson]);
            inf_redirect('Node health check recorded.');
        }
        if ($action === 'create_node_incident') {
            if (!ops_has_table($pdo, 'node_incidents')) throw new RuntimeException('node_incidents table not available.');
            $nodeId = (int)($_POST['node_id'] ?? 0);
            $severity = trim((string)($_POST['severity'] ?? 'medium'));
            $summaryTxt = trim((string)($_POST['summary'] ?? ''));
            $details = trim((string)($_POST['details'] ?? ''));
            if ($nodeId < 1) throw new RuntimeException('Select a node.');
            if ($summaryTxt === '') throw new RuntimeException('Incident summary is required.');
            $valid = ['low','medium','high','critical'];
            if (!in_array($severity, $valid, true)) throw new RuntimeException('Select a valid severity.');
            $incidentKey = trim((string)($_POST['incident_key'] ?? '')) ?: inf_key('INC');
            $pdo->prepare('INSERT INTO node_incidents (node_id, incident_key, severity, status, summary, details, created_at, updated_at) VALUES (?,?,?,\'open\',?,?,NOW(),NOW())')
                ->execute([$nodeId, $incidentKey, $severity, $summaryTxt, $details !== '' ? $details : null]);
            inf_redirect('Node incident opened: ' . $incidentKey . '.');
        }
        if ($action === 'create_key_ceremony') {
            if (!ops_has_table($pdo, 'key_ceremonies')) throw new RuntimeException('key_ceremonies table not available.');
            $ceremonyKey = trim((string)($_POST['ceremony_key'] ?? '')) ?: inf_key('CER');
            $hsmDeviceId = (int)($_POST['hsm_device_id'] ?? 0);
            $locationId = (int)($_POST['key_custody_location_id'] ?? 0);
            $ceremonyType = trim((string)($_POST['ceremony_type'] ?? 'generation'));
            $status = trim((string)($_POST['status'] ?? 'planned'));
            $heldAt = trim((string)($_POST['held_at'] ?? ''));
            $summaryTxt = trim((string)($_POST['summary'] ?? ''));
            $evidenceHash = trim((string)($_POST['evidence_hash'] ?? ''));
            if ($hsmDeviceId < 1) throw new RuntimeException('Select an HSM device.');
            if ($locationId < 1) throw new RuntimeException('Select a custody location.');
            if (!in_array($ceremonyType, ['generation','rotation','recovery','decommission'], true)) throw new RuntimeException('Invalid ceremony type.');
            if (!in_array($status, ['planned','in_progress','completed','failed'], true)) throw new RuntimeException('Invalid ceremony status.');
            $pdo->prepare('INSERT INTO key_ceremonies (ceremony_key, hsm_device_id, key_custody_location_id, ceremony_type, status, held_at, summary, evidence_hash, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())')
                ->execute([$ceremonyKey, $hsmDeviceId, $locationId, $ceremonyType, $status, $heldAt !== '' ? $heldAt : null, $summaryTxt !== '' ? $summaryTxt : null, $evidenceHash !== '' ? $evidenceHash : null]);
            inf_redirect('Key ceremony recorded: ' . $ceremonyKey . '.');
        }
        if ($action === 'assign_node_shard') {
            if (!ops_has_table($pdo, 'node_shard_assignments')) throw new RuntimeException('node_shard_assignments table not available.');
            $nodeId = (int)($_POST['node_id'] ?? 0);
            $shardId = (int)($_POST['shard_id'] ?? 0);
            $assignmentRole = trim((string)($_POST['assignment_role'] ?? 'validator'));
            $status = trim((string)($_POST['status'] ?? 'planned'));
            if ($nodeId < 1 || $shardId < 1) throw new RuntimeException('Select a node and shard.');
            if (!in_array($assignmentRole, ['validator','primary_signer','replica','observer'], true)) throw new RuntimeException('Invalid assignment role.');
            if (!in_array($status, ['planned','active','suspended','retired'], true)) throw new RuntimeException('Invalid assignment status.');
            $pdo->prepare('INSERT INTO node_shard_assignments (node_id, shard_id, assignment_role, status, created_at, updated_at) VALUES (?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = NOW()')
                ->execute([$nodeId, $shardId, $assignmentRole, $status]);
            inf_redirect('Node/shard assignment saved.');
        }
        if ($action === 'open_quorum_request') {
            if (!ops_has_table($pdo, 'quorum_requests')) throw new RuntimeException('quorum_requests table not available.');
            $requestKey = trim((string)($_POST['request_key'] ?? '')) ?: inf_key('QR');
            $executionRequestId = (int)($_POST['execution_request_id'] ?? 0);
            $executionBatchId = (int)($_POST['execution_batch_id'] ?? 0);
            $requiredSignatures = max(1, (int)($_POST['required_signatures'] ?? 3));
            $notes = trim((string)($_POST['notes'] ?? ''));
            if ($executionRequestId < 1 && $executionBatchId < 1) throw new RuntimeException('Select an execution request or batch.');
            $pdo->prepare('INSERT INTO quorum_requests (request_key, execution_request_id, execution_batch_id, required_signatures, status, opened_at, notes) VALUES (?,?,?,?,\'open\',NOW(),?)')
                ->execute([$requestKey, $executionRequestId > 0 ? $executionRequestId : null, $executionBatchId > 0 ? $executionBatchId : null, $requiredSignatures, $notes !== '' ? $notes : null]);
            inf_redirect('Quorum request opened: ' . $requestKey . '.');
        }
        throw new RuntimeException('Unknown infrastructure action.');
    } catch (Throwable $e) {
        $flash = $e->getMessage();
        $flashType = 'error';
    }
}

$rows = ops_has_table($pdo, 'v_phase1_sovereign_infrastructure_status') ? inf_rows($pdo, 'SELECT * FROM v_phase1_sovereign_infrastructure_status ORDER BY network_name, node_name LIMIT 100') : [];
$nodes = ops_has_table($pdo, 'ledger_nodes') ? inf_rows($pdo, 'SELECT id, node_key, display_name, node_role, status, fnac_status, fpic_status FROM ledger_nodes ORDER BY display_name') : [];
$shards = ops_has_table($pdo, 'ledger_shards') ? inf_rows($pdo, 'SELECT id, shard_key, display_name, status FROM ledger_shards ORDER BY display_name') : [];
$hsms = ops_has_table($pdo, 'hsm_devices') ? inf_rows($pdo, 'SELECT id, device_key, display_name, status FROM hsm_devices ORDER BY display_name') : [];
$locations = ops_has_table($pdo, 'key_custody_locations') ? inf_rows($pdo, 'SELECT id, location_key, display_name, fnac_status, fpic_status FROM key_custody_locations ORDER BY display_name') : [];
$keyCeremonies = ops_has_table($pdo, 'key_ceremonies') ? inf_rows($pdo, 'SELECT * FROM key_ceremonies ORDER BY id DESC LIMIT 20') : [];
$incidents = ops_has_table($pdo, 'node_incidents') ? inf_rows($pdo, 'SELECT ni.*, ln.display_name AS node_name FROM node_incidents ni LEFT JOIN ledger_nodes ln ON ln.id = ni.node_id ORDER BY ni.id DESC LIMIT 20') : [];
$healthChecks = ops_has_table($pdo, 'node_health_checks') ? inf_rows($pdo, 'SELECT nh.*, ln.display_name AS node_name FROM node_health_checks nh LEFT JOIN ledger_nodes ln ON ln.id = nh.node_id ORDER BY nh.id DESC LIMIT 20') : [];
$assignments = ops_has_table($pdo, 'node_shard_assignments') ? inf_rows($pdo, 'SELECT nsa.*, ln.display_name AS node_name, ls.display_name AS shard_name FROM node_shard_assignments nsa LEFT JOIN ledger_nodes ln ON ln.id = nsa.node_id LEFT JOIN ledger_shards ls ON ls.id = nsa.shard_id ORDER BY nsa.id DESC LIMIT 20') : [];
$executionRequests = ops_has_table($pdo, 'execution_requests') ? inf_rows($pdo, "SELECT id, request_key, execution_status FROM execution_requests WHERE execution_status NOT IN ('published','archived') ORDER BY id DESC LIMIT 50") : [];
$executionBatches = ops_has_table($pdo, 'execution_batches') ? inf_rows($pdo, "SELECT id, batch_key, batch_status FROM execution_batches WHERE batch_status NOT IN ('published','failed') ORDER BY id DESC LIMIT 50") : [];
$quorumRequests = ops_has_table($pdo, 'quorum_requests') ? inf_rows($pdo, 'SELECT qr.*, er.request_key AS execution_request_key, eb.batch_key AS execution_batch_key FROM quorum_requests qr LEFT JOIN execution_requests er ON er.id = qr.execution_request_id LEFT JOIN execution_batches eb ON eb.id = qr.execution_batch_id ORDER BY qr.id DESC LIMIT 20') : [];

$nodeCount = count($nodes);
$liveNodeCount = 0; $activeShardAssignments = 0; $openIncidentCount = 0; $openQuorumCount = 0;
foreach ($nodes as $node) if (($node['status'] ?? '') === 'live') $liveNodeCount++;
foreach ($assignments as $a) if (($a['status'] ?? '') === 'active') $activeShardAssignments++;
foreach ($incidents as $i) if (($i['status'] ?? '') !== 'closed') $openIncidentCount++;
foreach ($quorumRequests as $q) if (($q['status'] ?? '') === 'open') $openQuorumCount++;

ob_start(); ?>
<?= ops_admin_help_assets_once() ?>
<style>
.ops-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.row-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:980px){.ops-grid,.row-grid{grid-template-columns:1fr}}
</style>
<?= ops_admin_collapsible_help('Page guide & workflow', [
  ops_admin_info_panel('Infrastructure control plane', 'What this page does', 'Use this page to manage the technical control layer that sits underneath execution and governance. It records node health, incidents, key ceremonies, shard assignments, and quorum requests for the sovereign infrastructure stack.', [
    'Use this page when you need to record or review infrastructure state.',
    'Use Execution for live batch progression and this page for the infrastructure controls that support it.',
    'Legacy bridge pages remain diagnostic and traceability surfaces only.',
  ]),
  ops_admin_workflow_panel('Typical workflow', 'Infrastructure actions usually support or verify another operator process.', [
    ['title' => 'Check status', 'body' => 'Review node and shard status before opening or diagnosing a live process.'],
    ['title' => 'Record evidence', 'body' => 'Add health checks, incidents, or key ceremonies as control evidence.'],
    ['title' => 'Open supporting control', 'body' => 'Assign shards or open a quorum request only when the execution or governance process requires it.'],
    ['title' => 'Review outcomes', 'body' => 'Use the history tables below to confirm that the technical control record is complete.'],
  ]),
  ops_admin_status_panel('How to read this page', 'These summaries and tables show whether the infrastructure layer is healthy, active, and ready.', [
    ['label' => 'Nodes / Live nodes', 'body' => 'How many node records exist and how many are currently marked live.'],
    ['label' => 'Active shard assignments', 'body' => 'Current node-to-shard relationships that are marked active.'],
    ['label' => 'Open incidents', 'body' => 'Operational issues that still need review or closure.'],
    ['label' => 'Open quorum requests', 'body' => 'Infrastructure-side quorum requests still waiting on completion.'],
  ]),
  ops_admin_guide_panel('Infrastructure section guide', 'Each form and table serves a different control purpose.', [
    ['title' => 'Record node health', 'body' => 'Use when you need a current technical health snapshot of a node.'],
    ['title' => 'Open node incident', 'body' => 'Use when there is a problem that needs tracking, escalation, or later review.'],
    ['title' => 'Record key ceremony', 'body' => 'Use for HSM and key-management events that require a durable audit trail.'],
    ['title' => 'Assign node to shard', 'body' => 'Use to define or update which node is serving which shard role.'],
    ['title' => 'Open quorum request', 'body' => 'Use when an infrastructure-linked quorum process must be opened.'],
  ]),
]) ?>

<div class="card">
  <div class="card-head"><h1 style="margin:0">Sovereign Infrastructure <?= ops_admin_help_button('Sovereign infrastructure', 'Technical control surface for nodes, shards, HSM-backed key ceremonies, incidents, and quorum support.') ?></h1></div>
  <div class="card-body">
    <div class="stat-grid">
      <div class="card"><div class="card-body"><div class="stat-label">Nodes</div><div class="stat-value"><?= (int)$nodeCount ?></div></div></div>
      <div class="card"><div class="card-body"><div class="stat-label">Live nodes</div><div class="stat-value"><?= (int)$liveNodeCount ?></div></div></div>
      <div class="card"><div class="card-body"><div class="stat-label">Active shards</div><div class="stat-value"><?= (int)$activeShardAssignments ?></div></div></div>
      <div class="card"><div class="card-body"><div class="stat-label">Open quorum</div><div class="stat-value"><?= (int)$openQuorumCount ?></div></div></div>
    </div>
  </div>
</div>

<?php if (!$canManage): ?>
  <div class="alert alert-err">You have read access only on this page.</div>
<?php endif; ?>

<div class="ops-grid">
  <form method="post" class="card">
    <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
    <input type="hidden" name="action" value="record_health_check">
    <div class="card-head"><h2>Record node health <?= ops_admin_help_button('Record node health', 'Creates an auditable health snapshot for a node.') ?></h2></div>
    <div class="card-body">
      <div class="field"><label>Node</label><select name="node_id" required><option value="">Select node</option><?php foreach ($nodes as $node): ?><option value="<?= (int)$node['id'] ?>"><?= ops_h(($node['display_name'] ?? '') . ' (' . ($node['status'] ?? '') . ')') ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Status</label><select name="health_status" required><option value="ok">ok</option><option value="warning">warning</option><option value="critical">critical</option><option value="offline">offline</option></select></div>
      <div class="field"><label>Summary</label><input name="summary" placeholder="Short health note"></div>
      <div class="field"><label>Details JSON</label><textarea name="details_json" placeholder='{"cpu":"healthy","sync":"ok"}'></textarea></div>
      <div class="actions"><button class="btn btn-gold" type="submit">Save health check</button></div>
    </div>
  </form>

  <form method="post" class="card">
    <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
    <input type="hidden" name="action" value="create_node_incident">
    <div class="card-head"><h2>Open node incident <?= ops_admin_help_button('Open node incident', 'Tracks a node problem until it is resolved or closed.') ?></h2></div>
    <div class="card-body">
      <div class="field"><label>Node</label><select name="node_id" required><option value="">Select node</option><?php foreach ($nodes as $node): ?><option value="<?= (int)$node['id'] ?>"><?= ops_h($node['display_name'] ?? '') ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Incident key</label><input name="incident_key" placeholder="Auto if blank"></div>
      <div class="field"><label>Severity</label><select name="severity"><option>medium</option><option>low</option><option>high</option><option>critical</option></select></div>
      <div class="field"><label>Summary</label><input name="summary" required placeholder="What happened?"></div>
      <div class="field"><label>Details</label><textarea name="details" placeholder="Operational notes"></textarea></div>
      <div class="actions"><button class="btn btn-gold" type="submit">Open incident</button></div>
    </div>
  </form>

  <form method="post" class="card">
    <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
    <input type="hidden" name="action" value="create_key_ceremony">
    <div class="card-head"><h2>Record key ceremony <?= ops_admin_help_button('Record key ceremony', 'Captures HSM and key-management events as a durable audit trail.') ?></h2></div>
    <div class="card-body">
      <div class="form-grid">
        <div class="field"><label>Ceremony key</label><input name="ceremony_key" placeholder="Auto if blank"></div>
        <div class="field"><label>HSM</label><select name="hsm_device_id" required><option value="">Select HSM</option><?php foreach ($hsms as $row): ?><option value="<?= (int)$row['id'] ?>"><?= ops_h(($row['display_name'] ?? '') . ' (' . ($row['status'] ?? '') . ')') ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Custody location</label><select name="key_custody_location_id" required><option value="">Select location</option><?php foreach ($locations as $row): ?><option value="<?= (int)$row['id'] ?>"><?= ops_h(($row['display_name'] ?? '') . ' [' . ($row['fnac_status'] ?? '') . '/' . ($row['fpic_status'] ?? '') . ']') ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Type</label><select name="ceremony_type"><option>generation</option><option>rotation</option><option>recovery</option><option>decommission</option></select></div>
        <div class="field"><label>Status</label><select name="status"><option>planned</option><option>in_progress</option><option>completed</option><option>failed</option></select></div>
        <div class="field"><label>Held at</label><input type="datetime-local" name="held_at"></div>
        <div class="field"><label>Evidence hash</label><input name="evidence_hash"></div>
        <div class="field" style="grid-column:1/-1"><label>Summary</label><textarea name="summary"></textarea></div>
      </div>
      <div class="actions"><button class="btn btn-gold" type="submit">Record ceremony</button></div>
    </div>
  </form>

  <form method="post" class="card">
    <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
    <input type="hidden" name="action" value="assign_node_shard">
    <div class="card-head"><h2>Assign node to shard <?= ops_admin_help_button('Assign node to shard', 'Links a node to a shard role — validator, primary signer, replica, or observer.') ?></h2></div>
    <div class="card-body">
      <div class="field"><label>Node</label><select name="node_id" required><option value="">Select node</option><?php foreach ($nodes as $node): ?><option value="<?= (int)$node['id'] ?>"><?= ops_h($node['display_name'] ?? '') ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Shard</label><select name="shard_id" required><option value="">Select shard</option><?php foreach ($shards as $row): ?><option value="<?= (int)$row['id'] ?>"><?= ops_h(($row['display_name'] ?? '') . ' (' . ($row['status'] ?? '') . ')') ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Assignment role</label><select name="assignment_role"><option>validator</option><option>primary_signer</option><option>replica</option><option>observer</option></select></div>
      <div class="field"><label>Status</label><select name="status"><option>planned</option><option>active</option><option>suspended</option><option>retired</option></select></div>
      <div class="actions"><button class="btn btn-gold" type="submit">Save assignment</button></div>
    </div>
  </form>

  <form method="post" class="card" style="grid-column:1/-1">
    <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
    <input type="hidden" name="action" value="open_quorum_request">
    <div class="card-head"><h2>Open quorum request <?= ops_admin_help_button('Open quorum request', 'Opens an infrastructure-side quorum control linked to an execution request or batch.') ?></h2></div>
    <div class="card-body">
      <div class="form-grid">
        <div class="field"><label>Request key</label><input name="request_key" placeholder="Auto if blank"></div>
        <div class="field"><label>Required signatures</label><input type="number" min="1" max="9" name="required_signatures" value="3"></div>
        <div class="field"><label>Execution request</label><select name="execution_request_id"><option value="">Optional</option><?php foreach ($executionRequests as $row): ?><option value="<?= (int)$row['id'] ?>"><?= ops_h(($row['request_key'] ?? '') . ' [' . ($row['execution_status'] ?? '') . ']') ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Execution batch</label><select name="execution_batch_id"><option value="">Optional</option><?php foreach ($executionBatches as $row): ?><option value="<?= (int)$row['id'] ?>"><?= ops_h(($row['batch_key'] ?? '') . ' [' . ($row['batch_status'] ?? '') . ']') ?></option><?php endforeach; ?></select></div>
        <div class="field" style="grid-column:1/-1"><label>Notes</label><textarea name="notes"></textarea></div>
      </div>
      <div class="actions"><button class="btn btn-gold" type="submit">Open quorum request</button></div>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-head"><h2>Node and shard status <?= ops_admin_help_button('Node and shard status', 'Best quick read of current infrastructure readiness.') ?></h2></div>
  <div class="card-body table-wrap"><table>
    <thead><tr><th>Node</th><th>Network</th><th>Shard</th><th>Role</th><th>FNAC / FPIC</th><th>HSM</th><th>Signer keys</th><th>Health</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="8" class="empty">No infrastructure rows found.</td></tr><?php endif; ?>
    <?php foreach ($rows as $row): ?><tr>
      <td><?= ops_h($row['node_name'] ?? ($row['node_key'] ?? '')) ?></td>
      <td><?= ops_h($row['network_name'] ?? '') ?></td>
      <td><?= ops_h($row['shard_name'] ?? '—') ?></td>
      <td><?= ops_h($row['node_role'] ?? '') ?></td>
      <td><?= ops_h(($row['fnac_status'] ?? 'pending') . ' / ' . ($row['fpic_status'] ?? 'pending')) ?></td>
      <td class="mono small"><?= ops_h(($row['hsm_device_key'] ?? '—') . ' / ' . ($row['hsm_status'] ?? '—')) ?></td>
      <td><?= (int)($row['active_signer_key_count'] ?? 0) ?></td>
      <td><?= ops_h($row['last_health_status'] ?? '—') ?></td>
    </tr><?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="row-grid">
  <div class="card">
    <div class="card-head"><h2>Open incidents <?= ops_admin_help_button('Open incidents', 'Tracked infrastructure problems or control exceptions.') ?></h2></div>
    <div class="card-body table-wrap"><table>
      <thead><tr><th>Incident</th><th>Node</th><th>Severity</th><th>Status</th><th>When</th></tr></thead>
      <tbody>
      <?php if (!$incidents): ?><tr><td colspan="5" class="empty">No incidents recorded.</td></tr><?php endif; ?>
      <?php foreach ($incidents as $row): ?><tr>
        <td class="mono small"><?= ops_h($row['incident_key'] ?? '') ?><div class="muted small"><?= ops_h($row['summary'] ?? '') ?></div></td>
        <td><?= ops_h($row['node_name'] ?? '—') ?></td>
        <td><?= ops_h($row['severity'] ?? '') ?></td>
        <td><?= ops_h($row['status'] ?? '') ?></td>
        <td class="small"><?= ops_h($row['created_at'] ?? '') ?></td>
      </tr><?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="card">
    <div class="card-head"><h2>Recent health checks <?= ops_admin_help_button('Recent health checks', 'Most recent recorded health observations.') ?></h2></div>
    <div class="card-body table-wrap"><table>
      <thead><tr><th>Node</th><th>Status</th><th>Summary</th><th>Checked</th></tr></thead>
      <tbody>
      <?php if (!$healthChecks): ?><tr><td colspan="4" class="empty">No health checks yet.</td></tr><?php endif; ?>
      <?php foreach ($healthChecks as $row): ?><tr>
        <td><?= ops_h($row['node_name'] ?? '—') ?></td>
        <td><?= ops_h($row['health_status'] ?? '') ?></td>
        <td><?= ops_h($row['summary'] ?? '—') ?></td>
        <td class="small"><?= ops_h($row['checked_at'] ?? '') ?></td>
      </tr><?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>

<div class="row-grid">
  <div class="card">
    <div class="card-head"><h2>Key ceremonies <?= ops_admin_help_button('Key ceremonies', 'Formal signer or HSM control events with evidence hashes.') ?></h2></div>
    <div class="card-body table-wrap"><table>
      <thead><tr><th>Ceremony</th><th>Type</th><th>Status</th><th>Held at</th><th>Evidence</th></tr></thead>
      <tbody>
      <?php if (!$keyCeremonies): ?><tr><td colspan="5" class="empty">No key ceremony rows yet.</td></tr><?php endif; ?>
      <?php foreach ($keyCeremonies as $row): ?><tr>
        <td class="mono small"><?= ops_h($row['ceremony_key'] ?? '') ?></td>
        <td><?= ops_h($row['ceremony_type'] ?? '') ?></td>
        <td><?= ops_h($row['status'] ?? '') ?></td>
        <td class="small"><?= ops_h($row['held_at'] ?? '—') ?></td>
        <td class="mono small"><?= ops_h($row['evidence_hash'] ?? '—') ?></td>
      </tr><?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="card">
    <div class="card-head"><h2>Quorum requests <?= ops_admin_help_button('Quorum requests', 'Infrastructure-side quorum requests supporting execution.') ?></h2></div>
    <div class="card-body table-wrap"><table>
      <thead><tr><th>Request</th><th>Execution ref</th><th>Required</th><th>Status</th><th>Opened</th></tr></thead>
      <tbody>
      <?php if (!$quorumRequests): ?><tr><td colspan="5" class="empty">No quorum requests yet.</td></tr><?php endif; ?>
      <?php foreach ($quorumRequests as $row): ?><tr>
        <td class="mono small"><?= ops_h($row['request_key'] ?? '') ?></td>
        <td class="mono small"><?= ops_h(($row['execution_request_key'] ?? '') !== '' ? (string)$row['execution_request_key'] : ((($row['execution_batch_key'] ?? '') !== '') ? (string)$row['execution_batch_key'] : '—')) ?></td>
        <td><?= (int)($row['required_signatures'] ?? 0) ?></td>
        <td><?= ops_h($row['status'] ?? '') ?></td>
        <td class="small"><?= ops_h($row['opened_at'] ?? '') ?></td>
      </tr><?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>

<div class="card">
  <div class="card-head"><h2>Node / shard assignments <?= ops_admin_help_button('Node / shard assignments', 'How nodes are currently assigned across shards and roles.') ?></h2></div>
  <div class="card-body table-wrap"><table>
    <thead><tr><th>Node</th><th>Shard</th><th>Role</th><th>Status</th><th>Updated</th></tr></thead>
    <tbody>
    <?php if (!$assignments): ?><tr><td colspan="5" class="empty">No assignments yet.</td></tr><?php endif; ?>
    <?php foreach ($assignments as $row): ?><tr>
      <td><?= ops_h($row['node_name'] ?? '—') ?></td>
      <td><?= ops_h($row['shard_name'] ?? '—') ?></td>
      <td><?= ops_h($row['assignment_role'] ?? '') ?></td>
      <td><?= ops_h($row['status'] ?? '') ?></td>
      <td class="small"><?= ops_h($row['updated_at'] ?? '') ?></td>
    </tr><?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php $body = ob_get_clean(); ops_render_page('Sovereign Infrastructure', 'infrastructure', $body, $flash, $flashType); ?>
