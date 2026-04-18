<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/ops_workflow.php';
ops_require_admin();
$pdo = ops_db();
$flash = null; $flashType = 'ok';

if (!function_exists('aud_rows')) {
    function aud_rows(PDO $pdo, string $sql, array $params = []): array {
        try { return ops_fetch_all($pdo, $sql, $params); } catch (Throwable $e) { return []; }
    }
}
if (!function_exists('aud_key')) {
    function aud_key(string $prefix): string {
        return strtoupper($prefix) . '-' . gmdate('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }
}
if (!function_exists('aud_redirect')) {
    function aud_redirect(string $flash, string $type = 'ok'): never {
        $qs = http_build_query(['flash' => $flash, 'type' => $type]);
        header('Location: ' . admin_url('audit.php?' . $qs));
        exit;
    }
}
$canAudit = ops_admin_can($pdo, 'audit.read') || ops_admin_can($pdo, 'infrastructure.manage') || ops_admin_can($pdo, 'admin.full');
$canManage = ops_admin_can($pdo, 'infrastructure.manage') || ops_admin_can($pdo, 'admin.full');
if (isset($_GET['flash'])) { $flash = (string)$_GET['flash']; $flashType = (string)($_GET['type'] ?? 'ok'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
    if (!$canManage) {
        aud_redirect('You do not have permission to manage audit and recovery workflows.', 'error');
    }
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create_audit_run') {
            if (!ops_has_table($pdo, 'audit_runs')) throw new RuntimeException('audit_runs table not available.');
            $auditKey = trim((string)($_POST['audit_key'] ?? '')) ?: aud_key('AUD');
            $auditType = trim((string)($_POST['audit_type'] ?? 'full_stack'));
            $status = trim((string)($_POST['status'] ?? 'opened'));
            $summaryTxt = trim((string)($_POST['summary'] ?? ''));
            if (!in_array($auditType, ['governance','execution','infrastructure','zone','full_stack'], true)) throw new RuntimeException('Invalid audit type.');
            if (!in_array($status, ['opened','collecting','tested','verified','exceptions_found','remediated','closed'], true)) throw new RuntimeException('Invalid audit status.');
            $pdo->prepare('INSERT INTO audit_runs (audit_key, audit_type, status, summary, started_at, closed_at) VALUES (?,?,?,?,NOW(),NULL)')
                ->execute([$auditKey, $auditType, $status, $summaryTxt !== '' ? $summaryTxt : null]);
            aud_redirect('Audit run created: ' . $auditKey . '.');
        }
        if ($action === 'create_snapshot_verification') {
            if (!ops_has_table($pdo, 'snapshot_verifications')) throw new RuntimeException('snapshot_verifications table not available.');
            $verificationKey = trim((string)($_POST['verification_key'] ?? '')) ?: aud_key('SV');
            $snapshotType = trim((string)($_POST['snapshot_type'] ?? 'state_root'));
            $snapshotRef = trim((string)($_POST['snapshot_ref'] ?? ''));
            $verificationStatus = trim((string)($_POST['verification_status'] ?? 'pending'));
            $notes = trim((string)($_POST['notes'] ?? ''));
            if ($snapshotRef === '') throw new RuntimeException('Snapshot ref is required.');
            if (!in_array($snapshotType, ['eligibility','vote','execution','state_root','other'], true)) throw new RuntimeException('Invalid snapshot type.');
            if (!in_array($verificationStatus, ['pending','matched','mismatch','waived'], true)) throw new RuntimeException('Invalid verification status.');
            $verifiedAt = in_array($verificationStatus, ['matched','mismatch','waived'], true) ? date('Y-m-d H:i:s') : null;
            $pdo->prepare('INSERT INTO snapshot_verifications (verification_key, snapshot_type, snapshot_ref, verification_status, notes, verified_at, created_at) VALUES (?,?,?,?,?,?,NOW())')
                ->execute([$verificationKey, $snapshotType, $snapshotRef, $verificationStatus, $notes !== '' ? $notes : null, $verifiedAt]);
            aud_redirect('Snapshot verification recorded: ' . $verificationKey . '.');
        }
        if ($action === 'create_recovery_drill') {
            if (!ops_has_table($pdo, 'recovery_drills')) throw new RuntimeException('recovery_drills table not available.');
            $drillKey = trim((string)($_POST['drill_key'] ?? '')) ?: aud_key('RD');
            $status = trim((string)($_POST['status'] ?? 'planned'));
            $scope = trim((string)($_POST['scope_summary'] ?? ''));
            if (!in_array($status, ['planned','in_progress','completed','failed'], true)) throw new RuntimeException('Invalid recovery drill status.');
            $startedAt = in_array($status, ['in_progress','completed','failed'], true) ? date('Y-m-d H:i:s') : null;
            $completedAt = in_array($status, ['completed','failed'], true) ? date('Y-m-d H:i:s') : null;
            $pdo->prepare('INSERT INTO recovery_drills (drill_key, status, scope_summary, started_at, completed_at) VALUES (?,?,?,?,?)')
                ->execute([$drillKey, $status, $scope !== '' ? $scope : null, $startedAt, $completedAt]);
            aud_redirect('Recovery drill recorded: ' . $drillKey . '.');
        }
        if ($action === 'create_migration_run') {
            if (!ops_has_table($pdo, 'migration_runs')) throw new RuntimeException('migration_runs table not available.');
            $migrationKey = trim((string)($_POST['migration_key'] ?? '')) ?: aud_key('MIG');
            $source = trim((string)($_POST['source_environment'] ?? ''));
            $target = trim((string)($_POST['target_environment'] ?? ''));
            $status = trim((string)($_POST['status'] ?? 'planned'));
            $notes = trim((string)($_POST['notes'] ?? ''));
            if ($source === '' || $target === '') throw new RuntimeException('Source and target environments are required.');
            if (!in_array($status, ['planned','in_progress','completed','failed','rolled_back'], true)) throw new RuntimeException('Invalid migration status.');
            $startedAt = in_array($status, ['in_progress','completed','failed','rolled_back'], true) ? date('Y-m-d H:i:s') : null;
            $completedAt = in_array($status, ['completed','failed','rolled_back'], true) ? date('Y-m-d H:i:s') : null;
            $pdo->prepare('INSERT INTO migration_runs (migration_key, source_environment, target_environment, status, notes, started_at, completed_at) VALUES (?,?,?,?,?,?,?)')
                ->execute([$migrationKey, $source, $target, $status, $notes !== '' ? $notes : null, $startedAt, $completedAt]);
            aud_redirect('Migration run recorded: ' . $migrationKey . '.');
        }
        if ($action === 'create_discrepancy') {
            if (!ops_has_table($pdo, 'ledger_discrepancies')) throw new RuntimeException('ledger_discrepancies table not available.');
            $discrepancyKey = trim((string)($_POST['discrepancy_key'] ?? '')) ?: aud_key('LD');
            $subjectType = trim((string)($_POST['subject_type'] ?? 'execution_request'));
            $subjectId = (int)($_POST['subject_id'] ?? 0);
            $severity = trim((string)($_POST['severity'] ?? 'medium'));
            $summaryTxt = trim((string)($_POST['summary'] ?? ''));
            $details = trim((string)($_POST['details'] ?? ''));
            if ($subjectId < 1) throw new RuntimeException('Subject ID is required.');
            if ($summaryTxt === '') throw new RuntimeException('Summary is required.');
            if (!in_array($severity, ['low','medium','high','critical'], true)) throw new RuntimeException('Invalid severity.');
            $pdo->prepare('INSERT INTO ledger_discrepancies (discrepancy_key, subject_type, subject_id, severity, status, summary, details, created_at, updated_at) VALUES (?,?,?,?,\'open\',?,?,NOW(),NOW())')
                ->execute([$discrepancyKey, $subjectType, $subjectId, $severity, $summaryTxt, $details !== '' ? $details : null]);
            aud_redirect('Ledger discrepancy recorded: ' . $discrepancyKey . '.');
        }
        throw new RuntimeException('Unknown audit action.');
    } catch (Throwable $e) {
        $flash = $e->getMessage();
        $flashType = 'error';
    }
}

$summary = ops_has_table($pdo, 'v_phase1_audit_and_recovery_status') ? aud_rows($pdo, 'SELECT * FROM v_phase1_audit_and_recovery_status') : [];
$walletRows = ops_has_table($pdo, 'wallet_activity') ? aud_rows($pdo, 'SELECT * FROM wallet_activity ORDER BY id DESC LIMIT 50') : [];
$auditRuns = ops_has_table($pdo, 'audit_runs') ? aud_rows($pdo, 'SELECT * FROM audit_runs ORDER BY id DESC LIMIT 20') : [];
$drills = ops_has_table($pdo, 'recovery_drills') ? aud_rows($pdo, 'SELECT * FROM recovery_drills ORDER BY id DESC LIMIT 20') : [];
$migrations = ops_has_table($pdo, 'migration_runs') ? aud_rows($pdo, 'SELECT * FROM migration_runs ORDER BY id DESC LIMIT 20') : [];
$verifications = ops_has_table($pdo, 'snapshot_verifications') ? aud_rows($pdo, 'SELECT * FROM snapshot_verifications ORDER BY id DESC LIMIT 20') : [];
$discrepancies = ops_has_table($pdo, 'ledger_discrepancies') ? aud_rows($pdo, 'SELECT * FROM ledger_discrepancies ORDER BY id DESC LIMIT 20') : [];

$openAudit = 0; $openRecovery = 0; $openMigration = 0; $openDiscrepancies = 0;
foreach ($auditRuns as $row) if (!in_array(($row['status'] ?? ''), ['closed','verified','remediated'], true)) $openAudit++;
foreach ($drills as $row) if (!in_array(($row['status'] ?? ''), ['completed'], true)) $openRecovery++;
foreach ($migrations as $row) if (!in_array(($row['status'] ?? ''), ['completed'], true)) $openMigration++;
foreach ($discrepancies as $row) if (!in_array(($row['status'] ?? ''), ['resolved','waived'], true)) $openDiscrepancies++;

ob_start();
ops_admin_help_assets_once(); ?>
<style>
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}
.ops-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}
.field{display:grid;gap:6px}
.field label{font-size:.86rem;color:var(--muted);font-weight:600}
.field input,.field select,.field textarea{width:100%;padding:.85rem 1rem;border-radius:12px;border:1px solid var(--line);background:rgba(255,255,255,.03);color:var(--text)}
.field textarea{min-height:88px;resize:vertical}
.stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.stat{padding:16px;border-radius:18px;background:rgba(255,255,255,.03);border:1px solid var(--line)}
.stat strong{display:block;font-size:1.45rem}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
@media (max-width:980px){.form-grid,.ops-grid,.stat-grid{grid-template-columns:1fr}}
</style>
<?= ops_admin_info_panel(
    'Stage 7 — Audit, diagnostics, and control review',
    'What this page does',
    'Audit / Recovery is the authoritative control-review page for audit runs, snapshot verification, recovery drills, migration rehearsals, and discrepancy logging. Use it to record structured control evidence and test resilience, not to perform the operational fix itself.',
    [
        'Create audit runs when you need a formal review trail for governance, execution, infrastructure, zones, or the whole stack.',
        'Record snapshot verifications when a state root, vote snapshot, eligibility snapshot, or execution snapshot has been checked against source evidence.',
        'Use recovery and migration forms to document rehearsal activity, not just production incidents.',
    ]
) ?>

