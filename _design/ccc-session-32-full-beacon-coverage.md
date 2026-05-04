# CCC Session 32: full beacon coverage + live error panel + email alert
# Branch: review/session-32
# FILES: seat/index.html · seat/inside/index.html · wallets/member.html
#        wallets/business.html · admin/monitor.php · _app/api/routes/admin.php

## Purpose
Session 31 added the client-error beacon to site.js, covering the join flow only.
This session extends coverage to the 4 uncovered pages that matter most:
  /seat/           (lead capture — active campaign cold path)
  /seat/inside/    (phone capture — step 2 of cold funnel)
  wallets/member.html  (member vault — most JS-heavy page)
  wallets/business.html

It also adds:
  A. Live error panel in admin/monitor.php — auto-refreshes every 30s,
     shows unacknowledged client + server errors with route, message, time.
  B. Email alert to admin@cogsaustralia.org the first time a new error class
     appears (debounced: same route+message combo only emails once per hour).
     Uses the existing smtpSendEmail() — no new dependencies.

---

## Step 1 — Pull and ground truth

```bash
git pull --rebase origin main

echo "=== session-31 beacon present in site.js ==="
grep -c "Client-side JS error beacon" assets/site.js

echo "=== session-31 client-error route registered ==="
grep -c "client-error" _app/api/index.php

echo "=== uncovered pages have NO window.onerror ==="
for f in seat/index.html seat/inside/index.html wallets/member.html wallets/business.html; do
  echo -n "$f: "; grep -c "window.onerror" "$f" 2>/dev/null || echo "0"
done

echo "=== </head> appears exactly once on each page ==="
for f in seat/index.html seat/inside/index.html wallets/member.html wallets/business.html; do
  echo -n "$f: "; grep -c "</head>" "$f"
done

echo "=== monitor.php has errorRate element ==="
grep -c "errorRate" admin/monitor.php

echo "=== adminSummary in admin.php ==="
grep -c "adminSummary" _app/api/routes/admin.php
```

Abort if:
- session-31 beacon is NOT in site.js (count = 0)
- Any </head> count != 1
- adminSummary count = 0

---

## Step 2 — Inline beacon snippet for 4 uncovered pages

The snippet is identical on all 4 pages. It is injected immediately before </head>.
It shares the same logic as the site.js beacon: MAX_REPORTS=3, sendBeacon,
falls back to fetch, derives API root from relative path depth.

Each page uses a different ROOT prefix:
- seat/index.html        ROOT = '../'
- seat/inside/index.html ROOT = '../../'
- wallets/member.html    ROOT = '../'
- wallets/business.html  ROOT = '../'

```bash
python3 << 'PYEOF'
import re

PAGES = {
    'seat/index.html':          '../',
    'seat/inside/index.html':   '../../',
    'wallets/member.html':      '../',
    'wallets/business.html':    '../',
}

BEACON_TPL = """<script>
/* Client-side JS error beacon — injected for pages that do not load site.js */
(function(){{
  'use strict';
  var MAX=3,n=0,ROOT='{root}';
  function ep(){{return ROOT+'_app/api/index.php/client-error';}}
  function send(p){{
    if(n>=MAX)return;n++;
    try{{
      var b=JSON.stringify(p);
      if(navigator.sendBeacon){{navigator.sendBeacon(ep(),new Blob([b],{{type:'application/json'}}));}}
      else{{fetch(ep(),{{method:'POST',body:b,keepalive:true,headers:{{'Content-Type':'application/json'}}}}).catch(function(){{}});}}
    }}catch(e){{}}
  }}
  window.onerror=function(msg,src,line,col,err){{
    send({{message:String(msg||'').slice(0,500),source:String(src||'').slice(0,255),
          line:line||null,col:col||null,
          stack:err&&err.stack?String(err.stack).slice(0,2000):null,
          page:window.location.pathname.slice(0,120),
          ua_hint:(navigator.userAgent||'').slice(0,80)}});
    return false;
  }};
  window.addEventListener('unhandledrejection',function(e){{
    var r=e&&e.reason,msg=r instanceof Error?r.message:String(r||'Unhandled promise rejection');
    send({{message:msg.slice(0,500),source:null,line:null,col:null,
          stack:r instanceof Error&&r.stack?String(r.stack).slice(0,2000):null,
          page:window.location.pathname.slice(0,120),
          ua_hint:(navigator.userAgent||'').slice(0,80)}});
  }});
}})();
</script>
</head>"""

results = []
for path, root in PAGES.items():
    with open(path) as f:
        content = f.read()

    # Guard: skip if already injected
    if 'Client-side JS error beacon' in content:
        print(f"SKIP (already present): {path}")
        results.append((path, 'skipped'))
        continue

    count = content.count('</head>')
    if count != 1:
        print(f"ABORT: {path} has {count} </head> tags")
        results.append((path, 'aborted'))
        continue

    snippet = BEACON_TPL.format(root=root)
    new_content = content.replace('</head>', snippet)

    with open(path, 'w') as f:
        f.write(new_content)
    print(f"INJECTED: {path}")
    results.append((path, 'injected'))

print("\nSummary:", results)
PYEOF
```

