# CCC Session 47: go.php short link handler + click tracking
# Branch: review/session-47-go-php
# FILES: go.php (new) · .htaccess · _design/TRACKING-SPEC.md

## Purpose
Campaign links like:
  https://cogsaustralia.org/seat/?ref=fb&utm_campaign=seat-launch
are too long to paste cleanly into social posts.

This session adds:
  https://cogsaustralia.org/fb  -> /seat/?ref=fb&utm_campaign=seat-launch
  https://cogsaustralia.org/yt  -> /seat/?ref=yt&utm_campaign=seat-launch
  https://cogsaustralia.org/ig  -> /seat/?ref=ig&utm_campaign=seat-launch

go.php handles the redirect. It:
  1. Looks up the slug (fb/yt/ig) in a config array
  2. Records the click to link_clicks table (ip_hash, timestamp, slug, referrer)
  3. Issues a 302 redirect to the destination with full tracking params

Thomas can add new short links by editing the $LINKS array at the top
of go.php only — no CCC session, no deploy needed.

link_clicks table needs to be created via SQL migration first (Step 2).

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

## Step 2 — SQL migration (Thomas runs this in phpMyAdmin BEFORE proceeding)

Print this for Thomas:

```bash
cat << 'SQL'
-- Run in phpMyAdmin on cogsaust_TRUST before proceeding with this session.
-- Creates the link_clicks table for go.php click tracking.

CREATE TABLE IF NOT EXISTS `link_clicks` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`         VARCHAR(20)     NOT NULL COMMENT 'Short link slug e.g. fb, yt, ig',
  `ip_hash`      VARCHAR(64)         NULL COMMENT 'SHA-256 of visitor IP — no raw PII',
  `referrer`     VARCHAR(255)        NULL COMMENT 'HTTP_REFERER truncated to 255',
  `user_agent`   VARCHAR(120)        NULL COMMENT 'First 120 chars of UA string',
  `clicked_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_slug`       (`slug`),
  KEY `idx_clicked_at` (`clicked_at`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
echo ""
echo "Thomas: run the SQL above in phpMyAdmin, then confirm here before continuing."
```

## STOP — wait for Thomas to confirm the SQL has been run.

---

## Step 3 — Create go.php

```bash
python3 << 'PYEOF'
content = r'''<?php
declare(strict_types=1);

/**
 * go.php — Branded short link handler for COG$ of Australia Foundation
 *
 * Short links:
 *   cogsaustralia.org/fb  -> /seat/?ref=fb&utm_campaign=seat-launch
 *   cogsaustralia.org/yt  -> /seat/?ref=yt&utm_campaign=seat-launch
 *   cogsaustralia.org/ig  -> /seat/?ref=ig&utm_campaign=seat-launch
 *
 * To add a new short link: add an entry to $LINKS below.
 * No deploy required — edit this file directly on the server.
 * Format: 'slug' => ['dest' => '/path/', 'ref' => 'source', 'campaign' => 'name']
 *
 * Clicks are recorded to link_clicks table (ip_hash only — no raw PII).
 * Redirect is 302 (not 301) so browsers do not cache destination changes.
 */

// =============================================================================
// CONFIGURATION — edit this section to add or change short links
// =============================================================================

$LINKS = [
    'fb' => [
        'dest'     => '/seat/',
        'ref'      => 'fb',
        'campaign' => 'seat-launch',
    ],
    'yt' => [
        'dest'     => '/seat/',
        'ref'      => 'yt',
        'campaign' => 'seat-launch',
    ],
    'ig' => [
        'dest'     => '/seat/',
        'ref'      => 'ig',
        'campaign' => 'seat-launch',
    ],
];

// =============================================================================
// HANDLER — do not edit below this line
// =============================================================================

$slug = trim((string)($_GET['s'] ?? ''), '/');

if ($slug === '' || !array_key_exists($slug, $LINKS)) {
    // Unknown slug — redirect to homepage silently
    header('Location: /', true, 302);
    exit;
}

$link = $LINKS[$slug];
$dest = rtrim((string)($link['dest'] ?? '/'), '/') . '/';
$ref  = rawurlencode((string)($link['ref'] ?? ''));
$camp = rawurlencode((string)($link['campaign'] ?? ''));

$url  = $dest . '?ref=' . $ref;
if ($camp !== '') {
    $url .= '&utm_campaign=' . $camp;
}

// Record click — silent fail, never block the redirect
try {
    require_once __DIR__ . '/_app/api/config/database.php';
    $db = getDB();
    $tableOk = (bool)$db->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'link_clicks'"
    )->fetchColumn();

    if ($tableOk) {
        $rawIp  = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $ipHash = $rawIp !== '' ? hash('sha256', $rawIp) : null;
        $ref_hdr = substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 255) ?: null;
        $ua      = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120) ?: null;

        $db->prepare(
            'INSERT INTO link_clicks (slug, ip_hash, referrer, user_agent, clicked_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP())'
        )->execute([$slug, $ipHash, $ref_hdr, $ua]);
    }
} catch (Throwable $e) {
    // Silent — click tracking must never block redirect
    error_log('[go.php] ' . $e->getMessage());
}

header('Location: ' . $url, true, 302);
exit;
'''
with open('go.php', 'w') as f:
    f.write(content)
print(f"go.php written: {len(content.splitlines())} lines")
PYEOF
```

---

## Step 4 — Add rewrite rules to .htaccess

Route /fb, /yt, /ig (and any future slugs) to go.php.
Insert immediately before the catch-all RewriteRule that sends to index.html.

```bash
python3 << 'PYEOF'
with open('.htaccess') as f:
    content = f.read()

# The catch-all block — unique anchor
OLD = """  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d"""

NEW = """  # ── Short links -> go.php (add new slugs to go.php $LINKS array) ──────────
  RewriteRule ^(fb|yt|ig|tele|email|sms|qr)$ go.php?s=$1 [L,QSA]

  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d"""

count = content.count(OLD)
print(f"Anchor match: {count} (must be 1)")
if count != 1:
    print("ABORT")
    exit(1)

content = content.replace(OLD, NEW)
with open('.htaccess', 'w') as f:
    f.write(content)
print(".htaccess updated.")
PYEOF
```

---

## Step 5 — PHP lint go.php

```bash
php -l go.php
```

## STOP — must show "No syntax errors detected".

---

## Step 6 — Verification

```bash
python3 << 'PYEOF'
checks = []

with open('go.php') as f:
    go = f.read()

with open('.htaccess') as f:
    htaccess = f.read()

# go.php structure
checks.append(('go: declare strict_types',          'declare(strict_types=1)' in go))
checks.append(('go: $LINKS config array',           '$LINKS = [' in go))
checks.append(('go: fb entry',                      "'fb'" in go and 'seat-launch' in go))
checks.append(('go: yt entry',                      "'yt'" in go))
checks.append(('go: ig entry',                      "'ig'" in go))
checks.append(('go: unknown slug -> homepage',      "header('Location: /', true, 302)" in go))
checks.append(('go: 302 redirect',                  'true, 302' in go))
checks.append(('go: ref param in URL',              "'?ref='" in go or '"?ref="' in go or "'ref='" in go))
checks.append(('go: utm_campaign in URL',           'utm_campaign' in go))
checks.append(('go: ip_hash not raw IP',            "hash('sha256'" in go))
checks.append(('go: link_clicks INSERT',            'INSERT INTO link_clicks' in go))
checks.append(('go: table existence check',         'information_schema' in go))
checks.append(('go: silent fail on click track',    'Silent' in go or 'silent' in go))
checks.append(('go: exit after redirect',           go.count('exit') >= 2))
checks.append(('go: config section comment',        'CONFIGURATION' in go))
checks.append(('go: no deploy comment',             'No deploy required' in go))

# .htaccess
checks.append(('htaccess: RewriteRule for fb|yt|ig', 'fb|yt|ig' in htaccess))
checks.append(('htaccess: routes to go.php',         'go.php?s=$1' in htaccess))
checks.append(('htaccess: QSA flag',                 'QSA' in htaccess))
checks.append(('htaccess: before catch-all',
    htaccess.index('go.php?s=$1') < htaccess.index('RewriteCond %{REQUEST_FILENAME} !-f')))
checks.append(('htaccess: catch-all still present',  'index.html [L]' in htaccess))

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
git checkout -b review/session-47-go-php
git add go.php .htaccess _design/TRACKING-SPEC.md
git diff --cached --stat
git commit -m "feat(links): go.php branded short link handler + click tracking

Short links now available:
  cogsaustralia.org/fb -> /seat/?ref=fb&utm_campaign=seat-launch
  cogsaustralia.org/yt -> /seat/?ref=yt&utm_campaign=seat-launch
  cogsaustralia.org/ig -> /seat/?ref=ig&utm_campaign=seat-launch

go.php:
- Config array at top — Thomas adds new slugs without CCC/deploy
- Records click to link_clicks table (ip_hash, referrer, ua, timestamp)
- Table existence guard — silent fail if migration not run
- 302 redirect — never cached, safe to change destination
- Unknown slug redirects to homepage

.htaccess:
- RewriteRule routes /fb /yt /ig (and future slugs) to go.php
- QSA flag preserves any additional query params
- Rule inserted before catch-all"
git push origin review/session-47-go-php
```

## STOP — paste full verification output and diff stat for review before merge.