<?= ops_admin_workflow_panel(
    'Typical workflow',
    'This page records control activity. It usually sits beside another operational page rather than replacing it.',
    [
        ['title' => 'Open a review object', 'body' => 'Create the audit run, verification, drill, migration record, or discrepancy.'],
        ['title' => 'Perform the real check', 'body' => 'Carry out the governance, execution, infrastructure, or data review on the relevant operational page.'],
        ['title' => 'Record the outcome', 'body' => 'Update the audit object here so the review trail is visible and reusable.'],
        ['title' => 'Use bridge activity only diagnostically', 'body' => 'Legacy wallet/admin activity below is retained for traceability, not as the authoritative ledger.'],
    ]
) ?>

<?= ops_admin_guide_panel(
    'How to use this page',
    'Each form below creates a different kind of control-review record. Choose the one that matches the review you are performing.',
    [
        ['title' => 'Create audit run', 'body' => 'Use for broad control reviews such as governance, execution, infrastructure, zones, or full-stack checks.'],
        ['title' => 'Snapshot verification', 'body' => 'Use when a specific snapshot or hash needs to be checked and recorded.'],
        ['title' => 'Recovery drill', 'body' => 'Use for controlled resilience or restoration practice.'],
        ['title' => 'Migration rehearsal', 'body' => 'Use when testing movement between environments or ledger infrastructure.'],
        ['title' => 'Ledger discrepancy', 'body' => 'Use when the recorded state does not reconcile and a tracked issue must be opened.'],
    ]
) ?>

