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
    if(!r.ok || j.success===false) throw new Error(j.error||'Request failed ('+r.status+')');
    return j.data !== undefined ? j.data : j;
  }catch(e){
    if(e&&e.name==='AbortError') throw new Error('Request timed out.');
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
    _hubData = await api('vault/hub?area='+areaKey);
  }catch(e){
    hideSplash();
    var msg = (e&&e.message)||'';
    window._hubNavigating = true;
    if(/timed out|Failed to fetch|NetworkError|50\d/i.test(msg)){
      window.location.replace(ROOT+'partners/index.html?next=vault&reason=server');
    }else{
      window.location.replace(ROOT+'partners/index.html?next=vault');
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
    rv.innerHTML = '<button class="hub-roster-toggle" onclick="toggleRosterVis()" id="rv-btn">'+(vis?'✓ Visible on roster':'○ Hidden from roster')+'</button>';
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
    var d = await api('vault/hub-roster?area='+areaKey+'&page='+page+'&per='+_rosterPer);
    _rosterTotal = d.total || 0;
    var members = d.members || [];
    if(!members.length){
      wrap.innerHTML = '<div class="hub-empty">No members have opted into this hub yet.</div>';
      return;
    }
    var cards = members.map(function(m){
      return '<div class="hub-roster-card">' +
        '<div class="hub-roster-name">'+esc(m.first_name||'Member')+'</div>' +
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
    var d = await api('vault/hub?area='+window.HUB_AREA_KEY);
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
    var d = await api('vault/hub?area='+window.HUB_AREA_KEY);
    _hubData.threads = d.threads || _hubData.threads;
    _hubData.summary = d.summary || _hubData.summary;
    renderSummaryStats();
    renderForumTab(_forumTab);
  }catch(e){
    flash('compose-fl',e.message||'Could not post thread.','err');
    if(btn){ btn.disabled=false; btn.textContent='Post to Forum'; }
  }
}

/* ── Projects ───────────────────────────────────────────────────────────────── */
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
    wrap.innerHTML = '<div class="hub-empty">No projects in this hub yet.</div>' +
      (_enrolled ? '<div id="create-project-form-wrap"></div>' : '<div class="hub-gate-msg">Activate participation to create the first project.</div>');
    return;
  }

  var cards = projects.map(function(p){
    var statusCls = p.status||'proposed';
    var joinedMark = p.joined_by_me ? ' <span style="color:var(--green);font-size:.78rem">✓ Joined</span>' : '';
    return '<div class="hub-project-card" onclick="openProject('+p.id+')">' +
      '<div class="hub-project-row">' +
        '<div class="hub-project-title">'+esc(p.title)+'</div>' +
        '<span class="hub-status-chip '+statusCls+'">'+statusCls+'</span>' +
      '</div>' +
      (p.summary?'<div class="hub-project-summary">'+esc(p.summary.substring(0,120))+(p.summary.length>120?'…':'')+'</div>':'') +
      '<div class="hub-project-footer">' +
        '<span>'+p.participant_count+' participant'+(p.participant_count===1?'':'s')+'</span>' +
        (p.target_close_at?'<span>Target: '+dt(p.target_close_at)+'</span>':'') +
        joinedMark +
      '</div>' +
    '</div>';
  }).join('');

  wrap.innerHTML = '<div class="hub-project-list">'+cards+'</div><div id="create-project-form-wrap"></div>';
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
    var d2 = await api('vault/hub?area='+window.HUB_AREA_KEY);
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
    _projectData = await api('vault/hub-project?id='+id);
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

  wrap.innerHTML =
    '<button class="hub-detail-back" onclick="closeProject()">← Back to '+esc(window.HUB_LABEL||'Hub')+'</button>' +
    '<div class="hub-detail-card">' +
      '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:10px">' +
        '<div class="hub-detail-title">'+esc(p.title)+'</div>' +
        '<span class="hub-status-chip '+(p.status||'proposed')+'">'+esc(p.status)+'</span>' +
      '</div>' +
      (p.summary?'<div class="hub-detail-body" style="margin-bottom:12px">'+esc(p.summary)+'</div>':'') +
      (p.body?'<div class="hub-detail-body">'+esc(p.body)+'</div>':'') +
      '<div style="margin-top:14px;font-size:.82rem;color:var(--text3)">'+
        (p.target_close_at?'Target close: '+dt(p.target_close_at)+' · ':'') +
        p.participant_count+' participant'+(p.participant_count===1?'':'s') +
        ' · Created '+dt(p.created_at) +
      '</div>' +
      (participantsHtml?'<div class="hub-participants" style="margin-top:12px">'+participantsHtml+'</div>':'') +
      '<div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">'+joinBtn+'</div>' +
      '<div class="flash" id="pj-fl"></div>' +
    '</div>' +
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
    _projectData = await api('vault/hub-project?id='+id);
    renderProjectDetail();
  }catch(e){
    flash('pj-fl',e.message||'Could not join project.','err');
    if(btn){ btn.disabled=false; btn.textContent='Join this project'; }
  }
}

async function leaveProject(id){
  try{
    await api('vault/hub-project-leave',{method:'POST',body:JSON.stringify({project_id:id})});
    _projectData = await api('vault/hub-project?id='+id);
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
    _projectData = await api('vault/hub-project?id='+projId);
    renderProjectDetail();
  }catch(e){
    flash('pc-fl',e.message||'Could not post comment.','err');
    if(btn){ btn.disabled=false; btn.textContent='Post Comment'; }
  }
}

/* ── Enrolment actions ──────────────────────────────────────────────────────── */
async function hubJoin(){
  var btn = el('activate-btn');
  if(btn){ btn.disabled=true; btn.textContent='Activating…'; }
  try{
    await api('vault/hub-join',{method:'POST',body:JSON.stringify({area_key:window.HUB_AREA_KEY})});
    _enrolled = true;
    var d = await api('vault/hub?area='+window.HUB_AREA_KEY);
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
    var d = await api('vault/hub?area='+window.HUB_AREA_KEY);
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

function renderError(msg){
  var page = document.querySelector('.hub-page');
  if(page) page.innerHTML='<div class="hub-empty" style="padding:60px 20px">'+esc(msg)+'</div>';
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
window.postComment         = postComment;
window.hubJoin             = hubJoin;
window.hubLeave            = hubLeave;
window.toggleRosterVis     = toggleRosterVis;
window.renderRoster        = renderRoster;
window.coinTransition      = coinTransition;

/* ── Start ───────────────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', boot);

})();
