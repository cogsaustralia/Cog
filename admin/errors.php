<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';
ops_require_admin();
$pdo = ops_db();

// ── Super-admin gate ─────────────────────────────────────────────────────────
if (!ops_admin_can($pdo, 'admin.full')) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="en"><body style="background:#090909;color:#fff;font-family:sans-serif;padding:48px;text-align:center">';
    echo '<h2 style="color:#ef4444">Access Restricted</h2>';
    echo '<p style="color:#888;margin-top:12px">This page is accessible to super-admin users only.</p>';
    echo '<p style="margin-top:20px"><a href="./dashboard.php" style="color:#d4b25c">← Return to dashboard</a></p>';
    echo '</body></html>';
    exit;
}

function err_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function err_val(PDO $pdo, string $sql, array $p = []): string|int|float {
    try { $s=$pdo->prepare($sql); $s->execute($p); return $s->fetchColumn() ?: 0; }
    catch (Throwable) { return 0; }
}
function err_rows(PDO $pdo, string $sql, array $p = []): array {
    try { $s=$pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    catch (Throwable) { return []; }
}

// ── POST actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_verify();
    $act     = (string)($_POST['action'] ?? '');
    $adminId = ops_current_admin_user_id($pdo);

    if ($act === 'acknowledge' && ops_has_table($pdo, 'app_error_log')) {
        $pdo->prepare(
            "UPDATE app_error_log
                SET acknowledged=1, acknowledged_by=?, acknowledged_at=NOW()
              WHERE acknowledged=0
                AND route=? AND http_status=? AND LEFT(error_message,120)=?"
        )->execute([
            $adminId,
            (string)($_POST['route']         ?? ''),
            (int)   ($_POST['http_status']   ?? 0),
            (string)($_POST['error_snippet'] ?? ''),
        ]);
        $flash = 'Error class acknowledged.';
    } elseif ($act === 'acknowledge_all' && ops_has_table($pdo, 'app_error_log')) {
        $pdo->prepare("UPDATE app_error_log SET acknowledged=1, acknowledged_by=?, acknowledged_at=NOW() WHERE acknowledged=0")
            ->execute([$adminId]);
        $flash = 'All unacknowledged errors marked as reviewed.';
    }
    header('Location: ' . admin_url('errors.php' . (!empty($flash) ? '?flash='.urlencode($flash) : '')));
    exit;
}

$flash = isset($_GET['flash']) ? (string)$_GET['flash'] : null;

// ── Data ─────────────────────────────────────────────────────────────────────
$hasTable    = ops_has_table($pdo, 'app_error_log');
$sizeMb      = 0.0;
$totalErrors = 0;
$unackCount  = 0;
$summary     = [];
$recent      = [];