<?= ops_admin_status_panel(
    'Status guide',
    'These statuses show whether the review object is still open, actively being worked, or has reached a settled result.',
    [
        ['label' => 'opened / collecting / tested', 'body' => 'The review is still active and evidence gathering or checking is in progress.'],
        ['label' => 'verified / remediated / closed', 'body' => 'The review reached a settled outcome and no longer needs active operator focus.'],
        ['label' => 'pending / matched / mismatch / waived', 'body' => 'Snapshot verification states showing whether a record still needs checking or has a confirmed result.'],
        ['label' => 'completed / failed / rolled_back', 'body' => 'Recovery and migration outcomes showing whether the rehearsal succeeded or had to be stopped.'],
    ]
) ?>

<div class="card">
  <h2 style="margin:0 0 8px">Audit / recovery<?= ops_admin_help_button('Audit / recovery', 'This page is the authoritative control-review surface for audit runs, snapshot verifications, drills, rehearsals, and discrepancy records.') ?></h2>
  <p class="muted">This is the authoritative audit and recovery page for audit runs, snapshot verification, recovery drills, migration rehearsals, and discrepancy logging. Use legacy bridge screens for diagnostics only, not as the operational audit path.</p>
  <div class="stat-grid" style="margin-top:14px">
    <div class="stat"><span class="muted">Open audits</span><strong><?= (int)$openAudit ?></strong></div>
    <div class="stat"><span class="muted">Recovery drills live</span><strong><?= (int)$openRecovery ?></strong></div>
    <div class="stat"><span class="muted">Migration runs live</span><strong><?= (int)$openMigration ?></strong></div>
    <div class="stat"><span class="muted">Open discrepancies</span><strong><?= (int)$openDiscrepancies ?></strong></div>
  </div>
