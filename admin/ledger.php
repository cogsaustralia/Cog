<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/admin_paths.php';
require_once __DIR__ . '/includes/ops_workflow.php';
require_once __DIR__ . '/includes/admin_sidebar.php';

ops_require_admin();
$pdo = ops_db();

function ld_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

function ld_rows(PDO $p, string $q, array $params = []): array {
    try {
        $s = $p->prepare($q);
        $s->execute($params);
        return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

function ld_val(PDO $p, string $q, array $params = []): int {
    try {
        $s = $p->prepare($q);
        $s->execute($params);
        return (int)$s->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

function ld_dollars(int $cents): string {
    return '$' . number_format($cents / 100, 2);
}

// ── Filters from GET ──────────────────────────────────────────────────────────
$fAccount  = isset($_GET['account'])  ? (int)$_GET['account']                          : 0;
$fFlow     = isset($_GET['flow'])     ? preg_replace('/[^a-z0-9_]/', '', $_GET['flow']) : '';
$fRef      = isset($_GET['ref'])      ? trim((string)$_GET['ref'])                      : '';
$fSource   = isset($_GET['source'])   ? trim((string)$_GET['source'])                   : '';
$fDateFrom = isset($_GET['date_from'])? trim((string)$_GET['date_from'])                : '';
$fDateTo   = isset($_GET['date_to'])  ? trim((string)$_GET['date_to'])                  : '';
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 20;

// ── Account list for dropdown ─────────────────────────────────────────────────
$accounts = ld_rows($pdo, "
    SELECT sa.id, sa.display_name, sa.account_type,
           CASE WHEN sa.account_type IN ('sub_trust_a','sub_trust_a_admin_fund','sub_trust_a_partners_pool','p_class_suspense') THEN 'A'
                WHEN sa.account_type = 'sub_trust_b' THEN 'B'
                WHEN sa.account_type IN ('sub_trust_c','sub_trust_c_gift_fund') THEN 'C'
                WHEN sa.account_type IN ('partner','donor') THEN 'M'
                ELSE 'X' END AS sub_trust
    FROM stewardship_accounts sa
    WHERE sa.status = 'active'
    ORDER BY FIELD(sa.account_type,'sub_trust_a','sub_trust_a_admin_fund','sub_trust_a_partners_pool',
                   'sub_trust_b','sub_trust_c','sub_trust_c_gift_fund','partner','donor'), sa.id
");

// ── Distinct flow categories for dropdown ────────────────────────────────────
$flowOptions = ld_rows($pdo, "SELECT DISTINCT flow_category FROM ledger_entries WHERE flow_category IS NOT NULL ORDER BY flow_category");

// ── Distinct source_table values ─────────────────────────────────────────────
$sourceOptions = ld_rows($pdo, "SELECT DISTINCT source_table FROM ledger_entries ORDER BY source_table");

// ── Build WHERE clause ────────────────────────────────────────────────────────
$where   = ['1=1'];
$params  = [];

if ($fAccount > 0) {
    $where[] = 'le.stewardship_account_id = ?';
    $params[] = $fAccount;
}
if ($fFlow !== '') {
    $where[] = 'le.flow_category = ?';
    $params[] = $fFlow;
}
if ($fRef !== '') {
    $where[] = 'le.transaction_ref LIKE ?';
    $params[] = '%' . $fRef . '%';
}
if ($fSource !== '') {
    $where[] = 'le.source_table = ?';
    $params[] = $fSource;
}
if ($fDateFrom !== '') {
    $where[] = 'le.entry_date >= ?';
    $params[] = $fDateFrom;
}
if ($fDateTo !== '') {
    $where[] = 'le.entry_date <= ?';
    $params[] = $fDateTo;
}

$whereSQL = implode(' AND ', $where);

// ── Total count ───────────────────────────────────────────────────────────────
$total    = ld_val($pdo, "SELECT COUNT(*) FROM ledger_entries le WHERE $whereSQL", $params);
$totalPages = max(1, (int)ceil($total / $perPage));
$page     = min($page, $totalPages);
$offset   = ($page - 1) * $perPage;

// ── Main page query ───────────────────────────────────────────────────────────
$pageParams   = array_merge($params, [$perPage, $offset]);
$entries = ld_rows($pdo, "
    SELECT le.id, le.transaction_ref, le.source_table, le.source_id,
           le.stewardship_account_id, sa.display_name AS account_name, sa.account_type,
           le.entry_type, le.amount_cents, le.classification,
           le.flow_category, le.entry_date, le.created_at
    FROM ledger_entries le
    JOIN stewardship_accounts sa ON sa.id = le.stewardship_account_id
    WHERE $whereSQL
    ORDER BY le.entry_date DESC, le.transaction_ref, le.id
    LIMIT ? OFFSET ?
", $pageParams);

// ── Collect sibling groups for visible transaction_refs ───────────────────────
$visibleRefs = array_unique(array_column($entries, 'transaction_ref'));
$siblings = [];
if (!empty($visibleRefs)) {
    $placeholders = implode(',', array_fill(0, count($visibleRefs), '?'));
    $sibRows = ld_rows($pdo,
        "SELECT le.id, le.transaction_ref, le.stewardship_account_id,
                sa.display_name AS account_name, le.entry_type,
                le.amount_cents, le.classification, le.flow_category
         FROM ledger_entries le
         JOIN stewardship_accounts sa ON sa.id = le.stewardship_account_id
         WHERE le.transaction_ref IN ($placeholders)
         ORDER BY le.transaction_ref, le.id",
        $visibleRefs
    );
    foreach ($sibRows as $sr) {
        $siblings[$sr['transaction_ref']][] = $sr;
    }
}

// ── Build query string helper ─────────────────────────────────────────────────
function ld_qs(array $overrides = []): string {
    global $fAccount, $fFlow, $fRef, $fSource, $fDateFrom, $fDateTo, $page;
    $base = [
        'account'   => $fAccount  ?: '',
        'flow'      => $fFlow,
        'ref'       => $fRef,
        'source'    => $fSource,
        'date_from' => $fDateFrom,
        'date_to'   => $fDateTo,
        'page'      => $page,
    ];
    $merged = array_merge($base, $overrides);
    $merged = array_filter($merged, fn($v) => $v !== '' && $v !== 0 && $v !== '0');
    return '?' . http_build_query($merged);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="./assets/admin.min.css">
<title>Ledger Entries | COG$ Admin</title>
<?php ops_admin_help_assets_once(); ?>
<style>.main { padding:24px 28px; min-width:0; }
.topbar { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:26px; flex-wrap:wrap; }
.topbar h1 { font-size:1.9rem; font-weight:700; margin-bottom:6px; }
.topbar p { color:var(--sub); font-size:13px; max-width:560px; }
.btn { display:inline-block; padding:8px 16px; border-radius:10px; font-size:13px; font-weight:700; border:1px solid var(--line2); background:var(--panel2); color:var(--text); cursor:pointer; }
.btn:hover { background:rgba(255,255,255,.08); }
.btn-sm { padding:5px 11px; font-size:12px; }

.card { background:linear-gradient(180deg,var(--panel),var(--panel2)); border:1px solid var(--line); border-radius:var(--r); overflow:hidden; margin-bottom:18px; }
.card-head { display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid var(--line); }
.card-head h2 { font-size:1rem; font-weight:700; }
.card-body { padding:16px 20px; }

table { width:100%; border-collapse:collapse; }
th, td { text-align:left; padding:9px 10px; font-size:13px; border-top:1px solid var(--line); vertical-align:top; }
th { color:var(--dim); font-weight:600; font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; border-top:none; }
.mono { font-family:monospace; font-size:11.5px; }

.entry-type-dr { color:var(--err); font-weight:700; }
.entry-type-cr { color:var(--ok); font-weight:700; }

.st { display:inline-block; padding:2px 7px; border-radius:5px; font-size:10.5px; font-weight:700; text-transform:uppercase; }
.st-asset    { background:rgba(90,158,212,.12); color:var(--blue); }
.st-liability{ background:rgba(155,125,212,.12); color:var(--purple); }
.st-equity   { background:rgba(212,178,92,.12);  color:var(--gold); }
.st-income   { background:rgba(82,184,122,.12);  color:var(--ok); }
.st-expense  { background:rgba(196,96,96,.12);   color:var(--err); }

.sibling-panel { display:none; background:rgba(255,255,255,.02); border-top:1px solid var(--line); }
.sibling-panel.open { display:table-row-group; }
.sibling-panel td { padding:5px 10px 5px 28px; font-size:11.5px; border-top:none !important; }
.sibling-panel tr:first-child td { padding-top:8px; }
.sibling-panel tr:last-child td  { padding-bottom:8px; }
.sib-entry-dr { color:var(--err); font-size:10px; font-weight:700; }
.sib-entry-cr { color:var(--ok);  font-size:10px; font-weight:700; }

.expand-btn { background:none; border:none; color:var(--dim); cursor:pointer; font-size:11px; padding:2px 5px; border-radius:4px; white-space:nowrap; }
.expand-btn:hover { color:var(--sub); background:rgba(255,255,255,.05); }

/* Filter bar */
.filter-bar { background:var(--panel2); border:1px solid var(--line); border-radius:var(--r2); padding:14px 18px; margin-bottom:18px; display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; }
.filter-group { display:flex; flex-direction:column; gap:4px; }
.filter-group label { font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--dim); }
.filter-group select,
.filter-group input  { background:var(--panel); border:1px solid var(--line2); border-radius:8px; color:var(--text); font-size:12px; padding:6px 10px; min-width:130px; }
.filter-group select:focus,
.filter-group input:focus { outline:1px solid rgba(212,178,92,.4); }

/* Pagination */
.pager { display:flex; gap:6px; align-items:center; justify-content:flex-end; margin-top:14px; font-size:12px; flex-wrap:wrap; }
.pager .pg-info { color:var(--sub); }
.pager a, .pager span { display:inline-block; padding:5px 11px; border-radius:8px; border:1px solid var(--line); background:var(--panel2); color:var(--sub); font-size:12px; font-weight:600; }
.pager a { color:var(--text); }
.pager a:hover { background:rgba(255,255,255,.07); }
.pager .pg-current { background:rgba(212,178,92,.12); border-color:rgba(212,178,92,.3); color:var(--gold); }

.empty { color:var(--dim); font-size:13px; padding:24px 0; text-align:center; }

/* Flow badge */
.flow-badge { display:inline-block; padding:2px 7px; border-radius:5px; font-size:10px; font-weight:700; background:rgba(255,255,255,.06); color:var(--sub); font-family:monospace; }
</style>
</head>
<body>
<div class="admin-shell">
<?php admin_sidebar_render('accounting'); ?>
<main class="main">

<div class="topbar">
  <div>
    <h1>Ledger entries</h1>
    <p>Double-entry ledger drill-down. Click any row to expand sibling entries sharing the same transaction reference.</p>
  </div>
  <div style="display:flex;gap:8px">
    <a class="btn" href="<?php echo ld_h(admin_url('accounting.php')); ?>">← Accounting</a>
  </div>
</div>

<!-- Filter bar -->
<form method="get" action="" style="margin:0">
<div class="filter-bar">
  <div class="filter-group">
    <label>Account</label>
    <select name="account">
      <option value="">All accounts</option>
      <?php
      $lastSector = '';
      foreach ($accounts as $ac):
        if ($ac['sub_trust'] !== $lastSector) {
            $sectorName = ['A'=>'Sub-Trust A','B'=>'Sub-Trust B','C'=>'Sub-Trust C','M'=>'Members','X'=>'External'][$ac['sub_trust']] ?? $ac['sub_trust'];
            if ($lastSector !== '') echo '</optgroup>';
            echo '<optgroup label="' . ld_h($sectorName) . '">';
            $lastSector = $ac['sub_trust'];
        }
        $sel = $fAccount === (int)$ac['id'] ? ' selected' : '';
        echo '<option value="' . ld_h((string)$ac['id']) . '"' . $sel . '>' . ld_h($ac['display_name']) . '</option>';
      endforeach;
      if ($lastSector !== '') echo '</optgroup>';
      ?>
    </select>
  </div>

  <div class="filter-group">
    <label>Flow category</label>
    <select name="flow">
      <option value="">All flows</option>
      <?php foreach ($flowOptions as $fo):
        $sel = $fFlow === $fo['flow_category'] ? ' selected' : '';
        echo '<option value="' . ld_h($fo['flow_category']) . '"' . $sel . '>' . ld_h($fo['flow_category']) . '</option>';
      endforeach; ?>
    </select>
  </div>

  <div class="filter-group">
    <label>Source table</label>
    <select name="source">
      <option value="">All sources</option>
      <?php foreach ($sourceOptions as $so):
        $sel = $fSource === $so['source_table'] ? ' selected' : '';
        echo '<option value="' . ld_h($so['source_table']) . '"' . $sel . '>' . ld_h($so['source_table']) . '</option>';
      endforeach; ?>
    </select>
  </div>

  <div class="filter-group">
    <label>Transaction ref</label>
    <input type="text" name="ref" value="<?php echo ld_h($fRef); ?>" placeholder="GDLY-…" style="min-width:180px">
  </div>

  <div class="filter-group">
    <label>Date from</label>
    <input type="date" name="date_from" value="<?php echo ld_h($fDateFrom); ?>">
  </div>

  <div class="filter-group">
    <label>Date to</label>
    <input type="date" name="date_to" value="<?php echo ld_h($fDateTo); ?>">
  </div>

  <div style="display:flex;gap:6px;align-items:flex-end">
    <button type="submit" class="btn btn-sm" style="background:rgba(212,178,92,.15);border-color:rgba(212,178,92,.3);color:var(--gold)">Filter</button>
    <a href="ledger.php" class="btn btn-sm">Reset</a>
  </div>
</div>
</form>

<div class="card">
  <div class="card-head">
    <h2>Results</h2>
    <span style="font-size:12px;color:var(--dim)"><?php echo number_format($total); ?> entr<?php echo $total === 1 ? 'y' : 'ies'; ?> &middot; page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
  </div>
  <?php if (empty($entries)): ?>
    <div class="card-body"><p class="empty">No ledger entries match the current filters.</p></div>
  <?php else: ?>
  <div style="overflow-x:auto">
    <table id="ledger-table">
      <thead>
        <tr>
          <th style="width:32px"></th>
          <th>Transaction ref</th>
          <th>Account</th>
          <th>Dr / Cr</th>
          <th>Amount</th>
          <th>Classification</th>
          <th>Flow</th>
          <th>Source</th>
          <th>Date</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $renderedRefs = [];
      foreach ($entries as $idx => $e):
        $ref = $e['transaction_ref'];
        $sibGroup = $siblings[$ref] ?? [];
        $sibCount = count($sibGroup);
        $rowId = 'row-' . $idx;
        $sibId = 'sib-' . $idx;
        $isDr = $e['entry_type'] === 'debit';
        $classMap = ['asset'=>'st-asset','liability'=>'st-liability','equity'=>'st-equity','income'=>'st-income','expense'=>'st-expense'];
        $clsClass = $classMap[$e['classification']] ?? '';
        $alreadyShownRef = in_array($ref, $renderedRefs, true);
        if (!$alreadyShownRef) $renderedRefs[] = $ref;
      ?>
        <tr id="<?php echo ld_h($rowId); ?>" class="ledger-row" data-sib="<?php echo ld_h($sibId); ?>" style="cursor:pointer" title="Click to show all entries in this transaction group">
          <td style="text-align:center">
            <?php if ($sibCount > 1): ?>
              <button class="expand-btn" type="button" data-sib="<?php echo ld_h($sibId); ?>">+<?php echo $sibCount - 1; ?></button>
            <?php endif; ?>
          </td>
          <td>
            <span class="mono" style="color:<?php echo $alreadyShownRef ? 'var(--dim)' : 'var(--text)'; ?>"><?php echo ld_h($ref); ?></span>
          </td>
          <td style="font-size:12px">
            <a href="ledger.php<?php echo ld_h(ld_qs(['account' => $e['stewardship_account_id'], 'page' => 1])); ?>" style="color:var(--sub)" title="Filter to this account"><?php echo ld_h($e['account_name']); ?></a>
          </td>
          <td><span class="<?php echo $isDr ? 'entry-type-dr' : 'entry-type-cr'; ?>"><?php echo $isDr ? 'Dr' : 'Cr'; ?></span></td>
          <td style="font-weight:700"><?php echo ld_h(ld_dollars((int)$e['amount_cents'])); ?></td>
          <td><span class="st <?php echo ld_h($clsClass); ?>"><?php echo ld_h($e['classification']); ?></span></td>
          <td><?php echo $e['flow_category'] ? '<span class="flow-badge">' . ld_h($e['flow_category']) . '</span>' : '<span style="color:var(--dim)">—</span>'; ?></td>
          <td style="color:var(--sub);font-size:11.5px"><?php echo ld_h($e['source_table']); ?><?php if($e['source_id']) echo ' #' . (int)$e['source_id']; ?></td>
          <td style="color:var(--sub);font-size:11.5px;white-space:nowrap"><?php echo ld_h($e['entry_date']); ?></td>
        </tr>
        <?php if ($sibCount > 1): ?>
        <tr>
          <td colspan="9" style="padding:0;border-top:none">
            <table style="width:100%;border-collapse:collapse" id="<?php echo ld_h($sibId); ?>" class="sibling-panel">
              <tbody>
              <?php foreach ($sibGroup as $sib):
                $sibDr = $sib['entry_type'] === 'debit';
                $sibClsClass = $classMap[$sib['classification']] ?? '';
              ?>
                <tr>
                  <td style="width:32px"></td>
                  <td style="width:26%;font-size:11px;color:var(--dim)">
                    <?php if ((int)$sib['id'] === (int)$e['id']): ?>
                      <span style="color:var(--gold);font-size:10px">▶ this entry</span>
                    <?php endif; ?>
                  </td>
                  <td style="width:22%;font-size:11.5px;color:var(--sub)"><?php echo ld_h($sib['account_name']); ?></td>
                  <td style="width:8%"><span class="<?php echo $sibDr ? 'sib-entry-dr' : 'sib-entry-cr'; ?>"><?php echo $sibDr ? 'Dr' : 'Cr'; ?></span></td>
                  <td style="width:12%;font-weight:700;font-size:12px"><?php echo ld_h(ld_dollars((int)$sib['amount_cents'])); ?></td>
                  <td><span class="st <?php echo ld_h($sibClsClass); ?>" style="font-size:9.5px"><?php echo ld_h($sib['classification']); ?></span></td>
                  <td><?php echo $sib['flow_category'] ? '<span class="flow-badge">' . ld_h($sib['flow_category']) . '</span>' : ''; ?></td>
                  <td colspan="2"></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </td>
        </tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <div class="card-body" style="padding-top:0">
    <div class="pager">
      <span class="pg-info"><?php echo number_format($total); ?> results</span>
      <?php if ($page > 1): ?>
        <a href="ledger.php<?php echo ld_h(ld_qs(['page' => 1])); ?>">«</a>
        <a href="ledger.php<?php echo ld_h(ld_qs(['page' => $page - 1])); ?>">‹ Prev</a>
      <?php else: ?>
        <span>«</span><span>‹ Prev</span>
      <?php endif; ?>

      <?php
      $start = max(1, $page - 2);
      $end   = min($totalPages, $page + 2);
      for ($pg = $start; $pg <= $end; $pg++):
        $cls = $pg === $page ? ' pg-current' : '';
        echo $pg === $page
          ? '<span class="' . trim('pg-current') . '">' . $pg . '</span>'
          : '<a href="ledger.php' . ld_h(ld_qs(['page' => $pg])) . '">' . $pg . '</a>';
      endfor;
      ?>

      <?php if ($page < $totalPages): ?>
        <a href="ledger.php<?php echo ld_h(ld_qs(['page' => $page + 1])); ?>">Next ›</a>
        <a href="ledger.php<?php echo ld_h(ld_qs(['page' => $totalPages])); ?>">»</a>
      <?php else: ?>
        <span>Next ›</span><span>»</span>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

</main>
</div>

<script>
(function(){
  /* Expand/collapse sibling groups */
  function toggleSib(sibId) {
    var panel = document.getElementById(sibId);
    if(!panel) return;
    panel.classList.toggle('open');
  }

  /* Click expand button */
  document.querySelectorAll('.expand-btn').forEach(function(btn){
    btn.addEventListener('click', function(e){
      e.stopPropagation();
      var sibId = btn.getAttribute('data-sib');
      toggleSib(sibId);
      btn.textContent = btn.textContent.startsWith('+') ? '−' + btn.textContent.slice(1) : '+' + btn.textContent.slice(1);
    });
  });

  /* Click row to toggle sibling group */
  document.querySelectorAll('.ledger-row').forEach(function(row){
    row.addEventListener('click', function(e){
      if(e.target.tagName === 'A' || e.target.tagName === 'BUTTON') return;
      var sibId = row.getAttribute('data-sib');
      var btn = row.querySelector('.expand-btn');
      if(!sibId) return;
      toggleSib(sibId);
      if(btn) btn.textContent = btn.textContent.startsWith('+') ? '−' + btn.textContent.slice(1) : '+' + btn.textContent.slice(1);
    });
  });
})();
</script>
</body>
</html>
