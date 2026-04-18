<?php
require_once __DIR__ . '/includes/ops_workflow.php';
ops_require_admin();
$pdo = ops_db();
$flash=''; $flashType='ok';
$memberFilter = (int)($_GET['member_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
  try {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create_review') {
      $subjectType = trim((string)($_POST['subject_type'] ?? 'member'));
      $subjectId = (int)($_POST['subject_id'] ?? 0);
      $memberId = (int)($_POST['member_id'] ?? 0);
      $tokenClassId = (int)($_POST['token_class_id'] ?? 0);
      $reviewType = trim((string)($_POST['review_type'] ?? 'general'));
      $notes = trim((string)($_POST['notes'] ?? ''));
      $docRef = trim((string)($_POST['document_reference'] ?? ''));
      if ($subjectId <= 0 || $memberId <= 0) throw new RuntimeException('Subject and member are required.');
      ops_create_evidence_review($pdo, $subjectType, $subjectId, $memberId, $tokenClassId ?: null, $reviewType, 'pending', $notes, $docRef);
      $flash='Evidence review created.';
    }
    if ($action === 'update_review') {
      $reviewId = (int)($_POST['review_id'] ?? 0);
      $status = trim((string)($_POST['review_status'] ?? 'pending'));
      $notes = trim((string)($_POST['notes'] ?? ''));
      $docRef = trim((string)($_POST['document_reference'] ?? ''));
      if (!in_array($status, ops_evidence_review_statuses(), true)) throw new RuntimeException('Invalid review status.');
      $review = ops_fetch_one($pdo, 'SELECT * FROM evidence_reviews WHERE id=?', [$reviewId]);
      if (!$review) throw new RuntimeException('Evidence review not found.');
      $pdo->prepare('UPDATE evidence_reviews SET review_status=?, reviewer_admin_id=?, notes=?, document_reference=?, reviewed_at=?, updated_at=? WHERE id=?')
        ->execute([$status, ops_admin_id(), $notes ?: null, $docRef ?: null, ops_now(), ops_now(), $reviewId]);
      $review['review_type'] = $review['review_type'] ?? 'general';
      ops_apply_review_outcome($pdo, $review, $status);
      $flash='Evidence review updated.';
    }
  } catch (Throwable $e) {
    $flash = $e->getMessage();
    $flashType = 'err';
  }
}

$members = ops_fetch_all($pdo, 'SELECT id, member_number, full_name, member_type FROM members ORDER BY id DESC LIMIT 200');
$tokenClasses = ops_fetch_all($pdo, 'SELECT id, class_code, display_name FROM token_classes WHERE is_active=1 ORDER BY display_order, id');

// ── Pagination ─────────────────────────────────────────────────────────────────
$perPage = 20;
$page    = max(1, (int)($_GET['page'] ?? 1));

$countSql = "SELECT COUNT(*) AS c FROM evidence_reviews er JOIN members m ON m.id=er.member_id";
$countParams = [];
if ($memberFilter > 0) { $countSql .= " WHERE er.member_id = ?"; $countParams[] = $memberFilter; }
$totalReviews = ops_table_exists($pdo,'evidence_reviews')
    ? (int)(ops_fetch_one($pdo, $countSql, $countParams)['c'] ?? 0)
    : 0;
