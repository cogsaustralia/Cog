# UX Audit Remediation — Session 1: join/index.html
# Source: _design/audits/ux-audit-cold-funnel-2026-05-03.html
# Read the file before every edit. No guessing. Show diff. STOP before committing.

## GROUND TRUTH RULES
- Read exact current file state before every edit
- Python write only for file creation — never heredoc
- Stage only join/index.html — never git add .
- After all changes: verify div balance, script balance, </body>, </html>
- Zero AI tells in user-facing text — Grade 6 Australian plain English only
- Banned: em-dashes, passive constructions, not-X-not-Y-not-Z patterns

---

## CHANGE 3.1 — Remove hidden caretaker invite inject

Find the cold-path block (comment: "Caretaker default invite for cold visitors").
Current behaviour: sets invite_code_input.value = 'COGS-FUHT2L', hides invite-wrap,
calls validateInviteCode().

New behaviour:
- If no URL invite code (hasCode is false): hide invite-wrap (display:none), set
  _inviteValid = true, call checkSubmit(), return. Do not inject any code. Do not
  call validateInviteCode().
- If a real partner code IS in the URL (hasCode is true): show invite section normally,
  do nothing — existing URL-based validation handles it.

Replace the entire body of the cold-path IIFE with:

  var params = new URLSearchParams(window.location.search);
  var hasCode = !!(params.get('partner_code') || params.get('code') || params.get('c') ||
                   params.get('invite') || params.get('invite_token') || params.get('ref'));
  if (hasCode) return;
  var wrap = document.getElementById('invite-wrap');
  if (wrap) wrap.style.display = 'none';
  _inviteValid = true;
  checkSubmit();

---

## CHANGE 3.5 — Replace 6-step field stepper with flat 5-field form

### Step A: Remove field stepper HTML
Remove the entire #field-stepper div (id="field-stepper") and all its contents.

### Step B: Replace with flat form grid
In its place inside step-1, after the step-hd div, add:

  <div class="form-grid">
    <div class="field">
      <label for="first_name">First name</label>
      <input id="first_name" name="first_name" type="text" autocomplete="given-name"
             required placeholder="Jane" autofocus>
    </div>
    <div class="field">
      <label for="middle_name">Middle name <span class="field-opt">optional</span></label>
      <input id="middle_name" name="middle_name" type="text" autocomplete="additional-name"
             placeholder="Leave blank to skip">
    </div>
    <div class="field full">
      <label for="last_name">Last name</label>
      <input id="last_name" name="last_name" type="text" autocomplete="family-name"
             required placeholder="Smith">
    </div>
    <div class="field full">
      <label for="email">Email address</label>
      <input id="email" name="email" type="email" autocomplete="email"
             required placeholder="you@example.com">
    </div>
    <div class="field full">
      <label for="mobile">Mobile number</label>
      <input id="mobile" name="mobile" type="tel" autocomplete="tel"
             required placeholder="04xx xxx xxx">
    </div>
  </div>

### Step C: Remove field stepper JS
Remove the entire field stepper IIFE block (starts with "/* ── Field stepper ── */",
ends with the closing })(); of that block).
Remove the buildSummary() function entirely.
Remove the fs-summary-text element if present.

### Step D: Remove hide-until-fs-done IIFE
Remove the IIFE that sets membership-pill, cost-row, and step-nav to display:none
(comment: "Hide pill/cost/proceed until fs-done").
The pill, cost row, and Review and confirm button are now always visible in step-1.

---

## CHANGE 3.6 — Define vault on first mention

In the hero trust box, find the first list item that mentions "Independence Vault".
Change "Your Independence Vault" to:
  "Your member dashboard (we call it the Independence Vault)"

In the side panel heading or list, change the first "vault" or "Independence Vault"
reference to "your member dashboard".

---

## CHANGE 3.7 — Single spinner replaces theatrical progress

In the submit handler, find:
  var _pMsgs = [ ... ];
  var _pTimers = _pMsgs.map(function(msg){ return setTimeout(...) });

Remove the _pMsgs array and _pTimers declaration entirely.
Replace the status line that follows with:
  status.className = 'form-status loading';
  status.textContent = 'Lodging your registration...';

Remove all instances of: _pTimers.forEach(clearTimeout);

---

## CHANGE 3.8 — Replace auto-fill stewardship with 5 real questions

### Step A: Remove hard-coded stewardship answers from submit handler
Find in the submit handler:
  data['stewardship_module'] = { module_name: 'stewardship_awareness', ... answers: { q11: ... } };
Remove this entire block.