if ($hasTable) {
    $sizeMb      = (float) err_val($pdo, "SELECT (DATA_LENGTH+INDEX_LENGTH)/1048576 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='app_error_log'");
    $totalErrors = (int)   err_val($pdo, "SELECT COUNT(*) FROM app_error_log");
    $unackCount  = (int)   err_val($pdo, "SELECT COUNT(*) FROM app_error_log WHERE acknowledged=0");

    if (ops_has_table($pdo, 'v_app_error_summary')) {
        $summary = err_rows($pdo, "SELECT * FROM v_app_error_summary LIMIT 60");
    }
    $recent = err_rows($pdo,
        "SELECT id, route, http_status, LEFT(error_message,300) AS msg,
                area_key, member_id, request_method, acknowledged, created_at
           FROM app_error_log ORDER BY id DESC LIMIT 100");
}
$sizeAlert = $sizeMb >= 10.0;
$barPct    = min(100, ($sizeMb / 10) * 100);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Error Log — COG$ Admin</title>
<?php require __DIR__ . '/assets/admin.css'; ?>
<style>
.el-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}
.el-stat{background:var(--card-bg,#111);border:1px solid var(--line,rgba(255,255,255,.1));border-radius:10px;padding:14px 16px;text-align:center}
.el-stat .n{font-size:1.9rem;font-weight:700;font-family:monospace;line-height:1}
.el-stat .l{font-size:.72rem;color:var(--muted,#666);margin-top:6px;text-transform:uppercase;letter-spacing:.05em}
.c-red{color:#ef4444}.c-amber{color:#f59e0b}.c-green{color:#10b981}.c-dim{color:#64748b}
.tbl{width:100%;border-collapse:collapse;font-size:.81rem}
.tbl th{text-align:left;padding:8px 10px;border-bottom:1px solid var(--line,rgba(255,255,255,.1));color:var(--muted,#666);font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.tbl td{padding:7px 10px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:top}
.tbl tr:hover td{background:rgba(255,255,255,.02)}
.rpill{font-family:monospace;font-size:.77rem;padding:2px 7px;border-radius:4px;background:rgba(255,255,255,.07);color:#e2e8f0;white-space:nowrap}
.s5xx{color:#ef4444;font-weight:700;font-family:monospace}
.s4xx{color:#f59e0b;font-weight:700;font-family:monospace}
.b-new{background:#ef4444;color:#fff;font-size:.67rem;font-weight:700;padding:1px 6px;border-radius:99px}
.b-ack{background:#10b981;color:#fff;font-size:.67rem;padding:1px 6px;border-radius:99px}
.size-wrap{height:8px;background:rgba(255,255,255,.08);border-radius:4px;overflow:hidden;margin:10px 0}
.size-fill{height:8px;border-radius:4px;transition:width .4s}
.tab-row{border-bottom:1px solid var(--line,rgba(255,255,255,.1));margin-bottom:16px;display:flex;gap:0}
.tab-b{background:none;border:none;border-bottom:2px solid transparent;padding:8px 16px;font-size:.88rem;font-weight:600;color:var(--muted,#666);cursor:pointer;font-family:inherit;margin-bottom:-1px}
.tab-b.on{color:var(--gold,#d4b25c);border-bottom-color:var(--gold,#d4b25c)}
.tp{display:none}.tp.on{display:block}
.ack-btn{background:none;border:1px solid rgba(16,185,129,.35);color:#10b981;font-size:.72rem;padding:2px 8px;border-radius:4px;cursor:pointer;font-family:inherit}
.ack-btn:hover{background:rgba(16,185,129,.1)}
.alert-box{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.3);border-radius:8px;padding:14px 16px;margin-bottom:16px;color:#fca5a5;font-size:.88rem}
.info-box{background:rgba(212,178,92,.06);border:1px solid rgba(212,178,92,.2);border-radius:8px;padding:14px 16px;margin-bottom:16px;color:#d4b25c;font-size:.88rem}
.flash{background:#064e3b;border:1px solid #10b981;color:#d1fae5;padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:.88rem}
code{font-family:monospace;font-size:.82em;background:rgba(255,255,255,.07);padding:1px 5px;border-radius:3px}
@media(max-width:700px){.el-grid{grid-template-columns:repeat(2,1fr)}}
</style>
</head>
<body>
<?php $active_page='errors'; ?>
<div class="admin-shell">
<?php echo admin_sidebar_html($active_page); ?>
<main class="admin-main">

<div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px">
  <div>
    <h1 class="page-title">🚨 Application Error Log</h1>
    <p style="font-size:.82rem;color:var(--muted,#666);margin-top:4px">
      API errors captured from the live platform. Permanent record.
      Notify when table reaches <strong>10 MB</strong>.
      <em style="color:#ef4444;margin-left:6px">Super-admin access only.</em>
    </p>
  </div>
  <?php if ($hasTable && $unackCount > 0): ?>
  <form method="POST" style="flex-shrink:0">
    <?= admin_csrf_field() ?>
    <input type="hidden" name="action" value="acknowledge_all">
    <button class="admin-btn admin-btn-secondary" style="font-size:.8rem"
            onclick="return confirm('Mark all <?= $unackCount ?> unacknowledged errors as reviewed?')">
      ✓ Acknowledge All (<?= number_format($unackCount) ?>)
    </button>
  </form>
  <?php endif; ?>
</div>

<?php if ($flash): ?><div class="flash"><?= err_h($flash) ?></div><?php endif; ?>

<?php if (!$hasTable): ?>
<div class="info-box">
  ⬡ The <code>app_error_log</code> table does not exist yet.
  Run <strong>hub_monitor_queries_v1.sql</strong> via phpMyAdmin to enable error logging.
</div>
<?php else: ?>

<?php if ($sizeAlert): ?>
<div class="alert-box">
  ⚠ <strong>Size Alert:</strong> Error log is <strong><?= number_format($sizeMb,2) ?> MB</strong> — exceeds 10 MB threshold.<br>
  <span style="font-size:.83rem;opacity:.8">To archive: <code>DELETE FROM app_error_log WHERE acknowledged=1 AND created_at &lt; DATE_SUB(NOW(), INTERVAL 1 YEAR);</code></span>
</div>
<?php endif; ?>

<div class="el-grid">
  <div class="el-stat"><div class="n <?= $totalErrors>0?'c-amber':'c-green' ?>"><?= number_format($totalErrors) ?></div><div class="l">Total errors</div></div>
  <div class="el-stat"><div class="n <?= $unackCount>0?'c-red':'c-green' ?>"><?= number_format($unackCount) ?></div><div class="l">Unacknowledged</div></div>
  <div class="el-stat"><div class="n <?= $sizeMb>=10?'c-red':($sizeMb>=5?'c-amber':'c-green') ?>"><?= number_format($sizeMb,2) ?> MB</div><div class="l">Table size</div></div>
  <div class="el-stat"><div class="n c-dim"><?= count($summary) ?></div><div class="l">Error classes</div></div>
</div>

<?php $barColor = $sizeMb>=10 ? '#ef4444' : ($sizeMb>=5 ? '#f59e0b' : '#10b981'); ?>
<div class="size-wrap" title="<?= number_format($sizeMb,2) ?> MB of 10 MB alert threshold">
  <div class="size-fill" style="width:<?= number_format($barPct,1) ?>%;background:<?= $barColor ?>"></div>
</div>
<p style="font-size:.73rem;color:var(--muted,#666);margin-bottom:18px">
  <?= number_format($sizeMb,2) ?> MB / 10 MB notification threshold
</p>

<div class="tab-row">
  <button class="tab-b on" onclick="etab('grouped',this)">Grouped by Error Class (<?= count($summary) ?>)</button>
  <button class="tab-b" onclick="etab('recent',this)">Raw Log — Last 100</button>
</div>

<!-- Grouped tab -->
<div class="tp on" id="tp-grouped">
<?php if (!$summary): ?>
  <p style="text-align:center;color:var(--muted,#666);padding:32px 0">No errors recorded yet.</p>
<?php else: ?>
  <p style="font-size:.78rem;color:var(--muted,#666);margin-bottom:10px">
    Errors grouped by route + message. Acknowledge a class to mark all matching rows as reviewed.
  </p>
  <div style="overflow-x:auto">
  <table class="tbl">
    <thead><tr>
      <th>Route</th><th>Status</th><th>Error</th>
      <th style="text-align:right">Count</th>
      <th>First seen</th><th>Last seen</th>
      <th>Unack</th><th>Action</th>
    </tr></thead>
    <tbody>
    <?php foreach ($summary as $r):
      $uc  = (int)($r['unacknowledged_count']??0);
      $st  = (int)($r['http_status']??500);
      $sc  = $st>=500?'s5xx':'s4xx';
    ?>
    <tr>
      <td>
        <span class="rpill"><?= err_h((string)($r['route']??'')) ?></span>
        <?php if (!empty($r['sample_area_key'])): ?>
          <span style="font-size:.71rem;color:var(--muted,#666);margin-left:4px">[<?= err_h($r['sample_area_key']) ?>]</span>
        <?php endif; ?>
      </td>
      <td><span class="<?= $sc ?>"><?= $st ?></span></td>
      <td style="font-family:monospace;font-size:.76rem;max-width:300px;word-break:break-word"><?= err_h(substr((string)($r['error_snippet']??''),0,120)) ?></td>
      <td style="text-align:right;font-weight:700"><?= number_format((int)($r['occurrence_count']??0)) ?></td>
      <td style="font-size:.73rem;color:var(--muted,#666);white-space:nowrap"><?= err_h(substr((string)($r['first_seen']??''),0,16)) ?></td>
      <td style="font-size:.73rem;color:var(--muted,#666);white-space:nowrap"><?= err_h(substr((string)($r['last_seen']??''),0,16)) ?></td>
      <td><?= $uc>0 ? '<span class="b-new">'.$uc.'</span>' : '<span class="b-ack">ack</span>' ?></td>
      <td>
        <?php if ($uc>0): ?>
        <form method="POST" style="display:inline">
          <?= admin_csrf_field() ?>
          <input type="hidden" name="action" value="acknowledge">
          <input type="hidden" name="route" value="<?= err_h((string)($r['route']??'')) ?>">
          <input type="hidden" name="http_status" value="<?= (int)($r['http_status']??0) ?>">
          <input type="hidden" name="error_snippet" value="<?= err_h((string)($r['error_snippet']??'')) ?>">
          <button class="ack-btn" type="submit">Acknowledge</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</div>

<!-- Raw tab -->
<div class="tp" id="tp-recent">
  <p style="font-size:.78rem;color:var(--muted,#666);margin-bottom:10px">Most recent 100 individual error events. Member IDs are shown by number only — no name or PII. IPs and user-agents are SHA-256 hashed.</p>
  <div style="overflow-x:auto">
  <table class="tbl">
    <thead><tr>
      <th>Time (UTC)</th><th>Route</th><th>Status</th>
      <th>Method</th><th>Area</th><th>Member #</th>
      <th>Error message</th><th>Ack</th>
    </tr></thead>
    <tbody>
    <?php foreach ($recent as $r):
      $st = (int)($r['http_status']??500);
      $sc = $st>=500?'s5xx':'s4xx';
    ?>
    <tr>
      <td style="font-size:.72rem;color:var(--muted,#666);white-space:nowrap"><?= err_h(substr((string)($r['created_at']??''),0,16)) ?></td>
      <td><span class="rpill"><?= err_h((string)($r['route']??'')) ?></span></td>
      <td><span class="<?= $sc ?>"><?= $st ?></span></td>
      <td style="font-size:.75rem"><?= err_h((string)($r['request_method']??'')) ?></td>
      <td style="font-size:.74rem;color:var(--muted,#666)"><?= err_h((string)($r['area_key']??'—')) ?></td>
      <td style="font-size:.74rem"><?= $r['member_id'] ? '#'.err_h((string)$r['member_id']) : '—' ?></td>
      <td style="font-family:monospace;font-size:.74rem;max-width:280px;word-break:break-word"><?= err_h(substr((string)($r['msg']??''),0,200)) ?></td>
      <td><?= $r['acknowledged'] ? '<span class="b-ack">ack</span>' : '<span class="b-new">new</span>' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php endif; ?>
</main></div>
<script>
function etab(id,btn){
  document.querySelectorAll('.tp').forEach(function(p){p.classList.remove('on');});
  document.querySelectorAll('.tab-b').forEach(function(b){b.classList.remove('on');});
  document.getElementById('tp-'+id).classList.add('on');
  btn.classList.add('on');
}
</script>
</body>
</html>