$totalPages = max(1, (int)ceil($totalReviews / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$sql = "SELECT er.*, m.full_name, m.member_number, tc.class_code, tc.display_name
        FROM evidence_reviews er
        JOIN members m ON m.id=er.member_id
        LEFT JOIN token_classes tc ON tc.id=er.token_class_id";
$params = [];
if ($memberFilter > 0) {
  $sql .= " WHERE er.member_id = ?";
  $params[] = $memberFilter;
}
$sql .= " ORDER BY er.id DESC LIMIT " . $perPage . " OFFSET " . $offset;
$rows = ops_table_exists($pdo,'evidence_reviews') ? ops_fetch_all($pdo, $sql, $params) : [];

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
$pagerBase = 'evidence_reviews.php?' . ($memberFilter > 0 ? 'member_id=' . $memberFilter . '&' : '');
ob_start(); ?>
<?php ops_admin_help_assets_once(); ?>
<div class="stack">
  <?= ops_admin_info_panel('Evidence · Review', 'What this page does', 'Use Evidence Reviews to create and update explicit evidence review records for intake or operational subjects. This page is for documenting review work and its outcome.', [
    'Create a review when a subject needs documented evidence assessment.',
    'Update the review status as the evidence is checked or resolved.',
    'Use document references and notes so later operators can follow the trail.'
  ]) ?>

  <?= ops_admin_workflow_panel('Typical workflow', 'Evidence Reviews is a controlled review register, not a payment or execution page.', [
    ['title' => 'Create the review', 'body' => 'Choose the subject, member, review type, and optional document reference.'],
    ['title' => 'Assess the evidence', 'body' => 'Examine the supporting material outside or alongside this page.'],
    ['title' => 'Update the status', 'body' => 'Move the review to approved, rejected, or another relevant status with notes.'],
    ['title' => 'Let downstream pages react', 'body' => 'Review outcomes can feed later intake, approval, or exception handling.']
  ]) ?>

  <?= ops_admin_status_panel('Status guide', 'These statuses describe the evidence review itself.', [
    ['label' => 'Pending', 'body' => 'Review was created but has not yet been concluded.'],
    ['label' => 'Approved', 'body' => 'Evidence review completed successfully.'],
    ['label' => 'Rejected', 'body' => 'Evidence did not satisfy the review requirement.'],
    ['label' => 'Document reference', 'body' => 'Use this to link the review to an internal source or evidence item.']
  ]) ?>
  <div class="section">
    <h2 style="margin-top:0">Create evidence review <?= ops_admin_help_button('Create evidence review', 'Create a review record when a subject needs explicit evidence assessment. The review record is the auditable wrapper around the evidence decision, not the evidence file itself.') ?></h2>
    <form method="post" class="stack">
      <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
      <input type="hidden" name="action" value="create_review">
      <div class="form-grid">
        <div><label>Subject type</label><select name="subject_type"><option value="member">member</option><option value="approval_request">approval_request</option><option value="mint_queue">mint_queue</option></select></div>
        <div><label>Subject ID</label><input name="subject_id" type="number" min="1"></div>
        <div><label>Member</label><select name="member_id"><?php foreach($members as $m): ?><option value="<?= (int)$m['id'] ?>"><?= ops_h($m['full_name'].' · '.$m['member_number'].' · '.$m['member_type']) ?></option><?php endforeach; ?></select></div>
        <div><label>Token class</label><select name="token_class_id"><option value="0">— none —</option><?php foreach($tokenClasses as $t): ?><option value="<?= (int)$t['id'] ?>"><?= ops_h($t['display_name'].' · '.$t['class_code']) ?></option><?php endforeach; ?></select></div>
        <div><label>Review type</label><select name="review_type"><?php foreach(ops_evidence_review_types() as $t): ?><option value="<?= $t ?>"><?= $t ?></option><?php endforeach; ?></select></div>
        <div><label>Document reference</label><input name="document_reference"></div>
      </div>
      <div><label>Notes</label><textarea name="notes"></textarea></div>
      <div class="actions"><button class="btn" type="submit">Create review</button></div>
    </form>
  </div>

  <div class="section">
    <h2 style="margin-top:0">Evidence reviews <?= ops_admin_help_button('Evidence reviews list', 'This table shows the evidence review register. Update the status and notes as the review progresses so later operators can see what happened and why.') ?></h2>
    <div class="table-wrap"><table><thead><tr><th>ID</th><th>Subject</th><th>Type</th><th>Status</th><th>Doc ref</th><th>Updated</th><th>Action</th></tr></thead><tbody>
    <?php if(!$rows): ?><tr><td colspan="7">No evidence reviews found.</td></tr><?php endif; ?>
    <?php foreach($rows as $r): ?><tr>
      <td><?= (int)$r['id'] ?></td>
      <td><?= ops_h(ops_review_subject_label($r)) ?><div class="muted"><?= ops_h((string)$r['subject_type']) ?> #<?= (int)$r['subject_id'] ?></div></td>
      <td><?= ops_h($r['review_type']) ?></td>
      <td><?= ops_h($r['review_status']) ?></td>
      <td><?= ops_h((string)$r['document_reference']) ?></td>
      <td><?= ops_h((string)$r['updated_at']) ?></td>
      <td style="min-width:260px">
        <form method="post" class="stack">
      <input type="hidden" name="_csrf" value="<?= ops_h(admin_csrf_token()) ?>">
          <input type="hidden" name="action" value="update_review">
          <input type="hidden" name="review_id" value="<?= (int)$r['id'] ?>">
          <select name="review_status"><?php foreach(ops_evidence_review_statuses() as $s): ?><option value="<?= $s ?>"<?= $r['review_status']===$s?' selected':'' ?>><?= $s ?></option><?php endforeach; ?></select>
          <input name="document_reference" value="<?= ops_h((string)$r['document_reference']) ?>" placeholder="Document ref">
          <textarea name="notes"><?= ops_h((string)$r['notes']) ?></textarea>
          <button class="btn-secondary" type="submit">Update review</button>
        </form>
      </td>
    </tr><?php endforeach; ?>
    </tbody></table></div>
    <?= render_pager($pagerBase, $page, $totalPages, $totalReviews, 'review') ?>
  </div>
</div>
<?php $body=ob_get_clean(); ops_render_page('Evidence Reviews','evidence_reviews',$body,$flash,$flashType);