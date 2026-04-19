<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/ops_workflow.php';
ops_require_admin();
$pdo = ops_db();
$labels = ops_label_settings($pdo);
$partnerLabel = $labels['public_label_partner'] ?? 'Partner';

function gv_rows(PDO $pdo, string $sql, array $params = []): array { try { return ops_fetch_all($pdo, $sql, $params); } catch (Throwable $e) { return []; } }
function gv_val(PDO $pdo, string $sql, array $params = []): int { try { return (int)ops_fetch_val($pdo, $sql, $params); } catch (Throwable $e) { return 0; } }
function gv_badge(string $status): string {
    $status = trim($status);
    $class = match ($status) {
        'open','active','executed','declared','confirmed','submitted','approved','published','endorsed' => 'badge-open',
        'closed','archived','resolved','cancelled','retired','rejected','withdrawn' => 'badge-closed',
        default => 'badge-draft',
    };
    return '<span class="badge ' . $class . '">' . ops_h($status !== '' ? $status : '—') . '</span>';
}

$controls = ops_has_table($pdo, 'v_phase1_governance_control_status') ? gv_rows($pdo, 'SELECT * FROM v_phase1_governance_control_status ORDER BY exception_count DESC, evidence_link_count DESC, control_key ASC LIMIT 100') : [];
$polls = ops_has_table($pdo, 'community_polls') ? gv_rows($pdo, 'SELECT * FROM community_polls ORDER BY id DESC LIMIT 50') : [];
$proposals = ops_has_table($pdo, 'proposal_register') ? gv_rows($pdo, 'SELECT * FROM proposal_register ORDER BY id DESC LIMIT 50') : [];
$directions = ops_has_table($pdo, 'governance_directions') ? gv_rows($pdo, 'SELECT * FROM governance_directions ORDER BY id DESC LIMIT 50') : [];
$executions = ops_has_table($pdo, 'board_execution_records') ? gv_rows($pdo, 'SELECT ber.*, gd.direction_key FROM board_execution_records ber LEFT JOIN governance_directions gd ON gd.id = ber.governance_direction_id ORDER BY ber.id DESC LIMIT 50') : [];
$proxy = ops_has_table($pdo, 'proxy_vote_instructions') ? gv_rows($pdo, 'SELECT * FROM proxy_vote_instructions ORDER BY id DESC LIMIT 50') : [];
$snapshots = ops_has_table($pdo, 'vote_snapshots') ? gv_rows($pdo, 'SELECT * FROM vote_snapshots ORDER BY id DESC LIMIT 50') : [];
$legacyProposalCount = ops_has_table($pdo, 'vote_proposals') ? gv_val($pdo, 'SELECT COUNT(*) FROM vote_proposals') : 0;
$legacyPollCount = ops_has_table($pdo, 'wallet_polls') ? gv_val($pdo, 'SELECT COUNT(*) FROM wallet_polls') : 0;

