<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/ops_workflow.php';
ops_require_admin();
$pdo = ops_db();
$adminUserId = ops_current_admin_user_id($pdo);
$canManage = ops_admin_can($pdo, 'operations.manage') || ops_admin_can($pdo, 'admin.full');

// ── Area definitions ────────────────────────────────────────────────────────
$areas = [
    'operations_oversight'   => ['label' => 'Operations Oversight',     'ico' => '⚙',  'desc' => 'General JV operations monitoring, direction, and oversight.'],
    'governance_polls'       => ['label' => 'Research & Acquisitions',  'ico' => '🔭', 'desc' => 'Members research and identify new acquisition targets — ASX companies, real world assets, resources, and commodities.'],
    'esg_proxy_voting'       => ['label' => 'ESG & Proxy Voting',       'ico' => '🌱',  'desc' => 'Portfolio company engagement, ESG strategy, and AGM proxy voting.'],
    'first_nations'          => ['label' => 'First Nations Joint Venture', 'ico' => '🤝',  'desc' => 'FNAC, FPIC, ICIP, and Cultural Heritage matters.'],
    'community_projects'     => ['label' => 'Community Projects',        'ico' => '🏘',  'desc' => 'Sub-Trust C grants and community benefit activity.'],
    'technology_blockchain'  => ['label' => 'Technology & Blockchain',   'ico' => '🔗',  'desc' => 'Infrastructure, System development, and blockchain operations.'],
    'financial_oversight'    => ['label' => 'Financial Oversight',       'ico' => '📊',  'desc' => 'Distribution verification, accounting, and reporting.'],
    'place_based_decisions'  => ['label' => 'Place-Based Decisions',     'ico' => '📍',  'desc' => 'Local Decision Votes and Affected Zone matters.'],
    'education_outreach'     => ['label' => 'Education & Outreach',      'ico' => '📚',  'desc' => 'Member education, public communications, and onboarding.'],
];

