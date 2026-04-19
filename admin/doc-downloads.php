<?php
require_once __DIR__ . '/includes/ops_workflow.php';
ops_require_admin();
$pdo = ops_db();

// ── Fetch all download events ──
$rows = $pdo->query("
    SELECT we.id, we.subject_ref AS member_number, we.description AS filename, we.created_at,
           sm.full_name, sm.email
    FROM wallet_events we
    LEFT JOIN snft_memberships sm ON sm.member_number = we.subject_ref
    WHERE we.event_type = 'document_download'
    ORDER BY we.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Aggregate: downloads per document ──
$byDoc = [];
foreach ($rows as $r) {
    $fn = $r['filename'] ?: '(unknown)';
    if (!isset($byDoc[$fn])) $byDoc[$fn] = ['count' => 0, 'members' => []];
    $byDoc[$fn]['count']++;
    $byDoc[$fn]['members'][] = $r;
}
ksort($byDoc);

// ── Aggregate: downloads per member ──
$byMember = [];
foreach ($rows as $r) {
    $mn = $r['member_number'] ?: '(unknown)';
    if (!isset($byMember[$mn])) $byMember[$mn] = ['name' => $r['full_name'] ?? '—', 'email' => $r['email'] ?? '', 'downloads' => []];
    $byMember[$mn]['downloads'][] = $r;
}
ksort($byMember);

// ── Which view? ──
$view = $_GET['view'] ?? 'documents';  // 'documents' or 'members'
$filterDoc = $_GET['doc'] ?? '';
$filterMember = $_GET['mn'] ?? '';

ob_start(); ?>
<?= ops_admin_collapsible_help('Page guide & workflow', [
  ops_admin_info_panel('Document activity', 'What this page does', 'Use this page to review which governing or reference documents have been downloaded from the library, which members downloaded them, and how often a document has been accessed. This is a traceability page, not a publishing page.', [
    'Use By Document when you want to see which files are being accessed most often.',
    'Use By Member when you want to see which documents a specific member has downloaded.',
    'Use Full Log when you need the raw chronological download trail.'
]),
  ops_admin_workflow_panel('Typical workflow', 'This page is usually used for review, evidence, or follow-up rather than direct operational action.', [
    ['title' => 'Choose the right view', 'body' => 'Start with By Document, By Member, or Full Log depending on what question you are trying to answer.'],
    ['title' => 'Drill into the relevant record', 'body' => 'Open a document detail or member detail view to see exactly who downloaded what and when.'],
    ['title' => 'Use it as evidence', 'body' => 'Treat this page as a traceability record when confirming document access history.'],
]),
  ops_admin_status_panel('How to read this page', 'The three views below answer different questions about document access.', [
    ['label' => 'By Document', 'body' => 'Best for seeing which files are being accessed and by how many members.'],
    ['label' => 'By Member', 'body' => 'Best for reviewing one member\'s document-access history.'],
    ['label' => 'Full Log', 'body' => 'Best for the raw chronological trail of all logged download events.'],
]),
]) ?>
<div class="card">
  <div class="card-head"><h2>📥 Document Downloads<?= ops_admin_help_button('Document downloads', 'This page shows logged document-download events pulled from wallet events. It helps operators review who accessed governing documents and when.') ?></h2>
  <p class="muted">Track which members download governing documents from the community library. <?= count($rows) ?> total downloads logged.</p>

  <!-- View tabs -->
  <div style="display:flex;gap:8px;margin:16px 0">
    <a href="?view=documents" title="View downloads grouped by document" style="padding:6px 14px;border-radius:8px;font-size:13px;text-decoration:none;<?= $view === 'documents' ? 'background:#c8973e;color:#1a0f00;font-weight:600;' : 'background:rgba(200,151,62,.1);color:#c8973e;border:1px solid rgba(200,151,62,.2);' ?>">By Document</a>
    <a href="?view=members" title="View downloads grouped by member" style="padding:6px 14px;border-radius:8px;font-size:13px;text-decoration:none;<?= $view === 'members' ? 'background:#c8973e;color:#1a0f00;font-weight:600;' : 'background:rgba(200,151,62,.1);color:#c8973e;border:1px solid rgba(200,151,62,.2);' ?>">By Member</a>
    <a href="?view=all" title="View the full chronological download log" style="padding:6px 14px;border-radius:8px;font-size:13px;text-decoration:none;<?= $view === 'all' ? 'background:#c8973e;color:#1a0f00;font-weight:600;' : 'background:rgba(200,151,62,.1);color:#c8973e;border:1px solid rgba(200,151,62,.2);' ?>">Full Log</a>
  </div>

  <?php if ($view === 'documents' && !$filterDoc): ?>
  <!-- ═══ BY DOCUMENT ═══ -->
  <div class="table-wrap"><table>
    <thead><tr><th>Document<?= ops_admin_help_button('Document', 'The filename or document label that was downloaded.') ?></th><th>Downloads<?= ops_admin_help_button('Downloads', 'Total logged download events for the document.') ?></th><th>Unique members<?= ops_admin_help_button('Unique members', 'How many distinct member numbers appear in the download records for this document.') ?></th><th></th></tr></thead>
    <tbody>
    <?php if (!$byDoc): ?><tr><td colspan="4">No downloads recorded yet.</td></tr><?php endif; ?>
    <?php foreach ($byDoc as $fn => $d):
        $uniqueMembers = count(array_unique(array_column($d['members'], 'member_number')));
    ?>
      <tr>
        <td><strong><?= ops_h($fn) ?></strong></td>
        <td><?= $d['count'] ?></td>
        <td><?= $uniqueMembers ?></td>
        <td><a href="?view=documents&doc=<?= urlencode($fn) ?>" style="font-size:12px;color:#c8973e">View details →</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <?php elseif ($view === 'documents' && $filterDoc): ?>
  <!-- ═══ SINGLE DOCUMENT DETAIL ═══ -->
  <p><a href="?view=documents" title="View downloads grouped by document" style="color:#c8973e;font-size:13px">← Back to all documents</a></p>
  <h3><?= ops_h($filterDoc) ?></h3>
  <div class="table-wrap"><table>
    <thead><tr><th>When</th><th>Member<?= ops_admin_help_button('Member', 'The member name linked to the download event, where available.') ?></th><th>Member Number<?= ops_admin_help_button('Member Number', 'The member identifier linked to the download event.') ?></th><th>Email</th></tr></thead>
    <tbody>
    <?php
    $docRows = $byDoc[$filterDoc]['members'] ?? [];
    if (!$docRows): ?><tr><td colspan="4">No downloads for this document.</td></tr><?php endif;
    foreach ($docRows as $r): ?>
      <tr>
        <td><?= ops_h($r['created_at']) ?></td>
        <td><?= ops_h($r['full_name'] ?? '—') ?></td>
        <td><a href="?view=members&mn=<?= urlencode($r['member_number']) ?>" style="color:#c8973e;font-family:monospace;font-size:12px"><?= ops_h($r['member_number']) ?></a></td>
        <td><?= ops_h($r['email'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <?php elseif ($view === 'members' && !$filterMember): ?>
  <!-- ═══ BY MEMBER ═══ -->
  <div class="table-wrap"><table>
    <thead><tr><th>Member<?= ops_admin_help_button('Member', 'The member associated with the logged downloads.') ?></th><th>Member Number</th><th>Email</th><th>Downloads<?= ops_admin_help_button('Downloads', 'Number of document-download events recorded for that member.') ?></th><th></th></tr></thead>
    <tbody>
    <?php if (!$byMember): ?><tr><td colspan="5">No downloads recorded yet.</td></tr><?php endif; ?>
    <?php foreach ($byMember as $mn => $m): ?>
      <tr>
        <td><strong><?= ops_h($m['name']) ?></strong></td>
        <td style="font-family:monospace;font-size:12px"><?= ops_h($mn) ?></td>
        <td><?= ops_h($m['email']) ?></td>
        <td><?= count($m['downloads']) ?></td>
        <td><a href="?view=members&mn=<?= urlencode($mn) ?>" style="font-size:12px;color:#c8973e">View details →</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <?php elseif ($view === 'members' && $filterMember): ?>
  <!-- ═══ SINGLE MEMBER DETAIL ═══ -->
  <p><a href="?view=members" title="View downloads grouped by member" style="color:#c8973e;font-size:13px">← Back to all members</a></p>
  <?php $m = $byMember[$filterMember] ?? null; ?>
  <?php if ($m): ?>
  <h3><?= ops_h($m['name']) ?></h3>
  <p class="muted" style="margin-bottom:12px"><?= ops_h($filterMember) ?> · <?= ops_h($m['email']) ?></p>
  <div class="table-wrap"><table>
    <thead><tr><th>When</th><th>Document<?= ops_admin_help_button('Document', 'The document downloaded by this member on that date.') ?></th></tr></thead>
    <tbody>
    <?php foreach ($m['downloads'] as $r): ?>
      <tr>
        <td><?= ops_h($r['created_at']) ?></td>
        <td><a href="?view=documents&doc=<?= urlencode($r['filename']) ?>" style="color:#c8973e"><?= ops_h($r['filename']) ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?>
  <p>No downloads found for this member.</p>
  <?php endif; ?>

  <?php else: ?>
  <!-- ═══ FULL LOG ═══ -->
  <div class="table-wrap"><table>
    <thead><tr><th>ID<?= ops_admin_help_button('ID', 'Raw wallet_event row identifier for the logged download.') ?></th><th>When</th><th>Member</th><th>Member Number</th><th>Document</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="5">No downloads recorded yet.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td><?= ops_h($r['created_at']) ?></td>
        <td><?= ops_h($r['full_name'] ?? '—') ?></td>
        <td style="font-family:monospace;font-size:12px"><?= ops_h($r['member_number']) ?></td>
        <td><?= ops_h($r['filename']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>

</div>
<?php
$body = ob_get_clean();
ops_render_page('Document Downloads', 'doc_downloads', $body);
