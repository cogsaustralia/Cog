
(() => {
  function root(){ return window.COGS_ROOT || '../'; }
  function apiUrl(route){ return root() + '_app/api/index.php/' + route.replace(/^\/+/, ''); }
  async function request(route, options){
    const cfg = Object.assign({method:'GET', credentials:'include', headers:{}}, options || {});
    if (cfg.body && !cfg.headers['Content-Type']) cfg.headers['Content-Type'] = 'application/json';
    const resp = await fetch(apiUrl(route), cfg);
    const raw = await resp.text();
    let data = {};
    try { data = raw ? JSON.parse(raw) : {}; } catch(e) { data = { error: raw || 'Request failed' }; }
    if (!resp.ok || data.success === false) throw new Error(data.error || ('HTTP ' + resp.status));
    return data.data;
  }
  function showStatus(el,msg,kind){ if(!el) return; el.textContent = msg; el.className = 'form-status show ' + (kind||'success'); }
  function formatDt(v){ return v || ''; }
  function escapeHtml(v){ return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
  function renderTransfers(items, subjectRef){
    if (!Array.isArray(items) || !items.length) return '<div class="list-item"><div class="muted">No pending transfers yet.</div></div>';
    return items.map(item => {
      const outgoing = String(item.sender_subject_ref||'') === String(subjectRef||'');
      return `<div class="list-item transfer-direction ${outgoing ? 'out':'in'}"><div class="wallet-meta"><span class="pill ${outgoing ? 'warn':'ok'}">${outgoing ? 'Debit':'Credit'}</span><span class="pill">${escapeHtml(item.token_key||'')}</span><span class="muted">${escapeHtml(formatDt(item.created_at))}</span></div><strong>${outgoing ? 'To ' + escapeHtml(item.recipient_subject_ref||'') : 'From ' + escapeHtml(item.sender_subject_ref||'')}</strong><div>${Number(item.units||0).toLocaleString()} pending units</div>${item.note ? `<div class="muted">${escapeHtml(item.note)}</div>` : ''}</div>`;
    }).join('');
  }
  async function hydrateTransfers(){
    const page = document.body.getAttribute('data-vault-page');
    if (!page) return;
    const list = document.querySelector('[data-wallet-transfers]');
    if (!list) return;
    try {
      const route = page === 'business' ? 'vault/business' : 'vault/member';
      const data = await request(route);
      list.innerHTML = renderTransfers(data.pending_transfers || [], page === 'business' ? data.abn : data.member_number);
    } catch(e) {}
  }
  function attachPendingTransferForm(){
    const form = document.querySelector('[data-pending-transfer-form]');
    if (!form) return;
    const page = form.getAttribute('data-pending-transfer-form');
    const status = form.querySelector('[data-transfer-status]');
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = form.querySelector('button[type=submit]');
      if (btn){ btn.disabled = true; btn.dataset.original = btn.textContent; btn.textContent = 'Recording…'; }
      try {
        const fd = new FormData(form); const payload = {}; fd.forEach((v,k)=>payload[k]=v);
        const route = page === 'business' ? 'vault/business-transfer' : 'vault/member-transfer';
        await request(route, {method:'POST', body: JSON.stringify(payload)});
        showStatus(status, 'Pending transfer recorded with debit and credit history.', 'success');
        form.reset();
        if (window.location.search.indexOf('mode=setup') === -1) setTimeout(()=>window.location.reload(), 400);
      } catch(err){ showStatus(status, err.message || 'Unable to record transfer.', 'error'); }
      finally { if (btn){ btn.disabled = false; btn.textContent = btn.dataset.original || 'Record pending transfer'; } }
    });
  }
  document.addEventListener('DOMContentLoaded', () => {
    attachPendingTransferForm();
    setTimeout(hydrateTransfers, 900);
  });
})();