/* ── LIVE PROPOSAL TALLIES ── */
$voteProposals = [];
if (ops_has_table($pdo, 'vote_proposals')) {
    $voteProposals = gv_rows($pdo,
        "SELECT id, proposal_key, title, summary, status, audience_scope, closes_at, created_at
         FROM vote_proposals ORDER BY id DESC LIMIT 20");
    if ($voteProposals) {
        $vpIds = array_map(fn($r) => (int)$r['id'], $voteProposals);
        $ph = implode(',', array_fill(0, count($vpIds), '?'));
        // Tallies
        $vpTallies = [];
        try {
            $rs = ops_fetch_all($pdo,
                "SELECT proposal_id, response_value, COUNT(*) AS cnt
                 FROM vote_proposal_responses WHERE proposal_id IN ($ph)
                 GROUP BY proposal_id, response_value", $vpIds);
            foreach ($rs as $r) {
                $vpTallies[(int)$r['proposal_id']][$r['response_value']] = (int)$r['cnt'];
            }
        } catch (Throwable $e) {}
        // Comment counts
        $vpCommentCounts = [];
        if (ops_has_table($pdo, 'proposal_comments')) {
            try {
                $rs = ops_fetch_all($pdo,
                    "SELECT proposal_id, COUNT(*) AS cnt
                     FROM proposal_comments WHERE proposal_id IN ($ph)
                     GROUP BY proposal_id", $vpIds);
                foreach ($rs as $r) $vpCommentCounts[(int)$r['proposal_id']] = (int)$r['cnt'];
            } catch (Throwable $e) {}
        }
        // Comments (latest 10 per proposal)
        $vpComments = [];
        if (ops_has_table($pdo, 'proposal_comments')) {
            try {
                $rs = ops_fetch_all($pdo,
                    "SELECT proposal_id, comment_text, submitted_at
                     FROM proposal_comments WHERE proposal_id IN ($ph)
                     ORDER BY proposal_id ASC, submitted_at DESC", $vpIds);
                foreach ($rs as $r) {
                    $pid = (int)$r['proposal_id'];
                    if (!isset($vpComments[$pid])) $vpComments[$pid] = [];
                    if (count($vpComments[$pid]) < 10) $vpComments[$pid][] = $r;
                }
            } catch (Throwable $e) {}
        }
        // Inject into proposal rows
        foreach ($voteProposals as &$vp) {
            $pid  = (int)$vp['id'];
            $t_   = $vpTallies[$pid] ?? [];
            $yes  = (int)($t_['yes']   ?? 0);
            $maybe= (int)($t_['maybe'] ?? 0);
            $no   = (int)($t_['no']    ?? 0);
            $total= $yes + $maybe + $no;
            $vp['tally'] = ['yes' => $yes, 'maybe' => $maybe, 'no' => $no];
            $vp['total_responses'] = $total;
            $vp['comment_count']   = $vpCommentCounts[$pid] ?? 0;
            $vp['comments']        = $vpComments[$pid] ?? [];
        }
        unset($vp);
    }
}

/* ── LIVE POLL VOTE TALLIES ── */
$walletPollsFull = [];
if (ops_has_table($pdo, 'wallet_polls')) {
    $walletPollsFull = gv_rows($pdo,
        "SELECT id, poll_key, title, summary, poll_type, audience_scope,
                status, opens_at, closes_at, certified_at, result_summary,
                quorum_reached, community_poll_id
         FROM wallet_polls ORDER BY id DESC LIMIT 20");
    if ($walletPollsFull && ops_has_table($pdo, 'wallet_poll_votes')) {
        $wpIds = array_map(fn($r) => (int)$r['id'], $walletPollsFull);
        $ph2 = implode(',', array_fill(0, count($wpIds), '?'));
        $wpTallies = [];
        $wpTotals  = [];
        try {
            $rs = ops_fetch_all($pdo,
                "SELECT poll_id, choice_code, COUNT(*) AS cnt
                 FROM wallet_poll_votes WHERE poll_id IN ($ph2)
                 GROUP BY poll_id, choice_code
                 ORDER BY poll_id, cnt DESC", $wpIds);
            foreach ($rs as $r) {
                $pid = (int)$r['poll_id'];
                $wpTallies[$pid][] = ['choice' => $r['choice_code'], 'votes' => (int)$r['cnt']];
                $wpTotals[$pid]    = ($wpTotals[$pid] ?? 0) + (int)$r['cnt'];
            }
        } catch (Throwable $e) {}
        foreach ($walletPollsFull as &$wp) {
            $pid = (int)$wp['id'];
            $wp['vote_tally'] = $wpTallies[$pid] ?? [];
            $wp['total_votes']= $wpTotals[$pid]  ?? 0;
        }
        unset($wp);
    }
}

$stats = [
    'Control rows' => count($controls),
    'Partners polls' => count($polls),
    'Proposal register' => count($proposals),
    'Directions' => count($directions),
    'Execution records' => count($executions),
    'Vote snapshots' => count($snapshots),
];

ob_start(); ?>
<?php ops_admin_help_assets_once(); ?>
<style>
.row-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.bridge-note{padding:14px 16px;border-radius:14px;background:rgba(212,178,92,.08);border:1px solid rgba(212,178,92,.2);margin-bottom:18px;font-size:.88rem;line-height:1.6}
.section-title{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin:0}
@media(max-width:900px){.row-grid,.stat-grid{grid-template-columns:1fr 1fr}}
@media(max-width:640px){.row-grid,.stat-grid{grid-template-columns:1fr}}
/* badge-open/closed/draft used in gv_badge() */
.badge-open{background:rgba(82,184,122,.12);color:#7ee0a0;border:1px solid rgba(82,184,122,.25)}
.badge-closed{background:rgba(196,96,96,.12);color:#f0a0a0;border:1px solid rgba(196,96,96,.25)}
.badge-draft{background:rgba(212,178,92,.12);color:var(--gold);border:1px solid rgba(212,178,92,.2)}
/* Live tally cards */
.gv-proposal-card{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:18px 20px;margin-bottom:14px}
.gv-proposal-card.status-open{border-left:3px solid rgba(82,184,122,.5)}
.gv-proposal-card.status-closed,.gv-proposal-card.status-archived{border-left:3px solid rgba(155,155,155,.3)}
.gv-prop-title{font-size:1rem;font-weight:700;color:var(--text);margin-bottom:4px}
.gv-prop-meta{font-size:.8rem;color:var(--sub);margin-bottom:14px;display:flex;gap:12px;flex-wrap:wrap;align-items:center}
.gv-tally{margin:0 0 14px}
.gv-tally-row{display:flex;align-items:center;gap:10px;margin-bottom:7px}
.gv-tally-lbl{font-size:.82rem;font-weight:700;min-width:52px;text-transform:uppercase;letter-spacing:.04em}
.gv-tally-lbl.yes{color:var(--ok)}
.gv-tally-lbl.maybe{color:#e09a42}
.gv-tally-lbl.no{color:var(--err)}
.gv-tally-track{flex:1;height:7px;background:rgba(255,255,255,.07);border-radius:99px;overflow:hidden}
.gv-tally-fill{height:100%;border-radius:99px;transition:width .5s ease}
.gv-tally-fill.yes{background:var(--ok)}
.gv-tally-fill.maybe{background:#e09a42}
.gv-tally-fill.no{background:var(--err)}
.gv-tally-count{font-family:var(--mono,monospace);font-size:.8rem;color:var(--sub);min-width:80px;text-align:right}
.gv-tally-total{font-size:.8rem;color:var(--sub);margin-top:4px}
.gv-comments-toggle{background:none;border:1px solid rgba(255,255,255,.1);border-radius:8px;color:var(--sub);font-size:.8rem;padding:4px 10px;cursor:pointer;margin-bottom:8px}
.gv-comments-toggle:hover{border-color:rgba(212,178,92,.3);color:var(--gold)}
.gv-comments-list{margin-top:8px;display:none}
.gv-comments-list.open{display:block}
.gv-comment-item{padding:8px 12px;background:rgba(255,255,255,.02);border:1px solid rgba(255,255,255,.06);border-radius:8px;margin-bottom:6px}
.gv-comment-text{font-size:.85rem;color:var(--text);line-height:1.55}
.gv-comment-date{font-size:.75rem;color:var(--dim);margin-top:3px}
.gv-no-comments{font-size:.82rem;color:var(--dim);padding:6px 0}
/* Poll results */
.gv-poll-card{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:18px 20px;margin-bottom:14px}
.gv-poll-card.status-open{border-left:3px solid rgba(212,178,92,.5)}
.gv-poll-card.status-closed,.gv-poll-card.status-certified{border-left:3px solid rgba(82,184,122,.4)}
.gv-poll-choice{font-size:.82rem;font-weight:700;color:var(--text);min-width:120px;font-family:var(--mono,monospace)}
.gv-live-dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--ok);margin-right:5px;animation:gv-pulse 2s infinite}
@keyframes gv-pulse{0%,100%{opacity:1}50%{opacity:.3}}
</style>

<?= ops_admin_collapsible_help('Page guide & workflow', [
  ops_admin_info_panel('Stage 6 · Governance', 'What this page does', 'Governance is the authoritative operator page for formal poll records, the proposal register, governance directions, proxy-vote instructions, board execution records, and certified vote snapshots.', [
    'Partners Polls are the formal governance instrument — rely on these when checking live governance state.',
    'The proposal register shows initiation and pre-poll objects. It is not the final voting record by itself.',
    'Governance directions show what operational instruction should be carried out after governance has spoken.',
    'Proxy instructions and vote snapshots are evidence objects that support the live governance trail.',
  ]),
  ops_admin_workflow_panel('Typical workflow', 'Governance moves through a clear sequence from initiation to evidence.', [
    ['title' => 'Proposal / initiation', 'body' => 'A governance topic is opened and tracked in the proposal register.'],
    ['title' => 'Formal poll', 'body' => 'The matter becomes a Partners Poll and the eligible voting group is opened.'],
    ['title' => 'Result / direction', 'body' => 'Once the result is declared, a governance direction may be created for operational follow-through.'],
    ['title' => 'Execution / proxy / publication', 'body' => 'The board execution record, proxy instruction, or downstream control action shows how the decision was carried out.'],
    ['title' => 'Snapshot / evidence', 'body' => 'Vote snapshots and governance-control evidence preserve the auditable record of what happened.'],
  ]),
  ops_admin_guide_panel('How to use this page', 'Each section answers a different operator question.', [
    ['title' => 'Governance control status', 'body' => 'Use this first to see whether any governance controls are missing evidence or carrying open exceptions.'],
    ['title' => 'Partners Polls', 'body' => 'This is the formal voting record. Check poll title, eligibility scope, current status, and close time.'],
    ['title' => 'Proposal register', 'body' => 'Use this to understand where a matter started and whether it has already been linked to a formal poll.'],
    ['title' => 'Directions and execution', 'body' => 'Use these two sections together to confirm whether a governance outcome has been carried into an operational action.'],
    ['title' => 'Proxy instructions and vote snapshots', 'body' => 'Use these when checking investment stewardship, vote evidence, or certification history.'],
  ]),
  ops_admin_status_panel('Status guide', 'These terms appear repeatedly across the governance page.', [
    ['label' => 'Draft / proposed / pending', 'body' => 'The matter exists, but the formal governance or review step is not complete yet.'],
    ['label' => 'Open / active', 'body' => 'The poll, review, or instruction is live and still requires operator attention.'],
    ['label' => 'Declared / confirmed / executed', 'body' => 'The relevant step has been completed and can be used as part of the audit record.'],
    ['label' => 'Closed / archived / retired', 'body' => 'The record remains part of history, but it is no longer an active operator task.'],
    ['label' => 'Legacy bridge', 'body' => 'Visible for compatibility and retirement checks only.'],
  ]),
]) ?>

<div class="card">
  <div class="card-head">
    <h1 style="margin:0">Governance control plane <?= ops_admin_help_button('Governance control plane', 'Use this page to read live governance state — what polls exist, what directions are live, what was executed, and what evidence supports the result.') ?></h1>
  </div>
  <div class="card-body" style="padding-top:6px">
    <p class="muted small" style="margin:0">Start at governance control status, then review live Partners Polls, then follow any resulting directions and evidence objects.</p>
  </div>
</div>

<div class="stat-grid">
  <?php foreach ($stats as $label => $value): ?>
    <div class="card"><div class="card-body"><div class="stat-label"><?= ops_h($label) ?></div><div class="stat-value"><?= (int)$value ?></div></div></div>
  <?php endforeach; ?>
</div>

<!-- ══ VOTE PROPOSALS — LIVE TALLY ══ -->
<div class="card" style="margin-bottom:18px">
  <div class="card-head" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <h2 class="section-title">
      <?php if (array_filter($voteProposals, fn($p) => $p['status'] === 'open')): ?>
        <span class="gv-live-dot"></span>
      <?php endif; ?>
      Vote Proposals — Community Responses
      <?= ops_admin_help_button('Vote proposals tally', 'Live Yes/Maybe/No response tallies from the member wallet. Updates every 30 seconds automatically. Comments are anonymous — no member ID is stored.') ?>
    </h2>
    <span style="font-size:.8rem;color:var(--sub)" id="gv-tally-ts"></span>
  </div>
  <div class="card-body">
  <?php if (!$voteProposals): ?>
    <p class="muted small">No vote proposals found.</p>
  <?php else: ?>
    <div id="gv-proposals-wrap">
    <?php foreach ($voteProposals as $vp):
      $pid    = (int)$vp['id'];
      $tally  = $vp['tally'];
      $total  = $vp['total_responses'];
      $status = $vp['status'];
      $pct    = fn(int $v) => $total > 0 ? round($v / $total * 100) : 0;
    ?>
    <div class="gv-proposal-card status-<?= ops_h($status) ?>" id="gv-prop-<?= $pid ?>">
      <div class="gv-prop-title"><?= ops_h($vp['title']) ?></div>
      <div class="gv-prop-meta">
        <?= gv_badge($status) ?>
        <span>ID <?= $pid ?></span>
        <?php if ($vp['closes_at']): ?><span>Closes <?= ops_h($vp['closes_at']) ?></span><?php endif; ?>
        <span><?= ops_h($vp['audience_scope']) ?> audience</span>
        <span><?= (int)$vp['comment_count'] ?> comment<?= $vp['comment_count'] != 1 ? 's' : '' ?></span>
      </div>
      <?php if ($vp['summary']): ?>
        <div style="font-size:.85rem;color:var(--sub);margin-bottom:12px;line-height:1.5"><?= ops_h($vp['summary']) ?></div>
      <?php endif; ?>

      <!-- Tally bars -->
      <div class="gv-tally" id="gv-tally-<?= $pid ?>">
        <?php foreach (['yes' => 'Yes', 'maybe' => 'Maybe', 'no' => 'No'] as $key => $lbl): ?>
        <div class="gv-tally-row">
          <span class="gv-tally-lbl <?= $key ?>"><?= $lbl ?></span>
          <div class="gv-tally-track">
            <div class="gv-tally-fill <?= $key ?>" id="gv-fill-<?= $pid ?>-<?= $key ?>"
                 style="width:<?= $pct($tally[$key]) ?>%"></div>
          </div>
          <span class="gv-tally-count" id="gv-count-<?= $pid ?>-<?= $key ?>">
            <?= $tally[$key] ?> (<?= $pct($tally[$key]) ?>%)
          </span>
        </div>
        <?php endforeach; ?>
        <div class="gv-tally-total" id="gv-total-<?= $pid ?>">
          <?= $total ?> response<?= $total != 1 ? 's' : '' ?>
        </div>
      </div>

      <!-- Comments toggle -->
      <?php if ($vp['comment_count'] > 0 || !empty($vp['comments'])): ?>
      <button class="gv-comments-toggle" onclick="gvToggleComments(this, <?= $pid ?>)">
        💬 Show <?= (int)$vp['comment_count'] ?> anonymous comment<?= $vp['comment_count'] != 1 ? 's' : '' ?>
      </button>
      <div class="gv-comments-list" id="gv-cmt-<?= $pid ?>">
        <?php if (empty($vp['comments'])): ?>
          <div class="gv-no-comments">No comments loaded.</div>
        <?php else: ?>
          <?php foreach ($vp['comments'] as $cmt): ?>
          <div class="gv-comment-item">
            <div class="gv-comment-text"><?= ops_h($cmt['comment_text']) ?></div>
            <div class="gv-comment-date"><?= ops_h($cmt['submitted_at']) ?></div>
          </div>
          <?php endforeach; ?>
          <?php if ($vp['comment_count'] > count($vp['comments'])): ?>
          <div class="gv-no-comments">… and <?= $vp['comment_count'] - count($vp['comments']) ?> more. View in database for full list.</div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <?php else: ?>
        <div style="font-size:.8rem;color:var(--dim)">No comments yet.</div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
  </div>
</div>

<!-- ══ WALLET POLLS — BINDING VOTE RESULTS ══ -->
<div class="card" style="margin-bottom:18px">
  <div class="card-head">
    <h2 class="section-title">
      <?php if (array_filter($walletPollsFull, fn($p) => $p['status'] === 'open')): ?>
        <span class="gv-live-dot"></span>
      <?php endif; ?>
      Partners Polls — Binding Vote Results
      <?= ops_admin_help_button('Partners Polls vote results', 'Binding vote breakdowns from wallet_poll_votes. These are the formal governance instruments. Certified polls show the locked result_summary JSON. Open polls show live running tallies.') ?>
    </h2>
  </div>
  <div class="card-body">
  <?php if (!$walletPollsFull): ?>
    <p class="muted small">No Partners Polls found.</p>
  <?php else: ?>
    <?php foreach ($walletPollsFull as $wp):
      $wpid   = (int)$wp['id'];
      $wTotal = (int)$wp['total_votes'];
      $wTally = $wp['vote_tally'];
      $wStatus= $wp['status'];
      $pct2   = fn(int $v) => $wTotal > 0 ? round($v / $wTotal * 100) : 0;
      // Parse result_summary if certified
      $resultSummary = null;
      if (!empty($wp['result_summary'])) {
          try { $resultSummary = json_decode($wp['result_summary'], true); } catch (Throwable $e) {}
      }
    ?>
    <div class="gv-poll-card status-<?= ops_h($wStatus) ?>">
      <div class="gv-prop-title"><?= ops_h($wp['title'] ?: $wp['poll_key']) ?></div>
      <div class="gv-prop-meta">
        <?= gv_badge($wStatus) ?>
        <span class="badge badge-draft"><?= ops_h(str_replace('_', ' ', $wp['poll_type'])) ?></span>
        <span><?= ops_h($wp['audience_scope']) ?> audience</span>
        <?php if ($wp['opens_at']): ?><span>Opens <?= ops_h($wp['opens_at']) ?></span><?php endif; ?>
        <?php if ($wp['closes_at']): ?><span>Closes <?= ops_h($wp['closes_at']) ?></span><?php endif; ?>
        <?php if ($wp['certified_at']): ?><span style="color:var(--ok)">✓ Certified <?= ops_h($wp['certified_at']) ?></span><?php endif; ?>
        <?php if ($wp['community_poll_id']): ?><span>→ Community poll #<?= (int)$wp['community_poll_id'] ?></span><?php endif; ?>
      </div>
      <?php if ($wp['summary']): ?>
        <div style="font-size:.85rem;color:var(--sub);margin-bottom:12px;line-height:1.5"><?= ops_h($wp['summary']) ?></div>
      <?php endif; ?>

      <?php if ($wTotal > 0): ?>
      <!-- Vote breakdown bars -->
      <div class="gv-tally">
        <?php foreach ($wTally as $i => $row): ?>
        <div class="gv-tally-row">
          <span class="gv-poll-choice"><?= ops_h($row['choice']) ?></span>
          <div class="gv-tally-track">
            <div class="gv-tally-fill" style="width:<?= $pct2($row['votes']) ?>%;background:<?= $i === 0 ? 'var(--ok)' : ($i === count($wTally)-1 ? 'var(--err)' : '#e09a42') ?>"></div>
          </div>
          <span class="gv-tally-count"><?= $row['votes'] ?> (<?= $pct2($row['votes']) ?>%)</span>
        </div>
        <?php endforeach; ?>
        <div class="gv-tally-total">
          <?= $wTotal ?> vote<?= $wTotal != 1 ? 's' : '' ?> cast
          <?php if ($wp['quorum_reached']): ?> · <span style="color:var(--ok)">✓ Quorum reached</span><?php endif; ?>
        </div>
      </div>
      <?php else: ?>
        <div style="font-size:.85rem;color:var(--dim);margin:8px 0">No votes cast yet.</div>
      <?php endif; ?>

      <?php if ($resultSummary): ?>
      <details style="margin-top:10px">
        <summary style="font-size:.82rem;color:var(--gold);cursor:pointer">Certified result JSON</summary>
        <pre style="font-size:.75rem;color:var(--sub);background:rgba(0,0,0,.2);padding:10px;border-radius:8px;overflow-x:auto;margin-top:6px"><?= ops_h(json_encode($resultSummary, JSON_PRETTY_PRINT)) ?></pre>
      </details>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
  </div>
</div>

<div class="bridge-note">
  <strong>Legacy bridge diagnostic only:</strong>
  <span class="legend-note"><?= (int)$legacyProposalCount ?> legacy proposal threads and <?= (int)$legacyPollCount ?> legacy wallet polls remain visible for compatibility and retirement checks. Use <a href="./legacy-dependencies.php">Legacy Bridge Status</a> to see whether those older dependencies can be retired. Treat the sections below as the live governance reading surface.</span>
</div>

<div class="row-grid">
  <div class="card">
  <div class="card-head"><h2 class="section-title">Governance control status<?= ops_admin_help_button('Governance control status', 'Each row represents a governance control area. Check evidence counts and exception counts first. A high exception count means governance may be operationally complete on paper but still missing part of its evidence trail.') ?></h2></div>
  <div class="card-body">
    <p class="muted small">Start here when you need a quick health view of the governance control environment.</p>
    <div class="table-wrap"><table>
      <thead><tr><th>Control<?= ops_admin_help_button('Control key', 'The internal identifier for the governance control area being measured.') ?></th><th>Area<?= ops_admin_help_button('Area', 'The broad governance domain that the control belongs to, such as voting, evidence, or publication.') ?></th><th>Evidence<?= ops_admin_help_button('Evidence count', 'How many evidence links have been recorded against that control. Low evidence may indicate an incomplete audit trail.') ?></th><th>Exceptions<?= ops_admin_help_button('Exception count', 'Open exceptions or unresolved issues linked to the control. Investigate high counts first.') ?></th><th>Status<?= ops_admin_help_button('Control status', 'The summarised state of the control based on evidence and exceptions.') ?></th></tr></thead>
      <tbody>
      <?php if (!$controls): ?><tr><td colspan="5" class="empty-note">No governance control rows found.</td></tr><?php endif; ?>
      <?php foreach ($controls as $row): ?><tr>
        <td class="code"><?= ops_h($row['control_key'] ?? '') ?></td>
        <td><?= ops_h($row['control_area'] ?? '') ?></td>
        <td><?= (int)($row['evidence_link_count'] ?? 0) ?></td>
        <td><?= (int)($row['exception_count'] ?? 0) ?></td>
        <td><?= gv_badge((string)($row['control_status'] ?? '')) ?></td>
      </tr><?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  </div>
  <div class="card">
  <div class="card-head"><h2 class="section-title">Partners Polls<?= ops_admin_help_button('Partners Polls', 'These are the formal governance instruments. When someone asks what the partnership has voted on, this is the section to use first. Focus on title, eligibility scope, status, and voting close time.') ?></h2></div>
  <div class="card-body">
    <p class="muted small">Formal poll records only. These are the authoritative governance objects, not general discussion or consultation threads.</p>
    <div class="table-wrap"><table>
      <thead><tr><th>Poll<?= ops_admin_help_button('Poll title', 'The formal title or poll key for the matter being put to the partnership.') ?></th><th>Eligibility<?= ops_admin_help_button('Eligibility scope', 'The group that is allowed to vote on this poll. Use this to confirm whether the poll is all-partner or a narrower scope.') ?></th><th>Status<?= ops_admin_help_button('Poll status', 'Open and active require attention; closed and declared are historical; archived is reference-only.') ?></th><th>Voting closes<?= ops_admin_help_button('Voting closes', 'The time after which the open voting window ends.') ?></th></tr></thead>
      <tbody>
      <?php if (!$polls): ?><tr><td colspan="4" class="empty-note">No formal Partners Polls found.</td></tr><?php endif; ?>
      <?php foreach ($polls as $row): ?><tr>
        <td><?= ops_h($row['title'] ?? ($row['poll_key'] ?? '')) ?></td>
        <td><?= ops_h($row['eligibility_scope'] ?? 'all') ?></td>
        <td><?= gv_badge((string)($row['status'] ?? '')) ?></td>
        <td><?= ops_h((string)($row['voting_closes_at'] ?? '—')) ?></td>
      </tr><?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  </div>
</div>

<div class="row-grid">
  <div class="card">
  <div class="card-head"><h2 class="section-title">Proposal register<?= ops_admin_help_button('Proposal register', 'This is the initiation and tracking layer for governance matters. Use it to see whether a topic has been opened, what type of proposal it is, and whether it has already been linked to a formal poll.') ?></h2></div>
  <div class="card-body">
    <p class="muted small">Useful when tracing how a governance matter started or why a poll exists.</p>
    <div class="table-wrap"><table>
      <thead><tr><th>Proposal<?= ops_admin_help_button('Proposal key / title', 'The initiating or register record for the governance matter.') ?></th><th>Type<?= ops_admin_help_button('Proposal type', 'The class of matter being proposed, such as governance, stewardship, or another controlled action.') ?></th><th>Status<?= ops_admin_help_button('Proposal status', 'Use this to see whether the matter is still being formed, has moved into a formal poll, or is already closed.') ?></th><th>Linked poll<?= ops_admin_help_button('Linked poll', 'If present, this connects the register entry to the formal Partners Poll that carried the governance decision.') ?></th></tr></thead>
      <tbody>
      <?php if (!$proposals): ?><tr><td colspan="4" class="empty-note">No proposal register rows yet.</td></tr><?php endif; ?>
      <?php foreach ($proposals as $row): ?><tr>
        <td><?= ops_h($row['title'] ?? ($row['proposal_key'] ?? '')) ?></td>
        <td><?= ops_h($row['proposal_type'] ?? '') ?></td>
        <td><?= gv_badge((string)($row['status'] ?? '')) ?></td>
        <td><?= ops_h((string)($row['linked_poll_id'] ?? '—')) ?></td>
      </tr><?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  </div>
  <div class="card">
  <div class="card-head"><h2 class="section-title">Governance directions<?= ops_admin_help_button('Governance directions', 'Directions are the operational instructions that follow a governance outcome. Use this section after reviewing polls to see whether action has been created, what subject it targets, and whether the direction is still pending or already executed.') ?></h2></div>
  <div class="card-body">
    <p class="muted small">This section connects governance outcomes to operational follow-through.</p>
    <div class="table-wrap"><table>
      <thead><tr><th>Direction<?= ops_admin_help_button('Direction key', 'The internal identifier for the governance instruction.') ?></th><th>Type<?= ops_admin_help_button('Direction type', 'The kind of instruction created after governance has spoken.') ?></th><th>Subject<?= ops_admin_help_button('Subject', 'The object the direction applies to, such as a zone, poll, asset, or other governed record.') ?></th><th>Status<?= ops_admin_help_button('Direction status', 'Pending means action is still required. Executed or closed means the direction has already been carried through.') ?></th></tr></thead>
      <tbody>
      <?php if (!$directions): ?><tr><td colspan="4" class="empty-note">No governance directions yet.</td></tr><?php endif; ?>
      <?php foreach ($directions as $row): ?><tr>
        <td class="code"><?= ops_h($row['direction_key'] ?? '') ?></td>
        <td><?= ops_h($row['direction_type'] ?? '') ?></td>
        <td><?= ops_h(trim((string)($row['subject_type'] ?? '')) . (isset($row['subject_id']) ? (' #' . $row['subject_id']) : '')) ?></td>
        <td><?= gv_badge((string)($row['status'] ?? '')) ?></td>
      </tr><?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  </div>
</div>

<div class="row-grid">
  <div class="card">
  <div class="card-head"><h2 class="section-title">Board execution records<?= ops_admin_help_button('Board execution records', 'These records show how a governance direction was actually carried out by the board or operator layer. Use them to verify that a direction progressed from instruction to action.') ?></h2></div>
  <div class="card-body">
    <p class="muted small">Use this alongside Governance directions when confirming that a result was acted on.</p>
    <div class="table-wrap"><table>
      <thead><tr><th>Direction<?= ops_admin_help_button('Direction reference', 'The governance direction that this execution record belongs to.') ?></th><th>Execution type<?= ops_admin_help_button('Execution type', 'The kind of carry-through action performed, such as multisig, publication, distribution, or proxy submission.') ?></th><th>Reference<?= ops_admin_help_button('Execution reference', 'Any operator reference, external identifier, or trace value captured for the action.') ?></th><th>Executed at<?= ops_admin_help_button('Executed at', 'The recorded time that the carry-through step was performed.') ?></th></tr></thead>
      <tbody>
      <?php if (!$executions): ?><tr><td colspan="4" class="empty-note">No board execution rows yet.</td></tr><?php endif; ?>
      <?php foreach ($executions as $row): ?><tr>
        <td class="code"><?= ops_h((string)($row['direction_key'] ?? '—')) ?></td>
        <td><?= ops_h($row['execution_type'] ?? '') ?></td>
        <td><?= ops_h($row['execution_reference'] ?? '—') ?></td>
        <td><?= ops_h((string)($row['executed_at'] ?? '—')) ?></td>
      </tr><?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  </div>
  <div class="card">
  <div class="card-head"><h2 class="section-title">Proxy vote instructions<?= ops_admin_help_button('Proxy vote instructions', 'These are stewardship instructions for CHESS-held portfolio positions. Use this section when a governance outcome affects proxy voting or ESG direction at a portfolio company AGM/EGM.') ?></h2></div>
  <div class="card-body">
    <p class="muted small">This is the governance-to-stewardship bridge for investment voting decisions.</p>
    <div class="table-wrap"><table>
      <thead><tr><th>Ticker<?= ops_admin_help_button('Ticker', 'The ASX/security code for the company whose meeting or resolution is being instructed.') ?></th><th>Resolution<?= ops_admin_help_button('Resolution reference', 'The specific AGM/EGM resolution or event reference.') ?></th><th>Choice<?= ops_admin_help_button('Instruction choice', 'The direction to be cast or submitted for that resolution.') ?></th><th>Status<?= ops_admin_help_button('Instruction status', 'Draft and directed usually still need handling. Submitted or confirmed means the proxy instruction has moved forward.') ?></th></tr></thead>
      <tbody>
      <?php if (!$proxy): ?><tr><td colspan="4" class="empty-note">No proxy instruction rows yet.</td></tr><?php endif; ?>
      <?php foreach ($proxy as $row): ?><tr>
        <td class="code"><?= ops_h($row['ticker'] ?? '') ?></td>
        <td><?= ops_h($row['resolution_ref'] ?? '—') ?></td>
        <td><?= ops_h($row['instruction_choice'] ?? '') ?></td>
        <td><?= gv_badge((string)($row['status'] ?? '')) ?></td>
      </tr><?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  </div>
</div>

<div class="card">
  <div class="card-head"><h2 class="section-title">Vote snapshots<?= ops_admin_help_button('Vote snapshots', 'Vote snapshots are the certifiable result records that lock who could vote and what the final recorded outcome was at the relevant time. Use them for evidence, audits, disputes, and legal traceability.') ?></h2></div>
  <div class="card-body">
  <p class="muted small">These are the certifiable result records that support the live governance evidence path. Legacy wallet-poll references remain visible only as bridge trace data until retirement is complete.</p>
  <div class="table-wrap"><table>
    <thead><tr><th>Snapshot key<?= ops_admin_help_button('Snapshot key', 'The unique identifier for the certified vote snapshot record.') ?></th><th>Partners Poll<?= ops_admin_help_button('Partners Poll', 'The formal poll that this snapshot belongs to, if any.') ?></th><th>Legacy wallet poll<?= ops_admin_help_button('Legacy wallet poll', 'Only a bridge trace field. Do not rely on this as the primary live governance object.') ?></th><th>Certified at<?= ops_admin_help_button('Certified at', 'The time the snapshot was certified into the governance evidence trail.') ?></th></tr></thead>
    <tbody>
    <?php if (!$snapshots): ?><tr><td colspan="4" class="empty-note">No vote snapshots found.</td></tr><?php endif; ?>
    <?php foreach ($snapshots as $row): ?><tr>
      <td class="code"><?= ops_h($row['snapshot_key'] ?? '') ?></td>
      <td><?= ops_h((string)($row['community_poll_id'] ?? '—')) ?></td>
      <td><?= ops_h((string)($row['wallet_poll_id'] ?? '—')) ?></td>
      <td><?= ops_h((string)($row['certified_at'] ?? '—')) ?></td>
    </tr><?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<script>
/* ── Comment toggle ── */
function gvToggleComments(btn, pid){
  var list = document.getElementById('gv-cmt-'+pid);
  if(!list) return;
  var open = list.classList.toggle('open');
  btn.textContent = open
    ? btn.textContent.replace('Show','Hide')
    : btn.textContent.replace('Hide','Show');
}

/* ── Live tally refresh (30s) for open proposals ── */
(function(){
  var ROOT = document.body ? (document.body.dataset.root || '../') : '../';
  var API  = ROOT + '_app/api/index.php?route=vault/proposal-tallies';

  function pad(n){ return n < 10 ? '0'+n : n; }
  function stamp(){
    var d = new Date();
    return pad(d.getHours())+':'+pad(d.getMinutes())+':'+pad(d.getSeconds());
  }
  function el(id){ return document.getElementById(id); }

  function applyTally(item){
    var pid   = item.proposal_id;
    var total = item.total_votes || 0;
    var tally = item.tally || [];
    tally.forEach(function(row){
      var lbl   = row.label;
      var votes = row.votes || 0;
      var pct   = total > 0 ? Math.round(votes/total*100) : 0;
      var fill  = el('gv-fill-'+pid+'-'+lbl);
      var count = el('gv-count-'+pid+'-'+lbl);
      if(fill)  fill.style.width = pct+'%';
      if(count) count.textContent = votes+' ('+pct+'%)';
    });
    var totEl = el('gv-total-'+pid);
    if(totEl) totEl.textContent = total+' response'+(total!==1?'s':'');
  }

  function refresh(){
    fetch(API, {credentials:'include'})
      .then(function(r){ return r.json(); })
      .then(function(j){
        var tallies = (j.data||j).tallies || [];
        tallies.forEach(applyTally);
        var ts = el('gv-tally-ts');
        if(ts) ts.textContent = 'Last updated '+stamp();
      })
      .catch(function(){}); // silent — admin page still works without live data
  }

  // Only start polling if there are open proposal cards on the page
  if(document.querySelector('.gv-proposal-card.status-open')){
    setTimeout(refresh, 2000);   // initial refresh 2s after load
    setInterval(refresh, 30000); // then every 30s
  }
})();
</script>

<?php $body = ob_get_clean(); ops_render_page('Governance', 'governance', $body); ?>