// ── Helpers ─────────────────────────────────────────────────────────────────
function op_rows(PDO $pdo, string $sql, array $p = []): array {
    try { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) { return []; }
}
function op_val(PDO $pdo, string $sql, array $p = []): int {
    try { $st = $pdo->prepare($sql); $st->execute($p); return (int)$st->fetchColumn(); } catch (Throwable $e) { return 0; }
}
function op_one(PDO $pdo, string $sql, array $p = []): ?array {
    try { $st = $pdo->prepare($sql); $st->execute($p); $r = $st->fetch(PDO::FETCH_ASSOC); return $r ?: null; } catch (Throwable $e) { return null; }
}
function op_enrolled(PDO $pdo, string $areaKey): array {
    // Members who selected this area in participation_answers JSON
    return op_rows($pdo, "SELECT m.id, m.full_name, m.member_number, m.email, m.wallet_status, m.participation_completed_at
        FROM members m
        WHERE m.participation_completed = 1
          AND JSON_CONTAINS(m.participation_answers, JSON_QUOTE(?), '$')
          AND m.is_active = 1
        ORDER BY m.full_name ASC", [$areaKey]);
}

// ── POST handler ─────────────────────────────────────────────────────────────
$flash = null; $flashType = 'ok';
$area = (string)($_GET['area'] ?? '');
$areaValid = isset($areas[$area]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
    if (!$canManage) { $flash = 'Permission denied.'; $flashType = 'error'; }
    else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'new_broadcast') {
                $postArea = (string)($_POST['area_key'] ?? '');
                if (!isset($areas[$postArea])) throw new RuntimeException('Invalid area.');
                $subject = trim((string)($_POST['subject'] ?? ''));
                $body    = trim((string)($_POST['body'] ?? ''));
                if ($subject === '') throw new RuntimeException('Subject is required.');
                if ($body === '')    throw new RuntimeException('Body is required.');
                // Create thread
                $pdo->prepare("INSERT INTO partner_op_threads (area_key, direction, subject, body, status, created_by_admin_user_id, created_at, updated_at)
                    VALUES (?, 'broadcast', ?, ?, 'open', ?, NOW(), NOW())")
                    ->execute([$postArea, $subject, $body, $adminUserId]);
                $threadId = (int)$pdo->lastInsertId();
                // Seed broadcast_reads for all enrolled Members
                $enrolled = op_enrolled($pdo, $postArea);
                $ins = $pdo->prepare("INSERT IGNORE INTO partner_op_broadcast_reads (thread_id, member_id, delivered_at) VALUES (?, ?, NOW())");
                foreach ($enrolled as $m) { $ins->execute([$threadId, (int)$m['id']]); }
                $area = $postArea;
                $flash = 'Broadcast sent to ' . count($enrolled) . ' Member' . (count(\$enrolled) !== 1 ? 's' : '') . '.';
            } elseif ($action === 'reply_thread') {
                $threadId = (int)($_POST['thread_id'] ?? 0);
                $replyBody = trim((string)($_POST['reply_body'] ?? ''));
                if ($threadId <= 0) throw new RuntimeException('Invalid thread.');
                if ($replyBody === '') throw new RuntimeException('Reply cannot be empty.');
                $thread = op_one($pdo, "SELECT * FROM partner_op_threads WHERE id = ? LIMIT 1", [$threadId]);
                if (!$thread) throw new RuntimeException('Thread not found.');
                $pdo->prepare("INSERT INTO partner_op_replies (thread_id, body, direction, from_admin_user_id, created_at)
                    VALUES (?, ?, 'outbound', ?, NOW())")->execute([$threadId, $replyBody, $adminUserId]);
                $pdo->prepare("UPDATE partner_op_threads SET reply_count = reply_count + 1, last_reply_at = NOW(), status = 'replied', updated_at = NOW() WHERE id = ?")
                    ->execute([$threadId]);
                $area = (string)$thread['area_key'];
                $flash = 'Reply sent.';
            } elseif ($action === 'close_thread') {
                $threadId = (int)($_POST['thread_id'] ?? 0);
                $thread = op_one($pdo, "SELECT area_key FROM partner_op_threads WHERE id = ? LIMIT 1", [$threadId]);
                if (!$thread) throw new RuntimeException('Thread not found.');
                $pdo->prepare("UPDATE partner_op_threads SET status = 'closed', updated_at = NOW() WHERE id = ?")->execute([$threadId]);
                $area = (string)$thread['area_key'];
                $flash = 'Thread closed.';
            } elseif ($action === 'reopen_thread') {
                $threadId = (int)($_POST['thread_id'] ?? 0);
                $thread = op_one($pdo, "SELECT area_key FROM partner_op_threads WHERE id = ? LIMIT 1", [$threadId]);
                if (!$thread) throw new RuntimeException('Thread not found.');
                $pdo->prepare("UPDATE partner_op_threads SET status = 'open', updated_at = NOW() WHERE id = ?")->execute([$threadId]);
                $area = (string)$thread['area_key'];
                $flash = 'Thread reopened.';
            }
        } catch (Throwable $e) { $flash = $e->getMessage(); $flashType = 'error'; }
    }
}

// ── Data ─────────────────────────────────────────────────────────────────────
$csrf = admin_csrf_token();

// Per-area stats for selector
$areaSummary = [];
foreach ($areas as $key => $def) {
    $enrolled = op_val($pdo, "SELECT COUNT(*) FROM members WHERE participation_completed = 1 AND JSON_CONTAINS(participation_answers, JSON_QUOTE(?), '$') AND is_active = 1", [$key]);
    $openThreads = op_val($pdo, "SELECT COUNT(*) FROM partner_op_threads WHERE area_key = ? AND status IN ('open','replied')", [$key]);
    $inbound = op_val($pdo, "SELECT COUNT(*) FROM partner_op_threads WHERE area_key = ? AND direction = 'inbound' AND status = 'open'", [$key]);
    $areaSummary[$key] = ['enrolled' => $enrolled, 'open_threads' => $openThreads, 'inbound' => $inbound];
}

