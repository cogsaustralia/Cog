<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/ops_workflow.php';
ops_require_admin();
$pdo = ops_db();
$flash = null; $flashType = 'ok';

if (!function_exists('zn_rows')) {
    function zn_rows(PDO $pdo, string $sql, array $params = []): array {
        try { return ops_fetch_all($pdo, $sql, $params); } catch (Throwable $e) { return []; }
    }
}
if (!function_exists('zn_key')) {
    function zn_key(string $prefix): string {
        return strtoupper($prefix) . '-' . gmdate('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }
}
if (!function_exists('zn_redirect')) {
    function zn_redirect(string $flash, string $type = 'ok'): never {
        $qs = http_build_query(['flash' => $flash, 'type' => $type]);
        header('Location: ' . admin_url('zones.php?' . $qs));
        exit;
    }
}
$adminUserId = ops_current_admin_user_id($pdo);
$canManage = ops_admin_can($pdo, 'governance.manage') || ops_admin_can($pdo, 'operations.manage');
if (isset($_GET['flash'])) { $flash = (string)$_GET['flash']; $flashType = (string)($_GET['type'] ?? 'ok'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
    if (!$canManage) {
        zn_redirect('You do not have permission to manage zones and eligibility.', 'error');
    }
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create_evidence_packet') {
            if (!ops_has_table($pdo, 'zone_evidence_packets')) throw new RuntimeException('zone_evidence_packets table not available.');
            $zoneId = (int)($_POST['zone_id'] ?? 0);
            $packetKey = trim((string)($_POST['packet_key'] ?? '')) ?: zn_key('ZEP');
            $status = trim((string)($_POST['status'] ?? 'draft'));
            $sourceLayers = trim((string)($_POST['source_layers_json'] ?? ''));
            $evidenceHash = trim((string)($_POST['evidence_hash'] ?? ''));
            if ($zoneId < 1) throw new RuntimeException('Select a zone.');
            if (!in_array($status, ['draft','ready','under_review','approved','superseded'], true)) throw new RuntimeException('Invalid packet status.');
            $sourceJson = null;
            if ($sourceLayers !== '') { json_decode($sourceLayers, true, 512, JSON_THROW_ON_ERROR); $sourceJson = $sourceLayers; }
            $pdo->prepare('INSERT INTO zone_evidence_packets (zone_id, packet_key, source_layers_json, evidence_hash, status, created_by_admin_user_id, created_at, updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())')
                ->execute([$zoneId, $packetKey, $sourceJson, $evidenceHash !== '' ? $evidenceHash : null, $status, $adminUserId]);
            zn_redirect('Zone evidence packet saved: ' . $packetKey . '.');
        }
        if ($action === 'record_fnac_review') {
            if (!ops_has_table($pdo, 'fnac_reviews')) throw new RuntimeException('fnac_reviews table not available.');
            $zoneId = (int)($_POST['zone_id'] ?? 0);
            $reviewKey = trim((string)($_POST['review_key'] ?? '')) ?: zn_key('FNR');
            $status = trim((string)($_POST['status'] ?? 'pending'));
            $reviewNotes = trim((string)($_POST['review_notes'] ?? ''));
            if ($zoneId < 1) throw new RuntimeException('Select a zone.');
            if (!in_array($status, ['pending','endorsed','rejected','withdrawn'], true)) throw new RuntimeException('Invalid FNAC status.');
            $pdo->prepare('INSERT INTO fnac_reviews (subject_type, subject_id, review_key, status, review_notes, reviewed_at, created_at) VALUES (\'affected_zone\',?,?,?,?,NOW(),NOW())')
                ->execute([$zoneId, $reviewKey, $status, $reviewNotes !== '' ? $reviewNotes : null]);
            if (ops_has_table($pdo, 'affected_zones') && in_array($status, ['endorsed','rejected'], true)) {
                $fieldVal = $status === 'endorsed' ? 1 : 0;
                $pdo->prepare('UPDATE affected_zones SET fnac_consulted = 1, fnac_endorsed = ?, updated_at = NOW() WHERE id = ?')->execute([$fieldVal, $zoneId]);
            }
            zn_redirect('FNAC review recorded: ' . $reviewKey . '.');
        }
        if ($action === 'open_board_signoff') {
            if (!ops_has_table($pdo, 'board_signoff_requests')) throw new RuntimeException('board_signoff_requests table not available.');
            $zoneId = (int)($_POST['zone_id'] ?? 0);
            $signoffKey = trim((string)($_POST['signoff_key'] ?? '')) ?: zn_key('BSR');
            $requiredSignatures = max(1, (int)($_POST['required_signatures'] ?? 3));
            if ($zoneId < 1) throw new RuntimeException('Select a zone.');
            $pdo->prepare('INSERT INTO board_signoff_requests (subject_type, subject_id, signoff_key, required_signatures, status, opened_at) VALUES (\'affected_zone\',?,?,?,\'pending\',NOW())')
                ->execute([$zoneId, $signoffKey, $requiredSignatures]);
            zn_redirect('Board signoff request opened: ' . $signoffKey . '.');
        }
        if ($action === 'create_eligibility_snapshot') {
            if (!ops_has_table($pdo, 'eligibility_snapshots')) throw new RuntimeException('eligibility_snapshots table not available.');
            $zoneId = (int)($_POST['zone_id'] ?? 0);
            $snapshotKey = trim((string)($_POST['snapshot_key'] ?? '')) ?: zn_key('ELS');
            $snapshotType = trim((string)($_POST['snapshot_type'] ?? 'zone'));
            $snapshotHash = trim((string)($_POST['snapshot_hash'] ?? ''));
            if ($zoneId < 1) throw new RuntimeException('Select a zone.');
            if (!in_array($snapshotType, ['zone','poll','distribution','other'], true)) throw new RuntimeException('Invalid snapshot type.');
            $memberCount = (int)(ops_fetch_val($pdo, 'SELECT COUNT(*) FROM address_verifications WHERE zone_id = ? AND in_affected_zone = 1', [$zoneId]) ?? 0);
            $pdo->prepare('INSERT INTO eligibility_snapshots (snapshot_key, snapshot_type, subject_type, subject_id, snapshot_hash, member_count, created_at) VALUES (?,?,\'affected_zone\',?,?,?,NOW())')
                ->execute([$snapshotKey, $snapshotType, $zoneId, $snapshotHash !== '' ? $snapshotHash : null, $memberCount]);
            zn_redirect('Eligibility snapshot created: ' . $snapshotKey . '.');
        }
        if ($action === 'open_zone_challenge') {
            if (!ops_has_table($pdo, 'zone_challenges')) throw new RuntimeException('zone_challenges table not available.');
            $zoneId = (int)($_POST['zone_id'] ?? 0);
            $challengerMemberId = (int)($_POST['challenger_member_id'] ?? 0);
            $summaryTxt = trim((string)($_POST['challenge_summary'] ?? ''));
            $details = trim((string)($_POST['challenge_details'] ?? ''));
            if ($zoneId < 1) throw new RuntimeException('Select a zone.');
            if ($summaryTxt === '') throw new RuntimeException('Challenge summary is required.');
            $pdo->prepare('INSERT INTO zone_challenges (zone_id, challenger_member_id, status, challenge_summary, challenge_details, created_at, updated_at) VALUES (?,?,\'open\',?,?,NOW(),NOW())')
                ->execute([$zoneId, $challengerMemberId > 0 ? $challengerMemberId : null, $summaryTxt, $details !== '' ? $details : null]);
            zn_redirect('Zone challenge opened.');
        }
        throw new RuntimeException('Unknown zones action.');
    } catch (Throwable $e) {
        $flash = $e->getMessage();
        $flashType = 'error';
    }
}

$zones = ops_has_table($pdo, 'affected_zones') ? zn_rows($pdo, 'SELECT id, zone_code, zone_name, status, fnac_consulted, fnac_endorsed, board_approved, effective_date, review_date, ledger_tx_hash FROM affected_zones ORDER BY id DESC') : [];
$rows = ops_has_table($pdo, 'v_phase1_zone_governance_queue') ? zn_rows($pdo, 'SELECT * FROM v_phase1_zone_governance_queue ORDER BY zone_id DESC LIMIT 100') : [];
$addr = ops_has_table($pdo, 'address_verifications') ? zn_rows($pdo, 'SELECT member_id, member_number, input_street, input_suburb, input_state, input_postcode, status, zone_code, ledger_tx_hash, created_at FROM address_verifications ORDER BY id DESC LIMIT 20') : [];
$packets = ops_has_table($pdo, 'zone_evidence_packets') ? zn_rows($pdo, 'SELECT zep.*, az.zone_code, az.zone_name FROM zone_evidence_packets zep LEFT JOIN affected_zones az ON az.id = zep.zone_id ORDER BY zep.id DESC LIMIT 20') : [];
$fnacReviews = ops_has_table($pdo, 'fnac_reviews') ? zn_rows($pdo, "SELECT fr.*, az.zone_code, az.zone_name FROM fnac_reviews fr LEFT JOIN affected_zones az ON fr.subject_type = 'affected_zone' AND az.id = fr.subject_id ORDER BY fr.id DESC LIMIT 20") : [];
$signoffs = ops_has_table($pdo, 'board_signoff_requests') ? zn_rows($pdo, "SELECT bsr.*, az.zone_code, az.zone_name FROM board_signoff_requests bsr LEFT JOIN affected_zones az ON bsr.subject_type = 'affected_zone' AND az.id = bsr.subject_id ORDER BY bsr.id DESC LIMIT 20") : [];
$snapshots = ops_has_table($pdo, 'eligibility_snapshots') ? zn_rows($pdo, 'SELECT es.*, az.zone_code, az.zone_name FROM eligibility_snapshots es LEFT JOIN affected_zones az ON es.subject_type = "affected_zone" AND az.id = es.subject_id ORDER BY es.id DESC LIMIT 20') : [];
$challenges = ops_has_table($pdo, 'zone_challenges') ? zn_rows($pdo, 'SELECT zc.*, az.zone_code, az.zone_name FROM zone_challenges zc LEFT JOIN affected_zones az ON az.id = zc.zone_id ORDER BY zc.id DESC LIMIT 20') : [];
$members = ops_has_table($pdo, 'members') ? zn_rows($pdo, 'SELECT id, member_number, full_name FROM members ORDER BY id DESC LIMIT 100') : [];

$activeZones = 0; $openChallenges = 0; $openSignoffs = 0; $endorsedZones = 0;
foreach ($zones as $z) { if (($z['status'] ?? '') === 'active') $activeZones++; if ((int)($z['fnac_endorsed'] ?? 0) === 1) $endorsedZones++; }
foreach ($challenges as $c) if (($c['status'] ?? '') === 'open') $openChallenges++;
foreach ($signoffs as $s) if (($s['status'] ?? '') === 'pending') $openSignoffs++;

ob_start(); ?>
<style>
.ops-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.form-grid > .field{min-width:0}
.field{display:flex;flex-direction:column;gap:6px}
.field label{font-size:.82rem;font-weight:600;color:var(--sub)}
.field input,.field select,.field textarea{width:100%;min-width:0;box-sizing:border-box;background:var(--panel2);border:1px solid var(--line);border-radius:10px;padding:8px 10px;color:var(--text);font:inherit;font-size:.85rem}
.field textarea{min-height:80px;resize:vertical}
.bridge-note{padding:14px 16px;border-radius:14px;background:rgba(212,178,92,.08);border:1px solid rgba(212,178,92,.2);margin-bottom:18px;font-size:.88rem;line-height:1.6}
@media(max-width:980px){.ops-grid,.form-grid{grid-template-columns:1fr}}
</style>

<?= ops_admin_collapsible_help('Page guide & workflow', [
  ops_admin_info_panel('Stage 6 · Zones & eligibility', 'What this page does', 'Zones and eligibility is the operator page for the geographic and evidentiary controls that determine who is in scope for place-based governance. Use it to manage evidence packets, FNAC reviews, board signoffs, eligibility snapshots, and challenges to zone boundaries or inclusion logic.', [
    'An affected zone is the governed area that determines who is in scope for local eligibility or place-based decisions.',
    'Evidence packets hold the source material that supports why a zone exists and how it was defined.',
    'FNAC reviews and board signoffs show the cultural and governance approvals required before a zone can be treated as settled.',
    'Eligibility snapshots freeze who was in scope at a point in time so later disputes and audits can be checked against a stable record.',
  ]),
  ops_admin_workflow_panel('Typical workflow', 'Zones usually move through the same evidence and approval chain.', [
    ['title' => 'Define or review the zone', 'body' => 'Start with the zone queue and confirm which affected area is being worked on.'],
    ['title' => 'Collect evidence', 'body' => 'Create or update an evidence packet that records source layers, rationale, and evidence hash material.'],
    ['title' => 'Record FNAC review', 'body' => 'Capture the relevant cultural review outcome and whether endorsement has been granted.'],
    ['title' => 'Open Board signoff', 'body' => 'Once the evidence and FNAC stage are ready, open the formal board signoff request for the zone.'],
    ['title' => 'Create eligibility snapshot', 'body' => 'Freeze the in-scope member set so the zone can later support governance, audit, or dispute resolution.'],
    ['title' => 'Handle challenges', 'body' => 'Use the challenge workflow when a Member disputes inclusion, exclusion, or the basis of the zone itself.'],
  ]),
  ops_admin_guide_panel('How to use this page', 'Read the page in three passes: the live queue, the control actions, then the trace tables.', [
    ['title' => 'Zone governance queue', 'body' => 'Quickest live view of current zone state.'],
    ['title' => 'Control actions', 'body' => 'Forms to add or update evidence, review, signoff, snapshot, and challenge records.'],
    ['title' => 'Trace tables', 'body' => 'Lower tables show what has already been recorded.'],
    ['title' => 'Address verification activity', 'body' => 'Connects member-level address checks back to zone eligibility.'],
  ]),
  ops_admin_status_panel('Status guide', 'These labels appear throughout the zone workflow.', [
    ['label' => 'Active zone', 'body' => 'The zone is currently in force and may affect eligibility or governance scope.'],
    ['label' => 'FNAC pending / endorsed / rejected', 'body' => 'Whether cultural review has not started, is positive, or has been refused.'],
    ['label' => 'Board pending / executed', 'body' => 'Whether the formal governance signoff is still open or has been completed.'],
    ['label' => 'Evidence packet draft / ready / approved', 'body' => 'Whether the evidentiary basis is still being assembled or is settled.'],
    ['label' => 'Open challenge', 'body' => 'A Member or operator has raised a dispute that still requires review.'],
  ]),
]) ?>

<div class="card">
  <div class="card-head">
    <h1 style="margin:0">Zones &amp; eligibility <?= ops_admin_help_button('Zones & eligibility', 'Manage the geographic and evidentiary controls that determine who is in scope for place-based governance.') ?></h1>
  </div>
  <div class="card-body">
    <div class="stat-grid">
      <div class="card"><div class="card-body"><div class="stat-label">Affected zones</div><div class="stat-value"><?= count($zones) ?></div></div></div>
      <div class="card"><div class="card-body"><div class="stat-label">Active zones</div><div class="stat-value"><?= (int)$activeZones ?></div></div></div>
      <div class="card"><div class="card-body"><div class="stat-label">FNAC endorsed</div><div class="stat-value"><?= (int)$endorsedZones ?></div></div></div>
      <div class="card"><div class="card-body"><div class="stat-label">Open signoffs</div><div class="stat-value"><?= (int)$openSignoffs ?></div></div></div>
    </div>
  </div>
</div>

<?php if (!$canManage): ?>
  <div class="alert alert-err">You have read access only on this page.</div>
<?php endif; ?>

<div class="bridge-note">
  <strong>Operator note:</strong> The action forms below create or update the records that justify a zone. The tables further down are the trace view for explaining why a zone is active, what evidence supports it, and whether any challenge is still open.
</div>

<div class="ops-grid">
  <form method="post" class="card">
    <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
    <input type="hidden" name="action" value="create_evidence_packet">
    <div class="card-head"><h2>Create evidence packet <?= ops_admin_help_button('Create evidence packet', 'Record or refresh the evidentiary basis for a zone.') ?></h2></div>
    <div class="card-body">
      <div class="form-grid">
        <div class="field"><label>Zone</label><select name="zone_id" required><option value="">Select zone</option><?php foreach ($zones as $zone): ?><option value="<?= (int)$zone['id'] ?>"><?= ops_h(($zone['zone_code'] ?? '') . ' — ' . ($zone['zone_name'] ?? '')) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Packet key</label><input name="packet_key" placeholder="Auto if blank"></div>
        <div class="field"><label>Status</label><select name="status"><option>draft</option><option>ready</option><option>under_review</option><option>approved</option><option>superseded</option></select></div>
        <div class="field"><label>Evidence hash</label><input name="evidence_hash"></div>
        <div class="field" style="grid-column:1/-1"><label>Source layers JSON</label><textarea name="source_layers_json" placeholder='[{"source":"G-NAF","version":"2026-04"}]'></textarea></div>
      </div>
      <div class="actions"><button class="btn btn-gold" type="submit">Save evidence packet</button></div>
    </div>
  </form>

  <form method="post" class="card">
    <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
    <input type="hidden" name="action" value="record_fnac_review">
    <div class="card-head"><h2>Record FNAC review <?= ops_admin_help_button('Record FNAC review', 'Record the First Nations Advisory Council review outcome.') ?></h2></div>
    <div class="card-body">
      <div class="form-grid">
        <div class="field"><label>Zone</label><select name="zone_id" required><option value="">Select zone</option><?php foreach ($zones as $zone): ?><option value="<?= (int)$zone['id'] ?>"><?= ops_h(($zone['zone_code'] ?? '') . ' — ' . ($zone['zone_name'] ?? '')) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Review key</label><input name="review_key" placeholder="Auto if blank"></div>
        <div class="field"><label>Status</label><select name="status"><option>pending</option><option>endorsed</option><option>rejected</option><option>withdrawn</option></select></div>
        <div class="field" style="grid-column:1/-1"><label>Review notes</label><textarea name="review_notes"></textarea></div>
      </div>
      <div class="actions"><button class="btn btn-gold" type="submit">Record FNAC review</button></div>
    </div>
  </form>

  <form method="post" class="card">
    <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
    <input type="hidden" name="action" value="open_board_signoff">
    <div class="card-head"><h2>Open Board signoff <?= ops_admin_help_button('Open Board signoff', 'Open the formal governance signoff request for the zone.') ?></h2></div>
    <div class="card-body">
      <div class="form-grid">
        <div class="field"><label>Zone</label><select name="zone_id" required><option value="">Select zone</option><?php foreach ($zones as $zone): ?><option value="<?= (int)$zone['id'] ?>"><?= ops_h(($zone['zone_code'] ?? '') . ' — ' . ($zone['zone_name'] ?? '')) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Signoff key</label><input name="signoff_key" placeholder="Auto if blank"></div>
        <div class="field"><label>Required signatures</label><input type="number" min="1" max="9" name="required_signatures" value="3"></div>
      </div>
      <div class="actions"><button class="btn btn-gold" type="submit">Open signoff request</button></div>
    </div>
  </form>

  <form method="post" class="card">
    <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
    <input type="hidden" name="action" value="create_eligibility_snapshot">
    <div class="card-head"><h2>Create eligibility snapshot <?= ops_admin_help_button('Create eligibility snapshot', 'Freeze the in-scope population for a zone at a specific point in time.') ?></h2></div>
    <div class="card-body">
      <div class="form-grid">
        <div class="field"><label>Zone</label><select name="zone_id" required><option value="">Select zone</option><?php foreach ($zones as $zone): ?><option value="<?= (int)$zone['id'] ?>"><?= ops_h(($zone['zone_code'] ?? '') . ' — ' . ($zone['zone_name'] ?? '')) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Snapshot key</label><input name="snapshot_key" placeholder="Auto if blank"></div>
        <div class="field"><label>Type</label><select name="snapshot_type"><option>zone</option><option>poll</option><option>distribution</option><option>other</option></select></div>
        <div class="field"><label>Snapshot hash</label><input name="snapshot_hash"></div>
      </div>
      <div class="actions"><button class="btn btn-gold" type="submit">Create snapshot</button></div>
    </div>
  </form>

  <form method="post" class="card" style="grid-column:1/-1">
    <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
    <input type="hidden" name="action" value="open_zone_challenge">
    <div class="card-head"><h2>Open zone challenge <?= ops_admin_help_button('Open zone challenge', 'Formally track a dispute about a zone boundary or inclusion logic.') ?></h2></div>
    <div class="card-body">
      <div class="form-grid">
        <div class="field"><label>Zone</label><select name="zone_id" required><option value="">Select zone</option><?php foreach ($zones as $zone): ?><option value="<?= (int)$zone['id'] ?>"><?= ops_h(($zone['zone_code'] ?? '') . ' — ' . ($zone['zone_name'] ?? '')) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Challenger member (optional)</label><select name="challenger_member_id"><option value="">Optional</option><?php foreach ($members as $m): ?><option value="<?= (int)$m['id'] ?>"><?= ops_h(($m['member_number'] ?? '') . ' — ' . ($m['full_name'] ?? '')) ?></option><?php endforeach; ?></select></div>
        <div class="field" style="grid-column:1/-1"><label>Challenge summary</label><input name="challenge_summary" required></div>
        <div class="field" style="grid-column:1/-1"><label>Details</label><textarea name="challenge_details"></textarea></div>
      </div>
      <div class="actions"><button class="btn btn-gold" type="submit">Open challenge</button></div>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-head"><h2>Zone governance queue <?= ops_admin_help_button('Zone governance queue', 'Quickest live view: status, FNAC, board, evidence count, and ledger reference.') ?></h2></div>
  <div class="card-body table-wrap"><table>
    <thead><tr><th>Zone</th><th>Status</th><th>FNAC</th><th>Board</th><th>Evidence packets</th><th>Ledger ref</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty">No zone queue rows found.</td></tr><?php endif; ?>
    <?php foreach ($rows as $row): ?><tr>
      <td><?= ops_h(($row['zone_code'] ?? '') . ' — ' . ($row['zone_name'] ?? '')) ?></td>
      <td><?= ops_h($row['zone_status'] ?? '') ?></td>
      <td><?= ops_h($row['latest_fnac_review_status'] ?? ((int)($row['fnac_endorsed'] ?? 0) === 1 ? 'endorsed' : 'pending')) ?></td>
      <td><?= ops_h($row['latest_board_signoff_status'] ?? ((int)($row['board_approved'] ?? 0) === 1 ? 'executed' : 'pending')) ?></td>
      <td><?= (int)($row['evidence_packet_count'] ?? 0) ?></td>
      <td class="mono small"><?= ops_h($row['ledger_tx_hash'] ?? '—') ?></td>
    </tr><?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="row-grid">
  <div class="card">
    <div class="card-head"><h2>Evidence packets &amp; FNAC reviews <?= ops_admin_help_button('Evidence packets & FNAC reviews', 'Trace table: what evidence packet exists and what was the latest FNAC review outcome.') ?></h2></div>
    <div class="card-body table-wrap"><table>
      <thead><tr><th>Packet / review</th><th>Zone</th><th>Status</th><th>When</th></tr></thead>
      <tbody>
      <?php if (!$packets && !$fnacReviews): ?><tr><td colspan="4" class="empty">No evidence or FNAC rows yet.</td></tr><?php endif; ?>
      <?php foreach ($packets as $row): ?><tr><td class="mono small"><?= ops_h($row['packet_key'] ?? '') ?></td><td><?= ops_h(($row['zone_code'] ?? '') . ' — ' . ($row['zone_name'] ?? '')) ?></td><td><?= ops_h($row['status'] ?? '') ?></td><td class="small"><?= ops_h($row['created_at'] ?? '') ?></td></tr><?php endforeach; ?>
      <?php foreach ($fnacReviews as $row): ?><tr><td class="mono small"><?= ops_h($row['review_key'] ?? '') ?></td><td><?= ops_h(($row['zone_code'] ?? '') . ' — ' . ($row['zone_name'] ?? '')) ?></td><td><?= ops_h($row['status'] ?? '') ?></td><td class="small"><?= ops_h($row['reviewed_at'] ?? $row['created_at'] ?? '') ?></td></tr><?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="card">
    <div class="card-head"><h2>Board signoffs &amp; snapshots <?= ops_admin_help_button('Board signoffs & snapshots', 'Confirm governance signoff occurred and whether an eligibility population was frozen.') ?></h2></div>
    <div class="card-body table-wrap"><table>
      <thead><tr><th>Object</th><th>Zone</th><th>Status / count</th><th>When</th></tr></thead>
      <tbody>
      <?php if (!$signoffs && !$snapshots): ?><tr><td colspan="4" class="empty">No signoff or snapshot rows yet.</td></tr><?php endif; ?>
      <?php foreach ($signoffs as $row): ?><tr><td class="mono small"><?= ops_h($row['signoff_key'] ?? '') ?></td><td><?= ops_h(($row['zone_code'] ?? '') . ' — ' . ($row['zone_name'] ?? '')) ?></td><td><?= ops_h(($row['status'] ?? '') . ' / ' . (string)($row['required_signatures'] ?? 0)) ?></td><td class="small"><?= ops_h($row['opened_at'] ?? '') ?></td></tr><?php endforeach; ?>
      <?php foreach ($snapshots as $row): ?><tr><td class="mono small"><?= ops_h($row['snapshot_key'] ?? '') ?></td><td><?= ops_h(($row['zone_code'] ?? '') . ' — ' . ($row['zone_name'] ?? '')) ?></td><td><?= ops_h((string)($row['member_count'] ?? 0) . ' members') ?></td><td class="small"><?= ops_h($row['created_at'] ?? '') ?></td></tr><?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>

<?php
$unverifiedAddresses = ops_has_table($pdo, 'members') ? ops_fetch_all($pdo,
    "SELECT id, full_name, member_number, street_address, suburb, state_code, postcode, gnaf_pid, address_verified_at
     FROM members WHERE is_active = 1 AND street_address IS NOT NULL AND street_address != ''
     AND (gnaf_pid IS NULL OR address_verified_at IS NULL) ORDER BY full_name ASC LIMIT 50"
) : [];
?>
<?php if ($unverifiedAddresses): ?>
<div class="alert alert-warn">
  <strong>⚠ <?= count($unverifiedAddresses) ?> member<?= count($unverifiedAddresses) !== 1 ? 's' : '' ?> with unverified G-NAF address</strong>
  — correct address typos via the <a href="./members.php">Members page</a> (edit member → address fields → Save address), then re-run address verification.
</div>
<?php endif; ?>

<div class="row-grid">
  <div class="card">
    <div class="card-head"><h2>Address verification activity <?= ops_admin_help_button('Recent address verification activity', 'Member-level address checks and zone matching.') ?></h2></div>
    <div class="card-body table-wrap"><table>
      <thead><tr><th>Member</th><th>Address</th><th>Status</th><th>Zone</th><th>When</th></tr></thead>
      <tbody>
      <?php if (!$addr): ?><tr><td colspan="5" class="empty">No address verification rows found.</td></tr><?php endif; ?>
      <?php foreach ($addr as $row): ?><tr>
        <td class="mono small"><?= ops_h($row['member_number'] ?? '') ?></td>
        <td><?= ops_h(trim(($row['input_street'] ?? '') . ' ' . ($row['input_suburb'] ?? '') . ' ' . ($row['input_state'] ?? '') . ' ' . ($row['input_postcode'] ?? ''))) ?></td>
        <td><?= ops_h($row['status'] ?? '') ?></td>
        <td><?= ops_h($row['zone_code'] ?? '—') ?></td>
        <td class="small"><?= ops_h($row['created_at'] ?? '') ?></td>
      </tr><?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="card">
    <div class="card-head"><h2>Zone challenges <?= ops_admin_help_button('Zone challenges', 'Open or historical disputes about zone boundaries or inclusion.') ?></h2></div>
    <div class="card-body table-wrap"><table>
      <thead><tr><th>Zone</th><th>Summary</th><th>Status</th><th>When</th></tr></thead>
      <tbody>
<?php if (!$challenges): ?><tr><td colspan="4" class="empty">No challenges recorded.</td></tr><?php endif; ?>
      <?php foreach ($challenges as $row): ?><tr>
        <td><?= ops_h(($row['zone_code'] ?? '') . ' — ' . ($row['zone_name'] ?? '')) ?></td>
        <td><?= ops_h($row['challenge_summary'] ?? '') ?></td>
        <td><?= ops_h($row['status'] ?? '') ?></td>
        <td><?= ops_h($row['created_at'] ?? '') ?></td>
      </tr><?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>
<?php $body = ob_get_clean(); ops_render_page('Zones & Eligibility', 'zones', $body, $flash, $flashType); ?>
<?php $body = ob_get_clean(); ops_render_page('Zones & Eligibility', 'zones', $body, $flash, $flashType); ?>
