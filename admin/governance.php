<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/ops_workflow.php';
ops_require_admin();
$pdo = ops_db();
$labels = ops_label_settings($pdo);
$partnerLabel = $labels['public_label_partner'] ?? 'Member';

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

/* ── Governance machinery ── */
$controls   = ops_has_table($pdo, 'v_phase1_governance_control_status') ? gv_rows($pdo, 'SELECT * FROM v_phase1_governance_control_status ORDER BY exception_count DESC, evidence_link_count DESC, control_key ASC LIMIT 100') : [];
$polls      = ops_has_table($pdo, 'community_polls') ? gv_rows($pdo, 'SELECT * FROM community_polls ORDER BY id DESC LIMIT 50') : [];
$proposals  = ops_has_table($pdo, 'proposal_register') ? gv_rows($pdo, 'SELECT * FROM proposal_register ORDER BY id DESC LIMIT 50') : [];
$directions = ops_has_table($pdo, 'governance_directions') ? gv_rows($pdo, 'SELECT * FROM governance_directions ORDER BY id DESC LIMIT 50') : [];
$executions = ops_has_table($pdo, 'board_execution_records') ? gv_rows($pdo, 'SELECT ber.*, gd.direction_key FROM board_execution_records ber LEFT JOIN governance_directions gd ON gd.id = ber.governance_direction_id ORDER BY ber.id DESC LIMIT 50') : [];
$proxy      = ops_has_table($pdo, 'proxy_vote_instructions') ? gv_rows($pdo, 'SELECT * FROM proxy_vote_instructions ORDER BY id DESC LIMIT 50') : [];
$snapshots  = ops_has_table($pdo, 'vote_snapshots') ? gv_rows($pdo, 'SELECT * FROM vote_snapshots ORDER BY id DESC LIMIT 50') : [];
$legacyProposalCount = ops_has_table($pdo, 'vote_proposals') ? gv_val($pdo, 'SELECT COUNT(*) FROM vote_proposals') : 0;
$legacyPollCount     = ops_has_table($pdo, 'wallet_polls')   ? gv_val($pdo, 'SELECT COUNT(*) FROM wallet_polls')   : 0;

