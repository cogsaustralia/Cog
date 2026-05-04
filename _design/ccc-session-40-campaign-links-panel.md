# CCC Session 40: Instagram support + Campaign Links panel in monitor
# Branch: review/session-40-campaign-links
# FILES: admin/monitor.php · _design/TRACKING-SPEC.md

## Purpose
Two things:

1. Instagram (ig) is already in track.php $allowedRefs and DB — but
   the monitor icons dict shows 'ig' as plain text instead of a label.
   Fix the icons dict to show platform labels clearly for all three.

2. Add a Campaign Links panel to admin/monitor.php immediately before
   the Live Error Panel. Shows all ready-to-paste tracking URLs for
   FB, YT, and Instagram across cold and warm paths. Thomas can copy
   any link directly from the monitor without opening any other file.
   Static panel — no API call required.

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
echo "=== icons dict in monitor.php ==="
grep -n "icons.*fb\|icons.*yt\|icons.*ig" admin/monitor.php

echo "=== Live Error Panel anchor ==="
grep -n "<!-- Live Error Panel -->" admin/monitor.php

echo "=== ig in track.php allowedRefs ==="
grep "allowedRefs" _app/api/routes/track.php

echo "=== TRACKING-SPEC updated ==="
grep -c "ig-a\|ig-b" _design/TRACKING-SPEC.md
```

Abort if:
- "<!-- Live Error Panel -->" count != 1

---

## Step 3 — Fix icons dict in monitor.php

Replace plain text icon labels with readable platform names.

```bash
python3 << 'PYEOF'
with open('admin/monitor.php') as f:
    content = f.read()

OLD = "                const icons = {fb:'fb',yt:'yt',ig:'ig',tw:'tw',li:'li',email:'email',sms:'sms',direct:'direct',qr:'qr',other:'other'};"

NEW = "                const icons = {fb:'Facebook',yt:'YouTube',ig:'Instagram',tw:'Twitter',li:'LinkedIn',email:'Email',sms:'SMS',direct:'Direct',qr:'QR code',other:'Other'};"

count = content.count(OLD)
print(f"Icons dict match: {count} (must be 1)")
if count != 1:
    print("ABORT")
    exit(1)

content = content.replace(OLD, NEW)
with open('admin/monitor.php', 'w') as f:
    f.write(content)
print("Icons dict updated.")
PYEOF
```

---

## Step 4 — Insert Campaign Links panel before Live Error Panel

```bash
python3 << 'PYEOF'
with open('admin/monitor.php') as f:
    content = f.read()

ANCHOR = '<!-- Live Error Panel -->'
count = content.count(ANCHOR)
print(f"Anchor count: {count} (must be 1)")
if count != 1:
    print("ABORT")
    exit(1)

PANEL = """<!-- Campaign Links Panel -->
<div style="margin-top:24px;margin-bottom:24px;">
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
    <h2 style="font-size:0.9rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;margin:0;">Campaign Links</h2>
    <span style="font-size:0.75rem;color:#475569;margin-left:auto;">Copy and paste — do not edit parameters</span>
  </div>
  <div style="background:#0f1923;border:1px solid rgba(255,255,255,0.08);border-radius:10px;overflow:hidden;padding:16px 20px;">

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
  </div>
</div>

"""

content = content.replace(ANCHOR, PANEL + ANCHOR)
with open('admin/monitor.php', 'w') as f:
    f.write(content)
print("Campaign Links panel inserted.")
print(f"File size: {len(content):,} chars")
PYEOF
```

---

## Step 5 — PHP lint

```bash
php -l admin/monitor.php
```

## STOP — must show "No syntax errors detected".

---

## Step 6 — Verification

```bash
python3 << 'PYEOF'
with open('admin/monitor.php') as f:
    content = f.read()

checks = [
    # Icons dict
    ("icons: Facebook label",           "fb:'Facebook'" in content),
    ("icons: YouTube label",            "yt:'YouTube'" in content),
    ("icons: Instagram label",          "ig:'Instagram'" in content),
    ("icons: no bare 'fb' label",       "fb:'fb'" not in content),

    # Panel present
    ("panel: Campaign Links header",    "Campaign Links" in content),
    ("panel: before Live Error Panel",  content.index("Campaign Links") < content.index("Live Error Panel")),
    ("panel: Cold Path section",        "Cold Path" in content and "seat-launch" in content),
    ("panel: Warm Path section",        "Warm Path" in content and "fairsay" in content),
    ("panel: Intro path section",       "intro/" in content and "fairsay" in content),

    # All 6 cold path links present
    ("links: fb-a seat",                "seat/?ref=fb&amp;utm_campaign=seat-launch&amp;utm_content=fb-a" in content),
    ("links: fb-b seat",                "seat/?ref=fb&amp;utm_campaign=seat-launch&amp;utm_content=fb-b" in content),
    ("links: yt-a seat",                "seat/?ref=yt&amp;utm_campaign=seat-launch&amp;utm_content=yt-a" in content),
    ("links: yt-b seat",                "seat/?ref=yt&amp;utm_campaign=seat-launch&amp;utm_content=yt-b" in content),
    ("links: ig-a seat",                "seat/?ref=ig&amp;utm_campaign=seat-launch&amp;utm_content=ig-a" in content),
    ("links: ig-b seat",                "seat/?ref=ig&amp;utm_campaign=seat-launch&amp;utm_content=ig-b" in content),

    # Warm path links
    ("links: fb fairsay post-1",        "ref=fb&amp;utm_campaign=fairsay&amp;utm_content=post-1" in content),
    ("links: yt fairsay post-1",        "ref=yt&amp;utm_campaign=fairsay&amp;utm_content=post-1" in content),
    ("links: ig fairsay post-1",        "ref=ig&amp;utm_campaign=fairsay&amp;utm_content=post-1" in content),

    # user-select:all for easy copy
    ("links: user-select:all present",  "user-select:all" in content),

    # Source reference in panel
    ("panel: TRACKING-SPEC reference",  "TRACKING-SPEC.md" in content),

    # Original elements intact
    ("monitor: Live Error Panel intact", "Live Error Panel" in content),
    ("monitor: errorBadge intact",       "errorBadge" in content),
    ("monitor: loadFunnel intact",       "loadFunnel" in content),
    ("monitor: cfMatrixHead intact",     "cfMatrixHead" in content),
    ("monitor: structure intact",        "</main>" in content or "errorPanelBody" in content),
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

## Step 7 — Commit and push

Only if ALL PASS and PHP lint clean.

```bash
git checkout -b review/session-40-campaign-links
git add admin/monitor.php _design/TRACKING-SPEC.md
git diff --cached --stat
git commit -m "feat(monitor): campaign links panel + Instagram support

admin/monitor.php:
- Campaign Links panel added before Live Error Panel
- All 6 cold path links: FB A/B, YT A/B, IG A/B -> /seat/
- All warm path links: FB/YT/IG -> / and /intro/
- user-select:all on every link for one-click copy
- Source labels updated: 'fb'->'Facebook', 'yt'->'YouTube',
  'ig'->'Instagram' etc across Pages by Source matrix

_design/TRACKING-SPEC.md:
- ig-b variant added to utm_content table
- Full campaign link library with all 3 platforms and all variants
- Panel reference rule added"
git push origin review/session-40-campaign-links
```

## STOP — paste full verification output and diff stat for review before merge.
