# UX Audit Remediation — Session 3: seat/index.html
# Read file before every edit. Show diff. STOP before committing.

## GROUND TRUTH RULES
- Read exact current file state before every edit
- Stage only seat/index.html
- Verify div balance, script balance, </body>, </html> after changes
- Zero AI tells — Grade 6 Australian plain English only

---

## CHANGE X.2 — Privacy link on email capture

Find the email capture form and its submit button.
Add this line directly below the submit button, inside the form container:
  <p style="font-size:.78rem;color:var(--muted);margin-top:8px;text-align:center">
    We will not share your email.
    <a href="/privacy/" style="color:var(--gold-2);text-decoration:underline">Privacy policy</a>.
  </p>

If a privacy page does not exist yet, the link still goes in — it will be built separately.

---

## CHANGE 2.5 — Move Substack link out of CTA area

Find the "Read the CEO Power Cheat Sheet" Substack link or any Substack reference
near the primary $4 or join CTA.
Move it to the page footer area, below the main content.
It must not appear adjacent to or competing with the primary conversion CTA.
If it is already in the footer, confirm and leave it. Report what you find.

---

## VERIFICATION
1. div balance
2. script balance
3. </body> once, </html> once
4. Privacy link present below email submit button
5. No Substack link adjacent to primary CTA

## COMMIT
  git add seat/index.html
  git commit -m "fix(seat): privacy link on email capture, Substack moved from CTA area"
  git pull --rebase origin main && git push origin main