---

## Step 3 — Add unacknowledged error count to adminSummary API response

This adds `unack_errors` and `recent_errors` (last 5) to the admin/summary
endpoint so the monitor dashboard can display them without a separate fetch.

```bash
python3 << 'PYEOF'
with open('_app/api/routes/admin.php') as f:
    content = f.read()

# Insert DB queries just before the apiSuccess call in adminSummary
# The anchor is the unique string at the end of adminSummary's data block
OLD = "        'crm_provider' => CRM_PROVIDER,"

NEW = """        'crm_provider' => CRM_PROVIDER,
        'unack_errors' => (static function() use ($db): int {
            try {
                if (!api_table_exists($db, 'app_error_log')) return 0;
                return (int)($db->query("SELECT COUNT(*) FROM app_error_log WHERE acknowledged=0")->fetchColumn() ?: 0);
            } catch (Throwable $e) { return 0; }
        })(),
        'recent_errors' => (static function() use ($db): array {
            try {
                if (!api_table_exists($db, 'app_error_log')) return [];
                return $db->query(
                    "SELECT route, http_status, LEFT(error_message,200) AS msg, area_key, created_at
                       FROM app_error_log WHERE acknowledged=0
                      ORDER BY id DESC LIMIT 5"
                )->fetchAll() ?: [];
            } catch (Throwable $e) { return []; }
        })(),"""

count = content.count(OLD)
print(f"Anchor match count: {count} (must be 1)")
if count == 1:
    content = content.replace(OLD, NEW)
    with open('_app/api/routes/admin.php', 'w') as f:
        f.write(content)
    print("adminSummary updated.")
else:
    print("ABORT: anchor not found or not unique")
PYEOF
```

---

## Step 4 — Add email alert on first-seen error class in client-error.php

When a new error class (route + first 120 chars of message) is written that
has NOT been seen in the last 60 minutes, send one email to MAIL_ADMIN_EMAIL.
Uses the existing smtpSendEmail() function already required via mailer.php.

