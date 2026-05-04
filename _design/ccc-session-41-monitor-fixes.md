# CCC Session 41: monitor.php — 4 targeted fixes
# Branch: review/session-41-monitor-fixes
# FILES: admin/monitor.php

## Purpose
Four confirmed bugs in admin/monitor.php:

1. Live Errors panel stuck on "Loading..." — fetches dashboard.php?ajax=admin-summary
   but the endpoint is on monitor.php. One URL string fix.

2. Campaign Links panel too detailed — simplify to 3 links only:
   one per platform (FB, YT, IG) for cold path /seat/ only.

3. "Paid members" stat card — remove. Paid data is not reliable
   at this stage and clutters the view.

4. Stat cards — replace Paid card with Cold landed and Warm landed.
   sessions_seat (cold total) and unique_sessions_7d (all sessions = warm proxy)
   are already in the API response. Wire them to new cards.

Note on "Read Guide" showing nothing: this is correct behaviour.
seat_inside was only added to $ALLOWED_PATHS in session 39. No historical
data exists yet. It will populate as visitors hit /seat/inside/.
No code change needed for this.

---

## Step 1 — Pull and sync check

```bash
git fetch origin main --quiet
LOCAL=$(git rev-parse HEAD)
REMOTE=$(git rev-parse origin/main)
if [ "$LOCAL" != "$REMOTE" ]; then
  echo "ABORT: local repo is behind origin/main."
  exit 1
fi
echo "SYNC OK: $(git log --oneline -1)"
git pull --rebase origin main
```

---

## Step 2 — Ground truth

```bash
echo "=== Live Errors fetch URL ==="
grep -n "dashboard.php.*ajax=admin-summary\|monitor.php.*ajax=admin-summary" admin/monitor.php

echo "=== cfPaid element ==="
grep -n "cfPaid" admin/monitor.php

echo "=== Campaign Links panel ==="
grep -c "utm_content" admin/monitor.php

echo "=== cfLandedKpi binding ==="
grep -n "cfLandedKpi\|sessions_seat\b" admin/monitor.php
```

Abort if:
- "dashboard.php?ajax=admin-summary" count = 0 (already fixed)
- cfPaid count = 0 (already fixed)

---

## Step 3 — Fix 1: Live Errors fetch URL

```bash
python3 << 'PYEOF'
with open('admin/monitor.php') as f:
    content = f.read()

OLD = "fetch('dashboard.php?ajax=admin-summary', {credentials:'include'})"
NEW = "fetch('monitor.php?ajax=admin-summary', {credentials:'include'})"

count = content.count(OLD)
print(f"URL match: {count} (must be 1)")
if count != 1:
    print("ABORT")
    exit(1)

content = content.replace(OLD, NEW)
with open('admin/monitor.php', 'w') as f:
    f.write(content)
print("Live Errors URL fixed.")
PYEOF
```

---

## Step 4 — Fix 2: Replace Paid card with Cold landed + Warm landed

Replace the 3-card grid (Emails, Paid, Total sessions) with
(Emails, Cold landed, Warm landed).

