<?php
declare(strict_types=1);

/**
 * COG$ of Australia Foundation — Weekly Hub Digest Generator
 *
 * Queues one digest email per enrolled member when their hubs have had
 * activity in the past 7 days. Emails are processed by the existing
 * cron-email.php queue worker (runs every 5 minutes).
 *
 * CRON SETUP — add via cPanel Cron Jobs (once weekly, Friday 08:00 UTC = 18:00 AEST):
 *   0 8 * * 5 php /home4/cogsaust/public_html/_app/api/cron-hub-digest.php >> /home4/cogsaust/logs/hub-digest.log 2>&1
 *
 * MANUAL TEST (SSH or cPanel Terminal):
 *   php /home4/cogsaust/public_html/_app/api/cron-hub-digest.php
 *
 * SECURITY: CLI or localhost only.
 */

ignore_user_abort(true);
set_time_limit(120);

// ── Access guard ──────────────────────────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip !== '127.0.0.1' && $ip !== '::1') {
        http_response_code(403);
        exit('Forbidden');
    }
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/integrations/mailer.php';

// ── Config ────────────────────────────────────────────────────────────────────
$startTime  = microtime(true);
$timestamp  = date('Y-m-d H:i:s T');
$windowDays = 7;
$windowStart = date('Y-m-d H:i:s', strtotime("-{$windowDays} days"));
$windowEnd   = date('Y-m-d H:i:s');
$weekEnding  = date('Y-m-d');
$site        = (defined('SITE_URL') && SITE_URL !== '') ? SITE_URL : 'https://cogsaustralia.org';

echo "[{$timestamp}] [hub-digest] Starting — window {$windowStart} → {$windowEnd}\n";

try {
    $db = getDB();

    // ── 1. Check mailer is enabled ─────────────────────────────────────────
    if (!mailerEnabled()) {
        echo "[{$timestamp}] [hub-digest] SKIPPED — mailer not enabled\n";
        exit(0);
    }

    // ── 2. Pre-compute hub activity for the window ─────────────────────────
    // Groups by area_key + status for any project that entered that phase
    // (phase_opened_at) within the window. hub_digest_enabled may not exist
    // yet on older DB states — query is wrapped safely.
    $actStmt = $db->prepare(
        "SELECT area_key, status,
                COUNT(*)                                                        AS n,
                GROUP_CONCAT(title ORDER BY phase_opened_at DESC SEPARATOR '|||') AS titles
           FROM hub_projects
          WHERE phase_opened_at BETWEEN ? AND ?
            AND status NOT IN ('archived')
          GROUP BY area_key, status"
    );
    $actStmt->execute([$windowStart, $windowEnd]);

    $byAreaStatus = [];   // [area_key][status] => ['n' => int, 'titles' => string]
    foreach ($actStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byAreaStatus[(string)$row['area_key']][(string)$row['status']] = [
            'n'      => (int)$row['n'],
            'titles' => (string)$row['titles'],
        ];
    }

    if (!$byAreaStatus) {
        echo "[{$timestamp}] [hub-digest] No hub activity this week — no digests queued\n";
        exit(0);
    }

    // ── 3. Load eligible members ───────────────────────────────────────────
    // hub_digest_enabled defaults to 1 — use COALESCE to handle the column
    // not existing in very old DB states without crashing.
    try {
        $memberStmt = $db->query(
            "SELECT id, member_number, first_name, email, participation_answers,
                    COALESCE(hub_digest_enabled, 1) AS hub_digest_enabled
               FROM members
              WHERE is_active = 1
                AND participation_completed = 1
                AND email <> ''
                AND wallet_status = 'active'"
        );
    } catch (Throwable) {
        // Fallback: column not yet present — treat all as opted-in
        $memberStmt = $db->query(
            "SELECT id, member_number, first_name, email, participation_answers,
                    1 AS hub_digest_enabled
               FROM members
              WHERE is_active = 1
                AND participation_completed = 1
                AND email <> ''
                AND wallet_status = 'active'"
        );
    }
    $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── 4. Queue one digest per member ─────────────────────────────────────
    $queued  = 0;
    $skipped = 0;

    foreach ($members as $m) {
        if (!(int)$m['hub_digest_enabled']) {
            $skipped++;
            continue;
        }

        $areas = json_decode((string)$m['participation_answers'], true);
        if (!is_array($areas) || !$areas) {
            continue;
        }

        $hubs = [];
        foreach ($areas as $areaKey) {
            $areaKey = (string)$areaKey;
            $s = $byAreaStatus[$areaKey] ?? [];
            if (!$s) {
                continue;  // no activity in this hub this week
            }

            // Highlight titles from deliberation phase (most substantive signal)
            $highlightSource = $s['deliberation']['titles']
                ?? $s['open_for_input']['titles']
                ?? $s['vote']['titles']
                ?? $s['accountability']['titles']
                ?? '';
            $highlightTitles = array_slice(
                array_filter(explode('|||', $highlightSource)),
                0, 3
            );

            $hubs[] = [
                'area_key'               => $areaKey,
                'area_label'             => hubAreaLabel($areaKey),
                'entered_input'          => (int)($s['open_for_input']['n']   ?? 0),
                'entered_deliberation'   => (int)($s['deliberation']['n']     ?? 0),
                'entered_vote'           => (int)($s['vote']['n']             ?? 0),
                'entered_accountability' => (int)($s['accountability']['n']   ?? 0),
                'completed'              => (int)($s['completed']['n']        ?? 0),
                'highlight_titles'       => $highlightTitles,
            ];
        }

        if (!$hubs) {
            continue;  // member has no hubs with activity this week
        }

        queueEmail(
            $db,
            'snft_member',
            (int)$m['id'],
            (string)$m['email'],
            'hub_weekly_digest',
            'Your COG$ weekly hub digest — ' . $weekEnding,
            [
                'member_first_name' => (string)$m['first_name'],
                'week_ending'       => $weekEnding,
                'hubs'              => $hubs,
                'mainspring_url'    => $site . '/hubs/mainspring/',
            ]
        );
        $queued++;
    }

    $elapsed = round(microtime(true) - $startTime, 2);
    echo "[{$timestamp}] [hub-digest] Done — queued={$queued}, skipped_optout={$skipped}, elapsed={$elapsed}s\n";

} catch (Throwable $e) {
    $elapsed = round(microtime(true) - $startTime, 2);
    echo "[{$timestamp}] [hub-digest] ERROR after {$elapsed}s: " . $e->getMessage() . "\n";
    exit(1);
}
