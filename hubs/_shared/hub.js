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

  // Update splash line to show specific hub name
  var splashLine = document.querySelector('.splash-line');
  if(splashLine) splashLine.textContent = label;

  document.title = label + ' Hub — COG$ of Australia Foundation';

  var titleEl = el('hub-title');
  if(titleEl) titleEl.textContent = label;

  var pillEl = el('hub-status-pill');
  if(pillEl){
    pillEl.textContent = status === 'soon' ? 'Activates at Expansion Day' : 'Live';
    pillEl.className = 'hub-status-pill ' + (status === 'soon' ? 'soon' : 'live');
  }

  var dotEl = el('hub-live-dot');
  if(dotEl && status === 'live') dotEl.classList.add('visible');

  var heroLabel = el('hub-hero-label');
  if(heroLabel) heroLabel.textContent = 'Management Hub · ' + label;
  var heroH1 = el('hub-hero-h1');
  if(heroH1) heroH1.textContent = label;
  var heroSub = el('hub-hero-sub');
  if(heroSub && window.HUB_TIP){
    var _tipFull = window.HUB_TIP.split('\\n\\n')[0].replace(/\\n/g,' ').trim();
    heroSub.textContent = _tipFull;
  }
  var chipsWrap = el('hub-hero-chips');
  if(chipsWrap){
    var chipDefs = [
      {label:'Forum',    target:'hub-forum-list'},
      {label:'Projects', target:'hub-projects-wrap'},
      {label:'Roster',   target:'hub-roster-wrap'},
      {label:'Queries',  target:'hub-query-section'},
    ];
    chipsWrap.innerHTML = chipDefs.map(function(c,i){
      return '<button class="hub-hero-chip'+(i===0?' active':'')+'" onclick="hubChipClick(this,\''+c.target+'\')">' + esc(c.label) + '</button>';
    }).join('');
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

  // 5-second splash safety net — hide splash regardless of API outcome
  var _splashTimeout = setTimeout(function(){ hideSplash(); }, 5000);

  try{
    var [_d] = await Promise.all([
      api('vault/hub&area='+areaKey),
      fetchResolvedQueries(),
    ]);
    clearTimeout(_splashTimeout);
    _hubData = _d;
  }catch(e){
    clearTimeout(_splashTimeout);
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

  // Deliverable 5: ?project=<id> auto-open. If the URL carries a project
  // parameter AND the project exists in this hub's owned projects, open it.
  // This is the destination side of openReferencedProject() navigation.
  try{
    var params = new URLSearchParams(window.location.search || '');
    var pidStr = params.get('project');
    if(pidStr){
      var pidNum = parseInt(pidStr, 10);
      if(pidNum > 0 && Array.isArray(_hubData.projects)){
        var match = _hubData.projects.some(function(p){
          return (!p.is_referenced) && parseInt(p.id,10) === pidNum;
        });
        if(match){
          setTimeout(function(){ openProject(pidNum); }, 120);
        }
      }
    }
  }catch(_ignored){ /* defensive */ }
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
  if(b) b.style.display = 'none';
  var inl = el('hub-enrol-inline');
  if(inl){
    if(_enrolled){
      inl.className = 'hub-enrol-inline enrolled';
      inl.innerHTML =
        '<span class="hub-enrol-inline-t"><span style="color:var(--green)">&#x25CF;</span> You are active in this hub.</span>' +
        '<button class="hub-enrol-leave" onclick="hubLeave()">Leave</button>';
    }else{
      inl.className = 'hub-enrol-inline';
      inl.innerHTML =
        '<span class="hub-enrol-inline-t">Read-only mode — activate to post, create projects, and join discussions.</span>' +
        '<button class="hub-enrol-inline-cta" id="activate-btn" onclick="hubJoin()">Activate</button>';
    }
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
function scrollToSection(contentId){
  var target = el(contentId);
  if(!target) return;
  var section = target.closest ? target.closest('.hub-section') : target.parentNode;
  while(section && !section.classList.contains('hub-section')) section = section.parentNode;
  var scrollEl = section || target;
  var topbarH = (document.querySelector('.hub-topbar')||{}).offsetHeight || 60;
  var y = scrollEl.getBoundingClientRect().top + (window.scrollY||window.pageYOffset) - topbarH - 12;
  window.scrollTo({top: Math.max(0, y), behavior:'smooth'});
}

function renderSummaryStats(){
  var s = _hubData.summary || {};
  var rows = [
    {id:'stat-members', n: s.member_count||0, l:'Members', dest:'Roster', target:'hub-roster-wrap'},
    {id:'stat-threads', n: s.thread_count||0, l:'Threads', dest:'Forum', target:'hub-forum-list'},
    {id:'stat-projects',n: s.active_project_count||0, l:'Projects', dest:'Projects', target:'hub-projects-wrap'},
  ];
  var scrollTargets = {
    'stat-members':  'hub-roster-wrap',
    'stat-threads':  'hub-forum-list',
    'stat-projects': 'hub-projects-wrap',
  };
  rows.forEach(function(r){
    var e = el(r.id);
    if(!e) return;
    var nDisplay = r.n > 0 ? Number(r.n).toLocaleString('en-AU') : '<span style="opacity:.4">—</span>';
    e.innerHTML = '<div class="hub-stat-n">'+nDisplay+'</div><div class="hub-stat-l">'+r.l+'</div><div class="hub-stat-dest">↓ '+r.dest+'</div>';
    e.dataset.scroll = r.target;
    e.title = 'Jump to '+r.dest;
    (function(tgt){ e.onclick = function(){ scrollToSection(tgt); }; })(r.target);
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
    var _AV_COLS = [
      'rgba(232,184,75,.18);color:#e8b84b','rgba(62,207,110,.14);color:#3ecf6e',
      'rgba(56,132,255,.14);color:#5ea8ff','rgba(224,92,92,.14);color:#e05c5c',
      'rgba(163,94,255,.14);color:#a35eff','rgba(224,154,66,.14);color:#e09a42',
    ];
    var cards = members.map(function(m){
      var colIdx = (parseInt((m.member_number_masked||'1').replace(/\D/g,'').slice(-3)||'1',10)||1) % _AV_COLS.length;
      var avStyle = 'background:'+_AV_COLS[colIdx];
      var initials = m.first_name ? esc(m.first_name.charAt(0).toUpperCase()) : '?';
      var displayName = m.first_name ? esc(m.first_name) : '<span style="opacity:.45;font-style:italic">Anonymous</span>';
      var sinceStr = m.joined_area_at ? 'Since '+dt(m.joined_area_at) : '';
      return '<div class="hub-roster-card">' +
        '<div class="hub-roster-avatar" style="'+avStyle+'">'+initials+'</div>' +
        '<div class="hub-roster-info">' +
          '<div class="hub-roster-name">'+displayName+'</div>' +
          (sinceStr ? '<div class="hub-roster-meta">'+sinceStr+'</div>' : '') +
        '</div>' +
      '</div>';
    }).join('');

    var totalPages = Math.ceil(_rosterTotal / _rosterPer);
    var pager = '<div class="hub-roster-pagination">' +
      '<button onclick="renderRoster('+(page-1)+')" '+(page<=1?'disabled':'')+'>← Prev</button>' +
      '<span>'+((page-1)*_rosterPer+1)+'–'+Math.min(page*_rosterPer,_rosterTotal)+' of '+_rosterTotal.toLocaleString('en-AU')+'</span>' +
      '<button onclick="renderRoster('+(page+1)+')" '+(page>=totalPages?'disabled':'')+'>Next →</button>' +
      '</div>';

    wrap.innerHTML = '<div class="hub-roster-grid">'+cards+'</div>'+(totalPages>1?pager:'') + '<div class="hub-section-top"><a onclick="window.scrollTo({top:0,behavior:\'smooth\'});return false;" href="#">↑ Back to top</a></div>';
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
    wrap.innerHTML = '<div class="hub-empty">'+(tab==='broadcasts'?'No broadcasts yet.':'No threads yet — be the first to start a discussion.')+'</div>' + '<div class="hub-section-top"><a onclick="window.scrollTo({top:0,behavior:\'smooth\'});return false;" href="#">↑ Back to top</a></div>';
    renderCompose();
    return;
  }

  var _threadStatusMap = {open:'Open',in_review:'In review',resolved:'Resolved',closed:'Closed'};
  var _threadBadgeCls  = {open:'badge-open',in_review:'badge-rev',resolved:'badge-res',closed:'badge-closed'};
  wrap.innerHTML = items.map(function(t){
    var isOpen = !!_openThreads[t.id];
    var unread = !t.read_at && t.direction==='broadcast';
    var statusLabel = _threadStatusMap[t.status] || (t.direction==='broadcast' ? 'Broadcast' : 'Thread');
    var statusCls   = _threadBadgeCls[t.status]  || 'badge-open';
    return '<div class="hub-thread'+(unread?' unread':'')+'" id="thread-'+t.id+'">' +
      '<div class="hub-thread-hd" onclick="toggleThread('+t.id+')">' +
        '<span class="hub-thread-dot'+(unread?'':' read')+'"></span>' +
        '<div style="flex:1;min-width:0">' +
          '<div class="hub-thread-subject">'+esc(t.subject)+'</div>' +
          '<div class="hub-thread-meta">'+(t.author_first_name||'Foundation')+' · '+dts(t.created_at)+(t.reply_count>0?' · '+t.reply_count+' repl'+(t.reply_count===1?'y':'ies'):'')+'</div>' +
        '</div>' +
        '<span class="hub-thread-status-chip '+statusCls+'">'+esc(statusLabel)+'</span>' +
        '<span class="hub-thread-chevron'+(isOpen?' open':'')+'">▾</span>' +
      '</div>' +
      '<div class="hub-thread-body'+(isOpen?' open':'')+'" id="thread-body-'+t.id+'">' +
        '<div class="hub-thread-text">'+esc(t.body)+'</div>' +
        (tab==='inbound'||tab==='threads' ? renderThreadReplyForm(t) : '') +
      '</div>' +
    '</div>';
  }).join('') + '<div class="hub-section-top"><a onclick="window.scrollTo({top:0,behavior:\'smooth\'});return false;" href="#">↑ Back to top</a></div>';

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
      + (_enrolled ? '<div id="create-project-form-wrap"></div>' : '<div class="hub-gate-msg">Activate participation to create the first project.</div>') + '<div class="hub-section-top"><a onclick="window.scrollTo({top:0,behavior:\'smooth\'});return false;" href="#">↑ Back to top</a></div>';
    return;
  }

  var _PHASE_WIDTHS = {
    draft:'8%',proposed:'8%',open_for_input:'35%',deliberation:'55%',
    vote:'75%',accountability:'100%',active:'60%',paused:'50%',
    completed:'100%',archived:'100%'
  };
  var cards = projects.map(function(p){
    var statusCls = p.status||'draft';
    var barWidth  = _PHASE_WIDTHS[statusCls] || '10%';

    // Referenced (cross-hub) project — read-only in this hub
    if(p.is_referenced){
      var ownerKey   = p.owner_area_key || '';
      var ownerLbl   = p.owner_area_label || ownerKey;
      return '<div class="hub-project-card referenced" data-owner-key="'+esc(ownerKey)+'" data-project-id="'+p.id+'" onclick="openReferencedProject(this)">' +
        '<div class="hub-proj-ref-pill">Referenced from '+esc(ownerLbl)+'</div>' +
        '<div class="hub-proj-phase-row">' +
          '<span class="hub-status-chip '+statusCls+'">'+esc(phaseLabel(statusCls))+'</span>' +
          (p.phase_target_end_at ? '<span class="hub-proj-phase-end">ends '+dt(p.phase_target_end_at)+'</span>' : '') +
        '</div>' +
        '<div class="hub-proj-bar"><div class="hub-proj-bar-fill ph-'+statusCls+'" style="width:'+barWidth+'"></div></div>' +
        '<div class="hub-project-title">'+esc(p.title)+'</div>' +
        (p.summary?'<div class="hub-project-summary">'+esc(p.summary.substring(0,100))+(p.summary.length>100?'…':'')+'</div>':'') +
        '<div class="hub-project-footer">' +
          '<span>'+p.participant_count+' participant'+(p.participant_count===1?'':'s')+'</span>' +
          '<span class="hub-proj-ref-arrow">Open in '+esc(ownerLbl)+' →</span>' +
        '</div>' +
      '</div>';
    }

    // Owned project — full interaction
    var joinedMark = p.joined_by_me ? '<span style="color:var(--green);font-size:.72rem">✓ Joined</span>' : '';
    var phaseEnd = p.phase_target_end_at ? '<span>ends '+dt(p.phase_target_end_at)+'</span>' : '';
    return '<div class="hub-project-card" onclick="openProject('+p.id+')">' +
      '<div class="hub-proj-phase-row">' +
        '<span class="hub-status-chip '+statusCls+'">'+esc(phaseLabel(statusCls))+'</span>' +
        (p.phase_target_end_at ? '<span class="hub-proj-phase-end">ends '+dt(p.phase_target_end_at)+'</span>' : '') +
      '</div>' +
      '<div class="hub-proj-bar"><div class="hub-proj-bar-fill ph-'+statusCls+'" style="width:'+barWidth+'"></div></div>' +
      '<div class="hub-project-title">'+esc(p.title)+'</div>' +
      (p.summary?'<div class="hub-project-summary">'+esc(p.summary.substring(0,100))+(p.summary.length>100?'…':'')+'</div>':'') +
      '<div class="hub-project-footer">' +
        '<span>'+p.participant_count+' participant'+(p.participant_count===1?'':'s')+'</span>' +
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
    + '<div id="create-project-form-wrap"></div>' + '<div class="hub-section-top"><a onclick="window.scrollTo({top:0,behavior:\'smooth\'});return false;" href="#">↑ Back to top</a></div>';
}

function showCreateProject(){
  var fw = el('create-project-form-wrap');
  if(!fw) return;
  if(fw.innerHTML){fw.innerHTML=''; return;} // toggle

  // Trustee-only advanced section — cross-hub interest areas
  var trusteeAdv = '';
  if(_hubData && _hubData.is_trustee){
    var currentKey = window.HUB_AREA_KEY || '';
    var otherHubs = _HUB_NAV_ITEMS.filter(function(h){ return h.key !== currentKey; });
    var checkboxes = otherHubs.map(function(h){
      return '<label><input type="checkbox" class="cp-ia" value="'+esc(h.key)+'"> '+esc(h.label)+'</label>';
    }).join('');
    trusteeAdv =
      '<div class="hub-proj-trustee-adv">' +
        '<div class="hub-proj-trustee-adv-hd">Trustee · Link to other hubs (optional)</div>' +
        '<div style="font-size:.78rem;color:var(--text3);margin-bottom:10px;line-height:1.5">' +
          'Tag other hubs to make this project appear there as a read-only reference card. Each referenced hub can see the project; all interaction (join, comment, phase advance) remains here in the owner hub.' +
        '</div>' +
        '<div class="hub-proj-trustee-adv-grid">' + checkboxes + '</div>' +
      '</div>';
  }

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
      trusteeAdv +
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

  // Trustee-only: collect checked interest areas. Empty on non-Trustee flows.
  var interestKeys = [];
  var boxes = document.querySelectorAll('#create-project-form-wrap .cp-ia:checked');
  for(var i=0;i<boxes.length;i++){
    var v = (boxes[i].value||'').trim();
    if(v) interestKeys.push(v);
  }

  var btn = document.querySelector('#create-project-form-wrap .btn-gold');
  if(btn){ btn.disabled=true; btn.textContent='Creating…'; }
  try{
    var payload = {
      area_key: window.HUB_AREA_KEY,
      title:title, summary:summary.trim(), body:body.trim(),
      target_close_at: date||undefined
    };
    if(interestKeys.length) payload.interest_area_keys = interestKeys;
    var res = await api('vault/hub-projects',{method:'POST',body:JSON.stringify(payload)});
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

/* ── Cross-hub referenced project navigation ─────────────────────────────────
   Invoked when a Member clicks a referenced (read-only) project card.
   Navigates to the owner hub with ?project=<id> so the destination can
   auto-scroll and auto-open the project detail. Uses data-* attributes
   per JS architecture rule — no onclick string concatenation. */
function openReferencedProject(cardEl){
  if(!cardEl || !cardEl.dataset) return;
  var ownerKey = (cardEl.dataset.ownerKey || '').trim();
  var pid      = parseInt(cardEl.dataset.projectId || '0', 10);
  if(!ownerKey || !pid) return;
  // Validate against known hub list to avoid open-redirect via tampered DOM
  var known = _HUB_NAV_ITEMS.some(function(h){ return h.key === ownerKey; });
  if(!known) return;
  var path = '../' + ownerKey + '/index.html?project=' + pid;
  if(typeof coinTransition === 'function'){
    coinTransition(path, 'Opening in owner hub');
  }else{
    window.location.href = path;
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
window.hubChipClick = function(btn, targetId){
  document.querySelectorAll('.hub-hero-chip').forEach(function(c){ c.classList.remove('active'); });
  btn.classList.add('active');
  scrollToSection(targetId);
};
/* ── Hub nav dropdown ───────────────────────────────────────────────────────── */
var _HUB_NAV_ITEMS = [
  {key:'operations_oversight',  label:'Day-to-Day Operations',  path:'../operations_oversight/'},
  {key:'governance_polls',      label:'Research & Acquisitions', path:'../governance_polls/'},
  {key:'esg_proxy_voting',      label:'ESG & Proxy Voting',      path:'../esg_proxy_voting/'},
  {key:'first_nations',         label:'First Nations JV',        path:'../first_nations/'},
  {key:'community_projects',    label:'Community Projects',      path:'../community_projects/'},
  {key:'technology_blockchain', label:'Technology & Blockchain', path:'../technology_blockchain/'},
  {key:'financial_oversight',   label:'Financial Oversight',     path:'../financial_oversight/'},
  {key:'place_based_decisions', label:'Place-Based Decisions',   path:'../place_based_decisions/'},
  {key:'education_outreach',    label:'Education & Outreach',    path:'../education_outreach/'},
];

function renderHubNavDropdown(){
  var btn = document.getElementById('hub-nav-dropdown-btn');
  var panel = document.getElementById('hub-nav-panel');
  if(!btn || !panel) return;

  var currentKey = window.HUB_AREA_KEY || '';

  // Two-letter abbreviations for icon squares
  var _ABBR = {
    operations_oversight:  'OO', governance_polls:      'RA',
    esg_proxy_voting:      'ES', first_nations:         'FN',
    community_projects:    'CP', technology_blockchain: 'TB',
    financial_oversight:   'FO', place_based_decisions: 'PB',
    education_outreach:    'EO'
  };

  var html = '<div class="hub-nav-panel-hd">Management Hubs</div>';
  html += '<div class="hub-nav-section">';

  _HUB_NAV_ITEMS.forEach(function(h){
    var isCurrent = h.key === currentKey;
    var abbr = _ABBR[h.key] || h.label.slice(0,2).toUpperCase();
    html += '<button class="hub-nav-item'+(isCurrent?' current':'')+'"';
    html += ' data-hub-path="'+esc(h.path)+'" data-hub-label="'+esc(h.label)+'"';
    html += ' onclick="hubNavItemClick(this)">';
    html += '<span class="hub-nav-item-icon">'+abbr+'</span>';
    html += '<span class="hub-nav-item-label">'+esc(h.label)+'</span>';
    html += '<span class="hub-nav-item-check">✓</span>';
    html += '</button>';
  });

  html += '</div>';
  html += '<div class="hub-nav-panel-foot">';
  html += '<button class="hub-nav-mainspring-btn" data-hub-path="../mainspring/" data-hub-label="Mainspring" onclick="hubNavItemClick(this)">';
  html += 'All hubs overview — Mainspring →';
  html += '</button>';
  html += '</div>';

  panel.innerHTML = html;
}

function hubNavItemClick(el){
  var path  = el.dataset.hubPath;
  var label = el.dataset.hubLabel || 'Hub';
  hubNavGo(path, label);
}

function hubNavToggle(){
  var btn = document.getElementById('hub-nav-dropdown-btn');
  var panel = document.getElementById('hub-nav-panel');
  if(!btn || !panel) return;
  var isOpen = panel.classList.contains('open');
  if(isOpen){
    panel.classList.remove('open');
    btn.classList.remove('open');
  } else {
    renderHubNavDropdown();
    panel.classList.add('open');
    btn.classList.add('open');
  }
}

function hubNavGo(path, label){
  var panel = document.getElementById('hub-nav-panel');
  var btn   = document.getElementById('hub-nav-dropdown-btn');
  if(panel) panel.classList.remove('open');
  if(btn)   btn.classList.remove('open');
  coinTransition(path, 'Opening '+label);
}

// Close on outside click
document.addEventListener('click', function(e){
  var wrap = document.getElementById('hub-nav-dropdown-wrap');
  if(wrap && !wrap.contains(e.target)){
    var panel = document.getElementById('hub-nav-panel');
    var btn   = document.getElementById('hub-nav-dropdown-btn');
    if(panel) panel.classList.remove('open');
    if(btn)   btn.classList.remove('open');
  }
});

// Close on Escape
document.addEventListener('keydown', function(e){
  if(e.key === 'Escape'){
    var panel = document.getElementById('hub-nav-panel');
    var btn   = document.getElementById('hub-nav-dropdown-btn');
    if(panel) panel.classList.remove('open');
    if(btn)   btn.classList.remove('open');
  }
});

window.hubNavToggle    = hubNavToggle;
window.hubNavGo        = hubNavGo;
window.hubNavItemClick = hubNavItemClick;

window.switchForumTab      = switchForumTab;
window.toggleThread        = toggleThread;
window.postReply           = postReply;
window.postThread          = postThread;
window.showCreateProject   = showCreateProject;
window.cancelCreateProject = cancelCreateProject;
window.submitCreateProject = submitCreateProject;
window.openProject         = openProject;
window.openReferencedProject = openReferencedProject;
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


/* ── Info popout system ─────────────────────────────────────────────────────── */
// All popout definitions. key → {title, body (HTML string)}
var _INFO = {
  'hub-enrol': {
    title: 'Activate Participation',
    body: `<p>Joining a hub gives you write access — you can create projects, post in the forum, vote, and raise queries.</p><p>You remain in read-only mode until you activate.</p>`
  },
  'hub-leave': {
    title: 'Leave this hub',
    body: `<p>Leaving removes your write access. You can re-join at any time. Your past contributions remain in the record.</p>`
  },
  'hub-roster-vis': {
    title: 'Roster visibility',
    body: `<p>Controls whether your name appears in this hub's member list. Hiding yourself is a global setting — it applies to all hubs you belong to.</p>`
  },
  'hub-show-name': {
    title: 'Show your name',
    body: `<p>When you opt in, your first name is shown next to your membership number on the roster. When opted out, you appear as Anonymous.</p>`
  },
  'hub-status-live': {
    title: 'Hub status: Live',
    body: `<p>This hub is fully active. You can read, comment, create projects, and vote.</p>`
  },
  'hub-status-soon': {
    title: 'Hub status: Expansion Day',
    body: `<p>This hub activates at Expansion Day. You can read content now but write actions are not yet available.</p>`
  },
  'phase-draft': {
    title: 'Phase: Draft',
    body: `<p>The coordinator is still preparing the proposal. It's visible to participants but not open for community input yet.</p><p>Only the coordinator can advance to the next phase.</p>`
  },
  'phase-open_for_input': {
    title: 'Phase: Open for Input',
    body: `<p>The proposal is open for at least <strong>7 days</strong>. Read, comment, and join the project. This is where you ask questions and propose refinements.</p>`
  },
  'phase-deliberation': {
    title: 'Phase: Deliberation',
    body: `<p>At least <strong>7 days</strong> of structured debate on the refined proposal. Weigh arguments and prepare your position before voting opens.</p>`
  },
  'phase-vote': {
    title: 'Phase: Vote Open',
    body: `<p>Cast your consent vote — agree, disagree, block, or abstain. Minimum <strong>7 days</strong> (48 hours in certified urgency). A single block re-opens deliberation.</p>`
  },
  'phase-accountability': {
    title: 'Phase: Accountability',
    body: `<p>The proposal is adopted. The coordinator tracks delivery via milestones. All members can see progress and comment on execution.</p>`
  },
  'phase-advance': {
    title: 'Advance the phase',
    body: `<p>Moves this project to the next governance phase. Only the project coordinator can do this. Each phase has a minimum period — the button's label shows the next phase name.</p><p><a href="../../guide/" target="_blank">See the full lifecycle guide ›</a></p>`
  },
  'vote-agree': {
    title: '✔ Agree',
    body: `<p>I support this proposal and consent to its adoption. The outcome can proceed.</p>`
  },
  'vote-disagree': {
    title: '✗ Disagree',
    body: `<p>I do not support this proposal but accept the outcome if the majority agrees. Your disagreement is recorded but does not block adoption.</p>`
  },
  'vote-block': {
    title: '⛔ Block',
    body: `<p>I have a <strong>paramount objection</strong> based on the Foundation's purpose or governing principles. Requires written reasoning. <strong>A single block re-opens deliberation.</strong></p><p>This is not a personal veto — it must relate to the Foundation's purpose or rules.</p>`
  },
  'vote-abstain': {
    title: '○ Abstain',
    body: `<p>I am not expressing a position. I acknowledge the vote is proceeding and accept the outcome.</p>`
  },
  'vote-tally': {
    title: 'Live vote tally',
    body: `<p>Updates immediately when any enrolled member votes. You may change your position at any time while the Vote phase is open.</p>`
  },
  'milestone-list': {
    title: 'Delivery Milestones',
    body: `<p>Specific deliverables the coordinator has committed to. All members can see progress. Only the coordinator can add or toggle milestones.</p><p>Milestones feed the Foundation's quarterly evidence compilation under the JVPA Schedule.</p>`
  },
  'milestone-add': {
    title: 'Add a milestone',
    body: `<p>Describe a specific deliverable and set a target date. Members will see whether it has been completed.</p><p>Only available to the project coordinator in the Accountability phase.</p>`
  },
  'query-transparency': {
    title: 'Transparency level',
    body: `<p><strong>🔒 Private</strong> — only you and admins see this.<br><strong>👥 Hub Members</strong> — enrolled members in this hub see the resolution summary.<br><strong>📢 Public Record</strong> — the reply is broadcast to the entire hub.</p><p>Choose based on whether others would benefit from the answer.</p>`
  },
  'query-resolved': {
    title: 'Resolved this month',
    body: `<p>Questions raised by members that have been answered in the past 30 days. Only queries where the member chose Hub Members or Public Record transparency are shown here.</p><p>Private queries are never surfaced.</p>`
  },
  'ai-assistant': {
    title: '⬡ Governance Assistant',
    body: `<p>Answers governance questions about this hub — how the rules work, what phases mean, and how to interpret the Foundation's governing instruments.</p><p>Powered by Claude. Governance context only — it does not have access to your wallet or personal data.</p>`
  },
  'mainspring': {
    title: '⬡ Mainspring',
    body: `<p>An at-a-glance overview across all nine Management Hubs — project counts by phase, recent activity, and quick links to each hub area.</p>`
  },
};

// Active popout state
var _popoutEl = null;
var _popoutKey = null;

function showPopout(key, anchorEl){
  // Toggle off if same key
  if(_popoutKey === key && _popoutEl){ hidePopout(); return; }
  hidePopout();

  var def = _INFO[key];
  if(!def) return;

  var pop = document.createElement('div');
  pop.className = 'hub-popout';
  pop.setAttribute('role','tooltip');
  pop.innerHTML =
    '<div class="hub-popout-arrow"><div></div></div>' +
    '<div class="hub-popout-title">'+esc(def.title)+'</div>' +
    '<div class="hub-popout-body">'+def.body+'</div>';

  document.body.appendChild(pop);
  _popoutEl  = pop;
  _popoutKey = key;

  // Position relative to anchor
  var rect = anchorEl.getBoundingClientRect();
  var scrollY = window.scrollY || window.pageYOffset;
  var left = Math.min(rect.left, window.innerWidth - 280);
  left = Math.max(8, left);
  pop.style.top  = (rect.bottom + scrollY + 6) + 'px';
  pop.style.left = left + 'px';

  // Reposition arrow
  var arrow = pop.querySelector('.hub-popout-arrow');
  if(arrow) arrow.style.left = Math.max(8, rect.left - left + 3) + 'px';

  if(anchorEl) anchorEl.setAttribute('aria-expanded','true');
  _popoutAnchor = anchorEl;
}

var _popoutAnchor = null;

function hidePopout(){
  if(_popoutEl){ _popoutEl.remove(); _popoutEl = null; _popoutKey = null; }
  if(_popoutAnchor){ _popoutAnchor.setAttribute('aria-expanded','false'); _popoutAnchor = null; }
}

document.addEventListener('click', function(e){
  if(_popoutEl && !_popoutEl.contains(e.target) && !e.target.classList.contains('hub-info-btn')){
    hidePopout();
  }
}, true);

document.addEventListener('keydown', function(e){
  if(e.key === 'Escape') hidePopout();
});

function infoBtn(key){
  return '<button class="hub-info-btn" aria-label="More information" aria-expanded="false"'
    + ' data-info-key="'+key+'" onclick="showPopout(this.dataset.infoKey,this)">ⓘ</button>';
}

window.showPopout = showPopout;
window.hidePopout = hidePopout;

// ── Patch section titles with info buttons ───────────────────────────────────
// Called once after renderAll() to decorate static section headings
function attachInfoBtns(){
  // Stat labels
  var statMembers = document.querySelector('#stat-members .hub-stat-l');
  if(statMembers && !statMembers.querySelector('.hub-info-btn'))
    statMembers.innerHTML += infoBtn('hub-roster-vis');

  // Phase chip in project detail (delegated — handled in renderProjectDetail)
  // Roster vis / show name buttons
  var rvWrap = el('hub-roster-vis-wrap');
  if(rvWrap && !rvWrap.querySelector('.hub-info-btn'))
    rvWrap.insertAdjacentHTML('beforeend', infoBtn('hub-roster-vis'));
}

// ── Override renderProjectDetail to inject info buttons ───────────────────────
// We monkey-patch by wrapping the wrap.innerHTML setter with a MutationObserver
// (simpler: call injectProjectInfoBtns() at end of renderProjectDetail)
var _origRenderProjectDetail = renderProjectDetail;
renderProjectDetail = function(){
  _origRenderProjectDetail.apply(this, arguments);
  injectProjectInfoBtns();
};

function injectProjectInfoBtns(){
  // Phase banner info button on the phase chip
  var detailWrap = el('hub-project-detail');
  if(!detailWrap) return;
  var chips = detailWrap.querySelectorAll('.hub-status-chip');
  chips.forEach(function(chip){
    if(chip.querySelector('.hub-info-btn')) return;
    var cls = Array.from(chip.classList).find(function(c){ return c !== 'hub-status-chip'; });
    if(cls && _INFO['phase-'+cls]) chip.insertAdjacentHTML('beforeend', infoBtn('phase-'+cls));
  });

  // Advance button — add info icon next to it
  var advBtn = detailWrap.querySelector('[onclick*="advancePhase"]');
  if(advBtn && !advBtn.previousElementSibling?.classList.contains('hub-info-btn')){
    advBtn.insertAdjacentHTML('beforebegin', infoBtn('phase-advance')+' ');
  }

  // Consent vote section title
  detailWrap.querySelectorAll('.hub-section-title').forEach(function(t){
    if(t.textContent.trim()==='Consent Vote' && !t.querySelector('.hub-info-btn'))
      t.insertAdjacentHTML('beforeend', infoBtn('vote-tally'));
    if(t.textContent.trim()==='Delivery Milestones' && !t.querySelector('.hub-info-btn'))
      t.insertAdjacentHTML('beforeend', infoBtn('milestone-list'));
  });

  // Vote buttons
  var voteMap = {'.hub-vote-agree':'vote-agree','.hub-vote-disagree':'vote-disagree','.hub-vote-block':'vote-block','.hub-vote-abstain':'vote-abstain'};
  Object.keys(voteMap).forEach(function(sel){
    var btn = detailWrap.querySelector(sel);
    if(btn && !btn.previousElementSibling?.classList.contains('hub-info-btn'))
      btn.insertAdjacentHTML('afterend', ' '+infoBtn(voteMap[sel]));
  });

  // Milestone add label
  var msLabel = detailWrap.querySelector('#ms-label');
  if(msLabel){
    var msAddBtn = detailWrap.querySelector('[onclick*="addMilestone"]');
    if(msAddBtn && !msAddBtn.nextElementSibling?.classList.contains('hub-info-btn'))
      msAddBtn.insertAdjacentHTML('afterend', ' '+infoBtn('milestone-add'));
  }
}

// ── Patch renderQuerySection for transparency info button ─────────────────────
var _origShowQueryForm = showQueryForm;
showQueryForm = function(){
  _origShowQueryForm.apply(this, arguments);
  // After the form renders, add info button next to transparency label
  setTimeout(function(){
    var transLabel = document.querySelector('#hub-query-form-wrap label');
    if(transLabel && !transLabel.querySelector('.hub-info-btn'))
      transLabel.insertAdjacentHTML('beforeend', infoBtn('query-transparency'));
  }, 50);
};

// ── Patch renderProjects for resolved-queries info button ─────────────────────
var _origRenderProjects = renderProjects;
renderProjects = function(){
  _origRenderProjects.apply(this, arguments);
  var rqBlock = el('rq-block');
  if(rqBlock && rqBlock.firstElementChild){
    var rqTitle = rqBlock.querySelector('div');
    if(rqTitle && !rqTitle.querySelector('.hub-info-btn'))
      rqTitle.insertAdjacentHTML('beforeend', ' '+infoBtn('query-resolved'));
  }
};

// ── Override renderAll to call attachInfoBtns ─────────────────────────────────
var _origRenderAll = renderAll;
renderAll = function(){
  _origRenderAll.apply(this, arguments);
  attachInfoBtns();
};

/* ── Start ───────────────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', function(){
  boot();
  renderQuerySection();
});

})();

/* ══════════════════════════════════════════════════════════════════════════════
   BLOCK 3 — Hub Admin Activity
   loadAdminActivity(areaKey) — called from DOMContentLoaded on each hub page.
   Fetches /vault/hub-admin-activity and renders into #hub-admin-activity.
   Not enrolled → shows Activate Participation prompt.
   Empty activity → renders empty state, still shows admin pages panel.
   ══════════════════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  // Private copies of esc/dts — block 2 IIFE versions are out of scope here
  function _esc(s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function _dts(s) {
    if (!s) return '—';
    try {
      return new Date(s).toLocaleDateString('en-AU', {
        day: '2-digit', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit'
      });
    } catch (e) { return s; }
  }

  var _activityLoaded = false;

  /**
   * Fetch and render the Operational Activity card for the current hub.
   * Safe to call multiple times — renders only once per page load.
   */
  async function loadAdminActivity(areaKey) {
    if (_activityLoaded) return;
    _activityLoaded = true;

    var wrap = document.getElementById('hub-admin-activity');
    if (!wrap) return;

    var ROOT_LOCAL = document.body.dataset.root || '../../';
    var API_LOCAL = ROOT_LOCAL + '_app/api/index.php?route=';

    wrap.innerHTML = '<div class="hub-loading" style="font-size:.82rem">Loading operational activity…</div>';

    var res, data;
    try {
      res = await fetch(
        API_LOCAL + 'vault/hub-admin-activity&area_key=' + encodeURIComponent(areaKey) + '&limit=8',
        { credentials: 'include' }
      );
      data = await res.json();
    } catch (e) {
      wrap.innerHTML = '<div class="hub-empty" style="font-size:.82rem">Operational activity unavailable.</div>';
      return;
    }

    // Auth failure or not enrolled
    if (res.status === 401 || res.status === 403) {
      wrap.innerHTML = '<div class="hub-empty" style="font-size:.82rem">Activate Participation to view operational activity for this hub.</div>';
      return;
    }

    if (!data || !data.success) {
      wrap.innerHTML = '<div class="hub-empty" style="font-size:.82rem">Operational activity unavailable.</div>';
      return;
    }

    var pages   = (data.data && data.data.admin_pages)  || { primary: [], secondary: [] };
    var events  = (data.data && data.data.activity)     || [];
    var html    = '';

    // ── Admin pages panel ──────────────────────────────────────────────────
    if (pages.primary.length || pages.secondary.length) {
      html += '<div class="hub-activity-pages">';
      html += '<div class="hub-activity-pages-title">Admin functions serving this hub</div>';
      html += '<div class="hub-activity-chips">';
      pages.primary.forEach(function (p) {
        html += '<span class="hub-activity-chip hub-activity-chip-primary">' + _esc(p.label) + '</span>';
      });
      pages.secondary.forEach(function (p) {
        html += '<span class="hub-activity-chip hub-activity-chip-secondary">' + _esc(p.label) + '</span>';
      });
      html += '</div></div>';
    }

    // ── Activity feed ──────────────────────────────────────────────────────
    if (!events.length) {
      html += '<div class="hub-empty" style="font-size:.82rem;margin-top:12px">No recent operational activity recorded yet.</div>';
    } else {
      html += '<div class="hub-activity-feed">';
      events.forEach(function (ev) {
        var timeStr = '';
        try { timeStr = _dts(ev.ts); } catch (e) { timeStr = ev.ts || ''; }
        html += '<div class="hub-activity-item">' +
          '<div class="hub-activity-item-inner">' +
            '<span class="hub-activity-source">' + _esc(ev.source || '') + '</span>' +
            '<span class="hub-activity-summary">' + _esc(ev.summary || '') + '</span>' +
          '</div>' +
          '<span class="hub-activity-ts">' + _esc(timeStr) + '</span>' +
        '</div>';
      });
      html += '</div>';
    }

    wrap.innerHTML = html;
    // Append hub-specific live data if present
    var _hd = (data.data && data.data.hub_data) || {};
    if (Object.keys(_hd).length) { _renderHubData(areaKey, _hd, wrap); }
  }

  window.loadAdminActivity = loadAdminActivity;

  /* ── Hub-specific live data renderer ──────────────────────────────────────
     One branch per area_key. Returns nothing — mutates container via
     insertAdjacentHTML. All data already sanitised server-side; _esc()
     used as belt-and-braces on display strings. */
  function _renderHubData(areaKey, hd, container) {
    var html = '';

    // ── 1. Operations Oversight ───────────────────────────────────────────
    if (areaKey === 'operations_oversight') {
      html += '<div class="hub-livedata-section">';
      html += '<div class="hub-livedata-title">Live Operational Data</div>';

      // Exception counts
      if (hd.exceptions) {
        var ex  = hd.exceptions;
        var sev = ex.by_severity || {};
        var hi  = sev['high']   || 0;
        var med = sev['medium'] || 0;
        var lo  = sev['low']    || 0;
        html += '<div class="hub-livedata-row">';
        html += '<span class="hub-livedata-label">Open exceptions</span>';
        html += '<span class="hub-livedata-val' + (ex.open_count > 0 ? ' lvd-issues' : '') + '">' + ex.open_count + '</span>';
        if (ex.open_count > 0) {
          html += '<span class="hub-livedata-chips">';
          if (hi)  html += '<span class="sev-chip sev-high">'   + hi  + ' high</span>';
          if (med) html += '<span class="sev-chip sev-med">'    + med + ' med</span>';
          if (lo)  html += '<span class="sev-chip sev-low">'    + lo  + ' low</span>';
          html += '</span>';
        } else {
          html += '<span class="hub-livedata-ok">✓ All clear</span>';
        }
        html += '</div>';
      }

      // Pending approvals
      if (typeof hd.pending_approvals !== 'undefined') {
        html += '<div class="hub-livedata-row">';
        html += '<span class="hub-livedata-label">Pending approvals</span>';
        html += '<span class="hub-livedata-val">' + hd.pending_approvals + '</span>';
        html += '</div>';
      }

      // Recently resolved exceptions
      if (hd.recent_resolved && hd.recent_resolved.length) {
        html += '<div class="hub-livedata-subtitle">Recently resolved</div>';
        html += '<div class="hub-livedata-list">';
        hd.recent_resolved.forEach(function(r) {
          html += '<div class="hub-livedata-item">' +
            '<div class="hub-livedata-item-inner">' +
              '<span class="hub-livedata-type">' + _esc(r.exception_type) + '</span>' +
              '<span class="hub-livedata-summary">' + _esc(r.summary) + '</span>' +
            '</div>' +
            '<span class="hub-livedata-ts">' + _dts(r.resolved_at) + '</span>' +
          '</div>';
        });
        html += '</div>';
      }

            // Pending approvals by type
      if (hd.pending_by_type && Object.keys(hd.pending_by_type).length) {
        html += '<div class="hub-livedata-subtitle" style="margin-top:10px">Pending approvals by type</div>';
        Object.keys(hd.pending_by_type).forEach(function(type) {
          html += '<div class="hub-livedata-row">';
          html += '<span class="hub-livedata-label">' + _esc(type.replace(/_/g,' ')) + '</span>';
          html += '<span class="hub-livedata-val">' + hd.pending_by_type[type] + '</span></div>';
        });
      }
      // Overdue transfers
      if (typeof hd.overdue_transfer_count !== 'undefined') {
        html += '<div class="hub-livedata-row"><span class="hub-livedata-label">Overdue trust transfers</span>';
        html += '<span class="hub-livedata-val' + (hd.overdue_transfer_count > 0 ? ' lvd-issues' : '') + '">' + hd.overdue_transfer_count + '</span>';
        if (hd.overdue_transfer_count === 0) html += '<span class="hub-livedata-ok">✓ None overdue</span>';
        html += '</div>';
      }
      // ── TDR: Operational Trustee Decisions ─────────────────────────
      if (hd.tdr_operational && hd.tdr_operational.length) {
        html += '<div class="hub-livedata-subtitle" style="margin-top:14px">Trustee Decision Records (operational)</div>';
        html += '<div class="hub-livedata-list">';
        hd.tdr_operational.forEach(function(tdr) {
          var cat = _esc(tdr.category.replace(/_/g,' '));
          var ctx = tdr.context !== 'all' ? ' · ST ' + _esc(tdr.context.replace('sub_trust_','')) : '';
          var badges = '';
          if (tdr.fnac) badges += '<span class="sev-chip sev-low">FNAC</span>';
          if (tdr.fpic) badges += '<span class="sev-chip sev-low">FPIC</span>';
          if (tdr.visibility === 'public') badges += '<span class="sev-chip inv-pass">Public</span>';
          html += '<div class="hub-livedata-item hub-tdr-item">';
          html += '<div class="hub-livedata-item-inner">';
          html += '<span class="hub-tdr-ref">' + _esc(tdr.ref) + '</span>';
          html += '<span class="hub-livedata-summary">' + _esc(tdr.title) + '</span>';
          html += badges;
          html += '</div>';
          html += '<div class="hub-tdr-meta">' + cat + ctx + '<span class="hub-livedata-ts">' + _esc(tdr.effective) + '</span></div>';
          html += '</div>';
        }); html += '</div>';
      } else if (typeof hd.tdr_operational !== 'undefined') {
        html += '<div class="hub-livedata-subtitle" style="margin-top:14px">Trustee Decision Records (operational)</div>';
        html += '<div class="hub-empty" style="font-size:.82rem">No executed operational TDRs yet.</div>';
      }

      // ── TDR: Deed Execution Records ─────────────────────────────────
      if (hd.deed_records && hd.deed_records.length) {
        html += '<div class="hub-livedata-subtitle" style="margin-top:12px">Legal instrument execution records</div>';
        html += '<div class="hub-livedata-list">';
        hd.deed_records.forEach(function(dr) {
          var statusClass = dr.status === 'fully_executed' ? 'inv-pass' : 'sev-med';
          html += '<div class="hub-livedata-item">';
          html += '<div class="hub-livedata-item-inner">';
          html += '<span class="hub-tdr-ref">' + _esc(dr.deed_key) + '</span>';
          html += '<span class="hub-livedata-summary">' + _esc(dr.title) + '</span>';
          html += '<span class="sev-chip ' + statusClass + '">' + _esc(dr.status.replace(/_/g,' ')) + '</span>';
          html += '</div>';
          html += '<span class="hub-livedata-ts">' + _esc(dr.date) + '</span>';
          html += '</div>';
        }); html += '</div>';
      }

      // ── TDR: Trustee Counterpart Record ─────────────────────────────
      if (hd.trustee_counterpart) {
        var tcr = hd.trustee_counterpart;
        html += '<div class="hub-livedata-subtitle" style="margin-top:12px">Trustee Counterpart Record (JVPA cl. 10.10A)</div>';
        html += '<div class="hub-tdr-tcr-card">';
        html += '<div class="hub-livedata-row"><span class="hub-livedata-label">Caretaker Trustee</span><span class="hub-livedata-val" style="font-size:.9rem">' + _esc(tcr.trustee_name) + '</span></div>';
        html += '<div class="hub-livedata-row"><span class="hub-livedata-label">JVPA</span><span class="hub-livedata-val" style="font-size:.82rem">' + _esc(tcr.jvpa_version) + ' · executed ' + _esc(tcr.jvpa_date) + '</span></div>';
        html += '<div class="hub-livedata-row"><span class="hub-livedata-label">Acceptance recorded</span><span class="hub-livedata-val" style="font-size:.82rem">' + _esc(tcr.accepted_date) + '</span></div>';
        html += '<div class="hub-livedata-row"><span class="hub-livedata-label">Record SHA-256</span><span class="hub-livedata-val" style="font-size:.78rem;font-family:monospace">' + _esc(tcr.sha256_prefix) + '…</span></div>';
        html += '<div class="hub-livedata-row"><span class="hub-livedata-label">Status</span><span class="sev-chip inv-pass">Active</span></div>';
        html += '</div>';
      }

html += '</div>'; // .hub-livedata-section
    }

    // ── 2. Research & Acquisitions ────────────────────────────────────────
    else if (areaKey === 'governance_polls') {
      html += '<div class="hub-livedata-section">';
      html += '<div class="hub-livedata-title">Live Research Data</div>';

      // Active proposals
      html += '<div class="hub-livedata-row">';
      html += '<span class="hub-livedata-label">Active proposals</span>';
      var propCount = (typeof hd.open_proposal_count !== 'undefined') ? hd.open_proposal_count : '—';
      html += '<span class="hub-livedata-val' + (propCount > 0 ? ' lvd-active' : '') + '">' + propCount + '</span>';
      html += '</div>';
      if (hd.open_proposals && hd.open_proposals.length) {
        html += '<div class="hub-livedata-list">';
        hd.open_proposals.forEach(function(p) {
          var closeStr = p.closes_at ? (' — closes ' + _dts(p.closes_at)) : '';
          html += '<div class="hub-livedata-item">' +
            '<div class="hub-livedata-item-inner">' +
              '<span class="hub-livedata-type">Proposal</span>' +
              '<span class="hub-livedata-summary">' + _esc(p.title) + '</span>' +
            '</div>' +
            '<span class="hub-livedata-ts">' + _esc(closeStr) + '</span>' +
          '</div>';
        });
        html += '</div>';
      }

      // Portfolio holdings
      if (typeof hd.holdings_count !== 'undefined') {
        html += '<div class="hub-livedata-row">';
        html += '<span class="hub-livedata-label">Portfolio holdings</span>';
        html += '<span class="hub-livedata-val">' + hd.holdings_count + '</span>';
        html += '</div>';
      }

      // Recent settled trades
      if (hd.recent_trades && hd.recent_trades.length) {
        html += '<div class="hub-livedata-subtitle">Recent settled trades</div>';
        html += '<div class="hub-livedata-list">';
        hd.recent_trades.forEach(function(t) {
          html += '<div class="hub-livedata-item">' +
            '<div class="hub-livedata-item-inner">' +
              '<span class="hub-livedata-type">' + _esc(t.ticker) + '</span>' +
              '<span class="hub-livedata-summary">' + Number(t.units).toLocaleString('en-AU') + ' units</span>' +
            '</div>' +
            '<span class="hub-livedata-ts">' + _esc(t.trade_date) + '</span>' +
          '</div>';
        });
        html += '</div>';
      }

            // Decided proposals
      if (hd.closed_proposals && hd.closed_proposals.length) {
        html += '<div class="hub-livedata-subtitle" style="margin-top:10px">Decided proposals</div>';
        html += '<div class="hub-livedata-list">';
        hd.closed_proposals.forEach(function(p) {
          html += '<div class="hub-livedata-item"><div class="hub-livedata-item-inner">';
          html += '<span class="hub-livedata-type">' + _esc((p.proposal_type||'').replace(/_/g,' ')) + '</span>';
          html += '<span class="hub-livedata-summary">' + _esc(p.title) + '</span>';
          html += '<span class="sev-chip sev-low">' + _esc(p.status) + '</span>';
          html += '</div><span class="hub-livedata-ts">' + _dts(p.updated_at) + '</span></div>';
        }); html += '</div>';
      }
      // Binding poll outcomes
      if (hd.closed_polls && hd.closed_polls.length) {
        html += '<div class="hub-livedata-subtitle" style="margin-top:8px">Binding poll outcomes</div>';
        html += '<div class="hub-livedata-list">';
        hd.closed_polls.forEach(function(p) {
          html += '<div class="hub-livedata-item"><div class="hub-livedata-item-inner">';
          html += '<span class="hub-livedata-type">Poll</span>';
          html += '<span class="hub-livedata-summary">' + _esc(p.title) + '</span>';
          html += '<span class="sev-chip sev-low">' + _esc(p.status) + '</span>';
          html += '</div><span class="hub-livedata-ts">' + _esc((p.closed_at||'').split(' ')[0]) + '</span></div>';
        }); html += '</div>';
      }
      // Business partners
      if (typeof hd.business_partner_count !== 'undefined') {
        html += '<div class="hub-livedata-row"><span class="hub-livedata-label">Business Partners registered</span>';
        html += '<span class="hub-livedata-val">' + hd.business_partner_count + '</span></div>';
      }
      // ── TDR: Investment & Poll Implementation ───────────────────────
      if (hd.tdr_investment && hd.tdr_investment.length) {
        html += '<div class="hub-livedata-subtitle" style="margin-top:14px">Trustee Decision Records (investment & polls)</div>';
        html += '<div class="hub-livedata-list">';
        hd.tdr_investment.forEach(function(tdr) {
          var cat = _esc(tdr.category.replace(/_/g,' '));
          var ctx = tdr.context !== 'all' ? ' · ST ' + _esc(tdr.context.replace('sub_trust_','')) : '';
          html += '<div class="hub-livedata-item hub-tdr-item">';
          html += '<div class="hub-livedata-item-inner">';
          html += '<span class="hub-tdr-ref">' + _esc(tdr.ref) + '</span>';
          html += '<span class="hub-livedata-summary">' + _esc(tdr.title) + '</span>';
          if (tdr.visibility === 'public') html += '<span class="sev-chip inv-pass">Public</span>';
          html += '</div>';
          html += '<div class="hub-tdr-meta">' + cat + ctx + '<span class="hub-livedata-ts">' + _esc(tdr.effective) + '</span></div>';
          html += '</div>';
        }); html += '</div>';
      } else if (typeof hd.tdr_investment !== 'undefined') {
        html += '<div class="hub-livedata-subtitle" style="margin-top:14px">Trustee Decision Records (investment & polls)</div>';
        html += '<div class="hub-empty" style="font-size:.82rem">No executed investment TDRs yet.</div>';
      }

html += '</div>'; // .hub-livedata-section
    }

    // ── 3. ESG & Proxy Voting ────────────────────────────────────────────
    else if (areaKey === 'esg_proxy_voting') {
      html += '<div class="hub-livedata-section">';
      html += '<div class="hub-livedata-title">Portfolio ESG Status</div>';

      if (typeof hd.poor_esg_count !== 'undefined') {
        html += '<div class="hub-livedata-row">';
        html += '<span class="hub-livedata-label">Poor ESG targets in portfolio</span>';
        html += '<span class="hub-livedata-val' + (hd.poor_esg_count > 0 ? ' lvd-issues' : '') + '">' + hd.poor_esg_count + '</span>';
        if (hd.poor_esg_count === 0) html += '<span class="hub-livedata-ok">✓ None flagged</span>';
        html += '</div>';
      }

      if (hd.holdings && hd.holdings.length) {
        html += '<div class="hub-livedata-subtitle">Holdings</div>';
        html += '<div class="hub-livedata-list">';
        hd.holdings.forEach(function(hg) {
          html += '<div class="hub-livedata-item">' +
            '<div class="hub-livedata-item-inner">' +
              '<span class="hub-livedata-type">' + _esc(hg.ticker) + '</span>' +
              '<span class="hub-livedata-summary">' + _esc(hg.company_name) + '</span>' +
              (hg.is_poor_esg_target ? '<span class="esg-poor-badge">Poor ESG</span>' : '') +
            '</div>' +
            '<span class="hub-livedata-ts">' + Number(hg.units_held).toLocaleString('en-AU') + ' units</span>' +
          '</div>';
        });
        html += '</div>';
      }

      if (hd.recent_engagements && hd.recent_engagements.length) {
        html += '<div class="hub-livedata-subtitle">Recent proxy engagements</div>';
        html += '<div class="hub-livedata-list">';
        hd.recent_engagements.forEach(function(eg) {
          var typeLabel = _esc(eg.engagement_type.replace(/_/g, ' '));
          html += '<div class="hub-livedata-item">' +
            '<div class="hub-livedata-item-inner">' +
              '<span class="hub-livedata-type">' + _esc(eg.ticker) + '</span>' +
              '<span class="hub-livedata-summary">' + typeLabel + ' — ' + _esc(eg.status) + '</span>' +
            '</div>' +
            '<span class="hub-livedata-ts">' + _esc(eg.meeting_date) + '</span>' +
          '</div>';
        });
        html += '</div>';
      }

            // Purchase history
      if (hd.settled_trades_detail && hd.settled_trades_detail.length) {
        html += '<div class="hub-livedata-subtitle" style="margin-top:10px">Purchase history (settled)</div>';
        html += '<div class="hub-livedata-list">';
        hd.settled_trades_detail.forEach(function(t) {
          var price = t.price_cents > 0 ? ' @ $' + (t.price_cents/100).toFixed(4) : '';
          html += '<div class="hub-livedata-item"><div class="hub-livedata-item-inner">';
          html += '<span class="hub-livedata-type">' + _esc(t.ticker) + '</span>';
          html += '<span class="hub-livedata-summary">' + Number(t.units).toLocaleString('en-AU') + ' units' + _esc(price) + '</span>';
          html += '</div><span class="hub-livedata-ts">' + _esc(t.trade_date) + '</span></div>';
        }); html += '</div>';
      }
      // Token class breakdown
      if (hd.token_classes && hd.token_classes.length) {
        html += '<div class="hub-livedata-subtitle" style="margin-top:8px">COGⓢ classes</div>';
        html += '<div class="hub-livedata-list">';
        hd.token_classes.forEach(function(tc) {
          var price = tc.unit_price_cents > 0 ? ' — $' + (tc.unit_price_cents/100).toFixed(2) : '';
          html += '<div class="hub-livedata-item"><div class="hub-livedata-item-inner">';
          html += '<span class="hub-livedata-type">' + _esc(tc.class_code) + '</span>';
          html += '<span class="hub-livedata-summary">' + _esc(tc.display_name) + _esc(price) + '</span>';
          html += '<span class="sev-chip sev-low">' + _esc(tc.member_type) + '</span>';
          html += '</div></div>';
        }); html += '</div>';
      }
html += '</div>';
    }

    // ── 4. First Nations Joint Venture ───────────────────────────────────
    else if (areaKey === 'first_nations') {
      html += '<div class="hub-livedata-section">';
      html += '<div class="hub-livedata-title">First Nations Joint Venture Data</div>';

      if (typeof hd.country_overlay_count !== 'undefined') {
        html += '<div class="hub-livedata-row">';
        html += '<span class="hub-livedata-label">Active Country overlays</span>';
        html += '<span class="hub-livedata-val">' + hd.country_overlay_count + '</span>';
        html += '</div>';
      }
      if (hd.country_overlays && hd.country_overlays.length) {
        html += '<div class="hub-livedata-list">';
        hd.country_overlays.forEach(function(z) {
          html += '<div class="hub-livedata-item">' +
            '<div class="hub-livedata-item-inner">' +
              '<span class="hub-livedata-type">Country</span>' +
              '<span class="hub-livedata-summary">' + _esc(z.zone_name) + '</span>' +
            '</div>' +
            '<span class="hub-livedata-ts">' + _esc(z.effective_date) + '</span>' +
          '</div>';
        });
        html += '</div>';
      }

      if (hd.fn_grants) {
        var fg = hd.fn_grants;
        var disbursedAUD = fg.total_disbursed
          ? '$' + (fg.total_disbursed / 100).toLocaleString('en-AU', {minimumFractionDigits:2,maximumFractionDigits:2})
          : '$0.00';
        html += '<div class="hub-livedata-row">';
        html += '<span class="hub-livedata-label">First Nations grants</span>';
        html += '<span class="hub-livedata-val">' + fg.total_count + '</span>';
        html += '</div>';
        html += '<div class="hub-livedata-row">';
        html += '<span class="hub-livedata-label">Total disbursed</span>';
        html += '<span class="hub-livedata-val lvd-active">' + disbursedAUD + '</span>';
        html += '</div>';
      }

      if (hd.recent_fnac_reviews && hd.recent_fnac_reviews.length) {
        html += '<div class="hub-livedata-subtitle">Recent FNAC reviews</div>';
        html += '<div class="hub-livedata-list">';
        hd.recent_fnac_reviews.forEach(function(r) {
          html += '<div class="hub-livedata-item">' +
            '<div class="hub-livedata-item-inner">' +
              '<span class="hub-livedata-type">' + _esc(r.status) + '</span>' +
              '<span class="hub-livedata-summary">' + _esc(r.review_key.replace(/_/g,' ')) + '</span>' +
            '</div>' +
            '<span class="hub-livedata-ts">' + _dts(r.created_at) + '</span>' +
          '</div>';
        });
        html += '</div>';
      }

            // Zone challenges
      if (typeof hd.active_zone_challenges !== 'undefined') {
        if (hd.active_zone_challenges.length) {
          html += '<div class="hub-livedata-subtitle" style="margin-top:10px">Active zone challenges</div>';
          html += '<div class="hub-livedata-list">';
          hd.active_zone_challenges.forEach(function(zc) {
            html += '<div class="hub-livedata-item"><div class="hub-livedata-item-inner">';
            html += '<span class="hub-livedata-type">' + _esc(zc.status) + '</span>';
            html += '<span class="hub-livedata-summary">' + _esc(zc.zone_name) + ' — ' + _esc(zc.summary) + '</span>';
            html += '</div><span class="hub-livedata-ts">' + _dts(zc.created_at) + '</span></div>';
          }); html += '</div>';
        } else {
          html += '<div class="hub-livedata-row"><span class="hub-livedata-label">Zone challenges</span><span class="hub-livedata-ok">✓ None active</span></div>';
        }
      }
      // Evidence reviews
      if (hd.evidence_reviews && hd.evidence_reviews.length) {
        html += '<div class="hub-livedata-subtitle" style="margin-top:8px">Evidence & FPIC reviews</div>';
        html += '<div class="hub-livedata-list">';
        hd.evidence_reviews.forEach(function(er) {
          html += '<div class="hub-livedata-item"><div class="hub-livedata-item-inner">';
          html += '<span class="hub-livedata-type">' + _esc(er.review_type.replace(/_/g,' ')) + '</span>';
          html += '<span class="hub-livedata-summary">' + _esc(er.subject_type.replace(/_/g,' ')) + '</span>';
          html += '<span class="sev-chip sev-low">' + _esc(er.review_status) + '</span>';
          html += '</div><span class="hub-livedata-ts">' + _dts(er.created_at) + '</span></div>';
        }); html += '</div>';
      }
      // ── TDR: FNAC Engagement Decisions ──────────────────────────────
      if (hd.tdr_fnac && hd.tdr_fnac.length) {
        html += '<div class="hub-livedata-subtitle" style="margin-top:14px">Trustee Decision Records (First Nations engagement)</div>';
        html += '<div class="hub-livedata-list">';
        hd.tdr_fnac.forEach(function(tdr) {
          var badges = '';
          if (tdr.fnac)             badges += '<span class="sev-chip sev-low">FNAC consulted</span>';
          if (tdr.fpic)             badges += '<span class="sev-chip sev-low">FPIC obtained</span>';
          if (tdr.cultural_assessed)badges += '<span class="sev-chip sev-low">Cultural assessed</span>';
          if (tdr.visibility === 'public') badges += '<span class="sev-chip inv-pass">Public</span>';
          html += '<div class="hub-livedata-item hub-tdr-item">';
          html += '<div class="hub-livedata-item-inner">';
          html += '<span class="hub-tdr-ref">' + _esc(tdr.ref) + '</span>';
          html += '<span class="hub-livedata-summary">' + _esc(tdr.title) + '</span>';
          html += badges;
          html += '</div>';
          html += '<div class="hub-tdr-meta">FNAC engagement<span class="hub-livedata-ts">' + _esc(tdr.effective) + '</span></div>';
          html += '</div>';
        }); html += '</div>';
      } else if (typeof hd.tdr_fnac !== 'undefined') {
        html += '<div class="hub-livedata-subtitle" style="margin-top:14px">Trustee Decision Records (First Nations engagement)</div>';
        html += '<div class="hub-empty" style="font-size:.82rem">No executed FNAC engagement TDRs yet.</div>';
      }

html += '</div>';
    }

    // ── 5. Community Projects ────────────────────────────────────────────
    else if (areaKey === 'community_projects') {
      html += '<div class="hub-livedata-section">';
      html += '<div class="hub-livedata-title">Community Benefit Data</div>';

      if (hd.grants) {
        var gr = hd.grants;
        var disbAUD = gr.total_disbursed
          ? '$' + (gr.total_disbursed / 100).toLocaleString('en-AU', {minimumFractionDigits:2,maximumFractionDigits:2})
          : '$0.00';
        var bs = gr.by_status || {};
        html += '<div class="hub-livedata-row">';
        html += '<span class="hub-livedata-label">Total grants</span>';
        html += '<span class="hub-livedata-val">' + gr.total_count + '</span>';
        if (gr.total_count > 0) {
          var chips = '';
          ['proposed','approved','disbursed','acquitted','cancelled'].forEach(function(s) {
            if (bs[s]) chips += '<span class="sev-chip sev-low">' + bs[s] + ' ' + s + '</span>';
          });
          if (chips) html += '<span class="hub-livedata-chips">' + chips + '</span>';
        }
        html += '</div>';
        html += '<div class="hub-livedata-row">';
        html += '<span class="hub-livedata-label">Total disbursed</span>';
        html += '<span class="hub-livedata-val lvd-active">' + disbAUD + '</span>';
        html += '</div>';
      }

      if (typeof hd.trust_income_12m !== 'undefined' && hd.trust_income_12m > 0) {
        var incAUD = '$' + (hd.trust_income_12m / 100).toLocaleString('en-AU', {minimumFractionDigits:2,maximumFractionDigits:2});
        html += '<div class="hub-livedata-row">';
        html += '<span class="hub-livedata-label">Trust income (12 months)</span>';
        html += '<span class="hub-livedata-val">' + incAUD + '</span>';
        html += '</div>';
      }

      if (hd.open_announcements && hd.open_announcements.length) {
        html += '<div class="hub-livedata-subtitle">Open announcements</div>';
        html += '<div class="hub-livedata-list">';
        hd.open_announcements.forEach(function(a) {
          html += '<div class="hub-livedata-item">' +
            '<div class="hub-livedata-item-inner">' +
              '<span class="hub-livedata-type">Open</span>' +
              '<span class="hub-livedata-summary">' + _esc(a.title) + '</span>' +
            '</div>' +
            '<span class="hub-livedata-ts">' + (a.closes_at ? 'closes ' + _dts(a.closes_at) : '') + '</span>' +
          '</div>';
        });
        html += '</div>';
      }

            // Recent grants (titles + types, no grantee PII)
      if (hd.recent_grants && hd.recent_grants.length) {
        html += '<div class="hub-livedata-subtitle" style="margin-top:10px">Recent approved grants</div>';
        html += '<div class="hub-livedata-list">';
        hd.recent_grants.forEach(function(g) {
          var amt = '$' + (g.amount_cents/100).toLocaleString('en-AU',{minimumFractionDigits:2,maximumFractionDigits:2});
          html += '<div class="hub-livedata-item"><div class="hub-livedata-item-inner">';
          html += '<span class="hub-livedata-type">' + _esc((g.grant_type||'').replace(/_/g,' ')) + '</span>';
          html += '<span class="hub-livedata-summary">' + _esc(g.title) + '</span>';
          html += '<span class="sev-chip sev-low">' + _esc(g.status) + '</span>';
          html += '</div><span class="hub-livedata-ts">' + amt + '</span></div>';
        }); html += '</div>';
      }
      // STC compliance
      if (typeof hd.stc_pending_count !== 'undefined') {
        html += '<div class="hub-livedata-row"><span class="hub-livedata-label">STC transfers pending (2-day rule)</span>';
        html += '<span class="hub-livedata-val' + (hd.stc_pending_count > 0 ? ' lvd-issues' : '') + '">' + hd.stc_pending_count + '</span>';
        if (hd.stc_pending_count === 0) html += '<span class="hub-livedata-ok">✓ Clear</span>';
        html += '</div>';
      }
html += '</div>';
    }

    // ── 6. Technology & Blockchain ───────────────────────────────────────
    else if (areaKey === 'technology_blockchain') {
      html += '<div class="hub-livedata-section">';
      html += '<div class="hub-livedata-title">Infrastructure Status</div>';

      if (hd.nodes) {
        var ns = hd.nodes.by_status || {};
        var liveCount = ns['live'] || 0;
        html += '<div class="hub-livedata-row">';
        html += '<span class="hub-livedata-label">Ledger nodes</span>';
        html += '<span class="hub-livedata-val">' + hd.nodes.total + '</span>';
        if (hd.nodes.total > 0) {
          var nchips = '';
          [['live','node-live'],['commissioning','node-other'],['planned','node-other'],
           ['suspended','node-other'],['retired','node-other']].forEach(function(pair) {
            if (ns[pair[0]]) nchips += '<span class="sev-chip ' + pair[1] + '">' + ns[pair[0]] + ' ' + pair[0] + '</span>';
          });
          if (nchips) html += '<span class="hub-livedata-chips">' + nchips + '</span>';
        }
        html += '</div>';
      }

      if (hd.mint_queue) {
        var mq = hd.mint_queue;
        html += '<div class="hub-livedata-row">';
        html += '<span class="hub-livedata-label">Mint queue items</span>';
        html += '<span class="hub-livedata-val">' + mq.total + '</span>';
        if (mq.total > 0) {
          var mqchips = '';
          Object.keys(mq.by_status || {}).forEach(function(s) {
            mqchips += '<span class="sev-chip sev-low">' + mq.by_status[s] + ' ' + _esc(s) + '</span>';
          });
          if (mqchips) html += '<span class="hub-livedata-chips">' + mqchips + '</span>';
        }
        html += '</div>';
      }

      if (hd.recent_batches && hd.recent_batches.length) {
        html += '<div class="hub-livedata-subtitle">Recent mint batches</div>';
        html += '<div class="hub-livedata-list">';
        hd.recent_batches.forEach(function(b) {
          html += '<div class="hub-livedata-item">' +
            '<div class="hub-livedata-item-inner">' +
              '<span class="hub-livedata-type">' + _esc(b.batch_status) + '</span>' +
              '<span class="hub-livedata-summary">' + _esc(b.batch_label) + '</span>' +
            '</div>' +
            '<span class="hub-livedata-ts">' + _dts(b.created_at) + '</span>' +
          '</div>';
        });
        html += '</div>';
      }

            // Active node incidents
      if (typeof hd.node_incidents !== 'undefined') {
        if (hd.node_incidents.length) {
          html += '<div class="hub-livedata-subtitle" style="margin-top:10px">Active node incidents</div>';
          html += '<div class="hub-livedata-list">';
          hd.node_incidents.forEach(function(inc) {
            var sc = inc.severity==='critical'||inc.severity==='high' ? 'sev-high' : inc.severity==='medium' ? 'sev-med' : 'sev-low';
            html += '<div class="hub-livedata-item"><div class="hub-livedata-item-inner">';
            html += '<span class="sev-chip ' + sc + '">' + _esc(inc.severity) + '</span>';
            html += '<span class="hub-livedata-summary">' + _esc(inc.summary) + '</span>';
            html += '</div><span class="hub-livedata-ts">' + _dts(inc.created_at) + '</span></div>';
          }); html += '</div>';
        } else {
          html += '<div class="hub-livedata-row"><span class="hub-livedata-label">Node incidents</span><span class="hub-livedata-ok">✓ None active</span></div>';
        }
      }
      // Infrastructure reports
      if (hd.infra_reports && hd.infra_reports.length) {
        html += '<div class="hub-livedata-subtitle" style="margin-top:8px">Published infrastructure reports</div>';
        html += '<div class="hub-livedata-list">';
        hd.infra_reports.forEach(function(rpt) {
          html += '<div class="hub-livedata-item"><div class="hub-livedata-item-inner">';
          html += '<span class="hub-livedata-type">' + _esc(rpt.report_type.replace(/_/g,' ')) + '</span>';
          html += '<span class="hub-livedata-summary">' + _esc(rpt.summary || rpt.report_key) + '</span>';
          html += '</div><span class="hub-livedata-ts">' + _dts(rpt.created_at) + '</span></div>';
        }); html += '</div>';
      }
html += '</div>';
    }

    // ── 7. Financial Oversight ───────────────────────────────────────────
    else if (areaKey === 'financial_oversight') {
      html += '<div class="hub-livedata-section">';
      html += '<div class="hub-livedata-title">Financial Position</div>';

      if (hd.last_distribution) {
        var ld = hd.last_distribution;
        var poolAUD = ld.total_pool_cents
          ? '$' + (ld.total_pool_cents / 100).toLocaleString('en-AU', {minimumFractionDigits:2,maximumFractionDigits:2})
          : '—';
        html += '<div class="hub-livedata-row">';
        html += '<span class="hub-livedata-label">Last distribution run</span>';
        html += '<span class="hub-livedata-val">' + _esc(ld.distribution_date) + '</span>';
        html += '<span class="hub-livedata-chips"><span class="sev-chip sev-low">' + _esc(ld.status) + '</span></span>';
        html += '</div>';
        html += '<div class="hub-livedata-row">';
        html += '<span class="hub-livedata-label">Distribution pool</span>';
        html += '<span class="hub-livedata-val lvd-active">' + poolAUD + '</span>';
        html += '</div>';
      }

      if (typeof hd.overdue_transfers !== 'undefined') {
        html += '<div class="hub-livedata-row">';
        html += '<span class="hub-livedata-label">Overdue transfers</span>';
        html += '<span class="hub-livedata-val' + (hd.overdue_transfers > 0 ? ' lvd-issues' : '') + '">' + hd.overdue_transfers + '</span>';
        if (hd.overdue_transfers === 0) html += '<span class="hub-livedata-ok">✓ None overdue</span>';
        html += '</div>';
      }

      if (typeof hd.trust_income_12m !== 'undefined') {
        var incAUD = '$' + (hd.trust_income_12m / 100).toLocaleString('en-AU', {minimumFractionDigits:2,maximumFractionDigits:2});
        html += '<div class="hub-livedata-row">';
        html += '<span class="hub-livedata-label">Trust income (12 months)</span>';
        html += '<span class="hub-livedata-val">' + incAUD + '</span>';
        html += '</div>';
      }

      if (hd.expenses_by_category && Object.keys(hd.expenses_by_category).length) {
        html += '<div class="hub-livedata-subtitle">Paid expenses by category (12 months)</div>';
        html += '<div class="hub-livedata-list">';
        Object.keys(hd.expenses_by_category).forEach(function(cat) {
          var amt = '$' + (hd.expenses_by_category[cat] / 100).toLocaleString('en-AU', {minimumFractionDigits:2,maximumFractionDigits:2});
          html += '<div class="hub-livedata-item">' +
            '<div class="hub-livedata-item-inner">' +
              '<span class="hub-livedata-summary">' + _esc(cat.replace(/_/g,' ')) + '</span>' +
            '</div>' +
            '<span class="hub-livedata-ts">' + amt + '</span>' +
          '</div>';
        });
        html += '</div>';
      }

      // Invariant strip
      if (hd.invariants && hd.invariants.length) {
        var tv = hd.invariant_violations_total || 0;
        html += '<div class="hub-livedata-subtitle" style="margin-top:14px">'
          + 'Godley invariants (I1–I12) '
          + (tv === 0 ? '<span class="hub-livedata-ok">✓ All clear</span>'
            : '<span style="color:#e87070;font-weight:700">⚠ ' + tv + ' violation' + (tv !== 1 ? 's' : '') + '</span>') + '</div>';
        html += '<div class="hub-invariant-strip">';
        hd.invariants.forEach(function(inv) {
          var pass = inv.violation_count === 0;
          html += '<span class="hub-inv-chip ' + (pass ? 'inv-pass' : 'inv-fail') + '" title="' + _esc(inv.name) + '">'
            + _esc(inv.code) + (pass ? '' : ' ⚠') + '</span>';
        });
        html += '</div>';
      }
      // Sub-trust balances
      if (hd.sub_trust_balances && hd.sub_trust_balances.length) {
        html += '<div class="hub-livedata-subtitle" style="margin-top:10px">Sub-trust balances</div>';
        hd.sub_trust_balances.forEach(function(st) {
          var bal = (Math.abs(st.balance_cents)/100).toLocaleString('en-AU',{minimumFractionDigits:2,maximumFractionDigits:2});
          var sign = st.balance_cents < 0 ? ' Cr' : (st.balance_cents > 0 ? ' Dr' : '');
          var zero = st.balance_cents === 0;
          html += '<div class="hub-livedata-row"><span class="hub-livedata-label">';
          html += '<span class="hub-st-badge">ST ' + _esc(st.sub_trust) + '</span> ' + _esc(st.display_name) + '</span>';
          html += '<span class="hub-livedata-val">' + (zero ? '✓ Zero' : '$' + bal + sign) + '</span></div>';
        });
      }
      // Upcoming deadlines
      if (hd.upcoming_deadlines) {
        var ud = hd.upcoming_deadlines;
        html += '<div class="hub-livedata-row"><span class="hub-livedata-label">Upcoming compliance deadlines (14 days)</span>';
        html += '<span class="hub-livedata-val' + (ud.count > 0 ? ' lvd-issues' : '') + '">' + ud.count + '</span>';
        if (ud.count > 0 && ud.earliest)
          html += '<span class="hub-livedata-chips"><span class="sev-chip sev-med">next ' + _esc(ud.earliest.split(' ')[0]) + '</span></span>';
        else html += '<span class="hub-livedata-ok">✓ None due</span>';
        html += '</div>';
      }

            // ── TDR: Distribution Decisions ─────────────────────────────────
      if (hd.tdr_distribution && hd.tdr_distribution.length) {
        html += '<div class="hub-livedata-subtitle" style="margin-top:14px">Trustee Decision Records (distributions)</div>';
        html += '<div class="hub-livedata-list">';
        hd.tdr_distribution.forEach(function(tdr) {
          var ctx = tdr.context !== 'all' ? ' · ST ' + _esc(tdr.context.replace('sub_trust_','')) : ' · All sub-trusts';
          html += '<div class="hub-livedata-item hub-tdr-item">';
          html += '<div class="hub-livedata-item-inner">';
          html += '<span class="hub-tdr-ref">' + _esc(tdr.ref) + '</span>';
          html += '<span class="hub-livedata-summary">' + _esc(tdr.title) + '</span>';
          if (tdr.visibility === 'public') html += '<span class="sev-chip inv-pass">Public</span>';
          html += '</div>';
          html += '<div class="hub-tdr-meta">Distribution' + ctx + '<span class="hub-livedata-ts">' + _esc(tdr.effective) + '</span></div>';
          html += '</div>';
        }); html += '</div>';
      } else if (typeof hd.tdr_distribution !== 'undefined') {
        html += '<div class="hub-livedata-subtitle" style="margin-top:14px">Trustee Decision Records (distributions)</div>';
        html += '<div class="hub-empty" style="font-size:.82rem">No executed distribution TDRs yet.</div>';
      }

html += '</div>';
    }

    // ── 8. Place-Based Decisions ─────────────────────────────────────────
    else if (areaKey === 'place_based_decisions') {
      html += '<div class="hub-livedata-section">';
      html += '<div class="hub-livedata-title">Place & Asset Data</div>';

      if (typeof hd.active_zone_count !== 'undefined') {
        html += '<div class="hub-livedata-row">';
        html += '<span class="hub-livedata-label">Active Affected Zones</span>';
        html += '<span class="hub-livedata-val">' + hd.active_zone_count + '</span>';
        if (hd.zones_by_status) {
          var zchips = '';
          ['proposed','active','expired','revoked'].forEach(function(s) {
            if (hd.zones_by_status[s]) zchips += '<span class="sev-chip sev-low">' + hd.zones_by_status[s] + ' ' + s + '</span>';
          });
          if (zchips) html += '<span class="hub-livedata-chips">' + zchips + '</span>';
        }
        html += '</div>';
      }

      if (hd.active_zones && hd.active_zones.length) {
        html += '<div class="hub-livedata-list">';
        hd.active_zones.forEach(function(z) {
          var typeLabel = z.zone_type.replace(/_/g,' ');
          html += '<div class="hub-livedata-item">' +
            '<div class="hub-livedata-item-inner">' +
              '<span class="hub-livedata-type">' + _esc(typeLabel) + '</span>' +
              '<span class="hub-livedata-summary">' + _esc(z.zone_name) + '</span>' +
            '</div>' +
            '<span class="hub-livedata-ts">' + _esc(z.effective_date) + '</span>' +
          '</div>';
        });
        html += '</div>';
      }

      if (typeof hd.rwa_count !== 'undefined') {
        html += '<div class="hub-livedata-row">';
        html += '<span class="hub-livedata-label">Active real-world assets</span>';
        html += '<span class="hub-livedata-val">' + hd.rwa_count + '</span>';
        html += '</div>';
      }

      if (hd.active_rwa_assets && hd.active_rwa_assets.length) {
        html += '<div class="hub-livedata-list">';
        hd.active_rwa_assets.forEach(function(a) {
          html += '<div class="hub-livedata-item">' +
            '<div class="hub-livedata-item-inner">' +
              '<span class="hub-livedata-type">' + _esc(a.asset_type.replace(/_/g,' ')) + '</span>' +
              '<span class="hub-livedata-summary">' + _esc(a.asset_name) + '</span>' +
            '</div>' +
            '<span class="hub-livedata-ts">' + _esc(a.location_summary) + '</span>' +
          '</div>';
        });
        html += '</div>';
      }

            // Zone challenges
      if (typeof hd.zone_challenges !== 'undefined') {
        if (hd.zone_challenges.length) {
          html += '<div class="hub-livedata-subtitle" style="margin-top:10px">Active zone challenges</div>';
          html += '<div class="hub-livedata-list">';
          hd.zone_challenges.forEach(function(zc) {
            html += '<div class="hub-livedata-item"><div class="hub-livedata-item-inner">';
            html += '<span class="hub-livedata-type">' + _esc(zc.status) + '</span>';
            html += '<span class="hub-livedata-summary">' + _esc(zc.zone_name) + ' — ' + _esc(zc.summary) + '</span>';
            html += '</div><span class="hub-livedata-ts">' + _dts(zc.created_at) + '</span></div>';
          }); html += '</div>';
        } else {
          html += '<div class="hub-livedata-row"><span class="hub-livedata-label">Zone challenges</span><span class="hub-livedata-ok">✓ None active</span></div>';
        }
      }
html += '</div>';
    }

    // ── 9. Education & Outreach ───────────────────────────────────────────
    else if (areaKey === 'education_outreach') {
      html += '<div class="hub-livedata-section">';
      html += '<div class="hub-livedata-title">Membership & Outreach Data</div>';

      if (typeof hd.members_total !== 'undefined') {
        var ms = hd.members_by_status || {};
        html += '<div class="hub-livedata-row">';
        html += '<span class="hub-livedata-label">Total Partners</span>';
        html += '<span class="hub-livedata-val">' + hd.members_total + '</span>';
        var mchips = '';
        [['active','sev-med'],['invited','sev-low'],['locked','sev-high']].forEach(function(pair) {
          if (ms[pair[0]]) mchips += '<span class="sev-chip ' + pair[1] + '">' + ms[pair[0]] + ' ' + pair[0] + '</span>';
        });
        if (mchips) html += '<span class="hub-livedata-chips">' + mchips + '</span>';
        html += '</div>';
      }

      if (typeof hd.new_members_30d !== 'undefined') {
        html += '<div class="hub-livedata-row">';
        html += '<span class="hub-livedata-label">New Partners (30 days)</span>';
        html += '<span class="hub-livedata-val' + (hd.new_members_30d > 0 ? ' lvd-active' : '') + '">' + hd.new_members_30d + '</span>';
        html += '</div>';
      }

      if (typeof hd.invite_codes !== 'undefined') {
        html += '<div class="hub-livedata-row">';
        html += '<span class="hub-livedata-label">Active invite codes</span>';
        html += '<span class="hub-livedata-val">' + hd.invite_codes + '</span>';
        html += '<span class="hub-livedata-chips"><span class="sev-chip sev-low">' + hd.invite_uses + ' uses</span></span>';
        html += '</div>';
      }

      if (hd.open_announcements && hd.open_announcements.length) {
        html += '<div class="hub-livedata-subtitle">Open announcements</div>';
        html += '<div class="hub-livedata-list">';
        hd.open_announcements.forEach(function(a) {
          html += '<div class="hub-livedata-item">' +
            '<div class="hub-livedata-item-inner">' +
              '<span class="hub-livedata-type">Open</span>' +
              '<span class="hub-livedata-summary">' + _esc(a.title) + '</span>' +
            '</div>' +
            '<span class="hub-livedata-ts">' + (a.closes_at ? 'closes ' + _dts(a.closes_at) : '') + '</span>' +
          '</div>';
        });
        html += '</div>';
      }

            // Broadcast wallet messages
      if (hd.broadcast_messages && hd.broadcast_messages.length) {
        html += '<div class="hub-livedata-subtitle" style="margin-top:10px">Recent broadcast wallet messages</div>';
        html += '<div class="hub-livedata-list">';
        hd.broadcast_messages.forEach(function(m) {
          html += '<div class="hub-livedata-item"><div class="hub-livedata-item-inner">';
          html += '<span class="hub-livedata-type">' + _esc(m.message_type) + '</span>';
          html += '<span class="hub-livedata-summary">' + _esc(m.subject) + '</span>';
          html += '</div><span class="hub-livedata-ts">' + _dts(m.created_at) + '</span></div>';
        }); html += '</div>';
      }
      // Email event summary
      if (hd.email_event_summary && Object.keys(hd.email_event_summary).length) {
        html += '<div class="hub-livedata-subtitle" style="margin-top:8px">Email activity (admin/broadcast)</div>';
        Object.keys(hd.email_event_summary).forEach(function(type) {
          html += '<div class="hub-livedata-row">';
          html += '<span class="hub-livedata-label">' + _esc(type.replace(/_/g,' ')) + '</span>';
          html += '<span class="hub-livedata-val">' + hd.email_event_summary[type] + '</span></div>';
        });
      }
html += '</div>';
    }

    if (html) container.insertAdjacentHTML('beforeend', html);
  }

}());
