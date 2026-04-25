<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';
ops_require_admin();
$pdo = ops_db();

function hq_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function hq_rows(PDO $p, string $sql, array $params=[]): array {
    try { $s=$p->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC)?:[]; }
    catch (Throwable) { return []; }
}
function hq_val(PDO $p, string $sql, array $params=[]): int {
    try { $s=$p->prepare($sql); $s->execute($params); return (int)$s->fetchColumn(); }
    catch (Throwable) { return 0; }
}

$AREA_LABELS = [
    'operations_oversight'  => 'Day-to-Day Operations',
    'research_acquisitions'      => 'Research & Acquisitions',
    'esg_proxy_voting'      => 'ESG & Proxy Voting',
    'first_nations'         => 'First Nations Joint Venture',
    'community_projects'    => 'Community Projects',
    'technology_blockchain' => 'Technology & Blockchain',
    'financial_oversight'   => 'Financial Oversight',
    'place_based_decisions' => 'Place-Based Decisions',
    'education_outreach'    => 'Education & Outreach',
];

// ── POST actions ─────────────────────────────────────────────────────────────
$flash = null; $flashType = 'ok';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
    $act     = (string)($_POST['action'] ?? '');
    $qId     = (int)($_POST['query_id'] ?? 0);
    $adminId = ops_current_admin_user_id($pdo);

    if ($act === 'update_status' && $qId) {
        $newStatus = (string)($_POST['status'] ?? 'open');
        $newStatus = in_array($newStatus, ['open','in_review','resolved','closed'], true) ? $newStatus : 'open';
        $notes     = trim((string)($_POST['admin_notes'] ?? ''));
        $pdo->prepare(
            "UPDATE member_hub_queries SET status=?, admin_notes=?, assigned_to_admin_id=?, updated_at=NOW() WHERE id=?"
        )->execute([$newStatus, $notes ?: null, $adminId, $qId]);
        $flash = 'Query updated.';
    }

    if ($act === 'send_reply' && $qId) {
        // Fetch query to get member_id and area_key
        $q = hq_rows($pdo, "SELECT * FROM member_hub_queries WHERE id=? LIMIT 1", [$qId]);
        $q = $q[0] ?? null;
        if ($q) {
            $replyBody = trim((string)($_POST['reply_body'] ?? ''));
            $isBroadcast = ($_POST['reply_type'] ?? 'private') === 'broadcast';
            if ($replyBody) {
                $direction = $isBroadcast ? 'broadcast' : 'outbound';
                $subject   = 'Re: ' . substr((string)($q['subject']), 0, 240);
                try {
                    $pdo->prepare(
                        "INSERT INTO partner_op_threads
                           (area_key, direction, subject, body, status,
                            created_by_admin_user_id, initiated_by_member_id, created_at, updated_at)
                         VALUES (?, ?, ?, ?, 'open', ?, ?, NOW(), NOW())"
                    )->execute([$q['area_key'], $direction, $subject, $replyBody, $adminId, (int)$q['member_id']]);
                    $threadId = (int)$pdo->lastInsertId();

                    // If broadcast, seed read receipts for all enrolled members
                    if ($isBroadcast) {
                        $areaKey = (string)$q['area_key'];
                        try {
                            $enrolled = hq_rows($pdo,
                                "SELECT id FROM members WHERE participation_completed=1 AND is_active=1
                                   AND JSON_SEARCH(participation_answers,'one',?) IS NOT NULL",
                                [$areaKey]);
                            if ($enrolled) {
                                $ins = $pdo->prepare(
                                    "INSERT IGNORE INTO partner_op_broadcast_reads (thread_id,member_id,delivered_at) VALUES (?,?,NOW())"
                                );
                                foreach ($enrolled as $m) { $ins->execute([$threadId, (int)$m['id']]); }
                            }
                        } catch (Throwable) {}
                    }

                    // Link thread to query
                    $col = $isBroadcast ? 'reply_broadcast_id' : 'reply_thread_id';
                    $pdo->prepare("UPDATE member_hub_queries SET $col=?, status='in_review', updated_at=NOW() WHERE id=?")
                        ->execute([$threadId, $qId]);

                    $flash = $isBroadcast
                        ? 'Broadcast reply sent to all enrolled members in this hub.'
                        : 'Private reply sent to member.';
                } catch (Throwable $e) {
                    $flash = 'Reply failed: ' . $e->getMessage();
                    $flashType = 'err';
                }
            }
        }
    }

    header('Location: ' . admin_url('hub_queries.php' . ($qId ? "?view=$qId" : '') . '#flash'));
    exit;
}