### Step B: Add stewardship_module build from actual answers
In its place add:
  data['stewardship_module'] = {
    module_name: 'stewardship_awareness',
    completed_at: new Date().toISOString(),
    answers: {
      q1: document.querySelector('input[name="sw_q1"]:checked') ?
          document.querySelector('input[name="sw_q1"]:checked').value : '',
      q2: document.querySelector('input[name="sw_q2"]:checked') ?
          document.querySelector('input[name="sw_q2"]:checked').value : '',
      q3: document.querySelector('input[name="sw_q3"]:checked') ?
          document.querySelector('input[name="sw_q3"]:checked').value : '',
      q4: document.querySelector('input[name="sw_q4"]:checked') ?
          document.querySelector('input[name="sw_q4"]:checked').value : '',
      q5: document.querySelector('input[name="sw_q5"]:checked') ?
          document.querySelector('input[name="sw_q5"]:checked').value : ''
    }
  };

### Step C: Add stewardship questions to checkSubmit
In checkSubmit(), add to the canSubmit check:
  var swDone = ['sw_q1','sw_q2','sw_q3','sw_q4','sw_q5'].every(function(n){
    var c = document.querySelector('input[name="'+n+'"]:checked');
    return c && c.value === 'agree';
  });
  var canSubmit = _inviteValid && swDone;

### Step D: Add stewardship question HTML to step-2
In step-2, ABOVE the confirm-summary div, add the following stewardship section.
Use the site's existing CSS variables (--panel, --gold-rim, --gold-1, --text2, --line, --r, etc).
Style question cards as selection cards — border highlights gold when agree is selected,
muted when more is selected.

HTML for the stewardship section:

<div class="sw-section" id="sw-section">
  <div class="step-hd" style="margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid var(--line2)">
    <div class="step-num-badge" style="background:rgba(240,209,138,.15);color:var(--gold-1)">✦</div>
    <div>
      <h2 style="font-family:var(--serif);font-size:1.25rem;color:var(--text)">5 quick questions</h2>
      <p style="font-size:.88rem;color:var(--text2)">Your answers are recorded with your membership.</p>
    </div>
  </div>
  <div class="sw-questions" id="sw-questions"></div>
</div>

Add CSS for .sw-card, .sw-option, .sw-expand in the page <style> block:

  .sw-card{background:var(--panel);border:1px solid var(--line2);border-radius:12px;
    padding:18px 20px;margin-bottom:12px}
  .sw-q-text{font-size:.92rem;color:var(--text);line-height:1.65;margin-bottom:14px;font-weight:500}
  .sw-options{display:flex;flex-direction:column;gap:8px}
  .sw-option{display:flex;align-items:center;gap:12px;padding:11px 14px;
    border:1.5px solid var(--line2);border-radius:10px;cursor:pointer;transition:border-color .2s,background .2s}
  .sw-option:hover{border-color:var(--gold-rim)}
  .sw-option input[type=radio]{flex-shrink:0;accent-color:var(--gold-2);width:16px;height:16px}
  .sw-option label{font-size:.88rem;color:var(--text2);cursor:pointer;line-height:1.4}
  .sw-option.selected-agree{border-color:var(--ok);background:var(--okb)}
  .sw-option.selected-agree label{color:var(--ok)}
  .sw-option.selected-more{border-color:var(--gold-rim)}
  .sw-expand{font-size:.84rem;color:var(--text2);line-height:1.6;
    background:rgba(240,209,138,.05);border:1px solid var(--line);
    border-radius:8px;padding:12px 14px;margin-top:8px;display:none}
  .sw-expand.open{display:block}

Add JS to build and wire the questions (goes in block 3, after existing block 3 code):