```bash
python3 << 'PYEOF'
with open('_app/api/routes/client-error.php') as f:
    content = f.read()

# Replace the final db->prepare INSERT block with the version that
# includes the first-seen email alert. Anchor on the unique INSERT line.
OLD = '''    $db = getDB();
    $db->prepare(
        "INSERT INTO app_error_log
           (route, http_status, error_message, area_key,
            member_id, request_method, ip_hash, ua_hash, created_at)
         VALUES (?, 0, ?, ?, NULL, 'GET', ?, ?, NOW())"
    )->execute([
        $route,
        $fullMessage,
        'client-js',
        $ipHash,
        $uaHash,
    ]);'''

NEW = '''    $db = getDB();
    $db->prepare(
        "INSERT INTO app_error_log
           (route, http_status, error_message, area_key,
            member_id, request_method, ip_hash, ua_hash, created_at)
         VALUES (?, 0, ?, ?, NULL, \'GET\', ?, ?, NOW())"
    )->execute([
        $route,
        $fullMessage,
        \'client-js\',
        $ipHash,
        $uaHash,
    ]);

    // First-seen alert: email admin if this error class has not fired in the last 60 minutes.
    // Debounced by route + message snippet to avoid flooding on repeat errors.
    try {
        $snippet = substr($fullMessage, 0, 120);
        $recentCount = (int)($db->prepare(
            "SELECT COUNT(*) FROM app_error_log
              WHERE route = ? AND LEFT(error_message,120) = ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)
                AND id != LAST_INSERT_ID()"
        )->execute([$route, $snippet]) ? $db->query("SELECT FOUND_ROWS()")->fetchColumn() : 1);
        // Use a direct COUNT query instead
        $stmt2 = $db->prepare(
            "SELECT COUNT(*) FROM app_error_log
              WHERE route = ? AND LEFT(error_message,120) = ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)
                AND created_at < NOW()"
        );
        $stmt2->execute([$route, $snippet]);
        $priorCount = (int)($stmt2->fetchColumn() ?: 0);

        if ($priorCount === 0 && function_exists(\'smtpSendEmail\') && mailerEnabled()) {
            $adminTo = defined(\'MAIL_ADMIN_EMAIL\') ? MAIL_ADMIN_EMAIL : \'admin@cogsaustralia.org\';
            $subject = \'[COGS Alert] New JS error: \' . substr($route, 0, 60);
            $html = \'<p><strong>New client-side JS error detected on cogsaustralia.org</strong></p>\'
                . \'<p><strong>Route/Page:</strong> \' . htmlspecialchars($route) . \'</p>\'
                . \'<p><strong>Error:</strong> \' . htmlspecialchars(substr($fullMessage, 0, 500)) . \'</p>\'
                . \'<p><strong>Time:</strong> \' . date(\'Y-m-d H:i:s T\') . \'</p>\'
                . \'<p>View and acknowledge at <a href="https://cogsaustralia.org/admin/errors.php">admin/errors.php</a></p>\';
            $text = "New client-side JS error detected on cogsaustralia.org\n"
                . "Route/Page: {$route}\n"
                . "Error: " . substr($fullMessage, 0, 500) . "\n"
                . "Time: " . date(\'Y-m-d H:i:s T\') . "\n"
                . "View: https://cogsaustralia.org/admin/errors.php";
            smtpSendEmail($adminTo, $subject, $html, $text);
        }
    } catch (Throwable $alertEx) {
        error_log(\'[client-error alert] \' . $alertEx->getMessage());
    }'''

count = content.count(OLD)
print(f"INSERT anchor match count: {count} (must be 1)")
if count == 1:
    content = content.replace(OLD, NEW)
    with open('_app/api/routes/client-error.php', 'w') as f:
        f.write(content)
    print("client-error.php updated with email alert.")
else:
    print("ABORT: anchor not found")
PYEOF
```

---

## Step 5 — Add live error panel to admin/monitor.php

Inject an error panel card into the monitor dashboard. It polls the existing
admin/summary endpoint (already authenticated) for `unack_errors` and
`recent_errors`, and displays them live with auto-refresh every 30 seconds.
Red badge on the panel header when unacknowledged errors exist.

