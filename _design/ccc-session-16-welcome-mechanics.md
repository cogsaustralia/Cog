# UX Audit Remediation — Session 16: welcome/index.html — Mechanics
# Source: _design/audits/ux-audit-welcome-2026-05-03.html findings 45.x, 46.x, 47.x, 48.x
# Run AFTER session 15 is merged to main.
# Pull main before starting: git pull --rebase origin main

## GROUND TRUTH RULES
- Read exact current file state before every edit
- Stage only welcome/index.html
- Verify div balance, script balance, </body>, </html> after all changes
- Zero AI tells — Grade 6 Australian plain English only

---

## CHANGE 48.1 — Replace scrollIntoView with manual scrollTo (finding 48.1)
Find: ctaSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
Replace with:
  window.scrollTo({ top: ctaSection.offsetTop - 24, behavior: 'smooth' });

## CHANGE 46.1 — Pause quote rotator on hover/focus (finding 46.1)
Find the setInterval call for the quote rotator.
Wrap it in a variable:
  var rotatorInterval = setInterval(function(){ idx=(idx+1)%quotes.length; render(idx); }, 8000);

Find the quote rotator container element (the div wrapping the rotator).
Add pause/resume on interaction:
  rotatorContainer.addEventListener('mouseenter', function(){ clearInterval(rotatorInterval); });
  rotatorContainer.addEventListener('mouseleave', function(){
    rotatorInterval = setInterval(function(){ idx=(idx+1)%quotes.length; render(idx); }, 8000);
  });
  rotatorContainer.addEventListener('focusin', function(){ clearInterval(rotatorInterval); });
  rotatorContainer.addEventListener('focusout', function(){
    rotatorInterval = setInterval(function(){ idx=(idx+1)%quotes.length; render(idx); }, 8000);
  });

If a media element (audio/video) is playing, pause the rotator:
  Add a check inside the rotator tick: if any video/audio in the container is playing, skip the advance.
  var mediaEls = rotatorContainer.querySelectorAll('video, audio');
  // In the interval: if any media is playing, skip
  var anyPlaying = Array.from(mediaEls).some(function(m){ return !m.paused; });
  if(anyPlaying) return;

## CHANGE 46.2 — Make quote dots interactive (finding 46.2)
Find the .quote-dot span elements.
Convert each to a <button> element with:
  onclick="goToQuote(i)"
  aria-label="Quote [N]"
  aria-current="true" on the active one

Add goToQuote function:
  function goToQuote(i){
    idx = i;
    render(idx);
    clearInterval(rotatorInterval);
    rotatorInterval = setInterval(function(){ idx=(idx+1)%quotes.length; render(idx); }, 8000);
  }

## CHANGE 46.4 — Empty state copy on joiner ticker (finding 46.4)
Find the empty/fallback state for the joiner ticker.
Already updated in session 14 ("No new members in the last hour...").
Verify this was applied and the previous "Members are joining — be next." is gone.

## CHANGE 46.5 — Pause rotator during media playback (finding 46.5)
Already handled in CHANGE 46.1 above — confirm the media-playing check is in place.

## CHANGE 48.2 — Add disclosure for session token bridge (finding 48.2)
Find the consent block added in session 15.
Add an additional disclosure line below the existing consent text:
  <p style="font-size:.75rem;color:var(--muted);margin-top:6px;line-height:1.5">
    If you join in the next 30 minutes, we'll link your message to your new account
    automatically — so you don't have to re-enter it.
  </p>

## CHANGE 48.4 — Extract API base constant (finding 48.4)
Find the two separate API base path computations:
  Line ~288: var apiBase = window.location.pathname.replace(...)
  Line ~338: var apiBase = window.location.pathname.replace(...)

Replace both with a single constant defined once at the top of the IIFE, after 'use strict':
  var API_BASE = window.location.pathname.replace(/\/welcome\/?.*$/, '') + '/_app/api/';

Replace both individual apiBase variable declarations with references to API_BASE.

## CHANGE 48.5 — Friendly error messages (finding 48.5)
Find the catch handler in the submit function:
  statusEl.textContent = err.message || 'Something went wrong. Please try again.';

Replace with a whitelist approach:
  var friendlyErrors = {
    'banned_phrase': 'Your message contains a phrase we need to review. Try rewording it.',
    'too_short': 'A sentence or two works best — a bit more would help.',
    'rate_limit': 'You've sent a few messages recently — please try again in a few minutes.',
  };
  var friendlyMsg = friendlyErrors[err.code] ||
    'Couldn't send right now. Want to try again, or email it to <a href="mailto:info@cogsaustralia.org">info@cogsaustralia.org</a>?';
  statusEl.innerHTML = friendlyMsg;
  submitBtn.disabled = false;
  submitBtn.textContent = 'Send my message →';

## CHANGE 48.6 — Social API failure message (finding 48.6)
Find the social-proof fetch error handler (the console.error call on social API failure).
After the console.error, add UI feedback:
  var quotesContainer = document.querySelector('.quote-rotator') || document.getElementById('quotes-section');
  if(quotesContainer){
    var loadingEl = quotesContainer.querySelector('.loading-placeholder');
    if(loadingEl) loadingEl.textContent = "Couldn't load community voices right now — that's OK, you can read them later.";
  }

## CHANGE 41.6 — Remove sticky header on cold landing (finding 41.6)
Find the header CSS: position: sticky; top: 0
Change to: position: relative; (or remove position:sticky and top:0)
The cold landing page has no scrollable content that needs a persistent nav.

## VERIFICATION
1. div balance
2. script balance
3. </body> once, </html> once
4. scrollIntoView gone — manual scrollTo in place
5. setInterval wrapped in variable
6. mouseenter/mouseleave pause handlers present
7. Quote dots are <button> elements
8. API_BASE constant defined once at top of IIFE
9. Two separate apiBase declarations removed
10. Session token disclosure line present

## COMMIT
git add welcome/index.html
git commit -m "fix(welcome): rotator pause, interactive dots, scrollTo fix, API_BASE constant, friendly errors"
git checkout -b review/session-16 && git push origin review/session-16
