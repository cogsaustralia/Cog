/* =============================================================================
   hub.js — COG$ Management Hubs shared logic
   Loaded by all 9 pathway hub pages. Each page sets:
     window.HUB_AREA_KEY   — e.g. 'community_projects'
     window.HUB_LABEL      — human label e.g. 'Community Projects'
     window.HUB_STATUS     — 'live' | 'soon'
     window.HUB_TIP        — overview paragraph text
   The Mainspring Hub (/hubs/mainspring/) has its own inline script.
   ============================================================================= */
(function(){
'use strict';

/* ── Config ─────────────────────────────────────────────────────────────────── */
var ROOT = (function(){
  // hub pages are two levels deep: /hubs/<area>/index.html
  var s = document.body.dataset.root;
  if(s) return s;
  // derive from path
  var p = window.location.pathname; // e.g. /hubs/community_projects/index.html
  var parts = p.split('/').filter(Boolean);
  // strip filename if present
  if(parts.length && parts[parts.length-1].indexOf('.') !== -1) parts.pop();
  var depth = parts.length; // typically 2 for /hubs/<area>/
  var up = '';
  for(var i=0;i<depth;i++) up += '../';
  return up || '../../';
})();
var API = ROOT + '_app/api/index.php?route=';

/* ── State ──────────────────────────────────────────────────────────────────── */
var _hubData       = null;   // response from GET /vault/hub
var _enrolled      = false;
var _rosterPage    = 1;
var _rosterTotal   = 0;
var _rosterPer     = 20;
var _forumTab      = 'threads'; // 'threads' | 'broadcasts'
var _view          = 'hub';    // 'hub' | 'project'
var _projectId     = null;
var _projectData   = null;
var _openThreads   = {};       // threadId → true
var _resolvedQueries = null;  // cached resolved-query list for this hub

/* ── Utility ────────────────────────────────────────────────────────────────── */
function el(id){ return document.getElementById(id); }

function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function dt(s){
  if(!s) return '—';
  try{ return new Date(s).toLocaleDateString('en-AU',{day:'2-digit',month:'short',year:'numeric'}); }catch(e){ return s; }
}
function dts(s){
  if(!s) return '—';
  try{ return new Date(s).toLocaleDateString('en-AU',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}); }catch(e){ return s; }
}

function flash(id, msg, kind){
  var e = el(id); if(!e) return;
  e.textContent = msg;
  e.className = 'flash show flash-'+(kind||'info');
  if(!msg) e.className='flash';
}

function coinTransition(href, label){
  label = label || 'Opening…';
  window._hubNavigating = true;
  var ov = document.createElement('div');
  ov.className = 'coin-transition';
  ov.innerHTML = '<img src="'+ROOT+'assets/cogs_coin_web.png" alt=""><div class="ct-label">'+esc(label)+'</div>';
  document.body.appendChild(ov);
  requestAnimationFrame(function(){ ov.classList.add('active'); });
  setTimeout(function(){ window.location.href = href; }, 1700);
}

function hideSplash(){
  var s = el('splash');
  if(s){ s.classList.add('hidden'); setTimeout(function(){ s.style.display='none'; }, 450); }
}

/* ── API fetch ──────────────────────────────────────────────────────────────── */
async function api(route, opts){
  opts = opts || {};
  var ctrl = new AbortController();
  var tid = setTimeout(function(){ ctrl.abort(); }, 20000);
  try{
    var r = await fetch(API + route, Object.assign({
      credentials:'include',
      headers:{'Content-Type':'application/json','Accept':'application/json'},
      signal: ctrl.signal
    }, opts));
    var txt = await r.text();
    var j; try{ j=JSON.parse(txt); }catch(e){ j={success:false,error:txt}; }
    if(!r.ok || j.success===false){
      var err = new Error(j.error||'Request failed ('+r.status+')');
      err.status = r.status;
      throw err;
    }
    return j.data !== undefined ? j.data : j;
  }catch(e){
    if(e&&e.name==='AbortError'){ var te=new Error('Request timed out.'); te.status=0; throw te; }
    throw e;
  }finally{
    clearTimeout(tid);
  }
}

/* ── Boot ───────────────────────────────────────────────────────────────────── */
async function boot(){
  // Apply label and status from per-page constants
  var label  = window.HUB_LABEL  || 'Management Hub';
  var status = window.HUB_STATUS || 'live';
  var areaKey= window.HUB_AREA_KEY || '';

  document.title = label + ' Hub — COG$ of Australia Foundation';

  var titleEl = el('hub-title');
  if(titleEl) titleEl.textContent = label + ' Hub';

  var pillEl = el('hub-status-pill');
  if(pillEl){
    pillEl.textContent = status === 'soon' ? 'Activates at Expansion Day' : 'Live';
    pillEl.className = 'hub-status-pill ' + (status === 'soon' ? 'soon' : 'live');
  }

  var tipEl = el('hub-overview-text');
  if(tipEl && window.HUB_TIP){
    var lines = window.HUB_TIP.split('\\n\\n');
    tipEl.innerHTML = lines.map(function(l){ return '<p>'+esc(l.trim())+'</p>'; }).join('');
  }

  // Fetch hub data (also validates auth — 401 redirects to members hub)
  if(!areaKey){
    hideSplash();
    renderError('Hub configuration error: no area key set.');
    return;
  }

  try{
    var [_d] = await Promise.all([
      api('vault/hub&area='+areaKey),
      fetchResolvedQueries(),
    ]);
    _hubData = _d;
  }catch(e){
    hideSplash();
    var status = e && e.status;
    var msg    = (e&&e.message)||'';
    // Only redirect to partners on genuine auth failure (401/403)
    // For server errors, show an inline message so the user knows what happened
    if(status === 401 || status === 403 || /not.*auth|log.*in|session/i.test(msg)){
      window._hubNavigating = true;
      window.location.replace(ROOT+'partners/index.html?next=vault');
    }else{
      renderError(
        'Could not load hub data.' +
        (status ? ' (Error '+status+')' : '') +
        '<br><br><small style="color:var(--text3)">' + esc(msg) + '</small>' +
        '<br><br><a href="' + ROOT + 'wallets/member.html" style="color:var(--gold)">' +
        '← Return to Vault</a>'
      );
    }
    return;
  }

  _enrolled = !!_hubData.enrolled;
  hideSplash();
  renderAll();
}

/* ── Render orchestrator ────────────────────────────────────────────────────── */
function renderAll(){
  renderEnrolBanner();
  renderSummaryStats();
  renderOverview();
  renderRoster();
  renderForum();
  renderProjects();
}

/* ── Enrolment banner ───────────────────────────────────────────────────────── */
function renderEnrolBanner(){
  var b = el('hub-enrol-banner');
  if(!b) return;
  if(_enrolled){
    b.className = 'hub-enrol-banner enrolled';
    b.innerHTML =
      '<span class="hub-enrol-text"><span class="hub-enrol-dot active"></span>You are active in this hub — you can post, create projects, and join discussions.</span>' +
      '<button class="hub-leave-btn" onclick="hubLeave()">Leave this hub</button>';
  }else{
    b.className = 'hub-enrol-banner';
    b.innerHTML =
      '<span class="hub-enrol-text"><span class="hub-enrol-dot"></span>You are viewing in read-only mode. Activate to post, create projects, and join discussions.</span>' +
      '<button class="hub-activate-btn" id="activate-btn" onclick="hubJoin()">⬡ Activate Participation</button>';
  }
  // Roster visibility toggle
  var rv = el('hub-roster-vis-wrap');
  if(rv){
    var vis = !!_hubData.roster_visible;
    var showNameBtn = vis
      ? ' <button class="hub-roster-toggle" onclick="toggleShowName()" id="sn-btn" style="margin-left:8px">'+(_hubData.show_name?'✓ Name shown':'○ Show my name')+'</button>'
      : '';
    rv.innerHTML =
      '<button class="hub-roster-toggle" onclick="toggleRosterVis()" id="rv-btn">'+(vis?'✓ Visible on roster':'○ Hidden from roster')+'</button>' +
      showNameBtn;
  }
}

/* ── Summary stats ──────────────────────────────────────────────────────────── */
function renderSummaryStats(){
  var s = _hubData.summary || {};
  var rows = [
    {id:'stat-members', n: s.member_count||0, l:'Members'},
    {id:'stat-threads', n: s.thread_count||0, l:'Forum threads'},
    {id:'stat-projects',n: s.active_project_count||0, l:'Active projects'},
  ];
  rows.forEach(function(r){
    var e = el(r.id);
    if(e) e.innerHTML = '<div class="hub-stat-n">'+Number(r.n).toLocaleString('en-AU')+'</div><div class="hub-stat-l">'+r.l+'</div>';
  });
  // Unread badge in topbar
  var unread = ((_hubData.unread_broadcasts||0) + (_hubData.unread_threads||0));
  var ub = el('hub-unread-badge');
  if(ub){
    ub.textContent = unread;
    ub.style.display = unread > 0 ? '' : 'none';
  }
}

/* ── Overview ───────────────────────────────────────────────────────────────── */
function renderOverview(){
  // Already rendered from HUB_TIP in boot(); nothing dynamic needed here.
}

/* ── Roster ─────────────────────────────────────────────────────────────────── */
async function renderRoster(page){
  page = page || _rosterPage;
  _rosterPage = page;
  var wrap = el('hub-roster-wrap');
  if(!wrap) return;
  wrap.innerHTML = '<div class="hub-loading">Loading roster…</div>';

  try{
    var areaKey = window.HUB_AREA_KEY;
    var d = await api('vault/hub-roster&area='+areaKey+'&page='+page+'&per='+_rosterPer);
    _rosterTotal = d.total || 0;
    var members = d.members || [];
    if(!members.length){
      wrap.innerHTML = '<div class="hub-empty">No members have opted into this hub yet.</div>';
      return;
    }
    var cards = members.map(function(m){
      return '<div class="hub-roster-card">' +
        '<div class="hub-roster-name">'+(m.first_name ? esc(m.first_name) : '<span style="color:var(--text3);font-style:italic">Anonymous member</span>')+'</div>' +
        '<div class="hub-roster-meta">'+esc(m.state_code||'')+(m.state_code&&m.suburb?' · ':'')+esc(m.suburb||'')+'</div>' +
        '<div class="hub-roster-meta" style="margin-top:4px;font-size:.75rem">'+esc(m.member_number_masked||'')+'</div>' +
        '<div class="hub-roster-meta" style="margin-top:4px">Joined '+dt(m.joined_area_at)+'</div>' +
        '</div>';
    }).join('');

    var totalPages = Math.ceil(_rosterTotal / _rosterPer);
    var pager = '<div class="hub-roster-pagination">' +
      '<button onclick="renderRoster('+(page-1)+')" '+(page<=1?'disabled':'')+'>← Prev</button>' +
      '<span>'+((page-1)*_rosterPer+1)+'–'+Math.min(page*_rosterPer,_rosterTotal)+' of '+_rosterTotal.toLocaleString('en-AU')+'</span>' +
      '<button onclick="renderRoster('+(page+1)+')" '+(page>=totalPages?'disabled':'')+'>Next →</button>' +
      '</div>';

    wrap.innerHTML = '<div class="hub-roster-grid">'+cards+'</div>'+(totalPages>1?pager:'');
  }catch(e){
    wrap.innerHTML = '<div class="hub-empty">Could not load roster: '+esc(e.message)+'</div>';
  }
}

/* ── Forum ──────────────────────────────────────────────────────────────────── */
function renderForum(){
  renderForumTab(_forumTab);
}

function switchForumTab(tab){
  _forumTab = tab;
  var tabs = document.querySelectorAll('.hub-tab');
  tabs.forEach(function(t){ t.classList.toggle('on', t.dataset.tab===tab); });
  renderForumTab(tab);
}

function renderForumTab(tab){
  var wrap = el('hub-forum-list');
  if(!wrap) return;
  var threads = _hubData.threads || [];

  var items;
  if(tab==='broadcasts'){
    items = threads.filter(function(t){ return t.direction==='broadcast'; });
  }else{
    items = threads.filter(function(t){ return t.direction==='inbound'; });
  }

  if(!items.length){
    wrap.innerHTML = '<div class="hub-empty">'+(tab==='broadcasts'?'No broadcasts yet.':'No threads yet — be the first to start a discussion.')+'</div>';
    renderCompose();
    return;
  }

  wrap.innerHTML = items.map(function(t){
    var isOpen = !!_openThreads[t.id];
    var unread = !t.read_at && t.direction==='broadcast';
    return '<div class="hub-thread'+(unread?' unread':'')+'" id="thread-'+t.id+'">' +
      '<div class="hub-thread-hd" onclick="toggleThread('+t.id+')">' +
        '<div>' +
          '<div class="hub-thread-subject">'+esc(t.subject)+'</div>' +
          '<div class="hub-thread-meta">'+(t.author_first_name||'Foundation')+' · '+dts(t.created_at)+(t.reply_count>0?' · '+t.reply_count+' repl'+(t.reply_count===1?'y':'ies'):'')+'</div>' +
        '</div>' +
        '<span class="hub-thread-chevron'+(isOpen?' open':'')+'">▾</span>' +
      '</div>' +
      '<div class="hub-thread-body'+(isOpen?' open':'')+'" id="thread-body-'+t.id+'">' +
        '<div class="hub-thread-text">'+esc(t.body)+'</div>' +
        (tab==='inbound'||tab==='threads' ? renderThreadReplyForm(t) : '') +
      '</div>' +
    '</div>';
  }).join('');

  renderCompose();
}

function renderThreadReplyForm(t){
  if(!_enrolled) return '';
  return '<div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--border)">' +
    '<textarea class="hub-textarea" id="reply-'+t.id+'" placeholder="Reply to this thread…" rows="3" style="margin-bottom:6px"></textarea>' +
    '<button class="btn btn-gold btn-sm" onclick="postReply('+t.id+')">Post Reply</button>' +
    '<div class="flash" id="reply-fl-'+t.id+'"></div>' +
    '</div>';
}

function toggleThread(id){
  _openThreads[id] = !_openThreads[id];
  var body = el('thread-body-'+id);
  var chev = document.querySelector('#thread-'+id+' .hub-thread-chevron');
  if(body) body.classList.toggle('open', !!_openThreads[id]);
  if(chev) chev.classList.toggle('open', !!_openThreads[id]);

  // Mark broadcast read
  var t = (_hubData.threads||[]).find(function(x){ return x.id==id; });
  if(t && t.direction==='broadcast' && !t.read_at && _enrolled){
    api('vault/partner-op-read',{method:'POST',body:JSON.stringify({thread_id:id})}).catch(function(){});
    t.read_at = new Date().toISOString();
  }
}

async function postReply(threadId){
  var ta = el('reply-'+threadId);
  var fl = 'reply-fl-'+threadId;
  if(!ta) return;
  var body = ta.value.trim();
  if(!body){ flash(fl,'Reply cannot be empty.','err'); return; }
  try{
    await api('vault/partner-op-reply',{method:'POST',body:JSON.stringify({thread_id:threadId,body:body})});
    ta.value='';
    flash(fl,'Reply posted.','ok');
    // Refresh hub data thread list
    var d = await api('vault/hub&area='+window.HUB_AREA_KEY);
    _hubData.threads = d.threads || _hubData.threads;
    renderForumTab(_forumTab);
  }catch(e){
    flash(fl,e.message||'Could not post reply.','err');
  }
}

function renderCompose(){
  var wrap = el('hub-compose-wrap');
  if(!wrap) return;

  if(!_enrolled){
    wrap.innerHTML = '<div class="hub-gate-msg">Activate participation in this hub to start or reply to threads.</div>';
    return;
  }
  wrap.innerHTML =
    '<div class="hub-compose">' +
      '<div class="hub-compose-title">Start a new thread</div>' +
      '<div class="hub-compose-disclaimer">Posts are visible to all members enrolled in this hub. The Foundation reviews all content. Keep discussion relevant to this governance area.</div>' +
      '<input class="hub-input" id="compose-subject" placeholder="Subject…" maxlength="255">' +
      '<textarea class="hub-textarea" id="compose-body" placeholder="Your message…" rows="4"></textarea>' +
      '<button class="btn btn-gold" onclick="postThread()">Post to Forum</button>' +
      '<div class="flash" id="compose-fl"></div>' +
    '</div>';
}

async function postThread(){
  var subj = (el('compose-subject')||{}).value||'';
  var body = (el('compose-body')||{}).value||'';
  subj = subj.trim(); body = body.trim();
  if(!subj){ flash('compose-fl','Subject is required.','err'); return; }
  if(!body){ flash('compose-fl','Message body is required.','err'); return; }
  var btn = document.querySelector('#hub-compose-wrap .btn-gold');
  if(btn){ btn.disabled=true; btn.textContent='Posting…'; }
  try{
    await api('vault/partner-op-threads',{method:'POST',body:JSON.stringify({area_key:window.HUB_AREA_KEY,subject:subj,body:body})});
    flash('compose-fl','Thread posted.','ok');
    if(el('compose-subject')) el('compose-subject').value='';
    if(el('compose-body')) el('compose-body').value='';
    // Refresh
    var d = await api('vault/hub&area='+window.HUB_AREA_KEY);
    _hubData.threads = d.threads || _hubData.threads;
    _hubData.summary = d.summary || _hubData.summary;
    renderSummaryStats();
    renderForumTab(_forumTab);
  }catch(e){
    flash('compose-fl',e.message||'Could not post thread.','err');
    if(btn){ btn.disabled=false; btn.textContent='Post to Forum'; }
  }
}

/* ── Resolved queries ──────────────────────────────────────────────────────── */
async function fetchResolvedQueries(){
  if(!window.HUB_AREA_KEY) return;
  try{
    var res = await api('vault/hub-resolved-queries&area_key='+window.HUB_AREA_KEY);
    _resolvedQueries = res.queries || [];
  }catch(e){
    _resolvedQueries = []; // silent fail — table may not be migrated yet
  }
}

/* ── Projects ───────────────────────────────────────────────────────────────── */

var _PHASE_LABELS = {
  draft:           'Draft',
  open_for_input:  'Open for Input',
  deliberation:    'Deliberation',
  vote:            'Vote Open',
  accountability:  'Accountability',
  // legacy
  proposed:        'Proposed',
  active:          'Active',
  paused:          'Paused',
  completed:       'Completed',
  archived:        'Archived',
};

var _PHASE_NEXT_LABELS = {
  draft:          'Open for Input',
  open_for_input: 'Move to Deliberation',
  deliberation:   'Open Voting',
  vote:           'Adopt — Begin Accountability',
};

function phaseLabel(status){ return _PHASE_LABELS[status] || status; }

function renderProjects(){
  if(_view==='project'){ renderProjectDetail(); return; }
  var wrap = el('hub-projects-wrap');
  if(!wrap) return;
  var projects = _hubData.projects || [];

  var createBtn = _enrolled
    ? '<button class="btn btn-gold btn-sm" onclick="showCreateProject()">+ New Project</button>'
    : '';

  var hd = el('hub-projects-hd-action');
  if(hd) hd.innerHTML = createBtn;

  if(!projects.length){
    var rqEmptyHtml = (_resolvedQueries && _resolvedQueries.length)
      ? '<div style="background:rgba(62,207,110,.04);border:1px solid rgba(62,207,110,.15);border-radius:8px;padding:8px 14px;margin-bottom:12px">'
        + '<span style="font-size:.75rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--green)">'
        + '✓ Resolved this month ('+_resolvedQueries.length+')</span></div>'
      : '';
    wrap.innerHTML = rqEmptyHtml
      + '<div class="hub-empty">No projects in this hub yet.</div>'
      + (_enrolled ? '<div id="create-project-form-wrap"></div>' : '<div class="hub-gate-msg">Activate participation to create the first project.</div>');
    return;
  }

  var cards = projects.map(function(p){
    var statusCls = p.status||'draft';
    var joinedMark = p.joined_by_me ? ' <span style="color:var(--green);font-size:.78rem">✓ Joined</span>' : '';
    var phaseEnd = p.phase_target_end_at
      ? '<span>Phase ends: '+dt(p.phase_target_end_at)+'</span>'
      : (p.target_close_at ? '<span>Target: '+dt(p.target_close_at)+'</span>' : '');
    return '<div class="hub-project-card" onclick="openProject('+p.id+')">' +
      '<div class="hub-project-row">' +
        '<div class="hub-project-title">'+esc(p.title)+'</div>' +
        '<span class="hub-status-chip '+statusCls+'">'+esc(phaseLabel(statusCls))+'</span>' +
      '</div>' +
      (p.summary?'<div class="hub-project-summary">'+esc(p.summary.substring(0,120))+(p.summary.length>120?'…':'')+'</div>':'') +
      '<div class="hub-project-footer">' +
        '<span>'+p.participant_count+' participant'+(p.participant_count===1?'':'s')+'</span>' +
        phaseEnd +
        joinedMark +
      '</div>' +
    '</div>';
  }).join('');

  // Resolved-queries block — fetched on boot, shown above project list
  var rqHtml = '';
  if(_resolvedQueries && _resolvedQueries.length){
    var rqItems = _resolvedQueries.slice(0,5).map(function(q){
      var excerpt = q.resolution_excerpt
        ? '<div style="font-size:.78rem;color:var(--text3);margin-top:2px;padding-left:20px">'+esc(q.resolution_excerpt)+(q.resolution_excerpt.length===280?'…':'')+'</div>'
        : '';
      return '<div style="padding:5px 0;border-bottom:1px solid rgba(255,255,255,.05)">'
        + '<span style="color:var(--green);margin-right:6px;font-size:.85rem">✓</span>'
        + '<span style="font-size:.85rem">'+esc(q.subject)+'</span>'
        + ' <span style="font-size:.75rem;color:var(--text3)">· '+dts(q.resolved_at)+'</span>'
        + excerpt
        + '</div>';
    }).join('');
    rqHtml = '<div style="background:rgba(62,207,110,.04);border:1px solid rgba(62,207,110,.15);border-radius:8px;padding:10px 14px;margin-bottom:14px">'
      + '<div style="font-size:.75rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--green);margin-bottom:6px">'
      + '✓ Resolved this month ('+_resolvedQueries.length+')</div>'
      + rqItems
      + '</div>';
  }

  wrap.innerHTML = '<div id="rq-block">'+rqHtml+'</div>'
    + '<div class="hub-project-list">'+cards+'</div>'
    + '<div id="create-project-form-wrap"></div>';
}

function showCreateProject(){
  var fw = el('create-project-form-wrap');
  if(!fw) return;
  if(fw.innerHTML){fw.innerHTML=''; return;} // toggle
  fw.innerHTML =
    '<div class="hub-compose" style="margin-top:16px">' +
      '<div class="hub-compose-title">Create a new project</div>' +
      '<input class="hub-input" id="cp-title" placeholder="Project title (required)" maxlength="255">' +
      '<textarea class="hub-textarea" id="cp-summary" placeholder="Summary (what is this project about?)" rows="3"></textarea>' +
      '<textarea class="hub-textarea" id="cp-body" placeholder="Full description, goals, resources needed… (optional)" rows="4" style="margin-top:0"></textarea>' +
      '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px">' +
        '<label style="font-size:.88rem;color:var(--text3)">Target close date (optional)</label>' +
        '<input class="hub-input" id="cp-date" type="date" style="width:auto;flex:none">' +
      '</div>' +
      '<button class="btn btn-gold" onclick="submitCreateProject()">Create Project</button>' +
      '<button class="btn btn-ghost" style="margin-left:8px" onclick="cancelCreateProject()">Cancel</button>' +
      '<div class="flash" id="cp-fl"></div>' +
    '</div>';
}

function cancelCreateProject(){
  var fw = el('create-project-form-wrap');
  if(fw) fw.innerHTML='';
}

async function submitCreateProject(){
  var title   = (el('cp-title')||{}).value||'';
  var summary = (el('cp-summary')||{}).value||'';
  var body    = (el('cp-body')||{}).value||'';
  var date    = (el('cp-date')||{}).value||'';
  title = title.trim();
  if(!title){ flash('cp-fl','Project title is required.','err'); return; }
  var btn = document.querySelector('#create-project-form-wrap .btn-gold');
  if(btn){ btn.disabled=true; btn.textContent='Creating…'; }
  try{
    var res = await api('vault/hub-projects',{method:'POST',body:JSON.stringify({
      area_key: window.HUB_AREA_KEY,
      title:title, summary:summary.trim(), body:body.trim(),
      target_close_at: date||undefined
    })});
    cancelCreateProject();
    // Refresh projects
    var d2 = await api('vault/hub&area='+window.HUB_AREA_KEY);
    _hubData.projects = d2.projects || _hubData.projects;
    _hubData.summary  = d2.summary  || _hubData.summary;
    renderSummaryStats();
    renderProjects();
    // Auto-open the new project
    if(res.project_id) openProject(res.project_id);
  }catch(e){
    flash('cp-fl',e.message||'Could not create project.','err');
    if(btn){ btn.disabled=false; btn.textContent='Create Project'; }
  }
}

/* ── Project detail ─────────────────────────────────────────────────────────── */
async function openProject(id){
  _view = 'project';
  _projectId = id;
  var mainWrap = el('hub-main-content');
  if(mainWrap) mainWrap.style.display='none';
  var detailWrap = el('hub-project-detail');
  if(detailWrap){ detailWrap.style.display=''; detailWrap.innerHTML='<div class="hub-loading">Loading project…</div>'; }
  try{
    _projectData = await api('vault/hub-project&id='+id);
    renderProjectDetail();
  }catch(e){
    if(detailWrap) detailWrap.innerHTML='<div class="hub-empty">Could not load project: '+esc(e.message)+'</div>';
  }
}

function renderProjectDetail(){
  var wrap = el('hub-project-detail');
  if(!wrap||!_projectData) return;
  var p = _projectData.project;
  var parts = _projectData.participants||[];
  var comments = _projectData.comments||[];
  var myRole = _projectData.my_role;
  var enrolledInArea = !!_projectData.enrolled_in_area;

  var participantsHtml = parts.map(function(m){
    return '<span class="hub-participant-chip'+(m.role==='coordinator'?' coord':'')+'">'+esc(m.first_name)+(m.role==='coordinator'?' (Coordinator)':'')+'</span>';
  }).join('');

  var joinBtn = '';
  if(enrolledInArea && !myRole && p.status!=='completed'&&p.status!=='archived'){
    joinBtn = '<button class="btn btn-gold btn-sm" onclick="joinProject('+p.id+')" id="join-proj-btn">Join this project</button>';
  }
  if(myRole==='coordinator'){
    joinBtn = '<span class="hub-status-chip active">You are the coordinator</span>';
  }else if(myRole){
    joinBtn = '<span class="hub-status-chip active">You are a participant</span> <button class="btn btn-ghost btn-sm" onclick="leaveProject('+p.id+')" style="margin-left:6px">Leave</button>';
  }

  // Phase banner — shown for lifecycle-phase projects (not legacy statuses)
  var LIFECYCLE_PHASES = ['draft','open_for_input','deliberation','vote','accountability'];
  var isLifecycle = LIFECYCLE_PHASES.indexOf(p.status) !== -1;
  var phaseBanner = '';
  if(isLifecycle){
    var phaseEndStr = p.phase_target_end_at
      ? '<span style="color:var(--text3);font-size:.82rem"> · Phase target end: '+dt(p.phase_target_end_at)+'</span>'
      : '';
    var advanceBtn = '';
    if(myRole==='coordinator' && p.status !== 'accountability'){
      var nextLbl = _PHASE_NEXT_LABELS[p.status] || 'Advance Phase';
      advanceBtn = '<button class="btn btn-gold btn-sm" style="margin-top:10px" '
        + 'data-project-id="'+p.id+'" onclick="advancePhase(this.dataset.projectId)">'
        + esc(nextLbl)+'</button>';
    }
    phaseBanner = '<div style="background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:8px;padding:12px 16px;margin-bottom:14px">'
      + '<div style="font-size:.82rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--text3);margin-bottom:4px">Phase</div>'
      + '<span class="hub-status-chip '+p.status+'">'+esc(phaseLabel(p.status))+'</span>'
      + phaseEndStr
      + advanceBtn
      + '<div class="flash" id="advance-fl" style="margin-top:6px"></div>'
      + '</div>';
  }

  var commentsHtml = comments.length
    ? comments.map(function(c){
        return '<div class="hub-comment"><div class="hub-comment-meta">'+esc(c.member_first_name||'Member')+' · '+dts(c.created_at)+'</div><div class="hub-comment-body">'+esc(c.body)+'</div></div>';
      }).join('')
    : '<div class="hub-empty" style="padding:16px 0">No comments yet.</div>';

  var commentForm = enrolledInArea
    ? '<div class="hub-compose" style="margin-top:8px">' +
        '<div class="hub-compose-title">Add a comment</div>' +
        '<textarea class="hub-textarea" id="pc-body" placeholder="Share an update, resource, or question…" rows="3"></textarea>' +
        '<button class="btn btn-gold btn-sm" onclick="postComment('+p.id+')">Post Comment</button>' +
        '<div class="flash" id="pc-fl"></div>' +
      '</div>'
    : '<div class="hub-gate-msg">Activate participation in this hub to comment.</div>';

  // Vote widget — only shown when project is in 'vote' phase and member is enrolled
  var voteWidget = '';
  if(p.status === 'vote' && enrolledInArea){
    var vs = _projectData.vote_summary || {agree_count:0,disagree_count:0,block_count:0,abstain_count:0,total_votes:0};
    var mv = _projectData.my_vote || null;
    var myPos = mv ? mv.position : null;
    var _posBtn = function(pos, label, cls){
      var active = myPos === pos ? ' style="outline:2px solid currentColor;outline-offset:2px"' : '';
      return '<button class="btn btn-sm hub-vote-btn '+cls+'"'+active
        +' data-project-id="'+p.id+'" data-position="'+pos+'"'
        +' onclick="castVote(this)">'+label+'</button>';
    };
    var blockNote = (myPos === 'block' && mv && mv.reasoning)
      ? '<div style="font-size:.8rem;color:var(--text3);margin-top:6px">Your block reason: '+esc(mv.reasoning)+'</div>'
      : '';
    voteWidget =
      '<div class="hub-section">' +
        '<div class="hub-section-hd"><span class="hub-section-title">Consent Vote</span></div>' +
        '<div style="padding:0 0 12px">' +
          '<div style="font-size:.85rem;color:var(--text3);margin-bottom:12px">' +
            'Cast your position. A single block re-opens deliberation. You may change your vote while voting is open.' +
          '</div>' +
          '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">' +
            _posBtn('agree',    '✔ Agree',    'hub-vote-agree') +
            _posBtn('disagree', '✕ Disagree', 'hub-vote-disagree') +
            _posBtn('block',    '⛔ Block',    'hub-vote-block') +
            _posBtn('abstain',  '○ Abstain',  'hub-vote-abstain') +
          '</div>' +
          '<div id="block-reason-wrap" style="display:'+(myPos==='block'?'block':'none')+'">' +
            '<textarea class="hub-textarea" id="block-reason-txt" rows="2" maxlength="2000"' +
            ' placeholder="Required: explain your block (what would need to change?)">'+
            (mv && mv.reasoning ? esc(mv.reasoning) : '')+
            '</textarea>' +
          '</div>' +
          blockNote +
          '<div class="flash" id="vote-fl" style="margin-top:4px"></div>' +
          '<div style="margin-top:10px;font-size:.82rem;color:var(--text3)">' +
            '✔ Agree: <b>'+vs.agree_count+'</b> · ' +
            '✕ Disagree: <b>'+vs.disagree_count+'</b> · ' +
            '⛔ Block: <b>'+vs.block_count+'</b> · ' +
            '○ Abstain: <b>'+vs.abstain_count+'</b> · ' +
            'Total: <b>'+vs.total_votes+'</b>' +
          '</div>' +
        '</div>' +
      '</div>';
  }

  // Milestones section — shown in accountability phase for all enrolled members
  var milestonesSection = '';
  if(p.status === 'accountability'){
    var milestones = _projectData.milestones || [];
    var isCoord = myRole === 'coordinator';
    var mItems = milestones.length
      ? milestones.map(function(m){
          var doneClass = m.done ? 'style="text-decoration:line-through;opacity:.55"' : '';
          var toggleBtn = isCoord
            ? '<button class="btn btn-ghost btn-sm" style="padding:1px 8px;font-size:.75rem;margin-left:8px"'
              + ' data-milestone-id="'+m.id+'" onclick="toggleMilestone(this)">'
              + (m.done ? 'Reopen' : 'Mark done')+'</button>'
            : '';
          var dateStr = m.target_date
            ? '<span style="color:var(--text3);font-size:.78rem;margin-left:6px">by '+dt(m.target_date)+'</span>'
            : '';
          return '<div style="display:flex;align-items:center;padding:6px 0;border-bottom:1px solid var(--border)">'
            + '<span style="font-size:1rem;margin-right:8px;color:'+(m.done?'var(--green)':'var(--text3)')+'">'
            + (m.done ? '✓' : '○') + '</span>'
            + '<span '+doneClass+'>'+esc(m.label)+'</span>'
            + dateStr
            + toggleBtn
            + '</div>';
        }).join('')
      : '<div style="color:var(--text3);font-size:.85rem;padding:6px 0">No milestones added yet.</div>';

    var addForm = isCoord
      ? '<div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">'
          + '<input class="hub-input" id="ms-label" placeholder="Milestone label" maxlength="255" style="flex:1;min-width:140px">'
          + '<input class="hub-input" id="ms-date" type="date" style="width:140px;flex:none">'
          + '<button class="btn btn-gold btn-sm" data-project-id="'+p.id+'" onclick="addMilestone(this)">Add</button>'
          + '</div>'
          + '<div class="flash" id="ms-fl" style="margin-top:4px"></div>'
      : '';

    milestonesSection =
      '<div class="hub-section">' +
        '<div class="hub-section-hd"><span class="hub-section-title">Delivery Milestones</span></div>' +
        '<div style="padding:0 0 4px">'+mItems+'</div>' +
        addForm +
      '</div>';
  }

  wrap.innerHTML =
    '<button class="hub-detail-back" onclick="closeProject()">← Back to '+esc(window.HUB_LABEL||'Hub')+'</button>' +
    '<div class="hub-detail-card">' +
      phaseBanner +
      '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:10px">' +
        '<div class="hub-detail-title">'+esc(p.title)+'</div>' +
        '<span class="hub-status-chip '+(p.status||'draft')+'">'+esc(phaseLabel(p.status||'draft'))+'</span>' +
      '</div>' +
      (p.summary?'<div class="hub-detail-body" style="margin-bottom:12px">'+esc(p.summary)+'</div>':'') +
      (p.body?'<div class="hub-detail-body">'+esc(p.body)+'</div>':'') +
      '<div style="margin-top:14px;font-size:.82rem;color:var(--text3)">' +
        (p.target_close_at?'Target close: '+dt(p.target_close_at)+' · ':'') +
        p.participant_count+' participant'+(p.participant_count===1?'':'s') +
        ' · Created '+dt(p.created_at) +
      '</div>' +
      (participantsHtml?'<div class="hub-participants" style="margin-top:12px">'+participantsHtml+'</div>':'') +
      '<div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">'+joinBtn+'</div>' +
      '<div class="flash" id="pj-fl"></div>' +
    '</div>' +
    milestonesSection +
    voteWidget +
    '<div class="hub-section">' +
      '<div class="hub-section-hd"><span class="hub-section-title">Discussion</span></div>' +
      '<div class="hub-comment-list">'+commentsHtml+'</div>' +
      commentForm +
    '</div>';
}

function closeProject(){
  _view = 'hub';
  _projectId = null;
  _projectData = null;
  var mainWrap = el('hub-main-content');
  if(mainWrap) mainWrap.style.display='';
  var detailWrap = el('hub-project-detail');
  if(detailWrap){ detailWrap.style.display='none'; detailWrap.innerHTML=''; }
}

async function joinProject(id){
  var btn = el('join-proj-btn');
  if(btn){ btn.disabled=true; btn.textContent='Joining…'; }
  try{
    await api('vault/hub-project-join',{method:'POST',body:JSON.stringify({project_id:id})});
    _projectData = await api('vault/hub-project&id='+id);
    renderProjectDetail();
  }catch(e){
    flash('pj-fl',e.message||'Could not join project.','err');
    if(btn){ btn.disabled=false; btn.textContent='Join this project'; }
  }
}

async function leaveProject(id){
  try{
    await api('vault/hub-project-leave',{method:'POST',body:JSON.stringify({project_id:id})});
    _projectData = await api('vault/hub-project&id='+id);
    renderProjectDetail();
  }catch(e){
    flash('pj-fl',e.message||'Could not leave project.','err');
  }
}

async function postComment(projId){
  var ta = el('pc-body');
  var body = ta ? ta.value.trim() : '';
  if(!body){ flash('pc-fl','Comment cannot be empty.','err'); return; }
  var btn = document.querySelector('#hub-project-detail .btn-gold');
  if(btn){ btn.disabled=true; btn.textContent='Posting…'; }
  try{
    await api('vault/hub-project-comment',{method:'POST',body:JSON.stringify({project_id:projId,body:body})});
    _projectData = await api('vault/hub-project&id='+projId);
    renderProjectDetail();
  }catch(e){
    flash('pc-fl',e.message||'Could not post comment.','err');
    if(btn){ btn.disabled=false; btn.textContent='Post Comment'; }
  }
}

async function advancePhase(projectId){
  projectId = parseInt(projectId, 10);
  var advBtn = document.querySelector('[data-project-id="'+projectId+'"]');
  var origLabel = advBtn ? advBtn.textContent : '';
  if(advBtn){ advBtn.disabled=true; advBtn.textContent='Advancing…'; }
  try{
    await api('vault/hub-project-advance',{method:'POST',body:JSON.stringify({project_id:projectId})});
    // Reload full project so banner + chip update
    _projectData = await api('vault/hub-project&id='+projectId);
    renderProjectDetail();
  }catch(e){
    flash('advance-fl', e.message||'Could not advance phase.', 'err');
    if(advBtn){ advBtn.disabled=false; advBtn.textContent=origLabel; }
  }
}

async function castVote(btn){
  var projectId = parseInt(btn.dataset.projectId, 10);
  var position  = btn.dataset.position;
  var reasoning = '';
  if(position === 'block'){
    // Show textarea if hidden, require content
    var wrap = document.getElementById('block-reason-wrap');
    if(wrap) wrap.style.display = 'block';
    var ta = document.getElementById('block-reason-txt');
    reasoning = ta ? ta.value.trim() : '';
    if(!reasoning){ flash('vote-fl','Please explain your block before submitting.','err'); return; }
  } else {
    // Hide block textarea when switching to another position
    var bwrap = document.getElementById('block-reason-wrap');
    if(bwrap) bwrap.style.display = 'none';
  }
  var allBtns = document.querySelectorAll('.hub-vote-btn');
  allBtns.forEach(function(b){ b.disabled=true; });
  try{
    var res = await api('vault/hub-project-vote',{method:'POST',body:JSON.stringify({
      project_id:projectId, position:position, reasoning:reasoning||undefined
    })});
    // Update summary counts and my_vote in cached data, re-render
    if(_projectData){
      _projectData.vote_summary = res.summary;
      _projectData.my_vote = {position:res.my_position, reasoning:reasoning||null};
    }
    renderProjectDetail();
  }catch(e){
    flash('vote-fl', e.message||'Could not cast vote.', 'err');
    allBtns.forEach(function(b){ b.disabled=false; });
  }
}

async function addMilestone(btn){
  var projectId = parseInt(btn.dataset.projectId, 10);
  var label = (document.getElementById('ms-label')||{}).value||'';
  var date  = (document.getElementById('ms-date')||{}).value||'';
  label = label.trim();
  if(!label){ flash('ms-fl','Milestone label is required.','err'); return; }
  btn.disabled = true; btn.textContent = 'Adding…';
  try{
    var res = await api('vault/hub-milestone-add',{method:'POST',body:JSON.stringify({
      project_id:projectId, label:label, target_date:date||undefined
    })});
    if(_projectData) _projectData.milestones = res.milestones;
    renderProjectDetail();
  }catch(e){
    flash('ms-fl', e.message||'Could not add milestone.','err');
    btn.disabled=false; btn.textContent='Add';
  }
}

async function toggleMilestone(btn){
  var milestoneId = parseInt(btn.dataset.milestoneId, 10);
  btn.disabled = true;
  try{
    var res = await api('vault/hub-milestone-toggle',{method:'POST',body:JSON.stringify({
      milestone_id:milestoneId
    })});
    if(_projectData) _projectData.milestones = res.milestones;
    renderProjectDetail();
  }catch(e){
    flash('ms-fl', e.message||'Could not update milestone.','err');
    btn.disabled=false;
  }
}

/* ── Enrolment actions ──────────────────────────────────────────────────────── */
async function hubJoin(){
  var btn = el('activate-btn');
  if(btn){ btn.disabled=true; btn.textContent='Activating…'; }
  try{
    await api('vault/hub-join',{method:'POST',body:JSON.stringify({area_key:window.HUB_AREA_KEY})});
    _enrolled = true;
    var d = await api('vault/hub&area='+window.HUB_AREA_KEY);
    _hubData = d;
    renderAll();
  }catch(e){
    if(btn){ btn.disabled=false; btn.textContent='⬡ Activate Participation'; }
    alert(e.message||'Could not activate participation.');
  }
}

async function hubLeave(){
  if(!confirm('Leave this hub? You will lose write access to threads and projects.')) return;
  try{
    await api('vault/hub-leave',{method:'POST',body:JSON.stringify({area_key:window.HUB_AREA_KEY})});
    _enrolled = false;
    var d = await api('vault/hub&area='+window.HUB_AREA_KEY);
    _hubData = d;
    renderAll();
  }catch(e){
    alert(e.message||'Could not leave hub.');
  }
}

async function toggleRosterVis(){
  var vis = !_hubData.roster_visible;
  try{
    await api('vault/hub-roster-visibility',{method:'POST',body:JSON.stringify({visible:vis?1:0})});
    _hubData.roster_visible = vis;
    var btn = el('rv-btn');
    if(btn) btn.textContent = vis ? '✓ Visible on roster' : '○ Hidden from roster';
  }catch(e){
    alert(e.message||'Could not update roster visibility.');
  }
}

async function toggleShowName(){
  var show = !_hubData.show_name;
  try{
    await api('vault/hub-roster-name-visibility',{method:'POST',body:JSON.stringify({show_name:show?1:0})});
    _hubData.show_name = show;
    var btn = el('sn-btn');
    if(btn) btn.textContent = show ? '✓ Name shown' : '○ Show my name';
  }catch(e){
    alert(e.message||'Could not update name visibility.');
  }
}

function renderError(msg){
  var page = document.querySelector('.hub-page');
  if(page) page.innerHTML='<div class="hub-empty" style="padding:60px 20px;line-height:1.8">'+msg+'</div>';
}

/* ── Expose to inline onclick handlers ──────────────────────────────────────── */
window.switchForumTab      = switchForumTab;
window.toggleThread        = toggleThread;
window.postReply           = postReply;
window.postThread          = postThread;
window.showCreateProject   = showCreateProject;
window.cancelCreateProject = cancelCreateProject;
window.submitCreateProject = submitCreateProject;
window.openProject         = openProject;
window.closeProject        = closeProject;
window.joinProject         = joinProject;
window.leaveProject        = leaveProject;
window.advancePhase        = advancePhase;
window.castVote            = castVote;
window.addMilestone        = addMilestone;
window.toggleMilestone     = toggleMilestone;
window.postComment         = postComment;
window.hubJoin             = hubJoin;
window.fetchResolvedQueries = fetchResolvedQueries;
window.hubLeave            = hubLeave;
window.toggleRosterVis     = toggleRosterVis;
window.toggleShowName      = toggleShowName;
window.renderRoster        = renderRoster;
window.coinTransition      = coinTransition;

/* ── Hub Query Form ─────────────────────────────────────────────────────────── */

function renderQuerySection(){
  var wrap = el('hub-query-section');
  if(!wrap) return;

  // My existing queries
  api('vault/hub-my-queries&area='+window.HUB_AREA_KEY)
    .then(function(d){
      var qs = d.queries || [];
      var myWrap = el('hub-my-queries');
      if(!myWrap) return;
      if(!qs.length){
        myWrap.innerHTML = '<div style="font-size:.85rem;color:var(--text3)">No queries raised yet.</div>';
        return;
      }
      myWrap.innerHTML = qs.map(function(q){
        var statusColor = {open:'var(--amber)',in_review:'#60a5fa',resolved:'var(--green)',closed:'var(--text3)'}[q.status]||'var(--text3)';
        var tIcon = {private:'🔒',hub_members:'👥',public_record:'📢'}[q.transparency]||'🔒';
        return '<div style="border:1px solid var(--border);border-radius:var(--r2);padding:10px 12px;margin-bottom:8px">' +
          '<div style="font-size:.9rem;font-weight:600;color:var(--text)">' + esc(q.subject) + '</div>' +
          '<div style="font-size:.78rem;color:var(--text3);margin-top:4px;display:flex;gap:10px">' +
            '<span>' + tIcon + ' ' + (q.transparency||'').replace(/_/g,' ') + '</span>' +
            '<span style="color:'+statusColor+'">● ' + (q.status||'').replace(/_/g,' ') + '</span>' +
            '<span>' + dt(q.created_at) + '</span>' +
          '</div>' +
        '</div>';
      }).join('');
    }).catch(function(){});
}

function showQueryForm(){
  var fw = el('hub-query-form-wrap');
  if(!fw) return;
  if(fw.innerHTML){ fw.innerHTML=''; return; }

  fw.innerHTML =
    '<div class="hub-compose" style="margin-top:14px">' +
      '<div class="hub-compose-title">Raise a governance query</div>' +
      '<div class="hub-compose-disclaimer">Your query is directed to the Foundation. Select the transparency level — this determines who can see the query and reply.</div>' +
      '<input class="hub-input" id="qf-subject" placeholder="Subject — briefly describe your query" maxlength="255">' +
      '<textarea class="hub-textarea" id="qf-body" placeholder="Describe your query in detail…" rows="5" style="margin-top:0"></textarea>' +
      '<div style="margin-bottom:10px">' +
        '<label style="font-size:.82rem;color:var(--text3);display:block;margin-bottom:5px">Transparency level</label>' +
        '<select class="hub-input" id="qf-trans" style="width:auto;cursor:pointer">' +
          '<option value="private">🔒 Private — admin and I only</option>' +
          '<option value="hub_members">👥 Hub members — enrolled members in this hub can see the reply</option>' +
          '<option value="public_record">📢 Public record — reply broadcast to the entire hub</option>' +
        '</select>' +
      '</div>' +
      '<div style="display:flex;gap:8px">' +
        '<button class="btn btn-gold" onclick="submitQuery()">Submit Query</button>' +
        '<button class="btn btn-ghost" onclick="cancelQuery()">Cancel</button>' +
      '</div>' +
      '<div class="flash" id="qf-fl"></div>' +
    '</div>';
}

function cancelQuery(){
  var fw = el('hub-query-form-wrap');
  if(fw) fw.innerHTML='';
}

async function submitQuery(){
  var subj  = (el('qf-subject')||{}).value||'';
  var body  = (el('qf-body')||{}).value||'';
  var trans = (el('qf-trans')||{}).value||'private';
  subj = subj.trim(); body = body.trim();
  if(!subj){ flash('qf-fl','Subject is required.','err'); return; }
  if(!body){ flash('qf-fl','Please describe your query.','err'); return; }
  var btn = document.querySelector('#hub-query-form-wrap .btn-gold');
  if(btn){ btn.disabled=true; btn.textContent='Submitting…'; }
  try{
    await api('vault/hub-query',{method:'POST',body:JSON.stringify({
      area_key:window.HUB_AREA_KEY, subject:subj, body:body, transparency:trans
    })});
    cancelQuery();
    flash('hub-query-flash','Query submitted. The Foundation will respond.','ok');
    renderQuerySection();
  }catch(e){
    flash('qf-fl',e.message||'Could not submit query.','err');
    if(btn){ btn.disabled=false; btn.textContent='Submit Query'; }
  }
}

/* ── AI Governance Assistant ─────────────────────────────────────────────────── */

var _aiHistory = [];
var _aiOpen    = false;

// Per-area system prompt context — governance purpose + what the AI should/shouldn't do
var _AI_AREA_CONTEXT = {
  operations_oversight:  'You are assisting with the Day-to-Day Operations hub. This hub covers monitoring Trustee activity, raising proposals, tracking JVPA compliance, and ensuring the Joint Venture runs according to the Partnership Agreement. Members — not the Trustee — are the operators. You can suggest how to table proposals, what constitutes a governance issue, and how operational threads work.',
  governance_polls:      'You are assisting with the Research & Acquisitions hub. This hub covers identifying new ASX companies, real world assets, and resources as potential acquisition targets for the Members Asset Pool. You can help members formulate research questions, understand what makes a good acquisition candidate under the JV framework, and how to initiate a Members Poll.',
  esg_proxy_voting:      'You are assisting with the ESG & Proxy Voting hub. This hub activates at Expansion Day when CHESS-registered shares are held. It covers how Members set the ESG engagement strategy collectively via the Aggregate Unitholder Direction mechanism and direct proxy votes at portfolio company AGMs. You can explain the proxy voting framework and what ESG criteria the community might consider.',
  first_nations:         'You are assisting with the First Nations Joint Venture hub. This hub covers engagement with the FNAC, Free Prior and Informed Consent (FPIC) obligations, Indigenous Cultural and Intellectual Property (ICIP) protections, and the automatic zero-cost Landholder entitlement for Local Aboriginal Land Councils. First Nations custodians are founding governance members. Approach all matters with respect for Country and cultural protocols.',
  community_projects:    'You are assisting with the Community Projects hub. This hub covers directing grants and community benefit priorities funded through Sub-Trust C — environmental stewardship, First Nations programs, social welfare, and community flourishing. You can help members propose projects, understand the Sub-Trust C funding mechanism, and build community impact initiatives.',
  technology_blockchain: 'You are assisting with the Technology & Blockchain hub. This hub covers smart contract governance, system audits, and infrastructure decisions. Members own and operate the proprietary cryptographic governance system. The planned migration is to a permissioned Hyperledger Besu blockchain. You can discuss governance of technical infrastructure, audit scheduling, and open-source principles.',
  financial_oversight:   'You are assisting with the Financial Oversight hub. This hub covers monitoring trust accounts, distribution accuracy, and dividend strategy. The Trustee publishes quarterly reports and annual audited accounts. The Godley-style double-entry ledger tracks all trust flows. You can explain financial oversight responsibilities, what to look for in trust accounts, and how distribution calculations work.',
  place_based_decisions: 'You are assisting with the Place-Based Decisions hub. This hub covers Affected Zone declarations and land-impact assessments. Residents of a declared Affected Zone receive weighted local governance rights. The first Affected Zone is Drake Village (AZ-DRAKE-001). You can help members understand zone governance, what triggers a declaration, and how affected residents participate.',
  education_outreach:    'You are assisting with the Education & Outreach hub. This hub covers helping new Members understand their role, explaining the JV structure plainly, and growing the governance base. Every Member brought in strengthens the community voice. You can suggest onboarding resources, member guides, outreach approaches, and community education strategies.',
};

function buildAISystemPrompt(hubData){
  var areaKey  = window.HUB_AREA_KEY || '';
  var label    = window.HUB_LABEL    || 'this hub';
  var areaCtx  = _AI_AREA_CONTEXT[areaKey] || 'You are assisting with a COG$ management hub.';
  var summary  = hubData ? hubData.summary || {} : {};
  var enrolled = hubData ? (hubData.enrolled ? 'Yes' : 'No') : 'Unknown';
  var threads  = hubData ? (hubData.threads||[]).slice(0,5) : [];

  var threadTitles = threads.length
    ? threads.map(function(t){ return '  - "'+t.subject+'" ('+t.direction+', '+t.status+')'; }).join('\n')
    : '  (no recent threads)';

  return [
    'You are the COG$ of Australia Foundation Governance Assistant — a proactive, knowledgeable AI assistant helping members participate effectively in the Joint Venture.',
    '',
    'AREA CONTEXT:',
    areaCtx,
    '',
    'LIVE HUB DATA (as of this session):',
    '  Hub: ' + label,
    '  Enrolled members: ' + (summary.member_count||0),
    '  Active projects: '  + (summary.active_project_count||0),
    '  Forum threads: '    + (summary.thread_count||0),
    '  Last activity: '    + (summary.last_activity_at||'none recorded'),
    '  Current member enrolled: ' + enrolled,
    '',
    'RECENT FORUM THREAD SUBJECTS (titles only — not content):',
    threadTitles,
    '',
    'AUTHORITY BOUNDARIES — STRICTLY ENFORCED:',
    '- You may analyse, explain, suggest, and guide members on governance participation.',
    '- You may NOT provide legal advice or make binding determinations on legal questions.',
    '- You may NOT provide financial advice or recommend specific investments.',
    '- You may NOT access, reveal, or speculate about specific member PII, balances, or private data.',
    '- You may NOT initiate, approve, or simulate any governance action — only explain and suggest.',
    '- For binding matters always direct members to the JVPA, the Declaration, or the Foundation directly.',
    '- If asked about something outside this hub\'s governance area, redirect to the appropriate hub.',
    '',
    'FOUNDATION BACKGROUND:',
    'COG$ of Australia Foundation is a Community Joint Venture Partnership under South Australian law. Governing documents: JVPA (supreme), CJVM Hybrid Trust Declaration, Sub-Trust Deeds A/B/C. Members are the operators. The Trustee acts under Member direction. First Nations communities are founding governance partners. Primary ASX holding: Legacy Minerals (LGM). Foundation Day: 14 May 2026.',
    '',
    'APPROACH:',
    'Be specific, proactive, and practical. Reference actual hub data when making suggestions.',
    'Offer concrete next steps. Ask clarifying questions when needed. Celebrate community participation.',
  ].join('\n');
}

function toggleAIPanel(){
  var panel = el('ai-assistant-panel');
  if(!panel) return;
  _aiOpen = !_aiOpen;
  panel.style.display = _aiOpen ? 'flex' : 'none';
  var btn = el('ai-panel-btn');
  if(btn) btn.textContent = _aiOpen ? '✕ Close Assistant' : '⬡ AI Assistant';
  if(_aiOpen && !el('ai-msgs').children.length){
    appendAIMsg('assistant','Hello! I\'m the COG$ Governance Assistant for the <strong>'+esc(window.HUB_LABEL||'this hub')+'</strong> hub. I have access to the current hub activity and am here to help you participate effectively.\n\nWhat would you like to explore or discuss?');
  }
  if(_aiOpen){
    var inp = el('ai-input');
    if(inp) setTimeout(function(){ inp.focus(); }, 150);
  }
}

function appendAIMsg(role, html){
  var msgs = el('ai-msgs');
  if(!msgs) return;
  var wrap = document.createElement('div');
  wrap.style.cssText = 'margin-bottom:12px;display:flex;flex-direction:column;align-items:'+(role==='user'?'flex-end':'flex-start');
  var bubble = document.createElement('div');
  bubble.style.cssText = [
    'max-width:88%;padding:10px 14px;border-radius:14px;font-size:.88rem;line-height:1.65;word-break:break-word;',
    role==='user'
      ? 'background:linear-gradient(135deg,rgba(232,184,75,.18),rgba(232,184,75,.08));border:1px solid var(--gold-bdr);color:var(--text);border-bottom-right-radius:4px'
      : 'background:rgba(255,255,255,.05);border:1px solid var(--border);color:var(--text2);border-bottom-left-radius:4px'
  ].join('');
  bubble.innerHTML = html.replace(/\n/g,'<br>');
  wrap.appendChild(bubble);
  msgs.appendChild(wrap);
  msgs.scrollTop = msgs.scrollHeight;
}

async function sendAIMessage(){
  var inp = el('ai-input');
  var msg = inp ? inp.value.trim() : '';
  if(!msg) return;
  inp.value='';

  appendAIMsg('user', esc(msg));
  _aiHistory.push({role:'user',content:msg});

  var thinking = document.createElement('div');
  thinking.id='ai-thinking';
  thinking.style.cssText='margin-bottom:12px;font-size:.82rem;color:var(--text3);font-family:var(--mono);animation:hubPulse 1.2s infinite';
  thinking.textContent='Thinking…';
  el('ai-msgs').appendChild(thinking);
  el('ai-msgs').scrollTop=el('ai-msgs').scrollHeight;

  var sendBtn = el('ai-send-btn');
  if(sendBtn) sendBtn.disabled=true;

  try{
    var systemPrompt = buildAISystemPrompt(window._hubData||null);
    // Call via admin API proxy (avoids CORS + keeps API key server-side)
    var ROOT = document.body.dataset.root || '../../';
    var r = await fetch(ROOT+'_app/api/index.php?route=vault/hub-ai', {
      method:'POST',
      credentials:'include',
      headers:{'Content-Type':'application/json','Accept':'application/json'},
      body:JSON.stringify({
        message:msg,
        history:_aiHistory.slice(-10),   // last 10 turns only
        system:systemPrompt,
        area_key:window.HUB_AREA_KEY||''
      })
    });
    var txt = await r.text();
    var j; try{ j=JSON.parse(txt); }catch(e){ j={error:txt}; }

    var th = el('ai-thinking');
    if(th) th.remove();

    // apiSuccess wraps payload under .data — unwrap it
    var reply = (j.data && j.data.reply) ? j.data.reply : (j.reply || null);
    var errMsg = (j.data && j.data.error) ? j.data.error : (j.error || null);

    if(reply){
      appendAIMsg('assistant', esc(reply).replace(/\n\n/g,'<br><br>').replace(/\n/g,'<br>'));
      _aiHistory.push({role:'assistant',content:reply});
    }else{
      var displayErr = errMsg || ('Server returned HTTP ' + r.status + '. Please try again.');
      appendAIMsg('assistant','<span style="color:var(--amber)">⚠ '+esc(displayErr)+'</span>');
    }
  }catch(e){
    var th2=el('ai-thinking'); if(th2) th2.remove();
    appendAIMsg('assistant','<span style="color:var(--amber)">⚠ Connection error: '+esc(e.message||'Unknown error')+'</span>');
  }finally{
    if(sendBtn) sendBtn.disabled=false;
  }
}

function aiKeydown(e){
  if((e.key==='Enter'||e.keyCode===13) && !e.shiftKey){
    e.preventDefault();
    sendAIMessage();
  }
}

window.showQueryForm    = showQueryForm;
window.cancelQuery      = cancelQuery;
window.submitQuery      = submitQuery;
window.renderQuerySection = renderQuerySection;
window.toggleAIPanel    = toggleAIPanel;
window.sendAIMessage    = sendAIMessage;
window.aiKeydown        = aiKeydown;


/* ── Start ───────────────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', function(){
  boot();
  renderQuerySection();
});

})();