```bash
python3 << 'PYEOF'
with open('admin/monitor.php') as f:
    content = f.read()

OLD = """                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px;">
                    <div style="background:#0f172a;border:1px solid #1e293b;border-radius:8px;padding:14px 16px;">
                        <div id="cfLeads" style="font-size:1.6rem;font-weight:700;color:#f0d18a;">—</div>
                        <div style="font-size:0.78em;color:#94a3b8;margin-top:2px;">Emails captured (7d)</div>
                    </div>
                    <div style="background:#0f172a;border:1px solid #1e293b;border-radius:8px;padding:14px 16px;">
                        <div id="cfPaid" style="font-size:1.6rem;font-weight:700;color:#52b87a;">—</div>
                        <div style="font-size:0.78em;color:#94a3b8;margin-top:2px;">Paid members (7d)</div>
                    </div>
                    <div style="background:#0f172a;border:1px solid #1e293b;border-radius:8px;padding:14px 16px;">
                        <div id="cfLandedKpi" style="font-size:1.6rem;font-weight:700;color:#e2e8f0;">—</div>
                        <div style="font-size:0.78em;color:#94a3b8;margin-top:2px;">Total sessions (7d)</div>
                    </div>
                </div>"""

NEW = """                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px;">
                    <div style="background:#0f172a;border:1px solid #1e293b;border-radius:8px;padding:14px 16px;">
                        <div id="cfLeads" style="font-size:1.6rem;font-weight:700;color:#f0d18a;">—</div>
                        <div style="font-size:0.78em;color:#94a3b8;margin-top:2px;">Emails captured (7d)</div>
                    </div>
                    <div style="background:#0f172a;border:1px solid #1e293b;border-radius:8px;padding:14px 16px;">
                        <div id="cfColdLanded" style="font-size:1.6rem;font-weight:700;color:#f0d18a;">—</div>
                        <div style="font-size:0.78em;color:#94a3b8;margin-top:2px;">Cold path landed (7d)</div>
                    </div>
                    <div style="background:#0f172a;border:1px solid #1e293b;border-radius:8px;padding:14px 16px;">
                        <div id="cfWarmLanded" style="font-size:1.6rem;font-weight:700;color:#38bdf8;">—</div>
                        <div style="font-size:0.78em;color:#94a3b8;margin-top:2px;">Warm path landed (7d)</div>
                    </div>
                </div>"""

count = content.count(OLD)
print(f"Card grid match: {count} (must be 1)")
if count != 1:
    print("ABORT")
    exit(1)

content = content.replace(OLD, NEW)
with open('admin/monitor.php', 'w') as f:
    f.write(content)
print("Stat cards updated.")
PYEOF
```

---

## Step 5 — Fix 3: Update JS bindings for new cards

Replace cfPaid and cfLandedKpi bindings with cfColdLanded and cfWarmLanded.

```bash
python3 << 'PYEOF'
with open('admin/monitor.php') as f:
    content = f.read()

# Replace cfPaid binding -> cfColdLanded (seats = cold landed)
OLD_PAID = "                document.getElementById('cfPaid').textContent      = (d.warm_funnel||[]).find(s=>s.stage==='Paid')?.sessions ?? '—';"
NEW_PAID = "                document.getElementById('cfColdLanded').textContent = d.sessions_seat ?? '—';"

count1 = content.count(OLD_PAID)
print(f"cfPaid binding match: {count1} (must be 1)")
if count1 != 1:
    print("ABORT")
    exit(1)
content = content.replace(OLD_PAID, NEW_PAID)

# Replace cfLandedKpi binding -> cfWarmLanded (all sessions = warm proxy)
OLD_KPI = "                document.getElementById('cfLandedKpi').textContent = d.visits_total ?? '—';"
NEW_KPI = "                document.getElementById('cfWarmLanded').textContent = d.unique_sessions_7d ?? '—';"

count2 = content.count(OLD_KPI)
print(f"cfLandedKpi binding match: {count2} (must be 1)")
if count2 != 1:
    print("ABORT")
    exit(1)
content = content.replace(OLD_KPI, NEW_KPI)

with open('admin/monitor.php', 'w') as f:
    f.write(content)
print("JS bindings updated.")
PYEOF
```

---

## Step 6 — Fix 4: Simplify Campaign Links to 3 links only

Replace the entire panel content with 3 rows — FB, YT, IG for cold path /seat/ only.