// Area-specific data
$areaEnrolled = [];
$areaThreads = [];
$viewThread = null;
$viewReplies = [];

if ($areaValid) {
    $areaEnrolled = op_enrolled($pdo, $area);
    $areaThreads  = op_rows($pdo, "SELECT t.*, 
        COALESCE(m.full_name, 'Admin') AS initiator_name,
        m.member_number AS initiator_number
        FROM partner_op_threads t
        LEFT JOIN members m ON m.id = t.initiated_by_member_id
        WHERE t.area_key = ?
        ORDER BY t.updated_at DESC LIMIT 100", [$area]);
    // Single thread view
    $viewId = (int)($_GET['thread'] ?? 0);
    if ($viewId > 0) {
        $viewThread = op_one($pdo, "SELECT t.*, COALESCE(m.full_name,'Admin') AS initiator_name FROM partner_op_threads t LEFT JOIN members m ON m.id = t.initiated_by_member_id WHERE t.id = ? LIMIT 1", [$viewId]);
        if ($viewThread) {
            $viewReplies = op_rows($pdo, "SELECT r.*, COALESCE(m.full_name,'Admin') AS author_name FROM partner_op_replies r LEFT JOIN members m ON m.id = r.from_member_id WHERE r.thread_id = ? ORDER BY r.created_at ASC", [$viewId]);
            // Mark as read if inbound and was open
            if ((string)$viewThread['direction'] === 'inbound' && (string)$viewThread['status'] === 'open') {
                $pdo->prepare("UPDATE partner_op_threads SET status = 'read', updated_at = NOW() WHERE id = ?")->execute([$viewId]);
                $viewThread['status'] = 'read';
            }
        }
    }
}

// ── Render ───────────────────────────────────────────────────────────────────
ob_start();
?>
<?php ops_admin_help_assets_once(); ?>
<style>
.area-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
.area-card{background:linear-gradient(180deg,var(--panel),var(--panel2));border:1px solid var(--line);border-radius:var(--r);padding:16px 18px;text-decoration:none;color:inherit;display:block;transition:border-color .15s}
.area-card:hover{border-color:rgba(212,178,92,.4)}
.area-card.has-inbound{border-color:rgba(212,178,92,.35);background:linear-gradient(180deg,rgba(212,178,92,.06),rgba(212,178,92,.02))}
.area-ico{font-size:1.6rem;margin-bottom:8px;display:block}
.area-name{font-size:.95rem;font-weight:700;margin-bottom:4px}
.area-desc{font-size:.8rem;color:var(--muted);line-height:1.5;margin-bottom:10px}
.area-stats{display:flex;gap:10px;flex-wrap:wrap}
.area-stat{font-size:.78rem;padding:3px 8px;border-radius:6px;background:rgba(255,255,255,.05);border:1px solid var(--line)}
.area-stat.alert{background:rgba(212,178,92,.12);color:var(--gold);border-color:rgba(212,178,92,.25)}
.thread-row{display:flex;align-items:flex-start;gap:12px;padding:12px 0;border-bottom:1px solid var(--line)}
.thread-row:last-child{border-bottom:none}
.thread-dir{width:8px;height:8px;border-radius:50%;flex-shrink:0;margin-top:6px}
.dir-in{background:#7ee0a0}
.dir-out{background:#7ab8e8}
.dir-broadcast{background:var(--gold)}
.thread-body{flex:1;min-width:0}
.thread-subject{font-weight:600;font-size:.9rem;margin-bottom:2px}
.thread-meta{font-size:.78rem;color:var(--muted)}
.thread-actions{display:flex;gap:6px;flex-shrink:0}
.reply-bubble{border-radius:12px;padding:12px 14px;margin-bottom:10px;max-width:90%}
.reply-out{background:rgba(212,178,92,.08);border:1px solid rgba(212,178,92,.15);margin-left:auto}
.reply-in{background:rgba(255,255,255,.04);border:1px solid var(--line)}
.enrolled-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
.enrolled-chip{padding:8px 10px;border-radius:10px;background:rgba(255,255,255,.03);border:1px solid var(--line);font-size:.82rem}
@media(max-width:900px){.area-grid{grid-template-columns:1fr 1fr}.enrolled-grid{grid-template-columns:1fr 1fr}}
@media(max-width:600px){.area-grid{grid-template-columns:1fr}.enrolled-grid{grid-template-columns:1fr}}
</style>

<?php if ($areaValid): ?>
  <?php /* ── AREA VIEW ── */ ?>
  <?php $aDef = $areas[$area]; ?>

  <div class="card">
    <div class="card-head">
      <div>
        <div class="muted small" style="margin-bottom:4px"><a href="./operations.php" class="muted">← Member Operations</a></div>
        <h1 style="margin:0"><?= ops_h($aDef['ico']) ?> <?= ops_h($aDef['label']) ?></h1>
      </div>
      <div class="stat-label"><?= count($areaEnrolled) ?> Member<?= count(\$areaEnrolled) !== 1 ? 's' : '' ?> enrolled</div>
    </div>
    <div class="card-body" style="padding-top:6px">
      <p class="muted small" style="margin:0"><?= ops_h($aDef['desc']) ?></p>
    </div>
  </div>

  <?php if ($viewThread): ?>
    <?php /* ── SINGLE THREAD VIEW ── */ ?>
    <div class="card">
      <div class="card-head">
        <div>
          <div class="muted small"><a href="./operations.php?area=<?= urlencode($area) ?>" class="muted">← Back to <?= ops_h($aDef['label']) ?></a></div>
          <div class="card-head"><h2><?= ops_h($viewThread['subject']) ?></h2></div>
  <div class="card-body">

        </div>
        <span class="st st-<?= $viewThread['status'] === 'closed' ? 'dim' : ($viewThread['status'] === 'open' ? 'warn' : 'ok') ?>"><?= ops_h($viewThread['status']) ?></span>
      </div>
      <div class="card-body">
        <div class="thread-meta" style="margin-bottom:14px">
          <?= ops_h(ucfirst($viewThread['direction'])) ?> ·
          From <?= ops_h($viewThread['initiator_name']) ?> ·
          <?= ops_h((string)$viewThread['created_at']) ?>
        </div>
        <div class="reply-bubble reply-<?= $viewThread['direction'] === 'inbound' ? 'in' : 'out' ?>">
          <?= nl2br(ops_h($viewThread['body'])) ?>
        </div>
        <?php foreach ($viewReplies as $rep): ?>
          <div class="reply-bubble reply-<?= $rep['direction'] === 'inbound' ? 'in' : 'out' ?>" style="margin-top:8px">
            <div class="muted small" style="margin-bottom:6px"><?= ops_h($rep['author_name']) ?> · <?= ops_h((string)$rep['created_at']) ?></div>
            <?= nl2br(ops_h($rep['body'])) ?>
          </div>
        <?php endforeach; ?>

        <?php if ($canManage && $viewThread['status'] !== 'closed'): ?>
        <form method="post" style="margin-top:16px">
          <input type="hidden" name="_csrf" value="<?= ops_h($csrf) ?>">
          <input type="hidden" name="action" value="reply_thread">
          <input type="hidden" name="thread_id" value="<?= (int)$viewThread['id'] ?>">
          <div class="field"><label>Reply</label><textarea name="reply_body" rows="4" placeholder="Write your reply…"></textarea></div>
          <div class="actions">
            <button class="btn btn-gold" type="submit">Send reply</button>
            <button class="btn" formaction="./operations.php" type="button" onclick="
              document.querySelector('[name=action]').value='close_thread';
              this.closest('form').submit()
            ">Close thread</button>
          </div>
        </form>
        <form method="post" style="margin-top:8px">
          <input type="hidden" name="_csrf" value="<?= ops_h($csrf) ?>">
          <input type="hidden" name="action" value="close_thread">
          <input type="hidden" name="thread_id" value="<?= (int)$viewThread['id'] ?>">
          <button class="btn-secondary small" type="submit">Close without reply</button>
        </form>
        <?php elseif ($canManage && $viewThread['status'] === 'closed'): ?>
        <form method="post" style="margin-top:16px">
          <input type="hidden" name="_csrf" value="<?= ops_h($csrf) ?>">
          <input type="hidden" name="action" value="reopen_thread">
          <input type="hidden" name="thread_id" value="<?= (int)$viewThread['id'] ?>">
          <button class="btn-secondary" type="submit">Reopen thread</button>
        </form>
        <?php endif; ?>
      </div>
    </div>

  <?php else: ?>
    <?php /* ── AREA LISTING VIEW ── */ ?>

    <?= ops_admin_collapsible_help('Area guide', [
      ops_admin_info_panel(ops_h($aDef['label']), 'What this area does', ops_h($aDef['desc']), [
        'Inbound threads are messages sent by Members enrolled in this area from their Hub.',
        'Broadcasts are admin-initiated messages sent to all enrolled Members.',
        'Enrolled Members are those who selected this area during their participation setup.',
      ]),
    ]) ?>

    <?php /* Enrolled Members */ ?>
    <div class="card">
      <div class="card-head">
        <h2>Enrolled Members</h2>
        <span class="muted small"><?= count($areaEnrolled) ?> Member<?= count(\$areaEnrolled) !== 1 ? 's' : '' ?></span>
      </div>
      <div class="card-body">
      <?php if (!$areaEnrolled): ?>
        <p class="empty">No Members have selected this area yet.</p>
      <?php else: ?>
        <div class="enrolled-grid">
          <?php foreach ($areaEnrolled as $m): ?>
          <div class="enrolled-chip">
            <div style="font-weight:600"><?= ops_h($m['full_name'] ?? '—') ?></div>
            <div class="muted small"><?= ops_h($m['member_number'] ?? '') ?></div>
            <div class="muted small"><span class="st st-<?= $m['wallet_status'] === 'active' ? 'ok' : 'dim' ?>"><?= ops_h($m['wallet_status']) ?></span></div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      </div>
    </div>

    <?php /* Threads */ ?>
    <div class="card">
      <div class="card-head">
        <h2>Threads</h2>
        <div class="actions">
          <?php if ($canManage): ?>
          <button class="btn btn-gold btn-sm" onclick="document.getElementById('broadcast-form').style.display=document.getElementById('broadcast-form').style.display==='none'?'block':'none'">+ New broadcast</button>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($canManage): ?>
      <div id="broadcast-form" style="display:none">
        <div class="card-body" style="border-top:1px solid var(--line)">
          <form method="post">
            <input type="hidden" name="_csrf" value="<?= ops_h($csrf) ?>">
            <input type="hidden" name="action" value="new_broadcast">
            <input type="hidden" name="area_key" value="<?= ops_h($area) ?>">
            <div class="field"><label>Subject</label><input name="subject" placeholder="Broadcast subject…"></div>
            <div class="field"><label>Body</label><textarea name="body" rows="5" placeholder="Message to all <?= count($areaEnrolled) ?> enrolled Members in this area…"></textarea></div>
            <div class="actions">
              <button class="btn btn-gold" type="submit">Send broadcast to <?= count($areaEnrolled) ?> Member<?= count($areaEnrolled) !== 1 ? 's' : '' ?></button>
            </div>
          </form>
        </div>
      </div>
      <?php endif; ?>

      <div class="card-body">
      <?php if (!$areaThreads): ?>
        <p class="empty">No threads yet in this area.</p>
      <?php else: ?>
        <?php foreach ($areaThreads as $t): ?>
        <div class="thread-row">
          <div class="thread-dir dir-<?= $t['direction'] === 'inbound' ? 'in' : ($t['direction'] === 'broadcast' ? 'broadcast' : 'out') ?>" title="<?= ops_h($t['direction']) ?>"></div>
          <div class="thread-body">
            <div class="thread-subject"><?= ops_h($t['subject']) ?></div>
            <div class="thread-meta">
              <?= ops_h(ucfirst($t['direction'])) ?> ·
              <?= ops_h($t['initiator_name']) ?> ·
              <?= ops_h((string)$t['created_at']) ?>
              <?php if ($t['reply_count'] > 0): ?> · <?= (int)$t['reply_count'] ?> repl<?= (int)$t['reply_count'] === 1 ? 'y' : 'ies' ?><?php endif; ?>
            </div>
          </div>
          <div class="thread-actions">
            <span class="st st-<?= $t['status'] === 'closed' ? 'dim' : ($t['status'] === 'open' ? 'warn' : 'ok') ?>"><?= ops_h($t['status']) ?></span>
            <a class="btn-secondary small" href="./operations.php?area=<?= urlencode($area) ?>&thread=<?= (int)$t['id'] ?>">View</a>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
      </div>
    </div>

  <?php endif; /* end single thread vs listing */ ?>

<?php else: ?>
  <?php /* ── SELECTOR VIEW ── */ ?>

  <div class="card">
    <div class="card-head">
      <h1 style="margin:0">🤝 Member Operations</h1>
    </div>
    <div class="card-body" style="padding-top:6px">
      <p class="muted small" style="margin:0">Day-to-day management coordination across the 9 Member participation areas. Select an area to view enrolled Members, manage threads, and send broadcasts.</p>
    </div>
  </div>

  <?= ops_admin_collapsible_help('Page guide', [
    ops_admin_info_panel('Member Operations Hub', 'What this page does', 'This section coordinates the Foundation\'s day-to-day management across the 9 participation areas that Members select in their Hub. Each area has its own thread inbox and broadcast channel.', [
      'Inbound threads (green) are messages Members have sent from their Hub into that area.',
      'Broadcasts (gold) are admin-initiated messages sent to all Members enrolled in an area.',
      'A gold border on an area card means there are unread inbound threads waiting.',
    ]),
    ops_admin_status_panel('Thread statuses', 'Use these to track the lifecycle of each thread.', [
      ['label' => 'Open', 'body' => 'New or unactioned thread — needs admin attention.'],
      ['label' => 'Read', 'body' => 'Admin has opened the thread but not yet replied.'],
      ['label' => 'Replied', 'body' => 'Admin has sent a reply.'],
      ['label' => 'Closed', 'body' => 'Thread is resolved. Can be reopened if needed.'],
    ]),
  ]) ?>

  <div class="area-grid">
    <?php foreach ($areas as $key => $def): ?>
    <?php $s = $areaSummary[$key]; ?>
    <a class="area-card<?= $s['inbound'] > 0 ? ' has-inbound' : '' ?>" href="./operations.php?area=<?= urlencode($key) ?>">
      <span class="area-ico"><?= $def['ico'] ?></span>
      <div class="area-name"><?= ops_h($def['label']) ?></div>
      <div class="area-desc"><?= ops_h($def['desc']) ?></div>
      <div class="area-stats">
        <span class="area-stat"><?= $s['enrolled'] ?> Member<?= \$s['enrolled'] !== 1 ? 's' : '' ?></span>
        <?php if ($s['inbound'] > 0): ?>
          <span class="area-stat alert"><?= $s['inbound'] ?> inbound</span>
        <?php endif; ?>
        <?php if ($s['open_threads'] > 0): ?>
          <span class="area-stat"><?= $s['open_threads'] ?> open</span>
        <?php endif; ?>
      </div>
    </a>
    <?php endforeach; ?>
  </div>

<?php endif; /* end selector vs area */ ?>
<?php
$body = ob_get_clean();
ops_render_page('Member Operations', 'operations', $body, $flash, $flashType);
