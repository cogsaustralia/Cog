<?php
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