```bash
python3 << 'PYEOF'
with open('admin/monitor.php') as f:
    content = f.read()

# Find and replace the full inner content of the campaign links panel
OLD = """  <div style="background:#0f1923;border:1px solid rgba(255,255,255,0.08);border-radius:10px;overflow:hidden;padding:16px 20px;">

    <div style="margin-bottom:18px;">
      <div style="font-size:0.78rem;font-weight:700;color:#d4b25c;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:10px;">Cold Path — /seat/ (lead magnet)</div>
      <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
        <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
          <td style="padding:5px 10px 5px 0;color:#64748b;white-space:nowrap;width:120px;">Facebook A</td>
          <td style="padding:5px 0;font-family:monospace;font-size:0.76rem;"><span style="color:#94a3b8;user-select:all;">https://cogsaustralia.org/seat/?ref=fb&amp;utm_campaign=seat-launch&amp;utm_content=fb-a</span></td>
        </tr>
        <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
          <td style="padding:5px 10px 5px 0;color:#64748b;white-space:nowrap;">Facebook B</td>
          <td style="padding:5px 0;font-family:monospace;font-size:0.76rem;"><span style="color:#94a3b8;user-select:all;">https://cogsaustralia.org/seat/?ref=fb&amp;utm_campaign=seat-launch&amp;utm_content=fb-b</span></td>
        </tr>
        <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
          <td style="padding:5px 10px 5px 0;color:#64748b;white-space:nowrap;">YouTube A</td>
          <td style="padding:5px 0;font-family:monospace;font-size:0.76rem;"><span style="color:#94a3b8;user-select:all;">https://cogsaustralia.org/seat/?ref=yt&amp;utm_campaign=seat-launch&amp;utm_content=yt-a</span></td>
        </tr>
        <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
          <td style="padding:5px 10px 5px 0;color:#64748b;white-space:nowrap;">YouTube B</td>
          <td style="padding:5px 0;font-family:monospace;font-size:0.76rem;"><span style="color:#94a3b8;user-select:all;">https://cogsaustralia.org/seat/?ref=yt&amp;utm_campaign=seat-launch&amp;utm_content=yt-b</span></td>
        </tr>
        <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
          <td style="padding:5px 10px 5px 0;color:#64748b;white-space:nowrap;">Instagram A</td>
          <td style="padding:5px 0;font-family:monospace;font-size:0.76rem;"><span style="color:#94a3b8;user-select:all;">https://cogsaustralia.org/seat/?ref=ig&amp;utm_campaign=seat-launch&amp;utm_content=ig-a</span></td>
        </tr>
        <tr>
          <td style="padding:5px 10px 5px 0;color:#64748b;white-space:nowrap;">Instagram B</td>
          <td style="padding:5px 0;font-family:monospace;font-size:0.76rem;"><span style="color:#94a3b8;user-select:all;">https://cogsaustralia.org/seat/?ref=ig&amp;utm_campaign=seat-launch&amp;utm_content=ig-b</span></td>
        </tr>
      </table>
    </div>

    <div style="margin-bottom:18px;">
      <div style="font-size:0.78rem;font-weight:700;color:#38bdf8;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:10px;">Warm Path — / (homepage, Fair Say organic posts)</div>
      <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
        <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
          <td style="padding:5px 10px 5px 0;color:#64748b;white-space:nowrap;width:120px;">Facebook 1</td>
          <td style="padding:5px 0;font-family:monospace;font-size:0.76rem;"><span style="color:#94a3b8;user-select:all;">https://cogsaustralia.org/?ref=fb&amp;utm_campaign=fairsay&amp;utm_content=post-1</span></td>
        </tr>
        <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
          <td style="padding:5px 10px 5px 0;color:#64748b;white-space:nowrap;">Facebook 2</td>
          <td style="padding:5px 0;font-family:monospace;font-size:0.76rem;"><span style="color:#94a3b8;user-select:all;">https://cogsaustralia.org/?ref=fb&amp;utm_campaign=fairsay&amp;utm_content=post-2</span></td>
        </tr>
        <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
          <td style="padding:5px 10px 5px 0;color:#64748b;white-space:nowrap;">Facebook 3</td>
          <td style="padding:5px 0;font-family:monospace;font-size:0.76rem;"><span style="color:#94a3b8;user-select:all;">https://cogsaustralia.org/?ref=fb&amp;utm_campaign=fairsay&amp;utm_content=post-3</span></td>
        </tr>
        <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
          <td style="padding:5px 10px 5px 0;color:#64748b;white-space:nowrap;">YouTube 1</td>
          <td style="padding:5px 0;font-family:monospace;font-size:0.76rem;"><span style="color:#94a3b8;user-select:all;">https://cogsaustralia.org/?ref=yt&amp;utm_campaign=fairsay&amp;utm_content=post-1</span></td>
        </tr>
        <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
          <td style="padding:5px 10px 5px 0;color:#64748b;white-space:nowrap;">YouTube 2</td>
          <td style="padding:5px 0;font-family:monospace;font-size:0.76rem;"><span style="color:#94a3b8;user-select:all;">https://cogsaustralia.org/?ref=yt&amp;utm_campaign=fairsay&amp;utm_content=post-2</span></td>
        </tr>
        <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
          <td style="padding:5px 10px 5px 0;color:#64748b;white-space:nowrap;">Instagram 1</td>
          <td style="padding:5px 0;font-family:monospace;font-size:0.76rem;"><span style="color:#94a3b8;user-select:all;">https://cogsaustralia.org/?ref=ig&amp;utm_campaign=fairsay&amp;utm_content=post-1</span></td>
        </tr>
        <tr>
          <td style="padding:5px 10px 5px 0;color:#64748b;white-space:nowrap;">Instagram 2</td>
          <td style="padding:5px 0;font-family:monospace;font-size:0.76rem;"><span style="color:#94a3b8;user-select:all;">https://cogsaustralia.org/?ref=ig&amp;utm_campaign=fairsay&amp;utm_content=post-2</span></td>
        </tr>
      </table>
    </div>

    <div>
      <div style="font-size:0.78rem;font-weight:700;color:#a78bfa;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:10px;">Warm Path — /intro/ (intro flow)</div>
      <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
        <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
          <td style="padding:5px 10px 5px 0;color:#64748b;white-space:nowrap;width:120px;">Facebook</td>
          <td style="padding:5px 0;font-family:monospace;font-size:0.76rem;"><span style="color:#94a3b8;user-select:all;">https://cogsaustralia.org/intro/?ref=fb&amp;utm_campaign=fairsay&amp;utm_content=fb-a</span></td>
        </tr>
        <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
          <td style="padding:5px 10px 5px 0;color:#64748b;white-space:nowrap;">YouTube</td>
          <td style="padding:5px 0;font-family:monospace;font-size:0.76rem;"><span style="color:#94a3b8;user-select:all;">https://cogsaustralia.org/intro/?ref=yt&amp;utm_campaign=fairsay&amp;utm_content=yt-a</span></td>
        </tr>
        <tr>
          <td style="padding:5px 10px 5px 0;color:#64748b;white-space:nowrap;">Instagram</td>
          <td style="padding:5px 0;font-family:monospace;font-size:0.76rem;"><span style="color:#94a3b8;user-select:all;">https://cogsaustralia.org/intro/?ref=ig&amp;utm_campaign=fairsay&amp;utm_content=ig-a</span></td>
        </tr>
      </table>
    </div>

    <div style="margin-top:14px;padding-top:12px;border-top:1px solid rgba(255,255,255,0.06);font-size:0.75rem;color:#334155;">
      Source: <code style="color:#64748b;">_design/TRACKING-SPEC.md</code> &nbsp;|&nbsp;
      Add new links there first, then update this panel.
    </div>
  </div>"""

NEW = """  <div style="background:#0f1923;border:1px solid rgba(255,255,255,0.08);border-radius:10px;overflow:hidden;padding:16px 20px;">
    <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
      <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
        <td style="padding:6px 12px 6px 0;color:#64748b;white-space:nowrap;width:100px;">Facebook</td>
        <td style="padding:6px 0;font-family:monospace;font-size:0.78rem;"><span style="color:#94a3b8;user-select:all;">https://cogsaustralia.org/seat/?ref=fb&amp;utm_campaign=seat-launch</span></td>
      </tr>
      <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
        <td style="padding:6px 12px 6px 0;color:#64748b;white-space:nowrap;">YouTube</td>
        <td style="padding:6px 0;font-family:monospace;font-size:0.78rem;"><span style="color:#94a3b8;user-select:all;">https://cogsaustralia.org/seat/?ref=yt&amp;utm_campaign=seat-launch</span></td>
      </tr>
      <tr>
        <td style="padding:6px 12px 6px 0;color:#64748b;white-space:nowrap;">Instagram</td>
        <td style="padding:6px 0;font-family:monospace;font-size:0.78rem;"><span style="color:#94a3b8;user-select:all;">https://cogsaustralia.org/seat/?ref=ig&amp;utm_campaign=seat-launch</span></td>
      </tr>
    </table>
  </div>"""

count = content.count(OLD)
print(f"Panel content match: {count} (must be 1)")
if count != 1:
    print("ABORT")
    exit(1)

content = content.replace(OLD, NEW)
with open('admin/monitor.php', 'w') as f:
    f.write(content)
print("Campaign links simplified.")
PYEOF
```

