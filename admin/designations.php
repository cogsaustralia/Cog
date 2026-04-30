<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/ops_workflow.php';
ops_require_admin();
$pdo   = ops_db();
$flash = '';
$flashT = 'ok';

function dh2(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ── Active tab ────────────────────────────────────────────────────────────────
$tab = in_array($_GET['tab'] ?? '', ['designations','milestones','objectives'], true)
    ? $_GET['tab'] : 'designations';

// ── Table guard ───────────────────────────────────────────────────────────────
$tablesReady = ops_table_exists($pdo, 'poor_esg_target_designations')
            && ops_table_exists($pdo, 'designation_milestones')
            && ops_table_exists($pdo, 'esg_improvement_objectives');

// ── AJAX save handlers ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action === 'save_designation') {
            $id     = (int)($_POST['id'] ?? 0);
            $status = $_POST['designation_status'] ?? '';
            $fnSummary = trim((string)($_POST['first_nations_engagement_summary'] ?? ''));
            $esgRat    = trim((string)($_POST['esg_rationale_summary'] ?? ''));
            $active    = isset($_POST['is_active']) ? (int)(bool)$_POST['is_active'] : null;
            $allowed = ['pending_consultation','fnac_review','board_identified','acquisition_ready','active_engagement'];
            if (!$id || !in_array($status, $allowed, true)) {
                echo json_encode(['ok'=>false,'msg'=>'Invalid input']); exit;
            }
            $sets = ['designation_status=?','first_nations_engagement_summary=?','esg_rationale_summary=?'];
            $params = [$status, $fnSummary ?: null, $esgRat ?: null];
            if ($active !== null) { $sets[] = 'is_active=?'; $params[] = $active; }
            $params[] = $id;
            $pdo->prepare('UPDATE poor_esg_target_designations SET '.implode(',',$sets).' WHERE id=?')->execute($params);
            echo json_encode(['ok'=>true,'msg'=>'Saved']); exit;
        }

        if ($action === 'save_milestone') {
            $id     = (int)($_POST['id'] ?? 0);
            $status = $_POST['milestone_status'] ?? '';
            $note   = trim((string)($_POST['status_note'] ?? ''));
            $date   = trim((string)($_POST['completed_date'] ?? ''));
            $allowed = ['pending','in_progress','complete','blocked'];
            if (!$id || !in_array($status, $allowed, true)) {
                echo json_encode(['ok'=>false,'msg'=>'Invalid input']); exit;
            }
            $completedDate = ($date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) ? $date : null;
            $pdo->prepare('UPDATE designation_milestones SET milestone_status=?,completed_date=?,status_note=? WHERE id=?')
                ->execute([$status, $completedDate, $note ?: null, $id]);
            echo json_encode(['ok'=>true,'msg'=>'Saved']); exit;
        }

        if ($action === 'save_objective') {
            $id   = (int)($_POST['id'] ?? 0);
            $text = trim((string)($_POST['objective_text'] ?? ''));
            $year = (int)($_POST['target_agm_year'] ?? 0);
            if (!$id || $text === '') {
                echo json_encode(['ok'=>false,'msg'=>'Invalid input']); exit;
            }
            $pdo->prepare('UPDATE esg_improvement_objectives SET objective_text=?,target_agm_year=? WHERE id=?')
                ->execute([$text, $year ?: null, $id]);
            echo json_encode(['ok'=>true,'msg'=>'Saved']); exit;
        }

        echo json_encode(['ok'=>false,'msg'=>'Unknown action']); exit;

    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'msg'=>'DB error: '.dh2($e->getMessage())]); exit;
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$search  = trim((string)($_GET['q'] ?? ''));
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

// Designation filter (used in milestones + objectives tabs)
$filterDesignationId = (int)($_GET['designation_id'] ?? 0);

// Load data for active tab
$designations = [];
$milestones   = [];
$objectives   = [];
$total        = 0;
$totalPages   = 1;

