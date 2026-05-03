# UX Audit Remediation — Session 15: welcome/index.html — Structural Fixes
# Source: _design/audits/ux-audit-welcome-2026-05-03.html findings 40.x, 41.4, 42.x, 43.x, 44.x
# Run AFTER session 14 is merged to main.
# Pull main before starting: git pull --rebase origin main

## GROUND TRUTH RULES
- Read exact current file state before every edit
- Stage only welcome/index.html
- Verify div balance, script balance, </body>, </html> after all changes
- Zero AI tells — Grade 6 Australian plain English only

---

## CHANGE 40.1 — Add COG$ explainer above the voice form (finding 40.1)
Find the voice section, directly ABOVE the eyebrow div (class="voice-eyebrow" or similar).
Insert a new explainer block:

  <div class="cogs-explainer" style="max-width:520px;margin:0 auto 32px;text-align:center">
    <p style="font-size:1rem;color:var(--text2);line-height:1.7;margin-bottom:10px">
      COG$ is an Australian community that owns real shares in mining companies together
      and votes on what happens to the land. Joining costs $4, once.
      We don't take it until you say yes.
    </p>
    <a href="/intro/" style="font-size:.88rem;color:var(--gold-2);text-decoration:none;
      font-weight:600">Want the longer version? Read the 5-card intro &#x203A;</a>
  </div>

## CHANGE 40.2 — Demote voice form, add primary CTA (finding 40.2)
The voice form is currently the primary CTA. Add a more prominent orientation CTA above it.
Find the voice section container.
ABOVE the voice form textarea block, add:

  <div style="margin-bottom:28px">
    <a href="/intro/" class="btn-gold" style="display:inline-block;padding:14px 28px;
      font-weight:700;font-size:.95rem;border-radius:99px;text-decoration:none;
      background:linear-gradient(135deg,var(--gold-1),var(--gold-2));color:#1a0f00">
      See how it works &#x203A;
    </a>
    <p style="font-size:.78rem;color:var(--muted);margin-top:8px">
      5-card intro — takes 2 minutes
    </p>
  </div>
  <p style="font-size:.82rem;color:var(--muted);margin-bottom:16px">
    Or share your thoughts first:
  </p>

## CHANGE 42.1 — Remove teaser quote above textarea (finding 42.1)
Find the renderTeaser() function and the teaser display element (class="voice-teaser" or similar).
Change renderTeaser() to NOT render the teaser ABOVE the textarea.
Instead, render it BELOW the submit button with a heading: "What other members have said:"
The teaser element should be moved in the DOM to after the submit button, not above the textarea.

## CHANGE 42.2 — Add consent line to voice form (finding 42.2)
Find the voice form, directly BELOW the submit button.
Add:
  <div class="voice-consent" style="margin-top:14px;text-align:left;max-width:480px;margin-left:auto;margin-right:auto">
    <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-size:.84rem;color:var(--text2);line-height:1.5">
      <input type="checkbox" id="consent-social" name="consent_social"
             style="margin-top:3px;flex-shrink:0;accent-color:var(--gold-2)">
      <span>I'm OK with COG$ sharing this on social media.
        If unticked, your message is stored but not posted.</span>
    </label>
    <p style="font-size:.78rem;color:var(--muted);margin-top:8px;line-height:1.5">
      We'll show your first name only. Email
      <a href="mailto:info@cogsaustralia.org" style="color:var(--gold-2)">info@cogsaustralia.org</a>
      any time to remove your message.
      <a href="/privacy/#voices" style="color:var(--gold-2)">How we use voice messages &#x203A;</a>
    </p>
  </div>

Update the form submit handler to include consent_social value:
  var consentEl = document.getElementById('consent-social');
  var consentSocial = consentEl ? consentEl.checked : false;
  body: JSON.stringify({ text_content: text, session_token: token, ref_source: refSource, consent_social: consentSocial })

## CHANGE 42.4 — Character limit warning copy (finding 42.4)
Find the textarea input handler where charCount className is set.
Add feedback copy below the counter:
  At len >= 270: show a hint element "You're nearly out of room — short is good!"
  At len >= 280 (maxlength hit): change hint to "You've used the full 280 characters."

Add a hint element below the char counter:
  <div id="char-hint" style="font-size:.75rem;color:var(--muted);margin-top:4px;min-height:16px"></div>

Wire it in the input handler:
  var hint = document.getElementById('char-hint');
  if(hint){
    if(len >= 280) hint.textContent = "You've used the full 280 characters.";
    else if(len >= 270) hint.textContent = "You're nearly out of room — short is good!";
    else hint.textContent = '';
  }

## CHANGE 42.5 — Raise submit minimum to 30 characters (finding 42.5)
Find: submitBtn.disabled = len < 3;
Change to: submitBtn.disabled = len < 30;

Find: if (text.length < 3) return;
Change to: if (text.length < 30) return;

Add guidance below the textarea (above the consent block):
  <p id="length-hint" style="font-size:.78rem;color:var(--muted);margin-top:6px;display:none">
    A sentence or two works well. We'd love a real thought.
  </p>

Show length-hint when len > 0 and len < 30:
  var lhint = document.getElementById('length-hint');
  if(lhint) lhint.style.display = (len > 0 && len < 30) ? '' : 'none';

## CHANGE 43.5 — Preserve voice section read-only after submit (finding 43.5)
Find: document.getElementById('voice-section').style.display = 'none';
Replace with:
  var vs = document.getElementById('voice-section');
  if(vs){
    vs.querySelector('textarea') && (vs.querySelector('textarea').readOnly = true);
    vs.querySelector('textarea') && (vs.querySelector('textarea').style.opacity = '0.6');
    var submitBtnEl = vs.querySelector('#voice-submit');
    if(submitBtnEl) submitBtnEl.style.display = 'none';
    var vsLabel = document.createElement('p');
    vsLabel.style.cssText = 'font-size:.75rem;color:var(--muted);margin-top:8px;text-align:center';
    vsLabel.textContent = 'You wrote this.';
    vs.appendChild(vsLabel);
  }
Do NOT hide the voice section — the user should be able to scroll back and re-read what they wrote.

## CHANGE 41.4 — Brand mark loop fix (finding 41.4)
Find the brand mark link (the <a> wrapping the logo/brand).
If it links to / or ../index.html or similar root:
  Change href to /intro/
This prevents the redirect loop back to /welcome/.

## CHANGE 47.1 — Entity identification in first viewport (finding 47.1)
Find the header/brand area.
Add a small trust strip directly below the brand mark, inside the header:
  <div style="font-size:.7rem;color:var(--muted);text-align:center;margin-top:4px">
    COG$ of Australia Foundation &middot; ABN 91 341 497 529 &middot; Drake Village NSW
  </div>

## VERIFICATION
1. div balance
2. script balance
3. </body> once, </html> once
4. Explainer block present above voice eyebrow
5. Consent checkbox present below submit button
6. Submit minimum is 30 chars
7. Voice section NOT hidden after submission (textarea read-only instead)
8. renderTeaser renders BELOW submit button, not above textarea
9. Brand mark links to /intro/
10. ABN trust strip in header

## COMMIT
git add welcome/index.html
git commit -m "fix(welcome): explainer first, consent checkbox, submit min 30 chars, preserve voice section, teaser moved below"
git checkout -b review/session-15 && git push origin review/session-15
