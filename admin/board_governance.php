<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';

ops_require_admin();
$pdo = ops_db();

$canManage = ops_admin_can($pdo, 'admin.full') || ops_admin_can($pdo, 'governance_admin');

function bg_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function bg_rows(PDO $p, string $q, array $params = []): array {
    try { $s = $p->prepare($q); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    catch (Throwable $e) { return []; }
}
function bg_val(PDO $p, string $q, array $params = []): mixed {
    try { $s = $p->prepare($q); $s->execute($params); return $s->fetchColumn(); }
    catch (Throwable $e) { return null; }
}
function bg_badge(string $status): string {
    $class = match ($status) {
        'active','passed','elected','verified','executed','endorsed'  => 'badge-open',
        'resigned','removed','rejected','not_elected','failed','withdrawn' => 'badge-closed',
        default => 'badge-draft',
    };
    return '<span class="badge ' . $class . '">' . bg_h($status !== '' ? $status : '—') . '</span>';
}
function bg_seat_label(string $seat): string {
    return match ($seat) {
        '14.4(a)_community_adjacent'  => 'cl.14.4(a) Community-Adjacent',
        '14.4(b)_professional_expertise' => 'cl.14.4(b) Professional Expertise',
        '14.4(c)_fnac_nominated'      => 'cl.14.4(c) FNAC-Nominated ★ Entrenched',
        'at_large'                    => 'At-Large',
        default                       => bg_h($seat),
    };
}
function bg_phase_label(int $phase): string {
    return match ($phase) {
        1 => 'Phase 1 — Founding (3)',
        2 => 'Phase 2 — Sub-Committee Chairs (6)',
        3 => 'Phase 3 — At-Large (9)',
        default => "Phase {$phase}",
    };
}

$tablesReady = ops_has_table($pdo, 'board_directors')
            && ops_has_table($pdo, 'director_nominations')
            && ops_has_table($pdo, 'inaugural_meeting_resolutions');

$flash = null; $flashType = 'ok';
if (isset($_GET['flash'])) { $flash = (string)$_GET['flash']; $flashType = (string)($_GET['type'] ?? 'ok'); }

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage && $tablesReady) {
    if (function_exists('admin_csrf_verify')) { admin_csrf_verify(); }
    $action = trim((string)($_POST['action'] ?? ''));
    try {

        // ── Update director status ────────────────────────────────────────────
        if ($action === 'update_director_status') {
            $uuid       = trim((string)($_POST['director_uuid'] ?? ''));
            $newStatus  = trim((string)($_POST['new_status']    ?? ''));
            $note       = trim((string)($_POST['status_note']   ?? ''));
            $statusDate = trim((string)($_POST['status_date']   ?? date('Y-m-d')));
            if ($uuid === '') throw new RuntimeException('Director UUID missing.');
            $allowed = ['nominee','active','resigned','removed','term_expired'];
            if (!in_array($newStatus, $allowed, true)) throw new RuntimeException('Invalid status.');
            $pdo->prepare(
                'UPDATE board_directors SET status=?, status_date=?, status_note=?, updated_at=UTC_TIMESTAMP() WHERE uuid=?'
            )->execute([$newStatus, $statusDate, $note, $uuid]);
            $flash = 'Director status updated.'; $flashType = 'ok';
        }

        // ── Confirm undertaking signed ────────────────────────────────────────
        elseif ($action === 'confirm_undertaking') {
            $uuid = trim((string)($_POST['director_uuid'] ?? ''));
            if ($uuid === '') throw new RuntimeException('Director UUID missing.');
            $pdo->prepare(
                'UPDATE board_directors SET undertaking_signed_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP() WHERE uuid=? AND undertaking_signed_at IS NULL'
            )->execute([$uuid]);
            $flash = 'Director undertaking recorded.'; $flashType = 'ok';
        }

        // ── Confirm key ceremony completed ───────────────────────────────────
        elseif ($action === 'confirm_key_ceremony') {
            $uuid    = trim((string)($_POST['director_uuid'] ?? ''));
            $hsmStd  = trim((string)($_POST['hsm_standard']  ?? 'FIPS 140-3 Level 3'));
            if ($uuid === '') throw new RuntimeException('Director UUID missing.');
            $pdo->prepare(
                'UPDATE board_directors SET key_ceremony_completed_at=UTC_TIMESTAMP(), hsm_standard=?, updated_at=UTC_TIMESTAMP() WHERE uuid=? AND key_ceremony_completed_at IS NULL'
            )->execute([$hsmStd, $uuid]);
            $flash = 'Key generation ceremony recorded.'; $flashType = 'ok';
        }

        // ── Add director (manual pathway or Board-initiated) ──────────────────
        elseif ($action === 'add_director') {
            $fullName  = trim((string)($_POST['full_name']  ?? ''));
            $address   = trim((string)($_POST['address']    ?? ''));
            $seatType  = trim((string)($_POST['seat_type']  ?? ''));
            $phase     = (int)($_POST['phase']              ?? 1);
            $qualBasis = trim((string)($_POST['qualification_basis'] ?? ''));
            $apptRes   = trim((string)($_POST['appointing_resolution'] ?? ''));
            $memberNo  = trim((string)($_POST['member_number'] ?? ''));
            $termStart = trim((string)($_POST['term_start_date'] ?? ''));
            $termEnd   = trim((string)($_POST['term_end_date']   ?? ''));
            $subComm   = trim((string)($_POST['sub_committee']   ?? 'none'));
            $scRole    = trim((string)($_POST['sub_committee_role'] ?? 'none'));
            if ($fullName === '') throw new RuntimeException('Full name is required.');
            $allowed = ['14.4(a)_community_adjacent','14.4(b)_professional_expertise','14.4(c)_fnac_nominated','at_large'];
            if (!in_array($seatType, $allowed, true)) throw new RuntimeException('Invalid seat type.');
            $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
                mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
                mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
            $pdo->prepare(
                'INSERT INTO board_directors
                 (uuid, seat_type, phase, full_name, address, qualification_basis,
                  sub_committee, sub_committee_role, term_start_date, term_end_date,
                  status, appointing_resolution, member_number, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,"nominee",?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
            )->execute([$uuid,$seatType,$phase,$fullName,$address,$qualBasis,$subComm,$scRole,
                        $termStart ?: null, $termEnd ?: null, $apptRes, $memberNo]);
            $flash = 'Director record added.'; $flashType = 'ok';
        }

        // ── Update inaugural resolution status ───────────────────────────────
        elseif ($action === 'update_resolution') {
            $ref    = trim((string)($_POST['resolution_ref'] ?? ''));
            $status = trim((string)($_POST['res_status']     ?? ''));
            $vFor   = (int)($_POST['votes_for']    ?? 0);
            $vAgst  = (int)($_POST['votes_against'] ?? 0);
            $vAbs   = (int)($_POST['abstain']       ?? 0);
            $note   = trim((string)($_POST['implementation_note'] ?? ''));
            if ($ref === '') throw new RuntimeException('Resolution ref missing.');
            $declaredAt = ($status === 'passed' || $status === 'failed') ? 'UTC_TIMESTAMP()' : 'NULL';
            $pdo->prepare(
                "UPDATE inaugural_meeting_resolutions
                 SET status=?, votes_for=?, votes_against=?, abstain=?,
                     implementation_note=?,
                     declared_at=IF(status IN ('passed','failed'), COALESCE(declared_at, UTC_TIMESTAMP()), declared_at),
                     updated_at=UTC_TIMESTAMP()
                 WHERE resolution_ref=?"
            )->execute([$status,$vFor,$vAgst,$vAbs,$note,$ref]);
            $flash = 'Resolution updated.'; $flashType = 'ok';
        }

        // ── Add nomination ────────────────────────────────────────────────────
        elseif ($action === 'add_nomination') {
            $seatType   = trim((string)($_POST['nom_seat_type']    ?? ''));
            $source     = trim((string)($_POST['nom_source']       ?? 'board'));
            $nomineeName= trim((string)($_POST['nominee_full_name']?? ''));
            $nomAddr    = trim((string)($_POST['nominee_address']   ?? ''));
            $seatQual   = trim((string)($_POST['seat_qualification']?? ''));
            $statement  = trim((string)($_POST['candidate_statement']?? ''));
            $windowOpen = trim((string)($_POST['window_open_date'] ?? ''));
            $windowClose= trim((string)($_POST['window_close_date']?? ''));
            if ($nomineeName === '') throw new RuntimeException('Nominee name required.');
            $allowed = ['14.4(a)_community_adjacent','14.4(b)_professional_expertise','14.4(c)_fnac_nominated','at_large'];
            if (!in_array($seatType, $allowed, true)) throw new RuntimeException('Invalid seat type.');
            $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
                mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
                mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
            $pdo->prepare(
                'INSERT INTO director_nominations
                 (uuid, seat_type, nomination_source, nominee_full_name, nominee_address,
                  seat_qualification, candidate_statement, status, window_open_date, window_close_date,
                  created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,"submitted",?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
            )->execute([$uuid,$seatType,$source,$nomineeName,$nomAddr,$seatQual,$statement,
                        $windowOpen ?: null, $windowClose ?: null]);
            $flash = 'Nomination submitted.'; $flashType = 'ok';
        }

        header('Location: ' . admin_url('board_governance.php') . '?flash=' . urlencode($flash) . '&type=' . $flashType);
        exit;
    } catch (Throwable $e) {
        $flash = 'Error: ' . $e->getMessage(); $flashType = 'err';
    }
}

// ── Read data ─────────────────────────────────────────────────────────────────
$directors   = $tablesReady ? bg_rows($pdo, 'SELECT * FROM board_directors ORDER BY phase ASC, id ASC') : [];
$nominations = $tablesReady ? bg_rows($pdo, 'SELECT * FROM director_nominations ORDER BY id DESC LIMIT 50') : [];
$resolutions = $tablesReady ? bg_rows($pdo, 'SELECT * FROM inaugural_meeting_resolutions ORDER BY resolution_num ASC') : [];

$activeCount     = count(array_filter($directors, fn($d) => $d['status'] === 'active'));
$nomineeCount    = count(array_filter($directors, fn($d) => $d['status'] === 'nominee'));
$resPassed       = count(array_filter($resolutions, fn($r) => $r['status'] === 'passed'));
$resPending      = count(array_filter($resolutions, fn($r) => $r['status'] === 'pending'));
$undersignedUndertaking = array_filter($directors, fn($d) => in_array($d['status'],['active','nominee'],true) && empty($d['undertaking_signed_at']));
$underKeyCeremony       = array_filter($directors, fn($d) => in_array($d['status'],['active','nominee'],true) && empty($d['key_ceremony_completed_at']));

$csrfToken = function_exists('admin_csrf_token') ? admin_csrf_token() : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Board Governance | COG$ Admin</title>
<?php ops_admin_help_assets_once(); ?>
<style>
.main { padding:24px 28px; }
.topbar { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:26px; flex-wrap:wrap; }
.topbar h1 { font-size:1.9rem; font-weight:700; margin-bottom:6px; }
.topbar p { color:var(--sub); font-size:13px; max-width:580px; }
.btn { display:inline-block; padding:8px 16px; border-radius:10px; font-size:13px; font-weight:700; border:1px solid var(--line2); background:var(--panel2); color:var(--text); cursor:pointer; text-decoration:none; }
.btn-gold { background:rgba(212,178,92,.15); border-color:rgba(212,178,92,.3); color:var(--gold); }
.btn-sm { padding:5px 12px; font-size:12px; border-radius:8px; }
.btn-action { background:rgba(82,184,122,.15); border-color:rgba(82,184,122,.3); color:var(--ok); }
.btn-danger { background:rgba(240,80,80,.12); border-color:rgba(240,80,80,.3); color:#f05050; }
.card { background:linear-gradient(180deg,var(--panel),var(--panel2)); border:1px solid var(--line); border-radius:var(--r); overflow:hidden; margin-bottom:18px; }
.card-head { display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid var(--line); }
.card-head h2 { font-size:1rem; font-weight:700; }
.card-body { padding:16px 20px; }
.stat-row { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:20px; }
.stat { background:var(--panel2); border:1px solid var(--line2); border-radius:10px; padding:14px 20px; min-width:130px; text-align:center; }
.stat .n { font-size:2rem; font-weight:800; line-height:1; }
.stat .l { font-size:11px; color:var(--sub); margin-top:4px; }
.gold-n { color:var(--gold); }
.ok-n   { color:var(--ok); }
.warn-n { color:#e89a35; }
table.tbl { width:100%; border-collapse:collapse; font-size:13px; }
table.tbl th { padding:8px 10px; text-align:left; color:var(--sub); font-weight:600; border-bottom:1px solid var(--line); }
table.tbl td { padding:8px 10px; border-bottom:1px solid var(--line2); vertical-align:top; }
table.tbl tr:last-child td { border-bottom:none; }
.seat-tag { display:inline-block; padding:2px 8px; border-radius:6px; font-size:11px; font-weight:700; background:rgba(212,178,92,.12); color:var(--gold); border:1px solid rgba(212,178,92,.25); }
.seat-fnac { background:rgba(100,180,255,.12); color:#7cc4ff; border-color:rgba(100,180,255,.25); }
.seat-pro  { background:rgba(150,255,180,.12); color:#5fd98a; border-color:rgba(150,255,180,.25); }
.seat-alarge { background:rgba(200,160,255,.12); color:#c880ff; border-color:rgba(200,160,255,.25); }
.phase-tag { font-size:11px; color:var(--sub); }
.tick { color:var(--ok); font-weight:700; }
.cross { color:#f05050; }
.warn  { color:#e89a35; }
details summary { cursor:pointer; font-size:13px; color:var(--sub); padding:6px 0; }
details[open] summary { color:var(--text); }
.form-row { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:10px; }
.form-row label { display:flex; flex-direction:column; gap:4px; font-size:12px; font-weight:600; color:var(--sub); flex:1; min-width:160px; }
.form-row input, .form-row select, .form-row textarea {
    background:var(--panel2); border:1px solid var(--line2); border-radius:8px;
    color:var(--text); font-size:13px; padding:7px 10px;
}
.form-row textarea { min-height:80px; resize:vertical; }
.flash-ok  { background:rgba(82,184,122,.15); border:1px solid rgba(82,184,122,.35); color:var(--ok); border-radius:10px; padding:10px 16px; margin-bottom:16px; }
.flash-err { background:rgba(240,80,80,.12); border:1px solid rgba(240,80,80,.3); color:#f05050; border-radius:10px; padding:10px 16px; margin-bottom:16px; }
.install-notice { background:rgba(232,154,53,.12); border:1px solid rgba(232,154,53,.3); color:#e89a35; border-radius:10px; padding:14px 18px; margin-bottom:18px; font-size:13px; }
.res-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:12px; }
.res-card { background:var(--panel2); border:1px solid var(--line2); border-radius:10px; padding:14px 16px; }
.res-num { font-size:11px; font-weight:700; color:var(--sub); margin-bottom:4px; }
.res-title { font-size:13px; font-weight:700; margin-bottom:8px; }
.res-actions { margin-top:8px; display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.subcomm-tag { display:inline-block; padding:1px 7px; border-radius:5px; font-size:10px; font-weight:700;
    background:rgba(212,178,92,.12); color:var(--gold); border:1px solid rgba(212,178,92,.2); }
</style>
</head>
<body>
<div class="admin-shell">
<?php admin_sidebar_render('board_governance'); ?>
<main class="main">

<div class="topbar">
  <div>
    <h1>Board Governance<?php echo ops_admin_help_button('Board Governance', 'Foundation Board Director register, Director Nomination and Election Policy pipeline, and Inaugural Meeting resolution tracker. Declaration cl.14 (Board composition), cl.15 (Board powers and duties), cl.15A.4 (Key Management Policy), cl.33A (FNAC). Part 1 and Part 2 of the Board Governance Pack v1.0.'); ?></h1>
    <p>Director register · Nomination pipeline · Inaugural Meeting resolutions · Sub-Committee assignments</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn" href="<?php echo bg_h(admin_url('governance.php')); ?>">Governance</a>
    <a class="btn" href="<?php echo bg_h(admin_url('foundation_day.php')); ?>">Foundation Day</a>
    <a class="btn" href="<?php echo bg_h(admin_url('trustees.php')); ?>">Trustees Register</a>
    <a class="btn" href="<?php echo bg_h(admin_url('trustee_decisions.php')); ?>">Trustee Decisions</a>
  </div>
</div>

<?php if ($flash): ?>
<div class="flash-<?php echo $flashType === 'ok' ? 'ok' : 'err'; ?>"><?php echo bg_h($flash); ?></div>
<?php endif; ?>

<?php if (!$tablesReady): ?>
<div class="install-notice">
  <strong>⚠ Database tables not yet installed.</strong> Run <code>board_governance.sql</code> via phpMyAdmin against <code>cogsaust_TRUST</code>, then reload this page.
</div>
<?php else: ?>

<!-- ── Summary stats ─────────────────────────────────────────────────────── -->
<div class="stat-row">
  <div class="stat"><div class="n gold-n"><?php echo count($directors); ?></div><div class="l">Total Directors</div></div>
  <div class="stat"><div class="n ok-n"><?php echo $activeCount; ?></div><div class="l">Active</div></div>
  <div class="stat"><div class="n warn-n"><?php echo $nomineeCount; ?></div><div class="l">Nominees</div></div>
  <div class="stat"><div class="n ok-n"><?php echo $resPassed; ?></div><div class="l">Resolutions Passed</div></div>
  <div class="stat"><div class="n warn-n"><?php echo $resPending; ?></div><div class="l">Resolutions Pending</div></div>
  <div class="stat"><div class="n <?php echo count($undersignedUndertaking) ? 'warn-n' : 'ok-n'; ?>"><?php echo count($undersignedUndertaking); ?></div><div class="l">Undertakings Pending</div></div>
  <div class="stat"><div class="n <?php echo count($underKeyCeremony) ? 'warn-n' : 'ok-n'; ?>"><?php echo count($underKeyCeremony); ?></div><div class="l">Key Ceremonies Pending</div></div>
</div>

<!-- ── Inaugural Meeting Resolutions ─────────────────────────────────────── -->
<div class="card">
  <div class="card-head">
    <h2>Inaugural Meeting Resolutions<?php echo ops_admin_help_button('Inaugural Meeting Resolutions', 'Eight resolutions under Declaration cl.1.7.5. The Inaugural Meeting must be held before Expansion Day under cl.12B.3(a). Requires a quorum of ≥10 S-NFT holder Members under JVPA cl.6.5(d). Governance Foundation Day (14 May 2026) is the target date subject to the 10-Member floor being met.'); ?></h2>
    <span class="phase-tag"><?php echo $resPassed; ?>/<?php echo count($resolutions); ?> passed</span>
  </div>
  <div class="card-body">
    <div class="res-grid">
    <?php foreach ($resolutions as $res): ?>
      <div class="res-card">
        <div class="res-num">Resolution <?php echo (int)$res['resolution_num']; ?> — <?php echo bg_h($res['resolution_ref']); ?></div>
        <div class="res-title"><?php echo bg_h($res['title']); ?></div>
        <div><?php echo bg_badge($res['status']); ?>
          <?php if ($res['votes_for'] > 0 || $res['votes_against'] > 0): ?>
            <span style="font-size:11px;color:var(--sub);margin-left:8px">
              ✓ <?php echo (int)$res['votes_for']; ?> / ✗ <?php echo (int)$res['votes_against']; ?> / ~ <?php echo (int)$res['abstain']; ?>
            </span>
          <?php endif; ?>
        </div>
        <?php if ($res['declared_at']): ?>
          <div style="font-size:11px;color:var(--sub);margin-top:4px">Declared <?php echo bg_h(substr($res['declared_at'],0,10)); ?></div>
        <?php endif; ?>
        <?php if ($res['implementation_note']): ?>
          <div style="font-size:11px;color:var(--sub);margin-top:4px"><?php echo bg_h(mb_strimwidth($res['implementation_note'],0,80,'…')); ?></div>
        <?php endif; ?>
        <?php if ($canManage && in_array($res['status'],['pending','open','deferred'],true)): ?>
        <div class="res-actions">
          <details>
            <summary>Update result</summary>
            <form method="post" action="<?php echo bg_h(admin_url('board_governance.php')); ?>" style="margin-top:8px">
              <input type="hidden" name="action" value="update_resolution">
              <input type="hidden" name="resolution_ref" value="<?php echo bg_h($res['resolution_ref']); ?>">
              <?php if ($csrfToken): ?><input type="hidden" name="_csrf" value="<?php echo bg_h($csrfToken); ?>"><?php endif; ?>
              <div class="form-row">
                <label>Status<select name="res_status">
                  <option value="open"<?php echo $res['status']==='open'?' selected':''; ?>>Open</option>
                  <option value="passed"<?php echo $res['status']==='passed'?' selected':''; ?>>Passed</option>
                  <option value="failed"<?php echo $res['status']==='failed'?' selected':''; ?>>Failed</option>
                  <option value="deferred"<?php echo $res['status']==='deferred'?' selected':''; ?>>Deferred</option>
                </select></label>
                <label>Votes For<input type="number" name="votes_for" value="<?php echo (int)$res['votes_for']; ?>" min="0"></label>
                <label>Votes Against<input type="number" name="votes_against" value="<?php echo (int)$res['votes_against']; ?>" min="0"></label>
                <label>Abstain<input type="number" name="abstain" value="<?php echo (int)$res['abstain']; ?>" min="0"></label>
              </div>
              <div class="form-row">
                <label style="flex:2">Implementation note<textarea name="implementation_note"><?php echo bg_h($res['implementation_note'] ?? ''); ?></textarea></label>
              </div>
              <button type="submit" class="btn btn-sm btn-action">Save</button>
            </form>
          </details>
        </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ── Director Register ──────────────────────────────────────────────────── -->
<div class="card">
  <div class="card-head">
    <h2>Director Register<?php echo ops_admin_help_button('Director Register', 'Public register of Foundation Directors under Declaration cl.14. The Board must at all times include the three mandatory seats (cl.14.4(a) community-adjacent, cl.14.4(b) professional expertise, cl.14.4(c) FNAC-nominated). Board size: 3–9 Directors (cl.14.1). Terms: 2 years, max 3 consecutive (cl.14.3).'); ?></h2>
    <span class="phase-tag"><?php echo $activeCount; ?> active / <?php echo count($directors); ?> total</span>
  </div>
  <div class="card-body">
  <?php if (empty($directors)): ?>
    <p style="color:var(--sub);font-size:13px">No directors recorded yet.</p>
  <?php else: ?>
    <table class="tbl">
      <tr>
        <th>Name</th>
        <th>Seat</th>
        <th>Phase</th>
        <th>Sub-Committee</th>
        <th>Term</th>
        <th>Status</th>
        <th>Undertaking</th>
        <th>Key Ceremony</th>
        <?php if ($canManage): ?><th>Actions</th><?php endif; ?>
      </tr>
      <?php foreach ($directors as $dir):
        $seatClass = match ($dir['seat_type']) {
            '14.4(c)_fnac_nominated'      => 'seat-fnac',
            '14.4(b)_professional_expertise' => 'seat-pro',
            'at_large'                    => 'seat-alarge',
            default                       => '',
        };
      ?>
      <tr>
        <td>
          <strong><?php echo bg_h($dir['full_name']); ?></strong>
          <?php if ($dir['address']): ?><br><span style="font-size:11px;color:var(--sub)"><?php echo bg_h($dir['address']); ?></span><?php endif; ?>
          <?php if ($dir['appointing_resolution']): ?><br><span style="font-size:11px;color:var(--sub)">Res: <?php echo bg_h($dir['appointing_resolution']); ?></span><?php endif; ?>
        </td>
        <td><span class="seat-tag <?php echo $seatClass; ?>"><?php echo bg_h(bg_seat_label($dir['seat_type'])); ?></span></td>
        <td><span class="phase-tag"><?php echo bg_h(bg_phase_label((int)$dir['phase'])); ?></span></td>
        <td>
          <?php if ($dir['sub_committee'] !== 'none'): ?>
            <span class="subcomm-tag"><?php echo bg_h($dir['sub_committee']); ?></span>
            <span style="font-size:11px;color:var(--sub)"><?php echo bg_h($dir['sub_committee_role']); ?></span>
          <?php else: echo '<span style="color:var(--sub);font-size:12px">—</span>'; endif; ?>
        </td>
        <td style="font-size:12px">
          <?php echo $dir['term_start_date'] ? bg_h(substr($dir['term_start_date'],0,10)) : '<span style="color:var(--sub)">TBC</span>'; ?>
          <?php echo $dir['term_end_date'] ? ' → ' . bg_h(substr($dir['term_end_date'],0,10)) : ''; ?>
        </td>
        <td><?php echo bg_badge($dir['status']); ?></td>
        <td>
          <?php if ($dir['undertaking_signed_at']): ?>
            <span class="tick">✓</span> <span style="font-size:11px;color:var(--sub)"><?php echo bg_h(substr($dir['undertaking_signed_at'],0,10)); ?></span>
          <?php else: ?>
            <span class="cross warn">Pending</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($dir['key_ceremony_completed_at']): ?>
            <span class="tick">✓</span> <span style="font-size:11px;color:var(--sub)"><?php echo bg_h(substr($dir['key_ceremony_completed_at'],0,10)); ?></span>
            <?php if ($dir['hsm_standard']): ?><br><span style="font-size:10px;color:var(--sub)"><?php echo bg_h($dir['hsm_standard']); ?></span><?php endif; ?>
          <?php else: ?>
            <span class="cross warn">Pending</span>
          <?php endif; ?>
        </td>
        <?php if ($canManage): ?>
        <td>
          <details>
            <summary>Actions</summary>
            <?php if (empty($dir['undertaking_signed_at'])): ?>
            <form method="post" action="<?php echo bg_h(admin_url('board_governance.php')); ?>" style="display:inline">
              <input type="hidden" name="action" value="confirm_undertaking">
              <input type="hidden" name="director_uuid" value="<?php echo bg_h($dir['uuid']); ?>">
              <?php if ($csrfToken): ?><input type="hidden" name="_csrf" value="<?php echo bg_h($csrfToken); ?>"><?php endif; ?>
              <button type="submit" class="btn btn-sm btn-action" style="margin-top:6px">✓ Undertaking signed</button>
            </form>
            <?php endif; ?>
            <?php if (empty($dir['key_ceremony_completed_at'])): ?>
            <form method="post" action="<?php echo bg_h(admin_url('board_governance.php')); ?>" style="margin-top:6px">
              <input type="hidden" name="action" value="confirm_key_ceremony">
              <input type="hidden" name="director_uuid" value="<?php echo bg_h($dir['uuid']); ?>">
              <?php if ($csrfToken): ?><input type="hidden" name="_csrf" value="<?php echo bg_h($csrfToken); ?>"><?php endif; ?>
              <label style="font-size:11px;display:block;margin-bottom:4px">HSM standard<br>
              <input type="text" name="hsm_standard" value="FIPS 140-3 Level 3" style="font-size:12px;padding:4px 8px;background:var(--panel2);border:1px solid var(--line2);border-radius:6px;color:var(--text)"></label>
              <button type="submit" class="btn btn-sm btn-action">🔑 Key ceremony done</button>
            </form>
            <?php endif; ?>
            <form method="post" action="<?php echo bg_h(admin_url('board_governance.php')); ?>" style="margin-top:8px">
              <input type="hidden" name="action" value="update_director_status">
              <input type="hidden" name="director_uuid" value="<?php echo bg_h($dir['uuid']); ?>">
              <?php if ($csrfToken): ?><input type="hidden" name="_csrf" value="<?php echo bg_h($csrfToken); ?>"><?php endif; ?>
              <div class="form-row">
                <label>Status<select name="new_status" style="font-size:12px">
                  <option value="nominee"<?php echo $dir['status']==='nominee'?' selected':''; ?>>Nominee</option>
                  <option value="active"<?php echo $dir['status']==='active'?' selected':''; ?>>Active</option>
                  <option value="resigned"<?php echo $dir['status']==='resigned'?' selected':''; ?>>Resigned</option>
                  <option value="removed"<?php echo $dir['status']==='removed'?' selected':''; ?>>Removed</option>
                  <option value="term_expired"<?php echo $dir['status']==='term_expired'?' selected':''; ?>>Term Expired</option>
                </select></label>
                <label>Date<input type="date" name="status_date" value="<?php echo bg_h(date('Y-m-d')); ?>"></label>
              </div>
              <label style="font-size:11px;display:block;margin-bottom:6px">Note<br>
              <input type="text" name="status_note" placeholder="Optional note" style="width:100%;font-size:12px;padding:4px 8px;background:var(--panel2);border:1px solid var(--line2);border-radius:6px;color:var(--text)"></label>
              <button type="submit" class="btn btn-sm">Update status</button>
            </form>
          </details>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <?php if ($canManage): ?>
  <details style="margin-top:16px">
    <summary class="btn btn-sm btn-gold">+ Add Director record</summary>
    <form method="post" action="<?php echo bg_h(admin_url('board_governance.php')); ?>" style="margin-top:12px">
      <input type="hidden" name="action" value="add_director">
      <?php if ($csrfToken): ?><input type="hidden" name="_csrf" value="<?php echo bg_h($csrfToken); ?>"><?php endif; ?>
      <div class="form-row">
        <label>Full name *<input type="text" name="full_name" placeholder="Full legal name" required></label>
        <label>Address<input type="text" name="address" placeholder="Residential address"></label>
      </div>
      <div class="form-row">
        <label>Seat type *<select name="seat_type">
          <option value="14.4(a)_community_adjacent">cl.14.4(a) Community-Adjacent</option>
          <option value="14.4(b)_professional_expertise">cl.14.4(b) Professional Expertise</option>
          <option value="14.4(c)_fnac_nominated">cl.14.4(c) FNAC-Nominated ★</option>
          <option value="at_large">At-Large</option>
        </select></label>
        <label>Phase<select name="phase">
          <option value="1">Phase 1 — Founding (3)</option>
          <option value="2">Phase 2 — Sub-Committee Chairs (6)</option>
          <option value="3">Phase 3 — At-Large (9)</option>
        </select></label>
        <label>Sub-Committee<select name="sub_committee">
          <option value="none">None</option>
          <option value="STA">STA — Finance &amp; Regulatory</option>
          <option value="STB">STB — Member Affairs</option>
          <option value="STC">STC — Charity &amp; Community</option>
        </select></label>
        <label>SC Role<select name="sub_committee_role">
          <option value="none">None</option>
          <option value="chair">Chair</option>
          <option value="member">Member</option>
        </select></label>
      </div>
      <div class="form-row">
        <label>Appointing resolution<input type="text" name="appointing_resolution" placeholder="e.g. Inaugural-R2"></label>
        <label>Member number<input type="text" name="member_number" placeholder="If director is a member"></label>
        <label>Term start<input type="date" name="term_start_date"></label>
        <label>Term end<input type="date" name="term_end_date"></label>
      </div>
      <div class="form-row">
        <label style="flex:2">Qualification basis (cl.14.4(a)/(b) written finding)<textarea name="qualification_basis" placeholder="Describe how the candidate meets the seat qualification"></textarea></label>
      </div>
      <button type="submit" class="btn btn-action">Add Director</button>
    </form>
  </details>
  <?php endif; ?>
  </div>
</div>

<!-- ── Nomination Pipeline ────────────────────────────────────────────────── -->
<div class="card">
  <div class="card-head">
    <h2>Nomination Pipeline<?php echo ops_admin_help_button('Nomination Pipeline', 'Director nominations under the Director Nomination and Election Policy (Part 2 of the Board Governance Pack). Nomination windows: ≥21 days, closing ≥14 days before the Members Poll. Verified nominations proceed to an Ordinary Resolution Members Poll under JVPA cl.6.5A.'); ?></h2>
    <span class="phase-tag"><?php echo count($nominations); ?> nomination(s)</span>
  </div>
  <div class="card-body">
  <?php if (empty($nominations)): ?>
    <p style="color:var(--sub);font-size:13px">No nominations on record.</p>
  <?php else: ?>
    <table class="tbl">
      <tr>
        <th>Nominee</th>
        <th>Seat</th>
        <th>Source</th>
        <th>Window</th>
        <th>Status</th>
        <th>Result</th>
      </tr>
      <?php foreach ($nominations as $nom):
        $seatClass = match ($nom['seat_type']) {
            '14.4(c)_fnac_nominated' => 'seat-fnac',
            '14.4(b)_professional_expertise' => 'seat-pro',
            'at_large' => 'seat-alarge',
            default => '',
        };
      ?>
      <tr>
        <td>
          <strong><?php echo bg_h($nom['nominee_full_name']); ?></strong>
          <?php if ($nom['nominee_address']): ?><br><span style="font-size:11px;color:var(--sub)"><?php echo bg_h($nom['nominee_address']); ?></span><?php endif; ?>
        </td>
        <td><span class="seat-tag <?php echo $seatClass; ?>"><?php echo bg_h(bg_seat_label($nom['seat_type'])); ?></span></td>
        <td style="font-size:12px"><?php echo bg_h($nom['nomination_source']); ?></td>
        <td style="font-size:12px">
          <?php echo $nom['window_open_date'] ? bg_h(substr($nom['window_open_date'],0,10)) : '<span style="color:var(--sub)">—</span>'; ?>
          <?php echo $nom['window_close_date'] ? ' → ' . bg_h(substr($nom['window_close_date'],0,10)) : ''; ?>
        </td>
        <td><?php echo bg_badge($nom['status']); ?></td>
        <td style="font-size:12px">
          <?php if ($nom['result_votes_for'] > 0 || $nom['result_votes_against'] > 0): ?>
            ✓ <?php echo (int)$nom['result_votes_for']; ?> / ✗ <?php echo (int)$nom['result_votes_against']; ?>
          <?php else: echo '<span style="color:var(--sub)">—</span>'; endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <?php if ($canManage): ?>
  <details style="margin-top:16px">
    <summary class="btn btn-sm btn-gold">+ Submit nomination</summary>
    <form method="post" action="<?php echo bg_h(admin_url('board_governance.php')); ?>" style="margin-top:12px">
      <input type="hidden" name="action" value="add_nomination">
      <?php if ($csrfToken): ?><input type="hidden" name="_csrf" value="<?php echo bg_h($csrfToken); ?>"><?php endif; ?>
      <div class="form-row">
        <label>Nominee full name *<input type="text" name="nominee_full_name" required></label>
        <label>Nominee address<input type="text" name="nominee_address"></label>
      </div>
      <div class="form-row">
        <label>Seat type *<select name="nom_seat_type">
          <option value="14.4(a)_community_adjacent">cl.14.4(a) Community-Adjacent</option>
          <option value="14.4(b)_professional_expertise">cl.14.4(b) Professional Expertise</option>
          <option value="14.4(c)_fnac_nominated">cl.14.4(c) FNAC-Nominated ★</option>
          <option value="at_large">At-Large</option>
        </select></label>
        <label>Nomination source<select name="nom_source">
          <option value="board">Board</option>
          <option value="member_group">Member Group (≥10 S-NFT holders)</option>
          <option value="self_nominated">Self-Nominated (seconded)</option>
          <option value="fnac">FNAC</option>
        </select></label>
        <label>Window opens<input type="date" name="window_open_date"></label>
        <label>Window closes<input type="date" name="window_close_date"></label>
      </div>
      <div class="form-row">
        <label style="flex:2">Seat qualification basis<textarea name="seat_qualification"></textarea></label>
      </div>
      <div class="form-row">
        <label style="flex:2">Candidate statement (up to 1,500 words)<textarea name="candidate_statement" style="min-height:120px"></textarea></label>
      </div>
      <button type="submit" class="btn btn-action">Submit Nomination</button>
    </form>
  </details>
  <?php endif; ?>
  </div>
</div>

<!-- ── Board Architecture Reference ─────────────────────────────────────── -->
<div class="card">
  <div class="card-head">
    <h2>Board Architecture Reference<?php echo ops_admin_help_button('Architecture Reference', 'Board Governance Pack v1.0 — Part 1 architecture. Single unitary Board, 3–9 Directors, three Sub-Trust Sub-Committees (STA/STB/STC), FNAC standing advisory body. Governing clauses: Declaration cl.14.1–14.6, cl.15.1–15.5, cl.15A.4, cl.33A, cl.35(l). JVPA cl.6.1–6.7A.'); ?></h2>
  </div>
  <div class="card-body" style="font-size:13px">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px">

      <div style="background:var(--panel2);border:1px solid var(--line2);border-radius:10px;padding:14px">
        <div style="font-weight:700;color:var(--gold);margin-bottom:8px">📋 STA — Finance, Regulatory &amp; Commercial</div>
        <ul style="margin:0;padding-left:16px;color:var(--sub);font-size:12px;line-height:1.7">
          <li>Financial management, treasury, reporting</li>
          <li>Corporations Act, Trustee Act compliance</li>
          <li>ASX holdings, portfolio voting, ESG</li>
          <li>ASIC Innovation Hub engagement</li>
          <li>Audit and assurance liaison</li>
          <li>Tier 2 issuance preparatory work</li>
        </ul>
        <div style="margin-top:8px;font-size:11px;color:var(--sub)">Chair: cl.14.4(b) Director (recommended)</div>
      </div>

      <div style="background:var(--panel2);border:1px solid var(--line2);border-radius:10px;padding:14px">
        <div style="font-weight:700;color:var(--gold);margin-bottom:8px">📋 STB — Member Affairs &amp; Compliance</div>
        <ul style="margin:0;padding-left:16px;color:var(--sub);font-size:12px;line-height:1.7">
          <li>Member communications, onboarding, education</li>
          <li>Members Polls facilitation (JVPA Part 6)</li>
          <li>Independence Vault support</li>
          <li>KYC/AML, B-NFT verification</li>
          <li>Dormant Member classification (cl.4.11)</li>
          <li>Dispute resolution intake</li>
        </ul>
        <div style="margin-top:8px;font-size:11px;color:var(--sub)">Chair: Director appointed by Board resolution</div>
      </div>

      <div style="background:var(--panel2);border:1px solid var(--line2);border-radius:10px;padding:14px">
        <div style="font-weight:700;color:var(--gold);margin-bottom:8px">📋 STC — Charity &amp; Community</div>
        <ul style="margin:0;padding-left:16px;color:var(--sub);font-size:12px;line-height:1.7">
          <li>Sub-Trust C grant-making (30% First Nations priority)</li>
          <li>Charity registration, ACNC compliance</li>
          <li>Seat of Country stewardship (cl.33B)</li>
          <li>LALC &amp; PBC engagement</li>
          <li>ICIP compliance review</li>
          <li>Annual grants report for FNAC endorsement</li>
        </ul>
        <div style="margin-top:8px;font-size:11px;color:var(--sub)">Chair: Director appointed by Board resolution</div>
      </div>

      <div style="background:rgba(100,180,255,.06);border:1px solid rgba(100,180,255,.2);border-radius:10px;padding:14px">
        <div style="font-weight:700;color:#7cc4ff;margin-bottom:8px">★ FNAC — First Nations Advisory Council</div>
        <ul style="margin:0;padding-left:16px;color:var(--sub);font-size:12px;line-height:1.7">
          <li>Standing advisory body — not a sub-committee</li>
          <li>Entrenched cl.14.4(c) Director nomination</li>
          <li>Endorsement rights under cl.33A.3 and cl.35(l)</li>
          <li>Primary nominating body: Jubullum LALC</li>
          <li>Transitional arrangements: cl.33A.1A</li>
          <li>18-month remediation trigger; 24-month longstop</li>
        </ul>
        <div style="margin-top:8px;font-size:11px;color:var(--sub)">Contact: Ken Avery (Chair), Priscilla Bell (Deputy)</div>
      </div>

    </div>

    <div style="margin-top:16px;padding:12px 16px;background:rgba(212,178,92,.07);border:1px solid rgba(212,178,92,.2);border-radius:10px;font-size:12px;color:var(--sub)">
      <strong style="color:var(--gold)">Board size ramp:</strong>
      Phase 1 — 3 founding Directors (mandatory seats) →
      Phase 2 — +3 Sub-Committee chairs (target: ≤12 months of Inaugural Meeting) →
      Phase 3 — +3 at-large Directors (target: ≤24 months).
      Maximum 9 Directors (cl.14.1). Terms: 2 years, max 3 consecutive (cl.14.3).
      Mandatory composition (cl.14.4) must be maintained at every size.
    </div>
  </div>
</div>

<?php endif; // tablesReady ?>
</main>
</div>
</body>
</html>