if ($tablesReady) {
    // Always load designation list for filter dropdowns
    $allDesignations = $pdo->query('SELECT id,company_name,asx_code FROM poor_esg_target_designations ORDER BY public_display_order,id')->fetchAll(PDO::FETCH_ASSOC);

    if ($tab === 'designations') {
        $where  = [];
        $params = [];
        if ($search) {
            $where[]  = '(company_name LIKE ? OR asx_code LIKE ?)';
            $params[] = '%'.$search.'%';
            $params[] = '%'.$search.'%';
        }
        $whereSQL = $where ? 'WHERE '.implode(' AND ',$where) : '';
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM poor_esg_target_designations $whereSQL");
        $cnt->execute($params);
        $total      = (int)$cnt->fetchColumn();
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * $perPage;
        $stmt = $pdo->prepare("SELECT * FROM poor_esg_target_designations $whereSQL ORDER BY public_display_order,id LIMIT $perPage OFFSET $offset");
        $stmt->execute($params);
        $designations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($tab === 'milestones') {
        $where  = [];
        $params = [];
        if ($filterDesignationId) { $where[] = 'dm.designation_id=?'; $params[] = $filterDesignationId; }
        if ($search) { $where[] = '(dm.milestone_label LIKE ? OR dm.status_note LIKE ?)'; $params[] = '%'.$search.'%'; $params[] = '%'.$search.'%'; }
        $whereSQL = $where ? 'WHERE '.implode(' AND ',$where) : '';
        $joinSQL  = 'FROM designation_milestones dm JOIN poor_esg_target_designations d ON d.id=dm.designation_id';
        $cnt = $pdo->prepare("SELECT COUNT(*) $joinSQL $whereSQL");
        $cnt->execute($params);
        $total      = (int)$cnt->fetchColumn();
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * $perPage;
        $stmt = $pdo->prepare("SELECT dm.*,d.company_name,d.asx_code $joinSQL $whereSQL ORDER BY dm.designation_id,dm.display_order LIMIT $perPage OFFSET $offset");
        $stmt->execute($params);
        $milestones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($tab === 'objectives') {
        $where  = [];
        $params = [];
        if ($filterDesignationId) { $where[] = 'eo.designation_id=?'; $params[] = $filterDesignationId; }
        if ($search) { $where[] = 'eo.objective_text LIKE ?'; $params[] = '%'.$search.'%'; }
        $whereSQL = $where ? 'WHERE '.implode(' AND ',$where) : '';
        $joinSQL  = 'FROM esg_improvement_objectives eo JOIN poor_esg_target_designations d ON d.id=eo.designation_id';
        $cnt = $pdo->prepare("SELECT COUNT(*) $joinSQL $whereSQL");
        $cnt->execute($params);
        $total      = (int)$cnt->fetchColumn();
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * $perPage;
        $stmt = $pdo->prepare("SELECT eo.*,d.company_name,d.asx_code $joinSQL $whereSQL ORDER BY eo.designation_id,eo.display_order LIMIT $perPage OFFSET $offset");
        $stmt->execute($params);
        $objectives = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ── Status labels ─────────────────────────────────────────────────────────────
$designationStatusLabels = [
    'pending_consultation' => 'Pending Consultation',
    'fnac_review'          => 'FNAC Review',
    'board_identified'     => 'Board Identified',
    'acquisition_ready'    => 'Acquisition Ready',
    'active_engagement'    => 'Active Engagement',
];
$milestoneStatusLabels = [
    'pending'     => 'Pending',
    'in_progress' => 'In Progress',
    'complete'    => 'Complete',
    'blocked'     => 'Blocked',
];
$milestoneStatusBadge = [
    'pending'     => 'badge-muted',
    'in_progress' => 'badge-warn',
    'complete'    => 'badge-ok',
    'blocked'     => 'badge-err',
];

ob_start(); ?>
<style>
.dg-tabs{display:flex;gap:0;border-bottom:1px solid var(--line,#2a2a2a);margin-bottom:18px}
.dg-tab{padding:9px 18px;font-size:.82rem;font-weight:600;cursor:pointer;border:none;background:none;color:var(--muted,#888);border-bottom:2px solid transparent;margin-bottom:-1px}
.dg-tab.on{color:var(--gold,#c9973d);border-bottom-color:var(--gold,#c9973d)}
.dg-filterbar{display:flex;gap:8px;align-items:center;margin-bottom:14px;flex-wrap:wrap}
.dg-filterbar input[type=text],.dg-filterbar select{padding:5px 9px;background:rgba(255,255,255,.06);border:1px solid var(--line,#333);color:inherit;border-radius:4px;font-size:.81rem}
.dg-filterbar button{padding:5px 14px;background:var(--gold,#c9973d);border:none;border-radius:4px;color:#000;font-weight:700;cursor:pointer;font-size:.81rem}
.dg-table{width:100%;border-collapse:collapse;font-size:.82rem}
.dg-table th{text-align:left;font-size:.68rem;text-transform:uppercase;letter-spacing:.07em;color:var(--muted,#888);padding:7px 10px;border-bottom:1px solid var(--line,#2a2a2a)}
.dg-table td{padding:8px 10px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:top}
.dg-table tr:hover td{background:rgba(201,151,61,.04)}
.dg-inline-form{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.dg-inline-form select,.dg-inline-form input[type=text],.dg-inline-form input[type=date],.dg-inline-form input[type=number]{padding:4px 7px;background:rgba(255,255,255,.06);border:1px solid var(--line,#333);color:inherit;border-radius:4px;font-size:.8rem}
.dg-inline-form textarea{padding:4px 7px;background:rgba(255,255,255,.06);border:1px solid var(--line,#333);color:inherit;border-radius:4px;font-size:.8rem;resize:vertical;min-height:50px;width:100%;box-sizing:border-box}
.dg-save-btn{padding:4px 12px;background:var(--gold,#c9973d);border:none;border-radius:4px;color:#000;font-weight:700;cursor:pointer;font-size:.8rem}
.dg-pager{display:flex;gap:10px;align-items:center;padding:10px 0;font-size:.75rem;color:var(--muted)}
.dg-pager a{color:var(--gold,#c9973d);text-decoration:none;padding:2px 8px;border:1px solid var(--line,#333);border-radius:3px}
.badge{display:inline-block;padding:2px 7px;border-radius:99px;font-size:.67rem;font-weight:700}
.badge-ok{background:rgba(82,184,122,.15);color:#52b87a}
.badge-warn{background:rgba(255,193,7,.15);color:#ffc107}
.badge-err{background:rgba(220,53,69,.15);color:#dc3545}
.badge-muted{background:rgba(150,150,150,.15);color:#999}
.badge-gold{background:rgba(201,151,61,.15);color:#c9973d}
#dg-toast{display:none;padding:6px 12px;border-radius:4px;font-size:.82rem;margin-bottom:12px}
.toast-ok{background:rgba(82,184,122,.12);border:1px solid rgba(82,184,122,.3);color:#52b87a}
.toast-err{background:rgba(220,53,69,.12);border:1px solid rgba(220,53,69,.3);color:#dc3545}
</style>

<div id="dg-toast"></div>

<?php if (!$tablesReady): ?>
<div class="alert alert-err">
  Poor ESG Target tables not found. Run <code>sql/stage6_poor_esg_designations_v1.sql</code> in phpMyAdmin against <code>cogsaust_TRUST</code> and reload.
</div>
<?php else: ?>

<!-- Tab bar -->
<div class="dg-tabs">
  <a href="?tab=designations" class="dg-tab <?= $tab==='designations'?'on':'' ?>">Designations</a>
  <a href="?tab=milestones"   class="dg-tab <?= $tab==='milestones'?'on':'' ?>">Milestones</a>
  <a href="?tab=objectives"   class="dg-tab <?= $tab==='objectives'?'on':'' ?>">ESG Objectives</a>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<?php if ($tab === 'designations'): ?>

<form method="get" class="dg-filterbar">
  <input type="hidden" name="tab" value="designations">
  <input type="text" name="q" value="<?= dh2($search) ?>" placeholder="Search company / ASX code…">
  <button type="submit">Filter</button>
  <?php if ($search): ?><a href="?tab=designations" style="font-size:.8rem;color:var(--muted)">Clear</a><?php endif ?>
</form>

<table class="dg-table">
  <thead>
    <tr>
      <th>Company</th>
      <th>Status</th>
      <th>Strategy</th>
      <th>FN Engagement Summary</th>
      <th>ESG Rationale</th>
      <th>Active</th>
      <th>Save</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($designations as $d): ?>
  <tr>
    <td>
      <strong><?= dh2($d['company_name']) ?></strong><br>
      <span style="font-size:.74rem;color:var(--muted)">ASX: <?= dh2($d['asx_code']) ?></span>
    </td>
    <td>
      <select class="dg-status-sel" data-id="<?= (int)$d['id'] ?>" data-field="designation_status" style="font-size:.8rem">
        <?php foreach ($designationStatusLabels as $v=>$l): ?>
          <option value="<?= dh2($v) ?>" <?= $d['designation_status']===$v?'selected':'' ?>><?= dh2($l) ?></option>
        <?php endforeach ?>
      </select>
    </td>
    <td style="font-size:.78rem"><?= dh2($d['strategy_version']) ?><br><?= dh2($d['strategy_issued_date']) ?></td>
    <td>
      <textarea class="dg-fn-summary" data-id="<?= (int)$d['id'] ?>" rows="3"><?= dh2($d['first_nations_engagement_summary'] ?? '') ?></textarea>
    </td>
    <td>
      <textarea class="dg-esg-rat" data-id="<?= (int)$d['id'] ?>" rows="3"><?= dh2($d['esg_rationale_summary'] ?? '') ?></textarea>
    </td>
    <td>
      <select class="dg-active-sel" data-id="<?= (int)$d['id'] ?>">
        <option value="1" <?= $d['is_active']?'selected':'' ?>>Yes</option>
        <option value="0" <?= !$d['is_active']?'selected':'' ?>>Disabled</option>
      </select>
    </td>
    <td>
      <button class="dg-save-btn" onclick="dgSaveDesignation(<?= (int)$d['id'] ?>)">Save</button>
    </td>
  </tr>
  <?php endforeach ?>
  <?php if (!$designations): ?>
  <tr><td colspan="7" style="text-align:center;padding:30px;color:var(--muted)">No designations found.</td></tr>
  <?php endif ?>
  </tbody>
</table>

<?php endif ?>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<?php if ($tab === 'milestones'): ?>

<form method="get" class="dg-filterbar">
  <input type="hidden" name="tab" value="milestones">
  <select name="designation_id">
    <option value="">All designations</option>
    <?php foreach ($allDesignations as $ad): ?>
      <option value="<?= (int)$ad['id'] ?>" <?= $filterDesignationId===(int)$ad['id']?'selected':'' ?>>
        <?= dh2($ad['company_name']) ?> (<?= dh2($ad['asx_code']) ?>)
      </option>
    <?php endforeach ?>
  </select>
  <input type="text" name="q" value="<?= dh2($search) ?>" placeholder="Search label / note…">
  <button type="submit">Filter</button>
  <?php if ($search||$filterDesignationId): ?><a href="?tab=milestones" style="font-size:.8rem;color:var(--muted)">Clear</a><?php endif ?>
</form>

<table class="dg-table">
  <thead>
    <tr>
      <th>Company</th>
      <th>Milestone</th>
      <th>Status</th>
      <th>Completed Date</th>
      <th>Note</th>
      <th>Save</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($milestones as $m): ?>
  <tr>
    <td style="font-size:.78rem"><?= dh2($m['company_name']) ?><br><span style="color:var(--muted)"><?= dh2($m['asx_code']) ?></span></td>
    <td><?= dh2($m['milestone_label']) ?></td>
    <td>
      <select class="dg-ms-status" data-id="<?= (int)$m['id'] ?>">
        <?php foreach ($milestoneStatusLabels as $v=>$l): ?>
          <option value="<?= dh2($v) ?>" <?= $m['milestone_status']===$v?'selected':'' ?>><?= dh2($l) ?></option>
        <?php endforeach ?>
      </select>
    </td>
    <td>
      <input type="date" class="dg-ms-date" data-id="<?= (int)$m['id'] ?>" value="<?= dh2($m['completed_date'] ?? '') ?>">
    </td>
    <td>
      <input type="text" class="dg-ms-note" data-id="<?= (int)$m['id'] ?>" value="<?= dh2($m['status_note'] ?? '') ?>" style="width:180px">
    </td>
    <td>
      <button class="dg-save-btn" onclick="dgSaveMilestone(<?= (int)$m['id'] ?>)">Save</button>
    </td>
  </tr>
  <?php endforeach ?>
  <?php if (!$milestones): ?>
  <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--muted)">No milestones found.</td></tr>
  <?php endif ?>
  </tbody>
</table>

<?php endif ?>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<?php if ($tab === 'objectives'): ?>

<form method="get" class="dg-filterbar">
  <input type="hidden" name="tab" value="objectives">
  <select name="designation_id">
    <option value="">All designations</option>
    <?php foreach ($allDesignations as $ad): ?>
      <option value="<?= (int)$ad['id'] ?>" <?= $filterDesignationId===(int)$ad['id']?'selected':'' ?>>
        <?= dh2($ad['company_name']) ?> (<?= dh2($ad['asx_code']) ?>)
      </option>
    <?php endforeach ?>
  </select>
  <input type="text" name="q" value="<?= dh2($search) ?>" placeholder="Search objective text…">
  <button type="submit">Filter</button>
  <?php if ($search||$filterDesignationId): ?><a href="?tab=objectives" style="font-size:.8rem;color:var(--muted)">Clear</a><?php endif ?>
</form>

<table class="dg-table">
  <thead>
    <tr>
      <th>Company</th>
      <th>Category</th>
      <th>Objective Text</th>
      <th>Target AGM Year</th>
      <th>Save</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($objectives as $o): ?>
  <tr>
    <td style="font-size:.78rem"><?= dh2($o['company_name']) ?><br><span style="color:var(--muted)"><?= dh2($o['asx_code']) ?></span></td>
    <td><span class="badge badge-gold"><?= dh2(str_replace('_',' ',ucfirst($o['objective_category']))) ?></span></td>
    <td>
      <textarea class="dg-obj-text" data-id="<?= (int)$o['id'] ?>" rows="3"><?= dh2($o['objective_text']) ?></textarea>
    </td>
    <td>
      <input type="number" class="dg-obj-year" data-id="<?= (int)$o['id'] ?>" value="<?= (int)($o['target_agm_year'] ?? 0) ?>" min="2026" max="2040" style="width:80px">
    </td>
    <td>
      <button class="dg-save-btn" onclick="dgSaveObjective(<?= (int)$o['id'] ?>)">Save</button>
    </td>
  </tr>
  <?php endforeach ?>
  <?php if (!$objectives): ?>
  <tr><td colspan="5" style="text-align:center;padding:30px;color:var(--muted)">No objectives found.</td></tr>
  <?php endif ?>
  </tbody>
</table>

<?php endif ?>

<!-- Pagination (shared) -->
<?php if ($totalPages > 1): ?>
<div class="dg-pager">
  Page <?= $page ?> of <?= $totalPages ?>
  <?php if ($page > 1): ?>
    <a href="?tab=<?= dh2($tab) ?>&page=<?= $page-1 ?>&q=<?= urlencode($search) ?>&designation_id=<?= $filterDesignationId ?>">&#8592; Prev</a>
  <?php endif ?>
  <?php if ($page < $totalPages): ?>
    <a href="?tab=<?= dh2($tab) ?>&page=<?= $page+1 ?>&q=<?= urlencode($search) ?>&designation_id=<?= $filterDesignationId ?>">Next &#8594;</a>
  <?php endif ?>
</div>
<?php endif ?>

<?php endif ?>

<script>
(function(){
function toast(msg,type){
  var el=document.getElementById('dg-toast');
  el.textContent=msg;
  el.className=(type==='err')?'toast-err':'toast-ok';
  el.style.display='block';
  clearTimeout(el._t);
  el._t=setTimeout(function(){el.style.display='none';},3000);
}
function post(data,cb){
  fetch(window.location.pathname,{
    method:'POST',
    headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams(data).toString()
  })
  .then(function(r){return r.json();})
  .then(function(j){if(j.ok){toast(j.msg||'Saved');}else{toast(j.msg||'Error','err');}})
  .catch(function(){toast('Network error','err');});
}

window.dgSaveDesignation=function(id){
  var row=document.querySelector('.dg-status-sel[data-id="'+id+'"]');
  if(!row)return;
  var status=row.value;
  var fn=document.querySelector('.dg-fn-summary[data-id="'+id+'"]');
  var esg=document.querySelector('.dg-esg-rat[data-id="'+id+'"]');
  var active=document.querySelector('.dg-active-sel[data-id="'+id+'"]');
  post({
    action:'save_designation',
    id:id,
    designation_status:status,
    first_nations_engagement_summary:fn?fn.value:'',
    esg_rationale_summary:esg?esg.value:'',
    is_active:active?active.value:'1'
  });
};

window.dgSaveMilestone=function(id){
  var status=document.querySelector('.dg-ms-status[data-id="'+id+'"]');
  var date=document.querySelector('.dg-ms-date[data-id="'+id+'"]');
  var note=document.querySelector('.dg-ms-note[data-id="'+id+'"]');
  if(!status)return;
  post({
    action:'save_milestone',
    id:id,
    milestone_status:status.value,
    completed_date:date?date.value:'',
    status_note:note?note.value:''
  });
};

window.dgSaveObjective=function(id){
  var text=document.querySelector('.dg-obj-text[data-id="'+id+'"]');
  var year=document.querySelector('.dg-obj-year[data-id="'+id+'"]');
  if(!text)return;
  post({
    action:'save_objective',
    id:id,
    objective_text:text.value,
    target_agm_year:year?year.value:'0'
  });
};
})();
</script>
<?php
$body = ob_get_clean();
ops_render_page('Poor ESG Designations', 'designations', $body, $flash ?: null, $flashT);