(function(){
  var SW_DATA = [
    { name:'sw_q1',
      q:"COG$ is a community joint venture, not an investment product. My $4 is a one-time membership fee. There is no guaranteed return and no way to get my $4 back.",
      agree:"Yes, I get that",
      more:"Tell me more",
      expand:"Your $4 pays for your Member record and governance vote. It cannot be refunded. COG$ is not a bank, a fund, or a financial adviser."
    },
    { name:'sw_q2',
      q:"I am joining to have a say in how Australia's resources are managed. My vote counts the same as every other Member, regardless of when I joined.",
      agree:"Yes, that's why I'm joining",
      more:"How does voting work?",
      expand:"Every Member gets one vote. No one gets extra votes for joining early or paying more. Votes are cast through your member dashboard."
    },
    { name:'sw_q3',
      q:"When the Foundation earns income from its shareholdings, half goes back to all Members equally. The other half buys more ASX shares to grow what the Foundation owns and increase everyone's governance weight over time.",
      agree:"I get how the money works",
      more:"Tell me more",
      expand:"The split is fixed in the Foundation rules and cannot be changed without a Member vote. You do not need to do anything — it happens automatically."
    },
    { name:'sw_q4',
      q:"Traditional Custodians of Country have binding authority over extraction decisions on their land. This is written into the Foundation rules and cannot be changed without their agreement.",
      agree:"I respect that",
      more:"Why does this matter?",
      expand:"COG$ operates on Country across Australia. First Nations Custodians have a binding authority over resource extraction on their land. This is a legal rule in the Foundation's governing documents, not a courtesy."
    },
    { name:'sw_q5',
      q:"COG$ is new. There is no guaranteed return and no government protection. I am joining because I believe in what COG$ is trying to do.",
      agree:"I understand the risk and I'm in",
      more:"What are the risks?",
      expand:"COG$ may not achieve its goals. The Foundation could fail. You could lose your $4. No government scheme protects this membership. Join only if you support the community purpose."
    }
  ];

  window._swReady = false;

  function buildSwQuestions(){
    var container = document.getElementById('sw-questions');
    if(!container) return;
    SW_DATA.forEach(function(d, i){
      var num = i+1;
      var card = document.createElement('div');
      card.className = 'sw-card';
      card.innerHTML =
        '<div class="sw-q-text">'+num+'. '+escText(d.q)+'</div>'+
        '<div class="sw-options">'+
          '<div class="sw-option" id="sw-opt-agree-'+num+'">'+
            '<input type="radio" id="sw-'+num+'-agree" name="'+d.name+'" value="agree">'+
            '<label for="sw-'+num+'-agree">'+escText(d.agree)+'</label>'+
          '</div>'+
          '<div class="sw-option" id="sw-opt-more-'+num+'">'+
            '<input type="radio" id="sw-'+num+'-more" name="'+d.name+'" value="more">'+
            '<label for="sw-'+num+'-more">'+escText(d.more)+'</label>'+
          '</div>'+
        '</div>'+
        '<div class="sw-expand" id="sw-expand-'+num+'">'+escText(d.expand)+'</div>';
      container.appendChild(card);

      var agreeOpt = card.querySelector('#sw-opt-agree-'+num);
      var moreOpt  = card.querySelector('#sw-opt-more-'+num);
      var expandEl = card.querySelector('#sw-expand-'+num);
      var agreeIn  = card.querySelector('#sw-'+num+'-agree');
      var moreIn   = card.querySelector('#sw-'+num+'-more');

      function updateCard(){
        agreeOpt.classList.toggle('selected-agree', agreeIn.checked);
        moreOpt.classList.toggle('selected-more', moreIn.checked);
        expandEl.classList.toggle('open', moreIn.checked);
        if(typeof checkSubmit === 'function') checkSubmit();
      }
      agreeIn.addEventListener('change', updateCard);
      moreIn.addEventListener('change', updateCard);
      agreeOpt.addEventListener('click', function(){ agreeIn.checked=true; updateCard(); });
      moreOpt.addEventListener('click', function(){ moreIn.checked=true; updateCard(); });
    });
  }

  var origGoStep = window.goStep;
  window.goStep = function(n){
    if(n === 2) buildSwQuestions();
    if(origGoStep) origGoStep(n);
  };
})();

---

## CHANGE 3.9 — Fix scrollIntoView

Find all instances of:
  .scrollIntoView({behavior:'smooth',block:'start'})
  .scrollIntoView({behavior:'smooth',block:'center'})

Replace each with a safe scroll helper. Add this function once in block 3:

  function safeScroll(el, offset) {
    if (!el) return;
    window.scrollTo({
      top: el.getBoundingClientRect().top + window.scrollY - (offset || 80),
      behavior: 'smooth'
    });
  }

Replace each scrollIntoView call:
  el.scrollIntoView(...) → safeScroll(el, 80)
  document.getElementById('form').scrollIntoView(...) → safeScroll(document.getElementById('form'), 80)

---

## CHANGE 3.10 — Promote card fee to cost row

In the cost-row div, find the cost-note div ("Once only. Not refundable.").
Add a line directly below it:
  <div class="cost-note">Pay by bank transfer (no fee) or card (+40c Stripe fee).</div>

---

## VERIFICATION BEFORE DIFF
Run these checks and report results before showing diff:
1. div opens == div closes
2. script opens == script closes
3. </body> present once
4. </html> present once
5. No instance of 'COGS-FUHT2L' in user-facing code paths
6. No instance of 'validateInviteCode' called from cold-path block
7. No instance of 'scrollIntoView' remaining
8. field-stepper div is gone
9. sw-questions div is present
10. _pMsgs is gone

## COMMIT
After Thomas approves the diff:
  git add join/index.html
  git commit -m "fix(join): flat form, stewardship questions, remove caretaker inject, single spinner, safe scroll"
  git pull --rebase origin main && git push origin main