</div>
<?php if (!$canManage): ?>
  <div class="err">You have read access only on this page.</div>
<?php endif; ?>
<div class="ops-grid">
  <form method="post" class="card">
    <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>"><input type="hidden" name="action" value="create_audit_run">
    <h3 style="margin:0 0 12px">Create audit run<?= ops_admin_help_button('Create audit run', 'Use this to open a formal audit object for a review that will be tracked over time.') ?></h3>
    <div class="field"><label>Audit key</label><input name="audit_key" placeholder="Auto if blank"></div>
    <div class="field"><label>Type</label><select name="audit_type"><option>full_stack</option><option>governance</option><option>execution</option><option>infrastructure</option><option>zone</option></select></div>
    <div class="field"><label>Status</label><select name="status"><option>opened</option><option>collecting</option><option>tested</option><option>verified</option><option>exceptions_found</option><option>remediated</option><option>closed</option></select></div>
    <div class="field" style="grid-column:1/-1"><label>Summary</label><textarea name="summary"></textarea></div>
    <div class="actions"><button type="submit">Create audit run</button></div>
  </form>

  <form method="post" class="card">
    <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>"><input type="hidden" name="action" value="create_snapshot_verification">
    <h3 style="margin:0 0 12px">Record snapshot verification<?= ops_admin_help_button('Snapshot verification', 'Use this when you need to document whether a specific snapshot reference matched source evidence or not.') ?></h3>
    <div class="field"><label>Verification key</label><input name="verification_key" placeholder="Auto if blank"></div>
    <div class="field"><label>Snapshot type</label><select name="snapshot_type"><option>state_root</option><option>eligibility</option><option>vote</option><option>execution</option><option>other</option></select></div>
    <div class="field"><label>Snapshot ref</label><input name="snapshot_ref" required></div>
    <div class="field"><label>Status</label><select name="verification_status"><option>pending</option><option>matched</option><option>mismatch</option><option>waived</option></select></div>
    <div class="field" style="grid-column:1/-1"><label>Notes</label><textarea name="notes"></textarea></div>
    <div class="actions"><button type="submit">Save verification</button></div>
  </form>

  <form method="post" class="card">
    <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>"><input type="hidden" name="action" value="create_recovery_drill">
    <h3 style="margin:0 0 12px">Record recovery drill<?= ops_admin_help_button('Recovery drill', 'A structured rehearsal of restore, failover, or other resilience procedures.') ?></h3>
    <div class="field"><label>Drill key</label><input name="drill_key" placeholder="Auto if blank"></div>
    <div class="field"><label>Status</label><select name="status"><option>planned</option><option>in_progress</option><option>completed</option><option>failed</option></select></div>
    <div class="field" style="grid-column:1/-1"><label>Scope summary</label><textarea name="scope_summary"></textarea></div>
    <div class="actions"><button type="submit">Save recovery drill</button></div>
  </form>

  <form method="post" class="card">
    <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>"><input type="hidden" name="action" value="create_migration_run">
    <h3 style="margin:0 0 12px">Record migration rehearsal<?= ops_admin_help_button('Migration rehearsal', 'Use this for planned environment or ledger migration testing so there is a visible rehearsal record.') ?></h3>
    <div class="field"><label>Migration key</label><input name="migration_key" placeholder="Auto if blank"></div>
    <div class="field"><label>Source environment</label><input name="source_environment" required placeholder="phase1-parallel"></div>
    <div class="field"><label>Target environment</label><input name="target_environment" required placeholder="fcn"></div>
    <div class="field"><label>Status</label><select name="status"><option>planned</option><option>in_progress</option><option>completed</option><option>failed</option><option>rolled_back</option></select></div>
    <div class="field" style="grid-column:1/-1"><label>Notes</label><textarea name="notes"></textarea></div>
    <div class="actions"><button type="submit">Save migration run</button></div>
  </form>

  <form method="post" class="card">
    <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>"><input type="hidden" name="action" value="create_discrepancy">
    <h3 style="margin:0 0 12px">Record ledger discrepancy<?= ops_admin_help_button('Ledger discrepancy', 'Open one of these when recorded state does not reconcile and the mismatch needs a tracked investigation.') ?></h3>
    <div class="field"><label>Discrepancy key</label><input name="discrepancy_key" placeholder="Auto if blank"></div>
    <div class="field"><label>Subject type</label><input name="subject_type" value="execution_request"></div>
    <div class="field"><label>Subject ID</label><input type="number" min="1" name="subject_id" required></div>
    <div class="field"><label>Severity</label><select name="severity"><option>medium</option><option>low</option><option>high</option><option>critical</option></select></div>
    <div class="field" style="grid-column:1/-1"><label>Summary</label><input name="summary" required></div>
    <div class="field" style="grid-column:1/-1"><label>Details</label><textarea name="details"></textarea></div>
    <div class="actions"><button type="submit">Save discrepancy</button></div>
  </form>