/* ── Live vote_proposals with tallies + comments ── */
$voteProposals = [];
if (ops_has_table($pdo, 'vote_proposals')) {
    $vpRows = gv_rows($pdo, 'SELECT id, title, summary, status, proposal_type, audience_scope, closes_at, created_at FROM vote_proposals ORDER BY id DESC LIMIT 30');
    if ($vpRows) {
        $vpIds = array_map(fn($r) => (int)$r['id'], $vpRows);
        $ph    = implode(',', array_fill(0, count($vpIds), '?'));

        // Tallies
        $tallyMap = [];
        try {
            $tRows = ops_fetch_all($pdo,
                "SELECT proposal_id, response_value, COUNT(*) AS votes
                 FROM vote_proposal_responses WHERE proposal_id IN ({$ph})
                 GROUP BY proposal_id, response_value", $vpIds);
            foreach ($tRows as $r) {
                $tallyMap[(int)$r['proposal_id']][(string)$r['response_value']] = (int)$r['votes'];
            }
        } catch (Throwable) {}

        // Comment counts + latest 5 per proposal
        $commentMap = [];
        if (ops_has_table($pdo, 'proposal_comments')) {
            try {
                $cRows = ops_fetch_all($pdo,
                    "SELECT proposal_id, COUNT(*) AS cnt FROM proposal_comments
                     WHERE proposal_id IN ({$ph}) GROUP BY proposal_id", $vpIds);
                foreach ($cRows as $r) {
                    $commentMap[(int)$r['proposal_id']]['count'] = (int)$r['cnt'];
                }
                // Latest comments per proposal (inline LIMIT via PHP)
                $allComments = ops_fetch_all($pdo,
                    "SELECT proposal_id, comment_text, submitted_at
                     FROM proposal_comments WHERE proposal_id IN ({$ph})
                     ORDER BY proposal_id, submitted_at DESC", $vpIds);
                $perProp = [];
                foreach ($allComments as $c) {
                    $pid = (int)$c['proposal_id'];
                    if (!isset($perProp[$pid])) $perProp[$pid] = [];
                    if (count($perProp[$pid]) < 5) $perProp[$pid][] = $c;
                }
                foreach ($perProp as $pid => $coms) {
                    $commentMap[$pid]['latest'] = $coms;
                }
            } catch (Throwable) {}
        }

        foreach ($vpRows as $vp) {
            $pid    = (int)$vp['id'];
            $counts = $tallyMap[$pid] ?? [];
            $total  = array_sum($counts);
            $voteProposals[] = $vp + [
                'tally'         => [
                    'yes'   => (int)($counts['yes']   ?? 0),
                    'maybe' => (int)($counts['maybe'] ?? 0),
                    'no'    => (int)($counts['no']    ?? 0),
                ],
                'total_votes'   => $total,
                'comment_count' => (int)($commentMap[$pid]['count'] ?? 0),
                'comments'      => $commentMap[$pid]['latest'] ?? [],
            ];
        }
    }
}

/* ── wallet_polls with vote breakdown ── */
$walletPolls = [];
if (ops_has_table($pdo, 'wallet_polls')) {
    $wpRows = gv_rows($pdo,
        'SELECT wp.id, wp.title, wp.poll_type, wp.audience_scope, wp.status,
                wp.opens_at, wp.closes_at, wp.certified_at, wp.result_summary,
                wp.quorum_required_count, wp.ballot_schema,
                cp.quorum_reached, cp.quorum_required_count AS cp_quorum
         FROM wallet_polls wp
         LEFT JOIN community_polls cp ON cp.id = wp.community_poll_id
         ORDER BY wp.id DESC LIMIT 30');
    if ($wpRows && ops_has_table($pdo, 'wallet_poll_votes')) {
        $wpIds = array_map(fn($r) => (int)$r['id'], $wpRows);
        $ph2   = implode(',', array_fill(0, count($wpIds), '?'));
        $voteBreakMap = [];
        try {
            $vRows = ops_fetch_all($pdo,
                "SELECT poll_id, choice_code, COUNT(*) AS votes
                 FROM wallet_poll_votes WHERE poll_id IN ({$ph2})
                 GROUP BY poll_id, choice_code", $wpIds);
            foreach ($vRows as $r) {
                $voteBreakMap[(int)$r['poll_id']][(string)$r['choice_code']] = (int)$r['votes'];
            }
        } catch (Throwable) {}
        foreach ($wpRows as $wp) {
            $pid    = (int)$wp['id'];
            $counts = $voteBreakMap[$pid] ?? [];
            $total  = array_sum($counts);
            $walletPolls[] = $wp + ['vote_breakdown' => $counts, 'total_votes' => $total];
        }
    } else {
        foreach ($wpRows as $wp) {
            $walletPolls[] = $wp + ['vote_breakdown' => [], 'total_votes' => 0];
        }
    }
}

$stats = [
    'Control rows'      => count($controls),
    'Members polls'    => count($polls),
    'Proposal register' => count($proposals),
    'Directions'        => count($directions),
    'Execution records' => count($executions),
    'Vote snapshots'    => count($snapshots),
];

/* ── Helpers ── */
function gv_bar(string $id, int $votes, int $total, string $colour, string $label, bool $mine = false): string {
    $pct = $total > 0 ? round(($votes / $total) * 100) : 0;
    return '<div class="gv-bar-row">'
        . '<span class="gv-bar-lbl" style="color:' . $colour . '">' . ($mine ? '✓ ' : '') . ops_h($label) . '</span>'
        . '<div class="gv-bar-track"><div class="gv-bar-fill" id="gvf-' . ops_h($id) . '" style="width:' . $pct . '%;background:' . $colour . '"></div></div>'
        . '<span class="gv-bar-pct" id="gvp-' . ops_h($id) . '">' . $votes . ' (' . $pct . '%)</span>'
        . '</div>';
}

ob_start(); ?>
<?php ops_admin_help_assets_once(); ?>
<style>
.badge-open   { background:rgba(34,197,94,.14);  color:#90f0b1; border:1px solid rgba(34,197,94,.25); }
.badge-closed { background:rgba(148,163,184,.16); color:#d5dbe4; border:1px solid rgba(148,163,184,.2); }
.badge-draft  { background:rgba(212,178,92,.15);  color:var(--gold); border:1px solid rgba(212,178,92,.2); }
.row-grid     { display:grid; grid-template-columns:1fr 1fr; gap:18px }
.bridge-note  { padding:14px 16px; border-radius:14px; background:rgba(212,178,92,.08); border:1px solid rgba(212,178,92,.2); margin-bottom:18px; font-size:.88rem; line-height:1.6 }
.section-title{ display:flex; align-items:center; gap:6px; flex-wrap:wrap; margin:0 }
/* ── Tally bars ── */
.gv-bar-row   { display:flex; align-items:center; gap:10px; margin-bottom:7px }
.gv-bar-lbl   { font-size:.82rem; font-weight:600; min-width:52px }
.gv-bar-track { flex:1; height:7px; background:rgba(255,255,255,.07); border-radius:99px; overflow:hidden }
.gv-bar-fill  { height:100%; border-radius:99px; transition:width .5s ease }
.gv-bar-pct   { font-size:.8rem; color:var(--sub); min-width:68px; text-align:right; font-family:monospace }
.gv-total     { font-size:.8rem; color:var(--dim); margin-top:4px }
/* ── Proposal cards ── */
.vp-card      { border:1px solid var(--line); border-radius:12px; padding:16px 18px; margin-bottom:14px; background:var(--panel2) }
.vp-card:last-child { margin-bottom:0 }
.vp-title     { font-size:.97rem; font-weight:600; color:var(--text); margin-bottom:4px }
.vp-meta      { font-size:.8rem; color:var(--dim); margin-bottom:12px; display:flex; gap:12px; flex-wrap:wrap; align-items:center }
.vp-comments  { margin-top:12px; padding-top:12px; border-top:1px solid var(--line) }
.vp-cmt-hd    { font-size:.8rem; font-weight:600; color:var(--sub); margin-bottom:8px }
.vp-cmt-item  { padding:8px 10px; border-radius:8px; background:rgba(255,255,255,.03); border:1px solid var(--line); margin-bottom:6px }
.vp-cmt-text  { font-size:.85rem; color:var(--text); line-height:1.5 }
.vp-cmt-date  { font-size:.75rem; color:var(--dim); margin-top:3px; font-family:monospace }
.vp-no-cmt    { font-size:.82rem; color:var(--dim); font-style:italic }
/* ── Poll cards ── */
.wp-card      { border:1px solid var(--line); border-radius:12px; padding:16px 18px; margin-bottom:14px; background:var(--panel2) }
.wp-card:last-child { margin-bottom:0 }
.wp-title     { font-size:.97rem; font-weight:600; color:var(--text); margin-bottom:4px }
.wp-meta      { font-size:.8rem; color:var(--dim); margin-bottom:12px; display:flex; gap:12px; flex-wrap:wrap; align-items:center }
.wp-result    { margin-top:10px; padding:10px 12px; border-radius:8px; background:rgba(212,178,92,.07); border:1px solid rgba(212,178,92,.18); font-size:.83rem; color:var(--gold) }
/* ── Live badge ── */
.live-dot     { display:inline-block; width:7px; height:7px; border-radius:50%; background:#52b87a; margin-right:5px; animation:pulse-dot 2s infinite }
@keyframes pulse-dot { 0%,100%{opacity:1} 50%{opacity:.35} }
.refresh-ts   { font-size:.75rem; color:var(--dim); margin-left:8px }
@media(max-width:900px){ .row-grid{ grid-template-columns:1fr 1fr } }
@media(max-width:640px){ .row-grid,.stat-grid{ grid-template-columns:1fr } }
</style>

<?= ops_admin_collapsible_help('Page guide & workflow', [
  ops_admin_info_panel('Stage 6 · Governance', 'What this page does', 'Governance is the authoritative operator page for formal poll records, the proposal register, governance directions, proxy-vote instructions, board execution records, and certified vote snapshots.', [
    'These are the formal governance instruments. When someone asks what the joint venture has voted on are the formal governance instrument — rely on these when checking live governance state.',
    'The proposal register shows initiation and pre-poll objects. It is not the final voting record by itself.',
    'Governance directions show what operational instruction should be carried out after governance has spoken.',
    'Proxy instructions and vote snapshots are evidence objects that support the live governance trail.',
  ]),
  ops_admin_workflow_panel('Typical workflow', 'Governance moves through a clear sequence from initiation to evidence.', [
    ['title' => 'Proposal / initiation', 'body' => 'A governance topic is opened and tracked in the proposal register.'],
    ['title' => 'Formal poll', 'body' => 'The matter becomes a Members Poll and the eligible voting group is opened.'],
    ['title' => 'Result / direction', 'body' => 'Once the result is declared, a governance direction may be created for operational follow-through.'],
    ['title' => 'Execution / proxy / publication', 'body' => 'The board execution record, proxy instruction, or downstream control action shows how the decision was carried out.'],
    ['title' => 'Snapshot / evidence', 'body' => 'Vote snapshots and governance-control evidence preserve the auditable record of what happened.'],
  ]),
  ops_admin_guide_panel('How to use this page', 'Each section answers a different operator question.', [
    ['title' => 'Vote proposals — live tally', 'body' => 'This is the real-time community consultation surface. Bars update every 30 seconds. Comments are anonymous and shown in full. Open proposals show live response counts.'],
    ['title' => 'These are the formal governance instruments. When someone asks what the joint venture has voted on — binding vote results', 'body' => 'These are formal binding votes. Each poll shows a full breakdown of how Members voted on each option.'],
    ['title' => 'Governance control status', 'body' => 'Use this to see whether any governance controls are missing evidence or carrying open exceptions.'],
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
    <p class="muted small" style="margin:0">Start with live proposal tallies and poll results, then review governance control status and follow any resulting directions and evidence objects.</p>
  </div>
</div>

<div class="stat-grid">
  <?php foreach ($stats as $label => $value): ?>
    <div class="card"><div class="card-body"><div class="stat-label"><?= ops_h($label) ?></div><div class="stat-value"><?= (int)$value ?></div></div></div>
  <?php endforeach; ?>
</div>

<!-- ══ SECTION 1: VOTE PROPOSALS — LIVE TALLY ══ -->
<div class="card">
  <div class="card-head" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <h2 class="section-title">
      <span class="live-dot"></span>
      Vote proposals — live response tally
      <?= ops_admin_help_button('Vote proposals', 'Community consultation proposals (Yes / Maybe / No). Tallies refresh every 30 seconds. Comments are anonymous — no member ID is stored. Use these to gauge community sentiment before a binding poll.') ?>
    </h2>
    <span class="refresh-ts" id="gv-refresh-ts"></span>
  </div>
  <div class="card-body" id="gv-proposals-body">
    <?php if (!$voteProposals): ?>
      <p class="muted small">No vote proposals found.</p>
    <?php endif; ?>
    <?php foreach ($voteProposals as $vp):
      $pid   = (int)$vp['id'];
      $total = (int)$vp['total_votes'];
      $t     = $vp['tally'];
    ?>
    <div class="vp-card" id="vpc-<?= $pid ?>">
      <div class="vp-title"><?= ops_h($vp['title']) ?></div>
      <div class="vp-meta">
        <?= gv_badge((string)$vp['status']) ?>
        <span><?= ops_h(ucfirst(str_replace('_',' ',$vp['proposal_type'] ?? ''))) ?></span>
        <?php if ($vp['closes_at']): ?><span>Closes <?= ops_h($vp['closes_at']) ?></span><?php endif; ?>
        <span><?= ops_h(ucfirst($vp['audience_scope'] ?? 'all')) ?> audience</span>
      </div>
      <?php if ($vp['summary']): ?>
        <p style="font-size:.85rem;color:var(--sub);margin:0 0 12px;line-height:1.5"><?= ops_h($vp['summary']) ?></p>
      <?php endif; ?>
      <!-- Tally bars -->
      <?= gv_bar("vp{$pid}-yes",   $t['yes'],   $total, '#52b87a', 'Yes') ?>
      <?= gv_bar("vp{$pid}-maybe", $t['maybe'], $total, '#c8973e', 'Maybe') ?>
      <?= gv_bar("vp{$pid}-no",    $t['no'],    $total, '#c44061', 'No') ?>
      <div class="gv-total" id="gvt-<?= $pid ?>">
        <?= $total ?> response<?= $total !== 1 ? 's' : '' ?>
        · <?= (int)$vp['comment_count'] ?> comment<?= (int)$vp['comment_count'] !== 1 ? 's' : '' ?>
      </div>
      <!-- Comments -->
      <div class="vp-comments">
        <div class="vp-cmt-hd">💬 Anonymous comments (<?= (int)$vp['comment_count'] ?>)</div>
        <?php if (!$vp['comments']): ?>
          <div class="vp-no-cmt">No comments yet.</div>
        <?php else: ?>
          <?php foreach ($vp['comments'] as $c): ?>
            <div class="vp-cmt-item">
              <div class="vp-cmt-text"><?= ops_h($c['comment_text']) ?></div>
              <div class="vp-cmt-date"><?= ops_h($c['submitted_at']) ?></div>
            </div>
          <?php endforeach; ?>
          <?php if ((int)$vp['comment_count'] > 5): ?>
            <div class="vp-no-cmt"><?= (int)$vp['comment_count'] - 5 ?> more comment<?= ((int)$vp['comment_count'] - 5) !== 1 ? 's' : '' ?> not shown.</div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ══ SECTION 2: MEMBERS POLLS — BINDING VOTE RESULTS ══ -->
<div class="card" style="margin-top:18px">
  <div class="card-head">
    <h2 class="section-title">
      These are the formal governance instruments. When someone asks what the joint venture has voted on — binding vote results
      <?= ops_admin_help_button('These are the formal governance instruments. When someone asks what the joint venture has voted on binding', 'Formal binding votes cast by Members through their Independence Vault Wallet. Each option shows vote count and percentage. Closed and certified polls show result summary.') ?>
    </h2>
  </div>
  <div class="card-body">
    <?php if (!$walletPolls): ?>
      <p class="muted small">No These are the formal governance instruments. When someone asks what the joint venture has voted on found.</p>
    <?php endif; ?>
    <?php foreach ($walletPolls as $wp):
      $wpid  = (int)$wp['id'];
      $total = (int)$wp['total_votes'];
      $breakdown = $wp['vote_breakdown'];
      arsort($breakdown);
      $resultJson = null;
      if (!empty($wp['result_summary'])) {
          try { $resultJson = json_decode($wp['result_summary'], true); } catch (Throwable) {}
      }
    ?>
    <div class="wp-card">
      <div class="wp-title"><?= ops_h($wp['title']) ?></div>
      <div class="wp-meta">
        <?= gv_badge((string)$wp['status']) ?>
        <span><?= ops_h(ucfirst(str_replace('_',' ',$wp['poll_type'] ?? ''))) ?></span>
        <span><?= ops_h(ucfirst($wp['audience_scope'] ?? 'all')) ?> scope</span>
        <?php if ($wp['opens_at']): ?><span>Opens <?= ops_h($wp['opens_at']) ?></span><?php endif; ?>
        <?php if ($wp['closes_at']): ?><span>Closes <?= ops_h($wp['closes_at']) ?></span><?php endif; ?>
        <?php if ($wp['certified_at']): ?><span>Certified <?= ops_h($wp['certified_at']) ?></span><?php endif; ?>
      </div>
      <?php if ($breakdown): ?>
        <?php
        $colours = ['yes'=>'#52b87a','no'=>'#c44061','maybe'=>'#c8973e','abstain'=>'#6b7f8f'];
        $i = 0; $palette = ['#52b87a','#c8973e','#c44061','#6b7f8f','#7eb8d4','#b87ab2'];
        foreach ($breakdown as $choice => $votes):
            $col = $colours[strtolower($choice)] ?? $palette[$i % count($palette)];
            $i++;
        ?>
          <?= gv_bar("wp{$wpid}-" . $i, $votes, $total, $col, ucfirst($choice)) ?>
        <?php endforeach; ?>
        <div class="gv-total"><?= $total ?> vote<?= $total !== 1 ? 's' : '' ?> cast</div>
        <?php
        $quorum = (int)($wp['quorum_required_count'] ?? $wp['cp_quorum'] ?? 0);
        $reached = (bool)($wp['quorum_reached'] ?? false);
        if ($quorum > 0): ?>
          <div style="font-size:.8rem;margin-top:4px;color:<?= $reached ? '#52b87a' : 'var(--gold)' ?>">
            Quorum: <?= $quorum ?> required — <?= $reached ? '✓ reached' : 'not yet reached' ?>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <div class="gv-total" style="font-style:italic">No votes recorded yet.</div>
      <?php endif; ?>
      <?php if ($resultJson): ?>
        <div class="wp-result">
          <strong>Result:</strong>
          <?= ops_h(is_string($resultJson) ? $resultJson : (isset($resultJson['outcome']) ? $resultJson['outcome'] : json_encode($resultJson))) ?>
        </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="bridge-note" style="margin-top:18px">
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
  <div class="card-head"><h2 class="section-title">These are the formal governance instruments. When someone asks what the joint venture has voted on<?= ops_admin_help_button('These are the formal governance instruments. When someone asks what the joint venture has voted on', 'These are the formal governance instruments. When someone asks what the joint venture has voted on, this is the section to use first. Focus on title, eligibility scope, status, and voting close time.') ?></h2></div>
  <div class="card-body">
    <p class="muted small">Formal poll records only. These are the authoritative governance objects, not general discussion or consultation threads.</p>
    <div class="table-wrap"><table>
      <thead><tr><th>Poll<?= ops_admin_help_button('Poll title', 'The formal title or poll key for the matter being put to the joint venture.') ?></th><th>Eligibility<?= ops_admin_help_button('Eligibility scope', 'The group that is allowed to vote on this poll. Use this to confirm whether the poll is all-member or a narrower scope.') ?></th><th>Status<?= ops_admin_help_button('Poll status', 'Open and active require attention; closed and declared are historical; archived is reference-only.') ?></th><th>Voting closes<?= ops_admin_help_button('Voting closes', 'The time after which the open voting window ends.') ?></th></tr></thead>
      <tbody>
      <?php if (!$polls): ?><tr><td colspan="4" class="empty-note">No formal These are the formal governance instruments. When someone asks what the joint venture has voted on found.</td></tr><?php endif; ?>
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
      <thead><tr><th>Proposal<?= ops_admin_help_button('Proposal key / title', 'The initiating or register record for the governance matter.') ?></th><th>Type<?= ops_admin_help_button('Proposal type', 'The class of matter being proposed, such as governance, stewardship, or another controlled action.') ?></th><th>Status<?= ops_admin_help_button('Proposal status', 'Use this to see whether the matter is still being formed, has moved into a formal poll, or is already closed.') ?></th><th>Linked poll<?= ops_admin_help_button('Linked poll', 'If present, this connects the register entry to the formal Members Poll that carried the governance decision.') ?></th></tr></thead>
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
    <thead><tr><th>Snapshot key<?= ops_admin_help_button('Snapshot key', 'The unique identifier for the certified vote snapshot record.') ?></th><th>Members Poll<?= ops_admin_help_button('Members Poll', 'The formal poll that this snapshot belongs to, if any.') ?></th><th>Legacy wallet poll<?= ops_admin_help_button('Legacy wallet poll', 'Only a bridge trace field. Do not rely on this as the primary live governance object.') ?></th><th>Certified at<?= ops_admin_help_button('Certified at', 'The time the snapshot was certified into the governance evidence trail.') ?></th></tr></thead>
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
</div>

<!-- ══ LIVE TALLY REFRESH SCRIPT ══ -->
<script>
(function(){
  var ROOT = (document.querySelector('base')||{href:''}).href || window.location.origin + '/';
  // Derive API base from current page path
  var pathParts = window.location.pathname.split('/');
  var adminIdx  = pathParts.lastIndexOf('admin');
  var apiBase   = adminIdx >= 0
    ? pathParts.slice(0, adminIdx).join('/') + '/_app/api/index.php?route='
    : '/_app/api/index.php?route=';

  function patchBars(proposalId, tally, totalVotes, commentCount){
    ['yes','maybe','no'].forEach(function(lbl){
      var votes = tally[lbl] || 0;
      var pct   = totalVotes > 0 ? Math.round((votes / totalVotes) * 100) : 0;
      var fill  = document.getElementById('gvf-vp'+proposalId+'-'+lbl);
      var pctEl = document.getElementById('gvp-vp'+proposalId+'-'+lbl);
      if(fill)  fill.style.width = pct + '%';
      if(pctEl) pctEl.textContent = votes + ' (' + pct + '%)';
    });
    var totEl = document.getElementById('gvt-'+proposalId);
    if(totEl) totEl.textContent = totalVotes + ' response' + (totalVotes !== 1 ? 's' : '')
      + ' · ' + commentCount + ' comment' + (commentCount !== 1 ? 's' : '');
  }

  function refreshTallies(){
    fetch(apiBase + 'vault/proposal-tallies', {credentials:'include'})
      .then(function(r){ return r.json(); })
      .then(function(json){
        var tallies = (json.data || json).tallies || [];
        tallies.forEach(function(item){
          var pid = item.proposal_id;
          // Convert array [{label,votes}] → dict {yes:N,...}
          var tallyDict = {};
          (item.tally||[]).forEach(function(t){ tallyDict[t.label] = t.votes; });
          patchBars(pid, tallyDict, item.total_votes, item.comment_count || 0);
        });
        var ts = document.getElementById('gv-refresh-ts');
        if(ts){
          var now = new Date();
          ts.textContent = 'Updated ' + now.getHours() + ':' + String(now.getMinutes()).padStart(2,'0') + ':' + String(now.getSeconds()).padStart(2,'0');
        }
      })
      .catch(function(){}); // silent — admin page still fully functional without live refresh
  }

  // Start polling every 30 seconds
  setInterval(refreshTallies, 30000);
  // Also refresh once after 3s on load (catches any votes cast since page rendered)
  setTimeout(refreshTallies, 3000);
})();
</script>

<?php $body = ob_get_clean(); ops_render_page('Governance', 'governance', $body); ?>