$flash       = isset($_GET['flash']) ? (string)$_GET['flash'] : $flash;
$flashType   = isset($_GET['type'])  ? (string)$_GET['type']  : $flashType;
$viewId      = (int)($_GET['view'] ?? 0);
$filterArea  = trim((string)($_GET['area'] ?? ''));
$filterStatus= trim((string)($_GET['status'] ?? ''));

$hasTable = ops_has_table($pdo, 'member_hub_queries');

// ── Summary counts ────────────────────────────────────────────────────────────
$totalOpen   = 0;
$querySummary = [];
$queries     = [];
$viewQuery   = null;

if ($hasTable) {
    $totalOpen    = hq_val($pdo, "SELECT COUNT(*) FROM member_hub_queries WHERE status IN ('open','in_review')");
    $querySummary = hq_rows($pdo, "SELECT * FROM v_hub_query_summary ORDER BY open_count DESC, last_query_at DESC");

    // Build filter
    $where = ['1=1']; $params = [];
    if ($filterArea)   { $where[] = 'area_key=?';  $params[] = $filterArea; }
    if ($filterStatus) { $where[] = 'status=?';    $params[] = $filterStatus; }
    $whereStr = implode(' AND ', $where);
    $queries = hq_rows($pdo,
        "SELECT q.*, m.first_name, m.last_name, m.member_number
           FROM member_hub_queries q
           LEFT JOIN members m ON m.id = q.member_id
          WHERE $whereStr
          ORDER BY FIELD(q.status,'open','in_review','resolved','closed'), q.created_at DESC
          LIMIT 100",
        $params);

    if ($viewId) {
        $r = hq_rows($pdo,
            "SELECT q.*, m.first_name, m.last_name, m.member_number, m.email
               FROM member_hub_queries q
               LEFT JOIN members m ON m.id=q.member_id
              WHERE q.id=? LIMIT 1", [$viewId]);
        $viewQuery = $r[0] ?? null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Hub Queries — COG$ Admin</title>
<?php require __DIR__ . '/assets/admin.css'; ?>
<style>
.hq-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin-bottom:20px}
.hq-tile{background:var(--card-bg,#111);border:1px solid var(--line,rgba(255,255,255,.1));border-radius:10px;padding:12px 14px;cursor:pointer;transition:border-color .15s}
.hq-tile:hover{border-color:var(--goldb,rgba(212,178,92,.3))}
.hq-tile .area{font-size:.78rem;font-weight:700;color:var(--text,#fff);margin-bottom:6px}
.hq-tile .counts{display:flex;gap:8px;font-size:.75rem}
.open-badge{color:#f59e0b;font-weight:700}.res-badge{color:#10b981}
.tbl{width:100%;border-collapse:collapse;font-size:.8rem}
.tbl th{text-align:left;padding:8px 10px;border-bottom:1px solid var(--line,rgba(255,255,255,.1));color:var(--muted,#666);font-size:.71rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.tbl td{padding:8px 10px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:top}
.tbl tr:hover td{background:rgba(255,255,255,.02)}
.s-open{color:#f59e0b}.s-review{color:#60a5fa}.s-resolved{color:#10b981}.s-closed{color:#64748b}
.t-private{color:#94a3b8}.t-hub{color:#a78bfa}.t-public{color:#34d399}
.chip{display:inline-block;font-size:.69rem;font-weight:700;padding:2px 7px;border-radius:99px}
.chip-open{background:rgba(245,158,11,.15);color:#f59e0b;border:1px solid rgba(245,158,11,.3)}
.chip-review{background:rgba(96,165,250,.12);color:#60a5fa;border:1px solid rgba(96,165,250,.25)}
.chip-resolved{background:rgba(16,185,129,.1);color:#10b981;border:1px solid rgba(16,185,129,.25)}
.chip-closed{background:rgba(100,116,139,.1);color:#64748b;border:1px solid rgba(100,116,139,.2)}
.detail-card{background:var(--card-bg,#111);border:1px solid var(--line,rgba(255,255,255,.1));border-radius:12px;padding:22px 24px;margin-bottom:16px}
.detail-body{font-size:.9rem;line-height:1.7;color:var(--text2,rgba(255,255,255,.85));white-space:pre-wrap;word-break:break-word}
.reply-form{background:rgba(212,178,92,.04);border:1px solid rgba(212,178,92,.2);border-radius:10px;padding:18px 20px;margin-top:16px}
.form-label{font-size:.78rem;font-weight:700;color:var(--muted,#666);text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px;display:block}
.form-tx{width:100%;padding:10px 12px;border-radius:8px;border:1px solid var(--line,rgba(255,255,255,.1));background:rgba(255,255,255,.03);color:var(--text,#fff);font:inherit;font-size:.88rem;resize:vertical;min-height:90px}
.form-tx:focus{outline:none;border-color:rgba(212,178,92,.4)}
.form-sel{padding:7px 10px;border-radius:8px;border:1px solid var(--line,rgba(255,255,255,.1));background:#111;color:var(--text,#fff);font:inherit;font-size:.88rem}
.flash-ok{background:#064e3b;border:1px solid #10b981;color:#d1fae5;padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:.88rem}
.flash-err{background:#7f1d1d;border:1px solid #ef4444;color:#fee2e2;padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:.88rem}
.info-box{background:rgba(212,178,92,.06);border:1px solid rgba(212,178,92,.2);border-radius:8px;padding:14px 16px;margin-bottom:16px;color:#d4b25c;font-size:.88rem}
.filter-row{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;align-items:center}
.back-btn{display:inline-flex;align-items:center;gap:6px;font-size:.82rem;color:var(--muted,#666);cursor:pointer;background:none;border:none;font-family:inherit;padding:0;margin-bottom:14px}
.back-btn:hover{color:var(--gold,#d4b25c)}
</style>
</head>
<body>
<?php $active_page='hub_queries'; ?>
<div class="admin-shell">
<?php echo admin_sidebar_html($active_page); ?>
<main class="admin-main">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px">
  <div>
    <h1 class="page-title">💬 Hub Member Queries</h1>
    <p style="font-size:.82rem;color:var(--muted,#666);margin-top:4px">
      Questions and issues raised by members from the nine management hub pages.
    </p>
  </div>
  <?php if ($totalOpen > 0): ?>
    <span style="background:rgba(245,158,11,.15);border:1px solid rgba(245,158,11,.3);color:#f59e0b;font-size:.82rem;font-weight:700;padding:5px 12px;border-radius:99px">
      <?= $totalOpen ?> open
    </span>
  <?php endif; ?>
</div>

<span id="flash"></span>
<?php if ($flash): ?>
  <div class="flash-<?= hq_h($flashType) ?>"><?= hq_h($flash) ?></div>
<?php endif; ?>

<?php if (!$hasTable): ?>
<div class="info-box">
  ⬡ The <code>member_hub_queries</code> table does not exist yet.
  Run <strong>hub_monitor_queries_v1.sql</strong> via phpMyAdmin to enable member queries.
</div>
<?php elseif ($viewQuery): ?>
<!-- ── Detail view ──────────────────────────────────────────────────────── -->
<?php
$q   = $viewQuery;
$al  = $AREA_LABELS[$q['area_key']] ?? $q['area_key'];
$statusChip = match($q['status']) {
    'open'      => '<span class="chip chip-open">Open</span>',
    'in_review' => '<span class="chip chip-review">In Review</span>',
    'resolved'  => '<span class="chip chip-resolved">Resolved</span>',
    'closed'    => '<span class="chip chip-closed">Closed</span>',
    default     => hq_h((string)$q['status'])
};
$transLabel = match($q['transparency']) {
    'private'        => '🔒 Private',
    'hub_members'    => '👥 Hub members',
    'public_record'  => '📢 Public record',
    default          => hq_h((string)$q['transparency'])
};
?>
<button class="back-btn" onclick="location.href='hub_queries.php'">← Back to all queries</button>

<div class="detail-card">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px">
    <div>
      <div style="font-size:1.1rem;font-weight:700;color:var(--text,#fff);margin-bottom:6px"><?= hq_h((string)$q['subject']) ?></div>
      <div style="font-size:.78rem;color:var(--muted,#666);display:flex;gap:14px;flex-wrap:wrap">
        <span>Area: <strong style="color:var(--text2,rgba(255,255,255,.8))"><?= hq_h($al) ?></strong></span>
        <span>Member: <strong>#<?= hq_h((string)$q['member_id']) ?></strong>
          <?php if (!empty($q['first_name'])): ?>(<?= hq_h((string)$q['first_name']) . ' ' . hq_h((string)($q['last_name']??'')) ?>)<?php endif; ?></span>
        <span>Transparency: <strong><?= $transLabel ?></strong></span>
        <span>Raised: <?= hq_h(substr((string)($q['created_at']??''),0,16)) ?></span>
      </div>
    </div>
    <div><?= $statusChip ?></div>
  </div>
  <div class="detail-body"><?= hq_h((string)$q['body']) ?></div>
</div>

<!-- Update status + notes -->
<div class="detail-card">
  <div style="font-weight:700;color:var(--text,#fff);margin-bottom:14px">Admin Actions</div>
  <form method="POST" style="display:flex;flex-direction:column;gap:12px">
    <?= admin_csrf_field() ?>
    <input type="hidden" name="action" value="update_status">
    <input type="hidden" name="query_id" value="<?= (int)$q['id'] ?>">
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
      <div>
        <label class="form-label">Status</label>
        <select name="status" class="form-sel">
          <?php foreach (['open','in_review','resolved','closed'] as $s): ?>
            <option value="<?= $s ?>" <?= $q['status']===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="admin-btn admin-btn-primary" type="submit">Update Status</button>
    </div>
    <div>
      <label class="form-label">Internal Admin Notes (never shown to member)</label>
      <textarea name="admin_notes" class="form-tx" rows="3"><?= hq_h((string)($q['admin_notes']??'')) ?></textarea>
    </div>
  </form>
</div>

<!-- Reply form -->
<div class="reply-form">
  <div style="font-weight:700;color:var(--gold,#d4b25c);margin-bottom:12px">Send Reply</div>
  <p style="font-size:.82rem;color:var(--muted,#666);margin-bottom:14px">
    <strong>Private</strong> — sent as an outbound thread to the member only.<br>
    <strong>Hub broadcast</strong> — sent as a broadcast to all enrolled members in this hub area (use for public-record queries or where the answer benefits everyone).
  </p>
  <form method="POST" style="display:flex;flex-direction:column;gap:10px">
    <?= admin_csrf_field() ?>
    <input type="hidden" name="action" value="send_reply">
    <input type="hidden" name="query_id" value="<?= (int)$q['id'] ?>">
    <div>
      <label class="form-label">Reply type</label>
      <select name="reply_type" class="form-sel">
        <option value="private">🔒 Private — member only</option>
        <option value="broadcast">📢 Hub broadcast — all enrolled members</option>
      </select>
    </div>
    <div>
      <label class="form-label">Reply message</label>
      <textarea name="reply_body" class="form-tx" rows="5" placeholder="Write your reply…" required></textarea>
    </div>
    <div>
      <button class="admin-btn admin-btn-primary" type="submit">Send Reply</button>
    </div>
  </form>
</div>

<?php else: ?>
<!-- ── List view ────────────────────────────────────────────────────────── -->

<!-- Area summary tiles -->
<?php if ($querySummary): ?>
<div class="hq-grid">
  <?php foreach ($querySummary as $qs):
    $al = $AREA_LABELS[$qs['area_key']] ?? $qs['area_key'];
    $oc = (int)($qs['open_count']??0);
  ?>
  <div class="hq-tile" onclick="location.href='hub_queries.php?area=<?= urlencode($qs['area_key']) ?>'">
    <div class="area"><?= hq_h($al) ?></div>
    <div class="counts">
      <span class="open-badge"><?= $oc ?> open</span>
      <span class="res-badge"><?= (int)($qs['resolved_count']??0) ?> resolved</span>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Filter bar -->
<div class="filter-row">
  <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <select name="area" class="form-sel" onchange="this.form.submit()">
      <option value="">All hubs</option>
      <?php foreach ($AREA_LABELS as $k=>$l): ?>
        <option value="<?= hq_h($k) ?>" <?= $filterArea===$k?'selected':'' ?>><?= hq_h($l) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="status" class="form-sel" onchange="this.form.submit()">
      <option value="">All statuses</option>
      <?php foreach (['open','in_review','resolved','closed'] as $s): ?>
        <option value="<?= $s ?>" <?= $filterStatus===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($filterArea||$filterStatus): ?>
      <a href="hub_queries.php" style="font-size:.8rem;color:var(--muted,#666)">Clear filters</a>
    <?php endif; ?>
  </form>
</div>

<?php if (!$queries): ?>
  <p style="text-align:center;color:var(--muted,#666);padding:40px 0">No queries match the current filter.</p>
<?php else: ?>
  <div style="overflow-x:auto">
  <table class="tbl">
    <thead><tr>
      <th>#</th><th>Hub area</th><th>Subject</th>
      <th>Member</th><th>Transparency</th>
      <th>Status</th><th>Raised</th><th>Action</th>
    </tr></thead>
    <tbody>
    <?php foreach ($queries as $q):
      $al = $AREA_LABELS[$q['area_key']] ?? $q['area_key'];
      $chip = match($q['status']) {
          'open'      => '<span class="chip chip-open">Open</span>',
          'in_review' => '<span class="chip chip-review">In Review</span>',
          'resolved'  => '<span class="chip chip-resolved">Resolved</span>',
          'closed'    => '<span class="chip chip-closed">Closed</span>',
          default     => hq_h((string)$q['status'])
      };
      $tIcon = match($q['transparency']) {
          'hub_members'   => '👥',
          'public_record' => '📢',
          default         => '🔒'
      };
    ?>
    <tr>
      <td style="font-family:monospace;font-size:.76rem;color:var(--muted,#666)">#<?= (int)$q['id'] ?></td>
      <td style="font-size:.78rem;color:var(--muted,#666)"><?= hq_h($al) ?></td>
      <td style="max-width:240px;word-break:break-word"><?= hq_h(substr((string)$q['subject'],0,80)) ?></td>
      <td style="font-size:.76rem">
        #<?= hq_h((string)$q['member_id']) ?>
        <?php if (!empty($q['first_name'])): ?>
          <span style="color:var(--muted,#666)"> <?= hq_h((string)$q['first_name']) ?></span>
        <?php endif; ?>
      </td>
      <td style="font-size:.78rem"><?= $tIcon ?></td>
      <td><?= $chip ?></td>
      <td style="font-size:.73rem;color:var(--muted,#666);white-space:nowrap"><?= hq_h(substr((string)($q['created_at']??''),0,10)) ?></td>
      <td>
        <a href="hub_queries.php?view=<?= (int)$q['id'] ?>" class="admin-btn admin-btn-secondary" style="font-size:.75rem;padding:4px 10px">View</a>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
<?php endif; // viewQuery vs list ?>

</main></div>
</body>
</html>
