# UX Audit Remediation — Session 13: Voice pages — UX fixes
# Source: _design/audits/ux-audit-intro-2026-05-03.html findings 32.x, 33.x, 34.x, 35.x
# Files: intro/voice-welcome.html, intro/voice-text.html, intro/voice-audio.html,
#        intro/voice-video.html, intro/voice-confirmed.html
# Run AFTER session 12 is merged to main.
# Pull main before starting: git pull --rebase origin main

## GROUND TRUTH RULES
- Read each file before every edit
- Stage only the specific files changed
- Verify div/script balance on each file after changes
- Zero AI tells — Grade 6 Australian plain English only

---

## CHANGE 32.01 — voice-welcome.html: defer ask, prominent skip
Find the page heading/sub-heading asking for a testimonial.
Change the framing heading to:
  "Want to share why you joined?"
Change the sub-heading to:
  "Optional. Takes 30 seconds. Your words might help someone else decide."
Add a prominent skip link at the TOP of the page (not buried at bottom):
  <a href="../partners/?welcome=1" class="skip-link"
     style="display:block;text-align:center;font-size:.88rem;color:var(--text3);
     margin-bottom:20px;text-decoration:underline">
     Skip — go straight to my account ›
  </a>

## CHANGE 33.01 — voice-text.html: opt-in sharing, not opt-out
Find the display-as line: "Shown as: your first name, your state"
Change the default to display name only (no state), with opt-in for state:
  Default display: first name only
  Add checkbox: "Include my state (e.g. NSW) — helps show COG$ is national"
  Checkbox unchecked by default
Update the form submission to only include state if the checkbox is checked.

## CHANGE 33.02 — voice-text.html: character count plain English
Find the character counter (e.g. "280 / 280" or "0 / 280").
Add a plain-English prompt below the text area:
  "One sentence is enough. Something like: I joined because I want my community to have a say."
Style: font-size:.78rem; color:var(--text3); margin-top:6px;

## CHANGE 34.01 — voice-video.html: defer camera permission
Find the initCamera() call that fires on page load.
Remove it from the DOMContentLoaded or equivalent auto-trigger.
Replace with a visible "Start camera" button that the user must click first:
  <button class="btn btn-gold" id="start-camera-btn" onclick="initCamera(); this.style.display='none'">
    Allow camera and start recording
  </button>
Show this button prominently before any recording UI appears.
Only call initCamera() when this button is clicked.

## CHANGE 34.02 — voice-audio.html: same defer pattern
Apply the same deferred permission pattern to voice-audio.html:
  Move initMicrophone() (or equivalent) off page load
  Add "Allow microphone and start recording" button
  Only trigger on user click

## CHANGE 35.01 — voice-confirmed.html: reduce stacked asks
The page currently shows: thank-you tick, Pass the Coin panel, Santos/Origin canvass,
Submit another voice link, privacy note — all in one viewport.
Remove: "Submit another voice" link (one submission is enough)
Move: Santos/Origin sentiment canvass to a separate section below the fold with a heading:
  "One more thing (optional) — 30 seconds"
Keep: thank-you tick, Pass the Coin panel, privacy note above the fold
Keep: Santos/Origin canvass below fold

## CHANGE 35.02 — voice-confirmed.html: Santos/Origin auto-submit
Find the Santos/Origin sentiment form. It currently auto-submits (finding 35.x).
Remove the auto-submit behaviour.
Replace with a standard submit button: "Send my view ›"
The user must click to submit, not just select an option.

## VERIFICATION
For each changed file:
1. div balance
2. script balance
3. Skip link present and prominent on voice-welcome.html
4. Default display is first-name-only in voice-text.html
5. initCamera() not called on page load in voice-video.html
6. initMicrophone() not called on page load in voice-audio.html
7. "Submit another voice" removed from voice-confirmed.html
8. Santos/Origin auto-submit removed

## COMMIT
git add intro/voice-welcome.html intro/voice-text.html intro/voice-audio.html intro/voice-video.html intro/voice-confirmed.html
git commit -m "fix(voice): defer camera/mic permission, opt-in state display, skip links, reduce stacked asks"
git checkout -b review/session-13 && git push origin review/session-13