---

## Step 7 — PHP lint

```bash
php -l admin/monitor.php
```

## STOP — must show "No syntax errors detected".

---

## Step 8 — Verification

```bash
python3 << 'PYEOF'
with open('admin/monitor.php') as f:
    content = f.read()

checks = [
    # Fix 1: Live Errors URL
    ("errors: monitor.php URL",             "monitor.php?ajax=admin-summary" in content),
    ("errors: no dashboard.php URL",        "dashboard.php?ajax=admin-summary" not in content),

    # Fix 2: Stat cards
    ("cards: cfColdLanded present",         'id="cfColdLanded"' in content),
    ("cards: cfWarmLanded present",         'id="cfWarmLanded"' in content),
    ("cards: cfPaid removed from HTML",     'id="cfPaid"' not in content),
    ("cards: Cold path landed label",       "Cold path landed" in content),
    ("cards: Warm path landed label",       "Warm path landed" in content),
    ("cards: cfLeads still present",        'id="cfLeads"' in content),

    # Fix 3: JS bindings
    ("js: cfColdLanded binds sessions_seat","cfColdLanded" in content and "sessions_seat" in content),
    ("js: cfWarmLanded binds unique_sessions","cfWarmLanded" in content and "unique_sessions_7d" in content),
    ("js: no cfPaid binding",               "cfPaid" not in content),
    ("js: no cfLandedKpi binding",          "cfLandedKpi" not in content),
    ("js: cfLeads binding intact",          "cfLeads" in content and "leads_captures" in content),

    # Fix 4: Campaign links simplified
    ("links: 3 links only",                 content.count("user-select:all") == 3),
    ("links: fb seat-launch",               "ref=fb&amp;utm_campaign=seat-launch" in content),
    ("links: yt seat-launch",               "ref=yt&amp;utm_campaign=seat-launch" in content),
    ("links: ig seat-launch",               "ref=ig&amp;utm_campaign=seat-launch" in content),
    ("links: no fb-a variant",              "utm_content=fb-a" not in content),
    ("links: no warm path links",           "fairsay" not in content),

    # Existing elements intact
    ("monitor: Live Error Panel intact",    "Live Error Panel" in content),
    ("monitor: errorBadge intact",          "errorBadge" in content),
    ("monitor: loadFunnel intact",          "loadFunnel" in content),
    ("monitor: cfMatrixHead intact",        "cfMatrixHead" in content),
    ("monitor: Campaign Links header",      "Campaign Links" in content),
]

all_pass = True
for label, ok in checks:
    s = 'PASS' if ok else 'FAIL'
    if not ok:
        all_pass = False
    print(f'[{s}] {label}')

print()
print('ALL PASS' if all_pass else 'FAILURES DETECTED — do not commit')
PYEOF
```

---

## Step 9 — Commit and push

Only if ALL PASS and PHP lint clean.

```bash
git checkout -b review/session-41-monitor-fixes
git add admin/monitor.php
git diff --cached --stat
git commit -m "fix(monitor): 4 targeted monitor.php fixes

1. Live Errors: fetch URL was dashboard.php, must be monitor.php
   — panel was permanently stuck on Loading...

2. Stat cards: remove Paid (unreliable), add Cold path landed
   (sessions_seat) and Warm path landed (unique_sessions_7d)
   — both already in API response, just needed wiring

3. Campaign links: simplified from 13 rows to 3 rows
   — one link per platform (FB/YT/IG) for /seat/ cold path only
   — utm_content variants removed for clean sharing

4. JS bindings: cfPaid/cfLandedKpi replaced with
   cfColdLanded/cfWarmLanded pointing to correct API fields"
git push origin review/session-41-monitor-fixes
```

## STOP — paste full verification output and diff stat for review before merge.
