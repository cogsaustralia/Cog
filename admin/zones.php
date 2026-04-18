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
<?php ops_admin_help_assets_once(); ?>
<style>
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.ops-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.field{display:flex;flex-direction:column;gap:8px;margin-bottom:12px}.field label{font-weight:700;color:#d8e0ea}.field input,.field select,.field textarea{background:#0f1720;border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:12px 14px;color:#eef2f7}.field textarea{min-height:120px;resize:vertical}.actions{display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;margin-top:10px}.table-wrap .table-wrap th,.table-wrap td{padding:10px 8px;border-bottom:1px solid rgba(255,255,255,.08);text-align:left;vertical-align:top}.table-wrap .muted{color:#9fb0c1}.stat strong{display:block;font-size:2rem;margin-top:6px}.card h2,.card h3{display:flex;align-items:center;gap:6px;flex-wrap:wrap}.bridge-note{padding:14px 16px;border-radius:16px;background:rgba(212,178,92,.08);border:1px solid rgba(212,178,92,.2);margin-bottom:18px}.small{font-size:.88rem}.empty-note{font-size:.9rem;color:#9fb0c1}@media(max-width:980px){.ops-grid,.form-grid,} </style>

<div class="grid" style="margin-bottom:18px;gap:16px">
  <?= ops_admin_collapsible_help('Page guide & workflow', [
    <?= ops_admin_info_panel('Stage 6 · Zones & eligibility', 'What this page does', 'Zones and eligibility is the operator page for the geographic and evidentiary controls that determine who is in scope for place-based governance. Use it to manage evidence packets, FNAC reviews, board signoffs, eligibility snapshots, and challenges to zone boundaries or inclusion logic.', [
        'An affected zone is the governed area that determines who is in scope for local eligibility or place-based decisions.',
        'Evidence packets hold the source material that supports why a zone exists and how it was defined.',
        'FNAC reviews and board signoffs show the cultural and governance approvals required before a zone can be treated as settled.',
        'Eligibility snapshots freeze who was in scope at a point in time so later disputes and audits can be checked against a stable record.'
      ]) ?>
      <?= ops_admin_workflow_panel('Typical workflow', 'Zones usually move through the same evidence and approval chain.', [
        ['title' => 'Define or review the zone', 'body' => 'Start with the zone queue and confirm which affected area is being worked on.'],
        ['title' => 'Collect evidence', 'body' => 'Create or update an evidence packet that records source layers, rationale, and evidence hash material.'],
        ['title' => 'Record FNAC review', 'body' => 'Capture the relevant cultural review outcome and whether endorsement has been granted.'],
        ['title' => 'Open Board signoff', 'body' => 'Once the evidence and FNAC stage are ready, open the formal board signoff request for the zone.'],
        ['title' => 'Create eligibility snapshot', 'body' => 'Freeze the in-scope member set so the zone can later support governance, audit, or dispute resolution.'],
        ['title' => 'Handle challenges', 'body' => 'Use the challenge workflow when a Partner disputes inclusion, exclusion, or the basis of the zone itself.']
      ]) ?>
      <?= ops_admin_guide_panel('How to use this page', 'Read the page in three passes: the live queue, the control actions, then the trace tables.', [
        ['title' => 'Zone governance queue', 'body' => 'This is the quickest live view of the current zone state: status, FNAC, board, packet count, and ledger reference.'],
        ['title' => 'Control actions', 'body' => 'These forms are how you add or update the evidence, review, signoff, snapshot, and challenge records.'],
        ['title' => 'Trace tables', 'body' => 'The lower tables show what has already been recorded so you can explain and audit the current state.'],
        ['title' => 'Address verification activity', 'body' => 'Use this section to connect member-level address checks back to zone inclusion and eligibility.']
      ]) ?>
      <?= ops_admin_status_panel('Status guide', 'These labels appear throughout the zone workflow.', [
        ['label' => 'Active zone', 'body' => 'The zone is currently in force and may affect eligibility or governance scope.'],
        ['label' => 'FNAC pending / endorsed / rejected', 'body' => 'Shows whether cultural review has not started, is complete and positive, or has been refused.'],
        ['label' => 'Board pending / executed', 'body' => 'Shows whether the formal governance signoff is still open or has been completed.'],
        ['label' => 'Evidence packet draft / ready / approved', 'body' => 'Shows whether the evidentiary basis is still being assembled or is settled enough for the next stage.'],
        ['label' => 'Open challenge', 'body' => 'A Partner or operator has raised a dispute that still requires review.']
      ]) ?>
  ]) ?>
</div>

<div class="card">
<div class="card-body">  <h2>Zones & eligibility control plane<?= ops_admin_help_button('Zones & eligibility control plane', 'Use this page to explain why a zone exists, what approval state it is in, who is in scope, and whether any open challenge or missing evidence is preventing the zone from being treated as settled.') ?></h2>
  <p class="muted">This page turns the zone workflow into plain-English operator steps: evidence, FNAC, board, eligibility snapshot, then challenge handling if needed.</p></div>
</div>

<div class="card">
  <div class="stats">
    <div class="stat"><span class="muted">Affected zones<?= ops_admin_help_button('Affected zones', 'The total number of zone records currently visible on this page.') ?></span><strong><?= count($zones) ?></strong></div>
    <div class="stat"><span class="muted">Active zones<?= ops_admin_help_button('Active zones', 'Zones whose current status is active and therefore may affect real eligibility or governance scope.') ?></span><strong><?= (int)$activeZones ?></strong></div>
    <div class="stat"><span class="muted">FNAC endorsed<?= ops_admin_help_button('FNAC endorsed', 'Zones where the FNAC endorsement flag has been recorded positively.') ?></span><strong><?= (int)$endorsedZones ?></strong></div>
    <div class="stat"><span class="muted">Open board signoffs<?= ops_admin_help_button('Open board signoffs', 'Board signoff requests that are still pending and therefore block the zone from being fully governance-complete.') ?></span><strong><?= (int)$openSignoffs ?></strong></div>
    <div class="stat"><span class="muted">Open challenges<?= ops_admin_help_button('Open challenges', 'Zone disputes that still require review or resolution.') ?></span><strong><?= (int)$openChallenges ?></strong></div>
    <div class="stat"><span class="muted">Read-only / manage mode<?= ops_admin_help_button('Manage mode', 'If you only have read access, the forms below are visible for explanation but should not be used for write actions.') ?></span><strong><?= $canManage ? 'Manage' : 'Read only' ?></strong></div>
  </div>
</div>

<?php if (!$canManage): ?>
  <div class="err">You have read access only on this page.</div>
<?php endif; ?>

<div class="bridge-note">
  <strong>Operator note:</strong>
  <span class="small">The action forms below create or update the records that justify a zone. The tables further down are the trace view you use when someone asks why a zone is active, what evidence supports it, whether FNAC review happened, and whether any challenge is still open.</span>
</div>

<div class="ops-grid">
  <form method="post" class="card">
    <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>"><input type="hidden" name="action" value="create_evidence_packet">
    <h3>Create evidence packet<?= ops_admin_help_button('Create evidence packet', 'Use this when you need to record or refresh the evidentiary basis for a zone. This is normally the first structured operator step after a zone has been identified or changed.') ?></h3>
    <div class="field"><label>Zone<?= ops_admin_help_button('Zone', 'Select the affected zone that the evidence packet belongs to.') ?></label><select name="zone_id" required><option value="">Select zone</option><?php foreach ($zones as $zone): ?><option value="<?= (int)$zone['id'] ?>"><?= ops_h(($zone['zone_code'] ?? '') . ' — ' . ($zone['zone_name'] ?? '')) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Packet key<?= ops_admin_help_button('Packet key', 'A unique reference for this evidence packet. Leave blank if you want the system to generate one automatically.') ?></label><input name="packet_key" placeholder="Auto if blank"></div>
    <div class="field"><label>Status<?= ops_admin_help_button('Packet status', 'Draft means still assembling. Ready or under review means operators are checking the packet. Approved means it can be relied on as the current evidence basis.') ?></label><select name="status"><option>draft</option><option>ready</option><option>under_review</option><option>approved</option><option>superseded</option></select></div>
    <div class="field"><label>Evidence hash<?= ops_admin_help_button('Evidence hash', 'An optional hash or integrity reference for the packet contents so later operators can verify the evidence body has not changed unexpectedly.') ?></label><input name="evidence_hash"></div>
    <div class="field" style="grid-column:1/-1"><label>Source layers JSON<?= ops_admin_help_button('Source layers JSON', 'Use this to record the source layers or GIS/evidence references that justify the zone, for example G-NAF, cadastral, title, or other published layers.') ?></label><textarea name="source_layers_json" placeholder='[{"source":"G-NAF","version":"2026-04"}]'></textarea></div>
    <div class="actions"><button type="submit">Save evidence packet</button></div>
  </form>

  <form method="post" class="card">
    <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>"><input type="hidden" name="action" value="record_fnac_review">
    <h3>Record FNAC review<?= ops_admin_help_button('Record FNAC review', 'Use this form when the First Nations Advisory Council has reviewed the zone and you need to record the review outcome on the operator ledger.') ?></h3>
    <div class="field"><label>Zone<?= ops_admin_help_button('Zone', 'The affected zone that the FNAC review belongs to.') ?></label><select name="zone_id" required><option value="">Select zone</option><?php foreach ($zones as $zone): ?><option value="<?= (int)$zone['id'] ?>"><?= ops_h(($zone['zone_code'] ?? '') . ' — ' . ($zone['zone_name'] ?? '')) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Review key<?= ops_admin_help_button('Review key', 'The reference for the review record. Leave blank for an auto-generated key.') ?></label><input name="review_key" placeholder="Auto if blank"></div>
    <div class="field"><label>Status<?= ops_admin_help_button('FNAC review status', 'Pending means no final review outcome yet. Endorsed means positive review. Rejected means the zone cannot be treated as settled on FNAC grounds. Withdrawn means the review record was retracted.') ?></label><select name="status"><option>pending</option><option>endorsed</option><option>rejected</option><option>withdrawn</option></select></div>
    <div class="field" style="grid-column:1/-1"><label>Review notes<?= ops_admin_help_button('Review notes', 'Capture the decision basis, conditions, or explanatory remarks from the FNAC review stage.') ?></label><textarea name="review_notes"></textarea></div>
    <div class="actions"><button type="submit">Record FNAC review</button></div>
  </form>

  <form method="post" class="card">
    <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>"><input type="hidden" name="action" value="open_board_signoff">
    <h3>Open Board signoff<?= ops_admin_help_button('Open Board signoff', 'Open this request after the evidence and FNAC stage are ready and you need the formal governance signoff to proceed.') ?></h3>
    <div class="field"><label>Zone<?= ops_admin_help_button('Zone', 'The zone the board signoff request applies to.') ?></label><select name="zone_id" required><option value="">Select zone</option><?php foreach ($zones as $zone): ?><option value="<?= (int)$zone['id'] ?>"><?= ops_h(($zone['zone_code'] ?? '') . ' — ' . ($zone['zone_name'] ?? '')) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Signoff key<?= ops_admin_help_button('Signoff key', 'The operator reference for the board signoff request.') ?></label><input name="signoff_key" placeholder="Auto if blank"></div>
    <div class="field"><label>Required signatures<?= ops_admin_help_button('Required signatures', 'How many signers are needed for this signoff request to count as complete.') ?></label><input type="number" min="1" max="9" name="required_signatures" value="3"></div>
    <div class="actions"><button type="submit">Open signoff request</button></div>
  </form>

  <form method="post" class="card">
    <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>"><input type="hidden" name="action" value="create_eligibility_snapshot">
    <h3>Create eligibility snapshot<?= ops_admin_help_button('Create eligibility snapshot', 'Use this to freeze the in-scope population for a zone at a specific point in time. This is important for later audits, disputes, and local decision votes.') ?></h3>
    <div class="field"><label>Zone<?= ops_admin_help_button('Zone', 'The zone whose eligible member set is being frozen.') ?></label><select name="zone_id" required><option value="">Select zone</option><?php foreach ($zones as $zone): ?><option value="<?= (int)$zone['id'] ?>"><?= ops_h(($zone['zone_code'] ?? '') . ' — ' . ($zone['zone_name'] ?? '')) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Snapshot key<?= ops_admin_help_button('Snapshot key', 'The identifier for this frozen eligibility record.') ?></label><input name="snapshot_key" placeholder="Auto if blank"></div>
    <div class="field"><label>Type<?= ops_admin_help_button('Snapshot type', 'Zone is the usual choice here. Other types exist where the same eligibility mechanism supports a poll or distribution decision.') ?></label><select name="snapshot_type"><option>zone</option><option>poll</option><option>distribution</option><option>other</option></select></div>
    <div class="field"><label>Snapshot hash<?= ops_admin_help_button('Snapshot hash', 'Optional integrity reference for the frozen eligibility record.') ?></label><input name="snapshot_hash"></div>
    <div class="actions"><button type="submit">Create snapshot</button></div>
  </form>

  <form method="post" class="card">
    <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>"><input type="hidden" name="action" value="open_zone_challenge">
    <h3>Open zone challenge<?= ops_admin_help_button('Open zone challenge', 'Use this when a Partner or operator disputes the zone boundary, inclusion logic, or evidentiary basis and wants the issue tracked formally.') ?></h3>
    <div class="field"><label>Zone<?= ops_admin_help_button('Zone', 'The affected zone being challenged.') ?></label><select name="zone_id" required><option value="">Select zone</option><?php foreach ($zones as $zone): ?><option value="<?= (int)$zone['id'] ?>"><?= ops_h(($zone['zone_code'] ?? '') . ' — ' . ($zone['zone_name'] ?? '')) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Challenger member<?= ops_admin_help_button('Challenger member', 'Optional link to the Partner who raised the dispute.') ?></label><select name="challenger_member_id"><option value="">Optional</option><?php foreach ($members as $m): ?><option value="<?= (int)$m['id'] ?>"><?= ops_h(($m['member_number'] ?? '') . ' — ' . ($m['full_name'] ?? '')) ?></option><?php endforeach; ?></select></div>
    <div class="field" style="grid-column:1/-1"><label>Challenge summary<?= ops_admin_help_button('Challenge summary', 'A short statement of what is being disputed so operators can triage it quickly.') ?></label><input name="challenge_summary" required></div>
    <div class="field" style="grid-column:1/-1"><label>Details<?= ops_admin_help_button('Challenge details', 'Longer explanation of why the challenge was opened or what evidence may be in dispute.') ?></label><textarea name="challenge_details"></textarea></div>
    <div class="actions"><button type="submit">Open challenge</button></div>
  </form>
</div>

<div class="card">
  <h3>Zone governance queue<?= ops_admin_help_button('Zone governance queue', 'This is the live queue view. Read this table when you need the quickest explanation of a zone: current status, FNAC state, board state, evidence count, and any ledger reference already recorded.') ?></h3>
  <div class="table-wrap"><table>
    <thead><tr><th>Zone<?= ops_admin_help_button('Zone', 'The code and name for the affected zone.') ?></th><th>Status<?= ops_admin_help_button('Zone status', 'The overall current lifecycle state for the zone.') ?></th><th>FNAC<?= ops_admin_help_button('FNAC', 'The most recent FNAC review state or the derived endorsement state from the zone record.') ?></th><th>Board<?= ops_admin_help_button('Board', 'The most recent board signoff state or the derived board-approval state from the zone record.') ?></th><th>Evidence packets<?= ops_admin_help_button('Evidence packets', 'How many evidence packets have been recorded against this zone.') ?></th><th>Ledger ref<?= ops_admin_help_button('Ledger reference', 'Any recorded ledger/hash value used to trace the zone state.') ?></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty-note">No zone queue rows found.</td></tr><?php endif; ?>
    <?php foreach ($rows as $row): ?><tr>
      <td><?= ops_h(($row['zone_code'] ?? '') . ' — ' . ($row['zone_name'] ?? '')) ?></td>
      <td><?= ops_h($row['zone_status'] ?? '') ?></td>
      <td><?= ops_h($row['latest_fnac_review_status'] ?? ((int)($row['fnac_endorsed'] ?? 0) === 1 ? 'endorsed' : 'pending')) ?></td>
      <td><?= ops_h($row['latest_board_signoff_status'] ?? ((int)($row['board_approved'] ?? 0) === 1 ? 'executed' : 'pending')) ?></td>
      <td><?= (int)($row['evidence_packet_count'] ?? 0) ?></td>
      <td><?= ops_h($row['ledger_tx_hash'] ?? '—') ?></td>
    </tr><?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="grid" style="grid-template-columns:1fr 1fr;gap:18px">
  <div class="card">
    <h3>Evidence packets & FNAC reviews<?= ops_admin_help_button('Evidence packets & FNAC reviews', 'Use this trace table when someone asks: what evidence packet exists for this zone and what was the latest FNAC review outcome?') ?></h3>
    <div class="table-wrap"><table>
      <thead><tr><th>Packet / review<?= ops_admin_help_button('Packet / review', 'The evidence-packet key or FNAC-review key.') ?></th><th>Zone<?= ops_admin_help_button('Zone', 'Which zone the evidence or review record belongs to.') ?></th><th>Status<?= ops_admin_help_button('Status', 'The state of the packet or review row.') ?></th><th>When<?= ops_admin_help_button('When', 'The relevant creation or review timestamp.') ?></th></tr></thead>
      <tbody>
      <?php if (!$packets && !$fnacReviews): ?><tr><td colspan="4" class="empty-note">No evidence or FNAC rows yet.</td></tr><?php endif; ?>
      <?php foreach ($packets as $row): ?><tr><td><?= ops_h($row['packet_key'] ?? '') ?></td><td><?= ops_h(($row['zone_code'] ?? '') . ' — ' . ($row['zone_name'] ?? '')) ?></td><td><?= ops_h($row['status'] ?? '') ?></td><td><?= ops_h($row['created_at'] ?? '') ?></td></tr><?php endforeach; ?>
      <?php foreach ($fnacReviews as $row): ?><tr><td><?= ops_h($row['review_key'] ?? '') ?></td><td><?= ops_h(($row['zone_code'] ?? '') . ' — ' . ($row['zone_name'] ?? '')) ?></td><td><?= ops_h($row['status'] ?? '') ?></td><td><?= ops_h($row['reviewed_at'] ?? $row['created_at'] ?? '') ?></td></tr><?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="card">
    <h3>Board signoffs & snapshots<?= ops_admin_help_button('Board signoffs & snapshots', 'Use this section to confirm that governance signoff occurred and to see whether an eligibility population was frozen into a snapshot.') ?></h3>
    <div class="table-wrap"><table>
      <thead><tr><th>Object<?= ops_admin_help_button('Object', 'The signoff key or snapshot key for the record.') ?></th><th>Zone<?= ops_admin_help_button('Zone', 'Which zone the signoff or snapshot belongs to.') ?></th><th>Status / count<?= ops_admin_help_button('Status / count', 'For signoffs this is the request status and required signatures. For snapshots it is the frozen member count.') ?></th><th>When<?= ops_admin_help_button('When', 'The time the signoff was opened or the snapshot was created.') ?></th></tr></thead>
      <tbody>
      <?php if (!$signoffs && !$snapshots): ?><tr><td colspan="4" class="empty-note">No signoff or snapshot rows yet.</td></tr><?php endif; ?>
      <?php foreach ($signoffs as $row): ?><tr><td><?= ops_h($row['signoff_key'] ?? '') ?></td><td><?= ops_h(($row['zone_code'] ?? '') . ' — ' . ($row['zone_name'] ?? '')) ?></td><td><?= ops_h(($row['status'] ?? '') . ' / ' . (string)($row['required_signatures'] ?? 0)) ?></td><td><?= ops_h($row['opened_at'] ?? '') ?></td></tr><?php endforeach; ?>
      <?php foreach ($snapshots as $row): ?><tr><td><?= ops_h($row['snapshot_key'] ?? '') ?></td><td><?= ops_h(($row['zone_code'] ?? '') . ' — ' . ($row['zone_name'] ?? '')) ?></td><td><?= ops_h((string)($row['member_count'] ?? 0) . ' members') ?></td><td><?= ops_h($row['created_at'] ?? '') ?></td></tr><?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>

<div class="grid" style="grid-template-columns:1fr 1fr;gap:18px">
  <div class="card">
    <h3>Recent address verification activity<?= ops_admin_help_button('Recent address verification activity', 'This is the member-level input into zone eligibility. Use it to see how addresses are being matched into zones and whether verification status is blocking inclusion logic.') ?></h3>
    <div class="table-wrap"><table>
      <thead><tr><th>Member<?= ops_admin_help_button('Member', 'The Partner/member reference for the address check.') ?></th><th>Address<?= ops_admin_help_button('Address', 'The submitted address string used for the verification process.') ?></th><th>Status<?= ops_admin_help_button('Status', 'The state of the address verification attempt.') ?></th><th>Zone<?= ops_admin_help_button('Zone', 'The zone code currently associated with the address, if one has been matched.') ?></th><th>When<?= ops_admin_help_button('When', 'The time the address verification activity was recorded.') ?></th></tr></thead>
      <tbody>
      <?php if (!$addr): ?><tr><td colspan="5" class="empty-note">No address verification rows found.</td></tr><?php endif; ?>
      <?php foreach ($addr as $row): ?><tr>
        <td><?= ops_h($row['member_number'] ?? '') ?></td>
        <td><?= ops_h(trim(($row['input_street'] ?? '') . ' ' . ($row['input_suburb'] ?? '') . ' ' . ($row['input_state'] ?? '') . ' ' . ($row['input_postcode'] ?? ''))) ?></td>
        <td><?= ops_h($row['status'] ?? '') ?></td>
        <td><?= ops_h($row['zone_code'] ?? '—') ?></td>
        <td><?= ops_h($row['created_at'] ?? '') ?></td>
      </tr><?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="card">
    <h3>Zone challenges<?= ops_admin_help_button('Zone challenges', 'This section lists the open or historical disputes about zone boundaries, inclusion, or evidence. Use this when someone asks whether a zone is still contested.') ?></h3>
    <div class="table-wrap"><table>
      <thead><tr><th>Zone<?= ops_admin_help_button('Zone', 'The challenged zone.') ?></th><th>Summary<?= ops_admin_help_button('Summary', 'The short statement of what is being disputed.') ?></th><th>Status<?= ops_admin_help_button('Status', 'Open means still unresolved. Closed or another terminal state means the challenge is historical.') ?></th><th>When<?= ops_admin_help_button('When', 'The time the challenge was opened.') ?></th></tr></thead>
      <tbody>
      <?php if (!$challenges): ?><tr><td colspan="4" class="empty-note">No challenges recorded.</td></tr><?php endif; ?>
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
