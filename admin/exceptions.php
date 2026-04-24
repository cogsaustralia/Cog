<?php
require_once __DIR__ . '/includes/ops_workflow.php';
ops_require_admin();
$pdo = ops_db();
$flash=''; $flashType='ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
    try {
        if (!ops_table_exists($pdo, 'admin_exceptions')) throw new RuntimeException('admin_exceptions table missing.');
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_exception') {
            $id = (int)($_POST['exception_id'] ?? 0);
            $type = trim((string)($_POST['exception_type'] ?? 'general'));
            $severity = trim((string)($_POST['severity'] ?? 'medium'));
            $memberId = (int)($_POST['member_id'] ?? 0);
            $summary = trim((string)($_POST['summary'] ?? ''));
            $details = trim((string)($_POST['details'] ?? ''));
            $status = trim((string)($_POST['status'] ?? 'open'));
            if ($summary === '') throw new RuntimeException('Summary is required.');
            if ($id > 0) {
                $pdo->prepare('UPDATE admin_exceptions SET exception_type=?, severity=?, member_id=?, summary=?, details=?, status=?, resolved_by_admin_id=?, resolved_at=?, updated_at=? WHERE id=?')
                    ->execute([$type, $severity, $memberId ?: null, $summary, $details ?: null, $status, $status === 'resolved' ? ops_admin_id() : null, $status === 'resolved' ? ops_now() : null, ops_now(), $id]);
                $flash='Exception updated.';
            } else {
                $pdo->prepare('INSERT INTO admin_exceptions (exception_type, severity, member_id, summary, details, status, created_by_admin_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$type, $severity, $memberId ?: null, $summary, $details ?: null, $status, ops_admin_id(), ops_now(), ops_now()]);
                $flash='Exception created.';
            }
        }
        if ($action === 'refresh_from_system') {
            $items = ops_collect_system_exceptions($pdo);
            foreach ($items as $it) {
                $exists = ops_fetch_one($pdo, 'SELECT id FROM admin_exceptions WHERE status <> ? AND exception_type = ? AND member_id <=> ? AND summary = ? ORDER BY id DESC LIMIT 1', ['resolved', $it['exception_type'], $it['member_id'] ?? null, $it['summary']]);
                if (!$exists) {
                    $pdo->prepare('INSERT INTO admin_exceptions (exception_type, severity, member_id, summary, details, status, created_by_admin_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$it['exception_type'], $it['severity'], $it['member_id'] ?? null, $it['summary'], $it['details'] ?? null, 'open', ops_admin_id(), ops_now(), ops_now()]);
                }
            }
            $flash='System exceptions refreshed.';
        }
    } catch (Throwable $e) {
        $flash = $e->getMessage();
        $flashType = 'err';
    }
}
$members = ops_fetch_all($pdo, 'SELECT id, full_name, member_number, email FROM members ORDER BY id DESC LIMIT 200');

// ── Pagination ─────────────────────────────────────────────────────────────────
$perPage = 20;
$page    = max(1, (int)($_GET['page'] ?? 1));

// Stat counts from DB (always totals, unaffected by pagination)
$openCount     = 0; $reviewCount = 0; $resolvedCount = 0; $criticalCount = 0;
if (ops_table_exists($pdo, 'admin_exceptions')) {
    $openCount     = (int)ops_fetch_one($pdo, "SELECT COUNT(*) AS c FROM admin_exceptions WHERE status='open'")['c'];
    $reviewCount   = (int)ops_fetch_one($pdo, "SELECT COUNT(*) AS c FROM admin_exceptions WHERE status='in_progress'")['c'];
    $resolvedCount = (int)ops_fetch_one($pdo, "SELECT COUNT(*) AS c FROM admin_exceptions WHERE status='resolved'")['c'];
    $criticalCount = (int)ops_fetch_one($pdo, "SELECT COUNT(*) AS c FROM admin_exceptions WHERE status<>'resolved' AND severity = 'high'")['c'];
}

$totalExceptions = $openCount + $reviewCount + $resolvedCount;
$totalPages      = max(1, (int)ceil($totalExceptions / $perPage));
$page            = min($page, $totalPages);
$offset          = ($page - 1) * $perPage;

