<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/admin_bootstrap.php';

$pdo    = admin_get_pdo();
$flash  = '';
$flashT = 'ok';

// ── Filters ───────────────────────────────────────────────────────────────────
$filterStatus = in_array($_GET['status'] ?? '', ['pending_review','cleared_for_use','rejected','withdrawn'], true)
    ? $_GET['status'] : 'pending_review';
$filterType   = in_array($_GET['type'] ?? '', ['text','audio','video'], true) ? $_GET['type'] : '';
$filterState  = trim((string)($_GET['state'] ?? ''));
$search       = trim((string)($_GET['q'] ?? ''));
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 25;

// ── Build query ───────────────────────────────────────────────────────────────
$where  = ['mvs.compliance_status = ?'];
$params = [$filterStatus];
if ($filterType)  { $where[] = 'mvs.submission_type = ?';  $params[] = $filterType; }
if ($filterState) { $where[] = 'mvs.display_state = ?';    $params[] = $filterState; }
if ($search) {
    $where[]  = '(mvs.text_content LIKE ? OR COALESCE(mvs.display_name_first, m.first_name) LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
$whereSQL = 'WHERE ' . implode(' AND ', $where);

$joinSQL = "FROM member_voice_submissions mvs
            JOIN partners p ON p.id = mvs.partner_id
            JOIN members  m ON m.id = p.member_id";

// Total count
$cntStmt = $pdo->prepare("SELECT COUNT(*) $joinSQL $whereSQL");
$cntStmt->execute($params);
$total      = (int)$cntStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// Items
$itemStmt = $pdo->prepare(
    "SELECT mvs.id, mvs.submission_type, mvs.text_content, mvs.file_path,
            mvs.file_mime_type, mvs.duration_seconds, mvs.compliance_status,
            mvs.compliance_notes, mvs.rejection_reason_to_member,
            mvs.used_in_post_url, mvs.created_at, mvs.compliance_reviewed_at,
            mvs.withdrawn_at,
            COALESCE(mvs.display_name_first, m.first_name) AS disp_name,
            COALESCE(mvs.display_state, m.state_code) AS disp_state,
            m.email AS member_email, mvs.partner_id
     $joinSQL $whereSQL
     ORDER BY mvs.created_at ASC
     LIMIT $perPage OFFSET $offset"
);
$itemStmt->execute($params);
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

// Pending count for badge
$pendingCount = (int)($pdo->query("SELECT COUNT(*) FROM member_voice_submissions WHERE compliance_status = 'pending_review'")->fetchColumn() ?: 0);

// Banned-framing terms for reference panel
$bannedTerms = [
    'investment', 'returns', 'profit', 'upside', 'gains', 'ROI',
    'get in early', "don't miss out", 'to the moon', 'presale',
    'IDO', 'IPO', 'token price', 'token launch', 'worth more later',
];

// ── Pagination helper ─────────────────────────────────────────────────────────
function vs_pager(int $page, int $total, int $perPage, string $baseUrl): string {
    $totalPages = max(1, (int)ceil($total / $perPage));
    if ($totalPages <= 1) return '';
    $o = '<div class="vs-pager">';
    if ($page > 1) $o .= '<a href="' . htmlspecialchars($baseUrl . 'page=' . ($page-1)) . '">‹</a>';
    $o .= '<span>Page ' . $page . ' of ' . $totalPages . ' (' . number_format($total) . ' total)</span>';
    if ($page < $totalPages) $o .= '<a href="' . htmlspecialchars($baseUrl . 'page=' . ($page+1)) . '">›</a>';
    $o .= '</div>';
    return $o;
}

$h     = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$baseQ = '?status=' . urlencode($filterStatus)
       . ($filterType  ? '&type=' . urlencode($filterType)  : '')
       . ($filterState ? '&state=' . urlencode($filterState) : '')
       . ($search      ? '&q=' . urlencode($search)          : '')
       . '&';

$statusLabels = [
    'pending_review'  => ['Pending',  'vs-badge-amber'],
    'cleared_for_use' => ['Accepted', 'vs-badge-green'],
    'rejected'        => ['Rejected', 'vs-badge-red'],
    'withdrawn'       => ['Withdrawn','vs-badge-grey'],
];
$typeIcons = ['text' => '✏️', 'audio' => '🎙️', 'video' => '🎬'];

$apiBase = '/_app/api/index.php?route=admin/voice-submissions/';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Voice Submissions — COG$ Admin</title>
  <link rel="stylesheet" href="/admin/assets/admin.css">
  <style>
    .vs-layout{display:grid;grid-template-columns:220px 1fr 320px;gap:0;height:calc(100vh - 120px);overflow:hidden}
    .vs-sidebar{padding:16px;border-right:1px solid var(--line,#2a2a2a);overflow-y:auto;background:var(--panel,#111)}
    .vs-queue{overflow-y:auto;border-right:1px solid var(--line,#2a2a2a)}
    .vs-detail{overflow-y:auto;padding:16px;background:var(--panel,#111)}
    .vs-sidebar h3{font-size:.7rem;text-transform:uppercase;letter-spacing:.1em;color:var(--muted,#888);margin:16px 0 6px}
    .vs-sidebar select,.vs-sidebar input[type=text]{width:100%;padding:6px 8px;margin-bottom:8px;background:var(--bg2,#1a1a1a);border:1px solid var(--line,#333);color:inherit;border-radius:4px;font-size:.85rem}
    .vs-sidebar button[type=submit]{width:100%;padding:7px;background:var(--gold,#c9973d);border:none;border-radius:4px;color:#000;font-weight:600;cursor:pointer;font-size:.85rem}
    .vs-item{padding:12px 16px;border-bottom:1px solid var(--line,#1e1e1e);cursor:pointer;transition:background .15s}
    .vs-item:hover,.vs-item.active{background:rgba(201,151,61,.08)}
    .vs-item-meta{display:flex;align-items:center;gap:8px;margin-bottom:4px;font-size:.8rem;color:var(--muted,#888)}
    .vs-item-preview{font-size:.85rem;color:var(--text,#eee);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .vs-badge{display:inline-block;padding:2px 7px;border-radius:99px;font-size:.7rem;font-weight:700}
    .vs-badge-amber{background:rgba(255,193,7,.15);color:#ffc107}
    .vs-badge-green{background:rgba(82,184,122,.15);color:#52b87a}
    .vs-badge-red{background:rgba(220,53,69,.15);color:#dc3545}
    .vs-badge-grey{background:rgba(120,120,120,.15);color:#888}
    .vs-pager{display:flex;align-items:center;gap:12px;padding:12px 16px;font-size:.8rem;border-top:1px solid var(--line,#2a2a2a);color:var(--muted,#888)}
    .vs-pager a{color:var(--gold,#c9973d);text-decoration:none;padding:2px 8px;border:1px solid var(--line,#333);border-radius:4px}
    .vs-detail .vs-btn{display:inline-block;padding:7px 14px;border-radius:4px;border:none;cursor:pointer;font-size:.85rem;font-weight:600;margin:4px 4px 4px 0}
    .vs-btn-approve{background:#52b87a;color:#000}
    .vs-btn-reject{background:#dc3545;color:#fff}
    .vs-btn-markused{background:var(--gold,#c9973d);color:#000}
    .vs-btn-withdraw{background:#6c757d;color:#fff}
    .vs-detail label{display:block;font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted,#888);margin:14px 0 4px}
    .vs-detail textarea,.vs-detail input[type=text]{width:100%;padding:7px 9px;background:var(--bg2,#1a1a1a);border:1px solid var(--line,#333);color:inherit;border-radius:4px;font-size:.85rem;resize:vertical}
    .vs-banned{background:rgba(220,53,69,.06);border:1px solid rgba(220,53,69,.2);border-radius:6px;padding:10px 12px;margin-top:auto}
    .vs-banned h4{font-size:.7rem;text-transform:uppercase;letter-spacing:.1em;color:#dc3545;margin-bottom:6px}
    .vs-banned ul{padding-left:14px;font-size:.78rem;color:var(--text2,#ccc)}
    .vs-empty{padding:32px;text-align:center;color:var(--muted,#888);font-size:.9rem}
    #vs-player{width:100%;margin-top:8px;border-radius:6px}
    .vs-flash-ok{background:rgba(82,184,122,.12);border:1px solid rgba(82,184,122,.3);color:#52b87a;padding:8px 12px;border-radius:4px;margin-bottom:12px;font-size:.85rem}
    .vs-flash-err{background:rgba(220,53,69,.12);border:1px solid rgba(220,53,69,.3);color:#dc3545;padding:8px 12px;border-radius:4px;margin-bottom:12px;font-size:.85rem}
  </style>
</head>
<body class="admin">
  <?php require_once __DIR__ . '/includes/admin_layout.php'; admin_render_header('dashboard', 'Voice Submissions', 'Member content moderation'); ?>

  <?php if ($flash): ?>
    <div class="vs-flash-<?= $h($flashT) ?>"><?= $h($flash) ?></div>
  <?php endif ?>

  <div id="vs-flash-js" style="display:none;margin:0 24px 8px"></div>

  <div class="vs-layout">

    <!-- ── Sidebar filters ── -->
    <aside class="vs-sidebar">
      <form method="get">
        <h3>Status</h3>
        <?php foreach (['pending_review'=>'Pending','cleared_for_use'=>'Accepted','rejected'=>'Rejected','withdrawn'=>'Withdrawn'] as $val => $lbl): ?>
          <div>
            <label style="font-size:.85rem;display:flex;align-items:center;gap:6px;margin-bottom:4px;cursor:pointer">
              <input type="radio" name="status" value="<?= $h($val) ?>" <?= $filterStatus===$val?'checked':'' ?>>
              <?= $h($lbl) ?>
              <?php if ($val==='pending_review' && $pendingCount > 0): ?>
                <span class="vs-badge vs-badge-amber"><?= $pendingCount ?></span>
              <?php endif ?>
            </label>
          </div>
        <?php endforeach ?>

        <h3>Type</h3>
        <select name="type">
          <option value="">All types</option>
          <option value="text"  <?= $filterType==='text' ?'selected':'' ?>>✏️ Text</option>
          <option value="audio" <?= $filterType==='audio'?'selected':'' ?>>🎙️ Audio</option>
          <option value="video" <?= $filterType==='video'?'selected':'' ?>>🎬 Video</option>
        </select>

        <h3>State</h3>
        <select name="state">
          <option value="">All states</option>
          <?php foreach (['NSW','QLD','VIC','SA','WA','TAS','ACT','NT'] as $st): ?>
            <option value="<?= $h($st) ?>" <?= $filterState===$st?'selected':'' ?>><?= $h($st) ?></option>
          <?php endforeach ?>
        </select>

        <h3>Search</h3>
        <input type="text" name="q" value="<?= $h($search) ?>" placeholder="Name or text…">
        <button type="submit">Apply filters</button>
      </form>

      <div class="vs-banned" style="margin-top:24px">
        <h4>§2 Banned framing — never approve</h4>
        <ul>
          <?php foreach ($bannedTerms as $t): ?><li><?= $h($t) ?></li><?php endforeach ?>
        </ul>
      </div>
    </aside>

    <!-- ── Queue ── -->
    <div class="vs-queue" id="vs-queue">
      <?php if (empty($items)): ?>
        <div class="vs-empty">No <?= $h($filterStatus === 'pending_review' ? 'pending' : ($filterStatus === 'cleared_for_use' ? 'accepted' : $filterStatus)) ?> submissions<?= $search ? ' matching "' . $h($search) . '"' : '' ?>.</div>
      <?php else: ?>
        <?php foreach ($items as $item):
          [$statusLabel, $statusClass] = $statusLabels[$item['compliance_status']] ?? ['Unknown','vs-badge-grey'];
          $icon = $typeIcons[$item['submission_type']] ?? '?';
          $preview = $item['submission_type'] === 'text'
              ? mb_substr((string)$item['text_content'], 0, 80) . (mb_strlen((string)$item['text_content']) > 80 ? '…' : '')
              : ucfirst($item['submission_type']) . ' · ' . ($item['duration_seconds'] ? $item['duration_seconds'] . 's' : '?s');
        ?>
          <div class="vs-item" data-id="<?= (int)$item['id'] ?>" onclick="vsSelect(<?= (int)$item['id'] ?>)">
            <div class="vs-item-meta">
              <span><?= $icon ?></span>
              <strong style="color:var(--text,#eee)"><?= $h($item['disp_name']) ?>, <?= $h($item['disp_state']) ?></strong>
              <span class="vs-badge <?= $h($statusClass) ?>"><?= $h($statusLabel) ?></span>
              <span style="margin-left:auto"><?= $h(substr((string)$item['created_at'], 0, 16)) ?></span>
            </div>
            <div class="vs-item-preview"><?= $h($preview) ?></div>
          </div>
        <?php endforeach ?>
      <?php endif ?>
      <?= vs_pager($page, $total, $perPage, $baseQ) ?>
    </div>

    <!-- ── Detail pane ── -->
    <div class="vs-detail" id="vs-detail">
      <p style="color:var(--muted,#888);font-size:.85rem;margin-top:32px;text-align:center">
        Select a submission to review.
      </p>
    </div>

  </div><!-- /vs-layout -->

<script>
(function(){
  'use strict';

  var API  = '/_app/api/index.php?route=admin/voice-submissions/';
  var sel  = null; // currently selected id

  function flash(msg, type) {
    var el = document.getElementById('vs-flash-js');
    el.textContent = msg;
    el.className = 'vs-flash-' + (type || 'ok');
    el.style.display = 'block';
    setTimeout(function(){ el.style.display = 'none'; }, 4000);
  }

  function h(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function vsSelect(id) {
    sel = id;
    document.querySelectorAll('.vs-item').forEach(function(el){
      el.classList.toggle('active', parseInt(el.dataset.id) === id);
    });
    // Use inline PHP data first (avoids round-trip for items on current page)
    if (ITEMS[id]) {
      renderDetail(ITEMS[id]);
      return;
    }
    // Fallback fetch for items not on this page
    fetch(API + id, {credentials:'include'})
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (!d.success) { flash('Failed to load: ' + (d.error || 'Unknown error'), 'err'); return; }
        renderDetail(d.data || d);
      })
      .catch(function(){ flash('Network error loading submission', 'err'); });
  }

  // Pre-render from inline PHP data (avoid extra fetch for items already in queue)
  var ITEMS = <?= json_encode(array_column($items, null, 'id')) ?>;

  document.querySelectorAll('.vs-item').forEach(function(el){
    el.addEventListener('click', function(){ vsSelect(parseInt(el.dataset.id)); });
  });

  function renderDetail(item) {
    var d   = document.getElementById('vs-detail');
    var st  = item.compliance_status;
    var isFile = item.submission_type === 'audio' || item.submission_type === 'video';
    var fileUrl = isFile ? '/_app/api/index.php?route=admin/voice-submissions/' + item.id + '/file' : '';
    var statusMap = {pending_review:'Pending',cleared_for_use:'Accepted',rejected:'Rejected',withdrawn:'Withdrawn'};
    var clsMap    = {pending_review:'vs-badge-amber',cleared_for_use:'vs-badge-green',rejected:'vs-badge-red',withdrawn:'vs-badge-grey'};

    var html = '<h3 style="margin:0 0 12px">#' + item.id + ' &nbsp;<span class="vs-badge ' + h(clsMap[st]||'') + '">' + h(statusMap[st]||st) + '</span></h3>';
    html += '<div style="font-size:.82rem;color:var(--muted,#888);margin-bottom:12px">';
    html += h((item.disp_name||'') + ', ' + (item.disp_state||'')) + ' · ' + h(item.submission_type||'') + ' · ' + h((item.created_at||'').slice(0,16));
    html += '</div>';

    if (item.submission_type === 'text') {
      html += '<div style="background:rgba(255,255,255,.04);border-radius:6px;padding:12px;font-size:.9rem;line-height:1.6;white-space:pre-wrap">' + h(item.text_content||'') + '</div>';
    } else if (isFile) {
      var mime  = item.file_mime_type || '';
      var isVid = mime.startsWith('video');
      html += '<' + (isVid?'video':'audio') + ' id="vs-player" controls src="' + h(fileUrl) + '"></' + (isVid?'video':'audio') + '>';
      if (item.duration_seconds) html += '<div style="font-size:.78rem;color:var(--muted,#888);margin-top:4px">' + h(item.duration_seconds) + 's</div>';
    }

    if (item.used_in_post_url) {
      html += '<label>Used in post</label><div style="font-size:.8rem;word-break:break-all"><a href="' + h(item.used_in_post_url) + '" target="_blank" rel="noopener">' + h(item.used_in_post_url) + '</a></div>';
    }
    if (item.rejection_reason_to_member) {
      html += '<label>Rejection reason (shown to member)</label><div style="font-size:.82rem;white-space:pre-wrap;background:rgba(220,53,69,.06);padding:8px;border-radius:4px">' + h(item.rejection_reason_to_member) + '</div>';
    }

    // Action buttons
    html += '<div style="margin-top:16px">';
    if (st === 'pending_review') {
      html += '<button class="vs-btn vs-btn-approve" onclick="vsApprove()">✓ Accept</button>';
      html += '<button class="vs-btn vs-btn-reject" onclick="vsRejectOpen()">✗ Reject</button>';
    }
    if (st === 'cleared_for_use') {
      html += '<button class="vs-btn vs-btn-markused" onclick="vsMarkUsedOpen()">🔗 Mark as used</button>';
      html += '<button class="vs-btn vs-btn-withdraw" onclick="vsWithdrawOpen()">Withdraw</button>';
    }
    if (st === 'pending_review' || st === 'cleared_for_use') {
      html += '<button class="vs-btn vs-btn-withdraw" onclick="vsWithdrawOpen()" style="margin-left:4px">Admin withdraw</button>';
    }
    html += '</div>';

    // Notes textareas
    html += '<label>Internal notes (not shown to member)</label>';
    html += '<textarea id="vs-notes" rows="3" placeholder="Optional internal note…"></textarea>';

    // Rejection form (hidden by default)
    html += '<div id="vs-reject-form" style="display:none">';
    html += '<label>Reason shown to member (required for rejection)</label>';
    html += '<textarea id="vs-reject-reason" rows="4" placeholder="Thanks for sending this through. Could you rephrase to focus on why you joined the community?"></textarea>';
    html += '<button class="vs-btn vs-btn-reject" style="margin-top:4px" onclick="vsRejectConfirm()">Confirm reject</button>';
    html += '<button class="vs-btn" style="background:none;border:1px solid #444;color:#ccc" onclick="document.getElementById(\'vs-reject-form\').style.display=\'none\'">Cancel</button>';
    html += '</div>';

    // Mark as used form (hidden by default)
    html += '<div id="vs-used-form" style="display:none">';
    html += '<label>Permalink to the FB or YT post</label>';
    html += '<input type="text" id="vs-used-url" placeholder="https://facebook.com/…">';
    html += '<button class="vs-btn vs-btn-markused" style="margin-top:4px" onclick="vsMarkUsedConfirm()">Confirm</button>';
    html += '<button class="vs-btn" style="background:none;border:1px solid #444;color:#ccc" onclick="document.getElementById(\'vs-used-form\').style.display=\'none\'">Cancel</button>';
    html += '</div>';

    // Withdraw form
    html += '<div id="vs-withdraw-form" style="display:none">';
    html += '<label>Withdrawal reason (internal)</label>';
    html += '<input type="text" id="vs-withdraw-reason" placeholder="Reason (optional)">';
    html += '<button class="vs-btn vs-btn-withdraw" style="margin-top:4px" onclick="vsWithdrawConfirm()">Confirm withdraw</button>';
    html += '<button class="vs-btn" style="background:none;border:1px solid #444;color:#ccc" onclick="document.getElementById(\'vs-withdraw-form\').style.display=\'none\'">Cancel</button>';
    html += '</div>';

    d.innerHTML = html;
  }

  function vsApprove() {
    if (!sel) return;
    var notes = (document.getElementById('vs-notes')||{}).value || '';
    fetch(API + sel + '/approve', {
      method:'POST', credentials:'include',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({notes: notes})
    }).then(function(r){ return r.json(); }).then(function(d){
      if (d.success) { flash('Accepted. Member notified.'); location.reload(); }
      else flash('Error: ' + d.error, 'err');
    }).catch(function(){ flash('Network error', 'err'); });
  }

  function vsRejectOpen() {
    var rf = document.getElementById('vs-reject-form');
    if (rf) rf.style.display = rf.style.display === 'none' ? 'block' : 'none';
  }

  function vsRejectConfirm() {
    if (!sel) return;
    var notes  = (document.getElementById('vs-notes')||{}).value || '';
    var reason = (document.getElementById('vs-reject-reason')||{}).value || '';
    if (!reason.trim()) { flash('Please write a reason for the member.', 'err'); return; }
    fetch(API + sel + '/reject', {
      method:'POST', credentials:'include',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({compliance_notes: notes, rejection_reason_to_member: reason})
    }).then(function(r){ return r.json(); }).then(function(d){
      if (d.success) { flash('Rejected. Member notified.'); location.reload(); }
      else flash('Error: ' + d.error, 'err');
    }).catch(function(){ flash('Network error', 'err'); });
  }

  function vsMarkUsedOpen() {
    var f = document.getElementById('vs-used-form');
    if (f) f.style.display = f.style.display === 'none' ? 'block' : 'none';
  }

  function vsMarkUsedConfirm() {
    if (!sel) return;
    var url = (document.getElementById('vs-used-url')||{}).value || '';
    if (!url.trim()) { flash('Please enter the post URL.', 'err'); return; }
    fetch(API + sel + '/mark-used', {
      method:'POST', credentials:'include',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({used_in_post_url: url})
    }).then(function(r){ return r.json(); }).then(function(d){
      if (d.success) { flash('Marked as used.'); location.reload(); }
      else flash('Error: ' + d.error, 'err');
    }).catch(function(){ flash('Network error', 'err'); });
  }

  function vsWithdrawOpen() {
    var f = document.getElementById('vs-withdraw-form');
    if (f) f.style.display = f.style.display === 'none' ? 'block' : 'none';
  }

  function vsWithdrawConfirm() {
    if (!sel) return;
    var reason = (document.getElementById('vs-withdraw-reason')||{}).value || '';
    fetch(API + sel + '/withdraw', {
      method:'POST', credentials:'include',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({reason: reason})
    }).then(function(r){ return r.json(); }).then(function(d){
      if (d.success) {
        flash(d.data && d.data.social_removal ? 'Withdrawn. Take down the social post within 24 hours.' : 'Withdrawn.');
        location.reload();
      } else flash('Error: ' + d.error, 'err');
    }).catch(function(){ flash('Network error', 'err'); });
  }

  // Auto-load first item if only one on page
  if (<?= count($items) ?> === 1) {
    var firstId = <?= count($items) > 0 ? (int)$items[0]['id'] : 0 ?>;
    if (firstId) vsSelect(firstId);
  }

  window.vsSelect  = vsSelect;
  window.vsApprove = vsApprove;
  window.vsRejectOpen    = vsRejectOpen;
  window.vsRejectConfirm = vsRejectConfirm;
  window.vsMarkUsedOpen    = vsMarkUsedOpen;
  window.vsMarkUsedConfirm = vsMarkUsedConfirm;
  window.vsWithdrawOpen    = vsWithdrawOpen;
  window.vsWithdrawConfirm = vsWithdrawConfirm;

})();
</script>
</body>
</html>