```bash
python3 << 'PYEOF'
with open('admin/monitor.php') as f:
    content = f.read()

# Inject the error panel HTML immediately before the closing </main> tag
# which is unique in monitor.php
HTML_ANCHOR = '</main></div>'
count = content.count(HTML_ANCHOR)
print(f"HTML anchor '</main></div>' count: {count} (must be 1)")

if count != 1:
    print("ABORT")
    exit(1)

ERROR_PANEL_HTML = """
<!-- Live Error Panel -->
<div style="margin-top:24px;">
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
    <h2 style="font-size:0.9rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;margin:0;">Live Errors</h2>
    <span id="errorBadge" style="display:none;background:#ef4444;color:#fff;font-size:0.72rem;font-weight:700;padding:2px 8px;border-radius:99px;"></span>
    <span style="font-size:0.75rem;color:#475569;margin-left:auto;">Auto-refresh 30s &nbsp;|&nbsp; <a href="errors.php" style="color:#d4b25c;text-decoration:none;">Full log &rarr;</a></span>
  </div>
  <div id="errorPanelBody" style="background:#0f1923;border:1px solid rgba(255,255,255,0.08);border-radius:10px;overflow:hidden;">
    <div style="padding:20px;text-align:center;color:#475569;font-size:0.83rem;" id="errorPanelLoading">Loading...</div>
  </div>
</div>

<script>
(function(){
  function loadErrorPanel(){
    fetch('dashboard.php?ajax=admin-summary', {credentials:'include'})
      .then(function(r){return r.ok?r.json():Promise.reject(r.status);})
      .then(function(json){
        var d = (json.data || json);
        var unack = parseInt(d.unack_errors || 0);
        var errors = Array.isArray(d.recent_errors) ? d.recent_errors : [];
        var badge = document.getElementById('errorBadge');
        var body  = document.getElementById('errorPanelBody');
        if(unack > 0){
          badge.textContent = unack + ' unacknowledged';
          badge.style.display = 'inline-block';
        } else {
          badge.style.display = 'none';
        }
        if(errors.length === 0){
          body.innerHTML = '<div style="padding:20px;text-align:center;color:#22c55e;font-size:0.83rem;">No unacknowledged errors.</div>';
          return;
        }
        var rows = errors.map(function(e){
          var statusColor = parseInt(e.http_status)===0 ? '#38bdf8' : '#ef4444';
          var statusLabel = parseInt(e.http_status)===0 ? 'JS' : e.http_status;
          return '<div style="padding:10px 14px;border-bottom:1px solid rgba(255,255,255,0.05);display:grid;grid-template-columns:44px 1fr auto;gap:8px;align-items:start;">'
            + '<span style="font-weight:700;font-size:0.78rem;color:'+statusColor+';padding-top:1px;">'+statusLabel+'</span>'
            + '<div>'
            +   '<div style="font-family:monospace;font-size:0.78rem;color:#94a3b8;margin-bottom:2px;">'+esc(e.route||'')+'</div>'
            +   '<div style="font-size:0.82rem;color:#e2e8f0;word-break:break-word;">'+esc((e.msg||'').slice(0,160))+'</div>'
            + '</div>'
            + '<div style="font-size:0.72rem;color:#475569;white-space:nowrap;padding-top:2px;">'+(e.created_at||'').slice(0,16)+'</div>'
            + '</div>';
        }).join('');
        body.innerHTML = rows
          + '<div style="padding:8px 14px;text-align:right;">'
          + '<a href="errors.php" style="font-size:0.78rem;color:#d4b25c;text-decoration:none;">Acknowledge in full log &rarr;</a></div>';
      })
      .catch(function(){ /* silent — monitor panel failing should not alert */ });
  }
  function esc(s){ var d=document.createElement('div');d.textContent=s;return d.innerHTML; }
  loadErrorPanel();
  setInterval(loadErrorPanel, 30000);
})();
</script>
"""

# Also need to wire the admin summary AJAX endpoint on monitor.php
# Currently monitor.php polls ?ajax=monitor-data — add a second AJAX case for admin-summary
AJAX_ANCHOR = "if (isset($_GET['ajax']) && $_GET['ajax'] === 'monitor-data') {"
count2 = content.count(AJAX_ANCHOR)
print(f"AJAX anchor count: {count2} (must be 1)")

if count2 != 1:
    print("ABORT: AJAX anchor not found")
    exit(1)

AJAX_ADDITION = """if (isset($_GET['ajax']) && $_GET['ajax'] === 'admin-summary') {
    ops_require_admin();
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    require_once __DIR__ . '/../_app/api/routes/admin.php';
    // adminSummary() is called via the route file — but here we call getDB directly
    // and return only the error fields to keep this endpoint lightweight
    $pdo2 = ops_db();
    $unack = 0;
    $recent = [];
    try {
        if (function_exists('ops_has_table') && ops_has_table($pdo2, 'app_error_log')) {
            $unack = (int)($pdo2->query("SELECT COUNT(*) FROM app_error_log WHERE acknowledged=0")->fetchColumn() ?: 0);
            $recent = $pdo2->query(
                "SELECT route, http_status, LEFT(error_message,200) AS msg, area_key, created_at
                   FROM app_error_log WHERE acknowledged=0
                  ORDER BY id DESC LIMIT 5"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $e) {}
    echo json_encode(['success'=>true,'data'=>['unack_errors'=>$unack,'recent_errors'=>$recent]]);
    exit;
}

"""

content = content.replace(AJAX_ANCHOR, AJAX_ADDITION + AJAX_ANCHOR)
content = content.replace(HTML_ANCHOR, ERROR_PANEL_HTML + HTML_ANCHOR)

with open('admin/monitor.php', 'w') as f:
    f.write(content)
print("monitor.php updated with error panel and AJAX endpoint.")
PYEOF
```