$rows = ops_table_exists($pdo, 'admin_exceptions') ? ops_fetch_all($pdo, 'SELECT ae.*, m.full_name, m.member_number FROM admin_exceptions ae LEFT JOIN members m ON m.id = ae.member_id ORDER BY FIELD(ae.status, "open", "in_progress", "resolved"), FIELD(ae.severity,"high","medium","low"), ae.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset) : [];

if (!function_exists('render_pager')) {
    function render_pager(string $base, int $page, int $totalPages, int $total, string $label = 'result'): string {
        if ($totalPages <= 1 && $total <= 20) return '';
        $sfx = $total !== 1 ? 's' : '';
        $ue  = fn(int $pg): string => htmlspecialchars($base . 'page=' . $pg, ENT_QUOTES, 'UTF-8');
        $o   = '<div class="pager"><span class="pg-info">' . number_format($total) . ' ' . $label . $sfx . '</span>';
        if ($page > 1) {
            $o .= '<a href="' . $ue(1) . '">«</a><a href="' . $ue($page - 1) . '">‹ Prev</a>';
        } else { $o .= '<span>«</span><span>‹ Prev</span>'; }
        for ($pg = max(1, $page - 2); $pg <= min($totalPages, $page + 2); $pg++) {
            $o .= $pg === $page
                ? '<span class="pg-current">' . $pg . '</span>'
                : '<a href="' . $ue($pg) . '">' . $pg . '</a>';
        }
        if ($page < $totalPages) {
            $o .= '<a href="' . $ue($page + 1) . '">Next ›</a><a href="' . $ue($totalPages) . '">»</a>';
        } else { $o .= '<span>Next ›</span><span>»</span>'; }
        return $o . '</div>';
    }
}

$statusItems = [
    ['label' => 'open', 'body' => 'New or unresolved issue. These should be triaged first.'],
    ['label' => 'in_progress', 'body' => 'Under investigation. Evidence or operator action is still being gathered.'],
    ['label' => 'resolved', 'body' => 'Closed with a clear outcome and resolution note in the record.'],
    ['label' => 'critical / high severity', 'body' => 'Operational, legal, or ledger-impacting items that should be prioritised.'],
];

ob_start();
ops_admin_help_assets_once();
?>
<style>
.exception-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:18px}
.exception-header{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.exception-meta{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:16px}
.exception-stat{padding:14px;border-radius:14px;background:rgba(255,255,255,.03);border:1px solid var(--line)}
.exception-stat .k{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:6px}
.exception-stat strong{font-size:1.35rem;display:block}
.exception-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.exception-form-grid .full{grid-column:1/-1}
@media(max-width:980px){.exception-grid,.exception-meta,.exception-form-grid{grid-template-columns:1fr}}
</style>
<?= ops_admin_collapsible_help('Page guide & workflow', [
  ops_admin_info_panel('Stage 7 — Exceptions', 'What this page does', 'Exceptions is the structured work queue for issues that need human review. Use it to log, triage, investigate, and close operational, compliance, and ledger-related anomalies.', [
    'Refresh from system to pull in newly detected issues automatically.',
    'Use create / update when an operator needs to record a manual issue or close a resolved item.',
    'Treat this page as the exceptions register, not as the source page for the fix.',
  ]),
  ops_admin_workflow_panel('Typical workflow', 'A clean exception workflow keeps the control plane auditable.', [
    ['title' => 'Identify', 'body' => 'Refresh from system or create a manual exception when an issue is found.'],
    ['title' => 'Triage', 'body' => 'Set severity and status so operators can see what needs urgent action.'],
    ['title' => 'Investigate', 'body' => 'Use the member link and details text to gather evidence.'],
    ['title' => 'Resolve', 'body' => 'Update the record to resolved once the root cause has been addressed.'],
  ]),
  ops_admin_guide_panel('How to use this page', 'This page has two jobs: maintain the live exceptions queue and let operators create or update records.', [
    ['title' => 'Exceptions work queue', 'body' => 'Read top to bottom. Open and critical items are the main operator focus.'],
    ['title' => 'Refresh from system', 'body' => 'Collects new automatically-detected issues without duplicating already-open items.'],
    ['title' => 'Create / update exception', 'body' => 'Use for manual issues, status changes, severity escalation, or recording resolution.'],
  ]),
  ops_admin_status_panel('Status guide', 'These statuses tell operators whether an exception is still waiting or has been closed.', $statusItems),
]) ?>
<div class="card">
  <div class="card-head exception-header">
    <div>
      <h2 style="margin:0">Exceptions work queue<?= ops_admin_help_button('Exceptions work queue', 'This table is the live control register for operational issues that still need attention. Read open and high-severity items first.') ?></h2>
      <p class="muted" style="margin:8px 0 0">Use this register to see what is still open, what is under review, and what has already been resolved.</p>
    </div>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
      <input type="hidden" name="action" value="refresh_from_system">
      <button class="btn-secondary" type="submit">Refresh from system<?= ops_admin_help_button('Refresh from system', 'Pulls in newly detected system exceptions that are not already open. It does not close or change existing exception records.') ?></button>
    </form>
  </div>
  <div class="card-body">
  <div class="exception-meta">
    <?php
      // Stat counts are pre-computed from DB above — already available as $openCount etc.
    ?>
    <div class="exception-stat"><div class="k">Open exceptions</div><strong><?= (int)$openCount ?></strong></div>
    <div class="exception-stat"><div class="k">In progress</div><strong><?= (int)$reviewCount ?></strong></div>
    <div class="exception-stat"><div class="k">Resolved</div><strong><?= (int)$resolvedCount ?></strong></div>
    <div class="exception-stat"><div class="k">High severity still open</div><strong><?= (int)$criticalCount ?></strong></div>
  </div>
  <div class="table-wrap" style="margin-top:16px"><table>
    <thead><tr><th>ID</th><th>Type<?= ops_admin_help_button('Exception type', 'The category of issue. Use it to see whether the problem is operational, compliance-related, wallet-related, or another tracked exception class.') ?></th><th>Severity<?= ops_admin_help_button('Severity', 'Severity indicates operator priority. Critical and high items should be addressed before low-risk informational issues.') ?></th><th>Member<?= ops_admin_help_button('Linked member', 'If a specific Member is affected, the row will show the linked person and their Member number.') ?></th><th>Summary<?= ops_admin_help_button('Summary and details', 'The summary should state the issue plainly. The details line should explain context, evidence, or what still needs to be checked.') ?></th><th>Status<?= ops_admin_help_button('Exception status', 'Open means unresolved, in_progress means actively being investigated, and resolved means the issue has been closed.') ?></th><th>Updated</th></tr></thead>
    <tbody>
    <?php if(!$rows): ?><tr><td colspan="7">No exceptions found.</td></tr><?php endif; ?>
    <?php foreach($rows as $r): ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td><?= ops_h($r['exception_type']) ?></td>
        <td><?= ops_h($r['severity']) ?></td>
        <td><?= ops_h((string)($r['full_name'] ?? '—')) ?><?php if(!empty($r['member_number'])): ?><div class="muted"><?= ops_h($r['member_number']) ?></div><?php endif; ?></td>
        <td><?= ops_h($r['summary']) ?><div class="muted"><?= ops_h((string)$r['details']) ?></div></td>
        <td><?= ops_h($r['status']) ?></td>
        <td><?= ops_h($r['updated_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= render_pager('exceptions.php?', $page, $totalPages, $totalExceptions, 'exception') ?>
</div>

<div class="card" style="margin-top:18px">
  <div class="card-head">
    <h2>Create / update exception <?= ops_admin_help_button('Create / update exception', 'Use this form to add a manual exception, raise or lower severity, change status during investigation, or record the final resolution.') ?></h2>
  </div>
  <div class="card-body">
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
    <input type="hidden" name="action" value="save_exception">
    <div class="exception-form-grid">
      <div class="field"><label>Exception ID (leave blank for new)<?= ops_admin_help_button('Exception ID', 'Enter an existing ID only when you intend to update a current exception record. Leave blank to create a new one.') ?></label><input type="number" name="exception_id" min="0"></div>
      <div class="field"><label>Type<?= ops_admin_help_button('Type', 'Choose the best available category so reporting and filtering stay meaningful.') ?></label><select name="exception_type"><?php foreach(ops_exception_types() as $t): ?><option value="<?= ops_h($t) ?>"><?= ops_h($t) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Severity<?= ops_admin_help_button('Severity', 'Use severity to show operator urgency, not just technical complexity.') ?></label><select name="severity"><?php foreach(ops_exception_severities() as $s): ?><option value="<?= ops_h($s) ?>"><?= ops_h($s) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Status<?= ops_admin_help_button('Status', 'Move to resolved only after the underlying issue has truly been fixed or formally waived.') ?></label><select name="status"><option value="open">open</option><option value="in_progress">in_progress</option><option value="resolved">resolved</option></select></div>
      <div class="field"><label>Member<?= ops_admin_help_button('Member', 'Attach a Member when the exception is tied to a specific person, vault, or account path.') ?></label><select name="member_id"><option value="0">— none —</option><?php foreach($members as $m): ?><option value="<?= (int)$m['id'] ?>"><?= ops_h($m['full_name'].' · '.$m['member_number']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Summary<?= ops_admin_help_button('Summary', 'Write the issue in one clear sentence.') ?></label><input name="summary"></div>
      <div class="field full"><label>Details<?= ops_admin_help_button('Details', 'Context, evidence, what was checked, and what page or workflow is affected.') ?></label><textarea name="details"></textarea></div>
    </div>
    <div class="actions"><button class="btn btn-gold" type="submit">Save exception</button></div>
  </form>
  </div>
</div>
<?php
$body = ob_get_clean();
ops_render_page('Exceptions', 'exceptions', $body, $flash, $flashType);
