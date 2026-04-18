/* COG$ Ask AI — Floating Chat Widget
   Include on any page: <script src="/assets/ask-widget.js"></script>
   Requires: /_app/api/ask endpoint configured with ANTHROPIC_API_KEY
*/
(function(){
'use strict';

var API = '/_app/api/ask';
var MAX_HISTORY = 10;

// ── Inject styles ────────────────────────────────────────────────────────────
var css = document.createElement('style');
css.textContent = `
.cogs-ask-fab{position:fixed;bottom:24px;right:24px;z-index:9999;width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,#f0d18a,#c9973d);border:2px solid rgba(240,209,138,.5);box-shadow:0 4px 20px rgba(0,0,0,.35);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:transform .2s,box-shadow .2s;-webkit-tap-highlight-color:transparent}
.cogs-ask-fab:hover{transform:scale(1.08);box-shadow:0 6px 28px rgba(180,130,40,.5)}
.cogs-ask-fab svg{width:26px;height:26px;fill:#1a0f00}
.cogs-ask-fab.open svg.ico-chat{display:none}
.cogs-ask-fab:not(.open) svg.ico-close{display:none}

.cogs-ask-panel{position:fixed;bottom:90px;right:24px;z-index:9998;width:370px;max-width:calc(100vw - 32px);max-height:520px;background:#12171f;border:1px solid rgba(240,209,138,.18);border-radius:20px;box-shadow:0 12px 48px rgba(0,0,0,.6);display:none;flex-direction:column;overflow:hidden;font-family:'DM Sans',system-ui,sans-serif}
.cogs-ask-panel.open{display:flex}

.cogs-ask-hdr{padding:14px 18px;background:linear-gradient(135deg,rgba(24,17,8,.95),rgba(18,12,5,.92));border-bottom:1px solid rgba(240,209,138,.12);display:flex;align-items:center;gap:10px}
.cogs-ask-hdr img{width:32px;height:32px;border-radius:50%;border:1px solid rgba(240,209,138,.3)}
.cogs-ask-hdr-text{flex:1}
.cogs-ask-hdr h4{margin:0;font-size:13px;font-weight:600;color:#f0d18a}
.cogs-ask-hdr p{margin:0;font-size:11px;color:rgba(210,185,130,.55)}

.cogs-ask-msgs{flex:1;overflow-y:auto;padding:14px 16px;display:flex;flex-direction:column;gap:10px;min-height:200px}
.cogs-ask-msg{max-width:88%;padding:10px 14px;border-radius:14px;font-size:13px;line-height:1.6;word-wrap:break-word}
.cogs-ask-msg.user{align-self:flex-end;background:rgba(240,209,138,.12);color:#fff8e8;border-bottom-right-radius:4px}
.cogs-ask-msg.ai{align-self:flex-start;background:rgba(255,255,255,.05);color:rgba(255,248,232,.82);border-bottom-left-radius:4px;border:1px solid rgba(255,255,255,.06)}
.cogs-ask-msg.ai a{color:#f0d18a}
.cogs-ask-typing{align-self:flex-start;padding:10px 14px;font-size:12px;color:rgba(210,185,130,.45);font-style:italic}

.cogs-ask-input{padding:12px 14px;border-top:1px solid rgba(240,209,138,.1);display:flex;gap:8px;background:rgba(8,6,2,.6)}
.cogs-ask-input textarea{flex:1;background:rgba(255,255,255,.05);border:1px solid rgba(240,209,138,.12);border-radius:12px;color:#fff8e8;padding:9px 12px;font:inherit;font-size:13px;resize:none;height:40px;max-height:80px;outline:none;transition:border-color .2s}
.cogs-ask-input textarea:focus{border-color:rgba(240,209,138,.35)}
.cogs-ask-input textarea::placeholder{color:rgba(210,185,130,.35)}
.cogs-ask-input button{width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,#f0d18a,#c9973d);border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:opacity .15s}
.cogs-ask-input button:disabled{opacity:.4;cursor:default}
.cogs-ask-input button svg{width:18px;height:18px;fill:#1a0f00}

@media(max-width:480px){
  .cogs-ask-panel{bottom:0;right:0;width:100vw;max-width:100vw;max-height:100vh;border-radius:20px 20px 0 0}
  .cogs-ask-fab{bottom:16px;right:16px}
}
`;
document.head.appendChild(css);

// ── Build DOM ────────────────────────────────────────────────────────────────
var fab = document.createElement('div');
fab.className = 'cogs-ask-fab';
fab.setAttribute('aria-label', 'Ask a question');
fab.innerHTML = '<svg class="ico-chat" viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H5.2L4 17.2V4h16v12z"/><path d="M7 9h10v2H7zm0-3h10v2H7z"/></svg><svg class="ico-close" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>';

var panel = document.createElement('div');
panel.className = 'cogs-ask-panel';
panel.innerHTML = `
<div class="cogs-ask-hdr">
  <img src="/assets/logo_webcir.png" alt="COG$">
  <div class="cogs-ask-hdr-text">
    <h4>Ask about COG$</h4>
    <p>AI assistant · FAQ knowledge only</p>
  </div>
</div>
<div class="cogs-ask-msgs" id="cogs-ask-msgs">
  <div class="cogs-ask-msg ai">G'day! I can answer questions about COG$ of Australia Foundation — membership, governance, token classes, and how it all works. What would you like to know?</div>
</div>
<div class="cogs-ask-input">
  <textarea id="cogs-ask-q" placeholder="Type your question…" rows="1"></textarea>
  <button id="cogs-ask-send" aria-label="Send"><svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg></button>
</div>`;

document.body.appendChild(panel);
document.body.appendChild(fab);

// ── State ────────────────────────────────────────────────────────────────────
var history = [];
var sending = false;
var msgs = document.getElementById('cogs-ask-msgs');
var input = document.getElementById('cogs-ask-q');
var sendBtn = document.getElementById('cogs-ask-send');

// ── Toggle ───────────────────────────────────────────────────────────────────
fab.addEventListener('click', function(){
  var isOpen = panel.classList.toggle('open');
  fab.classList.toggle('open', isOpen);
  if(isOpen) input.focus();
});

// ── Send ─────────────────────────────────────────────────────────────────────
function renderMarkdown(text){
  // Convert markdown links: [text](url)
  // Internal paths (/...) — same tab
  text = text.replace(/\[([^\]]+)\]\((\/[^)]+)\)/g, '<a href="$2">$1</a>');
  // External URLs (http/https) — new tab
  text = text.replace(/\[([^\]]+)\]\((https?:\/\/[^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
  // Bare internal paths e.g. /skeptic/ or /faq/ (word boundary, not already inside href="")
  text = text.replace(/(?<!=["'])(\/[a-z0-9\-]+\/)/g, '<a href="$1">$1</a>');
  // Bare https:// URLs not already in an href
  text = text.replace(/(?<!=["'])(https?:\/\/[^\s<"']+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>');
  // Bare email addresses
  text = text.replace(/([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})/g, '<a href="mailto:$1">$1</a>');
  // Bold: **text**
  text = text.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
  // Italic: *text* (not already inside **)
  text = text.replace(/(?<!\*)\*([^*]+)\*(?!\*)/g, '<em>$1</em>');
  // Newlines to <br>
  text = text.replace(/\n/g, '<br>');
  return text;
}

function addMsg(text, role){
  var div = document.createElement('div');
  div.className = 'cogs-ask-msg ' + role;
  div.innerHTML = role === 'ai' ? renderMarkdown(text) : text.replace(/\n/g, '<br>');
  msgs.appendChild(div);
  if(role === 'ai'){
    // Scroll so the top of the AI reply is visible
    msgs.scrollTop = div.offsetTop - msgs.offsetTop - 10;
  } else {
    msgs.scrollTop = msgs.scrollHeight;
  }
  return div;
}

function send(){
  var q = input.value.trim();
  if(!q || sending) return;
  sending = true;
  sendBtn.disabled = true;
  input.value = '';
  input.style.height = '40px';

  addMsg(q, 'user');
  history.push({role:'user', content:q});

  var typing = document.createElement('div');
  typing.className = 'cogs-ask-typing';
  typing.textContent = 'Thinking…';
  msgs.appendChild(typing);
  msgs.scrollTop = msgs.scrollHeight;

  fetch(API, {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({question: q, history: history.slice(-MAX_HISTORY)})
  })
  .then(function(r){ return r.json(); })
  .then(function(data){
    typing.remove();
    var answer = data.answer || data.error || 'Sorry, something went wrong. Please try again.';
    addMsg(answer, 'ai');
    history.push({role:'assistant', content:answer});
  })
  .catch(function(){
    typing.remove();
    addMsg('Could not connect. Please check your internet and try again.', 'ai');
  })
  .finally(function(){
    sending = false;
    sendBtn.disabled = false;
    input.focus();
  });
}

sendBtn.addEventListener('click', send);
input.addEventListener('keydown', function(e){
  if(e.key === 'Enter' && !e.shiftKey){ e.preventDefault(); send(); }
});

// Auto-resize textarea
input.addEventListener('input', function(){
  this.style.height = '40px';
  this.style.height = Math.min(this.scrollHeight, 80) + 'px';
});

})();