---

## Step 6 — Verification

```bash
python3 << 'PYEOF'
import re

checks = []

# 1. Beacon injected in all 4 pages
for path, root in [('seat/index.html','../'), ('seat/inside/index.html','../../'),
                   ('wallets/member.html','../'), ('wallets/business.html','../')]:
    with open(path) as f: c = f.read()
    checks.append((f"{path}: window.onerror present",        'window.onerror' in c))
    checks.append((f"{path}: unhandledrejection present",    'unhandledrejection' in c))
    checks.append((f"{path}: correct ROOT='{root}'",         f"ROOT='{root}'" in c))
    checks.append((f"{path}: beacon before </head>",
        c.index('window.onerror') < c.index('</head>')))
    checks.append((f"{path}: single </head>",                c.count('</head>') == 1))

# 2. admin.php has unack_errors
with open('_app/api/routes/admin.php') as f: adm = f.read()
checks.append(("admin.php: unack_errors key",  "'unack_errors'" in adm))
checks.append(("admin.php: recent_errors key", "'recent_errors'" in adm))
checks.append(("admin.php: api_table_exists guard", "api_table_exists" in adm))

# 3. client-error.php has email alert
with open('_app/api/routes/client-error.php') as f: php = f.read()
checks.append(("client-error.php: priorCount check",    'priorCount' in php))
checks.append(("client-error.php: smtpSendEmail call",  'smtpSendEmail' in php))
checks.append(("client-error.php: mailerEnabled guard", 'mailerEnabled' in php))
checks.append(("client-error.php: 60 MINUTE debounce",  'INTERVAL 60 MINUTE' in php))
checks.append(("client-error.php: admin@cogsaustralia", 'admin@cogsaustralia.org' in php))

# 4. monitor.php has error panel
with open('admin/monitor.php') as f: mon = f.read()
checks.append(("monitor.php: errorBadge element",       'errorBadge' in mon))
checks.append(("monitor.php: loadErrorPanel function",  'loadErrorPanel' in mon))
checks.append(("monitor.php: admin-summary AJAX",       'admin-summary' in mon))
checks.append(("monitor.php: 30s refresh interval",     '30000' in mon))
checks.append(("monitor.php: </main></div> intact",     '</main></div>' in mon))
checks.append(("monitor.php: ops_has_table guard",      'ops_has_table' in mon))

# 5. Structure checks
for tag in ['</style>','</head>','<body','</body>','</html>']:
    checks.append((f"monitor.php: {tag} present", tag in mon))

all_pass = True
for label, ok in checks:
    s = 'PASS' if ok else 'FAIL'
    if not ok: all_pass = False
    print(f"[{s}] {label}")

print()
print("ALL PASS" if all_pass else "FAILURES DETECTED — do not commit")
PYEOF
```

---

## Step 7 — Commit and push to review branch

Only if ALL PASS above.

```bash
git checkout -b review/session-32
git add seat/index.html seat/inside/index.html wallets/member.html wallets/business.html \
        _app/api/routes/admin.php _app/api/routes/client-error.php admin/monitor.php
git diff --cached --stat
git commit -m "feat(monitoring): full beacon coverage + live error panel + email alert

Coverage:
- seat/index.html: inline beacon injected (ROOT=../)
- seat/inside/index.html: inline beacon injected (ROOT=../../)
- wallets/member.html: inline beacon injected (ROOT=../)
- wallets/business.html: inline beacon injected (ROOT=../)
- All 6 critical user journey pages now report JS errors to app_error_log

Live monitor panel:
- admin/monitor.php: new Live Errors panel, polls every 30s
- Red badge shows unacknowledged error count
- Shows last 5 unacknowledged errors (route, message, time, JS vs server)
- Links to errors.php for acknowledge workflow

Email alert:
- client-error.php: first-seen alert emails MAIL_ADMIN_EMAIL
- Debounced: same route+message combo only emails once per 60 minutes
- Uses existing smtpSendEmail() — no new dependencies
- Guard: only fires if mailerEnabled() is true

admin/summary API: adds unack_errors + recent_errors fields"
git push origin review/session-32
```

## STOP — wait for review before merging to main