</div>
<div class="card">
  <h3 style="margin:0 0 12px">Audit summary<?= ops_admin_help_button('Audit summary', 'This is a compact health view of review objects already recorded in the system.') ?></h3>
  <div class="table-wrap"><table>
    <thead><tr><th>Subject</th><th>Total</th><th>Open</th><th>Latest activity</th></tr></thead>
    <tbody>
    <?php if (!$summary): ?><tr><td colspan="4">No audit summary rows found.</td></tr><?php endif; ?>
    <?php foreach ($summary as $row): ?><tr>
      <td><?= ops_h($row['subject_type'] ?? '') ?></td>
      <td><?= (int)($row['total_records'] ?? 0) ?></td>
      <td><?= (int)($row['open_records'] ?? 0) ?></td>
      <td><?= ops_h($row['latest_activity_at'] ?? '—') ?></td>
    </tr><?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<div class="grid" style="grid-template-columns:1fr 1fr;gap:18px">
  <div class="card">
    <h3 style="margin:0 0 12px">Audit runs & verifications<?= ops_admin_help_button('Audit runs and verifications', 'This table shows the most recent formal audit runs and snapshot verification records together.') ?></h3>
    <div class="table-wrap"><table>
      <thead><tr><th>Object</th><th>Type</th><th>Status</th><th>When</th></tr></thead>
      <tbody>
      <?php if (!$auditRuns && !$verifications): ?><tr><td colspan="4">No audit/verification rows yet.</td></tr><?php endif; ?>
      <?php foreach ($auditRuns as $row): ?><tr><td><?= ops_h($row['audit_key'] ?? '') ?></td><td><?= ops_h($row['audit_type'] ?? '') ?></td><td><?= ops_h($row['status'] ?? '') ?></td><td><?= ops_h($row['started_at'] ?? '') ?></td></tr><?php endforeach; ?>
      <?php foreach ($verifications as $row): ?><tr><td><?= ops_h($row['verification_key'] ?? '') ?></td><td><?= ops_h($row['snapshot_type'] ?? '') ?></td><td><?= ops_h($row['verification_status'] ?? '') ?></td><td><?= ops_h($row['verified_at'] ?? $row['created_at'] ?? '') ?></td></tr><?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="card">
    <h3 style="margin:0 0 12px">Recovery, migration, and discrepancies<?= ops_admin_help_button('Recovery, migration, and discrepancies', 'These records help you see resilience testing and open mismatch issues in one place.') ?></h3>
    <div class="table-wrap"><table>
      <thead><tr><th>Object</th><th>Status</th><th>Scope / summary</th><th>When</th></tr></thead>
      <tbody>
      <?php if (!$drills && !$migrations && !$discrepancies): ?><tr><td colspan="4">No recovery/migration/discrepancy rows yet.</td></tr><?php endif; ?>
      <?php foreach ($drills as $row): ?><tr><td><?= ops_h($row['drill_key'] ?? '') ?></td><td><?= ops_h($row['status'] ?? '') ?></td><td><?= ops_h($row['scope_summary'] ?? '—') ?></td><td><?= ops_h($row['started_at'] ?? $row['completed_at'] ?? '') ?></td></tr><?php endforeach; ?>
      <?php foreach ($migrations as $row): ?><tr><td><?= ops_h($row['migration_key'] ?? '') ?></td><td><?= ops_h($row['status'] ?? '') ?></td><td><?= ops_h(($row['source_environment'] ?? '') . ' → ' . ($row['target_environment'] ?? '')) ?></td><td><?= ops_h($row['started_at'] ?? $row['completed_at'] ?? '') ?></td></tr><?php endforeach; ?>
      <?php foreach ($discrepancies as $row): ?><tr><td><?= ops_h($row['discrepancy_key'] ?? '') ?></td><td><?= ops_h($row['status'] ?? '') ?></td><td><?= ops_h($row['summary'] ?? '') ?></td><td><?= ops_h($row['created_at'] ?? '') ?></td></tr><?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>
