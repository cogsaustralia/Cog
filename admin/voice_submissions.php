<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/ops_workflow.php';
ops_require_admin();
$pdo    = ops_db();
$flash  = '';
$flashT = 'ok';

// ── Filters ───────────────────────────────────────────────────────────────────
$filterStatus = in_array($_GET['status'] ?? '', ['pending_review','cleared_for_use','rejected','withdrawn'], true)
    ? $_GET['status'] : 'pending_review';
$filterType  = in_array($_GET['type'] ?? '', ['text','audio','video'], true) ? $_GET['type'] : '';
$filterState = trim((string)($_GET['state'] ?? ''));
$search      = trim((string)($_GET['q'] ?? ''));
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 25;

// ── Guard: table may not exist yet ────────────────────────────────────────────
$tableReady = ops_table_exists($pdo, 'member_voice_submissions');
$items      = [];
$total      = 0;
$totalPages = 1;

if ($tableReady) {
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
    $joinSQL  = 'FROM member_voice_submissions mvs
                 JOIN partners p ON p.id = mvs.partner_id
                 JOIN members  m ON m.id = p.member_id';

    $cnt = $pdo->prepare("SELECT COUNT(*) $joinSQL $whereSQL");
    $cnt->execute($params);
    $total      = (int)$cnt->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page       = min($page, $totalPages);
    $offset     = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        "SELECT mvs.id, mvs.submission_type, mvs.text_content, mvs.file_mime_type,
                mvs.duration_seconds, mvs.compliance_status,
                mvs.rejection_reason_to_member, mvs.used_in_post_url,
                mvs.created_at,
                COALESCE(mvs.display_name_first, m.first_name) AS disp_name,
                COALESCE(mvs.display_state, m.state_code) AS disp_state,
                m.email AS member_email, mvs.partner_id
         $joinSQL $whereSQL
         ORDER BY mvs.created_at ASC
         LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pendingCount = $tableReady
    ? (int)($pdo->query("SELECT COUNT(*) FROM member_voice_submissions WHERE compliance_status='pending_review'")->fetchColumn() ?: 0)
    : 0;

$banned = ['investment','returns','profit','upside','gains','ROI',
    'get in early',"don't miss out",'to the moon','presale',
    'IDO','IPO','token price','token launch','worth more later'];

$sLabels = [
    'pending_review'  => ['Pending',  'badge-warn'],
    'cleared_for_use' => ['Accepted', 'badge-ok'],
    'rejected'        => ['Rejected', 'badge-err'],
    'withdrawn'       => ['Withdrawn','badge-muted'],
];

$h    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$baseQ = '?status='.urlencode($filterStatus)
       .($filterType  ? '&type='.urlencode($filterType)  : '')
       .($filterState ? '&state='.urlencode($filterState) : '')
       .($search      ? '&q='.urlencode($search)          : '').'&';
$API   = '/_app/api/index.php?route=admin/voice-submissions/';

ob_start(); ?>
<style>
.vs-wrap{display:grid;grid-template-columns:210px 1fr 320px;height:calc(100vh - 130px);overflow:hidden;border:1px solid var(--line,#2a2a2a);border-radius:8px}
.vs-col{overflow-y:auto}
.vs-sidebar{padding:14px;border-right:1px solid var(--line,#2a2a2a)}
.vs-queue{border-right:1px solid var(--line,#2a2a2a)}
.vs-detail{padding:14px}
.vs-sidebar h3{font-size:.67rem;text-transform:uppercase;letter-spacing:.09em;color:var(--muted,#888);margin:12px 0 4px}
.vs-sidebar select,.vs-sidebar input[type=text]{width:100%;padding:5px 8px;margin-bottom:6px;background:rgba(255,255,255,.06);border:1px solid var(--line,#333);color:inherit;border-radius:4px;font-size:.81rem}
.vs-sidebar .btn-apply{width:100%;padding:7px;background:var(--gold,#c9973d);border:none;border-radius:4px;color:#000;font-weight:700;cursor:pointer;font-size:.81rem}
.vs-row{padding:10px 13px;border-bottom:1px solid rgba(255,255,255,.04);cursor:pointer;transition:background .1s}
.vs-row:hover,.vs-row.on{background:rgba(201,151,61,.09)}
.vs-row-meta{display:flex;align-items:center;gap:5px;flex-wrap:wrap;font-size:.75rem;color:var(--muted,#888);margin-bottom:2px}
.vs-row-preview{font-size:.81rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.badge{display:inline-block;padding:2px 6px;border-radius:99px;font-size:.66rem;font-weight:700}
.badge-warn{background:rgba(255,193,7,.15);color:#ffc107}
.badge-ok{background:rgba(82,184,122,.15);color:#52b87a}
.badge-err{background:rgba(220,53,69,.15);color:#dc3545}
.badge-muted{background:rgba(150,150,150,.15);color:#999}
.vs-pager{display:flex;gap:10px;align-items:center;padding:9px 13px;font-size:.75rem;color:var(--muted);border-top:1px solid rgba(255,255,255,.05)}
.vs-pager a{color:var(--gold,#c9973d);text-decoration:none;padding:1px 7px;border:1px solid var(--line,#333);border-radius:3px}
.vs-empty{padding:40px;text-align:center;color:var(--muted,#888);font-size:.85rem}
.vb{display:inline-block;padding:5px 11px;border-radius:4px;border:none;cursor:pointer;font-size:.8rem;font-weight:600;margin:2px 2px 2px 0;text-decoration:none;vertical-align:middle}
.vb-ok{background:#52b87a;color:#000}
.vb-err{background:#dc3545;color:#fff}
.vb-gold{background:var(--gold,#c9973d);color:#000}
.vb-grey{background:#555;color:#fff}
.vb-ghost{background:rgba(240,209,138,.08);border:1px solid rgba(240,209,138,.3);color:#f0d18a}
.vs-detail label{display:block;font-size:.68rem;text-transform:uppercase;letter-spacing:.07em;color:var(--muted,#888);margin:10px 0 3px}
.vs-detail textarea,.vs-detail input[type=text]{width:100%;padding:5px 8px;background:rgba(255,255,255,.05);border:1px solid var(--line,#333);color:inherit;border-radius:4px;font-size:.81rem;resize:vertical}
#vs-player{width:100%;border-radius:5px;margin-top:5px}
.vs-banned{background:rgba(220,53,69,.05);border:1px solid rgba(220,53,69,.16);border-radius:5px;padding:8px 10px;margin-top:12px}
.vs-banned h4{font-size:.66rem;text-transform:uppercase;letter-spacing:.08em;color:#dc3545;margin:0 0 5px}
.vs-banned ul{padding-left:13px;font-size:.74rem;line-height:1.65;margin:0}
#vs-toast{display:none;padding:6px 11px;border-radius:4px;font-size:.81rem;margin-bottom:8px}
.toast-ok{background:rgba(82,184,122,.12);border:1px solid rgba(82,184,122,.3);color:#52b87a}
.toast-err{background:rgba(220,53,69,.12);border:1px solid rgba(220,53,69,.3);color:#dc3545}
</style>

<div id="vs-toast"></div>

<?php if (!$tableReady): ?>
  <div class="alert alert-err">
    The <code>member_voice_submissions</code> table does not exist yet.
    Please run <code>phase4_member_voice_submissions_v1.sql</code> in phpMyAdmin against <code>cogsaust_TRUST</code> and reload.
  </div>
<?php else: ?>

<div class="vs-wrap">

  <!-- Sidebar filters -->
  <div class="vs-col vs-sidebar">
    <form method="get">
      <h3>Status</h3>
      <?php foreach (['pending_review'=>'Pending','cleared_for_use'=>'Accepted','rejected'=>'Rejected','withdrawn'=>'Withdrawn'] as $v=>$l): ?>
        <label style="font-size:.81rem;display:flex;align-items:center;gap:6px;margin-bottom:4px;cursor:pointer">
          <input type="radio" name="status" value="<?= $h($v) ?>" <?= $filterStatus===$v?'checked':'' ?>>
          <?= $h($l) ?>
          <?php if ($v==='pending_review'&&$pendingCount>0): ?><span class="badge badge-warn" style="margin-left:auto"><?= $pendingCount ?></span><?php endif ?>
        </label>
      <?php endforeach ?>

      <h3>Type</h3>
      <select name="type">
        <option value="">All types</option>
        <option value="text"  <?= $filterType==='text' ?'selected':'' ?>>Text</option>
        <option value="audio" <?= $filterType==='audio'?'selected':'' ?>>Audio</option>
        <option value="video" <?= $filterType==='video'?'selected':'' ?>>Video</option>
      </select>

      <h3>State</h3>
      <select name="state">
        <option value="">All states</option>
        <?php foreach (['NSW','QLD','VIC','SA','WA','TAS','ACT','NT'] as $s): ?>
          <option <?= $filterState===$s?'selected':'' ?>><?= $s ?></option>
        <?php endforeach ?>
      </select>

      <h3>Search</h3>
      <input type="text" name="q" value="<?= $h($search) ?>" placeholder="Name or text&hellip;">
      <button type="submit" class="btn-apply">Apply filters</button>
    </form>

    <div class="vs-banned">
      <h4>&sect;2 Banned &mdash; never accept</h4>
      <ul><?php foreach ($banned as $b): ?><li><?= $h($b) ?></li><?php endforeach ?></ul>
    </div>
  </div>

  <!-- Queue -->
  <div class="vs-col vs-queue" id="vs-queue">
    <?php if (empty($items)): ?>
      <div class="vs-empty">No <?= $h($filterStatus==='cleared_for_use'?'accepted':($filterStatus==='pending_review'?'pending':$filterStatus)) ?> submissions<?= $search?' matching &ldquo;'.$h($search).'&rdquo;':'' ?>.</div>
    <?php else: ?>
      <?php foreach ($items as $row):
        [$sl,$sc] = $sLabels[$row['compliance_status']] ?? ['?','badge-muted'];
        $ico = ['text'=>'&#x270F;','audio'=>'&#x1F399;','video'=>'&#x1F3AC;'][$row['submission_type']] ?? '';
        $prev = $row['submission_type']==='text'
          ? $h(mb_substr((string)$row['text_content'],0,80)).(mb_strlen((string)$row['text_content'])>80?'&hellip;':'')
          : $h(ucfirst($row['submission_type'])).($row['duration_seconds']?' &middot; '.$row['duration_seconds'].'s':'');
      ?>
        <div class="vs-row" data-id="<?= (int)$row['id'] ?>">
          <div class="vs-row-meta">
            <span><?= $ico ?></span>
            <strong style="color:inherit"><?= $h($row['disp_name']) ?>, <?= $h($row['disp_state']) ?></strong>
            <span class="badge <?= $h($sc) ?>"><?= $h($sl) ?></span>
            <span style="margin-left:auto"><?= $h(substr((string)$row['created_at'],0,16)) ?></span>
          </div>
          <div class="vs-row-preview"><?= $prev ?></div>
        </div>
      <?php endforeach ?>
      <?php if ($totalPages>1): ?>
        <div class="vs-pager">
          <?php if ($page>1): ?><a href="<?= $h($baseQ.'page='.($page-1)) ?>">&#8249;</a><?php endif ?>
          <span>Page <?= $page ?>/<?= $totalPages ?> (<?= number_format($total) ?>)</span>
          <?php if ($page<$totalPages): ?><a href="<?= $h($baseQ.'page='.($page+1)) ?>">&#8250;</a><?php endif ?>
        </div>
      <?php endif ?>
    <?php endif ?>
  </div>

  <!-- Detail -->
  <div class="vs-col vs-detail" id="vs-detail">
    <p style="color:var(--muted,#888);font-size:.81rem;text-align:center;margin-top:48px">Select a submission.</p>
  </div>

</div>

<script>
(function(){
'use strict';
var API='<?= $API ?>';
var sel=null;
var ITEMS=<?= json_encode(array_column($items,null,'id'),JSON_HEX_TAG|JSON_HEX_APOS) ?>;

function toast(msg,type){
  var el=document.getElementById('vs-toast');
  el.textContent=msg; el.className=type==='err'?'toast-err':'toast-ok';
  el.style.display='block';
  setTimeout(function(){el.style.display='none';},4000);
}
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

// Row click
document.querySelectorAll('.vs-row').forEach(function(el){
  el.addEventListener('click',function(){vsSelect(parseInt(el.dataset.id,10));});
});

function vsSelect(id){
  sel=id;
  document.querySelectorAll('.vs-row').forEach(function(el){
    el.classList.toggle('on',parseInt(el.dataset.id,10)===id);
  });
  if(ITEMS[id]){renderDetail(ITEMS[id]);return;}
  fetch(API+id,{credentials:'include'})
    .then(function(r){return r.json();})
    .then(function(d){if(d.success)renderDetail(d.data||d);else toast('Load failed: '+(d.error||'?'),'err');})
    .catch(function(){toast('Network error','err');});
}

function renderDetail(item){
  var pane=document.getElementById('vs-detail');
  var st=item.compliance_status;
  var isFile=(item.submission_type==='audio'||item.submission_type==='video');
  var furl=isFile?(API+item.id+'/file'):'';
  var SM={pending_review:'Pending',cleared_for_use:'Accepted',rejected:'Rejected',withdrawn:'Withdrawn'};
  var SC={pending_review:'badge-warn',cleared_for_use:'badge-ok',rejected:'badge-err',withdrawn:'badge-muted'};

  var o='<h3 style="font-size:.93rem;margin:0 0 7px">#'+item.id
       +' <span class="badge '+(SC[st]||'')+'">'+(SM[st]||st)+'</span></h3>';
  o+='<p style="font-size:.74rem;color:var(--muted,#888);margin:0 0 10px">'
    +esc((item.disp_name||'')+', '+(item.disp_state||''))+' &middot; '+esc(item.submission_type||'')
    +' &middot; '+esc((item.created_at||'').slice(0,16))+'</p>';

  if(item.submission_type==='text'){
    o+='<div id="vs-tb" style="background:rgba(255,255,255,.05);border-radius:5px;padding:10px;font-size:.88rem;line-height:1.6;white-space:pre-wrap;margin-bottom:6px">'+esc(item.text_content||'')+'</div>';
    if(st==='cleared_for_use') o+='<button class="vb vb-ghost" onclick="vsCopy()" style="font-size:.75rem">&#128203; Copy text</button>';
  } else if(isFile){
    var vid=(item.file_mime_type||'').indexOf('video')===0;
    o+='<'+(vid?'video':'audio')+' id="vs-player" controls src="'+esc(furl)+'"></'+(vid?'video':'audio')+'>';
    if(item.duration_seconds) o+='<p style="font-size:.73rem;color:var(--muted,#888);margin:3px 0">'+esc(item.duration_seconds)+'s</p>';
    if(st==='cleared_for_use') o+='<a class="vb vb-ghost" href="'+esc(furl+'?download=1')+'" download style="font-size:.75rem;margin-top:6px;display:inline-block">&#8675; Download file</a>';
  }

  if(item.used_in_post_url) o+='<label>Used in post</label><p style="font-size:.76rem;word-break:break-all;margin:0"><a href="'+esc(item.used_in_post_url)+'" target="_blank" rel="noopener">'+esc(item.used_in_post_url)+'</a></p>';
  if(item.rejection_reason_to_member) o+='<label>Rejection reason (shown to member)</label><p style="font-size:.78rem;white-space:pre-wrap;background:rgba(220,53,69,.06);padding:7px;border-radius:4px;margin:0">'+esc(item.rejection_reason_to_member)+'</p>';

  o+='<div style="margin-top:12px">';
  if(st==='pending_review'){o+='<button class="vb vb-ok" onclick="vsAccept()">&#10003; Accept</button><button class="vb vb-err" onclick="vsRejOpen()">&#10007; Reject</button>';}
  if(st==='cleared_for_use'){o+='<button class="vb vb-gold" onclick="vsUsedOpen()">&#128279; Mark as used</button>';}
  if(st==='pending_review'||st==='cleared_for_use'){o+='<button class="vb vb-grey" onclick="vsWdOpen()">Withdraw</button>';}
  o+='</div>';

  o+='<label>Internal notes (not shown to member)</label><textarea id="vs-notes" rows="2" placeholder="Optional&hellip;"></textarea>';

  o+='<div id="vs-rej" style="display:none;margin-top:5px">'
    +'<label>Reason shown to member <span style="color:#dc3545">*</span></label>'
    +'<textarea id="vs-rej-r" rows="3" placeholder="Thanks for sending this through. Could you rephrase to focus on why you joined the community?"></textarea>'
    +'<button class="vb vb-err" style="margin-top:3px" onclick="vsRejDo()">Confirm reject</button> '
    +'<button class="vb vb-grey" onclick="hide(\'vs-rej\')">Cancel</button></div>';

  o+='<div id="vs-used" style="display:none;margin-top:5px">'
    +'<label>Post URL (FB or YT)</label>'
    +'<input type="text" id="vs-used-u" placeholder="https://&hellip;">'
    +'<button class="vb vb-gold" style="margin-top:3px" onclick="vsUsedDo()">Confirm</button> '
    +'<button class="vb vb-grey" onclick="hide(\'vs-used\')">Cancel</button></div>';

  o+='<div id="vs-wd" style="display:none;margin-top:5px">'
    +'<label>Reason (internal, optional)</label>'
    +'<input type="text" id="vs-wd-r" placeholder="Reason&hellip;">'
    +'<button class="vb vb-grey" style="margin-top:3px" onclick="vsWdDo()">Confirm withdraw</button> '
    +'<button class="vb vb-grey" onclick="hide(\'vs-wd\')">Cancel</button></div>';

  pane.innerHTML=o;
}

function hide(id){var e=document.getElementById(id);if(e)e.style.display='none';}
function tog(id){var e=document.getElementById(id);if(e)e.style.display=e.style.display==='none'?'block':'none';}
function notes(){return(document.getElementById('vs-notes')||{}).value||'';}

function post(path,body,cb){
  fetch(API+path,{method:'POST',credentials:'include',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)})
    .then(function(r){
      var status = r.status;
      return r.text().then(function(text){
        var d;
        try { d = JSON.parse(text); } catch(e) {
          toast('Server error ('+status+'): '+text.slice(0,120),'err');
          return;
        }
        if(d && d.success) cb(d);
        else toast('Error: '+(d&&d.error?d.error:'unknown — status '+status),'err');
      });
    })
    .catch(function(e){ toast('Network error: '+(e&&e.message?e.message:'connection failed'),'err'); });
}

function vsAccept(){if(!sel)return;post(sel+'/approve',{notes:notes()},function(){toast('Accepted. Member notified.');location.href='?status=cleared_for_use';});}
function vsRejOpen(){tog('vs-rej');}
function vsRejDo(){
  if(!sel)return;
  var r=(document.getElementById('vs-rej-r')||{}).value||'';
  if(!r.trim()){toast('Please write a reason for the member.','err');return;}
  post(sel+'/reject',{compliance_notes:notes(),rejection_reason_to_member:r},function(){toast('Rejected. Member notified.');location.reload();});
}
function vsUsedOpen(){tog('vs-used');}
function vsUsedDo(){
  if(!sel)return;
  var u=(document.getElementById('vs-used-u')||{}).value||'';
  if(!u.trim()){toast('Please enter the post URL.','err');return;}
  post(sel+'/mark-used',{used_in_post_url:u},function(){toast('Marked as used.');location.reload();});
}
function vsWdOpen(){tog('vs-wd');}
function vsWdDo(){
  if(!sel)return;
  var r=(document.getElementById('vs-wd-r')||{}).value||'';
  post(sel+'/withdraw',{reason:r},function(d){
    toast(d.data&&d.data.social_removal?'Withdrawn. Take down the social post within 24 hours.':'Withdrawn.');
    location.reload();
  });
}
function vsCopy(){
  var el=document.getElementById('vs-tb');
  var t=el?(el.textContent||el.innerText||''):'';
  if(!t)return;
  if(navigator.clipboard&&navigator.clipboard.writeText){
    navigator.clipboard.writeText(t).then(function(){toast('Copied.');}).catch(function(){fbCopy(t);});
  }else fbCopy(t);
}
function fbCopy(t){
  var ta=document.createElement('textarea');ta.value=t;ta.style.cssText='position:fixed;opacity:0';
  document.body.appendChild(ta);ta.select();
  try{document.execCommand('copy');toast('Copied.');}catch(e){toast('Select text manually.','err');}
  document.body.removeChild(ta);
}

<?php if(count($items)===1&&!empty($items[0]['id'])): ?>vsSelect(<?=(int)$items[0]['id']?>);<?php endif ?>

window.vsSelect=vsSelect;window.vsAccept=vsAccept;
window.vsRejOpen=vsRejOpen;window.vsRejDo=vsRejDo;
window.vsUsedOpen=vsUsedOpen;window.vsUsedDo=vsUsedDo;
window.vsWdOpen=vsWdOpen;window.vsWdDo=vsWdDo;
window.vsCopy=vsCopy;
})();
</script>
<?php endif; ?>
<?php
$body = ob_get_clean();
ops_render_page('Why I Joined', 'voice_submissions', $body, $flash ?: null, $flashT);