<div class="card">
  <p class="muted small" style="margin:0 0 10px">These rows are retained as bridge trace data while legacy wallet and admin activity is retired. Use them for reconciliation and dependency checks, not as the authoritative audit ledger.</p>
  <h3 style="margin:0 0 12px">Bridge wallet/admin activity (diagnostic)<?= ops_admin_help_button('Bridge wallet/admin activity', 'Retained for traceability while old pathways are being retired. This is not the authoritative audit ledger.') ?></h3>
  <div class="table-wrap"><table>
    <thead><tr><th>ID</th><th>When</th><th>Action</th><th>Actor</th><th>Member</th></tr></thead>
    <tbody>
    <?php if(!$walletRows): ?><tr><td colspan="5">No wallet activity rows found.</td></tr><?php endif; ?>
    <?php foreach($walletRows as $r): ?>
      <tr>
        <td><?= (int)($r['id'] ?? 0) ?></td>
        <td><?= ops_h($r['created_at'] ?? '') ?></td>
        <td><?= ops_h((string)($r['action_type'] ?? '')) ?></td>
        <td><?= ops_h((string)($r['actor_type'] ?? '')) ?></td>
        <td><?= ops_h((string)($r['member_id'] ?? '—')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php $body = ob_get_clean(); ops_render_page('Audit / Recovery', 'audit', $body, $flash, $flashType); ?>
